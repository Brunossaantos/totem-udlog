-- Totens cadastrados: e isso que deixa o sistema pronto pra multi-totem
CREATE TABLE tb_totem (
    id_totem      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo        VARCHAR(30) NOT NULL UNIQUE,
    nome          VARCHAR(100) NOT NULL,
    localizacao   VARCHAR(150) NULL,
    token_api     CHAR(64) NOT NULL UNIQUE,
    ativo         TINYINT(1) NOT NULL DEFAULT 1,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Diretorio local de clientes (autocomplete do recebimento + casamento por
-- CNPJ via chave da NF + identificacao automatica de cliente via OCR nas
-- notas do Recebimento, NotaFiscalRn::identificarCliente). Os tres fluxos
-- leem a MESMA tabela (decisao intencional, ver
-- sql/migrations/003_tb_cliente_razao_normalizada.sql). Coluna
-- razao_social_normalizada guarda o "nome" ja normalizado pelo mesmo
-- algoritmo de Util\RazaoSocialMatcher::normalizar(), usada pelo fuzzy
-- match para nao precisar normalizar a listagem inteira a cada chamada.
CREATE TABLE tb_cliente (
    id_cliente               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome                     VARCHAR(150) NOT NULL,
    razao_social_normalizada VARCHAR(150) NULL,
    cnpj                     VARCHAR(20) NOT NULL UNIQUE,
    ativo                    TINYINT(1) NOT NULL DEFAULT 1,
    criado_em                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nome (nome),
    INDEX idx_cnpj (cnpj),
    INDEX idx_razao_social_normalizada (razao_social_normalizada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sessao de atendimento: funciona como state machine.
-- Se o totem travar/reiniciar no meio do fluxo, retoma daqui em vez de perder tudo
CREATE TABLE tb_atendimento (
    id_atendimento    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo_publico    CHAR(36) NOT NULL UNIQUE,
    id_totem          INT UNSIGNED NOT NULL,
    tipo              ENUM('expedicao','recebimento') NOT NULL,
    etapa_atual       VARCHAR(40) NOT NULL DEFAULT 'placa',
    status            ENUM('em_andamento','concluido','cancelado','bloqueado') NOT NULL DEFAULT 'em_andamento',

    placa             VARCHAR(8) NULL,
    ordem_coleta      VARCHAR(30) NULL,
    cliente_nome      VARCHAR(150) NULL,
    cliente_cnpj      VARCHAR(20) NULL,

    motorista_nome    VARCHAR(150) NULL,
    motorista_cpf     VARCHAR(14) NULL,
    cnh_validade      DATE NULL,
    crlv_ano          SMALLINT NULL,

    possui_ajudante   TINYINT(1) NOT NULL DEFAULT 0,
    ajudante_nome     VARCHAR(150) NULL,
    ajudante_cpf      VARCHAR(14) NULL,

    pasta_documentos  VARCHAR(255) NULL,
    dados_brutos_json JSON NULL,

    talent_enviado_em DATETIME NULL,
    talent_senha      VARCHAR(20) NULL,
    talent_protocolo  VARCHAR(60) NULL,

    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (id_totem) REFERENCES tb_totem(id_totem),
    INDEX idx_placa (placa),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notas fiscais do recebimento (1 atendimento pode ter varias)
CREATE TABLE tb_atendimento_nota (
    id_nota               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_atendimento        BIGINT UNSIGNED NOT NULL,
    ordem                 TINYINT UNSIGNED NOT NULL,
    arquivo               VARCHAR(255) NOT NULL,
    chave_acesso          CHAR(44) NULL,
    cnpj_emitente         VARCHAR(20) NULL,
    cliente_identificado  TINYINT(1) NOT NULL DEFAULT 0,
    -- status_ocr/processado_em: identificacao de cliente via OCR client-side
    -- (Tesseract.js) + endpoint sincrono nota.php?acao=identificar-cliente
    -- (demanda recebimento-leitura-notas). Ver sql/migrations/002_status_ocr_atendimento_nota.sql
    -- para instalacoes ja existentes.
    status_ocr            ENUM('PENDENTE','PROCESSANDO','IDENTIFICADA','NAO_IDENTIFICADA','ERRO')
                           NOT NULL DEFAULT 'PENDENTE',
    processado_em         DATETIME NULL,
    criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_atendimento) REFERENCES tb_atendimento(id_atendimento),
    INDEX idx_chave (chave_acesso),
    INDEX idx_status_ocr (status_ocr),
    UNIQUE KEY uk_atendimento_ordem (id_atendimento, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Controle de taxa (rate limit) por totem para nota.php?acao=identificar-cliente.
-- Janela fixa de 60s (janela = FLOOR(unix_timestamp/60)), incremento atomico
-- via INSERT ... ON DUPLICATE KEY UPDATE contra a PK composta. Ver
-- sql/migrations/004_tb_rate_limit_ocr.sql para o raciocinio completo
-- (limite de 30 chamadas/totem/janela, escolhido em 2026-09-08).
CREATE TABLE tb_rate_limit_ocr (
    id_totem       INT UNSIGNED NOT NULL,
    janela         INT UNSIGNED NOT NULL,
    contador       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_totem, janela),
    FOREIGN KEY (id_totem) REFERENCES tb_totem(id_totem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fila de reenvio quando a API do Talent falha (cron processa)
CREATE TABLE tb_fila_envio (
    id_fila               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_atendimento        BIGINT UNSIGNED NOT NULL,
    tentativas            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_erro           TEXT NULL,
    status                ENUM('pendente','sucesso','falhou_definitivo') NOT NULL DEFAULT 'pendente',
    proxima_tentativa_em  DATETIME NULL,
    criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (id_atendimento) REFERENCES tb_atendimento(id_atendimento),
    INDEX idx_status_proxima (status, proxima_tentativa_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
