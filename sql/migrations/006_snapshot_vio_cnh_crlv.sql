-- Migration 006 — Snapshot do VIO Decode para permitir rebaixamento correto
-- para MANUAL (demanda expedicao-vio-cnh-crlv, achado do /02-testes de
-- 2026-09-08: rebaixamento nunca fora implementado por falta de uma
-- "verdade original" para comparar).
--
-- cnh_snapshot_*/crlv_snapshot_* guardam o valor EXATO que veio do VIO/cache
-- no momento em que cnh_origem_validacao/crlv_origem_validacao foi gravado
-- como VIO_TRIAL/VIO_VALIDADO — nunca atualizados por edicao manual
-- posterior (ver App\Dao\AtendimentoDao::atualizarValidacaoCnh/
-- atualizarValidacaoCrlv e App\Rn\AtendimentoRn::salvarDadosMotorista).
-- Quando a origem gravada e MANUAL, os snapshots ficam NULL (nao ha
-- "verdade VIO" a preservar naquele momento).
--
-- Padrao idempotente: SQL preparado condicional (SET @sql := IF(...);
-- PREPARE; EXECUTE; DEALLOCATE), mesmo padrao ja corrigido em
-- sql/migrations/003_tb_cliente_razao_normalizada.sql e
-- sql/migrations/005_vio_decode_cnh_crlv.sql — NAO o padrao fragil de
-- 001/002. SET NAMES utf8mb4 como primeira instrucao.

SET NAMES utf8mb4;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_snapshot_nome VARCHAR(150) NULL AFTER cnh_validado_em',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_snapshot_nome'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_snapshot_cpf VARCHAR(14) NULL AFTER cnh_snapshot_nome',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_snapshot_cpf'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_snapshot_validade DATE NULL AFTER cnh_snapshot_cpf',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_snapshot_validade'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_snapshot_placa VARCHAR(8) NULL AFTER crlv_validado_em',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_snapshot_placa'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_snapshot_exercicio SMALLINT NULL AFTER crlv_snapshot_placa',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_snapshot_exercicio'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
