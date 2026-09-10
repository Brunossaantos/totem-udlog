<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_concorrencia_real_iniciar_processamento.php
 * — chama DocumentoController::iniciarProcessamento diretamente (sem HTTP
 * real), com QR garbage (chamada REAL contra o Trial vivo do Serpro, so
 * para exercitar o caminho completo de concorrencia end-to-end).
 *
 * Uso: php _caso_iniciar_processamento.php <id_totem> <id_atendimento> <tipo>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = $argv[3] ?? 'cnh';

$atendimentoDao = new AtendimentoDao($pdo);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao);
$controller = new DocumentoController($atendimentoDao, $documentoRn, $pdo);

$bytesGarbage = random_bytes(40);

$controller->iniciarProcessamento([
    'id_atendimento' => $idAtendimento,
    'tipo' => $tipo,
    'qr_bytes_base64' => base64_encode($bytesGarbage),
], $idTotem);
