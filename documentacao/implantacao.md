# Implantação do totem-udlog

Guia de implantação e atualização do totem de autoatendimento UDLOG (Expedição e Recebimento). Descreve o que está **confirmado** (registrado nos deploys já feitos) e marca como `PENDENTE DE CONFIRMAÇÃO` o que não está.

Este guia não executa nada: cada passo em produção depende de autorização explícita de quem opera a hospedagem. Nenhum valor real (credencial, token, chave, caminho de máquina) aparece aqui; use os placeholders `<CONTA_CPANEL>` e `<INSTALACAO>` (raiz do repositório no servidor).

Documentos relacionados: [banco de dados](banco-dados.md) e [fluxos](fluxos/README.md).

## 1. Requisitos da hospedagem

Ambiente real: hospedagem compartilhada HostGator via cPanel, com SSH de usuário (sem root), atrás de Cloudflare.

| Item | Situação |
|---|---|
| Servidor web | LiteSpeed (LSAPI); `fastcgi_finish_request` não existe. |
| PHP | `composer.json` exige `php >=8.0`. Produção: PHP 8.3 no CLI (`ea-php83`) e na web (web confirmado pelo responsável do projeto em 2026-10-10). Desenvolvimento: 8.0.30. |
| Banco | MySQL 5.7.44 em produção (banco do totem e banco externo de gestão de coletas); MariaDB em desenvolvimento. |
| Extensões usadas | `pdo_mysql`, `curl`, `gd` (sem GD toda imagem é rejeitada), `openssl`, `mbstring` (usada e não declarada no `composer.json`; presente em produção), `ctype`, `json`, `hash`, `pcre`. Opcionais: `intl` (há fallback), Argon2id (há fallback para bcrypt; disponível em produção). |
| Limites do PHP | `memory_limit` >= 128M (256M recomendado para o GD), `post_max_size` >= 16M para o anexo de OC (~7 MB de corpo), `upload_max_filesize`, `max_execution_time`: `PENDENTE DE CONFIRMAÇÃO` no PHP efetivo da web (CLI e web podem divergir). |
| Rede de saída | HTTPS (curl) para Talent e vio.api.br. Bloqueios de rede de saída da hospedagem, por integração: `PENDENTE DE CONFIRMAÇÃO`. |
| HTTPS | AutoSSL (Let's Encrypt via cPanel) no domínio. A gestão e a rota de anexo da OC exigem HTTPS. |
| Cron | Somente o cron do cPanel (PHP CLI). |
| Proibido | Docker, filas persistentes, WebSocket server, processo persistente, binário arbitrário no servidor. |

## 2. Preparação do ambiente

1. Domínio ou subdomínio apontando para a pasta `public/` do repositório (ver seção 8).
2. Repositório clonado fora do `public_html`, em `<INSTALACAO>` (em produção: pasta de aplicações da conta, por exemplo `/home2/<CONTA_CPANEL>/apps/totem-udlog`).
3. Dois bancos criados no cPanel (o nome e o usuário levam o prefixo da conta): o banco do totem e o banco externo de gestão de coletas (já existente na UDLOG; o totem usa o mesmo servidor e a mesma credencial, só muda o nome do banco).
4. Pasta de storage criada fora do docroot, por exemplo `/home/<CONTA_CPANEL>/storage/atendimentos`, gravável pelo usuário do PHP (ver seção 6).
5. `.env` criado a partir de `.env.example` (seção 4).

## 3. Dependências (`vendor/`)

- `vendor/` **não é versionado** (`.gitignore`) e o `composer.lock` é versionado (dependências: `vlucas/phpdotenv`, `setasign/fpdf` e transitivas).
- Em produção as dependências são instaladas pelo Composer; o comando de implantação é `composer install --no-dev --optimize-autoloader` (confirmado pelo responsável do projeto em 2026-10-10). A versão do Composer em produção não está comprovada (`PENDENTE DE CONFIRMAÇÃO`).
- As classes resolvem via PSR-4 (`App\` em `app/`, `Util\` em `util/`).
- Ferramenta de verificação: `php tools/verificar-vendor.php` confere os assets JS do totem em `public/totem/assets/vendor/` contra `MANIFEST.sha256` (somente CLI).
- Serviço de impressão do mini PC: Node.js >= 22.17.1 e `npm install` na pasta `servico-impressao-local/` (seção 12).

## 4. Configuração segura do `.env`

Regras:

- O `.env` **nunca** vai para o Git nem para a pasta pública; permissão `600`.
- O modelo versionado é `.env.example` (valores fictícios; hosts `exemplo.invalid`; check-in do Talent desligado).
- Gerar segredos hex de 64 caracteres no próprio servidor: `php -r "echo bin2hex(random_bytes(32));"`. Nunca colar o valor em chat, chamado ou documento.
- O arquivo é lido por `vlucas/phpdotenv` (`createImmutable`): uma variável já definida no ambiente do processo prevalece sobre o arquivo.
- Não definir em produção `GESTAO_PERMITIR_HTTP` nem `ORDEM_COLETA_ANEXO_PERMITIR_HTTP` (só desenvolvimento local sem HTTPS).
- Em produção, depois de editar o `.env`, conferir `chmod 600` e que não há CRLF.

| Variável | Obrigatória? | Finalidade |
|---|---|---|
| `DB_HOST` | Sim | Servidor MySQL (também usado pelo banco externo). |
| `DB_NAME` | Sim | Banco do totem. |
| `DB_USER` | Sim | Usuário MySQL (também usado pelo banco externo). |
| `DB_PASS` | Sim | Senha MySQL. |
| `DB_PORT` | Não (padrão 3306) | Porta do MySQL. |
| `GESTAO_COLETAS_DB_NAME` | Sim para Ordem de Coleta | Banco externo de gestão de coletas (consulta/baixa de OC, anexos, tela Ordens). |
| `STORAGE_PATH` | Sim | Raiz de armazenamento, fora do docroot e gravável. Os crons de abandono, quarentena e anexos encerram com erro sem ela. |
| `GESTAO_HASH_SALT` | Sim para a Gestão | Segredo (>= 16 caracteres; recomendado 64 hex) usado no limite de login, CSRF do login e rate limit da página do totem. Ausente/curto: a gestão responde 503. Definir só no servidor. Trocar o valor zera contadores de IP e invalida formulários de login abertos (sessões abertas não são afetadas). |
| `TOTEM_URL_BASE` | Sim para a tela Totens | URL base do quiosque (termina em `/`, sem query); nunca derivada do Host. Ausente/inválida: 503. |
| `TALENT_API_URL` | Sim para check-in real | Base URL do Talent (sem o endpoint). |
| `TALENT_API_KEY` | Sim para check-in real | Token Bearer do Talent. |
| `TALENT_CHECKIN_ATIVO` | Não (fail-closed) | Só o literal `true` liga a chamada real; senão a finalização responde 503. |
| `VIO_API_BR_BASE_URL` | Sim para validar documentos | Base HTTPS da vio.api.br (o cliente recusa vazio ou não-HTTPS). |
| `VIO_API_BR_API_KEY` | Sim para validar documentos | Chave enviada em `X-API-Key`. |
| `IMPRESSAO_LOCAL_URL` | Sim para imprimir | URL do serviço de impressão local (127.0.0.1 do mini PC). |
| `IMPRESSAO_LOCAL_TOKEN` | Sim para imprimir | Mesmo valor do campo `token` do `config.json` do serviço (cópia manual). |
| `IMPRESSAO_FRONTEND_TIMEOUT_MS` | Sim para imprimir | Timeout (numérico > 0) devolvido ao front; deve ser maior que `timeoutMs` do serviço. |
| `ETIQUETA_LARGURA_MM` | Sim | Largura do papel em mm (ausente/inválida: erro 500). |
| `ETIQUETA_COMPRIMENTO_MM` | Sim | Comprimento do papel em mm. |
| `ETIQUETA_ORIENTACAO` | Sim | `portrait` ou `landscape`. |
| `ETIQUETA_CORTE_APOS_IMPRESSAO` | Sim | `true` ou `false`. |
| `ORDEM_COLETA_ANEXO_API_KEY` | Sim para a rota do n8n | Bearer enviado pelo n8n; ausente/vazia: a rota responde 503. |
| `ETIQUETA_AREA_UTIL_MM` | Não (padrão 48) | Largura útil do conteúdo da etiqueta (20 até a largura da página). |
| `NOTA_IMAGEM_MAX_BYTES` | Não (padrão 5242880) | Teto do binário decodificado de imagens. |
| `ORDEM_COLETA_ANEXO_MAX_BYTES` | Não (padrão 5242880) | Teto do PDF decodificado do anexo da OC. |
| `CONCLUIR_EXIGE_NUMERO_NOTA` | Não (padrão do código e do `.env.example`: `false`; em produção: `true`) | Só `true` liga o 422 `NOTAS_SEM_NUMERO` em `concluir-digitalizacao` (alguma nota ativa sem número válido; o front destaca as notas). Desligada, o motorista segue e só o `finalizar` recusa (422 `NOTAS_SEM_NUMERO`) se alguma nota tiver número vazio. **Em produção está `true`, conferido em 2026-10-16.** |
| `GESTAO_SESSION_IDLE_MIN` | Não (30; faixa 1..1440) | Inatividade da sessão da gestão. |
| `GESTAO_SESSION_ABSOLUTE_MIN` | Não (720; faixa 1..10080) | Teto absoluto da sessão da gestão. |
| `DOCUMENTO_DATA_KEY` | Não | Chave AES-256-GCM (64 hex) do cache de CNH/CRLV (`VioCacheDao`), que o fluxo atual (QR) não consulta. |
| `GESTAO_PERMITIR_HTTP` | Não (só dev) | Libera HTTP na gestão; não definir em produção. |
| `ORDEM_COLETA_ANEXO_PERMITIR_HTTP` | Não (só dev) | Libera HTTP na rota de anexo; não definir em produção. |

O serviço de impressão (Node) **não lê o `.env`**: usa `servico-impressao-local/config/config.json` (não versionado; modelo `config.example.json`) e, opcionalmente, a variável de processo `CONFIG_PATH`.

Variáveis que existiram no `.env.example` e foram removidas por não terem consumidor no código: `DOCUMENTO_QR_HMAC_KEY`, `VIO_API_BR_CACHE_TTL_DIAS`, `VIO_API_BR_CACHE_HMAC_KEY_V1`, `VIO_API_BR_CACHE_HMAC_VERSION`, `IMPRESSORAS_PERMITIDAS`, `IMPRESSAO_TIMEOUT_MS` (as duas últimas são do `config.json` do serviço, não do PHP). Se algum `.env` antigo as contiver, são ignoradas.

## 5. Banco de dados

- **`sql/schema.sql` é a única fonte da estrutura do banco do totem.** As migrations (`sql/migrations/` e `sql/migrations_gestao_coletas/`) foram removidas em 2026-10-09; não há mais histórico. Mudança futura de estrutura exige uma nova migration.
- Instalação nova: aplicar `sql/schema.sql` no banco do totem (phpMyAdmin, cPanel ou terminal). O arquivo é equivalente à estrutura de produção (MySQL 5.7.44); a única diferença cosmética está em `tb_totem` (FK `fk_totem_empresa` e um índice redundante existem em produção).
- O banco externo de gestão de coletas é preexistente; sua estrutura de referência fica no dump local `docs/<CONTA_CPANEL>_db_gestao_coletas.sql` (fora do Git). Antes de qualquer alteração nele, use `SHOW CREATE TABLE` no servidor como fonte de verdade.
- Descrição das tabelas: [banco-dados.md](banco-dados.md).
- Antes do primeiro acesso à gestão: criar o primeiro administrador por CLI (somente em terminal interativo; a senha é digitada sem eco, nunca por pipe, argumento ou `.env`):
  `php tools/criar-admin.php [--login=primeiro.segundo] [--nome=NOME] [--forcar]`
  O script usa o `.env` da máquina onde roda. `--forcar` só ignora a checagem "já existe admin ativo" e cria **outro** admin com login novo; login já existente é recusado; nada é desbloqueado nem recriado.
- Ao menos um totem em `tb_totem`: pela tela Totens da gestão (código `NOME-EMPRESA-HASH16`, token novo gerado). O totem RECEPCAO-01 segue com URL fixa `?totem=RECEPCAO-01` até o usuário regerar.
- Privilégios do usuário MySQL: ALL nos dois bancos em produção. Segundo usuário MySQL só para DELETE da auditoria: não confirmado (não existe hoje).

## 6. Permissões

- Arquivos `644`, diretórios `755`, `.env` `600`.
- **Nunca** deixar arquivo público em `600`: o servidor web precisa ler os estáticos (incidente de 2026-10-09: `gestao.css` com `0600` respondeu 403 após `git pull` sob `umask 077`). Nunca rodar `git pull` sob `umask 077`; usar `umask 077` só em subshell para arquivos temporários.
- `public/` é `750` com grupo `nobody` (padrão cPanel do docroot).
- `STORAGE_PATH` e subpastas graváveis pelo usuário do PHP (web e cron são o mesmo usuário cPanel). `rename` e `unlink` exigem escrita na **pasta**. O código cria pastas `0750`; só os PDFs de ordem de coleta e os arquivos de contador de rate limit recebem `0640`; as fotos das notas são gravadas sem `chmod` e ficam com o `umask` do processo. Subpastas criadas pelo código: `AAAA-MM-DD/PLACA_HHMMSS/`, `ordens_coleta/<cnpj>/`, `ordens_coleta_ratelimit/`, `tmp/`, `totem_pagina_ratelimit/`, `log_throttle/`.
- Backups em pasta `700` e arquivos `600`, fora do docroot; nunca colar conteúdo em chat ou chamado (o dump de `tb_totem` contém tokens).

## 7. Pastas e arquivos que não podem ser públicos

Somente `public/` é servido. Não podem ser acessíveis por URL: `storage/` (fotos de CNH/CPF/CRLV/notas e PDFs de OC, LGPD), `app/`, `util/`, `sql/`, `tests/`, `tools/`, `cron/`, `.env`, `vendor/`, `docs/` e `documentacao/`. Não há `.htaccess` versionado: a proteção é o docroot em `public/`.

Verificação feita em produção (2026-10-08/09): `/storage`, `/app`, `/sql`, `/tools`, `/cron` e `/vendor` respondem 404 e `/.env` responde 406. `tools/*.php` e `cron/*.php` recusam SAPI diferente de CLI. A confirmação formal de que `storage/` está fora do docroot real consta como pendência aberta (P35).

## 8. Docroot, domínio, HTTPS e Cloudflare

- Docroot = `public/` (quiosque em `/totem/?totem=CODIGO`, gestão em `/gestao/`, API em `/api/`). Produção: `https://totem.udlog.online`, com `TOTEM_URL_BASE=https://totem.udlog.online/totem/`.
- O quiosque precisa de HTTPS válido (`getUserMedia` exige contexto seguro).
- Cloudflare: `/gestao/*` deve **ficar sem cache**. Em produção foi observado `cf-cache-status: DYNAMIC` e `Cache-Control: no-store, private`, sem regra extra de bypass registrada; confirmar após qualquer mudança de regras do Cloudflare.
- O IP do cliente (`CF-Connecting-IP`) só é usado quando `REMOTE_ADDR` está em faixa oficial do Cloudflare.
- WAF/limite de corpo do Cloudflare/HostGator para POST de ~7 MB (anexo de OC): `PENDENTE DE CONFIRMAÇÃO`. O n8n anônimo recebe 406.
- Cookie `Secure` e `REMOTE_ADDR` real só são verificáveis em produção. `AuthServidor::requisicaoHttps` confia em `X-Forwarded-Proto` (pendência P57).

## 9. Jobs agendados (cron do cPanel)

São 5 jobs, todos somente PHP CLI (o script recusa execução fora do CLI), sem credencial no comando, caminho absoluto só no painel. Comando-modelo:

`/usr/local/bin/ea-php83 <INSTALACAO>/cron/<job>.php >> /home2/<CONTA_CPANEL>/logs/totem-cron.log 2>&1`

Fuso do servidor: -03. Os 5 jobs, incluindo `limpar-logs-gestao`, estão no crontab de produção e executando corretamente (confirmado pelo responsável do projeto em 2026-10-10). O cPanel injetou a linha `SHELL="/usr/local/cpanel/bin/jailshell"` no crontab; o crontab pode conter entradas de outros sistemas da mesma conta, que devem ser preservadas.

| Job | Horário | O que faz |
|---|---|---|
| `limpar-rate-limit-ocr.php` | `0 * * * *` | Apaga linhas de `tb_rate_limit_ocr` com mais de 24 h (lotes de 500). |
| `abandonar-atendimentos.php` | `15 3 * * *` | Atendimentos `em_andamento` sem atividade há 24 h ou mais passam a `cancelado`; fotos das notas vão para quarentena (`.del`). Limite 500 por execução. Nunca abandona ENVIANDO/ENVIADO/ENVIO_INDETERMINADO. Exige banco e `STORAGE_PATH`. |
| `limpar-notas-quarentena.php` | `30 3 * * *` | Apaga `nota_0[1-5].jpg.<id>.del` com mais de 24 h. Limite 500 por execução. Não depende do banco para limpar; o resumo em `tb_log_sistema` (`cron_resumo`/`cron_falhou`) usa o banco, com fallback para `error_log`. Exige `STORAGE_PATH`. |
| `limpar-anexos-ordem-coleta.php` | `55 3 * * *` | Apaga PDFs de OC INATIVA há mais de 15 dias, ou sem OC e com mais de 15 dias; OC ATIVA nunca. Usa o banco externo e `STORAGE_PATH`. |
| `limpar-logs-gestao.php` | `10 4 * * *` | Poda `tb_log_sistema` e `tb_gestao_auditoria` com retenção fixa de 90 dias (aceita `--dry-run`). Usa GET_LOCK; trilha `RETENCAO_EXECUTAR`. |

Notas:

- **Não existe cron de reenvio**: a fila de reenvio ao Talent foi removida; nunca agendar reenvio. Se existir entrada `reenviar-fila` no cPanel, removê-la.
- Código de saída `0` = sucesso; `1` = falha. Os logs são agregados, sem placa, CNPJ, número de OC ou caminho.
- Antes do primeiro disparo de `abandonar-atendimentos` em uma base nova, conferir a contagem de candidatos (pode cancelar muitos atendimentos de uma vez).
- Desabilitar um job: remover a entrada no cPanel; nenhum dado é corrompido.
- Agendamento e execução dos 5 jobs: confirmados (confirmado pelo responsável do projeto em 2026-10-10; crontab e execução OK).

## 10. Sequência segura de atualização (produção)

Cada passo em produção exige autorização explícita.

1. Conferir o `HEAD` do servidor e o diff a aplicar (`git fetch` e comparação); anotar contagens das tabelas que importam (só contagem).
2. Backup do banco afetado (mysqldump em pasta `700`, arquivo `600`, fora do docroot) e cópia do `.env` se for alterado.
3. Se houver mudança de estrutura: aplicar a migration **antes** do código quando o código novo depende dela, e depois do código quando o código antigo ainda depende da estrutura antiga. Hoje não há pasta de migrations; ver seção 5.
4. `git pull --ff-only` com `umask 0022` (nunca sob `umask 077`).
5. `php -l` com `ea-php83` nos PHP alterados.
6. Permissões: `find public -type f -perm 600` deve ficar **vazio** (idem para `app util cron tools sql`).
7. Smoke test sem POST real (seção 11).
8. Contagens antes = depois; `error_log` sem linhas novas; remover scripts temporários.
9. Para mudança de `public/totem/assets/*`: conferir o hash do arquivo servido contra o Git (os assets são versionados por `?v=filemtime`).

Deploys anteriores usaram sempre esse padrão (`git pull --ff-only`, `php -l`, smoke e contagens).

## 11. Smoke tests (sem POST real)

Nenhum smoke deve chamar `finalizar`, Talent real, vio.api.br real ou gravar dado de atendimento.

- `GET /gestao/login.php`: 200, `no-store`, `cf-cache-status: DYNAMIC`.
- Páginas da gestão sem sessão: 302 para `/gestao/login.php` com `Cache-Control: no-store, private`.
- Endpoints de ação da gestão por GET: 405.
- `GET /totem/` sem código: 404; `/cron/`, `/tools/`, `/storage/`, `/app/`, `/sql/`, `/vendor/`: 404; `/.env`: não 200.
- Quiosque com código válido: 200 e assets 200 (`app.js`, `app.css`, `impressao.js`).
- API de clientes com Bearer do totem: 200; sem token: 401. Rota autenticada sem token: 401; rota inexistente: 4xx; nunca 500.
- O token do totem usado no smoke não deve ser digitado em chat; leitura direta do banco em variável do shell e descarte em seguida.

## 12. Itens dependentes de HostGator, mini PC e ambiente físico

### HostGator

- cPanel Cron Jobs (`ea-php83`, jailshell), SSH sem root, Composer (instalação de dependências), MySQL 5.7.44, LiteSpeed LSAPI, AutoSSL, prefixo `<CONTA_CPANEL>_` nos bancos, limites do `php.ini` via cPanel.
- `display_errors=Off`: configurado e confirmado pelo responsável do projeto em 2026-10-10; é defesa complementar (o código não vaza exceção em resposta HTTP).
- Repasse do cabeçalho `Authorization` ao PHP na rota do n8n: `PENDENTE DE CONFIRMAÇÃO`.

### Mini PC (Windows)

- Serviço de impressão em `C:\udlog\servico-impressao-local` (Node >= 22.17.1; `npm install`; `config/config.json` a partir de `config.example.json`). Escuta só em `127.0.0.1`.
- Autostart como **tarefa de logon** do Windows (`scripts/instalar-tarefa-logon.ps1`; `UDLOG Servico Impressao Local (logon)`), sob o usuário do quiosque com login automático. O serviço do Windows (`node-windows`) foi descartado.
- `origensPermitidas` do `config.json` deve conter a origem do totem (produção: `https://totem.udlog.online`). Sem a origem correta o navegador bloqueia a chamada local (CORS/PNA).
- `token`, `impressorasPermitidas` e `timeoutMs` do `config.json` devem conferir com `IMPRESSAO_LOCAL_TOKEN` e `IMPRESSAO_FRONTEND_TIMEOUT_MS` do `.env` (sincronização manual; não há automática).
- Chrome em modo quiosque iniciado por `C:\udlog\iniciar-totem.bat` (pasta de inicialização). A URL do quiosque é `<TOTEM_URL_BASE>?totem=NOME-EMPRESA-HASH16`. Para trocar a URL: editar o `.bat` e reiniciar o PC. Flags do Chrome e conteúdo do `.bat` não estão no repositório.
- Impressora EPSON TM-T88VII com driver `Receipt6` na porta `TMUSB001`, papel "totem" 80x80 mm, área útil de 48 mm. Qual driver vale em produção (o README do serviço cita medição com `Receipt5`): `PENDENTE DE CONFIRMAÇÃO`.
- Scanner Netum SD-2000 como câmera UVC (NetumScan Pro não pode estar aberto). Permissão de câmera persistente para a origem do quiosque e `VideoCaptureAllowedUrls` (P36): `PENDENTE DE CONFIRMAÇÃO`.
- Testes físicos pendentes: falta de papel real (409 `sem_papel`), duas etiquetas em sequência (motorista e ajudante) e leitura de QR com o Netum.
- Monitor touch em retrato, login automático do Windows e rede do mini PC: ambiente físico.

## 13. Rollback

Não há procedimento único de rollback de código documentado em detalhe; o registrado é:

- Código: reverter para o commit anterior (`git` no servidor, com autorização) e repetir `php -l`, permissões e smoke. Reversão de código **não** desfaz alteração de estrutura do banco.
- Banco: restaurar o dump anterior à alteração; evitar reverter código que dependa da estrutura nova ou antiga sem alinhar as duas partes.
- Cron: remover a entrada no cPanel (faça backup do crontab antes de alterá-lo, em pasta fora do docroot).
- Chave do n8n: esvaziar `ORDEM_COLETA_ANEXO_API_KEY` (a rota passa a responder 503).
- Serviço de impressão: voltar `origensPermitidas` à lista vazia.
- Mantenha os backups de deploys fora do docroot, em pasta com permissão restrita (700 a pasta, 600 os arquivos); os que contêm tokens ou dados não devem ser apagados sem decisão do responsável.

## 14. Checklist pós-publicação

- [ ] `HEAD` do servidor igual ao commit publicado.
- [ ] `php -l` sem falhas nos PHP alterados.
- [ ] `find public -type f -perm 600` vazio; `.env` em `600`.
- [ ] Smoke da seção 11 com os resultados esperados.
- [ ] `error_log` (raiz do repositório e `public/api/error_log`) sem linhas novas.
- [ ] Contagens de tabelas iguais antes e depois (quando não há migração de dados).
- [ ] `GESTAO_HASH_SALT`, `TOTEM_URL_BASE` e `STORAGE_PATH` definidos e `STORAGE_PATH` gravável.
- [ ] Primeiro administrador criado (`tools/criar-admin.php`) e login funcionando em HTTPS.
- [ ] Totem(ns) cadastrado(s) pela tela Totens e URL do quiosque atualizada no mini PC.
- [ ] 5 crons presentes no cPanel e nenhum cron de reenvio.
- [ ] Cloudflare sem cache em `/gestao/*`.
- [ ] Serviço de impressão ativo no mini PC (tarefa de logon) e `/saude` do serviço respondendo localmente.
- [ ] Hash do `app.js` servido igual ao do Git, quando o front mudou.

## 15. Execução local (XAMPP / desenvolvimento)

Resumo; o detalhamento do ambiente de desenvolvimento não é objetivo deste guia.

- Windows com XAMPP (Apache + PHP 8.0.30) e MariaDB; banco de desenvolvimento `udlog_totem`.
- `composer install`; copiar `.env.example` para `.env` e preencher; aplicar `sql/schema.sql`; `STORAGE_PATH` em pasta fora de `public/` (`storage/` local é ignorado pelo Git).
- Em desenvolvimento sem HTTPS, definir `GESTAO_PERMITIR_HTTP=true` e usar `TOTEM_URL_BASE=http://localhost:8080/totem/`. Como o Apache aponta o docroot para `public/` na porta 8080: `PENDENTE DE CONFIRMAÇÃO` (não há vhost versionado).
- Criar o admin com `php tools/criar-admin.php`; cadastrar um totem pela tela Totens.
- Serviço de impressão: `cd servico-impressao-local`, `npm install`, `config/config.json` a partir de `config.example.json`, `npm start`; `npm test` não usa impressora física.
- Testes ficam em `tests/manual/` (sem PHPUnit): `php tests/manual/teste_NOME.php` e `node tests/manual/*.js`. As suites que gravam usam banco descartável `qa_*` criado a partir de `sql/schema.sql`. Não rodar teste que escreva em banco compartilhado ou real sem autorização.

## 16. Divergências entre fontes

1. **`docs/deploy-checklist.md` desatualizado quanto às migrations**: ainda manda aplicar migrations (013, 015, 016, 018, 019, 002/003 externas etc.) e cita `sql/migrations/`, que foram removidas em 2026-10-09. Vale `sql/schema.sql`.
2. **Checklist cita variáveis removidas**: a seção 1.Z manda preencher `VIO_API_BR_CACHE_TTL_DIAS`, `VIO_API_BR_CACHE_HMAC_KEY_V1`, `VIO_API_BR_CACHE_HMAC_VERSION` e `DOCUMENTO_QR_HMAC_KEY`, e o item 1 cita `IMPRESSORAS_PERMITIDAS`/`IMPRESSAO_TIMEOUT_MS` no `.env`; nenhuma tem consumidor no código.
3. **Versão do PHP web (divergência histórica, resolvida)**: o checklist registra `.htaccess` em `public_html` fixando `ea-php82`; o PHP web de produção é 8.3 (confirmado pelo responsável do projeto em 2026-10-10), igual ao do cron (`ea-php83`).
4. **`HEAD` do servidor (resolvido)**: registros antigos citavam `9d44513` e `4188fee`; em 2026-10-16 o servidor foi verificado em `82ad36d`, igual ao `origin/main` da época.
5. **`docs/` fora do Git**: `docs/` (incluindo o checklist) está no `.gitignore` e não vem em um clone. Este guia fica em `documentacao/`, versionado.
6. **README do serviço de impressão (resolvido)**: foi atualizado; a origem de produção consta como ativa, o driver do mini PC é `Receipt6` (o `Receipt5` é só histórico de outro modelo) e não há mais instrução com `Restart-Service`.
7. **Hospedagem compartilhada**: sem acesso root e sem processo persistente; os deploys usam SSH de usuário.
8. **`.env.example`**: o texto antigo citava MariaDB; produção é MySQL 5.7.44.

## 17. Pendências desta documentação

- Versão do Composer em produção (seção 3).
- Limites efetivos do PHP web, WAF e corpo de ~7 MB (seções 1 e 8).
- Driver real da impressora em produção, `VideoCaptureAllowedUrls`, flags do Chrome (seção 12).
- Vhost/porta 8080 do XAMPP (seção 15).
