# 04 - Notas fiscais e OCR

Índice: [fluxos](README.md) | API: [totem-api-interna](../apis/totem-api-interna.md) | [regras de negócio](../regras-negocio.md) | [banco de dados](../banco-dados.md)

Legenda de confiança: ver [README](README.md#legenda-de-confiança).

## Objetivo

No **Recebimento**, digitalizar de 1 a 5 notas fiscais com o scanner, registrar o número de cada nota, tentar identificar o cliente por OCR (no navegador) e consolidar o resultado em `concluir-digitalizacao`. `[CÓDIGO]`

## Atores

Motorista; scanner Netum SD-2000 (câmera USB, orientação física `[AMBIENTE-TERCEIRO]`); Tesseract.js no Chromium; backend (`NotaController`, `NotaFiscalRn`); tabela `tb_cliente` local.

## Pré-condições

Atendimento de Recebimento `em_andamento`, etapa `digitalizacao_notas` (definida por `salvar-etapa`, ver [03](03-recebimento.md)); totem do token é o dono do atendimento. `[CÓDIGO]`

## Etapas do motorista

1. `rec_digitaliza`: a câmera abre (resolução ideal 4096x3072). "Capturar nota" gera uma prévia; "Usar imagem" envia, "Refazer" descarta. O contador mostra "N de 5". "Finalizar digitalização" habilita com 1 nota ou mais; com 5 notas a captura é desabilitada ("Limite de 5 notas atingido"). `[CÓDIGO]`
2. `rec_revisao_numeros`: cada nota precisa de número confirmado. Se o OCR sugeriu número com confiança alta, abre modal de confirmação; senão, teclado numérico. "Adicionar outra nota" volta a `rec_digitaliza` se há menos de 5; "Continuar" só habilita com todas confirmadas. `[CÓDIGO]`
3. Na revisão o motorista pode **excluir** uma nota (modal do número, com confirmação). Se zerar as notas, volta a `rec_digitaliza`. `[CÓDIGO]`
4. "Continuar" chama `concluir-digitalizacao`; o destino é `rec_cnh_qr` (cliente identificado) ou `rec_cliente` (não identificado). `[CÓDIGO]`

## Processamento interno

### Captura (front)

- `capturarFotoScannerNota` desenha o quadro de vídeo num canvas com **rotação fixa de 270 graus** e gera JPEG com qualidades de 0,92 a 0,4 até caber em 4,5 MiB; se ainda não couber, reduz a escala (fator 0,9) até 600 px. O **mesmo JPEG rotacionado** é a prévia, o arquivo enviado ao servidor e a entrada do OCR. `[CÓDIGO]`
- **Divergência registrada, sem correção:** um registro anterior dizia que a rotação valeria só para a cópia do OCR e que a imagem original seria salva sem alteração. No código atual não existe cópia separada. Validação física da orientação: `[PENDENTE DE CONFIRMAÇÃO]` (P13, P32).

### Upload (`nota.php?acao=processar`)

- Corpo: `id_atendimento`, `imagem` (data URL JPEG), `uid` (`client_uid`, 8 a 64 caracteres `A-Za-z0-9_-`, gerado no front com `crypto.randomUUID`) e `chave` (sempre nula; P26). `[CÓDIGO]`
- Todo o processamento roda sob transação com `SELECT ... FOR UPDATE` do atendimento, serializando uploads do mesmo atendimento. Falha de lock (1205/1213) vira 503. `[CÓDIGO]`
- **Idempotência por `client_uid`:** UNIQUE (`id_atendimento`, `client_uid`). Se a nota com o `uid` já existe, responde 200 com `reaproveitada=true` e os mesmos `id_nota`/`ordem`. `[CÓDIGO]` `[TESTE: teste_hardening_r4_uid_reconciliacao]`
- Limite de 5: a 6ª nota é recusada; `ordem` é a primeira posição livre de 1 a 5. `[CÓDIGO]`
- Validação da imagem (fail-closed): máximo de 5 MiB (`NOTA_IMAGEM_MAX_BYTES`), assinatura JPEG e EOI, dimensões até 5000 px por lado e 13.000.000 px de área, decodificação GD completa. Risco residual aceito: JPEG truncado com EOI forjado. `[CÓDIGO]` `[TESTE: teste_validacao_jpeg_seguro]`
- Gravação em `STORAGE_PATH/<pasta_documentos>/nota_NN.jpg` (fora do `public_html`) e inserção da linha em `tb_atendimento_nota`. Em erro ou rollback, a compensação apaga o arquivo. `[CÓDIGO]`
- Não há teto de tamanho do corpo HTTP em `nota.php` antes do `json_decode` (diferente de `documento.php`); valem os limites do PHP/servidor e os 5 MiB do binário decodificado. `[CÓDIGO]`
- Timeouts do front: 90 s no upload e 20 s no `listar`.

### Reconciliação após falha de rede

Em falha de rede o front guarda o `clientUid` pendente. `reconciliarNotasComServidor` chama `nota.php?acao=listar`, reidrata as notas do servidor e reenvia com o **mesmo** `uid`, sem duplicar. `[CÓDIGO]` `[TESTE: front_r4b_uid_409, front_r4b_real]`

### OCR client-side

- Tesseract.js 5.1.1, idioma `por` (`por.traineddata.gz` em `assets/vendor`), **um** worker, execução serializada e fila FIFO. `[CÓDIGO]`
- O OCR é sempre no navegador; o servidor não faz OCR. O servidor recebe só os candidatos (CNPJs/razão social) em `identificar-cliente`. `[CÓDIGO]`
- Número da nota: busca por rótulo "N" com símbolo de número, 1 a 9 dígitos; ignora sequências de 44 e de 14 dígitos; só sugere com confiança alta. `[CÓDIGO]`
- Resultado tardio é descartado se o atendimento mudou ou se a digitalização já foi concluída. `[CÓDIGO]`
- Precisão e tempo reais com o scanner: `[AMBIENTE-TERCEIRO]` (P33, P03).

### Identificação do cliente (`nota.php?acao=identificar-cliente`)

1. Rate limit de 30 requisições por 60 s por totem (429 com `Retry-After`; falha de PDO no limitador responde 500). O front reenvia com backoff de 1 s e 2 s em erro de rede. `[CÓDIGO]` `[TESTE: teste_rate_limit_identificar_cliente_pdo]`
2. Exige etapa `digitalizacao_notas`.
3. `avaliarNota`: até 10 CNPJs candidatos com dígito verificador válido e que **não** sejam o da UDLOG. Cada um é buscado em `tb_cliente` **ativo**. Dois ou mais clientes: `MULTIPLAS_CORRESPONDENCIAS` (status de OCR `ERRO`). Um cliente: `IDENTIFICADA`. `[CÓDIGO]`
4. Sem CNPJ, usa busca aproximada por razão social normalizada (`RazaoSocialMatcher`: limiar mínimo 80, margem mínima 15): `IDENTIFICADA`, `RAZAO_SOCIAL_AMBIGUA` ou `NAO_IDENTIFICADA`. Limiares sem calibração com dados reais (P39). `[CÓDIGO]` `[TESTE: teste_identificar_cliente]` (não rodado nas últimas rodadas).
5. O resultado é gravado com compare-and-swap (só se `status_ocr` está `PENDENTE`/`PROCESSANDO`): imutável por nota. `[CÓDIGO]`
6. Clientes inativos nunca identificam. `[CÓDIGO]`

**Papel emitente/destinatário: NÃO implementado.** Há decisão registrada de que só o CNPJ comprovadamente emitente/destinatário associaria o cliente automaticamente e a transportadora nunca. O código não classifica papel: qualquer CNPJ candidato válido presente em `tb_cliente` ativo identifica. Registrado sem correção (P05, P09 abertas).

### Definir número (`nota.php?acao=definir-numero`)

Sob lock; só dígitos; remove zeros à esquerda; 1 a 9 dígitos. Origem `OCR` (sugestão confirmada) ou `MANUAL`. Número duplicado no atendimento: 409 `NUMERO_NOTA_DUPLICADO` (UNIQUE `id_atendimento`+`numero_nota`). Nota inexistente: 404. `[CÓDIGO]` `[TESTE: teste_numero_nota_validacao_tamanho, teste_concorrencia_numero_nota_duplicado]`

### Concluir digitalização (`atendimento.php?acao=concluir-digitalizacao`)

Sob `FOR UPDATE` do atendimento e das notas: `[CÓDIGO]`

1. Exige `em_andamento`. Se a etapa já é a de destino ou posterior, responde 200 idempotente; senão, se não é `digitalizacao_notas`, responde 400.
2. Exige de 1 a 5 notas.
3. Notas com OCR `PENDENTE`/`PROCESSANDO` e idade menor que 120 s bloqueiam com 409 `OCR_EM_ANDAMENTO` e a lista `ordens_em_processamento`.
4. **`CONCLUIR_EXIGE_NUMERO_NOTA`** (variável opcional; só o texto `true` a liga):
   - **Ligada:** `concluir-digitalizacao` responde 422 `NOTAS_SEM_NUMERO` (`dados.ordens_pendentes`, `total_notas`, `total_pendentes`) se alguma nota ativa não tiver número válido (`NotaFiscalRn::numeroNotaValido`), e o front destaca as notas pendentes; o motorista não avança até corrigir.
   - **Desligada (padrão do código e do `.env.example`):** esse bloqueio **não** acontece na digitalização (o front só libera "Continuar" com todas confirmadas); o motorista segue e só o `finalizar` recusa (422 `NOTAS_SEM_NUMERO`, no texto de `erro`) se alguma nota tiver número **vazio**: critério mais frouxo (não vazio) que o `numeroNotaValido` ([07](07-checkin-talent.md)).
   - **Decisão do usuário em 2026-10-11 de ativar em produção;** valor no servidor **a confirmar** `[PENDENTE DE CONFIRMAÇÃO]` (P08).
5. Avaliação do cliente do atendimento: dois ou mais clientes distintos => `ANOMALIA`/`CONFLITO`; nota em erro ou processamento expirado => `ANOMALIA`/`INDETERMINADO`; um cliente => `IDENTIFICADO`.
6. `IDENTIFICADO`: grava o cliente (se ainda vazio) e vai para `rec_cnh`; caso contrário vai para `cliente`. Transição com compare-and-swap; se outra requisição já avançou, 200 idempotente. Lock timeout: 503.

### Exclusão e quarentena (`nota.php?acao=excluir`)

Sob lock: se a nota já não existe, 200 `ja_excluida=true`. A foto vai para quarentena (`nota_NN.jpg.<id_nota>.del`) **antes** de apagar a linha (DELETE físico); rollback restaura a foto; após o commit o `.del` é removido e, se falhar, o cron de quarentena cobre ([09](09-cancelamento-e-abandono.md)). A resposta traz as notas ativas e o estado do cliente. `[CÓDIGO]` `[TESTE: teste_hardening_exclusao (E17 falha preexistente, P55), teste_hardening_r4_restaurar_log]`

## Endpoints envolvidos

`nota.php` (`processar`, `listar`, `identificar-cliente`, `definir-numero`, `excluir`), `atendimento.php` (`salvar-etapa`, `concluir-digitalizacao`), `cliente.php?acao=buscar` (tela `rec_cliente`). Ver [totem-api-interna](../apis/totem-api-interna.md).

## Mudanças de estado

`digitalizacao_notas` -> `rec_cnh` (cliente identificado) ou `cliente`. Por nota: `status_ocr` `PENDENTE` -> `IDENTIFICADA`/`NAO_IDENTIFICADA`/`ERRO` (`PROCESSANDO` existe no esquema mas nenhum código lido o grava). No front: `pendente`, `sugerido`, `sem_sugestao`, `confirmado`. `[CÓDIGO]`

## Integrações

Scanner Netum e Tesseract.js (cliente); `tb_cliente` local. Nenhuma API externa. `[AMBIENTE-TERCEIRO]` para o scanner.

## Conclusão

Etapa concluída quando `concluir-digitalizacao` avança a etapa. As fotos ficam em `STORAGE_PATH` até o fim do atendimento e seguem no disco depois de `concluido`.

## Cancelamento

Cancelar ou abandonar move as fotos para quarentena (24 h) ([09](09-cancelamento-e-abandono.md)). O front descarta uploads e OCR obsoletos por contadores de geração. `[CÓDIGO]`

## Falhas e alternativas

- Falha de rede no upload: reconciliação com o mesmo `uid`.
- Cliente não identificado ou em conflito: `rec_cliente` (seleção manual da lista).
- OCR em andamento ao concluir: 409, o front aguarda.
- Mais de 5 notas: ver [03](03-recebimento.md) (balcão da portaria).
- Inatividade durante captura/OCR: o aviso é adiado até 120 s no total ([09](09-cancelamento-e-abandono.md)).

## Pendências

- Rotação de 270 graus aplicada à prévia, ao upload e ao OCR (divergência com registro anterior). `[PENDENTE DE CONFIRMAÇÃO]`
- Papel emitente/destinatário não implementado (P05, P09).
- **Retenção das fotos de notas de atendimentos concluídos** (decisão de produto em 2026-10-10: não será tratado): comportamento atual, nenhuma rotina as remove (o abandono só atua em `em_andamento`; a limpeza de quarentena só apaga `.del`).
- `CONCLUIR_EXIGE_NUMERO_NOTA`: padrão `false`; decisão do usuário em 2026-10-11 de ativar em produção, valor no servidor a confirmar (P08).
- Chave de acesso da nota sempre nula (P26); captura de notas sem interrupção (P01 a P16) com implementações 2 e 3 não iniciadas e testes físicos pendentes.
- P04: lacuna preexistente no guard de `idAtendimento` após `await` em `identificar-cliente`, aberta.
- `teste_hardening_exclusao` com falha E17 preexistente (P55).
