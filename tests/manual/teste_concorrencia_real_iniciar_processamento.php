<?php

/**
 * Teste de CONCORRENCIA REAL (2 subprocessos PHP disparados quase
 * simultaneamente via proc_open, nao sequencial) para
 * documento.php?acao=iniciar-processamento contra o Trial VIVO do Serpro
 * (demanda expedicao-vio-cnh-crlv, REPLANEJAMENTO 2026-09-09).
 *
 * Confirma que, entre as duas chamadas concorrentes para o MESMO
 * atendimento+documento, so UMA de fato processa (chama a VIO e grava o
 * resultado) — a outra recebe uma resposta IDEMPOTENTE (contendo a chave
 * 'status_processamento', usada so no caminho de "ja em andamento/ja
 * concluido") sem reprocessar.
 *
 * Uso: php tests/manual/teste_concorrencia_real_iniciar_processamento.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_CONC_REAL', 'Totem Teste Concorrencia Real', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$dao = new AtendimentoDao($pdo);
$idAtendimento = $dao->criar($idTotem, 'expedicao', 'DDD2222');
$dao->atualizarEtapa($idAtendimento, 'exp_cnh');

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

/**
 * Dispara os dois subprocessos QUASE SIMULTANEAMENTE via proc_open (nao
 * bloqueante ate o momento de ler a saida), para de fato exercitar a
 * corrida entre os dois processos reais do SO — nao apenas duas chamadas
 * sequenciais no mesmo processo PHP.
 */
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

$script = __DIR__ . '/_caso_iniciar_processamento.php';
$saidas = dispararParalelo($script, [
    'a' => [(string) $idTotem, (string) $idAtendimento, 'cnh'],
    'b' => [(string) $idTotem, (string) $idAtendimento, 'cnh'],
]);

$aTemStatusProcessamento = str_contains($saidas['a'], 'status_processamento');
$bTemStatusProcessamento = str_contains($saidas['b'], 'status_processamento');

// Exatamente UM dos dois deve ter processado de fato (resposta SEM a chave
// 'status_processamento', que so aparece no caminho idempotente/ja
// aprovado — App\Controller\DocumentoController::respostaStatusAtual) — o
// outro deve ter recebido a resposta idempotente (COM 'status_processamento').
afirmar('Exatamente um dos dois processou de fato (o outro foi idempotente)', $aTemStatusProcessamento xor $bTemStatusProcessamento);

echo "--- saida A ---\n{$saidas['a']}\n--- saida B ---\n{$saidas['b']}\n";

// Confirma no banco que o processamento terminou em um estado TERMINAL
// (CONCLUIDO ou ERRO), nunca preso em PROCESSANDO (nenhuma tentativa
// zumbi deveria ter deixado o registro travado)
$stmt = $pdo->prepare('SELECT cnh_status_processamento FROM tb_atendimento WHERE id_atendimento = :id');
$stmt->execute(['id' => $idAtendimento]);
$statusFinal = $stmt->fetchColumn();
afirmar('Status final e terminal (CONCLUIDO ou ERRO), nunca preso em PROCESSANDO', in_array($statusFinal, ['CONCLUIDO', 'ERRO'], true));

// Limpeza
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
