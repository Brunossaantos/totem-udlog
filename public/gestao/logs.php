<?php

// Lista de logs do sistema (abas API, Recebimento, Expedicao, Cron e Gestao). So admin, so GET.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoLogController;

$ctx = gestaoPagina('Logs', 'logs', 'admin', ['metodos' => ['GET']]);
gestaoRenderizar($ctx, (new GestaoLogController($ctx))->listar());
