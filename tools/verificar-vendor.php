<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$raiz = dirname(__DIR__) . '/public/totem/assets/vendor';
$manifesto = $raiz . '/MANIFEST.sha256';

if (!is_file($manifesto)) {
    fwrite(STDERR, "Manifesto ausente: MANIFEST.sha256\n");
    exit(1);
}

$esperados = [];
foreach (file($manifesto, FILE_IGNORE_NEW_LINES) as $n => $linha) {
    if (trim($linha) === '') {
        continue;
    }
    if (!preg_match('/^([0-9a-f]{64}) [ *](\S.*)$/', $linha, $m)) {
        fwrite(STDERR, 'Linha invalida no manifesto: ' . ($n + 1) . "\n");
        exit(1);
    }
    if (strpos($m[2], '..') !== false || $m[2][0] === '/') {
        fwrite(STDERR, 'Caminho invalido no manifesto: linha ' . ($n + 1) . "\n");
        exit(1);
    }
    $esperados[$m[2]] = $m[1];
}

if ($esperados === []) {
    fwrite(STDERR, "Manifesto vazio\n");
    exit(1);
}

$falhas = 0;
foreach ($esperados as $caminho => $hashEsperado) {
    $completo = $raiz . '/' . $caminho;
    if (!is_file($completo)) {
        echo $caminho . ": AUSENTE\n";
        $falhas++;
        continue;
    }
    $hashAtual = hash_file('sha256', $completo);
    if (!hash_equals($hashEsperado, $hashAtual)) {
        echo $caminho . ": DIVERGENTE\n";
        $falhas++;
        continue;
    }
    echo $caminho . ": OK\n";
}

$iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($iterador as $arquivo) {
    if (!$arquivo->isFile()) {
        continue;
    }
    $relativo = str_replace('\\', '/', substr($arquivo->getPathname(), strlen($raiz) + 1));
    if ($relativo !== 'MANIFEST.sha256' && !isset($esperados[$relativo])) {
        echo $relativo . ": FORA DO MANIFESTO\n";
        $falhas++;
    }
}

echo $falhas === 0 ? "Vendor integro.\n" : "Falhas: $falhas\n";
exit($falhas === 0 ? 0 : 1);
