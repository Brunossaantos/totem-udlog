<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoClienteController;

$ctx = gestaoPagina('Clientes', 'clientes', 'admin', ['metodos' => ['POST']]);
gestaoRenderizar($ctx, (new GestaoClienteController($ctx))->acao());
