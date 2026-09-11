# Handoff — expedicao-consulta-ordem-coleta-teste

Data: 2026-09-11
Etapa: 00-planejamento

## O que foi pedido

Ao informar a placa TST0A01 na Expedicao, o backend deve consultar a fonte
real de ordens de coleta, localizar uma ordem vinculada, e retornar os dados
necessarios para o atendimento continuar - usando um registro de teste
claramente identificavel, sem dado pessoal real, e removido por completo
depois dos testes. Nao avancar ate o envio ao Talent (doctos[] continua
bloqueante). Nao alterar Recebimento. Nao criar coluna de status/regra de
"ordem ja usada" agora.

## Descoberta que mudou o rumo do planejamento

docs/db_gestao_coletas.md (secao "ordens de coleta por placa") continua
vazia - nunca existiu uma API REST real e documentada para essa consulta.
O usuario autorizou, nesta demanda, acesso de LEITURA direto ao banco
externo udlogo59_db_gestao_coletas (mesmo servidor/credencial do banco do
totem, so troca DB_NAME), e trouxe o dump completo do schema real em
docs/db_gestao_coletas.sql.

Alerta relevante: uma primeira investigacao (sub-agente explorer, antes do
dump ser fornecido) reportou detalhes que se confirmaram incorretos/
inventados ao comparar com o DDL verbatim (ex: afirmou existir uma coluna
status enum em tb_ordens_coleta, e tabelas tb_atendimentos/tb_ajudantes/
tb_documentos que nao existem nesse banco). O ambiente sinalizou um aviso
de seguranca automatico sobre as acoes desse sub-agente na mesma janela.
Todo o planejamento abaixo se baseia exclusivamente no dump verbatim
fornecido pelo usuario (docs/db_gestao_coletas.sql), nao na primeira
investigacao, que deve ser descartada.

## Schema real confirmado (verbatim, docs/db_gestao_coletas.sql)

- tb_ordens_coleta: id PK, numero_ordem_coleta VARCHAR(50) NOT NULL,
  cliente_id NOT NULL (FK tb_clientes.id), transportadora_nome/
  transportadora_cnpj nullable, motorista_id nullable (FK
  tb_motoristas.id, ON DELETE SET NULL), email_recebido_id NOT NULL (FK
  tb_emails_recebidos.id), placa_prevista VARCHAR(10) nullable (indexada,
  idx_ordens_placa_prevista), motorista_nome_previsto/cnh_prevista
  nullable. Unico: (cliente_id, numero_ordem_coleta). Sem coluna de
  status.
- tb_clientes: razao_social, cnpj (unico), status enum ATIVO/INATIVO.
- tb_emails_recebidos: id=1 ja existe como fixture de teste vinculada a
  cliente_id=8 - reaproveitavel como FK obrigatoria sem criar e-mail novo.
- Ja existem 3 ordens de teste reais: OC-TESTE-001, OC-TESTE-NOVA-003,
  OC-TESTE-NOVA-004, todas cliente_id=8 (AKRO-PLASTIC DO BRASIL),
  placa_prevista='ABC1D23' - nenhuma colide com OC-TESTE-005/TST0A01
  propostos aqui.
- Nenhuma outra tabela do dump tem FK apontando para tb_ordens_coleta.id -
  confirmado por leitura completa das 12 CREATE TABLE/ADD CONSTRAINT do
  dump.

## O que sera feito (na proxima /01-implementacao)

1. App\Rn\OrdemColetaClient deixa de ser cliente HTTP placeholder e passa
   a delegar a uma nova App\Dao\OrdemColetaDao, que consulta
   tb_ordens_coleta INNER JOIN tb_clientes por placa_prevista (normalizada
   em maiusculas, sem espaco/hifen, exclusivamente no backend), via PDO
   prepared statement - sem filtro de status (nao existe na fonte real).
2. Nova classe Util\ConexaoGestaoColetas (conexao PDO propria e separada
   da conexao do totem, nunca compartilhada/reaproveitada), lendo
   DB_HOST/DB_USER/DB_PASS/DB_PORT ja existentes no .env e uma variavel
   nova, so o nome do banco (GESTAO_COLETAS_DB_NAME), sem duplicar a
   senha. Timeout de conexao curto e explicito (proposta: 3-5s),
   diferente da conexao atual do totem.
3. Mapeamento de campos: numero/cliente_nome/cliente_cnpj diretos; data
   proposto como criado_em (pendencia de UX: e data de cadastro da ordem,
   nao data prevista de coleta); veiculo sem fonte real - omitido nesta
   rodada (escapeHtml trata ausencia sem quebrar a tela).
4. AtendimentoRn::consultarOrdensAbertas() perde o filtro morto de status
   (campo nunca existiu de verdade na fonte real).
5. Erros de conexao/consulta nunca vazam SQL/host/credencial ao totem -
   sempre mensagem generica + log tecnico server-side, replicando o padrao
   ja usado em Util\Conexao. O gate "0 ordens bloqueia" e o fluxo de
   selecao "mais de uma ordem" ja existem em
   AtendimentoController::iniciar()/AtendimentoRn e continuam funcionando
   sem alteracao - so a fonte de dados muda.
6. Migration proposta (nao implementada agora): ampliar
   tb_atendimento.ordem_coleta de VARCHAR(30) para VARCHAR(50), ja que a
   fonte real permite ate 50 caracteres (achado novo, nao bloqueia o teste
   desta demanda - OC-TESTE-005 cabe em 30).
7. Dado de teste (SQL pronto, NAO EXECUTADO ainda):

INSERT INTO tb_ordens_coleta
    (numero_ordem_coleta, cliente_id, transportadora_nome, transportadora_cnpj,
     motorista_id, email_recebido_id, placa_prevista, motorista_nome_previsto, cnh_prevista)
VALUES
    ('OC-TESTE-005', 8, NULL, NULL,
     NULL, 1, 'TST0A01', NULL, NULL);

DELETE FROM tb_ordens_coleta WHERE numero_ordem_coleta = 'OC-TESTE-005';

Reaproveita fixtures ja existentes (cliente_id=8, email_recebido_id=1) -
zero dado pessoal novo (motorista_id/transportadora_nome/transportadora_cnpj/
motorista_nome_previsto/cnh_prevista todos NULL). Confirmado sem colisao
de unicidade, sem violar NOT NULL/FK, sem deixar orfao em nenhuma tabela
filha. A execucao real (INSERT e, depois, DELETE) sera feita manualmente
contra udlogo59_db_gestao_coletas, fora da aplicacao, so quando o usuario
autorizar a etapa de teste.

## O que NAO sera feito

- Nenhuma alteracao em Recebimento.
- Nenhuma coluna de status nem regra de "ordem ja usada" (a fonte real
  nunca teve isso).
- Nenhum avanco ate o envio ao Talent - finalizar() continua bloqueado
  por TALENT_DOCTOS_PENDENTE (regra ja existente, nao muda aqui).
- Nenhuma escrita real no banco externo nesta etapa.
- Nenhum codigo implementado nesta etapa (/00-planejamento apenas).

## Sub-agentes envolvidos

- explorer - mapeamento inicial (achados parcialmente descartados - ver
  secao acima) e confirmacao de arquivos/estado de codigo.
- backend-especialista - desenho completo de OrdemColetaClient/
  OrdemColetaDao/ConexaoGestaoColetas, consulta SQL, mapeamento de campos,
  tratamento de erro, SQL de teste.
- security-especialista - revisao do plano de acesso ao banco externo.
- qa-testes - roteiro de teste para a etapa /02-testes futura.

## Pendencias conhecidas

- Confirmar se ordens de clientes tb_clientes.status = INATIVO devem
  aparecer no totem (nao decidido, nao presumido).
- Decisao de UX: data (mapeado para criado_em) e veiculo (sem fonte real,
  omitido) - frontend-especialista/usuario decidir se e aceitavel.
- Migration de tb_atendimento.ordem_coleta VARCHAR(30) para VARCHAR(50) -
  desenhada, nao implementada.
- Decisao sobre remover ou manter ORDEM_COLETA_API_URL/
  ORDEM_COLETA_API_KEY (variaveis que ficam sem uso apos esta mudanca).
- [SEGURANCA] Recomendacao do security-especialista: usar credencial de
  banco dedicada, somente leitura, restrita a udlogo59_db_gestao_coletas,
  em vez de reaproveitar o mesmo DB_USER do totem - decisao de
  arquitetura/DBA/devops-especialista pendente, nao implementada nesta
  rodada.
- sql_mode (strict ou nao) do MySQL/MariaDB em producao Hostgator nao
  confirmado - afeta o comportamento de truncamento silencioso vs erro
  fatal se a migration do item acima nao for aplicada antes de dados
  reais mais longos.
- IDOR pre-existente em AtendimentoController::selecionarOrdem()
  (posse/tipo/status/etapa nao validados) - ja era pendencia conhecida,
  nao e criada nem corrigida por esta demanda.

## Proximo passo

Aguardar autorizacao do usuario para: (1) decidir as pendencias de
UX/seguranca acima (ou aceitar as propostas como estao) e (2) rodar
/01-implementacao - que incluira, como primeiro passo pratico, a execucao
manual do INSERT de teste acima contra udlogo59_db_gestao_coletas (fora da
aplicacao, por quem tiver privilegio de escrita real nesse banco).

## Atualizacao 2026-09-11 (decisoes confirmadas pelo usuario, indo para /01-implementacao)

Todas as pendencias da rodada anterior foram decididas pelo usuario:

- Consulta direta ao banco `udlogo59_db_gestao_coletas`, sem API REST.
- Reutilizar `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS` do totem; nova
  variavel so para o nome do banco externo.
- Remover `ORDEM_COLETA_API_URL`/`ORDEM_COLETA_API_KEY` do `.env.example`
  (ficaram sem uso).
- Ignorar ordens de clientes `INATIVO`.
- `data` = `criado_em`; `veiculo` fica vazio nesta rodada.
- Ampliar `tb_atendimento.ordem_coleta` para VARCHAR(50).
- Nao implementar ainda controle de "ordem ja utilizada".
- **Nova decisao**: adicionar coluna real de status em
  `tb_ordens_coleta` (banco externo):
  `status ENUM('ATIVA','INATIVA') NOT NULL DEFAULT 'ATIVA'`, com indice
  para consulta por `placa_prevista + status`, via migration idempotente
  separada e claramente identificada como pertencente ao
  `db_gestao_coletas` (nao ao banco do totem). Ordens existentes
  permanecem `ATIVA` (default cobre isso).
- Consulta passa a filtrar `tb_clientes.status = 'ATIVO'` E
  `tb_ordens_coleta.status = 'ATIVA'`.
- Nova exigencia de seguranca: `selecionarOrdem` nunca aceita do
  front-end uma ordem que nao pertenca ao resultado realmente consultado
  para aquele atendimento/placa — o backend deve reconsultar e validar
  antes de gravar.
- Fixture de teste (`OC-TESTE-005`/`TST0A01`/`status=ATIVA`) so sera
  inserido apos validar em tempo real: `cliente_id=8` existe e ativo,
  `email_recebido_id=1` existe, `OC-TESTE-005` ainda nao existe.

Handoff mantido como registro do plano original; a implementacao real
segue registrada na proxima entrada do log de
`ia_development_state.md` e, ao final, nesta secao com o resultado real.

## Resultado da /01-implementacao (2026-09-11) — CONCLUIDA

Implementado pelo backend-especialista, revisado pelo security-especialista,
com correcoes aplicadas na mesma rodada.

### Arquivos criados
- `util/ConexaoGestaoColetas.php` — conexao PDO dedicada ao banco externo,
  timeout curto, erro generico ao totem (nunca vaza DSN/credencial).
- `app/Dao/OrdemColetaDao.php` — consulta `tb_ordens_coleta INNER JOIN
  tb_clientes` por `placa_prevista`, filtrando `status='ATIVA'`/
  `status='ATIVO'` na propria query SQL (prepared statement).
- `sql/migrations/011_ampliar_ordem_coleta.sql` — amplia
  `tb_atendimento.ordem_coleta` para VARCHAR(50) (banco do totem,
  aplicada em dev local).
- `sql/migrations_gestao_coletas/001_status_ordem_coleta.sql` — adiciona
  `status ENUM('ATIVA','INATIVA') DEFAULT 'ATIVA'` + indice composto
  (banco EXTERNO, pasta separada, aplicada e confirmada idempotente
  contra `udlogo59_db_gestao_coletas` real).
- `tests/manual/teste_consulta_ordem_coleta.php` + auxiliares.

### Arquivos alterados
- `app/Rn/OrdemColetaClient.php` — delega ao Dao, normaliza placa no
  backend (maiusculas, sem espaco/hifen).
- `app/Rn/AtendimentoRn.php` — removido filtro morto de status em PHP.
- `app/Controller/AtendimentoController.php` — `selecionarOrdem()`
  corrigido (IDOR real e completo, confirmado pelo security-especialista):
  valida posse/tipo/status/etapa pelo totem autenticado, reconsulta as
  ordens reais e so aceita a selecao se bater com o resultado real —
  `cliente_nome`/`cliente_cnpj` do front sao sempre descartados, dados
  gravados vem exclusivamente do servidor.
- `public/api/atendimento.php` — wiring atualizado.
- `.env`/`.env.example` — `GESTAO_COLETAS_DB_NAME=udlogo59_db_gestao_coletas`
  (nome correto, apos reconciliacao); `ORDEM_COLETA_API_URL`/`API_KEY`
  removidas (confirmado sem resquicio).

### Reconciliacao de nome de banco (achado critico resolvido em 2026-09-11)
Uma confusao real entre dois bancos (`udlogo59_db_gestao_coletas` antigo,
com schema divergente e incompativel, vs. `db_gestao_coletas` sem
prefixo, que batia com o primeiro dump fornecido) foi identificada e
travada antes de qualquer decisao. O usuario apagou o banco errado e
reimportou o schema correto em `udlogo59_db_gestao_coletas`
(`docs/udlogo59_db_gestao_coletas.sql`, 10 ordens reais + fixture de
teste). Implementacao reconciliada e testada contra esse banco, o unico
que resta no ambiente.

### Testes reais — todos passando
- `teste_consulta_ordem_coleta.php`: 17/17 (zero ordens, uma ordem
  `TST0A01`/fixture `OC-TESTE-005`/`id=11`, multiplas ordens `ABC1D23`
  — confirmado que nenhuma das 7 ordens reais adicionais usa essa placa
  —, normalizacao de placa, selecao legitima vs. forjada, IDOR classico,
  indisponibilidade do banco externo com erro generico).
- Regressao: `teste_avancar_etapa_expedicao.php` (10/10),
  `teste_talent_trava_doctos_pendente.php` (16/16),
  `teste_rebaixamento_manual.php` (45/45).
- Nao testavel: cenario de cliente INATIVO (nenhum fixture real
  disponivel, nao autorizado alterar dado real de producao so para
  testar isso) — registrado, nao bloqueante.

### Revisao de seguranca (security-especialista) — sem achados criticos
- Confirmado: SQL sempre via prepared statement; filtro de status na
  query real; nenhuma escrita de producao disparada pela aplicacao
  contra o banco externo; `.env` fora do git; IDOR de `selecionarOrdem()`
  corrigido de forma real e completa.
- Achado de atencao corrigido nesta mesma rodada: aspas duplas nos
  literais de status em `OrdemColetaDao.php` trocadas por aspas simples
  (escapadas corretamente dentro da string PHP), reconfirmado com
  `php -l` e reexecucao das 17 asseroes (continuam 17/17).
- Achado de atencao NAO corrigido nesta demanda (pendencia de
  arquitetura, ja registrada, cabe a `devops-especialista`/decisao do
  usuario): a conexao ao banco externo reutiliza a mesma credencial
  `root`/`DB_PASS` do totem — recomendado usar credencial dedicada,
  somente leitura, restrita a `udlogo59_db_gestao_coletas`, antes de
  producao real.

### Alerta de privacidade registrado (nao resolvido nesta etapa)
`docs/udlogo59_db_gestao_coletas.sql` contem dados pessoais reais de
motorista (nome completo, numero de CNH) nas ordens de id 4-10. Esse
arquivo esta hoje como untracked no git (nao commitado). Decisao
pendente do usuario sobre: remover o arquivo do repositorio, mascarar os
dados pessoais antes de qualquer commit, ou manter fora do controle de
versao permanentemente (`.gitignore`).

### Fixture de teste — ainda NAO removido (aguardando fim dos testes)
`OC-TESTE-005` (`id=11`, placa `TST0A01`, `status='ATIVA'`) permanece em
`udlogo59_db_gestao_coletas`. SQL de limpeza documentado, pronto para
uso quando o usuario autorizar a remocao:
```sql
DELETE FROM tb_ordens_coleta WHERE numero_ordem_coleta = 'OC-TESTE-005';
```

### Nao alterado
Recebimento intocado. `finalizar()` continua bloqueado por
`TALENT_DOCTOS_PENDENTE` — nenhum avanco ao Talent nesta demanda.
Nenhum commit/push realizado.

### Pendencias abertas ao final desta etapa
- Credencial dedicada/somente-leitura para o banco externo (arquitetura,
  `devops-especialista`).
- Confirmar nome real do banco em Producao (Hostgator) antes de aplicar
  a migration externa la — o prefixo `udlogo59_` observado e do cPanel
  local.
- Decisao sobre `docs/udlogo59_db_gestao_coletas.sql` (dado pessoal real
  em arquivo do repositorio).
- Remocao do fixture `OC-TESTE-005` apos os testes desta demanda serem
  encerrados (SQL pronto acima).
- Cenario de cliente INATIVO nao testado por falta de fixture real.

### Proximo passo
`/02-testes` formal (ou o usuario decidir se os 88 testes ja executados
nesta rodada substituem essa etapa) e `/03-revisao`, seguidos de decisao
sobre commit/push e sobre as pendencias acima.

## /02-testes (2026-09-11) — SOMENTE etapa inicial da Expedicao — APROVADO

Escopo: motorista digita placa ate confirmar/selecionar ordem. NAO cobriu
CNH/CRLV/Talent (fora do escopo desta rodada, por instrucao explicita).

### Decisoes aplicadas antes do teste
- Coluna `status` em `tb_ordens_coleta` (`ATIVA`/`INATIVA`) confirmada
  autorizada pelo usuario (ja implementada em rodada anterior).
- Nomes/CNH ja existentes no dump (ordens id 4-10) confirmados FICTICIOS
  pelo usuario.
- Regra de negocio confirmada (nao implementada ainda, so registrada):
  ordem permanece ATIVA durante todo o atendimento; so viraria INATIVA
  no futuro apos o Talent confirmar check-in com sucesso; erro/timeout/
  cancelamento/ENVIO_INDETERMINADO nunca inativam a ordem.

### Verificacao de dado sensivel no dump (pedida pelo usuario antes de
versionar) — achado ADICIONAL alem do token_hash ja conhecido:
`docs/udlogo59_db_gestao_coletas.sql` (`tb_api_logs`) contem IPs publicos
reais de origem de chamadas a API, incluindo um provavel IP fixo do
servidor n8n de producao (`179.125.31.177`, repetido dezenas de vezes).
Combinado com o `token_hash` real de `n8n-producao` (`tb_api_tokens`) ja
registrado antes. **Arquivo continua NAO versionado (untracked) —
decisao do usuario pendente sobre mascarar/remover antes de qualquer
commit.**

### Testes reais executados (qa-testes, independente do backend)
- Zero ordens (placa inventada) → bloqueia. OK.
- Uma ordem (`TST0A01`/`OC-TESTE-005`) → avanca para `dados_encontrados`
  direto, sem pedir selecao multipla. OK.
- Multiplas ordens (`ABC1D23`, 3 ordens reais) → lista para escolha. OK.
- Ordem INATIVA (fixture temporario `OC-TESTE-006`, removido ao final) →
  placa nao retorna nenhuma ordem. OK.
- Cliente INATIVO (cliente temporario `CLIENTE TESTE INATIVO` + ordem
  `OC-TESTE-007`, ambos removidos ao final) → placa nao retorna nenhuma
  ordem mesmo com ordem ativa. OK.
- Normalizacao de placa (`tst-0a01`, `" tst 0a01 "`) → casa com
  `TST0A01`. OK.
- Selecao forjada (numero que nao pertence ao resultado real) →
  rejeitada, nada gravado. OK.
- IDOR (totem invasor) → rejeitado com mensagem generica. OK.
- Indisponibilidade do banco externo → erro generico ao totem, log
  tecnico so server-side. OK.
- Suite automatizada `tests/manual/teste_consulta_ordem_coleta.php`:
  17/17 antes e depois dos testes adicionais.

### Limpeza confirmada
`OC-TESTE-006`, `OC-TESTE-007` e o cliente de teste temporario removidos
sem residuo (confirmado por SELECT). Totens/atendimentos de teste
temporarios removidos do banco do totem. **`OC-TESTE-005` (id=11,
placa `TST0A01`, status `ATIVA`) confirmada INTACTA e MANTIDA** — sera
usada no teste fisico, NAO foi removida nesta rodada.

### Veredito
**APROVADO** para a etapa inicial da Expedicao (placa → consulta → 0/1/
multiplas ordens → selecao/confirmacao). Nenhuma falha encontrada,
nenhuma correcao necessaria. Nao avancado para CNH/CRLV/Talent (fora do
escopo). Nenhum commit/push realizado.

### Proximo passo
Teste fisico com `OC-TESTE-005`/placa `TST0A01` (motivo pelo qual o
fixture foi mantido). Decisao do usuario sobre o arquivo
`docs/udlogo59_db_gestao_coletas.sql` (token_hash + IPs reais) antes de
qualquer commit. Continuacao do fluxo (CNH/CRLV/Talent) fica para
demanda/rodada futura, fora desta.

## Validacao FISICA da etapa inicial da Expedicao (2026-09-11) — APROVADA

Conduzida passo a passo com o usuario, no ambiente real
(`http://localhost:8000/totem/index.php?totem=RECEPCAO-01`), sem alterar
codigo durante o teste.

### Passos executados
1. Selecionar Expedicao — OK, avancou normalmente.
2. Digitar placa `TST0A01` — OK, sem erro.
3. Sem erro JSON/HTML — CONFIRMADO (tela carregou os dados normalmente,
   sem `Unexpected token`/erro de rede).
4. Banco retornou somente `OC-TESTE-005` — CONFIRMADO (tela foi direto
   para os dados da ordem, nao caiu em selecao multipla).
5. Tela exibiu numero da ordem e pediu confirmacao — CONFIRMADO: tela
   "Dados da ordem — toque para editar" com Cliente
   `AKRO-PLASTIC DO BRASIL INDUSTRIA E COMERCIO DE POLIMEROS DE`, CNPJ
   `20200104000238`, Placa `TST0A01`, Veiculo (vazio, conforme decisao
   ja registrada), Ordem de coleta `OC-TESTE-005`.
6. Confirmar a ordem — OK, avancou sem erro para a tela de CNH (sem
   erros no console).
7. Ordem selecionada pertence a placa — CONFIRMADO por consulta real ao
   banco do totem: `id_atendimento=1185`, `placa='TST0A01'`,
   `ordem_coleta='OC-TESTE-005'`, `cliente_cnpj='20200104000238'`,
   `etapa_atual='exp_cnh'`, `status='em_andamento'`, batendo exatamente
   com o que a tela mostrou. Sem vazamento de ordem de outra placa.
8. Teste parado imediatamente apos a confirmacao/selecao — CONFIRMADO,
   nao avancou para preenchimento de CNH/CRLV/Talent.
9. `OC-TESTE-005` continua `ATIVA` apos o teste — CONFIRMADO por
   consulta real ao banco externo: `id=11`, `status='ATIVA'`,
   `placa_prevista='TST0A01'`, inalterado.

### Veredito
**APROVADA** a validacao fisica da etapa inicial da Expedicao. Nenhuma
falha encontrada, nenhum codigo alterado durante o teste.

### Registro para limpeza controlada em /03-revisao (NAO EXECUTADO AGORA)
- `id_atendimento=1185` (banco do totem, `udlog_totem`) — atendimento de
  teste criado por este teste fisico, em `status='em_andamento'`,
  `etapa_atual='exp_cnh'`. Precisa ser cancelado/removido de forma
  controlada no `/03-revisao`, nao antes.
- Observacoes adicionais encontradas (nao relacionadas a este teste,
  pre-existentes, NAO tocadas): `id_atendimento=1184` (mesma placa/OC,
  ja `status='cancelado'`, tentativa anterior) e `id_atendimento=1125`/
  `1126` (placa gravada sem ordem, de 2026-09-10, orfaos de outra
  sessao) — registrados para avaliacao de limpeza futura, decisao do
  usuario.
- Fixture `OC-TESTE-005` (banco externo, id=11) permanece INTACTO e
  MANTIDO — nao remover ainda, conforme instrucao explicita (uso
  continuado em testes fisicos).

Nenhum commit/push realizado.

## Fixture adicional para teste MANUAL de multiplas ordens (2026-09-11)

A pedido do usuario, antes da limpeza do `/03-revisao`: criado um segundo
fixture dedicado para ele testar fisicamente o cenario de "multiplas
ordens ativas para a mesma placa" (ate agora so testado automaticamente
com `ABC1D23`).

- Placa: `TST0B01` (nova, sem colisao com nenhuma ordem existente)
- Ordens: `OC-TESTE-008` e `OC-TESTE-009`, ambas `status='ATIVA'`,
  `cliente_id=8` (AKRO-PLASTIC DO BRASIL, mesmo reaproveitado em
  `OC-TESTE-005`), `email_recebido_id=1` (mesmo fixture reaproveitado).
  Zero dado pessoal novo (`motorista_id`/`transportadora_*`/
  `motorista_nome_previsto`/`cnh_prevista` todos NULL nas duas).
- Validado ao vivo antes de inserir: `cliente_id=8` ATIVO,
  `email_recebido_id=1` existe, placa e numeros de ordem sem colisao.
  `OC-TESTE-005` confirmada intacta (nao foi tocada por esta insercao).

SQL de remocao documentado (NAO EXECUTADO, usar quando o usuario terminar
o teste manual — igual `OC-TESTE-005`, este fixture tambem fica
pendente de limpeza controlada, agora junto com `OC-TESTE-008`/`009`):
```sql
DELETE FROM tb_ordens_coleta WHERE numero_ordem_coleta IN ('OC-TESTE-008','OC-TESTE-009');
```

Nenhum commit/push. Nenhuma alteracao de codigo.

## Resultado da revisao (/03-revisao, 2026-09-11)

### Revisao de seguranca independente — APROVADO

Todos os 10 pontos do checklist do usuario confirmados por leitura do
codigo REAL (nao so o handoff): conexao dedicada sem vazamento de DSN/
credencial; SQL sempre via prepared statement; filtros `status='ATIVA'`/
`status='ATIVO'` na query real com aspas corretas; normalizacao de placa
exclusivamente no backend; comportamento 0/1/multiplas ordens correto,
sem selecao automatica indevida; `selecionarOrdem()` reconsulta as
ordens reais, valida posse/tipo/status/etapa, rejeita selecao forjada e
IDOR classico; migrations idempotentes e corretamente isoladas por
banco; **ausencia confirmada** de qualquer logica automatica
ATIVA->INATIVA; `.gitignore` protege o dump sensivel; nenhuma alteracao
fora do escopo (Recebimento/CNH/CRLV/Talent intocados,
`finalizar()` continua bloqueado por `TALENT_DOCTOS_PENDENTE`).

Nenhum achado bloqueante. Pendencias ja conhecidas (credencial dedicada
para o banco externo, decisao sobre o dump sensivel) permanecem
registradas, nao bloqueiam esta demanda.

### Teste fisico registrado como aprovado

- `TST0A01 -> OC-TESTE-005`: lista/confirmacao corretas, vinculo
  confirmado (id_atendimento=1185 na primeira rodada).
- `TST0B01 -> OC-TESTE-008/OC-TESTE-009`: lista exibida corretamente,
  SEM selecao automatica com multiplas ordens, escolha e vinculo
  funcionando (id_atendimento=1188 selecionou OC-TESTE-009).

### Limpeza controlada executada

**Removido (transacional, por `id_atendimento` exato, banco do totem):**
- `id_atendimento=1185` (TST0A01/OC-TESTE-005, autorizado).
- `id_atendimento=1186` (TST0B01, etapa `placa`, sem ordem selecionada —
  tentativa inicial do teste de multiplas ordens, capturado pelo filtro
  autorizado `tipo=expedicao AND placa=TST0B01`).
- `id_atendimento=1188` (TST0B01/OC-TESTE-009, autorizado — pasta
  `storage/atendimentos/2026-09-11/TST0B01_114116` com `cnh_frente.jpg`/
  `cnh_verso.jpg` removida junto).
- `tb_atendimento_nota`/`tb_fila_envio`: 0 antes/depois para esses 3 ids
  (nenhum dependente existia).

**Removido (banco externo `udlogo59_db_gestao_coletas`):**
```sql
DELETE FROM tb_ordens_coleta WHERE numero_ordem_coleta IN ('OC-TESTE-005','OC-TESTE-008','OC-TESTE-009');
```
Contagem antes=3, depois=0. Coluna `status` e migration
`001_status_ordem_coleta.sql` preservadas (confirmado via `SHOW COLUMNS`).

### Achados NOVOS, fora do escopo autorizado — NAO tocados

Ao localizar os atendimentos de `TST0B01`, apareceram dois vizinhos que
NAO estavam na lista autorizada nem na lista "so analisar" — o
backend-especialista parou e nao tocou em nenhum, corretamente:

| id_atendimento | tipo | placa | ordem_coleta | status | etapa_atual | observacao |
|---|---|---|---|---|---|---|
| 1187 | expedicao | TST0A01 | OC-TESTE-005 | cancelado | dados_encontrados | outra tentativa do mesmo teste, pasta nao existe em disco |
| 1189 | recebimento | TST0A01 | (vazio) | cancelado | digitalizacao_notas | tipo RECEBIMENTO (fora desta demanda), 5 notas reais em disco (`storage/atendimentos/2026-09-11/TST0A01_114312/nota_01.jpg`...`nota_05.jpg`) |

### Dados de 1184/1125/1126 (leitura apenas, NAO removidos)

- **1184**: `expedicao`, `TST0A01`/`OC-TESTE-005`, `status=cancelado`,
  `etapa_atual=dados_encontrados`, criado 2026-09-11 11:13:13 — outra
  tentativa do mesmo teste fisico, pasta nao existe em disco.
- **1125**: `expedicao`, `TST0A01`, sem ordem, `status=em_andamento`
  (AINDA ATIVO), `etapa_atual=placa`, criado 2026-09-10 19:21:46.
- **1126**: `expedicao`, `TST0A01`, sem ordem, `status=em_andamento`
  (AINDA ATIVO), `etapa_atual=placa`, criado 2026-09-10 19:21:53 (7s
  depois do 1125).

Origem exata de 1125/1126 nao determinavel com certeza pelos dados
disponiveis (nenhum campo aponta a causa) — permanecem `em_andamento`
indefinidamente ate decisao do usuario.

### Veredito final

**APROVADO.** Demanda pode seguir para `/04-commit-e-push` quando o
usuario autorizar, apos decidir sobre os achados fora de escopo
(1187, 1189, 1125, 1126) — nenhum deles bloqueia a aprovacao desta
demanda, sao apenas residuos/atendimentos independentes encontrados
durante a limpeza.

Nenhum commit/push realizado nesta etapa.
