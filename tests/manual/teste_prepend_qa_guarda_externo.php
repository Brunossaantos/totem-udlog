<?php

/**
 * A3 (F4a): os prepends QA que forcam DB_NAME (qa_qr_exclusivo_prepend.php,
 * qa_gestao_prepend.php, qa_oc_anexo_prepend.php) tambem forcam
 * GESTAO_COLETAS_DB_NAME para um banco QA ou abortam com exit 3. Sem banco,
 * sem rede: so subprocessos PHP que imprimem as chaves forcadas.
 *
 * Uso: php tests/manual/teste_prepend_qa_guarda_externo.php
 */
declare(strict_types=1);

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

const REAL_EXT = 'udlogo59_db_gestao_coletas';
const REAL_TOTEM = 'udlog_totem';
const QA_TOTEM = 'qa_qr_exclusivo_deadbeef';
const QA_EXT = 'qa_qr_exclusivo_cafe1234';

$tmp = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'qa_prepend_guarda_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});
$alvo = $tmp . DIRECTORY_SEPARATOR . 'alvo.php';
file_put_contents($alvo, '<?php echo json_encode([$_ENV["DB_NAME"] ?? null, $_ENV["GESTAO_COLETAS_DB_NAME"] ?? null, $_SERVER["GESTAO_COLETAS_DB_NAME"] ?? null]);');

/** @return array{0:int,1:string,2:string} */
function rodar(string $prepend, array $env, string $alvo): array
{
    $e = ['SystemRoot' => (string) getenv('SystemRoot'), 'PATH' => (string) getenv('PATH')] + $env;
    $p = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'display_errors=0', $alvo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($p), $out, $err];
}

$raiz = __DIR__;
foreach (['qa_qr_exclusivo_prepend.php', 'qa_gestao_prepend.php', 'qa_oc_anexo_prepend.php'] as $nome) {
    $pre = $raiz . DIRECTORY_SEPARATOR . $nome;

    [$rc, $out] = rodar($pre, ['QA_QR_FORCE_DB_NAME' => QA_TOTEM], $alvo);
    $v = json_decode($out, true);
    afirmar("$nome: so com DB_NAME QA, GESTAO_COLETAS_DB_NAME tambem vira nome QA (nunca vazio/real)",
        $rc === 0 && is_array($v) && $v[0] === QA_TOTEM && preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', (string) $v[1]) === 1 && $v[1] === $v[2] && $v[1] !== REAL_EXT);

    if ($nome !== 'qa_oc_anexo_prepend.php') {
        [$rc, $out] = rodar($pre, ['QA_QR_FORCE_DB_NAME' => QA_TOTEM, 'QA_QR_FORCE_EXT_DB_NAME' => QA_EXT], $alvo);
        $v = json_decode($out, true);
        afirmar("$nome: QA_QR_FORCE_EXT_DB_NAME valido e usado no externo", $rc === 0 && is_array($v) && $v[1] === QA_EXT && $v[2] === QA_EXT);

        foreach ([REAL_EXT, REAL_TOTEM, 'qa_qr_exclusivo_xyz', 'qa_qr_exclusivo_deadbeef; DROP', 'QA_QR_EXCLUSIVO_CAFE1234'] as $ruim) {
            [$rc, $out, $err] = rodar($pre, ['QA_QR_FORCE_DB_NAME' => QA_TOTEM, 'QA_QR_FORCE_EXT_DB_NAME' => $ruim], $alvo);
            afirmar("$nome: recusa banco externo '$ruim' (exit 3, sem executar o alvo, sem eco do valor)", $rc === 3 && $out === '' && !str_contains($err, $ruim));
        }
    }

    foreach ([REAL_TOTEM, REAL_EXT] as $real) {
        [$rc, $out] = rodar($pre, ['QA_QR_FORCE_DB_NAME' => $real], $alvo);
        afirmar("$nome: recusa $real como DB_NAME (exit 3)", $rc === 3 && $out === '');
    }
    [$rc, $out] = rodar($pre, [], $alvo);
    afirmar("$nome: sem QA_QR_FORCE_DB_NAME aborta (exit 3)", $rc === 3 && $out === '');
}

// qa_gestao_prepend: o mapa JSON nao pode sobrescrever o banco externo
[$rc, $out] = rodar($raiz . '/qa_gestao_prepend.php', ['QA_QR_FORCE_DB_NAME' => QA_TOTEM, 'QA_GESTAO_ENV_JSON' => json_encode(['GESTAO_COLETAS_DB_NAME' => REAL_EXT])], $alvo);
afirmar('qa_gestao_prepend: QA_GESTAO_ENV_JSON com GESTAO_COLETAS_DB_NAME e recusado (exit 3)', $rc === 3 && $out === '');

// Mutante: prepend sem a guarda do externo deixa o valor do ambiente passar => o teste acima o detectaria
foreach (['qa_qr_exclusivo_prepend.php', 'qa_gestao_prepend.php'] as $nome) {
    $src = (string) file_get_contents($raiz . DIRECTORY_SEPARATOR . $nome);
    $mut = preg_replace('/\/\/ A3 \(F4a\).*?\$_SERVER\[\'GESTAO_COLETAS_DB_NAME\'\] = \$qaExt;\r?\n/s', '', $src, 1, $n);
    $pm = $tmp . DIRECTORY_SEPARATOR . 'mut_' . $nome;
    file_put_contents($pm, (string) $mut);
    [$rc, $out, $err] = rodar($pm, ['QA_QR_FORCE_DB_NAME' => QA_TOTEM, 'QA_QR_FORCE_EXT_DB_NAME' => REAL_EXT], $alvo);
    $v = json_decode($out, true);
    afirmar("mutante ($nome sem a guarda do externo): sem o bloco o banco externo real passa (rc 0, nao 3) e NAO e forcado a QA", $n === 1 && $rc === 0 && is_array($v) && $v[1] === null);
    if ($rc !== 0) {
        echo 'DEBUG rc=' . $rc . ' err=' . $err . "
";
    }
}

echo "\n=== RESULTADO teste_prepend_qa_guarda_externo: {$total} verificacoes, " . ($total - $falhas) . " passaram, {$falhas} falharam ===\n";
exit($falhas > 0 ? 1 : 0);
