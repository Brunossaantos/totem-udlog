<?php

/**
 * Teste manual (sem rede) dos anexos em PDF exigidos pelo Talent — item 4
 * do escopo de docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md.
 *
 * Cobre:
 * - Util\AnexoPdfHelper::gerarPdfDeImagens: assinatura %PDF-, contagem EXATA
 *   de paginas (2 para CNH frente+verso, 1 para CRLV/nota/CNH legado),
 *   remocao do arquivo temporario apos a chamada (sucesso e falha antes da
 *   geracao do PDF);
 * - App\Rn\TalentRn::montarAnexos (via Reflection): CNH com fallback legado
 *   (cnh.jpg unico), CRLV obrigatorio (ausencia bloqueia), notas fiscais
 *   1..5 (ordem preservada, nota ausente no disco e pulada sem travar).
 *
 * Uso: php tests/manual/teste_talent_anexos_pdf.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use Util\AnexoPdfHelper;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Dao\EmpresaDao;
use App\Rn\TalentRn;
use App\Rn\TalentClient;

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

function contarArquivosTmp(string $pastaTmp): int
{
    if (!is_dir($pastaTmp)) {
        return 0;
    }
    return count(glob($pastaTmp . '/*.pdf'));
}

$pastaTmp = rtrim($_ENV['STORAGE_PATH'], '/') . '/tmp';
$pastaTrabalho = rtrim($_ENV['STORAGE_PATH'], '/') . '/teste_anexos_pdf_' . bin2hex(random_bytes(4));
mkdir($pastaTrabalho, 0750, true);

// ============================================================
// 1. AnexoPdfHelper::gerarPdfDeImagens — casos diretos
// ============================================================
$imgFrente = $pastaTrabalho . '/cnh_frente.jpg';
$imgVerso = $pastaTrabalho . '/cnh_verso.jpg';
$imgCrlv = $pastaTrabalho . '/crlv.jpg';
talentCriarJpegValido($imgFrente);
talentCriarJpegValido($imgVerso);
talentCriarJpegValido($imgCrlv);

$antesCnh = contarArquivosTmp($pastaTmp);
$pdfCnh = AnexoPdfHelper::gerarPdfDeImagens([$imgFrente, $imgVerso]);
afirmar('CNH (frente+verso): assinatura %PDF- valida', substr($pdfCnh, 0, 5) === '%PDF-');
afirmar('CNH (frente+verso): exatamente 2 paginas', preg_match_all('/\/Type\s*\/Page[^s]/', $pdfCnh) === 2);
afirmar('CNH (frente+verso): arquivo temporario removido apos a chamada (sem sobra em storage/tmp)', contarArquivosTmp($pastaTmp) === $antesCnh);

$antesCrlv = contarArquivosTmp($pastaTmp);
$pdfCrlv = AnexoPdfHelper::gerarPdfDeImagens([$imgCrlv]);
afirmar('CRLV: assinatura %PDF- valida', substr($pdfCrlv, 0, 5) === '%PDF-');
afirmar('CRLV: exatamente 1 pagina', preg_match_all('/\/Type\s*\/Page[^s]/', $pdfCrlv) === 1);
afirmar('CRLV: arquivo temporario removido apos a chamada', contarArquivosTmp($pastaTmp) === $antesCrlv);

// CNH legado (fallback cnh.jpg unico, 1 pagina)
$imgLegado = $pastaTrabalho . '/cnh.jpg';
talentCriarJpegValido($imgLegado);
$pdfLegado = AnexoPdfHelper::gerarPdfDeImagens([$imgLegado]);
afirmar('CNH legado (cnh.jpg unico): assinatura %PDF- valida', substr($pdfLegado, 0, 5) === '%PDF-');
afirmar('CNH legado (cnh.jpg unico): exatamente 1 pagina', preg_match_all('/\/Type\s*\/Page[^s]/', $pdfLegado) === 1);

// Nota fiscal (1 pagina)
$imgNota = $pastaTrabalho . '/nota_01.jpg';
talentCriarJpegValido($imgNota);
$pdfNota = AnexoPdfHelper::gerarPdfDeImagens([$imgNota]);
afirmar('Nota fiscal: assinatura %PDF- valida', substr($pdfNota, 0, 5) === '%PDF-');
afirmar('Nota fiscal: exatamente 1 pagina', preg_match_all('/\/Type\s*\/Page[^s]/', $pdfNota) === 1);

// ============================================================
// 2. Falhas ANTES da geracao do PDF — nunca deixam arquivo temporario
// ============================================================
$antesFalha1 = contarArquivosTmp($pastaTmp);
$lancouAusente = false;
try {
    AnexoPdfHelper::gerarPdfDeImagens([$pastaTrabalho . '/nao_existe.jpg']);
} catch (\RuntimeException $e) {
    $lancouAusente = str_contains($e->getMessage(), 'nao encontrado');
}
afirmar('Imagem ausente lanca RuntimeException', $lancouAusente);
afirmar('Imagem ausente NAO deixa arquivo temporario em storage/tmp', contarArquivosTmp($pastaTmp) === $antesFalha1);

$imgCorrompida = $pastaTrabalho . '/corrompida.jpg';
file_put_contents($imgCorrompida, 'isto nao e um jpeg valido');
$antesFalha2 = contarArquivosTmp($pastaTmp);
$lancouCorrompida = false;
try {
    AnexoPdfHelper::gerarPdfDeImagens([$imgCorrompida]);
} catch (\RuntimeException $e) {
    $lancouCorrompida = str_contains($e->getMessage(), 'nao e um JPEG valido');
}
afirmar('Imagem com magic bytes invalidos lanca RuntimeException', $lancouCorrompida);
afirmar('Imagem corrompida NAO deixa arquivo temporario em storage/tmp', contarArquivosTmp($pastaTmp) === $antesFalha2);

// ============================================================
// 3. Validacao de contagem de paginas via Reflection (forca mismatch) —
// confirma que validarPdfGerado() rejeita PDF com contagem diferente da
// esperada (guarda contra pagina perdida/anexo cortado)
// ============================================================
$reflexaoValidar = new ReflectionMethod(AnexoPdfHelper::class, 'validarPdfGerado');
$reflexaoValidar->setAccessible(true);
$lancouMismatch = false;
try {
    $reflexaoValidar->invoke(null, $pdfCrlv, 2); // pdfCrlv tem 1 pagina real, esperando 2
} catch (\RuntimeException $e) {
    $lancouMismatch = str_contains($e->getMessage(), 'contagem de paginas');
}
afirmar('validarPdfGerado rejeita PDF cuja contagem real de paginas difere da esperada', $lancouMismatch);

// ============================================================
// 4. TalentRn::montarAnexos (via Reflection) — CNH ausente bloqueia,
// CRLV ausente bloqueia, notas 1..5 com uma faltando no disco e pulada
// ============================================================
$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);
$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_ANEXOS_' . bin2hex(random_bytes(3)));
$fix = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'ANX1111');
$idAtendimento = $fix['id_atendimento'];
$pastaAtendimento = $fix['pasta_completa'];

$notaDao->inserir($idAtendimento, 1, 'nota_01.jpg', null, null, false);
$notaDao->inserir($idAtendimento, 2, 'nota_02.jpg', null, null, false); // arquivo NAO sera criado no disco (nota "sumida")
$notaDao->inserir($idAtendimento, 3, 'nota_03.jpg', null, null, false);
talentCriarJpegValido($pastaAtendimento . '/nota_01.jpg');
talentCriarJpegValido($pastaAtendimento . '/nota_03.jpg');
// nota_02.jpg propositalmente ausente do disco

$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);
$reflexaoAnexos = new ReflectionMethod(TalentRn::class, 'montarAnexos');
$reflexaoAnexos->setAccessible(true);

$atendimentoAtual = $atendimentoDao->buscarPorId($idAtendimento);
$notas = $notaDao->listarPorAtendimento($idAtendimento);
$anexos = $reflexaoAnexos->invoke($talentRn, $atendimentoAtual, $notas);

// CNH + CRLV + 2 notas presentes no disco (nota_02 pulada) = 4 anexos
afirmar('montarAnexos: total de anexos = CNH + CRLV + 2 notas presentes (nota ausente no disco e pulada)', count($anexos) === 4);
afirmar('montarAnexos: descricoes na ordem CNH, CRLV, Nota 01, Nota 03', array_column($anexos, 'descricao') === ['CNH', 'CRLV', 'Nota Fiscal 01', 'Nota Fiscal 03']);

// Remove o CRLV do disco — deve bloquear (excecao), nunca "seguir sem CRLV"
unlink($pastaAtendimento . '/crlv.jpg');
$lancouCrlvAusente = false;
try {
    $reflexaoAnexos->invoke($talentRn, $atendimentoAtual, $notas);
} catch (\RuntimeException $e) {
    $lancouCrlvAusente = str_contains($e->getMessage(), 'anexo_crlv_ausente');
}
afirmar('montarAnexos: CRLV ausente no disco BLOQUEIA (excecao anexo_crlv_ausente)', $lancouCrlvAusente);

// Remove tambem a CNH — deve bloquear por CNH (checada primeiro)
unlink($pastaAtendimento . '/cnh_frente.jpg');
unlink($pastaAtendimento . '/cnh_verso.jpg');
$lancouCnhAusente = false;
try {
    $reflexaoAnexos->invoke($talentRn, $atendimentoAtual, $notas);
} catch (\RuntimeException $e) {
    $lancouCnhAusente = str_contains($e->getMessage(), 'anexo_cnh_ausente');
}
afirmar('montarAnexos: CNH ausente no disco (sem fallback legado) BLOQUEIA (excecao anexo_cnh_ausente)', $lancouCnhAusente);

// ============================================================
// Limpeza
// ============================================================
foreach (glob($pastaTrabalho . '/*') as $arquivo) {
    unlink($arquivo);
}
rmdir($pastaTrabalho);
talentLimparPasta($pastaAtendimento);
$pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
