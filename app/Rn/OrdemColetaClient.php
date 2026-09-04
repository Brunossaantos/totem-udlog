<?php

namespace App\Rn;

/**
 * Adaptador para a API de ordens de coleta.
 * TODO: ajustar URL, autenticacao e mapeamento de campos assim que a
 * documentacao real da API estiver disponivel. O formato abaixo eh um
 * chute razoavel pra nao travar o resto do sistema.
 */
class OrdemColetaClient
{
    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = ''
    ) {}

    public function buscarPorPlaca(string $placa): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . '/ordens-coleta?placa=' . urlencode($placa));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$this->apiKey}"],
            CURLOPT_TIMEOUT => 8,
        ]);
        $resposta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($codigoHttp !== 200 || $resposta === false) {
            throw new \RuntimeException("Falha ao consultar API de ordens de coleta (HTTP {$codigoHttp})");
        }

        $dados = json_decode($resposta, true);

        // formato esperado (AJUSTAR conforme retorno real):
        // { "ordens": [{ "numero": "OC-88213", "status": "aberto", "data": "2026-09-03",
        //                "cliente_nome": "...", "cliente_cnpj": "...", "veiculo": "..." }] }
        return $dados['ordens'] ?? [];
    }
}
