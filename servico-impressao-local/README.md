# servico-impressao-local (totem UDLOG)

Servico Node.js **standalone**, fora do app PHP/Hostgator, que roda no
mini PC Windows do totem. Recebe um PDF ja pronto (gerado por FPDF no
backend PHP) em base64 e manda para a impressora fisica indicada. Nao
gera conteudo de etiqueta, nao decide layout/formato — isso e sempre do
backend PHP.

Este documento cobre instalacao e operacao. O hardware de producao e a EPSON
TM-T88VII (driver `Receipt6`, porta `TMUSB001`, impressora `EPSON TM-T88VII Receipt`).
Impressao real, papel "totem" 80x80, `printSettings`, tarefa de logon e deteccao de
falta de papel foram exercitados no PC de desenvolvimento com a TM-T88VII; a
validacao fisica completa no mini PC de producao continua pendente (secao 6).

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
- **`node-windows`**: usada so na tentativa de Servico do Windows, que foi
  DESCARTADA (ver secao 3); a inicializacao automatica adotada e a tarefa de
  logon do Agendador de Tarefas (secao 3.1).
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
   Isso instala `express`, `pdf-to-printer` e `node-windows` (legado; servico do Windows descartado) (nenhuma
   exige compilacao nativa).

### 2.4. Gerar o token do servico

Este servico usa um token PROPRIO (nunca reutilizar token do totem ou
do Talent). Gerar um valor aleatorio forte:

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
> - **Nunca** registrar o token em print de tela, log, documentacao
>   ou ticket.
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
       "origensPermitidas": ["https://totem.udlog.online"]
       ```
       Repare que e `https://totem.udlog.online` (com HTTPS) — `http://totem.udlog.online`
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
       `["https://totem.udlog.online"]` no mini PC, reconfirmar visualmente na
       barra de endereco do navegador do totem que a origem realmente
       servida e exatamente `https://totem.udlog.online`, sem path, antes de
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

### 2.6. Testar manualmente antes de instalar a tarefa de logon

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

**Decisao adotada: tarefa de logon do Agendador de Tarefas sob a conta do
usuario** (item 3.1). O servico do Windows (`node-windows`, LocalSystem) foi
**DESCARTADO**: como LocalSystem o job de impressao travou no spooler (sem as
preferencias da EPSON nem o papel "totem" 80x80 do usuario). Os scripts
`npm run instalar-servico-windows` / `desinstalar-servico-windows` continuam no
repositorio apenas por historico e **nao devem ser usados**.

### 3.1. Tarefa de logon sob a conta do usuario (ADOTADA)

Uma **tarefa do Agendador de Tarefas** roda `node src\server.js` no logon, sob a
conta do usuario (nao LocalSystem), sem janela e sem senha armazenada:

```
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\instalar-tarefa-logon.ps1   # -Force substitui a existente
Start-ScheduledTask -TaskName "UDLOG Servico Impressao Local (logon)"
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\desinstalar-tarefa-logon.ps1
```

E execucao unica: a tarefa persiste entre reinicios e inicia a cada logon
(reinicia 3x/1 min em falha). So funciona com o usuario logado; no mini PC
de producao usar login automatico do Windows com o usuario do kiosk
(decisao do deploy final). Pare o `npm start` manual antes (porta 4747).

## 4. Roteiro de diagnostico

### 4.1. O servico esta rodando?

- Via Agendador de Tarefas (PowerShell):
  ```
  Get-ScheduledTask -TaskName "UDLOG Servico Impressao Local (logon)" | Get-ScheduledTaskInfo
  ```
  (estado "Running" / `LastTaskResult` 267009 = em execucao).
- Via porta: `Get-NetTCPConnection -LocalPort 4747 -State Listen`.
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
configurada e outra — checar `config/config.json` e se a tarefa de logon
esta em execucao (4.1); para ver o log, rodar `npm start` no console.

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

**Nota — como a listagem e feita.** `GET /impressoras` usa um listador
proprio (`src/lib/listarImpressoras.js`): PowerShell
(`Get-CimInstance Win32_Printer`) com saida **JSON** em UTF-8, timeout de
10 s. O `getPrinters()` do `pdf-to-printer` (v5.6.x) foi abandonado para
isso porque le texto formatado e quebra com
`TypeError: Cannot read properties of undefined (reading 'match')` quando
alguma impressora instalada (ex.: virtual) tem lista longa de papeis — o
PowerShell quebra o valor em linhas sem `:` e a listagem inteira falha
(HTTP 500). O `pdf-to-printer` continua sendo usado so para imprimir.

**Arquivos a copiar para o mini PC** (dentro de `servico-impressao-local/`),
depois reiniciar o servico (encerrar o `node` e disparar a tarefa de logon):
- `src/lib/listarImpressoras.js` (novo)
- `src/routes/impressoras.js` (alterado)

(`README.md`, `package.json` e `tests/listar-impressoras.test.js` sao so
documentacao/testes; opcionais no mini PC.)

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

- Opcao `printSettings` (opcional, `config.json`): string passada ao SumatraPDF como
  `-print-settings`. Ausente = padrao `noscale,portrait,paper=totem`; `""` = sem a opcao
  (comportamento antigo, para reverter); aceita so `A-Za-z0-9,=._ -` ate 100 caracteres
  (invalida = o servico nao inicia). Exige reiniciar o servico para valer.
- Deteccao de falta de papel: apos o Sumatra sair com codigo 0, o servico consulta a fila da
  impressora (PowerShell fixo, `Get-PrintJob`, a cada 500 ms). Job que some da fila = `impresso`;
  job que continua na fila ate `semPapelTimeoutMs` (padrao 10000 ms, aceita 3000-60000) = o servico
  remove so esse job (`Remove-PrintJob` por Id) e responde HTTP 409 `{"status":"sem_papel"}` (nao
  entra na idempotencia). Se a consulta falhar ou o job nunca aparecer em ~3 s, responde `impresso`.
  Desligar com `"deteccaoSemPapel": false`. Limitacao: job lento (>10 s) pode ser tratado como
  sem papel; ajustar `semPapelTimeoutMs`. Chaves opcionais, invalidas = o servico nao inicia.
  - **Producao atual** (mini PC): EPSON TM-T88VII, driver `EPSON TM-T88VII Receipt6`, porta
    `TMUSB001`, impressora renomeada para `EPSON TM-T88VII Receipt` (nome usado na allowlist e no
    campo `impressora`). Com este driver o `DocumentName` do job e so o nome do arquivo
    temporario; sem papel o job fica `Printing, Retained` (0 paginas, 36+ s), e com papel sai da
    fila em menos de 1 s (medicao registrada em `src/lib/verificarFila.js`). Os campos de estado
    da impressora nao distinguem os dois casos nesse driver; a deteccao e so pela permanencia do
    job na fila. A comparacao e sempre pelo NOME BASE do documento (ultimo segmento apos `\` ou
    `/`) com igualdade exata contra o arquivo temporario (UUID interno); a remocao
    (`Remove-PrintJob`) tambem so ocorre se o nome base conferir.
  - **Historico / outro modelo** (nao e a producao atual): EPSON TM-T88V, driver
    `EPSON TM-T88V Receipt5`, porta `ESDPRT001`. Nele o Windows registra o `DocumentName` com o
    CAMINHO COMPLETO (`C:\Users\...\AppData\Local\Temp\impressao-local-udlog-<uuid>.pdf`), e foi
    isso que motivou a comparacao pelo nome base: antes dela o job nunca era "visto" e o servico
    respondia `impresso` (`job_nao_visto`) mesmo sem papel. A medicao abaixo vale so para esse
    modelo/driver. O `PrinterState` e so referencia: NAO e usado como sinal.

    Medicao historica (TM-T88V, Receipt5, ESDPRT001):

    | Situacao | PrinterState | PrinterStatus | Job na fila |
    |---|---|---|---|
    | Com papel, parada | 0 | 3 | nenhum |
    | Imprimindo | 1024 | - | sai em ~3 s |
    | Sem papel | 144 (PAPER_OUT 0x10 + OFFLINE 0x80) | 2 | `Normal`, retido (>24 s) |
- O script `scripts/teste-escpos-raw.ps1` e descartavel/diagnostico (envio ESC/POS RAW de reguas),
  nao e usado em producao nem faz parte do fluxo de impressao do servico.
- Repetir a mesma chamada com o mesmo `identificador` deve retornar
  `{"status":"ja_impresso", ...}` sem imprimir de novo.
- Enviar um `pdf_base64` que nao comeca com `%PDF` deve retornar `400`.
- Enviar sem `Authorization` deve retornar `401`.

### 4.4.0. Script de teste de impressao (recomendado)

Le o token do `config/config.json` (nunca o exibe) e faz uma unica
tentativa, sem retry:

```
cd servico-impressao-local
npm run teste:impressao                 # verificacao: GET /saude e /impressoras, NAO imprime
npm run teste:impressao -- --imprimir   # imprime 1 etiqueta de TESTE (padrao 80x50 mm, sem dado pessoal)
npm run teste:impressao -- --imprimir --largura=80 --altura=50   # tamanho da pagina do PDF em mm (largura x altura)
```

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
producao `https://totem.udlog.online` esta documentada mas **nao** ativada
neste arquivo — sera ligada somente no deploy final no mini PC, com
reconfirmacao da origem na barra do navegador depois do deploy (ver
secao 2.5). Enquanto `origensPermitidas` estiver vazio (`[]`), qualquer
chamada do navegador do totem com cabecalho `Origin` deve ser rejeitada
com `403` de proposito (fail-closed) — esse e o comportamento padrao
antes de qualquer ambiente ser configurado, e tambem o rollback seguro
caso a origem ativa precise ser desativada.

### 4.6. Parar o servico manualmente (teste/depuracao)

Quando o servico estiver rodando via tarefa de logon ou direto no console
(`npm start`/`node src/server.js`), durante um teste ou diagnostico manual, e
precisar ser encerrado (para a tarefa, preferir `Stop-ScheduledTask -TaskName
"UDLOG Servico Impressao Local (logon)"`; o `taskkill` abaixo e sempre por PID):

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

- **Ativacao de `origensPermitidas` para producao** (`https://totem.udlog.online`)
  em `config/config.json` — origem ja confirmada e documentada na secao
  2.5, mas so sera ativada no arquivo real durante o deploy final no mini
  PC (nao antes disso).
- **Validacao no hardware fisico do mini PC de producao** (`EPSON TM-T88VII
  Receipt` via USB (porta `TMUSB001`) + Epson Advanced Printer Driver 6 (`Receipt6`), papel "totem" 80x80,
  tarefa de logon com login automatico): pendente, adiada para o deploy final.
  Testes fisicos ainda nao feitos: falta de papel real pela fila, duas etiquetas
  (motorista + ajudante) em sequencia.
- **Largura util da etiqueta**: a TM-T88VII imprime so ~50,8 mm dos 80 mm; o
  backend gera o conteudo em area util de 48 mm (`ETIQUETA_AREA_UTIL_MM`).
