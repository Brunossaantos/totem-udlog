<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_consulta_ordem_coleta.php —
 * chama AtendimentoController::selecionarOrdem diretamente (sem HTTP real),
 * simulando o payload que o front-end envia (objeto 'ordem' inteiro, que
 * pode estar FORJADO em cenarios de teste de IDOR). Resposta::sucesso()/
 * erro() chamam exit(), por isso roda isolado.
 *
 * Uso: php _caso_selecionar_ordem.php <id_totem> <id_atendimento> <ordem_json_base64>
 * (base64 pelo mesmo motivo documentado em _caso_salvar_etapa.php — evita
 * corrupcao de aspas duplas pelo escapeshellarg()/cmd.exe no Windows)
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
$idAtendimento = (int) ($argv[2] ?? 0);
$ordem = json_decode(base64_decode($argv[3] ?? '', true) ?: '{}', true) ?? [];

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao()));
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$controller = new AtendimentoController($atendimentoRn, $talentRn, new AtendimentoNotaDao($pdo));

$controller->selecionarOrdem(['id_atendimento' => $idAtendimento, 'ordem' => $ordem], $idTotem);
