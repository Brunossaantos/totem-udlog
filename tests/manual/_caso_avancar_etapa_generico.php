<?php

/**
 * Subprocesso auxiliar — chama AtendimentoController::avancarEtapaDocumentos
 * diretamente (sem HTTP real), generalizado para expedicao E recebimento
 * (demanda expedicao-vio-cnh-crlv, REPLANEJAMENTO 2026-09-09).
 *
 * Uso: php _caso_avancar_etapa_generico.php <id_totem> <id_atendimento>
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
