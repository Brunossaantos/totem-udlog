<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Controller\AtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();
$totem = Auth::validarTotem($pdo);

$ordemColetaClient = new OrdemColetaClient($_ENV['ORDEM_COLETA_API_URL'] ?? '', $_ENV['ORDEM_COLETA_API_KEY'] ?? '');
$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), $ordemColetaClient);

$talentClient = new TalentClient($_ENV['TALENT_API_URL'] ?? '', $_ENV['TALENT_API_KEY'] ?? '');
$talentRn = new TalentRn($talentClient, new FilaEnvioDao($pdo), $_ENV['STORAGE_PATH']);

$controller = new AtendimentoController($atendimentoRn, $talentRn, new AtendimentoNotaDao($pdo));

$acao = $_GET['acao'] ?? '';
$entrada = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($acao) {
    case 'iniciar':
        $controller->iniciar((int) $totem['id_totem'], $entrada);
        break;
    case 'selecionar-ordem':
        $controller->selecionarOrdem($entrada);
        break;
    case 'salvar-etapa':
        $controller->salvarEtapa($entrada, (int) $totem['id_totem']);
        break;
    case 'bloquear-excesso-notas':
        $controller->bloquearPorExcessoDeNotas($entrada, (int) $totem['id_totem']);
        break;
    case 'concluir-digitalizacao':
        $controller->concluirDigitalizacao($entrada, (int) $totem['id_totem']);
        break;
    case 'finalizar':
        $controller->finalizar($entrada);
        break;
    case 'cancelar':
        $controller->cancelar($entrada, (int) $totem['id_totem']);
        break;
    default:
        Resposta::erro('Acao invalida', 404);
}
