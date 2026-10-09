<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\RateLimitOcrDao;
use App\Rn\NotaFiscalRn;
use App\Controller\NotaController;

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
$totem = Auth::validarTotem($pdo);

$notaFiscalRn = new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));
$controller = new NotaController($notaFiscalRn, new AtendimentoDao($pdo), new RateLimitOcrDao($pdo));
$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    $entrada = [];
}

switch ($_GET['acao'] ?? '') {
    case 'processar':
        $controller->processar($entrada, (int) $totem['id_totem']);
        break;
    case 'status':
        $controller->algumaIdentificada((int) ($_GET['id_atendimento'] ?? 0), (int) $totem['id_totem']);
        break;
    case 'identificar-cliente':
        $controller->identificarCliente($entrada, (int) $totem['id_totem']);
        break;
    case 'definir-numero':
        $controller->definirNumero($entrada, (int) $totem['id_totem']);
        break;
    case 'listar':
        $controller->listar((int) ($_GET['id_atendimento'] ?? $entrada['id_atendimento'] ?? 0), (int) $totem['id_totem']);
        break;
    case 'excluir':
        $controller->excluir($entrada, (int) $totem['id_totem']);
        break;
    default:
        Resposta::erro('Acao invalida', 404);
}
