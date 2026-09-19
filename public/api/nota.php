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

// Bootstrap isolado: Util\Bootstrap::conectar() cobre .env ausente/malformado,
// variavel obrigatoria de banco ausente/invalida e falha de conexao -- capturado
// SOMENTE aqui, antes de Auth::validarTotem()/controller/OCR, para nunca vazar
// fatal error cru (stack trace + caminho do servidor) ao cliente HTTP.
try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
$totem = Auth::validarTotem($pdo);

// Identificacao automatica de cliente via OCR (NotaFiscalRn::identificarCliente)
// consulta tb_cliente localmente via ClienteDao desde 2026-09-08 — a antiga
// API externa de clientes (App\Rn\ClienteApiClient) nao e mais usada aqui
// (classe mantida como codigo morto documentado, ver o proprio arquivo).
$notaFiscalRn = new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));
$controller = new NotaController($notaFiscalRn, new AtendimentoDao($pdo), new RateLimitOcrDao($pdo));
$entrada = json_decode(file_get_contents('php://input'), true) ?? [];

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
    default:
        Resposta::erro('Acao invalida', 404);
}
