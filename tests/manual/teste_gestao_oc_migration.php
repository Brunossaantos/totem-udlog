<?php

/**
 * Teste da migration EXTERNA 004 (indices da tela de Ordens de Coleta, F4a,
 * 2026-10-08) em banco QA externo descartavel (qa_qr_exclusivo_<hex>, schema do
 * dump real + migrations 001-003; ver qa_gestao_oc_infra.php). NUNCA o banco
 * externo real nem udlog_totem. Confirma: cria so o que falta, NAO duplica
 * indice equivalente (idx_ordens_numero_ordem ja existe no schema real),
 * idempotente (2x e 3x), nao altera dados, e o EXPLAIN passa a usar os indices.
 *
 * Uso: php tests/manual/teste_gestao_oc_migration.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_oc_infra.php';

$total = 0;
$falhas = 0;
function afirmar(string $d, bool $c): void
{
    global $total, $falhas;
    $total++;
    echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n";
    if (!$c) {
        $falhas++;
    }
}

/** @return array<string,string> nome do indice => colunas em ordem ("a,b") */
function indices(PDO $pdo): array
{
    $saida = [];
    foreach ($pdo->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_ordens_coleta' GROUP BY INDEX_NAME")->fetchAll() as $l) {
        $saida[(string) $l['INDEX_NAME']] = (string) $l['cols'];
    }
    ksort($saida);

    return $saida;
}

function checksum(PDO $pdo): string
{
    return (string) $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(id),0), ':', MD5(GROUP_CONCAT(CONCAT_WS('|', id, numero_ordem_coleta, cliente_id, status, COALESCE(inativada_em,''), criado_em, atualizado_em) ORDER BY id SEPARATOR ';'))) FROM tb_ordens_coleta")->fetchColumn();
}

/** key escolhida pelo EXPLAIN (primeira linha) */
function chave(PDO $pdo, string $sql): ?string
{
    $l = $pdo->query('EXPLAIN ' . $sql)->fetch();

    return $l['key'] ?? null;
}

/** A004: cada ADD INDEX com ALGORITHM=INPLACE, LOCK=NONE e timeouts de sessao ANTES do primeiro ALTER. */
function migrationGuardada(string $sql): bool
{
    $exec = implode("
", array_filter(explode("
", $sql), static fn (string $l): bool => preg_match('/^\s*--/', $l) !== 1));
    $adds = preg_match_all("/ADD INDEX \w+ \([\w, ]+\), ALGORITHM=INPLACE, LOCK=NONE'/", $exec);
    $pAlter = strpos($exec, 'ALTER TABLE');

    return $adds === 3 && substr_count($exec, 'ADD INDEX') === 3
        && substr_count($exec, 'ALGORITHM=INPLACE') === 3 && substr_count($exec, 'LOCK=NONE') === 3
        && $pAlter !== false
        && preg_match('/^SET SESSION lock_wait_timeout = 5;/m', $exec, $m1, PREG_OFFSET_CAPTURE) === 1 && $m1[0][1] < $pAlter
        && preg_match('/^SET SESSION innodb_lock_wait_timeout = 5;/m', $exec, $m2, PREG_OFFSET_CAPTURE) === 1 && $m2[0][1] < $pAlter;
}

$amb = null;
$bancos = [];
register_shutdown_function(static function () use (&$bancos): void {
    foreach ($bancos as $b) {
        qaQrDroparBanco($b);
    }
});

try {
    $amb = ogCriarAmbiente(false); // 001-003 aplicadas, 004 NAO
    $bancos = [$amb['banco_totem'], $amb['banco_externo']];
    $ext = $amb['externo'];
    $mig = dirname(__DIR__, 2) . '/sql/migrations_gestao_coletas/004_indices_gestao_oc.sql';
    $sql004 = (string) file_get_contents($mig);

    // ----- estatica da migration
    $executavel = implode("\n", array_filter(explode("\n", $sql004), static fn (string $l): bool => preg_match('/^\s*--/', $l) !== 1));
    afirmar('migration 004 existe e so tem DDL de ADD INDEX (nenhum DROP/DELETE/TRUNCATE/UPDATE/INSERT/CREATE nas linhas executaveis)',
        $sql004 !== '' && !preg_match('/\b(DROP|DELETE|TRUNCATE|UPDATE|INSERT|CREATE|RENAME)\b/i', $executavel) && substr_count($executavel, 'ADD INDEX') === 3);
    afirmar('migration 004: aviso do banco externo, reversao documentada em comentario e nomes dos 3 indices',
        str_contains($sql004, 'NUNCA deve ser executado contra o banco do totem') && substr_count($sql004, 'DROP INDEX') === 3
        && str_contains($sql004, 'idx_oc_status_criado') && str_contains($sql004, 'idx_oc_status_inativada') && str_contains($sql004, 'idx_oc_numero')
        && preg_match('/^\s*--\s+ALTER TABLE tb_ordens_coleta DROP INDEX/m', $sql004) === 1);
    afirmar('migration 004: padrao idempotente (INFORMATION_SCHEMA + PREPARE) e sem referencia a banco real', substr_count($sql004, 'PREPARE stmt FROM @sql') === 3 && substr_count($sql004, 'INFORMATION_SCHEMA.STATISTICS') >= 6 && !preg_match('/\bUSE\s+/i', $executavel));

    afirmar('A4: 3 ADD INDEX com ALGORITHM=INPLACE, LOCK=NONE e SET SESSION lock_wait_timeout/innodb_lock_wait_timeout = 5 antes do primeiro ALTER', migrationGuardada($sql004));
    afirmar('A4 mutante: sem ALGORITHM=INPLACE e detectado', !migrationGuardada(str_replace(', ALGORITHM=INPLACE', '', $sql004)));
    afirmar('A4 mutante: sem LOCK=NONE e detectado', !migrationGuardada(str_replace(', LOCK=NONE', '', $sql004)));
    afirmar('A4 mutante: sem SET SESSION lock_wait_timeout e detectado', !migrationGuardada(str_replace('SET SESSION lock_wait_timeout = 5;', '', $sql004)));
    afirmar('A4 mutante: sem innodb_lock_wait_timeout e detectado', !migrationGuardada(str_replace('SET SESSION innodb_lock_wait_timeout = 5;', '', $sql004)));
    afirmar('A4: reversao continua so em comentario (3 DROP INDEX comentados) e MySQL 5.7 (sem sintaxe so-MariaDB: IF NOT EXISTS/INSTANT)', !preg_match('/IF NOT EXISTS|ALGORITHM=INSTANT/i', $executavel));

    // ----- estado ANTES (schema real + 001-003)
    $antes = indices($ext);
    afirmar('antes: indices reais presentes (uk_ordem_cliente, idx_ordens_numero_ordem, idx_ordens_placa_status...) e nenhum idx_oc_*',
        ($antes['uk_ordem_cliente'] ?? '') === 'cliente_id,numero_ordem_coleta' && ($antes['idx_ordens_numero_ordem'] ?? '') === 'numero_ordem_coleta' && isset($antes['idx_ordens_placa_status'])
        && !preg_grep('/^idx_oc_/', array_keys($antes)));

    // massa para o EXPLAIN: 30.000 linhas, 3% INATIVA
    $cli = ogCliente($ext, '10101010000110', 'MASSA');
    $ext->exec('CREATE TABLE digitos (i TINYINT NOT NULL)');
    $ext->exec('INSERT INTO digitos VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)');
    $ext->exec("INSERT INTO tb_ordens_coleta (numero_ordem_coleta, cliente_id, email_recebido_id, status, inativada_em, criado_em)
        SELECT CONCAT('MASSA-', a.i, b.i, c.i, d.i, e.i), $cli, 1,
               IF(MOD(a.i * 10000 + b.i * 1000 + c.i * 100 + d.i * 10 + e.i, 33) = 0, 'INATIVA', 'ATIVA'),
               IF(MOD(a.i * 10000 + b.i * 1000 + c.i * 100 + d.i * 10 + e.i, 33) = 0, DATE_SUB(NOW(), INTERVAL (a.i * 10000 + b.i * 1000 + c.i * 100 + d.i * 10 + e.i) MINUTE), NULL),
               DATE_SUB(NOW(), INTERVAL (a.i * 10000 + b.i * 1000 + c.i * 100 + d.i * 10 + e.i) MINUTE)
        FROM digitos a, digitos b, digitos c, digitos d, digitos e LIMIT 30000");
    $ext->query('ANALYZE TABLE tb_ordens_coleta')->fetchAll();
    $sqlAtivas = "SELECT oc.id FROM tb_ordens_coleta oc WHERE oc.status = 'ATIVA' ORDER BY oc.criado_em DESC, oc.id DESC LIMIT 25";
    $sqlInativas = "SELECT oc.id FROM tb_ordens_coleta oc WHERE oc.status = 'INATIVA' ORDER BY oc.inativada_em DESC, oc.id DESC LIMIT 25";
    $sqlNumero = "SELECT oc.id FROM tb_ordens_coleta oc WHERE oc.numero_ordem_coleta LIKE 'MASSA-0001%' LIMIT 25";
    $cs0 = checksum($ext);
    $kAntesAtivas = chave($ext, $sqlAtivas);
    $kAntesInativas = chave($ext, $sqlInativas);
    afirmar('antes: o EXPLAIN das listas NAO usa os indices novos (ainda nao existem)', !in_array($kAntesAtivas, ['idx_oc_status_criado'], true) && !in_array($kAntesInativas, ['idx_oc_status_inativada'], true));

    // ----- aplica 1x
    ocQaAplicarMigration($ext, $mig);
    $d1 = indices($ext);
    afirmar('1a aplicacao: cria idx_oc_status_criado (status,criado_em) e idx_oc_status_inativada (status,inativada_em)', ($d1['idx_oc_status_criado'] ?? '') === 'status,criado_em' && ($d1['idx_oc_status_inativada'] ?? '') === 'status,inativada_em');
    afirmar('1a aplicacao: NAO cria idx_oc_numero (idx_ordens_numero_ordem ja cobre numero_ordem_coleta) => sem indice duplicado', !isset($d1['idx_oc_numero']) && count(array_keys($d1, 'numero_ordem_coleta', true)) === 1);
    $preservados = array_diff_key($d1, ['idx_oc_status_criado' => 1, 'idx_oc_status_inativada' => 1]);
    afirmar('1a aplicacao: todos os indices anteriores preservados e identicos', $preservados === $antes);
    afirmar('1a aplicacao: dados identicos (nada lido/alterado/apagado)', checksum($ext) === $cs0);

    // ----- aplica 2x e 3x (idempotencia)
    ocQaAplicarMigration($ext, $mig);
    ocQaAplicarMigration($ext, $mig);
    afirmar('2a e 3a aplicacao: mesmos indices (sem erro, sem duplicar, sem alterar)', indices($ext) === $d1 && checksum($ext) === $cs0);

    // ----- EXPLAIN usando os indices
    $ext->query('ANALYZE TABLE tb_ordens_coleta')->fetchAll();
    $kAtivas = chave($ext, $sqlAtivas);
    $kInativas = chave($ext, $sqlInativas);
    $kNumero = chave($ext, $sqlNumero);
    afirmar('EXPLAIN aba ativas (status=ATIVA ORDER BY criado_em, id LIMIT 25) usa idx_oc_status_criado (key=' . var_export($kAtivas, true) . ')', $kAtivas === 'idx_oc_status_criado');
    afirmar('EXPLAIN aba inativas (status=INATIVA ORDER BY inativada_em, id LIMIT 25) usa idx_oc_status_inativada (key=' . var_export($kInativas, true) . ')', $kInativas === 'idx_oc_status_inativada');
    afirmar('EXPLAIN busca por prefixo do numero usa o indice existente idx_ordens_numero_ordem (key=' . var_export($kNumero, true) . ')', $kNumero === 'idx_ordens_numero_ordem');
    $extra = $ext->query('EXPLAIN ' . $sqlAtivas)->fetch()['Extra'] ?? '';
    afirmar('EXPLAIN ativas sem filesort (ordenacao pelo indice)', !str_contains((string) $extra, 'filesort'));
    $extraAntes = '';
    $ext->exec('ALTER TABLE tb_ordens_coleta DROP INDEX idx_oc_status_criado'); // so no QA, para comparar
    $extraAntes = (string) ($ext->query('EXPLAIN ' . $sqlAtivas)->fetch()['Extra'] ?? '');
    afirmar('contraprova: sem o indice a mesma consulta usa filesort', str_contains($extraAntes, 'filesort'));
    ocQaAplicarMigration($ext, $mig); // recria
    afirmar('apos recriar pela migration (mesmo nome/colunas)', (indices($ext)['idx_oc_status_criado'] ?? '') === 'status,criado_em');

    // ----- cria idx_oc_numero SO se nenhum indice cobre o numero
    $ext->exec('ALTER TABLE tb_ordens_coleta DROP INDEX idx_ordens_numero_ordem'); // simula banco sem o indice (so QA)
    ocQaAplicarMigration($ext, $mig);
    $d2 = indices($ext);
    afirmar('sem indice de numero: a migration cria idx_oc_numero (numero_ordem_coleta)', ($d2['idx_oc_numero'] ?? '') === 'numero_ordem_coleta');
    ocQaAplicarMigration($ext, $mig);
    afirmar('reaplicar: idx_oc_numero nao duplica', indices($ext) === $d2 && count(array_keys(indices($ext), 'numero_ordem_coleta', true)) === 1);

    // ----- nome ja existente com outras colunas: nao aborta e nao sobrescreve
    $ext->exec('ALTER TABLE tb_ordens_coleta DROP INDEX idx_oc_status_inativada');
    $ext->exec('ALTER TABLE tb_ordens_coleta ADD INDEX idx_oc_status_inativada (placa_prevista)');
    $e = null;
    try {
        ocQaAplicarMigration($ext, $mig);
    } catch (Throwable $t) {
        $e = $t;
    }
    afirmar('indice com o MESMO NOME e outras colunas: migration nao aborta e nao o altera', $e === null && (indices($ext)['idx_oc_status_inativada'] ?? '') === 'placa_prevista');
    // indice equivalente MAIS LARGO com outro nome: nao cria o idx_oc_status_inativada
    $ext->exec('ALTER TABLE tb_ordens_coleta DROP INDEX idx_oc_status_inativada');
    $ext->exec('ALTER TABLE tb_ordens_coleta ADD INDEX idx_outro_nome (status, inativada_em, id)');
    ocQaAplicarMigration($ext, $mig);
    $d3 = indices($ext);
    afirmar('indice equivalente (mesmas colunas iniciais) com outro nome: NAO cria duplicata', !isset($d3['idx_oc_status_inativada']) && ($d3['idx_outro_nome'] ?? '') === 'status,inativada_em,id');
    afirmar('dados continuam identicos apos todas as aplicacoes', checksum($ext) === $cs0);

    // ----- uso real dos indices pelo DAO (mesma consulta da lista, sem erro)
    $dao = new App\Dao\OrdemColetaGestaoDao($ext);
    $t0 = microtime(true);
    $pg = $dao->listar([], 'ativas', 1);
    $pgI = $dao->listar([], 'inativas', 1);
    $ms = (microtime(true) - $t0) * 1000;
    afirmar('DAO sobre 30.000 linhas com os indices: paginas de 25, rapido (' . (int) $ms . ' ms)', count($pg) === 25 && count($pgI) === 25 && $ms < 3000);
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ': ' . substr($e->getMessage(), 0, 200) . ')', false);
}

echo "\n=== RESULTADO teste_gestao_oc_migration: {$total} verificacoes, " . ($total - $falhas) . " passaram, {$falhas} falharam ===\n";
exit($falhas > 0 ? 1 : 0);
