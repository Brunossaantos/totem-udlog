<?php

/**
 * Teste manual de regressao pontual: AtendimentoController::salvarEtapa()
 * case 'cliente' (identificacao MANUAL do cliente no Recebimento, feita pelo
 * atendente — caminho DIFERENTE do OCR automatico em concluirDigitalizacao()).
 *
 * Ate esta correcao, esse case gravava o cliente mas NUNCA chamava
 * atualizarEtapa(), deixando etapa_atual travada em 'cliente'. Isso quebrava
 * o primeiro upload de rec_cnh em DocumentoController::upload(), que
 * exige a etapa EXATA 'rec_cnh' (ver ETAPAS_UPLOAD).
 *
 * Atualizado na rodada corretiva de migracao-vio-api-br-com-cache
 * (2026-09-26): a etapa de destino era 'rec_cnh_frente' (2 etapas separadas
 * de CNH no Recebimento); agora e 'rec_cnh' (etapa unica, mesmo padrao de
 * exp_cnh na Expedicao, com 2 uploads client-side dentro da mesma etapa).
 *
 * Cobre:
 * - apos salvar-etapa 'cliente', etapa_atual passa a ser 'rec_cnh'
 *   (mesma proxima etapa usada pelo fluxo automatico via OCR, ver
 *   AtendimentoController::concluirDigitalizacao());
 * - o primeiro upload de cnh_frente funciona sem erro de etapa logo em
 *   seguida (exercita DocumentoController::upload de verdade, via
 *   subprocesso, reaproveitando o helper ja existente
 *   _caso_upload_documento.php).
 *
 * Atualizado em 2026-09-09 (correcao de IDOR critico no case 'cliente' de
 * AtendimentoController::salvarEtapa, achado do security-especialista): o
 * case agora exige etapa_atual = 'cliente' antes de aceitar a identificacao
 * manual (mesmo padrao ja usado pelo case 'digitalizacao_notas') — o setup
 * abaixo passou a chamar atualizarEtapa($idAtendimento, 'cliente')
 * explicitamente antes da chamada, reproduzindo o estado real deixado por
 * concluirDigitalizacao() quando nenhuma nota identifica o cliente
 * automaticamente.
 *
 * Uso: php tests/manual/teste_salvar_etapa_cliente_manual.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

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

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_SALVAR_ETAPA_CLI', 'Totem Teste Salvar Etapa Cliente', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$atendimentoDao = new AtendimentoDao($pdo);
$idAtendimento = $atendimentoDao->criar($idTotem, 'recebimento', 'GGG5555');
$pastaTeste = 'teste_salvar_etapa_cli_' . bin2hex(random_bytes(4));
$atendimentoDao->definirPasta($idAtendimento, $pastaTeste);

// O case 'cliente' agora exige etapa_atual = 'cliente' (validacao de posse
// adicionada em 2026-09-09) — reproduz o estado real deixado por
// concluirDigitalizacao() quando nenhuma nota identifica o cliente
// automaticamente, sem precisar rodar a digitalizacao inteira aqui.
// Resposta::sucesso()/erro() chamam exit() -- por isso a chamada real ao
// Controller roda em subprocesso isolado (_caso_salvar_etapa.php), mesmo
// padrao ja usado pelos demais testes manuais deste diretorio.
$atendimentoDao->atualizarEtapa($idAtendimento, 'cliente');
// base64: escapeshellarg() no Windows remove aspas duplas de argumentos,
// corrompendo JSON literal na linha de comando — ver comentario em
// _caso_salvar_etapa.php.
$dadosCliente = base64_encode(json_encode(['nome' => 'CLIENTE TESTE MANUAL LTDA', 'cnpj' => '11222333000181']));
$rSalvarEtapa = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotem, $idAtendimento, 'cliente', $dadosCliente]);
afirmar('salvar-etapa cliente (manual) responde sucesso', str_contains($rSalvarEtapa['saida'], '"sucesso":true'));

$atendimentoAposCliente = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Apos salvar-etapa cliente (manual), etapa_atual avanca para rec_cnh', $atendimentoAposCliente['etapa_atual'] === 'rec_cnh');

$imagemJpegBase64 = 'data:image/jpeg;base64,' . base64_encode(
    base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=')
);

$rUpload = rodarSubprocesso(__DIR__ . '/_caso_upload_documento.php', [$idTotem, $idAtendimento, 'cnh_frente', $imagemJpegBase64]);
afirmar('Primeiro upload de cnh_frente (etapa rec_cnh) funciona sem erro de etapa apos identificacao manual do cliente', str_contains($rUpload['saida'], '"sucesso":true'));

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
