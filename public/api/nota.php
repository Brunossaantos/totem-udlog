<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\RateLimitOcrDao;
use App\Rn\NotaFiscalRn;
use App\Controller\NotaController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();
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
