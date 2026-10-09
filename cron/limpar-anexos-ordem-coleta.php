<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Dao\OrdemColetaArquivoDao;
use App\Rn\OrdemColetaArquivoRn;
use Util\LogSistema;
use Util\OrdemColetaArquivoStorage;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

try {
    Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
} catch (\Throwable $e) {
    error_log('limpar-anexos-ordem-coleta: falha ao carregar .env (malformado) -- nenhuma exclusao iniciada');
    LogSistema::registrar('cron_falhou', ['job' => 'limpar_anexos_oc', 'excecao' => $e, 'motivo' => 'config_invalida']);
    LogSistema::descarregar();
    exit(1);
}

$storagePath = (string) ($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '');
if ($storagePath === '') {
    error_log('limpar-anexos-ordem-coleta: STORAGE_PATH ausente -- nenhuma exclusao iniciada');
    LogSistema::registrar('cron_falhou', ['job' => 'limpar_anexos_oc', 'motivo' => 'config_ausente']);
    LogSistema::descarregar();
    exit(1);
}

try {
    $rn = new OrdemColetaArquivoRn(new OrdemColetaArquivoDao(), new OrdemColetaArquivoStorage($storagePath));
    $resultado = $rn->limparExpirados(OrdemColetaArquivoRn::LOTE_RETENCAO, OrdemColetaArquivoRn::DIAS_RETENCAO);
} catch (\Throwable $e) {
    error_log('limpar-anexos-ordem-coleta: falha ao consultar a retencao (' . get_class($e) . ') -- nenhuma exclusao concluida');
    LogSistema::registrar('cron_falhou', ['job' => 'limpar_anexos_oc', 'excecao' => $e, 'motivo' => 'indisponivel']);
    LogSistema::descarregar();
    exit(1);
}

error_log(sprintf(
    'limpar-anexos-ordem-coleta: %d elegivel(is), %d arquivo(s) apagado(s), %d ja ausente(s), %d falha(s), lote de %d por execucao',
    $resultado['elegiveis'],
    $resultado['apagados'],
    $resultado['ausentes'],
    $resultado['falhas'],
    OrdemColetaArquivoRn::LOTE_RETENCAO
));

if ($resultado['falhas'] > 0) {
    error_log('limpar-anexos-ordem-coleta: houve falha ao apagar arquivo/linha -- verificar permissoes de STORAGE_PATH/ordens_coleta');
}

if ($resultado['falhas'] > 0) {
    LogSistema::registrar('cron_falhou', ['job' => 'limpar_anexos_oc', 'motivo' => 'falha_inesperada', 'itens' => $resultado['apagados'], 'falhas' => $resultado['falhas']]);
} else {
    LogSistema::registrar('cron_resumo', ['job' => 'limpar_anexos_oc', 'itens' => $resultado['apagados'], 'falhas' => 0]);
}
LogSistema::descarregar();

exit($resultado['falhas'] > 0 ? 1 : 0);
