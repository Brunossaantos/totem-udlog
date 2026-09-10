# Handoff — expedicao-vio-cnh-crlv

Data: 2026-09-08
Etapa: 00-planejamento

## O que foi pedido

Planejar (sem implementar) a integracao com a API oficial VIO DECODE
(Serpro) no fluxo de Expedicao, para validar CNH e CRLV via leitura de
QR Code com a camera NETUM. Objetivo desta etapa: uma PROVA TECNICA
ISOLADA em ambiente Trial (QR oficiais de demonstracao do Serpro,
captura pelo Netum, extracao integra dos bytes brutos, chamada
backend->VIO Trial, validacao do JSON, medicao de tempo/fluidez) — sem
integrar producao, sem contratar nada nesta etapa.

## Documentacao oficial confirmada

Pesquisada via WebFetch (unico sub-agente com essa ferramenta,
ui-ux-especialista) diretamente no portal oficial do Serpro
(apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode). Registrada
integralmente em `docs/manual_vio_decode.md` (novo arquivo, mesmo
espirito de `docs/manual_talent.md`/`docs/db_gestao_coletas.md`).

Resumo do contrato confirmado:
- URLs distintas Trial (`viodec-trial`) e Producao (`viodec`), dominio
  `gateway.apiserpro.serpro.gov.br`.
- OAuth2 client_credentials (Base64 de `key:secret`, Bearer token).
- **CRITICO**: documentacao descreve o Trial como "ambiente de testes...
  com dados de exemplo (Mock)" — sem confirmacao categorica de que QR
  reais funcionem la. Tratamento adotado: Trial valida a integracao
  TECNICA (formato, auth, parsing), NAO a autenticidade de documento
  real.
- A API espera o "raw value" bruto do QR (ISO-8859-1, metadados do
  padrao QR removidos) — nao imagem, nao texto generico decodificado.
- Campos de resposta CNH (19) e CRLV (28) confirmados e listados
  integralmente em `docs/manual_vio_decode.md`.
- Codigos de retorno: `200`, `400`, `422` (com `VD001`-`VD004`).
- NAO CONFIRMADO: rate limit, timeout recomendado, custo/contratacao
  para producao, e a URL EXATA do endpoint de obtencao do token OAuth2
  (a doc descreve o fluxo, mas nao lista essa URL especifica).

## Investigacao do estado real do codigo (explorer)

- NADA existe hoje de VIO/Serpro/QR funcional no projeto.
- `app/Rn/ProdespClient.php`: stub morto, nunca instanciado,
  `consultarPorQr()` sempre retorna `null`. API generica diferente da
  VIO Decode — nao reaproveitada, mantida como esta.
- `public/api/documento.php` + `DocumentoController::upload()`: endpoint
  JA EXISTENTE para foto de CNH/CRLV (sem QR). **Achado de seguranca
  relevante**: esse endpoint tem o MESMO IDOR ja corrigido em
  `NotaController` (nao valida posse/tipo/status/etapa, so verifica que
  o atendimento existe) — dentro do escopo desta demanda, proposto
  corrigir junto.
- Leitor Netum ANTIGO (HID/teclado) ja existe em `app.js`
  (`habilitarLeitorScanner`/`onLeituraScanner`), guarda
  `state.ultimaLeituraQr` como STRING SIMPLES — nao preserva bytes
  brutos/ISO-8859-1, nunca e enviado a nenhuma API hoje. Nao serve como
  base para o novo requisito de raw bytes.
- `exp_cnh`/`exp_crlv` COMPARTILHAM as mesmas funcoes com
  `rec_cnh`/`rec_crlv` em `app.js` (`telaCaptura`, `iniciarCamera` com
  `facingMode` generico sem `deviceId`, `capturarFotoBase64`,
  `capturarDocumento`) — unica diferenca e por `state.tela`. Como a
  demanda proibe tocar em Recebimento, isso exige criar FUNCOES E TELAS
  NOVAS DEDICADAS para Expedicao (duplicacao deliberada, nao divida
  tecnica).
- `tb_atendimento` hoje so tem `motorista_nome`, `motorista_cpf`,
  `cnh_validade DATE`, `crlv_ano SMALLINT` — texto livre, sem status de
  validacao, sem dado estruturado de QR.
- Precedente de padrao de status de validacao: `status_ocr` ENUM em
  `tb_atendimento_nota` (migration 002), usado no Recebimento — bom
  precedente estrutural, nao reaproveitavel diretamente (tabela
  diferente).
- `.env.example` ja tem `PRODESP_API_URL`/`PRODESP_API_KEY`, apontando
  para o portal generico da Prodesp — API DIFERENTE da VIO Decode/Serpro.
  Nao reaproveitada; variaveis novas com prefixo `VIO_*` propostas.
- Nenhuma biblioteca de QR Code vendorizada existe hoje no projeto.
- `git status`: working tree limpo (so a nota de fechamento do handoff
  anterior, documentacao, sem risco) — nada a preservar alem disso.

## Arquitetura planejada

### Backend (backend-especialista)

- Novo `App\Rn\VioDecodeClient` (mesmo padrao de `TalentClient`):
  metodo `decodificar(string $rawValueQr, string $tipoDocumento)`,
  interno `obterAccessToken()` com cache de token (tabela pequena ou
  arquivo em `storage/cache/` — decisao a fechar na implementacao, nao
  bloqueante para a prova tecnica), `baseUrl()` decide trial/producao
  por config. Nunca lanca excecao nao tratada para fora do Controller.
- Novo endpoint: `documento.php?acao=validar-qr` (reaproveita bootstrap
  ja existente de Auth/Conexao/Dotenv, mesmo padrao de `nota.php`).
  Payload: `id_atendimento`, `tipo` (cnh|crlv), `raw_value_qr` (base64
  dos bytes brutos), `imagem_frente`/`imagem_verso` (CNH) ou `imagem`
  unica (CRLV).
- Fluxo sincrono dentro da mesma requisicao (sem webhook — API nao tem
  callback): salva fotos primeiro (sempre, mesmo se QR falhar depois) ->
  se `raw_value_qr` presente, chama VIO -> sucesso grava campos
  estruturados + `VIO_TRIAL`; erro tratado (VD00x/timeout/indisponivel)
  grava `NAO_VALIDADO` com log tecnico, sem bloquear o atendimento -> se
  QR ausente, grava `NAO_VALIDADO` direto sem gastar chamada faturavel.
- Schema (migration nova `sql/migrations/005_vio_decode_cnh_crlv.sql`,
  seguindo ESTRITAMENTE o padrao idempotente da migration 003 corrigida
  — SQL preparado condicional + `SET NAMES utf8mb4`, nao o padrao fragil
  das 001/002): 4 colunas novas em `tb_atendimento`:
  `cnh_origem_validacao`/`crlv_origem_validacao`
  (`ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO')` DEFAULT
  `NAO_VALIDADO`), `cnh_dados_vio_json`/`crlv_dados_vio_json` (JSON, guarda
  os campos estruturados da resposta).
- Regra de rebaixamento para `MANUAL`: no case `confirmacao` de
  `AtendimentoController::salvarEtapa()` (unico ponto onde o
  atendente edita os campos), se a origem atual e `VIO_TRIAL`/
  `VIO_VALIDADO` E o valor enviado difere do gravado -> rebaixa para
  `MANUAL`. Se o valor enviado e igual ao gravado (so confirmou, nao
  editou) -> mantem a origem original. Comparacao campo a campo (CNH e
  CRLV com origem independente).
- Validacoes obrigatorias no novo endpoint (replicando exatamente o
  padrao ja corrigido em `NotaController`): Auth -> posse do atendimento
  (`buscarAtendimentoDoTotem`, mensagem generica) -> `tipo='expedicao'`
  -> `status='em_andamento'` -> `etapa_atual` correta (nome exato a
  confirmar contra `AtendimentoRn` na implementacao, nao presumido aqui).
- **Correcao dentro do escopo**: `DocumentoController::upload()`
  (endpoint JA EXISTENTE) ganha as mesmas 5 validacoes — hoje nao tem
  nenhuma alem de "atendimento existe".
- Variaveis de ambiente novas (prefixo `VIO_*`, nao `SERPRO_*` nem
  reaproveitar `PRODESP_*`): `VIO_AMBIENTE=trial|production` (enum, URL
  base DERIVADA no codigo a partir disso, nunca URL livre em .env),
  `VIO_BASE_URL_TRIAL`, `VIO_BASE_URL_PRODUCAO`, `VIO_TOKEN_URL`
  (PENDENTE — nao confirmado no manual), `VIO_CONSUMER_KEY`,
  `VIO_CONSUMER_SECRET`. Comportamento se `VIO_AMBIENTE` ausente/invalido:
  FALHA EXPLICITA (excecao), nunca assume trial nem production por
  default.
- Retry/timeout: timeout 20s (mesmo padrao de `TalentClient`, sem numero
  "oficial" documentado). 1 retry automatico so para falha de
  rede/timeout (nao para 400/422, que sao respostas definitivas). Se
  todas as tentativas falharem -> fallback manual, nao bloqueia.
- QR bruto: existe so em memoria durante a requisicao HTTP, nunca
  persistido em arquivo/tabela/log — sai de escopo ao fim do request.
  Atende ao requisito "nao guardar QR bruto alem do necessario" sem
  precisar de mecanismo de descarte adicional.

### Frontend (frontend-especialista)

- **Achado arquitetural que condiciona tudo**: como `exp_cnh`/`exp_crlv`
  compartilham funcoes com `rec_cnh`/`rec_crlv`, e a demanda proibe
  tocar em Recebimento, sera necessario criar TELAS E FUNCOES NOVAS
  DEDICADAS so para Expedicao (ex.: `telaCapturaCnhFrente`,
  `telaCapturaCnhVerso`, `telaCapturaCrlv`, `iniciarCameraExpDocumento`,
  `capturarFotoExpDocumento`) — duplicacao deliberada e aceita, nao
  divida tecnica a corrigir sem pedido.
- Biblioteca de QR recomendada: **jsQR** (propriedade `binaryData` expoe
  os bytes brutos do payload, antes da conversao para string — e uma
  funcao sincrona pura, sem Worker interno proprio, logo SEM risco do
  bug de Worker aninhado ja documentado com o Tesseract.js). **Precisa
  de confirmacao pratica** (comparar `binaryData` byte a byte contra o
  que a API espera) antes de fechar a escolha — `zxing-js` fica como
  plano B, mais opaco (acesso a bytes brutos nao e garantido pela API
  publica estavel).
- Fluxo de telas: captura->preview->confirmar/refazer (mesmo padrao
  superior ja usado em `rec_digitaliza`, melhor que o `capturarDocumento`
  atual de CNH/CRLV porque permite conferir antes de enviar — importa
  mais aqui porque errar a leitura do QR custa uma nova tentativa
  faturavel). CNH: frente -> verso (roda QR sobre a mesma imagem ja
  capturada, sem novo enquadramento) -> resultado. CRLV: imagem completa
  -> QR -> resultado.
- Seleciona camera "NETUM Camera" por `deviceId` (portando o mecanismo
  ja usado em `rec_digitaliza`) — decisao em aberto se reaproveita a
  mesma chave de `localStorage` ou usa uma separada (mesmo hardware
  fisico, mas nao decidido explicitamente pelo usuario).
- Web Worker dedicado (`qr-worker.js`, criado uma unica vez pela thread
  principal, nunca aninhado) roda `jsQR` sobre o `ImageData` recebido via
  `postMessage`/transferable — evita bloquear a UI sem repetir o erro
  do Tesseract.js.
- Payload ao backend: fotos em base64 JPEG (mesmo padrao ja usado),
  bytes do QR em BASE64 do array de bytes (nunca string UTF-8 direta,
  unica forma segura de transportar bytes potencialmente nao-UTF-8
  dentro de JSON).
- UX de erro/fallback: QR ilegivel, API indisponivel, ou 422/VD00x ->
  nao bloqueia, segue para os campos MANUAIS que JA EXISTEM em
  `exp_confirma` (nao precisa criar tela de fallback nova, so nao
  pre-preencher quando a leitura falhar) — mesmo espirito ja usado no
  Recebimento quando o OCR nao identifica cliente.
- Comunicacao "Trial != oficial": aviso inline (nunca modal bloqueante,
  nunca header fixo — respeita a regra ja existente do projeto), texto
  tipo "Ambiente de teste (Trial) — nao substitui validacao oficial do
  documento", sempre visivel em 100% das execucoes.

### Seguranca (security-especialista) — parecer preventivo

- **LGPD/dado pessoal sensivel**: CPF, data de nascimento, filiacao
  (pai/mae), numero de RENACH/CNH sao dado pessoal de titular
  identificavel — risco maior que o CNPJ de PJ ja tratado na demanda de
  clientes. Recomendacao (decisao de produto pendente, nao decidida
  aqui): persistir so o minimo necessario (ex.: status + timestamp, CPF
  talvez como chave); campos de exibicao/conferencia (data de
  nascimento, filiacao, dados completos do CRLV como cor/motor/potencia)
  sao candidatos fortes a NUNCA SEREM PERSISTIDOS, so exibidos na sessao
  e descartados. Filiacao em particular e dado de OUTRA pessoa (pai/mae
  do motorista), nem parte do atendimento — agrava o risco se retido sem
  finalidade clara.
- Credenciais OAuth2 exclusivamente no backend/`.env`, nunca no
  front-end — requisito inegociavel, reforcado.
- QR bruto: descartar imediatamente apos sucesso (200); retencao
  temporaria so se necessaria para retry, com TTL curto, nunca em log de
  aplicacao (`error_log`) — se precisar depurar, logar so metadado nao
  sensivel (tamanho em bytes, numero da tentativa), nunca o conteudo.
- Validacao Auth/posse/tipo/status/etapa deve seguir EXATAMENTE o padrao
  ja corrigido em `NotaController::identificarCliente()` (try/catch
  defensivo, validacao de status/etapa antes de processar, IDOR via
  posse) — nao repetir o erro ja corrigido em outro lugar do mesmo
  projeto.
- Ambiente Trial/Producao: variavel de ambiente EXPLICITA e propria
  (nunca inferida de `APP_ENV`/hostname/outra flag), fail-closed se
  ausente/invalida (nunca assume producao por default, que e o ambiente
  mais caro/sensivel).
- **Trial nao pode ter peso de validacao real em nenhuma logica futura**:
  como a doc nao nega categoricamente que QR real "passe" no Trial, o
  enum `VIO_TRIAL` vs `VIO_VALIDADO` precisa ser a BARREIRA LOGICA real
  (determinado so pelo backend a partir da config de ambiente, nunca
  aceito do front-end), nao so um rotulo visual — registrar como
  invariante para nao ser esquecido quando/se migrar para producao.
- Retry/timeout: reenviar sem limite arrisca bloqueio da conta pelo
  Serpro (rate limit nao documentado, motivo a mais para ser
  conservador) e amplia exposicao do QR (dado sensivel) em rede/log a
  cada tentativa. Reaproveitar o MESMO mecanismo de rate limit ja
  existente (`RateLimitOcrDao`) em vez de criar um segundo.

### DevOps (devops-especialista)

- `VIO_AMBIENTE=trial|production` (enum) em vez de URL livre — URL
  correta DERIVADA no codigo (hardcoded como constante, mesmo padrao ja
  usado em `ClienteApiClient::BASE_URL` para contrato ja confirmado),
  elimina risco de digitar URL errada manualmente.
- Ausente/invalido -> falha explicita (`RuntimeException` no bootstrap),
  nunca assume nenhum dos dois ambientes por default.
- Armazenamento de credenciais: padrao ja existente do projeto se aplica
  sem modificacao (`.env` fora de versionamento/webroot, `.env.example`
  so com placeholder vazio).
- Credenciais de Trial (Consumer Key/Secret): dependem de cadastro da
  UDLOG no portal Serpro — fora do escopo tecnico, registrado como
  pendencia de obtencao, nao inventado.
- Rede/Hostgator -> `gateway.apiserpro.serpro.gov.br`: sem razao tecnica
  para esperar bloqueio (mesmo padrao ja validado para Talent/API de
  clientes, HTTPS de saida via curl nativo) — mas a prova tecnica desta
  etapa roda no ambiente LOCAL do desenvolvedor, nao no Hostgator nem no
  mini PC; validacao pratica da saida HTTPS do Hostgator fica pendente
  para quando (se) migrar.

### Testes (qa-testes) — roteiro da prova tecnica isolada

Passo a passo com marcacao [FISICO]/[MOCK OK] e criterio de sucesso:
1. Obter QR oficiais de demonstracao CNH/CRLV do Serpro — [FISICO/externo].
2. Capturar QR pelo Netum — [FISICO].
3. Extrair raw value preservando bytes (ISO-8859-1) — [MOCK OK], testavel so com a imagem do QR.
4. Obter access_token OAuth2 no Trial — [MOCK OK].
5. POST dos bytes ao endpoint de decode Trial — [MOCK OK].
6. Validar schema do JSON contra os campos documentados — [MOCK OK].
7. Medir tempo de resposta da API e tempo ponta a ponta com captura — [MOCK OK]/[FISICO].

Casos de erro mapeados: `400` (MOCK OK), `422 VD001` (MOCK OK/FISICO
leve), `422 VD002` (MOCK OK, corromper bytes deliberadamente), `422
VD003` (SEM forma etica/segura identificada de reproduzir — marcar como
NAO TESTAVEL nesta prova, salvo exemplo oficial do Serpro), `422 VD004`
(depende de confirmar como a API infere o tipo de documento —
pendencia).

**Teste de fronteira mock-vs-real**: proposta explicita — NUNCA usar
documento de terceiro; unico teste eticamente aceitavel seria o proprio
responsavel pela prova usando voluntariamente e com consentimento
explicito o PROPRIO documento, com descarte imediato do dado apos o
teste. Requer APROVACAO HUMANA EXPLICITA E ESPECIFICA antes de ser
executado — nao presumido como autorizado por este planejamento. Sem
essa aprovacao, aceita a documentacao como esta (Trial = mock-only).

Timeout/retry/indisponibilidade: todos simulaveis via mock server local
ou bloqueio de DNS via hosts, sem depender da API real estar fora do ar
- [MOCK OK].

Evidencia de "nunca validar documento real": aviso permanente e visivel
em 100% das execucoes (inclusive erros) — QA valida so a presenca/
visibilidade, a existencia e responsabilidade da implementacao.

Comparacao de placa: [MOCK OK], testar match e mismatch usando o dado
mock do QR de demonstracao CRLV.

## Arquivos que seriam afetados (na implementacao, se aprovada — NADA criado/alterado nesta etapa)

Novos:
- `app/Rn/VioDecodeClient.php`
- `sql/migrations/005_vio_decode_cnh_crlv.sql`
- `public/totem/assets/qr-worker.js`
- `public/totem/assets/jsqr/` (biblioteca vendorizada localmente, sem CDN — a confirmar apos teste pratico de `binaryData`)
- `docs/manual_vio_decode.md` (JA CRIADO nesta etapa de planejamento, registrando o contrato confirmado)

Alterados:
- `app/Controller/DocumentoController.php` (nova acao `validar-qr` + correcao de IDOR em `upload()`)
- `public/api/documento.php` (nova rota `?acao=validar-qr`)
- `app/Controller/AtendimentoController.php` (case `confirmacao` de `salvarEtapa`, regra de rebaixamento para `MANUAL`)
- `app/Rn/AtendimentoRn.php` (`salvarDadosMotorista`, comparacao de valor antes de gravar origem)
- `sql/schema.sql` (4 colunas novas em `tb_atendimento`)
- `.env.example` (variaveis `VIO_*`)
- `public/totem/assets/app.js` (telas/funcoes NOVAS e dedicadas para `exp_cnh`/`exp_crlv`, sem tocar nas equivalentes de Recebimento)

Explicitamente NAO tocados: Recebimento (notas, clientes, OCR local,
scanner Netum de notas), Talent, `rec_cnh`/`rec_crlv`, qualquer outra
etapa de Expedicao alem de CNH/CRLV, `app/Rn/ProdespClient.php` (mantido
como stub morto, API diferente).

## Riscos e pendencias consolidados

| # | Item | Origem | Severidade/natureza |
|---|---|---|---|
| 1 | Trial e descrito como ambiente MOCK — sem confirmacao categorica se aceita QR real | Documentacao oficial | Estrutural — aceito, tratado como limitacao conhecida, nao bloqueia a prova tecnica |
| 2 | URL exata do endpoint de token OAuth2 nao esta documentada | backend-especialista/devops-especialista | Bloqueia inicio da implementacao real do `VioDecodeClient` ate confirmar |
| 3 | `jsQR.binaryData` pode nao corresponder exatamente ao raw value esperado pela API | frontend-especialista | So teste pratico com QR real do Trial resolve — maior risco tecnico do plano |
| 4 | `DocumentoController::upload()` ja existente tem o mesmo IDOR ja corrigido em `NotaController` | backend-especialista (achado durante investigacao) | Correcao proposta dentro do escopo desta demanda |
| 5 | Nome exato de `etapa_atual` para CNH/CRLV no banco nao confirmado nesta investigacao | backend-especialista | A confirmar contra `AtendimentoRn` na implementacao |
| 6 | Decisao de produto: quais campos de CNH/CRLV persistir vs. so exibir/descartar | security-especialista | PENDENTE — decisao explicita do usuario antes da implementacao |
| 7 | Rate limit/timeout/custo/contratacao da VIO Decode nao confirmados oficialmente | ui-ux-especialista (pesquisa) | Fora do escopo desta prova tecnica; relevante so para producao futura |
| 8 | Cache de token OAuth2 (tabela vs arquivo) nao decidido | backend-especialista | Nao bloqueante — funciona sem cache nesta prova de baixo volume |
| 9 | QR oficiais de demonstracao CNH/CRLV do Serpro ainda nao obtidos | qa-testes | Bloqueia inicio pratico do roteiro de teste |
| 10 | Credenciais Trial (Consumer Key/Secret) ainda nao existem | devops-especialista | Dependem de cadastro da UDLOG no portal Serpro — fora do escopo tecnico |
| 11 | Hardware Netum indisponivel neste ambiente de desenvolvimento | qa-testes | Mesma limitacao ja conhecida do scanner de Recebimento |
| 12 | Caso de erro `VD003` sem forma etica/segura de reproducao identificada | qa-testes | Marcado como NAO TESTAVEL, nao inventar workaround |
| 13 | Teste de fronteira mock-vs-real com documento proprio requer aprovacao humana explicita | qa-testes | NAO presumido autorizado — aguardar decisao do usuario |
| 14 | Duplicacao deliberada de telas/funcoes entre Expedicao e Recebimento | frontend-especialista | Aceita como preco de nao tocar Recebimento, nao e divida a corrigir sem pedido |
| 15 | Decisao se reaproveita a mesma chave `localStorage` de `deviceId` do scanner de notas | frontend-especialista | Nao decidido, nao pedido explicitamente |
| 16 | Decisao se compara placa do CRLV no frontend, backend, ou ambos | frontend-especialista/backend-especialista | Nao decidido, nao pedido explicitamente |

## O que NAO sera feito nesta etapa

Nenhum codigo implementado, nenhuma migration real escrita, nenhum
arquivo de `app/`, `public/` alterado. Nenhuma alteracao em Recebimento,
notas, clientes, Talent, ou qualquer etapa de Expedicao alem de
CNH/CRLV. Nenhuma decisao de contratacao/producao/custo da VIO Decode.
Nenhum teste executado (so planejado). Unico artefato criado nesta
etapa: `docs/manual_vio_decode.md` (documentacao confirmada da API,
nunca implementacao).

## Sub-agentes envolvidos

- `explorer`: mapeamento do estado real do codigo (nada de VIO/QR existe
  hoje; achados sobre `DocumentoController::upload()`, telas
  compartilhadas, schema atual).
- `ui-ux-especialista`: pesquisa da documentacao oficial do Serpro via
  WebFetch (unico com essa ferramenta) — contrato real confirmado.
- `backend-especialista`: arquitetura de backend completa (cliente HTTP,
  endpoint, schema, regra de rebaixamento, validacoes, env vars,
  retry/timeout).
- `frontend-especialista`: avaliacao de biblioteca de QR, desenho de
  telas/Worker, payload, UX de erro/fallback.
- `security-especialista`: parecer preventivo sobre LGPD, credenciais,
  QR bruto, validacoes, ambiente trial/producao, retry.
- `devops-especialista`: padrao de variaveis de ambiente, comportamento
  seguro na ausencia de config, armazenamento de credenciais, rede.
- `qa-testes`: roteiro de teste da prova tecnica isolada, casos de erro,
  teste de fronteira mock-vs-real (com salvaguarda de aprovacao humana).

## Decisoes pendentes que precisam da sua confirmacao antes de `/01-implementacao`

1. Confirmar URL exata do endpoint de token OAuth2 (nao documentada) —
   provavelmente obtida junto das credenciais na area do cliente Serpro.
2. Decisao de produto: quais campos de CNH/CRLV serao persistidos em
   banco vs. so exibidos e descartados (recomendacao do
   security-especialista: minimizar, especialmente filiacao/data de
   nascimento).
3. Confirmar disponibilidade de QR oficiais de demonstracao CNH/CRLV do
   Serpro antes de iniciar a prova tecnica.
4. Confirmar obtencao de credenciais Trial (Consumer Key/Secret).
5. Decidir se o teste de fronteira mock-vs-real (documento proprio, com
   consentimento) sera autorizado — NAO presumido aqui.
6. Confirmar se a correcao do IDOR em `DocumentoController::upload()`
   entra no escopo desta demanda (recomendado pelo backend-especialista)
   ou fica para outra rodada.
7. Decidir sobre reaproveitar a mesma chave `localStorage` de `deviceId`
   entre Recebimento e Expedicao, ou usar uma separada.
8. Decidir onde comparar a placa do CRLV (frontend, backend, ou ambos).

## Proximo passo

Aguardar confirmacao do usuario sobre as pendencias acima (especialmente
1, 2, 3 e 4, que bloqueiam o inicio pratico) antes de rodar
`/01-implementacao`. Nenhuma decisao foi tomada unilateralmente por
nenhum sub-agente.

## Decisoes aprovadas pelo usuario (atualizacao do planejamento, 2026-09-08)

Substituem/resolvem os itens "Decisoes pendentes" listados acima. Nada
foi implementado ainda — esta secao registra decisoes de produto para a
proxima etapa (`/01-implementacao`).

### Campos persistidos (resolve pendencia 6)

**CNH**: `nome` (motorista), `cpf`, `data_validade` (validade da CNH).
**CRLV**: `exercicio` (ano do CRLV).

O JSON completo da resposta da VIO Decode NAO sera persistido. Alem dos
campos acima, ficam salvos: caminhos das imagens (fotos originais),
origem/status da validacao (`VIO_TRIAL`/`VIO_VALIDADO`/`MANUAL`/
`NAO_VALIDADO`), e metadados minimos de auditoria (ex.: timestamp da
validacao, ambiente usado). Isso muda o desenho de schema proposto
anteriormente: as colunas `cnh_dados_vio_json`/`crlv_dados_vio_json`
(JSON completo) da proposta original **NAO devem ser criadas** — o
schema real a implementar tera so os campos especificos acima, mais as
colunas de origem/status e metadados de auditoria. Isso tambem reduz a
superficie de dado pessoal sensivel retido (alinhado com a recomendacao
do security-especialista de minimizar).

### Regras de negocio para avancar no atendimento (novo)

**CNH valida somente quando, simultaneamente**:
- VIO retornar documento do tipo CNH como valido (sem erro
  VD001-VD004, resposta 200);
- `nome` estiver preenchido (nao vazio);
- `cpf` passar na validacao matematica (digito verificador — reaproveitar
  logica equivalente a `util/CnpjValidador.php`, mas para CPF, novo
  utilitario a criar na implementacao, ex. `util/CpfValidador.php`);
- `data_validade` estiver preenchida E nao vencida (comparar contra a
  data atual do servidor).

**CRLV valido somente quando, simultaneamente**:
- VIO retornar documento do tipo CRLV como valido (sem erro
  VD001-VD004, resposta 200);
- `exercicio` estiver preenchido E for numerico;
- a placa retornada pela VIO Decode for IGUAL a placa ja registrada no
  atendimento (comparacao normalizada, sem mascara/case).

**Explicitamente NAO valida**: o `exercicio` sozinho NAO significa que o
veiculo esta licenciado em tempo real — a VIO Decode so valida a
autenticidade/assinatura do QR, nao consulta restricoes atuais (multas,
bloqueio, licenciamento vigente etc.). Essa limitacao deve ficar clara
na UI (nao apresentar como "veiculo licenciado", so como "QR do CRLV
validado tecnicamente").

**Se qualquer validacao falhar** (CNH ou CRLV): NAO avancar
automaticamente no atendimento — direcionar para nova tentativa de
captura OU para a portaria (fallback manual/humano), nunca travar o
totem sem saida. Replica o mesmo espirito ja usado no Recebimento
(fallback manual quando OCR nao identifica), mas aqui com um destino
adicional explicito (portaria) alem da tentativa nova.

### Autorizacoes confirmadas pelo usuario

1. **Localizar e usar os QR Codes oficiais de demonstracao** do Serpro —
   autorizado, resolve a pendencia 9 (bloqueio de inicio do roteiro de
   teste).
2. **Testar documento real proprio, com consentimento, SEM registrar
   dados pessoais em logs, testes ou documentacao** — autorizado com
   essa condicao explicita (resolve a pendencia 13, o teste de fronteira
   mock-vs-real que exigia aprovacao humana). Qualquer evidencia gerada
   por esse teste (prints, logs, arquivos de teste) NAO PODE conter CPF,
   nome completo, data de nascimento ou qualquer dado pessoal real —
   usar mascaramento/redacao em qualquer registro que precise ser
   guardado como evidencia tecnica.
3. **Corrigir o IDOR existente em `DocumentoController::upload()` nesta
   mesma demanda** — confirmado, resolve a pendencia de escopo (item 4
   da tabela de riscos e decisao pendente 6 anterior).
4. **Salvar as fotos da CNH e do CRLV para anexacao futura ao sistema** —
   confirmado, mantem o desenho ja proposto (fotos originais sempre
   salvas via `UploadHelper`, mesmo se a validacao de QR falhar depois).

### Endpoint OAuth2 — a confirmar antes de implementar

Usuario informou que o endpoint oficial documentado e:
`https://gateway.apiserpro.serpro.gov.br/token`

Conforme instruido explicitamente pelo usuario ("Confirme novamente na
documentacao antes de implementar"), esta URL esta sendo RECONFIRMADA
via WebFetch pelo `ui-ux-especialista` nesta mesma rodada de
atualizacao do planejamento, antes de ser aceita como definitiva no
`docs/manual_vio_decode.md`. Resultado da reconfirmacao sera acrescentado
a esta secao assim que concluido — **nao presumido como confirmado
antes desse retorno**.

Credenciais (`VIO_CONSUMER_KEY`/`VIO_CONSUMER_SECRET`) continuam
exclusivamente no `.env`, nunca hardcoded, nunca no front-end — reforcado,
sem mudanca em relacao ao plano original.

### Status no Trial (reforco, sem mudanca de decisao anterior)

Confirmado pelo usuario: usar status `VIO_TRIAL` no ambiente Trial, e
NUNCA apresentar documento real como oficialmente validado nesse
ambiente. A validacao real (peso oficial/juridico) depende do ambiente
de producao contratado no futuro — nao decidido, nao contratado nesta
etapa.

## Pendencias atualizadas (substituem a lista anterior de "Decisoes pendentes")

Resolvidas por esta atualizacao: campos a persistir (✓), regras de
avanco no atendimento (✓), autorizacao para localizar QR de demonstracao
(✓), autorizacao condicional para teste com documento proprio (✓),
confirmacao de que o IDOR entra no escopo (✓), confirmacao de salvar
fotos (✓).

Ainda pendentes:
1. **Reconfirmacao do endpoint OAuth2** (`https://gateway.apiserpro.serpro.gov.br/token`)
   — em andamento nesta mesma rodada, resultado a seguir.
2. Cache de token OAuth2 (tabela vs arquivo) — nao bloqueante, decisao de
   implementacao.
3. Nome exato de `etapa_atual` para CNH/CRLV no banco — a confirmar
   contra `AtendimentoRn` na implementacao.
4. Confirmacao pratica de que `jsQR.binaryData` corresponde ao raw value
   esperado pela API — so teste real resolve.
5. Decisao se reaproveita a mesma chave `localStorage` de `deviceId` do
   scanner de notas do Recebimento, ou usa uma separada.
6. Rate limit/timeout/custo/contratacao da VIO Decode continuam nao
   confirmados oficialmente — fora do escopo desta prova tecnica em
   Trial.
7. `VD003` continua sem forma etica/segura de reproducao identificada —
   nao testavel nesta prova, salvo exemplo oficial do Serpro.

## Proximo passo

Aguardar o resultado da reconfirmacao do endpoint OAuth2 (em andamento).
Apos isso, com os campos/regras/autorizacoes ja decididos nesta
atualizacao, o planejamento fica pronto para `/01-implementacao`, desde
que as pendencias 1-2-3-4-5 acima sejam tratadas explicitamente na fase
de implementacao (nenhuma delas bloqueia o INICIO da implementacao,
exceto a pendencia 1 caso a URL nao seja confirmavel).

## Resultado da reconfirmacao do endpoint OAuth2 (tentativa)

Data: 2026-09-08

Tentei reconfirmar via `WebFetch` (delegado ao `ui-ux-especialista`, unico
sub-agente com essa ferramenta neste ambiente) se
`https://gateway.apiserpro.serpro.gov.br/token` e o endpoint oficial
documentado. O agente recusou a tarefa em 2 tentativas distintas,
considerando-a fora do escopo dele (identidade visual/UX, nao
integracao de API externa) — recusa consistente e coerente com a
definicao de papel dele, nao um erro pontual. Nao ha nenhum outro
sub-agente nem ferramenta de acesso a internet disponivel neste ambiente
de trabalho para o orquestrador ou os demais especialistas.

**Resultado: NAO FOI POSSIVEL reconfirmar essa URL de forma
independente neste ciclo.** Registrado em `docs/manual_vio_decode.md`
(secao "Endpoint OAuth2 de token — status da confirmacao") como
"fornecida pelo usuario, nao verificada tecnicamente por este projeto"
— nao presumida como correta so por ter sido informada, conforme a
regra do projeto de nunca aceitar contrato de API nao confirmado.

Recomendacao registrada (nao implementada): manter o endpoint de token
como variavel de ambiente configuravel (`VIO_TOKEN_URL` em `.env`),
nunca hardcoded no `VioDecodeClient`, justamente por nao estar 100%
confirmada — se estiver errada, corrige-se so a configuracao, sem
alterar codigo.

**Pedido ao usuario**: se possivel, confirmar a fonte de onde essa URL
foi obtida (print da area do cliente Serpro, documentacao especifica
recebida na demonstracao aprovada mencionada no pedido original, ou
outro documento confiavel) — isso substituiria a necessidade de
reconfirmacao automatizada, que nao foi possivel neste ambiente.

## Confirmacao do endpoint OAuth2 (atualizacao final)

Data: 2026-09-08

O usuario confirmou o endpoint `POST https://gateway.apiserpro.serpro.gov.br/token`
via TESTE HTTP REAL (nao via documentacao estatica) — chamada sem
credenciais retornou `401 invalid_client` / "Unsupported Client
Authentication Method" com `WWW-Authenticate: Basic realm=...`,
resultado esperado e tecnicamente coerente que confirma: (a) a URL
existe e responde corretamente como endpoint OAuth2 real (nao 404), e
(b) o metodo de autenticacao exigido e HTTP Basic Auth no header
`Authorization` (Base64 de `Consumer Key:Consumer Secret`), refinando o
entendimento do fluxo ja descrito na documentacao oficial.

`docs/manual_vio_decode.md` foi atualizado: removida a observacao "URL
nao verificada", substituida pela secao "Endpoint OAuth2 de token —
CONFIRMADO por teste real", sem registrar cookies de sessao nem dump
completo de headers do teste (por instrucao explicita do usuario).

**Regra de configuracao mantida/reforcada**:
- `VIO_TOKEN_URL` continua configuravel via `.env` (nunca hardcoded no
  codigo) — nao por duvida sobre a URL (agora confirmada), mas por boa
  pratica (facilita ajuste se a Serpro mudar no futuro).
- **Para Producao**: `VIO_CONSUMER_KEY`/`VIO_CONSUMER_SECRET` sao
  OBRIGATORIOS — se ausentes/vazios com `VIO_AMBIENTE=production`, o
  `VioDecodeClient` deve falhar explicitamente no bootstrap (mesmo
  padrao de "fail closed" ja definido para `VIO_AMBIENTE` invalido),
  nunca tentar chamar a API sem credenciais em producao.
- **Para Trial**: pendente confirmar se a documentacao oficial oferece
  algum Bearer/token de demonstracao pre-gerado (sem precisar do fluxo
  completo de Consumer Key/Secret) — investigacao em andamento nesta
  mesma rodada (ver secao de pendencias). Se a resposta for negativa
  (Trial exige o mesmo fluxo OAuth2 completo que Producao, so mudando a
  URL base do endpoint de decode), o requisito de Consumer Key/Secret
  vale igualmente para Trial, e a unica diferenca entre os dois
  ambientes seria a URL de decode (`viodec-trial` vs `viodec`) mais o
  significado do resultado (mock vs oficial).

### Bearer de demonstracao para Trial — nao verificavel neste ambiente

Tentativa de confirmar se a documentacao oficial oferece um Bearer/token
de demonstracao pre-gerado para o ambiente Trial (dispensando o fluxo
completo de Consumer Key/Secret) NAO teve sucesso — o
`ui-ux-especialista` (unico agente com `WebFetch` neste ambiente)
recusou a tarefa em 3 tentativas distintas por considera-la fora do
escopo dele, mesmo com o pedido reformulado como checagem factual
pontual. Nenhum outro sub-agente tem acesso a internet.

`docs/manual_vio_decode.md` (fonte ja confirmada) NAO menciona, em
nenhum trecho ja levantado, a existencia desse recurso — mas ausencia de
mencao nao e prova de ausencia (pode nao ter sido perguntado
especificamente na pesquisa original).

**Decisao de planejamento adotada na duvida**: assumir, ate confirmacao
em contrario, que o Trial exige o MESMO fluxo OAuth2 completo que
Producao (Consumer Key/Secret proprios, obtidos no cadastro do portal
Serpro) — unica diferenca entre os ambientes seria a URL de decode
(`viodec-trial` vs `viodec`). Isso e uma premissa de planejamento, nao
um fato confirmado — se o usuario tiver acesso a essa informacao (ex.:
material da demonstracao aprovada, ou a propria area do cliente Serpro),
a confirmacao dele substitui essa premissa.

## Confirmacao final: Bearer publico do Trial (atualizacao)

Data: 2026-09-08

O usuario localizou e confirmou, no Swagger oficial
(`https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/swagger/`),
que o ambiente Trial disponibiliza um Bearer token PUBLICO e
compartilhado, dispensando o fluxo OAuth2 completo (Consumer Key/Secret)
SOMENTE para uso no Trial. Registrado em `docs/manual_vio_decode.md`
(secao "Bearer publico do ambiente Trial — CONFIRMADO no Swagger
oficial") **sem incluir o valor literal do token** em nenhum lugar
(nem documentacao, nem handoff, nem qualquer arquivo) — por instrucao
explicita do usuario, tratado como segredo desde a fase de
planejamento.

Isso RESOLVE a pendencia anterior ("nao verificavel se o Trial oferece
Bearer de demonstracao") — a premissa adotada antes (Trial exigiria o
mesmo fluxo completo que Producao) estava incorreta e e substituida por
este fato confirmado.

### Desenho final de variaveis de ambiente (`.env`, NAO alterado nesta etapa — so planejado)

```
VIO_AMBIENTE=trial
VIO_TRIAL_BEARER=
VIO_TRIAL_DECODE_URL=https://gateway.apiserpro.serpro.gov.br/viodec-trial/v1/decode
VIO_TOKEN_URL=https://gateway.apiserpro.serpro.gov.br/token
VIO_CONSUMER_KEY=
VIO_CONSUMER_SECRET=
VIO_PRODUCAO_DECODE_URL=https://gateway.apiserpro.serpro.gov.br/viodec/v1/decode
```

Todos os placeholders vazios acima (`VIO_TRIAL_BEARER`,
`VIO_CONSUMER_KEY`, `VIO_CONSUMER_SECRET`) sao preenchidos SOMENTE no
`.env` real de cada ambiente, nunca commitados, nunca com valor de
exemplo no `.env.example` alem do nome vazio da variavel — mesmo padrao
ja usado no projeto para toda credencial externa.

### Regra de autenticacao por ambiente (substitui a versao anterior)

- **`VIO_AMBIENTE=trial`**: `VioDecodeClient` usa `VIO_TRIAL_BEARER`
  DIRETAMENTE no header `Authorization: Bearer <valor>` das chamadas a
  `VIO_TRIAL_DECODE_URL` — SEM passar pelo fluxo OAuth2 de
  `VIO_TOKEN_URL` (nao e necessario para o Trial). Falha explicita se
  `VIO_TRIAL_BEARER` estiver vazio com `VIO_AMBIENTE=trial` (mesmo
  padrao fail-closed ja definido).
- **`VIO_AMBIENTE=production`**: `VIO_CONSUMER_KEY`/`VIO_CONSUMER_SECRET`
  OBRIGATORIOS — `VioDecodeClient` obtem o token via
  `POST VIO_TOKEN_URL` com HTTP Basic Auth (header `Authorization:
  Basic <base64(key:secret)>`, confirmado pelo teste HTTP real anterior),
  usa o `access_token` retornado nas chamadas a
  `VIO_PRODUCAO_DECODE_URL`. Falha explicita se as credenciais
  estiverem ausentes.
- Nenhum dos dois fluxos e cruzado — o `VIO_TRIAL_BEARER` nunca e usado
  contra a URL de producao, e vice-versa (o `VioDecodeClient` decide o
  fluxo inteiro — URL + metodo de auth — a partir de um unico ponto de
  configuracao, `VIO_AMBIENTE`, nunca inferido de outra forma).

### Atualizacao da tabela de riscos/pendencias

Removida a pendencia "Bearer de demonstracao para Trial nao verificavel"
(RESOLVIDA). Nova nota de seguranca: o Bearer publico do Trial, por ser
COMPARTILHADO e PUBLICO (documentado no Swagger oficial, acessivel a
qualquer desenvolvedor), tem uma natureza diferente de uma credencial
privada — mesmo assim, deve seguir o mesmo tratamento de qualquer
segredo do projeto (nunca commitado, nunca em log), tanto por precaucao
quanto porque a documentacao confirma que pode ser alterado/revogado
pelo Serpro sem aviso, exigindo o mesmo cuidado operacional de qualquer
credencial rotativa.

## Proximo passo (atualizado)

Com o endpoint OAuth2 confirmado por teste real e o Bearer do Trial
confirmado no Swagger oficial, as duas maiores pendencias bloqueantes
de infraestrutura de autenticacao estao resolvidas. Pendencias
remanescentes antes de `/01-implementacao` (ver lista completa acima,
secao "Decisoes pendentes" original, atualizada): nome exato de
`etapa_atual` para CNH/CRLV no banco, confirmacao pratica de
`jsQR.binaryData`, decisao sobre chave de `localStorage` de `deviceId`,
decisao sobre onde comparar a placa do CRLV. Nenhuma dessas bloqueia o
INICIO da implementacao — podem ser resolvidas durante ela.

## Resultado da implementacao (01-implementacao)

Data: 2026-09-08

### Divergencia estrutural encontrada e resolvida antes de implementar

O explorer confirmou que NAO existia etapa_atual de backend para
CNH/CRLV na Expedicao (telas eram so state.tela no front, banco ficava
travado em dados_encontrados do inicio ao fim). O usuario decidiu
introduzir uma maquina de estados REAL, autorizada so pelo backend:

dados_encontrados -> exp_cnh -> exp_crlv -> exp_confirmacao -> impressao

Isso expandiu o escopo original do handoff (que presumia reaproveitar
validacao simples) - implementado dessa forma.

### Backend implementado

- app/Rn/VioDecodeClient.php (novo): Trial usa VIO_TRIAL_BEARER direto
  no header Authorization; Producao usa OAuth2 client_credentials com
  HTTP Basic (Consumer Key/Secret), cache de token em
  storage/cache/vio_token_production.json. Fail-closed se ambiente
  invalido ou credencial faltante. Trata 200/400/422(VD00x)/401/429/5xx/
  timeout, 1 retry so para rede/timeout, nunca propaga excecao.
- app/Rn/DocumentoRn.php (novo): HMAC-SHA256 do QR bruto
  (DOCUMENTO_QR_HMAC_KEY), fluxo cache->VIO->regras de aprovacao para
  CNH/CRLV, CNH vencida nunca reaproveita cache, CRLV sempre recompara
  placa do atendimento ATUAL (nao do atendimento que originou o cache),
  manual nunca grava cache.
- app/Dao/VioCacheDao.php (novo): tb_vio_cache_cnh/tb_vio_cache_crlv,
  UNIQUE(identificador_qr, ambiente) separa Trial/Producao
  completamente, INSERT...ON DUPLICATE KEY UPDATE atomico.
- util/CriptografiaHelper.php (novo): AES-256-GCM, IV unico por
  operacao, tag verificada na descriptografia, chave via
  DOCUMENTO_DATA_KEY. Usado para nome/cpf do cache de CNH.
- util/CpfValidador.php (novo): mesmo padrao de CnpjValidador.
- app/Controller/DocumentoController.php: upload() IDOR corrigido
  (posse/tipo/status/etapa antes de salvar, aceita frente/verso CNH e
  imagem unica CRLV); validarQr() (novo) e preencherManual() (novo).
- app/Controller/AtendimentoController.php: avancarEtapaExpedicao()
  (novo) - maquina de estados real, revalida posse/tipo/status/etapa a
  cada chamada; CORRIGIDO apos achado do qa-testes: case exp_confirmacao
  agora recheca cnhAprovada()/crlvAprovado() antes de liberar impressao
  (nao rechecava na primeira versao).
- sql/migrations/005_vio_decode_cnh_crlv.sql (novo, idempotente, padrao
  SQL preparado condicional + SET NAMES utf8mb4): tabelas de cache +
  colunas novas em tb_atendimento
  (cnh_origem_validacao/cnh_status_revisao/crlv_origem_validacao/
  crlv_status_revisao, ENUM VIO_TRIAL/VIO_VALIDADO/MANUAL/NAO_VALIDADO
  e OK/PENDENTE_REVISAO). sql/schema.sql atualizado.
- Lock por atendimento+tipo via GET_LOCK/RELEASE_LOCK (sem tabela nova)
  contra chamada duplicada/concorrencia.
- .env local recebeu as credenciais reais (Bearer Trial, chaves HMAC/
  criptografia geradas) - nunca exibidas em nenhuma resposta nem
  documentacao. .env.example recebeu so os nomes das variaveis (vazias,
  exceto URLs publicas e VIO_AMBIENTE=trial/VIO_CACHE_TTL_DIAS=30).

### Frontend implementado

- jsQR vendorizado localmente (public/totem/assets/jsqr/jsQR.js, sem
  CDN) + qr-worker.js (worker dedicado, nao aninhado, seguro contra o
  bug ja documentado do Tesseract.js).
- Telas NOVAS e dedicadas de Expedicao (exp_cnh_frente, exp_cnh_verso,
  exp_cnh_manual, exp_crlv, exp_crlv_manual) - SEM tocar em
  rec_cnh/rec_crlv/rec_digitaliza. Reaproveita a chave
  totem_scanner_deviceId do localStorage (mesmo hardware Netum).
- Fluxo: captura->preview->confirma->upload->leitura QR
  (worker)->validar-qr->avancar-etapa-expedicao (front NUNCA decide
  sozinho mudar de tela). Falha de QR oferece "Tentar novamente" ou
  "Preencher manualmente", nunca trava.
- Avisos: "Integracao Trial validada" inline (nunca modal/header fixo).
  Tela de confirmacao mostra literalmente VIO_TRIAL, VIO_VALIDADO ou
  "MANUAL - pendente de revisao" para cada documento.
- Botoes de acao desabilitados durante chamada de rede (evita duplicidade).

### Validacoes executadas (nao so lidas - execucao real)

- php -l em todos os 14+ arquivos PHP alterados/criados: sem erro.
- node --check em app.js/qr-worker.js/jsQR.js: sem erro.
- 69 asserts no total entre os testes: teste_vio_decode.php (21/21),
  teste_avancar_etapa_expedicao.php (8/8, incluindo o novo cenario da
  correcao), mais 41 asserts complementares do qa-testes (concorrencia
  real via 2 subprocessos, criptografia lida direto do banco,
  varredura de QR bruto em banco/log, preenchimento manual
  valido/invalido, IDOR por etapa/status/totem errados, fail-closed de
  credenciais, erros VIO mockados 401/429/5xx/timeout) - TODOS
  passaram, 0 residuo de dado de teste.
- Revisao de seguranca completa (14 pontos) sem achado critico - 1
  achado de atencao (lock nao liberado explicitamente em finally por
  causa de exit() em Resposta::erro(), mitigado hoje pela conexao
  nao-persistente, documentado como pendencia).
- Nao regressao confirmada por diff: Recebimento, notas, OCR de
  clientes, Talent, ordem de coleta intocados - unico ponto de leitura
  cruzada e tb_atendimento.placa (ja existente), exatamente como
  autorizado.

### Achado corrigido durante esta rodada

exp_confirmacao -> impressao nao rechecava CNH/CRLV aprovados -
encontrado pelo qa-testes (forcando NAO_VALIDADO e confirmando que o
avanco ocorria indevidamente), corrigido pelo backend-especialista no
mesmo ciclo, revalidado com 8/8 + 21/21 apos a correcao.

### Pendencias / bloqueios remanescentes

1. HTTP 415 no teste real contra a VIO Decode Trial - formato exato do
   corpo/Content-Type esperado pela API nao esta documentado nem
   confirmado na pratica; o payload tentado (JSON {"raw_value":...})
   nao foi aceito. Bloqueia a integracao ponta-a-ponta real (mock foi
   usado para todos os testes de sucesso/erro estruturado). Precisa de
   exemplo oficial (Swagger/collection do Serpro) ou teste iterativo
   com outros formatos (application/octet-stream, form-data, etc.).
2. Correspondencia pratica de jsQR.binaryData com o raw value esperado
   pela API continua sem confirmacao - so teste fisico com QR real
   resolve, agravado pelo achado 1.
3. Lock GET_LOCK/RELEASE_LOCK nao liberado explicitamente em caminhos
   de erro (mitigado hoje, documentacao do codigo incorreta) - achado
   de atencao do security-especialista, correcao futura sugerida (nao
   decidida: corrigir comentario ou ajustar Resposta::erro()).
4. motorista_nome/motorista_cpf em tb_atendimento (dado do atendimento
   em si) permanecem em texto plano - so o CACHE de CNH e
   criptografado, decisao registrada, nao alterada.
5. Retomada apos recarregar a pagina nao implementada (limitacao
   pre-existente do projeto, nao expandida nem criada aqui).
6. Validacao fisica real (camera Netum, QR real, round-trip completo
   com VIO) nao foi possivel neste ambiente - sem hardware/browser.
7. Formato exato de data_validade da CNH retornado pela VIO nao
   confirmado - normalizarData() aceita Y-m-d e dd/mm/aaaa, ajustar se
   necessario apos teste real.

### Confirmacao de escopo

Nenhuma alteracao em Recebimento (notas, OCR de clientes, scanner Netum
de notas), Talent, ordem de coleta, EXCETO a leitura de
tb_atendimento.placa (ja existente), exatamente como autorizado.
Nenhum commit/push realizado.

### Proximo passo

Rodar /02-testes formal (incluindo tentativa de resolver o HTTP 415
com formatos alternativos de payload, e teste fisico assim que hardware
estiver disponivel) antes de /03-revisao.

## Correcao da integracao real (formato de payload) — HTTP 415 RESOLVIDO

Data: 2026-09-08

### Evidencia fisica fornecida pelo usuario

Testes diretos com os arquivos oficiais de demonstracao do Serpro,
enviados com `Content-Type: application/octet-stream` (bytes binarios
puros, sem JSON/base64/hex):

- `qrcode-trial.bin` (1041 bytes) -> HTTP 200
- `crlv-demo.bin` (232 bytes) -> HTTP 200, retornando `template.name`,
  `data.placa`, `data.exercicio` e demais campos

Isso confirmou que o HTTP 415 anterior era causado pela montagem
incorreta do corpo da requisicao (JSON `{"raw_value":...}`), nao por
problema de autenticacao/URL (ja confirmados corretos antes).

### Correcao aplicada

`app/Rn/VioDecodeClient.php` reescrito: `CURLOPT_POSTFIELDS` agora
recebe uma STRING BINARIA PURA (nunca array/JSON/base64/hex/multipart/
`CURLFile`), com headers exatos (`Accept: application/json`,
`Content-Type: application/octet-stream`, `Authorization: Bearer`,
`Content-Length` real). O HMAC do cache usa exatamente os MESMOS bytes
binarios enviados ao Serpro (provado via SHA-256 identico nos dois
pontos, sem re-serializacao no meio).

### Achado adicional descoberto durante a correcao

A resposta real da VIO Decode vem ENVELOPADA em `template`/`data`/
`image` — nao achatada na raiz do JSON como a suposicao anterior
presumia. `app/Rn/DocumentoRn.php` corrigido para ler `dados['data']`
(com fallback para o formato achatado, preservando compatibilidade com
os mocks ja existentes nos testes automatizados).

### Limitacoes do Trial confirmadas e tratadas no codigo

- `qrcode-trial.bin` representa um CRACHA GENERICO, nao uma CNH real —
  o Trial, mesmo com HTTP 200, so comprova que o TRANSPORTE funciona,
  nao que os dados sejam de uma CNH de verdade. Nao existe QR de
  demonstracao de CNH real disponibilizado pelo Serpro.
- `crlv-demo.bin` no Trial retorna `placa`/`exercicio` como
  **placeholder `"xxxxx"`** — implementado `ehValorPlaceholder()` em
  `DocumentoRn` que REJEITA explicitamente esse padrao (case-insensitive,
  variacoes obvias) tanto na aprovacao (`avaliarCrlv`) quanto no cache
  (nunca grava valor placeholder como se fosse dado real).
- O Trial NAO PODE validar comparacao de placa real nem mapeamento
  completo de CNH — para testar o avanco completo do atendimento no
  Trial, o caminho e sempre "Preencher dados manualmente", resultando em
  `MANUAL`/`PENDENTE_REVISAO` (confirmado que continua funcionando apos
  a correcao).
- Producao continua exigindo dados reais retornados pelo VIO, placa
  correspondente de verdade, e todas as validacoes aprovadas — nenhuma
  flexibilizacao de regra foi introduzida para producao.
- Nunca mostrar "autenticidade confirmada" no Trial — reforcado, sem
  mudanca na decisao ja tomada.

### Validacao executada (19/19, teste real contra o Trial vivo)

Novo `tests/manual/teste_vio_decode_wire_format.php`: ambos os arquivos
retornam HTTP 200, tamanho de bytes preservado, SHA-256 identico entre
entrada do backend e o que e enviado via cURL, nenhuma persistencia/log
dos bytes brutos (so tamanho/hash registrados nos testes), JSON
interpretado corretamente, erro 422 (VD00x) corretamente separado de
415 (que agora tem branch de erro dedicado, `formato_corpo_invalido`,
nao deveria mais ocorrer, mas fica tratado se ocorrer por regressao
futura).

### Sobre "Demonstra├º├úo" visto no PowerShell

Confirmado: e problema de exibicao do proprio terminal PowerShell (nao
decodifica UTF-8 por padrao em alguns consoles), nao um bug de
decodificacao no PHP. O backend confirma que a resposta e interpretada
corretamente como UTF-8 pelo `json_decode` (que espera UTF-8 por
padrao) — nenhuma correcao de codigo foi necessaria para esse ponto.

### Arquivos .bin oficiais — nao versionados

Baixados temporariamente para o teste (scratchpad da sessao, fora do
repositorio), apagados ao final. `git status` confirmou que nao ficaram
como untracked/staged. Para reproduzir o teste no futuro, baixar de
novo em:
```
https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/qrcodes/qrcode-trial.bin
https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/qrcodes/crlv-demo.bin
```

### Pendencias remanescentes (atualizadas)

1. **RESOLVIDA**: formato do corpo da requisicao (HTTP 415).
2. Correspondencia pratica de `jsQR.binaryData` (extracao via camera
   Netum real) com o raw value esperado pela VIO continua sem
   confirmacao — testado so com os `.bin` oficiais alimentados
   diretamente, nao via captura de camera real.
3. Formato exato de `data_validade` da CNH continua nao confirmado —
   nao existe QR de demonstracao de CNH real do Serpro para testar.
4. O envelope `template`/`data`/`image` pode variar para templates de
   CNH real (so verificado para cracha generico e CRLV mock).
5. Front-end (telas `exp_cnh_*`/`exp_crlv_*`) ainda nao foi revalidado
   contra este novo entendimento do formato de resposta — recomendado
   cobrir em `/02-testes` formal.
6. Demais pendencias ja registradas (lock de erro, dado de atendimento
   em texto plano, retomada apos reload, validacao fisica completa)
   continuam sem mudanca.

### Proximo passo

Rodar `/02-testes` formal, incluindo revalidacao do front-end contra o
formato de resposta corrigido, e teste fisico assim que hardware/QR de
CNH real (ou aprovacao para teste com documento proprio, ja autorizada
anteriormente) estiver disponivel.

## Resultado dos testes (02-testes)

Data: 2026-09-08

Reexecutados do zero por qa-testes/security-especialista/frontend-especialista,
sem confiar nos relatos anteriores, incluindo chamadas REAIS ao Trial
vivo do Serpro com os arquivos oficiais.

### Resultado item a item (todos PASSOU)

1. php -l (23 arquivos) - PASSOU
2. node --check (app.js/qr-worker.js/jsQR.js) - PASSOU
3. Formato normalizado template/data/image - PASSOU (mock achatado E
   envelope real testados, 21/21 + 19/19)
4. Bytes puros no VioDecodeClient - PASSOU (revalidado contra Trial
   vivo com qrcode-trial.bin/crlv-demo.bin, HTTP 200 nos dois)
5. Cache hit sem nova consulta - PASSOU
6. Cache miss consultando VIO - PASSOU
7. Separacao Trial/Producao - PASSOU
8. Maquina de estados completa (incluindo rechecagem na transicao
   final, correcao da rodada anterior) - PASSOU
9. IDOR e pular etapas - PASSOU
10. Preenchimento manual valido/invalido - PASSOU
11. Retomada apos recarregar - confirmado que front nao retoma sozinho
    (limitacao pre-existente, nao desta demanda), mas dados ja
    gravados no backend nao se perdem - PASSOU (comportamento esperado)
12. Rejeicao de "xxxxx" - PASSOU (testado inclusive com resposta real
    do crlv-demo.bin, que retorna xxxxx literal)
13. Ausencia de QR bruto/JSON completo/Bearer/cookies/image.base64 em
    banco/logs - PASSOU
14. Frontend consumindo so resposta normalizada - PASSOU
15. Nao regressao - PASSOU

### Achados de atencao (nao bloqueantes, recomendacoes registradas)

- **[Seguranca]** Campo `image` do envelope VIO (pode conter foto do
  documento) nao e descartado explicitamente - hoje nao vaza para
  log/banco, mas fragil a mudanca futura. Recomendado descarte
  explicito como defesa em profundidade.
- **[Seguranca]** Rejeicao de placeholder (`ehValorPlaceholder()`)
  cobre CRLV mas nao CNH - assimetria de robustez. Sem QR de CNH real
  disponivel para confirmar se e risco pratico hoje.
- **[Frontend]** Rotulo de origem (`VIO_TRIAL`/`VIO_VALIDADO`/`MANUAL`)
  e derivado localmente no front em vez de vir de um campo explicito do
  backend - funciona hoje, mas e logica de negocio duplicada.

### ACHADO IMPORTANTE — divergencia entre planejamento e implementacao

**A regra de "rebaixamento para MANUAL" planejada originalmente no
`/00-planejamento` (quando o atendente edita manualmente um campo que
veio validado pelo VIO, a origem deveria ser rebaixada de
VIO_TRIAL/VIO_VALIDADO para MANUAL) NUNCA FOI IMPLEMENTADA.**

Confirmado por `git diff app/Rn/AtendimentoRn.php` (sem alteracao) —
`salvarDadosMotorista()` continua sem logica de comparar o valor
editado contra o valor ja gravado nem de rebaixar
`cnh_origem_validacao`/`crlv_origem_validacao` quando o atendente edita
um campo na tela `exp_confirma`.

Isso significa que, hoje, se um atendente EDITAR manualmente o nome ou
CPF de uma CNH que foi validada pelo VIO, o sistema continuaria
mostrando `VIO_TRIAL`/`VIO_VALIDADO` mesmo que o dado exibido nao seja
mais o que veio da validacao automatica — inconsistencia real entre o
que foi decidido no planejamento e o que existe hoje.

### Veredito

**APROVADO COM RESSALVAS.**

Todos os 15 itens pedidos nesta rodada de teste passaram, incluindo
validacao real contra o Trial vivo do Serpro. As ressalvas sao:
1. Divergencia de planejamento (rebaixamento para MANUAL nao
   implementado) — requer decisao do usuario se corrige agora ou fica
   registrado como pendencia.
2. 2 achados de seguranca de atencao (campo image, assimetria de
   placeholder CNH/CRLV) — recomendacoes, nao bloqueantes.
3. 1 observacao de frontend (logica de origem duplicada) — nao
   bloqueante.

### Proximo passo

Aguardar decisao do usuario sobre corrigir a regra de rebaixamento para
MANUAL (e opcionalmente os 2 achados de seguranca) antes de
`/03-revisao`, ou seguir para revisao com essas pendencias documentadas.

## REPLANEJAMENTO (voltando de /01-implementacao para /00-planejamento)

Data: 2026-09-09

### Correcao de registro (nao e divergencia real)

O explorer confirmou que a "ACHADO IMPORTANTE - rebaixamento para MANUAL
nunca implementado" registrada na secao de /02-testes acima JA FOI
CORRIGIDA no ciclo seguinte (correcao pontual que rodamos antes desta
pausa) - o handoff so nao tinha sido atualizado com o resultado daquela
correcao antes do usuario pedir a pausa para replanejar. Nao e uma
inconsistencia de codigo, e atraso de documentacao do proprio
orquestrador. AtendimentoRn::salvarDadosMotorista() ja implementa o
rebaixamento comparando contra snapshot (cnh_snapshot_*/crlv_snapshot_*,
migration 006). Corrigido/confirmado agora.

### Mudanca de escopo aprovada pelo usuario

1. VIO Decode passa a valer em Expedicao E Recebimento (nao so
   Expedicao).
2. Processamento precisa ser ASSINCRONO do ponto de vista do navegador:
   captura de CNH libera IMEDIATAMENTE a captura do CRLV, sem esperar a
   validacao terminar. So depois do CRLV confirmado e que existe uma
   tela de carregamento (se ainda faltar processamento) ou fallback
   manual.
3. Novo status persistido por documento: PENDENTE/PROCESSANDO/
   VIO_TRIAL/VIO_VALIDADO/MANUAL/ERRO + revisao NAO_NECESSARIA/
   PENDENTE_REVISAO.
4. Restricao de infraestrutura reforcada: Hostgator sem daemon - todo
   "segundo plano" e client-side (JS nao bloqueante), nunca processo
   servidor persistente.

### Achado CRITICO que condiciona a implementacao (frontend-especialista)

A maquina de estados atual (AtendimentoController::avancarEtapaExpedicao)
BLOQUEIA a transicao exp_cnh -> exp_crlv ate a CNH estar aprovada pelo
VIO - fisicamente incompativel com o requisito de liberar o CRLV
imediatamente. Correcao proposta (nao decidida, para backend-especialista
fechar em /01-implementacao):
- exp_cnh -> exp_crlv e exp_crlv -> exp_confirmacao: passam a exigir so
  que a FOTO tenha sido enviada (upload feito), nao que a validacao VIO
  ja tenha terminado. A aprovacao continua OBRIGATORIA no gate final que
  ja existe (exp_confirmacao -> impressao, que ja recheca
  cnhAprovada()/crlvAprovado()), sem nenhuma mudanca nesse gate final.
- Checagem de etapa em validar-qr/preencher-manual muda de "igual a
  etapa esperada" para "igual OU POSTERIOR" (ordinal na sequencia) -
  necessario porque a chamada de validacao da CNH e disparada
  fire-and-forget ANTES do avanco de etapa, e as duas chamadas de rede
  correm em paralelo; se a etapa ja avancou quando o servidor processa
  validar-qr, o check antigo rejeitaria uma validacao legitima.
  upload() continua com check EXATO de etapa (uploads seguem
  estritamente ordenados).
- Mesmo raciocinio para as novas etapas de Recebimento.

### Desenho de status de processamento (backend-especialista)

Campo NOVO e SEPARADO de origem_validacao (nao funde os dois conceitos,
preserva toda a logica ja implementada em DocumentoRn):

cnh_status_processamento  ENUM('PENDENTE','PROCESSANDO','CONCLUIDO','ERRO') DEFAULT 'PENDENTE'
cnh_processamento_iniciado_em DATETIME NULL
crlv_status_processamento ENUM('PENDENTE','PROCESSANDO','CONCLUIDO','ERRO') DEFAULT 'PENDENTE'
crlv_processamento_iniciado_em DATETIME NULL

status_processamento = "o backend terminou de tentar" (ortogonal);
origem_validacao (ja existente) = "com que resultado". MANUAL marca
CONCLUIDO direto, sem chamar o VIO.

### Contrato de endpoints (backend-especialista)

- documento.php?acao=iniciar-processamento (novo): valida posse/tipo/
  status/etapa (aceita expedicao E recebimento agora), verifica status
  persistido ANTES de aceitar (nao so lock de curta duracao - replay
  sequencial precisa ser barrado por estado, nao so por lock), transicao
  para PROCESSANDO deve ser ATOMICA (UPDATE...WHERE status IN(...)
  checando affected_rows, nunca SELECT+UPDATE separado - achado do
  security-especialista), chama o VIO DENTRO do mesmo request PHP
  (sincrono do lado do servidor, assincrono so do lado do JS que nao
  usa await bloqueante), grava resultado, libera lock no finally.
- documento.php?acao=status-processamento (novo, leitura pura, barato,
  NUNCA dispara nova chamada ao VIO): usado pelo front para polling
  quando nao ha Promise viva em memoria (ex.: apos reload). Precisa das
  MESMAS 5 validacoes (Auth/posse/tipo/status/etapa) e de RATE LIMIT
  proprio (achado do security-especialista - reaproveitar padrao de
  RateLimitOcrDao), dimensionado para a frequencia natural de polling
  (mais alta que "iniciar", que so acontece 1x por documento).

### Timeout/zumbi (achado CRITICO do security-especialista)

Ao expirar um PROCESSANDO travado e permitir nova tentativa, a tentativa
"zumbi" (requisicao antiga ainda em voo) pode retornar e sobrescrever o
resultado da tentativa nova. Garantia minima necessaria: identificador
de tentativa (token gerado ao entrar em PROCESSANDO, gravado junto do
status) - escrita do resultado so aceita se o token ainda corresponder
ao que esta no banco (UPDATE...WHERE tentativa_id = :token), analogo a
controle de versao otimista. So GET_LOCK (ja liberado nesse ponto) nao
resolve esse cenario especifico. Timeout proposto: 30s (VIO tem timeout
de 20s + margem).

### Desenho de front-end (frontend-especialista)

Mesmo padrao ja validado no OCR de notas fiscais (fire-and-forget + fila/
flag, nunca await bloqueante): captura->upload (unico await)->dispara
validacao em segundo plano SEM await->libera tela seguinte imediatamente.
Indicador discreto e nao bloqueante mostra "Validando CNH em segundo
plano..." na tela do CRLV. So depois do CRLV confirmado,
Promise.all([cnhPromise, crlvPromise]) com timeout de 45s decide: segue
direto (ja terminou), mostra tela de carregamento cheia (nao modal)
exp_aguardando_validacao, ou abre fallback manual direcionado (so CNH,
so CRLV, ou os dois). Se nao houver Promise viva em memoria (reload),
consulta status-processamento via polling de 2s (nunca re-dispara
iniciar-processamento depois de reload - QR bruto nunca e persistido por
decisao de seguranca ja tomada, entao nao ha como reprocessar sem nova
captura). Telas/funcoes NOVAS e dedicadas tambem para Recebimento
(rec_cnh_frente/rec_cnh_verso/rec_crlv, mesmo padrao de duplicacao
deliberada ja aceito para nao cruzar os dois fluxos), reaproveitando so
o que ja e generico (obterQrWorker(), bytesArrayParaBase64()).

### Viabilidade de infraestrutura (devops-especialista)

Confirmado: unica forma valida de "segundo plano" no Hostgator e
client-side (JS nao bloqueante fazendo fetch curto), nunca processo
servidor persistente. Chamada ao VIO permanece sincrona DENTRO do
request PHP (ja e assim, testado, resposta em segundos). Recomendado
confirmar max_execution_time real do plano Hostgator em producao
(pendencia de verificacao pratica, nao suposta) e adicionar isso ao
docs/deploy-checklist.md. Nunca tentar fire-and-forget do lado do
servidor (exec()/fastcgi_finish_request()).

### Cache compartilhado (confirmado por 2 especialistas independentes)

tb_vio_cache_cnh/tb_vio_cache_crlv ja sao agnosticas de tipo de
atendimento (chave e so identificador_qr+ambiente) - CONFIRMADO
formalmente que continuam compartilhadas entre Expedicao e Recebimento
SEM alteracao de schema. O recheque de placa contra o atendimento ATUAL
(ja existente) precisa ser preservado exatamente igual quando chamado a
partir do Recebimento tambem.

### Roteiro de teste planejado (qa-testes)

12 categorias cobrindo: fluxo assincrono ponta-a-ponta nos dois fluxos,
"ambos rapidos" vs "algum pendente", concorrencia CNH/CRLV, cache
compartilhado com recheque de placa por atendimento atual, idempotencia,
timeout/zumbi, retomada apos reload, fallback manual, nao regressao do
OCR de notas, desempenho/fluidez, e quais itens de seguranca ja
validados continuam validos vs precisam de reteste (lock de concorrencia
e retry precisam ser re-testados no novo modelo; IDOR/placeholder/
criptografia/allowlist continuam validos sem mudanca).

### Riscos e pendencias consolidados desta rodada

1. BLOQUEANTE: mudanca de gating em avancarEtapaExpedicao/
   validarAtendimentoParaEtapa (etapa exata -> etapa igual-ou-posterior
   para validar-qr/preencher-manual) precisa ser aprovada antes de
   /01-implementacao - sem isso, requisito de liberar CRLV imediato nao
   e implementavel.
2. Nome exato das novas etapas de Recebimento (rec_cnh_frente/
   rec_cnh_verso/rec_crlv - hoje rec_cnh/rec_crlv existem mas com
   semantica antiga sem VIO) - proposto por simetria com Expedicao, nao
   fechado.
3. DocumentoController generalizado para aceitar tipo='recebimento'
   (hoje hardcoded para expedicao em alguns pontos) - trabalho de
   /01-implementacao.
4. Migration numerada como 007_* - confirmar numero real no momento da
   implementacao (verificar sql/migrations/ para nao colidir).
5. Valor de timeout (30s backend / 45s front-end / 2s polling) sao
   propostas iniciais, nao validadas empiricamente - mesmo padrao de
   "ajuste empirico" ja registrado para outros parametros do projeto.
6. Pendencias ja conhecidas do ciclo sincrono continuam validas sem
   mudanca: correspondencia pratica de jsQR.binaryData com camera real,
   formato de data_validade de CNH real (sem QR de demonstracao real
   disponivel). O descarte explicito do campo image e a rejeicao
   simetrica de placeholder CNH/CRLV JA FORAM RESOLVIDOS na correcao
   anterior - confirmar se seguem valendo apos o novo desenho assincrono.
7. max_execution_time real do Hostgator em producao nao confirmado -
   verificacao pratica pendente, a adicionar em docs/deploy-checklist.md.

### Proximo passo

Apresentar este plano ao usuario para aprovacao explicita dos pontos 1-7
acima (especialmente o item 1, bloqueante) antes de retomar
/01-implementacao. Nenhum codigo foi alterado nesta etapa de
replanejamento.

## Resultado da implementacao assincrona (01-implementacao, retomada)

Data: 2026-09-09

### Backend implementado

- Migration `sql/migrations/007_status_processamento_assincrono_vio.sql`
  (idempotente, testada 2x): `cnh_status_processamento`/
  `crlv_status_processamento` ENUM(PENDENTE/PROCESSANDO/CONCLUIDO/ERRO),
  `*_processamento_iniciado_em`, `*_tentativa_id`, tabela
  `tb_rate_limit_vio_status` (rate limit proprio do polling).
- `AtendimentoDao`: `iniciarProcessamento()` (transicao ATOMICA via
  UPDATE...WHERE status IN(...) + rowCount()), `gravarResultadoProcessamento()`
  (so grava se tentativa_id bater, descarta zumbi silenciosamente),
  `marcarProcessamentoObsoletoComoErro()` (timeout 30s), `marcarProcessamentoConcluido()`.
- `DocumentoController`: `iniciarProcessamento()` (novo, validarQr mantido
  como alias), `statusProcessamento()` (nunca chama VIO, rate limit
  proprio via `RateLimitVioStatusDao`, valida posse, so campos permitidos),
  `upload()` generalizado para Recebimento (etapas separadas
  rec_cnh_frente/rec_cnh_verso, diferente de exp_cnh unica).
- `AtendimentoController`: `avancarEtapaDocumentos()` (maquina de
  estados unificada Expedicao/Recebimento via match() de gates:
  upload_cnh/upload_crlv/ambos_aprovados), allowlist FECHADA de etapas
  por (tipo_atendimento, tipo_documento), nunca comparacao ordinal
  implicita.
- Etapas reais: Expedicao `exp_cnh -> exp_crlv -> exp_aguarde_documentos
  -> exp_confirmacao -> impressao`; Recebimento `rec_cnh_frente ->
  rec_cnh_verso -> rec_crlv -> rec_aguarde_documentos -> rec_confirmacao`.
- Cache `tb_vio_cache_cnh`/`tb_vio_cache_crlv` confirmado compartilhado
  entre os dois fluxos sem alteracao de schema, recheque de placa contra
  atendimento ATUAL preservado em ambos.

### Correcoes aplicadas durante a rodada

1. **Gap de etapa apos identificacao manual de cliente**: `salvarEtapa()`
   case `cliente` nao avancava `etapa_atual` — corrigido, agora avanca
   para `rec_cnh_frente` (mesma proxima etapa do caminho automatico via
   OCR), consistente com `concluirDigitalizacao()`.
2. **IDOR CRITICO**: `salvarEtapa()` casos `confirmacao`/`cliente`/
   `ajudante` nao validavam posse/tipo/status/etapa (permitia sobrescrever
   CPF de motorista/ajudante e forcar transicao de etapa de atendimento
   ALHEIO). Corrigido replicando o padrao ja usado em
   `digitalizacao_notas` — todos os 3 casos agora exigem
   `buscarAtendimentoDoTotem()` + tipo + status + etapa esperada antes de
   gravar qualquer dado.

### Frontend implementado

- Fluxo assincrono real: confirmar CNH -> upload -> `iniciar-processamento`
  SEM await (fire-and-forget, guarda a Promise) -> libera IMEDIATAMENTE
  a tela do CRLV, com indicador discreto "Validando CNH em segundo
  plano..." (nunca modal).
- Apos CRLV confirmado: `exp_aguarde_documentos`/`rec_aguarde_documentos`
  ("Estamos validando seus documentos. Aguarde.") — SO aparece aqui, nao
  entre CNH e CRLV. `Promise.all` com timeout 45s se ha Promise viva;
  polling de 2s em `status-processamento` se nao (reload).
- Resultado: ambos aprovados -> avanca automatico; algum reprovado/erro/
  timeout -> abre manual SO do documento pendente, preservando o
  aprovado.
- Telas novas e dedicadas de Recebimento (`rec_cnh_frente`,
  `rec_cnh_verso`, `rec_crlv`, manuais) sem tocar nas telas antigas de
  notas/OCR/scanner.
- `jsQR` confirmado exclusivo no Web Worker, nunca thread principal.

### Pendencia registrada, nao implementada (fora do escopo autorizado)

Pre-preenchimento do formulario manual com dado sugerido do QR (mesmo
que nao aprovado) nao foi implementado — exigiria campo novo no
contrato de resposta do backend, que o frontend-especialista nao
inventou por nao ter sido confirmado explicitamente. Formularios
manuais continuam abrindo em branco.

### Achado nao relacionado a esta demanda (fora do escopo, apenas registrado)

`TalentRn::montarAnexos()` procura `cnh.jpg`, mas o upload sempre salva
`cnh_frente.jpg`/`cnh_verso.jpg` — o anexo de CNH nunca e encontrado no
envio ao Talent (falha silenciosa, nao trava o envio, confirmado pelo
security-especialista como bug funcional sem risco de seguranca). Nao
corrigido, decisao futura do usuario.

### Testes executados (execucao real, nao so leitura)

Suite completa reexecutada de forma independente por 2 agentes (backend
e qa-testes), 100% dos resultados batendo:
- `teste_vio_decode.php`: 21/21
- `teste_avancar_etapa_expedicao.php`: 10/10
- `teste_concorrencia_processamento_vio.php`: 16/16
- `teste_concorrencia_real_iniciar_processamento.php`: 2/2 (2 processos
  reais simultaneos contra o Trial VIVO do Serpro)
- `teste_status_processamento.php`: 9/9
- `teste_fluxo_recebimento_documentos.php`: 11/11
- `teste_rebaixamento_manual.php`: 39/39
- `teste_identificar_cliente.php`: 35/35
- `teste_salvar_etapa_cliente_manual.php`: 3/3
- `teste_idor_salvar_etapa_manual.php` (novo, pos-correcao IDOR): 15/15

Mais testes independentes criados pelo qa-testes nesta rodada (scratchpad,
nao versionados): concorrencia com 3 subprocessos reais (5/5), timeout/
tentativa zumbi (9/9), e2e CNH aprovada + CRLV reprovado em ambos os
fluxos (22/22), allowlist de etapas (16/16), IDOR nos novos endpoints
(6/6). **Total: mais de 200 asserts, 0 falha, 0 residuo de dado de
teste.**

`php -l` sem erro em 31+ arquivos PHP. `node --check` sem erro em
`app.js`/`qr-worker.js`/`jsQR.js`.

### Revisao de seguranca final

Sem achado critico remanescente apos a correcao do IDOR. Confirmado:
concorrencia/atomicidade correta, tentativa zumbi descartada
corretamente, timeout de 30s funcional, endpoint de status nunca chama
o VIO e tem rate limit proprio, allowlist fechada de etapas, IDOR
corrigido nos 3 casos criticos E confirmado ja correto nos novos
endpoints (upload/iniciar-processamento/status-processamento/
avancar-etapa-documentos), cache compartilhado seguro, criptografia e
fail-closed do VioDecodeClient intactos. Achado de atencao remanescente
(nao bloqueante, ja conhecido): lock GET_LOCK nao liberado
explicitamente em 2 caminhos de erro por causa de exit() em
Resposta::erro() — mitigado hoje pela conexao nao-persistente,
documentado como divida tecnica de infraestrutura.

### Confirmacao de escopo

Nenhuma alteracao em notas fiscais, OCR de clientes, Talent (exceto o
achado ja documentado, nao corrigido), ordem de coleta. Recebimento
confirmado sem `ordem_coleta` (comportamento preservado). Nenhum
commit/push realizado.

### Proximo passo

Pronto para `/02-testes` formal (fisico, quando hardware disponivel) e
`/03-revisao`. Pendencias remanescentes documentadas na secao anterior
do handoff continuam validas (jsQR.binaryData com camera real, formato
de data_validade de CNH real, max_execution_time em producao,
pre-preenchimento do manual, TalentRn::montarAnexos()).

## Atualização (2026-09-09) — impacto da integração Talent no CRLV

Durante o planejamento da demanda `integracao-talent-portaria-checkin`,
foi confirmado que o payload do Talent (`POST /Portaria/Checkin`) exige
`veiculo.uf`. Isso impacta diretamente o fluxo de CRLV desta demanda:

- **`uf` (obrigatório para Talent):** será extraído do campo `data.uf` já
  presente na resposta do VIO Decode para CRLV (campo já mapeado nas
  28 chaves confirmadas, mas até agora não persistido). Passa a ser
  adicionado à allowlist `CAMPOS_PERMITIDOS_CRLV` em `DocumentoRn`,
  persistido em `tb_atendimento.crlv_uf` e `tb_atendimento.crlv_snapshot_uf`
  (novas colunas planejadas), e em `tb_vio_cache_crlv.uf` (nova coluna
  planejada). Quando o CRLV for preenchido manualmente, a seleção de UF
  passa a ser obrigatória via lista fechada de 27 UFs (nunca texto livre),
  mudança de front-end a implementar junto com o backend. Editar o CRLV
  depois de aprovado automaticamente continua rebaixando para MANUAL,
  segundo a regra já vigente nesta demanda.
- **`nrCNH` e `categoriaCNH`:** confirmados como **opcionais** no manual
  real do Talent (não obrigatórios). Decisão registrada nesta rodada:
  **omitir esses dois campos do payload nesta primeira versão da
  integração Talent**, sem nenhuma mudança na captura/allowlist de CNH
  desta demanda. Fica registrada como sugestão de melhoria futura (não
  implementada, não solicitada) a possibilidade de futuramente também
  capturar `nrCNH`/`categoriaCNH` do VIO Decode, caso o produto decida
  enviar esses campos opcionais ao Talent.

Nenhuma mudança de código foi feita nesta atualização — é só o registro
da decisão de escopo entre as duas demandas. A implementação de `uf` será
feita como parte do `/01-implementacao` da demanda Talent (arquivos:
`app/Rn/DocumentoRn.php`, `app/Dao/VioCacheDao.php`, `sql/schema.sql`,
nova migration `008_talent_checkin_uf_idempotencia.sql`), não como
retrabalho desta demanda já commitada/testada.
