<?php

/**
 * Lacunas adicionais (QA independente, demanda anexo-ordem-coleta-n8n):
 * Auth com variacoes de Bearer/tamanho de chave, base64 com espacos/quebras de
 * linha/prefixo data:, cnpj/numero hostis, token em query string, Content-Type
 * diferente de JSON (CGI) e concorrencia com checagem de consistencia
 * arquivo x sha256 (varias rodadas). Banco QA descartavel; nunca toca
 * udlog_totem nem o banco externo real. Hermetico quanto ao .env: sobrepoe a
 * chave e a flag de HTTP via QA_OC_*.
 *
 * Uso: php tests/manual/teste_oc_anexo_lacunas.php [rodadas_concorrencia=100]
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_oc_anexo_infra.php';

use App\Dao\OrdemColetaArquivoDao;
use App\Rn\OrdemColetaArquivoRn;
use Util\AuthServidor;
use Util\OrdemColetaArquivoStorage;

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

[$pdo, $banco, $storage] = ocQaCriarAmbiente();
try {
    $raizOc = $storage . '/ordens_coleta';
    $cnpj = '12345678000199';
    $rn = new OrdemColetaArquivoRn(new OrdemColetaArquivoDao(), new OrdemColetaArquivoStorage($storage));
    $pdf = ocQaPdf(700, 'lac');
    $b64 = base64_encode($pdf);
    $linhas = static fn () => (int) $pdo->query('SELECT COUNT(*) FROM tb_ordem_coleta_arquivos')->fetchColumn();
    $env = static fn (array $e) => $e + ['cnpj_cliente' => $cnpj, 'numero_ordem_coleta' => 'L-1', 'arquivo_base64' => $b64];

    // ---- Auth: variacoes de Bearer e chave
    $chave = 'chave-QA-' . bin2hex(random_bytes(16));
    $auth = new AuthServidor($chave, false, null);
    $https = ['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.5'];
    foreach (['bearer ' . $chave, 'BEARER ' . $chave, 'Bearer  ' . $chave, 'Bearer ' . $chave . ' ', ' Bearer ' . $chave, "Bearer {$chave}\n", 'Bearer ' . substr($chave, 0, -1), 'Bearer ' . $chave . 'x', 'Bearer ' . str_repeat('A', 4096), $chave, 'Bearer'] as $h) {
        afirmar('auth recusa variacao: ' . substr(json_encode(str_replace($chave, '<K>', $h)), 0, 40), ($auth->verificar($https, $h)['http'] ?? 0) === 401);
    }
    afirmar('auth aceita Bearer exato', $auth->verificar($https, 'Bearer ' . $chave) === null);

    // ---- Rn: base64 hostil
    $casos = [
        'quebra de linha no meio' => chunk_split($b64, 76, "\n"),
        'CRLF' => chunk_split($b64, 76, "\r\n"),
        'espaco no meio' => substr($b64, 0, 8) . ' ' . substr($b64, 8),
        'espaco no fim' => $b64 . ' ',
        'prefixo data:' => 'data:application/pdf;base64,' . $b64,
        'padding excessivo' => $b64 . '====',
        'padding no meio' => substr($b64, 0, 8) . '====' . substr($b64, 8),
    ];
    foreach ($casos as $nome => $v) {
        $r = $rn->receber($env(['arquivo_base64' => $v]));
        afirmar("base64 $nome => 400/413 e nada gravado", in_array($r['http'], [400, 413], true) && $linhas() === 0);
    }

    // ---- Rn: cnpj hostil
    foreach (['12.345.678/0001-99', "12345678000199\n", ' 12345678000199', '1234567800019', '123456780001999', '1234567800019a', 12345678000199, null, ['x']] as $c) {
        $r = $rn->receber($env(['cnpj_cliente' => $c]));
        afirmar('cnpj hostil recusado: ' . substr(json_encode($c), 0, 30), $r['http'] === 400 && $linhas() === 0);
    }
    // ---- Rn: numero hostil
    foreach (['', '   ', str_repeat('9', 51), "A\tB", "A\nB", "\x7f", "A\u{200B}B", "A\u{2028}B", "\xff\xfe", 123, ['x'], null, "A\0B"] as $n) {
        $r = $rn->receber($env(['numero_ordem_coleta' => $n]));
        afirmar('numero hostil recusado: ' . substr((string) json_encode($n, JSON_INVALID_UTF8_SUBSTITUTE), 0, 30), $r['http'] === 400 && $linhas() === 0);
    }
    foreach (['.oculto', '..', '.', 'A B', 'AÇÃO-1', 'a/b\\c', 'CON', 'oc:1', 'a.pdf', '-rf', '~x'] as $n) {
        $r = $rn->receber($env(['numero_ordem_coleta' => $n]));
        $q = $pdo->prepare('SELECT caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta=?');
        $q->execute([$n]);
        $rel = (string) $q->fetchColumn();
        $nome = substr($rel, strlen($cnpj) + 1);
        afirmar('numero aceito como texto, nome seguro e dentro da pasta: ' . json_encode($n, JSON_UNESCAPED_UNICODE), $r['http'] === 201 && OrdemColetaArquivoStorage::caminhoRelativoValido($rel) && $nome !== '' && $nome[0] !== '.' && is_file($raizOc . '/' . $rel) && !str_contains($nome, '/'));
    }
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');
    foreach (glob($raizOc . '/*/*') ?: [] as $f) {
        @unlink($f);
    }
    $a = $rn->receber($env(['numero_ordem_coleta' => '  TRIM-1 ']));
    $b = $rn->receber($env(['numero_ordem_coleta' => 'TRIM-1']));
    afirmar('numero com espacos nas pontas = mesma chave (duplicado, 1 linha)', $a['http'] === 201 && $b['http'] === 200 && $linhas() === 1);
    $c = $rn->receber($env(['numero_ordem_coleta' => 'trim-1', 'arquivo_base64' => base64_encode(ocQaPdf(300, 'outro'))]));
    echo 'INFO - mesmo numero em outra CAIXA com conteudo diferente => http ' . $c['http'] . ' (' . ($c['dados']['status'] ?? $c['codigo'] ?? '?') . '), linhas=' . $linhas() . ', arquivos=' . count(ocQaArquivos($raizOc)) . "\n";
    afirmar('outra caixa: sem orfao em disco (arquivos == linhas)', count(ocQaArquivos($raizOc)) === $linhas());

    // ---- CGI: query string / Content-Type
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    if (!is_file($cgi)) {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi';
    }
    $raiz = dirname(__DIR__, 2);
    $rota = $raiz . '/public/api/ordem-coleta-anexo.php';
    $chamar = static function (array $o) use ($cgi, $raiz, $rota, $chave): array {
        $corpo = $o['corpo'] ?? '';
        $tmp = tempnam(sys_get_temp_dir(), 'qa_oc_lac_');
        file_put_contents($tmp, $corpo);
        $env = ['REDIRECT_STATUS' => '200', 'REQUEST_METHOD' => 'POST', 'SCRIPT_FILENAME' => $rota, 'SCRIPT_NAME' => '/api/ordem-coleta-anexo.php',
            'CONTENT_TYPE' => $o['ct'] ?? 'application/json', 'CONTENT_LENGTH' => (string) strlen($corpo), 'REMOTE_ADDR' => $o['ip'] ?? '192.0.2.77', 'HTTPS' => 'on',
            'QUERY_STRING' => $o['qs'] ?? '', 'QA_OC_API_KEY' => $chave, 'QA_OC_PERMITIR_HTTP' => 'false', 'QA_OC_MAX_BYTES' => '5242880', 'SystemRoot' => (string) getenv('SystemRoot'),
            'QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'), 'QA_QR_FORCE_STORAGE' => (string) getenv('QA_QR_FORCE_STORAGE'),
            'QA_QR_DB_HOST' => (string) getenv('QA_QR_DB_HOST'), 'QA_QR_DB_PORT' => (string) getenv('QA_QR_DB_PORT'),
            'QA_QR_DB_USER' => (string) getenv('QA_QR_DB_USER'), 'QA_QR_DB_PASS' => (string) getenv('QA_QR_DB_PASS')];
        if (isset($o['auth'])) {
            $env['HTTP_AUTHORIZATION'] = $o['auth'];
        }
        $p = proc_open([$cgi, '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_oc_anexo_prepend.php', '-d', 'display_errors=0'], [0 => ['file', $tmp, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $raiz . '/public/api', $env);
        $s = (string) stream_get_contents($pp[1]);
        stream_get_contents($pp[2]);
        fclose($pp[1]);
        fclose($pp[2]);
        proc_close($p);
        @unlink($tmp);
        preg_match('/^Status:\s*(\d{3})/mi', $s, $m);

        return ['status' => (int) ($m[1] ?? 200)];
    };
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');
    $corpo = json_encode(['cnpj_cliente' => $cnpj, 'numero_ordem_coleta' => 'Q-1', 'arquivo_base64' => $b64]);
    $r = $chamar(['corpo' => $corpo, 'qs' => 'token=' . $chave . '&api_key=' . $chave . '&Authorization=Bearer+' . $chave]);
    afirmar('token na query string, sem header => 401 e nada gravado', $r['status'] === 401 && $linhas() === 0);
    $r = $chamar(['corpo' => $corpo, 'auth' => 'Bearer ' . $chave, 'qs' => 'cnpj_cliente=99999999999999&numero_ordem_coleta=HACK']);
    $q = $pdo->query('SELECT cnpj_cliente, numero_ordem_coleta FROM tb_ordem_coleta_arquivos')->fetchAll();
    afirmar('query string ignorada (valores vem so do corpo)', $r['status'] === 201 && count($q) === 1 && $q[0]['cnpj_cliente'] === $cnpj && $q[0]['numero_ordem_coleta'] === 'Q-1');
    $res = [];
    foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', ''] as $ct) {
        $r = $chamar(['corpo' => $corpo, 'auth' => 'Bearer ' . $chave, 'ct' => $ct]);
        $res[$ct] = $r['status'];
    }
    echo 'INFO - Content-Type != JSON com corpo JSON valido (controller nao valida Content-Type): ' . json_encode($res) . "\n";
    afirmar('Content-Type diferente de JSON nunca da 500', !in_array(500, $res, true));

    // ---- Concorrencia (regressivo D1/M2): N rodadas x 6 processos, criterio 0 falhas
    //  (A) MESMO par (cnpj, numero): sempre 200/201, 1 linha, 1 arquivo, sha256 do arquivo == da linha;
    //  (B) numeros DISTINTOS que geram a MESMA parte do nome ("OC 1","OC-1","OC/1",... => "OC-1"):
    //      6 x 201, 6 linhas, 6 arquivos, cada arquivo com o sha256 da SUA linha, caminhos distintos.
    $N = (int) ($argv[1] ?? 100);
    $caso = __DIR__ . '/_caso_oc_anexo_receber.php';
    $dispara = static function (array $numeros) use ($caso, $cnpj): array {
        $procs = [];
        foreach ($numeros as $i => $numero) {
            $c = escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/qa_oc_anexo_prepend.php') . ' ' . escapeshellarg($caso) . ' ' . $cnpj . ' ' . escapeshellarg($numero) . ' marca' . $i;
            $p = proc_open($c, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
            $procs[] = [$p, $pp];
        }
        $http = [];
        foreach ($procs as [$p, $pp]) {
            $j = json_decode((string) stream_get_contents($pp[1]), true);
            $http[] = $j['http'] ?? 0;
            stream_get_contents($pp[2]);
            fclose($pp[1]);
            fclose($pp[2]);
            proc_close($p);
        }

        return $http;
    };
    $limpar = static function () use ($pdo, $raizOc, $cnpj): void {
        $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');
        if (is_dir($raizOc . '/' . $cnpj)) {
            foreach (new DirectoryIterator($raizOc . '/' . $cnpj) as $f) {
                if ($f->isFile()) {
                    @unlink($f->getPathname());
                }
            }
        }
    };
    $ruinsA = [];
    $ruinsB = [];
    $t0 = microtime(true);
    for ($r = 0; $r < $N; $r++) {
        // (A) mesmo par
        $limpar();
        $http = $dispara(array_fill(0, 6, 'CONC-9'));
        $l = $pdo->query('SELECT * FROM tb_ordem_coleta_arquivos')->fetchAll();
        $a = ocQaArquivos($raizOc . '/' . $cnpj);
        $ok = count($l) === 1 && count($a) === 1 && is_file($raizOc . '/' . $l[0]['caminho_relativo']) && hash_file('sha256', $raizOc . '/' . $l[0]['caminho_relativo']) === $l[0]['sha256'];
        if (!$ok || array_diff($http, [200, 201]) !== []) {
            $ruinsA[] = ($ok ? 'consistente' : 'INCONSISTENTE') . ' http=' . implode(',', $http);
        }
        // (B) numeros distintos, mesma parte
        $limpar();
        $http = $dispara(['OC 1', 'OC-1', 'OC/1', 'OC:1', 'OC*1', 'OC?1']);
        $l = $pdo->query('SELECT * FROM tb_ordem_coleta_arquivos')->fetchAll();
        $a = ocQaArquivos($raizOc . '/' . $cnpj);
        $ok = count($l) === 6 && count($a) === 6 && count(array_unique(array_column($l, 'caminho_relativo'))) === 6;
        foreach ($l as $linha) {
            $ok = $ok && is_file($raizOc . '/' . $linha['caminho_relativo']) && hash_file('sha256', $raizOc . '/' . $linha['caminho_relativo']) === $linha['sha256'];
        }
        if (!$ok || $http !== array_fill(0, 6, 201)) {
            $ruinsB[] = ($ok ? 'consistente' : 'INCONSISTENTE') . ' linhas=' . count($l) . ' arquivos=' . count($a) . ' http=' . implode(',', $http);
        }
    }
    $seg = round(microtime(true) - $t0);
    afirmar("concorrencia (A) MESMO par ($N rodadas x 6 processos, {$seg}s): sempre 200/201 e arquivo == sha256 da linha (falhas=" . count($ruinsA) . ' ' . implode(' | ', array_slice($ruinsA, 0, 3)) . ')', $ruinsA === []);
    afirmar("concorrencia (B) numeros DISTINTOS com a mesma parte ($N rodadas x 6 processos): 6 x 201, 6 linhas, 6 arquivos, sha256 de cada um == o da sua linha (falhas=" . count($ruinsB) . ' ' . implode(' | ', array_slice($ruinsB, 0, 3)) . ')', $ruinsB === []);
} catch (Throwable $e) {
    afirmar('execucao sem excecao: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    ocQaLimpar($banco, $storage);
}
echo "\nTotal: $total, falhas: $falhas\n";
