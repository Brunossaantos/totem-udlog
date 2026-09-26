<?php

/**
 * Subprocesso auxiliar (demanda talent-doctos-finalizacao-checkin,
 * 2026-09-14) — chama AtendimentoController::concluirDigitalizacao()
 * diretamente (sem HTTP real).
 *
 * Uso: php _caso_concluir_digitalizacao.php <id_totem> <id_atendimento>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Dao\OrdemColetaDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Controller\AtendimentoController;

// Ponte DB_NAME herdado via putenv() do processo pai (banco `qa_`
// descartavel, quando aplicavel) para $_ENV -- ver
// qaDbTrechoPonteEnvSubprocesso() em tests/manual/qa_db_bootstrap.php. Sem
// efeito (no-op) quando nenhum processo pai fez putenv('DB_NAME=...')
// (ex.: teste_e2e_recebimento_expedicao_mock.php, que continua conectando
// normalmente no banco configurado em .env).
if (getenv('DB_NAME') !== false) {
    $_ENV['DB_NAME'] = getenv('DB_NAME');
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao()));
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$controller = new AtendimentoController($atendimentoRn, $talentRn, new AtendimentoNotaDao($pdo));

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

$controller->concluirDigitalizacao(['id_atendimento' => $idAtendimento], $idTotem);
