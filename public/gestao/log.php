<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoLogController;

$ctx = gestaoPagina('Detalhe do registro', 'logs', 'admin', ['metodos' => ['GET']]);
gestaoRenderizar($ctx, (new GestaoLogController($ctx))->detalhe());
