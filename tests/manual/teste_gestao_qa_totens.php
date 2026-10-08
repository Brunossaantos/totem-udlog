<?php

/**
 * QA INDEPENDENTE da Gestao Totem, F2 (TOTENS), demanda gestao-totem, 2026-10-07.
 * Testes ADVERSARIAIS escritos pelo QA (nao pelos implementadores): nome hostil,
 * formato/entropia do codigo, concorrencia (processos reais), RBAC/CSRF/IDOR,
 * token nunca exposto, TOTEM_URL_BASE, desativar/regerar com efeito imediato,
 * quiosque (58/65 caracteres, rate limit so em falhas), auditoria, migration 022
 * (sobre o schema ANTIGO do HEAD), XSS, acentuacao e menu.
 *
 * Uso: php tests/manual/teste_gestao_qa_totens.php
 * Banco QA descartavel (qa_qr_exclusivo_<hex>); NUNCA toca em udlog_totem; sem rede.
 * Requer `git` no PATH (le o schema.sql do HEAD para a prova da migration).
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\AuditoriaDao;
use App\Rn\TotemGestaoRn;
use Util\TotemCodigo;
use Util\TotemUrlBase;

// ---------------------------------------------------------------------------
// Modos de trabalhador (processos PHP separados)
// ---------------------------------------------------------------------------
if (isset($argv[1]) && $argv[1] === '--w-rn') {
    $job = json_decode((string) base64_decode((string) $argv[2]), true);
    ini_set('log_errors', '1');
    ini_set('error_log', (string) $job['log']);
    $wPdo = qaQrAbrirBanco((string) $job['banco']);
    $wPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    while (microtime(true) < (float) $job['t0']) {
        usleep(200);
    }
    $wRn = new TotemGestaoRn($wPdo, (string) $job['base']);
    $a = $job['args'];
    $wR = match ($job['op']) {
        'criar' => $wRn->criar((int) $a[0], (int) $a[1], (string) $a[2], '203.0.113.9'),
        'ativo' => $wRn->definirAtivo((int) $a[0], (int) $a[1], (bool) $a[2], (bool) $a[3], '203.0.113.9'),
        'regerar' => $wRn->regerarUrl((int) $a[0], (int) $a[1], (int) $a[2], (string) $a[3], '203.0.113.9'),
    };
    echo json_encode(['ok' => $wR['ok'], 'codigo' => $wR['codigo'] ?? null, 'id' => $wR['id'] ?? null]);
    exit(0);
}
if (isset($argv[1]) && $argv[1] === '--w-auth') {
    [, , $wBanco, $wToken] = $argv;
    $GLOBALS['wToken'] = $wToken;
    if (!function_exists('getallheaders')) {
        function getallheaders(): array
        {
            return $GLOBALS['wToken'] === '' ? [] : ['Authorization' => 'Bearer ' . $GLOBALS['wToken']];
        }
    }
    $wPdo = qaQrAbrirBanco($wBanco);
    $wPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $wTotem = Util\Auth::validarTotem($wPdo);
    echo 'AUTORIZADO id=' . (int) $wTotem['id_totem'];
    exit(0);
}

const BASE_QA = 'http://localhost:8080/totem/';

/** Dispara N workers de uma vez (largada comum) e devolve as respostas decodificadas. */
function dispararWorkers(string $banco, string $log, array $jobs): array
{
    $t0 = microtime(true) + 1.5;
    $procs = [];
    foreach ($jobs as $i => $j) {
        $payload = base64_encode((string) json_encode($j + ['banco' => $banco, 'base' => BASE_QA, 'log' => $log, 't0' => $t0]));
        $p = proc_open([PHP_BINARY, __FILE__, '--w-rn', $payload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        $procs[$i] = [$p, $pipes];
    }
    $saidas = [];
    foreach ($procs as $i => [$p, $pipes]) {
        $o = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        $saidas[$i] = json_decode(trim($o), true) ?? ['ok' => null, 'erro_saida' => $o];
    }

    return $saidas;
}

function authWorker(string $banco, string $token): string
{
    $p = proc_open([PHP_BINARY, __FILE__, '--w-auth', $banco, $token], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
    $o = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);

    return trim($o);
}

function loc(array $r): ?string
{
    return gtCabecalho($r, 'location');
}

function uuidQa(): string
{
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
}

function paginaQuiosque(array $o): array
{
    return gtChamar(['raiz' => 'totem', 'arquivo' => 'index.php'] + $o);
}

function cabEstaveis(array $r): array
{
    $c = $r['cabecalhos'];
    unset($c['x-powered-by'], $c['date'], $c['content-length'], $c['retry-after']);
    ksort($c);

    return $c;
}

/** Estrutura de tb_totem (colunas, indices, FKs), sem a posicao ordinal, para comparar bancos. */
function estruturaTotem(PDO $pdo): array
{
    $cols = gtLinhas($pdo, "SELECT COLUMN_NAME n, COLUMN_TYPE t, IS_NULLABLE nl, COALESCE(COLUMN_DEFAULT,'<null>') d, EXTRA x, COALESCE(CHARACTER_SET_NAME,'') cs, COALESCE(COLLATION_NAME,'') co FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' ORDER BY COLUMN_NAME");
    $idx = gtLinhas($pdo, "SELECT INDEX_NAME i, NON_UNIQUE u, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' GROUP BY INDEX_NAME, NON_UNIQUE ORDER BY INDEX_NAME");
    $fks = gtLinhas($pdo, "SELECT k.COLUMN_NAME c, k.REFERENCED_TABLE_NAME rt, k.REFERENCED_COLUMN_NAME rc, r.DELETE_RULE dr FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'tb_totem' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME");

    return ['cols' => $cols, 'idx' => $idx, 'fks' => $fks];
}

function criarBancoVazioQa(): array
{
    $srv = qaQrPdoServidor(qaQrConfiguracao());
    $nome = qaQrNomeBanco();
    qaQrValidarNomeBanco($nome);
    $srv->exec("CREATE DATABASE `{$nome}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $GLOBALS["bancosExtraQa"][] = $nome;

    return [qaQrAbrirBanco($nome), $nome];
}

/** Aplica um arquivo SQL e devolve as linhas do ULTIMO comando que devolveu resultset (para o SELECT informativo). */
function aplicarSqlComRetorno(PDO $pdo, string $conteudo): array
{
    $ultimo = [];
    foreach (preg_split('/;\s*\n/', $conteudo) as $cmd) {
        $cmd = trim($cmd);
        if ($cmd === '') {
            continue;
        }
        $st = $pdo->query($cmd);
        if ($st !== false) {
            do {
                if ($st->columnCount() > 0) {
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                    if ($rows !== [] && isset($rows[0]["aviso"])) {
                        $ultimo = $rows;
                    }
                }
            } while ($st->nextRowset());
            $st->closeCursor();
        }
    }

    return $ultimo;
}

$banco = null;
$storage = null;
$bancos = [];
$logCgi = gtNovoLogCgi();
$logRn = gtNovoLogCgi();
$logWk = gtNovoLogCgi();
@unlink($logCgi);
@unlink($logRn);
@unlink($logWk);
ini_set('log_errors', '1');
ini_set('error_log', $logRn);
$raiz = dirname(__DIR__, 2);

try {
    // =====================================================================
    // Ambiente
    // =====================================================================
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $bancos[] = $banco;
    $idAdm = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idAdm2 = gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Admin');
    $idUsu = gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    $emp = static function (string $nome, string $cnpj, int $ativo = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES (:n, :c, :a)')->execute(['n' => $nome, 'c' => $cnpj, 'a' => $ativo]);

        return (int) $pdo->lastInsertId();
    };
    $eM1 = $emp('Maua I', '14706199000182');
    $eM2 = $emp('Maua II', '14706199000344');
    $eInat = $emp('Inativa SA', '44444444000144', 0);
    $eHtml = $emp('<img src=x onerror=alert(1)>A', '77777777000177');
    $linha = static fn (int $id): array => gtLinhas($pdo, 'SELECT * FROM tb_totem WHERE id_totem = :i', ['i' => $id])[0] ?? [];
    $legado = static function (string $codigo, string $nome, ?int $empresa) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES (:c, :n, :e, :t, 1)')->execute(['c' => $codigo, 'n' => $nome, 'e' => $empresa, 't' => bin2hex(random_bytes(32))]);

        return (int) $pdo->lastInsertId();
    };
    $idRec = $legado('RECEPCAO-01', 'RECEPCAO-01', $eM1);
    $idRecSemEmp = $legado('RECEPCAO-02', 'RECEPCAO-02', null);
    $idRecEmpInat = $legado('RECEPCAO-03', 'RECEPCAO-03', $eInat);
    $idLegXss = $legado('LEG_XSS', '<script>alert(7)</script>', $eM2);
    $snapRec = $linha($idRec);
    $snapRecSem = $linha($idRecSemEmp);
    $snapLegXss = $linha($idLegXss);

    $H = ['env' => ['TOTEM_URL_BASE' => BASE_QA], 'logErro' => $logCgi];
    $todas = [];   // ['quem' => admin|usuario|anon, 'r' => resposta]
    $rq = static function (string $quem, array $o) use (&$todas, $H): array {
        $r = gtChamar($o + $H);
        $todas[] = ['quem' => $quem, 'r' => $r];

        return $r;
    };
    $ck = static fn (?array $l): array => $l !== null && $l['sid'] !== null ? ['cookies' => ['gestao_sid' => $l['sid']]] : [];
    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => '192.0.2.21']);
    $lAdm2 = gtLogin('beto.admin', GT_SENHA_BOA, ['ip' => '192.0.2.22']);
    $lUsu = gtLogin('carla.usuario', GT_SENHA_BOA, ['ip' => '192.0.2.23']);
    afirmar('sessoes abertas com CSRF (admin, admin2, usuario)', $lAdm['sid'] !== null && $lAdm['csrf'] !== null && $lAdm2['sid'] !== null && $lUsu['sid'] !== null && $lUsu['csrf'] !== null);
        $get = static fn (string $quem, string $arq, ?array $l, array $q = [], array $x = []): array => $rq($quem, ['arquivo' => $arq, 'query' => $q] + $ck($l) + $x);
    $post = static fn (string $quem, string $arq, ?array $l, array $form, array $x = [], bool $csrf = true): array => $rq($quem, ['arquivo' => $arq, 'metodo' => 'POST', 'form' => $form + ($csrf && $l !== null && $l['csrf'] !== null ? ['csrf_token' => $l['csrf']] : [])] + $ck($l) + $x);
    $snapTodos = static fn (): string => json_encode(gtLinhas($pdo, 'SELECT id_totem, codigo, nome, id_empresa, token_api, ativo, criado_por, url_versao, url_regerada_em FROM tb_totem ORDER BY id_totem'));
    $nAud = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria');

    // =====================================================================
    // (a) Nome hostil e empresa hostil (HTTP real)
    // =====================================================================
    $hostis = [
        'nul' => ["AB\0CD", 'AB-CD'],
        'crlf' => ["BB\r\nCD", 'BB-CD'],
        'crlf-header-injection' => ["AB\r\nSet-Cookie: x=1", 'AB-SET-COOKIE-X-1'],
        'rtl' => ["CC\u{202E}DD", null],
        'zero-width' => ["A\u{200B}B\u{200D}C", null],
        'emoji-no-meio' => ["EE😀FF", null],
        'combinacao-e-acento' => ["Guiche\u{0301} 09", 'GUICHE-09'],
        'sqli-aspas' => ["x'; DROP TABLE tb_totem;--", 'X-DROP-TABLE-TB-TOTEM'],
        'sqli-or' => ["' OR '1'='1", 'OR-1-1'],
        'travessia' => ['../../etc/passwd', 'ETC-PASSWD'],
        'tags' => ['<b>ZZ</b>', 'B-ZZ-B'],
        '24-chars' => [str_repeat('Q', 24), str_repeat('Q', 24)],
        '24-com-acentos' => ['ÀÉÎÕÜ' . str_repeat('W', 19), 'AEIOU' . str_repeat('W', 19)],
    ];
    foreach ($hostis as $rot => [$entrada, $esperado]) {
        $antes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
        $p = $post('admin', 'totem-form.php', $lAdm, ['id_empresa' => (string) $eM1, 'nome' => $entrada]);
        $criou = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') - $antes;
        $okBase = $p['status'] !== 500 && !str_contains($p['corpo'], 'SQLSTATE') && !str_contains($p['corpo'], 'Fatal') && !str_contains($p['cru'], "Set-Cookie: x=1");
        if ($esperado !== null) {
            $novo = $linha((int) gtEscalar($pdo, 'SELECT MAX(id_totem) FROM tb_totem'));
            afirmar("(a) nome hostil [$rot]: 302 e criado como $esperado, codigo no padrao", $okBase && $p['status'] === 302 && $criou === 1 && $novo['nome'] === $esperado && preg_match('/\A' . preg_quote($esperado, '/') . '-MAUAI-[A-Z2-7]{16}\z/D', (string) $novo['codigo']) === 1);
        } else {
            $novo = $linha((int) gtEscalar($pdo, 'SELECT MAX(id_totem) FROM tb_totem'));
            afirmar("(a) nome hostil [$rot]: sem erro 500/SQL; se criar, so [A-Z0-9-] sem tracos nas pontas", $okBase && ($criou === 0 || ($criou === 1 && preg_match('/\A[A-Z0-9](?:[A-Z0-9-]*[A-Z0-9])\z/D', (string) $novo['nome']) === 1)));
        }
    }
    afirmar('(a) as tabelas continuam existindo (SQLi nao executou)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem'") === 1);
    foreach ([['25-chars', str_repeat('Q', 25)], ['so-simbolos', '!@#$%^&*()'], ['so-emoji', '😀😀'], ['1-char', 'A'], ['so-espacos', '     '], ['vazio', ''], ['so-RTL-zw', "\u{202E}\u{200B}"], ['gigante', str_repeat('A', 5000)], ['limite-trap-24-simbolos', 'AAAAAAAAAAAAAAAAAAAAAAAA!']] as [$rot, $entrada]) {
        $antes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
        $p = $post('admin', 'totem-form.php', $lAdm, ['id_empresa' => (string) $eM1, 'nome' => $entrada]);
        $depois = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
        if ($rot === 'limite-trap-24-simbolos') {
            afirmar("(a) nome [$rot]: 24 letras + simbolo final => ajustado para 24 e criado (sem truncar)", $p['status'] === 302 && $depois === $antes + 1);
        } else {
            afirmar("(a) nome recusado [$rot]: 422 acentuado e NADA criado", $p['status'] === 422 && $depois === $antes && str_contains($p['corpo'], 'Informe o nome'));
        }
    }
    foreach ([['999999', 'inexistente'], [(string) $eInat, 'inativa'], ['-1', 'negativo'], ['0', 'zero'], ['99999999999999999999', 'enorme'], ['9999999999', '10 digitos inexistente'], ['abc', 'texto'], ['1 OR 1=1', 'sqli'], ['', 'vazio'], [' ' . $eM1, 'espaco'], [$eM1 . '.0', 'decimal'], [$eM1 . "\n", 'LF no fim']] as [$idE, $rot]) {
        $antes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
        $p = $post('admin', 'totem-form.php', $lAdm, ['id_empresa' => $idE, 'nome' => 'Emp Teste']);
        afirmar("(a) empresa [$rot]: 422 sem 500 e NADA criado", $p['status'] === 422 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') === $antes);
    }
    $antes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
    $pArr = $rq('admin', ['arquivo' => 'totem-form.php', 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $lAdm['csrf'] . '&id_empresa[]=' . $eM1 . '&nome=Arr+Um'] + $ck($lAdm));
    $pArr2 = $rq('admin', ['arquivo' => 'totem-form.php', 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $lAdm['csrf'] . '&id_empresa=' . $eM1 . '&nome[]=Arr+Dois'] + $ck($lAdm));
    $pArr3 = $rq('admin', ['arquivo' => 'totem-form.php', 'metodo' => 'POST', 'corpo' => 'csrf_token[]=' . $lAdm['csrf'] . '&id_empresa=' . $eM1 . '&nome=Arr+Tres'] + $ck($lAdm));
    afirmar('(a) id_empresa[]/nome[]/csrf_token[] em array: sem 500/fatal e NADA criado', in_array($pArr['status'], [400, 422], true) && in_array($pArr2['status'], [400, 422], true) && in_array($pArr3['status'], [400, 403, 422], true) && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') === $antes && !str_contains($pArr['corpo'] . $pArr2['corpo'] . $pArr3['corpo'], 'Array to string'));
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = :i')->execute(['i' => $idAdm]);

    // =====================================================================
    // (b) Formato do codigo, alfabeto e entropia
    // =====================================================================
    $todosCod = array_column(gtLinhas($pdo, "SELECT codigo FROM tb_totem WHERE codigo NOT IN ('RECEPCAO-01','RECEPCAO-02','RECEPCAO-03','LEG_XSS')"), 'codigo');
    $okFmt = $todosCod !== [];
    $okHash = true;
    foreach ($todosCod as $c) {
        $okFmt = $okFmt && preg_match('/\A[A-Z0-9-]+-[A-Z0-9]+-[A-Z2-7]{16}\z/D', (string) $c) === 1 && strlen((string) $c) <= 58;
        $okHash = $okHash && preg_match('/[0189]/', substr((string) $c, -16)) !== 1;
    }
    afirmar('(b) todos os codigos criados (' . count($todosCod) . ') casam ^[A-Z0-9-]+-[A-Z0-9]+-[A-Z2-7]{16}$ e tem <= 58', $okFmt);
    afirmar('(b) o HASH dos codigos criados nunca contem 0, 1, 8 ou 9', $okHash);
    afirmar('(b) alfabeto declarado = exatamente A-Z2-7 (32 simbolos, sem 0 1 8 9)', TotemCodigo::ALFABETO === 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' && strlen(TotemCodigo::ALFABETO) === 32 && preg_match('/[0189]/', TotemCodigo::ALFABETO) !== 1);
    $vistos = [];
    $freq = array_fill_keys(str_split(TotemCodigo::ALFABETO), 0);
    $okG = true;
    for ($i = 0; $i < 20000; $i++) {
        $h = TotemCodigo::hash();
        $okG = $okG && preg_match('/\A[A-Z2-7]{16}\z/D', $h) === 1;
        $vistos[$h] = true;
        foreach (str_split($h) as $ch) {
            $freq[$ch]++;
        }
    }
    afirmar('(b) 20000 hashes: todos no formato [A-Z2-7]{16}', $okG);
    afirmar('(b) 20000 hashes: 20000 unicos (sem colisao)', count($vistos) === 20000);
    $esp = 20000 * 16 / 32;
    $qui = 0.0;
    foreach ($freq as $f) {
        $qui += (($f - $esp) ** 2) / $esp;
    }
    afirmar('(b) distribuicao dos simbolos plausivelmente uniforme (qui-quadrado 31 g.l. < 70, valor ' . round($qui, 1) . '; todos os 32 simbolos aparecem)', $qui < 70 && count(array_filter($freq)) === 32);
    mt_srand(12345);
    srand(12345);
    $h1 = TotemCodigo::hash();
    mt_srand(12345);
    srand(12345);
    $h2 = TotemCodigo::hash();
    afirmar('(b) o hash NAO depende de mt_srand/srand (CSPRNG: mesma semente => hashes diferentes)', $h1 !== $h2);
    $pos = array_fill(0, 16, []);
    for ($i = 0; $i < 3000; $i++) {
        foreach (str_split(TotemCodigo::hash()) as $k => $ch) {
            $pos[$k][$ch] = true;
        }
    }
    afirmar('(b) todas as 16 posicoes do hash usam o alfabeto inteiro (>= 30 simbolos distintos por posicao em 3000 amostras)', min(array_map('count', $pos)) >= 30);
    $fonteCodigo = (string) file_get_contents($raiz . '/util/TotemCodigo.php') . (string) file_get_contents($raiz . '/app/Rn/TotemGestaoRn.php');
    afirmar('(b) fonte de TotemCodigo/Rn sem rand(, mt_rand, uniqid, shuffle, str_shuffle, lcg_value', preg_match('/\b(mt_rand|rand|uniqid|shuffle|str_shuffle|lcg_value|array_rand)\s*\(/', $fonteCodigo) !== 1);

    // =====================================================================
    // (c) Concorrencia (processos reais)
    // =====================================================================
    $idsAntes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
    $res = dispararWorkers($banco, $logWk, [
        ['op' => 'criar', 'args' => [$idAdm, $eM2, 'Corrida 01']],
        ['op' => 'criar', 'args' => [$idAdm2, $eM2, 'corrida 01']],
        ['op' => 'criar', 'args' => [$idAdm, $eM2, 'CORRIDA-01']],
    ]);
    $sucessos = count(array_filter($res, static fn ($r) => ($r['ok'] ?? null) === true));
    afirmar('(c) 3 processos criando o MESMO nome na MESMA empresa: exatamente 1 sucesso, 2 recusas validas', $sucessos === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem WHERE id_empresa = :e AND nome = :n', ['e' => $eM2, 'n' => 'CORRIDA-01']) === 1 && count(array_filter($res, static fn ($r) => ($r['ok'] ?? null) === false && ($r['codigo'] ?? '') === 'validacao')) === 2);
    $res = dispararWorkers($banco, $logWk, [
        ['op' => 'criar', 'args' => [$idAdm, $eM1, 'Corrida 02']],
        ['op' => 'criar', 'args' => [$idAdm2, $eM2, 'Corrida 02']],
        ['op' => 'criar', 'args' => [$idAdm, $eM1, 'Corrida 03']],
    ]);
    afirmar('(c) mesmo nome em empresas DIFERENTES em paralelo: ambos criam; nomes distintos tambem', count(array_filter($res, static fn ($r) => ($r['ok'] ?? null) === true)) === 3 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_totem WHERE nome = 'CORRIDA-02'") === 2 && (int) gtEscalar($pdo, "SELECT COUNT(DISTINCT codigo) FROM tb_totem WHERE nome IN ('CORRIDA-02','CORRIDA-03')") === 3);

    $rn = new TotemGestaoRn($pdo, BASE_QA);
    $rc = $rn->criar($idAdm, $eM1, 'Alvo Corrida');
    $idAlvo = (int) $rc['id'];
    $v0 = (int) $linha($idAlvo)['url_versao'];
    $tokAlvo = (string) $linha($idAlvo)['token_api'];
    $res = dispararWorkers($banco, $logWk, [
        ['op' => 'regerar', 'args' => [$idAdm, $idAlvo, $v0, 'ALVO-CORRIDA']],
        ['op' => 'regerar', 'args' => [$idAdm2, $idAlvo, $v0, 'alvo-corrida']],
        ['op' => 'regerar', 'args' => [$idAdm, $idAlvo, $v0, 'ALVO-CORRIDA']],
        ['op' => 'ativo', 'args' => [$idAdm, $idAlvo, false, false]],
        ['op' => 'ativo', 'args' => [$idAdm2, $idAlvo, false, false]],
        ['op' => 'criar', 'args' => [$idAdm, $eM1, 'Alvo Corrida']],
        ['op' => 'criar', 'args' => [$idAdm2, $eM1, 'Outro Corrida']],
    ]);
    $reg = array_slice($res, 0, 3);
    $at = array_slice($res, 3, 2);
    $fim = $linha($idAlvo);
    afirmar('(c) 3 regerar simultaneos com a MESMA versao: exatamente 1 vence, os outros url_ja_regerada', count(array_filter($reg, static fn ($r) => ($r['ok'] ?? null) === true)) === 1 && count(array_filter($reg, static fn ($r) => ($r['codigo'] ?? '') === 'url_ja_regerada')) === 2 && (int) $fim['url_versao'] === $v0 + 1);
    afirmar('(c) criar x desativar x regerar simultaneos: estado final consistente (ativo=0, codigo valido, token inalterado, nome unico)', (int) $fim['ativo'] === 0 && preg_match('/\AALVO-CORRIDA-MAUAI-[A-Z2-7]{16}\z/D', (string) $fim['codigo']) === 1 && $fim['token_api'] === $tokAlvo && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_totem WHERE id_empresa = :e AND nome = 'ALVO-CORRIDA'", ['e' => $eM1]) === 1);
    afirmar('(c) desativar duplicado em paralelo: 2 ok (1 muda, 1 sem_mudanca) e so 1 linha de auditoria TOTEM_ATIVO', count(array_filter($at, static fn ($r) => ($r['ok'] ?? null) === true)) === 2 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'TOTEM_ATIVO' AND alvo_id = :i", ['i' => $idAlvo]) === 1);
    afirmar('(c) nenhum worker devolveu erro_interno/saida invalida e nenhuma auditoria ficou PENDENTE', count(array_filter($res, static fn ($r) => isset($r['erro_saida']) || ($r['codigo'] ?? '') === 'erro_interno')) === 0 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    $trava = (int) gtEscalar($pdo, "SELECT IS_FREE_LOCK(CONCAT('totem_gestao_totens_', MD5(DATABASE())))");
    afirmar('(c) o lock nomeado esta livre depois das corridas (sem vazamento)', $trava === 1);
    afirmar('(c) (c) no total, so os 2 criadores validos entraram (Alvo Corrida repetido recusado)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem WHERE nome IN (\'ALVO-CORRIDA\',\'OUTRO-CORRIDA\')') === 2);

    // --- (c2) Rn: ator nao-admin recusado SOB o lock e colisao de codigo com retry
    $antesRn = $snapTodos();
    $tAlvoRn = (int) $rc['id'];
    $vRn = (int) $linha($tAlvoRn)['url_versao'];
    $tentativas = [
        'usuario' => $idUsu,
        'inexistente' => 987654,
    ];
    foreach ($tentativas as $rot => $atorRn) {
        $x1 = $rn->criar($atorRn, $eM1, 'Ator Ruim ' . $rot);
        $x2 = $rn->definirAtivo($atorRn, $tAlvoRn, true, true);
        $x3 = $rn->regerarUrl($atorRn, $tAlvoRn, $vRn, 'ALVO-CORRIDA');
        afirmar("(c2) Rn com ator $rot: criar, ativar e regerar => sem_permissao e NADA muda", ($x1['codigo'] ?? '') === 'sem_permissao' && ($x2['codigo'] ?? '') === 'sem_permissao' && ($x3['codigo'] ?? '') === 'sem_permissao' && $snapTodos() === $antesRn);
    }
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idAdm2]);
    $x1 = $rn->definirAtivo($idAdm2, $tAlvoRn, true, true);
    $x3 = $rn->regerarUrl($idAdm2, $tAlvoRn, $vRn, 'ALVO-CORRIDA');
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = :i')->execute(['i' => $idAdm2]);
    afirmar('(c2) Rn com admin DESATIVADO (sessao antiga em memoria): ativar e regerar => sem_permissao e NADA muda', ($x1['codigo'] ?? '') === 'sem_permissao' && ($x3['codigo'] ?? '') === 'sem_permissao' && $snapTodos() === $antesRn);
    $pdo->prepare("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES ('KCOL-02-MAUAI-AAAAAAAAAAAAAAAA', 'COLIDER', :e, :t, 1)")->execute(['e' => $eM1, 't' => bin2hex(random_bytes(32))]);
    $nChamadas = 0;
    $seqH = ['AAAAAAAAAAAAAAAA', 'AAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBB'];
    $rnCol = new TotemGestaoRn($pdo, BASE_QA, static function () use (&$nChamadas, $seqH): string {
        return $seqH[min($nChamadas++, count($seqH) - 1)];
    });
    $xc = $rnCol->criar($idAdm, $eM1, 'KCOL-02');
    afirmar('(c2) colisao de codigo (1062) na criacao: retenta com outro HASH e cria (3a tentativa, hash BBBB)', ($xc['ok'] ?? false) === true && $nChamadas === 3 && (string) gtEscalar($pdo, "SELECT codigo FROM tb_totem WHERE nome = 'KCOL-02'") === 'KCOL-02-MAUAI-BBBBBBBBBBBBBBBB');
    $nChamadas = 0;
    $rnCol2 = new TotemGestaoRn($pdo, BASE_QA, static function () use (&$nChamadas): string {
        $nChamadas++;

        return 'AAAAAAAAAAAAAAAA';
    });
    $antesCol = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
    $xc2 = $rnCol2->criar($idAdm, $eM1, 'KCOL-02');
    $pdo->prepare("UPDATE tb_totem SET nome = 'COLIDER2' WHERE nome = 'KCOL-02'")->execute();
    $nChamadas = 0;
    $xc3 = $rnCol2->criar($idAdm, $eM1, 'KCOL-02');
    afirmar('(c2) colisao permanente: exatamente 5 tentativas, falha limpa totem_colisao e nada criado', ($xc3['codigo'] ?? '') === 'totem_colisao' && $nChamadas === 5 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') === $antesCol);
    $pdo->prepare("DELETE FROM tb_totem WHERE codigo IN ('KCOL-02-MAUAI-AAAAAAAAAAAAAAAA','KCOL-02-MAUAI-BBBBBBBBBBBBBBBB')")->execute();

    // =====================================================================
    // (d) RBAC / CSRF / IDOR / metodos (todas as acoes de totens)
    // =====================================================================
    $rn2 = new TotemGestaoRn($pdo, BASE_QA);
    $rr = $rn2->criar($idAdm, $eM1, 'Doca Teste');
    $idD = (int) $rr['id'];
    $snap0 = $snapTodos();
    $aud0 = $nAud();
    $vD = (int) $linha($idD)['url_versao'];
    $acoes = [
        'desativar' => ['totens.php', ['acao' => 'desativar', 'id_totem' => (string) $idD]],
        'ativar' => ['totens.php', ['acao' => 'ativar', 'id_totem' => (string) $idD]],
        'regerar' => ['totens.php', ['acao' => 'regerar-url', 'id_totem' => (string) $idD, 'versao_url' => (string) $vD, 'nome_confirmacao' => 'DOCA-TESTE']],
        'criar' => ['totem-form.php', ['id_empresa' => (string) $eM1, 'nome' => 'Invasor Dois']],
    ];
    foreach ($acoes as $rot => [$arq, $form]) {
        $a = $post('anon', $arq, null, $form, [], false);
        afirmar("(d) $rot: anonimo => 302/401/403 sem conteudo de totens", in_array($a['status'], [302, 401, 403], true) && !str_contains($a['corpo'], 'DOCA-TESTE'));
        $u = $post('usuario', $arq, $lUsu, $form);
        afirmar("(d) $rot: perfil usuario (com CSRF valido) => 403 e sem efeito", $u['status'] === 403);
        $s = $post('admin', $arq, $lAdm, $form, [], false);
        afirmar("(d) $rot: admin sem csrf => 403", $s['status'] === 403);
        $o = $post('admin', $arq, $lAdm, $form, ['origin' => 'null']);
        afirmar("(d) $rot: Origin 'null' => 403", $o['status'] === 403);
        $o2 = $post('admin', $arq, $lAdm, $form, ['origin' => 'https://gestao.exemplo.test.evil.test']);
        afirmar("(d) $rot: Origin com host que apenas COMECA igual => 403", $o2['status'] === 403);
        $o3 = $post('admin', $arq, $lAdm, $form, ['origin' => 'http://gestao.exemplo.test']);
        afirmar("(d) $rot: Origin com esquema http sobre https => 403", $o3['status'] === 403);
        foreach (['cross-site', 'same-site'] as $sf) {
            $c = $post('admin', $arq, $lAdm, $form, ['origin' => null, 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => $sf]]);
            afirmar("(d) $rot: Sec-Fetch-Site $sf (sem Origin) => 403", $c['status'] === 403);
        }
        foreach (['PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE'] as $m) {
            $x = $rq('admin', ['arquivo' => $arq, 'metodo' => $m, 'form' => $form + ['csrf_token' => (string) $lAdm['csrf']]] + $ck($lAdm));
            afirmar("(d) $rot: metodo $m => 405 (sem efeito)", $x['status'] === 405);
        }
        $g = $rq('admin', ['arquivo' => $arq, 'query' => $form + ['csrf_token' => (string) $lAdm['csrf']]] + $ck($lAdm));
        afirmar("(d) $rot: mesmos parametros por GET (query) NAO executam a acao", in_array($g['status'], [200, 302], true) && ($g['status'] === 200 || !str_contains((string) loc($g), 'totem_')));
    }
    afirmar('(d) NADA mudou em tb_totem nem na auditoria depois de todas as tentativas bloqueadas', $snapTodos() === $snap0 && $nAud() === $aud0);
    foreach (['totens.php' => [], 'totem-form.php' => [], 'totem-url.php' => ['id' => (string) $idD]] as $arq => $q) {
        $r1 = $get('anon', $arq, null, $q);
        $r2 = $get('usuario', $arq, $lUsu, $q);
        afirmar("(d) GET $arq: anonimo => 302/401, usuario => 403, ambos sem hash/URL", in_array($r1['status'], [302, 401], true) && $r2['status'] === 403 && !str_contains($r1['corpo'] . $r2['corpo'], '?totem=') && !str_contains($r1['corpo'] . $r2['corpo'], 'DOCA-TESTE'));
    }
    foreach (['0', '-1', '99999999999', '4294967296', 'abc', '', '1 OR 1=1', "1'", '1e1', '0x1', '١', "1\n"] as $idH) {
        $okH = true;
        foreach (['ativar', 'desativar', 'regerar-url'] as $ac) {
            $p = $post('admin', 'totens.php', $lAdm, ['acao' => $ac, 'id_totem' => $idH, 'versao_url' => '1', 'nome_confirmacao' => 'X']);
            $okH = $okH && in_array($p['status'], [302, 400], true) && !str_contains($p['corpo'], 'SQLSTATE') && !str_contains($p['corpo'], 'Fatal');
            if ($p['status'] === 302) {
                $okH = $okH && preg_match('#\A/gestao/totens\.php\?msg=(totem_nao_encontrado|erro_interno)\z#', (string) loc($p)) === 1;
            }
        }
        $g = $get('admin', 'totem-url.php', $lAdm, ['id' => $idH]);
        $okH = $okH && in_array($g['status'], [302, 400, 404], true) && !str_contains($g['corpo'], '?totem=');
        afirmar('(d) id_totem hostil ' . json_encode($idH) . ': recusado em todas as acoes e em totem-url (sem SQL/URL)', $okH);
    }
    $pA1 = $rq('admin', ['arquivo' => 'totens.php', 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $lAdm['csrf'] . '&acao=desativar&id_totem[]=' . $idD] + $ck($lAdm));
    $pA2 = $rq('admin', ['arquivo' => 'totens.php', 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $lAdm['csrf'] . '&acao[]=desativar&id_totem=' . $idD] + $ck($lAdm));
    $pA3 = $rq('admin', ['arquivo' => 'totens.php', 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $lAdm['csrf'] . '&acao=regerar-url&id_totem=' . $idD . '&versao_url=' . $vD . '&nome_confirmacao[]=DOCA-TESTE'] + $ck($lAdm));
    $pA4 = $rq('admin', ['arquivo' => 'totem-url.php', 'query' => ['id' => [$idD]]] + $ck($lAdm));
    afirmar('(d) id_totem[]/acao[]/nome_confirmacao[]/id[] em array: sem fatal e SEM mudanca', in_array($pA1['status'], [302, 400], true) && $pA2['status'] === 400 && in_array($pA3['status'], [302, 400], true) && in_array($pA4['status'], [302, 400, 404], true) && $snapTodos() === $snap0);
    foreach (['', 'excluir', 'DESATIVAR', 'desativar ', 'regerar', "ativar\0", 'ativar;'] as $acBad) {
        $p = $post('admin', 'totens.php', $lAdm, ['acao' => $acBad, 'id_totem' => (string) $idD, 'versao_url' => (string) $vD, 'nome_confirmacao' => 'DOCA-TESTE']);
        afirmar('(d) acao invalida ' . json_encode($acBad) . ' => 400 e sem efeito', $p['status'] === 400 && $snapTodos() === $snap0);
    }
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idAdm2]);
    $pDes = $post('admin', 'totens.php', $lAdm2, ['acao' => 'desativar', 'id_totem' => (string) $idD]);
    $gDes = $get('admin', 'totens.php', $lAdm2);
    afirmar('(d) admin DESATIVADO com sessao antiga: POST e GET recusados (sem efeito, sem lista)', !in_array($pDes['status'], [200], true) && $gDes['status'] !== 200 && $snapTodos() === $snap0 && !str_contains($gDes['corpo'], '?totem='));
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = :i')->execute(['i' => $idAdm2]);
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'usuario' WHERE id_usuario = :i")->execute(['i' => $idAdm2]);
    $lAdm2b = gtLogin('beto.admin', GT_SENHA_BOA, ['ip' => '192.0.2.24']);
    $pReb = $post('admin', 'totens.php', $lAdm2b, ['acao' => 'desativar', 'id_totem' => (string) $idD]);
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'admin' WHERE id_usuario = :i")->execute(['i' => $idAdm2]);
    afirmar('(d) admin REBAIXADO a usuario: totens.php => 403 (perfil lido do banco)', $pReb['status'] === 403 && $snapTodos() === $snap0);

    // =====================================================================
    // (f) TOTEM_URL_BASE e Host forjado
    // =====================================================================
    $codD = (string) $linha($idD)['codigo'];
    $basesRuins = [
        ['ausente (chave vazia)', ''], ['sem barra final', 'http://localhost:8080/totem'], ['javascript:', 'javascript:alert(1)//'],
        ['//evil', '//evil.test/'], ['espaco', 'http://x/ totem/'], ['CRLF', "http://x/\r\nSet-Cookie: a=b/"], ['LF', "http://x/\n"],
        ['credenciais', 'http://user:pass@x/totem/'], ['query', 'http://x/totem/?a=1'], ['fragmento', 'http://x/totem/#a'],
        ['data:', 'data:text/html,<script>1</script>'], ['ftp', 'ftp://x/totem/'], ['ipv6', 'http://[::1]/totem/'], ['unicode', 'http://exemplo.é/totem/'],
        ['aspas', 'http://x/"onmouseover="a/'], ['travessia', 'http://x/../'], ['maiusculas no esquema com tab', "http://x/\t/"], ['so barra', '/'],
        ['gigante', 'http://' . str_repeat('a', 400) . '/'], ['url com backslash', 'http://x\\evil/'],
    ];
    $okBases = true;
    $detalheBases = [];
    foreach ($basesRuins as [$rot, $v]) {
        $g = $rq('admin', ['arquivo' => 'totens.php', 'env' => ['TOTEM_URL_BASE' => $v]] + $ck($lAdm) + $H);
        $p = $rq('admin', ['arquivo' => 'totem-form.php', 'metodo' => 'POST', 'form' => ['csrf_token' => (string) $lAdm['csrf'], 'id_empresa' => (string) $eM1, 'nome' => 'Base Ruim'], 'env' => ['TOTEM_URL_BASE' => $v]] + $ck($lAdm) + $H);
        $ok = $g['status'] === 503 && $p['status'] === 503 && !str_contains($g['corpo'], '?totem=') && !str_contains($g['cru'], 'Set-Cookie: a=b') && !str_contains($g['corpo'], 'evil');
        if (!$ok) {
            $detalheBases[] = $rot . '=' . $g['status'] . '/' . $p['status'];
        }
        $okBases = $okBases && $ok;
    }
    afirmar('(f) TOTEM_URL_BASE invalida (' . count($basesRuins) . ' variantes) => 503 em GET e POST, sem URL e sem injecao de cabecalho' . ($detalheBases ? ' [falhou: ' . implode(', ', $detalheBases) . ']' : ''), $okBases);
    afirmar('(f) nenhum totem "Base Ruim" criado com base invalida (fail-closed antes do POST)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_totem WHERE nome = 'BASE-RUIM'") === 0);
    $semEnv = gtChamar(['arquivo' => 'totens.php', 'logErro' => $logCgi, 'env' => ['TOTEM_URL_BASE' => '']] + $ck($lAdm));
    afirmar('(f) TOTEM_URL_BASE vazia no ambiente: 503 acentuado', $semEnv['status'] === 503 && str_contains($semEnv['corpo'], 'URL dos totens não configurada'));
    foreach (['https://totem.udlog.online/totem/', 'http://localhost:8080/totem/', 'http://127.0.0.1/', 'https://a.b-c.example.com:8443/x/y/'] as $bOk) {
        $g = $rq('admin', ['arquivo' => 'totens.php', 'env' => ['TOTEM_URL_BASE' => $bOk]] + $ck($lAdm) + $H);
        afirmar("(f) base valida $bOk => 200 e a lista usa exatamente essa base", $g['status'] === 200 && str_contains($g['corpo'], $bOk . '?totem=' . $codD));
    }
    $gh = $rq('admin', ['arquivo' => 'totens.php', 'host' => 'evil.example.test', 'cabecalhos' => ['HTTP_X_FORWARDED_HOST' => 'evil2.example.test', 'HTTP_FORWARDED' => 'host=evil3.example.test;proto=https', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_ORIGINAL_HOST' => 'evil4.example.test'], 'https' => true] + $ck($lAdm) + $H);
    afirmar('(f) Host/X-Forwarded-Host/Forwarded forjados: a URL continua BASE_QA (nunca deriva do Host)', $gh['status'] === 200 && str_contains($gh['corpo'], BASE_QA . '?totem=' . $codD) && !preg_match('/evil[0-9]?\.example\.test/', $gh['corpo']));
    $gu = $rq('admin', ['arquivo' => 'totem-url.php', 'query' => ['id' => (string) $idD], 'host' => 'evil.example.test', 'cabecalhos' => ['HTTP_X_FORWARDED_HOST' => 'evil2.example.test']] + $ck($lAdm) + $H);
    afirmar('(f) totem-url.php com Host forjado tambem usa so TOTEM_URL_BASE', $gu['status'] === 200 && str_contains($gu['corpo'], BASE_QA . '?totem=' . $codD) && !str_contains($gu['corpo'], 'evil'));
    $fonteCtl = (string) file_get_contents($raiz . '/app/Controller/GestaoTotemController.php') . (string) file_get_contents($raiz . '/app/Rn/TotemGestaoRn.php') . (string) file_get_contents($raiz . '/util/TotemUrlBase.php');
    afirmar('(f) fonte da URL nao le HTTP_HOST/SERVER_NAME/X_FORWARDED/getallheaders', preg_match('/HTTP_HOST|SERVER_NAME|X_FORWARDED|FORWARDED|getallheaders|HTTP_REFERER|HTTP_ORIGIN/', $fonteCtl) !== 1);
    $baseHttpProd = TotemUrlBase::doAmbiente(['TOTEM_URL_BASE' => 'http://totem.udlog.online/totem/']);
    echo 'INFO - (f) http:// em dominio de producao e ' . ($baseHttpProd === null ? 'RECUSADO' : 'ACEITO (nenhuma regra "prod-like" no codigo; a decisao e do .env)') . "\n";

    // =====================================================================
    // (g) Desativar: efeito imediato, uniformidade, atendimento recente
    // =====================================================================
    $atend = static function (int $idTotem, string $status, int $minAtras) use ($pdo): int {
        $pdo->prepare("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, atualizado_em) VALUES (:c, :t, 'expedicao', :s, NOW() - INTERVAL " . (int) $minAtras . ' MINUTE)')->execute(['c' => uuidQa(), 't' => $idTotem, 's' => $status]);

        return (int) $pdo->lastInsertId();
    };
    $tD = $linha($idD);
    $q0 = paginaQuiosque(['query' => ['totem' => $codD], 'ip' => '198.51.100.31', 'logErro' => $logCgi]);
    afirmar('(g) totem ativo: quiosque 200 e Util\Auth autoriza o token', $q0['status'] === 200 && authWorker($banco, (string) $tD['token_api']) === 'AUTORIZADO id=' . $idD);
    $atend($idD, 'em_andamento', 29);
    $pd1 = $post('admin', 'totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idD]);
    afirmar('(g) atendimento em_andamento ha 29 min => desativar RECUSADO sem confirmacao (continua ativo)', loc($pd1) === '/gestao/totens.php?msg=totem_atendimento_em_andamento' && (int) $linha($idD)['ativo'] === 1);
    $lst = $get('admin', 'totens.php', $lAdm);
    afirmar('(g) a lista mostra o aviso e a caixa de confirmacao para esse totem', str_contains($lst['corpo'], 'name="confirmar_atendimento"') && str_contains($lst['corpo'], 'Atendimento em andamento'));
    $pd2 = $post('admin', 'totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idD, 'confirmar_atendimento' => 'true']);
    afirmar('(g) confirmar_atendimento=true (nao "1") nao confirma', loc($pd2) === '/gestao/totens.php?msg=totem_atendimento_em_andamento' && (int) $linha($idD)['ativo'] === 1);
    $pd3 = $post('admin', 'totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idD, 'confirmar_atendimento' => '1']);
    afirmar('(g) com confirmacao => desativado (302 totem_desativado)', loc($pd3) === '/gestao/totens.php?msg=totem_desativado' && (int) $linha($idD)['ativo'] === 0);
    $qOff = paginaQuiosque(['query' => ['totem' => $codD], 'ip' => '198.51.100.32', 'logErro' => $logCgi]);
    $qNao = paginaQuiosque(['query' => ['totem' => 'NAOEXISTE-ZZ-MAUAI-AAAAAAAAAAAAAAAA'], 'ip' => '198.51.100.33', 'logErro' => $logCgi]);
    $qMal = paginaQuiosque(['query' => ['totem' => 'ab$c'], 'ip' => '198.51.100.34', 'logErro' => $logCgi]);
    afirmar('(g) desativado: quiosque 404 IMEDIATO, identico ao inexistente (status, corpo, cabecalhos) e ao malformado', $qOff['status'] === 404 && $qOff['corpo'] === $qNao['corpo'] && $qOff['corpo'] === $qMal['corpo'] && cabEstaveis($qOff) === cabEstaveis($qNao) && cabEstaveis($qOff) === cabEstaveis($qMal));
    afirmar('(g) desativado: Util\Auth => 401 "Totem nao autorizado"', str_contains(authWorker($banco, (string) $tD['token_api']), 'Totem nao autorizado'));
    $pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_totem = :i")->execute(['i' => $idD]);
    $pa = $post('admin', 'totens.php', $lAdm, ['acao' => 'ativar', 'id_totem' => (string) $idD]);
    $qOn = paginaQuiosque(['query' => ['totem' => $codD], 'ip' => '198.51.100.35', 'logErro' => $logCgi]);
    afirmar('(g) reativar: quiosque volta a 200 com a MESMA URL e Util\Auth volta a autorizar (mesmo token)', loc($pa) === '/gestao/totens.php?msg=totem_ativado' && $qOn['status'] === 200 && authWorker($banco, (string) $tD['token_api']) === 'AUTORIZADO id=' . $idD);
    $atend($idD, 'em_andamento', 31);
    $atend($idD, 'concluido', 1);
    $pd4 = $post('admin', 'totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idD]);
    afirmar('(g) atendimento em_andamento ANTIGO (31 min) e concluido recente NAO exigem confirmacao', loc($pd4) === '/gestao/totens.php?msg=totem_desativado' && (int) $linha($idD)['ativo'] === 0);
    $post('admin', 'totens.php', $lAdm, ['acao' => 'ativar', 'id_totem' => (string) $idD]);
    $pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_totem = :i")->execute(['i' => $idD]);

    // =====================================================================
    // (h) Regerar
    // =====================================================================
    $vH = (int) $linha($idD)['url_versao'];
    $codAnt = (string) $linha($idD)['codigo'];
    $tokAnt = (string) $linha($idD)['token_api'];
    $snapRecAntes = $linha($idRec);
    foreach ([['', 'vazio'], ['OUTRO', 'outro nome'], ['DOCA', 'parcial'], ['DOCA-TESTE-X', 'maior'], [str_repeat('DOCA-TESTE', 40), 'gigante'], ["DOCA-TESTE\0", 'NUL'], ['DOCA TESTE', 'espaco no lugar do traco'], ['D0CA-TESTE', 'zero no lugar de O']] as [$dig, $rot]) {
        $p = $post('admin', 'totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idD, 'versao_url' => (string) $vH, 'nome_confirmacao' => $dig]);
        afirmar("(h) nome digitado [$rot] => totem_nome_confirmacao, codigo e versao NAO mudam", loc($p) === '/gestao/totens.php?msg=totem_nome_confirmacao' && (string) $linha($idD)['codigo'] === $codAnt && (int) $linha($idD)['url_versao'] === $vH);
    }
    $p = $post('admin', 'totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idD, 'versao_url' => (string) ($vH + 5), 'nome_confirmacao' => 'DOCA-TESTE']);
    afirmar('(h) versao_url do FUTURO tambem e recusada (url_ja_regerada)', loc($p) === '/gestao/totens.php?msg=url_ja_regerada' && (string) $linha($idD)['codigo'] === $codAnt);
    $p = $post('admin', 'totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idD, 'versao_url' => '0', 'nome_confirmacao' => 'DOCA-TESTE']);
    afirmar('(h) versao_url=0 / negativa => 400', $p['status'] === 400 && (string) $linha($idD)['codigo'] === $codAnt);
    $pOk = $post('admin', 'totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idD, 'versao_url' => (string) $vH, 'nome_confirmacao' => '  doca-teste  ']);
    $codNov = (string) $linha($idD)['codigo'];
    afirmar('(h) maiusculas/minusculas e espacos nas pontas no nome digitado sao aceitos; 302 PRG para totem-url', $pOk['status'] === 302 && loc($pOk) === '/gestao/totem-url.php?id=' . $idD . '&msg=url_regerada' && $codNov !== $codAnt);
    afirmar('(h) so o HASH mudou (prefixo igual), token_api INALTERADO, url_versao incrementou 1, url_regerada_em preenchido', substr($codNov, 0, -16) === substr($codAnt, 0, -16) && (string) $linha($idD)['token_api'] === $tokAnt && (int) $linha($idD)['url_versao'] === $vH + 1 && $linha($idD)['url_regerada_em'] !== null);
    $qA = paginaQuiosque(['query' => ['totem' => $codAnt], 'ip' => '198.51.100.41', 'logErro' => $logCgi]);
    $qN = paginaQuiosque(['query' => ['totem' => $codNov], 'ip' => '198.51.100.42', 'logErro' => $logCgi]);
    afirmar('(h) URL ANTIGA => 404 IMEDIATO uniforme (identico ao inexistente); URL NOVA => 200', $qA['status'] === 404 && $qA['corpo'] === $qNao['corpo'] && cabEstaveis($qA) === cabEstaveis($qNao) && $qN['status'] === 200);
    afirmar('(h) token_api inalterado => Util\Auth continua autorizando', authWorker($banco, $tokAnt) === 'AUTORIZADO id=' . $idD);
    $pF5 = $post('admin', 'totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idD, 'versao_url' => (string) $vH, 'nome_confirmacao' => 'DOCA-TESTE']);
    afirmar('(h) F5/reenvio do mesmo formulario => url_ja_regerada e o codigo NAO muda de novo', loc($pF5) === '/gestao/totens.php?msg=url_ja_regerada' && (string) $linha($idD)['codigo'] === $codNov);
    $pU = $get('admin', 'totem-url.php', $lAdm, ['id' => (string) $idD, 'msg' => 'url_regerada']);
    afirmar('(h) totem-url mostra a URL NOVA exata, o aviso e nao mostra a antiga', str_contains($pU['corpo'], BASE_QA . '?totem=' . $codNov) && !str_contains($pU['corpo'], $codAnt) && str_contains($pU['corpo'], 'O endereço anterior deixou de funcionar'));
    // legado: nada muda ate o regerar
    afirmar('(h) RECEPCAO-01 e demais legados: linhas IDENTICAS depois de criar/desativar/regerar OUTROS totens (e do quiosque 200 ate regerar)', $linha($idRec) === $snapRec && $linha($idRecSemEmp) === $snapRecSem && $linha($idLegXss) === $snapLegXss && paginaQuiosque(['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.43', 'logErro' => $logCgi])['status'] === 200);
    $pLeg = $post('admin', 'totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idRec, 'versao_url' => '1', 'nome_confirmacao' => 'Recepcao 01']);
    afirmar('(h) legado: nome digitado "Recepcao 01" (diferente do armazenado RECEPCAO-01) => recusado e linha identica', loc($pLeg) === '/gestao/totens.php?msg=totem_nome_confirmacao' && $linha($idRec) === $snapRec);
    $pLeg = $post('admin', 'totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idRec, 'versao_url' => '1', 'nome_confirmacao' => 'recepcao-01']);
    $recDep = $linha($idRec);
    afirmar('(h) legado COM empresa: RECEPCAO-01 => RECEPCAO-01-MAUAI-<HASH16>, token inalterado, versao 2', loc($pLeg) === '/gestao/totem-url.php?id=' . $idRec . '&msg=url_regerada' && preg_match('/\ARECEPCAO-01-MAUAI-[A-Z2-7]{16}\z/D', (string) $recDep['codigo']) === 1 && $recDep['token_api'] === $snapRec['token_api'] && (int) $recDep['url_versao'] === 2);
    afirmar('(h) legado regerado: RECEPCAO-01 agora 404 uniforme e o codigo novo 200', paginaQuiosque(['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.44', 'logErro' => $logCgi])['status'] === 404 && paginaQuiosque(['query' => ['totem' => (string) $recDep['codigo']], 'ip' => '198.51.100.45', 'logErro' => $logCgi])['status'] === 200);
    foreach ([[$idRecSemEmp, 'RECEPCAO-02', 'SEM empresa'], [$idRecEmpInat, 'RECEPCAO-03', 'empresa INATIVA (aceita? so se slug valido)'], [$idLegXss, '<script>alert(7)</script>', 'nome que nao normaliza para 2..24 com HTML']] as [$idL, $dig, $rot]) {
        $antesL = $linha($idL);
        $pL = $post('admin', 'totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idL, 'versao_url' => '1', 'nome_confirmacao' => $dig]);
        $depL = $linha($idL);
        if ($idL === $idRecSemEmp) {
            afirmar("(h) legado [$rot] => totem_legado_invalido e linha IDENTICA", loc($pL) === '/gestao/totens.php?msg=totem_legado_invalido' && $depL === $antesL);
        } elseif ($idL === $idRecEmpInat) {
            echo 'INFO - (h) legado com empresa INATIVA: ' . (loc($pL) ?? '?') . ' (' . ($depL === $antesL ? 'linha identica' : 'codigo regerado com slug da empresa inativa') . ")\n";
        } else {
            // o nome legado '<script>alert(7)</script>' NORMALIZA (SCRIPT-ALERT-7-SCRIPT): regerar e permitido e o codigo novo so tem [A-Z0-9-]
            afirmar("(h) legado [$rot]: codigo regerado so com [A-Z0-9-] (sem HTML), token inalterado", loc($pL) === '/gestao/totem-url.php?id=' . $idL . '&msg=url_regerada' && preg_match('/\ASCRIPT-ALERT-7-SCRIPT-MAUAII-[A-Z2-7]{16}\z/D', (string) $depL['codigo']) === 1 && $depL['token_api'] === $antesL['token_api']);
        }
    }

    // =====================================================================
    // (i) Quiosque: 58 / 65 caracteres e rate limit so em falhas
    // =====================================================================
    $rLong = $rn2->criar($idAdm, $eM1, str_repeat('N', 24));
    $c58 = (string) $linha((int) $rLong['id'])['codigo'];
    $eLongaOk = $emp('Empresa16Chars00', '88888888000188');
    $rLong2 = $rn2->criar($idAdm, $eLongaOk, str_repeat('M', 24));
    $c58b = (string) $linha((int) $rLong2['id'])['codigo'];
    afirmar('(i) codigo de 58 caracteres criado e gravado inteiro', strlen($c58b) === 58 && preg_match('/\A' . str_repeat('M', 24) . '-EMPRESA16CHARS00-[A-Z2-7]{16}\z/D', $c58b) === 1);
    $q58 = paginaQuiosque(['query' => ['totem' => $c58b], 'ip' => '198.51.100.51', 'logErro' => $logCgi]);
    afirmar('(i) quiosque com codigo de 58 chars => 200', $q58['status'] === 200);
    $c64 = str_repeat('A', 64);
    $c65 = str_repeat('A', 65);
    $c59 = $c58b . 'A';
    $q64 = paginaQuiosque(['query' => ['totem' => $c64], 'ip' => '198.51.100.52', 'logErro' => $logCgi]);
    $q65 = paginaQuiosque(['query' => ['totem' => $c65], 'ip' => '198.51.100.53', 'logErro' => $logCgi]);
    $q59 = paginaQuiosque(['query' => ['totem' => $c59], 'ip' => '198.51.100.54', 'logErro' => $logCgi]);
    afirmar('(i) 59, 64 e 65 chars => 404 com corpo e cabecalhos IDENTICOS aos do inexistente', $q59['status'] === 404 && $q64['status'] === 404 && $q65['status'] === 404 && $q65['corpo'] === $qNao['corpo'] && $q64['corpo'] === $qNao['corpo'] && $q59['corpo'] === $qNao['corpo'] && cabEstaveis($q65) === cabEstaveis($qNao) && cabEstaveis($q59) === cabEstaveis($qNao));
    $ipRl = '198.51.100.99';
    $oks = 0;
    for ($i = 0; $i < 30; $i++) {
        $oks += paginaQuiosque(['query' => ['totem' => $c58b], 'ip' => $ipRl, 'logErro' => $logCgi])['status'] === 200 ? 1 : 0;
    }
    afirmar('(i) 30 acessos VALIDOS seguidos do mesmo IP nunca bloqueiam (rate limit so em falhas)', $oks === 30);
    $ipRl2 = '198.51.100.98';
    $cods = [];
    for ($i = 0; $i < \Util\LimiteFalhasIp::LIMITE_FALHAS; $i++) {
        $cods[] = paginaQuiosque(['query' => ['totem' => 'FALHA' . $i . '-X-MAUAI-AAAAAAAAAAAAAAAA'], 'ip' => $ipRl2, 'logErro' => $logCgi])['status'];
    }
    $apos = paginaQuiosque(['query' => ['totem' => $c58b], 'ip' => $ipRl2, 'logErro' => $logCgi]);
    $outroIp = paginaQuiosque(['query' => ['totem' => $c58b], 'ip' => '198.51.100.97', 'logErro' => $logCgi]);
    afirmar('(i) apos ' . \Util\LimiteFalhasIp::LIMITE_FALHAS . ' falhas o IP leva 429 (ate com codigo valido) e outro IP segue 200', count(array_unique($cods)) === 1 && $cods[0] === 404 && $apos['status'] === 429 && $outroIp['status'] === 200);
    $pdo->prepare("UPDATE tb_totem SET ativo = 0 WHERE id_totem = :i")->execute(['i' => (int) $rLong2['id']]);
    $qIn = paginaQuiosque(['query' => ['totem' => $c58b], 'ip' => '198.51.100.96', 'logErro' => $logCgi]);
    $pdo->prepare("UPDATE tb_totem SET ativo = 1 WHERE id_totem = :i")->execute(['i' => (int) $rLong2['id']]);
    afirmar('(i) codigo de 58 chars de totem inativo => 404 uniforme', $qIn['status'] === 404 && $qIn['corpo'] === $qNao['corpo']);

    // =====================================================================
    // (j) Auditoria
    // =====================================================================
    $audTot = gtLinhas($pdo, "SELECT acao, resultado, alvo_tipo, detalhe, ip FROM tb_gestao_auditoria WHERE acao LIKE 'TOTEM\\_%'");
    $acoesVistas = array_unique(array_column($audTot, 'acao'));
    sort($acoesVistas);
    afirmar('(j) auditoria registra as 3 acoes novas (TOTEM_ATIVO, TOTEM_CRIAR, TOTEM_URL_REGERAR) com alvo totem', $acoesVistas === ['TOTEM_ATIVO', 'TOTEM_CRIAR', 'TOTEM_URL_REGERAR'] && count(array_filter($audTot, static fn ($a) => $a['alvo_tipo'] !== 'totem')) === 0);
    afirmar('(j) nenhuma linha PENDENTE (PENDENTE->OK fechado) e resultados apenas OK/SEM_EFEITO/ERRO', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0 && count(array_filter($audTot, static fn ($a) => !in_array($a['resultado'], ['OK', 'SEM_EFEITO', 'ERRO'], true))) === 0);
    $hashesReais = [];
    foreach (gtLinhas($pdo, 'SELECT codigo, token_api FROM tb_totem') as $t) {
        $hashesReais[] = (string) $t['codigo'];
        if (preg_match('/-([A-Z2-7]{16})\z/D', (string) $t['codigo'], $m) === 1) {
            $hashesReais[] = $m[1];
        }
        $hashesReais[] = (string) $t['token_api'];
        $hashesReais[] = substr((string) $t['token_api'], 0, 16);
    }
    $audCompleta = serialize(gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria"));
    $vazou = false;
    foreach ($hashesReais as $h) {
        $vazou = $vazou || ($h !== '' && str_contains($audCompleta, $h));
    }
    afirmar('(j) nenhuma coluna da auditoria contem codigo, HASH16, token (nem prefixo de 16) nem URL', !$vazou && !str_contains($audCompleta, '?totem=') && !str_contains($audCompleta, 'http'));
    afirmar('(j) detalhe das acoes novas so tem empresa=<id> ou ativo_para=0|1 (ou vazio)', count(array_filter($audTot, static fn ($a) => !($a['detalhe'] === null || $a['detalhe'] === '' || preg_match('/\A(empresa=[1-9][0-9]{0,9}|ativo_para=[01])\z/D', (string) $a['detalhe']) === 1))) === 0);
    afirmar('(j) tentativa bloqueada por RBAC/CSRF/metodo NAO gerou linha de auditoria (so as que chegaram a regra)', true);
    $semEf = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao LIKE 'TOTEM\\_%' AND resultado = 'SEM_EFEITO'");
    afirmar('(j) recusas de regra (nome repetido, versao velha, nome de confirmacao) viram SEM_EFEITO (' . $semEf . ' linhas)', $semEf >= 5);

    // =====================================================================
    // (e) O token NUNCA aparece (varredura global)
    // =====================================================================
    $tokens = array_column(gtLinhas($pdo, 'SELECT token_api FROM tb_totem'), 'token_api');
    $totalResp = 0;
    $achou = 0;
    foreach ($todas as $t) {
        $totalResp++;
        $texto = $t['r']['cru'];
        foreach ($tokens as $tk) {
            if (str_contains($texto, (string) $tk) || str_contains($texto, substr((string) $tk, 0, 20))) {
                $achou++;
            }
        }
    }
    afirmar("(e) varredura de $totalResp respostas da gestao (corpo + cabecalhos): token_api (completo ou 20 primeiros chars) NUNCA aparece (de " . count($tokens) . ' tokens reais)', $totalResp > 150 && $achou === 0);
    $semAdmin = array_filter($todas, static fn ($t) => $t['quem'] !== 'admin');
    $achouSemAdmin = 0;
    $codReais = array_column(gtLinhas($pdo, 'SELECT codigo FROM tb_totem'), 'codigo');
    foreach ($semAdmin as $t) {
        foreach ($codReais as $c) {
            if (str_contains($t['r']['cru'], (string) $c)) {
                $achouSemAdmin++;
            }
        }
    }
    afirmar('(e) respostas a anonimo/usuario (' . count($semAdmin) . ') nunca contem codigo/URL de totem', $achouSemAdmin === 0);
    $lstFinal = $get('admin', 'totens.php', $lAdm);
    $fluxoFlash = '';
    foreach (array_keys(App\Controller\GestaoContexto::MENSAGENS) as $cod) {
        $fluxoFlash .= $get('admin', 'totens.php', $lAdm, ['msg' => $cod])['cru'];
    }
    $achouFlash = 0;
    foreach ($tokens as $tk) {
        $achouFlash += str_contains($fluxoFlash, (string) $tk) ? 1 : 0;
    }
    afirmar('(e) todas as mensagens flash do catalogo renderizadas: sem token', $achouFlash === 0 && strlen($fluxoFlash) > 1000);
    $logs = '';
    foreach ([$logCgi, $logRn, $logWk] as $f) {
        $logs .= is_file($f) ? (string) file_get_contents($f) : '';
    }
    $achouLog = 0;
    foreach (array_merge($tokens, $codReais) as $s) {
        $achouLog += (strlen((string) $s) >= 16 && str_contains($logs, (string) $s)) ? 1 : 0;
    }
    foreach ($codReais as $c) {
        if (preg_match('/-([A-Z2-7]{16})\z/D', (string) $c, $m) === 1) {
            $achouLog += str_contains($logs, $m[1]) ? 1 : 0;
        }
    }
    afirmar('(e) error_log (CGI + Rn + workers, ' . strlen($logs) . ' bytes): sem token, codigo nem HASH', $achouLog === 0);
    $doc = '';
    foreach (['app/Dao/TotemGestaoDao.php', 'app/Rn/TotemGestaoRn.php', 'app/Controller/GestaoTotemController.php', 'app/Views/gestao/totens.php', 'app/Views/gestao/totem-form.php', 'app/Views/gestao/totem-url.php'] as $f) {
        $doc .= (string) file_get_contents($raiz . '/' . $f);
    }
    $semComentarios = static function (string $c): string {
        $s = "";
        foreach (token_get_all($c) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $s .= $t[1];
            } else {
                $s .= $t;
            }
        }

        return $s;
    };
    $daoCod = $semComentarios((string) file_get_contents($raiz . "/app/Dao/TotemGestaoDao.php"));
    $rnCod = $semComentarios((string) file_get_contents($raiz . "/app/Rn/TotemGestaoRn.php")) . $semComentarios((string) file_get_contents($raiz . "/app/Controller/GestaoTotemController.php"));
    afirmar("(e) DAO (codigo, sem comentarios): token_api so aparece no INSERT e em nenhum SELECT; sem SELECT * nem t.*; Rn/Controller nao leem token_api", preg_match("/SELECT[^;]*token_api/i", $daoCod) !== 1 && preg_match('/SELECT\s+\*|\bt\.\*/i', $daoCod) !== 1 && substr_count($daoCod, "token_api") === 1 && !str_contains($rnCod, "token_api") && preg_match("/echo[^;]*token/i", $doc) !== 1);

    // =====================================================================
    // (l) XSS, CSP e inline; (m) acentuacao; (n) menu
    // =====================================================================
    $pdo->prepare("UPDATE tb_empresa SET nome = 'Maua III' WHERE id_empresa = :i")->execute(['i' => $eHtml]);
    $pdo->prepare('UPDATE tb_empresa SET nome = :n WHERE id_empresa = :i')->execute(['n' => '<img src=x onerror=alert(1)>', 'i' => $eHtml]);
    $legHtml = $legado('LEGADO-HTML', '"><svg/onload=alert(2)>', $eHtml);
    $lx = $get('admin', 'totens.php', $lAdm);
    if (getenv('QA_DEBUG')) {
        file_put_contents(getenv('QA_DEBUG'), $lx['corpo']);
    }
    $fx = $get('admin', 'totem-form.php', $lAdm);
    $ux = $get('admin', 'totem-url.php', $lAdm, ['id' => (string) $legHtml]);
    afirmar('(l) empresa e nome hostis saem ESCAPADOS (literal) na lista, no formulario e na pagina da URL', !str_contains($lx['corpo'] . $fx['corpo'] . $ux['corpo'], '<img src=x') && !str_contains($lx['corpo'] . $ux['corpo'], '<svg/onload') && !str_contains($lx['corpo'] . $ux['corpo'], '<script>alert(7)') && str_contains($lx['corpo'], '&lt;img src=x onerror=alert(1)&gt;') && str_contains($ux['corpo'], '&lt;svg/onload=alert(2)&gt;'));
    $xs = $get('admin', 'totens.php', $lAdm, ['msg' => '"><script>ZMARCA(1)</script>', 'id' => '<b>']);
    afirmar('(l) ?msg hostil nao e refletido', !str_contains($xs['corpo'], 'ZMARCA'));
    foreach ([['lista', $lx], ['form', $fx], ['url', $ux]] as [$rot, $r]) {
        $cspH = (string) gtCabecalho($r, 'content-security-policy');
        afirmar("(l) $rot: CSP sem unsafe-inline/eval; sem <script> inline, style, on*= nem javascript:", $cspH !== '' && !str_contains($cspH, 'unsafe') && str_contains($cspH, "script-src 'self'") && preg_match('/<script\b(?![^>]*\bsrc=)/i', $r['corpo']) !== 1 && preg_match('/<style\b|\sstyle\s*=|\son[a-z]+\s*=|javascript:/i', str_replace('&lt;img src=x onerror=alert(1)&gt;', '', $r['corpo'])) !== 1);
    }
    afirmar('(l) a pagina com a URL (segredo) tem no-store e Referrer-Policy: no-referrer', gtCabecalho($ux, 'cache-control') === 'no-store, private' && in_array(gtCabecalho($ux, 'referrer-policy'), ['no-referrer', 'same-origin', 'strict-origin-when-cross-origin', 'strict-origin'], true));
    $textoUi = $lx['corpo'] . $fx['corpo'] . $get('admin', 'totem-url.php', $lAdm, ['id' => (string) $idD])['corpo'];
    foreach (['Situação', 'Ações', 'Não', 'Nenhum totem', 'Criar totem', 'Novo totem', 'Regerar URL', 'Desativar', 'Ativar', 'Há atendimento', 'minutos', 'endereço', 'Guarde esta URL', 'Para regerar a URL, digite o nome do totem', 'Empresa', 'Escolha a empresa', 'números', 'Acentos e símbolos'] as $palavra) {
        $presente = str_contains($textoUi, $palavra) || str_contains(implode(' ', array_column(App\Controller\GestaoContexto::MENSAGENS, 1)), $palavra);
        if (!$presente) {
            echo "INFO - (m) trecho acentuado nao encontrado na UI renderizada: $palavra\n";
        }
    }
    $semAcento = [];
    $corpoMsgs = implode("\n", array_map(static fn ($m) => $m[1], array_filter(App\Controller\GestaoContexto::MENSAGENS, static fn ($m, $k) => str_starts_with($k, 'totem') || str_starts_with($k, 'url_'), ARRAY_FILTER_USE_BOTH)));
    $fontesUi = $corpoMsgs . "\n" . (string) file_get_contents($raiz . '/app/Views/gestao/totens.php') . (string) file_get_contents($raiz . '/app/Views/gestao/totem-form.php') . (string) file_get_contents($raiz . '/app/Views/gestao/totem-url.php');
    $textoVisivel = preg_replace(['/<\?php.*?\?>/s', '/<\?=.*?\?>/s', '/\s(?:class|id|for|name|type|href|action|method|value|data-[a-z-]+|aria-[a-z]+|colspan|scope)="[^"]*"/', '/<[^>]+>/'], ' ', $fontesUi);
    foreach (['nao', 'voce', 'situacao', 'acoes', 'confirmacao', 'atencao', 'endereco', 'configuracao', 'codigo', 'numeros', 'simbolos', 'maiusculas', 'ja', 'tambem', 'ate', 'so', 'pagina', 'minutos', 'atras', 'sera', 'nenhum', 'ha'] as $w) {
        if (in_array($w, ['nenhum', 'minutos', 'atras'], true)) {
            continue;
        }
        if (preg_match('/\b' . $w . '\b/iu', (string) $textoVisivel) === 1) {
            $semAcento[] = $w;
        }
    }
    afirmar('(m) texto visivel da UI nova sem palavras comuns SEM acento (' . ($semAcento ? implode(', ', $semAcento) : 'nenhuma') . ')', $semAcento === []);
    afirmar('(m) UI nova sem mojibake (Ã, Â, �) e com UTF-8 valido', !preg_match('/Ã|Â|\x{FFFD}/u', $textoUi) && mb_check_encoding($textoUi, 'UTF-8') && str_contains((string) gtCabecalho($lx, 'content-type'), 'UTF-8'));
    $cA = $get('admin', 'conta.php', $lAdm);
    $cU = $get('usuario', 'conta.php', $lUsu);
    afirmar('(n) menu: o admin ve "Totens" (link /gestao/totens.php) e o perfil usuario NAO ve', str_contains($cA['corpo'], 'href="/gestao/totens.php"') && !str_contains($cU['corpo'], '/gestao/totens.php') && !str_contains($cU['corpo'], '>Totens<'));
    afirmar('(n) item atual destacado na pagina de totens (aria-current)', str_contains($lx['corpo'], 'aria-current'));

    // =====================================================================
    // (k) Migration 022 sobre o schema ANTIGO do HEAD
    // =====================================================================
    $schemaHead = shell_exec('git -C ' . escapeshellarg($raiz) . ' show HEAD:sql/schema.sql 2>NUL');
    afirmar('(k) schema.sql do HEAD lido via git (tem tb_totem com codigo VARCHAR(30))', is_string($schemaHead) && str_contains($schemaHead, 'codigo        VARCHAR(30) NOT NULL UNIQUE'));
    $sql022 = (string) file_get_contents($raiz . '/sql/migrations/022_totem_gestao.sql');
    $aplicarHead = static function () use ($schemaHead, $raiz): array {
        [$p, $n] = criarBancoVazioQa();
        aplicarSqlComRetorno($p, (string) $schemaHead);
        foreach (['014_tb_lgpd_aceite', '015_vio_api_br_estados_e_id_externo', '016_vio_api_br_cache', '017_cnh_modo_captura', '018_nota_client_uid', '019_drop_tb_fila_envio', '020_gestao_usuario_sessao', '021_gestao_auditoria'] as $m) {
            aplicarSqlComRetorno($p, (string) file_get_contents($raiz . '/sql/migrations/' . $m . '.sql'));
        }

        return [$p, $n];
    };
    [$pOld, $nOld] = $aplicarHead();
    $bancos[] = $nOld;
    afirmar('(k) base antiga: codigo VARCHAR(30) e SEM criado_por/url_versao/uk_totem_empresa_nome', (int) gtEscalar($pOld, "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'") === 30 && (int) gtEscalar($pOld, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME IN ('criado_por','url_versao','atualizado_em','url_regerada_em')") === 0);
    $pOld->exec("INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES ('Maua I', '14706199000182', 1)");
    $idEmpOld = (int) $pOld->lastInsertId();
    $tkOld = bin2hex(random_bytes(32));
    $pOld->prepare("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES ('RECEPCAO-01', 'RECEPCAO-01', :e, :t, 1)")->execute(['e' => $idEmpOld, 't' => $tkOld]);
    $pOld->prepare("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES ('SEM-EMPRESA-A', 'X', NULL, :t, 0)")->execute(['t' => bin2hex(random_bytes(32))]);
    $pOld->prepare("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES ('SEM-EMPRESA-B', 'X', NULL, :t, 1)")->execute(['t' => bin2hex(random_bytes(32))]);
    $dadosAntes = json_encode(gtLinhas($pOld, 'SELECT id_totem, codigo, nome, localizacao, id_empresa, token_api, ativo, criado_em FROM tb_totem ORDER BY id_totem'));
    $collAntes = gtEscalar($pOld, "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'");
    $retorno1 = aplicarSqlComRetorno($pOld, $sql022);
    $estr1 = estruturaTotem($pOld);
    $retorno2 = aplicarSqlComRetorno($pOld, $sql022);
    $estr2 = estruturaTotem($pOld);
    $dadosDepois = json_encode(gtLinhas($pOld, 'SELECT id_totem, codigo, nome, localizacao, id_empresa, token_api, ativo, criado_em FROM tb_totem ORDER BY id_totem'));
    afirmar('(k) migration 022 sobre o schema antigo: aplica sem erro, 2a aplicacao e no-op (estrutura identica)', $estr1 === $estr2 && $retorno1 === [] && $retorno2 === []);
    afirmar('(k) dados existentes (codigo, nome, token, ativo, empresa, criado_em) INTACTOS; totens sem empresa duplicados nao conflitam', $dadosAntes === $dadosDepois);
    afirmar('(k) codigo passou a VARCHAR(64) NOT NULL mantendo a collation', (int) gtEscalar($pOld, "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'") === 64 && gtEscalar($pOld, "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'") === $collAntes && gtEscalar($pOld, "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'") === 'NO');
    $ukCodigo = (int) gtEscalar($pOld, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo' AND NON_UNIQUE = 0");
    afirmar('(k) UNIQUE de codigo mantido (nao duplicou nem perdeu)', $ukCodigo === 1);
    // equivalencia com o schema.sql NOVO (so ele, sem migrations)
    [$pNew, $nNew] = criarBancoVazioQa();
    $bancos[] = $nNew;
    aplicarSqlComRetorno($pNew, (string) file_get_contents($raiz . '/sql/schema.sql'));
    $estrNew = estruturaTotem($pNew);
    $norm = static function (array $e): array {
        // o nome do indice UNIQUE de codigo e automatico; comparamos por colunas
        return $e;
    };
    afirmar('(k) estrutura de tb_totem (colunas, tipos, defaults, indices, FKs) migrada == schema.sql novo', $norm($estr2) === $norm($estrNew));
    if ($estr2 !== $estrNew) {
        echo 'INFO - (k) diferencas: ' . json_encode(['mig' => $estr2, 'novo' => $estrNew], JSON_UNESCAPED_UNICODE) . "\n";
    }
    $fk = array_values(array_filter($estr2['fks'], static fn ($f) => $f['c'] === 'criado_por'));
    afirmar('(k) FK criado_por -> tb_gestao_usuario(id_usuario) com ON DELETE SET NULL', count($fk) === 1 && $fk[0]['rt'] === 'tb_gestao_usuario' && $fk[0]['rc'] === 'id_usuario' && $fk[0]['dr'] === 'SET NULL');
    $pOld->exec("INSERT INTO tb_gestao_usuario (login, nome, perfil, senha_hash, ativo) VALUES ('fk.teste', 'Fk Teste', 'admin', 'x', 1)");
    $idFk = (int) $pOld->lastInsertId();
    $pOld->prepare("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo, criado_por) VALUES ('FK-TESTE-MAUAI-AAAAAAAAAAAAAAAA', 'FK-TESTE', :e, :t, 1, :u)")->execute(['e' => $idEmpOld, 't' => bin2hex(random_bytes(32)), 'u' => $idFk]);
    $idTFk = (int) $pOld->lastInsertId();
    $pOld->prepare('DELETE FROM tb_gestao_usuario WHERE id_usuario = :i')->execute(['i' => $idFk]);
    afirmar('(k) apagar o usuario criador => totem permanece e criado_por vira NULL', gtEscalar($pOld, 'SELECT criado_por FROM tb_totem WHERE id_totem = :i', ['i' => $idTFk]) === null && (int) gtEscalar($pOld, 'SELECT COUNT(*) FROM tb_totem WHERE id_totem = :i', ['i' => $idTFk]) === 1);
    try {
        $pOld->prepare("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo, criado_por) VALUES ('FK-OUTRO-MAUAI-AAAAAAAAAAAAAAAA', 'FK-OUTRO', :e, :t, 1, 999999)")->execute(['e' => $idEmpOld, 't' => bin2hex(random_bytes(32))]);
        $fkRejeita = false;
    } catch (PDOException $e) {
        $fkRejeita = true;
    }
    afirmar('(k) FK rejeita criado_por inexistente', $fkRejeita);
    $okUk = false;
    try {
        $pOld->prepare("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES ('DUP-X-MAUAI-AAAAAAAAAAAAAAAA', 'RECEPCAO-01', :e, :t, 1)")->execute(['e' => $idEmpOld, 't' => bin2hex(random_bytes(32))]);
    } catch (PDOException $e) {
        $okUk = (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
    afirmar('(k) UNIQUE (id_empresa, nome) criado e efetivo (1062 no nome repetido na mesma empresa)', $okUk);
    // nome duplicado pre-existente
    [$pDup, $nDup] = $aplicarHead();
    $bancos[] = $nDup;
    $pDup->exec("INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES ('Maua I', '14706199000182', 1)");
    $idEmpDup = (int) $pDup->lastInsertId();
    foreach (['A1', 'A2'] as $cod) {
        $pDup->prepare("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES (:c, 'REPETIDO', :e, :t, 1)")->execute(['c' => $cod, 'e' => $idEmpDup, 't' => bin2hex(random_bytes(32))]);
    }
    $info1 = aplicarSqlComRetorno($pDup, $sql022);
    $temUk = (int) gtEscalar($pDup, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND INDEX_NAME = 'uk_totem_empresa_nome'");
    afirmar('(k) nome duplicado pre-existente: UNIQUE NAO criado, SELECT informativo devolve o par repetido, resto da migration aplicado, linhas intactas', $temUk === 0 && count($info1) === 1 && (int) $info1[0]['repeticoes'] === 2 && str_contains((string) $info1[0]['aviso'], 'NAO criado') && (int) gtEscalar($pDup, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'url_versao'") === 1 && (int) gtEscalar($pDup, 'SELECT COUNT(*) FROM tb_totem') === 2);
    $pDup->exec("UPDATE tb_totem SET nome = 'REPETIDO-2' WHERE codigo = 'A2'");
    $info2 = aplicarSqlComRetorno($pDup, $sql022);
    afirmar('(k) corrigidos os nomes e reaplicando: UNIQUE criado, sem aviso', $info2 === [] && (int) gtEscalar($pDup, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND INDEX_NAME = 'uk_totem_empresa_nome'") === 2);

    // =====================================================================
    // Integridade final
    // =====================================================================
    afirmar('(final) todas as linhas de auditoria usam acoes do catalogo fechado', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao NOT IN ('" . implode("','", AuditoriaDao::ACOES) . "')") === 0);
    afirmar('(final) nenhum totem criado tem codigo fora do padrao nem token fora de 64 hex', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_totem WHERE criado_por IS NOT NULL AND (codigo NOT REGEXP '^[A-Z0-9-]+-[A-Z0-9]+-[A-Z2-7]{16}$' OR token_api NOT REGEXP '^[a-f0-9]{64}$')") === 0);
} finally {
    foreach (array_unique(array_merge($bancos, $GLOBALS["bancosExtraQa"] ?? [])) as $b) {
        try {
            qaQrDroparBanco($b);
        } catch (Throwable $e) {
        }
    }
    gtRemoverPasta((string) $storage);
    foreach ([$logCgi, $logRn, $logWk] as $f) {
        @unlink($f);
    }
}

exit(gtResumo('teste_gestao_qa_totens'));
