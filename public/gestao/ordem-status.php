<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoOrdemController;

$ctx = gestaoPagina('Ordem de coleta', 'ordens', 'usuario', ['metodos' => ['POST']]);
(new GestaoOrdemController($ctx))->alterarStatus();
