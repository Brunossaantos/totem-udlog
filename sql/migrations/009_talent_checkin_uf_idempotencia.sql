-- Migration 009 — UF do CRLV (bloqueio de veiculo.uf resolvido) +
-- idempotencia de 5 estados do envio ao Talent (Portaria/Checkin), demanda
-- integracao-talent-portaria-checkin (2026-09-09). Ver
-- docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md.
--
-- crlv_uf / crlv_snapshot_uf: mesmo raciocinio ja usado para
-- crlv_ano/crlv_snapshot_exercicio (migrations 005/006) — snapshot so
-- gravado quando a origem e VIO_TRIAL/VIO_VALIDADO, usado para decidir o
-- rebaixamento para MANUAL quando o atendente edita a UF na tela de
-- confirmacao.
--
-- tb_vio_cache_crlv.uf: cache SEM uf (registros gravados antes desta
-- migration) e tratado como INCOMPLETO por App\Dao\VioCacheDao::
-- buscarCrlvValido() (WHERE uf IS NOT NULL) — nunca reaproveitado como
-- cache-hit, forca nova consulta ao VIO.
--
-- talent_checkin_status (5 estados: NAO_ENVIADO, ENVIANDO, ENVIADO,
-- ERRO_REPROCESSAVEL, ENVIO_INDETERMINADO) / talent_tentativa_id /
-- talent_status_iniciado_em: mesmo padrao de CAS (compare-and-swap via
-- UPDATE...WHERE) ja usado para cnh_status_processamento/
-- crlv_status_processamento (migration 007), aplicado ao envio ao Talent —
-- ver App\Dao\AtendimentoDao::iniciarEnvioTalent/gravarResultadoEnvioTalent/
-- marcarEnvioTalentObsoletoComoIndeterminado.
--
-- NAO cria coluna de retorno bruto do Talent (talent_retorno_bruto) —
-- decisao explicita do usuario nesta rodada, REVOGANDO uma mencao anterior
-- em rodada de planejamento: nunca persistir corpo bruto de resposta do
-- Talent (pode ecoar CPF/CNH em mensagem de validacao).
--
-- Padrao idempotente: SET NAMES utf8mb4 + SQL preparado condicional, mesmo
-- padrao ja usado em 003/005/006/007.

SET NAMES utf8mb4;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_uf VARCHAR(2) NULL AFTER crlv_ano',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_uf'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN crlv_snapshot_uf VARCHAR(2) NULL AFTER crlv_snapshot_exercicio',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'crlv_snapshot_uf'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE tb_atendimento ADD COLUMN talent_checkin_status ENUM('NAO_ENVIADO','ENVIANDO','ENVIADO','ERRO_REPROCESSAVEL','ENVIO_INDETERMINADO') NOT NULL DEFAULT 'NAO_ENVIADO' AFTER talent_protocolo",
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'talent_checkin_status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN talent_tentativa_id VARCHAR(32) NULL AFTER talent_checkin_status',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'talent_tentativa_id'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_atendimento ADD COLUMN talent_status_iniciado_em DATETIME NULL AFTER talent_tentativa_id',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_atendimento' AND COLUMN_NAME = 'talent_status_iniciado_em'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_vio_cache_crlv ADD COLUMN uf VARCHAR(2) NULL AFTER exercicio',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_vio_cache_crlv' AND COLUMN_NAME = 'uf'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
