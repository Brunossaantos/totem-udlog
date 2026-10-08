-- Migration 023 -- Log central do sistema (demanda gestao-totem, fase F3a,
-- 2026-10-08).
--
-- Problema: os 100+ error_log() espalhados pelo codigo nao tem nivel, origem
-- (Recebimento, Expedicao, API, Cron, Gestao), deduplicacao nem consulta pela
-- tela de logs da Gestao Totem. Alem disso, nada limita a quantidade de linhas
-- que um erro repetido (ou um anonimo gerando 401) poderia produzir.
--
-- Solucao ADITIVA: tb_log_sistema, alimentada SO por Util\LogSistema a partir de
-- um catalogo fechado (Util\LogCatalogo). Contrato de privacidade: nunca grava IP,
-- token, CPF, CNPJ, placa, nome, trace, arquivo nem linha. `mensagem` e um texto
-- FIXO do catalogo (nunca do chamador). `detalhe` so aceita chave=valor de uma
-- allowlist fixa da aplicacao (classe da excecao validada, SQLSTATE, http, motivo
-- e contagens de catalogo). `id_totem` so vem de totem ja autenticado. Eventos que
-- um anonimo consegue disparar (ex.: 401) nao levam id_totem nem id_atendimento.
--
-- Deduplicacao: UNIQUE (dedup_chave, janela). `dedup_chave` e o sha1 de
-- origem|categoria|id_totem|id_atendimento|codigo e `janela` e o inicio do balde
-- de tempo da categoria, entao o mesmo erro repetido na mesma janela vira UMA
-- linha com `contador` incrementado de forma atomica (INSERT ... ON DUPLICATE KEY
-- UPDATE). Sem chave estrangeira de proposito: o log deve sobreviver a qualquer
-- manutencao e ser podado por data (retencao de 90 dias, cron da fase F3d).
--
-- Compatibilidade: MySQL 5.7 (producao) e MariaDB 10.4 (dev). Sem CHECK, sem
-- coluna gerada, sem ADD COLUMN IF NOT EXISTS.
--
-- Idempotencia: CREATE TABLE IF NOT EXISTS. Reexecucao em banco ja convergido
-- e um no-op seguro.
--
-- REVERSAO (manual, so se necessario, APAGA todos os logs, exigir backup antes)
-- (sem ponto e virgula final):
--   DROP TABLE IF EXISTS tb_log_sistema

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tb_log_sistema (
    id_log             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nivel              ENUM('INFO','AVISO','ERRO') NOT NULL,
    origem             ENUM('API','RECEBIMENTO','EXPEDICAO','CRON','GESTAO') NOT NULL,
    categoria          VARCHAR(40) NOT NULL,
    mensagem           VARCHAR(160) NOT NULL,
    id_atendimento     BIGINT UNSIGNED NULL,
    id_totem           INT UNSIGNED NULL,
    detalhe            VARCHAR(255) NULL,
    dedup_chave        CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    janela             DATETIME NOT NULL,
    contador           INT UNSIGNED NOT NULL DEFAULT 1,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultima_ocorrencia  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_log_sistema_dedup (dedup_chave, janela),
    KEY idx_log_sistema_origem (origem, ultima_ocorrencia),
    KEY idx_log_sistema_nivel (nivel, ultima_ocorrencia),
    KEY idx_log_sistema_totem (id_totem, ultima_ocorrencia),
    KEY idx_log_sistema_atendimento (id_atendimento),
    KEY idx_log_sistema_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
