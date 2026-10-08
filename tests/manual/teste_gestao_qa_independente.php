<?php

/**
 * QA INDEPENDENTE da Gestao Totem (demanda gestao-totem, F0+F1, 2026-10-06).
 * Testes ADVERSARIAIS escritos pelo QA (nao pelos implementadores), por php-cgi
 * contra as paginas REAIS, em banco QA descartavel (qa_gestao_infra.php).
 *
 * Uso: php tests/manual/teste_gestao_qa_independente.php
 *      QA_HEAD_TREE=<pasta com a arvore do HEAD> php ... (liga a prova byte a byte do quiosque)
 *
 * Procedimento para preparar QA_HEAD_TREE (arvore do HEAD, sem as mudancas locais).
 * Com core.autocrlf=true (Windows) o working tree tem arquivos em CRLF e o HTML do
 * quiosque (termo LGPD, hash) so e igual byte a byte se a arvore do HEAD tiver o
 * MESMO fim de linha do working tree. Passos (bash, <pasta> fora do repositorio,
 * ex.: scratchpad da sessao):
 *   1. mkdir <pasta> && git -c core.autocrlf=false archive HEAD | tar -x -C <pasta>
 *      (blobs em LF, sem conversao do archive)
 *   2. Arquivos que o working tree tem em CRLF (git ls-files --eol: i/lf w/crlf)
 *      voltam a CRLF na <pasta>: sed -i 's/$/\r/' <pasta>/<arquivo> para cada um.
 *   3. Copiar `.env` e `vendor/` do working tree para <pasta> (o archive nao os traz).
 *   4. As URLs de asset levam ?v=<filemtime>: para cada arquivo de public/totem na
 *      <pasta>, touch -r <arquivo do working tree> <arquivo na pasta>.
 *   5. QA_HEAD_TREE=<pasta> php tests/manual/teste_gestao_qa_independente.php
 *   Sem QA_HEAD_TREE a prova do quiosque vs HEAD e reportada como PULADA (falha).
 *
 * Nunca toca em udlog_totem; sem rede; sem Talent/VIO/n8n.
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\AuditoriaDao;
use App\Dao\UsuarioGestaoDao;
use App\Rn\UsuarioGestaoRn;
use Util\IpCliente;
use Util\SenhaPolitica;

// ---------------------------------------------------------------------------
// Modo worker (concorrencia real: cada worker e um processo PHP separado)
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode((string) base64_decode((string) $argv[2]), true);
    while (microtime(true) < (float) $job['t0']) {
        // espera ativa ate o instante comum de largada
    }
    $r = gtChamar($job['o']);
    echo base64_encode((string) json_encode(['status' => $r['status'], 'cab' => $r['cabecalhos'], 'corpo' => substr($r['corpo'], 0, 6000), 'sid' => gtSid($r)]));
    exit(0);
}

$banco = null;
$storage = null;
$log = gtNovoLogCgi();
@unlink($log);
$TODAS = [];   // toda resposta da gestao, para a checagem global de cabecalhos
$ipSeq = 0;

const CAB6 = [
    'cache-control' => 'no-store, private',
    'x-frame-options' => 'DENY',
    'x-content-type-options' => 'nosniff',
    'referrer-policy' => 'same-origin',
    'x-robots-tag' => 'noindex, nofollow, noarchive',
    'content-security-policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'",
];
const SENT_ERRADA = 'Sentinela-Senha-Errada-ZQ91-abc';
const SENT_NOVA = 'Sentinela-Nova-Senha-KJ77-xyz-1';
const SENT_NOVA2 = 'Sentinela-Outra-Senha-PL42-uvw-2';

function ipNovo(): string
{
    global $ipSeq;
    $ipSeq++;

    return '198.18.' . intdiv($ipSeq, 250) . '.' . (($ipSeq % 250) + 1);
}

/** Chamada da gestao com registro global das respostas. */
function G(array $o): array
{
    global $log, $TODAS;
    $o += ['logErro' => $log, 'ip' => '198.18.250.1'];
    $r = gtChamar($o);
    $TODAS[] = ['arq' => (string) $o['arquivo'], 'metodo' => (string) ($o['metodo'] ?? 'GET'), 'status' => $r['status'], 'cab' => $r['cabecalhos']];

    return $r;
}

function temCab6(array $r): bool
{
    foreach (CAB6 as $n => $v) {
        if (gtCabecalho($r, $n) !== $v) {
            return false;
        }
    }

    return true;
}

function loc(array $r): ?string
{
    return gtCabecalho($r, 'location');
}

function entrar(string $login, string $senha, ?string $ip = null, string $ua = 'QA-Navegador/1.0', array $extra = []): array
{
    $ip ??= ipNovo();
    $base = ['ip' => $ip, 'ua' => $ua] + $extra;
    $g = G(['arquivo' => 'login.php'] + $base);
    $tk = (string) gtTokenLogin($g);
    $r = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => $login, 'senha' => $senha, 'token_login' => $tk]] + $base);
    $sid = gtSid($r);
    $csrf = null;
    if ($sid !== null) {
        $csrf = gtCsrf(G(['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => $sid]] + $base));
    }

    return ['sid' => $sid, 'csrf' => $csrf, 'ip' => $ip, 'ua' => $ua, 'resp' => $r, 'tk' => $tk];
}

/** Requisicao autenticada (ou anonima se $s === null). CSRF automatico em POST salvo 'semCsrf'. */
function pedir(?array $s, string $arq, string $metodo = 'GET', array $form = [], array $extra = []): array
{
    $o = ['arquivo' => $arq, 'metodo' => $metodo, 'ip' => $s['ip'] ?? ipNovo(), 'ua' => $s['ua'] ?? 'QA-Navegador/1.0'];
    if ($s !== null && $s['sid'] !== null) {
        $o['cookies'] = ['gestao_sid' => $s['sid']];
    }
    if ($metodo !== 'GET' && $s !== null && !array_key_exists('csrf_token', $form) && ($extra['semCsrf'] ?? false) !== true) {
        $form['csrf_token'] = (string) $s['csrf'];
    }
    unset($extra['semCsrf']);
    if ($form !== [] || $metodo === 'POST') {
        $o['form'] = $form;
    }

    return G($extra + $o);
}

function semInlineQA(string $html): bool
{
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    foreach ($d->getElementsByTagName('script') as $sc) {
        if (!preg_match('/\A\/gestao\/assets\/gestao\.js\?v=\d+\z/', (string) $sc->getAttribute('src')) || trim($sc->textContent) !== '') {
            return false;
        }
    }
    if ($d->getElementsByTagName('style')->length > 0) {
        return false;
    }
    foreach ((new DOMXPath($d))->query('//*') as $el) {
        foreach ($el->attributes as $at) {
            $n = strtolower($at->name);
            if ($n === 'style' || str_starts_with($n, 'on') || (in_array($n, ['href', 'src', 'action', 'formaction'], true) && preg_match('/^\s*(javascript|data:text\/html)/i', $at->value) === 1)) {
                return false;
            }
        }
    }

    return true;
}

function mediana(array $v): float
{
    sort($v);
    $n = count($v);

    return $n % 2 === 1 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/** Dispara N requisicoes em processos separados com largada comum. @return list<array<string,mixed>> */
function simultaneas(array $jobs, float $atrasoSeg = 1.2): array
{
    $t0 = microtime(true) + $atrasoSeg;
    $hs = [];
    foreach ($jobs as $o) {
        $cmd = [PHP_BINARY, __FILE__, '--worker', base64_encode((string) json_encode(['t0' => $t0, 'o' => $o]))];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        $hs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($hs as [$p, $pipes]) {
        $txt = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
        $d = json_decode((string) base64_decode($txt), true);
        $out[] = is_array($d) ? $d : ['status' => 0, 'cab' => [], 'corpo' => '', 'sid' => null];
    }

    return $out;
}

function estadoUsuarios(PDO $pdo): string
{
    return md5(json_encode(gtLinhas($pdo, 'SELECT id_usuario, login, nome, perfil, ativo, senha_hash, deve_trocar_senha FROM tb_gestao_usuario ORDER BY id_usuario')));
}

function ativoDe(PDO $pdo, string $login): int
{
    return (int) gtEscalar($pdo, 'SELECT ativo FROM tb_gestao_usuario WHERE login = :l', ['l' => $login]);
}

function idDe(PDO $pdo, string $login): int
{
    return (int) gtEscalar($pdo, 'SELECT id_usuario FROM tb_gestao_usuario WHERE login = :l', ['l' => $login]);
}

function restaurarAdmins(PDO $pdo): void
{
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 1, perfil = 'admin' WHERE login IN ('ana.admin','beto.admin')");
}

try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Admin');
    gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    gtSemear($pdo, 'davi.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Davi Usuario');
    gtSemear($pdo, 'inativo.pessoa', 'usuario', GT_SENHA_BOA, false, false, 'Inativo Pessoa');
    $contaUsuarios = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_usuario');
    $tokenGet = G(['arquivo' => 'login.php', 'ip' => ipNovo()]);
    $TK = (string) gtTokenLogin($tokenGet);

    // =====================================================================
    // 1. Login hostil (SQLi, Unicode, NUL, tamanhos)
    // =====================================================================
    $antes = estadoUsuarios($pdo);
    $nUsuarios = $contaUsuarios();
    $hostis = [
        'SQLi classico no login' => ["' OR '1'='1", "' OR '1'='1"],
        'SQLi com comentario' => ["ana.admin'--", GT_SENHA_BOA],
        'SQLi em UNION' => ["x' UNION SELECT 1,2,3,4-- ", GT_SENHA_BOA],
        'SQLi stacked' => ["ana.admin'; DROP TABLE tb_gestao_usuario;--", GT_SENHA_BOA],
        'NUL no fim do login' => ["ana.admin\0", GT_SENHA_BOA],
        'NUL no meio do login' => ["ana.admin\0x", GT_SENHA_BOA],
        'quebra de linha no fim' => ["ana.admin\n", GT_SENHA_BOA],
        'CRLF no fim' => ["ana.admin\r\n", GT_SENHA_BOA],
        'acento (a com agudo)' => ["\u{00E1}na.admin", GT_SENHA_BOA],
        'fullwidth' => ["\u{FF41}\u{FF4E}\u{FF41}.admin", GT_SENHA_BOA],
        'K kelvin (casefold)' => ["\u{212A}ana.admin", GT_SENHA_BOA],
        'login com 61 caracteres' => [str_repeat('a', 30) . '.' . str_repeat('b', 30), GT_SENHA_BOA],
        'login de 10000 caracteres' => [str_repeat('a', 10000), GT_SENHA_BOA],
        'login so ponto' => ['.', GT_SENHA_BOA],
        'senha > 72 bytes (errada)' => ['ana.admin', GT_SENHA_BOA . str_repeat('x', 100)],
        'senha 2000 bytes' => ['ana.admin', str_repeat('A', 2000)],
        'senha certa + NUL + lixo' => ['ana.admin', GT_SENHA_BOA . "\0lixo"],
        'senha vazia' => ['ana.admin', ''],
        'senha so NULs' => ['ana.admin', str_repeat("\0", 50)],
    ];
    $i = 0;
    foreach ($hostis as $rotulo => [$l, $s]) {
        $ip = ipNovo();
        $r = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => $l, 'senha' => $s, 'token_login' => $TK], 'ip' => $ip]);
        afirmar("login hostil [$rotulo]: 401 generico, sem cookie, sem 500, sem eco da senha", $r['status'] === 401 && gtSid($r) === null && str_contains($r['corpo'], 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.') && !str_contains($r['corpo'], 'SQLSTATE') && ($s === '' || !str_contains($r['corpo'], substr($s, 0, 20)) || $s === GT_SENHA_BOA));
    }
    afirmar('login hostil: nenhum usuario criado/alterado, tabela intacta (SQLi stacked nao rodou)', estadoUsuarios($pdo) === $antes && $contaUsuarios() === $nUsuarios);
    $rn = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => "  ANA.ADMIN\t", 'senha' => GT_SENHA_BOA, 'token_login' => $TK], 'ip' => ipNovo()]);
    afirmar('login: espacos/tab nas pontas e MAIUSCULAS sao normalizados (aceito, comportamento documentado)', $rn['status'] === 302 && gtSid($rn) !== null);
    // senha com NUL + lixo, depois da senha certa: com argon2id nunca equivale a senha certa
    $rNul = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'ana.admin', 'senha' => GT_SENHA_BOA . "\0", 'token_login' => $TK], 'ip' => ipNovo()]);
    afirmar('login: senha certa + NUL NAO e equivalente a senha certa (hash argon2id verifica o comprimento inteiro)', $rNul['status'] === 401 && PHP_VERSION_ID >= 70300);
    foreach (['login[]' => 'login[]=a&senha[]=b&token_login[]=c', 'token array' => 'login=ana.admin&senha=x&token_login[]=c'] as $rot => $corpo) {
        $r = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'corpo' => $corpo, 'ip' => ipNovo()]);
        afirmar("login: campo em array ($rot) => 400/401 sem erro 500", in_array($r['status'], [400, 401], true) && gtSid($r) === null);
    }
    $rBig = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'corpo' => 'login=ana.admin&senha=' . str_repeat('A', 200000) . '&token_login=' . $TK, 'ip' => ipNovo()]);
    afirmar('login: corpo de 200 KB nao quebra (401 ou 400/413), sem 500', in_array($rBig['status'], [400, 401, 413], true));

    // =====================================================================
    // 2. CSRF
    // =====================================================================
    $adm = entrar('ana.admin', GT_SENHA_BOA);
    $adm2 = entrar('beto.admin', GT_SENHA_BOA);
    afirmar('csrf: admin logado com token de 64 hex (preparacao)', $adm['sid'] !== null && preg_match('/\A[a-f0-9]{64}\z/', (string) $adm['csrf']) === 1 && $adm['csrf'] !== $adm2['csrf']);
    $alvoDes = ['acao' => 'desativar', 'id_usuario' => (string) idDe($pdo, 'carla.usuario')];
    $ativoOk = static fn (): bool => ativoDe($pdo, 'carla.usuario') === 1;
    $tentativas = [
        'sem csrf' => pedir($adm, 'usuarios.php', 'POST', $alvoDes, ['semCsrf' => true]),
        'csrf vazio' => pedir($adm, 'usuarios.php', 'POST', $alvoDes + ['csrf_token' => '']),
        'csrf de OUTRA sessao (do outro admin)' => pedir($adm, 'usuarios.php', 'POST', $alvoDes + ['csrf_token' => (string) $adm2['csrf']]),
        'csrf truncado' => pedir($adm, 'usuarios.php', 'POST', $alvoDes + ['csrf_token' => substr((string) $adm['csrf'], 0, 63)]),
        'csrf com espaco no fim' => pedir($adm, 'usuarios.php', 'POST', $alvoDes + ['csrf_token' => $adm['csrf'] . ' ']),
        'csrf em MAIUSCULAS' => pedir($adm, 'usuarios.php', 'POST', $alvoDes + ['csrf_token' => strtoupper((string) $adm['csrf'])]),
        'csrf array csrf_token[]' => G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'corpo' => http_build_query($alvoDes) . '&csrf_token[]=' . $adm['csrf'], 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]),
        'duplicado: bom e depois lixo (ultimo vence)' => G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'corpo' => http_build_query($alvoDes) . '&csrf_token=' . $adm['csrf'] . '&csrf_token=lixo', 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]),
        'header X-CSRF-Token ruim + campo bom' => pedir($adm, 'usuarios.php', 'POST', $alvoDes, ['cabecalhos' => ['HTTP_X_CSRF_TOKEN' => 'lixo']]),
        'csrf SO em query string' => G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'query' => ['csrf_token' => $adm['csrf']], 'form' => $alvoDes, 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]),
        'csrf valido sem cookie de sessao' => G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $alvoDes + ['csrf_token' => $adm['csrf']], 'ip' => $adm['ip']]),
    ];
    foreach ($tentativas as $rot => $r) {
        afirmar("CSRF [$rot]: rejeitado (401/403), sem redirect de sucesso e carla segue ATIVA", in_array($r['status'], [401, 403], true) && $ativoOk() && !str_contains((string) loc($r), 'usuario_desativado'));
    }
    $okDup = G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'corpo' => http_build_query(['acao' => 'ativar', 'id_usuario' => $alvoDes['id_usuario']]) . '&csrf_token=lixo&csrf_token=' . $adm['csrf'], 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]);
    afirmar('CSRF: duplicado com o BOM por ultimo e aceito (semantica do PHP: ultimo valor) - controle positivo', $okDup['status'] === 302 && str_contains((string) loc($okDup), 'sem_mudanca'));
    $okHdr = pedir($adm, 'usuarios.php', 'POST', ['acao' => 'ativar', 'id_usuario' => $alvoDes['id_usuario']], ['semCsrf' => true, 'cabecalhos' => ['HTTP_X_CSRF_TOKEN' => $adm['csrf']]]);
    afirmar('CSRF: header X-CSRF-Token correto e aceito - controle positivo', $okHdr['status'] === 302 && str_contains((string) loc($okHdr), 'sem_mudanca'));
    // comparacao ESTRITA: token da sessao numerico-like (0e...) nao pode casar com "0"/"00"
    $pdo->prepare('UPDATE tb_gestao_sessao SET csrf_token = :t WHERE id_sessao = :i')->execute(['t' => '0e' . str_repeat('1', 62), 'i' => hash('sha256', (string) $adm['sid'])]);
    foreach (['0', '00', '0e0', '0e' . str_repeat('2', 62), '0.0'] as $fraco) {
        $r = G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $alvoDes + ['csrf_token' => $fraco], 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]);
        afirmar("CSRF comparacao estrita (token da sessao 0e111...): '" . substr($fraco, 0, 8) . "' => 403 e carla ativa", $r['status'] === 403 && $ativoOk());
    }
    $adm = entrar('ana.admin', GT_SENHA_BOA);  // sessao nova (a anterior ficou com csrf manipulado)

    // =====================================================================
    // 3. Origin / Sec-Fetch-Site
    // =====================================================================
    $host = 'gestao.exemplo.test';
    $origens = [
        'evil.example' => ['https://evil.example', 403],
        'null' => ['null', 403],
        'sufixo (host.evil.com)' => ["https://$host.evil.com", 403],
        'subdominio (x.host)' => ["https://x.$host", 403],
        'esquema http' => ["http://$host", 403],
        'porta extra' => ["https://$host:8443", 403],
        'com barra final' => ["https://$host/", 403],
        'com userinfo' => ["https://user@$host", 403],
        'host com @ e evil' => ["https://$host@evil.example", 403],
        'muito longo' => ['https://' . str_repeat('a', 400) . '.test', 403],
        'valido em maiusculas' => ['HTTPS://GESTAO.EXEMPLO.TEST', 302],
        'valido porta 443 explicita' => ["https://$host:443", 302],
        'valido' => ["https://$host", 302],
    ];
    foreach ($origens as $rot => [$origem, $esperado]) {
        $r = pedir($adm, 'usuarios.php', 'POST', ['acao' => 'ativar', 'id_usuario' => $alvoDes['id_usuario']], ['origin' => $origem]);
        afirmar("Origin [$rot] => $esperado" . ($esperado === 302 ? ' (aceita)' : ' (rejeita)'), $r['status'] === $esperado);
    }
    foreach (['cross-site' => 403, 'same-site' => 403, 'same-origin' => 302, 'none' => 302] as $sfs => $esperado) {
        $r = pedir($adm, 'usuarios.php', 'POST', ['acao' => 'ativar', 'id_usuario' => $alvoDes['id_usuario']], ['origin' => null, 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => $sfs]]);
        afirmar("Sec-Fetch-Site: $sfs (sem Origin) => $esperado", $r['status'] === $esperado);
    }
    $semNada = pedir($adm, 'usuarios.php', 'POST', ['acao' => 'ativar', 'id_usuario' => $alvoDes['id_usuario']], ['origin' => null]);
    afirmar('sem Origin e sem Sec-Fetch-Site: o token CSRF decide (aceita com token valido)', $semNada['status'] === 302);
    $hostRuim = G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'host' => 'gestao.exemplo.test:abc', 'form' => $alvoDes + ['csrf_token' => $adm['csrf']], 'origin' => 'https://gestao.exemplo.test:abc', 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]);
    afirmar('Host com porta invalida => 400 (nunca aceita), carla ativa', $hostRuim['status'] === 400 && $ativoOk());
    afirmar('Origin/CSRF rejeitados nao mudaram estado', $ativoOk());

    // =====================================================================
    // 4. GET que muda estado / metodos inesperados
    // =====================================================================
    $idCarla = (string) idDe($pdo, 'carla.usuario');
    $antes = estadoUsuarios($pdo);
    foreach (['usuarios.php', 'usuario-form.php', 'conta.php', 'index.php'] as $pag) {
        $r = G(['arquivo' => $pag, 'query' => ['acao' => 'desativar', 'id_usuario' => $idCarla, 'id' => $idCarla, 'login' => 'novo.usuario', 'nome' => 'Novo Usuario', 'perfil' => 'admin', 'csrf_token' => (string) $adm['csrf'], 'senha_nova' => 'x'], 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]);
        afirmar("GET $pag com parametros de acao nao muda estado", estadoUsuarios($pdo) === $antes && $r['status'] < 500);
    }
    $rLg = G(['arquivo' => 'logout.php', 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]);
    afirmar('GET logout.php => 405 e a sessao continua valida', $rLg['status'] === 405 && pedir($adm, 'conta.php')['status'] === 200);
    foreach (['PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE', 'CONNECT'] as $m) {
        foreach (['usuarios.php', 'usuario-form.php', 'conta.php', 'login.php', 'logout.php', 'index.php'] as $pag) {
            $r = G(['arquivo' => $pag, 'metodo' => $m, 'form' => $alvoDes + ['csrf_token' => $adm['csrf']], 'cookies' => ['gestao_sid' => $adm['sid']], 'cabecalhos' => ['HTTP_X_CSRF_TOKEN' => (string) $adm['csrf']], 'ip' => $adm['ip']]);
            afirmar("metodo $m em $pag (autenticado, com CSRF) => 405 com Allow, sem mudanca", $r['status'] === 405 && gtCabecalho($r, 'allow') !== null && estadoUsuarios($pdo) === $antes);
        }
    }
    $rPostIdx = G(['arquivo' => 'index.php', 'metodo' => 'POST', 'form' => ['csrf_token' => $adm['csrf']], 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]);
    afirmar('POST index.php => 405 (so GET)', $rPostIdx['status'] === 405);
    $rHead = G(['arquivo' => 'usuarios.php', 'metodo' => 'HEAD', 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]);
    afirmar('HEAD usuarios.php autenticado: tratado como GET (200) sem alterar estado', $rHead['status'] === 200 && estadoUsuarios($pdo) === $antes);
    $rHeadAnon = G(['arquivo' => 'usuarios.php', 'metodo' => 'HEAD', 'ip' => ipNovo()]);
    afirmar('HEAD anonimo: 302 para o login (nada vaza)', $rHeadAnon['status'] === 302 && loc($rHeadAnon) === '/gestao/login.php');

    // =====================================================================
    // 5. RBAC por TODAS as paginas e acoes + IDOR
    // =====================================================================
    $usu = entrar('carla.usuario', GT_SENHA_BOA);
    $esperaGet = [
        'index.php' => 302, 'usuarios.php' => 403, 'usuario-form.php' => 403, 'conta.php' => 200, 'login.php' => 302,
    ];
    foreach ($esperaGet as $pag => $st) {
        $r = pedir($usu, $pag);
        afirmar("RBAC usuario GET $pag => $st", $r['status'] === $st);
    }
    $r = pedir($usu, 'usuario-form.php', 'GET', [], ['query' => ['id' => $alvoDes['id_usuario']]]);
    afirmar('RBAC usuario GET usuario-form.php?id=<outro> => 403 e sem dados do alvo', $r['status'] === 403 && !str_contains($r['corpo'], 'carla.usuario') && !str_contains($r['corpo'], 'ana.admin'));
    $nUsuarios = $contaUsuarios();
    $antes = estadoUsuarios($pdo);
    foreach (['desativar', 'ativar', 'redefinir-senha'] as $ac) {
        $r = pedir($usu, 'usuarios.php', 'POST', ['acao' => $ac, 'id_usuario' => (string) idDe($pdo, 'davi.usuario')]);
        afirmar("RBAC usuario POST usuarios.php acao=$ac => 403", $r['status'] === 403 && estadoUsuarios($pdo) === $antes);
    }
    $r = pedir($usu, 'usuario-form.php', 'POST', ['login' => 'invasor.novo', 'nome' => 'Invasor Novo', 'perfil' => 'admin']);
    afirmar('RBAC usuario criando ADMIN => 403 e nenhum usuario criado', $r['status'] === 403 && $contaUsuarios() === $nUsuarios);
    $r = pedir($usu, 'usuario-form.php', 'POST', ['id_usuario' => (string) idDe($pdo, 'carla.usuario'), 'nome' => 'Carla Usuario', 'perfil' => 'admin']);
    afirmar('RBAC usuario promovendo a si mesmo a admin => 403 e perfil segue usuario', $r['status'] === 403 && (string) gtEscalar($pdo, "SELECT perfil FROM tb_gestao_usuario WHERE login='carla.usuario'") === 'usuario');
    $rj = pedir($usu, 'usuarios.php', 'POST', ['acao' => 'desativar', 'id_usuario' => $idCarla], ['cabecalhos' => ['HTTP_ACCEPT' => 'application/json']]);
    afirmar('RBAC usuario com Accept JSON => 403 em JSON (sem HTML)', $rj['status'] === 403 && str_contains((string) gtCabecalho($rj, 'content-type'), 'application/json') && (json_decode($rj['corpo'], true)['sucesso'] ?? null) === false);
    $ra = G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $alvoDes, 'ip' => ipNovo()]);
    afirmar('anonimo POST usuarios.php => 401 (nao 302/200)', $ra['status'] === 401);
    $raj = G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $alvoDes, 'cabecalhos' => ['HTTP_ACCEPT' => 'application/json'], 'ip' => ipNovo()]);
    afirmar('anonimo POST JSON => 401 em JSON', $raj['status'] === 401 && (json_decode($raj['corpo'], true)['sucesso'] ?? null) === false);
    $raxhr = G(['arquivo' => 'usuarios.php', 'cabecalhos' => ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], 'ip' => ipNovo()]);
    afirmar('anonimo GET via XHR => 401 JSON (nao redireciona HTML)', $raxhr['status'] === 401);

    // perfil e ativo lidos do BANCO a cada requisicao
    $beto = entrar('beto.admin', GT_SENHA_BOA);
    afirmar('RBAC beto admin acessa usuarios.php (antes de ser rebaixado)', pedir($beto, 'usuarios.php')['status'] === 200);
    $r = pedir($adm, 'usuario-form.php', 'POST', ['id_usuario' => (string) idDe($pdo, 'beto.admin'), 'nome' => 'Beto Admin', 'perfil' => 'usuario']);
    afirmar('admin rebaixa outro admin (tem 2 admins): 302 usuario_editado', $r['status'] === 302 && str_contains((string) loc($r), 'usuario_editado'));
    afirmar('rebaixado: a MESMA sessao perde acesso na hora (perfil vem do banco): usuarios.php 403, POST 403', pedir($beto, 'usuarios.php')['status'] === 403 && pedir($beto, 'usuarios.php', 'POST', ['acao' => 'desativar', 'id_usuario' => $idCarla])['status'] === 403 && $ativoOk());
    restaurarAdmins($pdo);
    afirmar('promovido de volta pelo banco: mesma sessao volta a ter acesso (perfil nao e snapshot)', pedir($beto, 'usuarios.php')['status'] === 200);
    // desativado por UPDATE direto (sem revogar sessao): ativo lido do banco
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 0 WHERE login = 'beto.admin'");
    $r = pedir($beto, 'usuarios.php');
    afirmar('desativado direto no banco (sessao ainda existe): proxima requisicao => 302 login e sessao apagada', $r['status'] === 302 && loc($r) === '/gestao/login.php' && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => hash('sha256', (string) $beto['sid'])]) === 0);
    restaurarAdmins($pdo);

    // admin agindo sobre si mesmo
    $idAna = (string) idDe($pdo, 'ana.admin');
    foreach (['desativar' => 'proprio_ativo', 'ativar' => 'proprio_ativo', 'redefinir-senha' => 'propria_senha'] as $ac => $codigo) {
        $hashAntes = (string) gtEscalar($pdo, "SELECT senha_hash FROM tb_gestao_usuario WHERE login='ana.admin'");
        $r = pedir($adm, 'usuarios.php', 'POST', ['acao' => $ac, 'id_usuario' => $idAna, 'versao_senha' => gtVersaoSenha($pdo, 'ana.admin')]);
        afirmar("admin $ac a SI MESMO => recusa ($codigo), segue ativo e senha intacta", $r['status'] === 302 && str_contains((string) loc($r), $codigo) && ativoDe($pdo, 'ana.admin') === 1 && (string) gtEscalar($pdo, "SELECT senha_hash FROM tb_gestao_usuario WHERE login='ana.admin'") === $hashAntes);
    }
    $r = pedir($adm, 'usuario-form.php', 'POST', ['id_usuario' => $idAna, 'nome' => 'Ana Admin', 'perfil' => 'usuario']);
    afirmar('admin rebaixando a SI MESMO => recusa proprio_perfil', str_contains((string) loc($r), 'proprio_perfil') && (string) gtEscalar($pdo, "SELECT perfil FROM tb_gestao_usuario WHERE login='ana.admin'") === 'admin');
    // ultimo admin (via Rn, ator = o unico admin ativo; alvo = ele mesmo e outro admin INATIVO)
    $rnU = new UsuarioGestaoRn($pdo);
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 0 WHERE login = 'beto.admin'");
    $rU = $rnU->definirAtivo((int) $idAna, (int) $idAna, false, '198.18.9.9');
    afirmar('ultimo admin ativo nao se desativa (Rn)', $rU['ok'] === false && ativoDe($pdo, 'ana.admin') === 1);
    $rU = $rnU->editar((int) $idAna, (int) $idAna, 'Ana Admin', 'usuario', '198.18.9.9');
    afirmar('ultimo admin ativo nao se rebaixa (Rn)', $rU['ok'] === false && (string) gtEscalar($pdo, "SELECT perfil FROM tb_gestao_usuario WHERE login='ana.admin'") === 'admin');
    $rU = $rnU->definirAtivo((int) idDe($pdo, 'beto.admin'), (int) $idAna, false, '198.18.9.9');
    afirmar('admin INATIVO nao consegue agir (ator reconferido sob lock): sem_permissao e ana segue ativa', $rU['ok'] === false && ($rU['codigo'] ?? '') === 'sem_permissao' && ativoDe($pdo, 'ana.admin') === 1);
    restaurarAdmins($pdo);

    // IDOR em id_usuario
    $antes = estadoUsuarios($pdo);
    $idsHostis = ['999999', '0', '-1', '01', '1e3', '1 OR 1=1', "1; DROP TABLE tb_gestao_usuario", '99999999999', "\u{0663}", '', ' 3', '3 ', '0x3', '3.0'];
    foreach ($idsHostis as $idh) {
        foreach (['desativar', 'ativar', 'redefinir-senha'] as $ac) {
            $r = pedir($adm, 'usuarios.php', 'POST', ['acao' => $ac, 'id_usuario' => $idh]);
            if (!($r['status'] === 400 || ($r['status'] === 302 && str_contains((string) loc($r), 'nao_encontrado')) || ($r['status'] === 302 && str_contains((string) loc($r), 'sem_mudanca')))) {
                afirmar("IDOR id_usuario='" . substr($idh, 0, 20) . "' acao=$ac: resposta inesperada " . $r['status'] . ' ' . (string) loc($r), false);
            }
        }
        $r = pedir($adm, 'usuario-form.php', 'GET', [], ['query' => ['id' => $idh]]);
        if ($idh !== '' && !($r['status'] === 302 && str_contains((string) loc($r), 'nao_encontrado'))) {
            afirmar("IDOR usuario-form.php?id='" . substr($idh, 0, 20) . "' deveria ser nao_encontrado, veio " . $r['status'], false);
        }
    }
    $r = G(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'corpo' => 'acao=desativar&id_usuario[]=' . $idCarla . '&csrf_token=' . $adm['csrf'], 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]);
    afirmar('IDOR: id_usuario[] (array) => 400 e carla ativa', $r['status'] === 400 && $ativoOk());
    $depois = estadoUsuarios($pdo);
    afirmar('IDOR: estado dos usuarios identico apos todos os ids hostis', $depois === $antes);
    // usuario nao altera senha de OUTRO por id_usuario em conta.php
    $hAna = (string) gtEscalar($pdo, "SELECT senha_hash FROM tb_gestao_usuario WHERE login='ana.admin'");
    $r = pedir($usu, 'conta.php', 'POST', ['id_usuario' => $idAna, 'login' => 'ana.admin', 'senha_atual' => GT_SENHA_BOA, 'senha_nova' => SENT_NOVA, 'senha_confirmacao' => SENT_NOVA]);
    afirmar('IDOR: conta.php com id_usuario=<admin> troca a senha SO da propria sessao (ana intacta, carla alterada)', (string) gtEscalar($pdo, "SELECT senha_hash FROM tb_gestao_usuario WHERE login='ana.admin'") === $hAna && SenhaPolitica::verificar(SENT_NOVA, (string) gtEscalar($pdo, "SELECT senha_hash FROM tb_gestao_usuario WHERE login='carla.usuario'")));
    // devolve a senha original da carla (para os proximos testes)
    $pdo->prepare("UPDATE tb_gestao_usuario SET senha_hash = :h, deve_trocar_senha = 0 WHERE login = 'carla.usuario'")->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA)]);

    // =====================================================================
    // 6. Sessao
    // =====================================================================
    $s1 = entrar('carla.usuario', GT_SENHA_BOA);
    $cookie = $s1['resp']['setCookies'][0] ?? ['atributos' => []];
    $a = $cookie['atributos'];
    afirmar('cookie: HttpOnly+Secure+SameSite=Strict+Path=/gestao, sem Domain/Expires/Max-Age', array_key_exists('httponly', $a) && array_key_exists('secure', $a) && ($a['samesite'] ?? '') === 'Strict' && ($a['path'] ?? '') === '/gestao' && !array_key_exists('domain', $a) && !array_key_exists('expires', $a) && !array_key_exists('max-age', $a));
    $rPag = pedir($s1, 'conta.php');
    afirmar('cookie: paginas autenticadas NAO reemitem Set-Cookie (id nao rotaciona sem motivo)', $rPag['setCookies'] === []);
    // logout
    $r = pedir($s1, 'logout.php', 'POST');
    $cl = $r['setCookies'][0] ?? null;
    afirmar('logout: 302 /gestao/login.php e cookie limpo com Path=/gestao, expirado', $r['status'] === 302 && loc($r) === '/gestao/login.php' && $cl !== null && ($cl['atributos']['path'] ?? '') === '/gestao' && (str_contains(strtolower($cl['cru']), 'expires=thu, 01-jan-1970') || str_contains(strtolower($cl['cru']), 'max-age=0') || str_contains(strtolower($cl['cru']), '1970')));
    $r1 = pedir($s1, 'conta.php');
    $r2 = pedir($s1, 'usuarios.php', 'POST', ['acao' => 'ativar', 'id_usuario' => $idCarla]);
    afirmar('reuso do cookie APOS logout: GET => 302 login; POST => 401; linha da sessao apagada', $r1['status'] === 302 && loc($r1) === '/gestao/login.php' && $r2['status'] === 401 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => hash('sha256', (string) $s1['sid'])]) === 0);
    $rLo = G(['arquivo' => 'logout.php', 'metodo' => 'POST', 'form' => ['csrf_token' => 'x'], 'ip' => ipNovo()]);
    afirmar('logout sem sessao => redireciona ao login (sem 500), cookie limpo', $rLo['status'] === 302 && loc($rLo) === '/gestao/login.php');

    // desativacao pela UI revoga sessoes; reativar nao ressuscita
    $sc = entrar('davi.usuario', GT_SENHA_BOA);
    $sc2 = entrar('davi.usuario', GT_SENHA_BOA, null, 'Outro-UA/2.0');
    $r = pedir($adm, 'usuarios.php', 'POST', ['acao' => 'desativar', 'id_usuario' => (string) idDe($pdo, 'davi.usuario')]);
    afirmar('desativar via UI: 302 usuario_desativado', $r['status'] === 302 && str_contains((string) loc($r), 'usuario_desativado'));
    afirmar('desativado: AMBAS as sessoes do usuario morrem (302 login)', pedir($sc, 'conta.php')['status'] === 302 && pedir($sc2, 'conta.php')['status'] === 302);
    $rl = entrar('davi.usuario', GT_SENHA_BOA);
    afirmar('desativado: login com a senha certa => recusado (mesma mensagem), sem cookie', $rl['sid'] === null && $rl['resp']['status'] === 401);
    pedir($adm, 'usuarios.php', 'POST', ['acao' => 'ativar', 'id_usuario' => (string) idDe($pdo, 'davi.usuario')]);
    afirmar('reativado: o cookie ANTIGO continua morto (sessao foi apagada, nao suspensa)', pedir($sc, 'conta.php')['status'] === 302);
    // troca de senha propria: revoga as outras, mantem a nova; id novo
    $sa = entrar('davi.usuario', GT_SENHA_BOA);
    $sb = entrar('davi.usuario', GT_SENHA_BOA, null, 'Aparelho-B/1.0');
    $rt = pedir($sa, 'conta.php', 'POST', ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => SENT_NOVA, 'senha_confirmacao' => SENT_NOVA]);
    $novoSid = gtSid($rt);
    afirmar('troca de senha: 302 com cookie NOVO (id diferente do anterior)', $rt['status'] === 302 && $novoSid !== null && $novoSid !== $sa['sid']);
    afirmar('troca de senha: cookie ANTIGO da mesma sessao morre', pedir($sa, 'conta.php')['status'] === 302);
    afirmar('troca de senha: sessao de OUTRO aparelho morre', pedir($sb, 'conta.php')['status'] === 302);
    afirmar('troca de senha: o cookie novo funciona (200 em conta.php)', G(['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => $novoSid], 'ip' => $sa['ip'], 'ua' => $sa['ua']])['status'] === 200);
    afirmar('troca de senha: senha antiga nao loga mais; a nova loga', entrar('davi.usuario', GT_SENHA_BOA)['sid'] === null && entrar('davi.usuario', SENT_NOVA)['sid'] !== null);
    // redefinicao pelo admin tambem revoga
    $sd = entrar('davi.usuario', SENT_NOVA);
    $rr = pedir($adm, 'usuarios.php', 'POST', ['acao' => 'redefinir-senha', 'id_usuario' => (string) idDe($pdo, 'davi.usuario'), 'versao_senha' => gtVersaoSenha($pdo, 'davi.usuario')]);
    afirmar('redefinicao pelo admin: sessao viva do alvo morre', $rr['status'] === 200 && pedir($sd, 'conta.php')['status'] === 302);
    preg_match('/[A-HJ-NP-Za-km-z2-9]{4}(?:-[A-HJ-NP-Za-km-z2-9]{4}){3}/', $rr['corpo'], $mt);
    $tempDavi = $mt[0] ?? '';
    afirmar('redefinicao: senha temporaria exibida na resposta POST com Cache-Control no-store e SEM Location/cookie', $tempDavi !== '' && str_contains((string) gtCabecalho($rr, 'cache-control'), 'no-store') && loc($rr) === null && $rr['setCookies'] === []);
    afirmar('redefinicao: a temporaria funciona UMA vez como login e obriga troca (302 /gestao/conta.php)', loc(entrar('davi.usuario', $tempDavi)['resp']) === '/gestao/conta.php');
    // fixacao de sessao
    $fixo = bin2hex(random_bytes(32));
    $g = G(['arquivo' => 'login.php', 'cookies' => ['gestao_sid' => $fixo], 'ip' => ipNovo()]);
    $tkf = (string) gtTokenLogin($g);
    $rf = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'cookies' => ['gestao_sid' => $fixo], 'form' => ['login' => 'carla.usuario', 'senha' => GT_SENHA_BOA, 'token_login' => $tkf], 'ip' => ipNovo()]);
    $sidF = gtSid($rf);
    afirmar('fixacao: login com cookie plantado pelo atacante gera sid NOVO (diferente do plantado)', $rf['status'] === 302 && $sidF !== null && $sidF !== $fixo);
    afirmar('fixacao: o sid plantado NAO vale depois do login', G(['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => $fixo], 'ip' => ipNovo()])['status'] === 302);
    afirmar('fixacao: o sid plantado nunca foi gravado (sha256 ausente)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => hash('sha256', $fixo)]) === 0);
    // cookies malformados
    foreach (['maiusculas' => strtoupper(bin2hex(random_bytes(32))), '63 hex' => str_repeat('a', 63), '65 hex' => str_repeat('a', 65), 'nao hex' => str_repeat('g', 64), 'SQLi' => "' OR 1=1 --", 'vazio' => ''] as $rot => $v) {
        $r = G(['arquivo' => 'usuarios.php', 'cookies' => ['gestao_sid' => $v], 'ip' => ipNovo()]);
        afirmar("cookie malformado [$rot] => 302 login, sem 500", $r['status'] === 302 && loc($r) === '/gestao/login.php');
    }
    $rArr = G(['arquivo' => 'usuarios.php', 'cookies' => ['gestao_sid[]' => 'a'], 'ip' => ipNovo()]);
    afirmar('cookie em array (gestao_sid[]=a) => 302 login, sem 500', $rArr['status'] === 302);
    // expiracao
    $sx = entrar('carla.usuario', GT_SENHA_BOA);
    $hid = hash('sha256', (string) $sx['sid']);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 29 MINUTE) WHERE id_sessao = :i')->execute(['i' => $hid]);
    afirmar('inatividade: 29 min => sessao ainda valida (200)', pedir($sx, 'conta.php')['status'] === 200);
    $ult = (int) gtEscalar($pdo, 'SELECT TIMESTAMPDIFF(SECOND, ultimo_acesso_em, NOW()) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $hid]);
    afirmar('inatividade: o acesso RENOVA ultimo_acesso_em (janela deslizante)', $ult < 5);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE id_sessao = :i')->execute(['i' => $hid]);
    $r = pedir($sx, 'conta.php');
    afirmar('inatividade: 31 min => 302 login e linha apagada', $r['status'] === 302 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $hid]) === 0);
    $sx = entrar('carla.usuario', GT_SENHA_BOA);
    $hid = hash('sha256', (string) $sx['sid']);
    $minAbs = (int) gtEscalar($pdo, 'SELECT TIMESTAMPDIFF(MINUTE, NOW(), expira_em) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $hid]);
    afirmar('teto absoluto: expira_em ~ 12 h (719..720 min) a partir do login', $minAbs >= 719 && $minAbs <= 720);
    $pdo->prepare('UPDATE tb_gestao_sessao SET expira_em = DATE_SUB(NOW(), INTERVAL 1 SECOND), ultimo_acesso_em = NOW() WHERE id_sessao = :i')->execute(['i' => $hid]);
    afirmar('teto absoluto vencido (mesmo com atividade recente) => 302 login', pedir($sx, 'conta.php')['status'] === 302);
    $sx = entrar('carla.usuario', GT_SENHA_BOA);
    $pdo->prepare('UPDATE tb_gestao_sessao SET criado_em = DATE_SUB(NOW(), INTERVAL 13 HOUR) WHERE id_sessao = :i')->execute(['i' => hash('sha256', (string) $sx['sid'])]);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = NOW() WHERE id_sessao = :i')->execute(['i' => hash('sha256', (string) $sx['sid'])]);
    afirmar('NOTA: a sessao e limitada por expira_em (nao por criado_em); manipular criado_em nao estende nem encerra', pedir($sx, 'conta.php')['status'] === 200);
    // UA
    $su = entrar('carla.usuario', GT_SENHA_BOA, null, 'UA-Original/1.0');
    $rUa = G(['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => $su['sid']], 'ua' => 'UA-Roubado/9.9', 'ip' => $su['ip']]);
    afirmar('User-Agent diferente derruba a sessao (302) e apaga a linha', $rUa['status'] === 302 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => hash('sha256', (string) $su['sid'])]) === 0);
    afirmar('User-Agent: o dono (UA original) tambem perde a sessao apos o uso indevido', pedir($su, 'conta.php')['status'] === 302);
    // troca obrigatoria ativada com sessao viva
    $st = entrar('carla.usuario', GT_SENHA_BOA);
    $pdo->exec("UPDATE tb_gestao_usuario SET deve_trocar_senha = 1 WHERE login = 'carla.usuario'");
    $r = pedir($st, 'index.php');
    afirmar('troca obrigatoria marcada com sessao viva: GET index => 302 /gestao/conta.php', $r['status'] === 302 && loc($r) === '/gestao/conta.php');
    $pdo->exec("UPDATE tb_gestao_usuario SET deve_trocar_senha = 0 WHERE login = 'carla.usuario'");

    // =====================================================================
    // 7. Rate limit de login (conta e IP) e IP do cliente
    // =====================================================================
    $ipX = '203.0.113.77';
    $codigos = [];
    for ($i = 1; $i <= 11; $i++) {
        $g = G(['arquivo' => 'login.php', 'ip' => $ipX]);
        $r = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'fantasma.' . chr(96 + $i) . chr(96 + $i), 'senha' => 'x', 'token_login' => (string) gtTokenLogin($g)], 'ip' => $ipX]);
        $codigos[] = $r['status'];
    }
    afirmar('limite por IP: 10 falhas 401 e a 11a tentativa => 429', array_slice($codigos, 0, 10) === array_fill(0, 10, 401) && $codigos[10] === 429);
    $r429 = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'ana.admin', 'senha' => GT_SENHA_BOA, 'token_login' => $TK], 'ip' => $ipX]);
    $ra = (int) gtCabecalho($r429, 'retry-after');
    afirmar('limite por IP: credenciais CERTAS tambem => 429 com Retry-After 1..900, sem cookie', $r429['status'] === 429 && $ra >= 1 && $ra <= 900 && gtSid($r429) === null && temCab6($r429));
    afirmar('limite por IP: outro IP nao e afetado', entrar('ana.admin', GT_SENHA_BOA)['sid'] !== null);
    // CF-Connecting-IP forjado por IP nao Cloudflare
    $ipAtac = '203.0.113.88';
    $cods = [];
    for ($i = 1; $i <= 12; $i++) {
        $r = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'fantasma.zz', 'senha' => 'x', 'token_login' => $TK], 'ip' => $ipAtac, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.' . $i, 'HTTP_X_FORWARDED_FOR' => '192.0.2.' . $i . ', 10.0.0.1']]);
        $cods[] = $r['status'];
    }
    afirmar('CF-Connecting-IP/X-Forwarded-For FORJADOS por IP nao-Cloudflare NAO escapam do limite (11a e 12a => 429)', $cods[10] === 429 && $cods[11] === 429);
    // de IP Cloudflare: o IP do cabecalho e que conta
    $ipCf = '104.16.5.5';
    $cods = [];
    for ($i = 1; $i <= 12; $i++) {
        $r = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'fantasma.zz', 'senha' => 'x', 'token_login' => $TK], 'ip' => $ipCf, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.201']]);
        $cods[] = $r['status'];
    }
    afirmar('IP Cloudflare + CF-Connecting-IP fixo: o IP do VISITANTE e limitado (11a => 429)', $cods[10] === 429);
    $rOutro = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'fantasma.zz', 'senha' => 'x', 'token_login' => $TK], 'ip' => $ipCf, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.202']]);
    afirmar('IP Cloudflare + OUTRO visitante (CF-Connecting-IP diferente) NAO herda o bloqueio (401)', $rOutro['status'] === 401);
    $rInv = G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'fantasma.zz', 'senha' => 'x', 'token_login' => $TK], 'ip' => $ipCf, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => 'nao-e-ip']]);
    afirmar('IP Cloudflare + CF-Connecting-IP invalido: cai no IP do Cloudflare (ja bloqueado? nao: contador do proprio CF) sem 500', in_array($rInv['status'], [401, 429], true));
    // unitarios das faixas do Cloudflare (bordas)
    $bordas = [
        '104.16.0.0' => true, '104.23.255.255' => true, '104.24.0.0' => true, '104.27.255.255' => true, '104.15.255.255' => false, '104.28.0.0' => false,
        '172.64.0.0' => true, '172.71.255.255' => true, '172.72.0.0' => false, '172.63.255.255' => false,
        '131.0.72.0' => true, '131.0.75.255' => true, '131.0.76.0' => false, '131.0.71.255' => false,
        '162.158.0.0' => true, '162.159.255.255' => true, '162.160.0.0' => false,
        '198.41.128.0' => true, '198.41.255.255' => true, '198.42.0.0' => false, '198.41.127.255' => false,
        '173.245.48.0' => true, '173.245.63.255' => true, '173.245.64.0' => false,
        '203.0.113.5' => false, '127.0.0.1' => false, '10.0.0.1' => false,
        '2606:4700::1' => true, '2606:4700:ffff::1' => true, '2606:4701::1' => false, '2a06:98c0::1' => true, '2a06:98c7::1' => true, '2a06:98c8::1' => false,
        '::ffff:104.16.0.1' => true, '::1' => false, '2001:db8::1' => false,
    ];
    $falhasBorda = [];
    foreach ($bordas as $ipb => $esp) {
        if (IpCliente::pertenceAoCloudflare($ipb) !== $esp) {
            $falhasBorda[] = $ipb;
        }
    }
    afirmar('faixas Cloudflare: ' . count($bordas) . ' bordas (dentro/fora, v4/v6/IPv4-mapeado) corretas' . ($falhasBorda === [] ? '' : ' - ERROS: ' . implode(',', $falhasBorda)), $falhasBorda === []);
    afirmar('IpCliente: X-Forwarded-For NUNCA lido, REMOTE_ADDR invalido => 0.0.0.0', IpCliente::obter(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']) === '203.0.113.5' && IpCliente::obter(['REMOTE_ADDR' => 'lixo']) === '0.0.0.0' && IpCliente::obter([]) === '0.0.0.0');
    // por conta: 5 falhas bloqueiam 15 min, mesmo de IPs diferentes
    gtSemear($pdo, 'lucia.bloqueio', 'usuario', GT_SENHA_BOA, false, true, 'Lucia Bloqueio');
    $statusConta = [];
    for ($i = 1; $i <= 5; $i++) {
        $statusConta[] = entrar('lucia.bloqueio', 'errada-demais-' . $i)['resp']['status'];
    }
    $bloq = (int) gtEscalar($pdo, "SELECT bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW() FROM tb_gestao_usuario WHERE login='lucia.bloqueio'");
    afirmar('limite por CONTA: 5 falhas (de 5 IPs diferentes) => conta bloqueada ate ~15 min', $statusConta === array_fill(0, 5, 401) && $bloq === 1 && (int) gtEscalar($pdo, "SELECT TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_ate) FROM tb_gestao_usuario WHERE login='lucia.bloqueio'") >= 14);
    $rb = entrar('lucia.bloqueio', GT_SENHA_BOA);
    $semBloq = entrar('carla.usuario', GT_SENHA_BOA);
    afirmar('conta bloqueada: a senha CERTA e recusada com a MESMA mensagem generica (nao revela bloqueio), sem cookie', $rb['sid'] === null && $rb['resp']['status'] === 401 && str_contains($rb['resp']['corpo'], 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.') && !preg_match('/bloquead/i', $rb['resp']['corpo']) && $semBloq['sid'] !== null);
    $pdo->exec("UPDATE tb_gestao_usuario SET bloqueado_ate = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE login = 'lucia.bloqueio'");
    $rd = entrar('lucia.bloqueio', GT_SENHA_BOA);
    afirmar('conta desbloqueada apos o prazo: login ok e contador zerado', $rd['sid'] !== null && (int) gtEscalar($pdo, "SELECT tentativas_falhas FROM tb_gestao_usuario WHERE login='lucia.bloqueio'") === 0);
    // senha errada na troca de senha tambem conta como falha
    for ($i = 1; $i <= 5; $i++) {
        pedir($rd, 'conta.php', 'POST', ['senha_atual' => 'errada-troca-' . $i, 'senha_nova' => SENT_NOVA2, 'senha_confirmacao' => SENT_NOVA2]);
    }
    afirmar('troca de senha: 5 senhas atuais erradas => conta bloqueada E sessoes revogadas', (int) gtEscalar($pdo, "SELECT bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW() FROM tb_gestao_usuario WHERE login='lucia.bloqueio'") === 1 && pedir($rd, 'conta.php')['status'] === 302);

    // =====================================================================
    // 8. Timing de login inexistente x senha errada x inativo
    // =====================================================================
    $hashReal = (string) gtEscalar($pdo, "SELECT senha_hash FROM tb_gestao_usuario WHERE login='carla.usuario'");
    $custo = [];
    for ($i = 0; $i < 7; $i++) {
        $t = hrtime(true);
        SenhaPolitica::verificar('Tentativa-Errada-Qualquer', $hashReal);
        $custo[] = (hrtime(true) - $t) / 1e6;
    }
    $H = mediana($custo);
    $am = ['A' => [], 'B' => [], 'C' => []];
    $logins = ['A' => 'fantasma.nunca', 'B' => 'carla.usuario', 'C' => 'inativo.pessoa'];
    for ($i = 0; $i < 30; $i++) {
        $pdo->exec("UPDATE tb_gestao_usuario SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE login = 'carla.usuario'");
        foreach ($logins as $k => $lg) {
            $t = hrtime(true);
            G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => $lg, 'senha' => 'Senha-Errada-Timing-' . $i, 'token_login' => $TK], 'ip' => ipNovo()]);
            $am[$k][] = (hrtime(true) - $t) / 1e6;
        }
    }
    [$amA, $amB, $amC] = [$am['A'], $am['B'], $am['C']];
    $mA = mediana($amA);
    $mB = mediana($amB);
    $mC = mediana($amC);
    $tol = 0.5 * $H;
    echo sprintf("INFO timing: custo de 1 password_verify=%.1f ms; medianas (30 amostras): inexistente=%.1f ms, senha errada=%.1f ms, inativo=%.1f ms; tolerancia=%.1f ms (metade do custo de 1 verificacao)\n", $H, $mA, $mB, $mC, $tol);
    afirmar('timing: |mediana(inexistente) - mediana(senha errada)| < metade do custo de 1 verificacao (dummy realmente gasta o hash)', abs($mA - $mB) < $tol);
    afirmar('timing: |mediana(inativo) - mediana(senha errada)| < metade do custo de 1 verificacao', abs($mC - $mB) < $tol);

    // =====================================================================
    // 9. Segredos: senha temporaria, auditoria, logs
    // =====================================================================
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 1, bloqueado_ate = NULL, tentativas_falhas = 0 WHERE login IN ('carla.usuario','davi.usuario')");
    $adm = entrar('ana.admin', GT_SENHA_BOA);
    $rc = pedir($adm, 'usuario-form.php', 'POST', ['login' => 'nova.pessoa', 'nome' => 'Nome Sentinela Xq', 'perfil' => 'usuario']);
    preg_match('/[A-HJ-NP-Za-km-z2-9]{4}(?:-[A-HJ-NP-Za-km-z2-9]{4}){3}/', $rc['corpo'], $mt);
    $tempNova = $mt[0] ?? '';
    afirmar('criar usuario: 200 com senha temporaria (19 chars) UMA vez, Cache-Control no-store, sem Location', $rc['status'] === 200 && strlen($tempNova) === 19 && str_contains((string) gtCabecalho($rc, 'cache-control'), 'no-store') && loc($rc) === null && $rc['setCookies'] === []);
    $reload = pedir($adm, 'usuarios.php');
    $reload2 = pedir($adm, 'usuario-form.php', 'GET', [], ['query' => ['id' => (string) idDe($pdo, 'nova.pessoa')]]);
    afirmar('senha temporaria NAO reaparece em GET posterior (lista, formulario do usuario) nem hash na tela', !str_contains($reload['corpo'], $tempNova) && !str_contains($reload2['corpo'], $tempNova) && !str_contains($reload['corpo'] . $reload2['corpo'], '$argon2'));
    $rRepost = pedir($adm, 'usuario-form.php', 'POST', ['login' => 'nova.pessoa', 'nome' => 'Nome Sentinela Xq', 'perfil' => 'usuario']);
    afirmar('reenvio do mesmo POST (login duplicado) => 422 e NAO mostra nova senha nem altera a senha do usuario', $rRepost['status'] === 422 && !preg_match('/[A-HJ-NP-Za-km-z2-9]{4}(?:-[A-HJ-NP-Za-km-z2-9]{4}){3}/', $rRepost['corpo']) && SenhaPolitica::verificar($tempNova, (string) gtEscalar($pdo, "SELECT senha_hash FROM tb_gestao_usuario WHERE login='nova.pessoa'")));
    // varredura de sentinelas
    entrar('carla.usuario', SENT_ERRADA);
    $segredos = [$tempNova, $tempDavi, SENT_NOVA, SENT_NOVA2, SENT_ERRADA, GT_SENHA_BOA, 'errada-demais-1', 'Senha-Errada-Timing-3', 'Nome Sentinela Xq', GT_SAL];
    $dump = '';
    foreach (gtLinhas($pdo, "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'tb\\_gestao\\_%'") as $tb) {
        foreach (gtLinhas($pdo, 'SELECT * FROM `' . $tb['t'] . '`') as $linha) {
            if ($tb['t'] === 'tb_gestao_usuario') {
                unset($linha['senha_hash']);
            }
            $dump .= $tb['t'] . '|' . json_encode($linha, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    $logTxt = is_file($log) ? (string) file_get_contents($log) : '';
    $arqs = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getSize() < 5_000_000) {
            $arqs .= (string) file_get_contents($f->getPathname());
        }
    }
    $achou = [];
    foreach ($segredos as $sg) {
        if ($sg === 'Nome Sentinela Xq') {
            continue;
        }
        foreach (['banco(tb_gestao_*, sem senha_hash)' => $dump, 'error_log' => $logTxt, 'storage' => $arqs] as $onde => $txt) {
            if ($sg !== '' && str_contains($txt, $sg)) {
                $achou[] = substr($sg, 0, 6) . '...@' . $onde;
            }
        }
    }
    afirmar('sentinelas (senhas digitadas/temporarias, sal) ausentes do banco (exceto o hash), error_log e storage' . ($achou === [] ? '' : ' - ENCONTRADO: ' . implode(', ', $achou)), $achou === []);
    afirmar('hashes e tokens: nem o sid em claro nem o csrf em claro de sessao aparecem no log; sid so como sha256 no banco', !str_contains($logTxt, (string) $adm['sid']) && !str_contains($dump, (string) $adm['sid']));
    // auditoria sem PII
    $aud = gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria');
    $textoAud = json_encode($aud, JSON_UNESCAPED_UNICODE);
    $pii = [];
    foreach (['Nome Sentinela Xq', 'nova.pessoa', 'ana.admin', 'carla.usuario', 'fantasma', 'argon2', '@', 'senha', 'token', 'cookie', 'sid'] as $p) {
        if (stripos((string) $textoAud, $p) !== false) {
            $pii[] = $p;
        }
    }
    afirmar('auditoria: nenhuma ocorrencia de nome/login/hash/token/e-mail nas ' . count($aud) . ' linhas' . ($pii === [] ? '' : ' - ACHADO: ' . implode(',', $pii)), $pii === []);
    $detalhesOk = true;
    foreach ($aud as $l) {
        if ($l['detalhe'] !== null && preg_match('/\A(?:(?:origem|motivo|perfil_de|perfil_para|ativo_para|sessoes_revogadas)=[a-z0-9_]{1,20})(?:;(?:origem|motivo|perfil_de|perfil_para|ativo_para|sessoes_revogadas)=[a-z0-9_]{1,20})*\z/', (string) $l['detalhe']) !== 1) {
            $detalhesOk = false;
        }
        if (!in_array($l['acao'], AuditoriaDao::ACOES, true)) {
            $detalhesOk = false;
        }
    }
    afirmar('auditoria: toda acao esta no catalogo e todo detalhe obedece a allowlist (chave=valor fechados)', $detalhesOk);
    afirmar('auditoria: nenhuma linha PENDENTE sobrando apos os fluxos normais', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    $ids = gtLinhas($pdo, "SELECT id_auditoria FROM tb_gestao_auditoria WHERE resultado='OK' LIMIT 1");
    $audDao = new AuditoriaDao($pdo);
    afirmar('auditoria: linha ja fechada NAO pode ser reaberta/alterada (fechar devolve false)', $ids !== [] && $audDao->fechar((int) $ids[0]['id_auditoria'], 'ERRO') === false && (string) gtEscalar($pdo, 'SELECT resultado FROM tb_gestao_auditoria WHERE id_auditoria = :i', ['i' => $ids[0]['id_auditoria']]) === 'OK');
    foreach ([['SEGREDO', 'senha=abc'], ['perfil_para', 'root'], ['sessoes_revogadas', '99999'], ['origem', 'web;x=1']] as [$k, $v]) {
        $lan = false;
        try {
            AuditoriaDao::montarDetalhe([$k => $v]);
        } catch (InvalidArgumentException $e) {
            $lan = true;
        }
        afirmar("auditoria: detalhe fora da allowlist ($k=$v) => excecao, nada gravado", $lan);
    }
    // varredura estatica: o app so faz INSERT + 1 UPDATE (fechar) em tb_gestao_auditoria
    $raizRepo = dirname(__DIR__, 2);
    $nUpd = 0;
    $nDel = 0;
    foreach (['app', 'util', 'tools', 'public', 'cron'] as $pasta) {
        if (!is_dir($raizRepo . '/' . $pasta)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raizRepo . '/' . $pasta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || !preg_match('/\.php$/', $f->getFilename()) || str_contains($f->getPathname(), 'vendor')) {
                continue;
            }
            $c = (string) file_get_contents($f->getPathname());
            $nUpd += preg_match_all('/UPDATE\s+`?tb_gestao_auditoria/i', $c);
            $nDel += preg_match_all('/(DELETE\s+FROM|TRUNCATE(\s+TABLE)?|REPLACE\s+INTO|DROP\s+TABLE(\s+IF\s+EXISTS)?)\s+`?tb_gestao_auditoria/i', $c);
        }
    }
    afirmar("auditoria append-only no codigo: UPDATE=$nUpd (esperado 1, so o fechamento) e DELETE/TRUNCATE/REPLACE/DROP=$nDel (esperado 0)", $nUpd === 1 && $nDel === 0);

    // =====================================================================
    // 10. Cabecalhos/CSP em todas as respostas, 500 e views
    // =====================================================================
    // 500 real: tabela de sessao ausente => excecao nao tratada
    $pdo->exec('RENAME TABLE tb_gestao_sessao TO tb_gestao_sessao_x');
    $r500 = G(['arquivo' => 'usuarios.php', 'cookies' => ['gestao_sid' => str_repeat('b', 64)], 'ip' => ipNovo()]);
    $pdo->exec('RENAME TABLE tb_gestao_sessao_x TO tb_gestao_sessao');
    afirmar('500 (excecao nao tratada com tabela ausente): status 500, cabecalhos de seguranca/CSP presentes e SEM vazar trace/caminho/SQL', $r500['status'] === 500 && temCab6($r500) && !preg_match('/Fatal error|Stack trace|SQLSTATE|PDOException|\.php on line|\.php:\d|htdocs|C:\\\\|xampp/i', $r500['corpo']));
    $r503 = G(['arquivo' => 'login.php', 'env' => ['GESTAO_HASH_SALT' => ''], 'ip' => ipNovo()]);
    afirmar('503 (sem sal): cabecalhos presentes e corpo sem detalhes internos', $r503['status'] === 503 && temCab6($r503) && !preg_match('/GESTAO_HASH_SALT|\.env/i', $r503['corpo']));
    $falhaHdr = [];
    $statusVistos = [];
    foreach ($TODAS as $t) {
        $statusVistos[$t['status']] = ($statusVistos[$t['status']] ?? 0) + 1;
        foreach (CAB6 as $n => $v) {
            if (($t['cab'][$n][0] ?? null) !== $v) {
                $falhaHdr[] = $t['metodo'] . ' ' . $t['arq'] . ' ' . $t['status'] . ' falta ' . $n;
            }
        }
    }
    ksort($statusVistos);
    echo 'INFO headers: ' . count($TODAS) . ' respostas verificadas; status vistos: ' . json_encode($statusVistos) . "\n";
    afirmar('cabecalhos+CSP exatos em TODAS as ' . count($TODAS) . ' respostas da gestao (200/302/400/401/403/405/429/500/503)' . ($falhaHdr === [] ? '' : ' - FALHAS: ' . implode(' | ', array_slice(array_unique($falhaHdr), 0, 6))), $falhaHdr === []);
    foreach ([200, 302, 400, 401, 403, 405, 429, 500, 503] as $stEsp) {
        afirmar("cobertura de status: a bateria produziu pelo menos uma resposta $stEsp", isset($statusVistos[$stEsp]));
    }

    // views sem inline + XSS (dados hostis semeados direto no banco, como se tivessem escapado da validacao)
    $xssNome = '"><img src=x onerror=alert(1)><script>alert(2)</script>';
    $pdo->prepare("INSERT INTO tb_gestao_usuario (login, nome, perfil, senha_hash, ativo, deve_trocar_senha) VALUES ('xss.teste', :n, 'usuario', 'x', 1, 0)")->execute(['n' => $xssNome]);
    $adm = entrar('ana.admin', GT_SENHA_BOA);
    $idXss = (string) idDe($pdo, 'xss.teste');
    $paginas = [
        'login' => G(['arquivo' => 'login.php', 'ip' => ipNovo()]),
        'usuarios (lista com nome hostil)' => pedir($adm, 'usuarios.php'),
        'usuarios ?msg=<script>' => pedir($adm, 'usuarios.php', 'GET', [], ['query' => ['msg' => '<script>MSGSENT</script>']]),
        'usuarios ?msg[]' => G(['arquivo' => 'usuarios.php', 'query' => ['msg' => ['a']], 'cookies' => ['gestao_sid' => $adm['sid']], 'ip' => $adm['ip']]),
        'usuarios ?msg=usuario_editado (flash valido)' => pedir($adm, 'usuarios.php', 'GET', [], ['query' => ['msg' => 'usuario_editado']]),
        'usuario-form novo' => pedir($adm, 'usuario-form.php'),
        'usuario-form editar (nome hostil)' => pedir($adm, 'usuario-form.php', 'GET', [], ['query' => ['id' => $idXss]]),
        'usuario-form 422 com eco hostil' => pedir($adm, 'usuario-form.php', 'POST', ['login' => '"><script>alert(3)</script>', 'nome' => $xssNome, 'perfil' => '"><x>']),
        'conta' => pedir($adm, 'conta.php'),
        'senha temporaria' => $rc,
        '403 HTTPS' => G(['arquivo' => 'login.php', 'https' => false, 'ip' => ipNovo()]),
        '403 perfil' => pedir($usu, 'usuarios.php'),
        '405' => G(['arquivo' => 'login.php', 'metodo' => 'PUT', 'ip' => ipNovo()]),
    ];
    foreach ($paginas as $rot => $r) {
        afirmar("view [$rot]: sem script/style/on*=/javascript: inline (CSP-compativel)", semInlineQA($r['corpo']));
    }
    $lista = $paginas['usuarios (lista com nome hostil)']['corpo'];
    afirmar('XSS: nome hostil (do banco) aparece ESCAPADO na lista e nunca como tag', str_contains($lista, '&lt;script&gt;alert(2)&lt;/script&gt;') && !str_contains($lista, '<script>alert(2)') && !str_contains($lista, '<img src=x'));
    afirmar('XSS: nome hostil escapado tambem no formulario de edicao e no topo (nao-cru)', !str_contains($paginas['usuario-form editar (nome hostil)']['corpo'], '<img src=x') && !str_contains($paginas['usuario-form editar (nome hostil)']['corpo'], '<script>alert(2)'));
    $f422 = $paginas['usuario-form 422 com eco hostil'];
    afirmar('XSS: eco de login/nome/perfil invalidos no 422 volta escapado', $f422['status'] === 422 && !str_contains($f422['corpo'], '<script>alert(3)') && !str_contains($f422['corpo'], '<x>') && !str_contains($f422['corpo'], '<img src=x'));
    afirmar('XSS: ?msg=<script> e ?msg[] nao aparecem na pagina (so codigos fixos)', !str_contains($paginas['usuarios ?msg=<script>']['corpo'], 'MSGSENT') && $paginas['usuarios ?msg[]']['status'] === 200 && str_contains($paginas['usuarios ?msg=usuario_editado (flash valido)']['corpo'], 'Usuário atualizado.'));
    $js = (string) file_get_contents($raizRepo . '/public/gestao/assets/gestao.js');
    $jsSemComent = preg_replace('~/\*.*?\*/|(?<![:\'\"])//[^\n]*~s', '', $js);
    afirmar('gestao.js (sem comentarios): sem eval/new Function/document.write/innerHTML/insertAdjacentHTML/outerHTML', preg_match('/\beval\s*\(|new\s+Function|document\.write|innerHTML|insertAdjacentHTML|outerHTML/', (string) $jsSemComent) !== 1);
    $css = (string) file_get_contents($raizRepo . '/public/gestao/assets/gestao.css');
    afirmar('gestao.css: sem @import/url() externo (http) e sem expression()', preg_match('/@import|url\(\s*[\'"]?https?:|expression\s*\(/i', $css) !== 1);

    // =====================================================================
    // 11. Token HMAC do formulario de login
    // =====================================================================
    $agora = time();
    // B1: a chave do HMAC e DERIVADA do sal (o sal nao assina direto)
    $mk = static fn (int $ts, string $sal = GT_SAL): string => $ts . '.' . hash_hmac('sha256', 'gestao-login|' . $ts, hash_hmac('sha256', 'gestao-login-token-v1', $sal));
    $postTk = static fn (string $tk): array => G(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'carla.usuario', 'senha' => GT_SENHA_BOA, 'token_login' => $tk], 'ip' => ipNovo()]);
    afirmar('token_login valido (forjado com o sal do QA, ts agora-100) => aceito (302)', $postTk($mk($agora - 100))['status'] === 302);
    afirmar('token_login com 7199 s => aceito (borda interna)', $postTk($mk($agora - 7190))['status'] === 302);
    afirmar('token_login expirado (7201 s) => 400', $postTk($mk($agora - 7210))['status'] === 400);
    afirmar('token_login do FUTURO distante (+1 h) => 400', $postTk($mk($agora + 3600))['status'] === 400);
    afirmar('token_login com ts adulterado (mac do ts anterior) => 400', $postTk(($agora - 50) . '.' . explode('.', $mk($agora - 100))[1])['status'] === 400);
    afirmar('token_login com mac de OUTRO sal => 400', $postTk($mk($agora - 10, 'outro-sal-qualquer-0123456789abcdef'))['status'] === 400);
    $bom = $mk($agora - 10);
    $adulterado = substr($bom, 0, -1) . (substr($bom, -1) === 'a' ? 'b' : 'a');
    afirmar('token_login com 1 caractere do mac trocado => 400', $postTk($adulterado)['status'] === 400);
    afirmar('token_login em MAIUSCULAS / com espaco / vazio / so ponto => 400', $postTk(strtoupper($bom))['status'] === 400 && $postTk($bom . ' ')['status'] === 400 && $postTk('')['status'] === 400 && $postTk('.')['status'] === 400);
    $r1 = $postTk($bom);
    $r2 = $postTk($bom);
    echo "INFO token_login e SEM estado: o mesmo token valido foi aceito 2x ({$r1['status']}/{$r2['status']}) dentro da janela de 2 h (nao e uso unico; mitigado por CSRF/Origin e limite por IP)\n";
    afirmar('token_login: reutilizado dentro da janela e aceito (comportamento desenhado: HMAC sem estado)', $r1['status'] === 302 && $r2['status'] === 302);

    // =====================================================================
    // 12. Concorrencia (processos separados)
    // =====================================================================
    restaurarAdmins($pdo);
    $adm = entrar('ana.admin', GT_SENHA_BOA);
    $adm2 = entrar('beto.admin', GT_SENHA_BOA);
    // 12a. 2 processos criando o MESMO login
    $mk2 = static fn (array $s, string $nome) => ['arquivo' => 'usuario-form.php', 'metodo' => 'POST', 'form' => ['login' => 'duplo.criado', 'nome' => $nome, 'perfil' => 'usuario', 'csrf_token' => (string) $s['csrf']], 'cookies' => ['gestao_sid' => $s['sid']], 'ip' => $s['ip'], 'logErro' => $log];
    $res = simultaneas([$mk2($adm, 'Duplo Um'), $mk2($adm2, 'Duplo Dois')]);
    $nDup = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_usuario WHERE login='duplo.criado'");
    $st = array_map(static fn ($x) => $x['status'], $res);
    sort($st);
    afirmar('2 processos criando o MESMO login: exatamente 1 usuario e respostas 200 + 422 (status=' . implode(',', $st) . ')', $nDup === 1 && $st === [200, 422]);
    // 12b. 2 admins desativando-se mutuamente, 14 rodadas (invariante: >= 1 admin ativo)
    $zerou = 0;
    $dois500 = 0;
    $okAmbos = 0;
    for ($rod = 0; $rod < 14; $rod++) {
        restaurarAdmins($pdo);
        $a = entrar('ana.admin', GT_SENHA_BOA);
        $b = entrar('beto.admin', GT_SENHA_BOA);
        $acao = $rod % 2 === 0 ? 'desativar' : 'perfil';
        $jobA = $acao === 'desativar'
            ? ['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => ['acao' => 'desativar', 'id_usuario' => (string) idDe($pdo, 'beto.admin'), 'csrf_token' => (string) $a['csrf']], 'cookies' => ['gestao_sid' => $a['sid']], 'ip' => $a['ip'], 'logErro' => $log]
            : ['arquivo' => 'usuario-form.php', 'metodo' => 'POST', 'form' => ['id_usuario' => (string) idDe($pdo, 'beto.admin'), 'nome' => 'Beto Admin', 'perfil' => 'usuario', 'csrf_token' => (string) $a['csrf']], 'cookies' => ['gestao_sid' => $a['sid']], 'ip' => $a['ip'], 'logErro' => $log];
        $jobB = $acao === 'desativar'
            ? ['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => ['acao' => 'desativar', 'id_usuario' => (string) idDe($pdo, 'ana.admin'), 'csrf_token' => (string) $b['csrf']], 'cookies' => ['gestao_sid' => $b['sid']], 'ip' => $b['ip'], 'logErro' => $log]
            : ['arquivo' => 'usuario-form.php', 'metodo' => 'POST', 'form' => ['id_usuario' => (string) idDe($pdo, 'ana.admin'), 'nome' => 'Ana Admin', 'perfil' => 'usuario', 'csrf_token' => (string) $b['csrf']], 'cookies' => ['gestao_sid' => $b['sid']], 'ip' => $b['ip'], 'logErro' => $log];
        $res = simultaneas([$jobA, $jobB], 0.9);
        $ativos = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_usuario WHERE perfil='admin' AND ativo=1");
        if ($ativos < 1) {
            $zerou++;
        }
        $oks = 0;
        foreach ($res as $x) {
            if ($x['status'] >= 500 || $x['status'] === 0) {
                $dois500++;
            }
            if ($x['status'] === 302 && (str_contains((string) ($x['cab']['location'][0] ?? ''), 'usuario_desativado') || str_contains((string) ($x['cab']['location'][0] ?? ''), 'usuario_editado'))) {
                $oks++;
            }
        }
        if ($oks === 2) {
            $okAmbos++;
        }
    }
    afirmar("2 admins se desativando/rebaixando mutuamente (14 rodadas reais): NUNCA zera os admins ativos (zerou=$zerou), sem 5xx ($dois500), nunca ambos com sucesso ($okAmbos)", $zerou === 0 && $dois500 === 0 && $okAmbos === 0);
    restaurarAdmins($pdo);
    // 12c. troca de senha simultanea (mesma conta)
    $pdo->prepare("UPDATE tb_gestao_usuario SET senha_hash = :h WHERE login = 'carla.usuario'")->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA)]);
    $c1 = entrar('carla.usuario', GT_SENHA_BOA);
    $c2 = entrar('carla.usuario', GT_SENHA_BOA, null, 'Aparelho-2/1.0');
    $mkTroca = static fn (array $s, string $nova) => ['arquivo' => 'conta.php', 'metodo' => 'POST', 'form' => ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => $nova, 'senha_confirmacao' => $nova, 'csrf_token' => (string) $s['csrf']], 'cookies' => ['gestao_sid' => $s['sid']], 'ip' => $s['ip'], 'ua' => $s['ua'], 'logErro' => $log];
    $res = simultaneas([$mkTroca($c1, SENT_NOVA), $mkTroca($c2, SENT_NOVA2)]);
    $h = (string) gtEscalar($pdo, "SELECT senha_hash FROM tb_gestao_usuario WHERE login='carla.usuario'");
    $vale1 = SenhaPolitica::verificar(SENT_NOVA, $h);
    $vale2 = SenhaPolitica::verificar(SENT_NOVA2, $h);
    $sess = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_sessao s JOIN tb_gestao_usuario u ON u.id_usuario=s.id_usuario WHERE u.login='carla.usuario'");
    $s5 = array_map(static fn ($x) => $x['status'], $res);
    afirmar('troca de senha simultanea: sem 5xx, EXATAMENTE uma das duas senhas novas vale e a antiga morreu (' . implode(',', $s5) . ')', max($s5) < 500 && min($s5) > 0 && ($vale1 xor $vale2) && !SenhaPolitica::verificar(GT_SENHA_BOA, $h));
    echo "INFO troca simultanea: sessoes vivas de carla apos as 2 trocas = $sess (cada resposta cria uma sessao nova; se 2, a sessao da senha perdedora sobrevive)\n";
    // 12d. contador de login sob concorrencia (INSERT ... ON DUPLICATE KEY)
    $ipC = '203.0.113.150';
    $jobs = [];
    for ($i = 0; $i < 8; $i++) {
        $jobs[] = ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'fantasma.cc', 'senha' => 'x', 'token_login' => $TK], 'ip' => $ipC, 'logErro' => $log];
    }
    $res = simultaneas($jobs, 1.5);
    $ipHash = IpCliente::hash($ipC, GT_SAL);
    $cont = (int) gtEscalar($pdo, 'SELECT COALESCE(SUM(contador),0) FROM tb_gestao_login_tentativa WHERE ip_hash = :h', ['h' => $ipHash]);
    afirmar("contador de login por IP sob 8 requisicoes simultaneas: 8 falhas contadas, sem perda (contador=$cont)", $cont === 8 && count(array_filter($res, static fn ($x) => $x['status'] === 401)) === 8);

    // =====================================================================
    // 13. Travas deterministicas (2 conexoes PDO)
    // =====================================================================
    $pdoB = qaQrAbrirBanco($banco);
    $pdoB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $daoA = new UsuarioGestaoDao($pdo);
    $daoB = new UsuarioGestaoDao($pdoB);
    afirmar('GET_LOCK dos admins: a 1a conexao obtem; a 2a (timeout 1 s) NAO obtem enquanto a 1a segura', $daoA->obterLockAdmins(1) === true && $daoB->obterLockAdmins(1) === false);
    $daoA->liberarLockAdmins();
    afirmar('GET_LOCK dos admins: liberado, a 2a conexao obtem', $daoB->obterLockAdmins(1) === true);
    $daoB->liberarLockAdmins();
    $pdoB->exec('SET innodb_lock_wait_timeout = 1');
    $pdo->beginTransaction();
    $daoA->travarAdminsAtivos();
    $bloqueou = false;
    try {
        $pdoB->exec("UPDATE tb_gestao_usuario SET atualizado_em = NOW() WHERE login = 'ana.admin'");
    } catch (PDOException $e) {
        $bloqueou = true;
    }
    $pdo->rollBack();
    afirmar('travarAdminsAtivos usa FOR UPDATE de verdade: outra conexao NAO consegue alterar um admin ativo durante a transacao (lock wait timeout)', $bloqueou);

    // =====================================================================
    // 14. F0: pagina do totem
    // =====================================================================
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 1)')->execute(['c' => 'QUIOSQUE-QA-01', 'n' => 'Totem QA', 't' => $tok]);
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 0)')->execute(['c' => 'QUIOSQUE-OFF-01', 'n' => 'Totem Off', 't' => bin2hex(random_bytes(32))]);
    $T = static fn (array $q, string $ip, array $x = []) => gtChamar(['raiz' => 'totem', 'arquivo' => 'index.php', 'query' => $q, 'ip' => $ip, 'logErro' => $log] + $x);
    $hostil = [
        'traversal ../' => '../../etc/passwd',
        'traversal ..\\' => '..\\..\\windows\\win.ini',
        'NUL' => "QUIOSQUE-QA-01\0",
        'NUL no meio' => "QUIOSQUE\0-QA-01",
        'CRLF no fim' => "QUIOSQUE-QA-01\r\n",
        'LF no fim' => "QUIOSQUE-QA-01\n",
        'unicode (acento)' => "QUIOSQUE-QA-0\u{0031}\u{0301}",
        'fullwidth' => "\u{FF31}UIOSQUE-QA-01",
        '65 chars' => str_repeat('A', 65),
        'vazio' => '',
        'espacos' => ' QUIOSQUE-QA-01 ',
        'wildcard %' => 'QUIOSQUE%',
        'SQL' => "' OR '1'='1",
        'inativo' => 'QUIOSQUE-OFF-01',
        'inexistente' => 'NAO-EXISTE-77',
        'underscore' => 'QUIOSQUE_QA_01',
    ];
    $ref = null;
    $iph = 0;
    foreach ($hostil as $rot => $v) {
        $iph++;
        $r = $T(['totem' => $v], '198.19.0.' . $iph);
        $ref ??= $r;
        afirmar("F0 hostil [$rot]: 404 identico (status, corpo, cabecalhos) ao primeiro 404", $r['status'] === 404 && $r['corpo'] === $ref['corpo'] && array_diff_key($r['cabecalhos'], ['x-powered-by' => 1, 'date' => 1, 'content-length' => 1]) === array_diff_key($ref['cabecalhos'], ['x-powered-by' => 1, 'date' => 1, 'content-length' => 1]));
    }
    $rArr = $T(['totem' => ['QUIOSQUE-QA-01']], '198.19.1.1');
    $rNone = $T([], '198.19.1.2');
    afirmar('F0: totem[]=... e ausencia do parametro => o MESMO 404', $rArr['status'] === 404 && $rArr['corpo'] === $ref['corpo'] && $rNone['status'] === 404 && $rNone['corpo'] === $ref['corpo']);
    afirmar('F0: 404 sem token, sem nome do totem, sem "RECEPCAO"/formato, sem Retry-After', !str_contains($ref['corpo'], $tok) && !preg_match('/RECEPCAO|QUIOSQUE|totem=|formato|\[A-Z/i', $ref['corpo']) && gtCabecalho($ref, 'retry-after') === null);
    $r64 = $T(['totem' => str_repeat('A', 64)], '198.19.1.3');
    afirmar('F0: 64 caracteres validos de formato mas inexistentes => 404 identico (nao 400/503)', $r64['status'] === 404 && $r64['corpo'] === $ref['corpo']);
    $okMin = $T(['totem' => 'quiosque-qa-01'], '198.19.1.4');
    $okMax = $T(['totem' => 'QUIOSQUE-QA-01'], '198.19.1.4');
    afirmar('F0: codigo valido em minusculas e maiusculas => 200 identico (collation ignora caixa)', $okMin['status'] === 200 && $okMax['status'] === 200 && $okMin['corpo'] === $okMax['corpo'] && str_contains($okMax['corpo'], 'data-totem-token="' . $tok . '"'));
    // so falhas contam; 429
    $ipL = '198.19.2.1';
    $cods = [];
    for ($i = 1; $i <= 22; $i++) {
        $cods[] = $T(['totem' => 'NAO-EXISTE-' . $i], $ipL)['status'];
    }
    afirmar('F0: 20 falhas => 404 (20x) e a 21a e 22a => 429', array_slice($cods, 0, 20) === array_fill(0, 20, 404) && $cods[20] === 429 && $cods[21] === 429);
    $r429 = $T(['totem' => 'NAO-EXISTE-X'], $ipL);
    $ra = (int) gtCabecalho($r429, 'retry-after');
    afirmar('F0: 429 com Retry-After (1..600), 4 cabecalhos e corpo sem dados do totem', $r429['status'] === 429 && $ra >= 1 && $ra <= 600 && gtCabecalho($r429, 'cache-control') === 'no-store' && gtCabecalho($r429, 'referrer-policy') === 'no-referrer' && gtCabecalho($r429, 'x-robots-tag') === 'noindex, nofollow' && gtCabecalho($r429, 'x-content-type-options') === 'nosniff' && !str_contains($r429['corpo'], $tok));
    $rOutroIp = $T(['totem' => 'QUIOSQUE-QA-01'], '198.19.2.2');
    afirmar('F0: o quiosque valido em OUTRO IP segue 200 enquanto o atacante esta em 429', $rOutroIp['status'] === 200);
    $rMesmoIp = $T(['totem' => 'QUIOSQUE-QA-01'], $ipL);
    echo 'INFO F0: pedido VALIDO vindo do mesmo IP que ja esta bloqueado por 20 falhas recebe ' . $rMesmoIp['status'] . " (o bloqueio e por IP e vale para qualquer codigo ate a janela de 10 min vencer)\n";
    // quiosque valido nunca incrementa
    $ipV = '198.19.3.1';
    $cods = [];
    for ($i = 0; $i < 30; $i++) {
        $cods[] = $T(['totem' => 'QUIOSQUE-QA-01'], $ipV)['status'];
    }
    $arqIp = $storage . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit' . DIRECTORY_SEPARATOR . gtNomeContador($ipV) . '.json';
    afirmar('F0: 30 pedidos VALIDOS seguidos do mesmo IP => todos 200 e NENHUM contador criado', $cods === array_fill(0, 30, 200) && !is_file($arqIp));
    // validos entre falhas nao zeram nem somam
    $ipM = '198.19.3.2';
    for ($i = 0; $i < 10; $i++) {
        $T(['totem' => 'NAO-EXISTE-M' . $i], $ipM);
        $T(['totem' => 'QUIOSQUE-QA-01'], $ipM);
    }
    $arqM = $storage . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit' . DIRECTORY_SEPARATOR . gtNomeContador($ipM) . '.json';
    $est = json_decode((string) @file_get_contents($arqM), true);
    afirmar('F0: 10 falhas intercaladas com 10 validos => contador = 10 (valido nao soma)', ($est['falhas'] ?? -1) === 10);
    afirmar('F0: arquivo do contador nomeado por hash (sem IP em claro) e dentro do storage', is_file($arqM) && !str_contains($arqM, $ipM));
    // IP real atras do Cloudflare no F0
    $ipAtac = '203.0.113.99';
    $cods = [];
    for ($i = 1; $i <= 22; $i++) {
        $cods[] = $T(['totem' => 'NAO-EXISTE-C' . $i], $ipAtac, ['cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.' . $i]])['status'];
    }
    afirmar('F0: CF-Connecting-IP forjado de IP NAO Cloudflare nao escapa do limite (21a/22a => 429)', $cods[20] === 429 && $cods[21] === 429);
    $cods = [];
    for ($i = 1; $i <= 22; $i++) {
        $cods[] = $T(['totem' => 'NAO-EXISTE-D' . $i], '104.16.9.9', ['cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.250']])['status'];
    }
    $rVis2 = $T(['totem' => 'QUIOSQUE-QA-01'], '104.16.9.9', ['cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.251']]);
    afirmar('F0: de IP Cloudflare, o visitante (CF-Connecting-IP) e que e limitado; outro visitante pelo mesmo edge segue 200', $cods[20] === 429 && $rVis2['status'] === 200);
    // concorrencia no contador por arquivo
    $ipK = '198.19.4.1';
    $jobs = [];
    for ($i = 0; $i < 12; $i++) {
        $jobs[] = ['raiz' => 'totem', 'arquivo' => 'index.php', 'query' => ['totem' => 'NAO-EXISTE-K' . $i], 'ip' => $ipK, 'logErro' => $log];
    }
    $res = simultaneas($jobs, 1.5);
    $arqK = $storage . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit' . DIRECTORY_SEPARATOR . gtNomeContador($ipK) . '.json';
    $estK = json_decode((string) @file_get_contents($arqK), true);
    afirmar('F0: contador por arquivo com 12 falhas SIMULTANEAS (processos separados) = 12 exatos (flock, sem perda)', ($estK['falhas'] ?? -1) === 12 && count(array_filter($res, static fn ($x) => $x['status'] === 404)) === 12);
    // 503 por banco fora nao conta
    $ipB = '198.19.5.1';
    $antesB = getenv('QA_QR_FORCE_DB_NAME');
    putenv('QA_QR_FORCE_DB_NAME=qa_qr_exclusivo_00000000');
    $c503 = [];
    for ($i = 0; $i < 25; $i++) {
        $c503[] = $T(['totem' => 'QUIOSQUE-QA-01'], $ipB)['status'];
    }
    putenv('QA_QR_FORCE_DB_NAME=' . $antesB);
    afirmar('F0: 25 pedidos validos com banco FORA => 503 todos (nunca 429) e sem contador', $c503 === array_fill(0, 25, 503) && !is_file($storage . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit' . DIRECTORY_SEPARATOR . gtNomeContador($ipB) . '.json'));
    // prova byte a byte contra o HEAD
    $head = getenv('QA_HEAD_TREE');
    if ($head !== false && $head !== '' && is_file($head . '/public/totem/index.php')) {
        $cgiHead = static function (string $codigo, string $ip) use ($head, $log): array {
            $env = [
                'REDIRECT_STATUS' => '200', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $head . '/public/totem/index.php', 'SCRIPT_NAME' => '/totem/index.php',
                'QUERY_STRING' => 'totem=' . rawurlencode($codigo), 'REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'totem.exemplo.test', 'HTTPS' => 'on',
                'QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'), 'QA_QR_FORCE_STORAGE' => (string) getenv('QA_QR_FORCE_STORAGE'),
                'QA_GESTAO_ENV_JSON' => json_encode(gtEnvPadrao()), 'SystemRoot' => (string) getenv('SystemRoot'),
            ];
            $cmd = [gtCgiBinario(), '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'log_errors=1'];
            $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $head . '/public/totem', $env);
            fclose($pipes[0]);
            $o = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            $partes = preg_split("/\r?\n\r?\n/", $o, 2);

            return ['corpo' => $partes[1] ?? '', 'cab' => $partes[0] ?? ''];
        };
        $novo = $T(['totem' => 'QUIOSQUE-QA-01'], '198.19.6.1', ['host' => 'totem.exemplo.test']);
        $velho = $cgiHead('QUIOSQUE-QA-01', '198.19.6.1');
        afirmar('QUIOSQUE: HTML de ?totem=<valido> do WORKING TREE e BYTE A BYTE igual ao do HEAD (' . strlen($novo['corpo']) . ' bytes)', $novo['corpo'] !== '' && $novo['corpo'] === $velho['corpo'] && md5($novo['corpo']) === md5($velho['corpo']));
        $novo2 = $T(['totem' => 'quiosque-qa-01'], '198.19.6.2', ['host' => 'totem.exemplo.test']);
        $velho2 = $cgiHead('quiosque-qa-01', '198.19.6.2');
        afirmar('QUIOSQUE: idem com o codigo em minusculas (collation)', $novo2['corpo'] === $velho2['corpo'] && $novo2['corpo'] !== '');
        // F2 (2026-10-07): o HEAD (785ad6f) JA contem o endurecimento da F0 (os 4 cabecalhos), entao a prova deixou de ser "a diferenca e so aditiva" e passou a ser mais forte: os cabecalhos do working tree sao IDENTICOS aos do HEAD e nenhum Content-Security-Policy existe.
        $cabNorm = static function (string $bloco): array {
            $linhas = [];
            foreach (preg_split("/\r?\n/", $bloco) as $l) {
                $l = trim($l);
                if ($l === '' || preg_match('/^(date|content-length|x-powered-by):/i', $l) === 1) {
                    continue;
                }
                $linhas[] = strtolower($l);
            }
            sort($linhas);

            return $linhas;
        };
        afirmar('QUIOSQUE: os cabecalhos (os 4 da F0) do working tree sao IDENTICOS aos do HEAD e nenhum Content-Security-Policy foi adicionado', str_contains(strtolower($velho['cab']), 'referrer-policy') && $cabNorm($velho['cab']) === $cabNorm((string) (preg_split("/\r?\n\r?\n/", $novo['cru'], 2)[0] ?? '')) && gtCabecalho($novo, 'content-security-policy') === null);
    } else {
        afirmar('QUIOSQUE vs HEAD: QA_HEAD_TREE nao informado (PULADO - nao conta como aprovado)', false);
    }
} catch (Throwable $e) {
    afirmar('excecao nao esperada no teste: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getLine(), false);
} finally {
    gtDestruirAmbiente($banco, $storage);
    @unlink($log);
}

exit(gtResumo('teste_gestao_qa_independente'));
