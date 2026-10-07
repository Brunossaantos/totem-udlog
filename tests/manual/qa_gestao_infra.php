<?php

/**
 * Infra compartilhada dos testes da Gestao Totem (demanda gestao-totem, F0/F1,
 * 2026-10-06): banco QA descartavel `qa_qr_exclusivo_<hex>` (so schema.sql +
 * migrations, via qa_qr_exclusivo_bootstrap.php), storage temporario, semeadura
 * de usuarios e chamada de paginas REAIS por php-cgi (cookies, formulario,
 * cabecalhos). Nunca toca em udlog_totem; sem rede, sem Talent/VIO/n8n.
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_qr_exclusivo_bootstrap.php';

use App\Dao\UsuarioGestaoDao;
use Util\SenhaPolitica;

$GLOBALS['gtTotal'] = 0;
$GLOBALS['gtFalhas'] = 0;

function afirmar(string $descricao, bool $condicao): void
{
    $GLOBALS['gtTotal']++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) {
        $GLOBALS['gtFalhas']++;
    }
}

function gtResumo(string $nome): int
{
    echo "\n{$nome}: {$GLOBALS['gtTotal']} verificacoes, {$GLOBALS['gtFalhas']} falhas\n";

    return $GLOBALS['gtFalhas'] === 0 ? 0 : 1;
}

/** @return array{0:PDO,1:string,2:string} [pdo QA, nome do banco, storage temporario] */
function gtCriarAmbiente(): array
{
    [$pdoAdmin, $banco] = qaQrCriarBanco();
    $pdo = qaQrAbrirBanco($banco);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '-03:00'");
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_' . bin2hex(random_bytes(6));
    mkdir($storage, 0700, true);
    // herdados pelos subprocessos php-cgi (o prepend forca DB_NAME e STORAGE_PATH)
    putenv('QA_QR_FORCE_DB_NAME=' . $banco);
    putenv('QA_QR_FORCE_STORAGE=' . $storage);

    return [$pdo, $banco, $storage];
}

function gtRemoverPasta(string $dir): void
{
    if ($dir === '' || !is_dir($dir) || !str_contains($dir, 'qa_gestao_')) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function gtDestruirAmbiente(?string $banco, ?string $storage): void
{
    if ($banco !== null) {
        qaQrDroparBanco($banco);
    }
    if ($storage !== null) {
        gtRemoverPasta($storage);
    }
}

/** Semeia um usuario direto no banco (senha com o hash real da aplicacao). */
function gtSemear(PDO $pdo, string $login, string $perfil, string $senha, bool $deveTrocar = false, bool $ativo = true, string $nome = 'Fulano Teste'): int
{
    $dao = new UsuarioGestaoDao($pdo);
    $id = $dao->inserir($login, $nome, $perfil, SenhaPolitica::gerarHash($senha), $deveTrocar, null);
    if (!$ativo) {
        $dao->atualizarAtivo($id, false);
    }

    return $id;
}

const GT_SENHA_BOA = 'Correta-Horse-Battery-9';
const GT_SAL = 'qa-sal-gestao-0123456789abcdef0123456789abcdef';

/** Variaveis padrao da gestao nos subprocessos (sobrescreviveis por teste). */
function gtEnvPadrao(): array
{
    return [
        'GESTAO_HASH_SALT' => GT_SAL,
        'GESTAO_SESSION_IDLE_MIN' => '30',
        'GESTAO_SESSION_ABSOLUTE_MIN' => '720',
        'GESTAO_PERMITIR_HTTP' => 'false',
    ];
}

function gtCgiBinario(): string
{
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    if (!is_file($cgi)) {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi';
    }
    if (!is_file($cgi)) {
        fwrite(STDERR, "php-cgi nao encontrado; abortando.\n");
        exit(2);
    }

    return $cgi;
}

/**
 * Chama uma pagina por php-cgi.
 *
 * @param array<string,mixed> $o raiz ('gestao'|'totem'), arquivo, metodo, query (array), form (array),
 *        corpo (string crua), cookies (array), https (bool, padrao true), ip, host, ua, origin (string|null),
 *        cabecalhos (array nome CGI => valor, ex. HTTP_CF_CONNECTING_IP), env (array GESTAO_* etc.), logErro (caminho)
 * @return array{status:int,cabecalhos:array<string,list<string>>,cru:string,corpo:string,setCookies:list<array<string,mixed>>}
 */
function gtChamar(array $o): array
{
    $raizProjeto = dirname(__DIR__, 2);
    $raiz = ($o['raiz'] ?? 'gestao') === 'totem' ? $raizProjeto . '/public/totem' : $raizProjeto . '/public/gestao';
    $arquivo = (string) $o['arquivo'];
    $script = $raiz . '/' . $arquivo;
    $metodo = strtoupper((string) ($o['metodo'] ?? 'GET'));
    $corpo = array_key_exists('corpo', $o) ? (string) $o['corpo'] : http_build_query($o['form'] ?? []);
    $tmp = tempnam(sys_get_temp_dir(), 'qa_gestao_body_');
    file_put_contents($tmp, $corpo);

    $envCgi = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $metodo,
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/' . (($o['raiz'] ?? 'gestao') === 'totem' ? 'totem' : 'gestao') . '/' . $arquivo,
        'REQUEST_URI' => '/' . (($o['raiz'] ?? 'gestao') === 'totem' ? 'totem' : 'gestao') . '/' . $arquivo . ($o['query'] ?? [] ? '?' . http_build_query($o['query']) : ''),
        'QUERY_STRING' => http_build_query($o['query'] ?? []),
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'CONTENT_LENGTH' => (string) strlen($corpo),
        'REMOTE_ADDR' => (string) ($o['ip'] ?? '192.0.2.10'),
        'HTTP_HOST' => (string) ($o['host'] ?? 'gestao.exemplo.test'),
        'HTTP_USER_AGENT' => (string) ($o['ua'] ?? 'QA-Navegador/1.0'),
        'QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'),
        'QA_QR_FORCE_STORAGE' => (string) getenv('QA_QR_FORCE_STORAGE'),
        'QA_QR_DB_HOST' => (string) getenv('QA_QR_DB_HOST'),
        'QA_QR_DB_PORT' => (string) getenv('QA_QR_DB_PORT'),
        'QA_QR_DB_USER' => (string) getenv('QA_QR_DB_USER'),
        'QA_QR_DB_PASS' => (string) getenv('QA_QR_DB_PASS'),
        'QA_GESTAO_ENV_JSON' => json_encode(($o['env'] ?? []) + gtEnvPadrao()),
        'SystemRoot' => (string) getenv('SystemRoot'),
    ];
    if (($o['https'] ?? true) === true) {
        $envCgi['HTTPS'] = 'on';
    }
    $origin = array_key_exists('origin', $o) ? $o['origin'] : (($metodo === 'POST') ? 'https://' . ($o['host'] ?? 'gestao.exemplo.test') : null);
    if (is_string($origin)) {
        $envCgi['HTTP_ORIGIN'] = $origin;
    }
    if (!empty($o['cookies'])) {
        $pares = [];
        foreach ($o['cookies'] as $k => $v) {
            $pares[] = $k . '=' . $v;
        }
        $envCgi['HTTP_COOKIE'] = implode('; ', $pares);
    }
    foreach (($o['cabecalhos'] ?? []) as $k => $v) {
        $envCgi[$k] = (string) $v;
    }

    $cmd = [gtCgiBinario(), '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'memory_limit=256M'];
    foreach (($o['ini'] ?? []) as $diretiva) {
        $cmd[] = '-d';
        $cmd[] = $diretiva;
    }
    if (isset($o['logErro'])) {
        $cmd[] = '-d';
        // entre aspas: o caminho temporario do Windows tem "~" (BRUNO~1) e o INI o leria como operador
        $cmd[] = 'error_log="' . $o['logErro'] . '"';
    }
    $proc = proc_open($cmd, [0 => ['file', $tmp, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, $envCgi);
    $saida = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmp);

    $partes = preg_split("/\r?\n\r?\n/", $saida, 2);
    $bloco = $partes[0] ?? '';
    $status = 200;
    $cab = [];
    $setCookies = [];
    foreach (preg_split("/\r?\n/", $bloco) as $linha) {
        if ($linha === '' || !str_contains($linha, ':')) {
            continue;
        }
        [$nome, $valor] = explode(':', $linha, 2);
        $nome = strtolower(trim($nome));
        $valor = trim($valor);
        if ($nome === 'status') {
            $status = (int) $valor;
            continue;
        }
        $cab[$nome][] = $valor;
        if ($nome === 'set-cookie') {
            $setCookies[] = gtParseCookie($valor);
        }
    }

    return ['status' => $status, 'cabecalhos' => $cab, 'cru' => $saida, 'corpo' => $partes[1] ?? '', 'setCookies' => $setCookies];
}

/** @return array<string,mixed> */
function gtParseCookie(string $linha): array
{
    $partes = array_map('trim', explode(';', $linha));
    [$nome, $valor] = array_pad(explode('=', array_shift($partes), 2), 2, '');
    $atributos = [];
    foreach ($partes as $p) {
        $kv = explode('=', $p, 2);
        $atributos[strtolower($kv[0])] = $kv[1] ?? true;
    }

    return ['nome' => $nome, 'valor' => $valor, 'atributos' => $atributos, 'cru' => $linha];
}

function gtCabecalho(array $r, string $nome): ?string
{
    return $r['cabecalhos'][strtolower($nome)][0] ?? null;
}

/** Valor do cookie gestao_sid devolvido na resposta (ou null). */
function gtSid(array $r): ?string
{
    foreach ($r['setCookies'] as $c) {
        if ($c['nome'] === 'gestao_sid' && $c['valor'] !== '' && $c['valor'] !== 'deleted') {
            return $c['valor'];
        }
    }

    return null;
}

/** Extrai o token CSRF do <meta name="csrf-token"> ou de um input hidden. */
function gtCsrf(array $r): ?string
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]{64})"/', $r['corpo'], $m) === 1) {
        return $m[1];
    }

    return null;
}

function gtTokenLogin(array $r): ?string
{
    if (preg_match('/name="token_login" value="([0-9]+\.[a-f0-9]{64})"/', $r['corpo'], $m) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * Faz login completo por HTTP e devolve [sid, csrf, respostaDoLogin]. csrf vem de
 * um GET autenticado a conta.php (ou usuarios.php se admin).
 *
 * @return array{sid:?string,csrf:?string,resp:array<string,mixed>}
 */
function gtLogin(string $login, string $senha, array $extra = []): array
{
    $g = gtChamar(['arquivo' => 'login.php'] + $extra);
    $tk = gtTokenLogin($g);
    $r = gtChamar(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => $login, 'senha' => $senha, 'token_login' => (string) $tk]] + $extra);
    $sid = gtSid($r);
    $csrf = null;
    if ($sid !== null) {
        $p = gtChamar(['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => $sid]] + $extra);
        $csrf = gtCsrf($p);
        if ($csrf === null) {
            // troca obrigatoria: conta.php ainda mostra o csrf (meta presente)
            $csrf = gtCsrf($p);
        }
    }

    return ['sid' => $sid, 'csrf' => $csrf, 'resp' => $r];
}

function gtLinhas(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function gtEscalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchColumn();
}

/** Nome do arquivo do contador de falhas da pagina do totem (HMAC do balde do IP com o sal da gestao do QA). */
function gtNomeContador(string $ip, string $sal = GT_SAL): string
{
    return substr(hash_hmac('sha256', Util\IpCliente::balde($ip), strlen($sal) >= 16 ? $sal : Util\LimiteFalhasIp::SAL_PADRAO), 0, 40);
}

/** senha_versao atual do usuario (campo oculto do formulario de redefinir senha). */
function gtVersaoSenha(PDO $pdo, string $login): string
{
    return (string) gtEscalar($pdo, 'SELECT senha_versao FROM tb_gestao_usuario WHERE login = :l', ['l' => $login]);
}

function gtNovoLogCgi(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_cgi_' . bin2hex(random_bytes(4)) . '.log';
}
