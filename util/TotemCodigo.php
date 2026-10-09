<?php

namespace Util;

final class TotemCodigo
{
    public const NOME_MIN = 2;

    public const NOME_MAX = 24;

    public const EMPRESA_MAX = 16;

    public const HASH_TAMANHO = 16;

    public const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const CODIGO_MAX = 58;

    public const REGEX_PADRAO = '/\A([A-Z0-9][A-Z0-9-]{0,22}[A-Z0-9]-[A-Z0-9]{1,16})-([A-Z2-7]{16})\z/D';

    private const ENTRADA_MAX_BYTES = 200;

    public static function nome(string $bruto): ?string
    {
        if (strlen($bruto) > self::ENTRADA_MAX_BYTES) {
            return null;
        }
        $ascii = strtoupper(TextoEtiqueta::paraAscii($bruto));
        $tracos = preg_replace('/[^A-Z0-9]+/', '-', $ascii);
        if (!is_string($tracos)) {
            return null;
        }
        $nome = trim($tracos, '-');
        $tam = strlen($nome);
        if ($tam < self::NOME_MIN || $tam > self::NOME_MAX) {
            return null;
        }

        return $nome;
    }

    public static function empresa(string $nomeEmpresa): string
    {
        if (strlen($nomeEmpresa) > self::ENTRADA_MAX_BYTES) {
            return '';
        }
        $ascii = strtoupper(TextoEtiqueta::paraAscii($nomeEmpresa));

        return (string) preg_replace('/[^A-Z0-9]+/', '', $ascii);
    }

    public static function hash(): string
    {
        $saida = '';
        for ($i = 0; $i < self::HASH_TAMANHO; $i++) {
            $saida .= self::ALFABETO[random_int(0, 31)];
        }

        return $saida;
    }

    public static function montar(string $nome, string $empresa, string $hash): string
    {
        return $nome . '-' . $empresa . '-' . $hash;
    }

    public static function prefixoDoPadrao(string $codigo): ?string
    {
        if (strlen($codigo) > self::CODIGO_MAX || preg_match(self::REGEX_PADRAO, $codigo, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
