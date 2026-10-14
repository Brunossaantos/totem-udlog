<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\ClienteDao;

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
Auth::validarTotem($pdo);

$clienteDao = new ClienteDao($pdo);

if (($_GET['acao'] ?? '') === 'buscar') {
    $termo = $_GET['termo'] ?? '';
    if (!is_string($termo) || strlen($termo) < 3) {
        Resposta::sucesso(['clientes' => []]);
    }
    $cliente = $clienteDao->buscarUnicoPorTermo($termo);
    Resposta::sucesso(['clientes' => $cliente === null ? [] : [['nome' => $cliente['nome'], 'cnpj' => $cliente['cnpj']]]]);
} else {
    Resposta::erro('Acao invalida', 404);
}
