-- Migration 015 — Migracao da integracao de CNH/CRLV do Serpro (VIO Decode
-- direto, App\Rn\VioDecodeClient) para a API contratada `vio.api.br`
-- (demanda migracao-vio-api-br-com-cache, `/01-implementacao` de 2026-09-25).
-- Ver docs/handoffs/2026-09-25-migracao-vio-api-br-com-cache.md.
--
-- App\Rn\VioDecodeClient.php permanece FISICAMENTE intocado no repositorio
-- (rollback manual/controlado por configuracao, nunca fallback automatico) —
-- esta migration NAO remove nenhum valor de ENUM/coluna usado pelo fluxo
-- antigo (PENDENTE/PROCESSANDO/CONCLUIDO/ERRO continuam validos), apenas
-- ACRESCENTA os novos estados/colunas exigidos pelo fluxo assincrono novo.
--
-- Novos estados de cnh_status_processamento/crlv_status_processamento:
-- ENVIANDO (POST em voo, ainda sem id externo confirmado),
-- PROCESSANDO_LEITURA (id externo ja persistido, leitura do QR/documento em
-- andamento no fornecedor), PROCESSANDO_COMPARACAO (leitura completed,
-- aguardando a comparacao OCR), INDETERMINADO (timeout/ambiguidade apos o
-- envio ja ter sido confirmado, ou resposta fora do contrato esperado —
-- NUNCA volta a PENDENTE, nunca dispara novo POST automatico). PENDENTE
-- continua sendo o estado inicial; CONCLUIDO/ERRO mantem o mesmo significado
-- de antes (CONCLUIDO = fornecedor respondeu de forma definitiva, aprovado
-- ou nao; ERRO = falha tecnica SEM ambiguidade, permite nova tentativa
-- explicita via iniciar-processamento).
--
-- Novos valores VIO_CACHE/VIO_API_BR em cnh_origem_validacao/
-- crlv_origem_validacao (rodada corretiva de 2026-09-26, separacao de
-- origens de auditoria — ver handoff, secao "Rodada corretiva (2026-09-26)"):
-- VIO_CACHE = cache local seguro (tb_vio_api_cache_cnh/tb_vio_api_cache_crlv,
-- ver migration 016) evitando nova chamada paga ao fornecedor para o MESMO
-- QR dentro do TTL configurado (VIO_API_BR_CACHE_TTL_DIAS); VIO_API_BR =
-- validacao NOVA concluida em tempo real pela vio.api.br (nunca mais gravado
-- como VIO_VALIDADO — esse valor volta a significar EXCLUSIVAMENTE o
-- historico da integracao direta antiga com o Serpro/VioDecodeClient, nunca
-- mais gravado por codigo novo a partir desta rodada).
--
-- Colunas novas por documento (cnh/crlv):
-- *_vio_api_id: ID externo OPACO da vio.api.br — conceito DISTINTO de
--   *_tentativa_id (CAS local, ja existente desde a migration 007).
--   Persistido IMEDIATAMENTE apos o POST /api/qrcode/read responder com
--   sucesso, ANTES de qualquer polling (App\Dao\AtendimentoDao::
--   gravarIdExternoVioApiBr). Nunca reaproveitado entre tentativas
--   diferentes (uma nova tentativa grava NULL neste campo ate a proxima
--   confirmacao de envio).
-- *_vio_api_enviado_em: timestamp do momento em que o POST foi confirmado
--   (id externo persistido) — usado, junto com *_processamento_iniciado_em
--   ja existente, para o limite de duracao maxima de processamento
--   (App\Dao\AtendimentoDao::marcarProcessamentoVioApiBrExpiradoComoIndeterminado).
-- *_vio_api_fingerprint / *_vio_api_fingerprint_versao: fingerprint
--   HMAC-SHA256 do QR bruto (NUNCA o QR em si) + versao da chave HMAC usada
--   (VIO_API_BR_CACHE_HMAC_KEY_V{n}), calculados e persistidos no momento do
--   envio — necessarios porque o QR bruto so existe em memoria durante
--   iniciar-processamento (nunca persistido), mas o registro em cache
--   (VIO_CACHE) so pode ser gravado bem mais tarde, quando a comparacao
--   assincrona finalmente responde `completed` (status-processamento). Sem
--   esta persistencia intermediaria nao haveria como calcular o cache-write
--   tardio sem re-solicitar o QR ao motorista.
--
-- Padrao idempotente: SET NAMES utf8mb4 + SQL preparado condicional (mesmo
-- padrao ja usado em 003/005/006/007/009). Para as alteracoes de ENUM
-- (MODIFY COLUMN, que nao aceita "IF NOT EXISTS"), a idempotencia e obtida
-- verificando se o valor novo JA esta presente em COLUMN_TYPE antes de
-- executar o MODIFY — reexecucao em um banco ja convergido e sempre um
-- no-op seguro.

SET NAMES utf8mb4;

-- ============================================================
-- ENUM cnh_status_processamento / crlv_status_processamento — acrescenta
-- ENVIANDO/PROCESSANDO_LEITURA/PROCESSANDO_COMPARACAO/INDETERMINADO,
-- mantendo PENDENTE/PROCESSANDO/CONCLUIDO/ERRO ja existentes.
-- ============================================================

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'SELECT 1',
        "ALTER TABLE tb_atendimento MODIFY COLUMN cnh_status_processamento ENUM('PENDENTE','PROCESSANDO','ENVIANDO','PROCESSANDO_LEITURA','PROCESSANDO_COMPARACAO','CONCLUIDO','ERRO','INDETERMINADO') NOT NULL DEFAULT 'PENDENTE'"
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_status_processamento'
      AND COLUMN_TYPE NOT LIKE '%INDETERMINADO%'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'SELECT 1',
        "ALTER TABLE tb_atendimento MODIFY COLUMN crlv_status_processamento ENUM('PENDENTE','PROCESSANDO','ENVIANDO','PROCESSANDO_LEITURA','PROCESSANDO_COMPARACAO','CONCLUIDO','ERRO','INDETERMINADO') NOT NULL DEFAULT 'PENDENTE'"
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_status_processamento'
      AND COLUMN_TYPE NOT LIKE '%INDETERMINADO%'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- ENUM cnh_origem_validacao / crlv_origem_validacao — acrescenta VIO_CACHE e
-- VIO_API_BR (rodada corretiva de 2026-09-26 acrescentou VIO_API_BR — esta
-- migration nunca foi publicada/aplicada em banco real, editada diretamente
-- por instrucao explicita do usuario, sem criar migration 017).
-- ============================================================

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'SELECT 1',
        "ALTER TABLE tb_atendimento MODIFY COLUMN cnh_origem_validacao ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO','VIO_CACHE','VIO_API_BR') NOT NULL DEFAULT 'NAO_VALIDADO'"
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_origem_validacao'
      AND COLUMN_TYPE NOT LIKE '%VIO_API_BR%'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'SELECT 1',
        "ALTER TABLE tb_atendimento MODIFY COLUMN crlv_origem_validacao ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO','VIO_CACHE','VIO_API_BR') NOT NULL DEFAULT 'NAO_VALIDADO'"
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_origem_validacao'
      AND COLUMN_TYPE NOT LIKE '%VIO_API_BR%'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- Colunas novas — CNH
-- ============================================================

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_vio_api_id VARCHAR(128) NULL AFTER cnh_tentativa_id',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_vio_api_id'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_vio_api_enviado_em DATETIME NULL AFTER cnh_vio_api_id',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_vio_api_enviado_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_vio_api_fingerprint CHAR(64) NULL AFTER cnh_vio_api_enviado_em',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_vio_api_fingerprint'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN cnh_vio_api_fingerprint_versao TINYINT UNSIGNED NULL AFTER cnh_vio_api_fingerprint',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'cnh_vio_api_fingerprint_versao'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- Colunas novas — CRLV
-- ============================================================

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_vio_api_id VARCHAR(128) NULL AFTER crlv_tentativa_id',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_vio_api_id'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_vio_api_enviado_em DATETIME NULL AFTER crlv_vio_api_id',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_vio_api_enviado_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_vio_api_fingerprint CHAR(64) NULL AFTER crlv_vio_api_enviado_em',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_vio_api_fingerprint'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_vio_api_fingerprint_versao TINYINT UNSIGNED NULL AFTER crlv_vio_api_fingerprint',
        'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_vio_api_fingerprint_versao'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
