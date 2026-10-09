<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoEmpresaController;

$ctx = gestaoPagina('Empresas', 'empresas', 'admin', ['metodos' => ['GET']]);
gestaoRenderizar($ctx, (new GestaoEmpresaController($ctx))->listar());
