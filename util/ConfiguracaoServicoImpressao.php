<?php

namespace Util;

/**
 * Leitura centralizada e fail-closed da configuracao de conexao do servico
 * local de impressao (mini PC Windows, fora do escopo Hostgator) a partir do
 * .env: IMPRESSAO_LOCAL_URL / IMPRESSAO_LOCAL_TOKEN /
 * IMPRESSAO_FRONTEND_TIMEOUT_MS.
 *
 * Extraida da demanda impressao-arquitetura-producao-ux (2026-09-15) para
 * eliminar a duplicacao implicita entre App\Controller\ImpressaoTesteController
 * (rota exclusiva do diagnostico) e o novo endpoint de producao em
 * App\Controller\ImpressaoAtendimentoController. Metodo estatico, mesmo
 * padrao de Util\Auth/Util\Resposta/Util\CriptografiaHelper (classes
 * utilitarias sem estado, sem necessidade de instanciar).
 *
 * Fail-closed: qualquer valor ausente/invalido lanca RuntimeException cuja
 * mensagem cita SOMENTE os nomes das variaveis de ambiente esperadas — NUNCA
 * o valor de IMPRESSAO_LOCAL_TOKEN (nem em mensagem de excecao, nem em log
 * feito pelo chamador a partir dela).
 */
class ConfiguracaoServicoImpressao
{
    /**
     * @return array{url: string, token: string, frontend_timeout_ms: int}
     * @throws \RuntimeException se IMPRESSAO_LOCAL_URL/IMPRESSAO_LOCAL_TOKEN/
     *         IMPRESSAO_FRONTEND_TIMEOUT_MS estiverem ausentes ou invalidos.
     */
    public static function obter(): array
    {
        $url = $_ENV['IMPRESSAO_LOCAL_URL'] ?? '';
        $token = $_ENV['IMPRESSAO_LOCAL_TOKEN'] ?? '';
        $frontendTimeoutMs = $_ENV['IMPRESSAO_FRONTEND_TIMEOUT_MS'] ?? '';

        if (
            $url === ''
            || $token === ''
            || !is_numeric($frontendTimeoutMs)
            || (int) $frontendTimeoutMs <= 0
        ) {
            throw new \RuntimeException(
                'IMPRESSAO_LOCAL_URL/IMPRESSAO_LOCAL_TOKEN/IMPRESSAO_FRONTEND_TIMEOUT_MS ausentes ou invalidos no .env'
            );
        }

        return [
            'url' => $url,
            'token' => $token,
            'frontend_timeout_ms' => (int) $frontendTimeoutMs,
        ];
    }
}
