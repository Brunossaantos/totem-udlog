-- Migration 004 — cria tb_rate_limit_ocr (controle de taxa por totem para
-- o endpoint nota.php?acao=identificar-cliente)
--
-- Contexto: ao trocar a fonte de dado de identificacao de cliente via OCR
-- da API externa (que tinha rate limit proprio de 60/min, nao confirmado a
-- janela) para consulta local (app/Dao/ClienteDao.php contra tb_cliente), o
-- endpoint fica mais barato de abusar como "oraculo" de existencia de CNPJ
-- (consulta SQL local e ordens de magnitude mais rapida que HTTP externo) —
-- achado do security-especialista na etapa de planejamento
-- (docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md).
--
-- Estrategia: janela fixa de 60 segundos por id_totem (bucket =
-- FLOOR(unix_timestamp / 60)), contador incrementado atomicamente via
-- INSERT ... ON DUPLICATE KEY UPDATE (chave primaria composta id_totem +
-- janela garante que o incremento e a leitura do contador de uma mesma
-- janela sejam serializados pelo InnoDB em requisicoes concorrentes do
-- mesmo totem — sem race condition, sem depender de Redis/APCu,
-- compativel com Hostgator/hospedagem compartilhada).
--
-- Limite escolhido pela implementacao (ver App\Controller\NotaController):
-- 30 chamadas por totem por janela de 60s. Dimensionamento: ate 5 notas por
-- atendimento (limite ja existente), cada uma gerando no maximo 1 chamada
-- "normal" a identificar-cliente + ate 2-3 retries de rede com backoff do
-- front-end em caso de falha — pior caso realista de uso legitimo em rajada
-- fica bem abaixo de 20 chamadas/min; 30 da folga de ~2x sem abrir demais
-- a porta para uso como oraculo de CNPJ.
--
-- Nao ha mecanismo de migration automatica neste projeto (Hostgator,
-- hospedagem compartilhada, sem cron de deploy) — este arquivo e aplicado
-- MANUALMENTE via phpMyAdmin/cPanel em instalacoes que ja existiam antes
-- desta tabela. Para instalacoes novas, sql/schema.sql ja cria a tabela.
--
-- CREATE TABLE IF NOT EXISTS e idempotente por natureza (mesmo padrao usado
-- para tabelas estruturais novas, ex. tb_atendimento_nota original) — seguro
-- rodar esta migration mais de uma vez.
CREATE TABLE IF NOT EXISTS tb_rate_limit_ocr (
    id_totem       INT UNSIGNED NOT NULL,
    janela         INT UNSIGNED NOT NULL,
    contador       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_totem, janela),
    FOREIGN KEY (id_totem) REFERENCES tb_totem(id_totem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Observacao (nao resolvida por esta migration, registrada como pendencia):
-- a tabela cresce indefinidamente (uma linha por totem/minuto com pelo
-- menos 1 chamada). Sem cron de limpeza automatica nesta demanda — se
-- necessario no futuro, um job periodico (cPanel Cron) apagando linhas com
-- janela antiga (ex: mais de 1 dia) resolveria sem impacto no
-- funcionamento (o rate limit so olha para a janela atual).
