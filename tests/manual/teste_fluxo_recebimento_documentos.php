<?php

/**
 * Teste manual do fluxo NOVO de Recebimento (demanda expedicao-vio-cnh-crlv,
 * REPLANEJAMENTO 2026-09-09: VIO Decode passa a valer tambem em
 * Recebimento): rec_cnh_frente -> rec_cnh_verso -> rec_crlv ->
 * rec_aguarde_documentos -> rec_confirmacao.
 *
 * Cobre:
 * - upload separado de frente/verso da CNH (etapas distintas, ao contrario
 *   da Expedicao que usa uma unica etapa exp_cnh);
 * - avancarEtapaDocumentos() generalizado funciona para tipo='recebimento';
 * - gate de upload libera a etapa seguinte mesmo com CNH ainda NAO aprovada
 *   pelo VIO (processamento assincrono em segundo plano);
 * - "documento ja aprovado e preservado, nao reprocessado" (CNH aprovada +
 *   CRLV nao -> preenchimento manual so do CRLV nao mexe na CNH).
 *
 * Uso: php tests/manual/teste_fluxo_recebimento_documentos.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_REC_DOC', 'Totem Teste Recebimento Doc', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$atendimentoDao = new AtendimentoDao($pdo);
$idAtendimento = $atendimentoDao->criar($idTotem, 'recebimento', 'FFF4444');
$atendimentoDao->atualizarEtapa($idAtendimento, 'rec_cnh_frente');

$pastaTeste = 'teste_rec_doc_' . bin2hex(random_bytes(4));
$atendimentoDao->definirPasta($idAtendimento, $pastaTeste);

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function rodarSubprocesso(string $script, array $args): array
{
    $php = PHP_BINARY;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string) $a);
    }
    exec($cmd, $saida, $codigo);
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

$imagemJpegBase64 = 'data:image/jpeg;base64,' . base64_encode(
    // JPEG minimo valido (1x1 branco) — mesmo usado pelos demais testes de upload do projeto
    base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=')
);

// --- Etapa 1: upload frente da CNH (rec_cnh_frente) ---
$r1 = rodarSubprocesso(__DIR__ . '/_caso_upload_documento.php', [$idTotem, $idAtendimento, 'cnh_frente', $imagemJpegBase64]);
afirmar('Upload da frente da CNH aceito em rec_cnh_frente', str_contains($r1['saida'], '"sucesso":true'));

$r1b = rodarSubprocesso(__DIR__ . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtendimento]);
afirmar('rec_cnh_frente -> rec_cnh_verso permitido apos upload da frente', str_contains($r1b['saida'], '"etapa":"rec_cnh_verso"'));

// --- Etapa 2: upload verso da CNH (rec_cnh_verso) ---
$r2 = rodarSubprocesso(__DIR__ . '/_caso_upload_documento.php', [$idTotem, $idAtendimento, 'cnh_verso', $imagemJpegBase64]);
afirmar('Upload do verso da CNH aceito em rec_cnh_verso', str_contains($r2['saida'], '"sucesso":true'));

$r2b = rodarSubprocesso(__DIR__ . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtendimento]);
afirmar('rec_cnh_verso -> rec_crlv permitido so com upload (CNH ainda NAO aprovada pelo VIO)', str_contains($r2b['saida'], '"etapa":"rec_crlv"'));

// --- Etapa 3: upload do CRLV (rec_crlv) ---
$r3 = rodarSubprocesso(__DIR__ . '/_caso_upload_documento.php', [$idTotem, $idAtendimento, 'crlv', $imagemJpegBase64]);
afirmar('Upload do CRLV aceito em rec_crlv', str_contains($r3['saida'], '"sucesso":true'));

$r3b = rodarSubprocesso(__DIR__ . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtendimento]);
afirmar('rec_crlv -> rec_aguarde_documentos permitido so com upload (CRLV ainda NAO aprovado)', str_contains($r3b['saida'], '"etapa":"rec_aguarde_documentos"'));

// --- Etapa 4: aguarde_documentos -> confirmacao SEM aprovacao deve falhar ---
$r4 = rodarSubprocesso(__DIR__ . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtendimento]);
afirmar('rec_aguarde_documentos -> rec_confirmacao bloqueado sem CNH/CRLV aprovados', str_contains($r4['saida'], 'documentos pendentes'));

// --- Aprova CNH via VIO (mock, direto no banco) e CRLV via preenchimento MANUAL ---
$atendimentoDao->atualizarValidacaoCnh($idAtendimento, 'MARIA OLIVEIRA', '11144477735', '2031-01-01', 'VIO_TRIAL', 'OK');

// Preenchimento manual do CRLV via subprocesso real (exercita
// DocumentoController::preencherManual, allowlist rec_aguarde_documentos)
$rManual = rodarSubprocesso(__DIR__ . '/_caso_preencher_manual.php', [$idTotem, $idAtendimento, 'crlv', 'FFF4444', '2025']);
afirmar('Preenchimento manual do CRLV aceito em rec_aguarde_documentos', str_contains($rManual['saida'], '"origem":"MANUAL"'));

// Confirma que a CNH (ja aprovada via VIO) NAO foi tocada pelo
// preenchimento manual do CRLV — preserva o resultado, nao reprocessa.
$atendimentoAtual = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('CNH permanece com origem VIO_TRIAL (preservada, nao reprocessada pelo preenchimento manual do CRLV)', $atendimentoAtual['cnh_origem_validacao'] === 'VIO_TRIAL');
afirmar('CRLV foi gravado como MANUAL', $atendimentoAtual['crlv_origem_validacao'] === 'MANUAL');

// --- Etapa 5: aguarde_documentos -> confirmacao COM ambos aprovados ---
$r5 = rodarSubprocesso(__DIR__ . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtendimento]);
afirmar('rec_aguarde_documentos -> rec_confirmacao permitido com ambos aprovados (CNH via VIO + CRLV via MANUAL)', str_contains($r5['saida'], '"etapa":"rec_confirmacao"'));

// Limpeza
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);
$storagePath = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pastaTeste;
if (is_dir($storagePath)) {
    foreach (glob($storagePath . '/*') as $arquivo) {
        unlink($arquivo);
    }
    rmdir($storagePath);
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
