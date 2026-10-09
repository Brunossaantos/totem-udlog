<?php

// Lista de ordens de coleta (abas Ativas, Ativas ha mais de 15 dias, Inativas e Baixas pendentes). Perfis usuario e admin, so GET.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoOrdemController;

$ctx = gestaoPagina('Ordens de coleta', 'ordens', 'usuario', ['metodos' => ['GET']]);
gestaoRenderizar($ctx, (new GestaoOrdemController($ctx))->listar());
