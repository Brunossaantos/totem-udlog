<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_avancar_etapa_expedicao.php —
 * chama AtendimentoController::avancarEtapaExpedicao diretamente (sem HTTP
 * real) simulando um totem ja autenticado (id_totem passado por argv, papel
 * equivalente ao retorno de Util\Auth::validarTotem()).
 *
 * Uso: php _caso_avancar_etapa.php <id_totem> <id_atendimento>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\DocumentoRn;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Controller\AtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient('', ''));
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo));

$controller = new AtendimentoController($atendimentoRn, $talentRn, new AtendimentoNotaDao($pdo), $documentoRn);

$controller->avancarEtapaDocumentos(['id_atendimento' => $idAtendimento], $idTotem);
