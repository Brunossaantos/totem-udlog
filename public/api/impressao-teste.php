<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Controller\ImpressaoTesteController;


try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
Auth::validarTotem($pdo);

$controller = new ImpressaoTesteController();

$acao = $_GET['acao'] ?? '';

switch ($acao) {
    case 'gerar-etiqueta':
        $controller->gerarEtiqueta();
        break;
    case 'configuracao-servico-local':
        $controller->configuracaoServicoLocal();
        break;
    case 'configuracao-etiqueta':
        $controller->configuracaoEtiqueta();
        break;
    case 'etiqueta-pronta':
        $controller->etiquetaPronta();
        break;
    default:
        Resposta::erro('Acao invalida', 404);
}
