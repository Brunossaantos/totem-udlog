-- Empresa/armazem do Talent (Portaria/Checkin) — cnpjArmazem do payload
-- enviado ao Talent vem EXCLUSIVAMENTE daqui, via tb_totem.id_empresa,
-- nunca do frontend. Ver sql/migrations/008_tb_empresa_totem_vinculo.sql.
CREATE TABLE tb_empresa (
    id_empresa  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(100) NOT NULL,
    cnpj        VARCHAR(14) NOT NULL UNIQUE,
    ativo       TINYINT(1) NOT NULL DEFAULT 1,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Totens cadastrados: e isso que deixa o sistema pronto pra multi-totem
CREATE TABLE tb_totem (
    id_totem      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo        VARCHAR(30) NOT NULL UNIQUE,
    nome          VARCHAR(100) NOT NULL,
    localizacao   VARCHAR(150) NULL,
    id_empresa    INT UNSIGNED NULL,
    token_api     CHAR(64) NOT NULL UNIQUE,
    ativo         TINYINT(1) NOT NULL DEFAULT 1,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_empresa) REFERENCES tb_empresa(id_empresa)
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
    ordem_coleta      VARCHAR(50) NULL,
    cliente_nome      VARCHAR(150) NULL,
    cliente_cnpj      VARCHAR(20) NULL,

    motorista_nome    VARCHAR(150) NULL,
    motorista_cpf     VARCHAR(14) NULL,
    cnh_validade      DATE NULL,
    -- Origem/status/timestamp da validacao de CNH via VIO Decode (Serpro,
    -- QR Code) ou preenchimento manual. Ver sql/migrations/005_vio_decode_cnh_crlv.sql.
    cnh_origem_validacao  ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO') NOT NULL DEFAULT 'NAO_VALIDADO',
    cnh_status_revisao    ENUM('OK','PENDENTE_REVISAO') NOT NULL DEFAULT 'OK',
    cnh_validado_em       DATETIME NULL,
    -- Snapshot do valor EXATO retornado pelo VIO/cache no momento em que
    -- cnh_origem_validacao foi gravado como VIO_TRIAL/VIO_VALIDADO — nunca
    -- atualizado por edicao manual posterior, usado so para decidir o
    -- rebaixamento para MANUAL quando o atendente edita a tela exp_confirma.
    -- NULL quando a origem gravada e MANUAL. Ver
    -- sql/migrations/006_snapshot_vio_cnh_crlv.sql.
    cnh_snapshot_nome     VARCHAR(150) NULL,
    cnh_snapshot_cpf      VARCHAR(14) NULL,
    cnh_snapshot_validade DATE NULL,
    -- Processamento ASSINCRONO de CNH (demanda expedicao-vio-cnh-crlv,
    -- REPLANEJAMENTO 2026-09-09): campo ORTOGONAL a cnh_origem_validacao —
    -- responde "o backend terminou de tentar" (nao "com que resultado").
    -- tentativa_id/processamento_iniciado_em protegem contra respostas
    -- "zumbi" e timeout. Ver sql/migrations/007_status_processamento_assincrono_vio.sql.
    cnh_status_processamento      ENUM('PENDENTE','PROCESSANDO','CONCLUIDO','ERRO') NOT NULL DEFAULT 'PENDENTE',
    cnh_processamento_iniciado_em DATETIME NULL,
    cnh_tentativa_id              VARCHAR(32) NULL,
    crlv_ano          SMALLINT NULL,
    -- UF do veiculo (obrigatoria pelo Talent, veiculo.uf) — extraida de
    -- data.uf da resposta VIO Decode do CRLV ou preenchida manualmente
    -- (dropdown fechado de 27 UFs). Ver
    -- sql/migrations/009_talent_checkin_uf_idempotencia.sql.
    crlv_uf           VARCHAR(2) NULL,
    -- RNTC (Registro Nacional de Transportador Rodoviario de Cargas) e tipo
    -- de veiculo (obrigatorios pelo Talent, veiculo.rntc/veiculo.tipo,
    -- confirmado em teste real de Producao em 2026-09-10) — extraidos de
    -- data.rntrc/data.tipo da resposta VIO Decode do CRLV (a chave real na
    -- resposta da VIO e `rntrc`, nao `rntc` — divergencia de nomenclatura
    -- entre origem e destino, ver App\Rn\DocumentoRn) ou preenchidos
    -- manualmente. Ver sql/migrations/010_talent_rntc_tipo_veiculo.sql.
    crlv_rntc         VARCHAR(20) NULL,
    crlv_tipo_veiculo VARCHAR(60) NULL,
    crlv_origem_validacao ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO') NOT NULL DEFAULT 'NAO_VALIDADO',
    crlv_status_revisao   ENUM('OK','PENDENTE_REVISAO') NOT NULL DEFAULT 'OK',
    crlv_validado_em      DATETIME NULL,
    crlv_snapshot_placa      VARCHAR(8) NULL,
    crlv_snapshot_exercicio  SMALLINT NULL,
    crlv_snapshot_uf         VARCHAR(2) NULL,
    crlv_snapshot_rntc         VARCHAR(20) NULL,
    crlv_snapshot_tipo_veiculo VARCHAR(60) NULL,
    crlv_status_processamento      ENUM('PENDENTE','PROCESSANDO','CONCLUIDO','ERRO') NOT NULL DEFAULT 'PENDENTE',
    crlv_processamento_iniciado_em DATETIME NULL,
    crlv_tentativa_id              VARCHAR(32) NULL,

    possui_ajudante   TINYINT(1) NOT NULL DEFAULT 0,
    ajudante_nome     VARCHAR(150) NULL,
    ajudante_cpf      VARCHAR(14) NULL,

    pasta_documentos  VARCHAR(255) NULL,
    dados_brutos_json JSON NULL,

    talent_enviado_em DATETIME NULL,
    talent_senha      VARCHAR(20) NULL,
    talent_protocolo  VARCHAR(60) NULL,
    -- Idempotencia de 5 estados do envio ao Talent (Portaria/Checkin) — ver
    -- App\Dao\AtendimentoDao::iniciarEnvioTalent/gravarResultadoEnvioTalent/
    -- marcarEnvioTalentObsoletoComoIndeterminado e
    -- sql/migrations/009_talent_checkin_uf_idempotencia.sql. NUNCA guarda
    -- corpo bruto de resposta do Talent (decisao explicita de seguranca).
    talent_checkin_status ENUM('NAO_ENVIADO','ENVIANDO','ENVIADO','ERRO_REPROCESSAVEL','ENVIO_INDETERMINADO') NOT NULL DEFAULT 'NAO_ENVIADO',
    talent_tentativa_id VARCHAR(32) NULL,
    talent_status_iniciado_em DATETIME NULL,

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

-- Cache local de CNH/CRLV ja validados pela VIO Decode (Serpro), chaveado
-- por HMAC-SHA256 do QR bruto (identificador_qr) — o QR bruto/hex NUNCA e
-- persistido, so o hash. UNIQUE(identificador_qr, ambiente) impede cruzar
-- cache entre trial/producao. Ver sql/migrations/005_vio_decode_cnh_crlv.sql.
CREATE TABLE tb_vio_cache_cnh (
    id_cache        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identificador_qr CHAR(64) NOT NULL,
    ambiente        ENUM('trial','production') NOT NULL,
    nome_cifrado    VARBINARY(512) NOT NULL,
    cpf_cifrado     VARBINARY(512) NOT NULL,
    data_validade   DATE NOT NULL,
    origem          ENUM('VIO_TRIAL','VIO_VALIDADO') NOT NULL,
    data_validacao  DATETIME NOT NULL,
    valido_ate      DATETIME NOT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_qr_ambiente (identificador_qr, ambiente),
    INDEX idx_valido_ate (valido_ate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tb_vio_cache_crlv (
    id_cache        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identificador_qr CHAR(64) NOT NULL,
    ambiente        ENUM('trial','production') NOT NULL,
    placa           VARCHAR(8) NOT NULL,
    exercicio       SMALLINT NOT NULL,
    -- UF do veiculo — cache SEM uf (gravado antes desta coluna existir) e
    -- tratado como INCOMPLETO por VioCacheDao::buscarCrlvValido() (nunca um
    -- cache-hit valido). Ver sql/migrations/009_talent_checkin_uf_idempotencia.sql.
    uf              VARCHAR(2) NULL,
    -- RNTC/tipo de veiculo — cache SEM esses campos (gravado antes desta
    -- coluna existir) e tratado como INCOMPLETO por
    -- VioCacheDao::buscarCrlvValido() (nunca um cache-hit valido). Ver
    -- sql/migrations/010_talent_rntc_tipo_veiculo.sql.
    rntc            VARCHAR(20) NULL,
    tipo_veiculo    VARCHAR(60) NULL,
    origem          ENUM('VIO_TRIAL','VIO_VALIDADO') NOT NULL,
    data_validacao  DATETIME NOT NULL,
    valido_ate      DATETIME NOT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_qr_ambiente (identificador_qr, ambiente),
    INDEX idx_valido_ate (valido_ate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate limit PROPRIO do polling de documento.php?acao=status-processamento,
-- chave por (id_atendimento, tipo_documento, janela) — diferente de
-- tb_rate_limit_ocr (chave por id_totem) porque o polling e por
-- documento/atendimento. Ver sql/migrations/007_status_processamento_assincrono_vio.sql.
CREATE TABLE tb_rate_limit_vio_status (
    id_atendimento  BIGINT UNSIGNED NOT NULL,
    tipo_documento  ENUM('cnh','crlv') NOT NULL,
    janela          INT UNSIGNED NOT NULL,
    contador        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_atendimento, tipo_documento, janela),
    FOREIGN KEY (id_atendimento) REFERENCES tb_atendimento(id_atendimento)
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
