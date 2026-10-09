<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Controller\ImpressaoAtendimentoController;


try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
$totem = Auth::validarTotem($pdo);

$controller = new ImpressaoAtendimentoController(new AtendimentoDao($pdo));
$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    $entrada = [];
}

$acao = $_GET['acao'] ?? '';
$reimpressao = ImpressaoAtendimentoController::flagReimpressao($_GET, $entrada);

switch ($acao) {
    case 'gerar-etiqueta':
        $controller->gerarEtiqueta($entrada, (int) $totem['id_totem'], $reimpressao);
        break;
    case 'configuracao-servico-local':
        $controller->configuracaoServicoLocal();
        break;
    default:
        Resposta::erro('Acao invalida', 404);
}
