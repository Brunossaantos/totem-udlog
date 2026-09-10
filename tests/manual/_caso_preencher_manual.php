<?php

/**
 * Subprocesso auxiliar — chama DocumentoController::preencherManual
 * diretamente (sem HTTP real).
 *
 * Uso CNH:  php _caso_preencher_manual.php <id_totem> <id_atendimento> cnh <nome> <cpf> <validade>
 * Uso CRLV: php _caso_preencher_manual.php <id_totem> <id_atendimento> crlv <placa> <exercicio> [uf] [rntc] [tipo_veiculo]
 *
 * uf e opcional no argv por retrocompatibilidade com chamadas antigas do
 * script, mas se omitido usa 'SP' como padrao valido — DocumentoController::
 * preencherManual exige uf nao-vazia desde a demanda expedicao-vio-cnh-crlv
 * (veiculo.uf obrigatorio para o Talent). rntc/tipo_veiculo idem, opcionais
 * no argv (default '12345678'/'CAMINHAO') desde a extensao 2026-09-10
 * (veiculo.rntc/veiculo.tipo obrigatorios pelo Talent).
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
$tipo = $argv[3] ?? 'crlv';

$atendimentoDao = new AtendimentoDao($pdo);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao);
$controller = new DocumentoController($atendimentoDao, $documentoRn, $pdo);

if ($tipo === 'cnh') {
    $controller->preencherManual([
        'id_atendimento' => $idAtendimento,
        'tipo' => 'cnh',
        'nome' => $argv[4] ?? '',
        'cpf' => $argv[5] ?? '',
        'validade' => $argv[6] ?? '',
    ], $idTotem);
} else {
    $controller->preencherManual([
        'id_atendimento' => $idAtendimento,
        'tipo' => 'crlv',
        'placa' => $argv[4] ?? '',
        'exercicio' => $argv[5] ?? '',
        'uf' => $argv[6] ?? 'SP',
        'rntc' => $argv[7] ?? '12345678',
        'tipo_veiculo' => $argv[8] ?? 'CAMINHAO',
    ], $idTotem);
}
