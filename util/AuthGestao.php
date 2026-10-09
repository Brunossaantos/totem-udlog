<?php

namespace Util;

use App\Dao\AuditoriaDao;
use App\Dao\LoginTentativaGestaoDao;
use App\Dao\SessaoGestaoDao;
use App\Dao\UsuarioGestaoDao;
use App\Rn\UsuarioGestaoRn;
use PDO;
use Throwable;

class AuthGestao
{
    public const COOKIE = 'gestao_sid';

    public const COOKIE_PATH = '/gestao';

    public const LIMITE_FALHAS_CONTA = 5;

    public const BLOQUEIO_CONTA_MIN = 15;

    public const LIMITE_FALHAS_IP = 10;

    public const JANELA_IP_SEGUNDOS = 900;

    public const TOKEN_LOGIN_VALIDADE_SEG = 7200;

    public const LIMPEZA_UMA_EM = 50;

    public const MSG_LOGIN_GENERICA = 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.';

    public const MSG_LOGIN_ERRO_INTERNO = 'Não foi possível entrar agora. Nada foi alterado. Tente novamente em instantes.';

    private UsuarioGestaoDao $usuarios;

    private SessaoGestaoDao $sessoes;

    private LoginTentativaGestaoDao $tentativas;

    private AuditoriaDao $auditoria;

    private $sessao = false;

    public function __construct(
        private PDO $pdo,
        private GestaoConfig $config,
        private array $server,
        private array $cookies,
        private $relogio = null
    ) {
        $this->usuarios = new UsuarioGestaoDao($pdo);
        $this->sessoes = new SessaoGestaoDao($pdo);
        $this->tentativas = new LoginTentativaGestaoDao($pdo);
        $this->auditoria = new AuditoriaDao($pdo);
    }

    public function transporteAceito(): bool
    {
        return $this->config->permitirHttp || AuthServidor::requisicaoHttps($this->server);
    }

    public function requisicaoEhHttps(): bool
    {
        return AuthServidor::requisicaoHttps($this->server);
    }

    public function ipCliente(): string
    {
        return IpCliente::obter($this->server);
    }

    public function metodo(): string
    {
        $m = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        return $m === 'HEAD' ? 'GET' : $m;
    }

    public function metodoSeguro(): bool
    {
        return $this->metodo() === 'GET';
    }

    public function hostDaRequisicao(): ?string
    {
        $host = strtolower((string) ($this->server['HTTP_HOST'] ?? ''));

        return $this->normalizarAutoridade($host);
    }

    public function origemPermitida(): bool
    {
        $host = $this->hostDaRequisicao();
        if ($host === null) {
            return false;
        }
        $origin = $this->server['HTTP_ORIGIN'] ?? null;
        if ($origin !== null) {
            if (!is_string($origin) || strlen($origin) > 300) {
                return false;
            }
            $partes = parse_url(strtolower($origin));
            if (!is_array($partes) || !isset($partes['scheme'], $partes['host']) || isset($partes['path']) || isset($partes['user'])) {
                return false;
            }
            $esquemaEsperado = $this->requisicaoEhHttps() ? 'https' : 'http';
            if ($partes['scheme'] !== $esquemaEsperado) {
                return false;
            }
            $autoridade = $partes['host'] . (isset($partes['port']) ? ':' . $partes['port'] : '');
            $normalizada = $this->normalizarAutoridade($autoridade, $partes['scheme']);

            return $normalizada !== null && $normalizada === $host;
        }
        $site = $this->server['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($site !== null && (!is_string($site) || !in_array(strtolower($site), ['same-origin', 'none'], true))) {
            return false;
        }

        return true;
    }

    private function normalizarAutoridade(string $autoridade, ?string $esquema = null): ?string
    {
        if (preg_match('/\A([a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?)(:([0-9]{1,5}))?\z/D', $autoridade, $m) !== 1) {
            return null;
        }
        $nome = $m[1];
        $porta = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;
        $esquema ??= $this->requisicaoEhHttps() ? 'https' : 'http';
        if ($porta !== null && (($esquema === 'https' && $porta === 443) || ($esquema === 'http' && $porta === 80))) {
            $porta = null;
        }

        return $porta === null ? $nome : $nome . ':' . $porta;
    }

    public function sessaoAtual(): ?array
    {
        if ($this->sessao !== false) {
            return $this->sessao;
        }
        $this->sessao = null;
        $token = $this->cookies[self::COOKIE] ?? null;
        if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            return null;
        }
        $id = hash('sha256', $token);
        $linha = $this->sessoes->buscarValida($id, $this->config->idleMin);
        if ($linha === null) {
            $this->sessoes->excluir($id);

            return null;
        }
        if ((int) $linha['ativo'] !== 1 || !hash_equals((string) $linha['ua_hash'], $this->uaHash())) {
            $this->sessoes->excluir($id);

            return null;
        }
        $this->sessoes->tocar($id);
        $this->sessao = [
            'id_sessao' => (string) $linha['id_sessao'],
            'id_usuario' => (int) $linha['id_usuario'],
            'csrf_token' => (string) $linha['csrf_token'],
            'login' => (string) $linha['login'],
            'nome' => (string) $linha['nome'],
            'perfil' => (string) $linha['perfil'],
            'deve_trocar_senha' => (int) $linha['deve_trocar_senha'] === 1,
        ];

        return $this->sessao;
    }

    public function esquecerSessao(): void
    {
        $this->sessao = false;
    }

    public function exigirLogin(): array
    {
        $sessao = $this->sessaoAtual();
        if ($sessao === null) {
            if ($this->metodoSeguro() && !$this->pedidoJson()) {
                GestaoHttp::redirecionar('/gestao/login.php');
            }
            if ($this->pedidoJson()) {
                GestaoHttp::json(401, ['sucesso' => false, 'erro' => 'Não autenticado. Entre novamente para continuar.']);
            }
            GestaoHttp::negar(401, 'Sessão expirada', 'Sua sessão expirou. Entre novamente para continuar.', [], GestaoHttp::ACAO_LOGIN);
        }

        return $sessao;
    }

    public function exigirPerfil(string ...$perfis): array
    {
        $sessao = $this->exigirLogin();
        if (!in_array($sessao['perfil'], $perfis, true)) {
            if ($this->pedidoJson()) {
                GestaoHttp::json(403, ['sucesso' => false, 'erro' => 'Acesso negado. O seu perfil não tem permissão para esta ação.']);
            }
            GestaoHttp::negar(403, 'Acesso negado', 'O seu perfil não tem permissão para abrir esta página. Volte ao início ou peça acesso a um administrador.', [], GestaoHttp::ACAO_INICIO);
        }

        return $sessao;
    }

    public function pedidoJson(): bool
    {
        $accept = strtolower((string) ($this->server['HTTP_ACCEPT'] ?? ''));

        return str_contains($accept, 'application/json')
            || strtolower((string) ($this->server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    public function csrfToken(): string
    {
        $sessao = $this->sessaoAtual();

        return $sessao['csrf_token'] ?? '';
    }

    public function csrfValido(#[\SensitiveParameter] ?string $enviado): bool
    {
        $sessao = $this->sessaoAtual();
        if ($sessao === null || $enviado === null || $enviado === '') {
            return false;
        }

        return hash_equals($sessao['csrf_token'], $enviado);
    }

    private function chaveTokenLogin(): string
    {
        return hash_hmac('sha256', 'gestao-login-token-v1', $this->config->sal);
    }

    public function tokenLogin(): string
    {
        $ts = (string) $this->agora();

        return $ts . '.' . hash_hmac('sha256', 'gestao-login|' . $ts, $this->chaveTokenLogin());
    }

    public function tokenLoginValido(#[\SensitiveParameter] ?string $token): bool
    {
        if ($token === null || preg_match('/\A([0-9]{9,12})\.([a-f0-9]{64})\z/D', $token, $m) !== 1) {
            return false;
        }
        $ts = (int) $m[1];
        $agora = $this->agora();
        if ($ts > $agora + 300 || ($agora - $ts) > self::TOKEN_LOGIN_VALIDADE_SEG) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', 'gestao-login|' . $m[1], $this->chaveTokenLogin()), $m[2]);
    }

    public function login(string $login, #[\SensitiveParameter] string $senha): array
    {
        try {
            return $this->loginInterno($login, $senha);
        } catch (Throwable $e) {
            error_log('AuthGestao: login_falhou ' . get_class($e));

            return ['ok' => false, 'status' => 500];
        }
    }

    private function loginInterno(string $login, #[\SensitiveParameter] string $senha): array
    {
        $ip = $this->ipCliente();
        $ipHash = IpCliente::hash(IpCliente::balde($ip), $this->config->sal);
        $janela = intdiv($this->agora(), self::JANELA_IP_SEGUNDOS);

        if ($this->tentativas->contar($ipHash, $janela) >= self::LIMITE_FALHAS_IP) {
            return [
                'ok' => false,
                'status' => 429,
                'retry_after' => max(1, (($janela + 1) * self::JANELA_IP_SEGUNDOS) - $this->agora()),
            ];
        }

        $login = UsuarioGestaoRn::normalizarLogin($login);
        $usuario = null;
        $formatoOk = preg_match(UsuarioGestaoRn::LOGIN_REGEX, $login) === 1 && strlen($senha) <= SenhaPolitica::MAX_BYTES_LOGIN;
        if ($formatoOk) {
            $usuario = $this->usuarios->buscarPorLogin($login);
        }

        $motivo = 'credenciais';
        $verificado = false;
        if ($usuario === null) {
            SenhaPolitica::verificarDummy($senha);
        } elseif ((int) $usuario['ativo'] !== 1) {
            SenhaPolitica::verificarDummy($senha);
            $motivo = 'conta_inativa';
        } elseif ($this->usuarios->estaBloqueada((int) $usuario['id_usuario'])) {
            SenhaPolitica::verificarDummy($senha);
            $motivo = 'conta_bloqueada';
        } elseif (SenhaPolitica::verificar($senha, (string) $usuario['senha_hash'])) {
            $verificado = true;
        } else {
            if ($this->usuarios->registrarFalhaSenha((int) $usuario['id_usuario'], self::LIMITE_FALHAS_CONTA, self::BLOQUEIO_CONTA_MIN)) {
                $motivo = 'conta_bloqueada';
            }
        }

        if (!$verificado) {
            $this->tentativas->incrementar($ipHash, $janela);
            if ($this->tentativas->contar($ipHash, $janela) === self::LIMITE_FALHAS_IP) {
                $motivo = 'ip_limitado';
            }
            $this->auditarSemFalhar(
                fn () => $this->auditoria->registrar(
                    $usuario !== null ? (int) $usuario['id_usuario'] : null,
                    'LOGIN_FALHA',
                    $usuario !== null ? 'usuario' : null,
                    $usuario !== null ? (int) $usuario['id_usuario'] : null,
                    'SEM_EFEITO',
                    ['motivo' => $motivo],
                    $ip
                )
            );
            if (random_int(1, self::LIMPEZA_UMA_EM) === 1) {
                $this->limpezaOportunista($janela);
            }

            return ['ok' => false, 'status' => 401];
        }

        $idUsuario = (int) $usuario['id_usuario'];
        if (SenhaPolitica::precisaRehash((string) $usuario['senha_hash'])) {
            $this->usuarios->atualizarHashApenas($idUsuario, SenhaPolitica::gerarHash($senha));
        }
        $this->usuarios->registrarLoginOk($idUsuario);
        $anterior = $this->cookies[self::COOKIE] ?? null;
        if (is_string($anterior) && preg_match('/\A[a-f0-9]{64}\z/D', $anterior) === 1) {
            $this->sessoes->excluir(hash('sha256', $anterior));
        }
        $token = $this->criarSessao($idUsuario);
        $this->auditarSemFalhar(
            fn () => $this->auditoria->registrar($idUsuario, 'LOGIN_OK', 'usuario', $idUsuario, 'OK', ['origem' => 'web'], $ip)
        );

        if (random_int(1, self::LIMPEZA_UMA_EM) === 1) {
            $this->limpezaOportunista($janela);
        }

        return [
            'ok' => true,
            'status' => 200,
            'token' => $token,
            'usuario' => array_diff_key($usuario, ['senha_hash' => true]),
            'deve_trocar_senha' => (int) $usuario['deve_trocar_senha'] === 1,
        ];
    }

    public function criarSessao(int $idUsuario): string
    {
        $token = bin2hex(random_bytes(32));
        $this->sessoes->criar(
            hash('sha256', $token),
            $idUsuario,
            bin2hex(random_bytes(32)),
            $this->uaHash(),
            $this->config->absolutoMin
        );
        $this->esquecerSessao();

        return $token;
    }

    public function logout(): void
    {
        $sessao = $this->sessaoAtual();
        $token = $this->cookies[self::COOKIE] ?? null;
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1) {
            $this->sessoes->excluir(hash('sha256', $token));
        }
        if ($sessao !== null) {
            $id = (int) $sessao['id_usuario'];
            $this->auditarSemFalhar(
                fn () => $this->auditoria->registrar($id, 'LOGOUT', 'usuario', $id, 'OK', ['origem' => 'web'], $this->ipCliente())
            );
        }
        $this->esquecerSessao();
    }

    public function revogarSessoes(int $idUsuario, ?string $idSessaoExceto = null): int
    {
        $n = $this->sessoes->revogarDoUsuario($idUsuario, $idSessaoExceto);
        $this->esquecerSessao();

        return $n;
    }

    public function emitirCookie(#[\SensitiveParameter] string $token): void
    {
        setcookie(self::COOKIE, $token, $this->opcoesCookie(0));
    }

    public function limparCookie(): void
    {
        setcookie(self::COOKIE, '', $this->opcoesCookie(1));
    }

    private function opcoesCookie(int $expira): array
    {
        return [
            'expires' => $expira,
            'path' => self::COOKIE_PATH,
            'secure' => $this->requisicaoEhHttps() || !$this->config->permitirHttp,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    private function uaHash(): string
    {
        return hash('sha256', (string) ($this->server['HTTP_USER_AGENT'] ?? ''));
    }

    private function agora(): int
    {
        return $this->relogio !== null ? (int) ($this->relogio)() : time();
    }

    private function limpezaOportunista(int $janelaAtual): void
    {
        try {
            $this->sessoes->apagarExpiradas();
            $this->tentativas->apagarAntigas($janelaAtual - 2);
        } catch (Throwable $e) {
            error_log('AuthGestao: limpeza_falhou ' . get_class($e));
        }
    }

    private function auditarSemFalhar(callable $acao): void
    {
        try {
            $acao();
        } catch (Throwable $e) {
            error_log('AuthGestao: auditoria_falhou ' . get_class($e));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e]);
        }
    }
}
