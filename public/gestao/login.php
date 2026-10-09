<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoLoginController;

$ctx = gestaoPaginaPublica('Entrar');
gestaoRenderizar($ctx, (new GestaoLoginController($ctx))->login());
