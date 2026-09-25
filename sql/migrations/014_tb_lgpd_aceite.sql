-- Migration 014 — cria tb_lgpd_aceite (registro de aceite do Aviso de
-- Privacidade/LGPD exibido na nova tela inicial do Totem) + coluna
-- id_aceite_lgpd (nullable) em tb_atendimento
--
-- Demanda: tela-inicial-lgpd-totem (/01-implementacao, 2026-09-24). Ver
-- docs/handoffs/2026-09-24-tela-inicial-lgpd-totem.md (planejamento) para
-- o desenho tecnico completo do fluxo de aceite (token opaco de uso unico,
-- consumido via CAS em atendimento.php?acao=iniciar).
--
-- ============================================================
-- O que esta migration faz
-- ============================================================
-- 1. CREATE TABLE IF NOT EXISTS tb_lgpd_aceite — registro TEMPORARIO e
--    independente do aceite, emitido pelo totem ANTES de qualquer
--    atendimento existir (nao ha id_atendimento neste momento). Nenhum
--    dado pessoal do motorista e gravado aqui (correto — nesse momento
--    ele ainda nao informou nada, so o totem esta autenticado).
-- 2. ADD COLUMN condicional id_aceite_lgpd (NULLABLE) em tb_atendimento —
--    vincula o atendimento ao aceite que o originou. NULLABLE porque
--    atendimentos ja existentes (criados antes desta migration) nunca
--    tiveram nenhum aceite associado, e continuam validos sem ele.
-- 3. ADD INDEX condicional em tb_atendimento(id_aceite_lgpd) — apoio a
--    eventual consulta/auditoria por aceite (nao e FK, ver nota abaixo).
--
-- ============================================================
-- Por que id_aceite_lgpd NAO tem FOREIGN KEY declarada
-- ============================================================
-- tb_atendimento.id_aceite_lgpd referencia logicamente
-- tb_lgpd_aceite.id_aceite, mas NAO foi declarada como FOREIGN KEY
-- (InnoDB) neste arquivo, por 2 motivos:
--   (a) o fluxo de escrita real grava tb_atendimento.id_aceite_lgpd no
--       MESMO INSERT que cria o atendimento (App\Dao\AtendimentoDao::
--       criar()), a partir de um id_aceite ja confirmado pelo CAS de
--       consumo do token (App\Controller\AtendimentoController::iniciar())
--       — nunca um valor arbitrario vindo do front-end, entao a garantia
--       de integridade referencial ja vem inteiramente do fluxo de
--       aplicacao, nao depende do banco para isso;
--   (b) manter a tabela nova (tb_lgpd_aceite) totalmente ADITIVA e
--       dissociavel: se no futuro a politica de retencao de
--       tb_lgpd_aceite (pendencia registrada no handoff de planejamento,
--       NAO resolvida nesta demanda, so a intencao documentada abaixo)
--       expurgar linhas antigas, uma FK RESTRICT bloquearia o expurgo de
--       aceites ja vinculados a atendimentos antigos, e uma FK SET
--       NULL/CASCADE alteraria tb_atendimento por efeito colateral de um
--       expurgo em outra tabela — nenhuma das duas opcoes e desejavel sem
--       decisao de produto/juridico explicita sobre retencao. Ausencia de
--       FK aqui e uma decisao tecnica desta migration, nao um descuido.
--
-- ============================================================
-- Retencao (intencao documentada, NENHUMA rotina de expurgo criada aqui)
-- ============================================================
-- Decisao ja aprovada pelo usuario (fora desta migration): o registro de
-- aceite em tb_lgpd_aceite deve acompanhar o MESMO periodo de retencao
-- aplicavel ao atendimento/auditoria relacionada — isto e, enquanto o
-- atendimento (ou a obrigacao legal/auditoria correspondente) precisar
-- ser mantido, o aceite que o originou tambem deve ser mantido. Nenhum
-- cron/job de expurgo e criado nesta demanda (mesmo padrao ja usado no
-- projeto para pendencias de retencao — ver cron/limpar-rate-limit-ocr.php
-- como exemplo de mecanismo ja existente para OUTRA tabela, caso um job
-- futuro precise ser criado para esta).
--
-- ============================================================
-- Padrao de idempotencia usado (identico ao ja validado em
-- sql/migrations/003 e sql/migrations/013 — SEM stored procedure/SIGNAL)
-- ============================================================
-- sql/migrations/013_convergencia_idempotente_migrations_historicas.sql
-- documenta em detalhe (secao "REESCRITA SEM STORED PROCEDURE") por que
-- este projeto NAO usa SIGNAL SQLSTATE dentro de uma stored procedure
-- para abortar deterministicamente uma migration em caso de schema
-- divergente: o usuario de banco do Hostgator real NAO tem privilegio
-- confirmado de CREATE ROUTINE/ALTER ROUTINE, e SIGNAL so pode ser usado
-- dentro de uma rotina armazenada em MySQL/MariaDB puro (fora de uma
-- rotina, so PREPARE/EXECUTE de SQL dinamico esta disponivel). O
-- mecanismo de aborto determinismo usado em todo este arquivo e o MESMO
-- de 013: quando uma divergencia de schema e detectada, o SQL dinamico
-- gerado referencia deliberadamente uma tabela com nome invalido
-- (>64 caracteres, autoexplicativo, prefixado com o numero desta
-- migration) — o MySQL/MariaDB rejeita esse identificador com o erro
-- nativo "ERROR 1103 (42000): Incorrect table name '...'" ANTES de
-- tentar localiza-la, abortando o script inteiro de forma deterministica,
-- sem exigir privilegio de rotina, sem alterar/apagar nenhum dado.
--
-- Nenhum DROP, TRUNCATE ou renomeacao destrutiva em nenhum ponto deste
-- arquivo. Privilegios necessarios: SELECT sobre INFORMATION_SCHEMA
-- (leitura padrao) e CREATE/ALTER/INDEX no schema do totem — os MESMOS ja
-- exigidos pelas migrations anteriores (001/002/003/004/.../013), NENHUM
-- privilegio de rotina.
--
-- ============================================================
-- Rollback manual (nenhum automatico — mesmo padrao ja usado no projeto)
-- ============================================================
-- ATENCAO: so execute os comandos abaixo se tiver certeza de que o objeto
-- foi de fato criado por ESTA migration (confirme antes via SHOW INDEX
-- FROM/SHOW COLUMNS FROM/SHOW CREATE TABLE).
--   ALTER TABLE tb_atendimento DROP INDEX idx_atendimento_aceite_lgpd;
--   ALTER TABLE tb_atendimento DROP COLUMN id_aceite_lgpd;
--   DROP TABLE tb_lgpd_aceite;
--
-- Aplicada MANUALMENTE via phpMyAdmin/cPanel/terminal — sem executor de
-- migration automatico no projeto (Hostgator, hospedagem compartilhada).
-- Backup do banco OBRIGATORIO antes de aplicar (ver docs/deploy-checklist.md).

SET NAMES utf8mb4;

-- ============================================================
-- PASSO 0 — checagem de schema pre-existente incompativel para
-- tb_lgpd_aceite (aborta o script inteiro via erro nativo de "nome de
-- tabela invalido" se a tabela ja existir com uma coluna-chave de tipo
-- divergente do esperado; nunca altera/apaga dado)
-- ============================================================
-- So verificado se a tabela ja existir (instalacao nova: COUNT(*) = 0,
-- checagem e no-op, PASSO 1 cria a tabela do zero com a definicao
-- correta).
SET @tb_lgpd_aceite_existe := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_lgpd_aceite'
);

SET @tb_lgpd_aceite_diverge := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_lgpd_aceite'
      AND (
            (COLUMN_NAME = 'token_hash' AND (COLUMN_TYPE <> 'char(64)' OR IS_NULLABLE <> 'NO'))
         OR (COLUMN_NAME = 'id_totem' AND (COLUMN_TYPE <> 'int(10) unsigned' OR IS_NULLABLE <> 'NO'))
         OR (COLUMN_NAME = 'status' AND IS_NULLABLE <> 'NO')
      )
);

SET @sql_checagem_tb_lgpd_aceite := (
    SELECT IF(
        @tb_lgpd_aceite_existe = 1 AND @tb_lgpd_aceite_diverge > 0,
        'SELECT 1 FROM `migracao_014_abortar__tb_lgpd_aceite_schema_pre_existente_divergente_corrija_manualmente`',
        'SELECT 1'
    )
);
PREPARE stmt_checagem_tb_lgpd_aceite FROM @sql_checagem_tb_lgpd_aceite;
EXECUTE stmt_checagem_tb_lgpd_aceite;
DEALLOCATE PREPARE stmt_checagem_tb_lgpd_aceite;

-- ============================================================
-- PASSO 1 — CREATE TABLE IF NOT EXISTS tb_lgpd_aceite (idempotente por
-- natureza, mesmo padrao ja usado em sql/migrations/004_tb_rate_limit_ocr.sql
-- para tabela estrutural nova)
-- ============================================================
CREATE TABLE IF NOT EXISTS tb_lgpd_aceite (
    id_aceite      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- SHA-256 (hex, 64 caracteres) do token bruto (random_bytes(32) +
    -- bin2hex) gerado por App\Controller\LgpdController::aceitar() — o
    -- token BRUTO nunca e gravado aqui, so o hash. UNIQUE garante que o
    -- mesmo hash nunca e inserido 2x (colisao SHA-256 de um valor de 256
    -- bits de entropia e praticamente impossivel; UNIQUE e so uma
    -- garantia adicional em profundidade).
    token_hash     CHAR(64) NOT NULL UNIQUE,

    id_totem       INT UNSIGNED NOT NULL,

    -- Snapshot do texto do termo (App\Content\TermoLgpd) no momento da
    -- EMISSAO do token — usado depois, no CONSUMO
    -- (AtendimentoController::iniciar), para detectar se o termo mudou
    -- entre a emissao e o consumo (nesse caso o aceite e tratado como
    -- invalido, mesma resposta generica de qualquer outra falha de
    -- validacao).
    versao_termo   VARCHAR(20) NOT NULL,
    hash_termo     CHAR(64) NOT NULL,

    -- Emissao (App\Controller\LgpdController::aceitar()).
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em      DATETIME NOT NULL,

    -- Consumo (App\Controller\AtendimentoController::iniciar()) — NULL
    -- ate o token ser efetivamente consumido pelo CAS.
    usado_em       DATETIME NULL,

    status         ENUM('PENDENTE_USO', 'USADO') NOT NULL DEFAULT 'PENDENTE_USO',

    FOREIGN KEY (id_totem) REFERENCES tb_totem(id_totem),

    -- Apoio ao CAS de consumo (WHERE token_hash = ... AND id_totem = ...
    -- AND status = ... AND expira_em > NOW()) — token_hash ja e UNIQUE
    -- (localiza a linha sozinho), os 2 indices abaixo apoiam consultas
    -- auxiliares/auditoria por totem e por limpeza futura de expirados.
    INDEX idx_lgpd_aceite_totem_status (id_totem, status),
    INDEX idx_lgpd_aceite_status_expira (status, expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PASSO 2 — coluna condicional id_aceite_lgpd em tb_atendimento
-- (NULLABLE — atendimentos existentes/antigos continuam validos sem ela)
-- ============================================================
SET @coluna_id_aceite_lgpd_existe := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento'
      AND COLUMN_NAME = 'id_aceite_lgpd'
);

SET @ddl_id_aceite_lgpd := (
    SELECT IF(
        @coluna_id_aceite_lgpd_existe = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN id_aceite_lgpd BIGINT UNSIGNED NULL AFTER id_totem',
        'SELECT 1'
    )
);
PREPARE stmt_id_aceite_lgpd FROM @ddl_id_aceite_lgpd;
EXECUTE stmt_id_aceite_lgpd;
DEALLOCATE PREPARE stmt_id_aceite_lgpd;

-- ============================================================
-- PASSO 3 — indice equivalente para tb_atendimento(id_aceite_lgpd)
-- (apoio a consulta/auditoria futura; nao e FK, ver nota no topo do
-- arquivo)
-- ============================================================
SET @indice_atendimento_aceite_lgpd_existe := (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tb_atendimento'
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1
           AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'id_aceite_lgpd' THEN 1 ELSE 0 END) = 1
    ) indices_equivalentes
);

SET @ddl_idx_atendimento_aceite_lgpd := (
    SELECT IF(
        @indice_atendimento_aceite_lgpd_existe = 0,
        'ALTER TABLE tb_atendimento ADD INDEX idx_atendimento_aceite_lgpd (id_aceite_lgpd)',
        'SELECT 1'
    )
);
PREPARE stmt_idx_atendimento_aceite_lgpd FROM @ddl_idx_atendimento_aceite_lgpd;
EXECUTE stmt_idx_atendimento_aceite_lgpd;
DEALLOCATE PREPARE stmt_idx_atendimento_aceite_lgpd;
