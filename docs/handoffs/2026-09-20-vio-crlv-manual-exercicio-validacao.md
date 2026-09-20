# Handoff — vio-crlv-manual-exercicio-validacao

## Objetivo

Corrigir a pendencia nao bloqueante registrada em
`docs/handoffs/2026-09-20-vio-crlv-exercicio-faixa-storage.md`:
`DocumentoRn::preencherManualCrlv()` (fluxo de preenchimento manual
pelo operador do totem) NAO passa pela mesma validacao de
tipo/formato/magnitude/faixa armazenavel de `exercicio` ja aplicada
ao fluxo automatico via VIO Decode -- usa so
`is_numeric($exercicioBruto) ? (int) $exercicioBruto : 0`, que
tecnicamente permitiria um valor fracionario (truncado
silenciosamente) ou fora da faixa armazenavel de `crlv_ano`
(`SMALLINT SIGNED`, `-32768..32767`, confirmado na demanda
`vio-crlv-exercicio-faixa-storage`) chegar ao DAO por essa via.

## Preflight (2026-09-20)

- `git fetch origin` executado, `HEAD == origin/main`
  (`0dcf06c1668822bd63ee1ec8b266a87aec0b1586`), worktree limpo antes
  de iniciar.
- Lido `docs/handoffs/2026-09-20-vio-crlv-exercicio-faixa-storage.md`
  (achado registrado) e `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md`.
- **Achado adicional confirmado por leitura, relevante para o design
  da correcao**: `app/Controller/DocumentoController.php::preencherManual()`
  (linha ~366) faz `$exercicio = (string) ($entrada['exercicio'] ?? '');`
  ANTES de chamar `DocumentoRn::preencherManualCrlv(string $exercicioBruto, ...)`
  -- ou seja, um valor JSON nao-string (array/objeto/booleano) ja sofre
  cast `(string)` no proprio Controller, antes mesmo de chegar a
  `DocumentoRn`. Isso significa que a fronteira de tipo desta correcao
  nao pode ficar 100% dentro de `DocumentoRn::preencherManualCrlv()`
  se essa assinatura continuar aceitando so `string` -- ou a
  assinatura muda para aceitar `mixed`/o tipo bruto do JSON, ou o
  Controller precisa parar de fazer o cast prematuro para `exercicio`
  especificamente, delegando a decisao de tipo para a mesma fronteira
  central ja usada pelo fluxo VIO. Decisao de design fica a cargo do
  `backend-especialista` na implementacao, documentando a escolha.

## Fase 1 -- Implementacao (2026-09-20)

Escopo restrito conforme instrucao: `app/Rn/DocumentoRn.php`,
`app/Controller/DocumentoController.php`, este handoff,
`ia_development_state.md`. Nenhum arquivo de teste novo foi commitado
(ver justificativa na secao de testes abaixo). Nenhuma credencial
obtida/usada, nenhuma chamada real ao VIO/Serpro/Talent, nenhum
documento/CPF/placa/QR/imagem real, `.env` intocado, zero commit/push.
Trello NAO tocado nesta rodada (outra etapa cuida disso).

### Decisao de design sobre o cast prematuro do Controller

`DocumentoController::preencherManual()` fazia
`$exercicio = (string) ($entrada['exercicio'] ?? '');` ANTES de chamar
`DocumentoRn::preencherManualCrlv()`. Isso significava que um valor
`exercicio` vindo como array no JSON da requisicao ja sofreria o cast
`(string)` NO PROPRIO CONTROLLER -- um array vira a string literal
`"Array"` (so `E_WARNING`, nunca excecao, seria aprovado como dado
valido pelas checagens de conteudo seguintes) e um objeto sem
`__toString()` lancaria `Error` fatal (capturado so pelo `catch
(\Throwable $e)` generico do metodo, retornando HTTP 500) -- ambos
ANTES de qualquer fronteira de tipo/formato/magnitude/faixa.

**Decisao tomada: opcao (a)** -- o Controller parou de fazer o cast
prematuro de `exercicio` especificamente (os demais campos --
`placa`/`uf`/`rntc`/`tipo_veiculo` -- continuam com `(string) (...)`,
fora do escopo desta correcao, sem evidencia de que precisem mudar).
`$exercicioBruto = $entrada['exercicio'] ?? null;` repassa o valor
bruto (mixed) do JSON, sem cast. `DocumentoRn::preencherManualCrlv()`
mudou a assinatura do parametro de `string $exercicioBruto` para
`mixed $exercicioBruto`, e passa esse valor DIRETAMENTE para a nova
fronteira central `validarExercicioCompleto()` (mesma reutilizada pelo
fluxo VIO), dentro de um `try/catch` que NUNCA deixa
`DocumentoVioTipoInvalidoException` escapar da classe -- exatamente o
mesmo invariante ja documentado e testado para o fluxo automatico via
VIO Decode.

Justificativa da escolha: (1) mantem TODA a decisao de "o que fazer
com cada tipo" centralizada em `DocumentoRn` (camada de regra de
negocio), nunca espalhada/duplicada no Controller; (2) o Controller
so precisa decidir uma unica coisa, ortogonal a tipo -- se o campo foi
literalmente OMITIDO/vazio (mesmo criterio ja usado para os outros 4
campos deste endpoint, resultando em `Resposta::erro('Dados
incompletos')`, HTTP 400) -- nunca decide se um array/bool/float e
"valido"; (3) evita introduzir uma segunda estrategia de tratamento de
erro no Controller (ex.: teria que capturar uma excecao nova vinda de
`DocumentoRn` e traduzi-la para HTTP, quebrando o invariante "excecao
de tipo nunca escapa de `DocumentoRn`"); (4) e a MENOR mudanca de
contrato possivel -- o comportamento de "ausente" (`Resposta::erro`,
400) para os casos que ja eram tratados assim antes (string vazia)
continua identico.

`$exercicioAusente` no Controller cobre EXATAMENTE `null` (chave
omitida do JSON) e string vazia/so espacos (`is_string($x) &&
trim($x) === ''`) -- os MESMOS casos que ja causavam "Dados
incompletos" antes desta correcao (a antiga checagem
`$exercicio === ''` so podia disparar para os 2 casos acima, ja que
`(string) $entrada['exercicio']` de qualquer valor nao-string nao-nulo
nunca produzia `''`). Um array/objeto/bool/numero fora da faixa NAO e
"ausente" -- segue adiante e e rejeitado de forma controlada
(`pode_avancar=false`) pela fronteira central em `DocumentoRn`, nunca
pelo Controller.

### Fronteira central reutilizada (trecho de codigo)

```php
private function validarExercicioCompleto(mixed $valorBruto, string $documento, string $campo): int|string|null
{
    $valor = $this->validarTipoNumerico($valorBruto, $documento, $campo);
    $valor = $this->validarExercicioInteiroExato($documento, $campo, $valor);
    $valor = $this->validarExercicioDentroDaFaixaArmazenavel($documento, $campo, $valor);

    return $valor;
}
```

`validarTipoNumerico()` foi extraida de dentro de
`extrairCampoNumerico()` (mesmo corpo, comportamento identico -- aceita
`int`/`float`/`string`/`null`, rejeita array/objeto/bool/recurso) para
poder ser chamada tanto pela extracao de array do fluxo VIO quanto
diretamente pelo fluxo manual (que nao tem `$dadosBrutos` de onde
extrair). `validarExercicioInteiroExato()` e
`validarExercicioDentroDaFaixaArmazenavel()` sao os mesmos metodos
privados JA existentes e testados nas demandas anteriores -- nenhuma
logica reescrita, so reutilizada.

`preencherManualCrlv()` chama esta fronteira unica dentro de um
`try/catch`, retornando (em caso de rejeicao) o mesmo formato ja usado
por `avaliarCrlv()` para conteudo invalido:

```php
return [
    'ok' => false,
    'pode_avancar' => false,
    'motivo' => 'Exercicio do CRLV nao informado/invalido',
    'aviso_trial' => false,
    'origem' => $origemAtual,
    'status_revisao' => $statusRevisaoAtual,
];
```

(`$origemAtual`/`$statusRevisaoAtual` capturados ANTES da validacao,
garantindo que um CRLV ja aprovado -- MANUAL ou VIO -- nunca e
sobrescrito/rebaixado por uma tentativa com `exercicio` invalido.)

### Por que HTTP 200 + `pode_avancar=false` (nao `Resposta::erro` 400) para valor estruturalmente invalido

A demanda apontava "provavelmente `Resposta::erro(...)`" como
hipotese, pedindo para confirmar olhando o padrao real do endpoint.
Confirmado: `Resposta::erro('Dados incompletos', 400)` neste endpoint
e usado EXCLUSIVAMENTE para campo OMITIDO/vazio (checagem sintatica,
antes de qualquer chamada a `DocumentoRn`). Toda e qualquer rejeicao
de CONTEUDO de um campo presente (placa nao confere, UF invalida,
RNTC vazio, exercicio <= 0 -- inclusive o proprio caso ja existente de
`exercicio` invalido antes desta correcao) sempre retornou, e continua
retornando, via `Resposta::sucesso($resultado)` (HTTP 200) com
`dados.pode_avancar=false` e `dados.motivo` explicando o problema --
nunca um erro HTTP separado. Rejeitar `exercicio=32768`/array/bool
pelo MESMO canal ja usado por `exercicio<=0` (que e uma forma
igualmente "invalida" do mesmo campo) e o comportamento mais
consistente com o padrao ja adotado por este endpoint especifico, e o
requisito "pode_avancar=false ou equivalente" (primeira opcao citada
na demanda) confirma que essa e uma leitura aceita. HTTP nunca e 500
em nenhum dos casos testados.

### Tabela de valores testados (caminho HTTP/MANUAL real)

Fluxo real: `php -S 127.0.0.1:8098 -t public` servindo
`public/api/documento.php?acao=preencher-manual`, `curl` real com
`Authorization: Bearer <token QA>`, banco QA descartavel dedicado
(`qa_vio_manualcrlv_<timestamp>`, `SELECT DATABASE()` confirmado antes
de qualquer escrita, destruido ao final).

| Valor enviado no JSON | Esperado | Resultado |
|---|---|---|
| `2026` (int) | ACEITO, `crlv_ano`=2026, origem MANUAL | OK |
| `"2026"` (string) | ACEITO, `crlv_ano`=2026, origem MANUAL | OK |
| `32767` (int, teto exato) | ACEITO, `crlv_ano`=32767 | OK |
| `"0032767"` (string, zero a esquerda) | ACEITO, `crlv_ano`=32767 | OK |
| `32768` (int) | REJEITADO, `pode_avancar=false`, zero persistencia | OK |
| `PHP_INT_MAX` (`9223372036854775807`) | REJEITADO, zero persistencia | OK |
| `"99999999999999999999"` (overflow) | REJEITADO, zero persistencia | OK |
| `2026.9` (float) | REJEITADO, zero persistencia | OK |
| `"2026.9"` (string) | REJEITADO, zero persistencia | OK |
| `2026.0` (literal JSON sintatico, nao normalizado) | REJEITADO, zero persistencia | OK |
| `"2026.0"` (string) | REJEITADO, zero persistencia | OK |
| `2.026e3` (notacao cientifica, literal e string) | REJEITADO, zero persistencia | OK |
| `-2026` (negativo) | REJEITADO (regra de sinal ja existente) | OK |
| `true`/`false` (booleano) | REJEITADO, zero persistencia, sem erro fatal | OK |
| `[1,2,3]` (array) | REJEITADO, zero persistencia, sem erro fatal | OK |
| `{"ano":2026}` (objeto/array associativo) | REJEITADO, zero persistencia, sem erro fatal | OK |
| `""` / so espacos / `null` / chave ausente | `Resposta::erro('Dados incompletos')`, HTTP 400 | OK |

Para TODOS os casos rejeitados (estruturais e "ausentes"): HTTP nunca
500; `crlv_ano` nunca persistido (nem parcialmente); demais campos
(`crlv_uf`/`crlv_rntc`/`crlv_tipo_veiculo`) nunca persistidos
parcialmente; dado anterior preservado byte-a-byte quando ja existia
(testado com um CRLV MANUAL previo: `crlv_ano=2020`, `crlv_uf=RJ`,
etc. -- confirmado intacto apos cada tentativa invalida); origem
nunca regride nem "avanca" incorretamente -- testado tanto partindo de
`MANUAL` (permanece `MANUAL`, dado antigo intacto) quanto de
`NAO_VALIDADO` (permanece `NAO_VALIDADO`, nunca vira `MANUAL` "com
dado ruim"); mensagem sempre sanitizada (sem stack trace, sem `.php on
line`, sem tipo/valor recebido no corpo da resposta HTTP -- so o log
interno, via `error_log()`, recebe o marcador categorizado
documento/campo/tipo).

**Achado de bug do PROPRIO script de teste, corrigido durante a
execucao**: `json_encode(2026.0)` em PHP serializa como o literal JSON
`2026` (sem ponto decimal) por padrao -- colapsando com o `int`
equivalente no fio e descaracterizando o teste do caso `2026.0`
sintaticamente float. Corrigido montando o corpo JSON manualmente
(string literal) para os casos onde a distincao sintatica de fio
importa (`2026.0`, `2.026e3` sem aspas). Confirmado que o teste
original (antes da correcao do bug) mostrava 3 falhas falsas-positivas
nesse unico caso -- apos a correcao do script de teste (nao do
codigo-fonte), 137/137 limpo.

### Teste de `sql_mode`

Banco QA dedicado, servidor `php -S` reiniciado entre os 2 modos
(necessario pois `Util\Conexao` e um singleton por processo -- o
`sql_mode` de uma nova conexao MySQL e herdado do `sql_mode` GLOBAL no
momento da conexao):

1. `SET GLOBAL sql_mode='STRICT_ALL_TABLES'` -> reinicio do servidor ->
   `exercicio=PHP_INT_MAX`: `pode_avancar=false`, mesma mensagem,
   `crlv_ano` preservado (2020, nao alterado).
2. `SET GLOBAL sql_mode=''` (nao estrito) -> reinicio do servidor ->
   MESMO `exercicio=PHP_INT_MAX`: resultado IDENTICO byte-a-byte
   (`pode_avancar=false`, mesma mensagem, `crlv_ano` preservado).
3. Controle negativo em paralelo (MySQL puro, fora da aplicacao,
   `UPDATE tb_atendimento SET crlv_ano=32768`): modo estrito ->
   `ERROR 1264 (22003): Out of range value for column 'crlv_ano'`; modo
   nao estrito -> saturacao silenciosa para `32767`, sem erro.
4. `sql_mode` global restaurado ao valor original do ambiente
   (`NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`) ao final.

Confirma que a protecao da APLICACAO nunca depende do `sql_mode` da
sessao -- a rejeicao ocorre 100% em PHP, antes de qualquer chamada ao
DAO/banco, em qualquer modo.

### Prova negativa obrigatoria (com hash)

1. `md5sum app/Rn/DocumentoRn.php` ANTES da mutacao:
   `170d51f69bef426253fe8c029ef748ab`.
2. Corpo de `preencherManualCrlv()` temporariamente revertido para
   `$exercicio = is_numeric($exercicioBruto) ? (int) $exercicioBruto : 0;`
   (removendo a chamada a `validarExercicioCompleto()`/o `try/catch`).
3. Suite HTTP/MANUAL reexecutada: **106/137 passaram, 31 falharam** --
   exatamente os casos de faixa/magnitude fora da regra de sinal `> 0`
   ja existente (`32768`/`PHP_INT_MAX` passaram a ser aceitos e
   persistidos, incluindo o caso de origem `NAO_VALIDADO` virando
   `MANUAL` indevidamente com dado fora da faixa), confirmando deteccao
   real da regressao pela suite.
4. Revertido a 100% via copia de backup feita ANTES da mutacao.
5. `md5sum app/Rn/DocumentoRn.php` DEPOIS da reversao:
   `170d51f69bef426253fe8c029ef748ab` -- **identico**.
6. Suite HTTP/MANUAL reexecutada apos a reversao: **137/137 limpo
   novamente**.

### Regressoes -- contagens exatas (todas batendo, nenhuma regrediu)

| Suite | Resultado |
|---|---|
| Matriz de tipos VIO (`teste_vio_decode_matriz_tipos_campos.php`) | 622/622 |
| `teste_vio_decode_robustez.php` | 80/80 |
| `teste_vio_decode.php` | 21/21 |
| `teste_rebaixamento_manual.php` | 47/47 |
| `teste_status_processamento.php` | 9/9 |
| `teste_concorrencia_processamento_vio.php` | 16/16 |
| `teste_avancar_etapa_expedicao.php` | 10/10 |
| `teste_fluxo_recebimento_documentos.php` | 11/11 |
| `teste_talent_uf_crlv.php` | 11/11 |
| `teste_talent_rntc_tipo_crlv.php` | 23/23 |
| `teste_validacao_jpeg_seguro.php` | 22/22 controles obrigatorios ("PROVA NEGATIVA CONFIRMADA") |

Fluxo automatico via VIO Decode confirmado intocado: a matriz de tipos
(622/622) exercita `validarCrlv()` diretamente, incluindo os casos ja
cobertos pelas demandas anteriores (faixa/magnitude/tipo) -- nenhum
resultado regrediu.

### Testes novos -- justificativa de nao criar arquivo commitado

Nenhum arquivo de teste novo foi adicionado a `tests/manual/`. A
demanda exigia explicitamente testar o caminho HTTP real (`php -S` +
`curl` contra `public/api/documento.php`), diferente do padrao hoje
usado por TODA a suite `tests/manual/` existente (que testa
`DocumentoRn`/`DocumentoController` diretamente via PHP -- inclusive
`_caso_preencher_manual.php`, que so aceita argumentos de linha de
comando como string, incapaz de representar array/objeto/booleano no
JSON). Criar essa infraestrutura (servidor HTTP + banco descartavel
completo com schema/migrations) como parte permanente da suite
introduziria uma dependencia nova e mais pesada (porta TCP,
`php -S`, banco completo) para um cenario de fronteira ja coberto
architeturalmente pela MESMA fronteira central testada exaustivamente
pela matriz de tipos VIO (que ja teria pego a mesma logica se
regredisse, ja que `validarExercicioCompleto()`/`validarTipoNumerico()`/
`validarExercicioInteiroExato()`/`validarExercicioDentroDaFaixaArmazenavel()`
sao os MESMOS metodos usados pelos dois fluxos). A evidencia HTTP real
exigida pela demanda foi coletada via script QA isolado no scratchpad
da sessao (nao commitado, descartado ao final, banco QA destruido) --
suficiente para a comprovacao empirica pedida sem introduzir
infraestrutura permanente desproporcional ao escopo do achado
(1 campo, 1 metodo). Registrado explicitamente para decisao do
`qa-testes`/usuario em `/02-testes`: se avaliado que vale a pena
promover esse tipo de teste HTTP-real para um arquivo permanente da
suite (padrao novo, reutilizavel por outros endpoints), pode ser feito
como extensao dedicada em rodada futura.

### Arquivos alterados

- `app/Rn/DocumentoRn.php`: `preencherManualCrlv()` (assinatura
  `mixed $exercicioBruto` + fronteira central + retorno de falha
  controlada); `extrairCampoNumerico()` refatorado para delegar a nova
  `validarTipoNumerico()` (comportamento identico, so extracao de
  codigo); novo metodo privado `validarTipoNumerico()`; novo metodo
  privado `validarExercicioCompleto()`.
- `app/Controller/DocumentoController.php`: `preencherManual()` parou
  de fazer cast prematuro de `exercicio` para `string`, repassando o
  valor bruto do JSON; ajuste da checagem de "ausente" para so
  null/vazio/so-espacos (mesmo criterio anterior, agora explicito).
- Este handoff.
- `ia_development_state.md` (item de backlog marcado
  `~~riscado~~`/resolvido na secao 5.1 + novo log de mudanca na secao
  7).

Nao alterados (confirmado): `VioDecodeClient.php`, migrations, schema,
`.env`, frontend, Composer, `tests/manual/` (nenhum arquivo novo ou
modificado).

### Confirmacao `TrelloClient.php`/`tools/trello-cli.php`

```
TrelloClient: 905278b642cbc32a42952b93ed7108ec959e28b3 (working == HEAD)
trello-cli:   6ba98753aa289c92af49cfcb5e93f6659ca706b0 (working == HEAD)
```

### Validacoes finais

- `php -l` em `app/Rn/DocumentoRn.php` e
  `app/Controller/DocumentoController.php`: sem erros.
- `git diff --check`: sem problema de formatacao alem do aviso
  pre-existente de CRLF/LF (nao relacionado a este trabalho).
- Inspecao integral do diff: nenhum numero magico solto (`32767`
  sempre via `self::EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL`, ja existente,
  nao reintroduzido); nenhuma credencial/segredo/dado pessoal
  introduzido; nenhuma alteracao fora do escopo (`git status`
  confirmado -- so os 2 arquivos de codigo + este handoff +
  `ia_development_state.md`).
- Banco QA descartavel (`qa_vio_manualcrlv_<timestamp>`) destruido ao
  final (`DROP DATABASE`), confirmado via `SHOW DATABASES` que nao
  restou residuo. Residuos pre-existentes de rodadas anteriores
  (`qa013_*`, `qa_iso2`) confirmados intocados (nao removidos, nao
  pertencem a esta demanda).
- `sql_mode` GLOBAL restaurado ao valor original do ambiente apos o
  teste.
- Servidor `php -S` de teste encerrado ao final (nenhum processo
  residual).
- Zero commit, zero push nesta etapa (aguardando `/02-testes`/
  `/03-revisao`).

### Avaliacao para liberacao de `/02-testes`

Trabalho concluido dentro do escopo restrito, com evidencia real de
execucao pelo caminho HTTP/MANUAL exigido pela demanda (fluxo real +
prova negativa com hash + regressoes exatas + teste de `sql_mode`
duplo). Achado registrado explicitamente para decisao do
`qa-testes`/usuario: nenhum arquivo de teste novo foi commitado na
suite `tests/manual/` (justificativa detalhada acima) -- se essa
decisao for avaliada como insuficiente, a infraestrutura de teste
HTTP-real pode ser promovida a um arquivo dedicado em rodada
seguinte. **Avaliacao: pronto para `/02-testes`.**

## Trello

card_id: 6ab02761c4533394692c13b3

## `/02-testes` independente (2026-09-20)

2 revisores independentes (qa-testes + security-especialista, novas
instancias, sem participacao na implementacao). Ambos APROVADOS.

**qa-testes**: 38 requisicoes reais via `php -S`+curl contra
`public/api/documento.php?acao=preencher-manual`, banco descartavel
dedicado, cobrindo os 4 aceitos e 14 rejeitados (incluindo `2026.0`
literal montado manualmente para evitar colapso de serializacao
`json_encode`) a partir de 2 pontos de partida (`MANUAL` ja aprovado
e `NAO_VALIDADO`) -- zero persistencia parcial em qualquer rejeicao,
HTTP sempre 200/400 (nunca 500), origem nunca avanca incorretamente.
`sql_mode` estrito/nao-estrito confirmado sem diferenca funcional
(resposta HTTP identica byte-a-byte). Prova negativa com hash
identico (18 diferencas exatas ao reverter, revertido 100%). Todas
as 11 regressoes reconfirmadas, fluxo VIO automatico intocado.
Opiniao registrada (nao implementada): vale promover um teste
HTTP-real permanente numa rodada futura dedicada, para pegar erros
de serializacao JSON como o `2026.0`/`2026` -- fora do escopo desta
correcao pontual.

**security-especialista**: confirmou por leitura linha a linha que o
cast prematuro foi removido SOMENTE para `exercicio` (demais campos
intocados); refatoracao de `extrairCampoNumerico()` confirmada
logicamente identica ao comportamento anterior (fluxo VIO nao
regrediu); excecao interna confirmada nunca escapando do metodo.
Bateria de ataques adversariais proprios: array aninhado profundo,
objeto JSON (sempre vira array associativo via `json_decode(...,
true)`, confirmado por leitura do entrypoint real -- nao e vetor de
`__toString()`), booleanos, notacao cientifica gerando `INF`,
digitos Unicode fullwidth, caractere zero-width, byte nulo embutido
-- todos corretamente rejeitados, zero persistencia indevida.
Observacao NAO bloqueante: anomalia isolada e nao-reproduzivel no
proprio ambiente de teste ad-hoc do revisor durante depuracao de
aplicacao de migrations (nao no codigo) -- nao reproduzida em 6+
repeticoes subsequentes nem via chamada direta por Reflection,
atribuida a artefato transitorio do harness, nao da aplicacao.

### Veredito consolidado: APROVADO

Liberado para `/03-revisao` final.

## `/03-revisao` final independente (2026-09-20)

Revisor independente (backend-especialista, nao participou da
implementacao nem da `/02-testes`). Nenhum arquivo alterado.

Todos os 12 controles obrigatorios confirmados com evidencia real:
cast prematuro removido so para `exercicio`; fluxo VIO confirmado
sem regressao (diff linha a linha); funcao central sem duplicacao
(mesmas 3 checagens, mesma ordem, para VIO e manual); excecao
interna contida; HTTP nunca 500 (testado com `32768`, `PHP_INT_MAX`,
array, objeto, booleanos, fracionario); atomicidade confirmada;
aceitos persistidos exatos com origem `MANUAL`; `sql_mode`
confirmado sem diferenca funcional (resposta byte-identica em ambos
os modos); contrato inalterado; migrations/schema/`.env`/
`VioDecodeClient.php`/Talent/impressao intocados; Trello confirmado
byte-identico a `HEAD`; escopo restrito exatamente aos 4 arquivos
esperados.

**Investigacao conclusiva da anomalia `exercicio=32768`**: 25
execucoes repetidas do caso pelo caminho real (10 a partir de
baseline MANUAL + 15 a partir de NAO_VALIDADO) -- **25/25 rejeicoes
corretas, zero aceitacao indevida**. Confirmado que a anomalia
isolada relatada numa das rodadas de `/02-testes` NAO e
reproduzivel -- classificacao como artefato transitorio do harness
daquela rodada (nao da aplicacao) esta correta.

Contagens finais reconfirmadas: 622/622, 80/80, 21/21, 47/47, 9/9,
16/16, 10/10, 11/11, 11/11, 23/23, 22/22 -- todas batendo
exatamente (1 flake transitorio de timing em
`teste_status_processamento.php`, nao relacionado a este trabalho,
resolvido em reexecucao imediata).

### VEREDITO FINAL: APROVADO

Demanda pronta para `/04-commit-e-push`.

## Trello

card_id: `6ab02761c4533394692c13b3` -- comentario final sera
adicionado apos confirmacao do push; cartao sera movido para
"Sprint - Feito" somente apos `HEAD == origin/main` confirmado.

## Commit

Hash funcional (codigo): `4c8665ffb6bbc85f1b17b0cef8bdc22c4032cfda`
Mensagem: `fix(vio): valida exercicio no fluxo manual do CRLV`
Autor/Committer: Bruno Santos <brunossaantos@gmail.com>
2 arquivos alterados (`app/Controller/DocumentoController.php`,
`app/Rn/DocumentoRn.php`).

Confirmado antes do commit: `HEAD` identico a `origin/main`; `php -l`
limpo; `git diff --cached --check` limpo; lista de arquivos
exatamente os 2 autorizados; migrations/schema/`.env`/composer/
`VioDecodeClient.php`/Talent/impressao/Trello confirmados intocados
(`git hash-object` byte-identico a `HEAD` para `TrelloClient.php`/
`tools/trello-cli.php`); nenhuma credencial/atribuicao de IA.
