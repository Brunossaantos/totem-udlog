<?php

/**
 * POST /api/ordem-coleta-anexo.php — recebe o PDF da Ordem de Coleta enviado
 * pelo n8n (demanda anexo-ordem-coleta-n8n, 2026-10-05). Escopo: Expedicao.
 *
 * NAO usa o token do totem (Util\Auth::validarTotem): a autenticacao e
 * servidor-a-servidor (Util\AuthServidor, chave em ORDEM_COLETA_ANEXO_API_KEY)
 * e a rota nao precisa do banco do totem — so do banco externo de gestao de
 * coletas (tb_ordem_coleta_arquivos), aberto de forma preguicosa.
 *
 * Variaveis do .env: ORDEM_COLETA_ANEXO_API_KEY (obrigatoria; ausente = 503),
 * ORDEM_COLETA_ANEXO_MAX_BYTES (opcional, padrao 5242880),
 * ORDEM_COLETA_ANEXO_PERMITIR_HTTP (opcional; "true" so em dev local).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\AuthServidor;
use Util\OrdemColetaArquivoStorage;
use Util\Resposta;
use App\Controller\OrdemColetaAnexoController;
use App\Dao\OrdemColetaArquivoDao;
use App\Rn\OrdemColetaArquivoRn;

try {
    // safeLoad: .env ausente nao e erro aqui (a chave pode vir do ambiente);
    // chave ausente => 503 fail-closed no AuthServidor.
    Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();

    $chave = $_ENV['ORDEM_COLETA_ANEXO_API_KEY'] ?? getenv('ORDEM_COLETA_ANEXO_API_KEY');
    $chave = is_string($chave) ? $chave : '';
    $permitirHttp = ($_ENV['ORDEM_COLETA_ANEXO_PERMITIR_HTTP'] ?? getenv('ORDEM_COLETA_ANEXO_PERMITIR_HTTP')) === 'true';
    $limite = OrdemColetaArquivoRn::limiteDoAmbiente(
        isset($_ENV['ORDEM_COLETA_ANEXO_MAX_BYTES']) ? (string) $_ENV['ORDEM_COLETA_ANEXO_MAX_BYTES'] : null
    );

    $storagePath = rtrim((string) ($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: ''), '/\\');
    $dirRateLimit = $storagePath !== '' ? $storagePath . DIRECTORY_SEPARATOR . 'ordens_coleta_ratelimit' : null;

    // Authorization: $_SERVER (Apache/FPM, incl. REDIRECT_) ou getallheaders()
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
    Resposta::erroComDados('Erro interno ao processar o arquivo.', 'ERRO_INTERNO', [], 500);
}
