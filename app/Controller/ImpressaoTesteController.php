<?php

namespace App\Controller;

use FPDF;
use Util\ConfiguracaoServicoImpressao;
use Util\EtiquetaLayout;
use Util\Resposta;
use Util\TextoEtiqueta;

class ImpressaoTesteController
{
    public function gerarEtiqueta(): void
    {
        $config = $this->lerConfiguracaoEtiqueta();
        $corpo = $this->lerCorpoJson();
        $config = $this->aplicarSobreposicao($config, $corpo);

        $destinatario = 'motorista';
        if (array_key_exists('destinatario', $corpo) && $corpo['destinatario'] !== null && $corpo['destinatario'] !== '') {
            if (!is_string($corpo['destinatario']) || !in_array($corpo['destinatario'], ['motorista', 'ajudante'], true)) {
                Resposta::erro('destinatario invalido: use motorista ou ajudante', 422);
                return;
            }
            $destinatario = $corpo['destinatario'];
        }

        $identificador = bin2hex(random_bytes(16));

        try {
            $pdfBytes = $this->montarPdf($config, $identificador, $destinatario);
        } catch (\Throwable $e) {
            error_log('impressao-teste gerar-etiqueta: falha ao gerar PDF: ' . get_class($e));
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
            'pagina_gerada_mm' => $this->extrairMediaBoxMm($pdfBytes),
            'destinatario' => $destinatario,
        ]);
    }

    public function configuracaoEtiqueta(): void
    {
        header('Cache-Control: no-store');
        Resposta::sucesso($this->lerConfiguracaoEtiqueta());
    }

    public function etiquetaPronta(): void
    {
        header('Cache-Control: no-store');
        $caminho = __DIR__ . '/../../docs/50x80.pdf';

        if (!is_file($caminho) || !is_readable($caminho)) {
            Resposta::erro('Arquivo docs/50x80.pdf nao encontrado no servidor', 404);
            return;
        }
        $bytes = file_get_contents($caminho);
        if ($bytes === false || strncmp($bytes, '%PDF-', 5) !== 0) {
            Resposta::erro('Arquivo docs/50x80.pdf invalido', 500);
            return;
        }

        Resposta::sucesso([
            'pdf_base64' => base64_encode($bytes),
            'identificador' => bin2hex(random_bytes(16)),
            'origem' => 'docs/50x80.pdf',
            'pagina_gerada_mm' => $this->extrairMediaBoxMm($bytes),
        ]);
    }

    private function lerCorpoJson(): array
    {
        $bruto = file_get_contents('php://input');
        if ($bruto === false || trim($bruto) === '') {
            return [];
        }
        $json = json_decode($bruto, true);
        return is_array($json) ? $json : [];
    }

    private function aplicarSobreposicao(array $config, array $corpo): array
    {
        foreach (['largura_mm', 'comprimento_mm'] as $campo) {
            if (!array_key_exists($campo, $corpo) || $corpo[$campo] === null || $corpo[$campo] === '') {
                continue;
            }
            $v = $corpo[$campo];
            if ((!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) || !is_finite((float) $v)
                || (float) $v < 20 || (float) $v > 300) {
                Resposta::erro($campo . ' invalido: informe um numero entre 20 e 300 mm', 422);
            }
            $config[$campo] = (float) $v;
        }
        if (array_key_exists('orientacao', $corpo) && $corpo['orientacao'] !== null && $corpo['orientacao'] !== '') {
            if (!is_string($corpo['orientacao']) || !in_array($corpo['orientacao'], ['portrait', 'landscape'], true)) {
                Resposta::erro('orientacao invalida: use portrait ou landscape', 422);
            }
            $config['orientacao'] = $corpo['orientacao'];
        }
        return $config;
    }

    private function extrairMediaBoxMm(string $pdf): ?array
    {
        if (!preg_match('/\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $pdf, $m)) {
            return null;
        }
        $k = 25.4 / 72;
        return [
            'largura' => round(((float) $m[3] - (float) $m[1]) * $k, 1),
            'altura' => round(((float) $m[4] - (float) $m[2]) * $k, 1),
        ];
    }

    public function configuracaoServicoLocal(): void
    {
        header('Cache-Control: no-store');

        try {
            $config = ConfiguracaoServicoImpressao::obter();
        } catch (\RuntimeException $e) {
            error_log('impressao-teste configuracao-servico-local: ' . get_class($e));
            Resposta::erro('Servico local de impressao nao configurado', 503);
            return;
        }

        Resposta::sucesso($config);
    }

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

    private function montarPdf(array $config, string $identificador, string $destinatario = 'motorista'): string
    {
        $identificador = TextoEtiqueta::paraAscii($identificador);
        $orientacaoFpdf = $config['orientacao'] === 'landscape' ? 'L' : 'P';

        $pdf = new FPDF($orientacaoFpdf, 'mm', [$config['largura_mm'], $config['comprimento_mm']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(2, 2, 2);
        $pdf->AddPage();

        if ($destinatario === 'ajudante') {
            EtiquetaLayout::desenhar($pdf, [
                ['texto' => 'AJUDANTE', 'estilo' => 'B', 'pt' => 22, 'max_linhas' => 1, 'espaco_antes_mm' => 0],
                ['texto' => TextoEtiqueta::paraAscii('TESTE FICTICIO'), 'estilo' => '', 'pt' => 15, 'max_linhas' => 3, 'espaco_antes_mm' => 2],
                ['texto' => TextoEtiqueta::paraAscii('123456'), 'estilo' => 'B', 'pt' => 60, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
                ['texto' => 'ETIQUETA DE TESTE - NAO UTILIZAR', 'estilo' => 'B', 'pt' => 9, 'max_linhas' => 2, 'espaco_antes_mm' => 2],
                ['texto' => 'ID: ' . $identificador, 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 1],
                ['texto' => 'Gerado em: ' . date('Y-m-d H:i:s'), 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 1],
            ]);
            return $pdf->Output('S');
        }

        EtiquetaLayout::desenhar($pdf, [
            ['texto' => 'ETIQUETA DE TESTE', 'estilo' => 'B', 'pt' => 22, 'max_linhas' => 2, 'espaco_antes_mm' => 0],
            ['texto' => 'NAO UTILIZAR', 'estilo' => 'B', 'pt' => 18, 'max_linhas' => 1, 'espaco_antes_mm' => 1],
            ['texto' => 'Diagnostico de impressao - sem dado pessoal', 'estilo' => '', 'pt' => 10, 'max_linhas' => 2, 'espaco_antes_mm' => 3],
            ['texto' => 'ID: ' . $identificador, 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 2],
            ['texto' => 'Gerado em: ' . date('Y-m-d H:i:s'), 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 1],
        ]);

        return $pdf->Output('S');
    }
}
