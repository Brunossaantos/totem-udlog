<?php

/**
 * Suite F2 + F3 (rodada corretiva /01, 2026-10-01) da demanda
 * hardening-revisao-notas-e-cliente.
 *
 * F3: todas as regexes de Util\NotaArquivoStorage ancoradas ao fim real
 * (modificador D): rejeitam newline, CR, NUL, separador, traversal, extensao
 * adicional e sufixo invisivel (pasta, arquivo, diretorios da varredura e nome
 * da quarentena).
 *
 * F2: cron/limpar-notas-quarentena.php remove NO MAXIMO 500 arquivos por
 * execucao mesmo com mais elegiveis (backlog > 5000), sem scandir (readdir uma
 * entrada por vez), com falha de leitura de diretorio = log sanitizado, saida
 * != 0 e nenhuma falsa indicacao de sucesso; CLI-only; log so agregado.
 *
 * Sem banco. STORAGE_PATH temporario proprio (nunca o do .env).
 *
 * Uso: php tests/manual/teste_hardening_r4_storage_cron.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/hardening_helpers.php';

use Util\NotaArquivoStorage;

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totem_r4sc_' . bin2hex(random_bytes(6));
mkdir($raiz, 0777, true);
$tmp = $raiz . '_tmp';
mkdir($tmp, 0777, true);
$acls = [];

function r4scMontar(string $raiz, string $relativo, string $arquivo, int $idadeSegundos): string
{
    $dir = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $caminho = $dir . DIRECTORY_SEPARATOR . $arquivo;
    file_put_contents($caminho, 'x');
    touch($caminho, time() - $idadeSegundos);

    return $caminho;
}

/** Roda o cron CLI de verdade. @return array{exit:int, log:string, stdout:string} */
function r4scCron(string $storagePath, string $tmp, array $envExtra = []): array
{
    $arquivoLog = $tmp . DIRECTORY_SEPARATOR . 'cron_' . bin2hex(random_bytes(4)) . '.log';
    file_put_contents($arquivoLog, '');
    $env = array_merge(getenv(), ['STORAGE_PATH' => $storagePath], $envExtra);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log="' . $arquivoLog . '"', hdRaizProjeto() . '/cron/limpar-notas-quarentena.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        hdRaizProjeto(),
        $env
    );
    $stdout = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $codigo = proc_close($proc);
    $log = (string) file_get_contents($arquivoLog);
    @unlink($arquivoLog);

    return ['exit' => $codigo, 'log' => $log, 'stdout' => $stdout];
}

function r4scContar(string $dir, string $padrao = '/\.del$/'): int
{
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && preg_match($padrao, $f->getFilename()) === 1) {
            $n++;
        }
    }

    return $n;
}

try {
    // ===============================================================
    hdSecao('F3 regexes ancoradas ao fim real (modificador D)');
    $ref = new ReflectionClass(NotaArquivoStorage::class);
    $constantes = $ref->getConstants();
    $regexes = array_filter($constantes, static fn($k) => str_starts_with($k, 'REGEX_'), ARRAY_FILTER_USE_KEY);
    hdAfirmar('F3: existem as 5 regexes da classe', count($regexes) === 5);
    foreach ($regexes as $nome => $regex) {
        hdAfirmar("F3: {$nome} termina com o modificador D (ancora no fim real)", preg_match('/#[a-z]*D[a-z]*$/', $regex) === 1);
    }

    $arquivosRuins = [
        "nota_01.jpg\n", "nota_01.jpg\r", "nota_01.jpg\r\n", "nota_01.jpg\0", "nota_01.jpg\n\n",
        "nota_01.jpg.", "nota_01.jpg.jpg", "nota_01.jpg.bak", "nota_01.jpg/", "nota_01.jpg\\",
        "nota_01.jpg ", " nota_01.jpg", "../nota_01.jpg", "x/nota_01.jpg", "nota_01.jpg/../x",
        "nota_01.jpg\xe2\x80\x8b", "\xe2\x80\x8bnota_01.jpg", "nota_01.jpg\xc2\xa0", "nota_06.jpg", "nota_00.jpg",
        "NOTA_01.JPG", "nota_1.jpg", "nota_011.jpg", "nota_01.jpeg", "nota_01\0.jpg", "",
    ];
    $ruins = 0;
    foreach ($arquivosRuins as $a) {
        if (NotaArquivoStorage::arquivoValido($a)) {
            $ruins++;
            echo '  aceitou indevidamente: ' . json_encode($a) . "\n";
        }
    }
    hdAfirmar('F3: arquivoValido rejeita newline, CR, NUL, separador, traversal, extensao extra e sufixo invisivel (' . count($arquivosRuins) . ' casos)', $ruins === 0);
    foreach (['nota_01.jpg', 'nota_05.jpg'] as $ok) {
        hdAfirmar("F3: arquivoValido aceita {$ok}", NotaArquivoStorage::arquivoValido($ok));
    }
    hdAfirmar('F3: arquivoValido rejeita tipos nao string', !NotaArquivoStorage::arquivoValido(null) && !NotaArquivoStorage::arquivoValido(1) && !NotaArquivoStorage::arquivoValido(['nota_01.jpg']));

    $pastasRuins = [
        "2026-09-01/ABC1D23_101010\n", "2026-09-01/ABC1D23_101010\r", "2026-09-01/ABC1D23_101010\0", "2026-09-01/ABC1D23_101010/",
        "2026-09-01/ABC1D23_101010 ", "2026-09-01/ABC1D23_101010\xe2\x80\x8b", "2026-09-01/ABC1D23_101010.x", "2026-09-01/ABC1D23_101010/../x",
        "../2026-09-01/ABC1D23_101010", "/2026-09-01/ABC1D23_101010", "2026-09-01\\ABC1D23_101010", "2026-9-01/ABC1D23_101010",
        "2026-09-01/abc1d23_101010", "2026-09-01/ABC1D23_10101", "2026-09-01/ABC1D23_1010101", "2026-09-01/ABCDEFGHIJK_101010",
        "2026-09-01/ABC1D23_101010\n2026-09-02/ABC1D23_101010", "", "2026-09-01",
    ];
    $ruins = 0;
    foreach ($pastasRuins as $p) {
        if (NotaArquivoStorage::pastaValida($p)) {
            $ruins++;
            echo '  aceitou indevidamente: ' . json_encode($p) . "\n";
        }
    }
    hdAfirmar('F3: pastaValida rejeita newline, CR, NUL, separador, traversal, extensao e sufixo invisivel (' . count($pastasRuins) . ' casos)', $ruins === 0);
    foreach (['2026-09-01/ABC1D23_101010', '2026-09-01/_101010', '2026-09-30/A_000000'] as $ok) {
        hdAfirmar('F3: pastaValida aceita ' . $ok, NotaArquivoStorage::pastaValida($ok));
    }

    // regexes de varredura (privadas) exercitadas direto: o sistema de arquivos
    // do Windows nao cria nomes com newline, entao a prova e na propria regex
    $quarentena = $constantes['REGEX_QUARENTENA'];
    $dirData = $constantes['REGEX_DIR_DATA'];
    $dirAtend = $constantes['REGEX_DIR_ATENDIMENTO'];
    $ruinsQ = ["nota_01.jpg.5.del\n", "nota_01.jpg.5.del\r", "nota_01.jpg.5.del\0", "nota_01.jpg.5.del.bak", "nota_01.jpg.5.del/", "nota_01.jpg.5.del\xe2\x80\x8b", "nota_01.jpg.5.del ", "nota_01.jpg.5.del.del", "nota_01.jpg.del", "nota_01.jpg.x.del", "nota_01.jpg.123456789012345678901.del", "../nota_01.jpg.5.del", "nota_01.jpg.5.del\nnota_02.jpg.6.del"];
    $ruins = 0;
    foreach ($ruinsQ as $n) {
        $ruins += preg_match($quarentena, $n) === 1 ? 1 : 0;
    }
    hdAfirmar('F3: REGEX_QUARENTENA rejeita newline, CR, NUL, extensao adicional e sufixo invisivel (' . count($ruinsQ) . ' casos)', $ruins === 0);
    hdAfirmar('F3: REGEX_QUARENTENA aceita nota_01.jpg.5.del e nota_05.jpg.99999999999999999999.del', preg_match($quarentena, 'nota_01.jpg.5.del') === 1 && preg_match($quarentena, 'nota_05.jpg.99999999999999999999.del') === 1);
    hdAfirmar('F3: REGEX_DIR_DATA rejeita newline/CR/NUL/sufixo e aceita AAAA-MM-DD', preg_match($dirData, "2026-09-01\n") === 0 && preg_match($dirData, "2026-09-01\r") === 0 && preg_match($dirData, "2026-09-01\0") === 0 && preg_match($dirData, "2026-09-01\xe2\x80\x8b") === 0 && preg_match($dirData, '2026-09-01') === 1);
    hdAfirmar('F3: REGEX_DIR_ATENDIMENTO rejeita newline/CR/NUL/sufixo e aceita PLACA_HHMMSS', preg_match($dirAtend, "ABC1D23_101010\n") === 0 && preg_match($dirAtend, "ABC1D23_101010\r") === 0 && preg_match($dirAtend, "ABC1D23_101010\0") === 0 && preg_match($dirAtend, "ABC1D23_101010\xe2\x80\x8b") === 0 && preg_match($dirAtend, 'ABC1D23_101010') === 1);

    // a regex NAO ancorada (controle): prova que o D faz diferenca de verdade
    hdAfirmar('F3: controle: sem o D o "$" aceitaria o newline final (a prova do defeito)', preg_match('#^nota_0[1-5]\.jpg$#', "nota_01.jpg\n") === 1 && preg_match('#^nota_0[1-5]\.jpg$#D', "nota_01.jpg\n") === 0);

    // caminhoDoArquivo com entradas ruins (mesmo com a pasta existente)
    $dirReal = $raiz . DIRECTORY_SEPARATOR . '2026-09-01' . DIRECTORY_SEPARATOR . 'ABC1D23_101010';
    mkdir($dirReal, 0777, true);
    $st = new NotaArquivoStorage($raiz);
    hdAfirmar('F3: caminhoDoArquivo valido devolve o caminho', $st->caminhoDoArquivo('2026-09-01/ABC1D23_101010', 'nota_01.jpg') !== null);
    hdAfirmar('F3: caminhoDoArquivo rejeita arquivo com newline final (nenhum acesso a disco)', $st->caminhoDoArquivo('2026-09-01/ABC1D23_101010', "nota_01.jpg\n") === null);
    hdAfirmar('F3: caminhoDoArquivo rejeita pasta com newline final', $st->caminhoDoArquivo("2026-09-01/ABC1D23_101010\n", 'nota_01.jpg') === null);
    hdAfirmar('F3: caminhoDoArquivo rejeita traversal na pasta e no arquivo', $st->caminhoDoArquivo('2026-09-01/../ABC1D23_101010', 'nota_01.jpg') === null && $st->caminhoDoArquivo('2026-09-01/ABC1D23_101010', '../nota_01.jpg') === null);

    // ===============================================================
    hdSecao('F2 limite de 500 por execucao: backlog > 5000 (cron CLI real)');
    $total = 5300;
    $dirBacklog = $raiz . DIRECTORY_SEPARATOR . '2026-08-01' . DIRECTORY_SEPARATOR . 'BKL1A23_090909';
    mkdir($dirBacklog, 0777, true);
    $velho = time() - 3 * 86400;
    for ($i = 1; $i <= $total; $i++) {
        $f = $dirBacklog . DIRECTORY_SEPARATOR . 'nota_0' . (($i % 5) + 1) . '.jpg.' . $i . '.del';
        file_put_contents($f, 'x');
        touch($f, $velho);
    }
    // arquivos que NUNCA podem ser tocados no meio do backlog
    $guarda = [
        r4scMontar($raiz, '2026-08-01/BKL1A23_090909', 'nota_01.jpg', 9 * 86400),
        r4scMontar($raiz, '2026-08-01/BKL1A23_090909', 'cnh_frente.jpg', 9 * 86400),
        r4scMontar($raiz, '2026-08-01/BKL1A23_090909', 'nota_02.jpg.999999.del', 3600),
    ];
    $antes = r4scContar($raiz);
    hdAfirmar("F2: backlog montado ({$total} .del expirados + 1 .del novo)", $antes === $total + 1);

    $r = r4scCron($raiz, $tmp);
    $restante = r4scContar($raiz);
    hdAfirmar('F2: 1a execucao remove EXATAMENTE 500 (mesmo com 5300 elegiveis)', $antes - $restante === 500);
    hdAfirmar('F2: saida 0 (limite atingido nao e falha)', $r['exit'] === 0);
    hdAfirmar('F2: log agregado informa 500 apagados e limite ATINGIDO', str_contains($r['log'], '500 arquivo(s) .del apagado(s)') && str_contains($r['log'], 'ATINGIDO'));
    hdAfirmar('F2: arquivos que nao sao .del expirado intactos (nota_01.jpg, cnh_frente.jpg, .del novo)', file_exists($guarda[0]) && file_exists($guarda[1]) && file_exists($guarda[2]));

    $execucoes = 1;
    $removidosPorExecucao = [500];
    while (r4scContar($raiz) > 1 && $execucoes < 20) {
        $antesN = r4scContar($raiz);
        $rn = r4scCron($raiz, $tmp);
        $execucoes++;
        $removidosPorExecucao[] = $antesN - r4scContar($raiz);
        if ($rn['exit'] !== 0) {
            break;
        }
    }
    hdAfirmar('F2: nenhuma execucao removeu mais de 500', max($removidosPorExecucao) === 500);
    hdAfirmar('F2: 5300 elegiveis => 11 execucoes (10 x 500 + 1 x 300), sobra so o .del novo', $execucoes === 11 && array_sum($removidosPorExecucao) === $total && r4scContar($raiz) === 1 && end($removidosPorExecucao) === 300);
    hdAfirmar('F2: ultima execucao (300) nao atinge o limite', !str_contains($rn['log'] ?? '', 'ATINGIDO') && str_contains($rn['log'] ?? '', 'nao atingido'));
    hdAfirmar('F2: .del novo e arquivos comuns sobreviveram a todas as execucoes', file_exists($guarda[0]) && file_exists($guarda[1]) && file_exists($guarda[2]));

    // limite atravessando pastas: limite pequeno no metodo (3 pastas x 4 arquivos, limite 5)
    $raiz2 = $raiz . '_multi';
    mkdir($raiz2, 0777, true);
    foreach (['2026-07-01/AAA1A11_010101', '2026-07-02/BBB1B22_020202', '2026-07-03/CCC1C33_030303'] as $p) {
        for ($i = 1; $i <= 4; $i++) {
            r4scMontar($raiz2, $p, "nota_0{$i}.jpg.{$i}.del", 5 * 86400);
        }
    }
    $st2 = new NotaArquivoStorage($raiz2);
    $res = $st2->limparQuarentenaExpirada(time(), 86400, 5);
    hdAfirmar('F2: limite 5 atravessando pastas remove exatamente 5 e para (limite_atingido)', $res['removidos'] === 5 && $res['limite_atingido'] === true && r4scContar($raiz2) === 7);
    $res = $st2->limparQuarentenaExpirada(time(), 86400, 500);
    hdAfirmar('F2: execucao seguinte remove o restante (7) sem atingir o limite', $res['removidos'] === 7 && $res['limite_atingido'] === false && r4scContar($raiz2) === 0);
    hdAfirmar('F2: limite padrao da classe = 500', NotaArquivoStorage::LIMITE_REMOCOES_POR_EXECUCAO === 500);

    // ---------------------------------------------------------------
    hdSecao('F2 sem scandir completo: varredura por readdir');
    $fonte = (string) file_get_contents(__DIR__ . '/../../util/NotaArquivoStorage.php');
    $codigoSemComentarios = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $fonte);
    hdAfirmar('F2: a classe nao usa scandir, glob nem iterador que carregue a lista inteira', !preg_match('/\bscandir\s*\(|\bglob\s*\(|iterator_to_array|array_filter\s*\(\s*@?scandir/', $codigoSemComentarios));
    hdAfirmar('F2: a varredura usa opendir/readdir (via abrirDiretorio) e closedir', substr_count($codigoSemComentarios, 'readdir(') >= 3 && str_contains($codigoSemComentarios, 'closedir('));
    // memoria: o backlog de 5300 nomes nao cresce a memoria do processo da varredura
    $rt = $raiz . '_mem';
    mkdir($rt . DIRECTORY_SEPARATOR . '2026-06-01' . DIRECTORY_SEPARATOR . 'MEM1A23_060606', 0777, true);
    $dirMem = $rt . DIRECTORY_SEPARATOR . '2026-06-01' . DIRECTORY_SEPARATOR . 'MEM1A23_060606';
    for ($i = 1; $i <= 5200; $i++) {
        $f = $dirMem . DIRECTORY_SEPARATOR . 'nota_01.jpg.' . $i . '.del';
        file_put_contents($f, 'x');
        touch($f, time() - 3 * 86400);
    }
    gc_collect_cycles();
    $memAntes = memory_get_usage();
    $stm = new NotaArquivoStorage($rt);
    $stm->limparQuarentenaExpirada(time());
    $memDepois = memory_get_peak_usage();
    $cresceu = $memDepois - $memAntes;
    echo '  (pico de memoria acima da linha de base: ' . round($cresceu / 1024) . " KB com 5200 .del no diretorio)\n";
    hdAfirmar('F2: backlog de 5200 arquivos nao carrega a lista em memoria (crescimento < 256 KB)', $cresceu < 256 * 1024);

    // ---------------------------------------------------------------
    hdSecao('F2 falha de leitura = log sanitizado, saida != 0, sem falso sucesso');
    // (a) STORAGE_PATH aponta para um ARQUIVO (opendir falha de verdade)
    $arquivoComoRaiz = $tmp . DIRECTORY_SEPARATOR . 'nao_e_diretorio.txt';
    file_put_contents($arquivoComoRaiz, 'x');
    $r = r4scCron($arquivoComoRaiz, $tmp);
    hdAfirmar('F2a: raiz que nao abre (arquivo no lugar do diretorio) = saida 1', $r['exit'] === 1);
    hdAfirmar('F2a: log sanitizado de falha e NENHUMA mensagem de sucesso (sem "apagado(s)")', str_contains($r['log'], 'nenhuma exclusao iniciada') && !str_contains($r['log'], 'apagado(s)'));
    hdAfirmar('F2a: log sem caminho', !str_contains($r['log'], $arquivoComoRaiz) && !str_contains($r['log'], sys_get_temp_dir()));
    $r = r4scCron($tmp . DIRECTORY_SEPARATOR . 'nao_existe_xyz', $tmp);
    hdAfirmar('F2a: raiz inexistente = saida 1 sem sucesso falso', $r['exit'] === 1 && !str_contains($r['log'], 'apagado(s)'));

    // (b) falha de opendir em subdiretorio (seam abrirDiretorio): erros_leitura e nao silencio
    $raiz3 = $raiz . '_falha';
    mkdir($raiz3, 0777, true);
    $legivel = r4scMontar($raiz3, '2026-05-01/OKK1A23_050505', 'nota_01.jpg.1.del', 5 * 86400);
    $ilegivel = r4scMontar($raiz3, '2026-05-02/BAD1A23_060606', 'nota_01.jpg.2.del', 5 * 86400);
    $stFalha = new class($raiz3) extends NotaArquivoStorage {
        public int $aberturas = 0;
        protected function abrirDiretorio(string $caminho)
        {
            $this->aberturas++;
            if (str_contains($caminho, 'BAD1A23_060606')) {
                return false; // simula opendir falhando de verdade nesse diretorio
            }

            return parent::abrirDiretorio($caminho);
        }
    };
    $res = $stFalha->limparQuarentenaExpirada(time());
    hdAfirmar('F2b: opendir que falha vira erros_leitura = 1 (nunca silencio)', $res['erros_leitura'] === 1);
    hdAfirmar('F2b: o diretorio legivel e processado normalmente e o ilegivel nao e tocado', !file_exists($legivel) && file_exists($ilegivel) && $res['removidos'] === 1);
    $stRaizFalha = new class($raiz3) extends NotaArquivoStorage {
        protected function abrirDiretorio(string $caminho)
        {
            return false;
        }
    };
    $lancou = false;
    try {
        $stRaizFalha->limparQuarentenaExpirada(time());
    } catch (\RuntimeException $e) {
        $lancou = $e->getMessage() === 'raiz_indisponivel';
    }
    hdAfirmar('F2b: opendir da raiz falhando lanca raiz_indisponivel (o cron sai com 1)', $lancou);

    // (c) falha REAL de permissao no Windows (icacls) ou POSIX (chmod), quando o ambiente permite
    $raiz4 = $raiz . '_perm';
    mkdir($raiz4, 0777, true);
    $okPerm = r4scMontar($raiz4, '2026-04-01/OKP1A23_040404', 'nota_01.jpg.1.del', 5 * 86400);
    $negado = r4scMontar($raiz4, '2026-04-02/NEG1A23_050505', 'nota_01.jpg.2.del', 5 * 86400);
    $dirNegado = dirname($negado);
    $negou = false;
    if (DIRECTORY_SEPARATOR === '\\') {
        $usuario = (string) getenv('USERNAME');
        $saida = [];
        $cod = 1;
        @exec('icacls ' . escapeshellarg($dirNegado) . ' /deny ' . escapeshellarg($usuario . ':(RX)') . ' 2>&1', $saida, $cod);
        $negou = $cod === 0;
        if ($negou) {
            $acls[] = $dirNegado;
        }
    } elseif (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        $negou = @chmod($dirNegado, 0000);
        if ($negou) {
            $acls[] = $dirNegado;
        }
    }
    if ($negou) {
        clearstatcache();
        $r = r4scCron($raiz4, $tmp);
        hdAfirmar('F2c: diretorio ilegivel de verdade (permissao negada) = saida 1', $r['exit'] === 1);
        hdAfirmar('F2c: log agregado informa diretorio(s) ilegivel(is) e varredura INCOMPLETA, sem caminho', str_contains($r['log'], '1 diretorio(s) ilegivel(is)') && str_contains($r['log'], 'INCOMPLETA') && !str_contains($r['log'], 'NEG1A23') && !str_contains($r['log'], $raiz4));
        hdAfirmar('F2c: o diretorio legivel ao lado foi processado (apagou o .del expirado)', !file_exists($okPerm));
    } else {
        echo "AVISO - nao foi possivel negar permissao neste ambiente; F2c coberto so por F2a/F2b (seam)\n";
    }

    // ---------------------------------------------------------------
    hdSecao('Cron: CLI-only, retencao de 24 h e logs agregados');
    $fonteCron = (string) file_get_contents(__DIR__ . '/../../cron/limpar-notas-quarentena.php');
    hdAfirmar('Cron: rejeita execucao fora do PHP CLI (PHP_SAPI !== "cli" => 403 e exit 1)', str_contains($fonteCron, "PHP_SAPI !== 'cli'") && str_contains($fonteCron, 'http_response_code(403)'));
    hdAfirmar('Cron: nao ha rota em public/ para o script', !file_exists(__DIR__ . '/../../public/api/limpar-notas-quarentena.php') && count(glob(__DIR__ . '/../../public/**/limpar-notas*')) === 0);
    hdAfirmar('Cron: retencao padrao = 86400 s (24 h)', NotaArquivoStorage::RETENCAO_QUARENTENA_SEGUNDOS === 86400);
    $raiz5 = $raiz . '_ret';
    mkdir($raiz5, 0777, true);
    $novo23 = r4scMontar($raiz5, '2026-03-01/RET1A23_030303', 'nota_01.jpg.1.del', 23 * 3600 + 3000);
    $velho25 = r4scMontar($raiz5, '2026-03-01/RET1A23_030303', 'nota_02.jpg.2.del', 25 * 3600);
    $r = r4scCron($raiz5, $tmp);
    hdAfirmar('Cron: 23 h 50 min fica, 25 h e apagado; saida 0', file_exists($novo23) && !file_exists($velho25) && $r['exit'] === 0);
    hdAfirmar('Cron: log final so agregado (sem caminho, pasta/placa, nome de arquivo ou id)', !preg_match('/RET1A23|nota_0|\.del\b.*\d{4}-\d{2}-\d{2}|2026-03-01/', str_replace('.del apagado(s)', '', $r['log'])) && !str_contains($r['log'], $raiz5));
    $semLog = r4scCron($raiz5, $tmp, ['PATH' => getenv('PATH')]);
    hdAfirmar('Cron: 2a execucao sem elegiveis = saida 0 e 0 apagados', $semLog['exit'] === 0 && str_contains($semLog['log'], '0 arquivo(s) .del apagado(s)'));
} finally {
    // restaura permissoes ANTES de remover (somente o que este teste negou)
    foreach ($acls as $d) {
        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('icacls ' . escapeshellarg($d) . ' /remove:d ' . escapeshellarg((string) getenv('USERNAME')) . ' 2>&1');
        } else {
            @chmod($d, 0755);
        }
    }
    foreach ([$raiz, $raiz . '_tmp', $raiz . '_multi', $raiz . '_mem', $raiz . '_falha', $raiz . '_perm', $raiz . '_ret'] as $d) {
        hdRemoverDiretorio($d);
    }
}

hdEncerrar('teste_hardening_r4_storage_cron');
