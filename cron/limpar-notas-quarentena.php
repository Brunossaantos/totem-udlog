<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\LogSistema;
use Util\NotaArquivoStorage;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

try {
    Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
} catch (\Throwable $e) {
    error_log('limpar-notas-quarentena: falha ao carregar .env (malformado) -- nenhuma exclusao iniciada');
    LogSistema::registrar('cron_falhou', ['job' => 'limpar_notas_quarentena', 'excecao' => $e, 'motivo' => 'config_invalida']);
    LogSistema::descarregar();
    exit(1);
}

$storagePath = (string) ($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '');

try {
    $storage = new NotaArquivoStorage($storagePath !== '' ? $storagePath : null);
    $resultado = $storage->limparQuarentenaExpirada(time());
} catch (\Throwable $e) {
    error_log('limpar-notas-quarentena: STORAGE_PATH ausente ou inacessivel -- nenhuma exclusao iniciada');
    LogSistema::registrar('cron_falhou', ['job' => 'limpar_notas_quarentena', 'excecao' => $e, 'motivo' => 'config_ausente']);
    LogSistema::descarregar();
    exit(1);
}

error_log(sprintf(
    'limpar-notas-quarentena: %d arquivo(s) .del apagado(s), %d mantido(s) (menos de 24 h), %d falha(s), %d entrada(s) ignorada(s), %d diretorio(s) ilegivel(is), limite de %d por execucao %s',
    $resultado['removidos'],
    $resultado['mantidos'],
    $resultado['falhas'],
    $resultado['ignorados'],
    $resultado['erros_leitura'],
    NotaArquivoStorage::LIMITE_REMOCOES_POR_EXECUCAO,
    $resultado['limite_atingido'] ? 'ATINGIDO (restante na proxima execucao)' : 'nao atingido'
));

if ($resultado['erros_leitura'] > 0) {
    error_log('limpar-notas-quarentena: varredura INCOMPLETA (diretorio ilegivel) -- verificar permissoes de STORAGE_PATH');
}

if ($resultado['falhas'] > 0 || $resultado['erros_leitura'] > 0) {
    LogSistema::registrar('cron_falhou', [
        'job' => 'limpar_notas_quarentena',
        'motivo' => 'falha_inesperada',
        'itens' => $resultado['removidos'],
        'falhas' => $resultado['falhas'] + $resultado['erros_leitura'],
    ]);
} else {
    LogSistema::registrar('cron_resumo', ['job' => 'limpar_notas_quarentena', 'itens' => $resultado['removidos'], 'falhas' => 0]);
}
LogSistema::descarregar();

exit(($resultado['falhas'] > 0 || $resultado['erros_leitura'] > 0) ? 1 : 0);
