-- Migration 022 -- Gestao de totens (demanda gestao-totem, fase F2, 2026-10-07).
--
-- Problema: a gestao passa a criar totens com codigo no padrao
-- NOME-EMPRESA-HASH16 (ate 58 caracteres), mas tb_totem.codigo e VARCHAR(30), e
-- a tabela nao registra quem criou o totem, quando ele foi alterado nem quando a
-- URL foi regerada pela ultima vez. Tambem nao ha garantia no banco de que o
-- mesmo nome nao se repita na mesma empresa.
--
-- Solucao ADITIVA e condicional, sem tocar em token_api nem em nenhuma linha
-- existente (o totem legado RECEPCAO-01 continua exatamente como esta):
--   codigo           ampliado para VARCHAR(64) NOT NULL so se hoje for menor que 64
--                    (o indice UNIQUE existente e mantido)
--   criado_por       INT UNSIGNED NULL, chave estrangeira para
--                    tb_gestao_usuario(id_usuario) com ON DELETE SET NULL (mesmo
--                    tipo da chave primaria referenciada). Exige a migration 020
--   atualizado_em    DATETIME com ON UPDATE CURRENT_TIMESTAMP
--   url_regerada_em  DATETIME NULL (ultima regeracao do HASH da URL)
--   url_versao       INT UNSIGNED NOT NULL DEFAULT 1, incrementado a CADA
--                    regeracao. O formulario de regerar leva a versao vista pelo
--                    admin e o servidor recusa o reenvio se ela mudou
--   idx_totem_empresa_ativo (id_empresa, ativo)
--   uk_totem_empresa_nome   UNIQUE (id_empresa, nome), criado SO se NAO houver
--                    nome repetido na mesma empresa. Se houver, a migration nao
--                    cria o indice e devolve um SELECT informativo com os pares
--                    repetidos (corrigir e reaplicar). A aplicacao confere o nome
--                    sob lock de qualquer forma. Totens sem empresa (id_empresa
--                    NULL) nunca conflitam entre si
--
-- Compatibilidade: MySQL 5.7 (producao) e MariaDB 10.4 (dev). Sem CHECK, sem
-- coluna gerada, sem ADD COLUMN IF NOT EXISTS. Cada passo consulta
-- INFORMATION_SCHEMA e usa SQL preparado.
--
-- Idempotencia: cada alteracao so roda se o objeto ainda nao existe (ou, no caso
-- de codigo, se ainda e menor que 64). Reexecucao em banco ja convergido e um
-- no-op seguro e nenhum dado e alterado ou apagado.
--
-- REVERSAO (manual, so se necessario, exigir backup antes, sem ponto e virgula
-- final em cada comando). Nao encurtar codigo depois de existirem totens com
-- codigo maior que 30 caracteres:
--   ALTER TABLE tb_totem DROP INDEX uk_totem_empresa_nome
--   ALTER TABLE tb_totem DROP INDEX idx_totem_empresa_ativo
--   ALTER TABLE tb_totem DROP FOREIGN KEY fk_totem_criado_por
--   ALTER TABLE tb_totem DROP COLUMN criado_por, DROP COLUMN atualizado_em, DROP COLUMN url_regerada_em, DROP COLUMN url_versao

SET NAMES utf8mb4;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'SELECT 1',
        'ALTER TABLE tb_totem MODIFY COLUMN codigo VARCHAR(64) NOT NULL'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'codigo'
      AND CHARACTER_MAXIMUM_LENGTH < 64
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_totem ADD COLUMN criado_por INT UNSIGNED NULL',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'criado_por'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_totem ADD COLUMN atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'atualizado_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_totem ADD COLUMN url_regerada_em DATETIME NULL',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'url_regerada_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_totem ADD COLUMN url_versao INT UNSIGNED NOT NULL DEFAULT 1',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'url_versao'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_totem ADD CONSTRAINT fk_totem_criado_por FOREIGN KEY (criado_por) REFERENCES tb_gestao_usuario(id_usuario) ON DELETE SET NULL',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND CONSTRAINT_NAME = 'fk_totem_criado_por'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_totem ADD INDEX idx_totem_empresa_ativo (id_empresa, ativo)',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND INDEX_NAME = 'idx_totem_empresa_ativo'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND INDEX_NAME = 'uk_totem_empresa_nome') > 0,
        'SELECT 1',
        IF(
            (SELECT COUNT(*) FROM (SELECT 1 FROM tb_totem WHERE id_empresa IS NOT NULL GROUP BY id_empresa, nome HAVING COUNT(*) > 1) repetidos) > 0,
            'SELECT id_empresa, nome, COUNT(*) AS repeticoes, ''uk_totem_empresa_nome NAO criado: corrija os nomes repetidos e reaplique a migration 022'' AS aviso FROM tb_totem WHERE id_empresa IS NOT NULL GROUP BY id_empresa, nome HAVING COUNT(*) > 1',
            'ALTER TABLE tb_totem ADD UNIQUE KEY uk_totem_empresa_nome (id_empresa, nome)'
        )
    )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
