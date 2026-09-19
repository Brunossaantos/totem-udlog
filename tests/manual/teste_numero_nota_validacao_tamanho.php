<?php

/**
 * Teste da correcao do achado bloqueante da fase 1 de /02-testes
 * (2026-09-14) para a demanda talent-doctos-finalizacao-checkin:
 * App\Rn\NotaFiscalRn::atualizarNumeroNota() aceitava e truncava
 * SILENCIOSAMENTE valores acima do tamanho da coluna
 * tb_atendimento_nota.numero_nota (VARCHAR(20)), inclusive uma chave de
 * acesso de 44 digitos, sem rejeitar explicitamente.
 *
 * Cobre os 12 cenarios do roteiro de correcao:
 *  1. Numero valido comum ("123") — aceito.
 *  2. Numero com zeros a esquerda ("00123") — aceito, normalizado p/ "123".
 *  3. Numero com exatamente 1 digito ("5") — aceito.
 *  4. Numero com exatamente 9 digitos ("123456789") — aceito (limite maximo).
 *  5. Valor vazio ("") — rejeitado.
 *  6. Valor so zeros ("0", "00", "0000") — rejeitado.
 *  7. Letras/caracteres misturados ("12A34", "abc", "12-34") — rejeitado.
 *  8. Exatamente 10 digitos ("1234567890") — rejeitado (1 acima do limite).
 *  9. Exatamente 20 digitos — rejeitado.
 * 10. Exatamente 44 digitos (chave de acesso sintetica) — rejeitado.
 * 11. Tentativa "pela API" (via NotaController::definirNumero, mesmo
 *     caminho usado por public/api/nota.php, em subprocesso isolado —
 *     bypassando qualquer filtro de front) com 44 digitos — rejeitado.
 * 12. Para CADA cenario rejeitado: confirma que tb_atendimento_nota.
 *     numero_nota continua NULL (nunca truncado/gravado parcialmente).
 *
 * NUNCA chama o Talent, nunca faz impressao real, nunca altera ordem real.
 *
 * Uso: php tests/manual/teste_numero_nota_validacao_tamanho.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\RateLimitOcrDao;
use App\Rn\NotaFiscalRn;
use App\Controller\NotaController;

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

$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);
$notaFiscalRn = new NotaFiscalRn($notaDao, new ClienteDao($pdo));
// RateLimitOcrDao obrigatorio desde a demanda robustez-rate-limit-migrations
// (2026-09-18) -- este teste nao exercita rate limit, mas o construtor do
// Controller agora exige a dependencia real de qualquer forma.
$controller = new NotaController($notaFiscalRn, $atendimentoDao, new RateLimitOcrDao($pdo));

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_NUM_TAM_' . bin2hex(random_bytes(3)));
$idAtendimento = $atendimentoDao->criar($idTotem, 'recebimento', 'CNN2222');
$atendimentoDao->atualizarEtapa($idAtendimento, 'digitalizacao_notas');

// uma unica nota (ordem 1), reutilizada por todos os cenarios — cada
// cenario aceito reseta numero_nota para NULL antes do proximo, para nao
// interferir na checagem de "continua NULL apos rejeicao" dos cenarios
// seguintes nem esbarrar na UNIQUE KEY entre cenarios validos.
$idNota = $notaDao->inserir($idAtendimento, 1, 'nota_01.jpg', null, null, false);

function resetarNumeroNota(PDO $pdo, int $idNota): void
{
    $pdo->prepare('UPDATE tb_atendimento_nota SET numero_nota = NULL, numero_nota_origem = NULL WHERE id_nota = :id')
        ->execute(['id' => $idNota]);
}

function lerNumeroNota(PDO $pdo, int $idNota): ?string
{
    $stmt = $pdo->prepare('SELECT numero_nota FROM tb_atendimento_nota WHERE id_nota = :id');
    $stmt->execute(['id' => $idNota]);
    $valor = $stmt->fetchColumn();
    return $valor === false ? null : $valor;
}

/**
 * Executa um cenario "aceito": chama atualizarNumeroNota diretamente,
 * confirma o retorno normalizado e o valor persistido, depois reseta.
 */
function cenarioAceito(NotaFiscalRn $rn, PDO $pdo, int $idAtendimento, int $idNota, string $bruto, string $esperadoNormalizado, string $descricao): void
{
    try {
        $resultado = $rn->atualizarNumeroNota($idAtendimento, 1, $bruto, 'MANUAL');
        afirmar($descricao . ' — aceito e normalizado corretamente', ($resultado['numero_nota'] ?? null) === $esperadoNormalizado);
        afirmar($descricao . ' — persistido corretamente no banco', lerNumeroNota($pdo, $idNota) === $esperadoNormalizado);
    } catch (\Throwable $e) {
        afirmar($descricao . ' — aceito (mas lancou excecao inesperada: ' . get_class($e) . ')', false);
    } finally {
        resetarNumeroNota($pdo, $idNota);
    }
}

/**
 * Executa um cenario "rejeitado": chama atualizarNumeroNota diretamente,
 * confirma InvalidArgumentException('numero_invalido') e que o banco
 * continua NULL (item 12 do roteiro).
 */
function cenarioRejeitado(NotaFiscalRn $rn, PDO $pdo, int $idAtendimento, int $idNota, string $bruto, string $descricao): void
{
    $lancou = false;
    $mensagemCorreta = false;
    try {
        $rn->atualizarNumeroNota($idAtendimento, 1, $bruto, 'MANUAL');
    } catch (\InvalidArgumentException $e) {
        $lancou = true;
        $mensagemCorreta = $e->getMessage() === 'numero_invalido';
    } catch (\Throwable $e) {
        $lancou = true;
        $mensagemCorreta = false;
    }
    afirmar($descricao . ' — rejeitado com InvalidArgumentException(numero_invalido)', $lancou && $mensagemCorreta);
    afirmar($descricao . ' — banco continua com numero_nota NULL (nunca truncado/gravado)', lerNumeroNota($pdo, $idNota) === null);
}

echo "=== Cenarios aceitos ===\n";

// 1. Numero valido comum
cenarioAceito($notaFiscalRn, $pdo, $idAtendimento, $idNota, '123', '123', 'Cenario 1: numero valido comum ("123")');

// 2. Zeros a esquerda
cenarioAceito($notaFiscalRn, $pdo, $idAtendimento, $idNota, '00123', '123', 'Cenario 2: zeros a esquerda ("00123")');

// 3. Exatamente 1 digito
cenarioAceito($notaFiscalRn, $pdo, $idAtendimento, $idNota, '5', '5', 'Cenario 3: exatamente 1 digito ("5")');

// 4. Exatamente 9 digitos (limite maximo valido)
cenarioAceito($notaFiscalRn, $pdo, $idAtendimento, $idNota, '123456789', '123456789', 'Cenario 4: exatamente 9 digitos ("123456789", limite maximo)');

echo "\n=== Cenarios rejeitados ===\n";

// 5. Vazio
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, '', 'Cenario 5: valor vazio ("")');

// 6. So zeros
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, '0', 'Cenario 6a: so zeros ("0")');
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, '00', 'Cenario 6b: so zeros ("00")');
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, '0000', 'Cenario 6c: so zeros ("0000")');

// 7. Letras/caracteres misturados
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, '12A34', 'Cenario 7a: caracteres misturados ("12A34")');
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, 'abc', 'Cenario 7b: letras ("abc")');
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, '12-34', 'Cenario 7c: caracteres especiais ("12-34")');

// 8. Exatamente 10 digitos (1 acima do limite)
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, '1234567890', 'Cenario 8: exatamente 10 digitos ("1234567890", 1 acima do limite)');

// 9. Exatamente 20 digitos (tamanho da coluna, ainda assim rejeitado)
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, '12345678901234567890', 'Cenario 9: exatamente 20 digitos (tamanho da coluna)');

// 10. Exatamente 44 digitos (chave de acesso sintetica, nunca dado real)
$chaveAcessoSintetica44 = '35240912345678000199550010000012345678901234';
afirmar('Cenario 10 (sanidade da fixture): chave sintetica tem exatamente 44 digitos', strlen($chaveAcessoSintetica44) === 44 && ctype_digit($chaveAcessoSintetica44));
cenarioRejeitado($notaFiscalRn, $pdo, $idAtendimento, $idNota, $chaveAcessoSintetica44, 'Cenario 10: chave de acesso sintetica de 44 digitos');

echo "\n=== Cenario 11: tentativa direta pela API (NotaController::definirNumero, subprocesso isolado) ===\n";

// Subprocesso isolado, mesmo caminho de codigo que public/api/nota.php
// invoca (NotaController::definirNumero -> NotaFiscalRn::atualizarNumeroNota
// -> AtendimentoNotaDao::atualizarNumero), simulando "bypass do front" com
// um valor de 44 digitos enviado diretamente.
$script = __DIR__ . '/_caso_nota_definir_numero.php';
$cmd = [PHP_BINARY, $script, (string) $idTotem, (string) $idAtendimento, '1', $chaveAcessoSintetica44, 'MANUAL'];
$descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$processo = proc_open($cmd, $descritores, $pipes);
$saidaApi = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($processo);

afirmar('Cenario 11: chamada via NotaController::definirNumero (caminho da API) com 44 digitos retorna HTTP 400', str_contains($saidaApi, 'HTTP_CODE:400'));
afirmar('Cenario 11: resposta e generica, nao vaza detalhe de validacao/schema interno', str_contains($saidaApi, '"sucesso":false') && !str_contains($saidaApi, 'VARCHAR') && !str_contains($saidaApi, 'numero_invalido'));
afirmar('Cenario 11 (item 12): banco continua com numero_nota NULL apos tentativa via API', lerNumeroNota($pdo, $idNota) === null);

echo "--- saida do subprocesso (API) ---\n{$saidaApi}\n";

// ============================================================
// Limpeza
// ============================================================
$pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
