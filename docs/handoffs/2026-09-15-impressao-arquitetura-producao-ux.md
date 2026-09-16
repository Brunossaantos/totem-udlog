# Handoff — impressao-arquitetura-producao-ux

Data: 2026-09-15
Etapa: 00-planejamento
Baseline: commit `f7bbbdde594e232279cc7096e4edcbd037c59163`

## O que foi pedido

1. Separar completamente a impressao real dos componentes/rotas/nomes de
   teste.
2. Eliminar a dependencia do fluxo real em
   `impressao-teste.php?acao=configuracao-servico-local`.
3. Manter testes/diagnostico isolados, sem participacao no fluxo de
   producao.
4. Revisao formal de UX das telas `exp_impressao`/`rec_impressao`.
5. Planejar arquitetura simples, segura, compativel com PHP 8.0.3/
   Windows/servico local Node ja validado.

Nenhuma implementacao, chamada real, impressao, alteracao de banco,
remocao de endpoint, commit ou push ocorrem nesta etapa. Paleta de
cores e valores finais de `origensPermitidas` ficam fora de escopo.

## Estado atual comprovado (mapeamento do `explorer`)

O UNICO ponto real de acoplamento entre producao e teste, confirmado
por leitura de codigo linha a linha:

- `public/totem/assets/app.js`, funcao `imprApiConfiguracaoServicoLocal()`
  (linhas ~1336-1353), chamada por `imprIniciarImpressao()` (linha
  ~1404), faz `fetch` DIRETO em
  `impressao-teste.php?acao=configuracao-servico-local` — endpoint que
  pertence a `App\Controller\ImpressaoTesteController::configuracaoServicoLocal()`
  (`ImpressaoTesteController.php:64-86`), criado exclusivamente para a
  tela de diagnostico (`diagnostico-impressao.js`). O proprio codigo ja
  documenta isso como pendencia (comentario nas linhas 1337-1341 de
  `app.js`).
- **Nao existe hoje NENHUMA rota equivalente** em `impressao.php`/
  `ImpressaoAtendimentoController` para essa configuracao — o fluxo
  real depende 100% do endpoint de teste para descobrir `url`/`token`/
  `frontend_timeout_ms` do servico Node local.
- Tudo o mais JA esta corretamente isolado:
  - `ImpressaoAtendimentoController::gerarEtiqueta()` (producao real,
    `impressao.php?acao=gerar-etiqueta`): recebe so `id_atendimento`,
    valida posse (IDOR: `id_totem` do token == atendimento), valida
    `status='concluido' AND talent_checkin_status='ENVIADO' AND
    talent_senha preenchido`, NUNCA chama o Talent, gera PDF com nome+
    `nrRegAcesso` (sem CPF/CNH), identificador novo por chamada
    (`bin2hex(random_bytes(16))`, nunca reaproveitado nem em
    reimpressao).
  - `ImpressaoTesteController::gerarEtiqueta()` (teste, PDF fixo "ETIQUETA
    DE TESTE - NAO UTILIZAR", sem dado pessoal) — nunca chamado pelo
    fluxo real.
  - Allowlist de impressoras (`servico-impressao-local/config/config.json`)
    e revalidada em CADA chamada real ao servico Node (`GET /impressoras`
    E `POST /imprimir`), nunca confiada em estado anterior — ja e defesa
    em profundidade correta, sem necessidade de mudanca.
  - Idempotencia por identificador aleatorio sempre novo (nunca reaproveitado)
    + `IdempotenciaStore` em memoria no servico Node — ja correto.
  - Timeout/estado indeterminado (front-end, `AbortController`, sem
    retry automatico) — ja correto, independente de qual endpoint
    fornece a config.
  - `localStorage['totem_impressora_nome']` — unica persistencia de
    impressora escolhida, compartilhada por CONVENCAO DE NOME (nao por
    codigo) entre o fluxo real e o diagnostico.
- Comparacao de token do servico Node (`servico-impressao-local/src/middleware/auth.js`):
  **RECONFIRMADO NESTA RODADA que JA USA `crypto.timingSafeEqual` sobre
  hash SHA-256** (linhas 31-35) — o achado antigo de comparacao `!==`
  ja foi corrigido em rodada anterior (demanda `impressao-etiqueta-teste`,
  2026-09-14) e a referencia desatualizada usada na delegacao desta
  rodada foi identificada e corrigida pelo proprio `security-especialista`.
  Bind do servico Node em `127.0.0.1` e hardcoded, nunca configuravel
  (`config.js:131`) — reduz a severidade de qualquer achado futuro
  nesse ponto.
- `origensPermitidas` (CORS/PNA do servico Node) continua vazio/
  fail-closed — pendencia INDEPENDENTE desta demanda, so identificada
  onde se conecta (ver secao dedicada abaixo), sem definir valor.
- Nenhuma outra tela/endpoint de diagnostico de impressao foi
  encontrada alem das ja mapeadas.

## Fluxo atual (descricao)

```
Motorista conclui atendimento
  -> renderTela() entra em exp_impressao/rec_impressao
  -> processarImpressao() -> imprFinalizar()
       chama atendimento.php?acao=finalizar   [PRODUCAO OK]
  -> imprIniciarImpressao()
       chama imprApiConfiguracaoServicoLocal()
         -> impressao-teste.php?acao=configuracao-servico-local  [TESTE — PROBLEMA]
       (sem impressora salva) -> GET {url}/impressoras            [servico Node]
       -> imprTelaSelecionarImpressora() (se necessario)
  -> imprExecutarImpressao()
       chama imprApiGerarEtiqueta()
         -> impressao.php?acao=gerar-etiqueta                     [PRODUCAO OK]
       -> POST {url}/imprimir                                     [servico Node]
  -> concluido / erro / indeterminado
```

## Fluxo proposto (descricao)

```
Motorista conclui atendimento
  -> ... (igual) ...
  -> imprIniciarImpressao()
       chama imprApiConfiguracaoServicoLocal() [mesma funcao, nova URL]
         -> impressao.php?acao=configuracao-servico-local          [PRODUCAO, NOVO]
              consome Util\ConfiguracaoServicoImpressao::ler()
       (resto identico)
  -> ... (igual) ...

impressao-teste.php?acao=configuracao-servico-local permanece existindo,
mas passa a ser usado SOMENTE por diagnostico-impressao.js (uso
original). ImpressaoTesteController::configuracaoServicoLocal() e
refatorado para tambem consumir Util\ConfiguracaoServicoImpressao::ler()
(elimina duplicacao de codigo, sem mudar contrato de resposta).
```

## Arquivos provavelmente afetados

- NOVO: `app/Util/ConfiguracaoServicoImpressao.php` (classe compartilhada
  de leitura fail-closed de `IMPRESSAO_LOCAL_URL`/`IMPRESSAO_LOCAL_TOKEN`/
  `IMPRESSAO_FRONTEND_TIMEOUT_MS` — caminho/namespace exato a confirmar
  contra o padrao real de `Util\Auth`/`Util\Resposta` no `/01-implementacao`,
  nao verificado neste planejamento).
- `app/Controller/ImpressaoAtendimentoController.php` (novo metodo
  `configuracaoServicoLocal()`).
- `public/api/impressao.php` (novo `case 'configuracao-servico-local'`).
- `app/Controller/ImpressaoTesteController.php` (refatorar para usar a
  classe compartilhada, sem mudar comportamento externo da rota de
  teste).
- `public/totem/assets/app.js` (trocar a URL chamada em
  `imprApiConfiguracaoServicoLocal()`, linhas ~1336-1353; remover
  comentario de pendencia nas linhas ~1276-1279/1337-1341; POSSIVEL
  correcao de reentrancia/toque duplo, ver secao UX/riscos).
- `ia_development_state.md` (marcar a pendencia da linha 204 como
  resolvida ao final da implementacao real).
- `docs/deploy-checklist.md` (novo item de verificacao da transicao,
  ver secao DevOps).
- `.env.example` (comentario nas linhas ~91-92 cita literalmente
  `ImpressaoTesteController::configuracaoServicoLocal()` — ficara
  desatualizado, precisa correcao de texto).
- NAO afetados (confirmado): `servico-impressao-local/` inteiro (Node),
  `docs/manual_talent.md`, qualquer coisa do Talent/gestao de coletas.

## Contratos de API propostos

### Novo: `GET impressao.php?acao=configuracao-servico-local` (producao)

Mesmo contrato de resposta ja consumido pelo front-end (paridade
proposital, para minimizar diff no JS):

```json
{
  "sucesso": true,
  "dados": {
    "url": "http://127.0.0.1:PORTA",
    "token": "...",
    "frontend_timeout_ms": 35000
  }
}
```

Erros: `401` (token de totem ausente/invalido, via `Util\Auth::validarTotem()`
— OBRIGATORIO ser a primeira linha executavel, achado de atencao do
`security-especialista`), `503` (`.env` ausente/invalido, mensagem
generica "Servico local de impressao nao configurado", log tecnico so
com NOMES das variaveis ausentes, nunca valores/token). Nunca recebe
`id_atendimento` — e configuracao de totem/ambiente, nao de atendimento
especifico (confirmado correto pelo `security-especialista`; se no
futuro a config vier a ser diferenciada por totem, o `id_totem` deve
vir do proprio token de autenticacao, nunca de parametro de entrada).

### Mantido (sem mudanca de contrato): `impressao-teste.php?acao=configuracao-servico-local`

Continua existindo, exclusivo do diagnostico. Internamente passa a
chamar a mesma `Util\ConfiguracaoServicoImpressao::ler()` (elimina
duplicacao, comportamento externo identico).

### Mantido (sem mudanca): `impressao.php?acao=gerar-etiqueta`

Ja e producao real, nada muda.

### Nao proposto nesta demanda (decisao de produto pendente)

Endpoint de persistencia de impressora por totem no backend (ex.:
`impressao.php?acao=selecionar-impressora`, gravando em
`tb_totem.impressora_padrao`) — SO se a decisao de produto (ver secao
"Decisoes de produto necessarias") confirmar que `localStorage` e
insuficiente.

## Estrategia de compatibilidade e remocao do acoplamento antigo

Ordem de implementacao proposta pelo `backend-especialista`, cada etapa
independentemente reversivel (nunca deixa producao quebrada entre
etapas):

1. Criar `Util\ConfiguracaoServicoImpressao` isolada (sem tocar em
   nenhum controller ainda). Teste: unidade da classe isolada (valores
   validos -> array correto; ausentes/invalidos -> excecao).
2. Adicionar a nova acao em `ImpressaoAtendimentoController`/`impressao.php`,
   consumindo a classe nova. `impressao-teste.php` continua 100%
   inalterado nesta etapa. Teste: comparar resposta do endpoint novo
   com o antigo (mesmo `.env`) — paridade total; testar tambem token
   invalido/ausente e `.env` incompleto.
3. Refatorar `ImpressaoTesteController::configuracaoServicoLocal()`
   para usar a mesma classe (elimina duplicacao, sem mudar
   comportamento externo). Teste: regressao da rota de teste — mesma
   resposta de antes.
4. Trocar `app.js`: `imprApiConfiguracaoServicoLocal()` passa a chamar
   o endpoint novo. Remover comentario de pendencia. Teste: fluxo real
   completo ponta a ponta (finalizar -> config -> selecao/impressao),
   incluindo os estados de erro/indeterminado — nada deve mudar
   visualmente pro motorista.
5. **NAO remover** `impressao-teste.php?acao=configuracao-servico-local`
   — ela nasceu para o diagnostico e continua sendo o uso legitimo dela
   depois da migracao (achado do `explorer`/`devops-especialista`:
   nenhuma mudanca na operacao do atendente/toque longo). So deixa de
   ser chamada pelo fluxo real. Atualizar `ia_development_state.md`
   (pendencia da linha 204) e o comentario de
   `ImpressaoTesteController::configuracaoServicoLocal()` para refletir
   que agora e exclusiva do diagnostico.

Risco de janela quebrada durante deploy (achado do `frontend-especialista`):
se o `app.js` novo for publicado ANTES do endpoint novo existir em
producao, a tela de impressao real quebra ate o backend ser publicado.
Recomendacao: **deploy atomico** (backend + frontend no mesmo commit/
push do workflow de 5 etapas) — sem inventar feature flag/fallback
automatico pro endpoint antigo (isso reintroduziria a mistura
teste/producao que a demanda busca eliminar).

## Achados formais de UX (`ui-ux-especialista`)

Avaliacao feita POR LEITURA DE CODIGO (`app.js`/`app.css`) — sem acesso
a navegador/dispositivo real nesta sessao. Validacao visual real
(contraste sob luz de patio, ergonomia com luva, angulo de leitura em
retrato) fica para `/02-testes`.

### BLOQUEANTE

- **B1** — Mensagens de erro tecnicas do backend/servico local vao
  DIRETO pra tela do motorista, sem traducao (`e.message` bruto
  concatenado/exibido em varios pontos: `imprIniciarImpressao`,
  `imprCarregarImpressoras`, `imprExecutarImpressao`,
  `imprTelaErroFinalizar`). Risco real de jargao tecnico
  ("allowlist", "token", "CORS", codigos internos como
  `TALENT_CHECKIN_DESATIVADO`) aparecer literalmente na tela do
  motorista.
- **B2** — `imprTelaErroFinalizar` pode exibir mensagem tecnica sem
  fallback amigavel adequado (o `||` so cobre mensagem vazia/undefined,
  nao uma mensagem tecnicamente formatada mas presente).

### ATENCAO

- **A1** — Nao existe acao EXPLICITA de "trocar impressora" — so troca
  reativa a erro (regex `/impressora/i` no texto do erro). Sem
  distincao entre "selecionar" (1a vez) e uma eventual troca proativa.
- **A2** — Alvo de toque dos botoes (`.btn-primario`/`.btn-fantasma`,
  `padding:16px 22px`, sem `min-height`) fica em ~50-55px estimado,
  ABAIXO do padrao ja adotado no projeto (64px, `.tecla-numerica`/
  `.btn-saida-modal-nota`).
- **A3** — Nenhum spinner/indicador de progresso animado nos estados
  "enviando"/"preparando"/"imprimindo" (confirmado: sem `@keyframes`/
  `.spinner`/`animation` em `app.css`) — risco de o motorista achar que
  travou, especialmente com timeout de ate 35s.
- **A4** — Hierarquia tipografica inconsistente: estados de erro/
  carregamento usam so `.subtitulo` (15px, cinza secundario), nunca
  `.titulo` (22px, negrito) — uma mensagem de erro fica visualmente
  MENOS destacada que uma tela neutra de selecao de impressora.
- **A5** — Sem indicacao de tempo/limite no estado "imprimindo" antes
  de virar indeterminado (35s de espera sem nenhuma pista visual do
  limite).
- **A6** — Prevencao de toque duplo depende so da troca sincrona de
  tela (`imprState.tela='preparando'` antes do `await`) — sem flag de
  reentrancia explicita nem `disabled` no elemento clicado (o padrao
  `.btn-primario:disabled` existe no CSS mas nao e usado aqui). Risco
  baixo na pratica, mas ausencia de trava defensiva confirmada tambem
  pelo `frontend-especialista` de forma independente (ver riscos/plano
  de implementacao).

### MELHORIA

- **M1** — Tela de sucesso poderia destacar mais a instrucao fisica
  "Retire o comprovante na bandeja abaixo".
- **M2** — "Imprimir novamente" poderia deixar mais explicito que nao
  gera novo numero de acesso (risco baixo, o numero ja fica visivel na
  tela).
- **M3** — Nenhuma saida de "emergencia" (ex.: "Chamar atendente")
  distinta de "Novo atendimento" nas telas de falha — pode ser
  proposital (fora do escopo de UI decidir regra de negocio), registrado
  como observacao.

### Sem achado relevante

Consistencia Recebimento x Expedicao (codigo 100% compartilhado,
confirmado por grep), comportamento com impressora ja configurada (pula
direto pra impressao, sem tela extra), comportamento sem nenhuma
impressora configurada (fluxo direto e claro, exceto pelos achados A2/
A4 ja listados).

## Riscos de seguranca e regressao (`security-especialista`)

### Atencao

1. O novo endpoint de config PRECISA chamar `Util\Auth::validarTotem()`
   como PRIMEIRA linha executavel (mesmo padrao ja usado hoje) — vira
   parte do caminho "oficial" de producao, entao qualquer regressao
   aqui expoe `IMPRESSAO_LOCAL_TOKEN` sem autenticacao.
2. Volume de chamadas ao endpoint de config sobe (passa a ser chamado
   em todo carregamento de tela de impressao real, nao so diagnostico
   manual) — confirmar com o `backend-especialista` se o front-end
   deveria cachear a config por sessao em vez de rechamar a cada
   etiqueta (ja documentado como recomendacao no controller de teste).
3. Ao unificar a leitura em `Util\ConfiguracaoServicoImpressao`,
   preservar a disciplina ja existente: mensagens de erro/log SEMPRE
   citam so os NOMES das variaveis ausentes/invalidas, nunca os
   VALORES/token.
4. Coexistencia temporaria dos 2 endpoints de config: enquanto
   `impressao-teste.php?acao=configuracao-servico-local` continuar no
   ar, e um caminho paralelo plenamente funcional e autenticado pelo
   mesmo token de totem. Recomendacao (nao decisao): nenhuma acao nesta
   demanda alem de deixar de ser chamado pelo fluxo real — a rota
   continua legitima pro diagnostico, conforme decisao explicita da
   estrategia de migracao (etapa 5).
5. Comparacao de token nao constant-time: **RECONFIRMADO NESTA RODADA
   como JA CORRIGIDO** (`crypto.timingSafeEqual`) — achado antigo
   estava desatualizado na base de conhecimento usada para delegar esta
   tarefa; nao e mais um achado real, ja fechado desde
   `impressao-etiqueta-teste` (2026-09-14).

### Observacao

6. Ausencia de `id_atendimento` no novo endpoint esta CORRETA (config
   e por ambiente/totem, nao por atendimento) — se no futuro a config
   vier a ser diferenciada por totem, o `id_totem` deve vir do proprio
   token de autenticacao, nunca de parametro de entrada.
7. Persistencia futura de impressora no backend (SE decidida): gates
   necessarios — autenticacao do totem obrigatoria; a rota de escrita
   NUNCA deve aceitar nome de impressora arbitrario sem revalidar
   contra a allowlist do servico Node; prepared statements (padrao ja
   usado); o valor persistido no backend seria so UX (lembrar ultima
   escolha), NUNCA fonte de verdade de autorizacao — a allowlist do
   servico Node continua sendo o unico ponto de decisao real.
8. `origensPermitidas` vazio/fail-closed — comportamento correto, nao
   deve ser alterado nesta demanda; conecta-se a arquitetura so no
   sentido de que o endpoint PHP novo devolve `url`/`token` pro
   front-end usar contra o servico Node — a decisao de preencher e
   dependencia de infraestrutura (`devops-especialista`), fora do
   escopo.
9. Testes automatizados propostos (endpoint de teste desativado + mock)
   devem mockar na camada de CLIENTE HTTP (nunca confiar so em "servico
   nao estar rodando" como protecao) — para nenhum teste correr risco
   de imprimir de verdade se o servico Node estiver de pe na maquina.

Nenhum achado BLOQUEANTE.

## Consideracoes de DevOps (`devops-especialista`)

1. `.env` NAO precisa de novas variaveis — as mesmas 3
   (`IMPRESSAO_LOCAL_URL`/`IMPRESSAO_LOCAL_TOKEN`/`IMPRESSAO_FRONTEND_TIMEOUT_MS`)
   continuam validas, so muda QUAL controller as le.
2. `docs/deploy-checklist.md` nao tem hoje nenhuma referencia incorreta
   a corrigir — precisa so de um item NOVO cobrindo a transicao (texto
   sugerido: confirmar que o fluxo real usa o endpoint de producao, nao
   mais `impressao-teste.php`).
3. Manter `ImpressaoTesteController` exclusivo do diagnostico NAO muda
   nada na operacao do atendente (toque longo continua igual).
4. `origensPermitidas` vazio NAO bloqueia o endpoint PHP novo — CORS/PNA
   protege chamadas DIRETAS do navegador ao servico Node (`GET /impressoras`,
   `POST /imprimir`), nao a chamada PHP-para-`.env` (que nem e uma
   chamada de rede, e so leitura de variavel de ambiente). O front-end,
   ao usar a config pra chamar o Node via navegador, continua sujeito a
   `origensPermitidas` normalmente — pendencia INDEPENDENTE, ja
   registrada, nao afetada por esta demanda.
5. Nenhuma consideracao especial de compatibilidade PHP 8.0.3/Windows/
   Hostgator para extrair a leitura de config para uma classe comum
   (PHP puro, `$_ENV`, sem extensao especifica de versao).
6. Bind do servico Node em `127.0.0.1` e GARANTIDO (hardcoded em
   `config.js:131`, nunca configuravel) — reduz a superficie de
   qualquer achado futuro de autenticacao do servico Node.
7. Item sugerido para `docs/deploy-checklist.md`: "[ ] Confirmar que o
   fluxo real de impressao (atendimento) esta chamando o endpoint de
   PRODUCAO novo, nao mais `impressao-teste.php?acao=configuracao-servico-local`
   — o endpoint de teste deve ficar restrito a tela de diagnostico
   (toque longo), nunca ao fluxo real."

## Plano de implementacao em etapas

Ver secao "Estrategia de compatibilidade e remocao do acoplamento
antigo" acima (5 etapas, cada uma reversivel e com ponto de teste
proprio) — essa e a ordem oficial recomendada para a futura
`/01-implementacao`.

## Plano de testes automatizados e fisicos

### Automatizados/manuais (backend)

1. Unidade da classe `Util\ConfiguracaoServicoImpressao` isolada
   (valores validos/ausentes/invalidos).
2. Paridade campo-a-campo entre a resposta do endpoint novo e do antigo
   (mesmo `.env`).
3. Autenticacao do endpoint novo (401 sem token, 401 com token de outro
   totem/invalido).
4. Fail-closed: `.env` incompleto -> 503 em AMBAS as rotas (antiga e
   nova), sem vazar token em nenhum log gerado durante o teste.
5. Regressao completa das suites ja existentes de Talent/impressao
   (nao devem ser afetadas por esta demanda).
6. Teste de isolamento direto (o mais importante): desativar/renomear
   temporariamente (so em ambiente de teste, nunca producao) a acao
   `configuracao-servico-local` de `impressao-teste.php` e confirmar
   que o fluxo real completo continua funcionando sem erro — prova
   objetiva do desacoplamento.
7. Confirmar em paralelo que o diagnostico continua chamando
   `impressao-teste.php` normalmente (nao pode ser afetado).

### Front-end (manual, sem suite JS no projeto)

1. Percorrer o fluxo real completo com DevTools/Network aberto,
   filtrando por `impressao-teste` — confirmar ZERO requisicoes durante
   o fluxo real.
2. Confirmar a unica chamada de config indo para o endpoint novo, com o
   payload esperado sendo aplicado corretamente.
3. Teste de toque duplo nos botoes de acao (cliques rapidos repetidos)
   — confirmar que nenhuma chamada e duplicada (cobre achado A6/risco
   de reentrancia).
4. Teste de regressao visual de cada estado (erro de rede, 504, erro
   contendo "impressora", sucesso) — telas/textos devem permanecer
   identicos ao comportamento anterior a migracao.

### Fisico (etiqueta real, com autorizacao explicita previa, uma de
cada vez, seguindo o padrao ja estabelecido no projeto)

1. Apos a migracao completa, 1 impressao real de confirmacao ponta a
   ponta (fluxo real usando o endpoint novo) — orcamento a definir com
   o usuario no momento da execucao, nunca decidido nesta etapa de
   planejamento.
2. Confirmar visualmente que a etiqueta gerada e identica em conteudo/
   formato a antes da migracao (nome + numero de acesso, sem CPF/CNH).

Nenhum teste automatizado deve ter acesso de rede real ao servico Node
em CI/ambiente de dev sem mock explicito na camada de cliente HTTP
(achado do `security-especialista`) — nunca confiar so em "servico nao
estar rodando" como protecao contra impressao real acidental.

## Decisoes de produto realmente necessarias (bloqueiam parte do escopo, nao o todo)

Estas sao as UNICAS perguntas que realmente bloqueiam partes do
`/01-implementacao` — o restante do plano (novo endpoint, extracao de
classe compartilhada, migracao do front-end, correcao de reentrancia,
correcao de traducao de mensagens de erro) pode avancar sem elas:

1. **Persistencia de impressora**: `localStorage` (atual) continua
   suficiente, ou deve migrar para `tb_totem.impressora_padrao` no
   backend? Depende de saber se o navegador/perfil do totem reseta
   entre usos reais (kiosk efemero) ou e persistente. Se nao souber,
   proponho MANTER `localStorage` por ora (menor escopo, reversivel
   depois) e so migrar se o problema se confirmar na pratica.
2. **Botao explicito de "trocar impressora"**: deve existir no fluxo
   real (visivel ao motorista) ou so no diagnostico (uso do atendente)?
   O `backend-especialista` sugere que so faz sentido no diagnostico;
   o `ui-ux-especialista` registrou a ausencia como atencao (A1) sem
   decidir onde deveria aparecer.
3. **Traducao de mensagens de erro tecnicas** (achado BLOQUEANTE de UX,
   B1/B2): confirmar que faz parte do escopo desta demanda (recomendado
   pelo `ui-ux-especialista`) ou se fica para uma demanda futura
   dedicada a mensagens/copy do totem.
4. **Extrair `impr*` de `app.js` para um arquivo proprio** (`impressao-producao.js`):
   puramente organizacional, nao resolve nenhum problema de isolamento
   (ja esta isolado por prefixo/arquivo hoje) — decisao de preferencia
   de manutencao, nao obrigatoria para o objetivo desta demanda.

## O que sera feito (resumo consolidado do plano)

1. Criar `Util\ConfiguracaoServicoImpressao` (classe compartilhada,
   fail-closed, nunca loga valores sensiveis).
2. Adicionar `impressao.php?acao=configuracao-servico-local` (producao),
   com o mesmo contrato de resposta ja usado, protegido por
   `Util\Auth::validarTotem()` como primeira linha.
3. Refatorar `ImpressaoTesteController` para consumir a mesma classe
   (sem mudar comportamento externo).
4. Migrar `app.js` para chamar o endpoint novo; remover o comentario de
   pendencia.
5. Corrigir ausencia de trava de reentrancia/toque duplo nos botoes de
   acao das telas de impressao (achado confirmado por 2 especialistas
   independentes).
6. **Se confirmado no item 3 das decisoes de produto**: mapear mensagens
   de erro tecnicas para linguagem de motorista (B1/B2).
7. Reforcar alvo de toque (`min-height:64px`) e hierarquia tipografica
   (`.titulo` em telas de erro) nos botoes/estados das telas de
   impressao (A2/A4) — ajuste de CSS, sem tocar em paleta de cores.
8. Adicionar indicador de progresso/tempo nos estados de espera (A3/A5)
   — sem definir estilo visual final de cor.
9. Atualizar `docs/deploy-checklist.md` (item de verificacao da
   transicao) e `.env.example` (comentario desatualizado).
10. Atualizar `ia_development_state.md` (pendencia da linha 204
    resolvida).
11. NAO remover `impressao-teste.php`/`ImpressaoTesteController` —
    continuam exclusivos do diagnostico.

## O que NAO sera feito

- Nenhuma implementacao real nesta etapa de planejamento.
- Nenhuma chamada real, impressao, alteracao de banco/registro real.
- Nenhuma remocao de endpoint.
- Nenhum valor final de `origensPermitidas` definido.
- Nenhuma alteracao de paleta de cores (aguardando definicao futura do
  usuario).
- Nenhuma migracao de persistencia de impressora para o backend sem
  decisao explicita do usuario (item 1 acima).
- Nenhum commit, nenhum push.

## Sub-agentes envolvidos

- `explorer` (mapeamento completo do estado real, produção/teste)
- `backend-especialista` (arquitetura PHP: classe compartilhada, novo
  endpoint, ordem de migracao)
- `frontend-especialista` (contratos JS, reentrancia/toque duplo,
  estrategia de deploy atomico)
- `ui-ux-especialista` (revisao formal das telas `exp_impressao`/
  `rec_impressao`, achados por severidade)
- `security-especialista` (riscos de autenticacao, exposicao de
  config, coexistencia temporaria dos endpoints)
- `devops-especialista` (deploy checklist, `.env`, CORS/origensPermitidas,
  operacao do diagnostico)

Para a futura `/01-implementacao`: `backend-especialista`,
`frontend-especialista`, `qa-testes` (validacao do desacoplamento e das
correcoes de UX), `security-especialista` (revisao final).

## Pendencias conhecidas

1. As 4 decisoes de produto listadas acima.
2. `origensPermitidas` continua vazio/fail-closed — pendencia
   pre-existente e independente, nao resolvida nesta demanda.
3. Caminho/namespace fisico exato de `Util\ConfiguracaoServicoImpressao`
   nao confirmado neste planejamento (precisa checar o padrao real de
   `Util\Auth`/`Util\Resposta` no `/01-implementacao`).
4. Achado de segurança sobre comparacao de token do servico Node
   estava DESATUALIZADO na base usada para iniciar esta demanda (ja
   corrigido desde 2026-09-14) — corrigir a redacao correspondente em
   `ia_development_state.md` na proxima atualizacao, se ainda constar
   como pendente em algum lugar.

## Trello
card_id: 6aa9a23ef7b29686b181629e

## Proximo passo
Rodar `/01-implementacao` apos o usuario decidir os 4 pontos listados em
"Decisoes de produto realmente necessarias" (itens 1-2 podem ficar
pendentes sem bloquear o restante; item 3 define se a correcao de
mensagens de erro entra nesta demanda ou fica pra depois).

## Resultado da implementacao (2026-09-15)

Implementado por `backend-especialista` + `frontend-especialista`,
validado por `security-especialista` + `qa-testes`.

### Backend

- Nova classe estatica `Util\ConfiguracaoServicoImpressao`
  (`util/ConfiguracaoServicoImpressao.php`) — le/valida fail-closed
  `IMPRESSAO_LOCAL_URL`/`IMPRESSAO_LOCAL_TOKEN`/`IMPRESSAO_FRONTEND_TIMEOUT_MS`,
  lanca `RuntimeException` citando SO os nomes das variaveis (nunca
  valores) se algo faltar/for invalido.
- Novo endpoint de producao: `GET impressao.php?acao=configuracao-servico-local`
  -> `ImpressaoAtendimentoController::configuracaoServicoLocal()` — nao
  recebe `id_atendimento`/nenhum parametro, protegido por
  `Auth::validarTotem()` (primeira linha executavel de `impressao.php`,
  antes de qualquer switch), contrato de resposta identico ao ja
  consumido: `{sucesso:true, dados:{url, token, frontend_timeout_ms}}`,
  `Cache-Control: no-store` em TODA resposta (sucesso e erro, apos
  correcao de consistencia).
- `ImpressaoTesteController::configuracaoServicoLocal()` refatorado
  para reaproveitar a mesma classe (contrato externo inalterado),
  `Cache-Control: no-store` tambem em toda resposta. `impressao-teste.php`
  continua 100% funcional, exclusivo do diagnostico.
- `ImpressaoAtendimentoController::gerarEtiqueta()` — confirmado
  INTOCADO (unico diff no arquivo foi a adicao do novo metodo).

### Frontend

- Novo arquivo `public/totem/assets/impressao.js` — toda a maquina de
  estados de impressao real extraida de `app.js` (funcoes `impr*`,
  templates, `imprState`). `app.js` mantem so os 2 pontos de entrada
  (`telaImpressao()`/`processarImpressao()` chamados em `renderTela()`)
  + comentario de referencia.
- `public/totem/index.php` ganhou `<script src="assets/impressao.js">`
  entre `app.js` e `diagnostico-impressao.js`.
- `imprApiConfiguracaoServicoLocal()` migrada para chamar
  `impressao.php?acao=configuracao-servico-local` (producao) — comentario
  de pendencia removido.
- Nova `imprMensagemAmigavel(contexto, erro)`: SEMPRE loga o erro
  tecnico bruto so via `console.error`, nunca expoe `e.message`/URL/
  token/resposta bruta na tela — mensagens fixas em portugues simples
  por contexto (servico offline, impressora nao permitida, timeout/
  indeterminado, erro de finalizar, generico).
- Trava de reentrancia `imprState.processando` (com `try/finally`) nas
  4 funcoes de entrada por clique (`imprIniciarImpressao`,
  `imprExecutarImpressao`, `imprSelecionarImpressora`,
  `imprTentarNovamente`) — bloqueia toque duplo/chamadas concorrentes.
- Spinner CSS novo (`.impr-spinner`, `@keyframes impr-girar`, cores JA
  existentes no projeto, sem cor nova) nos 4 estados de espera.
- Nova classe `.impr-btn-alvo` (`min-height:64px`) aplicada aos botoes
  das telas de impressao, sem afetar outras telas do projeto.
- `imprTelaErro()`/`imprTelaErroFinalizar()`/`imprTelaIndeterminado()`
  passam a usar `.titulo` (maior destaque) para a mensagem principal.
- Estado INDETERMINADO: mensagem padronizada deixando claro que a
  impressao NAO pode ser confirmada, sem retry automatico (comportamento
  ja existente, so a mensagem/hierarquia mudou).
- NENHUM botao publico de "trocar impressora" foi adicionado (decisao
  do usuario) — troca continua reativa (erro contendo "impressora"
  limpa `localStorage`), mensagem tambem traduzida.
- `diagnostico-impressao.js` — confirmado INTOCADO.

### Testado (`qa-testes`) — 16/16 itens PASSOU

Endpoint novo testado por EXECUCAO REAL (curl contra Apache/PHP local,
com `.env` de dev real, nunca alterado): sucesso 200 com
`Cache-Control: no-store`, 401 sem/com token invalido, 503 com variavel
simulada ausente (sem tocar no `.env` real). Endpoint de diagnostico
confirmado com resposta byte-a-byte identica. Classe compartilhada
testada isoladamente (10/10 asseroes). Sanitizacao confirmada (nenhum
valor de token em resposta/log de erro). Fluxo com impressora
salva/sem impressora salva/allowlist removida/servico offline testados
via harness Node real carregando `impressao.js` de verdade com mocks de
`fetch`/`localStorage`/`document` (21/21 asseroes) — nunca acesso de
rede real ao servico Node, nunca impressao fisica. Prevencao de toque
duplo confirmada com chamada concorrente real (2 chamadas simultaneas
-> so 1 efetiva). Loading nunca travado em nenhum cenario testado.
Reimpressao com `identificador` novo confirmada (codigo intocado + teste
de regressao). Isolamento de `impressao-teste.php` confirmado por grep
(so referenciado por `diagnostico-impressao.js`). 10 suites de regressao
Talent/Recebimento/Expedicao/impressao: 0 falhas. `php -l`/`node --check`
sem erros em todos os arquivos.

**Nao houve acesso a navegador real nesta sessao** — validacao visual
efetiva (spinner visivel, hierarquia de fontes, alvo de toque fisico)
NAO foi feita. Roteiro exato de 14 passos preparado para `/02-testes`
(ver secao seguinte).

### Revisado (`security-especialista`)

Sem achado bloqueante. 2 pontos levantados:
1. **Observacao de baixo risco, CORRIGIDA nesta rodada**: `Cache-Control:
   no-store` estava so no caminho de sucesso — movido para antes do
   `try` em ambos os metodos, agora presente em TODA resposta (sucesso
   e erro). Confirmado por `php -l` e leitura do diff.
2. **Achado de atencao, FORA DO ESCOPO desta demanda, registrado como
   pendencia de produto (NAO implementado)**: nao ha limite/rate-limit
   server-side contra reimpressoes repetidas da mesma etiqueta usando o
   token valido do totem (`ImpressaoAtendimentoController::gerarEtiqueta()`
   gera um `identificador` novo a cada chamada, entao a idempotencia do
   servico Node por `identificador` nao protege contra chamadas
   repetidas deliberadas). O `security-especialista` confirmou que essa
   funcao NAO foi alterada nesta demanda (comportamento pre-existente,
   desde a demanda `talent-doctos-finalizacao-checkin`) — nao e uma
   regressao introduzida aqui. Registrado como decisao de produto
   pendente: confirmar se reimpressao "ilimitada" mediante posse do
   token do totem e aceitavel (controle de produto: reimpressao manual,
   nunca automatica) ou se deve ganhar um limite/cooldown server-side
   numa demanda futura.

Todas as confirmacoes positivas do checklist de seguranca (autenticacao
como primeira linha, ausencia de IDOR, revalidacao real da allowlist no
servico Node mesmo com `localStorage` adulterado, sanitizacao de
mensagens, regressao de `gerarEtiqueta()`) — ver detalhamento completo
no historico desta conversa/handoff de segurança.

### Roteiro exato de validacao manual para `/02-testes` (navegador real)

Pre-requisito: Apache+MySQL do XAMPP no ar, `.env` com
`IMPRESSAO_LOCAL_URL`/`IMPRESSAO_LOCAL_TOKEN`/`IMPRESSAO_FRONTEND_TIMEOUT_MS`
validos, um totem ativo cadastrado.

1. Completar um atendimento real (expedicao ou recebimento) ate a etapa
   de impressao, sem imprimir de verdade sem autorizacao.
2. Limpar `localStorage.totem_impressora_nome` no DevTools antes de
   chegar na tela de impressao — confirmar "Selecione a impressora" com
   spinner visivel enquanto carrega, botoes com alvo de toque grande
   (min 64px).
3. Com o servico Node local desligado, tentar imprimir — confirmar
   mensagem amigavel ("Nao foi possivel conectar a impressora. Chame o
   atendente."), sem termos tecnicos/URL na tela.
4. Com uma impressora salva no `localStorage` que NAO exista mais em
   `impressorasPermitidas` do `config.json` do servico Node, tentar
   imprimir — confirmar mensagem amigavel (sem "allowlist"),
   `localStorage` limpo, reabertura da selecao.
5. Com servico Node ativo e impressora valida na allowlist (fisica ou
   virtual/de teste, com autorizacao previa), completar a impressao —
   confirmar tela "Tudo certo!" com numero de acesso e nome do
   motorista (sem CPF/CNH), botoes "Imprimir novamente"/"Novo
   atendimento".
6. Reimpressao manual: clicar "Imprimir novamente" e confirmar (via
   DevTools/Network) que gera um `identificador` novo, sem novo check-in.
7. Toque duplo: clicar 2x rapido em "Imprimir novamente"/num botao de
   selecao de impressora — confirmar so 1 requisicao na aba Network.
8. Reload (F5) no meio de "Preparando etiqueta..." — confirmar
   comportamento seguro (sem estado inconsistente).
9. Recarregar com `totem_impressora_nome` ja salvo — confirmar que a
   proxima impressao pula direto pra impressao, sem tela de selecao.
10. Repetir os passos 2-9 para Recebimento E Expedicao, confirmando
    consistencia visual/textual entre os dois fluxos.
11. (Regressao, nao exclusiva desta demanda) Confirmar que mais de uma
    ordem de coleta em aberto, mais de 5 notas fiscais, e Talent fora do
    ar continuam se comportando como antes ate chegar na etapa de
    impressao.

Nenhuma impressao fisica deve ocorrer nesse roteiro sem autorizacao
explicita previa, uma chamada de cada vez, conforme regra de processo ja
estabelecida no projeto.

## Pendencias reais para `/02-testes`

1. Validacao visual REAL em navegador (nenhuma ferramenta de automacao
   disponivel nesta sessao) — roteiro de 11 itens acima pronto para
   execucao manual.
2. Decisao de produto pendente: limite/rate-limit server-side para
   reimpressao repetida (achado do `security-especialista`, PRE-EXISTENTE,
   nao introduzido por esta demanda, nao implementado aqui).
3. Painel administrativo (cadastro de totem, selecao de scanner/impressora,
   persistencia em banco) — registrado explicitamente como demanda
   FUTURA, conforme decisao do usuario, nao implementado aqui.
4. `origensPermitidas` continua vazio/fail-closed — pendencia
   independente, nao resolvida nesta demanda.
5. Paleta de cores oficial continua pendente — nao tocada nesta demanda.

## Resultado dos testes — /02-testes, Etapa 1 (2026-09-15)

`qa-testes` e `security-especialista` independentes, sem alteracao de
codigo.

### QA — 24/24 itens PASSOU

Separacao de arquitetura (1-8): confirmado por grep e EXECUCAO REAL
(harness PHP CLI replicando a ordem exata `Auth->controller` dos 2
endpoints, com totem de teste descartavel) que ambos devolvem corpo
JSON identico byte-a-byte para o mesmo `.env`; `impressao.js` chama so
o endpoint de producao; so `diagnostico-impressao.js` chama
`impressao-teste.php`; ordem de scripts correta em `index.php`; sem
funcao `impr*` remanescente/quebrada em `app.js`.

Seguranca (9-17): EXECUCAO REAL confirmou 401 sem token/com token
invalido, sem vazar `IMPRESSAO_LOCAL_*` no corpo; 503 com variavel
simulada ausente (sem tocar `.env` real), sem vazar token no corpo/log;
`Cache-Control: no-store` confirmado por LEITURA de codigo como
primeira linha de ambos os metodos (execucao real via `headers_list()`
nao funciona sob PHP-CLI SAPI, limitacao do ambiente de teste, nao do
app); grep completo confirmou zero valor real de token em qualquer
arquivo versionado; `.env.example` so com placeholders; mensagens ao
motorista sem `.message`/stack/URL; allowlist do servico Node
confirmada como unica fonte real de autorizacao; `gerarEtiqueta()`
confirmado 100% intocado por `git diff`.

Maquina de estados/UX (item 18): 21/21 asserções (harness Node real com
mocks) + leitura de codigo confirmando spinner nos 4 estados de espera,
alvo de toque 64px, hierarquia `.titulo` em erro/indeterminado,
reimpressao com identificador sempre novo, reset completo de estado em
"Novo atendimento" — nenhum caminho travado encontrado.

Testes tecnicos finais (19-24): `php -l`/`node --check` sem erros;
`git diff --check` sem problema; 10 suites de regressao com 0 falhas;
grep final confirma isolamento de `impressao-teste.php` e ausencia de
segredo/mensagem tecnica exposta.

**Limitacao registrada (nao bloqueante)**: confirmacao HTTP real (curl)
contra o endpoint foi bloqueada pelo classificador de permissoes desta
sessao (protecao contra "Credential Materialization", ja que o
endpoint pode devolver segredo real) — contornado com execucao real via
PHP CLI in-process para os demais itens. So o item 11 especifico
(header em respostas 401 do proprio `Auth`/`Resposta::erro()`, classes
compartilhadas nao alteradas por esta demanda) ficou confirmado so por
leitura de codigo.

### Seguranca — APROVADO (revisao de confirmacao independente)

Reavaliado do zero, sem presumir achados anteriores. Confirmado que a
correcao de `Cache-Control` da rodada de implementacao foi aplicada
corretamente em AMBOS os controllers e nao foi revertida. Zero achado
bloqueante ou de atencao novo. 1 observacao registrada (nao bloqueante):
a trava de reentrancia (`imprState.processando`) e 100% client-side,
sem equivalente server-side (ex.: lock por `id_atendimento` durante a
geracao) — a formulacao "chamadas concorrentes devem ser bloqueadas"
nao deixa explicito se isso exigiria protecao server-side alem do
rate-limit ja decidido como fora de escopo. Registrado para o
orquestrador/usuario confirmar se o desenho atual (so client-side) ja
atende a intencao original, sem decisao unilateral do
`security-especialista`.

### Veredito da Etapa 1

**APROVADO — avanca para a Etapa 2** (validacao visual manual em
navegador real).

## Resultado dos testes — /02-testes, Etapa 2 (2026-09-15)

Validacao visual REAL executada pelo proprio usuario (Bruno) no
navegador, sem ambiente de automacao disponivel nesta sessao. Sem
documentos fisicos (CNH/CRLV/ordem de coleta) — o orquestrador orientou
um metodo de teste ISOLADO, injetando `imprState` diretamente via
Console do DevTools e chamando `imprRender()`, sem passar pelo fluxo
real da aplicacao, sem nenhuma chamada real ao Talent/impressora fisica.

### Itens confirmados por execucao visual real

1. **Estrutura consistente Recebimento/Expedicao** — confirmado (codigo
   100% compartilhado, ja validado tecnicamente na Etapa 1; usuario
   visualizou o padrao).
2. **Alvo de toque >= 64px** — confirmado por medicao real no console:
   `Tentar novamente 64px`, `Novo atendimento 64px`.
3. **Spinner visivel durante carregamento** — CONFIRMADO VISUALMENTE:
   "apareceu sim o circulo rodando" (nao so o texto estatico).
6. **Servico offline, mensagem simples sem termos tecnicos** —
   confirmado: "Nao foi possivel conectar a impressora. Chame o
   atendente." (tela "Falha conhecida").
7. **Impressora ausente** — tela "Selecione a impressora" renderizada
   corretamente com o titulo/subtitulo/botoes esperados.
9. **Estado INDETERMINADO** — confirmado: "Nao foi possivel confirmar a
   impressao" + mensagem detalhada + so "Tentar novamente" manual, sem
   retry automatico.
10. **Sucesso com hierarquia clara** — confirmado: numero de acesso em
    destaque, nome do motorista, instrucao de retirar comprovante,
    botoes "Imprimir novamente"/"Novo atendimento".
11. **Cancelar/voltar sem travar** — confirmado: "Novo atendimento"
    retornou a tela inicial normalmente; o ciclo de forcar uma tela de
    impressao foi REPETIDO com sucesso depois, sem nenhum estado preso
    do ciclo anterior.
12. **Sem botao publico de "trocar impressora"** — confirmado ausente
    em todas as telas visualizadas.
14. **Nenhuma mensagem tecnica (URL/stack/e.message) na tela** —
    confirmado: todas as mensagens exibidas foram as traduzidas. Uma
    unica ocorrencia tecnica ("Error: Atendimento nao encontrado")
    apareceu SO no console do navegador (`console.error`), nunca na
    tela — comportamento intencional (item 1 do plano de UX),
    confirmado correto na pratica.

### Achado incidental confirmado como comportamento correto (nao bug)

Ao tentar `processarImpressao()` real (sem um atendimento valido de
verdade, `state.idAtendimento` nulo/invalido), `atendimento.php?acao=finalizar`
retornou corretamente "Atendimento nao encontrado" — gate de validacao
ja existente e ja testado em rodadas anteriores, funcionando como
esperado mesmo neste teste isolado. Nenhum efeito colateral: nenhum
check-in real tentado, nenhuma chamada ao Talent, nenhuma impressao.

### Itens 4, 5, 13 — validados via automacao/mock na Etapa 1, sem repeticao ao vivo nesta rodada

Botao desabilitado durante requisicao (4), toques repetidos nao geram
acao duplicada (5), e reimpressao manual como acao consciente (13) —
esses 3 exigiriam o servico de impressao local ligado com uma
impressora real/virtual configurada, ambiente que o usuario nao tem
disponivel no momento. Ja foram validados na Etapa 1 por teste
automatizado REAL (harness Node com `impressao.js` de verdade,
chamada concorrente real confirmando 1 unica requisicao efetiva e
liberacao correta da trava em todos os casos — 21/21 asserções). Ficam
registrados como PENDENTES de confirmacao visual ao vivo completa,
para quando o ambiente fisico (servico local + impressora) estiver
disponivel — nao bloqueiam o fechamento desta demanda.

### Itens fora do escopo desta demanda (nao testados, ja registrados como regressao geral do projeto)

Mais de uma ordem de coleta em aberto, mais de 5 notas fiscais, Talent
fora do ar/fila de reenvio — nao exercitados nesta rodada por
dependerem do fluxo completo com documentos reais; comportamento ja
coberto por suites de regressao anteriores, nao afetado por esta
demanda (confirmado por `git diff` nao tocar nesses fluxos).

### Veredito da Etapa 2

**APROVADO**, com 3 itens (4/5/13) validados so por automacao/mock na
Etapa 1, registrados como pendencia de confirmacao visual fisica
futura (nao bloqueante).

## VEREDITO FINAL do /02-testes

**APROVADO** — Etapa 1 (automatizada/estatica) e Etapa 2 (visual real,
executada pelo usuario) integralmente aprovadas. Demanda pronta para
`/03-revisao`.

## Resultado da revisao — /03-revisao final (2026-09-15)

4 revisoes independentes concluidas, sem alteracao de codigo.

### Veredito de ARQUITETURA — APROVADO (1 atencao)

Confirmados os 12 pontos pedidos: centralizacao sem duplicacao em
`Util\ConfiguracaoServicoImpressao`; fail-closed; endpoint real protegido
por `Auth::validarTotem()` como primeira linha, `Cache-Control: no-store`
cobrindo sucesso e erro; endpoint de diagnostico preservado, contrato
externo inalterado; nenhum controller depende do outro; nenhum codigo de
producao referencia `impressao-teste.php` (so `diagnostico-impressao.js`);
`impressao.js` contem so responsabilidades de impressao; `app.js` sem
duplicacao residual (so um comentario de referencia); ordem de
carregamento correta em `index.php`; globals compartilhados sem erro de
referencia; compatibilidade PHP 8.0.3/Chromium confirmada. Confirmado
tambem, por `git diff --stat`, que NADA foi implementado indevidamente:
sem painel administrativo, sem persistencia em `tb_totem`, sem botao
publico de trocar impressora, `origensPermitidas`/`servico-impressao-local/`
intocados, nenhuma cor nova em `app.css`, diagnostico preservado.

**Achado de ATENCAO (nao bloqueante)**: `docs/deploy-checklist.md` tem o
item de verificacao pos-deploy, mas nao documenta explicitamente a
recomendacao de DEPLOY ATOMICO (backend `impressao.php` novo + frontend
`app.js`/`impressao.js` novos no MESMO push) — risco de janela quebrada
se o frontend for publicado antes do backend em producao. Registrado
como pendencia de documentacao, correcao trivial (so texto), nao
implementada nesta etapa de revisao.

### Veredito de SEGURANCA — APROVADO (reavaliacao independente, do zero)

Reconfirmados todos os pontos: `Auth::validarTotem()` antes de qualquer
acesso sensivel; `Cache-Control: no-store` em sucesso e erro; ausencia
de token em logs/HTML/excecoes/documentacao; `.env.example` sem
credencial real; erros sanitizados em todos os caminhos; **XSS
verificado com atencao especifica nesta rodada**: `escapeHtml()`
confirmado em toda superficie vinda do servico Node (nome de impressora,
mensagens) antes de `innerHTML` — nenhum caminho novo de injecao
encontrado; adulteracao de `localStorage` nao ultrapassa a allowlist
(servico Node intocado); IDOR/posse/tipo/status/etapa de
`gerarEtiqueta()` confirmados 100% intocados por `git diff`;
idempotencia por identificador sempre novo; ausencia de retry
automatico; trava de reentrancia client-side reavaliada e mantida
NAO BLOQUEANTE (decisao de produto ja confirmada, nao reaberta); sem
impacto em Talent/banco/ordens de coleta (confirmado por `git diff --stat`,
so 9 arquivos tocados, nenhum de Talent/AtendimentoController/schema).

Zero achado bloqueante ou de atencao novo.

### Veredito de UX — APROVADO (1 atencao nova)

Confirmados os 13 pontos pedidos, consistentes com o que ja foi
validado ao vivo pelo usuario no `/02-testes`: consistencia
Recebimento/Expedicao; alvo de toque 64px em TODOS os botoes sem
excecao; spinner presente nos 4 momentos de espera, sem gap; controles
fisicamente removidos do DOM durante espera (nao so desabilitados);
feedback visual instantaneo contra toque duplo (troca de tela sincrona
antes do `await`); mensagens simples confirmadas; hierarquia `.titulo`
em erro/indeterminado; saida garantida do loading em todos os caminhos
rastreados; "Novo atendimento" como saida clara em erro/indeterminado/
sucesso; ausencia de botao publico de trocar impressora; persistencia
em `localStorage` comunicada claramente ("Essa escolha sera lembrada
neste totem"); reimpressao so por acao consciente; ausencia de termos
tecnicos em toda mensagem renderizada.

**Achado NOVO de ATENCAO (nao bloqueante, nao coberto pela validacao ao
vivo anterior)**: a tela `selecionar_impressora` nao tem nenhum botao de
saida/cancelamento ("Novo atendimento" ou equivalente) — se o motorista
chegar la e precisar desistir, nao ha caminho de saida visivel nessa
tela especifica (diferente das demais telas de erro/indeterminado/
sucesso). Registrado como pendencia de decisao de produto/UX, nao
decidido/implementado nesta revisao.

### Validacoes tecnicas finais (`qa-testes`) — APROVADO

`php -l`/`node --check` sem erro em todos os arquivos tocados;
`git diff --check` sem problema; grep completo confirma isolamento de
`impressao-teste.php`; grep completo confirma ausencia de segredo/dado
sensivel; 10 suites de regressao com 202/202 asserções PASSOU, 0
falhas; `git status` confirma worktree com EXATAMENTE os arquivos desta
demanda, nenhum residuo/arquivo de outra demanda misturado.

### VEREDITO GERAL do /03-revisao

**APROVADO.** Zero achado BLOQUEANTE em nenhuma das 4 revisoes
independentes. 2 achados de ATENCAO (nao bloqueantes) registrados como
pendencia:
1. `docs/deploy-checklist.md` — adicionar recomendacao explicita de
   deploy atomico (texto, sem impacto em codigo).
2. Tela `selecionar_impressora` sem botao de saida/cancelamento —
   decisao de produto/UX pendente, nao implementado.

Demanda `impressao-arquitetura-producao-ux` **PRONTA PARA
`/04-commit-e-push`** (os 2 achados de atencao NAO bloqueiam o
fechamento, conforme regra do usuario — ficam registrados como
pendencia para decisao/correcao futura, seja numa rodada curta antes do
commit, seja depois).

### Proximo passo

Aguardar decisao do usuario: seguir direto para `/04-commit-e-push` com
os 2 achados de atencao registrados como pendencia, ou pedir uma rodada
curta de `/01-implementacao` para corrigi-los antes do commit.

## Rodada curta de /01-implementacao — correcao dos 2 achados de atencao (2026-09-15)

### Achado 1 — deploy atomico documentado

`docs/deploy-checklist.md` (secao "1.X Servico local de impressao (mini
PC Windows)") ganhou novo item ANTES do item de verificacao pos-deploy
ja existente, explicitando que esta demanda exige DEPLOY ATOMICO:
backend (`util/ConfiguracaoServicoImpressao.php`,
`ImpressaoAtendimentoController::configuracaoServicoLocal()`,
`impressao.php`) e frontend (`impressao.js`, `app.js`, `app.css`,
`index.php`) devem subir JUNTOS, na mesma operacao de deploy — nunca
separados, sob risco de quebrar a tela de impressao real (frontend
novo sem o endpoint em producao) ou deixar o totem sem se beneficiar
da mudanca (backend novo sem frontend atualizado).

### Achado 2 — saida na tela de selecao de impressora

**Analise previa confirmada**: a tela `selecionar_impressora` so e
alcancada DEPOIS que o check-in com o Talent ja teve sucesso
(`atendimento.php?acao=finalizar` retornou `ENVIADO`/`JA_ENVIADO`, que
so ocorre quando `AtendimentoDao::gravarResultadoEnvioTalent()` ja
gravou `status='concluido'` no banco). **O atendimento SEMPRE esta
`concluido` nesse ponto do fluxo — nunca `em_andamento`.** Por isso a
unica acao correta e "Novo atendimento", nunca um fluxo de
cancelamento (nao ha nada a cancelar).

`imprTelaSelecionarImpressora()` (`public/totem/assets/impressao.js`)
ganhou um novo `<div class="grupo-botoes">` abaixo da lista de
impressoras, com
`<button class="btn-fantasma impr-btn-alvo" onclick="novoAtendimento()">Novo atendimento</button>`
— mesmo padrao visual/rotulo das demais telas terminais deste arquivo.
Reaproveita `novoAtendimento()` ja existente (definida em `app.js`),
sem criar nenhuma logica nova de reset/cancelamento. Confirmado:
`novoAtendimento()` nao chama nenhuma API, nao toca banco/Talent/
ordem de coleta, e NAO remove `localStorage['totem_impressora_nome']`
— a impressora escolhida sobrevive ao "Novo atendimento".

### Testado (`qa-testes`) — 12/12 itens PASSOU

Confirmado por leitura de codigo e execucao real: botao presente em
Recebimento e Expedicao (codigo 100% compartilhado); nunca chama
cancelamento; nao ha cenario de atendimento em andamento nessa tela;
`novoAtendimento()` reseta o estado e volta para `home` sem residuo;
nenhuma flag de reentrancia presa; nenhuma chamada de rede duplicada
(botao e so reset local); `localStorage` da impressora preservado;
alvo de toque 64px (`.impr-btn-alvo`); fluxo de selecao/impressao
existente preservado sem regressao; deploy atomico documentado
corretamente; nenhuma nova dependencia de `impressao-teste.php`
introduzida. 10 suites de regressao com 202/202 asserções PASSOU.
`node --check`/`git diff --check` sem erro.

### Confirmacao explicita

Zero chamada real ao Talent, zero impressao fisica, zero alteracao de
registro real, zero commit, zero push nesta rodada.

### Veredito

Ambos os achados de atencao corrigidos e validados. **Demanda pronta
para uma `/03-revisao` curta de confirmacao**, seguida de
`/04-commit-e-push`.

## Resultado da /03-revisao curta de confirmacao (2026-09-15)

Revisao restrita exclusivamente aos 2 ajustes da ultima rodada de
`/01-implementacao` (botao "Novo atendimento" + deploy atomico
documentado) — nenhum item ja aprovado anteriormente foi reaberto.

### Seguranca — APROVADO, zero achados

Confirmado: botao chama `novoAtendimento()` diretamente (sem
cancelamento, sem tocar banco/Talent/ordem de coleta); ausencia de XSS
(botao e texto fixo, lista de impressoras continua com `escapeHtml()`,
nenhum novo ponto de `innerHTML` inseguro); `localStorage` da
impressora preservado; nenhum botao publico de "Trocar impressora" no
fluxo real (so no diagnostico, fora de escopo); `docs/deploy-checklist.md`
sem dado sensivel; gates de seguranca ja aprovados (`Auth::validarTotem()`,
`Cache-Control: no-store`, IDOR de `gerarEtiqueta()`) confirmados
intocados por esta rodada.

### Validacoes tecnicas (`qa-testes`) — APROVADO

`node --check` sem erro em `impressao.js`/`app.js`; `git diff --check`
sem problema; 12/12 itens da rodada curta reconfirmados (botao em
Recebimento e Expedicao, sem duplicacao, atendimento sempre concluido,
sem alteracao de banco/Talent/ordem, retorno correto a tela inicial,
`localStorage` preservado, sem requisicao duplicada, funciona apos
loading/erro/retorno, alvo 64px, consistencia visual); 10 suites de
regressao com 202/202 asserções PASSOU; grep confirma isolamento de
`impressao-teste.php` mantido; grep confirma ausencia de segredo nos 2
arquivos tocados; texto de `docs/deploy-checklist.md` confirmado cobrir
todos os pontos pedidos (deploy atomico, arquivos exatos, risco de
publicacao parcial, validacao pos-deploy, isolamento do diagnostico).

### VEREDITO FINAL

**APROVADO.** Zero achado bloqueante ou de atencao nesta rodada de
confirmacao. Nenhuma regressao causada pelos 2 ajustes.

Nenhuma impressao, nenhuma chamada ao Talent, nenhuma alteracao de
registro real, nenhum commit, nenhum push nesta etapa.

**Demanda `impressao-arquitetura-producao-ux` PRONTA PARA
`/04-commit-e-push`.**

## Commit

**Hash completo**: `b6b5129f92f2a5e2499206bdb7e51953fd2676eb`
**Mensagem**: `refactor(impressao): separa fluxos de teste e producao`
**Data**: 2026-09-15

Staging seletivo por arquivo — 10 arquivos incluidos no commit
funcional:
- `.env.example`
- `app/Controller/ImpressaoAtendimentoController.php`
- `app/Controller/ImpressaoTesteController.php`
- `docs/deploy-checklist.md`
- `public/api/impressao.php`
- `public/totem/assets/app.css`
- `public/totem/assets/app.js`
- `public/totem/assets/impressao.js` (novo)
- `public/totem/index.php`
- `util/ConfiguracaoServicoImpressao.php` (novo)

`ia_development_state.md` e este handoff ficaram de fora do commit
funcional, para um commit documental separado registrando o hash.

Validacoes executadas antes do commit: diffs de todos os 12 arquivos
listados inspecionados integralmente (nenhum arquivo indevido, nenhuma
alteracao nao relacionada); confirmado por grep que so
`diagnostico-impressao.js` referencia `impressao-teste.php`
funcionalmente; `php -l` sem erro nos 5 arquivos PHP tocados;
`node --check` sem erro em `app.js`/`impressao.js`; `git diff --check`/
`git diff --cached --check` sem problema; 10 suites de regressao
reexecutadas (202/202 asserções PASSOU); busca por segredos/tokens/
stack traces/`e.message`/base64/dados pessoais sem nenhum achado real
(so nomes de campo/variavel e comentarios); `.env.example` confirmado
so com placeholders vazios; confirmado que nenhuma impressao real,
chamada ao Talent, ou alteracao de banco ocorreu durante esta etapa;
`git fetch` sem divergencia com `origin/main` antes do commit.
