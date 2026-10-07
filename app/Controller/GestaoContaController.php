<?php

namespace App\Controller;

use App\Rn\UsuarioGestaoRn;
use Util\GestaoHttp;

/**
 * "Minha conta" (todos os perfis): ver o proprio perfil e trocar a propria
 * senha (demanda gestao-totem, F1). A troca de senha revoga todas as sessoes do
 * usuario e abre uma sessao NOVA (id novo, CSRF novo) na mesma resposta.
 */
final class GestaoContaController
{
    private UsuarioGestaoRn $rn;

    public function __construct(private GestaoContexto $ctx)
    {
        $this->rn = new UsuarioGestaoRn($ctx->pdo);
    }

    /** @return array{view:string,dados:array<string,mixed>,status:int} */
    public function tratar(): array
    {
        if ($this->ctx->auth->metodoSeguro()) {
            return $this->pagina(200, []);
        }

        $idUsuario = $this->ctx->idUsuario();
        $obrigatoria = (bool) ($this->ctx->sessao['deve_trocar_senha'] ?? false);
        $r = $this->rn->trocarPropriaSenha(
            $idUsuario,
            GestaoContexto::post('senha_atual'),
            GestaoContexto::post('senha_nova'),
            GestaoContexto::post('senha_confirmacao'),
            $this->ctx->ip()
        );

        if ($r['ok']) {
            $token = $this->ctx->auth->criarSessao($idUsuario);
            $this->ctx->auth->emitirCookie($token);
            GestaoHttp::redirecionar(
                $obrigatoria
                    ? GestaoContexto::paginaInicial((string) $this->ctx->sessao['perfil'])
                    : GestaoContexto::CAMINHO_CONTA . '?msg=senha_trocada'
            );
        }
        if (($r['bloqueada'] ?? false) === true) {
            $this->ctx->auth->limparCookie();
            GestaoHttp::redirecionar('/gestao/login.php');
        }
        if (($r['codigo'] ?? '') === 'validacao') {
            return $this->pagina(422, $r['erros'] ?? []);
        }
        if (($r['codigo'] ?? '') === 'sem_permissao') {
            $this->ctx->auth->limparCookie();
            GestaoHttp::redirecionar('/gestao/login.php');
        }

        return $this->pagina(500, ['geral' => 'Não foi possível trocar a senha. Nada foi alterado. Tente novamente.']);
    }

    /**
     * @param array<string,string> $erros
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    private function pagina(int $status, array $erros): array
    {
        $usuario = $this->rn->obter($this->ctx->idUsuario()) ?? [];

        return [
            'view' => 'conta',
            'status' => $status,
            // so o texto do topo (#gestao-titulo): deixa claro que a troca e obrigatoria
            'titulo' => (bool) ($this->ctx->sessao['deve_trocar_senha'] ?? false) ? 'Troca de senha obrigatória' : 'Minha conta',
            'dados' => [
                'usuario' => [
                    'login' => (string) ($usuario['login'] ?? ''),
                    'nome' => (string) ($usuario['nome'] ?? ''),
                    'perfil' => (string) ($usuario['perfil'] ?? ''),
                    'ultimo_login_em' => $usuario['ultimo_login_em'] ?? null,
                ],
                'troca_obrigatoria' => (bool) ($this->ctx->sessao['deve_trocar_senha'] ?? false),
                'erros' => $erros,
            ],
        ];
    }
}
