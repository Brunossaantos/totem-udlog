<?php

// Criar totem (empresa + nome). So admin.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoTotemController;

$ctx = gestaoPagina('Totens', 'totens', 'admin');
gestaoRenderizar($ctx, (new GestaoTotemController($ctx))->formulario());
