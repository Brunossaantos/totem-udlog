# Estado real do projeto - totem-udlog (VERSAO RESUMIDA)

> ATENCAO: este e o arquivo RESUMIDO. Todo sub-agente e o orquestrador
> devem le-lo POR COMPLETO no inicio de qualquer demanda.
> O backup completo e `ia_development_state_bkp.md` (~6750 linhas). Consulte-o
> SO em demandas complexas ou para confirmar um detalhe, lendo apenas o trecho
> necessario (ver secao 9 - indice de ponteiros). O backup NAO deve ser alterado.
> Em 2026-10-11 este resumido foi reorganizado: as entradas datadas antigas, o
> cabecalho e as secoes 1-4 e 6-9 anteriores foram movidos VERBATIM para o
> final do backup (secao `## Entradas movidas do resumido em 2026-10-11`,
> L6008-6747). O que nao consta aqui, procure la; se nao estiver la tambem, e
> "nao documentado".
> Regra: nunca inventar. O que nao esta confirmado e pendencia (secao 6).

Ultima atualizacao: 2026-10-11
Estado do codigo: main em `4188fee`; ha alteracoes nao commitadas (documentacao
completa, `CHANGELOG.md`, `documentacao/` e a demanda Talent 2xx sem
`nrRegAcesso`, implementada e revisada; commit/push em seguida).
Este arquivo e atualizado ao final de cada ciclo (etapa 04), com entrada curta.

---

## Estado atual (2026-10-11)

- [2026-10-16] Recebimento (rec_digitaliza): depois de salvar uma nota o totem mostra faixa destacada (azul UDLOG #0179AD, texto branco 24px peso 700, `role=status`): 'Nota N de 5 salva! Coloque a proxima nota no scanner ou toque em Finalizar digitalizacao.' e, na 5a, 'Limite de 5 notas atingido. Toque em Finalizar digitalizacao.'; sem timer; some ao tocar em Capturar nota/Refazer, em erro ou troca de tela; o antigo 'Documento salvo' (13px) saiu. Arquivos: `app.js`, `app.css`, `vio_captura_layout.js` (1958 verificacoes, historico). `front_r4_f8_f9.js` (70 ok/7 falhas) e `front_r4b_uid_409.js` (timeout intermitente) falham tambem sem esta mudanca (relato do implementador; nao reconferido). Deploy em producao autorizado pelo usuario em 2026-10-16 (commits d1c68fd, 6e1061f e este); resultado da verificacao no proximo registro.
- [2026-10-15] Ajustes de UI (commitados; sem deploy ate o usuario fazer): (1) titulos "Leia o QR code da CNH/do CRLV" (Exp e Rec) em faixas de destaque, CNH azul UDLOG #0179AD e CRLV grafite #3A3A3A, ambos com texto branco (so cores da paleta; cores fora da paleta exigem aprovacao); (2) removido o placeholder dos campos de placa (Exp e Rec; Rec mantem maxlength 7 e exige 7); (3) CPF do ajudante: so digitos, maxlength 11, "Confirmar" exige exatamente 11 (mensagem 'O CPF deve ter 11 números', sem chamar a API; sem DV; backend inalterado); (4) login da Gestao: ajuda passa de 'Exemplo: bruno.carvalho' para 'Formato: nome.sobrenome'. Testes (historicos): `vio_captura_layout.js` 1919, `cliente_teclado_virtual.js` 23, `gestao_layout.js` 5682, `teste_gestao_unidade` 254, `teste_gestao_http` 168. Incidente: queda de energia interrompeu um agente e deixou 6 bancos `qa_qr_exclusivo_*` em dev; removidos pelo nome exato (restam os 11 `qa_*` antigos; `udlog_totem` intacto). Servidor em `a0c830d` (deploy anterior verificado); `CONCLUIR_EXIGE_NUMERO_NOTA` ainda `false` no servidor.
- [2026-10-14] DEPLOY EM PRODUCAO verificado (somente leitura, apos o `git pull` feito pelo usuario): servidor em `a0c830d` (== origin/main), arvore limpa (so `error_log` nao rastreados), `php -l` ok (ea-php83), `app.js` servido por HTTPS com o mesmo sha256 do commit (tem `maxlength="7"` e a mensagem da placa), `find public -type f -perm 600` vazio, `.env` 600 fora do docroot, `storage` fora do docroot (700), smoke sem token ok (totem sem codigo 404, login gestao 200, `cliente.php` sem token 401, gestao sem sessao 302, estaticos 200, pastas internas 404, `/.env` 406), busca unica em producao validada por SELECT (prefixo de CNPJ ambiguo = nenhuma sugestao; prefixo unico = uma; termo `car` e inexistente = nenhuma), sem erro novo nos logs. Contagens: tb_atendimento 28, tb_cliente 38/38 ativos, tb_totem 2, tb_log_sistema 163, OCs 1 ATIVA/12 INATIVA. Flags no servidor: `TALENT_CHECKIN_ATIVO=true`; `CONCLUIR_EXIGE_NUMERO_NOTA=false` (AINDA NAO ativada; usuario pretende ativar). Observacoes: diretorio `public` com 750 (site responde normalmente); `display_errors` web so se confirma por requisicao de erro.
- [2026-10-14] Recebimento (sem commit/push; sem deploy): (1) campo de placa aceita no maximo 7 caracteres (`maxlength`, teclado virtual respeita o limite, front exige exatamente 7; backend NAO valida formato); (2) BUSCA MANUAL DE CLIENTE (quando o OCR nao identifica) mostra no maximo UMA sugestao: so quando o texto digitado resulta em um unico cliente ativo (CNPJ por prefixo em digitos; nome por trecho; normaliza acentos/espacos/caixa); 0 ou >1 = nenhuma; o motorista toca para confirmar; `cliente.php?acao=buscar` devolve 0 ou 1 item so com `nome` e `cnpj` (`ClienteDao::buscarUnicoPorTermo` substitui `buscarPorTermo`; `RazaoSocialMatcher::normalizarTextoBusca`). Inalterados: OCR automatico, Expedicao, Talent, banco/schema. Testes (QA/mock; historicos): `teste_busca_cliente_unica` 28, `teste_gestao_cadastros` 547, `teste_hardening_cliente` 104, `cliente_teclado_virtual.js` 23, `vio_captura_layout.js` 1868. Incidente: `teste_identificar_cliente.php` (usa o dev) rodado por engano 2x; dev conferido limpo. Handoff: `docs/handoffs/2026-10-14-busca-manual-cliente-recebimento.md`. Documentacao e CHANGELOG atualizados.
- [2026-10-11] DECISAO DO USUARIO + IMPLEMENTACAO: Talent responde HTTP 2xx sem
  `nrRegAcesso` utilizavel (ausente/null/nao-string/vazio/so espacos/corpo
  nao-JSON/nao-array/204; criterio: string com `trim() !== ''`, "0" e "00123"
  valem) = atendimento CANCELADO e motorista ao guiche da portaria (antes:
  `ENVIADO`/concluido com senha NULL). `TalentClient` lanca
  `TalentClientException('resposta_ilegivel')`; `TalentRn::processarCheckin` chama
  `AtendimentoDao::cancelarPorRespostaSemRegistroTalent` (CAS por
  `talent_tentativa_id` + `ENVIANDO` + `status != 'concluido'`; grava
  `ENVIO_INDETERMINADO`; `status` vira `cancelado` so se `em_andamento`);
  `AtendimentoController::finalizar` responde 422 `TALENT_SEM_REGISTRO`
  (`dados.acao=guiche_portaria`, `tentar_novamente=false`, sem senha), log
  `talent_indeterminado` com `categoria_erro=resposta_ilegivel`, fotos das notas
  em quarentena, OC da Expedicao NAO baixada; 2a chamada de `finalizar` = 400.
  Front: tela `guiche_portaria` em `public/totem/assets/impressao.js` (so botao
  "Voltar ao inicio"; sem retry, ajudante nem impressao; `cancelar` nunca
  chamado; inatividade 180 s volta ao inicio sem aviso), Exp e Rec. Revisoes de
  QA, seguranca e UX APROVADAS. Contagens HISTORICAS (nao reexecutadas na
  documentacao): `vio_captura_layout.js` 1847 (102 novas), `teste_talent_sem_registro`
  142 (apos ajustes de CAS/cobertura); testes: `tests/manual/teste_talent_sem_registro.php`,
  `_caso_finalizar_talent_sem_registro.php` (ignorado pelo `.gitignore`: entra por
  `git add -f`), `teste_talent_client_parsing.php` atualizado. Arquivos:
  `app/Rn/TalentClient.php`, `app/Rn/TalentRn.php`, `app/Dao/AtendimentoDao.php`,
  `app/Controller/AtendimentoController.php`, `public/totem/assets/app.js` e
  `impressao.js`. Documentacao (`documentacao/`, `README.md`, `CHANGELOG.md`) e
  `servico-impressao-local/README.md` atualizados. Decisao do usuario de ATIVAR
  `CONCLUIR_EXIGE_NUMERO_NOTA` em producao (ligada: 422 `NOTAS_SEM_NUMERO` no
  `concluir-digitalizacao`; desligada: so o `finalizar` recusa numero vazio);
  valor no servidor NAO confirmado. Este estado foi reorganizado hoje (entradas
  antigas anexadas ao bkp, L6008+). Commit/push vem em seguida.
- [2026-10-11] Totem (Expedicao e Recebimento) em producao. Gestao Totem F0-F4 e
  F6 reduzida (cadastro de clientes e empresas) em producao; projeto da Gestao
  ENCERRADO.
- [2026-10-09/11] DESCARTADOS pelo usuario (nao serao feitos): F5 (atendimentos
  do dia, travados com Encerrar, anexos orfaos), painel, status das integracoes,
  saude dos crons, fotos de notas no Recebimento, recalculo da razao
  normalizada (ids 11/33/35), correcao do `teste_gestao_totem_f0`, observacoes
  de n8n 406 anonimo e LiteSpeed sem `fastcgi_finish_request`.
- [2026-10-09] Higienizacao: todas as migrations removidas; `sql/schema.sql` e a
  UNICA fonte da estrutura do banco do totem (provado identico a producao); a
  do banco externo esta no dump local `docs/<CONTA_CPANEL>_db_gestao_coletas.sql`
  (git-ignorado). Para mudar o banco no futuro, criar nova migration.
- [2026-10-09/10/11] Documentacao completa: `documentacao/` (banco, regras de
  negocio, implantacao, plano de testes, `apis/`, `fluxos/` 01-15),
  `README.md` e `CHANGELOG.md` (marcos M1-M11 + rodadas 2026-10-09/10/11).
  Em caso de divergencia, o codigo vence; `documentacao/` e a referencia
  detalhada de cada rota, fluxo e regra.
- [2026-10-09] Servidor em `4188fee` no ultimo deploy registrado (RECONCILIADO:
  o estado antigo dizia `9d44513`; o deploy da higienizacao foi feito em
  `4188fee`). Alteracoes locais posteriores (documentacao e outra demanda em andamento)
  ainda sem commit/deploy.
- [2026-10-10] Dump do banco externo atualizado (estrutura de producao, 13
  tabelas, sem dados; leitura autorizada pelo usuario).
- [2026-10-10] CONFIRMADOS pelo usuario: os 5 crons funcionando (incl.
  `limpar-logs-gestao`), PHP web 8.3, `TALENT_CHECKIN_ATIVO=true` em producao,
  dependencias instaladas pelo Composer (`composer install --no-dev
  --optimize-autoloader`), `display_errors=Off`.
- [2026-10-10] DECISOES DE PRODUTO: validacao de metodo HTTP nas APIs do totem e
  politica de retencao (atendimentos, fotos de notas, aceites LGPD) NAO serao
  tratadas; contrato da vio.api.br sem pendencia externa (vale o codigo e os
  testes).
- [2026-10-11] Decisao do usuario: `CONCLUIR_EXIGE_NUMERO_NOTA` sera ATIVADO em
  producao (422 se faltar numero de nota no concluir-digitalizacao; padrao do
  codigo e `.env.example` = `false`). Valor atual no servidor NAO confirmado.

## 1. O que e o projeto

Totem de autoatendimento fisico da UDLOG (United Logistics), com dois fluxos:
Expedicao (retirada de carga) e Recebimento (entrega de carga). O motorista
interage sozinho; ao final o sistema externo Talent devolve o registro
(`nrRegAcesso`) e a etiqueta e impressa. A Gestao Totem (`/gestao/`) e o painel
web de administracao (usuarios, totens, logs, ordens de coleta, clientes,
empresas).

Hardware: mini PC Windows + monitor touchscreen 18,5" em RETRATO. O Netum
SD-2000 e o scanner de documentos: dispositivo de video USB (`getUserMedia`),
NAO e leitor HID; e o unico equipamento de captura (resolucao validada
3264x2448). Impressora: EPSON TM-T88VII (driver `Receipt6`, porta `TMUSB001`).
Quiosque: Chrome em modo kiosk no mini PC de producao (`totem.udlog.online`).

## 2. Stack confirmada

- Back-end: PHP 8.x (web de producao 8.3), MVC em camadas (Model / Dao / Rn /
  Controller) mais `util/`; dependencias por Composer (`vlucas/phpdotenv`,
  `setasign/fpdf`; no servidor `composer install --no-dev
  --optimize-autoloader`)
- Banco do totem: MariaDB/MySQL via PDO com prepared statements (producao
  MySQL 5.7.44; dev XAMPP `udlog_totem`). Estrutura: `sql/schema.sql`
- Banco externo de coletas `<CONTA_CPANEL>_db_gestao_coletas` (conexao PDO propria,
  leitura e escrita autorizadas; estrutura no dump local `docs/`)
- Front-end: HTML/CSS/JS vanilla, sem framework nem build; OCR client-side
  (Tesseract.js, `vendor` versionado com manifesto SHA-256 verificado por
  `tools/verificar-vendor.php`)
- Hospedagem: Hostgator compartilhada (cPanel). Sem SSH root, sem processo
  persistente/WebSocket, sem instalar binario; cron so via cPanel
- Servico local de impressao: Node em `servico-impressao-local/`, no mini PC,
  escuta so em 127.0.0.1 (Sumatra), autostart por TAREFA DE LOGON
- Integracoes: Talent (check-in), vio.api.br (CNH/CRLV por QR), n8n (envia o PDF
  da ordem de coleta), Cloudflare na frente do dominio
- Crons (cPanel, ea-php83): `limpar-rate-limit-ocr`, `abandonar-atendimentos`,
  `limpar-notas-quarentena`, `limpar-anexos-ordem-coleta`, `limpar-logs-gestao`

## 3. O que ja existe (implementado)

Detalhe de cada rota, fluxo e regra: `documentacao/` (indices em
`documentacao/apis/README.md` e `documentacao/fluxos/README.md`).

Totem (front e fluxo)
- 26 telas (`renderTela` em `public/totem/assets/app.js`; `rec_cnh` e so nome de
  etapa do backend, mapeada para `rec_cnh_qr`). Teclado virtual pt-BR (dispara
  o evento `input`), inatividade (aviso 180 s/30 s, com teto de 120 s de
  suspensao quando ha trabalho ativo), cancelar em toda tela exceto a inicial
- `autocomplete="off"` em todos os campos de digitacao do quiosque (2026-10-09)
- Tela inicial LGPD: gate obrigatorio, aceite no backend (`tb_lgpd_aceite`, token
  de uso unico por CAS), termo `2026-09-25-v2`; paleta UDLOG; titulo `Totem` e
  favicon proprio; assets versionados por `filemtime`
- Pagina do totem (`public/totem/index.php`): URL `?totem=NOME-EMPRESA-HASH16`,
  404 uniforme para codigo invalido, rate limit so de falhas por IP

Documentos do motorista (QR-only, vio.api.br)
- CNH e CRLV (Exp e Rec) por UMA tela de QR; o JPEG do quadro vai direto a
  `VioApiBrClient` sob CAS (um POST, `comparar=true`, sem retry, sem cache, sem
  storage); 3 falhas de leitura levam ao preenchimento manual; reprovacao com
  `motivo_usuario` e re-escaneio (front limita a 3)
- Aprovacao: leitura concluida + QR esperado + campos validos; a comparacao VIO e
  so diagnostica. RNTRC e Tipo opcionais na leitura, obrigatorios na
  confirmacao e no `finalizar` (422 `CONFIRMACAO_INCOMPLETA`)
- Tela "Confirme os dados" em cartoes; rebaixamento para MANUAL quando o
  atendente altera dado validado; escolha CNH fisica/digital, upload e PDF foram
  REMOVIDOS; legado Serpro/Prodesp removido

Recebimento (notas e OCR)
- Digitalizacao pelo Netum (ate 5 notas), foto em pe (rotacao 270 no canvas),
  OCR de passada unica por nota (client-side, Web Worker), revisao unica de ate 5
  cartoes ao Finalizar, excluir nota (quarentena de 24 h), mais de 5 notas =
  bloqueio e balcao da portaria
- Cliente decidido pelo backend a partir das notas ativas: so `tb_cliente`
  ativa (UDLOG nunca identifica); uma correspondencia = automatica; 2 ou mais =
  conflito fail-closed; sem classificar emitente/destinatario
- `Util\RazaoSocialMatcher::normalizar` sem acentos, independente de locale
  (decisao "sempre normalizar os textos para nao ter acentos")
- Flag `CONCLUIR_EXIGE_NUMERO_NOTA` (422 ao concluir sem numero; padrao `false`;
  sera ativada em producao; desligada, so o `finalizar` recusa numero vazio)

Expedicao e ordens de coleta
- Consulta de OC por placa no banco externo; baixa da OC (INATIVA) apos check-in
  aceito, agora POR CLIENTE + numero (`cliente_cnpj` gravado pelo servidor); sem
  CNPJ registra baixa pendente (`tb_ordem_coleta_pendente_baixa`)
- Anexo da OC: o n8n envia o PDF para `public/api/ordem-coleta-anexo.php`
  (Bearer, base64, 5 MiB); arquivo em `STORAGE_PATH/ordens_coleta/`; anexado ao
  check-in Talent; cron apaga 15 dias apos a OC INATIVA

Talent e impressao
- Check-in `POST https://api.talentcs.com.br/Portaria/Checkin` com `doctos[]`
  real e anexos base64; idempotencia por `talent_checkin_status` com CAS;
  `mensagem_api` sanitizada no erro; correcao dos dados do ajudante; SEM fila de
  reenvio (removida em 2026-10-05; nova tentativa so manual); Talent nao exige
  mais "Nr. Pager"; resposta 2xx sem `nrRegAcesso` utilizavel cancela o
  atendimento (422 `TALENT_SEM_REGISTRO`, tela do guiche da portaria, sem senha)
- Etiquetas: duas (motorista e ajudante), 80x80 mm com 48 mm uteis, so ASCII;
  falta de papel detectada pela fila do spooler (409 `sem_papel`); impressao no
  mini PC de producao validada fisicamente em 2026-10-06; `impressao-teste.php`
  (diagnostico, exige token) permanece

Gestao Totem (`public/gestao/`, `app/Views/gestao/`, `Gestao*Controller`)
- F0/F1: login `primeiro.segundo`, sessao em tabela (30 min inatividade, 12 h
  absoluto), CSRF, RBAC `admin`/`usuario`, usuarios, Minha conta, auditoria
  append-only, `tools/criar-admin.php`
- F2 Totens (so admin): criar/listar/ativar/desativar/regerar URL
  (`NOME-EMPRESA-HASH16`; o token da API NAO muda ao regerar)
- F3 Logs: `tb_log_sistema` (`LogSistema`, catalogo, tetos e throttle), tela de
  logs (so admin) e cron `limpar-logs-gestao` (90 dias, tambem poda a auditoria)
- F4 Ordens de coleta: abas Ativas/Ativas >15 dias/Inativas/Baixas pendentes,
  ativar/inativar, PDF por POST; `usuario` e `admin` operam; SEM mascara
- F6 reduzida: CRUD de Clientes e Empresas (so admin; `tb_cliente` do totem; CNPJ
  imutavel; aviso de ambiguidade do OCR com confirmacao em dois passos)

Seguranca e integridade (resumo)
- IDOR validado por posse do atendimento; `concluido` terminal e imutavel (409);
  upload JPEG seguro (`UploadHelper`); logs sanitizados (nunca `getMessage`/PII);
  rate limit de OCR; token do totem por `Util\Auth`; `storage/` fora do webroot

## 4. Decisoes ja tomadas (nao reabrir sem pedido explicito)

Produto e UX
- Orientacao RETRATO; botoes de cor solida (nunca dependentes de `:hover`);
  "Cancelar atendimento" em TODAS as telas exceto a inicial; sem barra de marca
  fixa no topo (logo oficial so na tela LGPD)
- Ajudante "sim": pede nome completo e CPF (11 digitos, sem DV, decisao do usuario)
- Mais de 5 notas: bloqueia e direciona ao balcao; 5 ou menos: segue
- Atendimento concluido e terminal e imutavel (HTTP 409); reabertura NAO planejada
- Paleta UDLOG: #0179AD (acao/links/foco), #3A3A3A (texto), #878789 (texto
  secundario com contraste), #9BA0A5 (icones), #B0B0B1 (bordas), #FFFFFF; so essa
  paleta, tambem na Gestao
- LGPD: gate obrigatorio, termo versionado (DPO vale so para a versao exata),
  botao "Iniciar", logo so nessa tela; trade-offs de acessibilidade aceitos
- Sem codigo de barras (decisao de 2026-09-30); sem 2FA, sem bloqueio por IP,
  sem recuperacao de senha por e-mail
- Gestao: login livre `primeiro.segundo`; URL do totem `NOME-EMPRESA-HASH16`;
  retencao de 90 dias (logs e auditoria); "API" nos logs = falhas de integracao
  sem atendimento; extras descartados (ver "Estado atual")
- F4: sem mascara e sem rate limit por usuario; resolver baixa em UM clique; ativar
  OC de cliente inativo exige confirmacao em dois passos
- F6: CNPJ imutavel; CNPJ da UDLOG bloqueado como cliente; excluir cliente e
  fisico (inativar/ativar e separado); excluir empresa so sem totens
- Nenhuma validacao de metodo HTTP nas APIs do totem e nenhuma politica de
  retencao de atendimentos/fotos/aceites LGPD (decisao 2026-10-10)

Tecnicas
- Banco: so `sql/schema.sql` descreve a estrutura; sem historico de migrations
- Clientes: fonte e `tb_cliente` local; `ClienteApiClient` e codigo sem uso
- Campos persistidos de documentos: CNH (nome, cpf, data_validade) e CRLV
  (exercicio, placa, uf, rntrc, tipo), nunca o JSON de retorno; `motorista_*`
  em texto plano em `tb_atendimento`
- Convencao de pasta de documentos `AAAA-MM-DD/PLACA_HHMMSS` (so navegacao humana)
- Talent: anexos em JSON base64 (nao multipart); POST real so com
  `TALENT_CHECKIN_ATIVO=true` e autorizacao explicita do usuario para testes
- OCR so client-side; rotacao fixa de 270 graus; nota sem candidato valido segue
  para confirmacao manual
- Etiqueta: 80x80 mm com 48 mm uteis (a TM-T88VII imprime ~50,8 mm; causa nao
  confirmada); impressao so em impressoras fisicas da allowlist; parada manual
  do servico sempre por PID
- Autostart da impressao por tarefa de logon (servico do Windows descartado)
- Politica de abandono (cron, 24 h): so Talent NAO_ENVIADO/ERRO_REPROCESSAVEL;
  ENVIANDO/ENVIADO/ENVIO_INDETERMINADO nunca
- Fila de reenvio ao Talent REMOVIDA: nenhuma falha e reenviada automaticamente
- Regerar a URL do totem nao rotaciona o token (risco aceito); token do totem
  na pagina nao muda (risco aceito)
- Processo: nenhuma chamada externa paga, impressao real ou POST real ao Talent
  sem autorizacao previa; testes que escrevem usam banco `qa_` descartavel,
  nunca o `udlog_totem`; nao executar cron contra dev/producao sem autorizacao
- Comentarios no codigo-fonte foram removidos (2026-10-09)

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

## 6. Pendencias reais abertas

Legenda: IDs P## preservados do estado anterior (texto completo no bkp, secao
movida L6008+); P69+ sao novos (vindos de `documentacao/`). "Decisao" =
decidido pelo usuario, sem trabalho previsto. Confirmacoes de ambiente
pendentes ficam marcadas como [CONFIRMAR].

### 6.1 Funcionais e de codigo (registrados, NAO corrigidos)
- P69 `ENVIO_INDETERMINADO` do Talent por timeout/falha de rede sem saida (nem
  tela, nem cron): atendimento fica `em_andamento` para sempre; `cancelar` nao
  considera `talent_checkin_status` (cancelar durante o envio pode ser
  sobrescrito por `ENVIADO`; sem teste). O caso "2xx sem `nrRegAcesso`" esta
  RESOLVIDO (2026-10-11: cancelado + guiche)
- P84 (baixa severidade) A portaria so identifica o caso "2xx sem
  `nrRegAcesso`" (cancelado + `ENVIO_INDETERMINADO`) pelo `tb_log_sistema`
  (`categoria_erro=resposta_ilegivel`); sem campo proprio no atendimento, e o
  Talent pode ter registrado o check-in sem devolver a senha
- P70 Codigos de erro inconsistentes entre rotas/controllers
- P71 `salvar-etapa` com etapa desconhecida responde 200 `{"ok":true}` sem efeito
- P72 Empresa inativa: `finalizar` responde 500 (`EmpresaDao` filtra `ativo=1`)
- P73 Edicoes da tela "Dados da ordem" (`exp_dados`) nao sao persistidas nem
  enviadas
- P74 Atendimento orfao em `placa`: o token LGPD e consumido ANTES da consulta de
  OC; placa sem OC (400) ou consulta falhando (502) deixa `em_andamento` ate o
  cancelamento do front ou o abandono de 24 h
- P52 Sem teto de re-escaneio de CNH/CRLV no backend (so o front limita a 3) e
  sem rate limit especifico
- P75 Papel emitente/destinatario NAO implementado (decisao de 2026-09-29 exigia;
  hoje qualquer CNPJ candidato valido em `tb_cliente` ativo, exceto UDLOG,
  identifica); inclui P05 (como o front detecta 2 ou mais clientes) e P09
  (fallback por razao social so com correspondencia unica)
- P76 `finalizar` nao exige/confere `cliente_nome` no Recebimento (so a
  confirmacao exige)
- P77 Cache VIO (`VIO_CACHE`/`VioCacheDao`) e `ClienteApiClient` sem uso no fluxo
  atual (codigo morto; nao removidos)
- P78 IP do ator gravado (binario) em `tb_gestao_auditoria.ip` (divergente da frase
  "auditoria sem PII"); sem rate limit de falhas de token nas APIs do totem;
  limites de login por conta/IP nao atomicos entre processos
- P79 Sem validacao de formato de placa no backend nem no front
- P80 `S6`: excecao apos o commit do UPDATE de status de OC fecha a auditoria como
  `ERRO` mesmo se o estado mudou; `ClienteDao::buscarPorTermo` com LIKE sem escape;
  `tb_atendimento` so tem `idx_status` (sem indice composto por `ordem_coleta`)
- P22 RNTRC/Tipo opcionais na leitura e obrigatorios na confirmacao; cenario
  "veiculo particular sem RNTRC" segue sem regra (hoje bloqueia); RNTRC sem
  validacao de formato
- P25 Campos do payload Talent sem fonte de captura (reboque, exigePesagem,
  transportadora, telefones, categoria CNH, pernoite, paletes, container/lacre,
  obs, multiplos ajudantes): quais sao necessarios
- P26 Origem da chave de acesso da NF-e sem leitor HID (payload envia `chave:
  null`); sem substituto definido
- P27 [PARCIAL] Falta de papel ja e detectada pela fila; papel atolado/tampa
  aberta seguem sem deteccao (sucesso do spooler nao confirma impressao fisica)
- P28 Acesso a tela de diagnostico de impressao (`#diagHotspot`, toque longo) e
  escolha de implementacao, nao decisao de UX formal
- P38 Recarregar a pagina nao retoma o atendimento (limitacao preexistente)
- P39 Heuristica de razao social do OCR; `LIMIAR_MINIMO=80`/`MARGEM_MINIMA=15` do
  `RazaoSocialMatcher` sem calibracao com dados reais (inclui P14, 3a nota)
- P40 Causa do mojibake do seed em 8 dos 38 clientes nao investigada (dados
  corrigidos); P10 descarte por cancelamento nao gera `res_descart_*` (produto)
- P41-P44 Baixa severidade: `etapaEhAlvoOuPosterior()` posicional;
  `ehValorPlaceholder()` trata digito unico como placeholder; rotulo de origem VIO
  duplicado entre front e back; trade-offs de acessibilidade da LGPD aceitos
- P12 Baixa severidade da medicao `?medir=1` (buffer de 500, empate de ms,
  contraste `#B0B0B1` 2,17:1)
- P21 Risco aceito: `comparacao.campos` fora da decisao de aprovacao automatica
- P48 Contrato de erro da Talent nao documentado (400 real = `{errors:{...},status}`
  ASP.NET); sentido de "cracha ja associado" nos dados do ajudante
- P49 "Corrigir dados do ajudante" aparece por texto da `mensagem_api`
  ("ajudante"): criterio fragil
- P51 CPF do ajudante so exige 11 digitos (decisao do usuario)
- P56-P63 anexo-oc (validos): P56 `1062` por `str_contains` em `errorInfo[2]`,
  orfaos de arquivo e `.tmp_*` sem varredura, `caminhoReservado` engole excecao,
  rate limit por `REMOTE_ADDR` com contador sem teto; P57 rajada >30 envios/s
  com a mesma parte de nome = 503, `Authorization` removivel pelo Apache/FPM,
  `X-Forwarded-Proto` forjavel (tambem em `AuthServidor::requisicaoHttps`), sem
  tamanho minimo de chave; P58 `teste_oc_anexo_lacunas.php` sai com rc 0 mesmo
  com falhas; P59 `link()` validado so no Windows/NTFS (ha fallback `fopen 'xb'`);
  P60 confirmar em producao `tb_clientes.cnpj` VARCHAR(14) so digitos; P62 teste
  real na Talent com PDF ~5 MB so com autorizacao (timeout 30 s =
  ENVIO_INDETERMINADO); P63 demanda do n8n em `docs/n8n-anexo-ordem-coleta.md`
  (workflow inativo, URL/credencial a configurar)
- P81 Gestao, baixa severidade: login/sessao (token do formulario sem estado reutilizavel por
  2 h, bloqueio de conta como DoS recuperavel, senha temporaria sem expiracao,
  flash de sessao expirada sem uso); `catch` de `buscarClientes` engole erros;
  `NomeCadastro` aceita invisiveis raros; acoes POST da F6 voltam sem
  filtros/pagina; "Voltar e editar o nome" (F6) abre o form em branco

### 6.2 Fisicas, ambiente e confirmacoes
- P47 Testes fisicos pendentes: falta de papel real (409 `sem_papel`, fluxo e
  alerta), duas etiquetas em sequencia (motorista + ajudante) e leitura de QR
  com o Netum
- P32 Validacao fisica do Netum como `videoinput` no Chromium de producao (label,
  resolucao); P33 OCR client-side (tempo, precisao, cancelamento de fila);
  P36 persistencia de permissao de camera no Chromium kiosk
  (`VideoCaptureAllowedUrls`); P35 [CONFIRMAR] `storage/` fora do document root
  real; P54 medidas fisicas finais do papel (tamanho total, cortes, posicao) e
  causa dos 48 mm uteis; P19 [CONFIRMAR] credencial da vio.api.br vigente em
  producao
- [CONFIRMAR] valor de `CONCLUIR_EXIGE_NUMERO_NOTA` no servidor (a decisao do
  usuario e ativa-lo); `STORAGE_PATH` gravavel no cPanel (sem isso 401/recusas
  anonimas nao sao logados) e SAPI/FPM da Hostgator; segundo usuario MySQL so
  para DELETE da auditoria (nao confirmado); `no-store` da pagina do totem no
  quiosque; Argon2id/cookie `Secure`/Cloudflare/`REMOTE_ADDR` so verificaveis em
  producao
- Mini PC: `scripts/instalar-servico-windows.js` e `desinstalar-servico-windows.js`
  (legado) ainda existem no repo ao lado da tarefa de logon
- Documentos externos placeholder: `docs/db_gestao_coletas.md` (template do
  fornecedor, parte de ordens por placa vazia) e `docs/manual_talent.md`; corpo de
  erro HTTP 400 do Talent; aprovacao do DPO so relatada

### 6.3 Testes e higiene do repositorio
- P55 Falhas preexistentes (testes desatualizados): `teste_hardening_exclusao` E17,
  `teste_talent_payload` 41/45 e `teste_talent_anexos_pdf` 15/19 (esperam anexos
  de CNH/CRLV); `teste_concorrencia_finalizar_checkin`,
  `teste_talent_idor_finalizar` e `teste_talent_trava_doctos_pendente` so passam
  com `TALENT_CHECKIN_ATIVO` nao-true no `.env` do dev
- P82 Divida de teste por fim de linha: `teste_gestao_totem_f0` (7 falhas desde
  `d1ffda5`: guarda do prepend + CRLF em `public/totem/index.php`; correcao
  DESCARTADA pelo usuario) e `teste_gestao_logs` (1 falha por CRLF; passa em LF)
- P24 `teste_consulta_ordem_coleta` falha (12/17) por fixture do banco externo
  (OC-TESTE-005/TST0A01 inexistentes; OC-TESTE-001 INATIVA)
- P23 `teste_lgpd_aceite_backend_seguranca` depende de `tests/manual/_fixtures_lgpd.php`
  (nunca versionado; `_*.php` e ignorado pelo git; 16 `_*.php` fora do Git);
  `teste_status_processamento` flaky por timing; 4 suites possivelmente obsoletas
- P53/P64 Residuos de dev em `udlog_totem`: totem `TESTE_E2E_16a493` (id 1845),
  atendimentos 12924 e 12936 (TLT9001, `teste_preparacao_producao_checkin`) e
  bancos `qa_*` antigos; limpeza depende de autorizacao
- P83 [RESOLVIDA 2026-10-09/10] Worktree `totem-udlog-worktree-lgpd` e branches `tela-inicial-lgpd-totem` e `higienizacao` REMOVIDOS (usuario autorizou; nada era usado).

### 6.4 RESOLVIDAS / REMOVIDAS / DESCARTADAS (nao listar como abertas)
- RESOLVIDAS: Talent 2xx sem `nrRegAcesso` (2026-10-11: atendimento cancelado e
  guiche da portaria; sem `ENVIADO` com senha nula); P17 (removida, contrato VIO sem pendencia externa, 2026-10-10); P20
  (`display_errors=Off`); P37/P68 (`TALENT_CHECKIN_ATIVO=true` em producao); P18/P45/P46/P61/P66/P67
  (migrations e crons: 019 e externas 001-004 aplicadas; 5 crons agendados e
  confirmados em 2026-10-10; mini PC/papel/tarefa de logon em producao); P30/P31
  (`origensPermitidas` de producao e impressao validadas fisicamente em
  2026-10-06); P34 (nomes dos bancos conferidos nos deploys); P29 (cadastro de
  clientes pela F6); P65 (Talent desligou o "Nr. Pager"); P50 (fila removida)
- Demanda `captura-notas-sem-interrupcao`: P01-P04, P06, P07, P11, P13, P15, P16
  resolvidas (implementacoes 1-3, passada unica, guard de `idAtendimento`,
  validacao fisica em producao em 2026-09-30; sem codigo de barras); P08
  implementada como flag `CONCLUIR_EXIGE_NUMERO_NOTA` (ver [CONFIRMAR])
- DESCARTADAS pelo usuario: F5, painel, status das integracoes, saude dos crons,
  fotos de notas, recalculo da razao normalizada, correcao do
  `teste_gestao_totem_f0`, n8n 406 anonimo, LiteSpeed sem `fastcgi_finish_request`,
  validacao de metodo HTTP, politica de retencao

## 7. Fora de escopo (nao sugerir sem pedido)

- Infraestrutura com processo persistente, WebSocket de longa duracao ou
  instalacao de binario no servidor Hostgator
- OCR server-side (Tesseract PHP): so client-side (Tesseract.js)
- Reabertura de atendimento concluido, bloqueio por IP, 2FA, recuperacao de senha
  por e-mail, F5/painel/extras da Gestao descartados (ver secao 6.4)

## 8. Log resumido (uma linha por marco/demanda, cronologico)

Hashes do `git log` (somente leitura); detalhes e ponteiros no bkp (secao 9).
- 2026-09-03 projeto criado: digitalizacao de notas (rec_digitaliza), scanner Netum SD-2000 como video USB, 3264x2448 (`22eeba0`, `ae7f162`)
- 2026-09-04/08 leitura de notas por OCR client-side e clientes pela tabela local `tb_cliente` (`b6ab1b0`)
- 2026-09-08/11 VIO Decode/Serpro (depois removido), Talent contrato real, consulta de OC no banco externo (`89b8b5d`, `bdc8d91`)
- 2026-09-11/16 servico local de impressao (Node), allowlist, origens permitidas, separacao producao x teste (`44ec896`, `b6b5129`, `853857a`)
- 2026-09-14/15 Talent: doctos[] real, 4a tentativa real com sucesso, HTTP 409 (`2369f96`, `5332a86`)
- 2026-09-16/20 integridade da conclusao, JPEG seguro, rate limit/migration 013, hardening VIO (`a4b8e0a`, `03af1df`, `1e244cc`, `997139c`, `761c0ff`, `4c8665f`)
- 2026-09-24/25 tela inicial LGPD (gate, termo v2, paleta UDLOG) (`88c6a31`, `30f70c0`, `4cef4a0`)
- 2026-09-25/28 migracao para vio.api.br, CNH digital e remocao do legado Serpro (`d56c035`, `d5212cc`, `9da2516`)
- 2026-09-29/30 foto da nota em pe, medicao, revisao unica de notas e OCR de passada unica (`3a34c9f`, `077675d`, `97ae7c9`, `a21411a`); remocao do Trello (`2ec9093`)
- 2026-09-30/10-01 hardening de revisao de notas e cliente: cliente derivado, excluir nota, quarentena (`b37b7fc`, `3c3c18a`, `fb13a2f`)
- 2026-10-01/04 fluxo QR-only de CNH/CRLV, reprovacao com re-escaneio, confirmacao em cartoes (`deb0901`, `cea5c36`, `0a8c800`, `db7c9b1`)
- 2026-10-02/04 etiquetas motorista + ajudante, `mensagem_api`, tarefa de logon, falta de papel (`c2dcd0c`, `6933cbc`, `75e4bb1`)
- 2026-10-05 anexo da OC pelo n8n (`3bc0497`); remocao da fila de reenvio ao Talent (`6a3f353`); deploy e 4 crons
- 2026-10-06 impressao validada no mini PC de producao (`ea2c291`); Gestao Totem F0/F1 (`fae1d05`)
- 2026-10-08 F2 Totens e deploy da Gestao em producao (`fab53ab`, `785ad6f`); teclado virtual dispara `input` (`9aec6cb`, `93baea1`)
- 2026-10-08 F3 logs: logger/retencao, tela e instrumentacao (`c4411ce`, `5b91bd6`, `d1ffda5`); leao na sidebar (`93f3751`)
- 2026-10-09 F4 ordens de coleta (`325fe32`), ajustes (`6ff47ff`), em producao (`f60ebc2`)
- 2026-10-09 F6 reduzida: clientes e empresas (`51c7ceb`); normalizacao sem acentos (`2903857`); fechamento da Gestao (`3be1665`)
- 2026-10-09 comentarios removidos (`d783768`); `autocomplete="off"` no totem (`9d44513`); recalculo descartado (`1d74db9`)
- 2026-10-09 higienizacao: schema completo, migrations removidas, testes ajustados (`6ac26e9`, `95d11c5`, `1a69a1c`, `4188fee`)
- 2026-10-11 Talent 2xx sem `nrRegAcesso` cancela o atendimento e orienta o guiche (implementado e revisado; nao commitado)
- 2026-10-09/10/11 documentacao completa (`documentacao/`, `README.md`, `CHANGELOG.md`), rodada corretiva e reorganizacao deste estado (nao commitado)

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
- NOVO (movido do resumido em 2026-10-11): `## Entradas movidas do resumido em
  2026-10-11`, L6008-6747
  * L6012-6083 entradas datadas do topo do resumido (2026-10-01 a 2026-10-10:
    QR-only, impressao, remocao da fila, anexo n8n, Gestao F0-F6, higienizacao,
    normalizacao, documentacao e rodada corretiva)
  * L6084-6091 cabecalho/meta anterior
  * L6092-6359 secoes 1-4 anteriores (projeto, stack, existente, decisoes)
  * L6360-6747 secoes 6-9 anteriores: pendencias P01-P68 originais, log
    resumido antigo, indice, e entradas de 2026-09-30 a 2026-10-02
