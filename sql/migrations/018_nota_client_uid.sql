-- Migration 018 -- Identificador idempotente do front para cada nota (demanda
-- hardening-revisao-notas-e-cliente, rodada corretiva F7, 2026-10-01).
--
-- Problema: se a resposta de nota.php?acao=processar se perde (rede, aba
-- recarregada), o front nao sabe o id_nota/ordem da nota ja criada; retentar
-- criaria uma SEGUNDA nota e a primeira ficaria invisivel e sem saida (o 422
-- apontaria uma ordem que o motorista nao enxerga).
--
-- Solucao ADITIVA: coluna client_uid (VARCHAR(64) NULL) gerada pelo front e
-- enviada no processar (campo `uid`), com UNIQUE (id_atendimento, client_uid).
-- NULL = nota criada sem uid (front antigo): continua valendo o comportamento
-- anterior (varios NULL sao permitidos pelo indice UNIQUE). O formato do uid
-- (^[A-Za-z0-9_-]{8,64}$) e validado na aplicacao.
--
-- Aditiva e idempotente (mesmo padrao INFORMATION_SCHEMA/PREPARE de 017):
-- reexecucao em banco ja convergido e um no-op seguro.
--
-- REVERSAO (manual, so se necessario; nao ha perda de dado de negocio, apenas
-- o vinculo uid -> nota):
--   ALTER TABLE tb_atendimento_nota DROP INDEX uk_atendimento_client_uid (sem ponto e virgula final)
--   ALTER TABLE tb_atendimento_nota DROP COLUMN client_uid (sem ponto e virgula final)

SET NAMES utf8mb4;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento_nota ADD COLUMN client_uid VARCHAR(64) NULL AFTER ordem',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento_nota' AND COLUMN_NAME = 'client_uid'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento_nota ADD UNIQUE KEY uk_atendimento_client_uid (id_atendimento, client_uid)',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento_nota' AND INDEX_NAME = 'uk_atendimento_client_uid'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
