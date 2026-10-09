<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;
use Util\AnexoPdfHelper;

class TalentRn
{
    private const TIMEOUT_INDETERMINADO_SEGUNDOS = 60;

    private const FORMATO_ANEXO_ATIVO = 'anexos';

    public function __construct(
        private TalentClient $talentClient,
        private AtendimentoDao $atendimentoDao,
        private string $caminhoBase,
        private ?AnexoOrdemColetaLeitor $leitorAnexoOrdemColeta = null
    ) {}

    public function processarCheckin(array $atendimento, array $empresa, array $notas): array
    {
        $idAtendimento = (int) $atendimento['id_atendimento'];

        $this->atendimentoDao->marcarEnvioTalentObsoletoComoIndeterminado($idAtendimento, self::TIMEOUT_INDETERMINADO_SEGUNDOS);

        $atual = $this->atendimentoDao->buscarPorId($idAtendimento) ?? $atendimento;
        $statusAtual = $atual['talent_checkin_status'] ?? 'NAO_ENVIADO';

        if ($statusAtual === 'ENVIADO') {
            return ['status' => 'JA_ENVIADO', 'senha' => $atual['talent_senha'], 'protocolo' => $atual['talent_protocolo'], 'erro_categoria' => null];
        }
        if ($statusAtual === 'ENVIANDO') {
            return ['status' => 'EM_ANDAMENTO', 'senha' => null, 'protocolo' => null, 'erro_categoria' => null];
        }
        if ($statusAtual === 'ENVIO_INDETERMINADO') {
            return ['status' => 'INDETERMINADO_PENDENTE_MANUAL', 'senha' => null, 'protocolo' => null, 'erro_categoria' => null];
        }

        $tentativaId = bin2hex(random_bytes(16));
        if (!$this->atendimentoDao->iniciarEnvioTalent($idAtendimento, $tentativaId)) {
            return ['status' => 'EM_ANDAMENTO', 'senha' => null, 'protocolo' => null, 'erro_categoria' => null];
        }

        try {
            $atual = $this->atendimentoDao->buscarPorId($idAtendimento) ?? $atual;
            $payload = $this->montarPayload($atual, $notas, $empresa);
        } catch (\Throwable $e) {
            $this->atendimentoDao->gravarResultadoEnvioTalent($idAtendimento, $tentativaId, 'ERRO_REPROCESSAVEL', null, null);
            return ['status' => 'ERRO_REPROCESSAVEL', 'senha' => null, 'protocolo' => null, 'erro_categoria' => 'erro_montagem_payload'];
        }

        try {
            $resultado = $this->talentClient->checkin($payload);
        } catch (TalentClientException $e) {
            $statusFinal = $e->ehIndeterminado() ? 'ENVIO_INDETERMINADO' : 'ERRO_REPROCESSAVEL';
            $this->atendimentoDao->gravarResultadoEnvioTalent($idAtendimento, $tentativaId, $statusFinal, null, null);
            return ['status' => $statusFinal, 'senha' => null, 'protocolo' => null, 'erro_categoria' => $e->categoria(), 'mensagem_api' => $e->mensagemApi()];
        }

        $this->atendimentoDao->gravarResultadoEnvioTalent($idAtendimento, $tentativaId, 'ENVIADO', $resultado['senha'], $resultado['protocolo']);

        return ['status' => 'ENVIADO', 'senha' => $resultado['senha'], 'protocolo' => $resultado['protocolo'], 'erro_categoria' => null];
    }

    private function montarPayload(array $atendimento, array $notas, array $empresa): array
    {
        $cnpjArmazem = preg_replace('/\D/', '', (string) ($empresa['cnpj'] ?? ''));
        if (strlen($cnpjArmazem) !== 14) {
            throw new \RuntimeException('cnpj_armazem_invalido');
        }

        $cnpjDepositante = preg_replace('/\D/', '', (string) ($atendimento['cliente_cnpj'] ?? ''));
        if (strlen($cnpjDepositante) !== 14) {
            throw new \RuntimeException('cnpj_depositante_invalido');
        }

        if (!in_array($atendimento['tipo'] ?? null, ['expedicao', 'recebimento'], true)) {
            throw new \RuntimeException('tipo_atendimento_invalido');
        }
        $tipoEmbDesemb = $atendimento['tipo'] === 'expedicao' ? 'Embarque' : 'Desembarque';

        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($atendimento['placa'] ?? '')));
        $uf = strtoupper(trim((string) ($atendimento['crlv_uf'] ?? '')));
        $rntc = trim((string) ($atendimento['crlv_rntc'] ?? ''));
        $tipoVeiculo = trim((string) ($atendimento['crlv_tipo_veiculo'] ?? ''));
        if ($placa === '' || !in_array($uf, DocumentoRn::UFS_VALIDAS, true) || $rntc === '' || $tipoVeiculo === '') {
            throw new \RuntimeException('veiculo_invalido');
        }

        $cpfMotorista = preg_replace('/\D/', '', (string) ($atendimento['motorista_cpf'] ?? ''));
        $nomeMotorista = trim((string) ($atendimento['motorista_nome'] ?? ''));
        if (strlen($cpfMotorista) !== 11 || $nomeMotorista === '') {
            throw new \RuntimeException('motorista_invalido');
        }

        $payload = [
            'cnpjArmazem' => $cnpjArmazem,
            'cnpjDepositante' => $cnpjDepositante,
            'tipoEmbDesemb' => $tipoEmbDesemb,
            'veiculo' => ['placa' => $placa, 'uf' => $uf, 'rntc' => $rntc, 'tipo' => $tipoVeiculo],
            'motorista' => ['cpf' => $cpfMotorista, 'nome' => $nomeMotorista],
        ];

        if (!empty($atendimento['possui_ajudante']) && trim((string) ($atendimento['ajudante_nome'] ?? '')) !== '') {
            $payload['ajudantes'] = [[
                'cpf' => preg_replace('/\D/', '', (string) ($atendimento['ajudante_cpf'] ?? '')),
                'nome' => trim((string) $atendimento['ajudante_nome']),
            ]];
        }

        $payload['doctos'] = $this->montarDoctos($atendimento, $notas);

        $payload[self::FORMATO_ANEXO_ATIVO] = $this->montarAnexos($atendimento, $notas);

        return $payload;
    }

    private function montarDoctos(array $atendimento, array $notas): array
    {
        if ($atendimento['tipo'] === 'expedicao') {
            $ordemColeta = trim((string) ($atendimento['ordem_coleta'] ?? ''));
            if ($ordemColeta === '') {
                throw new \RuntimeException('doctos_ordem_coleta_ausente');
            }

            return [['tipo' => 'ORDEM_COLETA', 'nrDocto' => $ordemColeta]];
        }

        if (count($notas) === 0) {
            throw new \RuntimeException('doctos_notas_ausentes');
        }

        $doctos = [];
        foreach ($notas as $nota) {
            $numeroNota = trim((string) ($nota['numero_nota'] ?? ''));
            if ($numeroNota === '') {
                throw new \RuntimeException('doctos_nota_sem_numero');
            }
            $doctos[] = ['tipo' => 'NOTA_FISCAL', 'nrDocto' => $numeroNota];
        }

        return $doctos;
    }

    private function montarAnexos(array $atendimento, array $notas): array
    {
        $pasta = rtrim($this->caminhoBase, '/') . '/' . $atendimento['pasta_documentos'];
        $anexos = [];


        foreach ($notas as $nota) {
            $arquivoNota = $pasta . '/' . $nota['arquivo'];
            if (!is_file($arquivoNota)) {
                continue;
            }
            $descricao = sprintf('Nota Fiscal %02d', (int) $nota['ordem']);
            $anexos[] = $this->anexarPdf([$arquivoNota], $descricao);
        }

        $anexoOrdemColeta = $this->montarAnexoOrdemColeta($atendimento);
        if ($anexoOrdemColeta !== null) {
            $anexos[] = $anexoOrdemColeta;
        }

        return $anexos;
    }

    private function montarAnexoOrdemColeta(array $atendimento): ?array
    {
        if ($this->leitorAnexoOrdemColeta === null || ($atendimento['tipo'] ?? null) !== 'expedicao') {
            return null;
        }

        $idAtendimento = (int) ($atendimento['id_atendimento'] ?? 0);

        try {
            $cnpj = preg_replace('/\D/', '', (string) ($atendimento['cliente_cnpj'] ?? ''));
            $numero = trim((string) ($atendimento['ordem_coleta'] ?? ''));
            $resultado = $this->leitorAnexoOrdemColeta->buscar((string) $cnpj, $numero);

            $base64 = $resultado['base64'] ?? null;
            if (is_string($base64) && $base64 !== '') {
                return ['anexoBase64' => $base64, 'descricao' => 'Ordem de Coleta'];
            }

            $motivo = $resultado['motivo'] ?? null;
            if ($motivo === null) {
                return null;
            }
            $motivoLog = is_string($motivo) && preg_match('/\A[a-z0-9_]{1,40}\z/D', $motivo) === 1 ? $motivo : 'motivo_desconhecido';
        } catch (\Throwable $e) {
            $motivoLog = 'leitor_excecao';
        }

        error_log(sprintf('TalentRn: anexo_ordem_coleta_omitido id_atendimento=%d motivo=%s', $idAtendimento, $motivoLog));

        return null;
    }

    private function anexarPdf(array $caminhosJpeg, string $descricao): array
    {
        $pdfBytes = AnexoPdfHelper::gerarPdfDeImagens($caminhosJpeg);

        return [
            'anexoBase64' => base64_encode($pdfBytes),
            'descricao' => $descricao,
        ];
    }

    public function montarAnexosGzip(array $atendimento, array $notas): array
    {
        $anexosPadrao = $this->montarAnexos($atendimento, $notas);

        $anexosGzip = [];
        foreach ($anexosPadrao as $anexo) {
            $pdfBytes = base64_decode($anexo['anexoBase64'], true);
            if ($pdfBytes === false) {
                throw new \RuntimeException('anexo_gzip_decodificacao_invalida');
            }

            $comprimido = gzencode($pdfBytes);
            if ($comprimido === false) {
                throw new \RuntimeException('anexo_gzip_compressao_falhou');
            }

            $nomeArquivo = preg_replace('/[^A-Za-z0-9_]+/', '_', $anexo['descricao']) . '.pdf.gz';

            $anexosGzip[] = [
                'nome' => $nomeArquivo,
                'valueBase64' => base64_encode($comprimido),
            ];
        }

        return $anexosGzip;
    }
}
