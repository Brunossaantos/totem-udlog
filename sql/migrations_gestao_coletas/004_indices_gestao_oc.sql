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
-- Migration 004 (banco externo) — indices de apoio a tela de Ordens de Coleta
-- da Gestao Totem (F4, 2026-10-08). So ADICIONA indices; nenhum dado e lido,
-- alterado ou apagado, nenhuma coluna muda.
--
--   idx_oc_status_criado    (status, criado_em)
--       abas "Ativas" / "Ativas ha mais de 15 dias" / "Inativas" ordenadas e
--       filtradas por data de criacao (WHERE status = ... ORDER BY criado_em).
--   idx_oc_status_inativada (status, inativada_em)
--       aba "Inativas" ordenada/filtrada por data de inativacao.
--   idx_oc_numero           (numero_ordem_coleta)
--       busca por numero exato ou prefixo (LIKE 'abc%').
--
-- NAO DUPLICA indice equivalente: no schema real (docs/db_gestao_coletas.sql)
-- ja existe `idx_ordens_numero_ordem (numero_ordem_coleta)`, entao o
-- idx_oc_numero NAO e criado la (so em um banco que nao tenha nenhum indice
-- cujas primeiras colunas sejam numero_ordem_coleta). Idem para os outros
-- dois: se ja existir indice com o mesmo nome OU com as mesmas colunas
-- iniciais (na mesma ordem), nada e feito. Existentes e preservados:
-- uk_ordem_cliente (cliente_id, numero_ordem_coleta), idx_ordens_cliente_id,
-- idx_ordens_placa_prevista, idx_ordens_placa_status (migration 001) e
-- idx_ordens_transportadora.
--
-- Idempotente (SQL preparado condicional via INFORMATION_SCHEMA, mesmo padrao
-- das migrations 001 e 003): rodar 2x ou mais nao altera nada na segunda vez e
-- nunca aborta. Compativel com MySQL 5.7 / MariaDB. ADD INDEX declara
-- ALGORITHM=INPLACE, LOCK=NONE (falha em vez de bloquear); em producao, rode
-- fora do horario de pico e com backup de tb_ordens_coleta.
--
-- ORDEM DE DEPLOY: independe do codigo (o codigo funciona sem os indices,
-- so mais lento em tabelas grandes). Aplicar ANTES de liberar a tela de OCs.
--
-- REVERSAO (manual, SO se necessario; NAO faz parte desta migration, que
-- nunca apaga nada). Antes, confirme com SHOW INDEX FROM tb_ordens_coleta
-- que o indice foi criado por ESTA migration (o idx_oc_numero normalmente
-- nao existe). Para cada indice criado:
--   ALTER TABLE tb_ordens_coleta DROP INDEX idx_oc_status_criado;
--   ALTER TABLE tb_ordens_coleta DROP INDEX idx_oc_status_inativada;
--   ALTER TABLE tb_ordens_coleta DROP INDEX idx_oc_numero;

SET NAMES utf8mb4;

-- Guarda de bloqueio: cada ADD INDEX declara ALGORITHM=INPLACE, LOCK=NONE; se o
-- motor nao conseguir fazer assim, o ALTER FALHA (nao bloqueia a tabela de
-- producao nem copia a tabela). Os timeouts abaixo (so desta sessao) impedem
-- esperar muito por metadata lock / lock de linha com trafego ativo; se o
-- ALTER falhar por timeout ou algoritmo, repita fora do horario de pico.
SET SESSION lock_wait_timeout = 5;
SET SESSION innodb_lock_wait_timeout = 5;

SET @dbname := DATABASE();
SET @tablename := 'tb_ordens_coleta';

-- ---------------------------------------------------------------- (status, criado_em)
SET @indexname := 'idx_oc_status_criado';
SET @sql := (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) = 0
        AND (SELECT COUNT(*) FROM (
                SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename
                GROUP BY INDEX_NAME
                HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = 'status,criado_em'
                    OR GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) LIKE 'status,criado_em,%'
             ) equivalentes) = 0,
        'ALTER TABLE tb_ordens_coleta ADD INDEX idx_oc_status_criado (status, criado_em), ALGORITHM=INPLACE, LOCK=NONE',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- (status, inativada_em)
SET @indexname := 'idx_oc_status_inativada';
SET @sql := (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) = 0
        AND (SELECT COUNT(*) FROM (
                SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename
                GROUP BY INDEX_NAME
                HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = 'status,inativada_em'
                    OR GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) LIKE 'status,inativada_em,%'
             ) equivalentes) = 0,
        'ALTER TABLE tb_ordens_coleta ADD INDEX idx_oc_status_inativada (status, inativada_em), ALGORITHM=INPLACE, LOCK=NONE',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- (numero_ordem_coleta)
SET @indexname := 'idx_oc_numero';
SET @sql := (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) = 0
        AND (SELECT COUNT(*) FROM (
                SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename
                GROUP BY INDEX_NAME
                HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = 'numero_ordem_coleta'
                    OR GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) LIKE 'numero_ordem_coleta,%'
             ) equivalentes) = 0,
        'ALTER TABLE tb_ordens_coleta ADD INDEX idx_oc_numero (numero_ordem_coleta), ALGORITHM=INPLACE, LOCK=NONE',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
