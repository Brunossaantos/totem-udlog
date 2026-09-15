<?php

/**
 * Subprocesso auxiliar (demanda talent-doctos-finalizacao-checkin,
 * 2026-09-14) — chama NotaController::processar diretamente (sem HTTP
 * real), simulando a digitalizacao de UMA nota fiscal (mock de upload, JPEG
 * minimo valido gerado em memoria, sem chave de acesso). Resposta::sucesso()/
 * erro() chamam exit(), por isso roda isolado (mesmo padrao ja usado pelos
 * demais _caso_*.php deste projeto).
 *
 * Uso: php _caso_nota_processar.php <id_totem> <id_atendimento> <ordem>
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

$notaFiscalRn = new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));
$controller = new NotaController($notaFiscalRn, new AtendimentoDao($pdo));

// JPEG minimo porem estruturalmente valido (1x1 branco) — mesmo usado por
// outros testes de upload do projeto (ex: teste_fluxo_recebimento_documentos.php)
$imagemBase64 = 'data:image/jpeg;base64,' . base64_encode(
    base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=')
);

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

$controller->processar([
    'id_atendimento' => $idAtendimento,
    'ordem' => $ordem,
    'imagem' => $imagemBase64,
], $idTotem);
