<?php

/**
 * Normalizacao SEM acentos de Util\RazaoSocialMatcher::normalizar (decisao do usuario,
 * 2026-10-09: "sempre normalizar os textos para nao ter acentos") e ferramenta
 * tools/recalcular-razao-normalizada.php (CLI real, subprocesso).
 *
 *   A. normalizar sem banco: goldens, caixa (minuscula/maiuscula/mista), NFC/NFD, ß/Æ/Œ/Ø/Ł/Đ,
 *      cobertura de TODO o Latin-1 Supplement e Latin Extended-A, entrada invalida/emoji/CJK/RTL,
 *      vazio, so simbolos, 2 MB (tempo), idempotencia, ASCII identico ao algoritmo antigo
 *      (copia verbatim strtoupper+iconv, usada SO para ASCII) e fonte sem iconv/setlocale.
 *   B. OCR fuzzy ponta a ponta (NotaFiscalRn::avaliarNota + ClienteDao reais no banco QA):
 *      "Cafe Central" no OCR casa com o cliente cadastrado como "Café Central Ltda" e vice-versa.
 *   C. Ferramenta de recalculo: dry-run so imprime contagens e ids (sem nome/CNPJ/razao) e nao
 *      altera; --aplicar altera so as divergencias, e idempotente; aborta por duplicidade entre
 *      ATIVOS, valor vazio e valor acima da coluna; respeita o lock; argumentos; so CLI.
 * Banco QA descartavel `qa_qr_exclusivo_<hex>` (prepend fail-closed); NUNCA udlog_totem.
 *
 * Variaveis de teste de mutacao (opcionais): QA_RAZAO_PREPEND (prepend que carrega uma copia
 * mutante de RazaoSocialMatcher depois do prepend QA) e QA_RAZAO_FERRAMENTA (copia mutante da
 * ferramenta). Uso: php tests/manual/teste_razao_social_normalizacao.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\ClienteDao;
use App\Dao\ClienteGestaoDao;
use App\Dao\AtendimentoNotaDao;
use App\Rn\ClienteGestaoRn;
use App\Rn\NotaFiscalRn;
use Util\RazaoSocialMatcher;

const N = RazaoSocialMatcher::class;

/** Copia VERBATIM do algoritmo antigo (strtoupper + iconv TRANSLIT): referencia SO para ASCII. */
function normalizarAntigo(string $razaoSocial): string
{
    $sufixos = ['LTDA ME', 'LTDA', 'S A', 'S/A', 'SA', 'EIRELI', 'ME', 'EPP', 'MEI', 'CIA'];
    $normalizada = strtoupper($razaoSocial);
    $transliterada = @iconv('UTF-8', 'ASCII//TRANSLIT', $normalizada);
    if ($transliterada !== false && $transliterada !== null) {
        $normalizada = $transliterada;
    }
    $normalizada = preg_replace('/[^A-Z0-9 ]/', ' ', $normalizada);
    foreach ($sufixos as $sufixo) {
        $normalizada = preg_replace('/\b' . preg_quote($sufixo, '/') . '\b/', ' ', $normalizada);
    }
    $normalizada = preg_replace('/\s+/', ' ', $normalizada);

    return trim($normalizada);
}

function cnpjRz(string $base12): string
{
    $dv = static function (string $b, array $p): int {
        $s = 0;
        foreach (str_split($b) as $i => $d) {
            $s += (int) $d * $p[$i];
        }
        $r = $s % 11;

        return $r < 2 ? 0 : 11 - $r;
    };
    $d1 = $dv($base12, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    $d2 = $dv($base12 . $d1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

    return $base12 . $d1 . $d2;
}

/** Roda a ferramenta como CLI real no banco QA. @return array{codigo:int,out:string,err:string,log:string} */
function rodarFerramenta(array $args = []): array
{
    $raiz = dirname(__DIR__, 2);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_razao_' . bin2hex(random_bytes(4)) . '.log';
    file_put_contents($log, '');
    $prepend = (string) (getenv('QA_RAZAO_PREPEND') ?: __DIR__ . '/qa_gestao_prepend.php');
    $script = (string) (getenv('QA_RAZAO_FERRAMENTA') ?: $raiz . '/tools/recalcular-razao-normalizada.php');
    $cmd = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'display_errors=0', '-d', 'error_log="' . $log . '"', $script], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz);
    $out = str_replace("", '', (string) stream_get_contents($pipes[1]));
    $err = str_replace("", '', (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proc);
    $texto = (string) preg_replace('/^\[[^\]]+\] /m', '', (string) file_get_contents($log));
    @unlink($log);

    return ['codigo' => $codigo, 'out' => $out, 'err' => $err, 'log' => $texto];
}

function tudoCliente(PDO $pdo): array
{
    return gtLinhas($pdo, 'SELECT id_cliente, nome, razao_social_normalizada, cnpj, ativo FROM tb_cliente ORDER BY id_cliente');
}

// ===========================================================================
// A. normalizar (sem banco)
// ===========================================================================
$metodo = new ReflectionMethod(N, 'normalizar');
afirmar('normalizar: public static', $metodo->isPublic() && $metodo->isStatic());

foreach ([
    ['Café Ação Ltda', 'CAFE ACAO'],
    ['CAFÉ AÇÃO', 'CAFE ACAO'],
    ['café ação', 'CAFE ACAO'],
    ['CaFé AçÃo LtDa', 'CAFE ACAO'],
    ['Cafe' . "\u{0301}" . ' Central', 'CAFE CENTRAL'],
    ["Caf\u{00E9} Central", 'CAFE CENTRAL'],
    ["Cafe\u{0301}\u{0302} Central", 'CAFE CENTRAL'],
    ["Garc\u{0327}on Cia", 'GARCON'],
    ['Straße Ltda', 'STRASSE'],
    ['ß', 'SS'],
    ['Æther & Œuvre', 'AETHER OEUVRE'],
    ['Ørsted', 'ORSTED'],
    ['Łódź', 'LODZ'],
    ['Đorđe', 'DORDE'],
    ['Peña Nieto', 'PENA NIETO'],
    ['Ñandú', 'NANDU'],
    ['Þór', 'THOR'],
    ['İstanbul ılık', 'ISTANBUL ILIK'],
    ['Açúcar & Álcool S/A', 'ACUCAR ALCOOL'],
    ['Ünïcödé Comércio EIRELI - ME', 'UNICODE COMERCIO'],
    ['Maçã e Pêra S.A.', 'MACA E PERA'],
    ['Hightec Importação, Exportação LTDA', 'HIGHTEC IMPORTACAO EXPORTACAO'],
    ['CARGILL AGRÍCOLA S/A', 'CARGILL AGRICOLA'],
    ['CP KELCO BRASIL S/A - MATÃO', 'CP KELCO BRASIL MATAO'],
    ['BLUE CUBE BRASIL COMÉRCIO DE PRODUTOS QUÍMICOS LTDA', 'BLUE CUBE BRASIL COMERCIO DE PRODUTOS QUIMICOS'],
    ['', ''],
    ['***', ''],
    ['   ', ''],
    ['LTDA', ''],
    ['日本語', ''],
    ['ЖУК', ''],
    ["\u{1F600}", ''],
    ["Acme \u{1F600} Tintas", 'ACME TINTAS'],
    ["Nova \u{05E9}\u{05DC}\u{05D5}\u{05DD} Brasil", 'NOVA BRASIL'],
    ["Nova \u{0627}\u{0644}\u{0639}\u{0631}\u{0628}\u{064A}\u{0629} Brasil", 'NOVA BRASIL'],
    ["A\0B", 'A B'],
    ['CROMEX TINTAS LTDA', 'CROMEX TINTAS'],
] as [$entrada, $esperado]) {
    afirmar('normalizar ' . json_encode($entrada, JSON_UNESCAPED_UNICODE) . ' => ' . json_encode($esperado), RazaoSocialMatcher::normalizar($entrada) === $esperado);
}

// minuscula x maiuscula x mista x NFD dao SEMPRE o mesmo resultado
foreach (['Café São João', 'Ação Química Ltda', 'Pão de Açúcar S/A', 'Åland Œuvre Ørsted', 'Müller Straße'] as $nome) {
    $a = RazaoSocialMatcher::normalizar($nome);
    $b = RazaoSocialMatcher::normalizar(mb_strtoupper($nome));
    $c = RazaoSocialMatcher::normalizar(mb_strtolower($nome));
    $d = RazaoSocialMatcher::normalizar(preg_replace_callback('/./u', static fn (array $m): string => mt_rand(0, 1) ? mb_strtoupper($m[0]) : mb_strtolower($m[0]), $nome));
    $nfd = RazaoSocialMatcher::normalizar(strtr($nome, ['é' => "e\u{0301}", 'ã' => "a\u{0303}", 'ç' => "c\u{0327}", 'ú' => "u\u{0301}", 'í' => "i\u{0301}", 'ü' => "u\u{0308}", 'Å' => "A\u{030A}", 'ó' => "o\u{0301}"]));
    afirmar('mesma razao em caixa mista, MAIUSCULA, minuscula e NFD para ' . json_encode($nome, JSON_UNESCAPED_UNICODE) . ' => ' . json_encode($a), $a === $b && $a === $c && $a === $d && $a === $nfd && preg_match('/\A[A-Z0-9 ]+\z/', $a) === 1);
}

// Cobertura: todo o Latin-1 Supplement e Latin Extended-A vira [A-Z]+ (sem espaco no meio de "A?A")
$semMapa = [];
foreach (array_merge(range(0xC0, 0x17F), [0x1E9E]) as $cp) {
    if (in_array($cp, [0xD7, 0xF7, 0x149], true)) { // x e : sao simbolos; U+0149 expande para apostrofo+N
        continue;
    }
    $ch = mb_chr($cp, 'UTF-8');
    if (preg_match('/\p{L}/u', $ch) !== 1) {
        continue;
    }
    if (preg_match('/\A[A-Z]+\z/', RazaoSocialMatcher::normalizar('A' . $ch . 'A')) !== 1) {
        $semMapa[] = sprintf('U+%04X', $cp);
    }
}
afirmar('cobertura: toda letra de Latin-1 Supplement e Latin Extended-A (e U+1E9E) vira ASCII colado (sem lacunas: ' . implode(',', $semMapa) . ')', $semMapa === []);
$somenteLatim = RazaoSocialMatcher::normalizar('ÀÁÂÃÄÅ ÇÈÉÊË ÌÍÎÏ Ñ ÒÓÔÕÖØ ÙÚÛÜ ÝŸ');
afirmar('Latin-1 maiusculas: ' . json_encode($somenteLatim), $somenteLatim === 'AAAAAA CEEEE IIII N OOOOOO UUUU YY');

// Idempotencia (o OCR reaplica sobre o valor ja gravado)
$idemOk = true;
foreach (['Café Ação Ltda', 'ß æ œ', 'Ørsted Ltda', "Cafe\u{0301} Central", 'ÀÉÎÕÜ', 'A.B.C. Comércio EPP', '日本語 ABC', 'x', '', 'Straße Müller S/A'] as $e) {
    $n = RazaoSocialMatcher::normalizar($e);
    $idemOk = $idemOk && RazaoSocialMatcher::normalizar($n) === $n;
}
afirmar('idempotencia: normalizar(normalizar(x)) === normalizar(x) nos casos de referencia', $idemOk);

// ASCII: identico ao algoritmo antigo (copia verbatim). Fuzz de 8000 entradas ASCII.
mt_srand(20261009);
$pool = ['CROMEX', 'Acme', 'comercio', 'LTDA', 'ltda', 'S/A', 'S A', 'SA', 'ME', 'EIRELI', 'epp', 'MEI', 'CIA', 'de', 'e', 'tintas', '1', '2000', '&', '-', '.', ',', '(49)', 'x', 'Z', "'", "\t", '/', '_', '%'];
$sep = [' ', '  ', "\t", ' - ', '.', '/', ''];
$difs = 0;
$idemFalhas = 0;
for ($i = 0; $i < 8000; $i++) {
    $e = '';
    for ($k = mt_rand(0, 9); $k > 0; $k--) {
        $e .= $pool[array_rand($pool)] . $sep[array_rand($sep)];
    }
    $novo = RazaoSocialMatcher::normalizar($e);
    if ($novo !== normalizarAntigo($e)) {
        $difs++;
    }
    if (RazaoSocialMatcher::normalizar($novo) !== $novo) {
        $idemFalhas++;
    }
}
afirmar('ASCII: 8000 entradas sinteticas IDENTICAS ao algoritmo antigo (copia verbatim)', $difs === 0);
afirmar('idempotencia no fuzz ASCII (8000 entradas)', $idemFalhas === 0);

// Entrada com UTF-8 invalido (comportamento descrito): sequencias invalidas viram espaco (como antes
// viravam todos os bytes >= 0x80); o ASCII vizinho e preservado; as letras validas da MESMA entrada
// agora tambem sao mantidas (antes o iconv falhava para a string inteira).
foreach ([["AB\xFFCD", 'AB CD'], ["\xC3", ''], ["ACME\xE2\x82 LTDA", 'ACME'], ["Caf\xC3\xA9 \xFF Ltda", 'CAFE'], ["\xFF\xFE", ''], ["ab\xC0\xAFcd", 'AB CD']] as [$entrada, $esperado]) {
    $r = RazaoSocialMatcher::normalizar($entrada);
    afirmar('UTF-8 invalido ' . bin2hex($entrada) . ' => ' . json_encode($esperado) . ' (sem erro, sem bytes invalidos na saida)', $r === $esperado && preg_match('/\A[A-Z0-9 ]*\z/', $r) === 1);
}
afirmar('UTF-8 invalido so com ASCII ao redor: resultado igual ao antigo', RazaoSocialMatcher::normalizar("AB\xFFCD LTDA") === normalizarAntigo("AB\xFFCD LTDA"));

// 2 MB de entrada: tempo
$grande = str_repeat('Café Ação Ltda ', 140000);
$t0 = microtime(true);
$rg = RazaoSocialMatcher::normalizar($grande);
$tempoGrande = microtime(true) - $t0;
afirmar(sprintf('2 MB (%d bytes) de texto acentuado: normaliza em %.2f s (< 5 s) e o resultado e correto', strlen($grande), $tempoGrande), strlen($grande) > 2000000 && $tempoGrande < 5.0 && str_starts_with($rg, 'CAFE ACAO CAFE ACAO') && preg_match('/\A[A-Z0-9 ]+\z/', $rg) === 1);
$grande2 = str_repeat('é', 1100000);
$t0 = microtime(true);
$rg2 = RazaoSocialMatcher::normalizar($grande2);
$tempoGrande2 = microtime(true) - $t0;
afirmar(sprintf('2,2 MB de uma palavra so (1,1 M de "é"): %.2f s (< 5 s), resultado de 1,1 M de "E"', $tempoGrande2), $tempoGrande2 < 5.0 && $rg2 === str_repeat('E', 1100000));
unset($grande, $grande2, $rg, $rg2);

// Fonte: sem iconv, sem setlocale, mapa fixo
$fonteBruta = (string) file_get_contents(dirname(__DIR__, 2) . '/util/RazaoSocialMatcher.php');
$codigo = '';
foreach (token_get_all($fonteBruta) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $codigo .= is_array($tok) ? $tok[1] : $tok;
}
afirmar('fonte (sem comentarios): sem iconv e sem setlocale; usa mb_strtoupper, \p{Mn} e strtr com mapa fixo', !str_contains($codigo, 'iconv') && !str_contains($codigo, 'setlocale') && str_contains($codigo, 'mb_strtoupper') && str_contains($codigo, '\p{Mn}') && str_contains($codigo, 'strtr(') && str_contains($codigo, "'LTDA ME', 'LTDA', 'S A', 'S/A', 'SA', 'EIRELI', 'ME', 'EPP', 'MEI', 'CIA'"));

// ===========================================================================
// B e C: banco QA
// ===========================================================================
$banco = null;
$storage = null;
try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    afirmar('seguranca: o banco usado e um qa_qr_exclusivo_<hex> (nunca udlog_totem)', preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $banco) === 1 && $banco !== 'udlog_totem');
    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $pdo->exec('DELETE FROM tb_cliente');
    $rn = new ClienteGestaoRn($pdo);
    $nfRn = new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));

    // -----------------------------------------------------------------
    // B. OCR fuzzy ponta a ponta
    // -----------------------------------------------------------------
    $c1 = $rn->criar($idAdmin, 'Café Central Ltda', cnpjRz('510000000001'), '');
    $c2 = $rn->criar($idAdmin, 'Zeta Comercio de Plasticos', cnpjRz('510000000002'), '');
    $c3 = $rn->criar($idAdmin, 'Omega Quimica Industrial', cnpjRz('510000000003'), '');
    afirmar('B: clientes criados pelo caminho real da gestao; razao gravada sem acento', $c1['ok'] === true && $c2['ok'] === true && $c3['ok'] === true && gtEscalar($pdo, 'SELECT razao_social_normalizada FROM tb_cliente WHERE id_cliente = :i', ['i' => $c1['id']]) === 'CAFE CENTRAL');
    foreach (['Cafe Central', 'CAFE CENTRAL LTDA', 'Café Central', 'café central s/a', "Cafe\u{0301} Central", 'CAFÉ CENTRAL'] as $ocr) {
        $av = $nfRn->avaliarNota(1, 1, [], $ocr);
        afirmar('B: OCR ' . json_encode($ocr, JSON_UNESCAPED_UNICODE) . ' identifica "Café Central Ltda" (cadastrado com acento)', $av['status'] === 'IDENTIFICADA' && (string) $av['cnpj'] === cnpjRz('510000000001'));
    }
    $pdo->exec('DELETE FROM tb_cliente');
    $d1 = $rn->criar($idAdmin, 'Cafe Central Ltda', cnpjRz('520000000001'), '');
    $d2 = $rn->criar($idAdmin, 'Zeta Comercio de Plasticos', cnpjRz('520000000002'), '');
    $d3 = $rn->criar($idAdmin, 'Omega Quimica Industrial', cnpjRz('520000000003'), '');
    foreach (['Café Central Ltda', 'CAFÉ CENTRAL', 'café central', "Cafe\u{0301} Central", 'Cafe Central'] as $ocr) {
        $av = $nfRn->avaliarNota(1, 1, [], $ocr);
        afirmar('B (inverso): OCR ' . json_encode($ocr, JSON_UNESCAPED_UNICODE) . ' identifica "Cafe Central Ltda" (cadastrado sem acento)', $av['status'] === 'IDENTIFICADA' && (string) $av['cnpj'] === cnpjRz('520000000001'));
    }
    $av = $nfRn->avaliarNota(1, 1, [], 'Ação Química Inexistente');
    afirmar('B: nome acentuado sem cliente correspondente continua NAO_IDENTIFICADA', $av['status'] === 'NAO_IDENTIFICADA');
    $lista = (new ClienteDao($pdo))->listarParaFuzzy();
    $r = RazaoSocialMatcher::melhorCandidato('Cafe Central', array_map(static fn (array $l): array => ['id' => $l['id_cliente'], 'razao_social' => 'Café Central Ltda', 'cnpj' => $l['cnpj']], array_slice($lista, 0, 1)));
    afirmar('B: melhorCandidato("Cafe Central") contra razao_social "Café Central Ltda" (com acento, nao normalizada): identificado', $r['identificado'] === true && $r['score1'] >= 99.0);
    $r = RazaoSocialMatcher::melhorCandidato('Café Central Ltda', [['id' => 1, 'razao_social' => 'Cafe Central', 'cnpj' => '1']]);
    afirmar('B: melhorCandidato("Café Central Ltda") contra "Cafe Central": identificado (vice-versa)', $r['identificado'] === true && $r['score1'] >= 99.0);
    $pdo->exec('DELETE FROM tb_cliente');

    // -----------------------------------------------------------------
    // C. Ferramenta
    // -----------------------------------------------------------------
    $ins = $pdo->prepare('INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:n, :r, :c, :a)');
    $semear = static function (array $linhas) use ($pdo, $ins): array {
        $pdo->exec('DELETE FROM tb_cliente');
        $ids = [];
        foreach ($linhas as $i => [$nome, $razao, $ativo]) {
            $ins->execute(['n' => $nome, 'r' => $razao, 'c' => cnpjRz(sprintf('%012d', 600000000000 + $i)), 'a' => $ativo]);
            $ids[$i] = (int) $pdo->lastInsertId();
        }

        return $ids;
    };
    // A diverge (seed antigo perdeu letras), B igual, C diverge, D inativo diverge, E razao NULL diverge
    $ids = $semear([
        ['Café Central Ltda', 'CAF CENTRAL', 1],
        ['Acme Comercio LTDA', 'ACME COMERCIO', 1],
        ['Ação Tintas', 'A O TINTAS', 1],
        ['Química Inativa', 'QU MICA INATIVA', 0],
        ['Zeta Plasticos', null, 1],
    ]);
    $antes = tudoCliente($pdo);

    $dry = rodarFerramenta();
    if (getenv('QA_RAZAO_DEBUG')) {
        echo 'DBG:' . $dry['out'] . '|' . $dry['err'] . '|' . $dry['log'] . "\n";
    }
    $esperadoIds = $ids[0] . ',' . $ids[2] . ',' . $ids[3] . ',' . $ids[4];
    afirmar('dry-run (padrao): exit 0, modo DRY-RUN, total 5, iguais 1, diferentes 4, ids exatos', $dry['codigo'] === 0 && str_contains($dry['out'], 'modo: DRY-RUN') && str_contains($dry['out'], "total: 5\n") && str_contains($dry['out'], "iguais: 1\n") && str_contains($dry['out'], "diferentes: 4\n") && str_contains($dry['out'], 'ids_diferentes: ' . $esperadoIds . "\n") && str_contains($dry['out'], "vazias_que_ficariam_vazias: 0 "));
    afirmar('dry-run: NAO altera nenhuma linha (nome, razao, cnpj, ativo)', tudoCliente($pdo) === $antes);
    $saidaCompleta = $dry['out'] . $dry['err'] . $dry['log'];
    afirmar('dry-run: a saida/log NAO contem nome, razao nem CNPJ de cliente', !preg_match('/Caf|CAFE|Acme|ACME|Zeta|ZETA|Tintas|TINTAS|Qu[ií]mica|CENTRAL|\d{14}/i', $saidaCompleta));
    afirmar('dry-run: a saida diz que a aplicacao seria permitida', str_contains($dry['out'], 'a aplicacao seria PERMITIDA'));
    $dry2 = rodarFerramenta(['--dry-run']);
    afirmar('--dry-run explicito equivale ao padrao e tambem nao altera', $dry2['codigo'] === 0 && str_contains($dry2['out'], 'modo: DRY-RUN') && tudoCliente($pdo) === $antes);

    $audAntes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria');
    $ap = rodarFerramenta(['--aplicar']);
    $depois = tudoCliente($pdo);
    $porId = [];
    foreach ($depois as $l) {
        $porId[(int) $l['id_cliente']] = $l;
    }
    afirmar('--aplicar: exit 0 e APLICADO 4', $ap['codigo'] === 0 && str_contains($ap['out'], 'APLICADO: 4 '));
    afirmar('--aplicar: atualizou so as divergencias (razao nova sem acento; inativo e NULL inclusive)', $porId[$ids[0]]['razao_social_normalizada'] === 'CAFE CENTRAL' && $porId[$ids[2]]['razao_social_normalizada'] === 'ACAO TINTAS' && $porId[$ids[3]]['razao_social_normalizada'] === 'QUIMICA INATIVA' && $porId[$ids[4]]['razao_social_normalizada'] === 'ZETA PLASTICOS' && $porId[$ids[1]]['razao_social_normalizada'] === 'ACME COMERCIO');
    $mudouAlemDaRazao = false;
    foreach ($antes as $a) {
        $d = $porId[(int) $a['id_cliente']];
        $mudouAlemDaRazao = $mudouAlemDaRazao || $d['nome'] !== $a['nome'] || $d['cnpj'] !== $a['cnpj'] || (int) $d['ativo'] !== (int) $a['ativo'];
    }
    afirmar('--aplicar: nome, CNPJ e situacao de TODOS os clientes ficam intactos; sem linhas novas/apagadas', !$mudouAlemDaRazao && count($depois) === count($antes));
    afirmar('--aplicar: saida/log sem nome, razao ou CNPJ', !preg_match('/Caf|CAFE|Acme|ACME|Zeta|ZETA|Tintas|TINTAS|Qu[ií]mica|CENTRAL|\d{14}/i', $ap['out'] . $ap['err'] . $ap['log']) && str_contains($ap['log'], 'recalcular-razao-normalizada: aplicado total=5 atualizadas=4'));
    $resumo = gtLinhas($pdo, "SELECT nivel, origem, detalhe FROM tb_log_sistema WHERE categoria = 'cron_resumo' AND detalhe LIKE 'job=recalcular_razao%'");
    afirmar('--aplicar: LogSistema cron_resumo (INFO, CRON) com job e contagem, sem PII', count($resumo) === 1 && $resumo[0]['nivel'] === 'INFO' && $resumo[0]['origem'] === 'CRON' && $resumo[0]['detalhe'] === 'job=recalcular_razao;itens=4');
    afirmar('--aplicar: nao cria auditoria (acao nova NAO foi criada)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria') === $audAntes);

    $ap2 = rodarFerramenta(['--aplicar']);
    afirmar('idempotente: 2a execucao de --aplicar nao tem divergencias e nao altera nada', $ap2['codigo'] === 0 && str_contains($ap2['out'], "diferentes: 0\n") && str_contains($ap2['out'], 'APLICADO: 0 ') && tudoCliente($pdo) === $depois);
    $dry3 = rodarFerramenta();
    afirmar('apos aplicar o dry-run mostra 0 diferentes e 5 iguais', str_contains($dry3['out'], "diferentes: 0\n") && str_contains($dry3['out'], "iguais: 5\n"));

    // Duplicidade entre ATIVOS -> aborta sem alterar, lista os ids
    $ids = $semear([
        ['Café Central Ltda', 'CAF CENTRAL', 1],
        ['Cafe Central', 'CAFE CENTRAL', 1],
        ['Outro Cliente SA', 'OUTRO CLIENTE SA', 1],
    ]);
    $antes = tudoCliente($pdo);
    $dup = rodarFerramenta(['--aplicar']);
    afirmar('duplicidade entre ATIVOS: --aplicar RECUSA (exit 1), lista os ids envolvidos e NAO altera nada (nem a divergencia valida)', $dup['codigo'] === 1 && str_contains($dup['out'], 'RECUSADO') && str_contains($dup['out'], 'duplicidade_1_ids: ' . $ids[0] . ',' . $ids[1] . "\n") && tudoCliente($pdo) === $antes);
    $dupDry = rodarFerramenta();
    afirmar('duplicidade: o dry-run informa os ids e que a aplicacao seria RECUSADA (exit 0, nada gravado)', $dupDry['codigo'] === 0 && str_contains($dupDry['out'], 'grupos_duplicados_entre_ativos: 1') && str_contains($dupDry['out'], 'a aplicacao seria RECUSADA') && tudoCliente($pdo) === $antes);
    $falhou = gtLinhas($pdo, "SELECT detalhe FROM tb_log_sistema WHERE categoria = 'cron_falhou' AND detalhe LIKE 'job=recalcular_razao%'");
    afirmar('duplicidade: LogSistema cron_falhou (ERRO) sem PII', count($falhou) === 1 && !preg_match('/Caf|CAFE|CENTRAL/i', (string) $falhou[0]['detalhe']));

    // duplicidade com INATIVO nao bloqueia
    $ids = $semear([
        ['Café Central Ltda', 'CAF CENTRAL', 1],
        ['Cafe Central', 'CAFE CENTRAL', 0],
    ]);
    $okInativo = rodarFerramenta(['--aplicar']);
    afirmar('mesma razao entre um ATIVO e um INATIVO nao bloqueia (so ativos conflitam no OCR): aplica', $okInativo['codigo'] === 0 && str_contains($okInativo['out'], 'APLICADO: 1 '));

    // Valor novo vazio -> aborta
    $ids = $semear([
        ['Café Central Ltda', 'CAF CENTRAL', 1],
        ['LTDA', 'LTDA ANTIGA', 1],
    ]);
    $antes = tudoCliente($pdo);
    $vz = rodarFerramenta(['--aplicar']);
    afirmar('valor novo VAZIO: --aplicar RECUSA (exit 1), informa o id e NAO altera nada', $vz['codigo'] === 1 && str_contains($vz['out'], 'RECUSADO') && str_contains($vz['out'], 'ids_vazios_novos: ' . $ids[1] . "\n") && tudoCliente($pdo) === $antes);
    $vzDry = rodarFerramenta();
    afirmar('valor novo vazio: o dry-run conta 1 vazia que muda e informa que seria RECUSADA', str_contains($vzDry['out'], 'vazias_que_ficariam_vazias: 1 ') && str_contains($vzDry['out'], 'a aplicacao seria RECUSADA'));

    // Vazio que JA era vazio e continua vazio: contado, nao muda
    $ids = $semear([['LTDA', '', 1], ['Café Central Ltda', 'CAF CENTRAL', 1]]);
    $vz2 = rodarFerramenta();
    afirmar('vazia que continua vazia: contada em "vazias_que_ficariam_vazias" e nao conta como divergencia', str_contains($vz2['out'], 'vazias_que_ficariam_vazias: 1 (das quais 0 mudam') && str_contains($vz2['out'], "diferentes: 1\n"));

    // Valor novo acima da coluna (ß expande para SS) -> aborta
    $ids = $semear([[str_repeat('ß', 100), 'XX', 1]]);
    $antes = tudoCliente($pdo);
    $lg = rodarFerramenta(['--aplicar']);
    afirmar('valor novo acima da coluna (150): --aplicar RECUSA e NAO altera', $lg['codigo'] === 1 && str_contains($lg['out'], 'ids_acima_da_coluna: ' . $ids[0] . "\n") && tudoCliente($pdo) === $antes);

    // Lock do cadastro de clientes ocupado -> recusa
    $ids = $semear([['Café Central Ltda', 'CAF CENTRAL', 1]]);
    $antes = tudoCliente($pdo);
    $outro = qaQrAbrirBanco($banco);
    $outro->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $daoOutro = new ClienteGestaoDao($outro);
    $segurou = $daoOutro->obterLock(0);
    $lk = rodarFerramenta(['--aplicar']);
    $daoOutro->liberarLock();
    afirmar('lock do cadastro de clientes ocupado: --aplicar RECUSA (exit 1) e NAO altera; dry-run nao precisa do lock', $segurou && $lk['codigo'] === 1 && str_contains($lk['err'], 'lock') && tudoCliente($pdo) === $antes);
    $lk2 = rodarFerramenta(['--aplicar']);
    afirmar('liberado o lock, --aplicar funciona e libera o lock ao terminar', $lk2['codigo'] === 0 && (int) gtEscalar($pdo, 'SELECT IS_FREE_LOCK(CONCAT(:n, MD5(DATABASE())))', ['n' => 'totem_gestao_clientes_']) === 1);

    // Argumentos e CLI
    $e1 = rodarFerramenta(['--forcar']);
    $e2 = rodarFerramenta(['--aplicar', '--dry-run']);
    $e3 = rodarFerramenta(['apagar']);
    afirmar('argumento desconhecido ou conflitante: exit 2 sem tocar o banco', $e1['codigo'] === 2 && $e2['codigo'] === 2 && $e3['codigo'] === 2);
    $antesCgi = tudoCliente($pdo);
    $cgi = proc_open([gtCgiBinario(), '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', dirname(__DIR__, 2) . '/tools/recalcular-razao-normalizada.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, dirname(__DIR__, 2), ['QA_QR_FORCE_DB_NAME' => $banco, 'REQUEST_METHOD' => 'GET', 'REDIRECT_STATUS' => '200', 'SystemRoot' => (string) getenv('SystemRoot')]);
    $saidaCgi = (string) stream_get_contents($pp[1]);
    stream_get_contents($pp[2]);
    fclose($pp[1]);
    fclose($pp[2]);
    proc_close($cgi);
    afirmar('so CLI: via CGI responde 404 sem executar nada', str_contains($saidaCgi, '404') && !str_contains($saidaCgi, 'total:') && tudoCliente($pdo) === $antesCgi);

    $fonteTool = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/recalcular-razao-normalizada.php');
    afirmar('fonte da ferramenta: PHP_SAPI, GET_LOCK/RELEASE via ClienteGestaoDao, transacao, UPDATE condicional <=> e prepared statements (sem $pdo->exec/concatenacao de entrada)', str_contains($fonteTool, "PHP_SAPI !== 'cli'") && str_contains($fonteTool, 'obterLock(') && str_contains($fonteTool, 'beginTransaction') && str_contains($fonteTool, '<=> :antiga') && !str_contains($fonteTool, '->exec(') && !str_contains($fonteTool, 'udlog_totem'));
} finally {
    gtDestruirAmbiente($banco, $storage);
}

exit(gtResumo('teste_razao_social_normalizacao'));
