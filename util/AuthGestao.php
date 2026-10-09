<?php

namespace Util;

use App\Dao\AuditoriaDao;
use App\Dao\LoginTentativaGestaoDao;
use App\Dao\SessaoGestaoDao;
use App\Dao\UsuarioGestaoDao;
use App\Rn\UsuarioGestaoRn;
use PDO;
use Throwable;

/**
 * Autenticacao humana da Gestao Totem (demanda gestao-totem, F1). SEPARADA do
 * token do totem (Util\Auth) e da chave servidor-a-servidor (Util\AuthServidor).
 *
 * Sessao: cookie OPACO `gestao_sid` (32 bytes de random_bytes, em hex) com
 * HttpOnly, Secure, SameSite=Strict, Path=/gestao e sem Domain. No banco fica so
 * o sha256 do token (tb_gestao_sessao.id_sessao). Id novo a cada login e a cada
 * troca de senha. Inatividade e teto absoluto vem do .env (Util\GestaoConfig).
 * `ativo` e `perfil` sao relidos do BANCO a toda requisicao.
 *
 * CSRF: token por sessao (random_bytes 32), comparado com hash_equals, exigido
 * em todo metodo diferente de GET/HEAD (header X-CSRF-Token ou campo de
 * formulario csrf_token), mais verificacao de Origin/Sec-Fetch-Site contra o
 * Host da requisicao. O formulario de login (ainda sem sessao) usa um token
 * assinado por HMAC com validade de 2 h, cuja CHAVE e derivada do sal
 * (hash_hmac(sha256, "gestao-login-token-v1", GESTAO_HASH_SALT)): o sal nunca
 * assina nada diretamente. O token e SEM ESTADO (pode ser reusado dentro da
 * validade; protege contra CSRF de login, nao contra repeticao).
 *
 * Login: mensagem unica generica, password_verify contra hash dummy quando o
 * usuario nao existe/esta inativo/bloqueado (custo igual), bloqueio por conta
 * (5 falhas = 15 min) e limite por IP (10 falhas / 15 min, hash do "balde" do
 * IP + sal; balde = IPv4 inteiro ou prefixo /64 do IPv6). Qualquer excecao
 * dentro do login vira resposta 500 generica com log so da classe da excecao
 * (a senha digitada nunca chega a log, resposta ou stack trace).
 *
 * Esta classe nao emite HTML nem chama exit: devolve resultados. Quem emite
 * resposta e Util\GestaoHttp / os controllers.
 */
class AuthGestao
{
    public const COOKIE = 'gestao_sid';

    public const COOKIE_PATH = '/gestao';

    public const LIMITE_FALHAS_CONTA = 5;

    public const BLOQUEIO_CONTA_MIN = 15;

    public const LIMITE_FALHAS_IP = 10;

    public const JANELA_IP_SEGUNDOS = 900;

    public const TOKEN_LOGIN_VALIDADE_SEG = 7200;

    /** Limpeza oportunista: 1 chance em N a cada tentativa de login (falha ou sucesso), em lote limitado. */
    public const LIMPEZA_UMA_EM = 50;

    public const MSG_LOGIN_GENERICA = 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.';

    public const MSG_LOGIN_ERRO_INTERNO = 'Não foi possível entrar agora. Nada foi alterado. Tente novamente em instantes.';

    private UsuarioGestaoDao $usuarios;

    private SessaoGestaoDao $sessoes;

    private LoginTentativaGestaoDao $tentativas;

    private AuditoriaDao $auditoria;

    /** @var array<string,mixed>|null|false false = ainda nao resolvida */
    private $sessao = false;

    /**
     * @param array<string,mixed>  $server  tipicamente $_SERVER
     * @param array<string,mixed>  $cookies tipicamente $_COOKIE
     * @param callable|null        $relogio unix time (so para a janela de IP e o token de login, em testes)
     */
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

    // ------------------------------------------------------------------ transporte

    /** HTTPS obrigatorio, igual a AuthServidor::requisicaoHttps, salvo a flag explicita de dev local. */
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

    /** Host da requisicao validado e normalizado (minusculo, sem porta padrao), ou null. */
    public function hostDaRequisicao(): ?string
    {
        $host = strtolower((string) ($this->server['HTTP_HOST'] ?? ''));

        return $this->normalizarAutoridade($host);
    }

    /**
     * Origin (quando presente) tem de ser o proprio Host da requisicao, com o
     * esquema coerente. Sem Origin: se o navegador mandou Sec-Fetch-Site, so
     * same-origin/none passam. Sem nenhum dos dois, o token CSRF decide.
     */
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

    // ------------------------------------------------------------------ sessao

    /**
     * Sessao valida da requisicao (ou null). Efeitos: revoga a sessao se o
     * usuario foi desativado ou o User-Agent mudou e atualiza o ultimo acesso
     * (no maximo 1x/min).
     *
     * @return array{id_sessao:string,id_usuario:int,csrf_token:string,login:string,nome:string,perfil:string,deve_trocar_senha:bool}|null
     */
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
            // expirada por inatividade/teto (ou inexistente): apaga o resto, se houver
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

    /** Descarta o cache da sessao (apos login/logout/troca de senha na mesma requisicao). */
    public function esquecerSessao(): void
    {
        $this->sessao = false;
    }

    /**
     * Exige sessao valida. Sem sessao: GET de pagina = 302 para o login,
     * qualquer outro metodo ou pedido JSON = 401.
     *
     * @return array<string,mixed> sessao
     */
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

    /**
     * Exige login E um dos perfis (403 se o perfil nao atende).
     *
     * @return array<string,mixed> sessao
     */
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

    // ------------------------------------------------------------------ CSRF

    public function csrfToken(): string
    {
        $sessao = $this->sessaoAtual();

        return $sessao['csrf_token'] ?? '';
    }

    /** @param string|null $enviado valor recebido (header X-CSRF-Token ou campo csrf_token) */
    public function csrfValido(#[\SensitiveParameter] ?string $enviado): bool
    {
        $sessao = $this->sessaoAtual();
        if ($sessao === null || $enviado === null || $enviado === '') {
            return false;
        }

        return hash_equals($sessao['csrf_token'], $enviado);
    }

    /**
     * Chave do HMAC do token do formulario de login, DERIVADA do sal (o sal em si
     * nunca assina o token). Trocar o rotulo de versao invalida os tokens antigos.
     */
    private function chaveTokenLogin(): string
    {
        return hash_hmac('sha256', 'gestao-login-token-v1', $this->config->sal);
    }

    /** Token CSRF do formulario de login (sem sessao): "<ts>.<hmac>". Valido por 2 h, sem estado. */
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

    // ------------------------------------------------------------------ login / logout

    /**
     * Tenta o login. Nao emite cookie nem resposta.
     *
     * @return array{ok:bool,status:int,token?:string,usuario?:array<string,mixed>,deve_trocar_senha?:bool,retry_after?:int}
     *         status 200 = ok, 401 = credenciais (mensagem unica), 429 = limite por IP,
     *         500 = falha tecnica (excecao capturada; log so da classe)
     */
    public function login(string $login, #[\SensitiveParameter] string $senha): array
    {
        try {
            return $this->loginInterno($login, $senha);
        } catch (Throwable $e) {
            error_log('AuthGestao: login_falhou ' . get_class($e));

            return ['ok' => false, 'status' => 500];
        }
    }

    /** @return array<string,mixed> */
    private function loginInterno(string $login, #[\SensitiveParameter] string $senha): array
    {
        $ip = $this->ipCliente();
        // chave do contador = IPv4 inteiro ou prefixo /64 do IPv6 (B5); a auditoria grava o IP completo
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
            // B4: um atacante que so falha tambem enche as tabelas; a limpeza roda neste caminho (lote limitado)
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
        // Fixacao de sessao: um login SEMPRE gera token novo e descarta o que o cliente trazia.
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

    /** Cria uma sessao nova para o usuario e devolve o TOKEN do cookie (nunca gravado). */
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

    /** Destroi a sessao atual no servidor e audita. Quem chama limpa o cookie. */
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

    // ------------------------------------------------------------------ cookie

    public function emitirCookie(#[\SensitiveParameter] string $token): void
    {
        setcookie(self::COOKIE, $token, $this->opcoesCookie(0));
    }

    public function limparCookie(): void
    {
        setcookie(self::COOKIE, '', $this->opcoesCookie(1));
    }

    /** @return array{expires:int,path:string,secure:bool,httponly:bool,samesite:string} */
    private function opcoesCookie(int $expira): array
    {
        return [
            'expires' => $expira,
            'path' => self::COOKIE_PATH,
            // Em dev local por HTTP (flag explicita) o navegador descartaria um cookie Secure.
            'secure' => $this->requisicaoEhHttps() || !$this->config->permitirHttp,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    // ------------------------------------------------------------------ internos

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

    /** A auditoria de LOGIN/LOGOUT nunca derruba o fluxo: falha vira log fixo sem dado. */
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
