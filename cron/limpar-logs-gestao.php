<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Dao\AuditoriaDao;
use App\Dao\AuditoriaRetencaoDao;
use App\Dao\LogSistemaDao;
use Util\Bootstrap;
use Util\LogSistema;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

const LIMITE_LOTE = 500;
const MAX_LOTES_POR_TABELA = 50;
const MAX_SEGUNDOS = 240;

function apagarEmLotes(callable $apagarLote, int &$total, int &$lotes, float $prazo): void
{
    for ($i = 0; $i < MAX_LOTES_POR_TABELA; $i++) {
        if (microtime(true) >= $prazo) {
            break;
        }
        $apagados = $apagarLote(LIMITE_LOTE);
        if ($apagados === 0) {
            break;
        }
        $total += $apagados;
        $lotes++;
    }
}

function podar(callable $apagarLote, int &$total, int &$lotes, float $prazo): ?\Throwable
{
    try {
        apagarEmLotes($apagarLote, $total, $lotes, $prazo);

        return null;
    } catch (\Throwable $e) {
        return $e;
    }
}

function executarRetencao(bool $dryRun): int
{
    $inicio = microtime(true);
    $prazo = $inicio + MAX_SEGUNDOS;

    try {
        $pdo = Bootstrap::conectar(__DIR__ . '/../');
    } catch (\Throwable $e) {
        error_log('limpar-logs-gestao: falha de bootstrap (.env/conexao) -- nada foi apagado');

        return 1;
    }

    $logs = new LogSistemaDao($pdo);
    $retencao = new AuditoriaRetencaoDao($pdo);
    $corte = LogSistemaDao::corteRetencao()->format('Y-m-d H:i:s');

    if ($dryRun) {
        try {
            error_log(sprintf(
                'limpar-logs-gestao: dry-run logs_elegiveis=%d auditoria_elegiveis=%d corte=%s (nada foi apagado)',
                $logs->contarAntigos(),
                $retencao->contarAntigas(),
                $corte
            ));

            return 0;
        } catch (\Throwable $e) {
            error_log('limpar-logs-gestao: dry-run falhou (' . get_class($e) . ')');

            return 1;
        }
    }

    $nomeLock = 'totem_logs_' . substr(sha1((string) ($_ENV['DB_NAME'] ?? '')), 0, 16);
    $lockObtido = false;
    $idTrilha = null;
    $auditoria = new AuditoriaDao($pdo);
    $erro = null;
    $apagadosLogs = 0;
    $apagadosAuditoria = 0;
    $lotes = 0;

    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(:nome, 0)');
        $stmt->execute(['nome' => $nomeLock]);
        if ((int) $stmt->fetchColumn() !== 1) {
            error_log('limpar-logs-gestao: outra execucao em andamento -- nada foi feito');

            return 0;
        }
        $lockObtido = true;

        try {
            $idTrilha = $auditoria->abrir(null, 'RETENCAO_EXECUTAR', 'sistema', null, ['origem' => 'cron']);
        } catch (\Throwable $e) {
            error_log('limpar-logs-gestao: abortado -- nao foi possivel abrir a trilha de auditoria (nada foi apagado)');
            LogSistema::registrar('cron_falhou', ['job' => 'limpar_logs_gestao', 'motivo' => 'auditoria_indisponivel', 'excecao' => $e]);

            return 1;
        }

        $erroLogs = podar([$logs, 'apagarAntigosLote'], $apagadosLogs, $lotes, $prazo);
        $erroAuditoria = podar([$retencao, 'apagarLote'], $apagadosAuditoria, $lotes, $prazo);
        $falhas = array_values(array_filter([$erroLogs, $erroAuditoria]));
        $erro = $falhas[0] ?? null;

        $falhouFechar = false;
        try {
            $auditoria->fechar(
                $idTrilha,
                $erro === null ? 'OK' : 'ERRO',
                ['origem' => 'cron', 'logs_apagados' => $apagadosLogs, 'auditoria_apagados' => $apagadosAuditoria, 'lotes' => $lotes]
            );
        } catch (\Throwable $e) {
            $falhouFechar = true;
            error_log('limpar-logs-gestao: nao foi possivel fechar a trilha de auditoria (' . get_class($e) . ')');
        }

        error_log(sprintf(
            'limpar-logs-gestao: %s logs_apagados=%d auditoria_apagados=%d lotes=%d corte=%s',
            $erro === null ? 'concluido' : 'falhou (' . implode(', ', array_map(static fn(\Throwable $f): string => get_class($f), $falhas)) . ')',
            $apagadosLogs,
            $apagadosAuditoria,
            $lotes,
            $corte
        ));

        $contagens = ['logs_apagados' => $apagadosLogs, 'auditoria_apagados' => $apagadosAuditoria, 'lotes' => $lotes];
        if ($falhas === []) {
            LogSistema::registrar('cron_resumo', ['job' => 'limpar_logs_gestao'] + $contagens);
        } else {
            foreach ($falhas as $falha) {
                LogSistema::registrar('cron_falhou', [
                    'job' => 'limpar_logs_gestao',
                    'motivo' => $falha instanceof \PDOException ? 'erro_banco' : 'falha_inesperada',
                    'excecao' => $falha,
                ] + $contagens);
            }
        }

        return ($erro === null && !$falhouFechar) ? 0 : 1;
    } catch (\Throwable $e) {
        error_log('limpar-logs-gestao: falha inesperada (' . get_class($e) . ')');
        LogSistema::registrar('cron_falhou', ['job' => 'limpar_logs_gestao', 'motivo' => 'falha_inesperada', 'excecao' => $e]);

        return 1;
    } finally {
        if ($lockObtido) {
            try {
                $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:nome)');
                $stmt->execute(['nome' => $nomeLock]);
            } catch (\Throwable $e) {
            }
        }
    }
}

$dryRun = false;
foreach (array_slice($argv ?? [], 1) as $argumento) {
    if ($argumento === '--dry-run') {
        $dryRun = true;
    } else {
        error_log('limpar-logs-gestao: argumento desconhecido (aceita apenas --dry-run)');
        exit(2);
    }
}

$codigo = executarRetencao($dryRun);
LogSistema::descarregar();
exit($codigo);
