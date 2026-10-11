<?php

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
$_ENV['TALENT_CHECKIN_ATIVO'] = 'true';

ini_set('log_errors', '1');
ini_set('error_log', (string) ($argv[5] ?? ''));

$pdo = Conexao::obter();
$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$baseUrl = (string) ($argv[3] ?? '');
$cenario = (string) ($argv[4] ?? '');

class OrdemColetaClientEspiao extends OrdemColetaClient
{
    public function __construct()
    {
        parent::__construct(new OrdemColetaDao());
    }

    public function marcarConcluida(string $cnpj, string $numero): bool
    {
        echo "OC_BAIXA_CHAMADA\n";
        return true;
    }

    public function statusAtual(string $cnpj, string $numero): ?string
    {
        echo "OC_STATUS_CHAMADO\n";
        return 'ATIVA';
    }
}

$sabotagem = (string) ($argv[6] ?? '');

class TalentClientConcorrente extends TalentClient
{
    public function __construct(string $baseUrl, string $token, private PDO $pdo, private int $idAtendimento, private string $sabotagem)
    {
        parent::__construct($baseUrl, $token);
    }

    public function checkin(array $payload): array
    {
        try {
            return parent::checkin($payload);
        } finally {
            if ($this->sabotagem === 'indeterminado_por_terceiro') {
                $this->pdo->prepare("UPDATE tb_atendimento SET talent_checkin_status = 'ENVIO_INDETERMINADO' WHERE id_atendimento = :id")->execute(['id' => $this->idAtendimento]);
            } elseif ($this->sabotagem === 'bloqueado_e_indeterminado') {
                $this->pdo->prepare("UPDATE tb_atendimento SET status = 'bloqueado', talent_checkin_status = 'ENVIO_INDETERMINADO' WHERE id_atendimento = :id")->execute(['id' => $this->idAtendimento]);
            } elseif ($this->sabotagem === 'outra_tentativa') {
                $this->pdo->prepare("UPDATE tb_atendimento SET talent_tentativa_id = 'tentativa_de_terceiro' WHERE id_atendimento = :id")->execute(['id' => $this->idAtendimento]);
            }
        }
    }
}

$atendimentoDao = new AtendimentoDao($pdo);
$clienteTalent = $sabotagem === ''
    ? new TalentClient($baseUrl, 'cenario_' . $cenario)
    : new TalentClientConcorrente($baseUrl, 'cenario_' . $cenario, $pdo, $idAtendimento, $sabotagem);
$talentRn = new TalentRn($clienteTalent, $atendimentoDao, $_ENV['STORAGE_PATH']);
$ocEspiao = new OrdemColetaClientEspiao();
$controller = new AtendimentoController(
    new AtendimentoRn($atendimentoDao, $ocEspiao),
    $talentRn,
    new AtendimentoNotaDao($pdo),
    new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao),
    new TotemDao($pdo),
    new EmpresaDao($pdo),
    $ocEspiao,
    new OrdemColetaPendenteBaixaDao($pdo)
);

ob_start();
register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . (http_response_code() ?: 200) . "\n";
});

$controller->finalizar(['id_atendimento' => $idAtendimento], $idTotem);
