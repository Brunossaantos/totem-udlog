# Banco de dados do totem-udlog

Este documento descreve os dois bancos que o sistema usa: o **banco do totem** (estrutura em `sql/schema.sql`) e o **banco externo de gestão de coletas** (usado só para ordens de coleta). Base: HEAD `4188fee`, 2026-10-09.

Documentos relacionados: [regras-negócio](regras-negocio.md), [APIs](apis/README.md) e os fluxos em [`fluxos/`](fluxos/01-lgpd-e-inicio-atendimento.md).

## Legenda de confiança

| Marca | Significado |
|---|---|
| **[C]** | Confirmado lendo o código ou o `sql/schema.sql` nesta documentação. |
| **[T: nome]** | Existe teste em `tests/manual/` que cobre a regra. O teste não foi reexecutado ao escrever este documento; o "passa" vem de registro histórico. |
| **[A]** | Depende de ambiente ou terceiro (Hostgator, banco externo real, n8n, Talent). Só vale como relato registrado. |
| **[P]** | PENDENTE DE CONFIRMAÇÃO. Não verificado; nada foi presumido. |

Quando o código e registros anteriores divergem, o código vence (ver "Divergências").

---

## 1. Como o banco do totem é criado hoje

- A única fonte da estrutura é `sql/schema.sql` (18 tabelas). **[C]**
- As migrations 001-023 do banco do totem e as externas 001-004 foram **removidas do repositório** na higienização de 2026-10-09. Não existe mais histórico de migrations. **[C]**
- O schema foi provado idêntico a "schema antigo + migrations 014-023" e equivalente à estrutura de produção (MySQL 5.7.44), com uma diferença cosmética em `tb_totem` (ver "Divergências"). **[A]**
- Para mudar o banco no futuro, cria-se uma **nova migration** (o repositório não tem pasta de migrations; a nova precisa ser criada junto com a atualização do `sql/schema.sql`).
- O cabeçalho do `schema.sql` manda aplicar "só este arquivo numa instalação nova". Comentários internos que citam `sql/migrations/00x_*.sql` são só histórico. **[C]**

### Propriedades do script

- Só `CREATE TABLE` simples e um `ALTER TABLE tb_totem ADD CONSTRAINT fk_totem_criado_por`. Não tem `CREATE DATABASE`, `USE`, `DROP`, `IF NOT EXISTS`, `SET NAMES`, `SET FOREIGN_KEY_CHECKS`, triggers, views, procedures nem nenhum `INSERT` (sem seed). **[C]**
- **Não é idempotente.** Rodar duas vezes falha na primeira tabela já existente. O banco precisa existir, estar vazio e estar selecionado antes. **[C]**
- Todas as tabelas: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`, sem `COLLATE` explícito (herdam a collation do banco/servidor). Exceção: `tb_gestao_sessao.id_sessao/csrf_token/ua_hash`, `tb_gestao_login_tentativa.ip_hash` e `tb_log_sistema.dedup_chave` usam `ascii_bin`. **[C]**
- Versão mínima implícita: tipo `JSON` (MySQL >= 5.7.8 / MariaDB >= 10.2.7) e `DATETIME ... ON UPDATE CURRENT_TIMESTAMP` (>= 5.6.5). **[C]**
- Conexão do app: DSN `charset=utf8mb4`, `SET time_zone = '-03:00'`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, sempre com prepared statements. **[C]** (`util/Conexao.php`)
- O banco do totem em produção é MySQL 5.7.44 e o de desenvolvimento é MariaDB. **[A]**

### Ordem de criação (dependência de FK)

A ordem do próprio arquivo já respeita as FKs:

1. `tb_empresa` (sem FK)
2. `tb_totem` (FK para `tb_empresa`; a FK de `criado_por` entra por `ALTER` no fim)
3. `tb_cliente` (sem FK)
4. `tb_lgpd_aceite` (FK para `tb_totem`)
5. `tb_atendimento` (FK para `tb_totem`; `id_aceite_lgpd` sem FK)
6. `tb_atendimento_nota` (FK para `tb_atendimento`)
7. `tb_ordem_coleta_pendente_baixa` (FK para `tb_atendimento`)
8. `tb_rate_limit_ocr` (FK para `tb_totem`)
9. `tb_vio_cache_cnh`, 10. `tb_vio_cache_crlv`, 11. `tb_vio_api_cache_cnh`, 12. `tb_vio_api_cache_crlv` (sem FK)
13. `tb_rate_limit_vio_status` (FK para `tb_atendimento`)
14. `tb_gestao_usuario` (FK para si mesma)
15. `tb_gestao_sessao` (FK para `tb_gestao_usuario`, `ON DELETE CASCADE`)
16. `tb_gestao_login_tentativa` e 17. `tb_gestao_auditoria` (sem FK)
18. `ALTER TABLE tb_totem ADD CONSTRAINT fk_totem_criado_por ... ON DELETE SET NULL` (fecha o ciclo `tb_totem` <-> `tb_gestao_usuario`)
19. `tb_log_sistema` (sem FK)

**[C]** (`sql/schema.sql`)

### Primeiro administrador e dados iniciais

- Não existe seed no repositório. `tb_empresa`, `tb_cliente` e `tb_totem` são cadastradas pelas telas da Gestão (somente `admin`), o que exige antes um administrador. **[C]**
- **Primeiro admin:** `php tools/criar-admin.php [--login=primeiro.segundo] [--nome="Nome"] [--forcar]`. **[C]**
  - Só roda em CLI e exige terminal interativo; a senha é pedida sem eco e nunca por argumento, pipe ou `.env`; mínimo de 12 caracteres.
  - Usa o `.env` da máquina onde roda (exige `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`). **Nunca rodar apontando para um banco que não seja o pretendido.**
  - Sob `GET_LOCK`; se já existe admin ativo, recusa, salvo `--forcar`. Cria o usuário com `perfil='admin'`, `ativo=1`, `deve_trocar_senha=0`, `criado_por` NULL, e grava a auditoria `USUARIO_CRIAR` com origem `cli`.
  - Teste: [T: teste_gestao_cli_admin]
- Ordem lógica de uma instalação nova: criar o banco vazio, aplicar `sql/schema.sql`, preencher o `.env` (inclui `GESTAO_HASH_SALT` e `TOTEM_URL_BASE`), rodar `criar-admin.php`, cadastrar a empresa (CNPJ do armazém Talent), cadastrar o totem pela tela (gera `codigo` `NOME-EMPRESA-HASH16` e token de 256 bits) e cadastrar os clientes. **[C]**
- A origem do carregamento inicial dos 38 clientes de produção não está documentada no repositório. **[P]**

---

## 2. Banco do totem: tabela por tabela

Legenda de acesso: **E** = escreve, **L** = lê. Os DAOs estão em `app/Dao/`.

### 2.1 `tb_empresa`

Empresa/armazém do Talent. O `cnpjArmazem` do payload do check-in vem **só daqui**, via `tb_totem.id_empresa`, nunca do navegador. **[C]**

| Campo | Tipo | Observação |
|---|---|---|
| `id_empresa` | INT UNSIGNED AI | PK |
| `nome` | VARCHAR(100) NOT NULL | |
| `cnpj` | VARCHAR(14) NOT NULL | UNIQUE |
| `ativo` | TINYINT(1) DEFAULT 1 | Empresa inativa faz o `finalizar` responder 500 (o `EmpresaDao` filtra `ativo=1`) |
| `criado_em` | DATETIME | |

- Acesso: E `EmpresaGestaoDao` (INSERT/UPDATE/DELETE; excluir só sem totens vinculados, regra da RN); L `EmpresaDao`, `EmpresaGestaoDao`, `TotemGestaoDao`, `LogSistemaDao` (JOIN).
- Retenção: nenhuma.

### 2.2 `tb_totem`

Totens cadastrados (multi-totem). A autenticação da API é pelo `token_api`; a URL do quiosque é `?totem=<codigo>`. **[C]**

| Campo | Tipo | Observação |
|---|---|---|
| `id_totem` | INT UNSIGNED AI | PK |
| `codigo` | VARCHAR(64) NOT NULL | UNIQUE; formato `NOME-EMPRESA-HASH16` |
| `nome` | VARCHAR(100) NOT NULL | |
| `localizacao` | VARCHAR(150) NULL | |
| `id_empresa` | INT UNSIGNED NULL | FK `tb_empresa` |
| `token_api` | CHAR(64) NOT NULL | UNIQUE; segredo; nunca lido pela Gestão |
| `ativo` | TINYINT(1) DEFAULT 1 | Inativo: página 404 e API 401 |
| `criado_em`, `atualizado_em` | DATETIME | `atualizado_em` com `ON UPDATE` |
| `criado_por` | INT UNSIGNED NULL | FK `fk_totem_criado_por` -> `tb_gestao_usuario`, `ON DELETE SET NULL` |
| `url_regerada_em` | DATETIME NULL | |
| `url_versao` | INT UNSIGNED DEFAULT 1 | Controle otimista ao regerar a URL |

- Índices: PK; UNIQUE `codigo`; UNIQUE `token_api`; UNIQUE `uk_totem_empresa_nome (id_empresa, nome)`; KEY `idx_totem_empresa_ativo (id_empresa, ativo)`; FK sem nome de `id_empresa` para `tb_empresa`.
- Acesso: E `TotemGestaoDao` (criar, ativar/desativar, regerar URL); L `TotemDao`, `util/Auth.php` (valida o token), `TotemGestaoDao`, `LogSistemaDao`, `EmpresaGestaoDao` (contagem).
- Retenção: nenhuma.
- Comportamento do Talent com `id_empresa` NULL: não verificado. **[P]** (no `finalizar`, empresa ausente ou inativa devolve 500 "Totem sem empresa/armazem configurado" **[C]**).

### 2.2.1 `tb_cliente` (diretório local do totem)

Autocomplete do Recebimento, casamento por CNPJ e identificação por OCR. **Não é** o `tb_clientes` do banco externo. **[C]**

| Campo | Tipo | Observação |
|---|---|---|
| `id_cliente` | INT UNSIGNED AI | PK |
| `nome` | VARCHAR(150) NOT NULL | |
| `razao_social_normalizada` | VARCHAR(150) NULL | Gerada por `RazaoSocialMatcher::normalizar` (sem acentos); alimenta o casamento por razão social |
| `cnpj` | VARCHAR(20) NOT NULL | UNIQUE |
| `ativo` | TINYINT(1) DEFAULT 1 | Só clientes ativos identificam no totem |
| `criado_em` | DATETIME | Não há coluna de atualização |

- Índices: PK; UNIQUE `cnpj`; `idx_nome`; `idx_cnpj` (redundante com o UNIQUE); `idx_razao_social_normalizada`. Sem FK.
- Acesso: E `ClienteGestaoDao` (INSERT/UPDATE/DELETE físico); L `ClienteDao`, `ClienteGestaoDao`, `AtendimentoNotaDao` (JOIN no OCR).
- Retenção: nenhuma.

### 2.3 `tb_lgpd_aceite`

Aceite temporário do Aviso de Privacidade, emitido antes de existir atendimento. Token opaco de uso único; no banco só fica o SHA-256. **[C]**

| Campo | Tipo | Observação |
|---|---|---|
| `id_aceite` | BIGINT UNSIGNED AI | PK |
| `token_hash` | CHAR(64) NOT NULL | UNIQUE (SHA-256 do token) |
| `id_totem` | INT UNSIGNED NOT NULL | FK `tb_totem` |
| `versao_termo` | VARCHAR(20) NOT NULL | Hoje `2026-09-25-v2` |
| `hash_termo` | CHAR(64) NOT NULL | SHA-256 do texto do termo |
| `criado_em`, `expira_em`, `usado_em` | DATETIME | Validade de 10 minutos |
| `status` | ENUM('PENDENTE_USO','USADO') | Default `PENDENTE_USO` |

- Índices: UNIQUE `token_hash`; `idx_lgpd_aceite_totem_status (id_totem, status)`; `idx_lgpd_aceite_status_expira (status, expira_em)`.
- Transição: `PENDENTE_USO` -> `USADO` por UPDATE condicional (CAS) que exige `status=PENDENTE_USO`, `expira_em>NOW()`, mesmo totem e mesma versão/hash do termo.
- Acesso: E/L `AceiteLgpdDao`. `tb_atendimento.id_aceite_lgpd` aponta para `id_aceite` só logicamente (sem FK).
- Retenção: **nenhum DELETE ou cron**; tokens expirados também não são limpos. A retenção "acompanha o atendimento, sem prazo próprio".
- Teste: [T: teste_lgpd_aceite_backend_seguranca] (depende de `tests/manual/_fixtures_lgpd.php`, que não existe no disco; ver pendência P23).

### 2.4 `tb_atendimento`

Cada atendimento é uma máquina de estados. É a tabela central; as FKs de `tb_atendimento_nota`, `tb_ordem_coleta_pendente_baixa` e `tb_rate_limit_vio_status` apontam para ela. **[C]**

**Campos de identificação e fluxo**

| Campo | Tipo | Observação |
|---|---|---|
| `id_atendimento` | BIGINT UNSIGNED AI | PK |
| `codigo_publico` | CHAR(36) | UNIQUE (UUID) |
| `id_totem` | INT UNSIGNED NOT NULL | FK `tb_totem`; base da checagem de posse em toda API |
| `id_aceite_lgpd` | BIGINT UNSIGNED NULL | Sem FK |
| `tipo` | ENUM('expedicao','recebimento') | |
| `etapa_atual` | VARCHAR(40) DEFAULT 'placa' | **Texto livre**, sem ENUM nem CHECK (ver abaixo) |
| `status` | ENUM('em_andamento','concluido','cancelado','bloqueado') | Default `em_andamento` |
| `placa` VARCHAR(8), `ordem_coleta` VARCHAR(50), `cliente_nome` VARCHAR(150), `cliente_cnpj` VARCHAR(20) | | `cliente_cnpj` é gravado pelo servidor e usado na baixa da OC |
| `motorista_nome` VARCHAR(150), `motorista_cpf` VARCHAR(14), `cnh_validade` DATE | | Texto plano |
| `possui_ajudante` TINYINT(1), `ajudante_nome` VARCHAR(150), `ajudante_cpf` VARCHAR(14) | | |
| `pasta_documentos` | VARCHAR(255) | `AAAA-MM-DD/PLACA_HHMMSS`, só para navegação humana |
| `dados_brutos_json` | JSON NULL | Existe no schema; nenhum código lido o usa |
| `criado_em`, `atualizado_em` | DATETIME | |

**Campos da CNH** (prefixo `cnh_`): `origem_validacao` ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO','VIO_CACHE','VIO_API_BR') default `NAO_VALIDADO`; `status_revisao` ENUM('OK','PENDENTE_REVISAO'); `validado_em`; `snapshot_nome`, `snapshot_cpf`, `snapshot_validade` (valor exato devolvido pela VIO, usado para decidir rebaixamento); `status_processamento` ENUM('PENDENTE','PROCESSANDO','ENVIANDO','PROCESSANDO_LEITURA','PROCESSANDO_COMPARACAO','CONCLUIDO','ERRO','INDETERMINADO') default `PENDENTE`; `modo_captura` ENUM('FISICA','DIGITAL') NULL (legado do fluxo antigo); `processamento_iniciado_em`; `tentativa_id` VARCHAR(32); `vio_api_id` VARCHAR(128); `vio_api_enviado_em`; `vio_api_fingerprint` CHAR(64); `vio_api_fingerprint_versao` TINYINT UNSIGNED.

**Campos do CRLV** (prefixo `crlv_`): `ano` SMALLINT, `uf` VARCHAR(2), `rntc` VARCHAR(20), `tipo_veiculo` VARCHAR(60); os mesmos campos de origem, revisão, processamento, tentativa e VIO da CNH; `snapshot_placa`, `snapshot_exercicio`, `snapshot_uf`, `snapshot_rntc`, `snapshot_tipo_veiculo`. Não existe `crlv_modo_captura`.

**Campos do Talent:** `talent_enviado_em` DATETIME; `talent_senha` VARCHAR(20) (recebe o `nrRegAcesso`); `talent_protocolo` VARCHAR(60); `talent_checkin_status` ENUM('NAO_ENVIADO','ENVIANDO','ENVIADO','ERRO_REPROCESSAVEL','ENVIO_INDETERMINADO') default `NAO_ENVIADO`; `talent_tentativa_id` VARCHAR(32); `talent_status_iniciado_em` DATETIME. O corpo de resposta do Talent não é guardado.

- Índices: PK; UNIQUE `codigo_publico`; FK `id_totem`; `idx_placa`; `idx_status`; `idx_atendimento_aceite_lgpd`. Não há índice composto (pendência registrada).
- Acesso: E/L `AtendimentoDao` (todas as transições); L também `ClienteGestaoDao`, `TotemGestaoDao`, `LogSistemaDao`, `OrdemColetaGestaoDao`, `OrdemColetaPendenteBaixaDao` (JOIN).

**Transições de `status`** [C] (testes: [T: teste_integridade_conclusao_atendimento], [T: teste_hardening_r4_abandono])

```
em_andamento --(Talent ENVIADO)--> concluido        (terminal; grava etapa_atual='impressao')
em_andamento --(cancelar | cron de abandono)--> cancelado
em_andamento --(Talent 2xx sem nrRegAcesso utilizavel)--> cancelado  (talent_checkin_status='ENVIO_INDETERMINADO')
em_andamento --(excesso de notas)--> bloqueado       (etapa_atual='balcao_portaria')
```

- `concluido` é terminal: `cancelar` e `bloquear` respondem 409 sem alterar nada. Não há reabertura (não planejada).
- `bloquear` pode converter `cancelado` em `bloqueado` (só não toca `concluido`).
- O abandono só age em `em_andamento` com `talent_checkin_status` em `NAO_ENVIADO` ou `ERRO_REPROCESSAVEL`.

**`etapa_atual` é livre** [C]: validada só por código, sem enumeração única. Valores encontrados: `placa`, `dados_encontrados`, `exp_cnh`, `exp_crlv`, `exp_aguarde_documentos`, `exp_confirmacao`, `digitalizacao_notas`, `cliente`, `rec_cnh`, `rec_crlv`, `rec_aguarde_documentos`, `rec_confirmacao`, `impressao`, `balcao_portaria`. (Valor com nome `rec_cliente`, citado em documentação anterior, é nome de **tela** do front, não de etapa do backend.)

**`cnh_`/`crlv_status_processamento`** [C]: `PENDENTE` -> `ENVIANDO` (CAS `iniciarEnvioVioApiBr`, que também reabre `CONCLUIDO`+`NAO_VALIDADO` sem `*_validado_em`) -> `PROCESSANDO_LEITURA` -> `PROCESSANDO_COMPARACAO` -> `CONCLUIDO` | `ERRO` | `INDETERMINADO`. Processamento acima de 120 s vira `INDETERMINADO`. O preenchimento manual marca `CONCLUIDO`. O valor `PROCESSANDO` existe no ENUM, mas nenhuma atribuição foi encontrada no `AtendimentoDao`.

**`*_origem_validacao`** [C]: o fluxo atual grava só `VIO_API_BR` (leitura de QR aprovada) e `MANUAL`. `VIO_TRIAL`/`VIO_VALIDADO` (legado Serpro) e `VIO_CACHE` ficam no ENUM por compatibilidade histórica. Edição de dado validado na tela de confirmação rebaixa `VIO_API_BR` para `MANUAL` com `PENDENTE_REVISAO` ([T: teste_vio_api_br_regras_crlv_e_rebaixamento]).

**`talent_checkin_status`** [C]: `NAO_ENVIADO` -> `ENVIANDO` (CAS com `talent_tentativa_id`) -> `ENVIADO` (junto com `status='concluido'`) | `ERRO_REPROCESSAVEL` | `ENVIO_INDETERMINADO`. `ENVIANDO` acima de 60 s vira `ENVIO_INDETERMINADO`. Nova tentativa é só manual (a fila de reenvio foi removida; `tb_fila_envio` não existe). `ENVIO_INDETERMINADO` por timeout/falha de rede não tem saída no código (ver "Divergências"). **Combinação `status='cancelado'` + `talent_checkin_status='ENVIO_INDETERMINADO'`:** gravada quando o Talent responde 2xx sem `nrRegAcesso` utilizável (`AtendimentoDao::cancelarPorRespostaSemRegistroTalent`, decisão de 2026-10-11): `talent_senha` fica vazia e o `status` só vira `cancelado` se estava `em_andamento` (`bloqueado` e `concluido` não são sobrescritos). Essa combinação não se distingue de timeout seguido de `cancelar` pelas colunas do atendimento; só o `tb_log_sistema` (`talent_indeterminado`, `categoria_erro=resposta_ilegivel`) diferencia. **[C]**

**Limpeza:** nenhuma linha de `tb_atendimento` é apagada por código; o abandono só muda o `status`.

### 2.5 `tb_atendimento_nota`

Notas fiscais do Recebimento (máximo 5 por atendimento, imposto no backend). **[C]**

| Campo | Tipo | Observação |
|---|---|---|
| `id_nota` | BIGINT UNSIGNED AI | PK |
| `id_atendimento` | BIGINT UNSIGNED NOT NULL | FK `tb_atendimento` (RESTRICT) |
| `ordem` | TINYINT UNSIGNED NOT NULL | 1 a 5 |
| `client_uid` | VARCHAR(64) NULL | Idempotência do upload (gerado pelo front) |
| `arquivo` | VARCHAR(255) NOT NULL | Caminho da foto sob `STORAGE_PATH` |
| `chave_acesso` | CHAR(44) NULL | O front envia sempre null hoje |
| `numero_nota` | VARCHAR(20) NULL | 1 a 9 dígitos, sem zeros à esquerda |
| `numero_nota_origem` | ENUM('OCR','MANUAL') NULL | |
| `cnpj_emitente` | VARCHAR(20) NULL | |
| `cliente_identificado` | TINYINT(1) DEFAULT 0 | |
| `status_ocr` | ENUM('PENDENTE','PROCESSANDO','IDENTIFICADA','NAO_IDENTIFICADA','ERRO') | Default `PENDENTE` |
| `processado_em`, `criado_em` | DATETIME | |

- Índices: PK; `idx_chave`; `idx_status_ocr`; UNIQUE `uk_atendimento_ordem (id_atendimento, ordem)`; UNIQUE `uk_atendimento_numero_nota (id_atendimento, numero_nota)`; UNIQUE `uk_atendimento_client_uid (id_atendimento, client_uid)`.
- Transição de `status_ocr`: `PENDENTE`/`PROCESSANDO` -> `IDENTIFICADA` | `NAO_IDENTIFICADA` | `ERRO`. O UPDATE é condicionado a `IN ('PENDENTE','PROCESSANDO')`, então o resultado é imutável por nota. `PROCESSANDO` não é gravado por nenhum código lido.
- Acesso: E/L `AtendimentoNotaDao` (INSERT, UPDATE, DELETE físico de nota individual na exclusão); L `ClienteGestaoDao` (uso de cliente).
- Retenção: a **foto** em quarentena (`nota_0N.jpg.<id>.del`) é apagada 24 h depois pelo cron `limpar-notas-quarentena.php`. A linha da nota **não** é apagada por cron. Fotos de atendimentos concluídos não têm rotina de retenção.
- Testes: [T: teste_concorrencia_numero_nota_duplicado], [T: teste_hardening_exclusao] (a verificação E17 falha, pré-existente, P55).

### 2.6 `tb_ordem_coleta_pendente_baixa`

Mora no **banco do totem** (não no externo). Registra o cenário "o Talent aceitou o check-in, mas a baixa da OC no banco externo falhou". Não guarda payload, CPF, CNH nem token. **[C]**

| Campo | Tipo |
|---|---|
| `id` | BIGINT UNSIGNED AI, PK |
| `id_atendimento` | BIGINT UNSIGNED NOT NULL, UNIQUE `uk_ordem_coleta_pendente_baixa_atendimento`, FK `tb_atendimento` |
| `numero_ordem_coleta` | VARCHAR(50) NOT NULL |
| `criado_em` | DATETIME |
| `resolvido_em` | DATETIME NULL (preenchido ao resolver; CAS com `resolvido_em IS NULL`) |

- Acesso: E `OrdemColetaPendenteBaixaDao` (`registrar` é INSERT com `ON DUPLICATE KEY UPDATE criado_em=criado_em`, idempotente; `marcarResolvida`); L o mesmo DAO e a aba "Baixas pendentes" da Gestão.
- Retenção: nenhuma. Não há cron de reconciliação.
- Testes: [T: teste_ordem_coleta_pendente_baixa], [T: teste_gestao_oc_dao].

### 2.7 `tb_rate_limit_ocr`

Limite de `nota.php?acao=identificar-cliente` por totem: janela fixa de 60 s, 30 chamadas por totem por janela. **[C]**

- Campos: `id_totem` INT UNSIGNED, `janela` INT UNSIGNED, `contador` SMALLINT UNSIGNED (default 1), `atualizado_em`. PK `(id_totem, janela)`; FK `id_totem`.
- Acesso: E/L `RateLimitOcrDao` (`INSERT ... ON DUPLICATE KEY UPDATE` e DELETE de janelas antigas).
- Retenção: `cron/limpar-rate-limit-ocr.php` apaga janelas com mais de 24 h (mantém a atual e a anterior), em lotes de 500, até 50 lotes.
- Teste: [T: teste_rate_limit_identificar_cliente_pdo]

### 2.8 `tb_rate_limit_vio_status`

Limite do polling de `documento.php?acao=status-processamento`: 20 chamadas por 5 s por (atendimento, documento). **[C]**

- Campos: `id_atendimento`, `tipo_documento` ENUM('cnh','crlv'), `janela`, `contador`, `atualizado_em`. PK `(id_atendimento, tipo_documento, janela)`; FK `id_atendimento`.
- Acesso: E/L `RateLimitVioStatusDao`.
- Retenção: **nenhum DELETE ou cron encontrado**.

### 2.9 `tb_vio_cache_cnh` e `tb_vio_cache_crlv` (legado Serpro/VIO Decode)

Cache antigo chaveado por HMAC do QR (`identificador_qr`, `ambiente`), com nome/CPF cifrados na CNH. **[C]**

- Índices: PK; UNIQUE `uk_qr_ambiente (identificador_qr, ambiente)`; `idx_valido_ate`. Sem FK.
- `VioCacheDao` tem SELECT/INSERT, mas `DocumentoRn` só recebe o objeto no construtor e nunca o chama. **Sem uso em runtime.** Sem retenção.

### 2.10 `tb_vio_api_cache_cnh` e `tb_vio_api_cache_crlv` (cache vio.api.br)

Cache previsto para a vio.api.br: `fingerprint` CHAR(64) (HMAC do QR), `hmac_versao`, `fornecedor` ENUM('VIO_API_BR'), `versao_mapeamento`, resumo da comparação, `estado` ENUM('VALIDO','REVOGADO'), `origem_original`, `validado_em`, `revalidar_apos`, `expira_em`; dados pessoais cifrados (`nome_cifrado`, `cpf_cifrado` na CNH; `renavam_cifrado` no CRLV). **[C]**

- Índices: UNIQUE `uk_fingerprint_versao_cnh|crlv`; `idx_expira_em_*`; `idx_estado_*`. Sem FK.
- **Nenhum DAO ou SQL em `app/`, `util/` ou `public/` referencia estas tabelas.** Só testes afirmam que elas ficam vazias no fluxo QR-only. Sem retenção.

### 2.11 `tb_gestao_usuario`

| Campo | Tipo | Observação |
|---|---|---|
| `id_usuario` | INT UNSIGNED AI | PK |
| `login` | VARCHAR(60) NOT NULL | UNIQUE `uk_gestao_usuario_login`; formato `primeiro.segundo` |
| `nome` | VARCHAR(100) NOT NULL | |
| `perfil` | ENUM('admin','usuario') | Default `usuario` |
| `senha_hash` | VARCHAR(255) NOT NULL | Argon2id (fallback bcrypt custo 12) |
| `ativo` | TINYINT(1) DEFAULT 1 | Usuários não são excluídos, só inativados |
| `deve_trocar_senha` | TINYINT(1) DEFAULT 1 | |
| `tentativas_falhas` | TINYINT UNSIGNED | Bloqueio de 15 min após 5 falhas |
| `bloqueado_ate`, `ultimo_login_em`, `senha_alterada_em` | DATETIME NULL | |
| `senha_versao` | INT UNSIGNED DEFAULT 1 | Controle otimista ao redefinir senha |
| `criado_em`, `atualizado_em`, `criado_por` | | `criado_por` FK para a própria tabela |

- Índices: PK; UNIQUE login; `idx_gestao_usuario_perfil_ativo (perfil, ativo)`; FK `fk_gestao_usuario_criado_por` (RESTRICT). Referenciada por `tb_gestao_sessao` (CASCADE) e `tb_totem.criado_por` (SET NULL).
- Acesso: E/L `UsuarioGestaoDao`. Retenção: nenhuma.

### 2.12 `tb_gestao_sessao`

- Campos: `id_sessao` CHAR(64) `ascii_bin` PK (**SHA-256** do cookie opaco; o valor do cookie nunca é gravado), `id_usuario` (FK com `ON DELETE CASCADE`), `csrf_token`, `criado_em`, `ultimo_acesso_em`, `expira_em`, `ua_hash` (SHA-256 do User-Agent).
- Índices: `idx_gestao_sessao_usuario`, `idx_gestao_sessao_expira`.
- Regras: 30 min de inatividade (`GESTAO_SESSION_IDLE_MIN`) e 12 h absolutas (`GESTAO_SESSION_ABSOLUTE_MIN`).
- Acesso: E/L `SessaoGestaoDao`.
- Retenção: `apagarExpiradas` (DELETE com `expira_em <= NOW()`, até 200 linhas) roda de forma oportunista em `AuthGestao` (1 a cada 50 logins), **sem cron**.

### 2.13 `tb_gestao_login_tentativa`

- Campos: `ip_hash` CHAR(64) `ascii_bin`, `janela`, `contador`, `atualizado_em`. PK `(ip_hash, janela)`; `idx_gestao_login_tentativa_atualizado`. Sem FK.
- Limite por IP: 10 falhas por janela de 900 s (`AuthGestao::LIMITE_FALHAS_IP`, `JANELA_IP_SEGUNDOS`).
- Acesso: E/L `LoginTentativaGestaoDao`. Retenção: `apagarAntigas` oportunista em `AuthGestao`, sem cron.

### 2.14 `tb_gestao_auditoria` (append-only)

- Campos: `id_auditoria` BIGINT UNSIGNED AI PK; `id_usuario` INT UNSIGNED NULL (sem FK); `acao` VARCHAR(40); `alvo_tipo` VARCHAR(30) NULL; `alvo_id` BIGINT UNSIGNED NULL; `resultado` ENUM('PENDENTE','OK','SEM_EFEITO','ERRO') default `PENDENTE`; `detalhe` VARCHAR(255) NULL; `ip` VARBINARY(16) NULL; `criado_em`.
- Índices: `idx_gestao_auditoria_data (criado_em)`, `_usuario (id_usuario, criado_em)`, `_acao (acao, criado_em)`, `_alvo (alvo_tipo, alvo_id)`.
- `acao` **não é ENUM no banco**: a lista fechada de 25 ações está em `AuditoriaDao::ACOES`. `alvo_tipo` é limitado por `AuditoriaDao::ALVO_TIPOS` (usuario, totem, sistema, ordem_coleta, oc_baixa, cliente, empresa). O `detalhe` só aceita chaves de um conjunto fechado e não contém nome nem CNPJ. **[C]**
- Ciclo do `resultado`: a linha nasce `PENDENTE` **antes** da ação e é fechada em `OK`, `SEM_EFEITO` ou `ERRO`.
- O **IP do ator é gravado** (binário, não hash). O `detalhe` é que não tem dados pessoais. **[C]**
- Acesso: E `AuditoriaDao` (INSERT e UPDATE do resultado; **sem DELETE**); E `AuditoriaRetencaoDao` (**único DELETE**).
- Retenção: 90 dias fixos por `cron/limpar-logs-gestao.php`.

### 2.15 `tb_log_sistema`

Log central, alimentado só por `Util\LogSistema` a partir do catálogo fechado `Util\LogCatalogo`. **[C]**

- Campos: `id_log` BIGINT UNSIGNED AI PK; `nivel` ENUM('INFO','AVISO','ERRO'); `origem` ENUM('API','RECEBIMENTO','EXPEDICAO','CRON','GESTAO'); `categoria` VARCHAR(40); `mensagem` VARCHAR(160) (texto fixo do catálogo); `id_atendimento` e `id_totem` (opcionais, sem FK); `detalhe` VARCHAR(255) NULL (campos de allowlist); `dedup_chave` CHAR(40) `ascii_bin`; `janela` DATETIME; `contador` INT UNSIGNED (default 1); `criado_em`; `ultima_ocorrencia`.
- Índices: UNIQUE `uk_log_sistema_dedup (dedup_chave, janela)` (deduplicação com contador); `idx_log_sistema_origem`, `_nivel`, `_totem` (todos com `ultima_ocorrencia`); `_atendimento`; `_criado`.
- Sem IP, sem texto livre, sem exceção bruta.
- Tetos de gravação: 10 por requisição, 500 por dia por categoria, 2000 por dia no total, 100000 linhas no total (com registro `log_suprimido`).
- Acesso: E `LogSistemaDao` (INSERT, UPDATE do contador, DELETE da retenção) via `Util\LogSistema` (conexão dedicada, escrita no encerramento da requisição); L `LogSistemaDao` (tela `/gestao/logs.php`, só `admin`).
- Retenção: 90 dias por `criado_em`, pelo mesmo cron.
- Testes: [T: teste_log_sistema], [T: teste_gestao_qa_logs], [T: teste_gestao_retencao].

---

## 3. Relacionamentos

```
tb_empresa 1---N tb_totem (RESTRICT)
tb_gestao_usuario 1---N tb_totem.criado_por (SET NULL)
tb_gestao_usuario 1---N tb_gestao_usuario.criado_por (RESTRICT)
tb_gestao_usuario 1---N tb_gestao_sessao (CASCADE)
tb_totem 1---N tb_atendimento | tb_lgpd_aceite | tb_rate_limit_ocr
tb_atendimento 1---N tb_atendimento_nota
tb_atendimento 1---1 tb_ordem_coleta_pendente_baixa (UNIQUE por atendimento)
tb_atendimento 1---N tb_rate_limit_vio_status
```

Referências **lógicas sem FK**: `tb_atendimento.id_aceite_lgpd`, `tb_atendimento.cliente_cnpj` (casa com `tb_cliente.cnpj` e com `tb_clientes.cnpj` do banco externo), `tb_log_sistema.id_atendimento/id_totem`, `tb_gestao_auditoria.id_usuario/alvo_id`. **[C]**

---

## 4. Retenção e rotinas de limpeza

Todos os crons são **somente CLI** (recusam outro SAPI), registram `cron_resumo`/`cron_falhou` no `LogSistema` e saem com código 1 em falha. Os horários abaixo são os registrados no checklist de deploy (fuso do servidor -03, PHP `ea-php83` no cPanel). Os 5 crons estão agendados e executando em produção (confirmado pelo responsável do projeto em 2026-10-10). **[A]**

| Objeto | Regra | Script | Agenda de produção |
|---|---|---|---|
| `tb_rate_limit_ocr` | 24 h (preserva janela atual e anterior); lote 500, até 50 lotes | `cron/limpar-rate-limit-ocr.php` | toda hora cheia |
| `tb_atendimento` em andamento | Abandono após 24 h de inatividade (maior entre `atualizado_em` e atividade das notas): vai a `cancelado`, só se Talent `NAO_ENVIADO` ou `ERRO_REPROCESSAVEL`; 500 por execução; fotos das notas vão para quarentena | `cron/abandonar-atendimentos.php` | 03:15 |
| Fotos em quarentena (`.del`) | 24 h; 500 arquivos por execução | `cron/limpar-notas-quarentena.php` | 03:30 |
| PDFs de OC e `tb_ordem_coleta_arquivos` | 15 dias após a OC ficar INATIVA (`inativada_em`); sem OC correspondente (órfão), 15 dias após `criado_em`; OC ATIVA nunca perde o arquivo; lote 200 | `cron/limpar-anexos-ordem-coleta.php` | 03:55 |
| `tb_log_sistema` e `tb_gestao_auditoria` | 90 dias fixos, com piso; lotes de 500, até 50 lotes por tabela, 240 s; `GET_LOCK`; `--dry-run`; abre a trilha `RETENCAO_EXECUTAR` antes e fecha depois (aborta se não conseguir abrir); a própria trilha também é podada em 90 dias | `cron/limpar-logs-gestao.php` | 04:10 |
| `tb_gestao_sessao` expiradas e `tb_gestao_login_tentativa` antigas | Apagadas de forma oportunista pelo login | `AuthGestao` (sem cron) | n/a |
| `tb_lgpd_aceite`, `tb_rate_limit_vio_status`, `tb_vio_cache_*`, `tb_vio_api_cache_*`, `tb_ordem_coleta_pendente_baixa`, `tb_atendimento`, `tb_atendimento_nota` (linhas), `tb_cliente`, `tb_totem`, `tb_empresa`, `tb_gestao_usuario` | **Nenhuma poda automática** | n/a | n/a |

- Os 5 crons, incluindo `limpar-logs-gestao.php`, estão no crontab de produção e executando corretamente (confirmado pelo responsável do projeto em 2026-10-10). **[A]**
- Retenção de atendimentos, notas e fotos concluídas e aceites LGPD: decisão de produto (2026-10-10): não será tratado. A linha "Nenhuma poda automática" da tabela acima descreve o comportamento atual.
- O `reenviar-fila` foi removido junto com `tb_fila_envio`. **[C]**
- O cron de abandono não age em `ENVIANDO`, `ENVIADO` nem `ENVIO_INDETERMINADO`. **[C]**
- Testes: [T: teste_hardening_r4_abandono], [T: teste_hardening_cron_quarentena], [T: teste_hardening_r4_storage_cron], [T: teste_gestao_retencao].

---

## 5. Banco externo `<CONTA_CPANEL>_db_gestao_coletas`

Pertence ao sistema de gestão de coletas (n8n/iaUdlog). O totem acessa direto por PDO, com conexão própria aberta só quando necessário (`Util\ConexaoGestaoColetas`; usa as credenciais `DB_HOST/DB_PORT/DB_USER/DB_PASS` do mesmo `.env` e o nome em `GESTAO_COLETAS_DB_NAME`; timeout de 4 s; `time_zone -03:00`). **[C]**

O dump local `docs/<CONTA_CPANEL>_db_gestao_coletas.sql` (fora do Git) reflete a estrutura de produção extraída em 2026-10-10 (somente estrutura, sem dados; gerado por mysqldump) e é a referência da estrutura do banco externo. Tem 13 tabelas, mas o totem usa só **3**: `tb_clientes` (leitura), `tb_ordens_coleta` (leitura e escrita restrita) e `tb_ordem_coleta_arquivos` (leitura e escrita). O totem **não escreve** em `tb_clientes` externo, `tb_motoristas`, `tb_emails_recebidos` etc. **[C]**

### 5.1 `tb_clientes` (somente leitura)

`id` BIGINT UNSIGNED PK; `razao_social` VARCHAR(200); `cnpj` VARCHAR(14) UNIQUE `uk_clientes_cnpj`; `email` VARCHAR(255) NULL; `status` ENUM('ATIVO','INATIVO'); `criado_em`; `atualizado_em`. Lida por `OrdemColetaDao` (JOIN, só cliente `ATIVO`) e `OrdemColetaGestaoDao`. **[C]** (dump)

### 5.2 `tb_ordens_coleta`

Campos do dump: `id`, `numero_ordem_coleta` VARCHAR(50), `cliente_id`, `transportadora_nome`, `transportadora_cnpj`, `motorista_id`, `email_recebido_id`, `placa_prevista` VARCHAR(10), `motorista_nome_previsto`, `cnh_prevista`, `status`, `inativada_em`, `criado_em`, `atualizado_em`. Índices do dump: UNIQUE `uk_ordem_cliente (cliente_id, numero_ordem_coleta)`, `idx_ordens_numero_ordem`, `_cliente_id`, `_motorista_id`, `_email_recebido_id`, `_placa_prevista`, `_transportadora`. **[C]** (dump)

Também constam do dump: `status` ENUM('ATIVA','INATIVA') NOT NULL DEFAULT 'ATIVA'; `inativada_em` DATETIME NULL; índices `idx_ordens_placa_status (placa_prevista, status)`, `idx_oc_status_criado (status, criado_em)`, `idx_oc_status_inativada (status, inativada_em)`. A infra de teste (`tests/manual/qa_gestao_oc_infra.php`) embute uma cópia dessa estrutura. As migrations externas 001-004 (removidas do repositório) foram aplicadas em produção (001-003 em 2026-10-05; 004 em 2026-10-09, MySQL 5.7.44). Para mudar o banco externo no futuro, criar uma nova migration. **[C]** (dump)

Escritas feitas pelo totem e pela Gestão (únicas colunas escritas):

- Baixa pelo totem (`OrdemColetaDao::marcarInativaPorClienteNumero`): `status='INATIVA'`, `inativada_em=NOW()` onde o cliente é o do CNPJ do atendimento, o número confere e `status='ATIVA'` (CAS). Sem CNPJ não inativa. **[C]**
- Ativar/inativar pela tela de OCs (`OrdemColetaGestaoDao`): por `id`, com CAS no status; ao ativar, `inativada_em` volta a NULL. **[C]**
- Nenhum INSERT nem DELETE no código: as OCs são criadas pelo sistema externo.

Transição: `ATIVA <-> INATIVA`. Leituras: busca por placa (OC `ATIVA` e cliente `ATIVO`), `statusPorClienteNumero` (só devolve se houver exatamente 1 linha), listagens e contagens da Gestão. Testes: [T: teste_consulta_ordem_coleta] (12/17 por fixture, P24), [T: teste_gestao_oc_dao], [T: teste_gestao_ordens].

### 5.3 `tb_ordem_coleta_arquivos`

`id` BIGINT UNSIGNED AI PK; `cnpj_cliente` CHAR(14); `numero_ordem_coleta` VARCHAR(50); `caminho_relativo` VARCHAR(255); `tamanho_bytes` INT UNSIGNED; `sha256` CHAR(64); `criado_em`; `atualizado_em`. UNIQUE `uk_oc_arquivo_cnpj_numero (cnpj_cliente, numero_ordem_coleta)` e UNIQUE `uk_oc_arquivo_caminho (caminho_relativo)`. Sem FK. Só caminho e hash; o PDF fica em `STORAGE_PATH/ordens_coleta/<cnpj>/<numero>_<AAAAMMDDHHMMSS>.pdf`. **[C]** (dump; cópia embutida em `tests/manual/qa_oc_anexo_infra.php`)

- E `OrdemColetaArquivoDao` (`substituir`: INSERT ou UPDATE em transação com `SELECT ... FOR UPDATE`, até 5 tentativas; `excluirPorIdECaminho`: DELETE). L também `OrdemColetaGestaoDao` (para abrir o PDF).
- Retenção: 15 dias (ver seção 4).
- Testes: [T: teste_oc_anexo_logica], [T: teste_oc_anexo_http].

O `tests/manual/qa_*` e a infra de teste criam essa estrutura em bancos `qa_` descartáveis; nunca devem apontar para o banco real. **[C]**

---

## 6. Divergências

1. **Dump externo fora do Git.** `docs/<CONTA_CPANEL>_db_gestao_coletas.sql` reflete a estrutura de produção extraída em 2026-10-10 (somente estrutura, sem dados) e contém `tb_ordens_coleta.status`, `inativada_em`, os índices novos e `tb_ordem_coleta_arquivos`. Não há script do banco externo no repositório; `docs/` é ignorado pelo git. A documentação do fornecedor em `docs/db_gestao_coletas.md` continua template/desatualizada quanto à API de clientes e às ordens por placa.
2. **Cache VIO sem uso.** `tb_vio_cache_*` tem DAO nunca chamado; `tb_vio_api_cache_*` não tem DAO. Descrições anteriores ainda tratam o cache `VIO_CACHE` (AES-256-GCM, HMAC, TTL de 7 dias) como capacidade; no código QR-only ele não é usado. `ClienteApiClient` também é código morto.
3. **`etapa_atual` livre.** VARCHAR(40) sem ENUM; a enumeração só existe espalhada no código. O front tem 26 telas (26 casos em `renderTela`); `rec_cnh` é nome de etapa do backend, sem tela. A referência anterior a "27 estados" foi corrigida para 26.
4. **Valores de ENUM sem uso.** `PROCESSANDO` (processamento e `status_ocr`) não é gravado por nenhum código lido; as origens `VIO_TRIAL`, `VIO_VALIDADO` e `VIO_CACHE` são só legado.
5. **`ENVIO_INDETERMINADO` sem saída (timeout/falha de rede; o caso "2xx sem número" está resolvido: `cancelado`).** Nenhuma tela ou rotina o resolve; o abandono ignora esse estado e a Gestão não tem tela de atendimentos (F5 descartada). O atendimento fica `em_andamento` indefinidamente.
6. **Schema x produção em `tb_totem`.** Em produção há a FK `fk_totem_empresa` e um índice redundante; o schema declara a FK sem nome. O CHECK de JSON de `dados_brutos_json` existe só no MariaDB. **[A]**
7. **Tamanhos de CNPJ diferentes.** `tb_cliente.cnpj` e `tb_atendimento.cliente_cnpj` são VARCHAR(20); `tb_clientes.cnpj` externo é VARCHAR(14). O código normaliza para dígitos antes de comparar (`OrdemColetaDao::normalizarCnpj`). Que `tb_clientes.cnpj` em produção tenha só dígitos não foi confirmado (P60).
8. **README anterior e checklist desatualizados.** O README anterior (até `4188fee`) mandava "rodar `sql/schema.sql` no MariaDB" (produção é MySQL 5.7.44) e "cadastrar um totem em `tb_totem`" (hoje é pela Gestão, depois de `criar-admin.php`). O `docs/deploy-checklist.md` ainda lista migrations removidas. Documentos anteriores ainda citam migrations 001-017 como "preservadas".
9. **Auditoria "sem PII".** Documentos anteriores dizem isso, mas o IP do ator é gravado em `tb_gestao_auditoria.ip`. Só o `detalhe` é livre de dados pessoais.
10. **Retenção (decisão de produto em 2026-10-10: não será tratado).** Comportamento atual, para o desenvolvedor: não há poda em `tb_lgpd_aceite`, `tb_rate_limit_vio_status`, `tb_ordem_coleta_pendente_baixa`, linhas de `tb_atendimento`/`tb_atendimento_nota`; dados pessoais do motorista (nome, CPF, validade da CNH) ficam em texto plano em `tb_atendimento`, sem prazo de retenção.
11. **Índice faltante registrado.** `tb_atendimento` só tem `idx_status`, `idx_placa` e `idx_atendimento_aceite_lgpd`; o índice composto por OC (cliente+número) está pendente, sem migration.

## 7. PENDENTE DE CONFIRMAÇÃO

- Collation real das tabelas em produção (exceto `tb_totem.codigo`, citada como `utf8mb4_general_ci` em registro anterior) e charset padrão do banco `<CONTA_CPANEL>_udlog_totem`. **[P]**
- Comportamento do totem/Talent com `tb_totem.id_empresa` NULL. **[P]**
- Origem do carregamento inicial dos 38 clientes e dos dados de empresa/totem para uma instalação nova. **[P]**
- Se o `idx_cnpj` redundante de `tb_cliente` existe igual em produção. **[P]**
- Se há usuário MySQL separado só para o DELETE da auditoria (hoje o cron usa a mesma conexão). **[P]**
