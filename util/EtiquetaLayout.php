<?php

namespace Util;

use FPDF;

/**
 * Layout compartilhado das etiquetas (producao: ImpressaoAtendimentoController,
 * diagnostico: ImpressaoTesteController). So desenho/medicao no FPDF: sem I/O,
 * sem banco, sem dado pessoal proprio (so desenha o texto recebido).
 *
 * Regras: conteudo colado no topo (TOPO_MM), restrito a uma AREA UTIL de
 * largura maxima 48 mm (env opcional ETIQUETA_AREA_UTIL_MM, 20 a largura da
 * pagina; invalido => 48) que comeca a 1 mm da borda esquerda (a impressora
 * corta em ~50,8 mm), centralizado nela; margem inferior de 3 mm; fontes base definidas para uma pagina de 80 mm de largura
 * (escala inicial 1, independente da pagina). Cada bloco e reduzido de 1 em 1 pt
 * ate caber na largura util (e no maximo de linhas); se a altura total ainda
 * estourar, todo o conjunto e reduzido proporcionalmente. Nunca cria 2a pagina.
 */
final class EtiquetaLayout
{
    private const MARGEM_ESQUERDA_MM = 1.0;
    private const AREA_UTIL_PADRAO_MM = 48.0;
    private const AREA_UTIL_MIN_MM = 20.0;
    private const TOPO_MM = 2.5;
    private const MARGEM_INFERIOR_MM = 3.0;
    private const LARGURA_BASE_MM = 80.0;
    private const PT_MM = 25.4 / 72;
    private const ENTRELINHA = 1.15;
    private const PT_MINIMO_ABSOLUTO = 5;

    /**
     * Largura util (mm) do conteudo para uma pagina: ETIQUETA_AREA_UTIL_MM
     * (opcional; numerico entre 20 e a largura da pagina, senao 48), limitada
     * a (largura da pagina - 2 mm) para manter 1 mm de folga em cada borda.
     */
    public static function areaUtilMm(float $larguraPaginaMm): float
    {
        $area = self::AREA_UTIL_PADRAO_MM;
        $env = $_ENV['ETIQUETA_AREA_UTIL_MM'] ?? '';
        if (is_string($env) || is_int($env) || is_float($env)) {
            $env = trim((string) $env);
            if ($env !== '' && is_numeric($env) && is_finite((float) $env)
                && (float) $env >= self::AREA_UTIL_MIN_MM && (float) $env <= $larguraPaginaMm) {
                $area = (float) $env;
            }
        }
        return max(1.0, min($area, $larguraPaginaMm - 2 * self::MARGEM_ESQUERDA_MM));
    }

    /**
     * @param array $blocos lista de ['texto'=>string,'estilo'=>''|'B','pt'=>int base (pagina 80 mm),
     *                      'max_linhas'=>int,'espaco_antes_mm'=>float base]
     * @return array medidas: escala, x_inicio, x_fim, y_final, limite_y, largura_util, blocos[] (pt, linhas, largura_max)
     */
    public static function desenhar(FPDF $pdf, array $blocos): array
    {
        $larguraUtil = self::areaUtilMm($pdf->GetPageWidth());
        // Area util [x0, x0 + larguraUtil], com x0 = 1 mm (centralizada nela).
        $x0 = self::MARGEM_ESQUERDA_MM;
        $limiteY = $pdf->GetPageHeight() - self::MARGEM_INFERIOR_MM;
        // Fontes base ja sao dimensionadas para a area util (nao para a pagina):
        // escala inicial 1; a reducao por largura/altura acontece abaixo.
        $kBase = 1.0;

        $plano = null;
        for ($k = $kBase; $k >= 0.1; $k -= 0.02) {
            $plano = self::planejar($pdf, $blocos, $k, $larguraUtil);
            if (self::TOPO_MM + $plano['altura'] <= $limiteY) {
                break;
            }
        }

        $y = self::TOPO_MM;
        $medidas = [];
        foreach ($plano['blocos'] as $i => $b) {
            $y += $b['espaco'];
            $pdf->SetFont('Arial', $blocos[$i]['estilo'], $b['pt']);
            // MultiCell tem 1 mm de margem interna por lado: compensa para o
            // texto ocupar exatamente a largura util.
            $pdf->SetXY($x0 - 1, $y);
            $pdf->MultiCell($larguraUtil + 2, $b['lh'], $blocos[$i]['texto'], 0, 'C');
            $y += $b['linhas'] * $b['lh'];
            $medidas[] = ['pt' => $b['pt'], 'linhas' => $b['linhas'], 'largura_max' => $b['largura_max']];
        }

        return [
            'escala' => round($k, 3),
            'y_final' => $pdf->GetY(),
            'limite_y' => $limiteY,
            'largura_util' => $larguraUtil,
            'x_inicio' => $x0,
            'x_fim' => $x0 + $larguraUtil,
            'blocos' => $medidas,
        ];
    }

    private static function planejar(FPDF $pdf, array $blocos, float $k, float $larguraUtil): array
    {
        $saida = [];
        $altura = 0.0;
        foreach ($blocos as $bloco) {
            $ptMin = self::PT_MINIMO_ABSOLUTO;
            $pt = max($ptMin, (int) round($bloco['pt'] * $k));
            $maxLinhas = max(1, (int) $bloco['max_linhas']);
            do {
                $pdf->SetFont('Arial', $bloco['estilo'], $pt);
                $info = self::medir($pdf, (string) $bloco['texto'], $larguraUtil);
                $cabe = $info !== null && $info['linhas'] <= $maxLinhas;
                if ($cabe || $pt <= $ptMin) {
                    break;
                }
                $pt--;
            } while (true);

            if ($info === null) { // palavra maior que a largura mesmo no minimo: MultiCell quebra por caractere
                $info = [
                    'linhas' => (int) ceil($pdf->GetStringWidth((string) $bloco['texto']) / $larguraUtil),
                    'largura_max' => $larguraUtil,
                ];
            }
            $lh = $pt * self::PT_MM * self::ENTRELINHA;
            $espaco = (float) $bloco['espaco_antes_mm'] * $k;
            $altura += $espaco + $info['linhas'] * $lh;
            $saida[] = [
                'pt' => $pt, 'lh' => $lh, 'espaco' => $espaco,
                'linhas' => $info['linhas'], 'largura_max' => $info['largura_max'],
            ];
        }
        return ['blocos' => $saida, 'altura' => $altura];
    }

    /** Quebra gulosa por palavras (igual ao MultiCell); null se alguma palavra nao couber na largura. */
    private static function medir(FPDF $pdf, string $texto, float $larguraUtil): ?array
    {
        $linhas = 1;
        $atual = '';
        $maior = 0.0;
        foreach (preg_split('/\s+/', trim($texto)) ?: [] as $palavra) {
            if ($pdf->GetStringWidth($palavra) > $larguraUtil) {
                return null;
            }
            $teste = $atual === '' ? $palavra : $atual . ' ' . $palavra;
            if ($pdf->GetStringWidth($teste) <= $larguraUtil) {
                $atual = $teste;
            } else {
                $maior = max($maior, $pdf->GetStringWidth($atual));
                $linhas++;
                $atual = $palavra;
            }
        }
        $maior = max($maior, $pdf->GetStringWidth($atual));
        return ['linhas' => $linhas, 'largura_max' => $maior];
    }
}
