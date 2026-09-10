<?php

/**
 * Teste manual de documento.php?acao=status-processamento
 * (DocumentoController::statusProcessamento) — demanda expedicao-vio-cnh-crlv,
 * REPLANEJAMENTO 2026-09-09.
 *
 * Cobre:
 * - leitura pura NUNCA chama o VIO (usa um "VioDecodeClient espiao" que
 *   lanca excecao se instanciado/chamado — o teste falha se
 *   statusProcessamento() tentar usa-lo);
 * - detecta e marca PROCESSANDO obsoleto (> timeout) como ERRO, terminal=true;
 * - PROCESSANDO recente (nao obsoleto) permanece PROCESSANDO, terminal=false;
 * - rate limit proprio (tb_rate_limit_vio_status) bloqueia apos exceder o
 *   limite na janela, com HTTP 429;
 * - allowlist de etapa (nao aceita fora das etapas permitidas para o
 *   documento) e IDOR (posse do atendimento).
 *
 * Cada cenario roda em subprocesso separado (Resposta::erro/sucesso chamam
 * exit()). Uso: php tests/manual/teste_status_processamento.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_STATUS_PROC', 'Totem Teste Status', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$dao = new AtendimentoDao($pdo);
$idAtendimento = $dao->criar($idTotem, 'expedicao', 'EEE3333');
$dao->atualizarEtapa($idAtendimento, 'exp_crlv');

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function rodarSubprocesso(int $idTotem, int $idAtendimento, string $tipo): array
{
    $php = PHP_BINARY;
    $script = __DIR__ . '/_caso_status_processamento.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $idTotem . ' ' . $idAtendimento . ' ' . $tipo;
    exec($cmd, $saida, $codigo);
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

// Cenario 1: CNH em etapa nao permitida (exp_crlv nao esta na allowlist de CNH
// -> na verdade esta: ['exp_cnh','exp_crlv','exp_aguarde_documentos'] inclui
// exp_crlv. Usamos 'crlv' aqui, que TAMBEM aceita exp_crlv.
$r1 = rodarSubprocesso($idTotem, $idAtendimento, 'crlv');
afirmar('status-processamento aceito na etapa exp_crlv para tipo crlv (allowlist)', str_contains($r1['saida'], '"sucesso":true'));

// Cenario 2: CNH consultada numa etapa fora da allowlist (dados_encontrados)
$dao->atualizarEtapa($idAtendimento, 'dados_encontrados');
$r2 = rodarSubprocesso($idTotem, $idAtendimento, 'cnh');
afirmar('status-processamento bloqueado fora da allowlist de etapa', str_contains($r2['saida'], 'nao esta na etapa esperada'));
$dao->atualizarEtapa($idAtendimento, 'exp_crlv');

// Cenario 3: PROCESSANDO recente (nao obsoleto) -> permanece PROCESSANDO, terminal=false
$tentativa = bin2hex(random_bytes(16));
$dao->iniciarProcessamento($idAtendimento, 'crlv', $tentativa, 30);
$r3 = rodarSubprocesso($idTotem, $idAtendimento, 'crlv');
afirmar('PROCESSANDO recente permanece PROCESSANDO', str_contains($r3['saida'], '"status_processamento":"PROCESSANDO"'));
afirmar('PROCESSANDO recente reporta terminal=false', str_contains($r3['saida'], '"terminal":false'));

// Cenario 4: PROCESSANDO obsoleto (> 30s) -> vira ERRO, terminal=true, SEM
// chamar o VIO (o proprio subprocesso quebraria se chamasse, ver
// _caso_status_processamento.php)
$pdo->prepare('UPDATE tb_atendimento SET crlv_processamento_iniciado_em = DATE_SUB(NOW(), INTERVAL 45 SECOND) WHERE id_atendimento = :id')
    ->execute(['id' => $idAtendimento]);
$r4 = rodarSubprocesso($idTotem, $idAtendimento, 'crlv');
afirmar('PROCESSANDO obsoleto e reportado como ERRO', str_contains($r4['saida'], '"status_processamento":"ERRO"'));
afirmar('PROCESSANDO obsoleto reporta terminal=true', str_contains($r4['saida'], '"terminal":true'));
afirmar('status-processamento NUNCA chama o VIO (subprocesso nao lancou excecao do espiao)', $r4['codigo'] === 0);

// Cenario 5: rate limit — muitas chamadas na mesma janela devem eventualmente
// retornar 429 (limite 20/5s por documento)
$excedeu = false;
for ($i = 0; $i < 30; $i++) {
    $r = rodarSubprocesso($idTotem, $idAtendimento, 'crlv');
    if (str_contains($r['saida'], 'Muitas consultas de status')) {
        $excedeu = true;
        break;
    }
}
afirmar('Rate limit proprio de status-processamento bloqueia apos exceder o limite na janela', $excedeu === true);

// Cenario 6: IDOR — outro totem consultando status de atendimento alheio
$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_STATUS_INVASOR', 'Totem Invasor Status', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotemInvasor = (int) $pdo->lastInsertId();
$r6 = rodarSubprocesso($idTotemInvasor, $idAtendimento, 'crlv');
afirmar('IDOR: totem invasor nao consegue consultar status de atendimento alheio', str_contains($r6['saida'], 'Atendimento nao encontrado'));

// Limpeza
$pdo->prepare('DELETE FROM tb_rate_limit_vio_status WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem IN (:t1, :t2)')->execute(['t1' => $idTotem, 't2' => $idTotemInvasor]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
