<?php

namespace App\Controller;

use FPDF;
use App\Dao\AtendimentoDao;
use Util\ConfiguracaoServicoImpressao;
use Util\EtiquetaLayout;
use Util\LogSistema;
use Util\Resposta;
use Util\TextoEtiqueta;

class ImpressaoAtendimentoController
{
    public function __construct(private AtendimentoDao $atendimentoDao) {}

    public static function flagReimpressao(array $query, array $entrada): bool
    {
        foreach ([$query['reimpressao'] ?? null, $entrada['reimpressao'] ?? null] as $valor) {
            if ($valor === 1 || $valor === true || $valor === '1') {
                return true;
            }
        }

        return false;
    }

    public function configuracaoServicoLocal(): void
    {
        header('Cache-Control: no-store');

        try {
            $config = ConfiguracaoServicoImpressao::obter();
        } catch (\RuntimeException $e) {
            error_log('impressao configuracao-servico-local: ' . get_class($e));
            LogSistema::registrar('impressao_config_falhou', ['excecao' => $e, 'motivo' => 'config_invalida']);
            Resposta::erro('Servico local de impressao nao configurado', 503);
            return;
        }

        Resposta::sucesso($config);
    }

    public function gerarEtiqueta(array $entrada, int $idTotem, bool $reimpressao): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        if (!$idAtendimento) {
            Resposta::erro('Dados incompletos');
            return;
        }

        $destinatario = 'motorista';
        if (array_key_exists('destinatario', $entrada) && $entrada['destinatario'] !== null && $entrada['destinatario'] !== '') {
            $destinatario = $entrada['destinatario'];
            if (!is_string($destinatario) || !in_array($destinatario, ['motorista', 'ajudante'], true)) {
                Resposta::erro('Destinatario invalido: use motorista ou ajudante', 422);
                return;
            }
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

        $nomeMotorista = TextoEtiqueta::paraAscii((string) ($atendimento['motorista_nome'] ?? ''));
        $nrRegAcesso = TextoEtiqueta::paraAscii((string) $atendimento['talent_senha']);
        if ($nrRegAcesso === '' || ($destinatario === 'motorista' && $nomeMotorista === '')) {
            Resposta::erro('Dados da etiqueta incompletos — procure um atendente', 500);
            return;
        }

        $nomeAjudante = '';
        if ((int) ($atendimento['possui_ajudante'] ?? 0) === 1) {
            $nomeAjudante = $this->sanitizarNomeAjudante($atendimento['ajudante_nome'] ?? '');
        }

        if ($destinatario === 'ajudante' && $nomeAjudante === '') {
            Resposta::erro('Este atendimento nao possui ajudante — nao ha etiqueta de ajudante para imprimir', 409);
            return;
        }

        $config = $this->lerConfiguracaoEtiqueta($idAtendimento, $idTotem, (string) $atendimento['tipo']);

        $identificador = bin2hex(random_bytes(16));

        try {
            $pdfBytes = $this->montarPdf($config, $nomeMotorista, $nrRegAcesso, $nomeAjudante, $destinatario);
        } catch (\Throwable $e) {
            error_log('impressao gerar-etiqueta: falha ao gerar PDF: ' . get_class($e));
            LogSistema::registrar('etiqueta_pdf_falhou', ['id_atendimento' => $idAtendimento, 'id_totem' => $idTotem, 'tipo' => (string) $atendimento['tipo'], 'excecao' => $e]);
            Resposta::erro('Nao foi possivel gerar a etiqueta agora', 500);
            return;
        }

        error_log(sprintf(
            'impressao gerar-etiqueta: id_atendimento=%d destinatario=%s reimpressao=%s timestamp=%s',
            $idAtendimento,
            $destinatario,
            $reimpressao ? 'sim' : 'nao',
            date('Y-m-d H:i:s')
        ));

        $resposta = [
            'pdf_base64' => base64_encode($pdfBytes),
            'identificador' => $identificador,
            'largura_mm' => $config['largura_mm'],
            'comprimento_mm' => $config['comprimento_mm'],
            'orientacao' => $config['orientacao'],
            'corte_apos_impressao' => $config['corte_apos_impressao'],
            'destinatario' => $destinatario,
        ];
        if ($destinatario === 'motorista') {
            $resposta['tem_etiqueta_ajudante'] = $nomeAjudante !== '';
        }

        Resposta::sucesso($resposta);
    }

    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }

    private function lerConfiguracaoEtiqueta(int $idAtendimento, int $idTotem, string $tipo): array
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
            LogSistema::registrar('etiqueta_config_invalida', ['id_atendimento' => $idAtendimento, 'id_totem' => $idTotem, 'tipo' => $tipo, 'motivo' => 'config_invalida']);
            Resposta::erro('Configuracao de etiqueta ausente ou invalida', 500);
        }

        return [
            'largura_mm' => (float) $largura,
            'comprimento_mm' => (float) $comprimento,
            'orientacao' => $orientacao,
            'corte_apos_impressao' => strtolower((string) $corte) === 'true',
        ];
    }

    private function sanitizarNomeAjudante(mixed $nome): string
    {
        if (!is_string($nome)) {
            return '';
        }
        return TextoEtiqueta::paraAscii($nome, 150);
    }

    private function montarPdf(array $config, string $nomeMotorista, string $nrRegAcesso, string $nomeAjudante = '', string $destinatario = 'motorista'): string
    {
        $nomeMotorista = TextoEtiqueta::paraAscii($nomeMotorista);
        $nrRegAcesso = TextoEtiqueta::paraAscii($nrRegAcesso);
        $nomeAjudante = TextoEtiqueta::paraAscii($nomeAjudante, 150);

        $orientacaoFpdf = $config['orientacao'] === 'landscape' ? 'L' : 'P';

        $pdf = new FPDF($orientacaoFpdf, 'mm', [$config['largura_mm'], $config['comprimento_mm']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(2, 2, 2);
        $pdf->AddPage();

        if ($destinatario === 'ajudante') {
            EtiquetaLayout::desenhar($pdf, [
                ['texto' => 'AJUDANTE', 'estilo' => 'B', 'pt' => 22, 'max_linhas' => 1, 'espaco_antes_mm' => 0],
                ['texto' => $nomeAjudante, 'estilo' => '', 'pt' => 15, 'max_linhas' => 3, 'espaco_antes_mm' => 2],
                ['texto' => $nrRegAcesso, 'estilo' => 'B', 'pt' => 60, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
                ['texto' => 'Gerado em: ' . date('Y-m-d H:i:s'), 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
            ]);
            return $pdf->Output('S');
        }

        EtiquetaLayout::desenhar($pdf, [
            ['texto' => 'UDLOG', 'estilo' => 'B', 'pt' => 22, 'max_linhas' => 1, 'espaco_antes_mm' => 0],
            ['texto' => $nomeMotorista, 'estilo' => '', 'pt' => 15, 'max_linhas' => 3, 'espaco_antes_mm' => 2],
            ['texto' => $nrRegAcesso, 'estilo' => 'B', 'pt' => 60, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
            ['texto' => 'Gerado em: ' . date('Y-m-d H:i:s'), 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
        ]);

        return $pdf->Output('S');
    }
}
