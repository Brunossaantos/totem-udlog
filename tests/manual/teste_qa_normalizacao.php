<?php

/**
 * QA adversarial da normalizacao sem acentos (RazaoSocialMatcher::normalizar). Complementa (nao duplica) teste_razao_social_normalizacao.php.
 *   (a) paridade com o algoritmo ANTIGO (copia verbatim do HEAD) em >=10.000 entradas ASCII: 0 divergencias
 *   (b) seed da migration 003: quantos dos 38 divergem da funcao nova (so REPORTA)
 *   (c) colisoes novas (par distinto no antigo, igual no novo) em 50.000 pares sinteticos (so REPORTA)
 *   (d) idempotencia em 20.000 entradas + contra-exemplos (so REPORTA contra-exemplos de borda)
 * Uso: php tests/manual/teste_qa_normalizacao.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use Util\RazaoSocialMatcher;

/** Copia VERBATIM de HEAD:util/RazaoSocialMatcher.php::normalizar (strtoupper + iconv TRANSLIT). */
final class RazaoAntigoQa
{
    private const SUFIXOS_SOCIETARIOS = [
        'LTDA ME', 'LTDA', 'S A', 'S/A', 'SA', 'EIRELI', 'ME', 'EPP', 'MEI', 'CIA',
    ];

    public static function normalizar(string $razaoSocial): string
    {
        $normalizada = strtoupper($razaoSocial);
        $transliterada = @iconv('UTF-8', 'ASCII//TRANSLIT', $normalizada);
        if ($transliterada !== false && $transliterada !== null) {
            $normalizada = $transliterada;
        }

        // remove pontuacao/simbolos, mantem letras/numeros/espacos
        $normalizada = preg_replace('/[^A-Z0-9 ]/', ' ', $normalizada);

        // remove sufixos societarios (como palavras isoladas, em qualquer posicao)
        foreach (self::SUFIXOS_SOCIETARIOS as $sufixo) {
            $normalizada = preg_replace('/\b' . preg_quote($sufixo, '/') . '\b/', ' ', $normalizada);
        }

        // colapsa espacos multiplos
        $normalizada = preg_replace('/\s+/', ' ', $normalizada);

        return trim($normalizada);
    }
}

mt_srand(20261009);
$N = static fn (string $s): string => RazaoSocialMatcher::normalizar($s);
$A = static fn (string $s): string => RazaoAntigoQa::normalizar($s);

// ---------------------------------------------------------------- (a)
$palavras = ['ACME', 'ALFA', 'Beta', 'cafe', 'LTDA', 'ltda', 'S/A', 'S', 'A', 'SA', 'ME', 'EPP', 'MEI', 'CIA', 'EIRELI', 'Quimica', 'Brasil', 'do', 'DA', '&', '-', '.', ',', '(04)', '123', "O'Neil", 'Tintas', 'X'];
$div = 0;
$exemplo = null;
$n = 0;
for ($i = 0; $i < 12000; $i++) {
    if ($i % 3 === 0) {
        $s = '';
        $len = mt_rand(0, 40);
        for ($j = 0; $j < $len; $j++) {
            $s .= chr(mt_rand(0, 127));
        }
    } else {
        $partes = [];
        $k = mt_rand(1, 8);
        for ($j = 0; $j < $k; $j++) {
            $partes[] = $palavras[mt_rand(0, count($palavras) - 1)];
        }
        $s = implode(mt_rand(0, 4) === 0 ? '  ' : ' ', $partes);
        if (mt_rand(0, 5) === 0) {
            $s = strtoupper($s);
        }
    }
    $n++;
    if ($N($s) !== $A($s)) {
        $div++;
        $exemplo ??= $s;
    }
}
afirmar("(a) paridade ASCII com o algoritmo antigo: {$n} entradas, {$div} divergencias" . ($exemplo !== null ? ' ex=' . json_encode($exemplo) : ''), $div === 0 && $n >= 10000);

// ---------------------------------------------------------------- (b)
$sql = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/migrations/003_tb_cliente_razao_normalizada.sql');
$seed = [];
if (preg_match_all("/^\\s*\\('((?:[^'\\\\]|\\\\.|'')*)', '((?:[^'\\\\]|\\\\.|'')*)', '\\d+', [01]\\)[,;]/m", $sql, $m, PREG_SET_ORDER)) {
    foreach ($m as $l) {
        $seed[] = [str_replace(["''", "\\'"], "'", $l[1]), str_replace(["''", "\\'"], "'", $l[2])];
    }
}
$divSeed = [];
$ascii = 0;
$divAscii = 0;
foreach ($seed as [$nome, $esperada]) {
    $isAscii = preg_match('/[^\x00-\x7F]/', $nome) !== 1;
    $ascii += $isAscii ? 1 : 0;
    if ($N($nome) !== $esperada) {
        $divSeed[] = $nome;
        $divAscii += $isAscii ? 1 : 0;
    }
}
echo '(b) seed 003: ' . count($seed) . ' clientes extraidos, ' . $ascii . ' ASCII, ' . (count($seed) - $ascii) . ' acentuados; divergentes da funcao nova: ' . count($divSeed) . ' (ASCII divergentes: ' . $divAscii . ")\n";
foreach ($divSeed as $nome) {
    echo '    diverge: ' . json_encode($nome, JSON_UNESCAPED_UNICODE) . ' novo=' . json_encode($N($nome)) . "\n";
}
afirmar('(b) seed: 38 extraidos e 0 ASCII divergentes', count($seed) === 38 && $divAscii === 0);

// ---------------------------------------------------------------- (c)
$base = ['Café', 'Indústria', 'Química', 'Ação', 'Comércio', 'Distribuidora', 'Pão', 'Açúcar', 'Óleos', 'Têxtil', 'São Paulo', 'Álcool', 'Logística', 'Cerâmica', 'Plásticos', 'Eletrônica', 'Paraná', 'Máquinas', 'Ônix', 'Ótica', 'Acme', 'Beta', 'Gama', 'Brasil', 'Sul', 'Norte', 'Ltda', 'S/A', 'ME', 'Ñandú', 'Müller', 'Straße'];
$sem = static fn (string $s): string => strtr($s, ['Café' => 'Cafe', 'Indústria' => 'Industria', 'Química' => 'Quimica', 'Ação' => 'Acao', 'Comércio' => 'Comercio', 'Pão' => 'Pao', 'Açúcar' => 'Acucar', 'Óleos' => 'Oleos', 'Têxtil' => 'Textil', 'São Paulo' => 'Sao Paulo', 'Álcool' => 'Alcool', 'Logística' => 'Logistica', 'Cerâmica' => 'Ceramica', 'Plásticos' => 'Plasticos', 'Eletrônica' => 'Eletronica', 'Paraná' => 'Parana', 'Máquinas' => 'Maquinas', 'Ônix' => 'Onix', 'Ótica' => 'Otica', 'Ñandú' => 'Nandu', 'Müller' => 'Muller', 'Straße' => 'Strasse']);
$gen = static function () use ($base): string {
    $k = mt_rand(1, 4);
    $p = [];
    for ($j = 0; $j < $k; $j++) {
        $p[] = $base[mt_rand(0, count($base) - 1)];
    }

    return implode(' ', $p);
};
$pares = 50000;
$novas = 0;
$novasMesmaBase = 0;
$novasBaseDistinta = 0;
$iguaisAntes = 0;
$exFalsa = null;
for ($i = 0; $i < $pares; $i++) {
    $a = $gen();
    // 50%: o mesmo nome sem acentos (colisao "intencional"); 50%: outro nome qualquer
    $b = mt_rand(0, 1) ? $sem($a) : $gen();
    if ($A($a) === $A($b)) {
        $iguaisAntes++;
    } elseif ($N($a) === $N($b)) {
        $novas++;
        if ($N($sem($a)) === $N($sem($b)) && $sem($a) === $sem($b)) {
            $novasMesmaBase++;
        } else {
            $novasBaseDistinta++;
            $exFalsa ??= [$a, $b];
        }
    }
}
echo "(c) {$pares} pares sinteticos: ja iguais no antigo={$iguaisAntes}; colisoes NOVAS={$novas} (" . round(100 * $novas / $pares, 2) . "%); destas, mesmo nome so com/sem acento={$novasMesmaBase}, nomes de base diferente={$novasBaseDistinta}" . ($exFalsa ? ' ex=' . json_encode($exFalsa, JSON_UNESCAPED_UNICODE) : '') . "\n";

// ---------------------------------------------------------------- (d)
$toks = ['S', 'A', 'LTDA', 'ME', 'SA', 'S/A', 'EPP', 'MEI', 'CIA', 'EIRELI', 'ACME', 'Café', 'X', 'LTDA ME', 'Ñ', '-'];
$naoId = 0;
$contra = [];
$naoIdNaoAscii = 0;
for ($i = 0; $i < 20000; $i++) {
    $k = mt_rand(1, 6);
    $p = [];
    for ($j = 0; $j < $k; $j++) {
        $p[] = $toks[mt_rand(0, count($toks) - 1)];
    }
    $s = implode(' ', $p);
    $r = $N($s);
    if ($N($r) !== $r) {
        $naoId++;
        if (count($contra) < 5) {
            $contra[] = $s;
        }
        if (preg_match('/[^\x00-\x7F]/', $s) === 1) {
            $naoIdNaoAscii++;
        }
    }
}
echo "(d) idempotencia: 20000 entradas de tokens de borda, nao idempotentes={$naoId} (com nao-ASCII: {$naoIdNaoAscii}); contra-exemplos=" . json_encode($contra, JSON_UNESCAPED_UNICODE) . "\n";
$cx = 'S LTDA A';
echo '    "S LTDA A" => ' . json_encode($N($cx)) . ' => ' . json_encode($N($N($cx))) . ' (antigo: ' . json_encode($A($cx)) . ' => ' . json_encode($A($A($cx))) . ")\n";
// idempotencia em entradas ordinarias (sem tokens de sufixo)
$base2 = array_values(array_diff($base, ['Ltda', 'S/A', 'ME']));
$naoId2 = 0;
for ($i = 0; $i < 20000; $i++) {
    $p = [];
    for ($j = mt_rand(1, 4); $j > 0; $j--) {
        $p[] = $base2[mt_rand(0, count($base2) - 1)];
    }
    $s = implode(mt_rand(0, 1) ? ' ' : ' - ', $p);
    $naoId2 += $N($N($s)) === $N($s) ? 0 : 1;
}
afirmar("(d) idempotencia em 20000 nomes comuns (sem sufixos de borda): {$naoId2} nao idempotentes", $naoId2 === 0);

exit(gtResumo('teste_qa_normalizacao'));
