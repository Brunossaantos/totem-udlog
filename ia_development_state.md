# Estado real do projeto - totem-udlog (VERSAO RESUMIDA)

- [2026-10-06] `gestao-totem` F0+F1 (/00 a /03 APROVADAS por QA, seguranca, UX e backend independentes; correcoes finais pequenas depois; handoff `docs/handoffs/2026-10-06-gestao-totem.md`). F0: pagina do totem (`public/totem/index.php`) endurecida: 404 uniforme para codigo invalido, rate limit SO de falhas por IP (`CF-Connecting-IP` usado somente se `REMOTE_ADDR` for faixa Cloudflare oficial), `Referrer-Policy`, `no-store`, noindex; quiosque (HTML/assets/token) inalterado. F1 (fundacao em `public/gestao/`, `app/Views/gestao/`, `app/Controller/Gestao*`, `util/Gestao*`): login `primeiro.segundo`, sessao em TABELA (cookie opaco, so sha256 no banco; 30 min de inatividade e 12 h absoluto), CSRF, RBAC `admin`/`usuario` no servidor, usuarios (criar/editar/ativar/inativar/redefinir senha/Desbloquear), Minha conta (troca de senha), auditoria append-only sem PII, `tools/criar-admin.php` (primeiro admin por CLI), telas SOMENTE com a paleta UDLOG (estados por texto/icone/peso) e logo UDLOG. Migrations 020 (usuarios/sessoes/tentativas, `senha_versao`) e 021 (auditoria) em `sql/migrations/` e `sql/schema.sql`: NAO APLICADAS em nenhum banco real (so bancos QA); aplicar 020/021 e rodar `criar-admin.php` antes do primeiro acesso. `.env.example`: `GESTAO_HASH_SALT=` vazio (a definir no deploy). Testes (banco QA): migrations 46, unidade 254, http 167, totem_f0 53, cli_admin 22, correcoes 188, qa_correcoes 143, qa_independente 283 (com `QA_HEAD_TREE`), `gestao_layout.js` 293; regressao e2e mock 38, QR integracao 50, sem_fila 53, `vio_captura_layout.js` 1745. Decisoes do usuario D1..D14 (resumo): hash da URL do totem 16 caracteres CSPRNG (`NOME-EMPRESA-HASH16`, F2) com limite de tentativas; slug da empresa sem espaco/acento ("Maua I" -> MAUAI), nome do totem ate 24 e empresa ate 16, `tb_totem.codigo` -> VARCHAR(64); token do totem na pagina NAO muda (risco aceito); `admin` tambem ativa/inativa OC; dados pessoais mascarados, so `admin` revela com auditoria; retencao 90 dias (logs e auditoria); login livre `primeiro.segundo`; 2FA nunca; "API" nos logs = falhas de integracoes/infra sem atendimento (abas API/Recebimento/Expedicao + Cron e Gestao); URL do quiosque RECEPCAO-01 FICA FIXA ate o fim do desenvolvimento; so a paleta UDLOG; anexos orfaos: listar e excluir; extras aprovados (painel do dia, travados com Encerrar, baixas de OC pendentes, status das integracoes, cadastro de clientes/empresas P29, saude/crons); ver imagens/PDFs das OCs e fotos das notas por endpoint autenticado com auditoria. PENDENTE F2..F6: F2 totens (URL `NOME-EMPRESA-HASH16`), F3 logs (`tb_log_sistema`, instrumentacao, cron de retencao de 90 dias que tambem poda a auditoria, hoje SEM poda), F4 OCs (tela do usuario, ativas >15 dias), F5 atendimentos (travados/encerrar) e anexos orfaos, F6 painel/extras e ver imagens de OC e notas. Pendencias baixas: limites de login por conta/IP nao atomicos entre processos (B2); senha temporaria sem expiracao; token do formulario de login sem estado (reutilizavel por 2 h, mitigado por CSRF/Origin e limite por IP); bloqueio de conta como DoS recuperavel (`criar-admin.php --forcar` ou Desbloquear); flash de login para sessao expirada/saiu ainda sem uso; `no-store` na pagina do totem a confirmar no quiosque; Argon2id, cookie `Secure`, Cloudflare e `REMOTE_ADDR` so verificaveis em producao; `criar-admin.php` imprime erros acentuados (terminal Windows pode exibir mal). Reabertura de atendimento concluido, bloqueio por IP, 2FA e recuperacao de senha por e-mail continuam NAO planejados.

- [2026-10-06] Impressao no mini PC de PRODUCAO (validada fisicamente pelo usuario): servico instalado em `C:\udlog\servico-impressao-local`, TM-T88VII com driver `Receipt6` na porta `TMUSB001`, origem permitida `https://totem.udlog.online`, tarefa de logon, quiosque Chrome com `iniciar-totem.bat` na pasta de inicializacao e login automatico do Windows. Correcoes no servico: listagem de impressoras por PowerShell JSON (`src/lib/listarImpressoras.js`) e deteccao de falta de papel pelo nome BASE do documento na fila do spooler (`verificarFila.js`); validado: 409 `sem_papel` com o job removido. `npm test` 33/33 (5+10+9+9). O token do servico foi exposto em chat e aceito como risco pelo usuario. OBS: o README do servico documenta a medicao de falta de papel com driver `Receipt5` (TM-T88V, porta ESDPRT001) no mini PC, enquanto o relato desta entrada e `Receipt6`/`TMUSB001`; conferir qual driver vale em producao. `scripts/teste-escpos-raw.ps1` segue nao rastreado (diagnostico descartavel). Anexos da OC em `anexos` base64 puro FUNCIONARAM no Talent (registro 38018, leitura de QR ok).

- [2026-10-05] DEPLOY EM PRODUCAO da remocao da fila (Hostgator, SSH): servidor em `6a3f353` (pull --ff-only, hashes conferidos, `php -l` ea-php83/82 ok); backups em `/home2/udlogo59/backups/fila-envio-arquivo-morto/` (dump completo do banco do totem + arquivo morto de `tb_fila_envio` com 8 linhas; a 8a e provavelmente o check-in de teste recusado por "Nr. Pager"); migration 019 aplicada duas vezes (idempotente) so no banco do totem, tabela ausente, `tb_atendimento` 177 e `tb_atendimento_nota` 123 inalteradas, smoke sem POST (401/405/404/403). Migrations externas 001-003 aplicadas em producao (backup de `tb_ordens_coleta` em `/home2/udlogo59/backups/totem-pre-deploy/`); anexo de teste do n8n devolveu 201 e a OC correspondente existe e esta ATIVA. 4 CRONS DO TOTEM AGENDADOS (ea-php83, saida em `/home2/udlogo59/logs/totem-cron.log`; fuso do servidor -03): `limpar-rate-limit-ocr` toda hora cheia, `abandonar-atendimentos` 03:15, `limpar-notas-quarentena` 03:30, `limpar-anexos-ordem-coleta` 03:55; nenhum executado manualmente; backup do crontab em `/home2/udlogo59/backups/crontab-antes-totem-20261005-214644.txt`; o wrapper do cPanel injetou linhas `SHELL="/usr/local/cpanel/bin/jailshell"` (inclusive acima da entrada `cron_alertas` de outro sistema; linhas existentes preservadas). Contagens antes do 1o abandono: `tb_atendimento` em_andamento 77, concluido 5, cancelado 93, bloqueado 2; conferir apos 06/10 03:15 e ler o log. `composer dump-autoload` NAO feito: servidor sem composer; `FilaEnvioDao` segue so no classmap/autoload_static (inofensivo; decisao pendente). Producao: `TALENT_CHECKIN_ATIVO=true` (decisao do usuario); Talent exige "Nr. Pager" (campo ainda inexistente na API; Talent adiciona em 2026-10-06; o usuario avisa quando disponivel para a rodada de testes); em producao ha 1 atendimento `ENVIANDO` e 1 `ERRO_REPROCESSAVEL` para conferencia; `.env` local de dev tambem esta com a flag true.

- [2026-10-05] `remocao-fila-reenvio-talent` (/00 a /03 APROVADAS por revisores independentes): fila de reenvio ao Talent REMOVIDA (`App\Dao\FilaEnvioDao`, `cron/reenviar-fila.php`, `registrarFalhaParaReenvio`, parametro `FilaEnvioDao` do construtor de `TalentRn`; nova assinatura: TalentClient, AtendimentoDao, string $caminhoBase, ?AnexoOrdemColetaLeitor = null). Migration 019 `DROP TABLE IF EXISTS tb_fila_envio` aplicada SO no dev `udlog_totem` (7 linhas `pendente` de teste, dump fora do repo); PRODUCAO PENDENTE, ordem obrigatoria: codigo novo ANTES do DROP (codigo antigo faria INSERT e daria 500); `sql/schema.sql` sem a tabela (nota "removida pela migration 019"). `ERRO_REPROCESSAVEL` preservado; nova tentativa SO manual (nova chamada de `finalizar`/correcao do ajudante); nao ha reenvio automatico de nenhuma falha. `finalizar` grava UMA linha de log sanitizada por chamada (`checkin_talent_erro_reprocessavel id_atendimento=<int> categoria=<allowlist>`) e o 202 mostra `dados.mensagem_api` real do Talent, com texto fixo por categoria sem promessa de reprocessamento; o front NAO mudou (frase propria "Nao foi possivel concluir o check-in agora. Chame o atendente."); `timeout`/`erro_indeterminado` continuam ENVIO_INDETERMINADO/HTTP 500. Comentarios obsoletos de `TalentClient`/`TalentClientException` reescritos (sem mudanca de logica). Testes: `teste_sem_fila_envio` 53/53 (novo), e2e 38, ajudante 58, mensagem_api 39, QR 50, oc_anexo 185/59/59, layout 1745/0; preexistentes no HEAD 3bc0497: ver P55. Handoff: `docs/handoffs/2026-10-05-remocao-fila-reenvio-talent.md`.

- [2026-10-05] `anexo-ordem-coleta-n8n` concluida ate a /03 (somente Expedicao; /04 = commit): o n8n envia o PDF da OC para `public/api/ordem-coleta-anexo.php` (JSON base64, Bearer `ORDEM_COLETA_ANEXO_API_KEY` com hash_equals, HTTPS, rate limit, 5 MiB, `%PDF-`); arquivo em `STORAGE_PATH/ordens_coleta/<cnpj>/<numero>_<AAAAMMDDHHMMSS>.pdf`, tabela `tb_ordem_coleta_arquivos` (UNIQUE cnpj+numero, so caminho/sha256, sem FK) no banco externo; sobrescrita por (cnpj,numero); `TalentRn` anexa `Ordem de Coleta` (base64 puro) e, em qualquer falha, segue sem anexo; cron `limpar-anexos-ordem-coleta.php` apaga 15 dias apos OC INATIVA (`inativada_em`, gravada por `marcarInativaPorNumero`) ou 15 dias apos recebimento se orfao; OC ATIVA nunca apaga. Decisao do usuario: so PDF chega ao totem (Word/imagem nao sao convertidos nem enviados; OC correspondente faz check-in sem anexo). Ajuste pos-/02: corrida de nome corrigida com publicacao exclusiva via `link()`; `CaminhoJaRegistradoException`; testes HTTP hermeticos; contador do rate limit 0640. /02 e /03 APROVADAS por QA, seguranca e backend independentes: logica 185/185, http 59/59, lacunas 59/59 (100 e 200 rodadas x 6 processos), mutacoes detectadas, regressao sem falhas; unica falha `teste_hardening_exclusao` E17 e PRE-EXISTENTE no HEAD (ver P55). Migrations externas 002/003 aplicadas SO no banco externo de dev. Proximo passo: deploy na Hostgator (SSH, so com autorizacao a cada passo; migrations 002/003 + `.env` + pasta + cron). Handoff: `docs/handoffs/2026-10-05-anexo-ordem-coleta-n8n.md`.

- [2026-10-04] Padronizacao dos modais de numero da nota (sem commit): teclas do numerico ja eram iguais (177x124, gap 14, grade 3x4; agora peso 700 em todas e centralizacao flex; digitos 52px, Apagar/Confirmar 30px); botoes de acao (Confirmar/Corrigir/Voltar/Excluir nota/Cancelar atendimento) padronizados em 560x88, 28px/700, raio 16 (antes Confirmar/Corrigir 624x96/30px/raio 18, Voltar 624 ou 560, Cancelar peso 600). So CSS (`app.css`) + verificacoes novas em `vio_captura_layout.js` (1745).

- [2026-10-04] Titulo e favicon (sem commit): `<title>Totem</title>` em `public/totem/index.php` (unica pagina HTML; `public/index.php` nao existe; JS nao altera `document.title`); favicon proprio (quadrado arredondado #0179AD com totem branco) em `public/totem/assets/` (`favicon.svg`, `favicon-32.png`, `favicon-192.png`, `apple-touch-icon.png`, `favicon.ico` 16/32/48), versionados por `filemtime`; sem `.htaccess` no repo; `/favicon.ico` na raiz do dominio nao tratado (document root em producao ambiguo). Verificacoes adicionadas em `tests/manual/vio_captura_layout.js`.

- [2026-10-04] Ajustes pos-revisao (sem commit): `error_log` dos 2 controllers de impressao agora grava `get_class($e)` (nao a mensagem bruta); docblock de `ImpressaoTesteController::etiquetaPronta` corrigido (rota de diagnostico com token do totem, caminho fixo `docs/50x80.pdf` inexistente no deploy, avaliar exclusao no deploy final); README do servico: `node-windows` marcado como legado e secao 2.6 renomeada para "tarefa de logon". Verificado: php -l ok; etiqueta_ajudante 67/67, texto_etiqueta 105/105, etiqueta_ajudante_qa 32/32, impressao_idor 12/12 (banco QA); zero `qa_qr_exclusivo_%`.

- [2026-10-04] ESTADO ATUAL (consolidado; sem commit/push ate esta entrada). ENTREGUE (fluxo QR-only de CNH/CRLV em Exp e Rec, testado em dev e banco QA): leitura de QR melhorada; reprovacao por `motivo_usuario` com re-escaneio (3a so manual); tela "Confirme os dados" em 3 cartoes com campos do sistema somente leitura (Placa/Ordem/Cliente) e gate de obrigatorios; RNTRC/Tipo opcionais na leitura e obrigatorios na confirmacao; botoes/teclado ampliados (LGPD e inicial intactas); impressao com DUAS etiquetas (motorista + ajudante, 80x80, 48 mm uteis, so ASCII, nome do ajudante fora da etiqueta do motorista); deteccao de falta de papel pela fila do spooler (409 `sem_papel`, alerta no front); `mensagem_api` da Talent no erro de finalizacao; correcao dos dados do ajudante apos recusa; tarefa de logon do servico de impressao (servico Windows descartado); paginas web de teste (`teste-impressao.php`, `preview-confirmacao.php` e JS) EXCLUIDAS por decisao do usuario; corpo JSON escalar (`5`, `"x"`, `true`, `null`) em `impressao.php`/`atendimento.php`/`nota.php` agora vira `[]` e responde 4xx (antes TypeError/500), teste `teste_corpo_json_escalar.php` 31/31 via php-cgi em banco QA; docs atualizados (README do servico, deploy-checklist, handoff, `.env.example`). PENDENTE: ver secao 6 (Hostgator no final; testes fisicos de falta de papel, duas etiquetas e QR com Netum; contrato de erro da Talent; criterio por texto do "Corrigir dados do ajudante"; reenvio de rejeicoes de negocio pela fila; CPF do ajudante so 11 digitos; residuos de dev; sem teto de re-escaneio no backend; P22).

- [2026-10-02/04] Front consolidado (sem commit): (a) QR-only: progresso "Lendo documento...", modal de reprovacao por `motivo_usuario` com "Escanear novamente"/"Preencher manualmente"/"Cancelar" (`qrTentativas`); guia `.guia-qr` (4 cantos #0179AD, lado 36% da altura do video), worker com recorte/escalas 0,5-1,0/Otsu/orcamento 300 ms, ate 3 frames por toque, watchdog 4 s, calibracao `qr_leitura_calibracao.js` 47/48 (antes 39/48); `index.php` versiona o worker por mtime (ao mudar `qr-leitura.js` tocar tambem `qr-worker.js`). (b) Confirmacao em cartoes (Motorista, Veiculo, Atendimento; textarea auto-ajustavel, rodape sticky), modal "Faltam dados do atendimento" / "Nao e possivel continuar" para vazios e 422 `CONFIRMACAO_INCOMPLETA`. (c) Ampliacao 2026-10-04: principal 96px, secundario 88px, Cancelar 72px, campos 88-108px, teclado 5 linhas (tecla 100px), sem `:hover`; so `montarTeclado`/`scrollIntoView` no JS. (d) Impressao: `sem_papel` = alerta + "Tentar novamente" (so reimpressao); inatividade suspensa em `exp/rec_impressao` nos estados `sem_papel`, `erro`, `indeterminado`, `erro_finalizar` e na correcao do ajudante; etiqueta do ajudante impressa em seguida (`concluido` so apos as duas; retry reimprime so a pendente; 409 no ajudante = erro "Chame o atendimento"); `erro_finalizar` mostra `mensagem_api` em caixa com textContent; "Corrigir dados do ajudante" aparece se a `mensagem_api` normalizada contem "ajudante" (criterio por texto, fragil). Layout JS 1641/1641 (3 viewports) na ultima rodada.

- [2026-10-02] Back consolidado (sem commit): (a) RNTRC e Tipo do CRLV opcionais na leitura (NULL; `rntrc_ausente`/`tipo_ausente` fora de `motivo_usuario`), obrigatorios na confirmacao e no `finalizar` (422 `CONFIRMACAO_INCOMPLETA` com `campos`/`rotulos`; Rec exige tambem `cliente`); digitar o que a API nao trouxe nao rebaixa a origem. (b) `finalizar` devolve `dados.mensagem_api` (Talent com HTTP de erro; `Util\MensagemApi` remove controles/invisiveis incl. U+00AD, U+061C, U+180E, U+2060-2064, normaliza espacos, 300 chars; nunca logada nem persistida; HTTP 202 `ERRO_REPROCESSAVEL`, rede/timeout/corpo vazio = sem `dados`). (c) Ajudante: `salvar-etapa` etapa `ajudante` reutilizada para correcao (so `em_andamento` + NAO_ENVIADO/ERRO_REPROCESSAVEL, sob lock; senao 409); CPF do ajudante = 11 digitos sem DV (decisao do usuario; CPF do motorista inalterado, com DV); `TalentRn` relê o atendimento apos o CAS. (d) Etiqueta: `Util\TextoEtiqueta::paraAscii`, `Util\EtiquetaLayout` (area util 48 mm a partir de 1 mm, `ETIQUETA_AREA_UTIL_MM` opcional 20..largura, fontes 22/15/15/40/9 com reducao automatica, 1 pagina, topo 2,5 mm), `gerar-etiqueta` com `destinatario` (`motorista` padrao | `ajudante`; ajudante exige `possui_ajudante=1` + nome, senao 409; motorista devolve `tem_etiqueta_ajudante`; CPF nunca impresso); diagnostico (`impressao-teste.php`) aceita override de tamanho e `destinatario`, acoes `configuracao-etiqueta`/`etiqueta-pronta` (docs/50x80.pdf). (e) `flagReimpressao` aceita query OU corpo. (f) `.gitignore` ignora `exemplos/` (possivel dado pessoal) e `epson/`. (g) Observabilidade VIO: uma linha `vio_reprovado` com motivo da allowlist, sem valores. (h) `status-processamento` com `motivo_usuario` aditivo (so na chamada que efetiva o terminal reprovado) e `iniciar-processamento` reabre apenas CONCLUIDO + NAO_VALIDADO; `avancarEtapaDocumentos` devolve `dados_confirmacao`; `resolverTelaDocumentos` e timeouts (poll 125 s) corrigiram o "Enviando..." eterno e a confirmacao vazia. Migration 018 aplicada no dev local.

- [2026-10-02] Impressao e servico local consolidado: DECISAO DEFINITIVA da etiqueta = papel 80x80 (driver "totem", UserForm158), `.env` ETIQUETA 80/80 portrait, conteudo em 48 mm uteis (TM-T88VII imprime ~50,8 mm = 360 dots; causa NAO confirmada; ajustes de driver/Sumatra/ESC-POS e Paper Width do firmware descartados, procedimento em `epson/manual.pdf` p.69-75 so informativo; `scripts/teste-escpos-raw.ps1` e diagnostico descartavel). Servico Node: Sumatra com `-print-settings` (`printSettings`, padrao `noscale,portrait,paper=totem`, validado `[A-Za-z0-9,=._ -]` max 100); deteccao de falta de papel por permanencia do job na fila (`lib/verificarFila.js`, `semPapelTimeoutMs` 3000-60000 padrao 10 s, `deteccaoSemPapel` padrao true; remove so o job e responde 409 `sem_papel`; vale so para EPSON Receipt6/TMUSB001; `PrinterStatus`/`DetectedErrorState` NAO distinguem falta de papel); autostart por TAREFA DE LOGON (`scripts/instalar-tarefa-logon.ps1`, `desinstalar-tarefa-logon.ps1`, `iniciar-oculto.vbs`), servico do Windows/LocalSystem descartado porque o job travou no spooler; `scripts/teste-impressao.js` (`npm run teste:impressao`, `--imprimir`, `--pdf=`, `--largura=`/`--altura=`) e script de diagnostico do servico, fica. Retestes fisicos no dev (autorizados, 1 impressao cada): no ultimo, com spooler limpo e rolo novo, `POST /imprimir` -> HTTP 200 `impresso`, fila vazia em ~4 s e ~10 s e papel conferido pelo usuario. `npm test` 15/15.

- [2026-10-02] QA consolidado: suites legadas portadas ao fluxo QR-only em banco `qa_qr_exclusivo_<hex>` descartavel + storage temporario (prepend `qa_qr_exclusivo_prepend.php` fail-closed, infra `qa_qr_exclusivo_legado.php`, VIO falso por factory): e2e mock 38/38, `salvar_etapa_cliente` 18/18, integracao QR 50/50, `idor_salvar_etapa` 22/22 (so com banco QA forcado), etiqueta_ajudante 67/67 (sem banco) e 32/32 (QA), texto_etiqueta 105/105, correcao ajudante 58/58, mensagem_api_finalizar 39/39, mensagem_api_e_flag_reimpressao 24/24, impressao_idor 12/12 (QA), npm test 15/15. Residuos em dev: totem `TESTE_E2E_16a493` (id 1845) em `udlog_totem` e 11 bancos `qa_*` antigos (P23); 0 bancos `qa_qr_exclusivo_*`.

- [2026-10-01] Front QR-only (`app.js`/`app.css`), sem commit: progresso "Lendo documento... trazendo os dados." (+ spinner na espera) e modal de reprovacao por `motivo_usuario` (CNH/CRLV, Exp e Rec) com "Escanear novamente" (reabre a tela QR do documento, reenvia `iniciar-processamento` com novo frame, conta em `qrTentativas`; 3a reprovacao so oferece manual), "Preencher manualmente" e "Cancelar atendimento"; motivo null/fora da allowlist mantem o manual. Motivo e guardado em `state.<exp|rec>.motivos` ao chegar no resultado do poll (so vem uma vez). Layout JS 68/68, viewports 768x1366/1080x1920/1152x1846 sem overflow.
- [2026-10-01] Contrato novo QR-only (motivo ao motorista + re-escaneio), sem commit: `status-processamento` devolve `motivo_usuario` aditivo (`placa_divergente`|`rntrc_ausente`|`cnh_vencida`|`documento_ilegivel`|`dados_invalidos`|`null`), so na chamada que efetiva o terminal CONCLUIDO reprovado (nao persistido; polls seguintes e `persistencia_nao_vigente` = null). `iniciar-processamento` aceita novo POST para o mesmo documento quando CONCLUIDO + origem NAO_VALIDADO + `*_validado_em` nulo (CAS em `AtendimentoDao::iniciarEnvioVioApiBr`, tentativa_id nova, 1 POST); aprovado segue idempotente; PROCESSANDO_*/ENVIANDO/INDETERMINADO nunca reabrem. Regras de aprovacao inalteradas. Integracao 48/48.
- [2026-10-01] Bug tela de confirmacao (exp/rec) toda vazia: causa raiz era `telaConfirma` ler so `state.dados` (preenchido apenas por `iniciar`/`selecionar-ordem`/cliente), sem nenhum codigo que trouxesse motorista/CPF/CNH/CRLV persistidos (ja era assim antes do QR-only; dados estavam no banco). Corrigido: `AtendimentoController::avancarEtapaDocumentos` devolve `dados_confirmacao` (aditivo, so ao entrar em exp/rec_confirmacao, posse validada; CPF integral pois `salvarDadosMotorista` compara digitos com snapshot) e `tentarAvancarEtapaDocumentos` (app.js) mescla em `state.dados`. Cobre VIO e manual. Sem commit.
- [2026-10-01] Observabilidade de reprovacao VIO (CNH/CRLV QR-only), sem mudar decisao: `DocumentoController::processarResultadoVioApiBrObtido` agora loga UMA linha `[DocumentoController] vio_reprovado id= tipo= estado_leitura= qr_type= motivo=<codigo allowlist> [chaves_vio_result=<so nomes de chaves>]`; `DocumentoRn` devolve `motivo_codigo` interno (removido das respostas de preenchimento manual, nunca exposto via HTTP). Sem valores de documento/QR/excecao em log. Testes: isolado 23, CNH 15, comparacao 18, regras CRLV 79, integracao 31 (+4); banco QA removido.
- [2026-10-01] Bug "QR lido. Enviando para validacao..." eterno (CNH/CRLV QR-only): causa raiz era `tentarAvancarEtapaDocumentos` fazer `ir(avanco.proxima_tela)` com o nome logico do backend (`exp_cnh`/`exp_crlv`/`rec_cnh`/`rec_crlv`/`impressao`) que nao existe em `renderTela()` (telas do front sao `*_qr`), entao a tela nao trocava. Corrigido em `app.js` com `resolverTelaDocumentos` (mapa backend->front + allowlist, erro visivel se desconhecida); timeouts de fetch (avancar 30 s, status 20 s) e `PROCESSAMENTO_TIMEOUT_MS` 45 s -> 125 s (coerente com 120 s do backend). Request sem imagem observada era poll legitimo de status-processamento. Backend inalterado. Sem commit/push.
- [2026-10-01] Migration 018 (`client_uid` + `uk_atendimento_client_uid` em `tb_atendimento_nota`) aplicada em `udlog_totem` (dev local) por autorizacao do usuario; producao/Hostgator continua pendente.

- [2026-10-01] `fluxo-qr-exclusivo-cnh-crlv`: /02 e /03 APROVADAS por revisores independentes (frontend, backend, seguranca, QA; 268 verificacoes, 0 falhas, sem banco QA residual). Liberada para /04; commit/push ainda nao feitos nesta entrada. Detalhes e observacoes nao bloqueantes no handoff.

- [2026-10-01] `fluxo-qr-exclusivo-cnh-crlv` rodada corretiva pos-/02: codigo morto removido (VioApiBrCacheDao, fingerprint/HMAC, tentarCache*/gravarCache*, calcularIdentificador, constantes/allowlists orfas e falhaEstruturaInvalida* em DocumentoRn, arquivoDoDocumentoExiste, AtendimentoDao::definirModoCnh); construtor de DocumentoRn sem o DAO de cache. Suites portadas para entrada QR-only: isolado 23, CAS 25, CNH 15, comparacao 18, regras CRLV 79, PDO 51, integracao 26, sanitizacao 24, lock 25, integridade 43. Schema/migrations e origens historicas preservados; VioCacheDao, AnexoPdfHelper (notas) e envs `VIO_API_BR_CACHE_*` mantidos. Banco `qa_qr_exclusivo_*` = 0. Sem aprovacao da /02; sem commit/push.
- [2026-10-01] `fluxo-qr-exclusivo-cnh-crlv` /01 corretiva (ajustes finais): blocos legados do `app.js` e comentários do controller limpos; prova negativa de logs adicionada à integração (error_log capturado em arquivo temporário, respostas HTTP e todas as tabelas do banco QA; sentinelas de QR/Base64/API key/CPF/placa/Renavam/RNTRC/nome; cenários de sucesso e falha) sem vazamento. Contagens: isolado 23/23, CAS/cache 25/25, CNH 14/14, layout JS 31/31, integração 26/26; lint PHP/JS e `git diff --check` limpos. Banco `qa_qr_exclusivo_*` removido (0 restantes) e sem temporários. Pronto para /02 independente. Sem commit, push, migration, serviço externo, dado real ou código de produção alterado nesta rodada.
- [2026-10-01] Trilha QA em fork de `fluxo-qr-exclusivo-cnh-crlv`: criado bootstrap QA que usa `QA_QR_DB_*` ou, na ausência, carrega o `.env` apenas para `DB_HOST/DB_PORT/DB_USER/DB_PASS` (nunca `DB_NAME`), e só cria/remove bancos `qa_qr_exclusivo_[a-f0-9]{8}`. Lints passaram e o contrato isolado repetiu 23/23. A integração controller+DAO+VIO/Talent falso foi bloqueada antes de criar banco (exit 2), pois `DocumentoController` ainda instancia `VioApiBrClient` diretamente e não oferece injeção exclusivamente do servidor. Sem rede, banco/storage, dado real, impressão, migration, commit ou push. A corretiva precisa resolver a injeção antes de executar essa suíte.

- [2026-10-01] `/02 fluxo-qr-exclusivo-cnh-crlv`: **PRECISA DE AJUSTE**, não iniciar /03. Front ativo passou 41 verificações (34 layout + 7 QR), e contrato isolado repetiu 23/23, mas o upload legado ainda grava CNH/CRLV em storage, o código física/digital/upload permanece morto porém presente, e regressões VIO antigas falharam 37/39 e 44/46 por expectativas de cache/páginas. Falta portar Talent/JPEG/VIO/CAS para `qa_qr_exclusivo_*` e mock injetável VIO no controller. Sem rede, dado real, banco/storage real, impressão, commit ou push.

- [2026-10-01] `fluxo-qr-exclusivo-cnh-crlv` /01 retomada concluída sem rede: QR local é UX, JPEG QR-only segue direto à VIO sob CAS; três falhas vão ao manual; QR válido tem um POST sem retry, `comparar=true`. Sem binaryData, releitura, HMAC/fingerprint ou VIO_CACHE; tabelas/colunas antigas preservadas. CNH/CRLV não vão a storage nem Talent; `doctos[]`/notas permanecem. Dependência Chillerlan e prova experimental removidas. Contrato isolado QR-only: 23/23; liberar para /02 independente com mocks e `qa_qr_exclusivo_*`. Risco aceito: uma chamada por documento e sem liveness. Sem VIO/Talent/Serpro, dado real, banco/storage real, impressão, migration, commit ou push.

- [2026-10-01] `/01 fluxo-qr-exclusivo-cnh-crlv` foi interrompida antes da integração: instalada `chillerlan/php-qrcode` 5.0.5 fixada no `composer.lock`, compatível com PHP 8.0.30/GD. A prova isolada em memória confirmou `DecoderResult->data` byte a byte para QR sintético com NUL e bytes não UTF-8 (15 bytes; hash registrado no handoff), rotação 90/180/270, baixa luz e JPEG comprimido; tempo direto 108,92 ms e pico incremental 14 MiB. A transformação de perspectiva sintética falhou. Como o QR relido é raiz de confiança de HMAC/cache, nenhum frontend/backend/Talent/storage foi integrado até definir decoder ou pré-processamento que passe esse cenário. Sem VIO/Talent/Serpro, documento, banco/storage real, impressão, migration, commit ou push. Handoff atualizado.

- [2026-10-01] `/00 fluxo-qr-exclusivo-cnh-crlv` concluído, somente planejamento com quatro revisores reais (frontend, backend, segurança e QA): proposta substitui CNH física/digital e CRLV por tela única de QR local, três falhas locais levam ao manual, JPEG do QR só em memória, cache por QR e um POST VIO por CAS; Talent manterá `doctos[]`/notas sem anexos CNH/CRLV. **Bloqueador antes da /01:** o backend não tem decodificador QR e não pode confiar em `binaryData` do navegador para associar JPEG e HMAC/cache. É obrigatório adotar e validar decodificador local no backend que reextraia o QR do JPEG antes de implementar. Migrations/colunas históricas serão preservadas; comparação VIO continua diagnóstica. Sem rede, documento, banco/storage real, migration, impressão, commit ou push. Handoff: `docs/handoffs/2026-10-01-fluxo-qr-exclusivo-cnh-crlv.md`.

- [2026-10-01] `vio-comparacao-diagnostica-nao-bloqueante` /01 concluida, sem chamada externa: para CNH e CRLV, `compare` VIO e diagnostico e nao bloqueia por pending/processing/failed/expired/ausente, reliable=false, score, mismatch ou not_found. Persistem como gates leitura principal concluida, QR/tipo esperado, campos e paginas validos, CAS/tentativa vigente e transacao cache/persistencia. A composicao local continua fonte primaria das paginas quando a VIO omite contagens; contagem declarada divergente bloqueia. Persistencia VIO e cache foram unificados em transacao com CAS para descartar resultado tardio/cancelado. Suites QA: 493/493, incluindo prova negativa que detectou 25 falhas ao reintroduzir o bloqueio antigo. Sem segredo ou dado documental em logs; sem VIO/Talent/Serpro, documento real, banco/storage real, impressao, migration, commit ou push. Pendente /02-testes independente; risco aceito: sem liveness e compare visual fora da decisao.

- [2026-10-01] Decisao de produto para `ativacao-vio-api-br-e-padronizacao-captura-documentos`: para CNH e CRLV, resultado de extracao VIO com leitura concluida, QR esperado e campos obrigatorios validos passa a ser aprovado mesmo quando a comparacao OCR/imagem for nao conclusiva, `reliable=false` ou tiver divergencias. Falha HTTP, ambiguidade, QR inesperado, leitura/campos ausentes ou invalidos continuam fail-closed. A comparacao sera apenas diagnostica. Nenhum codigo foi alterado por esta decisao; exige rodada propria de implementacao, mocks e nova autorizacao para chamada real.

- [2026-10-01] `ativacao-vio-api-br-e-padronizacao-captura-documentos` — teste real controlado autorizado e encerrado: uma unica CNH digital pelo Netum, `comparar=true`, 1 POST externo protegido por CAS e 11 GETs/polls (aprox. 20 s, intervalo de 2 s), sem retry. Terminou `CONCLUIDO` com origem `NAO_VALIDADO`; o avanco foi corretamente bloqueado e nenhum campo pessoal foi persistido no QA. Banco, storage/imagem, perfil/logs temporarios, listener e arquivos de controle foram removidos. Sem segredo, Base64, QR ou dado pessoal em logs/docs/Git; sem Talent/Serpro/impressao/producao/HostGator/migration/commit/push. Handoff atualizado.

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

Ultima atualizacao: 2026-10-06
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
  http://localhost:8080 ativo; producao https://totem.udlog.online documentada
  e NAO ativada). `impressao.php` (producao) separado de
  `impressao-teste.php` (diagnostico)

Documentos do motorista (VIO)
- CNH/CRLV validados via API contratada `https://vio.api.br`
  (`VioApiBrClient`): maquina de estados assincrona (PENDENTE, ENVIANDO,
  PROCESSANDO_LEITURA, PROCESSANDO_COMPARACAO, CONCLUIDO, ERRO,
  INDETERMINADO), CAS de envio, ID externo persistido antes do polling,
  cache seguro `VIO_CACHE` (AES-256-GCM, HMAC, TTL 7 dias, migrations
  015/016, aplicadas no dev local `udlog_totem` em 2026-09-26)
- ENTRADA ATUAL = FLUXO QR-ONLY (2026-10-01): tela unica de QR por documento
  (CNH e CRLV, Exp e Rec); o JPEG do quadro vai direto a VIO sob CAS (um POST,
  `comparar=true`, sem retry), sem cache, sem storage e sem Talent; 3 falhas
  de leitura levam ao preenchimento manual. Escolha CNH fisica/digital, upload
  e PDF foram REMOVIDOS (acoes antigas = 404); `cnh_modo_captura` (migration
  017) e as origens/tabelas historicas ficam preservadas. Comparacao VIO e
  so diagnostica, nao bloqueia
- Contrato real de campos CONFIRMADO por chamadas reais pagas: CNH
  `Nome`/`CPF`/`Validade`; CRLV `Placa`/`Renavam`/`Exercício`/`UF`/
  `RNTRC`/`Tipo`; `pages_processed`/`total_pages` vem NULL (nao exigidos)
- Legado Serpro (VioDecodeClient, ProdespClient, manual_vio_decode.md)
  REMOVIDO (demanda remocao-legado-serpro-e-hardening-documentos);
  `VIO_VALIDADO` preservado no ENUM so para leitura historica
- RNTRC (`rntc`) e Tipo do veiculo: OPCIONAIS na leitura do CRLV e
  OBRIGATORIOS na confirmacao/`finalizar` (decisao 2026-10-02, substitui
  "obrigatorio em todos os pontos"; o Talent segue exigindo o `rntc`);
  `veiculo.tipo` e `veiculo.uf` extraidos do CRLV (27 UFs);
  exercicio validado por tipo/magnitude/faixa (caberEmPhpInt)
- Rebaixamento para MANUAL quando o atendente altera dado validado
  (AtendimentoRn::salvarDadosMotorista); allowlists com unset() do
  campo image; ehValorPlaceholder simetrico CNH/CRLV
- Reconciliacao de processamento abandonado no kiosk (INDETERMINADO)
- Preenchimento manual de CRLV com a mesma validacao de exercicio

Fluxo QR-only, confirmacao e impressao (2026-10-02 a 2026-10-04, sem commit)
- Front: leitura de QR melhorada (guia, recorte, escalas, ate 3 frames por
  toque), reprovacao por `motivo_usuario` com re-escaneio, tela "Confirme os
  dados" em cartoes (Placa/Ordem/Cliente somente leitura, obrigatorios
  validados), botoes e teclado ampliados (LGPD e tela inicial intactas)
- Impressao: duas etiquetas em papeis separados (motorista e ajudante), 80x80
  mm com 48 mm uteis, so ASCII, `gerar-etiqueta` com `destinatario`; falta de
  papel detectada pela fila do spooler (409 `sem_papel`) com alerta e
  "Tentar novamente"; servico local com `printSettings` (papel "totem") e
  autostart por TAREFA DE LOGON (servico do Windows descartado)
- Talent: `mensagem_api` (texto de erro sanitizado) no erro de finalizacao;
  correcao dos dados do ajudante apos recusa (`salvar-etapa` etapa `ajudante`)
- Corpo JSON escalar nas rotas `impressao.php`/`atendimento.php`/`nota.php`
  tratado como `[]` (4xx, nunca 500)
- Paginas web de teste de impressao e de pre-visualizacao EXCLUIDAS
  (2026-10-04); fica o endpoint de diagnostico `impressao-teste.php` (exige
  token) e o script `servico-impressao-local/scripts/teste-impressao.js`

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
- Aprovacao automatica vio.api.br (REVISTA em 2026-10-01): leitura concluida,
  QR esperado e campos validos (tipo/formato/faixa) aprovam; a comparacao
  (reliable, mismatched, score) e so diagnostica e nunca bloqueia; falha
  HTTP, ambiguidade, QR inesperado e campos ausentes/invalidos continuam
  fail-closed (risco residual aceito: sem liveness, uma chamada por documento)
- CNH: escolha fisica/digital SUPERADA pelo fluxo QR-only (2026-10-01)
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
  origensPermitidas de producao (https://totem.udlog.online, nunca http) so no
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
  * ETIQUETA (2026-10-02, definitivo): largura util 48 mm (x 1..49 mm) em
    papel 80x80; a TM-T88VII imprime so ~50,8 mm
  * causa provavel: 360 dots (~58 mm de papel) no firmware - NAO confirmada;
    procedimento do manual (`epson/manual.pdf` p.69-75) disponivel, NAO aplicado
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
- P18 [ABERTA] Migrations 015/016/017/018 aplicadas so no dev local
  (`udlog_totem`); aplicacao em producao/Hostgator entra no deploy final
  (018 ANTES do PHP novo; ver docs/deploy-checklist.md) (L221, L222).
  Acrescentar a migration 019 (DROP `tb_fila_envio`), aplicada so no dev:
  em producao DEPOIS do PHP novo
- P19 [ABERTA] Nova credencial rotacionada da vio.api.br: nao existia/nao
  usada ate o registro (L221)
- P20 [ABERTA] display_errors=Off em producao: item ja no
  docs/deploy-checklist.md, confirmar no Hostgator (L221)
- P21 [ABERTA] Risco residual aceito: comparacao.campos fora da decisao
  de aprovacao automatica (sempre veio vazio em 3 chamadas reais) (L223,
  L5868)
- P22 [ABERTA, MUDOU DE NATUREZA em 2026-10-02] RNTRC/Tipo agora sao
  opcionais na leitura do CRLV e obrigatorios na confirmacao/`finalizar`
  (422 `CONFIRMACAO_INCOMPLETA`); o cenario "veiculo particular sem RNTRC"
  continua sem regra (hoje bloqueia na confirmacao); RNTRC sem validacao de
  formato (L5881, L5988)
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
- P27 [PARCIAL] Sucesso do spooler nao confirma impressao fisica; falta de
  papel ja e detectada pela fila (ver 6.7); demais falhas fisicas (papel
  atolado, tampa aberta) seguem sem deteccao (L147)
- P28 [ABERTA] Ponto de acesso a tela de diagnostico de impressao (toque
  longo, #diagHotspot) e escolha de implementacao, nao decisao de UX
  formal (L146)
- P29 [ABERTA] Manutencao de novos clientes em tb_cliente (alem dos 38):
  continua manual por migration de dado (L143)

### 6.4 Adiado para producao/finalizacao
- P30 [ABERTA] Ativar origensPermitidas de producao (https://totem.udlog.online)
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

### 6.7 Pendencias de 2026-10-04 (fluxo QR-only, impressao e Talent)
- P45 [ABERTA] Deploy final Hostgator: migrations 015-018 (018 antes do PHP;
  019 DROP `tb_fila_envio` DEPOIS do PHP novo),
  crons do cPanel (limpeza de rate limit OCR e de notas em quarentena),
  preflight de `tb_cliente` (0 coincidencias com a UDLOG), HTTPS,
  `storage/` fora do document root, `display_errors=Off`, `.env` de producao
  (ETIQUETA_* 80/80, `TALENT_CHECKIN_ATIVO` ausente/false) e excluir
  `impressao-teste.php` do deploy se a tela de diagnostico nao for usada
- P46 [ABERTA] Mini PC de producao: papel "totem" 80x80 criado no driver,
  tarefa de logon registrada com login automatico do Windows,
  `origensPermitidas` de producao (P30)
- P47 [ABERTA] Testes fisicos pendentes: falta de papel real (409
  `sem_papel` pela fila, fluxo e alerta), duas etiquetas em sequencia
  (motorista + ajudante) e leitura de QR com o Netum (os exemplos locais nao
  decodificam em nenhum metodo; so cenarios sinteticos 47/48)
- P48 [ABERTA] Contrato de erro da Talent nao documentado (400 real =
  `{errors:{...},status}` ASP.NET); o que "cracha ja associado" implica nos
  dados do ajudante tambem nao esta documentado
- P49 [ABERTA] "Corrigir dados do ajudante" aparece por texto da
  `mensagem_api` ("ajudante"): criterio fragil, sem codigo de erro da Talent
- P51 [ABERTA] CPF do ajudante so exige 11 digitos (sem DV, decisao do
  usuario)
- P52 [ABERTA] Sem teto de re-escaneio no backend (`iniciar-processamento`
  aceita novo POST enquanto CONCLUIDO + NAO_VALIDADO; o front limita a 3);
  sem rate limit especifico nessa acao
- P53 [ABERTA] Residuos de dev: totem `TESTE_E2E_16a493` (id 1845) em
  `udlog_totem` e 11 bancos `qa_*` antigos (P23); a limpeza depende de
  autorizacao do usuario
- P54 [ABERTA] Larguras: TM-T88VII imprime ~50,8 mm (causa nao confirmada,
  firmware 360 dots); etiqueta fixada em 48 mm uteis; medidas fisicas finais
  do papel (tamanho total, cortes, posicao) pendentes do usuario

- P55 [ABERTA] Falhas PREEXISTENTES no HEAD 3bc0497 (testes desatualizados, identicas antes/depois da remocao da fila): `teste_hardening_exclusao` E17, `teste_talent_payload` 41/45 e `teste_talent_anexos_pdf` 15/19 (esperam anexos de CNH/CRLV que o fluxo QR-only nao gera mais). As 3 suites `teste_concorrencia_finalizar_checkin`, `teste_talent_idor_finalizar` e `teste_talent_trava_doctos_pendente` so passam com `TALENT_CHECKIN_ATIVO` nao-true no `.env` (esperam 503 `TALENT_CHECKIN_DESATIVADO`); o `.env` do dev local esta com a flag true (com false: 8/8, 10/10, 18/18)
- P64 [ABERTA] Residuos de `teste_preparacao_producao_checkin` no dev `udlog_totem` (atendimentos 12924 e 12936, totem id 1, TLT9001, em_andamento, +1 nota cada; o teste nao limpa por desenho); remocao depende de autorizacao
- P65 [RESOLVIDA 2026-10-06] Requisito "Nr. Pager" do Talent: o Talent desligou a exigencia; nao enviamos `pager`
- P66 [RESOLVIDA 2026-10-05: crons agendados, ver entrada do topo] Crons de producao NAO agendados no cPanel (ea-php83): `limpar-rate-limit-ocr` `0 * * * *`, `abandonar-atendimentos` `15 3 * * *` (risco: 85 atendimentos em_andamento em producao), `limpar-notas-quarentena` `30 3 * * *`, `limpar-anexos-ordem-coleta` `55 3 * * *`; reenviar-fila NUNCA (removido); agendar so com autorizacao
- P67 [RESOLVIDA 2026-10-05: 019 aplicada em producao] Deploy da migration 019 em producao (`udlogo59_udlog_totem`, MySQL 5.7.44) pendente: confirmar que nao ha cron do reenviar-fila, backup + dump da tabela (arquivo morto fora do webroot), `git pull`, `php -l`, 019; rollback = restaurar a tabela ANTES de reverter o commit; exige privilegio DROP
- P68 [ABERTA] `TALENT_CHECKIN_ATIVO=true` em producao: decisao do usuario (hoje ausente/false no checklist)
- P56 [ABERTA] anexo-oc B-1: 1062 classificado por `str_contains` em `errorInfo[2]`; B-2: orfaos de arquivo e `.tmp_*` sem varredura; B-3: `caminhoReservado` engole excecao; B-4: rate limit por REMOTE_ADDR e contador cresce sem teto
- P57 [ABERTA] anexo-oc I-1: rajada >30 envios/segundo com a mesma parte de nome => 503; I-4: `Authorization` pode ser removido pelo Apache/FPM, X-Forwarded-Proto forjavel, sem tamanho minimo de chave
- P58 [ABERTA] `tests/manual/teste_oc_anexo_lacunas.php` sai com rc 0 mesmo com falhas (conferir a saida)
- P59 [ABERTA] `link()` validado so no Windows/NTFS; testar no Linux/Hostgator (ha fallback `fopen 'xb'`)
- P60 [ABERTA] Confirmar em producao `tb_clientes.cnpj` VARCHAR(14) so digitos (join direto `c.cnpj = a.cnpj_cliente`)
- P61 [RESOLVIDA 2026-10-05] Migrations externas 001-003 (`ORDEM_COLETA`, `tb_ordem_coleta_arquivos`, `inativada_em`) JA aplicadas em producao em 2026-10-05; anexo de teste enviado pelo n8n com HTTP 201
- P62 [ABERTA] Teste real na Talent com PDF ~5 MB so com autorizacao (timeout 30 s => ENVIO_INDETERMINADO)
- P63 [ABERTA] Demanda do n8n em `docs/n8n-anexo-ordem-coleta.md` (workflow inativo, URL/credencial a configurar)

### 6.6 Resolvidas (nao listar como abertas)
- P50 (2026-10-05): estava errada (recusas de negocio TAMBEM enfileiravam,
  pois o controller nao distingue recusa de erro tecnico); decidido "sem
  reenvio automatico" e fila removida (`remocao-fila-reenvio-talent`)
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
- 2026-10-02/04: pagina e script de teste web (`teste-impressao.php`,
  `preview-confirmacao.php`) excluidos por decisao; autostart do servico de
  impressao decidido (tarefa de logon); causa do job retido no spooler
  (LocalSystem) eliminada ao sair do servico do Windows; etiqueta final
  (48 mm uteis, 80x80, ASCII) decidida; TypeError por corpo JSON escalar
  corrigido; `exemplos/` e `epson/` no `.gitignore`; confirmacao vazia e
  "Enviando..." eterno corrigidos; falta de papel detectada pela fila

## 7. Fora de escopo (nao sugerir sem pedido)

- Infraestrutura com processo persistente, WebSocket de longa duracao ou
  instalacao de binario no servidor Hostgator
- OCR server-side (Tesseract PHP): so client-side (Tesseract.js)

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

- [2026-10-01] `/01-implementacao` de `ativacao-vio-api-br-e-padronizacao-captura-documentos` concluida, sem chamada real: os quadros/previas CNH e CRLV de Expedicao e Recebimento agora compartilham `min(92%, 900px)` e proporcao real do video com notas; canvas nativo, QR local, JPEG/PDF/upload, cache, migrations e notas ficaram intactos. Medicoes headless 3264x2448: 655x491 em 768x1366 e 900x675 em 1080x1920/1152x1846, video e previa identicos, `contain`, sem overflow. Regressao nova 34/34; bateria VIO/mock/QA segura 431/431; total 465/465, zero rede externa. Excluidas: LGPD backend com fixture ausente preexistente, suites que usam dev storage/banco e JPEG sem override QA. Revisores reais frontend, backend, seguranca e QA participaram. Pendente obrigatoria: autorizacao explicita para uma unica chamada paga (documento definido, 1 POST, max. 60 GETs/2s/120s, `comparar=true`, sem retry). Nenhuma .env/migration/banco real/storage/impressao/producao/HostGator/Trello/commit/push. Handoff atualizado.

- [2026-10-01] `/00-planejamento` de `ativacao-vio-api-br-e-padronizacao-captura-documentos` concluido, somente leitura: VIO local esta tecnicamente apta para uma chamada real controlada — as cinco variaveis `VIO_API_BR_*` necessarias estao presentes (valores nao lidos), URL HTTPS, cURL/OpenSSL/CA presentes, migrations 015/016/017 presentes; 018 ausente como pendencia independente de notas e nao bloqueia VIO. Nao ha flag de ativacao: cache miss instancia `VioApiBrClient`; POST unico com `comparar=true`, CAS/id externo/polling/cache ja existem. CNH/CRLV ainda usam a mesma classe-base das notas, mas ficam em 360 px, contra `min(92%, 900px)` das notas. Plano: reaproveitar o quadro amplo e o calculo de proporcao real para Expedicão/Recebimento sem mudar canvas, QR, JPEG, upload ou notas; testes integralmente mockados e validacao fisica posterior. Nenhuma chamada externa, documento real, `.env`, migration, banco/storage, impressao, producao, HostGator, commit ou push. Chamada paga bloqueada ate nova autorizacao explicita com documento, 1 POST maximo, GETs estimados e limpeza. Handoff: `docs/handoffs/2026-10-01-ativacao-vio-api-br-e-padronizacao-captura-documentos.md`.

- [2026-10-01] `fluxo-qr-exclusivo-cnh-crlv` /01 corretiva: legado executavel de upload/modo/fotos/PDF removido; as acoes antigas retornam 404 sanitizado. Controller ganhou factory VIO exclusivamente de composicao para falso em QA; QR-only continua JPEG em memoria, CAS, um POST e sem cache/storage. Contrato isolado 23/23, lints e diff-check verdes. **PRECISA DE AJUSTE; /02 nao iniciado:** faltam portar as suites antigas fisica/digital/cache, atualizar o layout test e completar/executar a integracao QA controller+DAO+VIO/Talent falsos. O ambiente nao fornece `QA_QR_DB_*`; o bootstrap recusou `.env`, sem abrir conexao. Zero rede externa, banco/storage/documento real, migration, impressao, commit ou push. Handoff: `docs/handoffs/2026-10-01-fluxo-qr-exclusivo-cnh-crlv.md`.

- [2026-10-02] Teste ficticio da etiqueta de PRODUCAO (ImpressaoAtendimentoController::montarPdf, nome "TESTE FICTICIO", nrRegAcesso 999999, sem banco/Talent): PDF MediaBox 226.77x226.77 pt = 80x80 mm; maiores linhas 31 mm (margem folgada); 1 impressao na EPSON TM-T88VII (Sumatra noscale,portrait,paper=totem) com status "impresso". Medidas fisicas do papel (tamanho total, cortes, posicao) pendentes do usuario.

- [2026-10-02] Diagnostico (somente leitura) da impressao como servico Windows (LocalSystem, node-windows): servico Running/Auto na 4747, "HTTP 200 impresso" mas sem papel; fila da EPSON tem job 43 travado "Printing, Retained" (Size 0, owner SISTEMA) e job 44 atras dele; Sumatra roda como SYSTEM e o job trava no spooler/porta USB TMUSB001 (log PrintService desabilitado). Acao pendente do usuario: desinstalar o servico (npm run desinstalar-servico-windows), limpar a fila e voltar a npm start na conta dele.
- [2026-10-02] Observacao empirica (EPSON TM-T88VII, sem papel, 1 impressao de teste): Sumatra sai 0 e o servico responde "impresso" em ~2 s; ~5 s apos o envio o job aparece "Printing, Retained" (0/0 pag.) e Win32_Printer muda para PrinterStatus=4, PrinterState=1024, DetectedErrorState=2 e permanece assim; Get-Printer segue "Normal", WorkOffline=False, nenhum evento System/Application. Job removido por Remove-PrintJob; fila vazia e impressora volta a PrinterStatus=3/DES=0. CONCLUSAO (teste com papel, 1 impressao): PrinterStatus=4/PrinterState=1024/DetectedErrorState=2 aparecem tambem na impressao NORMAL (job "Printing, Retained" 0/0 pag.), entao esses campos NAO distinguem falta de papel; unico discriminador observado: job sai da fila em <1 s (com papel, ~t+3,6 a 4,5 s no monitor) vs retido 0 pag. por 36+ s (sem papel).