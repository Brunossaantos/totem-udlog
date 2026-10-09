<?php

/**
 * Subprocesso auxiliar de teste_ordem_coleta_pendente_baixa.php (F4a, cenario 8) —
 * chama AtendimentoController::finalizar() de uma Expedicao com Talent FALSO
 * (sucesso, sem rede) e um OrdemColetaClient DUBLE que FALHA se for chamado
 * (imprime EXTERNO_CHAMADO e lanca): prova que, com cliente_cnpj vazio/so sem
 * digitos, o banco externo NAO e tocado. Roda sob o prepend QA.
 *
 * Uso: php _caso_finalizar_baixa_sem_cnpj.php <id_totem> <id_atendimento> <arquivo_log>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\VioCacheDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Dao\OrdemColetaDao;
use App\Dao\OrdemColetaPendenteBaixaDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\DocumentoRn;
use App\Controller\AtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
$_ENV['TALENT_CHECKIN_ATIVO'] = 'true'; // o prepend QA pode ter forcado 'false'

ini_set('log_errors', '1');
ini_set('error_log', (string) ($argv[3] ?? ''));

$pdo = Conexao::obter();
$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);

class TalentClientSucesso extends TalentClient
{
    public function __construct()
    {
        parent::__construct('', '');
    }

    public function checkin(array $payload): array
    {
        echo "TALENT_CHAMADO\n";

        return ['senha' => 'FAL123', 'protocolo' => 'PROTO-FALSO'];
    }
}

/** Qualquer chamada ao banco externo e um defeito: avisa e lanca. */
class OrdemColetaClientQueFalha extends OrdemColetaClient
{
    public function __construct()
    {
        parent::__construct(new OrdemColetaDao());
    }

    public function marcarConcluida(string $cnpj, string $numero): bool
    {
        echo "EXTERNO_CHAMADO marcarConcluida\n";
        throw new \RuntimeException('banco externo nao deveria ser chamado');
    }

    public function statusAtual(string $cnpj, string $numero): ?string
    {
        echo "EXTERNO_CHAMADO statusAtual\n";
        throw new \RuntimeException('banco externo nao deveria ser chamado');
    }
}

$atendimentoDao = new AtendimentoDao($pdo);
$controller = new AtendimentoController(
    new AtendimentoRn($atendimentoDao, new OrdemColetaClient(new OrdemColetaDao())),
    new TalentRn(new TalentClientSucesso(), $atendimentoDao, $_ENV['STORAGE_PATH']),
    new AtendimentoNotaDao($pdo),
    new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao),
    new TotemDao($pdo),
    new EmpresaDao($pdo),
    new OrdemColetaClientQueFalha(),
    new OrdemColetaPendenteBaixaDao($pdo)
);

ob_start(); // evita 'headers already sent' ao imprimir marcadores antes de Resposta::json
register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . (http_response_code() ?: 200) . "\n";
});

$controller->finalizar(['id_atendimento' => $idAtendimento], $idTotem);
