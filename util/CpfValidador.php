<?php

namespace Util;

class CpfValidador
{
    private const PESOS_DV1 = [10, 9, 8, 7, 6, 5, 4, 3, 2];
    private const PESOS_DV2 = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];

    public static function normalizarEValidar(?string $bruto): ?string
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $bruto);

        if (strlen($digitos) !== 11) {
            return null;
        }

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
