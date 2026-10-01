<?php

/**
 * Suite do cron de quarentena e de Util\NotaArquivoStorage (demanda
 * hardening-revisao-notas-e-cliente, 2026-09-30, decisao D3): o cron apaga SO
 * arquivos nota_0[1-5].jpg.<id>.del com mais de 24 horas, em estrutura fixa
 * STORAGE/AAAA-MM-DD/PLACA_HHMMSS/, sem seguir symlink/juncao, sem banco;
 * log agregado sem caminho; somente CLI.
 *
 * Nao usa banco (prova de que o cron tambem nao precisa dele): STORAGE_PATH
 * temporario proprio; o subprocesso do cron recebe DB_HOST invalido de
 * proposito e deve continuar funcionando. NUNCA toca o STORAGE_PATH do .env.
 *
 * Uso: php tests/manual/teste_hardening_cron_quarentena.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/hardening_helpers.php';

use Util\NotaArquivoStorage;

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totem_hdc_' . bin2hex(random_bytes(6));
mkdir($raiz, 0777, true);
$fora = $raiz . '_fora';
mkdir($fora, 0777, true);

function hdcMontar(string $raiz, string $relativo, string $arquivo, int $idadeSegundos, string $conteudo = 'x'): string
{
    $dir = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $caminho = $dir . DIRECTORY_SEPARATOR . $arquivo;
    file_put_contents($caminho, $conteudo);
    touch($caminho, time() - $idadeSegundos);

    return $caminho;
}

try {
    $agora = time();
    $pasta = '2026-09-01/ABC1D23_101010';
    $pasta2 = '2026-09-02/XYZ9K88_202020';

    // ------------------------------------------------------------
    hdSecao('Escopo: so .del com mais de 24 h na estrutura fixa');
    $velho1 = hdcMontar($raiz, $pasta, 'nota_01.jpg.101.del', 25 * 3600);
    $velho2 = hdcMontar($raiz, $pasta, 'nota_05.jpg.9999999.del', 7 * 86400);
    $velho3 = hdcMontar($raiz, $pasta2, 'nota_03.jpg.7.del', 30 * 3600);
    $novo = hdcMontar($raiz, $pasta, 'nota_02.jpg.102.del', 3600);
    $quaseNovo = hdcMontar($raiz, $pasta, 'nota_04.jpg.104.del', 23 * 3600 + 3000);
    $notaAtiva = hdcMontar($raiz, $pasta, 'nota_01.jpg', 10 * 86400);
    $cnh = hdcMontar($raiz, $pasta, 'cnh_frente.jpg', 10 * 86400);
    $crlv = hdcMontar($raiz, $pasta, 'crlv.jpg', 10 * 86400);
    $delCnh = hdcMontar($raiz, $pasta, 'cnh_frente.jpg.5.del', 10 * 86400);
    $delNome1 = hdcMontar($raiz, $pasta, 'nota_06.jpg.1.del', 10 * 86400);
    $delNome2 = hdcMontar($raiz, $pasta, 'nota_01.jpg.del', 10 * 86400);
    $delNome3 = hdcMontar($raiz, $pasta, 'nota_01.jpg.abc.del', 10 * 86400);
    $delNome4 = hdcMontar($raiz, $pasta, 'NOTA_01.JPG.1.del', 10 * 86400);
    $delNome5 = hdcMontar($raiz, $pasta, 'nota_01.jpg.1.del.bak', 10 * 86400);
    $delNome6 = hdcMontar($raiz, $pasta, 'x_nota_01.jpg.1.del', 10 * 86400);
    $delRaiz = hdcMontar($raiz, '', 'nota_01.jpg.1.del', 10 * 86400);
    $delNivel1 = hdcMontar($raiz, '2026-09-01', 'nota_01.jpg.1.del', 10 * 86400);
    $delNivel3 = hdcMontar($raiz, $pasta . '/extra', 'nota_01.jpg.1.del', 10 * 86400);
    $delPastaInvalida = hdcMontar($raiz, '2026-09-01/pasta_lower_1', 'nota_01.jpg.1.del', 10 * 86400);
    $delDataInvalida = hdcMontar($raiz, 'backup/ABC1D23_101010', 'nota_01.jpg.1.del', 10 * 86400);

    $storage = new NotaArquivoStorage($raiz);
    $r = $storage->limparQuarentenaExpirada($agora);

    hdAfirmar('apaga .del com mais de 24 h (25 h, 30 h e 7 dias)', !file_exists($velho1) && !file_exists($velho2) && !file_exists($velho3));
    hdAfirmar('mantem .del novo (1 h) e quase novo (23 h 50 min)', file_exists($novo) && file_exists($quaseNovo));
    hdAfirmar('nunca apaga nota_NN.jpg ativa, CNH nem CRLV (mesmo muito antigos)', file_exists($notaAtiva) && file_exists($cnh) && file_exists($crlv));
    hdAfirmar('nunca apaga .del de outro tipo de arquivo (cnh_frente.jpg.5.del)', file_exists($delCnh));
    hdAfirmar('nome fora do padrao nota_0[1-5].jpg.<id>.del e mantido (6 variantes)', file_exists($delNome1) && file_exists($delNome2) && file_exists($delNome3) && file_exists($delNome4) && file_exists($delNome5) && file_exists($delNome6));
    hdAfirmar('respeita a estrutura: .del na raiz, no nivel 1 e no nivel 3 nao e apagado', file_exists($delRaiz) && file_exists($delNivel1) && file_exists($delNivel3));
    hdAfirmar('respeita a estrutura: pasta de atendimento/data com nome fora do formato e ignorada', file_exists($delPastaInvalida) && file_exists($delDataInvalida));
    hdAfirmar('contagens agregadas: 3 removidos, 0 falhas', $r['removidos'] === 3 && $r['falhas'] === 0 && $r['mantidos'] === 2);

    // ------------------------------------------------------------
    hdSecao('Nao segue symlink/juncao (pasta e arquivo)');
    $alvoExterno = $fora . DIRECTORY_SEPARATOR . 'atendimento_externo';
    mkdir($alvoExterno, 0777, true);
    $arquivoExterno = $alvoExterno . DIRECTORY_SEPARATOR . 'nota_01.jpg.1.del';
    file_put_contents($arquivoExterno, 'fora do storage');
    touch($arquivoExterno, time() - 10 * 86400);

    $linkPasta = $raiz . DIRECTORY_SEPARATOR . '2026-09-03' . DIRECTORY_SEPARATOR . 'LNK1A23_303030';
    mkdir($raiz . DIRECTORY_SEPARATOR . '2026-09-03', 0777, true);
    $criouLinkPasta = hdCriarLinkDiretorio($linkPasta, $alvoExterno);
    if ($criouLinkPasta) {
        $storage->limparQuarentenaExpirada(time());
        hdAfirmar('pasta de atendimento que e symlink/juncao para fora NAO e seguida (.del externo intacto)', file_exists($arquivoExterno));
        hdRemoverLinkDiretorio($linkPasta);
    } else {
        echo "AVISO - link de diretorio indisponivel neste ambiente\n";
    }

    $alvoData = $fora . DIRECTORY_SEPARATOR . 'data_externa';
    mkdir($alvoData . DIRECTORY_SEPARATOR . 'ZZZ1A23_404040', 0777, true);
    $arquivoData = $alvoData . DIRECTORY_SEPARATOR . 'ZZZ1A23_404040' . DIRECTORY_SEPARATOR . 'nota_02.jpg.2.del';
    file_put_contents($arquivoData, 'fora do storage 2');
    touch($arquivoData, time() - 10 * 86400);
    $linkData = $raiz . DIRECTORY_SEPARATOR . '2026-09-04';
    if (hdCriarLinkDiretorio($linkData, $alvoData)) {
        $storage->limparQuarentenaExpirada(time());
        hdAfirmar('pasta de DATA que e symlink/juncao para fora NAO e seguida (.del externo intacto)', file_exists($arquivoData));
        hdRemoverLinkDiretorio($linkData);
    }

    // link de ARQUIVO (so onde o SO permite)
    $alvoArq = $fora . DIRECTORY_SEPARATOR . 'alvo_arquivo.txt';
    file_put_contents($alvoArq, 'alvo');
    $linkArq = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pasta) . DIRECTORY_SEPARATOR . 'nota_03.jpg.3.del';
    if (@symlink($alvoArq, $linkArq)) {
        touch($linkArq, time() - 10 * 86400);
        $storage->limparQuarentenaExpirada(time());
        hdAfirmar('.del que e symlink de ARQUIVO nao e apagado nem seguido (alvo intacto)', file_exists($alvoArq) && is_link($linkArq));
        @unlink($linkArq);
    } else {
        echo "AVISO - symlink de arquivo indisponivel (Windows sem privilegio); coberto pela checagem is_link no codigo\n";
    }

    // ------------------------------------------------------------
    hdSecao('Util\NotaArquivoStorage: caminho derivado, validacoes e quarentena');
    $pastaOk = '2026-09-10/PLC1A23_111111';
    $dirOk = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pastaOk);
    mkdir($dirOk, 0777, true);
    $caminho = $storage->caminhoDoArquivo($pastaOk, 'nota_01.jpg');
    hdAfirmar('caminhoDoArquivo valido devolve caminho dentro da raiz', $caminho !== null && str_starts_with($caminho, realpath($raiz) . DIRECTORY_SEPARATOR));
    foreach ([['../x', 'nota_01.jpg'], [$pastaOk, '../nota_01.jpg'], [$pastaOk, 'nota_09.jpg'], ['2026-09-10/plc_111111', 'nota_01.jpg'], [null, 'nota_01.jpg'], [$pastaOk, null], ['2026-09-10/NAOEXISTE_111111', 'nota_01.jpg'], [$pastaOk . '/..', 'nota_01.jpg'], [['a'], 'nota_01.jpg']] as [$p, $a]) {
        hdAfirmar('caminhoDoArquivo rejeita ' . json_encode([$p, $a]), $storage->caminhoDoArquivo($p, $a) === null);
    }
    hdAfirmar('storage sem raiz valida devolve null (fail-closed)', (new NotaArquivoStorage($raiz . '_inexistente'))->caminhoDoArquivo($pastaOk, 'nota_01.jpg') === null);
    hdAfirmar('pastaValida/arquivoValido: formatos', NotaArquivoStorage::pastaValida($pastaOk) && !NotaArquivoStorage::pastaValida('2026-9-10/PLC_1') && NotaArquivoStorage::arquivoValido('nota_05.jpg') && !NotaArquivoStorage::arquivoValido('nota_00.jpg') && !NotaArquivoStorage::arquivoValido('nota_05.jpeg'));
    hdAfirmar('pastaValida: placa com 0 a 10 alfanumericos (placa digitada so com simbolos vira vazia em montarPasta) e sem simbolos/minusculas', NotaArquivoStorage::pastaValida('2026-09-10/_123456') && NotaArquivoStorage::pastaValida('2026-09-10/ABCDEFGHIJ_123456') && !NotaArquivoStorage::pastaValida('2026-09-10/ABCDEFGHIJK_123456') && !NotaArquivoStorage::pastaValida('2026-09-10/AB-1_123456') && !NotaArquivoStorage::pastaValida('2026-09-10/ab1_123456'));

    $arq = $dirOk . DIRECTORY_SEPARATOR . 'nota_01.jpg';
    file_put_contents($arq, 'foto');
    touch($arq, time() - 5 * 86400);
    $cam = $storage->caminhoDoArquivo($pastaOk, 'nota_01.jpg');
    hdAfirmar('existe() true para o arquivo', $storage->existe($cam));
    hdAfirmar('quarentenar move (retorno quarentenado) e renova o mtime (retencao conta da quarentena)', $storage->quarentenar($cam, 55) === 'quarentenado' && !file_exists($arq) && file_exists($arq . '.55.del') && abs(filemtime($arq . '.55.del') - time()) < 60);
    hdAfirmar('cron logo apos NAO apaga a foto recem-quarentenada (mtime novo), mesmo vinda de captura antiga', file_exists($arq . '.55.del') && $storage->limparQuarentenaExpirada(time())['removidos'] === 0);
    hdAfirmar('quarentenar de novo reconhece ja_em_quarentena', $storage->quarentenar($cam, 55) === 'ja_em_quarentena');
    hdAfirmar('restaurar devolve a foto ao nome original', $storage->restaurar($cam, 55) === true && file_get_contents($arq) === 'foto' && !file_exists($arq . '.55.del'));
    hdAfirmar('quarentenar arquivo ausente (nem original nem .del) = ausente', $storage->quarentenar($dirOk . DIRECTORY_SEPARATOR . 'nota_02.jpg', 56) === 'ausente');
    $storage->quarentenar($cam, 57);
    hdAfirmar('remover apaga o .del e retorna true', $storage->remover($cam, 57) === true && !file_exists($arq . '.57.del'));
    hdAfirmar('remover sem .del = true (nada a fazer)', $storage->remover($cam, 57) === true);
    hdAfirmar('restaurar sem .del = false', $storage->restaurar($cam, 58) === false);

    // ------------------------------------------------------------
    hdSecao('CLI: cron/limpar-notas-quarentena.php (sem banco, log agregado, exit code)');
    $cronRaiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totem_hdc_cli_' . bin2hex(random_bytes(6));
    mkdir($cronRaiz, 0777, true);
    $cVelho = hdcMontar($cronRaiz, '2026-09-01/CLI1A23_010101', 'nota_01.jpg.9.del', 3 * 86400);
    $cNovo = hdcMontar($cronRaiz, '2026-09-01/CLI1A23_010101', 'nota_02.jpg.10.del', 600);
    $cAtiva = hdcMontar($cronRaiz, '2026-09-01/CLI1A23_010101', 'nota_01.jpg', 3 * 86400);
    $script = hdRaizProjeto() . '/cron/limpar-notas-quarentena.php';
    $executar = static function (array $env) use ($script): array {
        $proc = proc_open([PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log="' . $env['_LOG'] . '"', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env['_ENV']);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $codigo = proc_close($proc);

        return ['saida' => $out . $err, 'codigo' => $codigo, 'log' => (string) @file_get_contents($env['_LOG'])];
    };
    $logArq = $fora . DIRECTORY_SEPARATOR . 'cron_log.txt';
    file_put_contents($logArq, '');
    $envBase = array_merge(getenv(), [
        'STORAGE_PATH' => $cronRaiz,
        // banco INVALIDO de proposito: o cron nao pode depender dele
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_NAME' => 'nao_existe_cron', 'DB_USER' => 'x', 'DB_PASS' => 'x',
    ]);
    $res = $executar(['_LOG' => $logArq, '_ENV' => $envBase]);
    hdAfirmar('CLI: exit 0 mesmo com banco inacessivel (nao usa banco)', $res['codigo'] === 0);
    hdAfirmar('CLI: apagou so o .del velho; novo e nota ativa intactos', !file_exists($cVelho) && file_exists($cNovo) && file_exists($cAtiva));
    hdAfirmar('CLI: log agregado com contagens', str_contains($res['log'], 'limpar-notas-quarentena: 1 arquivo(s) .del apagado(s), 1 mantido(s)'));
    hdAfirmar('CLI: log sem caminho, pasta (placa), nome de arquivo ou id', !str_contains($res['log'], 'CLI1A23') && !str_contains($res['log'], 'nota_0') && !str_contains($res['log'], $cronRaiz) && !str_contains($res['log'], 'totem_hdc'));
    $res = $executar(['_LOG' => $logArq, '_ENV' => $envBase]);
    hdAfirmar('CLI: segunda execucao idempotente (exit 0, 0 apagados)', $res['codigo'] === 0 && str_contains($res['log'], '0 arquivo(s) .del apagado(s)'));

    $envSem = $envBase;
    $envSem['STORAGE_PATH'] = $cronRaiz . '_inexistente';
    file_put_contents($logArq, '');
    $res = $executar(['_LOG' => $logArq, '_ENV' => $envSem]);
    hdAfirmar('CLI: STORAGE_PATH inexistente = exit 1 com log generico (nada apagado)', $res['codigo'] === 1 && str_contains($res['log'], 'STORAGE_PATH ausente ou inacessivel') && !str_contains($res['log'], $cronRaiz));

    // limite exato de 24 h e falha de unlink => exit 1
    hdcMontar($cronRaiz, '2026-09-05/CLI2A23_020202', 'nota_03.jpg.11.del', 86400 + 120);
    hdcMontar($cronRaiz, '2026-09-05/CLI2A23_020202', 'nota_04.jpg.12.del', 86400 - 120);
    $storageCron = new NotaArquivoStorage($cronRaiz);
    $rr = $storageCron->limparQuarentenaExpirada(time());
    hdAfirmar('limite: 24 h + 2 min apaga; 24 h - 2 min mantem', $rr['removidos'] === 1 && file_exists($cronRaiz . '/2026-09-05/CLI2A23_020202/nota_04.jpg.12.del'));
    $rr = $storageCron->limparQuarentenaExpirada(time() + 86400);
    hdAfirmar('retencao customizavel por parametro (passou mais 24 h: apaga o restante .del)', $rr['removidos'] >= 2 && !file_exists($cronRaiz . '/2026-09-05/CLI2A23_020202/nota_04.jpg.12.del') && file_exists($cAtiva));

    // somente CLI: a checagem existe no codigo e o script nunca esta sob public/
    $codigoCron = file_get_contents($script);
    hdAfirmar("somente CLI: o script rejeita PHP_SAPI diferente de 'cli' (exit 1) e nao esta dentro de public/", str_contains($codigoCron, "PHP_SAPI !== 'cli'") && !file_exists(hdRaizProjeto() . '/public/api/limpar-notas-quarentena.php') && !str_contains(realpath($script), DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR));
    hdAfirmar('sem banco: o script nao referencia Conexao/Bootstrap/PDO', !preg_match('/Conexao|Bootstrap|PDO|mysql/i', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $codigoCron)));
    hdRemoverDiretorio($cronRaiz);
} finally {
    hdRemoverDiretorio($raiz);
    hdRemoverDiretorio($fora);
}

hdEncerrar('teste_hardening_cron_quarentena');
