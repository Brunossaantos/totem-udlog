<?php

/**
 * Suite da regra de cliente (demanda hardening-revisao-notas-e-cliente,
 * 2026-09-30, fase 2 backend) — matriz C1 a C15 + D1 + validacao do cliente
 * manual + contrato HTTP de identificar-cliente.
 *
 * Banco `qa_` DESCARTAVEL + STORAGE_PATH temporario (hardening_helpers.php);
 * nunca toca o banco do .env, nunca chama Talent/VIO/impressao.
 *
 * Decisao D2: tb_cliente ATIVA e a UNICA allowlist; candidato que nao existe
 * como cliente ativo e ignorado (inclusive transportadora e UDLOG).
 *
 * Uso: php tests/manual/teste_hardening_cliente.php
 */

require_once __DIR__ . '/hardening_helpers.php';

use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Rn\NotaFiscalRn;
use Util\CnpjValidador;

$amb = hdCriarAmbiente('hd_cliente');
$pdo = $amb['pdo'];

try {
    $idTotem = hdCriarTotem($pdo, 'HD-CLI');
    $notaDao = new AtendimentoNotaDao($pdo);
    $rn = new NotaFiscalRn($notaDao, new ClienteDao($pdo));

    // base limpa de clientes (banco qa_ proprio): o fuzzy depende da listagem inteira
    $pdo->exec('DELETE FROM tb_cliente');

    $cnpjA = hdCnpj(1);
    $cnpjB = hdCnpj(2);
    $cnpjC = hdCnpj(3);
    $cnpjTransportadora = hdCnpj(4);
    $cnpjInativo = hdCnpj(5);
    $cnpjUdlog1 = CnpjValidador::CNPJ_UDLOG_1;
    $cnpjUdlog2 = CnpjValidador::CNPJ_UDLOG_2;

    hdAfirmar('Fixture: CNPJs de teste sao validos e distintos', CnpjValidador::normalizarEValidar($cnpjA) === $cnpjA
        && CnpjValidador::normalizarEValidar($cnpjB) === $cnpjB && $cnpjA !== $cnpjB);

    $idA = hdCriarCliente($pdo, 'CROMEX TINTAS LTDA', $cnpjA, 1, 'CROMEX TINTAS');
    $idB = hdCriarCliente($pdo, 'ZEBRA INDUSTRIA LTDA', $cnpjB, 1, 'ZEBRA INDUSTRIA');
    $idInativo = hdCriarCliente($pdo, 'FANTASMA COMERCIO LTDA', $cnpjInativo, 0, 'FANTASMA COMERCIO');

    $novoAtendimento = function () use ($amb, $idTotem) {
        return hdCriarAtendimento($amb, $idTotem);
    };

    // ---------------------------------------------------------------
    hdSecao('C1 CNPJ unico da allowlist identifica');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    hdAfirmar('C1: status IDENTIFICADA', $r['status'] === 'IDENTIFICADA');
    hdAfirmar('C1: cliente da nota e o A (id, razao social, cnpj)', (int) ($r['cliente']['id'] ?? 0) === $idA && $r['cliente']['cnpj'] === $cnpjA && $r['cliente']['razao_social'] === 'CROMEX TINTAS LTDA');
    hdAfirmar('C1: cliente_atendimento IDENTIFICADO com o cliente', $r['cliente_atendimento']['estado'] === 'IDENTIFICADO' && $r['cliente_atendimento']['cliente']['cnpj'] === $cnpjA);
    hdAfirmar('C1: ja_identificado_no_atendimento true', $r['ja_identificado_no_atendimento'] === true);
    hdAfirmar('C1: motivo nulo e id_nota devolvido', $r['motivo'] === null && $r['id_nota'] === $n1);
    $linha = hdNotas($pdo, $at['id'])[0];
    hdAfirmar('C1: persistido status_ocr IDENTIFICADA, cnpj_emitente e cliente_identificado=1', $linha['status_ocr'] === 'IDENTIFICADA' && $linha['cnpj_emitente'] === $cnpjA && (int) $linha['cliente_identificado'] === 1);

    $r = $rn->identificarCliente($at['id'], $n1, null, [CnpjValidador::normalizarEValidar($cnpjA) ? substr($cnpjA, 0, 2) . '.' . substr($cnpjA, 2, 3) . '.' . substr($cnpjA, 5, 3) . '/' . substr($cnpjA, 8, 4) . '-' . substr($cnpjA, 12, 2) : ''], null);
    hdAfirmar('C1: CNPJ com mascara tambem e aceito (idempotente, mesmo cliente)', $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === $cnpjA);

    // ---------------------------------------------------------------
    hdSecao('C2 razao social unica e inequivoca identifica (sem CNPJ valido)');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [], 'Cromex Tintas');
    hdAfirmar('C2: razao social inequivoca = IDENTIFICADA (cliente A)', $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === $cnpjA);
    hdAfirmar('C2: estado do atendimento IDENTIFICADO', $r['cliente_atendimento']['estado'] === 'IDENTIFICADO');

    // ---------------------------------------------------------------
    hdSecao('C3/C4 UDLOG e transportadora sao ignoradas (nao sao cliente ativo)');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjUdlog1, $cnpjA, $cnpjUdlog2], null);
    hdAfirmar('C3: UDLOG + cliente A -> identifica so o A', $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === $cnpjA);

    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjUdlog1, $cnpjUdlog2], null);
    hdAfirmar('C4: so UDLOG -> NAO_IDENTIFICADA', $r['status'] === 'NAO_IDENTIFICADA' && $r['cliente'] === null);
    hdAfirmar('C4: estado do atendimento NAO_IDENTIFICADO', $r['cliente_atendimento']['estado'] === 'NAO_IDENTIFICADO' && $r['ja_identificado_no_atendimento'] === false);

    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjTransportadora], null);
    hdAfirmar('C4b: transportadora (CNPJ valido que nao e cliente ativo) e ignorada -> NAO_IDENTIFICADA', $r['status'] === 'NAO_IDENTIFICADA');

    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjTransportadora, $cnpjA], null);
    hdAfirmar('C4c: transportadora + cliente A -> identifica so o A (nunca a transportadora)', $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === $cnpjA);

    // ---------------------------------------------------------------
    hdSecao('C5 mesmo cliente em varias notas = um cliente');
    $at = $novoAtendimento();
    $ids = [hdCriarNota($pdo, $at, 1), hdCriarNota($pdo, $at, 2), hdCriarNota($pdo, $at, 3)];
    foreach ($ids as $id) {
        $r = $rn->identificarCliente($at['id'], $id, null, [$cnpjA], null);
    }
    hdAfirmar('C5: 3 notas do mesmo cliente -> IDENTIFICADO (1 cliente distinto)', $r['cliente_atendimento']['estado'] === 'IDENTIFICADO');
    $aval = $rn->avaliarClienteDoAtendimento($at['id']);
    hdAfirmar('C5: avaliarClienteDoAtendimento IDENTIFICADO sem motivo', $aval['estado'] === 'IDENTIFICADO' && $aval['motivo'] === null);

    // ---------------------------------------------------------------
    hdSecao('C6 clientes distintos entre notas = ANOMALIA (CONFLITO)');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $n2 = hdCriarNota($pdo, $at, 2);
    $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    $r = $rn->identificarCliente($at['id'], $n2, null, [$cnpjB], null);
    hdAfirmar('C6: cada nota fica IDENTIFICADA do seu cliente', $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === $cnpjB);
    hdAfirmar('C6: atendimento em ANOMALIA e ja_identificado false', $r['cliente_atendimento']['estado'] === 'ANOMALIA' && $r['cliente_atendimento']['cliente'] === null && $r['ja_identificado_no_atendimento'] === false);
    $aval = $rn->avaliarClienteDoAtendimento($at['id']);
    hdAfirmar('C6: motivo CONFLITO', $aval['motivo'] === 'CONFLITO');
    hdAfirmar('C6: cliente_atendimento publico nunca expoe dados de candidatos na anomalia', !str_contains(json_encode($r['cliente_atendimento']), $cnpjA) && !str_contains(json_encode($r['cliente_atendimento']), $cnpjB));

    // ---------------------------------------------------------------
    hdSecao('C7 duas correspondencias na MESMA nota = ERRO (MULTIPLAS_CORRESPONDENCIAS)');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjA, $cnpjB], null);
    hdAfirmar('C7: status ERRO com motivo MULTIPLAS_CORRESPONDENCIAS e sem cliente', $r['status'] === 'ERRO' && $r['motivo'] === 'MULTIPLAS_CORRESPONDENCIAS' && $r['cliente'] === null);
    $linha = hdNotas($pdo, $at['id'])[0];
    hdAfirmar('C7: persistido ERRO sem cnpj_emitente e cliente_identificado=0 (conflito representado por ERRO, sem migration)', $linha['status_ocr'] === 'ERRO' && $linha['cnpj_emitente'] === null && (int) $linha['cliente_identificado'] === 0);
    hdAfirmar('C7: atendimento em ANOMALIA (INDETERMINADO)', $r['cliente_atendimento']['estado'] === 'ANOMALIA' && $rn->avaliarClienteDoAtendimento($at['id'])['motivo'] === 'INDETERMINADO');
    $at2 = $novoAtendimento();
    $m1 = hdCriarNota($pdo, $at2, 1);
    $m2 = hdCriarNota($pdo, $at2, 2);
    $rn->identificarCliente($at2['id'], $m1, null, [$cnpjA, $cnpjB], null);
    $rn->identificarCliente($at2['id'], $m2, null, [$cnpjA], null);
    hdAfirmar('C7b: nota em ERRO + outra IDENTIFICADA = ANOMALIA (ERRO nunca e ignorado)', $rn->avaliarClienteDoAtendimento($at2['id'])['estado'] === 'ANOMALIA');
    $at3 = $novoAtendimento();
    $k1 = hdCriarNota($pdo, $at3, 1);
    $r = $rn->identificarCliente($at3['id'], $k1, null, [$cnpjA, $cnpjA, $cnpjA], null);
    hdAfirmar('C7c: o MESMO cliente repetido na nota (3 vezes) conta como um so -> IDENTIFICADA', $r['status'] === 'IDENTIFICADA');

    // ---------------------------------------------------------------
    hdSecao('C8 fuzzy: empate/ambiguidade = ERRO; abaixo do limiar = NAO_IDENTIFICADA');
    $pdo->exec("UPDATE tb_cliente SET ativo = 0 WHERE id_cliente IN ({$idA}, {$idB})");
    $idF1 = hdCriarCliente($pdo, 'ALFA COMERCIAL LTDA', $cnpjC, 1, 'ALFA COMERCIAL');
    $cnpjD = hdCnpj(6);
    $idF2 = hdCriarCliente($pdo, 'ALFA COMERCIAL LTDA', $cnpjD, 1, 'ALFA COMERCIAL');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [], 'Alfa Comercial');
    hdAfirmar('C8: razao social igual em 2 clientes ativos (matriz/filial) = ERRO RAZAO_SOCIAL_AMBIGUA', $r['status'] === 'ERRO' && $r['motivo'] === 'RAZAO_SOCIAL_AMBIGUA' && $r['cliente'] === null);
    hdAfirmar('C8: atendimento em ANOMALIA', $r['cliente_atendimento']['estado'] === 'ANOMALIA');
    $matcher = \Util\RazaoSocialMatcher::melhorCandidato('Alfa Comercial', (new ClienteDao($pdo))->listarParaFuzzy());
    hdAfirmar('C8: RazaoSocialMatcher devolve ambiguo=true e identificado=false (campo aditivo)', $matcher['ambiguo'] === true && $matcher['identificado'] === false);

    // quase empate (88.9% vs 100%): margem < 15
    $pdo->exec("UPDATE tb_cliente SET razao_social_normalizada = 'ALFA COMERCIO', nome = 'ALFA COMERCIO LTDA' WHERE id_cliente = {$idF2}");
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [], 'Alfa Comercial');
    hdAfirmar('C8b: quase empate (margem < 15) tambem e ambiguo -> ERRO', $r['status'] === 'ERRO' && $r['motivo'] === 'RAZAO_SOCIAL_AMBIGUA');

    // abaixo do limiar
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [], 'Qwerty Xyzzy Plugh');
    hdAfirmar('C8c: abaixo do limiar = NAO_IDENTIFICADA (nao e ERRO)', $r['status'] === 'NAO_IDENTIFICADA' && $r['motivo'] === null);
    $matcher = \Util\RazaoSocialMatcher::melhorCandidato('Qwerty Xyzzy Plugh', (new ClienteDao($pdo))->listarParaFuzzy());
    hdAfirmar('C8c: matcher ambiguo=false abaixo do limiar', $matcher['ambiguo'] === false && $matcher['identificado'] === false);

    // vencedor isolado: unico e inequivoco
    $pdo->exec("UPDATE tb_cliente SET ativo = 0 WHERE id_cliente IN ({$idF1}, {$idF2})");
    $pdo->exec("UPDATE tb_cliente SET ativo = 1 WHERE id_cliente IN ({$idA}, {$idB})");

    // fuzzy so roda com ZERO casamentos por CNPJ
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjB], 'Cromex Tintas');
    hdAfirmar('C8d: CNPJ casou -> razao social (que apontaria para outro cliente) NAO decide; vale o CNPJ', $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === $cnpjB);

    // ---------------------------------------------------------------
    hdSecao('C9 nenhuma correspondencia');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, ['123', 'lixo', '00000000000000'], null);
    hdAfirmar('C9: candidatos invalidos -> NAO_IDENTIFICADA', $r['status'] === 'NAO_IDENTIFICADA' && $r['cliente_atendimento']['estado'] === 'NAO_IDENTIFICADO');
    $r = $rn->identificarCliente(($a2 = $novoAtendimento())['id'], $n2x = hdCriarNota($pdo, $a2, 1), null, [], null);
    hdAfirmar('C9b: sem candidato nenhum -> NAO_IDENTIFICADA', $r['status'] === 'NAO_IDENTIFICADA');
    hdAfirmar('C9c: atendimento sem nenhuma nota identificada = NAO_IDENTIFICADO', $rn->avaliarClienteDoAtendimento($a2['id'])['estado'] === 'NAO_IDENTIFICADO');

    // ---------------------------------------------------------------
    hdSecao('C10 cliente inativo nao identifica');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjInativo], 'Fantasma Comercio');
    hdAfirmar('C10: CNPJ e razao social de cliente INATIVO -> NAO_IDENTIFICADA', $r['status'] === 'NAO_IDENTIFICADA');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    hdAfirmar('C10b: (antes) identificado', $rn->avaliarClienteDoAtendimento($at['id'])['estado'] === 'IDENTIFICADO');
    $pdo->exec("UPDATE tb_cliente SET ativo = 0 WHERE id_cliente = {$idA}");
    hdAfirmar('C10c: cliente desativado DEPOIS de identificar deixa de contar (nota IDENTIFICADA sem cliente ativo = NAO_IDENTIFICADO)', $rn->avaliarClienteDoAtendimento($at['id'])['estado'] === 'NAO_IDENTIFICADO');
    $pdo->exec("UPDATE tb_cliente SET ativo = 1 WHERE id_cliente = {$idA}");

    // ---------------------------------------------------------------
    hdSecao('C11 UDLOG como linha de tb_cliente continua ignorada pelo CNPJ');
    $idUdlog = hdCriarCliente($pdo, 'UNITED LOGISTICS LTDA', $cnpjUdlog1, 1, 'UNITED LOGISTICS');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjUdlog1], null);
    hdAfirmar('C11: CNPJ da UDLOG nao identifica mesmo existindo como cliente ativo (ehUdlog preservado)', $r['status'] === 'NAO_IDENTIFICADA');
    $pdo->exec("DELETE FROM tb_cliente WHERE id_cliente = {$idUdlog}");

    // ---------------------------------------------------------------
    hdSecao('C12 escrita unica e idempotencia por nota');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $rn->identificarCliente($at['id'], $n1, null, [], null);
    $antes = hdNotas($pdo, $at['id'])[0];
    usleep(1100000);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    $depois = hdNotas($pdo, $at['id'])[0];
    hdAfirmar('C12: segunda chamada NAO sobrescreve (status gravado permanece NAO_IDENTIFICADA)', $depois['status_ocr'] === 'NAO_IDENTIFICADA' && $depois['cnpj_emitente'] === null);
    hdAfirmar('C12: processado_em intacto (nenhuma nova escrita)', $antes['processado_em'] === $depois['processado_em']);
    hdAfirmar('C12: resposta devolve o valor GRAVADO', $r['status'] === 'NAO_IDENTIFICADA' && $r['cliente'] === null);
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    $r = $rn->identificarCliente($at['id'], $n1, null, [$cnpjB], null);
    hdAfirmar('C12b: nota ja IDENTIFICADA do A nao vira B numa repeticao (devolve A gravado)', $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === $cnpjA && hdNotas($pdo, $at['id'])[0]['cnpj_emitente'] === $cnpjA);
    hdAfirmar('C12c: gravarResultadoOcrUnico direto: 0 linhas quando ja gravado', $notaDao->gravarResultadoOcrUnico($n1, $at['id'], 'IDENTIFICADA', $cnpjB) === false);
    hdAfirmar('C12d: gravarResultadoOcrUnico com id_atendimento de outro atendimento: 0 linhas', $notaDao->gravarResultadoOcrUnico($n1, $at['id'] + 9999, 'ERRO', null) === false);

    // ---------------------------------------------------------------
    hdSecao('C13 independe da ordem de chegada');
    $cenarios = [
        'A depois B'  => [[$cnpjA], [$cnpjB]],
        'B depois A'  => [[$cnpjB], [$cnpjA]],
    ];
    foreach ($cenarios as $nome => [$c1, $c2]) {
        $at = $novoAtendimento();
        $na = hdCriarNota($pdo, $at, 1);
        $nb = hdCriarNota($pdo, $at, 2);
        $rn->identificarCliente($at['id'], $na, null, $c1, null);
        $rn->identificarCliente($at['id'], $nb, null, $c2, null);
        hdAfirmar("C13: {$nome} -> ANOMALIA CONFLITO", ($av = $rn->avaliarClienteDoAtendimento($at['id']))['estado'] === 'ANOMALIA' && $av['motivo'] === 'CONFLITO');
    }
    $at = $novoAtendimento();
    $na = hdCriarNota($pdo, $at, 1);
    $nb = hdCriarNota($pdo, $at, 2);
    $rn->identificarCliente($at['id'], $nb, null, [$cnpjA], null);
    $rn->identificarCliente($at['id'], $na, null, [], null);
    hdAfirmar('C13b: nota 2 identifica, nota 1 nao (ordem inversa) -> IDENTIFICADO', $rn->avaliarClienteDoAtendimento($at['id'])['estado'] === 'IDENTIFICADO');
    $at = $novoAtendimento();
    $na = hdCriarNota($pdo, $at, 1);
    $nb = hdCriarNota($pdo, $at, 2);
    $rn->identificarCliente($at['id'], $na, null, [$cnpjA], null);
    $rn->identificarCliente($at['id'], $nb, null, [$cnpjA, $cnpjB], null);
    hdAfirmar('C13c: nota 1 identifica A e nota 2 ERRO -> ANOMALIA; ordem inversa igual', $rn->avaliarClienteDoAtendimento($at['id'])['estado'] === 'ANOMALIA');

    // ---------------------------------------------------------------
    hdSecao('C15 sem early-stop: a segunda nota tambem e analisada');
    $at = $novoAtendimento();
    $na = hdCriarNota($pdo, $at, 1);
    $nb = hdCriarNota($pdo, $at, 2);
    $rn->identificarCliente($at['id'], $na, null, [$cnpjA], null);
    $r = $rn->identificarCliente($at['id'], $nb, null, [$cnpjB], null);
    hdAfirmar('C15: nota 2 NAO herda a evidencia da nota 1 (status IDENTIFICADA com o cliente B)', $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === $cnpjB);
    $linhas = hdNotas($pdo, $at['id']);
    hdAfirmar('C15: cada nota guarda so a PROPRIA evidencia', $linhas[0]['cnpj_emitente'] === $cnpjA && $linhas[1]['cnpj_emitente'] === $cnpjB);
    $at = $novoAtendimento();
    $na = hdCriarNota($pdo, $at, 1);
    $nb = hdCriarNota($pdo, $at, 2);
    $rn->identificarCliente($at['id'], $na, null, [$cnpjA], null);
    $r = $rn->identificarCliente($at['id'], $nb, null, [], null);
    hdAfirmar('C15b: nota 2 sem candidato continua NAO_IDENTIFICADA (nao copia o cliente da nota 1)', $r['status'] === 'NAO_IDENTIFICADA' && hdNotas($pdo, $at['id'])[1]['cnpj_emitente'] === null);
    hdAfirmar('C15c: atendimento continua IDENTIFICADO (derivado, 1 cliente)', $r['cliente_atendimento']['estado'] === 'IDENTIFICADO');

    // ---------------------------------------------------------------
    hdSecao('Cliente sem CNPJ persistivel vale como nenhuma correspondencia');
    $cnpjMascarado = hdCnpj(9);
    $idMasc = hdCriarCliente($pdo, 'MASCARADA SOLUCOES LTDA', substr($cnpjMascarado, 0, 2) . '.' . substr($cnpjMascarado, 2, 3) . '.' . substr($cnpjMascarado, 5, 3) . '/' . substr($cnpjMascarado, 8, 4) . '-' . substr($cnpjMascarado, 12, 2), 1, 'MASCARADA SOLUCOES');
    $idInvalido = hdCriarCliente($pdo, 'INVALIDA COMERCIO LTDA', '123', 1, 'INVALIDA COMERCIO');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [], 'Mascarada Solucoes');
    hdAfirmar('cnpj mascarado em tb_cliente (nao renormaliza) via fuzzy -> NAO_IDENTIFICADA, sem gravar NULL', $r['status'] === 'NAO_IDENTIFICADA' && hdNotas($pdo, $at['id'])[0]['cnpj_emitente'] === null);
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r = $rn->identificarCliente($at['id'], $n1, null, [], 'Invalida Comercio');
    hdAfirmar('cnpj invalido em tb_cliente via fuzzy -> NAO_IDENTIFICADA', $r['status'] === 'NAO_IDENTIFICADA');
    $pdo->exec("DELETE FROM tb_cliente WHERE id_cliente IN ({$idMasc}, {$idInvalido})");

    // ---------------------------------------------------------------
    hdSecao('Falha tecnica em tb_cliente: ERRO_TECNICO e log sem getMessage/CNPJ/razao social');
    $rodadas = [];
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r1 = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $n1, 'cnpjs_candidatos' => [$cnpjA], 'razao_social_candidata' => 'RAZAOSENTINELAXYZ LTDA'], ['injecao' => 'cliente_falha']);
    $rodadas[] = $r1;
    hdAfirmar('ERRO_TECNICO: HTTP 200 com status ERRO e motivo ERRO_TECNICO', $r1['http'] === 200 && ($r1['corpo']['dados']['status'] ?? null) === 'ERRO' && ($r1['corpo']['dados']['motivo'] ?? null) === 'ERRO_TECNICO');
    hdAfirmar('ERRO_TECNICO: nota persistida em ERRO; atendimento ANOMALIA', hdNotas($pdo, $at['id'])[0]['status_ocr'] === 'ERRO' && ($r1['corpo']['dados']['cliente_atendimento']['estado'] ?? null) === 'ANOMALIA');
    hdAfirmar('ERRO_TECNICO: log tem a classe da excecao e ids inteiros', str_contains($r1['log'], 'RuntimeException') && str_contains($r1['log'], 'id_atendimento=' . $at['id']) && str_contains($r1['log'], 'id_nota=' . $n1));
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $r2 = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $n1, 'cnpjs_candidatos' => [], 'razao_social_candidata' => 'RAZAOSENTINELAXYZ LTDA'], ['injecao' => 'cliente_falha']);
    $rodadas[] = $r2;
    hdAfirmar('ERRO_TECNICO (fuzzy): status ERRO', ($r2['corpo']['dados']['status'] ?? null) === 'ERRO');
    $logs = hdLogsDe($rodadas);
    hdAfirmar('log sem getMessage (sentinelas de excecao ausentes)', !str_contains($logs, 'SENTINELA_CLIENTE'));
    hdAfirmar('log sem CNPJ, razao social ou placa', !str_contains($logs, $cnpjA) && !str_contains($logs, 'RAZAOSENTINELAXYZ') && !str_contains($logs, 'HDT1A23'));
    hdAfirmar('log sem caminho de storage nem pasta_documentos', !str_contains($logs, 'totem_hd_') && !str_contains($logs, date('Y-m-d') . '/'));

    // ---------------------------------------------------------------
    hdSecao('Contrato HTTP de identificar-cliente (id_nota e ordem)');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    $n2 = hdCriarNota($pdo, $at, 2);
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $n1, 'cnpjs_candidatos' => [$cnpjA], 'razao_social_candidata' => null]);
    $d = $r['corpo']['dados'] ?? [];
    hdAfirmar('HTTP 200 por id_nota', $r['http'] === 200 && ($r['corpo']['sucesso'] ?? false) === true);
    hdAfirmar('response: chaves exatas (status, motivo, cliente, id_nota, ja_identificado_no_atendimento, cliente_atendimento)', array_keys($d) === ['status', 'motivo', 'cliente', 'id_nota', 'ja_identificado_no_atendimento', 'cliente_atendimento']);
    hdAfirmar('response: cliente_atendimento so {estado, cliente}', array_keys($d['cliente_atendimento'] ?? []) === ['estado', 'cliente'] && $d['cliente_atendimento']['estado'] === 'IDENTIFICADO');
    hdAfirmar('response: cliente com id, razao_social, cnpj (shape inalterado)', array_keys($d['cliente'] ?? []) === ['id', 'razao_social', 'cnpj']);

    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'ordem' => 2, 'cnpjs_candidatos' => [$cnpjB]]);
    hdAfirmar('compat: por ordem (sem id_nota) continua funcionando (200)', $r['http'] === 200 && ($r['corpo']['dados']['id_nota'] ?? null) === $n2);
    hdAfirmar('compat: atendimento agora em ANOMALIA (A e B)', ($r['corpo']['dados']['cliente_atendimento']['estado'] ?? null) === 'ANOMALIA');

    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $n1, 'ordem' => 2, 'cnpjs_candidatos' => []]);
    hdAfirmar('id_nota e ordem que nao coincidem = 404 sem escrita', $r['http'] === 404);
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $n1 + 99999, 'cnpjs_candidatos' => []]);
    hdAfirmar('id_nota inexistente = 404', $r['http'] === 404);
    $outro = $novoAtendimento();
    $nOutro = hdCriarNota($pdo, $outro, 1);
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $nOutro, 'cnpjs_candidatos' => [$cnpjA]]);
    hdAfirmar('id_nota de OUTRO atendimento = 404 e a nota alheia nao foi tocada', $r['http'] === 404 && hdNotas($pdo, $outro['id'])[0]['status_ocr'] === 'PENDENTE');
    $idTotemAlheio = hdCriarTotem($pdo, 'HD-CLI-ALHEIO');
    $r = hdRodar($amb, 'nota.identificar', $idTotemAlheio, ['id_atendimento' => $at['id'], 'id_nota' => $n1, 'cnpjs_candidatos' => []]);
    hdAfirmar('totem alheio = 404', $r['http'] === 404);
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'cnpjs_candidatos' => []]);
    hdAfirmar('sem id_nota e sem ordem = 400 Dados incompletos', $r['http'] === 400);
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'ordem' => 9, 'cnpjs_candidatos' => []]);
    hdAfirmar('ordem fora de 1..5 = 400', $r['http'] === 400);
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => 'abc', 'cnpjs_candidatos' => []]);
    hdAfirmar('id_nota nao numerico = 400', $r['http'] === 400);

    // rate limit 429 mantido
    $janela = intdiv(time(), 60);
    $colunas = $pdo->query('SHOW COLUMNS FROM tb_rate_limit_ocr')->fetchAll(PDO::FETCH_COLUMN);
    hdAfirmar('rate limit: tabela tb_rate_limit_ocr existe (id_totem, janela, contador)', in_array('id_totem', $colunas, true) && in_array('janela', $colunas, true));
    // contador no limite nas janelas atual e seguinte (robusto a virada de janela)
    foreach ([$janela, $janela + 1] as $j) {
        $pdo->prepare('INSERT INTO tb_rate_limit_ocr (id_totem, janela, contador) VALUES (:t, :j, 30) ON DUPLICATE KEY UPDATE contador = 30')
            ->execute(['t' => $idTotem, 'j' => $j]);
    }
    $r = hdRodar($amb, 'nota.identificar', $idTotem, ['id_atendimento' => $at['id'], 'id_nota' => $n1, 'cnpjs_candidatos' => []]);
    hdAfirmar('429 do rate limit mantido (31a chamada na janela) com corpo de erro fixo', $r['http'] === 429 && ($r['corpo']['sucesso'] ?? true) === false);

    // ---------------------------------------------------------------
    hdSecao('status legado (acao=status) usa o estado derivado e ignora o JOIN sem ativo');
    $pdo->prepare('DELETE FROM tb_rate_limit_ocr')->execute();
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1);
    // legado: cliente_identificado=1 + cnpj_emitente de cliente ATIVO, mas status_ocr PENDENTE (caminho chave antigo)
    $pdo->prepare("UPDATE tb_atendimento_nota SET cliente_identificado = 1, cnpj_emitente = :c WHERE id_nota = :n")->execute(['c' => $cnpjA, 'n' => $n1]);
    $r = hdRodar($amb, 'nota.status', $idTotem, ['id_atendimento' => $at['id']]);
    hdAfirmar('status: nota com cliente_identificado=1 legado mas status_ocr PENDENTE NAO identifica mais', ($r['corpo']['dados']['cliente_identificado'] ?? null) === false);
    $rn->identificarCliente($at['id'], hdCriarNota($pdo, $at, 2), null, [$cnpjA], null);
    $r = hdRodar($amb, 'nota.status', $idTotem, ['id_atendimento' => $at['id']]);
    hdAfirmar('status: com nota IDENTIFICADA (ativo) = true', ($r['corpo']['dados']['cliente_identificado'] ?? null) === true);

    // ---------------------------------------------------------------
    hdSecao('Caminho chave desativado em processarLeitura');
    $at = $novoAtendimento();
    $chave = '35' . '2401' . $cnpjA . '55' . '001' . '000000001' . '1' . '00000001' . '0';
    $chave = substr($chave, 0, 43) . '0';
    $leitura = $rn->processarLeitura($at['id'], 1, 'nota_01.jpg', str_pad($chave, 44, '0'));
    $linha = hdNotas($pdo, $at['id'])[0];
    hdAfirmar('processarLeitura: chave nunca mais resolve cliente (cliente_identificado false, cnpj_emitente nulo)', $leitura['cliente_identificado'] === false && $leitura['cliente'] === null && (int) $linha['cliente_identificado'] === 0 && $linha['cnpj_emitente'] === null);
    hdAfirmar('processarLeitura: devolve id_nota e ordem (aditivo)', $leitura['id_nota'] === (int) $linha['id_nota'] && $leitura['ordem'] === 1);

    // ---------------------------------------------------------------
    hdSecao('D1: concluir-digitalizacao grava cliente_nome/cnpj SO com IDENTIFICADO');
    $flag = ['env' => []];
    // IDENTIFICADO
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1, '1001');
    $n2 = hdCriarNota($pdo, $at, 2, '1002');
    $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    $rn->identificarCliente($at['id'], $n2, null, [$cnpjA], null);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('D1 IDENTIFICADO: 200, rec_cnh/rec_cnh, cliente_estado IDENTIFICADO, motivo nulo', $r['http'] === 200 && $r['corpo']['dados'] === ['proxima_tela' => 'rec_cnh', 'etapa' => 'rec_cnh', 'cliente_estado' => 'IDENTIFICADO', 'cliente_motivo' => null]);
    hdAfirmar('D1 IDENTIFICADO: etapa_atual rec_cnh e cliente_nome/cliente_cnpj gravados de tb_cliente', $reg['etapa_atual'] === 'rec_cnh' && $reg['cliente_cnpj'] === $cnpjA && $reg['cliente_nome'] === 'CROMEX TINTAS LTDA');
    // repeticao nao regrava
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    hdAfirmar('D1: segunda chamada idempotente com o mesmo corpo', $r['http'] === 200 && $r['corpo']['dados']['cliente_estado'] === 'IDENTIFICADO' && $r['corpo']['dados']['etapa'] === 'rec_cnh');

    // NAO_IDENTIFICADO
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1, '2001');
    $rn->identificarCliente($at['id'], $n1, null, [], null);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('D1 NAO_IDENTIFICADO: rec_cliente/cliente e cliente NAO gravado', $r['http'] === 200 && $r['corpo']['dados'] === ['proxima_tela' => 'rec_cliente', 'etapa' => 'cliente', 'cliente_estado' => 'NAO_IDENTIFICADO', 'cliente_motivo' => null] && $reg['etapa_atual'] === 'cliente' && $reg['cliente_cnpj'] === null && $reg['cliente_nome'] === null);

    // ANOMALIA (conflito)
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1, '3001');
    $n2 = hdCriarNota($pdo, $at, 2, '3002');
    $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    $rn->identificarCliente($at['id'], $n2, null, [$cnpjB], null);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('D1 ANOMALIA (CONFLITO): rec_cliente, motivo CONFLITO, cliente NAO gravado', $r['http'] === 200 && $r['corpo']['dados'] === ['proxima_tela' => 'rec_cliente', 'etapa' => 'cliente', 'cliente_estado' => 'ANOMALIA', 'cliente_motivo' => 'CONFLITO'] && $reg['cliente_cnpj'] === null && $reg['etapa_atual'] === 'cliente');
    hdAfirmar('D1 ANOMALIA: resposta nao contem CNPJ nem nome de nenhum candidato', !str_contains($r['stdout'], $cnpjA) && !str_contains($r['stdout'], $cnpjB) && !str_contains($r['stdout'], 'CROMEX'));

    // ANOMALIA (nota em ERRO)
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1, '4001');
    $rn->identificarCliente($at['id'], $n1, null, [$cnpjA, $cnpjB], null);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    hdAfirmar('D1 ANOMALIA (nota em ERRO): motivo INDETERMINADO, rec_cliente', $r['http'] === 200 && $r['corpo']['dados']['cliente_motivo'] === 'INDETERMINADO' && $r['corpo']['dados']['proxima_tela'] === 'rec_cliente');

    // nao sobrescreve cliente manual ja gravado
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1, '5001');
    $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    $pdo->prepare('UPDATE tb_atendimento SET cliente_nome = :n, cliente_cnpj = :c WHERE id_atendimento = :id')->execute(['n' => 'MANUAL ANTERIOR', 'c' => $cnpjB, 'id' => $at['id']]);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('D1: cliente manual ja gravado NAO e sobrescrito (mesmo IDENTIFICADO)', $r['http'] === 200 && $reg['cliente_cnpj'] === $cnpjB && $reg['cliente_nome'] === 'MANUAL ANTERIOR' && $reg['etapa_atual'] === 'rec_cnh');

    // legado: OR com algumaIdentificada removido (cliente_identificado=1 sem status IDENTIFICADA)
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1, '6001');
    $pdo->prepare("UPDATE tb_atendimento_nota SET cliente_identificado = 1, cnpj_emitente = :c WHERE id_nota = :n")->execute(['c' => $cnpjA, 'n' => $n1]);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    hdAfirmar('legado: cliente_identificado=1 sem status IDENTIFICADA nao decide mais (vai a rec_cliente)', $r['http'] === 200 && $r['corpo']['dados']['etapa'] === 'cliente');

    // ---------------------------------------------------------------
    hdSecao('Validacao do cliente MANUAL (salvar-etapa cliente) contra tb_cliente ativa');
    $idUdlog = hdCriarCliente($pdo, 'UNITED LOGISTICS LTDA', $cnpjUdlog2, 0, 'UNITED LOGISTICS');
    $casosManual = [
        'cliente ativo valido'                      => [['nome' => 'NOME FORJADO DO FRONT', 'cnpj' => $cnpjA], true],
        'cliente ativo com CNPJ mascarado'          => [['nome' => 'X', 'cnpj' => substr($cnpjB, 0, 2) . '.' . substr($cnpjB, 2, 3) . '.' . substr($cnpjB, 5, 3) . '/' . substr($cnpjB, 8, 4) . '-' . substr($cnpjB, 12, 2)], true],
        'cliente inativo'                           => [['nome' => 'FANTASMA COMERCIO LTDA', 'cnpj' => $cnpjInativo], false],
        'CNPJ inexistente (transportadora)'         => [['nome' => 'TRANSPORTES RAPIDO', 'cnpj' => $cnpjTransportadora], false],
        'UDLOG (CNPJ UDLOG_1, nunca cliente ativo)' => [['nome' => 'UDLOG', 'cnpj' => $cnpjUdlog1], false],
        'UDLOG_2 mesmo cadastrada como inativa'     => [['nome' => 'UDLOG', 'cnpj' => $cnpjUdlog2], false],
        'sem CNPJ (texto livre)'                    => [['nome' => 'QUALQUER NOME DIGITADO', 'cnpj' => null], false],
        'CNPJ invalido'                             => [['nome' => 'X', 'cnpj' => '12345678000100'], false],
        'cnpj nao string'                           => [['nome' => 'X', 'cnpj' => ['a']], false],
    ];
    foreach ($casosManual as $descricao => [$dados, $esperadoOk]) {
        $at = $novoAtendimento();
        $pdo->prepare("UPDATE tb_atendimento SET etapa_atual = 'cliente' WHERE id_atendimento = :id")->execute(['id' => $at['id']]);
        $r = hdRodar($amb, 'atendimento.salvar', $idTotem, ['id_atendimento' => $at['id'], 'etapa' => 'cliente', 'dados' => $dados]);
        $reg = hdAtendimento($pdo, $at['id']);
        if ($esperadoOk) {
            hdAfirmar("manual: {$descricao} -> 200, etapa rec_cnh e nome/cnpj gravados DO BANCO (nunca do front)", $r['http'] === 200 && $reg['etapa_atual'] === 'rec_cnh' && in_array($reg['cliente_cnpj'], [$cnpjA, $cnpjB], true) && $reg['cliente_nome'] !== 'NOME FORJADO DO FRONT' && $reg['cliente_nome'] !== 'X');
        } else {
            hdAfirmar("manual: {$descricao} -> 400 generico, etapa e cliente inalterados", $r['http'] === 400 && $reg['etapa_atual'] === 'cliente' && $reg['cliente_cnpj'] === null && $reg['cliente_nome'] === null);
        }
    }
    $at = $novoAtendimento();
    $r = hdRodar($amb, 'atendimento.salvar', $idTotem, ['id_atendimento' => $at['id'], 'etapa' => 'cliente', 'dados' => ['cnpj' => $cnpjA]]);
    hdAfirmar('manual: etapa errada continua 400 (validacao de etapa antes da do cliente)', $r['http'] === 400 && hdAtendimento($pdo, $at['id'])['cliente_cnpj'] === null);
    $at = $novoAtendimento();
    $pdo->prepare("UPDATE tb_atendimento SET etapa_atual = 'cliente' WHERE id_atendimento = :id")->execute(['id' => $at['id']]);
    $r = hdRodar($amb, 'atendimento.salvar', $idTotemAlheio, ['id_atendimento' => $at['id'], 'etapa' => 'cliente', 'dados' => ['cnpj' => $cnpjA]]);
    hdAfirmar('manual: totem alheio = 404 (IDOR preservado)', $r['http'] === 404);

    // ---------------------------------------------------------------
    hdSecao('Anomalia de cliente na ponta: rec_cliente aceita a confirmacao manual (fluxo completo)');
    $at = $novoAtendimento();
    $n1 = hdCriarNota($pdo, $at, 1, '7001');
    $n2 = hdCriarNota($pdo, $at, 2, '7002');
    $rn->identificarCliente($at['id'], $n1, null, [$cnpjA], null);
    $rn->identificarCliente($at['id'], $n2, null, [$cnpjB], null);
    $r = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    $r2 = hdRodar($amb, 'atendimento.salvar', $idTotem, ['id_atendimento' => $at['id'], 'etapa' => 'cliente', 'dados' => ['cnpj' => $cnpjB]]);
    $reg = hdAtendimento($pdo, $at['id']);
    hdAfirmar('anomalia -> cliente manual confirmado -> rec_cnh com o cliente escolhido', $r['http'] === 200 && $r2['http'] === 200 && $reg['etapa_atual'] === 'rec_cnh' && $reg['cliente_cnpj'] === $cnpjB);
    $r3 = hdRodar($amb, 'atendimento.concluir', $idTotem, ['id_atendimento' => $at['id']]);
    hdAfirmar('reconcluir apos o manual continua idempotente e nao mexe no cliente manual', $r3['http'] === 200 && hdAtendimento($pdo, $at['id'])['cliente_cnpj'] === $cnpjB);
} finally {
    hdDestruirAmbiente($amb);
}

hdEncerrar('teste_hardening_cliente');
