# servico-impressao-local (totem UDLOG)

Servico Node.js **standalone**, fora do app PHP/Hostgator, que roda no
mini PC Windows do totem. Recebe um PDF ja pronto (gerado por FPDF no
backend PHP) em base64 e manda para a impressora fisica indicada. Nao
gera conteudo de etiqueta, nao decide layout/formato — isso e sempre do
backend PHP.

Este documento cobre instalacao e operacao. **Nada aqui foi executado
neste repositorio** — e o roteiro para quando o mini PC fisico existir.

## 1. Por que Node.js + `pdf-to-printer` + `node-windows`

- **Node.js v22.17.1**: versao confirmada pelo usuario, compativel com o
  ambiente de desenvolvimento atual.
- **`pdf-to-printer`**: biblioteca pura JS (sem dependencia nativa
  compilada) que empacota o `SumatraPDF` (binario redistribuivel,
  gratuito) e usa ele via `spawn` para imprimir PDF de verdade e listar
  impressoras instaladas no Windows (`wmic`/PowerShell por baixo dos
  panos). Evita depender de bibliotecas com `node-gyp`/compilacao nativa,
  que costumam dar problema em maquina Windows sem toolchain de C++
  instalado.
- **`node-windows`**: biblioteca pura JS (sem compilacao nativa) para
  registrar um script Node como Servico do Windows nativo, com reinicio
  automatico e inicializacao junto com o boot — sem precisar de sessao de
  usuario logada.
- **`express`**: so para organizar rotas/middleware (CORS, PNA, auth,
  parsing de JSON) de forma legivel; nao decide nada de dominio.

## 2. Instalacao no mini PC (Windows)

### 2.1. Node.js

1. Baixar o instalador oficial da versao **v22.17.1** (ou a LTS mais
   proxima compativel) em https://nodejs.org/dist/v22.17.1/ — escolher o
   `.msi` de 64 bits.
2. Instalar com as opcoes padrao (inclui `npm`).
3. Confirmar no PowerShell:
   ```
   node --version
   npm --version
   ```

### 2.2. Epson Advanced Printer Driver 6

1. Baixar o driver oficial da Epson para a **TM-T88VII** (Epson Advanced
   Printer Driver / APD 6) no site de suporte da Epson, versao para
   Windows compativel com a build do mini PC.
2. Instalar seguindo o instalador oficial, conectando a impressora via
   USB quando solicitado.
3. Apos instalado, confirmar em
   **Configuracoes > Dispositivos > Impressoras e scanners** que a
   impressora aparece com o nome exato configurado no driver (esse nome
   e o que sera enviado no campo `impressora` da API deste servico).
4. Imprimir uma pagina de teste pelo proprio Windows para validar a
   instalacao do driver antes de testar via este servico.

### 2.3. Copiar o codigo do servico

1. Copiar a pasta `servico-impressao-local/` (deste repositorio) para o
   mini PC, por exemplo em `C:\udlog\servico-impressao-local`.
2. Dentro da pasta, rodar:
   ```
   npm install
   ```
   Isso instala `express`, `pdf-to-printer` e `node-windows` (nenhuma
   exige compilacao nativa).

### 2.4. Gerar o token do servico

Este servico usa um token PROPRIO (nunca reutilizar token do totem, do
Trello ou do Talent). Gerar um valor aleatorio forte:

```
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

> **REGRA OBRIGATORIA — manuseio do token (nunca opcional):**
> - **Nunca** colar um comando ja preenchido com o valor real do token em
>   chat (inclusive conversas com IA/assistentes), terminal compartilhado,
>   ticket ou qualquer lugar que fique registrado.
> - **Nunca** colocar o token em URL/querystring.
> - **Nunca** passar o token como argumento de linha de comando visivel
>   (evitar que apareca no historico do shell).
> - **Nunca** registrar o token em print de tela, log, documentacao,
>   ticket ou comentario do Trello.
> - **Sempre** autenticar via header HTTP `Authorization: Bearer <token>`
>   — nunca outro mecanismo.
> - Testes manuais que precisem do token (secoes 4.3/4.4) devem ser feitos
>   por script que leia o valor direto de `config/config.json`/`.env`
>   (nunca digitado/colado manualmente num comando visivel) e exiba
>   somente `PASS`/`FAIL` ou o codigo HTTP — nunca o valor, prefixo,
>   sufixo, tamanho ou hash persistente do token. Remover qualquer script
>   temporario usado no teste ao final.
> - **Qualquer exposicao, mesmo acidental/parcial** (colado em chat, print,
>   log, etc.), torna o token comprometido: rotacionar IMEDIATAMENTE nos
>   dois pontos que precisam permanecer sincronizados com o MESMO valor —
>   `config/config.json` (chave `token`) e o `.env` real do backend PHP
>   (chave `IMPRESSAO_LOCAL_TOKEN`). Apos rotacionar, confirmar que o
>   token ANTIGO passa a responder `401` (prova de revogacao efetiva).

### 2.5. Criar `config/config.json`

1. Copiar `config/config.example.json` para `config/config.json` (esse
   arquivo NUNCA vai para o controle de versao — ver `.gitignore`).
2. Preencher:
   - `porta`: porta local (padrao sugerido `4747`).
   - `token`: o valor gerado no passo 2.4.
   - `maxPdfBytesDecodificado`: limite de tamanho do PDF decodificado, em
     bytes (padrao sugerido `2097152` = 2 MB, ajustar conforme o tamanho
     real dos PDFs de etiqueta gerados pelo backend).
   - `origensPermitidas`: lista de origens (protocolo + dominio, **sem**
     path e **sem** barra final — o cabecalho HTTP `Origin` enviado pelo
     navegador nunca inclui path) autorizadas a chamar este servico via
     navegador. Cada ambiente mantem **so a sua propria origem**:
     - **Desenvolvimento (ja ativo agora)**:
       ```json
       "origensPermitidas": ["http://localhost:8080"]
       ```
     - **Producao (documentado, NAO ativado ainda — so no deploy final no
       mini PC)**:
       ```json
       "origensPermitidas": ["https://udlog.online"]
       ```
       Repare que e `https://udlog.online` (com HTTPS) — `http://udlog.online`
       (sem HTTPS) **nao** e a origem autorizada e nao deve ser incluida.
     - **Nunca** deixar dev e producao juntas na mesma lista "por
       garantia" — cada ambiente usa exclusivamente a origem que
       corresponde a ele.
     - **Nunca** usar `*` (wildcard) nesta lista.
     - Enquanto a lista estiver vazia (`[]`), o servico permanece
       fail-closed: so responde a chamadas sem cabecalho `Origin`
       (diagnostico local via curl/PowerShell), rejeitando qualquer
       chamada de navegador. Voltar a lista para `[]` e o rollback seguro
       caso a origem ativa cause algum problema.
     - No deploy final em producao, depois de ativar
       `["https://udlog.online"]` no mini PC, reconfirmar visualmente na
       barra de endereco do navegador do totem que a origem realmente
       servida e exatamente `https://udlog.online`, sem path, antes de
       considerar o CORS/PNA validado em producao.
   - `idempotencia.ttlMs` / `idempotencia.maxEntries`: controle de
     deduplicacao de impressao por identificador de job (padrao sugerido
     `600000` ms / `500` entradas).
   - `impressorasPermitidas`: **allowlist de impressoras FISICAS**, por
     nome exato (o mesmo nome que aparece em
     **Configuracoes > Dispositivos > Impressoras e scanners** e que
     `GET /impressoras` devolve). So o que estiver nesta lista pode ser
     escolhido/usado — `GET /impressoras` ja filtra a resposta por essa
     lista, e `POST /imprimir` revalida de novo antes de cada job (nunca
     confia so no que o front-end mandou). Valor inicial autorizado:
     `["EPSON TM-T88VII Receipt"]`. **Nunca incluir impressora
     virtual/interativa** nesta lista — ex.: `Microsoft Print to PDF`,
     `Microsoft XPS Document Writer`, `Fax`, `Envio para o OneNote` — esse
     tipo de impressora pode abrir dialogo/travar o processo esperando
     interacao humana, o que trava a tela do totem (achado critico do
     `/02-testes` de 2026-09-14, ver
     `docs/handoffs/2026-09-11-impressao-etiqueta-teste.md`).
     Passo a passo para autorizar um novo modelo de impressora fisica no
     futuro:
     1. Confirmar o nome exato do driver instalado em
        **Configuracoes > Dispositivos > Impressoras e scanners** (ou via
        `GET /impressoras` sem a allowlist aplicada, se precisar
        conferir antes de adicionar).
     2. Editar `config/config.json` no mini PC e acrescentar o nome exato
        ao array `impressorasPermitidas`.
     3. Reiniciar o servico para a config ser recarregada:
        ```
        Restart-Service "UDLOG Servico Impressao Local"
        ```
        (ou, se estiver rodando via `npm start` manual em vez do Servico
        do Windows, parar com `Ctrl+C` e rodar `npm start` de novo).
   - `timeoutMs`: tempo maximo, em milissegundos, que um job de impressao
     pode levar antes de ser considerado travado. **Default `30000`
     (30s) se o campo for omitido.** Ao estourar:
     - so o processo (PID) daquele job especifico e seus subprocessos sao
       encerrados — **nunca** por nome de processo (`taskkill /IM`), o
       que poderia matar impressao de outro job ou processo do sistema
       sem relacao;
     - a fila, o lock de idempotencia e qualquer arquivo temporario
       daquele job sao liberados;
     - o resultado fica marcado como **indeterminado** (nao "erro" nem
       "sucesso") — o job **nunca** e reimpresso automaticamente nem
       marcado como concluido; o front-end recebe um erro sanitizado e
       decide como orientar o usuario a partir dai.
3. Levar o MESMO valor de `token` para o `.env` do backend PHP, na
   variavel `IMPRESSAO_LOCAL_TOKEN` (o `.env` do PHP so guarda essa
   referencia para o front-end saber qual token enviar — o valor real
   "mora" aqui, na config local do Windows). `IMPRESSAO_LOCAL_URL` no
   `.env` do PHP deve apontar para `http://127.0.0.1:<porta>` (a mesma
   porta configurada aqui).

### 2.5.1. Alerta operacional — sincronizacao manual com o `.env` do backend PHP

`.env.example` do backend PHP (Hostgator) documenta duas variaveis que
sao **espelho manual** dos campos acima, no mesmo padrao ja usado para
`IMPRESSAO_LOCAL_TOKEN`:

- `IMPRESSORAS_PERMITIDAS` ↔ `impressorasPermitidas`
- `IMPRESSAO_TIMEOUT_MS` ↔ `timeoutMs`

**Nao existe conexao viva entre os dois ambientes.** Sempre que um desses
valores for alterado no `.env` do backend PHP (Hostgator), o MESMO valor
precisa ser replicado manualmente aqui, em `config/config.json` no mini
PC, e vice-versa — e o servico precisa ser reiniciado (passo 3 do item
`impressorasPermitidas` acima) para a mudanca valer. Existe tambem
`IMPRESSAO_FRONTEND_TIMEOUT_MS` no `.env` do PHP, que controla apenas o
timeout do lado do front-end (navegador do totem) e nao tem
correspondente em `config.json` — nao confundir com `timeoutMs` deste
servico.

### 2.6. Testar manualmente antes de instalar como servico

```
npm start
```

Deve aparecer no console algo como:
```
servico-impressao-local ouvindo em http://127.0.0.1:4747 (config: C:\udlog\servico-impressao-local\config\config.json)
```

Testar (ver secao 4 — roteiro de diagnostico) antes de seguir para
inicializacao automatica.

## 3. Inicializacao automatica com o Windows

Escolhida a abordagem via **`node-windows`** (Servico do Windows nativo),
em vez de Agendador de Tarefas (Task Scheduler), porque:

- Um Servico do Windows reinicia sozinho se o processo cair (o
  Agendador de Tarefas tambem suporta isso, mas de forma menos direta).
- Sobe antes/independente de qualquer login de usuario — o kiosk do
  totem pode logar automaticamente sem depender de ordem de
  inicializacao com o servico.
- `node-windows` e puro JS, sem instalar nada fora do `npm install` ja
  feito.

Passos (PowerShell como **Administrador**, dentro da pasta do servico):

```
npm run instalar-servico-windows
```

Isso registra o servico com o nome `UDLOG Servico Impressao Local` e ja
inicia ele. Verificar em
**Servicos do Windows** (`services.msc`) que ele aparece com status
"Em execucao" e tipo de inicializacao "Automatico".

Para remover (ex.: durante testes/depuracao):

```
npm run desinstalar-servico-windows
```

**Nao rodar nenhum desses dois comandos fora do mini PC de producao.**

## 4. Roteiro de diagnostico

### 4.1. O servico esta rodando?

- Via Servicos do Windows: `services.msc` → procurar
  `UDLOG Servico Impressao Local` → status deve ser "Em execucao".
- Via linha de comando (PowerShell):
  ```
  Get-Service "UDLOG Servico Impressao Local"
  ```
- Via processo: `Get-Process node` deve listar ao menos um processo
  `node.exe` quando o servico esta ativo.

### 4.2. Health check

```
curl http://127.0.0.1:4747/saude
```

Resposta esperada (200):
```json
{"status":"ok","servico":"servico-impressao-local-udlog","versao":"1.0.0"}
```

Se der erro de conexao recusada, o servico nao esta rodando ou a porta
configurada e outra — checar `config/config.json` e o log do Servico do
Windows (Visualizador de Eventos → Logs do Windows → Aplicativo, ou a
pasta `daemon/` criada pelo `node-windows` ao lado do script, que guarda
`.log`/`.err.log` do processo).

### 4.3. Listagem de impressoras

```
curl -H "Authorization: Bearer SEU_TOKEN_AQUI" http://127.0.0.1:4747/impressoras
```

`SEU_TOKEN_AQUI` e um placeholder — **nunca** substituir pelo valor real
diretamente num comando colado em chat, terminal compartilhado ou
documentacao (ver regra obrigatoria na secao 2.4). Testes manuais
repetidos devem ler o token direto do arquivo de config via script.

Resposta esperada (200): lista com o nome exato de cada impressora
instalada, incluindo a `EPSON TM-T88VII Receipt`. Se a impressora
esperada nao aparecer aqui, o problema e o driver (ver secao 2.2), nao
este servico.

Sem o cabecalho `Authorization` correto, a resposta deve ser `401`.

### 4.4. Impressao (com autorizacao explicita antes de testar em impressora real)

```
curl -X POST http://127.0.0.1:4747/imprimir \
  -H "Authorization: Bearer SEU_TOKEN_AQUI" \
  -H "Content-Type: application/json" \
  -d "{\"pdf_base64\":\"<base64 de um PDF real>\",\"impressora\":\"EPSON TM-T88VII Receipt\",\"identificador\":\"teste-manual-001\"}"
```

`SEU_TOKEN_AQUI` e um placeholder — mesma regra obrigatoria da secao 2.4
se aplica aqui (nunca colar o valor real preenchido num comando
compartilhado).

- Repetir a mesma chamada com o mesmo `identificador` deve retornar
  `{"status":"ja_impresso", ...}` sem imprimir de novo.
- Enviar um `pdf_base64` que nao comeca com `%PDF` deve retornar `400`.
- Enviar sem `Authorization` deve retornar `401`.

### 4.4.1. Teste de travamento/timeout — SOMENTE com processo mock

Para validar o comportamento de `timeoutMs` (item 2.5, "Ao estourar"),
**nunca** testar abrindo uma impressora virtual/interativa real (ex.:
`Microsoft Print to PDF`) para forcar um dialogo travado — isso e
exatamente o cenario que a allowlist de `impressorasPermitidas` existe
para impedir, e pode deixar um processo pendurado de verdade no mini PC.
Simular a trava com um processo mock (ex.: um script que so dorme sem
terminar, no lugar do binario real de impressao) dentro do ambiente de
teste/dev, nunca em cima de uma impressora fisica ou virtual real.

### 4.5. CORS / Private Network Access

`origensPermitidas` em `config/config.json` (ver secao 2.5) hoje esta
ativa em desenvolvimento com `["http://localhost:8080"]`. A origem de
producao `https://udlog.online` esta documentada mas **nao** ativada
neste arquivo — sera ligada somente no deploy final no mini PC, com
reconfirmacao da origem na barra do navegador depois do deploy (ver
secao 2.5). Enquanto `origensPermitidas` estiver vazio (`[]`), qualquer
chamada do navegador do totem com cabecalho `Origin` deve ser rejeitada
com `403` de proposito (fail-closed) — esse e o comportamento padrao
antes de qualquer ambiente ser configurado, e tambem o rollback seguro
caso a origem ativa precise ser desativada.

### 4.6. Parar o servico manualmente (teste/depuracao)

Quando o servico estiver rodando fora do Servico do Windows — ex. via
`npm start`/`node src/server.js` direto no console, durante um teste ou
diagnostico manual — e precisar ser encerrado:

- **NUNCA usar `taskkill /IM node.exe`** (nem qualquer variante por nome
  de processo). Isso mataria **todo** processo `node.exe` em execucao na
  maquina, incluindo outros servicos Node sem nenhuma relacao com este
  (ja aconteceu um incidente real nesta mesma demanda, corrigido na
  hora).
- **Sempre encerrar pelo PID especifico** deste processo:
  1. Identificar o PID — ou pelo numero que o console mostra ao rodar
     `npm start`/`node src/server.js`, ou consultando:
     ```
     Get-Process node
     ```
  2. Encerrar so aquele PID:
     ```
     taskkill /PID <pid especifico> /T /F
     ```
- Se o servico estiver rodando como Servico do Windows (instalado via
  `npm run instalar-servico-windows`), usar os comandos proprios de
  servico em vez de `taskkill`:
  ```
  Stop-Service "UDLOG Servico Impressao Local"
  ```

Essa mesma regra (PID especifico, nunca por nome) ja vale para o
encerramento AUTOMATICO por `timeoutMs` (secao 2.5, "Ao estourar") — aqui
ela e formalizada tambem para quando a parada e feita manualmente por uma
pessoa.

## 5. O que este servico NUNCA faz

- Nunca faz bind em `0.0.0.0` — sempre `127.0.0.1` (fixo em
  `src/config.js`, nao configuravel).
- Nunca aceita caminho de arquivo do navegador — so conteudo PDF em
  base64 no corpo da requisicao.
- Nunca gera conteudo/layout de etiqueta — so imprime o PDF que recebeu.
- Nunca persiste a escolha de impressora — isso e responsabilidade do
  front-end do totem (localStorage).

## 6. Pendencias conhecidas

- **Ativacao de `origensPermitidas` para producao** (`https://udlog.online`)
  em `config/config.json` — origem ja confirmada e documentada na secao
  2.5, mas so sera ativada no arquivo real durante o deploy final no mini
  PC (nao antes disso).
- **Validacao no hardware fisico** (mini PC + `EPSON TM-T88VII Receipt`
  via USB + Epson Advanced Printer Driver 6) — nada disso foi testado de
  verdade ainda; este README e o roteiro para quando o equipamento
  existir, nao uma confirmacao de que ja funciona.
