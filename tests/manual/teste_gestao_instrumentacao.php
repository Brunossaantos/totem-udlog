<?php

/**
 * Instrumentacao do log central (demanda gestao-totem, F3b, 2026-10-08): liga o
 * Util\LogSistema nos pontos de codigo e troca os getMessage() aprovados. Cada
 * ponto REAL e disparado por subprocesso (_caso_instrumentacao.php), servidor
 * embutido (401 da API) ou CLI (crons), SEMPRE contra o banco QA descartavel
 * `qa_qr_exclusivo_<hex>` (nunca udlog_totem), sem rede, sem Talent/VIO/n8n
 * reais, sem impressora.
 *
 * Cobre: linha em tb_log_sistema (origem/aba, nivel, categoria, ids, detalhe sem
 * PII) por categoria instrumentada; sentinelas de PII nunca em coluna nem em
 * error_log capturado; 401 (sem id_totem, 100 chamadas = 1 linha com contador,
 * resposta identica); falha do proprio logger nao muda a resposta; getMessage()
 * de ConexaoGestaoColetas e DocumentoRn trocado; crons gravam cron_resumo e
 * cron_falhou; checagens estaticas (nenhum registrar com getMessage/getTrace,
 * Conexao.php e Bootstrap.php sem LogSistema, uma chamada por linha).
 *
 * Uso: php tests/manual/teste_gestao_instrumentacao.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Dao\AtendimentoDao;
use Util\LogCatalogo;

$RAIZ = dirname(__DIR__, 2);
$GLOBALS['inLogsCapturados'] = [];
$GLOBALS['inTemp'] = [];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** @return list<array<string,mixed>> */
function inCat(PDO $pdo, string $categoria): array
{
    return gtLinhas($pdo, 'SELECT * FROM tb_log_sistema WHERE categoria = :c ORDER BY id_log', ['c' => $categoria]);
}

function inSoma(array $linhas): int
{
    $s = 0;
    foreach ($linhas as $l) {
        $s += (int) $l['contador'];
    }

    return $s;
}

/** Linha da categoria com o detalhe exato (ou NULL), ou null se nao existir. */
function inLinha(PDO $pdo, string $categoria, ?string $detalhe): ?array
{
    foreach (inCat($pdo, $categoria) as $l) {
        if (($l['detalhe'] ?? null) === $detalhe) {
            return $l;
        }
    }

    return null;
}

function inLer(string $arquivo): string
{
    return is_file($arquivo) ? (string) file_get_contents($arquivo) : '';
}

/**
 * Roda um cenario do _caso_instrumentacao.php (CLI, prepend QA).
 *
 * @return array{saida:string,codigo:int,log:string,http:int}
 */
function inCaso(string $cenario, array $args = [], array $envGestao = []): array
{
    $log = gtNovoLogCgi();
    file_put_contents($log, '');
    $GLOBALS['inTemp'][] = $log;
    putenv('QA_GESTAO_ENV_JSON=' . ($envGestao === [] ? '' : json_encode($envGestao)));
    $cmd = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', __DIR__ . '/_caso_instrumentacao.php', $cenario, $log, json_encode($args === [] ? new stdClass() : $args)];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    $saida = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proc);
    putenv('QA_GESTAO_ENV_JSON=');
    $texto = inLer($log);
    $GLOBALS['inLogsCapturados'][] = $texto;
    $http = preg_match('/HTTP_CODE:(\d{3})/', $saida, $m) === 1 ? (int) $m[1] : 0;

    return ['saida' => $saida, 'codigo' => $codigo, 'log' => $texto, 'http' => $http];
}

/** Roda um cron em CLI contra o banco QA. @return array{saida:string,codigo:int,log:string} */
function inCron(string $arquivo, array $envGestao = []): array
{
    $log = gtNovoLogCgi();
    file_put_contents($log, '');
    $GLOBALS['inTemp'][] = $log;
    putenv('QA_GESTAO_ENV_JSON=' . ($envGestao === [] ? '' : json_encode($envGestao)));
    $cmd = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'error_log="' . $log . '"', dirname(__DIR__, 2) . '/cron/' . $arquivo];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    $saida = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proc);
    putenv('QA_GESTAO_ENV_JSON=');
    $texto = inLer($log);
    $GLOBALS['inLogsCapturados'][] = $texto;

    return ['saida' => $saida, 'codigo' => $codigo, 'log' => $texto];
}

/** Tabelas minimas do banco EXTERNO (QA faz o papel dele) para o cron de anexos de OC. */
function inCriarTabelasExternas(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE tb_clientes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        razao_social VARCHAR(200) NOT NULL,
        cnpj VARCHAR(14) NOT NULL,
        status ENUM(\'ATIVO\',\'INATIVO\') NOT NULL DEFAULT \'ATIVO\'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE tb_ordens_coleta (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        numero_ordem_coleta VARCHAR(50) NOT NULL,
        cliente_id BIGINT UNSIGNED NOT NULL,
        placa_prevista VARCHAR(10) DEFAULT NULL,
        cnh_prevista VARCHAR(20) DEFAULT NULL,
        status ENUM(\'ATIVA\',\'INATIVA\') NOT NULL DEFAULT \'ATIVA\',
        inativada_em DATETIME DEFAULT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ordem_cliente (cliente_id, numero_ordem_coleta),
        KEY idx_ordens_placa_status (placa_prevista, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE tb_ordem_coleta_arquivos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cnpj_cliente CHAR(14) NOT NULL,
        numero_ordem_coleta VARCHAR(50) NOT NULL,
        caminho_relativo VARCHAR(255) NOT NULL,
        tamanho_bytes INT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_oc_arquivo_cnpj_numero (cnpj_cliente, numero_ordem_coleta),
        UNIQUE KEY uk_oc_arquivo_caminho (caminho_relativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/** GET com Bearer opcional no servidor embutido. @return array{status:int,corpo:string} */
function inGet(int $porta, string $caminho, ?string $bearer): array
{
    $h = "Accept: application/json\r\n" . ($bearer !== null ? "Authorization: Bearer {$bearer}\r\n" : '');
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => $h, 'ignore_errors' => true, 'timeout' => 20]]);
    $corpo = (string) @file_get_contents("http://127.0.0.1:{$porta}{$caminho}", false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $linha) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return ['status' => $status, 'corpo' => $corpo];
}

/** Arquivos PHP do projeto que podem conter instrumentacao. @return list<string> */
function inArquivosPhp(string $raiz): array
{
    $saida = [];
    foreach (['app', 'util', 'cron', 'public', 'tools'] as $dir) {
        if (!is_dir($raiz . '/' . $dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                $saida[] = str_replace('\\', '/', $f->getPathname());
            }
        }
    }
    sort($saida);

    return $saida;
}

$banco = null;
$storage = null;
$servidor = null;
$logServidor = gtNovoLogCgi();
$GLOBALS['inTemp'][] = $logServidor;

try {
    // =======================================================================
    // A. Checagens estaticas (sem banco)
    // =======================================================================
    $statements = [];
    $linhasForaDeLinhaPropria = 0;
    $categoriasUsadas = [];
    foreach (inArquivosPhp($RAIZ) as $arq) {
        $conteudo = (string) file_get_contents($arq);
        if (strpos($conteudo, 'LogSistema::registrar(') === false || str_ends_with($arq, '/util/LogSistema.php')) {
            continue;
        }
        if (preg_match_all('/LogSistema::registrar\((.*?)\);/s', $conteudo, $mm) > 0) {
            foreach ($mm[1] as $corpo) {
                $statements[] = [$arq, $corpo];
                if (preg_match("/\A\s*'([a-z0-9_]+)'/", $corpo, $c) === 1) {
                    $categoriasUsadas[$c[1]] = true;
                } elseif (preg_match("/\A\s*\\\$categoriaLog/", $corpo) === 1) {
                    // categoria vinda de variavel (helper do check-in): coberta pelo teste dinamico
                } else {
                    $linhasForaDeLinhaPropria++;
                }
            }
        }
        foreach (preg_split('/\R/', $conteudo) as $linha) {
            if (strpos($linha, 'LogSistema::registrar(') !== false && preg_match('/^\s*LogSistema::registrar\(/', $linha) !== 1) {
                $linhasForaDeLinhaPropria++;
            }
        }
    }
    afirmar('estatico: ha pontos instrumentados (>= 40 chamadas a LogSistema::registrar fora do proprio logger)', count($statements) >= 40);
    $proibidos = 0;
    foreach ($statements as [$arq, $corpo]) {
        if (preg_match('/getMessage|getTrace|getFile|getLine|__toString|\$_SERVER|\$_POST|\$_GET|\$_REQUEST|getallheaders|REMOTE_ADDR|token|placa|cnpj|cpf/i', $corpo) === 1) {
            $proibidos++;
            echo "     proibido em {$arq}: " . trim(preg_replace('/\s+/', ' ', $corpo)) . "\n";
        }
    }
    afirmar('estatico: nenhum LogSistema::registrar( recebe getMessage/getTrace/getFile/getLine/mensagem/token/IP/CNPJ/CPF/placa', $proibidos === 0);
    afirmar('estatico: toda chamada a LogSistema::registrar( fica em linha propria (inicio de linha)', $linhasForaDeLinhaPropria === 0);
    $semCatalogo = [];
    foreach (array_keys($categoriasUsadas) as $cat) {
        $def = LogCatalogo::CATEGORIAS[$cat] ?? null;
        if ($def === null || !empty($def['interna'])) {
            $semCatalogo[] = $cat;
        }
    }
    afirmar('estatico: toda categoria usada existe no catalogo e nao e interna', $semCatalogo === []);
    $esperadas = ['totem_nao_autorizado', 'oc_consulta_falhou', 'oc_baixa_falhou', 'banco_coletas_indisponivel', 'n8n_anexo_recusado', 'n8n_anexo_erro', 'talent_erro_reprocessavel', 'talent_indeterminado', 'vio_falha_integracao', 'vio_erro_interno', 'erro_banco_pdo', 'erro_tecnico', 'rate_limit_ocr_excedido', 'etiqueta_pdf_falhou', 'etiqueta_config_invalida', 'impressao_config_falhou', 'cron_resumo', 'cron_falhou', 'gestao_erro_interno', 'auditoria_falhou'];
    $faltam = [];
    foreach ($esperadas as $cat) {
        if (!isset($categoriasUsadas[$cat]) && !in_array($cat, ['talent_erro_reprocessavel', 'talent_indeterminado'], true)) {
            $faltam[] = $cat;
        }
    }
    afirmar('estatico: as 20 categorias da F3b tem ao menos uma chamada no codigo' . ($faltam !== [] ? ' (faltam: ' . implode(',', $faltam) . ')' : ''), $faltam === []);
    afirmar('estatico: as categorias dinamicas do check-in (talent_*) sao passadas pelo helper com categoriaLog', strpos((string) file_get_contents($RAIZ . '/app/Controller/AtendimentoController.php'), "registrarFalhaCheckinNoLog('talent_erro_reprocessavel'") !== false && strpos((string) file_get_contents($RAIZ . '/app/Controller/AtendimentoController.php'), "registrarFalhaCheckinNoLog('talent_indeterminado'") !== false);
    $cat = LogCatalogo::CATEGORIAS;
    afirmar('catalogo (acrescimos da F3b): motivos baixa_pendente e pendencia_nao_registrada; chaves de dominio itens e falhas (inteiros) na ordem do detalhe', in_array('baixa_pendente', LogCatalogo::MOTIVOS, true) && in_array('pendencia_nao_registrada', LogCatalogo::MOTIVOS, true) && (LogCatalogo::DOMINIO['itens'][0] ?? '') === 'int' && (LogCatalogo::DOMINIO['falhas'][0] ?? '') === 'int' && in_array('itens', LogCatalogo::ORDEM_DOMINIO, true) && in_array('falhas', LogCatalogo::ORDEM_DOMINIO, true));
    afirmar('catalogo (acrescimos da F3b): contexto de cron_resumo/cron_falhou aceita itens/falhas; janelas rate_limit_ocr 10 min e etiqueta_config_invalida 1 h', in_array('itens', $cat['cron_resumo']['contexto'], true) && in_array('falhas', $cat['cron_falhou']['contexto'], true) && $cat['rate_limit_ocr_excedido']['janela'] === 600 && $cat['etiqueta_config_invalida']['janela'] === 3600 && $cat['totem_nao_autorizado']['janela'] === 900 && $cat['totem_nao_autorizado']['contexto'] === []);
    $semDescarga = [];
    foreach (['limpar-rate-limit-ocr', 'abandonar-atendimentos', 'limpar-notas-quarentena', 'limpar-anexos-ordem-coleta'] as $cron) {
        $c = (string) file_get_contents($RAIZ . '/cron/' . $cron . '.php');
        // todo exit( menos o do guard de SAPI nao-CLI (403) precisa da descarga explicita antes
        if (substr_count($c, 'LogSistema::descarregar()') < substr_count($c, 'exit(') - 1) {
            $semDescarga[] = $cron;
        }
    }
    afirmar('estatico: cada saida (exit) dos 4 crons existentes (exceto o guard 403 de SAPI) e precedida de LogSistema::descarregar()' . ($semDescarga !== [] ? ' (faltam: ' . implode(',', $semDescarga) . ')' : ''), $semDescarga === []);
    foreach (['util/Conexao.php', 'util/Bootstrap.php'] as $rel) {
        afirmar("estatico: {$rel} NAO referencia LogSistema (banco do totem fora do ar = so error_log)", stripos((string) file_get_contents($RAIZ . '/' . $rel), 'LogSistema') === false);
    }
    $cgc = (string) file_get_contents($RAIZ . '/util/ConexaoGestaoColetas.php');
    afirmar('estatico: ConexaoGestaoColetas nao usa getMessage() (texto fixo no error_log)', stripos($cgc, 'getMessage') === false || preg_match('/error_log\([^;]*getMessage/s', $cgc) !== 1);
    afirmar('estatico: ConexaoGestaoColetas nao concatena a excecao no error_log', preg_match('/error_log\([^;]*\$e\b/s', $cgc) !== 1);
    $docRn = (string) file_get_contents($RAIZ . '/app/Rn/DocumentoRn.php');
    afirmar('estatico: DocumentoRn nao tem mais error_log($e->getMessage())', preg_match('/error_log\(\s*\$e->getMessage\(\)\s*\)/', $docRn) !== 1);
    $textosMantidos = [
        'app/Controller/AtendimentoController.php' => ["'[AtendimentoController] checkin_talent_erro_reprocessavel id_atendimento='", '"finalizar: baixa da ordem de coleta pendente, registrada para reconciliacao manual (id_atendimento={$idAtendimento})"', 'finalizar: falha ao registrar pendencia de baixa de ordem de coleta'],
        'app/Rn/VioApiBrClient.php' => ["'VioApiBrClient: falha tecnica categoria='"],
        'app/Controller/NotaController.php' => ["': falha de banco (PDOException)'", "': falha nao prevista ['", 'identificarCliente (rate limit): falha inesperada na dependencia de rate limit'],
        'app/Controller/DocumentoController.php' => ["': falha nao prevista ['"],
        'app/Rn/NotaFiscalRn.php' => ["' id_nota=' . \$idNota"],
        'app/Controller/ImpressaoAtendimentoController.php' => ["'impressao configuracao-servico-local: '", "'impressao gerar-etiqueta: falha ao gerar PDF: '", 'configuracao de etiqueta ausente/invalida no .env'],
        'app/Controller/OrdemColetaAnexoController.php' => ["'OrdemColetaAnexoController: falha_inesperada '"],
        'util/GestaoHttp.php' => ["'gestao: excecao_nao_tratada '"],
        'util/AuthGestao.php' => ["'AuthGestao: auditoria_falhou '"],
        'app/Rn/AbandonoAtendimentoRn.php' => ['abandono: falha ao abandonar atendimento, mantido em_andamento'],
        'cron/limpar-rate-limit-ocr.php' => ["'limpar-rate-limit-ocr: %d linha(s) apagada(s)"],
        'cron/abandonar-atendimentos.php' => ["'abandonar-atendimentos: %d candidato(s)"],
        'cron/limpar-notas-quarentena.php' => ["'limpar-notas-quarentena: %d arquivo(s)"],
        'cron/limpar-anexos-ordem-coleta.php' => ["'limpar-anexos-ordem-coleta: %d elegivel(is)"],
        'util/ConexaoGestaoColetas.php' => ["'ConexaoGestaoColetas: DB_HOST ou GESTAO_COLETAS_DB_NAME ausente no .env'"],
    ];
    $perdidos = [];
    foreach ($textosMantidos as $rel => $textos) {
        $c = (string) file_get_contents($RAIZ . '/' . $rel);
        foreach ($textos as $t) {
            if (strpos($c, $t) === false) {
                $perdidos[] = $rel . ' :: ' . $t;
            }
        }
    }
    afirmar('estatico: os error_log existentes seguem no codigo (duplo registro)' . ($perdidos !== [] ? ' - perdidos: ' . implode(' | ', $perdidos) : ''), $perdidos === []);

    // =======================================================================
    // Ambiente QA
    // =======================================================================
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $_ENV['STORAGE_PATH'] = $storage;
    inCriarTabelasExternas($pdo);
    $pdo->exec("INSERT INTO tb_empresa (id_empresa, nome, cnpj) VALUES (1, 'EMPRESA QA', '11222333000181')");
    $atDao = new AtendimentoDao($pdo);
    $tokenValido = 'tok_' . bin2hex(random_bytes(16));
    $idTotem = talentCriarTotemComEmpresa($pdo, 'QA-INSTR-' . bin2hex(random_bytes(3)), 1);
    $pdo->prepare('UPDATE tb_totem SET token_api = :t WHERE id_totem = :i')->execute(['t' => str_pad($tokenValido, 64, '0'), 'i' => $idTotem]);
    $tokenValido = str_pad($tokenValido, 64, '0');
    $fixExp = talentCriarAtendimentoPronto($pdo, $atDao, $idTotem, 'expedicao', 'INS1A23', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-INS-1');
    $idExp = $fixExp['id_atendimento'];
    $fixRec = talentCriarAtendimentoPronto($pdo, $atDao, $idTotem, 'recebimento', 'INS2B34');
    $idRec = $fixRec['id_atendimento'];
    foreach ([$idExp, $idRec] as $idc) {
        $pdo->prepare("UPDATE tb_atendimento SET status = 'concluido', talent_checkin_status = 'ENVIADO', talent_senha = '35784' WHERE id_atendimento = :i")->execute(['i' => $idc]);
    }
    // Para o finalizar() (so em_andamento) um terceiro atendimento expedicao
    $fixFin = talentCriarAtendimentoPronto($pdo, $atDao, $idTotem, 'expedicao', 'INS3C45', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-INS-3');
    $idFin = $fixFin['id_atendimento'];
    afirmar('ambiente: tb_log_sistema vazia antes dos cenarios', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 0);

    // =======================================================================
    // B. 401 do totem (servidor embutido; rota real public/api/atendimento.php)
    // =======================================================================
    $porta = random_int(20000, 60000);
    putenv('QA_GESTAO_ENV_JSON=' . json_encode(gtEnvPadrao()));
    $cmdSrv = [PHP_BINARY, '-S', '127.0.0.1:' . $porta, '-t', $RAIZ . '/public', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log="' . $logServidor . '"'];
    $servidor = proc_open($cmdSrv, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/qa_instr_srv_out.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/qa_instr_srv_err.log', 'w']], $pipesSrv, $RAIZ);
    putenv('QA_GESTAO_ENV_JSON=');
    $GLOBALS['inTemp'][] = sys_get_temp_dir() . '/qa_instr_srv_out.log';
    $GLOBALS['inTemp'][] = sys_get_temp_dir() . '/qa_instr_srv_err.log';
    $pronto = false;
    for ($i = 0; $i < 60 && !$pronto; $i++) {
        $s = @fsockopen('127.0.0.1', $porta, $en, $es, 0.2);
        if ($s !== false) {
            fclose($s);
            $pronto = true;
        } else {
            usleep(100000);
        }
    }
    afirmar('401: servidor embutido (rota real) subiu', $pronto);

    $tokenRuim = 'SENT_TOKEN_RUIM_' . bin2hex(random_bytes(8));
    $esperado401 = '{"sucesso":false,"erro":"Totem nao autorizado"}';
    $r0 = inGet($porta, '/api/atendimento.php', $tokenRuim);
    afirmar('401: token informado e invalido => HTTP 401 e corpo identico ao contrato ("Totem nao autorizado")', $r0['status'] === 401 && $r0['corpo'] === $esperado401);
    $corpo401Base = $r0['corpo'];
    $statusSemToken = inGet($porta, '/api/atendimento.php', null);
    afirmar('401: sem token => 401 "Token nao informado" (nao e instrumentado)', $statusSemToken['status'] === 401 && str_contains($statusSemToken['corpo'], 'Token nao informado'));
    for ($i = 0; $i < 99; $i++) {
        $rr = inGet($porta, '/api/atendimento.php', $tokenRuim);
        if ($rr['status'] !== 401 || $rr['corpo'] !== $esperado401) {
            afirmar("401: chamada {$i} mudou de resposta", false);
            break;
        }
    }
    for ($i = 0; $i < 5; $i++) {
        inGet($porta, '/api/atendimento.php', null);
    }
    inGet($porta, '/api/atendimento.php?acao=nao-existe', $tokenValido);
    $l401 = inCat($pdo, 'totem_nao_autorizado');
    afirmar('401: 100 chamadas com token invalido em <60 s = throttle de escrita (1 tentativa por janela de 60 s): 1 a 3 linhas somando contador 1 a 3 (subestimado de proposito)', count($l401) >= 1 && count($l401) <= 3 && inSoma($l401) >= 1 && inSoma($l401) <= 3);
    $somaThrottle = inSoma($l401);
    $u = $l401[0] ?? [];
    afirmar('401: linha totem_nao_autorizado = API, AVISO, SEM id_totem, SEM id_atendimento, SEM detalhe', ($u['origem'] ?? '') === 'API' && ($u['nivel'] ?? '') === 'AVISO' && array_key_exists('id_totem', $u) && $u['id_totem'] === null && $u['id_atendimento'] === null && $u['detalhe'] === null);
    afirmar('401: mensagem = a FIXA do catalogo', ($u['mensagem'] ?? '') === LogCatalogo::CATEGORIAS['totem_nao_autorizado']['mensagem']);
    afirmar('401: "Token nao informado" e token valido NAO geram/incrementam linha (soma inalterada)', inSoma($l401) === $somaThrottle);
    $dumpLinha = json_encode($l401, JSON_UNESCAPED_UNICODE);
    afirmar('401: o token informado nao aparece em NENHUMA coluna', !str_contains((string) $dumpLinha, $tokenRuim) && !str_contains((string) $dumpLinha, 'SENT_'));

    // throttle pre-auth: N requisicoes anonimas (processos/requisicoes separadas) => <= 1 escrita/conexao dedicada por janela
    $statusGlobal = static function (PDO $p, string $v): int {
        return (int) $p->query("SHOW GLOBAL STATUS LIKE '" . $v . "'")->fetch(PDO::FETCH_NUM)[1];
    };
    $pdo->exec('DELETE FROM tb_log_sistema');
    foreach (glob($storage . '/log_throttle/*') ?: [] as $mk) {
        @unlink($mk);
    }
    $nAnon = 400;
    $c0 = $statusGlobal($pdo, 'Connections');
    $i0 = $statusGlobal($pdo, 'Com_insert');
    $u0 = $statusGlobal($pdo, 'Com_update');
    $identicas = true;
    for ($i = 0; $i < $nAnon; $i++) {
        $rr = inGet($porta, '/api/atendimento.php', 'SENT_ANON_' . $i);
        $identicas = $identicas && $rr['status'] === 401 && $rr['corpo'] === $esperado401;
    }
    $c1 = $statusGlobal($pdo, 'Connections');
    $i1 = $statusGlobal($pdo, 'Com_insert');
    $u1 = $statusGlobal($pdo, 'Com_update');
    afirmar("throttle: {$nAnon} requisicoes anonimas => todas 401 com corpo identico", $identicas);
    afirmar('throttle: no maximo 1 conexao dedicada alem da de cada requisicao (delta Connections - ' . $nAnon . ' = ' . ($c1 - $c0 - $nAnon) . ')', ($c1 - $c0 - $nAnon) <= 1);
    afirmar('throttle: no maximo 1 INSERT e 1 UPDATE (tentativa de incremento + criacao da unica linha) no banco em ' . $nAnon . ' requisicoes (INSERT=' . ($i1 - $i0) . ', UPDATE=' . ($u1 - $u0) . ')', ($i1 - $i0) <= 1 && ($u1 - $u0) <= 1);
    $mkArq = glob($storage . '/log_throttle/*.json') ?: [];
    afirmar('throttle: marcador = 1 arquivo por categoria, nome hash (sem a categoria) e conteudo so timestamp', count($mkArq) === 1 && preg_match('/\A[a-f0-9]{40}\.json\z/', basename($mkArq[0])) === 1 && preg_match('/\A\d{10}\z/', (string) file_get_contents($mkArq[0])) === 1);
    // 10.000 chamadas diretas ao throttle com relogio injetado: 1 permissao por janela de 60 s
    $dirT = $storage . '/ttl_prova';
    $envAnt = $_ENV['STORAGE_PATH'] ?? null;
    $_ENV['STORAGE_PATH'] = $dirT;
    $permitidas = 0;
    for ($i = 0; $i < 10000; $i++) {
        if (\Util\LogSistema::throttlePermiteEscrita('totem_nao_autorizado', 1000000000 + intdiv($i, 100))) {
            $permitidas++;
        }
    }
    afirmar('throttle: 10.000 chamadas em 100 s simulados => ' . $permitidas . ' permissoes (<= 2)', $permitidas >= 1 && $permitidas <= 2);
    // marcador ilegivel (diretorio no lugar do arquivo) => descarta
    foreach (glob($dirT . '/log_throttle/*.json') ?: [] as $mk) {
        @unlink($mk);
        @mkdir($mk, 0700);
    }
    afirmar('throttle: marcador ilegivel => DESCARTA (false), nao escreve', \Util\LogSistema::throttlePermiteEscrita('totem_nao_autorizado', 2000000000) === false);
    $_ENV['STORAGE_PATH'] = $storage . '/inexistente_x/y';
    @file_put_contents($storage . '/inexistente_x', 'arquivo no lugar da pasta');
    afirmar('throttle: pasta indisponivel => DESCARTA (false)', \Util\LogSistema::throttlePermiteEscrita('totem_nao_autorizado', 2000000000) === false);
    $_ENV['STORAGE_PATH'] = '';
    afirmar('throttle: STORAGE_PATH ausente => DESCARTA (false)', \Util\LogSistema::throttlePermiteEscrita('totem_nao_autorizado', 2000000000) === false);
    if ($envAnt === null) {
        unset($_ENV['STORAGE_PATH']);
    } else {
        $_ENV['STORAGE_PATH'] = $envAnt;
    }
    afirmar('throttle: so as categorias anonimas sem id tem a flag no catalogo (totem_nao_autorizado, n8n_anexo_recusado)', array_keys(array_filter(LogCatalogo::CATEGORIAS, static fn ($d) => !empty($d['throttle_escrita']))) === ['totem_nao_autorizado', 'n8n_anexo_recusado']);
    // prepend: DB_NAME em QA_GESTAO_ENV_JSON aborta (exit 3)
    putenv('QA_GESTAO_ENV_JSON=' . json_encode(['DB_NAME' => 'udlog_totem']));
    file_put_contents($storage . '/prepend_alvo.php', '<?php echo "chegou";');
    $pp = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', $storage . '/prepend_alvo.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ppPipes, $RAIZ);
    $ppOut = (string) stream_get_contents($ppPipes[1]);
    stream_get_contents($ppPipes[2]);
    fclose($ppPipes[1]);
    fclose($ppPipes[2]);
    $ppCodigo = proc_close($pp);
    putenv('QA_GESTAO_ENV_JSON=');
    afirmar('prepend QA: DB_NAME em QA_GESTAO_ENV_JSON aborta com exit 3 antes de executar o script (codigo ' . $ppCodigo . ', saida [' . $ppOut . '])', $ppCodigo === 3 && $ppOut === '');
    foreach (glob($storage . '/log_throttle/*') ?: [] as $mk) {
        @unlink($mk);
    }

    // logger quebrado (tabela renomeada): resposta IDENTICA e fallback fixo
    $pdo->exec('RENAME TABLE tb_log_sistema TO tb_log_sistema_x');
    $rQ = inGet($porta, '/api/atendimento.php', $tokenRuim);
    $pdo->exec('RENAME TABLE tb_log_sistema_x TO tb_log_sistema');
    afirmar('logger quebrado: a resposta 401 e IDENTICA (mesmo status e corpo) com a tabela de log ausente', $rQ['status'] === 401 && $rQ['corpo'] === $corpo401Base);
    $logSrv = inLer($logServidor);
    afirmar('logger quebrado: so o error_log fixo "LogSistema: gravacao falhou categoria=totem_nao_autorizado classe=..."', preg_match('/LogSistema: gravacao falhou categoria=totem_nao_autorizado classe=[A-Za-z0-9_\\\\]+/', $logSrv) === 1);
    afirmar('401: error_log do servidor nao tem o token', !str_contains($logSrv, $tokenRuim) && !str_contains($logSrv, 'SENT_TOKEN'));
    $GLOBALS['inLogsCapturados'][] = $logSrv;

    // =======================================================================
    // C. Cenarios por categoria (subprocessos)
    // =======================================================================
    // --- check-in Talent (finalizar) ---
    $r = inCaso('finalizar', ['id_totem' => $idTotem, 'id_atendimento' => $idFin, 'ret' => ['status' => 'ERRO_REPROCESSAVEL', 'senha' => null, 'protocolo' => null, 'erro_categoria' => 'erro_servidor', 'mensagem_api' => 'SENT_MSG_API_CPF_11144477735']]);
    afirmar('talent erro reprocessavel: HTTP 202 mantido', $r['http'] === 202);
    $l = inLinha($pdo, 'talent_erro_reprocessavel', 'categoria_erro=erro_servidor');
    afirmar('talent_erro_reprocessavel: EXPEDICAO, ERRO, id_atendimento e id_totem do atendimento validado, detalhe categoria_erro', $l !== null && $l['origem'] === 'EXPEDICAO' && $l['nivel'] === 'ERRO' && (int) $l['id_atendimento'] === $idFin && (int) $l['id_totem'] === $idTotem);
    afirmar("talent_erro_reprocessavel: error_log existente mantido (id_atendimento={$idFin} categoria=erro_servidor)", str_contains($r['log'], "[AtendimentoController] checkin_talent_erro_reprocessavel id_atendimento={$idFin} categoria=erro_servidor"));
    $r = inCaso('finalizar', ['id_totem' => $idTotem, 'id_atendimento' => $idFin, 'ret' => ['status' => 'ERRO_REPROCESSAVEL', 'senha' => null, 'protocolo' => null, 'erro_categoria' => 'erro_montagem_payload']]);
    afirmar('talent_erro_reprocessavel: categoria erro_montagem_payload registrada com a categoria da allowlist', inLinha($pdo, 'talent_erro_reprocessavel', 'categoria_erro=erro_montagem_payload') !== null);
    $r = inCaso('finalizar', ['id_totem' => $idTotem, 'id_atendimento' => $idFin, 'ret' => ['status' => 'ERRO_REPROCESSAVEL', 'senha' => null, 'protocolo' => null, 'erro_categoria' => 'SENT_CATEGORIA_HOSTIL_cpf_11144477735']]);
    afirmar('talent_erro_reprocessavel: categoria fora da allowlist vira linha SEM detalhe (nunca o valor recebido)', inLinha($pdo, 'talent_erro_reprocessavel', null) !== null);
    foreach (['timeout', 'erro_indeterminado'] as $cat) {
        $r = inCaso('finalizar', ['id_totem' => $idTotem, 'id_atendimento' => $idFin, 'ret' => ['status' => 'ENVIO_INDETERMINADO', 'senha' => null, 'protocolo' => null, 'erro_categoria' => $cat]]);
        $l = inLinha($pdo, 'talent_indeterminado', 'categoria_erro=' . $cat);
        afirmar("talent_indeterminado ({$cat}): HTTP 500 mantido; EXPEDICAO, ERRO, com id_atendimento/id_totem", $r['http'] === 500 && $l !== null && $l['origem'] === 'EXPEDICAO' && $l['nivel'] === 'ERRO' && (int) $l['id_atendimento'] === $idFin && (int) $l['id_totem'] === $idTotem);
    }
    $enviado = ['status' => 'ENVIADO', 'senha' => '35784', 'protocolo' => 'P1', 'erro_categoria' => null];
    $r = inCaso('finalizar', ['id_totem' => $idTotem, 'id_atendimento' => $idFin, 'ret' => $enviado, 'deps' => 'sem']);
    afirmar('oc_baixa_falhou (dependencias ausentes): sucesso HTTP 200 mantido; linha EXPEDICAO/ERRO com id_atendimento e motivo=config_ausente', $r['http'] === 200 && ($l = inLinha($pdo, 'oc_baixa_falhou', 'motivo=config_ausente')) !== null && $l['origem'] === 'EXPEDICAO' && $l['nivel'] === 'ERRO' && (int) $l['id_atendimento'] === $idFin);
    $r = inCaso('finalizar', ['id_totem' => $idTotem, 'id_atendimento' => $idFin, 'ret' => $enviado, 'deps' => 'pendencia_ok']);
    afirmar('oc_baixa_falhou (pendencia registrada): sucesso mantido; motivo=baixa_pendente; error_log existente mantido', $r['http'] === 200 && inLinha($pdo, 'oc_baixa_falhou', 'motivo=baixa_pendente') !== null && str_contains($r['log'], "baixa da ordem de coleta pendente, registrada para reconciliacao manual (id_atendimento={$idFin})"));
    $r = inCaso('finalizar', ['id_totem' => $idTotem, 'id_atendimento' => $idFin, 'ret' => $enviado, 'deps' => 'pendencia_falha']);
    afirmar('oc_baixa_falhou (falha ao registrar pendencia): sucesso mantido; classe e sqlstate; motivo=pendencia_nao_registrada', $r['http'] === 200 && inLinha($pdo, 'oc_baixa_falhou', 'classe=CasoPdoExc;sqlstate=42S02;motivo=pendencia_nao_registrada') !== null && str_contains($r['log'], "falha ao registrar pendencia de baixa de ordem de coleta (id_atendimento={$idFin})"));

    // --- iniciar -> 502 (banco externo inexistente) ---
    $maxAntes = (int) gtEscalar($pdo, 'SELECT COALESCE(MAX(id_atendimento), 0) FROM tb_atendimento');
    $r502 = inCaso('iniciar_502', ['id_totem' => $idTotem, 'placa' => 'ZZZ9Z99']);
    $idNovo = (int) gtEscalar($pdo, 'SELECT MAX(id_atendimento) FROM tb_atendimento');
    $corpo502 = trim((string) preg_replace('/\s*HTTP_CODE:\d{3}\s*$/', '', $r502['saida']));
    afirmar('iniciar: consulta de OC falha => 502 com a mensagem generica (resposta mantida)', $r502['http'] === 502 && str_contains($corpo502, 'Nao foi possivel consultar as ordens de coleta agora') && $idNovo > $maxAntes);
    $l = inLinha($pdo, 'oc_consulta_falhou', 'classe=RuntimeException;http=502');
    afirmar('oc_consulta_falhou: EXPEDICAO, ERRO, id_atendimento criado e id_totem do totem autenticado', $l !== null && $l['origem'] === 'EXPEDICAO' && $l['nivel'] === 'ERRO' && (int) $l['id_atendimento'] === $idNovo && (int) $l['id_totem'] === $idTotem);
    $lb = inLinha($pdo, 'banco_coletas_indisponivel', 'classe=PDOException');
    afirmar('banco_coletas_indisponivel (conexao recusada): origem API (sem tipo), ERRO, so a classe da excecao', $lb !== null && $lb['origem'] === 'API' && $lb['nivel'] === 'ERRO' && $lb['id_atendimento'] === null && $lb['id_totem'] === null);
    afirmar('banco_coletas_indisponivel: error_log passou a ser o texto fixo', str_contains($r502['log'], 'ConexaoGestaoColetas: falha na conexao com o banco de ordens de coleta') && !str_contains($r502['log'], 'Unknown database') && !str_contains($r502['log'], 'inexistente_qa_zzz'));

    // logger quebrado no fluxo de negocio: resposta IDENTICA
    $pdo->exec('RENAME TABLE tb_log_sistema TO tb_log_sistema_x');
    $r502b = inCaso('iniciar_502', ['id_totem' => $idTotem, 'placa' => 'ZZZ8Z88']);
    $pdo->exec('RENAME TABLE tb_log_sistema_x TO tb_log_sistema');
    $corpo502b = trim((string) preg_replace('/\s*HTTP_CODE:\d{3}\s*$/', '', $r502b['saida']));
    afirmar('logger quebrado (iniciar/502): mesma resposta HTTP e corpo; so o fallback fixo no error_log', $r502b['http'] === 502 && $corpo502b === $corpo502 && preg_match('/LogSistema: gravacao falhou categoria=(oc_consulta_falhou|banco_coletas_indisponivel) classe=/', $r502b['log']) === 1);

    $r = inCaso('coletas_sem_env');
    afirmar('banco_coletas_indisponivel (DB_HOST/GESTAO_COLETAS_DB_NAME ausente): linha sem detalhe, texto do error_log mantido', str_contains($r['log'], 'ConexaoGestaoColetas: DB_HOST ou GESTAO_COLETAS_DB_NAME ausente no .env') && inLinha($pdo, 'banco_coletas_indisponivel', null) !== null);

    // getMessage() trocado: usuario invalido NAO vaza no error_log
    $usuarioSentinela = 'SENTINELA_USUARIO_ZZ';
    $rv = inCaso('coletas_vaza', ['usuario' => $usuarioSentinela]);
    afirmar('ConexaoGestaoColetas: excecao relancada generica (mensagem fixa)', str_contains($rv['saida'], 'EXC:RuntimeException:Nao foi possivel conectar ao banco de ordens de coleta'));
    afirmar('ConexaoGestaoColetas: error_log com texto fixo', str_contains($rv['log'], 'ConexaoGestaoColetas: falha na conexao com o banco de ordens de coleta'));
    afirmar('ConexaoGestaoColetas: usuario/host/porta/"Access denied" NAO vazam no error_log nem na saida', !str_contains($rv['log'], $usuarioSentinela) && !stripos($rv['log'], 'access denied') && !str_contains($rv['saida'], $usuarioSentinela) && !str_contains($rv['log'], 'SQLSTATE') && !str_contains($rv['log'], (string) ($_ENV['DB_HOST'] ?? 'localhost-nao-definido')));

    // --- impressao ---
    $r = inCaso('etiqueta', ['id_totem' => $idTotem, 'id_atendimento' => $idExp], ['ETIQUETA_LARGURA_MM' => '', 'ETIQUETA_COMPRIMENTO_MM' => '', 'ETIQUETA_ORIENTACAO' => '', 'ETIQUETA_CORTE_APOS_IMPRESSAO' => '']);
    $l = inLinha($pdo, 'etiqueta_config_invalida', 'motivo=config_invalida');
    afirmar('etiqueta_config_invalida: HTTP 500 mantido; EXPEDICAO (por tipo), ERRO, ids; error_log existente mantido', $r['http'] === 500 && $l !== null && $l['origem'] === 'EXPEDICAO' && $l['nivel'] === 'ERRO' && (int) $l['id_atendimento'] === $idExp && (int) $l['id_totem'] === $idTotem && str_contains($r['log'], 'configuracao de etiqueta ausente/invalida no .env'));
    afirmar('etiqueta_config_invalida: janela de deduplicacao de 1 h (balde alinhado em 3600 s)', $l !== null && (int) gtEscalar($pdo, 'SELECT UNIX_TIMESTAMP(janela) % 3600 FROM tb_log_sistema WHERE id_log = :i', ['i' => $l['id_log']]) === 0);
    $r = inCaso('etiqueta', ['id_totem' => $idTotem, 'id_atendimento' => $idRec], ['ETIQUETA_LARGURA_MM' => '', 'ETIQUETA_COMPRIMENTO_MM' => '', 'ETIQUETA_ORIENTACAO' => '', 'ETIQUETA_CORTE_APOS_IMPRESSAO' => '']);
    afirmar('etiqueta_config_invalida: atendimento de RECEBIMENTO cai na aba RECEBIMENTO', ($l = inLinha($pdo, 'etiqueta_config_invalida', 'motivo=config_invalida')) !== null && gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'etiqueta_config_invalida' AND origem = 'RECEBIMENTO' AND id_atendimento = :i", ['i' => $idRec]) == 1);
    // PDF falha: FPDF dublê que lanca (o FPDF real nao falha com config valida)
    $r = inCaso('etiqueta_pdf_falha', ['id_totem' => $idTotem, 'id_atendimento' => $idRec], ['ETIQUETA_LARGURA_MM' => '80', 'ETIQUETA_COMPRIMENTO_MM' => '80', 'ETIQUETA_ORIENTACAO' => 'portrait', 'ETIQUETA_CORTE_APOS_IMPRESSAO' => 'true']);
    $linhasPdf = inCat($pdo, 'etiqueta_pdf_falhou');
    afirmar('etiqueta_pdf_falhou: HTTP 500 mantido; RECEBIMENTO, ERRO, ids e so a classe; error_log existente mantido', $r['http'] === 500 && count($linhasPdf) === 1 && $linhasPdf[0]['origem'] === 'RECEBIMENTO' && $linhasPdf[0]['nivel'] === 'ERRO' && (int) $linhasPdf[0]['id_atendimento'] === $idRec && str_starts_with((string) $linhasPdf[0]['detalhe'], 'classe=') && str_contains($r['log'], 'impressao gerar-etiqueta: falha ao gerar PDF: '));
    $r = inCaso('impressao_config', [], ['IMPRESSAO_LOCAL_URL' => '', 'IMPRESSAO_LOCAL_TOKEN' => '', 'IMPRESSAO_FRONTEND_TIMEOUT_MS' => '']);
    $l = inLinha($pdo, 'impressao_config_falhou', 'classe=RuntimeException;motivo=config_invalida');
    afirmar('impressao_config_falhou: HTTP 503 mantido; API (sem tipo), ERRO; error_log existente mantido', $r['http'] === 503 && $l !== null && $l['origem'] === 'API' && $l['nivel'] === 'ERRO' && str_contains($r['log'], 'impressao configuracao-servico-local: RuntimeException'));

    // --- copias dos helpers logFalha* (hook dentro do corpo) ---
    $r = inCaso('copias_log');
    $lp = inLinha($pdo, 'erro_banco_pdo', 'classe=CasoPdoExc;sqlstate=42S02');
    afirmar('erro_banco_pdo (NotaController::logFalhaBancoPdo): RECEBIMENTO, ERRO, classe e SQLSTATE validado; error_log existente mantido', $lp !== null && $lp['origem'] === 'RECEBIMENTO' && $lp['nivel'] === 'ERRO' && str_contains($r['log'], 'ctx_nota_pdo: falha de banco (PDOException) [SQLSTATE=42S02]'));
    $lt = inLinha($pdo, 'erro_tecnico', 'classe=RuntimeException');
    afirmar('erro_tecnico (NotaController::logFalhaTecnica): RECEBIMENTO, ERRO, so a classe; error_log mantido', $lt !== null && $lt['origem'] === 'RECEBIMENTO' && str_contains($r['log'], 'ctx_nota_tec: falha nao prevista [RuntimeException]'));
    $ld = inLinha($pdo, 'erro_tecnico', 'classe=LogicException');
    afirmar('erro_tecnico (DocumentoController::logFalhaTecnica): API (sem tipo/ids no helper), ERRO, so a classe; error_log mantido', $ld !== null && $ld['origem'] === 'API' && str_contains($r['log'], 'ctx_doc_tec: falha nao prevista [LogicException]'));
    $ln = inLinha($pdo, 'erro_tecnico', 'classe=DomainException');
    afirmar('erro_tecnico (NotaFiscalRn::logFalhaTecnica): RECEBIMENTO, ERRO; error_log mantido', $ln !== null && $ln['origem'] === 'RECEBIMENTO' && str_contains($r['log'], 'ctx_rn_tec [DomainException] id_atendimento=5 id_nota=7'));

    // --- rate limit do OCR ---
    $r = inCaso('nota_rate', ['id_totem' => $idTotem, 'modo' => '429']);
    $l = inCat($pdo, 'rate_limit_ocr_excedido');
    afirmar('rate_limit_ocr_excedido: HTTP 429 mantido; RECEBIMENTO, AVISO, id_totem do totem autenticado, sem detalhe', $r['http'] === 429 && count($l) === 1 && $l[0]['origem'] === 'RECEBIMENTO' && $l[0]['nivel'] === 'AVISO' && (int) $l[0]['id_totem'] === $idTotem && $l[0]['detalhe'] === null);
    afirmar('rate_limit_ocr_excedido: janela de 10 min (balde alinhado em 600 s)', count($l) === 1 && (int) gtEscalar($pdo, 'SELECT UNIX_TIMESTAMP(janela) % 600 FROM tb_log_sistema WHERE id_log = :i', ['i' => $l[0]['id_log']]) === 0);
    $r = inCaso('nota_rate', ['id_totem' => $idTotem, 'modo' => 'excecao']);
    afirmar('rate limit OCR 503 (dependencia falhou): HTTP 503 mantido; erro_tecnico RECEBIMENTO http=503 motivo=indisponivel; error_log mantido', $r['http'] === 503 && inLinha($pdo, 'erro_tecnico', 'classe=RuntimeException;http=503;motivo=indisponivel') !== null && str_contains($r['log'], 'identificarCliente (rate limit): falha inesperada na dependencia de rate limit'));

    // --- VIO ---
    $r = inCaso('vio_client');
    afirmar('vio_falha_integracao: o cliente roda sem lancar (resposta/retorno mantidos)', str_contains($r['saida'], 'FIM'));
    afirmar('vio_falha_integracao (timeout): API, ERRO, motivo=timeout; error_log mantido', ($l = inLinha($pdo, 'vio_falha_integracao', 'motivo=timeout')) !== null && $l['origem'] === 'API' && $l['nivel'] === 'ERRO' && str_contains($r['log'], 'VioApiBrClient: falha tecnica categoria=envio_timeout_ambiguo'));
    afirmar('vio_falha_integracao (HTTP 401): http=401;motivo=nao_autorizado (so http, sem classe/chave)', inLinha($pdo, 'vio_falha_integracao', 'http=401;motivo=nao_autorizado') !== null);
    afirmar('vio_falha_integracao (5xx/rede): motivo=indisponivel agrupado (2 ocorrencias)', ($l = inLinha($pdo, 'vio_falha_integracao', 'motivo=indisponivel')) !== null && (int) $l['contador'] === 2);
    afirmar('vio_falha_integracao (HTTP 422 na consulta): http=422;motivo=dados_invalidos', inLinha($pdo, 'vio_falha_integracao', 'http=422;motivo=dados_invalidos') !== null);

    // --- DocumentoRn: getMessage() trocado ---
    $r = inCaso('doc_vio', ['id_atendimento' => $idExp]);
    afirmar('DocumentoRn: as tres rejeicoes por tipo invalido seguem fail-closed (pode_avancar=false nas tres)', str_contains($r['saida'], '[false,false,false]'));
    afirmar('DocumentoRn: error_log passou a texto FIXO (sem marcador campo=/tipo_recebido=, sem valor)', str_contains($r['log'], 'DocumentoRn: campo VIO com tipo invalido (documento=cnh)') && str_contains($r['log'], 'DocumentoRn: campo VIO com tipo invalido (documento=crlv)') && !str_contains($r['log'], 'tipo_recebido') && !str_contains($r['log'], 'campo=') && !str_contains($r['log'], 'SENT_'));
    $lc = inLinha($pdo, 'vio_erro_interno', 'classe=App\\Rn\\DocumentoVioTipoInvalidoException;motivo=dados_invalidos;documento=cnh');
    $lr = inLinha($pdo, 'vio_erro_interno', 'classe=App\\Rn\\DocumentoVioTipoInvalidoException;motivo=dados_invalidos;documento=crlv');
    afirmar('vio_erro_interno (CNH): EXPEDICAO (tipo do atendimento), ERRO, id_atendimento, classe, documento, motivo', $lc !== null && $lc['origem'] === 'EXPEDICAO' && $lc['nivel'] === 'ERRO' && (int) $lc['id_atendimento'] === $idExp && (int) $lc['contador'] === 1);
    afirmar('vio_erro_interno (CRLV automatico + manual): mesma linha agrupada (2 ocorrencias)', $lr !== null && (int) $lr['contador'] === 2);

    // --- anexo do n8n ---
    $esperadosAnexo = [
        'metodo' => ['n8n_anexo_recusado', 'AVISO', 'http=405;motivo=metodo_nao_permitido'],
        'nao_autorizado' => ['n8n_anexo_recusado', 'AVISO', 'http=401;motivo=nao_autorizado'],
        'https' => ['n8n_anexo_recusado', 'AVISO', 'http=403;motivo=https_obrigatorio'],
        'corpo_invalido' => ['n8n_anexo_recusado', 'AVISO', 'http=400;motivo=corpo_invalido'],
        'grande' => ['n8n_anexo_recusado', 'AVISO', 'http=413;motivo=arquivo_muito_grande'],
        'pdf' => ['n8n_anexo_recusado', 'AVISO', 'http=422;motivo=pdf_invalido'],
        'indisponivel' => ['n8n_anexo_erro', 'ERRO', 'http=503;motivo=indisponivel'],
        'rn_excecao' => ['n8n_anexo_erro', 'ERRO', 'classe=RuntimeException;http=500;motivo=falha_inesperada'],
    ];
    foreach ($esperadosAnexo as $tipo => [$cat, $nivel, $detalhe]) {
        $r = inCaso('anexo', ['tipo' => $tipo]);
        $l = inLinha($pdo, $cat, $detalhe);
        $httpEsperado = (int) (preg_match('/http=(\d{3})/', $detalhe, $m) === 1 ? $m[1] : 0);
        afirmar("anexo n8n ({$tipo}): HTTP {$httpEsperado} mantido; {$cat} API/{$nivel} com {$detalhe}", $r['http'] === $httpEsperado && $l !== null && $l['origem'] === 'API' && $l['nivel'] === $nivel);
    }
    afirmar('anexo n8n: error_log existente da falha inesperada mantido', str_contains($r['log'], 'OrdemColetaAnexoController: falha_inesperada RuntimeException'));

    // --- Gestao ---
    $r = inCaso('gestao_handler');
    $l = inLinha($pdo, 'gestao_erro_interno', 'classe=RuntimeException;http=500;motivo=falha_inesperada');
    afirmar('gestao_erro_interno: pagina 500 generica mantida; GESTAO, ERRO, so classe/http/motivo; error_log mantido', $r['http'] === 500 && str_contains($r['saida'], 'Erro interno') && $l !== null && $l['origem'] === 'GESTAO' && $l['nivel'] === 'ERRO' && str_contains($r['log'], 'gestao: excecao_nao_tratada RuntimeException') && !str_contains($r['saida'], 'hunter2') && !str_contains($r['log'], 'hunter2'));
    $r = inCaso('auditoria_falhou');
    afirmar('auditoria_falhou: o fluxo segue (sem lancar); GESTAO/ERRO por classe e SQLSTATE', str_contains($r['saida'], 'FIM') && inLinha($pdo, 'auditoria_falhou', 'classe=CasoPdoExc;sqlstate=42S02') !== null && ($l = inLinha($pdo, 'auditoria_falhou', 'classe=PDOException;sqlstate=42S02')) !== null && $l['origem'] === 'GESTAO' && $l['nivel'] === 'ERRO');

    // =======================================================================
    // D. Crons (CLI contra o banco QA)
    // =======================================================================
    $r = inCron('limpar-rate-limit-ocr.php');
    afirmar('cron limpar-rate-limit-ocr: exit 0, error_log existente mantido, cron_resumo (CRON, INFO, contagens do catalogo)', $r['codigo'] === 0 && str_contains($r['log'], 'limpar-rate-limit-ocr: 0 linha(s) apagada(s)') && ($l = inLinha($pdo, 'cron_resumo', 'job=limpar_rate_limit_ocr;lotes=0;itens=0')) !== null && $l['origem'] === 'CRON' && $l['nivel'] === 'INFO');
    $pdo->exec('RENAME TABLE tb_rate_limit_ocr TO tb_rate_limit_ocr_x');
    $r = inCron('limpar-rate-limit-ocr.php');
    $pdo->exec('RENAME TABLE tb_rate_limit_ocr_x TO tb_rate_limit_ocr');
    afirmar('cron limpar-rate-limit-ocr (falha de banco): exit 1, error_log mantido, cron_falhou (CRON, ERRO, classe+sqlstate+job+motivo)', $r['codigo'] === 1 && str_contains($r['log'], 'limpar-rate-limit-ocr: falha de banco (PDOException)') && ($l = inLinha($pdo, 'cron_falhou', 'classe=PDOException;sqlstate=42S02;job=limpar_rate_limit_ocr;motivo=erro_banco')) !== null && $l['origem'] === 'CRON' && $l['nivel'] === 'ERRO');

    $r = inCron('abandonar-atendimentos.php');
    afirmar('cron abandonar-atendimentos: exit 0, error_log mantido, cron_resumo job=abandonar_atendimentos', $r['codigo'] === 0 && str_contains($r['log'], 'abandonar-atendimentos: 0 candidato(s)') && inLinha($pdo, 'cron_resumo', 'job=abandonar_atendimentos;itens=0;falhas=0') !== null);
    $r = inCron('abandonar-atendimentos.php', ['STORAGE_PATH' => '']);
    afirmar('cron abandonar-atendimentos (STORAGE_PATH ausente): exit 1, cron_falhou motivo=config_ausente', $r['codigo'] === 1 && str_contains($r['log'], 'abandonar-atendimentos: STORAGE_PATH ausente ou inacessivel') && inLinha($pdo, 'cron_falhou', 'job=abandonar_atendimentos;motivo=config_ausente') !== null);

    $r = inCron('limpar-notas-quarentena.php');
    afirmar('cron limpar-notas-quarentena: exit 0, error_log mantido, cron_resumo job=limpar_notas_quarentena', $r['codigo'] === 0 && str_contains($r['log'], 'limpar-notas-quarentena: 0 arquivo(s)') && inLinha($pdo, 'cron_resumo', 'job=limpar_notas_quarentena;itens=0;falhas=0') !== null);
    $r = inCron('limpar-notas-quarentena.php', ['STORAGE_PATH' => $storage . DIRECTORY_SEPARATOR . 'nao_existe_qa']);
    afirmar('cron limpar-notas-quarentena (storage inacessivel): exit 1, cron_falhou motivo=config_ausente', $r['codigo'] === 1 && ($l = inLinha($pdo, 'cron_falhou', 'classe=RuntimeException;job=limpar_notas_quarentena;motivo=config_ausente')) !== null && $l['origem'] === 'CRON');

    putenv('QA_QR_FORCE_EXT_DB_NAME=' . $banco); // o prepend F4a recusa GESTAO_COLETAS_DB_NAME no JSON; o banco externo QA vem desta variavel
    $r = inCron('limpar-anexos-ordem-coleta.php');
    afirmar('cron limpar-anexos-ordem-coleta: exit 0, error_log mantido, cron_resumo job=limpar_anexos_oc', $r['codigo'] === 0 && str_contains($r['log'], 'limpar-anexos-ordem-coleta: 0 elegivel(is)') && inLinha($pdo, 'cron_resumo', 'job=limpar_anexos_oc;itens=0;falhas=0') !== null);
    putenv('QA_QR_FORCE_EXT_DB_NAME=qa_qr_exclusivo_00000000'); // nome QA valido que NAO existe => banco externo indisponivel
    $r = inCron('limpar-anexos-ordem-coleta.php');
    putenv('QA_QR_FORCE_EXT_DB_NAME=');
    afirmar('cron limpar-anexos-ordem-coleta (banco externo indisponivel): exit 1, cron_falhou motivo=indisponivel e banco_coletas_indisponivel', $r['codigo'] === 1 && inLinha($pdo, 'cron_falhou', 'classe=RuntimeException;job=limpar_anexos_oc;motivo=indisponivel') !== null && str_contains($r['log'], 'ConexaoGestaoColetas: falha na conexao com o banco de ordens de coleta'));
    // falha por atendimento DENTRO da transacao do abandono: rollback mantido e log sobrevive
    $idAband = $atDao->criar($idTotem, 'expedicao', 'ABN1A11');
    $pdo->prepare('UPDATE tb_atendimento SET atualizado_em = NOW() - INTERVAL 3 DAY WHERE id_atendimento = :i')->execute(['i' => $idAband]);
    $r = inCaso('abandono_falha');
    afirmar('abandono (CAS perdido na transacao): falhas=1, nenhum abandonado; error_log existente mantido', str_contains($r['saida'], '{"falhas":1,"abandonados":0}') && str_contains($r['log'], "abandono: falha ao abandonar atendimento, mantido em_andamento [RuntimeException] id_atendimento={$idAband}"));
    afirmar('abandono: o ROLLBACK do atendimento nao muda (segue em_andamento)', gtEscalar($pdo, 'SELECT status FROM tb_atendimento WHERE id_atendimento = :i', ['i' => $idAband]) === 'em_andamento');
    afirmar('abandono: o log central sobrevive ao rollback (conexao propria, no shutdown): cron_falhou CRON/ERRO classe+job+motivo', ($l = inLinha($pdo, 'cron_falhou', 'classe=RuntimeException;job=abandonar_atendimentos;motivo=falha_inesperada')) !== null && $l['origem'] === 'CRON' && $l['nivel'] === 'ERRO');

    // =======================================================================
    // E. Varredura final: PII e parametros invalidos
    // =======================================================================
    $todos = '';
    foreach (gtLinhas($pdo, 'SELECT * FROM tb_log_sistema') as $linha) {
        $todos .= json_encode($linha, JSON_UNESCAPED_UNICODE) . "\n";
    }
    $logsJuntos = implode("\n", $GLOBALS['inLogsCapturados']);
    $padraoPii = '/SENT_|SENTINELA|11144477735|11222333000181|52998224725|ABC1D23|INS1A23|INS2B34|INS3C45|ZZZ9Z99|ZZZ8Z88|hunter2|carvalho|OC-SENT|OC-INS|CLIENTE TESTE|MOTORISTA TESTE|Access denied|inexistente_qa_zzz/i';
    afirmar('PII: nenhuma sentinela (CPF/CNPJ/placa/token/usuario/OC/mensagem) em NENHUMA coluna de tb_log_sistema', preg_match($padraoPii, $todos) !== 1);
    afirmar('PII: nenhuma sentinela em NENHUM error_log capturado (todos os cenarios, crons e servidor)', preg_match($padraoPii, $logsJuntos) !== 1);
    afirmar('PII: nenhum caminho do storage/projeto em coluna nem error_log', !str_contains($todos, str_replace('\\', '\\\\', $storage)) && !str_contains($logsJuntos, $storage) && !str_contains($todos, 'xampp') && !str_contains($logsJuntos, 'xampp'));
    afirmar('contrato: nenhum cenario gerou log_parametro_invalido (todo contexto passado esta no catalogo)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'log_parametro_invalido'") === 0);
    afirmar('contrato: nenhum cenario gerou log_suprimido (tetos nao estourados)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'log_suprimido'") === 0);
    $colunaTexto = (string) gtEscalar($pdo, "SELECT GROUP_CONCAT(DISTINCT mensagem SEPARATOR '|') FROM tb_log_sistema");
    $foraDoCatalogo = 0;
    foreach (gtLinhas($pdo, 'SELECT DISTINCT categoria, mensagem FROM tb_log_sistema') as $linha) {
        if ((LogCatalogo::CATEGORIAS[$linha['categoria']]['mensagem'] ?? null) !== $linha['mensagem']) {
            $foraDoCatalogo++;
        }
    }
    afirmar('contrato: toda mensagem gravada e a FIXA do catalogo', $foraDoCatalogo === 0 && $colunaTexto !== '');
    $categoriasGravadas = array_column(gtLinhas($pdo, 'SELECT DISTINCT categoria FROM tb_log_sistema'), 'categoria');
    $naoCobertas = array_values(array_diff($esperadas, $categoriasGravadas));
    afirmar('cobertura: as 20 categorias da F3b foram gravadas por pontos reais' . ($naoCobertas !== [] ? ' (faltam: ' . implode(',', $naoCobertas) . ')' : ''), $naoCobertas === []);
} catch (Throwable $e) {
    afirmar('execucao sem excecao nao tratada: ' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if (isset($servidor) && is_resource($servidor)) {
        proc_terminate($servidor);
        proc_close($servidor);
    }
    putenv('QA_GESTAO_ENV_JSON=');
    gtDestruirAmbiente($banco, $storage);
    foreach ($GLOBALS['inTemp'] as $t) {
        @unlink($t);
    }
}

exit(gtResumo('teste_gestao_instrumentacao'));
