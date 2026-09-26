<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_status_processamento.php —
 * chama DocumentoController::statusProcessamento diretamente (sem HTTP
 * real). Reescrito na rodada corretiva de migracao-vio-api-br-com-cache
 * (2026-09-26): o fluxo real de statusProcessamento() hoje e via
 * App\Rn\VioApiBrClient, nunca mais App\Rn\VioDecodeClient. Quando o
 * atendimento tem uma tentativa PENDENTE de consulta
 * (PROCESSANDO_LEITURA/PROCESSANDO_COMPARACAO com ID externo), este
 * subprocesso FAZ, de proposito, exatamente 1 GET real (controlado pelo
 * teste pai via VIO_API_BR_BASE_URL/API_KEY, sempre apontando para uma
 * porta local fechada — nunca rede real/externa) — o teste pai
 * (teste_status_processamento.php) e quem decide, cenario a cenario, se o
 * ambiente tera essas envs presentes ou nao.
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

// Ponte DB_NAME/VIO_API_BR_* herdados via putenv() do processo pai (banco
// `qa_` descartavel da rodada corretiva de migracao-vio-api-br-com-cache,
// 2026-09-26) para $_ENV -- ver qaDbTrechoPonteEnvSubprocesso() em
// tests/manual/qa_db_bootstrap.php para a explicacao completa do porque isso
// e necessario (Dotenv imutavel nunca promove sozinho um valor so-getenv()
// para $_ENV).
foreach (['DB_NAME', 'VIO_API_BR_BASE_URL', 'VIO_API_BR_API_KEY'] as $qaChaveHerdada) {
    $qaValorHerdado = getenv($qaChaveHerdada);
    if ($qaValorHerdado !== false) {
        $_ENV[$qaChaveHerdada] = $qaValorHerdado;
    }
}

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
