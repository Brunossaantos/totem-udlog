<?php

namespace App\Rn;

use App\Content\TermoLgpd;
use App\Dao\AceiteLgpdDao;

class LgpdRn
{
    private const MINUTOS_VALIDADE = 10;

    private const REGEX_TOKEN_HEX64 = '/^[0-9a-f]{64}$/';

    public function __construct(private AceiteLgpdDao $aceiteLgpdDao) {}

    public function emitir(int $idTotem): array
    {
        $tokenBruto = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenBruto);

        $expiraEm = (new \DateTimeImmutable('now'))
            ->modify('+' . self::MINUTOS_VALIDADE . ' minutes')
            ->format('Y-m-d H:i:s');

        $this->aceiteLgpdDao->criar($tokenHash, $idTotem, TermoLgpd::versao(), TermoLgpd::hash(), $expiraEm);

        return [
            'token_aceite' => $tokenBruto,
            'expira_em'    => $expiraEm,
        ];
    }

    public function formatoValido(mixed $tokenBruto): bool
    {
        return is_string($tokenBruto) && preg_match(self::REGEX_TOKEN_HEX64, $tokenBruto) === 1;
    }

    public function consumir(string $tokenBruto, int $idTotem): ?int
    {
        $tokenHash = hash('sha256', $tokenBruto);

        return $this->aceiteLgpdDao->consumirPorHash($tokenHash, $idTotem, TermoLgpd::versao(), TermoLgpd::hash());
    }
}
