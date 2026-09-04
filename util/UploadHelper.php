<?php

namespace Util;

class UploadHelper
{
    /**
     * Pasta do atendimento: AAAA-MM-DD/PLACA_HHMMSS — nunca eh usada pra localizar
     * o registro no banco, so serve pra navegacao humana no File Manager.
     */
    public static function montarPasta(string $placa): string
    {
        $agora = new \DateTime('now', new \DateTimeZone('America/Sao_Paulo'));
        $placaLimpa = preg_replace('/[^A-Za-z0-9]/', '', $placa);

        return $agora->format('Y-m-d') . '/' . strtoupper($placaLimpa) . '_' . $agora->format('His');
    }

    /**
     * Salva uma imagem enviada como data URL base64. Nesta fase so JPEG e
     * aceito (unico mimetype usado hoje em todo o totem — nota fiscal, CNH e
     * CRLV). Nome de arquivo e sempre definido pelo servidor (nunca vem do
     * cliente) — quem chama esta funcao ja monta $nomeArquivo com valores
     * controlados no backend (ex: sprintf('nota_%02d.jpg', $ordem)).
     */
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

        // magic bytes reais de JPEG (FF D8 FF) — nao confia no mimetype informado pelo navegador
        if (substr($binario, 0, 3) !== "\xFF\xD8\xFF") {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        // reforco alem dos magic bytes: getimagesizefromstring confirma que o
        // conteudo eh de fato decodificavel como imagem JPEG valida (dimensoes
        // reais), nao so um arquivo com os 3 bytes iniciais forjados
        $info = @getimagesizefromstring($binario);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] <= 0 || $info[1] <= 0) {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        $pastaCompleta = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pastaRelativa;

        if (!is_dir($pastaCompleta)) {
            if (!mkdir($pastaCompleta, 0750, true) && !is_dir($pastaCompleta)) {
                throw new \RuntimeException('Falha ao criar diretorio de armazenamento');
            }
        }

        // $nomeArquivo e sempre definido pelo servidor (sprintf com int validado
        // ou literal fixo) — nunca monta o caminho com dado bruto do cliente
        $caminhoArquivo = $pastaCompleta . '/' . $nomeArquivo;

        if (file_put_contents($caminhoArquivo, $binario) === false) {
            throw new \RuntimeException('Falha ao gravar a imagem no armazenamento');
        }

        return $caminhoArquivo;
    }
}
