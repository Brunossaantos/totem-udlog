<?php

/**
 * Teste manual do fluxo de Recebimento (demanda expedicao-vio-cnh-crlv,
 * REPLANEJAMENTO 2026-09-09; REESCRITO na rodada corretiva de QA de
 * migracao-vio-api-br-com-cache, 2026-09-26, apos a unificacao da etapa de
 * CNH): rec_cnh -> rec_crlv -> rec_aguarde_documentos -> rec_confirmacao.
 *
 * A etapa 'rec_cnh_frente'/'rec_cnh_verso' (2 etapas distintas do backend,
 * com uma transicao de etapa entre o upload da frente e do verso) NAO EXISTE
 * MAIS — 'rec_cnh' e uma unica etapa (mesmo padrao ja usado por exp_cnh na
 * Expedicao), com 2 uploads separados (cnh_frente/cnh_verso, cada um sua
 * propria chamada a DocumentoController::upload) MAS sem troca de etapa
 * entre eles. O gate 'upload_cnh' (AtendimentoController::
 * gateDeTransicaoLiberado) so libera rec_cnh -> rec_crlv quando AMBOS os
 * arquivos (cnh_frente.jpg E cnh_verso.jpg) ja estao salvos em disco.
 *
 * Cobre:
 * - upload da frente da CNH sozinho NAO libera a transicao de etapa (gate
 *   'upload_cnh' bloqueia com "documentos pendentes" enquanto faltar o
 *   verso) — comportamento NOVO desta rodada, antes (2 etapas separadas) o
 *   upload da frente sozinho ja liberava a transicao para a etapa do verso;
 * - upload do verso completa o par e libera rec_cnh -> rec_crlv numa UNICA
 *   transicao de etapa (nunca mais 2 transicoes separadas para a CNH);
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

// Sufixo aleatorio no codigo sintetico (mesmo padrao ja usado no projeto para
// token_api) + teardown via register_shutdown_function — roda mesmo se o
// script terminar com excecao/erro fatal no meio, evitando residuo em
// udlog_totem mesmo em caso de falha do teste.
$sufixoAleatorio = bin2hex(random_bytes(4));
$idTotemGlobal = null;
$idAtendimentoGlobal = null;
$pastaTesteGlobal = null;

register_shutdown_function(function () use ($pdo, &$idTotemGlobal, &$idAtendimentoGlobal, &$pastaTesteGlobal) {
    if ($idAtendimentoGlobal !== null) {
        $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimentoGlobal]);
    }
    if ($idTotemGlobal !== null) {
        $pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotemGlobal]);
    }
    if ($pastaTesteGlobal !== null) {
        $storagePath = rtrim($_ENV['STORAGE_PATH'] ?? '', '/') . '/' . $pastaTesteGlobal;
        if (is_dir($storagePath)) {
            foreach (glob($storagePath . '/*') as $arquivo) {
                @unlink($arquivo);
            }
            @rmdir($storagePath);
        }
    }
});

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_REC_DOC_{$sufixoAleatorio}', 'Totem Teste Recebimento Doc', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();
$idTotemGlobal = $idTotem;

$atendimentoDao = new AtendimentoDao($pdo);
$idAtendimento = $atendimentoDao->criar($idTotem, 'recebimento', 'FFF4444');
$idAtendimentoGlobal = $idAtendimento;
$atendimentoDao->atualizarEtapa($idAtendimento, 'rec_cnh');

$pastaTeste = 'teste_rec_doc_' . bin2hex(random_bytes(4));
$pastaTesteGlobal = $pastaTeste;
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

// --- Etapa unica rec_cnh: upload da FRENTE sozinho NAO libera a transicao ---
$r1 = rodarSubprocesso(__DIR__ . '/_caso_upload_documento.php', [$idTotem, $idAtendimento, 'cnh_frente', $imagemJpegBase64]);
afirmar('Upload da frente da CNH aceito em rec_cnh', str_contains($r1['saida'], '"sucesso":true'));

$atendimentoAposFrente = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('etapa_atual permanece rec_cnh apos upload SO da frente (nunca existiu rec_cnh_verso)', $atendimentoAposFrente['etapa_atual'] === 'rec_cnh');

$r1b = rodarSubprocesso(__DIR__ . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtendimento]);
afirmar('rec_cnh -> rec_crlv BLOQUEADO com so a frente da CNH salva (gate upload_cnh exige os 2 arquivos)', str_contains($r1b['saida'], 'documentos pendentes'));

$atendimentoAposGateBloqueado = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('etapa_atual continua rec_cnh apos a tentativa bloqueada (nenhuma transicao parcial)', $atendimentoAposGateBloqueado['etapa_atual'] === 'rec_cnh');

// --- Etapa unica rec_cnh: upload do VERSO completa o par e libera a transicao ---
$r2 = rodarSubprocesso(__DIR__ . '/_caso_upload_documento.php', [$idTotem, $idAtendimento, 'cnh_verso', $imagemJpegBase64]);
afirmar('Upload do verso da CNH aceito em rec_cnh (mesma etapa da frente)', str_contains($r2['saida'], '"sucesso":true'));

$r2b = rodarSubprocesso(__DIR__ . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtendimento]);
afirmar('rec_cnh -> rec_crlv permitido com os 2 lados salvos (CNH ainda NAO aprovada pelo VIO — processamento assincrono)', str_contains($r2b['saida'], '"etapa":"rec_crlv"'));

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
