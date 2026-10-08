<?php

/**
 * QA INDEPENDENTE do log central e da retencao de 90 dias da Gestao Totem
 * (demanda gestao-totem, F3a + F3d, 2026-10-08). Testes ADVERSARIAIS escritos pelo
 * QA (nao pelos implementadores): Util\LogSistema, App\Dao\LogSistemaDao,
 * Util\Conexao::criarDedicada, App\Dao\AuditoriaRetencaoDao, AuditoriaDao e
 * cron/limpar-logs-gestao.php (CLI real e php-cgi, subprocessos).
 *
 * Uso: php tests/manual/teste_gestao_qa_logs.php
 *
 * Banco QA descartavel `qa_qr_exclusivo_<hex>` (nunca udlog_totem), sem rede, sem
 * Talent/VIO/n8n; nenhum cron e ativado. Bancos extras (migration sobre schema
 * pre-023) tambem `qa_qr_exclusivo_<hex>` e sao dropados no fim.
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\AuditoriaDao;
use App\Dao\AuditoriaRetencaoDao;
use App\Dao\LogSistemaDao;
use Util\Conexao;
use Util\LogCatalogo;
use Util\LogSistema;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function qlApontar(string $banco): void
{
    $c = qaQrConfiguracao();
    $_ENV['DB_HOST'] = $c['host'];
    $_ENV['DB_PORT'] = $c['port'];
    $_ENV['DB_USER'] = $c['user'];
    $_ENV['DB_PASS'] = $c['pass'];
    qaQrValidarNomeBanco($banco);
    $_ENV['DB_NAME'] = $banco;
}

function qlEstatico(string $nome, mixed $valor = null, bool $ler = true): mixed
{
    $r = new ReflectionProperty(LogSistema::class, $nome);
    $r->setAccessible(true);
    if ($ler) {
        return $r->getValue();
    }
    $r->setValue(null, $valor);

    return null;
}

function qlReset(): void
{
    qlEstatico('fila', [], false);
    qlEstatico('ocupado', false, false);
    qlEstatico('bancoIndisponivel', false, false);
    qlEstatico('fallbackEm', [], false);
}

function qlFlush(): void
{
    LogSistema::descarregar();
}

function qlLimpar(PDO $pdo): void
{
    $pdo->exec('TRUNCATE TABLE tb_log_sistema');
}

/** @return list<array<string,mixed>> */
function qlLinhas(PDO $pdo, string $where = '1=1', array $p = []): array
{
    return gtLinhas($pdo, "SELECT * FROM tb_log_sistema WHERE $where ORDER BY id_log", $p);
}

function qlN(PDO $pdo, string $tabela, string $where = '1=1', array $p = []): int
{
    return (int) gtEscalar($pdo, "SELECT COUNT(*) FROM $tabela WHERE $where", $p);
}

function qlDumpTudo(PDO $pdo): string
{
    $s = '';
    foreach (['tb_log_sistema', 'tb_gestao_auditoria'] as $t) {
        foreach (gtLinhas($pdo, "SELECT * FROM $t") as $l) {
            $s .= json_encode(array_map(static fn ($v) => is_string($v) && !mb_check_encoding($v, 'UTF-8') ? bin2hex($v) : $v, $l), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        }
    }

    return $s;
}

function qlLogLer(string $arquivo): string
{
    return is_file($arquivo) ? (string) preg_replace('/^\[[^\]]+\] /m', '', (string) file_get_contents($arquivo)) : '';
}

function qlContemAlgum(string $texto, array $sentinelas): ?string
{
    foreach ($sentinelas as $s) {
        if ($s !== '' && str_contains($texto, $s)) {
            return $s;
        }
    }

    return null;
}

/** Exececao com SQLSTATE textual (o PDO real preenche code como string). */
class QlPdoExc extends PDOException
{
    public function __construct(string $mensagem, string|int $codigo)
    {
        parent::__construct($mensagem);
        $this->code = $codigo;
    }
}

/** Excecao cuja propriedade e __toString carregam segredo. */
class QlExcSegredo extends RuntimeException
{
    public string $segredo = '';

    public function __toString(): string
    {
        return 'QlExcSegredo ' . $this->segredo;
    }
}

function qlLancaComArgs(string $segredoArg): void
{
    throw new RuntimeException('falha ao tratar ' . $segredoArg, 7, new LogicException('anterior ' . $segredoArg));
}

/** Roda um subprocesso PHP CLI (tests/manual/teste_gestao_qa_logs.php --modo ...) e devolve [codigo, stdout, stderr]. */
function qlSub(array $args, array $ini = [], ?array $env = null, ?float $limite = null): array
{
    $cmd = [PHP_BINARY];
    foreach ($ini as $i) {
        $cmd[] = '-d';
        $cmd[] = $i;
    }
    $cmd = array_merge($cmd, [__FILE__], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);

    return [$rc, $out, $err];
}

// ---------------------------------------------------------------------------
// Modos de subprocesso
// ---------------------------------------------------------------------------
$modo = $argv[1] ?? '';
if ($modo === '--worker') {
    // concorrencia real: cada worker e um processo PHP separado
    $job = json_decode((string) base64_decode((string) $argv[2]), true);
    qlApontar((string) getenv('QA_QR_FORCE_DB_NAME'));
    while (microtime(true) < (float) $job['t0']) {
    }
    for ($i = 0; $i < (int) $job['rodadas']; $i++) {
        for ($j = 0; $j < (int) $job['por_rodada']; $j++) {
            LogSistema::registrar('erro_tecnico', ['id_atendimento' => 7001, 'id_totem' => 3, 'tipo' => 'recebimento']);
            LogSistema::registrar('erro_tecnico', ['id_atendimento' => 8000 + (int) $job['id'], 'id_totem' => 3, 'tipo' => 'expedicao']);
        }
        LogSistema::descarregar();
    }
    echo 'ok';
    exit(0);
}
if ($modo === '--reentrancia') {
    // um autoloader hostil chama o logger de DENTRO do logger (carga de LogCatalogo/Conexao/Dao)
    qlApontar((string) getenv('QA_QR_FORCE_DB_NAME'));
    $GLOBALS['aninhadas'] = 0;
    spl_autoload_register(static function (string $classe): void {
        if (in_array($classe, ['Util\LogCatalogo', 'App\Dao\LogSistemaDao', 'Util\Conexao'], true) && $GLOBALS['aninhadas'] < 50) {
            $GLOBALS['aninhadas']++;
            LogSistema::registrar('erro_tecnico', ['id_atendimento' => 999001]);
        }
    }, true, true);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 5555]);
    LogSistema::descarregar();
    $filaApos = count(qlEstatico('fila'));
    LogSistema::descarregar(); // se o evento aninhado tivesse entrado na fila, seria gravado aqui
    echo json_encode(['aninhadas' => $GLOBALS['aninhadas'], 'fila_apos' => $filaApos]);
    exit(0);
}
if ($modo === '--autoload-lancando') {
    // o autoloader falha (Error/Exception com segredo) ao carregar o catalogo (registrar) e o DAO/Conexao (descarregar)
    qlApontar((string) getenv('QA_QR_FORCE_DB_NAME'));
    $GLOBALS['quebrar'] = null;
    spl_autoload_register(static function (string $classe): void {
        if ($GLOBALS['quebrar'] === 'catalogo' && $classe === 'Util\LogCatalogo') {
            throw new RuntimeException('SENT_AUTOLOAD_SEGREDO_catalogo');
        }
        if ($GLOBALS['quebrar'] === 'descarga' && in_array($classe, ['App\Dao\LogSistemaDao', 'Util\Conexao'], true)) {
            throw new Error('SENT_AUTOLOAD_SEGREDO_descarga');
        }
    }, true, true);
    $r = [];
    $GLOBALS['quebrar'] = 'catalogo';
    try {
        LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1]);
        $r['registrar'] = 'ok';
    } catch (Throwable $e) {
        $r['registrar'] = 'LANCOU';
    }
    $GLOBALS['quebrar'] = null;
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 2]);
    $GLOBALS['quebrar'] = 'descarga';
    try {
        LogSistema::descarregar();
        $r['descarregar'] = 'ok';
    } catch (Throwable $e) {
        $r['descarregar'] = 'LANCOU';
    }
    echo json_encode($r);
    exit(0);
}
if ($modo === '--handler-lancando') {
    // tratador de erro que lanca ErrorException e tratador que loga: o logger nunca pode lancar
    $_ENV['DB_HOST'] = '127.0.0.1';
    $_ENV['DB_PORT'] = '1';
    $_ENV['DB_NAME'] = 'qa_qr_exclusivo_00000000';
    $_ENV['DB_USER'] = 'x';
    $_ENV['DB_PASS'] = 'x';
    set_error_handler(static function (int $n, string $s): bool {
        throw new ErrorException($s, 0, $n);
    });
    $lancou = false;
    try {
        LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1]);
        LogSistema::descarregar();
        LogSistema::registrar(['x'], new stdClass());
        LogSistema::descarregar();
    } catch (Throwable $e) {
        $lancou = true;
    }
    echo $lancou ? 'LANCOU' : 'NAOLANCOU';
    exit(0);
}

$banco = null;
$storage = null;
$bancoMig = null;
$arquivoLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_logs_' . bin2hex(random_bytes(4)) . '.log';
$logAnterior = ini_get('error_log');
$raiz = dirname(__DIR__, 2);
try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    qlApontar($banco);
    ini_set('error_log', $arquivoLog);
    file_put_contents($arquivoLog, '');
    ini_set('zend.exception_ignore_args', '0');
    afirmar('ambiente: DB_NAME e banco QA descartavel (nunca udlog_totem)', preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', (string) $_ENV['DB_NAME']) === 1 && $_ENV['DB_NAME'] === $banco);

    // =====================================================================
    // A1. Categoria hostil
    // =====================================================================
    qlReset();
    qlLimpar($pdo);
    $hostis = ['erro_tecnico ', 'ERRO_TECNICO', "erro_tecnico\0", 'erro_tecnico/../x', 5, null, [], new stdClass(), 1.5, true, false, '', str_repeat('A', 100000), "\xff\xfe\xfd", "'; DROP TABLE tb_log_sistema; --", "erro_tecnico' OR '1'='1", ['erro_tecnico'], static fn () => 1, '__proto__', 'constructor', "erro_tecnico\n", "\u{202E}erro_tecnico", 'log_parametro_invalido', 'log_suprimido'];
    $lancou = 0;
    ob_start();
    foreach ($hostis as $h) {
        try {
            LogSistema::registrar($h, ['id_atendimento' => 1]);
            LogSistema::registrar($h);
        } catch (Throwable $e) {
            $lancou++;
        }
    }
    qlFlush();
    $saida = ob_get_clean();
    $linhas = qlLinhas($pdo);
    $soma = array_sum(array_column($linhas, 'contador'));
    $detalhesOk = true;
    foreach ($linhas as $l) {
        if ($l['categoria'] !== 'log_parametro_invalido' || preg_match('/\A(alvo=[a-z_]+;)?motivo=[a-z_]+\z/D', (string) $l['detalhe']) !== 1) {
            $detalhesOk = false;
        }
    }
    afirmar('A1: 24 categorias hostis (x2 chamadas) nunca lancam e nunca escrevem na saida', $lancou === 0 && $saida === '');
    afirmar('A1: so existem linhas log_parametro_invalido com detalhe motivo=<enum> (nada do hostil na coluna)', $linhas !== [] && $detalhesOk);
    afirmar('A1: contador total == numero exato de chamadas rejeitadas (48)', $soma === 48);
    afirmar('A1: tabela intacta apos "DROP TABLE" hostil e nenhum dado hostil nas colunas', qlContemAlgum(qlDumpTudo($pdo), ['DROP', 'OR \'1\'', 'AAAAAAAA', 'proto']) === null);

    // =====================================================================
    // A2. Contexto hostil (qualquer item invalido descarta o evento INTEIRO)
    // =====================================================================
    qlReset();
    qlLimpar($pdo);
    $grande = str_repeat('a', 1000000);
    $objStr = new class () { public function __toString(): string { return '12'; } };
    $ctxHostis = [
        ['id_atendimento' => '12'], ['id_atendimento' => 12.0], ['id_atendimento' => true], ['id_atendimento' => -1], ['id_atendimento' => 0], ['id_atendimento' => null], ['id_atendimento' => [1]], ['id_atendimento' => new stdClass()], ['id_atendimento' => $objStr],
        ['id_totem' => 4294967296], ['id_totem' => PHP_INT_MAX], ['id_totem' => '5'], ['id_totem' => 0],
        ['tipo' => 'EXPEDICAO'], ['tipo' => "expedicao\0"], ['tipo' => "expedicao\n"], ['tipo' => ['expedicao']], ['tipo' => 'x'], ['tipo' => ''],
        ['excecao' => 'string'], ['excecao' => new stdClass()], ['excecao' => null], ['excecao' => ['x']],
        ['http' => '404'], ['http' => 99], ['http' => 600], ['http' => 404.0], ['http' => -404],
        ['motivo' => $grande], ['motivo' => "timeout\0"], ['motivo' => "\xff"], ['motivo' => ['timeout']], ['motivo' => 'TIMEOUT'], ['motivo' => 'timeout '], ['motivo' => 0], ['motivo' => true], ['motivo' => null],
        ['senha' => 'x'], ['mensagem' => 'texto livre'], ['detalhe' => 'x'], ['trace' => 'x'], ['ip' => '1.2.3.4'], ['token' => 'x'], ['cpf' => '123.456.789-09'], [0 => 'x'], ['id_atendimento' => 5, 'extra' => 1],
        ['job' => 'abandonar_atendimentos'], ['documento' => 'cnh'], ['categoria_erro' => 'timeout'], ['logs_apagados' => 1], ['alvo' => 'erro_tecnico'],
        ['id_atendimento' => 5, 'excecao' => new RuntimeException('x'), 'arquivo' => '/etc/passwd'],
        ['id_atendimento' => [str_repeat('x', 100000)]],
        ['id_atendimento' => ['a' => ['b' => ['c' => [1, 2, 3]]]]],
    ];
    $n = 0;
    $lancou = 0;
    ob_start();
    foreach ($ctxHostis as $c) {
        try {
            LogSistema::registrar('erro_tecnico', $c);
            $n++;
        } catch (Throwable $e) {
            $lancou++;
        }
    }
    foreach (['string', 5, null, new ArrayObject(['id_atendimento' => 5]), 1.5, true] as $naoArray) {
        try {
            LogSistema::registrar('erro_tecnico', $naoArray);
            $n++;
        } catch (Throwable $e) {
            $lancou++;
        }
    }
    qlFlush();
    $saida = ob_get_clean();
    afirmar('A2: ' . $n . ' contextos hostis (tipos errados, enormes, NUL, UTF-8 invalido, chaves estranhas, nao-array) nunca lancam nem imprimem', $lancou === 0 && $saida === '');
    afirmar('A2: nenhum evento erro_tecnico foi gravado (evento inteiro descartado)', qlN($pdo, 'tb_log_sistema', "categoria = 'erro_tecnico'") === 0);
    afirmar('A2: contador de log_parametro_invalido == numero exato de chamadas rejeitadas', (int) gtEscalar($pdo, "SELECT COALESCE(SUM(contador),0) FROM tb_log_sistema WHERE categoria = 'log_parametro_invalido'") === $n);
    $dump = qlDumpTudo($pdo);
    afirmar('A2: nada do conteudo hostil (CPF, /etc/passwd, NUL, texto livre) chegou a alguma coluna', qlContemAlgum($dump, ['123.456.789', '/etc/passwd', 'texto livre', '1.2.3.4', 'aaaaaaaa', 'xxxxxxxx']) === null);

    // limites superiores VALIDOS continuam aceitos (sem falso positivo)
    qlReset();
    qlLimpar($pdo);
    LogSistema::registrar('vio_falha_integracao', ['id_atendimento' => PHP_INT_MAX, 'id_totem' => 4294967295, 'tipo' => 'recebimento', 'http' => 599, 'documento' => 'crlv', 'motivo' => 'falha_inesperada']);
    LogSistema::registrar('erro_tecnico', ['http' => 100]);
    LogSistema::registrar('cron_resumo', ['job' => 'limpar_logs_gestao', 'logs_apagados' => 999999999, 'auditoria_apagados' => 0, 'lotes' => 99999]);
    qlFlush();
    afirmar('A2: limites validos (id max, http 100/599, contagens max) sao gravados', qlN($pdo, 'tb_log_sistema') === 3 && qlN($pdo, 'tb_log_sistema', "categoria = 'log_parametro_invalido'") === 0);
    afirmar('A2: detalhe de vio_falha_integracao segue a ordem fixa e cabe em 255', (string) gtEscalar($pdo, "SELECT detalhe FROM tb_log_sistema WHERE categoria = 'vio_falha_integracao'") === 'http=599;motivo=falha_inesperada;documento=crlv');

    // =====================================================================
    // A3. id_totem/id_atendimento/tipo em categoria que NAO aceita (401 anonimo)
    // =====================================================================
    qlReset();
    qlLimpar($pdo);
    for ($i = 1; $i <= 200; $i++) {
        LogSistema::registrar('totem_nao_autorizado', ['id_totem' => $i]);
        LogSistema::registrar('totem_nao_autorizado', ['id_atendimento' => $i]);
        LogSistema::registrar('totem_nao_autorizado', ['tipo' => 'expedicao']);
        LogSistema::registrar('n8n_anexo_erro', ['id_totem' => $i]);
        LogSistema::registrar('cron_resumo', ['id_totem' => $i]);
        LogSistema::registrar('gestao_erro_interno', ['id_atendimento' => $i]);
    }
    qlFlush();
    afirmar('A3: id_totem/id_atendimento/tipo em categoria que nao os aceita: 0 linhas da categoria', qlN($pdo, 'tb_log_sistema', "categoria IN ('totem_nao_autorizado','n8n_anexo_erro','cron_resumo','gestao_erro_interno')") === 0);
    afirmar('A3: so log_parametro_invalido (baixa cardinalidade: no maximo 4 linhas, uma por categoria-alvo)', qlN($pdo, 'tb_log_sistema') <= 4 && qlN($pdo, 'tb_log_sistema', "categoria <> 'log_parametro_invalido'") === 0);
    qlLimpar($pdo);
    for ($req = 0; $req < 120; $req++) {
        qlReset();
        LogSistema::registrar('totem_nao_autorizado', []);
        LogSistema::registrar('totem_nao_autorizado');
        qlFlush();
    }
    $l401 = qlLinhas($pdo, "categoria = 'totem_nao_autorizado'");
    afirmar('A3: 120 "requisicoes" 401 (240 eventos) viram <=2 linhas (janela 900 s) com contador total exato', count($l401) >= 1 && count($l401) <= 2 && array_sum(array_column($l401, 'contador')) === 240);
    afirmar('A3: a linha do 401 tem id_totem, id_atendimento e detalhe NULL, origem API, nivel AVISO', $l401[0]['id_totem'] === null && $l401[0]['id_atendimento'] === null && $l401[0]['detalhe'] === null && $l401[0]['origem'] === 'API' && $l401[0]['nivel'] === 'AVISO');
    qlLimpar($pdo);
    for ($req = 0; $req < 4; $req++) {
        qlReset();
        LogSistema::registrar('totem_nao_autorizado', []);
        qlFlush();
        usleep(1100000);
    }
    $l401 = qlLinhas($pdo, "categoria = 'totem_nao_autorizado'");
    afirmar('A3: 4 requisicoes 401 separadas por >1 s (mesma janela de 900 s) = <=2 linhas, soma 4 (a janela e um balde do relogio, nao o instante)', count($l401) >= 1 && count($l401) <= 2 && array_sum(array_column($l401, 'contador')) === 4);

    // =====================================================================
    // A4. Excecao com sentinelas de PII/segredo (nunca em coluna, error_log ou saida)
    // =====================================================================
    qlReset();
    qlLimpar($pdo);
    file_put_contents($arquivoLog, '');
    $sent = ['SENT_CPF_123.456.789-09', 'SENT_TOKEN_abcdef0123456789', 'SENT_SENHA_hunter2', 'SENT_PLACA_ABC1D23', 'SENT_CNPJ_12.345.678/0001-95'];
    $excs = [];
    try {
        qlLancaComArgs($sent[0] . ' ' . $sent[1]);
    } catch (Throwable $e) {
        $excs[] = $e;
    }
    $e2 = new QlExcSegredo($sent[2] . ' ' . $sent[3], 3, new RuntimeException($sent[4]));
    $e2->segredo = $sent[0];
    $excs[] = $e2;
    $excs[] = new QlPdoExc('SQLSTATE[42S02]: Base table or view not found: ' . $sent[1], '42S02');
    $excs[] = new QlPdoExc('Access denied for user ' . $sent[2] . '@host', 'HY000');
    $excs[] = new QlPdoExc('x ' . $sent[3], 1045);
    $excs[] = new QlPdoExc('x ' . $sent[4], "ab\ncd");
    $excs[] = new QlPdoExc('x', 'abcde');
    $excs[] = new TypeError('tipo errado ' . $sent[0]);
    $excs[] = new ErrorException($sent[1]);
    $excs[] = new class ($sent[0]) extends RuntimeException {};
    $excs[] = new class ($sent[0]) extends PDOException {};
    $ctxPorCat = ['erro_tecnico', 'erro_banco_pdo', 'vio_falha_integracao', 'cron_falhou', 'gestao_erro_interno', 'auditoria_falhou', 'oc_consulta_falhou', 'n8n_anexo_erro'];
    ob_start();
    $lancou = 0;
    foreach ($excs as $i => $ex) {
        foreach ($ctxPorCat as $cat) {
            try {
                LogSistema::registrar($cat, ['excecao' => $ex]);
            } catch (Throwable $t) {
                $lancou++;
            }
        }
        qlFlush();
        qlReset();
    }
    $saida = ob_get_clean();
    $dump = qlDumpTudo($pdo);
    $logTxt = qlLogLer($arquivoLog);
    afirmar('A4: excecoes com segredo em message/previous/trace/propriedade nunca lancam do logger', $lancou === 0);
    afirmar('A4: nenhuma sentinela (CPF/token/senha/placa/CNPJ) em QUALQUER coluna', qlContemAlgum($dump, $sent) === null);
    afirmar('A4: nenhuma sentinela no error_log nem na saida', qlContemAlgum($logTxt . $saida, $sent) === null && $saida === '');
    $detalhes = array_column(qlLinhas($pdo), 'detalhe');
    $so = true;
    foreach ($detalhes as $d) {
        if ($d !== null && preg_match('/\A(classe=[A-Za-z0-9_\\\\]{1,80})?(;?sqlstate=[A-Z0-9]{5})?(;?(http=\d{3}|motivo=[a-z_]+|documento=[a-z]+|job=[a-z_]+))*\z/D', $d) !== 1) {
            $so = false;
        }
    }
    afirmar('A4: detalhe so contem classe/sqlstate/http/enum (' . count($detalhes) . ' linhas)', $so);
    afirmar('A4: SQLSTATE valido (42S02, HY000) gravado; invalido (1045, "ab\\ncd", "abcde") omitido', qlN($pdo, 'tb_log_sistema', "detalhe LIKE '%sqlstate=42S02%'") >= 1 && qlN($pdo, 'tb_log_sistema', "detalhe LIKE '%sqlstate=HY000%'") >= 1 && qlN($pdo, 'tb_log_sistema', "detalhe LIKE '%sqlstate=1045%' OR detalhe LIKE '%abcde%' OR detalhe LIKE '%sqlstate=ab%'") === 0);
    afirmar('A4: classe anonima (nome com NUL/caminho) omite a classe mas grava o evento', qlN($pdo, 'tb_log_sistema', "categoria = 'erro_tecnico' AND (detalhe IS NULL OR detalhe NOT LIKE '%@anonymous%')") >= 1 && !str_contains($dump, '@anonymous') && !str_contains($dump, 'qa_logs'));

    // classe com nome de 80 e de 81 caracteres
    $n80 = 'Qa' . str_repeat('X', 78);
    $n81 = 'Qa' . str_repeat('Y', 79);
    eval("class $n80 extends RuntimeException {} class $n81 extends RuntimeException {}");
    qlReset();
    qlLimpar($pdo);
    LogSistema::registrar('erro_tecnico', ['excecao' => new $n80('x'), 'id_atendimento' => 1]);
    LogSistema::registrar('erro_tecnico', ['excecao' => new $n81('x'), 'id_atendimento' => 2]);
    qlFlush();
    $d1 = (string) gtEscalar($pdo, 'SELECT detalhe FROM tb_log_sistema WHERE id_atendimento = 1');
    $d2 = gtEscalar($pdo, 'SELECT detalhe FROM tb_log_sistema WHERE id_atendimento = 2');
    afirmar('A4: classe de 80 caracteres gravada; de 81 omitida; detalhe sempre <= 255', $d1 === 'classe=' . $n80 && ($d2 === null || $d2 === false) && strlen($d1) <= 255);

    // =====================================================================
    // A5. Tabela ausente/renomeada, coluna faltando, banco invalido
    // =====================================================================
    qlReset();
    qlLimpar($pdo);
    file_put_contents($arquivoLog, '');
    $pdo->exec('RENAME TABLE tb_log_sistema TO tb_log_sistema_qa_x');
    ob_start();
    $lancou = 0;
    try {
        for ($i = 0; $i < 30; $i++) {
            LogSistema::registrar('erro_tecnico', ['id_atendimento' => $i + 1, 'excecao' => new RuntimeException($sent[0])]);
            qlFlush();
        }
        LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 1]);
        qlFlush();
    } catch (Throwable $t) {
        $lancou++;
    }
    $saida = ob_get_clean();
    $logTxt = qlLogLer($arquivoLog);
    $linhasLog = array_values(array_filter(array_map('trim', explode("\n", $logTxt))));
    afirmar('A5: tabela renomeada: o logger nao lanca nem imprime nada', $lancou === 0 && $saida === '');
    afirmar('A5: error_log so com a linha FIXA (sem driver, banco, tabela, sentinela), uma por categoria/minuto', count($linhasLog) >= 1 && count($linhasLog) <= 2 && preg_match('/\ALogSistema: gravacao falhou categoria=[a-z_]+ classe=[A-Za-z\\\\]+\z/D', $linhasLog[0]) === 1 && qlContemAlgum($logTxt, array_merge($sent, ['tb_log_sistema', $banco, 'SQLSTATE', "doesn't exist"])) === null);
    $pdo->exec('RENAME TABLE tb_log_sistema_qa_x TO tb_log_sistema');
    qlReset();
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1]);
    qlFlush();
    afirmar('A5: tabela de volta: o logger se recupera na proxima requisicao (estado resetado)', qlN($pdo, 'tb_log_sistema') === 1);

    // coluna faltando
    qlReset();
    qlLimpar($pdo);
    file_put_contents($arquivoLog, '');
    $pdo->exec('ALTER TABLE tb_log_sistema DROP COLUMN contador');
    ob_start();
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1]);
    LogSistema::registrar('cron_falhou', ['excecao' => new RuntimeException($sent[1])]);
    qlFlush();
    $saida = ob_get_clean();
    $logTxt = qlLogLer($arquivoLog);
    $pdo->exec('ALTER TABLE tb_log_sistema ADD COLUMN contador INT UNSIGNED NOT NULL DEFAULT 1 AFTER janela');
    afirmar('A5: coluna ausente: sem lancar, sem saida, sem vazamento no error_log', $saida === '' && str_contains($logTxt, 'LogSistema: gravacao falhou') && qlContemAlgum($logTxt, array_merge($sent, ['Unknown column', 'contador', 'tb_log_sistema'])) === null);

    // banco invalido / host inatingivel (com tempo limitado)
    $envOriginal = $_ENV;
    $casos = [
        'banco inexistente' => ['DB_NAME' => 'qa_qr_exclusivo_deadbeef'],
        'porta recusada' => ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1'],
        'senha errada' => ['DB_PASS' => 'SENT_SENHA_errada_xyz'],
        'usuario inexistente' => ['DB_USER' => 'SENT_usuario_inexistente'],
        'DB_HOST ausente' => ['DB_HOST' => null],
        'DB_PASS nao string' => ['DB_PASS' => ['x']],
    ];
    foreach ($casos as $nome => $mudar) {
        $_ENV = $envOriginal;
        foreach ($mudar as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        qlReset();
        file_put_contents($arquivoLog, '');
        $t0 = microtime(true);
        ob_start();
        $lancou = 0;
        try {
            LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1]);
            qlFlush();
            LogSistema::registrar('erro_tecnico', ['id_atendimento' => 2]);
            qlFlush();
        } catch (Throwable $t) {
            $lancou++;
        }
        $saida = ob_get_clean();
        $dt = microtime(true) - $t0;
        $logTxt = qlLogLer($arquivoLog);
        afirmar("A5: banco invalido ($nome): nao lanca, sem saida, em " . round($dt, 2) . ' s (<= 6 s)', $lancou === 0 && $saida === '' && $dt <= 6.0);
        afirmar("A5: banco invalido ($nome): error_log fixo, sem host/usuario/senha/banco", qlContemAlgum($logTxt, ['SENT_', 'deadbeef', '127.0.0.1', 'Access denied', 'localhost', $envOriginal['DB_USER'], 'SQLSTATE']) === null);
        $_ENV = $envOriginal;
        $excMsg = null;
        $excPrev = true;
        $mudarEnv = $_ENV;
        foreach ($mudar as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        try {
            Conexao::criarDedicada();
        } catch (PDOException $t) {
            $excMsg = $t->getMessage();
            $excPrev = $t->getPrevious() !== null;
        }
        $_ENV = $envOriginal;
        afirmar("A5: criarDedicada ($nome): PDOException com mensagem FIXA e sem previous", $excMsg === 'Nao foi possivel conectar ao banco de dados' && $excPrev === false);
    }
    $_ENV = $envOriginal;
    qlReset();

    // =====================================================================
    // A6. Chamada dentro de transacao do chamador com rollback; lock do chamador
    // =====================================================================
    qlLimpar($pdo);
    $pdoChamador = Conexao::obter();
    $pdoChamador->exec('CREATE TABLE IF NOT EXISTS qa_aux (v INT)');
    $pdoChamador->beginTransaction();
    $pdoChamador->exec('INSERT INTO qa_aux (v) VALUES (1)');
    LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 31, 'excecao' => new PDOException('x')]);
    qlFlush();
    $pdoChamador->rollBack();
    afirmar('A6: rollback do chamador desfaz o dado dele mas NAO o log (conexao propria)', qlN($pdo, 'qa_aux') === 0 && qlN($pdo, 'tb_log_sistema', 'id_atendimento = 31') === 1 && !$pdoChamador->inTransaction());
    // o chamador segura lock na linha de log que o logger quer atualizar (mesma chave/janela)
    $pdoChamador->beginTransaction();
    $pdoChamador->query('SELECT * FROM tb_log_sistema WHERE id_atendimento = 31 FOR UPDATE')->fetchAll();
    file_put_contents($arquivoLog, '');
    qlReset();
    $t0 = microtime(true);
    LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 31, 'excecao' => new PDOException('x')]);
    qlFlush();
    $dt = microtime(true) - $t0;
    $logTxt = qlLogLer($arquivoLog);
    $pdoChamador->rollBack();
    afirmar('A6: lock do chamador na linha: o logger desiste em <= 6 s (innodb_lock_wait_timeout=2), sem lancar e com error_log fixo', $dt <= 6.0 && str_contains($logTxt, 'LogSistema: gravacao falhou') && !str_contains($logTxt, 'Lock wait'));
    $pdoChamador->exec('DROP TABLE qa_aux');
    qlReset();

    // =====================================================================
    // A7. Reentrancia e tratadores de erro hostis (subprocessos)
    // =====================================================================
    qlLimpar($pdo);
    [$rc, $out, $err] = qlSub(['--reentrancia'], ['auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', 'display_errors=0'], null);
    $j = json_decode($out, true);
    afirmar('A7: autoloader que chama o logger de dentro do logger: sem recursao infinita (rc 0), chamadas aninhadas disparadas', $rc === 0 && is_array($j) && $j['aninhadas'] >= 1 && $j['aninhadas'] < 50);
    afirmar('A7: chamada aninhada (durante registrar/descarregar) e IGNORADA pelo guard: fila vazia apos a descarga e 999001 nunca gravado', is_array($j) && $j['fila_apos'] === 0 && qlN($pdo, 'tb_log_sistema', 'id_atendimento = 5555') === 1 && qlN($pdo, 'tb_log_sistema', 'id_atendimento = 999001') === 0);
    [$rc, $out, $err] = qlSub(['--autoload-lancando'], ['auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', 'display_errors=0']);
    $j = json_decode($out, true);
    afirmar('A7: autoloader que lanca Exception/Error ao carregar catalogo/DAO/Conexao: registrar e descarregar nunca lancam e nada vaza', $rc === 0 && $j === ['registrar' => 'ok', 'descarregar' => 'ok'] && qlContemAlgum($out . $err, ['SENT_AUTOLOAD']) === null);
    [$rc, $out] = qlSub(['--handler-lancando'], ['display_errors=0']);
    afirmar('A7: tratador de erro que lanca ErrorException em todo aviso: o logger nunca lanca', $rc === 0 && $out === 'NAOLANCOU');

    // =====================================================================
    // A8. Volume: 10.000 iguais, teto por requisicao, dedup, concorrencia
    // =====================================================================
    qlReset();
    qlLimpar($pdo);
    for ($i = 0; $i < 10000; $i++) {
        LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 4242, 'id_totem' => 2, 'tipo' => 'recebimento']);
    }
    qlFlush();
    $l = qlLinhas($pdo, 'id_atendimento = 4242');
    afirmar('A8: 10.000 chamadas iguais na MESMA requisicao = 1 linha (contador limitado a 1000 por requisicao: teto em memoria)', count($l) === 1 && (int) $l[0]['contador'] === 1000);
    qlLimpar($pdo);
    for ($req = 0; $req < 10; $req++) {
        qlReset();
        for ($i = 0; $i < 1000; $i++) {
            LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 4242, 'id_totem' => 2, 'tipo' => 'recebimento']);
        }
        qlFlush();
    }
    $l = qlLinhas($pdo, 'id_atendimento = 4242');
    afirmar('A8: 10.000 chamadas em 10 requisicoes de 1000 = soma EXATA 10000 (<=2 linhas se cruzar a janela)', count($l) >= 1 && count($l) <= 2 && array_sum(array_column($l, 'contador')) === 10000);

    qlReset();
    qlLimpar($pdo);
    for ($i = 1; $i <= 40; $i++) {
        LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => $i, 'tipo' => 'expedicao']);
    }
    LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 3, 'tipo' => 'expedicao']);
    LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 3, 'tipo' => 'expedicao']);
    qlFlush();
    afirmar('A8: 40 eventos distintos em uma requisicao: so os 10 primeiros viram linha (teto por requisicao)', qlN($pdo, 'tb_log_sistema') === 10 && qlN($pdo, 'tb_log_sistema', 'id_atendimento BETWEEN 1 AND 10') === 10);
    afirmar('A8: repeticao de entrada JA na fila continua somando acima do teto (id 3 = contador 3)', (int) gtEscalar($pdo, 'SELECT contador FROM tb_log_sistema WHERE id_atendimento = 3') === 3);

    // dedup por janela (borda), injetando o balde na fila
    qlReset();
    qlLimpar($pdo);
    $balde = intdiv(time(), 300) * 300;
    $injetar = static function (int $janela) use ($pdo): void {
        qlEstatico('fila', ['k' . $janela => ['categoria' => 'erro_tecnico', 'tipo' => 'recebimento', 'id_totem' => null, 'id_atendimento' => 77, 'detalhe' => null, 'janela' => $janela, 'ocorrencias' => 1]], false);
        qlFlush();
    };
    $injetar($balde);
    $injetar($balde);
    $injetar($balde - 300);
    $injetar($balde + 300);
    $l = qlLinhas($pdo, 'id_atendimento = 77');
    afirmar('A8: mesma janela soma (2 no balde); janelas adjacentes (-300, +300) criam linhas SEPARADAS', count($l) === 3 && (int) gtEscalar($pdo, 'SELECT SUM(contador) FROM tb_log_sistema WHERE id_atendimento = 77') === 4);
    $esperada = gmdate('Y-m-d H:i:s', $balde - 3 * 3600);
    $jan = (string) gtEscalar($pdo, 'SELECT janela FROM tb_log_sistema WHERE id_atendimento = 77 AND contador = 2');
    afirmar('A8: coluna janela gravada no fuso -03:00 ' . $jan . ' (esperado ' . $esperada . ')', $jan === $esperada);
    $dtCriado = abs(strtotime((string) gtEscalar($pdo, 'SELECT criado_em FROM tb_log_sistema WHERE id_atendimento = 77 AND contador = 2') . ' -0300') - time());
    afirmar('A8: criado_em coerente com o relogio no fuso -03:00 (diferenca <= 5 s)', $dtCriado <= 5);

    // teto diario e total
    qlReset();
    qlLimpar($pdo);
    $semear = static function (PDO $pdo, int $n, string $quando, string $prefixo): void {
        for ($ini = 0; $ini < $n; $ini += 2000) {
            $q = min(2000, $n - $ini);
            $vals = [];
            $par = [];
            for ($i = 0; $i < $q; $i++) {
                $vals[] = "('INFO','CRON','cron_resumo','m',NULL,NULL,NULL,?,NOW(),1, $quando, $quando)";
                $par[] = sha1($prefixo . ($ini + $i));
            }
            $pdo->prepare('INSERT INTO tb_log_sistema (nivel,origem,categoria,mensagem,id_atendimento,id_totem,detalhe,dedup_chave,janela,contador,criado_em,ultima_ocorrencia) VALUES ' . implode(',', $vals))->execute($par);
        }
    };
    $semear($pdo, 1999, 'NOW()', 'dia');
    for ($i = 1; $i <= 5; $i++) {
        LogSistema::registrar('erro_tecnico', ['id_atendimento' => 600 + $i]);
    }
    qlFlush();
    afirmar('A8: teto diario (2000): com 1999 hoje, so 1 das 5 novas vira linha; 4 suprimidas', qlN($pdo, 'tb_log_sistema', 'id_atendimento BETWEEN 601 AND 605') === 1 && (int) gtEscalar($pdo, "SELECT COALESCE(SUM(contador),0) FROM tb_log_sistema WHERE categoria = 'log_suprimido' AND detalhe = 'motivo=teto_diario'") === 4);
    qlReset();
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 601]);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 699]);
    qlFlush();
    afirmar('A8: com o teto estourado, linha EXISTENTE (601, mesma janela) ainda soma e a nova (699) nao nasce', qlN($pdo, 'tb_log_sistema', 'id_atendimento = 699') === 0 && (int) gtEscalar($pdo, 'SELECT contador FROM tb_log_sistema WHERE id_atendimento = 601') >= 2);
    qlLimpar($pdo);
    qlReset();
    $semear($pdo, 100000, 'NOW() - INTERVAL 5 DAY', 'tot');
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 701]);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 702]);
    qlFlush();
    afirmar('A8: teto total (100000): nenhuma linha nova; log_suprimido motivo=teto_total contador 2', qlN($pdo, 'tb_log_sistema', 'id_atendimento IN (701,702)') === 0 && (int) gtEscalar($pdo, "SELECT COALESCE(SUM(contador),0) FROM tb_log_sistema WHERE categoria = 'log_suprimido' AND detalhe = 'motivo=teto_total'") === 2);
    for ($i = 0; $i < 20; $i++) {
        qlReset();
        LogSistema::registrar('erro_tecnico', ['id_atendimento' => 800 + $i]);
        qlFlush();
    }
    afirmar('A8: 20 requisicoes sob o teto total: log_suprimido continua em 1 linha por janela (<=2) e a tabela nao cresce alem de +2', qlN($pdo, 'tb_log_sistema', "categoria = 'log_suprimido'") <= 2 && qlN($pdo, 'tb_log_sistema') <= 100002);
    qlLimpar($pdo);

    // concorrencia real: 8 processos
    qlReset();
    $workers = 8;
    $rodadas = 25;
    $porRodada = 4;
    $t0 = microtime(true) + 3.0;
    $procs = [];
    for ($w = 1; $w <= $workers; $w++) {
        $job = base64_encode(json_encode(['t0' => $t0, 'rodadas' => $rodadas, 'por_rodada' => $porRodada, 'id' => $w]));
        $pr = proc_open([PHP_BINARY, __FILE__, '--worker', $job], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $raiz);
        $procs[] = [$pr, $pp];
    }
    $ok = 0;
    foreach ($procs as [$pr, $pp]) {
        $o = (string) stream_get_contents($pp[1]);
        stream_get_contents($pp[2]);
        fclose($pp[1]);
        fclose($pp[2]);
        proc_close($pr);
        $ok += $o === 'ok' ? 1 : 0;
    }
    $somaComum = (int) gtEscalar($pdo, 'SELECT COALESCE(SUM(contador),0) FROM tb_log_sistema WHERE id_atendimento = 7001');
    $linhasComum = qlN($pdo, 'tb_log_sistema', 'id_atendimento = 7001');
    afirmar("A8: $workers processos simultaneos terminam sem erro", $ok === $workers);
    afirmar('A8: chave compartilhada: soma EXATA ' . ($workers * $rodadas * $porRodada) . ' (obtido ' . $somaComum . ') em <=2 linhas (janela)', $somaComum === $workers * $rodadas * $porRodada && $linhasComum >= 1 && $linhasComum <= 2);
    $cadaOk = true;
    for ($w = 1; $w <= $workers; $w++) {
        if ((int) gtEscalar($pdo, 'SELECT COALESCE(SUM(contador),0) FROM tb_log_sistema WHERE id_atendimento = ' . (8000 + $w)) !== $rodadas * $porRodada) {
            $cadaOk = false;
        }
    }
    afirmar('A8: chaves privadas de cada worker: soma exata ' . ($rodadas * $porRodada) . ' cada', $cadaOk);
    afirmar('A8: sem duplicidade: nenhuma (dedup_chave, janela) repetida e sem linhas de log_parametro_invalido/suprimido', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM (SELECT dedup_chave, janela FROM tb_log_sistema GROUP BY dedup_chave, janela HAVING COUNT(*) > 1) x') === 0 && qlN($pdo, 'tb_log_sistema', "categoria IN ('log_parametro_invalido','log_suprimido')") === 0);
    qlLimpar($pdo);

    // =====================================================================
    // A9. Aba (origem) por tipo / sem atendimento
    // =====================================================================
    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api) VALUES ('QA-LOGS-1', 'QA Logs', '" . str_repeat('b', 64) . "')");
    $idTotemQa = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo) VALUES ('" . str_repeat('3', 36) . "', $idTotemQa, 'expedicao')");
    $idExp = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo) VALUES ('" . str_repeat('4', 36) . "', $idTotemQa, 'recebimento')");
    $idRec = (int) $pdo->lastInsertId();
    $casosAba = [
        ['atendimento exp (sem tipo)', ['id_atendimento' => $idExp], 'EXPEDICAO'],
        ['atendimento rec (sem tipo)', ['id_atendimento' => $idRec], 'RECEBIMENTO'],
        ['sem tipo e sem atendimento', [], 'API'],
        ['atendimento inexistente', ['id_atendimento' => 987654321], 'API'],
        ['tipo explicito recebimento', ['tipo' => 'recebimento'], 'RECEBIMENTO'],
        ['tipo explicito vence o atendimento', ['tipo' => 'recebimento', 'id_atendimento' => $idExp], 'RECEBIMENTO'],
        ['so id_totem', ['id_totem' => $idTotemQa], 'API'],
    ];
    foreach ($casosAba as [$nome, $ctx, $esperado]) {
        qlReset();
        qlLimpar($pdo);
        LogSistema::registrar('erro_banco_pdo', $ctx);
        qlFlush();
        afirmar("A9: aba por_tipo ($nome) = $esperado", (string) gtEscalar($pdo, 'SELECT origem FROM tb_log_sistema') === $esperado);
    }
    qlReset();
    qlLimpar($pdo);
    LogSistema::registrar('oc_baixa_falhou', ['id_atendimento' => $idRec]);
    LogSistema::registrar('rate_limit_ocr_excedido', ['id_atendimento' => $idExp]);
    LogSistema::registrar('cron_resumo', ['job' => 'limpar_logs_gestao']);
    LogSistema::registrar('gestao_erro_interno', []);
    qlFlush();
    afirmar('A9: origem FIXA do catalogo nao muda com o atendimento (EXPEDICAO/RECEBIMENTO/CRON/GESTAO)', (string) gtEscalar($pdo, "SELECT origem FROM tb_log_sistema WHERE categoria = 'oc_baixa_falhou'") === 'EXPEDICAO' && (string) gtEscalar($pdo, "SELECT origem FROM tb_log_sistema WHERE categoria = 'rate_limit_ocr_excedido'") === 'RECEBIMENTO' && (string) gtEscalar($pdo, "SELECT origem FROM tb_log_sistema WHERE categoria = 'cron_resumo'") === 'CRON' && (string) gtEscalar($pdo, "SELECT origem FROM tb_log_sistema WHERE categoria = 'gestao_erro_interno'") === 'GESTAO');
    qlLimpar($pdo);

    // =====================================================================
    // R1. Retencao: bordas com fuso, piso, lotes (DAO)
    // =====================================================================
    $aud = new AuditoriaRetencaoDao($pdo);
    $logsDao = new LogSistemaDao($pdo);
    $semAud = static function (PDO $pdo, int $n, string $quando, string $acao = 'LOGIN_OK'): void {
        for ($ini = 0; $ini < $n; $ini += 1000) {
            $q = min(1000, $n - $ini);
            $pdo->exec('INSERT INTO tb_gestao_auditoria (acao, resultado, criado_em) VALUES ' . implode(',', array_fill(0, $q, "('$acao','OK', $quando)")));
        }
    };
    $semLog = static function (PDO $pdo, int $n, string $quando, string $marca) use ($semear): void {
        $semear($pdo, $n, $quando, $marca);
    };
    $limparTudo = static function (PDO $pdo): void {
        $pdo->exec('TRUNCATE TABLE tb_log_sistema');
        $pdo->exec('DELETE FROM tb_gestao_auditoria');
    };
    $limparTudo($pdo);
    // idades: 89 d (mantem), 90 d + 2 min (apaga), 90 d - 2 min (mantem), 91 d (apaga)
    foreach (['89 DAY' => 'mantem', '90 DAY + INTERVAL 2 MINUTE' => 'mantem', '90 DAY - INTERVAL 2 MINUTE' => 'apaga', '91 DAY' => 'apaga'] as $idade => $_) {
        $semAud($pdo, 1, "NOW() - INTERVAL $idade");
        $semLog($pdo, 1, "NOW() - INTERVAL $idade", 'r1' . $idade);
    }
    afirmar('R1: contagem de elegiveis (apenas com idade 90d+2min e 91d): 2 e 2, sem alterar nada', $aud->contarAntigas() === 2 && $logsDao->contarAntigos() === 2 && qlN($pdo, 'tb_gestao_auditoria') === 4 && qlN($pdo, 'tb_log_sistema') === 4);
    afirmar('R1: apaga 2+2; idades 89d e 89d23h58m permanecem', $aud->apagarLote(500) === 2 && $logsDao->apagarAntigosLote(500) === 2 && qlN($pdo, 'tb_gestao_auditoria', 'criado_em >= NOW() - INTERVAL 90 DAY') === 2 && qlN($pdo, 'tb_log_sistema') === 2);

    $limparTudo($pdo);
    $semAud($pdo, 3, 'NOW() - INTERVAL 100 DAY');
    $semLog($pdo, 3, 'NOW() - INTERVAL 100 DAY', 'piso');
    $agoraTz = new DateTimeImmutable('now', new DateTimeZone('-03:00'));
    $cortes = [
        'agora' => $agoraTz,
        'agora - 89 dias' => $agoraTz->modify('-89 days'),
        'agora - 90 dias + 60 s' => $agoraTz->modify('-90 days')->modify('+60 seconds'),
        'amanha' => $agoraTz->modify('+1 day'),
        'agora (mutavel DateTime)' => DateTime::createFromImmutable($agoraTz),
        'agora em +14:00' => $agoraTz->setTimezone(new DateTimeZone('+14:00')),
        'agora em UTC' => $agoraTz->setTimezone(new DateTimeZone('UTC')),
        'PHP_INT_MAX' => (new DateTimeImmutable('@' . PHP_INT_MAX)),
    ];
    $recusados = 0;
    foreach ($cortes as $nome => $c) {
        foreach ([[$aud, 'apagarLoteAntesDe'], [$logsDao, 'apagarAntigosLoteAntesDe']] as [$o, $m]) {
            try {
                $o->$m($c, 500);
            } catch (InvalidArgumentException $e) {
                $recusados++;
            }
        }
    }
    afirmar('R1: piso de 90 dias: 8 cortes mais novos (agora, -89d, -90d+60s, futuro, outro fuso, DateTime) recusados nos 2 DAOs (16)', $recusados === 16 && qlN($pdo, 'tb_gestao_auditoria') === 3 && qlN($pdo, 'tb_log_sistema') === 3);
    $ok = 0;
    foreach ([[$aud, 'apagarLoteAntesDe'], [$logsDao, 'apagarAntigosLoteAntesDe']] as [$o, $m]) {
        $ok += $o->$m(new DateTimeImmutable('1900-01-01'), 500) === 0 ? 1 : 0;
        $ok += $o->$m($agoraTz->modify('-91 days')->setTimezone(new DateTimeZone('UTC')), 500) === 3 ? 1 : 0;
    }
    afirmar('R1: corte antigo valido (1900 apaga 0; -91d em UTC apaga as de 100d)', $ok === 4 && qlN($pdo, 'tb_gestao_auditoria') === 0 && qlN($pdo, 'tb_log_sistema') === 0);
    $semAud($pdo, 1200, 'NOW() - INTERVAL 100 DAY');
    $semLog($pdo, 1200, 'NOW() - INTERVAL 100 DAY', 'lote2');
    $lotes = [];
    for ($i = 0; $i < 4; $i++) {
        $lotes[] = [$aud->apagarLote(500), $logsDao->apagarAntigosLote(500)];
    }
    afirmar('R1: lotes de 500 (1200 linhas): 500/500, 500/500, 200/200, 0/0', $lotes === [[500, 500], [500, 500], [200, 200], [0, 0]]);
    $erros = 0;
    foreach ([-1, 0, 501, PHP_INT_MAX, PHP_INT_MIN] as $lim) {
        foreach ([[$aud, 'apagarLote'], [$logsDao, 'apagarAntigosLote']] as [$o, $m]) {
            try {
                $o->$m($lim);
            } catch (InvalidArgumentException $e) {
                $erros++;
            }
        }
    }
    afirmar('R1: lote fora de 1..500 recusado (10 chamadas)', $erros === 10);

    // =====================================================================
    // R2. Cron CLI real
    // =====================================================================
    $cron = static function (array $args = [], array $ini = [], bool $semLimite = false) use ($raiz): array {
        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_logs_cron_' . bin2hex(random_bytes(4)) . '.log';
        file_put_contents($log, '');
        $cmd = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'error_log="' . $log . '"'];
        foreach ($ini as $i) {
            $cmd[] = '-d';
            $cmd[] = $i;
        }
        $cmd[] = $raiz . '/cron/limpar-logs-gestao.php';
        $cmd = array_merge($cmd, $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz);
        $o = (string) stream_get_contents($pipes[1]);
        $e = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        $txt = (string) preg_replace('/^\[[^\]]+\] /m', '', (string) file_get_contents($log));
        @unlink($log);

        return ['rc' => $rc, 'log' => $txt, 'out' => $o . $e];
    };
    $snap = static function (PDO $pdo): string {
        return json_encode([
            gtLinhas($pdo, 'SELECT COUNT(*) c, COALESCE(MAX(id_auditoria),0) m, COALESCE(SUM(CRC32(CONCAT(acao,resultado,criado_em))),0) h FROM tb_gestao_auditoria'),
            gtLinhas($pdo, 'SELECT COUNT(*) c, COALESCE(MAX(id_log),0) m, COALESCE(SUM(contador),0) s FROM tb_log_sistema'),
        ]);
    };

    // R2.1 bordas sob varios fusos do PHP
    foreach (['America/Sao_Paulo', 'UTC', 'Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $tz) {
        $limparTudo($pdo);
        // 90 DAY - 5 MINUTE = idade de 90 d + 5 min (apaga); 90 DAY + 5 MINUTE = 89 d 23 h 55 min (mantem)
        foreach (['89 DAY', '90 DAY - INTERVAL 5 MINUTE', '90 DAY + INTERVAL 5 MINUTE', '91 DAY'] as $idade) {
            $semAud($pdo, 2, "NOW() - INTERVAL $idade");
            $semLog($pdo, 2, "NOW() - INTERVAL $idade", $tz . $idade);
        }
        $r = $cron([], ['date.timezone=' . $tz]);
        afirmar("R2: cron com date.timezone=$tz: apaga so as 2+2 linhas com idade > 90 dias (89d e 89d23h55m mantidas), rc 0", $r['rc'] === 0 && qlN($pdo, 'tb_gestao_auditoria', "acao = 'LOGIN_OK'") === 4 && qlN($pdo, 'tb_log_sistema', "categoria = 'cron_resumo' AND detalhe IS NULL AND ultima_ocorrencia < NOW() - INTERVAL 80 DAY") === 4 && str_contains($r['log'], 'logs_apagados=4 auditoria_apagados=4'));
    }
    $trilha = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'");
    afirmar('R2: trilha RETENCAO_EXECUTAR: alvo sistema, sem usuario, resultado OK, detalhe com origem=cron e contagens', count($trilha) === 1 && $trilha[0]['alvo_tipo'] === 'sistema' && $trilha[0]['id_usuario'] === null && $trilha[0]['resultado'] === 'OK' && preg_match('/origem=cron/', (string) $trilha[0]['detalhe']) === 1 && preg_match('/logs_apagados=4/', (string) $trilha[0]['detalhe']) === 1 && preg_match('/auditoria_apagados=4/', (string) $trilha[0]['detalhe']) === 1);
    $resumo = gtLinhas($pdo, "SELECT * FROM tb_log_sistema WHERE categoria = 'cron_resumo' AND ultima_ocorrencia >= NOW() - INTERVAL 1 DAY");
    afirmar('R2: o cron registrou cron_resumo (CRON/INFO) no log central com job e contagens', $resumo !== [] && $resumo[0]['origem'] === 'CRON' && $resumo[0]['nivel'] === 'INFO' && str_contains((string) $resumo[0]['detalhe'], 'job=limpar_logs_gestao'));

    // R2.2 teto de lotes por execucao
    $limparTudo($pdo);
    $semAud($pdo, 26000, 'NOW() - INTERVAL 100 DAY');
    $semLog($pdo, 26000, 'NOW() - INTERVAL 100 DAY', 'teto');
    $r = $cron();
    afirmar('R2: 26.000 elegiveis por tabela: uma execucao apaga no maximo 25.000 (50 lotes de 500) de cada; sobra 1000', $r['rc'] === 0 && qlN($pdo, 'tb_gestao_auditoria', "acao = 'LOGIN_OK'") === 1000 && qlN($pdo, 'tb_log_sistema', 'ultima_ocorrencia < NOW() - INTERVAL 90 DAY') === 1000 && str_contains($r['log'], 'lotes=100'));
    $r = $cron();
    afirmar('R2: a execucao seguinte termina o resto', $r['rc'] === 0 && qlN($pdo, 'tb_gestao_auditoria', "acao = 'LOGIN_OK'") === 0 && qlN($pdo, 'tb_log_sistema', 'ultima_ocorrencia < NOW() - INTERVAL 90 DAY') === 0);

    // R2.3 dry-run
    $limparTudo($pdo);
    $semAud($pdo, 7, 'NOW() - INTERVAL 120 DAY');
    $semLog($pdo, 7, 'NOW() - INTERVAL 120 DAY', 'dry');
    $semAud($pdo, 2, 'NOW() - INTERVAL 1 DAY');
    $antes = $snap($pdo);
    $r = $cron(['--dry-run']);
    afirmar('R2: --dry-run: rc 0, informa 7+7 elegiveis, NAO altera nenhuma linha das duas tabelas', $r['rc'] === 0 && $snap($pdo) === $antes && str_contains($r['log'], 'logs_elegiveis=7 auditoria_elegiveis=7'));
    afirmar('R2: --dry-run: nao grava trilha RETENCAO_EXECUTAR nem log cron_resumo/cron_falhou', qlN($pdo, 'tb_gestao_auditoria', "acao = 'RETENCAO_EXECUTAR'") === 0 && qlN($pdo, 'tb_log_sistema', "categoria IN ('cron_resumo','cron_falhou')") === 7);

    // R2.4 argumentos invalidos
    $antes = $snap($pdo);
    $ruins = [['--apagar'], ['--dry-run=1'], ['-n'], ['dry-run'], ['--DRY-RUN'], ['--dry-run', '--force'], ['--force', '--dry-run'], [''], ['--'], ['--dry-run ']];
    $todosDois = true;
    foreach ($ruins as $a) {
        $r = $cron($a);
        if ($r['rc'] !== 2) {
            $todosDois = false;
            echo '   (argumento ' . json_encode($a) . ' => rc ' . $r['rc'] . ")\n";
        }
    }
    afirmar('R2: argumento invalido => exit 2 em ' . count($ruins) . ' variantes, sem apagar nem gravar trilha', $todosDois && $snap($pdo) === $antes);

    // R2.5 fora de CLI (php-cgi)
    $cgi = gtCgiBinario();
    $envCgi = ['REDIRECT_STATUS' => '200', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $raiz . '/cron/limpar-logs-gestao.php', 'QUERY_STRING' => 'dry-run', 'QA_QR_FORCE_DB_NAME' => $banco, 'SystemRoot' => (string) getenv('SystemRoot'), 'QA_QR_DB_HOST' => (string) getenv('QA_QR_DB_HOST'), 'QA_QR_DB_PORT' => (string) getenv('QA_QR_DB_PORT'), 'QA_QR_DB_USER' => (string) getenv('QA_QR_DB_USER'), 'QA_QR_DB_PASS' => (string) getenv('QA_QR_DB_PASS')];
    $proc = proc_open([$cgi, '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', $raiz . '/cron/limpar-logs-gestao.php', ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $raiz, $envCgi);
    $o = (string) stream_get_contents($pp[1]);
    stream_get_contents($pp[2]);
    fclose($pp[1]);
    fclose($pp[2]);
    proc_close($proc);
    $antesCgi = $snap($pdo);
    afirmar('R2: execucao por php-cgi (web) recusada com 403 e corpo vazio, nada alterado', preg_match('/Status:\s*403/i', $o) === 1 && $snap($pdo) === $antes && $antesCgi === $antes);
    $limparTudo($pdo);
    $semAud($pdo, 3, 'NOW() - INTERVAL 100 DAY');
    $semLog($pdo, 3, 'NOW() - INTERVAL 100 DAY', 'cgi');
    $antes = $snap($pdo);
    $proc = proc_open([$cgi, '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', $raiz . '/cron/limpar-logs-gestao.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $raiz, $envCgi);
    $o = (string) stream_get_contents($pp[1]);
    stream_get_contents($pp[2]);
    fclose($pp[1]);
    fclose($pp[2]);
    proc_close($proc);
    afirmar('R2: php-cgi sem argumento tambem recusado (403) e NAO apaga o que era elegivel', preg_match('/Status:\s*403/i', $o) === 1 && $snap($pdo) === $antes);

    // R2.6 GET_LOCK ocupado => exit 0 sem apagar
    $nomeLock = 'totem_logs_' . substr(sha1($banco), 0, 16);
    $pdoLock = qaQrAbrirBanco($banco);
    $got = (int) $pdoLock->query("SELECT GET_LOCK('$nomeLock', 0)")->fetchColumn();
    $antes = $snap($pdo);
    $r = $cron();
    $depoisLock = $snap($pdo);
    $pdoLock->query("SELECT RELEASE_LOCK('$nomeLock')")->fetchAll();
    afirmar('R2: GET_LOCK ocupado => exit 0, "outra execucao em andamento", nada apagado e sem trilha', $got === 1 && $r['rc'] === 0 && str_contains($r['log'], 'outra execucao em andamento') && $depoisLock === $antes);
    $r = $cron();
    afirmar('R2: lock liberado => a execucao seguinte apaga normalmente', $r['rc'] === 0 && qlN($pdo, 'tb_gestao_auditoria', "acao = 'LOGIN_OK'") === 0 && qlN($pdo, 'tb_log_sistema', 'ultima_ocorrencia < NOW() - INTERVAL 90 DAY') === 0);
    $locks = (int) gtEscalar($pdo, "SELECT IS_USED_LOCK('$nomeLock') IS NOT NULL");
    afirmar('R2: o cron libera o lock ao terminar', $locks === 0);

    // R2.7 duas execucoes simultaneas
    $limparTudo($pdo);
    $semAud($pdo, 6000, 'NOW() - INTERVAL 100 DAY');
    $semLog($pdo, 6000, 'NOW() - INTERVAL 100 DAY', 'par');
    $procs = [];
    for ($i = 0; $i < 3; $i++) {
        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_logs_par_' . $i . bin2hex(random_bytes(3)) . '.log';
        file_put_contents($log, '');
        $pr = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'error_log="' . $log . '"', $raiz . '/cron/limpar-logs-gestao.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $raiz);
        $procs[] = [$pr, $pp, $log];
    }
    $rcs = [];
    $logsPar = '';
    foreach ($procs as [$pr, $pp, $log]) {
        stream_get_contents($pp[1]);
        stream_get_contents($pp[2]);
        fclose($pp[1]);
        fclose($pp[2]);
        $rcs[] = proc_close($pr);
        $logsPar .= (string) file_get_contents($log);
        @unlink($log);
    }
    $trilhas = gtLinhas($pdo, "SELECT detalhe, resultado FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'");
    $totalApagadoTrilha = 0;
    foreach ($trilhas as $t) {
        if (preg_match('/logs_apagados=(\d+)/', (string) $t['detalhe'], $m) === 1) {
            $totalApagadoTrilha += (int) $m[1];
        }
    }
    afirmar('R2: 3 crons simultaneos: todos rc 0, tudo apagado, sem trilha PENDENTE e soma das trilhas == 6000 logs', $rcs === [0, 0, 0] && qlN($pdo, 'tb_gestao_auditoria', "acao = 'LOGIN_OK'") === 0 && qlN($pdo, 'tb_log_sistema', 'ultima_ocorrencia < NOW() - INTERVAL 90 DAY') === 0 && qlN($pdo, 'tb_gestao_auditoria', "resultado = 'PENDENTE'") === 0 && $totalApagadoTrilha === 6000);

    // R2.8 abertura da trilha falhando aborta SEM apagar
    $limparTudo($pdo);
    $semAud($pdo, 5, 'NOW() - INTERVAL 100 DAY');
    $semLog($pdo, 5, 'NOW() - INTERVAL 100 DAY', 'trg1');
    $antes = $snap($pdo);
    $pdo->exec("CREATE TRIGGER qa_trg_ins BEFORE INSERT ON tb_gestao_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SENT_TRIGGER_123.456.789-09'");
    $r = $cron();
    $pdo->exec('DROP TRIGGER qa_trg_ins');
    $dumpPos = qlDumpTudo($pdo);
    afirmar('R2: trilha nao abre (insert falha): exit 1, "abortado", NADA apagado (auditoria e logs intactos)', $r['rc'] === 1 && str_contains($r['log'], 'abortado') && qlN($pdo, 'tb_gestao_auditoria') === 5 && qlN($pdo, 'tb_log_sistema', "categoria = 'cron_resumo' AND ultima_ocorrencia < NOW() - INTERVAL 90 DAY") === 5);
    afirmar('R2: a falha de abertura nao vaza a mensagem do banco (sentinela) em log, saida nem colunas', qlContemAlgum($r['log'] . $r['out'] . $dumpPos, ['SENT_TRIGGER', '123.456.789']) === null);
    afirmar('R2: a falha foi registrada no log central: cron_falhou motivo=auditoria_indisponivel', qlN($pdo, 'tb_log_sistema', "categoria = 'cron_falhou' AND detalhe LIKE '%motivo=auditoria_indisponivel%'") === 1);

    // tabela de auditoria ausente
    $pdo->exec('RENAME TABLE tb_gestao_auditoria TO tb_gestao_auditoria_qa_x');
    $r = $cron();
    $pdo->exec('RENAME TABLE tb_gestao_auditoria_qa_x TO tb_gestao_auditoria');
    afirmar('R2: tabela de auditoria ausente: exit 1 e logs NAO apagados', $r['rc'] === 1 && qlN($pdo, 'tb_log_sistema', "categoria = 'cron_resumo' AND ultima_ocorrencia < NOW() - INTERVAL 90 DAY") === 5 && qlN($pdo, 'tb_gestao_auditoria') === 5);

    // fechamento falhando
    $pdo->exec("CREATE TRIGGER qa_trg_upd BEFORE UPDATE ON tb_gestao_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SENT_UPDATE_hunter2'");
    $r = $cron();
    $pdo->exec('DROP TRIGGER qa_trg_upd');
    afirmar('R2: fechamento da trilha falha: exit 1, trilha fica PENDENTE, mensagem do banco nao vaza', $r['rc'] === 1 && qlN($pdo, 'tb_gestao_auditoria', "acao = 'RETENCAO_EXECUTAR' AND resultado = 'PENDENTE'") === 1 && qlContemAlgum($r['log'] . $r['out'] . qlDumpTudo($pdo), ['SENT_UPDATE', 'hunter2']) === null);

    // DELETE falhando no meio
    $limparTudo($pdo);
    $semAud($pdo, 5, 'NOW() - INTERVAL 100 DAY');
    $semLog($pdo, 5, 'NOW() - INTERVAL 100 DAY', 'trg3');
    $pdo->exec("CREATE TRIGGER qa_trg_del BEFORE DELETE ON tb_log_sistema FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SENT_DELETE_ABC1D23'");
    $r = $cron();
    $pdo->exec('DROP TRIGGER qa_trg_del');
    $tr = gtLinhas($pdo, "SELECT resultado FROM tb_gestao_auditoria WHERE acao = 'RETENCAO_EXECUTAR'");
    afirmar('R2: DELETE de logs falha: exit 1, trilha fechada como ERRO, cron_falhou motivo=erro_banco, sem vazamento', $r['rc'] === 1 && count($tr) === 1 && $tr[0]['resultado'] === 'ERRO' && qlN($pdo, 'tb_log_sistema', "categoria = 'cron_falhou' AND detalhe LIKE '%motivo=erro_banco%' AND detalhe LIKE '%classe=PDOException%'") === 1 && qlContemAlgum($r['log'] . $r['out'] . qlDumpTudo($pdo), ['SENT_DELETE', 'ABC1D23']) === null);
    // F3d correcao: podas independentes. A falha na poda de logs NAO impede a da auditoria.
    afirmar('R2: apos falha no DELETE de logs a auditoria antiga E podada mesmo assim (podas independentes) e os 5 logs antigos permanecem', qlN($pdo, 'tb_gestao_auditoria', "acao = 'LOGIN_OK'") === 0 && qlN($pdo, 'tb_log_sistema', "categoria = 'cron_resumo' AND criado_em < NOW() - INTERVAL 90 DAY") === 5);

    // bootstrap falhando (sem .env/banco): rc 1 e nada
    $limparTudo($pdo);

    // =====================================================================
    // R3. Estatico: quem pode apagar
    // =====================================================================
    $arquivosPhp = static function (string $raiz, array $pastas): array {
        $r = [];
        foreach ($pastas as $p) {
            if (!is_dir($raiz . '/' . $p)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $p, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && in_array($f->getExtension(), ['php', 'phtml', 'inc'], true) && !str_contains(str_replace('\\', '/', $f->getPathname()), '/vendor/') && !str_contains(str_replace('\\', '/', $f->getPathname()), '/node_modules/')) {
                    $r[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }

        return $r;
    };
    $semComentarios = static function (string $arq): string {
        $s = '';
        foreach (token_get_all((string) file_get_contents($arq)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $s .= $t[1];
            } else {
                $s .= $t;
            }
        }

        return $s;
    };
    $web = $arquivosPhp($raiz, ['public', 'app/Controller', 'app/Views', 'util']);
    $web = array_merge($web, array_filter($arquivosPhp($raiz, ['app']), static fn ($f) => str_contains($f, '/app/Controller') || str_contains($f, '/app/Views') || str_contains($f, '/app/Rn') || str_contains($f, '/app/Service')));
    $refs = [];
    foreach (array_unique($web) as $f) {
        if (preg_match('/AuditoriaRetencaoDao|apagarAntigosLote|apagarLoteAntesDe|limpar-logs-gestao/', $semComentarios($f)) === 1) {
            $refs[] = basename($f);
        }
    }
    afirmar('R3: nenhum arquivo de public/controllers/views/util/Rn/Service referencia AuditoriaRetencaoDao, apagarAntigosLote ou o cron (' . count(array_unique($web)) . ' arquivos varridos)' . ($refs === [] ? '' : ' ' . implode(',', $refs)), $refs === [] && count(array_unique($web)) > 20);
    $todos = array_merge($arquivosPhp($raiz, ['app', 'util', 'public', 'cron', 'tools']));
    $fora = [];
    foreach (array_unique($todos) as $f) {
        $b = basename($f);
        $c = $semComentarios($f);
        if ($b !== 'AuditoriaRetencaoDao.php' && preg_match('/(DELETE\s+FROM|TRUNCATE(\s+TABLE)?|DROP\s+TABLE(\s+IF\s+EXISTS)?)\s+`?tb_gestao_auditoria/i', $c) === 1) {
            $fora[] = $b . ':auditoria';
        }
        if ($b !== 'LogSistemaDao.php' && preg_match('/(DELETE\s+FROM|TRUNCATE(\s+TABLE)?|DROP\s+TABLE(\s+IF\s+EXISTS)?)\s+`?tb_log_sistema/i', $c) === 1) {
            $fora[] = $b . ':log';
        }
    }
    afirmar('R3: DELETE/TRUNCATE/DROP de tb_gestao_auditoria so em AuditoriaRetencaoDao e de tb_log_sistema so em LogSistemaDao' . ($fora === [] ? '' : ' VIOLACOES ' . implode(',', $fora)), $fora === []);
    $cAud = $semComentarios($raiz . '/app/Dao/AuditoriaDao.php');
    afirmar('R3: AuditoriaDao.php (sem comentarios) nao contem a palavra DELETE nem TRUNCATE', preg_match('/\bDELETE\b|\bTRUNCATE\b/i', $cAud) === 0);
    $cRet = $semComentarios($raiz . '/app/Dao/AuditoriaRetencaoDao.php');
    afirmar('R3: AuditoriaRetencaoDao tem exatamente 1 DELETE, com corte, ORDER BY e LIMIT parametrizado', preg_match_all('/\bDELETE\b/i', $cRet) === 1 && str_contains($cRet, 'WHERE criado_em < :corte ORDER BY id_auditoria LIMIT :limite'));
    $cLog = $semComentarios($raiz . '/app/Dao/LogSistemaDao.php');
    afirmar('R3: LogSistemaDao tem exatamente 1 DELETE (com corte e LIMIT parametrizado)', preg_match_all('/\bDELETE\b/i', $cLog) === 1 && str_contains($cLog, 'WHERE criado_em < :corte ORDER BY id_log LIMIT :limite'));
    $cron = $semComentarios($raiz . '/cron/limpar-logs-gestao.php');
    afirmar('R3: o cron nao le corte de .env/getenv/$_GET/$_POST/$_REQUEST/$_SERVER; so 1 leitura de $_ENV (DB_NAME do lock) e 1 de $argv (apenas --dry-run)', preg_match('/getenv|\$_GET|\$_POST|\$_REQUEST|\$_SERVER/', $cron) === 0 && preg_match_all('/\$_ENV\[/', $cron) === 1 && str_contains($cron, "\$_ENV['DB_NAME']") && preg_match_all('/\$argv/', $cron) === 1);
    $cCon = $semComentarios($raiz . '/util/Conexao.php') . $semComentarios($raiz . '/util/Bootstrap.php');
    afirmar('R3: Util\\Conexao e Util\\Bootstrap nao referenciam LogSistema/LogCatalogo', !str_contains($cCon, 'LogSistema') && !str_contains($cCon, 'LogCatalogo'));
    $cLs = $semComentarios($raiz . '/util/LogSistema.php');
    afirmar('R3: LogSistema nao usa getMessage/getTraceAsString/getFile/getLine/__toString nem AuditoriaDao', !preg_match('/getMessage|getTrace|getFile|getLine|__toString|AuditoriaDao|\(string\)\s*\$e/', $cLs) && !preg_match('/getMessage|getTrace|getFile|getLine|\(string\)\s*\$e/', $semComentarios($raiz . '/util/LogCatalogo.php')));
    afirmar('R3: nenhum SQL de LogSistemaDao/AuditoriaRetencaoDao concatena variavel de entrada (apenas $where montado de condicoes fixas e $ordem/$direcao validados)', preg_match_all('/prepare\([^;]*\.\s*\$(?!where|ordem|direcao)/', $cLog . $cRet) === 0);

    // =====================================================================
    // M1. Migration 023: idempotencia sobre schema pre-023 e leitura 5.7
    // =====================================================================
    $schemaHead = (string) shell_exec('git -C ' . escapeshellarg($raiz) . ' show HEAD:sql/schema.sql 2>NUL');
    if ($schemaHead === '') {
        $schemaHead = (string) shell_exec('git -C ' . escapeshellarg($raiz) . ' show HEAD:sql/schema.sql');
    }
    afirmar('M1: schema.sql do HEAD obtido e NAO contem tb_log_sistema (estado pre-023)', strlen($schemaHead) > 1000 && !str_contains($schemaHead, 'tb_log_sistema'));
    $srv = qaQrPdoServidor(qaQrConfiguracao());
    $bancoMig = qaQrNomeBanco();
    qaQrValidarNomeBanco($bancoMig);
    $srv->exec("CREATE DATABASE `{$bancoMig}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $srv->exec("USE `{$bancoMig}`");
    $tmpSchema = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_schema_head_' . bin2hex(random_bytes(3)) . '.sql';
    file_put_contents($tmpSchema, $schemaHead);
    qaQrAplicarSql($srv, $tmpSchema);
    @unlink($tmpSchema);
    foreach (['014_tb_lgpd_aceite', '015_vio_api_br_estados_e_id_externo', '016_vio_api_br_cache', '017_cnh_modo_captura', '018_nota_client_uid', '019_drop_tb_fila_envio', '020_gestao_usuario_sessao', '021_gestao_auditoria', '022_totem_gestao'] as $mig) {
        try {
            qaQrAplicarSql($srv, $raiz . '/sql/migrations/' . $mig . '.sql');
        } catch (Throwable $e) {
            // migrations ja convergidas no schema do HEAD podem falhar so se nao forem idempotentes: registrado abaixo
            echo "   (migration $mig sobre o schema do HEAD: " . get_class($e) . ")\n";
        }
    }
    $existeAntes = (int) $srv->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$bancoMig}' AND TABLE_NAME = 'tb_log_sistema'")->fetchColumn();
    afirmar('M1: banco pre-023 (schema do HEAD + 014..022) ainda NAO tem tb_log_sistema', $existeAntes === 0);
    $falha023 = null;
    try {
        qaQrAplicarSql($srv, $raiz . '/sql/migrations/023_log_sistema.sql');
    } catch (Throwable $e) {
        $falha023 = get_class($e);
    }
    $srv->exec("INSERT INTO tb_log_sistema (nivel,origem,categoria,mensagem,dedup_chave,janela) VALUES ('INFO','CRON','cron_resumo','m','" . str_repeat('c', 40) . "', '2026-01-01 00:00:00')");
    $falha023b = null;
    $stmtsAntes = null;
    try {
        qaQrAplicarSql($srv, $raiz . '/sql/migrations/023_log_sistema.sql');
        qaQrAplicarSql($srv, $raiz . '/sql/migrations/023_log_sistema.sql');
    } catch (Throwable $e) {
        $falha023b = get_class($e);
    }
    afirmar('M1: 023 aplicada sobre pre-023 sem erro; reaplicada 2x (idempotente) sem erro e sem perder a linha existente', $falha023 === null && $falha023b === null && (int) $srv->query('SELECT COUNT(*) FROM tb_log_sistema')->fetchColumn() === 1);
    $estrutura = static function (PDO $p, string $db): array {
        $c = $p->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'tb_log_sistema' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $i = $p->query("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'tb_log_sistema' ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
        $e = $p->query("SELECT ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'tb_log_sistema'")->fetchAll(PDO::FETCH_ASSOC);

        return [$c, $i, $e];
    };
    afirmar('M1: estrutura (colunas, tipos, defaults, charset, indices, engine) da migration sobre pre-023 == schema.sql novo', $estrutura($srv, $bancoMig) === $estrutura($srv, $banco));
    $contratoTipos = [
        'id_log' => 'bigint(20) unsigned', 'nivel' => "enum('INFO','AVISO','ERRO')", 'origem' => "enum('API','RECEBIMENTO','EXPEDICAO','CRON','GESTAO')",
        'categoria' => 'varchar(40)', 'mensagem' => 'varchar(160)', 'id_atendimento' => 'bigint(20) unsigned', 'id_totem' => 'int(10) unsigned',
        'detalhe' => 'varchar(255)', 'dedup_chave' => 'char(40)', 'janela' => 'datetime', 'contador' => 'int(10) unsigned', 'criado_em' => 'datetime', 'ultima_ocorrencia' => 'datetime',
    ];
    foreach ([['migration sobre pre-023', $bancoMig], ['schema.sql', $banco]] as [$rotulo, $db]) {
        $tipos = [];
        foreach ($estrutura($srv, $db)[0] as $c) {
            $tipos[$c['COLUMN_NAME']] = preg_replace('/\s+/', ' ', strtolower((string) $c['COLUMN_TYPE']));
        }
        $esperado = array_map('strtolower', $contratoTipos);
        // MySQL 8.0.19+ omite a largura de inteiros: normaliza so a largura
        $norm = static fn (array $a): array => array_map(static fn ($t) => preg_replace('/\((?:20|10)\)/', '', (string) $t), $a);
        afirmar("M1: contrato de tipos ($rotulo): sem TEXT/BLOB/JSON, detalhe VARCHAR(255), mensagem VARCHAR(160), categoria VARCHAR(40), dedup CHAR(40), enums fechados", $norm($tipos) === $norm($esperado));
    }
    $sql023 = (string) file_get_contents($raiz . '/sql/migrations/023_log_sistema.sql');
    $sql023Sem = (string) preg_replace('/^\s*--.*$/m', '', $sql023);
    $incompat = [];
    foreach (['CHECK\s*\(' => 'CHECK', 'GENERATED|AS\s*\(' => 'coluna gerada', 'IF\s+NOT\s+EXISTS\s+\w+\s+(ADD|COLUMN)|ADD\s+COLUMN\s+IF' => 'ADD COLUMN IF NOT EXISTS', '\bJSON\b' => 'JSON', 'DEFAULT\s*\(' => 'DEFAULT expressao', '\bWITH\b|\bOVER\s*\(' => 'CTE/janela', 'RETURNING' => 'RETURNING', 'utf8mb4_0900' => 'collation 8.0', 'INVISIBLE' => 'INVISIBLE', 'ALGORITHM\s*=\s*INSTANT' => 'INSTANT', 'RENAME\s+COLUMN' => 'RENAME COLUMN', 'CREATE\s+INDEX\s+IF' => 'CREATE INDEX IF NOT EXISTS'] as $re => $nome) {
        if (preg_match('/' . $re . '/i', $sql023Sem) === 1) {
            $incompat[] = $nome;
        }
    }
    afirmar('M1: SQL da 023 (sem comentarios) livre de construcoes ausentes no MySQL 5.7' . ($incompat === [] ? '' : ' ' . implode(',', $incompat)), $incompat === [] && preg_match('/CREATE TABLE IF NOT EXISTS tb_log_sistema/', $sql023Sem) === 1 && !preg_match('/\bDROP\b|\bDELETE\b|\bTRUNCATE\b|\bALTER\b/i', $sql023Sem));
    $sqlsPhp = $cLog . $cRet . $semComentarios($raiz . '/app/Dao/AuditoriaDao.php');
    afirmar('M1: SQL dos DAOs sem recursos so do MariaDB/8.0 (RETURNING, WITH, OVER, JSON_, ->>, ROW_NUMBER, LIMIT em subquery)', preg_match('/RETURNING|\bWITH\s|\bOVER\s*\(|JSON_|->>|ROW_NUMBER|INSERT\s+IGNORE\s+INTO.*SELECT/i', $sqlsPhp) === 0);
    $nomesParam = [];
    preg_match_all('/INSERT INTO tb_log_sistema.*?ON DUPLICATE KEY UPDATE.*?\'/s', $cLog, $mIns);
    preg_match_all('/:([a-z_]+)/', $mIns[0][0] ?? '', $mp);
    $contagem = array_count_values($mp[1] ?? []);
    afirmar('M1: UPSERT sem placeholder nomeado repetido (contador_novo/contador_soma distintos)', $contagem !== [] && max($contagem) === 1);
    $srv->exec("DROP DATABASE IF EXISTS `{$bancoMig}`");
    $bancoMig = null;
} catch (Throwable $e) {
    echo "\nEXCECAO NO TESTE: " . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ' - ' . $e->getMessage() . "\n";
    $GLOBALS['gtFalhas']++;
} finally {
    ini_set('error_log', (string) $logAnterior);
    @unlink($arquivoLog);
    if ($bancoMig !== null) {
        qaQrDroparBanco($bancoMig);
    }
    gtDestruirAmbiente($banco, $storage);
}

exit(gtResumo('teste_gestao_qa_logs'));
