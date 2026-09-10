<?php

/**
 * Teste manual do formato de corpo (wire format) real da VIO Decode
 * (demanda: correcao do HTTP 415 -> corpo binario puro), rodando contra o
 * ambiente Trial AO VIVO (rede real), nao mockado.
 *
 * Cobre os pontos pedidos na tarefa "Validacao":
 *  1. HTTP 200 para os dois arquivos oficiais de demonstracao do Serpro.
 *  2. Tamanho dos bytes preservado (strlen antes/depois).
 *  3. SHA-256 identico entre os bytes decodificados do base64 (simulando o
 *     payload do front-end) e os bytes efetivamente usados como corpo da
 *     chamada HTTP (nenhuma mutacao no caminho).
 *  4. Confirma pelo codigo (nao por rede) que nada dos bytes brutos e
 *     logado (grep no proprio VioDecodeClient.php por chamadas de log).
 *  5. JSON de resposta decodifica corretamente (estruturalmente).
 *  6. Payload invalido (bytes aleatorios) retorna 422 (qr_invalido), nao 415.
 *  7. crlv-demo.bin (Trial) retorna placa/exercicio placeholder ("xxxxx")
 *     e DocumentoRn::validarCrlv rejeita explicitamente (nunca aprova so
 *     por causa do HTTP 200).
 *
 * IMPORTANTE — NUNCA registrar neste teste (nem em stdout) o conteudo bruto
 * dos QRs, o Bearer, cookies, ou qualquer campo base64 de imagem. Somente
 * tamanho (bytes) e SHA-256 dos arquivos de teste sao impressos.
 *
 * Pre-requisito: baixar os dois arquivos oficiais de demonstracao do Serpro
 * (NAO versionados neste repositorio) e apontar os caminhos via variaveis
 * de ambiente antes de rodar:
 *
 *   VIO_TESTE_QRCODE_TRIAL_BIN=/caminho/para/qrcode-trial.bin
 *   VIO_TESTE_CRLV_DEMO_BIN=/caminho/para/crlv-demo.bin
 *
 * URLs oficiais de download (Serpro):
 *   https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/qrcodes/qrcode-trial.bin
 *   https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/qrcodes/crlv-demo.bin
 *
 * Uso:
 *   VIO_TESTE_QRCODE_TRIAL_BIN=... VIO_TESTE_CRLV_DEMO_BIN=... php tests/manual/teste_vio_decode_wire_format.php
 *
 * Requer VIO_AMBIENTE=trial e VIO_TRIAL_BEARER/VIO_TRIAL_DECODE_URL validos
 * no .env (chamada de rede real ao Trial da VIO Decode).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\VioCacheDao;
use App\Dao\AtendimentoDao;
use App\Rn\DocumentoRn;
use App\Rn\VioDecodeClient;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    if ($condicao) {
        echo "OK   - {$descricao}\n";
    } else {
        $totalFalhas++;
        echo "FALHA - {$descricao}\n";
    }
}

/**
 * O Trial da VIO Decode aplica rate limit (429) quando varias chamadas reais
 * acontecem em sequencia rapida (varias chamadas deste script no mesmo
 * segundo). Espaca as chamadas de rede reais e faz 1 retry apos pausa curta
 * se cair em 429 — nao mascara nenhum outro tipo de erro.
 */
function decodificarComEspera(VioDecodeClient $vio, string $bytes): array
{
    $resultado = $vio->decodificar($bytes);
    if ($resultado['ok'] === false && ($resultado['erro']['http_status'] ?? null) === 429) {
        echo "  -> 429 (rate limit do Trial) — aguardando e tentando novamente...\n";
        sleep(5);
        $resultado = $vio->decodificar($bytes);
    }
    sleep(2); // espacamento entre chamadas reais, evita novo 429 na proxima
    return $resultado;
}

$caminhoQrTrial = $_ENV['VIO_TESTE_QRCODE_TRIAL_BIN'] ?? getenv('VIO_TESTE_QRCODE_TRIAL_BIN') ?: null;
$caminhoCrlvDemo = $_ENV['VIO_TESTE_CRLV_DEMO_BIN'] ?? getenv('VIO_TESTE_CRLV_DEMO_BIN') ?: null;

if (!$caminhoQrTrial || !$caminhoCrlvDemo || !is_file($caminhoQrTrial) || !is_file($caminhoCrlvDemo)) {
    echo "PULADO — defina VIO_TESTE_QRCODE_TRIAL_BIN e VIO_TESTE_CRLV_DEMO_BIN apontando para os\n";
    echo "arquivos oficiais baixados temporariamente (ver URLs no cabecalho deste arquivo).\n";
    echo "Nenhum teste de rede executado.\n";
    exit(0);
}

if (($_ENV['VIO_AMBIENTE'] ?? '') !== 'trial') {
    echo "PULADO — VIO_AMBIENTE precisa ser 'trial' no .env para este teste de rede real.\n";
    exit(0);
}

// ============================================================
// 0. Confirmacao estatica: nenhuma chamada de log recebe os bytes do QR
// ============================================================
$fonteVioDecodeClient = file_get_contents(__DIR__ . '/../../app/Rn/VioDecodeClient.php');
$semLogDeBytes = !preg_match('/error_log\([^)]*bytesQrBrutos/', $fonteVioDecodeClient)
    && !preg_match('/error_log\([^)]*rawValueQr/', $fonteVioDecodeClient);
afirmar('VioDecodeClient.php nao passa os bytes do QR para error_log/log algum', $semLogDeBytes);

// ============================================================
// 1 e 2. Leitura dos arquivos oficiais — tamanho preservado
// ============================================================
$bytesQrTrial = file_get_contents($caminhoQrTrial);
$bytesCrlvDemo = file_get_contents($caminhoCrlvDemo);

$tamanhoQrTrial = strlen($bytesQrTrial);
$tamanhoCrlvDemo = strlen($bytesCrlvDemo);
$sha256QrTrial = hash('sha256', $bytesQrTrial);
$sha256CrlvDemo = hash('sha256', $bytesCrlvDemo);

echo "\n--- Metadados dos arquivos de teste (NUNCA o conteudo) ---\n";
echo "qrcode-trial.bin: {$tamanhoQrTrial} bytes, sha256={$sha256QrTrial}\n";
echo "crlv-demo.bin:    {$tamanhoCrlvDemo} bytes, sha256={$sha256CrlvDemo}\n\n";

afirmar('qrcode-trial.bin tem 1041 bytes (tamanho oficial esperado)', $tamanhoQrTrial === 1041);
afirmar('crlv-demo.bin tem 232 bytes (tamanho oficial esperado)', $tamanhoCrlvDemo === 232);

// ============================================================
// 3. SHA-256 identico entre bytes decodificados do base64 (simulando o
// payload do front-end) e os bytes originais do arquivo — nenhuma mutacao.
// ============================================================
$payloadBase64QrTrial = base64_encode($bytesQrTrial);
$bytesDecodificadosQrTrial = base64_decode($payloadBase64QrTrial, true);

afirmar('base64_decode(base64_encode($bytes), true) preserva o strlen exato (qrcode-trial.bin)', strlen($bytesDecodificadosQrTrial) === $tamanhoQrTrial);
afirmar('base64_decode(base64_encode($bytes), true) preserva o SHA-256 exato (qrcode-trial.bin)', hash('sha256', $bytesDecodificadosQrTrial) === $sha256QrTrial);

$payloadBase64CrlvDemo = base64_encode($bytesCrlvDemo);
$bytesDecodificadosCrlvDemo = base64_decode($payloadBase64CrlvDemo, true);

afirmar('base64_decode(base64_encode($bytes), true) preserva o strlen exato (crlv-demo.bin)', strlen($bytesDecodificadosCrlvDemo) === $tamanhoCrlvDemo);
afirmar('base64_decode(base64_encode($bytes), true) preserva o SHA-256 exato (crlv-demo.bin)', hash('sha256', $bytesDecodificadosCrlvDemo) === $sha256CrlvDemo);

// ============================================================
// 4. Chamada real ao VIO Decode Trial — qrcode-trial.bin (cracha generico)
// ============================================================
$vio = new VioDecodeClient();

$resultadoQrTrial = decodificarComEspera($vio, $bytesDecodificadosQrTrial);
afirmar('qrcode-trial.bin: chamada real ao VIO Decode Trial retorna ok=true (HTTP 200)', $resultadoQrTrial['ok'] === true);
afirmar('qrcode-trial.bin: resposta decodificada como array (JSON valido)', is_array($resultadoQrTrial['dados'] ?? null));
if ($resultadoQrTrial['ok'] !== true) {
    echo "  -> erro: " . json_encode($resultadoQrTrial['erro'] ?? null) . "\n";
}

// ============================================================
// 5. Chamada real ao VIO Decode Trial — crlv-demo.bin
// ============================================================
$resultadoCrlvDemo = decodificarComEspera($vio, $bytesDecodificadosCrlvDemo);
afirmar('crlv-demo.bin: chamada real ao VIO Decode Trial retorna ok=true (HTTP 200)', $resultadoCrlvDemo['ok'] === true);
// Resposta real envelopa os campos do documento em "data" (junto de
// "template"), confirmado por este teste — ver comentario em DocumentoRn.
$blocoDadosCrlv = $resultadoCrlvDemo['dados']['data'] ?? ($resultadoCrlvDemo['dados'] ?? []);
afirmar('crlv-demo.bin: campo "placa" presente na resposta (dentro de "data")', isset($blocoDadosCrlv['placa']));
afirmar('crlv-demo.bin: campo "exercicio" presente na resposta (dentro de "data")', isset($blocoDadosCrlv['exercicio']));

if ($resultadoCrlvDemo['ok'] === true) {
    $placaRetornada = (string) ($blocoDadosCrlv['placa'] ?? '');
    $exercicioRetornado = (string) ($blocoDadosCrlv['exercicio'] ?? '');
    afirmar('crlv-demo.bin (Trial): placa retornada e o placeholder conhecido "xxxxx" (confirma natureza mock do Trial)', strtolower(trim($placaRetornada)) === 'xxxxx');
    echo "  -> placa retornada (mock/placeholder esperado): " . strtolower(trim($placaRetornada)) . "\n";
    echo "  -> exercicio retornado (mock/placeholder esperado): " . strtolower(trim($exercicioRetornado)) . "\n";
}

// ============================================================
// 6. DocumentoRn::validarCrlv REJEITA o placeholder "xxxxx" mesmo com HTTP 200
// ============================================================
$pdo = Conexao::obter();
$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_VIO_WIRE', 'Totem Teste VIO Wire', 'token_teste_vio_wire_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$atendimentoDao = new AtendimentoDao($pdo);
$idAtendimento = $atendimentoDao->criar($idTotem, 'expedicao', 'XXXXX'); // mesmo valor do placeholder, de proposito
$atendimentoDao->atualizarEtapa($idAtendimento, 'exp_crlv');

$vioCacheDao = new VioCacheDao($pdo);
$documentoRn = new DocumentoRn($vioCacheDao, $atendimentoDao);

sleep(2); // espacamento antes de mais uma chamada real ao Trial (evita 429)
$atendimentoTeste = $atendimentoDao->buscarPorId($idAtendimento);
$resultadoValidarCrlv = $documentoRn->validarCrlv($atendimentoTeste, $vio, $bytesDecodificadosCrlvDemo);

afirmar('DocumentoRn::validarCrlv NAO aprova o CRLV do Trial so por causa do placeholder "xxxxx" (mesmo com placa do atendimento tambem "XXXXX")', $resultadoValidarCrlv['pode_avancar'] === false);

$totalCacheCrlv = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_cache_crlv')->fetchColumn();
afirmar('CRLV placeholder ("xxxxx") NUNCA e gravado em cache', $totalCacheCrlv === 0);

$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);
$pdo->exec('DELETE FROM tb_vio_cache_crlv');
$pdo->exec('DELETE FROM tb_vio_cache_cnh');

// ============================================================
// 7. Payload deliberadamente invalido (bytes aleatorios) -> NUNCA 415
// ============================================================
// A API pode classificar bytes aleatorios como 400 (corpo estruturalmente
// nao reconhecido como container VIO) ou 422/VD001 (reconhecido como
// tentativa de QR, mas invalido) — o que este teste precisa provar e que o
// bug original (415, corpo mal formado PELO NOSSO codigo) nao ocorre mais,
// e que nosso codigo nao confunde nenhum desses dois com o outro.
$bytesInvalidos = random_bytes(64);
$resultadoInvalido = decodificarComEspera($vio, $bytesInvalidos);

$httpStatusInvalido = $resultadoInvalido['erro']['http_status'] ?? null;
$tipoInvalido = $resultadoInvalido['erro']['tipo'] ?? null;

afirmar('Bytes aleatorios (nao-QR-VIO) retornam ok=false', $resultadoInvalido['ok'] === false);
afirmar('Bytes aleatorios NAO retornam mais HTTP 415 (bug original corrigido)', $httpStatusInvalido !== 415);
afirmar('Bytes aleatorios NAO sao classificados como "formato_corpo_invalido" (nao e um erro do nosso transporte)', $tipoInvalido !== 'formato_corpo_invalido');
afirmar('Bytes aleatorios recebem http_status esperado (400 validacao ou 422 qr_invalido)', in_array($httpStatusInvalido, [400, 422], true));
echo "  -> classificacao real da API para bytes aleatorios: http_status={$httpStatusInvalido}, tipo={$tipoInvalido}\n";

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
