-- Migration 017 — Suporte a CNH digital (documento eletronico oficial do
-- app do Detran/Senatran, PDF de 1 pagina com QR Code), alem da CNH fisica
-- fotografada (frente+verso, PDF de 2 paginas) ja suportada pela demanda
-- `migracao-vio-api-br-com-cache`. Motivado por teste real que usou uma CNH
-- digital de 1 pagina (docs/CNH-e.pdf.pdf) e parou no preflight interno do
-- totem, que exigia incondicionalmente 2 paginas.
--
-- Nova coluna cnh_modo_captura (ENUM('FISICA','DIGITAL') NULL DEFAULT NULL)
-- em tb_atendimento — NULL = modo ainda nao escolhido pelo motorista (nunca
-- gravado explicitamente por nenhum codigo desta migration; so a acao nova
-- App\Controller\DocumentoController::definirModoCnh() grava um valor).
-- Comportamento FAIL-SAFE quando NULL, decidido em codigo (nao aqui):
-- tratado como 'FISICA' (2 arquivos exigidos, PDF de 2 paginas, mesmo
-- comportamento de antes desta mudanca) — nenhum atendimento em andamento
-- que ja tenha cnh_frente.jpg salvo sem esta coluna preenchida pode quebrar.
--
-- Aditiva, idempotente (mesmo padrao INFORMATION_SCHEMA/ADD COLUMN
-- condicional ja usado em 003/005/006/007/009/015) — reexecucao em banco ja
-- convergido e sempre um no-op seguro.
--
-- Ver docs/handoffs/2026-09-25-migracao-vio-api-br-com-cache.md, secao
-- "Suporte a CNH digital (1 pagina) — 2026-09-27".

SET NAMES utf8mb4;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        "ALTER TABLE tb_atendimento ADD COLUMN cnh_modo_captura ENUM('FISICA','DIGITAL') NULL DEFAULT NULL AFTER cnh_status_processamento",
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_modo_captura'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
