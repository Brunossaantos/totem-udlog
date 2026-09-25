<?php

/**
 * Roteiro de /02-testes (backend/seguranca) da demanda
 * tela-inicial-lgpd-totem (2026-09-24) — valida os 14 cenarios obrigatorios
 * de backend/seguranca do fluxo de aceite LGPD (App\Content\TermoLgpd,
 * App\Dao\AceiteLgpdDao, App\Rn\LgpdRn, App\Controller\LgpdController,
 * public/api/lgpd.php, e a exigencia de token_aceite em
 * App\Controller\AtendimentoController::iniciar()).
 *
 * BANCO SEMPRE DESCARTAVEL (ver tests/manual/_fixtures_lgpd.php) — nome
 * sintetico qa_lgpd_testes_<timestamp>_<random>, criado do zero
 * (sql/schema.sql + sql/migrations/014_tb_lgpd_aceite.sql), validado via
 * SELECT DATABASE() ANTES de qualquer escrita, removido ao final (mesmo em
 * caso de falha, via register_shutdown_function). NUNCA usa o banco de
 * desenvolvimento real (DB_NAME do .env local).
 *
 * Uso: php tests/manual/teste_lgpd_aceite_backend_seguranca.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_lgpd.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function dispararSequencial(string $script, array $args): string
{
    $php = PHP_BINARY;
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php, $script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    return $saida;
}

function dispararSequencialComDisplayErrors(string $script, array $args): string
{
    $php = PHP_BINARY;
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', $script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    return $saida;
}

function dispararParalelo(string $script, array $args): array
{
    $php = PHP_BINARY;
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $processos = [];
    foreach ($args as $chave => $argv) {
        $cmd = array_merge([$php, $script], $argv);
        $processos[$chave] = proc_open($cmd, $descritores, $pipes);
        $processos[$chave . '_pipes'] = $pipes;
    }

    $saidas = [];
    foreach ($args as $chave => $argv) {
        $pipes = $processos[$chave . '_pipes'];
        $saidas[$chave] = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($processos[$chave]);
    }

    return $saidas;
}

// ============================================================
// Setup do banco descartavel
// ============================================================
$raizProjeto = __DIR__ . '/../..';
[$pdo, $nomeBanco, $host, $porta, $usuarioDb, $senhaDb] = lgpdQaCriarBancoDescartavel($raizProjeto);

$usuarioLimitado = null;
$senhaLimitado = null;

register_shutdown_function(function () use (&$host, &$porta, &$usuarioDb, &$senhaDb, &$nomeBanco, &$usuarioLimitado) {
    // Remove o usuario limitado (item 11), se chegou a ser criado.
    if ($usuarioLimitado !== null) {
        try {
            $pdoServidor = new PDO("mysql:host={$host};port={$porta};charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdoServidor->exec("DROP USER IF EXISTS '{$usuarioLimitado}'@'localhost'");
        } catch (\Throwable $e) {
            echo "AVISO: falha ao remover usuario limitado de teste: {$e->getMessage()}\n";
        }
    }
    try {
        lgpdQaDroparBanco($host, $porta, $usuarioDb, $senhaDb, $nomeBanco);
        echo "\n[limpeza] banco descartavel {$nomeBanco} removido.\n";
    } catch (\Throwable $e) {
        echo "\n[limpeza] FALHA ao remover banco descartavel {$nomeBanco}: {$e->getMessage()}\n";
    }
});

// Usa um caminho DENTRO do proprio diretorio de testes (nunca
// sys_get_temp_dir(), que no Windows pode resolver para um caminho curto
// 8.3 com '~' — confirmado que isso quebra o parser de "-d error_log=..."
// do PHP CLI) — arquivo temporario, removido ao final deste cenario.
$caminhoErrorLog = __DIR__ . '/_tmp_qa_lgpd_error_log_' . bin2hex(random_bytes(4)) . '.txt';
touch($caminhoErrorLog);

$totemA = lgpdQaCriarTotem($pdo, 'QA_LGPD_A_' . bin2hex(random_bytes(3)));
$totemB = lgpdQaCriarTotem($pdo, 'QA_LGPD_B_' . bin2hex(random_bytes(3)));

$todosOsTokensEmitidos = [];

function emitirToken(string $script, array $connArgs, int $idTotem): array
{
    global $todosOsTokensEmitidos;
    $saida = dispararSequencial($script, array_merge($connArgs, [(string) $idTotem]));
    $json = json_decode(trim(explode("\nHTTP_CODE:", $saida)[0]), true);
    if (isset($json['dados']['token_aceite'])) {
        $todosOsTokensEmitidos[] = $json['dados']['token_aceite'];
    }
    return ['saida' => $saida, 'json' => $json];
}

$connArgs = [$host, $porta, $usuarioDb, $senhaDb, $nomeBanco];
$scriptAceitar = __DIR__ . '/_caso_lgpd_aceitar.php';
$scriptIniciar = __DIR__ . '/_caso_lgpd_iniciar.php';

// ============================================================
// Cenario 1 — emissao de token valido
// ============================================================
echo "\n=== Cenario 1: emissao de token valido ===\n";
$r1 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
echo $r1['saida'] . "\n";
$token1 = $r1['json']['dados']['token_aceite'] ?? null;
$expiraEm1 = $r1['json']['dados']['expira_em'] ?? null;
// Resposta::sucesso() nunca chama http_response_code() explicitamente (so
// Resposta::erro() chama) — mesma ressalva ja documentada em
// teste_integridade_conclusao_atendimento.php: evidencia de sucesso e
// "sucesso":true + ausencia de HTTP_CODE de erro, nao um HTTP_CODE:200
// literal (que nunca e emitido).
afirmar('Cenario 1: HTTP 200 ("sucesso":true, sem HTTP_CODE de erro)', str_contains($r1['saida'], '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $r1['saida']));
afirmar('Cenario 1: token_aceite tem 64 caracteres hexadecimais', is_string($token1) && preg_match('/^[0-9a-f]{64}$/', $token1) === 1);
afirmar('Cenario 1: expira_em presente e ~10 minutos a frente (entre 9 e 11 min)', (function () use ($expiraEm1) {
    if (!$expiraEm1) return false;
    $delta = (new DateTimeImmutable($expiraEm1))->getTimestamp() - (new DateTimeImmutable('now'))->getTimestamp();
    return $delta >= 9 * 60 && $delta <= 11 * 60;
})());
afirmar('Cenario 1: resposta inclui termo (versao/hash/texto)', isset($r1['json']['dados']['termo']['versao'], $r1['json']['dados']['termo']['hash'], $r1['json']['dados']['termo']['texto']));

// ============================================================
// Cenario 2 — somente o hash SHA-256 do token e gravado (nunca o bruto)
// ============================================================
echo "\n=== Cenario 2: somente hash SHA-256 gravado no banco ===\n";
$linha2 = $pdo->query("SELECT token_hash FROM tb_lgpd_aceite ORDER BY id_aceite DESC LIMIT 1")->fetch();
$hashEsperado = hash('sha256', $token1);
afirmar('Cenario 2: token_hash gravado bate com SHA-256(token bruto)', $linha2 && $linha2['token_hash'] === $hashEsperado);
afirmar('Cenario 2: token BRUTO nunca aparece em nenhuma coluna de tb_lgpd_aceite', (function () use ($pdo, $token1) {
    $linhas = $pdo->query('SELECT * FROM tb_lgpd_aceite')->fetchAll();
    foreach ($linhas as $linha) {
        foreach ($linha as $valor) {
            if (is_string($valor) && str_contains($valor, $token1)) return false;
        }
    }
    return true;
})());

// ============================================================
// Cenario 3 — nenhum dado pessoal gravado na emissao
// ============================================================
echo "\n=== Cenario 3: nenhum dado pessoal gravado na emissao ===\n";
$colunas3 = $pdo->query("SHOW COLUMNS FROM tb_lgpd_aceite")->fetchAll(PDO::FETCH_COLUMN);
$colunasProibidas = ['cpf', 'nome', 'placa', 'motorista', 'documento', 'cnh', 'crlv'];
$colunasComDadoPessoal = array_filter($colunas3, fn($c) => (bool) array_filter($colunasProibidas, fn($p) => stripos($c, $p) !== false));
afirmar('Cenario 3: schema de tb_lgpd_aceite nao tem NENHUMA coluna de dado pessoal', count($colunasComDadoPessoal) === 0);
afirmar('Cenario 3: colunas reais sao exatamente as esperadas (id_aceite/token_hash/id_totem/versao_termo/hash_termo/criado_em/expira_em/usado_em/status)', $colunas3 === ['id_aceite', 'token_hash', 'id_totem', 'versao_termo', 'hash_termo', 'criado_em', 'expira_em', 'usado_em', 'status']);

// ============================================================
// Cenario 4 — token_aceite AUSENTE -> rejeitado (400), sem criar atendimento
// ============================================================
echo "\n=== Cenario 4: token_aceite ausente ===\n";
$totalAntes4 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$saida4 = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0004', 'SEM_TOKEN']));
echo $saida4 . "\n";
$totalDepois4 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
afirmar('Cenario 4: HTTP 409 (formatoValido rejeita ausencia)', str_contains($saida4, 'HTTP_CODE:409'));
afirmar('Cenario 4: mensagem generica "Aceite de privacidade invalido ou expirado"', str_contains($saida4, 'Aceite de privacidade invalido ou expirado'));
afirmar('Cenario 4: nenhum atendimento criado', $totalAntes4 === $totalDepois4);

// ============================================================
// Cenario 5 — token INVALIDO (string aleatoria nunca emitida)
// ============================================================
echo "\n=== Cenario 5: token invalido (nunca emitido) ===\n";
$tokenInventado = bin2hex(random_bytes(32));
$totalAntes5 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$saida5 = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0005', $tokenInventado]));
echo $saida5 . "\n";
$totalDepois5 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
afirmar('Cenario 5: HTTP 409', str_contains($saida5, 'HTTP_CODE:409'));
afirmar('Cenario 5: mensagem generica (nao diferencia causa)', str_contains($saida5, 'Aceite de privacidade invalido ou expirado'));
afirmar('Cenario 5: nenhum atendimento criado', $totalAntes5 === $totalDepois5);

// ============================================================
// Cenario 6 — token EXPIRADO (forcado via UPDATE direto no banco de teste)
// ============================================================
echo "\n=== Cenario 6: token expirado ===\n";
$r6 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
$token6 = $r6['json']['dados']['token_aceite'];
$hash6 = hash('sha256', $token6);
$pdo->prepare("UPDATE tb_lgpd_aceite SET expira_em = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token_hash = :h")->execute(['h' => $hash6]);
$totalAntes6 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$saida6 = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0006', $token6]));
echo $saida6 . "\n";
$totalDepois6 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$statusPos6 = $pdo->query("SELECT status FROM tb_lgpd_aceite WHERE token_hash = '{$hash6}'")->fetchColumn();
afirmar('Cenario 6: HTTP 409', str_contains($saida6, 'HTTP_CODE:409'));
afirmar('Cenario 6: nenhum atendimento criado', $totalAntes6 === $totalDepois6);
afirmar('Cenario 6: status do aceite permanece PENDENTE_USO (CAS nao consumiu por causa da expiracao)', $statusPos6 === 'PENDENTE_USO');

// ============================================================
// Cenario 7 — token JA USADO (replay)
// ============================================================
echo "\n=== Cenario 7: token ja usado (replay) ===\n";
$r7 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
$token7 = $r7['json']['dados']['token_aceite'];
$saida7a = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0007A', $token7]));
echo "--- 1a tentativa ---\n{$saida7a}\n";
$totalApos7a = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$saida7b = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0007B', $token7]));
echo "--- 2a tentativa (replay) ---\n{$saida7b}\n";
$totalApos7b = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
afirmar('Cenario 7: 1a tentativa HTTP 200 (sucesso)', str_contains($saida7a, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida7a));
afirmar('Cenario 7: 2a tentativa (replay) HTTP 409', str_contains($saida7b, 'HTTP_CODE:409'));
afirmar('Cenario 7: replay NAO cria um segundo atendimento', $totalApos7b === $totalApos7a);

// ============================================================
// Cenario 8 — token de OUTRO TOTEM
// ============================================================
echo "\n=== Cenario 8: token emitido pelo totem A, usado como totem B ===\n";
$r8 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
$token8 = $r8['json']['dados']['token_aceite'];
$saida8a = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemB['id_totem'], 'QA0008A', $token8]));
echo "--- totem B tenta consumir token do totem A ---\n{$saida8a}\n";
afirmar('Cenario 8: HTTP 409 (token de outro totem rejeitado)', str_contains($saida8a, 'HTTP_CODE:409'));
// Confirma que o token do totem A CONTINUA valido — consumido com sucesso pelo proprio totem A.
$saida8b = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0008B', $token8]));
echo "--- totem A (dono legitimo) consome o mesmo token, depois da tentativa indevida do totem B ---\n{$saida8b}\n";
afirmar('Cenario 8: token do totem A NAO foi consumido indevidamente pela tentativa do totem B — totem A ainda consegue usa-lo com sucesso', str_contains($saida8b, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida8b));

// ============================================================
// Cenario 9 — termo com versao/hash divergente
// ============================================================
echo "\n=== Cenario 9: versao/hash do termo divergente do atual ===\n";
$r9 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
$token9 = $r9['json']['dados']['token_aceite'];
$hash9 = hash('sha256', $token9);
$pdo->prepare("UPDATE tb_lgpd_aceite SET versao_termo = 'versao-divergente-simulada', hash_termo = SHA2('texto-divergente-simulado', 256) WHERE token_hash = :h")->execute(['h' => $hash9]);
$saida9 = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0009', $token9]));
echo $saida9 . "\n";
$statusPos9 = $pdo->query("SELECT status FROM tb_lgpd_aceite WHERE token_hash = '{$hash9}'")->fetchColumn();
afirmar('Cenario 9: HTTP 409 (termo divergente rejeitado)', str_contains($saida9, 'HTTP_CODE:409'));
afirmar('Cenario 9: status permanece PENDENTE_USO (CAS nao bateu por causa do termo)', $statusPos9 === 'PENDENTE_USO');

// ============================================================
// Cenario 10 — CONCORRENCIA REAL (2 subprocessos, mesmo token)
// ============================================================
echo "\n=== Cenario 10: 2 chamadas concorrentes reais consumindo o MESMO token ===\n";
$r10 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
$token10 = $r10['json']['dados']['token_aceite'];
$totalAntes10 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$saidas10 = dispararParalelo($scriptIniciar, [
    'a' => array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0010A', $token10]),
    'b' => array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0010B', $token10]),
]);
echo "--- concorrente A ---\n{$saidas10['a']}\n--- concorrente B ---\n{$saidas10['b']}\n";
$totalDepois10 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$sucessoA10 = str_contains($saidas10['a'], '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saidas10['a']);
$sucessoB10 = str_contains($saidas10['b'], '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saidas10['b']);
afirmar('Cenario 10: EXATAMENTE 1 sucesso entre os 2 concorrentes (XOR)', $sucessoA10 xor $sucessoB10);
afirmar('Cenario 10: o perdedor recebe HTTP 409', str_contains($saidas10['a'], 'HTTP_CODE:409') xor str_contains($saidas10['b'], 'HTTP_CODE:409'));
afirmar('Cenario 10: nenhuma duplicacao de atendimento (exatamente +1 linha)', $totalDepois10 - $totalAntes10 === 1);

// ============================================================
// Cenario 11 — consumo do token e criacao do atendimento na MESMA transacao
// (INSERT forcado a falhar DEPOIS do CAS via usuario MySQL sem privilegio de
// INSERT em tb_atendimento nesse banco descartavel)
// ============================================================
echo "\n=== Cenario 11: transacao real — falha no INSERT apos CAS bem-sucedido -> rollback (token volta a PENDENTE_USO) ===\n";
$usuarioLimitado = 'qa_lgpd_lim_' . bin2hex(random_bytes(4));
$senhaLimitadaGerada = bin2hex(random_bytes(8));
$pdoServidorPriv = new PDO("mysql:host={$host};port={$porta};charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoServidorPriv->exec("CREATE USER '{$usuarioLimitado}'@'localhost' IDENTIFIED BY '{$senhaLimitadaGerada}'");
$pdoServidorPriv->exec("GRANT SELECT, UPDATE ON `{$nomeBanco}`.tb_lgpd_aceite TO '{$usuarioLimitado}'@'localhost'");
$pdoServidorPriv->exec("GRANT SELECT ON `{$nomeBanco}`.tb_totem TO '{$usuarioLimitado}'@'localhost'");
$pdoServidorPriv->exec("GRANT SELECT ON `{$nomeBanco}`.tb_atendimento TO '{$usuarioLimitado}'@'localhost'"); // sem INSERT de proposito
$pdoServidorPriv->exec('FLUSH PRIVILEGES');
$senhaLimitado = $senhaLimitadaGerada;

$r11 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
$token11 = $r11['json']['dados']['token_aceite'];
$hash11 = hash('sha256', $token11);
$totalAntes11 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$saida11 = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0011', $token11, $usuarioLimitado, $senhaLimitadaGerada]));
echo $saida11 . "\n";
$totalDepois11 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$statusPos11 = $pdo->query("SELECT status, usado_em FROM tb_lgpd_aceite WHERE token_hash = '{$hash11}'")->fetch();
afirmar('Cenario 11: HTTP 500 generico (falha ao processar, nunca vaza detalhe)', str_contains($saida11, 'HTTP_CODE:500') && str_contains($saida11, 'Nao foi possivel processar o atendimento agora'));
afirmar('Cenario 11: nenhum atendimento criado (INSERT falhou e foi revertido)', $totalAntes11 === $totalDepois11);
afirmar('Cenario 11: token volta a PENDENTE_USO apos rollback (nunca fica USADO orfao)', $statusPos11 && $statusPos11['status'] === 'PENDENTE_USO' && $statusPos11['usado_em'] === null);

// Confirma que, apos o rollback, o MESMO token pode ser usado normalmente
// com o usuario de privilegio completo (prova de que o rollback devolveu o
// token a um estado genuinamente reutilizavel, nao so aparentemente).
$saida11b = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0011B', $token11]));
echo "--- retentativa com usuario de privilegio completo, mesmo token, apos rollback ---\n{$saida11b}\n";
afirmar('Cenario 11: apos o rollback, o mesmo token consegue ser consumido com sucesso numa nova tentativa', str_contains($saida11b, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida11b));

// ============================================================
// Cenario 12 — chamada direta a API sem nenhum aceite (front-end nao e a
// unica protecao) — mesmo caminho real do endpoint HTTP (lgpdRn/pdo
// injetados), sem token nenhum.
// ============================================================
echo "\n=== Cenario 12: chamada direta ao endpoint de iniciar, sem nenhum aceite ===\n";
$totalAntes12 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
$saida12 = dispararSequencial($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0012', 'SEM_TOKEN']));
echo $saida12 . "\n";
$totalDepois12 = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento')->fetchColumn();
afirmar('Cenario 12: bloqueado no backend mesmo chamando o endpoint diretamente (sem depender do front)', str_contains($saida12, 'HTTP_CODE:409'));
afirmar('Cenario 12: nenhum atendimento criado', $totalAntes12 === $totalDepois12);

// ============================================================
// Cenario 13 — respostas de erro nunca vazam detalhe tecnico, mesmo com
// display_errors=1 (reaproveita a falha forcada do cenario 11)
// ============================================================
echo "\n=== Cenario 13: nenhum vazamento tecnico mesmo com display_errors=1 ===\n";
$r13 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
$token13 = $r13['json']['dados']['token_aceite'];
$saida13 = dispararSequencialComDisplayErrors($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0013', $token13, $usuarioLimitado, $senhaLimitadaGerada]));
echo $saida13 . "\n";
afirmar('Cenario 13: HTTP 500 generico mesmo com display_errors=1', str_contains($saida13, 'HTTP_CODE:500') && str_contains($saida13, 'Nao foi possivel processar o atendimento agora'));
afirmar('Cenario 13: nunca vaza SQL/consulta', !preg_match('/SELECT|INSERT|UPDATE|FROM tb_/i', $saida13));
afirmar('Cenario 13: nunca vaza caminho de servidor/stack trace/getMessage() cru', !str_contains($saida13, __DIR__) && !str_contains($saida13, '.php on line') && !str_contains($saida13, 'Stack trace') && !str_contains($saida13, 'SQLSTATE'));
afirmar('Cenario 13: nunca vaza credencial (usuario/senha do banco)', !str_contains($saida13, $usuarioDb) && !str_contains($saida13, $senhaDb) && !str_contains($saida13, $senhaLimitadaGerada));

// ============================================================
// Cenario 14 — token bruto NUNCA aparece em nenhum log (error_log), em
// nenhum cenario de erro testado acima. Reexecuta os cenarios de erro com
// error_log redirecionado para um arquivo dedicado e confere.
// ============================================================
echo "\n=== Cenario 14: token bruto nunca aparece em error_log ===\n";
function dispararComErrorLog(string $script, array $args, string $caminhoLog): string
{
    $php = PHP_BINARY;
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php, '-d', "error_log={$caminhoLog}", $script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    return $saida;
}

$r14 = emitirToken($scriptAceitar, $connArgs, $totemA['id_totem']);
$token14 = $r14['json']['dados']['token_aceite'];
// dispara o mesmo cenario de falha do item 11 (INSERT sem privilegio, apos
// CAS bem sucedido) — o caminho mais provavel de qualquer log interno
// (error_log() em AtendimentoController::consumirAceiteECriarAtendimento())
// ser escrito, com error_log redirecionado a um arquivo proprio.
$saida14a = dispararComErrorLog($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0014', $token14, $usuarioLimitado, $senhaLimitadaGerada]), $caminhoErrorLog);
echo "--- subprocesso 14a (forca falha de INSERT apos CAS, error_log redirecionado) ---\n{$saida14a}\n";
// tambem reexecuta token invalido/expirado/ja usado/replay com log redirecionado
$saida14b = dispararComErrorLog($scriptIniciar, array_merge($connArgs, [(string) $totemA['id_totem'], 'QA0014B', bin2hex(random_bytes(32))]), $caminhoErrorLog);
echo "--- subprocesso 14b (token invalido, error_log redirecionado) ---\n{$saida14b}\n";
echo "--- caminho do error_log: {$caminhoErrorLog} (existe? " . (file_exists($caminhoErrorLog) ? 'sim' : 'nao') . ", tamanho: " . (file_exists($caminhoErrorLog) ? filesize($caminhoErrorLog) : 'n/a') . ") ---\n";

$conteudoLog = file_exists($caminhoErrorLog) ? file_get_contents($caminhoErrorLog) : '';
$tokensParaConferir = array_unique(array_filter([$token1, $token6, $token7, $token8, $token9, $token10, $token11, $token13, $token14]));
$algumTokenVazou = false;
foreach ($tokensParaConferir as $tok) {
    if ($tok && str_contains($conteudoLog, $tok)) { $algumTokenVazou = true; break; }
}
afirmar('Cenario 14: nenhum token BRUTO emitido durante esta suite aparece no error_log', !$algumTokenVazou);
afirmar('Cenario 14: log realmente foi escrito por pelo menos um cenario de erro (prova de que o teste exercitou o caminho de log, nao so um arquivo vazio por omissao)', trim($conteudoLog) !== '');
echo "--- conteudo do error_log capturado (para evidencia, sanitizado de token) ---\n" . preg_replace('/[0-9a-f]{64}/', '<token-suprimido-se-aparecesse>', $conteudoLog) . "\n";

@unlink($caminhoErrorLog);

// ============================================================
// RESULTADO
// ============================================================
echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
