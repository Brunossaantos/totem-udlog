<?php

namespace Util;

final class IpCliente
{
    public const FAIXAS_CLOUDFLARE_DATA = '2026-10-06';

    public const FAIXAS_CLOUDFLARE_V4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    public const FAIXAS_CLOUDFLARE_V6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public const IP_DESCONHECIDO = '0.0.0.0';

    public static function obter(array $server): string
    {
        $remoto = self::normalizar((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoto === null) {
            return self::IP_DESCONHECIDO;
        }

        if (self::pertenceAoCloudflare($remoto)) {
            $cabecalho = $server['HTTP_CF_CONNECTING_IP'] ?? null;
            if (is_string($cabecalho)) {
                $cliente = self::normalizar(trim($cabecalho));
                if ($cliente !== null) {
                    return $cliente;
                }
            }
        }

        return $remoto;
    }

    public static function pertenceAoCloudflare(string $ip): bool
    {
        $ip = self::normalizar($ip);
        if ($ip === null) {
            return false;
        }
        $faixas = str_contains($ip, ':') ? self::FAIXAS_CLOUDFLARE_V6 : self::FAIXAS_CLOUDFLARE_V4;
        foreach ($faixas as $cidr) {
            if (self::noCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizar(string $ip): ?string
    {
        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\x00", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }
        $texto = inet_ntop($bin);

        return $texto === false ? null : strtolower($texto);
    }

    public static function paraBinario(string $ip): ?string
    {
        $ip = self::normalizar($ip);
        if ($ip === null) {
            return null;
        }
        $bin = @inet_pton($ip);

        return $bin === false ? null : $bin;
    }

    public static function hash(string $ip, string $sal): string
    {
        return hash('sha256', $ip . $sal);
    }

    public static function balde(string $ip): string
    {
        $normal = self::normalizar($ip);
        if ($normal === null) {
            return $ip;
        }
        $bin = @inet_pton($normal);
        if ($bin === false || strlen($bin) !== 16) {
            return $normal;
        }
        $prefixo = inet_ntop(substr($bin, 0, 8) . str_repeat("\x00", 8));

        return ($prefixo === false ? $normal : strtolower($prefixo)) . '/64';
    }

    private static function noCidr(string $ip, string $cidr): bool
    {
        [$base, $bits] = explode('/', $cidr, 2);
        $binIp = @inet_pton($ip);
        $binBase = @inet_pton($base);
        if ($binIp === false || $binBase === false || strlen($binIp) !== strlen($binBase)) {
            return false;
        }
        $bits = (int) $bits;
        $bytesInteiros = intdiv($bits, 8);
        if ($bytesInteiros > 0 && substr($binIp, 0, $bytesInteiros) !== substr($binBase, 0, $bytesInteiros)) {
            return false;
        }
        $resto = $bits % 8;
        if ($resto === 0) {
            return true;
        }
        $mascara = (0xFF << (8 - $resto)) & 0xFF;

        return (ord($binIp[$bytesInteiros]) & $mascara) === (ord($binBase[$bytesInteiros]) & $mascara);
    }
}
