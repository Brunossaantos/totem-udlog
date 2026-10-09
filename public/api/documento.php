<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Dao\RateLimitVioStatusDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
$totem = Auth::validarTotem($pdo);

try {
    $documentoRn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo));
    $controller = new DocumentoController(new AtendimentoDao($pdo), $documentoRn, $pdo, new RateLimitVioStatusDao($pdo));
    $corpo = file_get_contents('php://input');
    if ($corpo === false || strlen($corpo) > 8 * 1024 * 1024) {
        Resposta::erro('Dados incompletos');
    }
    $entrada = json_decode($corpo, true);
    if (!is_array($entrada)) {
        Resposta::erro('Dados incompletos');
    }
    $idTotem = (int) $totem['id_totem'];

    switch ($_GET['acao'] ?? '') {
        case 'iniciar-processamento':
            $controller->iniciarProcessamento($entrada, $idTotem);
            break;
        case 'validar-qr':
            Resposta::erro('Acao invalida', 404);
            break;
        case 'status-processamento':
            $controller->statusProcessamento($entrada, $idTotem);
            break;
        case 'preencher-manual':
            $controller->preencherManual($entrada, $idTotem);
            break;
        default:
            Resposta::erro('Acao invalida', 404);
    }
} catch (\Throwable $e) {
    error_log('public/api/documento.php: falha nao prevista [' . get_class($e) . ']');
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 500);
}
