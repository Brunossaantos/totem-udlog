<?php

namespace Util;

final class TotemUrlBase
{
    private const REGEX = '#\Ahttps?://[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?(?:/(?!\.{1,2}(?:/|\z))[A-Za-z0-9._~-]+)*/\z#D';

    public static function doAmbiente(array $env): ?string
    {
        $v = $env['TOTEM_URL_BASE'] ?? null;
        if (!is_string($v) || strlen($v) > 300 || preg_match(self::REGEX, $v) !== 1) {
            return null;
        }

        return $v;
    }

    public static function urlDoCodigo(string $base, string $codigo): string
    {
        return $base . '?totem=' . rawurlencode($codigo);
    }
}
