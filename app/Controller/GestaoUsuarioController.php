<?php

namespace App\Controller;

use App\Rn\UsuarioGestaoRn;
use Util\GestaoHttp;

/**
 * Usuarios da Gestao Totem (SO admin; o guard esta em GestaoContexto):
 * listar, criar, editar nome/perfil, ativar/desativar e redefinir senha
 * (demanda gestao-totem, F1). Os metodos devolvem o resultado de pagina que
 * `gestaoRenderizar()` imprime, ou redirecionam (PRG com ?msg=codigo).
 */
final class GestaoUsuarioController
{
    private UsuarioGestaoRn $rn;

    public function __construct(private GestaoContexto $ctx)
    {
        $this->rn = new UsuarioGestaoRn($ctx->pdo);
    }

    /**
     * usuarios.php: GET lista. POST acao=ativar|desativar|desbloquear|redefinir-senha + id_usuario
     * (redefinir-senha exige tambem versao_senha: a `senha_versao` que a tela mostrou).
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function listar(): array
    {
        if ($this->ctx->auth->metodoSeguro()) {
            return $this->pagina(200);
        }

        $acao = GestaoContexto::post('acao');
        $alvo = GestaoContexto::inteiroPositivo(GestaoContexto::post('id_usuario'));
        $versao = GestaoContexto::inteiroPositivo(GestaoContexto::post('versao_senha'));
        if ($alvo === null || !in_array($acao, ['ativar', 'desativar', 'desbloquear', 'redefinir-senha'], true) || ($acao === 'redefinir-senha' && $versao === null)) {
            return $this->pagina(400, ['tipo' => 'erro', 'texto' => 'Não foi possível entender o pedido. Nada foi alterado. Recarregue a página e tente de novo.']);
        }
        $admin = $this->ctx->idUsuario();
        $ip = $this->ctx->ip();

        if ($acao === 'redefinir-senha') {
            $r = $this->rn->redefinirSenha($admin, $alvo, $ip, $versao);
            if (!$r['ok']) {
                $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
            }
            $usuario = $this->rn->obter($alvo) ?? [];

            return $this->senhaTemporaria('redefinida', $usuario, (string) $r['senha_temporaria']);
        }

        if ($acao === 'desbloquear') {
            $r = $this->rn->desbloquear($admin, $alvo, $ip);
            if (!$r['ok']) {
                $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
            }
            $this->voltar(($r['sem_mudanca'] ?? false) ? 'sem_mudanca' : 'usuario_desbloqueado');
        }

        $r = $this->rn->definirAtivo($admin, $alvo, $acao === 'ativar', $ip);
        if (!$r['ok']) {
            $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
        }
        $this->voltar(($r['sem_mudanca'] ?? false) ? 'sem_mudanca' : ($acao === 'ativar' ? 'usuario_ativado' : 'usuario_desativado'));

        return [];
    }

    /**
     * usuario-form.php: GET (?id= para editar) mostra o formulario. POST salva
     * (cria se nao ha id_usuario, edita se ha).
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function formulario(): array
    {
        $idTexto = GestaoContexto::post('id_usuario') !== '' ? GestaoContexto::post('id_usuario') : GestaoContexto::query('id');
        $idAlvo = null;
        if ($idTexto !== '') {
            $idAlvo = GestaoContexto::inteiroPositivo($idTexto);
            if ($idAlvo === null || $this->rn->obter($idAlvo) === null) {
                $this->voltar('nao_encontrado');
            }
        }

        if ($this->ctx->auth->metodoSeguro()) {
            $atual = $idAlvo !== null ? ($this->rn->obter($idAlvo) ?? []) : [];

            return $this->formularioView(200, $idAlvo, [
                'login' => (string) ($atual['login'] ?? ''),
                'nome' => (string) ($atual['nome'] ?? ''),
                'perfil' => (string) ($atual['perfil'] ?? 'usuario'),
            ], []);
        }

        $nome = GestaoContexto::post('nome');
        $perfil = GestaoContexto::post('perfil');
        $admin = $this->ctx->idUsuario();
        $ip = $this->ctx->ip();

        if ($idAlvo === null) {
            $login = GestaoContexto::post('login');
            $r = $this->rn->criar($admin, $login, $nome, $perfil, $ip);
            if ($r['ok']) {
                $novo = $this->rn->obter((int) $r['id']) ?? [];

                return $this->senhaTemporaria('criado', $novo, (string) $r['senha_temporaria']);
            }
            if (($r['codigo'] ?? '') === 'validacao') {
                return $this->formularioView(422, null, ['login' => $login, 'nome' => $nome, 'perfil' => $perfil], $r['erros'] ?? []);
            }
            $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
        }

        $r = $this->rn->editar($admin, $idAlvo, $nome, $perfil, $ip);
        if ($r['ok']) {
            $this->voltar('usuario_editado');
        }
        if (($r['codigo'] ?? '') === 'validacao') {
            $atual = $this->rn->obter($idAlvo) ?? [];

            return $this->formularioView(422, $idAlvo, ['login' => (string) ($atual['login'] ?? ''), 'nome' => $nome, 'perfil' => $perfil], $r['erros'] ?? []);
        }
        $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));

        return [];
    }

    /**
     * Cada linha de `usuarios` traz `bloqueado_ate` (so se o bloqueio ainda vale,
     * senao null), `eh_proprio` (bool: e o admin logado) e `senha_versao`.
     *
     * @param array{tipo:string,texto:string}|null $flashLocal
     */
    private function pagina(int $status, ?array $flashLocal = null): array
    {
        $logado = $this->ctx->idUsuario();
        $usuarios = [];
        foreach ($this->rn->listar() as $u) {
            $u['eh_proprio'] = (int) $u['id_usuario'] === $logado;
            $usuarios[] = $u;
        }

        return [
            'view' => 'usuarios',
            'status' => $status,
            'flash' => $flashLocal,
            'dados' => [
                'usuarios' => $usuarios,
                'id_usuario_logado' => $logado,
            ],
        ];
    }

    /**
     * @param array<string,string> $valores
     * @param array<string,string> $erros
     */
    private function formularioView(int $status, ?int $idAlvo, array $valores, array $erros): array
    {
        return [
            'view' => 'usuario-form',
            'status' => $status,
            'titulo' => $idAlvo !== null ? 'Editar usuário' : 'Novo usuário',
            'dados' => [
                'id_alvo' => $idAlvo,
                'editando' => $idAlvo !== null,
                'valores' => $valores,
                'erros' => $erros,
                'proprio' => $idAlvo !== null && $idAlvo === $this->ctx->idUsuario(),
                'perfis' => UsuarioGestaoRn::PERFIS,
            ],
        ];
    }

    /**
     * Tela da senha temporaria: aparece SO nesta resposta (POST), nunca em URL,
     * flash ou banco (so o hash).
     *
     * @param array<string,mixed> $usuario
     */
    private function senhaTemporaria(string $contexto, array $usuario, string $senha): array
    {
        return [
            'view' => 'usuario-senha',
            'status' => 200,
            'titulo' => 'Senha temporária',
            'dados' => [
                'contexto' => $contexto,
                'usuario_login' => (string) ($usuario['login'] ?? ''),
                'usuario_nome' => (string) ($usuario['nome'] ?? ''),
                'senha_temporaria' => $senha,
            ],
        ];
    }

    private function voltar(string $codigo): void
    {
        $codigo = isset(GestaoContexto::MENSAGENS[$codigo]) ? $codigo : 'erro_interno';
        GestaoHttp::redirecionar(GestaoContexto::CAMINHO_USUARIOS . '?msg=' . rawurlencode($codigo));
    }
}
