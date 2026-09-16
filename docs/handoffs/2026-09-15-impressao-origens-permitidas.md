# Handoff — impressao-origens-permitidas

Data: 2026-09-15
Etapa: 00-planejamento

## O que foi pedido

Planejar a configuracao segura de `origensPermitidas` do servico local
de impressao (CORS + Private Network Access), mantendo allowlist exata,
comportamento fail-closed, servico vinculado so a `127.0.0.1`, sem
dependencia de caminho, com desenvolvimento e producao configurados
separadamente. Enderecos informados pelo usuario:
- Dev: pagina `http://localhost:8080/totem`, origem
  `http://localhost:8080`.
- Producao: AINDA NAO validada — pagina prevista
  `http://udlog.online/totem`, origem a confirmar so apos deploy real
  (`http://udlog.online` ou, se HTTPS, `https://udlog.online` — origens
  diferentes, nunca habilitar as duas sem necessidade comprovada).

Nenhuma implementacao, alteracao de `.env`/`config.json` real,
habilitacao de producao, impressao, chamada ao Talent, alteracao de
banco, exposicao do servico a `0.0.0.0`, ou commit/push nesta etapa.

## Comportamento atual comprovado (leitura direta de codigo)

- **Fonte da configuracao**: `servico-impressao-local/config/config.json`
  (gitignored, nunca versionado — so `config.example.json` e template
  versionado, hoje com `origensPermitidas: []`). Carregado por
  `src/config.js::construirConfig()`, unico ponto do servico que le
  `process.env`/arquivo de config — nenhum outro modulo le direto.
- **Validacao/normalizacao**: `validarOrigensPermitidas(valor)` so exige
  array de strings (pode ser vazio) — **sem nenhuma normalizacao**
  (sem trim, sem lowercase, sem remocao de barra final). Se malformada
  (nao-array ou item nao-string), lanca excecao na INICIALIZACAO do
  servico — fail-closed real: o processo nem sobe com config invalida.
- **Middleware CORS/PNA** (`src/middleware/cors.js`, `corsEPna`),
  aplicado GLOBALMENTE em `server.js` (`app.use(corsEPna)`) ANTES de
  qualquer rota:
  - `origemAutorizada = typeof origem === 'string' && config.origensPermitidas.includes(origem)`
    — comparacao EXATA por igualdade de string completa (protocolo+
    host+porta). **Nenhum wildcard/startsWith/endsWith/regex existe no
    codigo atual** — a comparacao ja rejeita naturalmente variacoes
    como `http://localhost:8080.evil.example` (string diferente, sem
    necessidade de mudanca de codigo).
  - Se `origem` autorizada: `Access-Control-Allow-Origin: <origem
    exata>` + `Vary: Origin`. **Nunca `*`** em nenhum caminho do codigo.
  - PNA: `Access-Control-Allow-Private-Network: true` so quando a
    origem TAMBEM ja e autorizada (nunca libera PNA sozinho).
  - `OPTIONS`: sempre seta `Access-Control-Allow-Methods: GET, POST,
    OPTIONS` + `Access-Control-Allow-Headers: Content-Type,
    Authorization` + `Access-Control-Max-Age: 600`; 403 vazio se
    `origem` presente e nao autorizada, senao 204.
  - Requisicao normal com `origem` nao autorizada: 403 JSON `{erro:
    'Origem nao autorizada (CORS).'}` — mensagem fixa, nunca ecoa a
    origem recebida nem o token.
  - **Ausencia de header `Origin`** (ex. curl/PowerShell local): a
    checagem de CORS e pulada inteiramente, requisicao segue para
    `next()` — mas as rotas protegidas (`/impressoras`, `/imprimir`)
    ainda exigem o token Bearer via middleware `autenticar` (camada
    INDEPENDENTE, aplicada depois do CORS, so nessas 2 rotas).
  - `Origin: null` (string literal, ex. iframe sandboxed/`file://`): cai
    no `includes()` normal — so passaria se a string `"null"`
    estivesse literalmente na allowlist (nunca deve estar).
- **`GET /saude`**: sem autenticacao por token, mas sujeita ao mesmo
  CORS global. Resposta sem dado sensivel (`status`/`servico`/`versao`).
- **Bind**: `host: '127.0.0.1'` hardcoded em `src/config.js`, comentario
  explicito confirma "requisito de seguranca fixo, nao uma opcao de
  config" — **nao ha campo em `config.json` que possa sobrescrever
  isso**.
- **Relacao com autenticacao por token**: CORS (origem do navegador) e
  autenticacao (token Bearer) sao camadas INDEPENDENTES — uma nao
  substitui a outra. Habilitar uma origem no navegador NUNCA torna o
  servico acessivel de fora da maquina; a garantia real de isolamento
  de rede e o bind fixo em `127.0.0.1`.
- **Documentacao afetada**: `servico-impressao-local/config/config.example.json`
  (comentario ja explica o comportamento fail-closed atual);
  `docs/deploy-checklist.md` (pendencia ja registrada: "permanece
  vazio/fail-closed por enquanto"); `servico-impressao-local/README.md`
  (secoes 2.5 e 4.5, a confirmar/atualizar); `ia_development_state.md`
  (pendencia ja registrada, linha ~138).
- **Compatibilidade com o lado PHP**: confirmado que `Util\ConfiguracaoServicoImpressao`
  e as variaveis de `.env` (`IMPRESSAO_LOCAL_URL`/`TOKEN`, etc.) NAO
  tem nenhuma relacao com `origensPermitidas`/CORS/PNA — isso e
  resolvido inteiramente entre o navegador do totem e o servico Node
  local, sem nenhuma mudanca necessaria no backend PHP.

## Riscos encontrados (analise do security-especialista)

Nenhum achado bloqueante. 2 observacoes de baixo risco, sem necessidade
de mudanca de codigo para esta demanda:
1. Comparacao de origem e case-sensitive e sem normalizacao — na
   pratica sem risco real, pois o header `Origin` e sempre normalizado
   pelo proprio navegador (protocolo/host em minusculas, porta so
   explicita quando nao e a padrao do protocolo) antes de ser enviado.
2. Porta implicita vs explicita (`http://localhost` vs
   `http://localhost:80`) — nao e vetor de bypass, so um detalhe a nao
   confundir ao escrever a allowlist (nunca adicionar `:80`/`:443` "por
   garantia").

Nenhuma mudanca de codigo necessaria no mecanismo de comparacao/CORS/PNA
— o desenho atual (comparacao exata via `Array.includes()`, bind fixo
em `127.0.0.1`, headers minimos necessarios, PNA condicionado a origem
ja autorizada, mensagens de erro sem vazamento) ja atende integralmente
aos requisitos de seguranca desta demanda.

**Pendencia de verificacao registrada pelo security-especialista**:
`server.js` nao foi explorado em profundidade nesta rodada em busca de
um eventual logger de acesso HTTP generico que gravasse headers
completos (incluindo `Origin`/`Authorization`) — o orquestrador ja leu
`server.js` por completo no mapeamento inicial e confirma que NAO ha
nenhum logger de requisicao alem de `console.error` em erro nao tratado
e `console.log` no start do servico (porta/host) — pendencia
considerada RESOLVIDA por essa leitura adicional, sem achado.

## Configuracao exata proposta

### Desenvolvimento

```json
"origensPermitidas": ["http://localhost:8080"]
```

Exatamente essa string, nenhuma outra. **Nao incluir**:
`http://127.0.0.1:8080`, `http://localhost`, `http://localhost:80`,
`http://localhost:8080/totem`, `http://localhost:8080/`, `*`, `null` —
nenhuma dessas variacoes deve entrar sem uso e confirmacao separada
(o header `Origin` nunca inclui path, entao variacoes com `/totem` sao
impossiveis de qualquer forma, nao so indesejadas).

### Producao — estrategia futura (NAO habilitar agora)

1. Completar o deploy real no Hostgator, mantendo
   `origensPermitidas: []` (fail-closed) no `config.json` de producao
   ate este processo terminar.
2. Abrir a pagina real do totem em producao (a partir do navegador que
   efetivamente sera usado no totem fisico) e observar a barra de
   endereco FINAL apos qualquer redirecionamento automatico (http→
   https, com/sem `www`) — usar a aba Network do DevTools (primeira
   requisicao de navegacao) para confirmar a origem definitiva, nao so
   confiar visualmente na barra.
3. Registrar a origem exata confirmada (protocolo+dominio, sem path,
   sem barra final).
4. So entao preencher `origensPermitidas` no `config.json` do mini PC
   de producao com essa UNICA origem confirmada, e reiniciar o servico.
5. Nunca habilitar `http://udlog.online` E `https://udlog.online`
   simultaneamente sem necessidade comprovada (ex. redirecionamento
   intermediario real que o navegador de fato visita antes do HTTPS
   final — investigar, nunca presumir).
6. Repetir esse processo sempre que houver mudanca de infraestrutura
   de producao (dominio, certificado, proxy reverso) que possa alterar
   a origem observada.

## Arquivos que precisarao ser alterados (futura /01-implementacao)

- `servico-impressao-local/config/config.json` (local de dev — real,
  gitignored, nunca commitado; edita so `origensPermitidas`, sem
  sobrescrever token/outros campos ja preenchidos).
- `docs/deploy-checklist.md` (item ja existente sobre `origensPermitidas`
  ganha sub-itens separados dev/producao, com o passo explicito de
  confirmar a origem real de producao antes de preencher).
- `servico-impressao-local/README.md` (secoes 2.5 "Criar
  config/config.json" e 4.5 "CORS / Private Network Access" ganham
  exemplos separados dev/producao + explicacao da comparacao exata sem
  wildcard/normalizacao).
- `config.json` do mini PC de PRODUCAO — so depois da confirmacao da
  origem real (fora do controle deste repositorio local, feito
  fisicamente no mini PC de producao).
- **Nao precisa mudar**: nenhum arquivo PHP/backend
  (`Util\ConfiguracaoServicoImpressao`, `.env`), nenhum arquivo do
  serviço Node em si (`cors.js`/`config.js`/`server.js` — o mecanismo ja
  esta correto, so falta o VALOR de configuracao), `config.example.json`
  permanece `[]` como template versionado.

## Plano de implementacao (para a futura /01-implementacao)

1. Preencher `origensPermitidas: ["http://localhost:8080"]` no
   `config.json` REAL de desenvolvimento (nao versionado).
2. Reiniciar o servico local (`npm start` ou reinicio do servico
   Windows, conforme README secao 2.5/4.6).
3. Atualizar `docs/deploy-checklist.md` com os sub-itens dev/producao
   descritos acima.
4. Atualizar `servico-impressao-local/README.md` (secoes 2.5/4.5) com
   os exemplos separados e a explicacao da comparacao exata.
5. NAO tocar em `config.json` de producao nem em nenhum valor de
   producao nesta rodada — permanece `[]`/fail-closed ate confirmacao
   real pos-deploy (fora do escopo imediato desta demanda, a menos que
   o usuario autorize explicitamente um teste real em producao depois).
6. `qa-testes` valida o plano de testes abaixo, incluindo 1 chamada
   real pelo navegador em `http://localhost:8080/totem` a `GET /saude`/
   `GET /impressoras` (nunca `POST /imprimir`).

## Plano de testes

Sem impressao em nenhum cenario:

1. Origem de desenvolvimento autorizada (`http://localhost:8080`) →
   CORS libera, `Access-Control-Allow-Origin` reflete a origem exata,
   `Vary: Origin` presente.
2. Mesma origem com caminho `/totem` — nao aplicavel/nao testavel
   diretamente (o header `Origin` nunca inclui path por definicao do
   protocolo) — confirmar isso por leitura, nao por teste artificial.
3. Origem com porta diferente (ex. `http://localhost:8081`) → 403.
4. Origem semelhante maliciosa (`http://localhost:8080.evil.example`,
   `http://localhost.evil.example:8080`) → 403, sem
   `Access-Control-Allow-Origin` na resposta.
5. Wildcard — teste negativo: confirmar que nenhuma resposta em nenhum
   cenario contem `Access-Control-Allow-Origin: *` (nao ha "modo
   wildcard" a acionar, e so confirmacao de ausencia).
6. `Origin: null` (simular via `curl -H "Origin: null"` ou iframe
   sandboxed) → 403, a menos que alguem erroneamente tenha colocado
   `"null"` na allowlist (confirmar que nao esta).
7. Ausencia de `Origin` (curl puro) → passa pelo CORS; `GET /saude`
   responde sem token; `GET /impressoras`/`POST /imprimir` continuam
   exigindo o token Bearer.
8. Preflight `OPTIONS` valido (origem autorizada) → 204, headers
   `Allow-Methods`/`Allow-Headers`/`Max-Age` presentes.
9. Preflight PNA valido (origem autorizada +
   `Access-Control-Request-Private-Network: true`) →
   `Access-Control-Allow-Private-Network: true` presente.
10. PNA solicitado por origem NAO autorizada → header PNA ausente
    (requisicao ja e 403 pelo CORS de qualquer forma).
11. Metodo nao permitido (ex. `DELETE`) e header nao permitido (ex.
    `X-Custom-Header`) em preflight → confirmar que os headers
    devolvidos (`Allow-Methods`/`Allow-Headers`) nao os incluem (o
    bloqueio real e aplicado pelo proprio navegador com base nisso).
12. `origensPermitidas: []` (lista vazia, valor atual) → toda chamada
    de navegador com `Origin` e 403; chamada sem `Origin` passa (token
    continua exigido nas rotas protegidas).
13. `origensPermitidas` malformada (string em vez de array, ou item
    nao-string) → servico NAO SOBE (excecao na inicializacao,
    fail-closed real, nao um comportamento silencioso em runtime).
14. Token valido → 200 nas rotas protegidas; token invalido/ausente →
    401 com mensagem fixa, sem ecoar o token recebido.
15. Ausencia de vazamento de token — inspecionar corpo e headers de
    TODAS as respostas 403/401 e confirmar que nenhuma contem a origem
    completa recebida nem o token (so mensagem fixa esperada).
16. Bind so em `127.0.0.1` — confirmar em RUNTIME (nao so por leitura
    de codigo) via `netstat`/`Test-NetConnection` que a porta
    configurada so escuta em `127.0.0.1`, nunca em `0.0.0.0`/interface
    externa.
17. **Chamada real pelo navegador** em `http://localhost:8080/totem`:
    `fetch('http://127.0.0.1:4747/saude')` e
    `fetch('http://127.0.0.1:4747/impressoras', {headers:{Authorization:'Bearer <token>'}})`
    a partir do Console do DevTools na propria pagina do totem —
    esperado 200 em ambos, sem erro de CORS no console. **Nunca**
    chamar `POST /imprimir` neste teste. Teste negativo de controle:
    repetir a partir de outra origem (outra porta/`file://`) e
    confirmar que E bloqueado por CORS.

## Rollback

Se `origensPermitidas` causar bloqueio real em producao (origem
errada, totem nao consegue chamar `/saude`/`/impressoras`):
- Reverter IMEDIATAMENTE para `origensPermitidas: []` (fail-closed) no
  `config.json` do mini PC afetado e reiniciar o servico — restaura o
  estado seguro conhecido (bloqueia a UI de impressao via navegador ate
  nova origem correta ser confirmada, mas nunca expoe o servico).
- **Nunca** usar `"*"`, multiplas origens "por garantia", ou qualquer
  forma de abrir para todas as origens como atalho de emergencia.
- Repetir o processo de confirmacao da origem real (secao "Estrategia
  de producao" acima) antes de tentar de novo.

## O que NAO sera feito

- Nenhuma implementacao real nesta etapa de planejamento.
- Nenhuma alteracao de `.env`/`config.json` real.
- Nenhuma habilitacao de origem de producao.
- Nenhuma impressao, nenhuma chamada ao Talent, nenhuma alteracao de
  banco.
- Nenhuma exposicao do servico a `0.0.0.0` (bind permanece hardcoded em
  `127.0.0.1`, nao e sequer uma opcao de config).
- Nenhum commit, nenhum push.

## Sub-agentes envolvidos

- `security-especialista` (revisao do mecanismo atual de CORS/PNA,
  confirmacao de que nenhuma mudanca de codigo e necessaria, plano de
  17 testes de seguranca)
- `devops-especialista` (estrategia de configuracao dev/producao,
  processo de confirmacao segura da origem real, plano de atualizacao
  de `docs/deploy-checklist.md`/README, rollback)

Para a futura `/01-implementacao`: `devops-especialista` (preenchimento
do `config.json` de dev, atualizacao de documentacao), `qa-testes`
(validacao do plano de 17 testes, incluindo a chamada real pelo
navegador).

## Pendencias conhecidas

1. Origem real de producao nao confirmada — depende de deploy real no
   Hostgator e observacao fisica da barra de endereco pos-redirecionamento;
   nao pode ser resolvida nesta etapa nem deve ser suposta.
2. `servico-impressao-local/README.md` (secoes 2.5/4.5) e
   `docs/deploy-checklist.md` precisam de atualizacao de texto na
   `/01-implementacao` (nao e uma decisao bloqueante, e trabalho a
   fazer).

## Decisoes realmente bloqueantes

**Nenhuma.** O valor de desenvolvimento
(`origensPermitidas: ["http://localhost:8080"]`) ja foi fornecido
explicitamente pelo usuario nesta demanda e e suficiente para a
`/01-implementacao` avancar integralmente no ambiente de dev. A unica
pendencia real (origem de producao) e, por definicao, dependente de um
evento futuro (deploy real + confirmacao fisica) e ja esta corretamente
tratada como fail-closed ate la — nao bloqueia o trabalho de dev desta
demanda.

## Trello
card_id: 6aaa0130f86057b318c1d106

## Proximo passo
Rodar `/01-implementacao` para preencher `config.json` de
desenvolvimento e atualizar a documentacao (`deploy-checklist.md`,
README do servico), seguido de `/02-testes` com o plano de 17 itens
acima. A habilitacao de producao fica para uma demanda/autorizacao
futura, apos o deploy real confirmar a origem exata.

## Resultado da implementacao (2026-09-16)

Implementado por `devops-especialista`, sem tocar em nenhum codigo de
CORS/PNA/bind (confirmado sem divergencia entre o plano e o codigo
real).

### Configuracao local de desenvolvimento

`servico-impressao-local/config/config.json` (real, gitignored, nunca
exibido/versionado): campo `origensPermitidas` alterado de `[]` para
`["http://localhost:8080"]`. Todos os demais campos (`porta`, `token`,
`maxPdfBytesDecodificado`, `impressorasPermitidas`, `idempotencia`,
`timeoutMs`) preservados integralmente.

### Validacoes executadas (sem expor conteudo sensivel)

- JSON valido: **true**.
- Exatamente 1 origem na lista, igual a `http://localhost:8080` — sem
  `/totem`, sem barra final, sem `127.0.0.1`, sem a origem de producao,
  sem wildcard: **todas confirmadas true/false conforme esperado**.
- `git check-ignore -v config/config.json`: confirmado, arquivo
  continua fora do versionamento.
- Teste isolado de carregamento (`require('./src/config')`):
  `config.host === '127.0.0.1'` (true), `config.origensPermitidas` =
  `['http://localhost:8080']` (length 1, valor exato) — token nunca
  impresso.
- Teste de comparacao exata (simulando a logica de `corsEPna`, sem
  alterar `cors.js`): `.includes('http://localhost:8080')` = **true**;
  `.includes()` para `.../`, `.../totem`, `http://127.0.0.1:8080`,
  `https://udlog.online`, `http://udlog.online`, `*` = **todos false**.
- `src/config.js` confirmado com `host: '127.0.0.1'` hardcoded,
  inalterado (leitura de codigo, sem alteracao).
- `git diff --check` nos arquivos de documentacao alterados: sem
  problema.
- Busca por segredo/token nos arquivos VERSIONADOS
  (`docs/deploy-checklist.md`, `README.md`): nenhum valor real
  encontrado.
- Nenhum processo Node foi iniciado/reiniciado nesta etapa (nao foi
  necessario para as validacoes).

### Documentacao atualizada

- `servico-impressao-local/README.md` (secoes 2.5, 4.5, 6): dev ativo
  (`["http://localhost:8080"]`), producao documentada
  (`["https://udlog.online"]`, NAO ativada — so no deploy final),
  `http://udlog.online` (sem HTTPS) explicitamente marcado como NAO
  autorizado, nunca dev+producao juntas, nunca `*`, rollback seguro
  (`[]`), reconfirmacao da origem na barra do navegador pos-deploy.
- `docs/deploy-checklist.md` (item de `origensPermitidas`): mesma
  informacao, reescrita no formato de checklist.

### Confirmacao explicita

Zero impressao, zero chamada ao Talent, zero alteracao de banco/
registro real, zero commit, zero push nesta rodada. `config.json` real
NUNCA foi exibido/versionado.

### Veredito

Implementacao concluida e validada sem nenhuma divergencia do
planejamento. **Pronta para `/02-testes`** — o teste completo pelo
navegador (chamada real em `http://localhost:8080/totem` a `/saude`/
`/impressoras`) fica para essa etapa, conforme planejado.

## Resultado dos testes (2026-09-16, /02-testes)

Executado por `qa-testes` (17 cenarios via execucao real de rede contra o
servico rodando em `127.0.0.1:4747`) e `security-especialista` (revisao
de codigo independente, em paralelo), mais o teste real pelo navegador
conduzido diretamente com o usuario. Nenhum achado bloqueante em nenhuma
das tres frentes.

### Preparacao segura

- Confirmado (sem exibir conteudo) que `config.json` real tem exatamente
  1 origem (`http://localhost:8080`), sem producao, sem wildcard.
- `git check-ignore -v config/config.json`: confirmado fora do
  versionamento.
- Servico nao estava rodando no inicio da rodada (2 processos Node
  pre-existentes na maquina, PIDs 27816/28576, sem relacao com este
  servico e sem listener na porta 4747, nao foram tocados).
- Servico iniciado pelo `qa-testes` para os testes automatizados: PID
  28156, `127.0.0.1:4747`. Encerrado ao final via `taskkill /PID 28156
  /T /F` (PID exato, nunca por nome) — estado devolvido a "parado".
- Para o teste real pelo navegador (rodada separada, feita com o
  usuario), o servico foi reiniciado pelo proprio usuario (`npm start`
  em `servico-impressao-local/`) apos um primeiro erro de diretorio
  (`npm start` executado originalmente na pasta pessoal do usuario, sem
  `package.json` — corrigido apontando para o diretorio correto).
  Confirmado subindo em `127.0.0.1:4747`, mesmo `config.json` real. PID
  identificado pelo orquestrador via `Get-NetTCPConnection -LocalPort
  4747` = 30056, encerrado ao final via `taskkill /PID 30056 /T /F`
  (PID exato). Estado final: parado, igual ao estado anterior a esta
  rodada.

### Resultado dos 17 cenarios (execucao real, curl/PowerShell)

1-17: **PASSOU** em todos. Origem autorizada aceita com
`Access-Control-Allow-Origin` exato + `Vary: Origin` + preflight/PNA
corretos (itens 1-5). Todas as origens nao autorizadas testadas
rejeitadas com 403 sem `Access-Control-Allow-Origin` (itens 6-15:
`.../`, `.../totem`, `127.0.0.1:8080`, `localhost` sem porta,
`localhost:80`, `https://udlog.online`, `http://udlog.online`,
`localhost:8080.evil.example`, `localhost.evil.example:8080`, `Origin:
null`). Ausencia de wildcard confirmada em toda resposta e PNA nunca
concedido a origem nao autorizada (item 16). Ausencia de `Origin`,
token valido/invalido/ausente e lista vazia/malformada em teste isolado
— todos com o comportamento esperado (item 17).

### Teste real pelo navegador

Executado em `http://localhost:8080/totem`, Console do DevTools:
- `GET /saude` → `{status: 'ok', servico: 'servico-impressao-local-udlog',
  versao: '1.0.0'}`, sem erro de CORS/PNA.
- `GET /impressoras` (com token real, header `Authorization: Bearer`) →
  `{impressoras: Array(1)}`, sem erro de CORS/PNA, compativel com a
  allowlist de uma unica impressora autorizada.
- Nenhuma mensagem de bloqueio de CORS/PNA no console do navegador.
- `POST /imprimir` nao foi acessado nem simulado.

**Achado operacional (nao bloqueante, fora do escopo de codigo desta
demanda)**: durante o teste manual, o usuario colou no chat o comando
completo do Console ja preenchido com o valor literal do token real
(para testar `GET /impressoras`). O servico e o navegador nao vazaram o
token em nenhum momento — a exposicao foi por copia manual do comando
para a conversa. Recomendado ao usuario rotacionar esse token
(`node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`
+ atualizar `config.json`) por precaucao, ja que o valor ficou
registrado no historico da conversa. Nao bloqueia o veredito desta
demanda (o requisito tecnico de que nenhuma resposta da aplicacao expoe
o token foi cumprido integralmente).

### Seguranca adicional

Todos **PASSOU**: bind confirmado em runtime somente em `127.0.0.1:4747`
(`Get-NetTCPConnection`, teste negativo no IP externo da maquina);
comparacao exata via `Array.includes()` sem wildcard/regex (confirmado
por execucao real e por leitura de codigo independente); autenticacao
por token confirmada independente do CORS; token nunca em URL; token
nunca exibido em nenhuma saida de nenhum sub-agente nem do orquestrador;
erros 403/401 sanitizados (mensagem fixa, sem eco de origem/token);
`config.json` confirmado fora do Git; origem de producao confirmada
ausente do arquivo local; distincao `http://` vs `https://udlog.online`
confirmada como suficientemente explicita no README/deploy-checklist
pela revisao de seguranca independente.

### Regressao

- Sem suite automatizada formal em `servico-impressao-local` (lacuna
  estrutural pre-existente do projeto, registrada, fora do escopo desta
  demanda corrigir).
- `git diff --check`: sem problemas reais (so avisos de CRLF do Git no
  Windows).
- Busca por segredo/token nos arquivos versionados
  (`docs/deploy-checklist.md`, `servico-impressao-local/README.md`,
  este handoff, `ia_development_state.md`): nenhum valor real exposto.

### Confirmacoes finais

Zero impressao, zero chamada ao Talent, zero alteracao de banco/registro
real, zero commit, zero push nesta rodada. Producao (`https://udlog.online`)
continua fora do `config.json` local, nao ativada nem testada.

### Veredito

**APROVADO.** Nenhum teste obrigatorio falhou. Liberado para
`/03-revisao`.

## Remediacao de seguranca — rotacao de token (2026-09-16)

Motivo: durante o teste real pelo navegador do `/02-testes`, o token real
do servico local de impressao foi colado pelo usuario no chat (comando
do Console ja preenchido). O vazamento NAO partiu da aplicacao/codigo —
foi copia manual de um comando ja preenchido para a conversa. Ainda
assim, o token foi tratado como exposto e substituido por precaucao.

Executado por `devops-especialista`. Nenhum valor de token (antigo ou
novo), completo ou parcial, foi exibido em nenhum momento desta rodada.

### Mapeamento

Dois arquivos locais nao versionados identificados como consumidores do
mesmo token, confirmados sincronizados entre si antes da rotacao:
- `servico-impressao-local/config/config.json` (chave `token`).
- `.env` real do backend PHP, raiz do projeto (chave
  `IMPRESSAO_LOCAL_TOKEN`, usada por `Util\ConfiguracaoServicoImpressao`).

Busca em toda a arvore do repositorio nao encontrou nenhum outro arquivo
local contendo o valor do token antigo.

### Rotacao

Token anterior revogado. Novo token gerado com
`crypto.randomBytes(32).toString('hex')` (256 bits de entropia, 64 chars
hex), nunca passado por argumento de linha de comando nem logado no
terminal. Atualizado atomicamente nos dois arquivos acima, preservando
integralmente todos os demais campos (`origensPermitidas`, `porta`,
`impressorasPermitidas`, `idempotencia`, `timeoutMs`,
`maxPdfBytesDecodificado` em `config.json`; todas as demais variaveis em
`.env`, incluindo `IMPRESSAO_LOCAL_URL`/`IMPRESSAO_FRONTEND_TIMEOUT_MS`).
Script/arquivo temporario usado na rotacao removido do scratchpad ao
final.

### Reinicio seguro

Servico iniciado (`npm start`) para validacao, PID 33708 identificado
via `Get-NetTCPConnection -LocalPort 4747`, escutando somente em
`127.0.0.1:4747`. Encerrado ao final exclusivamente por esse PID
(`taskkill /PID 33708 /T /F`) — servico devolvido ao estado "parado",
igual ao estado anterior a esta rodada.

### Validacao (PASS/FAIL, sem exibir token)

Todos os itens **PASS**: `config.json`/`.env` sintaticamente validos;
token novo sincronizado entre os dois arquivos; token ANTIGO em
`GET /impressoras` → 401 (revogado); token NOVO em `GET /impressoras` →
200; `GET /saude` sem token → 200 (comportamento inalterado, endpoint
nao protegido); `GET /impressoras` sem token → 401; CORS continua
aceitando somente `http://localhost:8080`, `https://udlog.online`
confirmado ausente de `origensPermitidas`; bind confirmado somente em
`127.0.0.1:4747`; busca por segredo nos arquivos VERSIONADOS do
repositorio nao encontrou o token antigo nem o novo (hashes de 64 chars
encontrados em outros arquivos sao hash de commit git de outra demanda,
sem relacao, confirmado programaticamente); `config.json` e `.env`
confirmados ignorados pelo Git.

### Confirmacoes finais

Nenhum codigo alterado. Nenhum outro campo alterado alem do token.
`https://udlog.online` continua nao ativada. Zero impressao, zero
Talent, zero alteracao de banco, zero commit, zero push nesta rodada.

### Regra operacional registrada

Nunca colar comandos ja preenchidos com o token (ou qualquer segredo)
em chat, terminal compartilhado ou documentacao — usar sempre variavel
de shell nao impressa ou referencia indireta ao testar manualmente.

### Veredito

Remediacao concluida com sucesso, sem nenhum achado pendente. **Pronta
para `/03-revisao`.**

## Revisao final (2026-09-16, /03-revisao)

Tres revisoes INDEPENDENTES em paralelo, sem alteracao de codigo/config
nesta etapa: `security-especialista` (CORS/PNA + rotacao de token +
documentacao, por leitura), `devops-especialista` (config.json/.env +
documentacao de deploy, por leitura + 1 script temporario descartavel
de comparacao), `qa-testes` (reconfirmacao viva pos-rotacao: 11 testes
focados de CORS/PNA/auth + regressao, unico agente autorizado a
iniciar/parar o servico nesta rodada).

### Veredito por area

- **Configuracao local** (`config.json`/`.env`): APROVADO. `origensPermitidas`
  contem exatamente `["http://localhost:8080"]`, JSON valido, demais
  campos preservados, ambos os arquivos confirmados fora do Git, token
  sincronizado entre `config.json` e `.env` (comparacao booleana em
  memoria, `true`).
- **Rotacao do token**: APROVADO. Token antigo confirmado revogado (401),
  token novo autentica (200), ausencia de token rejeitada (401), `/saude`
  continua publico (200), nenhum token em arquivo versionado, nenhum
  script temporario remanescente, aplicacao confirmada como NAO sendo a
  origem do vazamento.
- **CORS/PNA**: APROVADO. Comparacao exata via `Array.includes()`
  reconfirmada sem wildcard/regex/comparacao parcial em nenhum arquivo;
  `Access-Control-Allow-Origin` so para origem autorizada; `Vary: Origin`
  presente; preflight `OPTIONS` correto; PNA so apos validacao da mesma
  variavel `origemAutorizada` (sem caminho alternativo); origem nao
  autorizada nunca recebe PNA; autenticacao confirmada independente do
  CORS; bind `127.0.0.1` hardcoded reconfirmado (estatico e em runtime);
  mensagens de erro sanitizadas. 11 testes focados executados de verdade
  (incluindo `https://udlog.online`/`http://udlog.online` continuando
  rejeitadas) — todos PASS. `POST /imprimir` nao foi acessado.
- **Documentacao**: APROVADO, com 1 achado de ATENCAO nao bloqueante
  (ver abaixo).

### Achado de atencao (nao bloqueante)

A regra operacional "nunca colar comando preenchido com token/segredo em
chat, terminal compartilhado ou documentacao" (registrada na remediacao
de rotacao de token) existe hoje **somente** neste handoff e no log
historico de `ia_development_state.md` — nao foi replicada em
`servico-impressao-local/README.md` (secao 2.4, "Gerar o token do
servico") nem em `docs/deploy-checklist.md`, que sao os documentos
operacionais que alguem consultaria ao gerar/rotacionar um token no
futuro. Identificado de forma convergente por `security-especialista` e
`devops-especialista`. Registrado como pendencia para decisao do
usuario — nao bloqueia esta demanda, nao foi corrigido nesta etapa
(fora de escopo do `/03-revisao`, que nao altera documentacao alem dos
proprios registros de fechamento).

### Validacoes finais

`git diff --check` sem problema real (so avisos de CRLF do Windows).
Arquivos versionados prontos para commit: `docs/deploy-checklist.md`,
`ia_development_state.md`, `servico-impressao-local/README.md`
(modificados), `docs/handoffs/2026-09-15-impressao-origens-permitidas.md`
(novo). Busca por token/segredo no diff e em todo o repositorio
versionado: nenhum valor real exposto (matches encontrados sao hash de
commit git e fingerprint SHA-256 de outra demanda, sem relacao).
`servico-impressao-local/config/config.json` e `.env` confirmados
ausentes de `git status`/`git diff`.

### Estado final do servico

Parado (porta 4747 sem listener), controlado exclusivamente por PID
(23664) durante os testes vivos desta rodada, encerrado ao final.

### Confirmacoes finais

Zero impressao, zero Talent, zero alteracao de banco/registro real, zero
commit, zero push nesta rodada. Producao continua nao ativada. Nenhum
token exibido em nenhuma saida de nenhum agente.

### Veredito geral

**APROVADO.** Nenhum achado bloqueante em nenhuma das quatro areas.
1 achado de atencao nao bloqueante (documentacao da regra operacional de
token, ver acima), registrado como pendencia. **Pronta para
`/04-commit-e-push`.**

## Correcao documental — regra de token replicada (2026-09-16, /01-implementacao curta)

Motivo: resolver o achado de atencao registrado na `/03-revisao` (secao
acima) — a regra operacional "nunca colar comando preenchido com
token/segredo em chat, terminal compartilhado ou documentacao" existia
so neste handoff e no log historico de `ia_development_state.md`, nao
nos documentos operacionais vivos.

Escopo estritamente documental, 4 arquivos: este handoff,
`ia_development_state.md`, `servico-impressao-local/README.md`,
`docs/deploy-checklist.md`. Nenhum codigo, `.env`, `config.json` ou
config operacional alterado; servico Node nao foi iniciado; nenhuma
impressao, chamada ao Talent ou commit/push nesta rodada.

### O que foi adicionado

- `servico-impressao-local/README.md`, secao 2.4: bloco visivel
  "REGRA OBRIGATORIA — manuseio do token" logo apos o comando de geracao
  do token, cobrindo: nunca colar comando preenchido em chat/terminal
  compartilhado/documentacao; nunca em URL/querystring; nunca como
  argumento de linha de comando visivel; nunca em print/log/documentacao/
  Trello; autenticacao sempre via header `Authorization: Bearer <token>`;
  testes manuais via script que le o valor direto do arquivo de config
  (nunca digitado/colado) e mostra so `PASS`/`FAIL`/codigo HTTP;
  qualquer exposicao (mesmo parcial) torna o token comprometido, exigindo
  rotacao imediata nos dois pontos sincronizados (`config/config.json`
  chave `token`, `.env` do backend PHP chave `IMPRESSAO_LOCAL_TOKEN`) e
  confirmacao de `401` no token antigo. Notas cruzadas adicionadas
  tambem nas secoes 4.3 e 4.4 (exemplos `curl` com `SEU_TOKEN_AQUI`),
  apontando de volta para a regra da secao 2.4.
- `docs/deploy-checklist.md`, item "Token do serviço gerado com
  segurança" (secao 1.X): sub-item novo com a mesma regra, condensada em
  formato de checklist, mesmo conteudo essencial do README (sem
  contradicao entre os dois documentos).
- `ia_development_state.md`: entrada no log de mudancas registrando esta
  rodada curta.

### Validacoes executadas (sem exibir nenhum valor sensivel)

- README e deploy-checklist confirmados com a mesma regra essencial,
  sem contradicao entre si.
- `git diff` dos 4 arquivos alterados: nenhum valor real (antigo ou novo)
  de token presente — so a regra e nomes de arquivo/chave
  (`config/config.json`/`token`, `.env`/`IMPRESSAO_LOCAL_TOKEN`).
  Nenhum exemplo usa hex de 64 caracteres nem qualquer valor que pareca
  credencial real — placeholders permanecem `SEU_TOKEN_AQUI`.
- `git diff --check`: limpo nos arquivos alterados.
- `git check-ignore -v servico-impressao-local/config/config.json` e
  `git check-ignore -v .env`: ambos confirmados fora do controle de
  versao, inalterado em relacao as rodadas anteriores.
- Nenhuma alteracao de codigo/config operacional nesta rodada — so os 4
  arquivos documentais listados acima.

### Confirmacoes finais

Zero valor de token exposto em qualquer saida. Zero impressao, zero
chamada ao Talent, zero alteracao de banco, zero inicio/parada de
servico Node, zero commit, zero push nesta rodada.

### Veredito

Achado de atencao da `/03-revisao` resolvido. Pronta para nova
`/02-testes`/`/03-revisao` documental (se necessario) ou diretamente
para `/04-commit-e-push`, a criterio do orquestrador.

## Revisao curta de confirmacao (2026-09-16, /03-revisao)

Revisao independente (`security-especialista`) restrita aos 4 arquivos
documentais da rodada anterior ("Correcao documental — regra de token
replicada"). Confirmado: README e `deploy-checklist.md` registram a
regra operacional de forma consistente entre si (nunca expor token em
chat/terminal/documentacao/Trello, nunca em URL/argumento de linha de
comando, sempre via header `Authorization: Bearer`, scripts de teste
leem o token do arquivo sem exibir valor, qualquer exposicao exige
rotacao imediata sincronizada em `config.json`/`.env` com confirmacao
de `401` no token antigo, remocao de scripts temporarios). `git diff
--check` limpo; nenhum token/credencial real em nenhum dos 4 arquivos;
`config.json`/`.env` confirmados fora do Git; nenhum codigo/config
operacional alterado nesta rodada. Nenhum achado.

### Veredito

**APROVADO.** Nenhuma pendencia nova. **Pronta para `/04-commit-e-push`.**
