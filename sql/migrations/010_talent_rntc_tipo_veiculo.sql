-- Migration 010 — RNTC e tipo de veiculo do CRLV (bloqueio de
-- veiculo.rntc/veiculo.tipo confirmado como obrigatorio pelo Talent em
-- teste real de Producao, 2026-09-10, HTTP 400: "The rntc field is
-- required."/"The tipo field is required."), demanda
-- integracao-talent-portaria-checkin (extensao 2026-09-10). Ver
-- docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md, secao
-- "Terceira tentativa — CAUSA RAIZ ENCONTRADA".
--
-- Nomenclatura: a resposta real da VIO Decode para CRLV documenta a chave
-- JSON como `rntrc` (docs/manual_vio_decode.md, "Estrutura de resposta —
-- CRLV"), mesmo a sigla oficial do registro ser "RNTRC". As colunas de
-- banco e o campo do payload do Talent usam `rntc` (nome exato exigido
-- pelo Talent, `veiculo.rntc`) — divergencia de nomenclatura entre a fonte
-- (VIO: `rntrc`) e o destino (Talent: `rntc`), documentada aqui e em
-- App\Rn\DocumentoRn, nao um erro de digitacao.
--
-- crlv_rntc / crlv_snapshot_rntc / crlv_tipo_veiculo /
-- crlv_snapshot_tipo_veiculo: mesmo raciocinio ja usado para
-- crlv_uf/crlv_snapshot_uf (migration 009) — snapshot so gravado quando a
-- origem e VIO_TRIAL/VIO_VALIDADO, usado para decidir o rebaixamento para
-- MANUAL quando o atendente edita RNTC/tipo na tela de confirmacao.
--
-- tb_vio_cache_crlv.rntc/tipo_veiculo: cache SEM esses campos (registros
-- gravados antes desta migration) e tratado como INCOMPLETO por
-- App\Dao\VioCacheDao::buscarCrlvValido() (WHERE rntc IS NOT NULL AND
-- rntc != '' AND tipo_veiculo IS NOT NULL AND tipo_veiculo != '') — nunca
-- reaproveitado como cache-hit, forca nova consulta ao VIO.
--
-- Padrao idempotente: SET NAMES utf8mb4 + SQL preparado condicional, mesmo
-- padrao ja usado em 003/005/006/007/008/009.

SET NAMES utf8mb4;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_rntc VARCHAR(20) NULL AFTER crlv_uf',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_rntc'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_tipo_veiculo VARCHAR(60) NULL AFTER crlv_rntc',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_tipo_veiculo'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_snapshot_rntc VARCHAR(20) NULL AFTER crlv_snapshot_uf',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_snapshot_rntc'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_snapshot_tipo_veiculo VARCHAR(60) NULL AFTER crlv_snapshot_rntc',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_snapshot_tipo_veiculo'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_vio_cache_crlv ADD COLUMN rntc VARCHAR(20) NULL AFTER uf',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_vio_cache_crlv' AND COLUMN_NAME = 'rntc'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_vio_cache_crlv ADD COLUMN tipo_veiculo VARCHAR(60) NULL AFTER rntc',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_vio_cache_crlv' AND COLUMN_NAME = 'tipo_veiculo'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
