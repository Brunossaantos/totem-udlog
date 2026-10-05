<?php

/**
 * Teste manual SEM banco/Talent/HTTP/impressao:
 * (a) Util\TextoEtiqueta::paraAscii (acentos pt/es/fr, maiusculas, c cedilha, n til, ss,
 *     emoji/simbolos, controles, UTF-8 invalido, vazio, 300 chars);
 * (b) PDFs via reflection (producao motorista/ajudante e diagnostico motorista/ajudante):
 *     texto extraido (pdftotext) so ASCII; ajudante: 1 pagina, MediaBox 80x80, x <= 49 mm,
 *     base <= 77 mm, "AJUDANTE", nome sem acento, mesmo nrRegAcesso e "Gerado em".
 *
 * Uso: php tests/manual/teste_texto_etiqueta.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Controller\ImpressaoAtendimentoController;
use App\Controller\ImpressaoTesteController;
use Util\TextoEtiqueta;

\Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->load();

$total = 0; $falhas = 0;
function afirmar(string $d, bool $c): void { global $total, $falhas; $total++; echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n"; if (!$c) $falhas++; }
function eq(string $d, string $obtido, string $esperado): void { afirmar($d . ($obtido === $esperado ? '' : " [obtido '" . $obtido . "']"), $obtido === $esperado); }

echo '(intl Normalizer: ' . (class_exists('Normalizer', false) ? 'presente' : 'ausente') . ")\n";

// ---------- (a) helper ----------
eq('pt minusculas', TextoEtiqueta::paraAscii("\u{E1}\u{E0}\u{E2}\u{E3}\u{E9}\u{EA}\u{ED}\u{F3}\u{F4}\u{F5}\u{FA}\u{FC}\u{E7}"), 'aaaaeeiooouuc');
eq('pt MAIUSCULAS', TextoEtiqueta::paraAscii("\u{C1}\u{C0}\u{C2}\u{C3}\u{C9}\u{CA}\u{CD}\u{D3}\u{D4}\u{D5}\u{DA}\u{DC}\u{C7}"), 'AAAAEEIOOOUUC');
eq('nome com cedilha', TextoEtiqueta::paraAscii("JOSE ANTONIO A\u{C7}UCENA"), 'JOSE ANTONIO ACUCENA');
eq('nome Jose Antonio Joao', TextoEtiqueta::paraAscii("Jos\u{E9} Ant\u{F4}nio Jo\u{E3}o"), 'Jose Antonio Joao');
eq('es: n til, ?, !, acentos', TextoEtiqueta::paraAscii("Mu\u{F1}oz \u{D1}and\u{FA} \u{BF}Qu\u{E9}? \u{A1}Hola!"), 'Munoz Nandu Que? Hola!');
eq('fr: grave/circunflexo/trema/oe/ae', TextoEtiqueta::paraAscii("\u{C8}lo\u{EF}se \u{E0} la for\u{EA}t \u{153}uvre \u{C6}on na\u{EF}ve No\u{EB}l"), 'Eloise a la foret oeuvre AEon naive Noel');
eq('ss', TextoEtiqueta::paraAscii("Stra\u{DF}e GRO\u{DF}"), 'Strasse GROss');
eq('outros europeus (l barrado, o barrado, d barrado)', TextoEtiqueta::paraAscii("\u{141}\u{F3}d\u{17A} \u{D8}stergaard \u{110}or\u{111}e"), 'Lodz Ostergaard Dorde');
eq('ordinais e aspas tipograficas', TextoEtiqueta::paraAscii("N\u{BA} 5 \u{201C}x\u{201D} O\u{2019}Brien \u{2013} a"), 'No 5 "x" O\'Brien - a');
eq('emoji removido', TextoEtiqueta::paraAscii("Ana \u{1F600} Silva"), 'Ana Silva');
eq('simbolos removidos', TextoEtiqueta::paraAscii("A\u{20AC}B\u{2122}C\u{2603}D"), 'ABCD');
eq('controles viram espaco', TextoEtiqueta::paraAscii("Ana\x00\x07\nSilva\tX\x1F\x7F"), 'Ana Silva X');
eq('espacos Unicode (nbsp, ideografico) colapsam', TextoEtiqueta::paraAscii("A\u{A0}\u{A0}B\u{3000}C   D"), 'A B C D');
eq('zero width removido', TextoEtiqueta::paraAscii("Jo\u{200B}ao"), 'Joao');
eq('trim', TextoEtiqueta::paraAscii("   Jo\u{E3}o   "), 'Joao');
eq('vazio', TextoEtiqueta::paraAscii(''), '');
eq('so espacos/controles => vazio', TextoEtiqueta::paraAscii(" \t\n\x00 "), '');
eq('so emoji => vazio', TextoEtiqueta::paraAscii("\u{1F600}\u{1F680}"), '');
eq('latin1 invalido em UTF-8 (Jo\xE3o) => Joao', TextoEtiqueta::paraAscii("Jo\xE3o"), 'Joao');
eq('latin1 invalido (A\xC7UCENA \xC9) => ACUCENA E', TextoEtiqueta::paraAscii("A\xC7UCENA \xC9"), 'ACUCENA E');
afirmar('bytes invalidos soltos (\xFF\xFE) nao lancam e retornam ASCII', preg_match('/\A[\x20-\x7E]*\z/', TextoEtiqueta::paraAscii("Jo\xE3o \xFF\xFE")) === 1);
$longo = str_repeat("A\u{E7}\u{E3} ", 75); // 300 caracteres
afirmar('300 caracteres: ASCII e sem excecao', preg_match('/\A[\x20-\x7E]*\z/', TextoEtiqueta::paraAscii($longo)) === 1 && strlen(TextoEtiqueta::paraAscii($longo)) === 299);
eq('limite $max', TextoEtiqueta::paraAscii("\u{E7}" . str_repeat('x', 300), 150), 'c' . str_repeat('x', 149));
eq('idempotente', TextoEtiqueta::paraAscii(TextoEtiqueta::paraAscii("Jos\u{E9} \u{C7}")), 'Jose C');
eq('ASCII puro intacto', TextoEtiqueta::paraAscii('ABC-123 (x) \\ / # "q"'), 'ABC-123 (x) \\ / # "q"');
// toda a faixa Latin-1 Supplement/Latin Extended-A: saida sempre ASCII imprimivel
$todos = '';
for ($cp = 0x80; $cp <= 0x17F; $cp++) { $todos .= mb_chr($cp, 'UTF-8') . ' '; }
afirmar('Latin-1 Sup + Ext-A: so ASCII imprimivel', preg_match('/\A[\x20-\x7E]*\z/', TextoEtiqueta::paraAscii($todos)) === 1);
$letras = 0; $semMapa = [];
for ($cp = 0xC0; $cp <= 0x17F; $cp++) {
    if ($cp === 0xD7 || $cp === 0xF7) continue; // multiplicacao/divisao
    $r = TextoEtiqueta::paraAscii(mb_chr($cp, 'UTF-8'));
    if ($r === '') $semMapa[] = dechex($cp);
}
afirmar('toda letra Latin-1/Ext-A tem equivalente (sem mapa: ' . implode(',', $semMapa) . ')', $semMapa === []);

// ---------- (b) PDFs ----------
function lerPdf(string $pdf): array
{
    $k = 25.4 / 72;
    preg_match_all('/\/Type \/Page\b(?!s)/', $pdf, $mp);
    preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $pdf, $mb);
    $stream = '';
    if (preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $ms)) {
        $dec = @gzuncompress($ms[1]);
        $stream = $dec !== false ? $dec : $ms[1];
    }
    $altura = (float) ($mb[2] ?? 0);
    $linhas = []; $pt = 0.0;
    foreach (preg_split('/\r?\n/', $stream) as $op) {
        if (preg_match('/^BT \/F\d+ ([\d.]+) Tf ET$/', $op, $m)) { $pt = (float) $m[1]; }
        elseif (preg_match('/^BT ([\d.\-]+) ([\d.\-]+) Td \((.*)\) Tj ET$/', $op, $m)) {
            $linhas[] = ['x' => (float) $m[1] * $k, 'y' => ($altura - (float) $m[2]) * $k, 'pt' => $pt, 'txt' => stripcslashes(str_replace(['\(', '\)'], ['(', ')'], $m[3]))];
        }
    }
    return ['paginas' => count($mp[0]), 'mediabox' => [round((float) ($mb[1] ?? 0) * $k, 1), round($altura * $k, 1)], 'linhas' => $linhas];
}

function textoPdf(string $pdf): string
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tx_etq_' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($tmp, $pdf);
    $out = [];
    exec('pdftotext -enc UTF-8 ' . escapeshellarg($tmp) . ' - 2>&1', $out, $cod);
    @unlink($tmp);
    return $cod === 0 ? implode("\n", $out) : '';
}

function verificarGeometria(string $rotulo, string $pdf, bool $apenasAscii = true): array
{
    $r = lerPdf($pdf);
    afirmar("$rotulo: 1 pagina", $r['paginas'] === 1);
    afirmar("$rotulo: MediaBox 80x80 mm", $r['mediabox'] === [80.0, 80.0]);
    $f = new FPDF('P', 'mm', [80, 80]);
    $xMin = 99; $xMax = 0; $yMax = 0;
    foreach ($r['linhas'] as $l) {
        $f->SetFont('Arial', in_array($l['txt'], ['AJUDANTE', 'UDLOG'], true) || preg_match('/^\d+$/', $l['txt']) ? 'B' : '', $l['pt']);
        $xMin = min($xMin, $l['x']); $xMax = max($xMax, $l['x'] + $f->GetStringWidth($l['txt'])); $yMax = max($yMax, $l['y']);
    }
    afirmar("$rotulo: x entre 1 e 49 mm (" . round($xMin, 2) . ' a ' . round($xMax, 2) . ')', $xMin >= 0.99 && $xMax <= 49.01);
    afirmar("$rotulo: base <= 77 mm (" . round($yMax, 2) . ')', $yMax <= 77.0);
    $txt = textoPdf($pdf);
    afirmar("$rotulo: pdftotext extraiu texto", trim($txt) !== '');
    afirmar("$rotulo: texto extraido sem nao-ASCII", preg_match('/[^\x09\x0A\x0C\x0D\x20-\x7E]/', $txt) !== 1);
    $todosTx = implode("\n", array_column($r['linhas'], 'txt'));
    afirmar("$rotulo: linhas do conteudo so ASCII", preg_match('/[^\x20-\x7E\n]/', $todosTx) !== 1);
    return ['r' => $r, 'txt' => $txt];
}

$ref = new ReflectionClass(ImpressaoAtendimentoController::class);
$ctl = $ref->newInstanceWithoutConstructor();
$mPdf = $ref->getMethod('montarPdf'); $mPdf->setAccessible(true);
$mSan = $ref->getMethod('sanitizarNomeAjudante'); $mSan->setAccessible(true);
$cfg = ['largura_mm' => 80.0, 'comprimento_mm' => 80.0, 'orientacao' => 'portrait', 'corte_apos_impressao' => true];
$nr = 'A12345';

// motorista com acentos (e ajudante com acentos no mesmo PDF)
$pdf = $mPdf->invoke($ctl, $cfg, "JOS\u{C9} ANT\u{D4}NIO A\u{C7}UCENA", $nr, $mSan->invoke($ctl, "Jo\u{E3}o M\u{FC}ller \u{1F600}"), 'motorista');
$v = verificarGeometria('producao motorista', $pdf);
afirmar('producao motorista: contem JOSE ANTONIO ACUCENA e UDLOG', str_contains($v['txt'], 'JOSE ANTONIO ACUCENA') && str_contains($v['txt'], 'UDLOG'));
afirmar('producao motorista com ajudante: nao contem "Ajudante" (qualquer caixa) nem o nome do ajudante', stripos($v['txt'], 'Ajudante') === false && !str_contains($v['txt'], 'Muller') && !str_contains($v['txt'], 'Joao M'));

// motorista sem ajudante: layout preservado (22/15/15/60->ajustado/9) = 4 blocos
$pdf = $mPdf->invoke($ctl, $cfg, "JOS\u{C9} A\u{C7}UCENA", $nr, '', 'motorista');
$r = lerPdf($pdf);
afirmar('producao motorista sem ajudante: sem "Ajudante:"', !in_array('Ajudante:', array_column($r['linhas'], 'txt'), true));

// ajudante
foreach ([
    'ajudante curto' => "Jo\u{E3}o Z\u{E9}",
    'ajudante acentuado' => "Jos\u{E9} Ant\u{F4}nio \u{C7}\u{E3}o M\u{FC}ller",
    'ajudante longo (3 linhas)' => "Francisco Jos\u{E9} Alexandre de Albuquerque Cavalcanti Montenegro Pereira Filho Neto",
    'ajudante 150 chars sem espaco-palavra longa' => str_repeat("Wwwwwwwwwww ", 13),
] as $rotulo => $nomeAj) {
    $nomeS = $mSan->invoke($ctl, $nomeAj);
    $pdf = $mPdf->invoke($ctl, $cfg, 'MOTORISTA QUALQUER', $nr, $nomeS, 'ajudante');
    $v = verificarGeometria("etiqueta $rotulo", $pdf);
    $txtNorm = preg_replace('/\s+/', ' ', $v['txt']);
    afirmar("etiqueta $rotulo: contem AJUDANTE", str_contains($v['txt'], 'AJUDANTE'));
    afirmar("etiqueta $rotulo: nao contem UDLOG nem nome do motorista", !str_contains($v['txt'], 'UDLOG') && !str_contains($v['txt'], 'MOTORISTA'));
    afirmar("etiqueta $rotulo: contem o nome sem acentos", str_contains($txtNorm, $nomeS) && $nomeS !== '');
    afirmar("etiqueta $rotulo: contem o mesmo nrRegAcesso", str_contains($v['txt'], $nr));
    afirmar("etiqueta $rotulo: contem 'Gerado em:'", str_contains($v['txt'], 'Gerado em:'));
    afirmar("etiqueta $rotulo: nao contem 'Ajudante:' (rotulo do bloco do motorista)", !str_contains($v['txt'], 'Ajudante:'));
}

// diagnostico
$refT = new ReflectionClass(ImpressaoTesteController::class);
$ctlT = $refT->newInstanceWithoutConstructor();
$mT = $refT->getMethod('montarPdf'); $mT->setAccessible(true);
$v = verificarGeometria('diagnostico motorista', $mT->invoke($ctlT, $cfg, 'abc123', 'motorista'));
afirmar('diagnostico motorista: ETIQUETA DE TESTE, sem "Ajudante" e sem TESTE FICTICIO', str_contains($v['txt'], 'ETIQUETA DE TESTE') && stripos($v['txt'], 'Ajudante') === false && !str_contains($v['txt'], 'TESTE FICTICIO'));
$v = verificarGeometria('diagnostico ajudante', $mT->invoke($ctlT, $cfg, 'abc123', 'ajudante'));
afirmar('diagnostico ajudante: AJUDANTE, TESTE FICTICIO, 123456, ETIQUETA DE TESTE, Gerado em:', str_contains($v['txt'], 'AJUDANTE') && str_contains($v['txt'], 'TESTE FICTICIO') && str_contains($v['txt'], '123456') && str_contains($v['txt'], 'ETIQUETA DE TESTE') && str_contains($v['txt'], 'Gerado em:'));

echo "\n=== RESULTADO: {$total} testes, " . ($total - $falhas) . " passaram, {$falhas} falharam ===\n";
exit($falhas > 0 ? 1 : 0);
