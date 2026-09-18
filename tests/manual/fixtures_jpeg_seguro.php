<?php

/**
 * Fixtures sinteticas para a demanda validacao-jpeg-segura (/02-testes).
 * TODAS as imagens sao geradas em memoria via GD (imagecreatetruecolor +
 * imagejpeg) ou por manipulacao manual de bytes de fixtures ja geradas
 * assim -- NUNCA documento, CPF, CNH, CRLV, placa ou imagem pessoal real.
 *
 * Nenhuma funcao aqui imprime ou loga conteudo binario/base64.
 *
 * Uso: require_once __DIR__ . '/fixtures_jpeg_seguro.php';
 */

/**
 * Gera um JPEG valido via GD. $ruido=true preenche com pixels aleatorios
 * (dados de scan com entropia real, util para os casos de corrupcao de
 * meio de stream) em vez de cor solida.
 */
function jpegFixtureGerar(int $largura, int $altura, int $qualidade = 80, bool $progressivo = false, bool $ruido = false): string
{
    $img = imagecreatetruecolor($largura, $altura);

    if ($ruido) {
        for ($y = 0; $y < $altura; $y++) {
            for ($x = 0; $x < $largura; $x++) {
                $cor = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagesetpixel($img, $x, $y, $cor);
            }
        }
    } else {
        $cor = imagecolorallocate($img, 120, 180, 220);
        imagefill($img, 0, 0, $cor);
    }

    if ($progressivo) {
        imageinterlace($img, true);
    }

    ob_start();
    imagejpeg($img, null, $qualidade);
    $binario = ob_get_clean();
    imagedestroy($img);

    return $binario;
}

/** Gera um PNG valido via GD (usado para simular MIME/extensao falsos). */
function pngFixtureGerar(int $largura, int $altura): string
{
    $img = imagecreatetruecolor($largura, $altura);
    $cor = imagecolorallocate($img, 10, 200, 90);
    imagefill($img, 0, 0, $cor);

    ob_start();
    imagepng($img);
    $binario = ob_get_clean();
    imagedestroy($img);

    return $binario;
}

/** Monta o data URL no formato exigido pela Etapa 1 de UploadHelper. */
function jpegFixtureParaDataUrl(string $binario): string
{
    return 'data:image/jpeg;base64,' . base64_encode($binario);
}

/** Retorna a posicao (offset) do marcador SOS (Start of Scan, FFDA) em um JPEG valido. */
function jpegFixtureOffsetSos(string $binario): ?int
{
    $pos = strpos($binario, "\xFF\xDA");
    return $pos === false ? null : $pos;
}

/** Retorna a posicao do primeiro marcador SOF0/SOF2 (FFC0/FFC2). */
function jpegFixtureOffsetSof(string $binario): ?int
{
    $posSof0 = strpos($binario, "\xFF\xC0");
    $posSof2 = strpos($binario, "\xFF\xC2");
    if ($posSof0 === false && $posSof2 === false) {
        return null;
    }
    if ($posSof0 === false) {
        return $posSof2;
    }
    if ($posSof2 === false) {
        return $posSof0;
    }
    return min($posSof0, $posSof2);
}

/** Confirma se o binario termina exatamente em FFD9. */
function jpegFixtureTemEoiNoFim(string $binario): bool
{
    return substr($binario, -2) === "\xFF\xD9";
}

/**
 * Constroi um JPEG sintetico simulando metadado APP1/EXIF com um
 * "thumbnail" JPEG completo embutido -- insere o segmento logo apos o SOI
 * do JPEG externo, respeitando o campo de tamanho do segmento (nao e busca
 * ingenua de substring).
 */
function jpegFixtureComThumbnailEmbutido(string $jpegExterno, string $jpegThumbnail): string
{
    $soi = substr($jpegExterno, 0, 2); // FFD8
    $resto = substr($jpegExterno, 2);

    $dadosApp1 = "Exif\x00\x00" . $jpegThumbnail;
    $tamanhoSegmento = strlen($dadosApp1) + 2; // campo de tamanho inclui os 2 bytes dele mesmo

    if ($tamanhoSegmento > 0xFFFF) {
        throw new \RuntimeException('Thumbnail grande demais para um segmento APP1 unico (fixture de teste)');
    }

    $app1 = "\xFF\xE1" . pack('n', $tamanhoSegmento) . $dadosApp1;

    return $soi . $app1 . $resto;
}
