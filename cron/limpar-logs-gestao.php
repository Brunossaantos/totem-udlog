<?php
// Rodar via cron do cPanel, SOMENTE via PHP CLI (sugestao: 04:10 diario):
//   php /caminho/absoluto/cron/limpar-logs-gestao.php
//   php /caminho/absoluto/cron/limpar-logs-gestao.php --dry-run   (so conta, nao apaga)
//
// Demanda gestao-totem F3d (2026-10-08) -- retencao de 90 dias, decisao do
// usuario, para os DOIS conjuntos de dados da gestao:
//   1. tb_log_sistema (log central, migration 023) -- LogSistemaDao;
//   2. tb_gestao_auditoria (trilha da gestao, migration 021) -- AuditoriaRetencaoDao,
//      o unico lugar do sistema autorizado a apagar dessa tabela.
// Apaga primeiro os logs e depois a auditoria, em podas INDEPENDENTES (a falha de
// uma nao impede a outra; exit 1 se qualquer uma falhar). O corte e FIXO (agora menos 90
// dias, calculado nos DAOs, com piso de 90 dias): nao vem de .env, de argumento
// nem de requisicao. So CLI: a checagem PHP_SAPI abaixo e defesa adicional (nao ha
// rota em public/ para este script; nenhum botao/endpoint web o dispara).
//
// Seguranca operacional (mesmo molde de limpar-rate-limit-ocr.php):
//  - GET_LOCK impede duas execucoes simultaneas (a segunda sai sem fazer nada);
//  - lotes de 500 linhas, ate 50 lotes por tabela e teto de tempo da execucao;
//    se sobrar linha elegivel, a proxima execucao continua;
//  - trilha da propria execucao: ANTES de apagar abre uma linha PENDENTE
//    RETENCAO_EXECUTAR (alvo `sistema`, sem usuario, origem cron). Se nao der para
//    abrir a trilha, ABORTA sem apagar nada. Depois fecha OK ou ERRO com as
//    contagens (logs_apagados, auditoria_apagados, lotes);
//  - --dry-run: so conta o que seria apagado; nao apaga, nao abre trilha, nao grava
//    log no banco;
//  - log final agregado em error_log (contagens e corte), nunca conteudo de linha;
//    tambem registra no log central: cron_resumo (INFO) ou cron_falhou (ERRO);
//  - exit(0) sucesso (ou outra execucao em andamento), exit(1) falha.

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

/**
 * Apaga em lotes ate acabar, atingir o teto de lotes ou o teto de tempo.
 *
 * $total e $lotes sao por REFERENCIA e atualizados a cada lote concluido: se um
 * lote lancar, a excecao sobe mas o que ja foi apagado ate la fica preservado
 * (a trilha e o log registram a contagem real, nao zero).
 *
 * @param callable(int):int $apagarLote
 */
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

/**
 * Poda independente de UMA tabela: captura a falha e a devolve (a outra poda
 * da mesma execucao roda mesmo assim).
 *
 * @param callable(int):int $apagarLote
 */
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
        // cobre .env ausente/malformado, variavel obrigatoria ausente e falha de
        // conexao com mensagem sanitizada; se falhar, nada e sequer preparado
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

        // Sem trilha, sem exclusao.
        try {
            $idTrilha = $auditoria->abrir(null, 'RETENCAO_EXECUTAR', 'sistema', null, ['origem' => 'cron']);
        } catch (\Throwable $e) {
            error_log('limpar-logs-gestao: abortado -- nao foi possivel abrir a trilha de auditoria (nada foi apagado)');
            LogSistema::registrar('cron_falhou', ['job' => 'limpar_logs_gestao', 'motivo' => 'auditoria_indisponivel', 'excecao' => $e]);

            return 1;
        }

        // Duas podas INDEPENDENTES: a falha (ou tabela ausente) de uma nao impede a outra.
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
                // o lock some sozinho ao fechar a conexao
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
