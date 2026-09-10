<?php

/**
 * Chamada real (integracao) a DocumentoController::validarQr contra o
 * ambiente Trial da VIO Decode, com bytes de QR ALEATORIOS (garbage) — so
 * para confirmar que a integracao ponta a ponta (HMAC -> cache miss ->
 * VioDecodeClient real -> resposta estruturada) nao lanca excecao nao
 * tratada e responde algo coerente (esperado: VD001, "QR nao e compativel
 * com padrao VIO"). NAO usa nenhum QR real de CNH/CRLV, nenhum dado
 * pessoal. Uso: php _caso_validar_qr.php <id_totem> <id_atendimento> <tipo>
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

$controller->validarQr([
    'id_atendimento' => $idAtendimento,
    'tipo' => $tipo,
    'qr_bytes_base64' => base64_encode($bytesGarbage),
], $idTotem);
