# Handoff - netum-preview-captura-resolucao

Data: 2026-09-16
Etapa: 00-planejamento

## O que foi pedido

Investigar a pendencia registrada em ia_development_state.md (antiga
linha 158, secao 5) descrevendo que o preview/captura do scanner Netum
SD-2000 "corta a imagem do documento e a resolucao capturada e
insuficiente (texto ilegivel)" - mas com suspeita explicita do usuario
de que essa pendencia pode estar desatualizada, ja que existem
evidencias de outra demanda anterior de captura fisica real em
3264x2448, preview 4:3, documento sem corte e guia estavel.

Tambem foi pedido, antes do diagnostico, corrigir 6 entradas
desatualizadas em ia_development_state.md (CORS/PNA dev/producao,
HTTP 409 do Talent, formato anexos/fallback anexosGZip, impressao
fisica no mini PC de producao) - ver secao "Correcoes de status
aplicadas" abaixo.

Nesta etapa: so planejamento e diagnostico nao destrutivo. Nenhuma
implementacao, nenhuma captura de documento real/dado pessoal, nenhuma
alteracao de banco, nenhuma chamada ao Talent, nenhuma impressao,
nenhum commit/push.

## Correções de status aplicadas em `ia_development_state.md` (seção 5)

Todas preservando o histórico/evidências anteriores (texto antigo
mantido riscado com `~~...~~`, nunca apagado):

1. **CORS/PNA do serviço local de impressão** (antiga linha 138):
   corrigido de "ainda vazias" para o estado real pós-demanda
   `impressao-origens-permitidas` (2026-09-16) — desenvolvimento ativo
   com `origensPermitidas: ["http://localhost:8080"]`; produção
   (`https://udlog.online`) confirmada e documentada, mas AINDA NÃO
   ativada nem testada (só no deploy final).
2. **Preview/captura do Netum SD-2000** (antiga linha 158): corrigido
   de "correção em andamento" (texto de 2026-09-04, pré-correção) para
   "RESOLVIDA em 2026-09-04, registro aqui estava DESATUALIZADO" — ver
   diagnóstico completo abaixo.
3. **Nova linha**: validação física do serviço de impressão no mini PC
   de PRODUÇÃO real (distinto do ambiente de dev já testado) —
   explicitada como ADIADA para a etapa final do projeto, por decisão
   do usuário, não como pendência técnica aberta.
4. **HTTP 409 real do Talent / `anexos` / `anexosGZip`** (antiga linha
   206): corrigido de "PENDENTE DE EXECUÇÃO CONTROLADA" para
   "CONCLUÍDO E CONFIRMADO EMPIRICAMENTE" — a demanda
   `talent-http409-limpeza-pendencias` (2026-09-15) já havia executado
   e confirmado isso, mas a linha da tabela de pendências nunca foi
   atualizada para refletir esse resultado (só o log da seção 7 tinha
   o dado correto).

## Diagnóstico do Netum SD-2000 (leitura de código + histórico, sem acesso a hardware nesta etapa)

Investigação feita por dois sub-agentes independentes em paralelo
(`explorer` mapeando `ia_development_state.md`/handoffs/commits;
`frontend-especialista` analisando o código atual linha a linha).
Ambos convergiram para a mesma conclusão.

### Veredito

**O defeito já foi corrigido e validado fisicamente em 2026-09-04.**
A pendência registrada não descreve um bug ainda presente no código —
descreve o estado ANTES da correção do mesmo dia, e nunca foi
atualizada. Não são dois problemas distintos (preview visual vs.
arquivo real) hoje — é uma única pendência de REGISTRO/documentação,
já corrigida acima nesta mesma etapa.

### Evidência no próprio histórico do projeto

- Handoff único existente sobre o tema:
  `docs/handoffs/2026-09-03-recebimento-scanner-netum-sd2000.md`.
- Sequência real de 2026-09-04: teste físico inicial REPROVADO (passo 5
  "documento cortado", passo 6 "resolução insuficiente") → causa raiz
  identificada (`getUserMedia` sem constraint de resolução + função de
  captura compartilhada com CNH/CRLV que forçava downscale fixo para
  900px) → 5 correções sucessivas no mesmo dia (constraint `ideal`
  4096×3072; função dedicada `capturarFotoScannerNota()` sem downscale;
  guia visual sincronizada via polling 250ms após listener `resize`
  isolado se mostrar insuficiente em Chromium; `flex-shrink:0`
  corrigindo compressão de altura por flexbox; eliminação de flash
  vertical) → validação física final confirmada pelo usuário: resolução
  real de captura **3264×2448 (4:3)**, documento inteiro visível, sem
  corte, texto legível, preview estável.
- Veredito consolidado da demanda original: `/02-testes` = APROVADO COM
  RESSALVAS, `/03-revisao` = APROVADO.
- Commit inicial do repositório (`22eeba04da3b9c475421f1105ef1b2657226821c`,
  `feat(recebimento): integra scanner para notas`, 2026-09-04) já
  contém TODA essa implementação e correções (squash do histórico
  anterior ao git). Commit de fechamento do handoff
  (`ae7f162c4af3c3237d8df545aa49d3b7652fa6c2`, `docs: fecha handoff do
  scanner Netum`) só tocou documentação. Nenhum dos dois foi alterado
  nesta investigação (só consulta via `git show`/`git log`).

### Estado atual do código (confirmado por leitura, 2026-09-16)

- `capturarFotoScannerNota(video)` (`public/totem/assets/app.js`,
  ~linha 1756) é a função DEDICADA e ATUAL do scanner de notas —
  totalmente separada de `capturarFotoBase64` (usada só por CNH/CRLV,
  com o downscale fixo de 900px que causava o bug original). Não há
  compartilhamento entre as duas hoje: qualquer alteração futura no
  scanner não afeta CNH/CRLV, e vice-versa.
- `canvas.width = video.videoWidth; canvas.height = video.videoHeight`
  — dimensionamento dinâmico com a resolução REAL entregue pela
  câmera, sem downscale algum antes da compressão JPEG.
- `getUserMedia` do scanner (`abrirStreamScanner`, ~linha 1549) pede
  `width: {ideal: 4096}, height: {ideal: 3072}` (constantes nomeadas
  `SCANNER_RESOLUCAO_IDEAL_LARGURA`/`SCANNER_RESOLUCAO_IDEAL_ALTURA`) —
  `ideal` nunca lança `OverconstrainedError`, o driver entrega o máximo
  real suportado (medido em 2026-09-04: 3264×2448).
- `video.videoWidth`/`videoHeight` só são usados após
  `onloadedmetadata`, reforçados por polling ativo a cada 250ms
  (mitigação documentada para o evento `resize` do `HTMLVideoElement`
  se mostrar inconsistente para streams `MediaStream` em Chromium) — a
  captura em si (`capturarPreviaNota`) só é habilitada quando
  `video.readyState >= 2 && videoWidth > 0 && videoHeight > 0`, nunca
  prematuramente.
- CSS `.caixa-scanner video { object-fit: contain }` (não `cover`) —
  preserva a proporção inteira do vídeo dentro do container, nunca
  corta visualmente. Isso é distinto de `.caixa-camera` (CNH/CRLV, que
  usa `cover`, mas isso é só exibição — não afeta o arquivo salvo, já
  que `drawImage()` sempre desenha o frame inteiro sem crop).
- Guia visual (`.guia-scanner`, `inset: 8%`) acompanha a proporção real
  do vídeo indiretamente, porque o container `.caixa-scanner` tem sua
  altura recalculada dinamicamente em JS (`aplicarAlturaScanner`) com
  base em `videoWidth`/`videoHeight` reais — não é uma caixa
  desalinhada da resolução real.
- Compressão JPEG iterativa (qualidades `[0.92, 0.85, 0.75, 0.65, 0.5,
  0.4]`) até caber em 4.5MB (margem sob o limite de 5MB do backend,
  `NOTA_IMAGEM_MAX_BYTES`); se ainda exceder na qualidade mínima,
  reduz a escala do canvas em passos de 90%, nunca abaixo de 600px de
  largura — mecanismo de segurança adicional não presente na função
  antiga.
- Nenhum `transform: scaleX(-1)`/rotação encontrado em nenhum ponto
  relacionado a câmera/canvas.

### O que permanece incerto (só confirmável com hardware físico real)

1. A resolução REAL entregue pelo Netum SD-2000 físico do totem em
   produção — o código pede `ideal 4096×3072`, mas o valor medido em
   2026-09-04 (3264×2448) foi específico daquele teste; drivers/UVC
   podem entregar valores diferentes em outra unidade de hardware.
2. Se a renegociação de resolução (evento `resize` inconsistente,
   mitigado por polling) se comporta igual em builds mais recentes do
   Chromium kiosk do mini PC de produção — o comportamento documentado
   foi observado numa versão específica de navegador em 2026-09-04.
3. Legibilidade real do texto em condições de iluminação variáveis do
   ambiente físico definitivo do totem (depende de foco automático,
   iluminação, distância do documento à guia — não é característica
   garantida estaticamente por código).
4. Estabilidade do `label`/comportamento do dispositivo `videoinput`
   entre reconexões USB/reboots do mini PC — pendência formalmente
   ainda aberta e já registrada (linha da tabela sobre validação física
   de `videoinput`/label/resolução no Windows/Chromium).
5. Roteiro físico completo de 20 itens da demanda original
   (`docs/handoffs/2026-09-03-recebimento-scanner-netum-sd2000.md`)
   segue formalmente não reexecutado/fechado, apesar do essencial já
   estar validado.

### Achados relacionados, não corrigidos (fora de escopo desta demanda)

- `util/UploadHelper.php:49-58` — `getimagesizefromstring()` valida
  cabeçalho/dimensões do JPEG, mas não verifica o marcador de fim de
  arquivo (EOI, `FFD9`) — um JPEG truncado no meio do stream ainda
  passaria na validação. Achado pré-existente, já registrado na tabela
  de pendências, reconfirmado ainda procedente por leitura de código.
- Comentário incorreto "leitor Netum SD2000: funciona como teclado
  (HID)" sobrevive em `app.js` (~linha 477, acima de
  `habilitarLeitorScanner()`), usado hoje pelas telas de CNH/CRLV — não
  é bug funcional, é imprecisão textual remanescente no código. Fora do
  escopo desta demanda (só a tela `rec_digitaliza` foi tocada pela
  correção original), registrado aqui só como observação.

## Arquivos que precisariam ser alterados

**Nenhum código precisa ser alterado** com base no diagnóstico atual —
o mecanismo já está correto. Se a validação física planejada abaixo
(quando executada) confirmar alguma regressão real, os arquivos
candidatos seriam:
- `public/totem/assets/app.js` (`abrirStreamScanner`,
  `capturarFotoScannerNota`, `ajustarProporcaoScanner`).
- `public/totem/assets/app.css` (`.caixa-scanner`, `.guia-scanner`).

Nenhuma alteração de backend é esperada (o backend só valida
tamanho/magic-bytes/dimensões básicas, não é o ponto de origem do
comportamento investigado).

## Plano mínimo e reversível de implementação

Como o diagnóstico não encontrou bug de código, o "plano de
implementação" desta demanda é, por ordem de prioridade:

1. **Já aplicado nesta etapa**: corrigir o registro desatualizado em
   `ia_development_state.md` (feito, ver seção acima).
2. **Opcional, a decidir com o usuário**: executar uma validação física
   rápida (não o roteiro completo de 20 itens) no hardware já
   disponível no ambiente de dev, para reconfirmar que o comportamento
   de 2026-09-04 se mantém (nenhuma mudança de código ocorreu desde
   então nessa área, mas nunca foi reconfirmado formalmente). Se
   confirmado, a demanda fecha sem nenhuma mudança de código. Se
   alguma regressão for encontrada, aí sim entra em `/01-implementacao`
   com escopo restrito ao achado específico.
3. Rollback: não aplicável — nenhuma mudança de código está planejada
   nesta etapa.

## Critérios objetivos de aceite (para a validação física, se executada)

- `video.videoWidth`/`video.videoHeight` (lidos via `console.log` de
  diagnóstico já existente no código, `[scanner Netum] dispositivo
  conectado:`) ≥ 3264×2448 (ou a resolução máxima real entregue pelo
  driver, o que for menor — mas nunca abaixo do que foi medido em
  2026-09-04).
- `canvas.width`/`canvas.height` (log `[scanner Netum] captura
  gerada:`) idênticos a `video.videoWidth`/`videoHeight` no momento da
  captura — sem downscale antes da compressão.
- Proporção do JPEG salvo em `storage/` (via `getimagesize()` do
  arquivo real, não só do log do navegador) igual a 4:3 (ou à
  proporção nativa real da câmera, se diferente de 4:3 — não presumir).
- Documento inteiro visível no preview E no arquivo salvo, sem corte
  em nenhuma borda (confirmação visual humana, não automatizável).
- Texto do documento legível a olho nu no arquivo salvo (confirmação
  visual humana).
- Preview sem corte de tela pela guia (`.guia-scanner`) em proporção
  compatível com o vídeo real.
- Nenhuma regressão em CNH/CRLV (que usam `capturarFotoBase64`,
  função distinta, não tocada) nem em Expedição.

## Plano de testes

### Automatizados (podem rodar sem hardware, nesta etapa ou na `/02-testes`)

- Leitura estática confirmando que `capturarFotoScannerNota` e
  `capturarFotoBase64` permanecem funções distintas, sem
  compartilhamento (grep/diff, sem execução de navegador).
- Regressão das suítes de backend já existentes relacionadas a upload
  de imagem (`util/UploadHelper.php`), garantindo que nenhuma mudança
  não intencional tenha ocorrido desde 2026-09-04 (comparação de
  `mtime`/hash do arquivo, sem execução real necessária se não houver
  suíte automatizada dedicada).

### Físicos (roteiro controlado, SÓ na `/02-testes`, com autorização explícita do usuário — hardware Netum SD-2000 disponível no ambiente de dev)

Regra de processo já registrada no projeto: qualquer teste envolvendo
captura real precisa de autorização prévia explícita do usuário. Nada
disso será executado nesta etapa de planejamento.

1. Abrir a tela `rec_digitaliza`, conceder permissão de câmera,
   confirmar que o Netum SD-2000 aparece como `videoinput` com label
   reconhecível.
2. Iniciar o preview, ler no console o log `[scanner Netum] dispositivo
   conectado:` — registrar resolução `ideal` solicitada vs. resolução
   real entregue (`track.getSettings()`).
3. Posicionar um documento de TESTE (nunca CNH/CPF/nota fiscal real —
   usar folha impressa neutra com texto de teste, sem dado pessoal) na
   guia, capturar.
4. Ler no console o log `[scanner Netum] captura gerada:` — registrar
   `canvas.width`/`canvas.height` e tamanho aproximado do JPEG.
5. Confirmar visualmente, no preview de confirmação da própria tela,
   que o documento aparece inteiro, sem corte, texto legível.
6. (Se avançar até salvar) Verificar o arquivo real salvo em
   `storage/` via `getimagesize()`/abrir a imagem diretamente,
   confirmando dimensões e proporção.
7. Repetir 2-3 vezes para confirmar estabilidade (múltiplas
   capturas/reconexões).
8. Confirmar, por navegação nas outras telas do totem, que CNH/CRLV
   (Expedição) continuam funcionando sem nenhuma alteração perceptível
   (regressão visual rápida, não o roteiro completo).

Todos os passos 3-8 exigem autorização explícita do usuário no momento
da execução (`/02-testes`), mesmo sendo documento de teste sem dado
pessoal.

## Riscos

- Nenhum risco de regressão identificado por código — o mecanismo já
  está correto e isolado (scanner não compartilha função com CNH/CRLV).
- Risco de a validação física (se executada) revelar uma variável não
  testada em 2026-09-04 (build diferente de Chromium, unidade
  diferente de hardware) — nesse caso a demanda abre uma
  `/01-implementacao` restrita ao achado específico, não teria efeito
  retroativo sobre o que já foi corrigido.
- Risco de a correção de registro em `ia_development_state.md`
  (aplicada nesta etapa) estar incompleta se houver outra menção
  desatualizada não encontrada nesta investigação — mitigado por busca
  ampla feita pelos dois sub-agentes, mas não pode ser 100% garantido
  sem uma varredura exaustiva de todo o arquivo (2400+ linhas).

## Rollback

Não aplicável a esta etapa — nenhuma alteração de código foi feita.
Se a validação física planejada revelar necessidade de mudança de
código no futuro, o rollback seria reverter o commit específico dessa
mudança (nunca reverter a correção de 2026-09-04, que está validada e
funcional).

## O que NÃO foi feito nesta etapa

- Nenhuma implementação/alteração de código.
- Nenhuma captura de documento real nem dado pessoal.
- Nenhuma alteração de banco.
- Nenhuma chamada ao Talent.
- Nenhuma impressão.
- Nenhum commit, nenhum push.
- Nenhuma alteração do histórico git (commits `22eeba04`/`ae7f162c`
  só foram consultados via `git show`/`git log`).

## Sub-agentes envolvidos

- `explorer` (mapeamento de `ia_development_state.md`, handoff
  original, commits `22eeba04`/`ae7f162c`).
- `frontend-especialista` (diagnóstico de código linha a linha do
  preview/captura atual).

Para a futura `/01-implementacao` (se necessária) ou `/02-testes`:
`qa-testes` (execução do roteiro físico controlado, com autorização
explícita do usuário).

## Pendências conhecidas

1. Roteiro físico completo de 20 itens da demanda original ainda não
   formalmente reexecutado/fechado (pré-existente, não criado por esta
   demanda).
2. Validação de `videoinput`/label/resolução em builds recentes de
   Chromium do mini PC de produção (distinto do ambiente de dev já
   testado) — pré-existente.
3. `util/UploadHelper.php` não valida marcador EOI de JPEG truncado —
   pré-existente, fora do escopo desta demanda.

## Decisões realmente bloqueantes

**Nenhuma.** O diagnóstico concluiu, com alto grau de confiança por
leitura de código e histórico convergente de dois sub-agentes
independentes, que o defeito já foi corrigido em 2026-09-04. A única
ação necessária identificada (corrigir o registro desatualizado em
`ia_development_state.md`) já foi executada nesta própria etapa de
planejamento. A validação física de reconfirmação é OPCIONAL e não
bloqueia o fechamento da demanda — fica a critério do usuário decidir
se quer essa reconfirmação formal ou se considera a demanda encerrada
já nesta etapa (sem `/01-implementacao` nem `/02-testes` físico).

## Trello
card_id: 6aaab5774d4ea890146040fc

## Próximo passo

Depende da decisão do usuário:
(a) considerar a demanda encerrada já nesta etapa (correção de registro
foi o único trabalho real necessário), avançando direto para
`/04-commit-e-push` da correção de documentação; ou
(b) autorizar uma rodada de `/02-testes` física de reconfirmação
(roteiro acima, com documento de teste sem dado pessoal) antes de
fechar.

## Revisão curta de confirmação (2026-09-16, /03-revisao)

Revisão independente (`security-especialista`, sem participação no
`/00-planejamento`), nenhum teste físico repetido. Todos os 8 itens de
confirmação: **PASS**.

1. Pendência do Netum marcada como resolvida, não mais soa como
   pendência ativa.
2. `capturarFotoScannerNota()` confirmada dedicada, sem downscale,
   totalmente separada de `capturarFotoBase64` (900px, só CNH/CRLV).
3. CORS/PNA dev confirmado ativo para `http://localhost:8080` exato.
4. Produção `https://udlog.online` confirmada só documentada, não
   ativada nem testada.
5. HTTP 409 real do Talent confirmado CONCLUÍDO/CONFIRMADO
   EMPIRICAMENTE.
6. `anexos` confirmado concluído; `anexosGZip` confirmado NÃO
   NECESSÁRIO.
7. Validação física do serviço de impressão no mini PC de produção
   confirmada como adiada para a etapa final, não pendência aberta.
8. Histórico preservado em todas as edições (texto antigo riscado,
   nunca apagado); nenhuma pendência genuinamente aberta apresentada
   como resolvida.

Validações técnicas: `git diff --check` limpo (só aviso de CRLF);
`git status`/`git diff` confirmam que SOMENTE `ia_development_state.md`
e este handoff foram tocados — nenhum código, configuração operacional
ou segredo alterado; busca por credencial no diff sem achado (só nomes
de variável em texto histórico preservado). Verificação cruzada com o
handoff original (`docs/handoffs/2026-09-03-recebimento-scanner-netum-sd2000.md`)
sem divergência.

### Veredito

**APROVADO.** Nenhum achado. **Pronta para `/04-commit-e-push` curto.**
