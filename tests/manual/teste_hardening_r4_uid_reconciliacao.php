<?php

/**
 * Suite F7 (rodada corretiva /01, 2026-10-01) da demanda
 * hardening-revisao-notas-e-cliente: reconciliacao idempotente entre o uid
 * gerado pelo front e a nota persistida (coluna client_uid,
 * UNIQUE (id_atendimento, client_uid)).
 *
 * Cobre: processar com uid (nota nova e retry idempotente, sem duplicar nem
 * regravar o arquivo, com e sem imagem no retry); validacao do formato do uid
 * (regex ancorada); compatibilidade (uid ausente = comportamento anterior);
 * IDOR (uid de outro atendimento/totem; resposta identica a inexistente);
 * concorrencia real com processos paralelos (mesmo uid = exatamente uma nota);
 * UNIQUE no banco; acao listar (somente leitura, escopo do atendimento, totem
 * autenticado, contrato exato, sem dado sensivel); 409/422 nunca apontam nota
 * invisivel (enumerar e excluir); rota HTTP real
 * (nota.php?acao=listar e processar com token, em porta 8395-8399).
 *
 * Banco `qa_` DESCARTAVEL (prefixo qa_r4be_) + STORAGE_PATH temporario.
 * Sem Talent/VIO/impressao/rede externa.
 *
 * Uso: php tests/manual/teste_hardening_r4_uid_reconciliacao.php
 */

require_once __DIR__ . '/hardening_helpers.php';

use App\Rn\NotaFiscalRn;

$amb = hdCriarAmbiente('r4be_uid');
$pdo = $amb['pdo'];
$servidor = null;

function r4Notas(PDO $pdo, int $idAtendimento): array
{
    return hdNotas($pdo, $idAtendimento);
}

try {
    $idTotem = hdCriarTotem($pdo, 'R4-UID');
    $idTotemAlheio = hdCriarTotem($pdo, 'R4-UID-ALHEIO');

    $pdo->exec('DELETE FROM tb_cliente');
    $cnpjA = hdCnpj(41);
    hdCriarCliente($pdo, 'CROMEX TINTAS LTDA', $cnpjA, 1, 'CROMEX TINTAS');

    $processar = static function (array $at, array $extra, ?int $totem = null, array $opc = []) {
        return hdRodar($GLOBALS['amb_r4'], 'nota.processar', $totem ?? $GLOBALS['totem_r4'], ['id_atendimento' => $at['id']] + $extra, $opc);
    };
    $listar = static fn(array $at, ?int $totem = null, ?int $idForcado = null) => hdRodar(
        $GLOBALS['amb_r4'], 'nota.listar', $totem ?? $GLOBALS['totem_r4'], ['id_atendimento' => $idForcado ?? $at['id']]
    );
    $GLOBALS['amb_r4'] = $amb;
    $GLOBALS['totem_r4'] = $idTotem;
    $uidDe = static fn(string $sufixo): string => 'uid_' . $sufixo . '_' . bin2hex(random_bytes(4));

    // ---------------------------------------------------------------
    hdSecao('U1 schema: coluna client_uid e indice unico');
    $colunas = array_column($pdo->query("SHOW COLUMNS FROM tb_atendimento_nota")->fetchAll(), 'Field');
    hdAfirmar('U1: coluna client_uid existe', in_array('client_uid', $colunas, true));
    $indice = $pdo->query("SHOW INDEX FROM tb_atendimento_nota WHERE Key_name = 'uk_atendimento_client_uid'")->fetchAll();
    hdAfirmar('U1: indice UNIQUE (id_atendimento, client_uid)', count($indice) === 2 && (int) $indice[0]['Non_unique'] === 0 && $indice[0]['Column_name'] === 'id_atendimento' && $indice[1]['Column_name'] === 'client_uid');

    // ---------------------------------------------------------------
    hdSecao('U2 processar com uid: nota nova');
    $at = hdCriarAtendimento($amb, $idTotem);
    $uid1 = $uidDe('a');
    $r = $processar($at, ['imagem' => hdJpegDataUrl(1), 'uid' => $uid1]);
    hdAfirmar('U2: HTTP 200', $r['http'] === 200);
    $d = $r['corpo']['dados'] ?? [];
    hdAfirmar('U2: corpo exato {cliente_identificado,cliente,id_nota,ordem,uid,reaproveitada}', array_keys($d) === ['cliente_identificado', 'cliente', 'id_nota', 'ordem', 'uid', 'reaproveitada']);
    hdAfirmar('U2: uid devolvido igual, ordem 1, reaproveitada false, cliente nulo', ($d['uid'] ?? null) === $uid1 && ($d['ordem'] ?? null) === 1 && ($d['reaproveitada'] ?? null) === false && $d['cliente'] === null && $d['cliente_identificado'] === false);
    $notas = r4Notas($pdo, $at['id']);
    hdAfirmar('U2: 1 nota persistida com client_uid, arquivo nota_01.jpg e id_nota igual ao devolvido', count($notas) === 1 && $notas[0]['client_uid'] === $uid1 && $notas[0]['arquivo'] === 'nota_01.jpg' && (int) $notas[0]['id_nota'] === $d['id_nota']);
    hdAfirmar('U2: arquivo gravado em disco', is_file($at['dir'] . DIRECTORY_SEPARATOR . 'nota_01.jpg'));
    $idNota1 = (int) $d['id_nota'];

    // ---------------------------------------------------------------
    hdSecao('U3 retry com o mesmo uid: devolve a MESMA nota, sem duplicar nem regravar');
    $arquivo1 = $at['dir'] . DIRECTORY_SEPARATOR . 'nota_01.jpg';
    $bytesAntes = file_get_contents($arquivo1);
    $passado = time() - 7200;
    touch($arquivo1, $passado);
    clearstatcache();
    $r = $processar($at, ['imagem' => hdJpegDataUrl(2), 'uid' => $uid1]); // imagem DIFERENTE
    $d2 = $r['corpo']['dados'] ?? [];
    hdAfirmar('U3: HTTP 200 com o mesmo id_nota e ordem', $r['http'] === 200 && ($d2['id_nota'] ?? null) === $idNota1 && ($d2['ordem'] ?? null) === 1);
    hdAfirmar('U3: reaproveitada true e uid igual', ($d2['reaproveitada'] ?? null) === true && ($d2['uid'] ?? null) === $uid1);
    hdAfirmar('U3: ainda UMA nota no banco', count(r4Notas($pdo, $at['id'])) === 1);
    clearstatcache();
    hdAfirmar('U3: arquivo NAO regravado (bytes e mtime identicos, mesmo com imagem diferente)', file_get_contents($arquivo1) === $bytesAntes && filemtime($arquivo1) === $passado);
    hdAfirmar('U3: nenhum arquivo extra na pasta', hdArquivosDaPasta($at) === ['nota_01.jpg']);

    $r = $processar($at, ['uid' => $uid1]); // SEM imagem
    hdAfirmar('U3: retry SEM imagem tambem devolve a mesma nota (recuperacao sem reenviar a imagem)', $r['http'] === 200 && ($r['corpo']['dados']['id_nota'] ?? null) === $idNota1 && ($r['corpo']['dados']['reaproveitada'] ?? null) === true);
    $r = $processar($at, ['uid' => $uidDe('inexistente')]);
    hdAfirmar('U3: uid DESCONHECIDO sem imagem = 400 Dados incompletos, nenhuma nota criada', $r['http'] === 400 && ($r['corpo']['erro'] ?? '') === 'Dados incompletos' && count(r4Notas($pdo, $at['id'])) === 1);
    $r = $processar($at, []);
    hdAfirmar('U3: sem uid e sem imagem = 400 (comportamento anterior)', $r['http'] === 400);

    $r = $processar($at, ['imagem' => hdJpegDataUrl(3), 'uid' => $uid1, 'ordem' => 4]);
    hdAfirmar('U3: retry com ordem divergente informada ainda devolve a nota original (ordem 1)', $r['http'] === 200 && ($r['corpo']['dados']['ordem'] ?? null) === 1);

    // ---------------------------------------------------------------
    hdSecao('U4 validacao do formato do uid (regex ancorada, tamanho limitado)');
    $at4 = hdCriarAtendimento($amb, $idTotem, 'UID4A23');
    $invalidos = [
        'curto 7'          => 'abcdefg',
        'longo 65'         => str_repeat('a', 65),
        'newline final'    => "abcdefgh\n",
        'CR final'         => "abcdefgh\r",
        'NUL no meio'      => "abcd\0efgh",
        'NUL final'        => "abcdefgh\0",
        'espaco'           => 'abcd efgh',
        'acento'           => 'abcdefgh' . "\xc3\xa7",
        'traversal'        => '../../etc/passwd',
        'barra'            => 'abcd/efgh',
        'ponto'            => 'abcd.efgh',
        'sufixo invisivel' => 'abcdefgh' . "\xe2\x80\x8b",
        'aspas'            => "abcd'efgh",
    ];
    foreach ($invalidos as $rotulo => $valor) {
        $r = $processar($at4, ['imagem' => hdJpegDataUrl(4), 'uid' => $valor]);
        hdAfirmar("U4: uid invalido ({$rotulo}) = 400 'Identificador da nota invalido'", $r['http'] === 400 && ($r['corpo']['erro'] ?? '') === 'Identificador da nota invalido');
    }
    foreach ([['array' => ['x']], ['int' => 12345678], ['bool' => true], ['objeto' => ['a' => 'b']]] as $par) {
        $rotulo = array_key_first($par);
        $r = $processar($at4, ['imagem' => hdJpegDataUrl(4), 'uid' => $par[$rotulo]]);
        hdAfirmar("U4: uid de tipo nao string ({$rotulo}) = 400", $r['http'] === 400);
    }
    hdAfirmar('U4: nenhum uid invalido criou nota nem arquivo', count(r4Notas($pdo, $at4['id'])) === 0 && hdArquivosDaPasta($at4) === []);
    $validos = [str_repeat('a', 8), str_repeat('Z', 64), 'a1_B2-c3d4', 'n-1_x9Y8z7W6'];
    $ordemOk = 0;
    foreach ($validos as $i => $valor) {
        $r = $processar($at4, ['imagem' => hdJpegDataUrl(10 + $i), 'uid' => $valor]);
        if ($r['http'] === 200 && ($r['corpo']['dados']['uid'] ?? null) === $valor) {
            $ordemOk++;
        }
    }
    hdAfirmar('U4: uids validos nos limites (8 e 64 caracteres, _ e -) aceitos', $ordemOk === 4);
    $r = $processar($at4, ['imagem' => hdJpegDataUrl(20), 'uid' => '']);
    hdAfirmar('U4: uid "" e tratado como ausente (comportamento anterior, 5a nota sem uid)', $r['http'] === 200 && !array_key_exists('uid', $r['corpo']['dados'] ?? []));

    // ---------------------------------------------------------------
    hdSecao('U5 compatibilidade: uid ausente = comportamento atual');
    $at5 = hdCriarAtendimento($amb, $idTotem, 'UID5A23');
    $r = $processar($at5, ['imagem' => hdJpegDataUrl(30)]);
    hdAfirmar('U5: corpo identico ao anterior {cliente_identificado,cliente,id_nota,ordem} (sem uid nem reaproveitada)', $r['http'] === 200 && array_keys($r['corpo']['dados'] ?? []) === ['cliente_identificado', 'cliente', 'id_nota', 'ordem']);
    $r2 = $processar($at5, ['imagem' => hdJpegDataUrl(31)]);
    $r3 = $processar($at5, ['imagem' => hdJpegDataUrl(32), 'ordem' => 4]);
    hdAfirmar('U5: varias notas sem uid coexistem (NULL repetido no UNIQUE) e ordem opcional/informada funcionam', $r2['http'] === 200 && $r3['http'] === 200 && array_map('intval', array_column(r4Notas($pdo, $at5['id']), 'ordem')) === [1, 2, 4] && array_filter(array_column(r4Notas($pdo, $at5['id']), 'client_uid')) === []);
    $r4 = $processar($at5, ['imagem' => hdJpegDataUrl(33), 'ordem' => 4]);
    hdAfirmar('U5: ordem ocupada continua 400', $r4['http'] === 400);

    // ---------------------------------------------------------------
    hdSecao('U6 IDOR: uid de outro atendimento/totem');
    $atX = hdCriarAtendimento($amb, $idTotem, 'UID6A23');
    $atY = hdCriarAtendimento($amb, $idTotem, 'UID6B23');
    $uidX = $uidDe('x');
    $rx = $processar($atX, ['imagem' => hdJpegDataUrl(40), 'uid' => $uidX]);
    $idX = $rx['corpo']['dados']['id_nota'];
    // o MESMO uid usado em OUTRO atendimento do mesmo totem cria uma nota PROPRIA (nunca devolve a de X)
    $ry = $processar($atY, ['imagem' => hdJpegDataUrl(41), 'uid' => $uidX]);
    $dy = $ry['corpo']['dados'] ?? [];
    hdAfirmar('U6: uid de outro atendimento NAO devolve a nota alheia (nota nova no atendimento Y, reaproveitada false)', $ry['http'] === 200 && ($dy['reaproveitada'] ?? null) === false && ($dy['id_nota'] ?? null) !== $idX);
    $xb = r4Notas($pdo, $atX['id']);
    $yb = r4Notas($pdo, $atY['id']);
    hdAfirmar('U6: cada atendimento tem exatamente a sua nota; a de X intocada', count($xb) === 1 && count($yb) === 1 && (int) $xb[0]['id_nota'] === $idX && (int) $yb[0]['id_nota'] === $dy['id_nota']);
    // resposta de uid desconhecido e a de uid de outro atendimento tem o mesmo formato
    $rDesc = $processar(hdCriarAtendimento($amb, $idTotem, 'UID6C23'), ['imagem' => hdJpegDataUrl(42), 'uid' => $uidDe('novo')]);
    hdAfirmar('U6: formato da resposta de uid alheio = formato de uid desconhecido', array_keys($dy) === array_keys($rDesc['corpo']['dados']));

    // totem alheio tentando usar o uid/atendimento de X
    $ra = $processar($atX, ['imagem' => hdJpegDataUrl(43), 'uid' => $uidX], $idTotemAlheio);
    $rb = $processar(['id' => 99999991], ['imagem' => hdJpegDataUrl(43), 'uid' => $uidX], $idTotemAlheio);
    hdAfirmar('U6: totem alheio + uid de X = 404, resposta IDENTICA a atendimento inexistente', $ra['http'] === 404 && $rb['http'] === 404 && $ra['stdout'] === $rb['stdout'] && !str_contains($ra['stdout'], (string) $idX));
    $rc = $processar($atX, ['uid' => $uidX], $idTotemAlheio);
    hdAfirmar('U6: totem alheio sem imagem (so recuperar) = 404, nada vaza', $rc['http'] === 404 && !str_contains($rc['stdout'], (string) $idX) && !str_contains($rc['stdout'], $uidX));
    hdAfirmar('U6: nenhuma nota nova criada por totem alheio', count(r4Notas($pdo, $atX['id'])) === 1);

    // ---------------------------------------------------------------
    hdSecao('U7 UNIQUE no banco (id_atendimento, client_uid)');
    $atU = hdCriarAtendimento($amb, $idTotem, 'UID7A23');
    $ins = $pdo->prepare('INSERT INTO tb_atendimento_nota (id_atendimento, ordem, client_uid, arquivo) VALUES (:a, :o, :u, :f)');
    $ins->execute(['a' => $atU['id'], 'o' => 1, 'u' => 'uniq_aaaa_1111', 'f' => 'nota_01.jpg']);
    $dup = false;
    try {
        $ins->execute(['a' => $atU['id'], 'o' => 2, 'u' => 'uniq_aaaa_1111', 'f' => 'nota_02.jpg']);
    } catch (\PDOException $e) {
        $dup = $e->getCode() === '23000';
    }
    hdAfirmar('U7: mesmo uid no mesmo atendimento viola a UNIQUE (23000)', $dup);
    $atU2 = hdCriarAtendimento($amb, $idTotem, 'UID7B23');
    $ins->execute(['a' => $atU2['id'], 'o' => 1, 'u' => 'uniq_aaaa_1111', 'f' => 'nota_01.jpg']);
    hdAfirmar('U7: mesmo uid em OUTRO atendimento e permitido', (int) $pdo->query("SELECT COUNT(*) FROM tb_atendimento_nota WHERE client_uid = 'uniq_aaaa_1111'")->fetchColumn() === 2);

    // ---------------------------------------------------------------
    hdSecao('U8 concorrencia real: mesmo uid em varios processos = exatamente uma nota');
    $violacoes = 0;
    $resumoReaproveitada = [];
    for ($rodada = 0; $rodada < 4; $rodada++) {
        $atC = hdCriarAtendimento($amb, $idTotem, 'UC' . $rodada . 'A234');
        $uidC = $uidDe('conc' . $rodada);
        $chamadas = [];
        for ($i = 0; $i < 6; $i++) {
            $chamadas[$i] = ['rota' => 'nota.processar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atC['id'], 'imagem' => hdJpegDataUrl(100 + $i), 'uid' => $uidC]];
        }
        $res = hdRodarParalelo($amb, $chamadas);
        $ids = [];
        $novas = 0;
        foreach ($res as $x) {
            if ($x['http'] !== 200) {
                $violacoes++;
                continue;
            }
            $ids[] = $x['corpo']['dados']['id_nota'];
            $novas += ($x['corpo']['dados']['reaproveitada'] ?? true) === false ? 1 : 0;
        }
        $notasC = r4Notas($pdo, $atC['id']);
        if (count($notasC) !== 1 || count(array_unique($ids)) !== 1 || $novas !== 1 || count(hdArquivosDaPasta($atC)) !== 1) {
            $violacoes++;
        }
        $resumoReaproveitada[] = $novas;
    }
    hdAfirmar('U8: 4 rodadas x 6 processos paralelos com o mesmo uid: sempre 1 nota, mesmo id_nota, 1 criacao, 1 arquivo', $violacoes === 0);

    $atD = hdCriarAtendimento($amb, $idTotem, 'UCDA234');
    $chamadas = [];
    for ($i = 0; $i < 3; $i++) {
        $chamadas[$i] = ['rota' => 'nota.processar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atD['id'], 'imagem' => hdJpegDataUrl(200 + $i), 'uid' => $uidDe('dif' . $i)]];
    }
    $res = hdRodarParalelo($amb, $chamadas);
    $ordens = array_column(r4Notas($pdo, $atD['id']), 'ordem');
    hdAfirmar('U8: 3 uids DIFERENTES em paralelo = 3 notas com ordens distintas (1,2,3) e 3 arquivos', count(array_filter($res, static fn($x) => $x['http'] === 200)) === 3 && array_map('intval', $ordens) === [1, 2, 3] && count(hdArquivosDaPasta($atD)) === 3);

    // limite de 5: retry de uid existente passa; uid novo e barrado
    $atL = hdCriarAtendimento($amb, $idTotem, 'UCLA234');
    $uidsL = [];
    for ($i = 0; $i < 5; $i++) {
        $uidsL[$i] = $uidDe('lim' . $i);
        $processar($atL, ['imagem' => hdJpegDataUrl(300 + $i), 'uid' => $uidsL[$i]]);
    }
    $r = $processar($atL, ['uid' => $uidsL[2]]);
    hdAfirmar('U8: com 5 notas, retry de uid EXISTENTE = 200 (recupera a nota 3)', $r['http'] === 200 && ($r['corpo']['dados']['ordem'] ?? null) === 3);
    $r = $processar($atL, ['imagem' => hdJpegDataUrl(310), 'uid' => $uidDe('lim5')]);
    hdAfirmar('U8: com 5 notas, uid NOVO = 400 limite de 5', $r['http'] === 400 && count(r4Notas($pdo, $atL['id'])) === 5);

    // retry apos exclusao: uid liberado (a linha foi removida) cria nota nova
    $exc = hdRodar($amb, 'nota.excluir', $idTotem, ['id_atendimento' => $atL['id'], 'id_nota' => $processar($atL, ['uid' => $uidsL[0]])['corpo']['dados']['id_nota']]);
    $r = $processar($atL, ['imagem' => hdJpegDataUrl(311), 'uid' => $uidsL[0]]);
    hdAfirmar('U8: depois de excluir a nota, o MESMO uid cria nota nova (a recuperacao nunca ressuscita nota excluida)', $exc['http'] === 200 && $r['http'] === 200 && ($r['corpo']['dados']['reaproveitada'] ?? null) === false);

    // ---------------------------------------------------------------
    hdSecao('U9 listar: reconciliacao somente leitura');
    $atR = hdCriarAtendimento($amb, $idTotem, 'LST1A23');
    $uR1 = $uidDe('l1');
    $uR2 = $uidDe('l2');
    $processar($atR, ['imagem' => hdJpegDataUrl(400), 'uid' => $uR1]);
    $pr2 = $processar($atR, ['imagem' => hdJpegDataUrl(401), 'uid' => $uR2]);   // "resposta perdida": o front nunca le este corpo
    $legacy = $processar($atR, ['imagem' => hdJpegDataUrl(402)]);               // nota legada, sem uid
    $idR2 = $pr2['corpo']['dados']['id_nota'];
    $idLegacy = $legacy['corpo']['dados']['id_nota'];
    hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $atR['id'], 'id_nota' => $idR2, 'numero' => '4002', 'origem' => 'MANUAL']);
    $antes = json_encode([hdNotas($pdo, $atR['id']), hdAtendimento($pdo, $atR['id']), hdArquivosDaPasta($atR)]);
    $x = $listar($atR);
    $dados = $x['corpo']['dados'] ?? [];
    hdAfirmar('U9: HTTP 200 e corpo {sucesso,dados{total_notas,notas}}', $x['http'] === 200 && ($x['corpo']['sucesso'] ?? null) === true && array_keys($dados) === ['total_notas', 'notas'] && $dados['total_notas'] === 3);
    $notasL = $dados['notas'] ?? [];
    hdAfirmar('U9: cada nota tem exatamente {id_nota,ordem,uid,numero_definido}', count($notasL) === 3 && array_reduce($notasL, static fn($c, $n) => $c && array_keys($n) === ['id_nota', 'ordem', 'uid', 'numero_definido'], true));
    hdAfirmar('U9: a nota cuja resposta se perdeu e recuperada por uid com id_nota e ordem corretos', ($notasL[1]['uid'] ?? null) === $uR2 && ($notasL[1]['id_nota'] ?? null) === $idR2 && ($notasL[1]['ordem'] ?? null) === 2);
    hdAfirmar('U9: numero_definido true so onde o numero e valido; nota legada com uid null', ($notasL[0]['numero_definido'] ?? null) === false && ($notasL[1]['numero_definido'] ?? null) === true && array_key_exists('uid', $notasL[2] ?? []) && $notasL[2]['uid'] === null && ($notasL[2]['id_nota'] ?? null) === $idLegacy);
    hdAfirmar('U9: tipos (id_nota/ordem inteiros, uid string|null, numero_definido bool)', array_reduce($notasL, static fn($c, $n) => $c && is_int($n['id_nota']) && is_int($n['ordem']) && (is_string($n['uid']) || $n['uid'] === null) && is_bool($n['numero_definido']), true));
    hdAfirmar('U9: sem arquivo, caminho, imagem, chave, CNPJ, numero ou status interno', !preg_match('/arquivo|nota_0|\.jpg|base64|chave|cnpj|status_ocr|numero_nota|"4002"|' . preg_quote(substr($atR['pasta'], 11), '/') . '/i', $x['stdout']));
    hdAfirmar('U9: SOMENTE LEITURA: banco e disco identicos (notas, atendimento incl. atualizado_em, arquivos)', $antes === json_encode([hdNotas($pdo, $atR['id']), hdAtendimento($pdo, $atR['id']), hdArquivosDaPasta($atR)]));

    // escopo
    $atOutro = hdCriarAtendimento($amb, $idTotem, 'LST2A23');
    $processar($atOutro, ['imagem' => hdJpegDataUrl(410), 'uid' => $uidDe('so_de_outro')]);
    $x2 = $listar($atR);
    hdAfirmar('U9: escopo: nunca lista nota de outro atendimento', count($x2['corpo']['dados']['notas']) === 3 && !str_contains($x2['stdout'], 'so_de_outro'));
    $xa = $listar($atR, $idTotemAlheio);
    $xb = $listar($atR, null, 99999992);
    hdAfirmar('U9: totem alheio = 404 IDENTICO ao atendimento inexistente', $xa['http'] === 404 && $xb['http'] === 404 && $xa['stdout'] === $xb['stdout'] && !str_contains($xa['stdout'], 'uid_'));
    $atExp = hdCriarAtendimento($amb, $idTotem, 'LST3A23', 'em_andamento', 'placa', 'expedicao');
    hdAfirmar('U9: atendimento de expedicao = 404', $listar($atExp)['http'] === 404);
    foreach (['cancelado', 'concluido', 'bloqueado'] as $st) {
        $atS = hdCriarAtendimento($amb, $idTotem, 'LST4A23', $st);
        hdAfirmar("U9: atendimento {$st} = 400", $listar($atS)['http'] === 400);
    }
    $xz = hdRodar($amb, 'nota.listar', $idTotem, []);
    hdAfirmar('U9: sem id_atendimento = 400', $xz['http'] === 400);
    $atVazio = hdCriarAtendimento($amb, $idTotem, 'LST5A23');
    $xv = $listar($atVazio);
    hdAfirmar('U9: atendimento sem notas = 200 com total_notas 0 e lista vazia', $xv['http'] === 200 && $xv['corpo']['dados'] === ['total_notas' => 0, 'notas' => []]);

    // ---------------------------------------------------------------
    hdSecao('U10 o 422 e o 409 nunca apontam nota invisivel: enumerar e corrigir/excluir');
    $atF = hdCriarAtendimento($amb, $idTotem, 'LST6A23');
    $uF1 = $uidDe('f1');
    $uF2 = $uidDe('f2');
    $f1 = $processar($atF, ['imagem' => hdJpegDataUrl(500), 'uid' => $uF1])['corpo']['dados']['id_nota'];
    $processar($atF, ['imagem' => hdJpegDataUrl(501), 'uid' => $uF2]); // resposta "perdida"
    hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $atF['id'], 'id_nota' => $f1, 'numero' => '5001', 'origem' => 'OCR']);
    $pdo->prepare("UPDATE tb_atendimento_nota SET status_ocr = 'NAO_IDENTIFICADA' WHERE id_atendimento = :a")->execute(['a' => $atF['id']]);
    $c = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atF['id']], ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'true'], 'sem_terminalizar' => true]);
    hdAfirmar('U10: 422 aponta a ordem 2 (a da nota com resposta perdida)', $c['http'] === 422 && ($c['corpo']['dados']['ordens_pendentes'] ?? null) === [2]);
    $lst = $listar($atF)['corpo']['dados']['notas'];
    $invisivel = array_values(array_filter($lst, static fn($n) => $n['ordem'] === 2));
    hdAfirmar('U10: a listagem enumera a ordem 2 com id_nota e uid (nao esta invisivel)', count($invisivel) === 1 && $invisivel[0]['uid'] === $uF2 && $invisivel[0]['numero_definido'] === false);
    $ex = hdRodar($amb, 'nota.excluir', $idTotem, ['id_atendimento' => $atF['id'], 'id_nota' => $invisivel[0]['id_nota']]);
    hdAfirmar('U10: e possivel EXCLUIR a nota recuperada pela listagem', $ex['http'] === 200 && ($ex['corpo']['dados']['excluida'] ?? null) === true);
    $c = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atF['id']], ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'true'], 'sem_terminalizar' => true]);
    hdAfirmar('U10: depois de excluir a nota invisivel, a conclusao passa (200)', $c['http'] === 200);

    $atG = hdCriarAtendimento($amb, $idTotem, 'LST7A23');
    $uG = $uidDe('g1');
    $processar($atG, ['imagem' => hdJpegDataUrl(510), 'uid' => $uG]); // fica PENDENTE (nunca identificou)
    $c = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atG['id']], ['sem_terminalizar' => true]);
    $ordens409 = $c['corpo']['dados']['ordens_em_processamento'] ?? [];
    $lstG = $listar($atG)['corpo']['dados']['notas'];
    hdAfirmar('U10: 409 aponta a ordem 1 e a listagem mostra essa mesma ordem (id_nota e uid presentes)', $c['http'] === 409 && $ordens409 === [1] && $lstG[0]['ordem'] === 1 && $lstG[0]['uid'] === $uG);

    // ---------------------------------------------------------------
    hdSecao('U11 rota HTTP real (nota.php com token do totem), porta 8395-8399');
    $porta = null;
    foreach ([8395, 8396, 8397, 8398, 8399] as $candidata) {
        $s = @fsockopen('127.0.0.1', $candidata, $errno, $errstr, 0.3);
        if ($s) {
            fclose($s);
            continue;
        }
        $porta = $candidata;
        break;
    }
    hdAfirmar('U11: ha porta livre entre 8395 e 8399 (nao encerra processo de terceiros)', $porta !== null);
    if ($porta !== null) {
        @mkdir($amb['dir_tmp'], 0777, true);
        $router = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'router_http.php';
        file_put_contents($router, "<?php\n" . qaDbTrechoPonteEnvSubprocesso() . "\nrequire " . var_export(hdRaizProjeto() . '/public/api/nota.php', true) . ";\n");
        $ambiente = array_merge(getenv(), ['DB_NAME' => $amb['banco'], 'STORAGE_PATH' => $amb['storage']]);
        $servidor = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$porta}", $router],
            [1 => ['file', $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'http_out.txt', 'w'], 2 => ['file', $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'http_err.txt', 'w']],
            $pipes,
            null,
            $ambiente
        );
        $subiu = false;
        for ($i = 0; $i < 60; $i++) {
            $s = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.2);
            if ($s) {
                fclose($s);
                $subiu = true;
                break;
            }
            usleep(100000);
        }
        hdAfirmar('U11: servidor HTTP de teste no ar', $subiu);

        $token = (string) $pdo->query("SELECT token_api FROM tb_totem WHERE id_totem = " . (int) $idTotem)->fetchColumn();
        $tokenAlheio = (string) $pdo->query("SELECT token_api FROM tb_totem WHERE id_totem = " . (int) $idTotemAlheio)->fetchColumn();
        $http = static function (string $metodo, string $url, ?string $tokenUsado, ?array $corpo = null): array {
            $ch = curl_init($url);
            $cabecalhos = ['Content-Type: application/json'];
            if ($tokenUsado !== null) {
                $cabecalhos[] = 'Authorization: Bearer ' . $tokenUsado;
            }
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $cabecalhos, CURLOPT_CUSTOMREQUEST => $metodo]);
            if ($corpo !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
            }
            $resp = (string) curl_exec($ch);
            $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return ['http' => $codigo, 'corpo' => json_decode($resp, true), 'bruto' => $resp];
        };
        $base = "http://127.0.0.1:{$porta}/api/nota.php";

        $atH = hdCriarAtendimento($amb, $idTotem, 'LST8A23');
        $r = $http('GET', "{$base}?acao=listar&id_atendimento={$atH['id']}", null);
        hdAfirmar('U11: listar SEM token = 401 (Util\Auth em toda rota)', $r['http'] === 401);
        $r = $http('GET', "{$base}?acao=listar&id_atendimento={$atH['id']}", 'token_invalido_xyz');
        hdAfirmar('U11: listar com token invalido = 401', $r['http'] === 401);
        $r = $http('POST', "{$base}?acao=processar", null, ['id_atendimento' => $atH['id'], 'imagem' => hdJpegDataUrl(600), 'uid' => 'http_uid_0001']);
        hdAfirmar('U11: processar SEM token = 401 e nada criado', $r['http'] === 401 && count(r4Notas($pdo, $atH['id'])) === 0);
        $r = $http('POST', "{$base}?acao=processar", $token, ['id_atendimento' => $atH['id'], 'imagem' => hdJpegDataUrl(600), 'uid' => 'http_uid_0001']);
        hdAfirmar('U11: processar com token e uid = 200, reaproveitada false', $r['http'] === 200 && ($r['corpo']['dados']['reaproveitada'] ?? null) === false);
        $idHttp = $r['corpo']['dados']['id_nota'] ?? 0;
        $r = $http('POST', "{$base}?acao=processar", $token, ['id_atendimento' => $atH['id'], 'uid' => 'http_uid_0001']);
        hdAfirmar('U11: retry HTTP do mesmo uid sem imagem = mesma nota, reaproveitada true', $r['http'] === 200 && ($r['corpo']['dados']['id_nota'] ?? null) === $idHttp && ($r['corpo']['dados']['reaproveitada'] ?? null) === true);
        $r = $http('GET', "{$base}?acao=listar&id_atendimento={$atH['id']}", $token);
        hdAfirmar('U11: listar GET com token = 200 e a nota', $r['http'] === 200 && ($r['corpo']['dados']['notas'][0]['uid'] ?? null) === 'http_uid_0001' && ($r['corpo']['dados']['notas'][0]['id_nota'] ?? null) === $idHttp);
        $r = $http('POST', "{$base}?acao=listar", $token, ['id_atendimento' => $atH['id']]);
        hdAfirmar('U11: listar POST (corpo JSON) com token = 200 e total 1', $r['http'] === 200 && ($r['corpo']['dados']['total_notas'] ?? null) === 1);
        $r = $http('GET', "{$base}?acao=listar&id_atendimento={$atH['id']}", $tokenAlheio);
        $rInex = $http('GET', "{$base}?acao=listar&id_atendimento=99999993", $tokenAlheio);
        hdAfirmar('U11: token de totem alheio = 404 identico ao atendimento inexistente', $r['http'] === 404 && $rInex['http'] === 404 && $r['bruto'] === $rInex['bruto']);
        $r = $http('GET', "{$base}?acao=inexistente", $token);
        hdAfirmar('U11: acao invalida continua 404', $r['http'] === 404);
        $errHttp = (string) @file_get_contents($amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'http_err.txt');
        hdAfirmar('U11: log do servidor sem token, imagem, uid, CNPJ ou caminho', !str_contains($errHttp, $token) && !str_contains($errHttp, 'http_uid_0001') && !str_contains($errHttp, 'base64') && !str_contains($errHttp, $amb['storage']));
    }

    // ---------------------------------------------------------------
    hdSecao('U12 logs dos subprocessos sem dado pessoal');
    $todos = hdTodosLogs();
    foreach ([$cnpjA, 'data:image', 'base64', '.jpg', 'SENTINELA_', 'uid_a_', 'so_de_outro'] as $s) {
        hdAfirmar('U12: log nao contem ' . (strlen($s) > 14 ? substr($s, 0, 14) . '...' : $s), !str_contains($todos, $s));
    }
    hdAfirmar('U12: nenhum warning/notice/fatal nos logs do PHP', !preg_match('/Warning|Notice|Deprecated|Fatal|Stack trace/i', $todos));
} finally {
    if ($servidor !== null) {
        proc_terminate($servidor);
        proc_close($servidor);
    }
    hdDestruirAmbiente($amb);
}

hdEncerrar('teste_hardening_r4_uid_reconciliacao');
