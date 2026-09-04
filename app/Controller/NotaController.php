<?php

namespace App\Controller;

use App\Rn\NotaFiscalRn;
use App\Dao\AtendimentoDao;
use Util\UploadHelper;
use Util\Resposta;

class NotaController
{
    public function __construct(
        private NotaFiscalRn $notaFiscalRn,
        private AtendimentoDao $atendimentoDao
    ) {}

    public function processar(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $ordem = (int) ($entrada['ordem'] ?? 0);
        $imagemBase64 = $entrada['imagem'] ?? null;
        $chave = $entrada['chave'] ?? null;

        if (!$idAtendimento || !$ordem || !$imagemBase64) {
            Resposta::erro('Dados incompletos');
        }

        if ($ordem < 1 || $ordem > 5) {
            Resposta::erro('Ordem da nota invalida');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'recebimento') {
            // mensagem generica: nao revela que o atendimento existe mas eh de outro tipo/totem
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }

        if ($atendimento['etapa_atual'] !== 'digitalizacao_notas') {
            Resposta::erro('Atendimento nao esta na etapa de digitalizacao de notas');
        }

        if ($this->notaFiscalRn->contarNotas($idAtendimento) >= 5) {
            Resposta::erro('Limite de 5 notas fiscais ja atingido para esse atendimento');
        }

        if ($this->notaFiscalRn->ordemJaRegistrada($idAtendimento, $ordem)) {
            Resposta::erro('Ja existe uma nota registrada para essa ordem');
        }

        $base64Limpo = null;
        if (is_string($imagemBase64)) {
            $semPrefixo = preg_replace('#^data:image/jpeg;base64,#', '', $imagemBase64);
            $base64Limpo = base64_decode($semPrefixo, true);
        }
        if ($base64Limpo === null || $base64Limpo === false || $base64Limpo === '') {
            Resposta::erro('Imagem invalida');
        }

        $nomeArquivo = sprintf('nota_%02d.jpg', $ordem);

        try {
            $caminhoArquivo = UploadHelper::salvarImagemBase64($imagemBase64, $atendimento['pasta_documentos'], $nomeArquivo);
        } catch (\RuntimeException $e) {
            Resposta::erro('Nao foi possivel salvar a imagem da nota');
        }

        try {
            // identificacao do cliente roda aqui, mas quem decide se pula a tela
            // de confirmacao eh o front-end, chamando /nota.php?acao=status ao finalizar
            $resultado = $this->notaFiscalRn->processarLeitura($idAtendimento, $ordem, $nomeArquivo, $chave);
        } catch (\Throwable $e) {
            // insercao falhou depois do arquivo ja gravado (ex: duplicidade em corrida
            // com a constraint UNIQUE) — nao deixa arquivo orfao sem registro no banco
            @unlink($caminhoArquivo);
            Resposta::erro('Nao foi possivel registrar a nota', 500);
        }

        Resposta::sucesso($resultado);
    }

    public function algumaIdentificada(int $idAtendimento, int $idTotem): void
    {
        $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        $identificada = $this->notaFiscalRn->algumaNotaIdentificouCliente($idAtendimento);
        Resposta::sucesso(['cliente_identificado' => $identificada]);
    }

    /**
     * Busca o atendimento e garante que pertence ao totem autenticado.
     * Mensagem de erro generica em qualquer caso de falha (nao existe / eh
     * de outro totem) para nao vazar a existencia de atendimento alheio.
     */
    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }
}
