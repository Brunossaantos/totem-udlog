<?php

namespace App\Controller;

use App\Dao\ClienteGestaoDao;
use App\Rn\ClienteGestaoRn;
use Util\GestaoHttp;

final class GestaoClienteController
{
    private ClienteGestaoRn $rn;

    public function __construct(private GestaoContexto $ctx)
    {
        $this->rn = new ClienteGestaoRn($ctx->pdo);
    }

    public function listar(): array
    {
        return $this->pagina(200, null);
    }

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

    public function formulario(): array
    {
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
