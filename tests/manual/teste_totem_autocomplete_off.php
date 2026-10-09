<?php

$raiz = dirname(__DIR__, 2);
$arquivos = ['public/totem/assets/app.js', 'public/totem/index.php'];
$tiposIsentos = ['hidden', 'file', 'checkbox', 'radio', 'button', 'submit'];
$allowlist = [];

$total = 0;
$falhas = 0;
function afirmar(string $d, bool $c): void
{
    global $total, $falhas;
    $total++;
    echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n";
    if (!$c) $falhas++;
}

$vistos = [];
foreach ($arquivos as $rel) {
    $src = file_get_contents($raiz . '/' . $rel);
    afirmar("$rel legivel", $src !== false && $src !== '');
    preg_match_all('/<(input|textarea)\b([^>]*)>/i', (string)$src, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as $i => $achado) {
        $attrs = $m[2][$i][0];
        $pos = $achado[1];
        $linha = substr_count(substr($src, 0, $pos), "\n") + 1;
        if (preg_match('/\$\{(\w+)\}/', $attrs) && preg_match('/^\s*\$\{comum\}/', $attrs)) {
            $antes = substr($src, 0, $pos);
            $ini = strrpos($antes, 'const comum = `');
            $fim = $ini === false ? false : strpos($src, '`;', $ini);
            $attrs .= ' ' . ($ini === false ? '' : substr($src, $ini, $fim - $ini));
        }
        $id = preg_match('/\bid="([^"]+)"/', $attrs, $mi) ? $mi[1] : '?';
        $tipo = preg_match('/\btype="([^"]+)"/', $attrs, $mt) ? strtolower($mt[1]) : 'text';
        if (in_array($tipo, $tiposIsentos, true) || in_array($id, $allowlist, true)) continue;
        $vistos[] = $id;
        afirmar("$rel:$linha #$id ($tipo) tem autocomplete=\"off\"", (bool)preg_match('/\bautocomplete="off"/', $attrs));
    }
    preg_match_all('/createElement\(\s*[\'"](input|textarea)[\'"]\s*\)/', (string)$src, $mc, PREG_OFFSET_CAPTURE);
    foreach ($mc[0] as $achado) {
        $linha = substr_count(substr($src, 0, $achado[1]), "\n") + 1;
        $bloco = substr($src, $achado[1], 600);
        afirmar("$rel:$linha createElement com setAttribute('autocomplete','off')", (bool)preg_match('/setAttribute\(\s*[\'"]autocomplete[\'"]\s*,\s*[\'"]off[\'"]\s*\)/', $bloco));
    }
}
afirmar('placa expedicao coberta', in_array('inputPlaca', $vistos, true));
afirmar('placa recebimento coberta', in_array('inputPlacaRec', $vistos, true));

echo "\nTotal: $total, falhas: $falhas\n";
exit($falhas ? 1 : 0);
