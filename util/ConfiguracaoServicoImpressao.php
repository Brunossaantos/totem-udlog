<?php

namespace Util;

class ConfiguracaoServicoImpressao
{
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
