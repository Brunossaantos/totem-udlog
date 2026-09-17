# Handoff - integridade-conclusao-atendimento

Data: 2026-09-16
Etapa: 00-planejamento

## O que foi pedido

Investigar pelo codigo real (nao por suposicao) e planejar a correcao de 4
problemas relacionados a integridade do atendimento apos conclusao:

1. cancelar e bloquear-excesso-notas conseguem hoje reverter um
   atendimento ja concluido -- decisao de produto confirmada: isso deve
   parar de acontecer. Ambas as acoes devem responder HTTP 409, mensagem
   segura, nenhuma alteracao no banco quando o atendimento ja estiver
   concluido. Reabertura fica para painel administrativo futuro
   (autenticado e auditavel), fora desta demanda.
2. Condicao de corrida em concluirDigitalizacao() com duas requisicoes
   quase simultaneas (sem lock/transacao/CAS hoje).
3. GET_LOCK/RELEASE_LOCK em DocumentoController nao e liberado
   explicitamente em erro porque exit() dentro de Resposta::erro()
   pula o finally.
4. Ausencia de tratamento de PDOException em NotaController.

Escopo adicional pedido: matriz de estados x acoes, plano de testes,
contrato HTTP, estrategia transacional/lock, sem implementar nada, sem
alterar banco, sem chamar Talent, sem imprimir, sem commit/push.

## Investigacao - causas confirmadas (por leitura direta de codigo, explorer)

1. cancelar() (AtendimentoController.php:609-617): so valida posse
   do atendimento pelo totem autenticado. Comentario explicito nas linhas
   612-613 diz que a ausencia de checagem de status e PROPOSITAL ("cancelar
   precisa funcionar em qualquer tela/tipo/status do fluxo"). Chama
   AtendimentoRn::cancelar() (linha 201-204, so repassa) ->
   AtendimentoDao::cancelar() (linha 408-412):
   UPDATE tb_atendimento SET status = "cancelado" WHERE id_atendimento = :id
   -- incondicional, sem clausula de status.

2. bloquearPorExcessoDeNotas() (AtendimentoController.php:239-250):
   so valida posse + tipo=recebimento. Chama
   AtendimentoRn::bloquear() (linha 206-209) ->
   AtendimentoDao::bloquear() (linha 414-418):
   UPDATE tb_atendimento SET status = "bloqueado", etapa_atual = "balcao_portaria" WHERE id_atendimento = :id
   -- tambem incondicional.

3. Nenhum terceiro caminho encontrado. Todos os outros metodos que
   escrevem em tb_atendimento (selecionarOrdem linha 94, salvarEtapa
   linhas 153/185/207/225, concluirDigitalizacao linha 271,
   avancarEtapaDocumentos linha 380, finalizar linha 502) ja checam
   status diferente de em_andamento antes de escrever -- sao seguros
   hoje, confirmados por leitura de cada metodo.

4. concluirDigitalizacao() (AtendimentoController.php:258-320): sem
   AtendimentoRn/AtendimentoDao dedicado, toda a logica no Controller.
   Fluxo: SELECT (posse/status/etapa) -> checagens em PHP -> SELECTs de
   contagem/status de notas -> AtendimentoDao::atualizarEtapa()
   (linha 38-42): UPDATE tb_atendimento SET etapa_atual = :etapa WHERE id_atendimento = :id
   -- sem clausula de etapa no WHERE, sem transacao, sem SELECT FOR
   UPDATE, sem GET_LOCK, sem CAS. Duas requisicoes quase simultaneas
   podem ambas passar pela checagem em PHP antes de qualquer uma escrever.

5. DocumentoController::iniciarProcessamento() (linhas 213-259): usa
   GET_LOCK/RELEASE_LOCK (obterLock()/liberarLock(), linhas
   461-481) com try/finally chamando liberarLock, mas dentro do try ha
   2 chamadas a Resposta::erro() (linhas 225, 235) que internamente
   chamam exit (util/Resposta.php:14-20). Em PHP, exit() dentro de try
   nao dispara finally. Confirmado por security-especialista via grep:
   PDO::ATTR_PERSISTENT nao e usado em nenhum lugar do projeto -- nao ha
   pooling de conexao do lado da aplicacao, logo o GET_LOCK e liberado
   quando a conexao MySQL fecha ao fim da requisicao PHP, mesmo sem
   passar pelo finally. Nao ha evidencia de lock preso "para sempre"
   hoje -- e uma fragilidade de higiene de codigo (o comentario do
   proprio codigo superestima a garantia), nao uma vulnerabilidade
   ativa. Risco residual (nao confirmavel so por codigo): comportamento
   de pooling/keep-alive do ambiente real do Hostgator -- registrado
   como pendencia para devops-especialista se necessario.

6. NotaController.php: nenhuma ocorrencia literal de PDOException no
   arquivo. processar(), identificarCliente(), definirNumero() e,
   principalmente, algumaIdentificada() (sem NENHUM try/catch, linhas
   242-248) e o metodo privado buscarAtendimentoDoTotem() (linhas
   280-288, usado por todos) tem trechos de chamada a banco fora de
   qualquer protecao. Confirmado por security-especialista: PDO roda
   com PDO::ERRMODE_EXCEPTION (util/Conexao.php:23), sem
   set_exception_handler() global em nenhum lugar do projeto -- uma
   PDOException nao capturada propaga ate o handler padrao do PHP, cujo
   comportamento de exposicao (stack trace/detalhe de SQL) depende de
   display_errors do ambiente, nao confirmado para o Hostgator de
   producao.

7. Padrao ja usado no projeto (reaproveitavel): CAS via UPDATE com
   SET novo estado WHERE id = :id AND coluna_estado IN (estados
   elegiveis), com rowCount() > 0, usado em
   AtendimentoDao::iniciarProcessamento()/gravarResultadoProcessamento()/
   iniciarEnvioTalent()/gravarResultadoEnvioTalent()/
   marcarEnvioTalentObsoletoComoIndeterminado(). Nao existe, em nenhum
   lugar do projeto, uso de beginTransaction()/commit()/rollBack() --
   nao ha precedente de transacao explicita.

8. Schema: tb_atendimento.status e ENUM('em_andamento','concluido',
   'cancelado','bloqueado') (sql/schema.sql:53). etapa_atual e
   VARCHAR(40) livre (linha 52), sem ENUM. Nenhuma migration altera
   essas duas colunas.

9. Precedente de HTTP 409 ja existe no projeto: AtendimentoController.php:589
   (check-in ja em processamento), ImpressaoAtendimentoController.php:82
   (check-in ainda nao confirmado, sem etiqueta), NotaController.php:227
   (numero de nota duplicado) -- todos "estado atual do recurso conflita
   com a acao pedida", mesma classe do problema desta demanda. 409 e
   semanticamente correto (nao e 403, que seria erro de autorizacao; nao
   e 422, que e mais para payload semanticamente invalido).

10. Analise do comentario "cancelar precisa funcionar em qualquer
    tela/tipo/status" (linhas 612-613): pela redacao, a intencao
    documentada e sobre etapa/tipo do fluxo em andamento, nao sobre
    reverter um atendimento ja concluido -- nenhum outro metodo do
    projeto depende de cancelar funcionar sobre status=concluido.
    Ainda assim, e uma leitura de intencao de comentario, nao
    confirmacao de decisao de produto sobre o front-end -- ver
    pendencia.

## Matriz de estados x acoes (comportamento alvo pos-correcao)

| status atual   | cancelar                                                | bloquear-excesso-notas (so tipo=recebimento)                          |
|----------------|----------------------------------------------------------|------------------------------------------------------------------------|
| em_andamento   | HTTP 200 - status vira cancelado                          | HTTP 200 - status vira bloqueado, etapa_atual vira balcao_portaria       |
| concluido      | HTTP 409 - nenhuma alteracao no banco                | HTTP 409 - nenhuma alteracao no banco                              |
| cancelado      | HTTP 200 - reafirma cancelado (comportamento atual mantido, sem regressao) | HTTP 200 - mesma logica de idempotencia (comportamento atual mantido) |
| bloqueado      | HTTP 200 - status vira cancelado (comportamento atual mantido) | HTTP 200 - reafirma bloqueado/balcao_portaria |

So concluido passa a ser bloqueado como origem -- nenhuma restricao nova
sobre cancelado/bloqueado como origem (fora do escopo confirmado).

## Estrategia transacional/lock (decisao consolidada - backend + security concordam)

1. cancelar()/bloquear-excesso-notas: CAS diretamente no UPDATE
   (WHERE id_atendimento = :id AND status != 'concluido'), retornando
   bool via rowCount() > 0, subindo esse retorno por
   AtendimentoDao -> AtendimentoRn -> AtendimentoController (responde
   409 se false). Rejeitada a alternativa de checagem so em PHP antes
   do UPDATE -- ambos os sub-agentes confirmaram que isso reabriria uma
   janela de corrida (TOCTOU) entre o SELECT de posse/status e o
   UPDATE, exatamente a classe de problema que o padrao CAS ja
   existente no projeto foi desenhado para evitar. Nenhuma transacao
   nova necessaria -- o UPDATE composto e atomico no InnoDB.

2. concluirDigitalizacao(): CAS via novo metodo dedicado em
   AtendimentoDao (ex. concluirDigitalizacaoNotas()):
   UPDATE tb_atendimento SET etapa_atual = :nova_etapa WHERE id_atendimento = :id AND status = 'em_andamento' AND etapa_atual = 'digitalizacao_notas',
   retornando rowCount() > 0. Rejeitadas transacao explicita e
   SELECT FOR UPDATE -- nenhum precedente no projeto, risco de deadlock
   (primeiro uso de lock de linha do projeto) e dependencia de
   parametros de timeout do MySQL do Hostgator nao
   controlaveis/confirmados. CAS via UPDATE/WHERE e atomico, sem lock
   explicito, consistente com o restante do AtendimentoDao, mais
   simples de auditar/testar.
   Contrato da segunda requisicao concorrente: se o CAS falhar
   (rowCount() igual a zero), a Controller rele o estado atual do
   atendimento; se etapa_atual ja e o estado-alvo (ou um estado
   posterior legitimo do fluxo), responde sucesso idempotente com os
   mesmos dados que a chamada vencedora devolveria, sem reexecutar
   nenhum efeito colateral; se o estado nao e nem a origem nem o
   destino esperado, responde conflito. Nenhuma nova escrita de
   negocio e perdida nesse desenho -- a leitura de notas ja ocorreu
   antes deste endpoint, a unica coisa em disputa e qual requisicao
   grava a transicao de etapa. AtendimentoDao::atualizarEtapa()
   generico nao deve virar CAS globalmente (quebraria outros
   chamadores que ja fazem sua propria checagem em PHP) -- o novo
   metodo e dedicado a este caminho.

3. Lock de DocumentoController: nao chamar Resposta::erro()/exit
   dentro do escopo protegido pelo try/finally do lock. Capturar a
   intencao de erro (mensagem + codigo HTTP) em variavel local, deixar
   o finally liberar o lock normalmente, e so chamar Resposta::erro()
   depois, fora do escopo do lock -- garantindo liberacao explicita
   independente do comportamento de fechamento de conexao do host.

4. PDOException em NotaController: envolver cada metodo publico
   (processar, identificarCliente, definirNumero, algumaIdentificada)
   num try/catch externo que cubra tambem as chamadas hoje
   desprotegidas (buscarAtendimentoDoTotem, contarNotas,
   ordemJaRegistrada, algumaNotaIdentificouCliente), no mesmo padrao ja
   usado nos catches internos existentes: error_log() com mensagem
   tecnica (nunca payload do motorista), Resposta::erro() com mensagem
   generica fixa (nunca $e->getMessage()), HTTP 500.

## Contrato HTTP proposto

- cancelar sobre concluido -> HTTP 409, corpo JSON de erro padrao do
  projeto (Resposta::erro), mensagem que nao sugira nova tentativa
  (ex. deixar claro que o atendimento ja foi concluido com sucesso).
- bloquear-excesso-notas sobre concluido -> HTTP 409, mesmo padrao.
- concluirDigitalizacao() - segunda requisicao perdedora do CAS:
  HTTP 200 idempotente se o estado ja e o alvo esperado; conflito (409)
  so se o estado nao corresponde a nenhum dos dois.
- Lock de DocumentoController e PDOException de NotaController: sem
  mudanca de contrato HTTP -- mesmas mensagens/codigos ja existentes
  hoje (503/500), so muda a garantia de quando sao emitidos (lock
  sempre liberado antes; PDOException sempre cai no formato sanitizado
  em vez de propagar sem tratamento).

## Arquivos que precisarao ser alterados (/01-implementacao)

- app/Dao/AtendimentoDao.php -- cancelar()/bloquear() com
  AND status != 'concluido' e retorno bool; novo metodo
  concluirDigitalizacaoNotas() (CAS por etapa_atual+status).
- app/Rn/AtendimentoRn.php -- cancelar()/bloquear() passam a repassar
  bool; novo metodo correspondente ao CAS de digitalizacao.
- app/Controller/AtendimentoController.php -- cancelar() e
  bloquearPorExcessoDeNotas() tratam o bool de retorno (409 se false);
  concluirDigitalizacao() usa o novo metodo CAS e trata a resposta
  idempotente da segunda requisicao concorrente.
- app/Controller/DocumentoController.php -- iniciarProcessamento()
  reestruturado para nao chamar Resposta::erro()/exit dentro do
  escopo protegido pelo lock.
- app/Controller/NotaController.php -- try/catch externo de
  PDOException/Throwable em processar(), identificarCliente(),
  definirNumero() e algumaIdentificada().

Nenhuma alteracao em sql/schema.sql/migrations e necessaria -- status
ja e ENUM com os 4 valores usados; nenhum campo novo e preciso.

## Compatibilidade

Todo SQL proposto usa sintaxe padrao MySQL/InnoDB ja usada em outros
pontos do projeto (UPDATE com WHERE/AND), sem transacao nova, sem
feature de versao recente. PHP: try/catch, try/finally, tipos de
retorno bool -- tudo disponivel desde PHP 7, compativel com PHP 8.0.3.

## Plano de testes (para /02-testes, fixtures isoladas ou transacao
reversivel - nenhum registro real alterado)

Reaproveitar o padrao ja usado em
tests/manual/teste_concorrencia_finalizar_checkin.php e
teste_concorrencia_numero_nota_duplicado.php (proc_open() para 2
subprocessos quase simultaneos, fixture isolada via helpers dedicados,
limpeza por DELETE explicito ao final):

1. Cancelar atendimento em_andamento -> HTTP 200, status=cancelado.
2. Cancelar atendimento concluido -> HTTP 409, status permanece
   concluido (confirmar por SELECT direto apos a chamada).
3. Bloquear excesso em atendimento em_andamento (tipo=recebimento) ->
   HTTP 200, status=bloqueado, etapa_atual=balcao_portaria.
4. Bloquear excesso em atendimento concluido -> HTTP 409, nenhuma
   alteracao no banco.
5. Duas conclusoes quase simultaneas de concluirDigitalizacao() (mesmo
   id_atendimento, 2 subprocessos via proc_open()) -> exatamente uma
   transicao efetiva de etapa_atual, a segunda responde sucesso
   idempotente sem duplicar nenhum efeito (sem segunda
   gravacao/auditoria/chamada externa).
6. Falha antes de adquirir o lock em DocumentoController (ex. mock de
   excecao antes de obterLock) -> confirmar que nao ha tentativa de
   liberarLock de um lock nunca adquirido.
7. Falha depois de adquirir o lock, dentro do escopo protegido ->
   confirmar RELEASE_LOCK explicito antes da resposta de erro (via
   SELECT IS_USED_LOCK(...) ou equivalente, nao so ausencia de erro).
8. Excecao durante a "transacao" (na pratica, durante a sequencia de
   UPDATEs do CAS) -> confirmar que nenhum estado parcial fica gravado
   (o CAS e um unico UPDATE, nao ha multiplos passos a reverter -- mas
   testar explicitamente que uma falha antes do UPDATE nao deixa
   talent/outros efeitos colaterais orfaos).
9. Confirmacao de rollback e liberacao do lock -- cobrir com os itens
   6/7 acima; como nao ha transacao nova, "rollback" aqui significa
   "nenhuma escrita parcial", validado por SELECT do estado
   antes/depois.
10. PDOException sanitizada -- forcar erro de banco (ex.
    mock/injecao de falha controlada) em NotaController e confirmar
    resposta JSON generica (sem SQL, stack trace, nome de
    host/credencial), com log tecnico completo apenas em error_log.
11. Regressao: Recebimento e Expedicao completos ponta a ponta (todas
    as etapas ja existentes, sem estado terminal envolvido) devem
    continuar funcionando sem nenhuma mudanca de comportamento
    observavel; envio real ao Talent (gate TALENT_CHECKIN_ATIVO) e
    impressao real permanecem fora de escopo desta demanda (nenhuma
    chamada real autorizada); reexecutar as suites automatizadas
    existentes (tests/manual/*.php relevantes) para confirmar zero
    regressao.

## Riscos e rollback

- Risco principal identificado pelo security-especialista: hoje, um
  atendimento concluido que seja cancelado via bug nao dispara nenhum
  callback de volta para Talent/gestao de coletas -- divergencia de
  estado entre 3 sistemas sem trilha de auditoria. A correcao desta
  demanda fecha esse vetor (bloqueio na origem), nao introduz nenhum
  novo mecanismo de sincronizacao reversa (fora de escopo).
- Rollback simples: as mudancas propostas sao aditivas em nivel de
  WHERE (clausula extra) e um novo metodo dedicado -- reverter e
  remover a clausula/o metodo novo, sem migration de schema envolvida.
- Risco residual nao mitigavel so por codigo: comportamento real de
  pooling/keep-alive de conexao do Hostgator em producao (lock do item
  3) -- nao verificavel sem acesso ao ambiente.

## O que sera feito (resumo)

Implementar, na proxima etapa (/01-implementacao), exatamente as 5
mudancas de arquivo listadas acima, seguindo a estrategia CAS
consolidada, sem tocar nos contratos ja aprovados de
Talent/impressao/Recebimento/Expedicao alem do estritamente
necessario.

## O que NAO sera feito

- Reabertura de atendimento concluido (painel administrativo futuro,
  fora de escopo).
- Nenhuma alteracao em sql/schema.sql/migrations.
- Nenhuma chamada real ao Talent, nenhuma impressao real.
- Nenhuma alteracao no mecanismo de idempotencia do Talent
  (talent_checkin_status), ja resolvido em demanda anterior.
- Nenhuma alteracao no comportamento de cancelado/bloqueado como
  estados de origem para cancelar/bloquear-excesso-notas (so
  concluido passa a ser bloqueado).
- Nenhuma extensao do tratamento de PDOException a outros controllers
  (AtendimentoController, DocumentoController) -- fora do escopo
  pedido, registrado como observacao (ver pendencias).

## Sub-agentes envolvidos

- explorer -- mapeamento completo do codigo real (endpoints, DAOs,
  padroes de CAS existentes, schema, testes de concorrencia ja
  existentes).
- backend-especialista -- plano tecnico de correcao (estrategia CAS,
  matriz de estados, arquivos a alterar, compatibilidade PHP/MySQL).
- security-especialista -- revisao independente de risco/severidade,
  confirmacao do mecanismo mais seguro contra corrida, confirmacao de
  ausencia de PDO::ATTR_PERSISTENT (risco de lock preso), validacao da
  semantica HTTP 409, analise do comentario "cancelar em qualquer
  status".
- trello-especialista -- criacao do cartao de acompanhamento.

## Pendencias conhecidas

1. Confirmar com quem conhece o front-end/fluxo de telas se existe
   algum caminho legitimo que dependa de cancelar funcionar apos
   status=concluido -- nao encontrada evidencia de codigo que dependa
   disso, mas nao e confirmacao definitiva do lado do front-end (fora
   do escopo desta rodada, que revisou so o backend).
2. Confirmar configuracao real de display_errors/log_errors do
   ambiente de producao Hostgator -- nao verificavel por codigo;
   relevante para dimensionar a urgencia real do achado de
   PDOException (sugestao: devops-especialista confirmar em demanda
   futura, se necessario).
3. Comportamento real de pooling/keep-alive de conexao do MySQL no
   Hostgator (relevante so como contexto residual do achado do lock --
   sem evidencia de problema ativo hoje).
4. Desenho fino exato da resposta idempotente da segunda requisicao
   concorrente em concluirDigitalizacao() (reconstituir proxima_tela/
   etapa a partir do estado atual do banco) fica para refinamento em
   /01-implementacao -- e detalhe de implementacao, nao decisao de
   produto pendente.
5. Mesmo padrao de ausencia de tratamento de PDOException pode existir
   em outros controllers do projeto (AtendimentoController,
   DocumentoController) -- nao investigado nem alterado nesta demanda,
   registrado apenas como observacao para avaliacao futura.

Nenhuma pendencia acima bloqueia o inicio de /01-implementacao -- a
correcao dos 4 pontos do escopo confirmado pode prosseguir com o plano
acima.

## Resultado da implementacao (2026-09-16, /01-implementacao)

Implementadas exatamente as 5 mudancas de arquivo previstas no plano,
pelo backend-especialista, com validacao (qa-testes) em 2 rodadas
(1 rodada inicial + 1 rodada de correcao pontual apos achados reais).

### O que foi feito

1. app/Dao/AtendimentoDao.php:
   - cancelar(int $id): bool -- UPDATE ... WHERE id_atendimento = :id
     AND status != "concluido", retorno via rowCount() > 0.
   - bloquear(int $id): bool -- mesmo padrao, incluindo
     etapa_atual = "balcao_portaria".
   - Novo metodo concluirDigitalizacaoNotas(int $id, string $novaEtapa): bool
     -- CAS via UPDATE ... WHERE id_atendimento = :id AND status =
     "em_andamento" AND etapa_atual = "digitalizacao_notas".
   - Novo metodo privado statusAtual(int $id): ?string (correcao do
     Bug 1, ver abaixo).
2. app/Rn/AtendimentoRn.php: cancelar()/bloquear() agora : bool
   (repasse puro do Dao); novo metodo concluirDigitalizacaoNotas()
   repassando o bool.
3. app/Controller/AtendimentoController.php:
   - cancelar()/bloquearPorExcessoDeNotas(): mantida a checagem de
     posse existente, sem checagem de status em PHP antes do UPDATE
     (evita TOCTOU); resposta HTTP 409 com mensagem segura se o Dao
     retornar false (atendimento concluido).
   - concluirDigitalizacao(): usa o novo CAS; se perder a corrida,
     rele o atendimento e responde sucesso idempotente se a etapa ja
     e o alvo ou uma etapa posterior legitima do fluxo de Recebimento
     (reaproveitando etapaEhAlvoOuPosterior()); conflito so se o
     estado nao corresponde a nem origem nem destino esperado.
     Correcao do Bug 2 (ver abaixo) tambem aplicada antes da checagem
     de precondicao original.
4. app/Controller/DocumentoController.php::iniciarProcessamento():
   reestruturado para que Resposta::erro() (que chama exit) nunca
   seja invocada dentro do escopo try com finally liberando o lock
   -- intencao de erro capturada em variavel local, resposta emitida
   so depois do finally ja ter rodado. Mensagens/codigos HTTP
   (503/500) e gravacao de gravarResultadoProcessamento com ERRO
   preservados exatamente como estavam.
5. app/Controller/NotaController.php: processar(),
   identificarCliente(), definirNumero(), algumaIdentificada()
   ganharam try/catch de PDOException cobrindo os trechos antes
   desprotegidos (buscarAtendimentoDoTotem, contarNotas,
   ordemJaRegistrada, buscarNotaDaOrdem,
   algumaNotaIdentificouCliente), respondendo HTTP 500 generico sem
   vazar a mensagem da excecao, log tecnico completo so em error_log.

Validado com php -l em todos os arquivos alterados -- zero erro de
sintaxe. Nenhum desvio do plano aprovado foi necessario na primeira
rodada.

### Bugs reais encontrados pelo qa-testes e corrigidos na mesma etapa

Bug 1 -- cancelar()/bloquear() respondiam HTTP 409 incorretamente
quando o atendimento ja estava no MESMO estado que a acao tentaria
gravar (ex. cancelar() sobre atendimento ja cancelado). Causa raiz:
rowCount()==0 do MySQL/PDO e ambiguo entre "0 linhas casadas pelo
WHERE" (bloqueio real por concluido) e "1 linha casada mas nenhuma
coluna mudou" (idempotencia). Reproduzido de forma 100% deterministica
pelo qa-testes. Corrigido: novo metodo privado statusAtual(int $id):
?string (SELECT status FROM tb_atendimento WHERE id_atendimento = :id)
chamado so quando rowCount()==0 -- se status igual a concluido,
bloqueio real (false/409); qualquer outro valor, sucesso idempotente
(true/200). Revalidado pelo qa-testes: 5a/5b/5c/5d PASSOU nas 3
execucoes.

Bug 2 -- concluirDigitalizacao() respondia HTTP 400 (erro de
precondicao generico) em vez de sucesso idempotente quando a segunda
requisicao concorrente chegava DEPOIS que a primeira ja tinha
terminado completamente (etapa ja avancada antes da checagem de
precondicao etapa_atual != digitalizacao_notas rodar). Reproduzido de
forma 100% deterministica pelo qa-testes. Corrigido: antes de
responder o erro de precondicao, o codigo agora calcula a
etapa-destino e chama etapaEhAlvoOuPosterior() (metodo ja existente,
reaproveitado); se a etapa atual ja e o destino ou uma etapa posterior
legitima, responde sucesso idempotente em vez de 400. Revalidado pelo
qa-testes: 20/20 assertivas em 3 rodadas dedicadas ao timing
sequencial exato, mais 3 execucoes completas da suite via proc_open()
real.

### Resultado da validacao (qa-testes, 2 rodadas)

Roteiro completo (itens 1-11 do plano) executado com fixtures
isoladas/descartaveis, seguindo o padrao ja existente do projeto
(proc_open() para concorrencia real, helpers dedicados, limpeza por
DELETE explicito). Apos a correcao dos 2 bugs: todos os 11 itens do
roteiro PASSARAM, incluindo:
- cancelar/bloquear sobre em_andamento e concluido (HTTP 200/409
  conforme esperado, zero alteracao no banco no caso 409);
- as 4 combinacoes de cancelado/bloqueado como origem (HTTP 200,
  idempotente, sem regressao);
- 2 conclusoes quase simultaneas reais via proc_open() (exatamente
  uma transicao efetiva de CAS, segunda resposta idempotente sem
  duplicar nota, contagem de notas identica antes/depois);
- timing sequencial exato do "vencedor termina 100% antes do perdedor
  buscar o atendimento" (branch de precondicao, distinto do CAS) --
  perdedor recebe 200 idempotente, nunca mais 400;
- falha antes e depois da aquisicao do GET_LOCK em DocumentoController
  (mensagens/codigos HTTP identicos aos anteriores, 503/500), com
  RELEASE_LOCK explicito confirmado via SELECT IS_USED_LOCK(...)
  retornando NULL apos o erro;
- PDOException forcada (conexao derrubada) em NotaController
  retornando resposta generica sem SQL/stack trace/credencial, log
  tecnico completo so em error_log.

Regressao: 16 suites automatizadas existentes de
Recebimento/Expedicao/E2E/Talent/impressao/ordem de coleta
reexecutadas sem alteracao -- 100% PASSOU, zero regressao.

### Confirmacoes finais

- Zero residuo no banco: confirmado por SELECT COUNT(*) com LIKE nos
  prefixos sinteticos usados, igual a 0 em todas as tabelas tocadas,
  apos todas as execucoes.
- Zero chamada real ao Talent: TALENT_CHECKIN_ATIVO ausente/false em
  todas as execucoes; suites que tocam Talent confirmam explicitamente
  ausencia de chamada real.
- Zero impressao real: nenhuma etiqueta real gerada em nenhuma
  execucao.
- Zero alteracao de dado real, zero commit, zero push nesta etapa.
- Um residuo de banco PRE-EXISTENTE (de uma execucao interrompida
  antes desta sessao de implementacao, nao causado pela correcao
  atual) foi encontrado e removido pelo qa-testes durante a
  revalidacao -- registrado como observacao de higiene de execucao de
  teste anterior, nao um bug de codigo.

### Arquivos de teste criados (tests/manual/, nao sao codigo de producao)

- tests/manual/teste_integridade_conclusao_atendimento.php
  (orquestrador principal, recomendado para versionamento).
- tests/manual/_caso_cancelar.php
- tests/manual/_caso_bloquear_excesso.php
- tests/manual/_caso_concluir_digitalizacao_cas_direto.php
- tests/manual/_caso_concluir_digitalizacao.php
- tests/manual/_caso_iniciar_processamento_vio_indisponivel.php
- tests/manual/_caso_iniciar_processamento_falha_durante_validacao.php
- tests/manual/_caso_nota_pdo_falha.php

### Pendencias restantes (nao bloqueiam fechamento desta etapa)

Todas as pendencias ja registradas na secao "Pendencias conhecidas"
acima (confirmacao de front-end sobre cancelar em concluido,
display_errors/pooling do Hostgator, extensao de PDOException a
outros controllers) continuam abertas e fora do escopo desta
implementacao -- nao foram tocadas. Nenhuma pendencia nova de produto
surgiu. Observacao tecnica nova (nao bloqueante): pequena duplicacao
de chamada a algumaNotaComStatusIdentificada()/algumaIdentificada()
em concluirDigitalizacao() apos a correcao do Bug 2 (leitura pura,
sem efeito colateral, so uma query redundante no caso raro do branch
de idempotencia) -- registrado para avaliacao futura, nao corrigido
por ampliar o diff alem do necessario para os 2 bugs.

## Resultado dos testes (2026-09-16, /02-testes)

Revisores independentes (qa-testes e security-especialista), sem
participacao na implementacao original, reexecutaram tudo do zero com
ceticismo (nao confiaram na validacao ja feita em /01-implementacao).

### Etapa 1 -- Inspecao estatica

Todos os 9 itens do checklist confirmados por leitura de codigo
(arquivo+linha), COM UMA EXCECAO PARCIAL:

1. CAS presente nos 3 pontos -- confirmado
   (AtendimentoDao.php:434-444/451-461/485-494).
2. WHERE protege o estado concluido corretamente -- confirmado.
3. rowCount()==0 diferencia idempotencia/concluido/inexistente/outro
   estado -- confirmado via statusAtual() (linhas 467-474); caso
   "atendimento inexistente" e tratado de forma conservadora (Dao
   isolado devolveria sucesso), mas e inalcancavel na pratica porque
   todo chamador ja valida posse antes (confirmado dinamicamente).
4. Nenhuma janela de regressao de estado entre releitura e resposta
   -- confirmado (releitura em concluirDigitalizacao() e so leitura,
   nenhuma escrita condicionada a ela).
5. Resposta::erro() nunca chamada antes da liberacao do GET_LOCK --
   confirmado, todos os pontos de erro capturam mensagem/codigo em
   variavel local, resposta emitida so apos o finally.
6. RELEASE_LOCK so ocorre quando o lock foi adquirido -- confirmado.
7. Os 4 metodos do NotaController sanitizam PDOException -- PARCIAL.
   Confirmado que buscarAtendimentoDoTotem/contarNotas/
   ordemJaRegistrada/buscarNotaDaOrdem/algumaNotaIdentificouCliente/
   atualizarNumeroNota estao cobertos. ACHADO REAL (security-especialista,
   severidade atencao): em identificarCliente(), a chamada a
   verificarRateLimit() (NotaController.php:134), que executa 2 queries
   PDO reais via RateLimitOcrDao::incrementarEContar()
   (RateLimitOcrDao.php:28-40), roda ANTES de qualquer try/catch --
   uma PDOException nesse ponto especifico propagaria sem tratamento,
   nao coberta pela demanda apesar do objetivo declarado de proteger
   integralmente os 4 metodos publicos.
8. Nenhuma resposta/log expoe SQL/stack trace/caminho/credencial/dado
   pessoal -- confirmado.
9. Os 7 scripts em tests/manual/ nao contem segredo/dado real/endpoint
   de producao/chamada real ao Talent ou impressao -- confirmado, lido
   linha a linha pelos dois revisores independentemente.

### Etapa 2 -- Testes reais no ambiente local (fixtures sinteticas
isoladas, marcador exclusivo desta rodada: QA0217)

Todos os 20 itens do roteiro PASSARAM (evidencia objetiva: HTTP,
rowCount real, comparacao de linha inteira antes/depois, SELECT
IS_USED_LOCK):

1-8. Cancelar/bloquear em todos os estados (em_andamento, cancelado,
     bloqueado, concluido) -- PASSOU (reexecucao completa da suite
     pre-existente teste_integridade_conclusao_atendimento.php,
     43/43, sem regressao).
9. Atendimento inexistente -- PASSOU, HTTP 404 "Atendimento nao
   encontrado", contrato identico ao de antes desta demanda.
10-13. Duas conclusoes reais e simultaneas via proc_open(), exatamente
   um CAS vencedor, segunda chamada idempotente, zero duplicacao --
   PASSOU (reexecucao da suite pre-existente).
14. Estado genuinamente incompativel -- PASSOU (HTTP 400, zero
    alteracao), COM ACHADO TECNICO nao-bloqueante do qa-testes (ver
    abaixo).
15. Falha antes do GET_LOCK -- PASSOU, lock nunca tocado.
16. Falha depois do GET_LOCK -- PASSOU (tecnica de KILL CONNECTION_ID()
    real).
17. Lock confirmado livre (IS_USED_LOCK = NULL) apos ambos os erros --
    PASSOU.
18. Caminho de sucesso do lock -- PASSOU por evidencia indireta (finally
    incondicional do PHP + 3 variacoes de branch confirmadas
    dinamicamente); chamada real a VIO Decode trial nao foi exercitada
    por estar fora do escopo autorizado nesta rodada.
19. PDOException real com display_errors=1 -- PASSOU, HTTP 500
    generico confirmado em ambiente com display_errors=STDOUT.
20. Resposta sem conteudo tecnico mesmo com display_errors=1 --
    PASSOU (regex de deteccao de SQL/stack trace nunca presente).

Contagem ANTES (marcador QA0217): 0 linhas. Contagem DEPOIS: 0 linhas
residuais (confirmado por asercao automatizada). Suite pre-existente
(marcador ITG) tambem confirmou 0 residuo ao final.

### Achados registrados (nao corrigidos nesta etapa)

**Achado 1 -- ATENCAO, confirmado por security-especialista** (ja
descrito no item 7 da Etapa 1 acima): gap de cobertura de
PDOException em identificarCliente() -- a chamada de rate limit
(verificarRateLimit()/RateLimitOcrDao::incrementarEContar()) fica
fora de qualquer try/catch. Nao expoe dado sensivel (nao ha CPF/CNH
envolvido nesse ponto), mas contradiz o objetivo declarado da demanda
de proteger integralmente os 4 metodos publicos do NotaController.
**Este item do checklist obrigatorio de inspecao estatica (item 7)
NAO passou integralmente** -- por instrucao explicita do usuario
("se algum teste falhar... bloqueie o avanco"), o avanco para
/03-revisao fica BLOQUEADO ate esse gap ser fechado numa rodada curta
de /01-implementacao.

**Achado 2 -- observacao tecnica, nao bloqueante, qa-testes**:
AtendimentoController::etapaEhAlvoOuPosterior() (metodo novo desta
demanda) e um cheque puramente POSICIONAL na sequencia de etapas --
testado forcando etapa_atual diretamente no banco (bypass de
controller) para um estado que nunca passou pela origem
digitalizacao_notas, e o metodo tratou como "posterior legitimo"
(sucesso idempotente) em vez de conflito. Confirmado que esse estado
e INALCANCAVEL por qualquer chamada real da API hoje (todo endpoint
exige a etapa_atual exata anterior) -- nao e vulnerabilidade
exploravel atualmente, e uma fragilidade de desenho para o caso de um
bug futuro em outro ponto do codigo permitir transicao fora de ordem.
Registrado para avaliacao futura, fora do escopo exato dos 4
problemas originais desta demanda.

### Confirmacoes de seguranca (security-especialista, revisor
independente)

Sem achado critico. Checagem de posse/autenticacao intacta em todos
os 3 pontos (cancelar/bloquear/concluirDigitalizacao). CAS livre de
nova janela de corrida (concluido tratado como terminal em todo o
codigo, nenhum UPDATE reverte esse estado). HTTP 409 nao vaza
informacao alem do padrao ja usado no projeto. GET_LOCK/RELEASE_LOCK
corretos em todos os caminhos exigidos (1 observacao pre-existente
nao-bloqueante sobre gravarResultadoProcessamento() sem catch
interno, nao introduzida por esta demanda). Scripts de teste sem
segredo/dado real. Regressao de seguranca de demandas anteriores
(IDOR em selecionarOrdem/finalizar, idempotencia do Talent, posse em
salvarEtapa) confirmada intacta.

### Frontend (leitura de codigo, sem ambiente de browser disponivel)

- cancelarESair() captura qualquer erro do fetch (incluindo 409) e
  sempre chama novoAtendimento() em seguida -- nenhum loading/overlay
  preso.
- bloquear-excesso-notas e chamado logo apos iniciar() criar um
  atendimento novo (sempre em_andamento) -- na pratica nunca recebe
  409 nesse ponto do fluxo real.
- concluirDigitalizacao(), em erro (incluindo 409), reabilita o botao
  e mostra mensagem sanitizada vinda de json.erro (nunca SQL/codigo
  interno).
- Nenhum ponto do front-end depende de cancelar funcionar sobre
  status=concluido -- confirmado por leitura completa do fluxo de
  telas pos-conclusao (impressao).

### Regressao -- 28 suites reexecutadas

27/28 suites PASSARAM 100% (Recebimento, Expedicao, E2E, Talent -- 9
suites incluindo idempotencia/IDOR/payload/anexos/parsing/log
sanitizado/trava doctos/RNTC/UF --, impressao, ordem de coleta,
scanner/VIO Decode, concorrencia). 1 suite com falha PRE-EXISTENTE e
NAO relacionada a esta demanda: teste_consulta_ordem_coleta.php
(12/17), depende de dados de fixture no banco EXTERNO
udlogo59_db_gestao_coletas que nao existem/foram alterados neste
ambiente local -- confirmado por git diff que nenhum arquivo tocado
por esta demanda foi alterado; e pendencia de higiene de ambiente,
fora do escopo.

### Veredito consolidado

**PRECISA DE AJUSTE** -- retorna para uma rodada CURTA de
/01-implementacao, restrita a fechar o gap de PDOException em
identificarCliente() (Achado 1). As 4 correcoes do escopo original
(CAS cancelar/bloquear/concluirDigitalizacao, lock do
DocumentoController, PDOException do NotaController nos pontos ja
cobertos, e os 2 bugs corrigidos na etapa anterior) estao corretas,
testadas de forma independente e cetica por 2 revisores distintos,
sem nenhuma regressao real encontrada. O bloqueio e especificamente
sobre o item 7 do checklist de inspecao estatica pedido pelo usuario,
que nao passou integralmente. Achado 2 (etapaEhAlvoOuPosterior) fica
registrado como observacao tecnica nao bloqueante para avaliacao
futura, nao impede o fechamento desta demanda apos o Achado 1 ser
corrigido.


## Rodada curta de /01-implementacao -- correcao do gap de PDOException no rate limit (2026-09-16)

Escopo unico: fechar o Achado 1 da rodada anterior de /02-testes --
`NotaController::identificarCliente()` nao protegia a chamada de rate
limit (`verificarRateLimit()` -> `RateLimitOcrDao::incrementarEContar()`,
2 queries PDO reais) contra `PDOException`.

### Correcao aplicada

Em `app/Controller/NotaController.php` (linhas 127-149), a chamada
`$this->verificarRateLimit($idTotem);` foi envolvida em:

```php
try {
    $this->verificarRateLimit($idTotem);
} catch (\PDOException $e) {
    error_log('identificarCliente: falha de banco no rate limit: ' . $e->getMessage());
    Resposta::erro('Nao foi possivel identificar o cliente', 500);
    return;
}
```

`return` garante que nenhum OCR/logica posterior e alcancado apos a
falha (`Resposta::erro()` ja faz `exit()` internamente, `return` mantido
por consistencia com o resto do arquivo). Nenhuma reexecucao de
`incrementarEContar()` ocorre no catch -- sem risco de dupla
contabilizacao. Nenhuma alteracao em `RateLimitOcrDao.php`, na politica
fail-open quando o Dao nao e injetado, em `etapaEhAlvoOuPosterior()`,
em outro controller, em schema/migrations, ou em contratos de
Talent/impressao/VIO/OCR. `php -l` confirmou ausencia de erro de
sintaxe.

### Validacao (qa-testes, marcador sintetico RTL0917)

Todos os 8 itens pedidos PASSARAM, com evidencia objetiva (HTTP,
contagem de linhas antes/depois em `tb_rate_limit_ocr`, comparacao de
`status_ocr`, ausencia de conteudo tecnico mesmo com
`display_errors=1`):

1. Rate limit funcionando normalmente -- PASSOU.
2. Limite nao atingido, prossegue ate a logica de identificacao --
   PASSOU.
3. Limite atingido, resposta preservada (HTTP 429) -- PASSOU (com
   ressalva de ambiente registrada abaixo, nao bloqueante).
4. `PDOException` real forcada (conexao derrubada) -- PASSOU, HTTP
   500 generico, log tecnico completo so em `error_log`.
5. Resposta sanitizada com `display_errors=1` -- PASSOU, sem
   SQL/stack trace/caminho/credencial.
6. OCR/identificacao NAO iniciada apos a falha -- PASSOU, confirmado
   que `status_ocr` permanece `PENDENTE` nos cenarios de falha.
7. Sem dupla contabilizacao -- PASSOU, nenhuma linha gravada em
   `tb_rate_limit_ocr` nos cenarios de falha (a conexao ja falha na
   primeira query).
8. Metodos ja protegidos do NotaController sem regressao
   (`algumaIdentificada`, `processar`, `definirNumero`) -- PASSOU.

Ressalva de ambiente (nao bloqueante): o header `Retry-After` do item
3 nao pode ser observado dinamicamente via PHP CLI puro (limitacao do
SAPI, nao introduzida por esta correcao) -- compensado por verificacao
estatica do codigo-fonte confirmando que a linha do header permanece
intocada na mesma posicao de antes.

### Regressao

27 suites automatizadas reexecutadas -- 100% PASSOU (incluindo
Recebimento, Expedicao, Talent -- 9 suites --, impressao, ordem de
coleta, scanner/VIO Decode, concorrencia). `teste_vio_decode_wire_format.php`
pulado por falta de binarios externos opcionais (ja documentado em
rodadas anteriores). Suite completa da demanda
(`teste_integridade_conclusao_atendimento.php`) reexecutada do zero:
43/43 PASSOU.

**Falha pre-existente isolada, NAO e regressao desta correcao**:
`teste_consulta_ordem_coleta.php` (5/17 falhas), causada por
indisponibilidade do banco externo `udlogo59_db_gestao_coletas` neste
ambiente local -- confirmado por `git status` que nenhum arquivo fora
de `NotaController.php` (e os 2 arquivos de teste novos) foi alterado.

### Zero residuo e zero operacao real

Marcador RTL0917 (distinto de QA0217/ITG das rodadas anteriores): 0
linhas antes, 0 linhas depois em `tb_totem`/`tb_atendimento`. Nenhum
POST real ao Talent, nenhuma impressao real, nenhum registro real
alterado, nenhum commit/push.

### Arquivos de teste criados (tests/manual/, nao sao codigo de
producao)

- `tests/manual/_caso_identificar_cliente_rate_limit.php`
- `tests/manual/teste_rate_limit_identificar_cliente_pdo.php`
  (orquestrador, 24/24 asserções, recomendado para versionamento)

### Veredito

**LIBERADO para nova `/02-testes` curta / avanco para `/03-revisao`.**
O Achado 1 da rodada anterior esta fechado e validado. Achado 2
(`etapaEhAlvoOuPosterior()`, observacao tecnica nao bloqueante) segue
registrado em `ia_development_state.md` para avaliacao futura, fora
do escopo desta correcao pontual.


## Rodada curta de /01-implementacao -- sanitizacao final do log de PDOException (2026-09-16)

Escopo unico: o usuario apontou que os 6 pontos de `catch (\PDOException)`
adicionados nesta demanda em `app/Controller/NotaController.php`
registravam `error_log('<contexto>: falha de banco: ' . $e->getMessage())`
-- risco real, ja que `PDOException::getMessage()` pode conter fragmento
de SQL, valores de parametro ou outro detalhe tecnico, dependendo do
driver/erro.

### Correcao aplicada

Novo metodo privado unico, usado nos 6 catches
(`processar`, `identificarCliente` -- 2 pontos, `definirNumero` -- 2
pontos, `algumaIdentificada`):

```php
private function logFalhaBancoPdo(string $contexto, \PDOException $e): void
{
    $sqlstate = (string) $e->getCode();
    $sqlstateValidado = preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? $sqlstate : null;

    error_log(
        $contexto . ': falha de banco (PDOException)'
        . ($sqlstateValidado !== null ? " [SQLSTATE={$sqlstateValidado}]" : '')
    );
}
```

Nunca acessa `getMessage()`/`getTraceAsString()`/`getFile()`/`getLine()`.
O SQLSTATE so entra no log se bater estritamente no formato esperado (5
caracteres alfanumericos maiusculos) -- nunca confia as cegas em
`getCode()`. Confirmado por grep: nenhum dos 6 catches de `\PDOException`
acessa esses metodos da excecao. `php -l` sem erro de sintaxe. Nenhuma
mudanca em mensagens de `Resposta::erro()`, HTTP 500, interrupcao antes
do OCR, comportamento de rate limit/HTTP 429, ou qualquer outro arquivo.

Observacao registrada (fora de escopo, nao alterada): os catches de
`\Throwable`/`\RuntimeException` (distintos de `\PDOException`) em
`identificarCliente()`/`definirNumero()` ainda logam `$e->getMessage()`
diretamente -- nao fazem parte do pedido desta rodada, que foi restrito
aos catches de `\PDOException`.

### Teste obrigatorio -- marcadores sinteticos (qa-testes)

`\PDOException` construida manualmente com mensagem combinando 5
marcadores sinteticos (SQL, caminho local, CPF, placa, credencial),
disparada atraves do caminho real de producao (subclasse de
`AtendimentoDao` injetada, sem alterar `app/`/`util/`) em 2 dos 6
pontos de catch (`processar()`, `identificarCliente()`). Executado com
`display_errors=1`, `log_errors=1`, `error_log` direcionado a um
arquivo temporario isolado fora do repositorio.

**Formato real gravado no log**:
```
[17-Sep-2026 19:20:23 ...] processar: falha de banco (PDOException) [SQLSTATE=42S02]
[17-Sep-2026 19:20:28 ...] identificarCliente: falha de banco (PDOException) [SQLSTATE=42S02]
```

**Busca dos 5 marcadores em cada local exigido**: ZERO ocorrencias em
todos os 4 locais -- resposta HTTP (corpo JSON), stdout do processo
PHP, arquivo de log temporario isolado (conteudo final e exatamente as
2 linhas acima, nada mais), e repositorio (`grep -r SINTETICO_ .` = 0
arquivos, `git status --porcelain` identico ao snapshot inicial).

### Regressao focada

`teste_integridade_conclusao_atendimento.php`: 43/43 PASSOU, sem
regressao (inclui os itens de PDOException sanitizada em
`algumaIdentificada`/`processar`).

`teste_rate_limit_identificar_cliente_pdo.php`: 23/24 PASSOU. A unica
falha (sub-verificacao do Item 5, "nunca vaza caminho de
servidor/stack trace") e um FALSO POSITIVO da propria asercao do
script de teste, nao um vazamento real: a asercao mistura stdout+stderr
num unico buffer e checa ausencia do literal `'PDOException'` -- essa
palavra so aparece no STDERR (destino de `error_log()` no CLI, ou seja,
o log de SERVIDOR), nunca no STDOUT (a resposta HTTP real que o
cliente recebe, confirmada limpa isoladamente:
`{"sucesso":false,"erro":"Nao foi possivel identificar o cliente"}`).
A asercao foi escrita numa rodada anterior, antes do novo formato de
log desta rodada incluir o rotulo fixo `(PDOException)` -- ela detecta
corretamente a presenca do NOME DA CLASSE no log de servidor (que e
esperado e inofensivo, nao e SQL/stack trace/dado sensivel), nao um
vazamento real. Nao corrigido por nao ser codigo de producao e estar
fora do pedido desta rodada -- registrado como observacao para quem
mantiver os scripts de teste desta demanda no futuro (ajustar a
asercao para isolar stdout de stderr, ou aceitar o literal
`(PDOException)` como esperado).

### Zero residuo e zero operacao real

Nenhum arquivo de producao alem de `NotaController.php` foi alterado.
Todo o trabalho de verificacao (script de marcadores, arquivo de log
temporario, capturas de stdout/stderr) foi feito e removido dentro do
scratchpad da sessao -- nenhum residuo no repositorio nem em disco.
Nenhuma chamada real ao Talent, nenhuma impressao real, nenhum
commit/push.

### Veredito

**LIBERADO para `/02-testes` curta.** Nenhum vazamento real de dado
sensivel confirmado pelos 5 marcadores testados em 4 locais distintos.
A unica falha de asercao e um falso positivo de metodologia do proprio
script de teste (nao isola stdout de stderr), nao uma falha de
seguranca do codigo corrigido.


## Correcao final -- metodologia do teste de rate limit (2026-09-16)

Escopo unico: corrigir SOMENTE `tests/manual/teste_rate_limit_identificar_cliente_pdo.php`
(nao e codigo de producao) para eliminar o falso positivo do Item 5,
sem tocar em `app/`/`util/`.

### Causa exata do falso positivo

O Item 5 rodava o subprocesso concatenando stdout+stderr num unico
buffer. `error_log()` do PHP CLI neste ambiente cai em stderr quando
nao ha override explicito (`C:\xampp\php\logs\` nao existe). O log
gerado por `NotaController::logFalhaBancoPdo()` grava de proposito
`"...: falha de banco (PDOException) [SQLSTATE=...]"` nesse destino --
comportamento correto e intencional. A asercao antiga checava ausencia
do literal `PDOException` no buffer COMBINADO, derrubando o teste
mesmo com a resposta HTTP (isolada em stdout) 100% limpa. Bug de
metodologia do teste, nao de producao.

### Alteracao feita no script de teste

Nova funcao `dispararComLogDedicado()`: roda o subprocesso com
stdout/stderr em pipes SEPARADOS (nunca concatenados) e configura
`-d log_errors=1 -d error_log=<arquivo dedicado>`, retornando os 3
fluxos isoladamente. Nova funcao `contemMarcadorSensivelComum()`:
checa marcadores proibidos (SQL, caminho local, credencial, host, CPF
e placa sinteticos) da mesma forma em qualquer buffer -- deliberadamente
NAO inclui o literal `PDOException`/SQLSTATE (permitidos so no log).
Item 5 reescrito com 5 asserçoes avaliando stdout, stderr e arquivo de
log separadamente. Achado colateral corrigido: `sys_get_temp_dir()`
neste ambiente resolve para um caminho com `~` (nome curto do
Windows) que quebra o parser de argumentos `-d` do PHP CLI -- o
arquivo de log dedicado foi movido para dentro de `tests/manual/`.

### Prova de deteccao de vazamento real (feita e revertida)

Injetada temporariamente uma string com marcador sensivel
(`SELECT senha FROM tb_totem_fake_prova`) no stdout capturado -- 2
asserçoes do Item 5 falharam, confirmando que a asercao corrigida
detecta vazamento real. Injecao removida em seguida (`diff` confirmou
reversao exata).

### Resultados

- `teste_rate_limit_identificar_cliente_pdo.php` (limpo, sem a
  injecao de prova): **24/24 PASSOU**.
- `teste_integridade_conclusao_atendimento.php`: **43/43 PASSOU**
  (confirma que nada de producao foi tocado/regrediu).
- Busca por `getMessage()`/`getTraceAsString()`/`getFile()`/`getLine()`
  nos 6 catches de `\PDOException` em `NotaController.php`: ZERO
  ocorrencias, inalterado desde a rodada anterior.

### Observacao pre-existente, NAO corrigida (fora do escopo)

`NotaController.php` tem 4 usos de `$e->getMessage()` em catches de
`\Throwable`/`\RuntimeException` (distintos de `\PDOException`) --
vem de excecoes internas do proprio codigo de negocio (nao de
`PDOException`), fora do escopo desta demanda de saneamento.
Registrado para avaliacao futura.

### Zero residuo

Nenhum arquivo em `app/`/`util/` alterado. Nenhum `.bak`/log residual
em `tests/manual/`. Nenhum registro real alterado, nenhuma chamada ao
Talent real, nenhuma impressao real, nenhum commit/push.

### Veredito

**LIBERADO para `/02-testes` curta e independente.** A metodologia do
teste agora isola corretamente os 4 fluxos (resposta HTTP, stdout,
stderr, arquivo de log), com prova concreta de deteccao de vazamento
real e reversao confirmada da injecao de prova.


## Resultado dos testes -- /02-testes curta e independente (2026-09-16)

Dois revisores independentes (qa-testes + security-especialista, sem
participacao na correcao anterior) reexecutaram tudo do zero com
ceticismo, sem confiar nos resultados relatados nas rodadas de
`/01-implementacao`.

### Confirmacao da metodologia do teste (item 1)

Confirmado por leitura independente: `dispararComLogDedicado()` captura
stdout/stderr/arquivo de log em variaveis SEPARADAS via pipes distintos
+ `-d error_log=<path>`, nunca concatenados. Item 5 avalia os 3 canais
isoladamente (stdout rejeita `PDOException`/marcadores; stderr exigido
vazio; log permite so contexto fixo + `PDOException` + SQLSTATE
regex). `contemMarcadorSensivelComum()` proibe SQL/caminho/stack
trace/credencial/CPF/placa em qualquer canal. stderr nunca tratado
como resposta HTTP.

### Reexecucao integral (item 2)

`teste_rate_limit_identificar_cliente_pdo.php`: **24/24 PASSOU**.
`teste_integridade_conclusao_atendimento.php`: **43/43 PASSOU**.

### Prova negativa controlada, feita de forma independente (item 3)

`qa-testes` injetou seu proprio marcador (`MARCADOR_QA02B_STDOUT_LEAK`)
no stdout capturado -- resultado caiu para 22/24 (exatamente as 2
asserçoes de stdout do Item 5 falharam, demais canais permaneceram OK,
confirmando isolamento real). Injecao revertida, `git diff` confirmou
arquivo identico ao HEAD, reexecucao voltou a 24/24.

### Confirmacao dos 6 catches de PDOException (item 4)

Confirmado por 2 revisores independentes: zero uso de
`getMessage()`/`getTraceAsString()`/`getFile()`/`getLine()` nos 6
catches de `NotaController.php` (linhas 95, 163, 212, 277, 285, 318).
Mensagens HTTP fixas e genericas confirmadas (sem interpolacao de `$e`
ou dado de requisicao). `security-especialista` confirmou que o
SQLSTATE de `PDOException` nao e controlavel por input do
usuario/atacante em uso normal do PDO -- validacao por regex e
suficiente.

### Reconfirmacao dos contratos principais (item 5)

Todos confirmados intactos por 2 revisores independentes: 409 para
`concluido` com zero alteracao; estados `em_andamento`/`cancelado`/
`bloqueado` preservados; corrida real em `concluirDigitalizacao()`
produz exatamente 1 CAS vencedor + resposta idempotente; lock de
`DocumentoController` liberado (`IS_USED_LOCK` = NULL) em sucesso e
erro; falha de rate limit interrompe antes do OCR sem dupla
contabilizacao.

### Regressao (item 6)

24 suites adicionais reexecutadas -- 100% PASSOU (Recebimento,
Expedicao, Talent -- 9 suites --, impressao, ordem de coleta).
`teste_consulta_ordem_coleta.php` (12/17) isolado corretamente como
falha pre-existente e NAO relacionada, confirmado por `git diff --stat`
que nenhum arquivo desse fluxo foi tocado -- NAO e regressao desta
demanda. **CORRECAO DE REGISTRO 2026-09-17**: a causa relatada nesta
rodada ("banco externo ausente") estava IMPRECISA -- ver secao
"Correcao de registro -- banco de gestao de coletas esta disponivel
(2026-09-17)" ao final deste handoff para a causa real confirmada
(divergencia de fixture, nao indisponibilidade de conexao).

### Confirmacoes finais (item 7)

Zero residuo sintetico (`RTL0917`/`ITG`/`TESTE_INTEGRIDADE_` = 0
linhas em `tb_atendimento`/`tb_totem`). `git diff --check` limpo.
Nenhum segredo/dado pessoal no diff atual. Nenhum arquivo de producao
alterado nesta etapa. Zero chamada real ao Talent, zero impressao
real, zero alteracao de registro real. Zero commit, zero push.

### Achados de seguranca (security-especialista, revisor independente)

Sem achado critico ou de atencao. 2 observacoes registradas, nao
bloqueantes:
- Metodologia do script de teste depende de lista fechada de
  marcadores (nao ha checagem generica de padrao tipo `Fatal error:`/
  path absoluto) -- suficiente para o escopo desta demanda, registrado
  para avaliacao futura do proprio script de teste.
- Assimetria pre-existente: catches de `\Throwable`/`\RuntimeException`
  (distintos de `PDOException`) em `NotaController.php` (linhas 232,
  293, 297, 301) ainda usam `getMessage()` -- nunca vaza ao cliente
  (so vai para `error_log()` ou e comparado internamente), mas nao
  tem a mesma sanitizacao aplicada ao `PDOException`. Fora do escopo
  desta demanda, registrado como observacao distinta.

### Veredito consolidado

**APROVADO -- liberado para `/03-revisao`.** As 2 observacoes
registradas nao bloqueiam o fechamento desta demanda.


## Correcao de registro -- banco de gestao de coletas esta disponivel (2026-09-17)

O usuario reportou que o banco externo `udlogo59_db_gestao_coletas`
ESTA disponivel localmente (visivel via phpMyAdmin), contradizendo o
registro repetido nesta demanda de que `teste_consulta_ordem_coleta.php`
falhava por "banco externo ausente/indisponivel". Delegado ao
`explorer` para investigar sem alterar nada.

### Causa raiz real confirmada

NAO e falha de conectividade/ambiente. Confirmado por conexao PDO
direta e via `Util\ConexaoGestaoColetas::obter()` (a classe real do
projeto, nao uma conexao paralela): o MySQL local esta rodando, o
banco `udlogo59_db_gestao_coletas` existe, e a conexao com as
credenciais exatas do `.env` funciona (`host=localhost` e
`host=127.0.0.1`, ambas OK). `SHOW DATABASES` confirma o banco na
lista do mesmo servidor que o phpMyAdmin do usuario acessa.
`SELECT COUNT(*) FROM tb_ordens_coleta` via `ConexaoGestaoColetas`
retornou 10 linhas com sucesso.

A causa real das 5 falhas de `teste_consulta_ordem_coleta.php` e
DIVERGENCIA DE DADOS DE FIXTURE, nao ausencia de conexao:
- Nao existe hoje nenhuma linha com `numero_ordem_coleta = 'OC-TESTE-005'`
  nem `placa_prevista = 'TST0A01'` em `tb_ordens_coleta` -- o fixture
  que o script espera para o cenario "placa com 1 ordem" nao esta
  presente nesta copia do banco.
- Para a placa `ABC1D23` (cenario "multiplas ordens"), existem hoje 3
  linhas no total, mas so 2 com `status = 'ATIVA'`
  (`OC-TESTE-NOVA-003`, `OC-TESTE-NOVA-004`) -- a terceira
  (`OC-TESTE-001`) esta `status = 'INATIVA'` e e corretamente excluida
  da consulta pelo codigo de producao (comportamento correto). O
  script de teste esperava 3 ordens ativas, hoje encontra 2.
- `tb_clientes.id = 8` (AKRO-PLASTIC) existe e esta `ATIVO` -- so falta
  a ordem fixture `OC-TESTE-005` vinculada a ele.

`teste_consulta_ordem_coleta.php` reexecutado nesta investigacao:
12/17 (mesmo resultado das rodadas anteriores, confirmando
reprodutibilidade -- a causa e estavel, nao intermitente).

### Impacto nesta demanda

Nenhum. `git diff --stat` confirma que nenhum arquivo do fluxo de
ordem de coleta (`OrdemColetaClient.php`, `OrdemColetaDao.php`) foi
tocado por `integridade-conclusao-atendimento` em nenhuma rodada --
a falha e genuinamente pre-existente e nao relacionada, como ja
registrado, mas a EXPLICACAO da causa ("banco externo ausente") estava
incorreta e e corrigida aqui. Nao muda o veredito de nenhuma etapa
desta demanda (todas permanecem APROVADO/LIBERADO).

### Nao corrigido nesta rodada

Por instrucao explicita, apenas diagnostico -- nenhuma alteracao em
`.env`, codigo, ou dados do banco externo. Nao investigado quando/por
que o fixture `OC-TESTE-005` deixou de existir nesta copia local, nem
por que a ordem `OC-TESTE-001` mudou para `INATIVA` -- fica como
pendencia de higiene de fixture para quem mantiver
`teste_consulta_ordem_coleta.php` no futuro, fora do escopo desta
demanda.


## Resultado da revisao -- /03-revisao (2026-09-17)

Dois revisores independentes (backend-especialista + security-especialista,
sem participacao na implementacao) releram todo o codigo do zero, com
ceticismo total, sem confiar em nenhuma conclusao de rodada anterior.

### Escopo confirmado (git status/diff --stat, repositorio inteiro)

Unicos arquivos alterados/criados: `app/Controller/AtendimentoController.php`,
`app/Controller/DocumentoController.php`, `app/Controller/NotaController.php`,
`app/Dao/AtendimentoDao.php`, `app/Rn/AtendimentoRn.php`,
`ia_development_state.md`, `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`
(novo), `tests/manual/teste_integridade_conclusao_atendimento.php` (novo),
`tests/manual/teste_rate_limit_identificar_cliente_pdo.php` (novo). Os
`_caso_*.php` sao intencionalmente ignorados pelo git (`.gitignore`,
convencao pre-existente para scripts descartaveis) -- nao e desvio de
escopo. Nenhum outro arquivo do projeto foi tocado.

### Criterio 1 -- Estado terminal: SEM ACHADO

Confirmado por 2 revisores independentes, com varredura completa de
TODOS os metodos de `AtendimentoController.php` que escrevem em
`tb_atendimento` (nao so os 3 ja conhecidos): nenhum outro caminho real
da API reverte um atendimento `concluido`. `em_andamento`/`cancelado`/
`bloqueado` preservados.

### Criterio 2 -- CAS de conclusao: SEM ACHADO

CAS atomico confirmado, exatamente um vencedor em corrida real
(reexecucao independente de `teste_integridade_conclusao_atendimento.php`:
43/43 por AMBOS os revisores), resposta idempotente do perdedor sem
efeito colateral duplicado, conflito seguro para estado incompativel.

### Criterio 3 -- Fragilidade posicional de `etapaEhAlvoOuPosterior()`: PONTO MAIS ESCRUTINADO, SEM BYPASS REAL

Ambos os revisores fizeram varredura PROPRIA e completa de todo
escritor de `etapa_atual` em `tb_atendimento` (30+ ocorrencias
revisadas). Conclusao unanime: toda rota publica exige o valor EXATO
anterior da cadeia (nenhum `IN(...)` frouxo, nenhuma comparacao
"igual ou posterior" em nenhum ponto de ESCRITA -- `etapaEhAlvoOuPosterior()`
so e usado para decisao de resposta idempotente em LEITURA, nunca para
autorizar escrita). A origem `digitalizacao_notas` so e alcancavel a
partir de `'placa'` (valor inicial). **Nenhuma rota publica real
encontrada capaz de produzir o estado que este metodo trataria
incorretamente**. Classificacao mantida: observacao nao bloqueante --
defesa em profundidade recomendavel para o futuro (dado que
`etapa_atual` e `VARCHAR(40)` livre, sem `ENUM`), nao vulnerabilidade
ativa hoje.

### Criterio 4 -- Lock de documentos: SEM ACHADO

Liberacao do lock confirmada em TODOS os caminhos (sucesso, falha antes
e depois da aquisicao) por leitura de codigo E reexecucao dinamica
(`IS_USED_LOCK` = NULL confirmado apos ambos os cenarios de erro).
Chave do lock especifica por atendimento+tipo, sem colisao entre
execucoes. **Observacao nova, pre-existente e fora do escopo desta
demanda** (linha nao tocada pelo diff): `obterLock()` ignora seu
proprio valor de retorno -- se `GET_LOCK(...,0)` falhar por lock ja
detido, o codigo prossegue mesmo assim; o proprio comentario do codigo
ja documenta que a protecao real e o CAS de `tentativa_id`, nao esse
lock. Nao bloqueante, nao regressao desta demanda.

### Criterio 5 -- PDOException em NotaController: SEM ACHADO

Confirmado por 2 revisores: os 6 catches usam exclusivamente
`logFalhaBancoPdo()`, zero `getMessage()`/`getTraceAsString()`/
`getFile()`/`getLine()`, mensagens fixas, SQLSTATE validado por regex
que nao permite injecao de conteudo (SQLSTATE nao e controlavel pelo
atacante em uso normal do PDO). `identificarCliente()` protege
distintamente rate limit e logica principal.

### Criterio 6 -- Testes: CONFIRMADO INDEPENDENTEMENTE

Ambos os revisores reexecutaram e confirmaram: `teste_rate_limit_identificar_cliente_pdo.php`
= 24/24; `teste_integridade_conclusao_atendimento.php` = 43/43;
suites de regressao amostradas 100% OK. AMBOS os revisores fizeram
sua PROPRIA prova negativa independente (injecao de marcador sensivel
no stdout, revertida, `diff` confirmando reversao exata) -- cada uma
derrubou as 2 asercoes esperadas do Item 5, confirmando de forma
redundante que a metodologia detecta vazamento real.

**Observacao de ambiente registrada**: numa das reexecucoes de
`teste_consulta_ordem_coleta.php` nesta revisao, a causa observada foi
novamente falha de conexao ("Nenhuma conexao pode ser feita"),
diferente da causa "divergencia de fixture" confirmada na correcao de
registro de 2026-09-17 -- possivel flutuacao do servico MySQL externo
entre sessoes. Isso NAO contradiz a correcao de registro (o banco
local foi confirmado acessivel naquela investigacao especifica) nem
muda o veredito desta demanda: `git diff --stat` confirma que nenhum
arquivo do fluxo de ordem de coleta foi tocado por esta demanda em
nenhuma rodada -- a falha, seja qual for a causa exata a cada momento,
permanece generuinamente nao relacionada e fora do escopo.

### Criterio 7 -- Regressoes e seguranca: SEM ACHADO

Contratos de Recebimento/Expedicao/Talent/impressao/ordem de coleta
intactos. Nenhum segredo/dado pessoal real em nenhum arquivo novo.
`git diff --check` limpo.

### Observacoes conhecidas -- avaliadas por ambos os revisores

Todas confirmadas NAO BLOQUEANTES:
- Sanitizacao assimetrica de `\Throwable`/`\RuntimeException` --
  confirmado que nunca vaza ao cliente (so log/comparacao interna).
- Cobertura por lista fechada de marcadores no script de teste --
  aceitavel para o escopo, limitacao de metodologia de teste, nao de
  codigo de producao.
- Leitura duplicada no ramo idempotente -- leitura pura, sem efeito
  colateral.
- `etapaEhAlvoOuPosterior()` posicional -- ver Criterio 3 acima.

### Veredito consolidado

**APROVADO -- liberado para `/04-commit-e-push`.** Nenhum ponto
bloqueante encontrado por nenhum dos 2 revisores independentes. Todas
as observacoes permanecem nao bloqueantes, ja registradas em
`ia_development_state.md`.

## Trello

card_id: 6aabd13455e22411f07b0da4


## Commit

Commit funcional criado com sucesso:

- **Hash completo**: `a4b8e0af8b746309360bf44532d0f167297dd85f`
- **Mensagem**: `fix(atendimento): protege conclusao e sanitiza falhas de banco`
- **Autor/committer**: `Bruno Santos <brunossaantos@gmail.com>` (identidade
  local do repositorio, confirmada nos primeiros commits validos via
  `git log --reverse`), sem nenhuma atribuicao de IA em autor,
  committer, mensagem ou trailers.
- **Arquivos versionados** (9, exatamente os autorizados):
  `app/Dao/AtendimentoDao.php`, `app/Rn/AtendimentoRn.php`,
  `app/Controller/AtendimentoController.php`,
  `app/Controller/DocumentoController.php`,
  `app/Controller/NotaController.php`,
  `tests/manual/teste_integridade_conclusao_atendimento.php` (novo),
  `tests/manual/teste_rate_limit_identificar_cliente_pdo.php` (novo),
  `docs/handoffs/2026-09-16-integridade-conclusao-atendimento.md`
  (novo), `ia_development_state.md`.
- **Validacoes pre-commit**: `git fetch` sem divergencia (0/0 com
  `origin/main`); inspecao de higiene/segredos por
  `security-especialista` independente (LIBERADO PARA COMMIT --
  nenhum segredo, CPF/placa real, caminho de maquina, log/backup
  residual encontrado; `git diff --check`/`git diff --cached --check`
  limpos; `php -l` sem erro nos 7 arquivos PHP); reexecucao propria
  dos 2 testes obrigatorios: `teste_rate_limit_identificar_cliente_pdo.php`
  = 24/24, `teste_integridade_conclusao_atendimento.php` = 43/43.
- Zero chamada real ao Talent, zero impressao, zero alteracao de
  registro real durante esta etapa.

## Proximo passo

Registrar este hash em `ia_development_state.md` se necessario, criar
o segundo commit documental fechando este handoff, fazer push, e
atualizar o cartao Trello.
