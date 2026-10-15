# Plano de testes do totem-udlog

Base: HEAD `4188fee`, 2026-10-09; cenários do ajuste "Talent 2xx sem `nrRegAcesso`" acrescentados em 2026-10-11 (alterações ainda não commitadas). Este documento descreve **como testar**. Ele **não registra nenhum resultado desta rodada**: nenhum teste foi executado ao escrevê-lo. Contagens de verificações citadas ficam só na seção 5 ("Resultados históricos"), sempre com data/entrada de origem, e **nunca valem como aprovação atual**.

Documentos relacionados: [fluxos](fluxos/README.md), [APIs](apis/README.md), [banco de dados](banco-dados.md), [regras de negócio](regras-negocio.md), [implantação](implantacao.md).

## Legenda

| Marca | Significado |
|---|---|
| **[CÓDIGO]** | Confirmado lendo arquivos de teste/infra do repositório. |
| **[HISTÓRICO]** | Vem de registro histórico, não verificado nesta rodada. |
| **[PENDENTE DE CONFIRMAÇÃO]** | Sem evidência suficiente; nada foi presumido. |

Tipos de cenário: **AUTO** (script CLI), **AUTO-FRONT** (Node + Chrome headless), **MANUAL**, **FÍSICO** (equipamento real).

Quando documentos divergem, o código vence.

---

## 1. Montagem do ambiente de teste

### 1.1 Regras inegociáveis

1. **Nunca usar o banco de desenvolvimento `udlog_totem`** nem o **banco externo real de gestão de coletas**. Testes automatizados devem rodar só em banco descartável `qa_*`.
2. **Nunca chamar Talent, vio.api.br, n8n ou impressora real** em teste automatizado. Eles são substituídos por mocks/falsos locais (mock `php -S` em 127.0.0.1, transporte em memória, `execFile` mockado). **[CÓDIGO]**
3. Teste real contra terceiro (VIO, Talent) só com autorização prévia explícita do responsável; não repetir sem nova autorização. **[HISTÓRICO]**
4. Não existe PHPUnit nem `phpunit.xml` e não foi encontrado CI: os testes são **scripts CLI** que imprimem OK/FALHA por verificação e terminam com código de saída (0 = sem falha). **[CÓDIGO]**

### 1.2 Pré-requisitos

- PHP 8 CLI com `vendor/autoload.php` (`composer install`).
- MariaDB/MySQL **local** acessível com usuário que possa `CREATE DATABASE`/`DROP DATABASE`. As credenciais vêm de `QA_QR_DB_*` ou, na falta, de `DB_HOST/PORT/USER/PASS` do `.env` (o bootstrap nunca lê `DB_NAME`). **[CÓDIGO]**
- `php-cgi` para as suítes de Gestão e de anexo de OC por HTTP; extensão GD para JPEG sintéticos.
- Para os `*.js` de front: Node + `puppeteer` (instalado fora do projeto) e Chrome/Chromium (variável `CHROME` opcional). **[CÓDIGO]**
- Serviço de impressão: Node >= 22.17.1 e `npm install` em `servico-impressao-local/`.

### 1.3 Banco QA descartável e prepends fail-closed

| Peça | Papel **[CÓDIGO]** |
|---|---|
| `tests/manual/qa_db_bootstrap.php` | `qaDbCriar(prefixo)` cria `qa_<prefixo>_<hex8>`, carrega **só** `sql/schema.sql` (única fonte da estrutura; ver [banco-dados.md](banco-dados.md)) e devolve o PDO. `qaDbDropar` recusa nome sem prefixo `qa_`. |
| `tests/manual/qa_qr_exclusivo_bootstrap.php` | Cria/derruba só bancos `qa_qr_exclusivo_[a-f0-9]{8}`. |
| `qa_qr_exclusivo_prepend.php`, `qa_gestao_prepend.php`, `qa_oc_anexo_prepend.php` | `auto_prepend_file` **fail-closed**: sem nome de banco QA válido, saem com **exit 3**. Forçam `DB_NAME`, `STORAGE_PATH` e `GESTAO_COLETAS_DB_NAME` para valores QA em todo subprocesso (o Dotenv imutável não sobrescreve `$_ENV`). |
| `tests/manual/qa_gestao_infra.php` | Banco QA + storage temporário + usuários semeados + páginas **reais** via `php-cgi`. |
| `tests/manual/qa_gestao_oc_infra.php` | **Dois** bancos QA (totem + "externo" com a estrutura do dump embutida na infra). |
| `tests/manual/qa_oc_anexo_infra.php` | Banco QA fazendo o papel do banco externo + storage temporário. |
| `tests/manual/hardening_helpers.php` | `hdCriarAmbiente`: banco `qa_` + storage temporário; aborta se o banco não tiver prefixo `qa_`. |
| `tests/manual/teste_prepend_qa_guarda_externo.php` | Prova que os 3 prepends forçam `GESTAO_COLETAS_DB_NAME` para QA ou abortam com exit 3 (sem banco/rede). |

Banco QA é removido no `finally`/shutdown. Em caso de abort podem sobrar bancos `qa_*` (há registro de resíduos em P23/P53). **[HISTÓRICO]**

Verificação prévia sugerida do ambiente (sem tocar dados): rodar `teste_prepend_qa_guarda_externo.php` e conferir que nenhum nome de banco que não comece com `qa_` aparece nas mensagens.

### 1.4 Variáveis relevantes

| Variável | Uso |
|---|---|
| `QA_QR_DB_HOST/PORT/USER/PASS` | Credenciais do servidor MySQL local para criar bancos QA. |
| `QA_GESTAO_ENV_JSON` | Entrada do `qa_gestao_prepend.php` (ele recusa `DB_NAME` nessa chave; ver D2 na seção 6). |
| `QA_HEAD_TREE=<pasta>` | Em `teste_gestao_qa_independente.php`: liga a prova byte a byte de que o HTML do quiosque é idêntico ao do HEAD. A pasta deve conter a árvore do HEAD com o **mesmo fim de linha** da árvore de trabalho (autocrlf). Sem a variável, essa prova **não roda** (não é falha, é cobertura ausente). Procedimento no cabeçalho do arquivo. |
| `QA_RAZAO_PREPEND` | Opcional em `teste_razao_social_normalizacao.php` (mutação). |
| `GESTAO_CAPTURAS` | Pasta de capturas de tela de `gestao_layout.js`. |
| `CHROME`, `APPJS`, `APPCSS` | Caminhos opcionais dos testes `*.js` de front. |

### 1.5 Flag `TALENT_CHECKIN_ATIVO`

- O check-in no Talent é fail-closed: com a flag diferente de `true`, a finalização responde **503 `TALENT_CHECKIN_DESATIVADO`** sem chamar o Talent. O `.env.example` traz `TALENT_CHECKIN_ATIVO=false`. **[CÓDIGO]**
- Três suítes **exigem a flag não-true** no ambiente que elas carregam: `teste_concorrencia_finalizar_checkin`, `teste_talent_idor_finalizar`, `teste_talent_trava_doctos_pendente`. Com a flag em `true` elas falham (ver D5). **[HISTÓRICO]**
- As suítes com mock de Talent (`teste_talent_mensagem_api_finalizar`, `teste_e2e_recebimento_expedicao_mock`, `teste_talent_idempotencia`, etc.) ativam a flag **apenas no subprocesso**, apontando para o mock local; confirmar no cabeçalho de cada uma antes de rodar. **[PENDENTE DE CONFIRMAÇÃO]** (o mecanismo exato por suíte não foi lido).
- Nunca definir a flag como `true` com credenciais reais de Talent num ambiente de teste.

### 1.6 Ordem recomendada de execução

1. **Guardas** (sem banco): `teste_prepend_qa_guarda_externo.php`.
2. **Estáticos/ZERO**: `teste_totem_autocomplete_off`, `teste_contrato_qr_exclusivo_isolado`, `teste_vio_api_br_client`, `teste_validacao_jpeg_seguro`, `teste_talent_client_parsing`, `teste_hardening_cron_quarentena`, `teste_texto_etiqueta`, `teste_etiqueta_ajudante`, `teste_mensagem_api_e_flag_reimpressao`.
3. **Backend do totem em banco QA** (família 2.2 a 2.7).
4. **Gestão Totem** (família 2.8), por último entre os PHP por serem as mais lentas.
5. **Front/Chrome headless** (`*.js`).
6. **Serviço de impressão**: `npm test` em `servico-impressao-local/`.
7. **Manuais/físicos** (seção 4), só depois que o automatizado do mesmo tema estiver conhecido.

### 1.7 Leitura dos resultados

- Saída esperada: linhas OK por verificação; qualquer linha FALHA ou exit code diferente de 0 reprova a suíte.
- **Exit 3** = prepend fail-closed recusou o ambiente (banco QA ausente/nome inválido). Não é falha de produto: corrija o ambiente, nunca contorne a guarda.
- Suítes com "arquivo auxiliar ausente" (ver D6/D9) falham por infra, não por regra de negócio.
- `teste_oc_anexo_lacunas.php` pode sair com rc 0 mesmo com falhas (P58): ler as linhas FALHA, não só o código. **[HISTÓRICO]**
- `teste_status_processamento.php` é flaky por timing (P23): repetir uma vez antes de reportar. **[HISTÓRICO]**
- Registrar para cada suíte: data, HEAD, ambiente, contagem OK/FALHA. Só isso vira evidência.

---

## 2. Seção A: testes automatizados

### 2.0 Convenções comuns a todos os cenários A

- **Pré-condições comuns (PC-A):** ambiente da seção 1.2; servidor MySQL local acessível; nenhum teste usa produção. A suíte cria e derruba o próprio banco `qa_*` (salvo as marcadas ZERO).
- **Dados fictícios (DF-A):** todos criados pela própria suíte (totens, clientes, empresas, placas, CNPJs de teste, JPEGs/QRs sintéticos, PDFs sintéticos). Nada real. Quando a suíte usa marcadores (ex.: totem `TESTE-QA-IDCLI`, placa `TSTQA01`), eles são fictícios.
- **Resultado esperado padrão (RE-A):** todas as verificações OK, nenhuma linha FALHA, exit code 0, banco QA removido ao final.
- **Comando padrão:** `php tests/manual/<arquivo>.php` (PHP) ou `node tests/manual/<arquivo>.js` (front), a partir da raiz do projeto.
- Nas tabelas, "Ambiente" usa: **QA** (banco `qa_*`), **ZERO** (sem banco/rede), **DEV** (toca o banco dev; ver D7: **não rodar** sem isolar).

### 2.1 Segurança do ambiente de teste

| ID | Objetivo / risco coberto | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-AMB-01 | Prepends forçam banco externo QA ou abortam com exit 3; evita que teste toque o banco externo real | `php tests/manual/teste_prepend_qa_guarda_externo.php` | RE-A | ZERO |

### 2.2 Fluxos principais Expedição/Recebimento (ver [02](fluxos/02-expedicao.md), [03](fluxos/03-recebimento.md))

| ID | Objetivo / risco coberto | Comando | Esperado | Ambiente / dependência |
|---|---|---|---|---|
| A-FLX-01 | E2E mock Exp/Rec: VIO falso por factory, Talent mock; regressão do fluxo completo | `php tests/manual/teste_e2e_recebimento_expedicao_mock.php` | RE-A. Depende de `_caso_finalizar_talent_mock.php` e outros, **não versionados** (D9) | QA + mock local |
| A-FLX-02 | Contrato QR-only isolado: 1 POST VIO, sem anexo CNH/CRLV ao Talent, CAS | `php tests/manual/teste_contrato_qr_exclusivo_isolado.php` | RE-A | ZERO (doubles) |
| A-FLX-03 | QR-only integrado: controller+DAO reais, VIO/Talent falsos, prova negativa de PII em logs/respostas/tabelas | `php tests/manual/teste_fluxo_qr_exclusivo_integracao.php` | RE-A | QA |
| A-FLX-04 | Máquina de estados da Expedição (`avancarEtapa`) | `php tests/manual/teste_avancar_etapa_expedicao.php` | RE-A. **Possivelmente obsoleto** (cabeçalho de 2026-09-09, fluxo de upload); ver D8 | não classificado |
| A-FLX-05 | `salvarEtapa` casos `cliente`/`confirmacao` | `php tests/manual/teste_salvar_etapa_cliente_manual.php` | RE-A | QA |
| A-FLX-06 | Regressão de CNH digital em 1 página (QR-only) | `php tests/manual/teste_cnh_digital_1_pagina.php` | RE-A | não classificado |
| A-FLX-07 | Fluxo rec_cnh/rec_crlv | `php tests/manual/teste_fluxo_recebimento_documentos.php` | **Não executar sem isolar**: usa o dev e pode estar obsoleto (D7/D8) | DEV |
| A-FLX-08 | Sem fila de reenvio ao Talent (estático + QA) | `php tests/manual/teste_sem_fila_envio.php` | RE-A | estático + QA |
| A-FLX-09 | Etapa/ordem de coleta (consulta por placa, mais de uma OC em aberto) | `php tests/manual/teste_consulta_ordem_coleta.php` | RE-A; histórico registra falha por fixture externa (P24, D8) | QA |

### 2.3 Validações

| ID | Objetivo / risco coberto | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-VAL-01 | Limite de 5 notas (bloqueio) e exclusão de nota por `id_nota`, quarentena `.del`, lock, concorrência | `php tests/manual/teste_hardening_exclusao.php` | RE-A, **exceto E17 (P55, D1)** | QA |
| A-VAL-02 | `concluir-digitalizacao` retorna 422 atrás da flag `CONCLUIR_EXIGE_NUMERO_NOTA` | `php tests/manual/teste_hardening_concluir_422.php` | RE-A | QA |
| A-VAL-03 | `atualizarNumeroNota` respeita VARCHAR(20) (12 cenários) | `php tests/manual/teste_numero_nota_validacao_tamanho.php` | RE-A | não classificado |
| A-VAL-04 | Validação de JPEG (EOI, dimensão, GD indisponível). Risco residual aceito: JPEG truncado com EOI forjado | `php tests/manual/teste_validacao_jpeg_seguro.php` | RE-A | ZERO banco |
| A-VAL-05 | Corpo JSON escalar nas rotas `impressao/atendimento/nota` devolve 4xx, nunca 500 | `php tests/manual/teste_corpo_json_escalar.php` | RE-A | QA |
| A-VAL-06 | Regra de cliente C1-C15 (só `tb_cliente` ativa) | `php tests/manual/teste_hardening_cliente.php` | RE-A (ambiente: ver D7, cabeçalho diz QA) | QA (a confirmar) |
| A-VAL-07 | CNPJ, razão social, `identificarCliente` | `php tests/manual/teste_identificar_cliente.php` | **Usa o dev** (D7): não rodar sem isolar | DEV |
| A-VAL-08 | Normalização de razão social sem acentos + OCR fuzzy | `php tests/manual/teste_razao_social_normalizacao.php` | RE-A | QA |
| A-VAL-09 | QA adversarial da normalização (paridade ASCII em 10.000+ entradas) | `php tests/manual/teste_qa_normalizacao.php` | RE-A | QA |
| A-VAL-10 | Regras de CRLV e rebaixamento a MANUAL | `php tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php` | RE-A | QA |
| A-VAL-11 | `autocomplete="off"` nos campos do quiosque | `php tests/manual/teste_totem_autocomplete_off.php` | RE-A | ZERO |
| A-VAL-12 | Aceite LGPD (backend, 14 cenários) | `php tests/manual/teste_lgpd_aceite_backend_seguranca.php` | **Não roda**: falta `_fixtures_lgpd.php` (D6) | QA (pelo cabeçalho) |
| A-VAL-13 | Busca manual de cliente (Recebimento): `ClienteDao::buscarUnicoPorTermo` e a rota real `cliente.php?acao=buscar` via php-cgi; no máximo um cliente ativo (CNPJ por prefixo, nome por trecho, normalização), 0 ou mais de 1 devolvem lista vazia, só `nome` e `cnpj` | `php tests/manual/teste_busca_cliente_unica.php` | RE-A | QA (php-cgi) |

### 2.4 Concorrência (GET_LOCK, CAS, UNIQUE)

Os locks usam `GET_LOCK` (ex.: `DocumentoController` com chave por atendimento e timeout 0; DAOs da Gestão com `GET_LOCK(CONCAT(nome, MD5(DATABASE())), segundos)`). **[CÓDIGO]**

| ID | Objetivo / risco coberto | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-CON-01 | `obterLock` de documento (lock imediato, sem espera) | `php tests/manual/teste_lock_obter_lock_documento.php` | RE-A (cabeçalho diz dev; código usa `qa_db_bootstrap`: conferir, D8) | QA (pelo código) |
| A-CON-02 | Corrida real de 2 processos no `numero_nota` (UNIQUE `uk_atendimento_numero_nota`) | `php tests/manual/teste_concorrencia_numero_nota_duplicado.php` | RE-A: só um vence | não classificado |
| A-CON-03 | `concluido` terminal (409), CAS em concluir, lock sem `exit` dentro | `php tests/manual/teste_integridade_conclusao_atendimento.php` | RE-A | QA |
| A-CON-04 | Finalizar com 2 cliques (idempotência sob corrida) | `php tests/manual/teste_concorrencia_finalizar_checkin.php` | RE-A **somente com `TALENT_CHECKIN_ATIVO` não-true e fora do dev** (D5/D7) | DEV (`.env`) |
| A-CON-05 | CAS de 5 estados do Talent, corrida real, timeout vira `ENVIO_INDETERMINADO` | `php tests/manual/teste_talent_idempotencia.php` | RE-A | não classificado |
| A-CON-06 | `vio_api_br` CAS e cache | `php tests/manual/teste_vio_api_br_cas_e_cache.php` | RE-A | não classificado |
| A-CON-07 | 409 `OCR_EM_ANDAMENTO` e timeout de 120 s | `php tests/manual/teste_hardening_r4_ocr_em_andamento.php` | RE-A | QA |
| A-CON-08 | CAS concorrente da baixa/OC e da Gestão (2 processos) | `php tests/manual/teste_gestao_ordens.php` e `php tests/manual/teste_gestao_qa_ordens.php` | RE-A | QA (php-cgi) |
| A-CON-09 | Corridas em cadastros/totens da Gestão | `php tests/manual/teste_gestao_cadastros.php`, `teste_gestao_qa_cadastros.php`, `teste_gestao_totens.php`, `teste_gestao_qa_totens.php` | RE-A | QA |
| A-CON-10 | Anexo de OC: concorrência (`rodadas_concorrencia` configurável) | `php tests/manual/teste_oc_anexo_lacunas.php [rodadas_concorrencia=100]` | RE-A; ler linhas FALHA (P58) | QA |

### 2.5 Idempotência (`client_uid`, status Talent)

| ID | Objetivo / risco coberto | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-IDE-01 | `client_uid` idempotente, reconciliação, IDOR, concorrência real | `php tests/manual/teste_hardening_r4_uid_reconciliacao.php` | RE-A | QA |
| A-IDE-02 | Front: reenvio com mesmo `client_uid` e 409 (API stubada) | `node tests/manual/front_r4b_uid_409.js` | RE-A | sem banco (Chrome) |
| A-IDE-03 | Front real contra backend real (`php -S`, banco `qa_r4fb_*`, portas 8595-8599) | `node tests/manual/front_r4b_real.js` | RE-A | QA + Chrome |
| A-IDE-04 | Estados de envio Talent (ver A-CON-05) e `mensagem_api` | `php tests/manual/teste_talent_mensagem_api_finalizar.php` | RE-A | QA + mock |
| A-IDE-05 | Baixa de OC falha após check-in aceito: pendente de baixa; sem CNPJ não toca o externo | `php tests/manual/teste_ordem_coleta_pendente_baixa.php` | RE-A | QA |

### 2.6 Cancelamento e abandono ([09](fluxos/09-cancelamento-e-abandono.md))

| ID | Objetivo / risco coberto | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-CAN-01 | Abandono >24 h de `em_andamento`, limite 500, concorrência | `php tests/manual/teste_hardening_r4_abandono.php` | RE-A | QA |
| A-CAN-02 | Cron de quarentena (24 h) e `NotaArquivoStorage`, sem banco | `php tests/manual/teste_hardening_cron_quarentena.php` | RE-A | ZERO banco |
| A-CAN-03 | Regex ancoradas, cron com teto 500/execução, sem `scandir` | `php tests/manual/teste_hardening_r4_storage_cron.php` | RE-A | storage temp (+`.env`) |
| A-CAN-04 | Falha de `restaurar()` na compensação do excluir; log sanitizado | `php tests/manual/teste_hardening_r4_restaurar_log.php` | RE-A | QA |
| A-CAN-05 | Front R4 (cancelar, abandono, OCR cancelável) com API/Tesseract stubados | `node tests/manual/front_r4_f8_f9.js` | RE-A | sem banco (Chrome) |

### 2.7 Falhas de integração

| ID | Integração | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-INT-01 | VIO fora/indisponível, status de processamento (porta local fechada) | `php tests/manual/teste_status_processamento.php` | RE-A; flaky (P23): repetir 1 vez | QA |
| A-INT-02 | Queda de PDO em 7 cenários de processamento de documentos | `php tests/manual/teste_pdo_falha_processamento_documentos.php` | RE-A; depende de `_poison_pdo.php` e `_caso_pdo_boundary_e_lock.php` (D9) | QA |
| A-INT-03 | Cliente VIO com transporte mock | `php tests/manual/teste_vio_api_br_client.php` | RE-A | ZERO |
| A-INT-04 | Comparação diagnóstica VIO | `php tests/manual/teste_vio_comparacao_diagnostica.php` | RE-A | não classificado |
| A-INT-05 | Parsing de `TalentClient::checkin()` contra mock `php -S` 127.0.0.1 (câmera/scanner não envolvidos). Atualizado em 2026-10-11: 2xx sem `nrRegAcesso` utilizável (ausente, nulo, não-texto, vazio, só espaços, aninhado, corpo não-JSON/não-array, 204) lança `resposta_ilegivel`; `"0"`, `"00123"` e `"AB 12"` são aceitos | `php tests/manual/teste_talent_client_parsing.php` | RE-A | ZERO banco |
| A-INT-06 | Payload do Talent (`doctos[]`, `cnpjArmazem`, `tipoEmbDesemb`) | `php tests/manual/teste_talent_payload.php` | **Falhas conhecidas (P55, D1)** | não classificado |
| A-INT-07 | Anexos PDF do Talent | `php tests/manual/teste_talent_anexos_pdf.php` | **Falhas conhecidas (P55, D1)** | sem rede |
| A-INT-08 | Gate `TALENT_CHECKIN_ATIVO` fail-closed (espiã nas credenciais) | `php tests/manual/teste_talent_trava_doctos_pendente.php` | RE-A só com flag não-true; fora do dev (D5/D7) | DEV (`.env`) |
| A-INT-09 | Log do Talent sanitizado (allowlist de categorias) | `php tests/manual/teste_talent_log_sanitizado.php` | RE-A | usa `.env` |
| A-INT-10 | Correção do ajudante no Talent | `php tests/manual/teste_talent_correcao_ajudante.php` | RE-A | QA |
| A-INT-11 | Banco externo indisponível na consulta de OC | `php tests/manual/teste_consulta_ordem_coleta.php` (usa `_caso_indisponibilidade_banco_externo.php`) | RE-A (ver A-FLX-09) | QA |
| A-INT-12 | Anexo de OC (n8n): storage, Rn, `AuthServidor`, retenção | `php tests/manual/teste_oc_anexo_logica.php` | RE-A; usa `_caso_oc_anexo_receber.php` (D9) | QA |
| A-INT-13 | Anexo de OC por HTTP real (php-cgi): token, tamanho, tipo, duplicidade | `php tests/manual/teste_oc_anexo_http.php` | RE-A | QA + php-cgi |
| A-INT-14 | Sem fila de reenvio quando o Talent está fora (ver A-FLX-08) | idem A-FLX-08 | RE-A | QA |
| A-INT-15 | Talent 2xx sem `nrRegAcesso` utilizável: atendimento `cancelado` + `ENVIO_INDETERMINADO`, sem senha, OC da Expedição **não** baixada, 422 `TALENT_SEM_REGISTRO` (`dados.acao=guiche_portaria`, sem `senha`/`protocolo`/`mensagem_api`), log `talent_indeterminado` com `categoria_erro=resposta_ilegivel`, fotos em quarentena, 2ª chamada 400 sem novo POST, CAS perdido sem quarentena, não sobrescreve `bloqueado`/`concluido`. Banco QA descartável `qa_qr_exclusivo_<hex>`, servidor mock local e `TalentClient` real por subprocesso | `php tests/manual/teste_talent_sem_registro.php` | RE-A. Usa o subprocesso `_caso_finalizar_talent_sem_registro.php`, **ignorado pelo `.gitignore`** (`tests/manual/_*.php`): entra no repositório por `git add -f` (ver D9) | QA + mock local |
| A-INT-15 | Impressão (backend): etiqueta, flag de reimpressão, `MensagemApi` | `php tests/manual/teste_mensagem_api_e_flag_reimpressao.php` | RE-A | ZERO |

### 2.8 Segurança (IDOR, token, CSRF, RBAC, SQL injection, pastas, rate limit)

| ID | Objetivo / risco coberto | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-SEG-01 | IDOR em `salvarEtapa` (confirmação/cliente/ajudante) | `php tests/manual/teste_idor_salvar_etapa_manual.php` | RE-A, **só com banco QA forçado** [HISTÓRICO]; confirmar ambiente antes | ver histórico |
| A-SEG-02 | IDOR da rota de impressão | `php tests/manual/teste_impressao_idor.php` | RE-A | não classificado |
| A-SEG-03 | IDOR de `finalizar` (503 com flag desligada) | `php tests/manual/teste_talent_idor_finalizar.php` | RE-A só com flag não-true; fora do dev (D5/D7) | DEV (`.env`) |
| A-SEG-04 | Comparação de token em tempo constante (serviço de impressão) | `node tests/manual/_teste_auth_token_timing.js` | RE-A | ZERO (config temporária) |
| A-SEG-05 | Rate limit de `identificarCliente` com falha de PDO (marcador `RTL0917`) | `php tests/manual/teste_rate_limit_identificar_cliente_pdo.php` | RE-A; ambiente não classificado, provável dev (D7): **confirmar antes** | não classificado |
| A-SEG-06 | Gestão: login, sessão, CSRF/Origin, RBAC por rota, XSS/SQLi, logs sem segredo | `php tests/manual/teste_gestao_http.php` | RE-A | QA (php-cgi) |
| A-SEG-07 | Gestão em processo: IP, limite de falhas, política de senha, auditoria append-only, `AuthGestao`, SQLi | `php tests/manual/teste_gestao_unidade.php` | RE-A | QA |
| A-SEG-08 | Rodada corretiva F0/F1 (senha/token fora de log/trace, desbloquear, B1-B7) | `php tests/manual/teste_gestao_correcoes.php` e `teste_gestao_qa_correcoes.php` | RE-A | QA |
| A-SEG-09 | QA adversarial F0+F1 e prova do quiosque idêntico ao HEAD | `QA_HEAD_TREE=<pasta> php tests/manual/teste_gestao_qa_independente.php` | RE-A; sem `QA_HEAD_TREE` a prova byte a byte não roda (D4) | QA |
| A-SEG-10 | Página do totem: 404 uniforme, rate limit de falhas por IP, Cloudflare, cabeçalhos | `php tests/manual/teste_gestao_totem_f0.php` | **7 falhas conhecidas (D2)** | QA |
| A-SEG-11 | Token do totem nunca exposto; RBAC/CSRF/IDOR em totens | `php tests/manual/teste_gestao_totens.php`, `teste_gestao_qa_totens.php` | RE-A | QA |
| A-SEG-12 | CLI `tools/criar-admin.php`: recusa fora do CLI/sem terminal/senha por argumento | `php tests/manual/teste_gestao_cli_admin.php` | RE-A (prompt sem eco **não** é exercitado: ver B-GES-01) | QA |
| A-SEG-13 | Anexo de OC: `AuthServidor`, token inválido | ver A-INT-12/13 | RE-A | QA |
| A-SEG-14 | PII nunca em logs/colunas/respostas | `php tests/manual/teste_sanitizacao_logs_documento_nota.php` e A-FLX-03 | RE-A | QA (pelo código; D8) |
| A-SEG-15 | Exposição de pastas: `storage/` fora do `public_html`; storage temporário nos testes | coberto indiretamente por A-CAN-03 e A-INT-12/13 | **PENDENTE DE CONFIRMAÇÃO**: não há suíte dedicada a acesso direto por URL a `storage/` | n/a |
| A-SEG-16 | Layout/UX/segurança das telas da Gestão em Chrome headless | `node tests/manual/gestao_layout.js` | RE-A; confirmações/copiar/mostrar senha dependem de JS (risco aceito) | QA + Chrome |

### 2.9 Gestão Totem ([fluxos](fluxos/README.md))

| ID | Área | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-GES-01 | Log central F3a (`tb_log_sistema`, `LogCatalogo`, `LogSistema`) | `php tests/manual/teste_log_sistema.php` | RE-A | QA |
| A-GES-02 | Logs: QA adversarial e retenção de 90 dias (CLI real + php-cgi) | `php tests/manual/teste_gestao_qa_logs.php` | RE-A | QA |
| A-GES-03 | Retenção: `AuditoriaRetencaoDao`, `cron/limpar-logs-gestao.php` (nenhum cron é ativado) | `php tests/manual/teste_gestao_retencao.php` | RE-A | QA |
| A-GES-04 | Instrumentação F3b: pontos instrumentados, PII nunca em coluna/`error_log`, 401 com contador | `php tests/manual/teste_gestao_instrumentacao.php` | RE-A | QA |
| A-GES-05 | Telas de logs (RBAC, filtros whitelist, paginação, XSS) | `php tests/manual/teste_gestao_logs.php` | **1 falha conhecida por CRLF (D3)** | QA |
| A-GES-06 | Telas de logs, QA adversarial | `php tests/manual/teste_gestao_qa_logs_tela.php` | RE-A | QA |
| A-GES-07 | OC: baixa por CLIENTE+número, `OrdemColetaGestaoDao`, CAS, baixas pendentes (dois bancos QA) | `php tests/manual/teste_gestao_oc_dao.php` | RE-A | QA |
| A-GES-08 | Telas de OC e PDF (auditoria antes do 1º byte) | `php tests/manual/teste_gestao_ordens.php` | RE-A | QA |
| A-GES-09 | Clientes/empresas: CRUD, auditoria, aviso de ambiguidade do OCR, nomes exóticos | `php tests/manual/teste_gestao_cadastros.php`, `teste_gestao_qa_cadastros.php` | RE-A | QA |
| A-GES-10 | Unidade e totens (código `NOME-EMPRESA-HASH16`, regerar URL, `TOTEM_URL_BASE`) | `php tests/manual/teste_gestao_unidade.php`, `teste_gestao_totens.php` | RE-A | QA |
| A-GES-11 | Autocomplete de cliente com teclado virtual/físico (`cliente.php` mockado); inclui a regra da busca única no front (no máximo uma sugestão; 0, 2 ou formato inesperado = nenhuma; sem seleção automática; toque confirma; resposta de termo antigo descartada) e o limite de `maxLength` no `digitar` | `node tests/manual/cliente_teclado_virtual.js` | RE-A | sem banco (Chrome) |
| A-GES-12 | Layout e QR do totem (frame simulado). Cobre, desde 2026-10-11, a tela `guiche_portaria` (título e texto com acentos, um único botão "Voltar ao início", sem retry/ajudante/impressão, barra Cancelar oculta, `cancelar` nunca chamado, 2º `finalizar` 400/500/422 mantém a tela, inatividade de 180 s volta ao início sem aviso) em Expedição e Recebimento | `node tests/manual/vio_captura_layout.js` | RE-A | sem banco (Chrome) |
| A-GES-13 | Títulos dos QR: as 4 telas (`exp_cnh_qr`, `exp_crlv_qr`, `rec_cnh_qr`, `rec_crlv_qr`) têm a faixa `.titulo-doc` (36 px, peso 800, texto branco; CNH azul `#0179AD`, CRLV grafite `#3A3A3A`) | `node tests/manual/vio_captura_layout.js` | RE-A | sem banco (Chrome) |
| A-GES-14 | Mensagem de próxima nota em `rec_digitaliza`: texto exato nas notas 1 a 4 e na 5ª (limite, captura desabilitada), some em "Capturar nota"/"Refazer", não aparece durante o envio nem em erro de upload ou de rede, nos 3 viewports | `node tests/manual/vio_captura_layout.js` | RE-A | sem banco (Chrome) |
| A-GES-15 | Placa: Recebimento com `maxlength=7` e sem `placeholder`, placa de 6 ou 8 caracteres não chama `iniciar` e mostra "A placa deve ter 7 caracteres"; Expedição sem `placeholder` e sem `maxlength` | `node tests/manual/vio_captura_layout.js` | RE-A | sem banco (Chrome) |
| A-GES-16 | CPF do ajudante: `maxlength=11`, `inputmode=numeric`, 10 dígitos ou vazio não chama `salvar-etapa` (nem no modo de correção) e mostra "O CPF deve ter 11 números"; nome só letras | `node tests/manual/vio_captura_layout.js` | RE-A | sem banco (Chrome) |
| A-GES-17 | Campos do motorista (CNH manual na Expedição e no Recebimento e "Confirme os dados"): nome só letras e espaço; CPF só números (11; valor pré-preenchido só com dígitos); validade com máscara `DD-MM-AAAA` (`20082035` vira `20-08-2035`), rótulo "Validade (DD-MM-AAAA)", exibição em `DD-MM-AAAA` e envio como `AAAA-MM-DD` | `node tests/manual/vio_captura_layout.js` | RE-A | sem banco (Chrome) |
| A-GES-18 | Validade incompleta, inexistente (31-02-2030) ou com mês 13 não chama a API e mostra "Informe a validade no formato DD-MM-AAAA" | `node tests/manual/vio_captura_layout.js` | RE-A | sem banco (Chrome) |

### 2.10 Impressão e equipamentos (automatizado, sem hardware)

| ID | Objetivo / risco coberto | Comando | Esperado | Ambiente |
|---|---|---|---|---|
| A-IMP-01 | Serviço de impressão: validação de `printSettings`, falta de papel pela fila do spooler, nome base do documento, listador de impressoras | `cd servico-impressao-local && npm test` (equivale a `node --test tests/print-settings.test.js tests/verificar-fila.test.js tests/verificar-fila-caminho.test.js tests/listar-impressoras.test.js`) | Todas as suítes `node --test` passam, 0 falhas. Não toca impressora (README/estado não indicam uso de hardware; corpo dos testes não foi lido) | Node >= 22.17.1 |
| A-IMP-02 | Timeout/kill do job de impressão com `execFile` mockado (nunca invoca o binário real nem impressora) | A partir da raiz do projeto: `node -r ./tests/manual/_preload-mock-execFile.js ./tests/manual/teste_timeout_kill_isolado.js` | RE-A | ZERO |
| A-IMP-03 | Etiquetas 80x80, texto <= 49 mm, ASCII; `TextoEtiqueta` | `php tests/manual/teste_etiqueta_ajudante.php`, `php tests/manual/teste_texto_etiqueta.php` (usa `pdftotext`) | RE-A | ZERO |
| A-IMP-04 | `gerarEtiqueta` com `destinatario` | `php tests/manual/teste_etiqueta_ajudante_qa.php` | RE-A | QA |
| A-IMP-05 | Geração de etiqueta e dados | `_caso_impressao_gerar_etiqueta.php` (auxiliar de outras suítes) | não rodar isolado | n/a |
| A-IMP-06 | JPEG sintético / QR sintético (auxiliares) | `tests/manual/qr_sintetico.js`, `fixtures_jpeg_seguro.php` | auxiliares, não são suítes | n/a |

Lacuna (ver seção 6): não há teste automatizado de rota HTTP, CORS ou 409 `sem_papel` ponta a ponta do serviço de impressão.

---

## 3. Convenções dos testes manuais e físicos

- **Pré-condição geral (PC-B):** build/commit sob teste identificado e anotado; ambiente de **homologação ou totem de teste**, nunca dados reais de motoristas. Para equipamento: mini PC e impressora do totem conforme [implantação](implantacao.md).
- **Dados fictícios (DF-B):** motorista, CPF, placa, CNPJ, número de nota e OC de teste criados para a rodada. QR/CNH/CRLV de teste: apenas documentos fictícios/sintéticos; **não usar documentos reais de terceiros**.
- **Proibido sem autorização prévia do responsável:** imprimir etiqueta real, chamar Talent real, POST real ao vio.api.br, qualquer POST em produção. Os cenários "sem POST real" só observam (GET/páginas).
- Registrar evidência por cenário: data, HEAD/versão, responsável, resultado (passou/falhou/não executado), observações. Sem registro, o cenário continua **pendente**.
- O estado de cada item citado vem de registro histórico (seção 5), não de execução nesta rodada.

---

## 4. Seção B: testes manuais/físicos

### B-IMP-01 Impressão real de uma etiqueta no mini PC
- **Objetivo / risco:** confirmar que o serviço local imprime no equipamento do totem; os testes automatizados não tocam hardware.
- **Pré-condições:** serviço de impressão instalado e ativo no mini PC; impressora térmica configurada ([implantação](implantacao.md)); origem do totem em `origensPermitidas`; **autorização prévia do responsável**. **[HISTÓRICO]** a impressão foi validada fisicamente em 2026-10-06, mas o README do serviço ainda a trata como pendente (divergência, seção 6).
- **Dados:** etiqueta de TESTE (`npm run teste:impressao` do serviço) ou atendimento fictício.
- **Passos:** (1) sem flag, `npm run teste:impressao` faz só `GET /saude` e `/impressoras` (não imprime); (2) com autorização, `npm run teste:impressao -- --imprimir`.
- **Esperado:** `/saude` e `/impressoras` respondem; etiqueta de TESTE legível; o token nunca aparece na saída.
- **Tipo:** FÍSICO. **Dependência:** mini PC, impressora, papel, Node.

### B-IMP-02 Falta de papel real (P47, aberta)
- **Objetivo / risco:** validar 409 `sem_papel` pela fila do spooler, o fluxo e o alerta no front; evitar motorista sem etiqueta e sem aviso.
- **Pré-condições:** B-IMP-01 aprovado nesta rodada; impressora sem papel (ou tampa aberta); autorização.
- **Dados:** atendimento fictício que chegue à impressão.
- **Passos:** retirar o papel; disparar a impressão pelo totem; observar a resposta do serviço e a mensagem no front; recolocar papel e reimprimir.
- **Esperado:** 409 `sem_papel`; alerta no front com opção de reimpressão; nenhum job preso na fila depois de recolocar o papel. **[HISTÓRICO]** em 2026-10-06 o 409 foi validado só com o job removido; falta o teste com falta de papel real.
- **Tipo:** FÍSICO. **Dependência:** impressora real.

### B-IMP-03 Duas etiquetas em sequência, motorista e ajudante (P47, aberta)
- **Objetivo / risco:** duas impressões consecutivas, corte e ordem; segunda etiqueta perdida ou sobreposta.
- **Pré-condições:** B-IMP-01 aprovado; atendimento fictício com ajudante.
- **Passos:** concluir o atendimento com ajudante; observar as etiquetas.
- **Esperado:** 2 etiquetas, na ordem esperada, texto dentro da largura útil (~50,8 mm, P54), sem acento quebrado.
- **Tipo:** FÍSICO. **Dependência:** impressora, papel.

### B-IMP-04 Medidas finais do papel (P54, aberta)
- **Objetivo / risco:** confirmar tamanho total, cortes e posição; largura útil ~50,8 mm.
- **Pré-condições:** B-IMP-01 aprovado. **Passos:** imprimir etiqueta de TESTE (opções `--largura=`/`--altura=`) e medir. **Esperado:** medidas finais **PENDENTE DE CONFIRMAÇÃO** (valor-alvo não definido nesta consulta). **Tipo:** FÍSICO.

### B-QR-01 Leitura de QR com o scanner Netum (P47/P32, abertas)
- **Objetivo / risco:** confirmar que o Netum SD-2000 lê o QR de CNH digital/CRLV de teste no Chrome kiosk; hoje só há cenários sintéticos e exemplos locais não decodificaram por nenhum método.
- **Pré-condições:** Netum conectado como videoinput; permissão de câmera concedida (B-CAM-01).
- **Dados:** QRs de documentos fictícios/de teste.
- **Passos:** abrir a etapa de captura; apresentar o QR; observar leitura, tempo e mensagem de erro com QR ilegível; repetir com luz fraca e QR inclinado.
- **Esperado:** QR válido lido; QR ilegível gera mensagem clara sem travar; preenchimento manual disponível. O roteiro físico de 20 itens (P32) **PENDENTE DE CONFIRMAÇÃO**: o conteúdo dos 20 itens não foi localizado nesta consulta.
- **Tipo:** FÍSICO. **Dependência:** Netum, Chrome kiosk.

### B-QR-02 Leitor de scanner sem sinal
- **Objetivo / risco:** totem travado sem saída quando o Netum está desconectado. **Pré-condições:** B-QR-01 aprovado. **Passos:** desconectar o scanner durante a captura. **Esperado:** mensagem clara e caminho alternativo (preenchimento manual), sem loop de erro. **Tipo:** FÍSICO.

### B-OCR-01 OCR no mini PC (P33, aberta)
- **Objetivo / risco:** medir tempo, precisão e cancelamento do OCR client-side no hardware real.
- **Pré-condições:** mini PC do totem; nota fiscal fictícia/de teste. **Passos:** fotografar a nota, aguardar o OCR, cancelar no meio, repetir. **Esperado:** tempo aceitável (limite **PENDENTE DE CONFIRMAÇÃO**, não definido); cancelamento libera a tela; 409 `OCR_EM_ANDAMENTO` tratado. Linha de base de produção de 2026-09-30 está registrada. **Tipo:** FÍSICO.

### B-CAM-01 Câmera e permissão no Chrome kiosk (P36, aberta)
- **Objetivo / risco:** saber se a permissão de câmera persiste após reinício do Chrome kiosk e do mini PC. **Passos:** conceder a permissão; reiniciar o Chrome kiosk e o mini PC; abrir a captura. **Esperado:** sem prompt, ou comportamento documentado. **Tipo:** FÍSICO.

### B-CAM-02 Câmera indisponível
- **Objetivo / risco:** tela em branco/travamento com câmera desconectada ou permissão negada. **Passos:** desconectar a câmera ou negar a permissão e entrar na captura. **Esperado:** mensagem clara e caminho alternativo. **Tipo:** FÍSICO.

### B-TEC-01 Teclado virtual
- **Objetivo / risco:** digitar placa, nome e cliente (autocomplete) no teclado virtual em tela touch; teclado cobrindo campos. **Esperado:** autocomplete funciona, campos visíveis. **[HISTÓRICO]** confirmado fisicamente pelo usuário em 2026-10-08 (histórico; repetir se a tela mudar). **Tipo:** FÍSICO. Automatizado relacionado: A-GES-11.

### B-TOT-01 Campos e telas do totem alterados recentemente (conferência no quiosque)
- **Objetivo / risco:** conferir no quiosque real (monitor touch em retrato, teclado virtual) o que só os testes headless simulam. **Pré-condições:** totem de **teste**; dados fictícios; Talent e impressão desligados ou em mock. **Tipo:** MANUAL/FÍSICO. **Automatizados relacionados:** A-VAL-13, A-GES-11, A-GES-13 a A-GES-18. **Esperado:** conforme [02](fluxos/02-expedicao.md), [03](fluxos/03-recebimento.md), [04](fluxos/04-notas-e-ocr.md) e [05](fluxos/05-cnh-crlv-qr-vio.md). Verificar, tela a tela:
  1. **Placa:** campos da Expedição e do Recebimento sem texto de exemplo; no Recebimento o teclado virtual não passa de 7 caracteres e 6 ou 8 caracteres mostram "A placa deve ter 7 caracteres".
  2. **Ajudante:** nome só com letras e espaço; CPF só com números, no máximo 11; "Confirmar" com menos de 11 mostra "O CPF deve ter 11 números".
  3. **CNH manual e "Confirme os dados":** nome só com letras e espaço; CPF só números (11); validade com hífens automáticos (digitar `20082035` mostra `20-08-2035`); validade incompleta ou inexistente (31-02-2030) mostra "Informe a validade no formato DD-MM-AAAA"; na confirmação, a validade vinda do sistema aparece em `DD-MM-AAAA`.
  4. **QR coloridos:** "Leia o QR code da CNH" em faixa azul e "Leia o QR code do CRLV" em faixa grafite, legíveis, nas quatro telas (Expedição e Recebimento).
  5. **Próxima nota (Recebimento):** após salvar cada nota, faixa azul com "Nota N de 5 salva! ..."; na 5ª, a mensagem de limite; some ao tocar "Capturar nota" ou "Refazer"; não aparece em erro de upload.
  6. **Busca de cliente (Recebimento, OCR sem identificar):** digitar parte do nome ou do CNPJ de um cliente ativo único mostra uma sugestão, que só vale ao tocar; termo com 0 ou mais de 1 correspondência não mostra nada.
  7. **Guichê da portaria:** ver B-INT-02.

### B-INA-01 Inatividade, cancelamento e recarga da página (sem retomada)
- **Objetivo / risco:** timeout de inatividade, abandono e comportamento após F5 no meio do atendimento (não existe retomada: P38).
- **Passos:** iniciar atendimento; deixar ocioso até o aviso e até o encerramento; em outro atendimento, recarregar a página no meio de uma etapa.
- **Esperado:** inatividade volta à tela inicial e cancela conforme [fluxo 09](fluxos/09-cancelamento-e-abandono.md); o reload (F5) volta à tela LGPD, porque o estado do front fica só em memória; o atendimento anterior permanece `em_andamento` até ser cancelado ou abandonado por 24 h; **não há retomada** (P38). Tempos: ver o fluxo (não repetidos aqui).
- **Tipo:** MANUAL (totem de teste). Automatizados relacionados: A-CAN-01, A-CAN-05, A-IDE-03.

### B-E2E-01 Ponta a ponta no totem (Expedição e Recebimento)
- **Objetivo / risco:** percorrer o atendimento completo em cada modalidade; regressões que só aparecem com hardware real.
- **Pré-condições:** totem de **teste** com URL própria; Talent em modo de teste ou `TALENT_CHECKIN_ATIVO` desligada (então a finalização deve devolver 503 `TALENT_CHECKIN_DESATIVADO`, comportamento esperado); impressão só com autorização.
- **Dados:** motorista, placa, cliente/empresa, OC e notas fictícios.
- **Passos:** LGPD, identificação, Expedição (ordem de coleta, notas incluindo uma 6ª para ver o bloqueio, conclusão); repetir em Recebimento com CNH/CRLV por QR. Bordas: mais de uma ordem de coleta em aberto; Talent fora do ar; câmera indisponível.
- **Esperado:** conforme [02](fluxos/02-expedicao.md), [03](fluxos/03-recebimento.md) e [07](fluxos/07-checkin-talent.md); com Talent fora, a falha é reportada e **não há fila de reenvio** (removida).
- **Tipo:** FÍSICO/MANUAL.

### B-INT-01 Falhas de integração induzidas
- **Objetivo / risco:** observar a tela do totem com VIO/n8n/banco externo/Talent indisponíveis, desligando o mock ou o ambiente de teste, nunca derrubando produção. **Esperado:** mensagens sanitizadas, caminho manual, sem 500 visível. Automatizados relacionados: seção 2.7. **Tipo:** MANUAL.

### B-INT-02 Talent 2xx sem número de registro: tela do guichê da portaria
- **Objetivo / risco:** conferir no totem de teste a tela do guichê quando o Talent responde 2xx sem `nrRegAcesso` utilizável (decisão de 2026-10-11).
- **Pré-condições:** totem de **teste** apontando para o mock do Talent (`tests/manual/_router_talent_mock.php`) ou para homologação capaz de devolver 2xx sem número; `TALENT_CHECKIN_ATIVO=true` apenas nesse ambiente; **nunca** produção nem Talent real sem autorização. Dados fictícios.
- **Passos:** percorrer um atendimento (Expedição e, em outra rodada, Recebimento) até a confirmação e a resposta sobre o ajudante; com o mock configurado para 2xx sem `nrRegAcesso` (ou corpo vazio/204), deixar o totem chamar `finalizar`; na tela final, tocar em "Voltar ao início"; repetir deixando 180 s sem tocar.
- **Esperado:** tela com "Não foi possível concluir seu check-in." e "Dirija-se ao guichê da portaria."; um único botão "Voltar ao início"; sem "Tentar novamente", sem ajudante, sem impressão e sem barra "Cancelar atendimento"; "Voltar ao início" leva à tela LGPD; após 180 s o totem volta ao início sem o aviso "Ainda está aí?". No banco de teste: atendimento `cancelado`, `talent_checkin_status='ENVIO_INDETERMINADO'`, `talent_senha` vazia; na Expedição a OC segue ATIVA; há linha de log `talent_indeterminado` com `categoria_erro=resposta_ilegivel`.
- **Tipo:** MANUAL (totem de teste). Automatizados relacionados: A-INT-05, A-INT-15, A-GES-12.

### B-DEP-01 Smoke pós-deploy sem POST real
- **Objetivo / risco:** confirmar a publicação sem criar dados nem acionar terceiros; deploy quebrado ou pasta exposta.
- **Pré-condições:** deploy concluído conforme [implantação](implantacao.md).
- **Passos (somente leitura):** (1) abrir a URL do totem de teste e ver que a página carrega; (2) URL inválida de totem dá 404 uniforme; (3) abrir o login da Gestão e conferir HTTPS e cabeçalhos; (4) conferir que `storage/` não é acessível por URL; (5) `GET /saude` do serviço de impressão no mini PC; (6) conferir crons agendados e o valor decidido de `TALENT_CHECKIN_ATIVO`.
- **Esperado:** tudo responde como previsto; nenhum POST de negócio enviado; nenhum dado criado.
- **Tipo:** MANUAL. **Dependência:** produção/Hostgator, Cloudflare.

### B-GES-01 Gestão: login e `criar-admin`
- **Objetivo / risco:** login, bloqueio por tentativas, política de senha, CSRF, perfis; prompt sem eco de `tools/criar-admin.php`, que o teste automático não exercita por exigir terminal real. **Esperado:** senha sem eco; recusa de senha por argumento. **Tipo:** MANUAL. Automatizados: A-SEG-06 a A-SEG-12.

### B-GES-02 Gestão: totens, logs, OCs, clientes/empresas e retenção
- **Objetivo / risco:** percorrer as telas com usuário de cada perfil em ambiente de teste: criar/regerar URL de totem (token nunca exibido), consultar logs com filtros, abrir PDF de OC, CRUD de cliente/empresa, conferir a retenção. **Esperado:** RBAC respeitado; ações auditadas. **Tipo:** MANUAL. Automatizados: seção 2.9.

### B-SEG-01 Verificações só possíveis em produção
- **Objetivo / risco:** Argon2id disponível, cookie `Secure`, Cloudflare e `REMOTE_ADDR` correto, `no-store` do quiosque. **[HISTÓRICO]** listadas como verificáveis só em produção. **Tipo:** MANUAL. **Dependência:** Hostgator, Cloudflare.

### B-REAL-01 Testes reais de terceiros (somente com nova autorização)
- VIO real (1 CNH digital, 1 POST) e Talent real já foram executados com autorização em 2026. **Não repetir sem nova autorização.** Não fazem parte deste plano.

---

## 5. Resultados históricos (NÃO são aprovação atual)

Todas as contagens abaixo são registros históricos de datas passadas e **nenhuma foi reexecutada nesta rodada**. Antes de citar como "aprovado", é preciso evidência desta rodada.

| Suíte | Contagem histórica | Origem |
|---|---|---|
| Gestão: cadastros / qa_cadastros | 534 / 99 | bateria de 2026-10-09 |
| Gestão: totens / qa_totens | 272 / 215 | bateria de 2026-10-09 (valor pós-higienização) |
| Gestão: http / correcoes / unidade / retencao | 168 / 233 / 254 / 46 | bateria de 2026-10-09 |
| `teste_log_sistema` / qa_logs | 89 / 116 | bateria de 2026-10-09 |
| Gestão: ordens / qa_ordens / oc_dao | 374 / 38 / 156 | bateria de 2026-10-09 |
| Gestão: instrumentacao / logs | 106 / 191 | bateria de 2026-10-09 (F4) |
| Gestão: qa_logs_tela | 177 | entradas de 2026-10-08 (F3c) |
| razao_social / qa_normalizacao | 74 / 3 | bateria de 2026-10-09 |
| `teste_gestao_qa_independente` | 286 | bateria de 2026-10-09 |
| `gestao_layout.js` | 5682 (F6) e 4037 (F4) | bateria de 2026-10-09 |
| `vio_captura_layout.js` | 1745 | bateria de 2026-10-09 |
| `vio_captura_layout.js` (com a tela `guiche_portaria`) | 1847 (102 novas) | entrada de 2026-10-11 (relato do QA; histórico) |
| `teste_busca_cliente_unica` | 28 | relato anterior ao deploy de 2026-10-16 (data da execução não registrada; histórico) |
| `cliente_teclado_virtual.js` (inclui a busca única) | 23 | relato anterior ao deploy de 2026-10-16 (data não registrada; histórico) |
| `vio_captura_layout.js` (títulos QR, próxima nota, placa, ajudante e campos do motorista) | 1958 no commit `82ad36d`; 2030 no último estado do código, ainda não commitado | relato anterior ao deploy de 2026-10-16 (data não registrada; histórico) |
| `teste_talent_sem_registro` | 142 | execução de 2026-10-11 após os ajustes de CAS e de cobertura (relato do implementador e reexecução; histórico, não é aprovação futura) |
| e2e mock / QR integração / sem_fila / salvar_etapa_cliente | 38 / 50 / 49 / 18 | bateria de 2026-10-09 |
| OC anexo: lógica / http / lacunas | 183 / 59 / 59 | bateria de 2026-10-09 |
| `autocomplete_off` | 14 | bateria de 2026-10-09 |
| Serviço de impressão (`npm test`) | 33 (5+10+9+9) | 2026-10-06; 33 em 2026-10-08; 15 em 2026-10-02 |
| Com flag desligada: `concorrencia_finalizar_checkin` / `talent_idor_finalizar` / `talent_trava_doctos_pendente` | 8/8, 10/10, 18/18 | registro de D5 |
| `teste_gestao_totem_f0` com as duas causas neutralizadas em cópia | 53/0 | registro de D2, 2026-10-09 |

Observações:
- Os valores pós-higienização (272, 215, 233, 89, 116, 74, 3, 49, 183) vêm de entradas que registram só a mudança de contagem, **não** uma nova execução completa.
- Para a regressão pós-`d783768`/`9d44513`, só há registro de vio_layout 1745, e2e 38, QR 50, sem_fila 53 e qa_independente 286.
- Não há registro de bateria completa após o HEAD `4188fee`.
- Relato recente, sem data de execução registrada e sem reexecução ao escrever esta documentação: `teste_gestao_unidade` 254, `teste_gestao_http` 168 e `gestao_layout.js` 5682 (relatadas como resultado recente); `teste_talent_sem_registro` 142. Histórico, não é aprovação atual.
- As duas linhas de 2026-10-11 (1847 e 93) são o relato das revisões de QA, segurança e UX do ajuste "Talent 2xx sem `nrRegAcesso`", sobre alterações ainda não commitadas; **não foram reexecutadas ao escrever este documento** e não valem como aprovação atual. O `teste_talent_client_parsing` foi atualizado na mesma alteração; não há contagem registrada para ele neste documento.

---

## 6. Dívidas e limitações de teste

| ID | Dívida / limitação | Efeito no plano |
|---|---|---|
| D1 | **P55 [ABERTA]** (HEAD `3bc0497`): `teste_hardening_exclusao` E17, `teste_talent_payload` (41/45) e `teste_talent_anexos_pdf` (15/19) esperam anexos CNH/CRLV que o QR-only não gera; testes desatualizados. | A-VAL-01, A-INT-06, A-INT-07 têm falhas conhecidas; não são regressão nova. Contagens históricas. |
| D2 | `teste_gestao_totem_f0`: **7 falhas** desde `d1ffda5`. Causas: (1) a guarda de `qa_gestao_prepend.php` recusa `DB_NAME` em `QA_GESTAO_ENV_JSON`, o que conflita com o teste F0, e o `fwrite(STDERR)` da guarda gera Fatal no php-cgi (HTTP 500) em vez de exit 3; (2) `public/totem/index.php` em CRLF na árvore de trabalho (autocrlf) contra LF no HEAD. **Correção descartada pelo usuário.** | A-SEG-10 reprova nesta configuração. |
| D3 | `teste_gestao_logs`: **1 falha** só por fim de linha (CRLF no Windows; passa em LF), mesma causa de D2(2). Não corrigida por decisão do usuário. | A-GES-05. |
| D4 | `QA_HEAD_TREE` obrigatória para a prova byte a byte do quiosque em `teste_gestao_qa_independente`, com fim de linha igual ao da árvore de trabalho. | Sem ela, cobertura ausente. |
| D5 | Três suítes exigem `TALENT_CHECKIN_ATIVO` não-true e carregam o `.env` (esperam 503 `TALENT_CHECKIN_DESATIVADO`). Com a flag em `true` falham. | A-CON-04, A-INT-08, A-SEG-03. |
| D6 | `teste_lgpd_aceite_backend_seguranca` depende de `tests/manual/_fixtures_lgpd.php`, que nunca foi versionado e não existe no disco. | A-VAL-12 não roda; aceite LGPD sem cobertura automatizada de backend. |
| D7 | Testes que usam o banco dev `udlog_totem` (via `.env`/sem bootstrap QA): `teste_identificar_cliente`, `teste_preparacao_producao_checkin` (não limpa o atendimento por desenho; resíduos P64), `teste_fluxo_recebimento_documentos`, `teste_talent_idor_finalizar`, `teste_talent_trava_doctos_pendente`, `teste_concorrencia_finalizar_checkin`; `teste_rate_limit_identificar_cliente_pdo` não classificado; também há registro de `teste_hardening_cliente`. Incidente registrado em 2026-10-09: teste legado rodado por engano contra o dev alterou dados. | **Não rodar** sem isolar. `teste_preparacao_producao_checkin` fora deste plano (toca o totem real). |
| D8 | Possivelmente obsoletos e cabeçalhos que contradizem o código: `_caso_upload_documento.php`, `_caso_validar_qr.php`, `teste_fluxo_recebimento_documentos.php` (`DocumentoController::upload` e `validarQr` não existem mais em `app/`); `teste_avancar_etapa_expedicao.php` com cabeçalho de 2026-09-09; `teste_lock_obter_lock_documento` e `teste_sanitizacao_logs_documento_nota` dizem "banco de desenvolvimento real" mas o código usa `qa_db_bootstrap`; `teste_hardening_cliente` (registro diz dev, cabeçalho diz QA); `teste_consulta_ordem_coleta` (cabeçalho diz só QA, mas mantém fixture externa; P24 registrou 12/17 por fixture). Não verificado em execução. | Ambiente e validade dessas suítes ficam **PENDENTE DE CONFIRMAÇÃO**. |
| D9 | **Exceção de 2026-10-11:** `tests/manual/_caso_finalizar_talent_sem_registro.php` (usado por `teste_talent_sem_registro`) também é ignorado pelo `.gitignore` (`tests/manual/_*.php`) e só entra no repositório por `git add -f` (junto de `teste_talent_sem_registro.php`); sem isso, em clone limpo o A-INT-15 fica sem o subprocesso. **16 arquivos `tests/manual/_*.php` fora do Git** pelo `.gitignore` (existem só no disco local): `_caso_bloquear_excesso`, `_caso_cancelar`, `_caso_concluir_digitalizacao_cas_direto`, `_caso_documento_qr_vio_falso`, `_caso_finalizar_talent_mock`, `_caso_identificar_cliente_rate_limit`, `_caso_iniciar_processamento_falha_durante_validacao`, `_caso_iniciar_processamento_pdo_lock_falha`, `_caso_iniciar_processamento_qr_invalido`, `_caso_iniciar_processamento_vio_indisponivel`, `_caso_nota_pdo_falha`, `_caso_oc_anexo_receber`, `_caso_pdo_boundary_e_lock`, `_caso_salvar_etapa_http` (`.php`), `_poison_pdo.php`, `_qa02_independente.php`. | Em clone limpo, e2e mock, `talent_mensagem_api_finalizar`, `oc_anexo_*`, `pdo_falha_processamento_documentos`, `integridade_conclusao_atendimento` e `rate_limit_identificar_cliente_pdo` ficam sem o subprocesso. Inferido de cabeçalhos e do `.gitignore`. |
| D10 | Serviço de impressão **sem teste de rota HTTP, CORS ou 409 `sem_papel` ponta a ponta**; `npm test` cobre só `printSettings`, fila do spooler, nome base e listador. | Cobertura só pelos manuais B-IMP-01/02. |
| D11 | **Testes físicos pendentes:** P47 (falta de papel real, duas etiquetas, QR com o Netum), P32 (Netum como videoinput, roteiro de 20 itens), P33 (OCR no mini PC), P36 (permissão de câmera no Chrome kiosk), P54 (medidas finais do papel). | B-IMP-02/03/04, B-QR-01, B-OCR-01, B-CAM-01. |
| D12 | Outras: P24 (`consulta_ordem_coleta` 12/17 por fixture), P58 (`oc_anexo_lacunas` rc 0 com falhas), P23 (`status_processamento` flaky), P59 (`link()` validado só em Windows/NTFS), `leitura_falhou` do PDF sem caso dinâmico, risco residual aceito de JPEG truncado com EOI forjado, resíduos de bancos `qa_*` antigos, sem PHPUnit e sem CI. | Ler as saídas completas; registrar resíduos. |
| D13 | Divergência de impressão: o registro do projeto afirma validação física no mini PC de produção (2026-10-06); o README do serviço ainda diz que a validação e a ativação de `origensPermitidas` de produção estão pendentes. | Tratar B-IMP-01 como não confirmado até nova evidência. |
| D14 | `front_r4_f8_f9.js`: 70 verificações OK e 7 falhas; `front_r4b_uid_409.js`: timeout intermitente. Relato de que **ambos já falhavam antes das mudanças recentes** (não reconferido ao escrever esta documentação). | A-CAN-05 e A-IDE-02 não devem ser lidos como aprovados; não é regressão atribuída às mudanças recentes. |
| D15 | `teste_identificar_cliente.php` usa o banco de desenvolvimento e **não deve ser rodado sem cuidado** (ver D7). Não cobre a busca manual (essa é A-VAL-13, em banco QA). | A-VAL-07 segue como DEV. |
