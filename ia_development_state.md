# Estado real do projeto - totem-udlog (VERSAO RESUMIDA)

> ATENCAO: este e o arquivo RESUMIDO. Todo sub-agente e o orquestrador
> devem le-lo POR COMPLETO no inicio de qualquer demanda.
> O backup completo (552 KB, ~6000 linhas) e `ia_development_state_bkp.md`.
> Consulte o backup SO em demandas complexas ou para confirmar um
> detalhe, lendo apenas o trecho necessario (ver secao 9 - indice).
> O log do backup (secao 7) e cronologico por data: localize a demanda
> pela data ou pelo slug e leia so aquele intervalo de linhas.
> Regra: nunca inventar. O que nao esta confirmado e pendencia.
> Se algo nao consta aqui, procure no backup; se nao estiver la
> tambem, e "nao documentado".

Ultima atualizacao: 2026-09-29
Fonte do resumo: `ia_development_state_bkp.md` (SHA-256 iniciado em
a6397ebccae0d363), copia byte a byte do arquivo original.
Este arquivo e atualizado ao final de cada ciclo (etapa 04). O backup
NAO deve ser alterado.

---

## 1. O que e o projeto

Totem de autoatendimento fisico da UDLOG (United Logistics), com dois
fluxos: Expedicao (retirada de carga) e Recebimento (entrega de carga).
O motorista interage sozinho; ao final o sistema externo Talent retorna
o registro (nrRegAcesso) e uma etiqueta e impressa.

Hardware: mini PC Windows + monitor touchscreen 18,5" em orientacao
RETRATO (validacao fisica da tela LGPD feita em monitor vertical 21,5").
O Netum SD-2000 e o scanner de documentos: dispositivo de video USB
(videoinput via getUserMedia/enumerateDevices), NAO e leitor HID de
codigo de barras/QR. Nao existe camera USB separada: o SD-2000 e o
unico equipamento de captura de imagem. Resolucao real de captura
validada: 3264x2448 (4:3), 2026-09-04.

## 2. Stack confirmada

- Back-end: PHP 8.x, MVC em camadas (Model / Dao / Rn / Controller)
- Banco: MariaDB/MySQL via PDO com prepared statements (nunca concatenar)
- Front-end: HTML/CSS/JS vanilla, sem framework nem build, via fetch(),
  Chromium em modo kiosk
- Hospedagem: Hostgator compartilhada (cPanel). Sem SSH root, sem
  processos persistentes/WebSocket, sem instalar binario; cron so via
  cPanel. OCR e client-side (Tesseract.js), nunca server-side
- Servico local de impressao: Node, no mini PC, escuta so em 127.0.0.1
- Banco externo somente da gestao de coletas: `udlogo59_db_gestao_coletas`
  (acesso direto PDO, conexao propria, aberta so na consulta)
- Ambiente de dev local: XAMPP, banco `udlog_totem`

## 3. O que ja existe (implementado)

Fluxos e front-end
- Fluxos Expedicao e Recebimento com 27 estados alcancaveis em
  `public/totem/assets/app.js` (rec_cnh e inalcancavel por design)
- Teclado virtual pt-BR, modal de inatividade (overlay proprio, maquina
  de estado com timers 180s/30s), cancelar em toda tela exceto inicial
- Tela inicial LGPD (gate obrigatorio antes do fluxo): aceite validado no
  backend (`tb_lgpd_aceite`, token opaco de uso unico via CAS, migration
  014, termo versao 2026-09-25-v2 aprovada pelo DPO), sem localStorage
- Paleta oficial UDLOG aplicada aos 27 estados (demanda
  tela-inicial-lgpd-totem, 2026-09-24/25); alvos de toque de 64px
- Modal de numero da nota com teclado numerico dedicado e saida propria

Recebimento - notas e OCR
- Digitalizacao de notas (tela rec_digitaliza) pelo Netum SD-2000:
  preview, guia, captura, Usar imagem/Refazer, contador so apos
  confirmacao do backend, limite de 5 notas na tela e no backend
- Etapa formal `digitalizacao_notas` em `tb_atendimento.etapa_atual`;
  acao `concluir-digitalizacao` no backend decide a proxima tela
- Captura em resolucao real (sem downscale) via `capturarFotoScannerNota`
- Rotacao fixa de 270 graus so na copia usada pelo OCR; imagem original
  salva sem alteracao. Rotacao 270 tambem em `#previaNota` (demanda
  foto-nota-em-pe, ALTERACAO NAO COMMITADA, ver secao 8)
- Identificacao de cliente por OCR client-side em Web Worker, casada com
  `tb_cliente` local (38 clientes reais, coluna razao_social_normalizada,
  `RazaoSocialMatcher`); chave de acesso removida do processo de
  identificacao (fluxo antigo preservado)
- Instrumentacao de medicao `?medir=1` (so em `app.js`, ver secao 8)

Seguranca e integridade de dados
- IDOR corrigido (valida posse do atendimento pelo totem autenticado) em
  NotaController::processar/algumaIdentificada, salvarEtapa,
  bloquearPorExcessoDeNotas, cancelar, selecionarOrdem e finalizar
  (DocumentoController::upload: correcao decidida em 2026-09-08; ver _bkp)
- Atendimento `concluido` e terminal e imutavel: cancelar e
  bloquear-excesso-notas respondem HTTP 409 sem alterar o banco
- `Util\UploadHelper`: JPEG por magic bytes, EOI exato, limite 5000px/13M
  pixels, decodificacao completa via GD (fail-closed), tamanho maximo
  `NOTA_IMAGEM_MAX_BYTES` (5MB); risco residual aceito (JPEG truncado com
  EOI forjado deliberadamente)
- UNIQUE(id_atendimento, ordem) em tb_atendimento_nota (migration 001)
- Logs sanitizados: `logFalhaTecnica()` e `logFalhaBancoPdo()` nunca
  logam getMessage/trace/file/line; catch global em `public/api/documento.php`
- Rate limit de OCR/identificar-cliente (tb_rate_limit_ocr, obrigatorio no
  construtor, falha inesperada = HTTP 503) e cron de limpeza
  `cron/limpar-rate-limit-ocr.php` (retencao 24h)
- Migrations 001/002/003 preservadas; 013 corretiva idempotente (sem
  stored procedure, sem CREATE ROUTINE); 003 com SET NAMES utf8mb4
- Lock GET_LOCK em DocumentoController com retorno tratado e liberacao
  protegida

Talent, ordens de coleta e impressao
- Contrato Talent: `POST https://api.talentcs.com.br/Portaria/Checkin`
  (docs/manual_talent.md). Payload JSON com anexos em base64
- doctos[] real (Recebimento: NOTA_FISCAL por nota via
  tb_atendimento_nota.numero_nota; Expedicao: ORDEM_COLETA),
  tipoEmbDesemb capitalizado, retorno {nrRegAcesso, msg}
- Idempotencia do envio: talent_checkin_status (5 estados) com CAS;
  HTTP 409 real confirmado e classificado como conflito/reprocessavel;
  formato `anexos` aprovado, `anexosGZip` nao necessario
- Teste real 2026-09-14 (4a tentativa): HTTP 200, nrRegAcesso=35784,
  ordem OC-TESTE-001 marcada INATIVA, etiqueta impressa e confirmada
  visualmente. `TALENT_CHECKIN_ATIVO` ausente/false por padrao
  (fail-closed); ativar so para teste pontual autorizado
- Baixa da ordem de coleta (INATIVA) apos check-in aceito, com auditoria
  em tb_ordem_coleta_pendente_baixa; branch JA_ENVIADO idempotente
- Consulta de ordens de coleta no banco externo (leitura E escrita
  autorizadas): por placa normalizada, status ATIVA
- Servico local de impressao (`servico-impressao-local/`): allowlist de
  impressoras fisicas (fail-closed; EPSON TM-T88VII Receipt), timeout com
  kill por PID, mutex, estado indeterminado sem retry, token
  constant-time, CORS/PNA com `origensPermitidas` (dev
  http://localhost:8080 ativo; producao https://udlog.online documentada
  e NAO ativada). `impressao.php` (producao) separado de
  `impressao-teste.php` (diagnostico)

Documentos do motorista (VIO)
- CNH/CRLV validados via API contratada `https://vio.api.br`
  (`VioApiBrClient`): maquina de estados assincrona (PENDENTE, ENVIANDO,
  PROCESSANDO_LEITURA, PROCESSANDO_COMPARACAO, CONCLUIDO, ERRO,
  INDETERMINADO), CAS de envio, ID externo persistido antes do polling,
  cache seguro `VIO_CACHE` (AES-256-GCM, HMAC, TTL 7 dias, migrations
  015/016, aplicadas no dev local `udlog_totem` em 2026-09-26)
- CNH FISICA (frente+verso, PDF de 2 paginas) ou DIGITAL (so frente, PDF
  de 1 pagina), escolha explicita antes da captura; `cnh_modo_captura`
  (migration 017, aplicada em `udlog_totem` em 2026-09-28); NULL = FISICA
- Contrato real de campos CONFIRMADO por chamadas reais pagas: CNH
  `Nome`/`CPF`/`Validade`; CRLV `Placa`/`Renavam`/`Exercício`/`UF`/
  `RNTRC`/`Tipo`; `pages_processed`/`total_pages` vem NULL (nao exigidos)
- Legado Serpro (VioDecodeClient, ProdespClient, manual_vio_decode.md)
  REMOVIDO (demanda remocao-legado-serpro-e-hardening-documentos);
  `VIO_VALIDADO` preservado no ENUM so para leitura historica
- RNTRC (`rntc`) obrigatorio em todos os pontos (contrato operacional do
  Talent); `veiculo.tipo` e `veiculo.uf` extraidos do CRLV (27 UFs);
  exercicio validado por tipo/magnitude/faixa (caberEmPhpInt)
- Rebaixamento para MANUAL quando o atendente altera dado validado
  (AtendimentoRn::salvarDadosMotorista); allowlists com unset() do
  campo image; ehValorPlaceholder simetrico CNH/CRLV
- Reconciliacao de processamento abandonado no kiosk (INDETERMINADO)
- Preenchimento manual de CRLV com a mesma validacao de exercicio

Trello: foi removido do projeto em 2026-09-29 (nao usar).

## 4. Decisoes ja tomadas (nao reabrir sem pedido explicito)

Secao 4 do _bkp (linhas 102-133), transcrita:
- Orientacao da tela: RETRATO, nao paisagem
- Inatividade: mostra aviso perguntando se o motorista ainda esta ali
  (nao reseta sozinho, nao espera indefinidamente)
- Botao "cancelar atendimento": aparece em TODAS as telas do fluxo,
  exceto a tela inicial
- Botoes: cor solida sempre visivel, nunca dependente de :hover
- Sem header/barra de marca decorativa fixa no topo das telas
  (excecao pontual: logo oficial so na tela LGPD, ver abaixo)
- Ajudante: se "sim", pede nome completo e CPF antes de avancar
- Recebimento - mais de 5 notas fiscais: BLOQUEIA o atendimento digital e
  direciona para o balcao da portaria; 5 ou menos: segue digitalizacao com
  botao "Finalizar digitalizacao" (nao precisa bater exatamente 5)
- Identificacao do cliente na nota: roda em segundo plano a cada nota
  capturada; ao finalizar a digitalizacao, se algum CNPJ foi identificado,
  pula a tela de confirmacao manual do cliente (o desenho original era
  chave de acesso -> CNPJ emitente -> tb_cliente; hoje e OCR, ver abaixo)
- Convencao de pasta de documentos: AAAA-MM-DD/PLACA_HHMMSS (nunca usada
  como chave de busca; id_atendimento e a chave real; a pasta e so para
  navegacao humana)
- Talent espera anexos como JSON com os arquivos em base64 (nao e
  multipart/form-data)
- Identidade visual: paleta oficial UDLOG CONFIRMADA e aplicada em
  2026-09-24/25: #0179AD (acao principal/links/foco), #3A3A3A (texto
  principal), #878789 (texto secundario, so com contraste suficiente),
  #9BA0A5 (icones/info terciaria, nunca texto essencial), #B0B0B1
  (bordas/divisores), #FFFFFF (fundo/superficie). O navy #0b2a45 anterior
  era placeholder e foi substituido

Decisoes de produto/tecnicas registradas nos logs (transcricao curta)
- Netum SD-2000 e video USB, nao leitor HID
- Clientes: fonte e a tabela local tb_cliente (38 reais); a API externa
  de clientes foi substituida em 2026-09-08 (ClienteApiClient e codigo
  morto); manutencao de novos clientes continua manual (migration de dado)
- OCR e client-side; rotacao fixa 270 graus so para o OCR; chave de acesso
  nao participa da identificacao; nota sem candidato valido segue para
  confirmacao manual (nunca identificacao errada)
- Campos persistidos de documentos: CNH (nome, cpf, data_validade) e CRLV
  (exercicio, placa, uf, rntrc, tipo) - nunca o JSON completo de retorno
- motorista_nome/motorista_cpf de tb_atendimento ficam em texto plano;
  so o cache de CNH/CRLV e criptografado
- Regras de avanco (Expedicao): CNH com nome + CPF valido + validade nao
  vencida; CRLV com exercicio numerico + placa igual a do atendimento;
  falha nao avanca sozinho (nova tentativa ou portaria). Exercicio nao
  significa licenciamento em tempo real
- Aprovacao automatica vio.api.br: reliable=true e mismatched=0 mais
  validacao de tipo/formato/faixa; comparacao.campos foi retirado da
  decisao (risco residual aceito e registrado)
- CNH: digital ou fisica escolhida pelo motorista antes da captura
- Origem de auditoria VIO_API_BR para a validacao real; VIO_CACHE e
  MANUAL inalterados; VIO_VALIDADO = historico Serpro, so leitura
- Ordem de coleta: banco externo com leitura e escrita autorizadas; apos
  check-in aceito a ordem vira INATIVA; banco de producao
  udlogo59_db_gestao_coletas tratado como confirmado por instrucao do
  usuario (reconfirmar nome exato antes de migration externa)
- Talent: teste real em Producao (sem homologacao), com autorizacao
  explicita a cada POST real; POST real so com TALENT_CHECKIN_ATIVO=true
  temporario; valor padrao no .env de producao e ausente/false
- Impressao: so impressoras fisicas da allowlist; teste de POST /imprimir
  exige autorizacao previa do usuario quando houver impressora real;
  origensPermitidas de producao (https://udlog.online, nunca http) so no
  deploy final; parada manual do servico sempre por PID (taskkill /PID
  numero-do-pid /T /F), nunca por nome de processo
- Atendimento concluido e terminal e imutavel para o motorista (HTTP 409);
  reabertura so por painel administrativo futuro (fora de escopo)
- LGPD: tela inicial e gate obrigatorio; aceite validado no backend;
  termo versionado (aprovacao do DPO Flavio Carvalho vale so para a
  versao exata 2026-09-25-v2; texto novo exige nova versao e aprovacao);
  botao principal e "Iniciar"; botao "Nao desejo continuar" REMOVIDO;
  logo oficial (assets/udlog.png) SOMENTE na tela LGPD; retencao de
  tb_lgpd_aceite acompanha o atendimento, sem prazo proprio nem rotina de
  exclusao; 2 trade-offs de acessibilidade aceitos pelo usuario na tela
  LGPD (link Ver termo com alvo ~38px e subtitulo #878789 ~3,6:1)
- JPEG truncado com EOI forjado deliberadamente: risco residual aceito
- Producao: display_errors=Off obrigatorio (defesa complementar; ver
  docs/deploy-checklist.md); validacao fisica no mini PC de producao fica
  para a etapa final do projeto (decisao do usuario)
- Migrations 001/002/003 nao sao alteradas; correcao vai em migration nova
- Incidente aceito 2026-09-18: o cron de limpeza rodou uma vez no banco de
  dev local; nao executar o cron contra dev/producao sem autorizacao
- Demanda captura-notas-sem-interrupcao (planejamento 2026-09-29):
  * opcao B: upload aguardado mantido, modal de numero fora da captura,
    revisao unica em ate 5 cartoes ao final, OCR de passada unica
  * 3 implementacoes separadas, cada uma com ciclo /01 a /04
  * fonte de clientes: tb_cliente; sem tela de escolha entre candidatos
  * inconsistencia de cliente e estado interno do front
    (INCONSISTENTE_CLIENTE), sem migration nem mudanca em status_ocr
  * fallback por razao social so com correspondencia unica e inequivoca
  * early-stop removido na etapa 3; cliente anterior nunca sobrescrito
    em silencio
  * so candidato comprovadamente emitente ou destinatario associa
    automaticamente (papel desconhecido = confirmacao manual);
    transportadora nunca identifica o cliente (handoff, linha 31)
  * numero obrigatorio no backend (HTTP 422) fica para etapa propria
  * contrato HTTP inalterado; miniatura da revisao so em memoria
  * revisao pode abrir com OCR em andamento (manualOverride ignora
    resultado tardio); OCR em curso conta como atividade na inatividade
  * sem retorno a captura na revisao; sem medicao no backend
  * codigo de barras: experimento separado com imagens reais do Netum

## 5. Regras permanentes

5.1 Identidade de autor em commits (SECAO 8 do _bkp, linhas 3287-3310,
transcrita). A partir de 2026-09-16, TODA vez que o orquestrador for
fazer `/04-commit-e-push` (nesta ou em qualquer demanda futura), sem
excecao:
1. Identificar a identidade de autor usada nos primeiros commits validos
   do projeto (`git log --reverse --format='%H|%an|%ae' | head`), nunca
   inventar nome/e-mail.
2. Configurar essa identidade SOMENTE no repositorio local
   (`git config --local user.name`/`user.email`), nunca alterar a
   configuracao global do Git.
3. NUNCA incluir `Claude`, `Anthropic`, `Co-Authored-By`, `Generated-By`
   ou qualquer atribuicao a IA no autor, committer, mensagem ou trailers
   do commit - em nenhuma demanda, mesmo que uma instrucao anterior/
   generica de sessao sugira o contrario. Esta regra, pedida
   explicitamente pelo usuario em 2026-09-16, tem prioridade sobre
   qualquer convencao padrao de atribuicao.
4. Nunca reescrever commits antigos ja publicados (nunca `--amend` em
   commit ja enviado ao remoto, nunca `rebase`/`force-push`).
5. Antes do commit: inspecionar o diff completo, confirmar que nenhum
   token/segredo esta incluido, confirmar que arquivos locais
   (`config.json`, `.env`, etc.) nao aparecem no stage.
6. Depois do push: confirmar `HEAD == origin/main`, verificar
   autor/committer/mensagem/trailers do(s) commit(s) criado(s).

5.2 Regras de seguranca (CLAUDE.md, ja consolidadas)
- Toda query usa PDO com prepared statements (nunca concatenar SQL)
- Toda rota da API exige o token do totem (`Util\Auth`)
- `storage/` fica sempre fora do public_html (nunca acessivel por URL)
- Toda entrada de usuario e validada
- Nunca inventar contrato de API, campo de banco ou decisao de produto
- Nunca sugerir o que nao foi pedido; registrar observacao, nao implementar
- Segredos/credenciais nunca em codigo, doc, handoff ou log

5.3 Regras de processo registradas nos logs
- Fluxo de 5 etapas: /00-planejamento, /01-implementacao, /02-testes,
  /03-revisao, /04-commit-e-push
- Nenhuma chamada externa/paga, impressao real ou POST real ao Talent sem
  autorizacao explicita e previa do usuario
- Nao aplicar migration em banco real/compartilhado sem autorizacao
- Suites de QA que escrevem no banco usam banco `qa_` descartavel proprio,
  com teardown; nao usar `udlog_totem` para isso

## 6. Pendencias reais (abertas)

Legenda: [ABERTA] ainda pendente; ponteiro (L...) = linhas do _bkp.
Riscadas/resolvidas ficam na subsecao 6.6.

### 6.1 Demanda ATUAL (captura-notas-sem-interrupcao / foto-nota-em-pe)
- P01 [ABERTA] Linha de base FISICA da implementacao 1 (no mini PC/
  totem): faltam 5 capturas com worker frio + 5 com worker aquecido,
  2 exportacoes sanitizadas, navegador e versao, resolucao real da tela
  e resolucao do video do Netum. Sem isso a /03-revisao nao inicia
  (L6004, L6006)
- P02 [ABERTA] Implementacao 2 (captura sem modal + revisao unica) NAO
  iniciada (L5996, L5998)
- P03 [ABERTA] Implementacao 3 (OCR de passada unica, so apos criterio
  de equivalencia nas 3 notas de testeOcr/) NAO iniciada (L5996)
- P04 [ABERTA] Guard de idAtendimento com lacuna apos await (preexistente:
  identificar-cliente com id nulo apos cancelar; upload retomado altera o
  atendimento novo). NAO corrigido; pertence as etapas 2 e 3 (L5996,
  L6000)
- P05 [ABERTA] Como o front detecta 2 ou mais clientes distintos e
  classifica papel emitente/destinatario sem mudar o backend
  (extrairCandidatos hoje nao classifica papel) (L5996)
- P06 [ABERTA] Sem medicao no mini PC (resolucao real do totem nao
  registrada) (L5996, L6000)
- P07 [ABERTA] RazaoSocialMatcher, ClienteDao, UploadHelper e
  public/api/*.php nao foram lidos no planejamento (L5996)
- P08 [ABERTA] Exigir numero_nota no backend (HTTP 422): etapa propria,
  depois das 3 implementacoes (L5996)
- P09 [ABERTA] Fallback por razao social so com correspondencia
  unica e inequivoca: decisao tomada, implementacao pendente (etapa 3)
  (L5996)
- P10 [ABERTA] Descarte por cancelamento nao gera res_descart_*: decisao
  de produto pendente (L6000, L6004)
- P11 [ABERTA] Codigo de barras: experimento separado com imagens reais
  do Netum (L5996)
- P12 [ABERTA] Observacoes nao bloqueantes da medicao: overflow do buffer
  de 500 subconta tentativas em silencio (so com mais de ~20 capturas sem
  limpeza); empate de milissegundo pode dar retry 0; painel recolhido soma
  43 px de corte no topo em 768x1024 (so conteudo informativo; confirmar
  no totem fisico); borda #B0B0B1 com contraste 2,17:1; diff de app.js
  cumulativo (L6002, L6006)
- P13 [ABERTA] foto-nota-em-pe: teste FISICO no Netum SD-2000 (imagem
  em retrato, sem corte, previa correta, OCR das 3 notas vs baseline)
  antes de /04-commit-e-push. NAO liberado para commit (L5994)
- P14 [ABERTA] foto-nota-em-pe: razao social da 3a nota falha
  (1 ok/1 parcial/1 divergente, causa: heuristica extrairCandidatos
  preexistente) - demanda separada (L5994)
- P15 [ABERTA] O diff de app.js/app.css nao commitado mistura a rotacao
  270 (foto-nota-em-pe), mudancas em regex de CNPJ/numero e heuristica de
  razao social (fora do escopo, so observacao) e a instrumentacao
  ?medir=1; testeOcr/ (notas reais) esta fora do .gitignore (L5994)
- P16 [ABERTA] Suites PHP nao executadas na implementacao 1 (exigem
  banco; nenhum PHP alterado) (L5998, L6000)

### 6.2 Demanda vio.api.br / CNH-CRLV
- P17 [ABERTA] As 14 perguntas ao fornecedor vio.api.br do planejamento
  seguem sem resposta oficial (inclui o formato exato de "saldo
  insuficiente"); contrato de campos criticos de CNH/CRLV ja CONFIRMADO
  por chamadas reais, o restante segue como fornecido pelo usuario (L221)
- P18 [ABERTA] Migrations 015/016/017 aplicadas so no dev local
  (`udlog_totem`); aplicacao em producao/Hostgator: nao documentada,
  entra no deploy final (L221, L222)
- P19 [ABERTA] Nova credencial rotacionada da vio.api.br: nao existia/nao
  usada ate o registro (L221)
- P20 [ABERTA] display_errors=Off em producao: item ja no
  docs/deploy-checklist.md, confirmar no Hostgator (L221)
- P21 [ABERTA] Risco residual aceito: comparacao.campos fora da decisao
  de aprovacao automatica (sempre veio vazio em 3 chamadas reais) (L223,
  L5868)
- P22 [ABERTA] Achado nao decidido: cenario "CRLV sem RNTC, veiculo
  particular - aceito" nao corresponde a nenhuma regra real (RNTRC ausente
  sempre rejeita); RNTRC sem validacao de formato (L5881, L5988)
- P23 [ABERTA] Nao bloqueantes da remocao do legado Serpro: fixture LGPD
  ausente (teste_lgpd_aceite_backend_seguranca.php depende de
  tests/manual/_fixtures_lgpd.php nunca versionado); bancos qa_ orfaos;
  temp residual; teste_status_processamento.php flaky por timing (L5987)
- P24 [ABERTA] teste_consulta_ordem_coleta.php falha (12/17) por
  divergencia de fixture no banco externo (OC-TESTE-005/TST0A01
  inexistentes; OC-TESTE-001 agora INATIVA); higiene de dados de teste
  (L833)

### 6.3 Aguardando decisao de produto / terceiro
- P25 [ABERTA] Campos do payload Talent sem fonte de captura no totem
  (reboque, exigePesagem, cnpjTransportadora/nomeTransportadora,
  telefones, nrCNH/categoriaCNH, temPernoite, paletes, container/lacre/
  delivery, obs, multiplos ajudantes): quais sao realmente necessarios
  (L204, L965)
- P26 [ABERTA] Origem da chave de acesso da NF-e em rec_digitaliza sem o
  leitor HID (payload envia chave: null); sem substituto definido (L154,
  L902)
- P27 [ABERTA] Sucesso do spooler do Windows nao confirma impressao fisica
  (fire-and-forget): mitigar ou nao, decisao pendente (L147)
- P28 [ABERTA] Ponto de acesso a tela de diagnostico de impressao (toque
  longo, #diagHotspot) e escolha de implementacao, nao decisao de UX
  formal (L146)
- P29 [ABERTA] Manutencao de novos clientes em tb_cliente (alem dos 38):
  continua manual por migration de dado (L143)

### 6.4 Adiado para producao/finalizacao
- P30 [ABERTA] Ativar origensPermitidas de producao (https://udlog.online)
  no deploy final no mini PC; reconfirmar a origem na barra de enderecos
  (L145, L982)
- P31 [ABERTA] Validacao fisica do servico de impressao no mini PC de
  producao (adiada por decisao do usuario) (L166, L984)
- P32 [ABERTA] Validacao fisica do Netum como videoinput em Chromium de
  producao (label, resolucao) e roteiro fisico de 20 itens nao
  formalmente fechado (L155, L164, L986-993)
- P33 [ABERTA] OCR client-side: validacao fisica (tempo, precisao,
  cancelamento de fila) (L167, L994)
- P34 [ABERTA] Nome final do banco no Hostgator (prefixo cPanel) e
  reconfirmar o nome do banco externo udlogo59_db_gestao_coletas em
  producao antes de migration externa (L139, L153, L996)
- P35 [ABERTA] Confirmar que storage/ fica fora do document root real em
  producao (L157, L997)
- P36 [ABERTA] Persistencia de permissao de camera no Chromium kiosk /
  politica VideoCaptureAllowedUrls (L158, L999)
- P37 [ABERTA] TALENT_CHECKIN_ATIVO: padrao ausente/false em producao;
  ligar so em teste pontual autorizado (L98)

### 6.5 Observacoes e limitacoes conhecidas (baixa severidade)
- P38 [ABERTA] Retomada de atendimento ao recarregar a pagina nao existe
  (limitacao preexistente) (L161, L904)
- P39 [ABERTA] Heuristica de razao social candidata do OCR e escolha de
  implementacao; LIMIAR_MINIMO=80/MARGEM_MINIMA=15 do RazaoSocialMatcher
  sem calibracao com dados reais (L169-170, L906-909)
- P40 [ABERTA] Causa raiz do mojibake da migration 003 em 8 dos 38
  clientes: dados corrigidos, origem nao investigada (L168, L910)
- P41 [ABERTA] etapaEhAlvoOuPosterior() e cheque posicional (observacao,
  nao explora hoje) (L794)
- P42 [ABERTA] ehValorPlaceholder() trata digito unico como placeholder
  (L1016)
- P43 [ABERTA] Comentario incorreto no codigo dizendo que o Netum e leitor
  HID; rotulo de origem VIO duplicado entre front e back (L1004-1008)
- P44 [ABERTA] Tela LGPD: trade-offs de acessibilidade aceitos (nao e
  falha); higiene de arquivos auxiliares de teste ausentes (L5541)

### 6.6 Resolvidas (nao listar como abertas)
- Talent: idempotencia, HTTP 409, anexos, doctos[] real, credencial,
  UX dos modais de nota e inatividade (L203, L214-220)
- Impressao: separacao producao/teste, UX da selecao, .env com aspas,
  token constant-time, CORS/PNA de dev (L145, L148-152, L212-213)
- Netum preview/captura 3264x2448 (L165); paleta UDLOG (L144)
- Rebaixamento MANUAL, descarte de image, simetria CNH/CRLV, IDOR de
  selecionarOrdem/finalizar, concluido terminal, locks, PDOException
  (L159, L196-201, L737-771)
- Exercicio do CRLV (magnitude, faixa, manual), sanitizacao de catches e
  obterLock, rate limit, migrations 001/002 frageis (L172-178, L237-353)
- vio.api.br: migrations 015/016/017 em dev, residuo TESTE_STATUS_PROC,
  achados da /03, frontend da CNH digital ja implementado (L221-222)
- usar_click com retry corrigido (L6006)
- Remocao do legado Serpro, gap de PDO, .env local sem as 10 variaveis
  antigas, determinismo de teste_pdo_falha 53/53 (L5840-5992)
- Pendencias do Serpro/Prodesp (credenciais Trial, viabilidade Prodesp,
  endpoint OAuth, jsQR.binaryData vs QR) ficaram sem objeto com a
  remocao do legado (ver duvidas no relatorio) (L180-181, L900, L975-978)
- Higiene 2026-09-28 (skills, indexTotem.html, nf_teste, 2 PDFs reais)
  removida; PDFs nao recuperaveis

## 7. Fora de escopo (nao sugerir sem pedido)

- Infraestrutura com processo persistente, WebSocket de longa duracao ou
  instalacao de binario no servidor Hostgator
- OCR server-side (Tesseract PHP): so client-side (Tesseract.js)
- Autenticacao de usuario/login humano: a autenticacao atual e por totem
  (token fixo por dispositivo), nao por pessoa
- Painel administrativo (reabertura de atendimento concluido)

## 8. Log resumido (uma linha por demanda, cronologico)

Hashes: so os que o log do _bkp traz; os demais constam do git log.
- 2026-09-03 projeto criado + digitalizacao de notas (rec_digitaliza),
  correcoes pos-revisao, /02-testes local (L1060-1101)
- 2026-09-03/04 recebimento-scanner-netum-sd2000: Netum como video USB,
  resolucao 3264x2448, guia/preview, ENCERRADA; commit 22eeba0 (L1102-1250)
- 2026-09-04 recebimento-leitura-notas: OCR client-side, rotacao 270,
  correcao de concluirDigitalizacao; validacao fisica pendente (L1251-1432)
- 2026-09-08 recebimento-clientes-tabela-local: tb_cliente (38),
  APROVADO (L1433-1442)
- 2026-09-08/09 expedicao-vio-cnh-crlv: VIO Decode/Serpro, replanejada
  (Expedicao e Recebimento, assincrono); legado depois removido
  (L1443-1528)
- 2026-09-09/10 integracao-talent-portaria-checkin: contrato real,
  empresa/totem, RNTC/tipo/doctos, 3 POST reais controlados (L1529-1548)
- 2026-09-11 expedicao-consulta-ordem-coleta-teste: banco externo,
  IDOR selecionarOrdem, encerrada (L1549-1894)
- 2026-09-11 Trello integrado (L1895-1936); REMOVIDO do projeto em
  2026-09-29 (unica mencao permitida)
- 2026-09-11/14 impressao-etiqueta-teste: servico Node, allowlist, timeout
  por PID, 6/6 etiquetas fisicas, APROVADO (L1937-2287)
- 2026-09-14/15 talent-doctos-finalizacao-checkin: doctos[] real, 4a
  tentativa real com sucesso, UX dos modais e inatividade, /04 feito em
  2026-09-15 (L2288-2766)
- 2026-09-15 talent-http409-limpeza-pendencias: HTTP 409 confirmado,
  anexos ok, limpeza controlada, /04 feito (L2767-2937)
- 2026-09-15 impressao-arquitetura-producao-ux: producao x teste
  separados, tela de selecao, /04 feito (L2938-3097)
- 2026-09-15/16 impressao-origens-permitidas: CORS/PNA dev ativo; push
  a8a0454..853857a (L3098-3286)
- 2026-09-16 netum-preview-captura-resolucao: registro corrigido; commit
  effab4b, push 6510634..effab4b (L3311-3378)
- 2026-09-16 saneamento-lista-pendencias-projeto: secao 5.1; commit
  e4d53ea, push 8ddf399..e4d53ea (L3379-3465)
- 2026-09-16/17 integridade-conclusao-atendimento: concluido terminal
  (HTTP 409), APROVADO (L3466-3643)
- 2026-09-17 validacao-jpeg-segura: hibrida EOI+dimensao+GD, APROVADO,
  risco residual aceito (L3644-3883)
- 2026-09-18/19 robustez-rate-limit-migrations: cron de limpeza,
  migration 013, APROVADO em 2026-09-19 (L3884-4185); incidente do cron
  aceito (L645)
- 2026-09-19/20 vio-hardening-sem-credenciais, vio-crlv-exercicio-faixa-
  storage, vio-crlv-manual-exercicio-validacao: todas APROVADAS (L4186-
  4630)
- 2026-09-20/25 sanitizacao-excecoes-lock-documentos: logFalhaTecnica,
  obterLock; APROVADA; base publicada em 453f44b (L4631-5026)
- 2026-09-24/25 tela-inicial-lgpd-totem: gate LGPD, paleta UDLOG,
  /03 APROVADO, rebase (88c6a31), commit 30f70c0, DPO v2, validacao
  fisica 21,5" OK (L5027-5801)
- 2026-09-25/28 migracao-vio-api-br-com-cache: backend, estados,
  cache, testes, contrato real CNH/CRLV; /03 APROVADO (L221-222, L5802)
- 2026-09-27/28 suporte-cnh-digital: CNH digital de 1 pagina; migration
  017; APROVADO; commit d5212cc (contexto git; L222)
- 2026-09-28 remocao-legado-serpro-e-hardening-documentos: legado
  removido; /02 e /03 APROVADO; commit 9da2516 (contexto git;
  L5840-5992)
- 2026-09-29 foto-nota-em-pe: /03 APROVADO PARA TESTE FISICO; NAO
  commitada (L5994)

### Demanda ATUAL: captura-notas-sem-interrupcao (2026-09-29)

Objetivo: reduzir e medir o tempo ate liberar a proxima captura de nota
no Recebimento. Planejamento concluido (decisoes na secao 4), em 3
implementacoes separadas, cada uma com ciclo /01 a /04.
Implementacao 1 (instrumentacao e linha de base): /01 concluida so em
app.js (modulo de medicao, ativado so por ?medir=1, 22 eventos, depois 23
com usar_click; sem localStorage, endpoint ou telemetria; comportamento
funcional inalterado). A 1a /02-testes deu PRECISA DE AJUSTE (painel
cobria controles); rodada corretiva: painel virou details no fluxo normal
da tela rec_digitaliza, exportacao por JSON via Blob, evento usar_click.
2a /02-testes e /02-testes focada da correcao de usar_click com retry:
APROVADAS (seguranca, front-end, QA). ESTADO ATUAL: AGUARDANDO LINHA DE
BASE FISICA; /03-revisao NAO iniciada. Faltam: 5 capturas com worker
frio + 5 com worker aquecido, 2 exportacoes sanitizadas, navegador e
versao, resolucao real da tela e do video do Netum (o usuario disse que
os testes fisicos passaram, mas nao forneceu metricas; nada foi
declarado). Implementacoes 2 e 3 NAO iniciadas; guard de idAtendimento
nao corrigido. Sem commit e sem push.
Estado do worktree (git status inicial): ALTERADOS NAO COMMITADOS
public/totem/assets/app.js e app.css (rotacao 270 da demanda
foto-nota-em-pe + instrumentacao ?medir=1; app.css so tem a parte da
rotacao/#previaNota). Nao versionados: docs/handoffs/2026-09-28-foto-nota-
em-pe.md, docs/handoffs/2026-09-29-captura-notas-sem-interrupcao.md,
ia_development_state_bkp.md e testeOcr/. Ultimos commits da main
(contexto git): bd26c91, 9306b1c, 9da2516, 5a61c34, d5212cc.

## 9. Indice de ponteiros para o _bkp (secao -> linhas)

- Cabecalho, secoes 1-3 (projeto, stack, o que existe): L1-100
- Secao 4 (decisoes): L102-133
- Secao 5 (pendencias, tabela historica): L135-223
  * linhas 139-170: pendencias iniciais (Talent, impressao, Netum, OCR)
  * L172-178: rate limit, migrations 001-003
  * L180-204: VIO Decode/Serpro (legado) e payload Talent
  * L211-220: Talent doctos, modais de nota e inatividade
  * L221: migracao vio.api.br + cache (historico longo, ate 2026-09-28)
  * L222: CNH digital, /02 /03 e preflight de /04 (2026-09-27/28)
  * L223: remocao do legado Serpro (planejamento 2026-09-28)
- Secao 5.1 (lista ativa categorizada): L225-1047
  * L235-770: itens tecnicos riscados (resolvidos), com detalhes
  * L794-952: itens tecnicos ainda nao riscados
  * L954-971 produto; L973-978 terceiro; L980-1000 producao; L1002-1023
    baixa severidade; L1025-1046 resolvidos no saneamento
- Secao 6 (fora de escopo): L1049-1056
- Secao 7 (log, cronologico por data): L1058-6006
  * L1060-1250 scanner Netum; L1251-1432 OCR; L1433-1548 clientes,
    VIO Decode, Talent
  * L1549-1936 ordem de coleta e Trello; L1937-2287 impressao-etiqueta
  * L2288-2937 Talent doctos e HTTP 409; L2938-3286 impressao producao/
    origens
  * L3311-3465 netum-preview e saneamento; L3466-3883 integridade e JPEG
  * L3884-4185 rate limit/migrations; L4186-4630 vio-hardening e CRLV
  * L4631-5026 sanitizacao; L5027-5801 LGPD (note: L5410-5582 revisao,
    rebase, DPO, logo)
  * L5802-5992 contrato CRLV e remocao do legado Serpro
  * L5994-6006 2026-09-29: foto-nota-em-pe e captura-notas-sem-interrupcao
- Secao 8 (regra de identidade de autor): L3287-3310 (fica no meio do log)
- Regras/handoffs: docs/handoffs/ (um arquivo por demanda)

- 2026-09-30 -- **Commit e push da implementacao 1 (para medir em producao) e limpeza**: commits `2ec9093` (chore: remove integracao Trello e para de versionar `.claude/`, `CLAUDE.md`, `docs/`, estado e backup) e `3a34c9f` (feat(recebimento): foto da nota em pe e instrumentacao de medicao ?medir=1), autor Bruno Santos, sem atribuicao de IA, `bd26c91..3a34c9f` em `origin/main` (push para os dois destinos de `origin`: Brunossaantos e tiudlog). `/03-revisao` da demanda `captura-notas-sem-interrupcao` NAO foi feita: o commit foi pedido para permitir a medicao fisica no dominio de producao (https://totem.udlog.online/). `testeOcr/` (fotos reais) nao versionado. Linha de base fisica: medicao de desenvolvimento recebida com 1 captura apenas (total 18568 ms, humano inicial 18325 ms, tecnico sucesso 243 ms; resumo do painel, sem exportacao por nota) - insuficiente; faltam 5 capturas worker frio + 5 aquecido em producao, exportacoes, navegador/versao, resolucoes. Deploy em producao (Hostgator) nao verificado por este orquestrador.

- 2026-09-30 -- **Linha de base fisica (producao https://totem.udlog.online/) da implementacao 1**: worker aquecido, 5 capturas com exportacao completa: total medio 1850 ms (1505-2340), tecnico sucesso medio 386 ms, canvas 1-4 ms, encode 234-261 ms, upload 254-440 ms, recognize cerca de 5,7-6,2 s por passada, 2 passadas por nota ate identificar cliente e 1 depois, fila de numero acumula ate cerca de 25 s; OCR total nas 5 notas cerca de 41 s (passada unica economizaria cerca de 12 s). Worker frio: so resumo (total medio 1591 ms). Ambiente: tela 768x1366, viewport 1152x1846, video Netum 4096x3072. Foto de cabeca para baixo era posicao da folha (inverter o lado resolve; sem mudanca de codigo). Falha inicial de producao foi cache do navegador. Faltam: exportacao por nota do worker frio, navegador e versao, observacao do modal do numero. Detalhes no handoff.

- 2026-09-30 -- **Linha de base fisica completa (exportacoes frio e aquecido)** da implementacao 1 e decisoes: frio total medio 2065 ms (1771-2328), tecnico 649 ms, encode 229-301 ms, upload 541-707 ms, recognize 4,9-6,0 s (sem penalidade de worker frio), OCR total nas 5 notas cerca de 37 s (passada unica cortaria cerca de 11 s); aquecido: total 1850 ms, upload 254-440 ms. Tela 768x1366, video 4096x3072. Usuario decidiu NAO adotar leitura de codigo de barras (experimento: zxing-wasm 3.1.4 leu 26/77, pyzbar 38/77, 0 erro silencioso nas 41 leituras, 6 documentos distintos, 16 imagens com a folha impressa ja cortada; registrado no handoff/log) e seguir a implementacao 2: digitalizar tudo e confirmar todos os numeros so ao clicar em Finalizar. Faltam para fechar a implementacao 1: navegador e versao, observacao do modal, `/03-revisao`.

- 2026-09-30 -- **`/03-revisao` da implementacao 1 de `captura-notas-sem-interrupcao`: APROVADA (implementacao 1 fechada; commits `2ec9093` e `3a34c9f` ja publicados)**. Tres revisores novos: front-end APROVADO com ressalvas informativas, QA APROVADO (63 execucoes de equivalencia, flag desligada inativa, painel 783/783 em 768x1024, 1080x1920, 768x1366 e 1152x1846, M14 nao equivalente, 39 mutantes mortos, hashes restaurados), seguranca APROVADO. Linha de base recalculada e conferida (aquecido total 1850 ms, tecnico 386 ms, OCR 41,3 s; frio total 2065 ms, tecnico 649 ms, OCR 37,0 s; passada unica economiza cerca de 11-12 s). Resumo frio antigo (1591 ms) substituido pela exportacao completa. Navegador do totem 154.0.8037.93. Em aberto: `testeOcr/` sem regra no `.gitignore`; historico ja publicado contem docs, `.claude/`, `CLAUDE.md` e estado nos remotos; `index.php` sem versao nos assets; guard de `idAtendimento` (etapas 2 e 3). Decisao do usuario: sem codigo de barras; proximo passo e a implementacao 2 (confirmar todos os numeros so ao clicar em Finalizar digitalizacao). Handoff: `docs/handoffs/2026-09-29-captura-notas-sem-interrupcao.md`.

- 2026-09-30 -- **Implementacao 2 de `captura-notas-sem-interrupcao` (`/01-implementacao`) concluida, NAO commitada**: sem modal na captura; Finalizar habilita com >=1 nota e abre tela unica de revisao (ate 5 cartoes, miniatura so em memoria, selos com icone e texto, Continuar so com todas confirmadas, rodape sticky); cartao pendente tocado liga `manualOverride` (OCR tardio ignorado); guard/token de atendimento (corrige a lacuna preexistente) e inatividade suspensa enquanto houver upload/OCR/identificacao; cache-busting (`?v=filemtime`) em `index.php`. Arquivos: `app.js`, `app.css` (bloco `.rev-*`), `index.php`. Testes do implementador (harness do zero): 15 testes, 905 verificacoes dinamicas e 56 estaticas PASS, 52 de 55 mutantes derrubados (3 equivalentes), hashes restaurados. Pendente: decisoes do usuario (manter re-edicao de cartao confirmado; teto para a suspensao da inatividade se recognize travar), `/02-testes` e `/03-revisao` com revisores novos, commit/push e deploy (envio ao servidor). Detalhes no handoff.

- 2026-09-30 -- **Implementacao 2: teto de 120 s da inatividade e `/02-testes` APROVADA (3 revisores novos)**: `INATIVIDADE_TETO_SUSPENSAO_MS`=120000 (aviso em cerca de 300 s e cancelamento em cerca de 330 s no pior caso com trabalho ativo). QA: teto exato em 4 tipos de trabalho, fluxo/guard/traco identicos ao HEAD, instrumentacao e geometria OK nos 4 viewports, 24/26 mutantes derrubados (2 equivalentes), hashes restaurados; seguranca sem bloqueante; front-end APROVADO. Erro 400 do `definir-numero` conferido no backend: textos fixos. Ressalvas: topo cortado com 5 cartoes e altura menor ou igual a cerca de 880 px (nao afeta 768x1366 nem 1152x1846), `Date.now` no teto, modal sem Voltar, mutex do OCR preexistente, `qr-worker.js`/tesseract sem versao. Proximo: `/03-revisao`, commit/push e deploy, teste fisico no totem.

- 2026-09-30 -- **Implementacao 2 de `captura-notas-sem-interrupcao`: `/03-revisao` APROVADA e commit/push**: commits `077675d` (confirmacao dos numeros so no Finalizar, tela unica de revisao, guard/token, inatividade com teto de 120 s) e `97ae7c9` (cache-busting `?v=filemtime` no `index.php`); `3a34c9f..97ae7c9` em `origin/main` (dois destinos), autor Bruno Santos, sem atribuicao de IA; `testeOcr/` fora. Revisao: front-end, seguranca e QA APROVADO (18/20 mutantes, 2 equivalentes; funcoes de CNH/CRLV/Expedicao identicas ao HEAD). Teto real do aviso com trabalho terminando perto do fim do teto: cerca de 510 s desde o ultimo toque. Pendentes: deploy no servidor e teste fisico no totem (roteiro no handoff); decisoes abertas (Continuar antes de identificar-cliente, toque acidental sem Voltar, toast cobre Continuar, limpeza de codigo morto, `Date.now`, `qr-worker.js`/tesseract sem versao, `testeOcr/` sem ignore). Implementacao 3 (OCR de passada unica) NAO iniciada.

- 2026-09-30 -- **Melhorias pos-teste fisico (revisao mais clara + OCR de passada unica): `/02-testes` enxuta APROVADA e commit `a21411a`** (push `97ae7c9..a21411a` em `origin/main`, dois destinos; autor Bruno Santos, sem atribuicao de IA). Revisao com instrucao, cartoes e modais com "Nota N de M" e miniatura em memoria; OCR de passada unica (1 recognize por nota, numero gravado antes da identificacao do cliente, OCR em todas as notas). Com Tesseract real nas 3 notas de teste: resultado identico ao de 2 passadas e OCR das 3 notas 25,7 s -> 14,1 s (-45%). Medicao (nao adotada): imagem do OCR a 75% ou 50% mantem o numero mas muda os candidatos de cliente. Medicao `?medir=1`: `fila_cli_ms` sempre `na`, `ocr_cli_ms` = tempo da identificacao. Pendentes: deploy de `app.js`/`app.css` e novo teste fisico; observacoes (try compartilhado entre numero e candidatos; identificar-cliente por nota enquanto nao identificado; `.modal-caixa` com max-height global); regras de cliente INCONSISTENTE_CLIENTE e papel emitente/destinatario continuam pendentes.

- 2026-09-30 -- **Teste fisico em producao apos a passada unica: tudo certo (usuario)**. Exportacao `?medir=1`, 5 capturas: total medio 2961 ms, tecnico sucesso 402 ms, uma passada de OCR de 5,0 a 5,4 s por nota, `fila_num_ms` de 15 a 2000 ms (antes 6,2 a 25,3 s), identificacao do cliente com so 2 chamadas de cerca de 45 ms. A fila so acumula se o motorista capturar mais rapido que cerca de 5 s por nota. Demanda `captura-notas-sem-interrupcao`: implementacoes 1 e 2 e a passada unica concluidas, revisadas, publicadas (commits `3a34c9f`, `077675d`, `97ae7c9`, `a21411a`) e validadas fisicamente. Pendentes remanescentes: regras de cliente INCONSISTENTE_CLIENTE e papel emitente/destinatario; exigir `numero_nota` no backend (etapa propria, HTTP 422); observacoes menores (try compartilhado numero/candidatos, `Date.now` no teto, `qr-worker.js`/tesseract sem versao, `testeOcr/` sem ignore, historico publicado com docs, toque acidental sem Voltar, toast cobre Continuar, codigo morto).

- 2026-09-30 -- **`/00-planejamento` de `hardening-revisao-notas-e-cliente` concluido (somente planejamento; nenhum codigo alterado; Trello removido do projeto, sem cartao)**: 6 frentes (explorer, backend, frontend, seguranca, ux, devops). Regra de cliente definitiva (UDLOG nunca identifica; so `tb_cliente` ativa; uma correspondencia distinta = automatica; 2 ou mais = anomalia fail-closed; mesmo cliente em varias notas = 1; sem classificar emitente x destinatario, o que supera a P05) decidida pelo backend de forma DERIVADA das notas ativas, sem migration (ERRO representa conflito). Concluir-digitalizacao com 422 sanitizado (ordens 1 a 5) sob lock de linha, etapa inalterada, atras de flag. Novo `nota.php?acao=excluir` por `id_nota` (quarentena por rename, DELETE, COMMIT, unlink apos; cron de limpeza `.del`), `processar` devolve `id_nota` e `ordem`, Adicionar outra nota; front com `uid` imutavel, Voltar (desfaz `manualOverride`), confirmacao de exclusao, try isolados, `performance.now`, toast, vendor versionado com manifesto SHA-256 e `/testeOcr/` no `.gitignore`. Historico publicado com docs/.claude encerrado (sem reescrita). Achados novos: o cliente associado automaticamente nunca e gravado em `tb_atendimento` (Talent exige `cliente_cnpj`; D1); `algumaIdentificada` ignora `ativo`; fluxo manual aceita cliente do front sem validar; nenhum cron limpa fotos (cancelamento tambem nao). Fases: 1 front sem backend, 2 backend aditivo, 3 front novo, 4 ativar 422. Decisoes bloqueantes D1 a D6 abertas. Handoff: `docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md`. Implementacao NAO iniciada.

- 2026-09-30 -- **`/01-implementacao` de `hardening-revisao-notas-e-cliente` em andamento (sem commit)**: decisoes D1 a D6 e padroes aprovados pelo usuario (D1 gravar cliente unico no concluir sem sobrescrever manual; D2 `tb_cliente` ativa como unica allowlist, sem lista da UDLOG, preflight dev ok: 38 clientes ativos, 0 coincidencias com UDLOG, repetir em producao; D3 quarentena de 24 h no excluir/cancelar/abandono com cron diario; D4 `id_nota`; D5 422 atras da flag `CONCLUIR_EXIGE_NUMERO_NOTA`; D6 Adicionar outra nota sempre com menos de 5). Fase 1 (front) e fase 2 (backend aditivo) implementadas no worktree; suites `teste_hardening_*` criadas e AINDA NAO executadas; fase 3 (front novo) e fase 4 (ativar 422) nao iniciadas. Backend anterior sem migration. Detalhes no handoff `docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md`.

- 2026-09-30 -- **Fase 2 (backend) de `hardening-revisao-notas-e-cliente` executada e verde (sem commit)**: auditoria A a I FEITO; suites novas `teste_hardening_*` 104 + 56 + 192 + 42 verificacoes sem falha em 3 rodadas; 27 suites existentes verdes (algumas atualizadas ao contrato novo sem enfraquecer); 1 bug de codigo corrigido (regex de `pasta_documentos` sem placa). Sem migration, `.env` real intacto, zero Talent/impressao/VIO reais, bancos QA dropados, dev somente leitura. Contratos aditivos: `processar` devolve `id_nota` e `ordem` (ordem opcional), `definir-numero`/`identificar-cliente`/`excluir` por `id_nota`, `cliente_atendimento`, `concluir-digitalizacao` com `proxima_tela`/`cliente_estado`/`cliente_motivo` e 422 atras da flag `CONCLUIR_EXIGE_NUMERO_NOTA` (padrao desligada), cron `limpar-notas-quarentena` (24 h, CLI), cancelar com quarentena, cliente manual validado. Pendentes: fase 3 (front novo), fase 4 (ativar 422), `/02-testes` e `/03-revisao`, deploy (cron no cPanel, preflight de producao).

- 2026-09-30 -- **Fase 3 (front novo) de `hardening-revisao-notas-e-cliente` implementada e verde no smoke (sem commit)**: `uid` imutavel por nota (nasce na resposta do `processar`), upload sem `ordem`, `definir-numero`/`identificar-cliente` por `id_nota`, Excluir nota so nos modais com overlay de confirmacao (clique duplo bloqueado), Adicionar outra nota sempre com menos de 5, 422 tratado (destaque por borda e selo, mensagem fixa), cliente decidido pelo backend (`cliente_atendimento`, `proxima_tela` fail-closed, texto neutro em `rec_cliente`), aviso "Cliente identificado" removido. Smoke Chrome headless com stubs: 145 verificacoes OK. `/01-implementacao` das fases 1 a 3 concluida (sem commit, sem push); fase 4 = ativar a flag do 422 so apos o front novo estar no ar. Pendentes: `/02-testes` com integracao front+backend real em banco QA, `/03-revisao`, commit/push, deploy (cron no cPanel, preflight de producao, `vendor/` junto com o front).

- [2026-10-01] hardening-revisao-notas-e-cliente /02-testes: PRECISA DE AJUSTE (4 revisores). Falhas reais: F1 fail-open de cliente com nota PENDENTE/PROCESSANDO no concluir; cron sem limite por lote e scandir silencioso; regex sem D; restaurar() sem log; nota orfa por resposta perdida do processar; modal mantem foto; manualOverride nao resetado. Detalhes e F9 menores em docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md. Nada commitado; /03 nao iniciada; aguarda decisao do usuario sobre rodada de ajuste /01.

- [2026-10-01] hardening-revisao-notas-e-cliente, rodada corretiva R4 e nova /02-testes: APROVADA tecnicamente, sem commit. F1-F4/F7/abandono no backend; F8/F9 e F1/F7 no frontend. Validacao atual: 899 verificacoes verdes nas suites da rodada e mantidas (inclui 36 de front contra backend real), sem rede externa; revisao de seguranca aprovada com ressalvas preexistentes fora de escopo. Resta confirmar a politica de nao abandonar Talent ENVIANDO/ENVIADO/ENVIO_INDETERMINADO; aplicar migration 018, agendar os dois crons no cPanel, repetir preflight de tb_cliente em producao e validar no totem. /03, commit e push continuam nao iniciados. Handoff: docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md.

- [2026-10-01] hardening-revisao-notas-e-cliente /03-revisao: APROVADA; liberada para /04, sem commit/push nesta rodada. Politica confirmada: abandono apos 24 h so para Talent NAO_ENVIADO/ERRO_REPROCESSAVEL; ENVIANDO/ENVIADO/ENVIO_INDETERMINADO nunca sao abandonados. Quatro trilhas aprovaram; 899 verificacoes conferidas, hashes de 55 arquivos inalterados durante a revisao, QA limpo. Ressalvas nao bloqueantes: placeholder 13 px; JSON escalar/primeira conexao com tratamento tecnico preexistente; CNH/CRLV fora da quarentena; reconciliacao Talent futura; migration 018/crons e preflight UDLOG de producao pendentes. Handoff: docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md.

- [2026-10-01] hardening-revisao-notas-e-cliente /04: commits funcionais `b37b7fc730020b6cd40acce688c1f399ba17710e` (backend/migration/cron/testes PHP) e `3c3c18adad4d514cbf8aa9d67a4270278aa2b301` (front/vendor/testes interface) criados apos repetir 899 verificacoes, lint e manifest SHA-256. Politica Talent confirmada. Migration 018, crons cPanel e preflight UDLOG de producao continuam pendentes de deploy; nenhum banco real, producao ou servico externo foi acionado. Handoff: docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md.
