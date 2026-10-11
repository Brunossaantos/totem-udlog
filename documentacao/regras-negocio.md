# Regras de negócio do totem-udlog

Este documento reúne as regras que o sistema aplica hoje: pré-condições, validações, limites, bloqueios, permissões, estados, idempotência, retenção e tratamento de falhas. Base: HEAD `4188fee`, 2026-10-09. Descreve o que o código faz; não propõe mudanças.

Documentos relacionados: [banco de dados](banco-dados.md), [APIs](apis/README.md) e os fluxos passo a passo citados em cada seção.

## Legenda de confiança

Mesma legenda do [banco-dados.md](banco-dados.md).

| Marca | Significado |
|---|---|
| **[C]** | Confirmado lendo o código. |
| **[T: nome]** | Existe teste em `tests/manual/` que cobre a regra. O teste não foi reexecutado ao escrever este documento; o "passa" vem do `ia_development_state.md`. |
| **[A]** | Depende de ambiente ou terceiro (Hostgator, banco externo real, n8n, Talent, vio.api.br, mini PC). Só vale como relato registrado no estado. |
| **[P]** | PENDENTE DE CONFIRMAÇÃO. Não verificado; nada foi presumido. |

Quando o código e o `ia_development_state.md` divergem, o código vence (ver a seção final).

---

## 1. Regras transversais

- **Autenticação do totem.** Toda rota de `public/api/` valida o token do totem (`Util\Auth::validarTotem`): token deve existir em `tb_totem` com `ativo=1`; senão 401. Totem desativado perde a API (401) e a página (404) de imediato. **[C]** Exceção: `ordem-coleta-anexo.php` usa chave própria do n8n (seção 8.3).
- **Posse do atendimento.** Todo endpoint confere que o `id_totem` do atendimento é o do token; senão 404. **[C]** [T: teste_idor_salvar_etapa_manual, teste_talent_idor_finalizar, teste_impressao_idor]
- **Corpo JSON.** Corpo que não seja objeto/array vira `[]` e a requisição responde 4xx (não 500). `documento.php` rejeita corpo acima de 8 MiB com 400. **[C]** [T: teste_corpo_json_escalar]
- **SQL.** Somente PDO com prepared statements. **[C]**
- **Página do quiosque.** Código do totem `^[A-Za-z0-9-]{1,64}$`; 404 uniforme para inválido, inexistente ou inativo; sem cache, sem referrer, noindex. **[C]**

Fluxos: [LGPD e início](fluxos/01-lgpd-e-inicio-atendimento.md), [Expedição](fluxos/02-expedicao.md), [Recebimento](fluxos/03-recebimento.md).

---

## 2. Atendimento: status, etapa e transições

### 2.1 Estados

- `status`: `em_andamento`, `concluido`, `cancelado`, `bloqueado`. **[C]**
- `etapa_atual` (texto livre de 40 caracteres, valores usados pelo código): `placa`, `dados_encontrados`, `exp_cnh`, `exp_crlv`, `exp_aguarde_documentos`, `exp_confirmacao`, `impressao`, `digitalizacao_notas`, `cliente`, `rec_cnh`, `rec_crlv`, `rec_aguarde_documentos`, `rec_confirmacao`, `balcao_portaria`. **[C]**

### 2.2 Transições de etapa

| Fluxo | Sequência | Condição para avançar |
|---|---|---|
| Expedição | `placa` -> `dados_encontrados` -> `exp_cnh` -> `exp_crlv` -> `exp_aguarde_documentos` -> `exp_confirmacao` -> `impressao` | `dados_encontrados`: 1 OC (automático) ou escolha da OC (>1). `exp_confirmacao`: CNH **e** CRLV aprovados. `impressao`: gravada pelo CAS do Talent (que marca `concluido`) e também por `avancar-etapa-documentos` em `exp_confirmacao` (rota não usada pelo front); **divergência do código**, não regra desejada. |
| Recebimento | `placa` -> `digitalizacao_notas` -> (`cliente`) -> `rec_cnh` -> `rec_crlv` -> `rec_aguarde_documentos` -> `rec_confirmacao` -> `impressao` | `cliente` só se o cliente não foi identificado automaticamente. Demais gates iguais à Expedição. |
| Bloqueio | qualquer -> `balcao_portaria` (`status=bloqueado`) | Ver 3.3. |

**[C]** [T: teste_avancar_etapa_expedicao, teste_e2e_recebimento_expedicao_mock, teste_integridade_conclusao_atendimento]

### 2.3 Transições de status

| De | Para | Quando |
|---|---|---|
| (novo) | `em_andamento` | Criação, após aceite LGPD válido. |
| `em_andamento` | `concluido` | Talent respondeu `ENVIADO` (grava `etapa=impressao` e a senha `nrRegAcesso`). |
| não `concluido` | `cancelado` | Ação `cancelar` do motorista ou cron de abandono. |
| `em_andamento` | `cancelado` | Talent respondeu 2xx sem `nrRegAcesso` utilizável (junto com `talent_checkin_status='ENVIO_INDETERMINADO'`; decisão de 2026-10-11; ver 7.3). |
| não `concluido` | `bloqueado` | Recebimento com mais de 5 notas. |

- `concluido` é **terminal**: cancelar ou bloquear responde 409. Reabertura de atendimento concluído NÃO está planejada. **[C]**
- Cancelar é idempotente (já cancelado devolve sucesso). Bloquear pode converter `cancelado` em `bloqueado`. **[C]**
- `cancelar` não consulta `talent_checkin_status`: um cancelamento entre o início do envio ao Talent e a gravação do resultado não é impedido (o `ENVIADO` sobrescreve). Nenhum teste cobre esse cruzamento. **[C]** (observação, ver seção final)

### 2.4 Início do atendimento

- Exige `tipo` em {expedicao, recebimento}, `placa` não vazia e token LGPD válido. A placa é gravada em maiúsculas; **não há validação de formato de placa** no backend nem no front. **[C]**
- O token LGPD é consumido ANTES da consulta de OCs da Expedição: se a placa não tem OC (400) ou a consulta falha (502), sobra um atendimento `em_andamento` na etapa `placa`, que só sai por cancelamento do front ou abandono de 24 h. **[C]**
- Ao criar um atendimento, processamentos VIO pendentes de OUTROS atendimentos `em_andamento` do mesmo totem viram `INDETERMINADO`. **[C]**

### 2.5 Front (quiosque)

- Inatividade: aviso após 180 s sem toque; 30 s depois cancela o atendimento. Não conta em `home` e `lgpd`. Fica suspensa durante impressão em `sem_papel`/`erro`/`indeterminado`/`erro_finalizar` e na correção do ajudante; com captura/OCR de notas ativo adia até 120 s no total. **[C]** [T: vio_captura_layout.js]
- Botão Cancelar (com confirmação) em toda tela exceto `home` e `lgpd`. `cancelarESair` ignora falha da API e volta à tela LGPD. **[C]**
- Ao recarregar a página não há retomada do atendimento. **[C]**
- Ao iniciar novo atendimento o front zera todo o estado local; persistem só a impressora escolhida (`totem_impressora_nome`) e o `deviceId` da câmera/scanner (`localStorage` `totem_scanner_deviceId`). **[C]**

Fluxo: [cancelamento e abandono](fluxos/09-cancelamento-e-abandono.md).

---

## 3. Notas fiscais (Recebimento)

Fluxo: [notas e OCR](fluxos/04-notas-e-ocr.md).

### 3.1 Limites

| Regra | Valor |
|---|---|
| Notas por atendimento | máximo **5** (ordem 1..5; a nota nova ocupa a primeira ordem livre) |
| Formato da foto | JPEG: assinatura `FFD8FF`, fim `FFD9`, decodificação completa pelo GD (falha = recusa) |
| Tamanho | até **5 MiB** (`NOTA_IMAGEM_MAX_BYTES`) |
| Dimensões | até **5000 px** por lado e 13.000.000 px de área |
| Número da nota | 1 a 9 dígitos, sem zeros à esquerda; único dentro do atendimento (409 `NUMERO_NOTA_DUPLICADO`) |

**[C]** [T: teste_validacao_jpeg_seguro, teste_numero_nota_validacao_tamanho, teste_concorrencia_numero_nota_duplicado]. Risco aceito: JPEG truncado com marcador final forjado. O backend não limita o tamanho do corpo HTTP em `nota.php` antes do `json_decode` (valem os limites do PHP/servidor e os 5 MiB do binário). **[C]**

### 3.2 Pré-condições do upload

Atendimento de Recebimento, do totem do token, `em_andamento`, etapa `digitalizacao_notas`. O upload roda sob transação e `SELECT ... FOR UPDATE` do atendimento, o que serializa uploads do mesmo atendimento; timeout de lock de 5 s (1205/1213 -> 503). **[C]**

### 3.3 Bloqueio acima de 5 notas

- O motorista informa "5 ou menos" ou "Mais de 5" na tela inicial. "Mais de 5" chama `bloquear-excesso-notas`: `status=bloqueado`, `etapa=balcao_portaria`, tela "precisa ser concluído no balcão da portaria". **[C]**
- O backend também trava por contagem (nunca aceita a 6ª nota). `bloquear-excesso-notas` não exige etapa nem `em_andamento`; `concluido` responde 409. **[C]**

### 3.4 Idempotência: `client_uid`

- O front gera um `uid` (8 a 64 caracteres `A-Za-z0-9_-`) por captura. A UNIQUE (`id_atendimento`, `client_uid`) faz a repetição da mesma foto devolver 200 com `reaproveitada=true` e o mesmo `id_nota`/`ordem`. **[C]** [T: teste_hardening_r4_uid_reconciliacao]
- Em falha de rede o front guarda o `uid` e reconcilia com `nota.php?acao=listar`, reenviando com o MESMO `uid`. **[C]**
- Em erro ou rollback a foto gravada é apagada (compensação). **[C]**

### 3.5 Exclusão e quarentena de 24 h

- Só na revisão, com confirmação. A foto vai para quarentena (`nota_NN.jpg.<id_nota>.del`) ANTES de a linha ser apagada (DELETE físico); se a transação falha, a foto é restaurada; após o commit o `.del` é removido, e o cron cobre a falha. Nota já inexistente responde 200 `ja_excluida`. **[C]** [T: teste_hardening_exclusao, teste_hardening_r4_storage_cron]
- O cron `limpar-notas-quarentena` apaga `.del` com mais de **24 h** (até 500 por execução; só caminhos válidos por regex; ignora symlinks). **[C]** [T: teste_hardening_cron_quarentena]

### 3.6 Concluir a digitalização

`concluir-digitalizacao` (sob `FOR UPDATE` do atendimento e das notas):

- Exige `em_andamento` e 1 a 5 notas. Se a etapa já é a de destino ou posterior, responde 200 idempotente.
- Nota com OCR PENDENTE/PROCESSANDO há menos de 120 s bloqueia com 409 `OCR_EM_ANDAMENTO`.
- `CONCLUIR_EXIGE_NUMERO_NOTA` (variável opcional; só o texto `true` a liga; padrão do código e do `.env.example`: `false`). **Ligada:** `concluir-digitalizacao` responde 422 `NOTAS_SEM_NUMERO` (`dados.ordens_pendentes`, `total_notas`, `total_pendentes`) se alguma nota ativa não tiver número válido (`NotaFiscalRn::numeroNotaValido`) e o front destaca as notas. **Desligada:** esse bloqueio não ocorre na digitalização (o front só libera "Continuar" com todas as notas confirmadas); o motorista segue e só o `finalizar` recusa (422 `NOTAS_SEM_NUMERO`) se alguma nota tiver número vazio (critério mais frouxo: não vazio). Decisão do usuário em 2026-10-11 de **ativar em produção**; valor no servidor a confirmar. **[C]** **[A]**
- Perdeu o CAS e outra requisição já avançou: 200 idempotente; senão 409. Lock timeout: 503. **[C]**

[T: teste_hardening_concluir_422, teste_hardening_r4_ocr_em_andamento]

---

## 4. Identificação de clientes (OCR)

### 4.1 Onde roda e limites

- O OCR (Tesseract.js) roda **sempre no navegador**; o servidor só recebe CNPJs candidatos e a razão social candidata e decide. **[C]**
- Rate limit de `identificar-cliente`: **30 por 60 s por totem**; excedido: 429 + `Retry-After` + log `rate_limit_ocr_excedido`; falha de PDO: 500; falha inesperada na dependência do limite: 503 (fail-closed). A tabela de janelas é limpa por cron horário (janelas com mais de 24 h). **[C]** [T: teste_rate_limit_identificar_cliente_pdo]
- Exige etapa `digitalizacao_notas`. O resultado de cada nota é gravado com CAS (só se `status_ocr` PENDENTE/PROCESSANDO): imutável depois de gravado. **[C]**

### 4.2 Regras de decisão por nota

1. Até 10 CNPJs candidatos, válidos (dígito verificador) e que **não sejam o da UDLOG**. A UDLOG nunca identifica cliente. **[C]**
2. Cada candidato é buscado em `tb_cliente` **ativo**. Clientes inativos não identificam.
3. **Um** cliente encontrado = IDENTIFICADA (automática). **Dois ou mais** = `ERRO` / `MULTIPLAS_CORRESPONDENCIAS` (anomalia).
4. Sem correspondência por CNPJ: comparação por razão social (`RazaoSocialMatcher`): maior pontuação >= **80** (`LIMIAR_MINIMO`) e diferença para a segunda >= **15** (`MARGEM_MINIMA`) = IDENTIFICADA; pontuação >= 80 sem a margem = `RAZAO_SOCIAL_AMBIGUA`; abaixo de 80 = NAO_IDENTIFICADA. **[C]** [T: teste_identificar_cliente, teste_hardening_cliente (não rodados nas últimas rodadas; usam o dev)]
5. Normalização (`RazaoSocialMatcher::normalizar`): maiúsculas, **sem acentos** (mapa fixo, independe de locale/`iconv`), sem pontuação, com tratamento de sufixos societários. "Café Ação Ltda" e "CAFÉ AÇÃO" resultam em `CAFE ACAO`. Letras fora do mapa viram espaço. **[C]** [T: teste_razao_social_normalizacao, teste_qa_normalizacao]

Limiares sem calibração com dados reais. **[P]** (P39 do estado)

### 4.3 Resultado do atendimento

- Todas as notas apontando o mesmo cliente = IDENTIFICADO: o cliente é gravado no atendimento e a etapa pula para `rec_cnh`.
- Dois ou mais clientes distintos = ANOMALIA/CONFLITO; nota em `ERRO` ou ainda processando (expirada) = ANOMALIA/INDETERMINADO; nenhum identificado: etapa `cliente`. Nos casos de anomalia e sem identificação o motorista seleciona o cliente manualmente. **[C]**
- Cliente manual (`salvar-etapa cliente`): só aceita cliente **selecionado da lista** (CNPJ válido e cliente ativo); texto livre é recusado (400). O nome e o CNPJ gravados vêm do banco, não do que o motorista digitou. O autocomplete começa em 3 caracteres e retorna até 8 itens. **[C]** [T: teste_salvar_etapa_cliente_manual]

---

## 5. CNH e CRLV (QR, vio.api.br e fallback manual)

Fluxo: [CNH, CRLV, QR e VIO](fluxos/05-cnh-crlv-qr-vio.md).

### 5.1 Leitura

- **QR-only:** o motorista aponta o QR do documento; o front lê o QR e envia o JPEG do quadro (mesmas travas de 5 MiB/5000 px/GD) para `iniciar-processamento`. O JPEG **não é gravado em disco** nem enviado ao Talent. **[C]**
- Etapas permitidas para iniciar: CNH em `exp_cnh`/`exp_crlv`/`exp_aguarde_documentos`; CRLV em `exp_crlv`/`exp_aguarde_documentos` (análogo no Recebimento). CNH e CRLV processam em paralelo. **[C]**
- **VIO sem retry:** um único POST por tentativa (`/api/qrcode/read`), conexão 5 s, total 20 s. Sem resposta de leitura = falha ambígua. **[C]** [T: teste_vio_api_br_client]
- Resultado por consulta (`status-processamento`), polling do front a cada 2 s com teto de 125 s. Rate limit **20 chamadas por 5 s por (atendimento, tipo)**; processamento acima de **120 s** vira `INDETERMINADO`. **[C]** [T: teste_status_processamento (instável por timing)]

### 5.2 Estados do processamento e fail-closed

- Estados: `PENDENTE`, `ENVIANDO`, `PROCESSANDO_LEITURA`, `PROCESSANDO_COMPARACAO`, `CONCLUIDO`, `ERRO`, `INDETERMINADO` (`PROCESSANDO` existe no ENUM e não é gravado). **[C]**
- **Envio:** falha ambígua (timeout após conexão, 5xx/HTTP desconhecido, resposta sem id) = `INDETERMINADO`; 401/403/400/422/sem conexão = `ERRO`.
- **Consulta:** 404 (`nao_encontrado`) e resposta ambígua (status desconhecido, corpo que não é JSON objeto) = `INDETERMINADO`; 400/401/403/422, 5xx, erro de rede e timeout são não ambíguos = `ERRO`.
- Nunca se aprova por ausência de resposta. **[C]**
- Origem gravada pelo fluxo atual: apenas `VIO_API_BR` e `MANUAL`. Valores `VIO_TRIAL`, `VIO_VALIDADO`, `VIO_CACHE`, `NAO_VALIDADO` são legado aceito na leitura (`NAO_VALIDADO` também marca reprovação reabrível). O cache VIO não é usado no fluxo QR-only. **[C]**

### 5.3 Aprovação automática

- Pré-requisito: estado de leitura `completed`, `qr_type=vio` e `dados_leitura` em formato de objeto. **[C]**
- **CNH aprovada:** nome não vazio e não placeholder (texto genérico ou caracteres todos iguais), CPF válido com dígito verificador, validade válida e **não vencida** (o próprio dia da validade ainda vale).
- **CRLV aprovado:** exercício inteiro exato (até 32767), UF entre as 27, nenhum campo placeholder e **placa do CRLV igual à placa do atendimento**.
- **RNTRC e Tipo do veículo são opcionais na leitura** (ficam NULL) e **obrigatórios na confirmação e no `finalizar`** (422 `CONFIRMACAO_INCOMPLETA` com `campos`/`rotulos`).
- A comparação (`compare`) é **só diagnóstica**: é normalizada e guardada, mas não decide aprovação. **[C]** [T: teste_vio_api_br_regras_crlv_e_rebaixamento, teste_vio_comparacao_diagnostica]
- Gravação atômica com CAS por `tentativa_id`; tentativa obsoleta responde `persistencia_nao_vigente`. **[C]**

### 5.4 Reprovação, re-escaneio e 3 falhas

- Reprovação grava `CONCLUIDO` com origem `NAO_VALIDADO` e o motivo ao motorista (`motivo_usuario`: `placa_divergente`, `cnh_vencida`, `documento_ilegivel`, `dados_invalidos`; só vem na chamada que efetiva o terminal; não é persistido). **[C]**
- Novo POST para o mesmo documento só é aceito se o processamento está `PENDENTE`/`ERRO` ou `CONCLUIDO` reprovado e sem validação; aprovado é idempotente; `PROCESSANDO_*`, `ENVIANDO` e `INDETERMINADO` nunca reabrem. **[C]**
- No front: o botão "Escanear novamente" só aparece enquanto `qrTentativas + 1 < 3`; na **3ª falha o único caminho é o preenchimento manual** (ou cancelar). QR não encontrado também conta tentativas, com a 3ª levando à tela manual. **[C]**
- **O backend não limita o número de re-escaneios** (nem tem rate limit próprio de `iniciar-processamento`); o teto de 3 é só do front. **[C]** (P52 do estado)

### 5.5 Fallback manual e rebaixamento

- `preencher-manual` passa pelas MESMAS validações (CNH vencida e placa divergente também são recusadas), grava origem `MANUAL` com `status_revisao=PENDENTE_REVISAO` e conclui o processamento. RNTRC/Tipo vazios herdam do snapshot só se a origem atual é VIO. **[C]**
- **Rebaixamento para MANUAL:** na confirmação, se a origem é VIO e qualquer campo difere do snapshot da leitura (nome, CPF em dígitos, validade; placa, ano, UF; RNTRC/Tipo só se o snapshot tinha valor), a origem vira `MANUAL` + `PENDENTE_REVISAO`. Digitar um campo que a VIO não trouxe NÃO rebaixa. **[C]** [T: teste_vio_api_br_regras_crlv_e_rebaixamento]

### 5.6 Confirmação dos dados

Campos obrigatórios (422 `CONFIRMACAO_INCOMPLETA` se faltar): placa; nome; CPF válido **com DV**; validade válida; ano > 0; UF das 27; RNTRC (1 a 20 caracteres); Tipo (1 a 60); OC (Expedição) ou cliente (Recebimento). Placa, OC e cliente aparecem somente leitura. **[C]**

Na Expedição, as edições da tela "Dados da ordem" (cliente, CNPJ, placa, veículo, OC) **não são enviadas nem persistidas**: o servidor segue com os valores do banco externo. **[C]**

### 5.7 Ajudante

Nome até 150 caracteres e CPF de **11 dígitos sem verificação de DV** (decisão do usuário; o CPF do motorista é validado com DV). "Sem ajudante" grava nome e CPF nulos. Salvar ou corrigir só é aceito com `em_andamento`, etapa de confirmação e `talent_checkin_status` em `NAO_ENVIADO`/`ERRO_REPROCESSAVEL` (sob `FOR UPDATE`); senão 409. O CPF do ajudante nunca é impresso. **[C]** [T: teste_talent_correcao_ajudante]

---

## 6. LGPD

Fluxo: [LGPD e início do atendimento](fluxos/01-lgpd-e-inicio-atendimento.md).

- A tela inicial exige marcar "Li e estou ciente do Aviso de Privacidade" antes de habilitar "Iniciar". **[C]**
- `lgpd.php?acao=aceitar` emite um token de **256 bits**, gravando só o SHA-256 em `tb_lgpd_aceite` com `versao_termo`, `hash_termo`, validade de **10 minutos** e status `PENDENTE_USO`. **[C]**
- **Uso único:** o token é consumido na criação do atendimento por UPDATE condicional (status `PENDENTE_USO`, não expirado, mesmo totem, mesma versão e mesmo hash do termo vigente), em transação. Token inválido, expirado, já usado ou de outro totem/versão = 409, e o front volta à tela LGPD. **[C]** [T: teste_lgpd_aceite_backend_seguranca (depende de fixture ausente no disco, ver seção final)]
- **Versão do termo:** `2026-09-25-v2`; hash = SHA-256 do texto. A aprovação do DPO consta só no estado, sem evidência no repositório. **[C]** / **[P]**
- Retenção: nenhuma rotina remove `tb_lgpd_aceite` nem tokens expirados (decisão de produto em 2026-10-10: não será tratado). **[C]**

---

## 7. Check-in no Talent

Fluxo: [check-in Talent](fluxos/07-checkin-talent.md).

### 7.1 Pré-condições do `finalizar` (nesta ordem)

1. `em_andamento` e etapa `exp_confirmacao`/`rec_confirmacao`.
2. CNH e CRLV aprovados.
3. RNTRC e Tipo preenchidos (422 `CONFIRMACAO_INCOMPLETA`).
4. Totem vinculado a empresa **ativa** (o CNPJ do armazém vem de `tb_empresa`, nunca do front); senão 500.
5. Recebimento: todas as notas com número (422 `NOTAS_SEM_NUMERO`). Expedição: OC não vazia (422).
6. **`TALENT_CHECKIN_ATIVO` exatamente `true`**; qualquer outro valor = 503 `TALENT_CHECKIN_DESATIVADO` (fail-closed). O `.env.example` traz `false`; em produção está `true` (confirmado pelo responsável do projeto em 2026-10-10).

**[C]** [T: teste_preparacao_producao_checkin]. O `finalizar` não confere `cliente_nome` (só a confirmação confere); o CNPJ do depositante é validado ao montar o payload. **[C]**

### 7.2 `talent_checkin_status` e transições

| Status | Significado | Saída |
|---|---|---|
| `NAO_ENVIADO` | ainda não enviado | CAS -> `ENVIANDO` |
| `ENVIANDO` | envio em curso (tentativa com `talent_tentativa_id` novo) | resposta 2xx com `nrRegAcesso` utilizável -> `ENVIADO`; 2xx sem `nrRegAcesso` utilizável -> `ENVIO_INDETERMINADO` + atendimento `cancelado`; `timeout`/`erro_indeterminado` -> `ENVIO_INDETERMINADO`; demais falhas -> `ERRO_REPROCESSAVEL`; acima de **60 s** é tratado como `ENVIO_INDETERMINADO` |
| `ENVIADO` | check-in feito; `status=concluido`, `etapa=impressao`, senha = `nrRegAcesso` | terminal; nova chamada devolve `JA_ENVIADO` (idempotente) |
| `ERRO_REPROCESSAVEL` | recusa ou falha com certeza de que não foi processado | nova tentativa manual -> `ENVIANDO` |
| `ENVIO_INDETERMINADO` | não se sabe se o Talent processou (timeout/falha de rede) ou o Talent respondeu 2xx sem número de registro | timeout/falha de rede: **sem saída no código**; 2xx sem número: atendimento `cancelado` e motorista ao guichê (7.3) |

**[C]** [T: teste_talent_idempotencia, teste_talent_client_parsing, teste_concorrencia_finalizar_checkin (só passa com a flag diferente de `true`)]

### 7.3 Reprocessável, indeterminado e ausência de reenvio

- **ERRO_REPROCESSAVEL:** responde **HTTP 202 com `sucesso=false`**, texto fixo por categoria e, se o Talent mandou motivo, `dados.mensagem_api` sanitizado (controles/invisíveis removidos, 300 caracteres, nunca logado nem persistido). O motorista vê frase própria ("Não foi possível concluir o check-in agora. Chame o atendente.") e pode tocar em "Tentar novamente", que chama `finalizar` de novo. **[C]** [T: teste_talent_mensagem_api_finalizar]
- **INDETERMINADO:** responde 500 "procure um atendente" + log `talent_indeterminado`. Atendimento em `ENVIO_INDETERMINADO` **não é cancelado pelo abandono** e a Gestão não tem tela para resolvê-lo: fica `em_andamento` indefinidamente. **[C]** (pendência registrada)
- **Sem reenvio automático.** A fila de reenvio foi removida em 2026-10-05; nenhuma falha é reenviada sozinha. Só uma nova chamada de `finalizar` (ou correção do ajudante) tenta de novo. **[C]** [T: teste_sem_fila_envio]
- **Talent 2xx sem `nrRegAcesso` utilizável (decisão do usuário em 2026-10-11, implementada):** o atendimento é **cancelado** e o motorista vai ao guichê da portaria. "Utilizável" = texto com `trim() !== ''` (`"0"`, `"00123"` e `"AB 12"` valem; ausente, nulo, não-texto, vazio, só espaços, aninhado, corpo não-JSON ou não-array e 204 não valem). Regras: `talent_checkin_status='ENVIO_INDETERMINADO'`; `status` vira `cancelado` só se estava `em_andamento` (não sobrescreve `bloqueado` nem `concluido`); `finalizar` responde 422 `TALENT_SEM_REGISTRO` (`dados.acao='guiche_portaria'`, `tentar_novamente=false`) sem senha; log `talent_indeterminado` com `categoria_erro=resposta_ilegivel`; fotos das notas em quarentena; na Expedição a **OC não é baixada** (continua ATIVA); a 2ª chamada de `finalizar` responde 400 `Atendimento nao esta em andamento`, sem novo POST. Se o CAS perder uma corrida (ajuste do ajudante em andamento), o motorista recebe o mesmo 422, mas as fotos não são quarentenadas. O Talent pode ter registrado o check-in sem devolver a senha: a portaria localiza o caso pelo `tb_log_sistema`, único meio de distinguir este `cancelado` + `ENVIO_INDETERMINADO` de timeout seguido de `cancelar`. Substitui o comportamento anterior (`ENVIADO`/`concluido` com senha nula). **[C]** [T: teste_talent_sem_registro, teste_talent_client_parsing]
- Anexos: PDF de cada nota ("Nota Fiscal NN") e, na Expedição, o PDF da OC ("Ordem de Coleta"), em base64 puro. Qualquer falha do anexo da OC segue **sem anexo**, só `error_log`. **[C]**
- Integração real com o Talent: contrato em `docs/manual_talent.md`; corpo de erro 400 real não documentado. **[A]** / **[P]**

---

## 8. Ordem de coleta (Expedição)

Fluxos: [consulta e baixa](fluxos/06-consulta-e-baixa-ordem-coleta.md), [anexo n8n](fluxos/10-anexo-ordem-coleta-n8n.md).

### 8.1 Consulta por placa

- O banco externo é acessado por PDO direto (sem API). A busca devolve OCs com `status=ATIVA` **e** cliente com `status=ATIVO`, pela placa prevista normalizada (maiúscula, sem não alfanuméricos), da mais recente para a mais antiga. Cliente inativo esconde a OC no totem. **[C]** / **[A]**
- Resultado: 0 OCs = 400 "Nenhuma ordem de coleta em aberto para essa placa"; 1 OC = seleção automática; mais de 1 = motorista escolhe, e o servidor **revalida** que o número está entre as OCs reais da placa. Falha de banco externo: 502 + log `oc_consulta_falhou`. **[C]** [T: teste_consulta_ordem_coleta (falha 12/17 por fixture do banco externo)]
- O contrato do banco externo em `docs/db_gestao_coletas.md` está como template vazio nessa parte; a estrutura vigente (colunas `status`/`inativada_em`, `tb_ordem_coleta_arquivos`) consta do dump local de `docs/` (fora do Git; produção, somente estrutura, 2026-10-10). **[P]**

### 8.2 Baixa

- Só na Expedição e só **depois** do Talent `ENVIADO` (também repetida em `JA_ENVIADO`, de forma idempotente). Recebimento não baixa OC. No ramo "Talent 2xx sem número de registro" (7.3) a OC **não** é baixada e continua ATIVA. **[C]**
- A baixa é **por cliente (CNPJ gravado no atendimento) + número**: UPDATE para `INATIVA` com `inativada_em`, só onde `status=ATIVA` e o cliente do CNPJ coincide. Sem CNPJ nunca se baixa por número ambíguo. **[C]** [T: teste_ordem_coleta_pendente_baixa, teste_gestao_oc_dao]
- Se a OC já está `INATIVA` (linha única), nada a fazer. Qualquer outro caso (ainda ATIVA, inexistente, ambígua, sem CNPJ, banco fora) registra **pendente de baixa** (`tb_ordem_coleta_pendente_baixa`, UNIQUE por atendimento) e log `oc_baixa_falhou`; se nem isso grava, log `pendencia_nao_registrada`. Falha de baixa **nunca desfaz o check-in** nem muda a resposta ao motorista. Não há cron de reconciliação; a resolução é manual na Gestão (seção 13.3). **[C]**

### 8.3 Anexo vindo do n8n

- Endpoint `ordem-coleta-anexo.php`: só POST, HTTPS, `Authorization: Bearer` com `ORDEM_COLETA_ANEXO_API_KEY` (comparação por `hash_equals`); sem chave configurada = 503. Rate limit só de falhas: **10 por IP em 900 s** (429). Corpo acima de 1,5x o limite = 413. **[C]** [T: teste_oc_anexo_http]
- Regras: `cnpj_cliente` com 14 dígitos; número da OC de 1 a 50 caracteres sem controles; base64 estrito; até **5 MiB**; começa com `%PDF-` (senão 422). Mesmo conteúdo (SHA-256 e tamanho) já presente = 200 `duplicado`; novo = 201 `criado`/`atualizado`. **Um arquivo por (CNPJ, número)**: o novo substitui o anterior. **[C]** [T: teste_oc_anexo_logica]
- Só PDF chega ao totem (decisão do usuário); OC sem anexo faz check-in sem anexo. **[C]**
- O arquivo fica em `STORAGE_PATH/ordens_coleta/<cnpj>/`, fora do webroot. **[C]**
- **Retenção de 15 dias:** o cron apaga o arquivo e a linha 15 dias após a inativação da OC (`inativada_em`), ou 15 dias após o recebimento se não existe OC correspondente (órfão). OC **ATIVA nunca perde** o arquivo. **[C]** [T: teste_oc_anexo_lacunas]

---

## 9. Impressão de etiquetas

Fluxo: [impressão e etiquetas](fluxos/08-impressao-etiquetas.md).

- **Pré-condição:** `gerar-etiqueta` só responde com `status=concluido`, `talent_checkin_status=ENVIADO` e senha não vazia; senão 409. **[C]**
- **Duas etiquetas:** motorista (padrão) e ajudante. A do ajudante exige `possui_ajudante=1` e nome, senão 409. O front imprime a do motorista e, se `tem_etiqueta_ajudante`, a do ajudante em seguida; o atendimento só é dado como concluído na tela após as duas; "Tentar novamente" reimprime só a pendente. **[C]** [T: teste_etiqueta_ajudante]
- **Formato:** papel **80x80 mm** (`.env`), conteúdo na **área útil de 48 mm** (a impressora imprime cerca de 50,8 mm; causa não confirmada), 1 página, fontes reduzidas automaticamente. Texto **somente ASCII** (0x20-0x7E, sem acento). Conteúdo: "UDLOG" (ou "AJUDANTE"), nome, número de acesso e "Gerado em"; **nunca CPF**. **[C]** [T: teste_texto_etiqueta] / **[A]** (a causa dos 48 mm)
- Configuração inválida de etiqueta: 500 + log `etiqueta_config_invalida`. Falha ao obter a configuração do serviço local: 503. **[C]**
- **Serviço local (mini PC) [A]:** escuta só em 127.0.0.1; token obrigatório; allowlist de impressoras; um job por vez (503 se ocupado); idempotência **em memória** por `identificador` (repetir devolve `ja_impresso`); timeout de 30 s (504 `indeterminado`).
- **`sem_papel`:** se o job permanece na fila do spooler além de `semPapelTimeoutMs` (padrão 10 s), o serviço remove só aquele job e responde 409 `sem_papel` sem marcar o identificador como impresso. O front mostra "Falta de papel na impressora. Chame o atendimento." com "Tentar novamente" sem limite e sem inatividade. Validado fisicamente só para a TM-T88VII (driver `Receipt6`, porta `TMUSB001`); sucesso do spooler não prova impressão física. **[C]** / **[A]**
- Estados do front: enviando, erro de finalização, seleção de impressora, preparando, imprimindo, concluído, erro, indeterminado, sem papel. **[C]**

---

## 10. Rate limits e proteção contra abuso

| Escopo | Limite | Resposta | Fonte |
|---|---|---|---|
| OCR (`identificar-cliente`) | 30 por 60 s por totem | 429 + `Retry-After`; dependência falhou: 503 | **[C]** |
| `status-processamento` | 20 por 5 s por (atendimento, tipo) | 429 | **[C]** |
| Página do totem (token/código inválido) | 20 falhas por 600 s por IP | 429 + `Retry-After` | **[C]** |
| API de anexo (n8n) | 10 falhas por 900 s por IP | 429 | **[C]** |
| Login da Gestão por IP | 10 falhas por 900 s | 429 + `Retry-After` | **[C]** |
| Login da Gestão por conta | 5 falhas -> bloqueio de 15 min | mensagem única | **[C]** |

- O limite da página do totem conta **só falhas**, por IP; `CF-Connecting-IP` só é usado se `REMOTE_ADDR` pertence às faixas oficiais da Cloudflare. Quiosques atrás do mesmo IP público compartilham o limite. **[C]** / **[A]** (comportamento em produção)
- **As APIs do totem não têm rate limit de falhas de token** (só throttle de gravação do log `totem_nao_autorizado`, 1 por 60 s). **[C]**
- Os limites de login por conta/IP não são atômicos entre processos. **[C]** (pendência baixa)

---

## 11. Abandono e retenção

Fluxo: [cancelamento e abandono](fluxos/09-cancelamento-e-abandono.md). Crons de CLI exclusivo (`PHP_SAPI`), cada um registra `cron_resumo`/`cron_falhou` e sai com código 1 em falha. **[C]**

| Rotina | Regra | Agenda em produção |
|---|---|---|
| Abandono (`abandonar-atendimentos`) | `em_andamento` com Talent `NAO_ENVIADO`/`ERRO_REPROCESSAVEL` e **24 h** (86400 s) sem atividade (maior entre `atualizado_em` e atividade das notas) vira `cancelado`; fotos das notas vão para quarentena; lote de 500; transação com `FOR UPDATE` e recheck; compensa (restaura fotos) em falha | 03:15 **[A]** |
| Quarentena de notas | apaga `.del` com mais de 24 h | 03:30 **[A]** |
| Anexos de OC | 15 dias (seção 8.3) | 03:55 **[A]** |
| Rate limit OCR | apaga janelas com mais de 24 h | hora cheia **[A]** |
| Logs e auditoria | 90 dias (seção 13.5) | 04:10 (decisão); agendamento em produção **[P]** |

- O abandono **não atua** em `ENVIANDO`, `ENVIADO` nem `ENVIO_INDETERMINADO`. **[C]**
- **Sem rotina de retenção** para `tb_atendimento`, `tb_atendimento_nota`, fotos de notas de atendimentos concluídos e `tb_lgpd_aceite`. Dados do motorista ficam em texto plano em `tb_atendimento`. **[C]**; decisão de produto (2026-10-10): não será tratado.
- `storage/` fica fora do webroot (fotos de notas, anexos de OC, marcadores de throttle). **[C]**

---

## 12. Concorrência e idempotência (resumo)

| Mecanismo | Onde |
|---|---|
| `SELECT ... FOR UPDATE` do atendimento | upload de nota, excluir/definir número, concluir digitalização, correção do ajudante, abandono |
| CAS por UPDATE condicional | token LGPD; envio VIO (`iniciarEnvioVioApiBr`) e gravação por `tentativa_id`; envio Talent (`talent_tentativa_id`); resultado de OCR da nota; `concluirDigitalizacaoNotas`; resolver baixa de OC; ativar/inativar OC |
| `GET_LOCK` nomeado | `vio_api_br_validar_<id>_<tipo>` (não bloqueante, adicional ao CAS); retenção de logs (não bloqueante); cadastros da Gestão (10 s); administradores (10 s) |
| UNIQUE | (`id_atendimento`, `client_uid`), (`id_atendimento`, `numero_nota`), pendência de baixa por atendimento, (`cnpj`, `numero`) do anexo, (empresa, nome) do totem |
| Idempotência de resposta | `finalizar` (`JA_ENVIADO`), `cancelar`, `concluir-digitalizacao`, nota repetida, anexo duplicado, impressão por `identificador` (em memória no serviço local) |

**[C]** Falhas de lock viram 503 (upload, concluir) em vez de erro genérico.

---

## 13. Gestão Totem

Fluxos: [acesso e usuários](fluxos/11-gestao-acesso-e-usuarios.md), [totens](fluxos/12-gestao-totens.md), [logs e auditoria](fluxos/13-gestao-logs-auditoria.md), [ordens de coleta](fluxos/14-gestao-ordens-coleta.md), [clientes e empresas](fluxos/15-gestao-clientes-empresas.md).

### 13.1 Acesso, sessão e permissões

- **Perfis (RBAC no servidor):** `admin` e `usuario`. `usuario` acessa **apenas** Ordens de coleta e Minha conta; todo o restante (Usuários, Totens, Clientes, Empresas, Logs) é só `admin`. Home: `admin` -> usuários; `usuario` -> ordens. **[C]**
- **Pipeline de toda página:** cabeçalhos de segurança (`no-store`, `X-Frame-Options: DENY`, `nosniff`, CSP), HTTPS obrigatório salvo `GESTAO_PERMITIR_HTTP=true`, `GESTAO_HASH_SALT` com 16+ caracteres (senão 503), conferência de `Origin`/`Sec-Fetch-Site` em métodos diferentes de GET, **CSRF** (token da sessão, `hash_equals`) em todo método diferente de GET. Ações de escrita são POST. **[C]** [T: teste_gestao_http, teste_gestao_unidade]
- **Login:** formato `primeiro.segundo` (2 a 30 letras minúsculas, ponto, 2 a 30 letras). Mensagem única para qualquer falha; verificação de senha fictícia para contas inexistentes/inativas/bloqueadas (tempo constante). Token do formulário de login sem estado, válido por 7200 s. **[C]**
- **Sessão:** cookie opaco `gestao_sid` (HttpOnly, SameSite=Strict, Secure), 256 bits; no banco só o SHA-256. Vale se: ociosidade menor que **30 min**, idade menor que **12 h**, usuário ativo e User-Agent (hash) igual ao do login. `ultimo_acesso_em` atualiza no máximo 1 vez por 60 s. Sessões expiradas são apagadas de forma oportunista (1 a cada 50 logins). **[C]** [A] (Argon2id, cookie `Secure` e Cloudflare só verificáveis em produção)
- **Senha:** 12 ou mais caracteres, até 72 bytes, mínimo 5 caracteres distintos, lista de senhas comuns; Argon2id (fallback bcrypt). **[C]**
- **Troca obrigatória:** usuário novo ou com senha redefinida só acessa `conta.php` até trocar. A troca exige a senha atual (falha conta como falha de conta), revoga todas as sessões do usuário e emite uma nova. Senha temporária não expira. **[C]**
- **Usuários (só admin):** criar (senha temporária exibida uma vez), editar nome/perfil, ativar/inativar, redefinir senha (com `versao_senha` otimista), desbloquear. Recusas: mudar o próprio perfil; inativar, redefinir ou desbloquear a própria conta; remover/inativar o **último admin ativo**. Inativar e redefinir revogam as sessões do alvo. Usuários nunca são excluídos. Toda mutação roda sob lock e reconfere que o ator ainda é admin ativo. **[C]** [T: teste_gestao_qa_independente]
- **Primeiro admin:** só por `tools/criar-admin.php` (CLI interativo, senha sem eco, recusa se já existe admin ativo, salvo `--forcar`). 2FA e recuperação de senha por e-mail NÃO existem nem estão planejados. **[C]** [T: teste_gestao_cli_admin]

### 13.2 Totens (só admin)

- Código `NOME-EMPRESA-HASH16`: nome do totem de 2 a 24 caracteres (ASCII maiúsculo), slug da empresa de até 16 alfanuméricos, hash de 16 caracteres CSPRNG (A-Z e 2-7); nome único por empresa; token novo de 256 bits; até 5 tentativas em colisão de código. **[C]**
- A URL do quiosque usa `TOTEM_URL_BASE` (obrigatória, nunca derivada do Host; ausente ou inválida = 503).
- **Desativar** exige confirmação se há atendimento `em_andamento` com atualização nos últimos 30 min. **Regerar a URL** exige digitar o nome do totem e a `url_versao` otimista; troca só o hash. **O `token_api` NÃO muda**: a URL antiga deixa de abrir a página, mas o token já embutido continua valendo na API até o totem ser desativado (risco aceito). **[C]** [T: teste_gestao_totens, teste_gestao_qa_totens]

### 13.3 Ordens de coleta (`usuario` e `admin`)

- **Sem máscara** de dados pessoais (motorista, CNH, placa em claro para ambos os perfis) e **sem rate limit por usuário** (decisões da F4). Abas: ativas, ativas há mais de 15 dias, inativas, baixas pendentes; filtros por cliente, número (exato ou prefixo de 3+ caracteres) e período; 25 por página. **[C]**
- **Ativar/inativar:** POST com `status_visto`; se o estado mudou, recusa (`estado_mudou`). Confirmação em **dois passos** quando: inativa OC com atendimento em andamento do mesmo cliente+número, ativa OC já baixada por atendimento concluído, ou ativa OC de cliente inativo. A auditoria é aberta ANTES do UPDATE externo; UPDATE por `id` com CAS de status. Banco externo fora: 503 + log. **[C]** [T: teste_gestao_ordens, teste_gestao_oc_dao]
- **Resolver baixa pendente:** um clique, sem confirmação; só resolve se a OC (cliente do atendimento + número) for **única e INATIVA**; recusas: `cliente_ausente`, `oc_inexistente`, `oc_ambigua`, `oc_ativa`, `externo_indisponivel`. **[C]**
- **PDF:** por POST, auditoria aberta antes do primeiro byte, caminho só por `OrdemColetaArquivoStorage::ler` (5 MiB, SHA-256 do registro, prefixo do CNPJ conferido), `attachment`, `no-store`, `nosniff`, CSP restritiva; arquivo ausente redireciona com mensagem; integridade divergente = 500 + log. **[C]**

### 13.4 Clientes e empresas (só admin)

- Escopo: clientes = `tb_cliente` do TOTEM (o banco externo não é escrito); empresas = `tb_empresa` (armazém do Talent). Cada ação roda sob `GET_LOCK` + transação, reconfere admin ativo, abre a auditoria dentro da transação. **[C]** [T: teste_gestao_cadastros, teste_gestao_qa_cadastros]
- **Clientes:** nome de 2 a 150 caracteres (UTF-8, sem controle/invisíveis); `razao_social_normalizada` recalculada ao criar/renomear (razão vazia ou igual à de outro cliente é recusada); CNPJ válido e **imutável** depois de criado; **CNPJ da UDLOG bloqueado**; ativar/inativar e excluir (físico) são ações separadas; excluir sempre exige confirmação (`andamento_confirmado` só muda a auditoria); inativar exige confirmação só com atendimento em andamento. Criar, renomear ou ativar um cliente que faça outro cliente ativo deixar de ser identificado sem ambiguidade no OCR (ou nascer ambíguo) exige confirmação em dois passos; a simulação é pulada acima de 500 ativos.
- **Empresas:** nome de 2 a 100 caracteres; precisa gerar slug de 1 a 16 alfanuméricos e **único** entre empresas; renomear não altera as URLs dos totens existentes; inativar com totens ativos exige confirmação; excluir **só sem totens vinculados**. **Empresa inativa faz o `finalizar` responder 500** ("Totem sem empresa/armazém configurado"). **[C]**

### 13.5 Auditoria e logs

- **Auditoria** (`tb_gestao_auditoria`, append-only): 25 ações de conjunto fechado, alvos `usuario`/`totem`/`sistema`/`ordem_coleta`/`oc_baixa`/`cliente`/`empresa`; `detalhe` só por chaves de conjunto fechado, **sem nome nem CNPJ**; resultado `PENDENTE` (aberto antes de gravar) -> `OK`/`SEM_EFEITO`/`ERRO`. O IP do ator é gravado em binário (o `detalhe` não tem dado pessoal, mas o IP existe). Recusas viram `SEM_EFEITO`. Ver ou listar URLs de totem e abrir a tela de logs não é auditado. **[C]**
- **Logs do sistema** (`tb_log_sistema`): categorias de catálogo fechado (22), mensagem fixa, contexto por allowlist, sem IP, sem texto livre, sem exceção bruta; nunca lança exceção; escrita na descarga da requisição por conexão dedicada. Tetos: 10 por requisição, 2000 por dia, 500 por dia por categoria (exceto `cron_*` e `log_suprimido`), 100000 no total, com registro `log_suprimido`. Throttle de 1 gravação por 60 s para `totem_nao_autorizado` e `n8n_anexo_recusado`. **[C]** [T: teste_log_sistema, teste_gestao_instrumentacao]
- **Não instrumentados por decisão:** banco do totem fora do ar, serviço de impressão do mini PC, `vio_reprovado` (só `error_log`), login, erros 4xx do usuário. **[C]**
- **Tela de logs (só admin, só GET):** 5 abas (API, Recebimento, Expedição, Cron, Gestão; sem "Todas"), 50 por página, filtros por allowlist, sem busca livre, sem exportar. "API" = falhas de integrações/infra sem atendimento. **[C]** [T: teste_gestao_logs, teste_gestao_qa_logs_tela]
- **Retenção de 90 dias** (`limpar-logs-gestao.php`, só CLI, `--dry-run`): poda `tb_log_sistema` e `tb_gestao_auditoria` de forma independente, em lotes de 500 (até 50 lotes por tabela e 240 s), com `GET_LOCK` não bloqueante. Abre a trilha `RETENCAO_EXECUTAR` ANTES de apagar e aborta se não conseguir; fecha com as contagens reais. A própria trilha também é podada em 90 dias. O único DELETE da auditoria está em `AuditoriaRetencaoDao`. **[C]** [T: teste_gestao_retencao]

---

## Divergências e pendências

### Divergências entre o `ia_development_state.md` e o código (o código vence)

1. Linha 3 do estado ainda lista F2 a F6 como pendentes e diz que as migrations 020/021 não foram aplicadas; entradas posteriores (08 e 09/10) registram F2 a F4 e F6 reduzida em produção e as migrations foram removidas do repositório. O cron de retenção de 90 dias já existe (`cron/limpar-logs-gestao.php`), ao contrário de "hoje SEM poda".
2. Estado: "dados pessoais mascarados, só admin revela" foi superado na F4 ("sem máscara"); o código não tem máscara nem "revelar" (só máscara de formato de CNPJ).
3. Estado: URL do RECEPCAO-01 "fixa"; entrada de 2026-10-08 registra a URL regerada e o `.bat` atualizado. Vale a mais recente.
4. Estado: "auditoria sem PII"; o `detalhe` não tem PII, mas o IP do ator é gravado em binário em `tb_gestao_auditoria.ip`.
5. Estado citava "27 estados" do front; referência corrigida para 26: o código tem 26 telas em `renderTela` (`rec_cnh` é só nome de etapa do backend, mapeado para `rec_cnh_qr`).
6. Estado: rotação de 270 graus "só na cópia do OCR, imagem original sem alteração"; no código a rotação é aplicada no canvas e o mesmo JPEG vai à prévia, ao servidor e ao OCR.
7. Estado (decisão de 2026-09-29): só candidato comprovadamente emitente/destinatário associa cliente e transportadora nunca identifica; o código **não classifica papel**: qualquer CNPJ candidato válido presente em `tb_cliente` ativo (exceto UDLOG) identifica (P05/P09 abertas).
8. Estado: "`finalizar` exige também `cliente` (Recebimento)"; o `finalizar` não confere `cliente_nome` (só a confirmação).
9. Estado descreve o cache VIO (`VIO_CACHE`) como capacidade; no código o cache não é chamado no fluxo QR-only, e `ClienteApiClient` é código morto.
10. Estado: a estrutura do banco externo é a do dump local em `docs/` (fora do Git), extraída da produção em 2026-10-10 (somente estrutura, sem dados), com `status`, `inativada_em` e `tb_ordem_coleta_arquivos`.
11. Estado diz serviço do Windows descartado; o repositório ainda contém `scripts/instalar-servico-windows.js` e `desinstalar-servico-windows.js` (legado) ao lado da tarefa de logon.
12. `TALENT_CHECKIN_ATIVO` e `CONCLUIR_EXIGE_NUMERO_NOTA` valem só quando iguais a `true` no código; o `.env.example` traz `false` em ambas. `TALENT_CHECKIN_ATIVO=true` em produção, confirmado pelo responsável do projeto em 2026-10-10 (resolve a divergência com o checklist, P37/P68). `CONCLUIR_EXIGE_NUMERO_NOTA`: decisão do usuário em 2026-10-11 de ativar em produção; o valor no servidor não foi confirmado. **[A]**

### Problemas funcionais registrados (não corrigidos)

- `ENVIO_INDETERMINADO` do Talent por timeout/falha de rede não tem saída (nem tela, nem cron); o atendimento fica `em_andamento` para sempre. O caso "2xx sem número de registro" está resolvido (atendimento cancelado e guichê da portaria).
- A portaria só identifica o caso "2xx sem número" pelo `tb_log_sistema` (`categoria_erro=resposta_ilegivel`); não há campo próprio. Baixa severidade.
- Cancelar não considera `talent_checkin_status`: cancelamento no meio do envio ao Talent pode ser sobrescrito por `ENVIADO`, sem teste.
- Tela "Dados da ordem" da Expedição permite editar campos que não são enviados nem persistidos.
- Sem teto de re-escaneio de CNH/CRLV no backend (só o front limita a 3).
- Token LGPD é consumido antes da consulta de OC: placa sem OC ou falha de consulta deixa atendimento `em_andamento`.
- Sem validação de formato de placa.
- Sem rate limit de falhas de token nas APIs do totem; limites de login não atômicos entre processos.
- Regerar a URL do totem não rotaciona o token da API (risco aceito pelo usuário).
- `S6`: exceção após o commit do UPDATE de status de OC fecha a auditoria como `ERRO` mesmo se o estado mudou.
- `ClienteDao::buscarPorTermo` usa LIKE sem escape; ver o estado para as demais pendências baixas.

### Decisões de produto (não serão tratadas)

- Retenção/limpeza de atendimentos, notas, fotos de notas de atendimentos concluídos e aceites LGPD: decisão de produto (2026-10-10): não será tratado. Comportamento atual descrito na seção 11.
- Validação de método HTTP nas APIs do totem (só `ordem-coleta-anexo.php` exige POST): decisão de produto (2026-10-10): não será tratado.

### Pendências de confirmação

- **[P]** Corpo de erro HTTP 400 do Talent.
- **[P]** Contrato do banco externo de coletas em `docs/db_gestao_coletas.md` (template vazio na parte de ordens por placa).
- **[P]** Aprovação do DPO sobre o termo LGPD (só relato no estado).
- **[P]** Calibração dos limiares do `RazaoSocialMatcher` (80/15) com dados reais.
- **[P]** Causa dos 48 mm úteis da etiqueta; deteção de `sem_papel` validada só para a TM-T88VII.
- **[P]** `STORAGE_PATH` gravável no cPanel (sem isso, 401 e recusas anônimas não são logados) e SAPI/FPM da Hostgator.
- **[P]** Existência de segundo usuário MySQL só para o DELETE da auditoria (não confirmado).

### Testes

- Os testes citados não foram reexecutados nesta documentação; o resultado "passa" vem do estado. Conhecidos: `teste_gestao_totem_f0` com 7 falhas (guarda do prepend e fim de linha CRLF), `teste_gestao_logs` com 1 falha por CRLF, `teste_consulta_ordem_coleta` com 12/17 por fixture do banco externo, `teste_talent_idor_finalizar` e `teste_concorrencia_finalizar_checkin` exigem `TALENT_CHECKIN_ATIVO` diferente de `true`.
- `teste_lgpd_aceite_backend_seguranca` depende de `tests/manual/_fixtures_lgpd.php`, que não existe no disco (arquivos `_*.php` são ignorados pelo git).
