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

-- Diretorio local de clientes (autocomplete do recebimento + casamento por CNPJ via chave da NF)
CREATE TABLE tb_cliente (
    id_cliente   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(150) NOT NULL,
    cnpj         VARCHAR(20) NOT NULL UNIQUE,
    ativo        TINYINT(1) NOT NULL DEFAULT 1,
    criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nome (nome),
    INDEX idx_cnpj (cnpj)
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
    criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_atendimento) REFERENCES tb_atendimento(id_atendimento),
    INDEX idx_chave (chave_acesso),
    UNIQUE KEY uk_atendimento_ordem (id_atendimento, ordem)
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
