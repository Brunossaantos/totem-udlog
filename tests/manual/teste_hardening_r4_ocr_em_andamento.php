<?php

/**
 * Suite F1 (rodada corretiva /01, 2026-10-01) da demanda
 * hardening-revisao-notas-e-cliente: concluir-digitalizacao BLOQUEADO enquanto
 * qualquer nota ativa estiver PENDENTE ou PROCESSANDO (HTTP 409
 * OCR_EM_ANDAMENTO, dados.ordens_em_processamento), com a flag
 * CONCLUIR_EXIGE_NUMERO_NOTA ligada ou desligada; nenhum cliente persistido
 * antes de todas as notas ativas atingirem estado terminal; timeout de OCR de
 * 120 s (nota expirada vai ao fallback manual, nunca bloqueia
 * indefinidamente); precedencia 400 > 409 > 422; resultado tardio nao altera
 * atendimento concluido/cancelado; concorrencia concluir x identificar-cliente
 * nunca persiste cliente com nota ainda indeterminada.
 *
 * Banco `qa_` DESCARTAVEL (prefixo qa_r4be_) + STORAGE_PATH temporario
 * (hardening_helpers.php). Sem Talent/VIO/impressao/rede externa.
 *
 * Uso: php tests/manual/teste_hardening_r4_ocr_em_andamento.php
 */

require_once __DIR__ . '/hardening_helpers.php';

use App\Rn\NotaFiscalRn;

$amb = hdCriarAmbiente('r4be_f1');
$pdo = $amb['pdo'];
// mesmo fuso de Util\Conexao (o criado_em retrodatado usa NOW() desta sessao)
$pdo->exec("SET time_zone = '-03:00'");

try {
    $idTotem = hdCriarTotem($pdo, 'R4-F1');
    $idTotemAlheio = hdCriarTotem($pdo, 'R4-F1-ALHEIO');

    $pdo->exec('DELETE FROM tb_cliente');
    $cnpjA = hdCnpj(31);
    $cnpjB = hdCnpj(32);
    hdCriarCliente($pdo, 'CROMEX TINTAS LTDA', $cnpjA, 1, 'CROMEX TINTAS');
    hdCriarCliente($pdo, 'ZEBRA INDUSTRIA LTDA', $cnpjB, 1, 'ZEBRA INDUSTRIA');

    $ligada = ['env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'true'], 'sem_terminalizar' => true];
    $desligada = ['sem_terminalizar' => true];
    $concluir = static fn(array $at, array $opc) => hdRodar($GLOBALS['amb_r4'], 'atendimento.concluir', $GLOBALS['totem_r4'], ['id_atendimento' => $at['id']], $opc);
    $GLOBALS['amb_r4'] = $amb;
    $GLOBALS['totem_r4'] = $idTotem;

    $corpo409 = static fn(array $ordens): array => [
        'sucesso' => false,
        'erro'    => 'Ainda ha notas fiscais em processamento (OCR_EM_ANDAMENTO)',
        'codigo'  => 'OCR_EM_ANDAMENTO',
        'dados'   => ['ordens_em_processamento' => $ordens],
    ];
    $setStatus = static function (PDO $pdo, int $idNota, string $status, ?string $cnpj = null): void {
        $pdo->prepare('UPDATE tb_atendimento_nota SET status_ocr = :s, cnpj_emitente = :c, cliente_identificado = :i, processado_em = NOW() WHERE id_nota = :id')
            ->execute(['s' => $status, 'c' => $cnpj, 'i' => $status === 'IDENTIFICADA' ? 1 : 0, 'id' => $idNota]);
    };
    $envelhecer = static function (PDO $pdo, int $idNota, int $segundos): void {
        $pdo->prepare('UPDATE tb_atendimento_nota SET criado_em = NOW() - INTERVAL ' . (int) $segundos . ' SECOND WHERE id_nota = :id')
            ->execute(['id' => $idNota]);
    };

    // ---------------------------------------------------------------
    hdSecao('P1 nota 1 = cliente A, nota 2 PENDENTE: 409 (flag desligada), nada persistido');
    $at = hdCriarAtendimento($amb, $idTotem);
    $n1 = hdCriarNota($pdo, $at, 1, '1001', 'IDENTIFICADA', $cnpjA);
    $n2 = hdCriarNota($pdo, $at, 2, '1002', 'PENDENTE');
    $r = $concluir($at, $desligada);
    hdAfirmar('P1: HTTP 409', $r['http'] === 409);
    hdAfirmar('P1: corpo EXATO {sucesso,erro,codigo,dados{ordens_em_processamento:[2]}}', $r['corpo'] === $corpo409([2]));
    hdAfirmar('P1: ordens_em_processamento sao inteiros de 1 a 5', array_reduce($r['corpo']['dados']['ordens_em_processamento'] ?? [], static fn($c, $o) => $c && is_int($o) && $o >= 1 && $o <= 5, true));
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('P1: cliente_nome e cliente_cnpj INALTERADOS (NULL)', $reg['cliente_nome'] === null && $reg['cliente_cnpj'] === null);
    hdAfirmar('P1: etapa_atual inalterada (digitalizacao_notas) e status em_andamento', $reg['etapa_atual'] === 'digitalizacao_notas' && $reg['status'] === 'em_andamento');
    hdAfirmar('P1: nenhuma nota alterada (status_ocr IDENTIFICADA/PENDENTE)', array_column(hdNotas($pdo, $at['id']), 'status_ocr') === ['IDENTIFICADA', 'PENDENTE']);
    hdAfirmar('P1: corpo sem id_nota, uid, arquivo, caminho, imagem, CNPJ ou nome', !preg_match('/id_nota|uid|nota_0|\.jpg|base64|' . $cnpjA . '|CROMEX|' . preg_quote(substr($at['pasta'], 11), '/') . '/i', $r['stdout']));
    hdAfirmar('P1: 409 sem a CNPJ nem razao social do cliente da nota 1 (nem do banco)', !str_contains($r['stdout'], $cnpjB) && !str_contains($r['stdout'], 'ZEBRA'));

    hdSecao('P2 mesma situacao com a flag LIGADA: 409 (nao 200 nem 422)');
    $r = $concluir($at, $ligada);
    hdAfirmar('P2: HTTP 409 com flag ligada, mesmo corpo', $r['http'] === 409 && $r['corpo'] === $corpo409([2]));
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('P2: cliente e etapa inalterados com flag ligada', $reg['cliente_cnpj'] === null && $reg['cliente_nome'] === null && $reg['etapa_atual'] === 'digitalizacao_notas');

    hdSecao('P3 PROCESSANDO tambem bloqueia; varias ordens, ordenadas');
    $at3 = hdCriarAtendimento($amb, $idTotem, 'RFA3A23');
    $a1 = hdCriarNota($pdo, $at3, 1, '3001', 'PROCESSANDO');
    $a2 = hdCriarNota($pdo, $at3, 2, '3002', 'NAO_IDENTIFICADA');
    $a3 = hdCriarNota($pdo, $at3, 4, '3004', 'PENDENTE');
    $r = $concluir($at3, $desligada);
    hdAfirmar('P3: 409 com ordens [1,4] (so as em processamento, sem a ordem 2)', $r['http'] === 409 && $r['corpo'] === $corpo409([1, 4]));
    hdAfirmar('P3: etapa e cliente inalterados', hdAtendimento($pdo, $at3['id'])['etapa_atual'] === 'digitalizacao_notas' && hdAtendimento($pdo, $at3['id'])['cliente_cnpj'] === null);

    hdSecao('P4 nota 2 termina NAO_IDENTIFICADA (pela rota real): mesmo cliente -> permite e grava A');
    $at4 = hdCriarAtendimento($amb, $idTotem, 'RFA4A23');
    $b1 = hdCriarNota($pdo, $at4, 1, '4001', 'IDENTIFICADA', $cnpjA);
    $b2 = hdCriarNota($pdo, $at4, 2, '4002', 'PENDENTE');
    $r = $concluir($at4, $desligada);
    hdAfirmar('P4: antes, 409', $r['http'] === 409);
    $x = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at4['id'], 'id_nota' => $b2, 'cnpjs_candidatos' => [], 'razao_social_candidata' => null]);
    hdAfirmar('P4: identificar-cliente da nota 2 = NAO_IDENTIFICADA', $x['http'] === 200 && ($x['corpo']['dados']['status'] ?? null) === 'NAO_IDENTIFICADA');
    $r = $concluir($at4, $desligada);
    hdAfirmar('P4: depois, 200 rec_cnh com cliente_estado IDENTIFICADO', $r['http'] === 200 && ($r['corpo']['dados']['proxima_tela'] ?? null) === 'rec_cnh' && ($r['corpo']['dados']['cliente_estado'] ?? null) === 'IDENTIFICADO');
    $reg = hdAtendimento($pdo, $at4['id']);
    hdAfirmar('P4: cliente A persistido e etapa rec_cnh', $reg['cliente_cnpj'] === $cnpjA && $reg['cliente_nome'] === 'CROMEX TINTAS LTDA' && $reg['etapa_atual'] === 'rec_cnh');

    hdSecao('P5 nota 2 identifica o MESMO cliente A: permite');
    $at5 = hdCriarAtendimento($amb, $idTotem, 'RFA5A23');
    $c1 = hdCriarNota($pdo, $at5, 1, '5001', 'IDENTIFICADA', $cnpjA);
    $c2 = hdCriarNota($pdo, $at5, 2, '5002', 'PENDENTE');
    hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at5['id'], 'id_nota' => $c2, 'cnpjs_candidatos' => [$cnpjA]]);
    $r = $concluir($at5, $ligada);
    hdAfirmar('P5: 200 rec_cnh IDENTIFICADO (flag ligada, numeros ok)', $r['http'] === 200 && ($r['corpo']['dados']['cliente_estado'] ?? null) === 'IDENTIFICADO');
    hdAfirmar('P5: cliente A persistido', hdAtendimento($pdo, $at5['id'])['cliente_cnpj'] === $cnpjA);

    hdSecao('P6 nenhuma identifica: fallback manual (rec_cliente), nada persistido');
    $at6 = hdCriarAtendimento($amb, $idTotem, 'RFA6A23');
    $d1 = hdCriarNota($pdo, $at6, 1, '6001', 'PENDENTE');
    $d2 = hdCriarNota($pdo, $at6, 2, '6002', 'PENDENTE');
    $r = $concluir($at6, $desligada);
    hdAfirmar('P6: 409 com [1,2] enquanto as duas pendem', $r['http'] === 409 && $r['corpo'] === $corpo409([1, 2]));
    $setStatus($pdo, $d1, 'NAO_IDENTIFICADA');
    $r = $concluir($at6, $desligada);
    hdAfirmar('P6: 1 terminou, a outra ainda pende: 409 com [2]', $r['http'] === 409 && $r['corpo'] === $corpo409([2]));
    $setStatus($pdo, $d2, 'NAO_IDENTIFICADA');
    $r = $concluir($at6, $desligada);
    hdAfirmar('P6: ambas terminais sem cliente = 200 rec_cliente NAO_IDENTIFICADO', $r['http'] === 200 && ($r['corpo']['dados']['proxima_tela'] ?? null) === 'rec_cliente' && ($r['corpo']['dados']['cliente_estado'] ?? null) === 'NAO_IDENTIFICADO');
    hdAfirmar('P6: nenhum cliente persistido', hdAtendimento($pdo, $at6['id'])['cliente_cnpj'] === null);

    hdSecao('P7 cliente DIFERENTE na segunda nota: ANOMALIA fail-closed, nada persistido');
    $at7 = hdCriarAtendimento($amb, $idTotem, 'RFA7A23');
    $e1 = hdCriarNota($pdo, $at7, 1, '7001', 'IDENTIFICADA', $cnpjA);
    $e2 = hdCriarNota($pdo, $at7, 2, '7002', 'PENDENTE');
    $r = $concluir($at7, $desligada);
    hdAfirmar('P7: antes da segunda nota terminar, 409 (cliente A NAO foi gravado)', $r['http'] === 409 && hdAtendimento($pdo, $at7['id'])['cliente_cnpj'] === null);
    hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at7['id'], 'id_nota' => $e2, 'cnpjs_candidatos' => [$cnpjB]]);
    $r = $concluir($at7, $desligada);
    hdAfirmar('P7: 200 rec_cliente com ANOMALIA/CONFLITO', $r['http'] === 200 && ($r['corpo']['dados']['proxima_tela'] ?? null) === 'rec_cliente' && ($r['corpo']['dados']['cliente_estado'] ?? null) === 'ANOMALIA' && ($r['corpo']['dados']['cliente_motivo'] ?? null) === 'CONFLITO');
    $reg = hdAtendimento($pdo, $at7['id']);
    hdAfirmar('P7: nenhum cliente persistido (nem A nem B) e etapa cliente', $reg['cliente_cnpj'] === null && $reg['cliente_nome'] === null && $reg['etapa_atual'] === 'cliente');

    // ---------------------------------------------------------------
    hdSecao('P8 timeout de OCR (120 s): nota expirada vai ao fallback manual, nunca bloqueia');
    $at8 = hdCriarAtendimento($amb, $idTotem, 'RFA8A23');
    $f1 = hdCriarNota($pdo, $at8, 1, '8001', 'IDENTIFICADA', $cnpjA);
    $f2 = hdCriarNota($pdo, $at8, 2, '8002', 'PENDENTE');
    $envelhecer($pdo, $f2, 100);
    $r = $concluir($at8, $desligada);
    hdAfirmar('P8: nota PENDENTE com 100 s (dentro do teto) AINDA bloqueia: 409 [2]', $r['http'] === 409 && $r['corpo'] === $corpo409([2]));
    $envelhecer($pdo, $f2, 125);
    $r = $concluir($at8, $desligada);
    hdAfirmar('P8: nota PENDENTE com 125 s (acima de 120 s) NAO bloqueia: 200 rec_cliente', $r['http'] === 200 && ($r['corpo']['dados']['proxima_tela'] ?? null) === 'rec_cliente');
    hdAfirmar('P8: estado ANOMALIA com motivo INDETERMINADO (fallback manual)', ($r['corpo']['dados']['cliente_estado'] ?? null) === 'ANOMALIA' && ($r['corpo']['dados']['cliente_motivo'] ?? null) === 'INDETERMINADO');
    $reg = hdAtendimento($pdo, $at8['id']);
    hdAfirmar('P8: cliente A (da nota 1) NAO foi persistido (nota 2 desconhecida) e etapa = cliente', $reg['cliente_cnpj'] === null && $reg['cliente_nome'] === null && $reg['etapa_atual'] === 'cliente');
    $r = $concluir($at8, $desligada);
    hdAfirmar('P8: reconcluir e idempotente (200, mesmo corpo) mesmo com a nota ainda PENDENTE', $r['http'] === 200 && ($r['corpo']['dados']['proxima_tela'] ?? null) === 'rec_cliente' && ($r['corpo']['dados']['cliente_motivo'] ?? null) === 'INDETERMINADO');
    $r = $concluir($at8, $ligada);
    hdAfirmar('P8: idempotente tambem com a flag ligada', $r['http'] === 200);

    hdSecao('P8b PROCESSANDO expirada idem; so a nota expirada: todas expiradas e sem nenhum cliente');
    $at8b = hdCriarAtendimento($amb, $idTotem, 'RFA8B23');
    $g1 = hdCriarNota($pdo, $at8b, 1, '8101', 'PROCESSANDO');
    $envelhecer($pdo, $g1, 3600);
    $r = $concluir($at8b, $desligada);
    hdAfirmar('P8b: PROCESSANDO com 1 h = 200 rec_cliente INDETERMINADO (nunca bloqueio indefinido)', $r['http'] === 200 && ($r['corpo']['dados']['cliente_motivo'] ?? null) === 'INDETERMINADO' && hdAtendimento($pdo, $at8b['id'])['cliente_cnpj'] === null);

    hdSecao('P8c fronteira exata do teto (classificacao pura): 119 s bloqueia, 120 s expira');
    $c119 = NotaFiscalRn::classificarNotasEmProcessamento([['ordem' => 1, 'status_ocr' => 'PENDENTE', 'idade_segundos' => 119]]);
    $c120 = NotaFiscalRn::classificarNotasEmProcessamento([['ordem' => 1, 'status_ocr' => 'PENDENTE', 'idade_segundos' => 120]]);
    hdAfirmar('P8c: 119 s = bloqueante; 120 s = expirada', $c119 === ['bloqueantes' => [1], 'expiradas' => []] && $c120 === ['bloqueantes' => [], 'expiradas' => [1]]);
    $cTerm = NotaFiscalRn::classificarNotasEmProcessamento([
        ['ordem' => 2, 'status_ocr' => 'IDENTIFICADA', 'idade_segundos' => 5000],
        ['ordem' => 3, 'status_ocr' => 'ERRO', 'idade_segundos' => 0],
        ['ordem' => 1, 'status_ocr' => 'NAO_IDENTIFICADA', 'idade_segundos' => 0],
    ]);
    hdAfirmar('P8c: notas terminais nunca entram na classificacao', $cTerm === ['bloqueantes' => [], 'expiradas' => []]);

    // ---------------------------------------------------------------
    hdSecao('P9 precedencia 400 > 409 > 422 (flag ligada)');
    $at9 = hdCriarAtendimento($amb, $idTotem, 'RFA9A23');
    $r = $concluir($at9, $ligada);
    hdAfirmar('P9: zero notas = 400 (nao 409, nao 422)', $r['http'] === 400);
    $h1 = hdCriarNota($pdo, $at9, 1, null, 'PENDENTE');      // pendente de OCR E sem numero
    $h2 = hdCriarNota($pdo, $at9, 2, null, 'NAO_IDENTIFICADA'); // terminal, sem numero
    $r = $concluir($at9, $ligada);
    hdAfirmar('P9: OCR em andamento + numero pendente = 409 (409 vence o 422)', $r['http'] === 409 && $r['corpo'] === $corpo409([1]));
    $setStatus($pdo, $h1, 'NAO_IDENTIFICADA');
    $r = $concluir($at9, $ligada);
    hdAfirmar('P9: todas terminais e numeros pendentes = 422 NOTAS_SEM_NUMERO [1,2]', $r['http'] === 422 && ($r['corpo']['codigo'] ?? null) === 'NOTAS_SEM_NUMERO' && ($r['corpo']['dados']['ordens_pendentes'] ?? null) === [1, 2]);
    $r = $concluir($at9, $desligada);
    hdAfirmar('P9: mesma situacao com flag desligada = 200 (so o 409 vale com flag desligada)', $r['http'] === 200);
    $at9b = hdCriarAtendimento($amb, $idTotem, 'RFA9B23');
    hdCriarNota($pdo, $at9b, 1, null, 'PENDENTE');
    $r = $concluir($at9b, $desligada);
    hdAfirmar('P9: 409 tambem com flag DESLIGADA e numero ausente', $r['http'] === 409);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at9b['id']], ['sem_terminalizar' => true, 'env' => ['CONCLUIR_EXIGE_NUMERO_NOTA' => 'TRUE']]);
    hdAfirmar('P9: flag com valor nao literal (TRUE) = desligada, 409 continua valendo', $r['http'] === 409);

    // expirada + numero pendente + flag ligada: 422 (expirada nao e 409)
    $at9c = hdCriarAtendimento($amb, $idTotem, 'RFA9C23');
    $i1 = hdCriarNota($pdo, $at9c, 1, null, 'PENDENTE');
    $envelhecer($pdo, $i1, 200);
    $r = $concluir($at9c, $ligada);
    hdAfirmar('P9: nota expirada sem numero e flag ligada = 422 (nao 409)', $r['http'] === 422 && ($r['corpo']['dados']['ordens_pendentes'] ?? null) === [1]);

    // ---------------------------------------------------------------
    hdSecao('P10 resultado tardio nao altera atendimento concluido/cancelado');
    $at10 = hdCriarAtendimento($amb, $idTotem, 'RFB0A23');
    $j1 = hdCriarNota($pdo, $at10, 1, '9001', 'IDENTIFICADA', $cnpjA);
    $j2 = hdCriarNota($pdo, $at10, 2, '9002', 'PENDENTE');
    $envelhecer($pdo, $j2, 300);
    $r = $concluir($at10, $desligada);
    hdAfirmar('P10: concluiu pelo fallback (expirada)', $r['http'] === 200 && ($r['corpo']['dados']['proxima_tela'] ?? null) === 'rec_cliente');
    $antes = json_encode([hdNotas($pdo, $at10['id']), hdAtendimento($pdo, $at10['id'])['cliente_cnpj'], hdAtendimento($pdo, $at10['id'])['etapa_atual']]);
    $x = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $j2, 'cnpjs_candidatos' => [$cnpjB]]);
    hdAfirmar('P10: identificar-cliente tardio apos o concluir = 400 (etapa errada)', $x['http'] === 400);
    $x2 = hdRodar($amb, 'nota.definir', $idTotem, ['id_atendimento' => $at10['id'], 'id_nota' => $j2, 'numero' => '777', 'origem' => 'OCR']);
    hdAfirmar('P10: definir-numero tardio = 400', $x2['http'] === 400);
    $depois = json_encode([hdNotas($pdo, $at10['id']), hdAtendimento($pdo, $at10['id'])['cliente_cnpj'], hdAtendimento($pdo, $at10['id'])['etapa_atual']]);
    hdAfirmar('P10: nada mudou (notas, cliente e etapa identicos)', $antes === $depois);

    foreach (['cancelado' => 'em_andamento', 'concluido' => 'concluido'] as $status => $_) {
        $atT = hdCriarAtendimento($amb, $idTotem, 'RFB1A23', $status, $status === 'concluido' ? 'impressao' : 'digitalizacao_notas');
        $t1 = hdCriarNota($pdo, $atT, 1, '9101', 'PENDENTE');
        $antes = json_encode(hdNotas($pdo, $atT['id']));
        $x = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $atT['id'], 'id_nota' => $t1, 'cnpjs_candidatos' => [$cnpjA]]);
        hdAfirmar("P10: resultado tardio em atendimento {$status} = 400", $x['http'] === 400);
        hdAfirmar("P10: nota de atendimento {$status} segue PENDENTE e sem cliente", json_encode(hdNotas($pdo, $atT['id'])) === $antes && hdAtendimento($pdo, $atT['id'])['cliente_cnpj'] === null);
    }

    // ---------------------------------------------------------------
    hdSecao('P11 posse/tipo do concluir inalterados (404 generico) e 409 nao vaza a existencia');
    $r = hdRodar($amb, 'atendimento.concluir', $idTotemAlheio, ['id_atendimento' => $at['id']], $desligada);
    hdAfirmar('P11: concluir de totem alheio = 404 (nao 409)', $r['http'] === 404);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => 987654], $desligada);
    hdAfirmar('P11: atendimento inexistente = 404', $r['http'] === 404);

    // ---------------------------------------------------------------
    hdSecao('P12 concorrencia concluir x identificar-cliente: nunca persiste cliente com nota indeterminada');
    $violacoes = 0;
    $rodadas = 8;
    $resumo = ['409' => 0, 'conflito' => 0, '200_outros' => 0];
    for ($i = 0; $i < $rodadas; $i++) {
        $atc = hdCriarAtendimento($amb, $idTotem, 'RFC' . $i . 'A23');
        hdCriarNota($pdo, $atc, 1, '10' . $i . '1', 'IDENTIFICADA', $cnpjA);
        $nc2 = hdCriarNota($pdo, $atc, 2, '10' . $i . '2', 'PENDENTE');
        $res = hdRodarParalelo($amb, [
            'concluir'    => ['rota' => 'atendimento.concluir', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atc['id']], 'opcoes' => $desligada],
            'identificar' => ['rota' => 'nota.identificar', 'totem' => $idTotem, 'entrada' => ['id_atendimento' => $atc['id'], 'id_nota' => $nc2, 'cnpjs_candidatos' => [$cnpjB]]],
        ]);
        $reg = hdAtendimento($pdo, $atc['id']);
        $clientes = array_filter(array_column(hdNotas($pdo, $atc['id']), 'cnpj_emitente'));
        $clientesDistintos = count(array_unique($clientes));
        $statusConcluir = $res['concluir']['http'];
        if ($statusConcluir === 409) {
            $resumo['409']++;
            if ($reg['cliente_cnpj'] !== null || $reg['etapa_atual'] !== 'digitalizacao_notas') {
                $violacoes++;
            }
        } elseif ($statusConcluir === 200) {
            if (($res['concluir']['corpo']['dados']['cliente_estado'] ?? '') === 'ANOMALIA') {
                $resumo['conflito']++;
            } else {
                $resumo['200_outros']++;
            }
            // 200 + cliente persistido so e valido se TODAS as notas apontam o mesmo cliente
            if ($reg['cliente_cnpj'] !== null && $clientesDistintos !== 1) {
                $violacoes++;
            }
            if (($res['concluir']['corpo']['dados']['cliente_estado'] ?? '') === 'IDENTIFICADO' && $clientesDistintos !== 1) {
                $violacoes++;
            }
            // com a nota 2 identificada como B, o 200 NUNCA pode ser IDENTIFICADO
            if (($res['concluir']['corpo']['dados']['cliente_estado'] ?? '') === 'IDENTIFICADO') {
                $violacoes++;
            }
        } else {
            $violacoes++;
        }
    }
    hdAfirmar("P12: {$rodadas} rodadas paralelas sem nenhuma violacao (cliente persistido so com notas concordantes)", $violacoes === 0);
    echo "  (desfechos: " . json_encode($resumo) . ")\n";
    hdAfirmar('P12: o paralelismo exercitou pelo menos um desfecho (409 ou conflito)', $resumo['409'] + $resumo['conflito'] > 0);

    // ---------------------------------------------------------------
    hdSecao('P13 logs sem dado pessoal');
    $todos = hdTodosLogs();
    foreach ([$cnpjA, $cnpjB, 'CROMEX', 'ZEBRA', 'data:image', 'base64', '.jpg', 'SENTINELA_'] as $s) {
        hdAfirmar('P13: log nao contem ' . (strlen($s) > 14 ? substr($s, 0, 14) . '...' : $s), !str_contains($todos, $s));
    }
    hdAfirmar('P13: nenhum warning/notice/fatal nos logs do PHP', !preg_match('/Warning|Notice|Deprecated|Fatal|Stack trace/i', $todos));
} finally {
    hdDestruirAmbiente($amb);
}

hdEncerrar('teste_hardening_r4_ocr_em_andamento');
