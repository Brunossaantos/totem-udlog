<?php

/**
 * Teste de CONCORRENCIA REAL (2 subprocessos PHP via proc_open, quase
 * simultaneos, nao sequenciais) para nota.php?acao=definir-numero — demanda
 * talent-doctos-finalizacao-checkin (2026-09-14), item "concorrencia" do
 * roteiro de /02-testes.
 *
 * Cenario: duas notas DIFERENTES (ordem 1 e ordem 2) do MESMO atendimento
 * tentam gravar o MESMO numero_nota ('789') quase ao mesmo tempo. Confirma
 * que a UNIQUE KEY uk_atendimento_numero_nota (id_atendimento, numero_nota)
 * garante que so UMA das duas seja aceita mesmo sob corrida real — a outra
 * recebe erro amigavel NUMERO_NOTA_DUPLICADO (HTTP 409), nunca duas linhas
 * gravadas com o mesmo numero.
 *
 * NUNCA chama o Talent (nao passa perto de finalizar()).
 *
 * Uso: php tests/manual/teste_concorrencia_numero_nota_duplicado.php
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

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_CONC_NUM_' . bin2hex(random_bytes(3)));
$idAtendimento = $atendimentoDao->criar($idTotem, 'recebimento', 'CNN1111');
$atendimentoDao->atualizarEtapa($idAtendimento, 'digitalizacao_notas');

// 2 notas ja digitalizadas (sem numero ainda), mesmo padrao de producao
// (NotaFiscalRn::processarLeitura via NotaDao::inserir)
$idNota1 = $notaDao->inserir($idAtendimento, 1, 'nota_01.jpg', null, null, false);
$idNota2 = $notaDao->inserir($idAtendimento, 2, 'nota_02.jpg', null, null, false);

$script = __DIR__ . '/_caso_nota_definir_numero.php';
$saidas = dispararParalelo($script, [
    'a' => [(string) $idTotem, (string) $idAtendimento, '1', '789', 'MANUAL'],
    'b' => [(string) $idTotem, (string) $idAtendimento, '2', '789', 'MANUAL'],
]);

$aSucesso = str_contains($saidas['a'], '"sucesso":true');
$bSucesso = str_contains($saidas['b'], '"sucesso":true');

afirmar('Concorrencia REAL (2 processos): exatamente UMA das duas gravacoes do mesmo numero_nota foi aceita', $aSucesso xor $bSucesso);

$saidaPerdedora = $aSucesso ? $saidas['b'] : $saidas['a'];
afirmar('A gravacao perdedora recebe erro amigavel NUMERO_NOTA_DUPLICADO (nunca a excecao bruta do PDO)', str_contains($saidaPerdedora, 'NUMERO_NOTA_DUPLICADO'));
afirmar('A gravacao perdedora recebe HTTP 409', str_contains($saidaPerdedora, 'HTTP_CODE:409'));

echo "--- saida A ---\n{$saidas['a']}\n--- saida B ---\n{$saidas['b']}\n";

// Confirma no banco: EXATAMENTE 1 linha com numero_nota = '789' neste atendimento
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_atendimento_nota WHERE id_atendimento = :id AND numero_nota = '789'");
$stmt->execute(['id' => $idAtendimento]);
$totalComNumero789 = (int) $stmt->fetchColumn();
afirmar('Banco tem EXATAMENTE 1 linha com numero_nota=789 para este atendimento (nunca duas)', $totalComNumero789 === 1);

// A nota perdedora continua com numero_nota NULL (nunca ficou com um numero
// parcial/incorreto por causa da corrida)
$notaPerdedoraOrdem = $aSucesso ? 2 : 1;
$stmt2 = $pdo->prepare('SELECT numero_nota FROM tb_atendimento_nota WHERE id_atendimento = :id AND ordem = :ordem');
$stmt2->execute(['id' => $idAtendimento, 'ordem' => $notaPerdedoraOrdem]);
$numeroPerdedora = $stmt2->fetchColumn();
afirmar('A nota que perdeu a corrida permanece com numero_nota NULL (nao gravou nada parcial)', $numeroPerdedora === null || $numeroPerdedora === false);

// ============================================================
// Limpeza
// ============================================================
$pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
