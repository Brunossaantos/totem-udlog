<?php

namespace App\Rn;

/**
 * Adaptador para a API do Talent.
 * Anexos confirmados: JSON com os arquivos em base64 (nao eh multipart).
 * TODO: confirmar URL do endpoint, header de autenticacao e formato exato
 * da resposta (nomes dos campos senha/protocolo).
 */
class TalentClient
{
    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = ''
    ) {}

    public function enviarAtendimento(array $payload): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . '/atendimentos');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->apiKey}",
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resposta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($codigoHttp !== 200 || $resposta === false) {
            throw new \RuntimeException("Talent respondeu HTTP {$codigoHttp}: {$resposta}");
        }

        $dados = json_decode($resposta, true);

        // formato esperado (AJUSTAR conforme retorno real):
        // { "senha": "042", "protocolo": "TAL-2026-000123" }
        return [
            'senha'     => $dados['senha'] ?? null,
            'protocolo' => $dados['protocolo'] ?? null,
        ];
    }
}
