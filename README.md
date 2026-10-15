# Totem de autoatendimento UDLOG

Totem físico de autoatendimento da UDLOG para **Expedição** e **Recebimento** de cargas. O sistema agiliza o atendimento dos motoristas: ele digitaliza notas fiscais e documentos, e, depois de concluir o atendimento, o motorista recebe uma senha e aguarda ser chamado.

Este README é o ponto de entrada. Cada assunto tem documento próprio em [`documentacao/`](documentacao/); aqui há um resumo e o link. Quando um documento diverge de outro, **o código vence**.

Convenções: nenhum valor real (credencial, token, chave, caminho de máquina) aparece neste arquivo. Os marcadores usados são `<CONTA_CPANEL>`, `<INSTALACAO>` (raiz do repositório) e `<PASTA_DO_SERVICO>`. O que não está comprovado no código ou na documentação aparece como `PENDENTE DE CONFIRMAÇÃO`.

## Sumário

1. [Objetivo e atendimento do motorista](#1-objetivo-e-atendimento-do-motorista)
2. [Funcionalidades implementadas](#2-funcionalidades-implementadas)
3. [Pendências relevantes](#3-pendências-relevantes)
4. [Tecnologias e arquitetura](#4-tecnologias-e-arquitetura)
5. [Estrutura de diretórios](#5-estrutura-de-diretórios)
6. [Requisitos e versões confirmadas](#6-requisitos-e-versões-confirmadas)
7. [Instalação (ordem cronológica)](#7-instalação-ordem-cronológica)
8. [Variáveis do `.env`](#8-variáveis-do-env)
9. [Equipamentos e serviços externos](#9-equipamentos-e-serviços-externos)
10. [Testes](#10-testes)
11. [Solução de problemas confirmados](#11-solução-de-problemas-confirmados)
12. [Documentação](#12-documentação)

---

## 1. Objetivo e atendimento do motorista

O motorista chega ao totem (Chrome em modo quiosque, monitor touch em retrato), aceita o Aviso de Privacidade, escolhe Expedição ou Recebimento, informa a placa e segue por etapas até receber a senha ("Número de acesso") e a etiqueta impressa. Depois, aguarda ser chamado.

Resumo da jornada (detalhe nos [fluxos](documentacao/fluxos/README.md)):

1. **LGPD**: aceite obrigatório, validado no backend, antes de qualquer atendimento ([fluxo 01](documentacao/fluxos/01-lgpd-e-inicio-atendimento.md)).
2. **Expedição**: placa, ordem de coleta (OC) encontrada por placa, dados, CNH e CRLV por QR (títulos em faixa colorida), confirmação e ajudante ([fluxo 02](documentacao/fluxos/02-expedicao.md)).
3. **Recebimento**: placa (7 caracteres) e quantidade de notas; até 5 notas são fotografadas e lidas por OCR no navegador, com uma mensagem azul após cada nota salva orientando a próxima ação; o cliente é identificado (se o OCR não identificar, o motorista busca manualmente e confirma com um toque a única sugestão, quando houver), depois CNH e CRLV por QR, confirmação e ajudante. Acima de 5 notas o atendimento é encaminhado ao balcão da portaria ([fluxo 03](documentacao/fluxos/03-recebimento.md), [fluxo 04](documentacao/fluxos/04-notas-e-ocr.md)).
4. **Finalização**: check-in no Talent, que devolve o número de acesso, e impressão das etiquetas do motorista e do ajudante. Se o Talent responder 2xx sem número de registro, o atendimento é cancelado e o motorista é orientado a ir ao guichê da portaria, sem etiqueta ([fluxo 07](documentacao/fluxos/07-checkin-talent.md), [fluxo 08](documentacao/fluxos/08-impressao-etiquetas.md)).
5. **Cancelamento e abandono**: botão de cancelar, aviso de inatividade e cron de abandono ([fluxo 09](documentacao/fluxos/09-cancelamento-e-abandono.md)).

## 2. Funcionalidades implementadas

Marcadas conforme os documentos de fluxo e as regras ([regras de negócio](documentacao/regras-negocio.md)); nada aqui foi reexecutado ao escrever este README.

**Totem (quiosque)**

- Fluxos Expedição e Recebimento, multi-totem (cada totem tem código e token próprios); teclado virtual pt-BR e modal de inatividade.
- **LGPD**: aceite com token de uso único e registro em `tb_lgpd_aceite`.
- **CNH e CRLV por QR** (leitura no navegador, validação na **vio.api.br** feita pelo PHP), com preenchimento manual como alternativa ([fluxo 05](documentacao/fluxos/05-cnh-crlv-qr-vio.md), [API](documentacao/apis/vio-api-br.md)).
- **Notas fiscais e OCR** (Recebimento): foto pelo scanner, OCR client-side (Tesseract.js) e identificação do cliente na base local `tb_cliente`; busca manual com no máximo uma sugestão (cliente ativo único) quando o OCR não identifica.
- **Campos com filtro e máscara**: nome só com letras e espaço; CPF só com números (11); validade da CNH em `DD-MM-AAAA` (o totem converte para `AAAA-MM-DD` ao enviar); placa do Recebimento com 7 caracteres; CPF do ajudante com exatamente 11 números ([regras de negócio](documentacao/regras-negocio.md)).
- **Check-in no Talent** (`TALENT_CHECKIN_ATIVO` desligado por padrão em `.env.example`, fail-closed; `true` em produção, confirmado pelo responsável do projeto em 2026-10-10) e **sem fila de reenvio**. Resposta 2xx do Talent sem `nrRegAcesso` utilizável **cancela o atendimento** (HTTP 422 `TALENT_SEM_REGISTRO`) e o totem mostra a tela "Dirija-se ao guichê da portaria" (decisão de 2026-10-11; a OC da Expedição não é baixada) ([API](documentacao/apis/talent-checkin.md)).
- **Impressão** de etiquetas 80x80 mm (PDF gerado por FPDF) pelo serviço local do mini PC, com detecção de falta de papel ([API](documentacao/apis/impressao-servico-local.md)).
- **Ordens de coleta** (Expedição): consulta por placa e baixa no banco externo de gestão de coletas; anexo de PDF da OC recebido do **n8n** ([fluxo 06](documentacao/fluxos/06-consulta-e-baixa-ordem-coleta.md), [fluxo 10](documentacao/fluxos/10-anexo-ordem-coleta-n8n.md)).

**Gestão Totem** (painel web em `/gestao/`, perfis `admin` e `usuario`; [rotas](documentacao/apis/gestao-rotas.md))

| Área | Quem acessa | Fluxo |
|---|---|---|
| Acesso, sessão e **usuários** | `admin` (usuário comum só vê Minha conta) | [11](documentacao/fluxos/11-gestao-acesso-e-usuarios.md) |
| **Totens** (criar, ativar/desativar, regerar URL) | `admin` | [12](documentacao/fluxos/12-gestao-totens.md) |
| **Logs** do sistema (a auditoria só existe no banco; retenção de 90 dias) | `admin` | [13](documentacao/fluxos/13-gestao-logs-auditoria.md) |
| **Ordens de coleta** (lista, ativar/inativar, baixas pendentes, PDF) | `usuario` e `admin` | [14](documentacao/fluxos/14-gestao-ordens-coleta.md) |
| **Clientes e empresas** | `admin` | [15](documentacao/fluxos/15-gestao-clientes-empresas.md) |

## 3. Pendências relevantes

Resumo; a lista completa e as divergências detalhadas ficam nos documentos citados, não aqui.

- Validação física no mini PC de produção: falta de papel real (409 `sem_papel`), duas etiquetas em sequência, leitura de QR com o Netum, OCR no hardware, persistência da permissão de câmera e medidas finais do papel ([plano de testes, seções 4 e 6](documentacao/plano-testes.md)).
- Limites efetivos do PHP web, versão do Composer, driver real da impressora e flags do Chrome ([implantação, seção 17](documentacao/implantacao.md)).
- Confirmado pelo responsável do projeto em 2026-10-10 (não é mais pendência): os 5 crons agendados e em execução (crontab OK); PHP web de produção 8.3; `TALENT_CHECKIN_ATIVO=true` em produção; dependências instaladas pelo Composer com `composer install --no-dev --optimize-autoloader`; `display_errors=Off`.
- `CONCLUIR_EXIGE_NUMERO_NOTA` (variável opcional, só `true` liga; padrão do código e do `.env.example`: `false`): em produção está **`true`**, conferido em 2026-10-16. Ligada, `concluir-digitalizacao` responde 422 `NOTAS_SEM_NUMERO` se alguma nota não tiver número válido; desligada (padrão), o motorista segue e só o `finalizar` recusa nota com número vazio ([fluxo 04](documentacao/fluxos/04-notas-e-ocr.md), [implantação](documentacao/implantacao.md)).
- Talent: `ENVIO_INDETERMINADO` por timeout/falha de rede segue sem saída (o atendimento fica `em_andamento`); o caso "2xx sem `nrRegAcesso`" foi resolvido em 2026-10-11 (cancelado + guichê). A portaria só identifica esse caso pelo `tb_log_sistema` (`categoria_erro=resposta_ilegivel`); não há campo próprio ([fluxo 07](documentacao/fluxos/07-checkin-talent.md)).
- Decisão de produto (2026-10-10): não será tratado: validação de método HTTP nas APIs do totem (só `ordem-coleta-anexo.php` exige POST) e política de retenção/limpeza de atendimentos, notas/fotos concluídas e aceites LGPD (o comportamento atual está descrito em [banco-dados](documentacao/banco-dados.md), seção 4).
- Testes automatizados desatualizados ou dependentes de arquivos auxiliares fora do Git ([plano de testes, seção 6](documentacao/plano-testes.md)).
- Divergências entre documentos e código: [fluxos](documentacao/fluxos/README.md), [regras de negócio](documentacao/regras-negocio.md), [banco de dados](documentacao/banco-dados.md), [implantação, seção 16](documentacao/implantacao.md) e [APIs](documentacao/apis/README.md).

## 4. Tecnologias e arquitetura

- **Backend**: PHP, MVC em camadas (`Controller` / `Dao` / `Rn` / `Model` em `app/`; utilitários em `util/`), PDO com prepared statements, autoload PSR-4 (`App\` em `app/`, `Util\` em `util/`).
- **Banco**: MySQL/MariaDB. Dois bancos: o do totem (estrutura em `sql/schema.sql`) e o **banco externo de gestão de coletas**, preexistente, acessado por PDO com conexão própria ([banco de dados](documentacao/banco-dados.md)).
- **Frontend**: HTML, CSS e JS vanilla (sem framework nem build), via `fetch()`. Bibliotecas versionadas em `public/totem/assets/vendor/`: Tesseract.js 5.1.1 (OCR) e jsQR (versão: `PENDENTE DE CONFIRMAÇÃO`).
- **Dependências PHP** (`composer.json`): `vlucas/phpdotenv` e `setasign/fpdf`.
- **Serviço de impressão local**: Node.js em `servico-impressao-local/`, no mini PC Windows, escuta só em `127.0.0.1`.
- **Hospedagem**: HostGator compartilhada (cPanel, LiteSpeed, Cloudflare). Sem processo persistente, sem WebSocket e sem Docker; cron só pelo cron do cPanel.
- **Segurança**: toda rota de API exige o token do totem (`Util\Auth`), exceto a rota do n8n (chave própria); `storage/` fica fora da pasta pública; a Gestão usa sessão, CSRF e RBAC no servidor.

Quem chama quem (resumo; diagrama e detalhes em [`documentacao/apis/README.md`](documentacao/apis/README.md)):

- Quiosque (Chrome) -> `public/api/*.php` (token do totem) e -> serviço de impressão em `127.0.0.1` (token próprio).
- PHP -> banco do totem, banco externo de coletas, vio.api.br e Talent.
- n8n -> `public/api/ordem-coleta-anexo.php`.
- Gestor (navegador) -> `public/gestao/*.php`.

## 5. Estrutura de diretórios

```
.
├── app/                        Controller, Dao, Rn, Views (gestão), Content (termo LGPD)
├── util/                       Auth, Bootstrap, Conexao, Resposta, helpers de storage, etiqueta etc.
├── public/                     ÚNICA pasta servida (docroot)
│   ├── api/                    endpoints do totem e rota do n8n
│   ├── gestao/                 painel Gestão Totem
│   └── totem/                  quiosque (index.php, assets, vendor/)
├── cron/                       5 jobs (somente PHP CLI)
├── sql/schema.sql              única fonte da estrutura do banco do totem
├── tools/                      criar-admin.php, verificar-vendor.php (somente CLI)
├── tests/manual/               testes (scripts CLI; sem PHPUnit)
├── servico-impressao-local/    serviço Node do mini PC (próprio README)
├── documentacao/               documentação do projeto
├── storage/                    dados do atendimento (ignorado pelo Git; ver seção 7)
├── composer.json, composer.lock
├── .env.example                modelo de configuração
└── CHANGELOG.md
```

Não é versionado: `.env`, `vendor/`, `storage/`, `servico-impressao-local/config/config.json` e `servico-impressao-local/node_modules/`.

## 6. Requisitos e versões confirmadas

### Obrigatórios

- PHP com as extensões abaixo, Composer (desenvolvimento) e MySQL/MariaDB com usuário que possa criar tabelas.
- Servidor web com o docroot em `public/` e HTTPS válido no quiosque (`getUserMedia` exige contexto seguro).
- Para imprimir: o serviço de impressão no mini PC (Node.js).

### Opcionais

- Para os testes de front: Node, `puppeteer` (instalado fora do projeto) e Chrome/Chromium. `php-cgi` para as suítes de Gestão e de anexo de OC. Detalhes em [plano de testes, seção 1.2](documentacao/plano-testes.md).

### Versões confirmadas

| Item | Versão | Fonte |
|---|---|---|
| PHP exigido | `>=8.0` | `composer.json` |
| PHP em produção | 8.3 (CLI `ea-php83` e web; web confirmado pelo responsável do projeto em 2026-10-10) | [implantação](documentacao/implantacao.md) |
| PHP no dev local | 8.0.30 (XAMPP) | [implantação](documentacao/implantacao.md) |
| MySQL em produção | 5.7.44 | [implantação](documentacao/implantacao.md) |
| MariaDB no dev local | `PENDENTE DE CONFIRMAÇÃO` (versão) | n/a |
| `vlucas/phpdotenv` | `^5.6` (travado em v5.7.0) | `composer.json`, `composer.lock` |
| `setasign/fpdf` | `^1.8` (travado em 1.9.0) | `composer.json`, `composer.lock` |
| Node.js (serviço de impressão) | `>=22.17.1` | `servico-impressao-local/package.json` |
| `express`, `pdf-to-printer`, `node-windows` | `^4.19.2`, `^5.6.0`, `^1.0.0-beta.8` (`node-windows` é legado, serviço do Windows descartado) | `servico-impressao-local/package.json` |
| Tesseract.js (front) | 5.1.1 | `public/totem/assets/vendor/` |
| jsQR, Composer, Chrome do quiosque, Windows do mini PC | `PENDENTE DE CONFIRMAÇÃO` | n/a |

### Extensões PHP usadas

Conforme [`documentacao/implantacao.md`](documentacao/implantacao.md) (seção 1): `pdo_mysql`, `curl`, `gd` (sem GD toda imagem é rejeitada), `openssl`, `mbstring` (usada, mas não declarada no `composer.json`), `ctype`, `json`, `hash`, `pcre`. Opcionais: `intl` (há fallback) e Argon2id (há fallback para bcrypt).

## 7. Instalação (ordem cronológica)

Passo a passo para uma instalação nova. O detalhamento de produção (HostGator, cron, Cloudflare, atualização e rollback) está em [`documentacao/implantacao.md`](documentacao/implantacao.md). Em produção, cada passo depende de autorização de quem opera a hospedagem.

### 7.1 Obter o código

Clone o repositório fora de qualquer pasta pública do servidor (em produção, em `<INSTALACAO>`, por exemplo uma pasta de aplicações da conta `<CONTA_CPANEL>`).

### 7.2 Instalar as dependências PHP

- **Desenvolvimento**: na raiz, `composer install`. O `composer.lock` é versionado; `vendor/` não é.
- **Produção**: as dependências são instaladas pelo Composer com `composer install --no-dev --optimize-autoloader` (confirmado pelo responsável do projeto em 2026-10-10). A versão do Composer não está comprovada (`PENDENTE DE CONFIRMAÇÃO`).
- Conferência dos assets JS do totem: `php tools/verificar-vendor.php` (compara `public/totem/assets/vendor/` com `MANIFEST.sha256`; somente CLI).

### 7.3 Criar o `.env`

1. Copie `.env.example` para `.env` na raiz e preencha ([seção 8](#8-variáveis-do-env)).
2. O `.env` nunca vai para o Git nem para a pasta pública; em produção, permissão `600`.
3. Segredos hex de 64 caracteres: gere no próprio servidor com `php -r "echo bin2hex(random_bytes(32));"`. Não cole o valor em chat, chamado ou documento.
4. Defina `STORAGE_PATH` para uma pasta **fora do docroot** e gravável pelo usuário do PHP (fotos de CNH, CPF, CRLV e notas, e PDFs de OC são dados pessoais).

### 7.4 Criar o banco e aplicar o schema

1. Crie um banco **vazio** (em produção, o nome e o usuário levam o prefixo da conta cPanel) e o usuário MySQL. O banco externo de gestão de coletas já existe na UDLOG: use o mesmo servidor e a mesma credencial e informe só o nome em `GESTAO_COLETAS_DB_NAME`.
2. Aplique `sql/schema.sql` no banco do totem (phpMyAdmin, cPanel ou terminal). O script **não é idempotente**, não cria o banco e não tem seed.
3. **`sql/schema.sql` é a única fonte da estrutura.** As migrations foram removidas em 2026-10-09; uma mudança futura de estrutura exige nova migration e a atualização do `schema.sql`. Descrição das tabelas: [`documentacao/banco-dados.md`](documentacao/banco-dados.md).

### 7.5 Criar o primeiro administrador

Em terminal interativo, na raiz (usa o `.env` da máquina onde roda; confira que ele aponta para o banco pretendido):

```
php tools/criar-admin.php [--login=primeiro.segundo] [--nome=NOME] [--forcar]
```

A senha é digitada sem eco (mínimo de 12 caracteres), nunca por argumento, pipe ou `.env`. O script recusa se já existe admin ativo, salvo `--forcar`.

### 7.6 Publicar e executar

- **Docroot = `public/`**: quiosque em `/totem/?totem=CODIGO`, gestão em `/gestao/`, API em `/api/`.
- **Local (XAMPP)**: Windows com Apache e PHP 8.0.30, MariaDB e banco `udlog_totem`. Sem HTTPS, defina `GESTAO_PERMITIR_HTTP=true` e use `TOTEM_URL_BASE=http://localhost:8080/totem/`. Como o Apache aponta o docroot para `public/` na porta 8080 é `PENDENTE DE CONFIRMAÇÃO` (não há vhost versionado).
- **Produção**: AutoSSL (Let's Encrypt via cPanel) no domínio. Não defina `GESTAO_PERMITIR_HTTP` nem `ORDEM_COLETA_ANEXO_PERMITIR_HTTP`. Cloudflare: `/gestao/*` deve ficar sem cache.

### 7.7 Cadastrar empresa, totem e clientes (pela Gestão)

Entre em `/gestao/` com o administrador e, nesta ordem ([fluxos 12](documentacao/fluxos/12-gestao-totens.md) e [15](documentacao/fluxos/15-gestao-clientes-empresas.md)):

1. **Empresa** (o CNPJ do armazém no Talent). Empresa inativa faz a finalização responder 500.
2. **Totem**: a tela gera o código `NOME-EMPRESA-HASH16` e o token. A URL do quiosque é `<TOTEM_URL_BASE>?totem=NOME-EMPRESA-HASH16` (exige `TOTEM_URL_BASE` e `GESTAO_HASH_SALT` no `.env`).
3. **Clientes** (`tb_cliente`, base usada na identificação por OCR). A origem do carregamento inicial dos 38 clientes de produção não está documentada (`PENDENTE DE CONFIRMAÇÃO`).

### 7.8 Permissões

- Arquivos `644`, diretórios `755`, `.env` `600`.
- **Nunca** deixe arquivo público em `600` (o servidor web precisa ler os estáticos) e nunca rode `git pull` sob `umask 077`.
- `STORAGE_PATH` e subpastas graváveis pelo usuário do PHP (web e cron). O código cria pastas `0750`; só os PDFs de ordem de coleta e os arquivos de contador de rate limit recebem `0640`; as fotos das notas são gravadas sem `chmod` e ficam com o `umask` do processo.
- Não podem ser acessíveis por URL: `storage/`, `app/`, `util/`, `sql/`, `tests/`, `tools/`, `cron/`, `.env`, `vendor/`, `documentacao/`. Não há `.htaccess` versionado; a proteção é o docroot em `public/`.

### 7.9 Instalar o serviço de impressão (mini PC)

Resumo; passo a passo completo no [README do serviço](servico-impressao-local/README.md):

1. Node.js `>=22.17.1` e o driver oficial da impressora instalados no Windows.
2. Copie `servico-impressao-local/` para `<PASTA_DO_SERVICO>` e rode `npm install`.
3. Copie `config/config.example.json` para `config/config.json` e preencha `porta`, `token`, `origensPermitidas` (origem do totem, sem path), `impressorasPermitidas` e `timeoutMs`.
4. Leve o **mesmo** `token` para `IMPRESSAO_LOCAL_TOKEN` no `.env` do PHP (cópia manual) e ajuste `IMPRESSAO_LOCAL_URL` e `IMPRESSAO_FRONTEND_TIMEOUT_MS` (maior que `timeoutMs`).
5. Teste com `npm start` e `GET /saude`; depois instale a tarefa de logon (`scripts/instalar-tarefa-logon.ps1`).

### 7.10 Agendar os crons (produção, cron do cPanel)

Cinco jobs, todos somente PHP CLI, sem credencial no comando: `limpar-rate-limit-ocr`, `abandonar-atendimentos`, `limpar-notas-quarentena`, `limpar-anexos-ordem-coleta` e `limpar-logs-gestao` (arquivos em `cron/`). Estão agendados e executando em produção (confirmado pelo responsável do projeto em 2026-10-10). Horários, o que cada um faz e o comando-modelo: [implantação, seção 9](documentacao/implantacao.md). **Nunca agende reenvio**: a fila de reenvio ao Talent foi removida.

### 7.11 Apontar o quiosque

No mini PC, o Chrome em modo quiosque abre a URL do totem do passo 7.7. Detalhes do mini PC: [implantação, seção 12](documentacao/implantacao.md).

## 8. Variáveis do `.env`

Valores reais nunca entram neste documento. A matriz completa (obrigatoriedade, padrão e efeito de cada variável) está em [`documentacao/implantacao.md`](documentacao/implantacao.md) (seção 4); o modelo comentado é [`.env.example`](.env.example).

| Grupo | Variáveis | Finalidade |
|---|---|---|
| Banco | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT` | Banco do totem (servidor e credencial também servem ao banco externo). |
| Banco externo | `GESTAO_COLETAS_DB_NAME` | Nome do banco de gestão de coletas (OCs). |
| Storage | `STORAGE_PATH` | Raiz de armazenamento, fora do docroot. |
| Gestão | `GESTAO_HASH_SALT`, `TOTEM_URL_BASE`, `GESTAO_SESSION_IDLE_MIN`, `GESTAO_SESSION_ABSOLUTE_MIN` | Segredo do limite de login e CSRF; URL base do quiosque; tempos de sessão. |
| Talent | `TALENT_API_URL`, `TALENT_API_KEY`, `TALENT_CHECKIN_ATIVO` | Check-in (só o literal `true` liga). |
| vio.api.br | `VIO_API_BR_BASE_URL`, `VIO_API_BR_API_KEY` | Validação de CNH e CRLV. |
| Impressão | `IMPRESSAO_LOCAL_URL`, `IMPRESSAO_LOCAL_TOKEN`, `IMPRESSAO_FRONTEND_TIMEOUT_MS` | Ligação com o serviço local. |
| Etiqueta | `ETIQUETA_LARGURA_MM`, `ETIQUETA_COMPRIMENTO_MM`, `ETIQUETA_ORIENTACAO`, `ETIQUETA_CORTE_APOS_IMPRESSAO`, `ETIQUETA_AREA_UTIL_MM` | Papel e área útil da etiqueta. |
| n8n | `ORDEM_COLETA_ANEXO_API_KEY`, `ORDEM_COLETA_ANEXO_MAX_BYTES` | Chave Bearer do anexo de OC e teto do PDF. |
| Limites e regras | `NOTA_IMAGEM_MAX_BYTES`, `CONCLUIR_EXIGE_NUMERO_NOTA` | Teto de imagem; exigência de número da nota (padrão `false`; `true` em produção, conferido em 2026-10-16). |
| Cache de documentos | `DOCUMENTO_DATA_KEY` | Chave do cache de CNH/CRLV, que o fluxo atual (QR) não consulta. |
| Só desenvolvimento | `GESTAO_PERMITIR_HTTP`, `ORDEM_COLETA_ANEXO_PERMITIR_HTTP` | Liberam HTTP; não definir em produção. |

O serviço de impressão (Node) **não lê o `.env`**: usa `servico-impressao-local/config/config.json`.

## 9. Equipamentos e serviços externos

| Item | Papel | Documento |
|---|---|---|
| Mini PC Windows + monitor touch em retrato | Roda o Chrome em modo quiosque e o serviço de impressão | [implantação, seção 12](documentacao/implantacao.md) |
| Scanner Netum SD-2000 | Câmera UVC (videoinput) para notas e QR; o NetumScan Pro não pode estar aberto | [implantação](documentacao/implantacao.md), [fluxo 04](documentacao/fluxos/04-notas-e-ocr.md) |
| Impressora EPSON TM-T88VII | Etiqueta 80x80 mm, área útil de 48 mm | [README do serviço](servico-impressao-local/README.md) |
| Serviço de impressão local | Node em `127.0.0.1`, recebe o PDF e imprime | [API](documentacao/apis/impressao-servico-local.md) |
| vio.api.br | Validação de CNH e CRLV por QR | [API](documentacao/apis/vio-api-br.md) |
| Talent | Check-in e número de acesso | [API](documentacao/apis/talent-checkin.md) |
| Banco externo de gestão de coletas | Ordens de coleta (PDO direto, não é HTTP) | [API](documentacao/apis/banco-gestao-coletas.md) |
| n8n | Envia o PDF da OC para `ordem-coleta-anexo.php` | [API](documentacao/apis/n8n-anexo-ordem-coleta.md) |

## 10. Testes

- Não há PHPUnit nem CI. Os testes são **scripts CLI** em `tests/manual/` que imprimem OK/FALHA e terminam com código de saída (0 = sem falha): `php tests/manual/teste_NOME.php` e `node tests/manual/NOME.js`, a partir da raiz.
- O serviço de impressão tem suíte própria: `cd servico-impressao-local && npm test` (não usa impressora física).
- **As suítes que gravam dados usam banco QA descartável (`qa_*`), criado a partir de `sql/schema.sql` e removido ao final, e NUNCA o banco de desenvolvimento nem o banco externo real.** Os prepends de teste são fail-closed (saem com código 3 sem banco QA válido). Nunca contorne essa guarda.
- Algumas suítes legadas ainda usam o banco de desenvolvimento ou dependem de arquivos auxiliares `tests/manual/_*.php` que não estão no Git: a lista e o que **não rodar sem isolar** estão em [`documentacao/plano-testes.md`](documentacao/plano-testes.md) (seção 6).
- Testes reais contra Talent, vio.api.br, n8n ou impressora só com autorização prévia do responsável.
- Ordem recomendada de execução, cenários, comandos e resultados históricos (que **não** valem como aprovação atual): [`documentacao/plano-testes.md`](documentacao/plano-testes.md).

## 11. Solução de problemas confirmados

Somente situações que constam na documentação e no código.

| Sintoma | Causa e ação |
|---|---|
| **403** em CSS/JS ou estático após `git pull` | Arquivo público com permissão `600` (ocorreu com `gestao.css` sob `umask 077`). Rode `find public -type f -perm 600` (deve ficar vazio) e corrija para `644`. Nunca puxe sob `umask 077`. |
| **503** em qualquer página da Gestão | `GESTAO_HASH_SALT` ausente ou com menos de 16 caracteres. |
| **503** na tela Totens | `TOTEM_URL_BASE` ausente ou inválida (deve terminar em `/`, sem query). |
| **404** na página do totem | Resposta uniforme para código inválido, inexistente ou totem inativo, e para `/totem/` sem código. Confira o código na tela Totens e se o totem está ativo. |
| **503** "Servico temporariamente indisponivel" nas APIs | Falha de `.env` ou de conexão com o banco, antes da autenticação. |
| **401** nas APIs do totem | Token ausente ou inválido, ou totem inativo. |
| **503** `TALENT_CHECKIN_DESATIVADO` ao finalizar | `TALENT_CHECKIN_ATIVO` diferente do literal `true` (fail-closed, comportamento esperado por padrão). |
| **503** na rota do n8n | `ORDEM_COLETA_ANEXO_API_KEY` ausente ou vazia. |
| Quiosque com tela antiga após deploy | O HTML do quiosque é `no-store` e os assets levam `?v=` do `filemtime`. Confira o hash do arquivo servido contra o Git ([implantação, seção 10](documentacao/implantacao.md)). Troca da URL no mini PC: editar o `.bat` de inicialização e reiniciar o PC. |
| Impressão bloqueada no navegador (**403**/CORS) | A origem do totem não está em `origensPermitidas` do `config.json` (com a lista vazia o serviço recusa qualquer chamada de navegador). |
| Impressão com **401** | `token` do `config.json` diferente de `IMPRESSAO_LOCAL_TOKEN`. |
| **409** `sem_papel` | O job ficou retido na fila da impressora; o serviço remove só esse job. Coloque papel e reimprima pela tela do totem. |
| Câmera não abre | O quiosque exige HTTPS válido; o NetumScan Pro não pode estar aberto. Persistência da permissão de câmera: `PENDENTE DE CONFIRMAÇÃO`. |
| Cron encerra com erro | Faltam `STORAGE_PATH` ou o banco; os scripts só rodam em PHP CLI. |
| Gestão recusa HTTP no desenvolvimento | HTTPS é obrigatório; só em desenvolvimento local defina `GESTAO_PERMITIR_HTTP=true`. |

## 12. Documentação

- [`documentacao/implantacao.md`](documentacao/implantacao.md): hospedagem, `.env`, banco, permissões, crons, atualização, smoke, rollback e divergências.
- [`documentacao/banco-dados.md`](documentacao/banco-dados.md): tabelas, relacionamentos e retenção.
- [`documentacao/regras-negocio.md`](documentacao/regras-negocio.md): regras de negócio.
- [`documentacao/plano-testes.md`](documentacao/plano-testes.md): como testar.
- [`documentacao/apis/`](documentacao/apis/README.md):
  [API interna do totem](documentacao/apis/totem-api-interna.md),
  [impressão e serviço local](documentacao/apis/impressao-servico-local.md),
  [vio.api.br](documentacao/apis/vio-api-br.md),
  [Talent](documentacao/apis/talent-checkin.md),
  [banco de gestão de coletas](documentacao/apis/banco-gestao-coletas.md),
  [anexo da OC (n8n)](documentacao/apis/n8n-anexo-ordem-coleta.md),
  [rotas da Gestão](documentacao/apis/gestao-rotas.md).
- [`documentacao/fluxos/`](documentacao/fluxos/README.md):
  [01 LGPD](documentacao/fluxos/01-lgpd-e-inicio-atendimento.md),
  [02 Expedição](documentacao/fluxos/02-expedicao.md),
  [03 Recebimento](documentacao/fluxos/03-recebimento.md),
  [04 Notas e OCR](documentacao/fluxos/04-notas-e-ocr.md),
  [05 CNH/CRLV/QR](documentacao/fluxos/05-cnh-crlv-qr-vio.md),
  [06 Ordem de coleta](documentacao/fluxos/06-consulta-e-baixa-ordem-coleta.md),
  [07 Talent](documentacao/fluxos/07-checkin-talent.md),
  [08 Impressão](documentacao/fluxos/08-impressao-etiquetas.md),
  [09 Cancelamento](documentacao/fluxos/09-cancelamento-e-abandono.md),
  [10 Anexo n8n](documentacao/fluxos/10-anexo-ordem-coleta-n8n.md),
  [11 Gestão: acesso](documentacao/fluxos/11-gestao-acesso-e-usuarios.md),
  [12 Gestão: totens](documentacao/fluxos/12-gestao-totens.md),
  [13 Gestão: logs](documentacao/fluxos/13-gestao-logs-auditoria.md),
  [14 Gestão: ordens](documentacao/fluxos/14-gestao-ordens-coleta.md),
  [15 Gestão: clientes e empresas](documentacao/fluxos/15-gestao-clientes-empresas.md).
- [`servico-impressao-local/README.md`](servico-impressao-local/README.md): instalação e operação do serviço de impressão.
- [`CHANGELOG.md`](CHANGELOG.md): histórico por marcos.
- [`.env.example`](.env.example): modelo de configuração.

**Nota ao leitor.** `docs/` é material de apoio local, listado no `.gitignore`, fora do repositório: não vem em um clone. Nada neste README ou na instalação depende dele. Onde a documentação traz relatos de situações anteriores, trata-se de registro histórico que só vale com a data informada.
