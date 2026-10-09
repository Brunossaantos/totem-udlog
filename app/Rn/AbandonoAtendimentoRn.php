<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use Util\LogSistema;
use Util\NotaArquivoStorage;

class AbandonoAtendimentoRn
{
    public const INATIVIDADE_SEGUNDOS = 86400;
    public const LIMITE_POR_EXECUCAO = 500;
    private const TIMEOUT_LOCK_SEGUNDOS = 5;

    public function __construct(
        private AtendimentoDao $atendimentoDao,
        private AtendimentoNotaDao $notaDao,
        private NotaArquivoStorage $storage
    ) {}

    public function executar(
        int $inatividadeSegundos = self::INATIVIDADE_SEGUNDOS,
        int $limite = self::LIMITE_POR_EXECUCAO
    ): array {
        $resultado = [
            'candidatos' => 0, 'abandonados' => 0, 'ignorados' => 0, 'falhas' => 0,
            'fotos_quarentenadas' => 0, 'fotos_sem_caminho_valido' => 0, 'limite_atingido' => false,
        ];

        $ids = $this->atendimentoDao->listarCandidatosAbandono($inatividadeSegundos, $limite);
        $resultado['candidatos'] = count($ids);
        $resultado['limite_atingido'] = count($ids) >= $limite;

        foreach ($ids as $idAtendimento) {
            $this->abandonarUm($idAtendimento, $inatividadeSegundos, $resultado);
        }

        return $resultado;
    }

    private function abandonarUm(int $idAtendimento, int $inatividadeSegundos, array &$resultado): void
    {
        $compensacoes = [];
        $confirmou = false;
        $fotos = 0;
        $semCaminho = 0;

        try {
            $this->atendimentoDao->iniciarTransacao(self::TIMEOUT_LOCK_SEGUNDOS);

            $atual = $this->atendimentoDao->buscarParaAbandonoParaUpdate($idAtendimento);
            if (
                $atual === null
                || $atual['status'] !== 'em_andamento'
                || !in_array($atual['talent_checkin_status'], ['NAO_ENVIADO', 'ERRO_REPROCESSAVEL'], true)
                || (int) $atual['inatividade_segundos'] < $inatividadeSegundos
            ) {
                $this->atendimentoDao->desfazerTransacao();
                $resultado['ignorados']++;

                return;
            }

            foreach ($this->notaDao->listarPorAtendimento($idAtendimento) as $nota) {
                $caminho = $this->storage->caminhoDoArquivo($atual['pasta_documentos'] ?? null, $nota['arquivo'] ?? null);
                if ($caminho === null) {
                    $semCaminho++;
                    continue;
                }

                $idNota = (int) $nota['id_nota'];
                $situacao = $this->storage->quarentenar($caminho, $idNota);
                if ($situacao === 'quarentenado') {
                    $fotos++;
                    $compensacoes[] = function () use ($caminho, $idNota, $idAtendimento): void {
                        if (!$this->storage->restaurar($caminho, $idNota)) {
                            error_log("abandono: FALHA ao restaurar a foto da quarentena apos rollback; foto permanece em quarentena (id_atendimento={$idAtendimento} id_nota={$idNota})");
                        }
                    };
                }
            }

            if (!$this->atendimentoDao->marcarAbandonado($idAtendimento)) {
                throw new \RuntimeException('abandono_cas_perdido');
            }

            $this->atendimentoDao->confirmarTransacao();
            $confirmou = true;

            $resultado['abandonados']++;
            $resultado['fotos_quarentenadas'] += $fotos;
            $resultado['fotos_sem_caminho_valido'] += $semCaminho;
        } catch (\Throwable $e) {
            foreach (array_reverse($compensacoes) as $compensacao) {
                try {
                    $compensacao();
                } catch (\Throwable $ignorada) {
                    error_log('abandono: falha ao compensar a quarentena [' . get_class($ignorada) . ']');
                }
            }
            error_log('abandono: falha ao abandonar atendimento, mantido em_andamento [' . get_class($e) . '] id_atendimento=' . $idAtendimento);
            LogSistema::registrar('cron_falhou', ['job' => 'abandonar_atendimentos', 'excecao' => $e, 'motivo' => 'falha_inesperada']);
            $resultado['falhas']++;
        } finally {
            if (!$confirmou) {
                try {
                    $this->atendimentoDao->desfazerTransacao();
                } catch (\Throwable $e) {
                }
            }
        }
    }
}
