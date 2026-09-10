-- Migration 008 — Empresa/armazem vinculado ao totem (integracao Talent
-- Portaria/Checkin), demanda integracao-talent-portaria-checkin (2026-09-09,
-- ver docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md,
-- "REFINAMENTO 2").
--
-- `cnpjArmazem` do payload do Talent passa a vir EXCLUSIVAMENTE do totem
-- autenticado (tb_totem.id_empresa -> tb_empresa.cnpj), nunca do frontend.
-- Totem sem id_empresa preenchido falha explicitamente na finalizacao (ver
-- App\Controller\AtendimentoController::finalizar).
--
-- Seeds idempotentes (INSERT ... ON DUPLICATE KEY UPDATE via UNIQUE(cnpj)):
-- Maua I (14706199000182) e Maua II (14706199000344).
--
-- Vinculo do totem de teste a Maua I: SOMENTE se id_totem = 1 E
-- codigo = 'RECEPCAO-01' simultaneamente (AND, nunca OR) — se a linha real
-- do banco nao bater nas duas condicoes ao mesmo tempo, este UPDATE nao
-- afeta nenhuma linha (nao ha mecanismo de fallback/forcar vinculo). Maua II
-- NAO e vinculada a nenhum totem nesta migration.
--
-- Padrao idempotente: SET NAMES utf8mb4 + SQL preparado condicional para a
-- coluna nova em tb_totem, mesmo padrao ja usado em 003/005/006/007.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tb_empresa (
    id_empresa  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(100) NOT NULL,
    cnpj        VARCHAR(14) NOT NULL UNIQUE,
    ativo       TINYINT(1) NOT NULL DEFAULT 1,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_totem ADD COLUMN id_empresa INT UNSIGNED NULL AFTER localizacao',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'id_empresa'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_totem ADD CONSTRAINT fk_totem_empresa FOREIGN KEY (id_empresa) REFERENCES tb_empresa(id_empresa)',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND CONSTRAINT_NAME = 'fk_totem_empresa'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')
    ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua II', '14706199000344')
    ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- Vinculo condicional do totem de teste — so tem efeito se as DUAS condicoes
-- (id_totem = 1 E codigo = 'RECEPCAO-01') forem verdadeiras ao mesmo tempo.
UPDATE tb_totem t
JOIN tb_empresa e ON e.cnpj = '14706199000182'
SET t.id_empresa = e.id_empresa
WHERE t.id_totem = 1 AND t.codigo = 'RECEPCAO-01';
