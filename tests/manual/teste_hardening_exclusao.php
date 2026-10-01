<?php

/**
 * Suite de exclusao de nota, id_nota, lock e quarentena (demanda
 * hardening-revisao-notas-e-cliente, 2026-09-30, fase 2 backend, decisoes
 * D3 e D4). Cobre a matriz E: exclusao confirmada; clique duplo sequencial e
 * concorrente; arquivo ausente; falha de banco no DELETE (rollback e arquivo
 * restaurado); falha ao remover .del; falha ao quarentenar; excluir x
 * concluir concorrentes em N rodadas; posse e id_nota de outro atendimento;
 * caminho forjado no request; arquivo invalido no banco; reuso de ordem e
 * limite de 5; definir-numero e identificar-cliente em nota excluida e
 * id_nota antigo apos reuso; doctos[] com ordens nao contiguas; dois
 * processar concorrentes nao apagam arquivo legitimo; timeout de lock = 503;
 * cancelar move fotos para quarentena; logs sem dado (sentinelas); zero
 * requisicao ao mock do Talent.
 *
 * Banco `qa_` DESCARTAVEL + STORAGE_PATH temporario (hardening_helpers.php).
 * Sem Talent real (mock `php -S` local com contador), sem impressao, sem VIO.
 *
 * Uso: php tests/manual/teste_hardening_exclusao.php
 */

require_once __DIR__ . '/hardening_helpers.php';

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\FilaEnvioDao;
use App\Rn\NotaFiscalRn;
use App\Rn\TalentClient;
use App\Rn\TalentRn;

$amb = hdCriarAmbiente('hd_exclusao');
$pdo = $amb['pdo'];
$mock = null;

try {
    $idTotem = hdCriarTotem($pdo, 'HD-EXC');
    $idTotemAlheio = hdCriarTotem($pdo, 'HD-EXC-ALHEIO');
    $rn = new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));

    $pdo->exec('DELETE FROM tb_cliente');
    $cnpjA = hdCnpj(21);
    $cnpjB = hdCnpj(22);
    hdCriarCliente($pdo, 'CROMEX TINTAS LTDA', $cnpjA, 1, 'CROMEX TINTAS');
    hdCriarCliente($pdo, 'ZEBRA INDUSTRIA LTDA', $cnpjB, 1, 'ZEBRA INDUSTRIA');

    $mock = hdIniciarMockTalent($amb);
    hdAfirmar('Infra: mock local do Talent no ar (nunca o Talent real)', $mock !== null && str_starts_with($mock['url'], 'http://127.0.0.1:'));
    $optMock = ['talent_url' => $mock['url'] ?? ''];

    $processar = static fn(array $at, int $semente, ?int $ordem = null, array $opc = []) => hdRodar(
        $GLOBALS['hd_amb'], 'nota.processar', $GLOBALS['hd_totem'],
        ['id_atendimento' => $at['id'], 'imagem' => hdJpegDataUrl($semente)] + ($ordem !== null ? ['ordem' => $ordem] : []),
        $opc
    );
    $excluir = static fn(array $at, int $idNota, array $opc = [], ?int $totem = null) => hdRodar(
        $GLOBALS['hd_amb'], 'nota.excluir', $totem ?? $GLOBALS['hd_totem'], ['id_atendimento' => $at['id'], 'id_nota' => $idNota], $opc
    );
    $GLOBALS['hd_amb'] = $amb;
    $GLOBALS['hd_totem'] = $idTotem;

    // ===============================================================
    hdSecao('E1 processar: id_nota e ordem no retorno, ordem alocada pelo servidor');
    $at = hdCriarAtendimento($amb, $idTotem, 'SNTL9X9');
    $r = $processar($at, 1);
    $d = $r['corpo']['dados'] ?? [];
    hdAfirmar('E1: sem ordem no request -> 200 com a primeira ordem livre (1)', $r['http'] === 200 && ($d['ordem'] ?? null) === 1);
    hdAfirmar('E1: response com chaves cliente_identificado, cliente, id_nota, ordem (aditivo)', array_keys($d) === ['cliente_identificado', 'cliente', 'id_nota', 'ordem'] && $d['cliente_identificado'] === false && $d['cliente'] === null && is_int($d['id_nota']));
    hdAfirmar('E1: arquivo nota_01.jpg gravado com o conteudo enviado', file_get_contents($at['dir'] . '/nota_01.jpg') === hdJpegBytes(1));
    $id1 = $d['id_nota'];
    $r = $processar($at, 2, 3);
    hdAfirmar('E1b: ordem informada livre (3) e respeitada', $r['http'] === 200 && $r['corpo']['dados']['ordem'] === 3 && file_get_contents($at['dir'] . '/nota_03.jpg') === hdJpegBytes(2));
    $id3 = $r['corpo']['dados']['id_nota'];
    $r = $processar($at, 3);
    hdAfirmar('E1c: sem ordem, aloca a primeira livre (2, preenchendo o buraco)', $r['http'] === 200 && $r['corpo']['dados']['ordem'] === 2);
    $id2 = $r['corpo']['dados']['id_nota'];
    $r = $processar($at, 4, 3);
    hdAfirmar('E1d: ordem informada ocupada continua 400 e nao sobrescreve o arquivo', $r['http'] === 400 && file_get_contents($at['dir'] . '/nota_03.jpg') === hdJpegBytes(2));
    $r = $processar($at, 4, 0);
    hdAfirmar('E1e: ordem 0 informada = 400 (Ordem da nota invalida)', $r['http'] === 400 && str_contains($r['stdout'], 'Ordem da nota invalida'));
    $r = $processar($at, 4, 6);
    hdAfirmar('E1f: ordem 6 informada = 400', $r['http'] === 400 && str_contains($r['stdout'], 'Ordem da nota invalida'));
    $r = hdRodar($amb, 'nota.processar', $idTotem, ['id_atendimento' => $at['id'], 'ordem' => 4]);
    hdAfirmar('E1g: sem imagem = 400 Dados incompletos', $r['http'] === 400);
    $r = hdRodar($amb, 'nota.processar', $idTotem, ['id_atendimento' => $at['id'], 'imagem' => 'data:image/jpeg;base64,@@@@']);
    hdAfirmar('E1h: imagem base64 invalida = 400 e nada gravado', $r['http'] === 400 && count(hdNotas($pdo, $at['id'])) === 3);
    $processar($at, 5);
    $processar($at, 6);
    $r = $processar($at, 7);
    hdAfirmar('E1i: 6a nota = 400 (limite de 5) sem arquivo novo', $r['http'] === 400 && count(hdNotas($pdo, $at['id'])) === 5 && count(hdArquivosDaPasta($at)) === 5);
    $r = $processar($at, 7, 2);
    hdAfirmar('E1j: limite de 5 tambem com ordem informada', $r['http'] === 400);
    $r = hdRodar($amb, 'nota.processar', $idTotemAlheio, ['id_atendimento' => $at['id'], 'imagem' => hdJpegDataUrl(1)]);
    hdAfirmar('E1k: totem alheio = 404', $r['http'] === 404);
    $atExp = hdCriarAtendimento($amb, $idTotem, 'EXP1A23', 'em_andamento', 'digitalizacao_notas', 'expedicao');
    $r = $processar($atExp, 1);
    hdAfirmar('E1l: atendimento de expedicao = 404', $r['http'] === 404);
    $atEt = hdCriarAtendimento($amb, $idTotem, 'ETP1A23', 'em_andamento', 'placa');
    $r = $processar($atEt, 1);
    hdAfirmar('E1m: etapa diferente de digitalizacao_notas = 400 sem gravar', $r['http'] === 400 && hdArquivosDaPasta($atEt) === []);

    // ===============================================================
    hdSecao('E2 exclusao confirmada (quarentena, DELETE, COMMIT, unlink do .del)');
    $notasAntes = hdNotas($pdo, $at['id']);
    $bytesOutras = [];
    foreach ($notasAntes as $n) {
        $bytesOutras[$n['arquivo']] = file_get_contents($at['dir'] . '/' . $n['arquivo']);
    }
    $r = $excluir($at, $id2);
    $d = $r['corpo']['dados'] ?? [];
    hdAfirmar('E2: 200 com excluida=true e ja_excluida=false', $r['http'] === 200 && $d['excluida'] === true && $d['ja_excluida'] === false && $d['id_nota'] === $id2);
    hdAfirmar('E2: chaves exatas (excluida, ja_excluida, id_nota, total_notas, notas, cliente_atendimento)', array_keys($d) === ['excluida', 'ja_excluida', 'id_nota', 'total_notas', 'notas', 'cliente_atendimento']);
    hdAfirmar('E2: total_notas 4 e notas [{id_nota, ordem, numero_definido}] sem a excluida', $d['total_notas'] === 4 && count($d['notas']) === 4 && !in_array($id2, array_column($d['notas'], 'id_nota'), true) && array_keys($d['notas'][0]) === ['id_nota', 'ordem', 'numero_definido'] && $d['notas'][0]['numero_definido'] === false);
    hdAfirmar('E2: linha removida do banco e ordem 2 livre', !in_array(2, array_map('intval', array_column(hdNotas($pdo, $at['id']), 'ordem')), true));
    hdAfirmar('E2: nota_02.jpg sumiu E nenhum .del ficou', !file_exists($at['dir'] . '/nota_02.jpg') && !in_array('nota_02.jpg.' . $id2 . '.del', hdArquivosDaPasta($at), true));
    $intactas = true;
    foreach (hdNotas($pdo, $at['id']) as $n) {
        $intactas = $intactas && file_get_contents($at['dir'] . '/' . $n['arquivo']) === $bytesOutras[$n['arquivo']];
    }
    hdAfirmar('E2: as outras 4 fotos intactas', $intactas);
    hdAfirmar('E2: cliente_atendimento presente (NAO_IDENTIFICADO, cliente nulo)', $d['cliente_atendimento'] === ['estado' => 'NAO_IDENTIFICADO', 'cliente' => null]);
    hdAfirmar('E2: resposta sem caminho, pasta, nome de arquivo, numero ou CNPJ', !preg_match('#nota_0|\.jpg|\.del|totem_hd|SNTL9X9|' . date('Y-m-d') . '/#', $r['stdout']));

    // ===============================================================
    hdSecao('E3 clique duplo: sequencial e concorrente');
    $r = $excluir($at, $id2);
    $d = $r['corpo']['dados'] ?? [];
    hdAfirmar('E3: segunda exclusao sequencial = 200 ja_excluida=true, excluida=false, sem efeito', $r['http'] === 200 && $d['ja_excluida'] === true && $d['excluida'] === false && $d['total_notas'] === 4);
    for ($rodada = 1; $rodada <= 4; $rodada++) {
        $atc = hdCriarAtendimento($amb, $idTotem, 'DBL1A23');
        $a = hdCriarNota($pdo, $atc, 1, '11');
        $b = hdCriarNota($pdo, $atc, 2, '22');
        $res = hdRodarParalelo($amb, [
            ['rota' => 'nota.excluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atc['id'], 'id_nota' => $a]],
            ['rota' => 'nota.excluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atc['id'], 'id_nota' => $a]],
        ]);
        $excluidas = ($res[0]['corpo']['dados']['excluida'] ?? false ? 1 : 0) + ($res[1]['corpo']['dados']['excluida'] ?? false ? 1 : 0);
        $ja = ($res[0]['corpo']['dados']['ja_excluida'] ?? false ? 1 : 0) + ($res[1]['corpo']['dados']['ja_excluida'] ?? false ? 1 : 0);
        hdAfirmar("E3 rodada {$rodada}: 2 exclusoes simultaneas = 2x 200, exatamente 1 excluida e 1 ja_excluida", $res[0]['http'] === 200 && $res[1]['http'] === 200 && $excluidas === 1 && $ja === 1);
        hdAfirmar("E3 rodada {$rodada}: so a nota b resta, foto a removida, sem .del", count(hdNotas($pdo, $atc['id'])) === 1 && hdArquivosDaPasta($atc) === ['nota_02.jpg']);
    }

    // ===============================================================
    hdSecao('E4 arquivo ausente: remove a linha e registra log fixo');
    $at4 = hdCriarAtendimento($amb, $idTotem, 'AUS1A23');
    $n = hdCriarNota($pdo, $at4, 1, '41', 'PENDENTE', null, false);
    $r = $excluir($at4, $n);
    hdAfirmar('E4: 200 excluida=true mesmo sem o arquivo', $r['http'] === 200 && $r['corpo']['dados']['excluida'] === true && hdNotas($pdo, $at4['id']) === []);
    hdAfirmar('E4: log fixo arquivo_ausente_na_exclusao (sem caminho)', str_contains($r['log'], 'arquivo_ausente_na_exclusao') && !str_contains($r['log'], 'nota_01'));

    // ===============================================================
    hdSecao('E5 falhas de banco: DELETE/commit -> ROLLBACK e arquivo restaurado');
    foreach (['dao_delete_falha' => 'DELETE lanca PDOException', 'dao_delete_zero' => 'DELETE afeta 0 linhas', 'dao_commit_falha' => 'COMMIT falha'] as $inj => $rotulo) {
        $at5 = hdCriarAtendimento($amb, $idTotem, 'FAL1A23');
        $n = hdCriarNota($pdo, $at5, 1, '51');
        $bytes = file_get_contents($at5['dir'] . '/nota_01.jpg');
        $r = $excluir($at5, $n, ['injecao' => $inj]);
        hdAfirmar("E5 ({$rotulo}): HTTP 500 generico", $r['http'] === 500 && !str_contains($r['stdout'], 'SENTINELA'));
        hdAfirmar("E5 ({$rotulo}): linha continua no banco (rollback)", count(hdNotas($pdo, $at5['id'])) === 1);
        hdAfirmar("E5 ({$rotulo}): foto RESTAURADA com o mesmo conteudo e nenhum .del sobrando", hdArquivosDaPasta($at5) === ['nota_01.jpg'] && file_get_contents($at5['dir'] . '/nota_01.jpg') === $bytes);
        hdAfirmar("E5 ({$rotulo}): log sem getMessage", !str_contains($r['log'], 'SENTINELA'));
        $r = $excluir($at5, $n);
        hdAfirmar("E5 ({$rotulo}): nova tentativa sem a falha conclui (200) e limpa a foto", $r['http'] === 200 && $r['corpo']['dados']['excluida'] === true && hdArquivosDaPasta($at5) === []);
    }

    // retry apos falha parcial: foto ja em quarentena e linha ainda no banco
    $at5 = hdCriarAtendimento($amb, $idTotem, 'PAR1A23');
    $n = hdCriarNota($pdo, $at5, 1, '52');
    rename($at5['dir'] . '/nota_01.jpg', $at5['dir'] . '/nota_01.jpg.' . $n . '.del');
    $r = $excluir($at5, $n);
    hdAfirmar('E5b: estado parcial (so o .del existe, linha no banco) -> retry reconhece a quarentena e conclui', $r['http'] === 200 && $r['corpo']['dados']['excluida'] === true && hdArquivosDaPasta($at5) === [] && hdNotas($pdo, $at5['id']) === []);

    // ===============================================================
    hdSecao('E6 falha ao remover o .del: sucesso ao motorista, orfao reconhecivel, log fixo');
    $at6 = hdCriarAtendimento($amb, $idTotem, 'REM1A23');
    $n = hdCriarNota($pdo, $at6, 1, '61');
    $r = $excluir($at6, $n, ['injecao' => 'storage_remover_falha']);
    hdAfirmar('E6: 200 excluida=true (banco ja confirmado)', $r['http'] === 200 && $r['corpo']['dados']['excluida'] === true && hdNotas($pdo, $at6['id']) === []);
    hdAfirmar('E6: orfao .del permanece com nome reconhecivel (cron cobre)', hdArquivosDaPasta($at6) === ['nota_01.jpg.' . $n . '.del']);
    hdAfirmar('E6: log fixo de falha ao remover a quarentena (sem caminho)', str_contains($r['log'], 'falha ao remover a foto em quarentena') && !str_contains($r['log'], 'nota_01'));
    $r = $excluir($at6, $n);
    hdAfirmar('E6b: repetir a exclusao = ja_excluida (nao apaga o orfao, o cron cuida)', $r['http'] === 200 && $r['corpo']['dados']['ja_excluida'] === true);

    // ===============================================================
    hdSecao('E7 falha ao quarentenar: 500, nada muda');
    $at7 = hdCriarAtendimento($amb, $idTotem, 'QUA1A23');
    $n = hdCriarNota($pdo, $at7, 1, '71');
    $bytes = file_get_contents($at7['dir'] . '/nota_01.jpg');
    $r = $excluir($at7, $n, ['injecao' => 'storage_quarentenar_falha']);
    hdAfirmar('E7: 500 generico', $r['http'] === 500 && !str_contains($r['stdout'], 'quarentena'));
    hdAfirmar('E7: linha e foto intactas', count(hdNotas($pdo, $at7['id'])) === 1 && file_get_contents($at7['dir'] . '/nota_01.jpg') === $bytes && hdArquivosDaPasta($at7) === ['nota_01.jpg']);

    // ===============================================================
    hdSecao('E8 posse, tipo, status e etapa');
    $at8 = hdCriarAtendimento($amb, $idTotem, 'POS1A23');
    $n = hdCriarNota($pdo, $at8, 1, '81');
    $r = $excluir($at8, $n, [], $idTotemAlheio);
    hdAfirmar('E8: totem alheio = 404 (nota e foto intactas)', $r['http'] === 404 && count(hdNotas($pdo, $at8['id'])) === 1 && hdArquivosDaPasta($at8) === ['nota_01.jpg']);
    $r = hdRodar($amb, 'nota.excluir', $idTotem, ['id_atendimento' => 999999, 'id_nota' => $n]);
    hdAfirmar('E8: atendimento inexistente = 404', $r['http'] === 404);
    foreach ([[], ['id_atendimento' => $at8['id']], ['id_nota' => $n], ['id_atendimento' => $at8['id'], 'id_nota' => 'abc'], ['id_atendimento' => $at8['id'], 'id_nota' => 0], ['id_atendimento' => $at8['id'], 'id_nota' => -1]] as $entrada) {
        $r = hdRodar($amb, 'nota.excluir', $idTotem, $entrada);
        hdAfirmar('E8: entrada incompleta/invalida ' . json_encode($entrada) . ' = 400', $r['http'] === 400);
    }
    $atE = hdCriarAtendimento($amb, $idTotem, 'EXC1A23', 'em_andamento', 'digitalizacao_notas', 'expedicao');
    $ne = hdCriarNota($pdo, $atE, 1, '82');
    $r = $excluir($atE, $ne);
    hdAfirmar('E8: atendimento de expedicao = 404 (foto intacta)', $r['http'] === 404 && hdArquivosDaPasta($atE) === ['nota_01.jpg']);
    foreach (['concluido', 'cancelado', 'bloqueado'] as $status) {
        $atS = hdCriarAtendimento($amb, $idTotem, 'STA1A23', $status);
        $ns = hdCriarNota($pdo, $atS, 1, '83');
        $r = $excluir($atS, $ns);
        hdAfirmar("E8: status {$status} = 400 sem tocar disco nem banco", $r['http'] === 400 && count(hdNotas($pdo, $atS['id'])) === 1 && hdArquivosDaPasta($atS) === ['nota_01.jpg']);
    }
    foreach (['rec_cnh', 'cliente', 'placa'] as $etapa) {
        $atS = hdCriarAtendimento($amb, $idTotem, 'ETA1A23', 'em_andamento', $etapa);
        $ns = hdCriarNota($pdo, $atS, 1, '84');
        $r = $excluir($atS, $ns);
        hdAfirmar("E8: etapa {$etapa} (inclusive apos concluir a digitalizacao) = 400 sem tocar disco nem banco", $r['http'] === 400 && count(hdNotas($pdo, $atS['id'])) === 1 && hdArquivosDaPasta($atS) === ['nota_01.jpg']);
    }

    // id_nota de OUTRO atendimento do mesmo totem
    $atA = hdCriarAtendimento($amb, $idTotem, 'AAA1A23');
    $atB = hdCriarAtendimento($amb, $idTotem, 'BBB1A23');
    $na = hdCriarNota($pdo, $atA, 1, '91');
    $nb = hdCriarNota($pdo, $atB, 1, '92');
    $r = $excluir($atA, $nb);
    hdAfirmar('E8b: id_nota de OUTRO atendimento = 200 ja_excluida (sem vazar existencia) e a nota alheia intacta', $r['http'] === 200 && $r['corpo']['dados']['ja_excluida'] === true && count(hdNotas($pdo, $atB['id'])) === 1 && hdArquivosDaPasta($atB) === ['nota_01.jpg'] && count(hdNotas($pdo, $atA['id'])) === 1);
    $r = hdRodar($amb, 'nota.excluir', $idTotem, ['id_atendimento' => $atA['id'], 'id_nota' => $na, 'ordem' => 7]);
    hdAfirmar('E8c: campo ordem no request de excluir e ignorado (exclui por id_nota)', $r['http'] === 200 && $r['corpo']['dados']['excluida'] === true);

    // ===============================================================
    hdSecao('E9 caminho forjado no request e arquivo invalido no banco');
    $vitima = $amb['storage'] . DIRECTORY_SEPARATOR . 'vitima_segredo.txt';
    $vitima2 = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'vitima_fora.txt';
    @mkdir($amb['dir_tmp'], 0777, true);
    file_put_contents($vitima, 'nao apagar');
    file_put_contents($vitima2, 'nao apagar');
    $at9 = hdCriarAtendimento($amb, $idTotem, 'FOR1A23');
    $n = hdCriarNota($pdo, $at9, 1, '95');
    $r = hdRodar($amb, 'nota.excluir', $idTotem, [
        'id_atendimento' => $at9['id'], 'id_nota' => $n,
        'arquivo' => '../../vitima_segredo.txt', 'caminho' => $vitima, 'pasta_documentos' => '../..',
        'path' => $vitima2, 'nome' => '../vitima_fora.txt',
    ]);
    hdAfirmar('E9: campos de caminho/arquivo no request sao IGNORADOS (exclui a nota correta)', $r['http'] === 200 && $r['corpo']['dados']['excluida'] === true && hdNotas($pdo, $at9['id']) === [] && hdArquivosDaPasta($at9) === []);
    hdAfirmar('E9: arquivos-vitima fora da pasta da nota intactos', file_get_contents($vitima) === 'nao apagar' && file_get_contents($vitima2) === 'nao apagar');

    $variantesArquivo = ['../../vitima_segredo.txt', '..\\..\\vitima_segredo.txt', 'nota_06.jpg', 'NOTA_01.JPG', 'nota_01.jpg/../../vitima_segredo.txt', '', 'nota_01.jpg ', 'nota_1.jpg', 'cnh_frente.jpg', $vitima];
    foreach ($variantesArquivo as $valor) {
        $atV = hdCriarAtendimento($amb, $idTotem, 'INV1A23');
        $n = hdCriarNota($pdo, $atV, 1, '96');
        $pdo->prepare('UPDATE tb_atendimento_nota SET arquivo = :a WHERE id_nota = :n')->execute(['a' => $valor, 'n' => $n]);
        file_put_contents($atV['dir'] . '/cnh_frente.jpg', 'documento');
        $r = $excluir($atV, $n);
        hdAfirmar('E9: arquivo invalido no banco ' . json_encode($valor) . ' = 500 sem tocar disco, linha mantida', $r['http'] === 500 && count(hdNotas($pdo, $atV['id'])) === 1 && file_exists($atV['dir'] . '/nota_01.jpg') && file_exists($atV['dir'] . '/cnh_frente.jpg') && file_get_contents($vitima) === 'nao apagar');
    }
    $variantesPasta = ['../vitima', '2026-09-30/../..', 'C:/Windows', '/etc', '2026-09-30/placa_minuscula_123456', '2026-09-30/ABC1D23_12345', '2026-09-30/ABC1D23_1234567', 'x', '', date('Y-m-d') . '/INV1A23_000001/../..'];
    foreach ($variantesPasta as $valor) {
        $atV = hdCriarAtendimento($amb, $idTotem, 'INP1A23');
        $n = hdCriarNota($pdo, $atV, 1, '97');
        $pdo->prepare('UPDATE tb_atendimento SET pasta_documentos = :p WHERE id_atendimento = :id')->execute(['p' => $valor, 'id' => $atV['id']]);
        $r = $excluir($atV, $n);
        hdAfirmar('E9: pasta_documentos invalida ' . json_encode($valor) . ' = 500, nota e foto mantidas', $r['http'] === 500 && count(hdNotas($pdo, $atV['id'])) === 1 && file_exists($atV['dir'] . '/nota_01.jpg'));
    }
    // pasta valida em formato mas inexistente
    $atV = hdCriarAtendimento($amb, $idTotem, 'INX1A23');
    $n = hdCriarNota($pdo, $atV, 1, '98');
    $pdo->prepare('UPDATE tb_atendimento SET pasta_documentos = :p WHERE id_atendimento = :id')->execute(['p' => '2026-01-01/NAOEXISTE_000000', 'id' => $atV['id']]);
    $r = $excluir($atV, $n);
    hdAfirmar('E9: pasta com formato valido mas inexistente = 500 (nao cria nem toca nada)', $r['http'] === 500 && !is_dir($amb['storage'] . '/2026-01-01') && count(hdNotas($pdo, $atV['id'])) === 1);
    // symlink
    $atV = hdCriarAtendimento($amb, $idTotem, 'SYM1A23');
    $n = hdCriarNota($pdo, $atV, 1, '99');
    $alvoReal = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'alvo_symlink';
    @mkdir($alvoReal, 0777, true);
    file_put_contents($alvoReal . '/nota_01.jpg', 'dentro do alvo do symlink');
    $pastaSym = date('Y-m-d') . '/SYMLNK_000001';
    $dirSym = $amb['storage'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pastaSym);
    $criouLink = hdCriarLinkDiretorio($dirSym, $alvoReal);
    if ($criouLink) {
        $pdo->prepare('UPDATE tb_atendimento SET pasta_documentos = :p WHERE id_atendimento = :id')->execute(['p' => $pastaSym, 'id' => $atV['id']]);
        $r = $excluir($atV, $n);
        hdAfirmar('E9: pasta que e symlink/juncao para fora do storage = 500, alvo intacto', $r['http'] === 500 && file_exists($alvoReal . '/nota_01.jpg') && count(hdNotas($pdo, $atV['id'])) === 1);
        hdRemoverLinkDiretorio($dirSym);
    } else {
        echo "AVISO - symlink indisponivel neste ambiente (Windows sem privilegio): caso do symlink de PASTA coberto so por teste unitario de NotaArquivoStorage em teste_hardening_cron_quarentena\n";
    }

    // ===============================================================
    hdSecao('E10 reuso de ordem (id_nota nunca reutilizado) e limite de 5');
    $at10 = hdCriarAtendimento($amb, $idTotem, 'REU1A23');
    $ids = [];
    for ($i = 1; $i <= 5; $i++) {
        $r = $processar($at10, 100 + $i);
        $ids[$r['corpo']['dados']['ordem']] = $r['corpo']['dados']['id_nota'];
    }
    hdAfirmar('E10: 5 notas com ordens 1..5', array_keys($ids) === [1, 2, 3, 4, 5]);
    $r = $processar($at10, 110);
    hdAfirmar('E10: 6a = 400', $r['http'] === 400);
    $r = $excluir($at10, $ids[3]);
    hdAfirmar('E10: excluir a de ordem 3 -> total 4', $r['http'] === 200 && $r['corpo']['dados']['total_notas'] === 4);
    $r = $processar($at10, 120);
    $novo = $r['corpo']['dados'] ?? [];
    hdAfirmar('E10: Adicionar outra nota (com menos de 5): servidor aloca a ordem 3 com NOVO id_nota', $r['http'] === 200 && $novo['ordem'] === 3 && $novo['id_nota'] !== $ids[3] && $novo['id_nota'] > $ids[5]);
    hdAfirmar('E10: nota_03.jpg agora tem a imagem NOVA', file_get_contents($at10['dir'] . '/nota_03.jpg') === hdJpegBytes(120));
    $r = $processar($at10, 130);
    hdAfirmar('E10: 6a de novo = 400 (limite de 5 preservado apos reuso)', $r['http'] === 400 && count(hdNotas($pdo, $at10['id'])) === 5);

    // ===============================================================
    hdSecao('E11 definir-numero e identificar-cliente: nota excluida e id_nota antigo = 404');
    $idAntigo = $ids[3];
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $idAntigo, 'numero' => '555', 'origem' => 'MANUAL']);
    hdAfirmar('E11: definir-numero com id_nota ANTIGO (ordem 3 reaproveitada) = 404 sem escrita', $r['http'] === 404 && hdNotas($pdo, $at10['id'])[2]['numero_nota'] === null);
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $idAntigo, 'ordem' => 3, 'numero' => '555', 'origem' => 'MANUAL']);
    hdAfirmar('E11: idem com id_nota antigo + ordem 3 = 404', $r['http'] === 404 && hdNotas($pdo, $at10['id'])[2]['numero_nota'] === null);
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $idAntigo, 'cnpjs_candidatos' => [$cnpjA]]);
    hdAfirmar('E11: identificar-cliente com id_nota antigo = 404 sem escrita', $r['http'] === 404 && hdNotas($pdo, $at10['id'])[2]['status_ocr'] === 'PENDENTE');
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $novo['id_nota'], 'numero' => '000555', 'origem' => 'MANUAL']);
    hdAfirmar('E11: id_nota novo funciona (numero normalizado, resposta com id_nota e ordem)', $r['http'] === 200 && $r['corpo']['dados']['numero_nota'] === '555' && $r['corpo']['dados']['id_nota'] === $novo['id_nota'] && $r['corpo']['dados']['ordem'] === 3);
    $r2 = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $novo['id_nota'], 'numero' => '555', 'origem' => 'MANUAL']);
    hdAfirmar('E11: definir-numero repetido com o mesmo valor = idempotente (200)', $r2['http'] === 200 && $r2['corpo']['dados']['numero_nota'] === '555');
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $ids[2], 'numero' => '555', 'origem' => 'MANUAL']);
    hdAfirmar('E11: numero duplicado em OUTRA nota = 409', $r['http'] === 409);
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'ordem' => 2, 'numero' => '777', 'origem' => 'OCR']);
    hdAfirmar('E11: compat por ordem continua (200)', $r['http'] === 200 && $r['corpo']['dados']['numero_nota'] === '777');
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $ids[1], 'ordem' => 2, 'numero' => '888', 'origem' => 'OCR']);
    hdAfirmar('E11: id_nota e ordem divergentes = 404 sem escrita', $r['http'] === 404 && hdNotas($pdo, $at10['id'])[0]['numero_nota'] === null);
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $nb, 'numero' => '888', 'origem' => 'OCR']);
    hdAfirmar('E11: id_nota de outro atendimento = 404', $r['http'] === 404);
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'numero' => '888', 'origem' => 'OCR']);
    hdAfirmar('E11: sem id_nota e sem ordem = 400', $r['http'] === 400);
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $ids[1], 'numero' => '12x', 'origem' => 'OCR']);
    hdAfirmar('E11: numero invalido = 400 (mensagem fixa)', $r['http'] === 400);

    // ===============================================================
    hdSecao('E12 excluir a nota que identificou o cliente recalcula; 0 notas nao conclui');
    $at12 = hdCriarAtendimento($amb, $idTotem, 'CLI1A23');
    $p1 = $processar($at12, 201)['corpo']['dados']['id_nota'];
    $p2 = $processar($at12, 202)['corpo']['dados']['id_nota'];
    hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at12['id'], 'id_nota' => $p1, 'cnpjs_candidatos' => [$cnpjA]]);
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at12['id'], 'id_nota' => $p2, 'cnpjs_candidatos' => [$cnpjB]]);
    hdAfirmar('E12: duas notas de clientes distintos = ANOMALIA', $r['corpo']['dados']['cliente_atendimento']['estado'] === 'ANOMALIA');
    $r = $excluir($at12, $p2);
    hdAfirmar('E12: excluir a nota conflitante resolve: IDENTIFICADO com o cliente A', $r['http'] === 200 && $r['corpo']['dados']['cliente_atendimento']['estado'] === 'IDENTIFICADO' && $r['corpo']['dados']['cliente_atendimento']['cliente']['cnpj'] === $cnpjA);
    $r = $excluir($at12, $p1);
    hdAfirmar('E12: excluir a nota que identificou recalcula: NAO_IDENTIFICADO e 0 notas', $r['http'] === 200 && $r['corpo']['dados']['cliente_atendimento'] === ['estado' => 'NAO_IDENTIFICADO', 'cliente' => null] && $r['corpo']['dados']['total_notas'] === 0);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at12['id']]);
    hdAfirmar('E12: concluir com 0 notas (todas excluidas) = 400 e etapa inalterada', $r['http'] === 400 && hdAtendimento($pdo, $at12['id'])['etapa_atual'] === 'digitalizacao_notas' && hdArquivosDaPasta($at12) === []);
    // excluir nota em ERRO resolve a anomalia INDETERMINADO
    $at12b = hdCriarAtendimento($amb, $idTotem, 'ERR1A23');
    $q1 = $processar($at12b, 211)['corpo']['dados']['id_nota'];
    $q2 = $processar($at12b, 212)['corpo']['dados']['id_nota'];
    hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at12b['id'], 'id_nota' => $q1, 'cnpjs_candidatos' => [$cnpjA, $cnpjB]]);
    hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at12b['id'], 'id_nota' => $q2, 'cnpjs_candidatos' => [$cnpjA]]);
    $r = $excluir($at12b, $q1);
    hdAfirmar('E12: excluir a nota em ERRO resolve: IDENTIFICADO com o cliente da outra nota', $r['corpo']['dados']['cliente_atendimento']['estado'] === 'IDENTIFICADO');

    // ===============================================================
    hdSecao('E13 excluir x concluir concorrentes (N rodadas)');
    $desfechos = ['excluir_antes' => 0, 'concluir_antes' => 0, 'invalido' => 0];
    for ($rodada = 1; $rodada <= 12; $rodada++) {
        $atr = hdCriarAtendimento($amb, $idTotem, 'RCE1A23');
        $a = hdCriarNota($pdo, $atr, 1, '1001');
        $b = hdCriarNota($pdo, $atr, 2, '1002');
        $chamadas = [
            ['rota' => 'nota.excluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atr['id'], 'id_nota' => $a]],
            ['rota' => 'atendimento.concluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atr['id']], 'opcoes' => ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'true']]],
        ];
        if ($rodada % 2 === 0) {
            $chamadas = array_reverse($chamadas);
        }
        $res = hdRodarParalelo($amb, $chamadas);
        $rExc = $rodada % 2 === 0 ? $res[1] : $res[0];
        $rCon = $rodada % 2 === 0 ? $res[0] : $res[1];
        $linhas = count(hdNotas($pdo, $atr['id']));
        $arqs = hdArquivosDaPasta($atr);
        $etapa = hdAtendimento($pdo, $atr['id'])['etapa_atual'];
        if ($rExc['http'] === 200 && $rCon['http'] === 200 && $linhas === 1 && $arqs === ['nota_02.jpg'] && $etapa === 'cliente') {
            $desfechos['excluir_antes']++;
        } elseif ($rExc['http'] === 400 && $rCon['http'] === 200 && $linhas === 2 && $arqs === ['nota_01.jpg', 'nota_02.jpg'] && $etapa === 'cliente') {
            $desfechos['concluir_antes']++;
        } else {
            $desfechos['invalido']++;
            echo "  INVALIDO rodada {$rodada}: excluir={$rExc['http']} concluir={$rCon['http']} linhas={$linhas} arquivos=" . implode(',', $arqs) . " etapa={$etapa}\n";
        }
    }
    hdAfirmar('E13: 12 rodadas, TODAS com desfecho consistente (nota e foto sempre coerentes; nunca linha sem foto nem foto sem linha)', $desfechos['invalido'] === 0);
    echo "  desfechos: excluir_antes={$desfechos['excluir_antes']} concluir_antes={$desfechos['concluir_antes']}\n";

    // ===============================================================
    hdSecao('E14 dois processar concorrentes nao apagam arquivo legitimo');
    for ($rodada = 1; $rodada <= 6; $rodada++) {
        $atp = hdCriarAtendimento($amb, $idTotem, 'PRC1A23');
        $semA = 300 + $rodada * 2;
        $semB = 301 + $rodada * 2;
        $res = hdRodarParalelo($amb, [
            ['rota' => 'nota.processar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atp['id'], 'ordem' => 2, 'imagem' => hdJpegDataUrl($semA)]],
            ['rota' => 'nota.processar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atp['id'], 'ordem' => 2, 'imagem' => hdJpegDataUrl($semB)]],
        ]);
        $vencedora = $res[0]['http'] === 200 ? 0 : 1;
        $semente = $vencedora === 0 ? $semA : $semB;
        $ok = ($res[0]['http'] === 200) !== ($res[1]['http'] === 200) && $res[1 - $vencedora]['http'] === 400;
        hdAfirmar("E14 rodada {$rodada}: mesma ordem -> exatamente 1x 200 e 1x 400", $ok);
        hdAfirmar("E14 rodada {$rodada}: a foto da vencedora permanece intacta e a linha existe", count(hdNotas($pdo, $atp['id'])) === 1 && is_file($atp['dir'] . '/nota_02.jpg') && file_get_contents($atp['dir'] . '/nota_02.jpg') === hdJpegBytes($semente) && hdArquivosDaPasta($atp) === ['nota_02.jpg']);
    }
    $atp = hdCriarAtendimento($amb, $idTotem, 'PRD1A23');
    $res = hdRodarParalelo($amb, [
        ['rota' => 'nota.processar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atp['id'], 'imagem' => hdJpegDataUrl(401)]],
        ['rota' => 'nota.processar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atp['id'], 'imagem' => hdJpegDataUrl(402)]],
    ]);
    $ordens = [$res[0]['corpo']['dados']['ordem'] ?? null, $res[1]['corpo']['dados']['ordem'] ?? null];
    sort($ordens);
    hdAfirmar('E14b: dois processar SEM ordem simultaneos = 2x 200 com ordens 1 e 2 distintas', $res[0]['http'] === 200 && $res[1]['http'] === 200 && $ordens === [1, 2]);
    $conteudoOk = true;
    foreach ([401 => $res[0], 402 => $res[1]] as $semente => $resp) {
        $conteudoOk = $conteudoOk && file_get_contents($atp['dir'] . '/' . sprintf('nota_%02d.jpg', $resp['corpo']['dados']['ordem'])) === hdJpegBytes($semente);
    }
    hdAfirmar('E14b: cada foto no arquivo da ordem alocada para ela', $conteudoOk);
    $atp = hdCriarAtendimento($amb, $idTotem, 'PRE1A23');
    $chamadas = [];
    for ($i = 0; $i < 7; $i++) {
        $chamadas[] = ['rota' => 'nota.processar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atp['id'], 'imagem' => hdJpegDataUrl(500 + $i)]];
    }
    $res = hdRodarParalelo($amb, $chamadas);
    $sucessos = array_filter($res, static fn(array $x): bool => $x['http'] === 200);
    $ordensSucesso = array_map(static fn(array $x): int => $x['corpo']['dados']['ordem'], $sucessos);
    sort($ordensSucesso);
    hdAfirmar('E14c: 7 processar simultaneos = exatamente 5 sucessos com ordens 1..5 distintas e 2x 400 (limite)', count($sucessos) === 5 && array_values($ordensSucesso) === [1, 2, 3, 4, 5] && count(array_filter($res, static fn(array $x): bool => $x['http'] === 400)) === 2);
    $coerente = count(hdNotas($pdo, $atp['id'])) === 5 && count(hdArquivosDaPasta($atp)) === 5;
    foreach ($sucessos as $i => $resp) {
        $coerente = $coerente && file_get_contents($atp['dir'] . '/' . sprintf('nota_%02d.jpg', $resp['corpo']['dados']['ordem'])) === hdJpegBytes(500 + $i);
    }
    hdAfirmar('E14c: 5 linhas, 5 fotos, cada uma com a imagem do seu processar', $coerente);

    // falha de INSERT: o arquivo gravado e removido (sob lock)
    $atp = hdCriarAtendimento($amb, $idTotem, 'PRF1A23');
    $r = $processar($atp, 600, null, ['injecao' => 'dao_insert_falha']);
    hdAfirmar('E14d: INSERT que falha = 500 generico, foto gravada removida e nenhuma linha', $r['http'] === 500 && hdArquivosDaPasta($atp) === [] && hdNotas($pdo, $atp['id']) === [] && !str_contains($r['stdout'], 'SENTINELA') && !str_contains($r['log'], 'SENTINELA'));
    $r = $processar($atp, 601);
    hdAfirmar('E14d: proxima tentativa aloca a ordem 1 normalmente', $r['http'] === 200 && $r['corpo']['dados']['ordem'] === 1);
    $atp = hdCriarAtendimento($amb, $idTotem, 'PRG1A23');
    $r = $processar($atp, 602, null, ['injecao' => 'dao_commit_falha']);
    hdAfirmar('E14e: COMMIT que falha no processar = 500, foto removida (melhor esforco) e sem linha', $r['http'] === 500 && hdArquivosDaPasta($atp) === [] && hdNotas($pdo, $atp['id']) === []);

    // ===============================================================
    hdSecao('E15 timeout de lock = 503 sanitizado (em todas as rotas de escrita)');
    $atl = hdCriarAtendimento($amb, $idTotem, 'LCK1A23');
    $nl1 = hdCriarNota($pdo, $atl, 1, '3001');
    $nl2 = hdCriarNota($pdo, $atl, 2, '3002');
    $estadoAntes = json_encode([hdNotas($pdo, $atl['id']), hdAtendimento($pdo, $atl['id'])['etapa_atual'], hdArquivosDaPasta($atl)]);
    $segura = hdSegurarLock($amb, $atl['id']);
    $inicio = microtime(true);
    $res = hdRodarParalelo($amb, [
        'processar'  => ['rota' => 'nota.processar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atl['id'], 'imagem' => hdJpegDataUrl(700), 'ordem' => 3]],
        'excluir'    => ['rota' => 'nota.excluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atl['id'], 'id_nota' => $nl1]],
        'definir'    => ['rota' => 'nota.definir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atl['id'], 'id_nota' => $nl2, 'numero' => '999', 'origem' => 'MANUAL']],
        'identificar' => ['rota' => 'nota.identificar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atl['id'], 'id_nota' => $nl2, 'cnpjs_candidatos' => [$cnpjA]]],
        'concluir'   => ['rota' => 'atendimento.concluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atl['id']], 'opcoes' => ['sem_terminalizar' => true]],
    ]);
    $duracao = microtime(true) - $inicio;
    hdSoltarLock($segura);
    foreach ($res as $rota => $x) {
        hdAfirmar("E15: {$rota} com a linha do atendimento travada = HTTP 503", $x['http'] === 503);
        hdAfirmar("E15: {$rota}: corpo sanitizado (sem SQL, sem 1205, sem Lock wait timeout)", !preg_match('/1205|Lock wait|SQLSTATE|innodb|tb_atendimento/i', $x['stdout']) && ($x['corpo']['sucesso'] ?? true) === false);
        hdAfirmar("E15: {$rota}: log sem mensagem do driver", !preg_match('/Lock wait|Deadlock|SELECT |UPDATE |tb_atendimento/i', $x['log']));
    }
    hdAfirmar('E15: o timeout de 5 s da sessao vale (resposta entre 4 e 12 s, nao os 50 s padrao)', $duracao >= 4.0 && $duracao < 12.0);
    $estadoDepois = json_encode([hdNotas($pdo, $atl['id']), hdAtendimento($pdo, $atl['id'])['etapa_atual'], hdArquivosDaPasta($atl)]);
    hdAfirmar('E15: nenhuma escrita parcial (banco e disco identicos ao estado anterior)', $estadoAntes === $estadoDepois);
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $atl['id'], 'id_nota' => $nl2, 'numero' => '999', 'origem' => 'MANUAL']);
    hdAfirmar('E15: liberado o lock, a mesma rota responde 200', $r['http'] === 200);

    // ===============================================================
    hdSecao('E16 cancelar move as fotos das notas para quarentena');
    $atc = hdCriarAtendimento($amb, $idTotem, 'CAN1A23');
    $c1 = hdCriarNota($pdo, $atc, 1, '1');
    $c2 = hdCriarNota($pdo, $atc, 4, '2');
    file_put_contents($atc['dir'] . '/cnh_frente.jpg', 'documento CNH');
    touch($atc['dir'] . '/nota_01.jpg', time() - 3 * 86400);
    $bytes1 = file_get_contents($atc['dir'] . '/nota_01.jpg');
    $r = hdRodar($amb, 'atendimento.cancelar', $idTotem, ['id_atendimento' => $atc['id']], $optMock);
    $arqs = hdArquivosDaPasta($atc);
    hdAfirmar('E16: cancelar = 200 e status cancelado', $r['http'] === 200 && hdAtendimento($pdo, $atc['id'])['status'] === 'cancelado');
    hdAfirmar('E16: fotos das notas viraram nota_NN.jpg.<id_nota>.del (nao apagadas)', in_array("nota_01.jpg.{$c1}.del", $arqs, true) && in_array("nota_04.jpg.{$c2}.del", $arqs, true) && !in_array('nota_01.jpg', $arqs, true) && !in_array('nota_04.jpg', $arqs, true));
    hdAfirmar('E16: conteudo da foto preservado na quarentena', file_get_contents($atc['dir'] . "/nota_01.jpg.{$c1}.del") === $bytes1);
    hdAfirmar('E16: CNH e demais documentos NAO sao tocados', in_array('cnh_frente.jpg', $arqs, true) && file_get_contents($atc['dir'] . '/cnh_frente.jpg') === 'documento CNH');
    hdAfirmar('E16: mtime do .del renovado na quarentena (retencao conta a partir do cancelamento, nao da captura)', abs(filemtime($atc['dir'] . "/nota_01.jpg.{$c1}.del") - time()) < 60);
    hdAfirmar('E16: linhas das notas continuam no banco (so a foto foi para quarentena)', count(hdNotas($pdo, $atc['id'])) === 2);
    $r = hdRodar($amb, 'atendimento.cancelar', $idTotem, ['id_atendimento' => $atc['id']]);
    hdAfirmar('E16: cancelar de novo e idempotente (200), sem erro nos .del', $r['http'] === 200 && count(array_filter(hdArquivosDaPasta($atc), static fn($x) => str_ends_with($x, '.del'))) === 2);

    // falha ao quarentenar nao impede o cancelamento
    $atc2 = hdCriarAtendimento($amb, $idTotem, 'CAN2A23');
    hdCriarNota($pdo, $atc2, 1, '1');
    $r = hdRodar($amb, 'atendimento.cancelar', $idTotem, ['id_atendimento' => $atc2['id']], ['injecao' => 'storage_quarentenar_falha']);
    hdAfirmar('E16b: falha no rename da quarentena NAO impede o cancelamento (200, status cancelado, foto permanece)', $r['http'] === 200 && hdAtendimento($pdo, $atc2['id'])['status'] === 'cancelado' && hdArquivosDaPasta($atc2) === ['nota_01.jpg']);
    hdAfirmar('E16b: log fixo agregado (sem caminho nem placa)', str_contains($r['log'], 'cancelar: falha ao mover foto(s)') && !str_contains($r['log'], 'nota_01') && !str_contains($r['log'], 'CAN2A23'));

    // concluido e terminal: 409 e fotos intocadas
    $atc3 = hdCriarAtendimento($amb, $idTotem, 'CAN3A23', 'concluido', 'impressao');
    hdCriarNota($pdo, $atc3, 1, '1');
    $r = hdRodar($amb, 'atendimento.cancelar', $idTotem, ['id_atendimento' => $atc3['id']]);
    hdAfirmar('E16c: cancelar atendimento concluido = 409 e fotos intocadas', $r['http'] === 409 && hdArquivosDaPasta($atc3) === ['nota_01.jpg']);
    $r = hdRodar($amb, 'atendimento.cancelar', $idTotemAlheio, ['id_atendimento' => $atc2['id']]);
    hdAfirmar('E16d: cancelar de totem alheio = 404', $r['http'] === 404);
    // expedicao cancelada (sem notas): nada a quarentenar, sem erro
    $atc4 = hdCriarAtendimento($amb, $idTotem, 'CAN4A23', 'em_andamento', 'placa', 'expedicao');
    $r = hdRodar($amb, 'atendimento.cancelar', $idTotem, ['id_atendimento' => $atc4['id']]);
    hdAfirmar('E16e: cancelar expedicao (sem notas) = 200', $r['http'] === 200);

    // ===============================================================
    hdSecao('E17 doctos[] e anexos com ordens nao contiguas; .del nunca e lido');
    $atd = hdCriarAtendimento($amb, $idTotem, 'DOC1A23');
    $d1 = hdCriarNota($pdo, $atd, 1, '111');
    $d3 = hdCriarNota($pdo, $atd, 3, '333');
    $d5 = hdCriarNota($pdo, $atd, 5, '555');
    $d2 = hdCriarNota($pdo, $atd, 2, '222');
    $r = $excluir($atd, $d2);
    $r = $excluir($atd, hdCriarNota($pdo, $atd, 4, '444'));
    foreach (['cnh_frente.jpg', 'cnh_verso.jpg', 'crlv.jpg'] as $doc) {
        $img = imagecreatetruecolor(200, 120);
        imagefill($img, 0, 0, imagecolorallocate($img, 180, 180, 180));
        imagejpeg($img, $atd['dir'] . '/' . $doc, 85);
        imagedestroy($img);
    }
    copy($atd['dir'] . '/nota_01.jpg', $atd['dir'] . '/nota_02.jpg.999999.del');
    $talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $amb['storage']);
    $atendimentoLinha = hdAtendimento($pdo, $atd['id']);
    $notasLinha = (new AtendimentoNotaDao($pdo))->listarPorAtendimento($atd['id']);
    hdAfirmar('E17: ordens restantes 1, 3 e 5 (nao contiguas)', array_map('intval', array_column($notasLinha, 'ordem')) === [1, 3, 5]);
    $mDoctos = new ReflectionMethod(TalentRn::class, 'montarDoctos');
    $mDoctos->setAccessible(true);
    $doctos = $mDoctos->invoke($talentRn, $atendimentoLinha, $notasLinha);
    hdAfirmar('E17: doctos[] = 3 NOTA_FISCAL em ordem (111, 333, 555)', array_column($doctos, 'nrDocto') === ['111', '333', '555'] && count(array_unique(array_column($doctos, 'tipo'))) === 1);
    $mAnexos = new ReflectionMethod(TalentRn::class, 'montarAnexos');
    $mAnexos->setAccessible(true);
    $anexos = $mAnexos->invoke($talentRn, $atendimentoLinha, $notasLinha);
    $descricoes = array_column($anexos, 'descricao');
    hdAfirmar('E17: anexos = CNH + CRLV + Nota Fiscal 01/03/05 (a excluida e o .del NAO entram)', $descricoes === ['CNH', 'CRLV', 'Nota Fiscal 01', 'Nota Fiscal 03', 'Nota Fiscal 05']);
    hdAfirmar('E17: nenhuma requisicao ao mock durante montagem de doctos/anexos', hdContarRequisicoesMock($mock) === 0);

    // ===============================================================
    hdSecao('E18 zero requisicao ao Talent (mock) em excluir/concluir/cancelar/processar');
    hdAfirmar('E18: contador parte de zero', hdContarRequisicoesMock($mock) === 0);
    $att = hdCriarAtendimento($amb, $idTotem, 'TAL1A23');
    $t1 = $processar($att, 801, null, $optMock)['corpo']['dados']['id_nota'];
    $t2 = $processar($att, 802, null, $optMock)['corpo']['dados']['id_nota'];
    hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $att['id'], 'id_nota' => $t1, 'numero' => '71', 'origem' => 'MANUAL'], $optMock);
    hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $att['id'], 'id_nota' => $t1, 'cnpjs_candidatos' => [$cnpjA]], $optMock);
    $excluir($att, $t2, $optMock);
    $excluir($att, $t2, $optMock);
    hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $att['id']], $optMock + ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'true']]);
    hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $att['id']], $optMock);
    hdRodar($amb, 'atendimento.cancelar', $idTotem, ['id_atendimento' => $att['id']], $optMock);
    hdAfirmar('E18: ZERO requisicoes ao mock do Talent apos processar/definir/identificar/excluir/concluir/cancelar', hdContarRequisicoesMock($mock) === 0);
    $ch = curl_init($mock['url'] . '/Portaria/Checkin');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}']);
    curl_exec($ch);
    curl_close($ch);
    hdAfirmar('E18: sanidade do contador (uma requisicao de prova ao mock local e contada)', hdContarRequisicoesMock($mock) === 1);

    // ===============================================================
    hdSecao('E19 logs sem dado pessoal (sentinelas) em TODAS as execucoes');
    $todos = hdTodosLogs();
    hdAfirmar('E19: algum log foi coletado (varredura nao vazia)', strlen($todos) > 0);
    $sentinelas = ['SENTINELA_', 'SNTL9X9', 'totem_hd_', 'vitima_segredo', 'vitima_fora', $cnpjA, $cnpjB, 'data:image', '/9j/4', 'base64', 'Nota Fiscal', '.jpg'];
    foreach ($sentinelas as $s) {
        hdAfirmar('E19: log nao contem ' . (strlen($s) > 14 ? substr($s, 0, 14) . '...' : $s), !str_contains($todos, $s));
    }
    hdAfirmar('E19: log nao contem nome de pasta (AAAA-MM-DD/PLACA_HHMMSS) nem o diretorio temporario', !preg_match('#\d{4}-\d{2}-\d{2}/[A-Z0-9]{3,10}_\d{6}#', $todos) && !str_contains($todos, sys_get_temp_dir()));
    hdAfirmar('E19: nenhum warning/notice/deprecated/fatal nos logs do PHP', !preg_match('/Warning|Notice|Deprecated|Fatal|Stack trace/i', $todos));
    echo "  (amostra do que e logado: " . count(array_filter(explode("\n", $todos), static fn($l) => trim($l) !== '')) . " linhas)\n";
} finally {
    hdPararMockTalent($mock);
    hdDestruirAmbiente($amb);
}

hdEncerrar('teste_hardening_exclusao');
