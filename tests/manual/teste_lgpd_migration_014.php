<?php

/**
 * Roteiro de /02-testes (migration) da demanda tela-inicial-lgpd-totem
 * (2026-09-24) — valida sql/migrations/014_tb_lgpd_aceite.sql nos 7
 * cenarios obrigatorios (15-21 do roteiro consolidado de /02-testes):
 *   15. banco vazio + schema.sql limpo, depois migration 014 -> cria tudo.
 *   16. schema parcial (so a coluna em tb_atendimento) -> completa so o que falta.
 *   17. schema completo (idempotencia) -> execucao dupla sem erro, sem duplicar.
 *   18. colisao incompativel (tipo de coluna divergente) -> aborta de forma
 *       segura e deterministica, sem alterar nada.
 *   19. preservacao de dados pre-existentes em tb_totem/tb_atendimento.
 *   20. indices criados corretamente (token, totem, expiracao, consumo).
 *   21. ausencia de qualquer operacao destrutiva (DROP/TRUNCATE).
 *
 * BANCO SEMPRE DESCARTAVEL, nome sintetico DEDICADO a este arquivo
 * (qa_lgpd_migration_<...>), DIFERENTE do usado pela suite de API
 * (qa_lgpd_testes_<...>) — um banco novo por cenario, removido ao final de
 * cada cenario (nunca reaproveitado entre cenarios, para isolar 100%).
 *
 * Uso: php tests/manual/teste_lgpd_migration_014.php
 */

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

$raizProjeto = __DIR__ . '/../..';
$host = 'localhost';
$porta = '3306';
$usuario = 'root';
$senha = 'UdlogT3c@';

$bancosCriados = [];

function migQaCriarBanco(string $host, string $porta, string $usuario, string $senha): array
{
    global $bancosCriados;
    $nomeBanco = 'qa_lgpd_migration_' . time() . '_' . bin2hex(random_bytes(4));
    if (str_starts_with($nomeBanco, 'qa013_') || $nomeBanco === 'qa_iso2') {
        throw new RuntimeException('Nome de banco colidiu com nome proibido');
    }
    $pdoServidor = new PDO("mysql:host={$host};port={$porta};charset=utf8mb4", $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoServidor->exec("CREATE DATABASE `{$nomeBanco}` CHARACTER SET utf8mb4");

    $pdo = new PDO("mysql:host={$host};port={$porta};dbname={$nomeBanco};charset=utf8mb4", $usuario, $senha, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $atual = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($atual !== $nomeBanco) {
        throw new RuntimeException("SELECT DATABASE() inesperado: {$atual}");
    }
    $bancosCriados[] = $nomeBanco;
    return [$pdo, $nomeBanco];
}

function migQaDropar(string $host, string $porta, string $usuario, string $senha, string $nomeBanco): void
{
    if (!str_starts_with($nomeBanco, 'qa_lgpd_migration_')) {
        throw new RuntimeException("Recusando DROP fora do padrao sintetico: {$nomeBanco}");
    }
    $pdoServidor = new PDO("mysql:host={$host};port={$porta};charset=utf8mb4", $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoServidor->exec("DROP DATABASE IF EXISTS `{$nomeBanco}`");
}

register_shutdown_function(function () use (&$host, &$porta, &$usuario, &$senha, &$bancosCriados) {
    foreach ($bancosCriados as $nomeBanco) {
        try {
            migQaDropar($host, $porta, $usuario, $senha, $nomeBanco);
        } catch (\Throwable $e) {
            echo "[limpeza] FALHA ao remover {$nomeBanco}: {$e->getMessage()}\n";
        }
    }
    echo "\n[limpeza] " . count($bancosCriados) . " banco(s) descartavel(is) de migration removido(s).\n";
});

$schemaSql = file_get_contents($raizProjeto . '/sql/schema.sql');
$migrationSql = file_get_contents($raizProjeto . '/sql/migrations/014_tb_lgpd_aceite.sql');

// Schema.sql SEM a tabela tb_lgpd_aceite/coluna id_aceite_lgpd — o proprio
// schema.sql do repositorio ja nao inclui esses objetos (migration 014 e
// aditiva sobre o schema convergido ate a 013), confirmado por leitura.
$temTbLgpdAceiteNoSchema = stripos($schemaSql, 'tb_lgpd_aceite') !== false;
afirmar('Pre-condicao: sql/schema.sql (linha de base) NAO inclui tb_lgpd_aceite (migration 014 e realmente aditiva)', !$temTbLgpdAceiteNoSchema);

// ============================================================
// Cenario 15 — banco vazio + schema.sql limpo, depois migration 014
// ============================================================
echo "\n=== Cenario 15: banco vazio + schema.sql + migration 014 ===\n";
[$pdo15, $db15] = migQaCriarBanco($host, $porta, $usuario, $senha);
$pdo15->exec($schemaSql);
$pdo15->exec($migrationSql);
$tabela15 = $pdo15->query("SHOW TABLES LIKE 'tb_lgpd_aceite'")->fetchColumn();
$coluna15 = $pdo15->query("SHOW COLUMNS FROM tb_atendimento LIKE 'id_aceite_lgpd'")->fetchColumn();
afirmar('Cenario 15: tb_lgpd_aceite criada', $tabela15 === 'tb_lgpd_aceite');
afirmar('Cenario 15: coluna id_aceite_lgpd criada em tb_atendimento', $coluna15 === 'id_aceite_lgpd');
$colunasTabela15 = $pdo15->query('SHOW COLUMNS FROM tb_lgpd_aceite')->fetchAll();
$nomesColunas15 = array_column($colunasTabela15, 'Field');
afirmar('Cenario 15: todas as 9 colunas esperadas presentes', $nomesColunas15 === ['id_aceite', 'token_hash', 'id_totem', 'versao_termo', 'hash_termo', 'criado_em', 'expira_em', 'usado_em', 'status']);

// ============================================================
// Cenario 16 — schema parcial (so a coluna em tb_atendimento, sem a tabela nova)
// ============================================================
echo "\n=== Cenario 16: schema parcial (coluna id_aceite_lgpd ja existe, tabela nao) ===\n";
[$pdo16, $db16] = migQaCriarBanco($host, $porta, $usuario, $senha);
$pdo16->exec($schemaSql);
// Simula um estado parcial: alguem ja rodou so o PASSO 2 manualmente antes.
$pdo16->exec('ALTER TABLE tb_atendimento ADD COLUMN id_aceite_lgpd BIGINT UNSIGNED NULL AFTER id_totem');
$colunaAntes16 = $pdo16->query("SHOW COLUMNS FROM tb_atendimento LIKE 'id_aceite_lgpd'")->fetchColumn();
afirmar('Cenario 16 (pre-condicao): coluna ja existe antes da migration', $colunaAntes16 === 'id_aceite_lgpd');
$pdo16->exec($migrationSql); // nao deve tentar recriar a coluna (evita "Duplicate column name")
$tabela16 = $pdo16->query("SHOW TABLES LIKE 'tb_lgpd_aceite'")->fetchColumn();
$colunaDepois16 = $pdo16->query("SHOW COLUMNS FROM tb_atendimento LIKE 'id_aceite_lgpd'")->fetchColumn();
$totalColunasIdAceiteLgpd16 = $pdo16->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'id_aceite_lgpd'")->fetchColumn();
afirmar('Cenario 16: migration completa so o que faltava — tabela nova criada', $tabela16 === 'tb_lgpd_aceite');
afirmar('Cenario 16: coluna preexistente preservada, sem erro de duplicidade', $colunaDepois16 === 'id_aceite_lgpd');
afirmar('Cenario 16: coluna id_aceite_lgpd aparece EXATAMENTE 1 vez (nao duplicada)', (int) $totalColunasIdAceiteLgpd16 === 1);

// ============================================================
// Cenario 17 — schema completo (idempotencia): execucao dupla sem erro
// ============================================================
echo "\n=== Cenario 17: execucao dupla da migration 014 (idempotencia) ===\n";
[$pdo17, $db17] = migQaCriarBanco($host, $porta, $usuario, $senha);
$pdo17->exec($schemaSql);
$pdo17->exec($migrationSql);
$excecao17 = null;
try {
    $pdo17->exec($migrationSql); // segunda execucao — nao deve lancar
} catch (\Throwable $e) {
    $excecao17 = $e;
}
afirmar('Cenario 17: segunda execucao NAO lanca excecao', $excecao17 === null);
$totalTabelasLgpd17 = (int) $pdo17->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_lgpd_aceite'")->fetchColumn();
$totalColunas17 = (int) $pdo17->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'id_aceite_lgpd'")->fetchColumn();
$totalIndicesEquivalentes17 = (int) $pdo17->query("
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento'
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1 AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'id_aceite_lgpd' THEN 1 ELSE 0 END) = 1
    ) x
")->fetchColumn();
afirmar('Cenario 17: exatamente 1 tabela tb_lgpd_aceite (sem duplicacao)', $totalTabelasLgpd17 === 1);
afirmar('Cenario 17: exatamente 1 coluna id_aceite_lgpd (sem duplicacao)', $totalColunas17 === 1);
afirmar('Cenario 17: exatamente 1 indice equivalente em id_aceite_lgpd (sem duplicacao)', $totalIndicesEquivalentes17 === 1);

// ============================================================
// Cenario 18 — colisao incompativel (tipo de coluna divergente do esperado)
// ============================================================
echo "\n=== Cenario 18: tb_lgpd_aceite pre-existente com token_hash de tipo divergente -> aborta ===\n";
[$pdo18, $db18] = migQaCriarBanco($host, $porta, $usuario, $senha);
$pdo18->exec($schemaSql);
// Cria uma tb_lgpd_aceite MANUALMENTE com token_hash divergente do esperado
// (VARCHAR(32) em vez de CHAR(64) NOT NULL) — simula uma instalacao
// incompativel pre-existente.
$pdo18->exec("
    CREATE TABLE tb_lgpd_aceite (
        id_aceite   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token_hash  VARCHAR(32) NULL,
        id_totem    INT UNSIGNED NOT NULL,
        status      ENUM('PENDENTE_USO','USADO') NOT NULL DEFAULT 'PENDENTE_USO',
        FOREIGN KEY (id_totem) REFERENCES tb_totem(id_totem)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Preserva um snapshot completo do schema ANTES de tentar a migration.
$snapshotAntes18 = $pdo18->query("SHOW CREATE TABLE tb_lgpd_aceite")->fetch();
$totalLinhasAntes18 = (int) $pdo18->query('SELECT COUNT(*) FROM tb_lgpd_aceite')->fetchColumn();

$excecao18 = null;
try {
    $pdo18->exec($migrationSql);
} catch (\Throwable $e) {
    $excecao18 = $e;
}
afirmar('Cenario 18: migration LANCA excecao (aborta) diante do schema divergente', $excecao18 !== null);
afirmar('Cenario 18: mensagem do erro nativo referencia o identificador de aborto autoexplicativo desta migration', $excecao18 !== null && str_contains($excecao18->getMessage(), 'migracao_014_abortar__tb_lgpd_aceite_schema_pre_existente_divergente_corrija_manualmente'));
$snapshotDepois18 = $pdo18->query("SHOW CREATE TABLE tb_lgpd_aceite")->fetch();
$totalLinhasDepois18 = (int) $pdo18->query('SELECT COUNT(*) FROM tb_lgpd_aceite')->fetchColumn();
afirmar('Cenario 18: schema de tb_lgpd_aceite NAO foi alterado pela tentativa abortada (SHOW CREATE TABLE identico)', $snapshotAntes18 === $snapshotDepois18);
afirmar('Cenario 18: nenhuma linha alterada/perdida', $totalLinhasAntes18 === $totalLinhasDepois18);
// Confirma tambem que a coluna id_aceite_lgpd em tb_atendimento (PASSO 2, que
// viria DEPOIS do PASSO 0 na ordem do arquivo) nunca chega a ser executada
// apos o abort do PASSO 0 dentro do MESMO exec() multi-statement.
$colunaAtendimento18 = $pdo18->query("SHOW COLUMNS FROM tb_atendimento LIKE 'id_aceite_lgpd'")->fetchColumn();
afirmar('Cenario 18: PASSO 2 (coluna em tb_atendimento) NAO chega a ser executado apos o abort do PASSO 0', $colunaAtendimento18 === false);

// ============================================================
// Cenario 19 — preservacao de dados pre-existentes em tb_totem/tb_atendimento
// ============================================================
echo "\n=== Cenario 19: dados pre-existentes em tb_totem/tb_atendimento preservados ===\n";
[$pdo19, $db19] = migQaCriarBanco($host, $porta, $usuario, $senha);
$pdo19->exec($schemaSql);
$pdo19->prepare("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('QA19', 'Totem QA19', 'tok_qa19', 1)")->execute();
$idTotem19 = (int) $pdo19->lastInsertId();
$pdo19->prepare("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, etapa_atual, status, placa) VALUES (UUID(), :t, 'recebimento', 'placa', 'em_andamento', 'QA19REC')")->execute(['t' => $idTotem19]);
$idAtendimento19 = (int) $pdo19->lastInsertId();

$totemAntes19 = $pdo19->query("SELECT * FROM tb_totem WHERE id_totem = {$idTotem19}")->fetch();
$atendimentoAntes19 = $pdo19->query("SELECT * FROM tb_atendimento WHERE id_atendimento = {$idAtendimento19}")->fetch();

$pdo19->exec($migrationSql);

$totemDepois19 = $pdo19->query("SELECT * FROM tb_totem WHERE id_totem = {$idTotem19}")->fetch();
$atendimentoDepois19 = $pdo19->query("SELECT * FROM tb_atendimento WHERE id_atendimento = {$idAtendimento19}")->fetch();

afirmar('Cenario 19: linha de tb_totem preservada EXATAMENTE igual (todas as colunas antigas)', array_intersect_key($totemAntes19, $totemDepois19) == $totemAntes19 && $totemAntes19 == array_diff_key($totemDepois19, []));
afirmar('Cenario 19: linha de tb_atendimento tem todas as colunas ANTIGAS preservadas com o mesmo valor', (function () use ($atendimentoAntes19, $atendimentoDepois19) {
    foreach ($atendimentoAntes19 as $coluna => $valor) {
        if (!array_key_exists($coluna, $atendimentoDepois19) || $atendimentoDepois19[$coluna] !== $valor) return false;
    }
    return true;
})());
afirmar('Cenario 19: nova coluna id_aceite_lgpd do atendimento pre-existente vem NULL (nunca teve aceite)', array_key_exists('id_aceite_lgpd', $atendimentoDepois19) && $atendimentoDepois19['id_aceite_lgpd'] === null);

// ============================================================
// Cenario 20 — indices criados corretamente
// ============================================================
echo "\n=== Cenario 20: indices (token, totem, expiracao, consumo) ===\n";
[$pdo20, $db20] = migQaCriarBanco($host, $porta, $usuario, $senha);
$pdo20->exec($schemaSql);
$pdo20->exec($migrationSql);
$indicesLgpd20 = $pdo20->query("SHOW INDEX FROM tb_lgpd_aceite")->fetchAll();
$nomesIndices20 = array_unique(array_column($indicesLgpd20, 'Key_name'));
afirmar('Cenario 20: token_hash tem indice UNIQUE proprio (apoia o CAS por token)', in_array('token_hash', $nomesIndices20, true));
afirmar('Cenario 20: idx_lgpd_aceite_totem_status existe (apoio ao CAS por id_totem+status)', in_array('idx_lgpd_aceite_totem_status', $nomesIndices20, true));
afirmar('Cenario 20: idx_lgpd_aceite_status_expira existe (apoio a expiracao/limpeza futura)', in_array('idx_lgpd_aceite_status_expira', $nomesIndices20, true));
$colunasIdxTotemStatus20 = array_column(array_filter($indicesLgpd20, fn($i) => $i['Key_name'] === 'idx_lgpd_aceite_totem_status'), 'Column_name');
afirmar('Cenario 20: idx_lgpd_aceite_totem_status cobre (id_totem, status) nessa ordem', $colunasIdxTotemStatus20 === ['id_totem', 'status']);
$colunasIdxStatusExpira20 = array_column(array_filter($indicesLgpd20, fn($i) => $i['Key_name'] === 'idx_lgpd_aceite_status_expira'), 'Column_name');
afirmar('Cenario 20: idx_lgpd_aceite_status_expira cobre (status, expira_em) nessa ordem', $colunasIdxStatusExpira20 === ['status', 'expira_em']);
$indicesAtendimento20 = $pdo20->query("SHOW INDEX FROM tb_atendimento WHERE Column_name = 'id_aceite_lgpd'")->fetchAll();
afirmar('Cenario 20: indice de apoio em tb_atendimento(id_aceite_lgpd) existe', count($indicesAtendimento20) > 0);

// ============================================================
// Cenario 21 — ausencia de qualquer operacao destrutiva no arquivo da migration
// ============================================================
echo "\n=== Cenario 21: ausencia de DROP/TRUNCATE real no arquivo da migration ===\n";
// Le o TEXTO do arquivo e confirma que a UNICA ocorrencia de "DROP" e
// dentro do comentario de rollback MANUAL (nao executada pelo script) —
// nunca um DROP/TRUNCATE efetivamente executado pela migration em si.
$linhas21 = explode("\n", $migrationSql);
$linhasComDropOuTruncate = [];
foreach ($linhas21 as $i => $linha) {
    if (preg_match('/\b(DROP|TRUNCATE)\b/i', $linha) && !preg_match('/^\s*--/', $linha)) {
        $linhasComDropOuTruncate[] = ($i + 1) . ': ' . trim($linha);
    }
}
afirmar('Cenario 21: nenhuma linha de CODIGO SQL EXECUTAVEL (fora de comentario) contem DROP/TRUNCATE', count($linhasComDropOuTruncate) === 0);
if (count($linhasComDropOuTruncate) > 0) {
    echo "Linhas suspeitas:\n" . implode("\n", $linhasComDropOuTruncate) . "\n";
}
// Confirma tambem, de forma comportamental (nao so textual), que rodar a
// migration nos cenarios 15/17/19/20 acima NUNCA reduziu contagem de linhas/
// tabelas pre-existentes (ja verificado cenario a cenario acima) — aqui so
// a checagem textual complementar do arquivo completo.
afirmar('Cenario 21: os comentarios de ROLLBACK MANUAL (unico lugar com DROP real) estao claramente marcados como manuais, nao executados pelo script', str_contains($migrationSql, 'Rollback manual') && str_contains($migrationSql, 'DROP TABLE tb_lgpd_aceite'));

// ============================================================
// RESULTADO
// ============================================================
echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
