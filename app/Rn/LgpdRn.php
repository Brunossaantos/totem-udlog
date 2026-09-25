<?php

namespace App\Rn;

use App\Content\TermoLgpd;
use App\Dao\AceiteLgpdDao;

/**
 * Regra de negocio do fluxo de aceite do Aviso de Privacidade/LGPD —
 * demanda tela-inicial-lgpd-totem. Emite e consome o token opaco de uso
 * unico que prova, no backend, que o motorista viu e confirmou ciencia do
 * termo ANTES de qualquer atendimento ser criado (ver
 * App\Controller\AtendimentoController::iniciar()).
 */
class LgpdRn
{
    /**
     * Validade do token de aceite — 10 minutos (sugestao tecnica do
     * planejamento, adotada nesta implementacao): tempo generoso para o
     * motorista digitar a placa devagar, curto o suficiente para nao
     * deixar tokens validos por muito tempo se o totem for abandonado.
     */
    private const MINUTOS_VALIDADE = 10;

    /** Formato esperado do token bruto: 64 caracteres hexadecimais (256 bits). */
    private const REGEX_TOKEN_HEX64 = '/^[0-9a-f]{64}$/';

    public function __construct(private AceiteLgpdDao $aceiteLgpdDao) {}

    /**
     * Emite um novo token de aceite PENDENTE_USO para o totem autenticado.
     * NUNCA recebe/grava nenhum dado pessoal (placa/CPF/documento/nome) —
     * so o totem autenticado e o snapshot da versao/hash do termo ATUAL.
     *
     * Retorna o token BRUTO (64 hex) — a UNICA vez que ele existe em
     * texto claro fora da memoria volatil do totem; NUNCA persistido,
     * NUNCA logado.
     */
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

    /**
     * Valida o formato do token BRUTO recebido do front-end — string de
     * exatamente 64 caracteres hexadecimais. Qualquer outra coisa (ausente,
     * tipo errado, tamanho errado, caractere invalido) e rejeitada aqui,
     * ANTES de qualquer consulta ao banco.
     */
    public function formatoValido(mixed $tokenBruto): bool
    {
        return is_string($tokenBruto) && preg_match(self::REGEX_TOKEN_HEX64, $tokenBruto) === 1;
    }

    /**
     * Consome atomicamente (CAS, ver App\Dao\AceiteLgpdDao::consumirPorHash())
     * o token BRUTO recebido do front-end, exigindo que ele pertenca ao
     * totem autenticado NA REQUISICAO ATUAL e que o termo aceito ainda seja
     * o termo ATUAL (App\Content\TermoLgpd). Retorna o id_aceite consumido
     * em caso de sucesso, ou null em QUALQUER outra situacao (ausente,
     * invalido, expirado, ja usado, de outro totem, termo divergente) — o
     * chamador (App\Controller\AtendimentoController::iniciar()) NUNCA
     * diferencia o motivo na resposta ao totem, evitando enumeracao.
     */
    public function consumir(string $tokenBruto, int $idTotem): ?int
    {
        $tokenHash = hash('sha256', $tokenBruto);

        return $this->aceiteLgpdDao->consumirPorHash($tokenHash, $idTotem, TermoLgpd::versao(), TermoLgpd::hash());
    }
}
