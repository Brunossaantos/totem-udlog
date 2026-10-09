<?php

// Ativar/inativar uma ordem de coleta por id (POST + CSRF, PRG). Perfis usuario e admin.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoOrdemController;

$ctx = gestaoPagina('Ordem de coleta', 'ordens', 'usuario', ['metodos' => ['POST']]);
(new GestaoOrdemController($ctx))->alterarStatus();
