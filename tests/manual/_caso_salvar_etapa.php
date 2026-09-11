<?php

/**
 * Subprocesso auxiliar — chama AtendimentoController::salvarEtapa
 * diretamente (sem HTTP real). Resposta::sucesso()/erro() chamam exit(), por
 * isso roda isolado (mesmo padrao de _caso_avancar_etapa_generico.php).
 *
 * Uso: php _caso_salvar_etapa.php <id_totem> <id_atendimento> <etapa> <dados_json_base64>
 *
 * <dados_json_base64> vem em base64 (nao JSON cru) porque o
 * escapeshellarg() do PHP no Windows remove aspas duplas do argumento antes
 * de repassar ao cmd.exe, corrompendo silenciosamente qualquer JSON literal
 * passado via linha de comando (achado ao escrever
 * teste_idor_salvar_etapa_manual.php em 2026-09-09 — os valores chegavam
 * como string vazia ao Controller sem erro nenhum). Base64 nao tem esse
 * problema por nao conter aspas.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Rn\AtendimentoRn;
use App\Dao\OrdemColetaDao;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Controller\AtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$etapa = $argv[3] ?? '';
$dados = json_decode(base64_decode($argv[4] ?? '', true) ?: '{}', true) ?? [];

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao()));
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$controller = new AtendimentoController($atendimentoRn, $talentRn, new AtendimentoNotaDao($pdo));

$controller->salvarEtapa([
    'id_atendimento' => $idAtendimento,
    'etapa' => $etapa,
    'dados' => $dados,
], $idTotem);
