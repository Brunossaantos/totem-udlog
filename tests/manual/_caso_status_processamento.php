<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_status_processamento.php —
 * chama DocumentoController::statusProcessamento diretamente (sem HTTP
 * real). Note que este caso NAO instancia VioDecodeClient em nenhum
 * momento — se DocumentoController::statusProcessamento algum dia passar a
 * chamar a VIO por engano, o unico jeito de o teste pai perceber e via
 * latencia/erro de rede real (statusProcessamento nao deve ter NENHUM
 * caminho de codigo que referencie VioDecodeClient — confirmado por
 * inspecao do codigo-fonte, ver App\Controller\DocumentoController).
 *
 * Uso: php _caso_status_processamento.php <id_totem> <id_atendimento> <tipo>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\RateLimitVioStatusDao;
use App\Rn\DocumentoRn;
use App\Dao\VioCacheDao;
use App\Controller\DocumentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = $argv[3] ?? 'cnh';

$atendimentoDao = new AtendimentoDao($pdo);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao);
$controller = new DocumentoController($atendimentoDao, $documentoRn, $pdo, new RateLimitVioStatusDao($pdo));

$controller->statusProcessamento([
    'id_atendimento' => $idAtendimento,
    'tipo' => $tipo,
], $idTotem);
