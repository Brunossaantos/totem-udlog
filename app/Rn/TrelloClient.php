<?php

namespace App\Rn;

/**
 * Cliente HTTP puro (curl, sem dependencia nova) para a API REST v1 do
 * Trello, usado exclusivamente pela integracao do workflow de 5 etapas do
 * totem-udlog com o quadro "Infraestrutura - Matriz"
 * (TRELLO_BOARD_ID=654015c3d29cc34bc1b881f6) — ver
 * .claude/agents/trello-especialista.md e docs/trello-integracao.md.
 *
 * Autenticacao: key/token sao enviados como parametros (`key`/`token`) no
 * proprio corpo/query de CADA requisicao — o mecanismo de autenticacao
 * formalmente documentado pela API REST v1 do Trello. Testado ao vivo
 * nesta implementacao (2026-09-11) contra o board real: tanto o header
 * `Authorization: OAuth oauth_consumer_key=...` quanto os parametros
 * key/token autenticam corretamente as chamadas GET (validarConexao,
 * buscarListaPorNome), mas TODAS as chamadas de escrita (POST/PUT — criar
 * cartao, comentar, mover cartao) retornam HTTP 401 com o TRELLO_API_TOKEN
 * atual, o que indica token sem escopo de escrita (o Trello emite tokens
 * com escopo read/write/account separadamente) — ver
 * docs/trello-integracao.md, secao "Pendencia". Os parametros
 * key/token sao adicionados **somente internamente** em requisitar() —
 * NUNCA logados, impressos, incluidos em excecao, ou expostos em qualquer
 * saida visivel deste cliente ou de tools/trello-cli.php.
 *
 * NUNCA loga/imprime/inclui em excecao a key ou o token — toda falha e
 * traduzida para App\Rn\TrelloClientException com categoria fechada antes
 * de sair deste cliente (mesmo padrao de App\Rn\TalentClient).
 *
 * Falha do Trello NUNCA pode corromper/desfazer o trabalho do projeto —
 * cabe ao chamador (CLI, orquestrador) decidir o que fazer com a excecao;
 * este cliente apenas garante que o erro devolvido e sempre sanitizado.
 */
class TrelloClient
{
    private const TIMEOUT_SEGUNDOS = 8;
    private const BASE_URL = 'https://api.trello.com/1';

    public function __construct(
        private string $apiKey,
        private string $apiToken,
        private string $boardId
    ) {
        if ($this->apiKey === '' || $this->apiToken === '') {
            throw new \RuntimeException('TRELLO_API_KEY/TRELLO_API_TOKEN ausentes');
        }
        if ($this->boardId === '') {
            throw new \RuntimeException('TRELLO_BOARD_ID ausente');
        }
    }

    /**
     * Confirma que key/token autenticam contra o board configurado.
     * Retorna false (nunca lanca) para qualquer falha de autenticacao/rede
     * — quem quiser o motivo exato deve olhar a categoria de uma chamada
     * real (ex: buscarListaPorNome) separadamente.
     */
    public function validarConexao(): bool
    {
        try {
            $this->requisitar('GET', "/boards/{$this->boardId}", ['fields' => 'id,name']);
            return true;
        } catch (TrelloClientException $e) {
            return false;
        }
    }

    /**
     * Busca lista do board por nome EXATO. Exige correspondencia unica —
     * nunca escolhe "a mais parecida". Lanca TrelloClientException
     * ('lista_nao_encontrada' ou 'lista_ambigua') se o total de listas com
     * nome exatamente igual a $nomeExato nao for exatamente 1.
     *
     * @return array{id: string, name: string}
     */
    public function buscarListaPorNome(string $nomeExato): array
    {
        $listas = $this->requisitar('GET', "/boards/{$this->boardId}/lists", ['fields' => 'id,name']);

        if (!is_array($listas)) {
            throw new TrelloClientException('resposta_ilegivel');
        }

        $encontradas = array_values(array_filter(
            $listas,
            static fn($lista) => is_array($lista) && ($lista['name'] ?? null) === $nomeExato
        ));

        if (count($encontradas) === 0) {
            $nomesReais = array_values(array_map(
                static fn($lista) => $lista['name'] ?? null,
                is_array($listas) ? $listas : []
            ));
            throw new TrelloClientException('lista_nao_encontrada', ['nome_pedido' => $nomeExato, 'nomes_encontrados_no_board' => $nomesReais]);
        }

        if (count($encontradas) > 1) {
            throw new TrelloClientException('lista_ambigua', ['nome_pedido' => $nomeExato, 'total_encontrado' => count($encontradas)]);
        }

        return ['id' => (string) $encontradas[0]['id'], 'name' => (string) $encontradas[0]['name']];
    }

    /**
     * @param string $posicao Posicao do cartao na lista de destino: 'top',
     *   'bottom', ou um numero positivo (formato aceito pela API do
     *   Trello no parametro `pos`). Padrao 'top' — todo cartao novo criado
     *   por este metodo aparece no topo da lista, nunca no fim.
     * @return array dados do cartao criado (inclui 'id')
     */
    public function criarCartao(string $idLista, string $titulo, ?string $descricao = null, string $posicao = 'top'): array
    {
        $body = ['idList' => $idLista, 'name' => $titulo, 'pos' => $posicao];
        if ($descricao !== null) {
            $body['desc'] = $descricao;
        }

        return $this->requisitar('POST', '/cards', $body);
    }

    /**
     * Lista os cartoes (abertos, nao arquivados) de uma lista do board.
     * Usado para checagem de idempotencia por titulo antes de criar um
     * cartao novo, quando ainda nao existe `card_id` salvo em nenhum
     * handoff da demanda.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function listarCartoesDaLista(string $idLista): array
    {
        $cartoes = $this->requisitar('GET', "/lists/{$idLista}/cards", ['fields' => 'id,name']);

        if (!is_array($cartoes)) {
            throw new TrelloClientException('resposta_ilegivel');
        }

        return array_values(array_map(
            static fn($cartao) => ['id' => (string) ($cartao['id'] ?? ''), 'name' => (string) ($cartao['name'] ?? '')],
            $cartoes
        ));
    }

    public function consultarCartao(string $idCartao): array
    {
        return $this->requisitar('GET', "/cards/{$idCartao}", ['fields' => 'id,name,desc,idList,url,due,dueComplete']);
    }

    public function adicionarComentario(string $idCartao, string $texto): array
    {
        return $this->requisitar('POST', "/cards/{$idCartao}/actions/comments", ['text' => $texto]);
    }

    public function moverCartao(string $idCartao, string $idListaDestino): array
    {
        return $this->requisitar('PUT', "/cards/{$idCartao}", ['idList' => $idListaDestino]);
    }

    /**
     * Marca o cartao como concluido usando o mecanismo nativo de "data de
     * vencimento" do Trello: define `due` (data/hora ISO 8601) e
     * `dueComplete=true`, o que faz o Trello exibir um selo de data com
     * check verde diretamente no cartao, visivel no board sem precisar
     * abri-lo. Usado na etapa `/04-commit-e-push`, ao mover o cartao para
     * "Sprint - Feito", para registrar a data real de conclusao.
     *
     * @param string $idCartao
     * @param string|null $dataIso8601 Data/hora de conclusao em ISO 8601
     *   (ex: '2026-09-11T00:00:00.000Z' ou '2026-09-11'). Se null, usa a
     *   data/hora atual do servidor (formatada em UTC, sufixo 'Z').
     */
    public function marcarConcluida(string $idCartao, ?string $dataIso8601 = null): array
    {
        $data = $dataIso8601 ?? gmdate('Y-m-d\TH:i:s\Z');

        return $this->requisitar('PUT', "/cards/{$idCartao}", [
            'due' => $data,
            'dueComplete' => 'true',
        ]);
    }

    /**
     * Executa a chamada HTTP contra a API do Trello. $params (nunca a
     * key/token do chamador) vira query string em GET, e corpo
     * application/x-www-form-urlencoded em POST/PUT. A key/token sao
     * adicionados aqui dentro, exclusivamente para esta chamada — a URL/
     * corpo completo (com credencial) NUNCA e logado, impresso, ou
     * incluido em qualquer excecao propagada por este metodo; se
     * curl_error() precisar ser inspecionado, apenas a categoria mapeada e
     * propagada, nunca a string bruta do erro (que pode conter a URL).
     */
    private function requisitar(string $metodo, string $path, array $params = []): array
    {
        $paramsComCredencial = $params + ['key' => $this->apiKey, 'token' => $this->apiToken];

        $url = self::BASE_URL . $path;
        $headers = ['Accept: application/json'];

        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
        ];

        if ($metodo === 'GET') {
            $url .= '?' . http_build_query($paramsComCredencial);
        } elseif ($metodo === 'POST') {
            $opcoes[CURLOPT_POST] = true;
            $opcoes[CURLOPT_POSTFIELDS] = http_build_query($paramsComCredencial);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($metodo === 'PUT') {
            $opcoes[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opcoes[CURLOPT_POSTFIELDS] = http_build_query($paramsComCredencial);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            throw new \InvalidArgumentException("Metodo HTTP nao suportado: {$metodo}");
        }

        $ch = curl_init($url);
        $opcoes[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opcoes);

        $resposta = curl_exec($ch);
        $erroCurl = curl_errno($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($erroCurl !== 0) {
            // curl_error() nunca e propagado — pode, em algumas
            // implementacoes de libcurl, ecoar a URL da requisicao.
            $categoria = $erroCurl === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'erro_conexao';
            throw new TrelloClientException($categoria);
        }

        if ($resposta === false) {
            throw new TrelloClientException('erro_conexao');
        }

        $corpo = json_decode((string) $resposta, true);
        unset($resposta); // corpo bruto nunca sobrevive alem deste ponto

        if ($codigoHttp >= 200 && $codigoHttp < 300) {
            if (!is_array($corpo)) {
                throw new TrelloClientException('resposta_ilegivel');
            }
            return $corpo;
        }

        throw new TrelloClientException(match ($codigoHttp) {
            400 => 'erro_validacao',
            401 => 'erro_autenticacao',
            404 => 'nao_encontrado',
            429 => 'rate_limit',
            default => $codigoHttp >= 500 ? 'erro_servidor' : 'erro_http',
        });
    }
}
