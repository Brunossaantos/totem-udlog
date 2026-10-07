-- Migration 021 -- Auditoria da Gestao Totem (demanda gestao-totem, fase F1,
-- 2026-10-06).
--
-- Problema: acoes administrativas (login, troca e redefinicao de senha,
-- criacao e alteracao de usuarios) precisam de trilha de auditoria que nao
-- guarde dado pessoal, segredo nem identificador sensivel.
--
-- Solucao ADITIVA: tb_gestao_auditoria, APPEND-ONLY. A aplicacao so faz INSERT
-- e UM unico UPDATE permitido, o fechamento do `resultado` (PENDENTE para OK,
-- SEM_EFEITO ou ERRO). Nunca grava senha, token, hash, CPF, nome, placa nem URL
-- de totem. `acao` pertence a um catalogo fechado validado na aplicacao
-- (VARCHAR para novas fases nao exigirem ALTER). `detalhe` so aceita
-- chave=valor de uma allowlist fixa da aplicacao. `ip` e o IP em binario
-- (VARBINARY 16: IPv4 com 4 bytes, IPv6 com 16), gravado EM CLARO (NAO e hash)
-- de proposito: a trilha serve a analise forense de acesso indevido, e um hash
-- impediria identificar a origem. Em compensacao a retencao e curta: 90 dias
-- (decisao do usuario, 2026-10-06), a ser implementada na fase F3 junto do cron
-- de retencao dos logs (ate la a tabela so cresce). Sem chave estrangeira de
-- proposito: a trilha deve sobreviver a qualquer manutencao de usuarios e ser
-- podada por data.
--
-- Compatibilidade: MySQL 5.7 (producao) e MariaDB 10.4 (dev). Sem CHECK, sem
-- coluna gerada, sem ADD COLUMN IF NOT EXISTS.
--
-- Idempotencia: CREATE TABLE IF NOT EXISTS. Reexecucao em banco ja convergido
-- e um no-op seguro.
--
-- REVERSAO (manual, so se necessario, APAGA a trilha de auditoria, exigir
-- backup antes) (sem ponto e virgula final):
--   DROP TABLE IF EXISTS tb_gestao_auditoria

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tb_gestao_auditoria (
    id_auditoria  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_usuario    INT UNSIGNED NULL,
    acao          VARCHAR(40) NOT NULL,
    alvo_tipo     VARCHAR(30) NULL,
    alvo_id       BIGINT UNSIGNED NULL,
    resultado     ENUM('PENDENTE','OK','SEM_EFEITO','ERRO') NOT NULL DEFAULT 'PENDENTE',
    detalhe       VARCHAR(255) NULL,
    ip            VARBINARY(16) NULL,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gestao_auditoria_data (criado_em),
    KEY idx_gestao_auditoria_usuario (id_usuario, criado_em),
    KEY idx_gestao_auditoria_acao (acao, criado_em),
    KEY idx_gestao_auditoria_alvo (alvo_tipo, alvo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
