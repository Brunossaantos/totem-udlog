<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use Util\Auth;
use Util\Resposta;
use App\Controller\ImpressaoTesteController;

// Endpoint ISOLADO de diagnostico (demanda impressao-etiqueta-teste) — NUNCA
// reaproveita atendimento.php/AtendimentoRn/TalentClient/OrdemColetaClient.
// Mesmo padrao de autenticacao de toda rota publica: Util\Auth::validarTotem().

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();
Auth::validarTotem($pdo);

$controller = new ImpressaoTesteController();

$acao = $_GET['acao'] ?? '';

switch ($acao) {
    case 'gerar-etiqueta':
        $controller->gerarEtiqueta();
        break;
    case 'configuracao-servico-local':
        $controller->configuracaoServicoLocal();
        break;
    default:
        Resposta::erro('Acao invalida', 404);
}
