<?php

/**
 * Teste manual (item 5 do roteiro de testes da demanda
 * talent-doctos-finalizacao-checkin, 2026-09-14) — IDOR do novo endpoint de
 * impressao real (App\Controller\ImpressaoAtendimentoController). Confirma
 * que:
 *
 * - totem ALHEIO (invasor) nao consegue gerar etiqueta de um atendimento de
 *   outro totem (mensagem generica "Atendimento nao encontrado", 404 — nao
 *   revela existencia do atendimento alheio);
 * - dono legitimo com check-in AINDA NAO confirmado (talent_checkin_status
 *   != ENVIADO ou talent_senha NULL) recebe erro explicito, NUNCA gera PDF
 *   com dado ausente/forjado;
 * - dono legitimo com check-in confirmado recebe a etiqueta (pdf_base64 +
 *   identificador), com nome do motorista + nrRegAcesso lidos do banco
 *   (nunca aceitos do corpo da requisicao — o body enviado so tem
 *   id_atendimento);
 * - reimpressao manual gera um identificador NOVO a cada chamada, mesmo
 *   nrRegAcesso ja persistido (nunca redispara chamada ao Talent — nao ha
 *   nenhum TalentClient/TalentRn instanciado neste fluxo).
 *
 * Uso: php tests/manual/teste_impressao_idor.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;

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

function rodarCaso(int $idTotem, int $idAtendimento, bool $reimpressao = false): array
{
    $php = PHP_BINARY;
    $script = __DIR__ . '/_caso_impressao_gerar_etiqueta.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $idTotem . ' ' . $idAtendimento . ' ' . ($reimpressao ? '1' : '0');
    exec($cmd, $saida, $codigo);
    $texto = implode("\n", $saida);
    return ['texto' => $texto, 'codigo' => $codigo];
}

$atendimentoDao = new AtendimentoDao($pdo);

$idTotemVitima = talentCriarTotemComEmpresa($pdo, 'TESTE_IMPR_IDOR_VITIMA_' . bin2hex(random_bytes(3)), 1);
$idTotemInvasor = talentCriarTotemComEmpresa($pdo, 'TESTE_IMPR_IDOR_INVASOR_' . bin2hex(random_bytes(3)), 1);

$idsAtendimento = [];

// ============================================================
// Fixture 1: atendimento com check-in CONFIRMADO (concluido/ENVIADO/senha)
// ============================================================
$idAt1 = $atendimentoDao->criar($idTotemVitima, 'expedicao', 'IMP1111');
$idsAtendimento[] = $idAt1;
$atendimentoDao->atualizarValidacaoCnh($idAt1, 'MOTORISTA IMPRESSAO TESTE', '11144477735', '2030-01-01', 'MANUAL', 'PENDENTE_REVISAO');
$pdo->prepare("
    UPDATE tb_atendimento
    SET status = 'concluido', etapa_atual = 'impressao', talent_checkin_status = 'ENVIADO',
        talent_senha = 'SENHA-TESTE-123', talent_enviado_em = NOW()
    WHERE id_atendimento = :id
")->execute(['id' => $idAt1]);

// ============================================================
// Fixture 2: atendimento do MESMO totem vitima, mas check-in AINDA NAO
// confirmado (em_andamento, talent_checkin_status NAO_ENVIADO)
// ============================================================
$idAt2 = $atendimentoDao->criar($idTotemVitima, 'expedicao', 'IMP2222');
$idsAtendimento[] = $idAt2;

// ============================================================
// Caso 1: IDOR — totem INVASOR tenta gerar etiqueta do atendimento
// CONFIRMADO da vitima
// ============================================================
$r1 = rodarCaso($idTotemInvasor, $idAt1);
afirmar('IDOR: totem invasor NAO consegue gerar etiqueta de atendimento alheio (sem sucesso:true)', !str_contains($r1['texto'], '"sucesso":true'));
afirmar('IDOR: mensagem generica "Atendimento nao encontrado" (nao revela existencia do atendimento alheio)', str_contains($r1['texto'], 'Atendimento nao encontrado'));
afirmar('IDOR: HTTP 404', str_contains($r1['texto'], 'HTTP_CODE:404'));
afirmar('IDOR: resposta NUNCA contem o nrRegAcesso real do atendimento alheio', !str_contains($r1['texto'], 'SENHA-TESTE-123'));

// ============================================================
// Caso 2: dono legitimo, mas check-in AINDA NAO confirmado — erro
// explicito, nunca gera PDF com dado ausente
// ============================================================
$r2 = rodarCaso($idTotemVitima, $idAt2);
afirmar('Check-in nao confirmado: dono legitimo recebe erro (sem sucesso:true)', !str_contains($r2['texto'], '"sucesso":true'));
afirmar('Check-in nao confirmado: HTTP 409', str_contains($r2['texto'], 'HTTP_CODE:409'));

// ============================================================
// Caso 3: dono legitimo, check-in confirmado — sucesso, PDF gerado
// ============================================================
$r3 = rodarCaso($idTotemVitima, $idAt1);
afirmar('Dono legitimo com check-in confirmado: sucesso (sucesso:true)', str_contains($r3['texto'], '"sucesso":true'));
afirmar('Dono legitimo: resposta contem pdf_base64', str_contains($r3['texto'], 'pdf_base64'));
afirmar('Dono legitimo: resposta contem identificador', str_contains($r3['texto'], 'identificador'));
$dados3 = json_decode(trim(explode("\nHTTP_CODE:", $r3['texto'])[0]), true);
$identificador1 = $dados3['dados']['identificador'] ?? null;
afirmar('Dono legitimo: identificador nao vazio', !empty($identificador1));

// ============================================================
// Caso 4: reimpressao manual — NOVO identificador a cada chamada, mesmo
// nrRegAcesso ja persistido (nunca redispara chamada ao Talent)
// ============================================================
$r4 = rodarCaso($idTotemVitima, $idAt1, true);
$dados4 = json_decode(trim(explode("\nHTTP_CODE:", $r4['texto'])[0]), true);
$identificador2 = $dados4['dados']['identificador'] ?? null;
afirmar('Reimpressao: sucesso (sucesso:true)', str_contains($r4['texto'], '"sucesso":true'));
afirmar('Reimpressao: identificador NOVO, diferente da 1a geracao (nunca reaproveita job anterior)', !empty($identificador2) && $identificador2 !== $identificador1);

// ============================================================
// Limpeza
// ============================================================
foreach ($idsAtendimento as $id) {
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem IN (:a, :b)')->execute(['a' => $idTotemVitima, 'b' => $idTotemInvasor]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
