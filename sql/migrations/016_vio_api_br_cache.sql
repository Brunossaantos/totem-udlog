-- Migration 016 — Cache local seguro (VIO_CACHE) exclusivo da nova
-- integracao `vio.api.br` (demanda migracao-vio-api-br-com-cache,
-- `/01-implementacao` de 2026-09-25). Ver
-- docs/handoffs/2026-09-25-migracao-vio-api-br-com-cache.md.
--
-- NAO altera/reaproveita tb_vio_cache_cnh/tb_vio_cache_crlv (migration 005,
-- cache do fluxo antigo Serpro/VioDecodeClient) — tabelas NOVAS e
-- COMPLETAMENTE SEPARADAS, para nunca misturar cache de fornecedores
-- diferentes sob o mesmo fingerprint/UNIQUE KEY. As tabelas antigas
-- permanecem intocadas (suporte ao rollback manual do fluxo Serpro).
--
-- fingerprint = hex do HMAC-SHA256(QR bruto, VIO_API_BR_CACHE_HMAC_KEY_V{n})
-- — o QR bruto e o segredo HMAC NUNCA sao persistidos, so o hash resultante.
-- hmac_versao identifica qual chave (VIO_API_BR_CACHE_HMAC_KEY_V{n}) gerou
-- aquele fingerprint especifico, permitindo rotacao da chave sem invalidar
-- em massa (cache antigo simplesmente para de receber hits novos e expira
-- pelo TTL, ver VIO_API_BR_CACHE_TTL_DIAS).
--
-- fornecedor fixo 'VIO_API_BR' (unico valor usado por esta tabela) —
-- coluna mantida por clareza de auditoria/consulta e para nunca colidir
-- estruturalmente com o cache antigo, mesmo que ambos existam ao mesmo
-- tempo no banco.
--
-- versao_mapeamento identifica a versao das REGRAS de extracao/aprovacao
-- (App\Rn\DocumentoRn::VERSAO_MAPEAMENTO_CNH/VERSAO_MAPEAMENTO_CRLV) usadas
-- para gerar aquele registro — mudar essa versao invalida o cache anterior
-- (nunca reaproveitado por uma versao de mapeamento diferente).
--
-- nome/cpf (CNH) e renavam (CRLV) cifrados em repouso via
-- Util\CriptografiaHelper (AES-256-GCM, MESMA chave/mecanismo ja usado pelo
-- cache antigo — DOCUMENTO_DATA_KEY, nenhuma criptografia nova). Placa/UF/
-- exercicio/RNTC/tipo de veiculo seguem texto plano, mesmo tratamento ja
-- aprovado hoje para dado de veiculo (nao e dado pessoal direto).
--
-- resumo_comparacao_reliable/resumo_comparacao_mismatched: resumo MINIMO da
-- comparacao (nunca o `summary` bruto inteiro, nunca `ocr_lines`, nunca
-- comparacao por campo completa) — apenas o suficiente para auditoria futura
-- de que aquele cache-hit correspondeu a uma aprovacao confiavel.
--
-- LIMITACAO REGISTRADA EXPLICITAMENTE (nao mascarar em avaliacoes futuras):
-- um cache-hit NUNCA executa nova comparacao OCR no fornecedor — ele reusa a
-- aprovacao/decisao anterior enquanto dentro do TTL. Isto NAO "reconfirma" o
-- documento fisico apresentado na revisita atual. O controle operacional
-- aceito para esse risco residual (decisao ja tomada pelo usuario) e a
-- combinacao de: (a) supervisao humana continua da apresentacao fisica do
-- documento no totem, e (b) TTL inicial de 7 dias (VIO_API_BR_CACHE_TTL_DIAS),
-- sensivelmente mais curto que a retencao de 30 dias do fornecedor. Qualquer
-- ampliacao futura desse TTL exige nova decisao explicita do usuario — nao
-- deve ser alterada silenciosamente por otimizacao de custo.
--
-- Estado ('VALIDO'/'REVOGADO') permite revogacao administrativa futura sem
-- exigir DELETE (nunca implementado nesta rodada — so a coluna existe, sem
-- nenhum endpoint/acao que a altere para 'REVOGADO' ainda).
--
-- Padrao idempotente: SET NAMES utf8mb4 + CREATE TABLE IF NOT EXISTS (mesmo
-- padrao ja usado em 004/005/007), sem DROP/TRUNCATE.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tb_vio_api_cache_cnh (
    id_cache                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint                   CHAR(64) NOT NULL,
    hmac_versao                   TINYINT UNSIGNED NOT NULL,
    fornecedor                    ENUM('VIO_API_BR') NOT NULL DEFAULT 'VIO_API_BR',
    versao_mapeamento             SMALLINT UNSIGNED NOT NULL,
    nome_cifrado                  VARBINARY(512) NOT NULL,
    cpf_cifrado                   VARBINARY(512) NOT NULL,
    data_validade                 DATE NOT NULL,
    resumo_comparacao_reliable    TINYINT(1) NOT NULL,
    resumo_comparacao_mismatched  SMALLINT UNSIGNED NOT NULL,
    estado                        ENUM('VALIDO','REVOGADO') NOT NULL DEFAULT 'VALIDO',
    origem_original                ENUM('VIO_API_BR') NOT NULL DEFAULT 'VIO_API_BR',
    validado_em                   DATETIME NOT NULL,
    revalidar_apos                DATETIME NULL,
    expira_em                     DATETIME NOT NULL,
    criado_em                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_fingerprint_versao_cnh (fingerprint, hmac_versao, fornecedor, versao_mapeamento),
    INDEX idx_expira_em_cnh (expira_em),
    INDEX idx_estado_cnh (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_vio_api_cache_crlv (
    id_cache                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint                   CHAR(64) NOT NULL,
    hmac_versao                   TINYINT UNSIGNED NOT NULL,
    fornecedor                    ENUM('VIO_API_BR') NOT NULL DEFAULT 'VIO_API_BR',
    versao_mapeamento             SMALLINT UNSIGNED NOT NULL,
    placa                         VARCHAR(8) NOT NULL,
    exercicio                     SMALLINT NOT NULL,
    uf                            VARCHAR(2) NULL,
    rntc                          VARCHAR(32) NULL,
    tipo_veiculo                  VARCHAR(60) NULL,
    renavam_cifrado                VARBINARY(512) NULL,
    resumo_comparacao_reliable    TINYINT(1) NOT NULL,
    resumo_comparacao_mismatched  SMALLINT UNSIGNED NOT NULL,
    estado                        ENUM('VALIDO','REVOGADO') NOT NULL DEFAULT 'VALIDO',
    origem_original                ENUM('VIO_API_BR') NOT NULL DEFAULT 'VIO_API_BR',
    validado_em                   DATETIME NOT NULL,
    revalidar_apos                DATETIME NULL,
    expira_em                     DATETIME NOT NULL,
    criado_em                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_fingerprint_versao_crlv (fingerprint, hmac_versao, fornecedor, versao_mapeamento),
    INDEX idx_expira_em_crlv (expira_em),
    INDEX idx_estado_crlv (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
