<?php

namespace App\Rn;

/**
 * Excecao categorizada do TrelloClient — a mensagem exposta ao chamador e
 * SEMPRE uma categoria fechada, nunca o corpo bruto da resposta, a URL da
 * requisicao, ou TRELLO_API_KEY/TRELLO_API_TOKEN. Segue o mesmo padrao de
 * App\Rn\TalentClientException.
 *
 * Categorias:
 * - erro_autenticacao: HTTP 401 (key/token invalidos ou revogados)
 * - nao_encontrado: HTTP 404 (board/lista/cartao inexistente ou sem acesso)
 * - erro_validacao: HTTP 400 (parametro invalido)
 * - rate_limit: HTTP 429
 * - erro_servidor: HTTP >= 500
 * - erro_http: qualquer outro codigo HTTP nao mapeado
 * - timeout: CURLE_OPERATION_TIMEDOUT
 * - erro_conexao: falha de rede/DNS antes de qualquer resposta do servidor
 * - resposta_ilegivel: 2xx mas corpo nao interpretavel como JSON
 * - lista_nao_encontrada / lista_ambigua: buscarListaPorNome nao encontrou
 *   exatamente uma lista com o nome exato pedido
 */
class TrelloClientException extends \RuntimeException
{
    private const CATEGORIAS_VALIDAS = [
        'erro_autenticacao', 'nao_encontrado', 'erro_validacao', 'rate_limit',
        'erro_servidor', 'erro_http', 'timeout', 'erro_conexao',
        'resposta_ilegivel', 'lista_nao_encontrada', 'lista_ambigua',
    ];

    public function __construct(private string $categoria, private array $contexto = [])
    {
        if (!in_array($categoria, self::CATEGORIAS_VALIDAS, true)) {
            throw new \InvalidArgumentException("Categoria de erro do Trello invalida: {$categoria}");
        }

        // Mensagem da excecao e SEMPRE a categoria fixa (+ contexto seguro,
        // ex: nomes de listas encontradas) — nunca a URL/credencial.
        $sufixo = $contexto !== [] ? ' (' . json_encode($contexto, JSON_UNESCAPED_UNICODE) . ')' : '';
        parent::__construct($categoria . $sufixo);
    }

    public function categoria(): string
    {
        return $this->categoria;
    }

    /**
     * Contexto adicional seguro (nunca contem credencial) — ex: para
     * lista_ambigua, a lista de nomes reais encontrados no board.
     */
    public function contexto(): array
    {
        return $this->contexto;
    }
}
