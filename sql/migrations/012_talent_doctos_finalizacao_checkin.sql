-- Migration 012 — demanda talent-doctos-finalizacao-checkin (2026-09-14).
-- Aplicavel APENAS ao banco do TOTEM (udlog_totem) — nunca ao banco externo
-- de gestao de coletas.
--
-- 1) tb_atendimento_nota.numero_nota / numero_nota_origem: numero da nota
--    fiscal (digitado manualmente ou confirmado a partir do OCR), sempre
--    normalizado no backend (sem zero a esquerda, so digitos) antes de
--    gravar aqui — ver App\Dao\AtendimentoNotaDao::atualizarNumero().
--    UNIQUE KEY bloqueia numero duplicado dentro do MESMO atendimento;
--    multiplos NULL sao permitidos pelo MariaDB (nota ainda sem numero
--    definido nao conflita com outra nota tambem sem numero).
--
-- 2) tb_ordem_coleta_pendente_baixa: registro de auditoria interno (banco do
--    TOTEM, nao o externo de gestao de coletas) para o cenario "Talent
--    aceitou o check-in, mas o UPDATE de status da ordem para INATIVA
--    falhou" — decisao do usuario em 2026-09-14 (ver handoff
--    docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md). NUNCA
--    guarda payload/corpo bruto/CPF/CNH/token — so os 2 identificadores +
--    timestamps. Sem cron de reconciliacao automatica nesta demanda (fora
--    de escopo).
--
-- Padrao idempotente: SET NAMES utf8mb4 + SQL preparado condicional, mesmo
-- padrao ja usado em 003/005/006/007/008/009/010/011.

SET NAMES utf8mb4;

-- 1a) numero_nota
SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento_nota ADD COLUMN numero_nota VARCHAR(20) NULL AFTER chave_acesso',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento_nota'
      AND COLUMN_NAME = 'numero_nota'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1b) numero_nota_origem
SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento_nota ADD COLUMN numero_nota_origem ENUM(''OCR'',''MANUAL'') NULL AFTER numero_nota',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento_nota'
      AND COLUMN_NAME = 'numero_nota_origem'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1c) UNIQUE KEY (id_atendimento, numero_nota)
SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento_nota ADD UNIQUE KEY uk_atendimento_numero_nota (id_atendimento, numero_nota)',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento_nota'
      AND INDEX_NAME = 'uk_atendimento_numero_nota'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) tb_ordem_coleta_pendente_baixa (banco do totem, auditoria interna)
SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'CREATE TABLE tb_ordem_coleta_pendente_baixa (
            id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_atendimento      BIGINT UNSIGNED NOT NULL,
            numero_ordem_coleta VARCHAR(50) NOT NULL,
            criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolvido_em        DATETIME NULL,
            UNIQUE KEY uk_ordem_coleta_pendente_baixa_atendimento (id_atendimento),
            FOREIGN KEY (id_atendimento) REFERENCES tb_atendimento(id_atendimento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_ordem_coleta_pendente_baixa'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
