<?php

/**
 * Gestao Totem, F2 (TOTENS, 2026-10-07): normalizacao e HASH do codigo, URL base,
 * regras de negocio (criar, nome repetido, colisao, desativar, regerar, legado),
 * paginas REAIS por php-cgi (RBAC, CSRF, IDOR, PRG, token nunca exposto, XSS,
 * TOTEM_URL_BASE, Host forjado), efeito imediato no quiosque e em Util\Auth,
 * auditoria sem segredo, concorrencia (2 processos) e migration 022 (idempotente
 * e equivalente ao schema.sql). Banco QA descartavel; NUNCA udlog_totem.
 *
 * Uso: php tests/manual/teste_gestao_totens.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Controller\GestaoContexto;
use App\Dao\AuditoriaDao;
use App\Rn\TotemGestaoRn;
use Util\LimiteFalhasIp;
use Util\TotemCodigo;
use Util\TotemUrlBase;

// ---------------------------------------------------------------------------
// Modos de trabalhador (subprocessos da propria bateria): concorrencia e Util\Auth
// ---------------------------------------------------------------------------
if (isset($argv[1]) && $argv[1] === '--w-criar') {
    [, , $wBanco, $wAdmin, $wEmpresa, $wNome, $wInicio, $wBase] = $argv;
    $wPdo = qaQrAbrirBanco($wBanco);
    $wPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    while (microtime(true) < (float) $wInicio) {
        usleep(200);
    }
    $wR = (new TotemGestaoRn($wPdo, $wBase))->criar((int) $wAdmin, (int) $wEmpresa, $wNome, '203.0.113.5');
    echo json_encode(['ok' => $wR['ok'], 'codigo' => $wR['codigo'] ?? null, 'erros' => array_keys($wR['erros'] ?? [])]);
    exit(0);
}
if (isset($argv[1]) && $argv[1] === '--w-auth') {
    [, , $wBanco, $wToken] = $argv;
    $GLOBALS['wToken'] = $wToken;
    if (!function_exists('getallheaders')) {
        function getallheaders(): array
        {
            return $GLOBALS['wToken'] === '' ? [] : ['Authorization' => 'Bearer ' . $GLOBALS['wToken']];
        }
    }
    $wPdo = qaQrAbrirBanco($wBanco);
    $wPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $wTotem = Util\Auth::validarTotem($wPdo);
    echo 'AUTORIZADO id=' . (int) $wTotem['id_totem'];
    exit(0);
}

$banco = null;
$storage = null;
$bancos = [];
$log = gtNovoLogCgi();
$logRn = gtNovoLogCgi();
@unlink($log);
@unlink($logRn);
ini_set('log_errors', '1');
ini_set('error_log', $logRn);

const BASE_DEV = 'http://localhost:8080/totem/';

/** Executa um worker desta mesma bateria e devolve a saida. */
function worker(array $args): string
{
    $cmd = array_merge([PHP_BINARY, __FILE__], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
    $o = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);

    return trim($o);
}

function loc(array $r): ?string
{
    return gtCabecalho($r, 'location');
}

/** Remove comentarios (tokens) de um arquivo PHP: para varrer so o codigo executavel. */
function codigoSemComentarios(string $arquivo): string
{
    $saida = '';
    foreach (token_get_all((string) file_get_contents($arquivo)) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                continue;
            }
            $saida .= $t[1];
        } else {
            $saida .= $t;
        }
    }

    return $saida;
}

function uuidTeste(): string
{
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
}

function paginaTotem(array $o): array
{
    return gtChamar(['raiz' => 'totem', 'arquivo' => 'index.php'] + $o);
}

function cabecalhosEstaveis(array $r): array
{
    $c = $r['cabecalhos'];
    unset($c['x-powered-by'], $c['date'], $c['content-length'], $c['retry-after']);
    ksort($c);

    return $c;
}

$raiz = dirname(__DIR__, 2);

try {
    // =====================================================================
    // A. TotemCodigo: normalizacao, slug da empresa e HASH (sem banco)
    // =====================================================================
    $nomes = [
        'Guichê 04' => 'GUICHE-04',
        'guiche  04' => 'GUICHE-04',
        '  --a b--  ' => 'A-B',
        'Recepção_01!' => 'RECEPCAO-01',
        'Ünïcödé' => 'UNICODE',
        "GUICHE\n04" => 'GUICHE-04',
        "A\0B" => 'A-B',
        'GUICHE😀04' => 'GUICHE04',
        'ab' => 'AB',
        str_repeat('A', 24) => str_repeat('A', 24),
        'Açaí/Loja #3' => 'ACAI-LOJA-3',
    ];
    foreach ($nomes as $entrada => $esperado) {
        afirmar('nome: ' . json_encode($entrada, JSON_UNESCAPED_UNICODE) . ' => ' . $esperado, TotemCodigo::nome($entrada) === $esperado);
    }
    foreach (['a' => '1 caractere', str_repeat('A', 25) => '25 caracteres', '😀😀' => 'so emoji', '***' => 'so simbolos', '   ' => 'so espacos', '' => 'vazio', '-a-' => 'um caractere entre simbolos', str_repeat('x', 300) => 'entrada gigante', 'A😀' => 'emoji apos 1 letra'] as $entrada => $rotulo) {
        afirmar("nome recusado ($rotulo)", TotemCodigo::nome((string) $entrada) === null);
    }
    afirmar('nome: 24 caracteres vindos de simbolos internos tambem passam (A-B repetido)', TotemCodigo::nome('AAAAAAAAAAA BBBBBBBBBBB') === 'AAAAAAAAAAA-BBBBBBBBBBB');
    afirmar('empresa: "Maua I" => MAUAI', TotemCodigo::empresa('Maua I') === 'MAUAI');
    afirmar('empresa: "Mauá II" => MAUAII', TotemCodigo::empresa('Mauá II') === 'MAUAII');
    afirmar('empresa: "Mauá-I" => MAUAI (remove TODO nao alfanumerico)', TotemCodigo::empresa('Mauá-I') === 'MAUAI');
    afirmar('empresa: so simbolos => vazio (quem chama recusa)', TotemCodigo::empresa('***') === '' && TotemCodigo::empresa('😀') === '');
    afirmar('empresa: longa passa de 16 (quem chama recusa, nunca trunca)', strlen(TotemCodigo::empresa('Empresa Muito Longa Demais Ltda')) > TotemCodigo::EMPRESA_MAX);
    afirmar('empresa: entrada gigante => vazio', TotemCodigo::empresa(str_repeat('A', 500)) === '');
    afirmar('montar: NOME-EMPRESA-HASH', TotemCodigo::montar('GUICHE-04', 'MAUAI', 'K7QX2M5PDW4RJT3A') === 'GUICHE-04-MAUAI-K7QX2M5PDW4RJT3A');
    afirmar('prefixoDoPadrao: reconhece o padrao novo e devolve NOME-EMPRESA', TotemCodigo::prefixoDoPadrao('GUICHE-04-MAUAI-K7QX2M5PDW4RJT3A') === 'GUICHE-04-MAUAI');
    afirmar('prefixoDoPadrao: RECEPCAO-01 e codigos fora do padrao => null', TotemCodigo::prefixoDoPadrao('RECEPCAO-01') === null && TotemCodigo::prefixoDoPadrao('TESTE_E2E_16a493') === null && TotemCodigo::prefixoDoPadrao('A-B-K7QX2M5PDW4RJT3A0') === null && TotemCodigo::prefixoDoPadrao('GUICHE-04-MAUAI-K7QX2M9PDW4RJT30') === null);

    $hashes = [];
    $posicoes = array_fill(0, 16, []);
    $todosOsSimbolos = [];
    $formatoOk = true;
    for ($i = 0; $i < 5000; $i++) {
        $h = TotemCodigo::hash();
        $formatoOk = $formatoOk && preg_match('/\A[A-Z2-7]{16}\z/D', $h) === 1;
        $hashes[$h] = true;
        for ($p = 0; $p < 16; $p++) {
            $posicoes[$p][$h[$p]] = true;
            $todosOsSimbolos[$h[$p]] = true;
        }
    }
    afirmar('hash: 5000 gerados, todos com 16 caracteres do alfabeto A-Z2-7 (sem 0, 1, 8, 9)', $formatoOk);
    afirmar('hash: 5000 gerados, 5000 DISTINTOS (sem repeticao)', count($hashes) === 5000);
    afirmar('hash: os 32 simbolos do alfabeto aparecem', count($todosOsSimbolos) === 32);
    $minPos = min(array_map('count', $posicoes));
    afirmar('hash: cada posicao usa quase todo o alfabeto (min ' . $minPos . ' de 32, sem posicao fixa)', $minPos >= 30);
    $freq = array_fill_keys(str_split(TotemCodigo::ALFABETO), 0);
    foreach (array_keys($hashes) as $h) {
        foreach (str_split($h) as $c) {
            $freq[$c]++;
        }
    }
    $esperadoPorSimbolo = 5000 * 16 / 32;
    afirmar('hash: distribuicao proxima da uniforme (cada simbolo dentro de +-15% de 2500)', max($freq) < $esperadoPorSimbolo * 1.15 && min($freq) > $esperadoPorSimbolo * 0.85);
    $fonteCodigo = codigoSemComentarios($raiz . '/util/TotemCodigo.php') . codigoSemComentarios($raiz . '/app/Rn/TotemGestaoRn.php') . codigoSemComentarios($raiz . '/app/Dao/TotemGestaoDao.php');
    afirmar('hash: o gerador usa random_int e o token usa random_bytes (CSPRNG)', str_contains(codigoSemComentarios($raiz . '/util/TotemCodigo.php'), 'random_int(') && str_contains(codigoSemComentarios($raiz . '/app/Rn/TotemGestaoRn.php'), 'random_bytes(32)'));
    afirmar('hash: codigo da F2 NAO usa rand, mt_rand, uniqid, shuffle, array_rand nem lcg_value', preg_match('/\b(mt_rand|rand|srand|mt_srand|uniqid|shuffle|str_shuffle|array_rand|lcg_value|openssl_random_pseudo_bytes)\s*\(/', $fonteCodigo) !== 1);
    afirmar('limites: NOME 2..24, EMPRESA 16, HASH 16, total 58 (VARCHAR 64)', TotemCodigo::NOME_MIN === 2 && TotemCodigo::NOME_MAX === 24 && TotemCodigo::EMPRESA_MAX === 16 && TotemCodigo::HASH_TAMANHO === 16 && TotemCodigo::CODIGO_MAX === 24 + 1 + 16 + 1 + 16 && TotemCodigo::CODIGO_MAX <= 64);

    // =====================================================================
    // B. TotemUrlBase (sem banco)
    // =====================================================================
    foreach (['https://totem.udlog.online/totem/', 'http://localhost:8080/totem/', 'http://localhost/', 'https://a.b-c.example.com:8443/x/y/'] as $v) {
        afirmar("url base valida: $v", TotemUrlBase::doAmbiente(['TOTEM_URL_BASE' => $v]) === $v);
    }
    foreach (['' => 'vazia', 'http://x/totem' => 'sem barra final', 'ftp://x/' => 'esquema ftp', 'javascript:alert(1)//' => 'javascript', 'http://x/ y/' => 'com espaco', "http://x/\n" => 'quebra de linha', 'http://x/?a=1/' => 'com query', 'http://x/#f/' => 'com fragmento', 'http://user@x/' => 'com credenciais', '//x/totem/' => 'sem esquema', 'https:///totem/' => 'sem host', 'http://x/../' => 'caminho com ..', 'HTTP://x' => 'sem barra', 'http://' . str_repeat('a', 400) . '/' => 'gigante'] as $v => $rotulo) {
        afirmar("url base invalida ($rotulo)", TotemUrlBase::doAmbiente(['TOTEM_URL_BASE' => (string) $v]) === null);
    }
    afirmar('url base: ausente e nao-string => null', TotemUrlBase::doAmbiente([]) === null && TotemUrlBase::doAmbiente(['TOTEM_URL_BASE' => ['http://x/']]) === null);
    afirmar('urlDoCodigo: BASE + ?totem= + codigo', TotemUrlBase::urlDoCodigo('https://totem.udlog.online/totem/', 'GUICHE-04-MAUAI-K7QX2M5PDW4RJT3A') === 'https://totem.udlog.online/totem/?totem=GUICHE-04-MAUAI-K7QX2M5PDW4RJT3A');

    // =====================================================================
    // C. Ambiente QA e regras de negocio (Rn em processo)
    // =====================================================================
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $bancos[] = $banco;
    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idAdmin2 = gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Admin');
    $idUsu = gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    $emp = static function (string $nome, string $cnpj, int $ativo = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES (:n, :c, :a)')->execute(['n' => $nome, 'c' => $cnpj, 'a' => $ativo]);

        return (int) $pdo->lastInsertId();
    };
    $eMaua1 = $emp('Maua I', '14706199000182');
    $eMaua2 = $emp('Maua II', '14706199000344');
    $eAlt = $emp('Mauá-I', '11111111000111');
    $eLonga = $emp('Empresa Muito Longa Demais Ltda', '22222222000122');
    $eSimbolos = $emp('***', '33333333000133');
    $eInativa = $emp('Inativa SA', '44444444000144', 0);
    $e16 = $emp('Empresa16Chars00', '55555555000155');
    $eXss = $emp('<i>"A\'&', '66666666000166');
    $tokenReal = static fn (int $id): string => (string) gtEscalar($pdo, 'SELECT token_api FROM tb_totem WHERE id_totem = :i', ['i' => $id]);
    $linhaTotem = static fn (int $id): array => gtLinhas($pdo, 'SELECT * FROM tb_totem WHERE id_totem = :i', ['i' => $id])[0] ?? [];
    $rn = new TotemGestaoRn($pdo, BASE_DEV);

    // legado: RECEPCAO-01 (nome e empresa), mais dois legados que nao deixam regerar
    $legado = static function (string $codigo, string $nome, ?int $empresa) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES (:c, :n, :e, :t, 1)')->execute(['c' => $codigo, 'n' => $nome, 'e' => $empresa, 't' => bin2hex(random_bytes(32))]);

        return (int) $pdo->lastInsertId();
    };
    $idRecepcao = $legado('RECEPCAO-01', 'RECEPCAO-01', $eMaua1);
    $idLegadoNomeRuim = $legado('LEGADO-NOME-RUIM', '#', $eMaua1);
    $idLegadoSemEmpresa = $legado('LEGADO-SEM-EMPRESA', 'LEGADO SEM EMPRESA', null);
    $idLegadoEmpresaLonga = $legado('LEGADO-EMPRESA-LONGA', 'LEGADO LONGA', $eLonga);
    $idLegadoXss = $legado('XSS_LEGADO', '<script>alert(1)</script>', $eMaua2);
    $snapRecepcao = $linhaTotem($idRecepcao);
    $snapLegados = [$linhaTotem($idLegadoNomeRuim), $linhaTotem($idLegadoSemEmpresa), $linhaTotem($idLegadoEmpresaLonga)];
    afirmar('schema: tb_totem.codigo ja e VARCHAR(64) e tem criado_por, atualizado_em, url_regerada_em, url_versao', (int) gtEscalar($pdo, "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'") === 64 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME IN ('criado_por','atualizado_em','url_regerada_em','url_versao')") === 4);

    // --- criar: caminho feliz
    $r = $rn->criar($idAdmin, $eMaua1, 'Guichê 04', '203.0.113.5');
    $idG4 = (int) ($r['id'] ?? 0);
    $g4 = $linhaTotem($idG4);
    afirmar('criar: ok e devolve o id', $r['ok'] === true && $idG4 > 0);
    afirmar('criar: nome normalizado GUICHE-04, ativo, empresa e criado_por corretos, versao 1', $g4['nome'] === 'GUICHE-04' && (int) $g4['ativo'] === 1 && (int) $g4['id_empresa'] === $eMaua1 && (int) $g4['criado_por'] === $idAdmin && (int) $g4['url_versao'] === 1 && $g4['url_regerada_em'] === null);
    afirmar('criar: codigo = ^GUICHE-04-MAUAI-[A-Z2-7]{16}$ e comprimento <= 58', preg_match('/\AGUICHE-04-MAUAI-[A-Z2-7]{16}\z/D', (string) $g4['codigo']) === 1 && strlen((string) $g4['codigo']) <= 58);
    afirmar('criar: formato geral ^[A-Z0-9-]+-[A-Z0-9]+-[A-Z2-7]{16}$', preg_match('/\A[A-Z0-9-]+-[A-Z0-9]+-[A-Z2-7]{16}\z/D', (string) $g4['codigo']) === 1);
    afirmar('criar: token_api tem 64 hex gerado no servidor (nao e o codigo)', preg_match('/\A[a-f0-9]{64}\z/D', (string) $g4['token_api']) === 1 && !str_contains((string) $g4['token_api'], (string) $g4['codigo']));
    $obtido = $rn->obter($idG4);
    afirmar('obter/URL: BASE + ?totem= + codigo (BASE do ambiente, nunca do Host)', $obtido !== null && $obtido['url'] === BASE_DEV . '?totem=' . $g4['codigo'] && $obtido['legado'] === false);
    afirmar('obter: a linha devolvida NAO contem token_api nem codigo solto', $obtido !== null && !array_key_exists('token_api', $obtido) && !array_key_exists('codigo', $obtido));
    $lista = $rn->listar();
    afirmar('listar: nenhuma linha traz token_api (colunas explicitas)', array_reduce($lista, static fn ($c, $l) => $c && !array_key_exists('token_api', $l), true) && count($lista) === 6);
    $leg = array_values(array_filter($lista, static fn ($l) => (int) $l['id_totem'] === $idRecepcao))[0] ?? [];
    afirmar('listar: RECEPCAO-01 aparece como legado, com a URL antiga', ($leg['legado'] ?? null) === true && ($leg['url'] ?? '') === BASE_DEV . '?totem=RECEPCAO-01');
    $aud = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'TOTEM_CRIAR' AND alvo_id = :i", ['i' => $idG4]);
    afirmar('auditoria: TOTEM_CRIAR OK, alvo totem/id, detalhe SO empresa=<id>, ip gravado', count($aud) === 1 && $aud[0]['resultado'] === 'OK' && $aud[0]['alvo_tipo'] === 'totem' && $aud[0]['detalhe'] === 'empresa=' . $eMaua1 && (int) $aud[0]['id_usuario'] === $idAdmin && $aud[0]['ip'] !== null);
    $r2 = $rn->criar($idAdmin, $eMaua2, 'Guichê 04');
    $g4b = $linhaTotem((int) $r2['id']);
    afirmar('criar: mesmo nome em OUTRA empresa e permitido (GUICHE-04-MAUAII-...)', $r2['ok'] === true && preg_match('/\AGUICHE-04-MAUAII-[A-Z2-7]{16}\z/D', (string) $g4b['codigo']) === 1 && $g4b['token_api'] !== $g4['token_api'] && $g4b['codigo'] !== $g4['codigo']);

    // --- limite maximo: 24 + 16 = 58
    $r58 = $rn->criar($idAdmin, $e16, str_repeat('N', 24));
    $c58 = (string) ($linhaTotem((int) ($r58['id'] ?? 0))['codigo'] ?? '');
    afirmar('criar: NOME 24 + EMPRESA 16 + HASH 16 = 58 caracteres, grava inteiro (VARCHAR 64)', $r58['ok'] === true && strlen($c58) === 58 && preg_match('/\A' . str_repeat('N', 24) . '-EMPRESA16CHARS00-[A-Z2-7]{16}\z/D', $c58) === 1);

    // --- nome repetido
    $d1 = $rn->criar($idAdmin, $eMaua1, 'guiche 04');
    $d2 = $rn->criar($idAdmin, $eMaua1, '  GUICHE-04  ');
    afirmar('nome repetido na MESMA empresa (mesmo apos normalizar) => recusa clara no campo nome, nada criado', $d1['ok'] === false && $d1['codigo'] === 'validacao' && isset($d1['erros']['nome']) && str_contains($d1['erros']['nome'], 'Já existe') && $d2['ok'] === false && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem WHERE id_empresa = :e AND nome = :n', ['e' => $eMaua1, 'n' => 'GUICHE-04']) === 1);
    afirmar('nome repetido: auditoria SEM_EFEITO (sem detalhe de nome nem codigo)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'TOTEM_CRIAR' AND resultado = 'SEM_EFEITO' AND detalhe = :d", ['d' => 'empresa=' . $eMaua1]) === 2);

    // --- validacao de entrada
    foreach ([['a', $eMaua1, 'nome', 'nome de 1 caractere'], [str_repeat('A', 25), $eMaua1, 'nome', 'nome de 25 caracteres'], ['😀', $eMaua1, 'nome', 'so emoji'], ['###', $eMaua1, 'nome', 'so simbolos'], ['OK-01', null, 'empresa', 'sem empresa'], ['OK-01', 999999, 'empresa', 'empresa inexistente'], ['OK-01', $eInativa, 'empresa', 'empresa inativa'], ['OK-01', $eLonga, 'empresa', 'empresa de slug > 16'], ['OK-01', $eSimbolos, 'empresa', 'empresa de slug vazio']] as [$nomeIn, $empIn, $campo, $rot]) {
        $antes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
        $x = $rn->criar($idAdmin, $empIn, $nomeIn);
        afirmar("criar recusa ($rot): validacao no campo $campo e nada criado", $x['ok'] === false && ($x['codigo'] ?? '') === 'validacao' && isset($x['erros'][$campo]) && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') === $antes);
    }
    $xl = $rn->criar($idAdmin, $eLonga, 'OK-01');
    afirmar('empresa longa: mensagem clara cita 16 e nao cria nada', str_contains((string) ($xl['erros']['empresa'] ?? ''), '16') && str_contains((string) ($xl['erros']['empresa'] ?? ''), 'Nenhum totem foi criado'));

    // --- RBAC no Rn: quem nao e admin ativo nao cria
    $xu = $rn->criar($idUsu, $eMaua1, 'Intruso 01');
    afirmar('Rn: usuario (nao admin) => sem_permissao e nada criado', $xu['ok'] === false && $xu['codigo'] === 'sem_permissao' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_totem WHERE nome = 'INTRUSO-01'") === 0);
    $xi = $rn->criar(999999, $eMaua1, 'Intruso 02');
    afirmar('Rn: id de ator inexistente => sem_permissao', $xi['ok'] === false && $xi['codigo'] === 'sem_permissao');
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idAdmin2]);
    $xd = $rn->criar($idAdmin2, $eMaua1, 'Intruso 03');
    afirmar('Rn: admin DESATIVADO (sessao antiga) => sem_permissao', $xd['ok'] === false && $xd['codigo'] === 'sem_permissao');
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = :i')->execute(['i' => $idAdmin2]);

    // --- colisao forcada
    $codColisao = 'COLIDE-01-MAUAI-AAAAAAAAAAAAAAAA';
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES (:c, :n, :e, :t, 1)')->execute(['c' => $codColisao, 'n' => 'COLIDE-01', 'e' => $eMaua1, 't' => bin2hex(random_bytes(32))]);
    $chamadas = 0;
    $seq = ['AAAAAAAAAAAAAAAA', 'AAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBB'];
    $rnSeq = new TotemGestaoRn($pdo, BASE_DEV, static function () use (&$chamadas, $seq): string {
        return $seq[min($chamadas++, count($seq) - 1)];
    });
    $rc = $rnSeq->criar($idAdmin, $eAlt, 'Colide 01');
    afirmar('colisao (1062 no codigo): retry com novo HASH ate funcionar (3a tentativa)', $rc['ok'] === true && $chamadas === 3 && (string) $linhaTotem((int) $rc['id'])['codigo'] === 'COLIDE-01-MAUAI-BBBBBBBBBBBBBBBB');
    $chamadas = 0;
    $rnFixo = new TotemGestaoRn($pdo, BASE_DEV, static function () use (&$chamadas): string {
        $chamadas++;

        return 'AAAAAAAAAAAAAAAA';
    });
    $pdo->exec("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES ('COLIDE-02-MAUAI-AAAAAAAAAAAAAAAA', 'OUTRO', {$eMaua1}, '" . bin2hex(random_bytes(32)) . "', 1)");
    $chamadas = 0;
    $rf = $rnFixo->criar($idAdmin, $eAlt, 'Colide 02');
    afirmar('colisao permanente: falha LIMPA apos exatamente 5 tentativas (totem_colisao), nada criado', $rf['ok'] === false && ($rf['codigo'] ?? '') === 'totem_colisao' && $chamadas === 5 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_totem WHERE nome = 'COLIDE-02' AND id_empresa = :e", ['e' => $eAlt]) === 0);
    afirmar('colisao permanente: auditoria ERRO (sem detalhe) e nenhuma linha PENDENTE', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'TOTEM_CRIAR' AND resultado = 'ERRO'") === 1 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    $logTxt = (string) @file_get_contents($logRn);
    afirmar('colisao permanente: log fixo sem o codigo/HASH', str_contains($logTxt, 'criar_codigo_colisao_esgotada') && !str_contains($logTxt, 'AAAAAAAAAAAAAAAA'));
    $rnRuim = new TotemGestaoRn($pdo, BASE_DEV, static fn (): string => 'abc');
    $rr = $rnRuim->criar($idAdmin, $eAlt, 'Colide 03');
    afirmar('gerador defeituoso (fora do alfabeto) => erro_interno, nunca grava codigo ruim', $rr['ok'] === false && $rr['codigo'] === 'erro_interno' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_totem WHERE nome = 'COLIDE-03'") === 0);
    $rnMt = new TotemGestaoRn($pdo, BASE_DEV, static fn (): string => '0123456789012345');
    afirmar('gerador com 0/1/8/9 (nao base32) => recusado', $rnMt->criar($idAdmin, $eAlt, 'Colide 04')['ok'] === false);

    // --- concorrencia: 2 processos criando o MESMO nome na MESMA empresa
    $lancar = static function (array $args): array {
        $p = proc_open(array_merge([PHP_BINARY, __FILE__], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);

        return [$p, $pipes];
    };
    $colher = static function (array $h): ?array {
        $o = (string) stream_get_contents($h[1][1]);
        stream_get_contents($h[1][2]);
        fclose($h[1][1]);
        fclose($h[1][2]);
        proc_close($h[0]);

        return json_decode($o, true);
    };
    foreach ([1, 2, 3] as $rodada) {
        $nomeC = 'Corrida ' . $rodada;
        $inicio = sprintf('%.4F', microtime(true) + 3.0);
        $hs = [];
        foreach ([$idAdmin, $idAdmin2] as $adm) {
            $hs[] = $lancar(['--w-criar', $banco, (string) $adm, (string) $eMaua2, $nomeC, $inicio, BASE_DEV]);
        }
        $saidas = array_map($colher, $hs);
        $oks = count(array_filter($saidas, static fn ($s) => ($s['ok'] ?? false) === true));
        $recusas = count(array_filter($saidas, static fn ($s) => is_array($s) && ($s['ok'] ?? true) === false && ($s['codigo'] ?? '') === 'validacao' && ($s['erros'] ?? []) === ['nome']));
        afirmar("concorrencia (rodada $rodada): 2 processos, mesmo nome e empresa => exatamente 1 cria e 1 e recusado com erro de nome", $oks === 1 && $recusas === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem WHERE id_empresa = :e AND nome = :n', ['e' => $eMaua2, 'n' => 'CORRIDA-' . $rodada]) === 1);
    }
    // 2 processos com nomes DIFERENTES: ambos criam, codigos e tokens distintos
    $inicio = sprintf('%.4F', microtime(true) + 3.0);
    $hs = [];
    foreach (['Paralelo A' => $idAdmin, 'Paralelo B' => $idAdmin2] as $nm => $adm) {
        $hs[] = $lancar(['--w-criar', $banco, (string) $adm, (string) $eMaua2, $nm, $inicio, BASE_DEV]);
    }
    $okD = count(array_filter(array_map($colher, $hs), static fn ($s) => ($s['ok'] ?? false) === true));
    afirmar('concorrencia: nomes diferentes em paralelo => os 2 criam, com codigos e tokens distintos', $okD === 2 && (int) gtEscalar($pdo, "SELECT COUNT(DISTINCT codigo) FROM tb_totem WHERE nome IN ('PARALELO-A','PARALELO-B')") === 2 && (int) gtEscalar($pdo, "SELECT COUNT(DISTINCT token_api) FROM tb_totem WHERE nome IN ('PARALELO-A','PARALELO-B')") === 2);
    // --- desativar / reativar (Rn) e atendimento recente
    $atend = static function (int $idTotem, string $status, int $minAtras) use ($pdo): int {
        $pdo->prepare("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, atualizado_em) VALUES (:c, :t, 'expedicao', :s, NOW() - INTERVAL " . (int) $minAtras . ' MINUTE)')->execute(['c' => uuidTeste(), 't' => $idTotem, 's' => $status]);

        return (int) $pdo->lastInsertId();
    };
    $rd = $rn->definirAtivo($idAdmin, $idG4, false, false, '203.0.113.5');
    afirmar('desativar (sem atendimento): ok e ativo = 0', $rd['ok'] === true && (int) $linhaTotem($idG4)['ativo'] === 0);
    $rd2 = $rn->definirAtivo($idAdmin, $idG4, false, false);
    afirmar('desativar de novo: sem_mudanca (sem auditoria nova)', $rd2['ok'] === true && ($rd2['sem_mudanca'] ?? false) === true && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'TOTEM_ATIVO' AND alvo_id = :i", ['i' => $idG4]) === 1);
    $ra = $rn->definirAtivo($idAdmin, $idG4, true, false, '203.0.113.5');
    afirmar('reativar: ok e ativo = 1', $ra['ok'] === true && (int) $linhaTotem($idG4)['ativo'] === 1);
    $audAtivo = gtLinhas($pdo, "SELECT detalhe, resultado, alvo_tipo FROM tb_gestao_auditoria WHERE acao = 'TOTEM_ATIVO' AND alvo_id = :i ORDER BY id_auditoria", ['i' => $idG4]);
    afirmar('auditoria TOTEM_ATIVO: ativo_para=0 e ativo_para=1, OK, alvo totem, sem codigo/URL', count($audAtivo) === 2 && $audAtivo[0]['detalhe'] === 'ativo_para=0' && $audAtivo[1]['detalhe'] === 'ativo_para=1' && $audAtivo[0]['resultado'] === 'OK' && $audAtivo[0]['alvo_tipo'] === 'totem');
    $atend($idG4, 'em_andamento', 31);
    $atend($idG4, 'concluido', 1);
    $atend($idG4, 'cancelado', 2);
    $atend($idG4b = (int) $r2['id'], 'em_andamento', 1);
    afirmar('desativar: atendimento em_andamento ANTIGO (31 min), concluido e cancelado nao bloqueiam; o de OUTRO totem nao conta', $rn->definirAtivo($idAdmin, $idG4, false, false)['ok'] === true);
    $pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_totem = :i")->execute(['i' => $idG4b]);
    $rn->definirAtivo($idAdmin, $idG4, true, false);
    $atend($idG4, 'em_andamento', 5);
    $rb = $rn->definirAtivo($idAdmin, $idG4, false, false);
    afirmar('desativar com atendimento em_andamento RECENTE: recusa totem_atendimento_em_andamento e NADA muda', $rb['ok'] === false && $rb['codigo'] === 'totem_atendimento_em_andamento' && (int) $linhaTotem($idG4)['ativo'] === 1);
    afirmar('desativar recusado por atendimento recente: auditoria SEM_EFEITO TOTEM_ATIVO com alvo totem/id e sem detalhe', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'TOTEM_ATIVO' AND resultado = 'SEM_EFEITO' AND alvo_tipo = 'totem' AND alvo_id = " . (int) $idG4 . ' AND detalhe IS NULL') === 1);
    $rbc = $rn->definirAtivo($idAdmin, $idG4, false, true);
    afirmar('desativar com confirmacao explicita: ok', $rbc['ok'] === true && (int) $linhaTotem($idG4)['ativo'] === 0);
    $rn->definirAtivo($idAdmin, $idG4, true, false);
    afirmar('definirAtivo: totem inexistente => totem_nao_encontrado', $rn->definirAtivo($idAdmin, 987654, false, false)['codigo'] === 'totem_nao_encontrado' && $rn->definirAtivo($idUsu, $idG4, false, true)['codigo'] === 'sem_permissao');
    afirmar('totem_nao_encontrado (definirAtivo e regerarUrl) grava SEM_EFEITO sem alvo (alvo_id NULL), sem inventar o id pedido', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'TOTEM_ATIVO' AND resultado = 'SEM_EFEITO' AND alvo_id IS NULL") === 1 && $rn->regerarUrl($idAdmin, 987654, 1, 'X')['codigo'] === 'totem_nao_encontrado' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'TOTEM_URL_REGERAR' AND resultado = 'SEM_EFEITO' AND alvo_id IS NULL") >= 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria WHERE alvo_id = 987654') === 0);
    $listaRec = array_values(array_filter($rn->listar(), static fn ($l) => (int) $l['id_totem'] === $idG4))[0];
    afirmar('listar: atendimentos_recentes conta so em_andamento dentro de 30 min (1 aqui)', (int) $listaRec['atendimentos_recentes'] === 1);

    // =====================================================================
    // D. Regerar URL (Rn)
    // =====================================================================
    $pdo->prepare('UPDATE tb_atendimento SET status = \'cancelado\' WHERE id_totem = :i')->execute(['i' => $idG4]);
    $antes = $linhaTotem($idG4);
    $rg = $rn->regerarUrl($idAdmin, $idG4, (int) $antes['url_versao'], 'guiche-04', '203.0.113.5');
    $depois = $linhaTotem($idG4);
    afirmar('regerar: ok (nome digitado sem diferenciar maiusculas)', $rg['ok'] === true);
    afirmar('regerar: MANTEM NOME-EMPRESA e troca SO o HASH (novo CSPRNG, diferente do antigo)', substr((string) $depois['codigo'], 0, -17) === 'GUICHE-04-MAUAI' && substr((string) $depois['codigo'], -16) !== substr((string) $antes['codigo'], -16) && preg_match('/\AGUICHE-04-MAUAI-[A-Z2-7]{16}\z/D', (string) $depois['codigo']) === 1);
    afirmar('regerar: token_api INALTERADO, versao +1, url_regerada_em preenchida, ativo/empresa/criado_por iguais', $depois['token_api'] === $antes['token_api'] && (int) $depois['url_versao'] === (int) $antes['url_versao'] + 1 && $depois['url_regerada_em'] !== null && (int) $depois['ativo'] === (int) $antes['ativo'] && $depois['id_empresa'] === $antes['id_empresa'] && $depois['criado_por'] === $antes['criado_por'] && $depois['nome'] === $antes['nome']);
    $audReg = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'TOTEM_URL_REGERAR' AND alvo_id = :i", ['i' => $idG4]);
    afirmar('auditoria TOTEM_URL_REGERAR: OK, alvo totem, SEM detalhe (nada de codigo/URL/hash)', count($audReg) === 1 && $audReg[0]['resultado'] === 'OK' && $audReg[0]['detalhe'] === null && $audReg[0]['alvo_tipo'] === 'totem');
    // versao otimista: reenvio do MESMO formulario (versao antiga) e recusado
    $codAposRegerar = (string) $depois['codigo'];
    $rReenvio = $rn->regerarUrl($idAdmin, $idG4, (int) $antes['url_versao'], 'GUICHE-04');
    afirmar('regerar: reenvio do mesmo formulario (versao antiga) => url_ja_regerada e o codigo NAO muda', $rReenvio['ok'] === false && $rReenvio['codigo'] === 'url_ja_regerada' && (string) $linhaTotem($idG4)['codigo'] === $codAposRegerar);
    foreach (['' => 'vazio', 'GUICHE-05' => 'outro nome', 'GUICHE' => 'parcial', "GUICHE-04\0" => 'com NUL', str_repeat('G', 300) => 'gigante'] as $digitado => $rot) {
        $x = $rn->regerarUrl($idAdmin, $idG4, (int) $depois['url_versao'], (string) $digitado);
        afirmar("regerar: nome digitado errado ($rot) => totem_nome_confirmacao e NADA muda", $x['ok'] === false && $x['codigo'] === 'totem_nome_confirmacao' && (string) $linhaTotem($idG4)['codigo'] === $codAposRegerar);
    }
    afirmar('regerar: ator nao admin => sem_permissao, totem inexistente => totem_nao_encontrado', $rn->regerarUrl($idUsu, $idG4, 2, 'GUICHE-04')['codigo'] === 'sem_permissao' && $rn->regerarUrl($idAdmin, 987654, 1, 'X')['codigo'] === 'totem_nao_encontrado');
    $rnReg = new TotemGestaoRn($pdo, BASE_DEV, (static function () use ($codAposRegerar): callable {
        $n = 0;

        return static function () use (&$n, $codAposRegerar): string {
            return $n++ < 3 ? substr($codAposRegerar, -16) : 'CCCCCCCCCCCCCCCC';
        };
    })());
    $xr = $rnReg->regerarUrl($idAdmin, $idG4, (int) $linhaTotem($idG4)['url_versao'], 'GUICHE-04');
    afirmar('regerar: HASH igual ao atual (3x) e descartado, usa o proximo diferente', $xr['ok'] === true && (string) $linhaTotem($idG4)['codigo'] === 'GUICHE-04-MAUAI-CCCCCCCCCCCCCCCC');

    // --- RECEPCAO-01: NADA da F2 mexeu na linha ate agora
    afirmar('RECEPCAO-01: a linha esta IDENTICA (codigo, nome, token, ativo, empresa, versao) depois de criar/desativar/regerar OUTROS totens', $linhaTotem($idRecepcao) === $snapRecepcao);
    foreach ([$idLegadoNomeRuim, $idLegadoSemEmpresa, $idLegadoEmpresaLonga] as $k => $idL) {
        afirmar('legado sem padrao: linha ' . $k . ' identica (nenhuma operacao da F2 a tocou)', $linhaTotem($idL) === $snapLegados[$k]);
    }
    // legados invalidos recusam regerar
    foreach ([[$idLegadoNomeRuim, '#', 'nome que nao normaliza'], [$idLegadoSemEmpresa, 'LEGADO SEM EMPRESA', 'sem empresa'], [$idLegadoEmpresaLonga, 'LEGADO LONGA', 'empresa de slug longo']] as [$idL, $nomeL, $rot]) {
        $x = $rn->regerarUrl($idAdmin, $idL, 1, $nomeL);
        afirmar("regerar em legado ($rot): recusa totem_legado_invalido e nada muda", $x['ok'] === false && $x['codigo'] === 'totem_legado_invalido' && $linhaTotem($idL) === ($snapLegados[array_search($idL, [$idLegadoNomeRuim, $idLegadoSemEmpresa, $idLegadoEmpresaLonga], true)]));
    }

    // --- limpeza das linhas de apoio e integridade da auditoria
    afirmar('auditoria: nenhuma linha PENDENTE e todas as acoes do catalogo fechado', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao NOT IN ('" . implode("','", AuditoriaDao::ACOES) . "')") === 0);
    afirmar('auditoria: catalogo tem TOTEM_CRIAR, TOTEM_ATIVO e TOTEM_URL_REGERAR e o alvo totem; detalhe fora da allowlist e recusado', in_array('TOTEM_CRIAR', AuditoriaDao::ACOES, true) && in_array('TOTEM_ATIVO', AuditoriaDao::ACOES, true) && in_array('TOTEM_URL_REGERAR', AuditoriaDao::ACOES, true) && in_array('totem', AuditoriaDao::ALVO_TIPOS, true) && (static function (): bool {
        foreach ([['codigo' => 'ABC'], ['url' => 'http://x'], ['token' => 'a'], ['empresa' => 'abc'], ['empresa' => '0'], ['empresa' => '12345678901'], ['hash' => 'K7QX2M5PDW4RJT3A']] as $d) {
            try {
                AuditoriaDao::montarDetalhe($d);

                return false;
            } catch (InvalidArgumentException $e) {
            }
        }

        return AuditoriaDao::montarDetalhe(['empresa' => 7]) === 'empresa=7';
    })());
    afirmar('auditoria: nenhum detalhe tem 64 hex, codigo, URL ou token', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE detalhe REGEXP '[a-fA-F0-9]{32}|http|[A-Z2-7]{16}|=.*-.*-'") === 0);

    // =====================================================================
    // E. Paginas REAIS por php-cgi
    // =====================================================================
    $H = ['env' => ['TOTEM_URL_BASE' => BASE_DEV], 'logErro' => $log];
    $todas = [];
    $req = static function (array $o) use (&$todas, $H): array {
        $r = gtChamar($o + $H);
        $todas[] = $r;

        return $r;
    };
    $ck = static fn (?array $l): array => $l !== null && $l['sid'] !== null ? ['cookies' => ['gestao_sid' => $l['sid']]] : [];
    $get = static fn (string $arq, ?array $l, array $q = [], array $extra = []): array => $req(['arquivo' => $arq, 'query' => $q] + $ck($l) + $extra);
    $post = static fn (string $arq, ?array $l, array $form, array $extra = [], bool $comCsrf = true): array => $req(['arquivo' => $arq, 'metodo' => 'POST', 'form' => $form + ($comCsrf && $l !== null && $l['csrf'] !== null ? ['csrf_token' => $l['csrf']] : [])] + $ck($l) + $extra);
    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => '192.0.2.21']);
    $lAdm2 = gtLogin('beto.admin', GT_SENHA_BOA, ['ip' => '192.0.2.22']);
    $lUsu = gtLogin('carla.usuario', GT_SENHA_BOA, ['ip' => '192.0.2.23']);
    afirmar('sessoes de teste abertas (admin, admin2, usuario) com CSRF', $lAdm['sid'] !== null && $lAdm['csrf'] !== null && $lAdm2['sid'] !== null && $lUsu['sid'] !== null && $lUsu['csrf'] !== null);
    $csrfsConhecidos = [$lAdm['csrf'], $lAdm2['csrf'], $lUsu['csrf']];
    $snapTotens = static fn (): string => json_encode(gtLinhas($pdo, 'SELECT id_totem, codigo, nome, id_empresa, token_api, ativo, criado_por, url_versao, url_regerada_em FROM tb_totem ORDER BY id_totem'));
    $snapAud = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria');

    // --- RBAC: anonimo, usuario, admin em TODAS as rotas e metodos
    $rotas = [['totens.php', []], ['totem-form.php', []], ['totem-url.php', ['id' => (string) $idG4]]];
    foreach ($rotas as [$arq, $q]) {
        $ra = $get($arq, null, $q);
        afirmar("RBAC $arq: anonimo => redireciona ao login (sem conteudo)", in_array($ra['status'], [302, 401], true) && !str_contains($ra['corpo'], 'GUICHE'));
        $ru = $get($arq, $lUsu, $q);
        afirmar("RBAC $arq: perfil usuario => 403 sem conteudo de totens", $ru['status'] === 403 && !str_contains($ru['corpo'], 'GUICHE') && !str_contains($ru['corpo'], 'totem='));
        $rad = $get($arq, $lAdm, $q);
        afirmar("RBAC $arq: admin => 200 com cabecalhos de seguranca", $rad['status'] === 200 && gtCabecalho($rad, 'cache-control') === 'no-store, private' && gtCabecalho($rad, 'x-frame-options') === 'DENY' && gtCabecalho($rad, 'content-security-policy') !== null);
    }
    $antesSnap = $snapTotens();
    $antesAud = $snapAud();
    $acoesPost = [
        ['totens.php', ['acao' => 'desativar', 'id_totem' => (string) $idG4]],
        ['totens.php', ['acao' => 'ativar', 'id_totem' => (string) $idG4]],
        ['totens.php', ['acao' => 'regerar-url', 'id_totem' => (string) $idG4, 'versao_url' => '3', 'nome_confirmacao' => 'GUICHE-04']],
        ['totem-form.php', ['id_empresa' => (string) $eMaua1, 'nome' => 'Invasor 01']],
    ];
    foreach ($acoesPost as [$arq, $form]) {
        $rot = $arq . ' ' . ($form['acao'] ?? 'criar');
        $pa = $post($arq, null, $form, [], false);
        afirmar("RBAC POST $rot: anonimo bloqueado", in_array($pa['status'], [302, 401, 403], true));
        $pu = $post($arq, $lUsu, $form);
        afirmar("RBAC POST $rot: perfil usuario => 403", $pu['status'] === 403);
        $p0 = $post($arq, $lAdm, $form, [], false);
        afirmar("CSRF POST $rot: sem token => 403 'Página expirada'", $p0['status'] === 403 && str_contains($p0['corpo'], 'Página expirada'));
        $p1 = $post($arq, $lAdm, $form + ['csrf_token' => str_repeat('a', 64)]);
        afirmar("CSRF POST $rot: token errado => 403", $p1['status'] === 403);
        $p2 = $post($arq, $lAdm, $form + ['csrf_token' => (string) $lAdm2['csrf']]);
        afirmar("CSRF POST $rot: token de OUTRA sessao => 403", $p2['status'] === 403);
        $p3 = $post($arq, $lAdm, $form, ['origin' => 'https://evil.example.test']);
        afirmar("CSRF POST $rot: Origin de outro site => 403", $p3['status'] === 403);
        $p4 = $post($arq, $lAdm, $form, ['origin' => null, 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']]);
        afirmar("CSRF POST $rot: Sec-Fetch-Site cross-site => 403", $p4['status'] === 403);
        $pm = $req(['arquivo' => $arq, 'metodo' => 'PUT', 'form' => $form] + $ck($lAdm));
        afirmar("metodo PUT em $arq => 405", $pm['status'] === 405);
    }
    afirmar('RBAC/CSRF: NENHUMA dessas tentativas alterou totens (snapshot identico)', $snapTotens() === $antesSnap);
    afirmar('RBAC/CSRF: nenhuma auditoria nova (bloqueado antes de chegar a regra)', $snapAud() === $antesAud);
    $pT = $req(['arquivo' => 'totem-url.php', 'metodo' => 'POST', 'form' => ['id' => (string) $idG4, 'csrf_token' => (string) $lAdm['csrf']]] + $ck($lAdm));
    afirmar('totem-url.php so aceita GET (POST => 405)', $pT['status'] === 405);

    // --- IDOR / entrada hostil
    $hostis = ['999999', '0', '-1', '1 OR 1=1', "1' OR '1'='1", '1e3', ' 1', '01', '1.5', '99999999999', 'abc', '', '1;DROP TABLE tb_totem'];
    $okHostis = true;
    foreach ($hostis as $idH) {
        foreach (['ativar', 'desativar', 'regerar-url'] as $acao) {
            $ph = $post('totens.php', $lAdm, ['acao' => $acao, 'id_totem' => $idH, 'versao_url' => '1', 'nome_confirmacao' => 'X']);
            $okHostis = $okHostis && in_array($ph['status'], [302, 400], true) && !str_contains($ph['corpo'], 'SQLSTATE') && !str_contains($ph['corpo'], 'Fatal');
            if ($ph['status'] === 302) {
                $okHostis = $okHostis && preg_match('#\A/gestao/totens\.php\?msg=(totem_nao_encontrado|erro_interno)\z#', (string) loc($ph)) === 1;
            }
        }
        $gh = $get('totem-url.php', $lAdm, ['id' => $idH]);
        $okHostis = $okHostis && $gh['status'] === 302 && preg_match('#\A/gestao/totens\.php\?msg=totem_nao_encontrado\z#', (string) loc($gh)) === 1;
    }
    afirmar('IDOR: id_totem hostil (inexistente, SQL, negativo, notacao cientifica, vazio) em todas as acoes e em totem-url => nao_encontrado/400, sem erro SQL', $okHostis);
    $pArr = $req(['arquivo' => 'totens.php', 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $lAdm['csrf'] . '&acao=desativar&id_totem[]=' . $idG4] + $ck($lAdm));
    afirmar('entrada: id_totem como array => 400, nada muda', $pArr['status'] === 400);
    $pAc = $post('totens.php', $lAdm, ['acao' => 'apagar', 'id_totem' => (string) $idG4]);
    afirmar('entrada: acao desconhecida ("apagar") => 400, nada muda (nao existe exclusao fisica)', $pAc['status'] === 400);
    afirmar('IDOR: nada mudou apos os pedidos hostis', $snapTotens() === $antesSnap);
    afirmar('totens: nao ha acao nem rota de exclusao', !str_contains((string) file_get_contents($raiz . '/app/Controller/GestaoTotemController.php') . (string) file_get_contents($raiz . '/app/Dao/TotemGestaoDao.php'), 'DELETE'));

    // --- lista e formulario (admin)
    $pLista = $get('totens.php', $lAdm);
    afirmar('lista: mostra nome, empresa, situacao e a URL completa (BASE + ?totem=) so para admin', str_contains($pLista['corpo'], 'GUICHE-04') && str_contains($pLista['corpo'], 'Maua I') && str_contains($pLista['corpo'], BASE_DEV . '?totem=' . $linhaTotem($idG4)['codigo']) && str_contains($pLista['corpo'], 'id="tabela-totens"'));
    afirmar('lista: RECEPCAO-01 aparece com a URL ANTIGA (?totem=RECEPCAO-01) e a marca de URL fora do padrao', str_contains($pLista['corpo'], BASE_DEV . '?totem=RECEPCAO-01<') && str_contains($pLista['corpo'], 'Endereço fixo; continua valendo até ser regerado.'));
    afirmar('lista: botoes Novo totem, Ativar/Desativar e Regerar com nome digitado e versao_url; confirmacao so quando ha atendimento', str_contains($pLista['corpo'], 'id="btn-novo-totem"') && str_contains($pLista['corpo'], 'name="nome_confirmacao"') && str_contains($pLista['corpo'], 'name="versao_url"') && str_contains($pLista['corpo'], 'value="desativar"') && str_contains($pLista['corpo'], 'value="regerar-url"') && !str_contains($pLista['corpo'], 'name="confirmar_atendimento"'));
    afirmar('lista: TODO formulario POST da lista tem o campo csrf_token (' . substr_count($pLista['corpo'], '<form') . ' formularios)', substr_count($pLista['corpo'], '<form') > 0 && substr_count($pLista['corpo'], '<form') === substr_count($pLista['corpo'], 'name="csrf_token"') && substr_count($pLista['corpo'], 'method="post"') === substr_count($pLista['corpo'], '<form'));
    $pForm = $get('totem-form.php', $lAdm);
    afirmar('formulario: lista as empresas ATIVAS (Maua I, Maua II) e nao a inativa', str_contains($pForm['corpo'], '>Maua I<') && str_contains($pForm['corpo'], '>Maua II<') && !str_contains($pForm['corpo'], 'Inativa SA') && str_contains($pForm['corpo'], 'name="nome"') && str_contains($pForm['corpo'], 'name="id_empresa"') && str_contains($pForm['corpo'], 'name="csrf_token"'));

    // --- criar por HTTP + PRG
    $antesQtd = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem');
    $pC = $post('totem-form.php', $lAdm, ['id_empresa' => (string) $eMaua2, 'nome' => 'Doca Leste 07'], ['ip' => '192.0.2.31']);
    $locC = (string) loc($pC);
    afirmar('criar (HTTP): 302 (PRG) para totem-url.php?id=N&msg=totem_criado', $pC['status'] === 302 && preg_match('#\A/gestao/totem-url\.php\?id=([0-9]+)&msg=totem_criado\z#', $locC) === 1 && $pC['corpo'] === '');
    preg_match('#id=([0-9]+)#', $locC, $mId);
    $idDoca = (int) ($mId[1] ?? 0);
    $doca = $linhaTotem($idDoca);
    afirmar('criar (HTTP): uma linha nova, DOCA-LESTE-07 na Maua II, ativa, criada pelo admin logado', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') === $antesQtd + 1 && $doca['nome'] === 'DOCA-LESTE-07' && (int) $doca['id_empresa'] === $eMaua2 && (int) $doca['criado_por'] === $idAdmin && preg_match('/\ADOCA-LESTE-07-MAUAII-[A-Z2-7]{16}\z/D', (string) $doca['codigo']) === 1);
    $pU = $get('totem-url.php', $lAdm, ['id' => (string) $idDoca, 'msg' => 'totem_criado']);
    afirmar('criar (HTTP): a pagina de sucesso mostra a URL completa exata e a mensagem de sucesso', $pU['status'] === 200 && str_contains($pU['corpo'], 'id="totem-url-valor">' . BASE_DEV . '?totem=' . $doca['codigo'] . '</code>') && str_contains($pU['corpo'], 'Totem criado e ativo') && str_contains($pU['corpo'], 'data-copiar-de="totem-url-valor"'));
    $pU2 = $get('totem-url.php', $lAdm, ['id' => (string) $idDoca]);
    afirmar('criar (HTTP): F5 na pagina de sucesso (GET) mostra a mesma URL e nao cria nada', str_contains($pU2['corpo'], (string) $doca['codigo']) && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') === $antesQtd + 1);
    $pCr = $post('totem-form.php', $lAdm, ['id_empresa' => (string) $eMaua2, 'nome' => 'Doca Leste 07']);
    afirmar('criar (HTTP): reenvio do mesmo POST (F5 do formulario) => 422 nome repetido, sem segunda linha', $pCr['status'] === 422 && str_contains($pCr['corpo'], 'Já existe um totem com esse nome') && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') === $antesQtd + 1);
    foreach ([['id_empresa' => '', 'nome' => 'Ok 01'], ['id_empresa' => (string) $eMaua1, 'nome' => 'A'], ['id_empresa' => (string) $eLonga, 'nome' => 'Ok 02'], ['id_empresa' => '1 OR 1=1', 'nome' => 'Ok 03'], ['id_empresa' => (string) $eInativa, 'nome' => 'Ok 04']] as $f) {
        $pv = $post('totem-form.php', $lAdm, $f);
        afirmar('criar (HTTP) invalido ' . json_encode($f) . ' => 422 com erro no campo e nada criado', $pv['status'] === 422 && str_contains($pv['corpo'], 'role="alert"') && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem') === $antesQtd + 1);
    }
    $nomeXss = '"><script>alert(document.cookie)</script>';
    $pX = $post('totem-form.php', $lAdm, ['id_empresa' => (string) $eMaua1, 'nome' => $nomeXss]);
    afirmar('XSS: nome hostil devolvido no formulario sai ESCAPADO (sem <script> cru)', $pX['status'] === 422 && !str_contains($pX['corpo'], '<script>alert') && str_contains($pX['corpo'], '&lt;script&gt;alert(document.cookie)&lt;/script&gt;'));
    $rx = $rn->criar($idAdmin, $eXss, 'Xss 01');
    $pLX = $get('totens.php', $lAdm, ['msg' => '<script>alert(1)</script>']);
    afirmar('XSS: empresa com <i>"A\'& e legado com <script> na lista saem escapados; ?msg hostil e ignorado', $rx['ok'] === true && !str_contains($pLX['corpo'], '<script>alert(1)</script>') && str_contains($pLX['corpo'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($pLX['corpo'], '<i>"A') && str_contains($pLX['corpo'], '&lt;i&gt;&quot;A&#039;&amp;') && !str_contains($pLX['corpo'], 'class="gestao-flash'));
    $pFl = $get('totens.php', $lAdm, ['msg' => 'url_regerada']);
    afirmar('flash: codigo do catalogo mostra o texto fixo acentuado', str_contains($pFl['corpo'], 'URL regerada. O endereço anterior deixou de funcionar. O acesso do totem em si (token) não foi alterado; para bloquear um totem, desative-o.'));
    foreach (['totens.php', 'totem-form.php'] as $arq) {
        $corpo = $get($arq, $lAdm)['corpo'];
        afirmar("views $arq sem script/style inline nem on*=", preg_match('/<script\b(?![^>]*\bsrc=)/i', $corpo) !== 1 && preg_match('/<style\b|\sstyle\s*=|\son[a-z]+\s*=|javascript:/i', $corpo) !== 1);
    }
    afirmar('view totem-url sem inline', preg_match('/<script\b(?![^>]*\bsrc=)/i', $pU['corpo']) !== 1 && preg_match('/<style\b|\sstyle\s*=|\son[a-z]+\s*=|javascript:/i', $pU['corpo']) !== 1);

    // --- quiosque: codigo novo funciona; RECEPCAO-01 (agora regerado no Rn) e o resto
    $qDoca = paginaTotem(['query' => ['totem' => (string) $doca['codigo']], 'ip' => '198.51.100.71', 'logErro' => $log]);
    afirmar('quiosque: o codigo NOVO (ate 58) abre a pagina (200) com o token e o nome do totem', $qDoca['status'] === 200 && str_contains($qDoca['corpo'], 'data-totem-token="' . $doca['token_api'] . '"') && str_contains($qDoca['corpo'], 'data-totem-nome="DOCA-LESTE-07"'));
    $qLongo = paginaTotem(['query' => ['totem' => $c58], 'ip' => '198.51.100.72', 'logErro' => $log]);
    afirmar('quiosque: codigo de 58 caracteres => 200', strlen($c58) === 58 && $qLongo['status'] === 200);
    $qMin = paginaTotem(['query' => ['totem' => strtolower((string) $doca['codigo'])], 'ip' => '198.51.100.73', 'logErro' => $log]);
    afirmar('quiosque: o codigo em minusculas continua 200 (collation)', $qMin['status'] === 200);

    // --- desativar por HTTP: efeito imediato no quiosque e em Util\Auth
    $totemAlvo = $linhaTotem($idDoca);
    $authAntes = worker(['--w-auth', $banco, (string) $totemAlvo['token_api']]);
    afirmar('Util\Auth: token do totem ativo => autorizado', $authAntes === 'AUTORIZADO id=' . $idDoca);
    $pD = $post('totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idDoca]);
    afirmar('desativar (HTTP): 302 para a lista com totem_desativado e ativo = 0', $pD['status'] === 302 && loc($pD) === '/gestao/totens.php?msg=totem_desativado' && (int) $linhaTotem($idDoca)['ativo'] === 0);
    $qOff = paginaTotem(['query' => ['totem' => (string) $totemAlvo['codigo']], 'ip' => '198.51.100.74', 'logErro' => $log]);
    $qInexistente = paginaTotem(['query' => ['totem' => 'NAOEXISTE-01-MAUAII-AAAAAAAAAAAAAAAA'], 'ip' => '198.51.100.75', 'logErro' => $log]);
    afirmar('desativar: efeito IMEDIATO no quiosque => 404, IDENTICO (corpo e cabecalhos) a um codigo inexistente', $qOff['status'] === 404 && $qOff['corpo'] === $qInexistente['corpo'] && cabecalhosEstaveis($qOff) === cabecalhosEstaveis($qInexistente) && !str_contains($qOff['corpo'], (string) $totemAlvo['token_api']));
    $authOff = worker(['--w-auth', $banco, (string) $totemAlvo['token_api']]);
    afirmar('desativar: efeito IMEDIATO em Util\Auth::validarTotem => 401 "Totem nao autorizado"', str_contains($authOff, 'Totem nao autorizado') && !str_contains($authOff, 'AUTORIZADO'));
    afirmar('Util\Auth: sem token => "Token nao informado" e token inventado => nao autorizado', str_contains(worker(['--w-auth', $banco, '']), 'Token nao informado') && str_contains(worker(['--w-auth', $banco, bin2hex(random_bytes(32))]), 'Totem nao autorizado'));
    $pAt = $post('totens.php', $lAdm, ['acao' => 'ativar', 'id_totem' => (string) $idDoca]);
    $qOn = paginaTotem(['query' => ['totem' => (string) $totemAlvo['codigo']], 'ip' => '198.51.100.76', 'logErro' => $log]);
    afirmar('reativar (HTTP): 302 totem_ativado, quiosque volta a 200 e Util\Auth volta a autorizar (mesmo token)', $pAt['status'] === 302 && loc($pAt) === '/gestao/totens.php?msg=totem_ativado' && $qOn['status'] === 200 && worker(['--w-auth', $banco, (string) $totemAlvo['token_api']]) === 'AUTORIZADO id=' . $idDoca);
    $pAt2 = $post('totens.php', $lAdm, ['acao' => 'ativar', 'id_totem' => (string) $idDoca]);
    afirmar('reativar de novo: totem_sem_mudanca (info), sem auditoria nova', loc($pAt2) === '/gestao/totens.php?msg=totem_sem_mudanca');

    // aviso de atendimento recente por HTTP
    $atend($idDoca, 'em_andamento', 3);
    $pL2 = $get('totens.php', $lAdm);
    afirmar('lista: totem com atendimento recente mostra o aviso e a caixa de confirmacao explicita', str_contains($pL2['corpo'], 'name="confirmar_atendimento"') && str_contains($pL2['corpo'], 'Atendimento em andamento') && str_contains($pL2['corpo'], 'Desativar mesmo assim'));
    $pD2 = $post('totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idDoca]);
    afirmar('desativar com atendimento recente SEM confirmar: 302 totem_atendimento_em_andamento e continua ativo (quiosque 200)', loc($pD2) === '/gestao/totens.php?msg=totem_atendimento_em_andamento' && (int) $linhaTotem($idDoca)['ativo'] === 1 && paginaTotem(['query' => ['totem' => (string) $totemAlvo['codigo']], 'ip' => '198.51.100.77', 'logErro' => $log])['status'] === 200);
    $pD3 = $post('totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idDoca, 'confirmar_atendimento' => '0']);
    afirmar('confirmar_atendimento diferente de "1" nao confirma', loc($pD3) === '/gestao/totens.php?msg=totem_atendimento_em_andamento' && (int) $linhaTotem($idDoca)['ativo'] === 1);
    $pD4 = $post('totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idDoca, 'confirmar_atendimento' => '1']);
    afirmar('desativar com confirmacao explicita: totem_desativado e 404 imediato', loc($pD4) === '/gestao/totens.php?msg=totem_desativado' && (int) $linhaTotem($idDoca)['ativo'] === 0 && paginaTotem(['query' => ['totem' => (string) $totemAlvo['codigo']], 'ip' => '198.51.100.78', 'logErro' => $log])['status'] === 404);
    $post('totens.php', $lAdm, ['acao' => 'ativar', 'id_totem' => (string) $idDoca]);
    $pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_totem = :i")->execute(['i' => $idDoca]);

    // --- regerar por HTTP: versao otimista, nome digitado, URL antiga 404 e nova 200
    $listaHtml = $get('totens.php', $lAdm)['corpo'];
    $versaoNaTela = null;
    if (preg_match('#<form[^>]*>\s*<input type="hidden" name="csrf_token" value="[a-f0-9]{64}">\s*<input type="hidden" name="id_totem" value="' . $idDoca . '">\s*<input type="hidden" name="versao_url" value="([0-9]+)">#', $listaHtml, $mv) === 1) {
        $versaoNaTela = $mv[1];
    }
    afirmar('regerar (HTTP): a lista leva a versao_url do totem no formulario', $versaoNaTela === (string) $linhaTotem($idDoca)['url_versao']);
    $codAntigo = (string) $linhaTotem($idDoca)['codigo'];
    $tokenAntes = $tokenReal($idDoca);
    $pRn = $post('totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idDoca, 'versao_url' => (string) $versaoNaTela, 'nome_confirmacao' => 'OUTRO-NOME']);
    afirmar('regerar (HTTP): nome errado => totem_nome_confirmacao e o codigo NAO muda (URL antiga segue 200)', loc($pRn) === '/gestao/totens.php?msg=totem_nome_confirmacao' && (string) $linhaTotem($idDoca)['codigo'] === $codAntigo && paginaTotem(['query' => ['totem' => $codAntigo], 'ip' => '198.51.100.79', 'logErro' => $log])['status'] === 200);
    $pRs = $post('totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idDoca, 'versao_url' => (string) $versaoNaTela]);
    afirmar('regerar (HTTP): sem o campo nome_confirmacao => totem_nome_confirmacao', loc($pRs) === '/gestao/totens.php?msg=totem_nome_confirmacao' && (string) $linhaTotem($idDoca)['codigo'] === $codAntigo);
    $pRv = $post('totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idDoca, 'nome_confirmacao' => 'DOCA-LESTE-07']);
    afirmar('regerar (HTTP): sem versao_url => 400 e nada muda', $pRv['status'] === 400 && (string) $linhaTotem($idDoca)['codigo'] === $codAntigo);
    $pRo = $post('totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idDoca, 'versao_url' => (string) $versaoNaTela, 'nome_confirmacao' => 'DOCA-LESTE-07'], ['ip' => '192.0.2.41']);
    $locR = (string) loc($pRo);
    afirmar('regerar (HTTP): 302 PRG para totem-url.php?id=N&msg=url_regerada', $pRo['status'] === 302 && $locR === '/gestao/totem-url.php?id=' . $idDoca . '&msg=url_regerada' && $pRo['corpo'] === '');
    $codNovo = (string) $linhaTotem($idDoca)['codigo'];
    afirmar('regerar (HTTP): codigo novo (so o HASH mudou) e token_api INALTERADO', $codNovo !== $codAntigo && substr($codNovo, 0, -16) === substr($codAntigo, 0, -16) && $tokenReal($idDoca) === $tokenAntes);
    $qAnt = paginaTotem(['query' => ['totem' => $codAntigo], 'ip' => '198.51.100.80', 'logErro' => $log]);
    $qNov = paginaTotem(['query' => ['totem' => $codNovo], 'ip' => '198.51.100.81', 'logErro' => $log]);
    afirmar('regerar: URL ANTIGA => 404 IMEDIATO (identico ao inexistente) e URL NOVA => 200', $qAnt['status'] === 404 && $qAnt['corpo'] === $qInexistente['corpo'] && cabecalhosEstaveis($qAnt) === cabecalhosEstaveis($qInexistente) && $qNov['status'] === 200);
    afirmar('regerar: Util\Auth continua autorizando o MESMO token (a API do quiosque nao e afetada)', worker(['--w-auth', $banco, $tokenAntes]) === 'AUTORIZADO id=' . $idDoca);
    $pRep = $post('totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idDoca, 'versao_url' => (string) $versaoNaTela, 'nome_confirmacao' => 'DOCA-LESTE-07']);
    afirmar('regerar: reenvio do MESMO formulario (F5/duplo clique) => url_ja_regerada e o codigo NAO muda de novo', loc($pRep) === '/gestao/totens.php?msg=url_ja_regerada' && (string) $linhaTotem($idDoca)['codigo'] === $codNovo);
    $pRu = $get('totem-url.php', $lAdm, ['id' => (string) $idDoca, 'msg' => 'url_regerada']);
    afirmar('regerar: a pagina do PRG mostra a URL NOVA exata e o aviso', str_contains($pRu['corpo'], 'id="totem-url-valor">' . BASE_DEV . '?totem=' . $codNovo . '</code>') && !str_contains($pRu['corpo'], $codAntigo));
    afirmar('regerar: aviso permanente neutro (com data e sem sugerir revogacao total) tambem SEM o ?msg do PRG', str_contains($pRu['corpo'], 'id="totem-url-regerada"') && str_contains($pRu['corpo'], 'O acesso do totem em si (token) não foi alterado') && preg_match('#URL regerada em <time datetime="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}">\d{2}/\d{2}/\d{4} \d{2}:\d{2}</time>#', $pRu['corpo']) === 1 && str_contains($get('totem-url.php', $lAdm, ['id' => (string) $idDoca])['corpo'], 'id="totem-url-regerada"'));
    $pRUsu = $post('totens.php', $lUsu, ['acao' => 'regerar-url', 'id_totem' => (string) $idDoca, 'versao_url' => '9', 'nome_confirmacao' => 'DOCA-LESTE-07']);
    afirmar('regerar: perfil usuario => 403 e o codigo nao muda', $pRUsu['status'] === 403 && (string) $linhaTotem($idDoca)['codigo'] === $codNovo);

    // --- RECEPCAO-01: nada da F2 mexeu ate o admin acionar "regerar" nela
    afirmar('RECEPCAO-01: a linha continua IDENTICA depois de TODA a bateria HTTP (RBAC, CSRF, IDOR, criar, desativar, regerar de outros)', $linhaTotem($idRecepcao) === $snapRecepcao);
    $qRec0 = paginaTotem(['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.84', 'logErro' => $log]);
    afirmar('quiosque: RECEPCAO-01 continua 200 (URL fixa ate o admin regerar)', $qRec0['status'] === 200 && str_contains($qRec0['corpo'], 'data-totem-token="' . $snapRecepcao['token_api'] . '"'));
    $tkRec = $tokenReal($idRecepcao);
    $pRec = $post('totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idRecepcao, 'versao_url' => (string) $snapRecepcao['url_versao'], 'nome_confirmacao' => 'recepcao-01'], ['ip' => '192.0.2.42']);
    $recDepois = $linhaTotem($idRecepcao);
    afirmar('regerar em legado (HTTP): RECEPCAO-01 vira RECEPCAO-01-MAUAI-<HASH16>, token inalterado, versao 2, redirect PRG', $pRec['status'] === 302 && loc($pRec) === '/gestao/totem-url.php?id=' . $idRecepcao . '&msg=url_regerada' && preg_match('/\ARECEPCAO-01-MAUAI-[A-Z2-7]{16}\z/D', (string) $recDepois['codigo']) === 1 && $recDepois['token_api'] === $tkRec && (int) $recDepois['url_versao'] === 2);
    $qRec = paginaTotem(['query' => ['totem' => 'RECEPCAO-01'], 'ip' => '198.51.100.85', 'logErro' => $log]);
    afirmar('legado regerado: o codigo antigo RECEPCAO-01 agora e 404 (uniforme) e o novo e 200', $qRec['status'] === 404 && $qRec['corpo'] === $qInexistente['corpo'] && paginaTotem(['query' => ['totem' => (string) $recDepois['codigo']], 'ip' => '198.51.100.86', 'logErro' => $log])['status'] === 200);
    foreach ([$idLegadoNomeRuim, $idLegadoSemEmpresa, $idLegadoEmpresaLonga] as $k => $idL) {
        $pL = $post('totens.php', $lAdm, ['acao' => 'regerar-url', 'id_totem' => (string) $idL, 'versao_url' => '1', 'nome_confirmacao' => (string) $snapLegados[$k]['nome']]);
        afirmar('regerar em legado invalido (HTTP) ' . $k . ': totem_legado_invalido e a linha NAO muda', loc($pL) === '/gestao/totens.php?msg=totem_legado_invalido' && $linhaTotem($idL) === $snapLegados[$k]);
    }
    // --- TOTEM_URL_BASE ausente/invalida => 503; valida (dev http) => URL certa; Host forjado
    $snapAntes503 = $snapTotens();
    foreach ([['', 'ausente/vazia'], ['http://localhost:8080/totem', 'sem barra final'], ['ftp://x/totem/', 'esquema invalido'], ['http://x/ totem/', 'com espaco'], ['http://x/totem/?a=1', 'com query']] as [$valorBase, $rot]) {
        $env503 = ['env' => ['TOTEM_URL_BASE' => $valorBase]];
        foreach ([['totens.php', 'GET', []], ['totem-form.php', 'GET', []], ['totem-url.php', 'GET', ['id' => (string) $idDoca]]] as [$arq, $m, $q]) {
            $x = $get($arq, $lAdm, $q, $env503);
            afirmar("TOTEM_URL_BASE $rot: GET $arq => 503 acentuado e sem URL montada", $x['status'] === 503 && str_contains($x['corpo'], 'URL dos totens não configurada') && !str_contains($x['corpo'], '?totem=') && gtCabecalho($x, 'cache-control') === 'no-store, private');
        }
        $xp = $post('totem-form.php', $lAdm, ['id_empresa' => (string) $eMaua1, 'nome' => 'Sem Base 01'], $env503);
        $xp2 = $post('totens.php', $lAdm, ['acao' => 'desativar', 'id_totem' => (string) $idDoca], $env503);
        afirmar("TOTEM_URL_BASE $rot: POST criar/desativar => 503 e NADA muda", $xp['status'] === 503 && $xp2['status'] === 503 && $snapTotens() === $snapAntes503);
    }
    $anon503 = $get('totens.php', null, [], ['env' => ['TOTEM_URL_BASE' => '']]);
    $usu503 = $get('totens.php', $lUsu, [], ['env' => ['TOTEM_URL_BASE' => '']]);
    afirmar('TOTEM_URL_BASE ausente: anonimo e usuario NAO descobrem a configuracao (login/403 antes do 503)', in_array($anon503['status'], [302, 401], true) && $usu503['status'] === 403);
    $outraBase = $get('totens.php', $lAdm, [], ['env' => ['TOTEM_URL_BASE' => 'https://totem.udlog.online/totem/']]);
    afirmar('TOTEM_URL_BASE valida (producao) => a lista usa EXATAMENTE essa base', $outraBase['status'] === 200 && str_contains($outraBase['corpo'], 'https://totem.udlog.online/totem/?totem=' . $codNovo) && !str_contains($outraBase['corpo'], 'localhost:8080'));
    $hostForjado = $get('totens.php', $lAdm, [], ['host' => 'evil.example.test', 'cabecalhos' => ['HTTP_X_FORWARDED_HOST' => 'evil2.example.test', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_FORWARDED' => 'host=evil3.example.test']]);
    afirmar('URL NAO deriva do Host nem de X-Forwarded-Host/Forwarded forjados (usa so TOTEM_URL_BASE)', $hostForjado['status'] === 200 && str_contains($hostForjado['corpo'], BASE_DEV . '?totem=' . $codNovo) && !preg_match('/evil[23]?\.example\.test/', $hostForjado['corpo']));
    $urlForjado = $get('totem-url.php', $lAdm, ['id' => (string) $idDoca], ['host' => 'evil.example.test']);
    afirmar('totem-url com Host forjado: a URL continua a da base do ambiente', str_contains($urlForjado['corpo'], 'id="totem-url-valor">' . BASE_DEV . '?totem=') && !str_contains($urlForjado['corpo'], 'evil.example.test'));

    // --- rate limit continua so em falhas
    $ipV = '203.0.113.150';
    $todosOk = true;
    for ($i = 0; $i < LimiteFalhasIp::LIMITE_FALHAS + 5; $i++) {
        $todosOk = $todosOk && paginaTotem(['query' => ['totem' => $codNovo], 'ip' => $ipV, 'logErro' => $log])['status'] === 200;
    }
    afirmar('rate limit: ' . (LimiteFalhasIp::LIMITE_FALHAS + 5) . ' pedidos VALIDOS do mesmo IP com codigo novo => sempre 200 (so falhas contam)', $todosOk);
    $ipF = '203.0.113.151';
    $statusFalhas = [];
    for ($i = 0; $i < LimiteFalhasIp::LIMITE_FALHAS + 2; $i++) {
        $statusFalhas[] = paginaTotem(['query' => ['totem' => $i % 2 === 0 ? $codAntigo : 'INEXISTENTE-' . $i], 'ip' => $ipF, 'logErro' => $log])['status'];
    }
    afirmar('rate limit: URL antiga (regerada) e inexistentes contam como falha: 404 ate o limite e depois 429', array_slice($statusFalhas, 0, LimiteFalhasIp::LIMITE_FALHAS) === array_fill(0, LimiteFalhasIp::LIMITE_FALHAS, 404) && $statusFalhas[LimiteFalhasIp::LIMITE_FALHAS] === 429);

    // --- varredura final: token e hash nunca aparecem fora do quiosque
    $tokens = array_column(gtLinhas($pdo, 'SELECT token_api FROM tb_totem'), 'token_api');
    $codigos = array_column(gtLinhas($pdo, 'SELECT codigo FROM tb_totem'), 'codigo');
    $hashesReais = [];
    foreach ($codigos as $c) {
        if (preg_match('/-([A-Z2-7]{16})\z/D', (string) $c, $mh) === 1) {
            $hashesReais[] = $mh[1];
        }
    }
    $vazouToken = false;
    $vazou64Hex = false;
    foreach ($todas as $resp) {
        foreach ($tokens as $t) {
            if (str_contains($resp['cru'], (string) $t)) {
                $vazouToken = true;
            }
        }
        $semCsrf = str_replace($csrfsConhecidos, '', $resp['corpo']);
        if (preg_match('/[a-f0-9]{64}/', $semCsrf) === 1) {
            $vazou64Hex = true;
        }
    }
    afirmar('token_api NUNCA aparece em nenhuma resposta da gestao (' . count($todas) . ' respostas: HTML, redirects, erros, cabecalhos)', !$vazouToken && count($todas) > 100);
    afirmar('nenhuma sequencia de 64 hex (alem do CSRF da propria sessao) nos corpos HTML', !$vazou64Hex);
    $audTexto = json_encode(gtLinhas($pdo, 'SELECT detalhe, acao, alvo_tipo FROM tb_gestao_auditoria'));
    $logsTexto = (string) @file_get_contents($log) . (string) @file_get_contents($logRn);
    $vazouAud = false;
    $vazouLog = false;
    foreach (array_merge($tokens, $codigos, $hashesReais) as $segredo) {
        if (str_contains($audTexto, (string) $segredo)) {
            $vazouAud = true;
        }
        if (str_contains($logsTexto, (string) $segredo)) {
            $vazouLog = true;
        }
    }
    afirmar('auditoria: nenhum token, codigo, HASH ou URL de totem gravado', !$vazouAud && !str_contains($audTexto, 'http') && preg_match('/[a-f0-9]{64}/', $audTexto) !== 1);
    afirmar('error_log (CGI e Rn): nenhum token, codigo nem HASH de totem', !$vazouLog && !preg_match('/[a-f0-9]{64}/', $logsTexto));
    $resumoAud = gtLinhas($pdo, "SELECT acao, resultado, COUNT(*) AS n FROM tb_gestao_auditoria WHERE acao LIKE 'TOTEM_%' GROUP BY acao, resultado ORDER BY acao, resultado");
    afirmar('auditoria TOTEM_*: so OK, SEM_EFEITO e ERRO (nenhuma PENDENTE) e ha registro de cada acao', count($resumoAud) >= 3 && count(array_filter($resumoAud, static fn ($l) => $l['resultado'] === 'PENDENTE')) === 0 && count(array_unique(array_column($resumoAud, 'acao'))) === 3);
    afirmar('log do CGI sem excecao nem erro fatal', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)|Stack trace|SQLSTATE/', $logsTexto));
    afirmar('catalogo de mensagens do totem: todas acentuadas, terminam em ponto e existem no GestaoContexto', (static function (): bool {
        foreach (['totem_criado', 'totem_ativado', 'totem_desativado', 'url_regerada', 'totem_sem_mudanca', 'totem_nao_encontrado', 'totem_atendimento_em_andamento', 'url_ja_regerada', 'totem_nome_confirmacao', 'totem_legado_invalido', 'totem_colisao'] as $c) {
            if (!isset(GestaoContexto::MENSAGENS[$c]) || !str_ends_with(GestaoContexto::MENSAGENS[$c][1], '.')) {
                return false;
            }
        }

        return true;
    })());

    // =====================================================================
    // F. Migration 022: idempotente e equivalente ao schema.sql
    // =====================================================================
    $sql022 = (string) file_get_contents($raiz . '/sql/migrations/022_totem_gestao.sql');
    $sem = preg_replace('/^--.*$/m', '', $sql022);
    afirmar('022: sem CHECK, coluna gerada, ADD COLUMN IF NOT EXISTS nem DROP/TRUNCATE/DELETE', stripos((string) $sem, 'CHECK (') === false && stripos((string) $sem, 'GENERATED') === false && stripos((string) $sem, 'ADD COLUMN IF NOT EXISTS') === false && preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM)\b/i', (string) $sem) !== 1);
    $comentComPv = 0;
    foreach (preg_split('/\R/', $sql022) as $linha) {
        if (str_starts_with($linha, '--') && str_contains($linha, ';')) {
            $comentComPv++;
        }
    }
    afirmar('022: nenhum comentario com ponto e virgula (loader dos QA)', $comentComPv === 0);
    afirmar('022: cabecalho com Problema, Solucao, Idempotencia e REVERSAO', str_contains($sql022, 'Problema') && str_contains($sql022, 'Solucao') && str_contains($sql022, 'Idempotencia') && str_contains($sql022, 'REVERSAO'));

    $admin = qaQrPdoServidor(qaQrConfiguracao());
    $criarVazio = static function () use ($admin, &$bancos): array {
        $nome = qaQrNomeBanco();
        qaQrValidarNomeBanco($nome);
        $admin->exec("CREATE DATABASE `{$nome}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $bancos[] = $nome;
        $c = qaQrConfiguracao();
        $p = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$nome};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

        return [$p, $nome];
    };
    $estrutura = static function (PDO $p): array {
        $cols = $p->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' ORDER BY ORDINAL_POSITION")->fetchAll();
        $idx = $p->query("SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetchAll();
        $fk = $p->query("SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'tb_totem' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME")->fetchAll();

        return ['colunas' => $cols, 'indices' => $idx, 'fks' => $fk];
    };

    // (1) caminho de producao: schema.sql ANTIGO (HEAD) + 014..021 + 022 duas vezes
    $schemaAntigo = shell_exec('git -C ' . escapeshellarg($raiz) . ' show HEAD:sql/schema.sql');
    afirmar('git: schema.sql do HEAD disponivel e ainda com codigo VARCHAR(30)', is_string($schemaAntigo) && str_contains($schemaAntigo, 'codigo        VARCHAR(30)'));
    [$pAnt, $bAnt] = $criarVazio();
    $tmpSchema = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_schema_antigo_' . bin2hex(random_bytes(4)) . '.sql';
    file_put_contents($tmpSchema, (string) $schemaAntigo);
    qaQrAplicarSql($pAnt, $tmpSchema);
    @unlink($tmpSchema);
    $prefixoMig = ['014_tb_lgpd_aceite', '015_vio_api_br_estados_e_id_externo', '016_vio_api_br_cache', '017_cnh_modo_captura', '018_nota_client_uid', '019_drop_tb_fila_envio', '020_gestao_usuario_sessao', '021_gestao_auditoria'];
    foreach ($prefixoMig as $m) {
        qaQrAplicarSql($pAnt, $raiz . '/sql/migrations/' . $m . '.sql');
    }
    $pAnt->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $tkLeg = bin2hex(random_bytes(32));
    $pAnt->exec("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api) VALUES ('RECEPCAO-01', 'Totem Recepcao', 1, '{$tkLeg}')");
    $antesMig = $pAnt->query('SELECT id_totem, codigo, nome, id_empresa, token_api, ativo FROM tb_totem')->fetchAll();
    afirmar('022 (antes): tb_totem.codigo = VARCHAR(30) e sem as colunas novas', (int) $pAnt->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'")->fetchColumn() === 30 && (int) $pAnt->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME IN ('criado_por','atualizado_em','url_regerada_em','url_versao')")->fetchColumn() === 0);
    $erroMig = null;
    try {
        qaQrAplicarSql($pAnt, $raiz . '/sql/migrations/022_totem_gestao.sql');
    } catch (Throwable $e) {
        $erroMig = get_class($e) . ': ' . $e->getMessage();
    }
    afirmar('022: 1a aplicacao sem erro' . ($erroMig !== null ? ' (' . $erroMig . ')' : ''), $erroMig === null);
    $estApos1 = $estrutura($pAnt);
    $erroMig = null;
    try {
        qaQrAplicarSql($pAnt, $raiz . '/sql/migrations/022_totem_gestao.sql');
    } catch (Throwable $e) {
        $erroMig = get_class($e);
    }
    afirmar('022: 2a aplicacao (idempotente) sem erro e a estrutura NAO muda', $erroMig === null && $estrutura($pAnt) === $estApos1);
    afirmar('022: codigo agora VARCHAR(64) NOT NULL e o indice UNIQUE de codigo foi mantido', (int) $pAnt->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'")->fetchColumn() === 64 && (int) $pAnt->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo' AND NON_UNIQUE = 0")->fetchColumn() === 1);
    $fkCriado = $pAnt->query("SELECT r.DELETE_RULE, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.CONSTRAINT_NAME = 'fk_totem_criado_por'")->fetch();
    afirmar('022: FK criado_por -> tb_gestao_usuario(id_usuario) com ON DELETE SET NULL, tipo igual ao da PK (INT UNSIGNED)', is_array($fkCriado) && $fkCriado['DELETE_RULE'] === 'SET NULL' && $fkCriado['REFERENCED_TABLE_NAME'] === 'tb_gestao_usuario' && $fkCriado['REFERENCED_COLUMN_NAME'] === 'id_usuario' && $pAnt->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'criado_por'")->fetchColumn() === $pAnt->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_gestao_usuario' AND COLUMN_NAME = 'id_usuario'")->fetchColumn());
    afirmar('022: indices idx_totem_empresa_ativo (id_empresa, ativo) e UNIQUE uk_totem_empresa_nome (id_empresa, nome) criados', count(array_filter($estApos1['indices'], static fn ($i) => $i['INDEX_NAME'] === 'idx_totem_empresa_ativo')) === 2 && count(array_filter($estApos1['indices'], static fn ($i) => $i['INDEX_NAME'] === 'uk_totem_empresa_nome' && (int) $i['NON_UNIQUE'] === 0)) === 2);
    afirmar('022: dados existentes INTACTOS (RECEPCAO-01 com o mesmo codigo, nome, empresa, token e ativo) e url_versao = 1', $pAnt->query('SELECT id_totem, codigo, nome, id_empresa, token_api, ativo FROM tb_totem')->fetchAll() === $antesMig && (int) $pAnt->query('SELECT url_versao FROM tb_totem')->fetchColumn() === 1);
    $pAnt->exec("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api) VALUES ('" . str_repeat('X', 64) . "', 'LONGO', 1, '" . bin2hex(random_bytes(32)) . "')");
    afirmar('022: aceita codigo de 64 caracteres depois de aplicada', (int) $pAnt->query("SELECT COUNT(*) FROM tb_totem WHERE CHAR_LENGTH(codigo) = 64")->fetchColumn() === 1);

    // (2) equivalencia: schema.sql NOVO + 014..022 (QA padrao) tem a MESMA estrutura de tb_totem
    afirmar('022 vs schema.sql: estrutura de tb_totem (colunas, indices e FKs) IDENTICA nos dois caminhos', $estrutura($pdo) === $estrutura($pAnt));
    afirmar('022: reaplicar 022 no banco criado pelo schema.sql novo e no-op (estrutura igual)', (static function () use ($pdo, $raiz, $estrutura): bool {
        $a = $estrutura($pdo);
        qaQrAplicarSql($pdo, $raiz . '/sql/migrations/022_totem_gestao.sql');

        return $estrutura($pdo) === $a;
    })());

    // (3) duplicados (id_empresa, nome): nao cria o UNIQUE, devolve SELECT informativo, depois de corrigir cria
    [$pDup, $bDup] = $criarVazio();
    $tmpSchema = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_schema_antigo_' . bin2hex(random_bytes(4)) . '.sql';
    file_put_contents($tmpSchema, (string) $schemaAntigo);
    qaQrAplicarSql($pDup, $tmpSchema);
    @unlink($tmpSchema);
    foreach ($prefixoMig as $m) {
        qaQrAplicarSql($pDup, $raiz . '/sql/migrations/' . $m . '.sql');
    }
    $pDup->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $pDup->exec("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api) VALUES ('DUP-A', 'REPETIDO', 1, '" . bin2hex(random_bytes(32)) . "'), ('DUP-B', 'REPETIDO', 1, '" . bin2hex(random_bytes(32)) . "'), ('SEMEMP-A', 'IGUAL', NULL, '" . bin2hex(random_bytes(32)) . "'), ('SEMEMP-B', 'IGUAL', NULL, '" . bin2hex(random_bytes(32)) . "')");
    // executa a 022 "a mao" para capturar o resultset do SELECT informativo
    $achouAviso = false;
    foreach (preg_split('/;\s*\R/', $sql022) as $cmd) {
        $cmd = trim($cmd);
        if ($cmd === '') {
            continue;
        }
        $st = $pDup->query($cmd);
        if ($st !== false) {
            do {
                $linhas = $st->fetchAll();
                foreach ($linhas as $l) {
                    if (isset($l['aviso']) && str_contains((string) $l['aviso'], 'uk_totem_empresa_nome NAO criado') && (string) $l['nome'] === 'REPETIDO' && (int) $l['repeticoes'] === 2) {
                        $achouAviso = true;
                    }
                }
            } while ($st->nextRowset());
            $st->closeCursor();
        }
    }
    afirmar('022 com nomes repetidos na mesma empresa: NAO cria o UNIQUE e devolve o SELECT informativo (par, repeticoes e aviso)', $achouAviso && (int) $pDup->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND INDEX_NAME = 'uk_totem_empresa_nome'")->fetchColumn() === 0);
    afirmar('022 com repetidos: as demais alteracoes (codigo 64, colunas, FK, indice) foram aplicadas e as linhas seguem intactas', (int) $pDup->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'")->fetchColumn() === 64 && (int) $pDup->query('SELECT COUNT(*) FROM tb_totem')->fetchColumn() === 4);
    $pDup->exec("UPDATE tb_totem SET nome = 'REPETIDO-2' WHERE codigo = 'DUP-B'");
    qaQrAplicarSql($pDup, $raiz . '/sql/migrations/022_totem_gestao.sql');
    afirmar('022 depois de corrigir o repetido: reaplicar cria o UNIQUE (e totens sem empresa nunca conflitam)', (int) $pDup->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND INDEX_NAME = 'uk_totem_empresa_nome'")->fetchColumn() === 2);
    $dupBloqueado = false;
    try {
        $pDup->exec("INSERT INTO tb_totem (codigo, nome, id_empresa, token_api) VALUES ('DUP-C', 'REPETIDO', 1, '" . bin2hex(random_bytes(32)) . "')");
    } catch (PDOException $e) {
        $dupBloqueado = (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
    afirmar('UNIQUE (id_empresa, nome) vale no banco: segundo INSERT igual => 1062', $dupBloqueado);

    // bootstraps dos QA incluem a 022
    afirmar('bootstraps QA aplicam a 022 (qa_db_bootstrap e qa_qr_exclusivo_bootstrap)', str_contains((string) file_get_contents(__DIR__ . '/qa_db_bootstrap.php'), '022_totem_gestao.sql') && str_contains((string) file_get_contents(__DIR__ . '/qa_qr_exclusivo_bootstrap.php'), '022_totem_gestao.sql'));
    afirmar('.env.example documenta TOTEM_URL_BASE vazia (sem valor real) e o 503 fail-closed', (static function () use ($raiz): bool {
        $e = (string) file_get_contents($raiz . '/.env.example');

        return preg_match('/^TOTEM_URL_BASE=$/m', $e) === 1 && str_contains($e, '503');
    })());
} finally {
    foreach ($bancos as $b) {
        try {
            qaQrDroparBanco($b);
        } catch (Throwable $e) {
        }
    }
    gtDestruirAmbiente(null, $storage);
    @unlink($log);
    @unlink($logRn);
}

exit(gtResumo('teste_gestao_totens'));
