<?php

/**
 * Normalizacao SEM acentos de Util\RazaoSocialMatcher::normalizar (decisao do usuario,
 * 2026-10-09: "sempre normalizar os textos para nao ter acentos").
 *
 *   A. normalizar sem banco: goldens, caixa (minuscula/maiuscula/mista), NFC/NFD, ß/Æ/Œ/Ø/Ł/Đ,
 *      cobertura de TODO o Latin-1 Supplement e Latin Extended-A, entrada invalida/emoji/CJK/RTL,
 *      vazio, so simbolos, 2 MB (tempo), idempotencia, ASCII identico ao algoritmo antigo
 *      (copia verbatim strtoupper+iconv, usada SO para ASCII) e fonte sem iconv/setlocale.
 *   B. OCR fuzzy ponta a ponta (NotaFiscalRn::avaliarNota + ClienteDao reais no banco QA):
 *      "Cafe Central" no OCR casa com o cliente cadastrado como "Café Central Ltda" e vice-versa.
 * Banco QA descartavel `qa_qr_exclusivo_<hex>` (prepend fail-closed); NUNCA udlog_totem.
 *
 * Variavel de teste de mutacao (opcional): QA_RAZAO_PREPEND (prepend que carrega uma copia
 * mutante de RazaoSocialMatcher depois do prepend QA). Uso: php tests/manual/teste_razao_social_normalizacao.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\ClienteDao;
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
// B: banco QA
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
} finally {
    gtDestruirAmbiente($banco, $storage);
}

exit(gtResumo('teste_razao_social_normalizacao'));
