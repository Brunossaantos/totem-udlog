<?php

/**
 * QA INDEPENDENTE da TELA DE LOGS da Gestao Totem (demanda gestao-totem, F3c, 2026-10-08).
 * Testes ADVERSARIAIS escritos pelo QA (nao pelos implementadores) por php-cgi contra as
 * paginas REAIS `logs.php` e `log.php`, em banco QA descartavel `qa_qr_exclusivo_<hex>`
 * (nunca udlog_totem; sem rede; sem Talent/VIO/n8n):
 *  - sessoes admin x usuario x anonimo x admin desativado/rebaixado/troca pendente com sessao antiga;
 *  - todos os metodos HTTP; Origin/Host/X-Forwarded forjados; HTTP puro;
 *  - query strings hostis (QUERY_STRING CRUA: arrays, duplicados, 10 KB, 1500 parametros, NUL);
 *  - XSS/injecao por linhas inseridas direto no banco (DOM parseado, nao so substring);
 *  - contagem por aba so por periodo; paginacao 0/49/50/51/100/101 linhas com timestamps iguais;
 *  - totem inativo/inexistente; vazamento de codigo/token_api/URL; cabecalhos; links validados;
 *  - abrir a tela nao grava nada; erro de carga (tabela renomeada, privilegio revogado) sem vazamento;
 *  - concorrencia com escrita simultanea do LogSistema; desempenho com 100 mil linhas.
 *
 * Uso: php tests/manual/teste_gestao_qa_logs_tela.php
 * Subprocessos internos: --escritor <banco> <n> e --registrar <banco> <n>.
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\LogSistemaDao;
use Util\LogCatalogo;
use Util\LogSistema;

// ---------------------------------------------------------------------------
// Modo worker (escrita simultanea)
// ---------------------------------------------------------------------------
if (in_array($argv[1] ?? '', ['--escritor', '--registrar'], true)) {
    $banco = (string) $argv[2];
    $n = (int) $argv[3];
    qaQrValidarNomeBanco($banco);
    $c = qaQrConfiguracao();
    $_ENV['DB_HOST'] = $c['host'];
    $_ENV['DB_PORT'] = $c['port'];
    $_ENV['DB_USER'] = $c['user'];
    $_ENV['DB_PASS'] = $c['pass'];
    $_ENV['DB_NAME'] = $banco;
    if ($argv[1] === '--escritor') {
        $dao = new LogSistemaDao(qaQrAbrirBanco($banco));
        for ($i = 0; $i < $n; $i++) {
            $dao->registrarOuIncrementar([
                'nivel' => 'ERRO', 'origem' => 'API', 'categoria' => 'erro_tecnico', 'mensagem' => 'Erro técnico inesperado.',
                'id_atendimento' => null, 'id_totem' => null, 'detalhe' => null,
                'dedup_chave' => sha1('esc' . ($i % 40)), 'janela' => time() - (($i % 40) * 600),
            ], 1);
        }
    } else {
        for ($lote = 0; $lote < $n; $lote++) {
            for ($i = 0; $i < 8; $i++) {
                LogSistema::registrar('erro_tecnico', ['id_atendimento' => 700000 + $lote * 10 + $i, 'tipo' => $i % 2 === 0 ? 'recebimento' : 'expedicao']);
            }
            LogSistema::descarregar();
        }
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function ltIds(string $corpo): array
{
    preg_match_all('/<tr class="gestao-tabela__linha" data-id-log="(\d+)"/', $corpo, $m);

    return array_map('intval', $m[1]);
}

/** Violacoes de HTML (DOM parseado): script fora do gestao.js, on*, style, javascript:, img/iframe/object/embed. */
function ltViolacoesDom(string $html): array
{
    $v = [];
    $prev = libxml_use_internal_errors(true);
    $d = new DOMDocument();
    // um NUL trunca o parse do libxml (esconderia marcacao depois dele): remove antes de parsear
    $d->loadHTML('<?xml encoding="UTF-8">' . str_replace("\0", '', $html));
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    foreach ($d->getElementsByTagName('*') as $el) {
        $tag = strtolower($el->nodeName);
        if ($tag === 'script' && preg_match('#\A/gestao/assets/gestao\.js\?v=\d+\z#', (string) $el->getAttribute('src')) !== 1) {
            $v[] = 'script';
        }
        // <img> so e aceito do layout (logo/mini-logo): src local em /gestao/assets/, sem on*/srcset
        if ($tag === 'img' && preg_match('#\A/gestao/assets/[A-Za-z0-9_.\-]+(\?v=\d+)?\z#', (string) $el->getAttribute('src')) !== 1) {
            $v[] = 'img';
        }
        if (in_array($tag, ['iframe', 'object', 'embed', 'style', 'base', 'meta'], true) && !($tag === 'meta' && in_array($el->getAttribute('name'), ['csrf-token', 'viewport', 'robots', 'referrer', 'color-scheme'], true) || $tag === 'meta' && $el->hasAttribute('charset'))) {
            $v[] = $tag;
        }
        foreach ($el->attributes as $a) {
            $nome = strtolower($a->nodeName);
            if (str_starts_with($nome, 'on') || $nome === 'style') {
                $v[] = $tag . '@' . $nome;
            }
            if (in_array($nome, ['href', 'src', 'action', 'formaction', 'xlink:href'], true) && preg_match('/\A\s*(javascript|data|vbscript):/i', $a->nodeValue) === 1) {
                $v[] = $tag . '@' . $nome . ':js';
            }
        }
    }

    return $v;
}

/** @return list<string> todos os href/action do HTML */
function ltLinks(string $html): array
{
    $prev = libxml_use_internal_errors(true);
    $d = new DOMDocument();
    // um NUL trunca o parse do libxml (esconderia marcacao depois dele): remove antes de parsear
    $d->loadHTML('<?xml encoding="UTF-8">' . str_replace("\0", '', $html));
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $l = [];
    foreach ($d->getElementsByTagName('*') as $el) {
        foreach (['href', 'action'] as $a) {
            if ($el->hasAttribute($a)) {
                $l[] = $el->getAttribute($a);
            }
        }
    }

    return $l;
}

/** Valida os links de logs.php/log.php: so chaves conhecidas e valores nos formatos validados. @return list<string> invalidos */
function ltLinksInvalidos(string $html, array $categoriasCatalogo): array
{
    $ruins = [];
    foreach (ltLinks($html) as $href) {
        if (preg_match('#\A(/gestao/(logs|log)\.php)(\?.*)?\z#s', $href, $m) !== 1) {
            if (preg_match('~\A(/gestao/[a-z\-]+\.php(\?[A-Za-z0-9_=&.%\-]*)?|/gestao/assets/[A-Za-z0-9_.\-]+(\?v=\d+)?|#[A-Za-z0-9_\-]*)\z~', $href) !== 1) {
                $ruins[] = 'fora do padrao: ' . substr($href, 0, 80);
            }
            continue;
        }
        $q = (string) ($m[3] ?? '');
        if ($q !== '' && preg_match('/\A\?[A-Za-z0-9_=&.%\-]*\z/', $q) !== 1) {
            $ruins[] = 'caracteres: ' . substr($href, 0, 80);
            continue;
        }
        parse_str(ltrim($q, '?'), $p);
        foreach ($p as $k => $val) {
            $ok = is_string($val) && match ($k) {
                'aba' => in_array($val, ['api', 'recebimento', 'expedicao', 'cron', 'gestao'], true),
                'nivel' => in_array($val, ['ERRO', 'AVISO', 'INFO'], true),
                'de', 'ate' => preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $val) === 1 && checkdate((int) substr($val, 5, 2), (int) substr($val, 8, 2), (int) substr($val, 0, 4)),
                'totem' => $val === 'sem' || preg_match('/\A[1-9]\d{0,9}\z/', $val) === 1,
                'categoria' => in_array($val, $categoriasCatalogo, true),
                'pagina', 'id' => preg_match('/\A[1-9]\d{0,9}\z/', $val) === 1,
                'msg' => $val === 'log_nao_encontrado',
                default => false,
            };
            if (!$ok) {
                $ruins[] = 'param ' . $k . ' invalido em ' . substr($href, 0, 100);
            }
        }
    }

    return $ruins;
}

function ltTexto(string $html, string $xpath): ?string
{
    $prev = libxml_use_internal_errors(true);
    $d = new DOMDocument();
    // um NUL trunca o parse do libxml (esconderia marcacao depois dele): remove antes de parsear
    $d->loadHTML('<?xml encoding="UTF-8">' . str_replace("\0", '', $html));
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $n = (new DOMXPath($d))->query($xpath);

    return $n !== false && $n->length > 0 ? $n->item(0)->textContent : null;
}

function ltUnicode(string $s): bool
{
    return mb_check_encoding($s, 'UTF-8');
}

$banco = null;
$storage = null;
$usuarioTemp = null;
$pdoAdm = null;
$raizLog = gtNovoLogCgi();
@unlink($raizLog);

// Teardown garantido tambem em aborto (erro fatal, exit, excecao fora do try): o banco QA, o usuario MySQL
// temporario, o storage e o log do CGI sao removidos aqui se o finally nao chegou a rodar (tudo idempotente).
register_shutdown_function(static function () use (&$banco, &$storage, &$usuarioTemp, $raizLog): void {
    if ($usuarioTemp !== null) {
        try {
            qaQrPdoServidor(qaQrConfiguracao())->exec("DROP USER IF EXISTS '$usuarioTemp'@'localhost'");
        } catch (Throwable) {
        }
    }
    if ($banco !== null) {
        try {
            qaQrDroparBanco($banco);
        } catch (Throwable) {
        }
    }
    if ($storage !== null) {
        gtRemoverPasta($storage);
    }
    @unlink($raizLog);
});

try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $H = ['logErro' => $raizLog];
    $todas = [];
    $categoriasCatalogo = array_map('strval', array_keys(LogCatalogo::CATEGORIAS));
    $ipSeq = 60;
    $novoIp = static function () use (&$ipSeq): string {
        $ipSeq++;

        return '192.0.2.' . $ipSeq;
    };
    $req = static function (array $o) use (&$todas, $H): array {
        $r = gtChamar($o + $H);
        $todas[] = $r;

        return $r;
    };
    /** GET com QUERY_STRING crua (sem passar por http_build_query) */
    $raw = static function (string $arq, ?string $sid, string $qs, array $extra = []) use ($req): array {
        $o = ['arquivo' => $arq, 'cabecalhos' => ['QUERY_STRING' => $qs, 'REQUEST_URI' => '/gestao/' . $arq . '?' . substr($qs, 0, 200)]] + $extra;
        if ($sid !== null) {
            $o['cookies'] = ['gestao_sid' => $sid];
        }

        return $req($o);
    };
    $loc = static fn (array $r): string => (string) gtCabecalho($r, 'location');
    $semConteudo = static fn (array $r): bool => !str_contains($r['corpo'], 'logs-tabela') && !str_contains($r['corpo'], 'log-detalhe') && !str_contains($r['corpo'], 'Erro técnico') && !str_contains($r['corpo'], 'logs-abas') && !str_contains($r['corpo'], 'Hostil-QA');

    // ------------------------------------------------------------------
    // Semeadura
    // ------------------------------------------------------------------
    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    $idRebaixada = gtSemear($pdo, 'rita.rebaixada', 'admin', GT_SENHA_BOA, false, true, 'Rita Rebaixada');
    $idDesativado = gtSemear($pdo, 'davi.desativado', 'admin', GT_SENHA_BOA, false, true, 'Davi Desativado');
    gtSemear($pdo, 'tiago.troca', 'admin', GT_SENHA_BOA, true, true, 'Tiago Troca');

    $pdo->exec("INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES ('Maua I', '14706199000182', 1)");
    $emp = (int) $pdo->lastInsertId();
    $codigos = ['GUICHE-04-MAUAI-K7QX2M5PDW4RJT3A', 'DOCA-07-MAUAI-ZZ5QW2M5PDW4RJ7BC', 'INATIVO-01-MAUAI-AB2CD3EF4GH5IJ6K'];
    $tokens = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
    $ids = [];
    foreach ([['GUICHE-04', 1], ['DOCA-07', 1], ['INATIVO-01', 0]] as $i => [$nome, $ativo]) {
        $pdo->prepare('INSERT INTO tb_totem (codigo, nome, localizacao, id_empresa, token_api, ativo) VALUES (:c, :n, :l, :e, :t, :a)')
            ->execute(['c' => $codigos[$i], 'n' => $nome, 'l' => 'LOCALIZACAO-SEGREDO-' . $i, 'e' => $emp, 't' => $tokens[$i], 'a' => $ativo]);
        $ids[$i] = (int) $pdo->lastInsertId();
    }
    [$tA, $tB, $tI] = $ids;
    $tFantasma = 987654;
    $urlsReais = [];
    foreach ($codigos as $cd) {
        $urlsReais[] = 'totem.udlog.online/totem/?totem=' . $cd;
    }
    $segredos = array_merge($codigos, $tokens, ['LOCALIZACAO-SEGREDO-0', 'LOCALIZACAO-SEGREDO-1', 'LOCALIZACAO-SEGREDO-2', 'K7QX2M5PDW4RJT3A', 'token_api', GT_SAL]);

    $seq = 0;
    $ins = static function (string $nivel, string $origem, string $cat, string $msg, ?int $idTotem, ?string $det, int $cont, string $quando = 'NOW()') use ($pdo, &$seq): int {
        $seq++;
        $pdo->prepare("INSERT INTO tb_log_sistema (nivel, origem, categoria, mensagem, id_totem, detalhe, dedup_chave, janela, contador, criado_em, ultima_ocorrencia)
                       VALUES (:n, :o, :c, :m, :t, :d, :k, NOW(), :ct, $quando, $quando)")
            ->execute(['n' => $nivel, 'o' => $origem, 'c' => $cat, 'm' => $msg, 't' => $idTotem, 'd' => $det, 'k' => sha1('q' . $seq), 'ct' => $cont]);

        return (int) $pdo->lastInsertId();
    };
    // linhas hostis (inseridas direto no banco, fora do contrato de gravacao)
    $hostis = [
        'script' => ['<script>alert(1)</script>', '"><script>alert(2)</script>', 'a=<script>alert(3)</script>;b="><img src=x onerror=alert(4)>'],
        'attr' => ['" onmouseover="alert(5)" x="', "' onfocus='alert(6)' autofocus x='", 'x=" onload="alert(7)'],
        'svg' => ['<svg/onload=alert(8)>', '<iframe src="javascript:alert(9)">', '<a href="javascript:alert(10)">x</a>'],
        'tpl' => ['{{7*7}}${7*7}<%= 7*7 %>', "\u{202E}txet-odranreta\u{202C}", '</td></tr></table><h1>Hostil-QA</h1>'],
    ];
    $idsHostis = [];
    foreach ($hostis as $grupo => [$msg, $cat, $det]) {
        $idsHostis[$grupo] = $ins('ERRO', 'API', substr($cat, 0, 40), substr($msg, 0, 160), null, $det, 2);
    }
    $idNul = $ins('AVISO', 'API', "cat\0nul", "msg\0nul <b>Hostil-QA</b>", $tA, "k=v\0w;x=<i>y</i>", 1);
    $idUtf8 = $ins('AVISO', 'API', "cat\xC3\x28", "msg \xC3\x28 \xF0\x28\x8C\xBC invalida", $tB, "k=\xC3\x28;\xFF=\xFE", 1);
    $idLongo = $ins('INFO', 'API', str_repeat('c', 40), str_repeat('m', 160), $tA, str_repeat('d=ééé;', 42), 4294967295);
    $idInativo = $ins('ERRO', 'API', 'erro_tecnico', 'Erro técnico inesperado.', $tI, 'classe=PDOException;http=500', 1);
    $idFantasma = $ins('ERRO', 'API', 'erro_tecnico', 'Erro técnico inesperado.', $tFantasma, null, 1);
    $idCronComTotem = $ins('ERRO', 'CRON', 'cron_falhou', 'Rotina agendada falhou.', $tA, 'job=limpar_logs_gestao;motivo=erro_banco', 1);
    $idNormal = $ins('ERRO', 'API', 'erro_tecnico', 'Erro técnico inesperado.', $tA, 'classe=PDOException;sqlstate=HY000;http=500', 3);
    $idAntigo = $ins('ERRO', 'API', 'erro_tecnico', 'Erro técnico inesperado.', $tB, null, 1, 'NOW() - INTERVAL 40 DAY');
    for ($i = 0; $i < 6; $i++) {
        $ins($i % 2 ? 'AVISO' : 'INFO', 'RECEBIMENTO', 'rate_limit_ocr_excedido', 'Limite de leitura de notas excedido pelo totem.', $i % 3 === 0 ? $tA : null, null, 1, "NOW() - INTERVAL $i HOUR");
    }
    $ins('ERRO', 'EXPEDICAO', 'oc_consulta_falhou', 'Falha ao consultar a ordem de coleta.', $tB, null, 1, 'NOW() - INTERVAL 20 DAY');
    $ins('INFO', 'CRON', 'cron_resumo', 'Rotina agendada concluída.', null, 'job=limpar_logs_gestao;lotes=1', 1);
    $ins('INFO', 'GESTAO', 'gestao_erro_interno', 'Erro interno da Gestão.', null, null, 1);

    $snap = static fn (): array => [
        'logs' => md5(json_encode(array_map(static fn ($l) => array_map(static fn ($v) => is_string($v) ? bin2hex($v) : $v, $l), gtLinhas($pdo, 'SELECT * FROM tb_log_sistema ORDER BY id_log')))),
        'aud' => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria'),
        'totens' => md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_totem ORDER BY id_totem'))),
        'empresas' => md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_empresa ORDER BY id_empresa'))),
        'atend' => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_atendimento'),
    ];

    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => $novoIp()]);
    $lUsu = gtLogin('carla.usuario', GT_SENHA_BOA, ['ip' => $novoIp()]);
    $lReb = gtLogin('rita.rebaixada', GT_SENHA_BOA, ['ip' => $novoIp()]);
    $lDes = gtLogin('davi.desativado', GT_SENHA_BOA, ['ip' => $novoIp()]);
    $lTroca = gtLogin('tiago.troca', GT_SENHA_BOA, ['ip' => $novoIp()]);
    $sidAdm = (string) $lAdm['sid'];
    afirmar('setup: 5 sessoes (admin, usuario, a rebaixar, a desativar, troca pendente)', $lAdm['sid'] !== null && $lUsu['sid'] !== null && $lReb['sid'] !== null && $lDes['sid'] !== null && $lTroca['sid'] !== null);
    $antes = $snap();

    // ------------------------------------------------------------------
    // 1. Sessoes e RBAC (logs.php e log.php)
    // ------------------------------------------------------------------
    $rotas = [['logs.php', ''], ['log.php', 'id=' . $idNormal]];
    $base = $raw('logs.php', $sidAdm, '');
    afirmar('admin: logs.php 200 com tabela e dados', $base['status'] === 200 && str_contains($base['corpo'], 'id="logs-tabela"') && in_array($idNormal, ltIds($base['corpo']), true));
    foreach ($rotas as [$arq, $qs]) {
        $ra = $raw($arq, null, $qs, ['ip' => $novoIp()]);
        afirmar("$arq anonimo: 302/401 sem conteudo", in_array($ra['status'], [302, 401, 403], true) && $semConteudo($ra) && ($ra['status'] !== 302 || str_starts_with($loc($ra), '/gestao/login.php')));
        $rc = $raw($arq, 'a' . str_repeat('0', 63), $qs);
        afirmar("$arq cookie de sessao forjado (formato valido, inexistente): negado sem conteudo", $rc['status'] !== 200 && $semConteudo($rc));
        $rj = $raw($arq, "'; DROP TABLE tb_log_sistema; --", $qs);
        afirmar("$arq cookie de sessao com SQL: negado sem conteudo, tabela intacta", $rj['status'] !== 200 && $semConteudo($rj) && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') > 0);
        $rar = $req(['arquivo' => $arq, 'cabecalhos' => ['QUERY_STRING' => $qs, 'HTTP_COOKIE' => 'gestao_sid[]=' . $sidAdm . '; gestao_sid[x]=y']]);
        afirmar("$arq cookie gestao_sid como ARRAY: nao e aceito como sessao e sem erro 500", $rar['status'] !== 200 && $rar['status'] !== 500 && $semConteudo($rar));
        $rdup = $req(['arquivo' => $arq, 'cabecalhos' => ['QUERY_STRING' => $qs, 'HTTP_COOKIE' => 'gestao_sid=lixo; gestao_sid=' . $sidAdm]]);
        echo 'INFO - ' . $arq . ' cookie gestao_sid duplicado (lixo; valido) => HTTP ' . $rdup['status'] . "\n";
        afirmar("$arq cookie duplicado: sem 500 e sem vazar para nao autenticado", $rdup['status'] !== 500);
        $ru = $raw($arq, (string) $lUsu['sid'], $qs);
        afirmar("$arq perfil usuario: 403 sem conteudo", $ru['status'] === 403 && $semConteudo($ru));
        $rt = $raw($arq, (string) $lTroca['sid'], $qs);
        afirmar("$arq admin com troca de senha pendente: nao mostra logs", $rt['status'] !== 200 && $semConteudo($rt));
        $rok = $raw($arq, $sidAdm, $qs);
        afirmar("$arq admin: 200", $rok['status'] === 200);
    }
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'usuario' WHERE id_usuario = :i")->execute(['i' => $idRebaixada]);
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idDesativado]);
    foreach ($rotas as [$arq, $qs]) {
        $rr = $raw($arq, (string) $lReb['sid'], $qs);
        afirmar("$arq admin REBAIXADO com sessao antiga: negado (403/302), sem conteudo", in_array($rr['status'], [302, 401, 403], true) && $semConteudo($rr));
        $rd = $raw($arq, (string) $lDes['sid'], $qs);
        afirmar("$arq admin DESATIVADO com sessao antiga: negado (302/401/403), sem conteudo", in_array($rd['status'], [302, 401, 403], true) && $semConteudo($rd));
    }
    $pdo->prepare('UPDATE tb_gestao_sessao SET expira_em = NOW() - INTERVAL 1 MINUTE WHERE 1 = 1 AND id_usuario = :i')->execute(['i' => $idAdmin]);
    $rExp = $raw('logs.php', $sidAdm, '');
    echo 'INFO - sessao com expira_em vencida => HTTP ' . $rExp['status'] . "\n";
    $colsSessao = array_column(gtLinhas($pdo, 'SHOW COLUMNS FROM tb_gestao_sessao'), 'Field');
    echo 'INFO - colunas de tb_gestao_sessao: ' . implode(',', $colsSessao) . "\n";
    // restaura a sessao do admin com novo login (a expirada pode ter sido apagada)
    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => $novoIp()]);
    $sidAdm = (string) $lAdm['sid'];
    afirmar('setup: admin reloga apos o teste de expiracao', $sidAdm !== '' && $raw('logs.php', $sidAdm, '')['status'] === 200);
    $antes['aud'] = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria'); // o login (nao a tela) grava auditoria

    // ------------------------------------------------------------------
    // 2. Metodos HTTP
    // ------------------------------------------------------------------
    // gtChamar() faz strtoupper() do metodo: o minusculo so chega ao PHP se REQUEST_METHOD for forcado por cabecalho CGI
    $cabMetodo = static fn (string $m): array => $m === strtolower($m) ? ['REQUEST_METHOD' => $m] : [];
    foreach ([['logs.php', ''], ['log.php', 'id=' . $idNormal]] as [$arq, $qs]) {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE', 'PROPFIND', 'FOO', 'get', 'CONNECT'] as $m) {
            $ra = $req(['arquivo' => $arq, 'metodo' => $m, 'cookies' => ['gestao_sid' => $sidAdm], 'cabecalhos' => ['QUERY_STRING' => $qs] + $cabMetodo($m), 'form' => ['csrf_token' => (string) $lAdm['csrf'], 'id' => (string) $idNormal]]);
            $an = $req(['arquivo' => $arq, 'metodo' => $m, 'cabecalhos' => ['QUERY_STRING' => $qs] + $cabMetodo($m), 'form' => ['x' => '1'], 'ip' => $novoIp()]);
            if ($m === 'get') {
                // AuthGestao::metodo() normaliza com strtoupper(): 'get' vira GET (comportamento do app; o servidor web real
                // ja recusa metodo nao canonico antes do PHP). Resposta aceitavel: igual a do GET legitimo de admin.
                echo 'INFO - ' . $arq . ' metodo minusculo "get" com admin => HTTP ' . $ra['status'] . " (tratado como GET)
";
                afirmar("$arq metodo get minusculo (admin): sem 500, tratado como GET (200 com a tela) ou negado", in_array($ra['status'], [200, 400, 405, 501], true));
            } else {
                afirmar("$arq metodo $m (admin): sem 500, 405 e sem dados de log", $ra['status'] !== 500 && ($ra['status'] !== 200 || (ltIds($ra['corpo']) === [] && !str_contains($ra['corpo'], 'log-detalhe'))));
            }
            afirmar("$arq metodo $m (anonimo): sem dados e sem 500", $an['status'] !== 500 && $semConteudo($an));
        }
        $rh = $req(['arquivo' => $arq, 'metodo' => 'HEAD', 'cookies' => ['gestao_sid' => $sidAdm], 'cabecalhos' => ['QUERY_STRING' => $qs]]);
        $rhAn = $req(['arquivo' => $arq, 'metodo' => 'HEAD', 'cabecalhos' => ['QUERY_STRING' => $qs], 'ip' => $novoIp()]);
        echo 'INFO - ' . $arq . ' HEAD admin => ' . $rh['status'] . ' (corpo ' . strlen($rh['corpo']) . ' bytes)' . "\n";
        afirmar("$arq HEAD anonimo: sem dados", $rhAn['status'] !== 500 && $semConteudo($rhAn));
    }
    $pp = $req(['arquivo' => 'logs.php', 'metodo' => 'POST', 'cookies' => ['gestao_sid' => $sidAdm], 'cabecalhos' => ['QUERY_STRING' => 'aba=cron'], 'form' => ['aba' => 'gestao', 'nivel' => 'ERRO', 'csrf_token' => (string) $lAdm['csrf']]]);
    afirmar('POST com filtros no corpo e csrf valido: 405, nada de dados', $pp['status'] === 405 && $semConteudo($pp) && stripos((string) gtCabecalho($pp, 'allow'), 'GET') !== false);

    // ------------------------------------------------------------------
    // 3. Query strings hostis (QUERY_STRING crua)
    // ------------------------------------------------------------------
    $padrao = ltIds($base['corpo']);
    $hostisQs = [
        'aba[]=cron', 'aba[x]=y', 'aba[][]=1', 'aba=api&aba=cron', 'aba=cron&aba=api', 'aba=%00', 'aba=API', 'aba=api%00', 'aba=api%20', "aba=api'--",
        'pagina=-1', 'pagina=0', 'pagina=1e9', 'pagina=9223372036854775807', 'pagina=9223372036854775808', 'pagina=0x10', 'pagina=%00', 'pagina=1%00', 'pagina[]=1', 'pagina=1&pagina=2', 'pagina=2&pagina=abc',
        'pagina=99999999999999999999', 'pagina=%EF%BC%91', 'pagina=1.0', 'pagina=+1', 'pagina=1%0A',
        'de=2026-10-08T00:00:00', 'de=2026-10-08%2000:00:00', 'de=%D9%A2%D9%A0%D9%A2%D9%A6-%D9%A1%D9%A0-%D9%A0%D9%A8', 'de=2026-10-08%0A', 'de=%2B2026-10-08', 'de=0000-00-00', 'de=9999-12-31', 'de=-001-01-01', 'de=1e3', 'de=%202026-10-08', 'de=2026-10-08%00', 'de[]=2026-10-08', 'ate=2026-02-29', 'ate=2026-W41-3', 'ate=now', 'ate=today', 'ate=@0', 'ate=-1%20day', 'de=2026-10-08&de=lixo',
        'totem=-1', 'totem=99999999999999999999', 'totem[]=1', 'totem=sem%00', 'totem=SEM', 'totem=1&totem=2', 'totem=0x1', 'totem=1e0', 'totem=%00', 'totem=sem&totem=1',
        'categoria=cron_resumo', 'categoria=gestao_erro_interno', 'categoria[]=erro_tecnico', 'categoria=erro_tecnico%00', 'categoria=erro_tecnico%20', 'categoria=ERRO_TECNICO', 'categoria=%25', 'categoria=erro_tecnico&categoria=cron_resumo',
        'nivel=erro', 'nivel=Erro', 'nivel[]=ERRO', 'nivel=ERRO%00', 'nivel=ERRO%20', 'nivel=ERRO&nivel=INFO', 'nivel=0', 'nivel=%00',
        'msg=log_nao_encontrado&msg=x', 'msg[]=log_nao_encontrado', 'msg=%3Cscript%3E', 'ordem=id_log&por_pagina=1000&limite=1', 'origem=CRON&id_totem=1&sem_totem=1&id_atendimento=1',
        '=&=&&&', '&&&&', '?aba=cron', 'aba', 'aba=', '%', '%zz=1', 'a=' . str_repeat('A', 10240), str_repeat('x=1&', 2500) . 'aba=cron', 'aba=' . str_repeat('%41', 3000),
    ];
    $problemas = [];
    $mensagensLoc = [];
    $naoUtf8 = 0;
    foreach ($hostisQs as $qs) {
        foreach (['logs.php', 'log.php'] as $arq) {
            $r = $raw($arq, $sidAdm, $qs . ($arq === 'log.php' && !str_contains($qs, 'id=') ? '&id=' . $idNormal : ''));
            if (!in_array($r['status'], [200, 302], true)) {
                $problemas[] = "$arq $qs => HTTP " . $r['status'];
            }
            if ($r['status'] === 302 && preg_match('#\A/gestao/logs\.php(\?[A-Za-z0-9_=&.%\-]*)?\z#', $loc($r)) !== 1) {
                $problemas[] = "$arq " . substr($qs, 0, 40) . ' => Location fora do padrao: ' . substr($loc($r), 0, 60);
            }
            // 'classe=PDOException' e dado legitimo do campo detalhe das linhas semeadas (nao vazamento)
            if (preg_match('/(Fatal error|Uncaught|Warning:|Notice:|Deprecated:|Stack trace|SQLSTATE|PDOException)/', str_replace(['classe=PDOException', '<dd>PDOException</dd>'], '', $r['cru'])) === 1) {
                $problemas[] = "$arq " . substr($qs, 0, 40) . ' => erro PHP/SQL na resposta';
            }
            if (!ltUnicode($r['corpo'])) {
                $naoUtf8++;
            }
            if ($r['status'] === 200) {
                $v = ltViolacoesDom($r['corpo']);
                if ($v) {
                    $problemas[] = "$arq " . substr($qs, 0, 40) . ' => HTML: ' . implode(',', array_unique($v));
                }
                $lk = ltLinksInvalidos($r['corpo'], $categoriasCatalogo);
                if ($lk) {
                    $problemas[] = "$arq " . substr($qs, 0, 40) . ' => links: ' . $lk[0];
                }
                // o corpo nunca repete a entrada hostil crua
                if (preg_match('/[<>"]/', urldecode(substr($qs, 0, 100))) === 1 && str_contains($r['corpo'], urldecode(substr($qs, 0, 30)))) {
                    $problemas[] = "$arq " . substr($qs, 0, 40) . ' => reflexo da entrada';
                }
            }
        }
    }
    foreach (array_slice($problemas, 0, 15) as $p) {
        echo 'PROBLEMA - ' . $p . "\n";
    }
    afirmar('query strings hostis (' . count($hostisQs) . ' x 2 paginas): so 200/302, Location validada, sem erro PHP/SQL, HTML sem script/on*/style/javascript:, links so com filtros validados', $problemas === []);
    afirmar('query strings hostis: todas as respostas sao UTF-8 valido', $naoUtf8 === 0);

    $rPadrao = static fn (string $qs): array => ltIds($raw('logs.php', $sidAdm, $qs)['corpo']);
    $equivalentes = ['aba[]=cron', 'aba=%00', 'aba=API', 'aba=api%00', 'nivel=erro', 'nivel[]=ERRO', 'nivel=ERRO%00', 'nivel=ERRO%20', 'categoria=cron_resumo', 'categoria[]=erro_tecnico', 'categoria=erro_tecnico%00', 'categoria=ERRO_TECNICO', 'categoria=%25',
        'totem=-1', 'totem=99999999999999999999', 'totem[]=1', 'totem=0x1', 'totem=1e0', 'totem=%00', 'totem=SEM', 'pagina=-1', 'pagina=0', 'pagina=0x10', 'pagina=%00', 'pagina[]=1', 'pagina=1.0', 'pagina=+1', 'pagina=1%0A', 'pagina=%EF%BC%91',
        'de=2026-10-08T00:00:00', 'de=0000-00-00', 'de=%2B2026-10-08', 'de=1e3', 'ate=now', 'ate=@0', 'ate=today', 'de[]=2026-10-08', 'ordem=id_log&por_pagina=1000&limite=1', 'origem=CRON&id_totem=1&sem_totem=1&id_atendimento=1', 'msg[]=log_nao_encontrado', '=&=&&&', 'aba=', 'a=' . str_repeat('A', 10240), str_repeat('x=1&', 2500) . 'x=1'];
    $difere = [];
    foreach ($equivalentes as $qs) {
        if ($rPadrao($qs) !== $padrao) {
            $difere[] = substr($qs, 0, 40);
        }
    }
    afirmar('entradas invalidas/exoticas sao IGNORADAS: a lista e identica a padrao (aba API, 1a pagina, sem filtro)', $difere === []);
    if ($difere) {
        echo 'PROBLEMA - diferem: ' . implode(' | ', array_slice($difere, 0, 10)) . "\n";
    }
    $rCronUltimo = ltIds($raw('logs.php', $sidAdm, 'aba=api&aba=cron')['corpo']);
    afirmar('aba duplicada: o ultimo valor vence e o resultado e o da aba CRON (2 linhas)', count($rCronUltimo) === 2);
    $rQuery1500 = $raw('logs.php', $sidAdm, str_repeat('p=1&', 1500) . 'aba=cron');
    echo 'INFO - 1500 parametros + aba=cron => ' . count(ltIds($rQuery1500['corpo'])) . " linhas (max_input_vars corta apos 1000: a aba final pode ser ignorada)\n";
    afirmar('1500 parametros: 200 sem erro, lista valida (padrao ou CRON)', $rQuery1500['status'] === 200 && in_array(count(ltIds($rQuery1500['corpo'])), [count($padrao), 2], true));

    // ids hostis no detalhe
    $idsRuins = ['0', '-1', '1e3', '0x1', '1.0', ' 1', '1 ', '01', 'abc', '%00', '1%00', '99999999999999999999', '9223372036854775807', '4294967296', '9999999999', "1'OR'1'='1", '1;DROP%20TABLE%20tb_log_sistema', 'id[]=1', '', 'x', '%EF%BC%91', '1%0A', '1&id=2&id=abc'];
    $okId = true;
    $falhaId = '';
    foreach ($idsRuins as $iv) {
        $r = $raw('log.php', $sidAdm, str_starts_with($iv, 'id[]') ? $iv : 'id=' . $iv);
        $certo = $r['status'] === 302 && str_starts_with($loc($r), '/gestao/logs.php?aba=api') && str_contains($loc($r), 'msg=log_nao_encontrado') && !str_contains($r['cru'], 'SQLSTATE');
        if (!$certo) {
            $okId = false;
            $falhaId .= ' [' . $iv . ' => ' . $r['status'] . ' ' . substr($loc($r), 0, 50) . ']';
        }
    }
    afirmar('log.php id invalido (0, -1, 1e3, 0x1, texto, array, vazio, gigante, SQL): 302 para a lista com mensagem fixa', $okId);
    if (!$okId) {
        echo 'PROBLEMA - ids:' . $falhaId . "\n";
    }
    $rDupId = $raw('log.php', $sidAdm, 'id=' . $idNormal . '&id=999999');
    afirmar('log.php id duplicado: o ultimo vence (999999 inexistente => 302)', $rDupId['status'] === 302);
    $rSemId = $raw('log.php', $sidAdm, 'aba=cron&nivel=ERRO&de=' . (new DateTimeImmutable('today', new DateTimeZone('-03:00')))->format('Y-m-d'));
    afirmar('log.php sem id: 302 para a lista da aba/filtros validados', $rSemId['status'] === 302 && str_starts_with($loc($rSemId), '/gestao/logs.php?aba=cron&nivel=ERRO&de='));

    // ------------------------------------------------------------------
    // 4. Origin / Host / proxies forjados; HTTP puro
    // ------------------------------------------------------------------
    $forjados = [
        ['host' => 'evil.example.com'], ['host' => 'gestao.exemplo.test:1234'], ['host' => 'a.b.c.d.evil.test'], ['host' => '[::1]'], ['host' => "x'\"<script>qaforjadomarca</script>"],
        ['origin' => 'https://evil.example.com'], ['origin' => 'null'], ['origin' => 'javascript:qaforjadomarca'],
        ['cabecalhos' => ['HTTP_X_FORWARDED_HOST' => 'evil.example.com', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_REFERER' => 'https://evil.example.com/<script>qaforjadomarca', 'HTTP_X_ORIGINAL_URL' => '/gestao/usuarios.php', 'HTTP_X_REWRITE_URL' => '/x']],
        ['ua' => "<script>qaforjadomarca</script>\"'"],
    ];
    $problemasHost = [];
    foreach ($forjados as $i => $f) {
        foreach ([['logs.php', ''], ['log.php', 'id=' . $idNormal]] as [$arq, $qs]) {
            $f2 = $f + ['cabecalhos' => []];
            $f2['cabecalhos'] += ['QUERY_STRING' => $qs];
            $f2['cabecalhos']['QUERY_STRING'] = $qs;
            $r = $req(['arquivo' => $arq, 'cookies' => ['gestao_sid' => $sidAdm]] + $f2);
            if (isset($f['ua'])) {
                // a sessao e vinculada ao User-Agent (ua_hash): outro UA com o cookie roubado NAO entrega logs
                // e a sessao original e encerrada; o teste reloga logo abaixo.
                if (!in_array($r['status'], [302, 401, 403], true) || !$semConteudo($r)) {
                    $problemasHost[] = "$arq UA forjado => HTTP " . $r['status'] . ' (esperado negar) ou conteudo vazado';
                }
                if (str_contains($r['cru'], 'qaforjadomarca') || str_contains($r['cru'], 'logs-tabela') || str_contains($r['cru'], 'log-detalhe')) {
                    $problemasHost[] = "$arq UA forjado => reflete/entrega conteudo";
                }
                $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => $novoIp()]);
                $sidAdm = (string) $lAdm['sid'];
                $antes['aud'] = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria'); // o login (nao a tela) grava auditoria
                continue;
            }
            if (!in_array($r['status'], [200, 302, 400, 401, 403], true)) {
                $problemasHost[] = "$arq forjado #$i => HTTP " . $r['status'];
            }
            foreach (['evil.example.com', '6.6.6.6', 'qaforjadomarca'] as $marca) {
                if (str_contains($r['corpo'], $marca) || str_contains((string) gtCabecalho($r, 'location'), $marca)) {
                    $problemasHost[] = "$arq forjado #$i => reflete $marca";
                }
            }
            if ($r['status'] === 200 && ltViolacoesDom($r['corpo'])) {
                $problemasHost[] = "$arq forjado #$i => HTML invalido";
            }
            if ($r['status'] === 200 && ltLinksInvalidos($r['corpo'], $categoriasCatalogo)) {
                $problemasHost[] = "$arq forjado #$i => links";
            }
        }
    }
    foreach ($problemasHost as $p) {
        echo 'PROBLEMA - ' . $p . "\n";
    }
    afirmar('Origin/Host/X-Forwarded/Referer/UA forjados em GET: sem 5xx, nada refletido, sem Location externa, links relativos', $problemasHost === []);
    $rHttp = $req(['arquivo' => 'logs.php', 'https' => false, 'cookies' => ['gestao_sid' => $sidAdm]]);
    afirmar('HTTP puro (sem TLS, GESTAO_PERMITIR_HTTP=false) com sessao de admin: nao entrega logs', $rHttp['status'] !== 200 && $semConteudo($rHttp));
    $rHttpAn = $req(['arquivo' => 'log.php', 'https' => false, 'cabecalhos' => ['QUERY_STRING' => 'id=' . $idNormal], 'cookies' => ['gestao_sid' => $sidAdm]]);
    afirmar('HTTP puro em log.php: nao entrega o detalhe', $rHttpAn['status'] !== 200 && $semConteudo($rHttpAn));
    $rProxy = $req(['arquivo' => 'logs.php', 'https' => false, 'cabecalhos' => ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_SSL' => 'on', 'HTTP_FRONT_END_HTTPS' => 'on'], 'cookies' => ['gestao_sid' => $sidAdm]]);
    echo 'INFO - HTTP puro com X-Forwarded-Proto: https => HTTP ' . $rProxy['status'] . "\n";

    // ------------------------------------------------------------------
    // 5. XSS e injecao por linhas hostis no banco
    // ------------------------------------------------------------------
    $lista = $raw('logs.php', $sidAdm, 'aba=api');
    $lista2 = $raw('logs.php', $sidAdm, 'aba=api&pagina=1');
    afirmar('XSS lista: HTML parseado sem script/on*/style/javascript:/img/iframe, mesmo com as linhas hostis', $lista['status'] === 200 && ltViolacoesDom($lista['corpo']) === [] && $lista2['status'] === 200);
    foreach (['script', 'attr', 'svg', 'tpl'] as $g) {
        $esc = $hostis[$g][0];
        $texto = ltTexto($lista['corpo'], '//tr[@data-id-log="' . $idsHostis[$g] . '"]/td[contains(@class,"col-mensagem")]');
        afirmar("XSS lista ($g): a mensagem hostil aparece como TEXTO identico (nao virou marcacao)", $texto !== null && trim($texto) === trim(substr($esc, 0, 160)) && !str_contains($lista['corpo'], $esc) || $g === 'tpl' && $texto !== null);
        $cat = ltTexto($lista['corpo'], '//tr[@data-id-log="' . $idsHostis[$g] . '"]/td[contains(@class,"col-categoria")]');
        afirmar("XSS lista ($g): a categoria hostil aparece como texto", $cat !== null && trim($cat) === trim(substr($hostis[$g][1], 0, 40)));
        $d = $raw('log.php', $sidAdm, 'id=' . $idsHostis[$g]);
        afirmar("XSS detalhe ($g): 200, HTML sem script/on*/style/javascript: e mensagem como texto", $d['status'] === 200 && ltViolacoesDom($d['corpo']) === [] && ltTexto($d['corpo'], '//*[@id="log-mensagem"]') === substr($esc, 0, 160) && ltTexto($d['corpo'], '//*[@id="log-categoria"]') === substr($hostis[$g][1], 0, 40));
        $dt = ltTexto($d['corpo'], '//*[@id="log-tecnico"]');
        afirmar("XSS detalhe ($g): detalhe tecnico escapado (texto presente, nada executavel)", $dt !== null && ltLinksInvalidos($d['corpo'], $categoriasCatalogo) === []);
    }
    foreach (['nul' => $idNul, 'utf8' => $idUtf8, 'longo' => $idLongo] as $nome => $idr) {
        $dl = $raw('log.php', $sidAdm, 'id=' . $idr);
        $ll = $raw('logs.php', $sidAdm, 'aba=api');
        afirmar("linha '$nome' (NUL/UTF-8 invalido/limite): detalhe 200, UTF-8 valido, sem tag injetada", $dl['status'] === 200 && ltUnicode($dl['corpo']) && ltViolacoesDom($dl['corpo']) === [] && !str_contains($dl['corpo'], '<b>Hostil-QA') && !str_contains($dl['corpo'], '<i>y</i>'));
        afirmar("linha '$nome': lista 200, UTF-8 valido e a linha esta presente", $ll['status'] === 200 && ltUnicode($ll['corpo']) && in_array($idr, ltIds($ll['corpo']), true));
    }
    echo 'INFO - byte NUL passa ao HTML da LISTA: ' . (str_contains($raw('logs.php', $sidAdm, 'aba=api')['corpo'], "\0") ? 'SIM (observacao: h() nao remove NUL; so linhas gravadas fora do contrato)' : 'nao') . "
";
    $dNul = $raw('log.php', $sidAdm, 'id=' . $idNul);
    echo 'INFO - byte NUL passa ao HTML do detalhe: ' . (str_contains($dNul['corpo'], "\0") ? 'SIM (observacao)' : 'nao') . "\n";
    $dLongo = $raw('log.php', $sidAdm, 'id=' . $idLongo);
    afirmar('contador no teto (4294967295): exibido sem overflow nem notacao cientifica', str_contains($dLongo['corpo'], '4294967295 ocorrências') && str_contains($raw('logs.php', $sidAdm, 'aba=api')['corpo'], 'x4294967295'));
    // <wbr> da categoria: inserido SO depois do h() (a categoria hostil continua escapada; nunca literal "&lt;wbr&gt;")
    $idWbr = $ins('ERRO', 'API', 'e_"><img src=x onerror=1>_f', 'Erro técnico inesperado.', null, null, 1);
    $rWbr = $raw('logs.php', $sidAdm, 'aba=api');
    $dWbr = $raw('log.php', $sidAdm, 'id=' . $idWbr);
    $pdo->prepare('DELETE FROM tb_log_sistema WHERE id_log = :i')->execute(['i' => $idWbr]);
    afirmar('<wbr> na categoria (lista e detalhe): marca <wbr> real depois de cada "_", conteudo hostil escapado e sem <img>/on* injetado', str_contains($rWbr['corpo'], 'e_<wbr>&quot;&gt;&lt;img src=x onerror=1&gt;_<wbr>f') && str_contains($dWbr['corpo'], 'e_<wbr>&quot;&gt;&lt;img src=x onerror=1&gt;_<wbr>f') && ltViolacoesDom($rWbr['corpo']) === [] && ltViolacoesDom($dWbr['corpo']) === [] && !str_contains($rWbr['corpo'], '&lt;wbr') && !str_contains($dWbr['corpo'], '&lt;wbr'));
    $rNormal = $raw('logs.php', $sidAdm, 'aba=api');
    afirmar('<wbr> na categoria normal: o texto continua "erro_tecnico" e o HTML tem erro_<wbr>tecnico (nao escapado como texto)', ltTexto($rNormal['corpo'], '//tr[@data-id-log="' . $idNormal . '"]/td[contains(@class,"col-categoria")]') === 'erro_tecnico' && str_contains($rNormal['corpo'], 'erro_<wbr>tecnico') && !str_contains($rNormal['corpo'], '&lt;wbr'));
    $filtroCat = $raw('logs.php', $sidAdm, 'aba=api');
    afirmar('categorias hostis do banco NAO entram no <select> do filtro (so catalogo)', !str_contains($filtroCat['corpo'], 'alert(2)') || ltTexto($filtroCat['corpo'], '//select[@id="filtro-categoria"]') !== null && !str_contains((string) ltTexto($filtroCat['corpo'], '//select[@id="filtro-categoria"]'), 'script'));
    $selCat = (string) ltTexto($filtroCat['corpo'], '//select[@id="filtro-categoria"]');
    afirmar('select de categoria: so valores do catalogo (nenhuma categoria hostil/NUL/UTF-8 invalido do banco)', !str_contains($selCat, 'script') && !str_contains($selCat, 'onerror') && !str_contains($selCat, "cat\0") && !str_contains($selCat, 'cat?(') && !str_contains($selCat, 'alert'));

    // ------------------------------------------------------------------
    // 6. Contagem por aba SO por periodo
    // ------------------------------------------------------------------
    $hoje = new DateTimeImmutable('today', new DateTimeZone('-03:00'));
    $iso = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d');
    $contagens = static function (string $corpo): array {
        preg_match_all('/id="aba-(api|recebimento|expedicao|cron|gestao)"[^>]*>.*?gestao-aba__contagem"[^>]*>(\d+)</s', $corpo, $m);

        return array_combine($m[1], array_map('intval', $m[2]));
    };
    $sem = $contagens($raw('logs.php', $sidAdm, 'aba=api')['corpo']);
    $dbAba = static function (?string $de, ?string $ate) use ($pdo): array {
        $w = [];
        $p = [];
        if ($de) {
            $w[] = 'ultima_ocorrencia >= :de';
            $p['de'] = $de . ' 00:00:00';
        }
        if ($ate) {
            $w[] = 'ultima_ocorrencia < :ate';
            $p['ate'] = (new DateTimeImmutable($ate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $r = array_fill_keys(['api', 'recebimento', 'expedicao', 'cron', 'gestao'], 0);
        foreach (gtLinhas($pdo, 'SELECT origem, COUNT(*) c FROM tb_log_sistema' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' GROUP BY origem', $p) as $l) {
            $r[strtolower($l['origem'])] = (int) $l['c'];
        }

        return $r;
    };
    afirmar('contagem por aba sem filtro = contagem do banco por origem', $sem === $dbAba(null, null));
    $comFiltros = $contagens($raw('logs.php', $sidAdm, 'aba=api&nivel=ERRO&totem=' . $tA . '&categoria=erro_tecnico')['corpo']);
    afirmar('contagem por aba IGNORA nivel/totem/categoria (identica a sem filtro)', $comFiltros === $sem);
    $cron = $contagens($raw('logs.php', $sidAdm, 'aba=cron&nivel=INFO&categoria=cron_resumo')['corpo']);
    afirmar('contagem por aba na aba CRON com nivel/categoria: ainda identica a sem filtro', $cron === $sem);
    $d30 = $iso($hoje->modify('-30 days'));
    $pPer = $contagens($raw('logs.php', $sidAdm, 'aba=api&nivel=ERRO&de=' . $d30 . '&ate=' . $iso($hoje))['corpo']);
    afirmar('contagem por aba APLICA o periodo (de/ate) e so ele', $pPer === $dbAba($d30, $iso($hoje)) && $pPer['api'] < $sem['api']);
    $pPerInv = $contagens($raw('logs.php', $sidAdm, 'aba=api&de=lixo&ate=2099-01-01')['corpo']);
    afirmar('periodo invalido nao altera a contagem (ignorado)', $pPerInv === $sem);
    $pInv = $contagens($raw('logs.php', $sidAdm, 'aba=api&de=' . $iso($hoje) . '&ate=' . $iso($hoje->modify('-3 days')))['corpo']);
    afirmar('periodo invertido: ignorado (contagem sem filtro)', $pInv === $sem);
    $d91 = $iso($hoje->modify('-91 days'));
    $p91 = $raw('logs.php', $sidAdm, 'de=' . $d91);
    afirmar('data com 91 dias (alem da retencao): ignorada com mensagem e contagem sem filtro', $contagens($p91['corpo']) === $sem && str_contains($p91['corpo'], 'erro-periodo-de'));
    $p90 = $raw('logs.php', $sidAdm, 'de=' . $iso($hoje->modify('-90 days')));
    afirmar('data com 90 dias exatos: aceita', !str_contains($p90['corpo'], 'erro-periodo-de'));

    // ------------------------------------------------------------------
    // 7. Totem inativo / inexistente
    // ------------------------------------------------------------------
    $lApi = $raw('logs.php', $sidAdm, 'aba=api')['corpo'];
    $rotuloDe = static fn (string $corpo, int $id): ?string => ltTexto($corpo, '//tr[@data-id-log="' . $id . '"]/td[contains(@class,"col-totem")]');
    afirmar('lista: totem INATIVO aparece com nome (empresa)', $rotuloDe($lApi, $idInativo) === 'INATIVO-01 (Maua I)');
    afirmar('lista: totem INEXISTENTE aparece como "Totem removido" (sem id nem erro)', $rotuloDe($lApi, $idFantasma) === 'Totem removido' && !str_contains($lApi, (string) $tFantasma));
    $dI = $raw('log.php', $sidAdm, 'id=' . $idInativo);
    $dF = $raw('log.php', $sidAdm, 'id=' . $idFantasma);
    afirmar('detalhe: totem inativo com nome (empresa); inexistente "Totem removido"', ltTexto($dI['corpo'], '//*[@id="log-totem"]') === 'INATIVO-01 (Maua I)' && ltTexto($dF['corpo'], '//*[@id="log-totem"]') === 'Totem removido' && !str_contains($dF['corpo'], (string) $tFantasma));
    $fI = $raw('logs.php', $sidAdm, 'totem=' . $tI);
    afirmar('filtro por totem INATIVO funciona (so as linhas dele)', in_array($idInativo, ltIds($fI['corpo']), true) && count(ltIds($fI['corpo'])) === (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API' AND id_totem = $tI") && str_contains($fI['corpo'], 'value="' . $tI . '" selected'));
    $fF = $raw('logs.php', $sidAdm, 'totem=' . $tFantasma);
    afirmar('filtro por totem INEXISTENTE e ignorado (lista padrao)', ltIds($fF['corpo']) === $padrao);
    $cCron = $raw('logs.php', $sidAdm, 'aba=cron');
    afirmar('aba CRON: linha com id_totem preenchido NAO mostra coluna nem nome do totem', !str_contains($cCron['corpo'], 'col-totem') && !str_contains($cCron['corpo'], 'GUICHE-04') && in_array($idCronComTotem, ltIds($cCron['corpo']), true));
    $dCron = $raw('log.php', $sidAdm, 'id=' . $idCronComTotem);
    afirmar('detalhe de linha CRON com id_totem: sem "Totem" nem nome do totem', !str_contains($dCron['corpo'], 'id="log-totem"') && !str_contains($dCron['corpo'], 'GUICHE-04'));
    $semTotem = $raw('logs.php', $sidAdm, 'totem=sem');
    afirmar('totem=sem: so linhas sem totem (id_totem NULL)', count(ltIds($semTotem['corpo'])) === min(50, (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem='API' AND id_totem IS NULL")) && $rotuloDe($semTotem['corpo'], $idNormal) === null);

    // ------------------------------------------------------------------
    // 8. Vazamento (codigo, token_api, URL, localizacao) e cabecalhos em TODAS as respostas
    // ------------------------------------------------------------------
    $vaza = 0;
    $semHeader = [];
    foreach ($todas as $r) {
        foreach (array_merge($segredos, $urlsReais) as $s) {
            if (str_contains($r['cru'], $s)) {
                $vaza++;
            }
        }
        if (str_contains($r['cru'], $sidAdm) && !str_contains($r['cru'], 'Set-Cookie')) {
            $vaza++;
        }
        $st = $r['status'];
        if (in_array($st, [200, 302, 401, 403, 405, 500], true)) {
            if (gtCabecalho($r, 'cache-control') === null || !str_contains((string) gtCabecalho($r, 'cache-control'), 'no-store')) {
                $semHeader[$st . ':cache'] = ($semHeader[$st . ':cache'] ?? 0) + 1;
            }
        }
    }
    afirmar('NENHUMA das ' . count($todas) . ' respostas contem codigo, token_api, URL, localizacao ou sal (valores reais)', $vaza === 0);
    echo 'INFO - respostas sem Cache-Control no-store por status: ' . json_encode($semHeader) . "\n";
    afirmar('toda resposta 200/302/401/403/405/500 sai com Cache-Control no-store', $semHeader === []);
    $csp = (string) gtCabecalho($base, 'content-security-policy');
    afirmar('CSP presente, sem unsafe-inline/unsafe-eval, default-src/script-src restritos', $csp !== '' && !str_contains($csp, 'unsafe-inline') && !str_contains($csp, 'unsafe-eval') && (str_contains($csp, "default-src 'none'") || str_contains($csp, "default-src 'self'")) && str_contains($csp, "frame-ancestors 'none'"));
    afirmar('X-Frame-Options DENY, nosniff e Referrer-Policy', gtCabecalho($base, 'x-frame-options') === 'DENY' && gtCabecalho($base, 'x-content-type-options') === 'nosniff' && gtCabecalho($base, 'referrer-policy') !== null);
    echo 'INFO - CSP: ' . $csp . "\n";
    $semInline = true;
    foreach ([$base, $lista, $dI, $dNul, $cCron, $fI] as $r) {
        if (ltViolacoesDom($r['corpo']) !== []) {
            $semInline = false;
        }
        if (preg_match('/<script\b(?![^>]*\bsrc=)/i', $r['corpo']) === 1) {
            $semInline = false;
        }
    }
    afirmar('sem inline (script/style/on*) nas telas de lista e detalhe', $semInline);
    $abasLinks = ltLinksInvalidos($base['corpo'], $categoriasCatalogo);
    afirmar('links da lista padrao (abas, atalhos, detalhe, limpar, menu) so com filtros validados', $abasLinks === []);

    // "Voltar" e paginacao com entrada hostil
    $volta = $raw('log.php', $sidAdm, 'id=' . $idNormal . '&aba=%22%3E%3Cscript%3E&nivel=ERRO&de=2026-02-31&ate=' . $iso($hoje) . '&totem=' . $tA . '&categoria=erro_tecnico&pagina=2&next=http://evil.test&redirect=//evil.test&return=javascript:1');
    $hv = '';
    if (preg_match('/id="log-voltar" href="([^"]*)"/', $volta['corpo'], $m)) {
        $hv = html_entity_decode($m[1]);
    }
    parse_str((string) parse_url($hv, PHP_URL_QUERY), $pv);
    afirmar('Voltar: so aba/nivel/ate/totem/categoria/pagina validados; sem next/redirect/return; de invalido fora', str_starts_with($hv, '/gestao/logs.php?') && ($pv['aba'] ?? '') === 'api' && ($pv['nivel'] ?? '') === 'ERRO' && !isset($pv['de']) && ($pv['ate'] ?? '') === $iso($hoje) && ($pv['totem'] ?? '') === (string) $tA && ($pv['categoria'] ?? '') === 'erro_tecnico' && !str_contains($hv, 'evil') && !str_contains($hv, 'javascript'));
    $pg = $raw('logs.php', $sidAdm, 'aba=api&nivel=ERRO&pagina=1&x=%22%3E%3Cscript%3E&de=nope');
    afirmar('paginacao/abas/atalhos de uma lista com entrada hostil: links so validados', ltLinksInvalidos($pg['corpo'], $categoriasCatalogo) === [] && !str_contains($pg['corpo'], 'nope') && !str_contains($pg['corpo'], 'x=%22'));

    // ------------------------------------------------------------------
    // 9. Abrir a tela nao grava nada
    // ------------------------------------------------------------------
    $depois = $snap();
    foreach ($antes as $k => $v) {
        afirmar("somente leitura: tabela/contagem '$k' inalterada apos " . count($todas) . ' requisicoes (RBAC, hostis, forjadas)', $v === $depois[$k]);
    }
    $nAtend = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_atendimento');
    afirmar('somente leitura: nenhum atendimento criado', $nAtend === 0);

    // ------------------------------------------------------------------
    // 10. Paginacao: 0/49/50/51/100/101 linhas, timestamps IGUAIS
    // ------------------------------------------------------------------
    $linkOk = true;
    foreach ([0, 1, 49, 50, 51, 100, 101] as $N) {
        $pdo->exec('TRUNCATE TABLE tb_log_sistema');
        if ($N > 0) {
            $pdo->exec("INSERT INTO tb_log_sistema (nivel, origem, categoria, mensagem, dedup_chave, janela, contador, criado_em, ultima_ocorrencia)
                SELECT 'INFO', 'GESTAO', 'gestao_erro_interno', 'Erro interno da Gestão.', SHA1(CONCAT('pg', n)), NOW(), 1, '2026-10-08 12:00:00', NOW() - INTERVAL 1 HOUR FROM (
                SELECT a.d + 10 * b.d + 100 * c.d + 1 AS n FROM (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
                (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
                (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c) x WHERE n <= $N");
        }
        $paginas = max(1, (int) ceil($N / 50));
        $vistos = [];
        $contOk = true;
        for ($p = 1; $p <= $paginas; $p++) {
            $r = $raw('logs.php', $sidAdm, 'aba=gestao' . ($p > 1 ? '&pagina=' . $p : ''));
            $linhas = ltIds($r['corpo']);
            $esperadoNaPag = $p < $paginas ? 50 : $N - 50 * ($paginas - 1);
            $vistos = array_merge($vistos, $linhas);
            $primeiro = $N === 0 ? 0 : ($p - 1) * 50 + 1;
            $ultimo = $N === 0 ? 0 : $primeiro + $esperadoNaPag - 1;
            $txt = $N === 0 ? 'Nenhum registro.' : "Exibindo $primeiro a $ultimo de $N " . ($N === 1 ? 'registro' : 'registros') . '.';
            $contOk = $contOk && $r['status'] === 200 && count($linhas) === $esperadoNaPag && str_contains($r['corpo'], $txt)
                && ($N === 0 || str_contains($r['corpo'], 'Página ' . $p . ' de ' . $paginas))
                && ($N === 0 || ($p === 1) === str_contains($r['corpo'], 'id="pag-anterior" aria-disabled="true"'))
                && ($N === 0 || ($p === $paginas) === str_contains($r['corpo'], 'id="pag-proxima" aria-disabled="true"'));
            if (ltLinksInvalidos($r['corpo'], $categoriasCatalogo)) {
                $linkOk = false;
            }
        }
        afirmar("paginacao N=$N: $paginas pagina(s), contador, anterior/proxima e linhas por pagina corretos", $contOk);
        if ($N > 0) {
            $todosIds = array_map('intval', array_column(gtLinhas($pdo, 'SELECT id_log FROM tb_log_sistema ORDER BY ultima_ocorrencia DESC, id_log DESC'), 'id_log'));
            afirmar("paginacao N=$N com timestamps IGUAIS: ordem estavel (id_log DESC), sem repeticao nem perda entre paginas", $vistos === $todosIds && count(array_unique($vistos)) === $N);
        }
        $alem = $raw('logs.php', $sidAdm, 'aba=gestao&pagina=' . ($paginas + 1));
        $alemGrande = $raw('logs.php', $sidAdm, 'aba=gestao&pagina=9999999999');
        $destino = '/gestao/logs.php?aba=gestao' . ($paginas > 1 ? '&pagina=' . $paginas : '');
        afirmar("paginacao N=$N: pagina alem da ultima (e 9999999999) => 302 para a ultima, sem laco", $alem['status'] === 302 && $loc($alem) === $destino && $alemGrande['status'] === 302 && $loc($alemGrande) === $destino && $raw('logs.php', $sidAdm, ltrim(substr($destino, strlen('/gestao/logs.php?')), '?'))['status'] === 200);
    }
    afirmar('paginacao: todos os links das paginas validados', $linkOk);

    // ------------------------------------------------------------------
    // 11. Erro de carga sem vazamento (tabela renomeada, coluna, tb_totem, privilegio revogado)
    // ------------------------------------------------------------------
    $pdo->exec('TRUNCATE TABLE tb_log_sistema');
    $idDet = $ins('ERRO', 'API', 'erro_tecnico', 'Erro técnico inesperado.', $tA, null, 1);
    $padroesTecnicos = '/(SQLSTATE|PDOException|Base table|doesn\'t exist|Unknown column|tb_log_sistema|tb_totem|tb_empresa|Table \'|Access denied|command denied|\.php on line|Stack trace|qa_qr_exclusivo|GestaoLogController|LogSistemaDao)/i';
    $erroLimpo = static function (array $rs) use ($padroesTecnicos): bool {
        foreach ($rs as $r) {
            if (preg_match($padroesTecnicos, $r['cru']) === 1) {
                return false;
            }
        }

        return true;
    };
    $cenarios = [
        'tabela tb_log_sistema renomeada' => ['RENAME TABLE tb_log_sistema TO tb_log_sistema_off', 'RENAME TABLE tb_log_sistema_off TO tb_log_sistema'],
        'tabela tb_totem renomeada' => ['RENAME TABLE tb_totem TO tb_totem_off', 'RENAME TABLE tb_totem_off TO tb_totem'],
        'tabela tb_empresa renomeada' => ['RENAME TABLE tb_empresa TO tb_empresa_off', 'RENAME TABLE tb_empresa_off TO tb_empresa'],
        'coluna detalhe renomeada' => ['ALTER TABLE tb_log_sistema CHANGE detalhe detalhe_off VARCHAR(255) NULL', 'ALTER TABLE tb_log_sistema CHANGE detalhe_off detalhe VARCHAR(255) NULL'],
    ];
    foreach ($cenarios as $nome => [$quebra, $conserta]) {
        @unlink($raizLog);
        $pdo->exec($quebra);
        try {
            $e1 = $raw('logs.php', $sidAdm, 'aba=api');
            $e2 = $raw('log.php', $sidAdm, 'id=' . $idDet);
            $e3 = $raw('logs.php', $sidAdm, 'aba=cron&pagina=3');
        } finally {
            $pdo->exec($conserta);
        }
        $lg = (string) @file_get_contents($raizLog);
        afirmar("erro de carga ($nome): lista e detalhe respondem 500 com mensagem fixa, sem SQLSTATE/tabela/classe/arquivo/linha", $e1['status'] === 500 && $e2['status'] === 500 && $e3['status'] === 500 && str_contains($e1['corpo'], 'Não foi possível carregar os logs agora') && $erroLimpo([$e1, $e2, $e3]));
        afirmar("erro de carga ($nome): sem aviso de retencao, sem tabela/abas/filtros (so o aviso de erro)", !str_contains($e1['corpo'], 'logs-aviso-retencao') && !str_contains($e1['corpo'], 'mantidos por 90 dias') && !str_contains($e1['corpo'], 'logs-tabela') && !str_contains($e1['corpo'], 'logs-abas') && !str_contains($e2['corpo'], 'mantidos por 90 dias') && str_contains($e1['corpo'], 'logs-erro-carga'));
        afirmar("erro de carga ($nome): log do PHP so com a CLASSE (sem mensagem SQL nem nome de tabela)", !preg_match('/SQLSTATE|tb_log_sistema|tb_totem|tb_empresa|Base table|Unknown column|doesn\'t exist/i', $lg) && str_contains($lg, 'logs_carga_falhou') );
    }
    $ok = $raw('logs.php', $sidAdm, 'aba=api');
    afirmar('apos restaurar tudo a tela volta ao normal', $ok['status'] === 200 && in_array($idDet, ltIds($ok['corpo']), true));

    // privilegio revogado: usuario MySQL temporario SEM acesso a tb_log_sistema
    $config = qaQrConfiguracao();
    $pdoAdm = qaQrPdoServidor($config);
    $usuarioTemp = 'qa_tela_' . bin2hex(random_bytes(4));
    $senhaTemp = bin2hex(random_bytes(12));
    $privOk = false;
    try {
        $pdoAdm->exec("CREATE USER '$usuarioTemp'@'localhost' IDENTIFIED BY '$senhaTemp'");
        foreach (array_column(gtLinhas($pdo, 'SHOW TABLES'), 'Tables_in_' . $banco) as $t) {
            if ($t !== 'tb_log_sistema') {
                $pdoAdm->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$banco`.`$t` TO '$usuarioTemp'@'localhost'");
            }
        }
        $privOk = true;
    } catch (Throwable $e) {
        echo 'INFO - nao foi possivel criar usuario MySQL temporario (' . get_class($e) . "): cenario de privilegio revogado nao executado\n";
    }
    if ($privOk) {
        $envPriv = ['DB_USER' => $usuarioTemp, 'DB_PASS' => $senhaTemp];
        $lPriv = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => $novoIp(), 'env' => $envPriv]);
        afirmar('privilegio: usuario MySQL restrito consegue logar na gestao (sem acesso so a tb_log_sistema)', $lPriv['sid'] !== null);
        @unlink($raizLog);
        $p1 = $req(['arquivo' => 'logs.php', 'cookies' => ['gestao_sid' => (string) $lPriv['sid']], 'env' => $envPriv]);
        $p2 = $req(['arquivo' => 'log.php', 'cookies' => ['gestao_sid' => (string) $lPriv['sid']], 'env' => $envPriv, 'cabecalhos' => ['QUERY_STRING' => 'id=' . $idDet]]);
        $lg = (string) @file_get_contents($raizLog);
        afirmar('privilegio revogado (SELECT negado em tb_log_sistema): 500 com mensagem fixa, sem "denied"/tabela/usuario/SQLSTATE', $p1['status'] === 500 && $p2['status'] === 500 && $erroLimpo([$p1, $p2]) && !str_contains($p1['cru'], $usuarioTemp) && !str_contains($lg, $usuarioTemp) && !preg_match('/denied|SQLSTATE|tb_log_sistema/i', $lg));
    }

    // ------------------------------------------------------------------
    // 12. Concorrencia: escrita simultanea (DAO e LogSistema real) durante a carga
    // ------------------------------------------------------------------
    $pdo->exec('TRUNCATE TABLE tb_log_sistema');
    for ($i = 0; $i < 70; $i++) {
        $ins('INFO', 'API', 'erro_tecnico', 'Erro técnico inesperado.', null, null, 1, 'NOW() - INTERVAL ' . (100 + $i) . ' MINUTE');
    }
    $procs = [];
    foreach ([['--escritor', 600], ['--escritor', 600], ['--registrar', 40]] as [$modo, $n]) {
        $procs[] = proc_open([PHP_BINARY, __FILE__, $modo, $banco, (string) $n], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp2);
    }
    $respConc = [];
    $incoerente = 0;
    for ($i = 0; $i < 12; $i++) {
        $r = $raw('logs.php', $sidAdm, 'aba=api' . ($i % 3 === 1 ? '&pagina=2' : ''));
        $r2 = $raw('log.php', $sidAdm, 'id=' . (int) gtEscalar($pdo, 'SELECT MIN(id_log) FROM tb_log_sistema'));
        $respConc[] = [$r, $r2];
        $ids = ltIds($r['corpo']);
        if ($r['status'] !== 200 || count($ids) > 50 || count($ids) !== count(array_unique($ids)) || $r2['status'] !== 200) {
            $incoerente++;
        }
    }
    foreach ($procs as $p) {
        proc_close($p);
    }
    afirmar('concorrencia: 12 cargas durante escrita simultanea (2 escritores DAO + LogSistema real): sempre 200, <=50 linhas, sem id repetido, detalhe sempre abre', $incoerente === 0);
    $final = $raw('logs.php', $sidAdm, 'aba=api');
    $totalDb = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem='API'");
    $contAba = $contagens($final['corpo'])['api'];
    afirmar("concorrencia: depois da escrita a contagem da aba ($contAba) = banco ($totalDb) e o contador do cabecalho coerente", $contAba === $totalDb && str_contains($final['corpo'], 'de ' . $totalDb . ' registros.'));
    $somaContador = (int) gtEscalar($pdo, "SELECT SUM(contador) FROM tb_log_sistema WHERE dedup_chave IN (SELECT dedup_chave FROM (SELECT dedup_chave FROM tb_log_sistema) t) AND categoria='erro_tecnico' AND origem='API' AND detalhe IS NULL AND id_atendimento IS NULL");
    echo 'INFO - API: ' . $totalDb . ' linhas; soma de contadores das linhas do escritor DAO = ' . $somaContador . " (esperado >= 1200)\n";

    // ------------------------------------------------------------------
    // 13. Desempenho com 100 mil linhas
    // ------------------------------------------------------------------
    $pdo->exec('TRUNCATE TABLE tb_log_sistema');
    $d10 = '(SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9)';
    $t0 = microtime(true);
    $pdo->prepare("INSERT INTO tb_log_sistema (nivel, origem, categoria, mensagem, id_totem, detalhe, dedup_chave, janela, contador, criado_em, ultima_ocorrencia)
        SELECT ELT(1 + (n MOD 3), 'ERRO', 'AVISO', 'INFO'), ELT(1 + (n MOD 5), 'API', 'RECEBIMENTO', 'EXPEDICAO', 'CRON', 'GESTAO'),
               ELT(1 + (n MOD 4), 'erro_tecnico', 'totem_nao_autorizado', 'oc_consulta_falhou', 'cron_falhou'), 'Mensagem de carga.',
               CASE n MOD 4 WHEN 0 THEN :a WHEN 1 THEN :b WHEN 2 THEN NULL ELSE 987654 END, 'classe=PDOException;http=500', SHA1(CONCAT('perf', n)), NOW(), 1 + (n MOD 7), ts, ts
          FROM (SELECT n, NOW() - INTERVAL (n MOD 129000) MINUTE AS ts FROM (SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d AS n FROM $d10 a, $d10 b, $d10 c, $d10 e, $d10 f) y) x")
        ->execute(['a' => $tA, 'b' => $tB]);
    $linhas = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema');
    $pdo->query('ANALYZE TABLE tb_log_sistema')->fetchAll();
    echo 'INFO - carga de ' . $linhas . ' linhas em ' . round((microtime(true) - $t0) * 1000) . " ms\n";
    afirmar('desempenho: 100000 linhas carregadas', $linhas === 100000);
    $dao = new LogSistemaDao($pdo);
    $cron = static function (callable $f): float {
        $t = microtime(true);
        $f();

        return (microtime(true) - $t) * 1000;
    };
    $tListar = $cron(static fn () => $dao->listar(['origem' => 'API'], 1, 50));
    $tUltima = $cron(static fn () => $dao->listar(['origem' => 'API'], 400, 50));
    $tNivel = $cron(static fn () => $dao->listar(['origem' => 'API', 'nivel' => 'ERRO', 'categoria' => 'erro_tecnico'], 1, 50));
    $tTotem = $cron(static fn () => $dao->listar(['origem' => 'API', 'id_totem' => $tA], 1, 50));
    $tSemTotem = $cron(static fn () => $dao->listar(['origem' => 'API', 'sem_totem' => true], 1, 50));
    $tContarTodas = $cron(static fn () => $dao->contarPorAba([]));
    $iniD = $hoje->modify('-6 days')->format('Y-m-d');
    $tContarPer = $cron(static fn () => $dao->contarPorAba(['de' => $iniD, 'ate' => $hoje->format('Y-m-d')]));
    $tListarPer = $cron(static fn () => $dao->listar(['origem' => 'API', 'de' => $iniD, 'ate' => $hoje->format('Y-m-d')], 1, 50));
    printf("INFO - DAO (100k linhas, ms): listar=%.0f ultima_pagina=%.0f nivel+categoria=%.0f totem=%.0f sem_totem=%.0f listar_7d=%.0f contarPorAba=%.0f contarPorAba_7d=%.0f\n", $tListar, $tUltima, $tNivel, $tTotem, $tSemTotem, $tListarPer, $tContarTodas, $tContarPer);
    $expl = gtLinhas($pdo, "EXPLAIN SELECT origem, COUNT(*) FROM tb_log_sistema WHERE ultima_ocorrencia >= '2026-10-01 00:00:00' GROUP BY origem");
    echo 'INFO - EXPLAIN contarPorAba(periodo): key=' . ($expl[0]['key'] ?? 'NULL') . ' rows=' . ($expl[0]['rows'] ?? '?') . ' Extra=' . ($expl[0]['Extra'] ?? '') . "\n";
    $expl = gtLinhas($pdo, "EXPLAIN SELECT id_log FROM tb_log_sistema WHERE origem='API' ORDER BY ultima_ocorrencia DESC, id_log DESC LIMIT 50 OFFSET 19950");
    echo 'INFO - EXPLAIN listar(API): key=' . ($expl[0]['key'] ?? 'NULL') . ' rows=' . ($expl[0]['rows'] ?? '?') . ' Extra=' . ($expl[0]['Extra'] ?? '') . "\n";
    $mCgi = [];
    foreach (['pagina 1' => 'aba=api', 'ultima pagina' => 'aba=api&pagina=400', 'nivel+categoria' => 'aba=api&nivel=ERRO&categoria=erro_tecnico', 'periodo 7d' => 'aba=api&de=' . $iniD . '&ate=' . $hoje->format('Y-m-d'), 'totem' => 'aba=api&totem=' . $tA, 'cron' => 'aba=cron'] as $nome => $qs) {
        $t = microtime(true);
        $r = $raw('logs.php', $sidAdm, $qs);
        $mCgi[$nome] = [(microtime(true) - $t) * 1000, $r['status'], count(ltIds($r['corpo']))];
    }
    $tVazio = microtime(true);
    $raw('conta.php', $sidAdm, '');
    $tVazio = (microtime(true) - $tVazio) * 1000;
    $linhasCgi = [];
    foreach ($mCgi as $nome => [$ms, $st, $nl]) {
        $linhasCgi[] = sprintf('%s=%.0f(%d,%d)', $nome, $ms, $st, $nl);
    }
    echo 'INFO - CGI logs.php com 100k (ms,status,linhas): ' . implode(' ', $linhasCgi) . sprintf(' | referencia conta.php=%.0f', $tVazio) . "\n";
    $rD = $raw('log.php', $sidAdm, 'id=' . (int) gtEscalar($pdo, 'SELECT id_log FROM tb_log_sistema ORDER BY id_log DESC LIMIT 1'));
    afirmar('desempenho: DAO (listar/contagens/filtros) cada consulta < 1500 ms com 100k linhas', max($tListar, $tUltima, $tNivel, $tTotem, $tSemTotem, $tContarTodas, $tContarPer, $tListarPer) < 1500);
    afirmar('desempenho: cada pagina logs.php < 4000 ms por php-cgi com 100k linhas (inclui startup do CGI)', max(array_column($mCgi, 0)) < 4000 && array_reduce($mCgi, static fn ($ok, $x) => $ok && $x[1] === 200, true));
    afirmar('desempenho: ultima pagina (offset 19950) correta e detalhe do ultimo id abre', $mCgi['ultima pagina'][2] === 50 && $rD['status'] === 200);
    $tc = [];
    for ($i = 0; $i < 3; $i++) {
        $t = microtime(true);
        $raw('logs.php', $sidAdm, 'aba=api');
        $tc[] = (microtime(true) - $t) * 1000;
    }
    printf("INFO - 3 cargas seguidas da lista padrao com 100k (ms): %s\n", implode(', ', array_map(static fn ($x) => (string) round($x), $tc)));
} catch (Throwable $e) {
    afirmar('execucao sem excecao nao tratada (' . get_class($e) . ' em linha ' . $e->getLine() . ')', false);
    echo substr($e->getMessage(), 0, 300) . "\n";
} finally {
    if ($usuarioTemp !== null && $pdoAdm instanceof PDO) {
        try {
            $pdoAdm->exec("DROP USER IF EXISTS '$usuarioTemp'@'localhost'");
        } catch (Throwable $e) {
            echo 'AVISO - nao removeu o usuario MySQL temporario ' . $usuarioTemp . "\n";
        }
    }
    gtDestruirAmbiente($banco, $storage);
    @unlink($raizLog);
}

exit(gtResumo('teste_gestao_qa_logs_tela'));
