<?php

namespace Util;

use FPDF;

class AnexoPdfHelper
{
    private const TAMANHO_MINIMO_BYTES = 1024;

    public static function gerarPdfDeImagens(array $caminhosJpeg): string
    {
        if (count($caminhosJpeg) === 0) {
            throw new \RuntimeException('Nenhuma imagem informada para gerar o PDF');
        }

        $paginasEsperadas = count($caminhosJpeg);
        $arquivoTemporario = null;

        try {
            $pdf = new FPDF('P', 'mm', 'A4');
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(10, 10, 10);

            foreach ($caminhosJpeg as $caminho) {
                self::validarJpeg($caminho);

                $info = @getimagesize($caminho);
                if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
                    throw new \RuntimeException('Nao foi possivel ler as dimensoes da imagem: ' . basename($caminho));
                }

                [$larguraPx, $alturaPx] = $info;

                $pdf->AddPage();

                $larguraUtil = $pdf->GetPageWidth() - 20;
                $alturaUtil = $pdf->GetPageHeight() - 20;

                $proporcao = $larguraPx / $alturaPx;
                $larguraFinal = $larguraUtil;
                $alturaFinal = $larguraFinal / $proporcao;

                if ($alturaFinal > $alturaUtil) {
                    $alturaFinal = $alturaUtil;
                    $larguraFinal = $alturaFinal * $proporcao;
                }

                $x = (210 - $larguraFinal) / 2;
                $y = (297 - $alturaFinal) / 2;

                $pdf->Image($caminho, $x, $y, $larguraFinal, $alturaFinal, 'JPG');
            }

            $conteudoPdf = $pdf->Output('S');

            $pastaTmp = rtrim($_ENV['STORAGE_PATH'] ?? '', '/') . '/tmp';
            if (!is_dir($pastaTmp)) {
                if (!mkdir($pastaTmp, 0750, true) && !is_dir($pastaTmp)) {
                    throw new \RuntimeException('Falha ao criar diretorio temporario de anexos');
                }
            }
            $arquivoTemporario = $pastaTmp . '/' . bin2hex(random_bytes(16)) . '.pdf';

            if (file_put_contents($arquivoTemporario, $conteudoPdf) === false) {
                throw new \RuntimeException('Falha ao gravar PDF temporario');
            }

            self::validarPdfGerado($conteudoPdf, $paginasEsperadas);

            return $conteudoPdf;
        } finally {
            if ($arquivoTemporario !== null && is_file($arquivoTemporario)) {
                @unlink($arquivoTemporario);
            }
        }
    }

    private static function validarJpeg(string $caminho): void
    {
        if (!is_file($caminho)) {
            throw new \RuntimeException('Arquivo de imagem nao encontrado: ' . basename($caminho));
        }

        $binario = file_get_contents($caminho);
        if ($binario === false || substr($binario, 0, 3) !== "\xFF\xD8\xFF") {
            throw new \RuntimeException('Arquivo nao e um JPEG valido: ' . basename($caminho));
        }
    }

    private static function validarPdfGerado(string $conteudoPdf, int $paginasEsperadas): void
    {
        if (substr($conteudoPdf, 0, 5) !== '%PDF-') {
            throw new \RuntimeException('PDF gerado nao tem assinatura valida');
        }

        if (strlen($conteudoPdf) < self::TAMANHO_MINIMO_BYTES) {
            throw new \RuntimeException('PDF gerado tem tamanho abaixo do minimo esperado');
        }

        $paginasEncontradas = preg_match_all('/\/Type\s*\/Page[^s]/', $conteudoPdf);
        if ($paginasEncontradas !== $paginasEsperadas) {
            throw new \RuntimeException('PDF gerado nao tem a contagem de paginas esperada');
        }
    }
}
