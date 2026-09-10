<?php

/**
 * Teste manual (unitario, deterministico, sem rede) da camada ATOMICA de
 * concorrencia/timeout do processamento assincrono de CNH/CRLV
 * (demanda expedicao-vio-cnh-crlv, REPLANEJAMENTO 2026-09-09):
 * App\Dao\AtendimentoDao::iniciarProcessamento/gravarResultadoProcessamento/
 * marcarProcessamentoObsoletoComoErro/marcarProcessamentoConcluido.
 *
 * Cobre exatamente os cenarios exigidos no escopo:
 * - transicao para PROCESSANDO e atomica (rowCount confirma quem adquiriu);
 * - tentativa concorrente enquanto PROCESSANDO nao expirado e negada;
 * - PROCESSANDO obsoleto (> timeout) permite nova tentativa;
 * - resultado de tentativa "zumbi" (tentativa_id ja sobrescrito por uma
 *   tentativa mais nova) e descartado (UPDATE sem efeito);
 * - resultado da tentativa vigente e gravado normalmente.
 *
 * Uso: php tests/manual/teste_concorrencia_processamento_vio.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_CONCORRENCIA', 'Totem Teste Concorrencia', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$dao = new AtendimentoDao($pdo);
$idAtendimento = $dao->criar($idTotem, 'expedicao', 'CCC1111');

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function statusAtual(PDO $pdo, int $id, string $documento): array
{
    $stmt = $pdo->prepare("SELECT {$documento}_status_processamento AS status, {$documento}_tentativa_id AS tentativa FROM tb_atendimento WHERE id_atendimento = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

// Cenario 1: primeira tentativa (status inicial PENDENTE) adquire o processamento
$tentativaA = bin2hex(random_bytes(16));
$adquiriuA = $dao->iniciarProcessamento($idAtendimento, 'cnh', $tentativaA, 30);
afirmar('1a tentativa (PENDENTE) adquire o processamento atomicamente', $adquiriuA === true);

$s1 = statusAtual($pdo, $idAtendimento, 'cnh');
afirmar('Status gravado como PROCESSANDO apos adquirir', $s1['status'] === 'PROCESSANDO');
afirmar('tentativa_id gravado corresponde ao da 1a tentativa', $s1['tentativa'] === $tentativaA);

// Cenario 2: segunda tentativa CONCORRENTE (ainda PROCESSANDO, nao obsoleto) e negada
$tentativaB = bin2hex(random_bytes(16));
$adquiriuB = $dao->iniciarProcessamento($idAtendimento, 'cnh', $tentativaB, 30);
afirmar('2a tentativa concorrente (PROCESSANDO nao obsoleto) e negada', $adquiriuB === false);

$s2 = statusAtual($pdo, $idAtendimento, 'cnh');
afirmar('tentativa_id NAO foi sobrescrito pela 2a tentativa negada', $s2['tentativa'] === $tentativaA);

// Cenario 3: resultado da 1a tentativa (ainda vigente) e gravado normalmente
$gravouA = $dao->gravarResultadoProcessamento($idAtendimento, 'cnh', $tentativaA, 'CONCLUIDO');
afirmar('Resultado da tentativa vigente (A) e gravado com sucesso', $gravouA === true);

$s3 = statusAtual($pdo, $idAtendimento, 'cnh');
afirmar('Status final gravado como CONCLUIDO', $s3['status'] === 'CONCLUIDO');

// Cenario 4: PROCESSANDO obsoleto (forcado via UPDATE direto simulando o
// relogio avancando mais de 30s) permite NOVA tentativa
$tentativaC = bin2hex(random_bytes(16));
$dao->iniciarProcessamento($idAtendimento, 'crlv', $tentativaC, 30); // deixa em PROCESSANDO
$pdo->prepare("UPDATE tb_atendimento SET crlv_processamento_iniciado_em = DATE_SUB(NOW(), INTERVAL 31 SECOND) WHERE id_atendimento = :id")
    ->execute(['id' => $idAtendimento]);

$tentativaD = bin2hex(random_bytes(16));
$adquiriuD = $dao->iniciarProcessamento($idAtendimento, 'crlv', $tentativaD, 30);
afirmar('PROCESSANDO obsoleto (> timeout) permite nova tentativa', $adquiriuD === true);

$s4 = statusAtual($pdo, $idAtendimento, 'crlv');
afirmar('tentativa_id foi substituido pela nova tentativa (D) apos timeout', $s4['tentativa'] === $tentativaD);

// Cenario 5: resultado "zumbi" da tentativa ANTIGA (C, ja obsoleta e
// substituida por D) tenta gravar DEPOIS — deve ser descartado (sem efeito)
$gravouZumbi = $dao->gravarResultadoProcessamento($idAtendimento, 'crlv', $tentativaC, 'ERRO');
afirmar('Escrita da tentativa zumbi (C) NAO tem efeito (tentativa_id ja e D)', $gravouZumbi === false);

$s5 = statusAtual($pdo, $idAtendimento, 'crlv');
afirmar('Status permanece PROCESSANDO (zumbi nao sobrescreveu)', $s5['status'] === 'PROCESSANDO');

// Cenario 6: resultado da tentativa vigente (D) e gravado normalmente
$gravouD = $dao->gravarResultadoProcessamento($idAtendimento, 'crlv', $tentativaD, 'ERRO');
afirmar('Escrita da tentativa vigente (D) tem efeito', $gravouD === true);

$s6 = statusAtual($pdo, $idAtendimento, 'crlv');
afirmar('Status final gravado como ERRO pela tentativa vigente', $s6['status'] === 'ERRO');

// Cenario 7: marcarProcessamentoObsoletoComoErro — so tem efeito se ainda
// PROCESSANDO e de fato obsoleto; idempotente (2a chamada sem efeito)
$pdo->prepare("UPDATE tb_atendimento SET cnh_status_processamento = 'ERRO' WHERE id_atendimento = :id")
    ->execute(['id' => $idAtendimento]); // reseta de CONCLUIDO (cenario 3) para permitir nova tentativa
$idOutraTentativa = bin2hex(random_bytes(16));
$dao->iniciarProcessamento($idAtendimento, 'cnh', $idOutraTentativa, 30);
$pdo->prepare("UPDATE tb_atendimento SET cnh_processamento_iniciado_em = DATE_SUB(NOW(), INTERVAL 45 SECOND) WHERE id_atendimento = :id")
    ->execute(['id' => $idAtendimento]);

$primeiraChamada = $dao->marcarProcessamentoObsoletoComoErro($idAtendimento, 'cnh', 30);
afirmar('marcarProcessamentoObsoletoComoErro detecta e marca ERRO na 1a chamada', $primeiraChamada === true);

$segundaChamada = $dao->marcarProcessamentoObsoletoComoErro($idAtendimento, 'cnh', 30);
afirmar('marcarProcessamentoObsoletoComoErro e idempotente (2a chamada sem efeito)', $segundaChamada === false);

// Cenario 8: marcarProcessamentoConcluido (usado pelo preenchimento manual)
// marca CONCLUIDO diretamente, sem checagem de tentativa_id
$dao->marcarProcessamentoConcluido($idAtendimento, 'crlv');
$s8 = statusAtual($pdo, $idAtendimento, 'crlv');
afirmar('marcarProcessamentoConcluido marca CONCLUIDO diretamente (preenchimento manual)', $s8['status'] === 'CONCLUIDO');

// Limpeza
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
