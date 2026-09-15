<?php

/**
 * Subprocesso auxiliar (demanda talent-doctos-finalizacao-checkin,
 * 2026-09-14) — chama NotaController::definirNumero diretamente (sem HTTP
 * real). Usado tanto pelo roteiro sequencial (E2E) quanto pelo teste de
 * CONCORRENCIA REAL (2 processos disputando o mesmo numero_nota dentro do
 * mesmo atendimento, ver teste_concorrencia_numero_nota_duplicado.php).
 *
 * Uso: php _caso_nota_definir_numero.php <id_totem> <id_atendimento> <ordem> <numero> <origem=OCR|MANUAL>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Rn\NotaFiscalRn;
use App\Controller\NotaController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$ordem = (int) ($argv[3] ?? 0);
$numero = (string) ($argv[4] ?? '');
$origem = (string) ($argv[5] ?? 'MANUAL');

$notaFiscalRn = new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));
$controller = new NotaController($notaFiscalRn, new AtendimentoDao($pdo));

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

$controller->definirNumero([
    'id_atendimento' => $idAtendimento,
    'ordem' => $ordem,
    'numero' => $numero,
    'origem' => $origem,
], $idTotem);
