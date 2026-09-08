# Handoff — recebimento-clientes-tabela-local

Data: 2026-09-08
Etapa: 00-planejamento

## O que foi pedido

Substituir a fonte de dado da identificacao automatica de cliente via OCR
nas notas do Recebimento: em vez de consultar a API externa de clientes
(https://udlog.online/iaUdlog/api/v1/clientes, via ClienteApiClient),
o sistema passa a consultar uma tabela local nova, populada com uma
lista fechada de clientes (razao social + CNPJ) fornecida pelo usuario.
Isso pausa a etapa /02-testes da demanda recebimento-leitura-notas
(handoff 2026-09-04-recebimento-leitura-notas.md), que estava em
andamento com testes fisicos reais (identificacao por CNPJ exato e fuzzy
match funcionando corretamente contra a API externa, sem nenhuma
identificacao incorreta).

Requisitos explicitos do usuario: tabela local nova ou reaproveitada (a
decidir), CNPJ so com 14 digitos, validacao matematica (DV) de todos os
CNPJs antes da inclusao, deteccao de duplicidade por CNPJ e por razao
social normalizada (sem inserir silenciosamente nada invalido/duplicado),
UNIQUE em CNPJ, migration idempotente e nao destrutiva, razao social
original + normalizada em colunas separadas, consulta CNPJ exato depois
fuzzy local (preservando LIMIAR_MINIMO/MARGEM_MINIMA ja validados),
remocao da dependencia da API externa no fluxo de OCR, remocao de
CLIENTES_API_TOKEN so se comprovadamente sem outro uso, preservacao de
early-stop/Web Worker/rotacao 270/fallback manual, e um plano para medir
a melhora de tempo local vs API externa. Sem tocar Expedicao, CNH, CRLV,
Talent ou a captura do scanner Netum SD-2000.

## Investigacao previa (explorer)

- tb_cliente ja existe no schema (id_cliente, nome, cnpj VARCHAR(20)
  UNIQUE, ativo, criado_em), esta vazia no banco local (0 registros),
  nenhuma migration a altera ate hoje.
- tb_cliente e lida (nunca escrita) por dois fluxos independentes da
  demanda de OCR: NotaFiscalRn processarLeitura (fluxo antigo de chave
  de acesso NF-e), e public/api/cliente.php (autocomplete manual da
  tela rec_cliente). Nenhum dos dois deve ser tocado.
- ClienteApiClient (API externa) e usado em UM UNICO lugar de producao:
  NotaFiscalRn identificarCliente. CLIENTES_API_TOKEN e lido em UM
  UNICO lugar: public/api/nota.php linha 28.
- util/CnpjValidador.php e util/RazaoSocialMatcher.php sao genericos e
  reaproveitaveis sem alteracao de logica.
- Migration mais recente: 002_status_ocr_atendimento_nota.sql (nao toca
  tb_cliente); uma nova seria 003.

## Validacao dos dados fornecidos (backend-especialista)

A lista fornecida pelo usuario tem 38 registros (contados e conferidos
duas vezes), nao 37 como mencionado na descricao da demanda. Pendencia
de confirmacao explicita do usuario antes de escrever a migration final
(nao presumido nem decidido por nenhum sub-agente).

Todos os 38 CNPJs passam na validacao de digito verificador (modulo 11).
Nenhuma duplicidade de CNPJ. Nenhuma duplicidade de razao social
normalizada, inclusive os pares que compartilham prefixo (BARENTZ 04/49,
CARGILL AGRICOLA S/A e CARGILL AGRICOLA S/A 62, CP KELCO MATAO/LIMEIRA,
LOUIS DREYFUS MATAO/BEBEDOURO) permanecem distintos pelo sufixo/filial.

Observacao registrada (nao e bug novo, comportamento pre-existente do
RazaoSocialMatcher herdado sem alteracao): a transliteracao ASCII de
AGRICOLA com acento produz um espaco no meio - nao e regressao desta
demanda.

## O que sera feito

### Arquitetura de dados
- Tabela nova e dedicada, nao reaproveitar tb_cliente - decisao
  justificada: tb_cliente esta vazia e e consumida por dois fluxos fora
  do escopo desta demanda (autocomplete manual, chave de acesso NF-e);
  popula-la so com os clientes da lista fechada mudaria o comportamento
  observavel desses dois fluxos, o que nao foi pedido. Isso nao resolve
  a pendencia mais ampla ja registrada em ia_development_state.md
  (origem/sincronizacao de tb_cliente) - continua em aberto.
- Nome proposto: tb_cliente_ocr (id_cliente_ocr, razao_social,
  razao_social_normalizada, cnpj CHAR(14) UNIQUE, ativo, criado_em,
  indice em razao_social_normalizada). Coluna chamada razao_social (nao
  nome) para ja bater com o shape esperado por
  RazaoSocialMatcher melhorCandidato, sem necessidade de mapeamento de
  campo.
- Migration sql/migrations/003_tb_cliente_ocr.sql: CREATE TABLE IF NOT
  EXISTS (idempotente por natureza) + seed via INSERT IGNORE com os
  registros ja validados e com razao social normalizada pre-computada.
  Validacao (DV + duplicidade) feita antes de escrever a migration, por
  script reaproveitando CnpjValidador/RazaoSocialMatcher - script de
  validacao a commitar em tests/manual/ para reuso se a lista mudar no
  futuro.

### Backend
- Novo App Dao ClienteOcrDao (buscarPorCnpj, listarTodos, mesma
  assinatura de retorno de ClienteApiClient) substitui a API externa nos
  dois pontos de consulta de NotaFiscalRn identificarCliente. Sem
  cache/TTL (mitigava rate-limit de API externa, nao se aplica a
  consulta local).
- NotaFiscalRn identificarCliente: sequencia de passos, early-stop,
  MAX_CNPJS_CANDIDATOS, LIMIAR_MINIMO/MARGEM_MINIMA, exclusao de CNPJ
  UDLOG, tratamento de erro tecnico - tudo identico, so a fonte de dado
  muda (injecao de ClienteOcrDao no lugar de ClienteApiClient).
- public/api/nota.php: remove instanciacao de ClienteApiClient
  (incluindo o setup de cache HTTP), instancia ClienteOcrDao.
- .env.example: remove CLIENTES_API_TOKEN - confirmado sem outro uso em
  qualquer outro fluxo.
- ClienteApiClient.php: mantido como codigo morto documentado (comentario
  indicando ausencia de uso desde a data da migracao), nao apagado nesta
  rodada - remocao fisica fica para pedido explicito futuro.
- util/CnpjValidador.php, util/RazaoSocialMatcher.php: zero alteracao.

### Seguranca (parecer do security-especialista)
- Achado elevado nesta rodada: sem o rate limit natural da API externa
  (60/min), o endpoint identificar-cliente fica mais barato de abusar
  como oraculo de existencia de CNPJ contra a tabela local (consulta SQL
  e ordens de magnitude mais rapida/barata que HTTP). Recomendacao:
  decidir rate limit por totem (nivel de aplicacao, ex. por
  id_totem/janela de tempo) ja na implementacao desta demanda, nao adiar
  indefinidamente.
- Persistencia permanente de CNPJ/razao social (antes so em cache
  transitorio com TTL de 60s) muda a superficie de exposicao -
  recomendado nao commitar os dados reais em texto claro dentro de
  arquivo de migration versionado sem avaliar politica de backup/dump do
  Hostgator (ponto para devops-especialista na implementacao). Estrutura
  (CREATE TABLE) pode ser versionada normalmente; o cuidado e com o
  INSERT de dado real.
- Nenhum vetor novo de SQL injection (PDO prepared statement, mesmo
  padrao ja usado em ClienteDao).
- IDOR, validacao de posse do atendimento/nota e MAX_CNPJS_CANDIDATOS
  continuam preservados - nao dependem da fonte de dado do cliente.
- Lembrete fora do escopo de codigo: revogar CLIENTES_API_TOKEN do lado
  do provedor externo quando a migracao for concluida.

### Testes (plano do qa-testes)
- Suite automatizada atual (29 testes) mantem os mesmos asserts, trocando
  so o fake de ClienteApiClient por um fake de ClienteOcrDao (mesmos
  metodos/contadores) injetado em NotaFiscalRn.
- 5 casos novos: validacao de DV na carga (rejeita/reporta, nao insere
  silenciosamente), duplicidade por CNPJ exato, duplicidade por razao
  social normalizada, constraint UNIQUE a nivel de banco, idempotencia
  da migration rodada duas vezes.
- Medicao de tempo: script manual novo, medindo microtime antes/depois
  da consulta em ambas as versoes (API externa vs tabela local),
  reportando min/media/max sobre N repeticoes - desenhado, nao
  executado nesta etapa.
- Roteiro fisico (12 passos, handoff anterior): passos 2, 3, 4, 7 e 9
  precisam ser refeitos (9 muda de natureza: timeout de API externa vira
  falha de banco local); passos 5, 6, 8, 10, 11, 12 continuam validos
  sem necessidade de repeticao isolada (mas sao revalidados de graca na
  mesma rodada de teste fisico da troca de fonte).

## O que NAO sera feito

- Nenhuma alteracao em Expedicao, CNH, CRLV, Talent ou na captura do
  scanner Netum SD-2000.
- Nenhuma alteracao em NotaFiscalRn processarLeitura (fluxo antigo de
  chave de acesso) nem em public/api/cliente.php (autocomplete manual) -
  ambos continuam usando tb_cliente/ClienteDao, intocados.
- Nenhuma alteracao em util/CnpjValidador.php/util/RazaoSocialMatcher.php.
- Nenhuma alteracao no contrato do endpoint
  nota.php?acao=identificar-cliente nem no front-end (app.js) - o formato
  de request/response nao muda, logo early-stop, Web Worker, fila,
  rotacao 270 e fallback manual continuam intactos sem qualquer edicao.
- Nao resolve a pendencia mais ampla de origem/sincronizacao de
  tb_cliente - essa tabela continua vazia e sem processo de escrita,
  como hoje.
- ClienteApiClient.php nao e apagado fisicamente nesta rodada (fica como
  codigo morto documentado).
- Nenhuma migration real escrita, nenhum codigo implementado, nenhuma
  execucao de teste, nenhum commit/push - esta e so a etapa de
  planejamento.

## Sub-agentes envolvidos

- explorer: mapeamento do estado real de tb_cliente, ClienteApiClient,
  CLIENTES_API_TOKEN e migrations existentes.
- backend-especialista: arquitetura da tabela nova, validacao matematica
  e de duplicidade dos 38 CNPJs/razoes sociais, desenho do DAO,
  mapeamento do que muda/nao muda no backend.
- security-especialista: parecer sobre exposicao de dado permanente,
  remocao segura do token, ausencia de novo vetor de SQL injection, e
  elevacao de severidade do risco de oraculo de CNPJ sem rate limit
  externo.
- qa-testes: plano de adaptacao dos 29 testes automatizados, novos casos
  especificos da tabela local, e mapeamento de quais dos 12 passos do
  roteiro fisico pendente precisam ser refeitos.

## Pendencias conhecidas

1. Confirmar se sao 37 ou 38 clientes - a lista fornecida tem 38
   registros distintos e validos; nenhum foi excluido por conta propria.
   Bloqueia a redacao final da migration/seed ate confirmacao do usuario.
2. Decidir rate limit por totem para identificar-cliente nesta mesma
   implementacao (severidade elevada pelo security-especialista dado que
   a tabela local remove a friccao de rede que hoje limita abuso).
3. Cuidado com backup/dump de banco em producao expondo os 38
   CNPJ/razao social permanentemente - ponto para devops-especialista na
   implementacao, nao resolvido neste planejamento.
4. Lembrete fora do escopo de codigo: revogar CLIENTES_API_TOKEN do lado
   do provedor externo quando a migracao terminar.
5. tb_cliente_ocr fica sem processo de escrita alem do seed inicial - se
   a UDLOG ganhar clientes novos no futuro, sera necessaria uma nova
   migration de dado manual (mesma limitacao estrutural de manutencao
   que ja existe para tb_cliente, agora duplicada em duas tabelas
   paralelas).
6. Ambiguidade esperada e aceitavel entre pares de nomes parecidos
   (BARENTZ 04/49, CARGILL AGRICOLA/CARGILL 62, CP KELCO MATAO/LIMEIRA,
   LOUIS DREYFUS MATAO/BEBEDOURO) - se o OCR capturar so o nome curto
   comum, o criterio de MARGEM_MINIMA corretamente recusa identificacao
   automatica (fallback manual), nao e regressao.
7. Nome final de tabela/DAO (tb_cliente_ocr/ClienteOcrDao) e sugestao
   desta etapa - pode ser renomeado na aprovacao sem impacto no resto do
   plano.
8. Pendencias ja conhecidas de demandas anteriores, nao tocadas por este
   replanejamento: IDOR remanescente em selecionarOrdem/finalizar, bug
   do CNPJ_REGEX (separadores multiplos do OCR), heuristica de extracao
   de razao social candidata com muito ruido (achado confirmado em teste
   fisico real nesta mesma conversa, antes da pausa), JPEG truncado
   aceito, handler global de excecao de banco ausente.

## Proximo passo

Aguardar confirmacao do usuario sobre a divergencia 37 vs 38, e decisao
sobre a recomendacao de rate limit por totem. Depois disso, rodar
/01-implementacao para executar este plano.

## Resultado da implementacao (01-implementacao)

Data: 2026-09-08
Etapa: 01-implementacao

### Decisoes finais confirmadas pelo usuario (substituem o plano original)

- Tabela reaproveitada: `tb_cliente` (singular, ja existente) - confirmada
  por leitura direta do schema antes de qualquer alteracao, conforme
  instrucao explicita do usuario de parar caso so existisse a tabela no
  singular. `tb_cliente_ocr` (proposta do planejamento original) foi
  descartada.
- Colunas existentes (`nome`, `cnpj VARCHAR(20)`) preservadas sem
  renomear/alterar tipo. Adicionada somente `razao_social_normalizada`.
- Decisao deliberada do usuario: os 38 clientes ficam disponiveis para os
  TRES fluxos que leem `tb_cliente` (autocomplete manual, chave de acesso
  antiga, novo OCR local) - efeito colateral nos outros dois fluxos e
  intencional, nao e regressao.
- Confirmados 38 clientes (nao 37) - validado matematicamente (DV modulo
  11) e por duplicidade de CNPJ/razao social normalizada, nenhuma
  encontrada.
- Rate limit por totem implementado nesta mesma rodada (nao adiado).

### O que foi implementado

**Backend:**
- `sql/migrations/003_tb_cliente_razao_normalizada.sql` (novo,
  idempotente): `ALTER TABLE tb_cliente ADD COLUMN razao_social_normalizada`
  (checagem via INFORMATION_SCHEMA.COLUMNS, mesmo padrao da migration 002)
  + seed via `INSERT IGNORE` dos 38 clientes (CNPJ 14 digitos sem
  mascara, razao normalizada pre-computada).
- `sql/migrations/004_tb_rate_limit_ocr.sql` (novo, idempotente):
  `CREATE TABLE IF NOT EXISTS tb_rate_limit_ocr` - janela fixa de 60s,
  limite de 30 chamadas/totem/janela, incremento atomico via
  `INSERT ... ON DUPLICATE KEY UPDATE`.
- `sql/schema.sql` atualizado para instalacoes novas.
- `app/Dao/ClienteDao.php`: novo metodo `listarParaFuzzy()` (retorna
  `razao_social`/`cnpj` ja no shape esperado por
  `RazaoSocialMatcher::melhorCandidato()`, usando a coluna
  `razao_social_normalizada` ja pre-computada em vez de normalizar em
  tempo real a cada chamada).
- `app/Dao/RateLimitOcrDao.php` (novo): `incrementarEContar()` atomico.
- `app/Rn/NotaFiscalRn.php`: `identificarCliente()` passou a usar
  `ClienteDao` em vez de `ClienteApiClient` - early-stop,
  `MAX_CNPJS_CANDIDATOS`, `LIMIAR_MINIMO`/`MARGEM_MINIMA`, exclusao UDLOG
  e tratamento de erro tecnico preservados sem alteracao de logica.
- `app/Controller/NotaController.php`: rate limit (`verificarRateLimit`)
  checado logo apos `Auth::validarTotem()`, antes de qualquer consulta
  cara - responde HTTP 429 + header `Retry-After` quando excedido.
- `public/api/nota.php`: removida instanciacao de `ClienteApiClient`/cache
  HTTP; instancia `ClienteDao` e `RateLimitOcrDao`.
- `.env.example`: removida `CLIENTES_API_TOKEN` (confirmado sem outro uso
  em todo o projeto via grep).
- `app/Rn/ClienteApiClient.php`: mantido no codigo, com comentario de
  "sem uso em producao desde 2026-09-08" - nao apagado.
- `tests/manual/teste_identificar_cliente.php`: fake `ClienteApiClientFake`
  substituido por `ClienteDaoFake`.

**Frontend:**
- `public/totem/assets/app.js`: `IDLE_MS` alterado de 45000 para 180000
  (3 minutos). `IDLE_ABANDONO_MS` (30s, contador pos-modal) preservado
  sem alteracao. Nenhuma outra linha do arquivo tocada nesta tarefa.

### Correcao pos-validacao - encoding corrompido em 8 registros

O `qa-testes`, validando de forma independente, encontrou que o `INSERT`
da migration 003 gravou 8 dos 38 nomes com encoding corrompido (mojibake -
charset de cliente divergente na execucao, nao um erro no arquivo da
migration, que estava em UTF-8 correto). Corrigido via `UPDATE` pontual
(por `id_cliente` + `cnpj`, sem tocar nos outros 30, sem nova migration):
ids afetados 10, 11, 16, 22, 23, 25, 33, 35 (BLUE CUBE, CARGILL AGRICOLA,
CP KELCO MATAO, HAIFA QUIMICA, HIGHTEC POLYMERS, INTERCOM, NEXO
INTERNATIONAL, OMYA) - todos corrigidos para a grafia UTF-8 correta,
identica a fornecida originalmente pelo usuario (incluindo peculiaridades
de grafia como "COMECIO" sem R e "MPORTACAO" sem I, preservadas
literalmente por serem a grafia original fornecida, nao erro de digitacao
nosso).

Durante a correcao, 2 imprecisoes de transcricao nos ids passados na
tarefa de correcao foram identificadas e corrigidas pelo proprio
backend-especialista por conferencia direta com o arquivo da migration
(fonte confiavel) antes de aplicar qualquer UPDATE - nenhum dado foi
gravado sem essa verificacao cruzada.

`razao_social_normalizada` desses 8 registros nunca foi afetada pela
corrupcao (ja era ASCII puro por design) - confirmado que continua
batendo com `RazaoSocialMatcher::normalizar()` aplicado ao nome corrigido.
Revalidado: 29/29 testes automatizados apos a correcao, zero registros
remanescentes com padrao de corrupcao em `tb_cliente.nome`.

### Validacoes executadas (nao so lidas - execucao real)

- Migration 003 e 004 aplicadas no banco local `udlog_totem`, rodadas 3
  vezes cada (pelo backend-especialista e depois de forma independente
  pelo qa-testes) - idempotentes, sem duplicar linhas, sem erro.
- `tb_cliente`: exatamente 38 registros novos, zero CNPJ duplicado,
  `razao_social_normalizada` preenchida em 100% dos registros.
- 29/29 testes automatizados (`tests/manual/teste_identificar_cliente.php`)
  - executado pelo backend-especialista, depois de forma independente
    pelo qa-testes, e novamente apos a correcao do encoding.
- `php -l` sem erros em todos os arquivos alterados/criados.
- `node --check public/totem/assets/app.js` sem erros.
- Testes HTTP reais (curl) contra `nota.php?acao=identificar-cliente`:
  CNPJ exato mascarado identifica corretamente; fuzzy com erro de
  digitacao identifica corretamente; 30 chamadas permitidas, 31a chamada
  retorna HTTP 429 com header `Retry-After` presente e plausivel.
- Autocomplete (`cliente.php?acao=buscar`) retorna clientes reais dos 38.
- `NotaFiscalRn::processarLeitura()` (fluxo antigo de chave de acesso)
  testado diretamente contra `tb_cliente` real - funciona sem erro.
- Ambiguidade dos pares de nomes parecidos (BARENTZ 04/49, CARGILL
  AGRICOLA/62, CP KELCO MATAO/LIMEIRA, LOUIS DREYFUS MATAO/BEBEDOURO)
  testada diretamente contra `RazaoSocialMatcher::melhorCandidato()` com
  a listagem real - todos corretamente NAO identificados quando o termo e
  generico/comum entre os dois - comportamento esperado, nao regressao.
- Todos os dados de teste (totens/atendimentos/notas) criados durante a
  validacao foram removidos ao final - confirmado 0 residuo, `tb_cliente`
  permanece em 38.

### Revisao de seguranca (security-especialista)

Nenhum achado critico/bloqueante. Confirmado: incremento do rate limit e
atomico (INSERT ... ON DUPLICATE KEY UPDATE, sem race condition
exploravel); checagem de rate limit ocorre logo apos autenticacao do
totem e antes de qualquer consulta cara; resposta 429 nao vaza dado
sensivel; nao ha caminho de bypass (chave do rate limit vem so do totem
autenticado, nunca do payload); `CLIENTES_API_TOKEN` confirmado sem uso
residual em todo o projeto; `ClienteApiClient` confirmado como codigo
morto real; `ClienteDao` usa prepared statements de forma consistente;
IDOR/posse do atendimento intactos, ordem de checagem (Auth -> RateLimit
-> IDOR/posse -> regras de negocio) preservada e correta.

Dois achados NAO bloqueantes registrados em `ia_development_state.md`
(secao 5): (1) rate limit e "fail-open" silencioso por design se
`RateLimitOcrDao` nao for injetado (hoje sempre e injetado, mas uma
refatoracao futura poderia desativar a protecao sem erro/log); (2)
`tb_rate_limit_ocr` cresce indefinidamente sem job de limpeza (severidade
baixa, sem vetor de amplificacao por atacante externo).

### Confirmacao de escopo

Nenhuma alteracao em Expedicao, CNH, CRLV, Talent, ou na captura ja
validada do scanner Netum SD-2000. `util/CnpjValidador.php` e
`util/RazaoSocialMatcher.php` nao foram alterados (so o que os chama
mudou). Nenhum commit/push realizado.

### Pendencias / bloqueios remanescentes

1. Causa raiz do problema de charset que gerou o mojibake na migration
   003 nao foi investigada/corrigida na origem - se essa migration for
   reaplicada do zero em outro ambiente, o mesmo problema pode se repetir
   dependendo de como for executada (recomendado garantir `SET NAMES
   utf8mb4` explicito no processo de aplicacao de migration em producao).
2. `tb_rate_limit_ocr` sem job de limpeza (severidade baixa).
3. Rate limit fail-open silencioso por design se a dependencia nao for
   injetada (nao e vulnerabilidade ativa hoje).
4. Origem/sincronizacao de `tb_cliente` para clientes NOVOS no futuro
   (alem dos 38 ja inseridos) continua sem processo automatizado -
   permanece manual via migration de dado.
5. Revogacao do `CLIENTES_API_TOKEN` do lado do provedor externo continua
   pendente, fora do escopo de codigo deste projeto.
6. Pendencias ja conhecidas de demandas anteriores, nao tocadas por esta
   implementacao: IDOR remanescente em `selecionarOrdem`/`finalizar`, bug
   do `CNPJ_REGEX` (separadores multiplos do OCR), heuristica de extracao
   de razao social candidata com ruido, JPEG truncado aceito, handler
   global de excecao de banco ausente, roteiro fisico pendente (passos 2,
   3, 4, 7 e 9 do roteiro anterior precisam ser refeitos contra a nova
   fonte local; passo 9 muda de natureza - de "timeout de API externa"
   para "falha de banco local").

### Proximo passo

Rodar `/02-testes` formal (incluindo o roteiro fisico no mini PC real,
que precisa ser refeito nos passos que dependem da fonte de dado do
cliente) antes de `/03-revisao`.

## Resultado dos testes (02-testes)

Data: 2026-09-08
Etapa: 02-testes

### Contexto da rodada

O usuario fez ajustes manuais no banco apos a etapa `/01-implementacao`
(alem da correcao pontual dos 8 registros com mojibake ja documentada).
Instrucao explicita: tratar o estado atual do banco como valido, sem
rodar nenhuma migration de novo. `explorer` fotografou o estado atual
antes de qualquer teste (ver abaixo) - nenhuma migration foi executada
nesta etapa.

### Estado atual de `tb_cliente` (fotografado pelo explorer antes dos testes)

- 38 registros ativos (ids 2 a 39, id 1 nao existe).
- Estrutura real: `id_cliente, nome, razao_social_normalizada, cnpj,
  ativo, criado_em` (razao_social_normalizada posicionada logo apos
  nome, conforme `ALTER ... AFTER nome` da migration 003 - nao e
  divergencia).
- Zero CNPJ duplicado. Zero duplicidade de razao_social_normalizada.
- Zero mojibake remanescente (busca refinada por padroes reais de
  encoding corrompido, descartando falsos positivos de acentuacao
  correta como MATAO/IMPORTACAO).
- Todos os 38 CNPJs com exatamente 14 digitos numericos, sem mascara.
- `tb_rate_limit_ocr` existe, estrutura conforme migration 004, vazia
  no momento da fotografia.
- Achado registrado (nao investigado, nao e problema): `AUTO_INCREMENT`
  em 116 enquanto so existem 38 linhas - confirma que o usuario fez
  ajustes manuais adicionais no banco (insercoes/exclusoes) alem da
  correcao pontual dos 8 registros de mojibake, escala maior que o
  documentado antes. Nao investigado por nao ter sido pedido.
- Nenhum arquivo de codigo alterado desde a ultima revisao aprovada
  (confirmado por 2 agentes independentes via git status/diff e
  comparacao de mtime).

### Resultado item a item

| # | Item | Resultado |
|---|---|---|
| 1 | Integridade dos 38 clientes (DV modulo 11, duplicidade) | APROVADO - 0 CNPJ invalido, 0 duplicidade |
| 2 | Ausencia de mojibake | APROVADO - 0 ocorrencias |
| 3 | Autocomplete por nome e CNPJ | APROVADO - testado via HTTP real |
| 4 | Fluxo antigo por chave NF-e | APROVADO - identificacao correta |
| 5 | OCR identificando cliente so em nota posterior | APROVADO - nota 1 NAO_IDENTIFICADA, nota 2 IDENTIFICADA, sem reescrita retroativa; confirmado tambem ao vivo com camera real |
| 6 | Fuzzy com erro leve de razao social | APROVADO - "CROMEX FILAL" identificou CROMEX real |
| 7 | Ambiguidade sem associacao automatica | APROVADO - termo generico BARENTZ nao identifica |
| 8 | Early-stop sem novos OCRs/consultas locais | APROVADO - instrumentado (listarParaFuzzy nunca chamado apos early-stop) e confirmado ao vivo (sem novas chamadas no Network) |
| 9 | Falha controlada de banco | APROVADO - PDOException real vira status ERRO, sem vazar SQLSTATE/driver |
| 10 | Rate limit 30 chamadas/totem/60s, HTTP 429 + Retry-After | APROVADO - chamada 31 retornou 429 com Retry-After plausivel |
| 11 | Isolamento do rate limit entre totens | APROVADO - totem B nao afetado pelo bloqueio do totem A |
| 12 | Timer de inatividade so apos 3 minutos | APROVADO - confirmado fisicamente pelo usuario |
| 13 | Contador pos-modal preservado (30s) | APROVADO - confirmado fisicamente pelo usuario |
| 14 | Fluidez da camera/capturas durante o OCR | APROVADO - confirmado fisicamente, sem travamento |
| 15 | Nenhuma regressao em Expedicao/CNH/CRLV/Talent/Netum | APROVADO - confirmado fisicamente pelo usuario |

### Revisao de seguranca (revalidacao)

Nenhum achado critico/bloqueante novo. Confirmado: nenhum codigo mudou
desde a ultima revisao aprovada; isolamento do rate limit por totem
correto (chave sempre vem do totem autenticado via Auth::validarTotem,
nunca do payload); tratamento de falha tecnica de banco ja existente e
seguro. Achados nao bloqueantes ja conhecidos mantidos com a mesma
severidade (rate limit fail-open silencioso se a dependencia nao for
injetada; tb_rate_limit_ocr sem job de limpeza).

### Pendencias nao resolvidas nesta rodada (ja conhecidas)

- Causa raiz do problema de charset que gerou o mojibake original nao
  foi investigada (so o dado ja gravado foi corrigido).
- `tb_rate_limit_ocr` sem job de limpeza (severidade baixa).
- Rate limit fail-open silencioso por design se a dependencia nao for
  injetada (nao e vulnerabilidade ativa hoje).
- Origem/sincronizacao de `tb_cliente` para clientes NOVOS no futuro
  continua manual.
- IDOR remanescente em `selecionarOrdem`/`finalizar` (demanda anterior).
- Bug do `CNPJ_REGEX` (separadores multiplos do OCR) - nao relacionado a
  esta demanda, ainda presente.

### Veredito final

**APROVADO** - todos os 15 itens do roteiro passaram, sem nenhum achado
bloqueante. Pendencias remanescentes sao de severidade baixa/nao
bloqueante, ja conhecidas e registradas em `ia_development_state.md`
secao 5.

### Proximo passo

Rodar `/03-revisao` (revisao final cruzada de codigo/seguranca/UX) antes
de `/04-commit-e-push`.

## Resultado da revisao (03-revisao)

Data: 2026-09-08
Etapa: 03-revisao

Revisao independente com 5 sub-agentes (explorer, backend-especialista,
frontend-especialista, security-especialista, qa-testes), cada um
validando diretamente no codigo/banco/migrations, sem confiar apenas nos
relatos de /01-implementacao e /02-testes.

### Confirmacoes positivas (itens do roteiro ja aprovados, revalidados)

- Uso de tb_cliente coerente nos 3 fluxos (autocomplete, chave de acesso
  antiga, OCR novo) - mesmo DAO, prepared statements, sem query solta.
- Integridade dos 38 clientes revalidada por 2 agentes distintos: 0 CNPJ
  invalido/duplicado, 0 duplicidade de razao normalizada, 0 mojibake
  remanescente, 100% CNPJ com 14 digitos.
- Consulta local por CNPJ exato antes de fuzzy, LIMIAR_MINIMO/
  MARGEM_MINIMA inalterados, exclusao UDLOG e MAX_CNPJS_CANDIDATOS
  preservados - sem regressao de logica.
- Early-stop (backend e frontend) confirmado correto.
- Rate limit atomico, isolado por id_totem (nunca do payload), Retry-After
  calculado corretamente.
- Timer de 3 minutos e contador pos-modal de 30s corretos, unica linha
  tocada foi IDLE_MS.
- Fluidez/concorrencia do OCR sem condicao de corrida real; limpeza de
  fila ao cancelar atendimento correta.
- Nenhum arquivo de Expedicao/CNH/CRLV/Talent/scanner Netum alterado.
- CLIENTES_API_TOKEN/ClienteApiClient sem uso residual.
- SQL injection: nenhuma concatenacao de entrada de usuario/OCR em query.
- Exposicao de dado: nenhuma resposta de erro vaza dado sensivel.
- 29/29 testes automatizados revalidados por 2 agentes distintos.

### Achados NOVOS desta revisao

**ALTO (reproduzido de forma real) - seed da migration 003 pode falhar
silenciosamente numa instalacao nova**
Rodar sql/migrations/003_tb_cliente_razao_normalizada.sql via
`mysql banco < arquivo.sql` (forma comum via terminal/SSH) contra um
banco onde sql/schema.sql ja foi aplicado do zero falha no PASSO 2
(ERROR 1060: Duplicate column name, coluna ja existe no schema novo) e o
cliente mysql interrompe a execucao do restante do arquivo nesse modo -
o PASSO 5 (seed dos 38 clientes) NUNCA RODA. Resultado: instalacao nova
fica com estrutura certa, mas tb_cliente vazia, sem aviso obvio alem do
erro de coluna duplicada. Reproduzido de forma deterministica pelo
qa-testes em banco de teste isolado, descartado depois. Nao afeta o
ambiente de desenvolvimento atual (ja seedado corretamente), mas e risco
real para deploy em producao.

**ALTO (causa raiz confirmada e reproduzida) - mojibake se repete em
instalacao nova sem SET NAMES utf8mb4 explicito**
O explorer ja tinha identificado que a migration nao tem protecao de
charset; o qa-testes CONFIRMOU reproduzindo o mojibake de forma
deterministica ao rodar a migration com um cliente mysql sem
--default-character-set=utf8mb4 (charset padrao do Windows e cp850).
Adicionar SET NAMES utf8mb4 antes da migration previne o problema, mas
nenhuma migration do repositorio tem isso embutido hoje.

**MEDIO (codigo pre-existente a esta demanda) - identificarCliente() sem
try/catch defensivo**
NotaController::identificarCliente() nao envolve a chamada de negocio em
try/catch(Throwable), diferente de processar() que tem esse tratamento.
Risco de vazar stack trace em falha nao prevista, dependendo de
display_errors do PHP em producao. Achado da demanda anterior
(recebimento-leitura-notas), notado agora nesta revisao mais profunda.

**MEDIO (codigo pre-existente a esta demanda) - identificarCliente() sem
validacao de status/etapa_atual**
Diferente de processar() (que valida status=em_andamento e
etapa_atual=digitalizacao_notas), identificarCliente() so valida tipo e
posse do atendimento - permite, dentro do mesmo totem, reprocessar
identificar-cliente para um atendimento fora do ciclo de vida esperado.
Nao e IDOR entre totens. Achado da demanda anterior, notado agora.

**BAIXO/OBSERVACAO**
- Comentario desatualizado em util/RazaoSocialMatcher.php (ainda cita
  API externa como fonte).
- Corrida no front-end pode gerar 1 chamada extra "gratuita" ao rate
  limit por nota (sem impacto de seguranca).
- Worker do Tesseract.js nunca terminado - design intencional (singleton
  reaproveitado, nao cresce por nota/atendimento), vale monitorar em uso
  continuo de kiosk.

### Achados JA CONHECIDOS (reconfirmados, sem mudanca de severidade)

- Rate limit fail-open silencioso se RateLimitOcrDao nao for injetado -
  reconfirmado sem caminho de exploracao hoje.
- tb_rate_limit_ocr sem job de limpeza - reconfirmado com estimativa
  numerica (dezenas/centenas de MB por ano mesmo em abuso sustentado).
- Divergencia entre banco de dev atual (ajustes manuais extras do
  usuario, AUTO_INCREMENT=116 com 38 linhas) e o que qualquer migration
  reproduziria - nao e bug, e inconsistencia de ambiente a considerar
  antes do deploy.
- IDOR remanescente em selecionarOrdem/finalizar, bug do CNPJ_REGEX,
  origem/sincronizacao de tb_cliente para clientes futuros - pendencias
  externas a esta demanda, nao tocadas.

### Bloqueadores antes do commit/deploy

Os dois achados ALTOS (seed pode nao rodar silenciosamente; mojibake se
repete sem SET NAMES) sao BLOQUEADORES PARA DEPLOY EM PRODUCAO, nao para
o commit em si (commit so versiona codigo/migration como estao, nao
executa nada). O ambiente de desenvolvimento atual ja esta correto e
testado fisicamente. Recomendacao: antes de aplicar esta migration em
producao (Hostgator), corrigir a migration 003 (separar o seed do ALTER
em arquivo/bloco independente que roda mesmo se o ALTER falhar por
coluna ja existente, e embutir SET NAMES utf8mb4 explicito) - isso
exigiria retornar a /01-implementacao para um ajuste pontual e focado,
antes do deploy real (nao necessariamente antes deste commit de
desenvolvimento).

### Veredito

**APROVADO COM RESSALVAS.**

Funcionalmente, tudo o que foi implementado funciona corretamente no
ambiente atual (testado fisicamente e revalidado por 5 agentes
independentes nesta revisao). As ressalvas sao:
1. 2 achados ALTOS que sao bloqueadores para DEPLOY EM PRODUCAO (nao
   para este commit de desenvolvimento) - a migration 003 precisa de
   correcao pontual antes de ser aplicada em ambiente novo/producao.
2. 2 achados MEDIOS de robustez em identificarCliente(), herdados da
   demanda anterior, nao introduzidos hoje, mas identificados nesta
   revisao mais profunda.
3. Achados baixos/observacao sem acao necessaria imediata.

Nao ha achado que invalide o trabalho feito nesta demanda especifica
(troca de fonte de dado, rate limit, timer) - os problemas encontrados
sao sobre a ROBUSTEZ DE DEPLOY da migration e sobre codigo herdado, nao
sobre a logica de negocio implementada agora.

### Proximo passo

Antes de /04-commit-e-push: decisao do usuario sobre se corrige a
migration 003 (SET NAMES + separacao do seed) num ciclo pontual de
/01-implementacao antes do commit, ou se aceita commitar o codigo como
esta (funcionalmente correto em dev) e trata a correcao da migration como
item obrigatorio antes do proximo deploy em producao, documentado com
destaque em ia_development_state.md.

## Correcao pos-revisao (ciclo pontual de /01-implementacao)

Data: 2026-09-08

O usuario aprovou corrigir agora, antes do commit, os 2 achados ALTOS
(robustez de deploy da migration) e os 2 achados MEDIOS
(identificarCliente) da secao "Resultado da revisao" acima.

### Correcoes aplicadas

1. **sql/migrations/003_tb_cliente_razao_normalizada.sql reescrita**:
   `SET NAMES utf8mb4;` como primeira instrucao (resolve o achado ALTO 2
   - mojibake em instalacao nova). ALTER TABLE/ADD INDEX convertidos
   para padrao de SQL preparado condicional (SET @sql := IF(...);
   PREPARE; EXECUTE; DEALLOCATE), tornando-os verdadeiramente idempotentes
   e nao-abortantes independente do metodo de execucao (resolve o achado
   ALTO 1 - seed podia nao rodar). Dados dos 38 clientes preservados
   exatamente como estavam. NAO executada contra o banco de
   desenvolvimento (udlog_totem) - ja estava correto - validada somente
   em bancos de teste descartaveis, criados e destruidos.

2. **app/Controller/NotaController.php::identificarCliente()**: adicionado
   try/catch(Throwable) espelhando processar() (log tecnico via
   error_log, resposta generica ao cliente) - resolve o achado MEDIO 3.
   Adicionada validacao de status=em_andamento e
   etapa_atual=digitalizacao_notas antes de processar, espelhando
   processar() - resolve o achado MEDIO 4.

3. **Testes ampliados**: tests/manual/teste_identificar_cliente.php de
   29 para 35 casos (novos: rejeicao por status errado, rejeicao por
   etapa errada, excecao generica sem vazar detalhe tecnico, caminho
   feliz sem regressao). Novos arquivos de suporte:
   tests/manual/_fixtures_identificar_cliente.php,
   tests/manual/_caso_controller_identificar_cliente.php.

### Validacao independente (security-especialista e qa-testes, cada um por conta propria)

- **security-especialista**: confirmou por leitura direta que o
  try/catch nao vaza $e->getMessage() na resposta HTTP, e que a nova
  validacao de etapa_atual NAO quebra o early-stop nem nenhum cenario
  legitimo - etapa_atual so sai de digitalizacao_notas em
  concluirDigitalizacao(), chamado pelo front-end SOMENTE depois de todas
  as chamadas de identificar-cliente daquele atendimento. Nenhum achado.
- **qa-testes**: reproduziu o cenario original do zero em banco de teste
  proprio e descartavel (diferente do usado pelo backend) - migration 003
  corrigida rodou por completo sem abortar mesmo com a coluna ja
  existindo, 38 clientes inseridos com encoding correto (confirmado via
  HEX() dos bytes), idempotencia total confirmada rodando a migration
  2 vezes seguidas. Suite completa: 35/35 OK contra o banco de dev real.
  Testado via HTTP real: atendimento com status/etapa errados -> HTTP 400
  rejeitado corretamente; atendimento normal -> HTTP 200, identificacao
  correta, sem regressao no caminho feliz. Banco de teste destruido ao
  final, dados de teste no banco de dev limpos, 0 residuo.

### Achado novo registrado (nao corrigido, fora do escopo desta correcao pontual)

As migrations 001 e 002 tem o MESMO padrao fragil que a 003 tinha
(checagem manual previa, sem SQL preparado condicional) - confirmado que
tambem abortariam se executadas via `mysql banco < arquivo.sql` de uma
vez so contra um banco onde as colunas/indices ja existem. Reproduzido
pelo qa-testes ao simular instalacao nova. Nao foi pedido para corrigir
agora - registrado como pendencia para decisao futura do usuario.

### Veredito final atualizado

**APROVADO** (upgrade de "aprovado com ressalvas" para aprovado pleno).

Os 2 achados ALTOS (bloqueadores de deploy) e os 2 achados MEDIOS foram
corrigidos e validados de forma independente por 2 agentes distintos,
sem nenhuma regressao. Pendencias remanescentes sao de severidade
baixa/observacao, ja conhecidas, e um novo achado de mesma natureza
(fragilidade das migrations 001/002) registrado para decisao futura, nao
bloqueante para este commit nem para o deploy desta demanda especifica.

### Proximo passo

Pronto para `/04-commit-e-push`.

## Segunda rodada de /03-revisao (pos-correcao, revisao completa do zero)

Data: 2026-09-08

A pedido do usuario, refeita a revisao COMPLETA dos 13 itens contra o
estado atual do codigo (pos-correcao da migration 003 e do
NotaController::identificarCliente()), com os mesmos 5 sub-agentes
(explorer, backend-especialista, frontend-especialista,
security-especialista, qa-testes), cada um validando do zero, sem
confiar nos relatorios da rodada anterior.

### Resultado: NENHUM ACHADO NOVO em nenhum dos 13 itens

- **Item 1** (uso de tb_cliente nos 3 fluxos) - confirmado coerente,
  mesmo DAO, prepared statements (explorer).
- **Item 2** (integridade dos 38 clientes) - reconfirmado por consulta
  direta: 0 CNPJ duplicado, 0 razao normalizada duplicada, 100% CNPJ com
  14 digitos (explorer, qa-testes).
- **Item 3** (instalacao nova reproduz o estado) - RECONFIRMADO POR
  EXECUCAO REAL em bancos de teste descartaveis distintos por 2 agentes
  independentes (explorer e qa-testes): migration 003 corrigida roda sem
  abortar mesmo com coluna/indice ja existentes, 38 clientes inseridos
  com encoding correto, idempotencia dupla confirmada. Os achados ALTOS
  da rodada anterior estao definitivamente resolvidos.
- **Item 4** (ajustes manuais em migration) - divergencia de
  AUTO_INCREMENT reconfirmada, sem mudanca, mesma pendencia ja registrada
  (nao e bug funcional).
- **Item 5** (CNPJ exato + fuzzy) - confirmado sem regressao de logica
  (backend-especialista).
- **Item 6** (early-stop) - confirmado correto em backend e frontend,
  sem nova consulta apos identificacao (security-especialista).
- **Item 7** (rate limit atomico/isolado/429/Retry-After) - confirmado
  sem mudanca (security-especialista).
- **Item 8** (fail-open e crescimento de tb_rate_limit_ocr) - reconfirmado
  mesma severidade baixa, sem caminho de exploracao ativo
  (backend-especialista).
- **Item 9** (timer 3min/contador pos-modal) - confirmado inalterado
  desde a ultima revisao, unico arquivo tocado na correcao foi backend
  (frontend-especialista, confirmado tambem por mtime de arquivo).
- **Item 10** (fluidez/concorrencia/worker) - confirmado sem condicao de
  corrida nova, worker nunca terminado reconfirmado como observacao ja
  conhecida (frontend-especialista).
- **Item 11** (token/config residual) - grep atualizado confirma ausencia
  total de uso residual (backend-especialista).
- **Item 12** (IDOR/SQLi/exposicao/logs) - revisao completa confirma
  ordem de validacoes correta (Auth -> RateLimit -> IDOR/posse ->
  status/etapa -> negocio), sem vazamento na correcao pontual
  (security-especialista).
- **Item 13** (nao regressao Expedicao/CNH/CRLV/Talent/Netum) -
  reconfirmado via diff completo, nenhum arquivo desses fluxos tocado
  (qa-testes).

### Validacao adicional por execucao real (qa-testes)

- Suite automatizada: 35/35 OK (reexecutada do zero).
- Identificacao via HTTP real: CNPJ exato mascarado e fuzzy com erro leve
  identificaram corretamente a CROMEX real.
- Os 2 comportamentos novos de identificarCliente() via HTTP real:
  status=concluido -> HTTP 400; etapa_atual=cnh -> HTTP 400; caminho
  feliz (em_andamento/digitalizacao_notas) -> HTTP 200 sem regressao.
- Todo dado de teste (totens, atendimentos, notas, banco de teste
  descartavel) removido/destruido ao final, 0 residuo confirmado.

### Veredito final (confirmado por segunda rodada completa e independente)

**APROVADO.**

Nenhum achado critico, alto, medio ou baixo NOVO nesta segunda rodada.
Todos os achados ALTOS e MEDIOS da primeira rodada de /03-revisao estao
definitivamente corrigidos e validados por execucao real (nao so
leitura), por 2 agentes independentes em cada ponto critico. Pendencias
remanescentes sao as mesmas ja documentadas (baixa severidade/observacao,
ou externas a esta demanda): tb_rate_limit_ocr sem limpeza, rate limit
fail-open silencioso por design, fragilidade das migrations 001/002 (nao
corrigida, fora do escopo), origem/sincronizacao futura de tb_cliente,
IDOR remanescente em selecionarOrdem/finalizar, bug do CNPJ_REGEX.

### Proximo passo

Pronto para /04-commit-e-push.

## Fechamento (04-commit-e-push)

Data: 2026-09-08

### Verificacoes pre-commit executadas

- `git status`/`git diff --stat`: todos os arquivos alterados/criados
  pertencem exclusivamente a esta demanda e a sua predecessora direta
  (`recebimento-leitura-notas`, OCR client-side, nunca commitada antes,
  pausada para o pivo desta demanda) - nao ha nenhuma mudanca de codigo
  nao relacionada a "identificacao automatica de cliente" no working
  tree.
- Confirmado: `.env` nao versionado (`git ls-files` nao lista),
  `.env.example` sem `CLIENTES_API_TOKEN` e sem nenhum segredo real
  (so placeholders vazios). `storage/` (imagens fiscais, cache,
  uploads, banco local) inteiro no `.gitignore`, confirmado `!!`
  (ignorado) via `git status --ignored`. `docs/evidencias-scanner/`
  (evidencias reais de teste) tambem ignorado. Nenhum arquivo de
  imagem (jpg/png) no working tree a ser commitado. OCR integral
  (texto bruto reconhecido) nunca e persistido em nenhum lugar do
  codigo (confirmado em revisoes anteriores).
- Ajustes manuais do usuario no banco: o CONTEUDO necessario para
  reproducao (os 38 clientes, CNPJ, razao social original e
  normalizada) esta integralmente representado na migration 003
  corrigida - confirmado por execucao real em banco de teste
  descartavel nas duas ultimas rodadas de `/03-revisao`. A unica
  divergencia remanescente entre o banco de dev e uma instalacao nova
  e o valor numerico de `id_cliente`/`AUTO_INCREMENT` (gaps por
  insercoes/exclusoes manuais do usuario) - confirmado por 2 agentes
  independentes que nenhum codigo do projeto depende do valor literal
  de `id_cliente`, portanto essa divergencia nao compromete a
  reprodutibilidade funcional.
- `php -l`: sem erros em todos os 14 arquivos PHP alterados/criados
  desta demanda (controllers, DAOs, RN, utils, testes).
- `node --check public/totem/assets/app.js`: sem erros.
- `git diff --check`: sem erro de whitespace (só avisos cosméticos de
  LF/CRLF, não bloqueantes).
- Suite automatizada: **35/35 OK** (reexecutada nesta etapa).
- `HEAD` local confirmado igual a `origin/main` antes do commit
  (`ae7f162`), branch atualizado, sem divergencia/conflito.

### Arquivos incluidos no commit

Modificados: `.gitignore`, `app/Controller/AtendimentoController.php`,
`app/Controller/NotaController.php`, `app/Dao/AtendimentoNotaDao.php`,
`app/Dao/ClienteDao.php`, `app/Rn/NotaFiscalRn.php`,
`docs/db_gestao_coletas.md`, `ia_development_state.md`,
`public/api/nota.php`, `public/totem/assets/app.js`,
`public/totem/index.php`, `sql/schema.sql`.

Novos: `app/Dao/RateLimitOcrDao.php`, `app/Rn/ClienteApiClient.php`,
`docs/handoffs/2026-09-04-recebimento-leitura-notas.md`,
`docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md`,
`public/totem/assets/tesseract/*` (7 arquivos vendorizados do
Tesseract.js), `sql/migrations/002_status_ocr_atendimento_nota.sql`,
`sql/migrations/003_tb_cliente_razao_normalizada.sql`,
`sql/migrations/004_tb_rate_limit_ocr.sql`, `tests/manual/*` (3
arquivos), `util/CnpjValidador.php`, `util/RazaoSocialMatcher.php`.

Nenhum arquivo de Expedição, CNH, CRLV, Talent ou scanner Netum incluso.
