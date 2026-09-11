<?php

/**
 * Subprocesso auxiliar — chama AtendimentoController::finalizar() diretamente
 * (sem HTTP real). TalentClient e instanciado com URL/token VAZIOS
 * (mesmo padrao ja usado por outros _caso_*.php deste projeto) — se a
 * execucao chegar a ponto de tentar a chamada HTTP real, o curl falha
 * localmente (URL relativa invalida, erro_conexao) SEM jamais abrir uma
 * conexao de rede real para api.talentcs.com.br. Usado pelos testes de IDOR
 * (que sempre barram ANTES desse ponto) e, quando necessario, tambem
 * confirma que a falha de rede local vira ERRO_REPROCESSAVEL/HTTP 202 (sem
 * expor nenhum dado sensivel).
 *
 * Uso: php _caso_finalizar.php <id_totem> <id_atendimento>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\VioCacheDao;
use App\Dao\FilaEnvioDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Rn\AtendimentoRn;
use App\Dao\OrdemColetaDao;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\DocumentoRn;
use App\Controller\AtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao()));
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo));

$controller = new AtendimentoController(
    $atendimentoRn,
    $talentRn,
    new AtendimentoNotaDao($pdo),
    $documentoRn,
    new TotemDao($pdo),
    new EmpresaDao($pdo)
);

$controller->finalizar(['id_atendimento' => $idAtendimento], $idTotem);
