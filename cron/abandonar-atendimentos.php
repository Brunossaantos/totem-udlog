<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Util\Bootstrap;
use Util\LogSistema;
use Util\NotaArquivoStorage;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Rn\AbandonoAtendimentoRn;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../');

    $storagePath = (string) ($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '');
    $storage = new NotaArquivoStorage($storagePath !== '' ? $storagePath : null);
    if ($storage->raizReal() === null) {
        error_log('abandonar-atendimentos: STORAGE_PATH ausente ou inacessivel -- nenhum atendimento alterado');
        LogSistema::registrar('cron_falhou', ['job' => 'abandonar_atendimentos', 'motivo' => 'config_ausente']);
        LogSistema::descarregar();
        exit(1);
    }

    $rn = new AbandonoAtendimentoRn(new AtendimentoDao($pdo), new AtendimentoNotaDao($pdo), $storage);
    $resultado = $rn->executar();
} catch (\PDOException $e) {
    $sqlstate = (string) $e->getCode();
    error_log('abandonar-atendimentos: falha de banco (PDOException)'
        . (preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? " [SQLSTATE={$sqlstate}]" : ''));
    LogSistema::registrar('cron_falhou', ['job' => 'abandonar_atendimentos', 'excecao' => $e, 'motivo' => 'erro_banco']);
    LogSistema::descarregar();
    exit(1);
} catch (\Throwable $e) {
    error_log('abandonar-atendimentos: falha de bootstrap ou execucao -- verificar .env, banco e STORAGE_PATH');
    LogSistema::registrar('cron_falhou', ['job' => 'abandonar_atendimentos', 'excecao' => $e, 'motivo' => 'falha_inesperada']);
    LogSistema::descarregar();
    exit(1);
}

error_log(sprintf(
    'abandonar-atendimentos: %d candidato(s), %d abandonado(s), %d ignorado(s) (voltaram a ficar ativos), %d falha(s), %d foto(s) em quarentena, %d foto(s) sem caminho valido, limite de %d por execucao %s',
    $resultado['candidatos'],
    $resultado['abandonados'],
    $resultado['ignorados'],
    $resultado['falhas'],
    $resultado['fotos_quarentenadas'],
    $resultado['fotos_sem_caminho_valido'],
    AbandonoAtendimentoRn::LIMITE_POR_EXECUCAO,
    $resultado['limite_atingido'] ? 'ATINGIDO (restante na proxima execucao)' : 'nao atingido'
));

if ($resultado['falhas'] > 0 || $resultado['fotos_sem_caminho_valido'] > 0) {
    LogSistema::registrar('cron_falhou', [
        'job' => 'abandonar_atendimentos',
        'motivo' => $resultado['falhas'] > 0 ? 'falha_inesperada' : 'dados_invalidos',
        'itens' => $resultado['abandonados'],
        'falhas' => $resultado['falhas'],
    ]);
} else {
    LogSistema::registrar('cron_resumo', ['job' => 'abandonar_atendimentos', 'itens' => $resultado['abandonados'], 'falhas' => 0]);
}
LogSistema::descarregar();

exit(($resultado['falhas'] > 0 || $resultado['fotos_sem_caminho_valido'] > 0) ? 1 : 0);
