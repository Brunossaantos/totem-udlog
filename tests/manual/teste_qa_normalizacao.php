<?php

/**
 * QA adversarial da normalizacao sem acentos (RazaoSocialMatcher::normalizar) e da ferramenta
 * tools/recalcular-razao-normalizada.php. Complementa (nao duplica) teste_razao_social_normalizacao.php.
 *   (a) paridade com o algoritmo ANTIGO (copia verbatim do HEAD) em >=10.000 entradas ASCII: 0 divergencias
 *   (b) seed da migration 003: quantos dos 38 divergem da funcao nova (so REPORTA)
 *   (c) colisoes novas (par distinto no antigo, igual no novo) em 50.000 pares sinteticos (so REPORTA)
 *   (d) idempotencia em 20.000 entradas + contra-exemplos (so REPORTA contra-exemplos de borda)
 *   (e) ferramenta CLI em banco QA descartavel: checksum, so divergentes, recusas, lock, CGI
 * Uso: php tests/manual/teste_qa_normalizacao.php   (banco QA qa_qr_exclusivo_<hex>, nunca udlog_totem)
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\ClienteGestaoDao;
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

// ---------------------------------------------------------------- (e)
function qaRodar(string $args = '', bool $cgi = false): array
{
    $raiz = dirname(__DIR__, 2);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_qaz_' . bin2hex(random_bytes(4)) . '.log';
    file_put_contents($log, '');
    $prepend = __DIR__ . '/qa_gestao_prepend.php';
    $script = $raiz . '/tools/recalcular-razao-normalizada.php';
    if ($cgi) {
        $cmd = [gtCgiBinario(), '-q', '-d', 'auto_prepend_file=' . $prepend, '-d', 'error_log="' . $log . '"', $script];
        $env = ['QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'), 'REQUEST_METHOD' => 'GET', 'REDIRECT_STATUS' => '200', 'SystemRoot' => (string) getenv('SystemRoot')];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $raiz, $env);
    } else {
        $cmd = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'display_errors=0', '-d', 'error_log="' . $log . '"', $script], $args === '' ? [] : explode(' ', $args));
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $raiz);
    }
    $out = (string) stream_get_contents($pp[1]);
    $err = (string) stream_get_contents($pp[2]);
    fclose($pp[1]);
    fclose($pp[2]);
    $c = proc_close($proc);
    @unlink($log);

    return ['c' => $c, 'out' => $out, 'err' => $err];
}

function qaChecksum(PDO $pdo): string
{
    return md5(json_encode($pdo->query('SELECT id_cliente, nome, razao_social_normalizada, cnpj, ativo FROM tb_cliente ORDER BY id_cliente')->fetchAll(PDO::FETCH_ASSOC)));
}

$banco = null;
$storage = null;
try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    echo "    banco QA: {$banco}\n";
    afirmar('(e) banco e QA descartavel', preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $banco) === 1);
    $ins = $pdo->prepare('INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:n, :r, :c, :a)');
    $semear = static function (array $linhas) use ($pdo, $ins): void {
        $pdo->exec('DELETE FROM tb_cliente');
        foreach ($linhas as $i => [$nome, $razao, $ativo]) {
            $ins->execute(['n' => $nome, 'r' => $razao, 'c' => sprintf('%014d', 91000000000000 + $i), 'a' => $ativo]);
        }
    };
    // cenario 1: 2 iguais, 2 divergentes (um NULL), tudo distinto
    $semear([["Café Central Ltda", 'CAF CENTRAL', 1], ['Acme Tintas', 'ACME TINTAS', 1], ['Ação Forte', 'A O FORTE', 1], ['Zeta Sul', 'ZETA SUL', 1]]);
    $pdo->exec("UPDATE tb_cliente SET razao_social_normalizada = NULL WHERE nome = 'Zeta Sul'");
    $pdo->exec("UPDATE tb_cliente SET razao_social_normalizada = 'ZETA SUL' WHERE nome = 'Zeta Sul'");
    $pdo->exec("UPDATE tb_cliente SET razao_social_normalizada = 'CAF CENTRAL' WHERE nome LIKE 'Caf%'");
    $antes = qaChecksum($pdo);
    $dry = qaRodar();
    afirmar('(e) dry-run: exit 0, checksum da tabela inalterado, diz DRY-RUN e 2 diferentes', $dry['c'] === 0 && qaChecksum($pdo) === $antes && str_contains($dry['out'], 'DRY-RUN') && str_contains($dry['out'], 'diferentes: 2'));
    $dry2 = qaRodar('--dry-run');
    afirmar('(e) --dry-run explicito: idem, sem gravar', $dry2['c'] === 0 && qaChecksum($pdo) === $antes);
    $nomes0 = $pdo->query('SELECT nome FROM tb_cliente ORDER BY id_cliente')->fetchAll(PDO::FETCH_COLUMN);
    $ap = qaRodar('--aplicar');
    $linhas = $pdo->query('SELECT nome, razao_social_normalizada r FROM tb_cliente ORDER BY id_cliente')->fetchAll(PDO::FETCH_KEY_PAIR);
    afirmar('(e) --aplicar: exit 0, so os 2 divergentes mudam (CAFE CENTRAL, ACAO FORTE), iguais intactos, nome nunca tocado', $ap['c'] === 0 && $linhas['Café Central Ltda'] === 'CAFE CENTRAL' && $linhas['Ação Forte'] === 'ACAO FORTE' && $linhas['Acme Tintas'] === 'ACME TINTAS' && $linhas['Zeta Sul'] === 'ZETA SUL' && $pdo->query('SELECT nome FROM tb_cliente ORDER BY id_cliente')->fetchAll(PDO::FETCH_COLUMN) === $nomes0 && str_contains($ap['out'], 'APLICADO: 2'));
    $depois = qaChecksum($pdo);
    $ap2 = qaRodar('--aplicar');
    afirmar('(e) 2a execucao --aplicar: idempotente (0 atualizadas, checksum igual)', $ap2['c'] === 0 && qaChecksum($pdo) === $depois && str_contains($ap2['out'], 'APLICADO: 0'));
    $ap3 = qaRodar('--aplicar --dry-run');
    $ap4 = qaRodar('--xyz');
    afirmar('(e) argumentos invalidos/excludentes: exit 2', $ap3['c'] === 2 && $ap4['c'] === 2);

    // duplicidade entre ativos
    $semear([['Café Sul', 'CAF SUL', 1], ['Cafe Sul', 'CAFE SUL', 1], ['Outro', 'OUTRO', 1]]);
    $antes = qaChecksum($pdo);
    $r = qaRodar('--aplicar');
    afirmar('(e) duplicidade entre ativos: --aplicar recusa (exit 1), checksum inalterado', $r['c'] === 1 && qaChecksum($pdo) === $antes && str_contains($r['out'], 'RECUSADO'));
    // duplicidade com um inativo: permitido
    $pdo->exec("UPDATE tb_cliente SET ativo = 0 WHERE nome = 'Cafe Sul'");
    $antes = qaChecksum($pdo);
    $r = qaRodar('--aplicar');
    afirmar('(e) duplicidade com 1 inativo: aplica (exit 0)', $r['c'] === 0 && qaChecksum($pdo) !== $antes);
    // vazio
    $semear([['Ввод', 'X', 1], ['Normal', 'NORMAL', 1]]);
    $antes = qaChecksum($pdo);
    $r = qaRodar('--aplicar');
    afirmar('(e) nome que normaliza para vazio (cirilico): recusa (exit 1), checksum inalterado', $r['c'] === 1 && qaChecksum($pdo) === $antes);
    // acima da coluna
    $semear([[str_repeat('ß', 80), 'X', 1]]);
    $antes = qaChecksum($pdo);
    $r = qaRodar('--aplicar');
    afirmar('(e) valor acima da coluna (150; 80 x ß => 160): recusa (exit 1), checksum inalterado', $r['c'] === 1 && qaChecksum($pdo) === $antes);
    // lock
    $semear([['Café Lock', 'CAF LOCK', 1]]);
    $antes = qaChecksum($pdo);
    $pdo2 = qaQrAbrirBanco($banco);
    $seguro = (new ClienteGestaoDao($pdo2))->obterLock(0);
    $r = qaRodar('--aplicar');
    $dryLock = qaRodar();
    (new ClienteGestaoDao($pdo2))->liberarLock();
    afirmar('(e) lock segurado por outra conexao: --aplicar recusa (exit 1) sem gravar; dry-run nao depende do lock (exit 0)', $seguro && $r['c'] === 1 && qaChecksum($pdo) === $antes && $dryLock['c'] === 0);
    // CGI
    $cgi = qaRodar('', true);
    $semSaida = !str_contains($cgi['out'], 'modo:') && !str_contains($cgi['out'], 'total:') && qaChecksum($pdo) === $antes;
    afirmar('(e) via CGI: 404/exit 1, sem imprimir contagens e sem gravar (status=' . json_encode(strtok($cgi['out'], "\n")) . ' exit=' . $cgi['c'] . ')', $semSaida && ($cgi['c'] === 1 || str_contains($cgi['out'], '404')));
} finally {
    gtDestruirAmbiente($banco, $storage);
}

exit(gtResumo('teste_qa_normalizacao'));
