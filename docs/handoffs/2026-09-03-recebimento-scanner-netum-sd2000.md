# Handoff — recebimento-scanner-netum-sd2000

Data: 2026-09-03
Etapa: 00-planejamento

## O que foi pedido

Colocar o scanner de documentos Netum SD-2000 (dispositivo de video USB) para
funcionar na digitalizacao de notas fiscais do fluxo de Recebimento,
corrigindo a premissa incorreta hoje registrada em ia_development_state.md
(secao 1) e no codigo, de que o Netum SD-2000 e um leitor de codigo de
barras/QR em modo teclado HID e de que existe uma camera USB separada para
fotografar documentos. O SD-2000 e, na verdade, o proprio scanner de
documentos, exposto como dispositivo de video. Escopo estritamente limitado
ao fluxo de Recebimento — nenhuma alteracao na Expedicao.

## O que sera feito (plano consolidado)

### Correcao de documentacao
- Corrigir ia_development_state.md secao 1 (hardware): Netum SD-2000 e o
  scanner de documentos (dispositivo de video USB), nao leitor HID; nao
  existe camera USB separada. Isso sera feito na etapa 01-implementacao /
  04-commit-e-push, junto com o restante do log de mudancas.

### Front-end (public/totem/assets/app.js, app.css) — escopo: so a tela rec_digitaliza
- Novo fluxo de selecao de dispositivo: getUserMedia({video:true}) para
  liberar permissao/labels, depois enumerateDevices() filtrando
  videoinput, depois heuristica de selecao por label (best-effort, nao
  confirmado no equipamento fisico), com fallback de selecao manual
  (modal) na ausencia de match confiavel. deviceId salvo em
  state.scannerDeviceId + localStorage para reuso entre atendimentos e
  reinicios do kiosk.
- Nova funcao dedicada iniciarCameraScanner() usando
  getUserMedia({video:{deviceId:{exact:deviceId}}}) — nao depende de
  facingMode.
- Mensagem "Conectando ao scanner" durante inicializacao; botao de captura
  desabilitado ate readyState/videoWidth/videoHeight validos
  (evento loadedmetadata).
- Guia visual de posicionamento sobreposto ao video; troca de
  object-fit cover para contain (nova classe .caixa-scanner, sem tocar
  .caixa-camera usada por CNH/CRLV) para nao cortar bordas da nota.
- Novo fluxo de captura com confirmacao: capturarPreviaNota() mostra
  preview da imagem capturada, com botoes "Usar imagem"/"Refazer";
  confirmarUsoImagemNota() so entao envia para a API.
- Contador de notas (state.notaOrdem) so incrementa apos resposta de
  sucesso do backend (elimina o padrao atual de incrementar antes e
  reverter em erro).
- Limite de 5 aplicado tambem dentro da propria tela de digitalizacao
  (hoje so e aplicado antes, na escolha "5 ou menos"/"Mais de 5").
- Bloqueio de clique duplo/envio duplicado via flag
  state.capturaNotaEmAndamento.
- Tratamento diferenciado de erros: permissao negada, scanner nao
  encontrado, scanner ocupado (NetumScan Pro/outro programa), dispositivo
  desconectado em uso (ondevicechange), video nao carregado, imagem
  vazia/invalida, erro de envio, erro de salvamento, tentativa de
  ultrapassar 5 notas.
- Encerramento de camera ao sair/cancelar ja e coberto pela pararCamera()
  atual (chamada em toda troca de tela) — decisao pendente se
  pararCamera() deve ser levemente estendida para tambem encerrar o
  stream do scanner, ou se isso fica isolado em pararCameraScanner()
  chamada so nos pontos de saida de rec_digitaliza (ver pendencias).
- Remocao da chamada a habilitarLeitorScanner()/onLeituraScanner() (logica
  de leitor HID) somente na tela rec_digitaliza; as outras 4 telas que
  usam essa funcao (CNH, CRLV expedicao, CRLV recebimento) nao sao
  tocadas.
- Miniaturas passam a exibir a imagem real capturada (nao mais um "check").

### Back-end (NotaController.php, NotaFiscalRn.php, AtendimentoNotaDao.php, UploadHelper.php, sql/schema.sql)
- nota.php passa a capturar $totem = Auth::validarTotem($pdo) e repassar
  id_totem ao controller (hoje o retorno e descartado).
- NotaController::processar passa a validar, em sequencia, antes de
  qualquer escrita:
  1. Atendimento existe (ja implementado hoje, correto).
  2. Atendimento pertence ao totem autenticado (correcao de falha critica
     de autorizacao/IDOR — hoje qualquer totem autenticado pode gravar
     nota em atendimento de outro totem).
  3. Tipo do atendimento e recebimento.
  4. Status e em_andamento.
  5. Etapa de digitalizacao — pendente de decisao de produto (ver
     Pendencias).
  6. ordem entre 1 e 5.
  7. Contagem atual de notas do atendimento menor que 5 (nova consulta na Dao).
  8. Base64 decodificado com base64_decode($x, true) (modo estrito) e
     nao vazio.
  9. Mimetype real validado via magic bytes (finfo), allowlist a
     confirmar (proposta: JPEG/PNG).
  10. Tamanho maximo do binario — proposta tecnica de 8MB, a confirmar.
  11. Confirmacao real de mkdir/file_put_contents (hoje ambos retornos
      sao ignorados).
  12. UNIQUE(id_atendimento, ordem) nova constraint em
      tb_atendimento_nota + checagem previa na aplicacao, com remocao do
      arquivo gravado (unlink) se o INSERT falhar por duplicidade.
  13. Resposta de sucesso so depois do INSERT confirmado
      (lastInsertId()).
- Alteracao de schema (UNIQUE(id_atendimento, ordem)) precisa ser
  aplicada em sql/schema.sql e via ALTER TABLE manual no banco ja
  existente (nao ha mecanismo de migration no projeto hoje — decisao de
  manter esse padrao ou criar um novo fica pendente, nao decidida
  unilateralmente).

### DevOps (ambiente Windows/Chromium kiosk)
- Confirmar contexto seguro do navegador (HTTPS ou localhost) — topologia
  real do totem nao esta documentada, fica como pendencia.
- Verificar persistencia de permissao de camera por origem no profile do
  Chromium kiosk (mesmo --user-data-dir fixo, sem modo incognito) e
  avaliar (sem garantir) a politica VideoCaptureAllowedUrls — validacao
  pratica necessaria.
- Orientacao operacional: NetumScan Pro (se instalado) nao deve iniciar
  automaticamente com o Windows nem rodar em paralelo ao kiosk.
- Roteiro de diagnostico caso o Netum nao apareca como videoinput: checar
  classe de driver (UVC padrao vs. proprietario) no Gerenciador de
  Dispositivos, e Configuracoes de Privacidade > Camera do Windows
  ("permitir apps de area de trabalho").
- Achado relevante do devops-especialista: a secao 1 atual de
  ia_development_state.md ainda descreve o Netum como leitor HID sem
  camera — essa e exatamente a informacao que este planejamento ja
  identificou como incorreta e que sera corrigida na implementacao; nao e
  um novo bloqueio, e o objeto desta demanda.

### Seguranca (parecer do security-especialista)
- Critico: IDOR em id_atendimento — corrigido no plano do backend (item 2
  acima).
- Alto: ausencia de limite de tamanho de imagem (DoS por disco/memoria) —
  corrigido no plano do backend (item 10).
- Alto: ausencia de validacao de magic bytes/tipo real — corrigido no
  plano do backend (item 9).
- Alto: ordem sem validacao de faixa antes de compor nome de arquivo — nao
  e path traversal (cast para int neutraliza), mas e risco de
  integridade/sobrescrita — corrigido no plano do backend (item 6).
- Medio: falta de UNIQUE(id_atendimento, ordem) combinada com o IDOR vira
  vetor de adulteracao silenciosa de evidencia de outro atendimento —
  corrigido no plano do backend (item 12), condicionado a correcao do
  item critico.
- Medio: exposicao de erro interno em falha de gravacao/banco sem
  tratamento de excecao — a fechar na implementacao (handler global de
  excecoes nao confirmado nos arquivos revisados).
- Observacao: isolamento de storage/ fora do document root e consistente
  no codigo, mas a confirmacao final do document root real em producao
  (Hostgator) depende do devops-especialista, nao e verificavel so por
  leitura de codigo.

### QA (roteiro de teste, a executar em 02-testes)
Roteiro completo com 13 casos de fluxo (RT-01 a RT-13, incluindo teste de
regressao da Expedicao) e 9 casos de erro (EC-01 a EC-09), cobrindo todos
os criterios de aceite da demanda. RT-03, RT-04, RT-05, EC-02, EC-03 e
EC-04 exigem obrigatoriamente o equipamento fisico Netum SD-2000 — nao sao
validaveis com confiabilidade em ambiente de desenvolvimento.

## O que NAO sera feito

- Nenhuma alteracao no fluxo de Expedicao (placa -> consulta de ordens de
  coleta -> selecao -> restante do fluxo permanece intocado).
- Nenhuma alteracao nas telas de CNH ou CRLV (mesmo usando iniciarCamera e
  habilitarLeitorScanner, compartilhadas com a tela de digitalizacao de
  notas hoje) — so a tela rec_digitaliza e modificada.
- Nenhuma integracao com SDK nativo do fabricante (NetumScan Pro) — so Web
  APIs padrao (getUserMedia, enumerateDevices).
- Nenhuma suposicao de que o Netum envia Enter ou funciona como teclado
  HID.
- Nenhuma alteracao de contrato das APIs de ordens de coleta, Talent ou
  Prodesp.
- Nenhuma implementacao de OCR/leitura de codigo de barras da nota como
  substituto da leitura HID que sera removida da tela de digitalizacao —
  ver pendencia sobre a chave de acesso da nota.

## Sub-agentes envolvidos

- explorer — mapeou o estado real do codigo de digitalizacao (app.js,
  NotaController, NotaFiscalRn, AtendimentoNotaDao, UploadHelper, schema).
- frontend-especialista — planejou as mudancas em app.js/app.css para a
  tela rec_digitaliza.
- backend-especialista — planejou as 13 validacoes/mudancas em
  Controller/Rn/Dao/UploadHelper/schema.
- devops-especialista — planejou os pontos de ambiente Windows/Chromium
  kiosk a verificar.
- security-especialista — emitiu parecer de seguranca com achados por
  severidade.
- qa-testes — desenhou o roteiro de teste para a etapa 02-testes.

Nenhum sub-agente implementou codigo nesta etapa.

## Pendencias conhecidas

1. Como o Netum SD-2000 aparece em enumerateDevices() (label exato, se de
   fato surge como videoinput) — so validavel no equipamento fisico. Se
   nao aparecer como videoinput, este plano fica bloqueado e sera
   necessaria documentacao de SDK/integracao local do fabricante (fora de
   escopo desta demanda).
2. Origem da chave de acesso da nota (codigo de barras) na tela de
   digitalizacao — hoje lida via leitor HID (habilitarLeitorScanner), que
   sera removido dessa tela. Nao ha decisao de como (ou se) capturar essa
   chave sem o HID; o plano do frontend mantem chave: null fixo como
   placeholder ate haver decisao.
3. Se pararCamera() deve ser estendida para tambem encerrar o stream do
   scanner, ou se fica isolado em funcao propria — decisao tecnica pequena
   a confirmar na implementacao, ja que pararCamera() e compartilhada com
   as 4 telas fora de escopo.
4. Etapa de digitalizacao em etapa_atual — hoje o fluxo de Recebimento nao
   grava um valor de etapa especifico para a tela de digitalizacao;
   decisao pendente entre (a) passar a gravar/exigir essa etapa, ou (b)
   validar so por tipo+status do atendimento.
5. Allowlist de mimetype de imagem (JPEG/PNG) e limite de tamanho (~8MB
   proposto) — nao confirmados como decisao de produto, sao propostas
   tecnicas do backend-especialista a validar.
6. Mecanismo de migration de schema — nao existe hoje no projeto; pergunta
   em aberto se o padrao atual (editar schema.sql + ALTER manual no
   Hostgator) deve ser mantido para a nova constraint
   UNIQUE(id_atendimento, ordem).
7. Topologia de acesso do totem (HTTPS direto no dominio Hostgator vs.
   algum componente local no mini PC) — nao documentada, necessaria para
   confirmar se o requisito de secure context do getUserMedia ja esta
   satisfeito.
8. Versao/forma de instalacao do Chromium no kiosk e presenca de software
   proprietario do fabricante no mini PC — nao confirmadas, necessarias
   para avaliar viabilidade de politicas de permissao automatica de
   camera.
9. Resolucao(oes) suportada(s) pelo Netum — nao confirmada, necessaria
   para ajustar o CSS do preview sem cortar a nota.
10. Correcao da secao 1 de ia_development_state.md (hardware) e do
    comentario incorreto em app.js (linhas ~297-298) — planejada para a
    etapa de implementacao, junto com a atualizacao da secao 5 de
    pendencias.

## Proximo passo

Rodar /01-implementacao para executar este plano.

## Resultado da implementação

Data: 2026-09-03
Etapa: 01-implementacao

### O que foi implementado

**Correção de documentação**: seção 1 de `ia_development_state.md` corrigida
— o Netum SD-2000 é o scanner de documentos (dispositivo de vídeo USB), não
leitor HID; não existe câmera USB separada.

**Backend** (`backend-especialista`):
- `public/api/nota.php` passa a capturar `$totem = Auth::validarTotem($pdo)`
  e repassar `id_totem` ao Controller (correção do IDOR crítico).
- `NotaController::processar` valida, antes de qualquer escrita: posse do
  atendimento pelo totem autenticado (mensagem genérica, sem vazar dados de
  outro totem), tipo `recebimento`, status `em_andamento`, etapa
  `digitalizacao_notas`, `ordem` entre 1-5, contagem atual < 5, duplicidade
  de `ordem`, base64 estrito. Mesma validação de posse aplicada em
  `algumaIdentificada`.
- Nova etapa formal `digitalizacao_notas`: `AtendimentoController::salvarEtapa`
  ganhou um `case` novo (reaproveitando `AtendimentoRn::atualizarEtapa`, que
  já existia mas nunca era chamado). O front-end chama
  `atendimento.php?acao=salvar-etapa` com `{ id_atendimento, etapa:
  digitalizacao_notas }` logo após criar o atendimento de recebimento (5
  notas ou menos) e antes de entrar na tela de digitalização.
- `UploadHelper::salvarImagemBase64`: exige prefixo
  `data:image/jpeg;base64,`, `base64_decode` estrito, valida magic bytes
  reais de JPEG (`FF D8 FF`), limite de tamanho configurável via `.env`
  (`NOTA_IMAGEM_MAX_BYTES`, padrão 5MB), confirma retorno real de
  `mkdir`/`file_put_contents`, remove arquivo órfão se o INSERT falhar
  depois da gravação.
- `AtendimentoNotaDao`: novos métodos `contarPorAtendimento` e
  `existeOrdem`.
- `sql/schema.sql`: `UNIQUE KEY uk_atendimento_ordem (id_atendimento,
  ordem)` adicionada em `tb_atendimento_nota`.
- `sql/migrations/001_uk_atendimento_nota_ordem.sql` (novo): migration
  idempotente e não-destrutiva para instalações existentes — verifica
  duplicidade antes do `ALTER TABLE`, não apaga nada automaticamente.
- `.env.example`: nova variável `NOTA_IMAGEM_MAX_BYTES=5242880` documentada.
- Correção pós-revisão de segurança: `catch (\Throwable $e)` trocado por
  `catch (\RuntimeException $e)` e mensagem de erro fixa (sem repassar
  `$e->getMessage()` ao cliente) no bloco de `UploadHelper` dentro de
  `NotaController::processar`.

**Frontend** (`frontend-especialista`), somente na tela `rec_digitaliza`:
- Checagem de suporte do navegador (`mediaDevices`, `getUserMedia`,
  `enumerateDevices`, `isSecureContext`) antes de tentar iniciar o scanner.
- Seleção de dispositivo: `enumerateDevices()` filtrando `videoinput`,
  reuso de `deviceId` salvo em `localStorage`, seleção automática se só
  houver 1 câmera, seleção manual (modal) se houver mais de uma sem escolha
  válida salva.
- Preview com `deviceId` explícito (não depende só de `facingMode`), guia
  visual de posicionamento, `object-fit: contain` (classe nova
  `.caixa-scanner`, sem tocar `.caixa-camera` de CNH/CRLV).
- Estados textuais: "Conectando ao scanner", "Scanner pronto", "Capturando
  imagem", "Enviando documento", "Documento salvo", erros específicos.
- Botão de captura desabilitado até `readyState`/`videoWidth`/`videoHeight`
  válidos.
- Fluxo de captura com confirmação: captura → preview congelado → "Usar
  imagem"/"Refazer" → só então envia.
- Contador (`state.notaOrdem`) incrementado somente após resposta de
  sucesso do backend.
- Limite de 5 aplicado também dentro da tela (além da escolha inicial).
- Tratamento de erros específicos: `NotAllowedError`, `NotFoundError`,
  `NotReadableError`/`TrackStartError`, `OverconstrainedError`, dispositivo
  desconectado (`track.onended`, `ondevicechange`), vídeo sem frame válido,
  erro de rede/API (mantém preview para nova tentativa sem recapturar).
- `pararCamera()` estendida para também encerrar o stream do scanner
  (no-op quando não há stream de scanner ativo — não afeta CNH/CRLV/
  Expedição).
- Removida a chamada a `habilitarLeitorScanner()`/`onLeituraScanner()`
  (lógica de leitor HID) SOMENTE na tela `rec_digitaliza`; as 4 telas de
  CNH/CRLV continuam usando essa função normalmente.
- Miniaturas passaram a exibir a imagem real capturada.
- Chamada de `salvar-etapa` (`digitalizacao_notas`) adicionada dentro de
  `iniciarRecebimento(false)`, antes de navegar para `rec_digitaliza`.
- `chave: null` mantido fixo no payload (sem substituto de leitura HID).

### Verificações executadas
- `php -l` sem erros em todos os arquivos PHP alterados.
- `node --check public/totem/assets/app.js` sem erros.
- Revisão de segurança (`security-especialista`): confirmou correção do
  IDOR crítico, validação de magic bytes/tamanho antes da gravação,
  validação de `ordem` antes de compor nome de arquivo (sem path
  traversal), constraint `UNIQUE` + migration não-destrutiva. Identificou 1
  achado de atenção (mensagem de exceção vazando ao cliente), corrigido
  nesta mesma etapa. Manteve como pendência (já sinalizada no plano) a
  ausência de handler global de exceção para falhas de banco.
- Verificação independente (`explorer`): confirmou por leitura direta do
  código (projeto não é repositório git, sem `git diff` disponível) que
  nenhuma tela/função de Expedição, CNH ou CRLV foi alterada; que
  `pararCamera()` estendida é segura (no-op fora da tela de digitalização);
  que a captura compartilhada de CNH/CRLV gera JPEG (`canvas.toDataURL
  ('image/jpeg')`), compatível com o novo hardening de `UploadHelper`; que
  `OrdemColetaClient.php`, `TalentClient.php`, `ProdespClient.php` não
  foram tocados.

### Limitações conhecidas / pendências (ver também seção 5 de
`ia_development_state.md`)
- Compatibilidade física do Netum SD-2000 como `videoinput` NÃO foi
  validada — não há hardware disponível neste ambiente de desenvolvimento.
  O scanner NÃO deve ser considerado fisicamente validado até o teste no
  equipamento real (roteiro em `docs/deploy-checklist.md` e no roteiro de
  QA planejado na etapa 00).
- Origem da chave de acesso da NF-e na tela de digitalização continua
  indefinida (`chave: null` fixo) — não há substituto de leitura decidido
  para a remoção do leitor HID nessa tela.
- Próximo valor de `etapa_atual` após "Finalizar digitalização" não foi
  implementado (navegação atual não depende disso, sem regressão, mas a
  etapa formal não fica registrada no banco nesse ponto).
- Handler global de exceção para falha de banco no fluxo de notas continua
  ausente (achado médio já sinalizado no plano 00, não bloqueante).

### Confirmação de escopo
A Expedição NÃO foi alterada: consulta de ordens de coleta, seleção de
ordem, telas de dados da Expedição, CNH, CRLV, Talent, Prodesp e contratos
de APIs externas permanecem exatamente como estavam. Confirmado de forma
independente pelo `explorer` por leitura direta do código (sem histórico
git disponível para diff formal).

### Próximo passo
Rodar `/02-testes`.

## Resultado da correção pós-revisão (continuação da etapa 01-implementacao)

Data: 2026-09-03
Etapa: 01-implementacao (correção de pendências encontradas na revisão)

### Correções realizadas

**1. Finalização da digitalização (backend + frontend)**
- Nova ação `POST atendimento.php?acao=concluir-digitalizacao`.
- Payload de entrada: `{ id_atendimento: <int> }`.
- Resposta de sucesso: `{ proxima_tela: 'rec_cnh' | 'rec_cliente', etapa: 'cnh' | 'cliente' }`.
- Valida, antes de qualquer escrita: posse do atendimento pelo totem autenticado,
  tipo `recebimento`, status `em_andamento`, etapa atual `digitalizacao_notas`,
  pelo menos 1 nota salva, no máximo 5 notas. Consulta `AtendimentoNotaDao::algumaIdentificada`
  (sem depender de parâmetro do cliente) para decidir `cnh` (identificado) ou
  `cliente` (não identificado). Atualiza `etapa_atual` só depois de todas as
  validações passarem.
- `public/totem/assets/app.js`: `finalizarDigitalizacao()` agora é assíncrona,
  chama essa ação, e só navega (`ir(dados.proxima_tela)`) em caso de sucesso.
  Em erro: permanece em `rec_digitaliza`, preserva `state.notaOrdem`/`state.notasImagens`,
  mostra mensagem clara, reabilita o botão. Proteção contra clique duplo via
  nova flag `state.finalizandoDigitalizacao` (independente da flag de captura
  de nota).

**2. IDOR corrigido em `salvar-etapa`, `bloquear-excesso-notas` e `cancelar`**
- `public/api/atendimento.php` passou a repassar `(int) $totem['id_totem']`
  para essas três ações e para a nova `concluir-digitalizacao`.
- `AtendimentoController::salvarEtapa` (case `digitalizacao_notas`): valida
  posse, tipo `recebimento`, status `em_andamento`, e que a etapa ANTERIOR
  seja `placa` (única etapa válida antes da transição, confirmada por leitura
  de `AtendimentoDao::criar`). Os cases `confirmacao`/`cliente`/`ajudante`
  (usados por Expedição e demais telas do Recebimento) permanecem
  intencionalmente SEM essa validação extra, para não alterar comportamento
  de Expedição.
- `AtendimentoController::bloquearPorExcessoDeNotas`: valida posse e tipo
  `recebimento` antes de bloquear.
- `AtendimentoController::cancelar`: valida só posse (sem restrição de tipo/
  status, para continuar funcionando em qualquer tela/tipo, conforme decisão
  já registrada na seção 4 do estado do projeto).

**3. Validação de JPEG reforçada**
- `UploadHelper::salvarImagemBase64` complementada com `getimagesizefromstring()`
  (nativo do PHP, sem dependência nova), confirmando que o conteúdo é
  interpretável como imagem, que o tipo é `IMAGETYPE_JPEG`, e que largura/altura
  são maiores que zero. Ordem de validação preservada (prefixo → base64 →
  tamanho → magic bytes → `getimagesizefromstring` → grava em disco).

**4. Migration — revisão estática (não executada)**
- `sql/migrations/001_uk_atendimento_nota_ordem.sql` confirmada como
  não-destrutiva (nenhum `DELETE`/`UPDATE` automático), com diagnóstico de
  duplicidade antes do `ALTER TABLE`. Observação: o arquivo não é
  idempotente por si só em SQL puro (não há `IF` condicional real) — depende
  do operador consultar o PASSO 2 manualmente antes de repetir o PASSO 3; se
  executado uma segunda vez sem essa checagem, falha com erro de chave
  duplicada (não destrutivo, só um erro de SQL). **Não foi executada contra
  nenhum banco** — não há MariaDB/MySQL disponível neste ambiente de
  desenvolvimento.

### Verificações executadas
- `php -l` sem erros em todos os arquivos PHP alterados.
- `node --check public/totem/assets/app.js` sem erros.
- Busca por chamadas antigas de finalização só por estado local: confirmado
  que `finalizarDigitalizacao()` não usa mais `state.clienteIdentificado`
  para decidir a navegação.
- Busca por ações que recebem `id_atendimento` sem comparar `id_totem`:
  confirmado que `salvar-etapa`, `bloquear-excesso-notas`, `cancelar` e
  `concluir-digitalizacao` agora comparam; `selecionar-ordem` e `finalizar`
  continuam sem essa comparação (ver pendências).
- Revisão de segurança (`security-especialista`): confirmou todas as
  correções como implementadas corretamente (validações antes de qualquer
  escrita, mensagens genéricas sem vazamento). Identificou 2 achados de
  atenção fora do escopo desta rodada (IDOR remanescente em `selecionarOrdem`
  e `finalizar`) e 1 achado de severidade baixa (ausência de lock contra
  corrida em `concluirDigitalizacao`).
- Verificação independente (`explorer`): confirmou por leitura direta do
  código que Expedição, CNH, CRLV, `iniciar`, `selecionarOrdem` e `finalizar`
  permanecem exatamente como estavam (só ganharam o que já era esperado, sem
  nada a mais); confirmou que a nova checagem de JPEG não rejeita imagens
  legítimas geradas por `canvas.toDataURL('image/jpeg')`.

### Limitações conhecidas / pendências (atualizadas na seção 5 de `ia_development_state.md`)
- **IDOR em `selecionarOrdem` e `finalizar` (envio ao Talent) — NÃO corrigido
  nesta rodada**, por decisão explícita de escopo do usuário. O
  `security-especialista` avalia o risco de `finalizar` como não-trivial
  (dispara efeito colateral em sistema externo para atendimento de outro
  totem) e recomenda priorizar essa correção antes de considerar o fluxo
  "seguro para produção". Fica registrado para decisão do usuário sobre
  quando corrigir.
- Ausência de lock/transação em `concluirDigitalizacao` contra cliques quase
  simultâneos — severidade baixa, não bloqueante, não corrigido.
- Retomada de atendimento ao recarregar a página (contador de notas em
  `state` não sincroniza com o banco) — limitação pré-existente do projeto,
  confirmada mas não criada/alterada nesta demanda (fora de escopo, conforme
  instrução explícita de não construir um sistema novo).
- Compatibilidade física do Netum SD-2000 continua não validada (sem
  hardware disponível).
- Origem da chave de acesso da NF-e na tela de digitalização continua
  indefinida (`chave: null` fixo).
- Handler global de exceção de banco continua ausente (achado médio já
  sinalizado, não bloqueante).

### Confirmação de escopo
A Expedição NÃO foi alterada nesta correção: `iniciar`, `selecionarOrdem`
(inclusive seu IDOR pré-existente, deixado como estava por decisão de
escopo), CNH, CRLV, Talent (`finalizar`, inclusive seu IDOR pré-existente),
Prodesp e contratos de APIs externas permanecem exatamente como estavam.
Confirmado de forma independente pelo `explorer`.

### Próximo passo
Rodar `/02-testes`.

## Resultado da etapa /02-testes

Data: 2026-09-03
Etapa: 02-testes

### Veredito: INCONCLUSIVO

Justificativa: o teste físico do Netum SD-2000 (20 itens) não foi executado
por ausência de hardware neste ambiente — os próprios critérios definidos
pelo usuário exigem hardware validado para `APROVADO` ou `APROVADO COM
RESSALVAS`. Nenhum dos achados reais de teste (migration, transições,
upload, IDOR) configurou falha de comportamento implementado ou
vulnerabilidade de autorização nova dentro do escopo desta correção que
justificasse `REPROVADO` — o IDOR remanescente em `selecionarOrdem`/`finalizar`
já era conhecido e estava fora de escopo; os achados de reversão de estado
`concluido` em `bloquear-excesso-notas`/`cancelar` são lacunas de design
não cobertas pela decisão de produto documentada, não quebras da proteção
contra outro totem (essa continua íntegra em ambos).

### Ambiente de teste usado
XAMPP local (Apache + PHP 8.0.30, MariaDB 10.4.32), banco `udlog_totem`,
servido em `http://localhost/totem-udlog/public/`. NÃO é produção
(produção é Hostgator). Testes reais via curl/SQL, não apenas estáticos.

### Testes executados
- Estático: `php -l` em 23 arquivos PHP (todos OK), `node --check` em
  `app.js` (OK), greps de não-regressão.
- Migration: 4 cenários reais em bancos MariaDB isolados (banco vazio,
  execução repetida, duplicidade real inserida manualmente, compatibilidade
  de sintaxe) — todos passaram, migration aplicada com sucesso depois no
  banco de trabalho.
- Transições de estado: 12 cenários reais via curl (todos passaram).
- Upload/validação de imagem: 18 cenários reais via curl (17 sem achado, 1
  achado médio).
- IDOR/autenticação: 6 ações × múltiplas condições, com 2 totens de teste
  reais (todas as ações do escopo confirmadas protegidas).
- Frontend: 20 cenários por trace de código (19 tratados, 1 achado baixo).

### Testes aprovados
62 de 63 cenários funcionais/estáticos executados passaram sem achado.

### Achados (não corrigidos nesta etapa)
| Severidade | Achado | Componente |
|---|---|---|
| Médio | JPEG truncado (sem marcador de fim) é aceito | `util/UploadHelper.php` |
| Atenção | `bloquear-excesso-notas` reverte atendimento `concluido` para `bloqueado` | `app/Controller/AtendimentoController.php` |
| Atenção | `cancelar` reverte atendimento `concluido` para `cancelado` (mesmo com Talent já enviado) | `app/Controller/AtendimentoController.php` |
| Atenção (já conhecido, confirmado por exploração real) | IDOR em `selecionarOrdem` e `finalizar` | `app/Controller/AtendimentoController.php` |
| Baixo | `ordem=0` rejeitado com mensagem "Dados incompletos" em vez de "Ordem da nota invalida" | `app/Controller/NotaController.php` |
| Baixo | Comentário da migration diz `indice_ja_existe=1`, valor real observado é `2` (lógica continua correta) | `sql/migrations/001_uk_atendimento_nota_ordem.sql` |
| Observação | `nota.php?acao=status` não valida tipo/status/etapa (só posse) | `app/Controller/NotaController.php` |
| Baixo | Clique duplo em "Capturar nota" sem flag explícita (mitigado na prática) | `public/totem/assets/app.js` |

### Testes não executados
- Teste físico do Netum SD-2000 (20 itens — ver roteiro abaixo).
- Falha de gravação em disco (risco de afetar ambiente compartilhado).
- Falha de INSERT pós-gravação do arquivo (exigiria instrumentação).
- Ramo `cnh` de `concluir-digitalizacao` (não exercitado, sem dado de teste
  com cliente identificado — não é falha).

### Evidências
Migration testada em bancos MariaDB isolados reais, depois aplicada no
banco de trabalho. Upload testado com arquivos JPEG/PNG reais gerados e
removidos ao final. IDOR testado com 2 totens autenticados reais, incluindo
exploração ativa confirmando os achados. Todos os dados de teste foram
limpos pelos sub-agentes ao final de suas rodadas.

### Limitações externas ao escopo
Hardware físico Netum SD-2000 indisponível neste ambiente de
desenvolvimento. `docs/manual_talent.md`/`docs/db_gestao_coletas.md`
continuam placeholder — fora do escopo desta demanda.

### Roteiro físico do Netum SD-2000 — INCONCLUSIVO — AGUARDANDO VALIDAÇÃO FÍSICA DO NETUM

Roteiro pronto para execução manual pelo usuário, um passo por vez, no mini
PC Windows do totem com o scanner conectado:

1. Conectar o Netum SD-2000 via USB e confirmar no Gerenciador de
   Dispositivos do Windows que é reconhecido com driver de classe UVC
   padrão (não proprietário).
2. Abrir o Chromium do kiosk na tela `rec_digitaliza` e verificar se
   solicita permissão de câmera; conceder e confirmar que persiste após
   reiniciar o kiosk.
3. Verificar em `enumerateDevices()` (DevTools ou log da aplicação) se o
   Netum aparece como `videoinput`, anotando o `label` exato retornado.
4. Confirmar que o preview de vídeo aparece sem tela preta.
5. Se houver mais de uma câmera, confirmar que o modal de seleção manual
   aparece e que a escolha é salva em `localStorage`.
6. Se houver só uma câmera, confirmar seleção automática sem modal.
7. Posicionar uma nota fiscal física sob o scanner e verificar a guia
   visual de posicionamento sobre a imagem ao vivo.
8. Clicar em "Capturar nota" e confirmar que o preview congelado mostra a
   imagem real, com botões "Usar imagem"/"Refazer" visíveis.
9. Clicar em "Refazer" e confirmar que volta ao vídeo ao vivo sem nenhuma
   chamada de rede.
10. Capturar novamente e clicar em "Usar imagem"; confirmar que a
    miniatura mostra a imagem real capturada.
11. Repetir até 5 notas e confirmar que o contador incrementa a cada envio
    bem-sucedido.
12. Tentar capturar uma 6ª nota e confirmar bloqueio com mensagem clara.
13. Clicar em "Finalizar digitalização" com pelo menos 1 nota e confirmar
    navegação correta para `rec_cnh` ou `rec_cliente`.
14. Clicar em "Cancelar atendimento" durante a digitalização e confirmar
    que a câmera do Netum é encerrada.
15. Desconectar o cabo USB durante uma sessão ativa e confirmar que a
    aplicação detecta e exibe mensagem de erro apropriada, sem travar.
16. Reconectar o Netum e verificar se é possível retomar sem reiniciar o
    kiosk.
17. Se o NetumScan Pro estiver instalado, verificar que não inicia
    automaticamente com o Windows nem compete pelo dispositivo.
18. Com o NetumScan Pro aberto simultaneamente, tentar iniciar a câmera no
    kiosk e confirmar o comportamento (erro "scanner ocupado" esperado).
19. Testar diferentes resoluções (se configurável) e confirmar que a nota
    não é cortada com `object-fit: contain`.
20. Repetir os passos 1-13 em um segundo mini PC/totem (se disponível)
    para confirmar reprodutibilidade antes de considerar validado em
    produção.

### Próximo passo
Aguardando execução do roteiro físico acima pelo usuário. Enquanto isso não
ocorrer, o veredito permanece `INCONCLUSIVO`. Recomenda-se também decidir
sobre os achados de "Atenção" (`bloquear-excesso-notas`/`cancelar` e IDOR
remanescente) antes de `/03-revisao`.

## Resultado do teste físico do Netum SD-2000 (execução real, 2026-09-04)

Executado com o usuário no hardware real (mini PC Windows), passo a passo,
conforme roteiro da etapa /02-testes.

| Passo | Item | Resultado |
|---|---|---|
| 1 | Netum reconhecido pelo Windows | **OK** — aparece como `NETUM Camera`, classe `Camera`, driver UVC padrão (não HID). Confirma a correção de premissa desta demanda. |
| 2 | Permissão de câmera no Chromium/navegador | **OK** — solicitada e concedida, preview abriu |
| 3 | Seletor de múltiplas câmeras | **OK** — apareceu corretamente com os labels reais: `Logi USB Camera` e `NETUM Camera` |
| 4 | Seleção do Netum salva e preview nítido | **OK** — preview nítido, sem tela preta |
| 5 | Documento inteiro visível, sem corte | **FALHOU** — imagem do documento aparece cortada |
| 6 | Resolução suficiente para leitura do texto | **FALHOU** — resolução de captura insuficiente |

### Veredito da etapa /02-testes: REPROVADO neste ponto

Causa raiz identificada por leitura de código (antes de corrigir):
- `abrirStreamScanner(deviceId)` (app.js) chama `getUserMedia({ video: {
  deviceId: { exact: deviceId } } })` **sem nenhuma constraint de
  resolução** — o navegador negocia um modo padrão (tipicamente baixo) com
  o driver da câmera, que pode inclusive ser um recorte central do sensor
  em vez de uma escala do frame completo.
- A captura de imagem (`capturarFotoBase64`, compartilhada com CNH/CRLV)
  força um downscale fixo para 900px de largura, independente da
  resolução real da fonte — perda adicional de nitidez.

Retornado para `/01-implementacao` para correção pontual e restrita à
tela `rec_digitaliza` (ver instruções detalhadas na próxima seção do
handoff, a preencher pela implementação).

### Ambiente de teste físico
Mini PC Windows do usuário, Netum SD-2000 conectado via USB, testado via
`http://localhost:8080/totem/index.php?totem=RECEPCAO-01` — VirtualHost
Apache local criado especificamente para viabilizar este teste (document
root em `public/`), documentado aqui para rastreabilidade. Essa mesma
configuração (document root em `public/`) precisa ser replicada na
implantação real do kiosk (pendência já registrada em
`docs/deploy-checklist.md`).

## Resultado da correção — corte e resolução do scanner (retorno de /02-testes)

Data: 2026-09-04
Etapa: 01-implementacao (correção pontual, escopo restrito a `rec_digitaliza`)

### Causa raiz confirmada
`abrirStreamScanner(deviceId)` chamava `getUserMedia` só com `deviceId`,
sem nenhuma constraint de resolução — o driver da câmera negociava um modo
padrão (recorte de baixa resolução) em vez do frame completo. A captura
também reaproveitava `capturarFotoBase64`, compartilhada com CNH/CRLV, que
força downscale fixo para 900px de largura.

### Correção implementada (`public/totem/assets/app.js`, `app.css`)
- `getUserMedia` do scanner agora solicita `width: {ideal: 4096}, height:
  {ideal: 3072}` junto do `deviceId` — o navegador/driver entrega o máximo
  que o hardware suportar (constraint `ideal` nunca falha por si só).
- Log de diagnóstico no console: dispositivo, resolução solicitada,
  resolução entregue (`track.getSettings()`), capabilities máximas
  (`track.getCapabilities()`, com fallback se indisponível).
- Nova função `ajustarProporcaoScanner()`: ajusta a proporção do container/
  preview/guia visual dinamicamente pela proporção REAL do vídeo (via
  `style.aspectRatio`), substituindo o `aspect-ratio: 3/4` fixo (que agora é
  só fallback antes do stream conectar).
- Nova função dedicada `capturarFotoScannerNota()` (separada de
  `capturarFotoBase64`, que continua intocada para CNH/CRLV): usa
  `video.videoWidth`/`video.videoHeight` diretamente (sem downscale fixo),
  preserva a proporção original, e comprime iterativamente a qualidade JPEG
  (0.92 → 0.85 → 0.75 → 0.65 → 0.5 → 0.4) até caber no limite estimado de
  4.5MB no front-end (margem de segurança abaixo do `NOTA_IMAGEM_MAX_BYTES`
  padrão de 5MB do backend); só reduz resolução como último recurso.
- `capturarPreviaNota()` passou a usar a nova função dedicada.

### Escopo respeitado
Não alterado: Expedição, CNH/CRLV (`capturarFotoBase64`/`capturarDocumento`
originais intocados), nenhum arquivo PHP, nenhum contrato de API externa.

### Verificação executada
`node --check app.js`: sem erros. Trace de código confirmou isolamento
entre a função original (CNH/CRLV) e a nova função dedicada (scanner de
notas), e que o fallback de `getCapabilities()` indisponível não bloqueia o
fluxo.

### Limitação conhecida
O limite de 4.5MB no front-end é uma estimativa client-side (base64 × 0.75)
sem sincronização automática com `.env` `NOTA_IMAGEM_MAX_BYTES` — se esse
valor mudar no backend, o limite do front-end precisa ser atualizado
manualmente. Registrado como pendência.

### Validação física
**Ainda não confirmada** — depende de novo teste físico do usuário no
Netum SD-2000 real, retomando exatamente do passo que falhou (preview/
captura sem corte, com resolução legível).

### Próximo passo
Retornar para `/02-testes`, exatamente no passo físico que reprovou.

### Correção adicional — guia visual dessincronizada do resize da câmera

Data: 2026-09-04
Etapa: 01-implementacao (correção pontual, escopo restrito a
`abrirStreamScanner`/`pararCameraScanner` em `app.js`)

**Causa raiz confirmada por evidência real** (medição de pixels de
screenshot + `getimagesize` de arquivos reais): a captura final (canvas →
JPEG salvo) já estava correta, sem corte de software — canvas, imagem
capturada e JPEG salvo em `storage/` idênticos em resolução (3264×2448,
4:3, batendo com a resolução real do Netum SD-2000). O problema era só a
guia visual/preview na tela, com proporção errada (medida em 1,78 ≈ 16:9
no screenshot, quando deveria ser 1,33 ≈ 4:3).

`ajustarProporcaoScanner(video.videoWidth, video.videoHeight)` era chamada
só uma vez, dentro de `video.onloadedmetadata`. Câmeras UVC frequentemente
entregam primeiro um modo de resolução inicial mais baixa (disparando
`loadedmetadata` com essas dimensões — 16:9 nesse caso) e só depois
renegociam para a resolução final pedida via `ideal` (3264×2448, 4:3),
disparando um evento `resize` no `<video>` que o código não escutava — por
isso a guia ficava presa na proporção inicial errada, mesmo a captura final
saindo correta (que lê `videoWidth`/`videoHeight` "ao vivo" no momento do
clique, não em cache).

**Correção aplicada**: adicionado um listener do evento `resize` no
elemento `video`, dentro de `abrirStreamScanner(deviceId)`, ao lado do
`onloadedmetadata` (mantido intocado). O handler reexecuta
`ajustarProporcaoScanner(video.videoWidth, video.videoHeight)` e
`atualizarBotaoCaptura()`, com guarda contra dimensões zeradas. A
referência do elemento `video` e da função handler são guardadas em novas
variáveis de escopo de módulo (`scannerVideoElementoAtual`,
`scannerResizeHandlerAtual`) e o listener é removido em
`pararCameraScanner()`, evitando duplicidade em múltiplas
aberturas/fechamentos da tela `rec_digitaliza` na mesma sessão do
navegador.

Nenhuma alteração em canvas, resolução solicitada
(`SCANNER_RESOLUCAO_IDEAL_LARGURA`/`ALTURA`), `capturarFotoScannerNota`,
backend, CNH, CRLV ou Expedição.

**Verificação**: `node --check public/totem/assets/app.js` sem erros.
Confirmado por leitura de código que o listener de `resize` é removido
corretamente em `pararCameraScanner()`.

**Validação física ainda pendente** — depende de novo teste do usuário no
Netum SD-2000 real, confirmando visualmente que a guia/preview passa a
bater com a proporção 4:3 real do documento.

### Correção adicional — polling ativo substitui dependência do evento resize

Data: 2026-09-04
Etapa: 01-implementacao (correção pontual, escopo restrito a
`abrirStreamScanner`/`pararCameraScanner` em `app.js`)

**Por que o `resize` sozinho não foi suficiente** — evidência real de 2
testes físicos consecutivos do usuário (inclusive em aba anônima,
descartando cache): a proporção da guia/preview permaneceu EXATAMENTE
igual (1,78, medida em pixels) antes e depois da correção do listener de
`resize`, em 3 medições diferentes. O evento `resize` do
`HTMLVideoElement` é conhecido por ser inconsistente para streams ao vivo
(`srcObject`/`MediaStream`) em várias versões de Chromium — não dispara de
forma confiável nesse cenário, mesmo a captura final continuando correta
(porque `capturarFotoScannerNota` lê `video.videoWidth`/`videoHeight` "ao
vivo" no momento do clique, e não depende de nenhum evento).

**Correção aplicada**: monitoramento ativo (polling) via `setInterval` a
cada 250ms, das dimensões reais de `videoScanner`, enquanto o scanner
estiver ativo — independente do `onloadedmetadata`/`resize` (mantidos
intocados, continuam como mecanismos auxiliares).

- Novas variáveis de escopo de módulo: `scannerPollingIntervalId`,
  `scannerUltimaLarguraAplicada`, `scannerUltimaAlturaAplicada` (todas
  inicializadas como `null`).
- Em `abrirStreamScanner(deviceId)`: qualquer polling anterior é limpo
  (`clearInterval` + zera a variável) logo no início da função, antes de
  abrir um novo stream — evita interval órfão caso a função seja chamada
  de novo sem passar por `pararCameraScanner` (ex.: troca manual de
  dispositivo via `abrirSelecaoScanner`). Logo após `video.srcObject =
  stream`, as variáveis de última dimensão aplicada são zeradas e o
  `setInterval` de 250ms é iniciado: a cada execução, ignora
  silenciosamente se `videoWidth`/`videoHeight` estiverem zerados/ausentes
  (evita erro/`aspect-ratio` inválido em momentos de transição), e só
  chama `ajustarProporcaoScanner()` + `atualizarBotaoCaptura()` quando as
  dimensões atuais diferem das últimas efetivamente aplicadas — evitando
  chamadas redundantes a cada ciclo quando nada mudou.
- Em `pararCameraScanner()`: `clearInterval(scannerPollingIntervalId)`
  (com guarda) e zera as três variáveis novas, ao lado da limpeza já
  existente do listener de `resize`.
- Confirmado por leitura de código que não há outro caminho (ex.: `catch`
  de erro em `abrirStreamScanner`, chamada repetida antes do
  `getUserMedia` resolver) que possa criar um `setInterval` sem antes
  limpar o anterior — o `setInterval` só é criado depois do `await
  getUserMedia` resolver com sucesso, e a limpeza acontece antes disso, no
  topo da função.

Nenhuma alteração em `capturarFotoScannerNota`,
`SCANNER_RESOLUCAO_IDEAL_LARGURA`/`ALTURA`, canvas, backend, CNH, CRLV ou
Expedição. `onloadedmetadata` e o listener de `resize` permanecem
exatamente como estavam.

**Verificação**: `node --check public/totem/assets/app.js` sem erros.
Confirmado por leitura de código que o polling é sempre limpo antes de um
novo iniciar, que a comparação "mudou desde a última vez" está correta, e
que dimensões momentaneamente zeradas durante o polling são ignoradas sem
erro.

**Validação física ainda pendente** — depende de novo teste físico do
usuário no Netum SD-2000 real, confirmando visualmente que a guia/preview
passa a acompanhar a proporção real do vídeo mesmo sem o evento `resize`
disparar.

### Correção adicional — flex-shrink:0 impede compressão da caixa pelo flexbox

Data: 2026-09-04
Etapa: 01-implementacao (correção pontual, escopo restrito a
`.caixa-scanner`/`.previa-nota` em `app.css`)

**Causa raiz confirmada por evidência real** (leitura de
`document.getElementById('caixaScanner').style.height` no navegador real):
o JavaScript já calculava e escrevia corretamente `style.height = "270px"`
em `#caixaScanner` (valor exato esperado para 360px de largura × proporção
3264×2448), mas o elemento continuava sendo renderizado em ~352px de
altura (proporção ~1,79, não 4:3). `.caixa-scanner`/`.previa-nota` são
filhos de `.tela`, que é `display:flex; flex-direction:column;
justify-content:center;`. Itens flex têm `flex-shrink:1` por padrão — o
algoritmo de flexbox comprime a altura do elemento abaixo do `style.height`
definido quando o conteúdo total da tela (título + caixa + status +
botões) excede o espaço vertical disponível.

**Correção aplicada**: `flex-shrink: 0;` adicionado diretamente dentro das
regras já existentes de `.caixa-scanner` (linha ~104) e `.previa-nota`
(linha ~107) em `app.css`. Nenhuma outra propriedade dessas duas classes
foi alterada (`width:100%; max-width:360px;` mantidos intocados em ambas).
Nenhuma linha de `app.js` foi tocada.

Confirmado por leitura completa do arquivo que nenhuma regra CSS
posterior a essas duas redefine `.caixa-scanner` ou `.previa-nota`.

**Verificações executadas**:
- `node --check public/totem/assets/app.js`: sem erros (arquivo não
  alterado, rodado por precaução).
- Releitura do CSS resultante: `.caixa-scanner` e `.previa-nota` mantêm
  exatamente suas propriedades anteriores, com `flex-shrink:0` adicionado.
- Confirmado que `.tela` já tem `overflow-y: auto` (linha 27) — se o
  conteúdo total não couber na viewport com a caixa impedida de encolher,
  o comportamento esperado é aparecer scroll em vez de cortar/esconder
  botões (não é um estouro sem tratamento).
- Confirmado por leitura que `capturarFotoScannerNota` (linhas 828-864 de
  `app.js`) usa `video.videoWidth`/`video.videoHeight` diretamente, sem
  qualquer dependência do tamanho visual do container — não é afetada por
  esta mudança de CSS, e não foi tocada.

**Risco identificado para observação no teste físico**: ao impedir o
encolhimento da caixa, é possível que o conteúdo total da tela passe a
exceder a altura da viewport, empurrando os botões para fora da área
visível inicial ou disparando o scroll do `.tela`. Isso é esperado ser
mitigado pelo `overflow-y:auto` já existente, mas só é confirmável no
hardware real.

**Validação visual real ainda pendente** — não confirmável sem navegador/
hardware neste ambiente. Critérios a conferir no próximo teste físico:
- `.caixa-scanner` renderizada em aproximadamente 360×270px (proporção
  4:3).
- `.previa-nota` sem compressão (mesmo `style.height` calculado).
- Nota inteira visível, sem corte.
- Botões e mensagens de status ainda visíveis na tela (ou acessíveis via
  scroll, se o conteúdo não couber por completo).
- Ausência de overflow indesejado.
- Funcionamento correto na orientação vertical (retrato) do totem.
- Preview e botões sem lentidão perceptível (não deveria ser afetado —
  mudança é só CSS estático, sem lógica nova de JavaScript).
- Captura mantida em 3264×2448 (não afetada — `capturarFotoScannerNota`
  não foi tocada e não depende do tamanho visual do container).

### Correção adicional — eliminação do flash vertical inicial

Data: 2026-09-04
Etapa: 01-implementacao (correção pontual, escopo restrito à tela
`rec_digitaliza` em `app.js`/`app.css`)

**Comportamento anterior** (confirmado pelo usuário em teste físico): ao
entrar na tela `rec_digitaliza`, a caixa do scanner aparecia
BREVEMENTE na proporção vertical (fallback CSS `aspect-ratio: 3/4`, mais
alto que largo) antes de "virar" para a proporção horizontal correta
(4:3, calculada via JS assim que a resolução real do vídeo era
conhecida). O flash era instantâneo, ocorria em toda abertura, e depois
de virar para horizontal a caixa ficava estável (sem oscilação
posterior) — não era um bug funcional grave, mas precisava ser eliminado
por UX.

**Correção aplicada**:
- `app.css`: fallback de `.caixa-scanner` trocado de `aspect-ratio: 3/4`
  (vertical) para `aspect-ratio: 4/3` (horizontal, a proporção real
  entregue pelo Netum SD-2000) — já nasce aproximadamente na orientação
  correta antes mesmo do primeiro cálculo em JS rodar, eliminando a
  maior parte do flash de inversão de orientação. Comentário do CSS
  atualizado para refletir a nova escolha e documentar o novo
  comportamento de `visibility` do `<video>`.
- `app.js`: `<video id="videoScanner">` passa a ficar com
  `visibility: hidden` (aplicado via JS, logo após `video.srcObject =
  stream` em `abrirStreamScanner`) até a altura real do container ser
  aplicada — evita mostrar um frame sem layout correto (sem tocar no
  HTML gerado por `telaDigitaliza()`, que permanece igual).
- Novo ponto único de entrada, `revelarVideoScannerQuandoPronto()`: (a)
  chama `aplicarAlturaScanner()` de forma síncrona para aplicar a altura
  real já calculada; (b) agenda um `requestAnimationFrame` (garantindo
  que o navegador já processou o novo `style.height` antes do próximo
  passo); dentro desse frame — (c) torna o vídeo visível
  (`visibility: visible`) e (d) só então muda o status para "Scanner
  pronto". Tudo isso guardado por uma flag de módulo
  (`scannerVideoRevelado`), garantindo que a sequência completa
  (mostrar vídeo + mudar texto) só rode uma única vez por sessão de
  stream — chamadas subsequentes do polling (250ms) ou do listener de
  `resize` (que recalculam a proporção quando a câmera renegocia
  resolução) passam a chamar essa mesma função, mas ela retorna
  imediatamente (no-op) se já revelado, evitando qualquer "piscar" do
  vídeo quando a resolução mudar de novo depois.
- `video.onloadedmetadata` reorganizado: antes mudava o status para
  "Scanner pronto" imediatamente e chamava `ajustarProporcaoScanner()`
  em paralelo/sem ordem garantida com a altura; agora chama
  `ajustarProporcaoScanner()` e depois `revelarVideoScannerQuandoPronto()`
  — a mudança de status só acontece DEPOIS que o vídeo é exibido,
  seguindo a sequência a→b→c→d.
- Cancelamento/reset seguro em 20 aberturas: nova variável
  `scannerRevelarFrameId` (id do `requestAnimationFrame` pendente da
  revelação) é cancelada e zerada tanto no início de `abrirStreamScanner`
  (junto com o reset de `scannerVideoRevelado = false` e ocultação do
  vídeo) quanto em `pararCameraScanner()` — evita que um callback tardio
  de uma sessão de stream anterior interfira em uma sessão nova aberta
  logo em seguida (janela de corrida teórica teoricamente possível numa
  troca de tela muito rápida, dentro de um mesmo frame de renderização).
  `scannerVideoRevelado` também é resetado em `pararCameraScanner()`.
- Nenhum `setTimeout`/atraso artificial usado — só
  `requestAnimationFrame`, encadeado a partir da infraestrutura já
  existente. Nenhum novo `setInterval`/`ResizeObserver`/listener criado —
  reaproveitado o polling (`scannerPollingIntervalId`), o
  `ResizeObserver` (`scannerResizeObserver`) e o listener de `resize`
  já existentes, só ajustando a ORDEM de quando o vídeo fica visível e
  o status muda. Nenhuma `transition` CSS adicionada em
  `.caixa-scanner`/`.previa-nota` (troca de altura continua instantânea).
- Preservado: guia visual (`.guia-scanner`), resolução de captura
  (3264×2448, `capturarFotoScannerNota`/
  `SCANNER_RESOLUCAO_IDEAL_LARGURA`/`ALTURA` intocados), todos os
  controles (botão "Capturar nota", "Finalizar digitalização",
  miniaturas).

**Verificações executadas**:
- `node --check public/totem/assets/app.js`: sem erros (executado após
  cada etapa de edição).
- Trace manual de 20 ciclos consecutivos de `abrirStreamScanner()` →
  `pararCameraScanner()`: confirmado que `scannerVideoRevelado` é
  sempre resetado para `false` tanto no início de `abrirStreamScanner`
  quanto em `pararCameraScanner`, e que `scannerRevelarFrameId` é
  sempre cancelado (`cancelAnimationFrame`) e zerado nos mesmos dois
  pontos — nenhuma abertura subsequente herda o estado "já revelado" de
  uma sessão anterior; cada abertura sempre começa com o vídeo oculto
  (`visibility: hidden` reaplicado) e só revela depois do cálculo de
  altura real.
- Confirmado por leitura de código: nenhum caminho chama `mostrarStatusScanner('Scanner
  pronto')` fora de dentro do callback de `revelarVideoScannerQuandoPronto()` nesta
  tela (a chamada antiga direta em `onloadedmetadata` foi removida);
  `capturarFotoScannerNota`, `SCANNER_RESOLUCAO_IDEAL_LARGURA`/`ALTURA`
  e o canvas de captura não foram tocados; nenhum `setTimeout` foi
  adicionado; nenhum novo `setInterval`/`ResizeObserver`/listener foi
  criado (só reaproveitados os já existentes); nenhuma `transition` CSS
  foi adicionada.

**Limitação desta verificação**: testes de tempo real (20 aberturas em
navegador de verdade, percepção visual de fluidez, ausência real de
flash) NÃO puderam ser executados neste ambiente sem navegador/hardware
— a verificação acima é inteiramente por leitura/trace manual de
código. Fica para validação física do usuário no próximo teste no
Netum SD-2000 real.

**Validação física ainda pendente** — depende de novo teste físico do
usuário confirmando visualmente: ausência do flash vertical inicial
(caixa já nasce horizontal), vídeo só aparece depois do layout correto
(sem piscar preto/vazio antes), nenhuma oscilação/piscar do vídeo
quando a câmera renegociar resolução depois (polling/resize), e
nenhuma lentidão perceptível adicional na abertura da tela.

## Veredito final consolidado — /02-testes

Data: 2026-09-04
Etapa: 02-testes (encerramento)

### Veredito: APROVADO COM RESSALVAS

Justificativa: o problema que causou dois REPROVADOS consecutivos (corte da
imagem, resolução insuficiente, proporção incorreta do preview/guia) foi
identificado até a causa raiz real (ausência de constraint de resolução no
`getUserMedia`, downscale fixo de 900px na captura compartilhada com
CNH/CRLV, `aspect-ratio` CSS não respeitado dentro do container flex,
compressão por `flex-shrink` padrão, e flash de fallback vertical) e
corrigido em rodadas sucessivas, cada uma validada com evidência física real
no hardware do usuário — não por suposição. O hardware foi validado para a
funcionalidade central desta demanda. As pendências que restam são não
bloqueantes (cenários de borda ainda não exercitados fisicamente, e
pendências de segurança/produto já conhecidas e explicitamente fora do
escopo desta correção).

### Testes aprovados

**Backend (rodada anterior, via curl/SQL reais contra ambiente local — não
foi alterado nesta rodada de correções de frontend, permanece válido):**
- Migration: 4/4 cenários (banco vazio, execução repetida, duplicidade
  real, compatibilidade MariaDB 10.4.32).
- Transições de estado: 12/12 cenários.
- Upload/validação de imagem: 17/18 sem achado (1 achado médio: JPEG
  truncado aceito — não bloqueante).
- IDOR nas ações do escopo desta demanda (`salvar-etapa`
  digitalizacao_notas, `nota.php processar/status`,
  `concluir-digitalizacao`, `bloquear-excesso-notas`, `cancelar`):
  confirmado protegido por exploração real com 2 totens de teste.

**Estático:**
- `php -l` em todos os arquivos PHP do projeto: OK.
- `node --check public/totem/assets/app.js`: OK (verificado a cada
  correção desta rodada).
- Não regressão: Expedição, CNH, CRLV, "Mais de 5 notas", cancelamento,
  contratos externos — todos confirmados intocados.

**Frontend (trace de código):** 19/20 cenários de tratamento de erro/fluxo
corretamente implementados (permissão negada, scanner não encontrado,
ocupado, desconectado, seleção de dispositivo, limite de 5, clique duplo em
"Usar imagem"/"Finalizar", "Refazer" sem rede, erro de API preservando
preview, encerramento de tracks sem efeito colateral em CNH/CRLV).

**Físico (validado pelo usuário no Netum SD-2000 real, nesta e nas rodadas
anteriores):**
1. Netum reconhecido pelo Windows como `NETUM Camera`, classe Camera/UVC
   (não HID) — confirma a correção de premissa desta demanda.
2. Permissão de câmera solicitada e concedida.
3. Seletor de múltiplas câmeras funciona, com labels reais (`Logi USB
   Camera`, `NETUM Camera`).
4. Escolha do Netum persistida (`localStorage`).
5. Preview abre nítido, sem tela preta.
6. Resolução de captura: 3264×2448 (confirmado via log de diagnóstico e
   por medição direta do arquivo salvo em `storage/`).
7. Documento inteiro visível, sem corte de borda.
8. Guia/preview renderizados em proporção 4:3 correta (~360×270px,
   confirmado via `style.height` e medição de pixels).
9. Container abre diretamente em horizontal, sem flash vertical.
10. Vídeo só aparece com enquadramento correto (não pisca).
11. Estável em múltiplas entradas/saídas da tela (sem regressão de
    orientação).
12. Preview fluido, sem lentidão perceptível.
13. Texto da nota legível na imagem capturada.
14. Captura salva em `storage/` consistente com o que foi mostrado no
    preview (mesma resolução, mesmo conteúdo).

### Testes não executados (roteiro físico de 20 itens da demanda original)

Os itens abaixo do roteiro físico completo não foram exercitados de forma
dedicada nesta bateria de testes (o foco desta rodada foi resolver o
bloqueio de corte/proporção). Não há indício de que estejam quebrados —
a lógica subjacente já foi validada por outros meios (ver observações),
mas a confirmação física direta desses cenários específicos fica pendente:
- Captura de uma sequência completa de até 5 notas fisicamente pela UI
  (só 1 nota foi capturada nas evidências desta bateria; a lógica de
  contagem/limite de 5 já foi validada via API real em rodada anterior).
- Bloqueio da 6ª captura na UI física (mesma observação — validado via API,
  não clicado fisicamente até o limite).
- Fluxo completo de "Finalizar digitalização" na UI física após as
  correções mais recentes (a ação `concluir-digitalizacao` já foi validada
  via curl em rodada anterior; a integração front-end→backend foi corrigida
  e testada estaticamente, mas não clicada fisicamente nesta bateria final).
- "Refazer" testado explicitamente no hardware físico nesta bateria (já
  validado por trace de código).
- Cancelar durante a digitalização encerrando a câmera fisicamente
  (validado por trace de código: `pararCameraScanner()` é chamado
  corretamente).
- Desconexão física do cabo USB durante uso, e reconexão.
- Disputa de acesso ao dispositivo com o NetumScan Pro aberto
  simultaneamente.

### Pendências externas ao escopo desta demanda (já conhecidas, não
bloqueiam este veredito, mas impedem considerar o sistema COMPLETO seguro
para produção)
- IDOR remanescente em `selecionarOrdem` e `finalizar` (envio ao Talent) —
  confirmado por exploração real em rodada anterior, decisão explícita de
  escopo do usuário de não corrigir nesta demanda. O `security-especialista`
  recomenda priorizar antes de considerar o fluxo de Recebimento como um
  todo seguro para produção.
- JPEG truncado aceito pela validação atual (achado médio, não bloqueante).
- Ausência de lock/transação em `concluirDigitalizacao` contra corrida
  (severidade baixa).
- Retomada de atendimento ao recarregar a página (limitação pré-existente,
  não criada nesta demanda).
- Origem da chave de acesso da NF-e na tela de digitalização continua
  indefinida (`chave: null` fixo) — sem leitor HID substituto decidido.
- Handler global de exceção de banco ausente (achado médio, não
  bloqueante).
- `docs/manual_talent.md`/`docs/db_gestao_coletas.md` continuam
  placeholder — fora do escopo desta demanda.

### Próximo passo
Rodar `/03-revisao`.

## Resultado da revisão — /03-revisao

Data: 2026-09-04
Etapa: 03-revisao (revisão independente antes do commit)

### Veredito: APROVADO

A implementação corresponde exatamente ao que foi planejado e corrigido ao
longo das etapas anteriores — nem menos, nem mais do que foi pedido. As
revisões cruzadas (`explorer`, `security-especialista`,
`ui-ux-especialista`) confirmaram, por leitura direta do código (não por
aceitação dos relatos anteriores), que não há divergência entre o que o
handoff descreve e o estado real do código, e não encontraram nenhum
problema bloqueante introduzido por esta demanda.

### Evidências verificadas (por leitura direta do código, nesta etapa)

- **Isolamento**: funções novas do scanner só são referenciadas no case
  `rec_digitaliza`; as 4 telas de CNH/CRLV continuam com o padrão HID
  antigo intocado; `AtendimentoController::iniciar` não foi alterado.
- **Seleção/persistência da NETUM Camera**: lógica de `enumerateDevices`,
  reuso de `localStorage`, fallback de seleção manual e tratamento de
  `OverconstrainedError` confirmados corretos.
- **Preview 4:3 / resolução 3264×2448 / ausência de flash**: cálculo em
  pixels, `ResizeObserver`, `requestAnimationFrame`, fallback CSS `4/3`,
  `flex-shrink:0` — tudo internamente consistente, sem resíduo de
  tentativas anteriores (`aspect-ratio` via JS, 0 ocorrências).
- **Limpeza de recursos**: 6 de 7 mecanismos (interval, ResizeObserver, 2×
  requestAnimationFrame, ondevicechange, tracks/onended) confirmados com
  limpeza simétrica antes de recriar. 1 achado novo de severidade baixa
  (ver abaixo).
- **Limite de 5 notas**: aplicado tanto no front-end quanto no backend
  (`ordem` 1-5 + contagem antes de aceitar).
- **Conclusão da digitalização**: `concluirDigitalizacao` valida
  posse/tipo/status/etapa/contagem antes de decidir `cnh`/`cliente` e
  atualizar `etapa_atual`; front-end só navega em caso de sucesso,
  preserva estado em erro.
- **Autenticação/IDOR nas ações do escopo**: `salvar-etapa`,
  `bloquear-excesso-notas`, `concluir-digitalizacao`, `cancelar`,
  `nota.php processar/status` — todas confirmadas protegidas.
- **Validação/persistência de imagens**: ordem de validação em
  `UploadHelper` confirmada correta; JPEG truncado tecnicamente explicado
  (não é falha de segurança).
- **Migration/documentação**: não-destrutiva, constraint também em
  `schema.sql`, seção 1 e 5 de `ia_development_state.md` coerentes, sem
  contradições.

### Problemas por severidade

**Baixa/atenção (novo, não relatado antes)**: em `abrirStreamScanner()`,
o listener `resize` do `<video>` e o stream anterior (`scannerStreamAtual`)
não são explicitamente limpos no início da função, diferente dos outros 4
mecanismos (que têm remoção explícita antes de recriar). Não há vazamento
ativo hoje (só 2 pontos de chamada existem, nenhum sobrepõe sessão), mas é
uma assimetria de robustez a observar se um novo ponto de chamada for
adicionado no futuro (ex: botão de trocar câmera durante sessão ativa).
Não bloqueante.

**Médio, não bloqueante (já conhecido)**: JPEG truncado ainda aceito —
confirmado pelo `security-especialista` como questão de integridade de
dado, não de segurança (sem path traversal, DoS ou RCE). Pior cenário:
nota fiscal ilegível salva com sucesso, recuperável por nova digitalização.

**Baixo, não bloqueante (já conhecidos)**: mensagem "scanner ocupado" com
jargão técnico não acionável pelo motorista leigo; ausência de instrução
textual junto à guia visual; inconsistência de hierarquia visual do botão
"Capturar nota" (`.btn-fantasma` em vez de `.btn-primario`, diferente do
padrão usado em CRLV); mensagem de `ordem=0` inconsistente; comentário
impreciso na migration.

**Atenção — fora do escopo desta demanda, mas bloqueante para PRODUÇÃO
GERAL do sistema**: IDOR remanescente em `AtendimentoController::selecionarOrdem`
e `::finalizar`, confirmado por leitura direta nesta revisão. Parecer
formal do `security-especialista`: *"O sistema totem-udlog, como um todo —
não apenas a demanda recebimento-scanner-netum-sd2000 — não deve ser
considerado seguro para uso em produção enquanto os métodos
`AtendimentoController::selecionarOrdem` e `AtendimentoController::finalizar`
não receberem a mesma validação de posse (`id_totem`) já aplicada aos
demais métodos deste controller. Atualmente, qualquer totem autenticado
pode: (a) via `selecionarOrdem`, adulterar os dados de cliente/CNPJ de um
atendimento de Expedição pertencente a outro totem; e (b) via `finalizar`,
disparar o envio de um atendimento alheio ao sistema externo Talent,
gerando senha e protocolo de atendimento de outro totem sem autorização —
efeito colateral irreversível em sistema de terceiros. Este é um IDOR do
mesmo padrão já corrigido em outras ações do sistema, e sua correção é
pré-requisito para produção."*

### Itens obrigatórios antes de produção (não antes deste commit)

1. Corrigir o IDOR em `selecionarOrdem` e `finalizar` — decisão explícita
   do usuário em rodadas anteriores de tratar como demanda separada, mas
   registrado formalmente como bloqueante para produção geral.
2. Confirmar validação física dos cenários ainda não exercitados (sequência
   completa de 5 notas, bloqueio da 6ª, fluxo completo de "Finalizar" após
   as últimas correções, cancelar/desconexão/reconexão fisicamente,
   disputa com NetumScan Pro) — não bloqueia o commit desta demanda, mas
   deve ser concluído antes de considerar o scanner 100% validado em
   produção.
3. Decidir origem da chave de acesso da NF-e (pendência de produto já
   registrada).

### Confirmação de escopo

Nenhuma alteração fora do escopo desta demanda foi encontrada. Expedição,
CNH, CRLV, contratos externos (Talent/Prodesp/OrdemColeta) permanecem
exatamente como estavam. As únicas alterações de backend desta demanda
(fora do IDOR remanescente, que já existia antes e não foi criado por
esta demanda) foram nas ações listadas no escopo original.

### Próximo passo

Rodar `/04-commit-e-push`.
