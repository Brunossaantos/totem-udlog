-- Migration 001 — adiciona UNIQUE KEY (id_atendimento, ordem) em tb_atendimento_nota
--
-- Contexto: nao ha mecanismo de migration automatica neste projeto (Hostgator,
-- hospedagem compartilhada, sem cron de deploy). Este arquivo eh aplicado
-- MANUALMENTE via phpMyAdmin/cPanel em instalacoes que ja existiam antes da
-- constraint UNIQUE ter sido adicionada em sql/schema.sql. Para instalacoes
-- novas, sql/schema.sql ja cria a tabela com o indice — nao rode esta
-- migration em banco novo.
--
-- Esta migration NUNCA apaga registros automaticamente. Se houver
-- duplicidade de (id_atendimento, ordem), o PASSO 3 vai falhar (erro de
-- chave duplicada) — resolva manualmente antes de repetir a tentativa.

-- ============================================================
-- PASSO 1 (obrigatorio) — diagnostico de duplicidade
-- ============================================================
-- Execute esta query e revise o resultado ANTES de ir para o PASSO 3.
-- Se retornar QUALQUER linha, existe mais de uma nota com a mesma ordem
-- no mesmo atendimento. Decida manualmente qual registro manter (e o
-- arquivo correspondente em storage/atendimentos/) — nao ha decisao de
-- produto tomada sobre qual registro prevalece em caso de duplicidade,
-- portanto esta migration nao decide isso por voce.
SELECT id_atendimento, ordem, COUNT(*) AS qtd_duplicada
FROM tb_atendimento_nota
GROUP BY id_atendimento, ordem
HAVING COUNT(*) > 1;

-- ============================================================
-- PASSO 2 (recomendado) — checagem de idempotencia
-- ============================================================
-- MySQL/MariaDB no Hostgator normalmente NAO aceita a sintaxe portavel
-- "ALTER TABLE ... ADD CONSTRAINT IF NOT EXISTS" para indices/chaves
-- unicas (suporte inconsistente entre versoes). Em vez disso, confira
-- via INFORMATION_SCHEMA se o indice ja existe antes de rodar o PASSO 3 —
-- se a query abaixo retornar indice_ja_existe = 1, NAO rode o PASSO 3
-- de novo (rodar novamente causaria erro "Duplicate key name").
SELECT COUNT(*) AS indice_ja_existe
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'tb_atendimento_nota'
  AND INDEX_NAME = 'uk_atendimento_ordem';

-- ============================================================
-- PASSO 3 — aplicar a constraint
-- ============================================================
-- So execute esta linha se o PASSO 1 nao retornou nenhuma linha E o
-- PASSO 2 retornou indice_ja_existe = 0.
ALTER TABLE tb_atendimento_nota
    ADD UNIQUE KEY uk_atendimento_ordem (id_atendimento, ordem);
