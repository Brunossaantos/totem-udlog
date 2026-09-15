<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
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

$pdo = Conexao::obter();
$totem = Auth::validarTotem($pdo);

// App\Dao\OrdemColetaDao so conecta ao banco externo de gestao de coletas
// (Util\ConexaoGestaoColetas — nunca reaproveita $pdo, que e do banco do
// totem) no momento real da consulta, nao aqui na instanciacao.
$ordemColetaClient = new OrdemColetaClient(new OrdemColetaDao());
$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), $ordemColetaClient);

$talentClient = new TalentClient($_ENV['TALENT_API_URL'] ?? '', $_ENV['TALENT_API_KEY'] ?? '');
$talentRn = new TalentRn($talentClient, new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);

// DocumentoRn usado so para revalidar CNH/CRLV ja gravados no momento da
// transicao de etapa (avancar-etapa-expedicao) — nao instancia VioDecodeClient
// aqui (essa dependencia so e necessaria em documento.php?acao=validar-qr).
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo));

$controller = new AtendimentoController(
    $atendimentoRn,
    $talentRn,
    new AtendimentoNotaDao($pdo),
    $documentoRn,
    new TotemDao($pdo),
    new EmpresaDao($pdo),
    $ordemColetaClient,
    new OrdemColetaPendenteBaixaDao($pdo)
);

$acao = $_GET['acao'] ?? '';
$entrada = json_decode(file_get_contents('php://input'), true) ?? [];

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
        // Alias de compatibilidade — hoje delega para avancarEtapaDocumentos(),
        // ja generalizado para expedicao E recebimento.
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
