<?php

/**
 * Suite de concluir-digitalizacao (demanda hardening-revisao-notas-e-cliente,
 * 2026-09-30, fase 2 backend, decisao D5): validacao obrigatoria de
 * numero_nota com HTTP 422 atras da FLAG CONCLUIR_EXIGE_NUMERO_NOTA
 * (ausente/false = comportamento anterior; so o literal "true" liga).
 * Cobre a matriz R: todas numeradas; pendentes = 422 com corpo EXATO e etapa
 * e cliente inalterados; numero legado invalido; clique duplo; idempotencia;
 * processar tardio = 400; flag desligada = comportamento atual; alem de
 * concluir com 0 notas, posse/tipo/status e o ramo idempotente por etapa.
 *
 * Banco `qa_` DESCARTAVEL + STORAGE_PATH temporario (hardening_helpers.php).
 *
 * Uso: php tests/manual/teste_hardening_concluir_422.php
 */

require_once __DIR__ . '/hardening_helpers.php';

use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Rn\NotaFiscalRn;

$amb = hdCriarAmbiente('hd_concluir');
$pdo = $amb['pdo'];

try {
    $idTotem = hdCriarTotem($pdo, 'HD-CON');
    $idTotemAlheio = hdCriarTotem($pdo, 'HD-CON-ALHEIO');
    $rn = new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));

    $pdo->exec('DELETE FROM tb_cliente');
    $cnpjA = hdCnpj(11);
    hdCriarCliente($pdo, 'CROMEX TINTAS LTDA', $cnpjA, 1, 'CROMEX TINTAS');

    $ligada = ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'true']];
    $concluir = static fn(array $at, array $opc = []) => hdRodar($GLOBALS['amb_hd'], 'atendimento.concluir', $GLOBALS['totem_hd'], ['id_atendimento' => $at['id']], $opc);
    $GLOBALS['amb_hd'] = $amb;
    $GLOBALS['totem_hd'] = $idTotem;

    $corpoPendentes = static function (array $ordens, int $total): array {
        return [
            'sucesso' => false,
            'erro'    => 'Existem notas fiscais sem numero definido (NOTAS_SEM_NUMERO)',
            'codigo'  => 'NOTAS_SEM_NUMERO',
            'dados'   => ['ordens_pendentes' => $ordens, 'total_notas' => $total, 'total_pendentes' => count($ordens)],
        ];
    };

    // ---------------------------------------------------------------
    hdSecao('R1 flag ligada, todas numeradas = 200');
    $at = hdCriarAtendimento($amb, $idTotem);
    $n1 = hdCriarNota($pdo, $at, 1, '100');
    $n2 = hdCriarNota($pdo, $at, 2, '200');
    $n3 = hdCriarNota($pdo, $at, 3, '123456789');
    $r = $concluir($at, $ligada);
    hdAfirmar('R1: 200 com o corpo de sucesso aditivo (proxima_tela, etapa, cliente_estado, cliente_motivo)', $r['http'] === 200 && $r['corpo']['dados'] === ['proxima_tela' => 'rec_cliente', 'etapa' => 'cliente', 'cliente_estado' => 'NAO_IDENTIFICADO', 'cliente_motivo' => null]);
    hdAfirmar('R1: etapa avancou', hdAtendimento($pdo, $at['id'])['etapa_atual'] === 'cliente');

    // ---------------------------------------------------------------
    hdSecao('R2 pendentes = 422 com corpo exato; etapa e cliente inalterados; ROLLBACK');
    $at = hdCriarAtendimento($amb, $idTotem);
    $n1 = hdCriarNota($pdo, $at, 1, null, 'IDENTIFICADA', $cnpjA);
    $n2 = hdCriarNota($pdo, $at, 2, '2002');
    $n3 = hdCriarNota($pdo, $at, 3, null);
    $r = $concluir($at, $ligada);
    hdAfirmar('R2: HTTP 422', $r['http'] === 422);
    hdAfirmar('R2: corpo EXATO {sucesso,erro,codigo,dados{ordens_pendentes,total_notas,total_pendentes}}', $r['corpo'] === $corpoPendentes([1, 3], 3));
    hdAfirmar('R2: ordens_pendentes sao inteiros de 1 a 5', array_reduce($r['corpo']['dados']['ordens_pendentes'], static fn($c, $o) => $c && is_int($o) && $o >= 1 && $o <= 5, true));
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('R2: etapa_atual inalterada (digitalizacao_notas)', $reg['etapa_atual'] === 'digitalizacao_notas');
    hdAfirmar('R2: cliente_nome/cliente_cnpj inalterados (nada gravado mesmo com nota IDENTIFICADA)', $reg['cliente_cnpj'] === null && $reg['cliente_nome'] === null);
    hdAfirmar('R2: corpo sem id_nota, numero, CNPJ, arquivo, pasta ou imagem', !preg_match('/id_nota|numero_nota|' . $cnpjA . '|nota_0|\.jpg|base64|' . preg_quote(substr($at['pasta'], 11), '/') . '/', $r['stdout']));
    hdAfirmar('R2: nenhuma nota foi alterada', array_column(hdNotas($pdo, $at['id']), 'numero_nota') === [null, '2002', null]);

    // corrige e tenta de novo
    hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $n1, 'numero' => '1001', 'origem' => 'MANUAL']);
    $r = $concluir($at, $ligada);
    hdAfirmar('R2b: 1 pendente restante -> ordens_pendentes [3]', $r['http'] === 422 && $r['corpo'] === $corpoPendentes([3], 3));
    hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $n3, 'numero' => '3003', 'origem' => 'OCR']);
    $r = $concluir($at, $ligada);
    hdAfirmar('R2c: todas numeradas depois do 422 = 200 (rec_cnh, cliente IDENTIFICADO)', $r['http'] === 200 && $r['corpo']['dados']['etapa'] === 'rec_cnh' && $r['corpo']['dados']['cliente_estado'] === 'IDENTIFICADO');
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('R2c: cliente gravado so agora (D1)', $reg['etapa_atual'] === 'rec_cnh' && $reg['cliente_cnpj'] === $cnpjA);

    // ---------------------------------------------------------------
    hdSecao('R3 numero legado invalido conta como pendente');
    $invalidos = ['' => 'vazio', '000123' => 'zeros a esquerda', 'abc' => 'letras', '1234567890' => '10 digitos', '12 34' => 'espaco', '0' => 'zero'];
    foreach ($invalidos as $valor => $rotulo) {
        $at = hdCriarAtendimento($amb, $idTotem);
        hdCriarNota($pdo, $at, 1, '5551');
        $idx = hdCriarNota($pdo, $at, 2, (string) $valor);
        $r = $concluir($at, $ligada);
        hdAfirmar("R3: numero legado '{$rotulo}' = pendente (422, ordem 2)", $r['http'] === 422 && $r['corpo'] === $corpoPendentes([2], 2));
    }
    hdAfirmar('R3: predicado numeroNotaValido: limites (1, 999999999 validos; 0, 01, 1000000000 invalidos; null invalido)', NotaFiscalRn::numeroNotaValido('1') && NotaFiscalRn::numeroNotaValido('999999999') && !NotaFiscalRn::numeroNotaValido('0') && !NotaFiscalRn::numeroNotaValido('01') && !NotaFiscalRn::numeroNotaValido('1000000000') && !NotaFiscalRn::numeroNotaValido(null) && !NotaFiscalRn::numeroNotaValido('') && NotaFiscalRn::numeroNotaValido(77));

    // todas pendentes (5 notas)
    $at = hdCriarAtendimento($amb, $idTotem);
    for ($o = 1; $o <= 5; $o++) {
        hdCriarNota($pdo, $at, $o, null);
    }
    $r = $concluir($at, $ligada);
    hdAfirmar('R3b: 5 notas todas pendentes -> ordens [1,2,3,4,5], total 5, pendentes 5', $r['http'] === 422 && $r['corpo'] === $corpoPendentes([1, 2, 3, 4, 5], 5));

    // ordens nao contiguas
    $at = hdCriarAtendimento($amb, $idTotem);
    hdCriarNota($pdo, $at, 2, '22');
    hdCriarNota($pdo, $at, 4, null);
    hdCriarNota($pdo, $at, 5, null);
    $r = $concluir($at, $ligada);
    hdAfirmar('R3c: ordens nao contiguas -> pendentes [4,5] (ordem real, nao posicao)', $r['http'] === 422 && $r['corpo'] === $corpoPendentes([4, 5], 3));

    // ---------------------------------------------------------------
    hdSecao('R4 clique duplo concorrente e idempotencia');
    for ($rodada = 1; $rodada <= 6; $rodada++) {
        $at = hdCriarAtendimento($amb, $idTotem);
        $m1 = hdCriarNota($pdo, $at, 1, '9001');
        $m2 = hdCriarNota($pdo, $at, 2, '9002');
        $rn->identificarCliente($at['id'], $m1, null, [$cnpjA], null);
        $res = hdRodarParalelo($amb, [
            ['rota' => 'atendimento.concluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $at['id']], 'opcoes' => $ligada],
            ['rota' => 'atendimento.concluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $at['id']], 'opcoes' => $ligada],
        ]);
        $corpoEsperado = ['proxima_tela' => 'rec_cnh', 'etapa' => 'rec_cnh', 'cliente_estado' => 'IDENTIFICADO', 'cliente_motivo' => null];
        $ok = $res[0]['http'] === 200 && $res[1]['http'] === 200 && $res[0]['corpo']['dados'] === $corpoEsperado && $res[1]['corpo']['dados'] === $corpoEsperado;
        $reg = hdAtendimento($pdo, $at['id']);
        hdAfirmar("R4 rodada {$rodada}: 2 concluir simultaneos = 2x sucesso com o MESMO corpo; etapa e cliente gravados uma vez", $ok && $reg['etapa_atual'] === 'rec_cnh' && $reg['cliente_cnpj'] === $cnpjA);
    }
    $r = $concluir($at, $ligada);
    hdAfirmar('R4b: chamada sequencial posterior (etapa ja avancada) = 200 idempotente, mesmo corpo', $r['http'] === 200 && $r['corpo']['dados'] === ['proxima_tela' => 'rec_cnh', 'etapa' => 'rec_cnh', 'cliente_estado' => 'IDENTIFICADO', 'cliente_motivo' => null]);

    // clique duplo com pendentes: os dois recebem 422 (nenhum efeito)
    $at = hdCriarAtendimento($amb, $idTotem);
    hdCriarNota($pdo, $at, 1, null);
    $res = hdRodarParalelo($amb, [
        ['rota' => 'atendimento.concluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $at['id']], 'opcoes' => $ligada],
        ['rota' => 'atendimento.concluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $at['id']], 'opcoes' => $ligada],
    ]);
    hdAfirmar('R4c: duplo clique com pendente = 2x 422, etapa inalterada', $res[0]['http'] === 422 && $res[1]['http'] === 422 && hdAtendimento($pdo, $at['id'])['etapa_atual'] === 'digitalizacao_notas');

    // ---------------------------------------------------------------
    hdSecao('R5 processar tardio (apos concluir) = 400 sem escrever');
    $at = hdCriarAtendimento($amb, $idTotem);
    hdCriarNota($pdo, $at, 1, '8001');
    $concluir($at, $ligada);
    $r = hdRodar($amb, 'nota.processar', $idTotem, ['id_atendimento' => $at['id'], 'imagem' => hdJpegDataUrl(5)]);
    hdAfirmar('R5: processar depois do concluir = 400', $r['http'] === 400);
    hdAfirmar('R5: nenhuma nota nova nem arquivo novo', count(hdNotas($pdo, $at['id'])) === 1 && hdArquivosDaPasta($at) === ['nota_01.jpg']);
    $r = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at['id'], 'ordem' => 1, 'numero' => '8002', 'origem' => 'MANUAL']);
    hdAfirmar('R5b: definir-numero depois do concluir = 400 (numero intacto)', $r['http'] === 400 && hdNotas($pdo, $at['id'])[0]['numero_nota'] === '8001');

    // ---------------------------------------------------------------
    hdSecao('R6 flag desligada = comportamento atual (sem validacao de numero)');
    $variantes = [
        'ausente'        => ['env' => []],
        'false'          => ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'false']],
        'vazia'          => ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => '']],
        'TRUE maiusculo' => ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'TRUE']],
        '1'              => ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => '1']],
        'true com espaco' => ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'true ']],
    ];
    foreach ($variantes as $rotulo => $opc) {
        $at = hdCriarAtendimento($amb, $idTotem);
        hdCriarNota($pdo, $at, 1, null);
        hdCriarNota($pdo, $at, 2, '000777');
        $r = $concluir($at, $opc);
        hdAfirmar("R6: flag {$rotulo} -> 200 com notas sem numero valido (so o literal true liga)", $r['http'] === 200 && hdAtendimento($pdo, $at['id'])['etapa_atual'] === 'cliente');
    }
    $at = hdCriarAtendimento($amb, $idTotem);
    hdCriarNota($pdo, $at, 1, null);
    $r = $concluir($at, ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'true']]);
    hdAfirmar('R6b: o literal true liga (422)', $r['http'] === 422);

    // ---------------------------------------------------------------
    hdSecao('R7 limites de quantidade (400 independentes da flag)');
    $at = hdCriarAtendimento($amb, $idTotem);
    foreach ([null, $ligada] as $opc) {
        $r = $concluir($at, $opc ?? []);
        hdAfirmar('R7: concluir com 0 notas = 400 (flag ' . ($opc ? 'ligada' : 'desligada') . ')', $r['http'] === 400 && hdAtendimento($pdo, $at['id'])['etapa_atual'] === 'digitalizacao_notas');
    }
    $at = hdCriarAtendimento($amb, $idTotem);
    for ($o = 1; $o <= 6; $o++) {
        hdCriarNota($pdo, $at, $o, (string) (7000 + $o));
    }
    $r = $concluir($at, $ligada);
    hdAfirmar('R7b: mais de 5 notas (dado legado) = 400 e etapa inalterada', $r['http'] === 400 && hdAtendimento($pdo, $at['id'])['etapa_atual'] === 'digitalizacao_notas');

    // ---------------------------------------------------------------
    hdSecao('R8 posse, tipo, status e etapa');
    $at = hdCriarAtendimento($amb, $idTotem);
    hdCriarNota($pdo, $at, 1, '1');
    $r = hdRodar($amb, 'atendimento.concluir', $idTotemAlheio, ['id_atendimento' => $at['id']], $ligada);
    hdAfirmar('R8: totem alheio = 404 sem efeito', $r['http'] === 404 && hdAtendimento($pdo, $at['id'])['etapa_atual'] === 'digitalizacao_notas');
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => 999999], $ligada);
    hdAfirmar('R8: atendimento inexistente = 404', $r['http'] === 404);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, [], $ligada);
    hdAfirmar('R8: sem id_atendimento = 400', $r['http'] === 400);
    $atExp = hdCriarAtendimento($amb, $idTotem, 'EXP1A23', 'em_andamento', 'digitalizacao_notas', 'expedicao');
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atExp['id']], $ligada);
    hdAfirmar('R8: atendimento de expedicao = 404', $r['http'] === 404);
    foreach (['concluido', 'cancelado', 'bloqueado'] as $status) {
        $atS = hdCriarAtendimento($amb, $idTotem, 'STA1A23', $status);
        hdCriarNota($pdo, $atS, 1, '1');
        $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atS['id']], $ligada);
        hdAfirmar("R8: status {$status} = 400 sem alterar etapa", $r['http'] === 400 && hdAtendimento($pdo, $atS['id'])['etapa_atual'] === 'digitalizacao_notas');
    }

    // ramo idempotente por etapa
    $atIdem = hdCriarAtendimento($amb, $idTotem, 'IDM1A23', 'em_andamento', 'rec_crlv');
    $i1 = hdCriarNota($pdo, $atIdem, 1, '31');
    $rn->identificarCliente($atIdem['id'], $i1, null, [$cnpjA], null);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atIdem['id']], $ligada);
    hdAfirmar('R8b: etapa posterior (rec_crlv) com cliente IDENTIFICADO = 200 idempotente (rec_cnh)', $r['http'] === 200 && $r['corpo']['dados']['etapa'] === 'rec_cnh' && $r['corpo']['dados']['cliente_estado'] === 'IDENTIFICADO');
    $atIdem = hdCriarAtendimento($amb, $idTotem, 'IDN1A23', 'em_andamento', 'cliente');
    hdCriarNota($pdo, $atIdem, 1, '32');
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atIdem['id']], $ligada);
    hdAfirmar('R8c: etapa cliente com NAO_IDENTIFICADO = 200 idempotente (rec_cliente)', $r['http'] === 200 && $r['corpo']['dados']['proxima_tela'] === 'rec_cliente');
    $atIdem = hdCriarAtendimento($amb, $idTotem, 'IDP1A23', 'em_andamento', 'placa');
    hdCriarNota($pdo, $atIdem, 1, '33');
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atIdem['id']], $ligada);
    hdAfirmar('R8d: etapa anomala (placa) = 400 e nada muda', $r['http'] === 400 && hdAtendimento($pdo, $atIdem['id'])['etapa_atual'] === 'placa');
    $atIdem = hdCriarAtendimento($amb, $idTotem, 'IDQ1A23', 'em_andamento', 'cliente');
    $q1 = hdCriarNota($pdo, $atIdem, 1, '34');
    $rn->identificarCliente($atIdem['id'], $q1, null, [$cnpjA], null);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $atIdem['id']], $ligada);
    hdAfirmar('R8e: etapa cliente mas estado IDENTIFICADO (destino rec_cnh) = 400, nao inventa sucesso', $r['http'] === 400);

    // ---------------------------------------------------------------
    hdSecao('R9 falha de banco na conclusao: 500 sanitizado, rollback, sem vazar detalhe');
    $at = hdCriarAtendimento($amb, $idTotem);
    hdCriarNota($pdo, $at, 1, '4242');
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']], ['injecao' => 'dao_commit_falha']);
    hdAfirmar('R9: commit que falha = 500 generico (sem a excecao)', $r['http'] === 500 && !str_contains($r['stdout'], 'SENTINELA'));
    hdAfirmar('R9: etapa continua digitalizacao_notas (rollback) e log sem getMessage', hdAtendimento($pdo, $at['id'])['etapa_atual'] === 'digitalizacao_notas' && !str_contains($r['log'], 'SENTINELA'));
    $r = $concluir($at);
    hdAfirmar('R9b: nova tentativa sem a falha conclui normalmente', $r['http'] === 200);
} finally {
    hdDestruirAmbiente($amb);
}

hdEncerrar('teste_hardening_concluir_422');
