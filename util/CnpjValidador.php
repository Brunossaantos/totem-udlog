<?php

namespace Util;

class CnpjValidador
{
    public const CNPJ_UDLOG_1 = '14706199000182';
    public const CNPJ_UDLOG_2 = '14706199000344';

    private const PESOS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    private const PESOS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    public static function normalizarEValidar(?string $bruto): ?string
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $bruto);

        if (strlen($digitos) !== 14) {
            return null;
        }

        if (preg_match('/^(\d)\1{13}$/', $digitos)) {
            return null;
        }

        $dv1Calculado = self::calcularDigitoVerificador(substr($digitos, 0, 12), self::PESOS_DV1);
        if ($dv1Calculado !== (int) $digitos[12]) {
            return null;
        }

        $dv2Calculado = self::calcularDigitoVerificador(substr($digitos, 0, 12) . $dv1Calculado, self::PESOS_DV2);
        if ($dv2Calculado !== (int) $digitos[13]) {
            return null;
        }

        return $digitos;
    }

    public static function ehUdlog(string $cnpj14): bool
    {
        return $cnpj14 === self::CNPJ_UDLOG_1 || $cnpj14 === self::CNPJ_UDLOG_2;
    }

    private static function calcularDigitoVerificador(string $base, array $pesos): int
    {
        $soma = 0;
        foreach (str_split($base) as $indice => $digito) {
            $soma += (int) $digito * $pesos[$indice];
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
