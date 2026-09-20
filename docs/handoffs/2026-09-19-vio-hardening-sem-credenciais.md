# Handoff — vio-hardening-sem-credenciais

## Objetivo

Planejar reforços de segurança, robustez e testabilidade na integração
VIO Decode (CNH/CRLV) executáveis SEM credenciais Trial e SEM chamada
real ao Serpro. Etapa `/00-planejamento` — nenhum código alterado.

## Metodologia

4 especialistas independentes investigaram em paralelo, cada um lendo o
código real (não apenas descrições históricas): `backend-especialista`
(API/contrato/log), `frontend-especialista` (fluxo jsQR/QR local),
`security-especialista` (segurança/privacidade transversal), `qa-testes`
(matriz de testes). Nenhuma credencial obtida/usada, nenhuma chamada
real ao VIO/Serpro/Talent, nenhum documento/CPF/placa/QR real usado.

## Pendências históricas — estado real confirmado

Confirmadas como GENUINAMENTE RESOLVIDAS (evidência de código, por 2
revisores independentes — backend e security, ambos citando os mesmos
arquivos/linhas):

1. **Rebaixamento para MANUAL**: `app/Rn/AtendimentoRn.php::salvarDadosMotorista()`
   (linhas 78-160) compara valor confirmado contra snapshot
   (`cnh_snapshot_*`/`crlv_snapshot_*`) só quando a origem atual é
   `VIO_TRIAL`/`VIO_VALIDADO`; se divergir, rebaixa para `MANUAL` +
   `status_revisao='PENDENTE_REVISAO'`. Decisão exclusiva do backend —
   nenhum campo de origem vindo do cliente é aceito.
2. **Descarte do campo `image`**: NÃO é uma blocklist pontual — é uma
   **allowlist positiva e fechada** em `app/Rn/DocumentoRn.php`
   (`CAMPOS_PERMITIDOS_CNH = ['nome','cpf','data_validade']` linha 42;
   `CAMPOS_PERMITIDOS_CRLV = ['placa','exercicio','uf','rntrc','tipo']`
   linha 63). Só essas chaves são lidas de `$dadosBrutos` por acesso
   nomeado direto; `image`/`template`/qualquer campo novo da VIO nunca
   é sequer copiado para uma variável que sobreviva ao bloco —
   estruturalmente impossível vazar por esse caminho hoje. Fail-closed
   a campo desconhecido (padrão correto, mais forte que blocklist).
3. **Simetria de `ehValorPlaceholder()`**: método único e privado em
   `DocumentoRn.php:395-407`, chamado por `avaliarCnh()` (nome) e
   `avaliarCrlv()` (placa/exercício/uf/rntc/tipo) com a mesma lógica.

Confirmadas como AINDA REAIS (sem mudança de estado):

- Credenciais Trial (Consumer Key/Secret) da VIO Decode não obtidas —
  Grupo 2 (bloqueia teste de contrato/autenticação real).
- Correspondência prática de `jsQR.binaryData` (câmera real) com o raw
  value esperado pela VIO Decode para CNH/CRLV reais — Grupo 3 (só
  teste físico controlado resolve; NÃO usar documento pessoal).
- Rótulo VIO_TRIAL/VIO_VALIDADO/MANUAL duplicado front/back —
  esclarecido pelo `frontend-especialista`: é só um `if/else` de
  apresentação de um booleano (`aviso_trial`) já decidido 100% pelo
  backend, não é lógica de negócio duplicada real. Baixo valor de
  correção, não prioritário.

## Achados NOVOS — implementáveis agora, sem credenciais (Grupo 1)

Confirmados de forma independente e convergente por 2 revisores
(backend-especialista e security-especialista), mesma linha de código:

### 1. Mensagem técnica de falha de autenticação vaza ao cliente HTTP

`app/Rn/VioDecodeClient.php:105` — em falha ao obter token OAuth2, o
erro estruturado inclui `'Falha ao obter token de acesso: ' .
$e->getMessage()` (mensagem original inclui HTTP status/curl errno da
chamada backend→VIO, ex.: `"Falha ao obter token OAuth2 (HTTP 401,
curl errno 0)"`). Esse valor propaga por `DocumentoRn::validarCnh/
validarCrlv` até o campo `'motivo'` do resultado, devolvido ao totem
via `Resposta::sucesso($resultado)` em
`DocumentoController.php:285` — ou seja, chega ao HTTP response do
cliente, não fica só em log de servidor.

Não inclui credencial/token/CPF/QR — é detalhe técnico de integração
(status HTTP/curl errno), mas contraria a regra do projeto de nunca
expor detalhe interno ao cliente (mesmo padrão já corrigido em
`NotaController`/outras demandas desta sessão). **Severidade: baixa/
atenção.**

Correção proposta (não implementada nesta etapa): mensagem estática
genérica no campo `'motivo'` (ex.: "Falha ao validar documento junto
ao serviço externo"), detalhe técnico completo só via `error_log` no
`DocumentoController` (que já loga essas exceções).

### 2. Ausência de limite de tamanho na resposta HTTP da VIO antes do parse

`VioDecodeClient::chamarDecode()` usa `CURLOPT_RETURNTRANSFER =>
true` sem `CURLOPT_MAXFILESIZE_LARGE` nem checagem de `Content-Length`
antes de aceitar o corpo — uma resposta HTTP 200 anormalmente grande
(bug do servidor VIO, ou MITM em rede comprometida) seria
integralmente carregada em memória antes do `json_decode`.
**Severidade: baixa** (exige VIO/MITM comprometido; mitigado
parcialmente pelo timeout de 20s já existente). Mitigação real (grupo
2, precisa confirmar tamanho típico de resposta real) vs. defesa em
profundidade (grupo 1, implementável hoje com um teto genérico
independente do tamanho real).

### 3. Robustez de `catch (\Throwable $e)` genéricos (observação, não achado confirmado)

`DocumentoController.php:248,398` — catches genéricos que hoje não
propagam `getMessage()` para o cliente (confirmado por leitura), mas
são frágeis a regressão futura (uma nova exceção de outro ponto do
código poderia, em tese, ter uma mensagem mais rica). Recomendação:
padronizar para nunca propagar `getMessage()` de catch genérico ao
cliente, só ao log — mesmo padrão já usado em `NotaController`.

### 4. Achado de teste a confirmar (não correção de código, é item da matriz)

`qa-testes` identificou que `DocumentoRn::validarCnh/validarCrlv`
acessam campos por `$dadosBrutos[chave] ?? ''` — SE `$dadosBrutos`
não for array (resposta malformada com `dados`/`data` como string ou
null), isso pode gerar `TypeError: Cannot access offset of type
string`. NÃO CONFIRMADO como falha real (não foi executado teste),
registrado como item explícito da matriz de testes (cenário 4) para
confirmar/reproduzir na etapa `/01-implementacao` — se reproduzir, é
achado real a corrigir; se `??` já proteger adequadamente, sem ação.

## Achados de baixa prioridade (front-end, opcionais)

- Teto de tamanho defensivo em `binaryData` no worker/`app.js` (não é
  correção de falha, é reforço — o teto físico do QR spec já limita
  isso na prática).
- Suíte de teste automatizado com mock de `Worker` para o fluxo de
  front-end sem browser real (jsdom + mock manual de `Worker`).

## Confirmações de segurança SEM achado (nenhuma mudança necessária)

- 100% das gravações relacionadas a VIO usam prepared statements com
  bind nomeado (`VioCacheDao`, `RateLimitVioStatusDao`,
  `AtendimentoDao`) — zero concatenação de SQL.
- QR bruto NUNCA persistido — só `HMAC-SHA256` do QR é gravado como
  `identificador_qr` (`DocumentoRn::calcularIdentificador`).
- CPF/nome de CNH criptografados em repouso (AES-256-GCM,
  `util/CriptografiaHelper.php`, IV único por operação, tag
  verificada na descriptografia).
- Nenhum IDOR remanescente nos 4 endpoints relacionados (upload,
  iniciar processamento, status, preencher manual) — todos passam por
  posse (`id_totem`) + tipo/status/etapa via allowlist fechada.
- Nenhum bypass de aprovação — nenhum parâmetro do cliente determina
  origem/status de validação; documento já aprovado nunca é
  reprocessado silenciosamente (rechecagem relê do banco, não confia
  em cache de execução).
- Front-end: separação real confirmada (nunca chama Serpro/VIO
  diretamente, só o próprio backend do totem); usa exclusivamente
  `binaryData` (nunca `code.data` textual, elimina divergência de
  encoding); conversão de bytes→base64 correta (sem tratamento como
  texto UTF-8); zero log/persistência de QR/CNH/CRLV em
  console/localStorage/sessionStorage.
- Fail-closed de credenciais confirmado: `VioDecodeClient::__construct`
  lança `RuntimeException` para `VIO_AMBIENTE` ausente/inválido ou
  qualquer variável obrigatória do ambiente escolhido vazia — nunca
  chama a API com credencial vazia; mensagens citam só nomes de
  variável, nunca valor.

## Plano de implementação por arquivo (proposta para `/01-implementacao`, se autorizada)

| Arquivo | Mudança proposta | Grupo |
|---|---|---|
| `app/Rn/VioDecodeClient.php` | Sanitizar mensagem de erro de falha de autenticação (não propagar `getMessage()` bruto ao campo `motivo`); adicionar teto de tamanho de resposta antes do `json_decode` (`CURLOPT_MAXFILESIZE_LARGE` ou checagem de `Content-Length`) | 1 |
| `app/Controller/DocumentoController.php` | Reforçar catches genéricos para nunca propagar `getMessage()` de exceção não prevista ao cliente (defesa em profundidade, padrão já usado em `NotaController`) | 1 |
| `public/totem/assets/qr-worker.js` / `app.js` | (Opcional, baixa prioridade) teto de tamanho defensivo em `binaryData` | 1 (opcional) |
| Novo: `tests/manual/teste_vio_decode_robustez.php` (ou extensão de `teste_vio_decode.php`) | Cobrir a matriz de testes abaixo usando o padrão já existente `VioDecodeClientFalso` + mock server HTTP local (`php -S`) para os 2 cenários que exigem nível de transporte real | 1 |
| Novo: mock server de teste (`tests/manual/mock_vio_server.php`, artefato só de teste) | Simular respostas HTTP 200/400/422/415/timeout/corpo grande para os cenários que não são cobertos por mock de `decodificar()` | 1 |

Nenhuma mudança em `DocumentoRn.php` foi proposta pelos achados de
segurança/backend — a allowlist e a lógica de validação já estão
corretas. Só será alterado SE o cenário 4 da matriz (TypeError
potencial) for confirmado como falha real durante `/01-implementacao`.

## Matriz de testes (resumo — 14 cenários, detalhe completo nos relatórios dos especialistas)

Todos usando `VioDecodeClientFalso` (padrão já estabelecido no
projeto, `tests/manual/teste_vio_decode.php`) ou injeção de `$env` no
construtor — nenhum precisa de credencial real:

1. Sucesso CNH/CRLV completo — testável hoje.
2. Falha de rede/timeout simulada — testável hoje (mock direto) +
   mock server local para nível de transporte.
3. Resposta parcial (campos faltando) — testável hoje.
4. Resposta malformada (JSON inválido/tipo errado) — testável hoje;
   inclui checagem do possível `TypeError` (achado 4 acima).
5. Campos extras/desconhecidos, incluindo reintrodução de `image` —
   testável hoje (teste de regressão direto do achado histórico).
6. Ausência de credencial (`$env` vazio no construtor) — testável
   hoje, sem tocar `.env` real.
7. Fallback manual funciona quando VIO falha — testável hoje.
8. Edição posterior rebaixa para MANUAL (regressão) — já coberto por
   `teste_rebaixamento_manual.php` (39 asserções), só reexecutar.
9. Sanitização de log/resposta varrendo todos os tipos de erro
   estruturado — testável hoje.
10. Placeholder simetria CNH/CRLV (regressão) — testável hoje.
11. Timeout de PROCESSANDO / tentativa zumbi (regressão) — já coberto
    por `teste_status_processamento.php`, mais 1 caso novo de
    "tentativa zumbi" explícito.
12. Concorrência com mock (variação sem Trial vivo dos testes
    `teste_concorrencia_*` já existentes) — testável hoje, requer só
    que o script helper aceite injetar o mock.
13. Envelope de resposta variando (achatado/`data`/ausente) —
    testável hoje.
14. `normalizarData()` com formatos inválidos plausíveis — testável
    hoje.

Critério de aceite geral: nenhum cenário resulta em fatal error cru,
nenhum vaza dado sensível (grep em log/resposta), nenhum aprova
documento com dado inválido/incompleto, fallback manual sempre
disponível, rebaixamento sempre ocorre quando aplicável.

Nenhum cenário desta matriz depende de credencial Trial ou QR físico.

## Decisões de escopo — 3 grupos

**Grupo 1 — implementável agora sem credenciais** (ver tabela acima):
sanitização de mensagem de erro de autenticação; teto de tamanho de
resposta; reforço de catches genéricos; matriz de testes completa
(14 cenários) com mock server local.

**Grupo 2 — depende de credenciais Trial**: confirmar tamanho real de
resposta da VIO (para dimensionar o teto do item acima com precisão);
confirmar formato exato de `data_validade` de CNH real; contrato de
resposta ambígua (2 candidatos) não documentado oficialmente.

**Grupo 3 — depende de teste físico controlado** (sem documento
pessoal): correspondência de `jsQR.binaryData` via câmera real com o
raw value esperado pela VIO; robustez de captura por câmera real
(ângulo/iluminação/foco do Netum SD-2000).

Nenhum item dos grupos 2/3 bloqueia a execução do grupo 1.

## Riscos e rollback

Nenhuma alteração funcional foi feita nesta etapa (só planejamento).
Para a futura `/01-implementacao`: as mudanças propostas são todas de
sanitização/defesa em profundidade (nunca alteram o contrato de
sucesso, nunca alteram a lógica de aprovação/rejeição) — risco de
regressão baixo, rollback trivial (reverter o commit, sem migration
nem alteração de schema envolvida). Nenhum item requer alteração de
banco.

## Decisões bloqueantes que precisam de confirmação do usuário

Nenhuma decisão é tecnicamente bloqueante para o Grupo 1 — todos os
itens são reforços de robustez/sanitização de baixa complexidade,
sem impacto em contrato ou regra de negócio. Fica para confirmação do
usuário apenas: (a) autorizar `/01-implementacao` do Grupo 1 nesta
demanda; (b) decidir se os itens de baixa prioridade de front-end
(teto de tamanho de `binaryData`, suíte de teste com mock de Worker)
entram no mesmo escopo ou ficam para depois.

## Trello

card_id: `6aae9bbdda2fce08c1b7269d`
URL: https://trello.com/c/KdC4cleD/2137-bruno-sistema-totem-hardening-vio-sem-credenciais
Lista: "Sprint Bruno - Fazendo [Semanal]"
Título: `Bruno: sistema totem - Hardening VIO sem credenciais`

Comentário de resumo do planejamento postado (id `6aae9bcb865bb387fbc9db9a`).

Uso obrigatório deste `card_id` nas próximas etapas desta demanda
(`/01-implementacao` em diante) — nunca buscar por título.

## Próximo passo

Aguardar decisão do usuário sobre autorizar `/01-implementacao` do
grupo 1 (itens implementáveis sem credenciais).

## Implementação do Grupo 1 (2026-09-19)

Autorizada e executada a `/01-implementacao` do Grupo 1 (achados 1 e 2
do planejamento) + investigação empírica do item 4 (matriz de testes).
Nenhum item do Grupo 2/3 foi tocado. Nenhuma credencial obtida/usada,
nenhuma chamada real ao VIO/Serpro/Talent, nenhum documento/CPF/placa/
QR real usado, zero commit/push.

### 1. Sanitização da mensagem de erro técnica (achado 1)

Arquivo: `app/Rn/VioDecodeClient.php`.

Antes (linha ~105, catch de falha de autenticação):

```php
} catch (\Throwable $e) {
    return $this->erroEstruturado('autenticacao', null, null, 'Falha ao obter token de acesso: ' . $e->getMessage());
}
```

`$e->getMessage()` continha, por exemplo,
`"Falha ao obter token OAuth2 (HTTP 401, curl errno 0)"` — propagava
até o campo `motivo` devolvido ao totem via `Resposta::sucesso()`.

Depois:

```php
} catch (\Throwable $e) {
    $this->logFalhaTecnica('autenticacao');
    return $this->erroEstruturado('autenticacao', null, null, self::MENSAGEM_ERRO_GENERICA);
}
```

`MENSAGEM_ERRO_GENERICA` é uma constante fixa: `"Nao foi possivel
validar o documento junto ao servico externo. Preencha manualmente."`
— aplicada em **todos** os ramos de erro de `chamarDecode()` (rede,
timeout, HTTP 400/415/422/401/429/500+/desconhecido, resposta 200 sem
JSON válido), não só no catch de autenticação original — os mesmos
tipos de detalhe técnico (status HTTP embutido na string, referência
ao método interno `VioDecodeClient::chamarDecode` na mensagem do 415)
apareciam em outros ramos e foram sanitizados pela mesma razão
(consistência com a regra do projeto, mesmo campo `motivo` de saída).
`tipo`/`http_status`/`codigo_vio` continuam estruturados internamente
(nunca expostos ao cliente — confirmado: `DocumentoRn::validarCnh/
validarCrlv` só usa `erro.mensagem`, nunca os outros campos, na
composição do `motivo`).

Log interno: `logFalhaTecnica(string $categoria)` grava só um marcador
categorizado fixo (ex.: `"VioDecodeClient: falha tecnica
categoria=autenticacao_401"`), nunca a mensagem bruta de exceção/
resposta externa — mesmo padrão de
`NotaController::logFalhaBancoPdo()` (SQLSTATE validado por regex, não
a mensagem completa), adaptado para categorias fixas de erro de
transporte/autenticação em vez de SQLSTATE.

**Contrato estrutural preservado**: `decodificar()` continua
devolvendo `['ok','ambiente','dados','erro']`; `DocumentoRn::validarCnh/
validarCrlv` continuam devolvendo `['ok','pode_avancar','motivo',
'aviso_trial','origem','status_revisao']` — só o CONTEÚDO da string de
`motivo` mudou (de técnico para genérico funcional).

### 2. Limite de tamanho da resposta HTTP (achado 2)

Arquivo: `app/Rn/VioDecodeClient.php`, `chamarDecode()`.

**Valor escolhido: 10 MB** (`TAMANHO_MAXIMO_RESPOSTA_BYTES = 10 * 1024
* 1024`).

Justificativa (documentada também na docstring da constante no
código):
- Os arquivos oficiais de demonstração do Serpro usados no teste real
  de 2026-09-08 (`docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`)
  são minúsculos como REQUISIÇÃO: `qrcode-trial.bin` = 1041 bytes,
  `crlv-demo.bin` = 232 bytes (confirmado lendo
  `tests/manual/teste_vio_decode_wire_format.php:127-128`, que afirma
  esses tamanhos exatos).
- A RESPOSTA do Trial nesse teste **não contém foto real** (dado de
  demonstração — o handoff de 2026-09-08 não registra nenhum tamanho
  observado do campo `image` em uma resposta real). Não há, portanto,
  nenhum tamanho real medido de uma resposta de Produção com foto —
  isso é pendência de Grupo 2 (precisa de credencial Trial/Produção
  para observar empiricamente), registrada e não inventada.
- Como defesa em profundidade (Grupo 1, independente do tamanho real):
  uma foto de documento (CNH/CRLV) em Base64 pode facilmente passar de
  1-2 MB (JPEG de resolução média/alta + inflação ~33% do Base64). 10
  MB dá margem generosa (5-10x acima do pior caso plausível de uma
  única foto de documento) sem deixar de ser um teto finito que
  protege contra uma resposta anormalmente grande (bug do servidor VIO
  ou MITM em rede comprometida) ser integralmente carregada em memória
  antes do parse.
- Não é uma confirmação de tamanho real de Produção — é uma defesa em
  profundidade genérica, como o próprio planejamento já havia
  classificado este item (Grupo 1 vs. dimensionamento preciso, que
  seria Grupo 2).

Implementação: `CURLOPT_WRITEFUNCTION` customizado (em vez de
`CURLOPT_RETURNTRANSFER`) acumula o corpo manualmente e aborta a
transferência (retornando um tamanho diferente do recebido, que o
libcurl trata como `CURLE_WRITE_ERROR`) assim que o acumulado
ultrapassa o teto — o corpo excedente nunca é integralmente carregado
em memória nem chega ao `json_decode`. Escolhida em vez de
`CURLOPT_MAXFILESIZE_LARGE` (que depende de `Content-Length` correto
do servidor, contornável por um servidor malicioso que omita o
header) ou checagem isolada de `Content-Length` (mesma limitação) —
`CURLOPT_WRITEFUNCTION` é robusto independente do header declarado.

Comportamento ao exceder: rejeitado ANTES do `json_decode`; log
interno só com marcador categorizado (`"resposta_excedeu_limite_tamanho"`),
nunca os bytes/tamanho exato; mensagem ao cliente é a mesma
`MENSAGEM_ERRO_GENERICA` sanitizada; conduz ao mesmo caminho de
fallback manual já existente (via `tipo => 'resposta_excessiva'`,
`ok => false`); nada é persistido em banco/arquivo/cache (o teto atua
antes de qualquer path de persistência do documento).

**Testado exatamente no limite e 1 byte acima**, contra um mock server
HTTP local real (`php -S`, nunca o Serpro real) —
`tests/manual/teste_vio_decode_robustez.php`, cenário `[2]`: resposta
de exatamente `TAMANHO_MAXIMO_RESPOSTA_BYTES` bytes é aceita
(`ok=true`); resposta de `TAMANHO_MAXIMO_RESPOSTA_BYTES + 1` bytes é
rejeitada (`ok=false`), sem detalhe técnico na mensagem.

### 3. Investigação do possível `TypeError` (achado 4 da matriz)

**Resultado: NÃO REPRODUZIDO.** `DocumentoRn.php` **não foi alterado**.

Reprodução isolada (script temporário, removido ao final, nunca
commitado):

```php
$dadosBrutos = "abc"; // string
$nome = trim((string) ($dadosBrutos['nome'] ?? '')); // resultado: ''
// Testado também com: null, int (123), bool (true), array vazio ([])
// — todos resultam em '' (ou array vazio para os campos vetoriais),
// SEM TypeError, SEM warning.
```

O operador `??` (null coalescing) em PHP 8.0 suprime completamente o
warning/erro de "tentar acessar offset de array em valor que não é
array" — o resultado é sempre `null` (convertido a `''` pelo `(string)
...`), nunca uma exceção. Confirmado que é exatamente o mesmo padrão
usado em `DocumentoRn::validarCnh` (linha 127-129) e `validarCrlv`
(linha 210-218).

Confirmado também fim a fim, com `DocumentoRn::validarCnh` real
(conectado ao banco de dev, mock de `VioDecodeClient::decodificar()`),
para 7 formatos malformados diferentes de `$resultadoVio['dados']`:
string simples, `null`, inteiro, booleano, array sem chave `data` nem
campos esperados, `data` como string, `data` como `null` — nenhum
lança exceção, todos resultam em `pode_avancar => false` (fail-closed,
mensagem genérica de campo ausente/inválido, nunca uma mensagem
técnica). Ver `tests/manual/teste_vio_decode_robustez.php`, cenário
`[4]` (14 asserções, todas passando).

Conforme instrução do planejamento, como o `TypeError` **não foi
reproduzido**, nenhuma alteração preventiva foi feita em
`DocumentoRn.php` — a proteção existente (`?? ''`) já é suficiente.

### 4. Item NÃO implementado (fora do escopo obrigatório desta rodada)

`DocumentoController.php:248,398` — catches genéricos que hoje **já
não propagam** `getMessage()` ao cliente (confirmado por leitura de
código, comportamento preservado e verificado nesta rodada). Por
instrução explícita do planejamento, o reforço preventivo desses
catches só seria implementado **se um teste real demonstrasse uma
falha alcançável por um fluxo real** — nenhum teste desta rodada
demonstrou isso (a investigação do item 3 confirmou que o cenário que
poderia acionar esses catches de forma anômala — `$dadosBrutos`
malformado — não gera exceção alguma). Mantido como observação
registrada em `ia_development_state.md`, sem mudança de código.

### 5. Testes executados — matriz de 14 cenários

Novo arquivo: `tests/manual/teste_vio_decode_robustez.php` (roda
contra o banco de dev real, cria e apaga seus próprios dados, nenhum
resíduo). Novo mock server: `tests/manual/mock_vio_server.php` (artefato
só de teste, `php -S` local, roteado por `?cenario=`, nunca acessa o
Serpro real).

**Resultado: 80 de 80 asserções passaram, 0 falhas.**

| # | Cenário | Cobertura nesta suite |
|---|---|---|
| 1 | Sucesso CNH/CRLV completo | 2 asserções (mock direto) |
| 2 | Falha de rede/timeout + limite de tamanho exato/+1 byte | 5 asserções (mock server real, cURL real) |
| 3 | Resposta parcial (campos faltando) | 4 asserções |
| 4 | `$dadosBrutos` não-array (7 formatos) | 14 asserções |
| 5 | Campos extras + reintrodução de `image` | 4 asserções |
| 6 | Ausência de credencial (`$env` sem `VIO_AMBIENTE`/trial/production) | 4 asserções |
| 7 | Fallback manual após falha do VIO | 3 asserções |
| 9 | Sanitização varrendo HTTP 400/401/404/415/422/429/500/502/JSON malformado/vazio | 26 asserções (mock server real, cURL real) |
| 10 | Placeholder simetria CNH/CRLV | 2 asserções |
| 13 | Envelope achatado/`data`/ausente | 3 asserções |
| 14 | `normalizarData()` com formatos inválidos + controle positivo | 13 asserções |

Cenários **8, 11, 12 são regressão pura** de suites já existentes
(ver seção 6 abaixo) — não duplicados nesta suite nova, conforme
orientação do planejamento.

Nota técnica de implementação do mock server: `proc_open()` com
comando em formato STRING no Windows usa `cmd.exe /c` como
intermediário — `proc_terminate()` mata o `cmd.exe`, não o processo
real do PHP embutido (`php -S`), deixando-o órfão na porta. Corrigido
usando `proc_open()` com comando em formato ARRAY (suportado desde PHP
7.4), que evita o wrapper `cmd.exe` e permite finalização correta do
processo filho real. Também evitado uso de pipes não lidos
(`['pipe','w']` sem leitura ativa pode travar o processo filho quando
o buffer do pipe enche, especialmente em Windows) — saída do mock
server redirecionada para arquivo via descriptor.

### 6. Prova negativa obrigatória — vazamento reintroduzido e revertido

Executada conforme exigido: o código original vazador (achado 1) foi
temporariamente reintroduzido em `app/Rn/VioDecodeClient.php`
(`'Falha ao obter token de acesso: ' . $e->getMessage()`), testado com
um script isolado (`VIO_AMBIENTE=production`, `VIO_TOKEN_URL` apontando
para o mock server local retornando HTTP 500), e confirmado que a
mensagem devolvida ao cliente continha `"curl errno"` e `"HTTP 500"` —
exatamente os marcadores que a suíte de sanitização (`[9]`) verifica.
Resultado do teste isolado:

```
Mensagem devolvida ao cliente: 'Falha ao obter token de acesso: Falha ao obter token OAuth2 (HTTP 500, curl errno 0)'
DETECTOU VAZAMENTO: marcador 'curl errno' encontrado na mensagem
DETECTOU VAZAMENTO: marcador 'HTTP 500' encontrado na mensagem
RESULTADO: teste de sanitizacao TERIA FALHADO (vazamento detectado corretamente)
```

Revertido 100% via cópia de backup do arquivo corrigido, confirmado
`diff` idêntico byte a byte antes de prosseguir. `git diff
app/Rn/VioDecodeClient.php` após a reversão mostra só as mudanças
pretendidas desta demanda (112 linhas adicionadas líquidas, nenhum
resquício do teste de prova negativa).

### 7. Regressão — suites reexecutadas

Todas via `php tests/manual/<arquivo>.php`, contra o banco de dev
real, sem alteração no `.env` real, zero chamada externa real:

| Suite | Resultado |
|---|---|
| `teste_vio_decode.php` | 21/21 |
| `teste_rebaixamento_manual.php` | 45/45 |
| `teste_status_processamento.php` | 9/9 |
| `teste_concorrencia_processamento_vio.php` | 16/16 |
| `teste_avancar_etapa_expedicao.php` | 10/10 |
| `teste_fluxo_recebimento_documentos.php` | 11/11 |
| `teste_talent_uf_crlv.php` | 11/11 |
| `teste_talent_rntc_tipo_crlv.php` | 23/23 |
| `teste_validacao_jpeg_seguro.php` | 22/22 (formato de saída próprio, "PROVA NEGATIVA CONFIRMADA") |

**Zero falha em todas as suites de regressão.** Nenhuma suite
pré-existente precisou de ajuste.

### 8. Validações finais

- `php -l` em todos os arquivos alterados/criados: sem erros
  (`app/Rn/VioDecodeClient.php`, `tests/manual/mock_vio_server.php`,
  `tests/manual/teste_vio_decode_robustez.php`).
- Inspeção integral do diff de `app/Rn/VioDecodeClient.php`: nenhuma
  credencial/token/Base64/dado pessoal introduzido; nenhuma mudança de
  contrato estrutural (`ok`/`ambiente`/`dados`/`erro` continuam com os
  mesmos tipos/chaves; `DocumentoRn`/`DocumentoController` não
  tocados).
- Busca por log/mensagem técnica remanescente: `logFalhaTecnica()`
  só recebe strings literais fixas (categorias), nunca variável
  interpolada com conteúdo de exceção/resposta externa.
- `.env` real: inalterado (não lido nem escrito por este trabalho além
  do uso normal do `Dotenv::load()` já existente nos testes).
- Nenhuma dependência nova (`composer.json`/`composer.lock` não
  tocados).
- Nenhum arquivo temporário/resíduo: scripts de reprodução/prova
  negativa criados em `sys_get_temp_dir()`/scratchpad, todos removidos
  ao final; nenhum processo órfão (`tasklist`/`netstat` confirmados
  limpos após cada execução da suite).
- Bancos de QA residuais de outras demandas (`qa013_*`, `qa_iso2`) não
  tocados/consultados.
- Zero commit, zero push nesta etapa (aguardando `/02-testes`/
  `/03-revisao`).

### 9. Achados/observações para uma futura `/02-testes`

- Validar que o valor de 10 MB do teto de tamanho continua adequado
  assim que uma credencial Trial/Produção real estiver disponível
  (Grupo 2) — nenhuma ação necessária até lá, é só uma reconfirmação.
- O item "catches genéricos" (`DocumentoController.php:248,398`)
  permanece como reforço preventivo não-bloqueante, não implementado
  nesta rodada (ver seção 4 acima) — considerar para uma futura rodada
  de robustez se um novo achado real justificar.
- Nenhum item do Grupo 2/3 foi endereçado (fora do escopo desta
  demanda) — seguem como pendências reais registradas em
  `ia_development_state.md`.

## `/02-testes` independente (2026-09-19)

3 revisores independentes (qa-testes, backend-especialista,
security-especialista — novas instancias, sem participacao na
implementacao). Nenhum codigo alterado. Nenhuma credencial/chamada
real ao VIO/Serpro/Talent. Nenhum documento/CPF/placa/QR real.

### Resultado por revisor

**qa-testes -- APROVADO** para seu escopo (sanitizacao/prova
negativa/fallback/regressoes): 28 cenarios proprios adicionais de
sanitizacao (DNS, conexao recusada, token malformado, latencia real)
todos passando; prova negativa REAL executada -- reintroduziu o
vazamento original no arquivo real (com backup), confirmou 10/28
asseriosoes detectando o vazamento, reverteu com md5sum identico
antes/depois; todas as regressoes batendo exatamente com o esperado
(80/80, 21/21, 45/45, 9/9, 16/16, 10/10, 11/11, 11/11, 23/23, 22/22).
Zero residuo/processo remanescente.

**backend-especialista -- PRECISA DE AJUSTE**: limite de 10MB
validado em profundidade nos 14 cenarios pedidos -- confirmado corte
por bytes reais recebidos (nao por `Content-Length` declarado,
inclusive headers mentirosos testados), corte progressivo (nao
espera terminar de baixar), `json_decode` nunca chamado sobre
excedente, pico de memoria ~26 MiB (margem de ~80-90% sobre
`memory_limit` de 128M/256M ja registrado no deploy-checklist), sem
vazamento de memoria entre chamadas sequenciais. Mock server
confirmado seguro (bind 127.0.0.1, sem rede externa, sem credencial,
deterministico). **Achado BLOQUEANTE, reproduzido de forma
independente**: `DocumentoRn::validarCnh()` (linha 127) faz
`trim((string) ($dadosBrutos['nome'] ?? ''))` -- se `nome` vier como
array aninhado (resposta VIO malformada/adulterada), o cast `(string)`
emite so um `E_WARNING` (nao excecao) e produz a string literal
`"Array"`, que passa por todas as validacoes (nao vazia, nao bate
padrao de placeholder) e a CNH e **aprovada e persistida no banco**
com `motorista_nome = "Array"`, `origem=VIO_VALIDADO/VIO_TRIAL`,
`status_revisao=OK` (nunca `PENDENTE_REVISAO`) -- fail-open
silencioso, sem qualquer sinalizacao de revisao manual. Mesmo padrao
de risco em `placa`/`uf`/`rntc`/`tipo` do CRLV (linhas 210,212,217,218),
mitigado so incidentalmente em `placa` pelo gate de comparacao com a
placa real do atendimento, nao por protecao estrutural. **Achado
MODERADO adicional**: `dados` como `stdClass`, ou `cpf`/`data_validade`
como array aninhado, lancam `Error`/`TypeError` reais que ESCAPAM de
`DocumentoRn` (nao capturados internamente) e so nao viram fatal
error exposto ao cliente porque `DocumentoController.php:247` tem um
catch generico `\Throwable` que intercepta uma camada acima -- e
comportamento hoje correto (500 sanitizado, log so com
`getMessage()` no servidor), mas ACIDENTAL/fragil, nao uma protecao
deliberada de `DocumentoRn`. Contradiz a alegacao original de "nao e
necessaria nenhuma correcao preventiva" para esses formatos
especificos (`stdClass`, array aninhado em campo interno), que nao
haviam sido testados na `/01-implementacao`.

**security-especialista -- PRECISA DE AJUSTE (nao bloqueante para o
ja implementado, mas achado real novo)**: confirmou de forma
independente o MESMO achado do backend-especialista (cast sem
`is_string()` produzindo `"Array"` aprovado), classificado como
ATENCAO por este revisor (backend classificou como BLOQUEANTE --
consolidado como BLOQUEANTE, severidade mais alta prevalece).
Confirmou tambem: allowlist funciona corretamente para marcador
grande em `image` (~210KB) e campo desconhecido (nunca sobrevivem em
resposta/cache/log); origem de validacao estruturalmente impossivel
de ser definida pelo cliente em nenhum dos 4 endpoints; varredura
completa de `chamarDecode()`/`decodificar()` sem ramo residual
vazando detalhe tecnico (9 pontos de retorno de erro, todos usando
`MENSAGEM_ERRO_GENERICA`). Achado OBSERVACAO nao bloqueante:
`logFalhaTecnica()` nao valida estruturalmente a categoria recebida
-- seguro hoje so por disciplina dos 8 call sites (todos strings
literais fixas), nao por barreira de codigo; recomendado enum/lista
fechada para regressao futura.

### Veredito consolidado

**PRECISA DE AJUSTE.** 2 achados reais confirmados por multiplos
revisores independentes:

1. **BLOQUEANTE**: `DocumentoRn.php` linhas 127 (CNH `nome`), 210/212/
   217/218 (CRLV `placa`/`uf`/`rntc`/`tipo`) -- cast `(string)` sem
   `is_string()` previo permite que um array aninhado vinda da VIO
   seja silenciosamente convertido para a string `"Array"` e
   aprovado como dado valido, sem excecao, sem sinalizacao de
   revisao manual. Corrigir antes de fechar a demanda.
2. **MODERADO**: os mesmos campos (mais `cpf`/`data_validade`, ja
   protegidos por tipagem `?string`) precisam de uma fronteira de
   validacao de tipo/esquema explicita em `DocumentoRn`, em vez de
   depender do catch generico de `DocumentoController.php:247` para
   nao expor fatal error -- hoje funciona, mas por acidente de
   arquitetura, nao por design.

O trabalho ja implementado (sanitizacao de mensagem tecnica, limite
de 10MB, decisao de nao alterar `DocumentoRn` para o cenario original
de `$dadosBrutos` nao-array) permanece CORRETO e reconfirmado por 3
revisores -- o veredito PRECISA DE AJUSTE e especificamente por estes
2 achados NOVOS, encontrados durante esta rodada de `/02-testes`
independente (nao estavam na lista original do planejamento, pois a
`/01-implementacao` testou `$dadosBrutos` como um todo malformado,
mas nao testou um CAMPO INTERNO individual sendo, ele mesmo, um array
aninhado).

Nenhum achado de vazamento de dado sensivel -- os marcadores
sinteticos usados nos ataques nunca aparecem em nenhum canal
observavel (resposta/log/cache). O risco e de integridade de dado
(nome corrompido aprovado como valido), nao de confidencialidade.

## Trello

card_id: `6aae9bbdda2fce08c1b7269d` -- comentario adicional
registrando o veredito PRECISA DE AJUSTE; cartao mantido em "Sprint
Bruno - Fazendo [Semanal]".

## Proximo passo

Rodar uma rodada curta de `/01-implementacao` restrita aos 2 achados:
adicionar `is_string()` (ou validacao de tipo equivalente) antes do
cast `(string)` nos campos `nome`/`placa`/`uf`/`rntc`/`tipo` em
`DocumentoRn.php`, tratando estrutura invalida como falha controlada
(mesmo padrao de "campo invalido/nao informado" ja usado para os
demais casos de rejeicao) -- nunca aprovando silenciosamente. Depois,
nova confirmacao curta de `/02-testes` antes de `/03-revisao`.

## Correcao dos 2 achados do /02-testes independente (2026-09-19)

Rodada curta de `/01-implementacao`, restrita EXCLUSIVAMENTE aos 2
achados da secao anterior. Nenhuma credencial obtida/usada, nenhuma
chamada real a VIO/Serpro/Talent, nenhum documento/CPF/placa/QR/imagem
real, `.env` intocado, zero commit/push.

### Arquivos alterados/criados

- `app/Rn/DocumentoRn.php` (alterado) -- fronteira de tipo/esquema.
- `app/Rn/DocumentoVioTipoInvalidoException.php` (novo) -- excecao
  interna, mensagem sem valor de payload.
- `tests/manual/teste_vio_decode_matriz_tipos_campos.php` (novo) --
  matriz de tipos por campo.
- `tests/manual/teste_rebaixamento_manual.php` (alterado, manutencao
  de teste -- ver secao "Regressoes" abaixo).
- `ia_development_state.md` (2 achados marcados `~~riscado~~` +
  evidencia).

`DocumentoController.php`, `.env`, `composer.json`/`composer.lock` --
intocados (confirmado via `git diff --stat`, sem entrada).

### Esquema de tipos aplicado (CNH e CRLV)

```php
private const ESQUEMA_TIPOS_CNH = [
    'nome' => 'texto',
    'cpf' => 'texto',
    'data_validade' => 'texto',
];

private const ESQUEMA_TIPOS_CRLV = [
    'placa' => 'texto',
    'exercicio' => 'numerico',
    'uf' => 'texto',
    'rntrc' => 'texto',
    'tipo' => 'texto',
];
```

Regra 'texto': aceita string OU ausencia/null (ausencia continua
tratada como "nao informado" pelas regras de CONTEUDO ja existentes,
nao por esta fronteira) -- REJEITA array, objeto/stdClass, bool, int,
float, recurso. Regra 'numerico': aceita string/int/float OU
ausencia/null -- REJEITA array, objeto/stdClass, bool, recurso.
String vazia e placeholder (`ehValorPlaceholder()`) continuam sendo
responsabilidade exclusiva das regras de CONTEUDO ja existentes --
esta fronteira SO valida tipo.

Extracao usa 2 metodos privados novos:

```php
private function extrairCampoTexto(array $dadosBrutos, string $documento, string $campo): ?string
{
    if (!array_key_exists($campo, $dadosBrutos) || $dadosBrutos[$campo] === null) {
        return null;
    }
    $valor = $dadosBrutos[$campo];
    if (!is_string($valor)) {
        throw new DocumentoVioTipoInvalidoException($documento, $campo, get_debug_type($valor));
    }
    return $valor;
}

private function extrairCampoNumerico(array $dadosBrutos, string $documento, string $campo): int|float|string|null
{
    if (!array_key_exists($campo, $dadosBrutos) || $dadosBrutos[$campo] === null) {
        return null;
    }
    $valor = $dadosBrutos[$campo];
    if (!is_int($valor) && !is_float($valor) && !is_string($valor)) {
        throw new DocumentoVioTipoInvalidoException($documento, $campo, get_debug_type($valor));
    }
    return $valor;
}
```

Antes de chegar a esses metodos, `validarCnh()`/`validarCrlv()`
tambem validam que `dados`/`dados['data']` sao sequer um array via
`is_array()` -- protege contra `stdClass` (que faria `$x['data']`
lancar `Error` fatal real antes mesmo de qualquer campo individual
ser lido, achado MODERADO original).

### Tratamento de estrutura invalida

Tipo incompativel em QUALQUER campo (ou no envelope `dados`/
`dados['data']`) invalida a resposta VIO **inteira** daquele documento
-- nunca so o campo. `validarCnh()`/`validarCrlv()` capturam
`DocumentoVioTipoInvalidoException` internamente (try/catch, dentro do
proprio `DocumentoRn`) e retornam a mesma estrutura de falha ja usada
para resposta VIO incompleta:

```php
private function falhaEstruturaInvalidaCnh(array $atendimento, string $ambiente): array
{
    return [
        'ok' => false,
        'pode_avancar' => false,
        'motivo' => self::MENSAGEM_ESTRUTURA_INVALIDA_CNH, // "Falha ao validar CNH: dados retornados em formato invalido"
        'aviso_trial' => $ambiente === 'trial',
        'origem' => $atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO',
        'status_revisao' => $atendimento['cnh_status_revisao'] ?? 'OK',
    ];
}
```

Efeitos garantidos (com evidencia de teste, ver secao seguinte):
nenhum valor parcial persistido; origem NUNCA marcada como
VIO_TRIAL/VIO_VALIDADO; `status_processamento` nunca fica preso em
PROCESSANDO (o Controller sempre recebe um array `['ok' => false, ...]`
normal, nunca uma excecao, e grava `ERRO`, permitindo nova tentativa);
conduz ao mesmo fallback manual ja existente; mensagem generica
funcional ao cliente (reaproveita o padrao textual "Falha ao validar
CNH/CRLV: <motivo>" ja usado pelos demais ramos de falha desta mesma
funcao -- decisao: manter a mensagem DENTRO de `DocumentoRn` em vez de
importar `VioDecodeClient::MENSAGEM_ERRO_GENERICA`, porque a falha
aqui e de PARSING da resposta ja recebida com sucesso do VIO, uma
camada de responsabilidade diferente da falha de TRANSPORTE que
`VioDecodeClient` trata).

O `DocumentoVioTipoInvalidoException` NUNCA e deixado escapar de
`DocumentoRn` -- o fluxo nao depende mais do catch generico
`\Throwable` de `DocumentoController.php:247` para sobreviver a uma
resposta VIO estruturalmente invalida (esse catch continua existindo,
intocado, como camada de seguranca adicional -- nao foi removido nem
ampliado, conforme instrucao explicita).

### Testes novos -- matrizes por campo

`tests/manual/teste_vio_decode_matriz_tipos_campos.php`: para cada
campo de CNH (`nome`, `cpf`, `data_validade`) e CRLV (`placa`, `uf`,
`rntrc`, `tipo`, `exercicio`), testados: array vazio, array aninhado
(com marcador sintetico `MARCADOR_QA02_ARRAY_e7f3a9`), `stdClass` (com
marcador `MARCADOR_QA02_STDCLASS_b21c40`), inteiro, float, booleano
(true/false) -- mais, como regressao, string vazia, `null`,
placeholder (`"xxxxx"`), e caminho feliz. Mais 3 casos de envelope
invalido (`dados` como `stdClass`, `dados['data']` como `stdClass`/
inteiro). Total: **453 asserções, 453 passaram, 0 falharam**.

Confirmacoes especificas com evidencia real de execucao:
1. `nome` como array NAO vira `"Array"` -- confirmado via
   `motivo !== 'Array'` E recarga direta do atendimento do banco
   (`motorista_nome` nunca contem `"Array"`).
2. `placa`/`uf`/`rntrc`/`tipo` como array NAO sao persistidos --
   confirmado recarregando o atendimento do banco apos a chamada
   (`crlv_origem_validacao` permanece `NAO_VALIDADO`, colunas `crlv_*`
   nunca contem o marcador sintetico).
3. `cpf` como `stdClass` NAO produz `TypeError` -- `excecao === null`
   confirmado (capturado internamente).
4. `data_validade` como array/objeto NAO produz `Error` -- idem.
5. Nenhuma estrutura invalida chega ao DAO -- confirmado indiretamente
   (recarga do atendimento mostra `NAO_VALIDADO`/sem coluna alterada;
   `VioCacheDao::salvarCnh/salvarCrlv` so e chamado apos
   `$avaliacao['pode_avancar']`, que e sempre `false` nestes cenarios).
6. Nenhuma gravacao parcial ocorre -- mesma evidencia do item 5.
7. Origem VIO nao e registrada -- `origem === 'NAO_VALIDADO'`
   confirmado em toda a matriz.
8. Fallback manual permanece acessivel -- `preencherManualCnh/Crlv`
   nao foram alterados nesta rodada, suites de regressao confirmam.
9. Mensagem ao cliente e sanitizada -- `motivo` e sempre a constante
   fixa `MENSAGEM_ESTRUTURA_INVALIDA_CNH/CRLV`, nunca contem nome de
   campo/tipo PHP/marcador.
10. stdout/stderr/logs sem warning/stack trace/dado do payload --
    `display_errors=1`/`error_reporting=E_ALL` explicitos, `error_log`
    redirecionado para arquivo isolado da suite; confirmado: log so
    contem entradas categorizadas (`tipo_recebido=array`/`stdClass`),
    ZERO ocorrencia de `"Array to string conversion"`, ZERO marcador
    sintetico; stdout capturado via `ob_start()`/`ob_get_clean()` em
    cada chamada, sempre vazio.

### Prova negativa

Marcadores `MARCADOR_QA02_ARRAY_e7f3a9`/`MARCADOR_QA02_STDCLASS_b21c40`
usados como chave E valor dentro dos campos malformados. Confirmado
que NUNCA aparecem em: resposta HTTP simulada (`json_encode($resultado)`
verificado em toda a matriz); log isolado da suite (arquivo temporario
dedicado, nunca o log real do servidor); stdout/stderr (capturados via
`ob_*`); banco (`tb_atendimento` recarregado e verificado via
`json_encode()` da linha inteira, nao so das colunas esperadas).
Reaproveitou o banco de dev (`udlog_totem`, mesmo padrao ja aceito nas
3 rodadas anteriores de `/02-testes` desta demanda para
`teste_vio_decode_robustez.php`) -- cria e apaga seus proprios dados
via `id_totem`/`id_atendimento` isolados, confirmado 0 residuo apos a
rodada (consulta `SELECT ... WHERE codigo LIKE 'TESTE_VIO%'` retornou
0 linhas). Bancos `qa013_*`/`qa_iso2` NAO tocados/consultados.

**Mutacao temporaria controlada** (obrigatoria, executada): revertido
temporariamente o codigo de `extrairCampoTexto()`/`validarCnh()`/
`validarCrlv()` para o padrao vulneravel ORIGINAL (cast `(string)`
sem `is_string()` previo, sem a fronteira de tipo), reexecutada a
suite completa -- **79 de 386 asserções passaram a FALHAR**,
confirmando deteccao real da regressao: `motorista_nome` voltou a
aceitar `"Array"` (assercao "motorista_nome NAO persistido como
'Array'/marcador" falhou), e `cpf`/`data_validade`/`placa`/`uf`/
`rntrc`/`tipo` como `stdClass` voltaram a lancar `TypeError`/`Error`
nao capturado (assercao "NAO lanca excecao/TypeError/Error" falhou em
7 campos). Revertido a 100% via copia de backup feita ANTES da
mutacao; confirmado `md5sum` **identico antes e depois**
(`fb2d3f3515f7d3424411a08f5b40dbf5`); `git diff --check` sem problema
de formatacao alem de aviso pre-existente de CRLF/LF (nao relacionado
a este trabalho); suite completa reexecutada apos a reversao --
**453/453 limpo novamente**.

### Regressoes -- contagens exatas

| Suite | Resultado |
|---|---|
| `teste_vio_decode_robustez.php` | 80/80 |
| `teste_vio_decode.php` | 21/21 |
| `teste_status_processamento.php` | 9/9 |
| `teste_concorrencia_processamento_vio.php` | 16/16 |
| `teste_avancar_etapa_expedicao.php` | 10/10 |
| `teste_fluxo_recebimento_documentos.php` | 11/11 |
| `teste_talent_uf_crlv.php` | 11/11 |
| `teste_talent_rntc_tipo_crlv.php` | 23/23 |
| `teste_validacao_jpeg_seguro.php` | 22/22 |
| `teste_rebaixamento_manual.php` | **47/47** (era 45/45 -- ver nota) |

**Nota sobre `teste_rebaixamento_manual.php` (45 -> 47, NAO e uma
falha ignorada)**: a suite tinha 1 assercao estatica que contava
`substr_count()` de `unset($resultadoVio, $dadosBrutos);` no codigo-
fonte de `DocumentoRn.php`, esperando EXATAMENTE 2 ocorrencias (uma
por metodo). A fronteira de tipo desta rodada introduziu
legitimamente mais pontos de retorno antecipado (um por ramo de
rejeicao estrutural, alem do caminho feliz), cada um corretamente
descartando `$resultadoVio`/`$dadosBrutos` antes de retornar -- o
numero literal de ocorrencias subiu de 2 para 8, quebrando a
assercao numerica fixa (mas SEM violar a intencao de seguranca da
assercao original: os dados seguem sendo descartados em TODOS os
caminhos, nao menos). Assercao reescrita para extrair o CORPO de cada
metodo (`validarCnh()`/`validarCrlv()`, via `substr()` entre a
assinatura do metodo e a proxima funcao publica) e confirmar `>= 1`
ocorrencia de descarte em CADA UM -- verificacao mais precisa e mais
robusta a mudanca estrutural futura do que o numero magico anterior.
Resultado: 2 assercoes novas substituindo a antiga (45 -> 47), 0
falhas.

Confirmado explicitamente: a resposta VALIDA existente de CNH e CRLV
(caminho feliz, sem malformacao) continua sendo aceita **sem nenhuma
alteracao de comportamento** -- testes de controle positivo dedicados
em `teste_vio_decode_matriz_tipos_campos.php` e reconfirmacao em todas
as suites de regressao acima.

### Validacoes finais

- `php -l` em todos os arquivos alterados/criados: sem erros
  (`app/Rn/DocumentoRn.php`, `app/Rn/DocumentoVioTipoInvalidoException.php`,
  `tests/manual/teste_vio_decode_matriz_tipos_campos.php`,
  `tests/manual/teste_rebaixamento_manual.php`).
- `git diff --check`: sem problema introduzido por este trabalho (so
  aviso pre-existente de LF/CRLF do ambiente Windows, nao relacionado
  a conteudo).
- Busca por casts `(string)` remanescentes nos campos VIO sem checagem
  de tipo previa: `grep -n "(string) (\$dadosBrutos" app/Rn/DocumentoRn.php`
  -- **zero ocorrencias** (todos substituidos pela fronteira nova).
- Busca por "Array to string conversion": zero ocorrencia real (so
  aparece em comentarios/nomes de assercao do arquivo de teste, nunca
  como warning real gerado).
- Busca por log de excecao bruta: `error_log($e->getMessage())` chama
  `DocumentoVioTipoInvalidoException::getMessage()`, que e construida
  100% a partir de constantes fixas do proprio codigo (nome do
  documento, nome do campo da allowlist, `get_debug_type()`) -- NUNCA
  o valor do payload.
- Busca por segredo/token/Base64/dado pessoal nos arquivos tocados:
  zero ocorrencia.
- `.env`: idêntico antes/depois (nao lido nem escrito por este
  trabalho alem do uso normal ja existente de `Dotenv::load()` nos
  testes).
- `DocumentoController.php`, `composer.json`/`composer.lock`: zero
  alteracao (`git diff --stat` sem entrada).
- Nenhum residuo: 1 arquivo temporario criado por engano durante a
  operacao de backup (path do scratchpad mal escapado no shell,
  criou um arquivo espurio no root do repositorio) -- identificado via
  `git status` e removido antes de prosseguir; log isolado de teste
  (`sys_get_temp_dir()`) removido ao final do proprio script via
  `@unlink()`; 0 linhas de teste remanescentes em `tb_totem`/
  `tb_atendimento` apos a rodada.
- Bancos `qa013_*`/`qa_iso2`: NAO tocados/consultados.
- Zero commit, zero push nesta etapa (aguardando `/02-testes`).

### Avaliacao para nova `/02-testes`

Ambos os achados (BLOQUEANTE e MODERADO) da rodada anterior de
`/02-testes` foram corrigidos com evidencia direta de execucao
(matriz de 453 asserções + mutacao temporaria controlada demonstrando
deteccao real da regressao). Nenhuma regressao de comportamento
introduzida (10 suites preexistentes reconfirmadas, 1 delas com ajuste
de manutencao justificado e documentado, nao uma falha real). Escopo
estritamente restrito aos 2 achados -- `DocumentoController.php`,
`.env`, dependencias, contrato HTTP/JSON, allowlists, `ehValorPlaceholder()`,
`normalizarData()`, banco/schema, frontend, Talent, impressao --
nenhum destes foi tocado. Avaliacao: **pronta para nova rodada de
`/02-testes` independente.**

## Trello

card_id: `6aae9bbdda2fce08c1b7269d` -- NAO atualizado nesta rodada
(instrucao explicita do usuario: atualizacao do Trello fica para
depois).

## Nova rodada de `/02-testes` independente, foco correção de integridade (2026-09-19)

3 revisores independentes (qa-testes, backend-especialista,
security-especialista -- novas instancias, sem participacao na
rodada de correcao). Nenhum codigo alterado. Nenhuma
credencial/chamada real ao VIO/Serpro/Talent. Nenhum documento/CPF/
placa/QR/imagem real.

### Resultado por revisor

**qa-testes -- APROVADO**: reproduziu de forma independente os 7
casos originalmente vulneraveis (33/33 asserçoes proprias, 0
falhas) -- nenhuma string "Array", nenhum TypeError/Error, nenhuma
origem VIO_TRIAL/VIO_VALIDADO gravada, fallback preservado.
Reexecutou e recontou manualmente a cobertura da suite nova
(453/453, numero batendo com a matriz combinatoria real, nao
inflado). Prova negativa independente executada no repositorio real
com backup/hash (`fb2d3f3515f7d3424411a08f5b40dbf5` identico
antes/depois) -- confirmou que a suite detecta a regressao (55/324
falharam ao remover o `is_string()`, via TypeError nao capturado
desta vez, mesma propriedade de deteccao). Confirmou que a mudanca
de 45/45 para 47/47 em `teste_rebaixamento_manual.php` testa
comportamento real, nao reduz cobertura, e a aritmetica confere (1
removida + 3 novas). Todas as 11 regressoes reexecutadas e
batendo. Achado OBSERVACAO nao bloqueante: descricao da asserção
("em TODOS os caminhos... >= 1") e um pouco mais forte do que o que
de fato verifica (so "pelo menos 1", nao por ramo individual) --
confirmado manualmente que o codigo real tem `unset()` nos 4 ramos
hoje, entao nao e uma perda de cobertura real, so uma imprecisao de
redacao do comentario do teste.

**backend-especialista -- APROVADO**: mapeamento completo confirmado
-- todos os 3 campos de `CAMPOS_PERMITIDOS_CNH` e os 5 de
`CAMPOS_PERMITIDOS_CRLV` estao em `ESQUEMA_TIPOS_CNH/CRLV` e passam
por `extrairCampoTexto()`/`extrairCampoNumerico()`, nenhum campo
fora da fronteira; `grep` confirma zero cast `(string)` antes da
checagem de tipo. Testou em banco descartavel dedicado
(`qa_vio_hardening_20260919`, criado/destruido nesta sessao,
confirmado via `SELECT DATABASE()`): atomicidade confirmada em 5
variacoes (1o campo valido+2o invalido, ultimo campo invalido,
estrutura aninhada profunda, dado pre-existente nao sobrescrito,
zero UPDATE parcial estrutural); obrigatorio/opcional/null
preservados sem regressao (campo ausente segue caindo na regra de
CONTEUDO ja existente, nao na fronteira nova); booleano nao vira
0/1 em `exercicio` (rejeitado estruturalmente); string numerica com
zero a esquerda preservada. Achado OBSERVACAO nao bloqueante, PRE-
EXISTENTE e FORA do escopo desta correcao: `exercicio=2024.9`
(float) e aceito pela fronteira de TIPO (float e valido para
'numerico') e depois truncado silenciosamente para `2024` pelo cast
`(int)` ja existente antes desta rodada -- a fronteira nova so valida
TIPO, nao precisao de CONTEUDO; registrado como item de backlog de
robustez, nao pendencia bloqueante.

**security-especialista -- APROVADO**: confirmou que
`DocumentoController.php`/`.env`/dependencias permanecem intocados.
Teste controlado proprio (20/20 asserçoes, marcadores sinteticos
exclusivos) confirmou que `DocumentoVioTipoInvalidoException` nunca
escapa de `DocumentoRn`, e capturada na camada correta, nao depende
do catch generico do Controller como defesa primaria, mensagem sem
valor do payload/dado pessoal. Allowlist reconfirmada correta mesmo
com origem forjada no atendimento (cliente nao consegue definir
origem de validacao). Validaçoes estaticas limpas.

### Veredito consolidado

**APROVADO.** Os 2 achados da rodada anterior (BLOQUEANTE: cast
`(string)` sem `is_string()` produzindo `"Array"` aprovado como
dado valido; MODERADO: `Error`/`TypeError` real escapando de
`DocumentoRn` para `stdClass`/array aninhado) estao corrigidos com
evidencia real e reproduzida de forma independente por 3
revisores, incluindo testes proprios em banco descartavel dedicado
e prova negativa com mutacao/reversao confirmada por hash. Nenhuma
regressao introduzida, nenhum vazamento de dado sensivel/marcador
sintetico em nenhum canal observavel, `DocumentoController.php`/
`.env`/dependencias intocados.

Nenhum achado bloqueante ou moderado novo. 2 observacoes nao
bloqueantes registradas para decisao futura (fora do escopo desta
correcao): (1) descricao da asserçao de `teste_rebaixamento_manual.php`
mais forte do que o que de fato verifica -- sugestao de redacao,
sem perda de cobertura real confirmada; (2) truncamento silencioso
de float em `exercicio` do CRLV (`2024.9` -> `2024`) -- comportamento
pre-existente, herdado do cast ja existente antes desta demanda, a
fronteira nova so valida tipo, nao precisao de conteudo.

## Trello

card_id: `6aae9bbdda2fce08c1b7269d` -- comentario adicional
registrando o veredito APROVADO; cartao mantido em "Sprint Bruno -
Fazendo [Semanal]" (so avanca de lista em `/04-commit-e-push`).

## Proximo passo

Demanda liberada para `/03-revisao`. Os 2 itens de backlog nao
bloqueantes (redacao de asserção; truncamento de float em
`exercicio`) ficam registrados para decisao do usuario sobre
incluir ou nao em rodada futura -- nao fazem parte do escopo desta
demanda.

## `/03-revisao` independente (2026-09-20)

3 revisores independentes (backend-especialista, security-especialista,
qa-testes -- novas instancias, sem participacao na implementacao).
Nenhum codigo alterado. Nenhuma credencial/chamada real. Nenhum
documento/CPF/placa/QR real.

### Resultado por revisor

**security-especialista -- APROVADO**: limpeza de escopo confirmada
(Trello byte-identico a HEAD via hash-object, DocumentoController.php/
.env/composer intocados). Sanitizacao do VioDecodeClient reconfirmada
completa (9 ramos de erro, todos via MENSAGEM_ERRO_GENERICA,
logFalhaTecnica so com categoria fixa). Excecao interna
DocumentoVioTipoInvalidoException confirmada por teste controlado
proprio (10/10) -- nunca escapa de DocumentoRn, mensagem sem valor do
payload. Allowlists/fallback reconfirmados. Achado de higiene NAO
bloqueante: processo `php -S` orfao de rodada anterior de teste
(porta 8091), identificado e encerrado durante a propria revisao --
resíduo de scratchpad, sem exposicao real (bind so 127.0.0.1).

**qa-testes -- APROVADO**: reproduziu os 7 casos originalmente
vulneraveis com evidencia real (zero "Array"/TypeError/Error/
persistencia). Recontou manualmente a matriz de 453 asserções linha
por linha (bate exatamente, sem inflacao). Prova negativa
independente propria (nao reaproveitou a anterior): 55/324 falhas ao
remover a validacao, reversao confirmada por hash identico
(`d15443ac06ae5f5db3954bd746cce980d3f2ffbe`/
`fb2d3f3515f7d3424411a08f5b40dbf5`). Classificou a imprecisao de
redacao em `teste_rebaixamento_manual.php` (45->47) como
ATENCAO/nao bloqueante, seguindo o criterio explicito do usuario:
nao cria evidencia de seguranca falsa sobre a protecao PRIMARIA
(allowlist por acesso nomeado, ja verificada por outras asserções),
o `unset()` e so higiene de memoria secundaria. Todas as 11
regressoes reconfirmadas (453/453, 80/80, 21/21, 47/47, 9/9, 16/16,
10/10, 11/11, 11/11, 23/23, 22/22). Identificou e corrigiu um
arquivo espurio proprio criado por erro de escaping durante a propria
mutacao de teste (removido antes de finalizar).

**backend-especialista -- PRECISA DE AJUSTE (achado BLOQUEANTE
confirmado)**: limite de 10MB reconfirmado correto em profundidade
(mock server real, cURL real, chunked, Content-Length mentiroso
maior/menor -- nenhum contorna o limite; pico de memoria ~26MB,
estabiliza sem crescimento ilimitado em chamadas sequenciais).
Mapeamento de campos confirmado completo (nenhum fora da fronteira).
Atomicidade confirmada em banco descartavel dedicado (6 cenarios,
23/23 asserções). **Achado BLOQUEANTE, reproduzido pelo fluxo real
em banco descartavel**: `exercicio=2026.9` (float) e
`exercicio="2026.9"` (string) sao aprovados normalmente
(`pode_avancar=true`, `origem=VIO_TRIAL`, `status_revisao=OK`) e
persistidos em `crlv_ano` como `2026` -- a parte fracionaria `.9` e
descartada pelo cast `(int)` ja existente em
`DocumentoRn.php:313` sem NENHUMA sinalizacao de perda de precisao.
A fronteira de tipo introduzida nesta demanda protege contra tipos
estruturalmente incompativeis (array/objeto/bool -- confirmado
rejeitados corretamente), mas NAO contra imprecisao de conteudo
dentro de um tipo aceito (float/string numerica). Isso contraria o
criterio explicito da demanda de impedir conversoes silenciosas em
campos VIO. Correcao minima proposta (nao implementada): validar que
o valor numerico de `exercicio` e matematicamente um inteiro exato
antes do cast, rejeitando fracionarios pelo MESMO caminho de
rejeicao de conteudo ja usado para valores negativos/excessivos --
escopo minimo, sem tocar contrato/allowlist/demais campos.

### Veredito consolidado

**PRECISA DE AJUSTE.** Motivo: 1 achado BLOQUEANTE real,
reproduzido pelo fluxo real em banco descartavel pelo
`backend-especialista` -- truncamento silencioso de `exercicio`
fracionario do CRLV, exatamente o cenario que uma rodada anterior
havia descartado como "observacao nao bloqueante, pre-existente" e
que o usuario pediu explicitamente para nao descartar sem
reproducao real nesta `/03-revisao`.

Todo o restante do escopo revisado esta APROVADO por 3 revisores
convergentes: sanitizacao do VioDecodeClient completa; limite de
10MB incontornavel (chunked, Content-Length mentiroso); mapeamento
de campos completo (nenhum fora da fronteira); atomicidade
confirmada; excecao interna corretamente contida; allowlists/
fallback preservados; qualidade da suite de 453 asserções confirmada
sem inflacao; escopo limpo (Trello confirmado byte-identico a HEAD).

1 achado nao bloqueante de higiene operacional (processo de teste
orfao, ja encerrado, sem exposicao real) e 1 achado ja registrado de
imprecisao de redacao em asserção de teste (classificado ATENCAO,
nao bloqueante, por criterio explicito do usuario).

## Trello

card_id: `6aae9bbdda2fce08c1b7269d` -- comentario adicional
registrando o veredito PRECISA DE AJUSTE; cartao mantido em "Sprint
Bruno - Fazendo [Semanal]".

## Proximo passo

Rodar uma rodada curta de `/01-implementacao` restrita ao achado
bloqueante: validar que `exercicio` do CRLV, quando numerico, e
matematicamente um inteiro exato antes de aprovar/persistir,
rejeitando fracionarios pelo mesmo caminho de rejeicao de conteudo
ja usado para valores negativos/excessivos -- escopo minimo, sem
alterar contrato, allowlist ou demais campos. Depois, nova
confirmacao curta de `/02-testes`/`/03-revisao` antes de
`/04-commit-e-push`.

## Correcao final do achado BLOQUEANTE de exercicio (2026-09-20)

Rodada curta e final de `/01-implementacao`, restrita EXCLUSIVAMENTE
ao campo `exercicio` do CRLV (achado BLOQUEANTE do `/03-revisao`
independente da secao anterior). Nenhuma credencial obtida/usada,
nenhuma chamada real a VIO/Serpro/Talent, nenhum documento/CPF/placa/
QR/imagem real, `.env` intocado, zero commit/push.

### Arquivos alterados

- `app/Rn/DocumentoRn.php` (alterado) -- novo metodo privado
  `validarExercicioInteiroExato()` + chamada em `validarCrlv()`.
- `app/Rn/DocumentoVioTipoInvalidoException.php` (alterado, so
  docblock) -- documenta o reaproveitamento da excecao para rejeicao
  de CONTEUDO (nao so tipo), sem mudanca de assinatura/comportamento.
- `tests/manual/teste_vio_decode_matriz_tipos_campos.php` (alterado)
  -- cobertura nova de `exercicio` (453 -> 556 asserções).
- `ia_development_state.md` (achado marcado `~~riscado~~` + evidencia).

`DocumentoController.php`, `.env`, `composer.json`/`composer.lock`,
`VioDecodeClient.php`, `TrelloClient.php`, `tools/trello-cli.php`,
frontend, allowlists, `ehValorPlaceholder()`, `preencherManualCrlv()`
-- intocados (confirmado via `git diff --stat`/`git hash-object`).

### Trecho de codigo -- antes/depois

Antes (`validarCrlv()`, achado do `/03-revisao`):

```php
$exercicioBruto = $this->extrairCampoNumerico($dadosBrutos, 'crlv', self::CAMPOS_PERMITIDOS_CRLV[1]);
// ... (fora do try, apos o catch)
$exercicio = is_numeric($exercicioBruto) ? (int) $exercicioBruto : 0;
```

`is_numeric()` aceita float/string fracionaria/notacao cientifica --
`2026.9` e `"2026.9"` passavam, e o cast `(int)` truncava
silenciosamente para `2026`, aprovado e persistido sem sinalizacao.

Depois:

```php
$exercicioBruto = $this->extrairCampoNumerico($dadosBrutos, 'crlv', self::CAMPOS_PERMITIDOS_CRLV[1]);
$exercicioBruto = $this->validarExercicioInteiroExato('crlv', self::CAMPOS_PERMITIDOS_CRLV[1], $exercicioBruto);
// ... (dentro do mesmo try/catch de DocumentoVioTipoInvalidoException)

private function validarExercicioInteiroExato(string $documento, string $campo, int|float|string|null $valor): int|string|null
{
    if ($valor === null) {
        return null;
    }
    if (is_int($valor)) {
        return $valor;
    }
    if (is_float($valor) || !preg_match('/^-?\d+$/', $valor)) {
        throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_nao_inteiro_exato');
    }
    return $valor;
}
```

A linha `$exercicio = is_numeric($exercicioBruto) ? (int) $exercicioBruto : 0;`
permanece EXATAMENTE igual apos essa mudanca -- ela so passa a operar
sobre um `$exercicioBruto` ja garantido (por construcao) `null`, `int`
exato, ou `string` de digitos puros (sinal negativo opcional) -- nunca
mais um fracionario/cientifico/float. O cast `(int)` deixa de ser
permissivo por decorrencia da fronteira anterior, sem precisar alterar
a linha em si.

### Tabela de formatos aceitos/rejeitados

| Formato | Antes desta rodada | Depois desta rodada |
|---|---|---|
| `2026` (int) | Aceito | Aceito (sem mudanca) |
| `"2026"` (string) | Aceito | Aceito (sem mudanca) |
| `"02026"` (zero a esquerda) | Aceito | Aceito (sem mudanca) |
| `2026.9` (float) | Aceito, truncado p/ `2026` | **Rejeitado** (estrutura invalida) |
| `"2026.9"` (string) | Aceito, truncado p/ `2026` | **Rejeitado** |
| `2026.1` (float) | Aceito, truncado p/ `2026` | **Rejeitado** |
| `2026.0` (float) | Aceito, truncado p/ `2026` | **Rejeitado** (sem evidencia de formato real -- ver decisao abaixo) |
| `"2026.0"` (string) | Aceito, truncado p/ `2026` | **Rejeitado** (idem) |
| `2.026e3` (float, notacao cientifica) | Aceito, truncado p/ `2026` | **Rejeitado** |
| `"2.026e3"` (string) | Aceito, truncado p/ `2026` | **Rejeitado** |
| `true`/`false` (bool) | Ja rejeitado (fronteira de tipo anterior) | Rejeitado (sem mudanca) |
| `-2026` (int) | Aceito estruturalmente, rejeitado depois por `$exercicio <= 0` (regra de FAIXA) | Mesmo comportamento (regra de FAIXA, nao a fronteira nova) |
| `"-2026"` (string) | Idem acima | Idem acima (fronteira nova aceita o formato `-?\d+`, deixa a FAIXA decidir) |
| valor excessivo dentro de `PHP_INT` (ex.: `918273`) | Aceito | Aceito (sem teto superior -- fora do escopo, regra de FAIXA so valida `> 0`) |
| overflow de int para float (`PHP_INT_MAX + 1`) | Aceito estruturalmente (virava float, truncado) | **Rejeitado** (e float) |
| array/`stdClass`/recurso | Ja rejeitado (fronteira de tipo anterior) | Rejeitado (sem mudanca) |
| `null`/ausente | Tratado como "nao informado" (regra de CONTEUDO) | Mesmo comportamento (sem mudanca) |
| string vazia `""` | Tratado como "nao informado" (`is_numeric('')===false` -> `exercicio=0`) | **Rejeitado estruturalmente** (nao bate `/^-?\d+$/`) -- resultado final identico (`pode_avancar=false`), so a mensagem/caminho interno muda (ver nota abaixo) |
| `" 2026"` / `"2026 "` (espaco prefixo/sufixo) | Aceito (`is_numeric()` com espaco a frente e permissivo em alguns casos, cast trunca/ignora) | **Rejeitado** |
| `"2026 anos"` (sufixo nao numerico) | `is_numeric()` ja rejeitava (`exercicio=0`, "nao informado") | Rejeitado estruturalmente (mesma decisao de resultado final, caminho interno muda) |

**Nota sobre string vazia/sufixo invalido**: antes desta rodada, esses
2 casos ja resultavam em `pode_avancar=false` (via `is_numeric()===false`
-> `exercicio=0` -> regra de FAIXA "nao informado/invalido"). Depois
desta rodada, esses mesmos 2 casos passam a ser interceptados pela
NOVA fronteira de exatidao (mensagem "estrutura invalida" em vez de
"nao informado/invalido") -- o RESULTADO final para o usuario/atendimento
e identico em ambos os casos (`pode_avancar=false`, nada persistido,
fallback manual disponivel), so o caminho interno/mensagem de log
muda. Nao havia teste pre-existente fixando a mensagem exata desses 2
casos especificos, portanto nao e uma regressao de contrato.

### Decisao sobre `2026.0`/`"2026.0"` -- evidencia investigada

Buscado em `docs/manual_vio_decode.md` (linhas 117-130) e
`docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md` (linha 882) --
o UNICO teste real observado do campo `exercicio` (Trial,
`crlv-demo.bin`) retornou o **placeholder literal string `"xxxxx"`**,
nunca um numero de nenhum formato. Nao existe, em nenhum dos 2
documentos, nenhuma evidencia de que a VIO envie `exercicio` como
float (inteiro ou fracionario) em uma resposta real. **Decisao**: sem
essa evidencia, o esquema estrito rejeita tambem `2026.0`/`"2026.0"`
(mesmo sendo matematicamente inteiro) -- so aceita `int` nativo ou
`string` de digitos puros (com sinal negativo opcional, deixado para a
regra de FAIXA decidir). Esta decisao contraria uma observacao NAO
bloqueante de uma rodada anterior (`/02-testes` de 2026-09-19) que
havia classificado o truncamento de float como "backlog de robustez,
nao bloqueante" -- reclassificada para BLOQUEANTE e corrigida nesta
rodada por instrucao explicita do usuario.

### Prova de que `2026.9` NAO e mais persistido como `2026` (execucao real)

Confirmado via `tests/manual/teste_vio_decode_matriz_tipos_campos.php`,
casos `[CRLV exercicio REJEITADO] 'float fracionario 2026.9'` e
`'string fracionaria "2026.9"'`: `pode_avancar === false`;
`origem === 'NAO_VALIDADO'`; recarga do atendimento do banco
(`buscarPorId`) confirma `crlv_origem_validacao` permanece
`NAO_VALIDADO` e `crlv_ano` e `null` OU `!== 2026` (nunca o valor
truncado); `motivo` e a mensagem generica fixa de estrutura invalida
(nunca expõe o campo/valor). Todas as 12 combinacoes de conteudo
invalido (fracionario/cientifico/`2026.0`/overflow/espaco) confirmadas
com a mesma evidencia.

### Atomicidade

Confirmada pela mesma bateria de asserções: nenhum valor parcial
(`crlv_ano`/`crlv_uf`/`crlv_rntc`/`crlv_tipo_veiculo`) e persistido
quando `exercicio` e rejeitado -- a excecao e lancada DENTRO do
bloco `try` de `validarCrlv()`, antes de qualquer chamada a
`atendimentoDao->atualizarValidacaoCrlv()` ou `cacheDao->salvarCrlv()`,
os quais so sao alcancados apos o `try/catch` completo com sucesso.
Nenhuma gravacao de cache tambem ocorre (mesma barreira).

### Testes -- contagem exata

`tests/manual/teste_vio_decode_matriz_tipos_campos.php`: **453 -> 556
asserções** (103 novas -- breakdown exato: +4 do novo caso
`booleano false` no loop de tipo estruturalmente invalido de
`exercicio`; substituicao do antigo bloco de regressao de 3 itens x 1
asserção (incluindo o caso `'float' => 2025.0` que ANTES era aceito
incorretamente) pelo novo bloco de 4 itens aceitos x 2 asserções
(net +5); +84 do novo bloco de 12 valores de CONTEUDO invalido x 7
asserções cada; +10 do novo bloco de regressao de sinal negativo, 2
itens x 5 asserções cada -- soma: 4+5+84+10 = 103, confere
exatamente com 556-453). **556/556 passaram, 0 falharam.**

### Prova negativa obrigatoria

Hash do arquivo corrigido ANTES da mutacao:
`ff5df31cb5598b2af36ce54a2336117b` (md5). Removida temporariamente a
chamada a `validarExercicioInteiroExato()` em `validarCrlv()`
(restaurando o cast permissivo original `is_numeric()`/`(int)` sem a
nova fronteira). Suite completa reexecutada: **12 de 556 asserções
passaram a FALHAR**, exatamente as 12 asserções de
"`motivo` e a mensagem generica de estrutura invalida" dos 12 casos de
conteudo invalido (fracionario/cientifico/`2026.0`/overflow/espaco) --
confirmando deteccao real: sem a fronteira, esses valores voltam a ser
aprovados (a mensagem generica de estrutura invalida nunca aparece,
porque o fluxo segue para `avaliarCrlv()` normalmente). As demais 544
asserções continuaram passando (nenhum outro comportamento afetado
pela mutacao pontual). Revertido a 100% via copia de backup feita
ANTES da mutacao; confirmado hash md5 **identico antes e depois**
(`ff5df31cb5598b2af36ce54a2336117b`); `php -l` sem erro; suite completa
reexecutada apos a reversao -- **556/556 limpo novamente**.

### Regressoes -- contagens exatas (nenhuma regrediu)

| Suite | Resultado |
|---|---|
| `teste_vio_decode_matriz_tipos_campos.php` | **556/556** (era 453/453) |
| `teste_vio_decode_robustez.php` | 80/80 |
| `teste_vio_decode.php` | 21/21 |
| `teste_rebaixamento_manual.php` | 47/47 |
| `teste_status_processamento.php` | 9/9 |
| `teste_concorrencia_processamento_vio.php` | 16/16 |
| `teste_avancar_etapa_expedicao.php` | 10/10 |
| `teste_fluxo_recebimento_documentos.php` | 11/11 |
| `teste_talent_uf_crlv.php` | 11/11 |
| `teste_talent_rntc_tipo_crlv.php` | 23/23 |
| `teste_validacao_jpeg_seguro.php` | 22/22 controles obrigatorios |

Nenhuma suite pre-existente precisou de ajuste, alem da propria
`teste_vio_decode_matriz_tipos_campos.php` (escopo explicito desta
rodada) -- inclusive o caso de regressao pre-existente
`'float' => 2025.0` no bloco de `exercicio` foi substituido pela nova
matriz de aceitos/rejeitados (o valor `2025.0` como float agora e
corretamente classificado como REJEITADO, conforme a nova regra
estrita -- nao e uma perda de cobertura, e a correcao do proprio
comportamento que a rodada anterior havia deixado como "backlog").

### Validacoes finais

- `php -l` em `app/Rn/DocumentoRn.php`,
  `app/Rn/DocumentoVioTipoInvalidoException.php`,
  `tests/manual/teste_vio_decode_matriz_tipos_campos.php`: sem erros.
- `git diff --check`: sem problema introduzido por este trabalho (so
  aviso pre-existente de LF/CRLF do ambiente Windows).
- Busca por cast numerico permissivo remanescente no fluxo VIO:
  `is_numeric`/`(int)`/`intval()`/regex permissivo -- confirmado que a
  unica ocorrencia de `is_numeric($exercicioBruto) ? (int) ... : 0`
  dentro de `validarCrlv()` agora opera SEMPRE sobre um valor ja
  validado pela nova fronteira (nunca mais recebe fracionario/
  cientifico/float); `preencherManualCrlv()` (fluxo MANUAL, fora do
  escopo do achado) mantem o mesmo padrao original, intocado
  deliberadamente.
- Busca por segredo/credencial/Base64/dado pessoal nos arquivos
  tocados: zero ocorrencia.
- `.env`: idêntico antes/depois.
- `DocumentoController.php`, `composer.json`/`composer.lock`,
  `VioDecodeClient.php`: zero alteracao (`git diff --stat` sem
  entrada).
- `app/Rn/TrelloClient.php` e `tools/trello-cli.php`: confirmados
  **byte-identicos a HEAD** via `git hash-object` (hashes batem
  exatamente com `git rev-parse HEAD:<arquivo>`), e `git diff` vazio
  para ambos -- sem repetir o incidente de escopo de rodadas
  anteriores.
- Nenhum residuo: banco de dev (`udlog_totem`) recarregado e
  verificado sem linhas `TESTE_VIO%`/`MTZ%` remanescentes desta
  rodada (1 totem `TESTE_IDEMP_2e528a` encontrado no banco e de OUTRA
  demanda anterior, nao tocado/removido por esta rodada). Bancos
  `qa013_*`/`qa_iso2`: NAO tocados/consultados.
- Zero commit, zero push nesta etapa.

### Avaliacao para nova `/03-revisao`

O achado BLOQUEANTE confirmado pelo `backend-especialista` na rodada
anterior de `/03-revisao` foi corrigido com evidencia direta de
execucao real (matriz de 556 asserções, incluindo confirmacao de zero
persistencia parcial via recarga do banco, e prova negativa com
mutacao/reversao confirmada por hash identico). Escopo estritamente
restrito ao campo `exercicio` -- nenhum outro arquivo/campo/contrato
tocado. Avaliacao: **pronta para nova rodada curta de `/03-revisao`
independente.**

## Trello

card_id: `6aae9bbdda2fce08c1b7269d` -- NAO atualizado nesta rodada
(instrucao explicita do usuario: atualizacao do Trello fica para
depois).

## Proximo passo

Rodar uma nova `/03-revisao` curta, focada em confirmar a correcao do
achado BLOQUEANTE de `exercicio` (esta secao) -- se aprovada, a
demanda `vio-hardening-sem-credenciais` fica liberada para
`/04-commit-e-push`.

## `/03-revisao` curta independente da correcao final de `exercicio` (2026-09-20)

Revisor independente (security-especialista, nao participou da
ultima `/01-implementacao`). Nenhum codigo alterado. Nenhuma
credencial/chamada real. Nenhum documento/CPF/placa/QR real.

### Achado BLOQUEANTE novo, reproduzido pelo fluxo real em banco descartavel

`app/Rn/DocumentoRn.php`, `validarExercicioInteiroExato()`
(~linhas 596-611): a regex `/^-?\d+$/` valida apenas o FORMATO
lexical (sequencia de digitos, sinal opcional), nunca se o valor
numerico cabe em `PHP_INT`. Uma string de digitos maior que
`PHP_INT_MAX` (ex. `"99999999999999999999"`) passa por essa
fronteira sem excecao, chega intacta ao cast `(int)` ja existente
(`DocumentoRn.php:314`), que SATURA SILENCIOSAMENTE para
`PHP_INT_MAX` (comportamento documentado do PHP) -- valor `> 0`,
passa pela regra de faixa, e e APROVADO. A gravacao no MySQL satura
de novo (coluna `SMALLINT`) para `32767`. Resultado real
confirmado: `pode_avancar=true`, `origem=VIO_TRIAL`,
`status_revisao=OK`, `crlv_ano='32767'` -- um "exercicio" de CRLV
completamente fantasioso, aprovado e persistido, sem nenhuma
sinalizacao de revisao manual. O metodo (nomeado "InteiroExato")
promete uma garantia que nao cumpre para MAGNITUDE -- so cobre
fracionario/notacao cientifica/tipo, nao overflow. Nenhum caso da
matriz de 556 asserções cobre string de digitos acima de
`PHP_INT_MAX` (o unico caso de "overflow" testado foi a variante
float, que ja era rejeitada corretamente por ser float).

Correcao minima sugerida (nao implementada): alem da regex, validar
que o valor cabe em `PHP_INT_MIN`..`PHP_INT_MAX` antes de aceitar
(ex. `filter_var($valor, FILTER_VALIDATE_INT)`, que retorna `false`
para fora da faixa), tratando overflow pelo mesmo caminho de
`DocumentoVioTipoInvalidoException` ja usado -- mesmo escopo minimo
ja aplicado nesta demanda.

### Restante do escopo -- sem achados, tudo reconfirmado

Ordem de validacao antes do cast confirmada correta; `2026`/`"2026"`
aceitos sem mudanca; fracionarios/notacao cientifica rejeitados
(556/556); booleanos/array/stdClass rejeitados a montante; sinal
negativo aceito lexicalmente mas sempre rejeitado pela regra de
faixa (`$exercicio <= 0`), confirmado NUNCA aprovado; zeros a
esquerda preservam comportamento anterior sem regressao; Unicode
minus/espaco/prefixo-sufixo/string vazia todos rejeitados
corretamente; atomicidade confirmada para todas as entradas
rejeitadas (exceto justamente pelo achado acima, que nao e falha de
atomicidade -- a gravacao e completa e consistente, so com CONTEUDO
invalido). Matriz recontada de forma independente: 556/556
confirmado. Todas as 11 regressoes reexecutadas e batendo
exatamente. Escopo confirmado limpo: VioDecodeClient.php/
DocumentoController.php/.env/dependencias intocados nesta rodada;
TrelloClient.php/trello-cli.php confirmados byte-identicos a HEAD
via hash-object.

### Veredito consolidado

**PRECISA DE AJUSTE**, por este unico achado bloqueante de
magnitude/overflow. NAO liberado para `/04-commit-e-push`.

## Trello

card_id: `6aae9bbdda2fce08c1b7269d` -- comentario adicional
registrando o veredito; cartao mantido em "Sprint Bruno - Fazendo
[Semanal]".

## Proximo passo

Nova rodada curta de `/01-implementacao` restrita a este achado:
adicionar validacao de magnitude (faixa PHP_INT_MIN..PHP_INT_MAX)
em `validarExercicioInteiroExato()`, alem da checagem lexical ja
existente, rejeitando overflow pelo mesmo caminho de excecao
interna ja usado. Depois, nova confirmacao curta de `/03-revisao`
antes de `/04-commit-e-push`.

## Correcao do achado BLOQUEANTE de magnitude/overflow de exercicio (2026-09-20)

Rodada final e curta de `/01-implementacao`, restrita EXCLUSIVAMENTE
ao problema de magnitude/overflow do campo `exercicio` do CRLV
(achado BLOQUEANTE da `/03-revisao` curta independente da secao
anterior). Nenhuma credencial obtida/usada, nenhuma chamada real a
VIO/Serpro/Talent, nenhum documento/CPF/placa/QR/imagem real, `.env`
intocado, zero commit/push.

### Arquivos alterados

- `app/Rn/DocumentoRn.php` (alterado) -- novo metodo privado
  `caberEmPhpInt()` + chamada em `validarExercicioInteiroExato()`.
- `tests/manual/teste_vio_decode_matriz_tipos_campos.php` (alterado)
  -- cobertura nova de magnitude/overflow (556 -> 590 asserções).
- `ia_development_state.md` (achado marcado `~~riscado~~` + evidencia
  + observacao nao bloqueante nova sobre SMALLINT).

`DocumentoController.php`, `.env`, `composer.json`/`composer.lock`,
`VioDecodeClient.php`, `DocumentoVioTipoInvalidoException.php`,
`TrelloClient.php`, `tools/trello-cli.php`, frontend, allowlists,
`ehValorPlaceholder()`, `preencherManualCrlv()`, schema/DB -- intocados
(confirmado via `git diff --stat`/`git status`/`git hash-object`).

### Trecho de codigo -- antes/depois

Antes (achado do `/03-revisao` curta):

```php
if (is_float($valor) || !preg_match('/^-?\d+$/', $valor)) {
    throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_nao_inteiro_exato');
}

return $valor;
```

A regex `/^-?\d+$/` validava so o FORMATO lexical -- `"99999999999999999999"`
passava incolume, saturava no cast `(int)` mais adiante para
`PHP_INT_MAX`, e de novo no MySQL (`SMALLINT`) para `32767`.

Depois:

```php
if (is_float($valor) || !preg_match('/^-?\d+$/', $valor)) {
    throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_nao_inteiro_exato');
}

// Formato lexical OK -- mas isso NAO garante que o valor cabe em
// PHP_INT. Valida MAGNITUDE aqui, em nivel de string, ANTES de
// qualquer cast/conversao numerica.
if (!$this->caberEmPhpInt($valor)) {
    throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_fora_da_capacidade_php_int');
}

return $valor;

// ...

private function caberEmPhpInt(string $valorDigitos): bool
{
    $negativo = $valorDigitos[0] === '-';
    $digitos = $negativo ? substr($valorDigitos, 1) : $valorDigitos;

    // Normalizacao de zeros a esquerda SOMENTE para comparacao de
    // magnitude (nao altera o valor original devolvido ao chamador).
    $digitosNormalizados = ltrim($digitos, '0');
    if ($digitosNormalizados === '') {
        $digitosNormalizados = '0';
    }

    $limiteMagnitude = $negativo
        ? ltrim((string) PHP_INT_MIN, '-')
        : (string) PHP_INT_MAX;

    $comprimentoValor = strlen($digitosNormalizados);
    $comprimentoLimite = strlen($limiteMagnitude);

    if ($comprimentoValor < $comprimentoLimite) {
        return true;
    }
    if ($comprimentoValor > $comprimentoLimite) {
        return false;
    }

    return strcmp($digitosNormalizados, $limiteMagnitude) <= 0;
}
```

Nenhuma conversao da string gigante para `int`/`float` ocorre em
nenhum momento desta funcao -- a decisao e feita inteiramente por
comprimento de string e, quando necessario, comparacao lexicografica
de string (`strcmp`), nunca por comparacao numerica.

### Tabela de formatos aceitos/rejeitados (foco magnitude)

| Formato | Resultado da fronteira de magnitude | Resultado final (`pode_avancar`) |
|---|---|---|
| `2026` (int) | N/A (int nativo, sempre garantido pelo PHP) | Aceito (sem mudanca) |
| `"2026"` (string) | Cabe (19 digitos ou menos) | Aceito (sem mudanca) |
| `"007"` (zero a esquerda) | Cabe (normalizado para "7" so na comparacao) | Aceito (sem mudanca no valor/comportamento) |
| `PHP_INT_MAX` (int nativo) | N/A (int nativo) | **Aceito** (sem teto de negocio para "ano" alem de `> 0` -- ver decisao abaixo) |
| `(string) PHP_INT_MAX` = `"9223372036854775807"` | Cabe (19 digitos, empate lexicografico <= limite) | **Aceito** (idem) |
| `"9223372036854775808"` (PHP_INT_MAX + 1, string literal) | **Nao cabe** (19 digitos, lexicograficamente > limite) | **Rejeitado** |
| `"-9223372036854775809"` (PHP_INT_MIN - 1, string literal) | **Nao cabe** (magnitude negativa 1 acima do permitido) | **Rejeitado** |
| `"99999999999999999999"` (caso original do achado, 20 digitos) | **Nao cabe** (20 > 19 digitos) | **Rejeitado** |
| Sequencia de 200 digitos | **Nao cabe** (200 > 19 digitos) | **Rejeitado** |
| `2026.9`/`"2026.9"`/`2026.0`/notacao cientifica (regressao) | N/A (rejeitados antes, por serem float/nao bater a regex) | Rejeitado (sem mudanca) |
| `-2026`/`"-2026"` (regressao) | Cabe (magnitude trivial) | Rejeitado pela regra de FAIXA (`$exercicio <= 0`), NAO pela fronteira de magnitude -- sem mudanca |

### Decisao: `filter_var()` vs. comparacao lexicografica manual

**Decisao: comparacao lexicografica manual (`caberEmPhpInt()`), NAO
`filter_var($valor, FILTER_VALIDATE_INT)`.** Motivos:

1. A garantia central exigida ("NUNCA converter a string gigante para
   numero, nem mesmo temporariamente") e mais facil de auditar por
   leitura direta de codigo com uma funcao propria (comprimento +
   `strcmp`) do que confiando no parsing interno de uma extensao PHP,
   cujo comportamento de borda para zero a esquerda/sinal nao esta
   documentado de forma auditável neste codigo-base.
2. Testado explicitamente `filter_var('007', FILTER_VALIDATE_INT)` --
   retorna `7` (inteiro, zero a esquerda removido). Isso serviria para
   uma checagem BOOLEANA de faixa (`!== false`), mas o valor de retorno
   em si mudaria o formato ("007" -> 7) se fosse usado tambem para
   extrair o valor -- por isso, mesmo se usado, `filter_var()` so
   poderia servir de CHECAGEM (ignorando o retorno), nunca de EXTRACAO,
   para preservar o comportamento ja aprovado de zeros a esquerda. A
   funcao propria evita essa armadilha por construcao (nunca usa o
   valor de retorno de nenhuma conversao numerica).
3. `caberEmPhpInt()` e testavel/auditavel isoladamente (funcao pura,
   sem I/O, sem dependencia de configuracao de PHP/extensao).

### Decisao sobre teto de negocio de faixa superior

**Nenhum teto de negocio superior foi inventado.** Investigado (mesma
evidencia da rodada anterior: `docs/manual_vio_decode.md`,
`docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md`) -- nenhum dos
dois documentos define um teto plausivel de "ano" alem de `> 0`.
Por isso, `PHP_INT_MAX` como `int` nativo (ou a string equivalente)
continua sendo ACEITO por esta fronteira de magnitude (tecnicamente
cabe em `PHP_INT`) -- a fronteira desta correcao resolve
especificamente o overflow que permitia BURLAR a checagem de
magnitude via string (o achado confirmado), nao a auséncia de um
teto de negocio razoavel para "ano", que e uma decisao de produto
distinta e nao documentada, portanto nao inventada aqui.

### Observacao nao bloqueante nova, fora do escopo -- coluna SMALLINT

Investigado empiricamente (banco de dev descartavel, nunca produção):
a coluna `crlv_ano` e `SMALLINT` (`sql/schema.sql:85`). Um valor que
CABE em `PHP_INT` (ex.: `PHP_INT_MAX`, aprovado corretamente por esta
fronteira -- que so valida capacidade de `PHP_INT`, nunca capacidade
de coluna de banco) ainda pode ser silenciosamente saturado pelo
MySQL na gravacao, se o `sql_mode` do servidor nao incluir
`STRICT_TRANS_TABLES`. Confirmado neste ambiente de dev via
`SELECT @@sql_mode` = `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`
(sem modo estrito): gravar `PHP_INT_MAX` em `crlv_ano` via
`AtendimentoDao::atualizarValidacaoCrlv()` resulta em `crlv_ano='32767'`
persistido, sem excecao. Esse teste tambem revelou, de forma
incidental, que o caso ja aceito em rodada anterior
`'int valido grande, sem overflow' => 918273` provavelmente sofre a
MESMA saturacao de coluna (nunca foi verificado o valor de `crlv_ano`
efetivamente persistido para esse caso em nenhuma rodada anterior --
so `pode_avancar`/`origem` foram verificados). **Nao corrigido nesta
rodada** -- alterar schema/DB esta fora do escopo explicito desta
demanda (arquivos autorizados: `DocumentoRn.php`, teste, handoff,
`ia_development_state.md`), e nao ha teto de negocio documentado para
"ano" que justifique um valor de corte arbitrario em PHP como
paliativo. Registrado em `ia_development_state.md` como observacao
nao bloqueante para decisao futura do usuario (candidatos: ampliar a
coluna para `INT`, ou definir um teto de negocio explicito de "ano
razoavel" em `DocumentoRn.php`, ou ambos).

### Prova de que a string gigante NAO e mais aprovada nem satura (execucao real)

Confirmado via `tests/manual/teste_vio_decode_matriz_tipos_campos.php`,
bloco `[CRLV exercicio MAGNITUDE REJEITADA]`: para
`"99999999999999999999"`, `"9223372036854775808"`
(`PHP_INT_MAX + 1`, string literal), `"-9223372036854775809"`
(`PHP_INT_MIN - 1`, string literal) e sequencia de 200 digitos --
`pode_avancar === false`; `origem === 'NAO_VALIDADO'`; recarga do
atendimento do banco confirma `crlv_origem_validacao` permanece
`NAO_VALIDADO` e `crlv_ano IS NULL` (nunca `32767`/`PHP_INT_MAX`
truncado/saturado); `motivo` e a mensagem generica fixa de estrutura
invalida (nunca expõe o campo/valor/PHP_INT_MAX). Para
`PHP_INT_MAX`/`(string) PHP_INT_MAX` (aceitos por nao haver teto de
negocio) -- confirmado `pode_avancar === true`, `origem === 'VIO_TRIAL'`,
e registrado via `echo` informativo (nao um `afirmar()`, para nao
confundir cobertura de seguranca desta correcao com a observacao
separada de schema) que `crlv_ano` persistido e `'32767'` --
exatamente a observacao nao bloqueante documentada acima, nao uma
regressao desta correcao (o overflow de PHP foi eliminado; o teto de
coluna do banco e outro problema, pre-existente e fora de escopo).

### Atomicidade

Confirmada pela mesma bateria: nenhum valor parcial
(`crlv_ano`/`crlv_uf`/`crlv_rntc`/`crlv_tipo_veiculo`) e persistido
quando `exercicio` e rejeitado por magnitude -- a excecao e lancada
DENTRO do bloco `try` de `validarCrlv()`, antes de qualquer chamada a
`atendimentoDao->atualizarValidacaoCrlv()`/`cacheDao->salvarCrlv()`
(mesma barreira estrutural ja usada pelas demais rejeicoes desta
demanda).

### Testes -- contagem exata

`tests/manual/teste_vio_decode_matriz_tipos_campos.php`: **556 -> 590
asserções** (34 novas -- 2 casos ACEITOS x 3 asserções cada (6) + 4
casos REJEITADOS x 7 asserções cada (28) = 34, confere exatamente com
590-556). **590/590 passaram, 0 falharam.**

### Prova negativa obrigatoria

Hash do arquivo corrigido ANTES da mutacao (md5):
`f9567bb36fad9e87ae84bc44cfc80948`. Removida temporariamente a
chamada a `caberEmPhpInt()` dentro de `validarExercicioInteiroExato()`
(restaurando o comportamento vulneravel: so a checagem lexical, sem
validacao de magnitude). Suite completa reexecutada: **4 de 590
asserções passaram a FALHAR**, exatamente as 4 asserções de "`motivo`
e a mensagem generica de estrutura invalida (nao satura, nao aprova)"
dos 4 casos de overflow (`PHP_INT_MAX + 1`, `PHP_INT_MIN - 1`, string
gigante de 20 digitos, sequencia de 200 digitos) -- confirmando
deteccao real: sem a fronteira de magnitude, esses valores voltam a
ser aprovados. As demais 586 asserções continuaram passando (nenhum
outro comportamento afetado pela mutacao pontual). Revertido a 100%
via copia de backup feita ANTES da mutacao; confirmado hash md5
**identico antes e depois** (`f9567bb36fad9e87ae84bc44cfc80948`);
`php -l` sem erro; suite completa reexecutada apos a reversao --
**590/590 limpo novamente**.

### Regressoes -- contagens exatas (nenhuma regrediu)

| Suite | Resultado |
|---|---|
| `teste_vio_decode_matriz_tipos_campos.php` | **590/590** (era 556/556) |
| `teste_vio_decode_robustez.php` | 80/80 |
| `teste_vio_decode.php` | 21/21 |
| `teste_rebaixamento_manual.php` | 47/47 |
| `teste_status_processamento.php` | 9/9 |
| `teste_concorrencia_processamento_vio.php` | 16/16 |
| `teste_avancar_etapa_expedicao.php` | 10/10 |
| `teste_fluxo_recebimento_documentos.php` | 11/11 |
| `teste_talent_uf_crlv.php` | 11/11 |
| `teste_talent_rntc_tipo_crlv.php` | 23/23 |
| `teste_validacao_jpeg_seguro.php` | 22/22 controles obrigatorios |

Nenhuma suite pre-existente precisou de ajuste alem da propria
`teste_vio_decode_matriz_tipos_campos.php` (escopo explicito desta
rodada).

### Validacoes finais

- `php -l` em `app/Rn/DocumentoRn.php` e
  `tests/manual/teste_vio_decode_matriz_tipos_campos.php`: sem erros.
- `git diff --check`: sem problema introduzido por este trabalho (so
  aviso pre-existente de LF/CRLF do ambiente Windows).
- Busca por cast/comparacao numerica antes da validacao de magnitude:
  `grep -n "(int) \$exercicioBruto\|(int) \$valor"` -- as duas unicas
  ocorrencias de `(int)` no fluxo de `exercicio` sao a linha
  ja existente `$exercicio = is_numeric($exercicioBruto) ? (int)
  $exercicioBruto : 0;` em `validarCrlv()` (opera SEMPRE sobre um
  valor ja validado por `caberEmPhpInt()`) e a mesma linha em
  `preencherManualCrlv()` (fluxo MANUAL, fora do escopo, intocado
  deliberadamente) -- nenhuma comparacao numerica feita antes da
  validacao de magnitude em nenhum ponto novo.
- Busca por segredo/credencial/token/dado pessoal nos arquivos
  tocados: zero ocorrencia.
- `.env`: identico antes/depois.
- `DocumentoController.php`, `composer.json`/`composer.lock`,
  `VioDecodeClient.php`, `DocumentoVioTipoInvalidoException.php`:
  zero alteracao (`git status`/`git diff --stat` sem entrada).
- `app/Rn/TrelloClient.php` e `tools/trello-cli.php`: confirmados
  **byte-identicos a HEAD** via `git hash-object` (hashes batem
  exatamente com `git rev-parse HEAD:<arquivo>`), `git diff` vazio
  para ambos.
- Nenhum residuo: banco de dev (`udlog_totem`) sem linhas
  `MTZ%`/`TESTE_VIO%` remanescentes desta rodada apos a limpeza final
  do proprio script de teste. Bancos `qa013_*`/`qa_iso2`: NAO
  tocados/consultados. Nenhum documento/CPF/placa/QR/imagem real
  usado.
- Zero commit, zero push nesta etapa.

### Avaliacao para nova `/02-testes`/`/03-revisao`

O achado BLOQUEANTE de magnitude/overflow foi corrigido com evidencia
direta de execucao real (matriz de 590 asserções, incluindo
confirmacao de zero persistencia/saturacao para os 4 casos de
overflow, e prova negativa com mutacao/reversao confirmada por hash
identico). Escopo estritamente restrito ao problema de
magnitude/overflow de `exercicio` -- nenhum outro
arquivo/campo/contrato tocado; `TrelloClient.php`/`trello-cli.php`
confirmados byte-identicos a `HEAD`. 1 observacao nova NAO bloqueante
registrada (saturacao de coluna `SMALLINT` independente do overflow
de PHP, ja corrigido) -- fora do escopo desta correcao, decisao de
schema/produto para o usuario. Avaliacao: **pronta para nova rodada
curta de `/02-testes`/`/03-revisao` independente.**

## Trello

card_id: `6aae9bbdda2fce08c1b7269d` -- NAO atualizado nesta rodada
(instrucao explicita do usuario: atualizacao do Trello fica para
depois).

## Proximo passo

Rodar uma nova `/03-revisao` curta, focada em confirmar a correcao do
achado BLOQUEANTE de magnitude/overflow de `exercicio` (esta secao)
-- se aprovada, a demanda `vio-hardening-sem-credenciais` fica
liberada para `/04-commit-e-push`.

## `/02-testes` independente da correcao de magnitude (2026-09-20)

2 revisores independentes (qa-testes + security-especialista, novas
instancias, sem participacao na correcao). Ambos APROVADOS.

**qa-testes**: reproduziu todas as 10 validacoes obrigatorias com
banco descartavel dedicado (`qa02_exercicio_overflow_20260920`),
prova negativa independente em copia isolada (nunca o repositorio
real, hash md5 identico antes/depois), contagens exatas
reconfirmadas (590/590 + 10 suites de regressao). Achado NOVO nao
bloqueante, fora do escopo desta correcao: `ehValorPlaceholder()`
(metodo pre-existente, intocado por qualquer rodada desta demanda)
usa regex que casa qualquer string de 1 caractere, entao um
`exercicio` que normalize para digito unico e rejeitado como
placeholder -- confirmado que esse comportamento e identico
antes/depois desta correcao (nao e regressao), registrado para
decisao futura.

**security-especialista**: revisao critica do algoritmo `caberEmPhpInt()`
confirmada matematicamente correta (18 casos adversariais proprios,
incluindo zeros a esquerda + limite exato, `-0`, string vazia/so
sinal). Proteção 100% em nivel de aplicacao confirmada (exceção
lancada antes de qualquer chamada ao DAO). Elevou a severidade da
observacao da coluna `SMALLINT` de "observacao" para "ATENCAO" (mas
mantida NAO bloqueante para esta demanda especifica) -- recomendou
demanda curta separada futura para decidir entre ampliar a coluna
ou definir teto de negocio de "ano razoavel", com envolvimento do
`devops-especialista` para confirmar `sql_mode`/`STRICT_TRANS_TABLES`
real do Hostgator.

### Veredito consolidado: APROVADO

Liberado para `/03-revisao` final. 2 achados nao bloqueantes
registrados para decisao futura do usuario (fora do escopo desta
demanda): saturacao da coluna `crlv_ano` SMALLINT (ATENCAO);
comportamento de `ehValorPlaceholder()` com digito unico
(observacao, pre-existente).

## `/03-revisao` final independente (2026-09-20)

Revisor independente (backend-especialista, nao participou da
implementacao da correcao de magnitude nem da `/02-testes` que a
validou). Nenhum arquivo alterado (2 mutacoes de prova negativa
revertidas com hash md5 identico confirmado).

Todos os 13 controles obrigatorios confirmados com evidencia real e
execucao propria: sanitizacao completa do `VioDecodeClient`; limite
de 10MB por bytes reais; todos os campos VIO com validacao explicita
de tipo; ordem correta tipo->formato->magnitude->cast para
`exercicio`; zero float/decimal truncado (28 asserções proprias);
zero overflow saturado (16 asserções proprias); zero persistencia
parcial (banco descartavel dedicado `qa_revisao_final_20260920`,
criado/destruido nesta sessao); excecao interna contida; fallback
preservado; contratos HTTP/JSON inalterados; prova negativa propria
confirmando deteccao (4/590 falhas ao desativar `caberEmPhpInt()`,
revertido com hash identico); Trello confirmado byte-identico a
HEAD; escopo de arquivos confirmado limpo e restrito ao autorizado.

Contagens finais reconfirmadas: 590/590, 80/80, 21/21, 47/47, 9/9,
16/16, 10/10, 11/11, 11/11, 23/23, 22/22 -- todas batendo exatamente,
zero falha.

### VEREDITO FINAL: APROVADO

Demanda pronta para `/04-commit-e-push`. 2 itens de backlog nao
bloqueantes permanecem registrados para decisao futura, fora do
escopo desta demanda: saturacao da coluna `crlv_ano` SMALLINT
(severidade ATENCAO); comportamento de `ehValorPlaceholder()` com
digito unico (observacao, pre-existente).

## Trello

card_id: `6aae9bbdda2fce08c1b7269d` -- comentario final sera
adicionado apos confirmacao do push em `/04-commit-e-push`; cartao
sera movido para "Sprint - Feito" somente apos `HEAD == origin/main`
confirmado.

## Commit

Hash funcional (codigo/testes): `997139c277fce125897972419504b0b6a7f24666`
Mensagem: `fix(vio): reforca validacao e sanitizacao sem credenciais`
Autor/Committer: Bruno Santos <brunossaantos@gmail.com>
7 arquivos alterados (3 modificados + 4 criados:
`app/Rn/DocumentoVioTipoInvalidoException.php`,
`tests/manual/mock_vio_server.php`,
`tests/manual/teste_vio_decode_matriz_tipos_campos.php`,
`tests/manual/teste_vio_decode_robustez.php`).

Confirmado antes do commit: `HEAD` identico a `origin/main` (sem
divergencia); `php -l` limpo; `git diff --cached --check` limpo;
lista de arquivos comparada ao handoff (exatamente os autorizados,
nenhum arquivo Trello/`.env`/dependencia/residuo incluido);
`app/Rn/TrelloClient.php`/`tools/trello-cli.php` confirmados
byte-identicos a `HEAD`, nao incluidos no commit; `DocumentoController.php`
confirmado intocado; nenhuma credencial/atribuicao de IA encontrada.
