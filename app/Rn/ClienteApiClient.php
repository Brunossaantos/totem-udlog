<?php

namespace App\Rn;

class ClienteApiClient
{
    private const BASE_URL = 'https://udlog.online/iaUdlog/api/v1/clientes';

    private const TIMEOUT_SEGUNDOS = 8;

    private const CACHE_TTL_SEGUNDOS = 60;

    private const NOME_ARQUIVO_CACHE = 'clientes_api_listagem.json';

    public function __construct(
        private string $token,
        private string $cacheDir
    ) {}

    public function buscarPorCnpj(string $cnpj14): ?array
    {
        $resposta = $this->requisitar(['cnpj' => $cnpj14, 'por_pagina' => 5]);
        $dados = $resposta['dados'] ?? [];

        return $dados[0] ?? null;
    }

    public function listarTodos(): array
    {
        $cache = $this->lerCache();
        if ($cache !== null) {
            return $cache;
        }

        $todos = [];
        $pagina = 1;
        $totalPaginas = 1;

        do {
            $resposta = $this->requisitar(['pagina' => $pagina, 'por_pagina' => 100]);
            $todos = array_merge($todos, $resposta['dados'] ?? []);
            $totalPaginas = max(1, (int) ($resposta['meta']['total_paginas'] ?? 1));
            $pagina++;
        } while ($pagina <= $totalPaginas);

        $this->gravarCache($todos);

        return $todos;
    }

    private function requisitar(array $query): array
    {
        $url = self::BASE_URL . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->token}",
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
        ]);
        $resposta = curl_exec($ch);
        $erroCurl = curl_errno($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($erroCurl !== 0) {
            throw new \RuntimeException("Falha de rede ao consultar API de clientes (curl errno {$erroCurl})");
        }

        if ($codigoHttp !== 200 || $resposta === false) {
            throw new \RuntimeException("API de clientes respondeu HTTP {$codigoHttp}");
        }

        $dados = json_decode($resposta, true);
        if (!is_array($dados)) {
            throw new \RuntimeException('API de clientes retornou corpo invalido');
        }

        return $dados;
    }

    private function caminhoCache(): string
    {
        return rtrim($this->cacheDir, '/') . '/' . self::NOME_ARQUIVO_CACHE;
    }

    private function lerCache(): ?array
    {
        $caminho = $this->caminhoCache();
        if (!is_file($caminho)) {
            return null;
        }

        if (time() - filemtime($caminho) > self::CACHE_TTL_SEGUNDOS) {
            return null;
        }

        $conteudo = @file_get_contents($caminho);
        if ($conteudo === false) {
            return null;
        }

        $dados = json_decode($conteudo, true);

        return is_array($dados) ? $dados : null;
    }

    private function gravarCache(array $listagem): void
    {
        $dir = $this->cacheDir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (!is_dir($dir)) {
            return;
        }

        @file_put_contents($this->caminhoCache(), json_encode($listagem, JSON_UNESCAPED_UNICODE));
    }
}
