<?php

/**
 * Subprocesso auxiliar de teste_sem_fila_envio.php — chama
 * AtendimentoController::finalizar() com um TalentClient FALSO (sem rede
 * alguma) que lanca TalentClientException(<categoria>) com mensagem_api
 * opcional. Roda sob o prepend QA (banco qa_qr_exclusivo_*). error_log vai
 * para o arquivo informado. Imprime CLIENT_CHAMADO a cada checkin() do
 * cliente falso (prova de que o Talent nao foi chamado quando nao deve).
 *
 * Uso: php _caso_finalizar_talent_falso.php <id_totem> <id_atendimento> <categoria|sucesso> <arquivo_log> [mensagem_api]
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\VioCacheDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Rn\AtendimentoRn;
use App\Dao\OrdemColetaDao;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\TalentClientException;
use App\Rn\DocumentoRn;
use App\Controller\AtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
$_ENV['TALENT_CHECKIN_ATIVO'] = 'true'; // o prepend QA pode ter forcado 'false'

ini_set('log_errors', '1');
ini_set('error_log', (string) ($argv[4] ?? ''));

$pdo = Conexao::obter();
$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$categoria = (string) ($argv[3] ?? '');
$mensagemApi = isset($argv[5]) && $argv[5] !== '' ? (string) $argv[5] : null;

class TalentClientFalso extends TalentClient
{
    public function __construct(private string $categoria, private ?string $mensagemApi)
    {
        parent::__construct('', '');
    }

    public function checkin(array $payload): array
    {
        echo "CLIENT_CHAMADO\n";
        if ($this->categoria === 'sucesso') {
            return ['senha' => 'FAL123', 'protocolo' => 'PROTO-FALSO'];
        }
        throw (new TalentClientException($this->categoria))->comMensagemApi($this->mensagemApi);
    }
}

$talentRn = new TalentRn(new TalentClientFalso($categoria, $mensagemApi), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$controller = new AtendimentoController(
    new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao())),
    $talentRn,
    new AtendimentoNotaDao($pdo),
    new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo)),
    new TotemDao($pdo),
    new EmpresaDao($pdo)
);

ob_start(); // evita 'headers already sent' ao imprimir CLIENT_CHAMADO antes de Resposta::json
register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . (http_response_code() ?: 200) . "\n";
});

$controller->finalizar(['id_atendimento' => $idAtendimento], $idTotem);
