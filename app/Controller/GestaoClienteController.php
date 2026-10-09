<?php

namespace App\Controller;

use App\Dao\ClienteGestaoDao;
use App\Rn\ClienteGestaoRn;
use Util\GestaoHttp;

/**
 * Clientes (tb_cliente do totem) da Gestao Totem (SO admin; o guard esta em
 * GestaoContexto): listar, criar, editar o nome, ativar/inativar e excluir (F6). Os
 * metodos devolvem o resultado de pagina que `gestaoRenderizar()` imprime, ou
 * redirecionam (PRG com ?msg=codigo). A confirmacao em dois passos de inativar/excluir
 * e uma pagina GET (`clientes.php?confirmar=<acao>&id=<id>`) com o aviso e um formulario
 * `confirmar=1`; o servidor so executa no segundo passo.
 */
final class GestaoClienteController
{
    private ClienteGestaoRn $rn;

    public function __construct(private GestaoContexto $ctx)
    {
        $this->rn = new ClienteGestaoRn($ctx->pdo);
    }

    /**
     * clientes.php (GET): lista com filtros por whitelist (situacao, q), 25 por pagina,
     * e, se vier `confirmar` + `id`, o aviso do segundo passo.
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function listar(): array
    {
        return $this->pagina(200, null);
    }

    /**
     * cliente-acao.php (POST): acao=inativar|ativar|excluir + id_cliente (+ confirmar=1).
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function acao(): array
    {
        $acao = GestaoContexto::post('acao');
        $alvo = GestaoContexto::inteiroPositivo(GestaoContexto::post('id_cliente'));
        if (!in_array($acao, ['inativar', 'ativar', 'excluir'], true)) {
            return $this->pagina(400, ['tipo' => 'erro', 'texto' => 'Não foi possível entender o pedido. Nada foi alterado. Recarregue a página e tente de novo.']);
        }
        if ($alvo === null) {
            $this->voltar('cliente_nao_encontrado');
        }
        $admin = $this->ctx->idUsuario();
        $ip = $this->ctx->ip();
        $confirmou = GestaoContexto::post('confirmar') === '1';

        if ($acao === 'excluir') {
            $r = $this->rn->excluir($admin, $alvo, $confirmou, $ip);
        } else {
            $r = $this->rn->definirAtivo($admin, $alvo, $acao === 'ativar', $confirmou, $ip);
        }
        if (in_array($r['codigo'] ?? '', ['confirmacao_necessaria', 'confirmacao_ambiguidade'], true)) {
            GestaoHttp::redirecionar(GestaoContexto::CAMINHO_CLIENTES . '?confirmar=' . rawurlencode($acao) . '&id=' . $alvo . '&msg=cliente_confirmacao_necessaria');
        }
        if (!$r['ok']) {
            $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
        }
        if ($r['sem_mudanca'] ?? false) {
            $this->voltar('cliente_sem_mudanca');
        }
        $this->voltar($acao === 'excluir' ? 'cliente_excluido' : ($acao === 'ativar' ? 'cliente_ativado' : 'cliente_inativado'));

        return [];
    }

    /**
     * cliente-form.php: GET (?id= para editar) mostra o formulario. POST salva (cria se
     * nao ha id_cliente, edita o nome se ha).
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function formulario(): array
    {
        // Em POST vale SO o id do corpo (`?id=` na URL de um POST de criacao e ignorado e a
        // criacao segue); `query('id')` so vale em GET.
        $ehGet = $this->ctx->auth->metodoSeguro();
        $idTexto = $ehGet ? GestaoContexto::query('id') : GestaoContexto::post('id_cliente');
        $idAlvo = null;
        $atual = null;
        if ($idTexto !== '') {
            $idAlvo = GestaoContexto::inteiroPositivo($idTexto);
            $atual = $idAlvo !== null ? $this->rn->obter($idAlvo) : null;
            if ($atual === null) {
                $this->voltar('cliente_nao_encontrado');
            }
        }

        if ($ehGet) {
            return $this->formularioView(200, $idAlvo, [
                'nome' => (string) ($atual['nome'] ?? ''),
                'cnpj' => (string) ($atual['cnpj'] ?? ''),
                'ativo' => '1',
            ], [], $atual);
        }

        $nome = GestaoContexto::post('nome');
        $admin = $this->ctx->idUsuario();
        $ip = $this->ctx->ip();
        $confirmou = GestaoContexto::post('confirmar') === '1';

        if ($idAlvo === null) {
            $cnpj = GestaoContexto::post('cnpj');
            $ativo = GestaoContexto::post('ativo');
            $r = $this->rn->criar($admin, $nome, $cnpj, $ativo, $ip, $confirmou);
            if ($r['ok']) {
                $this->voltar('cliente_criado');
            }
            $valores = ['nome' => $nome, 'cnpj' => $cnpj, 'ativo' => $ativo === '0' ? '0' : '1'];
            if (($r['codigo'] ?? '') === 'validacao') {
                return $this->formularioView(422, null, $valores, $r['erros'] ?? [], null);
            }
            if (($r['codigo'] ?? '') === 'confirmacao_ambiguidade') {
                return $this->formularioView(200, null, $valores, [], null, $r['ambiguidade']);
            }
            $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
        }

        $r = $this->rn->editar($admin, $idAlvo, $nome, $ip, $confirmou);
        if ($r['ok']) {
            $this->voltar(($r['sem_mudanca'] ?? false) ? 'cliente_sem_mudanca' : 'cliente_editado');
        }
        $valores = ['nome' => $nome, 'cnpj' => (string) ($atual['cnpj'] ?? ''), 'ativo' => '1'];
        if (($r['codigo'] ?? '') === 'validacao') {
            return $this->formularioView(422, $idAlvo, $valores, $r['erros'] ?? [], $atual);
        }
        if (($r['codigo'] ?? '') === 'confirmacao_ambiguidade') {
            return $this->formularioView(200, $idAlvo, $valores, [], $atual, $r['ambiguidade']);
        }
        $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));

        return [];
    }

    /**
     * @param array{tipo:string,texto:string}|null $flashLocal
     */
    private function pagina(int $status, ?array $flashLocal): array
    {
        $f = ClienteGestaoRn::filtrosValidos(GestaoContexto::query('situacao'), GestaoContexto::query('q'));
        $paginaPedida = GestaoContexto::inteiroPositivo(GestaoContexto::query('pagina')) ?? 1;
        $r = $this->rn->listarPagina($f, $paginaPedida);
        $filtros = ['situacao' => $f['situacao'], 'q' => $f['q']];
        $pagina = $r['pagina'];

        return [
            'view' => 'clientes',
            'status' => $status,
            'flash' => $flashLocal,
            'dados' => [
                'clientes' => $r['linhas'],
                'total' => $r['total'],
                'pagina' => $pagina,
                'paginas' => $r['paginas'],
                'por_pagina' => ClienteGestaoDao::POR_PAGINA,
                'filtros' => $filtros,
                'busca_curta' => $f['busca_curta'],
                'tem_filtros' => $f['situacao'] !== 'todos' || $f['q'] !== '',
                'url_anterior' => $pagina > 1 ? $this->urlLista($filtros, $pagina - 1) : null,
                'url_proxima' => $pagina < $r['paginas'] ? $this->urlLista($filtros, $pagina + 1) : null,
                'url_lista' => $this->urlLista($filtros, 1),
                'contador' => $this->contador($r['total'], $pagina, $r['paginas']),
                'confirmacao' => $this->confirmacao(),
            ],
        ];
    }

    /**
     * Aviso do segundo passo (so se a acao ainda faz sentido para o estado atual):
     * excluir sempre; inativar so de cliente ativo com atendimento em andamento.
     *
     * @return array{acao:string,id_cliente:int,nome:string,texto:string,rotulo:string,andamento:int}|null
     */
    private function confirmacao(): ?array
    {
        $acao = GestaoContexto::query('confirmar');
        $id = GestaoContexto::inteiroPositivo(GestaoContexto::query('id'));
        if ($id === null || !in_array($acao, ['inativar', 'excluir', 'ativar'], true)) {
            return null;
        }
        $c = $this->rn->obter($id);
        if ($c === null) {
            return null;
        }
        if ($acao === 'ativar') {
            if ((int) $c['ativo'] === 1) {
                return null;
            }
            $amb = $this->rn->ambiguidadeAoAtivar($id);
            if ($amb['total'] === 0) {
                return null;
            }

            return [
                'acao' => 'ativar', 'id_cliente' => $id, 'nome' => (string) $c['nome'], 'andamento' => 0,
                'texto' => self::textoAmbiguidade($amb['total']), 'rotulo' => 'Ativar mesmo assim', 'nomes' => $amb['nomes'],
            ];
        }
        $andamento = $this->rn->andamentoDoCliente((string) $c['cnpj']);
        $aviso = $andamento > 0 ? 'Há ' . $andamento . ' atendimento(s) em andamento que usam este cliente. ' : '';
        if ($acao === 'excluir') {
            return [
                'acao' => 'excluir', 'id_cliente' => $id, 'nome' => (string) $c['nome'], 'andamento' => $andamento,
                'texto' => $aviso . 'Esta ação exclui o cliente de forma definitiva. O OCR e o autocomplete deixam de reconhecê-lo imediatamente. Para voltar a cadastrá-lo será preciso criar de novo.',
                'rotulo' => 'Excluir definitivamente',
            ];
        }
        if ((int) $c['ativo'] !== 1 || $andamento === 0) {
            return null;
        }

        return [
            'acao' => 'inativar', 'id_cliente' => $id, 'nome' => (string) $c['nome'], 'andamento' => $andamento,
            'texto' => $aviso . 'Ao inativar, o OCR e o autocomplete deixam de reconhecer o cliente imediatamente. Você pode ativá-lo de novo depois.',
            'rotulo' => 'Inativar mesmo assim',
        ];
    }

    private function contador(int $total, int $pagina, int $paginas): string
    {
        if ($total === 0) {
            return 'Nenhum cliente encontrado.';
        }
        $de = ($pagina - 1) * ClienteGestaoDao::POR_PAGINA + 1;
        $ate = min($total, $pagina * ClienteGestaoDao::POR_PAGINA);

        return ($total === 1 ? '1 cliente' : $total . ' clientes') . ($paginas > 1 ? ' (mostrando ' . $de . ' a ' . $ate . ')' : '');
    }

    /** @param array{situacao:string,q:string} $filtros */
    private function urlLista(array $filtros, int $pagina): string
    {
        $q = [];
        if ($filtros['situacao'] !== 'todos') {
            $q['situacao'] = $filtros['situacao'];
        }
        if ($filtros['q'] !== '') {
            $q['q'] = $filtros['q'];
        }
        if ($pagina > 1) {
            $q['pagina'] = (string) $pagina;
        }

        return GestaoContexto::CAMINHO_CLIENTES . ($q === [] ? '' : '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param array{nome:string,cnpj:string,ativo:string} $valores
     * @param array<string,string> $erros
     * @param array<string,mixed>|null $atual cliente em edicao
     * @param array{total:int,nomes:list<string>}|null $ambiguidade passo 1 da confirmacao de ambiguidade do OCR
     */
    private function formularioView(int $status, ?int $idAlvo, array $valores, array $erros, ?array $atual, ?array $ambiguidade = null): array
    {
        return [
            'view' => 'cliente-form',
            'status' => $status,
            'titulo' => $idAlvo !== null ? 'Editar cliente' : 'Novo cliente',
            'flash' => $ambiguidade !== null ? ['tipo' => 'info', 'texto' => self::textoAmbiguidade($ambiguidade['total'])] : null,
            'dados' => [
                'ambiguidade' => $ambiguidade,
                'texto_ambiguidade' => $ambiguidade !== null ? self::textoAmbiguidade($ambiguidade['total']) : '',
                'id_alvo' => $idAlvo,
                'editando' => $idAlvo !== null,
                'valores' => $valores,
                'erros' => $erros,
                'situacao_atual' => $atual !== null ? ((int) $atual['ativo'] === 1 ? 'Ativo' : 'Inativo') : '',
            ],
        ];
    }

    /** Mensagem FIXA do aviso de ambiguidade do OCR (so o numero varia; nunca lista nomes). */
    private static function textoAmbiguidade(int $total): string
    {
        return 'Este nome é parecido com o de outros clientes e pode fazer o OCR das notas não identificar automaticamente ' . $total . ' cliente(s) (cairá no preenchimento manual). Confirme para continuar.';
    }

    private function voltar(string $codigo): void
    {
        $codigo = isset(GestaoContexto::MENSAGENS[$codigo]) ? $codigo : 'erro_interno';
        GestaoHttp::redirecionar(GestaoContexto::CAMINHO_CLIENTES . '?msg=' . rawurlencode($codigo));
    }
}
