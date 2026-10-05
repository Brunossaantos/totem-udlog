<?php

/**
 * Teste manual SEM banco/Talent/HTTP: etiquetas de producao (motorista e
 * ajudante; ImpressaoAtendimentoController::montarPdf via reflection).
 * Confere: 1 pagina, MediaBox 80x80 mm, toda linha com x entre 1 e 49 mm,
 * base do texto <= 77 mm; a etiqueta do MOTORISTA nunca traz "Ajudante" nem o
 * nome do ajudante e tem as mesmas medidas com ou sem ajudante (4 blocos:
 * UDLOG, motorista, numero, data); a etiqueta do AJUDANTE segue valida.
 *
 * Uso: php tests/manual/teste_etiqueta_ajudante.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Controller\ImpressaoAtendimentoController;

\Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->load();

$total = 0; $falhas = 0;
function afirmar(string $d, bool $c): void { global $total, $falhas; $total++; echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n"; if (!$c) $falhas++; }

/** @return array{paginas:int, mediabox:array, linhas:list<array{x:float,y:float,pt:float,txt:string}>} */
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

$ref = new ReflectionClass(ImpressaoAtendimentoController::class);
$ctl = $ref->newInstanceWithoutConstructor();
$mPdf = $ref->getMethod('montarPdf'); $mPdf->setAccessible(true);
$mSan = $ref->getMethod('sanitizarNomeAjudante'); $mSan->setAccessible(true);
$cfg = ['largura_mm' => 80.0, 'comprimento_mm' => 80.0, 'orientacao' => 'portrait', 'corte_apos_impressao' => true];

$motLongo = 'MARIA APARECIDA FERNANDES DE OLIVEIRA CAVALCANTI DOS SANTOS PEREIRA LIMA';
$cenarios = [
    'a_sem_ajudante' => ['JOAO DA SILVA SANTOS', null],
    'b_curto' => ['JOAO DA SILVA SANTOS', 'Pedro Alves'],
    'c_60+' => ['JOAO DA SILVA SANTOS', 'Antonio Carlos Ferreira de Albuquerque Junior Neto Filho Segundo'],
    'd_motorista_e_ajudante_longos' => [$motLongo, 'Francisco Jose Alexandre de Albuquerque Cavalcanti Montenegro Pereira Filho Neto'],
    'e_acento_emoji' => ['JOAO DA SILVA SANTOS', "Jos\u{E9} Ant\u{F4}nio \u{C7}\u{E3}o \u{1F600} M\u{FC}ller\x07"],
    'f_extremo_150' => [$motLongo, str_repeat('Wwwwwwwwwww ', 13)],
    'g_vazio_espacos' => ['JOAO DA SILVA SANTOS', "  \t\n  "],
];

$medidas = [];
foreach ($cenarios as $nome => [$mot, $aj]) {
    $ajS = $aj === null ? '' : $mSan->invoke($ctl, $aj);
    $pdf = $mPdf->invoke($ctl, $cfg, $mot, '123456', $ajS, 'motorista');
    $r = lerPdf($pdf);
    afirmar("$nome: 1 pagina", $r['paginas'] === 1);
    afirmar("$nome: MediaBox 80x80 mm", $r['mediabox'] === [80.0, 80.0]);
    afirmar("$nome: linhas lidas", count($r['linhas']) >= 4);
    $xMin = 99; $yMax = 0; $ptMinAj = 99;
    foreach ($r['linhas'] as $l) {
        $xMin = min($xMin, $l['x']); $yMax = max($yMax, $l['y']);
    }
    // largura real de cada linha (mesma fonte core) para validar x_fim <= 49
    $f = new FPDF('P', 'mm', [80, 80]); $xMax = 0;
    foreach ($r['linhas'] as $l) {
        $est = ($l['txt'] === '123456' || $l['txt'] === 'UDLOG') ? 'B' : '';
        $f->SetFont('Arial', $est, $l['pt']);
        $xMax = max($xMax, $l['x'] + $f->GetStringWidth($l['txt']));
    }
    afirmar("$nome: x_min >= 1 mm e x_max <= 49 mm (min " . round($xMin, 2) . ', max ' . round($xMax, 2) . ')', $xMin >= 1.0 - 0.01 && $xMax <= 49.0 + 0.01);
    afirmar("$nome: base <= 77 mm (" . round($yMax, 2) . ')', $yMax <= 77.0);
    // Etiqueta do MOTORISTA nunca contem "Ajudante" nem o nome do ajudante
    $txtTodo = implode("\n", array_column($r['linhas'], 'txt'));
    afirmar("$nome: motorista sem 'Ajudante' e sem nome do ajudante", stripos($txtTodo, 'Ajudante') === false && ($ajS === '' || !str_contains($txtTodo, $ajS)));
    // Mesmas medidas (fontes, posicoes) que o mesmo motorista sem ajudante
    $base = lerPdf($mPdf->invoke($ctl, $cfg, $mot, '123456', '', 'motorista'));
    $sig = fn(array $rr) => array_map(fn($l) => [$l['pt'], round($l['x'], 2), round($l['y'], 2)], $rr['linhas']);
    afirmar("$nome: medidas identicas as do motorista sem ajudante", $sig($r) === $sig($base));
    // Mesmo com destinatario omitido (padrao motorista)
    $rPadrao = lerPdf($mPdf->invoke($ctl, $cfg, $mot, '123456', $ajS));
    afirmar("$nome: destinatario padrao = motorista (sem ajudante no PDF)", stripos(implode("\n", array_column($rPadrao['linhas'], 'txt')), 'Ajudante') === false);
    $medidas[$nome] = $r;
    echo '       fontes: ' . implode('/', array_map(fn($l) => $l['pt'], $r['linhas'])) . " | base final {$yMax} mm\n";

    // Etiqueta do AJUDANTE continua existindo (quando ha nome)
    if ($ajS !== '') {
        $ra = lerPdf($mPdf->invoke($ctl, $cfg, $mot, '123456', $ajS, 'ajudante'));
        $txA = implode("\n", array_column($ra['linhas'], 'txt'));
        $xMaxA = 0; $yMaxA = 0; $xMinA = 99;
        foreach ($ra['linhas'] as $l) {
            $f->SetFont('Arial', ($l['txt'] === '123456' || $l['txt'] === 'AJUDANTE') ? 'B' : '', $l['pt']);
            $xMaxA = max($xMaxA, $l['x'] + $f->GetStringWidth($l['txt'])); $xMinA = min($xMinA, $l['x']); $yMaxA = max($yMaxA, $l['y']);
        }
        afirmar("$nome: etiqueta ajudante: 1 pagina 80x80, AJUDANTE, 123456, sem UDLOG, dentro de 1..49 mm e base <= 77 mm",
            $ra['paginas'] === 1 && $ra['mediabox'] === [80.0, 80.0] && str_contains($txA, 'AJUDANTE') && str_contains($txA, '123456')
            && !str_contains($txA, 'UDLOG') && $xMinA >= 0.99 && $xMaxA <= 49.01 && $yMaxA <= 77.0);
    }
}

// Layout do motorista: UDLOG 22, motorista 15 (2 linhas), numero 40, data 9
$sem = array_column($medidas['a_sem_ajudante']['linhas'], 'pt');
afirmar('motorista sem ajudante: fontes 22/15/15/40/9', $sem === [22.0, 15.0, 15.0, 40.0, 9.0]);
$com = array_column($medidas['b_curto']['linhas'], 'pt');
afirmar('motorista com ajudante: fontes 22/15/15/40/9 (iguais)', $com === [22.0, 15.0, 15.0, 40.0, 9.0]);

// Sanitizacao
afirmar('sanitizar: vazio/espacos => ""', $mSan->invoke($ctl, "  \t ") === '' && $mSan->invoke($ctl, null) === '');
afirmar('sanitizar: controles removidos', $mSan->invoke($ctl, "Ana\x00\x07\nSilva") === 'Ana Silva');
afirmar('sanitizar: maximo 150 caracteres', mb_strlen($mSan->invoke($ctl, str_repeat("\u{E9}", 400)), 'UTF-8') === 150);
afirmar('sanitizar: UTF-8 invalido nao lanca excecao', is_string($mSan->invoke($ctl, "Jo\xE3o \xFF\xFE")));

echo "\n=== RESULTADO: {$total} testes, " . ($total - $falhas) . " passaram, {$falhas} falharam ===\n";
exit($falhas > 0 ? 1 : 0);
