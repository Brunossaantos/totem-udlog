# API Talent Cloud Service (WMS) — contrato confirmado

> Fonte: MANUAL_TALENT_WMS.pdf ("Manual de Orientacao para Consumo de
> Webservice de Integracao — Operacoes WMS"), lido integralmente pelo
> orquestrador em 2026-09-09. Tudo abaixo e fato documentado no PDF
> oficial, nada inventado. Itens nao confirmados estao marcados
> explicitamente.

## Padroes tecnicos gerais

- Base URL: `https://api.talentcs.com.br`
- Autenticacao: header `Authorization: Bearer <token>` (token fornecido
  pela Talent ao parceiro)
- Formato: `application/json; charset=utf-8`
- Timestamp: UTC, formato `AAAA-MM-DDThh:mm:ssTZD`
- Protocolo: HTTPS, porta 443
- Swagger UI: `https://api.talentcs.com.br`

Codigos HTTP documentados:
- `200 OK` — sucesso
- `201 Created` — objeto criado com sucesso
- `400 Bad Request` — erro de validacao no input
- `401 Unauthorized` — token invalido/ausente
- `404 Not Found` — recurso nao encontrado
- `409 Conflict` — violacao de regra de negocio
- `500 Internal Server Error` — erro inesperado

## Endpoint usado pelo totem: Portaria - Checkin

**URL**: `POST https://api.talentcs.com.br/Portaria/Checkin`

**Funcao**: cadastro de check-in de motoristas na portaria do armazem —
este e o endpoint que o totem-udlog deve usar (decisao confirmada pelo
usuario em 2026-09-09, abandonando o contrato placeholder anterior
`/atendimentos`, que nunca existiu no manual oficial).

### Payload de entrada

```json
{
  "cnpjArmazem": "string",
  "cnpjDepositante": "string",
  "tipoEmbDesemb": "Embarque",
  "veiculo": { "placa": "string", "uf": "string", "rntc": "string", "tipo": "string", "anoLicenciamento": 0 },
  "reboque": { "placa": "string", "uf": "string", "rntc": "string", "tipo": "string", "anoLicenciamento": 0 },
  "exigePesagem": true,
  "cnpjTransportadora": "string",
  "nomeTransportadora": "string",
  "motorista": { "cpf": "string", "nome": "string", "celularDDD": "string", "celularNumero": "string", "nrCNH": "string", "categoriaCNH": "string", "validadeCNH": "string" },
  "ajudantes": [ { "cpf": "string", "nome": "string", "celularDDD": "string", "celularNumero": "string", "nrCNH": "string", "categoriaCNH": "string", "validadeCNH": "string" } ],
  "temPernoite": true,
  "doctos": [ { "tipo": "AR", "nrDocto": "string" } ],
  "paletes": [ { "tipo": "string", "qtd": 0 } ],
  "nrContainer": "string",
  "lacreContainer": "string",
  "delivery": "string",
  "obs": "string",
  "anexos": [ { "anexoBase64": "string", "descricao": "string" } ]
}
```

### Campos obrigatorios (confirmados no manual, Ocor. 1-1)

- `cnpjArmazem` (14 chars) — CNPJ do armazem/filial no sistema Talent
- `cnpjDepositante` (14 chars) — CNPJ do depositante/cliente
- `tipoEmbDesemb` — valores possiveis: `"Embarque"` ou `"Desembarque"`
- `veiculo` (grupo obrigatorio), com `placa` (7 chars) e `uf` (2 chars)
  obrigatorios dentro do grupo
- `motorista` (grupo obrigatorio), com `cpf` (11 chars) e `nome` (60
  chars) obrigatorios dentro do grupo
- `doctos` (lista, 1-N, obrigatoria), com `tipo` e `nrDocto`
  obrigatorios em cada item

### Campos opcionais confirmados

`reboque` (mesma estrutura de `veiculo`), `rntc`/`tipo`/
`anoLicenciamento` dentro de veiculo/reboque, `exigePesagem` (bool,
default false), `cnpjTransportadora`, `nomeTransportadora`,
`celularDDD`/`celularNumero`/`nrCNH`/`categoriaCNH`/`validadeCNH` dentro
de motorista/ajudantes, `ajudantes` (lista 0-N), `temPernoite` (bool,
default false), `paletes` (lista 0-N), `nrContainer`, `lacreContainer`,
`delivery`, `obs` (250 chars), `anexos` (lista 0-N).

### Valores de `doctos.tipo` confirmados no manual

`AR`, `APONTAMENTO`, `NOTA_FISCAL`, `ORDEM_COLETA`. O manual NAO detalha
a semantica exata de `nrDocto` para cada tipo (ex.: se `NOTA_FISCAL`
espera o numero curto da nota ou a chave de acesso completa de 44
digitos) — **pendencia nao confirmada**.

### `validadeCNH`

Formato `dd/mm/aaaa` (confirmado no manual, diferente do formato UTC
usado em outros campos de data do resto da API).

### Anexos

```json
"anexos": [ { "anexoBase64": "string", "descricao": "string" } ]
```

Estrutura simples: `anexoBase64` (base64, sem indicacao de gzip — este
endpoint NAO usa o padrao `anexosGZip` de outros endpoints da mesma API,
como Inbound/Outbound) e `descricao` (texto livre, 100 chars, sem enum
documentado). **Pendencia nao confirmada**: o manual nao especifica se
`anexoBase64` deve ou nao conter o prefixo `data:image/jpeg;base64,` —
nao presumir, so testar/confirmar na implementacao real.

### Retorno de sucesso

**NAO DOCUMENTADO no manual para este endpoint especifico.** Diferente
de outros endpoints (Inbound, Outbound), que tem "Leiaute Mensagem de
Retorno" explicito, a secao 3.3 (Portaria - Checkin) do manual nao
inclui um exemplo de payload de resposta. Nao presumir formato de senha/
protocolo ate confirmacao real (teste contra o endpoint, ou documentacao
adicional do Talent).

### Retorno de erro

Nao ha payload de erro detalhado documentado para este endpoint alem
dos codigos HTTP genericos da secao "Padroes Tecnicos" (400/401/404/
409/500). Nao presumir formato de corpo de erro.

## Outros endpoints do manual (nao usados pelo totem-udlog, registrados por completude)

- `POST /Inbound` — cadastro de NFe/Entrada Manual (usa `anexosGZip`,
  formato `{nome, valueBase64}`, compactado em gzip)
- `POST /Outbound` — cadastro de Autorizacao de Retirada (AR) (usa
  `anexosGZip` tambem)
- `POST /Outbound/NFe` — importacao de NFe de venda
- Metodos GET (Armazens, Produtos, Posicao de Estoque, Movimentacao de
  Estoque, Saida-Status, Portaria-Estatisticas, Mapa Metragem) — usam
  paginacao via headers `x-pageNumber`/`x-pageSize`, cache de 3 minutos
  do lado do Talent.

Nenhum desses e usado pelo fluxo do totem — registrados so para
referencia caso surja necessidade futura.

## Pendencias nao confirmadas (nao presumir, nao inventar)

1. Formato do retorno de sucesso de `Portaria/Checkin` (campos de
   senha/protocolo/identificador do check-in).
2. Formato do corpo de erro (alem do codigo HTTP).
3. Prefixo `data:image/...;base64,` em `anexoBase64` — sim ou nao.
4. Semantica exata de `nrDocto` por tipo de `docto` (especialmente
   `NOTA_FISCAL`: numero curto vs. chave de acesso completa).
5. Se existe ambiente de teste/homologacao separado de producao (nao
   documentado no manual, diferente do padrao Trial/Producao ja visto
   na API VIO Decode).
6. Timeout recomendado (nao documentado).
7. Se a API suporta deduplicacao/idempotencia por alguma chave enviada
   no payload (nao documentado — nenhum campo de idempotency key visivel
   no leiaute).
8. Qual CNPJ exato corresponde a `cnpjArmazem` no contexto da UDLOG
   (nao confirmado se sao as constantes `CNPJ_UDLOG_1`/`CNPJ_UDLOG_2`
   ja usadas em `util/CnpjValidador.php` para outro proposito).
9. Se `cliente_cnpj` do atendimento e de fato o `cnpjDepositante`
   esperado pelo Talent (suposicao razoavel, nao confirmada).
10. Mapeamento `tipoEmbDesemb`: `expedicao`→`"Embarque"` e
    `recebimento`→`"Desembarque"` e suposicao logica pelo nome, nao
    uma correspondencia literal confirmada no manual.

## Payload minimo planejado pelo totem-udlog (2026-09-09)

Campos OBRIGATORIOS que o totem vai enviar (os 7 grupos marcados 1-1
no manual): `cnpjArmazem`, `cnpjDepositante`, `tipoEmbDesemb`,
`veiculo.placa`, `veiculo.uf`, `motorista.cpf`, `motorista.nome`,
`doctos[]`.

Origem de cada um (ver handoff `2026-09-09-integracao-talent-portaria-checkin.md`
para a tabela completa e as pendencias de confirmacao):
- `veiculo.uf`: obtido do campo `data.uf` da resposta VIO Decode para
  CRLV (campo ja confirmado existir na resposta real, ver
  `docs/manual_vio_decode.md`), ou preenchimento manual obrigatorio
  (dropdown fechado de 27 UFs) quando o CRLV nao for validado pelo VIO.
- Demais campos obrigatorios: ja mapeados na tabela do handoff.

Campos OPCIONAIS sem fonte de captura no totem hoje (reboque,
exigePesagem, transportadora, telefones, nrCNH/categoriaCNH, pernoite,
paletes, container/lacre/delivery, obs) ficam DE FORA do payload —
nunca enviados como `null`/string vazia/placeholder.

`nrCNH`/`categoriaCNH`: confirmados como opcionais no manual. Decisao
desta rodada: OMITIR (sem fonte persistida hoje) — capturar isso exigiria
escopo adicional (nova allowlist na VIO, novas colunas, novo campo no
formulario manual) nao pedido explicitamente nesta demanda. Registrado
como sugestao de melhoria futura, nao implementado por conta propria.

`doctos[].nrDocto` para `NOTA_FISCAL`: recomendacao provisoria (NAO
confirmacao de contrato) — usar `chave_acesso` (44 digitos, ja validada
localmente) em vez do numero curto da nota, por ser identificador mais
completo e sem ambiguidade entre emitentes. Sujeito a correcao se o
Talent esperar outro formato.

Anexos: base64 PURO (sem prefixo `data:image/...;base64,`) — decisao
provisoria por ausencia de indicacao em contrario no manual, NAO e
confirmacao formal.

## Pendencias — status apos refinamento de 2026-09-09

Classificacao de cada pendencia (RESOLVIDA / NAO APLICAVEL / BLOQUEADA
EXTERNAMENTE) esta registrada em detalhe no handoff
`docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`,
secao "Fechamento das 18 pendencias". Resumo:
- RESOLVIDAS (com plano, nao implementadas ainda): UF do veiculo, IDOR
  em finalizar(), log com corpo bruto de erro, cenario "Talent aceita
  mas gravacao local falha".
- NAO APLICAVEIS (campo opcional omitido): anoLicenciamento, demais
  campos sem origem, multiplos ajudantes.
- BLOQUEADAS EXTERNAMENTE (exigem Talent/credencial/teste real):
  formato de retorno de sucesso/erro, cnpjArmazem, equivalencia
  cnpjDepositante=cliente_cnpj, mapeamento tipoEmbDesemb, prefixo do
  base64, semantica de nrDocto, significado de HTTP 409,
  OrdemColetaClient (herdada), idempotencia do lado do Talent,
  ambiente de teste/homologacao.

## REFINAMENTO 2 (2026-09-09) — decisoes de producao, empresa/armazem, anexos em PDF e 409

Estas sao decisoes de produto/arquitetura tomadas explicitamente pelo
usuario nesta rodada, substituindo suposicoes provisorias anteriores.
Nada disto foi implementado ainda.

### Ambiente de teste

Confirmado pelo usuario: o Talent **nao possui ambiente de homologacao**.
Teste controlado sera feito diretamente em **Producao**, com autorizacao
explicita, seguindo protocolo (ver handoff, secao "Protocolo de teste
controlado em Producao"). O token de acesso ja esta configurado em
`.env` (chaves `TALENT_API_URL`/`TALENT_API_KEY`, confirmadas existentes
e vazias no `.env.example`; existencia das mesmas chaves no `.env` real
confirmada sem revelar valor). Nenhuma chamada real feita durante o
planejamento.

### `cnpjArmazem` — fonte agora confirmada por decisao de produto

`cnpjArmazem` passa a vir **exclusivamente do totem autenticado**
(nunca do frontend), via nova tabela `tb_empresa` associada a `tb_totem`
por chave estrangeira. Duas empresas a cadastrar:

| Empresa | CNPJ |
|---|---|
| Maua I | `14706199000182` |
| Maua II | `14706199000344` |

**Vinculo do totem de teste a Maua I: BLOQUEADO ATE CONFIRMACAO.** O
`explorer` confirmou que o mecanismo de identificacao de totem existe
(`tb_totem.token_api` unico, `util/Auth.php` retorna a linha completa do
totem autenticado), mas qual linha especifica de `tb_totem` corresponde
ao "totem de teste" mencionado nao esta documentado em nenhum lugar do
projeto — depende de consulta direta ao banco (`codigo`/`nome` reais
cadastrados) ou de confirmacao explicita do usuario. Conforme instrucao
recebida ("se o totem de teste nao puder ser identificado com certeza,
parar antes de vincula-lo"), este vinculo especifico NAO sera decidido
por suposicao. A estrutura (tabela `tb_empresa`, FK em `tb_totem`,
insercao das duas empresas) pode ser planejada e implementada
normalmente; so o `UPDATE` que vincula o totem de teste especifico a
Maua I fica pendente dessa confirmacao.

Totens sem empresa vinculada devem falhar de forma explicita (erro
tecnico claro, nao suposicao de empresa default) ate serem configurados.

### `tipoEmbDesemb` — literais confirmados por decisao de produto

Decisao (nao mais suposicao provisoria): enviar em minusculas.

```
expedicao   -> "embarque"
recebimento -> "desembarque"
```

Substitui a suposicao anterior (`"Embarque"`/`"Desembarque"` capitalizados).

### `cnpjDepositante` — origem confirmada por decisao de produto

Decisao (nao mais suposicao provisoria): usar `tb_atendimento.cliente_cnpj`
tal como identificado no atendimento — via `OrdemColetaClient` na
Expedicao (ainda placeholder, ver pendencia externa abaixo), ou via
identificacao por notas/`tb_cliente` no Recebimento (ja implementado).
Validar 14 digitos antes de enviar; nunca enviar placeholder/vazio.

### Anexos — mudanca de formato: JPEG para PDF

Decisao de produto: todos os anexos passam a ser **PDF**, nao mais JPEG
direto. Estrutura planejada:
- `CNH.pdf`: duas paginas (frente e verso), a partir de
  `cnh_frente.jpg` + `cnh_verso.jpg` ja existentes;
- `CRLV.pdf`: uma pagina, a partir de `crlv.jpg` ja existente;
- Notas fiscais: um PDF separado por nota (`nota_01.jpg`...`nota_05.jpg`
  ja existentes), mantendo a ordem 1-5.

```json
{ "anexoBase64": "BASE64_PURO_DO_PDF", "descricao": "CNH" }
```

Base64 **puro**, sem prefixo `data:` (decisao agora explicita, nao mais
provisoria). Nunca usar `anexosGZip`. Nunca anexar `image.base64` do VIO
— sempre as imagens capturadas pelo scanner, ja existentes em disco.

Geracao de PDF exige nova dependencia (nenhuma biblioteca de PDF esta
instalada hoje — confirmado pelo `explorer`: `composer.json` so declara
`vlucas/phpdotenv`). Biblioteca a avaliar na implementacao: `setasign/fpdf`
(pura PHP, sem binario externo, suficiente para embutir imagens JPEG em
paginas — nao precisa de layout complexo). Decisao final de qual
biblioteca fica para o `/01-implementacao`, com validacao de
compatibilidade Hostgator antes de instalar.

### HTTP 409 — protocolo de teste controlado planejado

O Talent possui controle de duplicidade (confirmado no manual, secao
"Padroes Tecnicos"). Plano de teste controlado, autorizado pelo usuario,
a executar somente no `/01-implementacao`/`/02-testes` com aviso previo
explicito antes de cada chamada real:
1. Enviar um check-in de teste real;
2. Registrar apenas identificadores seguros da resposta (nunca CPF/CNH
   completos, nunca corpo bruto);
3. Se necessario, repetir exatamente o mesmo payload uma unica vez para
   confirmar o `409`;
4. Nao alterar dados para tentar contornar a duplicidade;
5. Documentar o significado real observado.

`409` so sera tratado como duplicidade se o manual ou o teste real
confirmarem — ate la, `409` e uma categoria propria que NAO dispara
retry automatico (por precaucao).

## Pendencias — reclassificacao final (2026-09-09, segunda rodada)

Taxonomia usada nesta rodada, substituindo a anterior: `RESOLVIDA` /
`NAO APLICAVEL` / `PENDENTE EXTERNA - OrdemColetaClient` / `VALIDACAO EM
PRODUCAO AUTORIZADA`. Tabela completa das 18 pendencias no handoff
`docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`, secao
"Reclassificacao final (segunda rodada, 2026-09-09)".

## Resultado da implementação (2026-09-09)

Implementado: tabela `tb_empresa`/vínculo com `tb_totem`, UF do CRLV
persistida e validada (27 UFs), anexos convertidos para PDF
(`setasign/fpdf`), `TalentClient`/`TalentRn` reescritos conforme o
contrato real, IDOR de `finalizar()` corrigido, idempotência de 5 estados
implementada, logs sanitizados, `tb_fila_envio`/cron ajustados. Revisão
de segurança encontrou e corrigiu 1 achado ALTO (classificação de erro de
rede podendo gerar check-in duplicado). 127 asserções novas + 121 de
regressão, todas passando. Nenhuma chamada real ao Talent. Detalhes
completos em `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`,
seção "Resultado da implementação".

Pronto para o teste controlado em Produção (protocolo já definido nas
seções acima).

## CONFIRMAÇÃO REAL (2026-09-10) — teste controlado em Produção

Teste real contra `POST /Portaria/Checkin` (autorizado pelo usuário,
CNPJ de depositante real usado) retornou HTTP 400 com corpo de erro
estruturado (validação ASP.NET Core), confirmando:

**Campos que NÃO geraram erro (portanto corretos como estão)**:
`cnpjArmazem`, `cnpjDepositante`, `tipoEmbDesemb`, `veiculo.placa`,
`veiculo.uf`, `motorista.cpf`, `motorista.nome`, `anexos`.

**Campos CONFIRMADOS como obrigatórios (contradiz suposição anterior de
opcional/omitido)**:
- `doctos` — obrigatório. A decisão anterior de omitir por falta de
  mapeamento de `nrDocto` **não é mais viável** — precisa de nova decisão
  de produto (semântica de `nrDocto` continua não 100% documentada no
  manual, mas o campo em si não pode mais ser omitido).
- `veiculo.rntc` — obrigatório. **Sem nenhuma fonte de captura hoje** no
  totem (não vem do CRLV via VIO Decode, não há campo manual). Novo
  achado bloqueante.
- `veiculo.tipo` — obrigatório. **Sem nenhuma fonte de captura hoje**, e
  o formato esperado (enum, texto livre, quais valores) não está
  documentado no manual. Novo achado bloqueante.

Corpo de erro real (mascarado, sem dado sensível):
```json
{"errors":{"doctos":["The doctos field is required."],"veiculo.rntc":["The rntc field is required."],"veiculo.tipo":["The tipo field is required."]},"status":400}
```

## Extensão RNTC/tipo de veículo + bloqueio doctos (2026-09-10)

Implementado: `veiculo.rntc` (extraído de `data.rntrc` do CRLV via VIO
Decode — grafia real confirmada no manual VIO) e `veiculo.tipo`
(extraído de `data.tipo`), com validação, cache, rebaixamento para MANUAL
e captura manual (campos de texto livre, sem enum documentado). Incluídos
no payload do Talent somente após aprovação completa do CRLV.

**`doctos` permanece pendência bloqueante**: enquanto a semântica de
`nrDocto`/`doctos[].tipo` não for confirmada, `AtendimentoController::finalizar()`
bloqueia INCONDICIONALMENTE qualquer envio real ao Talent com o código
interno `TALENT_DOCTOS_PENDENTE` (HTTP 501) — nenhum check-in real pode
ocorrer pelo totem até essa pendência ser resolvida por decisão de
produto explícita. Confirmado por 347/347 testes (0 falhas) e revisão de
segurança independente (0 achados críticos/altos/médios). Detalhes
completos em `docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md`,
seção "Nova rodada de /01-implementacao (2026-09-10)".
