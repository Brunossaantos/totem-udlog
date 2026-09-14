<?php

namespace App\Controller;

use FPDF;
use Util\Resposta;

/**
 * Endpoint ISOLADO de diagnostico (demanda impressao-etiqueta-teste,
 * 2026-09-11/2026-09-14) — gera uma etiqueta de TESTE em PDF real (FPDF) e
 * devolve os dados de conexao do servico local de impressao (mini PC
 * Windows, fora do escopo Hostgator). NUNCA instancia
 * AtendimentoRn/TalentRn/TalentClient/OrdemColetaClient, NUNCA consulta ou
 * altera tb_atendimento/status/etapa real — arquivo proprio, sem nenhuma
 * dependencia de fluxo de atendimento.
 *
 * Dimensao/orientacao/corte da etiqueta SEMPRE lidas de $_ENV (nunca
 * hardcoded no codigo), conforme decisao do usuario em 2026-09-14:
 * ETIQUETA_LARGURA_MM / ETIQUETA_COMPRIMENTO_MM / ETIQUETA_ORIENTACAO /
 * ETIQUETA_CORTE_APOS_IMPRESSAO.
 *
 * O conteudo do PDF gerado NUNCA contem dado pessoal (sem CPF/CNH/placa/
 * nome) — so o texto fixo de aviso "ETIQUETA DE TESTE - NAO UTILIZAR" e
 * metadados tecnicos de diagnostico (identificador, timestamp).
 */
class ImpressaoTesteController
{
    /**
     * Gera a etiqueta de teste (PDF real via FPDF) usando as dimensoes
     * configuradas em .env e devolve em base64, junto com um identificador
     * unico de idempotencia para esta geracao especifica.
     */
    public function gerarEtiqueta(): void
    {
        $config = $this->lerConfiguracaoEtiqueta();

        $identificador = bin2hex(random_bytes(16));

        try {
            $pdfBytes = $this->montarPdf($config, $identificador);
        } catch (\Throwable $e) {
            error_log('impressao-teste gerar-etiqueta: falha ao gerar PDF: ' . $e->getMessage());
            Resposta::erro('Nao foi possivel gerar a etiqueta de teste agora', 500);
            return;
        }

        Resposta::sucesso([
            'pdf_base64' => base64_encode($pdfBytes),
            'identificador' => $identificador,
            'largura_mm' => $config['largura_mm'],
            'comprimento_mm' => $config['comprimento_mm'],
            'orientacao' => $config['orientacao'],
            'corte_apos_impressao' => $config['corte_apos_impressao'],
        ]);
    }

    /**
     * Devolve URL/token do servico local de impressao (mini PC Windows) so
     * depois de validar o token do totem — NUNCA exposto em arquivo JS
     * estatico versionado. Rota separada da geracao do PDF para permitir
     * que o front-end resolva a configuracao de conexao uma unica vez por
     * sessao, independente de quantas etiquetas gerar.
     */
    public function configuracaoServicoLocal(): void
    {
        $url = $_ENV['IMPRESSAO_LOCAL_URL'] ?? '';
        $token = $_ENV['IMPRESSAO_LOCAL_TOKEN'] ?? '';
        $frontendTimeoutMs = $_ENV['IMPRESSAO_FRONTEND_TIMEOUT_MS'] ?? '';

        if (
            $url === ''
            || $token === ''
            || !is_numeric($frontendTimeoutMs)
            || (int) $frontendTimeoutMs <= 0
        ) {
            error_log('impressao-teste configuracao-servico-local: IMPRESSAO_LOCAL_URL/IMPRESSAO_LOCAL_TOKEN/IMPRESSAO_FRONTEND_TIMEOUT_MS ausentes ou invalidos no .env');
            Resposta::erro('Servico local de impressao nao configurado', 503);
            return;
        }

        Resposta::sucesso([
            'url' => $url,
            'token' => $token,
            'frontend_timeout_ms' => (int) $frontendTimeoutMs,
        ]);
    }

    /**
     * Le e valida a configuracao de etiqueta do .env. Fail-closed: qualquer
     * valor ausente/invalido interrompe a requisicao com erro, nunca assume
     * um default silencioso (mesmo padrao de VIO_AMBIENTE em outras rotas).
     */
    private function lerConfiguracaoEtiqueta(): array
    {
        $largura = $_ENV['ETIQUETA_LARGURA_MM'] ?? '';
        $comprimento = $_ENV['ETIQUETA_COMPRIMENTO_MM'] ?? '';
        $orientacao = $_ENV['ETIQUETA_ORIENTACAO'] ?? '';
        $corte = $_ENV['ETIQUETA_CORTE_APOS_IMPRESSAO'] ?? '';

        if (
            !is_numeric($largura) || (float) $largura <= 0
            || !is_numeric($comprimento) || (float) $comprimento <= 0
            || !in_array($orientacao, ['portrait', 'landscape'], true)
            || !in_array(strtolower((string) $corte), ['true', 'false'], true)
        ) {
            error_log('impressao-teste: configuracao de etiqueta ausente/invalida no .env (ETIQUETA_LARGURA_MM/ETIQUETA_COMPRIMENTO_MM/ETIQUETA_ORIENTACAO/ETIQUETA_CORTE_APOS_IMPRESSAO)');
            Resposta::erro('Configuracao de etiqueta ausente ou invalida', 500);
        }

        return [
            'largura_mm' => (float) $largura,
            'comprimento_mm' => (float) $comprimento,
            'orientacao' => $orientacao,
            'corte_apos_impressao' => strtolower((string) $corte) === 'true',
        ];
    }

    /**
     * Monta o PDF de teste com FPDF: pagina no tamanho exato configurado
     * (largura x comprimento em mm), orientacao configurada, sem nenhum
     * dado pessoal — so o aviso fixo e metadados tecnicos de diagnostico.
     */
    private function montarPdf(array $config, string $identificador): string
    {
        $orientacaoFpdf = $config['orientacao'] === 'landscape' ? 'L' : 'P';

        $pdf = new FPDF($orientacaoFpdf, 'mm', [$config['largura_mm'], $config['comprimento_mm']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(2, 2, 2);
        $pdf->AddPage();

        $larguraUtil = $pdf->GetPageWidth() - 4;

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY(2, 4);
        $pdf->MultiCell($larguraUtil, 5, 'ETIQUETA DE TESTE', 0, 'C');

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(2, $pdf->GetY());
        $pdf->MultiCell($larguraUtil, 5, 'NAO UTILIZAR', 0, 'C');

        $pdf->SetFont('Arial', '', 6);
        $pdf->SetXY(2, $pdf->GetY() + 3);
        $pdf->MultiCell($larguraUtil, 3.5, 'Diagnostico de impressao - sem dado pessoal', 0, 'C');

        $pdf->SetXY(2, $pdf->GetY() + 2);
        $pdf->MultiCell($larguraUtil, 3.5, 'ID: ' . $identificador, 0, 'C');

        $pdf->SetXY(2, $pdf->GetY() + 1);
        $pdf->MultiCell($larguraUtil, 3.5, 'Gerado em: ' . date('Y-m-d H:i:s'), 0, 'C');

        return $pdf->Output('S');
    }
}
