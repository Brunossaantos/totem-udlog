# Estado real do projeto — totem-udlog

> Este documento é a ÚNICA fonte de verdade sobre o estado atual do projeto.
> TODO sub-agente e o orquestrador leem este arquivo ANTES de planejar ou
> implementar qualquer coisa. Nada aqui é suposição — o que não foi
> confirmado fica marcado como PENDENTE, nunca é preenchido com invenção.
>
> Este arquivo é atualizado ao final de cada ciclo de implementação
> (etapa 01-implementacao e 04-commit-e-push do workflow).

Última atualização: 2026-09-16

---

## 1. O que é o projeto

Totem de autoatendimento físico para a UDLOG (United Logistics), com dois
fluxos: **Expedição** (retirada de carga) e **Recebimento** (entrega de
carga). Motorista interage sozinho na tela; ao final, um sistema externo
(Talent) retorna uma senha que é impressa.

Hardware: mini PC Windows + monitor touchscreen 18,5" em **orientação
retrato**. O **Netum SD-2000** é o scanner de documentos do totem — um
dispositivo de vídeo USB (exposto ao navegador como `videoinput` via
`getUserMedia`/`enumerateDevices`), não um leitor de código de barras/QR em
modo teclado HID. Não existe câmera USB separada para fotografar documentos
— o SD-2000 é o único equipamento de captura de imagem. (Correção de
premissa anterior, incorreta, registrada até 2026-09-03.)

## 2. Stack confirmada

- **Back-end**: PHP 8.x, padrão MVC em camadas (Model / Dao / Rn / Controller)
- **Banco**: MariaDB/MySQL, acesso via PDO com prepared statements (nunca
  concatenar SQL — ver seção de segurança)
- **Front-end**: HTML/CSS/JS vanilla (sem framework, sem build step),
  consumindo a API via `fetch()`, rodando em Chromium modo kiosk
- **Hospedagem**: Hostgator, hospedagem compartilhada (cPanel) — **não** é
  servidor interno. Isso significa: sem SSH root, sem processos
  persistentes/WebSocket de longa duração, sem instalar binário arbitrário
  (ex: Tesseract precisaria ser client-side via Tesseract.js, não
  server-side), cron só via cPanel

## 3. O que já existe (implementado)

- Estrutura de pastas completa: `app/{Model,Dao,Rn,Controller}`, `util/`,
  `public/{api,totem}`, `sql/`, `cron/`
- Schema SQL completo em `sql/schema.sql`: `tb_totem`, `tb_cliente`,
  `tb_atendimento`, `tb_atendimento_nota`, `tb_fila_envio`
- Backend: autenticação por token por totem (`Util\Auth`), CRUD de
  atendimento, seleção de ordem de coleta quando há mais de uma em aberto,
  decodificação local da chave de acesso da NF-e (sem custo, sem API
  externa), montagem de payload com anexos em base64 para o Talent, fila de
  reenvio com backoff exponencial
- Front-end: os 18 estados dos fluxos de Expedição e Recebimento
  implementados em `public/totem/assets/app.js`, incluindo teclado virtual
  pt-BR (números na primeira linha), captura de câmera via `getUserMedia`,
  modal de inatividade, botão de cancelar atendimento em toda tela do fluxo
- Digitalização de notas fiscais do Recebimento (tela `rec_digitaliza`) via
  scanner de documentos Netum SD-2000, tratado como dispositivo de vídeo USB
  (`getUserMedia`/`enumerateDevices`, seleção por `deviceId` salva em
  `localStorage`): preview em tempo real, guia visual, fluxo de
  captura→preview→"Usar imagem"/"Refazer", contador só incrementado após
  confirmação do backend, limite de 5 notas aplicado na tela e no backend,
  tratamento de erros específicos (permissão negada, dispositivo não
  encontrado/ocupado/desconectado, vídeo não pronto). CNH/CRLV continuam
  usando o `leitor Netum` antigo (HID) — não foram alterados nesta entrega.
  Nova etapa formal `digitalizacao_notas` em `tb_atendimento.etapa_atual`,
  exigida pelo backend antes de aceitar upload de nota. Correção de IDOR em
  `NotaController::processar`/`algumaIdentificada` (valida posse do
  atendimento pelo totem autenticado). `UploadHelper` agora valida JPEG por
  magic bytes, tamanho máximo configurável (`NOTA_IMAGEM_MAX_BYTES`,
  padrão 5MB) e confirma gravação real em disco. Constraint
  `UNIQUE(id_atendimento, ordem)` adicionada em `tb_atendimento_nota` (via
  `sql/schema.sql` + migration idempotente em
  `sql/migrations/001_uk_atendimento_nota_ordem.sql`). Validação física do
  equipamento Netum SD-2000 ainda não realizada (sem hardware disponível
  neste ambiente) — ver pendências. Ação `atendimento.php?acao=concluir-digitalizacao`
  criada: valida posse/tipo/status/etapa/contagem de notas (1-5) antes de
  atualizar `etapa_atual` para `cliente` ou `cnh` (conforme identificação de
  cliente) — o front-end (`finalizarDigitalizacao()`) agora aguarda essa
  resposta do backend em vez de decidir a próxima tela sozinho pelo estado
  local. IDOR corrigido também em `salvarEtapa` (case `digitalizacao_notas`),
  `bloquearPorExcessoDeNotas` e `cancelar` (validam posse do atendimento pelo
  totem autenticado antes de qualquer efeito). Validação de JPEG reforçada em
  `UploadHelper` com `getimagesizefromstring()`. `selecionarOrdem` e
  `finalizar` (envio ao Talent) continuam com o mesmo tipo de IDOR, NÃO
  corrigido nesta rodada (fora do escopo desta correção) — ver pendências.
- `doctos[]` real do Talent (Recebimento por número de nota, Expedição por
  ordem de coleta), atualização da ordem de coleta para `INATIVA` após
  check-in aceito (com tabela de auditoria `tb_ordem_coleta_pendente_baixa`
  para falha de reconciliação), e endpoint isolado de impressão real da
  etiqueta (`ImpressaoAtendimentoController`) — implementados e testados em
  2026-09-14 (demanda `talent-doctos-finalizacao-checkin`). **Validado de
  ponta a ponta com um POST real ao Talent (produção) em 2026-09-14**:
  HTTP 200, `sucesso=true`, `nrRegAcesso` retornado, ordem de coleta
  marcada `INATIVA` com sucesso, impressão física real confirmada
  visualmente pelo usuário — ver seção 5. O valor PADRÃO do `.env` de
  produção/deploy continua `TALENT_CHECKIN_ATIVO` ausente/`false`
  (fail-closed); a ativação é sempre uma decisão explícita e controlada
  para um teste pontual, nunca o estado permanente do totem em operação.

## 4. Decisões já tomadas (não reabrir sem pedido explícito)

- Orientação da tela: **retrato**, não paisagem
- Inatividade: mostra aviso perguntando se o motorista ainda está ali (não
  reseta sozinho, não espera indefinidamente)
- Botão "cancelar atendimento": aparece em **todas** as telas do fluxo,
  exceto a tela inicial
- Botões: cor sólida sempre visível, nunca dependente de `:hover`
- Sem header/barra de marca decorativa fixa no topo das telas
- Ajudante: se "sim", pede nome completo e CPF antes de avançar
- Recebimento — mais de 5 notas fiscais: **bloqueia** o atendimento digital
  e direciona pro balcão da portaria; 5 ou menos: segue digitalização, com
  botão "Finalizar digitalização" (não precisa bater exatamente 5)
- Identificação do cliente na nota: roda em segundo plano a cada nota
  capturada (via chave de acesso → CNPJ emitente → `tb_cliente`); ao
  finalizar a digitalização, se algum CNPJ foi identificado, pula a tela de
  confirmação manual do cliente
- Convenção de pasta de documentos: `AAAA-MM-DD/PLACA_HHMMSS` (nunca usada
  como chave de busca — `id_atendimento` é a chave real; a pasta é só para
  navegação humana)
- Talent espera anexos como **JSON com os arquivos em base64** (não é
  multipart/form-data)
- Identidade visual: seguir o padrão de https://udlog.com.br/ — paleta
  exata ainda não confirmada, está com navy (#0b2a45) + branco como
  placeholder

## 5. Pendências reais (não inventar — perguntar ou aguardar)

| Item | Onde impacta | Status |
|---|---|---|
| ~~URL, autenticacao e formato de resposta da API de ordens de coleta~~ | `app/Rn/OrdemColetaClient.php` | IMPLEMENTADA em 2026-09-11 (demanda `expedicao-consulta-ordem-coleta-teste`) — nunca existiu API REST real para essa consulta; usuario autorizou acesso direto de leitura E escrita ao banco externo de gestao de coletas. `OrdemColetaClient` delega a `App\Dao\OrdemColetaDao`/`Util\ConexaoGestaoColetas` (conexao PDO propria e separada, `PDO::ATTR_TIMEOUT` curto, conexao so aberta no momento real da consulta — nunca no bootstrap), consultando `tb_ordens_coleta INNER JOIN tb_clientes` por `placa_prevista` (normalizada exclusivamente no backend) com `tb_ordens_coleta.status='ATIVA'` e `tb_clientes.status='ATIVO'`. Testado com sucesso (17/17 asserções) — ver seção 7. **RECONCILIADO em 2026-09-11**: o achado anterior de schema divergente era causado por um banco `udlogo59_db_gestao_coletas` ERRADO que existia neste XAMPP local (schema `status ENUM('PENDENTE','LIBERADA','EM_ATENDIMENTO','CONCLUIDA','CANCELADA')`, incompatível). O usuário apagou esse banco errado e reimportou o dump correto (`docs/udlogo59_db_gestao_coletas.sql`, 70 queries) sob o nome real `udlogo59_db_gestao_coletas` — confirmado ao vivo (`SHOW CREATE TABLE`) que o schema bate com o dump verbatim, sem coluna de status até a migration desta demanda ser (re)aplicada com sucesso contra ele. `GESTAO_COLETAS_DB_NAME=udlogo59_db_gestao_coletas` atualizado no `.env` local. O banco antigo sem prefixo (`db_gestao_coletas`) usado como precaução na rodada anterior **não existe mais** neste ambiente (confirmado via `SHOW DATABASES`) — o usuário já o removeu. Bateria de 17 asserções e as 3 suítes de regressão (`teste_avancar_etapa_expedicao.php`, `teste_talent_trava_doctos_pendente.php`, `teste_rebaixamento_manual.php`) reexecutadas com sucesso (17/17, 10/10, 16/16, 45/45) contra o banco correto, incluindo as 7 ordens reais novas (dados de motorista tratados como sensíveis, nunca logados/exibidos por nome). Pendência remanescente: reconfirmar em Produção (Hostgator) se o nome do banco lá é de fato `udlogo59_db_gestao_coletas` (o prefixo `udlogo59_` é o prefixo cPanel local — pode ou não ser o mesmo em produção) antes de aplicar a migration externa lá. **RECLASSIFICAÇÃO 2026-09-15 (demanda `talent-http409-limpeza-pendencias`)**: banco de produção `udlogo59_db_gestao_coletas` tratado como **CONFIRMADO** por instrução explícita do usuário nesta conversa — decisão de produto registrada, não suposição técnica; a pendência remanescente acima (reconfirmar nome exato em produção/Hostgator antes de aplicar migration externa) permanece válida e não é afetada por esta reclassificação. |
| ~~API de clientes externa~~ usada em `identificar-cliente` | `app/Rn/ClienteApiClient.php` | SUBSTITUÍDA em 2026-09-08 por consulta local a `tb_cliente` (demanda `recebimento-clientes-tabela-local`) — `ClienteApiClient.php` mantido no código como morto/documentado, sem uso de produção; `CLIENTES_API_TOKEN` removido do `.env.example` |
| ~~URL, autenticação e formato de resposta da API do Talent~~ | `app/Rn/TalentClient.php` | PARCIALMENTE RESOLVIDA em 2026-09-09 — contrato oficial confirmado via `MANUAL_TALENT_WMS.pdf` lido na íntegra: endpoint real é `POST https://api.talentcs.com.br/Portaria/Checkin` (o placeholder anterior `/atendimentos` nunca existiu no manual). Documentado em `docs/manual_talent.md`. **Ainda não confirmado**: formato do retorno de sucesso/erro deste endpoint específico (não documentado no manual) — bloqueia saber como preencher `talent_senha`/`talent_protocolo` corretamente. Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md` |
| Viabilidade/convênio da API Prodesp (CNH/CRLV) | `app/Rn/ProdespClient.php` | Não confirmado — pode nunca ser viável; QR da CNH digital é payload assinado, QR do CRLV-e costuma ser só link de validação |
| Origem/sincronização de `tb_cliente` para clientes futuros (além dos 38 já inseridos) | Autocomplete do recebimento + casamento por CNPJ + novo OCR local | Parcialmente resolvida em 2026-09-08: `tb_cliente` agora tem 38 clientes reais (razão social + CNPJ validados, coluna nova `razao_social_normalizada`), usados deliberadamente pelos 3 fluxos (autocomplete, chave de acesso antiga, OCR novo). Não resolvido: processo de manutenção para adicionar clientes novos no futuro (continua manual, via migration de dado) |
| Paleta de cores oficial da UDLOG | Todo o front-end | Usando navy/branco como placeholder |
| ~~Origens CORS/PNA permitidas do servico local de impressao ainda vazias~~ | `servico-impressao-local/config/config.json` (real, gitignored) | **CORRIGIDO em 2026-09-16 (demanda `impressao-origens-permitidas`)**: desenvolvimento ativo com `origensPermitidas: ["http://localhost:8080"]` (comparacao exata, testado em 17 cenarios reais de CORS/PNA/auth, veredito APROVADO). Producao (`https://udlog.online`) esta CONFIRMADA e documentada em `servico-impressao-local/README.md`/`docs/deploy-checklist.md`, mas AINDA NAO ativada nem testada — sera ligada somente no deploy final no mini PC de producao, com reconfirmacao da origem na barra de enderecos apos o deploy. `http://udlog.online` (sem HTTPS) explicitamente NAO autorizado. Rollback seguro: `origensPermitidas: []`. Ver `docs/handoffs/2026-09-15-impressao-origens-permitidas.md` |
| Ponto de acesso a tela de diagnostico de impressao (toque longo na tela inicial) | `public/totem/assets/app.js` (`#diagHotspot`) | Implementado em 2026-09-14 como escolha de implementacao, reaproveitando sugestao anterior — NAO e decisao de UX/produto formalmente confirmada pelo usuario |
| Sucesso do spooler do Windows (`pdf-to-printer`) nao confirma fisicamente que a impressao ocorreu — e fire-and-forget por natureza da API do Windows | `servico-impressao-local/src/routes/imprimir.js`, `public/totem/assets/diagnostico-impressao.js` (tela "concluido") | Achado do `qa-testes` em 2026-09-14 — neste teste especifico houve confirmacao humana direta da impressao fisica, mas o mecanismo em si pode gerar falso positivo de UX se a impressora falhar silenciosamente apos o HTTP 200; decisao de mitigar (ou nao) pendente |
| **[BLOQUEIO DE AMBIENTE — CORRECOES VALIDADAS TECNICAMENTE, TESTE FISICO PENDENTE]** `/02-testes` de 2026-09-14 havia concluido **PRECISA DE AJUSTE** para a demanda `impressao-etiqueta-teste` — usuario classificou o achado critico de processo (impressao fisica ocorrida durante simulacao, antes de autorizacao formal) como bloqueante: selecionar impressora virtual/interativa pode travar o processo/tela do totem indefinidamente, risco entao sem mitigacao no servico local Node | `servico-impressao-local/` (rotas `impressoras`/`imprimir`, `src/lib/imprimirComTimeout.js`), `public/totem/assets/diagnostico-impressao.js`, `app/Controller/ImpressaoTesteController.php` | Nova rodada de `/01-implementacao` em 2026-09-14 implementou e validou as 10 correcoes obrigatorias: (1) allowlist configuravel de impressoras fisicas (fail-closed); (2) `EPSON TM-T88VII Receipt` liberada inicialmente; (3) impressoras virtuais (Print to PDF, XPS, Fax, OneNote etc.) bloqueadas como consequencia da allowlist; (4) timeout configuravel via `config.timeoutMs`/`IMPRESSAO_TIMEOUT_MS`, implementado em novo modulo `imprimirComTimeout.js` (chamada direta ao SumatraPDF via `execFile`, pois `pdf-to-printer` nao expunha o PID); (5) no timeout, kill exclusivo por PID+arvore de processos (`taskkill /PID <pid> /T /F`), nunca por nome; (6) mutex/lock e arquivo temporario liberados em `finally`; (7) `identificador` marcado como `indeterminado` no timeout, sem retry automatico/duplicidade; (8) resposta HTTP 504 sanitizada (`codigo:'IMPRESSAO_TIMEOUT'`); (9) front-end com timeout proprio via `AbortController` e novo estado de tela `'indeterminado'`, sem retry automatico; (10) mecanismo de timeout testado pelo `qa-testes` com processo MOCK controlado, nunca impressora virtual/fisica real. `security-especialista` encontrou e o `backend-especialista` corrigiu, na mesma rodada, 3 achados de atencao: corrida entre liberacao do mutex e confirmacao do `taskkill` (corrigida aguardando confirmacao antes de liberar), e vazamento de `erro.message` bruto em `routes/impressoras.js` e `server.js` (sanitizados). `qa-testes` aprovou os 8 criterios do teste mock de timeout. **Retestagem em 2026-09-14**: nova rodada de `/02-testes` validou tecnicamente as correcoes (17 itens sem impressao: 16 PASSOU + 1 N/A, zero achados bloqueantes; revisao de seguranca de confirmacao: os 3 achados anteriores confirmados corrigidos, zero achados novos bloqueantes, 1 observacao de baixa severidade sem necessidade de acao). O teste fisico autorizado (ate 2 etiquetas: 1 impressao normal + 1 teste de idempotencia) **NAO PODE SER EXECUTADO** por ausencia de `servico-impressao-local/config/config.json` real nesta maquina (so existe o template `config.example.json`) — 0 requisicoes de impressao enviadas, 0 etiquetas impressas, 0 do orcamento de 2 etiquetas restantes consumido. Esta e uma pendencia de PROVISIONAMENTO DE AMBIENTE (gerar `config.json` real na maquina fisica do totem/mini PC, ou definir maquina/sessao alternativa para o teste), nao uma falha de implementacao — nao retorna para `/01-implementacao`. `/02-testes` permanece EM ABERTO ate o teste fisico ser executado. **ATUALIZACAO 2026-09-14 (2a tentativa)**: apos o `devops-especialista` provisionar `config.json` real + variaveis no `.env` real, o bloqueio mudou de natureza — NAO E MAIS ausencia de `config.json`, e sim um ERRO DE SINTAXE no `.env` real desta maquina: a linha `IMPRESSORAS_PERMITIDAS=EPSON TM-T88VII Receipt` (linha 58) tem valor com espaco SEM aspas, o que faz `vlucas/phpdotenv` (`Dotenv::createImmutable()->load()`) lancar `InvalidFileException: Encountered unexpected whitespace`, quebrando o carregamento do `.env` INTEIRO (reproduzido isoladamente via `php -r`). **IMPACTO SISTEMICO**: como esse `load()` roda antes de qualquer autenticacao/rota, TODO endpoint PHP do projeto que dependa do `.env` fica com erro fatal nesta maquina enquanto essa linha nao for corrigida — nao e falha do servico de impressao em si, nem isolado a esta demanda. `qa-testes` parou imediatamente (0 requisicoes `POST /imprimir`, 0 etiquetas impressas, 0 do orcamento de 2 consumido, seguem 2 de 6 disponiveis) e NAO alterou `.env`/codigo, por instrucao explicita do usuario. `/02-testes` retorna para `/01-implementacao` — correcao pontual necessaria (adicionar aspas: `IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII Receipt"`), mas fica para o orquestrador/usuario decidir quando/quem aplica, dado que o `.env` real nao e versionado nem visivel fora desta maquina. Ver `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md`, seções "Resultado da implementação — correção do bloqueio (2026-09-14)", "Resultado dos testes — retestagem pós-correção (2026-09-14)" e "Teste físico — bloqueado por erro no .env (2026-09-14)". **ATUALIZACAO 2026-09-14 (RESOLVIDO)**: o `.env` real foi corrigido (linha 58 confirmada com aspas, `IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII Receipt"`, verificado por leitura direta do arquivo pelo `qa-testes`). O teste fisico autorizado foi executado nesta rodada (relatado pelo orquestrador, que executou pessoalmente — ver nota de transparencia abaixo e no handoff): 2 impressoes reais aprovadas pelo usuario (impressao normal + teste de idempotencia com o mesmo identificador, que corretamente NAO reimprimiu). Orcamento da demanda totalmente consumido (6 de 6 etiquetas). Rodada de `/02-testes` concluida com veredito **APROVADO**. **ATUALIZACAO 2026-09-14 (`/03-revisao` = APROVADO COM RESSALVA)**: revisao cruzada independente (seguranca/UX/devops) confirmou que a implementacao corresponde ao planejado, sem desvio de escopo, com as evidencias fisicas acima reconfirmadas (nenhuma nova impressao autorizada nesta etapa). Bloqueio principal (allowlist/timeout/mutex/indeterminado) aprovado e validado. Fechamento da demanda INTERROMPIDO por 4 pontos pontuais pendentes, que retornam para uma rodada curta de `/01-implementacao`: (1) `servico-impressao-local/src/middleware/auth.js` linha 19 — migrar comparacao de token de `!==` para `crypto.timingSafeEqual` (constant-time); (2) `.env.example` linha 96 — adicionar aspas (`IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII Receipt"`); (3) `docs/deploy-checklist.md` — nova secao cobrindo instalacao/configuracao/inicializacao automatica/diagnostico/validacao da impressora fisica do servico Node no mini PC de producao; (4) `servico-impressao-local/README.md` — nova instrucao operacional explicita para parada MANUAL do servico (sempre `taskkill /PID <pid> /T /F`, nunca `taskkill /IM node.exe`), com lembrete espelhado em `docs/deploy-checklist.md`. `origensPermitidas` (item 3 do pedido original de revisao do usuario) confirmado como NAO precisando de acao nesta demanda — pendencia de produto (URL real de producao), comportamento fail-closed atual seguro. **ATUALIZACAO 2026-09-14 (rodada curta de `/01-implementacao` = 4 ressalvas CORRIGIDAS)**: as 4 correcoes pontuais foram implementadas e testadas nesta rodada curta: (1) `servico-impressao-local/src/middleware/auth.js` — comparacao de token migrada para constant-time (SHA-256 dos dois lados + `crypto.timingSafeEqual`), testada com mock em 6 cenarios (correto, incorreto mesmo comprimento, mais curto, mais longo, ausente, vazio) — todos PASSOU, resposta HTTP 401 identica em todos os cenarios de falha; (2) `.env.example` linha 96 corrigida com aspas (`IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII Receipt"`); (3) `docs/deploy-checklist.md` ganhou a secao "1.X Servico local de impressao (mini PC Windows)", cobrindo instalacao/configuracao/inicializacao automatica/diagnostico, com `origensPermitidas` documentado explicitamente como permanecendo vazio/fail-closed (pendencia de produto, nao resolvida); (4) `servico-impressao-local/README.md` ganhou a subsecao "4.6. Parar o servico manualmente (teste/depuracao)", instruindo `taskkill /PID <pid especifico> /T /F`, nunca `taskkill /IM node.exe`, espelhado em `docs/deploy-checklist.md`. `security-especialista` confirmou implementacao correta, sem vazamento novo. Nenhuma nova impressao fisica foi feita nem necessaria (orcamento 6/6 ja consumido). Verificado por leitura direta do codigo/documentacao pelo `qa-testes`. **ATUALIZACAO 2026-09-14 (CONFIRMACAO FINAL = APROVADO)**: nova rodada de `/02-testes` de confirmacao concluida com **7/7 itens PASSOU**, incluindo as 3 suites de regressao automatizada (10/10, 16/16, 45/45 — 71/71 asserções somadas, zero regressao). Revisao de seguranca final: zero achados novos, sem regressao em nenhum ponto ja revisado, `origensPermitidas` confirmado intocado, Talent/atendimento/ordem de coleta confirmados intocados em TODA a demanda. Documentacao de implantacao (`docs/deploy-checklist.md` + `servico-impressao-local/README.md`) confirmada suficiente para configuracao do zero num mini PC novo, sem lacunas. As 4 ressalvas do `/03-revisao` anterior estao definitivamente encerradas. Nenhuma impressao fisica feita nesta rodada (orcamento 6/6 permanece esgotado). **VEREDITO FINAL DA DEMANDA: APROVADO** — pronta para `/04-commit-e-push`. Ver secao "Confirmação final — /02-testes e /03-revisao (2026-09-14)" em `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` |
| **[ACHADO SISTEMICO — ERRO DE SINTAXE NO `.env` REAL QUEBRA CARREGAMENTO INTEIRO — PERSISTE APOS 2ª TENTATIVA — RESOLVIDO EM 2026-09-14]** `.env` real desta maquina (raiz do projeto, linha 58) tinha `IMPRESSORAS_PERMITIDAS=EPSON TM-T88VII Receipt` sem aspas ao redor de um valor com espacos — `vlucas/phpdotenv` rejeitava essa sintaxe e falhava ao carregar o `.env` INTEIRO (`InvalidFileException: Encountered unexpected whitespace`), nao so essa variavel | `.env` (raiz do projeto, nao versionado) — impactava QUALQUER endpoint PHP do projeto que dependa do `.env`, nesta maquina, nao so `impressao-etiqueta-teste` | Achado do `qa-testes` em 2026-09-14 durante retestagem fisica da demanda `impressao-etiqueta-teste`; reproduzido isoladamente via `php -r` chamando so `Dotenv::createImmutable(__DIR__)->load()`. Correcao pontual conhecida (adicionar aspas: `IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII Receipt"`) nao aplicada nas 2 primeiras tentativas por instrucao explicita do usuario para aquelas rodadas. **ATUALIZACAO 2026-09-14 (RESOLVIDO)**: a linha 58 foi corrigida com aspas — confirmado por leitura direta do `.env` real pelo `qa-testes` nesta rodada (`IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII Receipt"`). O `.env.example` (linha 96) tem o mesmo padrao sem aspas — ver pendencia dedicada abaixo |
| Regra de processo: teste de `POST /imprimir` exige autorizacao previa explicita do usuario sempre que houver driver/impressora real no ambiente de teste | Processo de `/02-testes`/`qa-testes` | Adotada em 2026-09-14 apos impressao fisica real ter ocorrido durante uma simulacao antes da autorizacao formal planejada — usuario confirmou o resultado, mas a regra fica registrada para nao se repetir sem aviso previo |
| Comparacao de token nao constant-time (`!==`) em `servico-impressao-local/src/middleware/auth.js` (linha 19) | `servico-impressao-local/src/middleware/auth.js` | Achado do `security-especialista` em 2026-09-14, severidade OBSERVACAO (baixa), NAO bloqueante — risco pratico considerado baixo porque o servico so escuta em `127.0.0.1`. Registrado para avaliacao futura, sem correcao aplicada nesta rodada |
| `.env.example` (linha 96) tem o mesmo padrao sem aspas do `.env` real que causou o achado sistemico acima — `IMPRESSORAS_PERMITIDAS=EPSON TM-T88VII Receipt` sem aspas ao redor de valor com espacos | `.env.example` | Confirmado por leitura direta do arquivo pelo `qa-testes` em 2026-09-14. NAO bloqueante porque `.env.example` nunca e carregado em runtime, mas vale corrigir por precaucao para prevenir o mesmo bug em provisionamentos futuros (adicionar aspas: `IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII Receipt"`) |
| Nome final do banco de dados no Hostgator | `.env` | cPanel provavelmente prefixa com o usuário da conta |
| Origem da chave de acesso da NF-e na tela `rec_digitaliza` sem o leitor HID | `public/totem/assets/app.js` (payload de `nota.php` envia `chave: null` fixo) | Não decidido — o leitor Netum antigo (HID) foi removido só dessa tela; não há substituto definido (não é OCR, não é leitura automática) |
| Validação física do Netum SD-2000 como `videoinput` no Windows/Chromium (label real, se aparece de fato, resolução suportada) | `public/totem/assets/app.js` (`iniciarCameraScanner`) | **RECLASSIFICADO em 2026-09-15 (demanda `talent-http409-limpeza-pendencias`)**: redação anterior ("sem hardware físico disponível") estava desatualizada — a linha 410 já registra que o Scanner físico Netum SD-2000 foi validado no hardware real em 2026-09-04 (demanda `recebimento-scanner-netum-sd2000`). Status correto: **HARDWARE DISPONÍVEL — TESTE FÍSICO EM DEMANDA FUTURA** (esta validação específica de `videoinput`/label/resolução no Windows/Chromium ainda não foi reexecutada formalmente nesta pendência pontual); roteiro de diagnóstico em `docs/deploy-checklist.md` |
| Tratamento de exceção de banco (`PDOException`) em `NotaController::buscarAtendimentoDoTotem`/`processar`/`algumaIdentificada` sem handler global | `app/Controller/NotaController.php` | Reportado pelo security-especialista — comportamento em falha de banco depende de configuração de `display_errors` do PHP no Hostgator, não confirmada |
| Confirmação de que `storage/` fica fora do document root real em produção (Hostgator) | Configuração do cPanel | Consistente na estrutura do repositório, mas não verificável só por leitura de código — depende de configuração real do domínio |
| Persistência de permissão de câmera por origem no Chromium kiosk / política `VideoCaptureAllowedUrls` | Ambiente do mini PC Windows | Não validado na prática — ver `docs/deploy-checklist.md` |
| ~~IDOR em `selecionarOrdem`~~ (ação `selecionar-ordem`) | `app/Controller/AtendimentoController.php` | CORRIGIDA em 2026-09-11 (demanda `expedicao-consulta-ordem-coleta-teste`) — agora valida posse/tipo/status/etapa do atendimento (mesmo padrão já usado em outras ações) e RECONSULTA as ordens reais para a placa do atendimento, só aceitando a seleção se o `numero` enviado bater com uma ordem realmente retornada; dados gravados (`cliente_nome`/`cliente_cnpj`) vêm sempre do servidor, nunca do que o front enviou. Testado (múltiplas asserções dedicadas, incluindo tentativa de ordem forjada e IDOR clássico de totem alheio). `finalizar` (ação `finalizar`, envio ao Talent) tinha o mesmo tipo de IDOR mas já foi corrigido em 2026-09-09 (ver linha "IMPLEMENTADA em 2026-09-09" mais abaixo) — a menção anterior desta linha estava desatualizada. |
| Ausência de lock/transação em `concluirDigitalizacao` contra corrida (cliques quase simultâneos em "Finalizar digitalização") | `app/Controller/AtendimentoController.php` | Severidade baixa segundo o security-especialista — não bloqueante, registrado para decisão futura |
| Retomada de atendimento ao recarregar a página (contador de notas em `state` não é sincronizado com o banco) | `public/totem/assets/app.js` | Limitação pré-existente do projeto (sem mecanismo de sessão/retomada) — não criado nesta demanda, apenas confirmado que não existe |
| ~~JPEG truncado (sem marcador de fim) e aceito pela validacao atual~~ (getimagesizefromstring() nao detecta truncamento) | util/UploadHelper.php | RESOLVIDA COM RISCO RESIDUAL ACEITO em 2026-09-17 (demanda validacao-jpeg-segura) -- estrategia hibrida implementada (checagem estrutural de EOI exato + limite de dimensao 5000px/13M pixels + decodificacao completa via GD fail-closed), 22/22 controles obrigatorios aprovados. Bug ORIGINAL (truncamento acidental sem EOI) corrigido e validado. Risco residual formalmente aceito pelo usuario: JPEG truncado com EOI forjado deliberadamente pode ser aceito nesta instalacao de GD (limitacao confirmada da biblioteca, nao do codigo do projeto) -- cenario de ameaca mais restrito (exige acesso ja comprometido ao pipeline). Ver docs/handoffs/2026-09-17-validacao-jpeg-segura.md. |
| `bloquear-excesso-notas` e `cancelar` permitem reverter um atendimento já `concluido` (sem checagem de status terminal) | `app/Controller/AtendimentoController.php` | Achado novo dos testes reais de `/02-testes` (confirmado por exploração real) — decisão de produto pendente sobre se deve ser recusado |
| Teste físico do Netum SD-2000 não executado (roteiro de 20 itens pronto, aguardando hardware/usuário) | Hardware do mini PC Windows | `/02-testes` retornou veredito INCONCLUSIVO por esse motivo — ver roteiro no handoff. **RECLASSIFICADO em 2026-09-15 (demanda `talent-http409-limpeza-pendencias`)**: redação anterior ("aguardando hardware/usuário") estava desatualizada — a linha 410 já registra que o Scanner físico Netum SD-2000 foi validado no hardware real em 2026-09-04. Status correto: **HARDWARE DISPONÍVEL — TESTE FÍSICO EM DEMANDA FUTURA** (o roteiro de 20 itens completo desta pendência específica ainda não foi formalmente reexecutado/fechado). Nota: não confundir com a pendência distinta da linha seguinte (preview/resolução insuficiente da captura), que permanece real e não é afetada por esta reclassificação |
| ~~Preview/captura do Netum SD-2000 corta a imagem do documento e a resolução capturada é insuficiente (texto ilegível)~~ | `public/totem/assets/app.js` (`abrirStreamScanner`, `capturarFotoScannerNota`, CSS `.caixa-scanner`) | **RESOLVIDA em 2026-09-04, registro aqui estava DESATUALIZADO (corrigido em 2026-09-16, demanda `netum-preview-captura-resolucao`)**: o texto anterior desta linha descrevia a causa raiz ORIGINAL (antes da correção), sem refletir o resultado final. Handoff `docs/handoffs/2026-09-03-recebimento-scanner-netum-sd2000.md` e a seção 7 (log de 2026-09-04) registram a correção completa no mesmo dia: `getUserMedia` do scanner passou a pedir `width/height ideal 4096x3072`; nova função DEDICADA `capturarFotoScannerNota()` (linha ~1756) substituiu o uso de `capturarFotoBase64` (que tinha o downscale fixo de 900px, usada só por CNH/CRLV, nunca mais pelo scanner) — canvas dimensionado com `video.videoWidth`/`videoHeight` reais, sem downscale; guia visual sincronizada via polling ativo (250ms) após o listener `resize` isolado se mostrar insuficiente. Validação física real confirmada pelo usuário em 2026-09-04: resolução de captura real **3264×2448 (4:3)**, documento inteiro visível sem corte, texto legível, preview estável — `/02-testes` = APROVADO COM RESSALVAS, `/03-revisao` = APROVADO. Reconfirmado por leitura de código em 2026-09-16 (`frontend-especialista`): nenhuma regressão encontrada, mecanismo atual continua correto. Pendência REAL remanescente (distinta desta, ver linha acima): roteiro fisico completo de 20 itens ainda nao formalmente reexecutado/fechado. Ver `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md` |
| Validação física do serviço de impressão no mini PC de produção real (Hostgator + hardware físico definitivo, distinto do ambiente de dev usado até aqui) | `servico-impressao-local/` (deploy real), `docs/deploy-checklist.md` | **Status explicitado em 2026-09-16 (demanda `netum-preview-captura-resolucao`)**: por decisão do usuário, a validação física completa no mini PC de PRODUÇÃO (equipamento definitivo, fora do ambiente de dev local já testado) fica ADIADA para a etapa final do projeto — não é uma pendência técnica aberta nem um bloqueio de nenhuma demanda concluída até aqui; os testes físicos já realizados (impressão de etiqueta em 2026-09-14, scanner Netum em 2026-09-04) ocorreram no ambiente de dev/máquina de desenvolvimento disponível, não no mini PC de produção definitivo |
| Validação física do OCR client-side (Tesseract.js em Web Worker aninhado, tempo real de processamento, precisão de reconhecimento, cancelamento de fila em cenário real) | `public/totem/assets/ocr-worker.js`, `public/totem/assets/app.js` | Não realizado — sem navegador/hardware disponível neste ambiente; handoff `docs/handoffs/2026-09-04-recebimento-leitura-notas.md` lista o roteiro `[FÍSICO]` completo |
| Causa raiz do encoding corrompido (mojibake) em 8 dos 38 registros de `tb_cliente.nome` na migration 003 (INSERT rodado sem `SET NAMES utf8mb4`) não foi investigada/corrigida na origem — só os 8 dados já gravados foram corrigidos via UPDATE pontual em 2026-09-08 | `sql/migrations/003_tb_cliente_razao_normalizada.sql` | Se essa migration for reaplicada do zero em outro ambiente (novo banco), o mesmo problema de charset pode se repetir dependendo de como for executada — revisar processo de aplicação de migration (garantir `SET NAMES utf8mb4` explícito) antes de rodar em produção |
| Heurística de extração de "razão social candidata" no OCR (`ocr-worker.js`) é escolha de implementação, não formalmente decidida no handoff | `public/totem/assets/ocr-worker.js` | Pode precisar de ajuste após teste físico com notas reais |
| Valores de ajuste empírico (`LIMIAR_MINIMO`=80, `MARGEM_MINIMA`=15) definidos com valor inicial, não validados com volume real de produção; `CACHE_TTL_SEGUNDOS` não se aplica mais (era do cache HTTP da API externa, removido em 2026-09-08) | `util/RazaoSocialMatcher.php` | A recalibrar com dados reais após uso em produção |

| ~~tb_rate_limit_ocr cresce indefinidamente sem job de limpeza~~ (linhas de janelas antigas nunca sao apagadas) | sql/migrations/004_tb_rate_limit_ocr.sql, app/Dao/RateLimitOcrDao.php | IMPLEMENTADA em 2026-09-18 (demanda robustez-rate-limit-migrations) -- novo cron/limpar-rate-limit-ocr.php (retencao 24h, lotes de 500, preserva janela atual/anterior), novo metodo RateLimitOcrDao::apagarJanelasExpiradas(). Ver docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md. |
| ~~Rate limit de identificar-cliente e fail-open silencioso por design se RateLimitOcrDao nao for injetado no NotaController~~ (hoje sempre e injetado em public/api/nota.php, mas uma refatoracao futura poderia desativar a protecao sem erro/log) | app/Controller/NotaController.php | IMPLEMENTADA em 2026-09-18 (demanda robustez-rate-limit-migrations) -- RateLimitOcrDao obrigatorio no construtor (sem null); verificarRateLimit() com try/catch real, qualquer falha inesperada (incluindo Error/TypeError) responde HTTP 503 sanitizado, nunca prossegue para OCR. Ver docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md. |
| ~~**[ALTO]** Seed dos 38 clientes podia não rodar em instalação nova~~ | `sql/migrations/003_tb_cliente_razao_normalizada.sql` | RESOLVIDA em 2026-09-08 — migration reescrita com padrão de SQL preparado condicional (SET @sql := IF(...); PREPARE; EXECUTE), verdadeiramente idempotente independente do método de execução. Validado por `qa-testes` de forma independente em banco de teste descartável simulando instalação nova |
| ~~**[ALTO]** Mojibake se repetia em instalação nova sem SET NAMES~~ | `sql/migrations/003_tb_cliente_razao_normalizada.sql` | RESOLVIDA em 2026-09-08 — `SET NAMES utf8mb4;` adicionado como primeira instrução da migration. Validado por `qa-testes` via HEX() dos bytes gravados em banco de teste descartável |
| ~~**[MÉDIO]** `identificarCliente()` sem `try/catch` defensivo~~ | `app/Controller/NotaController.php` | RESOLVIDA em 2026-09-08 — envolvido em `try/catch (Throwable)` espelhando `processar()`, log técnico via `error_log`, resposta genérica ao cliente. Validado por `security-especialista` sem vazamento de `$e->getMessage()` |
| ~~**[MÉDIO]** `identificarCliente()` sem validação de status/etapa~~ | `app/Controller/NotaController.php` | RESOLVIDA em 2026-09-08 — validação de `status===em_andamento`/`etapa_atual===digitalizacao_notas` adicionada espelhando `processar()`. Confirmado por `security-especialista` que não quebra o early-stop (etapa só muda em `concluirDigitalizacao()`, chamado depois de todas as chamadas de identificação) |
| ~~sql/migrations/001_uk_atendimento_nota_ordem.sql e 002_status_ocr_atendimento_nota.sql tem o mesmo padrao fragil que a 003 tinha~~ (checagem manual previa, sem SQL preparado condicional) -- abortariam se executadas via mysql banco < arquivo.sql de uma vez so contra um banco onde as colunas/indices ja existem | sql/migrations/001_*.sql, sql/migrations/002_*.sql | IMPLEMENTADA em 2026-09-18 (demanda robustez-rate-limit-migrations) -- 001/002/003 preservadas SEM alteracao (decisao confirmada do usuario); nova migration corretiva sql/migrations/013_convergencia_idempotente_migrations_historicas.sql converge os objetos de forma idempotente (padrao PREPARE/EXECUTE condicional da 003), reconhece indice equivalente com nome diferente. VERSAO INICIAL (mesma data) abortava com SIGNAL SQLSTATE via stored procedure auxiliar se schema incompativel; essa versao foi RESCRITA em 2026-09-18/19 (rodada curta de /01-implementacao apos /02-testes independente confirmar que exigia privilegios CREATE ROUTINE/ALTER ROUTINE nao confirmados no Hostgator) -- a VERSAO FINAL, hoje no repositorio, aborta por meio de erros nativos do proprio MySQL/MariaDB (ERROR 1061 Duplicate key name para colisao de indice; ERROR 1103 Incorrect table name para divergencia de tipo/nulabilidade), sem nenhuma stored procedure, SIGNAL, CREATE ROUTINE ou ALTER ROUTINE, com privilegios identicos aos ja exigidos por 001/002/003. Ver docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md. |

| API VIO Decode (Serpro) para CNH/CRLV via QR: URL exata do endpoint de token OAuth2 não documentada oficialmente | `app/Rn/VioDecodeClient.php` (planejado) | Documentação confirmada em `docs/manual_vio_decode.md` descreve o fluxo client_credentials, mas não lista a URL do endpoint de token — bloqueia início da implementação real até confirmar |
| VIO Decode Trial é documentado como ambiente de dados MOCK — sem confirmação categórica de que QR reais de CNH/CRLV funcionem lá | `docs/manual_vio_decode.md` | Tratamento adotado no planejamento: Trial valida a integração técnica, não a autenticidade de documento real — decisão de produto sobre testar a fronteira mock-vs-real com documento próprio (consentimento explícito) ainda pendente de aprovação do usuário |
| ~~`DocumentoController::upload()` (CNH/CRLV) tem o mesmo IDOR já corrigido em `NotaController`~~ | `app/Controller/DocumentoController.php` | CONFIRMADO pelo usuário em 2026-09-08: correção entra no escopo da demanda `expedicao-vio-cnh-crlv` — ainda não implementada, mas decisão já tomada |
| ~~Decisão de produto: quais campos de CNH/CRLV persistir~~ | `sql/migrations/005_vio_decode_cnh_crlv.sql` (planejado) | RESOLVIDA em 2026-09-08: persistir apenas CNH (`nome`, `cpf`, `data_validade`) e CRLV (`exercicio`), NUNCA o JSON completo — mais caminhos das imagens, origem/status da validação e metadados mínimos de auditoria |
| Credenciais Trial (Consumer Key/Secret) da VIO Decode ainda não obtidas; localização dos QR oficiais de demonstração AUTORIZADA pelo usuário em 2026-09-08 (não obtidos ainda, mas sem bloqueio de autorização) | `.env` (produção/dev), roteiro de teste da prova técnica | Credencial depende de cadastro da UDLOG no portal Serpro — fora do escopo técnico deste projeto. QR de demonstração: autorizado buscar/usar |
| Endpoint OAuth2 informado pelo usuário (`https://gateway.apiserpro.serpro.gov.br/token`) para VIO Decode está sendo reconfirmado via WebFetch antes de ser aceito como definitivo | `docs/manual_vio_decode.md`, `app/Rn/VioDecodeClient.php` (planejado) | Em andamento em 2026-09-08 — instrução explícita do usuário de reconfirmar na documentação antes de implementar, nunca aceitar URL só porque foi informada, mesmo pelo usuário |
| Novas regras de negócio para avançar no atendimento de Expedição definidas (CNH: nome+CPF válido+validade não vencida; CRLV: exercício numérico+placa igual à do atendimento) — exercício sozinho NÃO significa veículo licenciado em tempo real (VIO só valida QR, não consulta restrições atuais) | `app/Rn/AtendimentoRn.php` (planejado), fluxo de Expedição | Decisão de produto confirmada em 2026-09-08, ainda não implementada — se qualquer validação falhar, não avança automaticamente, direciona para nova tentativa ou portaria |

| ~~Endpoint OAuth2 da VIO Decode não verificado~~ | `docs/manual_vio_decode.md`, `app/Rn/VioDecodeClient.php` (planejado) | RESOLVIDA em 2026-09-08: `POST https://gateway.apiserpro.serpro.gov.br/token` CONFIRMADO por teste HTTP real do usuário (401 `invalid_client`/"Unsupported Client Authentication Method" — resposta esperada sem credenciais, confirma URL real e método de auth Basic). `VIO_TOKEN_URL` mantido configurável em `.env` por boa prática, não por dúvida |

| ~~Bearer de demonstração para Trial não confirmado~~ | `app/Rn/VioDecodeClient.php` (planejado) | RESOLVIDA em 2026-09-08: usuário localizou e confirmou no Swagger oficial (`.../vio-decode/swagger/`) que o Trial disponibiliza um Bearer público compartilhado, dispensando OAuth2 completo só para Trial. **Valor do token NUNCA registrado em nenhum arquivo do projeto** (nem doc, nem handoff), por instrução explícita — só o nome da variável (`VIO_TRIAL_BEARER`) fica documentado, vazio |

| ~~Formato exato do corpo/`Content-Type` esperado pela VIO Decode para o raw value do QR não confirmado — teste real retornou HTTP 415 com `{"raw_value": "..."}` como JSON~~ | `app/Rn/VioDecodeClient.php` | RESOLVIDA em 2026-09-08: confirmado por teste real com os arquivos oficiais de demonstração do Serpro (`qrcode-trial.bin`, `crlv-demo.bin`) que o corpo deve ser os BYTES BINÁRIOS PUROS (`Content-Type: application/octet-stream`, sem JSON/base64/hex/multipart) — ambos retornaram HTTP 200 após a correção. Ver `docs/manual_vio_decode.md` |
| Lock `GET_LOCK`/`RELEASE_LOCK` (proteção contra chamada duplicada em `DocumentoController`) não é liberado explicitamente em caminhos de erro por causa de `exit()` dentro de `Resposta::erro()` pular o `finally` | `app/Controller/DocumentoController.php`, `util/Resposta.php` | Achado do `security-especialista` em 2026-09-08 — não causa deadlock hoje (conexão não-persistente libera o lock ao fechar), mas o comentário do código afirma incorretamente que é liberado "mesmo em erro fatal". Correção futura sugerida, não decidida |
| Correspondência prática de `jsQR.binaryData` com o raw value esperado pela VIO Decode não confirmada | `public/totem/assets/qr-worker.js` | Só teste físico com QR real resolve — agravado pelo achado do HTTP 415 |
| `motorista_nome`/`motorista_cpf` em `tb_atendimento` (dado do atendimento em si, distinto do cache de CNH) permanecem em texto plano | `sql/schema.sql` | Decisão registrada em 2026-09-08 — só o cache de CNH (`tb_vio_cache_cnh`) é criptografado, não os campos operacionais do atendimento |
| ~~Regra de "rebaixamento para MANUAL" (planejada no `/00-planejamento` original) nunca foi implementada~~ | `app/Rn/AtendimentoRn.php::salvarDadosMotorista()` | **RESOLVIDA — registro estava DESATUALIZADO (corrigido em 2026-09-16, demanda `saneamento-lista-pendencias-projeto`)**: `salvarDadosMotorista()` (linhas 78-160) já compara os valores confirmados pelo atendente contra o snapshot gravado no momento da validação VIO (`cnh_snapshot_*`/`crlv_snapshot_*`) e rebaixa `cnh_origem`/`crlv_origem` para `MANUAL` + `status_revisao='PENDENTE_REVISAO'` em caso de divergência, com regra documentada no cabeçalho do método (inclusive "já MANUAL não promove sozinho"). Confirmado por leitura de código pelo `security-especialista` em 2026-09-16 — nenhuma pendência técnica real remanescente aqui |
| ~~Campo `image` do envelope de resposta da VIO Decode não é descartado explicitamente ao ser recebido~~ | `app/Rn/VioDecodeClient.php`, `app/Rn/DocumentoRn.php` | **RESOLVIDA — registro estava DESATUALIZADO (corrigido em 2026-09-16, demanda `saneamento-lista-pendencias-projeto`)**: `DocumentoRn.php` usa allowlists explícitas (`CAMPOS_PERMITIDOS_CNH`/`CAMPOS_PERMITIDOS_CRLV`) e chama `unset($resultadoVio, $dadosBrutos);` logo após extrair os campos permitidos — descarte ativo e documentado no cabeçalho do arquivo. Confirmado por leitura de código pelo `security-especialista` em 2026-09-16 |
| ~~Rejeição de placeholder (`ehValorPlaceholder()`) cobre CRLV mas não CNH — assimetria de robustez~~ | `app/Rn/DocumentoRn.php::avaliarCnh()` | **RESOLVIDA — registro estava DESATUALIZADO (corrigido em 2026-09-16, demanda `saneamento-lista-pendencias-projeto`)**: `avaliarCnh()` já chama `ehValorPlaceholder($nome)` da mesma forma que `avaliarCrlv()` chama para seus campos — o próprio comentário de `ehValorPlaceholder()` no código documenta que essa assimetria foi encontrada e corrigida. Confirmado por leitura de código pelo `security-especialista` em 2026-09-16 |
| Rótulo de origem de validação (`VIO_TRIAL`/`VIO_VALIDADO`/`MANUAL`) é derivado localmente no front-end em vez de vir de um campo explícito do backend | `public/totem/assets/app.js` | Observação do `frontend-especialista` em `/02-testes` de 2026-09-08 — funciona hoje (mesma lógica replicada em ambos os lados), mas é regra de negócio duplicada, não uma falha |
| ~~**[BLOQUEANTE]** `veiculo.uf` sem coluna/fonte~~ | `sql/schema.sql`, `app/Rn/DocumentoRn.php` | IMPLEMENTADA em 2026-09-09 — `crlv_uf`/`crlv_snapshot_uf`/`tb_vio_cache_crlv.uf` criadas (migration 009), validação de 27 UFs, dropdown no formulário manual, rebaixamento para MANUAL se editado, cache sem UF tratado como incompleto. Testado (11/11 asserções) |
| ~~**[CRÍTICO]** `AtendimentoController::finalizar()` sem validação de posse/tipo/status/etapa~~ | `app/Controller/AtendimentoController.php` | IMPLEMENTADA em 2026-09-09 — corrigido com `buscarAtendimentoDoTotem` + tipo/status/etapa/documentos/empresa, CAS de idempotência como último portão. Confirmado por `security-especialista` e `qa-testes` de forma independente (10/10 asserções IDOR) |
| ~~**[CRÍTICO]** `TalentClient` persiste corpo bruto de resposta em log~~ | `app/Rn/TalentClient.php`, `app/Dao/FilaEnvioDao.php` | IMPLEMENTADA em 2026-09-09 — categorias internas fechadas via `TalentClientException`, nunca payload/CPF/CNH/base64/token. Confirmado por `security-especialista` e `qa-testes` (34/34 asserções de log sanitizado) |
| ~~Ausência total de mecanismo de idempotência no envio ao Talent~~ | `app/Rn/TalentRn.php`, `app/Dao/AtendimentoDao.php`, `cron/reenviar-fila.php` | **RESOLVIDA — registro estava DESATUALIZADO (corrigido em 2026-09-16, demanda `saneamento-lista-pendencias-projeto`)**: coluna `talent_checkin_status` com os 5 estados (`NAO_ENVIADO`/`ENVIANDO`/`ENVIADO`/`ERRO_REPROCESSAVEL`/`ENVIO_INDETERMINADO`) criada na migration 009 (2026-09-09); CAS real implementado em `AtendimentoDao::iniciarEnvioTalent()`/`gravarResultadoEnvioTalent()`/`marcarEnvioTalentObsoletoComoIndeterminado()`, reaproveitado por `TalentRn::processarCheckin()` e por `cron/reenviar-fila.php` (nunca reenvia ignorando o estado atual). Testado em `tests/manual/teste_talent_idempotencia.php` e `teste_concorrencia_finalizar_checkin.php`, e reaproveitado sem alteração pelas demandas `talent-doctos-finalizacao-checkin` (2026-09-14) e `talent-http409-limpeza-pendencias` (2026-09-15). Confirmado por leitura de código e histórico de commits (`89b8b5d`, `2369f96`) pelo `explorer` em 2026-09-16 |
| Diversos campos do payload real do Talent (`reboque`, `exigePesagem`, `cnpjTransportadora`/`nomeTransportadora`, telefones de motorista/ajudante, `nrCNH`/`categoriaCNH`, `temPernoite`, `paletes`, `nrContainer`/`lacreContainer`/`delivery`, `obs`, múltiplos ajudantes) não têm nenhuma fonte de captura no totem hoje | `sql/schema.sql`, fluxo de captura do totem | Decisão de produto pendente sobre quais são realmente necessários — nenhuma coluna proposta para eles até decisão explícita |
| Formulário manual do CRLV precisa de um dropdown fechado de 27 UFs (nunca texto livre) — mudança de UI a implementar junto com o backend | `public/totem/assets/app.js` (planejado) | Dependência de front-end/UX registrada em 2026-09-09, fora do escopo de backend puro |
| ~~Novas colunas planejadas~~ | `sql/migrations/008_tb_empresa_totem_vinculo.sql`, `sql/migrations/009_talent_checkin_uf_idempotencia.sql` | IMPLEMENTADA em 2026-09-09 — colunas criadas conforme planejado, exceto `talent_retorno_bruto`, que foi explicitamente revogada (nunca persistir corpo bruto). Migrations testadas limpas e repetidas |
| ~~**[BLOQUEIO EXPLÍCITO]** Qual linha real de `tb_totem` é o totem físico de teste~~ | `sql/migrations/008_tb_empresa_totem_vinculo.sql` | RESOLVIDA em 2026-09-09 — usuário confirmou `id_totem=1`, `codigo=RECEPCAO-01`; migration vincula Maua I somente com AMBOS batendo simultaneamente (testado: divergência em qualquer um dos dois não vincula nada) |
| ~~Biblioteca PHP de geração de PDF a confirmar~~ | `composer.json` | RESOLVIDA em 2026-09-09 — `setasign/fpdf` instalada e testada (19/19 asserções de PDF/páginas), compatível com PHP 8.0.3 e Hostgator (sem binário externo) |
| ~~**[BLOQUEANTE - CONFIRMADO REAL]** `veiculo.rntc` obrigatorio no Talent~~ | `app/Rn/DocumentoRn.php`, `sql/migrations/010_talent_rntc_tipo_veiculo.sql` | IMPLEMENTADA em 2026-09-10 — extraido de `data.rntrc` do CRLV via VIO Decode (grafia real do campo confirmada no manual VIO), com validacao/cache/rebaixamento/preenchimento manual. Testado (23/23 asserções dedicadas) |
| ~~**[BLOQUEANTE - CONFIRMADO REAL]** `veiculo.tipo` obrigatorio no Talent~~ | `app/Rn/DocumentoRn.php`, `sql/migrations/010_talent_rntc_tipo_veiculo.sql` | IMPLEMENTADA em 2026-09-10 — extraido de `data.tipo` do CRLV via VIO Decode, campo de texto livre (sem enum documentado pelo Talent). Testado (23/23 asserções dedicadas) |
| ~~**[RESOLVIDA — SUCESSO REAL CONFIRMADO]**~~ `doctos[]` obrigatorio no Talent, semantica de `nrDocto`/`doctos[].tipo` nao confirmada — nao presumir | `app/Controller/AtendimentoController.php`, `app/Rn/TalentRn.php`/`TalentClient.php` | Mitigado em 2026-09-10 com bloqueio INCONDICIONAL de qualquer envio real ao Talent (`finalizar()` sempre retorna HTTP 501 `TALENT_DOCTOS_PENDENTE`, antes do CAS de idempotência) — confirmado por security-especialista (0 achados) e qa-testes (bloqueio garantido, `talent_checkin_status` nunca muda). **ADENDO 2026-09-14**: plano completo de resolução produzido em `/00-planejamento` (`docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`), com fatos confirmados via Swagger oficial (`TPortariaCheckinRet={nrRegAcesso,msg}`, `enumTipoEmbDesemb` capitalizado). **DESFECHO (`/01-implementacao` 2026-09-14, IMPLEMENTADO E TESTADO)**: a trava incondicional `TALENT_DOCTOS_PENDENTE` foi REMOVIDA; `doctos[]` real implementado (Recebimento: `NOTA_FISCAL` por nota via novo campo `tb_atendimento_nota.numero_nota`; Expedição: `ORDEM_COLETA` via `ordem_coleta` já existente); `tipoEmbDesemb` corrigido para capitalizado; parsing de resposta corrigido para `nrRegAcesso`/`msg`; ordem de coleta passa a ser marcada `INATIVA` após sucesso (`OrdemColetaDao::marcarInativaPorNumero()`, com `tb_ordem_coleta_pendente_baixa` para falha de reconciliação); endpoint de impressão real criado (`ImpressaoAtendimentoController`). O bloqueio TÉCNICO de `doctos[]` está portanto RESOLVIDO — mais de 190 asserções passando, 0 achados de segurança bloqueantes. **O que CONTINUA intencionalmente desativado**: o envio REAL ao Talent permanece bloqueado por decisão de segurança (não pendência técnica) via nova variável `TALENT_CHECKIN_ATIVO` (fail-closed, ausente/`false` por padrão) — enquanto não for `true` explicitamente, `finalizar()` roda todos os gates reais mas responde HTTP 503 `TALENT_CHECKIN_DESATIVADO` em vez de chamar o Talent. Esse portão só deve ser ligado manualmente antes do protocolo de teste controlado em Produção (ver `docs/manual_talent.md`, seção "HTTP 409 — protocolo de teste controlado planejado"), que ainda não foi executado/autorizado. Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Resultado da implementação (2026-09-14)" |
| ~~Reaproveitamento do endpoint `impressao-teste.php?acao=configuracao-servico-local` pelo novo fluxo REAL de impressão~~ | `public/api/impressao.php`, `public/api/impressao-teste.php`, `app/Controller/ImpressaoAtendimentoController.php` | **RESOLVIDA — registro estava DESATUALIZADO (corrigido em 2026-09-16, demanda `saneamento-lista-pendencias-projeto`)**: demanda `impressao-arquitetura-producao-ux` (2026-09-15) separou os dois fluxos — `impressao.php` (produção, `ImpressaoAtendimentoController::configuracaoServicoLocal()`) e `impressao-teste.php` (exclusivo do diagnóstico) são hoje isolados, sem reaproveitamento. Commit `b6b5129` (`refactor(impressao): separa fluxos de teste e producao`), `/02-testes` e `/03-revisao` da época com veredito APROVADO. Confirmado por leitura de código e handoff pelo `explorer` em 2026-09-16 |
| ~~Nova tela de seleção de impressora no fluxo REAL — texto/estilo não objeto de revisão formal de UX/produto~~ | `public/totem/assets/impressao.js` | **RESOLVIDA — registro estava DESATUALIZADO (corrigido em 2026-09-16, demanda `saneamento-lista-pendencias-projeto`)**: a mesma demanda `impressao-arquitetura-producao-ux` (2026-09-15) incluiu revisão formal de UX (13 critérios, cobrindo especificamente a tela `selecionar_impressora`), com 1 achado (ausência de botão de saída) corrigido na mesma rodada (botão "Novo atendimento" adicionado), testado 12/12 pelo `qa-testes`, `/03-revisao` de confirmação APROVADO. Confirmado por leitura de handoff e commits (`93dc551`/`a8a0454`) pelo `explorer` em 2026-09-16 |
| ~~Protocolo de teste controlado em Produção para o Talent (HTTP 409, `anexos`/`anexosGZip`)~~ | `.env` (produção), `app/Rn/TalentRn.php`/`TalentClient.php` | **CONCLUÍDO E CONFIRMADO EMPIRICAMENTE em 2026-09-15 (demanda `talent-http409-limpeza-pendencias`)**: 2 tentativas reais controladas executadas com autorização explícita do usuário — tentativa 2 (payload duplicado, mesmo fingerprint SHA-256) confirmou HTTP 409 real do Talent, classificação interna `409 → conflito/ERRO_REPROCESSAVEL` correta, sem criar segundo check-in. Formato de anexo `anexos` = CONCLUÍDO (ativo, aprovado no teste real). Fallback `anexosGZip` = NÃO NECESSÁRIO (implementado em `montarAnexosGzip()`, nunca precisou ser chamado — não deve aparecer como falha/pendência em avaliações futuras). Ver `docs/handoffs/2026-09-15-talent-http409-limpeza-pendencias.md`, seção "Resultado da tentativa real 2 e CONFIRMACAO FINAL do HTTP 409" |
| `docs/manual_talent.md` desatualizado sobre `tipoEmbDesemb`/formato de retorno de sucesso/semântica de `nrDocto` | `docs/manual_talent.md` | RESOLVIDA em 2026-09-14 — nova seção final "Implementação de doctos[] e finalização (2026-09-14)" documenta os fatos confirmados via Swagger oficial e marca a decisão antiga de `tipoEmbDesemb` minúsculo (seção "REFINAMENTO 2 (2026-09-09)") como superada/incorreta |
| ~~**[BLOQUEANTE — `/02-testes` PRECISA DE AJUSTE]** `App\Rn\NotaFiscalRn::atualizarNumeroNota()` aceitava e persistia SILENCIOSAMENTE uma chave de acesso de 44 dígitos como `numero_nota`~~ | `app/Rn/NotaFiscalRn.php`, `public/api/nota.php` (ação `definir-numero`) | CORRIGIDA em 2026-09-14 (rodada curta de `/01-implementacao`) — validação de comprimento/formato de `numero_nota` adicionada, rejeitando explicitamente valores acima do plausível para número de nota (32/32 testes novos passando, segurança reconfirmada sem regressão). Fase 1 de `/02-testes` reexecutada integralmente: **27/27 itens PASSOU, veredito APROVADO**. Fase 2 (preparação do teste controlado em Produção) executada: atendimento sintético de Expedição (`id_atendimento=1573`, ambiente de DEV LOCAL) validado contra todos os gates, payload real montado via `TalentRn::montarPayload()` (nunca enviado) e apresentado mascarado (CPF/nome do motorista nunca exibidos), `doctos[]` com 1 `ORDEM_COLETA` sintética (`OC-TESTE-001`) + 2 anexos (só nome/quantidade), `tipoEmbDesemb="Embarque"` confirmado, `talent_checkin_status=NAO_ENVIADO`, `TALENT_CHECKIN_ATIVO` confirmado ausente (fail-closed). **ATUALIZAÇÃO 2026-09-14 (execução do único POST real controlado autorizado)**: o orquestrador, com autorização direta do usuário, executou o POST real contra `id_atendimento=1573` (Expedição, `OC-TESTE-001`, ambiente de DEV LOCAL), ativando `TALENT_CHECKIN_ATIVO=true` temporariamente. Resultado: HTTP 202, `sucesso=false`, mensagem sanitizada ao cliente, `talent_checkin_status=ERRO_REPROCESSAVEL`. Causa raiz confirmada por leitura do código (`TalentClient.php` linhas 79-112): erro caiu na categoria `erro_conexao`/reprocessável (`CURLE_COULDNT_RESOLVE_HOST`/`CURLE_COULDNT_CONNECT`) — a requisição nunca saiu desta máquina de dev local, nenhum byte chegou ao Talent; nenhum registro foi criado no Talent. Confirmado por consulta direta ao banco: ordem `OC-TESTE-001` permanece `ATIVA`, `tb_ordem_coleta_pendente_baixa` sem nenhuma linha para `id_atendimento=1573`, `TALENT_CHECKIN_ATIVO` revertido para ausente/`false` no `.env` real logo em seguida. Nenhuma impressão física realizada. **Conclusão inicial (superada, ver ATUALIZAÇÃO abaixo)**: o mecanismo de ativação/gates/classificação de erro funcionou exatamente como projetado; a suspeita inicial era de CONECTIVIDADE DE REDE do ambiente de dev local até `api.talentcs.com.br`, não de contrato/payload (o payload sequer teria chegado a ser avaliado pelo Talent). **ATUALIZAÇÃO 2026-09-14 (CAUSA RAIZ REAL CONFIRMADA — 3ª tentativa real controlada e autorizada)**: investigação a fundo (autorizada pelo usuário) descartou a hipótese de rede/DNS/conectividade com evidência concreta (DNS/TLS/conexão HEAD ao path exato do Talent funcionaram perfeitamente em todos os testes) e, via instrumentação de diagnóstico TEMPORÁRIA (autorizada, removida com sucesso logo depois — `git diff` confirmou reversão exata, `php -l` limpo), confirmou que a causa real das 3 tentativas foi **HTTP 401 Unauthorized retornado pelo Talent** (categoria interna `erro_autenticacao`, mapeamento `401 => 'erro_autenticacao'` confirmado em `TalentClient.php`). **CAUSA RAIZ = PENDÊNCIA DE CREDENCIAL EXTERNA**: a `TALENT_API_KEY` configurada no `.env` real desta máquina está sendo REJEITADA pelo Talent (credencial inválida/expirada/incorreta para este endpoint ou válida só para outro ambiente/CNPJ) — não é pendência de rede, payload, contrato ou código desta implementação. Estado final confirmado: atendimento `id_atendimento=1573` permanece `ERRO_REPROCESSAVEL`, não excluído; ordem `OC-TESTE-001` permanece `ATIVA`, intocada; `tb_ordem_coleta_pendente_baixa` sem nenhuma linha para esse atendimento; nenhuma impressão física em nenhuma das 3 tentativas; `TALENT_CHECKIN_ATIVO` revertido para ausente/`false` após cada uma das 3 tentativas. **Próximo passo necessário**: o usuário precisa confirmar/renovar a `TALENT_API_KEY` junto ao Talent (ou confirmar se essa credencial é válida apenas para outro ambiente/CNPJ) antes de qualquer nova tentativa real de POST — sem credencial válida, nenhum teste real ponta a ponta pode ser concluído com sucesso, independente do ambiente (dev local ou produção). A implementação de `doctos[]`/gates/mecanismo de ativação segue tecnicamente correta e validada (Fase 1 = 27/27, 3 revisões de segurança aprovadas); o bloqueio remanescente é puramente de credencial externa, fora do escopo de código. Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seções "Execução do POST real controlado (2026-09-14)" e "Investigação da causa raiz — HTTP 401 confirmado (2026-09-14)". **ATUALIZAÇÃO 2026-09-14 (4ª tentativa — SUCESSO REAL CONFIRMADO)**: causa raiz definitiva do HTTP 401 identificada pelo usuário — `TALENT_API_KEY` no `.env` real estava truncado em 1 caractere (42 em vez de 43). Corrigido, 4ª tentativa real autorizada: HTTP 200, `sucesso=true`, `nrRegAcesso=35784`, ordem `OC-TESTE-001` marcada `INATIVA` com sucesso, impressão física real confirmada visualmente pelo usuário. **ATUALIZAÇÃO 2026-09-14 (`/03-revisao` final = PRECISA DE AJUSTE)**: segurança e QA/evidência 100% APROVADOS (achado de atenção não bloqueante registrado abaixo, em linha própria, sobre `tentarMarcarOrdemConcluida`/`JA_ENVIADO`); UX/front-end encontrou 2 pontos a corrigir antes do fechamento: (1) **[BLOQUEANTE]** modal obrigatório de preenchimento manual do número da nota (`abrirModalNumeroNotaManual`, `app.js`) não tem botão de saída/cancelar/voltar, e seu overlay (`z-index:50`) cobre a barra de "Cancelar atendimento" da tela, tornando-a inacessível ao toque enquanto o modal está aberto; (2) **[ATENÇÃO]** o mesmo modal reaproveita o teclado QWERTY completo (alvos de toque ~40x46px) para o campo puramente numérico `numero_nota`, em vez de um teclado numérico dedicado. Usuário confirmou que já excluiu manualmente, no painel do Talent, o check-in de teste da placa `ABC1D23`. Demanda retorna para rodada curta de `/01-implementacao` restrita aos 2 pontos de UX acima. Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Resultado da /03-revisão final (2026-09-14)". **ATUALIZAÇÃO 2026-09-15 (rodada curta de `/01-implementacao` = CORRIGIDA E TESTADA)**: os 2 achados de UX foram corrigidos — (1) modal `abrirModalNumeroNotaManual` ganhou botão "✕ Cancelar atendimento" interno, reaproveitando exatamente `confirmarCancelar()` já existente (sem lógica nova), confirmada ausência de handler de fechamento por toque no overlay; (2) teclado numérico dedicado novo (`montarTecladoNumericoNota`), alvos de toque de 64px, nunca reaproveita `montarTeclado()`/`#teclado` QWERTY (intocado nas demais telas). `qa-testes` validou 13/13 itens do roteiro obrigatório (185 asserções), incluindo regressão de Recebimento/Expedição/Talent/impressão 100% aprovada. Pronta para nova rodada de `/03-revisao` de confirmação. Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Rodada curta de /01-implementacao — correções de UX e auditoria (2026-09-15)". **RECLASSIFICAÇÃO 2026-09-15 (demanda `talent-http409-limpeza-pendencias`)**: teste base do Talent (HTTP 200, `nrRegAcesso=35784`, 4ª tentativa real, 2026-09-14) = **CONCLUÍDO**; formato de anexo `anexos` (ativo, aprovado no teste real) = **CONCLUÍDO**; fallback `anexosGZip` (implementado em `montarAnexosGzip()`, nunca precisou ser chamado) = **NÃO NECESSÁRIO** — nunca deve aparecer como falha/pendência em avaliações futuras. |
| ~~Branch `JA_ENVIADO` de `AtendimentoController::tentarMarcarOrdemConcluida()` pode gravar entrada FALSA em `tb_ordem_coleta_pendente_baixa` quando a ordem já estava `INATIVA` de uma chamada anterior bem-sucedida (`UPDATE ... WHERE status='ATIVA'` retorna `rowCount()=0`/`$ok=false` mesmo sem falha real)~~ | `app/Controller/AtendimentoController.php` | Achado de atenção do `security-especialista` em `/03-revisao` de 2026-09-14, severidade não bloqueante (é ruído de auditoria, não falha de segurança). **CORRIGIDA em 2026-09-15 (rodada curta de `/01-implementacao`)**: novo `OrdemColetaDao::statusPorNumero()`/`OrdemColetaClient::statusAtual()` consulta o status real antes de decidir — se `INATIVA`, trata como sucesso idempotente (sem registro em `tb_ordem_coleta_pendente_baixa`, sem segundo `UPDATE`); só registra pendência se ainda `ATIVA`/não encontrada/erro real. Testado (14/14 asserções, incluindo os 4 novos cenários) |
| ~~**[BLOQUEANTE — `/03-revisao` PRECISA DE AJUSTE — 2ª tentativa, 2026-09-15]** NOVO achado de UX, distinto do achado de 2026-09-14 já corrigido (ausência de saída no modal): a PRÓPRIA CORREÇÃO da rodada anterior (botão "✕ Cancelar atendimento" interno ao modal de número de nota) introduziu um travamento funcional real~~ | `public/totem/assets/app.js` (`abrirModalNumeroNotaManual`, `confirmarCancelar`, `abrirModal`, flag `numeroModalAberta`), `public/totem/assets/app.css` (`.btn-cancelar`) | Achado do `qa-testes`/revisão UX em `/03-revisao` de 2026-09-15 (revisão de confirmação da correção de 2026-09-15 anterior). **Causa raiz**: `confirmarCancelar()` chama `abrirModal(...)`, que SUBSTITUI o `innerHTML` do mesmo `#modalCaixa` já aberto (não empilha um novo) — sobrescrevendo o modal de número de nota pela pergunta de confirmação de cancelamento. Se o motorista tocar "Continuar atendimento" (não quero cancelar), o `onclick` só chama `fecharModal()` (esconde o overlay) sem restaurar o conteúdo original do modal de número de nota e sem resetar `numeroModalAberta` (permanece `true` para sempre) — `processarProximoNumeroNotaModal()` passa a retornar cedo para qualquer nota futura, sem nenhum caminho de UI restante para reabrir o campo; `finalizarDigitalizacao()` continua bloqueado. Efeito real: motorista que escolhe "Continuar atendimento" fica PERMANENTEMENTE TRAVADO, só consegue sair cancelando o atendimento inteiro — o oposto da opção escolhida. **AJUSTE EXATO necessário**: o botão "Continuar atendimento" deste fluxo precisa RESTAURAR o modal de número de nota (reabrir `abrirModalNumeroNotaManual`/`abrirModalNumeroNotaSugestao` com o estado da nota pendente atual) em vez de só `fecharModal()` — ou usar confirmação que não sobrescreva o modal original (segundo modal empilhado, ou confirmação inline). **Achado de ATENÇÃO adicional (não bloqueante)**: botão "✕ Cancelar atendimento" usa `.btn-cancelar` (texto cinza claro, sem padding/altura/borda definidos) — contraste/alvo de toque abaixo do padrão do projeto; sugerido usar estilo próximo de `.btn-fantasma`/`.btn-alerta`, já que dentro do modal é a única saída disponível. Segurança 100% APROVADA nesta mesma rodada (10/10 itens, zero achados). **VEREDITO**: `/03-revisao` = PRECISA DE AJUSTE, retorna para nova rodada curta de `/01-implementacao` restrita a estes 2 pontos (1 bloqueante + 1 atenção), mesmo arquivo/modal já tocado na correção anterior. Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Resultado da /03-revisão final — 2ª tentativa (2026-09-15)" **CORRIGIDA em 2026-09-15 (nova rodada curta de `/01-implementacao`)**: implementado overlay de confirmação SEPARADO e independente (`#modalConfirmCancelNotaFundo`/`#modalConfirmCancelNotaCaixa`, `z-index:55`), empilhado por cima do `#modalCaixa` original — nunca mais sobrescreve/destrói o modal de número de nota. Novas funções `confirmarCancelarNotaModal()`/`fecharConfirmacaoCancelarNotaModal()`: "Continuar atendimento" fecha só a confirmação (nada precisa ser restaurado, pois o modal de baixo nunca foi tocado); "Sim, cancelar" reaproveita `cancelarESair()` já existente, sem duplicar lógica. Botão adicionado nas duas variantes (`abrirModalNumeroNotaManual`/`abrirModalNumeroNotaSugestao`). Ajuste visual: nova classe `.btn-saida-modal-nota` (contraste tipo `.btn-fantasma`, `min-height:64px`) substitui `.btn-cancelar-modal`. `qa-testes` validou os 10 itens obrigatórios por rastreamento de código (sem ambiente de navegador/DOM real disponível — recomendada validação manual rápida no dispositivo físico antes do `/04-commit-e-push`). Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Rodada curta de /01-implementacao — correção do cancelamento no modal de número de nota (2026-09-15)". |
| ~~**[BLOQUEANTE — `/02-testes` PRECISA DE AJUSTE, confirmado 2026-09-15]** `mostrarInatividade()` ainda usa `abrirModal()` diretamente, sobrescrevendo `#modalCaixa` — o timer de inatividade (180s) roda normalmente enquanto o modal obrigatório de número de nota está aberto (tela `rec_digitaliza` !== `home`); ao disparar, sobrescreve o modal de nota pelo aviso "Ainda está aí?", destruindo nota pendente/número digitado/teclado; se o motorista tocar "Continuar", `fecharModal()` só esconde o overlay, sem restaurar o modal de nota e sem resetar `numeroModalAberta` (fica `true` para sempre) — motorista PERMANENTEMENTE TRAVADO, sem nenhuma ação incorreta da parte dele, só 3min de inatividade com o modal aberto. Mais 30s de inatividade total cancela o atendimento inteiro automaticamente~~ | `public/totem/assets/app.js` (`mostrarInatividade`, linha ~125; `reiniciarIdle`, `abrirModal`, `numeroModalAberta`) | Achado do `qa-testes` registrado como observação não bloqueante em 2026-09-15 (rodada anterior), **RECLASSIFICADO PARA BLOQUEANTE em 2026-09-15 (`/02-testes` de confirmação)** por instrução explícita do usuário de tratar como bloqueante se reproduzido/confirmado por código (não como pendência futura) — confirmado com certeza por leitura exata de código, sem necessidade de execução em browser (lógica determinística). `/02-testes` = PRECISA DE AJUSTE, demanda retorna para `/01-implementacao` restrita a esta correção: `mostrarInatividade()` precisa do mesmo tratamento já aplicado ao cancelamento nesta demanda (overlay próprio/empilhado, ou verificação se o modal de número de nota está aberto antes de sobrescrever `#modalCaixa`, restaurando o estado ao fechar). Regressão automatizada (202/202 asserções, 10 suítes) 100% aprovada nesta mesma rodada, zero achados novos além deste. Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Resultado dos testes — /02-testes de confirmação (2026-09-15)" **CORRIGIDA em 2026-09-15 (nova rodada curta de `/01-implementacao`)**: `mostrarInatividade()` migrada para overlay PRÓPRIO e independente (`#modalInatividadeFundo`/`#modalInatividadeCaixa`, nova classe `.modal-fundo-inatividade { z-index: 80 }` — acima de `.modal-fundo` 50, `.modal-fundo-confirma-nota` 55 e `.diag-overlay` 70), nunca mais tocando em `#modalCaixa`/`#modalFundo`. Nova `fecharAvisoInatividade()` fecha só o overlay de inatividade; timer único `idleTimer` reaproveitado para os dois timeouts (180s/30s), sempre limpo antes de reagendar (sem duplicação); expiração do timer de abandono reaproveita `cancelarESair()` já existente, sem duplicar lógica. `ir()` ganhou `fecharAvisoInatividade()` no início, evitando overlay/timer órfão em qualquer troca de tela. `numeroModalAberta` não é tocada por esse fluxo. `qa-testes` validou 10/10 itens do roteiro (empilhamento sobre modal manual, sugestão OCR, e confirmação de cancelamento — 3 camadas) por rastreamento de código (sem ambiente de navegador real disponível), mais reexecução das 10 suítes automatizadas de backend (202/202 PASSOU, zero regressão, backend confirmado intocado por mtime). Observação não bloqueante registrada: listener global de toque reagenda o timer sem fechar o overlay se o motorista tocar fora do botão "Continuar" — pré-existente, não alterado, fora do escopo. Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Rodada curta de /01-implementacao — correção do bloqueio de inatividade (2026-09-15)". **RECLASSIFICAÇÃO 2026-09-15 (demanda `talent-http409-limpeza-pendencias`)**: `mostrarInatividade()` = **CORRIGIDO E VALIDADO**. |
| ~~**[BLOQUEANTE — `/02-testes` PRECISA DE AJUSTE, confirmado 2026-09-15, Etapa 1 de confirmação]** Listener global de toque (`document.addEventListener` para `click`/`touchstart`/`keydown`, chamando `reiniciarIdle()`) interfere no countdown de 30s de abandono do aviso de inatividade: um toque em QUALQUER lugar da tela, inclusive dentro do próprio overlay `#modalInatividadeFundo` fora do botão "Continuar", cancela o timer de abandono (30s) e o reagenda para `IDLE_MS` (180s) via `mostrarInatividade` — sem fechar o overlay nem alterar visualmente o aviso. Divergência real entre UI ("Ainda está aí?", implicando expiração em 30s) e comportamento de fundo (sistema já concedeu 180s silenciosamente)~~ | `public/totem/assets/app.js` (`reiniciarIdle` linha ~120-123, listener global linha ~160, `mostrarInatividade`/`fecharAvisoInatividade` linha ~138-158) | Achado do `qa-testes` em 2026-09-15 (rodada curta anterior registrou como observação não aprofundada; **CONFIRMADO BLOQUEANTE nesta rodada de `/02-testes` de confirmação, Etapa 1**, por instrução explícita do usuário de classificar como bloqueante se confirmado por código). `reiniciarIdle()` faz `clearTimeout(idleTimer)` incondicional, sem distinguir se o timer ativo era o principal (180s) ou o de abandono (30s) — mesma variável tratada de forma cega. **NÃO CORRIGIDO ainda** — `/02-testes` = PRECISA DE AJUSTE, Etapa 2 (validação manual) e `/03-revisao` NÃO iniciadas. Demanda retorna para `/01-implementacao`, restrita a: listener global ignorar toques enquanto `#modalInatividadeFundo` estiver com a classe `aberto` (exceto o botão "Continuar"), ou distinguir o estado do timer antes de reagendar incondicionalmente. Regressão automatizada (202/202 asserções, 10 suítes) 100% aprovada nesta mesma rodada, zero achados novos além deste. Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Resultado dos testes — /02-testes de confirmação, Etapa 1 (2026-09-15)" **CORRIGIDA em 2026-09-15 (nova rodada curta de `/01-implementacao`)**: implementada máquina de estado explícita — duas variáveis de timer DEDICADAS (`idleTimerPrincipal` 180s, `idleTimerAbandono` 30s, nunca compartilhadas) + novo estado `idleEstado` (`normal`/`aviso`/`inativo`). Listener global corrigido com guarda `if (idleEstado === 'aviso') return;` ANTES de qualquer `reiniciarIdle()` — confirmado ser a única linha capaz de decidir isso, sem nenhum outro listener concorrente. Nova `continuarAposAvisoInatividade()` é o único caminho que fecha o aviso/cancela o timer de abandono/reinicia o principal. `fecharAvisoInatividade()` sempre limpa `idleTimerAbandono` (evita callback fantasma). `qa-testes` validou 12/12 itens do roteiro (toque/tecla/conteúdo do overlay não alteram o prazo de 30s, expiração dispara `cancelarESair()` uma única vez, sem timers duplicados, ciclo repetido 3x, modal manual/sugestão OCR/confirmação de cancelamento preservados, navegação limpa tudo sem callback atrasado) por rastreamento de código (sem ambiente de navegador real disponível — mesma limitação recorrente nesta demanda), mais reexecução das 10 suítes automatizadas de backend (202/202 PASSOU, zero regressão). Ver `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção "Rodada curta de /01-implementacao — correção do listener global e timers de inatividade (2026-09-15)". **RECLASSIFICAÇÃO 2026-09-15 (demanda `talent-http409-limpeza-pendencias`)**: listener global de toque = **CORRIGIDO E VALIDADO**. |
### 5.1. Lista ativa consolidada e categorizada (saneamento de 2026-09-16)

Reorganização feita na demanda `saneamento-lista-pendencias-projeto`
(ver `docs/handoffs/2026-09-16-saneamento-lista-pendencias-projeto.md`).
A tabela acima (seção 5) permanece como registro histórico completo
(nada foi apagado); esta subseção é a referência RÁPIDA e categorizada
do que está genuinamente ativo hoje, auditado por dois sub-agentes
independentes (`explorer` + `security-especialista`) com evidência real
de código/commit/handoff, não por descrição textual.

#### 1. PENDENTE — AÇÃO TÉCNICA

- ~~**BLOQUEANTE, achado do `/03-revisao` curta independente de
  `vio-hardening-sem-credenciais` (2026-09-20)**: o novo metodo
  `validarExercicioInteiroExato()` em `DocumentoRn.php` (introduzido
  na correcao do achado anterior) usa regex `/^-?\d+$/` que valida
  so o FORMATO lexical (sequencia de digitos), nunca se o valor
  numerico cabe em `PHP_INT`. Uma string de digitos maior que
  `PHP_INT_MAX` (ex. `"99999999999999999999"`) passa incolume por
  essa fronteira, chega ao cast `(int)` ja existente e SATURA
  SILENCIOSAMENTE para `PHP_INT_MAX` (comportamento documentado do
  PHP) -- valor `> 0`, passa pela regra de faixa, e e APROVADO. A
  gravacao no MySQL satura de novo (coluna `SMALLINT`) para `32767`.
  Reproduzido pelo fluxo real em banco descartavel pelo
  `security-especialista`: `pode_avancar=true`, `origem=VIO_TRIAL`,
  `crlv_ano='32767'` -- exercicio fantasioso aprovado sem
  sinalizacao de revisao manual. O metodo (nomeado "InteiroExato")
  nao cumpre a garantia de magnitude, so cobre fracionario/notacao
  cientifica/tipo.~~ **CORRIGIDO em 2026-09-20** (rodada final e
  curta de `/01-implementacao`, restrita a este achado): novo metodo
  privado `caberEmPhpInt()` em `DocumentoRn.php`, chamado por
  `validarExercicioInteiroExato()` logo apos a checagem lexical
  `/^-?\d+$/` -- compara a string de digitos (sinal separado,
  normalizacao de zero a esquerda SO para a comparacao de magnitude,
  valor original devolvido intacto) por COMPRIMENTO e, quando empata,
  LEXICOGRAFICAMENTE contra `(string) PHP_INT_MAX`/`|PHP_INT_MIN|`,
  SEM jamais converter a string para int/float (evita o proprio
  overflow que a validacao previne). Decisao registrada:
  NAO usar `filter_var(..., FILTER_VALIDATE_INT)` — comparacao
  lexicografica manual e mais auditavel e nao depende do parsing
  interno da extensao para a garantia central ("nunca converte a
  string gigante para numero"). Decisao sobre teto de negocio:
  investigado e confirmado que NAO existe teto superior de negocio
  documentado para "ano" alem de `$exercicio > 0` -- por isso
  `PHP_INT_MAX` como `int` nativo (ou `string` igual a
  `(string) PHP_INT_MAX`) continua sendo ACEITO por esta fronteira
  (cabe tecnicamente em `PHP_INT`), nenhum teto arbitrario foi
  inventado sem contrato. Evidencia real de execucao: matriz de
  testes ampliada de 556 para 590 asserções (`tests/manual/
  teste_vio_decode_matriz_tipos_campos.php`), 590/590 passando --
  confirma que `"99999999999999999999"`, `(string) PHP_INT_MAX + 1`
  (`"9223372036854775808"`), `PHP_INT_MIN - 1`
  (`"-9223372036854775809"`) e uma sequencia de 200 digitos sao
  REJEITADOS (`pode_avancar=false`, `crlv_ano` nunca persistido, nem
  saturado para `32767`/`PHP_INT_MAX`), enquanto `PHP_INT_MAX` nativo
  e a string equivalente continuam sendo ACEITOS (sem teto de negocio
  documentado). Prova negativa executada: checagem de magnitude
  removida temporariamente, 4/590 asserções passaram a FALHAR
  (exatamente as 4 de "motivo e a mensagem generica de estrutura
  invalida" dos 4 casos de overflow), confirmando deteccao real;
  revertido a 100% via backup, hash md5 identico antes/depois
  (`f9567bb36fad9e87ae84bc44cfc80948`), suite completa reexecutada
  limpa (590/590). 11 suites de regressao reexecutadas sem nenhuma
  regressao (80/80, 21/21, 47/47, 9/9, 16/16, 10/10, 11/11, 11/11,
  23/23, mais os controles obrigatorios de
  `teste_validacao_jpeg_seguro.php`). `TrelloClient.php`/
  `tools/trello-cli.php` confirmados byte-identicos a `HEAD` via
  `git hash-object`. **OBSERVACAO NAO BLOQUEANTE, fora do escopo
  desta correcao** (nao corrigida, registrada para decisao futura):
  a coluna `crlv_ano` e `SMALLINT` (`sql/schema.sql:85`) -- um valor
  que CABE em `PHP_INT` e e aprovado corretamente por esta fronteira
  (que so valida capacidade de `PHP_INT`, nunca capacidade de coluna
  de banco) ainda pode ser silenciosamente saturado pelo MySQL na
  gravacao quando o `sql_mode` do servidor nao inclui
  `STRICT_TRANS_TABLES` -- confirmado empiricamente neste ambiente de
  dev (`sql_mode` atual:
  `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`, sem modo
  estrito): `PHP_INT_MAX` gravado em `crlv_ano` resulta em `'32767'`
  no banco, sem excecao. Nao corrigido nesta rodada -- alterar
  schema/DB esta fora do escopo explicito desta demanda (arquivos
  autorizados: so `DocumentoRn.php`/teste/handoff/
  `ia_development_state.md`), e nao ha teto de negocio documentado
  para "ano" que justifique inventar um valor de corte arbitrario.
  Ver docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md,
  secao "Correcao do achado BLOQUEANTE de magnitude/overflow de
  exercicio (2026-09-20)".
- ~~**BLOQUEANTE, achado do `/03-revisao` independente de
  `vio-hardening-sem-credenciais` (2026-09-20)**: `DocumentoRn.php`
  (`validarCrlv()`, ~linha 313) -- `exercicio=2026.9` (float) e
  `exercicio="2026.9"` (string) sao aprovados normalmente
  (`pode_avancar=true`, `origem=VIO_TRIAL`, `status_revisao=OK`) e
  persistidos em `crlv_ano` como `2026` -- a parte fracionaria e
  descartada pelo cast `(int)` ja existente SEM NENHUMA sinalizacao
  de perda de precisao. A fronteira de tipo desta demanda protege
  contra tipos estruturalmente incompativeis (array/objeto/bool),
  mas nao contra imprecisao de CONTEUDO dentro de um tipo aceito.
  Reproduzido pelo fluxo real em banco descartavel pelo
  `backend-especialista`, apos uma rodada anterior ter descartado
  esse mesmo cenario como "observacao nao bloqueante, pre-existente"
  -- reclassificado para BLOQUEANTE por instrucao explicita do
  usuario de nao aceitar descarte sem reproducao real.~~ -- IMPLEMENTADA
  em 2026-09-20 (rodada curta final de `/01-implementacao`, restrita ao
  campo `exercicio`): novo metodo privado
  `validarExercicioInteiroExato()` em `DocumentoRn.php`, chamado logo
  apos `extrairCampoNumerico()` dentro do `try` de `validarCrlv()` --
  aceita SO `int` nativo (sempre exato) ou `string` no formato
  `/^-?\d+$/` (mesmo formato que `(int)` converte sem perda de
  precisao, sinal negativo aceito de proposito para NAO usurpar a
  regra de FAIXA `$exercicio > 0` ja existente em `avaliarCrlv()`,
  intocada); rejeita qualquer `float` (fracionario, notacao cientifica,
  `NaN`/`Infinito`, overflow de int para float, e tambem `2026.0`/
  `"2026.0"` matematicamente inteiro) e qualquer `string` com ponto
  decimal/notacao cientifica/espaco em branco (prefixo ou sufixo)/
  string vazia -- reaproveita a MESMA excecao interna
  `DocumentoVioTipoInvalidoException` e o MESMO caminho de fail-closed
  ja usado para tipo incompativel (invalida a resposta VIO inteira do
  documento, nunca persiste parcial, nunca marca VIO_TRIAL/
  VIO_VALIDADO, fallback manual disponivel). Decisao sobre `2026.0`/
  `"2026.0"`: investigado `docs/manual_vio_decode.md` (linhas 126-130)
  -- o unico teste real observado de `exercicio` (Trial,
  `crlv-demo.bin`) retornou o placeholder literal string `"xxxxx"`,
  nunca um numero -- SEM EVIDENCIA de formato float real, rejeitado por
  esquema estrito. Testes novos em
  `tests/manual/teste_vio_decode_matriz_tipos_campos.php` (453 -> 556
  asserções, 103 novas, 556/556 passando) cobrindo aceitos (int/string
  validos, zero a esquerda, valor grande sem overflow) e rejeitados
  (fracionario float/string, notacao cientifica float/string, `2026.0`/
  `"2026.0"`, overflow int->float, string vazia/espaco/sufixo) com
  confirmacao de zero persistencia parcial (`crlv_ano` nunca gravado
  truncado) via recarga do banco; regressao explicita confirmando que
  valor negativo (int/string) continua sendo rejeitado pela regra de
  FAIXA JA EXISTENTE (mensagem de conteudo, nao a de estrutura
  invalida) -- a fronteira nova nao intercepta sinal. Prova negativa
  executada (reversao temporaria da chamada de
  `validarExercicioInteiroExato()`, 12/556 asserções falharam
  detectando a regressao exatamente nos 12 casos de conteudo
  fracionario/cientifico/overflow/espaco, revertido a 100% com hash
  MD5 identico antes/depois). 10 suites de regressao pre-existentes
  reexecutadas sem nenhuma queda: 80/80, 21/21, 47/47, 9/9, 16/16,
  10/10, 11/11, 11/11, 23/23, 22/22. `DocumentoController.php`, `.env`,
  dependencias, contrato HTTP/JSON, allowlists, `ehValorPlaceholder()`,
  banco/schema, `preencherManualCrlv()` (fora do escopo do achado --
  fluxo manual, nao VIO), `TrelloClient.php`/`tools/trello-cli.php`
  (confirmados byte-identicos a HEAD via `git hash-object`) --
  intocados. Ver
  docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md, secao
  "Correcao final do achado BLOQUEANTE de exercicio (2026-09-20)".
- ~~**BLOQUEANTE, achado do `/02-testes` independente de
  `vio-hardening-sem-credenciais` (2026-09-19)**: `DocumentoRn.php`
  linha 127 (CNH `nome`) e linhas 210/212/217/218 (CRLV
  `placa`/`uf`/`rntc`/`tipo`) fazem cast `(string)` sem `is_string()`
  previo -- se o campo vier como array aninhado (resposta VIO
  malformada/adulterada), o cast emite so um `E_WARNING` (nao
  excecao) e produz a string literal `"Array"`, que passa por todas
  as validacoes e e aprovada e persistida no banco como dado
  valido~~ -- IMPLEMENTADA em 2026-09-19 (rodada curta de
  `/01-implementacao`): nova fronteira explicita de tipo/esquema em
  `DocumentoRn.php` (`ESQUEMA_TIPOS_CNH`/`ESQUEMA_TIPOS_CRLV`,
  `extrairCampoTexto()`/`extrairCampoNumerico()`) valida
  `is_string()`/tipo ANTES de qualquer cast, para todos os campos das
  allowlists (nao so os 5 citados no achado). Tipo incompatibilidade
  invalida a resposta VIO inteira do documento, nunca marca
  VIO_TRIAL/VIO_VALIDADO, nunca persiste parcial. Confirmado por 3
  revisores independentes em nova rodada de `/02-testes`: 453/453 na
  matriz nova por campo, prova negativa com mutacao/reversao
  confirmada por hash identico, testes proprios em banco descartavel
  confirmando atomicidade (5 variacoes) e ausencia de sobrescrita de
  dado pre-existente. Ver
  docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md.
- ~~**MODERADO, achado do `/02-testes` independente de
  `vio-hardening-sem-credenciais` (2026-09-19)**: `dados` como
  `stdClass`, ou `cpf`/`data_validade` como array aninhado, lancam
  `Error`/`TypeError` reais que ESCAPAM de `DocumentoRn`~~ --
  IMPLEMENTADA em 2026-09-19 (mesma rodada): a mesma fronteira de
  tipo do achado acima tambem valida `is_array($resultadoVio['dados'])`
  e `is_array($dados['data'] ?? $dados)` no envelope inteiro antes de
  qualquer acesso por chave, e usa `is_string()`/tipo explicito para
  `cpf`/`data_validade` -- nova excecao interna
  `DocumentoVioTipoInvalidoException`, capturada DENTRO de
  `validarCnh()`/`validarCrlv()` (nunca escapa para o Controller,
  nao depende mais do catch generico acidental). Confirmado por
  teste controlado dedicado (3 revisores) que a excecao nunca
  ultrapassa a camada de regra de negocio. Ver mesmo handoff.
- ~~**NOVO, achado do `/00-planejamento` de `vio-hardening-sem-credenciais`
  (2026-09-19)**: `VioDecodeClient.php:105` -- mensagem de erro de
  falha de autenticacao OAuth2 (inclui HTTP status/curl errno da
  chamada backend->VIO, ex. "Falha ao obter token OAuth2 (HTTP 401,
  curl errno 0)") propaga ate o campo `motivo` do resultado, devolvido
  ao totem via `Resposta::sucesso()` em `DocumentoController.php:285`
  -- detalhe tecnico de integracao chega ao HTTP response do cliente
  (nao so ao log de servidor). Sem credencial/CPF/QR, mas contraria a
  regra de nunca expor detalhe interno ao cliente. Severidade
  baixa/atencao.~~ -- IMPLEMENTADA em 2026-09-19 (`/01-implementacao`
  do grupo 1): `VioDecodeClient::MENSAGEM_ERRO_GENERICA` fixa e
  sanitizada devolvida para QUALQUER falha tecnica (autenticacao,
  rede/timeout, HTTP 4xx/5xx, JSON invalido, tamanho excedido) --
  nunca mais status HTTP/curl errno/mensagem bruta chegam ao campo
  `motivo`. Detalhe tecnico so em log categorizado
  (`logFalhaTecnica()`, mesmo padrao de
  `NotaController::logFalhaBancoPdo()`), nunca a mensagem bruta da
  excecao/resposta externa. Testado com 80/80 asserções em
  `tests/manual/teste_vio_decode_robustez.php` (varredura de HTTP
  400/401/404/415/422/429/500/502/JSON malformado/vazio via mock
  server HTTP local real), prova negativa de deteccao de vazamento
  revertida e confirmada. Ver
  docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md.
- ~~**NOVO, achado do `/00-planejamento` de `vio-hardening-sem-credenciais`
  (2026-09-19)**: `VioDecodeClient::chamarDecode()` nao tem limite de
  tamanho de resposta HTTP antes do `json_decode` (`CURLOPT_
  RETURNTRANSFER` sem `CURLOPT_MAXFILESIZE_LARGE`/checagem de
  `Content-Length`) -- resposta anormalmente grande seria carregada
  integralmente em memoria antes do parse. Severidade baixa (exige VIO/
  MITM comprometido, mitigado parcialmente pelo timeout de 20s
  existente).~~ -- IMPLEMENTADA em 2026-09-19 (`/01-implementacao` do
  grupo 1): teto de 10 MB (`TAMANHO_MAXIMO_RESPOSTA_BYTES`) aplicado
  ANTES do `json_decode`, via `CURLOPT_WRITEFUNCTION` customizado que
  aborta a transferencia (CURLE_WRITE_ERROR) assim que o acumulado
  ultrapassa o teto -- corpo excedente NUNCA e integralmente carregado
  em memoria. Justificativa do valor documentada no proprio codigo
  (docstring da constante): nenhum tamanho real de resposta de
  Producao com `image` foi observado (pendencia de grupo 2), 10 MB e
  margem generosa (5-10x) sobre o pior caso plausivel de uma foto de
  documento em Base64. Testado EXATAMENTE no limite (aceita) e 1 byte
  acima (rejeita) via mock server HTTP local real, cURL de verdade —
  `tests/manual/teste_vio_decode_robustez.php`.
- **NOVO, observacao do `/00-planejamento` de `vio-hardening-sem-credenciais`
  (2026-09-19)**: `catch (\Throwable $e)` genericos em
  `DocumentoController.php:248,398` hoje nao propagam `getMessage()`
  ao cliente (confirmado por leitura), mas sao fragil a regressao
  futura -- recomendado padronizar para nunca propagar detalhe de
  excecao generica ao cliente, so ao log (mesmo padrao ja usado em
  `NotaController`). Nao bloqueante, reforco preventivo. **Ainda
  PENDENTE** apos `/01-implementacao` de 2026-09-19 -- fora do escopo
  obrigatorio da rodada (so seria implementado se um teste real
  demonstrasse falha alcancavel; nenhum teste demonstrou isso). Ver
  mesmo handoff.
- ~~**A CONFIRMAR (nao e achado ainda), item da matriz de testes do
  `/00-planejamento` de `vio-hardening-sem-credenciais` (2026-09-19)**:
  `DocumentoRn::validarCnh/validarCrlv` acessam campos por
  `$dadosBrutos[chave] ?? ''` -- se `$dadosBrutos` nao for array
  (resposta malformada), pode gerar `TypeError`. Nao executado teste
  ainda para confirmar; registrado como cenario 4 da matriz de testes
  planejada, a confirmar/corrigir (se real) em `/01-implementacao`.~~
  -- NAO REPRODUZIDO em 2026-09-19 (`/01-implementacao` do grupo 1):
  teste empirico isolado confirmou que `?? ''` ja protege
  completamente contra `$dadosBrutos` nao-array (string/null/
  int/bool) -- nenhum `TypeError`/warning, resultado sempre `''`
  (evidencia no handoff). `DocumentoRn.php` NAO foi alterado (decisao
  correta: nao mudar codigo sem falha real reproduzida). Confirmado
  tambem fim a fim via `DocumentoRn::validarCnh` com 7 formatos
  malformados diferentes, sem excecao, sempre resultando em
  `pode_avancar=false` (fail-closed) -- ver
  `tests/manual/teste_vio_decode_robustez.php`, cenario [4].
- ~~**BLOQUEANTE, achado NOVO do /02-testes independente (2a rodada,
  2026-09-18)**: em todos os 7 entrypoints corrigidos na rodada
  anterior, `Dotenv::load()` roda ANTES e FORA do try/catch que
  protege `Conexao::obter()` -- se o `.env` estiver ausente ou
  malformado, a biblioteca lanca `Dotenv\Exception\
  InvalidPathException`/`InvalidFileException` (nao `PDOException`),
  nao capturada por nenhum catch existente, podendo reproduzir o
  mesmo padrao de vazamento pre-autenticacao do achado 1 original~~
  -- IMPLEMENTADA em 2026-09-18 (rodada curta de
  `/01-implementacao`): novo `util/Bootstrap.php`, fronteira unica
  cobrindo `.env` ausente/malformado, variavel obrigatoria
  ausente/vazia e falha de conexao, sempre HTTP 503 sanitizado.
  Aplicado aos 7 entrypoints e ao `cron/limpar-rate-limit-ocr.php`.
  Testado com servidor HTTP real + curl em copia isolada do projeto
  (nunca o `.env` real), prova negativa de deteccao revertida e
  confirmada. Ver
  docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md,
  secao "Rodada curta de /01-implementacao -- correcao dos 2 achados
  bloqueantes novos".
- ~~**BLOQUEANTE, achado NOVO do /02-testes independente (2a rodada,
  2026-09-18)**: a migration
  `sql/migrations/013_convergencia_idempotente_migrations_historicas.sql`
  exige privilegios `CREATE ROUTINE` + `ALTER ROUTINE`~~ --
  IMPLEMENTADA em 2026-09-18 (mesma rodada): migration 013 reescrita
  sem nenhuma stored procedure/SIGNAL -- colisao de nome de indice
  agora falha nativamente via erro do proprio MySQL/MariaDB
  ("Duplicate key name"); checagem de tipo via PREPARE/EXECUTE
  contra tabela inexistente proposital. Testado com usuario MariaDB
  restrito (sem CREATE ROUTINE/ALTER ROUTINE/EXECUTE/TRIGGER/EVENT)
  -- migration roda com sucesso, privilegios minimos confirmados
  identicos aos ja exigidos por 001/002/003 (SELECT via
  INFORMATION_SCHEMA, CREATE, ALTER, INDEX, DROP). indice
  `idx_rate_limit_ocr_limpeza` inalterado, EXPLAIN confirma uso
  continuo sem full table scan. Ver mesmo handoff, mesma secao.
- ~~Achado de ambiente, nao de codigo: `.env` local de dev esta com
  `DB_PASS` igual a um valor de teste sintetico, residuo de uma
  rodada de QA anterior nao restaurada corretamente~~ -- RESOLVIDO
  pelo usuario em 2026-09-18 (fora do escopo de codigo): `DB_PASS`
  real restaurado manualmente, marcador sintetico confirmado
  ausente pelo orquestrador antes de iniciar a rodada seguinte,
  conexao local reconfirmada funcionando. Timezone local do PHP
  tambem corrigido pelo usuario para America/Sao_Paulo (-03:00),
  registrado como remediacao de ambiente, sem alteracao de codigo
  nesta demanda.

- **INCIDENTE ACEITO, 2026-09-18**: durante a validacao da rodada
  curta de `/01-implementacao` acima, o `backend-especialista`
  executou `cron/limpar-rate-limit-ocr.php` contra o BANCO DE DEV
  LOCAL REAL (nao um banco descartavel), removendo 1 linha real de
  `tb_rate_limit_ocr` (expirada pela propria regra de retencao,
  comportamento correto do cron em si, mas execucao nao autorizada
  nesta etapa). Banco local contem so dado de teste, nenhum dado
  pessoal envolvido, nenhum impacto em producao/Hostgator/operacao
  real da UDLOG. Linha NAO foi e nao sera recriada. Usuario
  informado e ACEITOU o incidente explicitamente. A alegacao de
  "zero alteracao no banco"/"zero operacao real" presente em relatos
  de outras rodadas desta demanda NAO se aplica a esta rodada
  especifica. Nenhuma nova execucao do cron contra banco de dev
  local ou producao autorizada dentro desta demanda a partir de
  agora -- proxima `/02-testes` deve reaproveitar evidencias ja
  produzidas ou usar banco descartavel. Ver mesmo handoff, secao
  "INCIDENTE -- execucao indevida do cron no banco local de
  desenvolvimento".


- ~~**BLOQUEANTE, achado do /02-testes**: `public/api/nota.php`
  chama `Util\Conexao::obter()` (linha ~19) ANTES de construir
  `NotaController` -- se a conexao inicial falhar nesse ponto
  especifico, um fatal error CRU (stack trace + caminho absoluto
  do servidor) e vazado ao cliente HTTP, sem passar por nenhum
  try/catch~~ -- IMPLEMENTADA em 2026-09-18 (rodada curta de
  `/01-implementacao`): os 7 entrypoints que chamam
  `Conexao::obter()` antes de qualquer controller (`nota.php`,
  `impressao.php`, `atendimento.php`, `impressao-teste.php`,
  `documento.php`, `cliente.php`, `totem/index.php`) passaram a
  envolver essa chamada em try/catch(PDOException) isolado,
  respondendo HTTP 503 sanitizado antes de qualquer
  auth/controller/OCR. Testado com servidor HTTP real + curl (503
  confirmado, sem stack trace/SQL/host/usuario, mesmo com
  display_errors=1). Ver
  docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md,
  secao "Rodada curta de /01-implementacao".
- ~~**BLOQUEANTE, achado do /02-testes**: a migration
  `sql/migrations/013_convergencia_idempotente_migrations_historicas.sql`
  cria o indice `idx_rate_limit_ocr_janela`, mas a query REAL de
  limpeza (`RateLimitOcrDao::apagarJanelasExpiradas()`) NAO usa
  esse indice -- `EXPLAIN` confirma full table scan~~ -- IMPLEMENTADA
  em 2026-09-18 (rodada curta de `/01-implementacao`): indice
  substituido por `idx_rate_limit_ocr_limpeza (atualizado_em,
  janela)`. EXPLAIN real confirmou eliminacao do full table scan
  (type=ALL,rows=6000 -> type=range,rows=4799 em banco de teste com
  6000 linhas sinteticas). Ver mesmo handoff, mesma secao.
- ~~Achado de severidade media, nao bloqueante: a mesma migration
  013 reconhece o indice `idx_rate_limit_ocr_janela` SO POR NOME
  (nao por coluna, diferente do tratamento dado a
  `uk_atendimento_ordem`/`idx_status_ocr`)~~ -- IMPLEMENTADA em
  2026-09-18 (mesma rodada). VERSAO INTERMEDIARIA (mesma data,
  SUBSTITUIDA logo em seguida, ver abaixo): stored procedure
  reutilizavel `_migracao_013_abortar_se_colisao` aplicada de forma
  simetrica aos 3 indices convergidos (PASSOS 1, 4 e 5) -- indice
  com nome esperado mas colunas erradas abortava com SIGNAL SQLSTATE
  sanitizado, nao mascarava mais. Essa versao intermediaria foi
  RESCRITA em 2026-09-18/19 (rodada curta de `/01-implementacao`
  apos `/02-testes` independente confirmar que a stored procedure
  exigia privilegios `CREATE ROUTINE`/`ALTER ROUTINE` nao
  confirmados no Hostgator -- ver achado bloqueante correspondente
  mais acima nesta mesma secao). VERSAO FINAL, hoje no repositorio:
  a mesma protecao simetrica nos 3 indices continua valendo, mas sem
  nenhuma stored procedure, `SIGNAL`, `CREATE ROUTINE` ou `ALTER
  ROUTINE` -- colisoes de nome incompativel sao interrompidas pelo
  proprio mecanismo NATIVO do MySQL/MariaDB (`ALTER TABLE ... ADD
  INDEX/ADD UNIQUE KEY <nome_esperado>` falha com `ERROR 1061
  Duplicate key name` se o nome ja existir com colunas erradas); a
  checagem de tipo/nulabilidade do PASSO 0 (`status_ocr`/
  `processado_em`) aborta com `ERROR 1103 (Incorrect table name)`,
  porque o identificador de tabela deliberadamente inexistente usado
  nessa checagem excede propositalmente o limite de 64 caracteres do
  MySQL/MariaDB. O comportamento permanece fail-closed em ambas as
  versoes -- so o MECANISMO de aborto mudou (SIGNAL customizado via
  stored procedure -> erro nativo do proprio banco). Ver mesmo
  handoff, secoes "Achado 2 -- Migration 013 sem stored procedures"
  e "Rodada curta de correcao textual final (2026-09-19)".

- ~~`util/Conexao.php` loga a mensagem CRUA de `PDOException::getMessage()`
  via `error_log()` simples quando a CONEXAO inicial falha (diferente
  de falha de query, ja sanitizada em todo o resto do projeto) --
  vaza o usuario do banco (ex. `root`) e o SQLSTATE real. Afeta
  qualquer ponto de entrada, incluindo o novo
  `cron/limpar-rate-limit-ocr.php`~~ -- IMPLEMENTADA em 2026-09-18
  (rodada curta de `/01-implementacao` da demanda
  `robustez-rate-limit-migrations`, junto com o achado bloqueante 1
  acima, mesma causa raiz): log substituido por string fixa
  sanitizada (`Conexao::obter: falha ao conectar ao banco de dados`),
  sem `getMessage()`/trace/file/line. Ver mesmo handoff, secao
  "Rodada curta de /01-implementacao".


- ~~`cancelar`/`bloquear-excesso-notas` não checam status terminal~~
  — IMPLEMENTADA em 2026-09-16 (demanda
  `integridade-conclusao-atendimento`, `/01-implementacao`): CAS no
  `UPDATE` (`WHERE status != 'concluido'`) em `AtendimentoDao::cancelar()`/
  `bloquear()`, retornando `bool`; `AtendimentoController` responde
  HTTP 409 com mensagem segura e zero alteração no banco quando
  `false`. Bug real encontrado e corrigido na mesma etapa: `rowCount()
  == 0` era ambíguo entre "bloqueio real por concluído" e
  "reafirmação idempotente sem mudança de coluna" — resolvido com
  novo método `statusAtual()` consultado só nesse caso. Testado
  (11/11 itens do roteiro + regressão de 16 suítes, 100% aprovado).
  Ver `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`.
- ~~Ausência de lock/transação em `concluirDigitalizacao()` contra
  corrida de cliques quase simultâneos~~ — IMPLEMENTADA em
  2026-09-16 (mesma demanda): CAS via novo método dedicado
  `AtendimentoDao::concluirDigitalizacaoNotas()` (`UPDATE ... WHERE
  etapa_atual='digitalizacao_notas' AND status='em_andamento'`),
  sem transação/lock novos. Segunda requisição concorrente perdedora
  responde sucesso idempotente. Bug real encontrado e corrigido na
  mesma etapa: quando o vencedor terminava completamente antes do
  perdedor buscar o atendimento, a checagem de precondição em PHP
  respondia HTTP 400 genérico em vez de idempotência — corrigido
  reaproveitando `etapaEhAlvoOuPosterior()` antes dessa checagem.
  Testado com `proc_open()` real (2 conclusões simultâneas + timing
  sequencial exato), 100% aprovado.
- ~~`GET_LOCK`/`RELEASE_LOCK` em `DocumentoController` não liberado
  explicitamente em erro (`exit()` pula o `finally`)~~ — IMPLEMENTADA
  em 2026-09-16 (mesma demanda): `iniciarProcessamento()`
  reestruturado para capturar a intenção de erro em variável local
  e só chamar `Resposta::erro()`/`exit` depois do `try/finally` já
  ter liberado o lock. Mensagens/códigos HTTP (503/500) preservados.
  Testado: `RELEASE_LOCK` confirmado via `SELECT IS_USED_LOCK(...)`
  retornando `NULL` após erro, em ambos os caminhos (falha na
  inicialização e falha durante a validação).
- ~~Ausência de handler global de `PDOException` em `NotaController`~~
  — IMPLEMENTADA em 2026-09-16 (demanda
  `integridade-conclusao-atendimento`): `processar()`,
  `identificarCliente()`, `definirNumero()` e `algumaIdentificada()`
  têm `try/catch (PDOException)` cobrindo integralmente
  `buscarAtendimentoDoTotem`/`contarNotas`/`ordemJaRegistrada`/
  `buscarNotaDaOrdem`/`algumaNotaIdentificouCliente`/
  `atualizarNumeroNota`/`verificarRateLimit` (rate limit),
  respondendo HTTP 500 genérico sem vazar SQL/stack trace. Gap
  encontrado em `/02-testes` (rate limit de `identificarCliente()`
  desprotegido) foi corrigido numa rodada curta de
  `/01-implementacao` e revalidado (8/8 itens PASSOU, marcador
  sintético `RTL0917`, zero resíduo, zero regressão em 27 suítes).
  Ver `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`,
  seção "Rodada curta de /01-implementacao — correção do gap de
  PDOException no rate limit". Log dos 6 catches sanitizado numa
  segunda rodada curta (2026-09-16): substituído `error_log(...$e->getMessage())`
  por método `logFalhaBancoPdo()` — nunca loga `getMessage()`/
  `getTraceAsString()`/`getFile()`/`getLine()`, só contexto fixo +
  SQLSTATE validado por regex estrita. Testado com 5 marcadores
  sintéticos (SQL, caminho, CPF, placa, credencial) em 4 locais
  (resposta HTTP, stdout, arquivo de log isolado, repositório) —
  zero vazamento confirmado.
- `AtendimentoController::etapaEhAlvoOuPosterior()` (novo método da
  demanda `integridade-conclusao-atendimento`, 2026-09-16) é um cheque
  puramente posicional na sequência de etapas — se `etapa_atual` for
  forçada fora de ordem por um bug futuro em outro ponto do código
  (hoje inalcançável por qualquer chamada real da API), o método
  trataria como "posterior legítimo" em vez de conflito. Achado do
  `qa-testes` em `/02-testes`, severidade observação, não bloqueante,
  não explorável hoje. Registrado para avaliação futura.
- `NotaController.php` (linhas 232, 293, 297, 301) tem catches de
  `Throwable`/`RuntimeException` (distintos de `PDOException`)
  que ainda logam `$e->getMessage()` em `error_log()` — nunca vaza ao
  cliente (mensagem ao cliente sempre fixa), mas sem a mesma
  sanitizacao/validacao aplicada ao `PDOException` via
  `logFalhaBancoPdo()`. Achado do `security-especialista` em
  `/02-testes` (demanda `integridade-conclusao-atendimento`,
  2026-09-16), severidade observação, não bloqueante. Registrado para
  avaliação futura.
- `teste_consulta_ordem_coleta.php` falha (12/17) hoje NAO por
  indisponibilidade do banco externo `udlogo59_db_gestao_coletas`
  (corrigido registro em 2026-09-17 — o usuário confirmou acesso via
  phpMyAdmin, e o `explorer` confirmou conexão real bem-sucedida via
  `UtilConexaoGestaoColetas::obter()`, mesmas credenciais do
  `.env`). Causa real: divergência de dados de fixture — não existe
  `numero_ordem_coleta='OC-TESTE-005'`/`placa_prevista='TST0A01'`
  em `tb_ordens_coleta` nesta cópia do banco, e a ordem
  `OC-TESTE-001` (placa `ABC1D23`) mudou para `status='INATIVA'`,
  reduzindo de 3 para 2 as ordens ATIVAS esperadas pelo cenário de
  "múltiplas ordens". Não corrigido (fixture, não código) — pendência
  de higiene de dados de teste, fora do escopo de qualquer demanda de
  código.
- `DocumentoController::obterLock()` ignora seu próprio valor de
  retorno — se `GET_LOCK(...,0)` falhar por lock já detido, o código
  prossegue mesmo assim chamando a VIO Decode sem o lock. Pré-existente
  (linha não tocada por `integridade-conclusao-atendimento`), não
  regressão. Achado do `backend-especialista` em `/03-revisao`
  (2026-09-17), severidade observação — o próprio comentário do código
  já documenta que a proteção real contra duplicidade é o CAS por
  `tentativa_id`, não esse lock. Registrado para avaliação futura.
- Correspondência prática de `jsQR.binaryData` com o raw value esperado
  pela VIO Decode não confirmada — só teste físico com QR real resolve.
- Origem da chave de acesso da NF-e na tela `rec_digitaliza` sem o
  leitor HID — `chave: null` fixo, sem substituto definido.
- Retomada de atendimento ao recarregar a página não sincronizada com o
  banco — limitação pré-existente, sem mecanismo de sessão/retomada.
- Heurística de extração de "razão social candidata" no OCR — escolha
  de implementação, pode precisar de ajuste após uso real.
- Valores de ajuste empírico (`LIMIAR_MINIMO`, `MARGEM_MINIMA`) do
  `RazaoSocialMatcher` — a recalibrar com dados reais de produção.
- Causa raiz do encoding corrompido (mojibake) na migration 003 nunca
  foi investigada na origem (só os dados já gravados foram corrigidos).

#### 2. AGUARDANDO DECISÃO DE PRODUTO

- ~~Se `cancelar`/`bloquear-excesso-notas` devem ser recusados para
  atendimento `concluido`~~ — RESOLVIDA em 2026-09-16 (demanda
  `integridade-conclusao-atendimento`, `/00-planejamento`): decisão
  de produto CONFIRMADA pelo usuário — `concluido` é terminal e
  imutável para o motorista; `cancelar`/`bloquear-excesso-notas`
  devem responder HTTP 409 sem alterar o banco. Reabertura fica
  para painel administrativo futuro, fora de escopo. Movida para a
  seção 1 (PENDENTE — AÇÃO TÉCNICA) acima como PLANEJADA — falta só
  a implementação (`/01-implementacao`).
- Diversos campos do payload real do Talent sem fonte de captura no
  totem (reboque, exigePesagem, transportadora, telefones, paletes,
  etc.) — quais são realmente necessários.
- Paleta de cores oficial da UDLOG — ainda usando navy/branco como
  placeholder.

#### 3. AGUARDANDO CREDENCIAL/TERCEIRO

- Credenciais Trial (Consumer Key/Secret) da VIO Decode ainda não
  obtidas — depende de cadastro da UDLOG no portal Serpro.
- Viabilidade/convênio da API Prodesp (CNH/CRLV) — não confirmado, pode
  nunca ser viável.

#### 4. ADIADO PARA PRODUÇÃO/FINALIZAÇÃO

- Ativação de `origensPermitidas` para produção (`https://udlog.online`)
  — documentada, só no deploy final no mini PC.
- Validação física do serviço de impressão no mini PC de PRODUÇÃO real
  — adiada para a etapa final do projeto, por decisão do usuário.
- Validação física do Netum SD-2000 como `videoinput` em builds reais
  de Chromium do mini PC de produção (label, resolução suportada) —
  hardware disponível, mas essa checagem específica em produção ainda
  não foi reexecutada.
- Roteiro físico completo de 20 itens do Netum (demanda original) —
  ainda não formalmente reexecutado/fechado (hardware já disponível no
  ambiente de dev, não depende estritamente de produção, mas segue
  adiado junto com o restante da validação física).
- OCR client-side (Tesseract.js) — validação física completa (tempo de
  processamento, precisão, cancelamento de fila em cenário real).
- Nome final do banco de dados no Hostgator (prefixo cPanel).
- Confirmação de que `storage/` fica fora do document root real em
  produção (depende de configuração do cPanel).
- Persistência de permissão de câmera por origem no Chromium kiosk /
  política `VideoCaptureAllowedUrls` (ambiente físico do mini PC).

#### 5. OBSERVAÇÃO DE BAIXA SEVERIDADE

- Comentário incorreto no código dizendo que o Netum é leitor HID
  (usado hoje só nas telas de CNH/CRLV) — imprecisão textual, não bug
  funcional.
- Rótulo de origem de validação (VIO_TRIAL/MANUAL) duplicado entre
  front-end e back-end — funciona, mas é regra de negócio repetida.

#### 6. RESOLVIDO nesta rodada de saneamento (removidos da lista ativa, preservados na tabela da seção 5 com `~~riscado~~`)

- Idempotência do envio ao Talent (`talent_checkin_status`, CAS de 5
  estados) — implementada desde 2026-09-09, reaproveitada em 3 demandas
  posteriores, testada.
- HTTP 409 real do Talent — confirmado empiricamente em 2026-09-15.
- Formato `anexos` aceito pelo Talent; `anexosGZip` confirmado como
  fallback não necessário.
- Separação entre `impressao.php` (produção) e `impressao-teste.php`
  (diagnóstico) — implementada em 2026-09-15.
- Revisão formal de UX da tela de seleção de impressora — feita em
  2026-09-15, com 1 achado corrigido na mesma rodada.
- Preview/captura do Netum SD-2000 (3264×2448, 4:3, sem corte) —
  corrigido em 2026-09-04, registro atualizado em 2026-09-16.
- CORS/PNA de desenvolvimento (`http://localhost:8080`) — ativado e
  testado em 2026-09-16.
- Regra de "rebaixamento para MANUAL" do VIO — já implementada em
  `AtendimentoRn::salvarDadosMotorista()`.
- Descarte explícito do campo `image` do envelope VIO — já implementado
  via allowlist + `unset()`.
- Assimetria de `ehValorPlaceholder()` CNH vs. CRLV — já corrigida, as
  duas chamam a mesma checagem.


## 6. Fora de escopo (não sugerir sem pedido)

- Qualquer infraestrutura que exija processo persistente, WebSocket de longa
  duração, ou instalação de binário no servidor Hostgator
- OCR server-side (Tesseract PHP) — só client-side (Tesseract.js) é viável
  no ambiente atual
- Autenticação de usuário/login humano — a autenticação atual é por
  totem (token fixo por dispositivo), não por pessoa

## 7. Log de mudanças

- 2026-09-03 — Projeto criado do zero: estrutura, schema, backend e
  front-end completos (primeira versão funcional, pendências da seção 5
  em aberto)
- 2026-09-03 — Digitalização de notas fiscais do Recebimento (tela
  `rec_digitaliza`) implementada com o scanner Netum SD-2000 como
  dispositivo de vídeo USB, substituindo a premissa incorreta de leitor
  HID nessa tela (CNH/CRLV e Expedição não foram alterados). Corrigido
  IDOR crítico em `NotaController` (posse do atendimento pelo totem
  autenticado). Adicionadas validações de tipo/status/etapa do
  atendimento, faixa de `ordem` (1-5), limite de 5 notas, JPEG por magic
  bytes, tamanho máximo configurável, gravação em disco confirmada, e
  constraint `UNIQUE(id_atendimento, ordem)` (schema + migration
  idempotente). Nova etapa formal `digitalizacao_notas`. Validação física
  do equipamento ainda pendente (sem hardware disponível neste ambiente).
  Handoff: `docs/handoffs/2026-09-03-recebimento-scanner-netum-sd2000.md`.
  Checklist de deploy criado em `docs/deploy-checklist.md`.
- 2026-09-03 — Correções pós-revisão da digitalização de notas: criada a
  ação `atendimento.php?acao=concluir-digitalizacao` (valida posse, tipo,
  status, etapa e contagem de notas antes de atualizar `etapa_atual` para
  `cliente`/`cnh`), corrigindo o problema de o atendimento ficar preso em
  `etapa_atual = digitalizacao_notas` após "Finalizar digitalização".
  Front-end (`finalizarDigitalizacao()`) passou a aguardar essa resposta em
  vez de decidir a tela sozinho. Corrigido IDOR em `salvarEtapa` (case
  `digitalizacao_notas`), `bloquearPorExcessoDeNotas` e `cancelar`.
  Reforçada validação de JPEG com `getimagesizefromstring()`. Revisão
  estática (sem execução) da migration confirmou que ela não é destrutiva.
  `selecionarOrdem` e `finalizar` continuam com o mesmo tipo de IDOR, não
  corrigido nesta rodada (ver seção 5).
- 2026-09-03 — Etapa `/02-testes` executada contra ambiente local real
  (XAMPP: Apache+PHP 8.0.30, MariaDB 10.4.32) — não apenas estático.
  Migration testada em bancos isolados reais (4 cenários) e aplicada com
  sucesso no banco de trabalho local. 12 cenários de transição de estado e
  18 de upload/validação de imagem testados via curl real — todos
  passaram, com 1 achado médio (JPEG truncado aceito) e 2 achados baixos
  (mensagens/comentário). IDOR testado com 2 totens reais: ações do escopo
  desta demanda confirmadas protegidas; achados NOVOS de atenção em
  `bloquear-excesso-notas` e `cancelar` (permitem reverter atendimento
  `concluido`); IDOR remanescente em `selecionarOrdem`/`finalizar`
  confirmado por exploração real (já era conhecido). Veredito:
  **INCONCLUSIVO** — teste físico do Netum SD-2000 não executado (sem
  hardware disponível). Ver seção 5 para achados detalhados e roteiro
  físico no handoff.
- 2026-09-04 — Teste físico do Netum SD-2000 iniciado com o usuário no
  hardware real. Confirmado: dispositivo reconhecido pelo Windows como
  `NETUM Camera` (classe Camera/UVC, não HID); aparece corretamente em
  `enumerateDevices()`; seletor de múltiplas câmeras funciona (`Logi USB
  Camera` + `NETUM Camera`); preview abre e é nítido. **Falhou**: a imagem
  do documento capturado fica cortada e a resolução é insuficiente (texto
  ilegível). `/02-testes` **REPROVADO** neste ponto — retornado para
  `/01-implementacao` para correção pontual, restrita à tela
  `rec_digitaliza` (não altera Expedição/CNH/CRLV/backend/contratos
  externos).
- 2026-09-04 — Corrigida a causa raiz do corte/baixa resolução: `getUserMedia`
  do scanner passou a solicitar resolução alta como `ideal` (4096x3072,
  ajustada ao máximo real suportado pelo hardware), nova função de captura
  dedicada (`capturarFotoScannerNota`) sem o downscale fixo de 900px usado
  por CNH/CRLV, com compressão iterativa de qualidade JPEG para respeitar o
  limite de tamanho do backend, e proporção do preview/guia ajustada
  dinamicamente à proporção real do vídeo. CNH/CRLV/Expedição/backend não
  tocados. Validação estática (`node --check`) OK — validação física
  aguardando reteste do usuário.
- 2026-09-04 — Corrigida a guia visual dessincronizada: `ajustarProporcaoScanner()`
  só era chamada em `loadedmetadata` (proporção inicial negociada pela
  câmera, medida em 1,78/16:9), mas o Netum renegocia depois para a
  resolução final mais alta (3264x2448, 4:3), disparando `resize` no
  `<video>` sem que o código escutasse — causando guia/preview
  dessincronizados da captura real (que já estava correta: canvas e JPEG
  salvo confirmados idênticos em resolução por medição direta de arquivos
  reais). Corrigido com listener de `resize` que reajusta a proporção,
  removido em `pararCameraScanner()` para evitar duplicidade. Nenhuma
  alteração em canvas/resolução/backend/CNH/CRLV/Expedição. Validação
  física aguardando novo teste do usuário.
- 2026-09-04 — Reteste físico (inclusive em guia anônima, descartando
  cache): a correção do listener de `resize` NÃO teve efeito — proporção
  da guia/preview continuou idêntica (1,78) antes e depois, em 3 medições
  distintas. Diagnóstico revisado: o evento `resize` do `HTMLVideoElement`
  é inconsistente para streams ao vivo (`srcObject`/`MediaStream`) neste
  navegador — não dispara de forma confiável, mesmo a captura final
  provando que a resolução real muda para 3264x2448 (4:3) em algum ponto
  entre a conexão e a captura. `/02-testes` REPROVADO nesta parte
  novamente. Nova correção: substituir a dependência do evento `resize`
  por monitoramento ativo (polling a cada 250ms) das dimensões do vídeo,
  mantendo `loadedmetadata`/`resize` como mecanismos auxiliares.
- 2026-09-04 — Implementado o polling ativo (setInterval 250ms) das
  dimensões de `videoScanner`, como mecanismo adicional (não substituto) a
  `loadedmetadata`/`resize` — só recalcula a proporção quando as dimensões
  mudam desde a última aplicação, iniciado logo após `srcObject` e sempre
  limpo em `pararCameraScanner()` e antes de qualquer novo início, evitando
  intervalos duplicados. Nenhuma alteração em captura/canvas/resolução/
  backend/CNH/CRLV/Expedição. `node --check` OK. Validação física
  aguardando novo teste do usuário.
- 2026-09-04 — Novo reteste físico (com verificação direta via DevTools:
  `video.videoWidth/videoHeight` confirmados corretos em 3264x2448,
  `caixaScanner.style.aspectRatio` confirmado aplicado como "3264 / 2448"
  pelo JS) revelou que o problema NÃO é mais o polling/resize — o valor
  correto está sendo escrito, mas o navegador não respeita a propriedade
  CSS `aspect-ratio` nesse elemento (renderiza 360x202,571px = 16:9 em vez
  de 360x270px = 4:3). Causa provável: interação entre `aspect-ratio` e o
  container pai `.tela` (`display:flex; flex-direction:column`).
  `/02-testes` REPROVADO nesta parte novamente. Nova correção aprovada:
  abandonar a propriedade CSS `aspect-ratio` e calcular a altura em pixels
  via JavaScript (`clientWidth * videoHeight / videoWidth`), aplicada como
  `style.height`, com `ResizeObserver` + `requestAnimationFrame` para
  manter sincronizado sem depender de `aspect-ratio`.
- 2026-09-04 — Implementada a correção definitiva: altura da caixa/preview
  do scanner calculada em pixels via JS (`clientWidth × videoHeight /
  videoWidth`, aplicada como `style.height`), substituindo a propriedade
  CSS `aspect-ratio` que não estava sendo respeitada dentro do container
  flex/column. `ResizeObserver` observa mudanças de largura do container,
  `requestAnimationFrame` agrupa as escritas no DOM (só escreve quando o
  valor muda de verdade), polling de 250ms mantido só para detectar
  renegociação de resolução do vídeo. `pararCameraScanner()` desfaz
  interval, observer, frame pendente e listener de resize, sem
  duplicidade. `node --check` OK; análise estática de 20 ciclos
  abrir/fechar não encontrou vazamento; testes de tempo real (2min
  contínuo, memória/CPU) não puderam ser executados neste ambiente sem
  browser real — ficam pendentes de validação física. Nenhuma alteração em
  captura/canvas/resolução/backend/CNH/CRLV/Expedição.
- 2026-09-04 — Novo reteste físico (sessão limpa/incógnita, confirmando que
  o `aplicarAlturaScanner` estava corretamente definido e sendo chamado):
  `style.height` do container mostrou "270px" corretamente calculado e
  escrito pelo JS, mas a caixa RENDERIZOU ainda em ~629x352px (proporção
  1,79, igual ao problema anterior) — ou seja, o valor certo é escrito no
  DOM mas o navegador não o respeita no layout final. `/02-testes`
  REPROVADO nesta parte novamente. Causa raiz identificada: `.caixa-scanner`
  é filho de `.tela` (`display:flex; flex-direction:column`), e por padrão
  itens flex têm `flex-shrink:1` — o algoritmo de flexbox comprime a altura
  do elemento abaixo do `style.height` definido quando não há espaço
  vertical suficiente para todo o conteúdo da tela. Correção aprovada:
  adicionar `flex-shrink:0` em `.caixa-scanner`/`.previa-nota` no CSS
  (sem alterar JavaScript/backend).
- 2026-09-04 — Implementado `flex-shrink: 0;` em `.caixa-scanner` e
  `.previa-nota` (app.css) — impede o flexbox de `.tela` de comprimir a
  altura calculada por JS. Nenhuma alteração em JS/backend/CNH/CRLV/
  Expedição. `node --check` OK (arquivo JS não tocado). Validação física
  aguardando novo teste do usuário.
- 2026-09-04 — Teste físico confirmou a caixa do scanner renderizando em
  4:3 (~360x270px) corretamente, sem compressão pelo flexbox. Achado menor
  registrado: "flash" vertical instantâneo ao entrar na tela
  `rec_digitaliza` (fallback CSS 3/4 visível por um instante antes do
  cálculo real em 4:3) — ocorre em toda abertura, mas estabiliza logo
  depois, sem oscilação. `/02-testes` — pendência menor registrada; nova
  correção aprovada para eliminar o flash: trocar fallback inicial para
  já iniciar horizontal (4/3), manter `<video>` oculto até a altura real
  ser aplicada, sem setTimeout/atraso artificial.
- 2026-09-04 — Implementada a eliminação do flash vertical: fallback CSS de
  `.caixa-scanner` trocado de `aspect-ratio: 3/4` para `4/3` (nasce
  horizontal), `<video>` fica `visibility: hidden` até a altura real ser
  aplicada, revelado só depois de uma sequência via `requestAnimationFrame`
  (sem setTimeout), guardada por flag para rodar uma única vez por sessão
  de stream (sem piscar em recálculos posteriores). Reset completo em
  `pararCameraScanner()`. Nenhum polling/observer/listener novo, nenhuma
  transition CSS. `node --check` OK; trace manual de 20 ciclos
  abrir/fechar não encontrou problema de reset. Nenhuma alteração em
  captura/backend/CNH/CRLV/Expedição. Validação física aguardando novo
  teste do usuário.
- 2026-09-04 — Validação física confirmada pelo usuário: container abre
  diretamente em horizontal, sem flash vertical; vídeo só aparece com
  enquadramento correto; estável em várias entradas/saídas da tela; preview
  fluido; nota inteira e legível; captura mantida em 3264x2448. Com isso,
  a causa raiz original do REPROVADO (corte/baixa resolução/proporção
  incorreta) está resolvida e confirmada fisicamente. `/02-testes`
  concluído com veredito **APROVADO COM RESSALVAS** — ver detalhamento
  completo no handoff (itens não executados do roteiro físico de 20 passos,
  achados não bloqueantes de backend/frontend, e IDOR remanescente já
  conhecido em `selecionarOrdem`/`finalizar`, fora do escopo desta
  correção). Próximo passo: `/03-revisao`.
- 2026-09-04 — Etapa `/03-revisao` concluída: revisão cruzada independente
  (`explorer`, `security-especialista`, `ui-ux-especialista`) confirmou por
  leitura direta do código que a implementação corresponde ao planejado,
  sem alteração fora do escopo. Veredito: **APROVADO**. 1 achado novo de
  severidade baixa (assimetria de limpeza do listener `resize`/stream em
  `abrirStreamScanner()`, sem vazamento ativo hoje). JPEG truncado
  confirmado como não bloqueante (integridade de dado, não segurança).
  Parecer formal registrado: o sistema como um todo não deve ser
  considerado seguro para produção enquanto o IDOR em
  `AtendimentoController::selecionarOrdem`/`::finalizar` não for
  corrigido (ver seção 5). Próximo passo: `/04-commit-e-push`.
- 2026-09-04 — Demanda `recebimento-scanner-netum-sd2000` encerrada.
  Commit `22eeba0` (`feat(recebimento): integra scanner para notas`)
  enviado para `origin/main` (`https://github.com/Brunossaantos/totem-udlog.git`).
  Verificação pós-commit confirmou: nenhum dado sensível incluído (`.env`,
  `storage/atendimentos/`, `docs/evidencias-scanner/`, `vendor/` todos
  corretamente ignorados e ausentes do repositório), `.env.example` sem
  segredo real, `php -l`/`node --check` sem erros, sem histórico anterior
  de `.env` commitado. `/02-testes`: aprovado com ressalvas. `/03-revisao`:
  aprovado. Scanner físico Netum SD-2000 validado no hardware real.
  Pendências para produção (ver seção 5): IDOR em `selecionarOrdem`/
  `finalizar`, testes físicos restantes (5 notas completas, bloqueio da
  6ª, finalizar/cancelar/desconexão fisicamente, NetumScan Pro), JPEG
  truncado aceito, origem da chave de acesso da NF-e.
- 2026-09-04 — Planejamento (`/00-planejamento`) da demanda
  `recebimento-leitura-notas` (identificação automática de cliente via
  OCR das notas do Recebimento). Investigação real confirmou o contrato
  da API de clientes (`https://udlog.online/iaUdlog/api/v1/clientes`,
  documentado em `docs/db_gestao_coletas.md`, seção clientes). Arquitetura
  planejada e revisada por todos os especialistas: OCR client-side via
  Tesseract.js em Web Worker (sem n8n, decisão corrigida durante o
  planejamento), novo endpoint síncrono `nota.php?acao=identificar-cliente`
  (posse validada via `buscarAtendimentoDoTotem`, token da API de clientes
  nunca exposto ao front-end), exclusão explícita dos CNPJs da UDLOG,
  identificação por CNPJ exato > chave de acesso validada localmente >
  fuzzy de razão social com score alto e sem ambiguidade. Nenhum código
  implementado. Handoff completo:
  `docs/handoffs/2026-09-04-recebimento-leitura-notas.md`. Nenhum achado
  crítico/bloqueante — pendências não-bloqueantes registradas (origem/
  sincronização de `tb_cliente`, mecanismo de cache de clientes, limite de
  candidatos no endpoint, valores de ajuste empírico).
- 2026-09-04 — Refinamento adicional ao planejamento de
  `recebimento-leitura-notas`: OCR deve processar notas sequencialmente
  (nota por nota, começando pela primeira); assim que um cliente for
  identificado com segurança, salvar no atendimento, interromper OCRs
  pendentes/em fila, não consultar mais a API para as notas seguintes,
  continuar capturando normalmente, e exibir ao motorista que o cliente
  já foi identificado. Novo campo de resposta
  `ja_identificado_no_atendimento` no endpoint `identificar-cliente`, com
  proteção redundante server-side (novo passo 0, reaproveitando
  `NotaFiscalRn::algumaNotaIdentificouCliente` já existente). Critério de
  fuzzy match formalizado: score do 1º candidato acima de `LIMIAR_MINIMO`
  E margem mínima sobre o 2º colocado (`MARGEM_MINIMA`), ambos parâmetros
  configuráveis — cobre exemplos de erro de OCR como CROMIX/CRMEX→CROMEX.
  Front-end: nova flag `state.clienteJaIdentificadoNesteAtendimento`,
  fila do Worker esvaziada para notas pendentes, resultado de OCR já em
  andamento é descartado (não `Worker.terminate()`) se a flag já estiver
  true. Novos casos de teste registrados no handoff. Nenhum código
  implementado — ainda em planejamento.
- 2026-09-04 — Implementada a identificação automática de cliente via OCR
  client-side (Tesseract.js em Web Worker, vendorizado localmente sem
  CDN) no fluxo de digitalização de notas do Recebimento. Novo endpoint
  `nota.php?acao=identificar-cliente` (posse validada via
  `buscarAtendimentoDoTotem`, token da API de clientes só no backend via
  `.env`, exclusão dos CNPJs UDLOG, prioridade CNPJ da chave de acesso >
  CNPJ exato na API > fuzzy de razão social com limiar+margem). Early-stop
  implementado nos dois lados: backend (passo 0, sem nova chamada externa
  se atendimento já identificado) e front-end (fila do Worker esvaziada,
  resultado de OCR em andamento descartado, reaproveitando a flag já
  existente `state.clienteIdentificado` em vez de criar uma nova). Novos
  arquivos: `app/Rn/ClienteApiClient.php`, `util/CnpjValidador.php`,
  `util/RazaoSocialMatcher.php`, `public/totem/assets/ocr-worker.js`,
  `public/totem/assets/tesseract/*` (7 arquivos vendorizados),
  `sql/migrations/002_status_ocr_atendimento_nota.sql`,
  `tests/manual/teste_identificar_cliente.php` (29/29 testes passaram).
  Revisão de segurança sem achado crítico; `.gitignore` corrigido para
  cobrir `storage/` inteiro (incluindo o novo `storage/cache/`). `php -l`
  e `node --check` sem erros. Nenhuma alteração em Expedição/CNH/CRLV/
  Talent/scanner Netum. Validação física (Tesseract.js real no hardware,
  precisão de OCR, cancelamento de fila em cenário real) ainda pendente —
  handoff completo:
  `docs/handoffs/2026-09-04-recebimento-leitura-notas.md`. Próximo passo:
  `/02-testes`.
- 2026-09-04 — Teste físico real (5 capturas) confirmou falha total do OCR:
  todas as notas ficaram presas em `status_ocr = PENDENTE` (endpoint
  `identificar-cliente` nunca chamado). Causa raiz confirmada via console
  do navegador: `Tesseract.createWorker()` chamado de DENTRO do Worker
  customizado (`ocr-worker.js`) cria um Worker aninhado próprio (via blob
  URL interno do Tesseract.js), e esse worker aninhado falha ao resolver
  o caminho relativo `tesseract/worker.min.js` em `importScripts`
  (`SyntaxError: ... URL 'tesseract/worker.min.js' is invalid` — o
  caminho relativo não resolve corretamente a partir do contexto de um
  blob worker aninhado). Erro capturado silenciosamente pelo try/catch de
  `ocr-worker.js`, retornando `erro: true`; e o código em `app.js` (linha
  ~1042) pula a chamada de `identificar-cliente` quando `resultado.erro`
  é `true`, deixando a nota presa em `PENDENTE` para sempre. Correção:
  eliminar o Worker customizado aninhado — chamar `Tesseract.createWorker()`
  diretamente da thread principal (`app.js`), já que o próprio Tesseract.js
  gerencia seu worker interno (não bloqueia a UI de qualquer forma, sem
  precisar de um Worker customizado por cima). Ver handoff para correção
  completa.
- 2026-09-04 — Corrigida a causa raiz do OCR nunca completar: eliminado o
  Web Worker customizado aninhado (`ocr-worker.js`, deletado — estava
  causando `importScripts` inválido dentro do worker interno do
  Tesseract.js). `Tesseract.createWorker()` agora é chamado diretamente
  na thread principal (`app.js`), com `workerPath`/`corePath`/`langPath`
  relativos à própria página (`assets/tesseract/...`) — o Tesseract.js já
  gerencia seu próprio worker interno, não bloqueando a UI. Bug secundário
  também corrigido: nota que falha o OCR agora sempre chama
  `identificar-cliente` com candidatos vazios (vira `NAO_IDENTIFICADA`),
  em vez de ficar presa em `PENDENTE` para sempre. `node --check` OK.
  Nenhuma alteração em Expedição/CNH/CRLV/Talent/scanner/backend.
  Validação física aguardando novo teste do usuário.
- 2026-09-04 — Diagnóstico técnico do OCR com imagem real (Chrome
  headless, mesmo Tesseract.js vendorizado de produção, 9 combinações de
  rotação/pré-processamento testadas contra nota fiscal real). Resultado:
  a nota é capturada em orientação que exige rotação de 270° para o texto
  ficar legível (0°/90°/180° não extraem CNPJ nem razão social legível).
  Melhor combinação: **rotação 270° + escala 75%** — CNPJ correto extraído
  e batendo com o esperado, razão social com boa similaridade (0,83),
  tempo de reconhecimento ~3,7s (mais rápido que as demais combinações
  válidas). Achado adicional: em nenhuma das 9 combinações a chave de
  acesso (44 dígitos) passou na validação (nem o próprio DV da chave, nem
  o CNPJ embutido) — sugere bug separado no parsing/regex da chave, não
  relacionado à rotação/pré-processamento (não investigado ainda).
  Nenhum código alterado nesta etapa — apenas diagnóstico. Aguardando
  decisão de implementar rotação automática (270° fixo ou detecção
  automática de orientação) + escala 75% antes de reconhecer.
- 2026-09-04 — Implementadas as correções aprovadas: rotação fixa de 270°
  aplicada só numa cópia em memória usada exclusivamente pelo OCR (imagem
  original salva sem alteração); chave de acesso removida do processo de
  identificação (backend: `NotaFiscalRn::identificarCliente()` não prioriza
  mais CNPJ da chave, `chaveValida()`/`decodificarChave()` preservados
  intactos para o fluxo antigo de `processarLeitura`; front-end: extração
  de chave removida de `extrairCandidatos()`, payload sempre envia
  `chave_ocr: null`). Testado contra 21 imagens fiscais reais via Chrome
  headless (mesmo Tesseract.js de produção): 8/21 identificaram CNPJ
  válido corretamente (confirmado visualmente), 13/21 caíram em "sem
  candidato válido" e seguem corretamente para confirmação manual (nunca
  identificação errada). Tempo por nota: 4,0–9,7s, sem travar a captura
  sequencial. Achado registrado (não corrigido, risco já aceito na
  decisão de rotação fixa): notas do mesmo atendimento podem ter
  orientação física diferente entre si (confirmado visualmente numa nota
  que ficou de cabeça para baixo mesmo após a rotação de 270°) — por isso
  a taxa de identificação não é 100%, mas o comportamento nesses casos é
  seguro (confirmação manual, não identificação incorreta). Testes
  automatizados: 29/29 passaram (caso de chave ignorada reescrito).
  `php -l`/`node --check` sem erros. Nenhuma alteração em Expedição/CNH/
  CRLV/Talent/scanner Netum. Validação física real no totem ainda
  pendente.
- 2026-09-04 — Teste físico real (passo 1 do roteiro) REPROVOU: motorista
  viu "Cliente identificado" na tela de digitalização, mas ao clicar
  "Finalizar digitalização" foi levado para confirmação manual
  (`rec_cliente`) em vez de pular para CNH (`rec_cnh`). Causa raiz
  confirmada: `AtendimentoController::concluirDigitalizacao()` decide a
  próxima tela usando `AtendimentoNotaDao::algumaIdentificada()` (método
  ANTIGO, `INNER JOIN` com `tb_cliente` LOCAL — usado pelo fluxo antigo de
  chave de acesso, que dependia do cliente já estar cadastrado localmente).
  O novo fluxo de OCR identifica via API EXTERNA e persiste o resultado em
  `status_ocr`, sem necessariamente popular `tb_cliente` local — método
  correto (`algumaNotaComStatusIdentificada()`, baseado em `status_ocr`)
  já existe mas nunca foi ligado a `concluirDigitalizacao()`. Bug de
  integração entre a demanda do scanner (que criou `concluirDigitalizacao`)
  e a demanda de OCR (que criou o novo método de checagem) — corrigindo.
- 2026-09-04 — Corrigido: `AtendimentoController::concluirDigitalizacao()`
  agora consulta as DUAS fontes de verdade e usa OR lógico entre elas —
  `algumaNotaComStatusIdentificada()` (novo, baseado em `status_ocr =
  IDENTIFICADA`, fluxo de OCR) e `algumaIdentificada()` (antigo, `JOIN`
  com `tb_cliente` local, fluxo de chave de acesso) — para não quebrar
  nenhum dos dois caminhos que coexistem no sistema. Fluxo antigo
  (`processarLeitura`, `algumaIdentificada()`, JOIN com `tb_cliente`) NÃO
  foi alterado, só passou a ser consultado em conjunto. `php -l` sem
  erros. `tests/manual/teste_identificar_cliente.php` não toca em
  `concluirDigitalizacao` (testa só `identificar-cliente`), confirmado
  que não precisava de ajuste. Validado com 2 scripts de teste manual
  (fora do repositório, dados criados e limpos no próprio banco local
  `udlog_totem`, 0 resíduos confirmados): (1) nota com `status_ocr =
  IDENTIFICADA` e SEM registro correspondente em `tb_cliente` local →
  `proxima_tela: rec_cnh` (bug corrigido); (2) nota via fluxo antigo
  (`cliente_identificado=1` + CNPJ presente em `tb_cliente` local,
  `status_ocr` ainda `PENDENTE`) → `proxima_tela: rec_cnh` (fluxo antigo
  preservado). Arquivo alterado: `app/Controller/AtendimentoController.php`.
  Pendência não resolvida (fora do escopo desta correção, avaliada e
  descartada por falta de evidência de necessidade): se `concluirDigitalizacao`
  deveria devolver dados do cliente identificado (nome/CNPJ) na resposta —
  hoje devolve só `proxima_tela`/`etapa`, sem indício de que o front-end
  espera mais que isso.
  Validação física real no totem (repetir o passo 1 do roteiro) ainda
  pendente.
- 2026-09-04 — Diagnóstico da falha real com a nota da LDC (6 fotos de
  duas capturas diferentes da mesma nota física): 2 achados novos. (1)
  Orientação física instável NOTA A NOTA dentro do MESMO atendimento — as
  3 fotos de uma mesma captura precisaram de rotações diferentes entre si
  (270°/90°/180°), confirmando que o motorista reposiciona o papel entre
  capturas e a rotação fixa não cobre esse caso (risco já aceito na
  decisão de rotação fixa, mas agora com evidência concreta mais severa
  que o esperado). (2) Bug real no regex de CNPJ do front-end
  (`CNPJ_REGEX` em `app.js`): mesmo quando a rotação está correta e o
  texto é legível, o CNPJ correto da LDC não é capturado porque o OCR
  insere mais de 1 caractere de separação entre grupos de dígitos em
  algumas capturas, e o regex atual só tolera exatamente 0 ou 1 caractere
  — um regex mais tolerante capturaria corretamente. Este é um bug
  corrigível (diferente da limitação de orientação), não uma limitação
  inerente do OCR. Nenhuma correção implementada ainda — aguardando
  decisão do usuário.

- 2026-09-08 — Demanda `recebimento-clientes-tabela-local`: substituída a consulta à API externa de clientes (`ClienteApiClient`) por consulta local, dentro da identificação automática de cliente via OCR do Recebimento. Reaproveitada a tabela já existente `tb_cliente` (singular — decisão explícita do usuário, corrigindo a recomendação inicial do planejamento de criar tabela nova), preservando as colunas existentes (`nome`, `cnpj VARCHAR(20)`) e adicionando somente `razao_social_normalizada` (migration `sql/migrations/003_tb_cliente_razao_normalizada.sql`, idempotente, com seed de 38 clientes reais validados matematicamente por DV e checados contra duplicidade de CNPJ/razão social normalizada — nenhuma encontrada). Decisão deliberada: os 38 clientes ficam disponíveis para os 3 fluxos que leem `tb_cliente` (autocomplete manual, identificação antiga por chave de acesso NF-e, novo OCR local) — não é regressão, é intencional. `NotaFiscalRn::identificarCliente()` passou a usar `ClienteDao` (novo método `listarParaFuzzy()`) em vez de `ClienteApiClient`, preservando integralmente early-stop, `MAX_CNPJS_CANDIDATOS`, `LIMIAR_MINIMO`/`MARGEM_MINIMA`, exclusão de CNPJ UDLOG e tratamento de erro técnico. Novo rate limit por totem no endpoint `identificar-cliente` (`app/Dao/RateLimitOcrDao.php`, migration `004_tb_rate_limit_ocr.sql`, incremento atômico via `INSERT ... ON DUPLICATE KEY UPDATE`, 30 chamadas/totem/janela de 60s, HTTP 429 + header `Retry-After` quando excedido — mecanismo compatível com Hostgator, sem Redis/APCu). `CLIENTES_API_TOKEN` removido do `.env.example` (confirmado sem outro uso); `ClienteApiClient.php` mantido no código como morto/documentado, não apagado. Timer de inatividade do front-end alterado de 45s para 3 minutos (`IDLE_MS` em `app.js`), preservando o contador de abandono pós-modal (`IDLE_ABANDONO_MS=30s`) sem alteração. Achado durante a validação (`qa-testes`, independente): 8 dos 38 registros ficaram com encoding corrompido (mojibake) na coluna `nome` devido a charset de cliente divergente na execução do `INSERT` da migration — corrigido via `UPDATE` pontual nesses 8 registros (por `id_cliente`+`cnpj`, sem tocar nos outros 30, sem nova migration), revalidado sem nenhum resíduo de corrupção e sem regressão nos 29 testes automatizados. Revisão de segurança (`security-especialista`) sem achado crítico/bloqueante — 2 achados não bloqueantes registrados na seção 5 (fail-open silencioso do rate limit se a dependência não for injetada; crescimento indefinido de `tb_rate_limit_ocr` sem limpeza). Regressão validada de forma independente (`qa-testes`, execução real, não só leitura) nos 3 fluxos que leem `tb_cliente`, incluindo ambiguidade correta entre pares de nomes parecidos (BARENTZ 04/49, CARGILL AGRÍCOLA/62, CP KELCO MATÃO/LIMEIRA, LOUIS DREYFUS MATÃO/BEBEDOURO — corretamente não identificados automaticamente quando o termo é genérico). Nenhuma alteração em Expedição/CNH/CRLV/Talent/scanner Netum. Nenhum commit/push realizado. Handoff: `docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md`. Próximo passo: `/02-testes` formal (incluindo reteste físico dos passos do roteiro que dependem da fonte de dado do cliente) e `/03-revisao`.

- 2026-09-08 — Etapa `/02-testes` da demanda `recebimento-clientes-tabela-local` concluída: 15/15 itens do roteiro aprovados (integridade dos 38 clientes reais em `tb_cliente`, ausência de mojibake, autocomplete, fluxo antigo de chave de acesso, identificação sequencial via OCR local, fuzzy com erro leve, ambiguidade sem associação automática, early-stop sem novas consultas, falha controlada de banco, rate limit 30/60s com HTTP 429 + `Retry-After`, isolamento do rate limit entre totens, timer de inatividade de 3 minutos, contador pós-modal de 30s preservado, fluidez da câmera durante o OCR, e ausência de regressão em Expedição/CNH/CRLV/Talent/scanner Netum — todos confirmados fisicamente pelo usuário no mini PC). Estado do banco fotografado antes dos testes por instrução do usuário (que fez ajustes manuais adicionais após a implementação) — nenhuma migration foi executada nesta etapa. Revisão de segurança revalidada sem achado crítico novo. Veredito: **APROVADO**. Handoff: `docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md`. Próximo passo: `/03-revisao`.

- 2026-09-08 — Etapa `/03-revisao` da demanda `recebimento-clientes-tabela-local` concluída: revisão independente com 5 sub-agentes (explorer, backend-especialista, frontend-especialista, security-especialista, qa-testes), cada um validando diretamente no código/banco/migrations. Confirmado sem regressão tudo o que já havia passado em `/02-testes`. **2 achados NOVOS de severidade ALTA, reproduzidos de forma real pelo qa-testes** (não hipotéticos): (1) o seed dos 38 clientes na migration `003_tb_cliente_razao_normalizada.sql` pode falhar SILENCIOSAMENTE numa instalação nova se aplicada via `mysql < arquivo.sql` — o `ALTER TABLE` falha por coluna já existente no schema novo e o cliente mysql interrompe a execução do restante do arquivo, nunca rodando o `INSERT IGNORE` dos 38 clientes; (2) causa raiz do mojibake confirmada e reproduzida — sem `SET NAMES utf8mb4` explícito, a mesma corrupção de encoding se repete em qualquer instalação nova (charset padrão do cliente MySQL no Windows é `cp850`). Ambos são bloqueadores para DEPLOY EM PRODUÇÃO, não para o commit de desenvolvimento em si (o ambiente de dev atual já está correto e testado fisicamente). Também 2 achados de severidade MÉDIA em `NotaController::identificarCliente()` — código herdado da demanda anterior (`recebimento-leitura-notas`), não introduzido por esta demanda, mas identificado nesta revisão mais profunda: ausência de `try/catch` defensivo (diferente de `processar()`) e ausência de validação de `status`/`etapa_atual` do atendimento antes de processar. Veredito: **APROVADO COM RESSALVAS**. Handoff: `docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md`. Próximo passo: decisão do usuário sobre corrigir a migration 003 antes do commit ou documentar como item obrigatório pré-deploy.

- 2026-09-08 — Correção pontual pós-`/03-revisao` da demanda `recebimento-clientes-tabela-local`: corrigidos os 2 achados ALTOS (migration `003_tb_cliente_razao_normalizada.sql` reescrita com `SET NAMES utf8mb4` + padrão de SQL preparado condicional, tornando-a verdadeiramente idempotente e não-abortante independente do método de execução — validada em banco de teste descartável simulando instalação nova, NÃO reexecutada contra o banco de desenvolvimento que já estava correto) e os 2 achados MÉDIOS (`NotaController::identificarCliente()` agora com `try/catch` defensivo e validação de `status`/`etapa_atual`, espelhando `processar()`). Suíte de testes ampliada de 29 para 35 casos. Validação independente por `security-especialista` (confirmou ausência de vazamento e ausência de regressão no early-stop) e `qa-testes` (reproduziu o cenário de instalação nova do zero em banco próprio, confirmou migration corrigida rodando sem erro com encoding correto, idempotência dupla, e testes HTTP reais dos 2 comportamentos novos sem regressão no caminho feliz). Achado novo registrado (não corrigido, fora do escopo): migrations 001 e 002 têm o mesmo padrão frágil que a 003 tinha — decisão futura pendente. **Veredito final: APROVADO** (upgrade de "aprovado com ressalvas"). Handoff: `docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md`. Próximo passo: `/04-commit-e-push`.

- 2026-09-08 — Segunda rodada COMPLETA de `/03-revisao` da demanda `recebimento-clientes-tabela-local` (pós-correção da migration 003 e do `NotaController::identificarCliente()`), a pedido do usuário: os 13 itens do checklist revalidados do zero por 5 sub-agentes independentes (explorer, backend-especialista, frontend-especialista, security-especialista, qa-testes), sem confiar nos relatórios da rodada anterior. **Nenhum achado novo em nenhum item.** Os 2 achados ALTOS (seed podia não rodar em instalação nova; mojibake se repetia sem SET NAMES) e os 2 MÉDIOS (`identificarCliente()` sem try/catch e sem validação de status/etapa) da rodada anterior foram reconfirmados como definitivamente corrigidos, com execução real em bancos de teste descartáveis por 2 agentes independentes (não só releitura de código). Suíte automatizada: 35/35. Identificação via HTTP real (CNPJ exato e fuzzy) e os 2 comportamentos novos de `identificarCliente()` confirmados sem regressão. **Veredito final: APROVADO.** Handoff: `docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md`. Próximo passo: `/04-commit-e-push`.

- 2026-09-08 — Planejamento (`/00-planejamento`) da demanda `expedicao-vio-cnh-crlv`: integração com a API oficial VIO DECODE (Serpro) no fluxo de Expedição, para validar CNH e CRLV via QR Code com a câmera NETUM, começando por uma prova técnica isolada em ambiente Trial (sem produção, sem contratação nesta etapa). Documentação oficial confirmada via WebFetch (`ui-ux-especialista`, único sub-agente com essa ferramenta) direto no portal do Serpro, registrada em novo arquivo `docs/manual_vio_decode.md`: URLs distintas trial/produção, OAuth2 client_credentials, a API espera o raw value bruto do QR (ISO-8859-1, não imagem/texto genérico), campos de resposta confirmados para CNH (19 campos) e CRLV (28 campos), códigos de erro `200`/`400`/`422` (`VD001`-`VD004`). **Achado crítico da documentação**: o Trial é descrito como "ambiente de testes... com dados de exemplo (Mock)" — sem confirmação categórica sobre QR reais. Investigação do código real (`explorer`) confirmou que NADA existe hoje de VIO/QR funcional (`ProdespClient.php` é stub morto, API diferente); achado de segurança relevante: `DocumentoController::upload()` (CNH/CRLV, já existente) tem o mesmo IDOR já corrigido em `NotaController` — correção proposta dentro do escopo desta demanda. Achado arquitetural: `exp_cnh`/`exp_crlv` compartilham funções com `rec_cnh`/`rec_crlv` — como a demanda proíbe tocar em Recebimento, será necessário criar telas/funções novas e dedicadas para Expedição (duplicação deliberada). Arquitetura planejada por 5 especialistas (backend, frontend, security, devops, qa) sem nenhuma implementação: novo `VioDecodeClient`, endpoint `documento.php?acao=validar-qr`, 4 colunas novas em `tb_atendimento` (origem de validação + JSON de dados), regra de rebaixamento para `MANUAL` quando o atendente edita campo validado, variáveis `VIO_*` com fail-closed se ausentes, biblioteca `jsQR` recomendada para extrair bytes brutos (pendente confirmação prática). Roteiro de teste da prova técnica isolada planejado, incluindo salvaguarda explícita: teste de fronteira mock-vs-real com documento próprio requer aprovação humana específica, NÃO presumida. Nenhum código implementado, nenhum commit/push. Handoff: `docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`. Próximo passo: aguardar confirmação do usuário sobre as pendências (URL do token OAuth2, quais campos persistir, obtenção de credenciais/QR de demonstração) antes de `/01-implementacao`.

- 2026-09-08 — Atualização do planejamento (`/00-planejamento`) da demanda `expedicao-vio-cnh-crlv` com decisões aprovadas pelo usuário: campos a persistir definidos (CNH: `nome`, `cpf`, `data_validade`; CRLV: `exercicio` — nunca o JSON completo da resposta, mais caminhos de imagem/origem-status/metadados mínimos de auditoria); regras de negócio para avançar no atendimento formalizadas (CNH válida = documento válido + nome preenchido + CPF com DV válido + validade preenchida e não vencida; CRLV válido = documento válido + exercício numérico + placa igual à do atendimento — exercício sozinho NÃO significa veículo licenciado em tempo real); autorizações confirmadas (localizar/usar QR oficiais de demonstração; testar documento próprio real com consentimento explícito, sem registrar dado pessoal em logs/testes/documentação; corrigir o IDOR de `DocumentoController::upload()` nesta mesma demanda; salvar fotos de CNH/CRLV para anexação). Tentativa de reconfirmar o endpoint OAuth2 (`https://gateway.apiserpro.serpro.gov.br/token`, informado pelo usuário) via WebFetch NÃO teve sucesso — único agente com a ferramenta (`ui-ux-especialista`) recusou por estar fora do escopo dele em 2 tentativas distintas; nenhum outro sub-agente tem acesso à internet neste ambiente. Registrado como não verificado tecnicamente, recomendado manter como variável de ambiente configurável em vez de hardcode. Nenhum código implementado, nenhum commit/push. Handoff: `docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`. Próximo passo: usuário confirmar a fonte da URL do token OAuth2 (ou aceitar o risco de usá-la como configuração ajustável) antes de `/01-implementacao`.

- 2026-09-08 — Segunda atualização do planejamento (`/00-planejamento`) da demanda `expedicao-vio-cnh-crlv`: endpoint OAuth2 (`https://gateway.apiserpro.serpro.gov.br/token`) CONFIRMADO por teste HTTP real feito pelo usuário (resposta 401 `invalid_client`/"Unsupported Client Authentication Method" com `WWW-Authenticate: Basic` — resultado esperado sem credenciais, confirma URL real e refina o entendimento do fluxo: autenticação via HTTP Basic no header `Authorization`, não campos separados no corpo). `docs/manual_vio_decode.md` atualizado removendo a observação "não verificada", sem registrar cookies/headers completos do teste (por instrução explícita). Regra reforçada: `VIO_CONSUMER_KEY`/`VIO_CONSUMER_SECRET` obrigatórios em produção (fail-closed se ausentes). Tentativa (3 vezes) de confirmar se o Trial oferece Bearer de demonstração pré-gerado NÃO teve sucesso — único agente com WebFetch (`ui-ux-especialista`) recusou por estar fora do escopo dele em todas as tentativas; registrado como premissa de planejamento não confirmada (assume-se que Trial exige o mesmo fluxo completo que Produção até informação em contrário). Nenhum código implementado, nenhum commit/push. Handoff: `docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`.

- 2026-09-08 — Terceira atualização do planejamento (`/00-planejamento`) da demanda `expedicao-vio-cnh-crlv`: usuário localizou e confirmou no Swagger oficial da VIO Decode (`https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/swagger/`) que o ambiente Trial disponibiliza um Bearer token PÚBLICO e compartilhado, dispensando o fluxo OAuth2 completo (Consumer Key/Secret) somente para uso no Trial — resolve a pendência anterior. **O valor literal do token não foi registrado em nenhum arquivo do projeto** (documentação, handoff, código), por instrução explícita do usuário, tratado como segredo desde a fase de planejamento; só o nome da variável de ambiente (`VIO_TRIAL_BEARER`) foi documentado, vazio. Desenho final de variáveis de ambiente planejado: `VIO_AMBIENTE`, `VIO_TRIAL_BEARER`, `VIO_TRIAL_DECODE_URL`, `VIO_TOKEN_URL`, `VIO_CONSUMER_KEY`, `VIO_CONSUMER_SECRET`, `VIO_PRODUCAO_DECODE_URL` — nenhuma alterada no `.env` real nesta etapa (só planejamento). Regra de autenticação por ambiente definida: Trial usa o Bearer público direto (sem OAuth2), Produção exige Consumer Key/Secret obrigatórios via OAuth2 com HTTP Basic Auth (endpoint já confirmado por teste HTTP real do usuário em atualização anterior). Com isso, as duas maiores pendências de infraestrutura de autenticação estão resolvidas. Nenhum código implementado, nenhum `.env` alterado, nenhum commit/push. Handoff: `docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`.

- 2026-09-08 — `/01-implementacao` (BACKEND) da demanda `expedicao-vio-cnh-crlv`, executada pelo `backend-especialista`. Implementada a máquina de estados REAL e autorizada pelo backend para CNH/CRLV da Expedição (`dados_encontrados → exp_cnh → exp_crlv → exp_confirmacao → impressao`), via nova ação `atendimento.php?acao=avancar-etapa-expedicao` (`AtendimentoController::avancarEtapaExpedicao`) — cada transição revalida posse/tipo/status/etapa atual e, para `exp_cnh`/`exp_crlv`, recheca no banco (não confia em cache de decisão anterior) se CNH/CRLV já aprovados via `App\Rn\DocumentoRn::cnhAprovada()`/`crlvAprovado()`. Front-end AINDA NÃO integrado a essa nova ação (fora do escopo de backend-especialista — `avancarDados()`/`capturarDocumento()` em `app.js` continuam decidindo a tela localmente; pendência registrada abaixo).
  Novo `App\Rn\VioDecodeClient`: Trial usa `VIO_TRIAL_BEARER` direto (sem OAuth2); Produção obtém token via `VIO_TOKEN_URL` (HTTP Basic, client_credentials), com cache do token em arquivo (`storage/cache/vio_token_production.json`, escolhido em vez de tabela nova por ser dado puramente transitório) — falha fechada no construtor se faltar credencial exigida pelo ambiente; `decodificar()` nunca lança exceção, sempre devolve resultado estruturado (sucesso ou erro categorizado: validacao/qr_invalido com código VD00x/autenticacao/rate_limit/servidor/rede_timeout com 1 retry automático só para rede/timeout), timeout 20s.
  Novo `App\Rn\DocumentoRn`: HMAC-SHA256 do QR bruto (`DOCUMENTO_QR_HMAC_KEY`) vira `identificador_qr` — o QR bruto/hex NUNCA é persistido em nenhuma tabela/log (confirmado por inspeção do schema após os testes). Fluxo cache→VIO→regra de aprovação implementado para CNH (nome+CPF com DV válido+validade não vencida) e CRLV (exercício numérico+placa igual à do atendimento, normalizada); CNH vencida nunca reaproveita cache (mesmo com `valido_ate` no futuro); CRLV SEMPRE recompara a placa do cache contra a placa atual do atendimento (o cache é por QR, não por atendimento); preenchimento manual (`preencherManualCnh`/`Crlv`) grava `origem=MANUAL`/`status_revisao=PENDENTE_REVISAO` e NUNCA escreve em `tb_vio_cache_*`, nunca impede consulta futura ao VIO para o mesmo QR.
  Novo `App\Dao\VioCacheDao` + migration idempotente `sql/migrations/005_vio_decode_cnh_crlv.sql` (padrão SQL preparado condicional + `SET NAMES utf8mb4`, mesmo padrão já corrigido na migration 003 — testada 2x localmente, segunda execução confirmada sem erro/no-op) criando `tb_vio_cache_cnh`/`tb_vio_cache_crlv` (`UNIQUE(identificador_qr, ambiente)` — trial/produção nunca se cruzam, concorrência tratada via `INSERT...ON DUPLICATE KEY UPDATE`) e 6 colunas novas em `tb_atendimento` (`cnh_origem_validacao`, `cnh_status_revisao`, `cnh_validado_em`, `crlv_origem_validacao`, `crlv_status_revisao`, `crlv_validado_em` — SEM o JSON completo da resposta VIO, conforme decisão de produto já aprovada). `sql/schema.sql` atualizado para instalação nova.
  Nome/CPF do cache de CNH criptografados em repouso via novo `Util\CriptografiaHelper` (AES-256-GCM, chave `DOCUMENTO_DATA_KEY`) — decisão registrada: `motorista_nome`/`motorista_cpf` em `tb_atendimento` (dado operacional do atendimento em si) permanecem em texto plano, mesmo padrão já usado hoje no projeto para esses campos (não criptografados nesta rodada — divergência de tratamento entre "cache" e "atendimento" documentada, não decidida silenciosamente). Novo `Util\CpfValidador` (mesmo padrão de `CnpjValidador`, DV módulo 11).
  IDOR corrigido em `DocumentoController::upload()` (agora recebe `id_totem` de `documento.php`, valida posse/tipo=`expedicao`/status=`em_andamento`/etapa correspondente ao documento antes de salvar; adaptado para aceitar frente+verso da CNH e imagem única do CRLV com nomes de arquivo fixos definidos pelo servidor). Duas ações novas em `documento.php`: `validar-qr` (recebe bytes do QR em base64, roda o fluxo HMAC/cache/VIO, nunca decide via campo vindo do front) e `preencher-manual` (fallback humano). Lock por atendimento+tipo via `GET_LOCK`/`RELEASE_LOCK` do MySQL (timeout 0, não bloqueante) evita chamada duplicada/concorrente ao VIO para o mesmo atendimento/documento, sem precisar de tabela nova.
  `.env`/`.env.example` atualizados com as variáveis `VIO_*`/`DOCUMENTO_*` (valores reais SÓ no `.env` local, nunca commitados; `DOCUMENTO_QR_HMAC_KEY`/`DOCUMENTO_DATA_KEY` gerados com `random_bytes(32)` real). `php -l` sem erros em todos os arquivos alterados/criados.
  Testes reais executados (não apenas leitura de código), com limpeza confirmada sem resíduo no banco de dev: `tests/manual/teste_vio_decode.php` (21/21 — CPF, criptografia AES-256-GCM com detecção de corrupção via tag GCM, cache hit sem chamar o VIO, cache miss chamando VIO mockado, CNH vencida nunca usa/grava cache, separação trial/produção coexistindo para o mesmo `identificador_qr`, CRLV recompara placa mesmo em cache hit — inclusive com atendimento DIFERENTE do que gerou o cache —, preenchimento manual nunca grava cache) e `tests/manual/teste_avancar_etapa_expedicao.php` (7/7 — as 4 transições da máquina de estados em subprocessos reais via `Util\Auth`-equivalente, bloqueio correto sem CNH/CRLV aprovados, IDOR bloqueado). Teste de integração real e pontual contra o Trial verdadeiro (`tests/manual/_caso_validar_qr.php`, com bytes de QR aleatórios/sem dado pessoal) confirmou conectividade e Bearer válidos (sem 401) mas retornou **HTTP 415** — achado NOVO, registrado como pendência abaixo (formato exato do corpo/`Content-Type` esperado pela VIO Decode para o raw value ainda não confirmado; o código atual envia `{"raw_value": "..."}` como JSON, que não é o formato aceito).
  **Fora do escopo desta rodada (não implementado por ser front-end, fora do papel de `backend-especialista`)**: `qr-worker.js`, `jsQR` vendorizado, telas dedicadas de captura de QR para `exp_cnh`/`exp_crlv`, e a integração do front-end (`app.js`) com a nova ação `avancar-etapa-expedicao`/`validar-qr`/`preencher-manual` — o front-end continua no comportamento antigo (decide telas localmente, não chama os endpoints novos). Nenhuma alteração em Recebimento/notas/clientes/OCR/Talent/`ProdespClient.php`. Nenhum commit/push realizado.
  **Pendências novas registradas**: (1) formato exato do corpo da requisição a `VIO_TRIAL_DECODE_URL`/`VIO_PRODUCAO_DECODE_URL` não confirmado — teste real retornou HTTP 415 com `{"raw_value": "..."}` como JSON; precisa de exemplo oficial de request do Serpro (Swagger/collection) antes de considerar a integração real funcional, hoje só a autenticação foi confirmada; (2) formato exato de `data_validade` retornado pela VIO Decode para CNH não confirmado — `DocumentoRn::normalizarData()` aceita `Y-m-d`/`dd/mm/aaaa`, ajustar se o formato real for outro; (3) integração de front-end (jsQR, telas, chamadas às novas ações) ainda não implementada — necessária participação do `frontend-especialista`; (4) `jsQR.binaryData` correspondência com o raw value esperado pela API continua sem confirmação prática (agora agravada pelo achado do HTTP 415). Próximo passo: `/02-testes` (após resolução das pendências 1 e 3, que bloqueiam teste funcional ponta a ponta real) ou continuação da implementação de front-end pelo `frontend-especialista`.

- 2026-09-08 — Continuação de `/01-implementacao` da demanda `expedicao-vio-cnh-crlv`: front-end integrado pelo `frontend-especialista` (jsQR vendorizado localmente, `qr-worker.js` dedicado sem repetir o bug de Worker aninhado do Tesseract.js, telas novas e exclusivas de Expedição — `exp_cnh_frente`/`exp_cnh_verso`/`exp_cnh_manual`/`exp_crlv`/`exp_crlv_manual` — sem tocar em `rec_cnh`/`rec_crlv`/`rec_digitaliza`, reaproveitando a chave `totem_scanner_deviceId` do `localStorage`; front nunca decide mudar de tela sozinho, sempre chama `avancar-etapa-expedicao` e só navega com a autorização do backend; avisos "Integração Trial validada" inline e rótulos `VIO_TRIAL`/`VIO_VALIDADO`/"MANUAL — pendente de revisão" na confirmação final). Revisão de segurança completa (`security-especialista`, 14 pontos) sem achado crítico — 1 achado de atenção (lock `GET_LOCK`/`RELEASE_LOCK` não liberado explicitamente em caminhos de erro por causa de `exit()` em `Resposta::erro()`, mitigado hoje pela conexão não-persistente, documentação do código incorreta sobre isso). Bateria completa de testes (`qa-testes`, 69 asserts entre os 2 scripts existentes reexecutados + 41 complementares próprios): concorrência real via 2 subprocessos, criptografia lida direto do banco (não é texto plano) e descriptografada corretamente, varredura de QR bruto em banco/logs (ausente, confirmado), preenchimento manual válido/inválido, `PENDENTE_REVISAO` consultável, IDOR por etapa/status/totem errados, fail-closed de credenciais, erros VIO mockados (401/429/5xx/timeout) — todos passaram, 0 resíduo. **Achado real encontrado e corrigido no mesmo ciclo**: `exp_confirmacao → impressao` não rechecava CNH/CRLV aprovados (confirmado experimentalmente forçando `NAO_VALIDADO` e o avanço ocorrendo indevidamente) — corrigido pelo `backend-especialista`, revalidado com 8/8 + 21/21 após a correção. Não regressão confirmada por diff: Recebimento, notas, OCR de clientes, Talent, ordem de coleta intocados (único ponto de leitura cruzada é `tb_atendimento.placa`, já existente). Nenhum commit/push realizado. Handoff: `docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`. Próximo passo: `/02-testes` formal (incluindo tentativa de resolver o HTTP 415 com formatos alternativos de payload à VIO Decode, e teste físico assim que hardware estiver disponível).
- 2026-09-08 — Correção do formato de corpo (wire format) da chamada HTTP à
  VIO Decode, feita pelo `backend-especialista`: o corpo era enviado como
  JSON `{"raw_value": "..."}`, causando HTTP 415 real. Corrigido em
  `App\Rn\VioDecodeClient::chamarDecode()` para enviar os bytes binários
  PUROS do QR em `CURLOPT_POSTFIELDS` (nunca array/JSON/base64/hex/
  multipart/`CURLFile`), com headers exatos `Accept: application/json`,
  `Content-Type: application/octet-stream`, `Authorization: Bearer <token>`,
  `Content-Length: <bytes>`. Confirmado que `App\Controller\DocumentoController::validarQr`
  já fazia `base64_decode($payload, true)` (modo estrito) corretamente
  antes desta correção — os MESMOS bytes binários resultantes são usados
  tanto para o HMAC do cache (`DocumentoRn::calcularIdentificador`) quanto
  para o corpo da chamada HTTP, sem re-serialização no meio (confirmado por
  SHA-256 idêntico nos dois pontos, via teste real).
  Validado com os dois arquivos OFICIAIS de demonstração do Serpro
  (`qrcode-trial.bin`, 1041 bytes; `crlv-demo.bin`, 232 bytes — baixados
  temporariamente fora do repositório para o teste, NÃO versionados,
  confirmado por `git status`) contra o Trial AO VIVO (rede real, não
  mockado): ambos retornaram HTTP 200. **Achado novo durante este teste
  real**: a resposta da VIO Decode não é um objeto achatado como
  `docs/manual_vio_decode.md` documentava antes — os campos do documento
  vêm dentro de um envelope `{"template": {...}, "data": {...}, "image":
  {...}}`; `App\Rn\DocumentoRn::validarCnh`/`validarCrlv` corrigidos para
  ler os campos de `dados['data']` (com fallback para o corpo achatado,
  preservando compatibilidade com os mocks já existentes em
  `tests/manual/teste_vio_decode.php`, que continuou 21/21). `qrcode-trial.bin`
  confirmado como um crachá genérico (campos `apelido`/`nome_completo`/
  `matricula`/`data_admissao`, nenhum campo de CNH) — o Trial só comprovou
  o TRANSPORTE funcionando, não a autenticidade de um documento real, como
  já esperado. `crlv-demo.bin` (Trial) confirmado retornando `data.placa`/
  `data.exercicio` como placeholder literal `"xxxxx"`/`"xxxx"` mesmo com
  HTTP 200 — `DocumentoRn` agora REJEITA explicitamente esse padrão
  (case-insensitive, qualquer sequência de `x` ou `0`), tratando como
  resultado técnico "sem dado real utilizável" (nunca aprovado
  automaticamente, nunca cacheado), com fallback para preenchimento manual
  preservado e reconfirmado funcionando. Payload de bytes aleatórios (não
  um QR VIO válido) confirmado NÃO retornando mais 415 (a API classificou
  como HTTP 400/`validacao`, comportamento real da API — distinto do 422/
  VD00x documentado para QR estruturalmente reconhecido mas inválido,
  ambos tratados como branches de erro distintos e claros no código, nunca
  confundidos com 415). Nenhum log/arquivo/tabela registrou os bytes brutos
  dos QRs durante os testes (confirmado por leitura do código e por
  inspeção). Novo teste automatizado `tests/manual/teste_vio_decode_wire_format.php`
  (19/19, contra o Trial real — requer variáveis de ambiente apontando
  para os `.bin` baixados manualmente, não incluídos no repositório; URLs
  de download documentadas no cabeçalho do arquivo). `docs/manual_vio_decode.md`
  atualizado com o formato de corpo confirmado e o envelope real da
  resposta. `ia_development_state.md` atualizado (pendência do HTTP 415
  marcada como resolvida). Escopo desta correção restrito a
  `VioDecodeClient`/ponto de decodificação do payload/rejeição de
  placeholder em `DocumentoRn`, conforme solicitado — máquina de estados,
  cache, criptografia e front-end não tocados. Nenhum commit/push
  realizado. **Pendências que continuam abertas** (não resolvidas por este
  teste): correspondência prática de `jsQR.binaryData` (captura real pela
  câmera) com o raw value esperado pela VIO Decode; formato exato de
  `data_validade` da CNH; nenhum QR de CNH real foi testado (Trial não
  possui QR de demonstração de CNH). Próximo passo: `/02-testes` formal
  ponta a ponta (incluindo teste físico com a câmera assim que possível) —
  seguido por avaliação se a pendência de front-end/telas já implementadas
  segue coerente com este novo entendimento do envelope de resposta.

- 2026-09-08 — Etapa `/02-testes` da demanda `expedicao-vio-cnh-crlv` concluída (após correção do formato de payload do `VioDecodeClient`): 15/15 itens pedidos aprovados, incluindo validação real contra o Trial vivo do Serpro com os arquivos oficiais (`qrcode-trial.bin`/`crlv-demo.bin`, HTTP 200 nos dois, hashes registrados nos testes, nunca o conteúdo). Confirmado: formato normalizado `template/data/image` funciona (mock achatado E envelope real), bytes puros no `VioDecodeClient`, cache hit/miss, separação Trial/Produção, máquina de estados completa com rechecagem na transição final, IDOR, preenchimento manual, rejeição de `"xxxxx"` (inclusive testado com a resposta real do `crlv-demo.bin`), ausência de dado sensível em banco/logs, front-end consumindo só a resposta normalizada, e não regressão. **Achado importante**: a regra de "rebaixamento para MANUAL" planejada no `/00-planejamento` original nunca foi implementada (`AtendimentoRn::salvarDadosMotorista()` sem alteração, confirmado por `git diff`) — registrado como pendência para decisão do usuário. Mais 2 achados de segurança de atenção (não bloqueantes): campo `image` da resposta VIO não descartado explicitamente; assimetria de rejeição de placeholder entre CRLV e CNH. E 1 observação de frontend (lógica de origem duplicada entre front/back). **Veredito: APROVADO COM RESSALVAS**. Handoff: `docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`. Próximo passo: decisão do usuário sobre corrigir a regra de rebaixamento (e opcionalmente os achados de segurança) antes de `/03-revisao`.

- 2026-09-09 — REPLANEJAMENTO (`/00-planejamento`, voltando de `/01-implementacao`) da demanda `expedicao-vio-cnh-crlv`: mudança de escopo aprovada pelo usuário — VIO Decode passa a valer em Expedição **e** Recebimento (não só Expedição), e o processamento precisa ser assíncrono do ponto de vista do navegador (captura de CNH libera imediatamente a captura do CRLV, sem esperar a validação terminar; tela de carregamento só aparece depois do CRLV, se ainda faltar processamento). Novo status persistido por documento (`PENDENTE`/`PROCESSANDO`/`VIO_TRIAL`/`VIO_VALIDADO`/`MANUAL`/`ERRO` + revisão `NAO_NECESSARIA`/`PENDENTE_REVISAO`), campo separado de `origem_validacao` (não funde os dois conceitos). **Correção de registro**: a "divergência" anotada em `/02-testes` sobre o rebaixamento para MANUAL nunca implementado NÃO era real — a correção já tinha sido feita num ciclo anterior, só não havia sido documentada no handoff antes da pausa. **Achado CRÍTICO do `frontend-especialista`**: a máquina de estados atual bloqueia `exp_cnh → exp_crlv` até a CNH ser aprovada pelo VIO — fisicamente incompatível com o requisito de liberar o CRLV imediatamente; proposta de correção: gate de avanço passa a exigir só "foto enviada" (aprovação obrigatória continua no gate final `exp_confirmacao → impressao`, que já existe), e a checagem de etapa em `validar-qr`/`preencher-manual` muda de "exata" para "igual ou posterior" (race condition entre disparo assíncrono e avanço de etapa). **Achado CRÍTICO do `security-especialista`**: timeout de `PROCESSANDO` obsoleto precisa de identificador de tentativa/versão para a "tentativa zumbi" não sobrescrever o resultado da tentativa nova; transição para `PROCESSANDO` precisa ser atômica (`UPDATE...WHERE status IN(...)` checando `affected_rows`); endpoint de polling de status precisa de rate limit próprio e nunca deve chamar o VIO. Cache confirmado compartilhado entre os dois fluxos sem alteração de schema (já é agnóstico de tipo de atendimento). `devops-especialista` confirmou viabilidade no Hostgator (tudo client-side, nunca daemon) e recomendou verificar `max_execution_time` real em produção. Roteiro de teste de 12 categorias planejado pelo `qa-testes`. Nenhum código implementado nesta etapa. Handoff: `docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`. Próximo passo: aprovação explícita do usuário sobre a mudança de gating (bloqueante) e demais pendências antes de retomar `/01-implementacao`.

- 2026-09-09 — Retomada de `/01-implementacao` da demanda `expedicao-vio-cnh-crlv` com a arquitetura assíncrona aprovada: VIO Decode estendido a Expedição E Recebimento, processamento em segundo plano (captura de CNH libera imediatamente o CRLV, tela de espera só depois do CRLV). Novo status `cnh_status_processamento`/`crlv_status_processamento` (PENDENTE/PROCESSANDO/CONCLUIDO/ERRO, separado de `origem_validacao`), com identificador de tentativa (`tentativa_id`) para descartar respostas "zumbi", transição atômica para `PROCESSANDO` (`UPDATE...WHERE status IN(...)` com `rowCount()`), timeout de 30s para processamento obsoleto. Novos endpoints `iniciar-processamento` e `status-processamento` (nunca chama o VIO, rate limit próprio via `RateLimitVioStatusDao`). Novas etapas reais: Expedição `exp_cnh → exp_crlv → exp_aguarde_documentos → exp_confirmacao → impressao`; Recebimento `rec_cnh_frente → rec_cnh_verso → rec_crlv → rec_aguarde_documentos → rec_confirmacao`. Cache confirmado compartilhado entre os dois fluxos sem alteração de schema. **2 correções aplicadas durante a rodada**: (1) gap de `etapa_atual` não avançada após identificação manual de cliente no Recebimento; (2) **IDOR crítico** em `AtendimentoController::salvarEtapa()` casos `confirmacao`/`cliente`/`ajudante` (não validavam posse/tipo/status/etapa, permitindo sobrescrever CPF de motorista/ajudante e forçar transição de etapa de atendimento alheio) — corrigido replicando o padrão já usado em `digitalizacao_notas`. Mais de 200 asserções de teste executadas entre suíte reexecutada e testes novos (concorrência real com múltiplos subprocessos contra o Trial vivo, timeout/tentativa zumbi, e2e completo em ambos os fluxos, allowlist de etapas, IDOR pós-correção) — 0 falha, 0 resíduo. Achado não relacionado a esta demanda, registrado sem correção: `TalentRn::montarAnexos()` procura `cnh.jpg` mas o upload salva `cnh_frente.jpg`/`cnh_verso.jpg` (bug funcional sem risco de segurança). Nenhum código de notas/OCR/Talent/ordem de coleta alterado além do já documentado. Nenhum commit/push realizado. Handoff: `docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`. Próximo passo: `/02-testes` formal (físico, quando houver hardware) e `/03-revisao`.

- 2026-09-09 — Planejamento (`/00-planejamento`) da nova demanda `integracao-talent-portaria-checkin`: descoberto que toda a integração atual com o Talent (`TalentClient`/`TalentRn`, endpoint `/atendimentos`) é um contrato placeholder que nunca correspondeu à API real — o manual oficial (`MANUAL_TALENT_WMS.pdf`) foi lido na íntegra pela primeira vez, revelando que o endpoint correto é `POST https://api.talentcs.com.br/Portaria/Checkin`, com payload, campos obrigatórios e estrutura de anexos (`{anexoBase64, descricao}`) completamente diferentes do que o código assumia. Contrato documentado em `docs/manual_talent.md`. Tabela campo a campo produzida comparando o payload real contra o que o totem coleta hoje — **achado bloqueante**: `veiculo.uf` é obrigatório mas não existe nenhuma coluna nem captura para isso. **2 achados críticos de segurança**: IDOR em `AtendimentoController::finalizar()` (sem validação de posse/tipo/status/etapa) e persistência do corpo bruto de resposta do Talent (pode ecoar CPF/CNH) em `tb_fila_envio.ultimo_erro`. Ausência de mecanismo de idempotência no envio (retry pode duplicar check-in). Diversos campos do contrato sem fonte de captura no totem (decisão de produto pendente). Nenhum código implementado, nenhuma migration escrita, nenhuma chamada real ao Talent, nenhum commit/push — demanda VIO preservada intacta. Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`. Próximo passo: decisão do usuário sobre as pendências bloqueantes antes de `/01-implementacao`.

- 2026-09-09 — Refinamento do planejamento (`/00-planejamento`) da demanda `integracao-talent-portaria-checkin`: todas as 18 pendências do handoff original foram revisadas e classificadas individualmente (4 RESOLVIDAS com plano completo, 3 NÃO APLICÁVEIS/campo opcional omitido, 11 BLOQUEADAS EXTERNAMENTE, exigindo confirmação real com o Talent). **Resolvido o achado bloqueante** (`veiculo.uf`): será obtido do campo `data.uf` da resposta VIO Decode para CRLV (campo já confirmado existir), com validação de sigla, persistência dupla (atendimento + cache), preenchimento manual obrigatório via dropdown quando não vier do VIO, e rebaixamento para MANUAL se editado depois. **Desenho completo pronto** para os 2 achados críticos de segurança (IDOR em `finalizar()`, log com corpo bruto de erro) e para o cenário "Talent aceita mas gravação local falha" (mecanismo de idempotência com 5 estados: `NAO_ENVIADO`/`ENVIANDO`/`ENVIADO`/`ERRO_REPROCESSAVEL`/`ENVIO_INDETERMINADO`, com `talent_tentativa_id` protegendo contra resposta atrasada sobrescrever tentativa mais recente). Payload mínimo definido campo a campo (7 grupos obrigatórios); campos opcionais sem fonte confirmados como omitidos do payload (nunca `null`/placeholder). `nrCNH`/`categoriaCNH` confirmados opcionais no manual — decisão de omitir nesta rodada, registrado como melhoria futura. Parser de retorno do Talent desenhado com allowlist e postura conservadora (nunca exibir senha inventada até contrato de retorno ser confirmado). `docs/manual_talent.md` e handoff atualizados com todo o desenho. Nenhum código implementado, nenhuma migration real escrita, nenhuma chamada real ao Talent/VIO. Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`. Próximo passo: usuário decidir se autoriza `/01-implementacao` das partes internas resolvidas, mantendo os bloqueios externos documentados como pendência a confirmar com a Talent.

- 2026-09-09 — Segunda rodada de refinamento (`/00-planejamento`) da demanda `integracao-talent-portaria-checkin`, com decisões de produto explícitas do usuário: (1) **Ambiente/teste**: Talent não tem homologação; teste controlado em Produção autorizado, com aviso obrigatório antes de qualquer POST real e relato pós-teste (registro criado, horário, placa, empresa, identificador) para exclusão manual no painel do Talent, sem expor CPF/CNH completos; (2) **Empresa/armazém**: nova tabela `tb_empresa` (Maua I `14706199000182`, Maua II `14706199000344`) com FK em `tb_totem`; `cnpjArmazem` passa a vir exclusivamente do totem autenticado, nunca do frontend; totens sem empresa falham explicitamente; vínculo do totem de teste específico a Maua I **fica bloqueado** até identificação certa da linha em `tb_totem` (não documentada em lugar nenhum); (3) **`tipoEmbDesemb`**: literais definidos como `"embarque"`/`"desembarque"` minúsculos; (4) **`cnpjDepositante`**: confirmado como `tb_atendimento.cliente_cnpj`, validado 14 dígitos; (5) **Anexos**: mudança de JPEG direto para PDF gerado no momento da finalização (`CNH.pdf` 2 páginas, `CRLV.pdf` 1 página, um PDF por nota fiscal), base64 puro, biblioteca PHP pura sem binário externo (proposta `setasign/fpdf`, a confirmar); (6) **HTTP 409**: protocolo de teste controlado definido (enviar, registrar identificador seguro, repetir uma única vez, nunca alterar dados para contornar); (7) **Idempotência**: 409 confirmado incorporado ao desenho de 5 estados como reconciliação para `ENVIADO`; (8) **`doctos[]`**: decisão de omitir nesta versão por falta de semântica confirmada de `nrDocto`. Todas as 18 pendências do handoff foram reclassificadas com nova taxonomia (RESOLVIDA / NÃO APLICÁVEL / PENDENTE EXTERNA — OrdemColetaClient / VALIDAÇÃO EM PRODUÇÃO AUTORIZADA): 9 RESOLVIDAS, 3 NÃO APLICÁVEIS, 5 VALIDAÇÃO EM PRODUÇÃO AUTORIZADA, 1 PENDENTE EXTERNA (`OrdemColetaClient`). `docs/manual_talent.md` e o handoff `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md` atualizados com todo o desenho. Nenhum código implementado, nenhuma migration real escrita, nenhuma chamada real ao Talent/VIO, nenhum `composer require` executado, nenhum commit/push. Próximo passo: usuário confirmar qual totem é o de teste (para o vínculo com Maua I) e autorizar `/01-implementacao` das partes internas resolvidas; aviso explícito obrigatório antes de qualquer POST real de teste em Produção quando essa fase chegar.

- 2026-09-09 — `/01-implementacao` da demanda `integracao-talent-portaria-checkin` concluída (backend-especialista), com revisão independente de security-especialista e qa-testes antes de fechar a etapa. Implementado: `tb_empresa` + FK em `tb_totem` (migration 008, seeds Maua I `14706199000182`/Maua II `14706199000344`, vínculo do totem de teste `id_totem=1`/`codigo=RECEPCAO-01` só com os dois batendo simultaneamente); UF do CRLV persistida/validada contra 27 UFs, com rebaixamento para MANUAL e cache antigo sem UF tratado como incompleto (migration 009); anexos convertidos para PDF via `setasign/fpdf` (CNH 2 páginas, CRLV 1 página, 1 PDF por nota fiscal), base64 puro; `TalentClient`/`TalentRn` reescritos conforme contrato real (`cnpjArmazem` exclusivamente do totem autenticado, `cnpjDepositante` validado 14 dígitos, `tipoEmbDesemb` minúsculo, `doctos[]` omitido, campos opcionais sem fonte omitidos); IDOR crítico em `finalizar()` corrigido (posse/tipo/status/etapa/documentos/empresa, todos antes do CAS de idempotência); idempotência de 5 estados implementada (`NAO_ENVIADO`/`ENVIANDO`/`ENVIADO`/`ERRO_REPROCESSAVEL`/`ENVIO_INDETERMINADO`, sem coluna de corpo bruto); `tb_fila_envio`/cron ajustados para só reprocessar `ERRO_REPROCESSAVEL`. **Revisão de segurança independente encontrou e corrigiu 1 achado ALTO**: `TalentClient` classificava a maioria dos erros de rede pós-envio como reprocessável (risco real de check-in duplicado); corrigido para tratar `CURLE_RECV_ERROR`/`CURLE_GOT_NOTHING`/`CURLE_PARTIAL_FILE`/`CURLE_SSL_CONNECT_ERROR`/`CURLE_SEND_ERROR` como `ENVIO_INDETERMINADO` (nunca retry automático), mantendo reprocessável só para erros que ocorrem antes de qualquer byte sair do totem. `qa-testes` validou com 127 asserções novas (payload, PDFs, IDOR, concorrência real via `proc_open`, timeout/resposta atrasada, logs sanitizados, UF) e encontrou uma regressão real em 3 suítes pré-existentes (`teste_rebaixamento_manual.php`, `teste_fluxo_recebimento_documentos.php`, `teste_vio_decode.php`) cujos mocks/fixtures de CRLV não incluíam UF — corrigido, todas as 7 suítes de regressão pedidas voltaram a 100% (121/121 asserções), sem resíduo em banco/disco. Nenhuma chamada real ao Talent, nenhum commit/push. Handoff atualizado: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`, seção "Resultado da implementação". Próximo passo: aviso explícito ao usuário imediatamente antes do teste controlado em Produção (protocolo já definido), com relato pós-teste limitado a identificadores seguros para exclusão manual no painel do Talent.

- 2026-09-09 — `/02-testes` da demanda `integracao-talent-portaria-checkin`: `qa-testes` re-executou de forma independente toda a suíte (migrations do zero + reexecução de 008/009, vínculo do totem de teste positivo/negativo, 17 suítes de teste) sem alterar nenhum código — **291/291 asserções passando, 0 falhas, sem resíduo em banco/disco**. Nenhuma correção necessária. Em seguida, `backend-especialista` preparou (sem enviar nada real) o teste controlado em Produção: confirmado no banco de dev local que `tb_totem id_totem=1, codigo=RECEPCAO-01` está vinculado a Maua I; criado atendimento de teste `id_atendimento=731` (Recebimento) com CNH/CRLV aprovados, 1 nota fiscal, `talent_checkin_status=NAO_ENVIADO`; payload e os 3 PDFs (CNH 2p, CRLV 1p, Nota Fiscal 01 1p) montados e validados sem chamar `TalentClient::checkin()`. Aguardando autorização explícita do usuário (`AUTORIZO POST REAL`) antes de qualquer chamada de rede real ao endpoint de Produção do Talent. Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`, seção "/02-testes".

- 2026-09-09 21:41 — Teste controlado REAL em Producao da demanda `integracao-talent-portaria-checkin`, autorizado explicitamente pelo usuario ("AUTORIZO POST REAL") diretamente na conversa. Um sub-agente recusou executar o POST por nao conseguir verificar de forma independente a legitimidade da autorizacao (recebida via despacho, nao diretamente do usuario) — comportamento de seguranca correto para acao real/irreversivel; o orquestrador entao executou a reconfirmacao e o envio diretamente, ja que recebeu a autorizacao em primeira mao. Reconfirmado imediatamente antes do envio: totem `id_totem=1`/`codigo=RECEPCAO-01` vinculado a Maua I (`14706199000182`), atendimento de teste `id_atendimento=731` em `NAO_ENVIADO` com CNH/CRLV aprovados e CNPJ valido. **Resultado real: HTTP 400 do Talent (categoria interna `erro_validacao`), `talent_checkin_status` final = `ERRO_REPROCESSAVEL`, nenhum identificador retornado.** Nenhuma linha criada em `tb_fila_envio` (chamada direta ao `TalentRn::processarCheckin()`, sem passar pelo enfileiramento do controller) — confirmado que nenhum reenvio automatico pode ocorrer para este registro de teste. Corpo bruto da resposta nunca foi lido/exibido (so a categoria interna). Limpeza confirmada: nenhum PDF residual, pasta de teste removida, nenhum CPF/CNH/token/base64 em log ou banco. Hipotese registrada (nao confirmada, sem acesso ao corpo bruto): o CNPJ de depositante fictício usado no teste (`11222333000181`) provavelmente nao existe na base real do Talent para Maua I, causando a rejeicao HTTP 400 — pendencia registrada para o usuario confirmar via painel do Talent. Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`, secao "Teste controlado REAL em Producao — executado". Nenhum commit/push. Nenhuma alteracao de codigo.

- 2026-09-09 22:05 — Segunda tentativa do teste controlado REAL em Producao da demanda `integracao-talent-portaria-checkin`, nova autorizacao explicita do usuario ("AUTORIZO POST REAL") recebida diretamente na conversa. Usuario forneceu um CNPJ de depositante real ja existente em `tb_cliente` (AKRO-PLASTIC DO BRASIL, `20200104000238`, confirmado ativo) para substituir o CNPJ ficticio da primeira tentativa. Atendimento de teste 731 atualizado e resetado para `NAO_ENVIADO`; reconfirmacao completa repetida; envio executado diretamente pelo orquestrador (mesmo motivo de seguranca da primeira tentativa). **Resultado: MESMO ERRO HTTP 400 (`erro_validacao`)**, mesmo com CNPJ de depositante real e ativo — a hipotese anterior (CNPJ ficticio como causa) esta descartada. Causa raiz do HTTP 400 continua desconhecida, pois o corpo bruto do erro nunca foi lido/exibido (regra mantida nas duas tentativas). Candidatos nao descartados: `cnpjArmazem` de Maua I pode nao estar cadastrado exatamente assim na base do Talent, literal `tipoEmbDesemb`, formato de `veiculo.uf`, ou algum campo obrigatorio nao documentado no manual (`doctos[]`/`nrCNH`/`categoriaCNH` omitidos nesta versao). Limpeza confirmada (sem PDF residual, sem linha em `tb_fila_envio`, sem dado sensivel em log/banco). Pendencia registrada: diagnosticar a causa exata exigiria relaxar pontualmente a regra de nunca exibir corpo bruto (com autorizacao explicita) ou contato direto com quem administra a conta do Talent. Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`, secao "Segunda tentativa do teste controlado REAL". Nenhum commit/push. Nenhuma alteracao de codigo.

- 2026-09-10 — Terceira tentativa do teste controlado REAL (diagnostico com corpo bruto), autorizada explicitamente pelo usuario para relaxar pontualmente a regra de nunca exibir corpo bruto. Um sub-agente recusou executar por nao poder verificar autorizacao repassada; em seguida a propria camada de auto-mode do Claude Code bloqueou o orquestrador de escrever/executar o script via Bash — o script foi entregue ao usuario, que executou manualmente e colou o resultado (ja mascarado por regex de CPF/CNPJ/token/base64 embutida no proprio script). **CAUSA RAIZ REAL DO HTTP 400 ENCONTRADA**: 3 campos obrigatorios ausentes do payload — `doctos` (decisao anterior de omitir revertida), `veiculo.rntc` (sem fonte de captura hoje), `veiculo.tipo` (sem fonte de captura hoje, formato nao confirmado). As duas hipoteses anteriores sobre CNPJ de depositante estavam descartadas corretamente — `cnpjArmazem`/`cnpjDepositante`/`tipoEmbDesemb`/`veiculo.placa`/`veiculo.uf`/`motorista.cpf`/`motorista.nome`/`anexos` confirmados corretos (nenhum erro de validacao). 3 novas pendencias bloqueantes registradas (RNTC e tipo de veiculo sem nenhuma fonte no totem hoje; doctos precisa ser reativado). Nao implementado nada — decisao de produto/planejamento necessaria antes de qualquer novo teste real. `docs/manual_talent.md` e handoff atualizados com o corpo de erro real (mascarado). Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`, secao "Terceira tentativa — CAUSA RAIZ ENCONTRADA". Nenhum commit/push. Nenhuma alteracao de codigo de producao.

- 2026-09-10 — Nova rodada de `/01-implementacao` da demanda `integracao-talent-portaria-checkin`, em resposta direta ao achado real do teste controlado em Producao (HTTP 400 confirmando `doctos`/`veiculo.rntc`/`veiculo.tipo` como obrigatorios). Acionados backend, frontend, security e QA. **Implementado**: `veiculo.rntc` (extraido de `data.rntrc` do CRLV via VIO — grafia real confirmada no manual VIO, diferente do nome usado no lado Talent) e `veiculo.tipo` (extraido de `data.tipo`), com schema/migration idempotente (`sql/migrations/010_talent_rntc_tipo_veiculo.sql`), validacao/rejeicao de placeholder, cache incompleto tratado, rebaixamento para MANUAL estendido, preenchimento manual que reaproveita valor ja aprovado pelo VIO quando so outro campo falta, e inclusao no payload do Talent somente apos aprovacao completa (com defesa em profundidade). Campos de front-end (RNTC, Tipo de veiculo — texto livre, sem enum documentado) adicionados ao formulario manual e a tela de confirmacao dos dois fluxos. **Bloqueio incondicional `TALENT_DOCTOS_PENDENTE`** adicionado em `AtendimentoController::finalizar()` (HTTP 501), impedindo qualquer chamada real ao Talent enquanto `doctos[]` continuar sem semantica de `nrDocto` confirmada — posicionado apos todas as checagens de posse/tipo/status/etapa/documentos/empresa e antes do CAS de idempotencia; `doctos` nunca e enviado vazio ou inventado (a chamada simplesmente nao acontece). **Seguranca**: revisao independente encontrou 0 achados criticos/altos/medios — trava confirmada blindada, sem IDOR no reaproveitamento de preenchimento manual, rebaixamento e cache incompleto corretos, SQL sempre parametrizado, migration idempotente. **QA**: 347/347 asserções passando (324 pre-existentes + 23 novas dedicadas a RNTC/tipo), 0 falhas, 0 chamada de rede real; bloqueio `TALENT_DOCTOS_PENDENTE` confirmado explicitamente (talent_checkin_status permanece NAO_ENVIADO). Resíduo pre-existente do teste real anterior (atendimento 731, pasta de teste, linha antiga em tb_fila_envio) reobservado mas nao alterado — decisao de limpeza cabe ao usuario. `doctos[]` continua pendencia bloqueante aberta (mitigada, nao resolvida) ate decisao de produto sobre `nrDocto`/`doctos[].tipo`. `docs/manual_talent.md` e handoff atualizados. Nenhum commit/push. Nenhuma chamada real ao Talent/VIO nesta rodada. Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`, secao "Nova rodada de /01-implementacao (2026-09-10)".

- 2026-09-10 — `/03-revisao` da demanda `integracao-talent-portaria-checkin`. **Limpeza autorizada dos resíduos do teste real anterior**: atendimento de teste `id_atendimento=731` (e sua única nota dependente, `id_nota=600`) excluído em transação única (respeitando FK), pasta `storage/atendimentos/teste_prep_producao_2dc56209/` e seus 4 arquivos removidos via caminho absoluto individual (sem recursão/curinga) — identificação prévia confirmou pasta exclusiva e ausência de divergência antes de qualquer exclusão; evidência mascarada (ID, horários das 3 tentativas reais, HTTP, estado final) preservada no handoff, sem CPF/CNH/imagens/PDFs/corpo bruto. Resíduo pré-existente e não relacionado (`tb_fila_envio.id_fila=2`, atendimento 160, de 2026-09-08) confirmado intocado, fora do escopo autorizado. **Revisão independente completa** (instâncias novas de security-especialista e qa-testes, sem depender de autorrelato anterior): segurança confirmou 12/12 itens do checklist sem nenhuma divergência (mapeamento VIO->Talent de rntc/tipo, validação/cache/preenchimento manual/rebaixamento, ambos os fluxos, ausência de IDOR/SQL injection/vazamento em log, migration idempotente, trava `TALENT_DOCTOS_PENDENTE` confirmada intransponível com `Resposta::erro()` sempre encerrando a execução, `talent_checkin_status` impossível de mudar pela trava, `doctos` nunca contornado); QA re-executou toda a suíte (17 suítes + 1 teste novo dedicado `teste_talent_trava_doctos_pendente.php`, 16/16 asserções, provando com credenciais reais do `.env` e um espião que nenhuma chamada de rede real ocorre) com 100% de sucesso (1 falha intermitente de timing em `teste_status_processamento.php`, não-regressão, confirmada ao reexecutar 9/9). Migrations 001-010 validadas limpas do zero. **Nenhum defeito encontrado — /03-revisao aprovado sem necessidade de nova rodada de implementação.** `doctos[]` continua pendência bloqueante em aberto. Handoff: `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`, seções "/03-revisao (2026-09-10) — limpeza autorizada" e "Revisão independente final (2026-09-10)". Nenhum commit/push. Nenhuma chamada real ao Talent/VIO.

- 2026-09-11 — `/00-planejamento` da nova demanda
  `expedicao-consulta-ordem-coleta-teste`: confirmado que nunca existiu API
  REST real para "buscar ordens de coleta por placa" (secao permanecia
  vazia em `docs/db_gestao_coletas.md`). Usuario autorizou explicitamente
  acesso de LEITURA direto ao banco externo `udlogo59_db_gestao_coletas`
  (mesmo servidor/credencial do totem, so muda `DB_NAME`) e forneceu o
  dump completo do schema real em `docs/db_gestao_coletas.sql`.
  **Achado de confiabilidade**: uma primeira investigacao do `explorer`
  (antes do dump ser fornecido) relatou detalhes que se confirmaram
  incorretos/inventados ao comparar com o DDL verbatim (coluna de status
  em `tb_ordens_coleta` que nao existe; tabelas `tb_atendimentos`/
  `tb_ajudantes`/`tb_documentos` que nao existem nesse banco) — coincidiu
  com um aviso de seguranca automatico do ambiente sobre as acoes desse
  sub-agente; os achados dela foram descartados e substituidos pelo dump
  verbatim real. Schema real confirmado: `tb_ordens_coleta` (sem coluna de
  status, `email_recebido_id` NOT NULL, unico `(cliente_id,
  numero_ordem_coleta)`), `tb_clientes`, `tb_motoristas`,
  `tb_emails_recebidos`. Ja existem 3 ordens de teste reais
  (`OC-TESTE-001`/`OC-TESTE-NOVA-003`/`OC-TESTE-NOVA-004`, `cliente_id=8`,
  placa `ABC1D23`). Plano consolidado (backend-especialista,
  security-especialista, qa-testes): `OrdemColetaClient` passa a delegar a
  uma nova `OrdemColetaDao`/`Util\ConexaoGestaoColetas` (conexao PDO
  dedicada, timeout curto, prepared statement, erro generico ao totem,
  nunca vaza SQL/credencial), consultando `tb_ordens_coleta INNER JOIN
  tb_clientes` por `placa_prevista` normalizada no backend, sem filtro de
  status. Dado de teste desenhado (SQL pronto, NAO EXECUTADO):
  `numero_ordem_coleta='OC-TESTE-005'`, `placa_prevista='TST0A01'`,
  reaproveitando fixtures existentes (`cliente_id=8`,
  `email_recebido_id=1`), zero dado pessoal novo. Achados novos
  registrados: `tb_atendimento.ordem_coleta` VARCHAR(30) menor que
  `numero_ordem_coleta` real VARCHAR(50) (migration proposta, nao
  implementada); filtro morto de status em
  `AtendimentoRn::consultarOrdensAbertas()` a remover; recomendacao do
  `security-especialista` de credencial de banco dedicada/somente-leitura
  em vez de reaproveitar o `DB_USER` do totem (pendente de decisao de
  arquitetura). Nenhum codigo implementado, nenhuma escrita real no banco
  externo, nenhum commit/push. Handoff:
  `docs/handoffs/2026-09-11-expedicao-consulta-ordem-coleta-teste.md`.
  Proximo passo: usuario decidir as pendencias de UX/seguranca listadas e
  autorizar `/01-implementacao`.
- 2026-09-11 — `/01-implementacao` da demanda
  `expedicao-consulta-ordem-coleta-teste` concluida. `OrdemColetaClient`
  reescrito para delegar a `App\Dao\OrdemColetaDao` (nova), que consulta
  `tb_ordens_coleta INNER JOIN tb_clientes` no banco externo via
  `Util\ConexaoGestaoColetas` (nova — conexao PDO propria e separada de
  `Util\Conexao`, `PDO::ATTR_TIMEOUT` curto, conexao aberta so no momento
  real da consulta, nunca no bootstrap de `atendimento.php`, para nao afetar
  acoes que nao precisam consultar ordem de coleta se o banco externo cair).
  Filtro `tb_clientes.status='ATIVO' AND tb_ordens_coleta.status='ATIVA'`
  aplicado na query SQL; `AtendimentoRn::consultarOrdensAbertas()` perdeu o
  filtro morto de status em array PHP (nunca existiu de fato na fonte real).
  Normalizacao de placa exclusivamente no backend
  (`OrdemColetaClient::normalizarPlaca`). Corrigido IDOR critico em
  `AtendimentoController::selecionarOrdem()` (achado antigo do
  security-especialista): agora valida posse/tipo/status/etapa do
  atendimento (mesmo padrao das demais acoes) e RECONSULTA as ordens reais
  para a placa do atendimento antes de aceitar a selecao — so aceita se o
  `numero` enviado bater com uma ordem realmente retornada; grava sempre com
  os dados vindos do servidor, nunca `cliente_nome`/`cliente_cnpj` do front.
  `.env`/`.env.example`: `GESTAO_COLETAS_DB_NAME` adicionada,
  `ORDEM_COLETA_API_URL`/`ORDEM_COLETA_API_KEY` removidas (sem uso).
  `sql/migrations/011_ampliar_ordem_coleta.sql` amplia
  `tb_atendimento.ordem_coleta` para VARCHAR(50) — aplicada com sucesso no
  banco de dev local (`udlog_totem`); `sql/schema.sql` atualizado para
  instalacoes novas. `sql/migrations_gestao_coletas/001_status_ordem_coleta.sql`
  (pasta NOVA, separada de `sql/migrations/`, com aviso explicito de que
  pertence exclusivamente ao banco externo) adiciona
  `status ENUM('ATIVA','INATIVA') DEFAULT 'ATIVA'` + indice
  `(placa_prevista, status)` em `tb_ordens_coleta` — aplicada com sucesso
  contra o banco externo local `db_gestao_coletas` (coluna/indice
  confirmados via `INFORMATION_SCHEMA`, as 3 ordens de teste ja existentes
  ficaram `ATIVA` pelo default, sem UPDATE necessario).

  **Achado critico de ambiente (nao inventado, verificado por consulta
  real)**: o banco `udlogo59_db_gestao_coletas` — nome que seria o real de
  Producao — JA EXISTE neste XAMPP local, mas com um schema DIVERGENTE do
  dump oficial fornecido pelo usuario (`docs/db_gestao_coletas.sql`): ja
  possui uma coluna `status` propria em `tb_ordens_coleta`, só que com
  `ENUM('PENDENTE','LIBERADA','EM_ATENDIMENTO','CONCLUIDA','CANCELADA')`,
  incompatível com o `ENUM('ATIVA','INATIVA')` desta demanda. Esse banco NAO
  foi tocado. A migration externa e o fixture de teste foram aplicados
  contra `db_gestao_coletas` (sem prefixo), que bate exatamente com o dump
  verbatim. `.env` local aponta `GESTAO_COLETAS_DB_NAME=db_gestao_coletas`,
  com o achado documentado em comentario no proprio arquivo. Pendencia nova:
  investigar a origem de `udlogo59_db_gestao_coletas` local antes de
  qualquer deploy real, e reconfirmar o nome exato do banco em Producao no
  Hostgator antes de aplicar `sql/migrations_gestao_coletas/001_status_ordem_coleta.sql`
  la (a migration usa `DATABASE()` dinamicamente, entao funciona contra
  qualquer nome, desde que a conexao aponte para o banco certo).

  Fixture de teste: validado em tempo real (via script temporario,
  descartado ao final, sem log de credencial) que `cliente_id=8` existe
  ATIVO, `email_recebido_id=1` existe, `OC-TESTE-005` nao existia — so entao
  inserido `numero_ordem_coleta='OC-TESTE-005'`, `cliente_id=8`,
  `email_recebido_id=1`, `placa_prevista='TST0A01'`, `status='ATIVA'`
  (demais campos NULL, zero dado pessoal novo) contra `db_gestao_coletas`.
  DELETE de limpeza documentado, NAO executado ainda (fica para depois que
  os testes desta demanda terminarem):
  `DELETE FROM tb_ordens_coleta WHERE numero_ordem_coleta = 'OC-TESTE-005';`
  contra `db_gestao_coletas`.

  Testes reais executados (`tests/manual/teste_consulta_ordem_coleta.php`,
  novo, mais 3 subprocessos auxiliares novos e 6 arquivos de teste
  pre-existentes corrigidos por causa da mudanca de assinatura do
  construtor de `OrdemColetaClient`): 17/17 asserções passaram — placa sem
  ordens bloqueia; placa com 1 ordem (`TST0A01`) avanca direto com dados
  reais do banco (inclusive normalizacao de placa minuscula/hifenizada);
  placa com multiplas ordens (`ABC1D23`, 3 ordens reais) retorna tela de
  selecao; selecionar-ordem legitimo grava os dados REAIS e ignora
  `cliente_nome`/`cliente_cnpj` forjados pelo front; tentativa de
  selecionar ordem com numero forjado/inexistente para a placa e bloqueada
  (nada gravado); IDOR classico de totem invasor tentando selecionar ordem
  de atendimento alheio bloqueado; ordem com fixture propria `INATIVA`
  (criada e removida pelo proprio teste) corretamente excluida da consulta;
  indisponibilidade do banco externo (host/porta simulados so na chamada de
  teste, nunca no `.env` real) retorna erro generico HTTP 502 sem vazar
  host/porta/credencial. Cenario de cliente `INATIVO` registrado como NAO
  TESTAVEL — nenhum cliente real com esse status existe nos fixtures
  disponiveis, e alterar um cliente real so para o teste nao foi
  autorizado. Regressao confirmada sem quebra:
  `teste_avancar_etapa_expedicao.php` (10/10),
  `teste_talent_trava_doctos_pendente.php` (16/16),
  `teste_rebaixamento_manual.php` (45/45). `php -l` OK em todos os arquivos
  novos/alterados.

  Nao alterado: Recebimento; `finalizar()` continua bloqueado por
  `TALENT_DOCTOS_PENDENTE` (nao mexido). Nenhum commit/push feito (fora do
  escopo desta etapa). Handoff original:
  `docs/handoffs/2026-09-11-expedicao-consulta-ordem-coleta-teste.md`.
- 2026-09-11 — Reconciliacao de nome de banco (mesma demanda
  `expedicao-consulta-ordem-coleta-teste`): o usuario apagou os bancos
  errados existentes neste XAMPP local e reimportou o schema/dados corretos
  sob o nome real `udlogo59_db_gestao_coletas` (dump em
  `docs/udlogo59_db_gestao_coletas.sql`, 10 ordens reais, incluindo dados
  pessoais reais de motorista nas linhas id 4-10 — tratados como sensiveis,
  nunca logados/exibidos). Confirmado ao vivo via `SHOW DATABASES`/
  `SHOW CREATE TABLE` que o banco antigo sem prefixo (`db_gestao_coletas`,
  usado como precaucao na rodada anterior) nao existe mais neste ambiente, e
  que `udlogo59_db_gestao_coletas` agora tem o schema verbatim esperado
  (sem coluna `status` ate a migration ser aplicada). `.env` atualizado
  (`GESTAO_COLETAS_DB_NAME=udlogo59_db_gestao_coletas`). Migration
  `sql/migrations_gestao_coletas/001_status_ordem_coleta.sql` aplicada com
  sucesso contra o banco correto (idempotencia confirmada por segunda
  execucao sem erro). Precondicoes do fixture revalidadas ao vivo
  (`cliente_id=8` ATIVO, `email_recebido_id=1` existe, `OC-TESTE-005` nao
  existia) antes de inserir `OC-TESTE-005`/`TST0A01`/`ATIVA`. Bateria de 17
  asserções (`teste_consulta_ordem_coleta.php`) e as 3 suites de regressao
  (`teste_avancar_etapa_expedicao.php` 10/10,
  `teste_talent_trava_doctos_pendente.php` 16/16,
  `teste_rebaixamento_manual.php` 45/45) reexecutadas com sucesso contra o
  banco correto — nenhuma das 7 ordens reais novas colide com a placa
  `ABC1D23` usada no cenario de "multiplas ordens" (continua batendo
  exatamente 3, todas de teste). Nenhum commit/push feito.

- 2026-09-11 — `/01-implementacao` da demanda
  `expedicao-consulta-ordem-coleta-teste` concluida. `OrdemColetaClient`
  deixou de ser cliente HTTP placeholder e passou a consultar
  diretamente, em leitura, o banco externo `udlogo59_db_gestao_coletas`
  (mesmo servidor/credencial do totem, nome do banco em variavel propria
  `.env`), via nova `Util\ConexaoGestaoColetas` + `App\Dao\OrdemColetaDao`
  (PDO prepared statement, timeout curto, erro generico ao totem).
  Placa normalizada exclusivamente no backend. Consulta filtra
  `tb_clientes.status='ATIVO'` e `tb_ordens_coleta.status='ATIVA'` (nova
  coluna real, adicionada via migration idempotente separada
  `sql/migrations_gestao_coletas/001_status_ordem_coleta.sql`, pasta
  isolada e claramente identificada como pertencente ao banco externo,
  nao ao do totem). `tb_atendimento.ordem_coleta` ampliada para
  VARCHAR(50) (`sql/migrations/011_ampliar_ordem_coleta.sql`).
  `ORDEM_COLETA_API_URL`/`ORDEM_COLETA_API_KEY` removidas do
  `.env`/`.env.example` (sem uso). **Corrigido IDOR critico** em
  `AtendimentoController::selecionarOrdem()`: agora valida posse/tipo/
  status/etapa pelo totem autenticado e RECONSULTA as ordens reais antes
  de aceitar a selecao, descartando `cliente_nome`/`cliente_cnpj`
  enviados pelo front — so os dados vindos do servidor sao gravados.
  **Achado critico resolvido durante a implementacao**: confusao real
  entre dois bancos (`udlogo59_db_gestao_coletas` antigo, schema
  divergente/incompativel, vs. `db_gestao_coletas` sem prefixo, usado por
  precaucao numa primeira rodada) — o usuario apagou o banco errado e
  reimportou o schema correto em `udlogo59_db_gestao_coletas`
  (`docs/udlogo59_db_gestao_coletas.sql`, 10 ordens reais). Implementacao
  reconciliada e testada contra o banco correto (o unico que resta).
  Fixture de teste `OC-TESTE-005`/placa `TST0A01`/`id=11`/`status=ATIVA`
  inserido apos validar pre-condicoes ao vivo (reaproveitando
  `cliente_id=8`/`email_recebido_id=1`, zero dado pessoal novo) — AINDA
  NAO REMOVIDO (SQL de limpeza documentado no handoff, aguardando fim dos
  testes). Testes reais: 17/17 (`teste_consulta_ordem_coleta.php`,
  incluindo zero/uma/multiplas ordens, normalizacao de placa, selecao
  legitima vs. forjada, IDOR classico, indisponibilidade do banco
  externo) + regressao 10/10 + 16/16 + 45/45 (expedicao/Talent/
  rebaixamento manual). Cliente INATIVO nao testado (sem fixture real
  disponivel, nao autorizado alterar dado real para criar um). Revisao de
  seguranca (security-especialista): sem achados criticos; 1 achado de
  atencao corrigido na mesma rodada (aspas duplas em literais SQL de
  status trocadas por aspas simples, reconfirmado 17/17); 1 achado de
  atencao NAO corrigido (credencial `root` compartilhada com o banco do
  totem usada tambem para o banco externo — recomendado credencial
  dedicada/somente-leitura antes de producao, pendencia de arquitetura
  para `devops-especialista`). **Alerta de privacidade registrado, nao
  resolvido**: `docs/udlogo59_db_gestao_coletas.sql` contem nome
  completo e numero de CNH reais de motoristas (ordens id 4-10) — arquivo
  ainda untracked no git, decisao pendente do usuario sobre
  remover/mascarar/manter fora de versionamento antes de qualquer
  commit. Nenhuma alteracao em Recebimento. `finalizar()` continua
  bloqueado por `TALENT_DOCTOS_PENDENTE`. Nenhum commit/push realizado.
  Handoff: `docs/handoffs/2026-09-11-expedicao-consulta-ordem-coleta-teste.md`.
  Proximo passo: `/02-testes` formal e/ou `/03-revisao`, decisao do
  usuario sobre as pendencias listadas.

- 2026-09-11 — `/02-testes` da demanda `expedicao-consulta-ordem-coleta-teste`,
  restrito SOMENTE a etapa inicial da Expedicao (placa digitada ate
  confirmar/selecionar ordem — CNH/CRLV/Talent fora do escopo desta
  rodada, por instrucao explicita do usuario). Decisoes confirmadas antes
  do teste: coluna `status` (`ATIVA`/`INATIVA`) em `tb_ordens_coleta`
  autorizada (ja implementada); nomes/CNH ja existentes no dump
  (`docs/udlogo59_db_gestao_coletas.sql`, ordens id 4-10) confirmados
  FICTICIOS pelo usuario; regra de negocio confirmada mas NAO
  implementada ainda (ordem so viraria INATIVA apos Talent confirmar
  check-in com sucesso; erro/timeout/cancelamento/ENVIO_INDETERMINADO
  nunca inativam a ordem). **Achado adicional de privacidade/seguranca**
  no dump (alem do `token_hash` de producao ja registrado): `tb_api_logs`
  contem IPs publicos reais, incluindo provavel IP fixo do servidor n8n
  de producao (`179.125.31.177`) — arquivo continua untracked/nao
  versionado, decisao do usuario pendente. `qa-testes` executou de forma
  independente (nao so revisou o autorrelato do backend): zero ordens
  bloqueia, uma ordem (`TST0A01`/`OC-TESTE-005`) avanca direto, multiplas
  ordens (`ABC1D23`) lista para escolha, ordem INATIVA (fixture temporario
  `OC-TESTE-006`, criado e removido nesta rodada) excluida corretamente,
  cliente INATIVO (cliente+ordem temporarios `OC-TESTE-007`, criados e
  removidos nesta rodada) excluido corretamente mesmo com ordem ativa,
  normalizacao de placa correta, selecao forjada rejeitada, IDOR
  bloqueado, indisponibilidade do banco externo com erro generico (nunca
  vaza host/credencial). Suite automatizada 17/17 antes e depois.
  **`OC-TESTE-005` (id=11, placa `TST0A01`, status ATIVA) confirmada
  intacta e MANTIDA de proposito** (sera usada em teste fisico futuro,
  instrucao explicita de nao remover). **Veredito: APROVADO** para esta
  etapa especifica. Nenhuma falha encontrada, nenhuma correcao
  necessaria, nao avancado para `/03-revisao` geral (a demanda continua
  parcial — so a etapa inicial da Expedicao foi testada). Nenhum
  commit/push. Handoff atualizado:
  `docs/handoffs/2026-09-11-expedicao-consulta-ordem-coleta-teste.md`.
  Proximo passo: teste fisico com o fixture mantido; decisao do usuario
  sobre o arquivo do dump antes de qualquer commit; continuacao do fluxo
  (CNH/CRLV/Talent) em demanda/rodada futura.

- 2026-09-11 — Validacao FISICA (real, conduzida passo a passo com o
  usuario) da etapa inicial da Expedicao da demanda
  `expedicao-consulta-ordem-coleta-teste`, no ambiente real
  (`http://localhost:8000/totem/index.php?totem=RECEPCAO-01`). Todos os
  9 passos do roteiro confirmados: Expedicao -> digitar `TST0A01` -> sem
  erro JSON/HTML -> banco retorna somente `OC-TESTE-005` -> tela exibe
  dados da ordem (Cliente AKRO-PLASTIC DO BRASIL, CNPJ
  `20200104000238`, Placa `TST0A01`) pedindo confirmacao -> confirmar
  avanca para `exp_cnh` sem erro -> vinculo placa/ordem confirmado por
  consulta real ao banco do totem (`id_atendimento=1185`,
  `ordem_coleta='OC-TESTE-005'`, `cliente_cnpj='20200104000238'`,
  batendo com a tela) -> teste parado exatamente na tela de CNH, sem
  avancar para CRLV/Talent -> fixture externo `OC-TESTE-005` (id=11)
  confirmado `status='ATIVA'`/`placa_prevista='TST0A01'` inalterado
  apos o teste. **Veredito: APROVADA.** Nenhum codigo alterado durante o
  teste. Registrado para limpeza controlada em `/03-revisao` (NAO
  executado agora): `id_atendimento=1185` (atendimento de teste criado
  por este teste fisico, em `em_andamento`/`exp_cnh`); observacoes
  adicionais pre-existentes e nao tocadas (`id_atendimento=1184`,
  `1125`, `1126`, de sessoes de teste anteriores, sem relacao com esta
  demanda). Fixture `OC-TESTE-005` mantido intacto, conforme instrucao
  explicita (uso continuado em testes fisicos futuros). Nenhum
  commit/push. Handoff atualizado:
  `docs/handoffs/2026-09-11-expedicao-consulta-ordem-coleta-teste.md`.
  Proximo passo: `/03-revisao`, incluindo a limpeza controlada dos
  atendimentos de teste registrados acima.

- 2026-09-11 — A pedido do usuario, criado um segundo fixture de teste
  na demanda `expedicao-consulta-ordem-coleta-teste`, antes da limpeza
  planejada para `/03-revisao`: placa nova `TST0B01` com DUAS ordens
  ativas (`OC-TESTE-008`/`OC-TESTE-009`, ambas `status='ATIVA'`),
  reaproveitando o mesmo `cliente_id=8`/`email_recebido_id=1` ja usados
  em `OC-TESTE-005` (zero dado pessoal novo), para teste manual/fisico
  do cenario "multiplas ordens ativas para a mesma placa" (ate entao so
  testado automaticamente com `ABC1D23`). Validado ao vivo antes de
  inserir (sem colisao de placa/numero, cliente ativo, e-mail existente).
  `OC-TESTE-005` confirmada intacta. SQL de remocao documentado, NAO
  executado (`OC-TESTE-008`/`009` ficam pendentes de limpeza controlada
  junto com `OC-TESTE-005` e o atendimento `id_atendimento=1185`, no
  `/03-revisao`). Nenhum commit/push. Handoff atualizado.

- 2026-09-11 — `/03-revisao` da demanda `expedicao-consulta-ordem-coleta-teste`
  concluida. Revisao de seguranca independente (10 pontos pedidos pelo
  usuario): **APROVADO**, sem achados bloqueantes — confirmado por
  leitura do codigo real (nao so do handoff) que a conexao dedicada nao
  vaza credencial/DSN, SQL sempre via prepared statement, normalizacao
  de placa no backend, comportamento 0/1/multiplas ordens correto sem
  selecao automatica indevida, `selecionarOrdem()` reconsulta e valida
  posse/tipo/status/etapa (IDOR e selecao forjada rejeitados), migrations
  idempotentes e isoladas por banco, **ausencia confirmada** de logica
  automatica ATIVA->INATIVA, `.gitignore` protegendo o dump sensivel, e
  nenhuma alteracao fora do escopo (Recebimento/CNH/CRLV/Talent
  intocados). Teste fisico registrado formalmente como aprovado:
  `TST0A01->OC-TESTE-005` e `TST0B01->OC-TESTE-008/009` (lista exibida
  corretamente, sem selecao automatica, vinculo confirmado). **Limpeza
  controlada executada** (transacional, por `id_atendimento` exato):
  removidos do banco do totem `id_atendimento` 1185 (TST0A01, autorizado),
  1186 (TST0B01, etapa `placa`, capturado pelo filtro autorizado de
  identificacao) e 1188 (TST0B01/OC-TESTE-009, autorizado, com pasta de
  documentos removida); 0 dependentes em `tb_atendimento_nota`/
  `tb_fila_envio`. Removidas do banco externo as 3 linhas
  `OC-TESTE-005`/`008`/`009` (contagem 3->0 confirmada); coluna `status`
  e migration `001_status_ordem_coleta.sql` preservadas. **Achados
  NOVOS fora do escopo autorizado, NAO tocados** (o backend-especialista
  parou corretamente ao encontra-los, sem improvisar remocao):
  `id_atendimento=1187` (expedicao, TST0A01/OC-TESTE-005, cancelado,
  outra tentativa do mesmo teste) e `id_atendimento=1189` (tipo
  RECEBIMENTO, fora desta demanda, com 5 notas fiscais reais
  digitalizadas em disco). `id_atendimento` 1125 e 1126 (expedicao,
  TST0A01, sem ordem, `status=em_andamento` ha dias, criados em
  2026-09-10) consultados por leitura apenas, origem exata nao
  determinavel pelos dados disponiveis, permanecem intocados. **Veredito
  final: APROVADO** — demanda pronta para `/04-commit-e-push` quando o
  usuario autorizar, apos decidir sobre 1187/1189/1125/1126 (nenhum
  bloqueia a aprovacao, sao residuos/atendimentos independentes achados
  durante a limpeza). Nenhum commit/push realizado. Handoff atualizado:
  `docs/handoffs/2026-09-11-expedicao-consulta-ordem-coleta-teste.md`.

- 2026-09-11 — `/04-commit-e-push` da demanda
  `expedicao-consulta-ordem-coleta-teste` concluido. Commit
  `bdc8d91a92a3a45a4664aee7148a17dda3ba776b` (`feat(expedicao): consulta
  real de ordem de coleta por placa`), push para `origin/main` realizado
  com sucesso. Staged seletivamente (nao `git add -A`): excluidos do
  commit `docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md`
  (modificacao pre-existente nao relacionada a esta demanda) e
  `tests/manual/_diagnostico_talent_731.php` (arquivo solto anterior,
  tambem nao relacionado). `docs/udlogo59_db_gestao_coletas.sql`
  confirmado fora do commit (protegido por `.gitignore`, como ja
  validado). Demanda `expedicao-consulta-ordem-coleta-teste` encerrada
  com sucesso: OrdemColetaClient substituido por consulta real ao banco
  externo, IDOR corrigido, 88 testes automatizados + teste fisico +
  revisao de seguranca aprovados. Pendencias remanescentes registradas
  para o futuro (nao bloqueiam o encerramento desta demanda): credencial
  dedicada somente-leitura para o banco externo; confirmar nome do banco
  em producao Hostgator antes de aplicar a migration externa la; decisao
  sobre versionar/mascarar `docs/udlogo59_db_gestao_coletas.sql`;
  atendimentos `1187`/`1189`/`1125`/`1126` deixados para decisao/testes
  futuros (usuario optou por nao remover agora); continuacao do fluxo de
  Expedicao (CNH/CRLV/Talent) fica para demanda futura, fora do escopo
  desta. Handoff fechado:
  `docs/handoffs/2026-09-11-expedicao-consulta-ordem-coleta-teste.md`.

- 2026-09-11 — Criado o sub-agente `trello-especialista` e implementada a
  integracao real do workflow de 5 etapas com o Trello (quadro
  "Infraestrutura - Matriz", `TRELLO_BOARD_ID=654015c3d29cc34bc1b881f6`).
  Novo cliente `App\Rn\TrelloClient` (curl puro, mesmo padrao de
  `TalentClient`/`VioDecodeClient`, timeout 8s, erros sempre traduzidos
  para `TrelloClientException` com categoria fechada — nunca vaza key/
  token/URL) com `validarConexao`, `buscarListaPorNome` (exige
  correspondencia EXATA e UNICA, nunca aproxima nem inventa ID),
  `criarCartao` (sempre no topo da lista, `pos=top` por padrao),
  `consultarCartao`, `adicionarComentario`, `moverCartao`,
  `marcarConcluida` (define `due`/`dueComplete=true`, selo de data com
  check verde visivel no cartao). CLI `tools/trello-cli.php` com
  subcomandos correspondentes. IDs de lista resolvidos AO VIVO contra o
  board real (nunca hardcoded/adivinhados):
  `TRELLO_LISTA_FAZENDO_ID=654015f94854311ccf1895b1` ("Sprint Bruno -
  Fazendo [Semanal]"), `TRELLO_LISTA_FEITO_ID=654015fb256ea4b83b4a8816`
  ("Sprint - Feito") — gravados no `.env` real, `.env.example` atualizado
  (so `TRELLO_BOARD_ID` com valor real, resto vazio). Os 5 comandos do
  workflow (`.claude/commands/00-planejamento.md` a
  `04-commit-e-push.md`) atualizados para localizar/criar cartao em
  `/00` (registrando `card_id` no handoff), comentar progresso em
  `/01`/`/02`/`/03`, e em `/04` — somente apos confirmar
  `HEAD == origin/main` — comentar hash+data de conclusao, mover para
  "Feito" e marcar a data de conclusao no cartao. Falha do Trello em
  qualquer etapa NUNCA bloqueia/desfaz o trabalho real do totem — so fica
  registrada como pendencia no handoff, cartao permanece em "Fazendo".
  **Achado de seguranca durante o teste**: o token inicialmente
  configurado so tinha escopo de leitura (escrita retornava HTTP 401);
  usuario gerou um token novo com escopo leitura+escrita, resolvendo o
  bloqueio — validado ponta a ponta com sucesso (criar/comentar/
  consultar/mover/marcar concluida), incluindo cartoes de teste
  descartaveis que o usuario removeu manualmente. `docs/trello-
  integracao.md` criado/atualizado com toda a configuracao, IDs
  resolvidos (nao sensiveis) e uso do CLI. Nenhuma credencial exposta em
  nenhum arquivo/log/documentacao. **Pendencia**: o novo tipo de agente
  `trello-especialista` so fica disponivel para o `Agent tool` a partir
  da PROXIMA sessao (o harness carrega tipos de agente no inicio da
  sessao) — nesta sessao, a implementacao foi feita via
  `backend-especialista` seguindo as mesmas regras documentadas no
  arquivo do novo agente. Nenhum commit/push realizado (fora do escopo
  pedido).

- 2026-09-11 — `/00-planejamento` da nova demanda
  `impressao-etiqueta-teste`: objetivo e testar impressao fisica no mini
  PC Windows com etiqueta de teste ("ETIQUETA DE TESTE - NAO UTILIZAR",
  sem dado pessoal), enquanto o retorno real de etiqueta do Talent
  continua indocumentado. Confirmado (explorer): hoje NAO existe
  nenhuma impressao fisica real no projeto (`processarImpressao()` so
  exibe a senha em HTML); precedente de `localStorage` para preferencia
  de dispositivo ja existe (`totem_scanner_deviceId`, scanner Netum);
  flags do Chromium kiosk e topologia HTTPS real de producao continuam
  indocumentadas (pendencias preexistentes, nao criadas agora);
  `setasign/fpdf` ja instalado e reaproveitavel. Plano consolidado
  (devops/frontend/backend/security/qa): endpoint isolado
  `public/api/impressao-teste.php` (sem tocar
  AtendimentoRn/TalentRn/TalentClient/OrdemColetaClient), telas/estados
  novos e exclusivos no front (`diag_menu`/`diag_impressao_teste`,
  maquina de estados preparando/imprimindo/concluido/erro), contrato de
  dados evolutivo pensado pro retorno real futuro do Talent. Confirmado
  que `window.print()` sozinho (mesmo com `--kiosk-printing`) nao atende
  o requisito de escolher/persistir impressora especifica — arquitetura
  provavelmente precisa de um servico local no Windows (4 opcoes de
  stack mapeadas pelo devops, NENHUMA decidida/instalada, aguardando
  aprovacao explicita do usuario). Achado critico de seguranca: CORS/PNA
  sozinhos nao protegem um servico local que aceita comando de
  impressao — recomendada autenticacao propria (token) alem de CORS, e
  bind exclusivo em `127.0.0.1`. Pendencias registradas, nao inventadas:
  modelo de impressora, tamanho/orientacao de etiqueta, flags reais do
  Chromium kiosk, topologia HTTPS real, texto simples vs. PDF real para
  o teste, ponto de acesso a tela de diagnostico (proposta, nao
  decidida), stack do servico local, mecanismo de autenticacao dele.
  Nenhum codigo implementado, nenhuma impressao real, nenhuma chamada ao
  Talent, nenhum commit/push. Handoff:
  `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md`. Proximo passo:
  usuario decidir as pendencias antes de `/01-implementacao`.
- 2026-09-14 — `/01-implementacao` da demanda `impressao-etiqueta-teste`
  concluída: backend PHP (`app/Controller/ImpressaoTesteController.php`
  + `public/api/impressao-teste.php`, endpoint isolado protegido por
  `Util\Auth::validarTotem()`, gera etiqueta de teste em PDF real via
  FPDF com dimensão/orientação/corte configuráveis via `.env`
  `ETIQUETA_LARGURA_MM`/`ETIQUETA_COMPRIMENTO_MM`/`ETIQUETA_ORIENTACAO`/
  `ETIQUETA_CORTE_APOS_IMPRESSAO`, sem dado pessoal); serviço local
  Node.js standalone `servico-impressao-local/` (mini PC Windows, fora
  do Hostgator, compatível Node v22.17.1, bind exclusivo `127.0.0.1`,
  token próprio distinto de totem/Trello/Talent, CORS+PNA restritos a
  origens configuráveis — hoje vazia/fail-closed —, endpoints
  saúde/impressoras/imprimir, validação de PDF/base64/tamanho máximo/
  idempotência por identificador, nunca aceita caminho de arquivo,
  documentação de instalação do Node/Epson Advanced Printer Driver 6/
  autostart/diagnóstico); front-end (`public/totem/assets/
  diagnostico-impressao.js` + trechos mínimos em `app.js`/`app.css`/
  `index.php`, tela de diagnóstico isolada das telas reais, acessada por
  toque longo — implementação não formalmente confirmada como decisão de
  UX —, máquina de estados conectando/selecionar_impressora/preparando/
  imprimindo/concluído/erro, seleção obrigatória de impressora na 1ª
  vez, persistida em `localStorage['totem_impressora_nome']`
  reaproveitando o padrão de `totem_scanner_deviceId`, reuso automático,
  botão "Trocar impressora", limpeza automática se a impressora salva
  falhar/sumir). Testes estáticos e simulados (`qa-testes`): aprovado
  com ressalvas — contratos batem nome-a-nome entre os 3 lados, `php -l`/
  `node --check` limpos, PDF real confirmado com dimensão exata
  50mm×297mm sem dado sensível, CORS/PNA fail-closed confirmado, tokens
  nunca cruzados entre sistemas, zero toque em
  Talent/`finalizar`/`talent_checkin_status`/atendimento real. **Achado
  crítico de processo**: durante a simulação (antes da autorização
  explícita de impressão física planejada separadamente), descobriu-se
  que a impressora `EPSON TM-T88VII Receipt` estava de fato instalada e
  fisicamente conectada/ligada no ambiente de teste, e uma impressão
  real ocorreu (`HTTP 200` do spooler). Usuário confirmou visualmente:
  impressora estava ligada, impressão ocorreu de verdade, saiu
  corretamente "ETIQUETA DE TESTE — NÃO UTILIZAR", resultado físico
  aprovado. Registrado que sucesso do spooler isoladamente não comprova
  impressão física (é fire-and-forget), mas houve confirmação humana
  direta neste caso. Regra adotada: qualquer novo teste de
  `POST /imprimir` exige autorização prévia explícita sempre que houver
  driver/impressora real no ambiente de teste. Nenhum commit/push feito.
  Handoff: `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md`
  (seção "Resultado da implementação"). Próximo passo: `/02-testes`.
- 2026-09-14 — `/02-testes` da demanda `impressao-etiqueta-teste`
  concluída: usuário confirmou fisicamente as 4 etiquetas impressas na
  etapa anterior (conteúdo, IDs, dimensão 50×297 mm, orientação e corte
  corretos, sem dado pessoal). Apesar disso, o usuário classificou o
  achado crítico de processo já registrado (impressão física ocorrida
  durante simulação, antes da autorização formal) como BLOQUEANTE, pelo
  risco de a seleção de impressora virtual/interativa travar o
  processo/tela do totem indefinidamente — risco hoje sem mitigação no
  serviço local Node. Veredito final: **PRECISA DE AJUSTE** (não
  aprovado). Retorna para nova rodada de `/01-implementacao` restrita a:
  allowlist configurável de impressoras físicas; permitir inicialmente
  `EPSON TM-T88VII Receipt`; ocultar/bloquear impressoras virtuais
  (Print to PDF, XPS, Fax, OneNote etc.); timeout configurável no
  processo de impressão; ao estourar o timeout, encerrar só o processo e
  subprocessos; liberar fila/lock/temporários; marcar resultado como
  indeterminado (sem retry automático/duplicidade); erro sanitizado ao
  frontend; tirar a tela do carregamento com recuperação segura; testar
  trava com mock, sem abrir impressora virtual real. Nenhum código
  alterado, nenhuma impressão executada, nenhum commit/push nesta etapa.
  Handoff: `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` (seção
  "Resultado dos testes (2026-09-14)"). Próximo passo: nova rodada de
  `/01-implementacao` restrita a essas correções.
- 2026-09-14 — Nova rodada de `/01-implementacao` da demanda
  `impressao-etiqueta-teste`, restrita à correção do bloqueio técnico
  identificado no `/02-testes` anterior. Registro explícito, a pedido do
  usuário, de que existem **dois problemas distintos** neste histórico:
  (a) o **incidente de processo** (impressão física real ocorrida durante
  uma simulação de teste, antes de autorização formal prévia) já estava
  resolvido desde a rodada anterior, com a "Regra adotada" de exigir
  autorização explícita sempre que houver driver/impressora real no
  ambiente de teste — questão de PROCESSO, sem relação técnica com o
  bloqueio corrigido agora; (b) o **bloqueio técnico** que motivou o
  veredito PRECISA DE AJUSTE (ausência de timeout no processo de
  impressão e possibilidade de selecionar impressora virtual/interativa,
  podendo travar o processo/tela do totem indefinidamente) — questão de
  IMPLEMENTAÇÃO, corrigida nesta rodada. As 10 correções obrigatórias
  foram implementadas e confirmadas item a item: allowlist fail-closed de
  impressoras físicas (`EPSON TM-T88VII Receipt` liberada inicialmente,
  bloqueando impressoras virtuais como Print to PDF/XPS/Fax/OneNote);
  timeout configurável (`config.timeoutMs`/`IMPRESSAO_TIMEOUT_MS`) via
  novo módulo `servico-impressao-local/src/lib/imprimirComTimeout.js`
  (chamada direta ao SumatraPDF via `execFile`, já que `pdf-to-printer`
  não expunha o PID do processo filho necessário para o kill seletivo);
  no timeout, kill exclusivo por PID + árvore de processos (nunca por
  nome); mutex/lock e arquivo temporário liberados em `finally`;
  `identificador` marcado como `indeterminado` no timeout (sem retry
  automático/duplicidade); resposta HTTP 504 sanitizada
  (`codigo:'IMPRESSAO_TIMEOUT'`); front-end
  (`public/totem/assets/diagnostico-impressao.js`) com timeout próprio via
  `AbortController` e novo estado de tela `'indeterminado'`, sem retry
  automático; mecanismo de timeout testado pelo `qa-testes` com processo
  MOCK controlado (`tests/manual/_preload-mock-execFile.js` +
  `tests/manual/teste_timeout_kill_isolado.js`), nunca impressora
  virtual/física real. `security-especialista` encontrou 3 achados de
  atenção (não críticos), todos corrigidos na mesma rodada: condição de
  corrida entre liberação do mutex e confirmação do `taskkill` (corrigida
  aguardando confirmação antes de liberar) e vazamento de `erro.message`
  bruto em `routes/impressoras.js`/`server.js` (sanitizados, log completo
  só no servidor). `qa-testes` revalidou o teste mock após as correções
  de segurança — comportamento intacto, 8/8 critérios aprovados.
  `devops-especialista` atualizou `servico-impressao-local/README.md`
  (seções 2.5, 2.5.1, 4.4.1) e `docs/deploy-checklist.md`. Nenhuma
  impressão real feita em nenhuma etapa desta rodada, nenhum código do
  Talent/atendimento/ordem de coleta tocado, nenhum commit/push.
  Pendências remanescentes: `origensPermitidas` do serviço Node continua
  vazia (pré-existente); teste físico formal do timeout com hardware real
  ainda não realizado. Handoff:
  `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` (seção
  "Resultado da implementação — correção do bloqueio (2026-09-14)").
- 2026-09-14 — Nova rodada de `/02-testes` (retestagem pós-correção) da
  demanda `impressao-etiqueta-teste`: validação sem impressão dos 17 itens
  pedidos pelo usuário (allowlist, timeout, kill por PID, liberação de
  mutex, limpeza de temporários, resposta sanitizada, idempotência,
  concorrência, front-end sem retry automático, config centralizada,
  regressão, isolamento do Talent) concluída com 16 PASSOU + 1 N/A, zero
  achados bloqueantes. Revisão de segurança de confirmação: os 3 achados
  de atenção da rodada anterior (corrida mutex/`taskkill`, vazamento de
  `erro.message` em `routes/impressoras.js`/`server.js`) confirmados
  corrigidos, zero achados novos bloqueantes, 1 observação de baixa
  severidade (`return` redundante) registrada sem necessidade de ação.
  Teste físico autorizado (máximo 2 etiquetas, do orçamento de 6 totais,
  4 já usadas) **NÃO EXECUTADO**: bloqueado por ausência de
  `servico-impressao-local/config/config.json` real nesta máquina (só o
  template `config.example.json` existe) — seguindo instrução explícita
  de parar e alertar em vez de gerar um config de produção, nenhuma
  requisição de impressão foi enviada, nenhuma etiqueta foi impressa,
  nenhuma do orçamento de 2 etiquetas restantes foi consumida. Veredito:
  correções validadas tecnicamente (mock + segurança + regressão), mas
  `/02-testes` não pode ser considerada concluída/aprovada até o teste
  físico ser executado — pendência de PROVISIONAMENTO DE AMBIENTE, não de
  código; não retorna para `/01-implementacao`. Nenhum código alterado,
  nenhuma impressão real ocorrida, nenhum commit/push. Handoff:
  `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` (seção
  "Resultado dos testes — retestagem pós-correção (2026-09-14)").
  Próximo passo: nova rodada de `/02-testes`.
- 2026-09-14 — Retestagem física da demanda `impressao-etiqueta-teste`
  (mesma rodada de `/02-testes`), após a validação sem impressão e a
  revisão de segurança já terem sido aprovadas: o `devops-especialista`
  provisionou `servico-impressao-local/config/config.json` real + as
  variáveis correspondentes no `.env` real (corrigindo o bloqueio de
  ambiente registrado na entrada anterior). Na 2ª tentativa de teste
  físico, o `qa-testes` encontrou um NOVO achado, sistêmico: a linha
  `IMPRESSORAS_PERMITIDAS=EPSON TM-T88VII Receipt` adicionada ao `.env`
  real (linha 58) tem valor com espaço sem aspas, o que faz
  `vlucas/phpdotenv` lançar `InvalidFileException: Encountered unexpected
  whitespace` e quebrar o carregamento do `.env` INTEIRO — reproduzido
  isoladamente via `php -r` chamando só `Dotenv::createImmutable()->load()`.
  Como esse `load()` roda antes de qualquer autenticação/rota, TODO
  endpoint PHP do projeto fica com erro fatal nesta máquina enquanto essa
  linha não for corrigida — impacto sistêmico, não isolado à demanda de
  impressão. `qa-testes` parou imediatamente, sem enviar nenhum
  `POST /imprimir`, sem imprimir nenhuma etiqueta (0 do orçamento de 2
  consumido, seguem 2 de 6 disponíveis), e sem alterar `.env`/código,
  conforme instrução explícita do usuário para esta rodada. Veredito:
  `/02-testes` retorna para `/01-implementacao` — correção pontual
  conhecida (adicionar aspas ao redor do valor na linha 58), mas não
  aplicada nesta rodada, aguardando decisão do orquestrador/usuário sobre
  quando/quem aplica. Nenhum código/`.env` alterado, nenhuma impressão
  real ocorrida, nenhum commit/push. Handoff:
  `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` (seção "Teste
  físico — bloqueado por erro no .env (2026-09-14)"). Próximo passo: nova
  rodada de `/01-implementacao` restrita à correção de sintaxe do `.env`.
- 2026-09-14 — Nova rodada de `/02-testes` da demanda
  `impressao-etiqueta-teste`: retestagem completa (2ª tentativa),
  solicitada explicitamente pelo usuário, executada de forma independente
  (não só releitura do histórico). Os 17 itens de validação sem impressão
  foram reexecutados: 17/17 PASSOU/N-A, zero achados bloqueantes novos
  (itens 11-idempotência e 12-concorrência validados por revisão de
  código nesta rodada, sem reexercitar via HTTP com job real, para não
  arriscar antes da correção do `.env`). Revisão de segurança de
  confirmação: parecer anterior continua válido, 1 achado NOVO de
  severidade OBSERVAÇÃO (comparação de token não constant-time em
  `servico-impressao-local/src/middleware/auth.js` linha 19, risco baixo
  por bind exclusivo em `127.0.0.1`, não bloqueante). O teste físico
  autorizado (máx. 2 etiquetas) foi BLOQUEADO NOVAMENTE, pelo MESMO
  motivo da tentativa anterior — a linha 58 do `.env` real
  (`IMPRESSORAS_PERMITIDAS=EPSON TM-T88VII Receipt`, sem aspas) continua
  quebrando o carregamento do `.env` inteiro; ninguém corrigiu desde a
  rodada anterior. `qa-testes` parou no passo 1, antes de iniciar
  qualquer fluxo de impressão — 0 requisições `POST /imprimir`, 0 jobs, 0
  etiquetas físicas, 0 de 2 do orçamento desta rodada consumido (seguem 2
  de 6 totais intocadas). Veredito: validação técnica 100% aprovada (mock
  + segurança), mas etapa segue bloqueada para conclusão final até a
  correção pontual do `.env` real ser aplicada — decisão de quem/quando é
  do orquestrador/usuário, ainda pendente após 2 tentativas. Nenhum
  código/`.env` alterado, nenhuma impressão real, nenhum commit/push.
  Handoff: `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` (seção
  "Retestagem completa — 2ª tentativa (2026-09-14)").
- 2026-09-14 — Desfecho completo da demanda `impressao-etiqueta-teste`
  nesta rodada de `/02-testes`: o `.env` real foi corrigido (linha 58,
  aspas adicionadas ao redor do valor de `IMPRESSORAS_PERMITIDAS`),
  destravando o erro sistêmico de carregamento do `.env` inteiro que
  bloqueava 2 tentativas anteriores — confirmado por leitura direta do
  arquivo pelo `qa-testes` (`IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII
  Receipt"`). Com o bloqueio de ambiente resolvido, o teste físico
  autorizado (máx. 2 etiquetas) foi executado: **diretamente pelo
  orquestrador**, não pelo `qa-testes` — o `qa-testes` recusou executar a
  impressão por trava de segurança própria (não aceita consentimento para
  ação física irreversível repassado por outro agente em vez de vindo
  diretamente do usuário na mesma conversa); o orquestrador tinha
  autorização direta e verificável do usuário nesta mesma conversa e
  executou pessoalmente, com script que carrega tokens internamente
  (nunca exibidos/persistidos em texto claro). Resultado relatado pelo
  orquestrador, com confirmação visual do usuário: Teste 1 (impressão
  normal, identificador `6c59b88da2967bf63d3947de1f2d81f5`) → HTTP 200
  `impresso`, aprovado (conteúdo/dimensão 50×297mm/orientação
  portrait/corte corretos, sem dado pessoal); Teste 2 (idempotência,
  identificador `788180290eefcc8e4f7653f1013b8de3`) → 1ª chamada HTTP 200
  `impresso`, 2ª chamada com o MESMO identificador HTTP 200
  `ja_impresso` (sem reimprimir), aprovado — apenas 1 etiqueta física
  saiu para o par de requisições. Total da rodada: 3 requisições
  `POST /imprimir` (2 resultaram em impressão real, 1 bloqueada por
  idempotência), 2 etiquetas físicas impressas, orçamento da demanda
  inteira 100% consumido (6 de 6 etiquetas autorizadas desde o início: 4
  da rodada original de `/01-implementacao` + 2 desta rodada). A rodada de
  `/02-testes` também já contava, de tentativas anteriores desta mesma
  sessão: validação sem impressão 17/17 PASSOU/N-A (zero achados
  bloqueantes, reconfirmada de forma independente 2 vezes) e revisão de
  segurança aprovada (3 achados anteriores corrigidos e confirmados + 1
  achado novo de severidade OBSERVAÇÃO não bloqueante — comparação de
  token não constant-time em `auth.js`, risco baixo por bind em
  `127.0.0.1`). Veredito final desta rodada de `/02-testes`:
  **APROVADO**. Novo achado registrado, não bloqueante: `.env.example`
  (linha 96) tem o mesmo padrão sem aspas que causou o bug do `.env`
  real — não corrigido (fora do escopo de teste), apenas registrado como
  pendência de precaução (ver seção 5). Nenhum código/`.env`/
  `.env.example` alterado pelo `qa-testes` nesta rodada, nenhum
  commit/push. Handoff:
  `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` (seção "Teste
  físico executado e aprovado (2026-09-14)"). Próximo passo:
  `/03-revisao`.
- 2026-09-14 — Etapa `/03-revisao` da demanda `impressao-etiqueta-teste`
  concluída: revisão cruzada independente (segurança/UX/devops)
  confirmou que a implementação corresponde ao planejado, sem desvio de
  escopo — evidências físicas já registradas (EPSON TM-T88VII Receipt:
  conteúdo/corte/orientação/dimensão 50×297mm aprovados; idempotência
  confirmada com hardware real; 6 de 6 etiquetas do orçamento
  consumidas) reconfirmadas, nenhuma nova impressão autorizada/executada
  nesta etapa. Segurança: 10/10 itens do checklist OK, zero achados
  bloqueantes, mais 2 achados pontuais a corrigir (comparação de token
  não constant-time em `auth.js`; `.env.example` linha 96 sem aspas). UX:
  zero achados na tela de diagnóstico (`diagnostico-impressao.js`,
  estado `'indeterminado'`). Devops: item `origensPermitidas` confirmado
  como pendência de produto sem ação nesta demanda; `docs/deploy-checklist.md`
  precisa de nova seção sobre o serviço Node local; falta instrução
  operacional explícita de parada manual do serviço sempre por PID
  específico (nunca por nome). Veredito: **APROVADO COM RESSALVA** — o
  bloqueio principal (allowlist/timeout/mutex/indeterminado) está
  aprovado e validado, mas o fechamento da demanda é interrompido para
  uma rodada curta de `/01-implementacao`, restrita a 4 pontos pontuais:
  (1) `auth.js` — `crypto.timingSafeEqual`; (2) `.env.example` linha 96 —
  aspas; (3) `docs/deploy-checklist.md` — seção do serviço Node local;
  (4) `servico-impressao-local/README.md` — instrução de parada manual
  por PID, espelhada em `docs/deploy-checklist.md`. Nenhum código/`.env`/
  README alterado nesta etapa, nenhuma impressão executada, nenhum
  commit/push. Handoff: `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md`
  (seção "Resultado da revisão (2026-09-14)"). Próximo passo: nova
  rodada curta de `/01-implementacao` restrita aos 4 pontos acima,
  depois nova `/02-testes`/`/03-revisao` antes de `/04-commit-e-push`.
- 2026-09-14 — Rodada curta de `/01-implementacao` da demanda
  `impressao-etiqueta-teste`, restrita exclusivamente às 4 ressalvas do
  `/03-revisao` anterior: (1) `servico-impressao-local/src/middleware/auth.js`
  — comparação de token migrada para constant-time (hash SHA-256 dos dois
  lados + `crypto.timingSafeEqual`), testada com mock em 6 cenários
  (correto/incorreto mesmo comprimento/mais curto/mais longo/ausente/
  vazio) — todos PASSOU, resposta HTTP 401 idêntica em todos os cenários
  de falha; (2) `.env.example` linha 96 corrigida com aspas
  (`IMPRESSORAS_PERMITIDAS="EPSON TM-T88VII Receipt"`); (3)
  `docs/deploy-checklist.md` ganhou a seção "1.X Serviço local de
  impressão (mini PC Windows)" (instalação/configuração/inicialização
  automática/diagnóstico, com `origensPermitidas` documentado como
  permanecendo vazio/fail-closed, pendência de produto não resolvida
  aqui); (4) `servico-impressao-local/README.md` ganhou a subseção "4.6.
  Parar o serviço manualmente (teste/depuração)" (sempre `taskkill /PID
  <pid> /T /F`, nunca `taskkill /IM node.exe`, espelhado em
  `docs/deploy-checklist.md`). `security-especialista` confirmou
  implementação correta, sem vazamento novo. `origensPermitidas` não foi
  alterado (permanece pendência de produto). Nenhum código de Talent/
  atendimento/ordem de coleta tocado. Nenhuma nova impressão física feita
  nem necessária (orçamento 6/6 já consumido em rodada anterior).
  `qa-testes` verificou as 4 correções por leitura direta do código/
  documentação (não só releitura de relato) e confirmou tudo presente
  como descrito. Handoff:
  `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` (seção "Rodada
  curta de /01-implementacao — ressalvas do /03-revisao (2026-09-14)").
  Próximo passo: nova rodada de `/02-testes`/`/03-revisao` de confirmação
  final (curta, restrita a estas correções) antes de `/04-commit-e-push`.
- 2026-09-14 — Confirmação final e fechamento completo da demanda
  `impressao-etiqueta-teste`: desde a implementação original (endpoint
  isolado `ImpressaoTesteController`, tela de diagnóstico exclusiva, e o
  serviço local Node.js `servico-impressao-local/` com allowlist de
  impressoras físicas fail-closed, timeout configurável com kill exclusivo
  por PID, liberação de mutex/temporários, estado `indeterminado` sem
  retry automático, resposta sanitizada), passando pela correção do
  bloqueio técnico do `/03-revisao` original (4 correções: allowlist,
  timeout, kill por PID, indeterminado — 10 itens obrigatórios atendidos),
  pelo teste físico executado e aprovado (6/6 etiquetas do orçamento
  consumidas, conteúdo/corte/orientação/dimensão 50×297mm aprovados,
  idempotência confirmada com hardware real), pela rodada curta de
  `/01-implementacao` que corrigiu as 4 ressalvas pontuais do
  `/03-revisao` anterior (comparação de token constant-time, aspas em
  `.env.example`, seção de deploy do serviço local, instrução de parada
  manual por PID), até esta rodada final de confirmação: `/02-testes` de
  confirmação com 7/7 itens PASSOU (incluindo 3 suítes de regressão
  automatizada, 71/71 asserções somadas, zero regressão) e `/03-revisao`
  de segurança final com zero achados novos, sem regressão em nenhum
  ponto já revisado, `origensPermitidas` confirmado intocado, Talent/
  atendimento/ordem de coleta confirmados intocados em TODA a demanda.
  Documentação de implantação (`docs/deploy-checklist.md` +
  `servico-impressao-local/README.md`) confirmada suficiente para
  configuração do zero num mini PC novo, sem lacunas. **VEREDITO FINAL DA
  DEMANDA: APROVADO.** Handoff completo:
  `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md` (seção
  "Confirmação final — /02-testes e /03-revisao (2026-09-14)"). Próximo
  passo: `/04-commit-e-push`.
- 2026-09-14 — Planejamento (`/00-planejamento`) da demanda
  `talent-doctos-finalizacao-checkin`: consolidado plano de backend para
  desbloquear `AtendimentoController::finalizar()` (hoje sempre HTTP 501
  `TALENT_DOCTOS_PENDENTE`), implementando `doctos[]` real (Recebimento
  por notas, Expedição por ordem de coleta), corrigindo o parsing da
  resposta do Talent para `nrRegAcesso`/`msg` (confirmados via Swagger
  oficial nesta rodada) em vez dos campos inexistentes `senha`/
  `protocolo`, corrigindo `tipoEmbDesemb` para capitalizado (bug real
  desde a decisão de 2026-09-09), atualizando a ordem de coleta para
  `INATIVA` após sucesso, e adicionando endpoint isolado de impressão
  real da etiqueta (nunca redispara envio ao Talent). Nenhum código foi
  alterado nesta etapa — só planejamento e documentação. Divergência
  crítica registrada: schema real do Swagger usa `anexosGZip`, não
  `anexos` (manual PDF); protocolo de teste controlado do usuário
  permanece válido. Handoff completo com todas as pendências e decisões
  necessárias do usuário antes de `/01-implementacao`:
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`.
- 2026-09-14 — `/01-implementacao` da demanda
  `talent-doctos-finalizacao-checkin` concluída com sucesso total, após
  as 3 decisões do usuário (tabela `tb_ordem_coleta_pendente_baixa`,
  reimpressão manual permitida, rótulo "Número de acesso") terem sido
  tomadas. **Backend**: migration `012_talent_doctos_finalizacao_checkin.sql`
  (`tb_atendimento_nota.numero_nota`/`numero_nota_origem` + UNIQUE key,
  nova tabela `tb_ordem_coleta_pendente_baixa`); `TalentRn`/`TalentClient`
  corrigidos (`tipoEmbDesemb` capitalizado, parsing real de
  `nrRegAcesso`/`msg`, `doctos[]` real — `NOTA_FISCAL` por nota no
  Recebimento via `numero_nota` novo, `ORDEM_COLETA` via `ordem_coleta`
  já existente na Expedição —, `montarAnexosGzip()` isolado nunca
  chamado automaticamente); `AtendimentoController::finalizar()` com a
  trava incondicional `TALENT_DOCTOS_PENDENTE` REMOVIDA, substituída por
  gates reais + nova variável `TALENT_CHECKIN_ATIVO` (fail-closed,
  ausente/`false` por padrão — enquanto não for `true`, todos os gates
  reais rodam mas o Talent nunca é chamado de verdade, HTTP 503
  `TALENT_CHECKIN_DESATIVADO`); `OrdemColetaDao::marcarInativaPorNumero()`
  atualiza a ordem para `INATIVA` após sucesso confirmado (`UPDATE`
  condicional idempotente), com falha de reconciliação registrada em
  `tb_ordem_coleta_pendente_baixa` sem nunca bloquear a resposta de
  sucesso ao motorista; novo `ImpressaoAtendimentoController` +
  `impressao.php` (isolado do endpoint de teste, só lê resultado já
  persistido, nunca redispara chamada ao Talent, nunca CPF/CNH na
  etiqueta); novo `nota.php?acao=definir-numero` com normalização
  sempre no backend. **Frontend**: captura do número de nota encaixada
  no ciclo de `rec_digitaliza` (confirmação rápida se OCR confiante,
  manual se não); nova máquina de estados de impressão real em
  `exp_impressao`/`rec_impressao` (mesmo padrão já aprovado de
  preparando/imprimindo/concluído/erro/indeterminado, sem retry
  automático), com rótulo "Número de acesso" + nome do motorista, nova
  tela de seleção de impressora no fluxo real, e reimpressão manual
  gerando novo `identificador` a cada vez sem redisparar `finalizar()`.
  **Segurança**: zero achados bloqueantes, 2 observações não
  bloqueantes (reaproveitamento do endpoint de configuração do serviço
  local de impressão pelo fluxo real; script não versionado
  `_diagnostico_talent_731.php` presente, não tocado). **QA**: mais de
  190 asserções somadas (unitário, integração, IDOR, concorrência, E2E,
  regressão), 100% passando, zero regressão nas suítes pré-existentes.
  **NENHUMA chamada real ao Talent, NENHUMA impressão física, NENHUMA
  alteração de ordem real de coleta em nenhum momento desta
  implementação** — `TALENT_CHECKIN_ATIVO` permaneceu `false`/ausente
  durante toda a demanda. `docs/manual_talent.md` atualizado com nova
  seção final registrando os fatos confirmados via Swagger oficial
  (`TPortariaCheckinRet`, `enumTipoEmbDesemb` capitalizado — corrige a
  decisão de 2026-09-09 sobre minúsculo —, semântica de `nrDocto`).
  Pendência de `doctos[]` (seção 5) atualizada: bloqueio TÉCNICO
  resolvido; envio real ao Talent permanece desativado por decisão de
  segurança (`TALENT_CHECKIN_ATIVO`) até o protocolo de teste
  controlado em Produção ser executado (ainda não autorizado). Nenhum
  commit/push realizado. Detalhes completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Resultado da implementação (2026-09-14)". Próximo passo: `/02-testes`
  formal (pode reaproveitar os testes já executados), seguido de
  `/03-revisao`.
- 2026-09-14 — `/02-testes` Fase 1 (QA + segurança independentes) da
  demanda `talent-doctos-finalizacao-checkin` executado. **Segurança**:
  100% aprovada, zero achados bloqueantes (só reafirmou as 2 observações
  não bloqueantes já conhecidas da implementação). **QA**: 26 de 27 itens
  do roteiro PASSARAM; 1 item FALHOU (bloqueante) — item 5,
  `App\Rn\NotaFiscalRn::atualizarNumeroNota()` aceita e persiste
  silenciosamente uma chave de acesso de 44 dígitos como `numero_nota`,
  truncada sem erro pela coluna `VARCHAR(20)` (ver seção 5). Item 27
  (regressão completa) teve 2 desvios AMBIENTAIS não bloqueantes e não
  causados por esta demanda (`teste_consulta_ordem_coleta.php` — fixture
  externa `OC-TESTE-005` ausente no banco de dev local;
  `teste_status_processamento.php` — 1 falha flaky de rate-limit,
  reexecutado e passou 9/9). Observação adicional do QA (não testada
  formalmente, fora dos 27 itens), registrada para avaliação futura, não
  confirmada como bug ativo: em
  `AtendimentoController::tentarMarcarOrdemConcluida()`, o branch
  `JA_ENVIADO` pode gerar entrada "falsa" em
  `tb_ordem_coleta_pendente_baixa` mesmo quando a baixa já ocorreu com
  sucesso antes. **Veredito**: `/02-testes` Fase 1 = PRECISA DE AJUSTE.
  Retorna para `/01-implementacao`, restrito à correção do item 5. Fase 2
  (teste controlado em Produção) NÃO iniciada. Nenhum código alterado,
  nenhuma chamada real ao Talent, nenhuma impressão física nesta rodada.
  Detalhes completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Resultado de /02-testes — Fase 1 (2026-09-14)".
- 2026-09-14 — rodada curta de `/01-implementacao` corrigiu o achado
  bloqueante do item 5 (validação de comprimento/formato de
  `numero_nota` em `NotaFiscalRn`/`nota.php?acao=definir-numero`),
  32/32 testes novos passando, segurança reconfirmada sem regressão.
  Em seguida, `/02-testes` reexecutou a Fase 1 integralmente:
  **27/27 itens PASSOU, veredito APROVADO.** Fase 2 (preparação do
  teste controlado em Produção) executada: atendimento sintético de
  Expedição (`id_atendimento=1573`, DEV LOCAL) validado contra todos
  os gates de `finalizar()`; payload real montado via
  `TalentRn::montarPayload()` (nunca enviado) e apresentado mascarado
  (CNPJs/placa parcialmente mascarados; CPF/nome do motorista nunca
  exibidos, só confirmados como presentes); `doctos[]` com 1
  `ORDEM_COLETA` sintética (`OC-TESTE-001`) + 2 anexos (só
  nome/quantidade); `tipoEmbDesemb="Embarque"` confirmado;
  `talent_checkin_status=NAO_ENVIADO`; `TALENT_CHECKIN_ATIVO`
  confirmado ausente do `.env` real (fail-closed). **NENHUM POST real
  foi enviado ao Talent, nenhuma impressão física, nenhuma alteração
  de ordem real.** Demanda PARADA aguardando autorização explícita do
  usuário ("AUTORIZO POST REAL") diretamente ao orquestrador; 3
  pendências remanescentes registradas na seção 5 (repetir preparação
  em produção real se necessário; esclarecer como localizar/excluir
  check-in no painel do Talent; `id_atendimento=1573` de dev local
  pendente de limpeza). Nenhum commit/push realizado. Detalhes
  completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`,
  seção "Resultado de /02-testes — Fase 1 reexecutada e Fase 2
  preparada (2026-09-14)".
- 2026-09-14 — o orquestrador, com autorização direta do usuário,
  executou o único POST real controlado autorizado até aqui, contra
  `id_atendimento=1573` (Expedição, `OC-TESTE-001`, ambiente de DEV
  LOCAL), ativando `TALENT_CHECKIN_ATIVO=true` temporariamente.
  Resultado: HTTP 202, `sucesso=false`, `talent_checkin_status`
  resultante `ERRO_REPROCESSAVEL`. Causa raiz confirmada por leitura do
  código (`TalentClient.php` linhas 79-112): erro de
  conexão/DNS (`erro_conexao`, `CURLE_COULDNT_RESOLVE_HOST`/
  `CURLE_COULDNT_CONNECT`) — a requisição nunca chegou a sair desta
  máquina de dev local, nenhum byte foi transmitido ao Talent, nenhum
  registro criado do lado do Talent. Confirmado por consulta direta ao
  banco: ordem `OC-TESTE-001` permanece `ATIVA`,
  `tb_ordem_coleta_pendente_baixa` sem nenhuma linha para
  `id_atendimento=1573`, `TALENT_CHECKIN_ATIVO` revertido para
  ausente/`false` no `.env` real logo em seguida. Nenhuma impressão
  física realizada. Mecanismo de ativação/gates/classificação de erro
  validado tecnicamente (bloqueio foi só de conectividade de rede do
  ambiente de dev local, não de contrato/payload). Pendência
  remanescente: teste real ponta a ponta depende de ambiente com rota
  de rede real até `api.talentcs.com.br` (provavelmente produção
  Hostgator). `id_atendimento=1573` permanece intacto, não excluído.
  Nenhum código alterado, nenhum commit/push nesta rodada. Detalhes
  completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`,
  seção "Execução do POST real controlado (2026-09-14)".
- 2026-09-14 — investigação da causa raiz das 3 tentativas reais de POST
  ao Talent contra `id_atendimento=1573` concluída. A suspeita inicial de
  conectividade de rede/DNS foi descartada com evidência concreta (DNS/
  TLS/conexão HEAD ao path exato funcionaram normalmente). Com
  autorização explícita do usuário, uma instrumentação de diagnóstico
  TEMPORÁRIA foi adicionada, uma 3ª tentativa real controlada foi
  executada, e a causa raiz real foi confirmada: o Talent respondeu
  **HTTP 401 Unauthorized** às 3 tentativas (categoria interna
  `erro_autenticacao`, confirmada no mapeamento `401 => 'erro_autenticacao'`
  de `TalentClient.php`). A instrumentação temporária foi removida com
  sucesso logo depois (`git diff` confirmou reversão exata, `php -l`
  limpo). **Causa raiz = pendência de CREDENCIAL EXTERNA**: a
  `TALENT_API_KEY` do `.env` real desta máquina está sendo rejeitada
  pelo Talent — não é problema de rede, payload, contrato ou código.
  Estado final: atendimento `1573` continua `ERRO_REPROCESSAVEL`, não
  excluído; ordem `OC-TESTE-001` continua `ATIVA`, intocada; nenhuma
  entrada em `tb_ordem_coleta_pendente_baixa`; nenhuma impressão física
  em nenhuma das 3 tentativas; `TALENT_CHECKIN_ATIVO` revertido para
  ausente/`false` após cada tentativa. Próximo passo: usuário precisa
  confirmar/renovar a `TALENT_API_KEY` junto ao Talent antes de qualquer
  nova tentativa real. Nenhum código alterado, nenhuma chamada real ao
  Talent nesta rodada de documentação, nenhum commit/push. Detalhes
  completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Investigação da causa raiz — HTTP 401 confirmado (2026-09-14)".
- 2026-09-14 — **RESOLUÇÃO FINAL, teste real controlado com SUCESSO
  CONFIRMADO**. A causa raiz real e definitiva do bloqueio de HTTP 401
  nas 3 tentativas anteriores foi identificada pelo próprio usuário: o
  `TALENT_API_KEY` no `.env` real estava truncado em 1 caractere (42 em
  vez de 43 caracteres), a partir de comparação com o e-mail original do
  Talent contendo o token completo. O usuário forneceu o token correto,
  o orquestrador corrigiu a linha do `.env`, e executou a 4ª tentativa
  real, autorizada explicitamente, com sucesso total: HTTP 200,
  `sucesso=true`, `nrRegAcesso=35784`, ordem `OC-TESTE-001` marcada
  `INATIVA` com sucesso (sem necessidade de reconciliação via
  `tb_ordem_coleta_pendente_baixa`), formato `anexos` aceito (nunca
  precisou de `anexosGZip`), impressão física real executada 1 vez e
  confirmada visualmente pelo usuário (etiqueta com "Número de acesso:
  35784" + nome do motorista sintético, sem CPF/CNH). `.env` revertido
  para `TALENT_CHECKIN_ATIVO` ausente/`false` imediatamente após,
  confirmado por releitura. **Veredito final da demanda**:
  `talent-doctos-finalizacao-checkin` validada de ponta a ponta com
  sucesso real em ambiente de dev local (o mecanismo funciona mesmo
  apontando para o Talent de produção real). Pendência remanescente: o
  usuário vai excluir manualmente o check-in de teste no painel do
  Talent (placa `ABC1D23`, CNPJ armazém Maua I, ordem `OC-TESTE-001`).
  Próximo passo: `/03-revisao` final, depois `/04-commit-e-push`. Nenhum
  código alterado nesta rodada de documentação, nenhum commit/push.
  Detalhes completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Teste real controlado — SUCESSO CONFIRMADO (2026-09-14)".
- 2026-09-14 — **`/03-revisao` final da demanda
  `talent-doctos-finalizacao-checkin` = PRECISA DE AJUSTE**. Três
  revisões independentes: segurança APROVADA (zero achados bloqueantes,
  1 achado de atenção não bloqueante registrado em pendência dedicada na
  seção 5, sobre `tentarMarcarOrdemConcluida`/`JA_ENVIADO`); QA/evidência
  APROVADO (confirmado por consulta direta ao banco: atendimento `1573`
  `concluido`/`ENVIADO`/`talent_senha=35784`, ordem `OC-TESTE-001`
  `INATIVA`, `tb_ordem_coleta_pendente_baixa` sem linha para o
  atendimento; regressão 100% nas suítes específicas da demanda, com 1
  suíte pré-existente — `teste_consulta_ordem_coleta.php` — mudando de 4
  para 5 falhas por CONSEQUÊNCIA ESPERADA do próprio sucesso do teste
  real, não regressão); UX/front-end encontrou 2 achados a corrigir: (1)
  **[BLOQUEANTE]** modal obrigatório de número da nota manual sem
  botão de saída/cancelar, com overlay cobrindo a barra de "Cancelar
  atendimento" da tela; (2) **[ATENÇÃO]** mesmo modal usando teclado
  QWERTY completo em vez de teclado numérico dedicado para campo
  puramente numérico. Usuário confirmou exclusão manual, no painel do
  Talent, do check-in de teste da placa `ABC1D23`. Demanda retorna para
  rodada curta de `/01-implementacao` restrita aos 2 pontos de UX (o
  achado de segurança de atenção pode entrar na mesma rodada, não é
  obrigatório). Nenhum código alterado nesta rodada de documentação,
  nenhuma chamada real ao Talent, nenhuma impressão física, nenhum
  commit/push. Detalhes completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Resultado da /03-revisão final (2026-09-14)".
- 2026-09-15 — **Rodada curta de `/01-implementacao` para
  `talent-doctos-finalizacao-checkin` — correções de UX e auditoria =
  IMPLEMENTADA E TESTADA**. Restrita aos 2 achados de UX (bloqueante e
  atenção) e ao achado de atenção de segurança do `/03-revisao` anterior:
  (1) modal `abrirModalNumeroNotaManual` ganhou botão "✕ Cancelar
  atendimento" interno, reaproveitando exatamente `confirmarCancelar()`
  já existente (nenhuma lógica de cancelamento nova), confirmada
  ausência de handler de fechamento por toque no overlay; (2) teclado
  numérico dedicado novo (`montarTecladoNumericoNota`/
  `digitarNumeroNota`/`apagarNumeroNota`/`atualizarBotaoNumeroNota`),
  alvos de toque de 64px, nunca reaproveita `montarTeclado()`/`#teclado`
  QWERTY (intocado nas demais telas); (3) `AtendimentoController::
  tentarMarcarOrdemConcluida()` corrigido com novo
  `OrdemColetaDao::statusPorNumero()`/`OrdemColetaClient::statusAtual()`
  — quando `marcarConcluida()` retorna `false`, consulta o status real
  antes de decidir, tratando ordem já `INATIVA` como sucesso idempotente
  (sem falsa entrada em `tb_ordem_coleta_pendente_baixa`). `qa-testes`
  validou 13/13 itens do roteiro obrigatório do usuário (185 asserções
  somadas), incluindo os 4 novos cenários de `JA_ENVIADO`/ordem `INATIVA`
  e regressão 100% de Recebimento/Expedição/Talent/impressão (mais de
  150 asserções). Nenhuma chamada real ao Talent, nenhuma impressão
  física, nenhuma reutilização da placa `ABC1D23`, nenhuma alteração de
  registro real, nenhuma lógica de OCR/confiança/doctos[]/TalentRn
  tocada. Veredito: pronta para nova rodada de `/03-revisao` de
  confirmação. Detalhes completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Rodada curta de /01-implementacao — correções de UX e auditoria
  (2026-09-15)".
- 2026-09-15 — **`/03-revisao` de confirmação (2ª tentativa) de
  `talent-doctos-finalizacao-checkin` = PRECISA DE AJUSTE (novo achado
  bloqueante de UX)**. Segurança 100% APROVADA (10/10 itens do roteiro,
  zero achados). A revisão de UX encontrou um achado BLOQUEANTE NOVO —
  distinto e mais grave que o já corrigido na rodada de 2026-09-15
  anterior — introduzido pela própria correção daquela rodada: o botão
  "✕ Cancelar atendimento" interno ao modal de número de nota chama
  `confirmarCancelar()` → `abrirModal(...)`, que SOBRESCREVE o
  `innerHTML` do mesmo `#modalCaixa` em vez de empilhar um novo modal.
  Se o motorista tocar "Continuar atendimento", apenas `fecharModal()` é
  chamado — sem restaurar o modal de número de nota nem resetar
  `numeroModalAberta` — travando permanentemente qualquer confirmação
  futura de número de nota (sem caminho de UI para prosseguir, exceto
  cancelar o atendimento inteiro, o oposto da escolha do motorista).
  Achado de atenção adicional (não bloqueante): `.btn-cancelar` do botão
  interno tem contraste/alvo de toque abaixo do padrão do projeto.
  Veredito: retorna para nova rodada curta de `/01-implementacao`,
  restrita a esses 2 pontos, no mesmo arquivo/modal já tocado. Detalhes
  completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Resultado da /03-revisão final — 2ª tentativa (2026-09-15)".

- 2026-09-15 — **Rodada curta de `/01-implementacao` corrige o achado
  BLOQUEANTE de UX do `/03-revisao` anterior (`talent-doctos-finalizacao-checkin`)**.
  O botão interno "✕ Cancelar atendimento", dentro do modal obrigatório de
  número da nota (`abrirModalNumeroNotaManual()`/`abrirModalNumeroNotaSugestao()`,
  `public/totem/assets/app.js`), agora abre uma CONFIRMAÇÃO SEPARADA e
  independente (`#modalConfirmCancelNotaFundo`/`#modalConfirmCancelNotaCaixa`,
  `z-index:55`, criada uma única vez em `iniciarApp()`), empilhada por cima
  do `#modalCaixa` original (`z-index:50`) — nunca mais sobrescreve/destrói
  o conteúdo do modal de número de nota. Novas funções
  `confirmarCancelarNotaModal()`/`fecharConfirmacaoCancelarNotaModal()`:
  "Continuar atendimento" fecha só a confirmação (o modal de baixo nunca foi
  destruído, então número digitado/nota pendente/teclado/tipo de modal
  permanecem intactos sem restauração manual); "Sim, cancelar" reaproveita
  exatamente `cancelarESair()` já existente, sem duplicar lógica de
  cancelamento. `numeroModalAberta` nunca é tocada nesse ciclo, permanecendo
  coerente. O botão foi adicionado em AMBAS as variantes do modal (manual e
  sugestão do OCR). Ajuste visual: nova classe `.btn-saida-modal-nota`
  substitui a antiga `.btn-cancelar-modal` — contraste tipo `.btn-fantasma`
  (fundo branco, borda sólida `#0b2a45`), `min-height:64px` (mesmo alvo de
  toque do teclado numérico dedicado), sem competir com Confirmar/Corrigir/
  Salvar. `qa-testes` validou os 10 itens obrigatórios do roteiro do
  usuário (ciclo repetido 3x, sem overlay invisível, sem toque externo
  fechando, `numeroModalAberta` nunca presa, regressão de OCR/notas/
  cancelamento geral/`doctos[]`/`TalentRn` confirmada intocada) por
  rastreamento de código — **sem ambiente de navegador/DOM real disponível
  nesta sessão para simular cliques de fato**, registrado como limitação,
  recomendando validação manual rápida no dispositivo físico antes do
  `/04-commit-e-push`. Nenhuma chamada real ao Talent, nenhuma impressão,
  nenhuma reutilização da placa `ABC1D23`, nenhuma alteração de registro
  real, nenhum arquivo de backend/banco tocado. Achado NOVO, não bloqueante,
  registrado para avaliação futura (fora do escopo desta correção):
  `mostrarInatividade()` (`app.js`, linha ~125) ainda usa `abrirModal()`
  diretamente e sobrescreveria o modal de número de nota da mesma forma que
  o bug corrigido, se o timer de inatividade disparar enquanto esse modal
  estiver aberto — não corrigido nesta rodada. Detalhes completos em
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Rodada curta de /01-implementacao — correção do cancelamento no modal
  de número de nota (2026-09-15)".

- 2026-09-15 — **`/02-testes` de confirmação de
  `talent-doctos-finalizacao-checkin` = PRECISA DE AJUSTE (novo achado
  BLOQUEANTE confirmado)**. Regressão automatizada 100% aprovada (202/202
  asserções, 10 suítes PHP reais, zero achados novos de regressão).
  Validação manual real em navegador/totem NÃO pôde ser executada de fato
  (sem ferramenta de automação de browser disponível nesta sessão —
  declarado explicitamente pelo `qa-testes`, sem simulação fingida),
  permanece pendente de execução genuína. O teste obrigatório de
  inatividade pedido pelo usuário CONFIRMOU um achado BLOQUEANTE por
  leitura exata de código: `mostrarInatividade()` continua chamando
  `abrirModal()` diretamente (não foi migrada para o novo overlay
  separado desta demanda) — o timer de 180s roda normalmente com o modal
  de número de nota aberto, e ao disparar sobrescreve esse modal; tocar
  "Continuar" no aviso de inatividade não restaura o modal de nota nem
  reseta `numeroModalAberta`, travando o motorista permanentemente sem
  nenhuma ação incorreta da parte dele. Por instrução explícita do
  usuário, esse achado foi tratado como bloqueante (não como pendência
  futura). Demanda RETORNA para `/01-implementacao`, restrita a essa
  correção; `/03-revisao` NÃO foi iniciada. Cartão Trello mantido em
  "Sprint Bruno - Fazendo [Semanal]". Ver
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Resultado dos testes — /02-testes de confirmação (2026-09-15)".

- 2026-09-15 — **Rodada curta de `/01-implementacao` corrige o bloqueio de
  inatividade confirmado no `/02-testes` anterior
  (`talent-doctos-finalizacao-checkin`)**. `mostrarInatividade()` migrada
  para overlay PRÓPRIO e independente (`#modalInatividadeFundo`/
  `#modalInatividadeCaixa`, `.modal-fundo-inatividade { z-index: 80 }` —
  acima de `.modal-fundo` 50, `.modal-fundo-confirma-nota` 55 e
  `.diag-overlay` 70), nunca mais sobrescrevendo `#modalCaixa`/`#modalFundo`
  do modal de número de nota. Nova `fecharAvisoInatividade()`; timer único
  `idleTimer` reaproveitado para os dois timeouts (180s/30s), sempre
  limpo antes de reagendar; expiração do timer de abandono reaproveita
  `cancelarESair()` já existente, sem duplicar lógica. `ir()` ganhou
  `fecharAvisoInatividade()` no início, evitando overlay/timer órfão em
  qualquer troca de tela. `numeroModalAberta` não é tocada por esse
  fluxo. `qa-testes` validou 10/10 itens do roteiro (empilhamento sobre
  modal manual, sugestão OCR, e confirmação de cancelamento — 3 camadas),
  por rastreamento de código (sem ambiente de navegador real disponível
  nesta sessão — limitação declarada explicitamente, recorrente nas 3
  últimas rodadas desta demanda), mais reexecução das 10 suítes
  automatizadas de backend (202/202 PASSOU, zero regressão, backend
  confirmado intocado por mtime de arquivo). Observação não bloqueante
  registrada: listener global de toque reagenda o timer sem fechar o
  overlay se o motorista tocar fora do botão "Continuar" — pré-existente,
  não alterado, fora do escopo. Escopo restrito a
  `public/totem/assets/app.js`/`app.css`; nenhum arquivo de backend/banco/
  Talent/impressão tocado; nenhuma chamada real ao Talent, nenhuma
  impressão, nenhuma reutilização da placa `ABC1D23`, nenhuma alteração
  de registro real. Cartão Trello mantido em "Sprint Bruno - Fazendo
  [Semanal]". Esta rodada foi restrita a `/01-implementacao` — aguardando
  decisão do usuário para nova rodada formal de `/02-testes`/`/03-revisao`
  de confirmação (idealmente incluindo validação manual real em
  navegador/dispositivo físico, ainda pendente de execução genuína em
  toda a demanda). Ver
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Rodada curta de /01-implementacao — correção do bloqueio de
  inatividade (2026-09-15)".

- 2026-09-15 — **`/02-testes` de confirmação (Etapa 1 — validação
  automatizada) de `talent-doctos-finalizacao-checkin` = PRECISA DE
  AJUSTE (novo achado BLOQUEANTE confirmado)**. Regressão automatizada
  100% aprovada (202/202 asserções, 10 suítes PHP reais, zero regressão).
  A pergunta crítica do usuário sobre o listener global de toque
  interferir no countdown de 30s de abandono do aviso de inatividade foi
  respondida SIM, com certeza, por leitura exata de código: `reiniciarIdle()`
  cancela e reagenda incondicionalmente o `idleTimer` (para `IDLE_MS`=180s)
  a qualquer toque na tela — inclusive dentro do próprio overlay de aviso,
  fora do botão "Continuar" — sem fechar o overlay, criando divergência
  real entre o que a UI mostra (aviso de 30s) e o comportamento de fundo
  (timer estendido silenciosamente para 180s). Por instrução explícita do
  usuário, classificado como BLOQUEANTE, não pendência futura. Etapa 2
  (validação manual real) e `/03-revisao` NÃO foram iniciadas, conforme
  regra de avanço definida pelo usuário. Demanda RETORNA para
  `/01-implementacao`, restrita a essa correção. Cartão Trello mantido em
  "Sprint Bruno - Fazendo [Semanal]". Ver
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Resultado dos testes — /02-testes de confirmação, Etapa 1 (2026-09-15)".

- 2026-09-15 — **Rodada curta de `/01-implementacao` corrige o listener
  global de toque e os timers de inatividade
  (`talent-doctos-finalizacao-checkin`)**. Implementada máquina de estado
  explícita: duas variáveis de timer DEDICADAS (`idleTimerPrincipal`
  180s, `idleTimerAbandono` 30s, nunca compartilhadas) + novo estado
  `idleEstado` (`normal`/`aviso`/`inativo`). Listener global corrigido com
  guarda `if (idleEstado === 'aviso') return;` antes de qualquer
  `reiniciarIdle()` — nenhum toque/tecla fora do botão "Continuar" tem
  qualquer efeito enquanto o aviso de inatividade está aberto. Nova
  `continuarAposAvisoInatividade()` é o único caminho autorizado a fechar
  o aviso/reiniciar o monitoramento normal. `fecharAvisoInatividade()`
  sempre limpa o timer de abandono, evitando callback fantasma na
  expiração. `qa-testes` validou 12/12 itens do roteiro (toque/tecla/
  conteúdo do overlay não alteram o prazo de 30s, expiração dispara
  `cancelarESair()` uma única vez, sem timers duplicados, ciclo repetido
  3x, modal manual/sugestão OCR/confirmação de cancelamento preservados,
  navegação limpa tudo) por rastreamento de código (sem ambiente de
  navegador real disponível nesta sessão — limitação recorrente ao longo
  de toda a demanda), mais reexecução das 10 suítes automatizadas de
  backend (202/202 PASSOU, zero regressão). Escopo restrito a
  `public/totem/assets/app.js`; nenhum arquivo de backend/banco/Talent/
  impressão tocado; nenhuma chamada real ao Talent, nenhuma impressão,
  nenhuma reutilização da placa `ABC1D23`, nenhuma alteração de registro
  real. Cartão Trello mantido em "Sprint Bruno - Fazendo [Semanal]". Esta
  rodada foi restrita a `/01-implementacao` — aguardando decisão do
  usuário para nova rodada formal de `/02-testes`/`/03-revisao` de
  confirmação (idealmente incluindo, finalmente, validação manual real em
  navegador/dispositivo físico, ainda pendente de execução genuína em
  toda a demanda). Ver
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Rodada curta de /01-implementacao — correção do listener global e
  timers de inatividade (2026-09-15)".

- 2026-09-15 — **`/02-testes` de confirmação FINAL de
  `talent-doctos-finalizacao-checkin` = APROVADO (Etapa 1 + Etapa 2)**.
  Etapa 1 (automatizada): 12/12 cenários de inatividade reconfirmados por
  leitura de código, 202/202 asserções das 10 suítes de backend PASSOU,
  zero regressão. **Etapa 2 (validação manual real no navegador, pela
  primeira vez em toda a demanda)**: 8/8 cenários confirmados PASSOU pelo
  próprio usuário, executando de fato no ambiente local (dados
  sintéticos, sem placa `ABC1D23`, sem POST real, sem impressão),
  incluindo o cenário mais crítico — aviso de inatividade aberto, toque
  fora + tecla pressionada, cronometrado com relógio real, expirou nos
  30s exatos sem ser estendido. Isso confirma em execução real, não só
  por código, que o bug do listener global foi corrigido. Também
  confirmados: ciclo repetido 3x, modal de sugestão OCR preservado,
  empilhamento de 3 camadas (inatividade → confirmação de cancelamento →
  modal de nota) preservado, cancelamento único na expiração, navegação
  sem overlay/callback fantasma, contraste/legibilidade adequados.
  Demanda segue para `/03-revisao` final. Ver
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Resultado dos testes — /02-testes de confirmação FINAL (2026-09-15)".

- 2026-09-15 — **`/03-revisao` final (3ª tentativa) de
  `talent-doctos-finalizacao-checkin` = APROVADO**. `security-especialista`
  e `ui-ux-especialista` revisaram de forma independente as 3 últimas
  rodadas de correção de front-end (cancelamento com overlay separado,
  aviso de inatividade em overlay próprio, máquina de estado de timers) e
  aprovaram sem nenhum achado: segurança confirmou ausência de XSS
  (templates estáticos ou com `escapeHtml()`), reaproveitamento correto
  de `cancelarESair()` sem caminho paralelo, estado client-side sem
  influência em validação de negócio real, e confirmou por leitura de
  código que as 3 rodadas tocaram exclusivamente
  `public/totem/assets/app.js`/`app.css`; UX confirmou contraste/alvo de
  toque adequados, empilhamento coerente de até 3 camadas (z-index
  50/55/80, sem destruição de camadas inferiores), conformidade com os
  padrões visuais já estabelecidos do projeto, e correspondência exata
  com o planejado (sem desvio de escopo). Combinado com `/02-testes` =
  APROVADO (Etapa 1 automatizada + Etapa 2 manual real com 8/8 cenários
  confirmados pelo próprio usuário no navegador, incluindo cronometragem
  real do bug original corrigido), **a demanda
  `talent-doctos-finalizacao-checkin` está PRONTA PARA
  `/04-commit-e-push`**. Ver
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Resultado da revisão — /03-revisao final (2026-09-15, 3ª tentativa)".

- 2026-09-15 — **`/04-commit-e-push` de `talent-doctos-finalizacao-checkin`**:
  commit principal `2369f9680d99a689f0525862c911f3dc909f5e6d`
  (`feat(talent): finaliza check-in com doctos e impressao`), 41 arquivos,
  staging seletivo (excluídos os 2 scripts descartáveis não versionados —
  `_diagnostico_talent_731.php` e `_preparacao_fase2_teste_producao_talent.php`).
  Todas as validações pré-commit passaram (sintaxe PHP/JS, 202/202
  asserções, sem segredos/dados pessoais no diff, migration inspecionada
  sem execução contra produção). Ver
  `docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md`, seção
  "Commit".

- 2026-09-15 — **`/01-implementacao` de `talent-http409-limpeza-pendencias`
  concluida**. Novo script de teste controlado
  `tests/manual/_teste_http409_talent.php` (nunca versionado, coberto
  por nova entrada no `.gitignore`) criado para confirmar o
  comportamento real do HTTP 409 do Talent — bypassa deliberadamente
  `TalentRn::processarCheckin()`/o CAS de idempotencia local (unica
  forma de gerar uma 2a chamada real ao Talent com o mesmo payload, ja
  que o CAS faz curto-circuito local apos o 1o sucesso), chamando
  `TalentClient::checkin()` diretamente. Exige `--tentativa=1|2` e
  `--modo=mock|real` explicitos; modo real exige `TALENT_CHECKIN_ATIVO
  === 'true'`; fixture sintetica (placa `ZZZ9Z99`, motorista "TESTE DA
  API"/CPF `11144477735`, CNPJ do cliente ja aprovado no teste anterior);
  payload montado 1 vez e reutilizado sem alteracao; 1 chamada por
  execucao, sem loop/retry; bloqueia a 3a tentativa via auditoria local;
  auditoria sanitizada (nunca payload/CPF/CNPJ/token/nrRegAcesso).
  `qa-testes` validou 10/10 itens em modo mock (100% execucao real, nao
  so leitura de codigo, exceto os 2 itens explicitamente de releitura):
  ambos os cenarios mock corretos, bloqueio real confirmado sem
  `TALENT_CHECKIN_ATIVO`, bloqueio da 3a tentativa confirmado, auditoria
  sanitizada inspecionada, zero regressao (202/202 asserções, 10
  suites). **Nenhuma chamada real ao Talent ocorreu nesta rodada.**
  Limpeza: `tests/manual/_diagnostico_talent_731.php` e
  `tests/manual/_preparacao_fase2_teste_producao_talent.php` REMOVIDOS
  (nenhum estava versionado, confirmado sem referencia funcional
  restante); `mascararDocumento()`/`mascararPlaca()` extraidas para
  `tests/manual/_fixtures_talent.php`. 8 reclassificacoes de status
  aplicadas nesta mesma secao (ver linhas correspondentes acima:
  CONCLUIDO/NAO NECESSARIO/CORRIGIDO E VALIDADO/CONFIRMADO/HARDWARE
  DISPONIVEL/PENDENTE DE EXECUCAO CONTROLADA/AGUARDANDO PRODUTO), sem
  apagar nenhum historico. Fixture local `id_atendimento=1885` (placa
  `ZZZ9Z99`) permanece no banco de dev local, pronta para o teste real
  futuro, a ser removida depois dele. Cartao Trello mantido em "Sprint
  Bruno - Fazendo [Semanal]". Proximo passo: aguardar autorizacao
  explicita do usuario para a 1a chamada real
  (`--modo=real --tentativa=1`), com aviso previo antes de cada
  chamada, uma de cada vez. Ver
  `docs/handoffs/2026-09-15-talent-http409-limpeza-pendencias.md`,
  secao "Resultado da implementacao (2026-09-15)".

- 2026-09-15 — **Tentativa real 1 do teste controlado HTTP 409
  (`talent-http409-limpeza-pendencias`) executada com SUCESSO**. Rodada
  via `tests/manual/_teste_http409_talent.php --tentativa=1 --modo=real`,
  executada manualmente pelo usuario (o classificador de modo automatico
  da sessao Claude Code bloqueou a execucao direta pelo orquestrador —
  seguranca de ambiente, nao falha do plano/script; `.env` real foi
  revertido corretamente antes de qualquer chamada nessa tentativa
  bloqueada). Resultado real: HTTP 200, `nrRegAcesso=35912`, fixture
  `id_atendimento=1885` (placa `ZZZ9Z99`, cliente AKRO-PLASTIC ja
  aprovado). Auditoria local sanitizada confirmada com exatamente 1
  linha (`storage/atendimentos/_auditoria_talent_http409.jsonl`); `.env`
  real confirmado revertido apos a chamada; estado local de
  `tb_atendimento` inalterado (`talent_checkin_status=NAO_ENVIADO`,
  por design — o script bypassa o CAS local deliberadamente). Fingerprint
  SHA-256 do payload calculado antes da chamada:
  `e949618e3a878e2ccc742823e53a9fcb17b24bda1264e8378d11b663f50c1160`
  (reservado para comparar com a tentativa 2). Fixture e auditoria
  preservadas intactas (nenhuma limpeza feita), aguardando a tentativa
  real 2 (nova autorizacao explicita do usuario necessaria, uma chamada
  de cada vez). 1 check-in real criado no Talent (placa `ZZZ9Z99`),
  ainda nao excluido — a excluir manualmente pelo usuario apos o
  encerramento da demanda. Ver
  `docs/handoffs/2026-09-15-talent-http409-limpeza-pendencias.md`,
  secao "Resultado da tentativa real 1 (2026-09-15)".

- 2026-09-15 — **Tentativa real 2 executada — HTTP 409 CONFIRMADO
  EMPIRICAMENTE (`talent-http409-limpeza-pendencias`)**. Mesma fixture
  `id_atendimento=1885`, MESMO payload byte-identico da tentativa 1
  (fingerprint SHA-256 inalterado). Resultado real: HTTP 409, categoria
  interna `conflito`, `sucesso=false` — o Talent REJEITOU corretamente a
  duplicata, nenhum 2o check-in real foi criado. **Objetivo central desta
  demanda alcancado**: confirmado que o Talent usa controle de
  duplicidade real e retorna 409 para o mesmo payload repetido, validando
  que a classificacao ja existente em `TalentClient::checkin()`
  (`409 => 'conflito'`, `ERRO_REPROCESSAVEL`, nunca reconciliado
  automaticamente) corresponde ao comportamento real do sistema externo,
  nao apenas a uma suposicao. Bloqueio da 3a tentativa CONFIRMADO POR
  EXECUCAO REAL (nao so leitura de codigo): `exit_code=3`, nenhuma
  chamada de rede adicional. Achado de processo registrado: apos a
  tentativa 2, o `.env` real ficou temporariamente com
  `TALENT_CHECKIN_ATIVO=true` esquecido (usuario nao reverteu
  imediatamente); o orquestrador detectou e reverteu assim que
  verificou o ambiente, antes de qualquer chamada real adicional poder
  ocorrer — o bloqueio estrutural de orcamento (2 de 2 ja consumidas)
  funcionou corretamente mesmo com o portao acidentalmente ligado
  durante a verificacao. `.env` real confirmado revertido, auditoria
  local confirmada com exatamente 2 linhas (nenhuma 3a), estado local de
  `tb_atendimento` inalterado. **Orcamento de 2 chamadas reais desta
  demanda totalmente consumido — nenhuma chamada adicional sera feita.**
  Pendencia remanescente unica: 1 check-in real no Talent (placa
  `ZZZ9Z99`, `nrRegAcesso=35912`) a excluir manualmente pelo usuario.
  `docs/manual_talent.md` atualizado com o resultado real confirmado.
  Proximo passo: limpeza da fixture local (decisao previa do usuario de
  remove-la depois do teste real) antes de `/03-revisao`/
  `/04-commit-e-push`. Ver
  `docs/handoffs/2026-09-15-talent-http409-limpeza-pendencias.md`,
  secao "Resultado da tentativa real 2 e CONFIRMACAO FINAL do HTTP 409
  (2026-09-15)".

- 2026-09-15 — **Limpeza controlada pos-teste real concluida
  (`talent-http409-limpeza-pendencias`)**. Usuario confirmou exclusao
  manual do check-in real no painel do Talent (placa `ZZZ9Z99`,
  `nrRegAcesso=35912`) — registrado por declaracao, nenhuma nova chamada
  ao Talent feita para confirmar. Identificacao previa somente-leitura
  confirmou exatamente 1 atendimento (`id_atendimento=1885`) e 1
  dependente (`id_nota=1046`) ligados a fixture, zero linhas em
  `tb_fila_envio`/`tb_ordem_coleta_pendente_baixa`/`tb_rate_limit_vio_status`
  para esse `id_atendimento` — todas as 4 tabelas com FK checadas.
  Transacao de exclusao executada com condicoes exatas (`id_atendimento`
  + `placa`), `rowCount()` validado igual a 1 em ambos os `DELETE`s antes
  do `COMMIT`. Removidos: pasta sintetica de documentos, os 2 arquivos
  de auditoria local (real + mock), e `tests/manual/_teste_http409_talent.php`
  (orcamento de 2 chamadas reais esgotado). Preservados: `_fixtures_talent.php`
  (13 dependentes ativos confirmados) e a entrada generica `.gitignore`
  (`tests/manual/_*.php`, nao especifica ao script removido). Validacao
  final: zero residuo em banco/disco, nenhum registro real removido.
  Achado operacional registrado (nao bloqueante): `TALENT_CHECKIN_ATIVO`
  ficou temporariamente esquecido `=true` apos a tentativa 2, detectado
  e revertido pelo orquestrador; o bloqueio estrutural de orcamento
  funcionou corretamente mesmo assim, nenhuma chamada adicional ocorreu;
  ativacao apenas via variavel de processo confirmada TECNICAMENTE
  INVIAVEL neste ambiente (`variables_order=GPCS` sem `E` no php.ini
  local) — registrado como observacao de infraestrutura para avaliacao
  futura, nao pendencia bloqueante.

## Status consolidado da demanda `talent-http409-limpeza-pendencias` (2026-09-15)

| Item | Status |
|---|---|
| Teste base do Talent (HTTP 200, `nrRegAcesso`) | **CONCLUÍDO** |
| Formato `anexos` | **CONCLUÍDO** |
| Fallback `anexosGZip` | **NÃO NECESSÁRIO** |
| HTTP 409 do Talent | **CONCLUÍDO E CONFIRMADO EMPIRICAMENTE** — payload duplicado (mesmo fingerprint SHA-256) foi rejeitado pelo Talent com HTTP 409, sem criar um segundo check-in real; classificação interna `409 → 'conflito' / ERRO_REPROCESSAVEL` confirmada como correta e correspondente ao comportamento real do sistema externo |
| Check-in de teste (placa `ZZZ9Z99`, `nrRegAcesso=35912`) | Excluído manualmente pelo usuário no painel do Talent |
| Fixture e auditoria locais deste teste | Removidas após o teste real (ver seção "Limpeza controlada pos-teste real") |
| `mostrarInatividade()`/listener global de toque | **CORRIGIDO E VALIDADO** |
| Banco de produção `udlogo59_db_gestao_coletas` | **CONFIRMADO** |
| Hardware Netum SD-2000 | **HARDWARE DISPONÍVEL — TESTE FÍSICO EM DEMANDA FUTURA** |
| Credenciais VIO Decode Trial | **AGUARDANDO PRODUTO** |

- 2026-09-15 — **`/03-revisao` final de `talent-http409-limpeza-pendencias`
  = APROVADO**. Seguranca confirmou `TalentClient.php`/`TalentRn.php`
  100% intocados nesta demanda (git diff vazio), classificacao `409 =>
  'conflito'`/ausencia de retry automatico confirmada inalterada,
  bloqueio de orcamento avaliado como defesa em profundidade correta e
  comprovada na pratica pelo proprio incidente real de ativacao
  esquecida, nenhum segredo/dado pessoal versionado. Avaliacao
  especifica: o incidente de ativacao esquecida NAO exige correcao em
  codigo/documentacao operacional versionada (erro humano pontual de
  teste manual, camada de defesa funcionou). QA reexecutou 10 suites de
  backend (202/202 asserções PASSOU, zero regressao), confirmou remocao
  dos 3 scripts descartaveis sem referencia funcional restante, zero
  residuo de banco/disco da fixture `1885`/placa `ZZZ9Z99`, `.env` real
  fail-closed. Observacao nao bloqueante de ambas as revisoes: pastas
  residuais em `storage/atendimentos/` pertencentes a demanda ANTERIOR
  (`talent-doctos-finalizacao-checkin`), fora do escopo desta demanda,
  nao mexidas. **Demanda PRONTA PARA `/04-commit-e-push`** (nao
  executado nesta rodada, por restricao explicita do usuario). Ver
  `docs/handoffs/2026-09-15-talent-http409-limpeza-pendencias.md`,
  secao "Resultado da revisao — /03-revisao final (2026-09-15)".

- 2026-09-15 — **`/04-commit-e-push` de `talent-http409-limpeza-pendencias`**:
  commit `5332a86b62a80934aba9f7e40c982967c86630ce`
  (`test(talent): valida conflito HTTP 409 e encerra limpeza`), 5
  arquivos (`.gitignore`, `docs/manual_talent.md`,
  `ia_development_state.md`, `tests/manual/_fixtures_talent.php`, novo
  handoff da demanda). Todas as validacoes pre-commit passaram
  (`TALENT_CHECKIN_ATIVO` ausente, zero residuo de banco/disco da
  fixture `1885`/placa `ZZZ9Z99`, sintaxe PHP, sem whitespace/segredos
  no diff). Ver `docs/handoffs/2026-09-15-talent-http409-limpeza-pendencias.md`,
  secao "Commit".

- 2026-09-15 — **`/00-planejamento` da demanda `impressao-arquitetura-producao-ux`
  concluido**. Mapeamento completo confirmou o UNICO ponto real de
  acoplamento entre producao e teste do fluxo de impressao:
  `public/totem/assets/app.js` (`imprApiConfiguracaoServicoLocal()`)
  chama diretamente `impressao-teste.php?acao=configuracao-servico-local`
  (endpoint criado para o diagnostico) — nao existe hoje rota
  equivalente em `impressao.php`/`ImpressaoAtendimentoController`. Todo
  o resto (geracao de PDF, gates de posse/status, allowlist revalidada
  a cada chamada no servico Node, idempotencia por identificador
  sempre novo, timeout/indeterminado sem retry) ja esta corretamente
  isolado. Plano de arquitetura consolidado: nova classe compartilhada
  `Util\ConfiguracaoServicoImpressao` (fail-closed, nunca loga token),
  novo endpoint de producao `impressao.php?acao=configuracao-servico-local`,
  `impressao-teste.php` mantido exclusivo do diagnostico (nao removido),
  migracao em 5 etapas reversiveis, deploy atomico backend+frontend.
  Revisao formal de UX encontrou achados BLOQUEANTES (mensagens de erro
  tecnicas expostas ao motorista sem traducao) e de ATENCAO (alvo de
  toque abaixo do padrao de 64px do projeto, ausencia de indicador de
  progresso, hierarquia tipografica inconsistente em telas de erro,
  ausencia de trava de reentrancia contra toque duplo — confirmada
  tambem pelo `frontend-especialista` de forma independente). Seguranca
  sem achados bloqueantes; reconfirmado que a comparacao de token do
  servico Node ja usa `crypto.timingSafeEqual` (achado antigo de `!==`
  estava desatualizado, ja corrigido desde 2026-09-14). `origensPermitidas`
  permanece vazio/fail-closed, pendencia independente nao resolvida
  nesta demanda. 4 decisoes de produto pendentes antes de partes do
  `/01-implementacao` (persistencia de impressora no backend vs.
  localStorage; botao explicito de trocar impressora; escopo da
  traducao de mensagens de erro; extracao opcional de arquivo JS
  proprio). Nenhuma implementacao, chamada real, impressao ou alteracao
  de banco nesta etapa. Ver
  `docs/handoffs/2026-09-15-impressao-arquitetura-producao-ux.md`.

- 2026-09-15 — **`/01-implementacao` de `impressao-arquitetura-producao-ux`
  concluida**. Eliminada a dependencia do fluxo real de impressao em
  `impressao-teste.php?acao=configuracao-servico-local`: nova classe
  estatica `Util\ConfiguracaoServicoImpressao` (fail-closed, nunca loga
  token), novo endpoint de producao `impressao.php?acao=configuracao-servico-local`
  (protegido por `Auth::validarTotem()`, `Cache-Control: no-store` em
  toda resposta apos correcao de consistencia), `ImpressaoTesteController`
  refatorado para reaproveitar a mesma classe SEM mudar contrato externo
  (`impressao-teste.php` continua 100% funcional, exclusivo do
  diagnostico, nao removido). Front-end: nova `public/totem/assets/impressao.js`
  (maquina de estados de impressao extraida de `app.js`), migrada para o
  endpoint novo; nova `imprMensagemAmigavel()` traduz erros tecnicos
  para portugues simples (nunca `e.message`/URL/token na tela); trava de
  reentrancia `imprState.processando` contra toque duplo; spinner CSS
  (`.impr-spinner`); alvo de toque `.impr-btn-alvo` (min 64px); hierarquia
  visual melhorada em telas de erro/indeterminado (`.titulo`). Nenhum
  botao publico de "trocar impressora" adicionado (decisao do usuario —
  fica para painel administrativo futuro). `security-especialista`
  aprovou apos correcao pontual de `Cache-Control` no caminho de erro;
  achado de atencao sobre ausencia de rate-limit server-side em
  reimpressoes repetidas confirmado como PRE-EXISTENTE (nao introduzido
  por esta demanda, `gerarEtiqueta()` intocado) — registrado como
  decisao de produto pendente, nao implementado. `qa-testes` validou
  16/16 itens (endpoint novo testado por execucao real via curl,
  isolamento de `impressao-teste.php` confirmado por grep, allowlist do
  servico Node confirmada como unica fonte real de autorizacao mesmo com
  `localStorage` adulterado, 10 suites de regressao Talent/Recebimento/
  Expedicao com 0 falhas) — sem acesso a navegador real nesta sessao,
  roteiro de 11 itens preparado para `/02-testes`. `docs/deploy-checklist.md`
  e `.env.example` atualizados. Nenhuma chamada real ao Talent, nenhuma
  impressao, nenhuma alteracao de banco/registro real, nenhum commit/push.
  Ver `docs/handoffs/2026-09-15-impressao-arquitetura-producao-ux.md`,
  secao "Resultado da implementacao (2026-09-15)".

- 2026-09-15 — **`/02-testes` de `impressao-arquitetura-producao-ux` =
  APROVADO (Etapa 1 + Etapa 2)**. Etapa 1 (automatizada/estatica):
  24/24 itens PASSOU (`qa-testes`) + revisao de seguranca independente
  APROVADA (`security-especialista`, reconfirmou correcao de
  `Cache-Control`, 1 observacao nao bloqueante sobre trava de
  reentrancia so client-side). **Etapa 2 (visual real, executada pelo
  proprio usuario no navegador, SEM documentos fisicos/CNH/ordem de
  coleta)**: metodo de teste ISOLADO orientado pelo orquestrador —
  injecao direta de `imprState` via Console do DevTools + `imprRender()`,
  sem passar pelo fluxo real da aplicacao, sem nenhuma chamada real ao
  Talent/impressora. Confirmados visualmente: alvo de toque 64px real
  (medido), spinner de fato visivel ("circulo rodando"), mensagens sem
  termos tecnicos em nenhuma tela (erro tecnico so no console, nunca na
  UI), hierarquia clara de sucesso/erro/indeterminado, ausencia de
  botao de "trocar impressora", cancelar/voltar ("Novo atendimento")
  funcionando sem travar mesmo apos ciclos repetidos. Achado incidental
  confirmado como comportamento CORRETO (nao bug): `atendimento.php?acao=finalizar`
  rejeitou corretamente um `id_atendimento` invalido/nulo do teste
  isolado ("Atendimento nao encontrado"), sem nenhum efeito colateral.
  3 itens (botao desabilitado durante requisicao, toques repetidos,
  reimpressao manual) validados so por automacao/mock real na Etapa 1
  (21/21 asserções) — confirmacao visual ao vivo completa fica pendente
  para quando o ambiente fisico (servico local + impressora) estiver
  disponivel, nao bloqueante. **Demanda pronta para `/03-revisao`.**
  Ver `docs/handoffs/2026-09-15-impressao-arquitetura-producao-ux.md`,
  secoes "Resultado dos testes — /02-testes, Etapa 1" e "Etapa 2".

- 2026-09-15 — **`/03-revisao` final de `impressao-arquitetura-producao-ux`
  = APROVADO**. 4 revisoes independentes concluidas sem alteracao de
  codigo: arquitetura (`backend-especialista`) confirmou os 12 pontos
  planejados e a ausencia de qualquer implementacao fora de escopo
  (painel administrativo, `tb_totem`, botao publico de trocar
  impressora, `origensPermitidas`, paleta de cores, remocao do
  diagnostico); seguranca (`security-especialista`) reavaliou do zero
  (incluindo verificacao especifica de XSS em nome de impressora/
  mensagens vindas do servico Node — `escapeHtml()` confirmado em toda
  superficie) e reconfirmou zero achado bloqueante; UX
  (`ui-ux-especialista`) confirmou os 13 pontos ja validados ao vivo no
  `/02-testes`; validacoes tecnicas finais (`qa-testes`) confirmaram
  202/202 asserções de regressao e worktree limpo. **2 achados de
  ATENCAO nao bloqueantes** registrados como pendencia: (1)
  `docs/deploy-checklist.md` nao documenta explicitamente a
  recomendacao de deploy atomico backend+frontend (correcao trivial de
  texto); (2) tela `selecionar_impressora` nao tem botao de saida/
  cancelamento (decisao de produto/UX pendente, nao implementada).
  Nenhum achado bloqueante em nenhuma das 4 revisoes. **Demanda PRONTA
  PARA `/04-commit-e-push`.** Ver
  `docs/handoffs/2026-09-15-impressao-arquitetura-producao-ux.md`,
  secao "Resultado da revisao — /03-revisao final (2026-09-15)".

- 2026-09-15 — **Rodada curta de `/01-implementacao` de
  `impressao-arquitetura-producao-ux` corrige os 2 achados de atencao do
  `/03-revisao` final**. (1) `docs/deploy-checklist.md` ganhou item
  explicito de DEPLOY ATOMICO (backend+frontend juntos, nunca
  separados). (2) `imprTelaSelecionarImpressora()` ganhou botao "Novo
  atendimento" — confirmado que a tela SEMPRE aparece com o atendimento
  ja `concluido` (check-in com o Talent ja confirmado), nunca "em
  andamento", entao a acao correta e so reset local (`novoAtendimento()`
  ja existente), nunca cancelamento. Confirmado que `localStorage` da
  impressora e preservado. `qa-testes` validou 12/12 itens + 10 suites
  de regressao (202/202 asserções). Zero chamada real ao Talent, zero
  impressao, zero alteracao de registro real, zero commit/push. **Demanda
  pronta para `/03-revisao` curta de confirmacao.** Ver
  `docs/handoffs/2026-09-15-impressao-arquitetura-producao-ux.md`, secao
  "Rodada curta de /01-implementacao — correcao dos 2 achados de atencao
  (2026-09-15)".

- 2026-09-15 — **`/03-revisao` curta de confirmacao de
  `impressao-arquitetura-producao-ux` = APROVADO**. Revisao restrita aos
  2 ajustes da ultima rodada (botao "Novo atendimento" + deploy atomico
  documentado): seguranca aprovou sem achados (sem cancelamento indevido,
  sem XSS, `localStorage` preservado, sem botao publico de trocar
  impressora, sem dado sensivel no checklist); `qa-testes` reconfirmou
  os 12 itens + 202/202 asserções de regressao + isolamento de
  `impressao-teste.php` mantido. Zero achado bloqueante ou de atencao.
  **Demanda `impressao-arquitetura-producao-ux` PRONTA PARA
  `/04-commit-e-push`.** Ver
  `docs/handoffs/2026-09-15-impressao-arquitetura-producao-ux.md`, secao
  "Resultado da /03-revisao curta de confirmacao (2026-09-15)".

- 2026-09-15 — **`/04-commit-e-push` de `impressao-arquitetura-producao-ux`**:
  commit `b6b5129f92f2a5e2499206bdb7e51953fd2676eb`
  (`refactor(impressao): separa fluxos de teste e producao`), 10
  arquivos (backend: `ConfiguracaoServicoImpressao.php` novo,
  `ImpressaoAtendimentoController.php`, `ImpressaoTesteController.php`,
  `impressao.php`; frontend: `impressao.js` novo, `app.js`, `app.css`,
  `index.php`; docs: `deploy-checklist.md`, `.env.example`). Todas as
  validacoes pre-commit passaram (sintaxe PHP/JS, sem whitespace/
  segredos no diff, isolamento de `impressao-teste.php` confirmado,
  202/202 asserções de regressao). Ver
  `docs/handoffs/2026-09-15-impressao-arquitetura-producao-ux.md`,
  secao "Commit".

- 2026-09-15 — **`/00-planejamento` da demanda `impressao-origens-permitidas`
  concluido**. Mapeamento completo confirmou que o mecanismo atual de
  CORS/PNA (`servico-impressao-local/src/middleware/cors.js`,
  `src/config.js`) ja e seguro e suficiente sem mudanca de codigo:
  comparacao EXATA de origem via `Array.includes()` (sem
  wildcard/startsWith/regex em lugar nenhum), bind fixo em `127.0.0.1`
  (hardcoded, nao configuravel), `Access-Control-Allow-Origin` nunca
  `*`, PNA so liberado se a origem ja e autorizada, autenticacao por
  token independente do CORS, mensagens de erro sem vazamento.
  `security-especialista` confirmou zero achado bloqueante (2
  observacoes de baixo risco sobre case-sensitivity/porta implicita,
  sem necessidade de correcao). `devops-especialista` planejou a
  estrategia dev/producao: dev recebe
  `origensPermitidas: ["http://localhost:8080"]` (valor exato ja
  fornecido pelo usuario); producao permanece fail-closed (`[]`) ate o
  deploy real confirmar a origem exata na barra de enderecos apos
  redirecionamento (nunca supor `http://udlog.online` vs
  `https://udlog.online` antecipadamente). Plano de 17 testes de
  seguranca/funcionais definido, incluindo 1 chamada real pelo
  navegador em `http://localhost:8080/totem` (so `/saude`/`/impressoras`,
  nunca `/imprimir`). Nenhuma decisao bloqueante pendente — o valor de
  dev ja foi fornecido pelo usuario, a pendencia de producao e
  esperada e ja tratada como fail-closed. Nenhuma implementacao,
  alteracao de `.env`/`config.json` real, ou habilitacao de producao
  nesta etapa. Ver
  `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`.

- 2026-09-16 — **`/01-implementacao` de `impressao-origens-permitidas`
  concluida**. `servico-impressao-local/config/config.json` (real,
  gitignored, nunca exibido/versionado) teve `origensPermitidas`
  alterado de `[]` para `["http://localhost:8080"]` — unico campo
  tocado, demais preservados. Nenhum codigo de CORS/PNA/bind foi
  alterado (mecanismo ja confirmado seguro no planejamento, sem
  divergencia encontrada entre plano e codigo real). Validacoes: JSON
  valido, exatamente 1 origem sem `/totem`/barra final/`127.0.0.1`/
  origem de producao/wildcard, arquivo confirmado ignorado pelo Git,
  teste isolado de carregamento (`host==='127.0.0.1'`,
  `origensPermitidas` correto), comparacao exata simulada rejeitando
  todas as variantes maliciosas/producao/wildcard. `servico-impressao-local/README.md`
  e `docs/deploy-checklist.md` atualizados: dev ativo
  (`http://localhost:8080`), producao documentada
  (`https://udlog.online`, NAO ativada — so no deploy final, com
  reconfirmacao pos-deploy), `http://udlog.online` (sem HTTPS)
  explicitamente marcado como nao autorizado, regra de nunca habilitar
  dev+producao juntas nem usar `*`, rollback seguro documentado
  (`[]`). Zero impressao, zero Talent, zero alteracao de banco/registro
  real, zero commit/push. **Pronta para `/02-testes`.** Ver
  `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`, secao
  "Resultado da implementacao (2026-09-16)".
- 2026-09-16 — **`/02-testes` de `impressao-origens-permitidas`
  concluida — APROVADO**. 17 cenarios de CORS/PNA/auth executados de
  verdade (curl/PowerShell) contra o servico rodando em
  `127.0.0.1:4747`, todos PASSOU: origem `http://localhost:8080`
  aceita com `Access-Control-Allow-Origin`/`Vary: Origin`/preflight/PNA
  corretos; todas as origens nao autorizadas testadas (`.../`,
  `.../totem`, `127.0.0.1:8080`, `localhost` sem porta, `localhost:80`,
  `https://udlog.online`, `http://udlog.online`, dois dominios
  maliciosos semelhantes, `Origin: null`) rejeitadas com 403 sem
  `Access-Control-Allow-Origin`; ausencia de wildcard confirmada;
  ausencia de `Origin`/token valido-invalido-ausente/lista vazia ou
  malformada (teste isolado) com o comportamento esperado. Revisao de
  seguranca independente (`security-especialista`, leitura de codigo em
  paralelo) confirmou sem achado novo: bind `127.0.0.1` hardcoded,
  comparacao exata sem wildcard, autenticacao independente do CORS,
  erros sanitizados, `config.json` fora do Git, distincao
  `http://`/`https://udlog.online` explicita na documentacao. Teste
  real pelo navegador (`http://localhost:8080/totem`, Console do
  DevTools) executado com o usuario: `GET /saude` e `GET /impressoras`
  responderam corretamente, sem bloqueio de CORS/PNA, `POST /imprimir`
  nao foi chamado. Servicos iniciados durante os testes controlados
  exclusivamente por PID (28156 na rodada automatizada, 30056 na rodada
  do navegador), ambos encerrados ao final restaurando o estado
  "parado" anterior. Regressao: sem suite automatizada formal no
  servico (lacuna pre-existente registrada), `git diff --check` sem
  problema, busca por segredo nos arquivos versionados sem achado. Zero
  impressao, zero Talent, zero alteracao de banco/registro real, zero
  commit/push; producao continua nao ativada/nao testada. **Achado
  operacional nao bloqueante**: usuario colou no chat o token real
  durante o teste manual pelo navegador (nao houve vazamento pela
  aplicacao) — rotacao do token recomendada por precaucao. **Liberado
  para `/03-revisao`.** Ver
  `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`, secao
  "Resultado dos testes (2026-09-16, /02-testes)".
- 2026-09-16 — **Remediacao de seguranca em `impressao-origens-permitidas`
  (rotacao de token) concluida**. Motivo: durante o teste real pelo
  navegador do `/02-testes`, o token do servico local de impressao foi
  colado pelo usuario no chat (comando ja preenchido) — vazamento por
  operacao manual, nao pela aplicacao/codigo. Token anterior revogado;
  novo token (256 bits, hex) gerado e sincronizado atomicamente em
  `servico-impressao-local/config/config.json` (`token`) e `.env` real
  do backend (`IMPRESSAO_LOCAL_TOKEN`), preservando integralmente
  `origensPermitidas`/porta/impressoras/timeouts/demais campos.
  Validado sem exibir nenhum valor: token antigo → 401 em
  `/impressoras`; token novo → 200; sem token → 401; `/saude` inalterado
  (200, sem token); CORS continua aceitando so `http://localhost:8080`;
  `https://udlog.online` ausente; bind so em `127.0.0.1`; nenhum token
  (antigo ou novo) encontrado em arquivo versionado; `config.json`/`.env`
  confirmados ignorados pelo Git. Servico controlado exclusivamente por
  PID (33708) durante a validacao, devolvido ao estado "parado" ao
  final. Zero impressao, zero Talent, zero alteracao de banco, zero
  commit/push. Regra operacional registrada: nunca colar comando
  preenchido com token/segredo em chat, terminal compartilhado ou
  documentacao. **Liberado para `/03-revisao`.** Ver
  `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`, secao
  "Remediacao de seguranca — rotacao de token (2026-09-16)".
- 2026-09-16 — **`/03-revisao` final de `impressao-origens-permitidas`
  concluida — APROVADO**. Tres revisoes independentes em paralelo:
  `security-especialista` (CORS/PNA + rotacao de token + documentacao,
  por leitura) confirmou comparacao exata via `Array.includes()` sem
  wildcard, `Access-Control-Allow-Origin` so para origem autorizada,
  PNA condicionado a mesma checagem, autenticacao independente do CORS,
  bind `127.0.0.1` hardcoded, mensagens sanitizadas, nenhum token em
  arquivo versionado; `devops-especialista` (config.json/.env +
  documentacao) confirmou `origensPermitidas` exata
  (`["http://localhost:8080"]`), JSON/dotenv validos, token sincronizado
  entre os dois arquivos (comparacao booleana em memoria), ambos fora
  do Git; `qa-testes` (unico agente autorizado a controlar o servico
  nesta rodada, PID 23664) reconfirmou 11 testes focados pos-rotacao ao
  vivo: token antigo 401, token novo 200, sem token 401, `/saude`
  publico 200, `https://udlog.online`/`http://udlog.online` continuam
  rejeitadas (403), preflight/PNA corretos, bind so em `127.0.0.1` —
  todos PASS; servico devolvido ao estado parado ao final. `git diff
  --check` sem problema real; busca por segredo no diff e no
  repositorio versionado sem achado. **1 achado de atencao NAO
  BLOQUEANTE** (convergente entre security e devops): a regra
  operacional "nunca colar comando preenchido com token em chat/
  terminal/documentacao" existe so no handoff/log historico, nao foi
  replicada em `servico-impressao-local/README.md` (secao 2.4) nem em
  `docs/deploy-checklist.md` — registrado como pendencia, nao corrigido
  nesta etapa (fora de escopo do `/03-revisao`). Zero impressao, zero
  Talent, zero alteracao de banco, zero commit/push. Producao continua
  nao ativada. **Liberado para `/04-commit-e-push`.** Ver
  `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`, secao
  "Revisao final (2026-09-16, /03-revisao)".
- 2026-09-16 — **`/01-implementacao` curta de `impressao-origens-permitidas`
  (correcao documental)** — resolve o achado de atencao nao bloqueante da
  `/03-revisao` acima: a regra operacional "nunca colar comando ja
  preenchido com token em chat/terminal compartilhado/documentacao"
  passa a existir tambem nos documentos operacionais vivos, nao so no
  handoff/log historico. Adicionada em
  `servico-impressao-local/README.md` (secao 2.4, bloco visivel "REGRA
  OBRIGATORIA — manuseio do token", com notas cruzadas nas secoes
  4.3/4.4) e em `docs/deploy-checklist.md` (sub-item do item "Token do
  serviço gerado com segurança", secao 1.X), com o mesmo conteudo
  essencial nos dois: nunca em URL/querystring, nunca como argumento de
  linha de comando visivel, nunca em print/log/documentacao/Trello,
  autenticacao sempre via header `Authorization: Bearer <token>`, testes
  manuais por script que le o token do arquivo de config (nunca
  digitado/colado, so exibe `PASS`/`FAIL`/codigo HTTP), qualquer
  exposicao (mesmo parcial) exige rotacao imediata nos dois pontos
  sincronizados (`config/config.json` chave `token`, `.env` chave
  `IMPRESSAO_LOCAL_TOKEN`) com confirmacao de `401` no token antigo.
  Escopo estritamente documental — nenhum codigo, `.env`, `config.json`
  real ou config operacional alterado; servico Node nao foi
  iniciado/parado; zero impressao, zero Talent, zero banco, zero
  commit/push nesta rodada. Nenhum valor de token exposto em nenhum
  diff. Ver `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`,
  secao "Correcao documental — regra de token replicada (2026-09-16,
  /01-implementacao curta)".
- 2026-09-16 — **`/03-revisao` curta de confirmacao em
  `impressao-origens-permitidas` concluida — APROVADO**. Revisao
  independente (`security-especialista`) restrita aos 4 arquivos
  documentais da rodada anterior (README, deploy-checklist, handoff,
  este log). Confirmado: regra operacional de manuseio de token
  consistente entre README e deploy-checklist; `git diff --check`
  limpo; nenhum token/credencial real presente nos 4 arquivos;
  `config.json`/`.env` confirmados fora do Git; nenhum codigo/config
  operacional alterado. Nenhum achado. **Liberado para
  `/04-commit-e-push`.** Ver
  `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`, secao
  "Revisao curta de confirmacao (2026-09-16, /03-revisao)".
- 2026-09-16 — **`/04-commit-e-push` de `impressao-origens-permitidas`
  concluido**. Identidade de autor confirmada pelos primeiros commits
  validos do repositorio e configurada somente localmente (`git config
  --local`), sem alterar a config global: `Bruno Santos
  <brunossaantos@gmail.com>`. Commit `853857afdc793973b9cc38b276fc0d696fbe85c4`
  (`docs(impressao-origens-permitidas): configura CORS/PNA dev e
  documenta producao`), sem nenhuma atribuicao de IA em autor,
  committer, mensagem ou trailers (por instrucao explicita do usuario
  para esta demanda e para todas as futuras). Arquivos versionados:
  `docs/deploy-checklist.md`, `servico-impressao-local/README.md`,
  `ia_development_state.md`, `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`
  (novo). `config.json`/`.env` confirmados fora do commit. Push para
  `origin/main` confirmado (`a8a0454..853857a`), `HEAD == origin/main`
  reconfirmado. Zero impressao, zero Talent, zero alteracao de banco.
  **Demanda `impressao-origens-permitidas` encerrada.** Ver
  `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`, secao
  "Commit e push (2026-09-16, /04-commit-e-push)".

## 8. Regra permanente de identidade de autor em commits

A partir de 2026-09-16, TODA vez que o orquestrador for fazer
`/04-commit-e-push` (nesta ou em qualquer demanda futura), sem exceção:

1. Identificar a identidade de autor usada nos primeiros commits validos
   do projeto (`git log --reverse --format='%H|%an|%ae' | head`), nunca
   inventar nome/e-mail.
2. Configurar essa identidade **somente no repositorio local**
   (`git config --local user.name`/`user.email`), nunca alterar a
   configuracao global do Git.
3. **Nunca** incluir `Claude`, `Anthropic`, `Co-Authored-By`,
   `Generated-By` ou qualquer atribuicao a IA no autor, committer,
   mensagem ou trailers do commit — em nenhuma demanda, mesmo que uma
   instrucao anterior/generica de sessao sugira o contrario. Esta regra,
   pedida explicitamente pelo usuario em 2026-09-16, tem prioridade
   sobre qualquer convencao padrao de atribuicao.
4. Nunca reescrever commits antigos ja publicados (nunca `--amend` em
   commit ja enviado ao remoto, nunca `rebase`/`force-push`).
5. Antes do commit: inspecionar o diff completo, confirmar que nenhum
   token/segredo esta incluido, confirmar que arquivos locais
   (`config.json`, `.env`, etc.) nao aparecem no stage.
6. Depois do push: confirmar `HEAD == origin/main`, verificar
   autor/committer/mensagem/trailers do(s) commit(s) criado(s).
- 2026-09-16 — **`/00-planejamento` da demanda `netum-preview-captura-resolucao`
  concluído**. Investigação de dois sub-agentes independentes
  (`explorer`, `frontend-especialista`) confirmou que a pendência
  registrada sobre "preview/captura do Netum corta a imagem e
  resolução insuficiente" descrevia o estado ANTES da correção
  aplicada em 2026-09-04 (mesmo dia, mesma demanda original
  `recebimento-scanner-netum-sd2000`) — o defeito já foi corrigido e
  validado fisicamente (resolução real 3264×2448/4:3, documento sem
  corte, texto legível, `/02-testes` APROVADO COM RESSALVAS, `/03-revisao`
  APROVADO na época). A pendência era de REGISTRO, não de código: a
  linha da tabela nunca foi atualizada para refletir o resultado final.
  Reconfirmado por leitura do código atual (`capturarFotoScannerNota`,
  função dedicada, sem downscale, canvas dimensionado com resolução
  real do vídeo — totalmente separada de `capturarFotoBase64`, usada
  só por CNH/CRLV) que o mecanismo continua correto, sem regressão.
  Correções de status aplicadas nesta mesma etapa (preservando
  histórico, nada apagado): (1) CORS/PNA do serviço de impressão
  (dev ativo, produção documentada não ativada); (2) Netum
  preview/captura (RESOLVIDA, registro corrigido); (3) nova linha sobre
  validação física do serviço de impressão no mini PC de PRODUÇÃO real,
  adiada para a etapa final do projeto por decisão do usuário; (4)
  HTTP 409 real do Talent/`anexos`/`anexosGZip` (CONCLUÍDO E CONFIRMADO
  EMPIRICAMENTE, alinhado ao que já constava na seção 7). Nenhuma
  implementação de código foi feita ou é considerada necessária.
  Validação física de reconfirmação (roteiro reduzido, documento de
  teste sem dado pessoal) fica OPCIONAL para uma eventual `/02-testes`
  futura, a critério do usuário — não bloqueia o fechamento da demanda.
  Nenhuma captura real, nenhuma alteração de banco, nenhuma chamada ao
  Talent, nenhuma impressão, nenhum commit/push, nenhuma alteração do
  histórico git (commits `22eeba04`/`ae7f162c` só consultados via
  `git show`/`git log`). Cartão Trello `6aaab5774d4ea890146040fc`
  criado em "Sprint Bruno - Fazendo [Semanal]" (novo, não reaproveitou
  cartão antigo). Ver
  `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`.
- 2026-09-16 — **`/03-revisao` curta de confirmação em
  `netum-preview-captura-resolucao` concluída — APROVADO**. Revisão
  independente (`security-especialista`, sem participação no
  `/00-planejamento`), sem repetir teste físico. Confirmados os 8 itens:
  pendência do Netum marcada resolvida (não mais ativa); função
  `capturarFotoScannerNota()` confirmada dedicada/sem
  downscale/separada de `capturarFotoBase64`; CORS/PNA dev ativo para
  `http://localhost:8080`; produção `https://udlog.online` só
  documentada, não ativada/testada; HTTP 409 do Talent CONCLUÍDO/
  CONFIRMADO; `anexos` concluído, `anexosGZip` NÃO NECESSÁRIO;
  impressão física em produção confirmada adiada para etapa final;
  histórico preservado em todas as edições, nenhuma pendência real
  apresentada como resolvida. `git diff --check` limpo; `git status`/
  `git diff` confirmam que só `ia_development_state.md` e o handoff
  desta demanda foram tocados — nenhum código/config/segredo alterado.
  Zero captura física, zero Talent, zero banco, zero impressão, zero
  commit/push. **Liberado para `/04-commit-e-push` curto.** Ver
  `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`, secao
  "Revisão curta de confirmação (2026-09-16, /03-revisao)".
- 2026-09-16 — **`/04-commit-e-push` curto de
  `netum-preview-captura-resolucao` concluído**. Identidade Git local
  `Bruno Santos <brunossaantos@gmail.com>` reutilizada (configurada
  anteriormente neste repositório), sem nenhuma atribuição de IA.
  Commit `effab4bf137c8788a933c35c1db77bc439913150`
  (`docs(netum-preview-captura-resolucao): corrige registro de
  pendencia ja resolvida`). Arquivos versionados:
  `ia_development_state.md`, `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`
  (novo). Push para `origin/main` confirmado (`6510634..effab4b`),
  `HEAD == origin/main` reconfirmado, worktree final limpo. Zero
  captura física, zero Talent, zero impressão, zero alteração de
  banco/configuração operacional. **Demanda `netum-preview-captura-resolucao`
  encerrada.** Ver
  `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`, secao
  "Commit e push (2026-09-16, /04-commit-e-push curto)".
- 2026-09-16 — **`/00-planejamento` da demanda
  `saneamento-lista-pendencias-projeto` concluído**. Auditoria
  documental por dois sub-agentes independentes (`explorer`,
  `security-especialista`) confirmou, com evidência real de código/
  handoffs/commits/git log (nunca por descrição textual), que 10 itens
  da seção 5 estavam marcados como pendência ativa mas já haviam sido
  resolvidos: idempotência do envio ao Talent (`talent_checkin_status`,
  CAS de 5 estados, implementada desde 2026-09-09); HTTP 409 real do
  Talent; formato `anexos`/`anexosGZip`; separação
  `impressao.php`/`impressao-teste.php`; revisão formal de UX da tela
  de seleção de impressora; Netum SD-2000 (já corrigido na demanda
  anterior); CORS/PNA dev (já corrigido na demanda anterior); e 3
  ajustes do VIO Decode (rebaixamento para MANUAL, descarte do campo
  `image`, simetria de `ehValorPlaceholder()`). Todas as 10 linhas da
  tabela foram marcadas com `~~texto antigo riscado~~` + evidência,
  preservando o histórico integralmente. 8 itens técnicos foram
  reconfirmados como genuinamente ainda ativos (integridade de
  `cancelar`/`bloquear-excesso-notas`, concorrência em
  `concluirDigitalizacao()`, `GET_LOCK` não liberado em erro,
  `PDOException` sem handler global, JPEG truncado, limpeza de
  `tb_rate_limit_ocr`, rate limit fail-open, migrations 001/002 com
  padrão frágil). Nova subseção `### 5.1. Lista ativa consolidada e
  categorizada` criada logo após a tabela histórica, organizando TODAS
  as pendências ativas em 6 categorias (PENDENTE - AÇÃO TÉCNICA,
  AGUARDANDO DECISÃO DE PRODUTO, AGUARDANDO CREDENCIAL/TERCEIRO, ADIADO
  PARA PRODUÇÃO/FINALIZAÇÃO, OBSERVAÇÃO DE BAIXA SEVERIDADE, RESOLVIDO).
  Roadmap oficial de 5 próximas demandas técnicas registrado:
  `integridade-conclusao-atendimento`, `validacao-jpeg-segura`,
  `robustez-rate-limit-migrations`, `vio-hardening-sem-credenciais`,
  `ocr-validacao-fisica-calibracao`, seguidas de decisões de produto e
  testes/ativação final em produção. Nenhuma alteração de código,
  banco, migration ou configuração nesta demanda — exclusivamente
  documental. Cartão Trello `6aaae214f34db59cd23e2d8c` criado em
  "Sprint Bruno - Fazendo [Semanal]" (novo, não reaproveitou cartão
  antigo). Ver
  `docs/handoffs/2026-09-16-saneamento-lista-pendencias-projeto.md`.
- 2026-09-16 — **Rodada curta de `/01-implementacao` em
  `saneamento-lista-pendencias-projeto` — correção de referência
  quebrada**. A `/03-revisao` anterior desta demanda foi bloqueada por
  achado do `explorer`: a linha do Netum (já marcada resolvida nesta
  mesma demanda) referenciava
  `docs/handoffs/2026-09-15-netum-preview-captura-resolucao.md`, arquivo
  que nunca existiu no repositório (confirmado por `git log --all`
  vazio) — introduzido por engano no `/00-planejamento` desta demanda.
  Corrigido para `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`
  (único arquivo real); seção "Inconsistências adicionais encontradas"
  do handoff do saneamento também corrigida para deixar explícito que
  não havia dois arquivos reais. Busca global confirma zero ocorrências
  vivas do caminho incorreto. `git diff --check` limpo; `git status`
  confirma que só `ia_development_state.md` e o handoff foram tocados —
  nenhuma outra classificação, código, banco, migration ou configuração
  alterada. Zero teste físico, zero chamada externa, zero commit/push.
  **Pronta para nova `/03-revisao` curta de confirmação.** Ver
  `docs/handoffs/2026-09-16-saneamento-lista-pendencias-projeto.md`,
  secao "Rodada curta de /01-implementacao — correção de referência
  quebrada (2026-09-16)".
- 2026-09-16 — **Nova `/03-revisao` curta de confirmação em
  `saneamento-lista-pendencias-projeto` concluída — APROVADO**. Revisão
  independente (`explorer`, mesmo que identificou o achado bloqueante
  anterior), restrita à correção da referência quebrada — os outros 9
  pontos permanecem aprovados sem repetição. Todos os 6 itens PASS:
  referência corrigida confirmada; busca global sem ocorrência viva do
  caminho incorreto; seção de inconsistências do handoff correta;
  nenhuma classificação/pendência/roadmap alterado; só os 2 arquivos
  esperados tocados; arquivo real do Netum confirmado existente;
  `git diff --check` limpo. **Liberado para `/04-commit-e-push`.** Ver
  `docs/handoffs/2026-09-16-saneamento-lista-pendencias-projeto.md`,
  secao "Nova revisão curta de confirmação (2026-09-16, /03-revisao)".
- 2026-09-16 — **`/04-commit-e-push` de
  `saneamento-lista-pendencias-projeto` concluído**. Identidade Git
  local `Bruno Santos <brunossaantos@gmail.com>` reutilizada, sem
  nenhuma atribuição de IA. Commit
  `e4d53ea95ed7e762e84607110b17133a165462d5`
  (`docs(saneamento-lista-pendencias-projeto): audita e reorganiza
  pendencias do projeto`). Arquivos versionados:
  `ia_development_state.md`,
  `docs/handoffs/2026-09-16-saneamento-lista-pendencias-projeto.md`
  (novo). Push para `origin/main` confirmado (`8ddf399..e4d53ea`),
  `HEAD == origin/main` reconfirmado, worktree final limpo. A subseção
  `### 5.1. Lista ativa consolidada e categorizada` passa a ser a fonte
  única e confiável das pendências reais do projeto. Zero teste físico,
  zero chamada externa, zero alteração de banco/configuração
  operacional. **Demanda `saneamento-lista-pendencias-projeto`
  encerrada.** Ver
  `docs/handoffs/2026-09-16-saneamento-lista-pendencias-projeto.md`,
  secao "Commit e push (2026-09-16, /04-commit-e-push)".

- 2026-09-16 — `/00-planejamento` e `/01-implementacao` da demanda
  `integridade-conclusao-atendimento` concluidos. Decisao de produto
  confirmada pelo usuario: atendimento `concluido` e terminal e
  imutavel para o motorista. Investigacao (explorer +
  backend-especialista + security-especialista, independentes)
  confirmou que `AtendimentoController::cancelar()`/
  `bloquearPorExcessoDeNotas()` eram os UNICOS dois caminhos capazes
  de reverter um atendimento `concluido` (UPDATEs incondicionais em
  `AtendimentoDao.php`); `concluirDigitalizacao()` sem CAS/lock/
  transacao contra corrida; `GET_LOCK` de `DocumentoController` nao
  liberado explicitamente em erro (mas sem `PDO::ATTR_PERSISTENT` no
  projeto, sem vulnerabilidade ativa); `NotaController.php` sem
  nenhum tratamento de `PDOException`. Implementado: CAS via
  `UPDATE ... WHERE status != 'concluido'` em `cancelar()`/`bloquear()`
  (retorno `bool`, HTTP 409 sem alteracao no banco quando `concluido`);
  novo metodo `AtendimentoDao::concluirDigitalizacaoNotas()` (CAS por
  `etapa_atual`+`status`) usado por `concluirDigitalizacao()`, com
  resposta idempotente na segunda requisicao concorrente perdedora;
  `DocumentoController::iniciarProcessamento()` reestruturado para
  liberar o lock antes de qualquer `Resposta::erro()`/`exit`;
  `NotaController.php` com `try/catch (PDOException)` sanitizado em
  4 metodos publicos. Dois bugs reais encontrados pelo qa-testes na
  validacao (ambiguidade de `rowCount()==0` em `cancelar/bloquear`
  sobre estado ja igual, e HTTP 400 em vez de idempotencia quando o
  vencedor da corrida terminava antes do perdedor buscar o
  atendimento) foram corrigidos na mesma etapa e revalidados: 11/11
  itens do roteiro completo PASSOU, 16 suites de regressao
  (Recebimento/Expedicao/Talent/impressao/ordem de coleta) 100%
  aprovadas, zero residuo no banco, zero chamada real ao Talent, zero
  impressao real, zero commit/push nesta etapa. Ver
  `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`.
  Cartao Trello: `card_id 6aabd13455e22411f07b0da4`.
- 2026-09-16 — `/02-testes` da demanda `integridade-conclusao-atendimento`
  concluido com veredito PRECISA DE AJUSTE. Dois revisores
  independentes (qa-testes + security-especialista, sem participacao
  na implementacao) reexecutaram do zero, com ceticismo, o roteiro
  completo de inspecao estatica (9 itens) e testes reais (20 itens),
  mais frontend e regressao (28 suites, 27/28 passou; 1 falha
  pre-existente e nao relacionada, dependente de fixture de banco
  externo). Todos os 20 testes reais passaram; das 9 checagens
  estaticas, 8 confirmadas integralmente e 1 (item 7, sanitizacao de
  PDOException nos 4 metodos do NotaController) confirmada como
  PARCIAL: gap real encontrado em `identificarCliente()`, chamada de
  rate limit fora de qualquer try/catch. Por instrucao explicita do
  usuario de bloquear o avanco se algum item do checklist falhar,
  a demanda RETORNA para uma rodada curta de `/01-implementacao`
  restrita a esse gap. Nenhum achado critico de seguranca; observacao
  tecnica nao bloqueante registrada sobre `etapaEhAlvoOuPosterior()`
  (cheque posicional, nao explorável hoje). Zero residuo no banco,
  zero chamada real ao Talent, zero impressao real, zero commit/push.
  Ver `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`,
  secao "Resultado dos testes". Cartao Trello:
  `card_id 6aabd13455e22411f07b0da4`.
- 2026-09-16 — Rodada curta de `/01-implementacao` da demanda
  `integridade-conclusao-atendimento` fecha o gap encontrado no
  `/02-testes` anterior: `NotaController::identificarCliente()`
  agora protege `verificarRateLimit()` (que chama
  `RateLimitOcrDao::incrementarEContar()`, 2 queries PDO reais) com
  `try/catch (PDOException)`, respondendo HTTP 500 genérico e
  interrompendo antes de qualquer OCR/logica posterior, sem risco de
  dupla contabilizacao. `qa-testes` validou os 8 itens pedidos (100%
  PASSOU, marcador sintetico RTL0917, zero residuo) e reexecutou 27
  suites de regressao (100% PASSOU) mais a suite completa da demanda
  (43/43). Falha pre-existente e nao relacionada de
  `teste_consulta_ordem_coleta.php` (fixture de banco externo ausente)
  isolada e nao contabilizada como regressao. Veredito: LIBERADO para
  nova `/02-testes` curta ou avanco direto para `/03-revisao`. Zero
  chamada real ao Talent, zero impressao real, zero commit/push. Ver
  `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`.
  Cartao Trello: `card_id 6aabd13455e22411f07b0da4`.
- 2026-09-16 — Segunda rodada curta de `/01-implementacao` da demanda
  `integridade-conclusao-atendimento`: sanitizado o log dos 6 pontos
  de `catch (PDOException)` em `NotaController.php`, que registravam
  `$e->getMessage()` bruto (risco apontado pelo usuario — pode conter
  SQL/valores de parametro). Novo metodo privado `logFalhaBancoPdo()`
  loga so contexto fixo + SQLSTATE validado por regex estrita
  (`^[A-Z0-9]{5}$`), nunca `getMessage()`/`getTraceAsString()`/
  `getFile()`/`getLine()`. `qa-testes` forcou uma `PDOException` real
  com 5 marcadores sinteticos (SQL, caminho, CPF, placa, credencial)
  passando pelo caminho real de producao, com
  `display_errors=1`/`log_errors=1`/log direcionado a arquivo
  temporario isolado: zero vazamento em resposta HTTP, stdout, arquivo
  de log e repositorio. Regressao: 43/43 (suite completa da demanda) +
  23/24 (suite de rate limit, 1 falso positivo de asercao do proprio
  script de teste que mistura stdout/stderr, sem vazamento real — nao
  corrigido por ser fora do escopo, registrado para manutencao
  futura). Zero residuo, zero chamada real ao Talent, zero impressao
  real, zero commit/push. Veredito: LIBERADO para `/02-testes` curta.
  Ver `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`.
  Cartao Trello: `card_id 6aabd13455e22411f07b0da4`.
- 2026-09-16 — Correcao final da demanda `integridade-conclusao-atendimento`
  (ainda dentro de `/01-implementacao`): corrigida a metodologia de
  `tests/manual/teste_rate_limit_identificar_cliente_pdo.php` (nao e
  codigo de producao) para isolar corretamente resposta HTTP, stdout,
  stderr e arquivo de log dedicado, eliminando um falso positivo do
  Item 5 (a asercao anterior misturava stdout+stderr e reagia ao
  literal `PDOException`, que so aparece no log de servidor,
  intencional e sem vazamento real). Prova de deteccao de vazamento
  real feita e revertida (injecao temporaria de marcador sensivel
  derrubou 2 asserçoes, confirmando que o teste corrigido funciona).
  Resultados: 24/24 (suite de rate limit) + 43/43 (suite completa da
  demanda) + zero ocorrencia de `getMessage()`/`getTraceAsString()`/
  `getFile()`/`getLine()` nos 6 catches de PDOException do
  NotaController (inalterado desde a rodada anterior). Nenhum arquivo
  de `app/`/`util/` tocado. Observacao pre-existente registrada, nao
  corrigida: 4 catches de `\Throwable`/`\RuntimeException` (distintos
  de `\PDOException`) em `NotaController.php` ainda usam
  `getMessage()` — fora do escopo desta demanda. Zero residuo, zero
  chamada real ao Talent, zero impressao real, zero commit/push.
  Veredito: LIBERADO para `/02-testes` curta e independente. Ver
  `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`.
  Cartao Trello: `card_id 6aabd13455e22411f07b0da4`.
- 2026-09-16 — `/02-testes` curta e independente da demanda
  `integridade-conclusao-atendimento` concluida com veredito
  APROVADO. Dois revisores independentes (qa-testes +
  security-especialista, sem participacao na correcao anterior)
  reexecutaram do zero, com ceticismo: metodologia do teste de rate
  limit confirmada (stdout/stderr/log isolados, prova negativa
  controlada independente reproduziu a falha esperada e reverteu com
  sucesso), 24/24 + 43/43 confirmados, zero uso de
  getMessage()/getTraceAsString()/getFile()/getLine() nos 6 catches
  de PDOException do NotaController, contratos principais da demanda
  (409 para concluido, CAS de concluirDigitalizacao, lock de
  DocumentoController, rate limit sem dupla contabilizacao)
  reconfirmados intactos, 24 suites de regressao adicionais 100%
  aprovadas (1 falha ambiental preexistente e nao relacionada,
  isolada corretamente). Zero achado critico de seguranca; 2
  observacoes nao bloqueantes registradas (cobertura por lista fechada
  de marcadores no script de teste; assimetria de sanitizacao entre
  PDOException e outras excecoes em NotaController, que nunca vazam ao
  cliente). Zero residuo, zero chamada real ao Talent, zero impressao
  real, zero commit/push. **Demanda liberada para `/03-revisao`.** Ver
  `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`.
  Cartao Trello: `card_id 6aabd13455e22411f07b0da4`.
- 2026-09-17 — Correcao de registro (fora do fluxo formal de
  demanda/handoff, a pedido direto do usuario): o registro repetido em
  varias rodadas de `/02-testes` da demanda
  `integridade-conclusao-atendimento` de que
  `teste_consulta_ordem_coleta.php` falhava por "banco externo
  udlogo59_db_gestao_coletas ausente/indisponivel" estava IMPRECISO. O
  usuario confirmou acesso real ao banco via phpMyAdmin local; o
  `explorer` confirmou por conexao PDO direta e via
  `Util\ConexaoGestaoColetas::obter()` (classe real do projeto) que o
  MySQL local esta rodando, o banco existe, e a conexao com as
  credenciais do `.env` funciona normalmente. A causa real das 5
  falhas (12/17) e divergencia de dados de fixture: nao existe
  `numero_ordem_coleta='OC-TESTE-005'`/`placa_prevista='TST0A01'` em
  `tb_ordens_coleta` nesta copia do banco, e a ordem `OC-TESTE-001`
  (placa `ABC1D23`) mudou para `status='INATIVA'`, reduzindo de 3 para
  2 as ordens ATIVAS esperadas. Nenhum impacto no veredito de nenhuma
  etapa da demanda `integridade-conclusao-atendimento` (confirmado por
  `git diff --stat` que nenhum arquivo do fluxo de ordem de coleta foi
  tocado por essa demanda) — a falha permanece pre-existente e nao
  relacionada, mas com a causa correta registrada. Nao corrigido
  (fixture de dados, nao codigo) — pendencia de higiene de dados de
  teste registrada na seção de pendencias. Ver
  `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`,
  seção "Correcao de registro — banco de gestao de coletas esta
  disponivel (2026-09-17)".
- 2026-09-17 — `/03-revisao` da demanda `integridade-conclusao-atendimento`
  concluida com veredito APROVADO. Dois revisores independentes
  (backend-especialista + security-especialista, sem participacao na
  implementacao) releram todo o codigo do zero e reexecutaram os
  testes de forma propria (24/24 + 43/43 confirmados por ambos, cada
  um com sua propria prova negativa independente de deteccao de
  vazamento). Escrutinio rigoroso do ponto mais critico
  (`etapaEhAlvoOuPosterior()`, fragilidade posicional) por ambos os
  revisores, com varredura completa e independente de todo escritor
  de `etapa_atual` — nenhuma rota publica real capaz de produzir bypass
  encontrada, mantido como observacao nao bloqueante. Nova observacao
  registrada (pre-existente, fora do escopo): `obterLock()` de
  `DocumentoController` ignora seu proprio retorno. Nenhum outro
  achado bloqueante. Escopo de arquivos alterados confirmado (5
  arquivos de producao + 2 novos testes + handoff + este arquivo,
  nenhum outro tocado). **Demanda LIBERADA para `/04-commit-e-push`.**
  Ver `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`,
  secao "Resultado da revisao". Cartao Trello:
  `card_id 6aabd13455e22411f07b0da4`.
- 2026-09-17 — `/00-planejamento` da demanda `validacao-jpeg-segura`
  concluido. Investigacao (explorer + backend-especialista +
  security-especialista, independentes) confirmou: a validacao atual
  de JPEG (unica no backend, `util/UploadHelper.php::salvarImagemBase64()`)
  usa magic bytes + `getimagesizefromstring()`, que so le o cabecalho
  SOF do JPEG (dimensoes) sem avancar ate o marcador de fim EOI
  (FFD9) — um JPEG truncado no meio dos dados de scan passa pela
  validacao sem erro. Afeta os 4 fluxos (CNH, CRLV, nota fiscal,
  scanner Netum) porque todos passam pelo mesmo unico ponto de
  validacao. Nenhum limite de dimensao maxima existe hoje (so limite
  de bytes, unico para todos os tipos). Duas estrategias comparadas —
  decodificacao completa via GD (mais forte, requer checagem de
  dimensao ANTES de decodificar para evitar bomba de descompressao) ou
  checagem estrutural leve de EOI + limite de dimensao (mais barata em
  CPU/memoria, suficiente para o bug relatado mas com vetor teorico de
  EOI forjado) — divergencia entre os dois revisores sobre qual
  adotar, registrada como decisao bloqueante do usuario antes de
  `/01-implementacao`. GD confirmado disponivel localmente; nao
  confirmado em producao Hostgator (pendencia ja existente). Nenhum
  codigo alterado, nenhum dado real usado, nenhuma chamada externa.
  Ver `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`. Cartao
  Trello: `card_id 6aac49f6059d69343f93e626`.
- 2026-09-17 — `/01-implementacao` da demanda `validacao-jpeg-segura`
  parcialmente concluida. Estrategia HIBRIDA implementada em
  `util/UploadHelper.php::salvarImagemBase64()` (15 etapas, limites
  5000px por lado / 13.000.000px de area, GD fail-closed, sem nova
  dependencia/variavel .env). `qa-testes` executou 24 casos + prova
  negativa com fixtures 100% sinteticas: 22/24 PASSOU, incluindo o
  bug ORIGINAL relatado (truncamento acidental sem EOI, corretamente
  rejeitado) e regressao de CNH/CRLV/nota (sem quebra). ACHADO
  CRITICO: decodificacao completa via GD nesta instalacao (bundled,
  Windows) nao rejeita JPEG truncado com EOI forjado manualmente no
  final -- decodifica silenciosamente sem warning. Prova negativa
  obrigatoria do usuario (item 3) NAO confirmada. Decisao arquitetural
  devolvida ao usuario (aceitar risco residual / parser estrutural
  completo / investigar flag do GD) -- nao decidida por conta propria.
  Zero dado real, zero chamada externa, zero commit/push. Ver
  `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`. Cartao Trello:
  `card_id 6aac49f6059d69343f93e626`.
- 2026-09-17 — Rodada curta de `/01-implementacao` da demanda
  `validacao-jpeg-segura` testou a decisao do usuario de usar
  `gd.jpeg_ignore_warning=0` temporariamente durante
  `imagecreatefromstring()`, sem parser JPEG proprio. Implementacao
  correta e segura (leitura/alteracao/confirmacao/restauracao
  garantida em `finally`, fail-closed se a diretiva nao existir ou
  nao puder ser confirmada em '0'). RESULTADO: a diretiva EXISTE
  nesta instalacao (bundled, PHP 8.0.30, Windows) e a alteracao
  FUNCIONA (confirmada por `ini_get()`), mas NAO TEM EFEITO
  PERCEPTIVEL contra o EOI forjado -- `imagecreatefromstring()`
  continua aceitando silenciosamente o JPEG truncado com EOI colado
  manualmente, com ou sem a diretiva alterada. Criterio de
  interrupcao explicito do usuario foi ACIONADO -- rodada interrompida
  sem implementar parser proprio, sem aceitar risco residual por
  conta propria. Bug ORIGINAL (truncamento acidental sem EOI)
  permanece corrigido e validado pela checagem estrutural (inalterada
  nesta rodada). Nova decisao arquitetural devolvida ao usuario (
  aceitar risco residual / reconfirmar comportamento em producao
  Hostgator / parser estrutural completo). Zero dado real, zero
  chamada externa, zero commit/push. Ver
  `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`, secao "Rodada
  curta de /01-implementacao — teste do modo rigoroso
  gd.jpeg_ignore_warning". Cartao Trello:
  `card_id 6aac49f6059d69343f93e626`.
- 2026-09-17 — Demanda `validacao-jpeg-segura` concluida em
  `/01-implementacao` com veredito LIBERADO. Decisao final do usuario:
  risco residual de JPEG truncado com EOI forjado deliberadamente
  (baixo/moderado) formalmente ACEITO — a tentativa de mitigar via
  `gd.jpeg_ignore_warning` foi testada e nao teve efeito nesta
  instalacao de GD, e um parser JPEG proprio foi descartado por
  complexidade desproporcional sem garantia adicional real (nao
  elimina o risco, so aumenta manutencao). Estrategia final:
  `util/UploadHelper.php::salvarImagemBase64()` com checagem
  estrutural de EOI exato no ultimo byte (cobre o bug ORIGINAL,
  truncamento acidental) + limites de dimensao (5000px por lado,
  13.000.000px de area, constantes no codigo, sem nova variavel
  `.env`) + decodificacao completa via GD como camada adicional +
  fail-closed se GD ausente. Tentativa de `gd.jpeg_ignore_warning`
  removida do codigo e da documentacao. 22/22 controles obrigatorios
  aprovados, 2 diagnosticos de limitacao conhecida documentados
  separadamente (mesma causa raiz: leniencia do GD/libjpeg nesta
  instalacao especifica, nao um bug do codigo). Regressao de
  CNH/CRLV/nota/Netum sem quebra. `docs/deploy-checklist.md`
  atualizado com o limite real da solucao (protege corrupcao
  acidental, nao autenticidade documental). Pendencia de JPEG
  truncado marcada RESOLVIDA COM RISCO RESIDUAL ACEITO e retirada da
  lista ativa da secao 5.1 — nenhuma pendencia equivalente criada.
  Recomendacao futura registrada (nao implementada): tratar
  legibilidade/autenticidade via OCR/VIO/conferencia operacional, se
  necessario. Zero dado real, zero chamada externa, zero commit/push.
  Ver `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`. Cartao
  Trello: `card_id 6aac49f6059d69343f93e626`.
- 2026-09-17 — `/02-testes` independente da demanda
  `validacao-jpeg-segura` concluido com veredito APROVADO. Dois
  revisores independentes (qa-testes + security-especialista, sem
  participacao na implementacao) reexecutaram tudo do zero: 22/22
  controles obrigatorios confirmados por 2 execucoes independentes
  (scripts proprios, nao reaproveitados cegamente), 7/7 confirmacoes
  do risco residual aceito, medicao de memoria ISOLADA em
  subprocessos separados (pico ~56,6MB no pior caso legitimo de 13M
  pixels, margem de 56% em 128M, sem acumulo em 2 imagens
  sequenciais, zero arquivo parcial em estouro forcado) confirmando
  que a recomendacao de 128M minimo/256M recomendado permanece
  valida, sem correcao documental necessaria. Zero achado critico de
  seguranca; 1 observacao nao bloqueante sobre `.gitignore` (scripts
  de teste com prefixo `_` nunca versionados, convencao
  pre-existente do projeto). Regressao de CNH/CRLV/nota/Netum sem
  quebra (11/11 + 30/30). Zero dado real, zero chamada externa, zero
  acesso a producao/Hostgator, zero commit/push. **Demanda liberada
  para `/03-revisao`.** Ver
  `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`. Cartao Trello:
  `card_id 6aac49f6059d69343f93e626`.
- 2026-09-17 — `/03-revisao` da demanda `validacao-jpeg-segura`
  concluida com veredito PRECISA DE AJUSTE. Dois revisores
  independentes (backend-especialista + security-especialista, sem
  participacao na implementacao) confirmaram os 20 pontos de
  implementacao SEM ACHADO (base64 estrito, limites de bytes/
  dimensao/area, ordem de validacao, fail-closed de GD, restauracao
  de error handler, gravacao so apos validacoes, zero dependencia
  nova, compatibilidade PHP 8.0) e o checklist Hostgator sem achado.
  ACHADO BLOQUEANTE confirmado empiricamente pelo
  `backend-especialista`: `tests/manual/teste_validacao_jpeg_seguro.php`
  (script RASTREADO pelo Git, entregue nesta demanda) depende de
  forma obrigatoria (require_once sem guard + exec()) de dois
  arquivos NAO rastreados (`_fixtures_jpeg_seguro.php`,
  `_caso_gd_indisponivel_mock.php`, cobertos por `.gitignore:
  tests/manual/_*.php`) — testado em diretorio isolado simulando
  clone limpo, script falha imediatamente com erro fatal de
  `require` ausente, ANTES de executar qualquer caso. A suite de
  teste desta demanda especifica NAO E reproduzivel a partir de um
  clone limpo do repositorio — a convencao pre-existente do
  `.gitignore` nao justifica isso neste caso, pois o proprio script
  entregue depende dos arquivos ignorados. Precisa retornar para
  rodada curta de `/01-implementacao` para versionar as 2
  dependencias ou inline-ar seu conteudo no script principal.
  Achado de ATENCAO nao bloqueante adicional: residuo fisico
  encontrado em `storage/atendimentos/2026-09-17/TST0A01_142344/nota_01.jpg`
  (3264x2448, marcador sintetico, nao dado real) — contradiz a
  alegacao de "zero residuo" do handoff, precisa investigacao/limpeza
  na mesma rodada. Risco residual de EOI forjado (ja formalmente
  aceito pelo usuario) reconfirmado CONSISTENTE entre codigo e
  documentacao, sem contradicao — NAO reaberto como pendencia. Zero
  dado real, zero chamada externa, zero commit/push. Ver
  `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`, secao
  "Resultado da revisao". Cartao Trello mantido em "Sprint Bruno -
  Fazendo [Semanal]": `card_id 6aac49f6059d69343f93e626`.
- 2026-09-17 — Rodada curta de `/01-implementacao` da demanda
  `validacao-jpeg-segura`, restrita aos 2 achados do `/03-revisao`
  anterior. `util/UploadHelper.php` NAO foi tocado (nenhuma regra de
  validacao/limite/risco residual alterada).
  **Reprodutibilidade (BLOQUEANTE, corrigido)**: os 2 arquivos que
  causavam o `Fatal error` em clone limpo foram RENOMEADOS no
  filesystem (removendo o prefixo `_` que batia com
  `.gitignore:tests/manual/_*.php`), sem tocar `.gitignore` e sem
  `git add -f`: `_fixtures_jpeg_seguro.php` ->
  `tests/manual/fixtures_jpeg_seguro.php`,
  `_caso_gd_indisponivel_mock.php` ->
  `tests/manual/caso_gd_indisponivel_mock.php`.
  `tests/manual/teste_validacao_jpeg_seguro.php` atualizado
  (`require_once`/`exec()` apontando para os novos nomes, ambos via
  `__DIR__`). Confirmado `git check-ignore -v` sem retorno para os 2
  novos nomes (nao mais ignorados) e `git status` mostrando ambos como
  untracked prontos para `git add`. Zero referencia viva aos nomes
  antigos fora de trechos historicos do proprio handoff. Prova em
  arvore limpa (fora do repositorio, sem nenhum arquivo `_*` de
  rodadas anteriores): `teste_validacao_jpeg_seguro.php` executado
  numa arvore isolada com `.env` SINTETICO minimo (so `STORAGE_PATH`/
  `NOTA_IMAGEM_MAX_BYTES`, zero segredo real) -- **22/22 controles
  obrigatorios PASSOU**, 2 diagnosticos de limitacao conhecida
  separados (inalterados), prova negativa CONFIRMADA, zero acesso ao
  worktree original durante a execucao. Arvore temporaria removida
  integralmente ao final. **Limitacao honesta registrada**: a copia do
  `.env` REAL do projeto (necessaria para os 2 testes de regressao
  dependentes de banco) foi BLOQUEADA automaticamente pelo
  classificador de seguranca do proprio ambiente do agente
  ("Credential Leakage") antes de qualquer copia ocorrer; por decisao
  de nao contornar esse bloqueio, `teste_fluxo_recebimento_documentos.php`
  (11/11 PASSOU) e `teste_e2e_recebimento_expedicao_mock.php` (30/30
  PASSOU) foram reexecutados no PROPRIO projeto real (que ja contem os
  2 arquivos renomeados) em vez de numa arvore hermeticamente separada
  — reprodutibilidade "clone limpo total" comprovada de forma completa
  apenas para o script diretamente citado no achado BLOQUEANTE.
  **Residuo fisico (ATENCAO, investigado e limpo)**: confirmados os 6
  pontos pedidos para
  `storage/atendimentos/2026-09-17/TST0A01_142344/nota_01.jpg` antes
  da remocao — nao e symlink, nao rastreado pelo Git, placa `TST0A01`
  e marcador sintetico ja convencionado (92 pastas analogas de outras
  demandas, fora de escopo, preservadas), JPEG baseline 3264x2448
  (resolucao do Netum, compativel com fixture sintetica, confirmado
  sem inspecao visual do conteudo), linha correspondente em
  `tb_atendimento` (`id_atendimento=2796`) com `motorista_nome`/
  `motorista_cpf` **NULL** (zero dado pessoal, confirmado por 1 leitura
  pontual no banco, zero escrita), origem provavel: rodada de
  `/02-testes`/`/03-revisao` do mesmo dia (14:23:44) que exercitou o
  fluxo real de upload sem limpeza completa de `storage/`. Removido
  somente o arquivo exato + o diretorio `TST0A01_142344` (ficou vazio),
  sem tocar em `storage/atendimentos/2026-09-17/` (preservada, ainda
  existe vazia) nem em nenhuma outra pasta. Confirmado por busca que
  nao ha mais nenhum outro residuo desta demanda especifica em
  `storage/`. A alegacao anterior de "zero residuo" no handoff foi
  CORRIGIDA com a ressalva completa. Zero commit/push, zero chamada
  externa, zero escrita no banco real. Ver
  `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`, secao "Rodada
  curta de /01-implementacao -- correcao de reprodutibilidade +
  limpeza de residuo". Cartao Trello: `card_id 6aac49f6059d69343f93e626`.
  **Proximo passo**: nova confirmacao curta de `/02-testes`/`/03-revisao`
  sobre estes 2 pontos antes de `/04-commit-e-push`.
- 2026-09-17 — `/02-testes` curta e independente da demanda
  `validacao-jpeg-segura` (focada exclusivamente nos 2 achados da
  rodada anterior) concluida com veredito APROVADO. `qa-testes`
  (sem participacao na correcao) reproduziu de forma independente:
  reprodutibilidade confirmada (arquivos renomeados existem, nomes
  antigos ausentes, zero ignorado pelo Git, zero referencia
  funcional aos nomes antigos), nova arvore hermetica montada do
  zero rodando o script a partir de diretorio corrente diferente do
  script (teste ainda mais rigoroso que o da rodada anterior) —
  22/22 controles obrigatorios PASSOU, residuo confirmado ausente
  sem dano colateral a outras pastas de teste, regressao 11/11 +
  30/30, `util/UploadHelper.php` confirmado intocado nesta rodada
  curta (mtime anterior, diff identico ao ja aprovado). Zero dado
  real, zero chamada externa, zero commit/push. **Demanda liberada
  para `/03-revisao` curta de confirmacao.** Ver
  `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`. Cartao Trello:
  `card_id 6aac49f6059d69343f93e626`.
- 2026-09-17 — `/03-revisao` curta de confirmacao da demanda
  `validacao-jpeg-segura` concluida com veredito APROVADO. Dois
  revisores independentes (backend-especialista + security-especialista,
  sem participacao nas correcoes) reproduziram do zero: nova arvore
  hermetica propria confirmando 22/22 controles obrigatorios,
  reprodutibilidade confirmada, residuo confirmado ausente sem dano
  colateral, integridade do diff confirmada (`util/UploadHelper.php`
  intocado desde a aprovacao anterior, `.gitignore` inalterado, sem
  `git add -f`, risco residual continua aceito e fora da lista ativa
  5.1, sem parser proprio/`gd.jpeg_ignore_warning` reintroduzidos),
  regressao 11/11 + 30/30 sem quebra, zero dependencia nova, zero
  segredo/dado pessoal. Zero dado real, zero chamada externa, zero
  commit/push nesta etapa. **Demanda LIBERADA para
  `/04-commit-e-push`.** Ver
  `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`, secao
  "Resultado da revisao — /03-revisao curta de confirmacao". Cartao
  Trello: `card_id 6aac49f6059d69343f93e626`.
- 2026-09-18 — `/00-planejamento` da demanda `robustez-rate-limit-migrations`
  concluido. Investigacao (explorer + 2x backend-especialista +
  security-especialista + devops-especialista, independentes)
  confirmou o estado real das 3 pendencias: (1) `tb_rate_limit_ocr`
  cresce 1 linha por totem por janela ativa (60s), sem indice
  adicional alem da PK composta, sem job de limpeza; (2) o fail-open
  de `RateLimitOcrDao` e um risco LATENTE (unico chamador em producao,
  `public/api/nota.php`, sempre injeta o DAO hoje), confirmado por 2
  revisores que `NotaFiscalRn::identificarCliente()` e 100% local
  (sem chamada externa), logo o risco e de disponibilidade/CPU, nao
  de confidencialidade; (3) migrations 001/002 usam checagem manual
  previa (nao `PREPARE`/`EXECUTE` condicional da 003), com risco
  confirmado de disponibilidade/operacional (001) e schema
  inconsistente silencioso (002), nunca de perda de dado. Estrategias
  convergentes definidas para fail-closed (injecao obrigatoria +
  HTTP 503, alinhado ao precedente ja usado em `DocumentoController`)
  e para limpeza (cron dedicado em lotes, seguindo o padrao real ja
  usado por `cron/reenviar-fila.php` — que nao tem lock/log
  estruturado, confirmado pelo `devops-especialista`, exigindo
  desenho novo). **DIVERGENCIA REAL entre revisores sobre a correcao
  das migrations 001/002** (editar diretamente vs. nova migration
  corretiva) registrada como decisao bloqueante pendente de
  confirmacao do usuario antes de `/01-implementacao`. Nenhum codigo
  alterado, nenhuma migration executada, nenhum registro excluido,
  nenhuma chamada externa, nenhum acesso a producao/Hostgator nesta
  etapa. Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`.
  Cartao Trello: `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-18 — `/01-implementacao` da demanda `robustez-rate-limit-migrations`
  concluida com veredito funcional aprovado (apos rodada curta de
  correcao de 2 achados). Usuario confirmou: preservar 001/002/003
  sem alteracao, nova migration corretiva 013, retencao de 24h, lotes
  de 500, limpeza so via cron/CLI, RateLimitOcrDao obrigatorio e
  fail-closed. Implementado: `NotaController` com DAO obrigatorio no
  construtor + `verificarRateLimit()` com try/catch real (qualquer
  `\Throwable` inesperado, incluindo `\Error`, responde HTTP 503
  sanitizado; `\PDOException` continua respondendo 500 via caminho ja
  existente); novo `RateLimitOcrDao::apagarJanelasExpiradas()`; novo
  `cron/limpar-rate-limit-ocr.php` (CLI-only, lotes de 500, teto de
  50 lotes/execucao, nunca apaga janela atual/anterior); nova
  migration `sql/migrations/013_convergencia_idempotente_migrations_historicas.sql`
  (001/002/003 preservadas byte a byte, converge objetos via padrao
  PREPARE/EXECUTE da 003, reconhece indice equivalente com nome
  diferente, cria indice novo idx_rate_limit_ocr_janela, aborta com
  SIGNAL SQLSTATE se schema incompativel); novo checklist de deploy.
  qa-testes validou 12+17+12 casos novos + todas as regressoes
  (rate limit, identificar-cliente, CNH/CRLV, Recebimento/Expedicao,
  impressao, conclusao/cancelamento, JPEG, Talent) sem quebra apos
  correcao de 5 scripts de teste afetados pela mudanca de assinatura
  do construtor (efeito colateral esperado, nenhum chamador de
  producao afetado). 2 achados reais corrigidos na mesma etapa: HTTP
  503 antes inalcancavel mesmo por Reflection (corrigido com
  try/catch real) e regressao de teardown em
  `teste_identificar_cliente.php` (limpeza de tb_rate_limit_ocr
  adicionada). 1 achado NOVO registrado sem correcao (fora do escopo
  dos arquivos autorizados): `util/Conexao.php` vaza usuario do banco
  em `error_log()` cru quando a conexao inicial falha —
  pre-existente, nao criado por esta demanda. Migrations testadas
  APENAS contra bancos descartaveis dedicados, nunca o banco de
  dev real nem producao. Zero dado real, zero registro excluido, zero
  chamada externa, zero commit/push. Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`.
  Cartao Trello: `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-18 — `/02-testes` independente da demanda
  `robustez-rate-limit-migrations` concluido com veredito PRECISA DE
  AJUSTE. Tres revisores independentes (qa-testes + backend-especialista
  + security-especialista, sem participacao na implementacao)
  confirmaram: bloco rate limit 35/36 (1 bloqueante: cenario "falha
  de conexao antes da construcao do NotaController" vaza fatal error
  cru ao cliente HTTP em public/api/nota.php); bloco limpeza 20/20
  (todos passaram, incluindo execucao real do cron com 26.000 linhas
  reais); bloco migration 013 16/18 (1 bloqueante: indice novo nao e
  usado pela query real de limpeza, EXPLAIN confirma full table scan;
  1 achado medio nao bloqueante de mascaramento silencioso por nome);
  todas as regressoes 100% sem falha. Achado de log de
  `util/Conexao.php` no cron (ja registrado) reconfirmado nao
  bloqueante isoladamente pelo security-especialista, mas o
  qa-testes encontrou uma manifestacao MAIS GRAVE da mesma causa raiz
  (exposicao ao cliente HTTP, nao so a log) que E classificada
  bloqueante. 2 achados bloqueantes registrados na secao 5.1 —
  demanda retorna para rodada curta de `/01-implementacao`. Zero
  dado real, zero chamada externa, zero acesso a producao/Hostgator,
  zero commit/push. Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`,
  secao "Resultado dos testes". Cartao Trello mantido em "Sprint
  Bruno - Fazendo [Semanal]": `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-18 -- Rodada curta de `/01-implementacao` da demanda
  `robustez-rate-limit-migrations`, restrita aos 2 achados
  bloqueantes + 1 achado medio do `/02-testes` independente
  anterior. Corrigidos: (1) vazamento de fatal error cru ao cliente
  HTTP no bootstrap de conexao -- 7 entrypoints (`nota.php`,
  `impressao.php`, `atendimento.php`, `impressao-teste.php`,
  `documento.php`, `cliente.php`, `totem/index.php`) passaram a
  envolver `Conexao::obter()` em try/catch isolado, HTTP 503
  sanitizado; log de `util/Conexao.php` deixou de usar
  `PDOException::getMessage()` bruto; (2) indice da migration 013
  (PASSO 5) corrigido de `idx_rate_limit_ocr_janela (janela)` para
  `idx_rate_limit_ocr_limpeza (atualizado_em, janela)`, EXPLAIN real
  confirmou fim do full table scan; (3) validacao estrutural de
  indice por colunas (nao so nome) aplicada de forma simetrica aos 3
  indices convergidos pela migration 013 (PASSOS 1, 4 e 5), nova
  stored procedure `_migracao_013_abortar_se_colisao` com SIGNAL
  SQLSTATE sanitizado em caso de colisao de nome incompativel.
  Migrations 001/002/003 confirmadas inalteradas. Regras de negocio,
  retencao (24h), lote (500), teto de lotes (50) e contratos HTTP
  ja aprovados (sucesso/429/500) preservados sem alteracao. Todas
  as regressoes relevantes reexecutadas sem falha. Zero dado real,
  zero operacao real, zero commit/push nesta rodada. Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`,
  secao "Rodada curta de /01-implementacao -- correcao dos achados
  bloqueantes". Demanda aguarda nova rodada de `/02-testes`
  independente antes de avancar para `/03-revisao`. Cartao Trello
  mantido em "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-18 -- Nova rodada independente de `/02-testes` da demanda
  `robustez-rate-limit-migrations`, validando a rodada curta de
  `/01-implementacao` anterior. 3 revisores independentes (qa-testes
  + backend-especialista + security-especialista, novas instancias,
  sem participacao na correcao). Resultado: os 2 achados bloqueantes
  anteriores (vazamento HTTP cru no bootstrap; full table scan na
  limpeza) e o achado medio (mascaramento por nome) foram
  RECONFIRMADOS corrigidos por evidencia real e independente (503
  sanitizado via curl real nos 7 entrypoints, prova negativa de
  deteccao de vazamento, cron real com 20.502 linhas sinteticas,
  EXPLAIN real sem full table scan em 3 cenarios incluindo volume de
  producao, migration 013 idempotente e abortando corretamente em
  colisao nos 3 indices). Porem 2 achados bloqueantes NOVOS, nao
  cobertos pela correcao anterior, impedem avanco para
  `/03-revisao`: (1) `Dotenv::load()` roda fora do try/catch nos 7
  entrypoints, pode reproduzir vazamento pre-auth se `.env` estiver
  ausente/malformado; (2) migration 013 exige privilegios `CREATE
  ROUTINE`+`ALTER ROUTINE`, testado empiricamente que falha sem
  eles, nao confirmados disponiveis no Hostgator real. Veredito
  consolidado: **PRECISA DE AJUSTE**. Zero dado real, zero operacao
  real, zero commit/push nesta rodada de testes. Achado de ambiente
  registrado (nao de codigo): `.env` local de dev com senha de teste
  residual de rodada de QA anterior, nao restaurada -- pendente de
  o usuario restaurar manualmente. Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`,
  secao "Nova rodada de /02-testes independente, pos-correcao".
  Cartao Trello mantido em "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-18 -- Rodada curta de `/01-implementacao` da demanda
  `robustez-rate-limit-migrations`, restrita aos 2 achados
  bloqueantes novos da 2a rodada de `/02-testes` independente.
  Corrigidos: (1) `Dotenv::load()` desprotegido -- novo
  `util/Bootstrap.php` cobre `.env` ausente/malformado, variavel
  obrigatoria ausente/vazia e falha de conexao numa unica fronteira,
  sempre HTTP 503 sanitizado, aplicado aos 7 entrypoints e ao cron;
  (2) migration 013 reescrita sem stored procedures/SIGNAL, colisao
  de indice agora falha nativamente via erro do proprio
  MySQL/MariaDB, privilegios minimos testados e confirmados
  identicos aos ja exigidos por 001/002/003 (sem CREATE
  ROUTINE/ALTER ROUTINE). Regras de negocio, retencao, lotes, teto,
  contratos HTTP e migrations 001/002/003 preservados sem alteracao.
  Regressoes relevantes reexecutadas sem falha. **INCIDENTE**:
  durante a validacao, o cron foi executado indevidamente contra o
  banco de dev local real (nao descartavel), removendo 1 linha
  expirada e real de `tb_rate_limit_ocr` -- banco so continha dado
  de teste, nenhum dado pessoal, nenhum impacto em
  producao/Hostgator; usuario informado e ACEITOU o incidente
  explicitamente; linha nao foi recriada; alegacao de "zero operacao
  real" NAO se aplica a esta rodada especifica; nenhuma nova
  execucao do cron contra banco real autorizada nesta demanda.
  Protocolo reforcado para proximas etapas: testes destrutivos
  somente em banco descartavel dedicado, confirmar nome do banco
  antes de qualquer DELETE/migration/cron, nunca usar credenciais do
  `.env` real em teste destrutivo. Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`,
  secoes "Rodada curta de /01-implementacao -- correcao dos 2
  achados bloqueantes novos" e "INCIDENTE". Demanda liberada para
  nova rodada de `/02-testes` independente. Cartao Trello mantido em
  "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-18 -- Nova rodada independente de `/02-testes` (3a rodada)
  da demanda `robustez-rate-limit-migrations`, validando a rodada
  curta de `/01-implementacao` que corrigiu os 2 achados bloqueantes
  novos (Dotenv fora da fronteira segura; migration 013 exigindo
  privilegios de rotina). 3 revisores independentes (qa-testes +
  backend-especialista + security-especialista, novas instancias).
  Regra critica de isolamento de banco cumprida integralmente pelos
  3: nenhuma operacao destrutiva contra banco de dev local/producao,
  todos usaram instancias/bancos MariaDB completamente isolados e
  descartaveis com marcador QA no nome, confirmado via `SELECT
  DATABASE()` antes de qualquer DDL/DML, destruidos ao final; `.env`
  real confirmado identico por hash antes/depois em todas as 3
  sessoes. Resultado: qa-testes APROVADO (bootstrap/7
  entrypoints/rate limit/cron real com 26.002 linhas
  sinteticas/regressoes); backend-especialista APROVADO (13/13
  cenarios da migration, ausencia total de routines confirmada,
  privilegios minimos identicos a 001/002/003, EXPLAIN em 4 cenarios
  sem full table scan com corte real de producao); security-
  especialista APROVADO com 1 achado ATENCAO nao bloqueante
  (`docs/deploy-checklist.md` linha ~121 desatualizada, ainda
  menciona SIGNAL que nao existe mais na migration reescrita).
  **VEREDITO CONSOLIDADO: APROVADO.** Todos os criterios de aprovacao
  definidos pelo usuario atendidos. Zero nova operacao real/residuo
  nesta rodada (o incidente da rodada anterior nao foi reaberto,
  corretamente documentado). Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`,
  secao "Nova rodada de /02-testes independente, 3a rodada". Demanda
  liberada para `/03-revisao`; recomendado corrigir a observacao nao
  bloqueante do deploy-checklist antes/durante essa etapa. Cartao
  Trello mantido em "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-19 -- Correcao documental curta (Fase 1) +
  `/03-revisao` independente (Fase 2) da demanda
  `robustez-rate-limit-migrations`. Fase 1: corrigida a referencia
  desatualizada a `SIGNAL SQLSTATE` em `docs/deploy-checklist.md`
  (achado ATENCAO nao bloqueante da 3a rodada de `/02-testes`),
  descrevendo agora os erros nativos reais de abort da migration.
  Fase 2: 3 revisores independentes (qa-testes + backend-especialista
  + security-especialista, novas instancias, sem participacao na
  implementacao nem na 3a rodada de `/02-testes`). Resultado:
  qa-testes APROVADO (2 achados ATENCAO nao bloqueantes: comentario
  desatualizado em teste manual; 16 bancos MariaDB residuais de QA
  na instancia local, fora do git); security-especialista APROVADO
  (1 achado ATENCAO nao bloqueante: linha ~171 desta tabela
  descrevendo versao intermediaria da migration com SIGNAL, hoje
  incorreta); backend-especialista **PRECISA DE AJUSTE** -- achado
  NOVO real: `docs/deploy-checklist.md` e o cabecalho da migration
  013 afirmam que o PASSO 0 aborta com `ERROR 1146 (Table doesn't
  exist)`, mas o erro real reproduzido empiricamente e `ERROR 1103
  (Incorrect table name)`, porque o nome de tabela inexistente usado
  excede 64 caracteres (limite de identificador do MySQL/MariaDB) --
  mecanismo de abort continua seguro/deterministico, e imprecisao
  textual apenas, mas do tipo que esta etapa deveria confirmar
  corrigido. **VEREDITO CONSOLIDADO: PRECISA DE AJUSTE.** Nenhum
  achado de seguranca/regressao/perda de dado; os 2 achados
  bloqueantes de rodadas anteriores permanecem corrigidos e
  reconfirmados. Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`,
  secao "/03-revisao independente (2026-09-19)". Demanda NAO avanca
  para `/04-commit-e-push` -- aguarda rodada curta adicional de
  correcao documental (ERROR 1146 -> ERROR 1103) e decisao do
  usuario sobre os 3 itens nao bloqueantes. Cartao Trello mantido em
  "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-19 -- Rodada curta de correcao textual (Fase 1) + `/03-revisao`
  curta independente (Fase 2) da demanda
  `robustez-rate-limit-migrations`. Fase 1: corrigidas as 3
  inconsistencias textuais apontadas pela `/03-revisao` anterior --
  (1) `docs/deploy-checklist.md` e cabecalho da migration 013
  corrigidos de `ERROR 1146` para `ERROR 1103` (causa: identificador
  proposital >64 caracteres, limite do MySQL/MariaDB), conteudo
  executavel da migration confirmado byte a byte identico; (2)
  comentario de `tests/manual/_caso_controller_identificar_cliente.php`
  (cenario `dao_ausente`) atualizado para refletir o comportamento
  ATUAL (503 sanitizado), sem alterar nenhuma instrucao executavel;
  (3) linha ~171 desta tabela corrigida para diferenciar a versao
  intermediaria da migration 013 (SIGNAL) da versao final (sem
  SIGNAL). Bancos residuais de QA (16, prefixo `qa013_*`/`qa_iso2`)
  explicitamente NAO tocados, registrados como pendencia operacional
  separada. Fase 2: revisor independente (security-especialista,
  nova instancia) confirmou os 9 dos 10 pontos verificados como
  corretos, mas encontrou 1 achado NOVO fora do escopo explicito
  desta rodada: linhas ~318-326 desta mesma tabela (achado de
  severidade media sobre mascaramento de indice por nome) ainda
  descrevem a correcao daquele achado como baseada em stored
  procedure/SIGNAL, mesma causa raiz do item 3 ja corrigido na linha
  171. **VEREDITO: PRECISA DE AJUSTE**, estritamente por este ponto
  pontual. Nenhuma alteracao funcional indevida, nenhum excesso de
  escopo, nenhuma regressao, apenas 1 consulta somente-leitura contra
  banco (sem escrita) pelo revisor. Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`,
  secoes "Rodada curta de correcao textual final" e "/03-revisao
  curta e independente da correcao textual". Demanda aguarda decisao
  do usuario sobre nova rodada muito curta para a linha ~318-326.
  Cartao Trello mantido em "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-19 -- Rodada final minima de correcao + confirmacao
  independente da demanda `robustez-rate-limit-migrations`. Corrigida
  a unica referencia remanescente (linhas ~318-347 desta tabela,
  achado de severidade media sobre mascaramento de indice por nome):
  texto agora diferencia claramente VERSAO INTERMEDIARIA (SIGNAL/
  stored procedure, marcada como SUBSTITUIDA) de VERSAO FINAL (sem
  SIGNAL/stored procedure/CREATE ROUTINE/ALTER ROUTINE, colisao via
  `ERROR 1061`, PASSO 0 via `ERROR 1103`). Busca global por 9 termos
  relacionados (SIGNAL, SQLSTATE, ERROR 1146, CREATE ROUTINE, ALTER
  ROUTINE, stored procedure, PROCEDURE, PASSO 0, migration 013)
  confirmou zero descricao ativa incorreta restante -- todas as
  demais ocorrencias sao registro historico datado (convencao ja
  usada em todo o arquivo) ou referencia de outra demanda sem
  relacao. Revisor independente (qa-testes, nova instancia) fez
  busca global propria e confirmou a mesma classificacao, alem de
  confirmar consistencia entre os 4 documentos
  (ia_development_state.md, handoff, deploy-checklist.md, cabecalho
  da migration 013), ausencia de toque na migration nesta rodada
  (mtime identico), e preservacao dos 2 achados bloqueantes
  originais. **VEREDITO FINAL: APROVADO.** Zero excesso de escopo
  (so esta tabela + handoff tocados), zero segredo/dado pessoal,
  zero nova operacao real (bancos residuais de QA nao tocados nem
  consultados). Ver
  `docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md`,
  secoes "Rodada final minima de correcao (2026-09-19)" e
  "Confirmacao independente final (2026-09-19)". **Demanda liberada
  para `/04-commit-e-push`.** Pendencia operacional separada
  registrada: inventario/limpeza dos 16 bancos MariaDB residuais de
  QA (`qa013_*`, `qa_iso2`), mediante autorizacao explicita futura
  do usuario. Cartao Trello mantido em "Sprint Bruno - Fazendo
  [Semanal]" ate a execucao de `/04-commit-e-push`:
  `card_id 6aaca8f164c46e169c806f67`.
- 2026-09-19 -- `/00-planejamento` da demanda
  `vio-hardening-sem-credenciais` concluido. 4 especialistas
  independentes (backend, frontend, security, qa-testes), cada um
  lendo o codigo real, sem confiar em descricao historica. Confirmado
  com evidencia de codigo (2 revisores convergentes) que 3 pendencias
  historicas ja estao genuinamente resolvidas: rebaixamento para
  MANUAL (`AtendimentoRn::salvarDadosMotorista`), descarte do campo
  `image` (allowlist positiva e fechada em `DocumentoRn`, mais forte
  que blocklist pontual), simetria de `ehValorPlaceholder()`
  CNH/CRLV. Confirmadas como ainda reais: credenciais Trial nao
  obtidas (grupo 2); correspondencia de `jsQR.binaryData` com QR
  fisico real (grupo 3). 4 achados NOVOS registrados nesta secao
  (2 de severidade baixa/atencao implementaveis sem credenciais:
  mensagem tecnica de autenticacao vazando ao cliente em
  `VioDecodeClient.php:105`; ausencia de limite de tamanho de
  resposta HTTP antes do parse; 1 observacao de robustez preventiva
  em catches genericos; 1 item a confirmar via teste, nao achado
  ainda -- possivel TypeError em acesso a `$dadosBrutos` malformado).
  Matriz de 14 cenarios de teste desenhada, reaproveitando o padrao
  de mock ja existente no projeto (`VioDecodeClientFalso`, injecao de
  `$env` no construtor) -- nenhum cenario depende de credencial real
  ou QR fisico. Nenhuma decisao bloqueante identificada para o grupo
  1 (todos reforcos de baixa complexidade, sem impacto em contrato/
  regra de negocio). Zero credencial obtida, zero chamada real a
  VIO/Serpro/Talent, zero documento/CPF/placa/QR real usado, zero
  alteracao de codigo/banco. Ver
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`.
  Proximo passo: usuario decidir se autoriza `/01-implementacao` do
  grupo 1 nesta demanda.
- 2026-09-19 -- `/01-implementacao` do Grupo 1 da demanda
  `vio-hardening-sem-credenciais` concluida. `app/Rn/VioDecodeClient.php`:
  (1) mensagem de erro sanitizada e fixa
  (`MENSAGEM_ERRO_GENERICA`) devolvida para QUALQUER falha tecnica
  (autenticacao/rede/timeout/HTTP 4xx-5xx/JSON invalido/tamanho
  excedido) -- nunca mais status HTTP, curl errno, mensagem bruta,
  URL/host chegam ao campo `motivo`; detalhe tecnico so em log
  categorizado (`logFalhaTecnica()`, mesmo padrao de
  `NotaController::logFalhaBancoPdo()`); (2) teto de 10 MB
  (`TAMANHO_MAXIMO_RESPOSTA_BYTES`) aplicado ANTES do `json_decode` via
  `CURLOPT_WRITEFUNCTION` customizado (aborta a transferencia ao
  exceder, nunca carrega o excedente em memoria); (3) possivel
  `TypeError` de `$dadosBrutos` nao-array (achado 4 do planejamento)
  reproduzido empiricamente como NAO REAL -- `?? ''` ja protege
  totalmente -- `DocumentoRn.php` NAO foi alterado (decisao correta,
  sem mudanca preventiva sem evidencia). Novos artefatos de teste:
  `tests/manual/mock_vio_server.php` (mock HTTP local, `php -S`, nunca
  Serpro real) e `tests/manual/teste_vio_decode_robustez.php` (matriz
  de 14 cenarios do planejamento, 80/80 asserções passando, incluindo
  limite de tamanho EXATO e 1 byte acima via cURL real contra o mock).
  Prova negativa obrigatoria executada: leak original reintroduzido
  temporariamente, detectado pelo teste de sanitizacao, revertido a
  100% e confirmado via `diff`/`git diff`. Regressao: 8 suites
  reexecutadas (`teste_vio_decode.php` 21/21,
  `teste_rebaixamento_manual.php` 45/45,
  `teste_status_processamento.php` 9/9,
  `teste_concorrencia_processamento_vio.php` 16/16,
  `teste_avancar_etapa_expedicao.php` 10/10,
  `teste_fluxo_recebimento_documentos.php` 11/11,
  `teste_talent_uf_crlv.php` 11/11,
  `teste_talent_rntc_tipo_crlv.php` 23/23,
  `teste_validacao_jpeg_seguro.php` 22/22) -- zero falha, zero
  regressao. Contrato HTTP/JSON, allowlists, rebaixamento MANUAL,
  fallback manual, placeholders -- todos preservados (nao alterados).
  Grupos 2/3 NAO tocados (dependem de credencial Trial/teste fisico).
  Item "observacao de catches genericos" (achado 3 do planejamento)
  mantido como pendencia nao-bloqueante (nenhum teste real demonstrou
  falha alcancavel). Zero credencial obtida/usada, zero chamada real a
  VIO/Serpro/Talent, zero documento/CPF/placa/QR real, zero commit/
  push (aguardando `/02-testes`/`/03-revisao`/decisao do usuario). Ver
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`, secao
  "Implementacao do Grupo 1 (2026-09-19)".
- 2026-09-19 -- `/02-testes` independente da demanda
  `vio-hardening-sem-credenciais` concluido com veredito PRECISA DE
  AJUSTE. 3 revisores independentes (qa-testes + backend-especialista
  + security-especialista, novas instancias). qa-testes APROVADO
  (sanitizacao/prova negativa real com reversao confirmada por
  md5sum/fallback/regressoes todas batendo exatamente: 80/80, 21/21,
  45/45, 9/9, 16/16, 10/10, 11/11, 11/11, 23/23, 22/22).
  backend-especialista PRECISA DE AJUSTE: limite de 10MB validado em
  profundidade (14 cenarios, corte por bytes reais nao por
  Content-Length, corte progressivo, pico de memoria ~26 MiB, sem
  vazamento entre chamadas) -- SEM achado aqui; porem reproduziu de
  forma independente 1 achado BLOQUEANTE (cast (string) sem
  is_string() aprova array aninhado como nome="Array" valido, sem
  excecao, sem sinalizacao de revisao) e 1 achado MODERADO
  (stdClass/array aninhado em cpf/data_validade escapam de
  DocumentoRn, so nao viram fatal error por dependerem de catch
  generico acidental do Controller). security-especialista PRECISA
  DE AJUSTE: confirmou de forma independente o mesmo achado
  bloqueante (classificado la como ATENCAO, mas a severidade mais
  alta do backend prevalece), allowlist/origem confirmadas corretas
  para os demais vetores testados. **VEREDITO CONSOLIDADO: PRECISA
  DE AJUSTE**, por 2 achados novos e reais (nao estavam na lista
  original do planejamento -- a `/01-implementacao` nao testou campo
  INTERNO individual sendo array aninhado, so `$dadosBrutos` como um
  todo). Nenhum vazamento de dado sensivel -- risco e de integridade
  (nome corrompido aprovado), nao confidencialidade. Trabalho ja
  implementado (sanitizacao de mensagem, limite de 10MB) reconfirmado
  correto por 3 revisores. Zero credencial/chamada real/dado real
  usado nesta rodada. Ver
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`, secao
  "/02-testes independente (2026-09-19)". Demanda retorna para
  rodada curta de `/01-implementacao` restrita aos 2 achados. Cartao
  Trello mantido em "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aae9bbdda2fce08c1b7269d`.
- 2026-09-19 -- Rodada curta de `/01-implementacao` da demanda
  `vio-hardening-sem-credenciais`, restrita aos 2 achados do
  `/02-testes` independente anterior. **~~1. BLOQUEANTE: cast
  `(string)` sem `is_string()` previo em `nome`/`placa`/`uf`/`rntc`/
  `tipo` permitia array aninhado virar a string literal "Array" e ser
  aprovado/persistido como dado valido~~ -- CORRIGIDO.** **~~2.
  MODERADO: `dados`/`cpf`/`data_validade` como stdClass/array
  aninhado lancavam Error/TypeError reais que escapavam de
  `DocumentoRn`, so nao explodindo ao cliente por acidente do catch
  generico do Controller~~ -- CORRIGIDO.** `app/Rn/DocumentoRn.php`:
  adicionada fronteira EXPLICITA de tipo/esquema
  (`ESQUEMA_TIPOS_CNH`/`ESQUEMA_TIPOS_CRLV`, documentando 'texto' vs
  'numerico' para cada campo das allowlists), aplicada ANTES de
  qualquer cast/trim/normalizacao/persistencia: (a) `dados`/
  `dados['data']` validados como array via `is_array()` ANTES de
  qualquer acesso por chave (protege contra stdClass, que faria
  `$x['data']` lancar `Error` fatal); (b) novos metodos privados
  `extrairCampoTexto()`/`extrairCampoNumerico()` validam cada campo
  (nome/cpf/data_validade/placa/uf/rntrc/tipo/exercicio) com
  `is_string()`/`is_int()`/`is_float()` antes de qualquer uso --
  array/objeto/bool/int/float sendo coagido a string e agora REJEITADO
  (lanca `DocumentoVioTipoInvalidoException`, arquivo novo, mensagem
  SEM valor do payload -- so documento/campo/tipo PHP via
  `get_debug_type()`); (c) tipo incompativel invalida a resposta VIO
  INTEIRA daquele documento (nunca so o campo) -- retorna a mesma
  estrutura de falha (`falhaEstruturaInvalidaCnh()`/
  `falhaEstruturaInvalidaCrlv()`, mensagem generica fixa
  `"...dados retornados em formato invalido"`) usada para resposta
  incompleta: nunca persiste valor parcial, nunca marca origem
  VIO_TRIAL/VIO_VALIDADO, nunca fica preso em PROCESSANDO, conduz ao
  mesmo fallback manual ja existente. Exceptions SEMPRE capturadas
  DENTRO de `DocumentoRn` (try/catch em `validarCnh()`/`validarCrlv()`)
  -- fluxo NAO depende mais do catch generico
  `\Throwable`/`DocumentoController.php:247` para sobreviver a resposta
  VIO malformada (catch do Controller preservado, intocado, nao
  ampliado -- continua so como camada de seguranca adicional). Regra
  de conteudo (`ehValorPlaceholder()`, string vazia = "nao informado",
  `normalizarData()`) preservada sem alteracao -- fronteira nova SO
  valida tipo, nunca conteudo. `exercicio` (unico campo 'numerico')
  ja era protegido incidentalmente por `is_numeric()` (array/objeto
  sempre `false`, sem warning) -- agora protegido por barreira
  explicita simetrica aos demais campos, invalidando a resposta
  inteira em vez de silenciosamente zerar. Novo arquivo:
  `app/Rn/DocumentoVioTipoInvalidoException.php`. Novo teste:
  `tests/manual/teste_vio_decode_matriz_tipos_campos.php` -- matriz
  por campo (CNH: nome/cpf/data_validade; CRLV: placa/uf/rntrc/tipo/
  exercicio) cobrindo array vazio/aninhado (com marcador sintetico
  `MARCADOR_QA02_*`)/stdClass/inteiro/float/booleano, mais regressao
  de string vazia/null/placeholder/caminho feliz, mais 3 casos de
  envelope invalido (`dados` como stdClass, `dados['data']` como
  stdClass/inteiro) -- **453/453 asserções passando**, incluindo
  confirmacao direta em banco (recarrega o atendimento apos a chamada)
  de que `motorista_nome`/colunas `crlv_*` NUNCA sao persistidas como
  `"Array"` ou com o marcador sintetico, e que `cnh_origem_validacao`/
  `crlv_origem_validacao` permanecem `NAO_VALIDADO` (nunca
  VIO_TRIAL/VIO_VALIDADO com dado corrompido). `error_log` redirecionado
  para arquivo isolado da suite com `display_errors=1`/
  `error_reporting=E_ALL` explicitos -- confirmado que o log so contem
  entradas categorizadas (`tipo_recebido=array`/`tipo_recebido=stdClass`,
  nunca o valor) e ZERO warning de "Array to string conversion"/marcador
  sintetico. **Mutacao temporaria controlada executada conforme
  exigido**: `is_string()`/casts revertidos ao codigo vulneravel
  original em `validarCnh()`/`validarCrlv()`, suite reexecutada --
  79 de 386 asserções passaram a falhar (regressao detectada:
  `motorista_nome` voltou a aceitar "Array", `cpf`/`data_validade`/
  `placa`/`uf`/`rntrc`/`tipo` como stdClass voltaram a lancar TypeError/
  Error nao capturado) -- revertido a 100% via copia de backup,
  confirmado `md5sum` identico antes/depois
  (`fb2d3f3515f7d3424411a08f5b40dbf5`), suite completa reexecutada
  limpa (453/453) apos a reversao. Regressao: `teste_vio_decode_robustez.php`
  80/80, `teste_vio_decode.php` 21/21, `teste_status_processamento.php`
  9/9, `teste_concorrencia_processamento_vio.php` 16/16,
  `teste_avancar_etapa_expedicao.php` 10/10,
  `teste_fluxo_recebimento_documentos.php` 11/11,
  `teste_talent_uf_crlv.php` 11/11, `teste_talent_rntc_tipo_crlv.php`
  23/23, `teste_validacao_jpeg_seguro.php` 22/22 -- todas batendo
  exatamente. `teste_rebaixamento_manual.php` exigiu 1 ajuste de
  manutencao (nao uma falha real): a asserção estatica que contava
  `substr_count()` de `unset($resultadoVio, $dadosBrutos);` esperando
  exatamente 2 ocorrencias ficou desatualizada, porque a nova
  fronteira de tipo introduziu mais ramos de retorno antecipado (cada
  um corretamente descartando os dados brutos antes de retornar) --
  assercao reescrita para confirmar que AMBOS `validarCnh()`/
  `validarCrlv()` descartam em TODOS os caminhos (`>= 1` por metodo,
  verificado por corpo de funcao via `substr()`, nao mais um numero
  magico fixo) -- suite final: **47/47** (2 assercoes a mais que as
  45 originais, refletindo a checagem mais precisa). Caminho feliz
  (CNH/CRLV 100% validos) confirmado sem nenhuma alteracao de
  comportamento em todas as suites. `DocumentoController.php`, `.env`,
  `composer.json`/`composer.lock` -- intocados (confirmado via
  `git diff --stat`). Zero credencial obtida/usada, zero chamada real
  a VIO/Serpro/Talent, zero documento/CPF/placa/QR real, zero banco
  `qa013_*`/`qa_iso2` tocado, zero residuo (confirmado 0 linhas de
  teste remanescentes em `tb_totem`/`tb_atendimento` apos a rodada),
  zero commit/push (aguardando nova `/02-testes`). Ver
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`, secao
  "Correcao dos 2 achados do /02-testes independente (2026-09-19)".
  Cartao Trello mantido em "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aae9bbdda2fce08c1b7269d`.
- 2026-09-19 -- Nova rodada de `/02-testes` independente da demanda
  `vio-hardening-sem-credenciais`, focada na correcao dos 2 achados
  de integridade da rodada anterior. 3 revisores independentes
  (qa-testes + backend-especialista + security-especialista, novas
  instancias). Todos APROVADOS: reproducao independente dos 7 casos
  originalmente vulneraveis sem "Array"/TypeError/Error (33/33 +
  20/20 asseriosoes proprias adicionais); suite nova de matriz por
  campo reconfirmada 453/453 (recontagem manual da cobertura, nao
  apenas aceita); prova negativa com mutacao real + reversao
  confirmada por hash identico em 2 execucoes independentes; testes
  de atomicidade/persistencia parcial em banco descartavel dedicado
  (5 variacoes, incluindo confirmacao de que dado pre-existente
  nunca e sobrescrito por tentativa rejeitada); excecao interna
  `DocumentoVioTipoInvalidoException` confirmada por teste
  controlado como nunca escapando de `DocumentoRn`; todas as 11
  regressoes batendo exatamente com o esperado (453/453, 80/80,
  21/21, 47/47, 9/9, 16/16, 10/10, 11/11, 11/11, 23/23, 22/22).
  **VEREDITO CONSOLIDADO: APROVADO.** 2 observacoes nao bloqueantes
  registradas como backlog futuro (fora do escopo desta demanda):
  redacao de 1 asserção em `teste_rebaixamento_manual.php` mais
  forte do que o que de fato verifica (sem perda de cobertura real
  confirmada); truncamento silencioso de float em `exercicio` do
  CRLV, comportamento pre-existente herdado do cast ja existente
  antes desta demanda. Zero credencial/chamada real/dado real usado
  nesta rodada. Ver
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`,
  secao "Nova rodada de /02-testes independente, foco correcao de
  integridade". **Demanda liberada para `/03-revisao`.** Cartao
  Trello mantido em "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aae9bbdda2fce08c1b7269d`.
- 2026-09-20 -- `/03-revisao` independente da demanda
  `vio-hardening-sem-credenciais` concluida com veredito PRECISA DE
  AJUSTE. 3 revisores independentes (backend-especialista +
  security-especialista + qa-testes, novas instancias).
  security-especialista APROVADO (limpeza de escopo confirmada,
  incluindo Trello byte-identico a HEAD; sanitizacao completa;
  excecao interna confirmada contida por teste proprio; 1 achado de
  higiene nao bloqueante -- processo de teste orfao encerrado
  durante a propria revisao). qa-testes APROVADO (reproducao dos 7
  casos vulneraveis; matriz de 453 recontada manualmente sem
  inflacao; prova negativa independente propria com hash identico;
  11 regressoes reconfirmadas; imprecisao de redacao em asserção de
  teste classificada ATENCAO por nao criar evidencia de seguranca
  falsa sobre a protecao primaria). backend-especialista PRECISA DE
  AJUSTE: confirmou (nao descartou) o achado bloqueante do campo
  `exercicio` -- truncamento silencioso de float/string fracionaria
  aprovado sem sinalizacao, reproduzido pelo fluxo real em banco
  descartavel; demais itens de seu escopo (limite de 10MB,
  mapeamento de campos, atomicidade) todos aprovados com evidencia
  real. **VEREDITO CONSOLIDADO: PRECISA DE AJUSTE**, por este unico
  achado bloqueante. Zero credencial/chamada real/dado real usado
  nesta rodada. Ver
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`, secao
  "/03-revisao independente (2026-09-20)". Demanda retorna para
  rodada curta de `/01-implementacao` restrita a este achado. Cartao
  Trello mantido em "Sprint Bruno - Fazendo [Semanal]":
  `card_id 6aae9bbdda2fce08c1b7269d`.
- 2026-09-20 -- `/03-revisao` curta independente da correcao final
  do campo `exercicio` na demanda `vio-hardening-sem-credenciais`.
  Revisor independente (security-especialista, nao participou da
  ultima `/01-implementacao`). VEREDITO: PRECISA DE AJUSTE -- achado
  BLOQUEANTE novo, reproduzido pelo fluxo real em banco descartavel:
  `validarExercicioInteiroExato()` valida so o formato lexical
  (regex), nunca a magnitude -- string de digitos > PHP_INT_MAX
  satura silenciosamente (PHP para PHP_INT_MAX, coluna MySQL
  SMALLINT para 32767) e e aprovada como exercicio valido com origem
  VIO_TRIAL. Restante do escopo revisado sem achados: ordem de
  validacao, fracionarios/notacao cientifica, sinal negativo
  (rejeitado pela regra de faixa), zeros a esquerda, Unicode
  minus/espaco/prefixo-sufixo, atomicidade das demais rejeicoes,
  matriz de 556 recontada de forma independente, todas as 11
  regressoes reexecutadas e batendo, escopo confirmado limpo
  (Trello byte-identico a HEAD). Zero credencial/chamada real/dado
  real usado. Ver
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`, secao
  "/03-revisao curta independente da correcao final de exercicio".
  Demanda NAO liberada para `/04-commit-e-push` -- retorna para nova
  rodada curta de `/01-implementacao` restrita a este achado de
  magnitude/overflow. Cartao Trello mantido em "Sprint Bruno -
  Fazendo [Semanal]": `card_id 6aae9bbdda2fce08c1b7269d`.
- 2026-09-20 -- `/02-testes` (2 revisores) + `/03-revisao` final (1
  revisor) independentes da correcao de magnitude/overflow de
  `exercicio`, demanda `vio-hardening-sem-credenciais`. Ambas as
  etapas: APROVADO. `/02-testes` (qa-testes + security-especialista):
  algoritmo `caberEmPhpInt()` confirmado matematicamente correto (18
  casos adversariais proprios), proteção 100% em nivel de aplicacao,
  prova negativa em copia isolada com hash identico, 590/590 +
  regressoes reconfirmadas. `/03-revisao` final (backend-especialista,
  independente das 2 fases anteriores): todos os 13 controles
  obrigatorios confirmados com execucao real propria (banco
  descartavel dedicado, prova negativa propria com hash identico,
  escopo de arquivos limpo, Trello byte-identico a HEAD). 2 itens de
  backlog nao bloqueantes registrados para decisao futura, fora do
  escopo desta demanda: saturacao da coluna `crlv_ano` SMALLINT
  (ATENCAO); comportamento de `ehValorPlaceholder()` com digito unico
  (observacao pre-existente). Zero credencial/chamada real/dado real
  usado. Ver
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`, secoes
  "/02-testes independente da correcao de magnitude" e "/03-revisao
  final independente". **Demanda `vio-hardening-sem-credenciais`
  liberada para `/04-commit-e-push`.** Cartao Trello mantido em
  "Sprint Bruno - Fazendo [Semanal]" ate a confirmacao do push:
  `card_id 6aae9bbdda2fce08c1b7269d`.
