# Estado real do projeto — totem-udlog

> Este documento é a ÚNICA fonte de verdade sobre o estado atual do projeto.
> TODO sub-agente e o orquestrador leem este arquivo ANTES de planejar ou
> implementar qualquer coisa. Nada aqui é suposição — o que não foi
> confirmado fica marcado como PENDENTE, nunca é preenchido com invenção.
>
> Este arquivo é atualizado ao final de cada ciclo de implementação
> (etapa 01-implementacao e 04-commit-e-push do workflow).

Última atualização: 2026-09-08

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
| URL, autenticação e formato de resposta da API de ordens de coleta | `app/Rn/OrdemColetaClient.php` | Aguardando `docs/db_gestao_coletas.md` (parte de ordens de coleta ainda vazia) |
| ~~API de clientes externa~~ usada em `identificar-cliente` | `app/Rn/ClienteApiClient.php` | SUBSTITUÍDA em 2026-09-08 por consulta local a `tb_cliente` (demanda `recebimento-clientes-tabela-local`) — `ClienteApiClient.php` mantido no código como morto/documentado, sem uso de produção; `CLIENTES_API_TOKEN` removido do `.env.example` |
| URL, autenticação e formato de resposta da API do Talent (envio final) | `app/Rn/TalentClient.php` | Aguardando `docs/manual_talent.md` |
| Viabilidade/convênio da API Prodesp (CNH/CRLV) | `app/Rn/ProdespClient.php` | Não confirmado — pode nunca ser viável; QR da CNH digital é payload assinado, QR do CRLV-e costuma ser só link de validação |
| Origem/sincronização de `tb_cliente` para clientes futuros (além dos 38 já inseridos) | Autocomplete do recebimento + casamento por CNPJ + novo OCR local | Parcialmente resolvida em 2026-09-08: `tb_cliente` agora tem 38 clientes reais (razão social + CNPJ validados, coluna nova `razao_social_normalizada`), usados deliberadamente pelos 3 fluxos (autocomplete, chave de acesso antiga, OCR novo). Não resolvido: processo de manutenção para adicionar clientes novos no futuro (continua manual, via migration de dado) |
| Paleta de cores oficial da UDLOG | Todo o front-end | Usando navy/branco como placeholder |
| Nome final do banco de dados no Hostgator | `.env` | cPanel provavelmente prefixa com o usuário da conta |
| Origem da chave de acesso da NF-e na tela `rec_digitaliza` sem o leitor HID | `public/totem/assets/app.js` (payload de `nota.php` envia `chave: null` fixo) | Não decidido — o leitor Netum antigo (HID) foi removido só dessa tela; não há substituto definido (não é OCR, não é leitura automática) |
| Validação física do Netum SD-2000 como `videoinput` no Windows/Chromium (label real, se aparece de fato, resolução suportada) | `public/totem/assets/app.js` (`iniciarCameraScanner`) | Não realizado — sem hardware físico disponível neste ambiente de desenvolvimento; roteiro de diagnóstico em `docs/deploy-checklist.md` |
| Tratamento de exceção de banco (`PDOException`) em `NotaController::buscarAtendimentoDoTotem`/`processar`/`algumaIdentificada` sem handler global | `app/Controller/NotaController.php` | Reportado pelo security-especialista — comportamento em falha de banco depende de configuração de `display_errors` do PHP no Hostgator, não confirmada |
| Confirmação de que `storage/` fica fora do document root real em produção (Hostgator) | Configuração do cPanel | Consistente na estrutura do repositório, mas não verificável só por leitura de código — depende de configuração real do domínio |
| Persistência de permissão de câmera por origem no Chromium kiosk / política `VideoCaptureAllowedUrls` | Ambiente do mini PC Windows | Não validado na prática — ver `docs/deploy-checklist.md` |
| IDOR em `selecionarOrdem` (ação `selecionar-ordem`) e `finalizar` (ação `finalizar`, envio ao Talent) — mesmo padrão já corrigido em outras ações | `app/Controller/AtendimentoController.php` | Identificado pelo security-especialista, NÃO corrigido — fora do escopo desta rodada. `finalizar` tem maior risco por disparar efeito colateral em sistema externo (Talent) para atendimento de outro totem |
| Ausência de lock/transação em `concluirDigitalizacao` contra corrida (cliques quase simultâneos em "Finalizar digitalização") | `app/Controller/AtendimentoController.php` | Severidade baixa segundo o security-especialista — não bloqueante, registrado para decisão futura |
| Retomada de atendimento ao recarregar a página (contador de notas em `state` não é sincronizado com o banco) | `public/totem/assets/app.js` | Limitação pré-existente do projeto (sem mecanismo de sessão/retomada) — não criado nesta demanda, apenas confirmado que não existe |
| JPEG truncado (sem marcador de fim) é aceito pela validação atual (`getimagesizefromstring()` não detecta truncamento) | `util/UploadHelper.php` | Achado médio dos testes reais de `/02-testes` — pode comprometer integridade de nota fiscal como evidência; decisão pendente se vale a pena validação adicional |
| `bloquear-excesso-notas` e `cancelar` permitem reverter um atendimento já `concluido` (sem checagem de status terminal) | `app/Controller/AtendimentoController.php` | Achado novo dos testes reais de `/02-testes` (confirmado por exploração real) — decisão de produto pendente sobre se deve ser recusado |
| Teste físico do Netum SD-2000 não executado (roteiro de 20 itens pronto, aguardando hardware/usuário) | Hardware do mini PC Windows | `/02-testes` retornou veredito INCONCLUSIVO por esse motivo — ver roteiro no handoff |
| Preview/captura do Netum SD-2000 corta a imagem do documento e a resolução capturada é insuficiente (texto ilegível) | `public/totem/assets/app.js` (`abrirStreamScanner`, `capturarFotoBase64` compartilhada com CNH/CRLV, CSS `.caixa-scanner`) | Achado do teste físico real em 2026-09-04 — `/02-testes` REPROVADO neste ponto. Causa raiz identificada: `getUserMedia` do scanner não solicita resolução (usa só `deviceId`), e a captura usa uma função compartilhada com CNH/CRLV que força downscale fixo para 900px de largura. Correção em andamento — ver seção 7 e handoff |
| Validação física do OCR client-side (Tesseract.js em Web Worker aninhado, tempo real de processamento, precisão de reconhecimento, cancelamento de fila em cenário real) | `public/totem/assets/ocr-worker.js`, `public/totem/assets/app.js` | Não realizado — sem navegador/hardware disponível neste ambiente; handoff `docs/handoffs/2026-09-04-recebimento-leitura-notas.md` lista o roteiro `[FÍSICO]` completo |
| Causa raiz do encoding corrompido (mojibake) em 8 dos 38 registros de `tb_cliente.nome` na migration 003 (INSERT rodado sem `SET NAMES utf8mb4`) não foi investigada/corrigida na origem — só os 8 dados já gravados foram corrigidos via UPDATE pontual em 2026-09-08 | `sql/migrations/003_tb_cliente_razao_normalizada.sql` | Se essa migration for reaplicada do zero em outro ambiente (novo banco), o mesmo problema de charset pode se repetir dependendo de como for executada — revisar processo de aplicação de migration (garantir `SET NAMES utf8mb4` explícito) antes de rodar em produção |
| Heurística de extração de "razão social candidata" no OCR (`ocr-worker.js`) é escolha de implementação, não formalmente decidida no handoff | `public/totem/assets/ocr-worker.js` | Pode precisar de ajuste após teste físico com notas reais |
| Valores de ajuste empírico (`LIMIAR_MINIMO`=80, `MARGEM_MINIMA`=15) definidos com valor inicial, não validados com volume real de produção; `CACHE_TTL_SEGUNDOS` não se aplica mais (era do cache HTTP da API externa, removido em 2026-09-08) | `util/RazaoSocialMatcher.php` | A recalibrar com dados reais após uso em produção |

| `tb_rate_limit_ocr` cresce indefinidamente sem job de limpeza (linhas de janelas antigas nunca são apagadas) | `sql/migrations/004_tb_rate_limit_ocr.sql`, `app/Dao/RateLimitOcrDao.php` | Severidade baixa segundo o `security-especialista` (crescimento limitado pelo próprio rate limit, sem vetor de amplificação por atacante externo) — recomendado job de limpeza futuro, não urgente |
| Rate limit de `identificar-cliente` é "fail-open" silencioso por design se `RateLimitOcrDao` não for injetado no `NotaController` (hoje sempre é injetado em `public/api/nota.php`, mas uma refatoração futura poderia desativar a proteção sem erro/log) | `app/Controller/NotaController.php` | Achado do `security-especialista` em 2026-09-08 — não é vulnerabilidade ativa hoje, registrado para avaliação futura (considerar fail-closed) |
| ~~**[ALTO]** Seed dos 38 clientes podia não rodar em instalação nova~~ | `sql/migrations/003_tb_cliente_razao_normalizada.sql` | RESOLVIDA em 2026-09-08 — migration reescrita com padrão de SQL preparado condicional (SET @sql := IF(...); PREPARE; EXECUTE), verdadeiramente idempotente independente do método de execução. Validado por `qa-testes` de forma independente em banco de teste descartável simulando instalação nova |
| ~~**[ALTO]** Mojibake se repetia em instalação nova sem SET NAMES~~ | `sql/migrations/003_tb_cliente_razao_normalizada.sql` | RESOLVIDA em 2026-09-08 — `SET NAMES utf8mb4;` adicionado como primeira instrução da migration. Validado por `qa-testes` via HEX() dos bytes gravados em banco de teste descartável |
| ~~**[MÉDIO]** `identificarCliente()` sem `try/catch` defensivo~~ | `app/Controller/NotaController.php` | RESOLVIDA em 2026-09-08 — envolvido em `try/catch (Throwable)` espelhando `processar()`, log técnico via `error_log`, resposta genérica ao cliente. Validado por `security-especialista` sem vazamento de `$e->getMessage()` |
| ~~**[MÉDIO]** `identificarCliente()` sem validação de status/etapa~~ | `app/Controller/NotaController.php` | RESOLVIDA em 2026-09-08 — validação de `status===em_andamento`/`etapa_atual===digitalizacao_notas` adicionada espelhando `processar()`. Confirmado por `security-especialista` que não quebra o early-stop (etapa só muda em `concluirDigitalizacao()`, chamado depois de todas as chamadas de identificação) |
| `sql/migrations/001_uk_atendimento_nota_ordem.sql` e `002_status_ocr_atendimento_nota.sql` têm o mesmo padrão frágil que a 003 tinha (checagem manual prévia, sem SQL preparado condicional) — abortariam se executadas via `mysql banco < arquivo.sql` de uma vez só contra um banco onde as colunas/índices já existem | `sql/migrations/001_*.sql`, `sql/migrations/002_*.sql` | Achado registrado em 2026-09-08 durante a correção da migration 003 (`recebimento-clientes-tabela-local`) — reproduzido pelo `qa-testes` ao simular instalação nova. Não corrigido (fora do escopo desta correção pontual) — decisão futura do usuário sobre se vale a pena aplicar o mesmo padrão retroativamente |

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
