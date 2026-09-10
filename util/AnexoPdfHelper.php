<?php

namespace Util;

use FPDF;

/**
 * Gera os anexos em PDF exigidos pelo Talent (Portaria/Checkin) a partir das
 * imagens JPEG JA validadas em disco pelo scanner/leitor do totem — NUNCA a
 * partir de `image.base64` da resposta da VIO Decode (decisao explicita da
 * demanda integracao-talent-portaria-checkin, 2026-09-09).
 *
 * Biblioteca: setasign/fpdf (PHP puro, sem binario externo, compativel com
 * Hostgator — validado neste ambiente com ext-gd/ext-zlib presentes).
 *
 * Geracao em arquivo temporario DENTRO de storage/ (fora do webroot publico),
 * removido em finally pelo chamador (App\Rn\TalentRn::montarAnexos) apos o
 * base64 do conteudo ja ter sido lido — este helper so devolve os BYTES do
 * PDF gerado, nunca decide onde/quando apagar o arquivo temporario que ele
 * mesmo cria e remove internamente.
 */
class AnexoPdfHelper
{
    private const TAMANHO_MINIMO_BYTES = 1024; // 1 KB — descarta PDF corrompido/vazio

    /**
     * Gera um PDF com uma pagina por imagem JPEG informada (ordem preservada),
     * valida o resultado (assinatura %PDF-, tamanho minimo, contagem de
     * paginas esperada) e retorna os BYTES BINARIOS do PDF final.
     *
     * @param string[] $caminhosJpeg caminhos absolutos, em ordem, das imagens
     *                                JPEG ja validadas em disco
     * @throws \RuntimeException se qualquer imagem for invalida/ilegivel, ou
     *                            se o PDF gerado nao passar na validacao
     */
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

                $larguraUtil = $pdf->GetPageWidth() - 20;  // margens de 10mm nas laterais
                $alturaUtil = $pdf->GetPageHeight() - 20;   // margens de 10mm em cima/baixo

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

            // Grava em arquivo temporario DENTRO de storage/ (fora do webroot)
            // so para permitir a validacao de contagem de paginas de forma
            // consistente com o restante do fluxo de arquivos do projeto —
            // removido no finally, sucesso ou excecao.
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

    /**
     * Validacao do PDF final antes de anexar ao payload do Talent: assinatura
     * `%PDF-`, tamanho minimo razoavel, e contagem de paginas EXATA esperada
     * (2 para CNH com frente+verso, 1 para os demais).
     */
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
