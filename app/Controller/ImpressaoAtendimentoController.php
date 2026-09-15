<?php

namespace App\Controller;

use FPDF;
use App\Dao\AtendimentoDao;
use Util\Resposta;

/**
 * Endpoint de impressao REAL (demanda talent-doctos-finalizacao-checkin,
 * 2026-09-14) — separado e ISOLADO de App\Controller\ImpressaoTesteController
 * (nunca reaproveitado por ele, nem o contrario). Gera a etiqueta final com
 * nome do motorista + nrRegAcesso (persistido como tb_atendimento.talent_senha)
 * — SO LE dados ja persistidos, NUNCA dispara/redispara chamada ao Talent
 * (achado do security-especialista no planejamento: risco de caminho
 * paralelo de envio).
 *
 * Entrada aceita SOMENTE id_atendimento (+ flag de reimpressao) — nome do
 * motorista e nrRegAcesso SEMPRE lidos do banco via buscarAtendimentoDoTotem
 * (mesmo padrao de posse/tipo/status/etapa de
 * App\Controller\AtendimentoController::finalizar()), NUNCA aceitos do corpo
 * da requisicao (evita forjar senha impressa).
 *
 * NUNCA inclui CPF/CNH na etiqueta — so nome do motorista + nrRegAcesso. O
 * rotulo de UI ("Numero de acesso" vs. outro texto) e responsabilidade do
 * front-end — este controller so devolve o dado bruto.
 */
class ImpressaoAtendimentoController
{
    public function __construct(private AtendimentoDao $atendimentoDao) {}

    /**
     * Reimpressao manual (nunca automatica) sempre gera um NOVO
     * `identificador` de job — cada chamada e logicamente um novo job de
     * impressao, mas sempre do MESMO nrRegAcesso ja persistido (nunca gera
     * um novo check-in, nunca chama o Talent).
     */
    public function gerarEtiqueta(array $entrada, int $idTotem, bool $reimpressao): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        if (!$idAtendimento) {
            Resposta::erro('Dados incompletos');
            return;
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (
            $atendimento['status'] !== 'concluido'
            || $atendimento['talent_checkin_status'] !== 'ENVIADO'
            || empty($atendimento['talent_senha'])
        ) {
            Resposta::erro('Check-in ainda nao confirmado para este atendimento — nao ha etiqueta para imprimir', 409);
            return;
        }

        $nomeMotorista = trim((string) ($atendimento['motorista_nome'] ?? ''));
        $nrRegAcesso = trim((string) $atendimento['talent_senha']);
        if ($nomeMotorista === '' || $nrRegAcesso === '') {
            Resposta::erro('Dados da etiqueta incompletos — procure um atendente', 500);
            return;
        }

        $config = $this->lerConfiguracaoEtiqueta();

        // Identificador SEMPRE novo — nunca reaproveita um job de impressao
        // anterior, mesmo em reimpressao (cada chamada e um novo job,
        // sempre do mesmo nrRegAcesso ja persistido).
        $identificador = bin2hex(random_bytes(16));

        try {
            $pdfBytes = $this->montarPdf($config, $nomeMotorista, $nrRegAcesso);
        } catch (\Throwable $e) {
            error_log('impressao gerar-etiqueta: falha ao gerar PDF: ' . $e->getMessage());
            Resposta::erro('Nao foi possivel gerar a etiqueta agora', 500);
            return;
        }

        // Auditoria simples (log estruturado) — id_atendimento, timestamp,
        // se e reimpressao. Sem dado pessoal alem do proprio id_atendimento
        // (ja e um identificador interno, nao dado pessoal em si).
        error_log(sprintf(
            'impressao gerar-etiqueta: id_atendimento=%d reimpressao=%s timestamp=%s',
            $idAtendimento,
            $reimpressao ? 'sim' : 'nao',
            date('Y-m-d H:i:s')
        ));

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
     * Mesmo padrao de posse (IDOR) ja usado em
     * App\Controller\AtendimentoController::buscarAtendimentoDoTotem —
     * mensagem generica em qualquer caso de falha, nao revela existencia de
     * atendimento alheio.
     */
    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }

    /**
     * Le e valida a configuracao de etiqueta do .env — mesmas 4 variaveis
     * ja usadas por App\Controller\ImpressaoTesteController (ETIQUETA_*),
     * fail-closed (nunca assume default silencioso). Duplicado
     * deliberadamente aqui (em vez de reaproveitar ImpressaoTesteController
     * diretamente, que e isolado por design) — leitura de config e simples
     * o bastante para nao justificar acoplamento entre os dois controllers.
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
            error_log('impressao gerar-etiqueta: configuracao de etiqueta ausente/invalida no .env (ETIQUETA_LARGURA_MM/ETIQUETA_COMPRIMENTO_MM/ETIQUETA_ORIENTACAO/ETIQUETA_CORTE_APOS_IMPRESSAO)');
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
     * Monta o PDF final: so nome do motorista + nrRegAcesso, SEM rotulo fixo
     * de "senha"/"numero de acesso" hardcoded (texto de UI e decisao do
     * front-end para a TELA; aqui usamos um rotulo tecnico neutro so para
     * legibilidade da etiqueta impressa em si). NUNCA CPF/CNH/placa.
     */
    private function montarPdf(array $config, string $nomeMotorista, string $nrRegAcesso): string
    {
        $orientacaoFpdf = $config['orientacao'] === 'landscape' ? 'L' : 'P';

        $pdf = new FPDF($orientacaoFpdf, 'mm', [$config['largura_mm'], $config['comprimento_mm']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(2, 2, 2);
        $pdf->AddPage();

        $larguraUtil = $pdf->GetPageWidth() - 4;

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY(2, 4);
        $pdf->MultiCell($larguraUtil, 5, 'UDLOG', 0, 'C');

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY(2, $pdf->GetY() + 2);
        $pdf->MultiCell($larguraUtil, 4, $nomeMotorista, 0, 'C');

        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetXY(2, $pdf->GetY() + 3);
        $pdf->MultiCell($larguraUtil, 7, $nrRegAcesso, 0, 'C');

        $pdf->SetFont('Arial', '', 6);
        $pdf->SetXY(2, $pdf->GetY() + 3);
        $pdf->MultiCell($larguraUtil, 3.5, 'Gerado em: ' . date('Y-m-d H:i:s'), 0, 'C');

        return $pdf->Output('S');
    }
}
