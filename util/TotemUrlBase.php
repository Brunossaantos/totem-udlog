<?php

namespace Util;

/**
 * URL base do quiosque (variavel TOTEM_URL_BASE do .env, demanda gestao-totem,
 * F2). NUNCA e derivada do cabecalho Host: so do ambiente. Formato aceito:
 * http ou https, host simples (letras, digitos, ponto, hifen) com porta opcional,
 * caminho opcional, SEM espaco, query, fragmento nem credenciais, e termina em "/".
 */
final class TotemUrlBase
{
    private const REGEX = '#\Ahttps?://[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?(?:/(?!\.{1,2}(?:/|\z))[A-Za-z0-9._~-]+)*/\z#D';

    /** @param array<string,mixed> $env tipicamente $_ENV @return string|null a base valida, ou null se ausente/invalida */
    public static function doAmbiente(array $env): ?string
    {
        $v = $env['TOTEM_URL_BASE'] ?? null;
        if (!is_string($v) || strlen($v) > 300 || preg_match(self::REGEX, $v) !== 1) {
            return null;
        }

        return $v;
    }

    /** URL completa do quiosque para um codigo (o codigo ja e [A-Z0-9-]). */
    public static function urlDoCodigo(string $base, string $codigo): string
    {
        return $base . '?totem=' . rawurlencode($codigo);
    }
}
