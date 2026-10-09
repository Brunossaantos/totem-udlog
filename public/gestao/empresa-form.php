<?php

// Criar/editar empresa (GET mostra, POST salva e redireciona). So admin.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoEmpresaController;

$ctx = gestaoPagina('Empresa', 'empresas', 'admin', ['metodos' => ['GET', 'POST']]);
gestaoRenderizar($ctx, (new GestaoEmpresaController($ctx))->formulario());
