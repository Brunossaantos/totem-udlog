<?php

// Detalhe de uma ordem de coleta (?id=). Perfis usuario e admin, so GET.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoOrdemController;

$ctx = gestaoPagina('Ordem de coleta', 'ordens', 'usuario', ['metodos' => ['GET']]);
gestaoRenderizar($ctx, (new GestaoOrdemController($ctx))->detalhe());
