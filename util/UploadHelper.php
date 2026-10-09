<?php

namespace Util;

class UploadHelper
{
    private const JPEG_DIMENSAO_MAXIMA_PX = 5000;

    private const JPEG_AREA_MAXIMA_PX = 13000000;

    public static function decodificarJpegBase64Seguro(string $base64): string
    {
        if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/]*={0,2})$#D', $base64, $partes)) {
            throw new \RuntimeException('Formato de imagem nao suportado');
        }
        $binario = base64_decode($partes[1], true);
        $maxBytes = isset($_ENV['NOTA_IMAGEM_MAX_BYTES']) && $_ENV['NOTA_IMAGEM_MAX_BYTES'] !== ''
            ? (int) $_ENV['NOTA_IMAGEM_MAX_BYTES'] : 5 * 1024 * 1024;
        if ($binario === false || $binario === '' || strlen($binario) > $maxBytes
            || substr($binario, 0, 3) !== "\xFF\xD8\xFF" || substr($binario, -2) !== "\xFF\xD9") {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }
        $info = @getimagesizefromstring($binario);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] <= 0 || $info[1] <= 0
            || $info[0] > self::JPEG_DIMENSAO_MAXIMA_PX || $info[1] > self::JPEG_DIMENSAO_MAXIMA_PX
            || $info[0] > self::JPEG_AREA_MAXIMA_PX / $info[1]
            || !extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }
        $aviso = false;
        set_error_handler(static function () use (&$aviso): bool { $aviso = true; return true; });
        try {
            $imagem = imagecreatefromstring($binario);
            if ($imagem === false || $aviso) {
                throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
            }
            imagedestroy($imagem);
        } finally {
            restore_error_handler();
        }
        return $binario;
    }

    public static function montarPasta(string $placa): string
    {
        $agora = new \DateTime('now', new \DateTimeZone('America/Sao_Paulo'));
        $placaLimpa = preg_replace('/[^A-Za-z0-9]/', '', $placa);

        return $agora->format('Y-m-d') . '/' . strtoupper($placaLimpa) . '_' . $agora->format('His');
    }

    public static function salvarImagemBase64(string $base64, string $pastaRelativa, string $nomeArquivo): string
    {
        if (!preg_match('#^data:image/jpeg;base64,#', $base64)) {
            throw new \RuntimeException('Formato de imagem nao suportado: apenas JPEG e aceito');
        }

        $base64Limpo = preg_replace('#^data:image/jpeg;base64,#', '', $base64);
        $binario = base64_decode($base64Limpo, true);

        if ($binario === false || $binario === '') {
            throw new \RuntimeException('Falha ao decodificar imagem');
        }

        $maxBytes = isset($_ENV['NOTA_IMAGEM_MAX_BYTES']) && $_ENV['NOTA_IMAGEM_MAX_BYTES'] !== ''
            ? (int) $_ENV['NOTA_IMAGEM_MAX_BYTES']
            : 5 * 1024 * 1024;

        if (strlen($binario) > $maxBytes) {
            throw new \RuntimeException('Imagem excede o tamanho maximo permitido');
        }

        if (substr($binario, 0, 3) !== "\xFF\xD8\xFF") {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        if (substr($binario, -2) !== "\xFF\xD9") {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        $info = @getimagesizefromstring($binario);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] <= 0 || $info[1] <= 0) {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        $largura = $info[0];
        $altura = $info[1];

        if ($largura > self::JPEG_DIMENSAO_MAXIMA_PX || $altura > self::JPEG_DIMENSAO_MAXIMA_PX) {
            throw new \RuntimeException('Imagem excede a dimensao maxima permitida');
        }

        if ($largura > self::JPEG_AREA_MAXIMA_PX / $altura) {
            throw new \RuntimeException('Imagem excede a dimensao maxima permitida');
        }

        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        $houveWarningGd = false;
        $imagemGd = false;

        set_error_handler(static function () use (&$houveWarningGd): bool {
            $houveWarningGd = true;
            return true;
        });

        try {
            $imagemGd = imagecreatefromstring($binario);

            if ($imagemGd === false || $houveWarningGd) {
                throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
            }
        } finally {
            if ($imagemGd !== false) {
                imagedestroy($imagemGd);
            }

            restore_error_handler();
        }

        $pastaCompleta = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pastaRelativa;

        if (!is_dir($pastaCompleta)) {
            if (!mkdir($pastaCompleta, 0750, true) && !is_dir($pastaCompleta)) {
                throw new \RuntimeException('Falha ao criar diretorio de armazenamento');
            }
        }

        $caminhoArquivo = $pastaCompleta . '/' . $nomeArquivo;

        if (file_put_contents($caminhoArquivo, $binario) === false) {
            throw new \RuntimeException('Falha ao gravar a imagem no armazenamento');
        }

        return $caminhoArquivo;
    }
}
