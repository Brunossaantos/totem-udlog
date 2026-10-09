<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\AceiteLgpdDao;
use App\Rn\LgpdRn;
use App\Controller\LgpdController;

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
$totem = Auth::validarTotem($pdo);

$controller = new LgpdController(new LgpdRn(new AceiteLgpdDao($pdo)));
$idTotem = (int) $totem['id_totem'];

switch ($_GET['acao'] ?? '') {
    case 'aceitar':
        $controller->aceitar($idTotem);
        break;
    default:
        Resposta::erro('Acao invalida', 404);
}
