<?php

namespace Util;

/**
 * Sanitizacao da mensagem de negocio devolvida por uma API externa (Talent)
 * para exibicao ao usuario. Nunca recebe payload enviado, headers, token nem
 * trace — so o texto do corpo de resposta de erro. NAO persiste e NAO loga.
 *
 * O texto e devolvido como TEXTO (sem escapar HTML): quem renderiza (front)
 * deve usar textContent/escape.
 */
final class MensagemApi
{
    public const LIMITE_CARACTERES = 300;

    /**
     * Extrai a mensagem do corpo cru de uma resposta de erro: `msg` string
     * nao vazia de um JSON objeto; caso contrario o proprio texto cru
     * (contrato do corpo de erro da Talent NAO documentado). Retorna null se
     * nao sobrar texto.
     */
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

    /**
     * Remove caracteres de controle, normaliza espacos e trunca em 300
     * caracteres. Retorna null se vazio.
     */
    public static function sanitizar(string $texto): ?string
    {
        // Garante UTF-8 valido (json_encode da resposta falharia com bytes invalidos).
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        // Controles (inclui \t \r \n, DEL e C1) viram espaco; depois normaliza.
        // Tambem invisiveis/de direcao: U+00AD, U+061C, U+180E, U+2060-2064.
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
