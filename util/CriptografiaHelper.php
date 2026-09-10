<?php

namespace Util;

/**
 * Criptografia simetrica em repouso (AES-256-GCM) para dado pessoal sensivel
 * persistido em cache (nome/CPF de tb_vio_cache_cnh). Escolhido GCM em vez
 * de CBC+HMAC separado porque o proprio modo ja fornece autenticacao
 * integrada (tag), evitando implementar/errar um MAC a parte — uma unica
 * primitiva, uma unica chave (DOCUMENTO_DATA_KEY, 32 bytes/64 hex chars).
 *
 * Formato do texto cifrado retornado (string binaria, gravada em coluna
 * VARBINARY): [12 bytes IV][16 bytes TAG][ciphertext]. IV gerado por
 * chamada (random_bytes) — nunca reaproveitado.
 */
class CriptografiaHelper
{
    private const ALGORITMO = 'aes-256-gcm';
    private const TAMANHO_IV = 12;
    private const TAMANHO_TAG = 16;

    public static function criptografar(string $texto): string
    {
        $chave = self::obterChave();
        $iv = random_bytes(self::TAMANHO_IV);

        $tag = '';
        $cifrado = openssl_encrypt($texto, self::ALGORITMO, $chave, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAMANHO_TAG);

        if ($cifrado === false) {
            throw new \RuntimeException('Falha ao criptografar dado');
        }

        return $iv . $tag . $cifrado;
    }

    public static function descriptografar(string $cifradoComPrefixo): string
    {
        $chave = self::obterChave();

        if (strlen($cifradoComPrefixo) < self::TAMANHO_IV + self::TAMANHO_TAG) {
            throw new \RuntimeException('Dado cifrado invalido');
        }

        $iv = substr($cifradoComPrefixo, 0, self::TAMANHO_IV);
        $tag = substr($cifradoComPrefixo, self::TAMANHO_IV, self::TAMANHO_TAG);
        $cifrado = substr($cifradoComPrefixo, self::TAMANHO_IV + self::TAMANHO_TAG);

        $texto = openssl_decrypt($cifrado, self::ALGORITMO, $chave, OPENSSL_RAW_DATA, $iv, $tag);

        if ($texto === false) {
            throw new \RuntimeException('Falha ao descriptografar dado (chave incorreta ou dado corrompido)');
        }

        return $texto;
    }

    private static function obterChave(): string
    {
        $chaveHex = $_ENV['DOCUMENTO_DATA_KEY'] ?? '';

        if ($chaveHex === '' || strlen($chaveHex) !== 64 || !ctype_xdigit($chaveHex)) {
            throw new \RuntimeException('DOCUMENTO_DATA_KEY ausente ou invalida (esperado 64 caracteres hex / 32 bytes)');
        }

        return hex2bin($chaveHex);
    }
}
