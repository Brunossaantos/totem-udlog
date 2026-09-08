<?php

namespace App\Rn;

/**
 * SEM USO EM PRODUCAO desde 2026-09-08 — App\Rn\NotaFiscalRn::identificarCliente
 * passou a consultar tb_cliente localmente via App\Dao\ClienteDao (decisao
 * aprovada pelo usuario, ver
 * docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md e
 * sql/migrations/003_tb_cliente_razao_normalizada.sql). Classe mantida
 * intacta so por rastreabilidade/rollback (nao apagada fisicamente nesta
 * rodada) — CLIENTES_API_TOKEN foi removido de .env.example por nao ter
 * mais nenhum outro uso no projeto.
 *
 * Adaptador para a API externa de clientes.
 * Contrato CONFIRMADO por teste real em 2026-09-04 (ver
 * docs/db_gestao_coletas.md, secao "clientes"): GET .../clientes?cnpj=<14
 * digitos> (filtro exato, retorna dados: [] se nao achar, nunca 404),
 * paginacao via ?pagina=/?por_pagina= (sem busca textual), campos
 * id/razao_social/cnpj/email/status/criado_em/atualizado_em, Authorization:
 * Bearer <token> obrigatorio, rate limit de 60 (janela nao confirmada).
 *
 * curl nativo, mesmo padrao de TalentClient/OrdemColetaClient — projeto nao
 * tem Guzzle instalado, nao instalar dependencia nova.
 */
class ClienteApiClient
{
    // URL oficial confirmada — hardcoded porque eh contrato ja confirmado
    // (nao um placeholder), mas o TOKEN nunca eh hardcoded, sempre vem de
    // $_ENV['CLIENTES_API_TOKEN'] (injetado via construtor pelo chamador).
    private const BASE_URL = 'https://udlog.online/iaUdlog/api/v1/clientes';

    private const TIMEOUT_SEGUNDOS = 8;

    // TTL do cache local da listagem completa (usada so pelo fuzzy match).
    // Valor de ajuste empirico — curto o suficiente para nao servir dado
    // muito desatualizado, longo o suficiente para nao repetir N chamadas
    // identicas em rajadas de notas do mesmo atendimento nem estourar o
    // rate limit de 60 observado (janela nao confirmada).
    private const CACHE_TTL_SEGUNDOS = 60;

    private const NOME_ARQUIVO_CACHE = 'clientes_api_listagem.json';

    public function __construct(
        private string $token,
        private string $cacheDir
    ) {}

    /**
     * Busca cliente por CNPJ exato (14 digitos, ja normalizado pelo
     * chamador). Retorna o primeiro item de "dados" se encontrado, null se
     * a API respondeu com sucesso mas lista vazia. Falha tecnica (timeout,
     * 5xx, token invalido, erro de rede) lanca RuntimeException — o
     * chamador decide o que fazer (endpoint responde ERRO), nunca deixa
     * excecao crua propagar ate o cliente HTTP do totem.
     */
    public function buscarPorCnpj(string $cnpj14): ?array
    {
        $resposta = $this->requisitar(['cnpj' => $cnpj14, 'por_pagina' => 5]);
        $dados = $resposta['dados'] ?? [];

        return $dados[0] ?? null;
    }

    /**
     * Busca TODAS as paginas da listagem (por_pagina alto, iterando
     * meta.total_paginas) para uso no fuzzy match de razao social. Cacheada
     * em arquivo dentro de storage/ (fora do webroot) por CACHE_TTL_SEGUNDOS.
     * Lanca RuntimeException em falha tecnica (cache-miss + API indisponivel).
     *
     * @return array<int, array{id:mixed, razao_social:string, cnpj:string}>
     */
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

        // mensagem de excecao nunca inclui query/CNPJ/resposta bruta — so
        // codigo tecnico, seguro para log de erro.
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
            // falha ao criar diretorio de cache nao deve quebrar o fluxo
            // principal (fuzzy match ainda funciona sem cache, so mais lento)
            return;
        }

        @file_put_contents($this->caminhoCache(), json_encode($listagem, JSON_UNESCAPED_UNICODE));
    }
}
