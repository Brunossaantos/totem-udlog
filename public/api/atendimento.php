<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\VioCacheDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Dao\OrdemColetaDao;
use App\Dao\OrdemColetaPendenteBaixaDao;
use App\Dao\AceiteLgpdDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\AnexoOrdemColetaLeitor;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\DocumentoRn;
use App\Rn\LgpdRn;
use App\Rn\NotaFiscalRn;
use App\Controller\AtendimentoController;

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
$totem = Auth::validarTotem($pdo);

$ordemColetaClient = new OrdemColetaClient(new OrdemColetaDao());
$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), $ordemColetaClient);

$talentClient = new TalentClient($_ENV['TALENT_API_URL'] ?? '', $_ENV['TALENT_API_KEY'] ?? '');
$talentRn = new TalentRn(
    $talentClient,
    new AtendimentoDao($pdo),
    $_ENV['STORAGE_PATH'],
    AnexoOrdemColetaLeitor::padrao()
);

$documentoRn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo));

$lgpdRn = new LgpdRn(new AceiteLgpdDao($pdo));

$controller = new AtendimentoController(
    $atendimentoRn,
    $talentRn,
    new AtendimentoNotaDao($pdo),
    $documentoRn,
    new TotemDao($pdo),
    new EmpresaDao($pdo),
    $ordemColetaClient,
    new OrdemColetaPendenteBaixaDao($pdo),
    $lgpdRn,
    $pdo,
    new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo)),
    new ClienteDao($pdo)
);

$acao = $_GET['acao'] ?? '';
$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    $entrada = [];
}

switch ($acao) {
    case 'iniciar':
        $controller->iniciar((int) $totem['id_totem'], $entrada);
        break;
    case 'selecionar-ordem':
        $controller->selecionarOrdem($entrada, (int) $totem['id_totem']);
        break;
    case 'salvar-etapa':
        $controller->salvarEtapa($entrada, (int) $totem['id_totem']);
        break;
    case 'bloquear-excesso-notas':
        $controller->bloquearPorExcessoDeNotas($entrada, (int) $totem['id_totem']);
        break;
    case 'concluir-digitalizacao':
        $controller->concluirDigitalizacao($entrada, (int) $totem['id_totem']);
        break;
    case 'avancar-etapa-expedicao':
        $controller->avancarEtapaExpedicao($entrada, (int) $totem['id_totem']);
        break;
    case 'avancar-etapa-documentos':
        $controller->avancarEtapaDocumentos($entrada, (int) $totem['id_totem']);
        break;
    case 'finalizar':
        $controller->finalizar($entrada, (int) $totem['id_totem']);
        break;
    case 'cancelar':
        $controller->cancelar($entrada, (int) $totem['id_totem']);
        break;
    default:
        Resposta::erro('Acao invalida', 404);
}
