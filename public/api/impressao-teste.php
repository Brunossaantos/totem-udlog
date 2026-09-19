<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Controller\ImpressaoTesteController;

// Endpoint ISOLADO de diagnostico (demanda impressao-etiqueta-teste) — NUNCA
// reaproveita atendimento.php/AtendimentoRn/TalentClient/OrdemColetaClient.
// Mesmo padrao de autenticacao de toda rota publica: Util\Auth::validarTotem().

// Bootstrap isolado: mesma protecao aplicada em public/api/nota.php --
// Util\Bootstrap::conectar() cobre .env ausente/malformado, variavel
// obrigatoria de banco ausente/invalida e falha de conexao (ver
// util/Bootstrap.php e util/Conexao.php).
try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
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
