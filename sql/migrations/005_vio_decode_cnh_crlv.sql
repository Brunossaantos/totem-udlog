-- Migration 005 — VIO Decode (Serpro): validacao de CNH/CRLV via QR Code na
-- Expedicao (demanda expedicao-vio-cnh-crlv, 2026-09-08).
--
-- Cria tb_vio_cache_cnh/tb_vio_cache_crlv (cache local de QR ja validados,
-- chaveado por HMAC-SHA256 do QR bruto — o QR bruto/hex NUNCA e persistido,
-- so o hash) e adiciona colunas de origem/status de validacao em
-- tb_atendimento. NAO persiste o JSON completo da resposta da VIO Decode
-- (decisao de produto aprovada em 2026-09-08, ver
-- docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md) — so os campos
-- especificos ja usados hoje por tb_atendimento (motorista_nome,
-- motorista_cpf, cnh_validade, crlv_ano) mais origem/status/timestamp de
-- auditoria.
--
-- Padrao idempotente: SQL preparado condicional (SET @sql := IF(...);
-- PREPARE; EXECUTE; DEALLOCATE) para as colunas de tb_atendimento (ALTER
-- TABLE ADD COLUMN nao suporta IF NOT EXISTS em todas as versoes de
-- MariaDB/MySQL usadas no projeto), CREATE TABLE IF NOT EXISTS para as
-- tabelas novas — mesmo padrao ja corrigido em
-- sql/migrations/003_tb_cliente_razao_normalizada.sql, nao o padrao fragil
-- de 001/002. SET NAMES utf8mb4 como primeira instrucao.

SET NAMES utf8mb4;

-- ============================================================
-- Tabelas novas de cache (idempotente via CREATE TABLE IF NOT EXISTS)
-- ============================================================

-- Cache de CNH ja validada pela VIO Decode. identificador_qr = hex do
-- HMAC-SHA256(QR bruto, DOCUMENTO_QR_HMAC_KEY) — nunca o QR bruto em si.
-- UNIQUE(identificador_qr, ambiente) impede cruzar cache entre trial/producao
-- e trata concorrencia via INSERT ... ON DUPLICATE KEY UPDATE (a constraint
-- serializa a corrida no proprio InnoDB, sem SELECT ... FOR UPDATE explicito).
CREATE TABLE IF NOT EXISTS tb_vio_cache_cnh (
    id_cache        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identificador_qr CHAR(64) NOT NULL,
    ambiente        ENUM('trial','production') NOT NULL,
    nome_cifrado    VARBINARY(512) NOT NULL,
    cpf_cifrado     VARBINARY(512) NOT NULL,
    data_validade   DATE NOT NULL,
    origem          ENUM('VIO_TRIAL','VIO_VALIDADO') NOT NULL,
    data_validacao  DATETIME NOT NULL,
    valido_ate      DATETIME NOT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_qr_ambiente (identificador_qr, ambiente),
    INDEX idx_valido_ate (valido_ate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cache de CRLV ja validado pela VIO Decode. Placa e recomparada SEMPRE
-- contra tb_atendimento.placa do atendimento atual, mesmo em cache hit
-- (a placa cacheada pode ser de outro atendimento) — regra aplicada em
-- codigo (App\Controller\DocumentoController), nao no schema.
CREATE TABLE IF NOT EXISTS tb_vio_cache_crlv (
    id_cache        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identificador_qr CHAR(64) NOT NULL,
    ambiente        ENUM('trial','production') NOT NULL,
    placa           VARCHAR(8) NOT NULL,
    exercicio       SMALLINT NOT NULL,
    origem          ENUM('VIO_TRIAL','VIO_VALIDADO') NOT NULL,
    data_validacao  DATETIME NOT NULL,
    valido_ate      DATETIME NOT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_qr_ambiente (identificador_qr, ambiente),
    INDEX idx_valido_ate (valido_ate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Colunas novas em tb_atendimento (idempotente, SQL preparado condicional)
-- ============================================================

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_origem_validacao ENUM(''VIO_TRIAL'',''VIO_VALIDADO'',''MANUAL'',''NAO_VALIDADO'') NOT NULL DEFAULT ''NAO_VALIDADO'' AFTER cnh_validade',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_origem_validacao'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_status_revisao ENUM(''OK'',''PENDENTE_REVISAO'') NOT NULL DEFAULT ''OK'' AFTER cnh_origem_validacao',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_status_revisao'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_validado_em DATETIME NULL AFTER cnh_status_revisao',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_validado_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_origem_validacao ENUM(''VIO_TRIAL'',''VIO_VALIDADO'',''MANUAL'',''NAO_VALIDADO'') NOT NULL DEFAULT ''NAO_VALIDADO'' AFTER crlv_ano',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_origem_validacao'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_status_revisao ENUM(''OK'',''PENDENTE_REVISAO'') NOT NULL DEFAULT ''OK'' AFTER crlv_origem_validacao',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_status_revisao'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_validado_em DATETIME NULL AFTER crlv_status_revisao',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_validado_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
