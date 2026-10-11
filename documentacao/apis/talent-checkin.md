# Talent WMS: check-in da portaria

Integração chamada no `finalizar` (`public/api/atendimento.php?acao=finalizar`). Código: `app/Rn/TalentClient.php` (HTTP), `app/Rn/TalentRn.php` (payload e estados), `app/Rn/TalentClientException.php` (categorias de erro). Fluxo funcional: [07 - Check-in no Talent](../fluxos/07-checkin-talent.md). Convenções: [README.md](README.md). Banco: [banco-dados.md](../banco-dados.md). Legenda: **[C]** código, **[T: nome]** teste, **[A]** ambiente/terceiro, **[P]** pendente. Quando a informação vem de `docs/manual_talent.md`, a linha diz **INFORMADO PELO FORNECEDOR**.

## Fontes e confiança

| Origem | O que cobre |
|---|---|
| IMPLEMENTADO PELO PROJETO **[C]** | URL, cabeçalhos, payload montado, timeouts, estados, tratamento de erro |
| INFORMADO PELO FORNECEDOR (`docs/manual_talent.md`) | endpoint `POST /Portaria/Checkin`, campos obrigatórios, valores de `tipoEmbDesemb` e `doctos.tipo`, retorno `{nrRegAcesso, msg}` (Swagger), 409 como duplicidade (teste real registrado em 2026-09-15) |
| NÃO CONFIRMADO **[P]** | corpo de erro do Talent, prefixo base64, timeout recomendado, idempotência do lado do Talent além do 409 observado, semântica de `cnpjArmazem` x CNPJ da UDLOG |

A seção "REFINAMENTO 2" de `docs/manual_talent.md` (valores em minúsculas) está marcada como superada no próprio documento. O manual também descreve o retorno de sucesso como "não documentado no manual PDF", e depois o confirma pelo Swagger (`TPortariaCheckinRet`).

## Endpoint

- **URL**: `POST https://talent.exemplo.invalid/Portaria/Checkin` (placeholder). A base vem de `TALENT_API_URL` (sem barra final; o cliente acrescenta `/Portaria/Checkin`). **[C]**
- **Autenticação**: `Authorization: Bearer <TALENT_API_KEY>` (token fornecido pelo Talent; nunca logado). `Content-Type: application/json; charset=utf-8`. **[C]**
- **Chamador**: só o servidor PHP, a partir de `TalentRn::processarCheckin`. O navegador não fala com o Talent.
- **Chamada real**: só em produção e com autorização; os testes usam servidor falso (`_router_talent_mock.php`). Dois check-ins reais estão documentados no state/manual. **[A]**

## Payload

Montado em `TalentRn::montarPayload`. Qualquer falha de montagem vira `ERRO_REPROCESSAVEL` com categoria `erro_montagem_payload` (sem chamar o Talent): CNPJ do armazém (`tb_empresa.cnpj`) ou do depositante (`tb_atendimento.cliente_cnpj`) sem 14 dígitos, tipo de atendimento inválido, placa/UF/RNTRC/tipo do veículo ausentes, CPF do motorista sem 11 dígitos, nome vazio, `doctos` impossíveis. **[C]**

Exemplo fictício (Expedição, com ajudante):

```json
{
  "cnpjArmazem": "00000000000191",
  "cnpjDepositante": "11111111000191",
  "tipoEmbDesemb": "Embarque",
  "veiculo": { "placa": "ABC1D23", "uf": "SP", "rntc": "12345678", "tipo": "CARRETA" },
  "motorista": { "cpf": "12345678909", "nome": "MOTORISTA EXEMPLO DA SILVA" },
  "ajudantes": [ { "cpf": "98765432100", "nome": "AJUDANTE EXEMPLO" } ],
  "doctos": [ { "tipo": "ORDEM_COLETA", "nrDocto": "OC-0001" } ],
  "anexos": [ { "anexoBase64": "JVBERi0xLjQK...", "descricao": "Ordem de Coleta" } ]
}
```

| Campo | Origem / regra |
|---|---|
| `cnpjArmazem` | `tb_empresa.cnpj` da empresa do totem (14 dígitos) |
| `cnpjDepositante` | `tb_atendimento.cliente_cnpj` (14 dígitos) |
| `tipoEmbDesemb` | `Embarque` (expedição) ou `Desembarque` (recebimento), capitalizados |
| `veiculo` | `placa` (só A-Z0-9, maiúscula), `uf` (lista de UFs válidas), `rntc`, `tipo` (do CRLV) |
| `motorista` | `cpf` (11 dígitos), `nome` |
| `ajudantes` | só se `possui_ajudante` e nome não vazio; `[{cpf, nome}]` (CPF do ajudante: 11 dígitos, sem validação de DV, decisão do usuário) |
| `doctos` | Expedição: um `{"tipo":"ORDEM_COLETA","nrDocto":<ordem>}`; Recebimento: um `{"tipo":"NOTA_FISCAL","nrDocto":<número>}` por nota (nota sem número = falha de montagem) |
| `anexos[]` | `{anexoBase64, descricao}`: um PDF por nota (`Nota Fiscal 01`, `02`...; gerado da foto JPEG) e, só na Expedição, o PDF da OC (`Ordem de Coleta`) |

- **Anexos em base64 puro**, sem prefixo `data:...;base64,`. O manual deixa o prefixo como pendência; o state registra que o formato puro funcionou no Talent (registro de teste 38018, leitura de QR ok). **[A]** **[P]** (manual)
- Anexo da OC (`AnexoOrdemColetaLeitor::buscar`, por CNPJ e número): **qualquer falha segue sem anexo** e só grava uma linha de log (`anexo_ordem_coleta_omitido id_atendimento=<n> motivo=<codigo>`); o check-in nunca falha por isso. Origem do PDF: [n8n-anexo-ordem-coleta.md](n8n-anexo-ordem-coleta.md). Nota cuja foto não existe em disco é pulada. **[C]**
- `montarAnexosGzip` existe em `TalentRn` e **nunca é chamado** (o formato ativo é `anexos`). **[C]**
- O manual lista campos opcionais (`reboque`, `paletes`, `obs` etc.) que o projeto **não envia**. **[C]**

## Timeouts

- Total da requisição: **30 s** (`CURLOPT_TIMEOUT`). **Não há `CURLOPT_CONNECTTIMEOUT`** separado e a verificação TLS é a padrão do curl. **[C]**
- O front **não define timeout** para a chamada `finalizar` (o limite efetivo é o do servidor/PHP). **[C]**
- Um envio em `ENVIANDO` há mais de **60 s** é reclassificado como `ENVIO_INDETERMINADO` na próxima chamada de `finalizar`. **[C]**

## Estados e idempotência (`tb_atendimento.talent_checkin_status`)

| Estado | Significado | O que `finalizar` faz |
|---|---|---|
| `NAO_ENVIADO` | ainda não enviado | tenta enviar |
| `ENVIANDO` | CAS ganho, requisição em curso | 409 `Seu check-in ja esta sendo processado, aguarde` |
| `ENVIADO` | sucesso; grava `status='concluido'`, `etapa_atual='impressao'`, `talent_senha`, `talent_enviado_em` | devolve a senha gravada (sem chamar o Talent) |
| `ERRO_REPROCESSAVEL` | falha com resposta clara | permite **nova tentativa manual** (nova chamada de `finalizar`) |
| `ENVIO_INDETERMINADO` | timeout ou falha de rede após enviar; **ou** 2xx sem `nrRegAcesso` utilizável (nesse caso o atendimento também vira `cancelado`) | **bloqueado**: 500 `... procure um atendente` (para um atendimento já `cancelado` a chamada responde 400 `Atendimento nao esta em andamento`) |

- **Prevenção de duplicidade**: `UPDATE ... SET talent_checkin_status='ENVIANDO', talent_tentativa_id=:t WHERE ... IN ('NAO_ENVIADO','ERRO_REPROCESSAVEL')` (CAS); o resultado só é gravado com o mesmo `talent_tentativa_id`. Duas chamadas concorrentes: só uma envia, a outra recebe 409. **[C]** [T: teste_talent_idempotencia, teste_concorrencia_finalizar_checkin]
- O Talent **também** rejeita duplicidade com HTTP 409 (observado em teste real de 2026-09-15 com o mesmo fingerprint): o projeto trata 409 como categoria `conflito` e `ERRO_REPROCESSAVEL`, nunca como sucesso. **INFORMADO PELO FORNECEDOR** / teste real registrado. **[A]**
- **Sem retry automático e sem fila de reenvio** (a fila foi removida em 2026-10-05). Nenhuma falha é reprocessada sozinha. **[C]** [T: teste_sem_fila_envio]
- `ENVIO_INDETERMINADO` por timeout/falha de rede não tem saída automática: o atendimento precisa de intervenção humana (ver [07](../fluxos/07-checkin-talent.md)). O caso "2xx sem número" tem saída definida: atendimento cancelado e motorista ao guichê (seção abaixo).

## Respostas e categorias de erro

| Resposta do Talent / falha | Categoria (`TalentClientException`) | Estado gravado |
|---|---|---|
| 2xx com `nrRegAcesso` texto não vazio (`trim() !== ''`) | sucesso (`senha` = `nrRegAcesso`; `msg` é descartada; `protocolo` sempre `null`) | `ENVIADO` |
| 2xx sem `nrRegAcesso` utilizável (ausente, nulo, não-texto, vazio/só espaços, corpo não-JSON ou não-array, 204) | `resposta_ilegivel` | `ENVIO_INDETERMINADO` + atendimento `cancelado` |
| 400 | `erro_validacao` | `ERRO_REPROCESSAVEL` |
| 401 | `erro_autenticacao` | `ERRO_REPROCESSAVEL` |
| 404 | `nao_encontrado` | `ERRO_REPROCESSAVEL` |
| 409 | `conflito` | `ERRO_REPROCESSAVEL` |
| 500 | `erro_servidor` | `ERRO_REPROCESSAVEL` |
| outros HTTP | `erro_http` | `ERRO_REPROCESSAVEL` |
| timeout do curl | `timeout` | `ENVIO_INDETERMINADO` |
| `RECV_ERROR`, `GOT_NOTHING`, `PARTIAL_FILE`, `SSL_CONNECT_ERROR`, `SEND_ERROR` | `erro_indeterminado` | `ENVIO_INDETERMINADO` |
| outros erros de curl / resposta falsa | `erro_conexao` | `ERRO_REPROCESSAVEL` |
| falha de montagem do payload | `erro_montagem_payload` (interno) | `ERRO_REPROCESSAVEL` |

### 2xx sem `nrRegAcesso` utilizável: atendimento cancelado (decisão de 2026-10-11)

Decisão do usuário (2026-10-11), implementada e ainda não commitada: se o Talent responder HTTP 2xx sem `nrRegAcesso` utilizável, o atendimento é **cancelado** e o motorista é orientado ao guichê da portaria (antes, o código gravava `ENVIADO`/`concluido` com senha nula).

- **Critério (`TalentClient`):** `nrRegAcesso` precisa ser texto com `trim() !== ''`. Aceitos: `"0"`, `"00123"`, `"AB 12"`. Rejeitados: campo ausente, `null`, número/booleano, texto vazio ou só espaços, valor aninhado, corpo não-JSON, corpo JSON não-array e 204 sem corpo. Em todos lança `TalentClientException('resposta_ilegivel')`.
- **Gravação (`TalentRn::processarCheckin`):** chama `AtendimentoDao::cancelarPorRespostaSemRegistroTalent` (CAS por `talent_tentativa_id` + `talent_checkin_status='ENVIANDO'` + `status != 'concluido'`): grava `talent_checkin_status='ENVIO_INDETERMINADO'` e `status='cancelado'` **só** se estava `em_andamento` (não sobrescreve `bloqueado` nem `concluido`). Não grava `talent_senha`.
- **Resposta (`finalizar`):** HTTP **422**, `codigo` `TALENT_SEM_REGISTRO`, `dados.acao='guiche_portaria'`, `dados.tentar_novamente=false`; sem `senha`, `protocolo` nem `mensagem_api`. Detalhes em [totem-api-interna.md](totem-api-interna.md).
- **Efeitos:** `LogSistema` `talent_indeterminado` com `categoria_erro=resposta_ilegivel`; `error_log` sanitizado `checkin_talent_sem_registro_cancelado`; fotos das notas vão à quarentena (como em `cancelar`); na Expedição a OC **não** é baixada (continua ATIVA); `gerar-etiqueta` não é alcançado (a tela do guichê não imprime).
- **Segunda chamada de `finalizar`:** 400 `Atendimento nao esta em andamento`, sem novo POST ao Talent.
- **Corrida do CAS:** se outro agente alterou o atendimento (por exemplo, ajuste do ajudante em andamento) e o CAS perde, o motorista recebe o mesmo 422; as fotos não são quarentenadas; o log é gravado.
- **Atenção operacional:** o Talent pode ter registrado o check-in sem devolver a senha. O `ENVIO_INDETERMINADO` de um atendimento cancelado por este ramo só se distingue de timeout seguido de `cancelar` pelo `tb_log_sistema` (`categoria_erro=resposta_ilegivel`); a portaria localiza o caso por esse log (não há campo próprio). **[C]** [T: teste_talent_sem_registro, teste_talent_client_parsing]

### Respostas ao front (`finalizar`)

- Falha reprocessável: HTTP **202** com `sucesso:false` (detalhes e códigos inconsistentes em [totem-api-interna.md](totem-api-interna.md) e [README.md](README.md)). O texto de `erro` depende da categoria: `timeout`/`erro_indeterminado`/`erro_conexao` -> "O sistema Talent esta fora do ar ou nao respondeu, e o check-in nao foi concluido. Chame o atendimento."; `erro_servidor` -> "O sistema Talent informou um erro interno e o check-in nao foi concluido. Chame o atendimento."; demais -> "Nao foi possivel concluir o check-in. Chame o atendimento." **[C]**
- Observação: `timeout` e `erro_indeterminado` gravam `ENVIO_INDETERMINADO` e a própria chamada já responde **500** `Nao foi possivel confirmar seu check-in — procure um atendente` (não 202). Na prática, o texto "fora do ar ou nao respondeu" do 202 só é alcançado pela categoria `erro_conexao`, embora a função de texto também cubra `timeout` e `erro_indeterminado`. **[C]**

### `mensagem_api`

- `Util\MensagemApi::extrairDoCorpo` lê o corpo de erro do Talent (HTTP de erro com corpo), remove caracteres de controle e invisíveis (inclui U+00AD, U+061C, U+180E, U+2060-2064), normaliza espaços e limita a **300 caracteres**.
- Vai ao front em `dados.mensagem_api` **só** no HTTP 202 e é exibida com `textContent`. **Nunca é logada nem persistida.** Rede, timeout e corpo vazio: sem `dados`. O formato do corpo de erro do Talent é **[P]**. **[C]** [T: teste_talent_mensagem_api_finalizar, teste_mensagem_api_e_flag_reimpressao]
- Critério de exibir "Corrigir dados do ajudante": o front procura a palavra "ajudante" no texto normalizado da `mensagem_api` (critério por texto, frágil, do state). **[C]**

## Portão `TALENT_CHECKIN_ATIVO` (fail-closed)

Com `TALENT_CHECKIN_ATIVO` diferente do literal `true`, `finalizar` responde **503** `Envio ao Talent temporariamente desativado (TALENT_CHECKIN_DESATIVADO)` **antes** de qualquer chamada ao Talent (o código `TALENT_CHECKIN_DESATIVADO` só aparece no texto de `erro`). Em produção `TALENT_CHECKIN_ATIVO=true`, confirmado pelo responsável do projeto em 2026-10-10. Os testes `teste_talent_idor_finalizar` e `teste_concorrencia_finalizar_checkin` exigem a flag diferente de `true` (esperam 503 `TALENT_CHECKIN_DESATIVADO`). **[C]** **[A]**

Outros pontos fail-closed antes da chamada: documentos pendentes (400), `CONFIRMACAO_INCOMPLETA` (422), empresa ausente **ou inativa** (500), nota sem número ou OC não selecionada (422).

## Efeitos colaterais do sucesso

- Expedição: após `ENVIADO`/`JA_ENVIADO`, tenta baixar a OC no banco externo ([banco-gestao-coletas.md](banco-gestao-coletas.md)); falha vira pendência (`tb_ordem_coleta_pendente_baixa`) e log, sem afetar a resposta. No ramo "2xx sem número" a OC **não** é baixada.
- A senha (`nrRegAcesso`) alimenta a etiqueta ([impressao-servico-local.md](impressao-servico-local.md)).

## Indisponibilidade do Talent

Talent fora do ar, lento ou com erro: o motorista vê a mensagem de falha e é orientado a chamar o atendimento; o atendimento **não conclui**, não há fila nem reenvio automático, e uma nova tentativa só ocorre se alguém acionar `finalizar` de novo (estado `ERRO_REPROCESSAVEL`). Se houve timeout ou falha de rede **depois** do envio, o estado é indeterminado e exige verificação manual no Talent. Resposta 2xx sem número de registro utilizável: atendimento cancelado e motorista ao guichê (seção acima). **[C]**

## Pendências

- Corpo de erro do Talent, prefixo base64 dos anexos, timeout recomendado e idempotência do lado do Talent além do 409: **[P]** (pendências do próprio `docs/manual_talent.md`).
- Semântica de `nrDocto` para `NOTA_FISCAL` (número curto x chave de 44 dígitos): o manual a deixa como pendência. **[P]**
- "Nr. Pager": **resolvida** (state P65, 2026-10-06). O Talent desligou a exigência desse campo; o projeto não envia `pager`. **[A]**
