<?php

/**
 * Log central do sistema (demanda gestao-totem, fase F3a, 2026-10-08): migration
 * 023, Util\LogCatalogo, Util\LogSistema, App\Dao\LogSistemaDao e
 * Util\Conexao::criarDedicada. Banco QA descartavel `qa_qr_exclusivo_<hex>`
 * (nunca udlog_totem), sem rede.
 *
 * Uso: php tests/manual/teste_log_sistema.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\LogSistemaDao;
use Util\Conexao;
use Util\LogCatalogo;
use Util\LogSistema;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function lsApontarParaBanco(string $banco): void
{
    $c = qaQrConfiguracao();
    $_ENV['DB_HOST'] = $c['host'];
    $_ENV['DB_PORT'] = $c['port'];
    $_ENV['DB_USER'] = $c['user'];
    $_ENV['DB_PASS'] = $c['pass'];
    qaQrValidarNomeBanco($banco);
    $_ENV['DB_NAME'] = $banco;
}

function lsEstatico(string $nome, mixed $valor = null, bool $ler = true): mixed
{
    $r = new ReflectionProperty(LogSistema::class, $nome);
    $r->setAccessible(true);
    if ($ler) {
        return $r->getValue();
    }
    $r->setValue(null, $valor);

    return null;
}

function lsReset(bool $manterFallback = false): void
{
    lsEstatico('fila', [], false);
    lsEstatico('ocupado', false, false);
    lsEstatico('bancoIndisponivel', false, false);
    if (!$manterFallback) {
        lsEstatico('fallbackEm', [], false);
    }
}

function lsFlush(): void
{
    LogSistema::descarregar();
}

/** @return list<array<string,mixed>> */
function lsLinhas(PDO $pdo, string $where = '1=1', array $p = []): array
{
    return gtLinhas($pdo, "SELECT * FROM tb_log_sistema WHERE $where ORDER BY id_log", $p);
}

function lsLimpar(PDO $pdo): void
{
    $pdo->exec('TRUNCATE TABLE tb_log_sistema');
}

function lsDump(PDO $pdo): string
{
    $s = '';
    foreach (gtLinhas($pdo, 'SELECT * FROM tb_log_sistema') as $l) {
        $s .= json_encode($l, JSON_UNESCAPED_UNICODE) . "\n";
    }

    return $s;
}

function lsLogLer(string $arquivo): string
{
    // remove o prefixo de data que o PHP acrescenta a cada linha do arquivo de log
    return is_file($arquivo) ? (string) preg_replace('/^\[[^\]]+\] /m', '', (string) file_get_contents($arquivo)) : '';
}

function lsLogLimpar(string $arquivo): void
{
    file_put_contents($arquivo, '');
}

/** Exececao com SQLSTATE textual (o PDO real preenche code como string). */
class LsPdoExc extends PDOException
{
    public function __construct(string $mensagem, string $codigo)
    {
        parent::__construct($mensagem);
        $this->code = $codigo;
    }
}

// ---------------------------------------------------------------------------
// Modo worker (concorrencia real: cada worker e um processo PHP separado)
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode((string) base64_decode((string) $argv[2]), true);
    $banco = (string) getenv('QA_QR_FORCE_DB_NAME');
    lsApontarParaBanco($banco);
    while (microtime(true) < (float) $job['t0']) {
        // espera ativa ate o instante comum de largada
    }
    for ($i = 0; $i < (int) $job['rodadas']; $i++) {
        for ($j = 0; $j < (int) $job['por_rodada']; $j++) {
            LogSistema::registrar('erro_tecnico', ['id_atendimento' => 77, 'id_totem' => 5, 'tipo' => 'recebimento']);
        }
        LogSistema::descarregar();
    }
    echo 'ok';
    exit(0);
}

$banco = null;
$storage = null;
$arquivoLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_log_sistema_' . bin2hex(random_bytes(4)) . '.log';
$logAnterior = ini_get('error_log');
try {
    // =====================================================================
    // 0. Ambiente
    // =====================================================================
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    lsApontarParaBanco($banco);
    ini_set('error_log', $arquivoLog);
    lsLogLimpar($arquivoLog);
    afirmar('ambiente: DB_NAME aponta para banco QA descartavel (nunca udlog_totem)', preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', (string) $_ENV['DB_NAME']) === 1 && $_ENV['DB_NAME'] === $banco);
    $raiz = dirname(__DIR__, 2);

    // =====================================================================
    // 1. Migration/estrutura da tabela
    // =====================================================================
    $colunas = gtLinhas($pdo, "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH AS tam FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_log_sistema' ORDER BY ORDINAL_POSITION");
    $nomesColunas = array_column($colunas, 'COLUMN_NAME');
    afirmar('tabela: exatamente as colunas previstas (sem IP, sem texto livre extra)', $nomesColunas === ['id_log', 'nivel', 'origem', 'categoria', 'mensagem', 'id_atendimento', 'id_totem', 'detalhe', 'dedup_chave', 'janela', 'contador', 'criado_em', 'ultima_ocorrencia']);
    $livres = array_filter($colunas, static fn ($c) => in_array($c['DATA_TYPE'], ['text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'json'], true) || ($c['DATA_TYPE'] === 'varchar' && (int) $c['tam'] > 255));
    afirmar('tabela: nenhuma coluna TEXT/BLOB/JSON nem VARCHAR acima de 255', $livres === []);
    $porNome = array_column($colunas, null, 'COLUMN_NAME');
    afirmar('tabela: tipos exatos (nivel, origem, categoria 40, mensagem 160, detalhe 255 NULL, dedup CHAR(40))',
        $porNome['nivel']['COLUMN_TYPE'] === "enum('INFO','AVISO','ERRO')"
        && $porNome['origem']['COLUMN_TYPE'] === "enum('API','RECEBIMENTO','EXPEDICAO','CRON','GESTAO')"
        && $porNome['categoria']['COLUMN_TYPE'] === 'varchar(40)'
        && $porNome['mensagem']['COLUMN_TYPE'] === 'varchar(160)'
        && $porNome['detalhe']['COLUMN_TYPE'] === 'varchar(255)' && $porNome['detalhe']['IS_NULLABLE'] === 'YES'
        && $porNome['dedup_chave']['COLUMN_TYPE'] === 'char(40)'
        && $porNome['id_atendimento']['IS_NULLABLE'] === 'YES' && $porNome['id_totem']['IS_NULLABLE'] === 'YES');
    $uk = (string) gtEscalar($pdo, "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_log_sistema' AND INDEX_NAME = 'uk_log_sistema_dedup' AND NON_UNIQUE = 0");
    afirmar('tabela: UNIQUE (dedup_chave, janela)', $uk === 'dedup_chave,janela');
    $fkLog = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_log_sistema' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    afirmar('tabela: sem chave estrangeira', $fkLog === 0);

    // =====================================================================
    // 2. Catalogo consistente
    // =====================================================================
    $cat = LogCatalogo::CATEGORIAS;
    $erros = [];
    foreach ($cat as $nome => $d) {
        if (preg_match('/\A[a-z0-9_]{1,40}\z/', $nome) !== 1) {
            $erros[] = "$nome: nome";
        }
        if (!in_array($d['origem'], array_merge(LogCatalogo::ORIGENS, ['por_tipo']), true)) {
            $erros[] = "$nome: origem";
        }
        if (!in_array($d['nivel'], LogCatalogo::NIVEIS, true)) {
            $erros[] = "$nome: nivel";
        }
        if (!is_string($d['mensagem']) || $d['mensagem'] === '' || mb_strlen($d['mensagem']) > 160) {
            $erros[] = "$nome: mensagem";
        }
        if (!is_int($d['janela']) || $d['janela'] < 1) {
            $erros[] = "$nome: janela";
        }
        foreach ($d['contexto'] as $k) {
            if (!in_array($k, LogCatalogo::CHAVES_GERAIS, true) && !isset(LogCatalogo::DOMINIO[$k])) {
                $erros[] = "$nome: contexto $k";
            }
        }
    }
    afirmar('catalogo: nomes, origens, niveis, mensagens (1..160), janelas (>0) e chaves de contexto validos (' . count($cat) . ' categorias)' . ($erros === [] ? '' : ' ' . implode(',', $erros)), $erros === []);
    $previstas = ['totem_nao_autorizado', 'oc_consulta_falhou', 'oc_baixa_falhou', 'banco_coletas_indisponivel', 'n8n_anexo_recusado', 'n8n_anexo_erro', 'talent_erro_reprocessavel', 'talent_indeterminado', 'vio_falha_integracao', 'vio_erro_interno', 'erro_banco_pdo', 'erro_tecnico', 'rate_limit_ocr_excedido', 'etiqueta_pdf_falhou', 'etiqueta_config_invalida', 'impressao_config_falhou', 'cron_resumo', 'cron_falhou', 'gestao_erro_interno', 'auditoria_falhou', 'log_parametro_invalido', 'log_suprimido'];
    afirmar('catalogo: todas as categorias previstas para a F3b existem (e so elas)', array_keys($cat) === $previstas || (array_diff($previstas, array_keys($cat)) === [] && array_diff(array_keys($cat), $previstas) === []));
    afirmar('catalogo: totem_nao_autorizado = API, AVISO, 15 min, SEM id_totem/id_atendimento no contexto', $cat['totem_nao_autorizado']['origem'] === 'API' && $cat['totem_nao_autorizado']['nivel'] === 'AVISO' && $cat['totem_nao_autorizado']['janela'] === 900 && $cat['totem_nao_autorizado']['contexto'] === []);
    afirmar('catalogo: por_tipo em vio_*, erro_banco_pdo, erro_tecnico; fixas conforme o plano', $cat['vio_falha_integracao']['origem'] === 'por_tipo' && $cat['vio_erro_interno']['origem'] === 'por_tipo' && $cat['erro_banco_pdo']['origem'] === 'por_tipo' && $cat['erro_tecnico']['origem'] === 'por_tipo' && $cat['oc_consulta_falhou']['origem'] === 'EXPEDICAO' && $cat['oc_baixa_falhou']['origem'] === 'EXPEDICAO' && $cat['rate_limit_ocr_excedido']['origem'] === 'RECEBIMENTO' && $cat['cron_resumo']['nivel'] === 'INFO' && $cat['cron_falhou']['nivel'] === 'ERRO' && $cat['n8n_anexo_erro']['origem'] === 'API' && $cat['gestao_erro_interno']['origem'] === 'GESTAO');
    $semPii = true;
    foreach (LogCatalogo::DOMINIO as $k => $regra) {
        if (!in_array($k, LogCatalogo::ORDEM_DOMINIO, true)) {
            $semPii = false;
        }
        if ($regra[0] === 'enum') {
            foreach ($regra[1] as $v) {
                if (preg_match('/\A[a-z0-9_]{1,40}\z/', $v) !== 1) {
                    $semPii = false;
                }
            }
        }
    }
    afirmar('catalogo: toda chave de dominio esta na ordem do detalhe e todo valor de enum e token minusculo curto', $semPii);
    $maiorDetalhe = 0;
    foreach ($cat as $nome => $d) {
        $t = 0;
        $ks = $d['contexto'];
        if (in_array('excecao', $ks, true)) {
            $t += strlen('classe=') + 80 + 1 + strlen('sqlstate=XXXXX') + 1;
        }
        if (in_array('http', $ks, true)) {
            $t += strlen('http=599') + 1;
        }
        foreach (LogCatalogo::ORDEM_DOMINIO as $k) {
            if (!in_array($k, $ks, true) && !($k === 'alvo' && $nome === 'log_parametro_invalido') && !($k === 'motivo' && in_array($nome, ['log_parametro_invalido', 'log_suprimido'], true))) {
                continue;
            }
            $regra = LogCatalogo::DOMINIO[$k] ?? ['enum', array_keys($cat)];
            $maior = $regra[0] === 'enum' ? max(array_map('strlen', $regra[1])) : strlen((string) $regra[2]);
            $t += strlen($k) + 1 + $maior + 1;
        }
        $maiorDetalhe = max($maiorDetalhe, $t);
    }
    afirmar("catalogo: o pior detalhe possivel de qualquer categoria cabe em 255 ($maiorDetalhe)", $maiorDetalhe <= 255);

    // =====================================================================
    // 3. Conexao::criarDedicada
    // =====================================================================
    $dedA = Conexao::criarDedicada();
    $dedB = Conexao::criarDedicada();
    afirmar('criarDedicada: conexao propria a cada chamada (nunca a compartilhada)', $dedA !== $dedB && $dedA !== Conexao::obter());
    afirmar('criarDedicada: no banco QA, fuso -03:00, modo excecao e timeout de conexao curto (1-2 s)',
        (string) $dedA->query('SELECT DATABASE()')->fetchColumn() === $banco
        && (string) $dedA->query('SELECT @@session.time_zone')->fetchColumn() === '-03:00'
        && $dedA->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
        && preg_match('/PDO::ATTR_TIMEOUT => [12],/', (string) file_get_contents(dirname(__DIR__, 2) . '/util/Conexao.php')) === 1);
    $dedA = $dedB = null;
    $hostOriginal = $_ENV['DB_HOST'];
    $_ENV['DB_HOST'] = 'db-sentinela.interno.exemplo.invalid';
    $msg = null;
    $prev = 'x';
    try {
        Conexao::criarDedicada();
    } catch (Throwable $e) {
        $msg = get_class($e) . '|' . $e->getMessage();
        $prev = $e->getPrevious();
    }
    $_ENV['DB_HOST'] = $hostOriginal;
    afirmar('criarDedicada: erro de conexao com mensagem fixa, sem host/usuario e sem excecao anterior', $msg === 'PDOException|Nao foi possivel conectar ao banco de dados' && $prev === null);
    $fonteConexao = (string) file_get_contents($raiz . '/util/Conexao.php');
    $corpoDedicada = substr($fonteConexao, (int) strpos($fonteConexao, 'function criarDedicada'));
    $corpoDedicada = substr($corpoDedicada, 0, (int) strpos($corpoDedicada, 'private static function dsn'));
    afirmar('criarDedicada: nao usa getMessage() nem error_log', !str_contains($corpoDedicada, 'getMessage') && !str_contains($corpoDedicada, 'error_log'));

    // =====================================================================
    // 4. Registro basico: colunas, mensagem fixa do catalogo, ordem do detalhe
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 12, 'id_totem' => 3, 'tipo' => 'recebimento', 'excecao' => new LsPdoExc('SQLSTATE[42S02]: Base table or view not found: 1146 Table \'x.y\' doesn\'t exist', '42S02')]);
    afirmar('fila: nada e gravado antes do descarregar (escrita adiada)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 0);
    lsFlush();
    $l = lsLinhas($pdo);
    afirmar('registrar: 1 linha com origem pelo tipo (RECEBIMENTO), nivel e mensagem do catalogo', count($l) === 1 && $l[0]['origem'] === 'RECEBIMENTO' && $l[0]['nivel'] === 'ERRO' && $l[0]['mensagem'] === $cat['erro_banco_pdo']['mensagem'] && $l[0]['categoria'] === 'erro_banco_pdo' && (int) $l[0]['id_atendimento'] === 12 && (int) $l[0]['id_totem'] === 3 && (int) $l[0]['contador'] === 1);
    afirmar('registrar: detalhe = classe (da excecao) e SQLSTATE validados, nada da mensagem', $l[0]['detalhe'] === 'classe=LsPdoExc;sqlstate=42S02' && !str_contains(lsDump($pdo), 'Base table') && !str_contains(lsDump($pdo), 'x.y'));
    afirmar('registrar: janela alinhada ao balde de 5 min e dedup_chave com 40 hex', preg_match('/\A[0-9a-f]{40}\z/', (string) $l[0]['dedup_chave']) === 1 && (int) gtEscalar($pdo, 'SELECT UNIX_TIMESTAMP(janela) % 300 FROM tb_log_sistema') === 0);

    // =====================================================================
    // 5. Sentinelas de PII em excecoes (nunca em nenhuma coluna nem no error_log)
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    lsLogLimpar($arquivoLog);
    $sentinelas = ['529.982.247-25', '52998224725', '11.222.333/0001-81', '11222333000181', 'QWE9R87', 'tok_Zq81SENTINELA00112233445566778899aabbccddeeff', 'db-sentinela.interno.exemplo.test', 'SenhaSentinela!42', 'Fulano Sentinela da Silva', '/var/www/segredo/arquivo.php', 'SELECT * FROM tb_segredo'];
    $mensagemRuim = 'CPF 529.982.247-25 CNPJ 11.222.333/0001-81 placa QWE9R87 token tok_Zq81SENTINELA00112233445566778899aabbccddeeff host db-sentinela.interno.exemplo.test SenhaSentinela!42 Fulano Sentinela da Silva /var/www/segredo/arquivo.php SELECT * FROM tb_segredo';
    $excecoes = [
        new RuntimeException($mensagemRuim, 5, new LogicException($mensagemRuim)),
        new LsPdoExc($mensagemRuim, 'HY000'),
        new LsPdoExc($mensagemRuim, 'SQLSTATE[HY000] [2002] ' . $mensagemRuim),
        new Error($mensagemRuim),
        new class ($mensagemRuim) extends Exception {
        },
    ];
    $categoriasComExcecao = ['oc_consulta_falhou', 'oc_baixa_falhou', 'banco_coletas_indisponivel', 'n8n_anexo_erro', 'talent_erro_reprocessavel', 'vio_falha_integracao', 'erro_banco_pdo', 'erro_tecnico', 'etiqueta_pdf_falhou', 'impressao_config_falhou', 'cron_falhou', 'gestao_erro_interno', 'auditoria_falhou'];
    foreach ($categoriasComExcecao as $c) {
        foreach ($excecoes as $x) {
            LogSistema::registrar($c, ['excecao' => $x]);
        }
        lsFlush();
        lsReset(true);
    }
    $dump = lsDump($pdo);
    $vazou = array_filter($sentinelas, static fn ($s) => str_contains($dump, $s) || str_contains($dump, str_replace('/', '\\/', $s)));
    afirmar('PII: nenhuma sentinela (CPF, CNPJ, placa, token, host, senha, nome, caminho, SQL) em nenhuma coluna apos ' . count($categoriasComExcecao) . ' categorias x ' . count($excecoes) . ' excecoes', $vazou === [] && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') > 0);
    $logTexto = lsLogLer($arquivoLog);
    afirmar('PII: nenhuma sentinela no error_log capturado', array_filter($sentinelas, static fn ($s) => str_contains($logTexto, $s)) === []);
    $detalhes = array_column(lsLinhas($pdo), 'detalhe');
    afirmar('PII: detalhe so tem classe validada e SQLSTATE valido (HY000 sim, texto de mensagem nao, classe anonima omitida)',
        in_array('classe=RuntimeException', $detalhes, true) && in_array('classe=LsPdoExc;sqlstate=HY000', $detalhes, true) && in_array('classe=LsPdoExc', $detalhes, true) && in_array('classe=Error', $detalhes, true)
        && count(array_filter($detalhes, static fn ($d) => $d !== null && preg_match('/\Aclasse=[A-Za-z0-9_\\\\]{1,80}(;sqlstate=[A-Z0-9]{5})?\z/D', $d) !== 1)) === 0);
    afirmar('excecao anonima: nao descarta o evento, so omite a classe (detalhe NULL)', in_array(null, $detalhes, true));
    $nomeLongo = 'LsClasseLonga' . str_repeat('A', 80);
    eval('class ' . $nomeLongo . ' extends Exception {}');
    lsReset();
    lsLimpar($pdo);
    LogSistema::registrar('erro_tecnico', ['excecao' => new $nomeLongo('x')]);
    lsFlush();
    $l = lsLinhas($pdo);
    afirmar('classe com mais de 80 caracteres: omitida do detalhe, evento gravado', count($l) === 1 && $l[0]['detalhe'] === null);

    // PII tentando entrar por contexto: rejeitado (nao grava a categoria)
    lsReset();
    lsLimpar($pdo);
    foreach (['motivo' => '529.982.247-25', 'job' => 'QWE9R87', 'categoria_erro' => 'tok_Zq81'] as $k => $v) {
        LogSistema::registrar($k === 'job' ? 'cron_falhou' : ($k === 'motivo' ? 'erro_tecnico' : 'talent_indeterminado'), [$k => $v]);
    }
    lsFlush();
    $dump = lsDump($pdo);
    afirmar('PII por contexto: valor livre em chave de dominio e descartado (so log_parametro_invalido, sem o valor)', !str_contains($dump, '529.982') && !str_contains($dump, 'QWE9R87') && !str_contains($dump, 'tok_Zq81') && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria <> 'log_parametro_invalido'") === 0);

    // =====================================================================
    // 6. Nunca lanca
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    lsLogLimpar($arquivoLog);
    $lancou = [];
    $entradas = [null, 5, 5.5, true, [], new stdClass(), 'inexistente', "erro_tecnico\0x", str_repeat('a', 100000), 'log_parametro_invalido', 'log_suprimido'];
    foreach ($entradas as $i => $c) {
        try {
            LogSistema::registrar($c);
            if ($c !== []) {
                LogSistema::registrar('erro_tecnico', $c);
            }
        } catch (Throwable $e) {
            $lancou[] = "entrada $i " . get_class($e);
        }
    }
    $ctxRuins = [[1, 2, 3], ['id_atendimento' => [1]], ['id_atendimento' => new stdClass()], ['excecao' => 'str'], ['excecao' => new stdClass()], ['http' => NAN], ['id_totem' => PHP_INT_MAX], ['id_totem' => INF], ['tipo' => ['x']], ['motivo' => fopen('php://memory', 'r')], ['' => 1], ["a\0b" => 1], ['id_atendimento' => '1; DROP TABLE tb_log_sistema']];
    foreach ($ctxRuins as $i => $ctx) {
        try {
            LogSistema::registrar('erro_tecnico', $ctx);
        } catch (Throwable $e) {
            $lancou[] = "ctx $i " . get_class($e);
        }
    }
    try {
        lsFlush();
        lsFlush();
    } catch (Throwable $e) {
        $lancou[] = 'flush ' . get_class($e);
    }
    afirmar('nunca lanca: categoria/ctx de qualquer tipo (inclusive TypeError, NAN, recurso, objeto) so descarta' . ($lancou === [] ? '' : ' ' . implode(',', $lancou)), $lancou === []);
    afirmar('nunca lanca: tabela segue integra (nenhum DROP/SQL injetado) e so ha log_parametro_invalido', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria <> 'log_parametro_invalido'") === 0 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') > 0);

    // tabela renomeada
    lsReset();
    lsLimpar($pdo);
    lsLogLimpar($arquivoLog);
    $pdo->exec('RENAME TABLE tb_log_sistema TO tb_log_sistema_x');
    $lancou = null;
    try {
        LogSistema::registrar('erro_banco_pdo', ['id_totem' => 1, 'excecao' => new RuntimeException($mensagemRuim)]);
        lsFlush();
    } catch (Throwable $e) {
        $lancou = get_class($e);
    } finally {
        $pdo->exec('RENAME TABLE tb_log_sistema_x TO tb_log_sistema');
    }
    $logTexto = lsLogLer($arquivoLog);
    afirmar('tabela ausente: nao lanca e grava 1 error_log fixo "LogSistema: gravacao falhou categoria=erro_banco_pdo classe=PDOException"', $lancou === null && trim($logTexto) === 'LogSistema: gravacao falhou categoria=erro_banco_pdo classe=PDOException');
    afirmar('tabela ausente: error_log sem SQL, sem nome de tabela, sem sentinela', !str_contains($logTexto, 'tb_log') && !str_contains($logTexto, 'SQLSTATE') && array_filter($sentinelas, static fn ($s) => str_contains($logTexto, $s)) === []);
    afirmar('tabela ausente: flag bancoIndisponivel ligada (nao insiste na requisicao)', lsEstatico('bancoIndisponivel') === true);
    lsEstatico('bancoIndisponivel', false, false);

    // conexao invalida (porta fechada) e DB_NAME inexistente
    lsReset();
    lsLogLimpar($arquivoLog);
    $portaOriginal = $_ENV['DB_PORT'];
    $_ENV['DB_PORT'] = '1';
    $lancou = null;
    $t0 = microtime(true);
    try {
        LogSistema::registrar('oc_consulta_falhou', ['id_totem' => 1]);
        lsFlush();
    } catch (Throwable $e) {
        $lancou = get_class($e);
    }
    $demora = microtime(true) - $t0;
    $_ENV['DB_PORT'] = $portaOriginal;
    $logTexto = lsLogLer($arquivoLog);
    afirmar('conexao invalida: nao lanca, demora pouco (timeout curto) e loga so categoria e classe', $lancou === null && $demora < 6 && trim($logTexto) === 'LogSistema: gravacao falhou categoria=oc_consulta_falhou classe=PDOException');
    lsReset();
    lsLogLimpar($arquivoLog);
    $nomeOriginal = $_ENV['DB_NAME'];
    unset($_ENV['DB_NAME']);
    LogSistema::registrar('oc_consulta_falhou', ['id_totem' => 1]);
    lsFlush();
    $_ENV['DB_NAME'] = $nomeOriginal;
    afirmar('configuracao de banco ausente: nao lanca e cai no error_log fixo', trim(lsLogLer($arquivoLog)) === 'LogSistema: gravacao falhou categoria=oc_consulta_falhou classe=PDOException');
    $_ENV['DB_NAME'] = $nomeOriginal;

    // limite de taxa do fallback: 1 por categoria por minuto
    lsReset();
    lsLogLimpar($arquivoLog);
    $_ENV['DB_PORT'] = '1';
    for ($i = 0; $i < 4; $i++) {
        LogSistema::registrar('oc_baixa_falhou', ['id_totem' => 1]);
        lsFlush();
        lsEstatico('bancoIndisponivel', false, false);
    }
    LogSistema::registrar('talent_indeterminado', ['id_totem' => 1]);
    lsFlush();
    $_ENV['DB_PORT'] = $portaOriginal;
    $linhasLog = array_values(array_filter(explode("\n", lsLogLer($arquivoLog))));
    afirmar('fallback: 1 linha por categoria por minuto (4 falhas da mesma categoria = 1 linha, outra categoria = outra linha)', count($linhasLog) === 2 && str_contains($linhasLog[0], 'categoria=oc_baixa_falhou') && str_contains($linhasLog[1], 'categoria=talent_indeterminado'));

    // banco indisponivel: uma tentativa so por requisicao
    lsReset();
    lsLogLimpar($arquivoLog);
    lsEstatico('bancoIndisponivel', true, false);
    LogSistema::registrar('erro_tecnico', ['id_totem' => 1]);
    lsFlush();
    afirmar('flag bancoIndisponivel: nao tenta conectar (nada gravado, so error_log)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 0 && str_contains(lsLogLer($arquivoLog), 'categoria=erro_tecnico'));

    // =====================================================================
    // 7. Sem recursao
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    lsEstatico('ocupado', true, false);
    LogSistema::registrar('erro_tecnico', ['id_totem' => 9]);
    $filaDentro = lsEstatico('fila');
    lsFlush();
    afirmar('reentrancia: registrar chamado de dentro do proprio log e ignorado (nada na fila, nada gravado)', $filaDentro === [] && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 0);
    lsReset();
    LogSistema::registrar('erro_tecnico', ['id_totem' => 9]);
    lsEstatico('ocupado', true, false);
    lsFlush();
    afirmar('reentrancia: descarregar chamado de dentro do proprio log e ignorado (fila intacta)', count(lsEstatico('fila')) === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 0);
    lsReset();
    // tratador de erro que chama o log (como um E_WARNING no meio da gravacao). O driver nao emite
    // aviso, entao a situacao "log ocupado" e simulada ligando o flag e disparando o aviso.
    $chamadasHandler = 0;
    set_error_handler(static function (int $n, string $s) use (&$chamadasHandler): bool {
        $chamadasHandler++;
        LogSistema::registrar('gestao_erro_interno', []);
        LogSistema::descarregar();

        return true;
    });
    LogSistema::registrar('erro_tecnico', ['id_totem' => 9]);
    // aviso disparado enquanto o log esta ocupado (como um E_WARNING no meio da gravacao)
    lsEstatico('ocupado', true, false);
    trigger_error('qa', E_USER_WARNING);
    lsEstatico('ocupado', false, false);
    restore_error_handler();
    afirmar('reentrancia: tratador de erro que chama o log durante a gravacao nao enfileira nem grava (1 chamada, fila com 1 item)', $chamadasHandler === 1 && count(lsEstatico('fila')) === 1);
    lsReset();
    afirmar('reentrancia: ocupado sempre volta a false apos registrar e descarregar (mesmo com excecao interna)', (static function (): bool {
        LogSistema::registrar(['x']);
        LogSistema::descarregar();

        return lsEstatico('ocupado') === false;
    })());

    // =====================================================================
    // 8. Deduplicacao
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    for ($i = 0; $i < 7; $i++) {
        LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    }
    afirmar('dedup: 7 chamadas iguais = 1 item na fila (soma na memoria)', count(lsEstatico('fila')) === 1);
    lsFlush();
    $l = lsLinhas($pdo);
    afirmar('dedup: 7 chamadas = 1 linha com contador 7', count($l) === 1 && (int) $l[0]['contador'] === 7);
    $criado = (string) $l[0]['criado_em'];
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    lsFlush();
    $l = lsLinhas($pdo);
    afirmar('dedup: nova descarga na mesma janela soma no banco (UPSERT atomico): ainda 1 linha, contador 9', count($l) === 1 && (int) $l[0]['contador'] === 9 && (string) $l[0]['criado_em'] === $criado);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 2, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 2, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento', 'http' => 500]);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento', 'excecao' => new RuntimeException('a')]);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'expedicao']);
    lsFlush();
    afirmar('dedup: outro totem, outro atendimento, outro codigo (http/classe) ou outra origem = outra linha (6 no total)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 6);
    // nova janela = nova linha (entrada com o balde anterior injetada na fila)
    lsReset();
    lsLimpar($pdo);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    $fila = lsEstatico('fila');
    $item = array_values($fila)[0];
    $antiga = $item;
    $antiga['janela'] = $item['janela'] - 300;
    $antiga['ocorrencias'] = 3;
    lsEstatico('fila', $fila + ['janela-anterior' => $antiga], false);
    lsFlush();
    $l = lsLinhas($pdo);
    afirmar('dedup: mesma chave em outra janela = outra linha (UNIQUE inclui a janela)', count($l) === 2 && $l[0]['dedup_chave'] === $l[1]['dedup_chave'] && $l[0]['janela'] !== $l[1]['janela'] && [(int) $l[0]['contador'], (int) $l[1]['contador']] === [1, 3]);
    // DAO: contador com teto
    $dao = new LogSistemaDao($pdo);
    $pdo->exec('UPDATE tb_log_sistema SET contador = 4294967290 WHERE contador = 1');
    $dao->registrarOuIncrementar(['nivel' => 'ERRO', 'origem' => 'RECEBIMENTO', 'categoria' => 'erro_tecnico', 'mensagem' => 'x', 'id_atendimento' => 1, 'id_totem' => 1, 'detalhe' => null, 'dedup_chave' => (string) $l[0]['dedup_chave'], 'janela' => (int) gtEscalar($pdo, 'SELECT UNIX_TIMESTAMP(janela) FROM tb_log_sistema WHERE contador = 4294967290')], 100);
    afirmar('dedup: contador satura em 4294967295 (sem erro de overflow)', (int) gtEscalar($pdo, 'SELECT MAX(contador) FROM tb_log_sistema') === 4294967295);

    // 401: anonimo, sem id_totem/id_atendimento
    lsReset();
    lsLimpar($pdo);
    for ($i = 0; $i < 50; $i++) {
        LogSistema::registrar('totem_nao_autorizado');
    }
    LogSistema::registrar('totem_nao_autorizado', ['id_totem' => 7]);
    LogSistema::registrar('totem_nao_autorizado', ['id_atendimento' => 7]);
    LogSistema::registrar('totem_nao_autorizado', ['excecao' => new RuntimeException('x')]);
    lsFlush();
    lsReset();
    for ($i = 0; $i < 20; $i++) {
        LogSistema::registrar('totem_nao_autorizado', []);
        if ($i % 5 === 0) {
            lsFlush();
        }
    }
    lsFlush();
    $l = lsLinhas($pdo, "categoria = 'totem_nao_autorizado'");
    afirmar('401: 70 chamadas anonimas = 1 linha por janela (contador 70), sem id_totem/id_atendimento, AVISO/API, janela de 15 min', count($l) === 1 && (int) $l[0]['contador'] === 70 && $l[0]['id_totem'] === null && $l[0]['id_atendimento'] === null && $l[0]['nivel'] === 'AVISO' && $l[0]['origem'] === 'API' && (int) gtEscalar($pdo, 'SELECT UNIX_TIMESTAMP(janela) % 900 FROM tb_log_sistema WHERE categoria = \'totem_nao_autorizado\'') === 0);
    afirmar('401: tentativa de passar id_totem/id_atendimento/excecao nessa categoria e descartada (nao grava com id)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'totem_nao_autorizado' AND (id_totem IS NOT NULL OR id_atendimento IS NOT NULL)") === 0 && (int) gtEscalar($pdo, "SELECT COALESCE(SUM(contador),0) FROM tb_log_sistema WHERE categoria = 'log_parametro_invalido'") === 3);

    // =====================================================================
    // 9. Concorrencia real (processos paralelos): contador exato
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    $faltam = 300 - (time() % 300);
    if ($faltam < 25) {
        sleep($faltam + 1); // nao largar perto da virada do balde
    }
    $trabalhadores = 6;
    $rodadas = 5;
    $porRodada = 5;
    $t0 = microtime(true) + 3.0;
    $procs = [];
    for ($w = 0; $w < $trabalhadores; $w++) {
        $job = base64_encode((string) json_encode(['t0' => $t0, 'rodadas' => $rodadas, 'por_rodada' => $porRodada]));
        $p = proc_open([PHP_BINARY, __FILE__, '--worker', $job], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $saidas = [];
    foreach ($procs as [$p, $pipes]) {
        $saidas[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }
    $l = lsLinhas($pdo);
    $esperado = $trabalhadores * $rodadas * $porRodada;
    afirmar("concorrencia: $trabalhadores processos x " . ($rodadas * $porRodada) . " chamadas = 1 linha com contador exato $esperado (sem perda, sem duplicata)", $saidas === array_fill(0, $trabalhadores, 'ok') && count($l) === 1 && (int) $l[0]['contador'] === $esperado);

    // =====================================================================
    // 10. Tetos
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    for ($i = 1; $i <= 25; $i++) {
        LogSistema::registrar('erro_tecnico', ['id_atendimento' => $i, 'id_totem' => 1, 'tipo' => 'recebimento']);
    }
    lsFlush();
    afirmar('teto por requisicao: 25 eventos distintos = no maximo 10 linhas', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 10 && LogSistema::TETO_POR_REQUISICAO === 10);
    afirmar('teto por requisicao: repeticao do mesmo evento nao conta como evento novo (soma no contador)', (static function () use ($pdo): bool {
        lsReset();
        lsLimpar($pdo);
        for ($i = 0; $i < 100; $i++) {
            LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
        }
        lsFlush();

        return (int) gtEscalar($pdo, 'SELECT contador FROM tb_log_sistema') === 100;
    })());

    // evento ja agrupado nao paga COUNT (so linha nova confere os tetos)
    lsReset();
    lsLimpar($pdo);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistemaDao::$consultasDeTeto = 0;
    lsFlush();
    afirmar('sem COUNT (a): linha nova confere os tetos (3 contagens: diaria, total e da categoria)', LogSistemaDao::$consultasDeTeto === 3 && (int) lsLinhas($pdo, "categoria = 'erro_tecnico'")[0]['contador'] === 1);
    lsReset();
    LogSistemaDao::$consultasDeTeto = 0;
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    lsFlush();
    afirmar('sem COUNT (b): evento ja existente (mesma dedup_chave+janela) soma sem NENHUM COUNT', LogSistemaDao::$consultasDeTeto === 0 && (int) lsLinhas($pdo, "categoria = 'erro_tecnico'")[0]['contador'] === 3);
    lsReset();
    LogSistemaDao::$consultasDeTeto = 0;
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 2, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 3, 'id_totem' => 1, 'tipo' => 'recebimento']);
    lsFlush();
    afirmar('sem COUNT (c): existente + 2 novas na mesma descarga = 3 contagens no total (diaria, total e da categoria, 1x por descarga) e 3 linhas', LogSistemaDao::$consultasDeTeto === 3 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'erro_tecnico'") === 3 && (int) lsLinhas($pdo, "categoria = 'erro_tecnico' AND id_atendimento = 1")[0]['contador'] === 4);
    lsLimpar($pdo);

    // teto diario
    lsReset();
    lsLimpar($pdo);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    lsFlush();
    $preencher = static function (PDO $pdo, int $n, string $criadoEm, string $prefixo): void {
        for ($ini = 0; $ini < $n; $ini += 1000) {
            $vals = [];
            $params = [];
            for ($i = $ini; $i < min($n, $ini + 1000); $i++) {
                $vals[] = "('INFO','CRON','cron_resumo','x',NULL,NULL,NULL,?,NOW(),1,?,?)";
                $params[] = sha1($prefixo . $i);
                $params[] = $criadoEm;
                $params[] = $criadoEm;
            }
            $pdo->prepare('INSERT INTO tb_log_sistema (nivel,origem,categoria,mensagem,id_atendimento,id_totem,detalhe,dedup_chave,janela,contador,criado_em,ultima_ocorrencia) VALUES ' . implode(',', $vals))->execute($params);
        }
    };
    $hoje = (string) gtEscalar($pdo, 'SELECT NOW()');
    $preencher($pdo, LogSistema::TETO_DIARIO - 1, $hoje, 'dia');
    afirmar('teto diario: preparado com exatamente ' . LogSistema::TETO_DIARIO . ' linhas criadas hoje', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em >= CURDATE()') === LogSistema::TETO_DIARIO);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 99, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 99, 'id_totem' => 1, 'tipo' => 'recebimento']);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 98, 'id_totem' => 1, 'tipo' => 'recebimento']);
    lsFlush();
    afirmar('teto diario: evento ja existente continua somando (contador 2)', (int) gtEscalar($pdo, "SELECT contador FROM tb_log_sistema WHERE categoria = 'erro_tecnico' AND id_atendimento = 1") === 2);
    afirmar('teto diario: eventos novos NAO criam linha', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'erro_tecnico' AND id_atendimento IN (98, 99)") === 0);
    $s = lsLinhas($pdo, "categoria = 'log_suprimido'");
    afirmar('teto diario: incrementa log_suprimido (1 linha, motivo=teto_diario, contador 3 = 3 ocorrencias suprimidas)', count($s) === 1 && $s[0]['detalhe'] === 'motivo=teto_diario' && (int) $s[0]['contador'] === 3 && $s[0]['origem'] === 'API' && $s[0]['nivel'] === 'AVISO');
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 97, 'id_totem' => 1, 'tipo' => 'recebimento']);
    lsFlush();
    $s = lsLinhas($pdo, "categoria = 'log_suprimido'");
    afirmar('teto diario: nova supressao so soma no log_suprimido (continua 1 linha, contador 4)', count($s) === 1 && (int) $s[0]['contador'] === 4);

    // teto diario POR CATEGORIA
    lsReset();
    lsLimpar($pdo);
    for ($i = 1; $i <= LogSistema::TETO_DIARIO_POR_CATEGORIA + 20; $i++) {
        LogSistema::registrar('vio_erro_interno', ['id_atendimento' => $i, 'id_totem' => 1, 'tipo' => 'expedicao']);
        if ($i % 10 === 0) {
            lsFlush();
        }
    }
    lsFlush();
    afirmar('teto por categoria: vio_erro_interno com ids distintos para em ' . LogSistema::TETO_DIARIO_POR_CATEGORIA . ' linhas', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'vio_erro_interno'") === LogSistema::TETO_DIARIO_POR_CATEGORIA);
    $s = lsLinhas($pdo, "categoria = 'log_suprimido'");
    afirmar('teto por categoria: excedentes contam em log_suprimido motivo=teto_categoria (20)', count($s) === 1 && $s[0]['detalhe'] === 'motivo=teto_categoria' && (int) $s[0]['contador'] === 20);
    LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'expedicao']);
    LogSistema::registrar('vio_erro_interno', ['id_atendimento' => 1, 'id_totem' => 1, 'tipo' => 'expedicao']);
    lsFlush();
    afirmar('teto por categoria: erro_banco_pdo (outra categoria) ainda grava; linha ja existente de vio_erro_interno ainda soma', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'erro_banco_pdo'") === 1 && (int) gtEscalar($pdo, "SELECT contador FROM tb_log_sistema WHERE categoria = 'vio_erro_interno' AND id_atendimento = 1") === 2);
    lsLimpar($pdo);

    // teto total
    lsReset();
    lsLimpar($pdo);
    $velho = (string) gtEscalar($pdo, 'SELECT NOW() - INTERVAL 5 DAY');
    $preencher($pdo, LogSistema::TETO_TOTAL, $velho, 'total');
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => 5, 'id_totem' => 1, 'tipo' => 'recebimento']);
    lsFlush();
    $s = lsLinhas($pdo, "categoria = 'log_suprimido'");
    afirmar('teto total (' . LogSistema::TETO_TOTAL . '): nao cria linha nova e conta em log_suprimido motivo=teto_total', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'erro_tecnico'") === 0 && count($s) === 1 && $s[0]['detalhe'] === 'motivo=teto_total' && (int) $s[0]['contador'] === 1);
    lsLimpar($pdo);

    // =====================================================================
    // 11. Allowlist: rejeita sem gravar
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    $rejeitados = [
        ['categoria_inexistente', []],
        ['erro_tecnico', ['mensagem' => 'SENTINELA_MSG']],
        ['erro_tecnico', ['ip' => '203.0.113.9']],
        ['erro_tecnico', ['trace' => 'x']],
        ['erro_tecnico', ['arquivo' => 'a.php']],
        ['erro_tecnico', ['linha' => 10]],
        ['erro_tecnico', ['token' => 'tok_x']],
        ['erro_tecnico', ['cpf' => '52998224725']],
        ['erro_tecnico', ['tipo' => 'outro']],
        ['erro_tecnico', ['tipo' => 'RECEBIMENTO']],
        ['erro_tecnico', ['http' => 99]],
        ['erro_tecnico', ['http' => 600]],
        ['erro_tecnico', ['http' => '500']],
        ['erro_tecnico', ['motivo' => 'texto livre com espaco']],
        ['erro_tecnico', ['categoria_erro' => 'erro_servidor']],
        ['cron_resumo', ['lotes' => -1]],
        ['cron_resumo', ['lotes' => 100000]],
        ['cron_resumo', ['lotes' => '5']],
        ['cron_falhou', ['job' => 'outro_job']],
        ['talent_erro_reprocessavel', ['categoria_erro' => 'inventada']],
        ['log_suprimido', []],
        ['log_parametro_invalido', []],
    ];
    foreach ($rejeitados as [$c, $x]) {
        LogSistema::registrar($c, $x);
        lsFlush();
        lsReset(true);
    }
    $dump = lsDump($pdo);
    $nTotal = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema');
    afirmar('allowlist: ' . count($rejeitados) . ' chamadas invalidas nao gravam nenhuma categoria de negocio (so log_parametro_invalido)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria NOT IN ('log_parametro_invalido')") === 0 && $nTotal > 0);
    afirmar('allowlist: nenhum valor rejeitado aparece nas colunas', !str_contains($dump, 'SENTINELA_MSG') && !str_contains($dump, '203.0.113.9') && !str_contains($dump, 'tok_x') && !str_contains($dump, '52998224725') && !str_contains($dump, 'texto livre') && !str_contains($dump, 'categoria_inexistente') && !str_contains($dump, 'inventada'));
    $conta = (int) gtEscalar($pdo, "SELECT SUM(contador) FROM tb_log_sistema WHERE categoria = 'log_parametro_invalido'");
    afirmar("allowlist: toda chamada invalida conta em log_parametro_invalido ($conta de " . count($rejeitados) . ')', $conta === count($rejeitados));
    $mot = array_column(gtLinhas($pdo, "SELECT detalhe FROM tb_log_sistema WHERE categoria = 'log_parametro_invalido'"), 'detalhe');
    afirmar('allowlist: detalhe do parametro invalido so tem motivo de catalogo e nome de categoria do catalogo', count(array_filter($mot, static fn ($d) => preg_match('/\A(alvo=[a-z0-9_]{1,40};)?motivo=(categoria_desconhecida|chave_desconhecida|valor_invalido|categoria_interna)\z/D', (string) $d) !== 1)) === 0);
    afirmar('allowlist: categoria desconhecida nao grava o nome informado (sem alvo)', in_array('motivo=categoria_desconhecida', $mot, true) && in_array('motivo=categoria_interna', array_map(static fn ($d) => preg_replace('/\Aalvo=[a-z0-9_]+;/', '', (string) $d), $mot), true));

    // ids invalidos
    lsReset();
    lsLimpar($pdo);
    $idsRuins = [0, -1, '5', 5.0, 1.5, true, null, [], PHP_INT_MIN, 4294967296];
    foreach ($idsRuins as $v) {
        LogSistema::registrar('erro_tecnico', ['id_totem' => $v]);
        LogSistema::registrar('erro_tecnico', ['id_atendimento' => $v]);
        lsFlush();
        lsReset(true);
    }
    // 4294967296 e valido como id_atendimento (BIGINT), invalido como id_totem
    $validosAtendimento = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'erro_tecnico'");
    afirmar('ids invalidos (0, negativo, string, float, bool, null, array, acima do INT UNSIGNED): so 4294967296 vale e so como id_atendimento (' . $validosAtendimento . ' linha)', $validosAtendimento === 1 && (int) gtEscalar($pdo, "SELECT id_atendimento FROM tb_log_sistema WHERE categoria = 'erro_tecnico'") === 4294967296);
    lsReset();
    lsLimpar($pdo);
    LogSistema::registrar('erro_tecnico', ['id_totem' => 4294967295, 'id_atendimento' => 9223372036854775807]);
    lsFlush();
    afirmar('ids no limite superior do tipo da coluna sao gravados sem erro', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE id_totem = 4294967295 AND id_atendimento = 9223372036854775807") === 1);

    // =====================================================================
    // 12. Origem (aba)
    // =====================================================================
    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api) VALUES ('QA-LOG-1', 'QA Log', '" . str_repeat('a', 64) . "')");
    $idTotemQa = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo) VALUES ('" . str_repeat('1', 36) . "', $idTotemQa, 'expedicao')");
    $idAtExp = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo) VALUES ('" . str_repeat('2', 36) . "', $idTotemQa, 'recebimento')");
    $idAtRec = (int) $pdo->lastInsertId();
    lsReset();
    lsLimpar($pdo);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => $idAtExp, 'id_totem' => $idTotemQa]);
    LogSistema::registrar('erro_tecnico', ['id_atendimento' => $idAtRec, 'id_totem' => $idTotemQa]);
    LogSistema::registrar('erro_banco_pdo', ['tipo' => 'expedicao']);
    LogSistema::registrar('erro_banco_pdo', ['tipo' => 'recebimento', 'id_atendimento' => $idAtExp]);
    LogSistema::registrar('vio_erro_interno', []);
    LogSistema::registrar('vio_falha_integracao', ['id_atendimento' => 987654]);
    lsFlush();
    $o = array_column(gtLinhas($pdo, 'SELECT categoria, id_atendimento, origem FROM tb_log_sistema ORDER BY id_log'), 'origem');
    afirmar('aba por_tipo: SELECT por PK do atendimento no flush (expedicao/recebimento), tipo do contexto tem prioridade, sem atendimento nem tipo = API, atendimento inexistente = API', $o === ['EXPEDICAO', 'RECEBIMENTO', 'EXPEDICAO', 'RECEBIMENTO', 'API', 'API']);
    lsReset();
    lsLimpar($pdo);
    foreach (['oc_consulta_falhou' => 'EXPEDICAO', 'oc_baixa_falhou' => 'EXPEDICAO', 'rate_limit_ocr_excedido' => 'RECEBIMENTO', 'cron_resumo' => 'CRON', 'cron_falhou' => 'CRON', 'gestao_erro_interno' => 'GESTAO', 'auditoria_falhou' => 'GESTAO', 'n8n_anexo_erro' => 'API', 'n8n_anexo_recusado' => 'API', 'totem_nao_autorizado' => 'API'] as $c => $esperada) {
        LogSistema::registrar($c, []);
    }
    lsFlush();
    $obtidas = array_column(gtLinhas($pdo, 'SELECT categoria, origem FROM tb_log_sistema'), 'origem', 'categoria');
    afirmar('aba fixa: gravada na origem do catalogo (Expedicao, Recebimento, Cron, Gestao, API)', $obtidas === ['oc_consulta_falhou' => 'EXPEDICAO', 'oc_baixa_falhou' => 'EXPEDICAO', 'rate_limit_ocr_excedido' => 'RECEBIMENTO', 'cron_resumo' => 'CRON', 'cron_falhou' => 'CRON', 'gestao_erro_interno' => 'GESTAO', 'auditoria_falhou' => 'GESTAO', 'n8n_anexo_erro' => 'API', 'n8n_anexo_recusado' => 'API', 'totem_nao_autorizado' => 'API']);
    lsReset();
    lsLimpar($pdo);
    LogSistema::registrar('cron_resumo', ['job' => 'limpar_logs_gestao', 'logs_apagados' => 1200, 'auditoria_apagados' => 34, 'lotes' => 3]);
    LogSistema::registrar('talent_erro_reprocessavel', ['tipo' => 'recebimento', 'categoria_erro' => 'erro_servidor', 'http' => 502, 'id_totem' => 1]);
    LogSistema::registrar('vio_falha_integracao', ['tipo' => 'expedicao', 'documento' => 'crlv', 'motivo' => 'timeout']);
    lsFlush();
    $dets = array_column(gtLinhas($pdo, 'SELECT categoria, detalhe FROM tb_log_sistema'), 'detalhe', 'categoria');
    afirmar('detalhe: chave=valor em ordem fixa (job, contagens; http antes do dominio; documento antes de motivo nao se aplica)', $dets['cron_resumo'] === 'job=limpar_logs_gestao;logs_apagados=1200;auditoria_apagados=34;lotes=3' && $dets['talent_erro_reprocessavel'] === 'http=502;categoria_erro=erro_servidor' && $dets['vio_falha_integracao'] === 'motivo=timeout;documento=crlv');

    // =====================================================================
    // 13. Rollback do chamador nao apaga o log (conexao propria)
    // =====================================================================
    lsReset();
    lsLimpar($pdo);
    $pdo->exec('DELETE FROM tb_gestao_auditoria');
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO tb_gestao_auditoria (acao, resultado) VALUES ('LOGIN_OK', 'OK')");
    $pdo->exec("UPDATE tb_atendimento SET etapa_atual = 'x' WHERE id_atendimento = $idAtRec");
    LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => $idAtRec, 'id_totem' => $idTotemQa, 'excecao' => new PDOException('x')]);
    $t0 = microtime(true);
    lsFlush(); // le tb_atendimento por PK enquanto o chamador segura lock de escrita na linha
    $dur = microtime(true) - $t0;
    $pdo->rollBack();
    afirmar('transacao do chamador: log gravado por conexao propria sobrevive ao ROLLBACK (e a auditoria do chamador foi desfeita)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria') === 0);
    afirmar('transacao do chamador: a gravacao nao espera o lock da transacao aberta (leitura sem bloqueio, ' . round($dur, 2) . ' s)', $dur < 1.5 && (string) gtEscalar($pdo, 'SELECT origem FROM tb_log_sistema') === 'RECEBIMENTO');

    // =====================================================================
    // 14. LogSistemaDao leitura (F3c)
    // =====================================================================
    lsLimpar($pdo);
    $dao = new LogSistemaDao($pdo);
    $semear = static function (PDO $pdo, string $origem, string $nivel, string $categoria, int $i, ?int $totem, ?int $at, string $quando): void {
        $pdo->prepare('INSERT INTO tb_log_sistema (nivel,origem,categoria,mensagem,id_atendimento,id_totem,detalhe,dedup_chave,janela,contador,criado_em,ultima_ocorrencia) VALUES (?,?,?,?,?,?,NULL,?,?,?,?,?)')
            ->execute([$nivel, $origem, $categoria, 'm', $at, $totem, sha1("r$i"), $quando, $i, $quando, $quando]);
    };
    for ($i = 1; $i <= 30; $i++) {
        $semear($pdo, $i <= 12 ? 'EXPEDICAO' : ($i <= 20 ? 'RECEBIMENTO' : ($i <= 28 ? 'API' : 'CRON')), $i % 3 === 0 ? 'ERRO' : 'AVISO', $i <= 12 ? 'oc_consulta_falhou' : ($i <= 20 ? 'erro_tecnico' : 'n8n_anexo_erro'), $i, $i % 2 === 0 ? 5 : null, $i <= 12 ? 40 : null, sprintf('2026-10-%02d 10:00:00', $i % 28 + 1));
    }
    $p1 = $dao->listar(['origem' => 'EXPEDICAO'], 1, 5);
    $p3 = $dao->listar(['origem' => 'EXPEDICAO'], 3, 5);
    afirmar('listar: paginacao por aba (12 linhas, 5 por pagina: 5 + 5 + 2) e total correto', $p1['total'] === 12 && count($p1['itens']) === 5 && count($p3['itens']) === 2 && $p1['pagina'] === 1 && $p3['pagina'] === 3);
    $ord = $dao->listar(['origem' => 'EXPEDICAO'], 1, 50, 'contador', 'ASC');
    $contadores = array_map('intval', array_column($ord['itens'], 'contador'));
    $sorted = $contadores;
    sort($sorted);
    afirmar('listar: ordenacao pela whitelist (contador ASC)', $contadores === $sorted && count($contadores) === 12);
    afirmar('listar: filtros combinados (nivel, categoria, totem, atendimento, datas)', $dao->listar(['origem' => 'EXPEDICAO', 'nivel' => 'ERRO'], 1, 50)['total'] === 4 && $dao->listar(['categoria' => 'erro_tecnico'], 1, 50)['total'] === 8 && $dao->listar(['id_totem' => 5], 1, 50)['total'] === 15 && $dao->listar(['id_atendimento' => 40], 1, 50)['total'] === 12 && $dao->listar(['de' => '2026-10-10', 'ate' => '2026-10-10'], 1, 50)['total'] === (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE DATE(ultima_ocorrencia) = '2026-10-10'"));
    $abas = $dao->contarPorAba();
    afirmar('contarPorAba: total por aba, todas as abas presentes (inclusive as vazias)', $abas === ['API' => 8, 'RECEBIMENTO' => 8, 'EXPEDICAO' => 12, 'CRON' => 2, 'GESTAO' => 0]);
    afirmar('categoriasDaAba: so as categorias presentes na aba', $dao->categoriasDaAba('API') === ['n8n_anexo_erro'] && $dao->categoriasDaAba('GESTAO') === [] && $dao->categoriasDaAba('EXPEDICAO') === ['oc_consulta_falhou']);
    $recusas = 0;
    foreach ([
        fn () => $dao->listar(['senha' => 'x']),
        fn () => $dao->listar(['origem' => "API' OR '1'='1"]),
        fn () => $dao->listar(['nivel' => 'CRITICO']),
        fn () => $dao->listar(['categoria' => 'x; DROP TABLE tb_log_sistema']),
        fn () => $dao->listar(['id_totem' => '5 OR 1=1']),
        fn () => $dao->listar(['de' => '2026-13-45']),
        fn () => $dao->listar(['de' => "2026-10-01' OR '1'='1"]),
        fn () => $dao->listar([], 1, 50, 'contador; DROP TABLE tb_log_sistema'),
        fn () => $dao->listar([], 1, 50, 'ultima_ocorrencia', 'DESC, id_log'),
        fn () => $dao->categoriasDaAba("API'"),
    ] as $f) {
        try {
            $f();
        } catch (InvalidArgumentException $e) {
            $recusas++;
        }
    }
    afirmar('listar: filtro, ordenacao, direcao e aba fora da whitelist/injecao => InvalidArgumentException (10 de 10) e a tabela segue intacta', $recusas === 10 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 30);
    afirmar('listar: por_pagina limitado a 200 e pagina minima 1', $dao->listar([], 0, 100000)['por_pagina'] === 200 && $dao->listar([], -5, 10)['pagina'] === 1);
    $fonteDao = (string) file_get_contents($raiz . '/app/Dao/LogSistemaDao.php');
    afirmar('LogSistemaDao: LIMIT/OFFSET por PARAM_INT e nenhuma interpolacao de entrada no SQL', str_contains($fonteDao, 'LIMIT :limite OFFSET :deslocamento') && str_contains($fonteDao, "bindValue('limite', \$porPagina, PDO::PARAM_INT)") && str_contains($fonteDao, "bindValue('deslocamento'"));
    lsLimpar($pdo);

    // =====================================================================
    // 15. Estaticos
    // =====================================================================
    $fonteLog = (string) file_get_contents($raiz . '/util/LogSistema.php');
    afirmar('estatico: util/Conexao.php e util/Bootstrap.php nao referenciam LogSistema', stripos((string) file_get_contents($raiz . '/util/Conexao.php'), 'LogSistema') === false && stripos((string) file_get_contents($raiz . '/util/Bootstrap.php'), 'LogSistema') === false);
    $violacoes = [];
    $chamadas = 0;
    foreach (['app', 'util', 'public', 'cron', 'tools'] as $pasta) {
        if (!is_dir($raiz . '/' . $pasta)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $pasta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php' || str_contains($f->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $c = (string) file_get_contents($f->getPathname());
            if (preg_match_all('/LogSistema::registrar\((.*?)\);/s', $c, $m)) {
                foreach ($m[1] as $args) {
                    $chamadas++;
                    if (preg_match('/getMessage|getTrace|getFile|getLine|__toString|\(string\)\s*\$e|\$_SERVER|REMOTE_ADDR|HTTP_|Authorization|token_api/i', $args) === 1) {
                        $violacoes[] = basename($f->getPathname()) . ': ' . trim(preg_replace('/\s+/', ' ', $args));
                    }
                }
            }
        }
    }
    afirmar("estatico: nenhum LogSistema::registrar( ($chamadas chamadas) passa getMessage/getTrace/arquivo/linha/IP/cabecalho/token" . ($violacoes === [] ? '' : ' ' . implode(' | ', $violacoes)), $violacoes === [] && $chamadas > 0);
    afirmar('estatico: LogSistema nao usa getMessage/getTrace/getFile/getLine nem AuditoriaDao', preg_match('/getMessage|getTrace|getFile|getLine|AuditoriaDao/', preg_replace('#/\*.*?\*/#s', '', $fonteLog)) !== 1);
    $corpoFallback = substr($fonteLog, (int) strpos($fonteLog, 'private static function fallback'));
    afirmar('estatico: o caminho de falha (fallback) nao chama registrar, descarregar, banco nem auditoria', !str_contains($corpoFallback, 'registrar(') && !str_contains($corpoFallback, 'descarregar(') && !str_contains($corpoFallback, 'LogSistemaDao') && !str_contains($corpoFallback, 'Conexao') && substr_count($corpoFallback, 'error_log(') === 1);
    afirmar('estatico: guard de reentrancia em registrar e em descarregar', substr_count($fonteLog, 'if (self::$ocupado)') === 2);
    afirmar('estatico: registrar aceita qualquer tipo (sem TypeError) e a escrita usa so a conexao dedicada', str_contains($fonteLog, 'public static function registrar($categoria, $ctx = []): void') && str_contains($fonteLog, 'Conexao::criarDedicada()') && !str_contains($fonteLog, 'Conexao::obter'));
    afirmar('estatico: UPSERT atomico com teto do contador no DAO', str_contains($fonteDao, 'ON DUPLICATE KEY UPDATE') && str_contains($fonteDao, 'LEAST(contador + :contador_soma, 4294967295)') && str_contains($fonteDao, 'ultima_ocorrencia = NOW()'));
    $migracao = (string) file_get_contents($raiz . '/sql/migrations/023_log_sistema.sql');
    $schema = (string) file_get_contents($raiz . '/sql/schema.sql');
    $bloco = static function (string $sql): string {
        preg_match('/CREATE TABLE (?:IF NOT EXISTS )?tb_log_sistema \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m);

        return (string) ($m[0] ?? '');
    };
    afirmar('schema.sql traz o mesmo bloco da migration 023 (exceto IF NOT EXISTS)', $bloco($migracao) !== '' && str_replace('IF NOT EXISTS ', '', $bloco($migracao)) === $bloco($schema));
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em linha ' . $e->getLine() . ')', false);
} finally {
    ini_set('error_log', (string) $logAnterior);
    @unlink($arquivoLog);
    gtDestruirAmbiente($banco, $storage);
}
exit(gtResumo('teste_log_sistema'));
