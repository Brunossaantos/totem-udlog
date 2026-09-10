<?php

/**
 * Subprocesso auxiliar (sem rede, sem HTTP) — chama SOMENTE
 * App\Dao\AtendimentoDao::iniciarEnvioTalent (a transicao CAS para
 * ENVIANDO), usado pelo teste de concorrencia REAL (2 processos
 * disparados via proc_open) de tests/manual/teste_talent_idempotencia.php.
 *
 * Uso: php _caso_iniciar_envio_talent.php <id_atendimento> <tentativa_id>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idAtendimento = (int) ($argv[1] ?? 0);
$tentativaId = (string) ($argv[2] ?? '');

$dao = new AtendimentoDao($pdo);
$adquiriu = $dao->iniciarEnvioTalent($idAtendimento, $tentativaId);

echo $adquiriu ? 'ADQUIRIU' : 'NEGADO';
