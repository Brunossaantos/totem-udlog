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

// Bootstrap isolado: mesma protecao aplicada em public/api/nota.php --
// Util\Bootstrap::conectar() cobre .env ausente/malformado, variavel
// obrigatoria de banco ausente/invalida e falha de conexao (ver
// util/Bootstrap.php e util/Conexao.php).
try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
$totem = Auth::validarTotem($pdo);

$documentoRn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo));
$controller = new DocumentoController(new AtendimentoDao($pdo), $documentoRn, $pdo, new RateLimitVioStatusDao($pdo));
$entrada = json_decode(file_get_contents('php://input'), true) ?? [];
$idTotem = (int) $totem['id_totem'];

switch ($_GET['acao'] ?? '') {
    case 'upload':
        $controller->upload($entrada, $idTotem);
        break;
    case 'iniciar-processamento':
        $controller->iniciarProcessamento($entrada, $idTotem);
        break;
    case 'validar-qr':
        // Alias de compatibilidade do ciclo sincrono anterior — mesma logica
        // de iniciar-processamento (ver DocumentoController::validarQr).
        $controller->validarQr($entrada, $idTotem);
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
