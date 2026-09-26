# Handoff — migracao-vio-api-br-com-cache

Data: 2026-09-25
Etapa: 00-planejamento

## O que foi pedido

Planejar (sem implementar) a substituição completa da integração direta com o
Serpro (VIO Decode) pela API contratada `https://vio.api.br`, incluindo
validação assíncrona de CNH/CRLV via QR, comparação OCR feita pelo fornecedor,
e um cache local seguro para reduzir chamadas pagas em revisitas do mesmo
motorista/veículo. Trello explicitamente fora do escopo desta demanda.

## Estado real confirmado (explorer, antes de qualquer plano)

- `App\Rn\VioDecodeClient` hoje é síncrono: POST com bytes binários crus do
  QR (nunca JSON/base64/multipart — envelope JSON causava HTTP 415, corrigido
  em 2026-09-08), nunca lança exceção (`['ok','ambiente','dados','erro']`),
  fail-closed na ausência de env. Trial usa Bearer público fixo; Produção usa
  OAuth2 client_credentials cacheado em
  `storage/cache/vio_token_production.json`.
- `App\Rn\ProdespClient` é placeholder morto, nunca chamado por nada no
  projeto.
- `DocumentoController`: `upload()` (grava imagem), `iniciarProcessamento()`
  (hoje síncrono dentro do próprio request, mas tratado como fire-and-forget
  pelo front; concorrência via CAS — `tentativa_id` gravado atomicamente em
  `AtendimentoDao::iniciarProcessamento`/`gravarResultadoProcessamento` —,
  mais `GET_LOCK`/`RELEASE_LOCK` como defesa em profundidade, NUNCA a
  proteção primária), `statusProcessamento()` (hoje leitura pura do banco,
  nunca chama a VIO, com rate limit próprio via `RateLimitVioStatusDao`),
  `preencherManual()` (fallback manual).
- `DocumentoRn`: `ESQUEMA_TIPOS_CNH` = nome/cpf/data_validade;
  `ESQUEMA_TIPOS_CRLV` = placa/exercicio/uf/rntrc (exposto como `rntc`)/tipo.
  Fronteira de tipo estrita via `DocumentoVioTipoInvalidoException` (invalida
  a resposta VIO INTEIRA, nunca parcial). Snapshot (`cnh_snapshot_*`/
  `crlv_snapshot_*`) preserva a "verdade VIO" original. Rebaixamento pra
  MANUAL hoje é sempre ação humana do atendente, não uma transição automática
  do sistema.
- `VioCacheDao`: `tb_vio_cache_cnh` (nome/cpf cifrados AES-256-GCM via
  `Util\CriptografiaHelper`, chave `DOCUMENTO_DATA_KEY`) e `tb_vio_cache_crlv`
  (placa/exercício texto plano), chave de busca HMAC-SHA256 via
  `DOCUMENTO_QR_HMAC_KEY`, `UNIQUE(identificador_qr, ambiente)`, TTL via
  `VIO_CACHE_TTL_DIAS` (default 30).
- Migrations aplicadas: 005 (cache + colunas de origem/status/validado_em),
  006 (snapshot), 007 (status assíncrono: `*_status_processamento`,
  `*_tentativa_id`, `tb_rate_limit_vio_status`).
- Frontend: Expedição já captura CNH frente+verso antes de enviar (upload
  único); Recebimento envia cada lado separado (upload duplo) — precisa ser
  unificado. CRLV hoje faz upload ANTES de ler o QR local — precisa inverter
  a ordem no novo fluxo. `jsQR` já em uso (`qr-worker.js`), sem geração de PDF
  em nenhum lugar do front hoje.
- `composer.json` já tem `setasign/fpdf ^1.8` instalado e em uso real
  (`ImpressaoAtendimentoController`). NÃO tem `setasign/fpdi`.
- Busca global confirmou: zero menção a `vio.api.br` em qualquer lugar do
  projeto (código, docs, `ia_development_state.md`) — integração inteiramente
  nova, sem nenhum precedente versionado.
- `docs/manual_vio_decode.md` documenta o contrato ANTIGO (Serpro/VIO Decode
  direto) — não tem relação com `vio.api.br`; segue existindo como histórico,
  não como base do novo contrato.
- Achado de código relevante para o novo fluxo: `state` do front NUNCA é
  persistido em `localStorage`/sessionStorage/cookie (design deliberado para
  garantir que qualquer F5 sempre volta pra tela LGPD com aceite desmarcado)
  — isso inclui `state.idAtendimento`. Existe um comentário no código que
  descreve `pollarAteTerminal()` como mecanismo de retomada pós-reload, o que
  é inconsistente com esse design atual — ver pendências bloqueantes.

## Arquitetura proposta (síntese dos 3 planos de especialista)

### Cliente HTTP novo

Substituir (sem remover) `VioDecodeClient` por um novo cliente (nome de
trabalho `VioApiBrClient`), com dois métodos públicos separados — nunca um
único método bloqueante:

- `enviarParaLeitura(...)`: exatamente 1 POST a `/api/qrcode/read`
  (`comparar:true`), devolve `['ok','id_externo','erro']`. Nunca faz polling
  internamente.
- `consultarResultado(idExterno)`: exatamente 1 GET a
  `/api/qrcode/result/{id}` por chamada, devolve estado bruto normalizado
  (nunca decide aprovação — isso continua em `DocumentoRn`, mesma separação
  de hoje). Nunca roda em loop com `sleep()` dentro do request PHP (a
  Hostgator compartilhada não permite processo persistente).

Fail-closed de credencial no construtor (env ausente/vazia → `RuntimeException`
imediata, mesmo padrão de hoje). "Inválida/inativa/sem permissão" só é
detectável na primeira chamada real (401/403) — tratado como falha técnica
categorizada em log, mensagem genérica idêntica a qualquer outra falha para
o totem (nunca diferenciar causa pro usuário final, só internamente/log).
Saldo insuficiente (`status:"failed"` sem HTTP de erro) vira categoria própria
de rebaixamento direto para manual.

### Máquina de estados assíncrona (substitui o `PROCESSANDO` único de hoje)

`PENDENTE` → `ENVIANDO` (POST em voo) → `PROCESSANDO_LEITURA` (id externo já
persistido, aguardando leitura) → `PROCESSANDO_COMPARACAO` (leitura completed,
aguardando comparação) → `CONCLUIDO` / `ERRO` (permite nova tentativa) /
`INDETERMINADO` (timeout sem resolução, ou comparação `expired` — nunca
dispara novo POST automático, exige ação explícita).

### Onde persistir o id externo

Dois conceitos distintos, ambos necessários: `tentativa_id` (interno, CAS
local, já existe) continua controlando exclusividade de tentativa;
`cnh_vio_api_id`/`crlv_vio_api_id` (novo, externo, opaco) precisa de coluna
própria, persistida imediatamente após o POST responder com sucesso, antes
de qualquer polling — nunca reaproveitar `tentativa_id` pra isso (ciclos de
vida diferentes: o id externo precisa sobreviver a reload, o `tentativa_id` é
descartável a cada nova tentativa).

### Quem faz o polling

Confirmado pelos 3 especialistas: só o backend, nunca o navegador direto
contra `vio.api.br` (chave nunca exposta ao cliente). Como a Hostgator não
tem worker/job persistente, o polling real só pode acontecer dentro do
próprio request PHP do endpoint `statusProcessamento`, disparado pelo
polling de 2-3s que o totem já faz hoje contra o backend — `statusProcessamento`
deixa de ser leitura pura do banco e passa a fazer 1 GET real por chamada
(mudança de contrato relevante a documentar).

### PDF de 2 páginas da CNH — recomendação (não decisão fechada)

Backend e frontend concordam: gerar no backend, usando FPDF sozinho (já
instalado, suficiente para montar 2 páginas a partir de 2 JPEGs — `fpdi` só
seria necessário para importar PDFs de terceiros como página, não é o caso
aqui). Motivos: projeto é vanilla JS sem lib/build step (gerar no cliente
quebraria esse padrão), sem ganho real de latência (gargalo é o upload das
imagens, não a montagem do PDF), menor risco de memória num kiosk de uso
contínuo o dia todo. Fica registrado como recomendação convergente dos dois
especialistas, não como decisão fechada do orquestrador — a confirmação
final acontece no `/01-implementacao`.

### Cache (`VIO_CACHE`)

- Ampliar/substituir `tb_vio_cache_cnh`/`tb_vio_cache_crlv` com: fingerprint
  HMAC, versão da chave HMAC (nova coluna `hmac_versao`), tipo de documento,
  fornecedor (nova coluna — necessária para não misturar cache Serpro x
  vio.api.br sob o mesmo HMAC), versão do mapeamento, campos normalizados
  permitidos, data de validação, data de validade do documento, exercício
  CRLV, prazo de revalidação, resumo mínimo da comparação (nunca o `summary`
  bruto inteiro), origem/estado, timestamps de auditoria. Nunca QR bruto/
  base64/imagem/resposta íntegra/`ocr_lines`/valores completos de comparação/
  erros brutos/credenciais.
- Segredo do HMAC separado tanto da API Key quanto de `DOCUMENTO_DATA_KEY`
  (blast radius diferente para cada um), com versão explícita no nome da env
  (`VIO_API_BR_CACHE_HMAC_KEY_V1`) para permitir rotação sem invalidar em
  massa: cache antigo simplesmente para de receber hits novos e expira pelo
  TTL já existente; só se a rotação for por suspeita de comprometimento é que
  invalidação ativa deveria ser considerada (decisão operacional a tomar no
  momento, não antecipada aqui).
- CPF e nome continuam cifrados (AES-256-GCM, mesmo padrão de hoje) — CPF é
  identificador direto de alto risco/correlação. Placa/exercício/UF seguem
  texto plano (dado de veículo, já é assim hoje). Achado a decidir com o
  usuário: Renavam é campo novo (não existe na tabela hoje) e tem mais poder
  de correlação que placa isolada — segurança sinalizou, não decidiu, se deve
  seguir texto plano ou ganhar tratamento mais cauteloso.
- Risco de fraude do cache — avaliação explícita das 4 opções pedidas:
  nenhuma das 4 (só QR / QR+TTL curto / QR+OCR local / sem cache) resolve
  sozinha o problema central, porque nenhuma inclui verificação biométrica/
  liveness — um cache-hit nunca reexecuta comparação no fornecedor, e um QR
  fotografado/copiado de documento de terceiro passa no fingerprint da mesma
  forma que o original. Recomendação de segurança: QR + TTL sensivelmente
  mais curto que os 30 dias de retenção do fornecedor (horas a poucos dias,
  valor exato é decisão de produto/risco aceitável do usuário, não técnica),
  apoiado no fato de que o totem hoje opera com supervisão humana da
  apresentação física do documento — o cache serve pra evitar custo repetido
  do MESMO motorista revisitando em curto prazo, não para substituir a
  checagem visual humana. Achado crítico registrado sem decidir: se o totem
  algum dia operar sem supervisão humana continuada, o risco sobe de atenção
  para crítico.

## Contratos/estados planejados

Ver seção "Contrato conhecido da API" mais abaixo (o que foi fornecido pelo
usuário, tratado como planejado/não implementado) e a máquina de estados
acima. Novos valores de `ENUM` previstos (nomes de trabalho, a confirmar na
implementação): `cnh_status_processamento`/`crlv_status_processamento` ganham
`ENVIANDO`/`PROCESSANDO_LEITURA`/`PROCESSANDO_COMPARACAO`/`INDETERMINADO`;
`cnh_origem_validacao`/`crlv_origem_validacao` ganham `VIO_CACHE`.

### Pontos de acoplamento a `VIO_TRIAL`/`VIO_VALIDADO`/`MANUAL` já encontrados no código

- `DocumentoController::respostaStatusAtual()` — allowlist fechada
  `in_array($origem, ['VIO_TRIAL','VIO_VALIDADO','MANUAL'], true)` decide se
  o documento é considerado aprovado. `VIO_CACHE` precisa entrar nessa lista
  ou documentos vindos de cache nunca avançam o totem.
- `DocumentoController::preencherManual()` — usa
  `in_array($origemAtualCrlv, ['VIO_TRIAL','VIO_VALIDADO'], true)` pra decidir
  se reaproveita `rntc`/`tipo_veiculo` já gravados. Se `VIO_CACHE` também deve
  entrar aqui é decisão de regra de negócio pendente, não resolvida neste
  plano.
- Front-end/`TalentClient`: não verificados nesta rodada (fora da lista de
  arquivos da tarefa de backend) — checagem explícita fica pendente para o
  `/01-implementacao`.

## O que será feito (na futura `/01-implementacao`, NÃO agora)

- Novo `VioApiBrClient` (mantendo `VioDecodeClient` fisicamente intocado no
  repositório — rollback via flag de ambiente, não deleção).
- Ajustes em `DocumentoController`/`DocumentoRn`/`AtendimentoDao` para a nova
  máquina de estados assíncrona e persistência do id externo.
- Nova(s) migration(s) aditivas: colunas de id externo, novos valores de
  `ENUM`, ampliação/nova versão de `tb_vio_cache_cnh`/`tb_vio_cache_crlv`
  (schema exato de coluna definido na implementação, não aqui).
- Geração de PDF de 2 páginas da CNH no backend via FPDF (recomendação
  convergente, a confirmar).
- Frontend: unificar Recebimento no padrão de captura frente+verso antes de
  enviar (já usado pela Expedição); inverter a ordem do CRLV (checar QR local
  ANTES do upload, não depois como hoje); nova tela/estados de espera com
  progresso granular (ENVIANDO/PROCESSANDO_LEITURA/PROCESSANDO_COMPARACAO/
  CONCLUIDO/FALHA/INDETERMINADO vindos de campo explícito do backend, nunca
  inferidos no front); tratamento de erro dedicado (texto fixo genérico, sem
  propagar `e.message` bruto do backend) separado do `mostrarErroTela()`
  genérico usado hoje em outros pontos.
- Testes com mocks locais cobrindo a matriz completa (ver seção de testes).

## O que NÃO será feito (nesta demanda / nesta etapa)

- Nenhuma chamada real/paga à `vio.api.br` em nenhuma etapa até nova
  autorização explícita do usuário, com quantidade e documento autorizados.
- Nenhuma leitura/exibição/cópia/teste/registro da credencial nova ou antiga.
- Nenhuma alteração no `.env` nesta etapa de planejamento.
- Nenhuma migration executada, nenhum banco alterado.
- Remoção física de `VioDecodeClient`/`ProdespClient` — ambos continuam
  existindo (rollback simples via flag).
- Decisão de "quando desligar de fato o Serpro" — fica para decisão de
  produto futura, fora do escopo desta demanda.
- Calendário legal de licenciamento do CRLV — não inventado; ver pendência
  dedicada.
- Uso do Trello (instrução explícita do usuário para esta demanda).
- Higiene de `docs/indexTotem.html`, `.claude/skills/`, `tests/nf_teste/` —
  autorizações já registradas (indexTotem.html e `.claude/skills/` autorizados
  pra remoção; `tests/nf_teste/` sem decisão) ficam para demanda documental
  SEPARADA, não tocadas aqui.

## Sub-agentes envolvidos

- `explorer` — mapeamento do estado real de `VioDecodeClient`, `ProdespClient`,
  `DocumentoController`, `DocumentoRn`, DAOs, migrations, frontend de
  captura, testes existentes, confirmação de ausência total de `vio.api.br`
  no projeto.
- `backend-especialista` — desenho do cliente HTTP, máquina de estados,
  persistência do id externo, quem faz o polling, recomendação de PDF,
  migrations previstas, pontos de acoplamento de origem, estratégia de
  rollback, perguntas ao fornecedor.
- `security-especialista` — validação fail-closed de credencial, separação/
  versionamento do segredo HMAC do cache, decisão de quais campos cifrar,
  avaliação comparativa de risco de fraude do cache, mecanismo de
  concorrência/resultado tardio (confirmou que o padrão real do projeto é CAS
  via `tentativa_id`, não `GET_LOCK` como havia sido presumido na requisição
  original — correção registrada), sinalização de privacidade/retenção,
  matriz de testes de segurança.
- `frontend-especialista` — fluxo de captura unificado CNH/CRLV, posição da
  checagem local de QR, recomendação de local de geração do PDF (ângulo UX/
  performance), desenho da tela de polling com estados intermediários,
  comportamento de timeout sem reenvio automático, achado bloqueante sobre
  retomada de polling pós-reload, tratamento de mensagem genérica de erro.

## Contrato conhecido da API (fornecido pelo usuário — tratado como PLANEJADO)

- `POST /api/qrcode/read`, header `X-API-Key`, body `{image, file_name,
  comparar:true}`. Resposta assíncrona: `{id, status:"processing"}`.
- `GET /api/qrcode/result/{id}`, polling 2-3s. Estados de leitura:
  processing/completed/failed. Estados de comparação:
  pending/processing/completed/failed/expired.
- Arquivo original retido até 30 dias pelo fornecedor; só leituras
  `completed` são cobradas; falhas não cobram; saldo insuficiente vem como
  `status:"failed"`, nunca HTTP de erro.
- Não usar `compare_lines=true` em produção.
- Critério de aprovação automática: leitura completed + `qr_type=vio` +
  `vio_result` válido + comparação completed + `summary.reliable=true` +
  `summary.mismatched=0` + nenhum campo crítico divergente/ausente (CNH:
  nome/CPF/validade; CRLV: placa/Renavam/exercício/UF).
- `mismatch`/`not_found` em campo crítico/`reliable=false`/comparação
  failed-expired/leitura failed → manual. `not_found` em campo secundário →
  avaliar por confiabilidade global. Timeout → indeterminado, nunca novo
  POST. Saldo insuficiente → mensagem genérica + manual.

## Política de validade — proposta (parcial, ver pendências)

- CNH vencida / CRLV com exercício anterior: já existe critério equivalente
  hoje em `DocumentoRn` (CNH vencida reprova; CRLV usa `exercicio` numérico)
  — reaproveitar o mesmo critério.
- Calendário legal de licenciamento do CRLV por final de placa: não
  inventado — não há fonte confiável no projeto hoje. Fica registrado como
  decisão de produto pendente, não uma regra técnica presumida.
- Cache atingindo o prazo máximo / mudança de QR após renovação / mudança de
  versão do mapeamento / alteração manual posterior aos dados / revogação
  administrativa do cache: nenhuma dessas regras foi desenhada em detalhe
  nesta rodada — ficam para o `/01-implementacao`, com o TTL curto
  recomendado pela segurança como ponto de partida a confirmar com o usuário.

## Perguntas ao fornecedor vio.api.br (contrato incompleto — não inventar resposta)

1. Tamanho máximo de arquivo (imagem única e PDF multipágina).
2. Número máximo de páginas aceito num PDF.
3. Formatos aceitos além de imagem/PDF, resolução mínima/máxima.
4. Limite de requisições por minuto/hora e comportamento em excesso.
5. Timeout recomendado de conexão/leitura do lado do cliente.
6. Tempo total esperado de processamento (leitura + comparação) em cenário
   normal — necessário pra calibrar o timeout de `INDETERMINADO`.
7. Custo de PDF multipágina (cobrado como 1 leitura ou por página?).
8. Custo/comportamento quando há múltiplos QR codes numa mesma imagem.
9. Existe webhook como alternativa a polling, ou só polling é suportado?
10. É possível solicitar exclusão antecipada do arquivo original (antes dos
    30 dias padrão)?
11. Formato exato de erro para QR ilegível/corrompido — existe código
    categorizado análogo ao `codigo_vio` do Serpro?
12. Comportamento de `GET /result/{id}` para um `id` inexistente/expirado/
    purgado (404? campo de erro?) — necessário pra tratar retomada de
    polling após muito tempo.
13. A API suporta algum mecanismo de idempotência (`idempotency_key`/
    `request_id`) para mitigar risco de retry em timeout de leitura (achado
    de segurança: retry após timeout de rede pura é seguro, retry após
    timeout de leitura da resposta arrisca cobrança duplicada porque o
    fornecedor pode já ter processado a primeira chamada)?
14. A API distingue 401 (credencial inválida) de 403 (credencial válida sem
    permissão `qrcode:write`/`qrcode:read`)?

## Matriz de testes planejada (mocks locais apenas — zero chamada real)

Funcional/estado: cache miss, cache hit, cache vencido, documento vencido, QR
renovado, concorrência (2 abas/refresh), POST único garantido, polling,
retomada após refresh (bloqueada até resolver a pendência de `state`),
cancelamento, timeout, resposta tardia (atendimento já cancelado/concluído),
saldo insuficiente, PDF com 1 e 2 páginas, CRLV, fallback manual,
rebaixamento para MANUAL.

Transporte/segurança: 401, 403, 404, 422, JSON inválido, resposta excessiva
(teto de bytes), campos extras/desconhecidos, imagem inesperada, comparação
`reliable=true`/`mismatch`/`not_found` crítico e secundário, zero vazamento de
credencial/CPF/nome/QR bruto em log, zero credencial no frontend (inspeção de
Network), fingerprint HMAC sem colisão, versão de HMAC não gera hit cruzado,
dados cifrados ilegíveis fora da aplicação.

Regressão: Recebimento, Expedição, Talent, impressão, LGPD, JPEG — sem
quebra.

## Riscos e pendências identificadas

### Bloqueantes para o `/01-implementacao`

1. Contrato real da `vio.api.br` incompleto — as 14 perguntas acima não têm
   resposta; timeout de `INDETERMINADO` e vários detalhes de transporte não
   podem ser calibrados sem isso.
2. Inconsistência de retomada pós-reload (achado do frontend-especialista):
   `state.idAtendimento` não sobrevive a F5 por design deliberado (sempre
   volta à tela LGPD), mas existe comentário no código tratando
   `pollarAteTerminal()` como mecanismo de recuperação pós-reload — isso é
   uma contradição real que precisa de decisão de produto/arquitetura antes
   de fechar o desenho de retomada de polling (risco real de atendimento
   órfão com chamada já paga à `vio.api.br` em andamento).
3. Nova credencial ainda não existe (rotação pendente) — sem ela, nenhuma
   chamada real, mock ou de homologação é possível.

### Não bloqueantes, mas relevantes

- Decisão de regra de negócio: `VIO_CACHE` deve ou não entrar na allowlist de
  reaproveitamento de `rntc`/`tipo_veiculo` em `preencherManual()`?
- Renavam (campo novo) — texto plano ou tratamento mais cauteloso? Sinalizado
  pela segurança, não decidido.
- TTL exato do cache — recomendação de segurança é "sensivelmente menor que
  30 dias", valor exato é decisão de produto do usuário.
- Rotação de HMAC key por suspeita de comprometimento exigiria invalidação
  ativa (não só passiva por TTL) — decisão operacional a tomar no momento,
  não antecipada.
- Retry automático de timeout de rede hoje existe em `VioDecodeClient`
  (`maxTentativas=2` só para timeout de rede pura) — precisa ser revisado na
  implementação nova para não confundir timeout de conexão (seguro) com
  timeout de leitura de resposta (risco de cobrança duplicada).
- Gravação de resultado tardio deveria checar `status` geral do atendimento
  além do `tentativa_id` específico do campo VIO (achado de segurança).
- Verificação de acoplamento de front-end/`TalentClient` a strings de origem
  (`VIO_TRIAL`/`VIO_VALIDADO`/`MANUAL`) não foi feita nesta rodada — só
  `DocumentoController` foi checado.
- Migrations 009/010 (UF/RNTC/tipo_veiculo do CRLV para o Talent) não foram
  lidas integralmente nesta rodada, só inferidas via comentário de código.
- Memória PHP real disponível no plano Hostgator não foi confirmada (usada
  estimativa de mercado, não dado real do ambiente).

### Privacidade — sinalizações da segurança, não decisões

- Mudança de operador (Serpro → vio.api.br) tipicamente exige nova análise/
  aprovação de operador pelo DPO, independente do cache local ser idêntico.
- Se o cache algum dia for usado para histórico/perfilamento entre
  atendimentos diferentes do mesmo motorista/veículo (além de "evitar custo
  repetido dentro do TTL"), isso é ampliação de finalidade que exigiria nova
  aprovação — não deve ser assumida como já coberta.
- Aprovação do DPO para retenção de 30 dias pelo fornecedor foi informada
  pelo usuário e registrada como fato relatado, sem validação/questionamento
  adicional por este orquestrador ou pelos especialistas.

## Confirmação de zero operação real

Nenhuma chamada de rede real/paga foi feita. Nenhuma credencial foi lida,
exibida, copiada, testada ou registrada. Nenhum `.env` foi alterado. Nenhuma
migration foi executada. Nenhum banco foi alterado. Nenhum documento real foi
usado. Nenhuma impressão ocorreu. Nenhum acesso a produção/Hostgator ocorreu.
Nenhum commit ou push foi feito. Trello não foi usado, por instrução
explícita do usuário para esta demanda.

## Trello

card_id: N/A - Trello explicitamente não usado nesta demanda, por instrução
do usuário.

## Próximo passo (planejamento — histórico)

Resolver as pendências bloqueantes (contrato completo do fornecedor, decisão
sobre retomada pós-reload, obtenção da credencial rotacionada) antes de
`/01-implementacao`. As pendências não bloqueantes podem ser decididas junto
com o início da implementação, conforme forem sendo alcançadas no fluxo.

## `/01-implementacao` — resultado (2026-09-25)

O usuário resolveu diretamente, por decisão explícita registrada no próprio
prompt desta rodada, as pendências bloqueantes que impediam a implementação
(contrato mínimo da vio.api.br tratado como PLACEHOLDER fornecido e aceito
para esta rodada; decisão de kiosk/reload confirmada — nenhuma retomada
visual de SPA por F5; nenhuma chamada real/paga autorizada ainda, então a
ausência de credencial nova não bloqueou a implementação em si). Trello não
usado, por instrução explícita. Nenhum commit/push feito nesta rodada.

### O que foi implementado

- **`App\Rn\VioApiBrClient`** (novo, `app/Rn/VioApiBrClient.php`) — cliente
  HTTP com 2 métodos separados (`enviarParaLeitura()`/`consultarResultado()`),
  nenhum polling interno, fail-closed de `VIO_API_BR_BASE_URL`/
  `VIO_API_BR_API_KEY` (inclusive exige HTTPS), HTTPS/certificado sempre
  validados, timeouts de conexão (5s) e total (20s) separados como constantes
  de classe (lista de env novas ficou fechada pelo usuário), teto de 10 MB na
  resposta (mesmo padrão de `VioDecodeClient`), transporte HTTP injetável via
  `callable` (mock completo sem rede em teste), nunca usa `compare_lines`,
  nunca loga corpo/credencial/dado pessoal. Distingue falha "sem ambiguidade"
  (segura para nova tentativa) de "ambígua" (nunca retry automático — vira
  `INDETERMINADO`) tanto no envio quanto na consulta.
- **`sql/migrations/015_vio_api_br_estados_e_id_externo.sql`** (novo) —
  amplia os `ENUM` de `cnh_status_processamento`/`crlv_status_processamento`
  com `ENVIANDO`/`PROCESSANDO_LEITURA`/`PROCESSANDO_COMPARACAO`/
  `INDETERMINADO` (mantendo `PENDENTE`/`PROCESSANDO`/`CONCLUIDO`/`ERRO` já
  existentes, para não quebrar o rollback do fluxo antigo); adiciona
  `VIO_CACHE` a `cnh_origem_validacao`/`crlv_origem_validacao`; adiciona
  colunas novas por documento: `*_vio_api_id` (ID externo opaco),
  `*_vio_api_enviado_em`, `*_vio_api_fingerprint`,
  `*_vio_api_fingerprint_versao`. Idempotente (checa `COLUMN_TYPE`/
  `INFORMATION_SCHEMA.COLUMNS` antes de cada `ALTER`).
- **`sql/migrations/016_vio_api_br_cache.sql`** (novo) — cria
  `tb_vio_api_cache_cnh`/`tb_vio_api_cache_crlv`, tabelas NOVAS e SEPARADAS
  de `tb_vio_cache_cnh`/`tb_vio_cache_crlv` (fluxo antigo, intactas). Campos:
  fingerprint + versão do HMAC, `fornecedor` fixo `'VIO_API_BR'`,
  `versao_mapeamento`, nome/cpf/renavam cifrados (AES-256-GCM via
  `Util\CriptografiaHelper`, mesma chave `DOCUMENTO_DATA_KEY` já usada — sem
  criptografia nova), placa/uf/exercicio/rntc/tipo_veiculo em texto plano
  (mesmo tratamento já aprovado), resumo mínimo da comparação (`reliable`
  bool + `mismatched` int — nunca o `summary` bruto inteiro), `estado`
  (`VALIDO`/`REVOGADO`, sem nenhuma ação que grave `REVOGADO` ainda),
  `validado_em`/`revalidar_apos`/`expira_em`, timestamps de auditoria.
  `UNIQUE KEY (fingerprint, hmac_versao, fornecedor, versao_mapeamento)`.
  Comentário explícito registrando a limitação de que um cache-hit nunca
  reexecuta OCR no fornecedor.
- **`App\Dao\VioApiBrCacheDao`** (novo, `app/Dao/VioApiBrCacheDao.php`) —
  `buscarCnhValido()`/`salvarCnh()`/`buscarCrlvValido()`/`salvarCrlv()`
  contra as tabelas novas, `INSERT ... ON DUPLICATE KEY UPDATE`, nunca recebe
  o QR bruto (só o fingerprint já calculado).
- **`App\Dao\AtendimentoDao`** (aditivo, métodos novos ao final, nenhum
  método antigo alterado) — `iniciarEnvioVioApiBr()` (CAS PENDENTE/ERRO ->
  ENVIANDO, grava fingerprint/versão), `gravarIdExternoVioApiBr()` (ENVIANDO
  -> PROCESSANDO_LEITURA, grava ID externo + timestamp de envio),
  `marcarEnvioComoErro()`/`marcarEnvioComoIndeterminado()` (falha
  sem-ambiguidade vs. ambígua), `avancarParaProcessandoComparacao()`,
  `gravarResultadoFinalVioApiBr()` (CONCLUIDO/ERRO/INDETERMINADO, com DUPLA
  checagem no `WHERE`: `tentativa_id` E `status = 'em_andamento'` do
  atendimento — achado do security-especialista sobre resultado tardio),
  `marcarProcessamentoVioApiBrExpiradoComoIndeterminado()` (limite de
  duração, nunca volta a `PENDENTE`).
- **`App\Rn\DocumentoRn`** (aditivo — `validarCnh()`/`validarCrlv()` do
  fluxo antigo, Serpro, permanecem 100% intocados) —
  `calcularFingerprintVioApiBr()` (HMAC com chave/versão dedicadas),
  `tentarCacheCnh()`/`tentarCacheCrlv()` (cache-hit reaproveitando
  INTEGRALMENTE `avaliarCnh()`/`avaliarCrlv()` já aprovados, origem
  `VIO_CACHE`), `avaliarResultadoVioApiBrCnh()`/`avaliarResultadoVioApiBrCrlv()`
  (critério de aprovação automática completo: leitura+comparação
  `completed`, `qr_type=vio`, `reliable=true`, `mismatched=0`, `pages_processed`/
  `total_pages=2` só para CNH, nenhum campo crítico com `mismatch`/
  `not_found` — CNH: nome/cpf/data_validade; CRLV: placa/renavam/exercicio/uf
  — grava cache automaticamente quando aprovado). Reaproveita a fronteira de
  tipo já existente (`extrairCampoTexto`/`extrairCampoNumerico`/
  `DocumentoVioTipoInvalidoException`) sem duplicar lógica.
- **`App\Controller\DocumentoController`** — `iniciarProcessamento()`
  reescrito: já-aprovado -> idempotente; fingerprint -> cache (hit evita
  QUALQUER chamada externa) -> CAS de envio -> monta imagem (PDF de 2
  páginas da CNH via `Util\AnexoPdfHelper::gerarPdfDeImagens()`, já
  aprovado/em uso pelo Talent, SEM lib nova; JPEG único do CRLV) -> exatamente
  1 POST -> persiste ID externo antes de qualquer polling.
  `statusProcessamento()` reescrito: aplica limite de duração -> no máximo 1
  GET real por chamada, só quando há ID externo pendente -> nunca decide
  aprovação de negócio diretamente, delega a `DocumentoRn`.
  `respostaStatusAtual()` ganhou `VIO_CACHE` na allowlist de aprovação e
  `INDETERMINADO` no cálculo de `terminal`; `aviso_trial` fixado em `false`
  (vio.api.br não tem conceito de trial). `preencherManual()` — a allowlist
  de reaproveitamento de `rntc`/`tipo_veiculo` ganhou `VIO_CACHE` ao lado de
  `VIO_TRIAL`/`VIO_VALIDADO`.
- **`public/api/documento.php`** — injeta `VioApiBrCacheDao` na construção de
  `DocumentoRn`.
- **`.env.example`** — bloco novo `VIO_API_BR_*` (5 variáveis, só
  placeholders seguros).
- **`docs/deploy-checklist.md`** — nova seção 1.Z com checklist específico
  desta migração.
- **`public/totem/assets/app.js`** — comentário de `pollarAteTerminal()`
  corrigido (não prometia mais retomada pós-reload; a versão anterior
  descrevia um cenário — "após recarregar a página" — que nunca ocorre na
  prática, já que `state` nunca sobrevive a F5 por design). Nenhuma mudança
  funcional neste arquivo.

### Decisões tomadas dentro do espaço já definido pelo usuário

- Origem gravada para uma validação REAL (não cache, não manual) continua
  `VIO_VALIDADO` (mesmo valor de `ENUM` já existente do fluxo antigo) — não
  foi criado um valor `VIO_API_BR` redundante só para diferenciar o
  fornecedor; a distinção fica no fato de que o fluxo antigo nunca mais é
  chamado automaticamente.
- Timeouts de conexão/total do `VioApiBrClient` (5s/20s) e o limite de
  duração máxima de processamento assíncrono (120s, em
  `DocumentoController::DURACAO_MAXIMA_PROCESSAMENTO_VIO_API_BR_SEGUNDOS`)
  implementados como constantes de classe, não `.env` — a lista de variáveis
  novas desta demanda foi fechada explicitamente pelo usuário.
- `VioApiBrClient` não tenta reconhecer "saldo insuficiente" por string
  matching em `error_message` (formato não confirmado pelo fornecedor) —
  tratado pelo mesmo caminho genérico de leitura/comparação `failed`
  (`CONCLUIDO` + fallback manual, mensagem sanitizada). Registrado como
  pendência de contrato, não inventado.
- Versão de mapeamento (`VERSAO_MAPEAMENTO_CNH`/`VERSAO_MAPEAMENTO_CRLV` em
  `DocumentoRn`, ambas `1`) criada para satisfazer a exigência de
  invalidação de cache por mudança de regras de extração/aprovação.
- Renavam é campo crítico da aprovação automática do CRLV, cifrado no cache,
  mas NÃO ganhou coluna em `tb_atendimento` (sem consumidor hoje — Talent
  usa só placa/uf/rntc/tipo) — usado só em memória para a decisão de
  aprovação e para o registro de cache.
- Nome exato dos novos métodos de `AtendimentoDao`: `iniciarEnvioVioApiBr`,
  `gravarIdExternoVioApiBr`, `marcarEnvioComoErro`,
  `marcarEnvioComoIndeterminado`, `avancarParaProcessandoComparacao`,
  `gravarResultadoFinalVioApiBr`,
  `marcarProcessamentoVioApiBrExpiradoComoIndeterminado`. Nome exato das
  colunas novas: `cnh_vio_api_id`/`crlv_vio_api_id`,
  `cnh_vio_api_enviado_em`/`crlv_vio_api_enviado_em`,
  `cnh_vio_api_fingerprint`/`crlv_vio_api_fingerprint`,
  `cnh_vio_api_fingerprint_versao`/`crlv_vio_api_fingerprint_versao`.

### O que NÃO foi feito nesta rodada (fora do escopo desta etapa)

- Nenhuma chamada real/paga, nenhuma leitura/uso da `VIO_API_BR_API_KEY`.
- Nenhuma migration aplicada em banco real (`udlog_totem`) — só criadas,
  sintaxe validada por leitura; aplicação/teste em banco descartável fica
  para `/02-testes`.
- Nenhuma mudança de frontend além da correção pontual do comentário em
  `app.js` — unificação de captura CNH no Recebimento, inversão de ordem do
  CRLV, e a nova tela de estados intermediários ficam para o
  `frontend-especialista`, em rodada separada, DEPOIS desta.
- Nenhum mecanismo de retomada de SPA por F5/reload foi implementado (nem
  cookie, nem localStorage, nem sessionStorage) — decisão de produto mantida.
- Nenhuma alteração em `VioDecodeClient.php`/`ProdespClient.php`,
  `tb_vio_cache_cnh`/`tb_vio_cache_crlv`, ou nas migrations 001-014.
- Trello não usado. Nenhum commit/push.

## Rodada corretiva (2026-09-26) — separação de origens e unificação CNH

Rodada corretiva curta de `/01-implementacao`, escopo fechado pelo
orquestrador em 5 tarefas. Preflight de segurança confirmado limpo antes de
tocar em qualquer arquivo (sem credencial em nenhum arquivo, `.env.example`
zerado, nenhuma migration aplicada em `udlog_totem`). Nenhuma chamada
real/paga, nenhum uso de credencial nova/antiga, nenhuma migration aplicada
em banco real, nenhum commit/push nesta rodada.

### Tarefa 1 — Unificação da captura de CNH do Recebimento (achado: já estava correta)

Leitura de código (`app/Controller/DocumentoController.php`,
`app/Controller/AtendimentoController.php::SEQUENCIA_RECEBIMENTO_DOCUMENTOS`,
`util/AnexoPdfHelper.php`) confirmou que o backend **já** garante, desde a
implementação original de 2026-09-25, exatamente 1 tentativa de validação
externa por documento, mesmo com o Recebimento fazendo 2 uploads separados
(`cnh_frente`/`cnh_verso`, em etapas distintas):

- `iniciarProcessamento()`/`statusProcessamento()` só aceitam `tipo='cnh'`
  como unidade indivisível — nunca existiu (nem no fluxo antigo, nem no
  novo) uma ação `iniciar-processamento` para `cnh_frente`/`cnh_verso`
  isolados. Um único envio, um único `comparar:true`, sempre.
- `montarPdfCnh()` (via `Util\AnexoPdfHelper::gerarPdfDeImagens()`) lê os 2
  arquivos (`cnh_frente.jpg`/`cnh_verso.jpg`) **antes** de qualquer chamada a
  `App\Rn\VioApiBrClient` — `AnexoPdfHelper::validarJpeg()` lança
  `RuntimeException` se qualquer um dos 2 arquivos ainda não existir em
  disco, o que aborta o fluxo (marca `ERRO`, permite nova tentativa) **sem
  nunca chegar a montar/enviar o POST**. Ou seja: mesmo que a máquina de
  etapas permitisse, em tese, chamar `iniciar-processamento` para CNH antes
  do verso ser de fato salvo (a etapa `rec_cnh_verso` já é liberada assim
  que a FRENTE é salva, antes da captura do verso), o backend nunca chegaria
  a fazer uma chamada externa incompleta — a falha acontece 100% localmente,
  antes de qualquer rede.

**Decisão tomada dentro do espaço definido**: como esse cenário (chamada
prematura, etapa já liberada mas verso ainda não capturado) é previsível e
hoje desperdiçaria um ciclo inteiro de CAS (`PENDENTE→ENVIANDO→ERRO`) só para
descobrir que faltava um arquivo, foi adicionado um gate explícito e mínimo
**antes** do CAS de envio: `DocumentoController::
arquivosCnhAmbosLadosPresentes()` — só um `is_file()` dos 2 caminhos,
retornando HTTP 409 com mensagem dedicada
("Aguardando captura de frente e verso da CNH antes de iniciar a
validacao") sem tocar em nenhum estado do banco. Nenhuma alteração na
`SEQUENCIA_RECEBIMENTO_DOCUMENTOS`/gates de etapa, nenhuma mudança na
Expedição (que já captura frente+verso numa única chamada de upload). Front
continua livre para decidir se mantém 2 uploads separados ou não — fora de
escopo desta rodada, dessa vez de fato reservado ao `frontend-especialista`.

### Tarefa 2 — Separação de origens de auditoria

Migrations 015/016 editadas DIRETAMENTE (nenhuma migration 017 criada,
conforme instrução — nenhuma delas foi aplicada em banco real até agora):

- `sql/migrations/015_vio_api_br_estados_e_id_externo.sql`:
  `cnh_origem_validacao`/`crlv_origem_validacao` ganharam o valor `VIO_API_BR`
  (além do `VIO_CACHE` já existente da rodada anterior).
- `sql/migrations/016_vio_api_br_cache.sql`: nenhuma mudança necessária —
  `fornecedor`/`origem_original` das tabelas de cache já eram `ENUM` de
  valor único `'VIO_API_BR'` desde a implementação original, já consistente
  com a nomenclatura final.

Código ajustado de forma consistente:

- `app/Rn/DocumentoRn.php`: `avaliarResultadoVioApiBrCnh()`/
  `avaliarResultadoVioApiBrCrlv()` passam a gravar origem `VIO_API_BR` (nunca
  mais `VIO_VALIDADO`) ao aprovar automaticamente uma validação real da
  vio.api.br.
- `app/Dao/AtendimentoDao.php`: `atualizarValidacaoCnh()`/
  `atualizarValidacaoCrlv()` — a lista `$ehOrigemVio` (que decide se o
  SNAPSHOT `cnh_snapshot_*`/`crlv_snapshot_*` é preservado) ganhou
  `VIO_API_BR` ao lado de `VIO_TRIAL`/`VIO_VALIDADO`. **Achado corrigido
  nesta rodada**: sem esse ajuste, uma validação real aprovada pela
  vio.api.br deixaria de preservar a "verdade VIO original" usada por
  `AtendimentoRn::salvarDadosMotorista` para decidir o rebaixamento para
  `MANUAL` na tela `exp_confirma` — o snapshot simplesmente nunca seria
  gravado para o fluxo novo.
- `app/Controller/DocumentoController.php`: `respostaStatusAtual()` (campo
  `aprovado`) e `preencherManual()` (allowlist de reaproveitamento de
  `rntc`/`tipo_veiculo`) passam a incluir `VIO_API_BR` ao lado de
  `VIO_CACHE`/`VIO_TRIAL`/`VIO_VALIDADO`/`MANUAL`. `VIO_VALIDADO` permanece
  nas duas allowlists só por compatibilidade histórica (fluxo antigo
  Serpro), nunca mais escrito por código novo.
- `app/Dao/VioApiBrCacheDao.php`: `buscarCnhValido()`/`buscarCrlvValido()`
  ganharam filtro explícito `AND origem_original = 'VIO_API_BR'` — defesa em
  profundidade registrada em comentário (hoje sempre verdadeiro por
  construção, já que a coluna é um `ENUM` de valor único nesta tabela
  exclusiva; o filtro explícito evita depender apenas do schema caso o
  `ENUM` seja ampliado no futuro). Confirma a exigência de que RNTC/tipo de
  veículo do cache só sejam reaproveitados de um registro cuja origem
  original foi de fato uma validação vio.api.br.
- **Achado adicional encontrado nesta rodada (varredura de todos os pontos
  de acoplamento a strings de origem, não limitada aos já listados no
  handoff original)**: `app/Rn/AtendimentoRn.php::salvarDadosMotorista()` —
  a lógica de rebaixamento para `MANUAL` por divergência do snapshot na tela
  `exp_confirma` também comparava a origem contra uma allowlist fechada
  (`['VIO_TRIAL', 'VIO_VALIDADO']`) que NÃO incluía `VIO_CACHE` nem
  `VIO_API_BR`. Sem a correção, uma CNH/CRLV aprovada automaticamente pela
  vio.api.br (ou por cache-hit) que fosse editada divergentemente pelo
  atendente NUNCA seria rebaixada para `MANUAL` — o bloco de comparação
  simplesmente não executaria para essa origem, deixando a origem/
  status_revisao incorretos (indicando validação automática confiável para
  um dado na verdade editado manualmente). Corrigido acrescentando
  `VIO_API_BR` às duas allowlists (CNH e CRLV) desse método. `VIO_CACHE` foi
  investigado e confirmado como não aplicável aqui: cache-hit reaproveita
  `avaliarCnh()`/`avaliarCrlv()` (mesmo caminho de `atualizarValidacaoCnh()`/
  `atualizarValidacaoCrlv()`), mas o `$ehOrigemVio` dessas duas funções (ver
  acima) só grava snapshot para `VIO_TRIAL`/`VIO_VALIDADO`/`VIO_API_BR` — um
  documento aprovado via `VIO_CACHE` nunca tem snapshot próprio gravado (o
  snapshot já existe de uma validação `VIO_API_BR` anterior, preservado
  intacto), então incluir `VIO_CACHE` nesta allowlist de
  `salvarDadosMotorista()` compararia contra um snapshot desatualizado/de
  outra tentativa — decisão de negócio não solicitada nesta rodada, não
  alterada; registrado aqui como achado não bloqueante para decisão futura
  do usuário se necessário.
- Serialização ao frontend: nenhuma mudança necessária além do já descrito
  acima — `respostaStatusAtual()` devolve o valor de `origem` direto da
  coluna do banco, então passa a devolver `VIO_API_BR` automaticamente assim
  que a migration 015 for aplicada; front-end (rótulo de origem em
  `app.js`) não foi tocado, por instrução explícita — fica para o
  `frontend-especialista` consumir o novo valor.

### Tarefa 3 — Reconciliação de kiosk

Novo `App\Dao\AtendimentoDao::reconciliarProcessamentoVioApiBrAbandonado()`
(aditivo) — chamado por `App\Controller\AtendimentoController::
consumirAceiteECriarAtendimento()` (via novo passthrough
`AtendimentoRn::reconciliarProcessamentoAbandonado()`) logo após a criação
do atendimento novo, dentro da MESMA transação já existente (nenhuma chamada
de rede dentro da transação, mesma garantia já documentada). Para qualquer
atendimento ANTERIOR do mesmo `id_totem`, ainda `em_andamento`, com
`cnh_status_processamento`/`crlv_status_processamento` em
`ENVIANDO`/`PROCESSANDO_LEITURA`/`PROCESSANDO_COMPARACAO`, transiciona
DIRETAMENTE para `INDETERMINADO` (nunca `PENDENTE` — nunca reabre a
possibilidade de novo POST automático). Escopo restrito por `id_totem` +
`id_atendimento != recém-criado` + `status = 'em_andamento'` — nunca toca no
atendimento novo, nunca em atendimento já cancelado/concluído.

**Achado/decisão registrada**: como o `state` do totem nunca sobrevive a
F5/reload (design já existente) e cada atendimento tem suas próprias colunas
de rastreamento (`*_tentativa_id`/`*_vio_api_id`), uma tentativa antiga nunca
poderia gerar um segundo POST de qualquer forma (a dupla checagem
`tentativa_id` + `status='em_andamento'` de
`gravarResultadoFinalVioApiBr()` já isola cada atendimento). O risco real que
esta reconciliação resolve é diferente: sem ela, essas colunas de um
atendimento abandonado ficariam PRESAS PARA SEMPRE em `ENVIANDO`/
`PROCESSANDO_LEITURA`/`PROCESSANDO_COMPARACAO`, porque o único mecanismo que
as move dali (`marcarProcessamentoVioApiBrExpiradoComoIndeterminado()`) só
roda quando o front chama `status-processamento` — o que nunca mais
acontece para um atendimento abandonado, já que o totem sempre reinicia na
tela LGPD com um `id_atendimento` novo. Não foi implementado nenhum
mecanismo de retomada visual/SPA (explicitamente dispensado) nem qualquer
alteração no status geral (`status`) do atendimento antigo — só nas 2
colunas de processamento vio.api.br; limpeza mais ampla de resíduo de
atendimento continua fora de escopo (tarefa do `qa-testes`, protocolo
próprio).

### Tarefa 4 — Reconfirmação

Todos os pontos da lista de reconfirmação (1 POST por tentativa, polling só
GET, leitura/comparação como estados diferentes, `INDETERMINADO` nunca volta
a `PENDENTE`, timeout pós-envio nunca dispara novo POST, resultado tardio
protegido por `tentativa_id`+status, cancelamento/conclusão impedem
gravação tardia, saldo insuficiente com falha controlada, mensagens
sanitizadas; cache com HMAC-SHA256 sobre bytes exatos do QR, segredo HMAC
separado, versão de chave, TTL configurável, dados sensíveis cifrados,
ausência de QR bruto/OCR bruto, documento vencido tratado à parte de cache
vencido, cache vencido nunca como fallback, invalidação por versão de
mapeamento, alteração manual nunca sobrescrita pelo cache) foram
reconfirmados corretos por leitura de código, sem necessidade de nenhuma
correção adicional além do já descrito nas Tarefas 1-3.

### Tarefa 5 — Correção das 4 suítes de teste antigas

Reescritas para exercitar `App\Rn\VioApiBrClient`/os novos estados
assíncronos, preservando a mesma cobertura temática (sanitização de log,
lock/mutex, concorrência/CAS, estados terminais, ausência de retry
automático, resultado tardio, fallback manual):

- `tests/manual/teste_status_processamento.php`: cenários 3/4 reescritos —
  antes testavam o estado `PROCESSANDO` único do fluxo antigo (nunca mais
  produzido por nenhum código real); agora cobrem uma tentativa RECENTE em
  `PROCESSANDO_LEITURA` (consulta real via GET contra uma porta local
  fechada, sem rede externa, resultando em `ERRO`) e uma tentativa OBSOLETA
  (expirando para `INDETERMINADO` pelo limite de duração máxima, sem nunca
  chegar a consultar). `tests/manual/_caso_status_processamento.php`
  atualizado (comentário).
- `tests/manual/teste_integridade_conclusao_atendimento.php`: itens 9/10
  reescritos para usar `tipo='crlv'` (dispensa JPEG estruturalmente válido —
  `montarImagemCrlv()` não valida assinatura/dimensão, ao contrário do PDF
  de CNH) com fixture real de `crlv.jpg` criada/removida pelo próprio teste;
  item 9a cobre falha de inicialização do `VioApiBrClient` (503), item 9b
  cobre falha de envio por conexão recusada — sem ambiguidade (502, `ERRO`,
  nunca `INDETERMINADO`); item 10 (chave de lock) atualizado para o prefixo
  novo `vio_api_br_validar_{id}_{tipo}`.
- `tests/manual/teste_lock_obter_lock_documento.php`: os 3 corpos de
  subprocesso embutidos (`$corpoVioIndisponivel`/`$corpoLockFalhaAquisicao`/
  `$corpoLockPerdidoAposAquisicao`) reescritos para forçar falha do
  `VioApiBrClient` (nunca mais `VioDecodeClient`); todos os cenários (itens
  1/2/4/5-8/prova negativa) migrados para `tipo='crlv'` com fixture de
  `crlv.jpg`, chave de lock atualizada, mensagens de log atualizadas
  ("(inicializar VioApiBrClient)").
- `tests/manual/teste_sanitizacao_logs_documento_nota.php`: Ponto 1
  (inicialização do cliente) migrado para `VioApiBrClient`/`tipo='crlv'`;
  Ponto 2 (antes cobria `validarCnh()`/`validarCrlv()`, métodos que o fluxo
  novo nunca mais chama dentro de `iniciarProcessamento()`) reescrito para
  cobrir o catch em torno de `DocumentoRn::calcularFingerprintVioApiBr()`
  (ponto mais cedo do fluxo novo que ainda delega a `DocumentoRn`), incluindo
  a "prova negativa" (trecho sanitizado/cru atualizado para o novo contexto
  de log); Pontos 3/4/5 (preencher-manual/NotaController) preservados sem
  alteração (métodos/caminhos intocados pela migração).
- `tests/manual/_caso_iniciar_processamento_vio_indisponivel.php` e
  `tests/manual/_caso_iniciar_processamento_falha_durante_validacao.php`
  (dependências não-versionadas de `teste_integridade_conclusao_
  atendimento.php` — `tests/manual/_*.php` é gitignored — mas presentes no
  worktree e reescritas junto, já que sem isso a suíte principal não teria
  como ser corrigida de forma real): migradas de forçar falha de
  `VioDecodeClient()`/`VIO_AMBIENTE` para forçar falha de
  `VioApiBrClient()`/`VIO_API_BR_BASE_URL`+`VIO_API_BR_API_KEY`, com HMAC do
  fingerprint configurado via env sintética.

Todos os arquivos passaram por `php -l` (sem erro de sintaxe). Nenhuma
suíte foi executada de fato nesta rodada (execução real em banco `qa_`
descartável fica para o `qa-testes`, próxima etapa) — por instrução
explícita, esta rodada não aplica migrations nem faz chamada de rede real.

### O que NÃO foi feito nesta rodada

- Nenhuma migration 017 criada — 015/016 editadas diretamente, conforme
  instrução.
- `public/totem/assets/app.js` não foi tocado (frontend é de outro agente).
- `tests/manual/_qa_db_bootstrap.php` não foi tocado; nenhum arquivo de
  teste novo criado além das correções pontuais dos 4 já listados (mais os
  2 helpers `_caso_iniciar_processamento_*` de que a suíte principal
  depende).
- Nenhuma limpeza de resíduo de `tb_totem` em `udlog_totem`.
- Nenhuma migration aplicada em banco real/compartilhado.
- Nenhuma chamada de rede real, nenhum uso de credencial real, nenhum
  commit/push.

## `/02-testes` — rodada de QA corretiva (2026-09-26)

Escopo fechado pelo orquestrador em 3 tarefas (Tarefa 3: reprodutibilidade em
clone limpo; Tarefa 4: limpeza de resíduo sintético protocolar; Tarefa 9:
execução real de todas as suítes). Zero chamada de rede real, zero uso de
credencial real, zero migration em `udlog_totem` (exceto a limpeza de
resíduo autorizada, que é DML). Nenhum commit/push. Trello não usado.

### Tarefa 3 — Reprodutibilidade em árvore hermética

`tests/manual/_qa_db_bootstrap.php` renomeado (`mv`, não `git mv` — nunca
esteve rastreado) para `tests/manual/qa_db_bootstrap.php`. As 2 únicas
referências existentes no projeto (`tests/manual/teste_vio_api_br_cas_e_cache.php`,
`tests/manual/teste_vio_api_br_migrations.php`) atualizadas de
`__DIR__ . '/_qa_db_bootstrap.php'` para `__DIR__ . '/qa_db_bootstrap.php'`.
`git check-ignore -v tests/manual/qa_db_bootstrap.php` confirmado sem
nenhuma saída (exit 1) — arquivo não é mais ignorado por `.gitignore:37`.

Prova de reprodutibilidade: `git add` temporário de todos os arquivos desta
demanda (backend + as 3 suítes novas + as 4 reescritas + `qa_db_bootstrap.php`
+ migrations 015/016 + este handoff + `ia_development_state.md`, EXCLUINDO
explicitamente `.claude/skills/`, `docs/indexTotem.html`, `tests/nf_teste/`
— autorizações/decisões de outra demanda) → `git write-tree` (hash
`ea65d55d4d3b2aa346d1d18cb537e1049aa6e4e2`, sem nenhum commit real) →
`git archive <tree>` extraído em diretório temporário fora do repositório
(scratchpad da sessão) → `git reset` imediato (confirmado por `git status
--porcelain` idêntico antes/depois, mesma contagem de arquivos M/??) →
`vendor/`/`.env` copiados por cima (gitignorados, infraestrutura de
execução, não fazem parte da prova). Confirmado por listagem: nenhum
helper `_*.php` NOVO desta demanda presente na árvore (só os fixtures
`_caso_*.php`/`_fixtures_*.php` pré-existentes já rastreados, mesmo padrão
de demandas anteriores); `qa_db_bootstrap.php` presente. Execução das 3
suítes novas DENTRO da árvore hermética: **77/77, 46/46, 29/29**, zero
`Failed opening required`, zero erro de `require`/`include` ausente.
Diretório hermético removido ao final.

**Achado não corrigido (fora do escopo desta rodada, mas do mesmo tipo)**:
`tests/manual/teste_integridade_conclusao_atendimento.php` (suíte
RASTREADA, não uma das 3 novas) depende via subprocesso
(`dispararSequencial`) de 2 helpers NUNCA versionados
(`_caso_iniciar_processamento_vio_indisponivel.php`,
`_caso_iniciar_processamento_falha_durante_validacao.php`, reescritos na
rodada corretiva de backend anterior mas nunca `git add -f`). A Tarefa 3
pediu confirmação hermética explicitamente só para as 3 suítes novas desta
demanda — este achado é registrado como pendência de reprodutibilidade,
não corrigido (fora do escopo desta tarefa específica).

### Tarefa 4 — Limpeza de resíduo sintético (protocolo seguido à risca)

1. **Consulta somente leitura**: confirmado por `SELECT * FROM tb_totem
   WHERE codigo IN ('TESTE_VIO','TESTE_REBAIXA','TESTE_REC_DOC')` —
   3 linhas (`id_totem` 1847/1848/1850), nomes sintéticos ("Totem Teste
   VIO"/"Totem Teste Rebaixa"/"Totem Teste Recebimento Doc"), sem CPF/nome
   de motorista/cliente real (a tabela `tb_totem` não tem esses campos).
2. **Levantamento de dependências**: `INFORMATION_SCHEMA.COLUMNS` consultado
   para todas as tabelas com coluna `id_totem`/`id_atendimento`
   (`tb_atendimento`, `tb_atendimento_nota`, `tb_fila_envio`,
   `tb_lgpd_aceite`, `tb_ordem_coleta_pendente_baixa`, `tb_rate_limit_ocr`,
   `tb_rate_limit_vio_status`). `tb_vio_cache_*`/`tb_vio_api_cache_*`
   confirmadas SEM coluna `id_totem`/`id_atendimento` (chaveadas só por
   fingerprint), portanto fora do escopo de FK desta limpeza.
3. **Confirmação de ausência de dado real**: `SELECT` em todas as 7 tabelas
   acima filtrando pelos 3 `id_totem` retornou **0 linhas em todas** — os 3
   totens sintéticos nunca chegaram a ter nenhum atendimento/nota/fila/
   aceite/rate-limit associado. Nenhum dado pessoal real em nenhuma
   dependência.
4. Transação (`beginTransaction`/`commit`) executada.
5. `DELETE FROM tb_totem WHERE codigo IN ('TESTE_VIO','TESTE_REBAIXA','TESTE_REC_DOC')`
   — lista exata, sem `LIKE`/glob/`TRUNCATE`/condição ampla. `rowCount()`
   verificado (`=== 3`) ANTES do commit — se fosse diferente de 3, o código
   fazia `rollBack()` automático (proteção adicional não usada, pois o
   valor bateu exato).
6. Contagens antes/depois: `tb_totem` total 12→9, matching (`codigo IN
   (...)`) 3→0; as 7 tabelas de dependência: 0→0 em todas (nenhuma mudança,
   pois já não tinham nenhuma linha relacionada). Reconfirmado depois com
   `codigo LIKE 'TESTE_VIO%' OR 'TESTE_REBAIXA%' OR 'TESTE_REC_DOC%'`
   (cobre também o novo padrão com sufixo aleatório introduzido no item 8)
   → 0 linhas.
7. Nenhum registro fora do esperado apareceu — nenhum rollback necessário.
8. Os 3 scripts corrigidos para nunca mais colidir/deixar resíduo:
   - `tests/manual/teste_vio_decode.php`: `codigo` agora
     `TESTE_VIO_{sufixo aleatório de 4 bytes}` (e
     `TESTE_VIO_OUTRO_{mesmo sufixo}` para o segundo totem/IDOR);
     `register_shutdown_function()` registrado logo no início do script
     (antes de qualquer `INSERT`), captura `$idTotemGlobal`/
     `$idTotemInvasorGlobal`/`$idsAtendimentoGlobal` por referência e limpa
     incondicionalmente ao final da execução do processo PHP — roda mesmo
     se uma assertiva/exceção interromper o script no meio.
   - `tests/manual/teste_rebaixamento_manual.php`: mesmo padrão,
     `codigo` = `TESTE_REBAIXA_{sufixo}`, mesmo mecanismo de
     `register_shutdown_function()`.
   - `tests/manual/teste_fluxo_recebimento_documentos.php`: mesmo padrão,
     `codigo` = `TESTE_REC_DOC_{sufixo}`, teardown via
     `register_shutdown_function()` cobre também a pasta física de
     documentos criada em `STORAGE_PATH` (JPEGs de teste).
   - `register_shutdown_function()` escolhido (em vez de só `finally`)
     porque também cobre `exit()`/erro fatal não capturável por
     `try/catch` — mais forte que o pedido mínimo de "em finally".

### Tarefa 9 — Execução real de todas as suítes

**3 suítes novas (auto-contidas, banco `qa_` descartável, dropado ao
final)**: `teste_vio_api_br_client.php` **77/77**,
`teste_vio_api_br_cas_e_cache.php` **46/46**,
`teste_vio_api_br_migrations.php` **29/29** — idênticos à rodada anterior,
reconfirmados por execução direta E dentro da árvore hermética (Tarefa 3).
Migrations 014 (schema divergente) e 015/016 confirmadas idempotentes
(2ª execução sem erro, mesmos índices), bancos `qa_vio_api_br_*` dropados
ao final de cada execução (confirmado no próprio stdout).

**4 suítes reescritas, executadas de verdade pela 1ª vez — ACHADO
BLOQUEANTE, NÃO corrigido por instrução explícita ("se algo falhar, não
corrija você mesmo")**:

| Suíte | Resultado | Causa raiz |
|---|---|---|
| `teste_status_processamento.php` | Não executou (Fatal error, 0 asserções rodadas) | `Duplicate entry 'TESTE_STATUS_PROC' for key 'codigo'` — resíduo PRÉ-EXISTENTE (`id_totem=2053`, `id_atendimento=9964`, `criado_em=2026-09-25 17:29:21`, de uma execução anterior sem teardown seguro), NÃO autorizado para remoção pela Tarefa 4 (só `TESTE_VIO`/`TESTE_REBAIXA`/`TESTE_REC_DOC`) |
| `teste_integridade_conclusao_atendimento.php` | 37/43 (6 falhas: Itens 9a/9b completos) | `SQLSTATE[42S22]: Column not found: 'crlv_vio_api_id'` em `AtendimentoDao::iniciarEnvioVioApiBr()` |
| `teste_lock_obter_lock_documento.php` | 15/25 (10 falhas) | mesma causa — coluna/schema novo ausente quebra o fluxo real de `iniciarProcessamento()` antes do ponto esperado pelo teste |
| `teste_sanitizacao_logs_documento_nota.php` | 27/29 (2 falhas: Ponto 1) | mesma causa — `iniciarEnvioVioApiBr()` falha com `PDOException` não tratada em vez do `RuntimeException` esperado de construção do `VioApiBrClient` |

**Causa raiz confirmada por leitura direta do schema** (`SHOW COLUMNS FROM
tb_atendimento`, `SHOW TABLES LIKE 'tb_vio_api%'` em `udlog_totem`): as
migrations 015/016 **nunca foram aplicadas ao banco de dev real**
(`cnh_vio_api_id`/`crlv_vio_api_id`/demais colunas novas ausentes,
`tb_vio_api_cache_cnh`/`tb_vio_api_cache_crlv` inexistentes) — consistente
com o que os 2 handoffs anteriores já registravam explicitamente ("Nenhuma
migration aplicada em banco real"). As 4 suítes reescritas exercitam
código de produção REAL (`DocumentoController::iniciarProcessamento()` via
`AtendimentoDao::iniciarEnvioVioApiBr()`) contra `udlog_totem`, então
FALHAM estruturalmente até que pelo menos a migration 015 seja aplicada
nesse banco — isso é distinto e não coberto pela migration em banco `qa_`
descartável (Tarefa 9 também pedia isso, já feito e aprovado acima, mas
não substitui a necessidade de aplicar em `udlog_totem` para estas 4
suítes). **Decisão pendente do usuário/orquestrador**: autorizar aplicar
migration 015 (no mínimo — 016 só é necessária se algum cenário futuro
exercitar cache real) em `udlog_totem`, e decidir o destino do resíduo
`TESTE_STATUS_PROC` (não removido, fora da autorização desta rodada).

**Regressão ampla — 100% aprovada após atualização de nomenclatura de
etapa** (`rec_cnh_frente`/`rec_cnh_verso` → `rec_cnh` unificada):

- `teste_e2e_recebimento_expedicao_mock.php` **30/30** (atualizado: upload
  da frente sozinho agora espera bloqueio do gate `upload_cnh`
  ("documentos pendentes"), só avança após os 2 lados).
- `teste_idor_salvar_etapa_manual.php` **15/15** (atualizado: 2 menções a
  `rec_cnh_frente` viram `rec_cnh`).
- `teste_salvar_etapa_cliente_manual.php` **3/3** (atualizado: mesma
  troca de nomenclatura, docblock atualizado).
- `teste_fluxo_recebimento_documentos.php` **REESCRITO por completo (13
  testes, 13/13)** — a suíte antiga testava a transição de etapa entre
  `rec_cnh_frente`/`rec_cnh_verso` (2 etapas), que não existe mais. Nova
  suíte cobre: upload da frente sozinho NÃO libera a transição (gate
  bloqueado, etapa permanece `rec_cnh`), upload do verso completa o par e
  libera `rec_cnh → rec_crlv` numa única transição, resto do fluxo
  (`rec_crlv → rec_aguarde_documentos → rec_confirmacao`, preenchimento
  manual do CRLV preservando a CNH já aprovada) preservado sem redução de
  cobertura. Também corrigido nesta mesma rodada (Tarefa 4, item 8) para
  sufixo aleatório de `codigo` + teardown via
  `register_shutdown_function()`.
- Talent (9 suítes): `teste_talent_anexos_pdf.php` 19/19,
  `teste_talent_client_parsing.php` 9/9, `teste_talent_idempotencia.php`
  23/23, `teste_talent_idor_finalizar.php` 10/10,
  `teste_talent_log_sanitizado.php` 34/34, `teste_talent_payload.php`
  45/45, `teste_talent_rntc_tipo_crlv.php` 23/23,
  `teste_talent_trava_doctos_pendente.php` 18/18,
  `teste_talent_uf_crlv.php` 11/11 — todas 100% aprovadas, zero regressão.
- `teste_avancar_etapa_expedicao.php` 10/10,
  `teste_concorrencia_finalizar_checkin.php` 8/8,
  `teste_concorrencia_numero_nota_duplicado.php` 5/5,
  `teste_concorrencia_processamento_vio.php` 16/16,
  `teste_impressao_idor.php` 12/12, `teste_lgpd_migration_014.php` 28/28,
  `teste_numero_nota_validacao_tamanho.php` 32/32,
  `teste_ordem_coleta_pendente_baixa.php` 14/14,
  `teste_rate_limit_identificar_cliente_pdo.php` 24/24,
  `teste_validacao_jpeg_seguro.php` 22/22 (controles obrigatórios),
  `teste_vio_decode_matriz_tipos_campos.php` 622/622,
  `teste_vio_decode_robustez.php` 80/80 — todas 100% aprovadas.
  `teste_preparacao_producao_checkin.php` é script de preparação (sem
  contagem PASSOU/FALHOU), executado sem erro; deixa deliberadamente um
  atendimento sintético em `udlog_totem` (`id_atendimento=10321`,
  "aguardando pedido de exclusão") — NÃO tocado, fora do escopo de
  limpeza autorizado pela Tarefa 4. `teste_vio_decode_wire_format.php`
  pulado (depende de variáveis de ambiente com arquivos oficiais baixados
  manualmente, ausentes neste ambiente — comportamento esperado, "PULADO"
  documentado no próprio script). `teste_concorrencia_real_iniciar_processamento.php`
  **NÃO executado** — chama rede real, fora do escopo de qualquer rodada
  de QA sem autorização explícita de chamada paga.

**2 achados pré-existentes, fora do escopo desta demanda, encontrados
durante a regressão ampla e NÃO corrigidos**:

- `teste_consulta_ordem_coleta.php`: 11/17 (6 falhas) — mesma causa já
  registrada em `ia_development_state.md` desde 2026-09-17 (divergência de
  fixture de dados no banco externo `udlogo59_db_gestao_coletas`, não é
  código/config desta aplicação). 1 falha adicional nesta execução
  ("banco externo indisponível: retorna erro genérico") não estava listada
  na pendência de 2026-09-17 — não investigada a fundo por estar
  completamente fora do escopo desta demanda (nenhum arquivo de
  `OrdemColetaClient`/`OrdemColetaDao` foi tocado por `migracao-vio-api-br-com-cache`).
- `teste_lgpd_aceite_backend_seguranca.php`: falha fatal
  (`require_once` de `tests/manual/_fixtures_lgpd.php`) — arquivo referenciado
  nunca existiu neste worktree nem tem histórico no git (`git log --all`
  vazio para o caminho). Suíte rastreada (`git ls-files` confirma) e
  intocada por esta demanda — mesma classe de achado de reprodutibilidade
  já registrada para outra demanda (`sanitizacao-excecoes-lock-documentos`),
  mas em teste de uma feature totalmente diferente (LGPD), fora do escopo
  de correção desta rodada.

### Confirmação de zero resíduo remanescente das 3 correções da Tarefa 4

Verificação final: `SELECT codigo FROM tb_totem` lista 9 registros —
`RECEPCAO-01` (real) + 8 sintéticos de outras suítes/execuções desta
rodada, todos com sufixo aleatório de execução própria de suas respectivas
suítes (removidos por elas mesmas ao final, exceto `TESTE_STATUS_PROC`,
único remanescente, não autorizado para remoção — ver Tarefa 9 acima).

### Veredito

**PRECISA DE AJUSTE** — 2 achados bloqueantes que dependem de decisão do
usuário/orquestrador, não de correção técnica do `qa-testes` (que foi
instruído a não corrigir código de produção nem aplicar migration em
`udlog_totem`): (1) migrations 015/016 nunca aplicadas ao banco de dev
real, impedindo validação end-to-end das 4 suítes reescritas contra código
de produção real; (2) resíduo `TESTE_STATUS_PROC` pré-existente, fora da
autorização de limpeza desta rodada. Tarefas 3 e 4 (reprodutibilidade
hermética das 3 suítes novas + protocolo de limpeza de resíduo autorizado)
100% concluídas e confirmadas. Regressão ampla 100% aprovada após
atualização de nomenclatura de etapa nos 4 arquivos de teste afetados.

### `/02-testes` — rodada corretiva de banco `qa_` próprio para as 4 suítes reescritas (2026-09-26)

Resolve o "ACHADO BLOQUEANTE NOVO" da seção anterior SEM tocar em
`udlog_totem`: as 4 suítes reescritas (`teste_status_processamento.php`,
`teste_integridade_conclusao_atendimento.php`,
`teste_lock_obter_lock_documento.php`,
`teste_sanitizacao_logs_documento_nota.php`) passam a rodar contra um
banco `qa_`-prefixado DESCARTÁVEL PRÓPRIO (mesmo `tests/manual/qa_db_bootstrap.php`
já usado pelas 3 suítes novas), criado do zero (schema.sql + migrations
014/015/016, com o mesmo workaround já documentado de `qaDbCorrigirEnumsMigration015()`
para a condição invertida da migration 015) e dropado ao final de cada
execução.

**Achado técnico durante a correção** (registrado para conhecimento, não
bloqueante): `Dotenv::createImmutable()->load()` em modo imutável, quando
uma variável já está definida via `getenv()`/ambiente do SO (herdada de um
`putenv()` do processo pai via `exec()`/`proc_open()`, sem o pai também
setar `$_ENV`, que não se propaga entre processos), **nunca promove
sozinho esse valor para dentro de `$_ENV`** — apenas pula a chave (por
considerá-la "já definida"), deixando `$_ENV[chave]` UNSET no
subprocesso. Como `Util\Conexao` e `App\Rn\VioApiBrClient` leem `$_ENV`
diretamente (nunca `getenv()`), isso significa que, sem uma ponte
explícita, qualquer subprocesso gerado por essas suítes conectaria sempre
no banco real do `.env` (nunca no banco `qa_` descartável do processo
pai), mesmo com `putenv('DB_NAME=...')` no pai. Confirmado por teste
isolado (script descartável, não versionado). Corrigido com um trecho de
ponte (copiar `getenv()` para `$_ENV` quando a chave ainda não existe em
`$_ENV`) executado em CADA subprocesso, ANTES de `Dotenv::createImmutable()->load()`
— disponível como `qaDbTrechoPonteEnvSubprocesso()` em
`tests/manual/qa_db_bootstrap.php`, usado tanto pelos subprocessos
inlinados quanto pelos arquivos `tests/manual/_caso_*.php` compartilhados
(`_caso_status_processamento.php`, `_caso_concluir_digitalizacao.php`,
`_caso_nota_definir_numero.php` — edição aditiva/no-op quando nenhum
processo pai fez `putenv('DB_NAME=...')`, confirmado sem regressão em
`teste_e2e_recebimento_expedicao_mock.php`/`teste_numero_nota_validacao_tamanho.php`/
`teste_rate_limit_identificar_cliente_pdo.php`/`teste_concorrencia_numero_nota_duplicado.php`,
que continuam usando esses mesmos arquivos contra `udlog_totem` sem
alteração de comportamento).

`qa_db_bootstrap.php` ganhou 3 novos helpers compartilhados:
`qaDbCorrigirEnumsMigration015()` (extraído do que já existia em
`teste_vio_api_br_cas_e_cache.php`), `qaGerarScriptTemporario()` (extraído
do padrão já usado em `teste_lock_obter_lock_documento.php`/
`teste_sanitizacao_logs_documento_nota.php`) e
`qaDbTrechoPonteEnvSubprocesso()` (novo, ver acima).

`tb_empresa` não vem semeada em `schema.sql` (as seeds de
`sql/migrations/008_tb_empresa_totem_vinculo.sql` só existem no banco de
dev real) — `teste_integridade_conclusao_atendimento.php` e
`teste_sanitizacao_logs_documento_nota.php` (únicas que chamam
`talentCriarTotemComEmpresa()`) passam a inserir sua própria fixture
sintética de `tb_empresa` dentro do banco `qa_` descartável, em vez de
assumir `id_empresa=1` fixo.

`teste_integridade_conclusao_atendimento.php` tinha, além da dependência
de banco, uma dependência de reprodutibilidade adicional já registrada
para as outras 2 suítes na rodada de 2026-09-25: 6 subprocessos
`tests/manual/_caso_*.php` NUNCA versionados (`_caso_cancelar.php`,
`_caso_bloquear_excesso.php`, `_caso_concluir_digitalizacao_cas_direto.php`,
`_caso_iniciar_processamento_vio_indisponivel.php`,
`_caso_iniciar_processamento_falha_durante_validacao.php`,
`_caso_nota_pdo_falha.php` — confirmado por `git ls-files`). Resolvido com
o mesmo padrão (nowdoc inlinado + `qaGerarScriptTemporario()`), sem alterar
nenhuma asserção/cobertura existente.

**Resultado (7 suítes desta demanda, banco `qa_` descartável cada uma,
dropado ao final)**:

| Suíte | Resultado |
|---|---|
| `teste_vio_api_br_client.php` | 77/77 |
| `teste_vio_api_br_cas_e_cache.php` | 46/46 |
| `teste_vio_api_br_migrations.php` | 29/29 |
| `teste_status_processamento.php` | 13/13 |
| `teste_integridade_conclusao_atendimento.php` | 43/43 |
| `teste_lock_obter_lock_documento.php` | 25/25 |
| `teste_sanitizacao_logs_documento_nota.php` | 29/29 |

Árvore hermética reconfirmada para as 7 (não só as 3 de antes): `git
worktree add --detach` a partir do `HEAD` committed + cópia manual, por
cima, do conjunto atual de arquivos modificados/novos desta demanda
(simulando o estado pós-commit, já que nenhum commit foi feito nesta
rodada) + `vendor/`/`.env` locais (não versionados, esperados no
ambiente) — as 7 suítes reproduzem os MESMOS resultados acima sem tocar
`udlog_totem` e sem depender de nenhum arquivo `tests/manual/_*.php` fora
dos que já são rastreados pelo git (`_fixtures_talent.php`,
`_fixtures_identificar_cliente.php`, `_caso_concluir_digitalizacao.php`,
`_caso_nota_definir_numero.php`, `_caso_status_processamento.php`).
Worktree temporário removido ao final (`git worktree remove --force`).

`TESTE_STATUS_PROC` (resíduo em `udlog_totem` de uma execução ANTERIOR a
esta mudança) deixa de ser bloqueio para nenhuma suíte, já que nenhuma
delas toca mais `udlog_totem` — permanece como observação/resíduo órfão
pré-existente, fora do escopo desta correção (remoção não autorizada
nesta rodada).

**Regressão ampla re-executada e 100% aprovada** (33 suítes adicionais,
fora as 7 acima): Talent (9 suítes), rate limit, IDOR (`teste_idor_salvar_etapa_manual.php`,
`teste_impressao_idor.php`, `teste_talent_idor_finalizar.php`),
concorrência (`teste_concorrencia_finalizar_checkin.php`,
`teste_concorrencia_numero_nota_duplicado.php`,
`teste_concorrencia_processamento_vio.php`), JPEG seguro (22/22 controles
obrigatórios), VIO robustez/matriz (`teste_vio_decode_matriz_tipos_campos.php`
622/622, `teste_vio_decode_robustez.php` 80/80), LGPD (`teste_lgpd_migration_014.php`
28/28), `teste_e2e_recebimento_expedicao_mock.php` 30/30,
`teste_fluxo_recebimento_documentos.php` 13/13,
`teste_salvar_etapa_cliente_manual.php` 3/3,
`teste_avancar_etapa_expedicao.php`, `teste_ordem_coleta_pendente_baixa.php`,
`teste_identificar_cliente.php`, `teste_numero_nota_validacao_tamanho.php`,
`teste_talent_rntc_tipo_crlv.php`, `teste_talent_uf_crlv.php` — sem
regressão em nenhuma. `teste_preparacao_producao_checkin.php` (script sem
contagem PASSOU/FALHOU) executado sem erro. `teste_vio_decode_wire_format.php`
pulado (depende de variáveis de ambiente com arquivos baixados
manualmente, ausentes neste ambiente — comportamento esperado).

2 achados pré-existentes fora do escopo desta demanda, reconfirmados, NÃO
corrigidos (arquivos intocados por esta demanda, sem qualquer dependência
das mudanças desta rodada): `teste_consulta_ordem_coleta.php` (11/17, já
registrado desde 2026-09-17) e `teste_lgpd_aceite_backend_seguranca.php`
(falha fatal por `_fixtures_lgpd.php` nunca versionado, mesma classe de
achado de outra demanda). Adicionalmente, nesta rodada,
`teste_concorrencia_real_iniciar_processamento.php` foi executado (ao
contrário da rodada anterior, que evitou por suposição de chamada de rede
real) e retornou 0/2 — investigação por leitura de código confirma que a
falha é `calcularFingerprintVioApiBr()` lançando por ausência de
`VIO_API_BR_CACHE_HMAC_VERSION`/`VIO_API_BR_CACHE_HMAC_KEY_V1` no `.env`
real deste ambiente (nenhuma chamada de rede chegou a ser feita) — achado
pré-existente de configuração de ambiente, fora do escopo desta correção
(arquivo intocado por esta demanda, não deve ser alterado sem autorização
para tocar `.env` real).

### Veredito (rodada corretiva de 2026-09-26)

**APROVADO** para o escopo desta correção: as 4 suítes reescritas agora
rodam de forma 100% reprodutível e determinística contra um banco `qa_`
descartável próprio, sem depender de `udlog_totem` nem de migration
aplicada em banco real, e sem nenhum arquivo `_*.php` gitignorado. As 7
suítes desta demanda somam 262/262 testes aprovados. Zero chamada
externa, zero credencial real, zero banco `qa_` remanescente ao final,
`git diff --check` limpo. Os 2 bloqueios da rodada anterior (migrations
015/016 nunca aplicadas em `udlog_totem`; resíduo `TESTE_STATUS_PROC`)
deixam de bloquear `/02-testes` desta demanda — seguem como pendência
separada (aplicar em `udlog_totem` é decisão de produto/infra, fora do
escopo de testes) e observação órfã não-bloqueante, respectivamente.

## `/02-testes` independente — veredito final (2026-09-26)

Etapa executada com 4 revisores independentes, cada um em instância nova,
sem participação na implementação nem nas rodadas anteriores de
`/02-testes`. Só documentação consolidada — nenhum código foi alterado,
nenhum teste foi reexecutado, nenhum banco (`udlog_totem` ou `qa_`) foi
tocado nesta rodada de registro.

### Revisores e escopo

- **frontend/UX** — 8 itens dos fluxos de captura de CNH (frente/verso
  unificados, ordem de leitura de QR, estados intermediários de espera,
  mensagens de erro genéricas). **8/8 CONFIRMADOS.**
- **security-especialista** — 6 itens, incluindo 2 provas negativas
  dedicadas (ausência de vazamento de dado sensível/credencial em log e
  comportamento correto de cache vencido). **6/6 CONFIRMADOS.**
- **backend-especialista-revisor** — 6 provas negativas controladas,
  executadas em cópia isolada do repositório (nunca no worktree
  principal): duplicação de POST à vio.api.br, bypass do CAS de envio,
  gravação de resultado com origem incorreta, gravação de resultado
  tardio (atendimento já finalizado/cancelado), ausência do helper
  obrigatório de ponte de ambiente em subprocesso de teste, e
  reaproveitamento de RNTC/tipo de veículo só a partir de cache
  efetivamente válido. **6/6 CONFIRMADAS** — worktree principal
  reconfirmado intocado hash-a-hash ao final.
- **qa-testes** — 9 itens, incluindo execução real das 7 suítes desta
  demanda em banco `qa_` próprio (**262/262**), em árvore hermética
  independente (clone/worktree isolado do commit atual), confirmação por
  execução própria de que as migrations 015/016 são idempotentes,
  confirmação de zero resíduo novo em `udlog_totem`, e regressão ampla
  100% aprovada. **9/9 CONFIRMADOS.**

### Confirmação explícita de zero operação real

Zero chamada real à `vio.api.br`/Serpro/Talent, zero documento real, zero
uso de API Key real, zero impressão física, zero acesso a
Hostgator/produção, zero migration aplicada em banco real, zero uso do
Trello, zero alteração de código durante `/02-testes` (todos os 4
revisores confirmaram `git status`/hash idênticos ao início de suas
respectivas tarefas), zero commit/push.

### Pendências que seguem em aberto para `/03-revisao` (não-bloqueantes para este veredito)

1. Decisão do usuário sobre quando/se aplicar as migrations 015/016 em
   `udlog_totem` (banco de dev real).
2. Decisão do usuário sobre remover o resíduo `TESTE_STATUS_PROC`
   (`id_totem` correspondente) em `udlog_totem`.
3. Achados pré-existentes não relacionados a esta demanda, reconfirmados
   sem correção: `teste_consulta_ordem_coleta.php` (fixture externa
   desatualizada) e `teste_lgpd_aceite_backend_seguranca.php`
   (`_fixtures_lgpd.php` nunca versionado, de outra demanda).

### VEREDITO FINAL: APROVADO.

Pronta para `/03-revisao`.

## Convergência do ambiente de desenvolvimento — migrations 015/016 aplicadas em `udlog_totem` (2026-09-26)

Autorização explícita do usuário, EXCLUSIVA para o banco de DEV LOCAL
`udlog_totem` — nunca Hostgator/produção, nunca qualquer outro banco
(`qa013_*`/`qa_iso2`/etc). Nenhuma chamada de rede real (`vio.api.br`/
Serpro/Talent), nenhum documento real, nenhuma credencial exibida/copiada/
logada. Nenhum commit/push. Trello não usado.

### Preflight (item a item)

1. **`SELECT DATABASE()`** confirmado `udlog_totem` — banco ativo correto,
   confirmado antes de qualquer escrita.
2. **Presença das estruturas esperadas** confirmada por
   `INFORMATION_SCHEMA.TABLES`: `tb_totem`, `tb_atendimento`, `tb_cliente`,
   `tb_vio_cache_cnh`, `tb_vio_cache_crlv`, `tb_rate_limit_vio_status`
   presentes; `tb_vio_api_cache_cnh`/`tb_vio_api_cache_crlv` corretamente
   AUSENTES antes da aplicação (esperado — são criadas pela migration 016).
   `cnh_status_processamento`/`crlv_status_processamento` no estado
   ANTERIOR à migration 015 (`ENUM('PENDENTE','PROCESSANDO','CONCLUIDO','ERRO')`,
   sem `ENVIANDO`/`PROCESSANDO_LEITURA`/`PROCESSANDO_COMPARACAO`/
   `INDETERMINADO`); `cnh_origem_validacao`/`crlv_origem_validacao` sem
   `VIO_CACHE`/`VIO_API_BR` ainda — nenhuma divergência estrutural
   inesperada em relação ao que o projeto documenta; nenhum bloqueio
   necessário.
3. **Estruturas/contagens registradas ANTES** (via `SHOW CREATE TABLE`/
   `SELECT COUNT(*)`):
   - `tb_totem`: 9 linhas.
   - `tb_atendimento`: 127 linhas.
   - `tb_cliente`: 38 linhas.
   - `tb_vio_cache_cnh`/`tb_vio_cache_crlv` (cache antigo, não afetado):
     0/0 linhas.
   - `tb_vio_api_cache_cnh`/`tb_vio_api_cache_crlv`: AUSENTES (esperado).
   - Resíduo pré-existente `TESTE_STATUS_PROC` em `tb_totem` confirmado
     ainda presente (1 linha, `LIKE 'TESTE_STATUS_PROC%'`) — não tocado
     nesta rodada, fora do escopo desta tarefa.
4. **Backup real gerado** via `mysqldump.exe --defaults-extra-file=<arquivo
   temporário com credenciais, apagado imediatamente após uso>
   --routines --triggers --single-transaction udlog_totem`, salvo em
   `<scratchpad da sessão>\udlog_totem_backup_20260926_133207.sql`
   (94315 bytes, 461 linhas, 12 `CREATE TABLE` confirmados por contagem —
   fora do repositório git, nunca commitado). Suficiente para restaurar o
   schema afetado.
5. **Nenhuma credencial exibida/copiada/logada** em nenhum momento — a
   conexão de leitura/preflight usou `Util\Bootstrap::conectar()`/
   `Util\Conexao` (mesmo caminho que o projeto já usa), e o backup usou um
   arquivo de opções `--defaults-extra-file` temporário com permissão
   restrita, apagado imediatamente após a execução do `mysqldump`. Nenhum
   valor de `DB_USER`/`DB_PASS`/`DB_HOST` apareceu em nenhuma saída
   registrada nesta sessão.

### Aplicação

6. **Migration 015 aplicada** (1ª execução) — 49 statements executados sem
   erro.
7. **Migration 016 aplicada** (1ª execução) — 3 statements executados sem
   erro (`CREATE TABLE IF NOT EXISTS` × 2, idempotente por natureza).
8. **Reaplicação de ambas (2ª execução)** para comprovar idempotência real:
   - Migration 015 reaplicada (49 statements, todos resolvendo para
     no-op `SELECT 1` internamente pelas condições `COLUMN_TYPE NOT LIKE`
     já satisfeitas — sem erro, sem duplicação).
   - Migration 016 reaplicada (3 statements, `CREATE TABLE IF NOT EXISTS`
     sem-op — sem erro).
   - **Achado técnico registrado durante a correção do script de
     aplicação** (não um problema da migration em si): a 1ª tentativa de
     reaplicar a migration 015 falhou com
     `SQLSTATE[HY000]: General error: 2014 Cannot execute queries while
     other unbuffered queries are active` porque o script auxiliar usado
     para aplicar as migrations (`PDO::exec()` por statement) não é
     apropriado para o padrão `PREPARE stmt FROM @ddl; EXECUTE stmt;
     DEALLOCATE PREPARE stmt;` quando `@ddl` já convergiu para
     `'SELECT 1'` (um SELECT real, que `exec()` não trata corretamente).
     Corrigido usando `PDO::query()` + `closeCursor()` em vez de `exec()`
     para cada statement — nenhuma alteração nos arquivos de migration em
     si, só no script de aplicação (ferramenta de convergência, fora do
     repositório).
9. **Colunas/ENUMs/tabelas/índices resultantes confirmados** por
   `SHOW CREATE TABLE`, comparados byte-a-byte entre a 1ª e a 2ª aplicação
   (`diff` = idêntico nos 3):
   - `tb_atendimento`: `cnh_status_processamento`/
     `crlv_status_processamento` agora
     `ENUM('PENDENTE','PROCESSANDO','ENVIANDO','PROCESSANDO_LEITURA','PROCESSANDO_COMPARACAO','CONCLUIDO','ERRO','INDETERMINADO')`;
     `cnh_origem_validacao`/`crlv_origem_validacao` agora
     `ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO','VIO_CACHE','VIO_API_BR')`;
     colunas novas presentes e com o tipo exato especificado na migration
     015: `cnh_vio_api_id`/`crlv_vio_api_id` (`varchar(128)`),
     `cnh_vio_api_enviado_em`/`crlv_vio_api_enviado_em` (`datetime`),
     `cnh_vio_api_fingerprint`/`crlv_vio_api_fingerprint` (`char(64)`),
     `cnh_vio_api_fingerprint_versao`/`crlv_vio_api_fingerprint_versao`
     (`tinyint(3) unsigned`).
   - `tb_vio_api_cache_cnh`/`tb_vio_api_cache_crlv`: criadas com schema
     idêntico ao especificado na migration 016 (colunas, `ENUM`s,
     `UNIQUE KEY`/índices secundários conferidos um a um).
10. **Registros existentes preservados** — contagens ANTES vs. DEPOIS
    (2ª aplicação) idênticas: `tb_totem` 9→9, `tb_atendimento` 127→127,
    `tb_cliente` 38→38, `tb_vio_cache_cnh`/`tb_vio_cache_crlv` 0→0/0→0
    (cache antigo intocado). `tb_vio_api_cache_cnh`/
    `tb_vio_api_cache_crlv` AUSENTE→0 linhas (tabelas novas, vazias, como
    esperado — nenhuma linha pré-existente poderia ter sido perdida).
11. **Ausência de `DROP`/`TRUNCATE`/perda de dado confirmada** — nenhuma
    das migrations contém `DROP`/`TRUNCATE`/`DELETE` (confirmado por
    leitura prévia do SQL antes de executar); nenhuma linha das 3 tabelas
    monitoradas mudou; nenhum rollback foi necessário.
12. **Testes específicos** — as 3 suítes desta demanda que rodam contra
    banco `qa_` próprio (`teste_vio_api_br_client.php`,
    `teste_vio_api_br_cas_e_cache.php`, `teste_vio_api_br_migrations.php`)
    não precisam mudar (confirmado por leitura de código:
    `teste_vio_api_br_migrations.php` inclusive afirma explicitamente
    "Confirmado SELECT DATABASE() = banco qa_ recem-criado (nunca
    udlog_totem)" e "Banco de teste nunca e udlog_totem" como asserções
    próprias) — não foram reexecutadas nesta rodada por não terem sido
    alteradas. Confirmado por leitura de código que NENHUMA suíte desta
    demanda depende especificamente de `udlog_totem` já migrado (as 4
    suítes antes bloqueadas por isso já foram migradas para banco `qa_`
    próprio na rodada corretiva de `/02-testes` de 2026-09-26, decisão não
    revertida por esta tarefa) — portanto nenhuma execução adicional foi
    necessária/pertinente para satisfazer este item.

### Resultado

Migrations 015 e 016 aplicadas com sucesso em `udlog_totem`, idempotência
comprovada por reaplicação real, zero perda/alteração de dado existente,
zero chamada de rede, zero exposição de credencial. Resíduo
`TESTE_STATUS_PROC` permanece intocado (fora do escopo desta tarefa,
remoção não autorizada aqui). Backup disponível fora do repositório para
restauração, caso necessário.

## Fase 3 — bateria de testes pós-convergência (2026-09-26, resumo consolidado)

Execução realizada por outro agente/rodada, fora do escopo desta tarefa de
limpeza. Resultado consolidado, conforme fornecido ao orquestrador (sem
detalhamento por suíte disponível para este agente — nenhum dado adicional
inventado além do que segue):

| Métrica | Resultado |
|---|---|
| Suítes executadas | 29 |
| Asserções | 1.350 / 1.350 |
| Falhas | 0 |

Rodam contra bancos `qa_` próprios (efêmeros, criados/derrubados pela
própria suíte) — independentes desta limpeza de `udlog_totem` (banco de
DEV LOCAL), conforme já registrado nas seções anteriores deste handoff.

## Limpeza do resíduo `TESTE_STATUS_PROC` em `udlog_totem` (2026-09-26)

Autorização explícita do usuário nesta rodada, exclusivamente para este
marcador (os outros 7 totens sintéticos documentados — `TESTE_IDEMP_*`,
`QA0918HTTP_*`, `TESTE_E2E_*`, `TESTE_INTEGRIDADE_*`, `TESTE_NUM_TAM_*`,
`TESTE_ORDEM_PENDBAIXA_*` — permanecem intocados por instrução explícita).

### Verificação prévia (somente leitura)

- `tb_totem.id_totem = 2053`, `codigo = 'TESTE_STATUS_PROC'`, `nome = 'Totem
  Teste Status'`, `localizacao`/`id_empresa` = `NULL`, criado em
  `2026-09-25 17:29:21` — dado sintético, sem qualquer indício de dado real.
- Único atendimento vinculado (`tb_atendimento.id_totem = 2053`):
  `id_atendimento = 9964`, `codigo_publico =
  08fca0fa-35a3-4cae-997f-ed696f05ce5d`, `placa = EEE3333` (placa fake),
  `cliente_nome`/`cliente_cnpj`/`motorista_nome`/`motorista_cpf`/
  `ajudante_nome`/`ajudante_cpf`/`pasta_documentos` todos `NULL` — confirmado:
  **nenhum CPF, nenhum documento (CNH/CRLV), nenhum cliente real associado**.
- Dependências por FK mapeadas (`SHOW COLUMNS`/relação por
  `id_totem`/`id_atendimento`): `tb_atendimento` (id_totem direto),
  `tb_lgpd_aceite` (id_totem direto), `tb_rate_limit_ocr` (id_totem como
  PK), `tb_atendimento_nota` (via `id_atendimento`), `tb_rate_limit_vio_status`
  (via `id_atendimento`, PK composta). `tb_vio_cache_cnh`/`tb_vio_cache_crlv`/
  `tb_vio_api_cache_cnh`/`tb_vio_api_cache_crlv` **não têm coluna
  `id_totem`/`id_atendimento`** — são caches por fingerprint/QR
  compartilhados, sem vínculo direto a totem/atendimento, portanto fora do
  escopo de exclusão (nenhuma linha pertence exclusivamente a este marcador).

### Contagens ANTES (por tabela afetada, filtradas pela chave exata)

| Tabela | Filtro | Qtd |
|---|---|---|
| `tb_atendimento` | `id_totem = 2053` | 1 |
| `tb_atendimento_nota` | `id_atendimento = 9964` | 0 |
| `tb_rate_limit_vio_status` | `id_atendimento = 9964` | 1 |
| `tb_lgpd_aceite` | `id_totem = 2053` | 0 |
| `tb_rate_limit_ocr` | `id_totem = 2053` | 0 |
| `tb_totem` | `id_totem = 2053` | 1 |

Baseline geral antes (para conferência de "nenhum outro registro atingido"):
`tb_totem` = 9, `tb_atendimento` = 127, `tb_atendimento_nota` = 100,
`tb_lgpd_aceite` = 0, `tb_rate_limit_ocr` = 8, `tb_rate_limit_vio_status` = 5.

### Execução

Transação única (`BEGIN`/`COMMIT`), chaves explícitas (`id_totem = 2053`,
`id_atendimento = 9964`, `codigo = 'TESTE_STATUS_PROC'`), sem `LIKE`/
curinga, sem `TRUNCATE`, dependências primeiro:

```sql
BEGIN;
DELETE FROM tb_rate_limit_vio_status WHERE id_atendimento = 9964;   -- 1 linha
DELETE FROM tb_atendimento_nota WHERE id_atendimento = 9964;         -- 0 linhas
DELETE FROM tb_lgpd_aceite WHERE id_totem = 2053;                    -- 0 linhas
DELETE FROM tb_rate_limit_ocr WHERE id_totem = 2053;                 -- 0 linhas
DELETE FROM tb_atendimento WHERE id_totem = 2053 AND id_atendimento = 9964; -- 1 linha
DELETE FROM tb_totem WHERE id_totem = 2053 AND codigo = 'TESTE_STATUS_PROC'; -- 1 linha
COMMIT;
```

Todas as contagens de linhas afetadas (`ROW_COUNT()`) conferiram
exatamente com o previsto antes do `COMMIT` — nenhum desvio, `ROLLBACK` não
foi necessário.

### Contagens DEPOIS (por tabela afetada)

| Tabela | Qtd depois | Delta |
|---|---|---|
| `tb_atendimento` | 126 | -1 |
| `tb_atendimento_nota` | 100 | 0 |
| `tb_rate_limit_vio_status` | 4 | -1 |
| `tb_lgpd_aceite` | 0 | 0 |
| `tb_rate_limit_ocr` | 8 | 0 |
| `tb_totem` | 8 | -1 |

`SELECT COUNT(*) FROM tb_totem WHERE codigo = 'TESTE_STATUS_PROC'` = **0**
(marcador ausente, confirmado). `SELECT COUNT(*) FROM tb_atendimento WHERE
id_atendimento = 9964` = **0**.

### Confirmação de integridade dos demais registros

Os 8 totens remanescentes (`RECEPCAO-01` real + 7 sintéticos de outras
demandas) permanecem com os mesmos `id_totem`/`codigo` de antes da operação:
`1` (`RECEPCAO-01`), `428` (`TESTE_IDEMP_2e528a`), `1214`
(`QA0918HTTP_b27393`), `1845` (`TESTE_E2E_16a493`), `1846`
(`TESTE_INTEGRIDADE_2c6415`), `1849` (`TESTE_NUM_TAM_da4583`), `1851`
(`TESTE_IDEMP_91280e`), `1857` (`TESTE_ORDEM_PENDBAIXA_4486bd`) — nenhum
alterado. Nenhum outro banco (`qa013_*`, `qa_iso2`, etc.) foi acessado.
Nenhuma credencial exibida além do necessário para a conexão local (mesmo
padrão já usado nas seções anteriores deste handoff). Nenhum commit/push,
nenhuma chamada de rede real, nenhum uso do Trello nesta tarefa.

### Resultado

Resíduo sintético `TESTE_STATUS_PROC` removido de `udlog_totem` (banco de
DEV LOCAL) com precisão cirúrgica — exatamente os 3 registros esperados
(`tb_totem` × 1, `tb_atendimento` × 1, `tb_rate_limit_vio_status` × 1),
zero impacto em qualquer outro dado real ou sintético de outras demandas.
Convergência do ambiente de desenvolvimento local considerada 100%
concluída (migrations aplicadas + Fase 3 de testes já validada + resíduo
sintético pendente agora eliminado).

## `/03-revisao` independente — veredito final (2026-09-26)

Revisão independente da implementação completa desta demanda, com 4
revisores independentes novos (`backend-especialista` como revisor,
`security-especialista`, `frontend-especialista`, `qa-testes`), sem
participação na implementação original. Não é revisão nova de código —
esta seção apenas documenta o resultado consolidado.

### Escopo e resultado por revisor

- **backend-especialista (revisor)**: 10/10 itens CONFIRMADOS — cliente
  vio.api.br, separação POST/polling, máquina de estados, timeout/
  `INDETERMINADO`, CAS/resultado tardio, mais 4 provas negativas reais via
  `proc_open`/mutação isolada: duplicação de POST, bypass de CAS por
  `tentativa_id`, resultado tardio pós-cancelamento, aprovação de CNH sem 2
  páginas/sem comparação confiável com controle positivo. Compatibilidade
  PHP 8.0/MariaDB 10.4/Hostgator confirmada. Observação não-bloqueante:
  sintaxe `VALUES()` em `ON DUPLICATE KEY UPDATE` está deprecated desde
  MySQL 8.0.20+ — não introduzida por esta demanda, já usada em código
  anterior do projeto.
- **security-especialista**: 6/7 CONFIRMADOS, 1 CONFIRMADO PARCIALMENTE
  com achado de atenção real (não bloqueante hoje, mas requer correção):
  `DocumentoController::iniciarProcessamento()` tem um bloco
  `try { ... } finally { ... }` envolvendo a chamada a
  `enviarParaLeitura()`/`gravarIdExternoVioApiBr()` SEM nenhuma cláusula
  `catch` — diferente do padrão já usado em `statusProcessamento()`, que
  tem `try/catch(\Throwable)` dedicado com log sanitizado. Hoje não há
  caminho de exploração ativo (o transporte HTTP real de produção nunca
  lança exceção, só retorna array de erro categorizado), mas a garantia
  depende inteiramente de `display_errors=Off` em produção, que não está
  documentado em `docs/deploy-checklist.md`. Achado secundário: comentários
  "ACHADO CRITICO" em `tests/manual/qa_db_bootstrap.php`
  (`qaDbCorrigirEnumsMigration015()`) e no cabeçalho de
  `tests/manual/teste_vio_api_br_cas_e_cache.php`, afirmando que a
  migration 015 precisa de workaround — CONTRADITOS por execução real e
  independente de 2 revisores diferentes (aplicaram a migration 015 num
  banco novo, sem workaround, e funcionou corretamente). Comentários
  desatualizados, precisam ser removidos/corrigidos numa próxima rodada.
- **frontend-especialista**: 7/7 CONFIRMADOS — CNH unificada, fluxos sem
  regressão, CRLV com ordem QR-antes-do-upload, fallback manual, zero
  retomada por F5, estados intermediários explícitos do backend, zero
  exposição de dado sensível. Confirmou explicitamente que uma nota
  anterior do handoff ("frontend ainda não inverteu a ordem do CRLV nem
  construiu a tela de estados intermediários") estava DESATUALIZADA/
  INCORRETA — o código real já implementa ambos desde rodadas anteriores.
- **qa-testes**: 5/5 CONFIRMADOS — migrations aplicadas corretamente em
  `udlog_totem` (contagens batendo: `tb_totem`=8, `tb_atendimento`=126,
  `tb_cliente`=38), resíduo `TESTE_STATUS_PROC` confirmado ausente, 38
  suítes reexecutadas de forma independente (1.492/1.492 asserções,
  cobertura mais ampla que a Fase 3 anterior de 29 suítes/1.350
  asserções — nenhuma suíte relevante ficou de fora, nenhuma falha nova),
  compatibilidade PHP 8.0.30/MariaDB 10.4.32 confirmada sem sintaxe
  incompatível.

### Confirmação de zero operação real

Zero chamada real à vio.api.br/Serpro/Talent, zero documento real, zero
API Key real, zero impressão, zero acesso a Hostgator/produção, zero
migration em banco real durante esta revisão (só leitura), zero Trello,
zero alteração de código durante `/03-revisao` (todos os revisores
confirmaram git status/hash idêntico ao início), zero commit/push.

### Pendências para decisão do usuário antes de `/04-commit-e-push`

Não bloqueiam o veredito, mas precisam de decisão:

1. Corrigir o gap de defesa em profundidade: adicionar `catch(\Throwable)`
   ao bloco try/finally de `iniciarProcessamento()` que envolve
   `enviarParaLeitura()`, com o mesmo padrão de log sanitizado já usado em
   `statusProcessamento()`.
2. Remover/corrigir os comentários "ACHADO CRITICO" desatualizados em
   `tests/manual/qa_db_bootstrap.php` e
   `tests/manual/teste_vio_api_br_cas_e_cache.php` (a migration 015 já
   funciona corretamente sem workaround, confirmado por 2 execuções
   independentes reais).
3. Documentar `display_errors=Off` como item obrigatório em
   `docs/deploy-checklist.md` para produção (achado do
   security-especialista).
4. Bancos `qa_`/`qa013_*` órfãos no MySQL local (de outras sessões/
   execuções interrompidas) — higienização não urgente, fora do escopo
   desta demanda.
5. Achados pré-existentes de outras demandas, reconfirmados sem correção:
   `teste_consulta_ordem_coleta.php`, `teste_lgpd_aceite_backend_seguranca.php`.

### VEREDITO FINAL

**APROVADO COM RESSALVA** — 2 achados não-bloqueantes de atenção (itens 1
e 2 acima) recomendados para correção numa rodada curta antes do
`/04-commit-e-push`, mas que não impedem a aprovação técnica da
implementação em si.

## Correção final — catch ausente em `iniciarProcessamento()` (2026-09-26)

Rodada curta de correção, escopo fechado pelo orquestrador em 3 tarefas,
endereçando exclusivamente o achado 1 do `/03-revisao` independente acima
(o achado 2 — comentários "ACHADO CRITICO" desatualizados em
`tests/manual/qa_db_bootstrap.php`/`teste_vio_api_br_cas_e_cache.php` — NÃO
foi tocado, fora do escopo desta rodada). Nenhuma chamada real/paga,
nenhum uso de credencial nova/antiga, nenhuma migration aplicada em banco
real, nenhum commit/push.

### Tarefa 1 — Correção do código

`app/Controller/DocumentoController.php::iniciarProcessamento()` — o bloco
que envolvia `App\Rn\VioApiBrClient::enviarParaLeitura()`/
`App\Dao\AtendimentoDao::gravarIdExternoVioApiBr()` só com `try { ... }
finally { ... }` (sem `catch`) ganhou `catch (\Throwable $e)` dedicado,
inserido **entre** o `try` e o `finally` já existentes (sintaxe PHP exige
essa ordem; `finally` continua sempre executando, liberando o lock
independente do resultado). Comportamento do novo `catch`:

- Loga via `logFalhaTecnica()` (mesmo padrão sanitizado já usado no resto
  da classe — nunca `getMessage()`/`getTraceAsString()`/`getFile()`/
  `getLine()`, só a classe concreta da exceção).
- Marca o documento como `INDETERMINADO` via
  `AtendimentoDao::marcarEnvioComoIndeterminado()` (método já existente,
  reaproveitado — nenhum método novo criado). Escolha de `INDETERMINADO`
  (nunca `ERRO`) é deliberada: como a exceção pode ter ocorrido antes do
  envio, durante o transporte HTTP, ou depois do fornecedor já ter aceito
  a chamada mas antes de `gravarIdExternoVioApiBr()` persistir o ID
  externo, não há como saber com segurança se um retry automático
  arriscaria uma segunda cobrança/segundo POST — `ERRO` permitiria uma
  nova tentativa via CAS (`iniciarEnvioVioApiBr()` aceita `PENDENTE`/
  `ERRO`), `INDETERMINADO` não.
- Devolve a MESMA mensagem/código HTTP genérico já usado para o outro
  caminho ambíguo pré-existente (`envio['ambiguo']` — "Nao foi possivel
  validar o documento agora", HTTP 502) — nenhuma mensagem nova, nenhum
  detalhe técnico exposto.
- `Resposta::erro()`/`exit()` continuam nunca sendo chamados dentro do
  `try/catch/finally` (só depois, no corpo do método) — mesma garantia já
  documentada no código, preservada.

### Tarefa 2 — `docs/deploy-checklist.md`

Novo item na seção "1.Z Migração vio.api.br + cache seguro" exigindo
`display_errors=Off`/`display_startup_errors=Off` em produção, com o texto
explícito de que é **defesa complementar, nunca dependência única** — o
código já é seguro mesmo com `display_errors=On` graças à correção da
Tarefa 1.

### Tarefa 3 — Validação (mocks locais, zero rede real, zero credencial real)

Script de validação pontual (não versionado, mantido fora do repositório —
scratchpad da sessão), rodando contra um banco `qa_`-prefixado descartável
próprio (mesmo `tests/manual/qa_db_bootstrap.php`), forçou uma exceção
REAL (nunca simulada por leitura de código) em cada um dos 3 pontos
exigidos, cada um também repetido com `ini_set('display_errors', '1')`
ligado dentro do próprio subprocesso de teste:

- **ANTES do envio**: `crlv.jpg` nunca gravado em disco — força
  `RuntimeException` real em `montarImagemCrlv()`. Cobre o catch JÁ
  EXISTENTE (regressão, não o novo) — confirmado sem mudança de
  comportamento: HTTP 500, mensagem sanitizada, `crlv_status_processamento
  = ERRO` (permite nova tentativa, correto — falha sem ambiguidade), lock
  liberado.
- **DURANTE o envio**: override de função por namespace (`App\Rn\curl_init`
  redefinida no subprocesso de teste, técnica padrão de teste sem
  framework de mock — PHP resolve chamadas de função não qualificadas
  dentro de um namespace preferencialmente pela função do próprio
  namespace antes do fallback global) força uma `RuntimeException` real
  dentro de `VioApiBrClient::executarHttpReal()`, sem nenhuma tentativa de
  rede de fato (zero DNS/socket real). Resultado: HTTP 502, mensagem
  genérica "Nao foi possivel validar o documento agora", zero detalhe
  técnico/stack trace na resposta OU no log (confirmado por
  `str_contains` negativo em ambos), `crlv_status_processamento =
  INDETERMINADO`, `crlv_vio_api_id` permanece `NULL`, lock liberado,
  `curl_init` chamado exatamente 1 vez (nenhum retry automático).
- **DEPOIS do envio bem-sucedido, antes de persistir o ID externo**:
  overrides completos de `curl_init`/`curl_setopt`/`curl_exec`/
  `curl_errno`/`curl_getinfo`/`curl_close` simulam uma resposta HTTP 200
  com um `id` de leitura válido do ponto de vista de
  `enviarParaLeitura()` (string não vazia — o cliente não valida tamanho),
  porém deliberadamente maior que o limite real da coluna
  `crlv_vio_api_id VARCHAR(128)`; com `SET SESSION sql_mode =
  'STRICT_ALL_TABLES'` na conexão de teste (sem isso o MariaDB local só
  emite warning e trunca silenciosamente, mascarando o cenário — achado
  registrado apenas para fins de reprodutibilidade do teste, a defesa do
  código em si é o `catch(\Throwable)` genérico, que cobre qualquer
  exceção neste ponto independente de `sql_mode`), `gravarIdExternoVioApiBr()`
  lança uma `PDOException` REAL ("Data too long for column"). Resultado:
  HTTP 502, mensagem genérica, zero SQL/nome de coluna/`SQLSTATE` vazado
  na resposta ou no log, `crlv_status_processamento = INDETERMINADO`,
  `crlv_vio_api_id` permanece `NULL` (o `UPDATE` que falhou nunca
  commitou), lock liberado.
- **Retentativa após `INDETERMINADO`** (cenários DURANTE/DEPOIS): uma
  segunda chamada a `iniciarProcessamento()` para o mesmo documento, sem
  nenhuma exceção forçada, confirmou resposta idempotente de sucesso
  (`"terminal":true`) e estado inalterado — o CAS (`iniciarEnvioVioApiBr()`
  só aceita `PENDENTE`/`ERRO`) impede que `VioApiBrClient` sequer seja
  instanciado de novo, então nenhum segundo POST é fisicamente possível
  nesse estado.

Todos os 3 cenários (6 execuções, com e sem `display_errors=1`) resultaram
em resposta e log sanitizados de forma idêntica — confirmando que a
proteção contra vazamento é do código (o novo `catch`), nunca dependente
de `display_errors=Off` do ambiente (que continua sendo recomendado como
defesa complementar, Tarefa 2).

Migrations 015/016 reaplicadas 2x num banco `qa_` descartável novo —
idempotentes, sem erro, sem duplicação (reconfirmação simples, mesmo
resultado já demonstrado nas rodadas anteriores).

As 7 suítes desta demanda foram reexecutadas e mantiveram exatamente as
mesmas contagens já registradas: `teste_vio_api_br_client.php` 77/77,
`teste_vio_api_br_cas_e_cache.php` 46/46, `teste_vio_api_br_migrations.php`
29/29, `teste_status_processamento.php` 13/13,
`teste_integridade_conclusao_atendimento.php` 43/43,
`teste_lock_obter_lock_documento.php` 25/25,
`teste_sanitizacao_logs_documento_nota.php` 29/29 — 262/262, zero
regressão.

`php -l` limpo em `app/Controller/DocumentoController.php`; `git diff
--check` limpo (só avisos de `LF`→`CRLF` do `core.autocrlf`, sem conflito/
whitespace real) em `app/Controller/DocumentoController.php` e
`docs/deploy-checklist.md`.

### Confirmação de zero operação real

Zero chamada real à vio.api.br/Serpro/Talent, zero credencial real, zero
documento real, zero impressão, zero acesso a produção/Hostgator, zero
migration em banco real (só `qa_` descartável, dropado ao final), zero
Trello, zero commit/push nesta rodada.

### Pendência remanescente

Achado 2 do `/03-revisao` (comentários "ACHADO CRITICO" desatualizados em
`tests/manual/qa_db_bootstrap.php`/`teste_vio_api_br_cas_e_cache.php`)
continua pendente — fora do escopo fechado desta rodada de correção.

## Rodada de limpeza de comentários (2026-09-26, qa-testes)

Achado 2 do `/03-revisao` resolvido: os comentários "ACHADO CRITICO" em
`tests/manual/qa_db_bootstrap.php` (função `qaDbCorrigirEnumsMigration015()`)
e no cabeçalho de `tests/manual/teste_vio_api_br_cas_e_cache.php` estavam
desatualizados — descreviam a migration 015 como quebrada/precisando de
workaround, quando o bug real já havia sido corrigido no próprio `.sql`
numa rodada anterior.

Confirmação independente própria (nova, além das 2 já feitas na
`/03-revisao`): migration 015 aplicada via `qaDbCriar()` num banco `qa_`
novo/descartável, **sem** chamar `qaDbCorrigirEnumsMigration015()`, produz
os ENUMs corretos (`INDETERMINADO` presente em
`cnh_status_processamento`/`crlv_status_processamento`, `VIO_CACHE` e
`VIO_API_BR` presentes em `cnh_origem_validacao`/`crlv_origem_validacao`);
reaplicar a migration em seguida é um no-op seguro (idempotente).

Ações tomadas (só comentários, nenhuma mudança de lógica/asserção):
- `tests/manual/qa_db_bootstrap.php`: `qaDbCorrigirEnumsMigration015()`
  virou no-op documentado (corpo vazio + docblock explicando o histórico),
  em vez de remover a função — menor mudança de comportamento possível,
  já que várias outras suítes (`teste_sanitizacao_logs_documento_nota.php`,
  `teste_lock_obter_lock_documento.php`,
  `teste_integridade_conclusao_atendimento.php`,
  `teste_status_processamento.php`) ainda a chamam explicitamente.
- `tests/manual/teste_vio_api_br_cas_e_cache.php`: cabeçalho reescrito
  para deixar claro no topo que o estado atual é "corrigido e validado",
  mantendo nota histórica do achado original (2026-09-26) e das 2
  reconfirmações independentes da `/03-revisão`. A função local
  `corrigirEnumsQuebradosPelaMigration015()` foi mantida sem alteração de
  lógica (só o docblock foi atualizado) — continua sendo chamada no fluxo
  do teste, hoje redundante/no-op na prática, mas fora do escopo desta
  limpeza (que é só de comentários) alterar essa chamada.

Validação: `teste_vio_api_br_migrations.php` 29/29, `teste_vio_api_br_cas_e_cache.php`
46/46 (mesmas contagens de antes), `php -l` limpo nos 2 arquivos editados,
`git diff --check` limpo (só avisos pré-existentes de `LF`→`CRLF` em
arquivos de outras rodadas, sem conflito real).

Zero chamada real, zero credencial real, zero migration em banco real (só
`qa_` descartável), zero commit/push, zero Trello nesta rodada.
