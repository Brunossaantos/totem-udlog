<?php

/**
 * F0 da Gestao Totem (2026-10-06): endurecimento da pagina do totem
 * (public/totem/index.php) por php-cgi, contra banco QA descartavel.
 *
 * Cobre: formato do codigo validado ANTES do banco, 404 UNIFORME (inexistente,
 * inativo, malformado) sem citar RECEPCAO-01 nem o formato, rate limit de FALHAS
 * por IP (so falhas contam, o quiosque valido nunca e limitado), IP real atras
 * do Cloudflare (CF-Connecting-IP so com REMOTE_ADDR do Cloudflare, nunca
 * X-Forwarded-For), 429 com Retry-After, 4 cabecalhos, ausencia de CSP, 503 nao
 * conta como falha e NAO-REGRESSAO: o HTML do quiosque e identico ao do HEAD.
 *
 * Uso: php tests/manual/teste_gestao_totem_f0.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

$banco = null;
$storage = null;
$raiz = dirname(__DIR__, 2);
$copiaAntiga = null;

function paginaTotem(array $o): array
{
    return gtChamar(['raiz' => 'totem', 'arquivo' => $o['arquivo'] ?? 'index.php'] + $o);
}

/** Cabecalhos de uma resposta, sem os que variam por natureza (tamanho, data). */
function cabecalhosEstaveis(array $r): array
{
    $c = $r['cabecalhos'];
    unset($c['x-powered-by'], $c['date'], $c['content-length'], $c['retry-after']);
    ksort($c);

    return $c;
}

try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $tokenTotem = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 1)')->execute(['c' => 'RECEPCAO-01', 'n' => 'Totem Recepcao', 't' => $tokenTotem]);
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 0)')->execute(['c' => 'INATIVO-01', 'n' => 'Totem Inativo', 't' => bin2hex(random_bytes(32))]);
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 1)')->execute(['c' => 'NOVO-FORMATO-A1B2C3D4E5F6G7H8', 'n' => 'Totem Futuro', 't' => bin2hex(random_bytes(32))]);
    $dirLimite = $storage . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit';
    $logCgi = gtNovoLogCgi();
    $b = ['logErro' => $logCgi];

    // =====================================================================
    // 1. Quiosque valido: 200, token no HTML, 4 cabecalhos, sem CSP
    // =====================================================================
    $ok = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.1']);
    afirmar('quiosque: ?totem=RECEPCAO-01 continua 200', $ok['status'] === 200);
    afirmar('quiosque: o token do totem continua no HTML (data-totem-token) como antes', str_contains($ok['corpo'], 'data-totem-token="' . $tokenTotem . '"') && str_contains($ok['corpo'], 'data-totem-nome="Totem Recepcao"'));
    afirmar('quiosque: Referrer-Policy no-referrer', gtCabecalho($ok, 'referrer-policy') === 'no-referrer');
    afirmar('quiosque: Cache-Control no-store', gtCabecalho($ok, 'cache-control') === 'no-store');
    afirmar('quiosque: X-Robots-Tag noindex, nofollow', gtCabecalho($ok, 'x-robots-tag') === 'noindex, nofollow');
    afirmar('quiosque: X-Content-Type-Options nosniff', gtCabecalho($ok, 'x-content-type-options') === 'nosniff');
    afirmar('quiosque: SEM Content-Security-Policy (nao quebra o totem)', gtCabecalho($ok, 'content-security-policy') === null);
    afirmar('quiosque: minusculas continuam 200 (collation atual)', paginaTotem($b + ['query' => ['totem' => 'recepcao-01'], 'ip' => '198.51.100.1'])['status'] === 200);
    afirmar('quiosque: formato futuro [A-Z0-9-]{1,64} aceito', paginaTotem($b + ['query' => ['totem' => 'NOVO-FORMATO-A1B2C3D4E5F6G7H8'], 'ip' => '198.51.100.1'])['status'] === 200);

    // nao-regressao: o HTML do quiosque e IDENTICO ao do HEAD (mesmo banco)
    $antigo = shell_exec('git -C ' . escapeshellarg($raiz) . ' show HEAD:public/totem/index.php 2>NUL');
    if (!is_string($antigo) || $antigo === '') {
        $antigo = shell_exec('git -C ' . escapeshellarg($raiz) . ' show HEAD:public/totem/index.php');
    }
    afirmar('git: versao do HEAD de public/totem/index.php disponivel para comparar', is_string($antigo) && str_contains($antigo, 'TermoLgpd'));
    $dirTotem = str_replace('\\', '/', $raiz) . '/public/totem';
    $codigoAntigo = str_replace(
        ["__DIR__ . '/../../vendor/autoload.php'", "__DIR__ . '/../../'", "__DIR__ . '/assets/"],
        [var_export(str_replace('\\', '/', $raiz) . '/vendor/autoload.php', true), var_export(str_replace('\\', '/', $raiz) . '/', true), var_export($dirTotem . '/assets/', true) . " . '"],
        (string) $antigo
    );
    // o ultimo replace deixa "'<dir>/assets/' . 'resto'": reescreve para concatenacao valida
    $codigoAntigo = str_replace("' . '", '', $codigoAntigo);
    $copiaAntiga = $dirTotem . '/_qa_f0_index_antigo.php';
    file_put_contents($copiaAntiga, $codigoAntigo);
    $rAntigo = gtChamar(['raiz' => 'totem', 'arquivo' => '_qa_f0_index_antigo.php', 'query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.1', 'logErro' => $logCgi]);
    @unlink($copiaAntiga);
    $copiaAntiga = null;
    afirmar('nao-regressao: a copia do HEAD tambem responde 200 (comparacao valida)', $rAntigo['status'] === 200 && str_contains($rAntigo['corpo'], 'data-totem-token'));
    afirmar('nao-regressao: o HTML do quiosque e BYTE A BYTE igual ao do HEAD', $rAntigo['corpo'] === $ok['corpo']);

    // =====================================================================
    // 2. 404 uniforme
    // =====================================================================
    $casos = [
        'inexistente' => 'NAOEXISTE-99',
        'inativo' => 'INATIVO-01',
        'inativo minusculo' => 'inativo-01',
        'sem parametro' => null,
        'vazio' => '',
        'aspas e SQL' => "' OR '1'='1",
        'SQL com ponto e virgula' => 'X; DROP TABLE tb_totem',
        'traversal' => '../../etc/passwd',
        '65 caracteres' => str_repeat('A', 65),
        'espaco' => 'RECEPCAO 01',
        'sublinhado' => 'RECEPCAO_01',
        'acentuado' => 'RECEPÇÃO-01',
        'NUL no fim' => "RECEPCAO-01\0",
        'quebra de linha no fim' => "RECEPCAO-01\n",
        'tag' => '<script>alert(1)</script>',
        'prefixo' => 'RECEPCAO-0',
        'sufixo' => 'RECEPCAO-011',
        'array' => ['RECEPCAO-01'],
    ];
    $primeira = null;
    foreach ($casos as $rotulo => $valor) {
        $q = $valor === null ? [] : ['totem' => $valor];
        $r = paginaTotem($b + ['query' => $q, 'ip' => '198.51.100.' . (50 + array_search($rotulo, array_keys($casos), true))]);
        if ($primeira === null) {
            $primeira = $r;
        }
        afirmar("404 uniforme: $rotulo => 404, mesmo corpo e mesmos cabecalhos do primeiro caso", $r['status'] === 404 && $r['corpo'] === $primeira['corpo'] && cabecalhosEstaveis($r) === cabecalhosEstaveis($primeira));
    }
    afirmar('404: corpo NAO cita RECEPCAO-01, ?totem=, "ex:" nem o formato esperado', $primeira !== null && !preg_match('/RECEPCAO|\?totem|ex:|formato|\[A-Z|configurado/i', $primeira['corpo']));
    afirmar('404: os 4 cabecalhos tambem estao presentes na resposta de erro', $primeira !== null && gtCabecalho($primeira, 'referrer-policy') === 'no-referrer' && gtCabecalho($primeira, 'cache-control') === 'no-store' && gtCabecalho($primeira, 'x-robots-tag') === 'noindex, nofollow' && gtCabecalho($primeira, 'x-content-type-options') === 'nosniff');
    afirmar('404: sem Retry-After e sem token na resposta', $primeira !== null && gtCabecalho($primeira, 'retry-after') === null && !str_contains($primeira['corpo'], $tokenTotem));

    // formato validado ANTES do banco: com o banco inacessivel, o malformado e 404 e o bem formado e 503
    $semBanco = ['env' => ['DB_NAME' => 'qa_qr_exclusivo_00000000']];
    $rMal = paginaTotem($b + $semBanco + ['query' => ['totem' => "' OR 1=1"], 'ip' => '198.51.100.90']);
    $rBem = paginaTotem($b + $semBanco + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.90']);
    afirmar('formato ANTES do banco: malformado => 404 mesmo sem banco (nem tenta conectar)', $rMal['status'] === 404 && $rMal['corpo'] === $primeira['corpo']);
    afirmar('503 de infraestrutura: bem formado com banco fora => 503 generico, sem vazar nada', $rBem['status'] === 503 && !preg_match('/qa_qr|SQLSTATE|PDO|localhost|root/i', $rBem['corpo']) && gtCabecalho($rBem, 'cache-control') === 'no-store');
    for ($i = 0; $i < 30; $i++) {
        $rBem = paginaTotem($b + $semBanco + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.91']);
    }
    afirmar('503 NAO conta como falha: 30 pedidos bem formados com o banco fora nunca viram 429', $rBem['status'] === 503);

    // =====================================================================
    // 3. Rate limit de falhas por IP
    // =====================================================================
    $ipA = '203.0.113.10';
    $tipos = ['NAOEXISTE-', "x' OR 1=1", 'INATIVO-01'];
    for ($i = 1; $i <= 19; $i++) {
        $r = paginaTotem($b + ['query' => ['totem' => $i % 3 === 1 ? 'NAOEXISTE-' . $i : ($i % 3 === 2 ? "x' OR 1=1" : 'INATIVO-01')], 'ip' => $ipA]);
        if ($r['status'] !== 404) {
            afirmar("falha $i deveria ser 404", false);
        }
    }
    afirmar('limite: 19 falhas (inexistente, malformado e inativo misturados) ainda respondem 404', $r['status'] === 404);
    // 30 pedidos VALIDOS do quiosque entre as falhas: nao contam
    $todosOk = true;
    for ($i = 0; $i < 30; $i++) {
        $rv = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => $ipA]);
        $todosOk = $todosOk && $rv['status'] === 200;
    }
    afirmar('limite: 30 pedidos VALIDOS do quiosque no mesmo IP (19 falhas acumuladas) seguem 200', $todosOk);
    $r20 = paginaTotem($b + ['query' => ['totem' => 'NAOEXISTE-20'], 'ip' => $ipA]);
    afirmar('limite: a 20a falha ainda e respondida como 404 (os 30 validos nao contaram)', $r20['status'] === 404);
    $r21 = paginaTotem($b + ['query' => ['totem' => 'NAOEXISTE-21'], 'ip' => $ipA]);
    afirmar('limite: a 21a falha => 429 com Retry-After entre 1 e 600', $r21['status'] === 429 && (int) gtCabecalho($r21, 'retry-after') >= 1 && (int) gtCabecalho($r21, 'retry-after') <= 600);
    afirmar('429: corpo uniforme sem citar totem/formato e com os 4 cabecalhos', !preg_match('/RECEPCAO|\?totem|formato|configurado/i', $r21['corpo']) && gtCabecalho($r21, 'cache-control') === 'no-store' && gtCabecalho($r21, 'referrer-policy') === 'no-referrer');
    $rv = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => $ipA]);
    afirmar('limite: IP bloqueado nao consegue nem confirmar um codigo valido (429, o limite protege contra adivinhacao)', $rv['status'] === 429 && !str_contains($rv['corpo'], $tokenTotem));
    afirmar('limite: outro IP continua com 200', paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '203.0.113.11'])['status'] === 200);
    $arquivos = array_map('basename', glob($dirLimite . DIRECTORY_SEPARATOR . '*') ?: []);
    afirmar('limite: contador em pasta propria de STORAGE_PATH, nome = hash (nunca o IP) e conteudo so inicio/falhas', $arquivos !== [] && count(array_filter($arquivos, static fn ($n) => str_contains($n, '203.0.113'))) === 0 && (function () use ($dirLimite, $arquivos) {
        foreach ($arquivos as $a) {
            $c = json_decode((string) file_get_contents($dirLimite . DIRECTORY_SEPARATOR . $a), true);
            if (!is_array($c) || array_keys($c) !== ['inicio', 'falhas']) {
                return false;
            }
        }

        return true;
    })());

    // CF-Connecting-IP forjado de IP nao-Cloudflare nao muda de balde
    $ipF = '203.0.113.20';
    for ($i = 1; $i <= 20; $i++) {
        paginaTotem($b + ['query' => ['totem' => 'NAOEXISTE-' . $i], 'ip' => $ipF, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '9.9.9.' . $i, 'HTTP_X_FORWARDED_FOR' => '8.8.8.' . $i]]);
    }
    $rF = paginaTotem($b + ['query' => ['totem' => 'NAOEXISTE-99'], 'ip' => $ipF, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '9.9.9.250', 'HTTP_X_FORWARDED_FOR' => '8.8.8.250']]);
    afirmar('IP: CF-Connecting-IP e X-Forwarded-For FORJADOS (REMOTE_ADDR nao-Cloudflare) nao evitam o bloqueio', $rF['status'] === 429);
    $rFv = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => $ipF, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '203.0.113.99']]);
    afirmar('IP: bloqueado tambem nao "herda" um IP limpo forjado no CF-Connecting-IP', $rFv['status'] === 429);
    // atras do Cloudflare de verdade
    $edge = '172.64.0.30';
    for ($i = 1; $i <= 20; $i++) {
        paginaTotem($b + ['query' => ['totem' => 'NAOEXISTE-' . $i], 'ip' => $edge, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.201']]);
    }
    $rE1 = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => $edge, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.201']]);
    $rE2 = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => $edge, 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.202']]);
    $rE3 = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => $edge, 'cabecalhos' => ['HTTP_X_FORWARDED_FOR' => '198.51.100.203']]);
    afirmar('IP: atras do Cloudflare o balde e o IP do cliente (201 bloqueado, 202 livre)', $rE1['status'] === 429 && $rE2['status'] === 200);
    afirmar('IP: atras do Cloudflare sem CF-Connecting-IP vale o REMOTE_ADDR (X-Forwarded-For ignorado) e o edge nao esta bloqueado', $rE3['status'] === 200);
    // IPv6 do Cloudflare
    $rV6 = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '2606:4700::7', 'cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '198.51.100.202']]);
    afirmar('IP: REMOTE_ADDR IPv6 do Cloudflare + CF-Connecting-IP IPv4 (cliente 202 livre) => 200', $rV6['status'] === 200);

    // pagina continua servindo o mesmo HTML apos o bloqueio de outros IPs
    $rFim = paginaTotem($b + ['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.1']);
    afirmar('quiosque: continua 200 e identico ao HEAD no fim da bateria', $rFim['status'] === 200 && $rFim['corpo'] === $rAntigo['corpo']);
    $logTxt = is_file($logCgi) ? (string) file_get_contents($logCgi) : '';
    afirmar('captura de log funciona (o 503 por banco fora gravou a linha fixa de Conexao)', str_contains($logTxt, 'Conexao::obter: falha ao conectar ao banco de dados'));
    afirmar('logs: sem IP, token, codigo de totem nem erro fatal', !str_contains($logTxt, '203.0.113') && !str_contains($logTxt, '198.51.100') && !str_contains($logTxt, $tokenTotem) && !str_contains($logTxt, 'RECEPCAO') && preg_match('/(Fatal|Uncaught|Warning:|Notice:|Stack trace)/i', $logTxt) !== 1);
    @unlink($logCgi);

    // arquivo da pagina nao altera nada alem do pedido (git)
    $alterados = (string) shell_exec('git -C ' . escapeshellarg($raiz) . ' status --porcelain -- util/Auth.php public/totem/assets app/Dao/TotemDao.php');
    afirmar('escopo F0: Util\Auth, assets do totem, TotemDao NAO foram alterados', trim($alterados) === '');
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ')', false);
} finally {
    if ($copiaAntiga !== null) {
        @unlink($copiaAntiga);
    }
    gtDestruirAmbiente($banco, $storage);
}
exit(gtResumo('teste_gestao_totem_f0'));
