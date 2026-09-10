<?php

namespace Util;

/**
 * Validacao/normalizacao de CPF (11 digitos), mesmo padrao de
 * Util\CnpjValidador (digito verificador modulo 11). Entrada tratada como
 * nao confiavel (dado retornado pela VIO Decode ou digitado manualmente pelo
 * atendente) — sempre normalizar/validar antes de qualquer uso.
 */
class CpfValidador
{
    private const PESOS_DV1 = [10, 9, 8, 7, 6, 5, 4, 3, 2];
    private const PESOS_DV2 = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * Remove mascara, exige 11 digitos e valida os dois digitos
     * verificadores. Retorna o CPF normalizado (11 digitos, sem mascara) se
     * valido, ou null em qualquer caso invalido (nao lanca excecao).
     */
    public static function normalizarEValidar(?string $bruto): ?string
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $bruto);

        if (strlen($digitos) !== 11) {
            return null;
        }

        // sequencias de digito unico repetido (ex: 00000000000, 11111111111)
        // passam no modulo 11 mas nao sao CPFs validos — exclusao padrao.
        if (preg_match('/^(\d)\1{10}$/', $digitos)) {
            return null;
        }

        $dv1Calculado = self::calcularDigitoVerificador(substr($digitos, 0, 9), self::PESOS_DV1);
        if ($dv1Calculado !== (int) $digitos[9]) {
            return null;
        }

        $dv2Calculado = self::calcularDigitoVerificador(substr($digitos, 0, 9) . $dv1Calculado, self::PESOS_DV2);
        if ($dv2Calculado !== (int) $digitos[10]) {
            return null;
        }

        return $digitos;
    }

    private static function calcularDigitoVerificador(string $base, array $pesos): int
    {
        $soma = 0;
        foreach (str_split($base) as $indice => $digito) {
            $soma += (int) $digito * $pesos[$indice];
        }
        $resto = ($soma * 10) % 11;

        return $resto === 10 ? 0 : $resto;
    }
}
