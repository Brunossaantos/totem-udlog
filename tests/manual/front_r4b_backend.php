<?php

/**
 * Apoio do teste front_r4b_real.js (hardening-revisao-notas-e-cliente, rodada
 * corretiva FRONT fase B, 2026-10-01). Somente CLI. NAO toca em udlog_totem:
 * so cria/consulta/dropa banco `qa_r4fb_*` DESCARTAVEL com STORAGE_PATH temporario.
 *
 *   php front_r4b_backend.php setup                      -> JSON {banco, storage, token, id_atendimento}
 *   php front_r4b_backend.php novo-atendimento BANCO ST ID_TOTEM PLACA -> JSON {id_atendimento}
 *   php front_r4b_backend.php notas BANCO ID_ATEND       -> JSON {linhas:[...], atendimento:{...}}
 *   php front_r4b_backend.php teardown BANCO STORAGE     -> remove tudo (so prefixo qa_r4fb_)
 *
 * Nenhuma saida inclui imagem, caminho absoluto ou dado pessoal (so contagens,
 * ids, ordens, uid de teste e status).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/hardening_helpers.php';

$acao = $argv[1] ?? '';

function r4fbPdoDoBanco(string $banco): PDO
{
    if (strpos($banco, 'qa_r4fb_') !== 0) {
        fwrite(STDERR, "banco recusado (prefixo)\n");
        exit(2);
    }
    $admin = qaDbConexaoAdmin();
    $admin->exec("USE `{$banco}`");
    $admin->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($admin->query('SELECT DATABASE()')->fetchColumn() !== $banco) {
        fwrite(STDERR, "USE falhou\n");
        exit(2);
    }

    return $admin;
}

if ($acao === 'setup') {
    $amb = hdCriarAmbiente('r4fb');
    $idTotem = hdCriarTotem($amb['pdo'], 'R4FB');
    $token = (string) $amb['pdo']->query('SELECT token_api FROM tb_totem WHERE id_totem = ' . $idTotem)->fetchColumn();
    $at = hdCriarAtendimento($amb, $idTotem, 'FBR1A23');
    echo json_encode([
        'banco' => $amb['banco'], 'storage' => $amb['storage'], 'dir_tmp' => $amb['dir_tmp'],
        'id_totem' => $idTotem, 'token' => $token, 'id_atendimento' => $at['id'],
    ]), "\n";
    exit(0);
}

if ($acao === 'novo-atendimento') {
    [$banco, $storage, $idTotem, $placa] = [$argv[2] ?? '', $argv[3] ?? '', (int) ($argv[4] ?? 0), $argv[5] ?? 'FBR2A23'];
    $pdo = r4fbPdoDoBanco($banco);
    $at = hdCriarAtendimento(['pdo' => $pdo, 'storage' => $storage], $idTotem, $placa);
    echo json_encode(['id_atendimento' => $at['id']]), "\n";
    exit(0);
}

if ($acao === 'notas') {
    $banco = $argv[2] ?? '';
    $id = (int) ($argv[3] ?? 0);
    $pdo = r4fbPdoDoBanco($banco);
    $stmt = $pdo->prepare('SELECT id_nota, ordem, client_uid, status_ocr, (numero_nota IS NOT NULL) AS tem_numero FROM tb_atendimento_nota WHERE id_atendimento = :i ORDER BY ordem');
    $stmt->execute(['i' => $id]);
    $linhas = $stmt->fetchAll();
    $at = $pdo->prepare('SELECT etapa_atual, status, cliente_cnpj FROM tb_atendimento WHERE id_atendimento = :i');
    $at->execute(['i' => $id]);
    echo json_encode(['linhas' => $linhas, 'atendimento' => $at->fetch()]), "\n";
    exit(0);
}

if ($acao === 'teardown') {
    [$banco, $storage] = [$argv[2] ?? '', $argv[3] ?? ''];
    if (strpos($banco, 'qa_r4fb_') !== 0) {
        fwrite(STDERR, "banco recusado (prefixo)\n");
        exit(2);
    }
    if ($storage !== '' && strpos(basename($storage), 'totem_hd_') === 0) {
        hdRemoverDiretorio($storage);
        hdRemoverDiretorio($storage . '_tmp');
    }
    qaDbDropar($banco);
    echo json_encode(['dropado' => $banco]), "\n";
    exit(0);
}

fwrite(STDERR, "acao desconhecida\n");
exit(1);
