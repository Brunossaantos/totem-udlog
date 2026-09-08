-- Migration 002 — adiciona status_ocr e processado_em em tb_atendimento_nota
--
-- Contexto: demanda `recebimento-leitura-notas` (identificacao automatica de
-- cliente via OCR client-side/Tesseract.js + novo endpoint sincrono
-- nota.php?acao=identificar-cliente). Nao ha mecanismo de migration
-- automatica neste projeto (Hostgator, hospedagem compartilhada, sem cron
-- de deploy) — este arquivo eh aplicado MANUALMENTE via phpMyAdmin/cPanel em
-- instalacoes que ja existiam antes destas colunas terem sido adicionadas em
-- sql/schema.sql. Para instalacoes novas, sql/schema.sql ja cria a tabela
-- com as colunas — nao rode esta migration em banco novo.
--
-- Esta migration NUNCA apaga/altera dado existente. Colunas novas sao
-- adicionadas com DEFAULT ('PENDENTE' / NULL), preservando todas as notas
-- ja gravadas anteriormente sem qualquer valor a inferir manualmente.

-- ============================================================
-- PASSO 1 (obrigatorio) — checagem de idempotencia
-- ============================================================
-- MySQL/MariaDB no Hostgator normalmente NAO aceita a sintaxe portavel
-- "ALTER TABLE ... ADD COLUMN IF NOT EXISTS" de forma consistente entre
-- versoes. Em vez disso, confira via INFORMATION_SCHEMA se as colunas ja
-- existem antes de rodar o PASSO 2 — se a query abaixo retornar
-- colunas_ja_existem = 2, NAO rode o PASSO 2 de novo (rodar novamente
-- causaria erro "Duplicate column name").
SELECT COUNT(*) AS colunas_ja_existem
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'tb_atendimento_nota'
  AND COLUMN_NAME IN ('status_ocr', 'processado_em');

-- ============================================================
-- PASSO 2 — aplicar as colunas novas
-- ============================================================
-- So execute este bloco se o PASSO 1 retornou colunas_ja_existem = 0.
ALTER TABLE tb_atendimento_nota
    ADD COLUMN status_ocr ENUM('PENDENTE','PROCESSANDO','IDENTIFICADA','NAO_IDENTIFICADA','ERRO')
        NOT NULL DEFAULT 'PENDENTE' AFTER cliente_identificado,
    ADD COLUMN processado_em DATETIME NULL AFTER status_ocr;

-- ============================================================
-- PASSO 3 (recomendado) — checagem de idempotencia do indice
-- ============================================================
-- Mesmo cuidado do PASSO 1, mas para o indice. Se retornar
-- indice_ja_existe = 1, NAO rode o PASSO 4 de novo.
SELECT COUNT(*) AS indice_ja_existe
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'tb_atendimento_nota'
  AND INDEX_NAME = 'idx_status_ocr';

-- ============================================================
-- PASSO 4 — aplicar o indice
-- ============================================================
-- So execute esta linha se o PASSO 3 retornou indice_ja_existe = 0.
ALTER TABLE tb_atendimento_nota
    ADD INDEX idx_status_ocr (status_ocr);

-- ============================================================
-- PASSO 5 (opcional, recomendado) — retrocompatibilidade de dados antigos
-- ============================================================
-- Notas ja existentes antes desta migration ficam com status_ocr =
-- 'PENDENTE' (o DEFAULT). Se desejar refletir, para notas ja processadas
-- pelo fluxo antigo (cliente_identificado = 1), que elas ja foram
-- "identificadas" por algum mecanismo anterior, execute (opcional, NAO
-- obrigatorio, nao afeta o funcionamento do novo endpoint, que so cria
-- registros NOVOS a partir de PENDENTE):
-- UPDATE tb_atendimento_nota SET status_ocr = 'IDENTIFICADA', processado_em = criado_em
--     WHERE cliente_identificado = 1 AND status_ocr = 'PENDENTE';
