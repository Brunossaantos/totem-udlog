<?php

// Minha conta (qualquer perfil): perfil e troca da propria senha. Acessivel
// mesmo com a troca obrigatoria de senha pendente (e a unica tela nesse caso).
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoContaController;

$ctx = gestaoPagina('Minha conta', 'conta', 'usuario', ['trocaPendenteOk' => true]);
gestaoRenderizar($ctx, (new GestaoContaController($ctx))->tratar());
