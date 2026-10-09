<?php

/**
 * Gestao Totem, F3c (TELA DE LOGS, 2026-10-08): paginas REAIS `logs.php` e `log.php`
 * por php-cgi (RBAC, so GET, abas, contagens por aba so pelo periodo, filtros por
 * whitelist e injecao, datas, paginacao de 50 com teto de pagina, ordenacao fixa,
 * coluna Totem so nas abas com totem, XSS, detalhe, "Voltar" so com filtros validados,
 * nada de codigo/token do totem, sem inline, no-store/CSP, erro de carga sem detalhe
 * tecnico, abrir a tela nao audita nem altera nada) e os metodos novos do
 * LogSistemaDao (buscarPorId, totensParaFiltro, sem_totem). Banco QA descartavel;
 * NUNCA udlog_totem.
 *
 * Uso: php tests/manual/teste_gestao_logs.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\LogSistemaDao;

$banco = null;
$storage = null;
$log = gtNovoLogCgi();
@unlink($log);
$raiz = dirname(__DIR__, 2);

function h2(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return list<int> ids (data-id-log) das linhas da tabela, na ordem */
function idsDasLinhas(string $corpo): array
{
    preg_match_all('/<tr class="gestao-tabela__linha" data-id-log="(\d+)" data-nivel="(erro|aviso|info)"/', $corpo, $m);

    return array_map('intval', $m[1]);
}

/** @return list<string> niveis (data-nivel) das linhas */
function niveisDasLinhas(string $corpo): array
{
    preg_match_all('/<tr class="gestao-tabela__linha" data-id-log="\d+" data-nivel="(erro|aviso|info)"/', $corpo, $m);

    return $m[1];
}

function semInlineLogs(string $html): bool
{
    if (preg_match('/<style\b/i', $html) === 1 || preg_match('/\sstyle\s*=/i', $html) === 1 || preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html) === 1) {
        return false;
    }
    if (preg_match_all('/<script\b([^>]*)>/i', $html, $m) > 0) {
        foreach ($m[1] as $a) {
            if (preg_match('/\bsrc="\/gestao\/assets\/gestao\.js\?v=\d+"/', $a) !== 1) {
                return false;
            }
        }
    }

    return true;
}

/** query do link "Voltar" (decodificada) como array */
function voltarQuery(string $corpo): ?array
{
    if (preg_match('/id="log-voltar" href="([^"]*)"/', $corpo, $m) !== 1) {
        return null;
    }
    $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    if (!str_starts_with($href, '/gestao/logs.php')) {
        return null;
    }
    $q = parse_url($href, PHP_URL_QUERY);
    parse_str((string) $q, $arr);

    return $arr;
}

try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idUsu = gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');

    // ------------------------------------------------------------------
    // Semeadura (direto no banco)
    // ------------------------------------------------------------------
    $emp = static function (string $nome, string $cnpj) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES (:n, :c, 1)')->execute(['n' => $nome, 'c' => $cnpj]);

        return (int) $pdo->lastInsertId();
    };
    $eMaua1 = $emp('Maua I', '14706199000182');
    $eMaua2 = $emp('Maua II', '14706199000344');
    $codigoA = 'GUICHE-04-MAUAI-K7QX2M5PDW4RJT3A';
    $codigoB = 'DOCA-07-MAUAII-ZZ5QW2M5PDW4RJ7B';
    $codigoX = 'XSSTOTEM-MAUAI-AB2CD3EF4GH5IJ6K';
    $tokenA = bin2hex(random_bytes(32));
    $tokenB = bin2hex(random_bytes(32));
    $tokenX = bin2hex(random_bytes(32));
    $totem = static function (string $codigo, string $nome, int $empresa, string $token) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES (:c, :n, :e, :t, 1)')->execute(['c' => $codigo, 'n' => $nome, 'e' => $empresa, 't' => $token]);

        return (int) $pdo->lastInsertId();
    };
    $tA = $totem($codigoA, 'GUICHE-04', $eMaua1, $tokenA);
    $tB = $totem($codigoB, 'DOCA-07', $eMaua2, $tokenB);
    $tX = $totem($codigoX, '<b>"X"&\'', $eMaua1, $tokenX);
    $segredos = [$codigoA, $codigoB, $codigoX, $tokenA, $tokenB, $tokenX, 'K7QX2M5PDW4RJT3A'];

    $seq = 0;
    $ins = static function (string $nivel, string $origem, string $cat, string $msg, ?int $idTotem, ?string $detalhe, int $contador, string $ultimaSql) use ($pdo, &$seq): int {
        $seq++;
        $pdo->prepare(
            "INSERT INTO tb_log_sistema (nivel, origem, categoria, mensagem, id_totem, detalhe, dedup_chave, janela, contador, criado_em, ultima_ocorrencia)
             VALUES (:n, :o, :c, :m, :t, :d, :k, NOW(), :ct, $ultimaSql, $ultimaSql)"
        )->execute(['n' => $nivel, 'o' => $origem, 'c' => $cat, 'm' => $msg, 't' => $idTotem, 'd' => $detalhe, 'k' => sha1('t' . $seq), 'ct' => $contador]);

        return (int) $pdo->lastInsertId();
    };
    $msgErro = 'Erro técnico inesperado.';
    $hostilMsg = '<script>alert(1)</script>"\'&<img src=x onerror=alert(2)>';
    $hostilDet = 'classe=<img src=x onerror=alert(3)>;http=500;motivo="><svg onload=alert(4)>';
    $idHostil = $ins('ERRO', 'API', 'erro_tecnico', $hostilMsg, null, $hostilDet, 3, 'NOW()');
    $totemsCiclo = [0 => $tA, 1 => $tB, 2 => null, 3 => $tX];
    $niveisCiclo = ['ERRO', 'AVISO', 'INFO'];
    for ($i = 1; $i <= 120; $i++) {
        $ins($niveisCiclo[$i % 3], 'API', $i % 2 === 0 ? 'erro_tecnico' : 'totem_nao_autorizado', $i % 2 === 0 ? $msgErro : 'Requisição à API com token de totem informado e inválido.', $totemsCiclo[$i % 4], $i % 7 === 0 ? 'classe=PDOException;sqlstate=HY000;http=500' : null, ($i % 5) + 1, "NOW() - INTERVAL $i MINUTE");
    }
    $idAntigo = $ins('ERRO', 'API', 'erro_tecnico', $msgErro, $tA, null, 1, 'NOW() - INTERVAL 30 DAY');
    for ($i = 1; $i <= 3; $i++) {
        $ins('AVISO', 'RECEBIMENTO', 'rate_limit_ocr_excedido', 'Limite de leitura de notas excedido pelo totem.', $tA, null, $i, "NOW() - INTERVAL $i HOUR");
    }
    $idExpB = $ins('ERRO', 'EXPEDICAO', 'oc_consulta_falhou', 'Falha ao consultar a ordem de coleta.', $tB, 'classe=PDOException;http=500;motivo=timeout', 2, 'NOW() - INTERVAL 5 MINUTE');
    $ins('ERRO', 'EXPEDICAO', 'oc_baixa_falhou', 'Falha ao dar baixa na ordem de coleta.', null, null, 1, 'NOW() - INTERVAL 6 MINUTE');
    $idCronInfo = $ins('INFO', 'CRON', 'cron_resumo', 'Rotina agendada concluída.', null, 'job=limpar_logs_gestao;logs_apagados=3;auditoria_apagados=0;lotes=1', 1, 'NOW() - INTERVAL 2 HOUR');
    $ins('ERRO', 'CRON', 'cron_falhou', 'Rotina agendada falhou.', null, 'classe=PDOException;job=limpar_logs_gestao;motivo=erro_banco', 1, 'NOW() - INTERVAL 3 HOUR');
    $totalApi = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API'");
    afirmar('semeadura: API tem 122 linhas (3 paginas de 50), GESTAO esta vazia', $totalApi === 122 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'GESTAO'") === 0);

    $semComentarios = static function (string $arquivo): string {
        $saida = '';
        foreach (token_get_all((string) file_get_contents($arquivo)) as $t) {
            if (is_array($t)) {
                if ($t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT) {
                    $saida .= $t[1];
                }
            } else {
                $saida .= $t;
            }
        }

        return $saida;
    };
    $snapLogs = static fn (): string => md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_log_sistema ORDER BY id_log')));
    $snapAud = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria');
    $snapTotens = static fn (): string => md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_totem ORDER BY id_totem')));

    // ------------------------------------------------------------------
    // A. LogSistemaDao: metodos novos (em processo)
    // ------------------------------------------------------------------
    $dao = new LogSistemaDao($pdo);
    $r = $dao->buscarPorId($idExpB);
    afirmar('dao: buscarPorId devolve o registro com nome do totem e da empresa', $r !== null && $r['totem_nome'] === 'DOCA-07' && $r['totem_empresa'] === 'Maua II' && $r['categoria'] === 'oc_consulta_falhou');
    afirmar('dao: buscarPorId NAO traz codigo nem token_api do totem', $r !== null && !array_key_exists('codigo', $r) && !array_key_exists('token_api', $r) && !str_contains(json_encode($r), $codigoB) && !str_contains(json_encode($r), $tokenB));
    afirmar('dao: buscarPorId inexistente => null; id 0/negativo => InvalidArgumentException', $dao->buscarPorId(99999999) === null && (static function () use ($dao): bool {
        foreach ([0, -1] as $id) {
            try {
                $dao->buscarPorId($id);

                return false;
            } catch (InvalidArgumentException $e) {
            }
        }

        return true;
    })());
    $lt = $dao->totensParaFiltro();
    afirmar('dao: totensParaFiltro devolve so id_totem, nome e empresa_nome (3 totens), nunca codigo/token', count($lt) === 3 && array_reduce($lt, static fn ($c, $l) => $c && array_keys($l) === ['id_totem', 'nome', 'empresa_nome'], true) && !str_contains(json_encode($lt), 'K7QX') && !str_contains(json_encode($lt), $tokenA));
    $semTotem = $dao->listar(['origem' => 'API', 'sem_totem' => true], 1, 200);
    afirmar('dao: filtro sem_totem=true traz so linhas com id_totem NULL', $semTotem['total'] === (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API' AND id_totem IS NULL") && array_reduce($semTotem['itens'], static fn ($c, $l) => $c && $l['id_totem'] === null, true));
    $okSemTotem = true;
    foreach ([false, 1, 'sim', 'true', ['x']] as $v) {
        try {
            $dao->listar(['sem_totem' => $v]);
            $okSemTotem = false;
        } catch (InvalidArgumentException $e) {
        }
    }
    afirmar('dao: sem_totem so aceita o booleano true (false, 1, texto e array => InvalidArgumentException)', $okSemTotem);
    afirmar('dao: contarPorAba com so periodo conta todas as abas', $dao->contarPorAba() === ['API' => 122, 'RECEBIMENTO' => 3, 'EXPEDICAO' => 2, 'CRON' => 2, 'GESTAO' => 0]);

    // ------------------------------------------------------------------
    // B. Paginas por php-cgi
    // ------------------------------------------------------------------
    $H = ['logErro' => $log];
    $todas = [];
    $req = static function (array $o) use (&$todas, $H): array {
        $resp = gtChamar($o + $H);
        $todas[] = $resp;

        return $resp;
    };
    $ck = static fn (?array $l): array => $l !== null && $l['sid'] !== null ? ['cookies' => ['gestao_sid' => $l['sid']]] : [];
    $get = static fn (string $arq, ?array $l, array $q = [], array $extra = []): array => $req(['arquivo' => $arq, 'query' => $q] + $ck($l) + $extra);
    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => '192.0.2.41']);
    $lUsu = gtLogin('carla.usuario', GT_SENHA_BOA, ['ip' => '192.0.2.42']);
    $antesLogs = $snapLogs();
    $antesAud = $snapAud();
    $antesTotens = $snapTotens();
    afirmar('sessoes de teste abertas (admin e usuario) com CSRF', $lAdm['sid'] !== null && $lAdm['csrf'] !== null && $lUsu['sid'] !== null && $lUsu['csrf'] !== null);
    $loc = static fn (array $r): string => (string) gtCabecalho($r, 'location');

    // --- RBAC e metodos
    foreach ([['logs.php', []], ['log.php', ['id' => (string) $idHostil]]] as [$arq, $q]) {
        $ra = $get($arq, null, $q);
        afirmar("RBAC $arq: anonimo => redireciona ao login, sem conteudo de log", in_array($ra['status'], [302, 401], true) && !str_contains($ra['corpo'], 'Erro técnico') && !str_contains($ra['corpo'], 'logs-tabela'));
        $ru = $get($arq, $lUsu, $q);
        afirmar("RBAC $arq: perfil usuario => 403 sem conteudo de log", $ru['status'] === 403 && !str_contains($ru['corpo'], 'Erro técnico') && !str_contains($ru['corpo'], 'logs-tabela') && !str_contains($ru['corpo'], 'log-detalhe'));
        $rad = $get($arq, $lAdm, $q);
        afirmar("RBAC $arq: admin => 200 com no-store, X-Frame-Options e CSP", $rad['status'] === 200 && gtCabecalho($rad, 'cache-control') === 'no-store, private' && gtCabecalho($rad, 'x-frame-options') === 'DENY' && gtCabecalho($rad, 'content-security-policy') !== null);
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $metodo) {
            $pm = $req(['arquivo' => $arq, 'metodo' => $metodo, 'query' => $q, 'form' => ['csrf_token' => (string) $lAdm['csrf']]] + $ck($lAdm));
            afirmar("metodo $metodo em $arq (admin) => 405 com Allow: GET", $pm['status'] === 405 && stripos((string) gtCabecalho($pm, 'allow'), 'GET') !== false && !str_contains($pm['corpo'], 'logs-tabela'));
        }
        $pmA = $req(['arquivo' => $arq, 'metodo' => 'POST', 'query' => $q, 'form' => ['x' => '1']]);
        afirmar("metodo POST em $arq (anonimo) => 405 sem conteudo de log", $pmA['status'] === 405 && !str_contains($pmA['corpo'], 'Erro técnico'));
    }

    // --- pagina padrao: contrato de ids
    $p = $get('logs.php', $lAdm);
    $c = $p['corpo'];
    afirmar('padrao: aba API, titulo "Logs", retencao permanente com o texto exato e acentuacao', $p['status'] === 200 && str_contains($c, 'id="logs-aviso-retencao"') && str_contains($c, 'Os logs são mantidos por 90 dias. Registros mais antigos são apagados automaticamente.') && str_contains($c, '<title>Logs - Gestão Totem</title>'));
    afirmar('padrao: nav#logs-abas com aria-label, ul SEM role=tablist e 5 links (#aba-*) sem role=tab/aria-controls/aria-selected', str_contains($c, '<nav class="gestao-abas" id="logs-abas" aria-label="Categorias de logs">') && !str_contains($c, 'role="tablist"') && !str_contains($c, 'role="tab"') && !str_contains($c, 'role="presentation"') && !str_contains($c, 'aria-controls=') && !str_contains($c, 'aria-selected=') && !str_contains($c, 'aria-labelledby="aba-') && preg_match('/id="aba-api".*id="aba-recebimento".*id="aba-expedicao".*id="aba-cron".*id="aba-gestao"/s', $c) === 1);
    afirmar('padrao: SEM aba "Todas"', !str_contains($c, 'Todas as abas') && !str_contains($c, 'id="aba-todas"') && !preg_match('/>\s*Todas\s*</', $c));
    afirmar('padrao: aba ativa = API (so ela tem aria-current=page, dentro do nav)', preg_match('/id="aba-api"[^>]*aria-current="page"/', $c) === 1 && substr_count((string) (preg_match('/<nav class="gestao-abas".*?<\/nav>/s', $c, $mNav) ? $mNav[0] : ''), 'aria-current="page"') === 1);
    afirmar('padrao: contagem visivel (aria-hidden) + texto .gestao-sr dentro do link, sem aria-label na contagem', str_contains($c, 'class="gestao-aba__contagem" aria-hidden="true">122</span><span class="gestao-sr"> (122 registros)</span>') && str_contains($c, '>3</span><span class="gestao-sr"> (3 registros)</span>') && str_contains($c, '>2</span><span class="gestao-sr"> (2 registros)</span>') && str_contains($c, '>0</span><span class="gestao-sr"> (0 registros)</span>') && !str_contains($c, 'gestao-aba__contagem" aria-label'));
    afirmar('padrao: #logs-painel sem role=tabpanel e #logs-descricao com o texto da aba API', str_contains($c, 'id="logs-painel">') && !str_contains($c, 'tabpanel') && str_contains($c, 'id="logs-descricao">Falhas das integrações e da infraestrutura (Talent, VIO, n8n, banco de dados, serviço de impressão) que não pertencem a um atendimento.<'));
    afirmar('padrao: form#logs-filtros method=get role=search action logs.php e hidden aba #logs-aba-campo', preg_match('/<form class="gestao-filtros" id="logs-filtros" method="get" action="\/gestao\/logs\.php" role="search"/', $c) === 1 && str_contains($c, 'id="logs-aba-campo" value="api"'));
    foreach (['filtro-nivel' => 'nivel', 'filtro-periodo-de' => 'de', 'filtro-periodo-ate' => 'ate', 'filtro-totem' => 'totem', 'filtro-categoria' => 'categoria'] as $idCampo => $nome) {
        afirmar("padrao: campo #$idCampo (name=$nome) com label for", preg_match('/id="' . $idCampo . '" name="' . $nome . '"/', $c) === 1 && str_contains($c, 'for="' . $idCampo . '"'));
    }
    afirmar('padrao: atalhos #periodo-hoje/#periodo-7d/#periodo-90d, #btn-aplicar-filtros e #btn-limpar-filtros (href ?aba=api)', str_contains($c, 'id="periodo-hoje"') && str_contains($c, 'id="periodo-7d"') && str_contains($c, 'id="periodo-90d"') && str_contains($c, 'id="btn-aplicar-filtros"') && str_contains($c, 'id="btn-limpar-filtros" href="/gestao/logs.php?aba=api"'));
    afirmar('padrao: sem atalho ativo (nenhum aria-current="true")', !str_contains($c, 'aria-current="true"'));
    afirmar('padrao: categoria na lista com <wbr> depois de cada _ (erro_<wbr>tecnico), escapada', str_contains($c, '<td class="col-categoria">erro_<wbr>tecnico</td>') && !str_contains($c, '&lt;wbr'));
    afirmar('padrao: aviso de retencao presente quando nao ha erro de carga', str_contains($c, 'id="logs-aviso-retencao"'));
    afirmar('padrao: #logs-contador role=status "Exibindo 1 a 50 de 122 registros."', str_contains($c, 'id="logs-contador" role="status">Exibindo 1 a 50 de 122 registros.<'));
    afirmar('padrao: table#logs-tabela.gestao-tabela--logs em .gestao-tabela-wrap com caption e th scope=col', str_contains($c, 'class="gestao-tabela-wrap"') && str_contains($c, 'id="logs-tabela"') && str_contains($c, 'gestao-tabela--logs') && str_contains($c, '<caption') && substr_count($c, '<th scope="col"') === 7);
    afirmar('padrao: cabecalho Nível | Quando | Totem | Categoria | Mensagem | Repetições | Detalhe, nessa ordem', preg_match('/Nível<.*Quando<.*Totem<.*Categoria<.*Mensagem<.*Repetições<.*Detalhe</s', $c) === 1);
    $ids1 = idsDasLinhas($c);
    afirmar('padrao: 50 linhas na pagina 1, com data-id-log e data-nivel', count($ids1) === 50);
    afirmar('padrao: celulas .col-nivel .col-quando .col-totem .col-categoria .col-mensagem .col-repeticoes .col-detalhe presentes 50x', array_reduce(['col-nivel', 'col-quando', 'col-totem', 'col-categoria', 'col-mensagem', 'col-repeticoes', 'col-detalhe'], static fn ($ok, $cl) => $ok && substr_count($c, '<td class="' . $cl . '">') === 50, true));
    afirmar('padrao: nivel com rotulo textual (Erro/Aviso/Info) em span.gestao-nivel--erro|aviso|info com icone', str_contains($c, 'class="gestao-nivel gestao-nivel--erro"') && str_contains($c, 'class="gestao-nivel gestao-nivel--aviso"') && str_contains($c, 'class="gestao-nivel gestao-nivel--info"') && str_contains($c, '<span>Erro</span>') && str_contains($c, '<span>Aviso</span>') && str_contains($c, '<span>Info</span>') && str_contains($c, '#i-erro'));
    afirmar('padrao: repeticoes "xN" com texto acessivel "N ocorrencias" (gestao-sr)', str_contains($c, '<span aria-hidden="true">x3</span><span class="gestao-sr">3 ocorrências</span>') && str_contains($c, '<span class="gestao-sr">1 ocorrência</span>'));
    afirmar('padrao: link "Ver detalhe" com aria-label descritivo e href log.php?id=N&aba=...', preg_match('/<a class="gestao-botao[^"]*" href="\/gestao\/log\.php\?id=' . $idHostil . '&amp;aba=api" aria-label="Ver detalhe do registro erro_tecnico, Erro, última ocorrência em \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}"><span>Ver detalhe<\/span><\/a>/u', $c) === 1);
    afirmar('padrao: paginacao nav#logs-paginacao aria-label, #pag-anterior desabilitado, #pag-posicao "Página 1 de 3", #pag-proxima link', str_contains($c, 'id="logs-paginacao" aria-label="Paginação"') && preg_match('/<span[^>]*id="pag-anterior" aria-disabled="true">/', $c) === 1 && str_contains($c, 'id="pag-posicao">Página 1 de 3<') && str_contains($c, 'id="pag-proxima" href="/gestao/logs.php?aba=api&amp;pagina=2"'));
    afirmar('padrao: sem busca de texto livre (nenhum input type=text/search) e sem coluna/exportacao/revelar', !preg_match('/<input[^>]*type="(text|search)"/', $c) && !str_contains($c, 'Exportar') && !str_contains($c, 'Revelar') && !str_contains($c, 'Excluir') && !str_contains($c, 'name="q"'));
    afirmar('padrao: totem na coluna usa nome (empresa) e "Sem totem"; nunca codigo/token/URL', str_contains($c, 'GUICHE-04 (Maua I)') && str_contains($c, 'Sem totem') && array_reduce($segredos, static fn ($ok, $s) => $ok && !str_contains($c, $s), true));
    afirmar('padrao: ordenacao fixa por ultima_ocorrencia DESC (ids da pagina = consulta do banco)', $ids1 === array_map('intval', array_column(gtLinhas($pdo, "SELECT id_log FROM tb_log_sistema WHERE origem = 'API' ORDER BY ultima_ocorrencia DESC, id_log DESC LIMIT 50"), 'id_log')) && $ids1[0] === $idHostil);
    afirmar('padrao: opcoes do filtro de totem = nome (empresa) com id; o totem hostil sai escapado', str_contains($c, '>GUICHE-04 (Maua I)<') && str_contains($c, '>DOCA-07 (Maua II)<') && str_contains($c, '&lt;b&gt;&quot;X&quot;&amp;&#039; (Maua I)') && !str_contains($c, '<b>"X"'));
    afirmar('padrao: opcoes de categoria da aba API vem do catalogo (so categorias de API e por_tipo)', str_contains($c, 'value="erro_tecnico"') && str_contains($c, 'value="totem_nao_autorizado"') && !str_contains($c, 'value="cron_resumo"') && !str_contains($c, 'value="gestao_erro_interno"'));
    afirmar('padrao: sem inline, com acentuacao correta e sem mojibake', semInlineLogs($c) && !str_contains($c, 'Ã') && str_contains($c, 'Expedição') && str_contains($c, 'Gestão') && str_contains($c, 'Aplicar filtros'));
    afirmar('menu: Logs aparece no menu do admin com aria-current na pagina; usuario nao ve Logs', preg_match('/<li class="gestao-menu__item gestao-menu__item--atual"><a class="gestao-menu__link" href="\/gestao\/logs\.php"[^>]*aria-current="page"/', $c) === 1 && !str_contains($get('conta.php', $lUsu)['corpo'], '/gestao/logs.php'));
    afirmar('sprite: simbolo i-erro (circulo com X) existe', str_contains($c, '<symbol id="i-erro"'));

    // --- abas: links GET, descricoes, colunas
    foreach (['recebimento' => ['Recebimento', 3, 'Erros e avisos de atendimentos de recebimento.', true], 'expedicao' => ['Expedição', 2, 'Erros e avisos de atendimentos de expedição.', true], 'cron' => ['Cron', 2, 'Rotinas automáticas (cron) e suas falhas.', false], 'gestao' => ['Gestão', 0, 'Ações e falhas da própria Gestão Totem.', false]] as $slug => [$rot, $qtd, $desc, $comTotem]) {
        $pa = $get('logs.php', $lAdm, ['aba' => $slug]);
        $ca = $pa['corpo'];
        afirmar("aba $slug: 200, ativa, descricao correta, $qtd linhas", $pa['status'] === 200 && preg_match('/id="aba-' . $slug . '"[^>]*aria-current="page"/', $ca) === 1 && str_contains($ca, '>' . $desc . '<') && count(idsDasLinhas($ca)) === $qtd && str_contains($ca, 'id="logs-aba-campo" value="' . $slug . '"'));
        afirmar("aba $slug: coluna e filtro Totem " . ($comTotem ? 'presentes' : 'ESCONDIDOS (sem th/td/select)'), $comTotem ? (str_contains($ca, 'class="col-totem"') && str_contains($ca, 'id="filtro-totem"')) : (!str_contains($ca, 'col-totem') && !str_contains($ca, 'id="filtro-totem"') && !str_contains($ca, '>Totem<')));
    }
    $pCron = $get('logs.php', $lAdm, ['aba' => 'cron']);
    afirmar('aba cron: categorias so de CRON no filtro e mensagem do catalogo na tabela', str_contains($pCron['corpo'], 'value="cron_resumo"') && str_contains($pCron['corpo'], 'value="cron_falhou"') && !str_contains($pCron['corpo'], 'value="erro_tecnico"') && str_contains($pCron['corpo'], 'Rotina agendada concluída.') && semInlineLogs($pCron['corpo']));
    $pExp = $get('logs.php', $lAdm, ['aba' => 'expedicao']);
    afirmar('aba expedicao: categorias de EXPEDICAO e por_tipo (oc_*, erro_tecnico), nao as de CRON', str_contains($pExp['corpo'], 'value="oc_consulta_falhou"') && str_contains($pExp['corpo'], 'value="erro_tecnico"') && !str_contains($pExp['corpo'], 'value="cron_resumo"') && !str_contains($pExp['corpo'], 'value="rate_limit_ocr_excedido"'));
    afirmar('abas: os links das abas sao GET (?aba=) e nao carregam filtros que nao sao do periodo', str_contains($pExp['corpo'], 'id="aba-cron" href="/gestao/logs.php?aba=cron"'));
    $hojeTz = new DateTimeImmutable('today', new DateTimeZone('-03:00'));
    $q5 = $hojeTz->modify('-5 days')->format('Y-m-d');
    $pTabFiltro = $get('logs.php', $lAdm, ['aba' => 'api', 'nivel' => 'ERRO', 'categoria' => 'erro_tecnico', 'totem' => (string) $tA, 'de' => $q5, 'ate' => $hojeTz->format('Y-m-d')]);
    afirmar('abas: o link de outra aba preserva SO o periodo (de/ate), nunca nivel/totem/categoria', preg_match('#id="aba-cron" href="/gestao/logs\.php\?aba=cron&amp;de=' . $q5 . '&amp;ate=\d{4}-\d{2}-\d{2}"#', $pTabFiltro['corpo']) === 1);
    $pAbaInv = $get('logs.php', $lAdm, ['aba' => "api' OR '1'='1"]);
    $pAbaInv2 = $get('logs.php', $lAdm, ['aba' => 'todas']);
    $pAbaArr = $req(['arquivo' => 'logs.php', 'query' => ['aba' => ['api']]] + $ck($lAdm));
    afirmar('aba invalida (injecao, "todas", array) => cai na API (padrao), sem erro', $pAbaInv['status'] === 200 && idsDasLinhas($pAbaInv['corpo']) === $ids1 && $pAbaInv2['status'] === 200 && idsDasLinhas($pAbaInv2['corpo']) === $ids1 && $pAbaArr['status'] === 200 && idsDasLinhas($pAbaArr['corpo']) === $ids1);

    // --- nivel, categoria, totem
    $pN = $get('logs.php', $lAdm, ['nivel' => 'ERRO', 'pagina' => '1']);
    $qErro = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API' AND nivel = 'ERRO'");
    afirmar("nivel=ERRO: so linhas de erro, contador do banco ($qErro), select marcado, contagem da aba NAO muda", $pN['status'] === 200 && array_unique(niveisDasLinhas($pN['corpo'])) === ['erro'] && str_contains($pN['corpo'], 'de ' . $qErro . ' registros.') && str_contains($pN['corpo'], '<option value="ERRO" selected>') && str_contains($pN['corpo'], '> (122 registros)<'));
    foreach (['erro', 'Erro', 'FATAL', "ERRO' OR '1'='1", 'ERRO;DROP TABLE tb_log_sistema', '<script>', "ERRO\0", '%00'] as $nv) {
        $pI = $get('logs.php', $lAdm, ['nivel' => $nv]);
        afirmar('nivel invalido ' . json_encode($nv) . ' => ignorado (lista completa, sem erro SQL)', $pI['status'] === 200 && idsDasLinhas($pI['corpo']) === $ids1 && !str_contains($pI['corpo'], 'SQLSTATE') && !str_contains($pI['corpo'], '<option value="ERRO" selected>'));
    }
    $pCat = $get('logs.php', $lAdm, ['categoria' => 'totem_nao_autorizado']);
    afirmar('categoria valida da aba => so essa categoria (mensagem do catalogo)', $pCat['status'] === 200 && str_contains($pCat['corpo'], 'de ' . (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API' AND categoria = 'totem_nao_autorizado'") . ' registros.') && !str_contains($pCat['corpo'], '<td class="col-categoria">erro_tecnico</td>') && str_contains($pCat['corpo'], '<option value="totem_nao_autorizado" selected>'));
    foreach (['cron_resumo', 'erro_tecnico OR 1=1', "erro_tecnico' --", 'ERRO_TECNICO', "erro_tecnico%", 'inexistente', '"><script>alert(1)</script>', '1; DROP TABLE tb_log_sistema'] as $ct) {
        $pI = $get('logs.php', $lAdm, ['categoria' => $ct]);
        afirmar('categoria invalida/de outra aba ' . json_encode($ct) . ' => ignorada', $pI['status'] === 200 && idsDasLinhas($pI['corpo']) === $ids1 && !str_contains($pI['corpo'], 'SQLSTATE') && !str_contains($pI['corpo'], '<script>alert(1)'));
    }
    $pTm = $get('logs.php', $lAdm, ['totem' => (string) $tA]);
    $qTm = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API' AND id_totem = $tA");
    afirmar("totem valido => so linhas do totem ($qTm), select marcado, nada de codigo/token", $pTm['status'] === 200 && str_contains($pTm['corpo'], 'de ' . $qTm . ' registros.') && str_contains($pTm['corpo'], 'value="' . $tA . '" selected>') && array_reduce($segredos, static fn ($ok, $s) => $ok && !str_contains($pTm['corpo'], $s), true) && array_unique(array_map(static fn ($id) => (int) gtEscalar($pdo, 'SELECT id_totem FROM tb_log_sistema WHERE id_log = :i', ['i' => $id]), idsDasLinhas($pTm['corpo']))) === [$tA]);
    $pSem = $get('logs.php', $lAdm, ['totem' => 'sem']);
    afirmar('totem=sem ("Sem totem") => so linhas sem totem', $pSem['status'] === 200 && str_contains($pSem['corpo'], 'de ' . (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API' AND id_totem IS NULL") . ' registros.') && str_contains($pSem['corpo'], '<option value="sem" selected>'));
    foreach (['999999', '0', '-1', '1 OR 1=1', "1' OR '1'='1", '1e3', '01', 'abc', 'SEM', '99999999999', "$tA OR 1=1", (string) $tA . ' '] as $tv) {
        $pI = $get('logs.php', $lAdm, ['totem' => $tv]);
        afirmar('totem invalido/inexistente ' . json_encode($tv) . ' => ignorado', $pI['status'] === 200 && idsDasLinhas($pI['corpo']) === $ids1 && !str_contains($pI['corpo'], 'SQLSTATE'));
    }
    $pTc = $get('logs.php', $lAdm, ['aba' => 'cron', 'totem' => (string) $tA]);
    afirmar('totem em aba sem totem (cron) => ignorado: mesmas 2 linhas', count(idsDasLinhas($pTc['corpo'])) === 2);
    $pOrd = $get('logs.php', $lAdm, ['ordem' => 'id_log; DROP TABLE tb_log_sistema', 'sort' => 'nivel', 'dir' => 'ASC', 'order' => 'contador', 'limite' => '5000', 'por_pagina' => '500', 'q' => "' OR 1=1 --", 'busca' => '%', 'origem' => 'CRON']);
    afirmar('ordenacao/limite/busca/origem vindos da URL sao IGNORADOS (mesma lista e ordem; 50 por pagina; tabela intacta)', $pOrd['status'] === 200 && idsDasLinhas($pOrd['corpo']) === $ids1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') > 0);

    // --- datas e periodo
    $hoje = (new DateTimeImmutable('today', new DateTimeZone('-03:00')));
    $iso = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d');
    $hojeIso = $iso($hoje);
    $qHoje = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API' AND ultima_ocorrencia >= :a AND ultima_ocorrencia < :b", ['a' => $hojeIso . ' 00:00:00', 'b' => $iso($hoje->modify('+1 day')) . ' 00:00:00']);
    $pHoje = $get('logs.php', $lAdm, ['de' => $hojeIso, 'ate' => $hojeIso]);
    afirmar("periodo hoje: conta so de hoje ($qHoje) e o atalho #periodo-hoje fica aria-current", $pHoje['status'] === 200 && str_contains($pHoje['corpo'], ($qHoje === 0 ? 'Nenhum registro' : 'de ' . $qHoje . ' registros')) && preg_match('/id="periodo-hoje" href="[^"]*" aria-current="true"/', $pHoje['corpo']) === 1 && substr_count($pHoje['corpo'], 'aria-current="true"') === 1);
    $d7 = $iso($hoje->modify('-6 days'));
    $p7 = $get('logs.php', $lAdm, ['de' => $d7, 'ate' => $hojeIso]);
    afirmar('periodo 7d: atalho #periodo-7d ativo e a linha de 30 dias atras NAO aparece', preg_match('/id="periodo-7d" href="[^"]*" aria-current="true"/', $p7['corpo']) === 1 && !in_array($idAntigo, idsDasLinhas($get('logs.php', $lAdm, ['de' => $d7, 'ate' => $hojeIso, 'pagina' => '3'])['corpo']), true));
    $d90 = $iso($hoje->modify('-89 days'));
    $p90 = $get('logs.php', $lAdm, ['de' => $d90, 'ate' => $hojeIso]);
    afirmar('periodo 90d: atalho #periodo-90d ativo; a linha de 30 dias esta incluida (total 122)', preg_match('/id="periodo-90d" href="[^"]*" aria-current="true"/', $p90['corpo']) === 1 && str_contains($p90['corpo'], 'de 122 registros.'));
    afirmar('atalhos: links GET montados com de/ate calculados no servidor (hoje, 7d, 90d)', str_contains($c, 'id="periodo-hoje" href="/gestao/logs.php?aba=api&amp;de=' . $hojeIso . '&amp;ate=' . $hojeIso . '"') && str_contains($c, 'id="periodo-7d" href="/gestao/logs.php?aba=api&amp;de=' . $d7 . '&amp;ate=' . $hojeIso . '"') && str_contains($c, 'id="periodo-90d" href="/gestao/logs.php?aba=api&amp;de=' . $d90 . '&amp;ate=' . $hojeIso . '"'));
    $contAbaPeriodo = $get('logs.php', $lAdm, ['de' => $d7, 'ate' => $hojeIso, 'nivel' => 'AVISO']);
    afirmar('contagem por aba usa SO o periodo: com nivel=AVISO + 7d a aba API mostra 121 (122 menos a de 30 dias), nao so os avisos', str_contains($contAbaPeriodo['corpo'], '> (121 registros)<'));
    $pDe = $get('logs.php', $lAdm, ['de' => $iso($hoje->modify('-90 days')), 'ate' => $hojeIso]);
    afirmar('periodo valido no limite (hoje-90 ate hoje) => aceito, sem erro', $pDe['status'] === 200 && !str_contains($pDe['corpo'], 'erro-periodo') && str_contains($pDe['corpo'], 'value="' . $iso($hoje->modify('-90 days')) . '"'));
    $casosData = [
        ['de', '2026-13-45', 'inválida'], ['de', '2026-02-30', 'inválida'], ['de', '2026-1-1', 'inválida'], ['de', "$hojeIso'--", 'inválida'], ['de', '<script>alert(1)</script>', 'inválida'], ['de', '20261008', 'inválida'], ['de', '08/10/2026', 'inválida'], ['de', "$hojeIso\n", 'inválida'], ['de', $iso($hoje->modify('-91 days')), 'anterior a'], ['de', '1999-01-01', 'anterior a'], ['de', $iso($hoje->modify('+1 day')), 'futura'],
        ['ate', 'abc', 'inválida'], ['ate', '2026-00-10', 'inválida'], ['ate', $iso($hoje->modify('+1 day')), 'futura'], ['ate', '2099-12-31', 'futura'], ['ate', $iso($hoje->modify('-91 days')), 'anterior a'],
    ];
    foreach ($casosData as [$campo, $valor, $trecho]) {
        $pD = $get('logs.php', $lAdm, [$campo => $valor]);
        $idErro = $campo === 'de' ? 'erro-periodo-de' : 'erro-periodo-ate';
        $idCampo = $campo === 'de' ? 'filtro-periodo-de' : 'filtro-periodo-ate';
        afirmar("data $campo=" . json_encode($valor) . ' => ignorada, mensagem no proprio campo (role=alert, aria-invalid), lista completa', $pD['status'] === 200 && preg_match('/id="' . $idErro . '" role="alert">.*' . preg_quote($trecho, '/') . '/su', $pD['corpo']) === 1 && str_contains($pD['corpo'], 'aria-describedby="' . $idErro . '"') && preg_match('/id="' . $idCampo . '" name="' . $campo . '" type="date" value=""/', $pD['corpo']) === 1 && idsDasLinhas($pD['corpo']) === $ids1 && !str_contains($pD['corpo'], '<script>alert(1)') && !str_contains($pD['corpo'], 'SQLSTATE'));
    }
    $pRev = $get('logs.php', $lAdm, ['de' => $hojeIso, 'ate' => $iso($hoje->modify('-3 days'))]);
    afirmar('intervalo invertido (de > ate) => os dois ignorados, mensagem no campo "ate", lista completa', $pRev['status'] === 200 && str_contains($pRev['corpo'], 'id="erro-periodo-ate" role="alert">') && str_contains($pRev['corpo'], 'anterior à inicial') && idsDasLinhas($pRev['corpo']) === $ids1 && preg_match('/name="de" type="date" value=""/', $pRev['corpo']) === 1);
    $pArrD = $req(['arquivo' => 'logs.php', 'query' => ['de' => ['x'], 'ate' => ['y']]] + $ck($lAdm));
    afirmar('de/ate como array => ignorados sem erro', $pArrD['status'] === 200 && idsDasLinhas($pArrD['corpo']) === $ids1);
    afirmar('campos de data trazem min (hoje-90) e max (hoje) para o navegador', str_contains($c, 'min="' . $iso($hoje->modify('-90 days')) . '" max="' . $hojeIso . '"'));

    // --- paginacao
    $p2 = $get('logs.php', $lAdm, ['pagina' => '2']);
    $p3 = $get('logs.php', $lAdm, ['pagina' => '3']);
    $ids2 = idsDasLinhas($p2['corpo']);
    $ids3 = idsDasLinhas($p3['corpo']);
    afirmar('paginacao: pagina 2 tem 50 linhas, "Exibindo 51 a 100 de 122", "Página 2 de 3", Anterior (prev) e Proxima (next) viram links', count($ids2) === 50 && str_contains($p2['corpo'], 'Exibindo 51 a 100 de 122 registros.') && str_contains($p2['corpo'], 'Página 2 de 3') && str_contains($p2['corpo'], 'id="pag-anterior" href="/gestao/logs.php?aba=api" rel="prev"') && str_contains($p2['corpo'], 'id="pag-proxima" href="/gestao/logs.php?aba=api&amp;pagina=3" rel="next"'));
    afirmar('paginacao: pagina 3 tem 22 linhas, "Exibindo 101 a 122 de 122", Proxima desabilitada; as 3 paginas nao repetem nem perdem linha (122 ids distintos)', count($ids3) === 22 && str_contains($p3['corpo'], 'Exibindo 101 a 122 de 122 registros.') && preg_match('/<span[^>]*id="pag-proxima" aria-disabled="true">/', $p3['corpo']) === 1 && count(array_unique(array_merge($ids1, $ids2, $ids3))) === 122 && end($ids3) === $idAntigo);
    $pf = $get('logs.php', $lAdm, ['nivel' => 'AVISO', 'pagina' => '2', 'totem' => 'sem', 'categoria' => 'totem_nao_autorizado', 'de' => $d90, 'ate' => $hojeIso]);
    $pfTotal = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE origem = 'API' AND nivel = 'AVISO' AND id_totem IS NULL AND categoria = 'totem_nao_autorizado'");
    afirmar("paginacao com filtros ($pfTotal registros): redireciona ou mostra pagina valida e os links carregam os filtros validados em ordem fixa", $pfTotal <= 50 ? ($pf['status'] === 302 && $loc($pf) === '/gestao/logs.php?aba=api&nivel=AVISO&de=' . $d90 . '&ate=' . $hojeIso . '&totem=sem&categoria=totem_nao_autorizado') : $pf['status'] === 200);
    foreach (['999', '5000', '9999999999', '4'] as $pg) {
        $pT = $get('logs.php', $lAdm, ['pagina' => $pg, 'nivel' => "ERRO' OR 1=1"]);
        afirmar("pagina fora do intervalo ($pg) => 302 para a ULTIMA pagina (3) sem o filtro invalido", $pT['status'] === 302 && $loc($pT) === '/gestao/logs.php?aba=api&pagina=3');
    }
    $pTeto = $get('logs.php', $lAdm, ['pagina' => '3']);
    afirmar('pagina 3 (a ultima) NAO redireciona (sem laco)', $pTeto['status'] === 200);
    foreach (['0', '-1', 'abc', '1e3', '2.5', '', ' 2', '02', '99999999999', "2' OR '1'='1", '2;DROP TABLE x'] as $pg) {
        $pI = $get('logs.php', $lAdm, ['pagina' => $pg]);
        afirmar('pagina invalida ' . json_encode($pg) . ' => pagina 1 (200, mesmas 50 linhas), sem erro', $pI['status'] === 200 && idsDasLinhas($pI['corpo']) === $ids1 && !str_contains($pI['corpo'], 'SQLSTATE'));
    }
    $pArrP = $req(['arquivo' => 'logs.php', 'query' => ['pagina' => ['2']]] + $ck($lAdm));
    afirmar('pagina como array => pagina 1', $pArrP['status'] === 200 && idsDasLinhas($pArrP['corpo']) === $ids1);
    $pGv = $get('logs.php', $lAdm, ['aba' => 'gestao', 'pagina' => '7']);
    afirmar('aba vazia com pagina 7 => redireciona para ?aba=gestao (pagina 1, sem pagina=) e nao entra em laco', $pGv['status'] === 302 && $loc($pGv) === '/gestao/logs.php?aba=gestao' && $get('logs.php', $lAdm, ['aba' => 'gestao'])['status'] === 200);

    // --- estados vazios e erro de carga
    $pV = $get('logs.php', $lAdm, ['aba' => 'gestao']);
    afirmar('vazio sem filtros: #logs-vazio "Nenhum registro nesta aba nos últimos 90 dias.", sem tabela nem paginacao, contador "Nenhum registro."', $pV['status'] === 200 && str_contains($pV['corpo'], 'id="logs-vazio"') && str_contains($pV['corpo'], 'Nenhum registro nesta aba nos últimos 90 dias.') && !str_contains($pV['corpo'], 'id="logs-tabela"') && !str_contains($pV['corpo'], 'id="logs-paginacao"') && !str_contains($pV['corpo'], 'Limpar filtros</a></div>') && str_contains($pV['corpo'], 'role="status">Nenhum registro.<'));
    $pVf = $get('logs.php', $lAdm, ['aba' => 'gestao', 'nivel' => 'ERRO']);
    afirmar('vazio com filtros: "Nenhum registro encontrado com estes filtros." + link Limpar filtros (?aba=gestao)', str_contains($pVf['corpo'], 'Nenhum registro encontrado com estes filtros.') && str_contains($pVf['corpo'], 'id="logs-vazio-limpar" href="/gestao/logs.php?aba=gestao">Limpar filtros<'));
    $pVp = $get('logs.php', $lAdm, ['aba' => 'cron', 'categoria' => 'cron_falhou', 'nivel' => 'INFO']);
    afirmar('filtros que nao casam em aba com dados => vazio com filtros', str_contains($pVp['corpo'], 'Nenhum registro encontrado com estes filtros.') && !str_contains($pVp['corpo'], 'id="logs-tabela"'));

    // --- XSS na lista
    afirmar('XSS lista: mensagem hostil vem escapada (ENT_QUOTES), nunca como HTML', str_contains($c, '&lt;script&gt;alert(1)&lt;/script&gt;&quot;&#039;&amp;&lt;img src=x onerror=alert(2)&gt;') && !str_contains($c, '<script>alert') && !str_contains($c, '<img src=x') && !preg_match('/<[a-z][^>]*\sonerror\s*=/i', $c));

    // --- detalhe
    $pDet = $get('log.php', $lAdm, ['id' => (string) $idExpB, 'aba' => 'expedicao']);
    $cd = $pDet['corpo'];
    afirmar('detalhe: 200, #log-detalhe, #log-contexto, #log-voltar "Voltar para a lista", titulo e menu Logs', $pDet['status'] === 200 && str_contains($cd, 'id="log-detalhe"') && str_contains($cd, 'id="log-contexto"') && str_contains($cd, 'id="log-voltar"') && str_contains($cd, 'Voltar para a lista') && str_contains($cd, '<title>Detalhe do registro - Gestão Totem</title>') && preg_match('/gestao-menu__item--atual"><a class="gestao-menu__link" href="\/gestao\/logs\.php"/', $cd) === 1);
    afirmar('detalhe: nivel, aba, categoria, totem (nome + empresa), primeira/ultima ocorrencia, repeticoes, mensagem completa', str_contains($cd, '<dd id="log-nivel">Erro</dd>') && str_contains($cd, '<dd id="log-aba">Expedição</dd>') && str_contains($cd, '<dd id="log-categoria">oc_<wbr>consulta_<wbr>falhou</dd>') && str_contains($cd, '<dd id="log-totem">DOCA-07 (Maua II)</dd>') && preg_match('/<dd id="log-primeira"><time datetime="[^"]+">\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}<\/time><\/dd>/', $cd) === 1 && str_contains($cd, 'id="log-ultima"') && str_contains($cd, '<dd id="log-repeticoes">2 ocorrências</dd>') && str_contains($cd, '<dd id="log-mensagem">Falha ao consultar a ordem de coleta.</dd>'));
    afirmar('detalhe: detalhe tecnico em pares chave/valor (classe, http, motivo)', str_contains($cd, 'id="log-tecnico"') && str_contains($cd, '<dt>classe</dt><dd>PDOException</dd>') && str_contains($cd, '<dt>http</dt><dd>500</dd>') && str_contains($cd, '<dt>motivo</dt><dd>timeout</dd>'));
    afirmar('detalhe: sem Revelar, exportar, editar ou excluir; sem inline; sem codigo/token/URL do totem', !str_contains($cd, 'Revelar') && !str_contains($cd, 'Exportar') && !str_contains($cd, 'Editar') && !str_contains($cd, 'Excluir') && !str_contains($cd, '<form class="gestao-form"') && semInlineLogs($cd) && array_reduce($segredos, static fn ($ok, $s) => $ok && !str_contains($cd, $s), true) && !str_contains($cd, '?totem='));
    $pDetH = $get('log.php', $lAdm, ['id' => (string) $idHostil]);
    afirmar('detalhe hostil: mensagem e detalhe tecnico escapados (sem script/img/svg executaveis)', $pDetH['status'] === 200 && str_contains($pDetH['corpo'], '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($pDetH['corpo'], '&lt;img src=x onerror=alert(3)&gt;') && str_contains($pDetH['corpo'], '&lt;svg onload=alert(4)&gt;') && !str_contains($pDetH['corpo'], '<script>alert') && !str_contains($pDetH['corpo'], '<img src=x') && !str_contains($pDetH['corpo'], '<svg onload'));
    $pDetC = $get('log.php', $lAdm, ['id' => (string) $idCronInfo]);
    afirmar('detalhe de Cron: sem linha de Totem; pares job/logs_apagados/auditoria_apagados/lotes; voltar cai na aba do registro', $pDetC['status'] === 200 && !str_contains($pDetC['corpo'], 'id="log-totem"') && str_contains($pDetC['corpo'], '<dt>job</dt><dd>limpar_logs_gestao</dd>') && str_contains($pDetC['corpo'], '<dt>logs_apagados</dt><dd>3</dd>') && (voltarQuery($pDetC['corpo']) ?? []) === ['aba' => 'cron']);
    $pDetSemTec = $get('log.php', $lAdm, ['id' => (string) $idAntigo]);
    afirmar('detalhe sem `detalhe`: mostra "Este registro não tem detalhe técnico." e totem da aba API', str_contains($pDetSemTec['corpo'], 'id="log-tecnico-vazio"') && str_contains($pDetSemTec['corpo'], 'Este registro não tem detalhe técnico.') && str_contains($pDetSemTec['corpo'], '<dd id="log-totem">GUICHE-04 (Maua I)</dd>'));

    // --- detalhe inexistente / ids hostis
    $pNf = $get('log.php', $lAdm, ['id' => '999999', 'aba' => 'cron', 'nivel' => 'ERRO']);
    afirmar('detalhe inexistente => 302 para a lista da aba (filtros validados) com msg=log_nao_encontrado', $pNf['status'] === 302 && $loc($pNf) === '/gestao/logs.php?aba=cron&nivel=ERRO&msg=log_nao_encontrado');
    $pNf2 = $get('logs.php', $lAdm, ['aba' => 'cron', 'msg' => 'log_nao_encontrado']);
    afirmar('lista com msg=log_nao_encontrado mostra a mensagem fixa em alerta de erro, acentuada', $pNf2['status'] === 200 && str_contains($pNf2['corpo'], 'Registro não encontrado. Ele pode ter sido apagado pela retenção de 90 dias.') && str_contains($pNf2['corpo'], 'gestao-flash--erro'));
    $pMsgX = $get('logs.php', $lAdm, ['msg' => '<script>alert(1)</script>']);
    afirmar('msg arbitraria na lista e ignorada (so codigos fixos)', $pMsgX['status'] === 200 && !str_contains($pMsgX['corpo'], 'gestao-flash') && !str_contains($pMsgX['corpo'], '<script>alert'));
    $okId = true;
    foreach (['0', '-1', '1 OR 1=1', "1' OR '1'='1", 'abc', '', '01', '1e3', '99999999999', ' 1', '1;DROP TABLE tb_log_sistema', '9999999999'] as $iv) {
        $pI = $get('log.php', $lAdm, ['id' => $iv]);
        $okId = $okId && $pI['status'] === 302 && $loc($pI) === '/gestao/logs.php?aba=api&msg=log_nao_encontrado' && !str_contains($pI['corpo'], 'SQLSTATE');
    }
    $pSemId = $get('log.php', $lAdm);
    $pArrId = $req(['arquivo' => 'log.php', 'query' => ['id' => [(string) $idHostil]]] + $ck($lAdm));
    afirmar('detalhe com id hostil, vazio, ausente ou array => 302 mensagem fixa, sem erro SQL', $okId && $pSemId['status'] === 302 && $pArrId['status'] === 302 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_log_sistema') === 129);

    // --- "Voltar" preserva SO filtros validados
    $qVolta = ['id' => (string) $idExpB, 'aba' => 'api', 'nivel' => 'ERRO', 'de' => $d7, 'ate' => $hojeIso, 'totem' => (string) $tA, 'categoria' => 'erro_tecnico', 'pagina' => '2'];
    $pV1 = $get('log.php', $lAdm, $qVolta);
    afirmar('voltar: preserva aba, nivel, periodo, totem, categoria e pagina validados (ordem canonica, relativo a /gestao/logs.php)', voltarQuery($pV1['corpo']) === ['aba' => 'api', 'nivel' => 'ERRO', 'de' => $d7, 'ate' => $hojeIso, 'totem' => (string) $tA, 'categoria' => 'erro_tecnico', 'pagina' => '2']);
    $qHostil = ['id' => (string) $idExpB, 'aba' => 'api', 'nivel' => '<script>alert(1)</script>', 'de' => '2026-02-30', 'ate' => '"><img src=x>', 'totem' => '999999', 'categoria' => '"><svg onload=1>', 'pagina' => '2;DROP', 'next' => 'https://evil.example.test/', 'redirect' => '//evil.example.test', 'url' => 'javascript:alert(1)'];
    $pV2 = $get('log.php', $lAdm, $qHostil);
    afirmar('voltar: parametros invalidos/extras (nivel, datas, totem inexistente, categoria, pagina, next/redirect/url) NAO entram no link (so ?aba=api)', $pV2['status'] === 200 && voltarQuery($pV2['corpo']) === ['aba' => 'api'] && !str_contains($pV2['corpo'], 'evil.example') && !str_contains($pV2['corpo'], 'javascript:') && !str_contains($pV2['corpo'], '<img src=x') && !str_contains($pV2['corpo'], '<svg onload'));
    afirmar('voltar: o href e sempre relativo ao proprio site (/gestao/logs.php?...), nunca URL livre', preg_match('/id="log-voltar" href="\/gestao\/logs\.php\?[A-Za-z0-9_=&;.%\-]*"/', $pV1['corpo']) === 1 && preg_match('/id="log-voltar" href="\/gestao\/logs\.php\?[A-Za-z0-9_=&;.%\-]*"/', $pV2['corpo']) === 1);
    $pV3 = $get('log.php', $lAdm, ['id' => (string) $idExpB]);
    afirmar('voltar sem aba na URL => aba do proprio registro (expedicao)', voltarQuery($pV3['corpo']) === ['aba' => 'expedicao']);
    $pV4 = $get('log.php', $lAdm, ['id' => (string) $idExpB, 'aba' => 'cron', 'totem' => (string) $tA]);
    afirmar('voltar: totem em aba sem totem (cron) e ignorado', voltarQuery($pV4['corpo']) === ['aba' => 'cron']);
    $pV5 = $get('log.php', $lAdm, ['id' => (string) $idExpB, 'aba' => 'invalida_xyz']);
    afirmar('voltar com aba INVALIDA na URL => aba do proprio registro (expedicao), nunca cai em api', $pV5['status'] === 200 && voltarQuery($pV5['corpo']) === ['aba' => 'expedicao']);
    $pV6 = $get('log.php', $lAdm, ['id' => (string) $idExpB, 'aba' => '']);
    afirmar('voltar com aba VAZIA na URL => aba do proprio registro (expedicao)', voltarQuery($pV6['corpo']) === ['aba' => 'expedicao']);
    $pV7 = $get('log.php', $lAdm, ['id' => (string) $idCronInfo, 'aba' => 'x<y']);
    afirmar('voltar com aba invalida em registro de cron => aba cron', voltarQuery($pV7['corpo']) === ['aba' => 'cron']);
    afirmar('detalhe: "Mensagem" e o PRIMEIRO par do #log-contexto (antes de Nivel)', preg_match('/<dl class="gestao-dados" id="log-contexto">\s*<dt>Mensagem<\/dt><dd id="log-mensagem">Falha ao consultar a ordem de coleta\.<\/dd>\s*<dt>Nível<\/dt>/u', $cd) === 1 && substr_count($cd, '<dt>Mensagem</dt>') === 1);
    afirmar('detalhe: h2 com a categoria escapada e <wbr> como unico HTML nao escapado (oc_<wbr>consulta_<wbr>falhou)', str_contains($cd, '</span> oc_<wbr>consulta_<wbr>falhou</h2>') && substr_count($cd, '<wbr>') === 4);
    $cssLogs = (string) file_get_contents($raiz . '/public/gestao/assets/gestao.css');
    afirmar('css: #log-mensagem 16px/600, Detalhe sticky a direita, Repeticoes 104px tabular-nums, categoria break-word 150px, min-width 886/1006', str_contains($cssLogs, '#log-mensagem { font-size: 16px; font-weight: 600; }') && str_contains($cssLogs, 'th.col-detalhe,') && str_contains($cssLogs, 'td.col-detalhe { position: sticky; right: 0; background: var(--branco); box-shadow: -1px 0 0 var(--borda); }') && str_contains($cssLogs, 'th.col-repeticoes { width: 104px; }') && str_contains($cssLogs, 'tabular-nums') && str_contains($cssLogs, 'th.col-categoria { width: 150px; }') && str_contains($cssLogs, '.col-categoria { overflow-wrap: break-word; }') && str_contains($cssLogs, 'min-width: 886px;') && str_contains($cssLogs, 'min-width: 1006px;') && str_contains($cssLogs, 'text-underline-offset: 4px'));
    $linkDetLista = $get('logs.php', $lAdm, array_diff_key($qVolta, ['id' => 1, 'pagina' => 1]));
    afirmar('lista -> detalhe -> lista: o "Ver detalhe" da lista carrega os filtros validados (round trip)', $linkDetLista['status'] === 200 && preg_match('#href="/gestao/log\.php\?id=\d+&amp;aba=api&amp;nivel=ERRO&amp;de=' . $d7 . '&amp;ate=' . $hojeIso . '&amp;totem=' . $tA . '&amp;categoria=erro_tecnico"#', $linkDetLista['corpo']) === 1);

    // --- seguranca geral das respostas
    $vazou = false;
    $semNoStore = 0;
    foreach ($todas as $resp) {
        foreach ($segredos as $s) {
            if (str_contains($resp['cru'], $s)) {
                $vazou = true;
            }
        }
        if (in_array($resp['status'], [200, 403, 405], true) && gtCabecalho($resp, 'cache-control') !== 'no-store, private') {
            $semNoStore++;
        }
    }
    afirmar('NENHUMA resposta (' . count($todas) . ') contem codigo, token_api ou URL do totem', !$vazou);
    afirmar('toda resposta 200/403/405 sai com Cache-Control: no-store, private', $semNoStore === 0);
    afirmar('nenhum 500 nem erro/trace de PHP nas respostas (so as 302/200/403/405 esperadas)', array_reduce($todas, static fn ($ok, $r) => $ok && $r['status'] !== 500 && !preg_match('/(Fatal error|Uncaught|Warning:|Notice:|Deprecated:|Stack trace|SQLSTATE)/', $r['cru']), true));
    afirmar('abrir as telas NAO alterou logs, totens nem a auditoria (somente leitura e sem trilha)', $snapLogs() === $antesLogs && $snapTotens() === $antesTotens && $snapAud() === $antesAud);

    // --- erro de carga (tabela indisponivel) sem detalhe tecnico
    @unlink($log);
    $pdo->exec('RENAME TABLE tb_log_sistema TO tb_log_sistema_qa_off');
    try {
        $pE = $get('logs.php', $lAdm);
        $pEd = $get('log.php', $lAdm, ['id' => (string) $idHostil]);
    } finally {
        $pdo->exec('RENAME TABLE tb_log_sistema_qa_off TO tb_log_sistema');
    }
    afirmar('erro de carga (lista): #logs-erro-carga role=alert com a mensagem fixa, sem tabela e SEM detalhe tecnico (SQLSTATE, nome de tabela, classe)', $pE['status'] === 500 && str_contains($pE['corpo'], 'id="logs-erro-carga" role="alert"') && str_contains($pE['corpo'], 'Não foi possível carregar os logs agora. Tente novamente em instantes.') && !str_contains($pE['corpo'], 'id="logs-tabela"') && !preg_match('/SQLSTATE|tb_log_sistema|PDOException|Stack trace/i', $pE['cru']));
    afirmar('erro de carga (lista): o aviso de retencao NAO aparece acima do erro (omitido)', !str_contains($pE['corpo'], 'id="logs-aviso-retencao"'));
    afirmar('erro de carga (detalhe): mensagem fixa, link Voltar para a lista e sem detalhe tecnico', $pEd['status'] === 500 && str_contains($pEd['corpo'], 'id="logs-erro-carga"') && str_contains($pEd['corpo'], 'id="log-voltar" href="/gestao/logs.php"') && !preg_match('/SQLSTATE|tb_log_sistema|PDOException|Stack trace/i', $pEd['cru']));
    $conteudoLog = (string) @file_get_contents($log);
    afirmar('erro de carga: o log do PHP so tem a linha fixa com a CLASSE da excecao (sem mensagem SQL, sem tabela)', str_contains($conteudoLog, 'gestao: logs_carga_falhou PDOException') && !preg_match('/SQLSTATE|tb_log_sistema|Base table|doesn\'t exist/i', $conteudoLog));
    $pAposErro = $get('logs.php', $lAdm);
    afirmar('apos restaurar a tabela a tela volta ao normal (200, 122 de API)', $pAposErro['status'] === 200 && str_contains($pAposErro['corpo'], '> (122 registros)<'));

    // --- varredura de codigo-fonte
    $fonteCtrl = $semComentarios($raiz . '/app/Controller/GestaoLogController.php');
    $fonteViews = (string) file_get_contents($raiz . '/app/Views/gestao/logs.php') . (string) file_get_contents($raiz . '/app/Views/gestao/log.php');
    $fonteDao = (string) file_get_contents($raiz . '/app/Dao/LogSistemaDao.php');
    preg_match('/function buscarPorId.*?\n    }\n/s', $fonteDao, $mb);
    preg_match('/function totensParaFiltro.*?\n    }\n/s', $fonteDao, $mt);
    afirmar('fonte: controller sem LIKE, sem SQL, sem DELETE/UPDATE/INSERT, sem token_api/codigo', !preg_match('/\bLIKE\b|\bSELECT\b|\bDELETE\b|\bUPDATE\b|\bINSERT\b/', $fonteCtrl) && !str_contains($fonteCtrl, 'token_api') && !str_contains($fonteCtrl, "'codigo'") && !str_contains($fonteCtrl, '$_GET'));
    afirmar('fonte: buscarPorId e totensParaFiltro selecionam colunas explicitas sem codigo nem token_api e usam prepared/bind', ($mb[0] ?? '') !== '' && ($mt[0] ?? '') !== '' && !preg_match('/codigo|token_api|SELECT \*/', ($mb[0] ?? '') . ($mt[0] ?? '')) && str_contains($mb[0] ?? '', 'bindValue(\'id\', $idLog, PDO::PARAM_INT)'));
    afirmar('fonte: views sem inline script/style e toda saida de dado por h()/gestaoData', !preg_match('/<script|<style|\sstyle=|\son[a-z]+=/i', $fonteViews) && !preg_match('/<\?=\s*\$(?:i|registro|filtros|a|o|c|at)\[[^\]]+\]\s*\?>/', $fonteViews));
    afirmar('fonte: so GET nas paginas (metodos GET) e menu Logs ativo', substr_count((string) file_get_contents($raiz . '/public/gestao/logs.php') . (string) file_get_contents($raiz . '/public/gestao/log.php'), "'metodos' => ['GET']") === 2 && str_contains((string) file_get_contents($raiz . '/app/Controller/GestaoContexto.php'), "'href' => '/gestao/logs.php', 'perfil' => 'admin', 'disponivel' => true"));
} finally {
    if ($banco !== null) {
        try {
            qaQrDroparBanco($banco);
        } catch (Throwable $e) {
        }
    }
    gtDestruirAmbiente(null, $storage);
    @unlink($log);
}

exit(gtResumo('teste_gestao_logs'));
