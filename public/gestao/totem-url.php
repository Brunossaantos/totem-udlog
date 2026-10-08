<?php

// URL completa de um totem (destino do PRG depois de criar e de regerar). So admin, so GET.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Views/gestao/_helpers.php';

use App\Controller\GestaoTotemController;

$ctx = gestaoPagina('Totens', 'totens', 'admin', ['metodos' => ['GET']]);
gestaoRenderizar($ctx, (new GestaoTotemController($ctx))->url());
