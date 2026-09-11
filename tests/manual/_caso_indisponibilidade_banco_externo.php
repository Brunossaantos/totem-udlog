<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_consulta_ordem_coleta.php —
 * simula indisponibilidade do banco EXTERNO de gestao de coletas
 * (host/porta errados) SOMENTE nesta chamada de teste, via sobrescrita de
 * $_ENV apos o .env real ja ter sido carregado — o arquivo .env real nunca
 * e alterado. Confirma que AtendimentoController::iniciar() trata a falha
 * com HTTP 502 generico, sem vazar host/porta/credencial na resposta.
 *
 * Uso: php _caso_indisponibilidade_banco_externo.php <id_totem> <placa>
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

// So sobrescreve em memoria (este processo), depois de Conexao::obter() ja
// ter aberto a conexao real do totem com os valores corretos — nunca toca
// o arquivo .env.
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = '1';
$_ENV['GESTAO_COLETAS_DB_NAME'] = 'inexistente_para_teste';

$idTotem = (int) ($argv[1] ?? 0);
$placa = $argv[2] ?? '';

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao()));
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$controller = new AtendimentoController($atendimentoRn, $talentRn, new AtendimentoNotaDao($pdo));

$controller->iniciar($idTotem, ['tipo' => 'expedicao', 'placa' => $placa]);
