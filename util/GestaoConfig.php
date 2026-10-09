<?php

namespace Util;

final class GestaoConfig
{
    public const IDLE_PADRAO_MIN = 30;

    public const ABSOLUTO_PADRAO_MIN = 720;

    public const SAL_MIN_CARACTERES = 16;

    public function __construct(
        public string $sal,
        public int $idleMin = self::IDLE_PADRAO_MIN,
        public int $absolutoMin = self::ABSOLUTO_PADRAO_MIN,
        public bool $permitirHttp = false
    ) {
    }

    public static function doAmbiente(array $env): self
    {
        $sal = $env['GESTAO_HASH_SALT'] ?? '';

        return new self(
            is_string($sal) ? $sal : '',
            self::inteiro($env['GESTAO_SESSION_IDLE_MIN'] ?? null, self::IDLE_PADRAO_MIN, 1, 1440),
            self::inteiro($env['GESTAO_SESSION_ABSOLUTE_MIN'] ?? null, self::ABSOLUTO_PADRAO_MIN, 1, 10080),
            ($env['GESTAO_PERMITIR_HTTP'] ?? null) === 'true'
        );
    }

    public function valida(): bool
    {
        return strlen($this->sal) >= self::SAL_MIN_CARACTERES;
    }

    private static function inteiro(mixed $valor, int $padrao, int $min, int $max): int
    {
        if (!is_string($valor) || preg_match('/\A[0-9]{1,6}\z/D', $valor) !== 1) {
            return $padrao;
        }
        $n = (int) $valor;

        return ($n < $min || $n > $max) ? $padrao : $n;
    }
}
