<?php

namespace Util;

/**
 * Validacao/normalizacao de CNPJ (14 digitos), independente da chave de
 * acesso de NF-e (44 digitos, ver App\Rn\NotaFiscalRn::calcularDV, que eh
 * privado e especifico daquele outro proposito). Algoritmo publico padrao
 * de digito verificador de CNPJ (modulo 11, dois DVs) — implementado do
 * zero, nada inventado alem do algoritmo conhecido.
 *
 * Entrada tratada como nao confiavel (candidato extraido por OCR client-side,
 * sujeito a erro de reconhecimento) — sempre normalizar/validar antes de
 * qualquer uso.
 */
class CnpjValidador
{
    // CNPJs da propria UDLOG — excluidos de qualquer identificacao automatica
    // de CLIENTE (a nota fiscal nao pode "identificar" a propria UDLOG como
    // cliente do atendimento).
    public const CNPJ_UDLOG_1 = '14706199000182';
    public const CNPJ_UDLOG_2 = '14706199000344';

    private const PESOS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    private const PESOS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * Remove mascara, exige 14 digitos e valida os dois digitos verificadores.
     * Retorna o CNPJ normalizado (14 digitos, sem mascara) se valido, ou
     * null em qualquer caso invalido (nao lanca excecao — entrada de OCR
     * espera-se que falhe com frequencia).
     */
    public static function normalizarEValidar(?string $bruto): ?string
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $bruto);

        if (strlen($digitos) !== 14) {
            return null;
        }

        // sequencias de digito unico repetido (ex: 00000000000000,
        // 11111111111111) tecnicamente passam no modulo 11 em alguns casos
        // conhecidos — exclusao padrao de boas praticas de validacao de CNPJ.
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

    /**
     * Verdadeiro se o CNPJ normalizado (14 digitos) bate com alguma das
     * constantes UDLOG. Chamar sempre depois de normalizarEValidar().
     */
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
