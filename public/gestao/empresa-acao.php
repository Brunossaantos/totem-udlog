<?php

// Ativar, inativar e excluir empresa (POST + CSRF, PRG; confirmacao em dois passos). So admin.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoEmpresaController;

$ctx = gestaoPagina('Empresas', 'empresas', 'admin', ['metodos' => ['POST']]);
gestaoRenderizar($ctx, (new GestaoEmpresaController($ctx))->acao());
