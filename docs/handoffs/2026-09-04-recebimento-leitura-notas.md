# Handoff — recebimento-leitura-notas

Data: 2026-09-04
Etapa: 00-planejamento (REPLANEJADO — versao 2, substitui a versao anterior baseada em n8n)

## Correcao de arquitetura registrada nesta versao

A versao anterior deste handoff (arquivada no historico do git, nao
reescrita a partir do zero aqui) planejava OCR via **n8n externo**
(webhook de disparo + cron de retry + callback autenticado). O usuario
decidiu **nao usar n8n**. O OCR agora roda **client-side, via
Tesseract.js dentro de um Web Worker no navegador do totem**, disparado
logo apos "Usar imagem" confirmar a nota. Esta versao descarta
integralmente: webhook de disparo do backend para servico externo,
endpoint de callback autenticado por token de n8n, e cron de retry de
OCR server-side. Nao ha mais processamento assincrono de longa duracao
do lado do servidor — o unico ponto de rede novo e uma chamada HTTP
curta e sincrona do front-end para o backend, depois que o OCR local ja
terminou.

Tambem incorporado nesta versao: o contrato da API de clientes
(`https://udlog.online/iaUdlog/api/v1/clientes`) foi **confirmado por
teste real** em 2026-09-04 (ver `docs/db_gestao_coletas.md`, secao
"clientes"). Autenticacao Bearer obrigatoria, busca por `?cnpj=` exato
(sem 404, retorna `dados: []`), listagem paginada sem busca textual,
campos `id, razao_social, cnpj, email, status, criado_em, atualizado_em`
(sem nome fantasia/aliases), rate limit de 60 (janela nao confirmada).

## O que foi pedido (original, ainda valido)

Identificar o cliente automaticamente apos cada nota fiscal digitalizada
no Recebimento, sem bloquear a fluidez da digitalizacao: normalizacao e
validacao de CNPJ, exclusao dos CNPJs da UDLOG (`14706199000182`,
`14706199000344`), identificacao por CNPJ exato primeiro e depois por
razao social (fuzzy), nunca identificar automaticamente em caso de
ambiguidade/baixa confianca, e comportamento definido ao finalizar a
digitalizacao conforme o resultado (identificado, nao identificado,
erro).

## Arquitetura de backend replanejada

### Fluxo (visao geral)

1. Front-end salva a nota normalmente (fluxo sincrono atual de
   `NotaController::processar`, **inalterado na parte de salvar
   imagem/registro** — so muda o dado de retorno, ver abaixo). Nota
   comeca com `status_ocr = PENDENTE`.
2. Front-end, apos salvar, dispara Tesseract.js num Web Worker sobre a
   imagem local (a mesma que acabou de capturar/confirmar — nao precisa
   buscar do servidor, ja esta em memoria no browser). Estado exibido/
   controlado no front: `PROCESSANDO`.
3. Ao terminar o OCR local, o front-end chama o **novo** endpoint
   `nota.php?acao=identificar-cliente`, enviando os candidatos extraidos
   (lista de CNPJs candidatos, texto de razao social candidato, chave de
   acesso de 44 digitos se encontrada). Chamada sincrona, curta (sem
   fila, sem callback, sem polling — a resposta HTTP desse endpoint JA
   traz o resultado final).
4. Backend normaliza/valida CNPJ, exclui UDLOG, prioriza chave de acesso
   valida (decodificacao 100% local ja existente), consulta a API de
   clientes por CNPJ exato, e se nao houver match exato faz fuzzy match
   local contra a listagem cacheada. Responde `IDENTIFICADA` (com dados
   do cliente), `NAO_IDENTIFICADA`, ou `ERRO` (falha tecnica ao consultar
   a API externa).
5. Backend persiste o resultado em `status_ocr` da nota (grava o que a
   propria requisicao acabou de decidir — nao ha estado intermediario
   persistido de "aguardando outro processo").
6. **Nao ha mais webhook, callback, nem cron de retry server-side.**
   Retry de falha de rede (se a chamada ao endpoint de identificacao
   falhar por erro de conexao) fica inteiramente no JavaScript do totem,
   dentro da mesma sessao do navegador (2-3 tentativas com backoff curto,
   ex.: 1s/2s/4s) — sem necessidade de fila/estado server-side para isso,
   porque nao ha nenhum processamento pesado/lento pendente do lado do
   servidor: e so uma chamada HTTP + fuzzy match local em PHP, que ou
   responde rapido ou falha rapido (sem meio-termo assincrono a
   gerenciar).

### Por que descartar webhook/callback/cron de retry (justificativa explicita)

O motivo original para essas 3 pecas era a latencia/nao-confiabilidade de
um processo **externo** rodando em segundo plano, fora do controle do
totem, sem fastcgi_finish_request confiavel em Hostgator. Com o OCR
rodando no proprio navegador do totem (sincrono do ponto de vista da
sessao, ainda que em Web Worker para nao travar a UI), esse motivo deixa
de existir:
- Nao ha mais "disparar um processo e esperar ele responder depois" —
  o navegador SABE quando o OCR terminou (o proprio worker retorna).
- A unica chamada de rede que resta (`identificar-cliente`) e uma
  chamada HTTP normal, request/response, do mesmo tipo que qualquer outra
  acao do totem hoje (`processar`, `concluir-digitalizacao` etc.) — nao
  precisa de nenhum mecanismo especial de fila/retry server-side que o
  resto do sistema tambem nao tem.
- Se essa chamada falhar (rede instavel do totem, API de clientes fora do
  ar), o pior caso e a nota ficar `PENDENTE`/`ERRO` e o front-end tratar
  como "nao identificada", oferecendo a confirmacao manual do cliente —
  mesmo comportamento de fallback ja usado hoje quando `cliente_identificado
  = false` no fluxo sincrono atual. Nao ha necessidade de garantir que o
  resultado "acabara chegando mais tarde" via cron, porque nao existe mais
  processamento externo lento cuja conclusao dependa de tempo.

### Estados por nota (simplificados)

Mantidos 5 estados conceituais, mas com semantica mais simples (sem
controle de retry server-side):

```
PENDENTE       -- nota salva, OCR ainda nao comecou no navegador
PROCESSANDO    -- Tesseract.js rodando no Web Worker (estado que existe
                  principalmente no front-end; no banco, so e persistido
                  se o front-end optar por marcar explicitamente antes de
                  chamar identificar-cliente — a avaliar na implementacao,
                  nao decidido aqui se vale a pena esse marco intermediario
                  no banco ou se basta ir direto de PENDENTE pro resultado
                  final)
IDENTIFICADA   -- backend confirmou cliente (CNPJ exato ou fuzzy de alta confianca)
NAO_IDENTIFICADA -- backend nao encontrou correspondencia confiavel
ERRO           -- falha tecnica ao consultar a API de clientes (ex: timeout,
                  5xx, token invalido) — tratado pelo front-end como
                  equivalente a NAO_IDENTIFICADA para fins de fluxo (nunca
                  alarmante para o motorista)
```

Nova coluna em `tb_atendimento_nota` (mesma ideia do plano anterior, mas
**sem** as colunas de controle de retry via cron):

```sql
status_ocr    ENUM('PENDENTE','PROCESSANDO','IDENTIFICADA','NAO_IDENTIFICADA','ERRO')
              NOT NULL DEFAULT 'PENDENTE'
processado_em DATETIME NULL
INDEX idx_status_ocr (status_ocr)
```

Removido do desenho anterior (nao fazem mais sentido, nao ha cron):
`tentativas_ocr`, `proxima_tentativa_ocr`. Removido tambem `texto_ocr`
como coluna obrigatoria do desenho — a decisao de persistir o texto bruto
do OCR (para auditoria/depuracao) e um trade-off separado, nao decidido
aqui (ver Pendencias); se decidido manter, deve ser tratado como dado
sensivel (nao logar em texto claro, ver Seguranca).

Transicoes validas: `PENDENTE -> (IDENTIFICADA | NAO_IDENTIFICADA | ERRO)`
diretamente pela resposta de `identificar-cliente`, sem passar
necessariamente por `PROCESSANDO` persistido no banco (o "processando" e
majoritariamente um estado de UI do front-end enquanto o worker roda,
nao um estado que precisa sobreviver a uma falha de servidor porque nao
ha mais retry server-side esperando por ele). **Nao decidido nesta etapa**
se `PROCESSANDO` deve ser gravado no banco antes da chamada de
identificacao (util so se o front-end quiser recarregar a pagina e saber
"ainda estava processando" — mas o projeto ja tem a limitacao conhecida
de nao ter retomada de sessao ao recarregar, entao esse ganho e marginal).

### Novo endpoint: `nota.php?acao=identificar-cliente`

Requisicao (enviada pelo front-end, autenticada com o token do totem via
`Util\Auth::validarTotem()` — igual a todo outro endpoint publico):

```
POST nota.php?acao=identificar-cliente
{
  "id_atendimento": <int>,
  "ordem": <int>,
  "chave_ocr": "<44 digitos ou null>",
  "cnpjs_candidatos": ["<string bruta, com ou sem mascara>", ...],
  "razao_social_candidata": "<string ou null>"
}
```

Formato exato dos campos `cnpjs_candidatos`/`razao_social_candidata` (se
o Tesseract.js devolve so texto bruto para o backend re-extrair CNPJ via
regex, ou se o front-end ja faz uma extracao/regex basica de CNPJ do
texto OCR antes de mandar) **nao decidido nesta etapa** — depende de como
o `frontend-especialista` desenhar a chamada ao Tesseract.js na propria
etapa de front-end deste replanejamento (fora do escopo desta tarefa,
que e so backend). Este plano assume que o backend recebe uma lista de
strings candidatas a CNPJ (ja extraidas por regex simples no front, ou
brutas do texto OCR — o backend normaliza/valida de qualquer forma) e
uma string candidata a razao social — ambas passiveis de estarem
vazias/nulas.

Passos do backend:
1. `Util\Auth::validarTotem()` (padrao de toda rota publica).
2. `buscarAtendimentoDoTotem($idAtendimento, $idTotem)` — **mesmo padrao
   de IDOR ja validado** em `NotaController::buscarAtendimentoDoTotem`
   (valida posse do atendimento pelo totem autenticado, mensagem
   generica 404 sem revelar existencia de atendimento alheio). Reforcar
   tambem que a nota (`id_atendimento` + `ordem`) precisa existir e
   pertencer a esse atendimento antes de aceitar o resultado — analogo
   a validacao ja feita em `processar`.
3. Se `chave_ocr` presente: valida com `NotaFiscalRn::chaveValida()` +
   `decodificarChave()` (100% locais, ja existentes) — se DV ok, o CNPJ
   do emitente decodificado tem prioridade maxima sobre qualquer CNPJ
   solto de `cnpjs_candidatos`.
4. Normaliza cada CNPJ candidato (remove nao-digito, exige 14 digitos,
   valida DV modulo 11 — algoritmo novo, independente do `calcularDV` de
   chave de NF-e que ja existe em `NotaFiscalRn` para outro proposito).
5. Remove da lista qualquer CNPJ que bata com as constantes UDLOG
   (`14706199000182`, `14706199000344`) — descartado antes de qualquer
   consulta, independente de ser o CNPJ da chave ou de texto livre.
6. Para cada CNPJ candidato valido restante (chave primeiro, depois os
   demais na ordem recebida): chama a API de clientes com `?cnpj=<14
   digitos>` (unico ponto que usa `CLIENTES_API_TOKEN`, nunca exposto ao
   front-end). Primeiro resultado nao-vazio (`dados` nao vazio) e usado
   -> `IDENTIFICADA` direto, sem precisar de fuzzy match.
7. Se nenhum CNPJ candidato bateu: se `razao_social_candidata` presente,
   busca a listagem completa da API (paginada, ver estrategia de cache
   abaixo) e faz fuzzy match local (`similar_text()` apos normalizacao:
   uppercase, remocao de acentos/pontuacao/sufixos societarios como
   LTDA/S.A/ME/EIRELI). So aceita como `IDENTIFICADA` se score >= limiar
   alto (ex.: 90%, ajustavel empiricamente, mesmo criterio conservador do
   plano anterior) E resultado unico (sem empate/segundo colocado
   proximo) — qualquer ambiguidade cai em `NAO_IDENTIFICADA`.
8. Falha tecnica na chamada a API de clientes (timeout, 5xx, token
   invalido, erro de rede) -> responde `ERRO` ao front-end, loga o erro
   tecnico no servidor (sem logar CNPJ/razao social do OCR em texto
   claro nesse log, ver Seguranca), **nao propaga excecao crua**.
9. Grava o resultado (`status_ocr`, `processado_em`, e os mesmos campos
   ja existentes `cnpj_emitente`/`cliente_identificado` reaproveitados —
   sem necessidade de nova coluna para isso) via PDO prepared statement.
10. Responde ao front-end: `{status: 'IDENTIFICADA', cliente: {...}}` ou
    `{status: 'NAO_IDENTIFICADA'}` ou `{status: 'ERRO'}`.

### Refinamento (2026-09-04) — early-stop por atendimento, ordem sequencial e criterio de confianca formalizado

Requisito adicional do usuario: o OCR deve processar as notas **nota por
nota, na ordem de captura, comecando pela primeira**; assim que um
cliente for identificado com seguranca, salvar no atendimento,
interromper/cancelar OCRs pendentes, nao executar OCR nem consultar a API
para as proximas notas, continuar capturando/salvando normalmente, e
exibir que o cliente ja foi identificado.

**Sinalizacao para o front-end**: toda resposta do endpoint (`IDENTIFICADA`,
`NAO_IDENTIFICADA` ou `ERRO`) passa a incluir um campo booleano adicional,
`ja_identificado_no_atendimento`, calculado APOS a resolucao da chamada
atual — `true` sempre que, ao final desta chamada, o atendimento ja possui
cliente identificado (seja de nota anterior, seja desta propria chamada).
O front-end usa esse campo para decidir sozinho nao chamar mais
`identificar-cliente` para as notas seguintes ainda sem resultado.

**Protecao redundante no backend (obrigatoria, nao so o sinal ao front)**:
novo passo 0, ANTES de qualquer normalizacao/chave/fuzzy: checar se o
atendimento ja tem cliente identificado (reaproveitando a mesma logica de
`NotaFiscalRn::algumaNotaIdentificouCliente`, ja existente e usada hoje
em `NotaController::algumaIdentificada`, adaptada para `status_ocr =
IDENTIFICADA`). Se sim, responde imediatamente `{status: 'IDENTIFICADA',
cliente: {...}, ja_identificado_no_atendimento: true}` SEM nenhuma
chamada a API externa de clientes — mesmo que o front chame de novo por
bug/corrida/reload. Nenhuma estrutura nova de banco necessaria (a mesma
query ja usada em producao ja responde "este atendimento ja tem cliente
identificado?").

Recomendacao nao travada: persistir tambem `status_ocr = IDENTIFICADA` na
nota que disparou a chamada tardia (mesmo valor de enum ja existente).
Pendencia registrada, nao decidida: como montar `cliente: {...}` no
short-circuit sem nova chamada externa — (a) reaproveitar o cache local
da listagem buscando pelo `cnpj_emitente` ja gravado na nota identificada,
ou (b) gravar campos adicionais (razao social, id) diretamente na nota no
momento da identificacao original. Fica para a implementacao decidir.

**Passos renumerados do endpoint** (passo 0 e o novo, demais mantem a
logica ja descrita acima):
```
0. Checar se o atendimento ja tem cliente identificado -> se sim, responde
   ja identificado e ENCERRA aqui, sem tocar em chave/CNPJ/fuzzy/API externa.
1. Util\Auth::validarTotem()
2. buscarAtendimentoDoTotem + validar que a nota (ordem) pertence ao atendimento
3. Validar chave de acesso (se presente) -> prioridade maxima
4. Normalizar/validar CNPJs candidatos
5. Excluir CNPJs UDLOG
6. Consultar API de clientes por CNPJ exato (chave primeiro, depois os demais)
   -> match unico = IDENTIFICADA direto, sem fuzzy
7. Sem match de CNPJ -> fuzzy de razao social (criterio refinado abaixo)
8. Falha tecnica -> ERRO
9. Persistir status_ocr/processado_em/campos de cliente
10. Responder ao front, sempre incluindo ja_identificado_no_atendimento
```

**Criterio de confianca do fuzzy match, formalizado** (substitui a
redacao anterior "score alto E resultado unico"):

> Identificacao automatica por razao social so ocorre quando, entre todos
> os candidatos da listagem cacheada, o score do 1o colocado for maior ou
> igual a `LIMIAR_MINIMO` E a diferenca entre o score do 1o e do 2o
> colocado for maior ou igual a `MARGEM_MINIMA` (se so existir um
> candidato na listagem inteira, o score do 2o colocado e tratado como 0
> para efeito desse calculo). Qualquer outro caso — score abaixo do
> limiar, ou acima do limiar mas sem margem suficiente sobre o segundo
> colocado — resulta em `NAO_IDENTIFICADA`, nunca identificacao
> automatica.

`LIMIAR_MINIMO` e `MARGEM_MINIMA` ficam como parametros configuraveis
(constante/config, nao hardcoded), valores exatos a ajustar
empiricamente na implementacao.

**Confirmacao da tecnica de normalizacao frente aos exemplos dados pelo
usuario** (`CROMIX`->`CROMEX`, `CRMEX`->`CROMEX`): a tecnica ja escolhida
(uppercase + remocao de acentos/pontuacao/sufixos societarios, seguida de
`similar_text()`/distancia de edicao) e tecnicamente compativel — sao
erros de OCR de 1-2 caracteres em palavras curtas, exatamente o tipo de
erro que essas tecnicas capturam bem. Nenhuma mudanca de tecnica
necessaria. Atencao: como a palavra e curta, 1-2 caracteres de diferenca
ja representam uma fatia grande do score percentual — reforca por que o
criterio de MARGEM sobre o segundo colocado (acima) e necessario alem do
limiar isolado, para nao aceitar falsos positivos entre nomes parecidos e
curtos.

### Cache local da listagem de clientes (para o fuzzy match)

Como nao ha busca textual na API externa (confirmado em
`docs/db_gestao_coletas.md`), o fuzzy match precisa da listagem completa
trazida para o PHP. Volume observado no teste real foi de ~40 registros
(nao presumir que sempre sera esse volume, a API pode crescer). Proposta:
- Buscar todas as paginas (`por_pagina` alto, ex.: 100, iterando
  `meta.total_paginas`) e cachear em memoria/arquivo local por uma janela
  curta (ex.: 60-300s, valor exato a ajustar empiricamente na
  implementacao) para nao repetir N chamadas identicas em rajadas de
  notas do mesmo atendimento nem estourar o rate limit de 60 observado
  (janela de tempo do rate limit nao confirmada — nao presumir).
- Mecanismo de cache (arquivo em `storage/` com TTL simples, ou
  `APCu`/cache em memoria se disponivel no Hostgator — **nao confirmado
  se APCu esta disponivel no ambiente de producao**, registrar como
  pendencia a validar na implementacao) fica a decidir na etapa de
  implementacao; nao trava aqui qual mecanismo exato.
- Esse cache e so para reduzir chamadas repetidas do fuzzy match dentro
  de uma janela curta — nao substitui nem resolve a pendencia ja
  registrada e nao alterada aqui de "origem/sincronizacao de
  `tb_cliente`" (essa pendencia de produto continua em aberto, este plano
  nao decide nada sobre ela).

### Normalizacao/validacao/exclusao de CNPJ (sem mudanca de criterio)

Mesma logica do plano anterior, so muda a origem do dado (vem do
front-end via `identificar-cliente`, nao de um callback de n8n):
algoritmo padrao de CNPJ (modulo 11, dois DVs), 14 digitos apos remover
mascara, constantes UDLOG excluidas antes de qualquer consulta,
prioridade de confianca: chave de acesso valida > CNPJ exato na API >
fuzzy de razao social com score alto e sem empate.

### "Finalizar digitalizacao" (`concluirDigitalizacao`)

Ainda faz sentido manter uma espera com timeout, mas **bem mais curta**
que a do plano anterior (que assumia latencia de n8n externo incerta):
agora o "tempo de espera" e majoritariamente o tempo do Tesseract.js
processar localmente (segundos, dependente de CPU do mini PC, nao de
rede) mais uma unica chamada HTTP rapida ao backend (a propria API de
clientes respondeu em bem menos de 1s nos testes, fuzzy match local em
PHP sobre ~40 registros e trivial). Reavaliacao proposta:
- Reduzir a janela de tolerancia de "~15-20s" (plano anterior) para algo
  na casa de alguns segundos por nota ainda pendente (ex.: 5-8s por nota,
  valor exato de ajuste empirico/produto, nao travado aqui) — o
  front-end so deve chamar `concluir-digitalizacao` depois que o proprio
  OCR client-side ja tiver terminado para todas as notas capturadas (o
  front-end sabe disso localmente, sem precisar de polling ao servidor
  para saber "ainda esta processando", diferente do cenario anterior com
  n8n).
- **Nao decidido nesta etapa**: se o backend ainda precisa validar/
  aguardar no servidor (ex.: nota ainda `PENDENTE` quando
  `concluir-digitalizacao` e chamado, por exemplo se o front-end
  desconectou/recarregou no meio do processamento local) ou se basta
  tratar `PENDENTE` remanescente como equivalente a `NAO_IDENTIFICADA`
  sem nenhuma espera server-side (mais simples, e coerente com a
  limitacao ja conhecida e registrada de nao ter retomada de sessao).
  Recomendacao deste plano: a segunda opcao (sem espera server-side) —
  simplifica bastante e e consistente com o novo cenario sem latencia
  externa incerta, mas fica registrado aqui como recomendacao, nao como
  decisao ja tomada, para confirmar na implementacao.

### Retry (JS do totem, sem componente server-side)

Se a chamada `identificar-cliente` falhar por erro de rede/timeout: o
proprio JavaScript do totem tenta novamente 2-3 vezes com backoff curto
(ex.: 1s, 2s, 4s), tudo dentro da mesma sessao do navegador, sem
persistir estado de tentativa no servidor. Se todas as tentativas
falharem, a nota fica `PENDENTE`/`ERRO` no banco (o que o backend ja
tiver conseguido gravar na ultima tentativa) e o front-end trata como
"nao identificada" para fins de fluxo, oferecendo confirmacao manual —
sem nenhum mecanismo de retry server-side (cron) necessario, porque nao
ha mais processamento externo lento cuja falha precise ser reprocessada
automaticamente depois; o proprio motorista, ainda na sessao, e quem
efetivamente teria a chance de "tentar de novo" (a nota inteira, se
necessario) antes de sair do totem.

## Variaveis de ambiente novas

- `CLIENTES_API_TOKEN` — token Bearer da API de clientes
  (`https://udlog.online/iaUdlog/api/v1/clientes`), **nunca exposto ao
  front-end/JS do totem** — usado exclusivamente dentro do backend PHP
  (unico chamador dessa API), nunca commitado, `.env.example` so com
  placeholder.
- Removidas do desenho anterior (nao existem mais): `N8N_CALLBACK_TOKEN`,
  `N8N_WEBHOOK_URL`.

## Seguranca (reforcado explicitamente)

- **Token da API de clientes nunca chega ao navegador**: toda chamada a
  `https://udlog.online/iaUdlog/api/v1/clientes` acontece dentro do
  backend PHP (`ClienteDao`/novo cliente HTTP dedicado, ex.
  `ClienteApiClient`), nunca no JS do totem. O endpoint
  `identificar-cliente` recebe do front-end so os CANDIDATOS extraidos
  pelo OCR local (CNPJs/razao social/chave), nunca o token.
- **IDOR**: `nota.php?acao=identificar-cliente` segue o MESMO padrao ja
  validado em `NotaController::buscarAtendimentoDoTotem` — totem so pode
  identificar cliente de nota do proprio atendimento (valida
  `id_atendimento` pertence ao `id_totem` autenticado, e que a nota
  `ordem` existe dentro desse atendimento), mensagem generica 404 sem
  vazar existencia de atendimento alheio. Confirmado explicitamente como
  requisito deste endpoint, nao uma sugestao a parte.
- **Entrada nao confiavel**: os candidatos de CNPJ/razao social vindos do
  front-end (resultado de OCR sobre imagem, sujeito a erro de
  reconhecimento) sao tratados como entrada nao confiavel de qualquer
  usuario — validados/normalizados no backend antes de qualquer uso,
  nunca usados para montar SQL diretamente (sempre prepared statements),
  nunca usados para decidir algo sem passar pelo criterio de confianca
  (CNPJ com DV valido, fuzzy com score alto e sem empate).
- **Logs**: nao registrar texto bruto de OCR nem CNPJ/CPF em texto claro
  em logs de erro tecnico (`ERRO`) — mesmo cuidado ja recomendado no
  plano anterior, mantido.
- **Rate limit da API de clientes**: 60 observado (janela nao
  confirmada). O cache local da listagem (acima) reduz o numero de
  chamadas em rajadas de multiplas notas do mesmo atendimento; ainda
  assim, se o backend fizer 1 chamada de CNPJ exato por nota (ate 5 notas
  por atendimento) mais paginas da listagem em cache-miss, o volume por
  atendimento e pequeno — nao ha necessidade de rate limiting adicional
  do lado do totem para esse fluxo especifico, mas fica como algo a
  monitorar se o volume de atendimentos simultaneos crescer (mesma
  postura de "nao ha rate limiting em nenhum endpoint hoje" ja registrada
  no parecer de seguranca anterior).

## O que NAO sera feito (mantido/reforcado)

- Nenhuma alteracao em Expedicao, CNH, CRLV, Talent, ou no fluxo do
  scanner Netum SD-2000 ja validado fisicamente.
- Nenhuma decisao unilateral sobre origem/sincronizacao de `tb_cliente`
  (pendencia de produto ja registrada antes desta demanda, nao tocada
  aqui).
- Nenhuma invencao de detalhe de Tesseract.js alem de conhecimento tecnico
  geral amplamente documentado (rodar em Web Worker, idioma portugues,
  reconhecimento de texto de imagem) — capacidades especificas da
  biblioteca (ex: precisao de deteccao de padrao numerico tipo CNPJ,
  performance real em hardware do mini PC) ficam registradas como "a
  confirmar na implementacao/teste fisico", nao presumidas.
- Nenhum codigo implementado, nenhuma migration escrita, nenhum commit ou
  push — esta e so a etapa de replanejamento.

## Pendencias / bloqueios

1. **Formato exato do payload `identificar-cliente` do lado do front-end**
   (se `cnpjs_candidatos` vem ja filtrado por regex no JS ou como texto
   bruto do OCR para o backend re-extrair) — depende do desenho do
   `frontend-especialista` para a integracao com Tesseract.js, fora do
   escopo desta tarefa (so backend).
2. **Origem/sincronizacao de `tb_cliente`** — pendencia de produto ja
   registrada antes desta demanda, nao resolvida aqui. Este plano usa a
   API externa (`CLIENTES_API_TOKEN`) como fonte direta para
   `identificar-cliente`, o que e viavel agora que o contrato foi
   confirmado — mas isso NAO decide a pendencia mais ampla de se
   `tb_cliente` (usado hoje no autocomplete do recebimento) deve ou nao
   ser sincronizado com essa mesma fonte; ambos os usos continuam
   podendo ter fontes diferentes ate uma decisao de produto explicita.
3. **Mecanismo de cache da listagem completa de clientes** (arquivo com
   TTL vs. APCu vs. outro) — nao decidido, incluindo se APCu esta
   disponivel no Hostgator de producao (nao confirmado).
4. **Se `PROCESSANDO` deve ser persistido no banco antes da chamada de
   identificacao** — nao decidido, avaliado como ganho marginal dado que
   nao ha retomada de sessao.
5. **Se `concluirDigitalizacao` ainda precisa de espera server-side para
   nota `PENDENTE` remanescente**, ou se basta tratar como
   `NAO_IDENTIFICADA` sem espera — recomendacao registrada (sem espera
   server-side), mas nao travada como decisao final.
6. **Valores exatos de parametros de ajuste empirico**: score minimo de
   fuzzy match, numero de tentativas de retry no JS do totem, TTL do
   cache local de clientes, janela de tolerancia (se houver) em
   `concluirDigitalizacao` — nenhum travado nesta etapa.
7. Se decidido persistir texto bruto do OCR (`texto_ocr` ou similar) para
   auditoria/depuracao, tratar como dado sensivel (nao logar em texto
   claro) — nao decidido se sera persistido.
8. Pendencias ja conhecidas de demandas anteriores continuam validas e
   nao foram tocadas por este replanejamento: IDOR remanescente em
   `AtendimentoController::selecionarOrdem`/`finalizar`, JPEG truncado
   aceito, handler global de excecao de banco ausente, retomada de
   sessao ao recarregar a pagina.

## Front-end replanejado (Tesseract.js)

### Fluxo apos "Usar imagem"

1. `confirmarUsoImagemNota()` continua identico (salva a imagem, contador,
   miniatura, `voltarParaVideoAoVivo()`) — nao ha nenhuma espera nova
   nesse ponto. `state.notasImagens` passa a ser array de objetos
   `{imagem, ordem, statusOcr}` (era array plano de base64), iniciando
   `statusOcr: PENDENTE`.
2. Logo apos o `push`, dispara-se (sem `await`, fire-and-forget do ponto
   de vista da UI) `processarOcrNota(indice)`: muda `statusOcr` para
   `PROCESSANDO`, envia a imagem para um Web Worker dedicado.
3. **Um unico Worker reaproveitado, com fila interna** processando as
   notas em ordem de chegada (nao instanciar um Worker novo por nota —
   evita competir por CPU/memoria com N workers carregando o modelo de
   idioma simultaneamente).
4. Extracao de candidatos de CNPJ (regex 14 digitos, com/sem mascara) e de
   chave de acesso (44 digitos) feita **dentro do proprio Worker**, logo
   apos o Tesseract.js devolver o texto reconhecido — o Worker devolve um
   payload ja enxuto (`{ordem, candidatosCnpj, candidatoChave, erro}`)
   para a thread principal, evitando uma segunda ida-e-volta de texto
   bruto grande.
5. A thread principal recebe via `onmessage` e chama
   `identificarClienteNota(...)` (endpoint `identificar-cliente` do
   backend) — tambem assincrono, sem bloquear nada.
6. **Polling eliminado do desenho anterior**: como o Worker e a chamada
   HTTP rodam na mesma sessao/navegador, o resultado chega via callback
   nativo (`onmessage`/`Promise`), sem necessidade de reconsultar
   periodicamente o servidor.
7. Retry simples no proprio JS (2-3 tentativas, backoff curto) so para
   falha de rede da chamada `identificar-cliente` — sem fila server-side.
8. `finalizarDigitalizacao()`: janela de tolerancia reduzida frente ao
   plano anterior (nao ha mais latencia de rede externa incerta) —
   valores exatos a ajustar empiricamente. Mesmo padrao de UX (desabilita
   botao, mensagem reaproveitando `#scannerStatus`, segue apos timeout).
9. Estado `ERRO` (nao alarmante) passa a cobrir tambem falha ao
   carregar/rodar o Worker ou baixar o modelo de idioma portugues
   (`por.traineddata`) — tratado como equivalente a nao identificado.
10. Atencao de performance: `por.traineddata` (alguns MB) precisa ser
    baixado na primeira execucao — como o totem e sempre o mesmo
    dispositivo fisico em kiosk, o cache do navegador deveria reter isso
    entre atendimentos (nao entre reinicios completos do Chromium).
    Recomendacao (nao decidida): iniciar o carregamento do Worker+modelo
    assim que a tela `rec_digitaliza` abre (`iniciarCameraScanner()`), em
    paralelo ao motorista posicionando a primeira nota, em vez de esperar
    a primeira captura.

### Interrupcao de OCR apos identificacao confirmada (refinamento adicional)

Requisito adicional (2026-09-04): assim que `identificar-cliente`
responder `IDENTIFICADA` para qualquer nota do atendimento, o front-end
para de gerar OCR/chamar o backend de identificacao para as notas
seguintes — a captura e o salvamento da imagem em si
(`confirmarUsoImagemNota()`) continuam inalterados, so o processamento de
OCR das notas subsequentes e que para.

- Nova flag `state.clienteJaIdentificadoNesteAtendimento` (booleana,
  default `false`), setada `true` exclusivamente no callback de sucesso
  de `identificarClienteNota(...)` quando a resposta trouxer
  `status: 'IDENTIFICADA'` (ou `ja_identificado_no_atendimento: true`,
  ver refinamento de backend acima). Resetada `false` nos mesmos pontos
  onde outro estado de `state` ja e resetado hoje: `novoAtendimento()` e
  o reset dentro de `iniciarRecebimento()`.
- Todo disparo de OCR (`processarOcrNota(indice)`, chamado logo apos o
  `push` em `confirmarUsoImagemNota()`) passa a checar essa flag antes de
  empurrar a nota para a fila do Worker — se `true`, pula OCR e vai
  direto para o fluxo normal de captura/salvamento.
- **Fila interna do Worker**: notas ainda nao retiradas da fila no
  momento da confirmacao sao simplesmente descartadas da fila (nao
  chegam a ser processadas) — controle trivial de aplicacao, sem
  envolver API alguma do Tesseract.js.
- **Nota ja em processamento no Worker no momento da confirmacao**:
  adotada como estrategia PRINCIPAL deixar o reconhecimento terminar
  naturalmente e, ao receber o resultado via `onmessage`, checar a flag
  antes de agir — se ja `true`, descarta o resultado sem chamar
  `identificar-cliente`. Avaliada como alternativa `Worker.terminate()`
  (API padrao de Web Workers do navegador, deveria abortar a execucao em
  andamento) — NAO adotada como estrategia principal porque mata o
  worker unico e compartilhado da fila (exigindo recriacao/recarregamento
  do modelo `por.traineddata` se ainda fosse necessario OCR depois, o que
  nao e o caso aqui) e porque a checagem de flag ja resolve o requisito
  sem lidar com estado de worker parcialmente terminado. Se o
  Tesseract.js expuser mecanismo proprio de cancelamento de job diferente
  do `Worker.terminate()` nativo, fica a confirmar na implementacao/
  documentacao oficial da versao instalada — nao presumido aqui.
- **UI**: mensagem de status reaproveitando o mesmo padrao visual de
  `#scannerStatus`/`mostrarStatusScanner` (ou elemento textual fixo
  proximo ao contador "Notas digitalizadas: X de 5"), sem popup/modal,
  indicando que o cliente ja foi identificado — texto exato nao decidido
  nesta etapa.
- Confirmado: nenhuma mudanca em `confirmarUsoImagemNota()` na parte de
  salvar imagem/contador/miniatura/`voltarParaVideoAoVivo()` — a
  alteracao fica isolada em "disparar ou nao `processarOcrNota()` depois
  do push".

### Pendencia relevante ja registrada (nao desta demanda)

`ia_development_state.md` (pendencias) ja registra que a captura do
scanner Netum SD-2000 teve um achado fisico de corte/resolucao
insuficiente em rodada anterior (posteriormente corrigido na demanda do
scanner) — a qualidade do OCR depende diretamente da nitidez da imagem
capturada; nao ha nada a fazer aqui alem de registrar essa dependencia.

## Proximo passo

Replanejamento de backend e front-end concluidos. Falta: revisao final de
seguranca e QA desta versao corrigida (sem n8n), antes de consolidar o
`/00-planejamento` completo e aguardar decisao do usuario para seguir a
`/01-implementacao`.

## Revisão final de segurança (v2, sem n8n)

**Veredito: nenhum achado crítico/bloqueante para `/01-implementacao`.**

- **Resolvido**: o risco crítico da versão anterior (exposição de imagem
  para servico externo n8n, autenticação de callback) deixou de existir —
  a imagem nunca sai da infraestrutura do totem/navegador nesta versão;
  o único tráfego de rede novo carrega apenas candidatos textuais
  extraídos, nunca a imagem. Confirmado como melhoria real de postura de
  segurança/LGPD.
- **Confirmado corretamente desenhado**: IDOR (`buscarAtendimentoDoTotem`
  exigido no novo endpoint), token da API de clientes nunca exposto ao
  front-end, candidatos de OCR tratados como entrada não confiável
  (normalizados/validados antes de qualquer uso, sempre prepared
  statement), recomendação de não logar OCR/CNPJ em texto claro.
- **Atenção (novo achado, não bloqueante)**: `identificar-cliente` pode
  funcionar como "oráculo" de existência de CNPJ/dados de cliente se
  chamado repetidamente com candidatos arbitrários (o requisito de posse
  do atendimento limita quem pode chamar, mas não quantas vezes nem
  quantos CNPJs por chamada). Recomendado, para a implementação: limitar
  a quantidade de `cnpjs_candidatos` aceitos por requisição (ex: mesmo
  teto de 5 notas por atendimento) e definir se o endpoint deve ser
  idempotente/bloqueado após resultado terminal já gravado.
- **Observação**: cache local da listagem de clientes (mecanismo ainda
  não decidido) deve, qualquer que seja a escolha, ficar fora do webroot
  público (mesma regra de `storage/atendimentos/`) e ter TTL curto — é
  dado de terceiro (carteira de clientes da UDLOG) sendo persistido,
  ainda que temporariamente.
- **Observação (fora do escopo backend)**: se o Tesseract.js/modelo de
  idioma for carregado via CDN externo em vez de vendorizado localmente,
  isso introduz uma dependência de terceiro em runtime (superfície de
  supply-chain) — considerar na implementação de front-end.

## Roteiro de teste atualizado (v2, sem n8n)

Casos REMOVIDOS da versão anterior (não se aplicam mais): callback
duplicado do n8n, timeout/retry de serviço externo, token de callback
inválido — não há mais webhook/callback/cron externo.

Casos ADAPTADOS/NOVOS cobertos no roteiro (marcados por executabilidade
**[FÍSICO]**/**[MOCK OK]**/**[BLOQUEADO]**, ver detalhe completo na
resposta do `qa-testes` desta etapa, a transcrever para o roteiro formal
de `/02-testes` quando a implementação existir):
- Chamada duplicada ao `identificar-cliente` para a mesma nota
  (idempotência) — **[MOCK OK]**.
- Tesseract.js falha ao carregar/processar no navegador — **[FÍSICO]**.
- Falha de rede na chamada `identificar-cliente` com retry simples no JS
  — **[MOCK OK]**.
- Extração via chave de acesso válida (prioridade máxima) — **[FÍSICO]**
  / variante **[MOCK OK]** chamando o endpoint direto com `chave_ocr`
  fixa.
- Exclusão de CNPJ da UDLOG — **[MOCK OK]**.
- Identificação por CNPJ exato contra a API real — **[MOCK OK]**/
  **[BLOQUEADO]** se não houver ambiente de homologação com dado
  conhecido.
- Fuzzy match de razão social (score alto+único vs. baixo/empatado,
  validando a direção do comportamento, não um número fixo) — **[MOCK
  OK]**.
- Estados por nota, aceitando ambos os comportamentos possíveis de
  `PROCESSANDO` persistido ou não — **[MOCK OK]**.
- "Finalizar digitalização" com OCR ainda rodando — **[FÍSICO]**.
- IDOR no endpoint `identificar-cliente` (atendimento/nota alheios) —
  **[MOCK OK]**.
- Falha ao baixar `por.traineddata` — **[FÍSICO]**.
- Múltiplas notas em sequência rápida, fila interna do Worker — **[FÍSICO]**.
- Candidatos vazios (OCR não reconheceu nada) → `NAO_IDENTIFICADA` —
  **[MOCK OK]**.
- Expiração/renovação do cache local de clientes — **[MOCK OK]**.
- Não regressão de Expedição/CNH/CRLV/Talent/scanner (smoke test) —
  **[FÍSICO]**.

Confirmado: não há suíte de testes automatizados configurada no projeto
(sem `phpunit.xml`, sem scripts de teste em `composer.json`) — roteiro
permanece manual, nenhum framework foi instalado.

## Próximo passo (final)

Planejamento (v2, sem n8n) concluído e revisado por todos os
especialistas relevantes (explorer, backend, frontend, security, QA).
Nenhum achado crítico/bloqueante impede seguir para `/01-implementacao`.
Aguardando decisão do usuário sobre as pendências não-bloqueantes listadas
(origem/sincronização de `tb_cliente`, mecanismo de cache, valores
empíricos de ajuste, limite de candidatos no endpoint) antes de iniciar a
implementação — nenhuma delas impede o início, mas devem ser resolvidas
durante ela.

## Casos de teste adicionais (refinamento de 2026-09-04 — early-stop e fuzzy match)

Casos novos a incorporar ao roteiro planejado (nenhum executado — nada
implementado ainda), cobrindo o refinamento de early-stop por atendimento
e o critério de confiança formalizado do fuzzy match:

- **Cliente encontrado na primeira nota**: capturar nota 1 com CNPJ exato
  válido → `IDENTIFICADA` com `ja_identificado_no_atendimento: true`;
  capturar notas 2-5 em seguida → nenhuma delas deve disparar OCR nem
  chamar `identificar-cliente` (verificar ausência de chamada de rede
  para essas notas). **[MOCK OK]** para a parte de backend (checar que o
  passo 0 do endpoint responde sem tocar na API externa) / **[FÍSICO]**
  para confirmar que o front-end realmente não dispara o Worker para as
  notas seguintes.
- **Cliente encontrado apenas em nota posterior** (ex.: nota 1 e 2 sem
  match, nota 3 identifica): notas 1 e 2 devem ter passado normalmente
  por OCR e chamada ao backend (`NAO_IDENTIFICADA`); a partir da
  identificação na nota 3, notas 4-5 não disparam mais OCR. **[MOCK OK]**
- **Cancelamento dos OCRs pendentes**: simular nota já em processamento
  no Worker no momento em que uma nota anterior confirma identificação —
  verificar que o resultado dessa nota em processamento, ao chegar, é
  descartado (não gera chamada a `identificar-cliente`). **[FÍSICO]**
  (depende de timing real do Worker) — variante **[MOCK OK]** simulando
  a corrida via mock do Worker/timers controlados.
- **Nenhuma nova consulta após identificação**: confirmar, via
  instrumentação/observação das chamadas HTTP feitas durante um
  atendimento completo (até 5 notas), que o número de chamadas à API
  externa de clientes não excede o necessário para identificar (idealmente
  1 chamada, mais eventuais chamadas de listagem para fuzzy se não houve
  match direto de CNPJ) — nunca 5 chamadas completas se a identificação
  ocorreu antes da última nota. **[MOCK OK]**
- **Variações `CROMIX` e `CRMEX`**: com um cliente cadastrado como
  `CROMEX` (ou nome de teste equivalente em ambiente de homologação, não
  usar dado real de produção no teste), simular razão social candidata
  `CROMIX` e depois `CRMEX` separadamente — ambas devem resultar em
  `IDENTIFICADA` automática, desde que sejam candidato único acima do
  limiar e com margem suficiente sobre o segundo colocado (validar a
  *direção* do comportamento, não um score numérico fixo). **[MOCK OK]**
  (requer dado de teste controlado, nunca produção real).
- **Dois nomes semelhantes sem associação automática**: simular dois
  clientes cadastrados com razões sociais muito parecidas entre si (ex.:
  duas filiais/variações do mesmo grupo) e uma razão social candidata que
  fique ambígua entre os dois (score alto para ambos, sem margem
  suficiente) — deve resultar em `NAO_IDENTIFICADA` (confirmação manual),
  nunca escolher um dos dois automaticamente. **[MOCK OK]**
- **Continuidade normal da captura até cinco notas**: mesmo com
  identificação ocorrendo cedo (ex.: nota 1) ou tarde (ex.: nota 5) ou
  nunca (todas `NAO_IDENTIFICADA`), confirmar que a captura/salvamento das
  5 notas continua funcionando normalmente em todos os casos — contador,
  miniaturas, limite de 5, botão "Finalizar digitalização" — sem nenhuma
  regressão introduzida pelo early-stop de OCR. **[FÍSICO]**

## Resultado da implementação (01-implementacao)

Data: 2026-09-04
Etapa: 01-implementacao

### O que foi implementado

**Backend:**
- `sql/migrations/002_status_ocr_atendimento_nota.sql` (novo, idempotente):
  adiciona `status_ocr` (ENUM PENDENTE/PROCESSANDO/IDENTIFICADA/
  NAO_IDENTIFICADA/ERRO) e `processado_em` em `tb_atendimento_nota`, mais
  índice. `sql/schema.sql` atualizado para instalações novas.
- `.env.example`: `CLIENTES_API_TOKEN=` (placeholder vazio).
- `app/Rn/ClienteApiClient.php` (novo): curl nativo (mesmo padrão de
  `TalentClient`), `buscarPorCnpj()` e `listarTodos()` (paginação
  completa, cache em arquivo `storage/cache/` com TTL de 60s).
- `util/CnpjValidador.php` (novo): normalização + validação de DV (módulo
  11) + exclusão dos CNPJs UDLOG.
- `util/RazaoSocialMatcher.php` (novo): normalização (uppercase/
  transliteração/sufixos societários) + fuzzy match via `similar_text()`
  com janela de palavras (correção necessária: comparar só a string
  inteira penalizava candidatos curtos contra razões sociais longas).
  `LIMIAR_MINIMO=80`, `MARGEM_MINIMA=15` (ajuste empírico, validado contra
  CROMIX→CROMEX 83,33% e CRMEX→CROMEX 90,91%).
- `app/Dao/AtendimentoNotaDao.php`: `buscarPorAtendimentoEOrdem`,
  `algumaNotaComStatusIdentificada`, `atualizarResultadoOcr`.
- `app/Rn/NotaFiscalRn.php`: `identificarCliente()` — sequência completa
  (passo 0 early-stop → chave de acesso com prioridade máxima → CNPJ
  exato → fuzzy → erro técnico → persistência), `MAX_CNPJS_CANDIDATOS=10`.
- `app/Controller/NotaController.php`: novo método `identificarCliente()`
  — IDOR via `buscarAtendimentoDoTotem` + validação de posse da nota.
- `public/api/nota.php`: novo `case 'identificar-cliente'`.

**Frontend:**
- Tesseract.js vendorizado localmente (sem CDN) em
  `public/totem/assets/tesseract/` (7 arquivos: lib, worker, 2 pares de
  core wasm LSTM, modelo de idioma português).
- `public/totem/assets/ocr-worker.js` (novo): Web Worker dedicado,
  extrai candidatos de CNPJ/chave/razão social dentro do próprio worker.
- `public/totem/assets/app.js`: `iniciarOcrWorker()` (chamado dentro de
  `iniciarCameraScanner()`, pré-carrega o modelo em paralelo à primeira
  captura — confirmado por leitura de código), `processarOcrNota()`
  (fila interna, early-stop reaproveitando a flag JÁ EXISTENTE
  `state.clienteIdentificado`, sem criar flag paralela),
  `identificarClienteNota()` (retry 2x com backoff, mensagem "Cliente
  identificado" via `mostrarStatusScanner`).
- `public/totem/index.php`: incluído script do Tesseract.js.

**Segurança:** revisão completa sem achado crítico. 1 achado de atenção
(`.gitignore` sem cobertura para `storage/cache/`) corrigido — `.gitignore`
agora ignora `storage/` inteiro.

**Testes:** `tests/manual/teste_identificar_cliente.php` (novo, PHP CLI,
sem PHPUnit — projeto não tem framework de teste configurado). 12 grupos
de casos, 29 asserts, **29/29 passaram** contra o banco local real, com
fake do `ClienteApiClient` (sem tocar rede real). Cobre: CNPJ exato
identifica, exclusão UDLOG, chave de acesso prioriza CNPJ do emitente,
fuzzy CROMIX/CRMEX→CROMEX, ambiguidade não identifica, cliente na
primeira nota, cliente só em nota posterior, nenhuma nova consulta após
identificação, candidatos vazios, early-stop/passo 0, falha técnica→ERRO,
IDOR. Dados de teste criados e limpos automaticamente (confirmado: 0
resíduos).

### Verificações executadas
- `php -l` em todos os arquivos PHP criados/alterados: sem erros.
- `node --check` em `app.js` e `ocr-worker.js`: sem erros.
- Fluidez confirmada por leitura de código: nenhum `await` foi introduzido
  no caminho de captura/salvamento de imagem — todo o OCR/identificação
  roda em paralelo (Web Worker + fetch assíncrono).
- Confirmado por leitura direta (`git status`/`git diff --stat`): nenhum
  arquivo de Expedição, CNH, CRLV, Talent ou do scanner Netum foi tocado.

### Limitações conhecidas (validação física pendente)
- Nenhum teste real em navegador/hardware foi possível neste ambiente.
  Pontos de maior incerteza técnica, a validar fisicamente:
  - Se `Tesseract.createWorker` funciona corretamente quando chamado de
    DENTRO de um Web Worker já existente (`ocr-worker.js`) — Workers
    aninhados são suportados por spec/Chromium, mas não foi executado de
    fato.
  - Tempo real de processamento por nota no mini PC do totem.
  - Precisão real de reconhecimento de CNPJ/chave/razão social em notas
    fiscais reais.
  - Cancelamento efetivo de OCR pendente em cenário de captura rápida de
    múltiplas notas (fila + descarte de resultado tardio).
  - Continuidade da captura até 5 notas em todos os cenários de
    identificação (cedo/tarde/nunca).
- `CLIENTES_API_TOKEN` real não testado neste ambiente (sem credencial
  local) — o fluxo de erro técnico foi validado com token vazio contra a
  API real, mas a identificação real por CNPJ/fuzzy contra dados reais
  não foi exercida.
- Heurística de extração de "razão social candidata" no `ocr-worker.js`
  é uma escolha de implementação (não formalmente decidida no handoff) —
  pode precisar de ajuste após teste físico.
- `concluirDigitalizacao` (backend) não foi alterado — segue sem espera
  adicional para nota `PENDENTE` remanescente, decisão registrada como
  "recomendação, não travada" desde o planejamento.

### Pendências não-bloqueantes (já conhecidas ou registradas nesta rodada)
- Origem/sincronização de `tb_cliente` continua não decidida (não tocada).
- IDOR remanescente em `selecionarOrdem`/`finalizar` (demanda anterior,
  não desta).
- `LIMIAR_MINIMO`/`MARGEM_MINIMA`/`CACHE_TTL_SEGUNDOS` são valores
  iniciais, a ajustar empiricamente com dados reais de produção.

### Confirmação de escopo
Nenhuma alteração em Expedição, CNH, CRLV, Talent ou na captura já
validada do scanner Netum SD-2000.

### Próximo passo
Rodar `/02-testes` (incluindo o roteiro físico `[FÍSICO]` documentado
acima e no handoff) antes de `/03-revisao`.

## Correção pós-teste físico — Worker aninhado quebrava o OCR

Data: 2026-09-04

### Achado (teste físico real, 5 capturas)
Todas as notas ficaram presas em `status_ocr = PENDENTE` — o endpoint
`identificar-cliente` nunca foi chamado. Console do navegador revelou:
```
Uncaught SyntaxError: Failed to execute 'importScripts' on
'WorkerGlobalScope': The URL 'tesseract/worker.min.js' is invalid.
```

### Causa raiz
`ocr-worker.js` era um Web Worker customizado que, por dentro, chamava
`Tesseract.createWorker()` — mas o Tesseract.js já cria SEU PRÓPRIO Worker
internamente (via blob URL) para rodar a engine. Isso resultava num
Worker aninhado dentro de outro Worker, e o worker interno do
Tesseract.js falhava ao resolver o caminho relativo de
`importScripts('tesseract/worker.min.js')` a partir do contexto de um
blob worker aninhado. O erro era capturado silenciosamente pelo
`try/catch` de `ocr-worker.js` (`erro: true`), e o código em `app.js`
pulava a chamada a `identificar-cliente` quando `resultado.erro` era
`true` — deixando a nota presa em `PENDENTE` para sempre.

### Correção aplicada
- **Eliminado o Worker customizado aninhado**: `ocr-worker.js` deletado
  (nunca chegou a ser commitado). `Tesseract.createWorker('por', 1, {
  workerPath: 'assets/tesseract/worker.min.js', corePath:
  'assets/tesseract', langPath: 'assets/tesseract', gzip: true })` agora
  é chamado DIRETAMENTE na thread principal (`app.js`) — o Tesseract.js
  já gerencia seu próprio worker interno para não bloquear a UI, sem
  precisar de um Worker customizado por cima.
- `extrairCandidatos()` (regex de CNPJ/chave/razão social) movida para
  `app.js`, roda na thread principal (é só processamento de string,
  rápido, não é a parte pesada).
- **Bug secundário corrigido**: nota com falha real de OCR agora sempre
  chama `identificar-cliente` com candidatos vazios (→
  `NAO_IDENTIFICADA`, já validado pelos testes automatizados), em vez de
  ficar presa em `PENDENTE`.
- `node --check` OK. Nenhuma alteração em Expedição/CNH/CRLV/Talent/
  scanner/backend — só `app.js` (mais a remoção de `ocr-worker.js`).

### Validação
Ainda pendente novo teste físico do usuário — repetir o roteiro de 5
capturas, confirmando no console a ausência do erro de `importScripts` e
que as notas saem de `PENDENTE` no banco.

## Diagnóstico técnico do OCR com imagem real (rotação/pré-processamento)

Data: 2026-09-04

### Metodologia
Chrome headless, mesmos arquivos vendorizados de produção
(`public/totem/assets/tesseract/`), mesma configuração de
`Tesseract.createWorker('por', 1, {...})` usada em `app.js`. Testada
contra nota fiscal real (`storage/atendimentos/2026-09-04/TST0A01_155049/nota_01.jpg`,
3264x2448, cópia local em scratch — original não alterado/movido). 4
rotações (0/90/180/270) em baseline, depois 6 pré-processamentos (grayscale,
contrast, binarize, scale75, scale125) na rotação vencedora.

### Resultado (CNPJ mascarado, razão social não reproduzida)

| Rotação | Pré-proc | CNPJ válido | CNPJ = esperado | Razão social (similaridade) | Chave válida | Tempo |
|---|---|---|---|---|---|---|
| 0/90/180 | baseline | não | — | 0,17–0,33 | não | 5,4–9,3s |
| 270 | baseline | sim | sim | 0,83 | não | 4,7s |
| 270 | grayscale | sim | sim | 0,83 | não | 4,5s |
| 270 | contrast | sim | sim | 0,17 | não | 4,5s |
| 270 | binarize | não | — | 0,83 | não | 4,0s |
| **270** | **scale75** | **sim** | **sim** | **0,83** | não | **3,7s (melhor)** |
| 270 | scale125 | sim | sim | 0,83 | não | 5,9s |

### Conclusão
**Rotação 270° + escala 75%** é a melhor combinação: CNPJ correto extraído
(bate com o esperado), razão social legível com boa similaridade, e o
tempo mais rápido entre as combinações válidas (~3,7s por nota) —
compatível com a exigência de fluidez (não trava a captura sequencial).

### Achado adicional (não corrigido, só diagnóstico)
Em NENHUMA das 9 combinações a chave de acesso (44 dígitos) passou na
validação — nem o próprio dígito verificador da chave, nem o CNPJ embutido
nela. Isso persiste mesmo quando o CNPJ solto (via regex no corpo do
texto) foi extraído corretamente e a razão social ficou legível — sugere
um problema separado, específico do trecho da imagem onde a chave é
impressa (código de barras/texto pequeno) ou do regex/parsing usado para
extrair a sequência de 44 dígitos, não relacionado à rotação geral da
nota. Não investigado nesta rodada — fica como pendência para diagnóstico
focado, caso a identificação por chave de acesso seja considerada
prioritária (a identificação por CNPJ solto já funciona bem com a
correção de rotação/escala acima).

### Ambiente do diagnóstico
Chrome headless via `chrome.exe --headless=new --disable-gpu`, script de
teste em diretório scratch fora do repositório. Nenhum arquivo do projeto
alterado. Imagem real usada só como cópia local, original preservado.

### Próximo passo
Aguardando decisão do usuário: implementar rotação fixa de 270° (mais
simples, mas presume que todas as notas serão capturadas na mesma
orientação física) ou detecção automática de orientação (mais robusto,
mais complexo) + escala 75% antes do reconhecimento.

### Confirmação cruzada com segunda imagem real

Uma segunda imagem real (`storage/atendimentos/2026-09-04/TST0A01_154738/nota_01.jpg`)
foi testada nas 4 rotações + grayscale — resultado consistente: **só
270° produziu CNPJ válido e batendo com o esperado** (0°/90°/180°
falharam nas duas imagens, sem exceção). Isso reforça que a causa raiz é
real (orientação de captura), não um acaso da primeira imagem.

Achado adicional confirmado nesta segunda rodada: a chave de acesso (44
dígitos) nunca validou em NENHUMA das 14 combinações testadas (2
imagens), mesmo quando CNPJ solto e razão social já estavam corretos —
reforça que a extração de 44 dígitos consecutivos via OCR é
estruturalmente frágil (1 único dígito errado entre 44 já invalida o
resultado), mesmo quando textos mais curtos (CNPJ, razão social) já são
reconhecíveis. Recomendação registrada (não decidida): tratar a chave de
acesso como fonte de identificação NÃO confiável neste pipeline de OCR
client-side, mesmo após corrigir a rotação — decisão de produto pendente.

Ressalva: só 2 imagens de ~13 disponíveis foram testadas (por tempo). 270°
foi consistente nas duas, mas não há garantia de que seja universal para
toda captura do Netum SD-2000 — pode depender de como o motorista
posiciona a nota fisicamente. Recomenda-se validar com mais amostras
antes de fixar 270° como rotação única em produção, ou considerar detecção
automática de orientação.

### Próximo passo (atualizado)

Aguardando decisão do usuário: implementar rotação fixa de 270° (mais
simples, mas presume orientação de captura consistente — confirmado em 2
amostras, não em todas) ou detecção automática de orientação (mais
robusto a variação de posicionamento, mais complexo/lento) + escala 75%
(ou grayscale, resultado equivalente) antes do reconhecimento. Decidir
também o tratamento da chave de acesso (ignorar como fonte de
identificação, dado o achado de fragilidade estrutural).

## Implementação das correções aprovadas (rotação 270° + remoção da chave)

Data: 2026-09-04

### O que foi implementado

**Front-end** (`public/totem/assets/app.js`):
- `rotacionarImagem270()`: gera cópia via `<canvas>` rotacionada 270°
  (troca largura/altura), usada SÓ como entrada de `worker.recognize()`
  — nunca persistida nem enviada ao backend. A imagem original salva via
  `nota.php?acao=processar` não foi tocada.
- Extração de chave de acesso (regex 44 dígitos) removida de
  `extrairCandidatos()` — retorna só `cnpjsCandidatos`/
  `razaoSocialCandidata`.
- `identificarClienteNota()`: payload sempre envia `chave_ocr: null`.

**Backend** (`app/Rn/NotaFiscalRn.php`, `app/Controller/NotaController.php`):
- `identificarCliente()`: removido o passo de prioridade da chave de
  acesso. Sequência agora: early-stop → CNPJ exato (de
  `cnpjs_candidatos`, única fonte) → fuzzy de razão social → erro →
  persistência.
- `chaveValida()`/`decodificarChave()`/`calcularDV()` preservados
  intactos — continuam usados pelo fluxo antigo `processarLeitura`
  (fora do escopo desta demanda).
- `chave_ocr` continua aceito no payload por compatibilidade, mas
  totalmente ignorado.
- `tests/manual/teste_identificar_cliente.php`: caso de "prioridade da
  chave" reescrito para confirmar que a chave é ignorada (identifica pelo
  CNPJ solto, não pelo da chave). **29/29 testes passaram.**

### Teste com imagens reais (21 notas fiscais reais, Chrome headless, mesmo Tesseract.js de produção)

- **8/21** identificaram CNPJ válido corretamente (confirmado visualmente
  batendo com o CNPJ impresso na nota).
- **13/21** não encontraram candidato válido (DV inválido ou nenhum
  candidato) — corretamente tratadas como "não identificado", seguindo
  para confirmação manual (nunca identificação incorreta).
- Tempo por nota: 4,0s–9,7s — aceitável, não trava a captura sequencial.

### Achado registrado (risco já aceito pela decisão de rotação fixa)
Inspeção visual confirmou que **notas do mesmo atendimento podem ter
orientação física diferente entre si** (uma nota ficou de cabeça para
baixo mesmo após a rotação de 270° aplicada). Isso explica por que a taxa
de identificação não é 100% — mas o comportamento nesses casos continua
seguro: cai em confirmação manual, nunca identifica errado. Não corrigido
(fora do escopo — a decisão de usar rotação fixa, não detecção automática,
já foi tomada e aceita esse risco).

### Verificações
- `node --check public/totem/assets/app.js`: sem erros.
- `php -l` em todos os arquivos backend alterados: sem erros.
- Confirmado por leitura de código: nenhum `await` bloqueante introduzido
  na captura da próxima nota (rotação e recognize continuam
  fire-and-forget).
- Nenhuma alteração em Expedição/CNH/CRLV/Talent/scanner Netum.

### Validação pendente
Teste físico real no totem (mini PC, touchscreen, Netum SD-2000) ainda
não realizado neste ciclo — toda a validação desta rodada foi feita via
Chrome headless com imagens já capturadas anteriormente pelo hardware
real. Recomenda-se nova rodada de captura ao vivo para confirmar
comportamento end-to-end (captura → rotação → OCR → identificação →
confirmação manual quando aplicável).

### Próximo passo
Rodar `/02-testes` (incluindo validação física ao vivo) antes de
`/03-revisao`.

## Correção pós-teste físico — concluirDigitalizacao não considerava identificação via OCR

Data: 2026-09-04

### Achado (teste físico, passo 1 do roteiro)
Motorista viu "Cliente identificado" na tela de digitalização, mas ao
clicar "Finalizar digitalização" foi levado para confirmação manual em
vez de pular para CNH.

### Causa raiz
`AtendimentoController::concluirDigitalizacao()` decidia a próxima tela
usando só `AtendimentoNotaDao::algumaIdentificada()` — método antigo que
faz `JOIN` com `tb_cliente` LOCAL (usado pelo fluxo de chave de acesso). O
novo fluxo de OCR identifica via API externa e persiste em `status_ocr`,
sem popular `tb_cliente` local — o método correto
(`algumaNotaComStatusIdentificada()`, baseado em `status_ocr`) já existia
mas nunca foi ligado a `concluirDigitalizacao()`.

### Correção aplicada
`concluirDigitalizacao()` agora considera identificado se QUALQUER UMA das
duas fontes indicar (`algumaNotaComStatusIdentificada()` OU
`algumaIdentificada()`) — preserva o fluxo antigo de chave de acesso
intacto, e corrige o novo fluxo de OCR. Validado via script direto contra
o banco local (2 cenários: só novo fluxo, só fluxo antigo) — ambos
resultam corretamente em `rec_cnh`. `php -l` sem erros.

### Pendência
Validação ainda não repetida via HTTP/front-end real (só via script
direto contra o banco) — aguardando reteste físico do usuário.

## PAUSADO em 2026-09-04 — aguardando comando do usuário para continuar

### Onde paramos
Etapa `/02-testes` em andamento. Já concluído nesta etapa:
- Verificações automatizadas: 29/29 testes passaram, `php -l`/`node --check`
  sem erros, revisão de segurança sem achado crítico, `explorer` confirmou
  não-regressão (Expedição/CNH/CRLV/Talent/scanner intocados).
- Teste físico passo 1 (CNPJ exato na 1ª nota): **REPROVOU inicialmente**
  (bug: `concluirDigitalizacao()` usava método antigo baseado em
  `tb_cliente` local em vez do novo baseado em `status_ocr`) — **já
  corrigido** (validado via script direto contra o banco, ainda não
  revalidado via HTTP/navegador real).
- Teste com nota da LDC: identificação falhou. Diagnóstico técnico
  (Chrome headless + Tesseract.js real) revelou 2 achados novos, ainda
  SEM correção implementada:
  1. **Orientação física instável nota-a-nota dentro do mesmo
     atendimento** (3 fotos da mesma nota precisaram de 3 rotações
     diferentes entre si — 270°/90°/180°) — risco já aceito na decisão de
     rotação fixa, mas mais severo que o esperado na prática.
  2. **Bug no `CNPJ_REGEX` de `app.js`**: não tolera múltiplos caracteres
     de separação que o OCR às vezes insere entre grupos de dígitos do
     CNPJ — mesmo com rotação/texto corretos, o CNPJ correto não é
     capturado. Bug corrigível (diferente do achado 1, que é limitação
     inerente de orientação).

### Pendente de decisão do usuário (perguntado, ainda sem resposta)
Corrigir o regex do CNPJ agora, ou seguir o roteiro de teste físico
primeiro e tratar como pendência registrada.

### Roteiro físico do `/02-testes` — passos ainda não executados
2. Cliente identificado só em nota posterior.
3. Razão social com erro leve de OCR (CROMIX/CRMEX → CROMEX).
4. Dois clientes com nomes semelhantes (sem associação automática).
5. Nota ilegível (fallback manual).
6. Captura sequencial de 5 notas com OCR processando.
7. Early-stop (sem novo OCR/consulta após identificar).
8. Imagens seguintes continuam salvando normalmente.
9. Falha/timeout/indisponibilidade da API de clientes.
10. Fluidez de câmera/botões/navegação durante os 4-10s de OCR.
11. Console/rede/memória/ausência do token no navegador.
12. Imagem original sem alteração, só a cópia do OCR rotacionada.

(Passo 1 já foi feito e corrigido — precisa só de reteste rápido para
confirmar via navegador real, já que a correção só foi validada via
script direto contra o banco.)

### Arquivos com mudanças NÃO commitadas (working tree)
Toda a implementação desta demanda (backend, frontend, testes, migration,
vendorização do Tesseract.js) está no working tree local, sem commit nem
push — conforme instruído em todas as etapas até aqui.

### Próximo passo
Aguardar o usuário retomar com um comando explícito (retomar
`/02-testes` a partir do passo 2, ou decidir sobre a correção do regex
de CNPJ primeiro).
