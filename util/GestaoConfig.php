<?php

namespace Util;

/**
 * Configuracao da Gestao Totem lida do .env (demanda gestao-totem, F1).
 *
 *  - GESTAO_HASH_SALT (obrigatoria, >= 16 caracteres): sal do hash de IP dos
 *    contadores de login (tb_gestao_login_tentativa), da derivacao da chave do
 *    token CSRF do formulario de login e do nome dos arquivos de rate limit da
 *    pagina do totem (esta usa uma constante de reserva se faltar). A AUDITORIA
 *    NAO usa o sal: grava o IP em claro (binario) para forense, com retencao
 *    de 90 dias (F3). Ausente/curta = a gestao responde 503 (fail-closed).
 *    Segredo: nunca logar, nunca commitar.
 *  - GESTAO_SESSION_IDLE_MIN (padrao 30): minutos de inatividade.
 *  - GESTAO_SESSION_ABSOLUTE_MIN (padrao 720 = 12 h): teto absoluto da sessao.
 *  - GESTAO_PERMITIR_HTTP: SO o literal "true" libera HTTP (dev local). Nunca padrao.
 */
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

    /** @param array<string,mixed> $env tipicamente $_ENV */
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
