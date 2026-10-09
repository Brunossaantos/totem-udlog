<?php

/**
 * Retencao de 90 dias da Gestao Totem (demanda gestao-totem, fase F3d, 2026-10-08):
 * App\Dao\AuditoriaRetencaoDao, retencao de App\Dao\LogSistemaDao e
 * cron/limpar-logs-gestao.php (CLI real, subprocesso). Banco QA descartavel
 * `qa_qr_exclusivo_<hex>` (nunca udlog_totem); nenhum cron e ativado.
 *
 * Uso: php tests/manual/teste_gestao_retencao.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\AuditoriaDao;
use App\Dao\AuditoriaRetencaoDao;
use App\Dao\LogSistemaDao;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
/** Semeia $n linhas de auditoria criadas "$intervalo" atras (ex.: '91 DAY'). */
function rtAuditoria(PDO $pdo, int $n, string $intervalo, string $acao = 'LOGIN_OK'): void
{
    for ($ini = 0; $ini < $n; $ini += 500) {
        $q = min(500, $n - $ini);
        $pdo->exec('INSERT INTO tb_gestao_auditoria (acao, resultado, criado_em) VALUES ' . implode(',', array_fill(0, $q, "('$acao','OK', NOW() - INTERVAL $intervalo)")));
    }
}

/** Semeia $n linhas de log criadas "$intervalo" atras. */
function rtLog(PDO $pdo, int $n, string $intervalo, string $marca): void
{
    for ($ini = 0; $ini < $n; $ini += 500) {
        $vals = [];
        $params = [];
        for ($i = $ini; $i < min($n, $ini + 500); $i++) {
            $vals[] = "('INFO','CRON','cron_resumo','m',NULL,NULL,NULL,?,NOW(),1, NOW() - INTERVAL $intervalo, NOW() - INTERVAL $intervalo)";
            $params[] = sha1($marca . $i);
        }
        $pdo->prepare('INSERT INTO tb_log_sistema (nivel,origem,categoria,mensagem,id_atendimento,id_totem,detalhe,dedup_chave,janela,contador,criado_em,ultima_ocorrencia) VALUES ' . implode(',', $vals))->execute($params);
    }
}

function rtLimpar(PDO $pdo): void
{
    $pdo->exec('TRUNCATE TABLE tb_log_sistema');
    $pdo->exec('DELETE FROM tb_gestao_auditoria');
}

function rtContar(PDO $pdo, string $tabela): int
{
    return (int) gtEscalar($pdo, "SELECT COUNT(*) FROM $tabela");
}

/**
 * Roda cron/limpar-logs-gestao.php como CLI REAL no banco QA (prepend fail-closed).
 *
 * @param list<string> $args
 * @return array{codigo:int,log:string}
 */
function rtCron(array $args = []): array
{
    $raiz = dirname(__DIR__, 2);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_retencao_' . bin2hex(random_bytes(4)) . '.log';
    file_put_contents($log, '');
    $cmd = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'error_log="' . $log . '"', $raiz . '/cron/limpar-logs-gestao.php'], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proc);
    $texto = (string) preg_replace('/^\[[^\]]+\] /m', '', (string) file_get_contents($log));
    @unlink($log);

    return ['codigo' => $codigo, 'log' => $texto];
}

/** @return list<string> arquivos .php do projeto (sem vendor e sem tests) das pastas dadas */
function rtArquivos(string $raiz, array $pastas): array
{
    $r = [];
    foreach ($pastas as $pasta) {
        if (!is_dir($raiz . '/' . $pasta)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $pasta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && !str_contains(str_replace('\\', '/', $f->getPathname()), '/vendor/')) {
                $r[] = str_replace('\\', '/', $f->getPathname());
            }
        }
    }

    return $r;
}

$banco = null;
$storage = null;
try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $raiz = dirname(__DIR__, 2);
    $aud = new AuditoriaRetencaoDao($pdo);
    $logs = new LogSistemaDao($pdo);
    $agora = new DateTimeImmutable('now', new DateTimeZone('-03:00'));

    // =====================================================================
    // 1. Borda: 89 dias mantem, 91 dias apaga (auditoria e logs)
    // =====================================================================
    rtLimpar($pdo);
    foreach (['1 HOUR', '89 DAY', '90 DAY - INTERVAL 1 HOUR', '90 DAY + INTERVAL 1 HOUR', '91 DAY', '400 DAY'] as $idade) {
        rtAuditoria($pdo, 1, $idade);
        rtLog($pdo, 1, $idade, 'b' . $idade);
    }
    afirmar('borda: contarAntigas/contarAntigos veem so as 3 linhas com mais de 90 dias e nao alteram nada', $aud->contarAntigas() === 3 && $logs->contarAntigos() === 3 && rtContar($pdo, 'tb_gestao_auditoria') === 6 && rtContar($pdo, 'tb_log_sistema') === 6);
    $a = $aud->apagarLote(500);
    $l = $logs->apagarAntigosLote(500);
    afirmar('borda: apagar remove 3 de cada (90 d + 1 h, 91 d, 400 d) e mantem 1 h, 89 d e 90 d - 1 h', $a === 3 && $l === 3 && rtContar($pdo, 'tb_gestao_auditoria') === 3 && rtContar($pdo, 'tb_log_sistema') === 3);
    $restoA = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria WHERE criado_em >= NOW() - INTERVAL 90 DAY');
    $restoL = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em >= NOW() - INTERVAL 90 DAY');
    afirmar('borda: tudo que sobrou tem menos de 90 dias (89 d mantido, 91 d apagado)', $restoA === 3 && $restoL === 3);
    afirmar('constantes: retencao fixa de 90 dias nos dois DAOs e corte = agora - 90 dias (+-5 s)', AuditoriaRetencaoDao::RETENCAO_DIAS === 90 && LogSistemaDao::RETENCAO_DIAS === 90 && abs(AuditoriaRetencaoDao::corteRetencao()->getTimestamp() - ($agora->getTimestamp() - 90 * 86400)) <= 5 && abs(LogSistemaDao::corteRetencao()->getTimestamp() - ($agora->getTimestamp() - 90 * 86400)) <= 5);

    // =====================================================================
    // 2. Lotes (LIMIT) e ordem
    // =====================================================================
    rtLimpar($pdo);
    rtAuditoria($pdo, 1300, '100 DAY');
    rtLog($pdo, 1300, '100 DAY', 'lote');
    $sa = [$aud->apagarLote(500), $aud->apagarLote(500), $aud->apagarLote(500), $aud->apagarLote(500)];
    $sl = [$logs->apagarAntigosLote(500), $logs->apagarAntigosLote(500), $logs->apagarAntigosLote(500), $logs->apagarAntigosLote(500)];
    afirmar('lotes: 1300 linhas saem em lotes de 500, 500, 300, 0 (auditoria e logs)', $sa === [500, 500, 300, 0] && $sl === [500, 500, 300, 0]);
    $ok = 0;
    foreach ([[$aud, 'apagarLote'], [$logs, 'apagarAntigosLote']] as [$o, $m]) {
        foreach ([0, -1, 501, 100000] as $n) {
            try {
                $o->$m($n);
            } catch (InvalidArgumentException $e) {
                $ok++;
            }
        }
    }
    afirmar('lotes: tamanho fora de 1..500 e recusado (8 de 8)', $ok === 8);
    rtLimpar($pdo);
    rtAuditoria($pdo, 5, '100 DAY');
    $ids = array_map('intval', array_column(gtLinhas($pdo, 'SELECT id_auditoria FROM tb_gestao_auditoria ORDER BY id_auditoria'), 'id_auditoria'));
    $aud->apagarLote(2);
    $restantes = array_map('intval', array_column(gtLinhas($pdo, 'SELECT id_auditoria FROM tb_gestao_auditoria ORDER BY id_auditoria'), 'id_auditoria'));
    afirmar('lotes: apaga os mais antigos primeiro (ORDER BY id) e so o tamanho do lote', $restantes === array_slice($ids, 2));

    // =====================================================================
    // 3. Piso de 90 dias: recusa corte mais novo
    // =====================================================================
    rtLimpar($pdo);
    rtAuditoria($pdo, 3, '89 DAY');
    rtAuditoria($pdo, 2, '95 DAY');
    rtLog($pdo, 3, '89 DAY', 'piso1');
    rtLog($pdo, 2, '95 DAY', 'piso2');
    $recusas = 0;
    $cortesNovos = [$agora, $agora->modify('-1 day'), $agora->modify('-89 days'), $agora->modify('-90 days')->modify('+2 minutes'), $agora->modify('+1 day')];
    foreach ($cortesNovos as $c) {
        foreach ([[$aud, 'apagarLoteAntesDe'], [$logs, 'apagarAntigosLoteAntesDe']] as [$o, $m]) {
            try {
                $o->$m($c, 500);
            } catch (InvalidArgumentException $e) {
                $recusas++;
            }
        }
    }
    afirmar('piso: corte mais novo que agora - 90 dias e recusado em ambos os DAOs (10 de 10) e nada e apagado', $recusas === 10 && rtContar($pdo, 'tb_gestao_auditoria') === 5 && rtContar($pdo, 'tb_log_sistema') === 5);
    $a = $aud->apagarLoteAntesDe($agora->modify('-90 days')->modify('-1 minute'), 500);
    $l = $logs->apagarAntigosLoteAntesDe($agora->modify('-91 days'), 500);
    afirmar('piso: corte igual ou mais antigo que o piso e aceito (apaga so as de 95 dias)', $a === 2 && $l === 2 && rtContar($pdo, 'tb_gestao_auditoria') === 3 && rtContar($pdo, 'tb_log_sistema') === 3);
    $a = $aud->apagarLoteAntesDe($agora->modify('-200 days'), 500);
    afirmar('piso: corte bem mais antigo apaga menos (nada de 89 dias)', $a === 0 && rtContar($pdo, 'tb_gestao_auditoria') === 3);

    // =====================================================================
    // 4. Auditoria: RETENCAO_EXECUTAR, sistema, allowlist ampliada
    // =====================================================================
    $audDao = new AuditoriaDao($pdo);
    afirmar('AuditoriaDao: RETENCAO_EXECUTAR no catalogo (17 acoes) e alvo sistema permitido', in_array('RETENCAO_EXECUTAR', AuditoriaDao::ACOES, true) && count(AuditoriaDao::ACOES) === 17 && in_array('sistema', AuditoriaDao::ALVO_TIPOS, true));
    $id = $audDao->abrir(null, 'RETENCAO_EXECUTAR', 'sistema', null, ['origem' => 'cron']);
    $linha = gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria WHERE id_auditoria = :i', ['i' => $id])[0];
    afirmar('AuditoriaDao: abre PENDENTE sem usuario, alvo sistema, origem=cron e sem IP', $linha['resultado'] === 'PENDENTE' && $linha['id_usuario'] === null && $linha['alvo_tipo'] === 'sistema' && $linha['alvo_id'] === null && $linha['detalhe'] === 'origem=cron' && $linha['ip'] === null && $linha['acao'] === 'RETENCAO_EXECUTAR');
    $fechou = $audDao->fechar($id, 'OK', ['origem' => 'cron', 'logs_apagados' => 12345, 'auditoria_apagados' => 999999999, 'lotes' => 7]);
    $linha = gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria WHERE id_auditoria = :i', ['i' => $id])[0];
    afirmar('AuditoriaDao: fechar grava as contagens (inteiros acima de 9999) e nao reabre linha fechada', $fechou === true && $linha['resultado'] === 'OK' && $linha['detalhe'] === 'origem=cron;logs_apagados=12345;auditoria_apagados=999999999;lotes=7' && $audDao->fechar($id, 'ERRO', ['lotes' => 1]) === false && (string) gtEscalar($pdo, 'SELECT detalhe FROM tb_gestao_auditoria WHERE id_auditoria = :i', ['i' => $id]) === $linha['detalhe']);
    $id2 = $audDao->abrir(1, 'LOGIN_OK', null, null, ['origem' => 'web']);
    $audDao->fechar($id2, 'OK');
    afirmar('AuditoriaDao: fechar sem detalhe mantem o detalhe da abertura', (string) gtEscalar($pdo, 'SELECT detalhe FROM tb_gestao_auditoria WHERE id_auditoria = :i', ['i' => $id2]) === 'origem=web');
    $recusas = 0;
    foreach ([
        fn () => AuditoriaDao::montarDetalhe(['lotes' => '1234567890']),
        fn () => AuditoriaDao::montarDetalhe(['lotes' => '-1']),
        fn () => AuditoriaDao::montarDetalhe(['logs_apagados' => '1.5']),
        fn () => AuditoriaDao::montarDetalhe(['sessoes_revogadas' => '12345']),
        fn () => AuditoriaDao::montarDetalhe(['origem' => 'cron;lotes=1']),
        fn () => AuditoriaDao::montarDetalhe(['origem' => 'api']),
        fn () => $audDao->abrir(null, 'RETENCAO_EXECUTAR', 'qualquer', null, []),
        fn () => $audDao->abrir(null, 'RETENCAO_APAGAR', 'sistema', null, []),
    ] as $f) {
        try {
            $f();
        } catch (InvalidArgumentException $e) {
            $recusas++;
        }
    }
    afirmar('AuditoriaDao: allowlist continua fechada (inteiro grande so ate 9 digitos, sessoes_revogadas ate 9999, origem so web/cli/cron, alvo/acao do catalogo): 8 de 8', $recusas === 8);

    // =====================================================================
    // 5. Cron real
    // =====================================================================
    rtLimpar($pdo);
    rtAuditoria($pdo, 700, '120 DAY');
    rtAuditoria($pdo, 3, '10 DAY');
    rtLog($pdo, 1300, '95 DAY', 'cron-velho');
    rtLog($pdo, 4, '2 DAY', 'cron-novo');

    // --dry-run: nao apaga, nao abre trilha, nao grava log
    $r = rtCron(['--dry-run']);
    afirmar('dry-run: exit 0, linha agregada com elegiveis e nada alterado (nem trilha, nem log)', $r['codigo'] === 0 && str_contains($r['log'], 'dry-run logs_elegiveis=1300 auditoria_elegiveis=700') && str_contains($r['log'], 'nada foi apagado') && rtContar($pdo, 'tb_gestao_auditoria') === 703 && rtContar($pdo, 'tb_log_sistema') === 1304 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'") === 0);

    $r = rtCron(['--argumento-estranho']);
    afirmar('argumento desconhecido: exit 2 e nada alterado', $r['codigo'] === 2 && rtContar($pdo, 'tb_gestao_auditoria') === 703 && rtContar($pdo, 'tb_log_sistema') === 1304);

    // via web (php-cgi): so CLI, nada acontece
    $cgi = gtCgiBinario();
    $envCgi = ['REDIRECT_STATUS' => '200', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $raiz . '/cron/limpar-logs-gestao.php', 'QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'), 'SystemRoot' => (string) getenv('SystemRoot')];
    $p = proc_open([$cgi, '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz . '/cron', $envCgi);
    $saidaCgi = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    afirmar('via SAPI web (php-cgi): 403 e nenhuma exclusao', str_contains($saidaCgi, '403') && rtContar($pdo, 'tb_gestao_auditoria') === 703 && rtContar($pdo, 'tb_log_sistema') === 1304);

    // execucao real
    $r = rtCron();
    $trilha = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'");
    afirmar('cron: exit 0, apagou 1300 logs e 700 auditorias em 5 lotes (3 + 2), so as linhas com mais de 90 dias', $r['codigo'] === 0 && rtContar($pdo, 'tb_log_sistema') === 5 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao <> \'RETENCAO_EXECUTAR\'') === 3);
    afirmar('cron: trilha RETENCAO_EXECUTAR fechada OK, alvo sistema, sem usuario, origem=cron e contagens exatas', count($trilha) === 1 && $trilha[0]['resultado'] === 'OK' && $trilha[0]['alvo_tipo'] === 'sistema' && $trilha[0]['id_usuario'] === null && $trilha[0]['ip'] === null && $trilha[0]['detalhe'] === 'origem=cron;logs_apagados=1300;auditoria_apagados=700;lotes=5');
    $resumo = gtLinhas($pdo, "SELECT * FROM tb_log_sistema WHERE categoria = 'cron_resumo' AND origem = 'CRON' AND nivel = 'INFO' AND detalhe LIKE 'job=limpar_logs_gestao;%'");
    afirmar('cron: registra cron_resumo (INFO, CRON) com as contagens', count($resumo) === 1 && $resumo[0]['detalhe'] === 'job=limpar_logs_gestao;logs_apagados=1300;auditoria_apagados=700;lotes=5');
    afirmar('cron: error_log agregado (contagens e corte) sem conteudo de linha', preg_match('/limpar-logs-gestao: concluido logs_apagados=1300 auditoria_apagados=700 lotes=5 corte=\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/', $r['log']) === 1 && !str_contains($r['log'], 'LOGIN_OK') && !str_contains($r['log'], 'cron-velho'));
    afirmar('cron: nao apaga linhas novas (3 auditorias de 10 dias, 4 logs de 2 dias e os dois registros da propria execucao)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em >= NOW() - INTERVAL 3 DAY") === 5 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE criado_em >= NOW() - INTERVAL 11 DAY") === 4);

    // idempotencia
    $antes = [rtContar($pdo, 'tb_log_sistema'), rtContar($pdo, 'tb_gestao_auditoria')];
    $r = rtCron();
    $ultima = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR' ORDER BY id_auditoria DESC LIMIT 1")[0];
    afirmar('idempotencia: segunda execucao apaga 0 (so acrescenta a trilha e o cron_resumo da propria execucao)', $r['codigo'] === 0 && $ultima['detalhe'] === 'origem=cron;logs_apagados=0;auditoria_apagados=0;lotes=0' && $ultima['resultado'] === 'OK' && rtContar($pdo, 'tb_gestao_auditoria') === $antes[1] + 1 && rtContar($pdo, 'tb_log_sistema') === $antes[0] + 1);

    // teto de 50 lotes por tabela (25000 linhas) e continuacao na proxima execucao
    rtLimpar($pdo);
    rtAuditoria($pdo, 3, '10 DAY');
    $pdo->exec('INSERT INTO tb_log_sistema (nivel,origem,categoria,mensagem,dedup_chave,janela,contador,criado_em,ultima_ocorrencia) SELECT \'INFO\',\'CRON\',\'cron_resumo\',\'m\',SHA1(CONCAT(\'t\',a.n,b.n,c.n)),NOW(),1,NOW() - INTERVAL 100 DAY,NOW() - INTERVAL 100 DAY FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a, (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b, (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c');
    for ($k = 0; $k < 26; $k++) {
        $pdo->exec('INSERT INTO tb_log_sistema (nivel,origem,categoria,mensagem,dedup_chave,janela,contador,criado_em,ultima_ocorrencia) SELECT nivel,origem,categoria,mensagem,SHA1(CONCAT(dedup_chave,' . $k . ')),janela,1,criado_em,ultima_ocorrencia FROM tb_log_sistema WHERE criado_em < NOW() - INTERVAL 90 DAY LIMIT 1000');
    }
    $totalVelhos = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em < NOW() - INTERVAL 90 DAY');
    $r = rtCron();
    $restamVelhos = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em < NOW() - INTERVAL 90 DAY');
    afirmar("teto: com $totalVelhos logs velhos, uma execucao apaga no maximo 50 lotes de 500 (25000) e a proxima continua", $totalVelhos > 25000 && $r['codigo'] === 0 && $totalVelhos - $restamVelhos === 25000 && (string) gtEscalar($pdo, "SELECT detalhe FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR' ORDER BY id_auditoria DESC LIMIT 1") === 'origem=cron;logs_apagados=25000;auditoria_apagados=0;lotes=50');
    $r = rtCron();
    afirmar('teto: segunda execucao termina o que sobrou', $r['codigo'] === 0 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em < NOW() - INTERVAL 90 DAY') === 0);

    // =====================================================================
    // 6. Abertura da trilha falha: ABORTA sem apagar
    // =====================================================================
    rtLimpar($pdo);
    rtLog($pdo, 10, '100 DAY', 'abort');
    rtLog($pdo, 2, '1 DAY', 'abort-novo');
    $pdo->exec('RENAME TABLE tb_gestao_auditoria TO tb_gestao_auditoria_x');
    try {
        $r = rtCron();
        $logsDepois = rtContar($pdo, 'tb_log_sistema');
        $falhou = gtLinhas($pdo, "SELECT * FROM tb_log_sistema WHERE categoria = 'cron_falhou'");
    } finally {
        $pdo->exec('RENAME TABLE tb_gestao_auditoria_x TO tb_gestao_auditoria');
    }
    afirmar('trilha indisponivel: exit 1, mensagem de abortado e NENHUM log apagado', $r['codigo'] === 1 && str_contains($r['log'], 'abortado') && str_contains($r['log'], 'nada foi apagado') && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em < NOW() - INTERVAL 90 DAY") === 10);
    afirmar('trilha indisponivel: registra cron_falhou (ERRO, CRON) com motivo auditoria_indisponivel', count($falhou) === 1 && $falhou[0]['nivel'] === 'ERRO' && $falhou[0]['origem'] === 'CRON' && str_contains((string) $falhou[0]['detalhe'], 'motivo=auditoria_indisponivel') && str_contains((string) $falhou[0]['detalhe'], 'job=limpar_logs_gestao'));

    // =====================================================================
    // 7. Falha no meio: fecha ERRO com as contagens parciais
    // =====================================================================
    rtLimpar($pdo);
    rtLog($pdo, 600, '100 DAY', 'meio');
    rtAuditoria($pdo, 5, '100 DAY');
    $pdo->exec("CREATE TRIGGER qa_bloqueia_delete BEFORE DELETE ON tb_gestao_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'segredo-sentinela-do-trigger'");
    try {
        $r = rtCron();
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS qa_bloqueia_delete');
    }
    $trilha = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'");
    $falhou = gtLinhas($pdo, "SELECT * FROM tb_log_sistema WHERE categoria = 'cron_falhou'");
    afirmar('falha no meio: exit 1, trilha fechada ERRO com as contagens parciais (600 logs, 0 auditorias, 2 lotes)', $r['codigo'] === 1 && count($trilha) === 1 && $trilha[0]['resultado'] === 'ERRO' && $trilha[0]['detalhe'] === 'origem=cron;logs_apagados=600;auditoria_apagados=0;lotes=2' && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao <> \'RETENCAO_EXECUTAR\'') === 5);
    afirmar('falha no meio: cron_falhou (ERRO) com classe e SQLSTATE, SEM a mensagem do banco, nem no error_log', count($falhou) === 1 && $falhou[0]['detalhe'] === 'classe=PDOException;sqlstate=45000;job=limpar_logs_gestao;motivo=erro_banco;logs_apagados=600;auditoria_apagados=0;lotes=2' && !str_contains(lsDumpTudo($pdo) . $r['log'], 'segredo-sentinela'));

    // =====================================================================
    // 7b. Falha PARCIAL no meio da auditoria: preserva as contagens reais
    // =====================================================================
    rtLimpar($pdo);
    rtLog($pdo, 600, '100 DAY', 'parcial');
    rtAuditoria($pdo, 1200, '100 DAY');
    $limiteId = (int) gtEscalar($pdo, 'SELECT id_auditoria FROM tb_gestao_auditoria ORDER BY id_auditoria LIMIT 1 OFFSET 999');
    $pdo->exec("CREATE TRIGGER qa_bloqueia_delete BEFORE DELETE ON tb_gestao_auditoria FOR EACH ROW BEGIN IF OLD.id_auditoria > $limiteId THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x'; END IF; END");
    try {
        $r = rtCron();
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS qa_bloqueia_delete');
    }
    $trilha = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'");
    $falhou = gtLinhas($pdo, "SELECT * FROM tb_log_sistema WHERE categoria = 'cron_falhou'");
    afirmar('falha parcial: exit 1 e trilha ERRO com as contagens REAIS ate a falha (600 logs, 1000 auditorias, 4 lotes)', $r['codigo'] === 1 && count($trilha) === 1 && $trilha[0]['resultado'] === 'ERRO' && $trilha[0]['detalhe'] === 'origem=cron;logs_apagados=600;auditoria_apagados=1000;lotes=4' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao <> 'RETENCAO_EXECUTAR'") === 200);
    afirmar('falha parcial: error_log e cron_falhou carregam as mesmas contagens reais', str_contains($r['log'], 'logs_apagados=600 auditoria_apagados=1000 lotes=4') && count($falhou) === 1 && str_contains((string) $falhou[0]['detalhe'], 'logs_apagados=600;auditoria_apagados=1000;lotes=4'));

    // =====================================================================
    // 7c. Podas INDEPENDENTES: falha em uma nao impede a outra
    // =====================================================================
    rtLimpar($pdo);
    rtLog($pdo, 700, '100 DAY', 'indep1');
    rtAuditoria($pdo, 300, '100 DAY');
    $pdo->exec("CREATE TRIGGER qa_bloqueia_delete_log BEFORE DELETE ON tb_log_sistema FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x'");
    try {
        $r = rtCron();
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS qa_bloqueia_delete_log');
    }
    $trilha = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'");
    afirmar('independencia: DELETE de logs falha, a auditoria e podada mesmo assim; exit 1; trilha ERRO com logs 0 e auditoria 300', $r['codigo'] === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em < NOW() - INTERVAL 90 DAY') === 700 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao <> 'RETENCAO_EXECUTAR'") === 0 && count($trilha) === 1 && $trilha[0]['resultado'] === 'ERRO' && $trilha[0]['detalhe'] === 'origem=cron;logs_apagados=0;auditoria_apagados=300;lotes=1');

    rtLimpar($pdo);
    rtLog($pdo, 10, '100 DAY', 'indep2');
    rtAuditoria($pdo, 300, '100 DAY');
    $pdo->exec('RENAME TABLE tb_log_sistema TO tb_log_sistema_x');
    try {
        $r = rtCron();
        $trilha = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'");
        $restoAud = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao <> 'RETENCAO_EXECUTAR'");
    } finally {
        $pdo->exec('RENAME TABLE tb_log_sistema_x TO tb_log_sistema');
    }
    afirmar('independencia: tabela de logs AUSENTE nao impede a poda da auditoria (300 apagadas), exit 1, trilha ERRO', $r['codigo'] === 1 && $restoAud === 0 && count($trilha) === 1 && $trilha[0]['resultado'] === 'ERRO' && $trilha[0]['detalhe'] === 'origem=cron;logs_apagados=0;auditoria_apagados=300;lotes=1' && rtContar($pdo, 'tb_log_sistema') === 10);

    rtLimpar($pdo);
    rtLog($pdo, 700, '100 DAY', 'indep3');
    rtAuditoria($pdo, 5, '100 DAY');
    $pdo->exec("CREATE TRIGGER qa_bloqueia_delete BEFORE DELETE ON tb_gestao_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x'");
    try {
        $r = rtCron();
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS qa_bloqueia_delete');
    }
    afirmar('independencia (inversa): auditoria falha, os logs ja foram podados; exit 1', $r['codigo'] === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em < NOW() - INTERVAL 90 DAY') === 0);

    // =====================================================================
    // 8. Lock: duas execucoes simultaneas
    // =====================================================================
    rtLimpar($pdo);
    rtLog($pdo, 5, '100 DAY', 'lock');
    rtAuditoria($pdo, 5, '100 DAY');
    $segurador = qaQrAbrirBanco($banco);
    $nomeLock = 'totem_logs_' . substr(sha1($banco), 0, 16);
    $ganhou = (int) gtEscalar($segurador, 'SELECT GET_LOCK(:n, 0)', ['n' => $nomeLock]);
    $r = rtCron();
    afirmar('lock: com outra execucao em andamento sai com 0 sem apagar nem abrir trilha', $ganhou === 1 && $r['codigo'] === 0 && str_contains($r['log'], 'outra execucao em andamento') && rtContar($pdo, 'tb_log_sistema') === 5 && rtContar($pdo, 'tb_gestao_auditoria') === 5);
    gtEscalar($segurador, 'SELECT RELEASE_LOCK(:n)', ['n' => $nomeLock]);
    $r = rtCron();
    afirmar('lock: liberado o lock, a proxima execucao roda normalmente (e libera o proprio lock ao terminar)', $r['codigo'] === 0 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em < NOW() - INTERVAL 90 DAY') === 0 && (int) gtEscalar($segurador, 'SELECT IS_FREE_LOCK(:n)', ['n' => $nomeLock]) === 1);
    $segurador = null;

    // =====================================================================
    // 9. Estaticos: DELETE so no DAO, sem acesso web
    // =====================================================================
    $tudo = rtArquivos($raiz, ['app', 'util', 'public', 'tools', 'cron']);
    $dao = str_replace('\\', '/', $raiz) . '/app/Dao/AuditoriaRetencaoDao.php';
    $porArquivo = [];
    $destrutivo = [];
    foreach ($tudo as $f) {
        $c = (string) file_get_contents($f);
        $n = preg_match_all('/DELETE\s+FROM\s+`?tb_gestao_auditoria/i', $c);
        if ($n > 0) {
            $porArquivo[$f] = $n;
        }
        if (preg_match('/(TRUNCATE(\s+TABLE)?|REPLACE\s+INTO|DROP\s+TABLE(\s+IF\s+EXISTS)?)\s+`?tb_gestao_auditoria/i', $c) === 1) {
            $destrutivo[] = $f;
        }
    }
    afirmar('estatico: DELETE FROM tb_gestao_auditoria so em app/Dao/AuditoriaRetencaoDao.php (e uma unica vez)', array_keys($porArquivo) === [$dao] && $porArquivo[$dao] === 1);
    afirmar('estatico: nenhum TRUNCATE/REPLACE/DROP de tb_gestao_auditoria no codigo', $destrutivo === []);
    $fonteDao = (string) file_get_contents($dao);
    afirmar('estatico: o DELETE tem predicado de idade (criado_em < :corte), ORDER BY e LIMIT', preg_match('/DELETE FROM tb_gestao_auditoria WHERE criado_em < :corte ORDER BY id_auditoria LIMIT :limite/', $fonteDao) === 1 && !preg_match('/TRUNCATE|REPLACE|DROP/i', $fonteDao));
    afirmar('estatico: AuditoriaDao sem DELETE', preg_match('/\bDELETE\b/i', preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($raiz . '/app/Dao/AuditoriaDao.php'))) !== 1);
    $logDao = (string) file_get_contents($raiz . '/app/Dao/LogSistemaDao.php');
    $deletesLog = 0;
    foreach ($tudo as $f) {
        $deletesLog += preg_match_all('/DELETE\s+FROM\s+`?tb_log_sistema/i', (string) file_get_contents($f));
    }
    afirmar('estatico: DELETE de tb_log_sistema so no LogSistemaDao, uma vez, com predicado de idade, ORDER BY e LIMIT', $deletesLog === 1 && preg_match('/DELETE FROM tb_log_sistema WHERE criado_em < :corte ORDER BY id_log LIMIT :limite/', $logDao) === 1);
    $referencias = [];
    foreach (rtArquivos($raiz, ['public', 'app/Controller', 'app/Views', 'util', 'tools']) as $f) {
        $c = (string) file_get_contents($f);
        if (str_contains($c, 'AuditoriaRetencaoDao') || str_contains($c, 'limpar-logs-gestao') || preg_match('/apagarAntigosLote|apagarLoteAntesDe|apagarLote\b/', $c) === 1) {
            $referencias[] = $f;
        }
    }
    foreach (glob($raiz . '/app/Views/*/*') ?: [] as $v) {
        if (is_file($v) && (str_contains((string) file_get_contents($v), 'AuditoriaRetencaoDao') || str_contains((string) file_get_contents($v), 'limpar-logs-gestao'))) {
            $referencias[] = $v;
        }
    }
    afirmar('estatico: nada em public/, controllers, views, util/ ou tools/ referencia o DAO de retencao, os metodos de apagar nem o cron', $referencias === []);
    $quemUsa = [];
    foreach (rtArquivos($raiz, ['app', 'public', 'util', 'tools', 'cron']) as $f) {
        if (preg_match('/new\s+AuditoriaRetencaoDao|use\s+App\Dao\AuditoriaRetencaoDao/', (string) file_get_contents($f)) === 1 && $f !== $dao) {
            $quemUsa[] = basename($f);
        }
    }
    afirmar('estatico: o unico consumidor do AuditoriaRetencaoDao e o cron limpar-logs-gestao.php', $quemUsa === ['limpar-logs-gestao.php']);
    $fonteCron = (string) file_get_contents($raiz . '/cron/limpar-logs-gestao.php');
    afirmar('estatico: cron CLI-only, GET_LOCK, --dry-run, lotes de 500, teto de 50 lotes e de tempo, sem .env/argumento no corte', str_contains($fonteCron, "PHP_SAPI !== 'cli'") && str_contains($fonteCron, 'GET_LOCK') && str_contains($fonteCron, '--dry-run') && str_contains($fonteCron, 'const LIMITE_LOTE = 500;') && str_contains($fonteCron, 'const MAX_LOTES_POR_TABELA = 50;') && str_contains($fonteCron, 'MAX_SEGUNDOS') && !preg_match('/getenv|\$_ENV\[.?(RETEN|DIAS)/i', $fonteCron . $fonteDao . $logDao));
    $posLogs = strpos($fonteCron, 'apagarAntigosLote');
    $posAud = strpos($fonteCron, "[\$retencao, 'apagarLote']");
    $posAbrir = strpos($fonteCron, '->abrir(');
    afirmar('estatico: ordem do cron = abrir trilha, depois logs, depois auditoria', $posAbrir !== false && $posLogs !== false && $posAud !== false && $posAbrir < $posLogs && $posLogs < $posAud);
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em linha ' . $e->getLine() . ')', false);
} finally {
    gtDestruirAmbiente($banco, $storage);
}

function lsDumpTudo(PDO $pdo): string
{
    $s = '';
    foreach (gtLinhas($pdo, 'SELECT detalhe FROM tb_log_sistema') as $l) {
        $s .= (string) $l['detalhe'] . "\n";
    }
    foreach (gtLinhas($pdo, 'SELECT detalhe FROM tb_gestao_auditoria') as $l) {
        $s .= (string) $l['detalhe'] . "\n";
    }

    return $s;
}

exit(gtResumo('teste_gestao_retencao'));
