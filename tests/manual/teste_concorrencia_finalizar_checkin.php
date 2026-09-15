<?php

/**
 * Teste de CONCORRENCIA REAL (subprocessos via proc_open, quase simultaneos)
 * para AtendimentoController::finalizar()/App\Rn\TalentRn::processarCheckin
 * apos a demanda talent-doctos-finalizacao-checkin (2026-09-14) — item
 * "concorrencia" do roteiro de /02-testes. NUNCA chama o Talent real (mock
 * em processo isolado / TalentClient('','') local).
 *
 * Parte 1 — "2 cliques quase simultaneos em Finalizar" no MESMO atendimento,
 * pelo caminho REAL de producao (AtendimentoController::finalizar(), mesmo
 * script _caso_finalizar.php ja usado por teste_talent_idor_finalizar.php).
 * Ambiente de teste NAO tem TALENT_CHECKIN_ATIVO=true (fail-closed, mesmo do
 * .env real) — confirma que o gate novo (doctos[]/TALENT_CHECKIN_ATIVO) e
 * 100% sem estado compartilhado mutavel (so leitura), entao os 2 processos
 * concorrentes recebem a MESMA resposta limpa (503 TALENT_CHECKIN_DESATIVADO)
 * sem nenhuma corrupcao/inconsistencia de estado — nenhuma janela de corrida
 * NOVA foi introduzida antes do CAS de idempotencia.
 *
 * Parte 2 — CAS de idempotencia (talent_checkin_status) sob concorrencia REAL
 * com o payload NOVO (doctos[] populado a partir de notas/ordem_coleta reais)
 * — via App\Rn\TalentRn::processarCheckin() direto (TalentClient mockado,
 * ver _caso_processar_checkin_direto.php), bypassando propositalmente o gate
 * TALENT_CHECKIN_ATIVO do controller (inatingivel neste ambiente) para provar
 * que o mecanismo de baixo nivel reaproveitado sem alteracao continua
 * garantindo exatamente 1 processamento efetivo mesmo com o payload novo.
 *
 * Uso: php tests/manual/teste_concorrencia_finalizar_checkin.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
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

$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_CONC_FIN_' . bin2hex(random_bytes(3)), 1);
$pastas = [];
$idsAtendimento = [];

// ============================================================
// Parte 1 — 2 cliques quase simultaneos em finalizar() (caminho REAL,
// TALENT_CHECKIN_ATIVO ausente/fail-closed neste ambiente)
// ============================================================
$fix1 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'DCK1111', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-DCK1111');
$pastas[] = $fix1['pasta_completa'];
$idsAtendimento[] = $fix1['id_atendimento'];

$scriptFinalizar = __DIR__ . '/_caso_finalizar.php';
$saidasFinalizar = dispararParalelo($scriptFinalizar, [
    'a' => [(string) $idTotem, (string) $fix1['id_atendimento']],
    'b' => [(string) $idTotem, (string) $fix1['id_atendimento']],
]);

afirmar('Parte 1: os 2 cliques quase simultaneos recebem 503 TALENT_CHECKIN_DESATIVADO (nenhum efeito colateral novo antes do CAS)', str_contains($saidasFinalizar['a'], 'TALENT_CHECKIN_DESATIVADO') && str_contains($saidasFinalizar['b'], 'TALENT_CHECKIN_DESATIVADO'));
afirmar('Parte 1: nenhum dos dois chega a "sera processada em instantes" (202) nem a sucesso', !str_contains($saidasFinalizar['a'], 'sera processada em instantes') && !str_contains($saidasFinalizar['b'], 'sera processada em instantes') && !str_contains($saidasFinalizar['a'], '"sucesso":true') && !str_contains($saidasFinalizar['b'], '"sucesso":true'));

$statusPos1 = $atendimentoDao->buscarPorId($fix1['id_atendimento'])['talent_checkin_status'];
afirmar('Parte 1: talent_checkin_status permanece EXATAMENTE NAO_ENVIADO apos os 2 cliques concorrentes (o gate novo nao muta estado)', $statusPos1 === 'NAO_ENVIADO');

echo "--- Parte 1: saida A ---\n{$saidasFinalizar['a']}\n--- Parte 1: saida B ---\n{$saidasFinalizar['b']}\n";

// ============================================================
// Parte 2 — CAS de idempotencia sob concorrencia real, payload NOVO
// (doctos[] real), TalentClient mockado (bypass proposital do gate
// TALENT_CHECKIN_ATIVO, inatingivel neste ambiente)
// ============================================================
$fix2 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'DCK2222');
$pastas[] = $fix2['pasta_completa'];
$idsAtendimento[] = $fix2['id_atendimento'];
talentInserirNotaComNumero($notaDao, $fix2['id_atendimento'], 1, '999');

$scriptCheckin = __DIR__ . '/_caso_processar_checkin_direto.php';
$saidasCheckin = dispararParalelo($scriptCheckin, [
    'a' => [(string) $fix2['id_atendimento']],
    'b' => [(string) $fix2['id_atendimento']],
]);

// Estados aceitaveis para o "perdedor" da corrida dependem do TIMING exato:
// se o vencedor JA tinha terminado quando o perdedor chegou ao CAS, o
// perdedor recebe JA_ENVIADO (idempotente, resultado ja persistido); se os
// dois chegaram ao CAS enquanto o vencedor AINDA estava processando, o
// perdedor recebe EM_ANDAMENTO (409 "aguarde", tambem correto — nao e uma
// falha, e o mesmo comportamento ja validado por
// tests/manual/teste_talent_idempotencia.php item 6, so exposto aqui a
// partir de processarCheckin() com o payload NOVO doctos[]). O que NUNCA
// pode acontecer e o perdedor tambem virar ENVIADO (dois processamentos
// efetivos para o mesmo atendimento).
$aEnviado = str_contains($saidasCheckin['a'], '"status":"ENVIADO"');
$aIdempotente = str_contains($saidasCheckin['a'], '"status":"JA_ENVIADO"') || str_contains($saidasCheckin['a'], '"status":"EM_ANDAMENTO"');
$bEnviado = str_contains($saidasCheckin['b'], '"status":"ENVIADO"');
$bIdempotente = str_contains($saidasCheckin['b'], '"status":"JA_ENVIADO"') || str_contains($saidasCheckin['b'], '"status":"EM_ANDAMENTO"');

afirmar('Parte 2: processo A termina em ENVIADO ou estado idempotente (JA_ENVIADO/EM_ANDAMENTO), nunca outro estado', $aEnviado || $aIdempotente);
afirmar('Parte 2: processo B termina em ENVIADO ou estado idempotente (JA_ENVIADO/EM_ANDAMENTO), nunca outro estado', $bEnviado || $bIdempotente);
afirmar('Parte 2: EXATAMENTE um dos dois processou de fato (ENVIADO), o outro foi idempotente (JA_ENVIADO/EM_ANDAMENTO) — CAS preservado com payload novo (doctos[])', ($aEnviado xor $bEnviado) && ($aIdempotente xor $bIdempotente));

echo "--- Parte 2: saida A ---\n{$saidasCheckin['a']}\n--- Parte 2: saida B ---\n{$saidasCheckin['b']}\n";

$atendimentoFinal2 = $atendimentoDao->buscarPorId($fix2['id_atendimento']);
afirmar('Parte 2: estado final e ENVIADO consolidado no banco (nunca preso em ENVIANDO)', $atendimentoFinal2['talent_checkin_status'] === 'ENVIADO');
afirmar('Parte 2: talent_senha final e a do mock (unica, sem sobrescrita parcial de corrida)', $atendimentoFinal2['talent_senha'] === 'SENHA_CONC_MOCK');

// ============================================================
// Limpeza
// ============================================================
foreach ($pastas as $p) {
    talentLimparPasta($p);
}
foreach ($idsAtendimento as $id) {
    $pdo->prepare('DELETE FROM tb_fila_envio WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
