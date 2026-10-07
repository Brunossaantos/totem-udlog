<?php

// Porta de entrada da Gestao Totem: manda para o login ou para a pagina inicial do perfil.
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Controller\GestaoContexto;
use Util\GestaoHttp;

$ctx = GestaoContexto::iniciar('Início', '', 'usuario', ['trocaPendenteOk' => true, 'metodos' => ['GET']]);
GestaoHttp::redirecionar(
    $ctx->sessao['deve_trocar_senha'] ? GestaoContexto::CAMINHO_CONTA : GestaoContexto::paginaInicial((string) $ctx->sessao['perfil'])
);
