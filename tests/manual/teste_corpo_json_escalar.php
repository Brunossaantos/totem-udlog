<?php

/**
 * Regressao: corpo JSON escalar/invalido (5, "x", true, null, [], texto nao
 * JSON) nas rotas public/api/impressao.php, atendimento.php e nota.php nao pode
 * gerar TypeError/HTTP 500: o corpo vira [] e a validacao posterior responde
 * 4xx (400/404/409/422 conforme a rota).
 *
 * Executa o arquivo REAL da rota via php-cgi (stdin = corpo, token no header),
 * contra banco descartavel `qa_qr_exclusivo_<hex>` (removido no finally) com
 * um totem ficticio. Nunca toca em udlog_totem. Sem rede, sem Talent, sem
 * impressao.
 *
 * Uso: php tests/manual/teste_corpo_json_escalar.php
 */

declare(strict_types=1);

require_once __DIR__ . '/qa_qr_exclusivo_legado.php';

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

$banco = null;
$storage = null;
$token = bin2hex(random_bytes(32));
$raiz = dirname(__DIR__, 2);

/** @return array{status:int,saida:string} */
function chamarRota(string $cgi, string $raiz, string $arquivo, string $acao, string $corpo, string $token): array
{
    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => 'POST',
        'SCRIPT_FILENAME' => $raiz . '/public/api/' . $arquivo,
        'SCRIPT_NAME' => '/api/' . $arquivo,
        'QUERY_STRING' => 'acao=' . $acao,
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) strlen($corpo),
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        'QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'),
        'QA_QR_FORCE_STORAGE' => (string) getenv('QA_QR_FORCE_STORAGE'),
        'QA_QR_FORCE_TALENT_ATIVO' => 'false',
        'SystemRoot' => (string) getenv('SystemRoot'),
    ];
    $cmd = [$cgi, '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_qr_exclusivo_prepend.php', '-d', 'display_errors=0', '-d', 'log_errors=0'];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz . '/public/api', $env);
    fwrite($pipes[0], $corpo);
    fclose($pipes[0]);
    $saida = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $status = 200;
    if (preg_match('/^Status:\s*(\d{3})/mi', $saida, $m) === 1) {
        $status = (int) $m[1];
    }
    return ['status' => $status, 'saida' => $saida];
}

try {
    [$pdo, $banco, $storage] = qaLegadoCriarAmbiente();
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 1)')
        ->execute(['c' => 'QA-ESCALAR', 'n' => 'QA escalar', 't' => $token]);

    $corpos = ['5' => '5', '"x"' => '"x"', 'true' => 'true', 'null' => 'null', '[]' => '[]', 'texto nao JSON' => 'abc{'];
    $rotas = [
        ['impressao.php', 'gerar-etiqueta'],
        ['atendimento.php', 'iniciar'],
        ['atendimento.php', 'salvar-etapa'],
        ['nota.php', 'processar'],
        ['nota.php', 'definir-numero'],
    ];

    // Controle: sem token a rota responde 401 (prova que o CGI executa o arquivo real).
    $r = chamarRota($cgi, $raiz, 'nota.php', 'processar', '5', 'invalido');
    afirmar('controle: token invalido = 401', $r['status'] === 401);

    foreach ($rotas as [$arquivo, $acao]) {
        foreach ($corpos as $nome => $corpo) {
            $r = chamarRota($cgi, $raiz, $arquivo, $acao, $corpo, $token);
            $ok = $r['status'] >= 400 && $r['status'] < 500
                && stripos($r['saida'], 'TypeError') === false
                && stripos($r['saida'], 'Fatal error') === false;
            afirmar("$arquivo?acao=$acao corpo $nome => 4xx (obtido {$r['status']})", $ok);
        }
    }
} catch (Throwable $e) {
    afirmar('execucao sem excecao: ' . get_class($e), false);
} finally {
    qaLegadoLimparAmbiente($banco, $storage);
}

echo "\nTotal: $total, falhas: $falhas\n";
exit($falhas === 0 ? 0 : 1);
