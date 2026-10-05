<?php

/**
 * Teste HTTP (demanda anexo-ordem-coleta-n8n, 2026-10-05) da rota REAL
 * public/api/ordem-coleta-anexo.php via php-cgi (stdin = corpo, headers por
 * variaveis CGI), contra banco QA descartavel `qa_qr_exclusivo_<hex>` e
 * storage temporario. Nunca toca em udlog_totem nem no banco externo real;
 * sem rede, sem Talent, sem impressao.
 *
 * Hermetico: chave, flag de HTTP e limite de tamanho sao SEMPRE fixados pelo
teste (nada do .env local interfere).

Cobre: metodo (405), autenticacao (ausente/errada/vazia/503), HTTPS (403) e
 * flag de dev, rate limit (429 + Retry-After), limites de corpo (5 MiB exato,
 * +1, acima do teto, Content-Length declarado, corpo vazio/post_max_size),
 * JSON invalido/escalar, campos, base64, nao-PDF, sobrescrita/duplicado pela
 * rota, ausencia de eco/vazamento em respostas e logs.
 *
 * Uso: php tests/manual/teste_oc_anexo_http.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_oc_anexo_infra.php';

$total = 0;
$falhas = 0;
function afirmar(string $d, bool $c): void
{
    global $total, $falhas;
    $total++;
    echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n";
    if (!$c) {
        $falhas++;
    }
}

$cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
if (!is_file($cgi)) {
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi';
}
if (!is_file($cgi)) {
    fwrite(STDERR, "php-cgi nao encontrado; abortando.\n");
    exit(2);
}
$raiz = dirname(__DIR__, 2);
$rota = $raiz . '/public/api/ordem-coleta-anexo.php';
$chave = 'QA-' . bin2hex(random_bytes(24));
$logCgi = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_oc_cgi_' . bin2hex(random_bytes(4)) . '.log';

/**
 * @param array<string,string|null> $o opcoes: metodo, auth, https, corpo, cl (Content-Length forcado),
 *        chave (QA_OC_API_KEY; null = nao define), max, permitirHttp, ip, extraIni
 * @return array{status:int, cabecalhos:string, corpo:string, json:?array}
 */
function chamar(string $cgi, string $raiz, string $rota, array $o): array
{
    $corpo = $o['corpo'] ?? '';
    $tmp = tempnam(sys_get_temp_dir(), 'qa_oc_body_');
    file_put_contents($tmp, $corpo);
    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $o['metodo'] ?? 'POST',
        'SCRIPT_FILENAME' => $rota,
        'SCRIPT_NAME' => '/api/ordem-coleta-anexo.php',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => $o['ip'] ?? '192.0.2.10',
        'QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'),
        'QA_QR_FORCE_STORAGE' => (string) getenv('QA_QR_FORCE_STORAGE'),
        'QA_QR_DB_HOST' => (string) getenv('QA_QR_DB_HOST'),
        'QA_QR_DB_PORT' => (string) getenv('QA_QR_DB_PORT'),
        'QA_QR_DB_USER' => (string) getenv('QA_QR_DB_USER'),
        'QA_QR_DB_PASS' => (string) getenv('QA_QR_DB_PASS'),
        'SystemRoot' => (string) getenv('SystemRoot'),
    ];
    $env['CONTENT_LENGTH'] = (string) ($o['cl'] ?? strlen($corpo));
    if (($o['https'] ?? true) === true) {
        $env['HTTPS'] = 'on';
    }
    if (array_key_exists('auth', $o)) {
        if ($o['auth'] !== null) {
            $env['HTTP_AUTHORIZATION'] = $o['auth'];
        }
    } else {
        $env['HTTP_AUTHORIZATION'] = 'Bearer ' . $o['chave_header'];
    }
    // Hermetico quanto ao .env local (chave, flag de HTTP e limite SEMPRE fixados):
    // chave vazia/ausente = marcador explicito (variavel vazia nao e confiavel no CGI Windows).
    if (($o['chave'] ?? null) === null || $o['chave'] === '') {
        $env['QA_OC_CHAVE_VAZIA'] = '1';
    } else {
        $env['QA_OC_API_KEY'] = $o['chave'];
    }
    $env['QA_OC_MAX_BYTES'] = (string) ($o['max'] ?? '5242880');
    $env['QA_OC_PERMITIR_HTTP'] = (string) ($o['permitirHttp'] ?? 'false');
    $cmd = [$cgi, '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_oc_anexo_prepend.php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $GLOBALS['logCgi'], '-d', 'memory_limit=256M'];
    $proc = proc_open($cmd, [0 => ['file', $tmp, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz . '/public/api', $env);
    $saida = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmp);
    $status = 200;
    if (preg_match('/^Status:\s*(\d{3})/mi', $saida, $m) === 1) {
        $status = (int) $m[1];
    }
    $partes = preg_split("/\r?\n\r?\n/", $saida, 2);
    $json = json_decode($partes[1] ?? '', true);

    return ['status' => $status, 'cabecalhos' => $partes[0] ?? '', 'corpo' => $partes[1] ?? '', 'json' => is_array($json) ? $json : null];
}

$banco = null;
$storage = null;
try {
    [$pdo, $banco, $storage] = ocQaCriarAmbiente();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $base = ['chave' => $chave, 'chave_header' => $chave];
    $cnpj = '11222333000181';
    $corpoOk = static fn (string $numero, string $bytes, string $cnpjX = '11222333000181'): string => json_encode(['cnpj_cliente' => $cnpjX, 'numero_ordem_coleta' => $numero, 'arquivo_base64' => base64_encode($bytes)]);
    $raizOc = $storage . DIRECTORY_SEPARATOR . 'ordens_coleta';
    $linhas = static fn () => (int) $pdo->query('SELECT COUNT(*) FROM tb_ordem_coleta_arquivos')->fetchColumn();

    // controle: caminho feliz
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('HTTP-1', ocQaPdf(900, 'h1'))]);
    afirmar('controle: POST valido => 201 {sucesso:true, dados.status=criado}', $r['status'] === 201 && ($r['json']['sucesso'] ?? null) === true && ($r['json']['dados']['status'] ?? null) === 'criado');
    afirmar('resposta: JSON UTF-8 com Cache-Control: no-store', stripos($r['cabecalhos'], 'application/json') !== false && stripos($r['cabecalhos'], 'no-store') !== false);
    afirmar('resposta de sucesso nao expoe caminho nem numero/cnpj', !str_contains($r['corpo'], 'ordens_coleta') && !str_contains($r['corpo'], 'HTTP-1') && !str_contains($r['corpo'], $cnpj) && !str_contains($r['corpo'], $storage));
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('HTTP-1', ocQaPdf(900, 'h1'))]);
    afirmar('mesmo conteudo => 200 duplicado (1 linha, 1 arquivo)', $r['status'] === 200 && ($r['json']['dados']['status'] ?? null) === 'duplicado' && $linhas() === 1 && count(ocQaArquivos($raizOc)) === 1);
    sleep(1);
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('HTTP-1', ocQaPdf(900, 'h2'))]);
    afirmar('conteudo novo => 201 atualizado, antigo apagado (1 linha, 1 arquivo)', $r['status'] === 201 && ($r['json']['dados']['status'] ?? null) === 'atualizado' && $linhas() === 1 && count(ocQaArquivos($raizOc)) === 1);

    // metodo
    foreach (['GET', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'] as $m) {
        $r = chamar($cgi, $raiz, $rota, $base + ['metodo' => $m, 'corpo' => '']);
        afirmar("metodo $m => 405 METODO_NAO_PERMITIDO com Allow: POST", $r['status'] === 405 && stripos($r['cabecalhos'], 'Allow: POST') !== false && ($m === 'HEAD' || ($r['json']['codigo'] ?? null) === 'METODO_NAO_PERMITIDO'));
    }
    afirmar('metodo errado nao grava nada', $linhas() === 1);

    // autenticacao
    $corpo = $corpoOk('AUTH-1', ocQaPdf(300));
    foreach (['sem header' => null, 'vazio' => '', 'so Bearer' => 'Bearer', 'Bearer vazio' => 'Bearer ', 'errada' => 'Bearer ' . $chave . 'x', 'minusculo' => 'bearer ' . $chave, 'Basic' => 'Basic ' . $chave, 'sem esquema' => $chave, 'token de totem (outro formato)' => 'Bearer ' . bin2hex(random_bytes(16))] as $nome => $hdr) {
        $r = chamar($cgi, $raiz, $rota, ['chave' => $chave, 'auth' => $hdr, 'corpo' => $corpo, 'ip' => '192.0.2.' . random_int(20, 200)]);
        afirmar("auth ($nome) => 401 NAO_AUTORIZADO generico", $r['status'] === 401 && ($r['json']['codigo'] ?? null) === 'NAO_AUTORIZADO' && !str_contains($r['corpo'], $chave));
    }
    afirmar('auth recusada nao grava nada', $linhas() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='AUTH-1'")->fetchColumn() === 0);
    $r = chamar($cgi, $raiz, $rota, ['chave' => '', 'auth' => 'Bearer ', 'corpo' => $corpo]);
    afirmar('chave vazia no .env => 503 INDISPONIVEL (fail-closed), nem "Bearer " autentica', $r['status'] === 503 && ($r['json']['codigo'] ?? null) === 'INDISPONIVEL');
    $r = chamar($cgi, $raiz, $rota, ['chave' => '', 'auth' => 'Bearer ' . $chave, 'corpo' => $corpo]);
    afirmar('chave vazia no .env => 503 mesmo com header preenchido', $r['status'] === 503);
    $r = chamar($cgi, $raiz, $rota, ['chave' => null, 'auth' => 'Bearer qualquer', 'corpo' => $corpo]);
    afirmar('chave AUSENTE (mesmo caminho de codigo da vazia; marcador do prepend, independe do .env local) => 503 INDISPONIVEL', $r['status'] === 503 && ($r['json']['codigo'] ?? null) === 'INDISPONIVEL');

    // HTTPS
    $r = chamar($cgi, $raiz, $rota, $base + ['https' => false, 'corpo' => $corpo]);
    afirmar('HTTP (sem HTTPS) com chave correta => 403 HTTPS_OBRIGATORIO e nada gravado', $r['status'] === 403 && ($r['json']['codigo'] ?? null) === 'HTTPS_OBRIGATORIO' && $linhas() === 1);
    $r = chamar($cgi, $raiz, $rota, $base + ['https' => false, 'permitirHttp' => 'false', 'corpo' => $corpo]);
    afirmar('ORDEM_COLETA_ANEXO_PERMITIR_HTTP=false => continua 403', $r['status'] === 403);
    $r = chamar($cgi, $raiz, $rota, $base + ['https' => false, 'permitirHttp' => 'true', 'corpo' => $corpo]);
    afirmar('ORDEM_COLETA_ANEXO_PERMITIR_HTTP=true (dev local) libera HTTP, mas a chave continua exigida', $r['status'] === 201 && chamar($cgi, $raiz, $rota, ['chave' => $chave, 'auth' => 'Bearer errada', 'https' => false, 'permitirHttp' => 'true', 'corpo' => $corpo, 'ip' => '192.0.2.99'])['status'] === 401);

    // corpo / JSON
    foreach (['texto nao JSON' => 'abc{', 'JSON truncado' => '{"cnpj_cliente":"1', 'escalar 5' => '5', 'escalar string' => '"x"', 'true' => 'true', 'null' => 'null', 'float' => '1.5'] as $nome => $c) {
        $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $c]);
        afirmar("corpo $nome => 400 CORPO_INVALIDO (nunca 500)", $r['status'] === 400 && ($r['json']['codigo'] ?? null) === 'CORPO_INVALIDO');
    }
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => '']);
    afirmar('corpo vazio sem Content-Length => 400 CORPO_INVALIDO', $r['status'] === 400 && ($r['json']['codigo'] ?? null) === 'CORPO_INVALIDO');
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => '', 'cl' => '500']);
    afirmar('Content-Length > 0 com corpo que nao chegou (ex.: post_max_size) => 413 (nao JSON invalido)', $r['status'] === 413 && ($r['json']['codigo'] ?? null) === 'ARQUIVO_MUITO_GRANDE');
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => '[]']);
    afirmar('JSON [] => 400 CAMPO_INVALIDO', $r['status'] === 400 && ($r['json']['codigo'] ?? null) === 'CAMPO_INVALIDO');
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => '{"a":' . str_repeat('[', 50) . str_repeat(']', 50) . '}']);
    afirmar('JSON profundo demais => 400 (sem 500)', $r['status'] === 400);
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => json_encode(['cnpj_cliente' => $cnpj, 'numero_ordem_coleta' => 'X', 'arquivo_base64' => 'AAAA!'])]);
    afirmar('base64 invalido => 400 CAMPO_INVALIDO', $r['status'] === 400 && ($r['json']['codigo'] ?? null) === 'CAMPO_INVALIDO');
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => json_encode(['cnpj_cliente' => $cnpj, 'numero_ordem_coleta' => 'X', 'arquivo_base64' => 'data:application/pdf;base64,' . base64_encode(ocQaPdf(100))])]);
    afirmar('prefixo data: => 400', $r['status'] === 400);
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('NAOPDF', 'GIF89a' . str_repeat('x', 100))]);
    afirmar('nao-PDF => 422 FORMATO_NAO_SUPORTADO', $r['status'] === 422 && ($r['json']['codigo'] ?? null) === 'FORMATO_NAO_SUPORTADO');
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('X', ocQaPdf(100), '123')]);
    afirmar('cnpj invalido => 400 CAMPO_INVALIDO (dados.campo = cnpj_cliente)', $r['status'] === 400 && ($r['json']['dados']['campo'] ?? null) === 'cnpj_cliente');
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => json_encode(['cnpj_cliente' => $cnpj, 'numero_ordem_coleta' => "A\u{0000}B", 'arquivo_base64' => base64_encode(ocQaPdf(100))])]);
    afirmar('numero com NUL (\\u0000) => 400', $r['status'] === 400);

    // limites
    $limite = 5242880;
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('LIM-EXATO', ocQaPdf($limite, 'e'))]);
    afirmar('PDF de EXATAMENTE 5 MiB => 201 (cabe no teto do corpo de 7,5 MiB)', $r['status'] === 201);
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('LIM-MAIS1', ocQaPdf($limite + 1, 'e'))]);
    afirmar('5 MiB + 1 byte => 413 ARQUIVO_MUITO_GRANDE e nada gravado', $r['status'] === 413 && ($r['json']['codigo'] ?? null) === 'ARQUIVO_MUITO_GRANDE' && (int) $pdo->query("SELECT COUNT(*) FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='LIM-MAIS1'")->fetchColumn() === 0);
    $arquivosAntes = count(ocQaArquivos($raizOc));
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => str_repeat('A', 8 * 1048576)]);
    afirmar('corpo de 8 MiB (acima do teto de 7,5 MiB, Content-Length correto) => 413 sem decodificar', $r['status'] === 413 && ($r['json']['codigo'] ?? null) === 'ARQUIVO_MUITO_GRANDE');
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => '{}', 'cl' => (string) (50 * 1048576)]);
    afirmar('Content-Length declarado de 50 MiB => 413 imediato', $r['status'] === 413);
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => str_repeat('B', 8 * 1048576), 'cl' => '100']);
    afirmar('Content-Length mentindo (menor que o corpo) nao permite estourar o teto de memoria: resposta 4xx e nada gravado', $r['status'] >= 400 && $r['status'] < 500 && count(ocQaArquivos($raizOc)) === $arquivosAntes);
    $r = chamar($cgi, $raiz, $rota, $base + ['max' => '1000', 'corpo' => $corpoOk('CFG-OK', ocQaPdf(1000))]);
    $r2 = chamar($cgi, $raiz, $rota, $base + ['max' => '1000', 'corpo' => $corpoOk('CFG-MAIS', ocQaPdf(1001))]);
    afirmar('ORDEM_COLETA_ANEXO_MAX_BYTES=1000: 1000 aceita (201), 1001 => 413', $r['status'] === 201 && $r2['status'] === 413);

    // traversal / nome
    $antes = ocQaArquivos($storage);
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('../../../../../etc/passwd', ocQaPdf(300, 'trav'))]);
    $novos = array_values(array_diff(ocQaArquivos($storage), $antes));
    afirmar('numero "../../..." via HTTP: 201, arquivo SO em ordens_coleta/<cnpj>/ com nome seguro; nada fora da raiz', $r['status'] === 201 && count($novos) === 1 && preg_match('#^ordens_coleta/' . $cnpj . '/[A-Za-z0-9._-]{1,60}_\d{14}\.pdf$#D', $novos[0]) === 1);
    afirmar('resposta nao contem o numero hostil nem o caminho', !str_contains($r['corpo'], 'passwd') && !str_contains($r['corpo'], '..'));
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => json_encode(['cnpj_cliente' => $cnpj, 'numero_ordem_coleta' => 'Ação Çedilha ÿ', 'arquivo_base64' => base64_encode(ocQaPdf(300, 'ac'))], JSON_UNESCAPED_UNICODE)]);
    $gravado = $pdo->query("SELECT numero_ordem_coleta FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta LIKE 'A%o %'")->fetchColumn();
    afirmar('numero com acentos: 201 e numero real preservado so no banco (UTF-8)', $r['status'] === 201 && $gravado === 'Ação Çedilha ÿ');

    // rate limit de falhas por IP
    $ipRl = '198.51.100.77';
    $codigos = [];
    for ($i = 0; $i < 11; $i++) {
        $rr = chamar($cgi, $raiz, $rota, ['chave' => $chave, 'auth' => 'Bearer errada', 'corpo' => $corpo, 'ip' => $ipRl]);
        $codigos[] = $rr['status'];
    }
    afirmar('rate limit: 10 falhas = 401 e a 11a tentativa = 429 MUITAS_TENTATIVAS', array_slice($codigos, 0, 10) === array_fill(0, 10, 401) && $codigos[10] === 429 && ($rr['json']['codigo'] ?? null) === 'MUITAS_TENTATIVAS' && stripos($rr['cabecalhos'], 'Retry-After: 900') !== false);
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpo, 'ip' => $ipRl]);
    afirmar('rate limit: IP bloqueado nem com a chave correta passa (429)', $r['status'] === 429);
    $r = chamar($cgi, $raiz, $rota, $base + ['corpo' => $corpoOk('RL-OK', ocQaPdf(300, 'rl')), 'ip' => '198.51.100.78']);
    afirmar('rate limit: outro IP segue normal (201)', $r['status'] === 201);
    $dirRl = $storage . DIRECTORY_SEPARATOR . 'ordens_coleta_ratelimit';
    afirmar('rate limit: contador fora de ordens_coleta/ e sem o IP no nome', is_dir($dirRl) && !str_contains(implode(' ', glob($dirRl . '/*') ?: []), '198.51.100') && !is_dir($raizOc . DIRECTORY_SEPARATOR . 'ordens_coleta_ratelimit'));

    // storage fora do docroot
    $docroot = realpath($raiz . '/public');
    afirmar('storage de teste fora de public/ (docroot) e nenhum arquivo de OC dentro de public/', !str_starts_with((string) realpath($storage), (string) $docroot) && count(glob($docroot . '/**/*.pdf') ?: []) === 0);

    // logs do CGI
    $log = (string) @file_get_contents($GLOBALS['logCgi']);
    $sent = [$chave, 'HTTP-1', 'passwd', 'AUTH-1', $cnpj, 'JVBER', '%PDF', 'Bearer', 'SQLSTATE', 'qa_oc_storage_', $banco];
    $vazou = [];
    foreach ($sent as $s) {
        if (str_contains($log, $s)) {
            $vazou[] = substr($s, 0, 6);
        }
    }
    afirmar('logs do servidor sem chave, numero, cnpj, caminho, base64 ou mensagem de excecao (' . ($vazou ? implode(',', $vazou) : 'limpo') . ')', $vazou === []);
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada: ' . get_class($e) . ' @' . basename($e->getFile()) . ':' . $e->getLine() . ' ' . substr($e->getMessage(), 0, 160), false);
} finally {
    @unlink($logCgi);
    ocQaLimpar($banco, $storage);
}

echo "\nTotal: $total, falhas: $falhas\n";
exit($falhas === 0 ? 0 : 1);
