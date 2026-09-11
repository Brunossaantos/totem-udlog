<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_consulta_ordem_coleta.php —
 * chama AtendimentoController::iniciar diretamente (sem HTTP real), tipo
 * fixo 'expedicao'. Resposta::sucesso()/erro() chamam exit(), por isso roda
 * isolado (mesmo padrao ja usado por outros _caso_*.php deste diretorio).
 *
 * Uso: php _caso_iniciar_expedicao.php <id_totem> <placa>
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

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$placa = $argv[2] ?? '';

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao()));
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$controller = new AtendimentoController($atendimentoRn, $talentRn, new AtendimentoNotaDao($pdo));

$controller->iniciar($idTotem, ['tipo' => 'expedicao', 'placa' => $placa]);
