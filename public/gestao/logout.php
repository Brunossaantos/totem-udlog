<?php

// Sair: SO POST com token CSRF (um GET nunca encerra a sessao).
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoLoginController;

$ctx = gestaoPagina('Sair', '', 'usuario', [
    'metodos' => ['POST'],
    'trocaPendenteOk' => true,
    'semSessaoRedireciona' => true,
]);
(new GestaoLoginController($ctx))->logout();
