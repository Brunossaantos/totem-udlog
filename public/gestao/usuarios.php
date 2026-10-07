<?php

// Lista de usuarios e acoes (ativar, desativar, desbloquear, redefinir senha). So admin.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoUsuarioController;

$ctx = gestaoPagina('Usuários', 'usuarios', 'admin');
gestaoRenderizar($ctx, (new GestaoUsuarioController($ctx))->listar());
