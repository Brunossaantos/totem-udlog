<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Controller\ImpressaoAtendimentoController;

// Endpoint de impressao REAL (demanda talent-doctos-finalizacao-checkin,
// 2026-09-14) — separado e ISOLADO de impressao-teste.php/
// ImpressaoTesteController. Mesmo padrao de autenticacao de toda rota
// publica: Util\Auth::validarTotem(). SO LE resultado ja persistido do
// check-in — NUNCA dispara/redispara chamada ao Talent.

// Bootstrap isolado: mesma protecao aplicada em public/api/nota.php --
// Util\Bootstrap::conectar() cobre .env ausente/malformado, variavel
// obrigatoria de banco ausente/invalida e falha de conexao (ver
// util/Bootstrap.php e util/Conexao.php).
try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 503);
}
$totem = Auth::validarTotem($pdo);

$controller = new ImpressaoAtendimentoController(new AtendimentoDao($pdo));
$entrada = json_decode(file_get_contents('php://input'), true) ?? [];

$acao = $_GET['acao'] ?? '';
$reimpressao = ($_GET['reimpressao'] ?? '') === '1';

switch ($acao) {
    case 'gerar-etiqueta':
        $controller->gerarEtiqueta($entrada, (int) $totem['id_totem'], $reimpressao);
        break;
    case 'configuracao-servico-local':
        $controller->configuracaoServicoLocal();
        break;
    default:
        Resposta::erro('Acao invalida', 404);
}
