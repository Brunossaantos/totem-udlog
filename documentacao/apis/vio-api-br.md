# vio.api.br (leitura e validação do QR de CNH e CRLV)

Integração usada pelo `DocumentoController` (`public/api/documento.php`) para ler o QR da CNH e do CRLV e compará-lo com os dados do atendimento. Cliente: `app/Rn/VioApiBrClient.php`. Fluxo funcional: [05 - CNH, CRLV, QR e vio.api.br](../fluxos/05-cnh-crlv-qr-vio.md). Endpoints do totem que a usam: [totem-api-interna.md](totem-api-interna.md) (seção `documento.php`). Convenções: [README.md](README.md). Legenda: **[C]** código, **[T: nome]** teste, **[A]** ambiente/terceiro, **[P]** pendente.

## Fonte das informações

Tudo o que está abaixo sobre `POST /api/qrcode/read` e `GET /api/qrcode/result/{id}` é o comportamento confirmado no código (`app/Rn/VioApiBrClient.php`, `app/Controller/DocumentoController.php`) e nos testes listados na seção Testes: o que o projeto envia, os campos que lê, os estados, os timeouts, o polling e a ausência de retry.

## Finalidade e quem chama

- O quiosque fotografa o QR do documento e envia o JPEG ao servidor (`documento.php?acao=iniciar-processamento`). O **PHP** chama a vio.api.br; o navegador **nunca** fala com ela.
- Instanciação: só em `DocumentoController::criarVioClient`. Variáveis `VIO_API_BR_BASE_URL` (obrigatória, **HTTPS**) e `VIO_API_BR_API_KEY` (cabeçalho `X-API-Key`, nunca logada). Ausentes ou não-HTTPS: exceção, e `iniciar-processamento` responde **503** `Servico de validacao de documento indisponivel no momento` (o estado vira `ERRO`). **[C]**

## Rotas externas (como o projeto as usa)

Base: `https://vio.exemplo.invalid` (placeholder; o valor real vem de `VIO_API_BR_BASE_URL`).

### Envio: `POST {base}/api/qrcode/read`

- Cabeçalhos: `Accept: application/json`, `Content-Type: application/json`, `X-API-Key: <chave>`.
- Corpo (fictício):

```json
{ "image": "/9j/4AAQSkZJRg...", "file_name": "0f1e2d3c4b5a6978.bin", "comparar": true }
```

`image` é o JPEG em base64; `file_name` é `bin2hex(random_bytes(8)) . '.bin'`; `comparar` é sempre `true`. **[C]**

- **Um único POST por tentativa. Sem retry** no cliente nem no servidor. **[C]**
- Interpretação da resposta:

| Resultado | Classificação |
|---|---|
| 200, 201 ou 202 com `id` string não vazia | `ok`, guarda `id_externo` |
| 200/201/202 sem `id` | falha **ambígua** (`envio_resposta_sem_id`) |
| 401 ou 403 | falha **não ambígua** (autenticação) |
| 400 ou 422 | falha **não ambígua** (validação) |
| qualquer outro HTTP e 5xx | falha **ambígua** |
| timeout **depois** de a conexão abrir | `timeout_ambiguo` (ambígua) |
| sem conexão / DNS (`COULDNT_CONNECT`, `COULDNT_RESOLVE_HOST`) | não ambígua |
| resposta maior que 10 MiB | não ambígua (`resposta_excessiva`) |

Ambígua = não se sabe se o fornecedor recebeu e processou; o atendimento fica `INDETERMINADO`. Não ambígua vira `ERRO`. A mensagem devolvida ao chamador é sempre um texto genérico (sem corpo do fornecedor).

### Consulta: `GET {base}/api/qrcode/result/{id}`

- `id` precisa casar `^[A-Za-z0-9_-]{1,128}$` (senão falha ambígua) e vai com `rawurlencode`.
- 200: normaliza `status` (`processing` | `completed` | `failed`; outro valor = ambígua), `qr_type`, `vio_result`, `compare.status` (`pending`, `processing`, `completed`, `failed`, `expired`), `compare.result.summary` (`reliable`, `mismatched`) e `compare.result.fields`, `pages_processed`, `total_pages`. Corpo que não é JSON objeto = ambígua. **[C]**
- 404: `nao_encontrado` (o atendimento fica `INDETERMINADO`).
- 400, 401, 403, 422, 5xx, erro de rede, timeout: **não ambígua** no cliente (consulta), o que faz o `status-processamento` gravar `ERRO` (não repete).

### Timeouts e limites

| Item | Valor |
|---|---|
| Conexão | 5 s |
| Total por chamada | 20 s |
| Corpo de resposta | máximo 10 MiB (aborta a leitura) |
| TLS | `SSL_VERIFYPEER` ligado, `SSL_VERIFYHOST` 2 |
| Expiração do processamento no servidor | 120 s (`ENVIANDO`/`PROCESSANDO_*` viram `INDETERMINADO`) |

## Polling (feito pelo front)

- Não há processo em segundo plano no servidor (hospedagem compartilhada). O **front** chama `documento.php?acao=status-processamento` a cada **2 s** (`PROCESSAMENTO_POLL_INTERVAL_MS`) por no máximo **125 s** (`PROCESSAMENTO_TIMEOUT_MS`); cada chamada tem timeout de 20 s. Esgotado o tempo, a tela mostra "Tempo de validação esgotado". Falha de rede na consulta mantém o loop (não terminal). **[C]**
- Cada `status-processamento` em estado `PROCESSANDO_LEITURA`/`PROCESSANDO_COMPARACAO` **consulta o VIO**. Limite: mais de 20 chamadas por (atendimento, tipo) em 5 s = **429** + `Retry-After`. **[C]**

## Estados (`<doc>_status_processamento` em `tb_atendimento`)

`PENDENTE`, `ENVIANDO`, `PROCESSANDO_LEITURA`, `PROCESSANDO_COMPARACAO`, `CONCLUIDO`, `ERRO`, `INDETERMINADO`. Terminais: `CONCLUIDO`, `ERRO`, `INDETERMINADO`. Origens aceitas do documento: `VIO_TRIAL`, `VIO_VALIDADO`, `MANUAL`, `VIO_CACHE`, `VIO_API_BR`. **[C]**

Resultado de `status-processamento` (esboço): com `status=failed` no fornecedor grava `CONCLUIDO` reprovado (motivo `documento_ilegivel`); com `completed`, o `DocumentoRn` avalia a regra (placa divergente, CNH vencida, campos inválidos etc.) e grava `CONCLUIDO` aprovado ou reprovado; `processing` não muda o estado. As regras de aprovação estão em [05](../fluxos/05-cnh-crlv-qr-vio.md) e [regras-negocio.md](../regras-negocio.md).

## CAS, duplicidade e concorrência

- `iniciar-processamento` só envia se o `UPDATE` condicional `iniciarEnvioVioApiBr` vencer (`PENDENTE` ou `ERRO`, ou `CONCLUIDO` + `NAO_VALIDADO` sem `*_validado_em`, que permite re-escanear). Grava `tentativa_id` (32 hex) e `GET_LOCK('vio_api_br_validar_<id>_<tipo>', 0)` como defesa adicional.
- Já aprovado, em processamento (`ENVIANDO`/`PROCESSANDO_*`) ou `INDETERMINADO`: **nunca** reabre; devolve o estado atual sem novo POST.
- Resultado e id externo só são gravados com o **mesmo `tentativa_id`** e `status='em_andamento'`; tentativa obsoleta é descartada (log `id externo descartado`).
- Re-escaneio: após reprovação `CONCLUIDO` + `NAO_VALIDADO` o front oferece "Escanear novamente" (terceira reprovação só oferece manual). **Não há limite de re-escaneio no backend** (P52 do state). **[C]**

## Erros e indisponibilidade

| Situação | Estado do documento | Resposta ao front |
|---|---|---|
| Env ausente / não-HTTPS | `ERRO` | 503 `Servico de validacao de documento indisponivel no momento` |
| Envio recusado (401/403/400/422/sem conexão) | `ERRO` | 502 `Nao foi possivel validar o documento agora` |
| Envio ambíguo (timeout, 5xx, sem `id`) | `INDETERMINADO` | 502 (mesmo texto) |
| Exceção ao enviar/gravar | `INDETERMINADO` | 502 |
| Consulta com erro não ambíguo | `ERRO` | 200 com `terminal:true` e `pode_avancar:false` |
| Consulta ambígua ou 404 | `INDETERMINADO` | 200 com `terminal:true` |

Em qualquer falha o motorista pode **preencher manualmente** (`preencher-manual`, origem `MANUAL`), então o fluxo não depende da disponibilidade do fornecedor. **[C]**

## Observabilidade

`error_log` com categoria técnica e `LogSistema 'vio_falha_integracao'` com `motivo` da lista fechada (`timeout`, `indisponivel`, `nao_autorizado`, `dados_invalidos`, `falha_inesperada`) e `http` quando houver; nunca grava corpo, chave ou valores do documento. Reprovação gera uma linha `vio_reprovado` com motivo da allowlist, sem valores (só no `error_log`). **[C]**

## Testes

[T: teste_vio_api_br_client, teste_vio_api_br_cas_e_cache, teste_vio_api_br_regras_crlv_e_rebaixamento, teste_vio_comparacao_diagnostica, teste_status_processamento, teste_contrato_qr_exclusivo_isolado, teste_fluxo_qr_exclusivo_integracao]. Todos usam transporte falso; nenhuma chamada real ao fornecedor. Não reexecutados aqui. **[A]**

## Pendências

- Cache: `VioCacheDao` é injetado em `DocumentoRn`, mas o construtor é o único uso visível; se existe caminho ativo de leitura/escrita de cache é **[P]**. A origem `VIO_CACHE` continua aceita.
