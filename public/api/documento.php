<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use Util\Auth;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Dao\VioApiBrCacheDao;
use App\Dao\RateLimitVioStatusDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

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

// Fronteira global COMPLEMENTAR (nunca substituta dos catches especificos ja
// existentes em App\Controller\DocumentoController) — demanda
// remocao-legado-serpro-e-hardening-documentos, 2026-09-28: rede de
// seguranca final contra qualquer excecao NAO PREVISTA (ex.: PDOException de
// uma chamada ao AtendimentoDao sem try/catch dedicado neste fluxo) que
// escape de todos os pontos ja tratados no Controller. Toda chamada real de
// Controller termina em Util\Resposta::sucesso()/erro() (ambas fazem
// exit()) — este catch so dispara para uma falha genuinamente imprevista, e
// nunca expõe getMessage()/trace/SQL/payload/credencial, mesmo padrao
// sanitizado ja usado em Util\Bootstrap/DocumentoController::logFalhaTecnica().
try {
    $documentoRn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo), new VioApiBrCacheDao($pdo));
    $controller = new DocumentoController(new AtendimentoDao($pdo), $documentoRn, $pdo, new RateLimitVioStatusDao($pdo));
    $entrada = json_decode(file_get_contents('php://input'), true) ?? [];
    $idTotem = (int) $totem['id_totem'];

    switch ($_GET['acao'] ?? '') {
        case 'definir-modo-cnh':
            $controller->definirModoCnh($entrada, $idTotem);
            break;
        case 'upload':
            $controller->upload($entrada, $idTotem);
            break;
        case 'iniciar-processamento':
            $controller->iniciarProcessamento($entrada, $idTotem);
            break;
        case 'validar-qr':
            // Alias de compatibilidade do ciclo sincrono anterior — mesma logica
            // de iniciar-processamento (ver DocumentoController::validarQr).
            $controller->validarQr($entrada, $idTotem);
            break;
        case 'status-processamento':
            $controller->statusProcessamento($entrada, $idTotem);
            break;
        case 'preencher-manual':
            $controller->preencherManual($entrada, $idTotem);
            break;
        default:
            Resposta::erro('Acao invalida', 404);
    }
} catch (\Throwable $e) {
    error_log('public/api/documento.php: falha nao prevista [' . get_class($e) . ']');
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 500);
}
