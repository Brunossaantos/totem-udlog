# Handoff - saneamento-lista-pendencias-projeto

Data: 2026-09-16
Etapa: 00-planejamento

## O que foi pedido

Auditar e reorganizar a seção de pendências (seção 5) de
`ia_development_state.md`, eliminando informações desatualizadas,
duplicadas ou contraditórias, com evidência real (código atual,
handoffs concluídos, histórico git, testes registrados, commits
publicados) para cada item — nunca aceitando descrição textual como
prova de resolução. Demanda exclusivamente documental: nenhuma
alteração de código, banco ou configuração.

Classificação obrigatória em 6 categorias: PENDENTE - AÇÃO TÉCNICA;
AGUARDANDO DECISÃO DE PRODUTO; AGUARDANDO CREDENCIAL/TERCEIRO; ADIADO
PARA PRODUÇÃO/FINALIZAÇÃO; OBSERVAÇÃO DE BAIXA SEVERIDADE; RESOLVIDO
(removido da lista ativa, preservado no histórico).

## Metodologia

Duas verificações independentes em paralelo:
- `explorer`: itens suspeitos de já estarem resolvidos (idempotência
  Talent, HTTP 409, `anexos`/`anexosGZip`, separação
  `impressao.php`/`impressao-teste.php`, revisão de UX da tela de
  impressora, Netum, CORS/PNA) + varredura completa da tabela em busca
  de outras contradições + spot-check de 5 itens já marcados
  RESOLVIDO/IMPLEMENTADO.
- `security-especialista`: itens que deveriam permanecer ativos
  (`cancelar`/`bloquear-excesso-notas`, concorrência em
  `concluirDigitalizacao()`, `GET_LOCK`, `PDOException`, JPEG truncado,
  `tb_rate_limit_ocr`, rate limit fail-open, migrations 001/002, 3
  ajustes do VIO que independem de credenciais).

Nenhum dos dois sub-agentes alterou nenhum arquivo — leitura pura de
código/handoffs/git log.

## Itens removidos da lista ativa (RESOLVIDOS, evidência real)

1. **Idempotência do envio ao Talent** (`talent_checkin_status`, CAS de
   5 estados) — implementada desde 2026-09-09 (migration 009, `AtendimentoDao`
   linhas 330-404), reaproveitada por `TalentRn::processarCheckin()` e
   `cron/reenviar-fila.php`, testada em `tests/manual/teste_talent_idempotencia.php`
   e `teste_concorrencia_finalizar_checkin.php`, reutilizada sem
   alteração pelas demandas `talent-doctos-finalizacao-checkin`
   (2026-09-14) e `talent-http409-limpeza-pendencias` (2026-09-15).
   Commits: `89b8b5d`, `2369f96`.
2. **HTTP 409 real do Talent** — já estava corretamente documentado
   (linha 207/agora renumerada da tabela), confirmado consistente com
   `docs/handoffs/2026-09-15-talent-http409-limpeza-pendencias.md`
   (tentativa 1 = HTTP 200, tentativa 2 = HTTP 409 real, commit `5332a86`).
3. **Formato `anexos` aceito / `anexosGZip` não necessário** — idem,
   já corretamente documentado, confirmado consistente.
4. **Separação `impressao.php` × `impressao-teste.php`** — implementada
   na demanda `impressao-arquitetura-producao-ux` (2026-09-15), commit
   `b6b5129`, `/02-testes`/`/03-revisao` da época com veredito APROVADO.
5. **Revisão formal de UX da tela de seleção de impressora** — feita na
   mesma demanda (13 critérios cobrindo `selecionar_impressora`), 1
   achado corrigido na mesma rodada (botão de saída), 12/12 testado,
   commits `93dc551`/`a8a0454`.
6. **Preview/captura do Netum SD-2000** (3264×2448, 4:3, sem corte) —
   já corrigido na demanda `netum-preview-captura-resolucao`
   (2026-09-16), confirmado consistente.
7. **CORS/PNA de desenvolvimento** (`http://localhost:8080`) — já
   corrigido na demanda `impressao-origens-permitidas` (2026-09-16),
   confirmado consistente.
8. **Regra de "rebaixamento para MANUAL" do VIO** — já implementada em
   `AtendimentoRn::salvarDadosMotorista()` (comparação contra snapshot,
   rebaixamento de origem/status_revisao em caso de divergência,
   inclusive regra "já MANUAL não promove sozinho").
9. **Descarte explícito do campo `image` do envelope VIO** — já
   implementado via allowlist (`CAMPOS_PERMITIDOS_CNH`/`CRLV`) +
   `unset($resultadoVio, $dadosBrutos)` em `DocumentoRn.php`.
10. **Assimetria de `ehValorPlaceholder()` CNH vs. CRLV** — já corrigida,
    `avaliarCnh()` e `avaliarCrlv()` chamam a mesma checagem.

Todas as 10 linhas correspondentes na tabela da seção 5 de
`ia_development_state.md` foram marcadas com `~~texto antigo riscado~~`
seguido da explicação da correção e da evidência — o texto original
NUNCA foi apagado, só riscado e complementado (preservação de
histórico).

## Pendências que permaneceram ativas (confirmadas com evidência)

### 1. PENDENTE — AÇÃO TÉCNICA

- `cancelar`/`bloquear-excesso-notas` não checam status terminal antes
  de reverter um atendimento — confirmado em `AtendimentoController.php`
  (`cancelar()` linhas 609-617, `bloquearPorExcessoDeNotas()` linhas
  239-250) e `AtendimentoDao.php` (`cancelar()`/`bloquear()` linhas
  408-418, sem `AND status != 'concluido'`). Severidade: atenção.
- Ausência de lock/transação em `concluirDigitalizacao()` — confirmado,
  sequência sem `BEGIN`/`COMMIT`/`SELECT...FOR UPDATE`/`GET_LOCK`.
  Severidade: observação/atenção.
- `GET_LOCK`/`RELEASE_LOCK` em `DocumentoController` não liberado
  explicitamente em erro — confirmado (`exit()` dentro de `Resposta::erro()`
  pula o `finally`), mitigação por CAS de `tentativa_id` já documentada
  no código. Severidade: observação.
- Ausência de handler global de `PDOException` em `NotaController` —
  confirmado, nenhum `set_exception_handler()` de produção no projeto,
  chamadas a `buscarAtendimentoDoTotem()`/`algumaIdentificada()` fora de
  `try/catch`. Severidade: atenção.
- JPEG truncado aceito na validação — reconfirmado independentemente
  (`UploadHelper.php`, sem checagem de EOI/FFD9). Severidade: observação.
- `tb_rate_limit_ocr` sem job de limpeza — confirmado, sem cron/DELETE.
  Severidade: observação (ação recomendada para `devops-especialista`).
- Rate limit fail-open silencioso — confirmado, mesmo padrão em
  `NotaController`/`DocumentoController`, sem log de alerta quando o Dao
  não é injetado. Severidade: atenção.
- Migrations 001/002 com padrão frágil de checagem manual (não
  idempotente de verdade, vs. padrão correto já usado na 003) —
  confirmado por comparação direta dos 3 arquivos. Severidade: atenção
  (risco operacional de deploy).
- Correspondência de `jsQR.binaryData` com raw value esperado pela VIO
  — não confirmável sem teste físico com QR real.
- Chave de acesso da NF-e sem leitor HID — decisão pendente, sem
  substituto definido.
- Retomada de atendimento ao recarregar a página — limitação
  pré-existente confirmada.
- Heurística de extração de razão social no OCR e recalibração dos
  valores empíricos (`LIMIAR_MINIMO`/`MARGEM_MINIMA`) — pendentes de
  uso real para ajuste.
- Causa raiz do mojibake na migration 003 nunca investigada na origem.

### 2. AGUARDANDO DECISÃO DE PRODUTO

- Se `cancelar`/`bloquear-excesso-notas` devem recusar atendimento
  `concluido` (decisão de regra de negócio antes da correção técnica).
- Campos adicionais do payload real do Talent sem fonte de captura no
  totem.
- Paleta de cores oficial da UDLOG.

### 3. AGUARDANDO CREDENCIAL/TERCEIRO

- Credenciais Trial (Consumer Key/Secret) da VIO Decode — depende de
  cadastro no portal Serpro.
- Viabilidade/convênio da API Prodesp — não confirmado.

### 4. ADIADO PARA PRODUÇÃO/FINALIZAÇÃO

- Ativação de `origensPermitidas` de produção (`https://udlog.online`).
- Validação física do serviço de impressão no mini PC de produção real.
- Validação física do Netum como `videoinput` em Chromium de produção.
- Roteiro físico completo de 20 itens do Netum.
- Validação física completa do OCR client-side.
- Nome final do banco no Hostgator; `storage/` fora do document root em
  produção; persistência de permissão de câmera no Chromium kiosk.

### 5. OBSERVAÇÃO DE BAIXA SEVERIDADE

- Comentário incorreto no código sobre o Netum ser leitor HID (usado
  hoje só nas telas de CNH/CRLV).
- Rótulo de origem de validação duplicado front/back-end.

Detalhamento completo, com arquivo+linha de cada item, está na nova
subseção `### 5.1. Lista ativa consolidada e categorizada` de
`ia_development_state.md`, inserida logo após a tabela histórica da
seção 5 (que permanece intacta).

## Inconsistências adicionais encontradas (fora dos itens pedidos originalmente)

- **[BLOQUEANTE, CORRIGIDO em 2026-09-16, rodada curta de `/01-implementacao`]**
  Referência inexistente ao arquivo `docs/handoffs/2026-09-15-netum-preview-captura-resolucao.md`:
  esse arquivo **nunca existiu** no repositório em nenhum commit
  (confirmado via `git log --all` retornando vazio para esse nome). Não
  se tratava de dois handoffs reais com nomenclatura divergente — a
  afirmação original registrada aqui (de que "existem tanto o arquivo
  com data 15 quanto o com data 16") estava incorreta: a referência ao
  arquivo com data 15 foi introduzida por engano pelo `explorer` durante
  o `/00-planejamento` desta demanda, sem verificação real de que o
  arquivo existia, e essa afirmação incorreta foi promovida a texto
  canônico tanto aqui quanto na linha 158 de `ia_development_state.md`
  (link quebrado). O único handoff correto e existente para a demanda
  `netum-preview-captura-resolucao` é
  `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`. Achado
  pela revisão independente do `explorer` na `/03-revisao` (2026-09-16),
  classificado como BLOQUEANTE (referência quebrada) — corrigido nesta
  mesma rodada curta de `/01-implementacao`, com busca global
  confirmando que nenhuma outra ocorrência do caminho incorreto
  permanece no repositório.
- As linhas sobre o Netum SD-2000 (validação de `videoinput`/label,
  roteiro de 20 itens, preview/captura) são 3 pendências textualmente
  muito próximas sobre o mesmo hardware — não é erro, mas é uma área de
  alto risco de confusão futura; qualquer nova alteração no Netum
  deveria revisar as 3 linhas juntas.
- Spot-check de 5 itens já marcados RESOLVIDO/IMPLEMENTADO (API de
  clientes local, IDOR `selecionarOrdem`, seed idempotente dos 38
  clientes, `SET NAMES utf8mb4`, `veiculo.uf`) confirmou que todas
  batem com o código real — nenhuma marcação falsa encontrada nessa
  amostra.

## Roadmap oficial das próximas demandas (ordem registrada)

1. `integridade-conclusao-atendimento`
2. `validacao-jpeg-segura`
3. `robustez-rate-limit-migrations`
4. `vio-hardening-sem-credenciais`
5. `ocr-validacao-fisica-calibracao`
6. Decisões de produto (campos do payload Talent, paleta oficial)
7. Testes e ativação final em produção

### Recomendação para a demanda 1 (`integridade-conclusao-atendimento`)

Recomendação ainda sujeita a confirmação no `/00-planejamento` dessa
demanda futura, não uma decisão já tomada:

> Atendimento `concluido` deve ser imutável para o motorista. `cancelar`
> e `bloquear-excesso-notas` devem retornar `409` sem alterar o banco.
> Eventual reabertura futura ficará restrita a um painel administrativo
> autenticado e auditável.

## O que NÃO foi feito nesta etapa

- Nenhuma alteração de código, migration, banco ou arquivo de
  configuração.
- Nenhum teste físico ou chamada externa (Talent, VIO, serviço de
  impressão).
- Nenhuma impressão.
- Nenhuma exposição de credencial ou dado pessoal.
- Nenhum commit, nenhum push.

## Sub-agentes envolvidos

- `explorer` (itens possivelmente resolvidos + varredura de
  contradições + spot-check).
- `security-especialista` (itens que deveriam permanecer ativos).

## Trello
card_id: 6aaae214f34db59cd23e2d8c

## Próximo passo

Aguardar confirmação do usuário sobre a reorganização aplicada e, se
aprovado, seguir para `/02-testes` curto (validação de que nenhuma
alteração funcional foi feita, `git diff --check`, inspeção do diff) ou
diretamente para `/03-revisao`/`/04-commit-e-push`, já que esta é uma
demanda puramente documental de baixo risco.

## Rodada curta de /01-implementacao — correção de referência quebrada (2026-09-16)

Motivo: a `/03-revisao` desta demanda (revisão independente por
`explorer` + `security-especialista`) identificou um achado bloqueante:
a linha 158 de `ia_development_state.md` (Netum, já marcada como
resolvida nesta mesma demanda) terminava com uma referência a um
arquivo que nunca existiu no repositório
(`docs/handoffs/2026-09-15-netum-preview-captura-resolucao.md`,
confirmado por `git log --all` vazio para esse nome). O `explorer`
classificou corretamente como BLOQUEANTE (referência quebrada); o
`security-especialista`, na mesma rodada, havia tratado o mesmo achado
como observação não bloqueante — o orquestrador optou pela
classificação mais rigorosa (`explorer`), conforme instrução explícita
do usuário de não aprovar com ressalva nenhum item que pudesse deixar a
lista de pendências novamente inconsistente.

### Correções aplicadas (exclusivamente estas duas)

1. `ia_development_state.md`, linha da entrada do Netum: caminho
   corrigido de `docs/handoffs/2026-09-15-netum-preview-captura-resolucao.md`
   para `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`
   (único arquivo real).
2. Este handoff, seção "Inconsistências adicionais encontradas":
   reescrita para deixar explícito que o arquivo com data 15 nunca
   existiu, que a referência foi introduzida por engano durante o
   `/00-planejamento` desta demanda, e que não havia dois arquivos reais
   nem uma divergência legítima de nomenclatura.

### Validação

- Busca global (`grep -rn` em todo o repositório, extensão `.md`)
  confirma: **zero** ocorrências vivas do caminho incorreto — a única
  ocorrência restante é o texto histórico desta própria seção,
  documentando o erro (não é mais uma referência funcional).
- `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`
  confirmado existente no disco.
- `git diff --check`: limpo (só aviso de CRLF do Windows).
- `git status`: só `ia_development_state.md` (modificado) e este
  handoff (novo/untracked) — nenhum código, banco, migration ou
  configuração tocado.
- Nenhuma outra classificação, pendência, categoria ou texto foi
  alterado nesta rodada além dessas duas correções pontuais.

### Confirmação

Zero teste físico, zero chamada externa (Talent/VIO/impressão), zero
impressão, zero alteração funcional, zero commit, zero push.

### Veredito

Correção aplicada com sucesso. **Pronta para nova `/03-revisao` curta
de confirmação.**

## Nova revisão curta de confirmação (2026-09-16, /03-revisao)

Revisão independente (`explorer`, o mesmo que identificou o achado
bloqueante), restrita à correção da referência quebrada — os outros 9
pontos da revisão anterior permanecem aprovados sem repetição.

Todos os 6 itens confirmados **PASS**: linha do Netum referencia só o
arquivo existente (`2026-09-16-...`); busca global confirma zero
ocorrências vivas do caminho incorreto (só 4 menções, todas dentro do
próprio registro histórico do erro); seção de inconsistências do
handoff correta e sem ambiguidade; nenhuma classificação/pendência/
roadmap alterado nesta correção; só `ia_development_state.md` e este
handoff tocados. `docs/handoffs/2026-09-16-netum-preview-captura-resolucao.md`
confirmado existente; `git diff --check` limpo.

### Veredito

**APROVADO.** Nenhum achado remanescente. **Pronta para
`/04-commit-e-push`.**
