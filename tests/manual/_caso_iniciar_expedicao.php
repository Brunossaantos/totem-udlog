<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_consulta_ordem_coleta.php —
 * chama AtendimentoController::iniciar diretamente (sem HTTP real), tipo
 * fixo 'expedicao'. Resposta::sucesso()/erro() chamam exit(), por isso roda
 * isolado (mesmo padrao ja usado por outros _caso_*.php deste diretorio).
 *
 * AJUSTE (demanda tela-inicial-lgpd-totem, 2026-09-24, /02-testes): a partir
 * desta demanda, App\Controller\AtendimentoController::iniciar() passou a
 * EXIGIR um 'token_aceite' valido (aceite LGPD, ver App\Rn\LgpdRn) ANTES de
 * criar qualquer atendimento — efeito colateral ESPERADO do novo contrato
 * obrigatorio, nao um bug de producao. Este subprocesso agora emite um
 * token de aceite genuino (via App\Rn\LgpdRn::emitir(), mesmo caminho real
 * usado por App\Controller\LgpdController::aceitar()) para o MESMO totem
 * autenticado, imediatamente antes de chamar iniciar(), e injeta
 * lgpdRn+pdo no Controller (necessarios para o CAS de consumo + transacao
 * real). Sem essa injecao, iniciar() falharia fechado (500) mesmo com
 * tipo/placa validos — ver comentario de "dependencias opcionais" em
 * AtendimentoController::iniciar().
 *
 * Uso: php _caso_iniciar_expedicao.php <id_totem> <placa>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Dao\OrdemColetaDao;
use App\Dao\AceiteLgpdDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\LgpdRn;
use App\Controller\AtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$placa = $argv[2] ?? '';

$lgpdRn = new LgpdRn(new AceiteLgpdDao($pdo));
$tokenAceite = $lgpdRn->emitir($idTotem)['token_aceite'];

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao()));
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$controller = new AtendimentoController(
    $atendimentoRn,
    $talentRn,
    new AtendimentoNotaDao($pdo),
    null,
    null,
    null,
    null,
    null,
    $lgpdRn,
    $pdo
);

$controller->iniciar($idTotem, ['tipo' => 'expedicao', 'placa' => $placa, 'token_aceite' => $tokenAceite]);
