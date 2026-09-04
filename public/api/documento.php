<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Controller\DocumentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();
Auth::validarTotem($pdo);

$controller = new DocumentoController(new AtendimentoDao($pdo));
$entrada = json_decode(file_get_contents('php://input'), true) ?? [];

if (($_GET['acao'] ?? '') === 'upload') {
    $controller->upload($entrada);
} else {
    Resposta::erro('Acao invalida', 404);
}
