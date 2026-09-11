-- Migration 011 — amplia tb_atendimento.ordem_coleta de VARCHAR(30) para
-- VARCHAR(50), demanda expedicao-consulta-ordem-coleta-teste (2026-09-11).
--
-- Motivo: tb_ordens_coleta.numero_ordem_coleta, no banco externo REAL
-- (udlogo59_db_gestao_coletas, schema verbatim em docs/db_gestao_coletas.sql),
-- e VARCHAR(50) NOT NULL — tb_atendimento.ordem_coleta (VARCHAR(30) ate
-- aqui) podia truncar (ou falhar, dependendo do sql_mode) um numero de
-- ordem de coleta real mais longo que 30 caracteres.
--
-- Aplicavel APENAS ao banco do TOTEM (udlog_totem) — nunca ao banco externo
-- de gestao de coletas (ver sql/migrations_gestao_coletas/ para esse).
--
-- Padrao idempotente: SET NAMES utf8mb4 + SQL preparado condicional, mesmo
-- padrao ja usado em 003/005/006/007/008/009/010. MODIFY COLUMN e
-- naturalmente idempotente (rodar de novo com o mesmo tipo nao tem efeito
-- destrutivo), mas mantemos a checagem condicional para nunca alterar a
-- coluna se ela ja estiver no tamanho esperado (evita ALTER TABLE
-- desnecessario em tabela grande).

SET NAMES utf8mb4;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento MODIFY COLUMN ordem_coleta VARCHAR(50) NULL',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento'
      AND COLUMN_NAME = 'ordem_coleta'
      AND CHARACTER_MAXIMUM_LENGTH = 50
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
