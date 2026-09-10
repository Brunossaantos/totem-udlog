-- Migration 007 — Processamento assincrono de CNH/CRLV (VIO Decode)
-- (demanda expedicao-vio-cnh-crlv, REPLANEJAMENTO de 2026-09-09, ver
-- docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md secao "REPLANEJAMENTO").
--
-- Campo NOVO e SEPARADO de cnh_origem_validacao/crlv_origem_validacao (nao
-- funde os dois conceitos): *_status_processamento responde "o backend
-- terminou de tentar" (PENDENTE/PROCESSANDO/CONCLUIDO/ERRO), enquanto
-- *_origem_validacao (ja existente desde a migration 005) responde "com que
-- resultado" (VIO_TRIAL/VIO_VALIDADO/MANUAL/NAO_VALIDADO). Preenchimento
-- MANUAL marca status_processamento = CONCLUIDO diretamente, sem chamar o
-- VIO.
--
-- *_tentativa_id: identificador unico por tentativa de processamento
-- (bin2hex(random_bytes(16)) no PHP), gravado junto da transicao ATOMICA
-- para PROCESSANDO. A escrita do resultado final (CONCLUIDO/ERRO) so tem
-- efeito se o tentativa_id ainda corresponder ao gravado no banco — impede
-- que uma chamada "zumbi" (requisicao antiga ainda em voo) sobrescreva o
-- resultado de uma tentativa mais nova (controle de versao otimista, ver
-- App\Dao\AtendimentoDao::iniciarProcessamento/gravarResultadoProcessamento).
--
-- *_processamento_iniciado_em: usado para detectar PROCESSANDO obsoleto
-- (mais de 30s sem resolver, timeout do VioDecodeClient e 20s + margem) —
-- tratado como ERRO, permitindo nova tentativa ou fallback manual.
--
-- tb_rate_limit_vio_status: rate limit PROPRIO (mesmo espirito de
-- tb_rate_limit_ocr, migration 004) para o endpoint de polling
-- documento.php?acao=status-processamento — chave por
-- (id_atendimento, tipo_documento, janela) em vez de (id_totem, janela),
-- porque o polling e por documento/atendimento, nao por totem. Janela de 5s
-- com limite de 20 chamadas (media de 1 chamada a cada 0.25s por
-- documento) — folga generosa sobre o polling de 2s especificado no
-- escopo, nunca bloqueia o uso legitimo.
--
-- Padrao idempotente: SQL preparado condicional (SET @sql := IF(...);
-- PREPARE; EXECUTE; DEALLOCATE) para as colunas de tb_atendimento, mesmo
-- padrao ja corrigido em sql/migrations/003/005/006 — NAO o padrao fragil
-- de 001/002. CREATE TABLE IF NOT EXISTS para a tabela nova (idempotente
-- por natureza). SET NAMES utf8mb4 como primeira instrucao.

SET NAMES utf8mb4;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE tb_atendimento ADD COLUMN cnh_status_processamento ENUM('PENDENTE','PROCESSANDO','CONCLUIDO','ERRO') NOT NULL DEFAULT 'PENDENTE' AFTER cnh_snapshot_validade",
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_status_processamento'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_processamento_iniciado_em DATETIME NULL AFTER cnh_status_processamento',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_processamento_iniciado_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_tentativa_id VARCHAR(32) NULL AFTER cnh_processamento_iniciado_em',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_tentativa_id'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE tb_atendimento ADD COLUMN crlv_status_processamento ENUM('PENDENTE','PROCESSANDO','CONCLUIDO','ERRO') NOT NULL DEFAULT 'PENDENTE' AFTER crlv_snapshot_exercicio",
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_status_processamento'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_processamento_iniciado_em DATETIME NULL AFTER crlv_status_processamento',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_processamento_iniciado_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_tentativa_id VARCHAR(32) NULL AFTER crlv_processamento_iniciado_em',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_tentativa_id'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tb_rate_limit_vio_status (
    id_atendimento  BIGINT UNSIGNED NOT NULL,
    tipo_documento  ENUM('cnh','crlv') NOT NULL,
    janela          INT UNSIGNED NOT NULL,
    contador        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_atendimento, tipo_documento, janela),
    FOREIGN KEY (id_atendimento) REFERENCES tb_atendimento(id_atendimento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
