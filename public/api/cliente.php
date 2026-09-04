<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use Util\Auth;
use Util\Resposta;
use App\Dao\ClienteDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();
Auth::validarTotem($pdo);

$clienteDao = new ClienteDao($pdo);

if (($_GET['acao'] ?? '') === 'buscar') {
    $termo = $_GET['termo'] ?? '';
    if (strlen($termo) < 3) {
        Resposta::sucesso(['clientes' => []]);
    }
    Resposta::sucesso(['clientes' => $clienteDao->buscarPorTermo($termo)]);
} else {
    Resposta::erro('Acao invalida', 404);
}
