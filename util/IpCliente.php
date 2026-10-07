<?php

namespace Util;

/**
 * IP real do cliente atras do Cloudflare (demanda gestao-totem, F0, 2026-10-06).
 *
 * Regra: o cabecalho `CF-Connecting-IP` (variavel `HTTP_CF_CONNECTING_IP`) SO e
 * considerado quando o `REMOTE_ADDR` (o par TCP real) pertence as faixas
 * publicadas do Cloudflare. Qualquer outro caso usa `REMOTE_ADDR`. O header
 * `X-Forwarded-For` NUNCA e lido: ele e controlado pelo cliente e nao tem
 * origem confiavel neste projeto.
 *
 * Faixas oficiais, lista embutida (constante) para nao depender de rede em
 * tempo de requisicao. Fonte: https://www.cloudflare.com/ips-v4 e
 * https://www.cloudflare.com/ips-v6, consultadas por GET em 2026-10-06.
 * REVISAR periodicamente (o Cloudflare avisa mudancas na mesma pagina): faixa
 * nova nao listada aqui faz o IP aparecer como o do proprio Cloudflare (todos os
 * visitantes dividem o mesmo contador de rate limit), nunca como um IP forjado.
 */
final class IpCliente
{
    /** Data (AAAA-MM-DD) em que as faixas abaixo foram conferidas na fonte oficial. */
    public const FAIXAS_CLOUDFLARE_DATA = '2026-10-06';

    /** @var list<string> */
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

    /** @var list<string> */
    public const FAIXAS_CLOUDFLARE_V6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /** IP devolvido quando nem o REMOTE_ADDR e um IP valido (todos dividem o mesmo balde). */
    public const IP_DESCONHECIDO = '0.0.0.0';

    /**
     * @param array<string,mixed> $server tipicamente $_SERVER
     */
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

    /**
     * Valida e normaliza (IPv4-mapeado vira IPv4 puro, IPv6 em forma canonica
     * minuscula). null = nao e um IP valido.
     */
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
        // ::ffff:a.b.c.d (IPv4 mapeado em IPv6) -> a.b.c.d
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\x00", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }
        $texto = inet_ntop($bin);

        return $texto === false ? null : strtolower($texto);
    }

    /** Representacao binaria (4 ou 16 bytes) para colunas VARBINARY(16). null se invalido. */
    public static function paraBinario(string $ip): ?string
    {
        $ip = self::normalizar($ip);
        if ($ip === null) {
            return null;
        }
        $bin = @inet_pton($ip);

        return $bin === false ? null : $bin;
    }

    /** sha256(ip + sal) em hex: chave de contadores sem guardar o IP em claro. */
    public static function hash(string $ip, string $sal): string
    {
        return hash('sha256', $ip . $sal);
    }

    /**
     * "Balde" de rate limit do IP: IPv4 = o proprio IP (inteiro); IPv6 = o
     * prefixo /64 (um assinante recebe um /64 inteiro, entao rodar o endereco
     * dentro dele nao pode zerar o contador). Valor de entrada invalido = o
     * proprio texto recebido (nunca lanca). Usado so como CHAVE de contador; a
     * auditoria grava o IP completo.
     */
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
