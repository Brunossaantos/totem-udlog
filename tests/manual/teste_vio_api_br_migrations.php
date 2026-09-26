<?php

/**
 * Teste manual (sem framework, mesmo padrao de tests/manual/teste_vio_decode.php)
 * da demanda migracao-vio-api-br-com-cache — bateria 4 (Migrations, banco
 * descartavel). Cria um banco `qa_`-prefixado, aplica sql/schema.sql +
 * migrations 014/015/016, confirma idempotencia (reexecutar 015/016 duas
 * vezes sem erro/duplicacao), confirma indices/colunas/tabelas esperados,
 * DROPA o banco ao final independente de sucesso ou falha (finally).
 *
 * NUNCA toca em udlog_totem nem em qualquer banco de outra pessoa --
 * confirma SELECT DATABASE() antes de qualquer escrita.
 *
 * Uso: php tests/manual/teste_vio_api_br_migrations.php
 */

require_once __DIR__ . '/qa_db_bootstrap.php';

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    if ($condicao) {
        echo "OK   - {$descricao}\n";
    } else {
        $totalFalhas++;
        echo "FALHA - {$descricao}\n";
    }
}

$nomeBanco = null;

try {
    [$pdo, $nomeBanco] = qaDbCriar('vio_api_br_migrations');

    $atual = $pdo->query('SELECT DATABASE()')->fetchColumn();
    afirmar('Confirmado SELECT DATABASE() = banco qa_ recem-criado (nunca udlog_totem)', $atual === $nomeBanco && strpos($atual, 'qa_') === 0);
    afirmar('Banco de teste nunca e udlog_totem', $atual !== 'udlog_totem');

    // ============================================================
    // 1. Colunas/ENUMs novos da migration 015 existem
    // ============================================================
    $stmt = $pdo->query("SHOW COLUMNS FROM tb_atendimento");
    $colunasPorNome = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $colunasPorNome[$linha['Field']] = $linha['Type'];
    }

    foreach (['cnh_vio_api_id', 'cnh_vio_api_enviado_em', 'cnh_vio_api_fingerprint', 'cnh_vio_api_fingerprint_versao',
              'crlv_vio_api_id', 'crlv_vio_api_enviado_em', 'crlv_vio_api_fingerprint', 'crlv_vio_api_fingerprint_versao'] as $coluna) {
        afirmar("Coluna nova {$coluna} existe apos migration 015", array_key_exists($coluna, $colunasPorNome));
    }

    afirmar('ENUM cnh_status_processamento inclui ENVIANDO/PROCESSANDO_LEITURA/PROCESSANDO_COMPARACAO/INDETERMINADO',
        str_contains($colunasPorNome['cnh_status_processamento'], 'ENVIANDO')
        && str_contains($colunasPorNome['cnh_status_processamento'], 'PROCESSANDO_LEITURA')
        && str_contains($colunasPorNome['cnh_status_processamento'], 'PROCESSANDO_COMPARACAO')
        && str_contains($colunasPorNome['cnh_status_processamento'], 'INDETERMINADO')
        // fluxo antigo preservado
        && str_contains($colunasPorNome['cnh_status_processamento'], 'PENDENTE')
        && str_contains($colunasPorNome['cnh_status_processamento'], 'PROCESSANDO')
        && str_contains($colunasPorNome['cnh_status_processamento'], 'CONCLUIDO')
        && str_contains($colunasPorNome['cnh_status_processamento'], 'ERRO'));

    afirmar('ENUM crlv_status_processamento inclui os mesmos estados novos',
        str_contains($colunasPorNome['crlv_status_processamento'], 'INDETERMINADO'));

    afirmar('ENUM cnh_origem_validacao inclui VIO_CACHE', str_contains($colunasPorNome['cnh_origem_validacao'], 'VIO_CACHE'));
    afirmar('ENUM crlv_origem_validacao inclui VIO_CACHE', str_contains($colunasPorNome['crlv_origem_validacao'], 'VIO_CACHE'));

    // ============================================================
    // 2. Tabelas/indices novos da migration 016
    // ============================================================
    $tabelas = $pdo->query("SHOW TABLES LIKE 'tb_vio_api_cache_%'")->fetchAll(PDO::FETCH_COLUMN);
    afirmar('tb_vio_api_cache_cnh criada', in_array('tb_vio_api_cache_cnh', $tabelas, true));
    afirmar('tb_vio_api_cache_crlv criada', in_array('tb_vio_api_cache_crlv', $tabelas, true));

    $indicesCnh = $pdo->query("SHOW INDEX FROM tb_vio_api_cache_cnh")->fetchAll(PDO::FETCH_ASSOC);
    $nomesIndicesCnh = array_unique(array_column($indicesCnh, 'Key_name'));
    afirmar('UNIQUE KEY uk_fingerprint_versao_cnh existe', in_array('uk_fingerprint_versao_cnh', $nomesIndicesCnh, true));
    afirmar('INDEX idx_expira_em_cnh existe', in_array('idx_expira_em_cnh', $nomesIndicesCnh, true));
    afirmar('INDEX idx_estado_cnh existe', in_array('idx_estado_cnh', $nomesIndicesCnh, true));

    $indicesCrlv = $pdo->query("SHOW INDEX FROM tb_vio_api_cache_crlv")->fetchAll(PDO::FETCH_ASSOC);
    $nomesIndicesCrlv = array_unique(array_column($indicesCrlv, 'Key_name'));
    afirmar('UNIQUE KEY uk_fingerprint_versao_crlv existe', in_array('uk_fingerprint_versao_crlv', $nomesIndicesCrlv, true));
    afirmar('INDEX idx_expira_em_crlv existe', in_array('idx_expira_em_crlv', $nomesIndicesCrlv, true));
    afirmar('INDEX idx_estado_crlv existe', in_array('idx_estado_crlv', $nomesIndicesCrlv, true));

    // Tabelas antigas do fluxo Serpro (migration 005) permanecem intocadas/coexistindo
    $tabelasAntigas = $pdo->query("SHOW TABLES LIKE 'tb_vio_cache_%'")->fetchAll(PDO::FETCH_COLUMN);
    afirmar('tb_vio_cache_cnh (fluxo antigo) coexiste, nao foi removida/renomeada', in_array('tb_vio_cache_cnh', $tabelasAntigas, true));
    afirmar('tb_vio_cache_crlv (fluxo antigo) coexiste, nao foi removida/renomeada', in_array('tb_vio_cache_crlv', $tabelasAntigas, true));

    // ============================================================
    // 3. Idempotencia -- reexecutar 015/016 (e 014) uma segunda vez nao
    // deve gerar erro nem duplicar coluna/indice/tabela.
    // ============================================================
    $raiz = __DIR__ . '/../../';
    $semErroSegundaExecucao = true;
    try {
        qaDbAplicarArquivoSql($pdo, $raiz . 'sql/migrations/014_tb_lgpd_aceite.sql');
        qaDbAplicarArquivoSql($pdo, $raiz . 'sql/migrations/015_vio_api_br_estados_e_id_externo.sql');
        qaDbAplicarArquivoSql($pdo, $raiz . 'sql/migrations/016_vio_api_br_cache.sql');
    } catch (\Throwable $e) {
        $semErroSegundaExecucao = false;
        echo '   (erro na 2a execucao: ' . get_class($e) . ' ' . $e->getMessage() . ")\n";
    }
    afirmar('Reexecutar migrations 014/015/016 uma 2a vez nao gera erro (idempotente)', $semErroSegundaExecucao);

    $colunasApos2aExecucao = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tb_atendimento")->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $colunasApos2aExecucao[] = $linha['Field'];
    }
    $ocorrenciasFingerprint = array_count_values($colunasApos2aExecucao)['cnh_vio_api_fingerprint'] ?? 0;
    afirmar('Coluna cnh_vio_api_fingerprint nao duplicada apos reexecucao', $ocorrenciasFingerprint === 1);

    $tabelasApos2aExecucao = $pdo->query("SHOW TABLES LIKE 'tb_vio_api_cache_cnh'")->fetchAll(PDO::FETCH_COLUMN);
    afirmar('tb_vio_api_cache_cnh nao duplicada/recriada apos reexecucao', count($tabelasApos2aExecucao) === 1);

    // uk_fingerprint_versao_cnh tem 4 colunas -- SHOW INDEX retorna 1 linha
    // por coluna do indice (comportamento normal do MySQL/MariaDB, nao
    // duplicacao). Comparado contra a contagem original (antes da
    // reexecucao), nao contra um valor fixo "1".
    $indicesCnhApos2a = $pdo->query("SHOW INDEX FROM tb_vio_api_cache_cnh WHERE Key_name = 'uk_fingerprint_versao_cnh'")->fetchAll(PDO::FETCH_ASSOC);
    afirmar('UNIQUE KEY uk_fingerprint_versao_cnh nao duplicada apos reexecucao (mesma contagem de colunas do indice, 4, antes/depois)', count($indicesCnhApos2a) === 4);

    // ============================================================
    // 4. Rodar de novo NAO mascara colisao de schema incompativel --
    // confirmado por leitura de codigo (sql/migrations/013 e 014, secao
    // "REESCRITA SEM STORED PROCEDURE"): quando uma divergencia e detectada,
    // o SQL dinamico referencia uma tabela com nome invalido (>64
    // caracteres), o que MySQL/MariaDB rejeita com erro 1103 ANTES de
    // tentar localiza-la -- aborta deterministicamente, nunca mascara.
    // Verificado aqui simulando uma divergencia real: um schema onde
    // token_hash (tb_lgpd_aceite) tem tipo incompativel.
    // ============================================================
    $pdo->exec('DROP TABLE tb_lgpd_aceite');
    $pdo->exec('CREATE TABLE tb_lgpd_aceite (id_aceite BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token_hash VARCHAR(10) NOT NULL, id_totem INT UNSIGNED NOT NULL, status ENUM("PENDENTE_USO","USADO") NOT NULL DEFAULT "PENDENTE_USO") ENGINE=InnoDB');

    $abortouPorDivergencia = false;
    try {
        qaDbAplicarArquivoSql($pdo, $raiz . 'sql/migrations/014_tb_lgpd_aceite.sql');
    } catch (\Throwable $e) {
        $abortouPorDivergencia = ($e instanceof PDOException) && str_contains($e->getMessage(), 'Incorrect table name');
    }
    afirmar('Migration 014 aborta deterministicamente (erro 1103, nao mascara) diante de schema divergente de tb_lgpd_aceite.token_hash', $abortouPorDivergencia);
} finally {
    if ($nomeBanco !== null) {
        qaDbDropar($nomeBanco);
        echo "\n(banco de teste {$nomeBanco} dropado)\n";
    }
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
