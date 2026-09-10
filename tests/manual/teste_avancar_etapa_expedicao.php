<?php

/**
 * Teste manual da maquina de estados de Expedicao (dados_encontrados ->
 * exp_cnh -> exp_crlv -> exp_aguarde_documentos -> exp_confirmacao ->
 * impressao) — ATUALIZADO no REPLANEJAMENTO de 2026-09-09
 * (expedicao-vio-cnh-crlv): exp_cnh->exp_crlv e exp_crlv->exp_aguarde_documentos
 * agora exigem so que a FOTO tenha sido enviada (upload/arquivo em disco),
 * NAO que a validacao VIO ja tenha terminado (roda em segundo plano). A
 * aprovacao efetiva so e exigida no gate exp_aguarde_documentos->exp_confirmacao
 * e revalidada de novo em exp_confirmacao->impressao.
 *
 * Cada cenario roda em um subprocesso PHP separado porque
 * Util\Resposta::erro()/sucesso() chamam exit() — captura via saida
 * (stdout) + codigo de retorno do processo filho. Cria e limpa seus
 * proprios dados (banco E pasta em STORAGE_PATH).
 *
 * Uso: php tests/manual/teste_avancar_etapa_expedicao.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_AVANCAR', 'Totem Teste Avancar', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$atendimentoDao = new AtendimentoDao($pdo);
$idAtendimento = $atendimentoDao->criar($idTotem, 'expedicao', 'ABC1234');
$atendimentoDao->atualizarEtapa($idAtendimento, 'dados_encontrados');

$pastaTeste = 'teste_avancar_etapa_' . bin2hex(random_bytes(4));
$atendimentoDao->definirPasta($idAtendimento, $pastaTeste);
$pastaCompleta = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pastaTeste;
mkdir($pastaCompleta, 0750, true);

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function rodarSubprocesso(int $idTotem, int $idAtendimento): array
{
    $php = PHP_BINARY;
    $script = __DIR__ . '/_caso_avancar_etapa.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $idTotem . ' ' . $idAtendimento;
    exec($cmd, $saida, $codigo);
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

// Cenario 1: dados_encontrados -> exp_cnh (sem exigir nenhum upload previo)
$r1 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('dados_encontrados -> exp_cnh permitido sem upload previo', str_contains($r1['saida'], '"etapa":"exp_cnh"'));

// Cenario 2: exp_cnh -> exp_crlv SEM upload de CNH deve falhar
$r2 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('exp_cnh -> exp_crlv bloqueado sem upload da CNH', str_contains($r2['saida'], 'documentos pendentes'));

// Simula upload das fotos de CNH (frente+verso) — so o ARQUIVO precisa
// existir, a validacao VIO em si NAO e exigida para este gate.
file_put_contents($pastaCompleta . '/cnh_frente.jpg', 'fake');
file_put_contents($pastaCompleta . '/cnh_verso.jpg', 'fake');

// Cenario 3: exp_cnh -> exp_crlv COM upload feito deve funcionar, MESMO SEM
// a CNH estar aprovada pelo VIO ainda (processamento assincrono em segundo
// plano) — este e o requisito central do REPLANEJAMENTO assincrono.
$r3 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('exp_cnh -> exp_crlv permitido so com upload (CNH ainda NAO aprovada)', str_contains($r3['saida'], '"etapa":"exp_crlv"'));

// Cenario 4: exp_crlv -> exp_aguarde_documentos SEM upload do CRLV deve falhar
$r4 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('exp_crlv -> exp_aguarde_documentos bloqueado sem upload do CRLV', str_contains($r4['saida'], 'documentos pendentes'));

file_put_contents($pastaCompleta . '/crlv.jpg', 'fake');

// Cenario 5: exp_crlv -> exp_aguarde_documentos COM upload feito
$r5 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('exp_crlv -> exp_aguarde_documentos permitido so com upload (CRLV ainda NAO aprovado)', str_contains($r5['saida'], '"etapa":"exp_aguarde_documentos"'));

// Cenario 6: exp_aguarde_documentos -> exp_confirmacao SEM CNH/CRLV aprovados deve falhar
$r6 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('exp_aguarde_documentos -> exp_confirmacao bloqueado sem CNH/CRLV aprovados', str_contains($r6['saida'], 'documentos pendentes'));

// Aprova CNH e CRLV manualmente no banco (simulando iniciar-processamento/
// preencher-manual ja concluidos)
$atendimentoDao->atualizarValidacaoCnh($idAtendimento, 'JOAO DA SILVA', '11144477735', '2030-01-01', 'MANUAL', 'PENDENTE_REVISAO');
$atendimentoDao->atualizarValidacaoCrlv($idAtendimento, 'ABC1234', 2025, 'SP', '12345678', 'CAMINHAO', 'MANUAL', 'PENDENTE_REVISAO');

// Cenario 7: exp_aguarde_documentos -> exp_confirmacao COM ambos aprovados
$r7 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('exp_aguarde_documentos -> exp_confirmacao permitido com ambos aprovados', str_contains($r7['saida'], '"etapa":"exp_confirmacao"'));

// Cenario 8: exp_confirmacao -> impressao deve RECHECAR CNH/CRLV, nao apenas
// confiar na etapa ja alcancada. Forca NAO_VALIDADO em ambos e confirma bloqueio.
$atendimentoDao->atualizarValidacaoCnh($idAtendimento, 'JOAO DA SILVA', '11144477735', '2030-01-01', 'NAO_VALIDADO', 'PENDENTE_REVISAO');
$atendimentoDao->atualizarValidacaoCrlv($idAtendimento, 'ABC1234', 2025, 'SP', '12345678', 'CAMINHAO', 'NAO_VALIDADO', 'PENDENTE_REVISAO');
$r8 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('exp_confirmacao -> impressao bloqueado quando CNH/CRLV voltam a NAO_VALIDADO', str_contains($r8['saida'], 'documentos pendentes') && !str_contains($r8['saida'], '"etapa":"impressao"'));

// Restaura aprovacao de CNH/CRLV antes do cenario 9
$atendimentoDao->atualizarValidacaoCnh($idAtendimento, 'JOAO DA SILVA', '11144477735', '2030-01-01', 'MANUAL', 'PENDENTE_REVISAO');
$atendimentoDao->atualizarValidacaoCrlv($idAtendimento, 'ABC1234', 2025, 'SP', '12345678', 'CAMINHAO', 'MANUAL', 'PENDENTE_REVISAO');

// Cenario 9: exp_confirmacao -> impressao
$r9 = rodarSubprocesso($idTotem, $idAtendimento);
afirmar('exp_confirmacao -> impressao permitido', str_contains($r9['saida'], '"etapa":"impressao"'));

// Cenario 10: IDOR — outro totem tentando avancar etapa de atendimento alheio
$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_AVANCAR_INVASOR', 'Totem Invasor', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotemInvasor = (int) $pdo->lastInsertId();
$r10 = rodarSubprocesso($idTotemInvasor, $idAtendimento);
afirmar('IDOR: totem invasor nao consegue avancar etapa de atendimento alheio', str_contains($r10['saida'], 'Atendimento nao encontrado'));

// Limpeza (banco + pasta em STORAGE_PATH)
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem IN (:t1, :t2)')->execute(['t1' => $idTotem, 't2' => $idTotemInvasor]);
foreach (glob($pastaCompleta . '/*') as $arquivo) {
    unlink($arquivo);
}
rmdir($pastaCompleta);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
