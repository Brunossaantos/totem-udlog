# API interna do totem

Endpoints usados pelo navegador do quiosque: `public/api/lgpd.php`, `atendimento.php`, `documento.php`, `nota.php` e `cliente.php`. As APIs de impressão estão em [impressao-servico-local.md](impressao-servico-local.md). Convenções gerais (auth, formato, HTTP, rate limits): [README.md](README.md). Legenda: **[C]** código, **[T: nome]** teste, **[A]** ambiente/terceiro, **[P]** pendente.

Fluxos relacionados: [01](../fluxos/01-lgpd-e-inicio-atendimento.md), [02](../fluxos/02-expedicao.md), [03](../fluxos/03-recebimento.md), [04](../fluxos/04-notas-e-ocr.md), [05](../fluxos/05-cnh-crlv-qr-vio.md), [06](../fluxos/06-consulta-e-baixa-ordem-coleta.md), [07](../fluxos/07-checkin-talent.md), [09](../fluxos/09-cancelamento-e-abandono.md). Regras: [regras-negocio.md](../regras-negocio.md). Tabelas: [banco-dados.md](../banco-dados.md).

## 0. Regras comuns

- **URL**: `https://<dominio-do-totem>/api/<arquivo>.php?acao=<acao>`.
- **Método**: o front usa `POST` com `Content-Type: application/json` (e `GET` em `cliente.php`). O servidor **não valida o método** (decisão de produto em 2026-10-10: não será tratado). **[C]**
- **Autenticação**: `Authorization: Bearer <token-do-totem>` (ver README). 401 sem token ou com token inválido.
- **Corpo**: JSON. Corpo ausente ou não-array (`5`, `"x"`, `true`, `null`) vira `[]` e a ação responde 4xx de "dados incompletos" (não 500). Exceção: `documento.php` responde **400** `Dados incompletos` se o corpo for ausente, não-array ou maior que 8 MiB. **[C]** [T: teste_corpo_json_escalar]
- **Posse**: ação com `id_atendimento` de outro totem = 404.
- **Timeouts do front** (`app.js`): o helper `api()` só aplica timeout quando a chamada pede. Definidos: `status-processamento` 20 s; `avancar-etapa-documentos` 30 s; `nota.php?acao=listar` 20 s; `nota.php?acao=processar` 90 s. As demais (inclui `finalizar`) **não têm timeout no front**. **[C]**
- **Retry**: o front não repete chamadas automaticamente, exceto `identificar-cliente` (até 2 novas tentativas após erro de rede, espera de 1 s e 2 s) e o polling de documento (ver [vio-api-br.md](vio-api-br.md)). **[C]**

Exemplo de cabeçalhos (fictício):

```http
POST /api/atendimento.php?acao=iniciar HTTP/1.1
Host: totem.exemplo.invalid
Authorization: Bearer TOKEN_FICTICIO_DO_TOTEM
Content-Type: application/json
```

## 1. `lgpd.php`

| Ação | Método | Auth |
|---|---|---|
| `aceitar` | qualquer (front usa POST) | token do totem |

### `aceitar`

Emite o token de aceite do Aviso de Privacidade. `LgpdRn::emitir` gera 32 bytes aleatórios (64 hex), grava **só o sha256** em `tb_lgpd_aceite` (`PENDENTE_USO`, `id_totem`, versão e hash do termo, validade de 10 minutos). **[C]**

- Parâmetros: nenhum.
- Resposta 200:

```json
{
  "sucesso": true,
  "dados": {
    "token_aceite": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
    "expira_em": "2026-01-15 10:10:00",
    "termo": { "versao": "1", "hash": "hash-ficticio", "texto": "Texto do aviso..." }
  }
}
```

- Erros: 401 (token). Falha de banco não é capturada neste arquivo: resposta **[P]**.
- Idempotência: nenhuma (cada chamada emite um token novo). O consumo é único (CAS em `iniciar`).
- Rate limit: nenhum. Fail-closed: sem token válido de aceite o atendimento não é criado.

## 2. `atendimento.php`

Ações: `iniciar`, `selecionar-ordem`, `salvar-etapa`, `bloquear-excesso-notas`, `concluir-digitalizacao`, `avancar-etapa-expedicao`, `avancar-etapa-documentos`, `finalizar`, `cancelar`. Todas: qualquer método (front usa POST), token do totem, JSON.

### `iniciar`

- Corpo: `tipo` (`expedicao`|`recebimento`), `placa`, `token_aceite` (hex de 64 caracteres).
- Requisição:

```json
{ "tipo": "expedicao", "placa": "ABC1D23", "token_aceite": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef" }
```

- 200, Expedição com uma OC: `{"id_atendimento":1001,"proxima_tela":"dados_encontrados","dados":{"numero":"OC-0001","cliente_nome":"CLIENTE EXEMPLO LTDA","cliente_cnpj":"00000000000191","data":"2026-01-15 09:00:00"}}`.
- 200, várias OCs: `{"id_atendimento":1001,"proxima_tela":"selecionar_ordem","ordens":[{...},{...}]}`. 200, Recebimento: `{"id_atendimento":1001,"proxima_tela":"quantidade_notas"}`.
- Erros: 400 `Tipo ou placa nao informados`; **409** `Aceite de privacidade invalido ou expirado`; 500 `Nao foi possivel processar o atendimento agora`; **502** `Nao foi possivel consultar as ordens de coleta agora`; 400 `Nenhuma ordem de coleta em aberto para essa placa`.
- Prevenção de duplicidade: o aceite é consumido por `UPDATE ... WHERE token_hash AND id_totem AND status='PENDENTE_USO' AND expira_em>NOW() AND versao/hash do termo` (CAS) **na mesma transação** que cria o atendimento; também reconcilia VIO abandonado do mesmo totem. **[C]** [T: teste_lgpd_aceite_backend_seguranca]
- Observação: a consulta de OC roda **depois do commit**. Atendimento e aceite ficam gravados mesmo quando a resposta é 400 (sem OC) ou 502 (banco externo indisponível). **[C]**

### `selecionar-ordem`

- Corpo: `id_atendimento`, `ordem` (`{"numero":"OC-0001"}`).
- 200: `{"proxima_tela":"dados_encontrados","dados":{...}}`. A OC é revalidada no banco externo para a placa.
- Erros: 400 `Dados incompletos`, 400 `Atendimento nao esta em andamento`, 400 `Atendimento nao esta na etapa esperada para selecionar a ordem` (só etapa `placa`), 400 `Ordem de coleta invalida para essa placa`, 404.
- Exceção do banco externo não é capturada: **[P]** o que o cliente recebe.

### `salvar-etapa`

- Corpo: `id_atendimento`, `etapa`, `dados`. 200 `{"ok":true}`.
- Etapas:

| `etapa` | Regra | Erros |
|---|---|---|
| `confirmacao` | só em `exp_confirmacao`/`rec_confirmacao` | 422 `CONFIRMACAO_INCOMPLETA` com `dados{campos[],rotulos[]}` (campo `codigo`); 400/404 de estado |
| `cliente` | só Recebimento; CNPJ válido e existente em `tb_cliente` | 400 `Cliente invalido ou nao cadastrado`; 500 `Nao foi possivel confirmar o cliente agora` |
| `ajudante` | nome completo e CPF de 11 dígitos (sem DV); `salvarAjudanteSeEditavel` com `SELECT ... FOR UPDATE`, só com Talent `NAO_ENVIADO` ou `ERRO_REPROCESSAVEL` | 409 se concluído ou Talent `ENVIANDO`/`ENVIADO`/indeterminado; 422 dados inválidos |
| `digitalizacao_notas` | só Recebimento, etapa `placa` | 400/404 de estado |

- **Etapa desconhecida: 200 `{"ok":true}` sem nenhum efeito** (sem `default`). Registrado, não corrigido. **[C]**
- Exemplo (ajudante):

```json
{ "id_atendimento": 1001, "etapa": "ajudante", "dados": { "nome": "AJUDANTE EXEMPLO DA SILVA", "cpf": "12345678901" } }
```

### `bloquear-excesso-notas`

- Corpo: `id_atendimento`. 200 `{"ok":true}`. Só Recebimento.
- Erros: 404 (posse ou tipo diferente de recebimento); **409** `Atendimento ja concluido, nao pode mais ser bloqueado`.
- Idempotente por desenho: `UPDATE ... status='bloqueado' WHERE status!='concluido'`.

### `concluir-digitalizacao`

- Corpo: `id_atendimento`. 200: `{"proxima_tela":"rec_cnh"|"rec_cliente","etapa":"...","cliente_estado":"...","cliente_motivo":"..."}`.
- Erros: 400 incompleto/status/etapa, 400 sem notas, 400 mais de 5 notas; **409 `OCR_EM_ANDAMENTO`** (`dados.ordens_em_processamento`); **422 `NOTAS_SEM_NUMERO`** (`dados.ordens_pendentes`, `total_notas`, `total_pendentes`), **só** se `CONCLUIR_EXIGE_NUMERO_NOTA=true`; 409 se o CAS foi perdido; 503 em lock timeout/deadlock (MySQL 1205/1213); 500.
- Concorrência: transação com `iniciarTransacao(5)` (espera de lock de 5 s) e `buscarParaUpdate`; CAS `concluirDigitalizacaoNotas`. Reentrada idempotente: 200 se o atendimento já está na etapa alvo ou posterior. **[C]** [T: _caso_concluir_digitalizacao, teste_hardening_concluir_422, teste_hardening_r4_ocr_em_andamento]

### `avancar-etapa-documentos` (e alias `avancar-etapa-expedicao`)

- Corpo: `id_atendimento`. O front só chama `avancar-etapa-documentos`; `avancar-etapa-expedicao` é alias não usado. **[C]**
- 200: `{"proxima_tela":"...","etapa":"..."}`. Ao entrar em `exp_confirmacao`/`rec_confirmacao`, acrescenta `dados_confirmacao` (`placa`, `motorista_nome`, `motorista_cpf`, `cnh_validade`, `crlv_ano`, `crlv_uf`, `crlv_rntc`, `crlv_tipo_veiculo`, `cliente_nome`, `cliente_cnpj`; o CPF vai integral).
- Erros: 400 `Atendimento nao esta em uma etapa valida para avancar`; 400 `Nao foi possivel avancar a etapa agora — documentos pendentes` (gate `ambos_aprovados`); 404; 500.

### `finalizar`

Check-in no Talent. Detalhes do payload e do cliente HTTP: [talent-checkin.md](talent-checkin.md).

- Corpo: `{"id_atendimento":1001}`.
- 200: `{"senha":"123456","protocolo":null}` (`senha` = `nrRegAcesso` do Talent; `protocolo` é sempre `null`).
- Erros, na ordem em que o código os avalia:

| HTTP | Texto de `erro` | Onde está o código |
|---|---|---|
| 404 | `Atendimento nao encontrado` | só texto |
| 400 | `Atendimento nao esta em andamento` / `... etapa esperada para finalizar` / `Documentos obrigatorios pendentes/invalidos` | só texto |
| 422 | `Nao e possivel continuar: preencha RNTRC, tipo de veiculo` | campo `codigo` = `CONFIRMACAO_INCOMPLETA` |
| 500 | `Totem sem empresa/armazem configurado para envio ao Talent` (inclui empresa **inativa**) | só texto |
| 422 | `Existem notas fiscais sem numero definido (NOTAS_SEM_NUMERO)` (Recebimento) ou `Atendimento sem ordem de coleta selecionada` (Expedição) | **só no texto** |
| 503 | `Envio ao Talent temporariamente desativado (TALENT_CHECKIN_DESATIVADO)` | **só no texto** |
| 409 | `Seu check-in ja esta sendo processado, aguarde` | só texto |
| 500 | `Nao foi possivel confirmar seu check-in — procure um atendente` (envio indeterminado) | só texto |
| **202** | texto por categoria (`... Chame o atendimento.`) com `sucesso:false` e, se houver, `dados.mensagem_api` | sem `codigo` |
| **422** | `Nao foi possivel concluir seu check-in. Dirija-se ao guiche da portaria.` (Talent respondeu 2xx sem `nrRegAcesso` utilizável; o atendimento é cancelado) | campo `codigo` = `TALENT_SEM_REGISTRO`; `dados` = `{"acao":"guiche_portaria","tentar_novamente":false}` |

- Exemplo 422 `TALENT_SEM_REGISTRO` (resposta real do código; sem `senha`, `protocolo` nem `mensagem_api`):

```json
{ "sucesso": false, "erro": "Nao foi possivel concluir seu check-in. Dirija-se ao guiche da portaria.", "codigo": "TALENT_SEM_REGISTRO", "dados": { "acao": "guiche_portaria", "tentar_novamente": false } }
```

- `TALENT_SEM_REGISTRO`: o `TalentClient` considera `nrRegAcesso` utilizável só se for texto com `trim() !== ''` (`"0"`, `"00123"` e `"AB 12"` valem; valor aninhado não). Nesse caso `TalentRn` chama `AtendimentoDao::cancelarPorRespostaSemRegistroTalent` (CAS por `talent_tentativa_id` + `talent_checkin_status='ENVIANDO'` + `status != 'concluido'`), grava `ENVIO_INDETERMINADO` e passa `status` a `cancelado` só se estava `em_andamento` (não sobrescreve `bloqueado` nem `concluido`). O controller registra `LogSistema` `talent_indeterminado` com `categoria_erro=resposta_ilegivel` e `error_log` sanitizado `checkin_talent_sem_registro_cancelado`, e move as fotos das notas para a quarentena (como `cancelar`). Na Expedição a OC **não** é baixada (continua ATIVA). Uma segunda chamada de `finalizar` responde 400 `Atendimento nao esta em andamento`, sem novo POST ao Talent. Se o CAS perder uma corrida (por exemplo, ajuste do ajudante em andamento por outro processo), o motorista recebe o mesmo 422, mas as fotos não são quarentenadas; o log é gravado. O Talent pode ter registrado o check-in sem devolver a senha: a portaria localiza o caso pelo `tb_log_sistema`. **[C]** [T: teste_talent_sem_registro, teste_talent_client_parsing]

- Exemplo 202 (fictício):

```json
{ "sucesso": false, "erro": "Nao foi possivel concluir o check-in. Chame o atendimento.", "dados": { "mensagem_api": "Mensagem fictícia devolvida pelo Talent." } }
```

- Idempotência: `JA_ENVIADO` devolve a senha gravada, sem nova chamada ao Talent. Após `ENVIADO` na Expedição, tenta baixar a OC; falha da baixa grava pendência em `tb_ordem_coleta_pendente_baixa` e log, **nunca falha o check-in**. **[C]** [T: teste_talent_idempotencia, teste_concorrencia_finalizar_checkin, teste_talent_idor_finalizar, teste_talent_mensagem_api_finalizar, teste_e2e_recebimento_expedicao_mock]. Nota: `teste_talent_idor_finalizar` e `teste_concorrencia_finalizar_checkin` falham quando `TALENT_CHECKIN_ATIVO` é `true` (o `.env` de dev tem `true`; com `false` passam), pois esperam 503 `TALENT_CHECKIN_DESATIVADO`. **[A]**
- Fail-closed: `TALENT_CHECKIN_ATIVO` diferente do literal `true` impede qualquer chamada ao Talent. Em produção está `true` (confirmado pelo responsável do projeto em 2026-10-10).

### `cancelar`

- Corpo: `id_atendimento`. 200 `{"ok":true}`; 404; **409** `Atendimento ja concluido, nao pode mais ser cancelado`.
- Idempotente por desenho: `UPDATE status='cancelado' WHERE status!='concluido'`. Depois move as fotos das notas para quarentena; falha só vai ao `error_log` (a resposta continua 200). **[C]** [T: _caso_cancelar]

## 3. `documento.php` (VIO)

Qualquer exceção não prevista responde **500** `Servico temporariamente indisponivel. Tente novamente em instantes.` Corpo ausente/não-array/maior que 8 MiB: 400 `Dados incompletos`. Integração externa: [vio-api-br.md](vio-api-br.md).

### `iniciar-processamento`

- Corpo: `id_atendimento`, `tipo` (`cnh`|`crlv`), `imagem_qr_base64` (JPEG em base64 puro).
- 200: `{"ok":true,"pode_avancar":false,"motivo":null,"aviso_trial":false,"origem":null,"status_revisao":null,"status_processamento":"PROCESSANDO_LEITURA","terminal":false}` (valores ilustrativos).
- Erros: 400 `Dados incompletos` / `Imagem do QR invalida` / estado ou etapa errada; **503** `Servico de validacao de documento indisponivel no momento` (`VioApiBrClient` não instancia); **502** `Nao foi possivel validar o documento agora`.
- Duplicidade: CAS `iniciarEnvioVioApiBr` (UPDATE condicional a `PENDENTE`/`ERRO`, ou `CONCLUIDO` + origem `NAO_VALIDADO` sem `*_validado_em`), com `tentativa_id` de 32 hex, mais `GET_LOCK('vio_api_br_validar_<id>_<tipo>', 0)` como defesa adicional. Já aprovado ou CAS perdido: devolve o estado atual **sem novo POST** ao VIO. **[C]** [T: teste_vio_api_br_cas_e_cache, teste_lock_obter_lock_documento]

### `validar-qr`

Sempre **404** `Acao invalida` (desativada). **[C]**

### `status-processamento`

- Corpo: `id_atendimento`, `tipo`. Mesmo formato de `iniciar-processamento` mais `motivo_usuario` (`placa_divergente`, `cnh_vencida`, `documento_ilegivel`, `dados_invalidos` ou `null`), preenchido **só** na chamada que efetiva o terminal `CONCLUIDO` reprovado.
- Rate limit: mais de 20 chamadas por (atendimento, tipo) em 5 s = **429** `Muitas consultas de status em pouco tempo. Aguarde.` + `Retry-After`. **[C]**
- Cada chamada em estado `PROCESSANDO_*` consulta o VIO. `ENVIANDO`/`PROCESSANDO` por mais de 120 s vira `INDETERMINADO`. O resultado só é gravado com o mesmo `tentativa_id` e `status='em_andamento'` (CAS). **[C]** [T: teste_status_processamento]

### `preencher-manual`

- Corpo CNH: `nome`, `cpf`, `validade`. Corpo CRLV: `placa`, `exercicio`, `uf`, `rntc`, `tipo_veiculo`. Marca `*_status_processamento='CONCLUIDO'`.
- Erros: 400 `Dados incompletos`; 500 `Nao foi possivel processar o preenchimento manual agora`.

## 4. `nota.php` (só Recebimento, etapa `digitalizacao_notas`)

Escritas sob transação com espera de lock de 5 s (`executarSobLock`) e `buscarPorIdParaUpdate`. Lock/deadlock (1205/1213) = **503** `Servico ocupado no momento. Tente novamente em instantes.`; compensações de disco em rollback. Erros comuns de estado: 404 `Atendimento nao encontrado`, 400 `Atendimento nao esta em andamento`, 400 `Atendimento nao esta na etapa de digitalizacao de notas`.

| Ação | Corpo/query | Sucesso | Erros e regras |
|---|---|---|---|
| `processar` | `id_atendimento`, `imagem` (JPEG base64), `chave?`, `uid?`, `ordem?` (1 a 5) | 200 com a leitura da nota | 400 `Imagem invalida`, `Ordem da nota invalida`, `Identificador da nota invalido`, `Ja existe uma nota registrada para essa ordem`, `Limite de 5 notas fiscais ja atingido...`; 500 `Nao foi possivel registrar a nota`. **Idempotência por `uid`**: nota já existente devolve 200 com `reaproveitada:true`. |
| `status` | query `id_atendimento` | 200 `{"cliente_identificado":true}` | O front não chama esta ação (uso **[P]**). |
| `identificar-cliente` | `id_atendimento`, `id_nota` ou `ordem`, `cnpjs_candidatos[]`, `razao_social_candidata` | 200 com o estado do cliente | **429** `Muitas requisicoes de identificacao de cliente em pouco tempo...` (30 por 60 s por totem) + `Retry-After` + log `rate_limit_ocr_excedido`; **503** `Servico de protecao indisponivel no momento...` se o limitador falhar (**fail-closed**); 404 `Nota nao encontrada para essa ordem`; 500. |
| `definir-numero` | `id_atendimento`, `numero`, `origem` (`OCR`\|`MANUAL`), `id_nota` ou `ordem` | 200 | 400 `Numero de nota invalido — informe apenas os digitos da NF-e`; **409** `... (NUMERO_NOTA_DUPLICADO)` **só no texto**; 404; 500. |
| `listar` | `id_atendimento` (query **ou** corpo) | 200 `{"total_notas":2,"notas":[...]}` | 400, 404, 500 `Nao foi possivel consultar as notas`. |
| `excluir` | `id_atendimento`, `id_nota` | 200 `{"excluida":true,"ja_excluida":false,"id_nota":..,"total_notas":..,"notas":[..],"cliente_atendimento":..}` | **Idempotente** (`ja_excluida:true`); foto vai à quarentena e é removida após o commit; 500 `Nao foi possivel excluir a nota`. |

Exemplo de `processar` (fictício, imagem truncada):

```json
{ "id_atendimento": 1001, "imagem": "/9j/4AAQSkZJRg...", "uid": "uid-ficticio-0001", "ordem": 1 }
```

Testes: [T: _caso_nota_processar, _caso_nota_definir_numero, _caso_nota_pdo_falha, teste_concorrencia_numero_nota_duplicado, teste_rate_limit_identificar_cliente_pdo, teste_numero_nota_validacao_tamanho]. `teste_hardening_exclusao` tem a falha E17 pré-existente. **[A]**

## 5. `cliente.php`

| Ação | Método | Parâmetros | Resposta |
|---|---|---|---|
| `buscar` | `GET` no front (não validado) | query `termo` | 200 `{"clientes":[]}` ou com **um único** cliente |

- `termo` com menos de 3 bytes: 200 `{"clientes":[]}` sem consultar o banco. Senão `ClienteDao::buscarUnicoPorTermo`.
- Outra ação: 404 `Acao invalida`.
- Regra da busca manual (decisão do produto, 2026-10-14): no máximo **uma** sugestão. A consulta lê só `id_cliente`, `nome` e `cnpj` dos clientes **ativos**; o termo é normalizado (acentos, maiúsculas, espaços). Termo só com dígitos (ignorando `.`, `-`, `/` e espaços) casa por **prefixo do CNPJ**; caso contrário, por **trecho do nome**. Com 0 ou mais de 1 cliente distinto a resposta é `{"clientes":[]}`; com exatamente 1, devolve só `nome` e `cnpj`. **[C]**
- Exemplo: `GET /api/cliente.php?acao=buscar&termo=exe` retorna `{"sucesso":true,"dados":{"clientes":[{"nome":"CLIENTE EXEMPLO LTDA","cnpj":"00000000000191"}]}}` (ilustrativo, só quando houver um único cliente ativo que case).
- Testes `teste_hardening_cliente` e `teste_identificar_cliente` usam o banco de dev e não foram rodados na F6. **[A]**
- O `catch` do front em `buscarClientes` engole erros 401/rede.

## 6. Resumo de idempotência, CAS, locks e fail-closed

| Ponto | Mecanismo |
|---|---|
| Aceite LGPD | token de uso único, CAS por `UPDATE` condicional dentro da transação de criação do atendimento |
| `concluir-digitalizacao` | lock de linha + CAS; reentrada idempotente |
| Ajudante | `SELECT ... FOR UPDATE`; só `NAO_ENVIADO`/`ERRO_REPROCESSAVEL` |
| Nota (`processar`) | idempotente por `uid`; limite de 5; ordem única |
| Nota (`excluir`) | idempotente (`ja_excluida`) |
| Documento (VIO) | CAS por `tentativa_id` + `GET_LOCK`; sem retry no servidor |
| Check-in Talent | CAS `iniciarEnvioTalent`; status `NAO_ENVIADO`/`ENVIANDO`/`ENVIADO`/`ERRO_REPROCESSAVEL`/`ENVIO_INDETERMINADO` |
| `cancelar`, `bloquear-excesso-notas` | `UPDATE ... WHERE status!='concluido'` |
| `identificar-cliente` | fail-closed (503) se o rate limit não puder ser verificado |
| `finalizar` | fail-closed por `TALENT_CHECKIN_ATIVO` |
| Banco do totem fora | 503 antes da autenticação |
