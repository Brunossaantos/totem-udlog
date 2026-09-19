<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\ClienteDao;

// Bootstrap isolado: mesma protecao aplicada em public/api/nota.php --
// Util\Bootstrap::conectar() cobre .env ausente/malformado, variavel
// obrigatoria de banco ausente/invalida e falha de conexao (ver
// util/Bootstrap.php e util/Conexao.php).
try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
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
