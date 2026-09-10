# API VIO DECODE (Serpro) — documentacao confirmada

> Fonte: documentacao tecnica oficial publicada em
> https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/
> (paginas: quick_start, integrando_vio_decoder, reference, exemplos,
> codigos_retorno). Consultada via WebFetch em 2026-09-08 pelo
> ui-ux-especialista (unico sub-agente com acesso a busca web neste
> projeto). Tudo abaixo e fato documentado, nada inventado. Itens nao
> confirmados estao marcados explicitamente como tal.

## Nome oficial

API VIO DECODE (tambem referida como "Vio Decodificador"). Documentacao
oficial: https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/

## Ambientes

Dois ambientes, diferenciados por URL base:

- **Trial**: `https://gateway.apiserpro.serpro.gov.br/viodec-trial/v1/decode`
- **Producao**: `https://gateway.apiserpro.serpro.gov.br/viodec/v1/decode`

## Autenticacao

OAuth2, fluxo `client_credentials`:
1. Concatenar `Consumer Key:Consumer Secret`.
2. Codificar em Base64.
3. POST ao endpoint de token com `grant_type=client_credentials`.
4. Receber `access_token` (Bearer), usado no header `Authorization` das
   chamadas de decode.

Credenciais (`consumer key`/`consumer secret`) sao obtidas na area do
cliente Serpro (fora do escopo tecnico deste documento).

## CRITICO — Trial e ambiente de dados MOCK

Citacao literal da documentacao oficial: *"API VIO DECODE Demonstracao e
o ambiente de testes da API VIO DECODE, com dados de exemplo (Mock)."*
O quick start orienta usar QR Codes de exemplo fornecidos pelo proprio
Serpro no ambiente Trial.

**Nao ha confirmacao explicita e categorica de que QR Codes reais de
CNH/CRLV NAO funcionem no Trial** — a documentacao nao nega
tecnicamente essa possibilidade, mas descreve o ambiente como sendo de
dados mock por natureza/proposito. Tratar como: **o Trial valida a
integracao tecnica (formato de request/response, autenticacao, fluxo),
NAO a autenticidade de um documento real** — isso so e possivel em
Producao, apos contratacao.

## Formato de entrada

A API espera os **dados brutos (raw value) extraidos do QR Code** — nao
uma imagem para decodificar, nem o texto ja interpretado por uma lib
generica de QR. E o payload bruto lido do QR, com metadados de
tamanho/recuperacao de erro do padrao QR removidos, codificacao
ISO-8859-1.

Implicacao arquitetural: o totem precisa de uma biblioteca/mecanismo que
extraia esse "raw value" do QR (nao basta decodificar o QR como texto
simples via lib generica que so devolve string UTF-8 do conteudo visivel
— precisa preservar os bytes brutos do payload).

### Formato do corpo HTTP (CONFIRMADO por teste real em 2026-09-08)

O corpo da requisicao `POST` e os **bytes binarios puros** do raw value do
QR — **nao** e JSON, **nao** e base64, **nao** e hex, **nao** e
`multipart/form-data`. Envelopar os bytes em `{"raw_value": "..."}` (o que
a implementacao original fazia) retorna `415 Unsupported Media Type`.

Headers exatos confirmados (testados com os arquivos oficiais de
demonstracao do Serpro — `qrcode-trial.bin` e `crlv-demo.bin`, ambos com
HTTP 200):

```
Accept: application/json
Content-Type: application/octet-stream
Authorization: Bearer <token>
Content-Length: <tamanho real em bytes>
```

## Estrutura de resposta — envelope confirmado por teste real (2026-09-08)

A resposta `200 OK` (testada com `qrcode-trial.bin` e `crlv-demo.bin`) NAO
e um objeto achatado com os campos do documento na raiz — os campos vem
dentro de um envelope, no formato:

```json
{
  "template": { "name": "...", "owner": { "name": "..." } },
  "data": { /* campos do documento, ver abaixo */ },
  "image": { "base64": "...", "type": "..." }
}
```

`image` so aparece em alguns templates (confirmado presente no template do
QR de demonstracao generico do Trial, `qrcode-trial.bin` — nao confirmado
se aparece para CNH/CRLV reais). Esta descoberta corrige a suposicao
anterior deste documento (campos do CNH/CRLV na raiz da resposta) — os
campos abaixo estao todos dentro de `data`.

## Estrutura de resposta — CNH (campos dentro de `data`)

Campos confirmados (todos como string, no formato codificado no QR):
`nome`, `nome_civil`, `identidade`, `cpf`, `data_nascimento`,
`filiacao_pai`, `filiacao_mae`, `permissao`, `acc`, `categoria`,
`numero_registro`, `data_validade`, `data_primeira_habilitacao`,
`observacoes`, `local_emissao`, `uf_emissao`, `data_emissao`,
`numero_validacao_cnh`, `numero_renach`.

Nao validado com um QR de CNH real neste teste (o Trial nao possui QR de
demonstracao de CNH — `qrcode-trial.bin` e um cracha generico, template
com campos `apelido`, `nome_completo`, `matricula`, `data_admissao`, sem
nenhum campo de CNH). O teste real confirmou apenas o TRANSPORTE (HTTP
200, envelope `template`/`data`/`image`), nao a lista de campos de CNH
acima.

## Estrutura de resposta — CRLV (campos dentro de `data`)

Campos confirmados: `codigo_seguranca_cla`, `numero_crv`, `uf`,
`renavam`, `rntrc`, `exercicio`, `nome`, `cpf_cnpj`, `placa`, `chassi`,
`especie`, `tipo`, `carroceria`, `combustivel`, `ano_fabricacao`,
`ano_modelo`, `marca_modelo`, `lotacao`, `potencia`, `cilindradas`,
`categoria`, `cor`, `motor`, `capacidade_maxima_carga`, `pbt`, `cmt`,
`eixos`, `local`, `data`, `observacoes`.

Confirmado por teste real com `crlv-demo.bin` (Trial): `data.placa` e
`data.exercicio` presentes, mas com valor **placeholder literal
`"xxxxx"`** (mock do ambiente Trial) — nunca deve ser tratado como CRLV
real aprovado, mesmo com HTTP 200 (`App\Rn\DocumentoRn` rejeita
explicitamente esse padrao).

## Codigos de retorno

- `200 OK` — validacao realizada com sucesso.
- `400 Bad Request` — dados invalidos na requisicao.
- `422 Unprocessable Entity` — QR nao pode ser processado, com codigos
  especificos no corpo:
  - `VD001` — QRCode nao e compativel com padrao VIO
  - `VD002` — falha na verificacao de assinatura
  - `VD003` — assinatura nao reconhecida
  - `VD004` — modelo de documento incompativel
- Nota de cobranca: apenas respostas `200` e `422` entram no calculo de
  faturamento (relevante para estimativa de custo futura, nao urgente
  nesta etapa de prova tecnica).

## Endpoint OAuth2 de token — CONFIRMADO por teste real

Endpoint: `POST https://gateway.apiserpro.serpro.gov.br/token`

Confirmado pelo usuario via teste HTTP real (nao via documentacao
estatica) em 2026-09-08: o servidor Serpro respondeu normalmente,
identificando-se como `WSO2 Carbon Server` (plataforma de API
management comum em gateways OAuth2 corporativos, coerente com o resto
do dominio `apiserpro.serpro.gov.br`). A chamada de teste foi feita
SEM Consumer Key/Secret (so `grant_type=client_credentials` no corpo,
sem header `Authorization`), e o servidor respondeu:

- HTTP `401 Unauthorized`
- Corpo: erro `invalid_client`, mensagem "Unsupported Client
  Authentication Method"
- Header `WWW-Authenticate: Basic realm=controlplane.apiserpro.serpro.gov.br`

Esse resultado e o ESPERADO para uma chamada sem credenciais e CONFIRMA
duas coisas tecnicamente relevantes:
1. **A URL do endpoint esta correta e acessivel** — um endpoint
   inexistente responderia 404, nao um erro estruturado de OAuth2.
2. **O metodo de autenticacao exigido e HTTP Basic Auth no header
   `Authorization`** (nao apenas `client_id`/`client_secret` no corpo
   da requisicao) — coerente com o fluxo ja descrito na documentacao
   oficial ("concatenar Consumer Key:Consumer Secret, codificar em
   Base64"): esse valor Base64 deve ir no header
   `Authorization: Basic <base64>`, nao em campos separados do corpo.
   Esta e uma confirmacao pratica que refina o entendimento do fluxo
   documentado.

Nao foram registrados aqui cookies de sessao nem o dump completo dos
headers de resposta do teste (irrelevantes para a integracao e
descartados por precaucao).

`VIO_TOKEN_URL` continua configuravel via `.env` (nao hardcoded no
codigo) — decisao mantida mesmo apos a confirmacao, por ser boa pratica
de configuracao (facilita ajuste se a Serpro mudar a URL no futuro),
nao por duvida sobre a URL em si.


## Bearer publico do ambiente Trial — CONFIRMADO no Swagger oficial

Fonte oficial: `https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/swagger/`

O usuario localizou e confirmou, diretamente no Swagger oficial da API
VIO Decode, que o ambiente Trial disponibiliza um **Bearer token PUBLICO
e compartilhado**, pronto para uso imediato, dispensando o fluxo
completo de OAuth2 client_credentials (Consumer Key/Secret) SOMENTE
para o Trial.

**O VALOR LITERAL DESSE TOKEN NAO ESTA E NUNCA DEVE SER REGISTRADO**
neste documento, em nenhum outro arquivo de documentacao, em codigo,
em teste, nem em log — nem mesmo como exemplo. Isso vale mesmo em fase
de planejamento, antes de qualquer implementacao. Ele existe apenas
como valor de configuracao a ser preenchido diretamente no `.env` do
ambiente onde a prova tecnica rodar, nunca commitado, nunca hardcoded.

Caracteristicas confirmadas (do proprio Swagger oficial, sem incluir o
valor):
- E **exclusivo do ambiente Trial** — nao funciona nem deve ser usado
  contra a URL de Producao.
- **Pode ser alterado ou revogado pelo Serpro a qualquer momento**, sem
  aviso previo — nao deve ser tratado como uma credencial estavel de
  longo prazo; se parar de funcionar, o primeiro passo de diagnostico e
  verificar se o Swagger oficial ainda lista o mesmo valor.
- **Deve ser usado SOMENTE com os QR Codes de demonstracao** fornecidos
  pelo proprio Serpro — reforca o que a documentacao ja dizia sobre o
  Trial ser ambiente de dados mock (secao acima). Usar esse Bearer nao
  muda o fato de que **o Trial valida a integracao TECNICA (formato,
  autenticacao, parsing da resposta), NUNCA comprova a autenticidade de
  um documento real** — essa e uma limitacao do proprio ambiente, nao
  do metodo de autenticacao usado.

Com essa confirmacao, o fluxo de autenticacao para a prova tecnica em
Trial fica mais simples do que o presumido anteriormente (nao e mais
necessario obter Consumer Key/Secret proprios so para rodar a prova
tecnica no Trial): basta usar o Bearer publico diretamente no header
`Authorization` das chamadas ao endpoint de decode do Trial. O fluxo
OAuth2 completo (obtencao de token via Consumer Key/Secret com HTTP
Basic Auth) continua sendo exigido apenas para o ambiente de PRODUCAO
(nao tocado nesta etapa de prova tecnica).

## NAO CONFIRMADO (pendencia — nao presumir)

- **Rate limit e timeout recomendado**: nao localizado em nenhuma pagina
  de documentacao tecnica acessada.
- **Custo por consulta / exigencia de convenio ou contrato formal para
  producao**: nao confirmado — paginas comerciais (`serpro.gov.br`,
  `loja.serpro.gov.br`) retornaram 403/404 ao acesso automatizado.
  Requer contato comercial direto ou acesso a area do cliente Serpro
  antes de qualquer decisao de contratacao (fora do escopo desta etapa,
  que e so prova tecnica em Trial).

## Fontes consultadas (URLs exatas)

- https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/
- https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/quick_start/
- https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/integrando_vio_decoder/
- https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/reference/
- https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/exemplos/
- https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/busca_documentos/
- https://apicenter.estaleiro.serpro.gov.br/documentacao/vio-decode/pt/codigos_retorno/
