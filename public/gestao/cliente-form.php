<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoClienteController;

$ctx = gestaoPagina('Cliente', 'clientes', 'admin', ['metodos' => ['GET', 'POST']]);
gestaoRenderizar($ctx, (new GestaoClienteController($ctx))->formulario());
