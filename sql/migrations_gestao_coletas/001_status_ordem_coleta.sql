-- ============================================================================
-- ATENCAO — LEIA ANTES DE EXECUTAR ESTE ARQUIVO
--
-- Este arquivo NUNCA deve ser executado contra o banco do totem
-- (`udlog_totem`). Ele pertence exclusivamente ao banco EXTERNO de gestao de
-- coletas — `udlogo59_db_gestao_coletas` (ver .env/GESTAO_COLETAS_DB_NAME).
-- Ja aplicada com sucesso contra o banco real neste XAMPP local em
-- 2026-09-11 (reconciliacao apos correcao do nome do banco pelo usuario).
--
-- Toda a pasta sql/migrations_gestao_coletas/ e SEPARADA de
-- sql/migrations/ (que e exclusivamente do banco do totem) por esse motivo —
-- nunca misture os dois em uma mesma execucao/ferramenta de migration.
-- ============================================================================
--
-- Migration 001 (banco externo) — adiciona status ENUM('ATIVA','INATIVA')
-- em tb_ordens_coleta, com indice composto (placa_prevista, status) para a
-- consulta de App\Dao\OrdemColetaDao::buscarPorPlacaNormalizada. Demanda
-- expedicao-consulta-ordem-coleta-teste (2026-09-11) — decisao do usuario:
-- a fonte real nunca teve coluna de status ate aqui (docs/db_gestao_coletas.sql
-- e o schema verbatim confirmado, sem essa coluna); esta migration
-- introduz o conceito de "ordem ativa/inativa" pela primeira vez, com
-- default ATIVA (ordens ja existentes permanecem ATIVAS automaticamente,
-- sem necessidade de UPDATE).
--
-- Padrao idempotente: SQL preparado condicional via INFORMATION_SCHEMA
-- (mesmo padrao ja usado em sql/migrations/003 e seguintes) — seguro rodar
-- mais de uma vez, nunca aborta se coluna/indice ja existirem.

SET NAMES utf8mb4;

SET @dbname := DATABASE();
SET @tablename := 'tb_ordens_coleta';
SET @columnname := 'status';
SET @sql := (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) = 0,
        'ALTER TABLE tb_ordens_coleta ADD COLUMN status ENUM(''ATIVA'',''INATIVA'') NOT NULL DEFAULT ''ATIVA'' AFTER cnh_prevista',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @indexname := 'idx_ordens_placa_status';
SET @sql2 := (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) = 0,
        'ALTER TABLE tb_ordens_coleta ADD INDEX idx_ordens_placa_status (placa_prevista, status)',
        'SELECT 1'
    )
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Ordens ja existentes ficam ATIVA automaticamente (o DEFAULT do ALTER acima
-- ja cobre isso) — nenhum UPDATE necessario.
