-- Migration 020 -- Usuarios, sessoes e tentativas de login da Gestao Totem
-- (demanda gestao-totem, fase F1, 2026-10-06).
--
-- Problema: o projeto so tem autenticacao por TOTEM (token fixo por
-- dispositivo). A area administrativa (/gestao) precisa de login humano com
-- perfis `admin` e `usuario`, sessao no servidor e limite de tentativas de
-- login, sem tocar em tb_totem nem no token do totem.
--
-- Solucao ADITIVA com 3 tabelas novas:
--   tb_gestao_usuario: login (primeiro.segundo, sempre minusculo, validado na
--     aplicacao e unico por indice), perfil, hash da senha (nunca a senha),
--     bloqueio por conta (tentativas_falhas e bloqueado_ate), troca obrigatoria
--     de senha no primeiro acesso. Usuarios nunca sao apagados (so ativo = 0).
--     senha_versao: contador incrementado a CADA senha nova (redefinicao pelo
--     admin ou troca pelo proprio usuario, mas nao no rehash transparente). O
--     formulario de "redefinir senha" leva a versao vista pelo admin e o
--     servidor recusa o reenvio (F5) se ela mudou, sem gerar outra senha.
--   tb_gestao_sessao: sessao em TABELA. id_sessao e o sha256 do token do cookie
--     (o token em si nunca e gravado), com o token CSRF da sessao, expiracao
--     absoluta (expira_em) e hash do User-Agent. A inatividade e calculada a
--     partir de ultimo_acesso_em.
--   tb_gestao_login_tentativa: contador atomico de falhas de login por IP, em
--     janelas fixas. ip_hash = sha256(ip + GESTAO_HASH_SALT), nunca o IP em
--     claro (mesmo padrao de tb_rate_limit_ocr: chave composta + INSERT ... ON
--     DUPLICATE KEY UPDATE).
--
-- Compatibilidade: MySQL 5.7 (producao) e MariaDB 10.4 (dev). Sem CHECK, sem
-- coluna gerada, sem ADD COLUMN IF NOT EXISTS.
--
-- Idempotencia: CREATE TABLE IF NOT EXISTS. Reexecucao em banco ja convergido
-- e um no-op seguro (nenhum dado e alterado ou apagado).
--
-- REVERSAO (manual, so se necessario, apaga TODOS os usuarios e sessoes da
-- gestao -- nao ha dado de negocio do totem nestas tabelas). Ordem obrigatoria
-- por causa das chaves estrangeiras (sem ponto e virgula final):
--   DROP TABLE IF EXISTS tb_gestao_login_tentativa
--   DROP TABLE IF EXISTS tb_gestao_sessao
--   DROP TABLE IF EXISTS tb_gestao_usuario
-- (a tabela tb_gestao_auditoria da migration 021 nao tem chave estrangeira e
-- deve ser preservada).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tb_gestao_usuario (
    id_usuario         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login              VARCHAR(60) NOT NULL,
    nome               VARCHAR(100) NOT NULL,
    perfil             ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
    senha_hash         VARCHAR(255) NOT NULL,
    ativo              TINYINT(1) NOT NULL DEFAULT 1,
    deve_trocar_senha  TINYINT(1) NOT NULL DEFAULT 1,
    tentativas_falhas  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate      DATETIME NULL,
    ultimo_login_em    DATETIME NULL,
    senha_alterada_em  DATETIME NULL,
    senha_versao       INT UNSIGNED NOT NULL DEFAULT 1,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    criado_por         INT UNSIGNED NULL,
    UNIQUE KEY uk_gestao_usuario_login (login),
    KEY idx_gestao_usuario_perfil_ativo (perfil, ativo),
    CONSTRAINT fk_gestao_usuario_criado_por FOREIGN KEY (criado_por) REFERENCES tb_gestao_usuario(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_gestao_sessao (
    id_sessao         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    id_usuario        INT UNSIGNED NOT NULL,
    csrf_token        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_acesso_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em         DATETIME NOT NULL,
    ua_hash           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    KEY idx_gestao_sessao_usuario (id_usuario),
    KEY idx_gestao_sessao_expira (expira_em),
    CONSTRAINT fk_gestao_sessao_usuario FOREIGN KEY (id_usuario) REFERENCES tb_gestao_usuario(id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_gestao_login_tentativa (
    ip_hash        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    janela         INT UNSIGNED NOT NULL,
    contador       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ip_hash, janela),
    KEY idx_gestao_login_tentativa_atualizado (atualizado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
