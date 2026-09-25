# Handoff - sanitizacao-excecoes-lock-documentos

Data: 2026-09-20
Etapa: 00-planejamento

## O que foi pedido

Planejar (sem alterar codigo) uma rodada de hardening restrita a 3
pontos:

1. Sanitizar os catches (\Throwable) de DocumentoController.php que
   ainda logam $e->getMessage() em texto claro.
2. Remover detalhe cru de excecao dos logs de Throwable/RuntimeException
   em NotaController.php (nao de PDOException, ja sanitizado via
   logFalhaBancoPdo()).
3. Tratamento explicito do valor de retorno de
   DocumentoController::obterLock().
4. Organizar documentalmente, em secao 5.1 de ia_development_state.md,
   2 achados de baixa severidade ja conhecidos (nao corrigidos nesta
   demanda): redacao de 1 assercao em
   tests/manual/teste_rebaixamento_manual.php mais forte do que o
   que de fato verifica; comportamento de ehValorPlaceholder() com
   digito unico.

Investigacao com 3 sub-agentes independentes (backend-especialista,
security-especialista, explorer), nenhum alterando arquivo de
producao. Handoffs lidos: integridade-conclusao-atendimento
(2026-09-16), vio-hardening-sem-credenciais (2026-09-19),
robustez-rate-limit-migrations (2026-09-18).

## Investigacao - estado real confirmado (leitura direta de codigo)

### app/Controller/DocumentoController.php (547 linhas)

4 catches totais:

- L145-147 catch (\RuntimeException $e) em upload() -- nao loga
  getMessage(). Resposta fixa. Achado adicional do
  security-especialista: nao loga NADA (nem mensagem sanitizada) --
  lacuna de diagnostico, nao de vazamento. Fora do escopo pedido
  (pedido e sanitizar log existente, nao criar log novo onde nao
  existe) -- registrado como observacao, nao implementado.
- L233-240 catch (\Throwable $e) em iniciarProcessamento(),
  protegendo new VioDecodeClient():
  error_log('iniciar-processamento: falha ao inicializar VioDecodeClient: ' . $e->getMessage());
  -- loga RAW. Resposta ao cliente: mensagem fixa, HTTP 503.
- L243-252 catch (\Throwable $e) em iniciarProcessamento(),
  protegendo validarCnh()/validarCrlv():
  error_log('iniciar-processamento: falha nao prevista: ' . $e->getMessage());
  -- loga RAW. Resposta ao cliente: mensagem fixa, HTTP 500. Maior
  risco potencial de conteudo sensivel no log (CPF/placa), pois estas
  chamadas processam dado de CNH/CRLV -- nao confirmado se alguma
  excecao interna de DocumentoRn interpola esse dado na mensagem
  (fora do escopo de arquivos desta demanda, restrita aos 2
  Controllers).
- L421-424 catch (\Throwable $e) em preencherManual():
  error_log('preencher-manual: falha nao prevista: ' . $e->getMessage());
  -- loga RAW. Resposta ao cliente: mensagem fixa, HTTP 500. Mesmo
  risco potencial do item anterior (CPF/placa digitados manualmente),
  mesma ressalva de escopo.

obterLock()/liberarLock() (L511-531):

    private function obterLock(string $chave): bool
    {
        if ($this->pdo === null) {
            return true; // dependencia opcional nao injetada
        }
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:chave, 0)');
        $stmt->execute(['chave' => $chave]);
        return (bool) $stmt->fetchColumn();
    }

    private function liberarLock(string $chave): void
    {
        if ($this->pdo === null) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:chave)');
        $stmt->execute(['chave' => $chave]);
    }

Call site real (L213-217), ANTES do bloco try/finally que comeca em
L232:

    $chaveLock = "vio_validar_{$idAtendimento}_{$tipo}";
    $this->obterLock($chaveLock);

Achados confirmados:

- O retorno booleano de obterLock() e completamente descartado
  (instrucao solta, nunca atribuido/testado).
- GET_LOCK(nome, 0) (timeout 0) retorna 1 (obtido), 0 (ocupado por
  outra sessao) ou NULL (erro) -- o cast (bool) colapsa 0 e NULL no
  mesmo false, indistinguiveis nesse ponto.
- Achado novo desta rodada de planejamento, nao levantado pelos 3
  sub-agentes de forma explicita: como a chamada a obterLock() ocorre
  ANTES do try (L232) que teria um finally liberando o lock, uma
  \PDOException real dentro de obterLock() (ex.: conexao cai
  exatamente entre o CAS de iniciarProcessamento() e esta linha) nao
  e capturada por nada dentro deste metodo -- propaga integralmente
  para fora de iniciarProcessamento(). Confirmado por leitura de
  public/api/documento.php (L18-50): nao existe nenhum try/catch
  global envolvendo a chamada
  $controller->iniciarProcessamento($entrada, $idTotem); -- uma
  excecao nao capturada aqui vai direto ao handler padrao do PHP,
  cujo comportamento de exposicao (stack trace ao cliente) depende de
  display_errors do ambiente real (nao confirmado para o Hostgator de
  producao). Esta lacuna e mais grave que "o retorno e descartado"
  isoladamente -- e um caminho de excecao sem tratamento nenhum,
  inconsistente com o padrao cuidadoso do resto do arquivo.
- liberarLock() sempre roda no finally (L276-278) mesmo se o lock
  nunca tiver sido obtido de fato -- confirmado inofensivo:
  RELEASE_LOCK do MySQL sobre uma chave que a sessao atual nao detem
  retorna 0/NULL, nunca lanca erro.
- Nenhuma outra conexao PDO persiste entre requests
  (PDO::ATTR_PERSISTENT confirmado ausente em todo o projeto via
  grep) -- o lock nomeado vive e morre com a conexao do request
  atual; nao ha risco de lock "preso" entre requests diferentes.
- $this->pdo nunca e null em producao -- unico chamador real
  (public/api/documento.php L26) sempre injeta. O parametro
  ?PDO $pdo = null existe so para permitir testes sem banco.

Papel do CAS via tentativa_id -- confirmado por leitura direta do
DAO, nao presumido:

- AtendimentoDao::iniciarProcessamento() (L221-237): UPDATE
  tb_atendimento SET status='PROCESSANDO', tentativa_id=:tentativa,
  iniciado_em=NOW() WHERE id_atendimento=:id AND (status IN
  ('PENDENTE','ERRO') OR (status='PROCESSANDO' AND iniciado_em <
  NOW() - :timeout)), retorno via rowCount() > 0. Atomico por
  natureza do proprio UPDATE...WHERE do MySQL/InnoDB (lock de linha
  implicito) -- so uma chamada concorrente pode "vencer" a condicao.
- AtendimentoDao::gravarResultadoProcessamento() (L247-259): UPDATE
  ... WHERE id_atendimento=:id AND tentativa_id=:tentativa -- so
  grava se a tentativa ainda for a vigente (protege contra "resposta
  zumbi").
- Confirmado: duas chamadas concorrentes para o MESMO
  id_atendimento+tipo nao conseguem ambas passar pelo CAS de
  iniciarProcessamento() -- apenas uma chega a L217 (onde o GET_LOCK
  esta). O cenario "GET_LOCK ocupado" e, portanto, estruturalmente
  inatingivel hoje pelo fluxo real da API -- o GET_LOCK cobre um
  cenario ainda mais estreito e hipotetico (bug futuro, reentrancia,
  uso externo da mesma chave), exatamente como o comentario do
  proprio codigo (L213-215) ja afirma. Isso NAO foi presumido a
  partir do comentario -- foi confirmado lendo o DAO.

### app/Controller/NotaController.php (~400 linhas)

13 catches totais, 7 \PDOException (ja sanitizados via
logFalhaBancoPdo(), fora de escopo). 6 nao-PDO:

- L112-116 catch (\RuntimeException $e) em processar() (upload) --
  nao loga nada. Mesma observacao de lacuna do item 1 do
  DocumentoController, fora do escopo pedido.
- L118-127 catch (\Throwable $e) em processar() (processarLeitura())
  -- nao loga getMessage() (so @unlink() do arquivo). Sem achado.
- L226-234 catch (\Throwable $e) em identificarCliente():
  error_log('identificar-cliente: falha nao prevista: ' . $e->getMessage());
  -- loga RAW. Ja documentado como pendencia em secao 5.1 (linha
  ~791-799, achado do security-especialista em
  integridade-conclusao-atendimento, 2026-09-16). Resposta ao
  cliente: mensagem fixa, HTTP 500.
- L283-291 catch (\PDOException)/catch (\InvalidArgumentException)
  em definirNumero() -- ja sanitizado / sem getMessage(). Sem achado.
- L292-304 catch (\RuntimeException $e) em definirNumero() --
  nuance confirmada por leitura: $e->getMessage() ===
  'numero_nota_duplicado' (L293) e === 'nota_nao_encontrada' (L297)
  sao comparacoes SEGURAS contra strings literais controladas
  inteiramente pelo proprio codigo (App\Rn\NotaFiscalRn,
  presumivelmente throw new \RuntimeException('numero_nota_duplicado'))
  -- nao e vazamento, nunca compara contra dado externo, NAO deve ser
  alterado por esta demanda. So o ramo else final (L301):
  error_log('definir-numero: falha nao prevista: ' . $e->getMessage());
  -- loga RAW -- e o alvo real da sanitizacao aqui.
- verificarRateLimit() (L357-384) -- catch (\PDOException) relanca de
  proposito (preserva o 500 ja existente no chamador); catch
  (\Throwable) generico loga mensagem FIXA sem interpolar
  getMessage(), responde 503. JA e o padrao ouro de sanitizacao
  (demanda robustez-rate-limit-migrations, 2026-09-18) -- nenhuma
  mudanca necessaria aqui, serve de modelo/precedente para o resto.

## Confirmacao de ausencia de vazamento ao CLIENTE

Em nenhum dos 10 catches nao-PDO mapeados o cliente HTTP recebe
getMessage()/trace/caminho/SQL/dado pessoal -- todas as respostas sao
mensagens fixas via Resposta::erro(). O problema confirmado e
exclusivamente do lado do log de SERVIDOR (error_log()). Nao foi
confirmado (fora do escopo de arquivos desta demanda -- exigiria ler
public/api/*.php por completo em busca de um set_exception_handler
global, que nao existe hoje) se alguma excecao poderia escapar de
todo o pipeline antes de qualquer catch -- a unica lacuna real
confirmada desse tipo e a do obterLock() descrita acima, que e nova a
este planejamento.

## Estrategia de sanitizacao de log - decisao

Novo metodo privado, duplicado individualmente em cada controller
(nao um trait/classe compartilhada nova) -- decisao justificada por
simplicidade: sao so 5 pontos de uso ao todo, a logica e trivial (uma
linha), e o projeto ja tem precedente de metodo de log privado local
a cada controller (logFalhaBancoPdo() em NotaController, nunca
compartilhado). Se o usuario preferir DRY explicito entre os dois
controllers, a alternativa de baixo custo e um trait em
app/Controller/ -- nao adotada por falta de necessidade real dado o
tamanho pequeno do codigo duplicado.

    /**
     * Loga falha tecnica generica de forma minima e segura: nunca
     * inclui getMessage(), getTraceAsString(), getFile() ou getLine()
     * da excecao (podem conter SQL, payload, dado pessoal ou detalhe
     * de integracao externa). Registra so o contexto operacional
     * fixo (acao) + a classe concreta da excecao, suficiente para
     * diferenciar rapidamente o tipo de falha em debug futuro sem
     * vazar conteudo.
     */
    private function logFalhaTecnica(string $contexto, \Throwable $e): void
    {
        error_log($contexto . ': falha nao prevista [' . get_class($e) . ']');
    }

Uso nos 5 pontos (substituindo o error_log(...getMessage()) existente,
mantendo IDENTICAS as chamadas de gravarResultadoProcessamento()/
Resposta::erro() ao redor):

- DocumentoController.php L236:
  logFalhaTecnica("iniciar-processamento (inicializar VioDecodeClient) id_atendimento={$idAtendimento} tipo={$tipo}", $e)
- DocumentoController.php L248:
  logFalhaTecnica("iniciar-processamento id_atendimento={$idAtendimento} tipo={$tipo}", $e)
- DocumentoController.php L422:
  logFalhaTecnica("preencher-manual id_atendimento={$idAtendimento} tipo={$tipo}", $e)
  (usar as variaveis reais ja disponiveis no escopo do metodo, a
  confirmar nome exato em /01-implementacao)
- NotaController.php L232:
  logFalhaTecnica("identificar-cliente id_atendimento={$idAtendimento}", $e)
- NotaController.php L301 (so o ramo else final):
  logFalhaTecnica("definir-numero id_atendimento={$idAtendimento} ordem={$ordem}", $e)

IDs tecnicos (id_atendimento/tipo/ordem) inclusos no contexto seguem
exatamente o padrao ja sanitizado e aprovado existente em
DocumentoController.php L273 ("iniciar-processamento: resultado
descartado (tentativa obsoleta) id_atendimento={$idAtendimento}
tipo={$tipo}") -- nunca sao dado pessoal (CPF/placa/nome), sao apenas
identificadores internos do proprio banco.

get_class($e) nunca vaza dado sensivel -- e sempre um nome de classe
do proprio codigo-fonte (\RuntimeException,
App\Rn\DocumentoVioTipoInvalidoException, etc.), nunca dado de
payload/SQL/CPF.

Nenhuma mudanca de resposta HTTP e necessaria em nenhum dos 5 pontos
-- todas as respostas ao cliente ja sao mensagens/codigos fixos hoje;
a mudanca e exclusivamente no error_log() do lado servidor.

Pre-condicao a verificar em /01-implementacao (nao confirmada nesta
rodada por estar fora do escopo de arquivos, app/Rn/DocumentoRn.php e
app/Rn/NotaFiscalRn.php nao foram lidos por nenhum dos 3 sub-agentes
desta demanda): confirmar que nenhuma excecao interna lancada dentro
dessas classes RN usa CPF/placa/razao social/CNPJ como parte da
MENSAGEM da excecao de forma que dependa de continuar sendo logada
para diagnostico -- se alguma validacao interna hoje depende de
getMessage() carregar "qual motivo especifico falhou" (texto livre),
essa granularidade se perde com a sanitizacao. Mitigacao ja usada com
sucesso no proprio projeto (definirNumero(), L293/297): motivos de
negocio devem ser strings de CODIGO FIXO conhecidas
('numero_nota_duplicado'), nunca mensagem com interpolacao de dado de
usuario -- se o backend-especialista encontrar esse padrao ausente em
DocumentoRn/NotaFiscalRn ao implementar, pode reaproveitar a mesma
tecnica, mas isso so pode ser decidido lendo essas classes na proxima
etapa.

## Estrategia para obterLock()/GET_LOCK - decisao

Recomendacao consolidada (backend-especialista + security-especialista
+ orquestrador, convergentes): NAO introduzir um novo contrato HTTP
(409/423/503) para "lock nao adquirido".

Fundamentacao por precedente real (nao por preferencia):

- Nao ha nenhum precedente de HTTP 423 em todo o projeto (grep
  confirmado, zero ocorrencias em app/Controller/).
- HTTP 503 no projeto e usado exclusivamente para "dependencia/
  servico externo obrigatorio indisponivel" (bootstrap de banco,
  VioDecodeClient nao inicializou, feature flag do Talent
  desativada) -- nao se aplica, pois o GET_LOCK nao e uma dependencia
  externa, e um mecanismo interno redundante.
- HTTP 409 no projeto e usado para "estado do recurso conflita com a
  acao pedida" (nota duplicada, atendimento ja concluido, check-in ja
  em processamento) -- o cenario mais proximo semanticamente
  (AtendimentoController.php:675, "check-in ja em processamento"),
  mas nao se aplica aqui porque o cenario "lock ocupado" e
  estruturalmente inatingivel hoje (o CAS por tentativa_id ja
  garante que so uma chamada por id_atendimento+tipo chega a esta
  linha) -- introduzir uma resposta de erro para um caminho
  comprovadamente inalcancavel adicionaria uma superficie de falha
  nova (o front-end teria que tratar um HTTP que nunca ocorre na
  pratica) sem nenhum ganho real de protecao.

Decisao: tornar a decisao explicita em codigo, sem mudar o
comportamento observavel:

    $chaveLock = "vio_validar_{$idAtendimento}_{$tipo}";
    $adquiriuLock = false;
    try {
        $adquiriuLock = $this->obterLock($chaveLock);
    } catch (\PDOException $e) {
        // Fecha a lacuna confirmada nesta demanda: uma falha de banco
        // ao tentar o lock adicional (defesa em profundidade, nao a
        // protecao primaria) NAO deve propagar sem tratamento -- o
        // CAS por tentativa_id, ja vencido acima, e quem realmente
        // garante exclusividade. Loga de forma sanitizada e prossegue
        // exatamente como se o lock nao tivesse sido adquirido.
        $this->logFalhaTecnica("iniciar-processamento (obter lock adicional) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
    }
    if (!$adquiriuLock) {
        // Cenario teoricamente inatingivel hoje: iniciarProcessamento()
        // do AtendimentoDao ja garante exclusividade via UPDATE...WHERE
        // atomico (CAS por tentativa_id) antes deste ponto -- este
        // lock e defesa em profundidade, nao a protecao primaria.
        // Logado para investigacao caso ocorra (bug futuro/
        // reentrancia/chave reaproveitada), mas NAO interrompe o
        // processamento -- nao ha precedente no projeto de tratar
        // "lock ocupado" como erro ao cliente, e o CAS ja fecha essa
        // brecha estruturalmente.
        error_log("iniciar-processamento: lock adicional nao adquirido (defesa em profundidade, CAS ja garantiu exclusividade) id_atendimento={$idAtendimento} tipo={$tipo}");
    }

Isso resolve tanto o "retorno descartado silenciosamente" (agora e
uma decisao explicita e documentada) quanto a lacuna nova confirmada
("PDOException de obterLock() propagava sem tratamento nenhum") --
sem introduzir nenhum HTTP novo, sem mudar nenhum contrato existente,
e sem tornar o fluxo mais fragil (o comportamento observavel ao
cliente e identico a hoje em 100% dos casos reais).

Comportamento por valor de retorno do GET_LOCK (contrato documentado,
nao mudado no runtime observavel):

| Retorno GET_LOCK | Interpretacao | Acao |
|---|---|---|
| 1 | lock obtido | prossegue normalmente (igual a hoje) |
| 0 | ocupado por outra sessao (estruturalmente inatingivel hoje, dado o CAS) | loga e prossegue (igual a hoje na pratica, agora explicito) |
| NULL | erro do GET_LOCK em si (raro) | loga e prossegue (igual a hoje na pratica) |
| PDOException na query | falha de banco ao tentar o lock | NOVO: capturada localmente, loga sanitizado, prossegue (hoje propagava sem tratamento) |

liberarLock() continua sendo chamada incondicionalmente no finally --
confirmado inofensivo (RELEASE_LOCK sobre lock nao detido pela sessao
atual retorna 0/NULL, nunca lanca erro). Nenhuma mudanca proposta
aqui.

## Arquivos que precisarao mudar (/01-implementacao)

- app/Controller/DocumentoController.php: novo metodo privado
  logFalhaTecnica(); substituir os 3 error_log(...getMessage())
  (L236, L248, L422); capturar/tratar o retorno de obterLock() (L217)
  incluindo try/catch (\PDOException) local, conforme proposta acima.
- app/Controller/NotaController.php: novo metodo privado
  logFalhaTecnica(); substituir os 2 error_log(...getMessage())
  (L232, L301 -- so o ramo else, sem tocar nas comparacoes de string
  L293/L297).
- ia_development_state.md: registrar a nova demanda, atualizar as
  pendencias ja existentes (linhas ~791-799 e ~813-820 de secao 5.1)
  para refletir o planejamento desta rodada, adicionar os 2 achados de
  baixa severidade (teste_rebaixamento_manual.php,
  ehValorPlaceholder()) na secao "5. OBSERVACAO DE BAIXA SEVERIDADE".

Nenhum outro arquivo precisa mudar -- nenhuma alteracao em
app/Rn/DocumentoRn.php, app/Rn/NotaFiscalRn.php, VioDecodeClient.php,
schema/migrations, frontend, Trello.

## Contratos HTTP preservados (nenhuma mudanca)

Todos os codigos/mensagens HTTP atuais de DocumentoController.php
(400/404/500/503/429) e NotaController.php (400/404/409/429/500/503)
permanecem EXATAMENTE como estao hoje. Esta demanda muda apenas o
CONTEUDO do error_log() do servidor e adiciona tratamento explicito
(sem mudanca de comportamento observavel) ao redor de obterLock().

## Regras de negocio preservadas explicitamente

- NotaController::definirNumero() L293/L297 -- comparacao de
  $e->getMessage() contra 'numero_nota_duplicado'/
  'nota_nao_encontrada' NAO e alterada (e controle de fluxo seguro,
  nao vazamento).
- verificarRateLimit() (NotaController) NAO e alterada -- ja e o
  padrao ouro de sanitizacao, usado como modelo para o novo helper.
- Nenhuma mudanca no CAS de tentativa_id (AtendimentoDao).

## Riscos de regressao

Baixos -- mudanca puramente de log (sem efeito em resposta HTTP/
logica de negocio) mais uma captura de excecao adicional
(\PDOException de obterLock()) que hoje simplesmente nao existe
(logo, nao ha comportamento anterior a preservar nesse ponto
especifico, so a lacuna a fechar). Ponto de atencao para
/01-implementacao: ao capturar o retorno de obterLock() em variavel
local, garantir que NENHUM return/Resposta::erro() seja introduzido
no bloco condicional if (!$adquiriuLock) -- precisa continuar SEMPRE
prosseguindo, para nao regredir o fluxo. Testes ja existentes que
verificam ausencia de mensagem de excecao crua no log
(teste_integridade_conclusao_atendimento.php,
teste_rate_limit_identificar_cliente_pdo.php) precisarao ser
estendidos para cobrir os 5 pontos agora sanitizados.

## Rollback

Mudanca aditiva/substitutiva simples em 2 arquivos de controller --
sem migration, sem mudanca de schema, sem mudanca de assinatura de
metodo publico. Reverter e trocar de volta os error_log() para o
formato anterior e remover o try/catch novo ao redor de obterLock(),
sem efeito colateral.

## Plano de testes (para /02-testes, fixtures sinteticas/banco
descartavel, marcador exclusivo a definir na proxima etapa)

### Sanitizacao (5 pontos)

1-5. Para cada um dos 5 pontos sanitizados: excecao sintetica com
   marcadores (SQL, caminho absoluto, credencial, host, CPF, placa,
   conteudo de documento) disparada pelo caminho real de producao
   (via mock/subclasse injetada, sem alterar app/util/), com
   display_errors=1, log_errors=1, log direcionado a arquivo isolado.
   Confirmar ausencia dos marcadores em: resposta HTTP (corpo JSON),
   stdout, stderr, arquivo de log (usando pipes separados, nao um
   buffer combinado -- reaproveitar dispararComLogDedicado() ja
   existente de teste_rate_limit_identificar_cliente_pdo.php).
6. Prova negativa: reverter temporariamente 1 dos 5 pontos para o
   formato antigo (getMessage() cru), confirmar que a assercao do
   item correspondente FALHA (detecta vazamento real), reverter e
   confirmar hash MD5 identico.
7. Confirmar que os 2 usos seguros de getMessage() em definirNumero()
   (L293/L297, comparacao contra 'numero_nota_duplicado'/
   'nota_nao_encontrada') continuam funcionando identicamente (HTTP
   409/404 corretos) apos a mudanca.
8. Confirmar preservacao de todos os codigos/mensagens HTTP publicos
   atuais (400/404/409/429/500/503) em todos os pontos tocados.

### Lock

9. Lock adquirido (GET_LOCK retorna 1) -- fluxo identico ao atual.
10. Lock ocupado simulado (0) -- via segunda sessao MySQL real
    segurando a mesma chave nomeada antes da chamada -- confirmar que
    o processamento prossegue igual a hoje (comportamento inalterado),
    e que o novo log de observabilidade e emitido.
11. GET_LOCK retornando NULL (dificil de forcar realisticamente --
    documentar se nao for possivel simular com seguranca, sem
    inventar cenario artificial que nao reflita o real).
12. PDOException real dentro de obterLock() (ex.: KILL
    CONNECTION_ID() no momento exato, tecnica ja usada em
    teste_integridade_conclusao_atendimento.php) -- confirmar que a
    excecao e capturada, logada sanitizada, e o fluxo prossegue sem
    erro fatal ao cliente (comparar com o comportamento ANTES desta
    correcao, que propagava sem tratamento).
13. Duas requisicoes concorrentes reais (proc_open()) para o mesmo
    id_atendimento+tipo -- confirmar que o CAS por tentativa_id
    continua sendo o unico determinante de qual delas processa
    (comportamento identico ao ja validado em
    teste_concorrencia_real_iniciar_processamento.php), sem
    duplicacao.
14. RELEASE_LOCK confirmado via SELECT IS_USED_LOCK(...) retornando
    NULL apos qualquer um dos cenarios de erro acima (reaproveitar
    tecnica ja usada em teste_integridade_conclusao_atendimento.php).
15. Confirmar que processamento NUNCA e bloqueado/impedido pelo
    estado do GET_LOCK isoladamente (sempre prossegue, dado que o CAS
    ja e a protecao real).

### Regressoes obrigatorias

CNH e CRLV (fluxo automatico VIO e fallback manual); nota fiscal OCR
(identificarCliente, processar, definirNumero); Recebimento e
Expedicao completos; conclusao/cancelamento de atendimento; Talent;
impressao; validacao de JPEG; rate limit de OCR. Suites relevantes a
reexecutar sem alteracao esperada: teste_vio_decode.php,
teste_vio_decode_robustez.php,
teste_concorrencia_processamento_vio.php,
teste_concorrencia_real_iniciar_processamento.php,
teste_status_processamento.php,
teste_integridade_conclusao_atendimento.php,
teste_rebaixamento_manual.php,
teste_rate_limit_identificar_cliente_pdo.php,
teste_identificar_cliente.php,
teste_numero_nota_validacao_tamanho.php,
teste_concorrencia_numero_nota_duplicado.php,
teste_fluxo_recebimento_documentos.php,
teste_e2e_recebimento_expedicao_mock.php,
teste_validacao_jpeg_seguro.php, suites de Talent/impressao/ordem de
coleta ja usadas em rodadas anteriores.

## Decisao BLOQUEANTE para confirmacao do usuario antes de /01-implementacao

Nenhuma decisao tecnica ficou bloqueante -- os 3 sub-agentes
convergiram integralmente em ambas as estrategias (sanitizacao de
log e contrato do lock). Ha, porem, 2 pontos que dependem de
confirmacao/preferencia do usuario, nao tecnicamente bloqueantes para
iniciar /01-implementacao:

1. Confirmar que "logar e prosseguir, sem novo HTTP" e aceitavel para
   o cenario "lock nao adquirido" -- e a recomendacao tecnica
   fundamentada acima (dado que o cenario e estruturalmente
   inatingivel hoje, protegido pelo CAS), mas e uma escolha que o
   usuario pode preferir tratar diferente.
2. Confirmar que duplicar logFalhaTecnica() em cada controller (em
   vez de um trait/helper compartilhado novo) e aceitavel -- ambas as
   opcoes sao pequenas, mudam so o numero de arquivos tocados.

## Achados de baixa severidade organizados nesta rodada (documentacao
apenas, NAO corrigidos)

Ambos ja existiam como observacoes soltas no changelog de
ia_development_state.md (demanda vio-hardening-sem-credenciais) --
esta demanda apenas os consolida na secao "5. OBSERVACAO DE BAIXA
SEVERIDADE" de 5.1, sem alterar nenhum comportamento:

1. tests/manual/teste_rebaixamento_manual.php -- 1 assercao com
   redacao mais forte do que o que de fato verifica (identificado em
   /02-testes de vio-hardening-sem-credenciais, 2026-09-19). Sem
   perda de cobertura real confirmada.
2. ehValorPlaceholder() (em DocumentoRn.php) trata qualquer valor de
   digito unico como placeholder -- um exercicio legitimo de 1
   digito e teoricamente possivel, ainda que improvavel na pratica
   (identificado em /03-revisao final de
   vio-hardening-sem-credenciais, 2026-09-20).

## Sub-agentes envolvidos

- backend-especialista -- mapeamento de catches, proposta de
  logFalhaTecnica(), analise do contrato de obterLock()/CAS.
- security-especialista -- analise de risco de cada catch,
  confirmacao de ausencia de vazamento ao cliente, avaliacao de
  seguranca do lock descartado.
- explorer -- mapeamento de entrypoints, testes existentes, contratos
  HTTP atuais, precedentes de 409/423/503, confirmacao do mecanismo
  CAS via leitura direta do AtendimentoDao.

## O que NAO sera feito

- Nenhuma alteracao em app/Rn/DocumentoRn.php,
  app/Rn/NotaFiscalRn.php, VioDecodeClient.php, schema/migrations,
  frontend.
- Nenhuma extensao do tratamento de log sanitizado para outros
  controllers do projeto (observado, nao ampliado, conforme instrucao
  explicita do usuario).
- Nenhum novo log adicionado onde hoje nao existe nenhum
  (DocumentoController::upload() L145, NotaController::processar()
  L112-127) -- fora do escopo pedido (sanitizar log existente, nao
  criar log novo).
- Nenhuma correcao dos 2 achados de baixa severidade documentados
  acima (item 4 do pedido e so organizacional).
- Nenhum novo codigo HTTP (409/423/503) para o cenario de lock.
- Nenhuma acao no Trello (nao solicitada nesta demanda).
- Nenhum codigo alterado, nenhum banco tocado, nenhuma credencial
  usada, nenhuma chamada real a VIO/Talent/Serpro, nenhum
  commit/push nesta etapa.

## Resultado da implementacao (2026-09-20, /01-implementacao)

Implementado pelo `backend-especialista`, em 2 rodadas (rodada principal
+ rodada curta de correcao do ciclo de liberacao do lock, esta ultima
motivada por achado critico da propria rodada principal).

### Rodada principal -- itens 1, 2 e 3 do planejamento

Confirmado por leitura do codigo real antes de alterar (nenhuma
divergencia material do planejamento encontrada).

**`app/Controller/DocumentoController.php`**: novo metodo privado
`logFalhaTecnica(string $contexto, \Throwable $e): void`
(`error_log($contexto . ': falha nao prevista [' . get_class($e) . ']');`).
3 catches substituidos (linha antes -> depois):
- L236: `error_log('iniciar-processamento: falha ao inicializar VioDecodeClient: ' . $e->getMessage());`
  -> `$this->logFalhaTecnica("iniciar-processamento (inicializar VioDecodeClient) id_atendimento={$idAtendimento} tipo={$tipo}", $e);`
- L248: `error_log('iniciar-processamento: falha nao prevista: ' . $e->getMessage());`
  -> `$this->logFalhaTecnica("iniciar-processamento id_atendimento={$idAtendimento} tipo={$tipo}", $e);`
- L421: `error_log('preencher-manual: falha nao prevista: ' . $e->getMessage());`
  -> `$this->logFalhaTecnica("preencher-manual id_atendimento={$idAtendimento} tipo={$tipo}", $e);`

Nenhuma resposta HTTP/mensagem publica/codigo de status mudou em
nenhum dos 3 pontos (confirmado por teste).

**`app/Controller/NotaController.php`**: `logFalhaTecnica()` duplicado
(identico, decisao ja aprovada no planejamento). 2 pontos substituidos:
- L232 (`identificarCliente()`): `error_log('identificar-cliente: falha nao prevista: ' . $e->getMessage());`
  -> `$this->logFalhaTecnica("identificar-cliente id_atendimento={$idAtendimento}", $e);`
- L301 (SOMENTE o ramo `else` final de `definirNumero()`): `error_log('definir-numero: falha nao prevista: ' . $e->getMessage());`
  -> `$this->logFalhaTecnica("definir-numero id_atendimento={$idAtendimento} ordem={$ordem}", $e);`

As 2 comparacoes `$e->getMessage() === 'numero_nota_duplicado'`
(L293)/`'nota_nao_encontrada'` (L297) NAO foram tocadas -- confirmadas
funcionando identicamente (HTTP 409/404) por teste dedicado.
`verificarRateLimit()` e todos os catches de `\PDOException`
permanecem intocados.

### `obterLock()` -- tratamento explicito do retorno + `PDOException` na aquisicao

Implementado exatamente o contrato aprovado:

    $chaveLock = "vio_validar_{$idAtendimento}_{$tipo}";
    $lockAdquirido = false;
    try {
        $lockAdquirido = $this->obterLock($chaveLock);
    } catch (\PDOException $e) {
        $this->logFalhaTecnica("iniciar-processamento (obter lock adicional) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
    }
    if (!$lockAdquirido) {
        error_log("iniciar-processamento: lock adicional nao adquirido (defesa em profundidade, CAS ja garantiu exclusividade) id_atendimento={$idAtendimento} tipo={$tipo}");
    }

Nenhum `return`/`Resposta::erro()`/`exit` introduzido; nenhuma mudanca
de HTTP em nenhum cenario testado.

### Achado critico descoberto durante os testes da rodada principal (motivou a rodada curta seguinte)

Testando `\PDOException` real dentro de `obterLock()` (via `KILL
CONNECTION_ID()` na mesma conexao usada pelo lock -- tecnica ja usada
no projeto), confirmou-se que a chamada original e incondicional a
`liberarLock()` no `finally` reutilizava a MESMA conexao ja morta,
lancando uma SEGUNDA `\PDOException` nao capturada por nada, que
propagava para fora de `iniciarProcessamento()` sem tratamento --
exatamente o tipo de sintoma que esta demanda pretendia eliminar, so
que por uma linha diferente da analisada isoladamente no planejamento
original (que so cobriu a query de aquisicao, nao a de liberacao).
Investigacao confirmou que `public/api/documento.php` usa a MESMA
instancia PDO para `AtendimentoDao`, `DocumentoRn`/`VioCacheDao` e o
mecanismo de lock -- nao sao conexoes independentes.

### Rodada curta -- correcao do ciclo de liberacao do lock (decisao do usuario: opcao "a", restrita a `DocumentoController.php`)

Estado local explicito `$lockAdquirido` (renomeado de `$adquiriuLock`
por instrucao do usuario) so vira `true` quando `obterLock()`
confirma aquisicao real. O `finally` foi alterado:

    } finally {
        // So tenta liberar o lock se este processo de fato o adquiriu
        // ($lockAdquirido === true) -- nunca chama liberarLock() para uma
        // chave que nunca foi obtida por esta conexao.
        //
        // Envolvida em try/catch propria: se a conexao PDO morrer entre a
        // aquisicao do lock (acima) e este finally, RELEASE_LOCK pode
        // lancar uma PDOException aqui dentro. Isso NUNCA pode escapar
        // de iniciarProcessamento() sem tratamento -- por isso a
        // excecao e contida e so logada de forma sanitizada, sem nunca
        // sobrescrever $erroMensagem/$erroCodigoHttp ja definidos pelos
        // catches acima (a resposta HTTP emitida logo depois deste
        // try/finally continua refletindo exclusivamente a falha
        // principal, se houver, nunca a falha de liberacao do lock).
        //
        // Nao ha reconexao/retry aqui: quando o MySQL/MariaDB encerra a
        // conexao (por qualquer motivo), o proprio servidor libera todos
        // os named locks pertencentes aquela sessao automaticamente --
        // ou seja, mesmo que RELEASE_LOCK falhe por a conexao ja estar
        // morta, o lock em si ja NAO esta mais "preso" no servidor. Esta
        // protecao existe so para conter a excecao, nunca para evitar um
        // lock realmente preso.
        if ($lockAdquirido) {
            try {
                $this->liberarLock($chaveLock);
            } catch (\PDOException $e) {
                $this->logFalhaTecnica("iniciar-processamento (liberar lock adicional) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
            }
        }
    }

### Comportamento confirmado por estado do lock (evidencia real de teste, nao so leitura de codigo)

| Estado | `$lockAdquirido` | `liberarLock()` chamada? | Excecao escapa de `iniciarProcessamento()`? | HTTP |
|---|---|---|---|---|
| Adquirido (`GET_LOCK`=1) | `true` | sim, normalmente | nao | inalterado |
| Nao adquirido (`GET_LOCK`=0) | `false` | nao | nao | inalterado |
| `NULL` (`GET_LOCK`->NULL) | `false` (mesmo caminho de "nao adquirido", via `(bool)` cast) | nao | nao | inalterado |
| `PDOException` na aquisicao | `false` | nao | nao | inalterado |
| Conexao morre APOS aquisicao (liberacao falha) | `true` | sim, mas a `PDOException` da liberacao e capturada e logada localmente | nao | inalterado -- falha principal (se houver) preservada, nunca mascarada pela falha de liberacao |

Cenario `NULL` nao simulado isoladamente (mesma decisao ja registrada
no planejamento -- `(bool) $stmt->fetchColumn()` colapsa `0`/`NULL` no
mesmo `false`, seguindo o mesmo caminho de "nao adquirido").

### Evidencia de que o CAS continua sendo a protecao efetiva

`teste_concorrencia_real_iniciar_processamento.php` (2 execucoes reais
via `proc_open()` para o mesmo `id_atendimento`+`tipo`) reexecutado
sem alteracao: 2/2 -- confirma que so uma chamada processa, a outra
recebe resposta idempotente, sem duplicacao, independente do estado
do lock adicional (a exclusividade continua vindo exclusivamente do
CAS por `tentativa_id` em `AtendimentoDao`, nao tocado nesta demanda).

### Arquivos de teste (`tests/manual/`)

Versionados (rastreados no git): `teste_sanitizacao_logs_documento_nota.php`
(29 testes), `teste_lock_obter_lock_documento.php` (ampliado para 25
testes, cobrindo lock adquirido/nao adquirido/`NULL`/`PDOException` na
aquisicao/conexao perdida apos aquisicao/`PDOException` na
liberacao/prova negativa automatizada). `tests/manual/teste_identificar_cliente.php`
teve 1 asserção pre-existente corrigida (Caso 15 esperava o
comportamento ANTIGO nao sanitizado -- atualizada para refletir o novo
log sanitizado, comportamento documentado no proprio comentario do
teste).

Nao versionados (convencao pre-existente do projeto, `.gitignore`
linha 37, `tests/manual/_*.php`): `_mocks_sanitizacao_logs.php`,
`_caso_iniciar_processamento_marcador.php`,
`_caso_preencher_manual_marcador.php`,
`_caso_identificar_cliente_marcador.php`,
`_caso_definir_numero_marcador.php`,
`_caso_iniciar_processamento_pdo_lock_falha.php` (script de
investigacao do achado critico), `_spy_lock_pdo.php` (novo, spy real
de `GET_LOCK`/`RELEASE_LOCK` via `PDO::ATTR_STATEMENT_CLASS`),
`_caso_iniciar_processamento_lock_falha_aquisicao_sem_rede.php` (novo),
`_caso_iniciar_processamento_lock_perdido_apos_aquisicao.php` (novo,
cenario central da correcao).

### Prova negativa (sanitizacao de logs)

Reversao temporaria de 1 dos 5 pontos sanitizados para `getMessage()`
cru -- a suite correspondente DETECTOU o vazamento (assercao falhou),
revertido de volta com hash MD5 identico ao estado pretendido antes/
depois.

### Prova negativa (ciclo do lock)

Automatizada dentro de `teste_lock_obter_lock_documento.php`: le o
arquivo de producao, substitui o guard/`try/catch` novo pela chamada
incondicional antiga (`$this->liberarLock($chaveLock);`), executa o
cenario "conexao perdida apos aquisicao", confirma que a `PDOException`
agora ESCAPA de `iniciarProcessamento()` (deteccao real da regressao),
reverte via `file_put_contents()` do conteudo original, confirma
`md5sum` identico ao baseline (`9eaf33dbeec39a11a4885170c89a7fc2`)
antes e depois. Prova negativa manual adicional feita pelo proprio
`backend-especialista` fora do script (edicao/reversao direta do
arquivo), com o mesmo resultado.

### Regressao completa (contagens exatas, ambas as rodadas)

| Suite | Resultado |
|---|---|
| teste_sanitizacao_logs_documento_nota.php | 29/29 |
| teste_lock_obter_lock_documento.php | 25/25 |
| teste_identificar_cliente.php | 36/36 |
| teste_vio_decode.php | 21/21 |
| teste_vio_decode_robustez.php | 80/80 |
| teste_concorrencia_processamento_vio.php | 16/16 |
| teste_concorrencia_real_iniciar_processamento.php | 2/2 |
| teste_status_processamento.php | 9/9 |
| teste_integridade_conclusao_atendimento.php | 43/43 |
| teste_rebaixamento_manual.php | 47/47 |
| teste_rate_limit_identificar_cliente_pdo.php | 24/24 |
| teste_numero_nota_validacao_tamanho.php | 32/32 |
| teste_concorrencia_numero_nota_duplicado.php | 5/5 |
| teste_fluxo_recebimento_documentos.php | 11/11 |
| teste_e2e_recebimento_expedicao_mock.php | 30/30 |
| teste_validacao_jpeg_seguro.php | 22/22 |
| teste_talent_anexos_pdf.php | 19/19 |
| teste_talent_uf_crlv.php | 11/11 |
| teste_talent_rntc_tipo_crlv.php | 23/23 |
| teste_talent_idempotencia.php | 23/23 |
| teste_talent_log_sanitizado.php | 34/34 |
| teste_talent_idor_finalizar.php | 10/10 |
| teste_talent_trava_doctos_pendente.php | 18/18 |
| teste_talent_payload.php | 45/45 |
| teste_talent_client_parsing.php | 9/9 |
| teste_impressao_idor.php | 12/12 |
| teste_ordem_coleta_pendente_baixa.php | 14/14 |
| teste_avancar_etapa_expedicao.php | 10/10 |
| teste_concorrencia_finalizar_checkin.php | 8/8 |
| teste_preparacao_producao_checkin.php | OK (sem contador numerico) |
| teste_salvar_etapa_cliente_manual.php | 3/3 |
| teste_idor_salvar_etapa_manual.php | 15/15 |

`teste_consulta_ordem_coleta.php`: 12/17, 5 falhas PRE-EXISTENTES e
NAO relacionadas (banco externo `gestao_coletas` indisponivel neste
ambiente local -- ja documentado em rodadas anteriores).

Nenhum codigo/mensagem HTTP mudou em nenhuma suite (400/404/429/500/
503 identicos aos anteriores).

### Residuos e incidentes durante a implementacao

Um incidente de debug (sobrescrita acidental temporaria de
`DocumentoController.php` inteiro por uma versao crua, durante `php -r`
manual do `backend-especialista` na rodada principal) foi detectado via
`git diff`, revertido a partir do indice git, e a correcao foi
reaplicada -- confirmado por `git diff` final que o arquivo ficou
identico ao estado pretendido. 2 registros de teste orfaos
(`DEBUGSANIT`/id 1648, `SANDBG1`/id 9024) ficaram no banco de dev
durante o incidente -- ja removidos manualmente, confirmado 0 residuo.
Nenhum outro residuo de banco/arquivo de log encontrado em nenhuma das
2 rodadas (confirmado por `COUNT(*)` com os marcadores sinteticos
usados em cada rodada, sempre 0 apos a limpeza).

### Achado NOVO fora do escopo desta demanda (nao corrigido, registrado como observacao)

Ao reexecutar `teste_concorrencia_real_iniciar_processamento.php`
(suite PRE-EXISTENTE, nao criada nem alterada por esta demanda), a
saida mostrou a mensagem sanitizada de falha tecnica do
`VioDecodeClient` (`"Nao foi possivel validar o documento junto ao
servico externo. Preencha manualmente."`), indicando que o `.env` de
desenvolvimento local tem `VIO_AMBIENTE=trial` com `VIO_TRIAL_BEARER`/
`VIO_TRIAL_DECODE_URL` configurados (`VIO_CONSUMER_KEY`/
`VIO_CONSUMER_SECRET` continuam vazios, consistente com a pendencia ja
documentada de "credenciais VIO Trial nao obtidas" via OAuth2 -- mas o
bearer estatico de trial parece estar configurado). Essa suite
PRE-EXISTENTE (de uma demanda anterior a esta) nao neutraliza
`VIO_AMBIENTE` antes de chamar `iniciarProcessamento()` com bytes de QR
sinteticos -- diferente dos testes NOVOS desta demanda, que explicitamente
fazem `unset($_ENV['VIO_AMBIENTE'])`/`putenv('VIO_AMBIENTE')` para
garantir zero chamada de rede real. Confirmado por teste de
conectividade generico do orquestrador que este ambiente TEM acesso
de saida a internet -- ou seja, nao e possivel descartar que essa suite
pre-existente esteja de fato tentando uma chamada de rede real ao
endpoint de trial da VIO Decode (com bytes de QR sinteticos/invalidos,
que a VIO rejeitaria de qualquer forma, gerando a mesma mensagem
generica de falha tecnica que um erro de rede/timeout tambem geraria --
nao e possivel distinguir as duas causas so pela mensagem sanitizada,
que e exatamente o comportamento correto e esperado da sanitizacao ja
implementada na demanda `vio-hardening-sem-credenciais`). Nao
investigado mais a fundo nem corrigido nesta demanda -- fora do escopo
autorizado (o arquivo pre-existente nao foi tocado). Registrado como
observacao para avaliacao futura: suites de teste pre-existentes que
usam o fluxo real de `iniciarProcessamento()` sem neutralizar
`VIO_AMBIENTE` deveriam adotar o mesmo padrao de isolamento de rede ja
usado pelos testes novos desta demanda.

### Nova pendencia registrada (NAO corrigida nesta demanda) -- `robustez-falha-conexao-processamento-documentos`

Descoberta durante os testes desta demanda (achado critico da rodada
principal, ver acima). Registrada tambem em `ia_development_state.md`
secao 5.1. Escopo da pendencia, para avaliacao em demanda futura
dedicada:

- `public/api/documento.php` constroi `AtendimentoDao`, `DocumentoRn`/
  `VioCacheDao` e o mecanismo de lock de `DocumentoController`
  compartilhando a MESMA instancia PDO (`Bootstrap::conectar()`), nao
  conexoes independentes.
- Se essa conexao INTEIRA morrer (nao so a query pontual do lock, ja
  protegida por esta demanda), qualquer operacao de persistencia
  POSTERIOR na mesma requisicao -- incluindo
  `AtendimentoDao::gravarResultadoProcessamento()`, chamada SEM
  protecao adicional dentro dos catches internos de
  `iniciarProcessamento()` -- tambem pode lancar `\PDOException` nao
  capturada, propagando sem tratamento.
- Isso e PRE-EXISTENTE e mais amplo que o ciclo do lock corrigido nesta
  demanda -- nao introduzido por esta correcao, e nao fechado por ela
  (o escopo desta demanda foi deliberadamente restrito so ao ciclo
  `obterLock()`/`liberarLock()`).
- A futura demanda dedicada devera avaliar: uma fronteira sanitizada
  para falha TOTAL de conexao (nao so de uma query isolada); o que
  acontece com o estado `PROCESSANDO` de `tb_atendimento` quando a
  conexao morre no meio do processamento (hoje so recuperado por
  timeout, via a mesma logica de "tentativa obsoleta" ja existente em
  `AtendimentoDao::iniciarProcessamento()`); necessidade ou nao de
  algum mecanismo de auditoria desse cenario; garantia de ausencia de
  persistencia parcial.
- **Nenhuma reconexao ou retry automatico deve ser implementado sem
  planejamento proprio** -- essa restricao e explicita e deve ser
  preservada em qualquer demanda futura que trate desta pendencia.
- Nao alterar `AtendimentoDao.php` nesta demanda (restricao respeitada
  integralmente).

## Confirmacao final de fechamento (2026-09-20)

`git diff --check`: sem erro (warnings de CRLF/LF sao conversao
cosmetica de fim de linha do Git no Windows, pre-existente em todo o
repositorio, nao um problema real). Arquivos de producao alterados:
exatamente `app/Controller/DocumentoController.php` e
`app/Controller/NotaController.php` (nenhum outro). Arquivos de teste
alterados/criados: dentro de `tests/manual/`, conforme listado acima.
`ia_development_state.md` e este handoff atualizados. `TrelloClient.php`/
`tools/trello-cli.php` nao tocados. Nenhuma acao no Trello realizada
nesta demanda (nao solicitada). Zero credencial real usada pela
implementacao/testes desta demanda (a suite pre-existente com possivel
chamada de rede real e um achado registrado, nao uma acao desta
demanda). Zero chamada real a Talent/Serpro. Zero documento/CPF/placa/
QR real. Zero impressao. Zero acesso a producao/Hostgator. Zero
commit/push.

**Veredito: todos os testes desta demanda passaram (25/25 lock, 29/29
sanitizacao, 36/36 identificar-cliente, todas as regressoes listadas
sem queda alem da pre-existente e documentada de
`teste_consulta_ordem_coleta.php`), nenhuma excecao do ciclo do lock
escapou de `iniciarProcessamento()` em nenhum cenario testado (incluindo
com prova negativa automatizada confirmando deteccao real caso a
protecao seja removida). Demanda LIBERADA para `/02-testes`
independente.**

## Resultado de `/02-testes` independente (2026-09-24)

3 revisores independentes, nenhum participante da implementacao,
instruidos a nao confiar nos resultados relatados e produzir evidencia
propria: `qa-testes`, `security-especialista`, `backend-especialista`.
Nenhum alterou codigo de producao nesta etapa (a unica mutacao
temporaria em cada revisor foi a prova negativa, sempre revertida com
hash MD5 identico confirmado).

### `qa-testes` -- veredito parcial: PRECISA DE AJUSTE (achado BLOQUEANTE)

**Achado bloqueante -- reprodutibilidade dos 2 testes novos em arvore
limpa.** Os 2 testes versionados desta demanda
(`teste_sanitizacao_logs_documento_nota.php`,
`teste_lock_obter_lock_documento.php`) dependem de helpers
`tests/manual/_*.php` NUNCA versionados no git (`.gitignore:37
tests/manual/_*.php`), diferente de 2 helpers pre-existentes de
demandas anteriores que ja foram force-adicionados
(`_fixtures_talent.php`/`_fixtures_identificar_cliente.php`, em
`git ls-files`) -- os helpers NOVOS desta demanda
(`_mocks_sanitizacao_logs.php`, `_spy_lock_pdo.php`, e os `_caso_*.php`
que eles referenciam via `__DIR__`) nunca foram force-adicionados.

Prova real (nao so leitura de `.gitignore`): o revisor montou uma
arvore hermetica via `git archive HEAD` em diretorio temporario,
copiou por cima os arquivos que estariam presentes pos-
`/04-commit-e-push` (os 2 controllers modificados, os 2 testes novos,
`teste_identificar_cliente.php`) SEM copiar nenhum helper `_*.php`
novo, executou os 2 testes a partir de um diretorio corrente diferente
(`__DIR__` confirmado correto, nao e problema de cwd). Resultado:
**ambos os testes falham com `Fatal error: Uncaught Error: Failed
opening required` para os helpers ausentes** -- erro fatal, o script
nem chega a rodar.

Conclusao do revisor: se commitado hoje, `/04-commit-e-push` deixaria
os 2 novos testes IRREPRODUZIVEIS para qualquer pessoa/CI que clone o
repositorio do zero -- so funcionam hoje porque os helpers existem
fisicamente no worktree local de quem implementou, nao porque estao no
git. `git add -f` nao e uma solucao aceitavel sem decisao explicita do
usuario (estenderia, sem autorizacao, a excecao ja existente de 2
helpers pre-existentes force-adicionados em demanda anterior).
Alternativas levantadas pelo revisor, nenhuma decidida: (a)
force-adicionar os novos helpers (mesmo padrao ja usado 2x antes no
projeto); (b) inlinar o conteudo dos helpers nos 2 testes versionados;
(c) aceitar formalmente que esses 2 testes sao "so reproduziveis
localmente".

**Restante do escopo do `qa-testes`: sem achado, tudo confirmado com
evidencia propria e independente**, batendo 100% com o handoff:
- Ciclo do lock (script proprio, banco descartavel `qa02sanit_lock`,
  nunca banco de dev, removido ao final, confirmado 0 residuo):
  cenarios 1 (adquirido), 2 (ocupado via 2a sessao real), 4
  (`PDOException` na aquisicao via `KILL` da PROPRIA conexao do
  teste), 5 (conexao morre apos aquisicao, `IS_USED_LOCK` confirma
  liberacao automatica pelo servidor, nova query na mesma conexao
  morta lanca `PDOException` real) reproduzidos com evidencia real.
  Cenario 3 (`GET_LOCK` retornando `NULL`) nao reproduzido com
  seguranca (mesma conclusao ja registrada no planejamento -- nao
  inventado). Cenarios 6-9 cobertos por leitura de codigo + reexecucao
  da suite versionada (25/25).
- Prova negativa: reexecutou a prova negativa automatizada JA embutida
  em `teste_lock_obter_lock_documento.php`, confirmou por si mesmo
  (`md5sum` antes/depois) que a reversao automatica funciona e nao
  deixa o arquivo de producao alterado (`9eaf33dbeec39a11a4885170c89a7fc2`,
  identico ao baseline do handoff).
- Regressao completa reexecutada pelo proprio revisor, TODAS as 31
  suites batendo exatamente com as contagens do handoff (29, 25, 36,
  21, 80, 16, 9, 43, 47, 24, 32, 5, 11, 30, 22, 19, 11, 23, 23, 34, 10,
  18, 45, 9, 12, 14, 10, 8, OK, 3, 15).
- `teste_consulta_ordem_coleta.php`: reexecutado e confirmado
  INDEPENDENTEMENTE (nao aceito por alegacao) -- 12/17, as 5 falhas com
  evidencia direta de `SQLSTATE[HY000][2002]` (banco externo
  `gestao_coletas` recusando conexao), confirmado pre-existente e nao
  relacionado.
- `teste_concorrencia_real_iniciar_processamento.php`: NAO executado,
  conforme instrucao. Confirmado por `grep` que o arquivo NAO
  neutraliza `VIO_AMBIENTE`, corroborando o achado ja registrado.
- Zero chamada externa, zero credencial real, zero residuo de banco
  (incluindo limpeza de um residuo vazio pre-existente de OUTRA
  sessao, `qa02sanit_70af6a65`, sem tabelas, fora do escopo vetado
  `qa013_*`/`qa_iso2`), zero commit/push.
- Observacao nao bloqueante: `teste_preparacao_producao_checkin.php`
  deixa intencionalmente 1 registro pre-existente "aguardando exclusao"
  -- comportamento documentado do proprio teste, pre-existente, nao
  desta rodada.

### `security-especialista` -- veredito parcial: APROVADO

Bateria propria independente de marcadores sinteticos exclusivos
(`QA02SEC_*`, distintos dos ja usados na implementacao), disparada via
subclasses de `DocumentoRn`/`NotaFiscalRn` (sem alterar `app/`), pipes
separados stdout/stderr, log dedicado por execucao. **36/36 passaram**
apos o proprio revisor corrigir um bug no SEU proprio script de teste
(caminho relativo incorreto, nao um problema de producao). Zero
ocorrencia de qualquer marcador em qualquer um dos 4 canais nos pontos
2-5; log confirmado contendo somente contexto fixo + `[RuntimeException]`.

**Ponto 1 (`new VioDecodeClient()`)**: nao injetavel com marcador
completo sem alterar producao (instanciado diretamente, sem DI nesse
ponto especifico) -- compensado por 2 evidencias: leitura de
`VioDecodeClient.php` confirmando que as 3 unicas mensagens possiveis
do construtor sao strings LITERAIS FIXAS (nunca interpolam
`$env`/dado de usuario, logo estruturalmente nunca podem carregar
SQL/caminho/host/credencial/token/CPF/placa/documento); e teste
confirmando que a mensagem fixa real nao aparece crua no log.

Prova negativa propria (distinta da ja feita pela implementacao):
mutacao do ponto 5 (`NotaController.php`) de volta para `getMessage()`
cru -- detectado pela sua suite; revertido via `file_put_contents()`,
hash MD5 identico antes/depois confirmado.

Comparacoes literais (`numero_nota_duplicado`/`nota_nao_encontrada`)
confirmadas preservadas (409/404). `verificarRateLimit()` e todos os
catches de `PDOException` confirmados intocados via `git diff`.

Seguranca contra chamadas externas: NAO executou
`teste_concorrencia_real_iniciar_processamento.php`; confirmou de
forma independente (leitura do `.env`, sem revelar valores) que
`VIO_AMBIENTE=trial` com bearer/URL de trial configurados e
consumer key/secret vazios -- corrobora a analise de risco ja
registrada (nao corrigido, fora do escopo). Todos os testes proprios
do revisor usaram exclusivamente mocks/subclasses que lancam excecao
ANTES de qualquer chamada de rede -- confirmado por leitura de codigo
que nenhum host externo foi referenciado.

Escopo de arquivos confirmado limpo (`git status`/`git diff --stat`
identicos antes/depois da revisao, unica mutacao -- a prova negativa
-- revertida com hash identico). `AtendimentoDao.php`, `DocumentoRn.php`,
`NotaFiscalRn.php`, `VioDecodeClient.php`, `TrelloClient.php`,
`tools/trello-cli.php`, schema/migrations, `public/api/*.php`
confirmados intocados. Ambas as pendencias documentadas
(`robustez-falha-conexao-processamento-documentos` e o risco de
`VIO_AMBIENTE`) confirmadas registradas corretamente em
`ia_development_state.md`.

**Observacao nao bloqueante, fora do escopo desta demanda**:
`docs/indexTotem.html` e `tests/nf_teste/` aparecem como untracked no
`git status`, nao mencionados no handoff nem criados por esta demanda
-- o orquestrador confirmou (2026-09-24) que ja existiam untracked
antes desta revisao, nao introduzidos por nenhuma etapa desta demanda.
Registrado para atencao antes de qualquer commit futuro (de QUALQUER
demanda), nao investigado a fundo (fora de escopo).

### `backend-especialista` -- veredito parcial: APROVADO

Leitura linha a linha de `DocumentoController.php` (619 linhas) e
`NotaController.php` (414 linhas) inteiros. Confirmado sem divergencia
do handoff: `logFalhaTecnica()` identica nos 2 controllers, nunca
acessa `getMessage()`/trace/`getFile()`/`getLine()`; os 5 pontos usam
contexto fixo + so IDs seguros; as 2 comparacoes literais preservadas
antes do ramo `else` sanitizado; `verificarRateLimit()`/catches de
`PDOException` intocados.

Estrutura do ciclo do lock confirmada exatamente como descrita:
`$lockAdquirido` so `true` dentro do `try` de `obterLock()`; guard
`if (!$lockAdquirido)` sem `return`/`exit`; `finally` com guard FORA
do try, catch de `liberarLock()` sem atribuir
`$erroMensagem`/`$erroCodigoHttp`, sem `throw`/`return` interno;
`obterLock()`/`liberarLock()` usam exclusivamente `$this->pdo`, zero
nova conexao; zero transacao/`SELECT FOR UPDATE`/retry/`sleep()`.

Testes reais proprios (banco descartavel `qa02bkd_lock`, nunca banco
de dev, removido ao final, `DROP DATABASE`, 24/24 passaram): caminho
feliz com `IS_USED_LOCK`; `PDOException` na aquisicao (conexao PROPRIA
do teste morta via `KILL` antes do `GET_LOCK`, spy confirma
`RELEASE_LOCK_CHAMADAS:0`); conexao morre apos aquisicao (spy
`PDOStatement` customizado, `PDOException` de `liberarLock()` contida
e logada, nenhuma excecao escapou, confirmado via subprocesso real +
`register_shutdown_function`); **evidencia real (nao documentacao) de
que o MySQL/MariaDB libera o named lock automaticamente ao matar a
conexao dona** (`IS_USED_LOCK` volta a `NULL` sem `RELEASE_LOCK`
explicito).

Prova negativa propria: guard/try-catch removido programaticamente,
excecao volta a ESCAPAR (confirmando que o teste exercita a protecao
real), revertido via `file_put_contents()`, hash MD5 pos-reversao
`9eaf33dbeec39a11a4885170c89a7fc2` -- identico ao baseline documentado
no handoff.

CAS por `tentativa_id`: `AtendimentoDao::iniciarProcessamento()`/
`gravarResultadoProcessamento()` confirmados intocados desde a demanda
anterior. Teste de concorrencia real proprio via `proc_open()` (2
processos reais, `AtendimentoDao` direto sem VIO/rede, `sleep` real
alargando a janela de corrida para forcar overlap genuino -- achado
metodologico do proprio revisor, documentado como nuance, nao como bug):
`PERDEDOR_ADQUIRIU:0`, `VENCEDOR_GRAVOU:1`, status final `CONCLUIDO`
sem duplicacao, CAS confirmado como protecao efetiva independente do
estado do lock adicional.

Nenhuma divergencia material entre o handoff e o codigo real
encontrada. Nenhum achado bloqueante.

### Veredito consolidado

**PRECISA DE AJUSTE**, exclusivamente pelo achado bloqueante do
`qa-testes` (reprodutibilidade em arvore limpa dos 2 testes novos --
helpers indispensaveis nunca versionados). Toda a logica funcional
desta demanda (sanitizacao dos 5 pontos, ciclo do lock, CAS,
contratos HTTP, ausencia de chamada externa, ausencia de residuo)
foi confirmada de forma INDEPENDENTE por 3 revisores distintos, com
evidencia real propria (nao aceitando os resultados relatados pela
implementacao), sem nenhum achado tecnico/funcional contrario ao que
foi implementado. O bloqueio e estritamente sobre versionamento/
reprodutibilidade dos artefatos de teste, nao sobre a correcao em si.

Decisao necessaria antes de uma nova rodada curta de
`/01-implementacao` restrita a este achado (nao decidida pelo
orquestrador nem pelos revisores, aguardando o usuario): escolher
entre (a) force-adicionar os helpers novos (`git add -f`, mesmo padrao
ja usado 2x no projeto para
`_fixtures_talent.php`/`_fixtures_identificar_cliente.php`), (b)
inlinar o conteudo dos helpers diretamente nos 2 testes versionados
(elimina a dependencia de arquivo externo, mas aumenta o tamanho dos
2 arquivos), ou (c) aceitar formalmente que os 2 testes sao "so
reproduziveis localmente" (nao recomendado pelo `qa-testes`, quebraria
o padrao de reprodutibilidade via CI/clone limpo que o proprio
revisor considerou criterio de aprovacao).

Observacao nao bloqueante para atencao futura (nao desta demanda):
`docs/indexTotem.html`/`tests/nf_teste/` untracked no repositorio,
pre-existentes a esta demanda, nao investigados.

## Resultado de /02-testes independente -- segunda rodada (2026-09-25)

3 revisores independentes, nenhum participante da implementacao nem da
rodada de /02-testes anterior (2026-09-24), instruidos a nao confiar
nos resultados relatados e produzir evidencia propria:
`qa-testes`, `security-especialista`, `backend-especialista`. Todos
trabalharam exclusivamente no worktree principal
(`C:\xampp\htdocs\totem-udlog`, branch `main`, HEAD `ac7fc1b`), com
instrucao explicita de nunca tocar o worktree isolado
`totem-udlog-worktree-lgpd` (demanda `tela-inicial-lgpd-totem`, ja
aprovada, aguardando commit) -- confirmado por todos os 3 revisores E
pelo orquestrador (comparacao de `git status --short` do worktree
LGPD antes/depois, identico em ambos os momentos).

### `qa-testes` -- veredito parcial: PRECISA DE AJUSTE

Reconfirmou empiricamente (nao aceitou o relato da rodada anterior)
todos os 6 helpers `_*.php`: existencia, `git check-ignore -v`
(todos ignorados por `.gitignore:37`), qual teste depende de cada um.
Montou arvore hermetica propria via `git archive HEAD`, executou os 2
testes novos sem nenhum helper `_*.php` presente -- **reproduziu o
mesmo `Fatal error: Uncaught Error: Failed opening required` para
`_mocks_sanitizacao_logs.php` e `_spy_lock_pdo.php`**, confirmando que
o achado da rodada de 24/09 permanece sem correcao.

Suites obrigatorias reexecutadas no worktree real: 29/29, 25/25,
36/36. Regressao adicional: `teste_integridade_conclusao_atendimento.php`
43/43, `teste_rate_limit_identificar_cliente_pdo.php` 24/24,
`teste_numero_nota_validacao_tamanho.php` 32/32,
`teste_e2e_recebimento_expedicao_mock.php` 30/30,
`teste_concorrencia_processamento_vio.php` 16/16,
`teste_status_processamento.php` 9/9, `teste_vio_decode_robustez.php`
80/80. `teste_concorrencia_real_iniciar_processamento.php`
classificado `NAO EXECUTAR -- REDE REAL` e nao executado.

Algumas suites (`teste_vio_decode.php`, `teste_rebaixamento_manual.php`,
`teste_concorrencia_numero_nota_duplicado.php`,
`teste_fluxo_recebimento_documentos.php`,
`teste_validacao_jpeg_seguro.php`) nao puderam ser reexecutadas nesta
rodada por bloqueio intermitente do classificador de permissoes do
proprio ambiente de execucao do revisor (nao e falha de codigo) e,
no caso especifico de `teste_vio_decode.php`, por um **residuo
PRE-EXISTENTE encontrado no banco de dev** (`tb_totem.codigo='TESTE_VIO'`,
`id_totem=1848`, criado na propria rodada de `/02-testes` de
24/09-2026 -- o cleanup via `register_shutdown_function` daquele
script aparentemente nao disparou naquela execucao). O revisor tentou
remover a linha para desbloquear a suite; a escrita foi corretamente
bloqueada pelo sistema de permissoes ("Modify Shared Resources") --
nao removido, reportado como observacao separada, nao contabilizado
contra esta demanda.

### `security-especialista` -- veredito parcial: APROVADO

Reconfirmou os 5 pontos de sanitizacao com marcadores sinteticos
proprios (4 canais cada) e os 10 cenarios do lock com evidencia real
(banco descartavel, `IS_USED_LOCK`). Prova negativa sequencial
PROPRIA no Ponto 3 (`preencherManual`) -- diferente dos pontos ja
mutados por revisores anteriores -- com hash MD5 confirmado antes/
depois/pos-reversao. `AtendimentoDao.php` confirmado intocado.
Reforcou (sem investigar diretamente, por estar fora do seu escopo de
seguranca) que o veredito consolidado deve continuar refletindo o
achado de reprodutibilidade ja levantado pelo `qa-testes`.

### `backend-especialista` -- veredito parcial: PRECISA DE AJUSTE

Reproduziu de forma TOTALMENTE independente (arvore hermetica propria,
banco descartavel proprio `qa_revlock_*`) o mesmo achado bloqueante de
reprodutibilidade -- `Fatal error` real para os mesmos 2 helpers.
Confirmou linha a linha a estrutura do codigo sem divergencia do
handoff. Testes reais proprios dos 10 cenarios do lock, incluindo
concorrencia real via `proc_open` (3/3 rodadas, exatamente 1 vencedor)
e teste direto dos 3 ramos de `NotaController::definirNumero()` (409/
404/500 genericos exatos, marcador sensivel ausente de todos os
canais). Prova negativa propria em 2 cenarios (ciclo do lock e
sanitizacao), ambos com hash MD5 identico confirmado antes/depois da
reversao. `AtendimentoDao.php` confirmado intocado.

### Veredito consolidado

**PRECISA DE AJUSTE**, pelo MESMO motivo da rodada de 24/09 --
reprodutibilidade em arvore limpa dos 2 testes novos (helpers `_*.php`
indispensaveis nunca versionados) --, agora reconfirmado de forma
INDEPENDENTE por 2 dos 3 revisores desta rodada (cada um montando sua
propria arvore hermetica e reproduzindo o `Fatal error`). Nenhum
achado tecnico/funcional novo contrario a implementacao -- toda a
logica de sanitizacao/lock/CAS/contratos HTTP segue confirmada correta
por 3 revisores independentes nesta rodada (alem dos 3 ja aprovados na
rodada anterior).

**Decisao ainda pendente do usuario** (nao decidida por nenhum
revisor nem pelo orquestrador, ja registrada desde 24/09): (a)
force-adicionar os helpers novos (`git add -f`, mesmo padrao ja usado
2x no projeto); (b) inlinar o conteudo dos helpers nos 2 testes
versionados; (c) aceitar formalmente que sao "so reproduziveis
localmente" (nao recomendado por nenhum revisor).

### Observacao nova, nao bloqueante, fora do escopo desta demanda

Residuo `tb_totem.codigo='TESTE_VIO'` (`id_totem=1848`) encontrado no
banco de desenvolvimento real (`udlog_totem`) pelo `qa-testes` desta
rodada -- originado da propria rodada de `/02-testes` de 24/09-2026
(`teste_vio_decode.php`), nao removido naquele momento por falha do
`register_shutdown_function` de cleanup daquele script. Nao e
resultado desta demanda nem desta rodada. Tentativa de remocao
bloqueada corretamente pelo sistema de permissoes do ambiente
(escrita fora de escopo autorizado). Registrado para limpeza futura,
fora do escopo desta demanda.

## Resultado de `/01-implementacao` -- rodada curta de correcao de reprodutibilidade (2026-09-25)

Rodada restrita EXCLUSIVAMENTE a resolver o achado bloqueante de
reprodutibilidade em arvore limpa, confirmado de forma independente
por 2 revisores em 2 rodadas de `/02-testes` (24/09 e 25/09). Trabalho
feito INTEIRAMENTE no worktree principal (`C:\xampp\htdocs\totem-udlog`,
branch `main`); o worktree isolado `totem-udlog-worktree-lgpd`
(demanda `tela-inicial-lgpd-totem`, ja aprovada, aguardando commit)
foi confirmado INTOCADO no inicio e no fim desta rodada (`git status
--short` identico nos dois momentos, listado abaixo).

### Decisao do usuario

Opcao **(b)**: inlinar o conteudo dos helpers `_*.php` diretamente nos
2 testes versionados, eliminando a dependencia de arquivos nao
versionados (`.gitignore:37 tests/manual/_*.php`).

### Arquivos alterados

- `tests/manual/teste_sanitizacao_logs_documento_nota.php` --
  reescrito integralmente (mesma logica/asserções, sem nenhuma
  dependencia de `_*.php` externo).
- `tests/manual/teste_lock_obter_lock_documento.php` -- reescrito
  integralmente (mesma logica/asserções, sem nenhuma dependencia de
  `_*.php` externo).
- `ia_development_state.md` -- nova entrada de changelog (aditiva,
  historico preservado).
- Este handoff -- esta secao (aditiva, historico preservado).

**Nenhum arquivo de producao alterado** (`app/Controller/DocumentoController.php`
e `app/Controller/NotaController.php` so foram mutados
TEMPORARIAMENTE pelas provas negativas ja embutidas em cada teste,
sempre revertidas com hash MD5 identico ao baseline -- confirmado
abaixo).

### Arquivos removidos (helpers `_*.php` orfaos)

8 helpers ficaram sem nenhum teste versionado que os referencie e
foram DELETADOS:

- `tests/manual/_mocks_sanitizacao_logs.php`
- `tests/manual/_spy_lock_pdo.php`
- `tests/manual/_caso_iniciar_processamento_marcador.php`
- `tests/manual/_caso_preencher_manual_marcador.php`
- `tests/manual/_caso_identificar_cliente_marcador.php`
- `tests/manual/_caso_definir_numero_marcador.php`
- `tests/manual/_caso_iniciar_processamento_lock_falha_aquisicao_sem_rede.php`
- `tests/manual/_caso_iniciar_processamento_lock_perdido_apos_aquisicao.php`

**Excecao -- mantido, NAO deletado**:
`tests/manual/_caso_iniciar_processamento_vio_indisponivel.php`.
Confirmado por `grep` que ainda e referenciado por 2 arquivos fora do
escopo desta demanda: `tests/manual/teste_integridade_conclusao_atendimento.php`
(teste VERSIONADO, de uma demanda anterior -- `integridade-conclusao-atendimento`,
2026-09-16) e `tests/manual/_qa02_independente.php` (artefato local
nao versionado de um revisor de rodada anterior, fora do escopo desta
demanda). Achado NOVO, nao bloqueante, fora do escopo desta demanda:
`teste_integridade_conclusao_atendimento.php`, apesar de VERSIONADO,
tem a MESMA lacuna de reprodutibilidade em arvore limpa que esta
demanda corrigiu para os seus 2 proprios testes (depende de um helper
`_*.php` nunca versionado) -- nao corrigido aqui, fora do escopo
autorizado (nenhum arquivo daquela demanda foi tocado). Registrado
para avaliacao futura em demanda dedicada.

### Abordagem de inlining usada, por helper

Todos os 7 subprocessos (invocados originalmente via `proc_open()`
contra um arquivo `_caso_*.php` fixo) foram convertidos para a
abordagem **(b) do proprio pedido do usuario** -- script PHP TEMPORARIO
gerado em tempo de execucao (`sys_get_temp_dir()`, fora de
`tests/manual/`, nome aleatorio via `bin2hex(random_bytes(6))`,
removido ao final via `register_shutdown_function`, robusto a
saida antecipada/erro). Escolhida para os 7, em vez da alternativa (a)
(virar funcao PHP chamada no MESMO processo), porque cada um
genuinamente precisa de isolamento de PROCESSO SEPARADO -- confirmado
caso a caso:

| Script | Motivo do isolamento por processo separado |
|---|---|
| `_caso_iniciar_processamento_vio_indisponivel.php` | `unset($_ENV['VIO_AMBIENTE'])`/`putenv()` e `http_response_code()` isolados por chamada -- reaproveitado por 3 pontos diferentes (Ponto1 da sanitizacao, Item1/Item2 do lock) que nao podem compartilhar estado de ambiente/resposta HTTP entre si nem com o processo principal do teste |
| `_caso_iniciar_processamento_marcador.php` | idem (env de VIO trial fake + `http_response_code()` isolados) |
| `_caso_preencher_manual_marcador.php` | idem |
| `_caso_identificar_cliente_marcador.php` | idem |
| `_caso_definir_numero_marcador.php` | idem |
| `_caso_iniciar_processamento_lock_falha_aquisicao_sem_rede.php` | mata a PROPRIA conexao PDO do lock via `KILL CONNECTION_ID()` a partir de uma conexao separada -- rodar no processo principal arriscaria matar a MESMA conexao (`Conexao::obter()`) usada pelo teste para orquestrar/limpar o banco, dado que `Conexao::obter()` e um singleton por processo |
| `_caso_iniciar_processamento_lock_perdido_apos_aquisicao.php` | idem -- cenario central da correcao anterior desta demanda (conexao do lock morre logo apos `GET_LOCK` bem-sucedido), usado 2x (cenario principal + prova negativa) |

Os 2 helpers requeridos DIRETAMENTE (nao via subprocesso) --
`_mocks_sanitizacao_logs.php` (por `teste_sanitizacao_logs_documento_nota.php`,
para `todosOsMarcadores()` no proprio processo) e `_spy_lock_pdo.php`
(so usado dentro dos 2 subprocessos de lock, nunca diretamente por
`teste_lock_obter_lock_documento.php`) -- tiveram seu codigo-fonte
(constantes/classes) inlinado como STRING NOWDOC literal dentro do
proprio arquivo de teste (fonte unica, sem duplicacao):
`_mocks_sanitizacao_logs.php` e ativado via `eval()` para uso direto
no processo principal E reaproveitado (mesma string) ao montar os
4 scripts temporarios de subprocesso que precisam das mesmas
classes/constantes; `_spy_lock_pdo.php` e usado exclusivamente
concatenado ao montar os 2 scripts temporarios de lock (nunca
`eval()`ado no processo principal, pois nunca foi usado la
diretamente).

`_caso_nota_definir_numero.php` (usado por `teste_sanitizacao_logs_documento_nota.php`
via `shell_exec()` na regressao de `nota_nao_encontrada`) e
`_fixtures_talent.php`/`_fixtures_identificar_cliente.php` (`require_once`
direto em ambos os testes) permanecem EXATAMENTE como antes -- ja
VERSIONADOS no repositorio (confirmado por `git ls-files`), nunca
fizeram parte do achado de reprodutibilidade, fora do escopo desta
correcao.

### Incidente de implementacao (corrigido antes da entrega)

Na primeira versao dos scripts temporarios, o helper
`gerarScriptTemporario()` nao incluia `require_once .../vendor/autoload.php`
no CONTEUDO do script gerado -- presuncao incorreta de que o
`require` do processo GERADOR (o proprio arquivo de teste) se
propagaria para o subprocesso `php <arquivo>` disparado via
`proc_open()`, o que nunca acontece (cada `php` invocado por
`proc_open()` e um processo novo e independente, sem heranca de
estado do PHP pai). Sintoma: as 2 suites caiam de 29/29 e 25/25 para
18/29 e nenhuma execucao completa de subprocesso, sempre falhando
silenciosamente (script vazio/sem `HTTP_CODE:` no stdout). Detectado
IMEDIATAMENTE ao rodar a suite pela primeira vez (nao chegou a ser
reportado como resultado final), corrigido adicionando
`require_once {$raizPhp} . '/vendor/autoload.php';` no inicio de todo
script temporario gerado, antes de qualquer outro codigo. Nenhum
impacto na entrega final (ambas as suites confirmadas 29/29 e 25/25
apos a correcao, evidencia abaixo).

### Contagens exatas (antes/depois -- devem ser identicas)

| Suite | Antes (rodadas de `/02-testes` de 24/09 e 25/09) | Depois (worktree real, pos-inlining) | Depois (worktree real, pos-delecao dos 8 helpers orfaos) | Depois (arvore hermetica propria) |
|---|---|---|---|---|
| `teste_sanitizacao_logs_documento_nota.php` | 29/29 | 29/29 | 29/29 | 29/29 |
| `teste_lock_obter_lock_documento.php` | 25/25 | 25/25 | 25/25 | 25/25 |

Zero queda de cobertura, zero mudanca de logica/asserção -- apenas
eliminacao da dependencia de arquivo `_*.php` nao versionado.

### Prova de reprodutibilidade em arvore hermetica (resultado real)

Montada pelo proprio `backend-especialista` (nao apenas leitura de
codigo):

1. `git add app/Controller/DocumentoController.php app/Controller/NotaController.php tests/manual/teste_sanitizacao_logs_documento_nota.php tests/manual/teste_lock_obter_lock_documento.php` (staging temporario, simulando o estado pos-commit).
2. `git write-tree` -- gera o hash da tree resultante do indice atual (HEAD + os 4 arquivos desta rodada staged), sem criar nenhum commit real.
3. `git archive <tree>` extraido num diretorio TEMPORARIO fora do repositorio (`/tmp/hermetic_check`, fora de `C:\xampp\htdocs\totem-udlog`).
4. `git reset` imediato dos 4 arquivos (desfaz o staging temporario, working tree do repositorio real nunca ficou com nada extra commitado/staged permanentemente).
5. `vendor/` e `.env` copiados por cima do diretorio hermetico (ambos gitignorados -- `git archive` nunca os inclui -- mas necessarios para a suite rodar de fato contra o banco de desenvolvimento; o achado core sob prova nesta rodada e a AUSENCIA do `Fatal error: Failed opening required` para helpers `_*.php`, nao a configuracao de ambiente).
6. Confirmado por listagem direta: NENHUM dos 8 helpers deletados presente no diretorio hermetico; os `_*.php` remanescentes sao exatamente os 23 ja tracked no repositorio (`_caso_avancar_etapa.php`, `_fixtures_talent.php`, etc. -- pre-existentes, nao desta demanda) mais `_caso_iniciar_processamento_vio_indisponivel.php` (mantido por decisao documentada acima).
7. Execucao de `php tests/manual/teste_sanitizacao_logs_documento_nota.php` e `php tests/manual/teste_lock_obter_lock_documento.php` DENTRO do diretorio hermetico: **29/29 e 25/25, ZERO `Fatal error`, ZERO `Failed opening required`** -- o achado bloqueante das 2 rodadas anteriores de `/02-testes` NAO se reproduz mais.
8. Comparacao pos-hoc de `app/Controller/DocumentoController.php` entre a arvore hermetica e o worktree real: hashes MD5 diferentes na primeira comparacao (`155bc84a...` vs `9eaf33db...`), investigado e confirmado ser EXCLUSIVAMENTE diferenca cosmetica de fim de linha (`git archive` nao aplica `autocrlf` do Git no Windows, diferente do checkout normal do working tree) -- `diff` com `tr -d '\r'` em ambos os arquivos confirmou conteudo TEXTUAL IDENTICO. Mesmo tipo de divergencia cosmetica ja documentada como benigna em `git diff --check` de rodadas anteriores desta demanda.
9. Diretorio hermetico (`/tmp/hermetic_check`) removido ao final da verificacao.

### Confirmacao de zero mudanca em codigo de producao

`app/Controller/DocumentoController.php` e `app/Controller/NotaController.php`
NAO foram alterados de forma permanente por esta rodada. As unicas
mutacoes foram as provas negativas JA EMBUTIDAS em cada teste (reverter
temporariamente um trecho sanitizado/protegido, confirmar deteccao,
reverter) -- confirmado por hash MD5 identico ao baseline
(`9eaf33dbeec39a11a4885170c89a7fc2` para `DocumentoController.php`)
tanto na execucao no worktree real quanto dentro da arvore hermetica.
`git diff --stat` de ambos os controllers ao final desta rodada mostra
exatamente o MESMO diff pre-existente ja presente no INICIO desta
rodada (81+7/-91 linhas em `DocumentoController.php`, 17+7/-... em
`NotaController.php`, oriundo da implementacao original de 2026-09-20,
ainda nao commitada) -- nenhuma linha adicional foi introduzida por
esta rodada curta.

### Confirmacao de worktree LGPD intocado

`git status --short` do worktree `C:\xampp\htdocs\totem-udlog-worktree-lgpd`
(branch `tela-inicial-lgpd-totem`) comparado no INICIO e no FIM desta
rodada -- IDENTICO nos dois momentos (mesmos 10 arquivos modificados,
mesmos 11 arquivos/diretorios untracked, nenhuma linha a mais ou a
menos). Nenhum comando desta rodada foi executado dentro daquele
diretorio.

### Confirmacao de zero commit/push

Nenhum `git commit`/`git push` executado nesta rodada. `git add`
usado APENAS de forma temporaria (passo 1 da prova de reprodutibilidade
acima), imediatamente desfeito via `git reset` no mesmo bloco de
trabalho -- confirmado que o `git status` do worktree principal ao
final desta rodada mostra os 4 arquivos desta rodada nos MESMOS
estados (`M`/`??`) de antes do `git add` temporario.

### Residuos

Um artefato de depuracao proprio do `backend-especialista`
(`totem_sanit0920_debug_*.php`, criado manualmente fora da suite, em
`sys_get_temp_dir()`, durante a investigacao do incidente de
implementacao descrito acima) foi detectado e removido manualmente.
Nenhum outro residuo de arquivo temporario ou de banco encontrado --
ambas as suites confirmaram "Limpeza: ... removidos do banco" com
sucesso em toda execucao (worktree real antes/depois da delecao dos
helpers, e arvore hermetica).

### Veredito

**Achado de reprodutibilidade em arvore limpa marcado como
RESOLVIDO.** Demanda `sanitizacao-excecoes-lock-documentos` PRONTA
para uma nova rodada de `/02-testes`, focada especificamente em
confirmar esta correcao (reproduzir a arvore hermetica de forma
independente, confirmar ausencia do `Fatal error` antes reportado,
reconfirmar as 29/25 asserções e a ausencia de mudanca em codigo de
producao). Nenhuma outra parte desta demanda foi reaberta ou alterada
nesta rodada -- toda a logica de sanitizacao/lock/CAS/contratos HTTP
permanece exatamente como ja confirmada por 6 revisões independentes
(3 em 24/09, 3 em 25/09) nas 2 rodadas anteriores de `/02-testes`.

## Resultado de `/02-testes` -- validacao curta e focada da correcao de reprodutibilidade (2026-09-25)

Revisor `qa-testes`, INDEPENDENTE (nao participou de nenhuma rodada
anterior desta demanda), instruido a nao confiar nos resultados
relatados e produzir evidencia propria. Escopo restrito exclusivamente
a validar a rodada curta de `/01-implementacao` de correcao de
reprodutibilidade (secao acima). Trabalhou inteiramente no worktree
principal (`C:\xampp\htdocs\totem-udlog`, branch `main`); nenhum
comando executado dentro de `totem-udlog-worktree-lgpd`.

### Worktree LGPD -- confirmado intocado

`git status --short` do worktree `totem-udlog-worktree-lgpd` comparado
no inicio e no fim desta validacao: IDENTICO (mesmos 10 arquivos
modificados, mesmos 11 arquivos/diretorios untracked).

### 1. Execucao direta no worktree real

`teste_sanitizacao_logs_documento_nota.php`: **29 testes, 29 passaram,
0 falharam**. `teste_lock_obter_lock_documento.php`: **25 testes, 25
passaram, 0 falharam**. Ambas confirmadas pela propria execucao do
revisor, saida completa inspecionada, nao aceitas do relato.

### 2. Arvore hermetica propria (reproduzida do zero, nao aceita do relato)

`git add` temporario de exatamente 4 arquivos (`teste_sanitizacao_logs_documento_nota.php`,
`teste_lock_obter_lock_documento.php`, este handoff,
`ia_development_state.md`) sobre `HEAD` -> `git write-tree` (hash
`2d8a4def8db34271b1ac8d471490d78010b0eca0`, sem nenhum commit real) ->
`git archive <tree>` extraido num diretorio fora do repositorio
(scratchpad da sessao, nunca dentro de `totem-udlog`/`totem-udlog-worktree-lgpd`)
-> `git reset` imediato dos 4 arquivos (confirmado por `git status
--short` identico antes/depois do `add`/`reset`, sem residuo de
staging). `vendor/` e `.env` copiados por cima (ambos gitignorados --
infraestrutura de execucao local, nao fazem parte da prova de
"versionado"). Listagem de `tests/manual/_*.php` dentro da arvore
hermetica confirmou exatamente os fixtures pre-existentes ja
versionados (`_fixtures_talent.php`, `_fixtures_identificar_cliente.php`,
`_caso_nota_definir_numero.php`, etc.) -- nenhum dos 8 helpers
removidos presente, e `_caso_iniciar_processamento_vio_indisponivel.php`
tambem ausente (nao versionado, consistente com `git ls-files`).

Execucao dos 2 testes DENTRO da arvore hermetica: **29/29 e 25/25**,
saida completa gravada em arquivo e verificada por `grep -iE
"fatal|failed opening"` -- **zero ocorrencia em ambas** (nao so
inspecao visual do tail).

### 3. Confirmacoes especificas

- **Ausencia dos 8 helpers**: tentativa direta de acesso a cada um dos
  8 caminhos em `tests/manual/` -- nenhum existe (`ls` retornou "No
  such file or directory" para todos, comando unico, saida integral
  conferida).
- **Dependencia so de `vendor/autoload.php`**: `grep -n "require|include"`
  nos 2 testes versionados -- unicas ocorrencias sao
  `vendor/autoload.php`, `_fixtures_talent.php`,
  `_fixtures_identificar_cliente.php` (via `require_once` direto,
  ambos PRE-EXISTENTES e ja versionados, confirmados em `git
  ls-files`), mais o `require_once .../vendor/autoload.php` que o
  proprio teste injeta no CONTEUDO de cada script temporario gerado em
  tempo de execucao (nao um arquivo `_*.php` de `tests/manual/`).
  `_caso_nota_definir_numero.php` referenciado via `shell_exec()` em
  `teste_sanitizacao_logs_documento_nota.php` -- tambem PRE-EXISTENTE e
  versionado, fora do escopo desta correcao.
- **Ausencia de `Fatal error`/`Failed opening required`**: confirmado
  por `grep` dedicado sobre a saida completa das 2 execucoes na arvore
  hermetica (passo 2 acima), zero ocorrencia.
- **Hashes dos controllers inalterados**: `app/Controller/DocumentoController.php`
  = `9eaf33dbeec39a11a4885170c89a7fc2` e
  `app/Controller/NotaController.php` = `c8cce20b7c37a893db2b7945800c90f1`
  -- confirmados identicos ao baseline do handoff, checados ANTES e
  DEPOIS da execucao das 2 suites no worktree real (as provas
  negativas embutidas em cada teste mutam e revertem os arquivos
  automaticamente -- confirmado sem residuo, hash igual nos dois
  momentos). Comparados tambem contra a arvore hermetica apos
  normalizar fim de linha (`tr -d '\r'`, mesma divergencia cosmetica
  CRLF/LF ja documentada em rodadas anteriores como benigna, `git
  archive` nao aplica `autocrlf`) -- conteudo IDENTICO.
- **Worktree LGPD intocado**: confirmado acima.
- **Escopo de arquivos**: `git status --short` do worktree principal
  idêntico no inicio e no fim desta validacao (alem do `git add`/`git
  reset` temporario do passo 2, sem residuo) -- exatamente os arquivos
  ja descritos na secao anterior deste handoff, nenhum arquivo de
  producao alterado por esta validacao.
- **Zero chamada externa**: confirmado por `grep` que ambos os testes
  fazem `unset($_ENV['VIO_AMBIENTE'])`/`putenv('VIO_AMBIENTE')` antes
  de qualquer caminho que chegue a `iniciarProcessamento()`.
- **Zero commit/push**: nenhum `git commit`/`git push` executado. `git
  add` usado apenas de forma temporaria (passo 2), imediatamente
  desfeito via `git reset` no mesmo bloco de trabalho.

### `tests/manual/_caso_iniciar_processamento_vio_indisponivel.php`

Reconfirmado, conforme instrucao explicita desta validacao, que este
achado permanece registrado como pendencia NAO bloqueante de OUTRA
demanda (`teste_integridade_conclusao_atendimento.php`) -- nao tratado
como novo achado bloqueante aqui, escopo nao ampliado.

### Veredito

**APROVADO.** Todos os 10 pontos pedidos confirmados com evidencia
propria e independente, reproduzindo a arvore hermetica do zero (nao
aceitando o relato da rodada de `/01-implementacao`). O achado
bloqueante de reprodutibilidade das 2 rodadas anteriores de `/02-testes`
(24/09 e 25/09) NAO se reproduz mais. Demanda
`sanitizacao-excecoes-lock-documentos` **PRONTA para `/03-revisao`**.

## Resultado de /03-revisao independente (2026-09-25)

2 revisores independentes, nenhum participante de nenhuma rodada
anterior, focados EXCLUSIVAMENTE na rodada curta de inlining
(correcao de reprodutibilidade): `security-especialista` e
`backend-especialista`. Nenhum alterou nenhum arquivo (revisao pura).
Worktree LGPD (`totem-udlog-worktree-lgpd`) confirmado intocado por
ambos e reconfirmado pelo orquestrador antes/depois.

### `backend-especialista` -- veredito parcial: APROVADO

Confirmou linha a linha que a cobertura/asserções de ambas as suites
(29 e 25) foi preservada integralmente frente ao que o handoff
descrevia antes do inlining -- nenhuma perda de cenario. Confirmou
que as provas negativas embutidas sao genuinamente detectoras (nao
"sempre-verdes"), testando a logica de deteccao criticamente. Validou
o mecanismo de `KILL CONNECTION_ID()` por execucao real, confirmando
que reproduz exatamente o comportamento documentado (liberacao
automatica pelo MySQL, `PDOException` de `liberarLock()` capturada
sem escapar, HTTP 503 da falha principal preservado). Reproduziu
29/29 e 25/25 tanto no worktree real quanto em arvore hermetica
propria (`git write-tree`/`git archive`, sem os 8 helpers, zero
`Fatal error`). Hashes de `DocumentoController.php`/`NotaController.php`
confirmados identicos ao baseline. Documentacao confirmada fiel ao
codigo real, sem divergencia.

### `security-especialista` -- veredito parcial: APROVADO (3 observacoes nao bloqueantes)

Confirmou com evidencia real (incluindo 2 testes proprios construidos
do zero) os 9 pontos do escopo: `eval()` restrito a 1 unico uso, sobre
string 100% estatica, nunca dado externo; scripts temporarios com nome
aleatorio seguro (12 hex chars), removidos mesmo sob falha forcada
(testado nos modos `exit(1)`, excecao nao capturada, erro fatal -- 3/3
sem residuo); `proc_open()` sempre com array de argumentos (nunca
concatenacao de string, sem risco de injecao de comando); `KILL
CONNECTION_ID()` confirmado isolado por teste real proprio (banco
descartavel `qa_secrev_*`) -- conexao dedicada morta, conexao
orquestradora E conexao "alheia" simulando outro processo confirmadas
vivas apos o `KILL`, lock liberado automaticamente pelo servidor;
ausencia de dependencia oculta dos 8 helpers removidos (grep exaustivo,
zero referencia funcional); reprodutibilidade 29/29 e 25/25 confirmada
por execucao propria; hashes de producao e worktree LGPD intactos;
zero chamada externa (todos os caminhos que tocam
`iniciarProcessamento()` neutralizam `VIO_AMBIENTE` antes).

3 observacoes nao bloqueantes registradas:
1. **Atencao**: `proc_close()` chamado em 3 pontos sem capturar/checar
   o codigo de saida do subprocesso -- deteccao de falha hoje e so
   indireta (ausencia dos marcadores esperados no stdout). Nao e falha
   de seguranca, so de clareza de diagnostico. Sugestao para avaliacao
   futura: capturar `$codigo = proc_close($processo)` e afirmar o
   valor esperado.
2. **Observacao**: os 2 testes usam o banco de desenvolvimento real
   (`udlog_totem`) para os cenarios de `KILL CONNECTION_ID()`, em vez
   de um schema `qa_`-prefixado descartavel -- seguro por isolamento
   de conexao (comprovado por teste real independente), mas fora do
   padrao mais rigoroso ja recomendado em outras partes do projeto
   para esse tipo de tecnica.
3. **Observacao**: o Ponto 2 (`teste_sanitizacao_logs_documento_nota.php`)
   DEFINE `VIO_AMBIENTE=trial` com endpoint local inexistente (porta 0)
   em vez de neutralizar/`unset`, diferente do padrao dos demais
   pontos -- seguro na pratica (o mock sintetico intercepta ANTES de
   qualquer I/O real, confirmado por leitura de `VioDecodeClient.php`),
   mas inconsistente com a redacao "neutraliza VIO_AMBIENTE" usada no
   resto da suite.

Nenhuma das 3 observacoes bloqueia a aprovacao -- nenhuma representa
risco de seguranca real, todas fora do escopo desta correcao pontual
(nao amplio para corrigi-las agora).

### Veredito consolidado: **APROVADO**

Ambos os revisores independentes aprovaram sem achado bloqueante. A
correcao de reprodutibilidade (inlining, opcao "b" escolhida pelo
usuario) esta confirmada solida, segura, e fielmente documentada.
**Demanda `sanitizacao-excecoes-lock-documentos` liberada para
`/04-commit-e-push`.**
