<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use Util\NotaArquivoStorage;

/**
 * Abandono de atendimento (rodada corretiva da demanda hardening-revisao-
 * notas-e-cliente, 2026-10-01; retencao de fotos decidida pelo usuario).
 *
 * Atendimento em_andamento sem atividade por mais de 24 horas e ABANDONADO:
 * passa ao estado terminal JA EXISTENTE 'cancelado' (o mesmo do cancelar e da
 * inatividade do front; 'bloqueado' e outra coisa: excesso de notas) e as
 * fotos das notas vao para a quarentena (nota_NN.jpg.<id_nota>.del), onde
 * ficam por mais 24 horas ate o cron cron/limpar-notas-quarentena.php
 * apaga-las (quarentenar() renova o mtime, entao a retencao conta da
 * quarentena).
 *
 * Consistencia (mesma sequencia do excluir, com compensacao), por
 * atendimento e em UMA transacao curta:
 *   BEGIN; lock da linha (FOR UPDATE); REVALIDA (status, Talent, inatividade);
 *   rename das fotos para .del (cada uma com compensacao); UPDATE
 *   em_andamento -> cancelado exigindo 1 linha; COMMIT.
 * Qualquer falha antes do COMMIT devolve as fotos (rename de volta, ainda sob
 * lock) e faz ROLLBACK: o atendimento segue em_andamento e a proxima execucao
 * tenta de novo. Atendimento ativo e recente nunca e candidato nem sofre
 * quarentena (a revalidacao sob lock cobre a corrida com atividade nova).
 *
 * Escopo: so as fotos das notas (mesmo escopo do cancelar atual). CNH/CRLV nao
 * sao movidas (pendencia registrada).
 *
 * Logs (quem chama): so contagens agregadas. Nunca placa, pasta, caminho,
 * nome de arquivo ou dado da nota.
 */
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

    /**
     * @return array{candidatos:int, abandonados:int, ignorados:int, falhas:int, fotos_quarentenadas:int, fotos_sem_caminho_valido:int, limite_atingido:bool}
     */
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

    /**
     * @param array<string, mixed> $resultado
     */
    private function abandonarUm(int $idAtendimento, int $inatividadeSegundos, array &$resultado): void
    {
        /** @var array<int, callable> $compensacoes */
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
                // ficou ativo/concluido/cancelado desde a listagem: nada a fazer
                $this->atendimentoDao->desfazerTransacao();
                $resultado['ignorados']++;

                return;
            }

            foreach ($this->notaDao->listarPorAtendimento($idAtendimento) as $nota) {
                $caminho = $this->storage->caminhoDoArquivo($atual['pasta_documentos'] ?? null, $nota['arquivo'] ?? null);
                if ($caminho === null) {
                    // pasta/arquivo fora do formato (legado): nenhum acesso a
                    // disco; contado e sinalizado no log agregado do cron
                    $semCaminho++;
                    continue;
                }

                $idNota = (int) $nota['id_nota'];
                $situacao = $this->storage->quarentenar($caminho, $idNota); // lanca em falha
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
            // rename de volta AINDA sob lock (ou apos falha do COMMIT), depois ROLLBACK
            foreach (array_reverse($compensacoes) as $compensacao) {
                try {
                    $compensacao();
                } catch (\Throwable $ignorada) {
                    error_log('abandono: falha ao compensar a quarentena [' . get_class($ignorada) . ']');
                }
            }
            error_log('abandono: falha ao abandonar atendimento, mantido em_andamento [' . get_class($e) . '] id_atendimento=' . $idAtendimento);
            $resultado['falhas']++;
        } finally {
            if (!$confirmou) {
                try {
                    $this->atendimentoDao->desfazerTransacao();
                } catch (\Throwable $e) {
                    // conexao descartada no fim do processo
                }
            }
        }
    }
}
