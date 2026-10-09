<?php

namespace App\Controller;

use App\Rn\EmpresaGestaoRn;
use Util\GestaoHttp;

/**
 * Empresas (tb_empresa do totem) da Gestao Totem (SO admin; o guard esta em
 * GestaoContexto): listar, criar, editar o nome, ativar/inativar e excluir (F6). Mesmo
 * desenho do GestaoClienteController: PRG com ?msg=codigo e confirmacao em dois passos
 * por pagina GET (`empresas.php?confirmar=<acao>&id=<id>`) com um formulario
 * `confirmar=1`; o servidor so executa no segundo passo. Nunca mexe em totens.
 */
final class GestaoEmpresaController
{
    private EmpresaGestaoRn $rn;

    public function __construct(private GestaoContexto $ctx)
    {
        $this->rn = new EmpresaGestaoRn($ctx->pdo);
    }

    /**
     * empresas.php (GET): todas as empresas (poucas) com a contagem de totens e, se vier
     * `confirmar` + `id`, o aviso do segundo passo.
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function listar(): array
    {
        return $this->pagina(200, null);
    }

    /**
     * empresa-acao.php (POST): acao=inativar|ativar|excluir + id_empresa (+ confirmar=1).
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function acao(): array
    {
        $acao = GestaoContexto::post('acao');
        $alvo = GestaoContexto::inteiroPositivo(GestaoContexto::post('id_empresa'));
        if (!in_array($acao, ['inativar', 'ativar', 'excluir'], true)) {
            return $this->pagina(400, ['tipo' => 'erro', 'texto' => 'Não foi possível entender o pedido. Nada foi alterado. Recarregue a página e tente de novo.']);
        }
        if ($alvo === null) {
            $this->voltar('empresa_nao_encontrada');
        }
        $admin = $this->ctx->idUsuario();
        $ip = $this->ctx->ip();
        $confirmou = GestaoContexto::post('confirmar') === '1';

        if ($acao === 'excluir') {
            $r = $this->rn->excluir($admin, $alvo, $confirmou, $ip);
        } else {
            $r = $this->rn->definirAtivo($admin, $alvo, $acao === 'ativar', $confirmou, $ip);
        }
        if (($r['codigo'] ?? '') === 'confirmacao_necessaria') {
            GestaoHttp::redirecionar(GestaoContexto::CAMINHO_EMPRESAS . '?confirmar=' . rawurlencode($acao) . '&id=' . $alvo . '&msg=empresa_confirmacao_necessaria');
        }
        if (!$r['ok']) {
            $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
        }
        if ($r['sem_mudanca'] ?? false) {
            $this->voltar('empresa_sem_mudanca');
        }
        $this->voltar($acao === 'excluir' ? 'empresa_excluida' : ($acao === 'ativar' ? 'empresa_ativada' : 'empresa_inativada'));

        return [];
    }

    /**
     * empresa-form.php: GET (?id= para editar) mostra o formulario. POST salva (cria se
     * nao ha id_empresa, edita o nome se ha).
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function formulario(): array
    {
        // Em POST vale SO o id do corpo (`?id=` na URL de um POST de criacao e ignorado e a
        // criacao segue); `query('id')` so vale em GET.
        $ehGet = $this->ctx->auth->metodoSeguro();
        $idTexto = $ehGet ? GestaoContexto::query('id') : GestaoContexto::post('id_empresa');
        $idAlvo = null;
        $atual = null;
        if ($idTexto !== '') {
            $idAlvo = GestaoContexto::inteiroPositivo($idTexto);
            $atual = $idAlvo !== null ? $this->rn->obter($idAlvo) : null;
            if ($atual === null) {
                $this->voltar('empresa_nao_encontrada');
            }
        }

        if ($ehGet) {
            return $this->formularioView(200, $idAlvo, ['nome' => (string) ($atual['nome'] ?? ''), 'cnpj' => (string) ($atual['cnpj'] ?? '')], [], $atual);
        }

        $nome = GestaoContexto::post('nome');
        $admin = $this->ctx->idUsuario();
        $ip = $this->ctx->ip();

        if ($idAlvo === null) {
            $cnpj = GestaoContexto::post('cnpj');
            $r = $this->rn->criar($admin, $nome, $cnpj, $ip);
            if ($r['ok']) {
                $this->voltar('empresa_criada');
            }
            if (($r['codigo'] ?? '') === 'validacao') {
                return $this->formularioView(422, null, ['nome' => $nome, 'cnpj' => $cnpj], $r['erros'] ?? [], null);
            }
            $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
        }

        $r = $this->rn->editar($admin, $idAlvo, $nome, $ip);
        if ($r['ok']) {
            $this->voltar(($r['sem_mudanca'] ?? false) ? 'empresa_sem_mudanca' : 'empresa_editada');
        }
        if (($r['codigo'] ?? '') === 'validacao') {
            return $this->formularioView(422, $idAlvo, ['nome' => $nome, 'cnpj' => (string) ($atual['cnpj'] ?? '')], $r['erros'] ?? [], $atual);
        }
        $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));

        return [];
    }

    /**
     * Cada linha de `empresas` traz id_empresa, nome, cnpj, ativo, criado_em,
     * totens_ativos e totens_total.
     *
     * @param array{tipo:string,texto:string}|null $flashLocal
     */
    private function pagina(int $status, ?array $flashLocal): array
    {
        return [
            'view' => 'empresas',
            'status' => $status,
            'flash' => $flashLocal,
            'dados' => [
                'empresas' => $this->rn->listar(),
                'confirmacao' => $this->confirmacao(),
            ],
        ];
    }

    /**
     * Aviso do segundo passo (so se a acao ainda faz sentido para o estado atual):
     * excluir so de empresa SEM totem; inativar so de empresa ativa com totens ativos.
     *
     * @return array{acao:string,id_empresa:int,nome:string,texto:string,rotulo:string,totens_ativos:int}|null
     */
    private function confirmacao(): ?array
    {
        $acao = GestaoContexto::query('confirmar');
        $id = GestaoContexto::inteiroPositivo(GestaoContexto::query('id'));
        if ($id === null || !in_array($acao, ['inativar', 'excluir'], true)) {
            return null;
        }
        $e = $this->rn->obter($id);
        if ($e === null) {
            return null;
        }
        $ativos = (int) $e['totens_ativos'];
        if ($acao === 'excluir') {
            if ((int) $e['totens_total'] > 0) {
                return null;
            }

            return [
                'acao' => 'excluir', 'id_empresa' => $id, 'nome' => (string) $e['nome'], 'totens_ativos' => 0,
                'texto' => 'Esta ação exclui a empresa de forma definitiva. Para voltar a usá-la será preciso cadastrá-la de novo.',
                'rotulo' => 'Excluir definitivamente',
            ];
        }
        if ((int) $e['ativo'] !== 1 || $ativos === 0) {
            return null;
        }

        return [
            'acao' => 'inativar', 'id_empresa' => $id, 'nome' => (string) $e['nome'], 'totens_ativos' => $ativos,
            'texto' => $ativos . ' totem(ns) ativo(s) vão deixar de concluir o check-in no Talent enquanto a empresa estiver inativa.',
            'rotulo' => 'Inativar mesmo assim',
        ];
    }

    /**
     * @param array{nome:string,cnpj:string} $valores
     * @param array<string,string> $erros
     * @param array<string,mixed>|null $atual empresa em edicao (com totens_ativos/totens_total)
     */
    private function formularioView(int $status, ?int $idAlvo, array $valores, array $erros, ?array $atual): array
    {
        return [
            'view' => 'empresa-form',
            'status' => $status,
            'titulo' => $idAlvo !== null ? 'Editar empresa' : 'Nova empresa',
            'dados' => [
                'id_alvo' => $idAlvo,
                'editando' => $idAlvo !== null,
                'valores' => $valores,
                'erros' => $erros,
                'totens_ativos' => (int) ($atual['totens_ativos'] ?? 0),
                'totens_total' => (int) ($atual['totens_total'] ?? 0),
            ],
        ];
    }

    private function voltar(string $codigo): void
    {
        $codigo = isset(GestaoContexto::MENSAGENS[$codigo]) ? $codigo : 'erro_interno';
        GestaoHttp::redirecionar(GestaoContexto::CAMINHO_EMPRESAS . '?msg=' . rawurlencode($codigo));
    }
}
