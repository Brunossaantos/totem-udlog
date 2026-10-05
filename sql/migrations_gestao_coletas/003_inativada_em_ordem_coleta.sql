-- ============================================================================
-- ATENCAO — LEIA ANTES DE EXECUTAR ESTE ARQUIVO
--
-- Este arquivo NUNCA deve ser executado contra o banco do totem
-- (`udlog_totem`). Ele pertence exclusivamente ao banco EXTERNO de gestao de
-- coletas — `udlogo59_db_gestao_coletas` (ver .env/GESTAO_COLETAS_DB_NAME).
--
-- Toda a pasta sql/migrations_gestao_coletas/ e SEPARADA de
-- sql/migrations/ (que e exclusivamente do banco do totem) por esse motivo —
-- nunca misture os dois em uma mesma execucao/ferramenta de migration.
-- ============================================================================
--
-- Migration 003 (banco externo) — adiciona tb_ordens_coleta.inativada_em
-- (DATETIME NULL): momento em que a ordem virou INATIVA. Usada pela retencao
-- de 15 dias dos PDFs de Ordem de Coleta (cron/limpar-anexos-ordem-coleta.php).
-- Demanda anexo-ordem-coleta-n8n (2026-10-05).
--
-- ORDEM DE DEPLOY (obrigatoria): esta migration ANTES do codigo novo. O codigo
-- novo de App\Dao\OrdemColetaDao::marcarInativaPorNumero grava inativada_em na
-- mesma instrucao do UPDATE de status; se o codigo novo rodar sem a coluna, a
-- baixa da ordem de coleta apos o check-in falharia (erro de coluna
-- inexistente).
--
-- Backfill: ordens ja INATIVA recebem inativada_em = atualizado_em (melhor
-- aproximacao disponivel — o momento real da baixa nao foi registrado). O
-- `atualizado_em = atualizado_em` evita que o ON UPDATE CURRENT_TIMESTAMP da
-- coluna renove o proprio atualizado_em durante o backfill.
--
-- Idempotente: SQL preparado condicional via INFORMATION_SCHEMA (mesmo padrao
-- da migration 001); o backfill so toca linhas INATIVA com inativada_em NULL,
-- entao reexecutar nao altera nada.

SET NAMES utf8mb4;

SET @dbname := DATABASE();
SET @tablename := 'tb_ordens_coleta';
SET @columnname := 'inativada_em';
SET @sql := (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) = 0,
        'ALTER TABLE tb_ordens_coleta ADD COLUMN inativada_em DATETIME NULL DEFAULT NULL AFTER status',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE tb_ordens_coleta
SET inativada_em = atualizado_em, atualizado_em = atualizado_em
WHERE status = 'INATIVA' AND inativada_em IS NULL;
