<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\AuthServidor;
use Util\LogSistema;
use Util\OrdemColetaArquivoStorage;
use Util\Resposta;
use App\Controller\OrdemColetaAnexoController;
use App\Dao\OrdemColetaArquivoDao;
use App\Rn\OrdemColetaArquivoRn;

try {
    Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();

    $chave = $_ENV['ORDEM_COLETA_ANEXO_API_KEY'] ?? getenv('ORDEM_COLETA_ANEXO_API_KEY');
    $chave = is_string($chave) ? $chave : '';
    $permitirHttp = ($_ENV['ORDEM_COLETA_ANEXO_PERMITIR_HTTP'] ?? getenv('ORDEM_COLETA_ANEXO_PERMITIR_HTTP')) === 'true';
    $limite = OrdemColetaArquivoRn::limiteDoAmbiente(
        isset($_ENV['ORDEM_COLETA_ANEXO_MAX_BYTES']) ? (string) $_ENV['ORDEM_COLETA_ANEXO_MAX_BYTES'] : null
    );

    $storagePath = rtrim((string) ($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: ''), '/\\');
    $dirRateLimit = $storagePath !== '' ? $storagePath . DIRECTORY_SEPARATOR . 'ordens_coleta_ratelimit' : null;

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($authorization === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valor) {
            if (strcasecmp((string) $nome, 'Authorization') === 0) {
                $authorization = (string) $valor;
                break;
            }
        }
    }

    $controller = new OrdemColetaAnexoController(
        new AuthServidor($chave, $permitirHttp, $dirRateLimit),
        new OrdemColetaArquivoRn(new OrdemColetaArquivoDao(), new OrdemColetaArquivoStorage(), $limite),
        OrdemColetaArquivoRn::tetoCorpo($limite)
    );
    $controller->processar($_SERVER, is_string($authorization) ? $authorization : null, static fn () => fopen('php://input', 'rb'));
} catch (\Throwable $e) {
    error_log('ordem-coleta-anexo: falha_inesperada ' . get_class($e));
    LogSistema::registrar('n8n_anexo_erro', ['excecao' => $e, 'http' => 500, 'motivo' => 'falha_inesperada']);
    Resposta::erroComDados('Erro interno ao processar o arquivo.', 'ERRO_INTERNO', [], 500);
}
