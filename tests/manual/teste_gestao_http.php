<?php

/**
 * Gestao Totem (F1, 2026-10-06), ponta a ponta por php-cgi contra as paginas
 * REAIS de public/gestao/: cabecalhos, HTTPS, cookie, login (mensagem unica,
 * limite por IP, CF-Connecting-IP forjado), sessao (inatividade, teto, User-Agent,
 * logout), CSRF/Origin, RBAC por rota (matriz), troca obrigatoria de senha,
 * usuarios (criar, editar, ativar/desativar, redefinir), senha temporaria exibida
 * uma vez, XSS/SQLi, auditoria e logs sem segredo. Banco QA descartavel.
 *
 * Uso: php tests/manual/teste_gestao_http.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\UsuarioGestaoDao;
use Util\SenhaPolitica;

$banco = null;
$storage = null;
$log = gtNovoLogCgi();
@unlink($log);

const CABECALHOS_ESPERADOS = [
    'cache-control' => 'no-store, private',
    'x-frame-options' => 'DENY',
    'x-content-type-options' => 'nosniff',
    'referrer-policy' => 'same-origin',
    'x-robots-tag' => 'noindex, nofollow, noarchive',
    'content-security-policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'",
];

function cabecalhosOk(array $r): bool
{
    foreach (CABECALHOS_ESPERADOS as $nome => $valor) {
        if (gtCabecalho($r, $nome) !== $valor) {
            return false;
        }
    }

    return true;
}

function semInline(string $html): bool
{
    // nenhum <script> sem src, nenhum <style>, nenhum atributo style= ou on*=
    if (preg_match_all('/<script\b([^>]*)>/i', $html, $m) > 0) {
        foreach ($m[1] as $attrs) {
            if (!preg_match('/\bsrc="\/gestao\/assets\/gestao\.js\?v=\d+"/', $attrs)) {
                return false;
            }
        }
    }
    if (preg_match('/<style\b/i', $html) === 1 || preg_match('/\sstyle\s*=/i', $html) === 1 || preg_match('/\son[a-z]+\s*=/i', $html) === 1 || preg_match('/javascript:/i', $html) === 1) {
        return false;
    }
    if (preg_match_all('/<link\b[^>]*>/i', $html, $links) > 0) {
        foreach ($links[0] as $l) {
            // so recursos do proprio /gestao/assets/: CSS e favicons (rel=icon/apple-touch-icon) copiados do totem
            if (!preg_match('/href="\/gestao\/assets\/(gestao\.css|favicon\.svg|favicon-32\.png|apple-touch-icon\.png)\?v=\d+"/', $l)) {
                return false;
            }
        }
    }

    return true;
}

function localizacao(array $r): ?string
{
    return gtCabecalho($r, 'location');
}

try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $dao = new UsuarioGestaoDao($pdo);
    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idAdmin2 = gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Admin');
    $idUsu = gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    $idUsuPend = gtSemear($pdo, 'davi.pendente', 'usuario', GT_SENHA_BOA, true, true, 'Davi Pendente');
    $idAdmPend = gtSemear($pdo, 'edu.pendente', 'admin', GT_SENHA_BOA, true, true, 'Edu Pendente');
    $contar = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_usuario');
    $base = ['logErro' => $log];

    // =====================================================================
    // 1. Cabecalhos, HTTPS, configuracao
    // =====================================================================
    $r = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '198.51.100.1']);
    afirmar('login GET: 200 e cabecalhos de seguranca exatos (no-store, XFO, nosniff, Referrer, Robots, CSP)', $r['status'] === 200 && cabecalhosOk($r));
    afirmar('login GET: CSP sem unsafe-inline/unsafe-eval e com frame-ancestors none', !str_contains((string) gtCabecalho($r, 'content-security-policy'), 'unsafe') && str_contains((string) gtCabecalho($r, 'content-security-policy'), "frame-ancestors 'none'"));
    afirmar('login GET: view SEM script/style inline, sem atributos style=/on*=', semInline($r['corpo']));
    afirmar('login GET: sem cookie de sessao antes do login', gtSid($r) === null);

    $rHttp = gtChamar($base + ['arquivo' => 'login.php', 'https' => false, 'ip' => '198.51.100.1']);
    afirmar('HTTPS obrigatorio: HTTP puro => 403 (sem a flag), sem formulario e SEM cookie, com cabecalhos', $rHttp['status'] === 403 && cabecalhosOk($rHttp) && !str_contains($rHttp['corpo'], 'token_login') && $rHttp['setCookies'] === []);
    $rHttpPost = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'https' => false, 'form' => ['login' => 'ana.admin', 'senha' => GT_SENHA_BOA, 'token_login' => 'x'], 'ip' => '198.51.100.1', 'origin' => 'http://gestao.exemplo.test']);
    afirmar('HTTPS obrigatorio: POST de login por HTTP => 403 e nada de cookie', $rHttpPost['status'] === 403 && $rHttpPost['setCookies'] === []);
    $rXfp = gtChamar($base + ['arquivo' => 'login.php', 'https' => false, 'cabecalhos' => ['HTTP_X_FORWARDED_PROTO' => 'https'], 'ip' => '198.51.100.1']);
    afirmar('HTTPS: X-Forwarded-Proto https (proxy) e aceito, como em AuthServidor::requisicaoHttps', $rXfp['status'] === 200);
    $rDev = gtChamar($base + ['arquivo' => 'login.php', 'https' => false, 'env' => ['GESTAO_PERMITIR_HTTP' => 'true'], 'ip' => '198.51.100.1']);
    afirmar('HTTPS: com GESTAO_PERMITIR_HTTP=true (dev local) o HTTP e aceito', $rDev['status'] === 200 && cabecalhosOk($rDev));
    $tk = gtTokenLogin($rDev);
    $rDevLogin = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'https' => false, 'origin' => 'http://gestao.exemplo.test', 'env' => ['GESTAO_PERMITIR_HTTP' => 'true'], 'form' => ['login' => 'ana.admin', 'senha' => GT_SENHA_BOA, 'token_login' => (string) $tk], 'ip' => '198.51.100.2']);
    $cDev = $rDevLogin['setCookies'][0] ?? null;
    afirmar('HTTPS: no modo dev por HTTP o cookie sai SEM Secure (senao o navegador o descarta) mas mantem HttpOnly e SameSite=Strict', $cDev !== null && !array_key_exists('secure', $cDev['atributos']) && array_key_exists('httponly', $cDev['atributos']) && ($cDev['atributos']['samesite'] ?? '') === 'Strict');
    $rSemSal = gtChamar($base + ['arquivo' => 'login.php', 'env' => ['GESTAO_HASH_SALT' => ''], 'ip' => '198.51.100.1']);
    afirmar('configuracao: sem GESTAO_HASH_SALT => 503 fechado (sem formulario) e com cabecalhos', $rSemSal['status'] === 503 && !str_contains($rSemSal['corpo'], 'token_login') && cabecalhosOk($rSemSal));
    $rSalCurto = gtChamar($base + ['arquivo' => 'login.php', 'env' => ['GESTAO_HASH_SALT' => 'curto'], 'ip' => '198.51.100.1']);
    afirmar('configuracao: GESTAO_HASH_SALT curto => 503', $rSalCurto['status'] === 503);
    $rHost = gtChamar($base + ['arquivo' => 'login.php', 'host' => 'a b/../x', 'ip' => '198.51.100.1']);
    afirmar('Host invalido => 400 com cabecalhos', $rHost['status'] === 400 && cabecalhosOk($rHost));
    foreach (['PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $m) {
        $rm = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => $m, 'ip' => '198.51.100.1']);
        afirmar("metodo $m em login.php => 405 com Allow", $rm['status'] === 405 && str_contains((string) gtCabecalho($rm, 'allow'), 'GET') && cabecalhosOk($rm));
    }

    // =====================================================================
    // 2. Login: mensagem unica, token de login, origem
    // =====================================================================
    $g = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '198.51.100.3']);
    $tk = (string) gtTokenLogin($g);
    $form = static fn (string $l, string $s, string $t) => ['login' => $l, 'senha' => $s, 'token_login' => $t];
    $falha1 = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('inexistente.pessoa', 'Qualquer-Coisa-1', $tk), 'ip' => '198.51.100.3']);
    $falha2 = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('ana.admin', 'Senha-Errada-Total-1', $tk), 'ip' => '198.51.100.3']);
    $msg = static function (array $r): ?string {
        return preg_match('/id="login-erro" role="alert">([^<]*)</', $r['corpo'], $m) === 1 ? $m[1] : null;
    };
    afirmar('login: usuario inexistente e senha errada => 401 com a MESMA mensagem unica', $falha1['status'] === 401 && $falha2['status'] === 401 && $msg($falha1) === 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.' && $msg($falha2) === 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.');
    afirmar('login: falha NAO emite cookie e mantem os cabecalhos', gtSid($falha1) === null && gtSid($falha2) === null && cabecalhosOk($falha1));
    afirmar('login: a senha digitada nunca volta na pagina', !str_contains($falha2['corpo'], 'Senha-Errada-Total-1'));
    $semTok = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'ana.admin', 'senha' => GT_SENHA_BOA], 'ip' => '198.51.100.3']);
    afirmar('login: sem token_login => 400 (nao autentica, mesmo com a senha certa)', $semTok['status'] === 400 && gtSid($semTok) === null);
    $tkRuim = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('ana.admin', GT_SENHA_BOA, '1791319136.' . str_repeat('a', 64)), 'ip' => '198.51.100.3']);
    afirmar('login: token_login adulterado => 400', $tkRuim['status'] === 400 && gtSid($tkRuim) === null);
    $orgRuim = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('ana.admin', GT_SENHA_BOA, $tk), 'origin' => 'https://evil.example', 'ip' => '198.51.100.3']);
    afirmar('login: Origin de outro site => 403 (login CSRF) e sem cookie', $orgRuim['status'] === 403 && gtSid($orgRuim) === null);
    $sfs = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('ana.admin', GT_SENHA_BOA, $tk), 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'origin' => null, 'ip' => '198.51.100.3']);
    afirmar('login: Sec-Fetch-Site cross-site sem Origin => 403', $sfs['status'] === 403);
    $arr = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'corpo' => 'login[]=a&senha[]=b&token_login[]=c', 'ip' => '198.51.100.3']);
    afirmar('login: campos em array (login[]=) nao quebram (400 sem erro 500)', $arr['status'] === 400);
    // XSS refletido no campo login
    $xss = '"><script>alert(1)</script>';
    $rx = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form($xss, 'x', $tk), 'ip' => '198.51.100.3']);
    afirmar('XSS: login digitado volta ESCAPADO (value=&quot;&gt;&lt;script&gt;) e nunca como HTML cru', str_contains($rx['corpo'], '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($rx['corpo'], '<script>alert') && semInline($rx['corpo']));

    // sucesso
    $lAdmin = gtLogin('ana.admin', GT_SENHA_BOA, $base + ['ip' => '198.51.100.4']);
    afirmar('login admin: 302 para /gestao/usuarios.php com cookie gestao_sid', $lAdmin['resp']['status'] === 302 && localizacao($lAdmin['resp']) === '/gestao/usuarios.php' && $lAdmin['sid'] !== null && preg_match('/\A[a-f0-9]{64}\z/', $lAdmin['sid']) === 1);
    $c = $lAdmin['resp']['setCookies'][0];
    afirmar('cookie: HttpOnly, Secure, SameSite=Strict, Path=/gestao, sem Domain, de sessao (sem Expires/Max-Age)', array_key_exists('httponly', $c['atributos']) && array_key_exists('secure', $c['atributos']) && ($c['atributos']['samesite'] ?? '') === 'Strict' && ($c['atributos']['path'] ?? '') === '/gestao' && !array_key_exists('domain', $c['atributos']) && !array_key_exists('expires', $c['atributos']) && !array_key_exists('max-age', $c['atributos']));
    afirmar('cookie: 32 bytes aleatorios (64 hex) e id_sessao no banco = sha256 do token', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => hash('sha256', (string) $lAdmin['sid'])]) === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => (string) $lAdmin['sid']]) === 0);
    $lAdmin2 = gtLogin('ana.admin', GT_SENHA_BOA, $base + ['ip' => '198.51.100.4']);
    afirmar('login: sid NOVO a cada login (diferente do anterior)', $lAdmin2['sid'] !== null && $lAdmin2['sid'] !== $lAdmin['sid'] && $lAdmin['csrf'] !== $lAdmin2['csrf']);
    $lUsu = gtLogin('carla.usuario', GT_SENHA_BOA, $base + ['ip' => '198.51.100.5']);
    afirmar('login usuario: 302 para /gestao/conta.php', $lUsu['resp']['status'] === 302 && localizacao($lUsu['resp']) === '/gestao/conta.php' && $lUsu['sid'] !== null);
    $lUsuPend = gtLogin('davi.pendente', GT_SENHA_BOA, $base + ['ip' => '198.51.100.6']);
    afirmar('login com troca de senha pendente: 302 para /gestao/conta.php', localizacao($lUsuPend['resp']) === '/gestao/conta.php' && $lUsuPend['sid'] !== null);
    $lAdmPend = gtLogin('edu.pendente', GT_SENHA_BOA, $base + ['ip' => '198.51.100.7']);
    $lAdmin2b = gtLogin('beto.admin', GT_SENHA_BOA, $base + ['ip' => '198.51.100.8']);

    $ck = static fn (array $l): array => ['gestao_sid' => (string) $l['sid']];
    $get = static function (string $arquivo, ?array $l, array $extra = []) use ($base, $ck): array {
        return gtChamar($base + ['arquivo' => $arquivo, 'cookies' => $l === null ? [] : $ck($l), 'ip' => '198.51.100.9'] + $extra);
    };
    $post = static function (string $arquivo, ?array $l, array $form, array $extra = []) use ($base, $ck): array {
        if ($l !== null && !array_key_exists('csrf_token', $form) && ($extra['semCsrf'] ?? false) !== true) {
            $form['csrf_token'] = (string) $l['csrf'];
        }
        unset($extra['semCsrf']);

        return gtChamar($base + ['arquivo' => $arquivo, 'metodo' => 'POST', 'form' => $form, 'cookies' => $l === null ? [] : $ck($l), 'ip' => '198.51.100.9'] + $extra);
    };

    // =====================================================================
    // 3. Matriz RBAC (GET)
    // =====================================================================
    $espera = static function (string $rotulo, array $r, int $status, ?string $loc = null): void {
        afirmar("RBAC $rotulo => $status" . ($loc !== null ? " -> $loc" : ''), $r['status'] === $status && ($loc === null || localizacao($r) === $loc) && cabecalhosOk($r));
    };
    $login = '/gestao/login.php';
    foreach (['index.php', 'usuarios.php', 'usuario-form.php', 'conta.php'] as $pag) {
        $espera("anonimo GET $pag", $get($pag, null), 302, $login);
    }
    $espera('anonimo GET login.php', $get('login.php', null), 200);
    $espera('anonimo com cookie invalido GET usuarios.php', gtChamar($base + ['arquivo' => 'usuarios.php', 'cookies' => ['gestao_sid' => str_repeat('a', 64)], 'ip' => '198.51.100.9']), 302, $login);
    $espera('usuario GET index.php', $get('index.php', $lUsu), 302, '/gestao/conta.php');
    $espera('usuario GET usuarios.php', $get('usuarios.php', $lUsu), 403);
    $espera('usuario GET usuario-form.php', $get('usuario-form.php', $lUsu), 403);
    $espera('usuario GET conta.php', $get('conta.php', $lUsu), 200);
    $espera('usuario GET login.php (ja logado)', $get('login.php', $lUsu), 302, '/gestao/conta.php');
    $espera('admin GET index.php', $get('index.php', $lAdmin), 302, '/gestao/usuarios.php');
    $espera('admin GET usuarios.php', $get('usuarios.php', $lAdmin), 200);
    $espera('admin GET usuario-form.php', $get('usuario-form.php', $lAdmin), 200);
    $espera('admin GET usuario-form.php?id=', $get('usuario-form.php', $lAdmin, ['query' => ['id' => (string) $idUsu]]), 200);
    $espera('admin GET conta.php', $get('conta.php', $lAdmin), 200);
    $espera('admin GET login.php (ja logado)', $get('login.php', $lAdmin), 302, '/gestao/usuarios.php');
    $espera('usuario com troca pendente GET index.php', $get('index.php', $lUsuPend), 302, '/gestao/conta.php');
    $espera('usuario com troca pendente GET conta.php', $get('conta.php', $lUsuPend), 200);
    $espera('usuario com troca pendente GET usuarios.php (perfil nao atende)', $get('usuarios.php', $lUsuPend), 403);
    $espera('admin com troca pendente GET usuarios.php (bloqueada ate trocar)', $get('usuarios.php', $lAdmPend), 302, '/gestao/conta.php');
    $espera('admin com troca pendente GET usuario-form.php', $get('usuario-form.php', $lAdmPend), 302, '/gestao/conta.php');
    $espera('admin com troca pendente GET index.php', $get('index.php', $lAdmPend), 302, '/gestao/conta.php');
    $espera('admin com troca pendente GET conta.php', $get('conta.php', $lAdmPend), 200);
    $pContaPend = $get('conta.php', $lUsuPend);
    afirmar('troca obrigatoria: conta.php mostra o aviso e NAO mostra menu lateral', str_contains($pContaPend['corpo'], 'id="aviso-troca-obrigatoria"') && !str_contains($pContaPend['corpo'], 'gestao-sidebar'));
    $pAdminMenu = $get('usuarios.php', $lAdmin);
    $pUsuMenu = $get('conta.php', $lUsu);
    afirmar('menu: admin ve Usuarios e Minha conta, e itens ainda inexistentes ficam ocultos', str_contains($pAdminMenu['corpo'], 'href="/gestao/usuarios.php"') && str_contains($pAdminMenu['corpo'], 'href="/gestao/conta.php"') && !str_contains($pAdminMenu['corpo'], '/gestao/totens.php') && !str_contains($pAdminMenu['corpo'], '/gestao/logs.php'));
    afirmar('menu: usuario NAO ve o item Usuarios (nem link para ele)', !str_contains($pUsuMenu['corpo'], '/gestao/usuarios.php') && str_contains($pUsuMenu['corpo'], 'href="/gestao/conta.php"'));
    afirmar('403: pagina de erro sem inline e sem detalhes tecnicos', semInline($get('usuarios.php', $lUsu)['corpo']) && !str_contains($get('usuarios.php', $lUsu)['corpo'], 'ana.admin'));
    foreach ([['usuarios.php', $lAdmin], ['usuario-form.php', $lAdmin], ['conta.php', $lAdmin], ['conta.php', $lUsu], ['conta.php', $lUsuPend]] as [$pag, $l]) {
        $rr = $get($pag, $l);
        afirmar("views $pag sem script/style inline e com csrf em meta", semInline($rr['corpo']) && gtCsrf($rr) === $l['csrf']);
    }

    // RBAC (POST) e sem sessao
    $n0 = $contar();
    $espera('anonimo POST usuarios.php', $post('usuarios.php', null, ['acao' => 'desativar', 'id_usuario' => (string) $idUsu]), 401);
    $espera('anonimo POST usuario-form.php', $post('usuario-form.php', null, ['login' => 'novo.alguem', 'nome' => 'Novo Alguem', 'perfil' => 'admin']), 401);
    $espera('anonimo POST conta.php', $post('conta.php', null, ['senha_atual' => 'x']), 401);
    $rj = gtChamar($base + ['arquivo' => 'usuarios.php', 'cabecalhos' => ['HTTP_ACCEPT' => 'application/json'], 'ip' => '198.51.100.9']);
    afirmar('anonimo pedindo JSON => 401 em JSON (sem redirect)', $rj['status'] === 401 && (json_decode($rj['corpo'], true)['sucesso'] ?? null) === false && str_contains((string) gtCabecalho($rj, 'content-type'), 'application/json'));
    $rj = gtChamar($base + ['arquivo' => 'usuarios.php', 'cookies' => $ck($lUsu), 'cabecalhos' => ['HTTP_ACCEPT' => 'application/json'], 'ip' => '198.51.100.9']);
    afirmar('usuario pedindo JSON de pagina admin => 403 em JSON', $rj['status'] === 403 && (json_decode($rj['corpo'], true)['sucesso'] ?? null) === false);
    $espera('usuario POST usuarios.php (com CSRF valido)', $post('usuarios.php', $lUsu, ['acao' => 'desativar', 'id_usuario' => (string) $idAdmin2]), 403);
    $espera('usuario POST usuario-form.php criar admin (com CSRF valido)', $post('usuario-form.php', $lUsu, ['login' => 'invasor.admin', 'nome' => 'Invasor Admin', 'perfil' => 'admin']), 403);
    $espera('usuario com troca pendente POST usuarios.php', $post('usuarios.php', $lUsuPend, ['acao' => 'desativar', 'id_usuario' => (string) $idAdmin2]), 403);
    $espera('admin com troca pendente POST usuario-form.php', $post('usuario-form.php', $lAdmPend, ['login' => 'invasor.admin', 'nome' => 'Invasor Admin', 'perfil' => 'admin']), 403);
    afirmar('POSTs negados nao alteraram nada (usuarios, ativos e perfis intactos)', $contar() === $n0 && $dao->buscarPorLogin('invasor.admin') === null && (int) $dao->buscarPorId($idAdmin2)['ativo'] === 1);
    foreach (['PUT', 'DELETE', 'PATCH'] as $m) {
        $rm = gtChamar($base + ['arquivo' => 'usuarios.php', 'metodo' => $m, 'cookies' => $ck($lAdmin), 'ip' => '198.51.100.9']);
        afirmar("metodo $m em usuarios.php => 405", $rm['status'] === 405 && str_contains((string) gtCabecalho($rm, 'allow'), 'POST'));
    }

    // =====================================================================
    // 4. CSRF
    // =====================================================================
    $acaoDesativar = ['acao' => 'desativar', 'id_usuario' => (string) $idUsu];
    $r1 = $post('usuarios.php', $lAdmin, $acaoDesativar, ['semCsrf' => true]);
    afirmar('CSRF: POST sem token => 403 e NADA muda', $r1['status'] === 403 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $r2 = $post('usuarios.php', $lAdmin, $acaoDesativar + ['csrf_token' => str_repeat('0', 64)]);
    afirmar('CSRF: token errado => 403 e NADA muda', $r2['status'] === 403 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $r3 = $post('usuarios.php', $lAdmin, $acaoDesativar + ['csrf_token' => $lAdmin2['csrf']]);
    afirmar('CSRF: token de OUTRA sessao (mesmo usuario) => 403 e NADA muda', $r3['status'] === 403 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $r4 = $post('usuarios.php', $lAdmin, $acaoDesativar + ['csrf_token' => $lAdmin2b['csrf']]);
    afirmar('CSRF: token de OUTRO usuario => 403', $r4['status'] === 403 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $r5 = $post('usuarios.php', $lAdmin, $acaoDesativar + ['csrf_token' => (string) $lAdmin['csrf']], ['origin' => 'https://evil.example']);
    afirmar('CSRF: token certo mas Origin de outro site => 403 e NADA muda', $r5['status'] === 403 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $r6 = $post('usuarios.php', $lAdmin, $acaoDesativar, ['origin' => 'null']);
    afirmar('CSRF: Origin "null" => 403', $r6['status'] === 403);
    $r7 = $post('usuarios.php', $lAdmin, $acaoDesativar, ['origin' => null, 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']]);
    afirmar('CSRF: Sec-Fetch-Site cross-site => 403', $r7['status'] === 403 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $rg = $get('usuarios.php', $lAdmin, ['query' => ['acao' => 'desativar', 'id_usuario' => (string) $idUsu]]);
    afirmar('GET com parametros de acao NAO tem efeito colateral', $rg['status'] === 200 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $rg = $get('usuario-form.php', $lAdmin, ['query' => ['login' => 'por.get', 'nome' => 'Por Get', 'perfil' => 'admin']]);
    afirmar('GET em usuario-form.php com campos na URL NAO cria usuario', $rg['status'] === 200 && $dao->buscarPorLogin('por.get') === null);
    $rLogoutGet = $get('logout.php', $lAdmin);
    afirmar('logout por GET => 405 e a sessao continua valida', $rLogoutGet['status'] === 405 && $get('usuarios.php', $lAdmin)['status'] === 200);
    $rLogoutSemTok = $post('logout.php', $lAdmin, [], ['semCsrf' => true]);
    afirmar('logout POST sem CSRF => 403 e a sessao continua valida', $rLogoutSemTok['status'] === 403 && $get('usuarios.php', $lAdmin)['status'] === 200);
    $rHeader = gtChamar($base + ['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => ['acao' => 'ativar', 'id_usuario' => (string) $idUsu], 'cookies' => $ck($lAdmin), 'cabecalhos' => ['HTTP_X_CSRF_TOKEN' => (string) $lAdmin['csrf']], 'ip' => '198.51.100.9']);
    afirmar('CSRF: header X-CSRF-Token tambem e aceito (acao "ativar" sem mudanca => 302 com msg)', $rHeader['status'] === 302 && str_contains((string) localizacao($rHeader), 'msg=sem_mudanca'));

    // =====================================================================
    // 5. SQL injection e entradas hostis
    // =====================================================================
    $tabelasAntes = gtEscalar($pdo, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tb\\_gestao\\_%'");
    foreach (["1 OR 1=1", "1; DROP TABLE tb_gestao_usuario", "1' OR '1'='1", "-1", "0", "99999999999999999999", "1e3", "abc", "1 UNION SELECT 1"] as $idRuim) {
        $rr = $post('usuarios.php', $lAdmin, ['acao' => 'desativar', 'id_usuario' => $idRuim]);
        afirmar('SQLi: id_usuario ' . json_encode($idRuim) . ' => 400 sem efeito', $rr['status'] === 400);
    }
    foreach (["1 OR 1=1", "1; DROP TABLE tb_gestao_usuario", "x"] as $idRuim) {
        $rr = $get('usuario-form.php', $lAdmin, ['query' => ['id' => $idRuim]]);
        afirmar('SQLi: usuario-form ?id=' . json_encode($idRuim) . ' => redireciona com nao_encontrado', $rr['status'] === 302 && str_contains((string) localizacao($rr), 'msg=nao_encontrado'));
    }
    $rr = $post('usuarios.php', $lAdmin, ['acao' => "desativar'; DROP TABLE tb_gestao_usuario;--", 'id_usuario' => (string) $idUsu]);
    afirmar('SQLi: acao hostil => 400', $rr['status'] === 400);
    $rr = $post('usuario-form.php', $lAdmin, ['login' => "x.y' OR '1'='1", 'nome' => "Robert'); DROP TABLE tb_gestao_usuario;--", 'perfil' => "admin' OR '1'='1"]);
    afirmar('SQLi: criar com login/nome/perfil hostis => 422 e nada criado', $rr['status'] === 422 && $contar() === $n0);
    $rr = $post('usuario-form.php', $lAdmin, ['id_usuario' => "1 OR 1=1", 'nome' => 'Nome Valido', 'perfil' => 'usuario']);
    afirmar('SQLi: editar com id hostil => nao_encontrado', $rr['status'] === 302 && str_contains((string) localizacao($rr), 'msg=nao_encontrado'));
    afirmar('SQLi: as 4 tabelas continuam intactas e ninguem foi alterado', gtEscalar($pdo, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tb\\_gestao\\_%'") === $tabelasAntes && $contar() === $n0 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $rr = $get('usuarios.php', $lAdmin, ['query' => ['msg' => '<script>alert(1)</script>']]);
    afirmar('XSS: ?msg= fora do catalogo e ignorado (nunca ecoado)', $rr['status'] === 200 && !str_contains($rr['corpo'], 'alert(1)') && !str_contains($rr['corpo'], 'id="gestao-flash"'));
    $rr = $get('usuarios.php', $lAdmin, ['query' => ['msg' => 'ultimo_admin']]);
    afirmar('flash: ?msg=ultimo_admin mostra a mensagem do catalogo (erro, role=alert)', str_contains($rr['corpo'], 'id="gestao-flash" role="alert"') && str_contains($rr['corpo'], 'último administrador'));

    // =====================================================================
    // 6. Usuarios: criar (senha temporaria UMA vez), primeiro acesso, trocar senha
    // =====================================================================
    $rc = $post('usuario-form.php', $lAdmin, ['login' => 'Fabio.Souza', 'nome' => "Fabio D'Souza", 'perfil' => 'usuario']);
    afirmar('criar: 200 com a tela da senha temporaria e cabecalhos no-store', $rc['status'] === 200 && str_contains($rc['corpo'], 'id="senha-temporaria-valor"') && cabecalhosOk($rc));
    preg_match('/id="senha-temporaria-valor">([^<]+)</', $rc['corpo'], $mt);
    $temp = $mt[1] ?? '';
    afirmar('criar: senha temporaria exibida (19 chars) e UMA unica vez na resposta', preg_match('/\A[A-Za-z2-9-]{19}\z/', $temp) === 1 && substr_count($rc['corpo'], $temp) === 1);
    afirmar('criar: nome com apostrofo exibido escapado (&#039;) e login normalizado', str_contains($rc['corpo'], 'Fabio D&#039;Souza') && str_contains($rc['corpo'], '(fabio.souza)') && semInline($rc['corpo']));
    $novo = $dao->buscarPorLogin('fabio.souza');
    afirmar('criar: no banco so o hash (confere com a temporaria), troca obrigatoria e criado_por = admin', $novo !== null && SenhaPolitica::verificar($temp, (string) $novo['senha_hash']) && $novo['senha_hash'] !== $temp && (int) $novo['deve_trocar_senha'] === 1 && (int) $novo['criado_por'] === $idAdmin);
    $lista = $get('usuarios.php', $lAdmin);
    afirmar('criar: a senha temporaria NAO reaparece (lista, form, URL, redirect)', !str_contains($lista['corpo'], $temp) && !str_contains($get('usuario-form.php', $lAdmin)['corpo'], $temp) && !str_contains($get('usuario-form.php', $lAdmin, ['query' => ['id' => (string) $novo['id_usuario']]])['corpo'], $temp));
    $rc2 = $post('usuario-form.php', $lAdmin, ['login' => 'fabio.souza', 'nome' => 'Outro Fabio', 'perfil' => 'usuario']);
    afirmar('criar: login duplicado => 422 com erro no campo e SEM senha temporaria', $rc2['status'] === 422 && str_contains($rc2['corpo'], 'Já existe um usuário com esse login') && !str_contains($rc2['corpo'], 'senha-temporaria-valor'));
    $rc3 = $post('usuario-form.php', $lAdmin, ['login' => 'login_invalido', 'nome' => 'A', 'perfil' => 'usuario']);
    afirmar('criar: login e nome invalidos => 422 com os dois erros, valores preservados e escapados', $rc3['status'] === 422 && str_contains($rc3['corpo'], 'primeiro.segundo') && str_contains($rc3['corpo'], 'Informe o nome') && str_contains($rc3['corpo'], 'value="login_invalido"'));

    $lFab = gtLogin('fabio.souza', $temp, $base + ['ip' => '198.51.100.11']);
    afirmar('primeiro acesso: login com a temporaria funciona e leva para trocar senha', $lFab['sid'] !== null && localizacao($lFab['resp']) === '/gestao/conta.php');
    $espera('primeiro acesso: usuarios.php => 403 (perfil)', $get('usuarios.php', $lFab), 403);
    $rTroca1 = $post('conta.php', $lFab, ['senha_atual' => 'errada-errada-12', 'senha_nova' => 'Nova-Senha-Do-Fabio-1', 'senha_confirmacao' => 'Nova-Senha-Do-Fabio-1']);
    afirmar('trocar senha: senha atual errada => 422 com erro no campo', $rTroca1['status'] === 422 && str_contains($rTroca1['corpo'], 'A senha atual está incorreta') && semInline($rTroca1['corpo']));
    $rTroca2 = $post('conta.php', $lFab, ['senha_atual' => $temp, 'senha_nova' => 'curta', 'senha_confirmacao' => 'curta']);
    afirmar('trocar senha: politica (curta) => 422 com mensagem', $rTroca2['status'] === 422 && str_contains($rTroca2['corpo'], 'pelo menos 12'));
    $rTroca3 = $post('conta.php', $lFab, ['senha_atual' => $temp, 'senha_nova' => str_repeat('a', 73), 'senha_confirmacao' => str_repeat('a', 73)]);
    afirmar('trocar senha: 73 bytes => 422', $rTroca3['status'] === 422 && str_contains($rTroca3['corpo'], '72'));
    $rTroca4 = $post('conta.php', $lFab, ['senha_atual' => $temp, 'senha_nova' => 'Nova-Senha-Do-Fabio-1', 'senha_confirmacao' => 'Diferente-Do-Fabio-1']);
    afirmar('trocar senha: confirmacao diferente => 422', $rTroca4['status'] === 422 && str_contains($rTroca4['corpo'], 'A confirmação não confere'));
    $rTroca5 = $post('conta.php', $lFab, ['senha_atual' => $temp, 'senha_nova' => 'password1234', 'senha_confirmacao' => 'password1234']);
    afirmar('trocar senha: senha comum => 422', $rTroca5['status'] === 422 && str_contains($rTroca5['corpo'], 'comum'));
    $sessoesAntes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_usuario = :i', ['i' => (int) $novo['id_usuario']]);
    $lFabOutra = gtLogin('fabio.souza', $temp, $base + ['ip' => '198.51.100.11']);
    $rTroca = $post('conta.php', $lFab, ['senha_atual' => $temp, 'senha_nova' => 'Nova-Senha-Do-Fabio-1', 'senha_confirmacao' => 'Nova-Senha-Do-Fabio-1']);
    $sidNovo = gtSid($rTroca);
    afirmar('trocar senha: ok => 302 para a pagina inicial, cookie NOVO e sessao antiga invalida', $rTroca['status'] === 302 && localizacao($rTroca) === '/gestao/conta.php' && $sidNovo !== null && $sidNovo !== $lFab['sid']);
    afirmar('trocar senha: a OUTRA sessao do usuario tambem foi revogada', $get('conta.php', $lFabOutra)['status'] === 302 && $get('conta.php', $lFab)['status'] === 302);
    $pNova = gtChamar($base + ['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => (string) $sidNovo], 'ip' => '198.51.100.9']);
    afirmar('trocar senha: a sessao nova funciona e a troca obrigatoria acabou (aviso some, menu volta)', $pNova['status'] === 200 && !str_contains($pNova['corpo'], 'aviso-troca-obrigatoria') && str_contains($pNova['corpo'], 'gestao-sidebar'));
    afirmar('trocar senha: a senha temporaria deixou de valer e a nova entra', gtLogin('fabio.souza', $temp, $base + ['ip' => '198.51.100.12'])['sid'] === null && gtLogin('fabio.souza', 'Nova-Senha-Do-Fabio-1', $base + ['ip' => '198.51.100.13'])['sid'] !== null);
    $rSucesso = gtChamar($base + ['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => (string) $sidNovo], 'query' => ['msg' => 'senha_trocada'], 'ip' => '198.51.100.9']);
    afirmar('flash de sucesso (senha_trocada) renderiza role=status', str_contains($rSucesso['corpo'], 'id="gestao-flash" role="status"'));

    // =====================================================================
    // 7. Usuarios: editar, ativar/desativar, redefinir, auto-alteracao
    // =====================================================================
    $idFab = (int) $novo['id_usuario'];
    $re = $post('usuario-form.php', $lAdmin, ['id_usuario' => (string) $idFab, 'nome' => 'Fabio Souza Junior', 'perfil' => 'admin']);
    afirmar('editar: nome e perfil => 302 com msg usuario_editado', $re['status'] === 302 && str_contains((string) localizacao($re), 'msg=usuario_editado') && $dao->buscarPorId($idFab)['perfil'] === 'admin' && $dao->buscarPorId($idFab)['nome'] === 'Fabio Souza Junior');
    afirmar('editar: auditoria USUARIO_PERFIL com perfil_de/perfil_para', gtEscalar($pdo, "SELECT detalhe FROM tb_gestao_auditoria WHERE acao = 'USUARIO_PERFIL' AND alvo_id = :i AND resultado = 'OK'", ['i' => $idFab]) === 'perfil_de=usuario;perfil_para=admin');
    $re = $post('usuario-form.php', $lAdmin, ['id_usuario' => (string) $idAdmin, 'nome' => 'Ana Admin', 'perfil' => 'usuario']);
    afirmar('auto-alteracao: admin tentando rebaixar a si mesmo => msg proprio_perfil e perfil intacto', $re['status'] === 302 && str_contains((string) localizacao($re), 'msg=proprio_perfil') && $dao->buscarPorId($idAdmin)['perfil'] === 'admin');
    $re = $post('usuarios.php', $lAdmin, ['acao' => 'desativar', 'id_usuario' => (string) $idAdmin]);
    afirmar('auto-alteracao: admin desativando a si mesmo => msg proprio_ativo e segue ativo', $re['status'] === 302 && str_contains((string) localizacao($re), 'msg=proprio_ativo') && (int) $dao->buscarPorId($idAdmin)['ativo'] === 1);
    $re = $post('usuarios.php', $lAdmin, ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idAdmin, 'versao_senha' => '1']);
    afirmar('auto-alteracao: admin redefinindo a propria senha por la => msg propria_senha', $re['status'] === 302 && str_contains((string) localizacao($re), 'msg=propria_senha'));
    $pLista = $get('usuarios.php', $lAdmin);
    afirmar('lista: a linha do proprio admin NAO oferece desativar/redefinir (so Editar)', preg_match('/data-id-usuario="' . $idAdmin . '".*?<\/tr>/s', $pLista['corpo'], $mm) === 1 && !str_contains($mm[0], 'value="desativar"') && !str_contains($mm[0], 'redefinir-senha') && str_contains($mm[0], 'Editar'));
    $pEdit = $get('usuario-form.php', $lAdmin, ['query' => ['id' => (string) $idAdmin]]);
    afirmar('form: ao editar a si mesmo o perfil vem desabilitado', str_contains($pEdit['corpo'], '<select class="gestao-campo__entrada" id="perfil" name="perfil" disabled>'));

    // desativar derruba a sessao
    $lFabAdm = ['sid' => $sidNovo, 'csrf' => gtCsrf($pNova)];
    $rd = $post('usuarios.php', $lAdmin, ['acao' => 'desativar', 'id_usuario' => (string) $idFab]);
    afirmar('desativar: 302 msg usuario_desativado e usuario inativo', $rd['status'] === 302 && str_contains((string) localizacao($rd), 'msg=usuario_desativado') && (int) $dao->buscarPorId($idFab)['ativo'] === 0);
    afirmar('desativar: a sessao ABERTA do usuario deixa de valer na proxima requisicao (302 login)', $get('conta.php', $lFabAdm)['status'] === 302 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_usuario = :i', ['i' => $idFab]) === 0);
    $rIn = gtLogin('fabio.souza', 'Nova-Senha-Do-Fabio-1', $base + ['ip' => '198.51.100.14']);
    afirmar('desativar: login do desativado => mesma falha generica 401', $rIn['sid'] === null && $rIn['resp']['status'] === 401 && $msg($rIn['resp']) === 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.');
    $ra = $post('usuarios.php', $lAdmin, ['acao' => 'ativar', 'id_usuario' => (string) $idFab]);
    afirmar('ativar: 302 usuario_ativado e o login volta', str_contains((string) localizacao($ra), 'msg=usuario_ativado') && gtLogin('fabio.souza', 'Nova-Senha-Do-Fabio-1', $base + ['ip' => '198.51.100.15'])['sid'] !== null);

    // redefinir: nova temporaria UMA vez, sessoes revogadas, troca obrigatoria
    $lFab2 = gtLogin('fabio.souza', 'Nova-Senha-Do-Fabio-1', $base + ['ip' => '198.51.100.16']);
    $rr = $post('usuarios.php', $lAdmin, ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idFab, 'versao_senha' => (string) $dao->buscarPorId($idFab)['senha_versao']]);
    preg_match('/id="senha-temporaria-valor">([^<]+)</', $rr['corpo'], $mt2);
    $temp2 = $mt2[1] ?? '';
    afirmar('redefinir: 200 com nova temporaria exibida UMA vez e cabecalhos', $rr['status'] === 200 && preg_match('/\A[A-Za-z2-9-]{19}\z/', $temp2) === 1 && substr_count($rr['corpo'], $temp2) === 1 && $temp2 !== $temp && cabecalhosOk($rr));
    afirmar('redefinir: sessoes do alvo revogadas, senha antiga nao vale, a nova obriga a trocar', $get('conta.php', $lFab2)['status'] === 302 && gtLogin('fabio.souza', 'Nova-Senha-Do-Fabio-1', $base + ['ip' => '198.51.100.17'])['sid'] === null && localizacao(gtLogin('fabio.souza', $temp2, $base + ['ip' => '198.51.100.18'])['resp']) === '/gestao/conta.php');
    afirmar('redefinir: a temporaria nova nao reaparece em nenhuma pagina', !str_contains($get('usuarios.php', $lAdmin)['corpo'], $temp2));
    $rr404 = $post('usuarios.php', $lAdmin, ['acao' => 'redefinir-senha', 'id_usuario' => '987654', 'versao_senha' => '1']);
    afirmar('redefinir usuario inexistente => msg nao_encontrado', str_contains((string) localizacao($rr404), 'msg=nao_encontrado'));

    // ultimo admin (HTTP): beto desativa fabio(admin) e vice-versa preserva pelo menos um
    $post('usuarios.php', $lAdmin, ['acao' => 'desativar', 'id_usuario' => (string) $idAdmin2]);
    $post('usuarios.php', $lAdmin, ['acao' => 'desativar', 'id_usuario' => (string) $idAdmPend]);
    $post('usuarios.php', $lAdmin, ['acao' => 'desativar', 'id_usuario' => (string) $idFab]);
    afirmar('com varios admins desativados pelo unico admin restante, ele (ana) continua ativo e admin', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_usuario WHERE perfil = 'admin' AND ativo = 1") === 1 && (int) $dao->buscarPorId($idAdmin)['ativo'] === 1);
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario IN ($idAdmin2, $idAdmPend, $idFab)");

    // =====================================================================
    // 8. Sessao: inatividade, teto absoluto, User-Agent, logout
    // =====================================================================
    $lT = gtLogin('ana.admin', GT_SENHA_BOA, $base + ['ip' => '198.51.100.20']);
    $idT = hash('sha256', (string) $lT['sid']);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 29 MINUTE) WHERE id_sessao = :i')->execute(['i' => $idT]);
    afirmar('sessao HTTP: 29 min de inatividade ainda vale', $get('usuarios.php', $lT)['status'] === 200);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE id_sessao = :i')->execute(['i' => $idT]);
    $rT = $get('usuarios.php', $lT);
    afirmar('sessao HTTP: 31 min de inatividade => 302 login e linha removida', $rT['status'] === 302 && localizacao($rT) === '/gestao/login.php' && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $idT]) === 0);
    $rTPost = $post('usuarios.php', $lT, ['acao' => 'desativar', 'id_usuario' => (string) $idUsu]);
    afirmar('sessao HTTP: POST com sessao expirada => 401 (nao executa)', $rTPost['status'] === 401 && (int) $dao->buscarPorId($idUsu)['ativo'] === 1);
    $lT = gtLogin('ana.admin', GT_SENHA_BOA, $base + ['ip' => '198.51.100.20']);
    $idT = hash('sha256', (string) $lT['sid']);
    $pdo->prepare('UPDATE tb_gestao_sessao SET expira_em = DATE_SUB(NOW(), INTERVAL 1 SECOND), ultimo_acesso_em = NOW() WHERE id_sessao = :i')->execute(['i' => $idT]);
    afirmar('sessao HTTP: teto absoluto vencido => 302 login mesmo com atividade recente', $get('usuarios.php', $lT)['status'] === 302);
    $lT = gtLogin('ana.admin', GT_SENHA_BOA, $base + ['ip' => '198.51.100.20']);
    $rUa = gtChamar($base + ['arquivo' => 'usuarios.php', 'cookies' => $ck($lT), 'ua' => 'Navegador-Diferente/2.0', 'ip' => '198.51.100.9']);
    afirmar('sessao HTTP: mesmo cookie com User-Agent diferente => 302 login (e a sessao e destruida)', $rUa['status'] === 302 && $get('usuarios.php', $lT)['status'] === 302);
    $rIdle15 = gtLogin('ana.admin', GT_SENHA_BOA, $base + ['ip' => '198.51.100.20', 'env' => ['GESTAO_SESSION_IDLE_MIN' => '5']]);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 6 MINUTE) WHERE id_sessao = :i')->execute(['i' => hash('sha256', (string) $rIdle15['sid'])]);
    afirmar('sessao HTTP: GESTAO_SESSION_IDLE_MIN do .env vale (5 min expira aos 6)', $get('usuarios.php', $rIdle15, ['env' => ['GESTAO_SESSION_IDLE_MIN' => '5']])['status'] === 302);

    $lOut = gtLogin('ana.admin', GT_SENHA_BOA, $base + ['ip' => '198.51.100.21']);
    $ro = $post('logout.php', $lOut, []);
    $limpa = $ro['setCookies'][0] ?? null;
    afirmar('logout: 302 para o login e cookie limpo (valor vazio/expirado, mesmos atributos)', $ro['status'] === 302 && localizacao($ro) === '/gestao/login.php' && $limpa !== null && $limpa['nome'] === 'gestao_sid' && ($limpa['valor'] === '' || $limpa['valor'] === 'deleted') && ($limpa['atributos']['path'] ?? '') === '/gestao' && cabecalhosOk($ro));
    afirmar('logout: a sessao foi DESTRUIDA no servidor (o cookie guardado nao volta a valer)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => hash('sha256', (string) $lOut['sid'])]) === 0 && $get('usuarios.php', $lOut)['status'] === 302);
    $ro2 = $post('logout.php', $lOut, [], ['semCsrf' => true]);
    afirmar('logout sem sessao => 302 para o login (sem erro)', $ro2['status'] === 302 && localizacao($ro2) === '/gestao/login.php');
    $ro3 = $post('logout.php', null, []);
    afirmar('logout anonimo => 302 para o login', $ro3['status'] === 302 && localizacao($ro3) === '/gestao/login.php');
    $lPend = $lUsuPend;
    $ro4 = $post('logout.php', $lPend, []);
    afirmar('logout funciona mesmo com a troca de senha pendente', $ro4['status'] === 302 && $get('conta.php', $lPend)['status'] === 302);

    // =====================================================================
    // 9. Limite de login por IP via HTTP
    // =====================================================================
    $ipL = '203.0.113.130';
    $ultimo = null;
    for ($i = 1; $i <= 10; $i++) {
        $gg = gtChamar($base + ['arquivo' => 'login.php', 'ip' => $ipL, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '9.9.9.' . $i]]);
        $ultimo = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('fantasma.numero' . chr(96 + $i), 'x-qualquer-senha-1', (string) gtTokenLogin($gg)), 'ip' => $ipL, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '9.9.9.' . $i]]);
    }
    $gg = gtChamar($base + ['arquivo' => 'login.php', 'ip' => $ipL]);
    $r11 = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('ana.admin', GT_SENHA_BOA, (string) gtTokenLogin($gg)), 'ip' => $ipL, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '9.9.9.77']]);
    afirmar('limite por IP: depois de 10 falhas a 11a (credenciais CERTAS, CF-Connecting-IP forjado) => 429 com Retry-After', $r11['status'] === 429 && (int) gtCabecalho($r11, 'retry-after') >= 1 && (int) gtCabecalho($r11, 'retry-after') <= 900 && gtSid($r11) === null && cabecalhosOk($r11));
    afirmar('limite por IP: mensagem de espera sem revelar nada da conta', str_contains($r11['corpo'], 'Muitas tentativas') && !str_contains($r11['corpo'], 'ana.admin'));
    $gg2 = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '203.0.113.131']);
    $rOutro = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('ana.admin', GT_SENHA_BOA, (string) gtTokenLogin($gg2)), 'ip' => '203.0.113.131']);
    afirmar('limite por IP: outro IP segue entrando', $rOutro['status'] === 302 && gtSid($rOutro) !== null);
    // atras do Cloudflare de verdade
    for ($i = 1; $i <= 10; $i++) {
        $ggc = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '172.64.0.20', 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.222']]);
        gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('fantasma.numero' . chr(96 + $i), 'x-qualquer-senha-1', (string) gtTokenLogin($ggc)), 'ip' => '172.64.0.20', 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.222']]);
    }
    $ggc = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '172.64.0.20', 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.222']]);
    $rCfBloq = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('ana.admin', GT_SENHA_BOA, (string) gtTokenLogin($ggc)), 'ip' => '172.64.0.20', 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.222']]);
    $ggc2 = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '172.64.0.20', 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.223']]);
    $rCfLivre = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('ana.admin', GT_SENHA_BOA, (string) gtTokenLogin($ggc2)), 'ip' => '172.64.0.20', 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.223']]);
    afirmar('atras do Cloudflare: o limite e do IP do CLIENTE (um bloqueado 429, outro cliente do mesmo edge entra)', $rCfBloq['status'] === 429 && $rCfLivre['status'] === 302);
    afirmar('tabela de tentativas guarda so hash do IP', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_login_tentativa WHERE ip_hash LIKE '%203.0.113%' OR ip_hash LIKE '%198.51.100%'") === 0 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_login_tentativa WHERE CHAR_LENGTH(ip_hash) = 64') === (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_login_tentativa'));

    // bloqueio por conta via HTTP
    $idBloq = gtSemear($pdo, 'gabi.bloqueio', 'usuario', GT_SENHA_BOA, false, true, 'Gabi Bloqueio');
    for ($i = 1; $i <= 5; $i++) {
        $gb = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '198.51.100.14' . $i]);
        gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('gabi.bloqueio', 'errada-errada-' . $i, (string) gtTokenLogin($gb)), 'ip' => '198.51.100.14' . $i]);
    }
    $gb = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '198.51.100.150']);
    $rBl = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => $form('gabi.bloqueio', GT_SENHA_BOA, (string) gtTokenLogin($gb)), 'ip' => '198.51.100.150']);
    afirmar('bloqueio por conta (5 falhas de IPs distintos): a senha CERTA e recusada com a mesma mensagem generica', $rBl['status'] === 401 && $msg($rBl) === 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.' && gtSid($rBl) === null && $dao->estaBloqueada($idBloq));

    // =====================================================================
    // 10. Auditoria e logs
    // =====================================================================
    $acoes = array_column(gtLinhas($pdo, 'SELECT DISTINCT acao FROM tb_gestao_auditoria'), 'acao');
    sort($acoes);
    $esperadas = ['LOGIN_FALHA', 'LOGIN_OK', 'LOGOUT', 'SENHA_RESETADA', 'SENHA_TROCADA', 'USUARIO_ATIVO', 'USUARIO_CRIAR', 'USUARIO_PERFIL'];
    afirmar('auditoria: as 8 acoes do catalogo foram registradas pelo fluxo HTTP real', $acoes === $esperadas);
    afirmar('auditoria: nenhuma linha PENDENTE ficou aberta', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    $sent = [GT_SENHA_BOA, $temp, $temp2, 'Nova-Senha-Do-Fabio-1', 'errada-errada', 'Senha-Errada-Total-1', GT_SAL, (string) $lAdmin['sid'], (string) $lAdmin['csrf'], (string) $lUsu['sid'], 'ana.admin', 'carla.usuario', 'fabio.souza', "D'Souza", 'x-qualquer-senha', '$argon2'];
    $achouAud = [];
    foreach (gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria') as $linha) {
        $txt = (string) json_encode(array_map(static fn ($v) => is_string($v) ? bin2hex($v) . '|' . $v : $v, $linha), JSON_INVALID_UTF8_SUBSTITUTE);
        foreach ($sent as $s) {
            if (str_contains($txt, $s) || str_contains($txt, bin2hex($s))) {
                $achouAud[] = substr($s, 0, 8);
            }
        }
    }
    afirmar('auditoria SEM PII: nenhuma senha, token, csrf, sal, hash, login ou nome em tb_gestao_auditoria', $achouAud === []);
    afirmar('auditoria: IP gravado em binario (4 bytes), nunca texto', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria WHERE ip IS NOT NULL AND LENGTH(ip) NOT IN (4, 16)') === 0);
    $conteudoLog = is_file($log) ? (string) file_get_contents($log) : '';
    $achouLog = [];
    foreach ($sent as $s) {
        if (str_contains($conteudoLog, $s)) {
            $achouLog[] = substr($s, 0, 8);
        }
    }
    afirmar('logs do PHP sem segredo/dado pessoal (senhas, tokens, sal, hash, logins, nomes)', $achouLog === []);
    afirmar('captura de log funciona (a linha fixa de configuracao invalida foi gravada, prova de que a varredura enxerga o log)', str_contains($conteudoLog, 'gestao: configuracao_invalida'));
    afirmar('logs do PHP sem erro fatal/warning de codigo (so, no maximo, linhas fixas)', preg_match('/(Fatal error|Uncaught|Warning:|Notice:|Deprecated:|Stack trace)/i', $conteudoLog) !== 1);
    afirmar('tabela de sessao guarda so sha256 do token (nenhum token em claro)', (function () use ($pdo, $lAdmin, $lUsu, $sidNovo) {
        foreach ([$lAdmin['sid'], $lUsu['sid'], $sidNovo] as $t) {
            if ((int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :t OR csrf_token = :t', ['t' => (string) $t]) !== 0) {
                return false;
            }
        }

        return true;
    })());
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ')', false);
} finally {
    gtDestruirAmbiente($banco, $storage);
    @unlink($log);
}
exit(gtResumo('teste_gestao_http'));
