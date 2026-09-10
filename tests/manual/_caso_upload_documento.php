<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_fluxo_recebimento_documentos.php
 * — chama DocumentoController::upload diretamente (sem HTTP real).
 *
 * Uso: php _caso_upload_documento.php <id_totem> <id_atendimento> <tipo> <imagem_base64>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Controller\DocumentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = $argv[3] ?? 'crlv';
$imagem = $argv[4] ?? '';

$atendimentoDao = new AtendimentoDao($pdo);
$controller = new DocumentoController($atendimentoDao);

$entrada = ['id_atendimento' => $idAtendimento, 'tipo' => $tipo];
if ($tipo === 'cnh') {
    $entrada['imagem_frente'] = $imagem;
    $entrada['imagem_verso'] = $imagem;
} else {
    $entrada['imagem'] = $imagem;
}

$controller->upload($entrada, $idTotem);
