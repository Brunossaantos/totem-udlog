# Estado real do projeto — totem-udlog

> Este documento é a ÚNICA fonte de verdade sobre o estado atual do projeto.
> TODO sub-agente e o orquestrador leem este arquivo ANTES de planejar ou
> implementar qualquer coisa. Nada aqui é suposição — o que não foi
> confirmado fica marcado como PENDENTE, nunca é preenchido com invenção.
>
> Este arquivo é atualizado ao final de cada ciclo de implementação
> (etapa 01-implementacao e 04-commit-e-push do workflow).

Última atualização: 2026-09-03

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
| URL, autenticação e formato de resposta da API de ordens de coleta e clientes | `app/Rn/OrdemColetaClient.php` | Aguardando `docs/db_gestao_coletas.md` |
| URL, autenticação e formato de resposta da API do Talent (envio final) | `app/Rn/TalentClient.php` | Aguardando `docs/manual_talent.md` |
| Viabilidade/convênio da API Prodesp (CNH/CRLV) | `app/Rn/ProdespClient.php` | Não confirmado — pode nunca ser viável; QR da CNH digital é payload assinado, QR do CRLV-e costuma ser só link de validação |
| Origem/sincronização de `tb_cliente` | Autocomplete do recebimento + casamento por CNPJ | Não decidido — importar do Talent? Cadastro manual? CSV? |
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
