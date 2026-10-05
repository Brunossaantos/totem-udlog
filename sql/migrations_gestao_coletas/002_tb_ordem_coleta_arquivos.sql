-- ============================================================================
-- ATENCAO — LEIA ANTES DE EXECUTAR ESTE ARQUIVO
--
-- Este arquivo NUNCA deve ser executado contra o banco do totem
-- (`udlog_totem`). Ele pertence exclusivamente ao banco EXTERNO de gestao de
-- coletas — `udlogo59_db_gestao_coletas` (ver .env/GESTAO_COLETAS_DB_NAME).
--
-- Toda a pasta sql/migrations_gestao_coletas/ e SEPARADA de
-- sql/migrations/ (que e exclusivamente do banco do totem) por esse motivo —
-- nunca misture os dois em uma mesma execucao/ferramenta de migration.
-- ============================================================================
--
-- Migration 002 (banco externo) — cria tb_ordem_coleta_arquivos, que guarda
-- SOMENTE o caminho relativo (sob STORAGE_PATH/ordens_coleta/) do PDF da
-- Ordem de Coleta enviado pelo n8n via public/api/ordem-coleta-anexo.php.
-- O PDF NUNCA fica no banco. Demanda anexo-ordem-coleta-n8n (2026-10-05).
--
-- Desenho (decisoes do usuario):
--  - SEM chave estrangeira para tb_ordens_coleta: o anexo pode chegar ANTES
--    ou DEPOIS de a ordem existir. O vinculo logico e feito na leitura por
--    (cnpj_cliente, numero_ordem_coleta) — cnpj_cliente casa com
--    tb_clientes.cnpj.
--  - UM unico registro/arquivo por (cnpj_cliente, numero_ordem_coleta):
--    reenvio sobrescreve (a aplicacao grava o arquivo novo, atualiza a linha
--    e apaga o arquivo antigo depois do commit).
--  - caminho_relativo UNIQUE; sha256 do conteudo para deteccao de reenvio
--    identico (idempotencia) e de arquivo corrompido na leitura.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS (seguro rodar mais de uma vez; nao
-- altera uma tabela ja existente). Ordem de deploy: backup do banco externo
-- -> esta migration -> (003) -> codigo novo -> n8n por ultimo.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tb_ordem_coleta_arquivos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cnpj_cliente CHAR(14) NOT NULL,
    numero_ordem_coleta VARCHAR(50) NOT NULL,
    caminho_relativo VARCHAR(255) NOT NULL,
    tamanho_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_oc_arquivo_cnpj_numero (cnpj_cliente, numero_ordem_coleta),
    UNIQUE KEY uk_oc_arquivo_caminho (caminho_relativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
