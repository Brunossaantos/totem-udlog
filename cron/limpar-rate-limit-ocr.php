<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Util\Bootstrap;
use Util\LogSistema;
use App\Dao\RateLimitOcrDao;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

const RATE_LIMIT_JANELA_SEGUNDOS = 60;
const RETENCAO_SEGUNDOS = 24 * 60 * 60;
const LIMITE_LOTE = 500;
const MAX_LOTES = 50;

function logFalhaBancoPdoCron(string $contexto, \PDOException $e): void
{
    $sqlstate = (string) $e->getCode();
    $sqlstateValidado = preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? $sqlstate : null;

    error_log(
        $contexto . ': falha de banco (PDOException)'
        . ($sqlstateValidado !== null ? " [SQLSTATE={$sqlstateValidado}]" : '')
    );
}

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../');
    $dao = new RateLimitOcrDao($pdo);

    $agora = time();
    $corteUnixTime = $agora - RETENCAO_SEGUNDOS;
    $janelaAtual = intdiv($agora, RATE_LIMIT_JANELA_SEGUNDOS);
    $janelaAnterior = $janelaAtual - 1;

    $totalApagado = 0;
    $lotes = 0;

    while (true) {
        $apagados = $dao->apagarJanelasExpiradas($corteUnixTime, $janelaAtual, $janelaAnterior, LIMITE_LOTE);
        if ($apagados === 0) {
            break;
        }
        $totalApagado += $apagados;
        $lotes++;
        if ($lotes >= MAX_LOTES) {
            break;
        }
    }

    error_log(sprintf(
        'limpar-rate-limit-ocr: %d linha(s) apagada(s) em %d lote(s), corte=%d',
        $totalApagado,
        $lotes,
        $corteUnixTime
    ));
    LogSistema::registrar('cron_resumo', ['job' => 'limpar_rate_limit_ocr', 'itens' => $totalApagado, 'lotes' => $lotes]);
    LogSistema::descarregar();

    exit(0);
} catch (\PDOException $e) {
    logFalhaBancoPdoCron('limpar-rate-limit-ocr', $e);
    LogSistema::registrar('cron_falhou', ['job' => 'limpar_rate_limit_ocr', 'excecao' => $e, 'motivo' => 'erro_banco']);
    LogSistema::descarregar();
    exit(1);
} catch (\Throwable $e) {
    error_log('limpar-rate-limit-ocr: falha de bootstrap (.env/conexao) -- nenhuma exclusao iniciada');
    LogSistema::registrar('cron_falhou', ['job' => 'limpar_rate_limit_ocr', 'excecao' => $e, 'motivo' => 'falha_inesperada']);
    LogSistema::descarregar();
    exit(1);
}
