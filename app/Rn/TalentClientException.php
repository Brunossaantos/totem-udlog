<?php

namespace App\Rn;

/**
 * Excecao categorizada do TalentClient — NUNCA carrega o corpo bruto da
 * requisicao/resposta HTTP, CPF/CNH, ou o valor de TALENT_API_KEY na
 * mensagem (item de seguranca critico desta demanda, ver
 * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md, "Achados
 * de seguranca").
 *
 * Categorias fechadas usadas no log sanitizado do finalizar, na escolha do
 * texto fixo do HTTP 202 e pela maquina de estados de 5 estados de
 * App\Dao\AtendimentoDao (ver
 * ehIndeterminado()): erro_validacao (400), erro_autenticacao (401),
 * nao_encontrado (404), conflito (409), erro_servidor (500), timeout,
 * erro_indeterminado (erro de curl que pode ter ocorrido apos a requisicao
 * ja ter sido total ou parcialmente enviada ao Talent — ver
 * App\Rn\TalentClient::checkin() para a lista exata de errno cobertos e o
 * raciocinio, adicionado apos revisao do security-especialista),
 * erro_conexao (excecao de rede/DNS que ocorre ANTES de qualquer byte ser
 * transmitido ao servidor — nunca chegou ao Talent, seguro reenviar),
 * erro_http (qualquer outro codigo HTTP nao mapeado), resposta_ilegivel (2xx
 * mas corpo nao interpretavel como JSON).
 */
class TalentClientException extends \RuntimeException
{
    public const CATEGORIAS_VALIDAS = [
        'erro_validacao', 'erro_autenticacao', 'nao_encontrado', 'conflito',
        'erro_servidor', 'timeout', 'erro_indeterminado', 'erro_conexao',
        'erro_http', 'resposta_ilegivel',
    ];

    private ?string $mensagemApi = null;

    public function __construct(private string $categoria)
    {
        if (!in_array($categoria, self::CATEGORIAS_VALIDAS, true)) {
            throw new \InvalidArgumentException("Categoria de erro do Talent invalida: {$categoria}");
        }

        // Mensagem da excecao e SEMPRE a categoria fixa — nunca corpo bruto.
        parent::__construct($categoria);
    }

    public function categoria(): string
    {
        return $this->categoria;
    }

    /**
     * Mensagem de negocio devolvida pela Talent em resposta HTTP de erro,
     * JA sanitizada (Util\MensagemApi: sem controles, <= 300 chars).
     * Fica fora de getMessage()/trace por desenho; NUNCA logar nem persistir.
     * Null quando a falha nao veio da Talent (rede/timeout) ou sem corpo.
     */
    public function mensagemApi(): ?string
    {
        return $this->mensagemApi;
    }

    public function comMensagemApi(?string $mensagemApi): static
    {
        $this->mensagemApi = $mensagemApi;
        return $this;
    }

    /**
     * Timeout de rede (requisicao pode ou nao ter chegado ao Talent) — ver
     * ehIndeterminado() para a categoria completa que NUNCA deve disparar
     * retry automatico via cron.
     *
     * @deprecated preferir ehIndeterminado(); mantido pois 'timeout'
     *             continua sendo uma categoria valida e distinta.
     */
    public function ehTimeout(): bool
    {
        return $this->categoria === 'timeout';
    }

    /**
     * Correcao de seguranca (revisao security-especialista, ver
     * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md,
     * secao "Idempotencia — 5 estados"): agrupa TODAS as categorias em que
     * nao se sabe se o Talent recebeu/processou a requisicao — 'timeout' e
     * 'erro_indeterminado' (demais erros de curl que podem ter ocorrido
     * apos a requisicao ja ter sido total ou parcialmente transmitida).
     * Essas categorias sao mapeadas para ENVIO_INDETERMINADO (nao
     * ERRO_REPROCESSAVEL) e exigem intervencao manual — apenas
     * 'erro_conexao' (falha ANTES de qualquer byte sair do totem) e
     * considerada segura para nova tentativa. Nao ha reenvio automatico
     * (a fila de reenvio foi removida): a nova tentativa so ocorre
     * manualmente, por nova chamada do `finalizar`.
     */
    public function ehIndeterminado(): bool
    {
        return $this->categoria === 'timeout' || $this->categoria === 'erro_indeterminado';
    }

    /**
     * HTTP 409 — por precaucao, NAO tratado automaticamente como duplicidade
     * nesta versao (TODO: reclassificar apos teste controlado em Producao
     * confirmar o significado real, ver handoff da demanda). Tratado como
     * ERRO_REPROCESSAVEL, nunca como sucesso/idempotencia automatica.
     */
    public function ehConflito(): bool
    {
        return $this->categoria === 'conflito';
    }
}
