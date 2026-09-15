<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_impressao_idor.php — chama
 * App\Controller\ImpressaoAtendimentoController::gerarEtiqueta() diretamente
 * (sem HTTP real). NUNCA chama TalentClient/TalentRn (o controller so le
 * dados ja persistidos).
 *
 * Uso: php _caso_impressao_gerar_etiqueta.php <id_totem> <id_atendimento> [reimpressao=0|1]
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Controller\ImpressaoAtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$reimpressao = ($argv[3] ?? '0') === '1';

$controller = new ImpressaoAtendimentoController(new AtendimentoDao($pdo));

// Resposta::erro()/sucesso() chamam exit() apos http_response_code() —
// register_shutdown_function roda ANTES do processo realmente terminar,
// permitindo capturar o codigo HTTP real que teria sido enviado.
register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

$controller->gerarEtiqueta(['id_atendimento' => $idAtendimento], $idTotem, $reimpressao);
