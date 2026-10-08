<?php

namespace Util;

/**
 * Codigo do totem na URL do quiosque (demanda gestao-totem, F2):
 * `<NOME>-<EMPRESA>-<HASH16>`, ex.: GUICHE-04-MAUAI-K7QX2M3PDW4RJT3A.
 *
 *  - NOME: 2 a 24 caracteres, ASCII maiusculo, sequencias fora de [A-Z0-9] viram
 *    um unico "-", sem "-" nas pontas;
 *  - EMPRESA: nome da empresa sem nenhum caractere fora de [A-Z0-9], ate 16;
 *  - HASH16: 16 caracteres do alfabeto base32 maiusculo (A-Z e 2-7), 80 bits,
 *    gerados SO com CSPRNG (random_int). NUNCA rand/mt_rand.
 *
 * Funcoes puras (sem I/O). O codigo e SEGREDO de acesso a pagina do quiosque:
 * nunca vai para log nem para auditoria.
 */
final class TotemCodigo
{
    public const NOME_MIN = 2;

    public const NOME_MAX = 24;

    public const EMPRESA_MAX = 16;

    public const HASH_TAMANHO = 16;

    public const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Tamanho maximo do codigo: NOME24 + "-" + EMPRESA16 + "-" + HASH16. */
    public const CODIGO_MAX = 58;

    /** Codigo no padrao novo: grupo 1 = NOME-EMPRESA, grupo 2 = HASH. */
    public const REGEX_PADRAO = '/\A([A-Z0-9][A-Z0-9-]{0,22}[A-Z0-9]-[A-Z0-9]{1,16})-([A-Z2-7]{16})\z/D';

    /** Entrada acima disto e recusada sem processar (defesa contra payload gigante). */
    private const ENTRADA_MAX_BYTES = 200;

    /** Nome normalizado, ou null se ficar fora de 2..24 caracteres (nunca trunca). */
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

    /** Slug da empresa (so [A-Z0-9], maiusculo). Pode ser '' ou passar de EMPRESA_MAX: quem chama decide. */
    public static function empresa(string $nomeEmpresa): string
    {
        if (strlen($nomeEmpresa) > self::ENTRADA_MAX_BYTES) {
            return '';
        }
        $ascii = strtoupper(TextoEtiqueta::paraAscii($nomeEmpresa));

        return (string) preg_replace('/[^A-Z0-9]+/', '', $ascii);
    }

    /** 16 caracteres base32 (A-Z2-7) do CSPRNG. random_int lanca Exception se nao houver fonte segura. */
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

    /** Prefixo NOME-EMPRESA de um codigo no padrao novo, ou null se o codigo e legado/fora do padrao. */
    public static function prefixoDoPadrao(string $codigo): ?string
    {
        if (strlen($codigo) > self::CODIGO_MAX || preg_match(self::REGEX_PADRAO, $codigo, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
