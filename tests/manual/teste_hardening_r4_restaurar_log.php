<?php

/**
 * Suite F4 (rodada corretiva /01, 2026-10-01) da demanda
 * hardening-revisao-notas-e-cliente: falha de NotaArquivoStorage::restaurar()
 * na compensacao do excluir e registrada com contexto fixo sanitizado (sem
 * caminho, nome original, excecao bruta, trace ou dado da nota) e preserva a
 * consistencia banco/filesystem (ROLLBACK + foto em quarentena, recuperavel
 * por um novo excluir).
 *
 * Falha REAL de restaurar (rename de volta bloqueado por um diretorio criado no
 * caminho original) e falha controlada (injecao), combinadas com falha real do
 * DELETE (injecao no DAO). Banco `qa_` descartavel (qa_r4be_) + STORAGE_PATH
 * temporario. Sem Talent/VIO/impressao.
 *
 * Uso: php tests/manual/teste_hardening_r4_restaurar_log.php
 */

require_once __DIR__ . '/hardening_helpers.php';

use Util\NotaArquivoStorage;

$amb = hdCriarAmbiente('r4be_restaurar');
$pdo = $amb['pdo'];

try {
    $idTotem = hdCriarTotem($pdo, 'R4-RST');

    $excluir = static fn(array $at, int $idNota, array $opc = []) => hdRodar($GLOBALS['amb_r4'], 'nota.excluir', $GLOBALS['totem_r4'], ['id_atendimento' => $at['id'], 'id_nota' => $idNota], $opc);
    $GLOBALS['amb_r4'] = $amb;
    $GLOBALS['totem_r4'] = $idTotem;

    $regexVazamento = static function (array $at, string $arquivo): string {
        return '/' . preg_quote(substr($at['pasta'], 11), '/') . '|' . preg_quote($arquivo, '/') . '|SENTINELA_|SQLSTATE|Stack trace|#\d+ |\.php|\.del|' . preg_quote(sys_get_temp_dir(), '/') . '/i';
    };

    // ---------------------------------------------------------------
    hdSecao('R1 falha REAL de restaurar (rename de volta bloqueado) + falha real do DELETE');
    $at = hdCriarAtendimento($amb, $idTotem, 'RST1A23');
    $n1 = hdCriarNota($pdo, $at, 1, '1001');
    $bytes = file_get_contents($at['dir'] . '/nota_01.jpg');
    $r = $excluir($at, $n1, ['injecao' => 'dao_delete_falha,storage_restaurar_bloqueada']);
    hdAfirmar('R1: a exclusao falha com HTTP 500 e mensagem fixa', $r['http'] === 500 && ($r['corpo']['erro'] ?? '') === 'Nao foi possivel excluir a nota');
    hdAfirmar('R1: a falha do restaurar foi REGISTRADA (antes passava em silencio)', str_contains($r['log'], 'excluir-nota: FALHA ao restaurar a foto da quarentena apos rollback'));
    hdAfirmar('R1: log com contexto fixo e SO ids inteiros (id_atendimento e id_nota)', str_contains($r['log'], "id_atendimento={$at['id']} id_nota={$n1}"));
    hdAfirmar('R1: log SEM caminho, nome do arquivo, pasta/placa, excecao bruta, trace nem extensao', !preg_match($regexVazamento($at, 'nota_01'), $r['log']));
    hdAfirmar('R1: resposta ao totem tambem sem caminho/excecao', !preg_match($regexVazamento($at, 'nota_01'), $r['stdout']));
    hdAfirmar('R1: banco consistente: ROLLBACK, a linha da nota continua existindo', count(hdNotas($pdo, $at['id'])) === 1);
    $arqs = hdArquivosDaPasta($at);
    hdAfirmar('R1: filesystem consistente: a foto esta INTEIRA na quarentena (nao perdida, nao duplicada)', in_array("nota_01.jpg.{$n1}.del", $arqs, true) && file_get_contents($at['dir'] . "/nota_01.jpg.{$n1}.del") === $bytes);
    hdAfirmar('R1: o bloqueio (diretorio no caminho original) e a unica outra entrada', is_dir($at['dir'] . '/nota_01.jpg') && count($arqs) === 2);

    // remove o bloqueio (o impedimento externo foi resolvido) e repete: o excluir reconhece a quarentena
    rmdir($at['dir'] . '/nota_01.jpg');
    $r = $excluir($at, $n1);
    hdAfirmar('R1: novo excluir (sem falha) conclui: 200 excluida', $r['http'] === 200 && ($r['corpo']['dados']['excluida'] ?? null) === true);
    hdAfirmar('R1: linha removida e .del apagado apos o COMMIT (sem orfao)', count(hdNotas($pdo, $at['id'])) === 0 && hdArquivosDaPasta($at) === []);

    // ---------------------------------------------------------------
    hdSecao('R2 falha controlada de restaurar (devolve false) + falha do DELETE');
    $at2 = hdCriarAtendimento($amb, $idTotem, 'RST2A23');
    $n2 = hdCriarNota($pdo, $at2, 3, '2003');
    $r = $excluir($at2, $n2, ['injecao' => 'dao_delete_falha,storage_restaurar_falha']);
    hdAfirmar('R2: 500 e log fixo de falha do restaurar', $r['http'] === 500 && str_contains($r['log'], 'FALHA ao restaurar a foto da quarentena'));
    hdAfirmar('R2: sem caminho, nome, pasta nem excecao no log', !preg_match($regexVazamento($at2, 'nota_03'), $r['log']));
    hdAfirmar('R2: nota preservada no banco e foto em quarentena', count(hdNotas($pdo, $at2['id'])) === 1 && in_array("nota_03.jpg.{$n2}.del", hdArquivosDaPasta($at2), true));
    $r = $excluir($at2, $n2);
    hdAfirmar('R2: recuperavel: novo excluir conclui e nao deixa orfao', $r['http'] === 200 && count(hdNotas($pdo, $at2['id'])) === 0 && hdArquivosDaPasta($at2) === []);

    // ---------------------------------------------------------------
    hdSecao('R3 controle: restaurar OK = foto devolvida e NENHUM log de falha');
    $at3 = hdCriarAtendimento($amb, $idTotem, 'RST3A23');
    $n3 = hdCriarNota($pdo, $at3, 2, '3002');
    $bytes3 = file_get_contents($at3['dir'] . '/nota_02.jpg');
    $r = $excluir($at3, $n3, ['injecao' => 'dao_delete_falha']);
    hdAfirmar('R3: DELETE falhou (500), foto DEVOLVIDA ao lugar e nota preservada', $r['http'] === 500 && hdArquivosDaPasta($at3) === ['nota_02.jpg'] && file_get_contents($at3['dir'] . '/nota_02.jpg') === $bytes3 && count(hdNotas($pdo, $at3['id'])) === 1);
    hdAfirmar('R3: sem log de falha de restauracao quando a restauracao funciona', !str_contains($r['log'], 'FALHA ao restaurar'));
    hdAfirmar('R3: log sem a mensagem bruta da excecao do DAO (sentinela)', !str_contains($r['log'], 'SENTINELA_DELETE_SECRETA'));

    // ---------------------------------------------------------------
    hdSecao('R4 restaurar() em si: false de verdade quando o original esta ocupado por um diretorio');
    $dirU = $amb['storage'] . DIRECTORY_SEPARATOR . 'unit_restaurar';
    mkdir($dirU, 0777, true);
    $st = new NotaArquivoStorage($amb['storage']);
    $caminho = $dirU . DIRECTORY_SEPARATOR . 'nota_01.jpg';
    file_put_contents($caminho . '.77.del', 'foto');
    mkdir($caminho);
    hdAfirmar('R4: rename de volta sobre um diretorio existente falha de verdade: restaurar() = false', $st->restaurar($caminho, 77) === false);
    hdAfirmar('R4: o .del continua intacto depois da falha', file_get_contents($caminho . '.77.del') === 'foto');
    rmdir($caminho);
    hdAfirmar('R4: sem o bloqueio, restaurar() = true e a foto volta', $st->restaurar($caminho, 77) === true && file_get_contents($caminho) === 'foto' && !file_exists($caminho . '.77.del'));
    hdAfirmar('R4: restaurar() sem .del e sem original = false (nada a restaurar)', $st->restaurar($dirU . DIRECTORY_SEPARATOR . 'nota_04.jpg', 5) === false);
    hdAfirmar('R4: restaurar() com o original ja no lugar = true (idempotente)', $st->restaurar($caminho, 77) === true);

    // ---------------------------------------------------------------
    hdSecao('R5 logs de todos os subprocessos sem dado pessoal');
    $todos = hdTodosLogs();
    foreach (['SENTINELA_', 'data:image', 'base64', '.jpg', '.del', 'RST1A23', 'RST2A23', 'RST3A23'] as $s) {
        hdAfirmar('R5: log nao contem ' . $s, !str_contains($todos, $s));
    }
    hdAfirmar('R5: nenhum warning/notice/fatal nos logs do PHP', !preg_match('/Warning|Notice|Deprecated|Fatal|Stack trace/i', $todos));
} finally {
    hdDestruirAmbiente($amb);
}

hdEncerrar('teste_hardening_r4_restaurar_log');
