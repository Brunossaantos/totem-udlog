# db_gestao_coletas — API de Ordens de Coleta e Clientes

> Cole aqui a documentação oficial dessa API. Enquanto este arquivo não
> tiver os dados reais preenchidos abaixo, `app/Rn/OrdemColetaClient.php`
> continua com o formato-placeholder e nenhum sub-agente deve tratar esse
> placeholder como contrato confirmado.

## URL base

<!-- ex: https://gestaocoletas.udlog.com.br/api -->

## Autenticação

<!-- tipo (Bearer token, API key, Basic), header exato, onde obter a credencial -->

## Endpoint: buscar ordens de coleta por placa

- Método e caminho:
- Parâmetros:
- Exemplo de resposta (JSON real, não inventado):
- Campo de status e valores possíveis (ex: aberto/concluido/cancelado — confirmar nomenclatura exata):

## Endpoint: clientes — CONFIRMADO em 2026-09-04 (API separada, ver nota)

> Nota importante: esta API ("API Clientes", versao 2.0.0, servida em
> `https://udlog.online/iaUdlog/api/v1/`) respondeu confirmando ser um
> servico dedicado de clientes, distinto da API de ordens de coleta por
> placa (secao acima, que continua sem contrato confirmado). Nao presumir
> que sao o mesmo sistema/mesma base — confirmado apenas que ambas
> existem como fontes possiveis para `OrdemColetaClient`/identificacao de
> cliente, tratadas separadamente.

- **URL base**: `https://udlog.online/iaUdlog/api/v1/`
- **Autenticacao**: `Authorization: Bearer <token>` obrigatorio em todas as
  rotas sob `/clientes` (inclusive `/health` e `/docs` sob esse path). Sem
  token: HTTP 401 `{"sucesso":false,"erro":{"mensagem":"Token nao
  informado...","codigo":"TOKEN_REQUIRED"}}`. Com token invalido:
  HTTP 401 `{"codigo":"INVALID_TOKEN"}`. Um token valido foi fornecido
  pelo usuario em 2026-09-04 e testado com sucesso (GETs seguros, sem
  alteracao de dados) — **o valor do token nao esta registrado neste
  arquivo nem em nenhum outro arquivo versionado**; deve ser armazenado
  apenas em `.env` local/producao como nova variavel (ex:
  `CLIENTES_API_TOKEN`), nunca commitado.
- **Endpoint de listagem**: `GET /api/v1/clientes` — retorna lista
  paginada de todos os clientes ativos/cadastrados.
  - Parametros de query confirmados por teste real:
    - `cnpj` (string, 14 digitos, sem mascara) — filtra por CNPJ exato.
      Se nao encontrar, retorna `dados: []` (nao e erro 404, e sucesso
      com lista vazia). CNPJ invalido (nao numerico) tambem so retorna
      lista vazia, sem validacao de formato do lado da API.
    - `pagina` (int, 1-based) — pagina atual.
    - `por_pagina` (int) — tamanho de pagina customizavel (testado com 5,
      20 padrao).
    - Nao ha parametro de busca textual por razao social confirmado — um
      parametro `busca=<termo>` testado foi IGNORADO silenciosamente
      (retornou a listagem completa paginada, sem filtrar) — ou seja,
      **nao existe busca fuzzy/textual do lado da API**; qualquer
      comparacao de razao social/aliases precisa ser feita do lado do
      totem, trazendo a listagem completa (paginada) e comparando
      localmente.
  - **Estrutura de resposta confirmada** (nomes de campo reais, SEM dados
    reais de cliente — valores abaixo sao ilustrativos):
    ```json
    {
        "sucesso": true,
        "dados": [
            {
                "id": "<int>",
                "razao_social": "<string>",
                "cnpj": "<string, 14 digitos sem mascara>",
                "email": "<string>",
                "status": "<string, ex: ATIVO>",
                "criado_em": "<datetime>",
                "atualizado_em": "<datetime>"
            }
        ],
        "meta": {
            "pagina_atual": "<int>",
            "por_pagina": "<int>",
            "total_registros": "<int>",
            "total_paginas": "<int>"
        }
    }
    ```
    **Campos confirmados que NAO existem** nesta resposta: `nome_fantasia`,
    `aliases`/apelidos, ID externo de outro sistema. So ha um unico campo
    de nome (`razao_social`) — qualquer necessidade de nome fantasia/alias
    para matching fuzzy precisaria vir de outra fonte (ex: cadastro
    complementar local em `tb_cliente`), nao desta API.
  - Outros valores de `status` alem de `ATIVO` nao foram observados (só
    testado com registros ja existentes, todos ativos) — nao confirmado
    se ha `INATIVO`/outro valor.
- **Endpoint de detalhe**: `GET /api/v1/clientes/{id}` — retorna um unico
  objeto (mesma estrutura de item da listagem, sem a chave `dados` ser
  array) para o ID interno da API. **Nao testado se {id} aceita CNPJ
  diretamente na URL** (so testado com o ID numerico interno) — usar
  `?cnpj=` na listagem e o caminho confirmado para busca por CNPJ.
- **Limites**: header `X-RateLimit-Limit: 60` observado, com
  `X-RateLimit-Remaining` decrescendo a cada chamada — sugere 60
  requisicoes por alguma janela de tempo nao confirmada explicitamente
  (provavelmente por minuto, padrao comum, mas nao documentado
  explicitamente pela API — nao presumir o valor exato da janela).
- **Timeout recomendado**: nao documentado pela API; requisicoes de teste
  responderam em bem menos de 1s.
- Nao ha endpoint de sincronizacao incremental (ex: "desde data X")
  confirmado — so paginacao simples da listagem completa.

## Limites e observações

<!-- rate limit, timeout recomendado, ambiente de teste/sandbox se houver -->
