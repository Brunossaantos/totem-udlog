<?php

namespace App\Controller;

use Util\AuthGestao;
use Util\GestaoHttp;

/**
 * Login e logout da Gestao Totem (demanda gestao-totem, F1). Os metodos
 * devolvem o "resultado de pagina" ['view','dados','status','cabecalhos'] que
 * `gestaoRenderizar()` imprime, ou redirecionam (exit).
 */
final class GestaoLoginController
{
    public function __construct(private GestaoContexto $ctx)
    {
    }

    /** @return array{view:string,dados:array<string,mixed>,status:int,cabecalhos?:list<string>} */
    public function login(): array
    {
        $auth = $this->ctx->auth;

        if ($auth->metodoSeguro()) {
            if ($this->ctx->sessao !== null) {
                GestaoHttp::redirecionar($this->destinoAposLogin($this->ctx->sessao));
            }

            return $this->formulario(200, null, '');
        }

        $login = GestaoContexto::post('login');
        if (!$auth->tokenLoginValido(GestaoContexto::post('token_login'))) {
            return $this->formulario(400, 'A página de login ficou aberta por tempo demais. Digite o login e a senha de novo.', $login);
        }

        $r = $auth->login($login, GestaoContexto::post('senha'));
        if ($r['status'] === 500) {
            return $this->formulario(500, AuthGestao::MSG_LOGIN_ERRO_INTERNO, $login);
        }
        if ($r['status'] === 429) {
            $minutos = max(1, (int) ceil(((int) ($r['retry_after'] ?? AuthGestao::JANELA_IP_SEGUNDOS)) / 60));

            return $this->formulario(
                429,
                'Muitas tentativas de acesso a partir deste local. Aguarde cerca de ' . $minutos . ' ' . ($minutos === 1 ? 'minuto' : 'minutos') . ' e tente de novo.',
                '',
                ['Retry-After: ' . (int) ($r['retry_after'] ?? AuthGestao::JANELA_IP_SEGUNDOS)]
            );
        }
        if (!$r['ok']) {
            return $this->formulario(401, AuthGestao::MSG_LOGIN_GENERICA, $login);
        }

        $auth->emitirCookie($r['token']);
        if ($r['deve_trocar_senha']) {
            GestaoHttp::redirecionar(GestaoContexto::CAMINHO_CONTA);
        }
        GestaoHttp::redirecionar(GestaoContexto::paginaInicial((string) $r['usuario']['perfil']));

        return [];
    }

    /** POST + CSRF (garantido por GestaoContexto::iniciar). Destroi a sessao no servidor. */
    public function logout(): void
    {
        $this->ctx->auth->logout();
        $this->ctx->auth->limparCookie();
        GestaoHttp::redirecionar('/gestao/login.php');
    }

    /** @param array<string,mixed> $sessao */
    private function destinoAposLogin(array $sessao): string
    {
        return $sessao['deve_trocar_senha'] ? GestaoContexto::CAMINHO_CONTA : GestaoContexto::paginaInicial((string) $sessao['perfil']);
    }

    /**
     * @param list<string> $cabecalhos
     * @return array{view:string,dados:array<string,mixed>,status:int,cabecalhos:list<string>}
     */
    private function formulario(int $status, ?string $erro, string $loginDigitado, array $cabecalhos = []): array
    {
        return [
            'view' => 'login',
            'status' => $status,
            'cabecalhos' => $cabecalhos,
            'dados' => [
                'token_login' => $this->ctx->auth->tokenLogin(),
                'erro' => $erro,
                'login_digitado' => mb_substr($loginDigitado, 0, 60, 'UTF-8'),
            ],
        ];
    }
}
