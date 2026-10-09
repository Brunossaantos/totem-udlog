<?php

namespace Util;

final class MensagemApi
{
    public const LIMITE_CARACTERES = 300;

    public static function extrairDoCorpo(string $corpo): ?string
    {
        $dados = json_decode($corpo, true);
        if (is_array($dados) && isset($dados['msg']) && is_string($dados['msg'])) {
            $msg = self::sanitizar($dados['msg']);
            if ($msg !== null) {
                return $msg;
            }
        }

        return self::sanitizar($corpo);
    }

    public static function sanitizar(string $texto): ?string
    {
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        $texto = preg_replace('/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{00AD}\x{061C}\x{180E}\x{2028}\x{2029}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $texto) ?? '';
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto, 'UTF-8') > self::LIMITE_CARACTERES) {
            $texto = rtrim(mb_substr($texto, 0, self::LIMITE_CARACTERES, 'UTF-8'));
        }

        return $texto;
    }
}
