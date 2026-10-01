# Handoff — hardening-revisao-notas-e-cliente

Data: 2026-09-30
Etapa: 00-planejamento (somente planejamento; nenhum codigo alterado)

## O que foi pedido
Fechar as pendencias restantes da captura de notas: (1) regra definitiva de cliente; (2) validacao
backend obrigatoria de numero_nota (422); (3) Voltar, Excluir nota e Adicionar outra nota;
(4) isolamento das falhas de OCR; (5) UX, seguranca e higiene (Date.now, versoes do Tesseract,
testeOcr, toast, codigo morto). Decisoes do usuario: historico publicado com docs/.claude ENCERRADO
(sem reescrita); tratar nesta demanda: separar try, performance.now, fixar versoes Tesseract/worker,
ignorar testeOcr/, toast, remover codigo morto confirmado. Trello foi removido do projeto (sem cartao).

## Regra definitiva de cliente (confirmada pelo usuario)
UDLOG e transportadora e nunca identifica; so tb_cliente ativa identifica; um cliente valido por nota;
sem necessidade de classificar emitente x destinatario se a correspondencia unica for comprovada
(supera a decisao anterior de papel comprovado, P05); nunca escolher o primeiro candidato do OCR;
zero correspondencias -> confirmacao manual; uma distinta -> automatica; duas ou mais -> anomalia
fail-closed; mesmo cliente em varias notas = uma; clientes distintos entre notas = anomalia.

## Estado real confirmado (explorer, com arquivo e linha)
- tb_atendimento_nota ja tem id_nota (PK AUTO_INCREMENT) que nunca chega ao front; o codigo consulta por
  (id_atendimento, ordem) e grava por id_nota. UNIQUE por ordem e UNIQUE por numero_nota no atendimento.
  Arquivo = nota_NN.jpg derivado da ordem (NotaController.php:123); nao existe endpoint de exclusao, nem
  remocao de arquivo (so unlink no INSERT falho, :138), nem cron de retencao ou de orfaos; o cancelamento
  nao apaga fotos.
- Cliente: NotaFiscalRn.php:170-199 (early-stop copia evidencia), :230-244 (primeiro CNPJ que casa, break),
  :253-266 (fuzzy; empate devolve nao identificado sem sinalizar ambiguidade), getMessage em :184,:234,:263.
  concluirDigitalizacao (AtendimentoController.php:376-489) usa OR de algumaNotaComStatusIdentificada e
  algumaIdentificada (JOIN sem c.ativo); so grava etapa. O cliente associado automaticamente NAO e gravado
  em tb_atendimento; TalentRn.php:156 exige cliente_cnpj de 14 digitos; so o fluxo manual (salvarEtapa case
  cliente, AtendimentoController.php:301, sem validar contra tb_cliente) grava cliente_*.
- UDLOG: so CnpjValidador (CNPJ_UDLOG_1 e CNPJ_UDLOG_2, dois CNPJs completos; ehUdlog, unico chamador
  NotaFiscalRn.php:213); mesmos CNPJs semeados em tb_empresa (migration 008, usada so para o CNPJ do
  armazem do Talent); sem filtro por raiz, razao social, cliente casado nem no fuzzy.
- finalizar ja responde 422 NOTAS_SEM_NUMERO (AtendimentoController.php:738-751), mas so no fim do fluxo.
- Front (app.js HEAD a21411a): notas em arrays paralelos por indice sem id proprio; find por ordem em 5
  pontos; ordem do upload = notaOrdem + 1; try unico entre recognize, extrairNumeroNota e extrairCandidatos
  (3192-3214); aplicar e identificar no finally sem try; Date.now em 518-519 (teto) e 1860-1861 (polling);
  toast fixo bottom 16 px, z-index 60, 14 px, 4 s, cobre o Continuar e duplica #revisaoErro; fecharModal
  nao limpa o HTML.
- Dependencias: tudo local (createWorker com caminhos locais, sem CDN); Tesseract.js 5.1.1 (core,
  traineddata e jsQR sem versao nos arquivos: usar SHA-256); tesseract.min.js e qr-worker.js sem ?v;
  cacheMethod nao definido (traineddata pode ficar em IndexedDB).
- Higiene: testeOcr/ = 3 JPEG 3264x2448 (24/09) sem regra de ignore (o QA anterior citou 5: divergencia
  nao apurada); docs/ e .claude/ ja ignorados; nenhum teste exercita exclusao.

## Desenho proposto (PROPOSTA: tudo abaixo e novo e nao existe hoje)

### 1. Regra de cliente (o backend e a unica autoridade; sem migration no desenho principal)
- Cada nota guarda so a propria evidencia; a decisao do atendimento e DERIVADA das notas ativas a cada
  consulta (excluir nota ou resolver conflito recalcula sozinho; nada e copiado entre notas).
- NotaFiscalRn::avaliarClienteDoAtendimento (leitura travada, JOIN tb_cliente ativo=1): IDENTIFICADO =
  exatamente 1 id_cliente distinto e nenhuma nota em ERRO; NAO_IDENTIFICADO = zero correspondencias;
  ANOMALIA = 2 ou mais id_cliente distintos ou qualquer nota em ERRO (motivos CONFLITO ou INDETERMINADO).
  A chave de agrupamento e id_cliente (nao a string do CNPJ).
- identificarCliente por nota: remover o early-stop; coletar todos os CNPJs validos nao-UDLOG sem break;
  deduplicar por id_cliente (1 = IDENTIFICADA; 2 ou mais = ERRO com motivo MULTIPLAS_CORRESPONDENCIAS);
  fuzzy so com zero casamentos por CNPJ (unica e inequivoca = IDENTIFICADA; ambigua = ERRO com motivo
  RAZAO_SOCIAL_AMBIGUA, via campo ambiguo aditivo em RazaoSocialMatcher; abaixo do limiar = NAO_IDENTIFICADA);
  escrita unica por nota (UPDATE condicionado a id_nota, id_atendimento e status_ocr PENDENTE ou PROCESSANDO;
  0 linhas = reler e devolver o gravado; idempotente). ERRO representa o conflito (sem novo valor de status_ocr).
- UDLOG centralizada em Util/IdentificadoresUdlog (fonte: tb_empresa ativa via EmpresaDao::listarCnpjsAtivos
  mais uma constante de contingencia fail-closed; opcional no .env: UDLOG_CNPJS_EXTRA, UDLOG_RAIZES_CNPJ,
  UDLOG_RAZOES_SOCIAIS); CnpjValidador::ehUdlog passa a delegar; aplicada em 4 pontos: candidatos de entrada,
  cliente casado, listagem entregue ao fuzzy e razao social candidata. Hardcodes de hoje: CnpjValidador.php
  linhas 21-22 e 69-72; NotaFiscalRn.php:213; migration 008 (seed); literais em testes.
- Concluir: so IDENTIFICADO vai a rec_cnh e grava cliente_nome e cliente_cnpj em tb_atendimento na mesma
  transacao do CAS (decisao D1); NAO_IDENTIFICADO e ANOMALIA vao a rec_cliente (etapa cliente) sem gravar
  cliente; em ANOMALIA a resposta sinaliza cliente_estado e cliente_motivo (o front abre a confirmacao manual
  com mensagem neutra, sem expor dados de terceiros). Remover o OR com algumaIdentificada (legado sem filtro
  ativo) e neutralizar a identificacao pelo caminho chave no servidor (o front envia chave nula).
- Contrato identificar-cliente (aditivo): request id_atendimento, id_nota (novo), ordem (compat; se vierem os
  dois devem coincidir, senao 404), cnpjs_candidatos (max 10), razao_social_candidata; response: status por nota,
  motivo, cliente da nota, id_nota e cliente_atendimento com estado e cliente (ou nulo); ja_identificado_no_
  atendimento mantido com semantica restrita a IDENTIFICADO. O front passa a usar cliente_atendimento.estado,
  remove o gate clienteIdentificado (uma chamada por nota; ate 5, dentro do limite de 30 por minuto por totem;
  429 = nao identificada, fail-closed) e neutraliza o aviso Cliente identificado da captura.

### 2. HTTP 422 em concluir-digitalizacao
- Ordem: id valido (400); posse e tipo (404); status (400); etapa errada mantem o ramo idempotente atual;
  BEGIN e leitura FOR UPDATE de tb_atendimento e releitura sob lock; notas ativas travadas (total menor que 1
  ou maior que 5 continua 400); NOVO: pendente = numero_nota que nao casa o formato de 1 a 9 digitos sem zero a
  esquerda (NULL, vazio e lixo legado), por um unico predicado NotaFiscalRn::numeroNotaValido; havendo pendentes:
  422, ROLLBACK, etapa e cliente inalterados; depois avaliarClienteDoAtendimento e CAS (grava cliente_* se
  IDENTIFICADO); COMMIT e so entao responder.
- Resposta 422 (Resposta::erroComDados, aditivo): sucesso false; erro com a mesma mensagem do finalizar
  (NOTAS_SEM_NUMERO); codigo NOTAS_SEM_NUMERO; dados com ordens_pendentes (inteiros de 1 a 5), total_notas e
  total_pendentes; sem id_nota, numero, CNPJ, caminho ou imagem.
- Front: api() propaga os detalhes; tratarNotasSemNumero destaca so ordens inteiras de 1 a 5, volta a
  rec_revisao_numeros e rola ate a primeira; nunca mostra texto cru do backend.
- Deploy: backend antes do front; o 422 so ativa em etapa propria depois do front novo no ar (aba antiga com
  422 se perderia); sugestao: flag no .env com padrao desligado para separar deploy de ativacao.

### 3. Excluir nota e Adicionar outra nota
- Identidade: id_nota (imutavel, nunca reutilizado). processar passa a devolver id_nota e ordem; ordem vira
  opcional no request (o servidor aloca a primeira ordem livre de 1 a 5 sob lock; se informada e ocupada,
  continua 400). definir-numero e identificar-cliente aceitam id_nota (com ordem devem coincidir). Nao renumera.
  O arquivo continua nota_NN.jpg por ordem; a quarentena libera o nome na hora (sem colisao).
- Contrato POST nota.php?acao=excluir com id_atendimento e id_nota; campos de caminho ou arquivo no request sao
  ignorados. 200 com excluida, ja_excluida, id_nota, total_notas, notas (id_nota, ordem, numero_definido) e
  cliente_atendimento. Erros: 400 dados incompletos; 404 posse, tipo ou atendimento; 400 status ou etapa errados
  (inclusive apos o concluir); 503 sanitizado em timeout de lock; 500 falha antes do commit. Idempotencia:
  id_nota inexistente no atendimento = 200 com ja_excluida (sem vazar existencia de nota alheia).
- Sequencia (innodb_lock_wait_timeout de 5 s): BEGIN; FOR UPDATE em tb_atendimento e validar posse, tipo, status
  e etapa antes de tocar no disco; ler a nota por id_nota e id_atendimento FOR UPDATE (ausente = ROLLBACK e
  ja_excluida); caminho montado so de STORAGE_PATH + pasta_documentos + arquivo do banco, validando o formato de
  pasta_documentos (data, placa e hora), o formato do arquivo (nota_01 a nota_05, jpg) e realpath com prefixo,
  sem symlink (invalido = 500 sem tocar disco nem banco); quarentena por rename atomico para
  nota_NN.jpg.<id_nota>.del (ausente = seguir; falha = ROLLBACK e 500); DELETE exigindo rowCount 1; COMMIT; so
  depois unlink do .del (falha tolerada, log fixo). Nunca chamar Resposta entre o rename e o COMMIT.
- Por que banco com quarentena primeiro: com o arquivo apagado primeiro, uma falha do banco deixa a linha sem
  arquivo e montarAnexos a pula em silencio (o numero iria ao Talent sem anexo).
- Falhas parciais: banco ok e .del nao removido = sucesso ao motorista, orfao com nome reconhecivel coberto pelo
  cron; arquivo movido e DELETE ou COMMIT falha = ROLLBACK e rename de volta (se falhar, o retry de excluir
  reconhece a quarentena e conclui; 500 e log fixo); processo morto entre rename e DELETE = mesmo estado;
  arquivo ausente = remove a linha, 200, log fixo arquivo_ausente_na_exclusao.
- Cron cron/limpar-notas-quarentena.php (PROPOSTA, so CLI, cPanel): apaga so arquivos .del com mais de 1 hora,
  em estrutura de pastas fixa, sem seguir symlink, sem banco. Classe Util/NotaArquivoStorage (quarentenar,
  restaurar, remover, existe) tambem permite injecao de falha nos testes. Exclusao fisica (sem coluna nova:
  exclusao logica manteria os UNIQUE bloqueando a reutilizacao). Nota orfa nota_NN.jpg sem linha nao e coberta.
- Adicionar (processar sob o mesmo lock): BEGIN, travar o atendimento, revalidar; contar (400 se 5 ou mais) e
  alocar a primeira ordem livre; gravar o arquivo; INSERT (falha = unlink seguro sob lock, eliminando a corrida
  atual que apaga arquivo legitimo); COMMIT (falha = unlink de melhor esforco). doctos[] e anexos iteram as
  linhas existentes por ordem (nao exigem contiguidade); o arquivo em quarentena nunca e lido.
- Excluir a nota que identificou o cliente recalcula o estado (derivado) e a resposta traz cliente_atendimento.

### 4. Concorrencia e resultados tardios
- Backend: lock de linha (SELECT FOR UPDATE em tb_atendimento) em transacao curta; processar, excluir,
  definir-numero e concluir travam na mesma ordem (atendimento, depois notas); identificar-cliente calcula fora do
  lock e trava so para persistir. concluir x concluir: o segundo recebe sucesso idempotente; concluir x excluir
  serializados; excluir x excluir = ja_excluida; definir-numero ou identificar-cliente em nota excluida (ou id_nota
  antigo apos reuso de ordem) = 404 sem escrita; timeout de lock = 503 sanitizado. Nao usar GET_LOCK (escopo global
  no servidor compartilhado).
- Front: cada nota ganha uid imutavel (por atendimento) e o array paralelo notasImagens deixa de existir; ocrFila,
  aplicarResultadoOcrNumero, tocarCartaoNota, marcarNumeroNotaConfirmado, modais e identificarClienteNota resolvem
  por uid; cartao com id por uid e reconciliacao do DOM (remove cartao sem nota); trava excluindoNota na mesma tick
  (clique duplo = no-op); Continuar desabilitado durante excluir e concluir; resposta tardia descartada por geracao,
  existencia do uid e versao; ordem de upload = proximaOrdemLivre (ou a alocada pelo servidor); voltar de Voltar
  limpa o manualOverride e recalcula o estado a partir do ultimo resultado do OCR (sugestaoOcr).

### 5. Front: modais, revisao e UX
- Voltar: botao de 64 px que fecha o modal sem api, sem alterar numero, confirmado, estado nem origem e desfaz o
  manualOverride; descarta o valor digitado sem perguntar; toque fora do modal nao faz nada (nao ha listener no
  fundo hoje; manter e provar em teste); sem Esc. Ordem nos modais: Confirmar, Corrigir, Voltar (contorno neutro
  com borda #3A3A3A), divisor e 28 px de folga, Excluir nota (vermelho solido #a32d2d, texto branco), Cancelar
  atendimento mais distante. Modal de digitar: Voltar em largura total abaixo do teclado. Altura do modal de
  digitar sobe de cerca de 673 para cerca de 820 px (medir em 768x1366 e 1152x1846).
- Confirmacao de exclusao em overlay proprio (z-index 56, sem reescrever #modalCaixa): titulo Excluir a Nota N de
  M?; texto conforme o backend (se apaga a foto: A foto sera apagada, voce precisara capturar de novo); botoes
  Nao, voltar (azul, em cima) e Sim, excluir (vermelho, embaixo, 24 px de folga); foco no Nao; clique duplo
  bloqueado (rotulo Excluindo...); erro inline fixo; toque fora nao faz nada. Sequencia: verificar uid e travas;
  travar botoes; chamada excluir com id e geracao capturados; reavaliar o guard; so entao remover do estado,
  tirar o item da ocrFila por uid, reconciliar o DOM e atualizar o contador; erro mantem a nota; ja_excluida e
  sucesso; resultado de OCR em execucao da nota excluida e descartado (nao cancelavel).
- Depois da exclusao: restam notas = fica na revisao (rotulo Nota N de M pela posicao, nao pela ordem); zero notas
  = volta sozinho a captura; menos de 5 = Adicionar outra nota (contorno azul, 64 px, acima do Continuar, fora do
  rodape sticky); com 5 o botao some; Continuar bloqueado com nota sem numero. Faixa Nota N excluida (some em ~5 s).
- Toast: nao exibir na tela rec_revisao_numeros (tudo inline); pointer-events none; em outras telas acima dos
  controles, 6 s, 20 px, aria-live; 422 destaca cartoes (borda 4 px #a32d2d, selo Falta o numero) e rola ate o
  primeiro, com mensagem fixa em #revisaoErro. Anomalia ou zero cliente: rec_cliente com texto neutro e igual nos
  dois casos (Nao conseguimos identificar o cliente. Digite o nome ou CNPJ), nunca mostrar dados de candidatos.
- Fontes: texto principal 20 px ou mais, rotulos 22 px ou mais, alvos 64 px ou mais (zoom do totem 0,67).

### 6. Isolamento das extracoes
Em processarProximaOcrDaFila: recognize em try proprio; extrairNumeroNota em try proprio (falha = numero nulo,
candidatos seguem); extrairCandidatos em try proprio (falha = candidatos vazios, numero preservado);
aplicarResultadoOcrNumero e identificarClienteNota em try/catch separados no finally, com catch na promise da
identificacao, e processarProximaOcrDaFila e notificarFimDeTrabalhoNotas sempre executados. Logs so com nome da
etapa e nome do erro, sem mensagem nem objeto (tambem em 3486 e 3526).

### 7. Inatividade
Date.now por performance.now (monotonico) no teto de 120 s (linhas 518-519 e comentario 456); tempos de 180 s, 30 s
e 120 s inalterados; o sentinela zero de suspensaoInicioMs continua valido; o harness precisa virtualizar Date.now,
performance.now e timers no mesmo relogio e testar salto so de Date.now. O polling de documentos (1860-1861) usa o
mesmo mecanismo: incluir (recomendado, decisao).

### 8. Dependencias e higiene
- Fixar versoes sem atualizar bibliotecas: mover os arquivos exatos para pasta versionada
  public/totem/assets/vendor/tesseract-5.1.1 (tesseract.min.js, worker.min.js, 4 cores, por.traineddata.gz) e
  vendor/jsqr; manifesto MANIFEST.sha256 versionado e script CLI de verificacao (tools/verificar-vendor.php,
  PHP_SAPI cli); caminho versionado para corePath e langPath (query nao funciona: o worker concatena o nome do
  arquivo); tesseract.min.js e qr-worker.js com ?v=filemtime; importScripts do jsQR com caminho versionado;
  versao do core, da traineddata e do jsQR nao consta nos arquivos (identidade = SHA-256); cacheMethod refresh
  ou none para evitar traineddata antigo no IndexedDB; provar zero requisicao a CDN na aba Network.
- Ignorar /testeOcr/ no .gitignore (fotos reais; nao remove do disco); fixtures sinteticas em
  tests/fixtures/sinteticas (geradas por script, dados ficticios, gabarito JSON, prefixo sint_); historico com
  docs/.claude encerrado (sem reescrita).
- Codigo morto seguro: numeroModalFila (nunca lida), .selo-numero no CSS, comentarios obsoletos; ocrConcluido
  passa a ser usado pelo Voltar; numeroModalAberta NAO e morto (mutex dos modais); os 23 eventos de medicao sao
  contrato aprovado: manter os 4 nunca emitidos (ocr_fila_cli, res_aplic_cli, res_descart_cli, falha_ocr_cli) salvo
  decisao formal; remover do backend/front nada alem do confirmado por Grep.
- LGPD em logs: trocar getMessage por logFalhaTecnica em NotaFiscalRn (184, 234, 263); nos demais (DocumentoRn,
  ImpressaoAtendimentoController, ImpressaoTesteController, ConexaoGestaoColetas) so registrar observacao.

## Arquivos previstos (PROPOSTA)
- Backend alterar: NotaController, AtendimentoController (concluirDigitalizacao), NotaFiscalRn (identificacao,
  avaliarClienteDoAtendimento, numeroNotaValido, exclusao, logs), AtendimentoRn (concluir transacional),
  AtendimentoNotaDao (por id_nota, proxima ordem livre, excluir, listar para update, escrita unica),
  AtendimentoDao (buscar para update), ClienteDao, EmpresaDao (listarCnpjsAtivos), RazaoSocialMatcher (campo
  ambiguo), CnpjValidador (delega), Resposta (erroComDados), public/api/nota.php (case excluir).
- Backend criar: util/IdentificadoresUdlog.php, util/NotaArquivoStorage.php, cron/limpar-notas-quarentena.php,
  suites em tests/manual (padrao teste_* e _caso_*, banco qa_ descartavel, STORAGE_PATH temporario).
- Front: public/totem/assets/app.js, app.css, qr-worker.js, index.php (?v e versoes), pasta vendor versionada.
- Higiene: .gitignore (/testeOcr/), tests/fixtures/sinteticas, tools/verificar-vendor.php.
- Migration: nenhuma no desenho principal (alternativa 018 com status_ocr CLIENTE_CONFLITO so se D4 mudar).

## Fases e ordem de deploy (PROPOSTA; cada fase com /01 a /04 proprio)
1. Front sem dependencia de backend: Voltar (com desfazer manualOverride), try isolados, performance.now, toast,
   codigo morto seguro, ?v nos assets, .gitignore de testeOcr. Reversivel com git revert.
2. Backend compativel e aditivo: lock/transacao, id_nota, UDLOG centralizada, regra de cliente (avaliar),
   excluir + quarentena + cron, contratos aditivos. Nenhum campo removido ou renomeado; o 422 fica atras de flag.
3. Front novo: uid, Excluir, Adicionar outra nota, tratamento do 422, identificacao sem gate, vendor versionado.
4. Ativacao do 422 (flag) depois do front novo no ar e confirmado no totem (sem abas antigas).
Deploy: backup; migration (se houver) antes do PHP; classes novas antes dos entrypoints; cron novo no cPanel;
permissao de escrita e remocao em STORAGE_PATH para o usuario do PHP (unlink exige escrita na pasta); front por
ultimo; smoke test (sem requisicao a CDN, exclusao remove o arquivo, LGPD, 422, cliente, CNH/CRLV, ?medir=1).
Rollback: git revert por fase; front novo contra backend antigo falha seguro (excluir mostra erro e mantem a nota).

## Matriz de testes (resumo; 32 itens do pedido cobertos)
- Cliente: C1 CNPJ unico; C2 razao social inequivoca; C3 UDLOG + cliente; C4 so UDLOG; C5 mesmo cliente em varias
  notas; C6 clientes distintos entre notas; C7 duas correspondencias na nota; C8 fuzzy com empate; C9 nenhuma;
  C10 cliente inativo; C11 UDLOG como linha de tb_cliente; C12 escrita unica e idempotencia; C13 ordem de chegada;
  C14 excluir nota conflitante resolve; C15 sem early-stop.
- 422: todas numeradas; pendentes = 422 com corpo exato e etapa e cliente inalterados; numero legado invalido
  como pendente; clique duplo; idempotencia; processar tardio = 400; corpo sanitizado.
- Exclusao: confirmada; clique duplo (sequencial e concorrente); arquivo ausente; falha de banco no DELETE
  (rollback e arquivo restaurado); falha ao remover .del; falha ao quarentenar; excluir x concluir em N rodadas;
  posse e id_nota de outro atendimento; caminho forjado e arquivo invalido no banco; reuso de ordem e limite de 5;
  definir-numero e identificar-cliente em nota excluida; excluir a nota que identificou o cliente; concluir com 0
  notas; doctos[] com ordens nao contiguas; zero POST real ao Talent; zero impressao; logs sem dado; timeout de
  lock = 503; dois processar concorrentes nao apagam arquivo legitimo.
- Front (harness do zero; relogio virtual para Date.now, performance.now e timers): Voltar sem alteracao (todos os
  estados) e desfaz manualOverride; toque fora dos modais; excluir confirmado e cancelado; clique duplo; exclusao com
  OCR em andamento; resultado tardio apos exclusao e apos cancelamento; excluir a unica nota; uma entre cinco;
  adicionar depois; limite de cinco; 422 destaca pendentes; toast sem cobrir controles; numero preservado quando o
  cliente falha e vice-versa; teto 120 s com performance.now e salto so de Date.now; zero CDN; regressao e 23 eventos.
- Mutacoes criticas: achar por ordem em vez de uid; remover guard; Voltar chama definir-numero; Voltar nao limpa
  override; fundo do modal fecha ou confirma; excluir sem confirmacao; sem trava de clique duplo; nao purgar
  ocrFila; Adicionar com 5; nao voltar a captura com 0; Continuar com pendente; destacar ordem errada; remover try
  isolado; voltar a Date.now; break no primeiro CNPJ; early-stop de volta; UDLOG sem filtro no fuzzy; caminho do
  front aceito; unlink antes do commit.

## Riscos
Lock em transacao com OCR concorrente (transacao curta, timeout 5 s, 503 mapeado); rename no Windows (XAMPP dev)
com arquivo aberto por antivirus (producao Linux, mesmo filesystem); cron novo a configurar; matriz e filial como
linhas distintas em tb_cliente geram ANOMALIA (fail-closed); exclusao fisica e irreversivel apos o cron; unificar
os arrays de notas troca a chave em 5 pontos; mover a pasta do Tesseract afeta deploy e cache do totem.

## Decisoes
Bloqueantes: D1 persistir cliente_nome e cliente_cnpj no concluir quando IDENTIFICADO (hoje o check-in automatico
pode falhar por cnpj_depositante_invalido; ninguem confirmou o caminho automatico de ponta a ponta no Talent); D2
lista oficial dos identificadores da UDLOG (filiais, raiz, razao social, variacoes); D3 exclusao fisica com
quarentena e cron (e retencao de fotos de cancelados e abandonados, hoje sem limpeza); D4 id_nota no contrato
(recomendado por front, backend e seguranca); D5 422 em concluir-digitalizacao (recomendado) com flag; D6
Adicionar outra nota sempre que houver menos de 5 (revoga o sem retorno a captura) ou so depois de excluir.
Confirmacoes com padrao recomendado: fuzzy ambiguo = anomalia; ERRO representa conflito (sem migration 018);
desativar identificacao pelo caminho chave; validar cliente manual contra tb_cliente ativa e UDLOG; Excluir so
dentro dos modais (conferir e digitar); manter ou remover Cancelar atendimento dentro dos modais; cor #FBECEC ou
so borda e selo; toast 6 s; mover a pasta do Tesseract e cacheMethod; incluir polling em performance.now; manter
os 4 eventos de medicao nunca emitidos; neutralizar o aviso Cliente identificado; texto da anomalia de cliente.

## Pendencias nao confirmadas
Conteudo real de tb_cliente e tb_empresa (filiais da UDLOG?); InnoDB em producao; versao do core, traineddata e
jsQR; comportamento offline e sobreposicao real do toast; divergencia de 5 x 3 arquivos em testeOcr/; se o fluxo
automatico de cliente ja foi validado no Talent; lista de suites que quebram com o 422 (confirmar rodando).

## Proximo passo
Decidir D1 a D6 (e confirmar ou ajustar os padroes recomendados) e rodar /01-implementacao da fase 1.

## Decisoes aprovadas pelo usuario para a /01-implementacao (2026-09-30)
- D1 aprovado: gravar cliente_nome e cliente_cnpj no atendimento dentro da transacao de concluir-digitalizacao
  somente com exatamente um cliente automatico ativo; nao sobrescrever cliente manual confirmado.
- D2: tb_cliente ativa e a UNICA allowlist oficial; nao criar lista hardcoded da UDLOG (sem IdentificadoresUdlog e
  sem chaves UDLOG_* no .env); todo candidato que nao exista como cliente ativo e ignorado (inclusive
  transportadora e UDLOG). Preflight somente leitura no banco de dev: 38 clientes ativos, 0 coincidem com as
  empresas UDLOG (tb_empresa), 0 com a raiz 14706199, 0 com UDLOG ou UNITED LOG no nome, todos com CNPJ de 14
  digitos. O mesmo preflight precisa ser repetido no banco de PRODUCAO antes de ativar a regra (checklist).
- D3 aprovado: exclusao, cancelamento e abandono movem as fotos das notas para quarentena; retencao de 24 horas
  (substitui a 1 hora do desenho); cron DIARIO; criar script e checklist; configuracao do cPanel fica para o
  deploy final.
- D4 aprovado: id_nota imutavel no contrato de processar, usado na revisao e na exclusao.
- D5 aprovado: validacao obrigatoria de numero_nota em concluir-digitalizacao com HTTP 422 e flag inicialmente
  desligada (CONCLUIR_EXIGE_NUMERO_NOTA). Implantacao: backend desligado, front novo, testes, ativacao.
- D6: Adicionar outra nota aparece sempre que houver menos de 5 notas; se nenhuma restar, voltar automaticamente
  a captura.
- Todos os padroes recomendados do planejamento aprovados.
- Restricoes da /01: sem Talent real, sem impressao, sem banco real, sem producao, sem commit e sem push; banco
  QA descartavel apenas; parar se um preflight contradisser o planejamento.

## Situacao da /01-implementacao (auditoria do worktree, sem commit)
- Fase 1 (front sem backend): IMPLEMENTADA (Voltar com desfazer do manualOverride, try isolados, performance.now,
  toast, codigo morto, vendor versionado com manifesto, cacheMethod none, .gitignore). Smoke em Chrome headless
  passou (zero requisicao externa).
- Fase 2 (backend aditivo): IMPLEMENTADA (lock e transacao, id_nota, regra de cliente derivada, concluir com D1,
  422 atras da flag, excluir com quarentena, cancelar com quarentena, cron, cliente manual validado). Suites
  teste_hardening_* criadas (cliente, concluir_422, exclusao, cron_quarentena): AINDA NAO EXECUTADAS.
- Fase 3 (front novo: uid, Excluir, Adicionar outra nota, 422, cliente_atendimento) e fase 4 (ativar 422): nao
  iniciadas.
- O investigador apontou como divergencias itens que seguem as decisoes acima (retencao de 24 h; quarentena no
  cancelar; ausencia de IdentificadoresUdlog por D2).

## Resultado da fase 2 (backend) — executada e verde (2026-09-30)
Sem commit, sem migration, .env real e sql/ intactos, zero Talent real, zero impressao, zero VIO. Testes rodados
em bancos qa_ descartaveis (todos dropados; bancos qa_ antigos nao tocados); banco de dev somente leitura
(tb_atendimento_nota 113, tb_atendimento 156, tb_cliente 38, inalterados). Auditoria A a I: todas FEITO.
- Suites novas: teste_hardening_cliente 104, concluir_422 56, exclusao 192 (concorrencia E13 com 12 rodadas),
  cron_quarentena 42; 3 rodadas completas sem variacao, 0 falhas.
- Suites existentes: 27 suites verdes (por exemplo integridade_conclusao 43/43, e2e 30/30, identificar_cliente 38,
  salvar_etapa_cliente_manual 6/6, talent_payload 45/45, rate_limit 24/24, jpeg_seguro 22/22, vio 46+83).
  Nao executadas: teste_preparacao_producao_checkin (exige totem real do dev), lgpd_aceite_backend (P23
  preexistente: falta _fixtures_lgpd.php), consulta_ordem_coleta e ordem_coleta_pendente_baixa (banco externo).
- 1 bug de codigo corrigido: regex de pasta_documentos em NotaArquivoStorage exigia 1 a 10 caracteres de placa, mas
  UploadHelper::montarPasta pode gerar pasta sem placa (AAAA-MM-DD/_HHMMSS); ajustado para 0 a 10 (so [A-Z0-9]);
  log de definir-numero restaurado com ordem. Suites antigas atualizadas ao novo contrato sem enfraquecer
  (caso 1, 6, 7 e 11 de identificar_cliente; cliente manual agora exige tb_cliente ativa; helper _caso_salvar_etapa
  com PDO; mocks de sanitizacao).
- Retencao do cron: 86400 s (24 h) pelo mtime do .del; cron so CLI, sem banco, log agregado sem caminhos.

### Contratos finais (todos POST JSON com Auth::validarTotem; sucesso {sucesso true, dados}; erro {sucesso false, erro})
- processar: request id_atendimento, imagem, chave (nula), ordem (OPCIONAL; ausente = servidor aloca a primeira
  livre; informada e ocupada = 400). 200 dados {cliente_identificado false, cliente null, id_nota, ordem}. Erros 400
  (dados, ordem, imagem, salvar imagem, ordem ja existe, limite de 5, status, etapa), 404 atendimento, 500, 503.
- definir-numero: request id_atendimento, id_nota e/ou ordem (se ambos, devem coincidir), numero, origem OCR ou
  MANUAL. 200 dados {numero_nota normalizado, id_nota, ordem}. 404 nota nao encontrada (inclui excluida, id antigo
  apos reuso de ordem, outro atendimento, divergencia id x ordem); 409 duplicado (NUMERO_NOTA_DUPLICADO); 400
  numero invalido; 503.
- identificar-cliente: request id_atendimento, id_nota e/ou ordem, cnpjs_candidatos (max 10), razao_social_candidata;
  200 dados {status IDENTIFICADA|NAO_IDENTIFICADA|ERRO, motivo null|MULTIPLAS_CORRESPONDENCIAS|
  RAZAO_SOCIAL_AMBIGUA|ERRO_TECNICO|INDETERMINADO, cliente null|{id,razao_social,cnpj}, id_nota,
  ja_identificado_no_atendimento, cliente_atendimento {estado IDENTIFICADO|NAO_IDENTIFICADO|ANOMALIA, cliente}};
  resultado escrito uma vez por nota (idempotente); 429 rate limit 30/min inalterado.
- excluir (NOVO): request id_atendimento, id_nota (caminho/arquivo/pasta/ordem ignorados). 200 dados {excluida,
  ja_excluida, id_nota, total_notas, notas [{id_nota, ordem, numero_definido}], cliente_atendimento}; id_nota
  inexistente no atendimento = 200 com ja_excluida true; 400 status ou etapa errados; 404 posse; 500 falha
  (foto restaurada); 503 timeout de lock.
- concluir-digitalizacao: 200 dados {proxima_tela rec_cnh|rec_cliente, etapa rec_cnh|cliente, cliente_estado,
  cliente_motivo null|CONFLITO|INDETERMINADO}; idempotente. 422 (so com a flag ligada) corpo exato: sucesso false,
  erro "Existem notas fiscais sem numero definido (NOTAS_SEM_NUMERO)", codigo NOTAS_SEM_NUMERO, dados {ordens_pendentes
  [inteiros 1 a 5], total_notas, total_pendentes}; ROLLBACK (etapa e cliente inalterados).
- salvar-etapa cliente: exige tb_cliente ativa (400 Cliente invalido ou nao cadastrado); nome e CNPJ vem do banco.
- cancelar: fotos das notas vao para quarentena (melhor esforco; nunca altera a resposta).
- Flag CONCLUIR_EXIGE_NUMERO_NOTA: so o literal true liga; ausente, vazia, false, TRUE, 1 ou com espaco = desligada.

### Riscos e decisoes desta fase
ERRO_TECNICO persiste como ERRO (escrita unica, fail-closed): uma falha transitoria empurra o atendimento para
confirmacao manual de cliente sem reprocessar aquela nota (excluir e adicionar de novo refaz) — mantido; matriz e filial
como linhas distintas geram ANOMALIA; cancelar nao e atomico com a quarentena; se o cron nao estiver configurado os
.del acumulam; D1 nunca validado de ponta a ponta no Talent real; bancos qa_iso2 e qa_lgpd_visual_1790279297
suspeitos de rodadas interrompidas (nao apagados); suites legadas rodadas "cruas" escreveriam linhas temporarias em
tb_cliente do dev (rodar so pelo wrapper QA).

## Resultado da fase 3 (front novo) — implementada e verde no smoke (2026-09-30), sem commit
Arquivos: public/totem/assets/app.js e app.css (index.php, backend, tests e tools intactos). node --check e git diff
--check limpos; Grep: 0 notasImagens, 0 notaOrdem, 0 gate clienteIdentificado, 0 envio de ordem no processar, 0 find por
ordem em OCR, modais e revisao, nenhum console novo.
- uid por nota (notaUidSeq por atendimento); modelo {uid, ordem, idNota, imagem, numero, confirmado, origem, estado,
  sugestao, sugestaoOcr, ocrConcluido, manualOverride, destaque}; o uid nasce na RESPOSTA do processar (depois do guard de
  geracao: upload falho ou cancelado nao deixa nota fantasma); resultado de OCR aplicado por uid (inexistente = descarte).
- Upload sem ordem (servidor aloca; fallback por ordem devolvida se faltar id_nota); definir-numero e identificar-cliente
  enviam so id_nota; 404 com uid inexistente e ignorado, com uid existente mostra mensagem fixa e nao confirma.
- Excluir nota so nos modais (vermelho, 64 px, divisor, folga minima de 57 px ate o Voltar e 135 px ate as teclas);
  overlay de confirmacao z-index 56 (Nao, voltar em cima e com foco; Sim, excluir embaixo; clique duplo bloqueado;
  toque fora e Esc nao fazem nada); sequencia: valida, trava, chama excluir, reavalia guard, so entao remove do estado,
  purga a ocrFila por uid e fecha; falha mantem a nota; ja_excluida = sucesso; restam notas = fica na revisao com faixa
  Nota N excluida (5 s); zero notas = volta a captura com mensagem.
- Adicionar outra nota sempre com menos de 5 (area rolavel, abaixo dos cartoes; chama so ir rec_digitaliza sem reset).
- 422: api() propaga codigo e dados; destaque so das ordens validas (inteiros 1 a 5), borda e selo Falta o numero, rolagem
  ao primeiro, mensagem fixa gerada localmente (nunca texto do backend); funciona tambem com a flag desligada.
- Cliente: identificar-cliente para toda nota (sem gate; 429 sem retry agressivo), so cliente_atendimento.estado;
  concluir usa proxima_tela (rec_cnh vira rec_cnh_modo; ausente ou desconhecida cai em rec_cliente, fail-closed);
  aviso Cliente identificado removido; rec_cliente com texto neutro igual para nao identificado e anomalia, campo de
  20 px e 64 px, sem candidatos nem pre-preenchimento; botao Avancar tambem em 64 px.
- Alturas medidas (768x1366 e 1152x1846): modal de conferir 708 px, de digitar 872 px, overlay de confirmacao 324 px, sem corte.
- Smoke em Chrome headless (stubs seguindo os contratos finais; rede externa bloqueada): 145 verificacoes, 0 falha,
  0 pageerror; cobre (a) a (i) do pedido, inclusive OCR tardio nao cair na nota nova que reutilizou a ordem e ?medir=1 com
  23 eventos.
- Nao validado: integracao com o backend REAL (so stubs), 422 real, Esc por teclado real, aviso de inatividade sobre o
  overlay, camera Netum, zoom 0,67 fisico.
- Pontos de decisao: destaque por toque (implementado: limpa so a nota tocada); 18 px de folga entre Excluir nota e Cancelar
  atendimento (dois botoes destrutivos proximos); mensagem fixa em todo 400 do upload (perde o detalhe do backend);
  backend antigo sem id_nota deixa a exclusao bloqueada (falha segura).

## /02-testes (4 revisores independentes) - VEREDITO: PRECISA DE AJUSTE

Ajuste previo autorizado: separacao Excluir nota x Cancelar atendimento = 99 px, separador 2 px #3A3A3A e rotulo "Atendimento". Nenhuma outra regra ou contrato alterado.

Resultado por revisor:
- qa-testes (integracao front+backend, banco QA, Talent mock): PRECISA DE AJUSTE (2 falhas: F1 e F7). 10/10 mutantes detectados, 0 rede externa.
- frontend/UX: APROVADO com achados de baixa severidade (F9).
- backend-especialista: PRECISA DE AJUSTE (F1, F2, F3, F4, F5). Suites novas (104/56/192/42) e 27 legadas verdes; 76 testes de numero/422; 107 de concorrencia, 0 violacoes.
- security-especialista: PRECISA DE AJUSTE (F1 critico, F2).

Falhas reais (nao corrigidas; /02 nao altera codigo):
- F1 CRITICO: avaliarClienteDoAtendimento/concluir ignora notas PENDENTE/PROCESSANDO/NAO_IDENTIFICADA. Nota 1 = cliente X, nota 2 pendente: concluir 200 rec_cnh e grava X; resultado tardio da nota 2 recebe 400 (etapa errada), conflito nunca detectado. Reproduzido por 3 revisores, flag ligada ou desligada.
- F2: cron sem limite por lote (5000 .del apagados em uma execucao, exigencia do usuario nao atendida); scandir com falha vira sucesso silencioso (exit 0).
- F3: regex de NotaArquivoStorage sem D/\z aceitam "\n" final.
- F4: restaurar() falso na exclusao nao e logado.
- F5: (backend) detalhes menores de log/erro conforme relatorio.
- F7: nota orfa se a resposta do processar se perde (front sem uid; 422 devolve ordens_pendentes sem nota visivel; sem saida pela UI, so cancelar). Tratar antes de ligar a flag.
- F8: #modalCaixa e #modalConfirmExcluirNotaCaixa mantem HTML da foto apos fechar; tratarNotasSemNumero nao reseta manualOverride (Lendo... preso).
- F9 menores: fontes (Confirmar/Corrigir 18px, badges 16px, Atendimento 20 vs 22), contraste de botao desabilitado, state.clienteEstado/clienteMotivo mortos, fixtures sinteticas vazias, folga Voltar-Excluir 57 px (<64).
- Politica pendente: retencao de fotos de atendimentos in_progress abandonados e nunca cancelados.

Passou: contratos HTTP (processar, definir-numero, identificar-cliente, excluir, concluir 200/422 corpo exato), identidade por id_nota/uid, regra de cliente (exceto F1), 422 atomico, exclusao/quarentena, vendor/manifest, inatividade, geometria 768x1366 e 1152x1846, 0 Talent real/impressao/rede/producao, preflight limpo (0 coincidencias UDLOG).

Residuos: bancos qa_r3qa_*/qa_r3be_* removidos; qa_iso2 e qa_lgpd_visual_1790279297 intactos; 4 *_cron.log no scratchpad (remocao bloqueada); processos php -S de terceiros nao tocados.

/03-revisao NAO iniciada. Proximo passo depende de decisao do usuario: rodada de ajuste de /01 para F1-F9.

## Rodada corretiva R4 - frontend fase A (F8 e F9) - 2026-10-01, sem commit
Arquivos: public/totem/assets/app.js, app.css, .gitignore (so o trecho de fixtures), tests/manual/front_r4_f8_f9.js (novo); removida a pasta tests/fixtures/ (so tinha README). index.php sem mudanca (versao dos assets ja e filemtime). F1 (409) e F7 (uid) NAO feitos: dependem de contrato do backend (fase B).
- F8: limparCaixaModal(id) tira o src de toda img e esvazia a caixa; chamada por fecharModal, abrirModal (troca Corrigir), fecharConfirmacaoExcluirNota e fecharConfirmacaoCancelarNotaModal (cobre Voltar, Nao voltar, excluir, ir() e fechamento programatico). tratarNotasSemNumero zera manualOverride das notas apontadas e de qualquer nota nao confirmada com override orfao (fora a do modal aberto) e reconstroi o estado por recalcularEstadoOcrNota.
- F9: Confirmar/Corrigir 18px/600 -> 20px/700 (escopo so do modal de conferir; igual a Voltar/Excluir/Continuar); badges 16 -> 18px/700; rotulo Atendimento 20 -> 22px (= Nota N de M); desabilitado sem opacity: fundo #B0B0B1, texto #3A3A3A, borda #B0B0B1 (contraste 5,25:1; antes 2,44:1 no Confirmar e 3,04:1 no Excluir); Voltar -> Excluir 57 -> 65 px (divisor 14 -> 18 px de margem); Excluir -> Cancelar atendimento 99 -> 102 px; caixa 789/953 -> 800/964 px (cabe em 768x1366 e 1152x1846); state.clienteEstado/clienteMotivo e consts CLIENTE_ESTADOS/MOTIVOS removidos (so escritos, nunca lidos; busca global em app.js e tests).
- Fixtures: nenhum teste usa imagem de arquivo (o teste gera SVG em memoria); excecao `!tests/fixtures/sinteticas/` removida do .gitignore (`tests/fixtures/*` segue ignorado) e pasta removida.
- Teste: node tests/manual/front_r4_f8_f9.js = 77 verificacoes, 0 falha; 14 mutantes (copias servidas via APPJS/APPCSS), 14 mortos; hashes dos arquivos do repo inalterados. Smoke antigo (scratch) 141/145: as 4 falhas sao a asserção do state.clienteEstado removido.

## Rodada corretiva /01 (BACKEND) - F1, F2, F3, F4, F7 e abandono (2026-10-01)

Escopo: so backend (app/, util/, public/api/, cron/, sql/migrations/, tests/). Nada commitado, zero Talent/VIO/impressao reais,
`.env` e `sql/schema.sql` intactos. Testes somente em bancos `qa_r4be_*` descartaveis (todos dropados) com STORAGE_PATH temporario.
A frente (app.js/app.css/index.php) foi de outro agente.

### F1 - concluir-digitalizacao bloqueado com OCR em andamento (decisao do usuario)
- Sob o lock do atendimento, na MESMA transacao que depois grava o cliente, todas as notas ativas sao travadas e relidas. Qualquer
  nota ativa PENDENTE ou PROCESSANDO dentro do teto de OCR => HTTP 409, ROLLBACK, `etapa_atual`, `cliente_nome` e `cliente_cnpj`
  inalterados. Vale com `CONCLUIR_EXIGE_NUMERO_NOTA` ligada ou desligada.
- Corpo EXATO do 409 (`Resposta::erroComDados`):
  `{"sucesso":false,"erro":"Ainda ha notas fiscais em processamento (OCR_EM_ANDAMENTO)","codigo":"OCR_EM_ANDAMENTO","dados":{"ordens_em_processamento":[2]}}`
  `ordens_em_processamento` = inteiros de 1 a 5, ordenados, SO as notas ainda em processamento. Nunca id_nota, uid, caminho, imagem,
  texto de OCR, CNPJ ou nome.
- Estado real encontrado: NAO existia timeout server-side. Nenhum codigo grava PROCESSANDO; uma nota cujo OCR do navegador nunca
  chegasse ficaria PENDENTE para sempre (bloqueio indefinido). DECISAO (minima e segura): o teto e o MESMO ja aprovado para o
  trabalho de OCR no front (`INATIVIDADE_TETO_SUSPENSAO_MS` = 120000, 120 s), contado do upload da nota (`tb_atendimento_nota.criado_em`,
  relogio do banco: `TIMESTAMPDIFF(SECOND, criado_em, NOW())`). Constante `NotaFiscalRn::OCR_TIMEOUT_SEGUNDOS = 120`; fronteira
  testada: 119 s bloqueia, 120 s expira. Nota PENDENTE/PROCESSANDO com 120 s ou mais NAO bloqueia: conta como cliente
  INDETERMINADO => `cliente_estado` ANOMALIA, `cliente_motivo` INDETERMINADO, `proxima_tela` rec_cliente, `etapa` cliente, NENHUM
  cliente persistido (fallback manual, fail-closed, mesmo que outra nota tenha identificado um cliente). Sem contrato novo para o
  front: o front so precisa tratar o 409 (esperar/tentar de novo) e saber que, passados 120 s do upload, o servidor para de bloquear.
- Depois da segunda nota terminar: mesmo cliente => 200 rec_cnh e cliente persistido; nenhum cliente => 200 rec_cliente
  (NAO_IDENTIFICADO), nada persistido; cliente diferente => 200 rec_cliente ANOMALIA/CONFLITO, nada persistido.
- Resultado tardio: identificar-cliente/definir-numero depois do concluir (etapa avancada), em atendimento cancelado ou concluido =
  HTTP 400 e nenhuma escrita (ja era assim por etapa/status; agora testado).
- Precedencia (documentada no codigo e testada): 400 (posse/status; zero notas ou mais de 5) > 409 OCR_EM_ANDAMENTO > 422
  NOTAS_SEM_NUMERO > decisao de cliente. O 409 vence o 422 porque, com OCR em curso, numero e cliente da nota ainda podem chegar. O
  ramo idempotente (etapa ja avancada) usa a mesma avaliacao e continua respondendo 200 com o mesmo corpo.
- Efeito colateral nos testes: fixtures antigas criavam notas PENDENTE e concluiam; `hdIniciar` (hardening_helpers) agora marca
  as notas PENDENTE como NAO_IDENTIFICADA antes de rodar `atendimento.concluir`, salvo `sem_terminalizar => true`; 2 suites legadas
  (integridade_conclusao item 6 e e2e_recebimento_expedicao_mock) passaram a gravar a nota terminal antes de concluir.

### Abandono e retencao de fotos
- Politica: atendimento `em_andamento` sem atividade ha 24 h ou mais vai ao estado terminal JA EXISTENTE `cancelado` (o mesmo do
  `cancelar`/inatividade do front; `bloqueado` e outra coisa: excesso de notas; `concluido` e imutavel). As fotos das notas vao para
  quarentena (`nota_NN.jpg.<id_nota>.del`, mtime renovado) e depois ficam mais 24 h ate `cron/limpar-notas-quarentena.php`.
- Onde roda: NOVO script CLI `cron/abandonar-atendimentos.php` (diario; precisa de banco, so PDO prepared via `Util\Bootstrap`;
  o cron de limpeza continua sem banco). Logica em `App\Rn\AbandonoAtendimentoRn`; SQL em `AtendimentoDao`
  (`listarCandidatosAbandono`, `buscarParaAbandonoParaUpdate`, `marcarAbandonado`). Nunca roda no web (PHP_SAPI !== cli, 403/exit 1).
- Criterio de "sem atividade" com colunas EXISTENTES (nao ha coluna dedicada de ultima atividade): o MAIOR entre
  `tb_atendimento.atualizado_em` (ON UPDATE CURRENT_TIMESTAMP) e, entre as notas, `criado_em`/`processado_em`.
  Nao conta: editar so o `numero_nota` (sem timestamp) nem leitura. Candidato = `em_andamento` E
  `talent_checkin_status` em (NAO_ENVIADO, ERRO_REPROCESSAVEL): envio ao Talent em curso/aceito (ENVIANDO, ENVIADO,
  ENVIO_INDETERMINADO) NUNCA e abandonado (decisao conservadora minha, ver pendencias).
- Consistencia por atendimento, uma transacao curta: BEGIN; lock da linha; REVALIDA sob lock (status, Talent, inatividade); rename
  das fotos (cada uma com compensacao); UPDATE CAS em_andamento -> cancelado exigindo 1 linha; COMMIT. Falha antes do COMMIT (rename,
  UPDATE, CAS perdido ou COMMIT) devolve as fotos (rename de volta, ainda sob lock) e faz ROLLBACK: continua em_andamento e a proxima
  execucao tenta de novo. Falha do restaurar e logada com contexto fixo e a foto fica em quarentena (recuperavel).
- Atendimento ativo/recente nunca e candidato; se ficar ativo entre a listagem e o lock, a revalidacao o ignora.
- Limite: 500 atendimentos por execucao (`AbandonoAtendimentoRn::LIMITE_POR_EXECUCAO`); o resto fica para a proxima.
- Foto sem caminho valido (pasta/arquivo fora do formato, legado): abandona sem tocar disco, conta `fotos_sem_caminho_valido` e o
  cron sai com 1 (anomalia visivel).
- Saida: 0 = sucesso (inclusive limite atingido); 1 = .env/banco/STORAGE_PATH indisponivel, atendimento que falhou ao abandonar ou
  foto sem caminho valido. Log SEMPRE agregado (candidatos, abandonados, ignorados, falhas, fotos em quarentena, sem caminho, limite).
- Escopo: so as fotos das notas (mesmo do cancelar). CNH/CRLV do atendimento abandonado NAO sao movidas/apagadas (pendencia).

### F2 - cron de quarentena com limite e sem falso sucesso
- Maximo de 500 `.del` removidos por execucao (`NotaArquivoStorage::LIMITE_REMOCOES_POR_EXECUCAO`), parada imediata; limite
  atingido nao e falha (saida 0, log "ATINGIDO"). Varredura por `opendir/readdir` (uma entrada por vez; sem `scandir`/`glob`).
- Falha de leitura: raiz que nao abre = `raiz_indisponivel` (saida 1, "nenhuma exclusao iniciada"); subdiretorio que nao abre ou
  existe mas nao resolve (permissao) = `erros_leitura` (log "N diretorio(s) ilegivel(is)" + "varredura INCOMPLETA", saida 1).
  Nunca "0 apagados" com saida 0 depois de erro de leitura.
- Medido: backlog de 5300 `.del` = 11 execucoes (10 x 500 + 1 x 300); backlog de 5200 num diretorio nao cresceu a memoria
  (59 KB acima da linha de base).

### F3 - regexes ancoradas ao fim real
- As 5 regexes de `NotaArquivoStorage` (pasta, arquivo, dir de data, dir de atendimento, nome de quarentena) terminam com o
  modificador `D`. Rejeitados: newline, CR, NUL, separador, traversal, extensao adicional, espaco e sufixo invisivel (U+200B).
  Bonus: `NotaFiscalRn::numeroNotaValido` (mesmo defeito: `123` + newline passava) ganhou `D`; o uid novo ja nasce com `D`.

### F4 - restaurar() com log fixo
- Na compensacao do `excluir`, `restaurar()` falso agora grava `excluir-nota: FALHA ao restaurar a foto da quarentena apos rollback;
  foto permanece em quarentena (id_atendimento=N id_nota=N)`: so ids inteiros (nunca caminho, nome, pasta, excecao, trace). Banco e
  disco ficam consistentes: ROLLBACK (linha existe) e foto inteira em quarentena; um novo excluir da mesma nota reconhece
  `ja_em_quarentena`, apaga a linha e remove o `.del`. Provado com falha REAL (um diretorio no caminho original bloqueia o rename de
  volta) e controlada, combinadas com falha real do DELETE.

### F7 - uid idempotente e reconciliacao (CONTRATO PARA O FRONT)
Migration ADITIVA `sql/migrations/018_nota_client_uid.sql` (aplicar DEPOIS da 017 e ANTES do PHP novo): coluna
`tb_atendimento_nota.client_uid VARCHAR(64) NULL` + `UNIQUE KEY uk_atendimento_client_uid (id_atendimento, client_uid)`. Idempotente
(INFORMATION_SCHEMA + PREPARE, sem stored procedure), reaplicar 2x e no-op; reversao documentada no proprio arquivo
(`DROP INDEX uk_atendimento_client_uid` + `DROP COLUMN client_uid`, so perde o vinculo uid -> nota). `qa_db_bootstrap.php` aplica a 018.
O caminho SEM uid nao toca a coluna (INSERT antigo), entao front antigo funciona mesmo antes da migration.

`POST nota.php?acao=processar` (Authorization: Bearer do totem; corpo JSON), campo NOVO opcional `uid`:
- formato `^[A-Za-z0-9_-]{8,64}$` (regex com `D`); string vazia = ausente; invalido/nao string = 400
  `{"sucesso":false,"erro":"Identificador da nota invalido"}`.
- uid novo no atendimento: cria a nota como antes. 200 `dados`:
  `{"cliente_identificado":false,"cliente":null,"id_nota":N,"ordem":N,"uid":"<uid>","reaproveitada":false}`.
- uid JA existente NESTE atendimento (retry apos resposta perdida): 200 com a MESMA nota, sem escrita no banco nem no disco:
  `{"cliente_identificado":false,"cliente":null,"id_nota":N,"ordem":N,"uid":"<uid>","reaproveitada":true}`. A imagem e ignorada
  (nao regrava) e pode ser OMITIDA no retry (so com `uid`). uid desconhecido SEM imagem = 400 `Dados incompletos`. Vale mesmo com 5
  notas (retry de uid existente passa; uid novo continua 400 limite de 5). `ordem` informada e ignorada quando o uid ja existe.
- Sem `uid`: comportamento e corpo identicos aos anteriores (sem `uid`/`reaproveitada`).
- Escopo/IDOR: busca SEMPRE por (id_atendimento, uid) sob o lock do atendimento. O mesmo uid em OUTRO atendimento cria nota propria
  (nunca devolve a alheia); atendimento de outro totem ou inexistente = 404 `Atendimento nao encontrado` com corpo IDENTICO.
- Concorrencia real (6 processos, mesmo uid, 4 rodadas): exatamente 1 nota, 1 arquivo, mesmo id_nota, 1 `reaproveitada:false`.
- Excluir a nota libera o uid (um uid reutilizado depois de excluir cria nota nova; a recuperacao nunca ressuscita nota excluida).

`GET|POST nota.php?acao=listar` (NOVA acao, somente leitura; Bearer do totem): `id_atendimento` na query (GET) ou no corpo JSON (POST).
- 200: `{"sucesso":true,"dados":{"total_notas":N,"notas":[{"id_nota":N,"ordem":N,"uid":"<uid>"|null,"numero_definido":true|false}]}}`,
  ordenado por ordem, TODAS as notas ativas do atendimento (uid null = nota criada sem uid). Nunca arquivo, caminho, imagem, chave,
  CNPJ, numero ou status interno; nao altera banco nem disco (provado por snapshot).
- Erros: sem token/invalido 401; sem `id_atendimento` 400; atendimento inexistente, de outro totem ou de expedicao 404
  `Atendimento nao encontrado` (corpo identico entre os 3); fora de `em_andamento` 400 `Atendimento nao esta em andamento`; falha de
  banco 500 `Nao foi possivel consultar as notas`.
- Garante que o 422 (`ordens_pendentes`) e o 409 (`ordens_em_processamento`) nunca apontam nota invisivel: o front enumera por
  `listar`, mapeia ordem -> id_nota/uid e pode corrigir (`definir-numero` por `id_nota`) ou excluir (`excluir` por `id_nota`).
- Uso sugerido pelo front: gerar o uid ANTES do upload; se a resposta do processar se perder, repetir o processar com o mesmo uid
  (com ou sem imagem) OU chamar `listar` e casar por `uid`. Depois de reload logico, `listar` reconstroi id_nota/ordem/numero_definido.

### Testes (banco `qa_r4be_*`, tudo verde; cada suite nova rodou 2 vezes)
- Suites novas: `teste_hardening_r4_ocr_em_andamento` 62, `teste_hardening_r4_uid_reconciliacao` 95 (inclui rota HTTP real
  porta 8395-8399 com token), `teste_hardening_r4_storage_cron` 54 (cron CLI real, backlog 5300, icacls real negando leitura),
  `teste_hardening_r4_restaurar_log` 31, `teste_hardening_r4_abandono` 61 (cron CLI real; concorrencia real processar x cron).
- Suites da rodada anterior (mantidas): cliente 104, concluir_422 56, exclusao 192, cron_quarentena 42 = 394 verificacoes sem falha.
- 27 legadas por wrapper QA: 27/27 EXIT=0.
- Provas negativas: 29 mutacoes controladas em COPIA do projeto (cada uma revertida e conferida por sha256; os arquivos reais nunca
  foram mutados, sha256 identico antes/depois): 29/29 mortas.

### Pendencias desta rodada
- Abandono nao trata fotos de CNH/CRLV (so notas, como o cancelar); decisao de produto se tambem devem ir para quarentena.
- Nao existe coluna de ultima atividade: a edicao so do numero de uma nota nao renova a atividade (24 h e folgado).
- Envio ao Talent em curso/aceito nunca e abandonado: se um atendimento ficar eternamente em ENVIANDO/ENVIADO/ENVIO_INDETERMINADO
  sem concluir, nao ha rotina que o resolva (fora do escopo).
- O teto de 120 s e minimo seguro: se o OCR real do totem passar disso o motorista cai no fallback manual de cliente.
- Cron de abandono e de limpeza precisam ser agendados no cPanel (nao configurado); migration 018 nao aplicada em dev/producao.

## Rodada corretiva R4 - frontend fase B (F1 409 e F7 uid/reconciliacao) - 2026-10-01, sem commit
Arquivos: public/totem/assets/app.js, app.css; testes novos tests/manual/front_r4b_uid_409.js (stubs), front_r4b_real.js (front real x backend real),
front_r4b_backend.php e front_r4b_router.php (apoio do teste real). index.php, backend e a fase A (F8/F9) intactos. Contratos HTTP existentes nao mudam.
- F1 (409 OCR_EM_ANDAMENTO em concluir-digitalizacao): revisao fica aberta, etapa nao avanca, mensagem fixa em #revisaoAguarde (role=status, borda azul,
  nao e erro): "A leitura das notas ainda esta sendo concluida. Aguarde alguns segundos e toque em Continuar novamente."; Continuar reabilitado; ZERO retry
  automatico (so toque do motorista). Notas das ordens de ordens_em_processamento (inteiros 1..5, mapeadas por ordem) ganham destaque neutro (borda e selo
  azuis "Leitura em andamento", o numero segue visivel); sai ao tocar na nota e ao nova tentativa. Antes de liberar o botao o front reconcilia via listar.
  409 de outro codigo ou sem codigo = erro generico (texto do backend nunca exibido); o 409 NUMERO_NOTA_DUPLICADO e do definir-numero (outra rota,
  mensagemErroSalvarNumero) e ficou como estava. Nunca bloqueia: sem timer no front; depois de 120 s do upload o servidor libera (ANOMALIA/INDETERMINADO ->
  rec_cliente) e o motorista pode tocar de novo; cancelar continua disponivel. Flag nova digitalizacaoConcluida: depois do 200 do concluir, resultado de OCR
  e identificar-cliente tardios sao descartados (nenhum identificar-cliente depois de concluir). Sem dado do 409 em console, medicao ou log.
- F7 (uid): uid do SERVIDOR = nota.clientUid (string; crypto.randomUUID, senao getRandomValues 32 hex, senao fallback Math.random+relogio+contador; regex
  ^[A-Za-z0-9_-]{8,64}$ conferida; Set por atendimento garante que nunca reutiliza, nem apos excluir). O uid LOCAL (state.notaUidSeq, numerico) continua
  sendo a chave de DOM/handlers/OCR (decisao: mapeamento clientUid <-> uid local; nada de DOM/handler mudou). uid e enviado no processar (upload continua sem ordem).
  Resposta com uid diferente do enviado nao e usada (erro, sem nota local); sem uid na resposta (backend antigo) e aceita.
- Retry: uploadPendente {clientUid, imagem, geracao, idAtendimento}. Falha AMBIGUA (sem status: rede/timeout/resposta ilegivel, ou 5xx) mantem o pendente; o
  Usar imagem seguinte reenvia a MESMA captura com o MESMO uid (idempotente; pode reenviar a imagem: mais simples e seguro) e reaproveitada:true e sucesso
  normal (cria o item local com a foto em memoria e roda OCR uma vez). Falha 4xx descarta o pendente. api() ganhou timeoutMs opcional (AbortController),
  usado so no upload (90 s) e no listar (20 s). Se o motorista abandona a captura com pendente (Refazer + nova captura, ou Finalizar), o front chama listar
  antes de seguir: a nota do servidor vira item local COM a foto que estava em memoria. Falha do listar nao trava nada (pendente mantido, repete no 422/409).
- Reconciliacao (reconciliarNotasComServidor): chamada apos 422 e 409 (antes de mapear ordens) e nos casos acima. So adiciona/atualiza ordem e idNota (nunca
  remove item local; ignora ids excluidos localmente, listar defasado nao ressuscita nota; ignora itens invalidos). Nota desconhecida vira item recuperado no
  FIM da lista local (posicoes das notas conhecidas nao mudam): sem foto (nao ha endpoint de imagem), estado "Nao consegui ler o numero", sem numero (listar
  nao devolve o numero), e identificar-cliente com candidatos vazios (para nao ficar PENDENTE no servidor e travar o concluir por 120 s). Placeholder
  "Foto indisponivel" no cartao, no modal e na miniatura da captura; informar numero (definir-numero por id_nota) e Excluir funcionam.
- Preservado: guard/token atendimentoGeracao, exclusao com confirmacao, Adicionar outra nota (<5), retorno a captura sem notas, vendor local, nenhuma chamada
  externa, 23 eventos ?medir=1 (nenhum evento novo; uid/ordens fora das medidas), CNH/CRLV/Expedicao, overlay z-index, LF.
- Testes: front_r4_f8_f9.js 77/0 (regressao); front_r4b_uid_409.js 89/0 (768x1366 e 1152x1846); front_r4b_real.js 36/0 contra php -S + MariaDB (banco
  qa_r4fb_*, STORAGE_PATH temporario, CONCLUIR_EXIGE_NUMERO_NOTA=true, portas 8595/8596, resposta do processar PERDIDA de verdade por proxy depois da
  gravacao: 1 linha no banco apos o retry; 409 real [1,2] e depois 200; orfa -> 409 -> listar -> corrigir -> 200; 422 real -> excluir por id_nota -> 200;
  excluir libera o uid e a nova nota usa client_uid diferente). Provas negativas: 22 mutantes no stub + 1 combinado (23/23 mortos; M08 sozinho e equivalente
  porque o guard e duplicado; M08b remove os dois e morre), 2 mutantes no suite real mortos; sha256 de app.js/app.css identicos antes/depois.
- Nao validado: camera Netum fisica, timeout real de 90 s de upload, tempo real de 120 s do servidor, Esc/aviso de inatividade sobre os novos elementos, teste
  fisico no totem.

## Rodada corretiva /01 (F1-F9) concluida - orquestrador
Decisoes do usuario: F1 opcao (a) bloqueio 409; abandono >24h em em_andamento -> cancelado + quarentena 24h; cron max 500/execucao. Backend (F1-F4, F7, abandono, migration 018) e front fases A (F8/F9) e B (F1/F7) entregues, suites verdes. Pendente de confirmacao do usuario: abandono nunca atinge atendimentos com talent_checkin_status ENVIANDO/ENVIADO/ENVIO_INDETERMINADO (decisao do backend). Nova /02-testes (4 revisores) iniciada em seguida.

## Resultado dos testes — rodada corretiva R4 (2026-10-01)

**VEREDITO: APROVADO tecnicamente.** A rodada corrigiu os achados F1 a F9 da primeira /02. A revisao dinamica de front/UX foi aprovada, com achado L1 nao bloqueante: o placeholder "Foto indisponivel" usa 13 px e pode quebrar em tres linhas; ele so aparece em nota recuperada, tem contraste suficiente e nao corta conteudo.

Validacao executada no worktree atual, com bancos `qa_` e storage temporario descartados:

- Frontend: `front_r4_f8_f9.js` 77/77, `front_r4b_uid_409.js` 89/89 e integracao real `front_r4b_real.js` 36/36; sem rede externa, pageerror nem vazamento de dados no console.
- Backend novo: OCR em andamento 62/62; uid/listar/concorrecia/migration 95/95; storage e cron 54/54; compensacao/restauracao 31/31; abandono 61/61.
- Suites mantidas: cliente 104/104; concluir/422 56/56; exclusao/quarentena 192/192; cron de quarentena 42/42.
- Seguranca: aprovado com ressalvas preexistentes e fora do escopo da rodada (corpo JSON escalar pode causar 500 vazio em rotas antigas; primeira leitura fora de `try` em concluir/cancelar). F1, IDOR, SQL injection, logs, UID/listar, cron e concorrencia foram validados sem achado bloqueante.

Pendencias que permanecem para decisao/operacao, sem impedir o resultado tecnico:

- Confirmar a politica conservadora: abandono nao toca Talent `ENVIANDO`, `ENVIADO` e `ENVIO_INDETERMINADO`; apenas `NAO_ENVIADO` e `ERRO_REPROCESSAVEL` sao candidatos.
- Aplicar a migration 018 e agendar no cPanel os crons de abandono e de limpeza antes do deploy.
- Preflight da `tb_cliente` de producao contra os CNPJs UDLOG; validacao fisica do totem, Netum e janelas reais de 90/120 s.
- Fotos CNH/CRLV continuam fora da quarentena, alinhadas ao comportamento atual de cancelar.

`/03-revisao`, commit e push nao foram iniciados nesta rodada.

## Resultado da revisao independente (/03, 2026-10-01)

**VEREDITO FINAL: APROVADO.** A rodada corretiva F1-F9 corresponde ao planejamento, confirma os controles solicitados e nao introduziu regressao funcional, de seguranca, atomicidade, concorrencia ou isolamento. Esta demanda esta liberada para `/04-commit-e-push`; nenhum commit ou push foi feito nesta revisao.

### Veredito por trilha independente

- **backend-especialista: APROVADO.** Confirmados 409 para PENDENTE/PROCESSANDO, fronteira 119/120 s, derivacao transacional e fail-closed do cliente, cron/regex/log, migration 018, uid/listar e abandono. PHP sem erro de sintaxe nos arquivos alterados.
- **security-especialista: APROVADO.** Rotas novas autenticam antes da acao; `uid`, `id_nota` e atendimento ficam escopados ao totem/atendimento; consultas novas usam parametros; listar nao expoe foto/caminho/numero/CNPJ/status; logs e respostas de erro permanecem sanitizados. IDOR, traversal, SQL injection, concorrencia e timeout de lock foram cobertos pelas suites.
- **frontend/UX: APROVADO com L1 nao bloqueante.** Chromium confirmou os dois viewports, alvo de 64 px, contraste, modais sem corte/overflow, aviso 409 neutro e camada de inatividade 80 acima da exclusao 56. L1: o placeholder "Foto indisponivel" permanece em 13 px e pode quebrar em tres linhas, sem corte e com contraste suficiente.
- **qa-testes: APROVADO.** As 12 suites que compoem as 899 verificacoes foram conferidas individualmente e terminaram sem falha.

### Composicao conferida das 899 verificacoes

| Suite | Verificacoes |
| --- | ---: |
| `front_r4_f8_f9.js` | 77 |
| `front_r4b_uid_409.js` | 89 |
| `front_r4b_real.js` (Chromium + PHP/MariaDB QA) | 36 |
| `teste_hardening_r4_ocr_em_andamento.php` | 62 |
| `teste_hardening_r4_uid_reconciliacao.php` | 95 |
| `teste_hardening_r4_storage_cron.php` | 54 |
| `teste_hardening_r4_restaurar_log.php` | 31 |
| `teste_hardening_r4_abandono.php` | 61 |
| `teste_hardening_cliente.php` | 104 |
| `teste_hardening_concluir_422.php` | 56 |
| `teste_hardening_exclusao.php` | 192 |
| `teste_hardening_cron_quarentena.php` | 42 |
| **Total** | **899** |

O backend real contra banco QA confirmou: 409 sem gravar cliente com nota pendente; 120 s para fallback manual; conflito de clientes sem persistencia; retry do mesmo uid com uma unica linha/arquivo; uid de outro atendimento/totem sem consulta/reuso; listar apenas de leitura; 422/409 sempre reconciliaveis; resultado tardio em concluido/cancelado sem escrita; exclusao/rollback e concorrencia coerentes. A integracao real de front simulou resposta de `processar` perdida apos gravacao, recuperou a nota pelo listar e a corrigiu/excluiu.

O Talent foi exclusivamente mock local em `127.0.0.1`; a suite de exclusao confirmou zero requisicao no fluxo e apenas uma chamada de sanidade ao mock. Nao houve Talent, VIO, Serpro, impressao, rede externa, producao, HostGator, Trello ou operacao em banco/storage real.

### Politica de abandono confirmada

Atendimento `em_andamento` so e candidato apos 24 h se `talent_checkin_status` for `NAO_ENVIADO` ou `ERRO_REPROCESSAVEL`. `ENVIANDO`, `ENVIADO` e `ENVIO_INDETERMINADO` nunca sao abandonados; recente, concluido, cancelado, bloqueado ou fora desses dois estados permanece intacto. Fotos de notas vao para `.del` por 24 h; CNH/CRLV nao entram nesta politica.

### Integridade, mutacoes e residuos

- As provas negativas registradas na /02 mantiveram hashes: backend 29/29 mutacoes mortas; F8/F9 14/14; fase B 22 mutacoes mais M08b e 2 reais mortas (M08 isolada e equivalente por dois guards redundantes).
- Nesta /03, hashes SHA-256 dos 55 arquivos de codigo/teste da demanda foram iguais antes e depois; `git diff --check` passou e nenhum codigo foi alterado pela revisao.
- Bancos `qa_r4*` e listeners das portas QA estavam ausentes ao final. Cinco servidores PHP orfaos de QA R5, preexistentes no scratchpad desta mesma demanda nas portas 8795-8799, foram encerrados por PID exato. `qa_iso2` e `qa_lgpd_visual_1790279297` nao foram alterados.

### Achados separados, nao bloqueantes e nao corrigidos

- Placeholder "Foto indisponivel" em 13 px.
- Corpo JSON escalar pode gerar `TypeError`/500 vazio nos entrypoints; preexistente.
- Falha inicial de conexao em concluir/cancelar ainda pode produzir log tecnico; preexistente.
- CNH/CRLV fora da quarentena do abandono.
- Reconciliacao futura para `ENVIANDO`/`ENVIO_INDETERMINADO`.
- Migration 018 e crons ainda precisam ser implantados/agendados no cPanel.
- Falta preflight de producao de `tb_cliente` contra os CNPJs UDLOG.

## Commit

`/04-commit-e-push` iniciado apos o preflight final e a repeticao das 899 verificacoes. Commits funcionais criados com autor e committer `Bruno Santos <brunossaantos@gmail.com>`, sem trailers:

- `b37b7fc730020b6cd40acce688c1f399ba17710e` — `feat(notas): reforca conclusao cliente exclusao e abandono`
- `3c3c18adad4d514cbf8aa9d67a4270278aa2b301` — `feat(totem): reforca revisao e reconciliacao de notas`

O primeiro contem backend, migration 018, crons, quarentena e testes PHP. O segundo contem interface, reconciliacao, vendor local com manifesto/verificador e testes de interface. A migration 018 e o agendamento dos crons continuam pendentes de deploy; esta etapa nao aplicou migration, nao configurou cPanel e nao tocou producao.
