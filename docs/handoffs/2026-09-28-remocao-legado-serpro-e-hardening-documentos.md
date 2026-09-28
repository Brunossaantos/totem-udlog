# Handoff — remocao-legado-serpro-e-hardening-documentos

Data: 2026-09-28
Etapa: 00-planejamento

## O que foi pedido

Planejar (sem implementar) a remoção completa do fluxo antigo Serpro/
`VioDecodeClient` e o reforço da robustez do processamento de documentos
(conexão PDO compartilhada, catches genéricos), continuação natural da
demanda `migracao-vio-api-br-com-cache` (já publicada em `main`).

## Estado real confirmado (explorer, antes de qualquer plano)

- **`App\Rn\VioDecodeClient`** — código morto hoje: zero instanciação real
  em produção. Único uso é como TIPO em `DocumentoRn::validarCnh()`/
  `validarCrlv()` (`DocumentoRn.php:256,373`), que por sua vez NÃO têm
  nenhum chamador real fora de 7 arquivos de teste manual.
- **`App\Rn\ProdespClient`** — código morto, zero chamador em qualquer
  lugar do projeto.
- **8 variáveis de ambiente antigas** (`VIO_AMBIENTE`, `VIO_TRIAL_BEARER`,
  `VIO_TRIAL_DECODE_URL`, `VIO_TOKEN_URL`, `VIO_CONSUMER_KEY`,
  `VIO_CONSUMER_SECRET`, `VIO_PRODUCAO_DECODE_URL`, `VIO_CACHE_TTL_DIAS`
  antiga) só lidas por `VioDecodeClient.php`, ausentes do
  `deploy-checklist.md`.
- **`VIO_VALIDADO` no ENUM de origem**: NÃO é mais gravado por nenhum
  código novo, mas É LIDO ativamente em produção vigente
  (`AtendimentoDao.php:99,132`, `AtendimentoRn.php:130,148`,
  `DocumentoController.php:749,834`) — compatibilidade com dados
  históricos já gravados no banco.
- **`AtendimentoDao::iniciarProcessamento()`/`gravarResultadoProcessamento()`/
  `marcarProcessamentoObsoletoComoErro()`** (linhas 235,261,311) — achado
  extra: também código morto do fluxo síncrono antigo, zero chamador em
  produção, único consumidor é `teste_concorrencia_processamento_vio.php`.
- **`comparacao.campos`**: `CAMPOS_CRITICOS_CNH`/`CAMPOS_CRITICOS_CRLV`
  (`DocumentoRn.php:63-64`) usadas dentro de `respostaVioApiBrAprovavel()`
  (`DocumentoRn.php:1375-1410`, lê `$resultado['comparacao']['campos']` na
  linha 1401, rejeita se campo crítico tiver `mismatch`/`not_found`).
  Confirmado por 3 chamadas reais e pagas (2 CNH + 1 CRLV): esse array
  sempre veio VAZIO — o bloqueio nunca disparou na prática.
- **Conexão PDO compartilhada**: `util/Conexao.php` (singleton, já com
  `catch(PDOException)` sanitizado), `util/Bootstrap.php::conectar()`
  (mesmo padrão). `public/api/documento.php:19-27` tem o ÚNICO try/catch
  GLOBAL do entrypoint, envolvendo só `Bootstrap::conectar()` — depois
  disso a MESMA `$pdo` é injetada em `VioCacheDao`, `AtendimentoDao` (2x),
  `VioApiBrCacheDao`, `RateLimitVioStatusDao`, sem fronteira global
  cobrindo o resto do fluxo. O lock adicional é `GET_LOCK`/`RELEASE_LOCK`
  nativo do MySQL, amarrado à MESMA sessão PDO do request (não pode ser
  uma conexão separada sem redesenho maior).
- **8 `catch(\Throwable)` em `DocumentoController.php`** (linhas
  304,315,395,405,434,608,669,779) — todos já chamam `logFalhaTecnica()`,
  que loga só contexto fixo + `get_class($e)`, nunca `getMessage()`/
  trace/SQL. Mais 2 `catch(\PDOException)` específicos (377,458, já
  sanitizados, lock). **Gap real encontrado pelo security-especialista**:
  1 `catch(\RuntimeException)` em `upload()` (linha 240) NÃO chama
  `logFalhaTecnica()` — único ponto sem log algum (mensagens da exceção
  são strings fixas confirmadas seguras, mas o padrão do projeto deve ser
  mantido por consistência).
- **`tests/manual/teste_concorrencia_real_iniciar_processamento.php`**:
  não neutraliza `VIO_AMBIENTE`; usa `_caso_iniciar_processamento.php`
  (que instancia `DocumentoRn` com assinatura DESATUALIZADA, sem
  `VioApiBrCacheDao` — incompatível com o `DocumentoRn` atual). **Achado
  do qa-testes que corrige uma suposição inicial**: `teste_vio_api_br_cas_e_cache.php`
  NÃO usa `proc_open`/subprocessos reais para testar concorrência — a
  bateria roda 2 chamadas SEQUENCIAIS no mesmo processo PHP contra
  `AtendimentoDao::iniciarEnvioVioApiBr()`, que é logicamente suficiente
  porque o CAS é um único `UPDATE ... WHERE status IN (...)` atômico no
  banco (a garantia vem da semântica do SQL, não do timing do processo).
- **5 arquivos de teste exclusivos do Serpro**: `teste_vio_decode.php`,
  `teste_vio_decode_robustez.php`, `teste_vio_decode_matriz_tipos_campos.php`,
  `teste_vio_decode_wire_format.php`, `mock_vio_server.php` (este último
  só é `require`ado por `teste_vio_decode_robustez.php`, sem dependência
  cruzada).
- **3 testes de regra de negócio via mock antigo**: `teste_rebaixamento_manual.php`,
  `teste_talent_rntc_tipo_crlv.php`, `teste_talent_uf_crlv.php` — usam
  subclasses locais de `VioDecodeClient` (não `mock_vio_server.php`) e
  chamam `validarCnh()`/`validarCrlv()` diretamente, testando regras REAIS
  (RNTC ausente/placeholder, tipo ausente/placeholder, UF inválida,
  rebaixamento automático pra revisão manual) que hoje NÃO têm cobertura
  explícita equivalente contra o fluxo novo (`avaliarResultadoVioApiBrCrlv()`
  só tem 1 caso de CRLV aprovado em `teste_vio_api_br_cas_e_cache.php`,
  sem cobrir RNTC/tipo ausentes nem UF inválida especificamente).
- **Higiene**: `.claude/skills/`, `docs/indexTotem.html`, `tests/nf_teste/`,
  `docs/CNH-e.pdf.pdf`, `docs/HMY-1J20.pdf` confirmados untracked, não
  symlink, sem nenhuma referência de código ativo — remoção já autorizada
  explicitamente pelo usuário nesta demanda.
- Scripts/logs/JSON temporários dos testes reais pagos (`docs/teste_real_*_UNICO_USO.php`)
  já foram removidos em rodada anterior — confirmado que não existem mais.

## Mapa de remoção do legado Serpro (backend-especialista)

| Item | Classificação |
|---|---|
| `VioDecodeClient.php` | REMOVER |
| `ProdespClient.php` | REMOVER |
| `DocumentoRn::validarCnh()`/`validarCrlv()` | REMOVER |
| 8 env vars antigas (`.env.example`) | REMOVER — **investigar antes**: confirmar que `VIO_CACHE_TTL_DIAS` antiga não colide de nome com nenhuma variável nova do fluxo `vio.api.br` |
| `docs/manual_vio_decode.md` | REMOVER (doc de contrato morto) |
| `deploy-checklist.md` | Sem nota necessária hoje (já não menciona vars antigas) — **investigar antes**: confirmar se referencia `.env.example` por número de linha (a remoção desloca numeração) |
| `AtendimentoDao::iniciarProcessamento()`/`gravarResultadoProcessamento()`/`marcarProcessamentoObsoletoComoErro()` | REMOVER |
| 5 arquivos de teste exclusivos do Serpro | REMOVER |
| `VioDecodeClientFalso` (inline em 6 arquivos) | REMOVER onde só serve aos 5 testes acima; nos 3 restantes, tratar conforme decisão pendente abaixo |
| `teste_rebaixamento_manual.php`/`teste_talent_rntc_tipo_crlv.php`/`teste_talent_uf_crlv.php` | **INVESTIGAR/DECISÃO PENDENTE** — não remover sem antes confirmar/recriar cobertura equivalente contra o fluxo novo (ver seção "Decisão bloqueante" abaixo) |
| `VIO_VALIDADO` no ENUM | **PRESERVAR só por compatibilidade histórica** — nunca apagar/reinterpretar dado já gravado; nenhum código novo volta a gravar esse valor; adicionar comentário documentando isso no schema/código |

## Estratégia para `comparacao.campos`

Duas opções avaliadas — **recomendação: remover o bloco inteiramente**
(Opção A) de `respostaVioApiBrAprovavel()`, mantendo a decisão de aprovação
baseada só em `estado_leitura`/`qr_type`/`estado_comparacao`/
`summary.reliable`/`summary.mismatched` + allowlist/validações locais de
tipo/formato/faixa (essas continuam intactas, fora desse método). O
security-especialista pede explicitamente que essa remoção seja
**registrada como risco residual aceito** (perda de uma segunda camada de
defesa granular por campo, hoje sem efeito observado mas potencialmente
útil se o fornecedor um dia populares esse namespace) — nunca como
"limpeza de código morto" silenciosa.

## Estratégia de robustez da conexão PDO — escolhida e justificada

Avaliadas as 4 opções pedidas:
1. **Conexão exclusiva para o lock** — DESCARTADA: `GET_LOCK`/`RELEASE_LOCK`
   do MySQL são amarrados à sessão de conexão; separar exigiria redesenho
   de arquitetura fora do escopo.
2. **Proteção local em todo método do DAO** — sozinha insuficiente (não
   cobre a sequência/orquestração entre chamadas distintas).
3. **Fronteira global sanitizada no entrypoint** — sozinha insuficiente
   (evita vazamento, mas não decide o que fazer em cada ponto do fluxo).
4. **Combinação — ESCOLHIDA**: DAO com `catch(\PDOException)` específico
   nos pontos de escrita crítica; Controller mantém os catches específicos
   já existentes (377/458 no lock, mais os 8 já mapeados) tratando os 7
   cenários pedidos pelo usuário (antes do lock / depois do lock antes do
   POST / durante o POST / depois do POST antes de persistir o ID / durante
   o polling / ao gravar resultado / no finally de liberação do lock) —
   cada um com a regra dura de nunca reenviar POST automaticamente e
   preservar `INDETERMINADO` quando ambíguo; entrypoint (`documento.php`)
   ganha uma fronteira global COMPLEMENTAR (nunca substituta dos catches
   específicos) como rede de segurança final para qualquer exceção não
   prevista. Compatível com PHP 8.0/Hostgator (síncrono, sem processo
   persistente, sem retry de rede).

## Padronização de catches genéricos

Formalizar 2 sub-padrões já existentes na prática (sem mudar comportamento
observável):
- **Padrão A — fail-closed responder**: log sanitizado + resposta HTTP
  genérica fixa (linhas 304,395,405,434,779).
- **Padrão B — fail-closed swallow-into-safe-state**: log sanitizado, sem
  resposta de erro direta — fluxo segue pra estado já seguro/idempotente
  (linhas 315,608,669, mais os 2 `catch(\PDOException)` do lock).

**Corrigir o gap**: `upload()` linha 240 (`catch(\RuntimeException)`) ganha
`logFalhaTecnica()`, sem alterar a mensagem/código HTTP já devolvidos ao
cliente. `get_class($e)` confirmado seguro em todos os pontos (nenhuma
classe de exceção customizada codifica dado de negócio no nome).
Observação registrada, não decidida: 5 pontos em `DocumentoRn.php` (linhas
325,434,516,1231,1279) usam `error_log($e->getMessage())` pra
`DocumentoVioTipoInvalidoException` — confirmado seguro (mensagem nunca
carrega valor real, só nome de campo/tipo PHP fixos), mas diverge do
padrão `logFalhaTecnica()`/`get_class()` — decisão de padronizar ou não
fica para `/01-implementacao`.

**Prova negativa futura com marcadores sintéticos**: para cada um dos 9
pontos de captura, injetar exceção com string única identificável, capturar
log+resposta HTTP, confirmar ausência total da string em ambos, com e sem
`display_errors=On`.

## Migrations

**Nenhuma migration nova é necessária** para esta demanda — `VIO_VALIDADO`
permanece no ENUM (preservação, não limpeza), nenhuma tabela/coluna
exclusiva do fluxo Serpro identificada para remoção física (dado
histórico protegido pela mesma regra de preservação).

## Decisão sobre `teste_concorrencia_real_iniciar_processamento.php`

**Recomendação: EXCLUIR** (junto com `_caso_iniciar_processamento.php`,
seu único consumidor). Motivos: alcança rede/Serpro real sem neutralizar
`VIO_AMBIENTE`; assinatura de `DocumentoRn` desatualizada/incompatível;
cobertura de atomicidade do CAS novo já suficiente em
`teste_vio_api_br_cas_e_cache.php` (atomicidade garantida pelo `UPDATE`
único do banco, não pelo timing de processo — conversão pra
subprocessos reais seria trabalho redundante).

## Matriz de testes planejada (mocks/hermético, zero rede real)

- Confirmação estática (grep automatizado) de que `VioDecodeClient`/
  `ProdespClient`/`validarCnh()`/`validarCrlv()` não são mais alcançáveis
  por nenhum caminho de `app/Controller/`.
- Remoção dos 5 arquivos Serpro-only sem referência órfã restante.
- Prova negativa de vazamento (marcador sintético) para os 9 pontos de
  catch padronizados, com/sem `display_errors`.
- Os 7 cenários de queda de conexão PDO — técnica hermética (matar conexão
  dedicada em ponto específico, banco `qa_` descartável, confirmar sempre
  erro genérico tratado e estado de banco consistente, nunca parcial).
- Se migrations forem criadas no futuro (não previstas aqui): idempotência
  em banco `qa_` novo.
- Regressão ampla das suítes não tocadas por esta demanda (Talent, VIO API
  BR novo, CNH digital, lock, status-processamento, e2e).

## Decisão bloqueante para `/01-implementacao`

**Única decisão que realmente bloqueia o início da implementação**: se a
cobertura de regra de negócio RNTC/tipo/UF do CRLV e rebaixamento manual
(hoje testada só via `VioDecodeClientFalso`/`validarCrlv()` legado em
`teste_rebaixamento_manual.php`/`teste_talent_rntc_tipo_crlv.php`/
`teste_talent_uf_crlv.php`) deve ser **recriada contra o fluxo novo**
`vio.api.br` (`avaliarResultadoVioApiBrCrlv()`) **antes** de remover esses
3 arquivos, ou se essa cobertura granular específica é aceita como perdida
(confiando só na checagem geral `reliable`/`mismatched` do novo fluxo).
Sem essa decisão, remover os 3 arquivos seria perda de teste de regra de
negócio real, não só troca de mock morto.

Pendências menores, não bloqueantes (investigar durante `/01`, não impedem
início): colisão de nome de `VIO_CACHE_TTL_DIAS`; referência por número de
linha em `deploy-checklist.md`.

## Perguntas ao fornecedor — reclassificação

Mantidas como pendências operacionais NÃO bloqueantes somente:
limite máximo de arquivo/páginas, rate limit, timeout/SLA, garantia de
idempotência, retenção/exclusão antecipada, cobrança em sucesso/falha/PDF
multipágina. Webhook removido da lista (não é requisito atual, polling já
implementado).

## O que será feito (na futura `/01-implementacao`, NÃO agora)

- Remoção física de `VioDecodeClient.php`, `ProdespClient.php`,
  `validarCnh()`/`validarCrlv()`, 8 env vars antigas, `docs/manual_vio_decode.md`,
  3 métodos mortos de `AtendimentoDao`, 5 arquivos de teste Serpro-only.
- Remoção da dependência de `comparacao.campos` na aprovação (registrada
  como risco residual aceito).
- Combinação de estratégias de robustez PDO (DAO + Controller + fronteira
  global complementar no entrypoint).
- Padronização dos 9 catches (2 sub-padrões formalizados + gap de log em
  `upload()` corrigido).
- Exclusão de `teste_concorrencia_real_iniciar_processamento.php` +
  `_caso_iniciar_processamento.php`.
- Higiene: remoção de `.claude/skills/`, `docs/indexTotem.html`,
  `tests/nf_teste/`, `docs/CNH-e.pdf.pdf`, `docs/HMY-1J20.pdf` (já
  autorizada).
- Decisão do usuário sobre os 3 testes de regra de negócio (recriar
  cobertura vs. aceitar perda) aplicada conforme resposta recebida.

## O que NÃO será feito

- Nenhuma migration de limpeza de schema (preservação de `VIO_VALIDADO`).
- Nenhuma remoção/reinterpretação de dado histórico no banco.
- Nenhum redesenho de conexão dedicada pro lock (opção 1 descartada).
- Fora do escopo, conforme instrução: campos faltantes do payload Talent,
  impressão/Netum físicos, `origensPermitidas` de produção, storage no
  Hostgator, permissão de câmera, OCR físico, outras pendências de baixa
  severidade não relacionadas, banco/deploy do Hostgator.
- Nenhuma chamada externa, leitura de credencial, teste pago, alteração de
  banco, remoção de arquivo, commit ou push nesta etapa de planejamento.

## Sub-agentes envolvidos

- `explorer` — mapeamento exaustivo (arquivo:linha) do legado Serpro, uso
  de `comparacao.campos`, conexão PDO compartilhada, catches genéricos,
  teste de concorrência real, higiene de arquivos.
- `backend-especialista` — mapa de remoção classificado, estratégia de
  `comparacao.campos`, estratégia de robustez PDO (4 opções comparadas,
  Combinação escolhida), padronização de catches, avaliação de migrations
  (nenhuma necessária).
- `security-especialista` — revisão dos catches (2 sub-padrões, gap de log
  encontrado em `upload()`), ângulo de segurança da estratégia PDO (nunca
  retry de POST, `INDETERMINADO` preservado, fronteira global como
  complementar não substituta), avaliação de risco residual da remoção de
  `comparacao.campos`, matriz de testes de segurança (prova negativa com
  marcador sintético).
- `qa-testes` — decisão/recomendação sobre `teste_concorrencia_real_iniciar_processamento.php`
  (excluir, com correção de uma suposição errada sobre `proc_open`), matriz
  de testes completa, achado bloqueante sobre cobertura de regra de negócio
  RNTC/tipo/UF/rebaixamento manual não recriada no fluxo novo.

## Riscos e pendências identificadas

- **Decisão bloqueante** (ver seção dedicada acima): recriar ou não a
  cobertura de RNTC/tipo/UF/rebaixamento manual antes de remover os 3
  testes legados.
- Risco residual aceito (se a remoção de `comparacao.campos` for
  confirmada): perda de uma segunda camada de defesa granular por campo,
  hoje sem efeito observado.
- Investigar antes de remover `VIO_CACHE_TTL_DIAS` antiga: colisão de nome
  com variável nova.
- Investigar `deploy-checklist.md`: referência por número de linha ao
  `.env.example`.
- `docs/manual_vio_decode.md` — removido nesta demanda; se algum dia o
  fornecedor confirmar contrato real de `comparacao.campos`, reintroduzir
  exigirá nova rodada de decisão, não reaproveitar código removido sem
  revisão.
- Os 2 PDFs reais do usuário (`docs/CNH-e.pdf.pdf`/`docs/HMY-1J20.pdf`)
  NÃO são recuperáveis pelo Git após remoção (nunca foram versionados) —
  usuário já ciente e autorizou.

## Confirmação de zero operação real

Nenhuma chamada de rede real foi feita. Nenhuma credencial foi lida.
Nenhum código foi alterado. Nenhum arquivo foi removido. Nenhuma migration
foi executada. Nenhum banco foi alterado. Nenhum teste pago foi executado.
Nenhum commit ou push foi feito. Trello não foi usado, por instrução
explícita do usuário para esta demanda.

## Trello

card_id: N/A - Trello explicitamente não usado nesta demanda, por
instrução do usuário.

## Próximo passo

Usuário decide sobre a única pendência realmente bloqueante (recriar ou
aceitar perda da cobertura de RNTC/tipo/UF/rebaixamento manual) antes de
`/01-implementacao`. As pendências não bloqueantes (colisão de nome de env
var, referência de linha no deploy-checklist) podem ser resolvidas durante
a própria implementação.

## Suíte de equivalência criada e validada (2026-09-28)

**Etapa**: `/01-implementacao` (primeira etapa, qa-testes isolado — nenhum
outro agente alterou o worktree em paralelo). Decisão do usuário já
confirmada: recriar a cobertura de regra de negócio RNTC/tipo/UF/CRLV e
rebaixamento manual contra o fluxo REAL `vio.api.br`
(`avaliarResultadoVioApiBrCrlv()`/`avaliarCrlv()`/`preencherManualCrlv()`/
`tentarCacheCrlv()`) ANTES de remover os 3 testes legados.

Suíte criada: `tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php`
— mesmo padrão hermético já usado no projeto (banco `qa_`-prefixado
descartável via `tests/manual/qa_db_bootstrap.php`, zero rede real, zero
credencial real). **46 asserções, 46 passaram, 0 falharam.** `php -l`
limpo.

### Achado registrado (não bloqueante, mas relevante — releitura de código
### confirmou, não presumiu)

O cenário 1 pedido no escopo desta tarefa ("CRLV sem RNTC, veículo
particular — aceito, sem inventar valor") **não corresponde a nenhuma regra
real do código, em nenhum dos dois fluxos (novo ou legado)**. Confirmado
por:
- Leitura de `DocumentoRn::avaliarCrlv()` — `if ($rntc === '') { ... }`
  rejeita incondicionalmente, sem nenhuma ramificação por tipo de veículo.
- `grep -i particular app/` — zero ocorrências em todo o código-fonte
  (nenhum conceito de "veículo particular" existe no sistema).
- Releitura de `tests/manual/teste_talent_rntc_tipo_crlv.php` (o teste
  legado mais próximo do tema) — RNTC vazio já era testado ali como
  rejeição incondicional (linhas ~98-101), sem exceção por tipo de veículo
  também no fluxo antigo.

Ou seja: não é uma equivalência perdida (a regra nunca existiu nos 3 testes
antigos para ser recriada) — é uma premissa da tarefa que não reflete o
comportamento real do sistema. A suíte nova testa a regra REAL confirmada
(RNTC ausente sempre rejeita o CRLV, independente do valor do campo tipo,
inclusive quando o tipo informado é literalmente "PARTICULAR") — ver
cenário 1 no arquivo de teste, com o achado documentado também no
cabeçalho do próprio arquivo. Reportado aqui para o orquestrador decidir
se há alguma decisão de produto pendente sobre veículos particulares sem
RNTC (fora do escopo desta demanda de testes — não decidido/implementado
por este agente).

### Matriz de equivalência (cenário antigo → cenário novo)

| Cenário antigo (arquivo:teste) | Cenário novo equivalente (nesta suíte) |
|---|---|
| `teste_talent_rntc_tipo_crlv.php`:item 2 (RNTC/tipo extraídos da resposta VIO aprovam o CRLV) | Cenário 3 (RNTC presente/válido preservado) + Cenário 5 (Tipo presente/válido preservado), contra `avaliarResultadoVioApiBrCrlv()` |
| `teste_talent_rntc_tipo_crlv.php`:item 3 (RNTC vazio rejeita, motivo menciona RNTC) | Cenário 2 (RNTC ausente nunca vira placeholder, rejeição real) — substitui também o cenário 1 pedido no escopo, ver achado acima |
| `teste_talent_rntc_tipo_crlv.php`:item 3 (Tipo vazio rejeita, motivo menciona "Tipo de veiculo") | Cenário 4 (Tipo ausente rejeita) |
| `teste_talent_rntc_tipo_crlv.php`:item 3 (RNTC/tipo placeholder "xxxxx"/"string" rejeitam) | **NÃO recriado nesta suíte** — ver nota de escopo abaixo |
| `teste_talent_rntc_tipo_crlv.php`:item 4 (cache completo com RNTC/tipo é reaproveitado; cache antigo sem RNTC/tipo nunca é cache-hit) | Cenário 12 (VIO_API_BR vs VIO_CACHE como origens distintas, cache-hit real via `tentarCacheCrlv()`) — a garantia de "cache incompleto nunca é hit" já está coberta estruturalmente por `VioApiBrCacheDao`/schema novo (colunas NOT NULL onde aplicável), não pelo cenário de "registro legado pré-migration" (não existe mais, é conceito exclusivo do cache antigo `tb_vio_cache_crlv`) |
| `teste_talent_rntc_tipo_crlv.php`:item 5 (preenchimento manual reaproveita RNTC/tipo já aprovados; rejeita como incompleto sem valor prévio) | **NÃO recriado nesta suíte** — pertence a `App\Controller\DocumentoController::preencherManual()` (camada de Controller via subprocesso HTTP real), fora do escopo de `DocumentoRn`/`AtendimentoRn` testado aqui; ver nota de escopo abaixo |
| `teste_talent_uf_crlv.php`:1 (UF válida via VIO aprova e grava maiúsculo) | Cenário 6 (UF válida aprova e grava maiúsculo) |
| `teste_talent_uf_crlv.php`:2 (UF inválida via VIO rejeita, motivo menciona UF) | Cenário 8 (UF fora da lista de 27 siglas rejeita) |
| `teste_talent_uf_crlv.php`:3 (preenchimento manual com UF válida aprova) | **NÃO recriado nesta suíte** (camada `preencherManualCrlv()` testada indiretamente pelos cenários de rebaixamento, mas sem um cenário MANUAL dedicado de UF válida) — ver nota de escopo abaixo |
| `teste_talent_uf_crlv.php`:4 (preenchimento manual com UF inválida rejeita) | **NÃO recriado nesta suíte** — mesma nota acima |
| `teste_talent_uf_crlv.php`:5 (cache antigo sem UF nunca é cache-hit) | Coberto estruturalmente pelo cache novo (mesma nota do item de RNTC/tipo acima — conceito exclusivo do cache legado) |
| `teste_rebaixamento_manual.php`:1-2 (snapshot gravado só quando origem é automática; confirmar sem editar preserva origem) | Cenário 11 (confirmar sem divergir do snapshot preserva `VIO_API_BR`) |
| `teste_rebaixamento_manual.php`:3-4 (editar 1 ou vários campos rebaixa para MANUAL) | Cenário 10 (editar RNTC divergente do snapshot rebaixa para MANUAL) |
| `teste_rebaixamento_manual.php`:7a-2 (RNTC/tipo divergentes do snapshot rebaixam, mesma lógica de exercício/placa) | Cenário 10 (mesmo mecanismo, mesma função `AtendimentoRn::salvarDadosMotorista()`, agora com snapshot gravado por origem `VIO_API_BR`) |
| `teste_rebaixamento_manual.php`:5 (restaurar valor original não promove de volta sozinho) | **NÃO recriado nesta suíte** — comportamento já 100% dentro de `AtendimentoRn::salvarDadosMotorista()` (mesma função testada nos cenários 10/11), não depende de qual fluxo (legado ou novo) populou o snapshot; risco de regressão específico do fluxo novo é baixo, mas não foi exercitado explicitamente aqui — ver nota de escopo |
| `teste_rebaixamento_manual.php`:6 (campo de origem forjado pelo front é ignorado) | **NÃO recriado nesta suíte** — mesma função `salvarDadosMotorista()`, já não lê nenhum campo de origem do array `$dados` (confirmado por leitura de código), comportamento independente do fluxo que populou o snapshot |
| `teste_rebaixamento_manual.php`:7b (cache VIO nunca é alterado por `salvarDadosMotorista`) | **NÃO recriado nesta suíte** — `salvarDadosMotoristaComOrigem()`/`salvarDadosMotorista()` nunca tocam `tb_vio_api_cache_crlv`/`tb_vio_api_cache_cnh` por design (mesma função testada nos cenários 10/11, nenhum código nesse caminho referencia as tabelas de cache) |
| `teste_rebaixamento_manual.php`:8-8b (rejeição de placeholder simétrica CNH/CRLV) | **NÃO recriado nesta suíte** (fora do escopo explícito desta tarefa, que listou 16 cenários específicos sem incluir placeholder) — cobertura de placeholder para CRLV via `avaliarResultadoVioApiBrCrlv()` seria um cenário adicional recomendado, não incluído aqui |
| `teste_rebaixamento_manual.php`:9 (descarte explícito de `image`, nunca vaza na resposta) | **NÃO recriado nesta suíte** — `avaliarResultadoVioApiBrCrlv()` nunca recebe/lê a chave `image` (o payload já vem pré-normalizado por `VioApiBrClient::normalizarResultado()`, fora do escopo desta suíte); risco residual muito baixo, mas não testado explicitamente aqui |

### Nota de escopo (não bloqueante, registrada para decisão do orquestrador)

Os itens marcados "NÃO recriado nesta suíte" acima se dividem em 2 grupos:
1. **Cobertos indiretamente/estruturalmente** (cache legado incompleto,
   `salvarDadosMotorista` não tocar cache, não ler campo de origem forjado)
   — a lógica testada nesses itens antigos é a MESMA função/tabela testada
   com sucesso nos cenários 10-13 desta suíte nova, só não foi reexercida
   em um cenário isolado dedicado. Risco de regressão: baixo.
2. **Genuinamente fora do escopo dos 16 cenários pedidos** (preenchimento
   manual via Controller/subprocesso HTTP real com reaproveitamento
   parcial de campos, rejeição de placeholder para CRLV no fluxo novo,
   restauração de valor original não promove sozinho, descarte de `image`)
   — a tarefa listou exatamente 16 cenários; esses comportamentos não
   estavam entre eles. Não são achados bloqueantes (a decisão do usuário
   foi sobre RNTC/tipo/UF/CRLV e rebaixamento — todos cobertos), mas ficam
   registrados aqui para o backend-especialista/orquestrador avaliar antes
   da remoção final, caso considerem necessária uma suíte complementar.

### Execução

```
php -l tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php
→ No syntax errors detected

php tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php
→ === RESULTADO: 46 testes, 46 passaram, 0 falharam ===
```

Nenhum arquivo além de `tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php`
e esta seção do handoff foi criado/alterado nesta etapa. Nenhum dos 3
arquivos de teste legados foi tocado/removido. Nenhuma chamada real,
nenhuma credencial real, nenhuma migration em banco real. Nenhum commit/
push feito. Trello não usado.

### Conclusão

**Cobertura EQUIVALENTE CONFIRMADA para os 16 cenários explicitamente
pedidos no escopo desta tarefa** (RNTC/tipo/UF ausentes-presentes-inválidos,
tipos incompatíveis, rebaixamento/manutenção de origem, VIO_API_BR vs
VIO_CACHE, cache vencido, atomicidade/gravação parcial, zero chamada
externa) — pronta para o backend-especialista prosseguir com a remoção dos
3 arquivos antigos (`teste_rebaixamento_manual.php`,
`teste_talent_rntc_tipo_crlv.php`, `teste_talent_uf_crlv.php`), **desde que
o orquestrador esteja ciente da nota de escopo acima** (itens não
recriados, nenhum deles bloqueante para a decisão original do usuário, mas
alguns genuinamente fora dos 16 cenários pedidos — não uma equivalência
silenciosamente perdida, e sim um limite de escopo explícito desta tarefa).

## Remoção do legado + hardening implementados (2026-09-28)

**Etapa**: `/01-implementacao` (backend-especialista, único agente
escrevendo no worktree nesta rodada). Segue a suíte de equivalência já
validada pela QA (seção acima, 46/46) e a decisão do usuário de recriar a
cobertura antes de remover os 3 testes legados.

### 1. Remoção do legado — inventário completo

**Arquivos removidos (`git rm`/delete físico, confirmados por busca global
antes de cada exclusão — zero dependência ativa restante)**:
- `app/Rn/VioDecodeClient.php`
- `app/Rn/ProdespClient.php`
- `docs/manual_vio_decode.md`
- `tests/manual/teste_vio_decode.php`
- `tests/manual/teste_vio_decode_robustez.php`
- `tests/manual/teste_vio_decode_matriz_tipos_campos.php`
- `tests/manual/teste_vio_decode_wire_format.php`
- `tests/manual/mock_vio_server.php`
- `tests/manual/teste_concorrencia_real_iniciar_processamento.php`
- `tests/manual/_caso_iniciar_processamento.php` (único consumidor era o
  teste acima)
- `tests/manual/teste_rebaixamento_manual.php`
- `tests/manual/teste_talent_rntc_tipo_crlv.php`
- `tests/manual/teste_talent_uf_crlv.php`
- `tests/manual/teste_concorrencia_processamento_vio.php` (achado adicional
  confirmado no início desta implementação: testava exclusivamente os 3
  métodos mortos do `AtendimentoDao` removidos nesta mesma rodada —
  `iniciarProcessamento()`/`gravarResultadoProcessamento()`/
  `marcarProcessamentoObsoletoComoErro()` — ficaria órfão sem eles)

**Código removido dentro de arquivos que permanecem**:
- `app/Rn/DocumentoRn.php`: métodos `validarCnh()` e `validarCrlv()`
  (fluxo síncrono antigo, tipo `VioDecodeClient` no parâmetro). Constantes
  `CAMPOS_CRITICOS_CNH`/`CAMPOS_CRITICOS_CRLV` também removidas (só existiam
  para o critério de `comparacao.campos`, ver seção 3 abaixo). `preencherManualCnh()`/
  `preencherManualCrlv()` **preservados intocados** (fluxo manual do
  atendente, independente do legado Serpro). `VioCacheDao $cacheDao`
  (propriedade do construtor), `calcularIdentificador()` e `cacheTtlDias()`
  **preservados fisicamente** mesmo hoje sem nenhum chamador dentro da
  classe — a assinatura pública do construtor de `DocumentoRn` é consumida
  por ~20 arquivos de teste não listados no inventário desta demanda (ex.:
  `tests/manual/teste_lock_obter_lock_documento.php`,
  `teste_sanitizacao_logs_documento_nota.php`, etc.) e por
  `public/api/documento.php`/`public/api/atendimento.php` — remover o
  parâmetro ou os métodos privados ficaria fora do inventário aprovado
  desta tarefa (regra explícita: parar e informar antes de tocar algo fora
  da lista). Registrado aqui como observação, não decidido/implementado.
- `app/Dao/AtendimentoDao.php`: métodos `iniciarProcessamento()`,
  `gravarResultadoProcessamento()`, `marcarProcessamentoObsoletoComoErro()`
  (fluxo síncrono antigo). `colunasStatusProcessamento()`/
  `COLUNAS_STATUS_PROCESSAMENTO` **preservados** — ainda usados por
  `marcarProcessamentoConcluido()` (fluxo manual atual).
- `app/Controller/DocumentoController.php`: constante
  `TIMEOUT_PROCESSAMENTO_SEGUNDOS` removida (só existia como documentação
  de um valor usado exclusivamente pelos 3 métodos de DAO acima, já
  removidos — ficaria 100% órfã).

**Comentários/blocos órfãos atualizados** (referenciavam diretamente
`VioDecodeClient`/`validarCnh()`/`validarCrlv()` como se ainda existissem
no código): cabeçalho de classe e bloco da seção "Fluxo vio.api.br" em
`app/Rn/DocumentoRn.php`; cabeçalho de `app/Rn/VioApiBrClient.php`;
comentário de `salvarDadosMotorista()` em `app/Rn/AtendimentoRn.php`
(atualizado para referenciar `avaliarResultadoVioApiBrCnh/Crlv`/
`VIO_API_BR`, já que é o caminho real que restaura a origem hoje);
cabeçalho de `app/Controller/DocumentoController.php`; bloco "Fluxo
assíncrono vio.api.br" em `app/Dao/AtendimentoDao.php`; menção em
`docs/deploy-checklist.md` (item 1.Z). Menções remanescentes de
`VioDecodeClient`/`validarCnh`/`validarCrlv` em todo `app/` são **só
comentários históricos claramente marcados como removidos/antigos**
(confirmado por `grep -rn "validarCnh\|validarCrlv\|VioDecodeClient\|ProdespClient" app/`
— zero código vivo, só documentação). Handoffs antigos
(`docs/handoffs/2026-09-*.md`, exceto este) e comentários em testes não
tocados nesta rodada **não foram reescritos** — são registro histórico de
demandas já encerradas, fora do escopo de reescrever.

**Confirmado por leitura de código**: nenhum fallback automático para
Serpro/Prodesp existe em nenhum lugar do projeto — `VioDecodeClient`/
`ProdespClient` nunca tiveram um único chamador real fora dos testes
manuais agora removidos.

### 2. Variáveis de ambiente

**Removidas de `.env.example`** (nomes apenas, nunca havia valor real
neste arquivo `.example`): `VIO_AMBIENTE`, `VIO_TRIAL_BEARER`,
`VIO_TRIAL_DECODE_URL`, `VIO_TOKEN_URL`, `VIO_CONSUMER_KEY`,
`VIO_CONSUMER_SECRET`, `VIO_PRODUCAO_DECODE_URL`, `VIO_CACHE_TTL_DIAS`
(antiga, do cache Serpro morto), `PRODESP_API_URL`, `PRODESP_API_KEY` — 10
variáveis no total (8 do VIO Decode + 2 do Prodesp, achado do explorer no
planejamento).

**Colisão de nome investigada e confirmada SEM conflito**: `VIO_CACHE_TTL_DIAS`
(antiga) e `VIO_API_BR_CACHE_TTL_DIAS` (nova, ativa) são nomes de variável
totalmente distintos — confirmado por grep antes da remoção
(`DocumentoRn::cacheTtlDias()`, agora dead code mas fisicamente presente,
lê `VIO_CACHE_TTL_DIAS`; `DocumentoRn::cacheTtlDiasVioApiBr()` lê
`VIO_API_BR_CACHE_TTL_DIAS`). Remover a antiga não afeta em nada a leitura
da nova.

**Preservadas obrigatoriamente em `.env.example`** (confirmado por leitura
do arquivo após a edição): `VIO_API_BR_BASE_URL`, `VIO_API_BR_API_KEY`,
`VIO_API_BR_CACHE_TTL_DIAS`, `VIO_API_BR_CACHE_HMAC_KEY_V1`,
`VIO_API_BR_CACHE_HMAC_VERSION`, `DOCUMENTO_QR_HMAC_KEY`,
`DOCUMENTO_DATA_KEY`.

**`.env` local real — AÇÃO PENDENTE, NÃO EXECUTADA**: a remoção das mesmas
10 linhas no `.env` local (fora do controle de versão) foi **bloqueada pelo
sistema de permissão do ambiente do agente** (classificador de "Credential
Leakage" recusou o comando `sed` mesmo sem imprimir nenhum valor — só os
nomes de variável seriam afetados). Nenhuma tentativa alternativa de
contornar essa recusa foi feita, conforme instrução de segurança. O `.env`
local **permanece com as 10 variáveis antigas ainda presentes**
(nomes confirmados presentes antes da tentativa: `VIO_AMBIENTE`,
`VIO_TRIAL_BEARER`, `VIO_TRIAL_DECODE_URL`, `VIO_TOKEN_URL`,
`VIO_CONSUMER_KEY`, `VIO_CONSUMER_SECRET`, `VIO_PRODUCAO_DECODE_URL`,
`VIO_CACHE_TTL_DIAS`, `PRODESP_API_URL`, `PRODESP_API_KEY` — só nomes,
nenhum valor foi lido/exibido). Isso é inofensivo do ponto de vista
funcional (nenhum código lê mais essas variáveis de forma alcançável em
produção — só o método dead code `cacheTtlDias()` ainda lê
`VIO_CACHE_TTL_DIAS` com fallback seguro), mas fica como **pendência
operacional**: o usuário (ou uma sessão com permissão de editar `.env`)
precisa remover essas 10 linhas manualmente do `.env` local, nunca
compartilhando o conteúdo removido.

### 3. `comparacao.campos` — risco residual aceito

Removida a dependência de `comparacao.campos` (e as constantes
`CAMPOS_CRITICOS_CNH`/`CAMPOS_CRITICOS_CRLV`) de
`DocumentoRn::respostaVioApiBrAprovavel()`. A decisão de aprovação agora
depende exclusivamente de: `estado_leitura`/`estado_comparacao=completed`,
`qr_type=vio`, `dados_leitura` presente e array, `summary.reliable=true`,
`summary.mismatched=0` (resumo GERAL), contagem de páginas correta (quando
aplicável), e a extração/validação de tipo/formato/faixa dos campos
críticos dentro de `dados_leitura` — via `avaliarResultadoVioApiBrCnh()`/
`Crlv()`, **intactas**. Fail-closed preservado: `reliable=false` rejeita;
`mismatched>0` (global) rejeita; contagem de páginas incorreta rejeita
(onde aplicável); campo crítico ausente/inválido no `dados_leitura` rejeita
(via `DocumentoVioTipoInvalidoException`, comportamento já existente,
intocado).

**RISCO RESIDUAL CONHECIDO E ACEITO, registrado explicitamente (não como
"limpeza de código morto" silenciosa)**: perde-se uma SEGUNDA camada de
defesa granular por campo (checagem individual de `mismatch`/`not_found`
por campo crítico) — hoje sem efeito observado em produção (3 respostas
reais/pagas confirmadas, 2 CNH + 1 CRLV, sempre trouxeram esse namespace
VAZIO), mas potencialmente útil se o fornecedor um dia popular
`comparacao.campos`/`compare.result.fields`. Reintroduzir essa checagem no
futuro exige nova rodada de decisão explícita do usuário, nunca reativação
automática. `tests/manual/teste_vio_api_br_cas_e_cache.php` foi atualizado
(cenário "1b.4", antes chamado "not_found em campo CRITICO... NUNCA
aprova") para refletir o novo comportamento sancionado (agora aprova,
comentário do teste documenta a mudança de regra) — **46/46, mesma
contagem de antes**, só o comportamento esperado da asserção mudou.

### 4. Robustez da conexão PDO — estratégia combinada

Confirmado por leitura de código que os 7 pontos pedidos (antes do lock,
depois do lock, durante o envio, depois do POST antes de persistir o ID
externo, durante o polling, ao gravar resultado, durante liberação do
lock) **já estavam cobertos** em `DocumentoController::iniciarProcessamento()`/
`statusProcessamento()` por catches específicos já existentes (herança da
demanda `migracao-vio-api-br-com-cache`, confirmada intacta): nunca repete
POST automaticamente, usa `INDETERMINADO` quando ambíguo, preserva
CAS/`tentativa_id`, `AtendimentoDao::gravarResultadoFinalVioApiBr()` já
faz a dupla checagem (`tentativa` vigente E `status='em_andamento'`) que
impede resultado tardio de alterar atendimento cancelado/concluído.

**Adição desta rodada — fronteira global complementar em
`public/api/documento.php`**: todo o corpo do entrypoint (da montagem de
`DocumentoRn`/`DocumentoController` até o `switch` de ações) foi envolvido
num `try/catch(\Throwable)` final, como rede de segurança contra qualquer
exceção NÃO PREVISTA que escape de todos os pontos já tratados no
Controller (ex.: uma `PDOException` de uma chamada ao `AtendimentoDao` sem
try/catch dedicado nesse fluxo específico — como `buscarAtendimentoDoTotem()`/
`validarAtendimentoParaProcessamento()`/`marcarProcessamentoVioApiBrExpiradoComoIndeterminado()`).
Log sanitizado (`get_class($e)`, nunca `getMessage()`/trace/SQL/payload) +
resposta HTTP 500 genérica fixa. **Nunca substitui** os catches específicos
já existentes no Controller — só complementa. Nenhuma conexão dedicada
para `GET_LOCK` foi criada (confirmado tecnicamente inviável, decisão já
registrada no planejamento — `GET_LOCK`/`RELEASE_LOCK` são amarrados à
sessão PDO do request).

### 5. Catches genéricos — gap corrigido

`DocumentoController::upload()`, `catch (\RuntimeException $e)` (único
ponto sem log, achado do security-especialista no planejamento) — agora
chama `logFalhaTecnica("upload id_atendimento={$idAtendimento} tipo={$tipo}", $e)`,
mesmo padrão sanitizado (contexto fixo + `get_class($e)`, nunca
`getMessage()`/trace) já usado nos outros 8 pontos de captura do
Controller. **Mensagem/código HTTP já devolvidos ao cliente não foram
alterados** — só o log foi adicionado. Confirmado por leitura: os demais 8
`catch(\Throwable)` (linhas originais 304/315/395/405/434/608/669/779) e 2
`catch(\PDOException)` (377/458, lock) **já chamavam `logFalhaTecnica()`**
antes desta rodada — nenhuma mudança neles. Os 5 pontos em
`DocumentoRn.php` que usam `error_log($e->getMessage())` para
`DocumentoVioTipoInvalidoException` (confirmados seguros — mensagem nunca
carrega valor real, só nome de campo/tipo PHP fixos) **não foram
padronizados** para `logFalhaTecnica()`/`get_class()` nesta rodada —
decisão registrada no planejamento como não bloqueante, mantida como está
(fora do inventário explícito desta tarefa).

### 6. Achado sobre "veículo particular" — registrado, não decidido

Confirmado pela QA (seção acima) e revalidado nesta rodada: o cenário
"CRLV sem RNTC, veículo particular — aceito" pedido originalmente para a
suíte de equivalência **não corresponde a nenhuma regra real do código**,
em nenhum dos dois fluxos (novo `vio.api.br` ou o antigo, agora removido).
`DocumentoRn::avaliarCrlv()` rejeita RNTC ausente/vazio
INCONDICIONALMENTE, sem nenhuma ramificação por tipo de veículo — e
`grep -i particular app/` continua retornando zero ocorrências em todo o
código-fonte (nenhum conceito de "veículo particular" existe no sistema).
Isso **não é uma regressão nem uma decisão tomada por este agente** — é
uma premissa de escopo da tarefa de testes que nunca bateu com o
comportamento real do sistema, já documentada pela QA antes desta
implementação. Se houver uma decisão de produto real pendente sobre
veículos particulares sem RNTC, ela **não foi tomada nem implementada
aqui** — fica registrada para o orquestrador avaliar, fora do escopo desta
demanda de remoção de legado/hardening.

### 7. Validação executada

- `grep -rn "validarCnh\|validarCrlv\|VioDecodeClient\|ProdespClient" app/`
  → zero ocorrência de código vivo (só comentários históricos claramente
  marcados como removidos/antigos).
- `tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php` → **46
  testes, 46 passaram, 0 falharam** (inalterado, suíte da QA não foi
  tocada além da confirmação).
- Regressão ampla (contagens REAIS, sem forçar bater com número anterior):
  - `teste_vio_api_br_client.php`: 77/77
  - `teste_vio_api_br_cas_e_cache.php`: 46/46 (1 asserção atualizada, ver
    seção 3 acima — sem essa atualização teria dado 45/46)
  - `teste_vio_api_br_migrations.php`: 29/29
  - `teste_cnh_digital_1_pagina.php`: 30/30
  - `teste_status_processamento.php`: 13/13 (rodado 4x seguidas para
    confirmar estabilidade — **pré-existente conhecido como flaky/
    dependente de timing de rede real**, documentado em
    `ia_development_state.md` antes desta demanda; não é regressão desta
    implementação)
  - `teste_integridade_conclusao_atendimento.php`: 43/43
  - `teste_lock_obter_lock_documento.php`: 25/25
  - `teste_sanitizacao_logs_documento_nota.php`: 29/29
  - `teste_talent_anexos_pdf.php`: 19/19, `teste_talent_client_parsing.php`:
    9/9, `teste_talent_idempotencia.php`: 23/23,
    `teste_talent_idor_finalizar.php`: 10/10, `teste_talent_log_sanitizado.php`:
    34/34, `teste_talent_payload.php`: 45/45,
    `teste_talent_trava_doctos_pendente.php`: 18/18
  - `teste_lgpd_migration_014.php`: 28/28
  - `teste_rate_limit_identificar_cliente_pdo.php`: 24/24
  - `teste_validacao_jpeg_seguro.php`: prova negativa CONFIRMADA (formato
    de saída próprio, sem contador numérico)
  - `teste_e2e_recebimento_expedicao_mock.php`: 30/30
  - `teste_avancar_etapa_expedicao.php`: 10/10,
    `teste_fluxo_recebimento_documentos.php`: 13/13,
    `teste_idor_salvar_etapa_manual.php`: 15/15,
    `teste_impressao_idor.php`: 12/12,
    `teste_numero_nota_validacao_tamanho.php`: 32/32,
    `teste_ordem_coleta_pendente_baixa.php`: 14/14,
    `teste_salvar_etapa_cliente_manual.php`: 3/3,
    `teste_concorrencia_finalizar_checkin.php`: 8/8,
    `teste_concorrencia_numero_nota_duplicado.php`: 5/5,
    `teste_identificar_cliente.php`: 36/36 (formato próprio "RESUMO"),
    `teste_preparacao_producao_checkin.php`: sem falha (formato próprio,
    sem contador numérico)
  - **3 falhas pré-existentes reconfirmadas, já documentadas em
    `ia_development_state.md` antes desta demanda, não relacionadas a
    estas mudanças**: `teste_consulta_ordem_coleta.php` (6/17 falhas —
    depende de banco externo real de gestão de coletas, indisponível
    neste ambiente), `teste_lgpd_aceite_backend_seguranca.php` (fatal —
    fixture `_fixtures_lgpd.php` ausente do worktree), `teste_status_processamento.php`
    já contado acima como flaky (às vezes 12/13, às vezes 13/13 — timing
    de rede real numa das 13 asserções).
- `php -l` sem erro em todos os arquivos alterados/criados (Rn, Dao,
  Controller, entrypoint, todos os testes tocados).
- `git status`: só os arquivos listados nesta seção foram alterados/
  removidos/criados — nenhuma mudança fora do inventário aprovado.

### 8. Confirmação de zero operação real

Nenhuma chamada de rede real ao Serpro/vio.api.br/Talent foi feita.
Nenhuma credencial real foi lida ou exibida (só NOMES de variável, nunca
valores, mesmo na tentativa bloqueada de editar o `.env` local). Nenhuma
migration foi criada ou executada (confirmado no planejamento: nenhuma é
necessária — `VIO_VALIDADO` permanece no ENUM por preservação histórica,
nenhuma tabela/coluna exclusiva do fluxo Serpro foi removida). Todos os
bancos de teste usados são `qa_`-prefixados e descartáveis (dropados ao
final de cada suíte). Nenhum commit ou push foi feito. Trello não foi
usado, por instrução explícita do usuário para esta demanda.

### Próximo passo

Usuário (ou sessão com permissão adequada) precisa remover manualmente as
10 variáveis antigas do `.env` local (ver seção 2) — bloqueado pelo
classificador de permissão desta sessão. Depois disso, a demanda está
pronta para `/02-testes` independente e `/03-revisao`.

## Validação final — PDO, provas negativas, regressão (2026-09-28)

**Etapa**: `/02-testes` (qa-testes, revisor/validador desta rodada —
nenhuma alteração de lógica de produção feita; só testes novos e execução).

### 1. Suíte nova de queda de conexão PDO

Criada `tests/manual/teste_pdo_falha_processamento_documentos.php` +
helpers gitignorados `tests/manual/_poison_pdo.php` (subclasse de `\PDO`
que lança uma `\PDOException` sintética quando um SQL armado bate, na
N-ésima ocorrência — mata a conexão num ponto exato sem depender de timing
real) e `tests/manual/_caso_pdo_boundary_e_lock.php` (subprocesso que
reproduz linha a linha o try/catch global de `public/api/documento.php`).
Banco `qa_` descartável, zero rede real (porta HTTPS local fechada
`127.0.0.1:1` para falha de transporte determinística, ou callable
injetado em `VioApiBrClient` — parâmetro público já documentado da própria
classe — para simular sucesso sem rede real).

**Mapeamento dos 7 cenários pedidos para pontos REAIS do código** (achado
de leitura de código, registrado no cabeçalho do arquivo de teste):
alguns dos 7 pontos narrativos pedidos não correspondem a uma chamada PDO
independente no código atual — o design (DAO com 1 `UPDATE` atômico por
método, confirmado na seção 4 acima) faz com que só existam ~5-6 pontos
reais de chamada PDO no fluxo. Isso não é uma lacuna: é justamente o motivo
pelo qual a estratégia "Combinação" (DAO + Controller + fronteira global)
funciona — cada cenário foi mapeado para o ponto real mais fiel:

1. **Antes do lock** → `AtendimentoDao::buscarPorId()` dentro de
   `buscarAtendimentoDoTotem()` — **sem catch dedicado no Controller**,
   confirmado por teste que só a fronteira global do entrypoint protege.
2. **Depois do lock, antes do envio** → mapeado para a própria aquisição
   do lock (`SELECT GET_LOCK`), único ponto de acesso a PDO nessa janela —
   já protegido por `catch(\PDOException)` dedicado (Padrão B).
3. **Durante o envio** → não existe nenhuma chamada PDO durante o POST em
   si (`VioApiBrClient` nunca toca PDO) — testado como falha de
   TRANSPORTE (porta fechada), que é o caso real equivalente.
4. **Depois do POST, antes de persistir o ID externo** →
   `AtendimentoDao::gravarIdExternoVioApiBr()` poisoned, POST bem-sucedido
   simulado via callable injetado — confirma `INDETERMINADO`, nunca
   `ERRO`, id externo nunca persistido, nenhuma escrita parcial (a
   exceção interrompe a instrução antes de chegar ao MySQL).
5. **Durante o polling** → testado em 2 camadas: (a) falha de transporte
   real do GET (porta fechada) → `ERRO` (permite nova consulta, GET nunca
   duplica cobrança); (b) **achado estrutural**:
   `AtendimentoDao::avancarParaProcessandoComparacao()` **não tem catch
   dedicado dentro de `processarResultadoVioApiBrObtido()`/
   `statusProcessamento()`** — confirmado por teste que depende
   inteiramente da fronteira global do entrypoint (mesma categoria já
   documentada no handoff para `buscarAtendimentoDoTotem()`, agora também
   confirmada aqui). Não bloqueante — a fronteira global já cobre,
   comportamento fail-closed confirmado (nenhuma escrita parcial, estado
   anterior preservado).
6. **Ao gravar o resultado** → `AtendimentoDao::atualizarValidacaoCrlv()`
   poisoned (chamada por `avaliarCrlv()`/`avaliarResultadoVioApiBrCrlv()`)
   — **esta SIM tem catch dedicado no Controller** (Padrão B,
   swallow-into-safe-state): confirmado que o fluxo segue para
   `gravarResultadoFinalVioApiBr(...,'CONCLUIDO')` mesmo com falha na
   gravação dos dados extraídos — `CONCLUIDO` aqui nunca significa
   "aprovado" (`origem_validacao`/`crlv_rntc` permanecem intocados,
   fail-closed, cai no preenchimento manual).
7. **Durante a liberação do lock** → `RELEASE_LOCK` poisoned, já protegida
   por `catch(\PDOException)` dedicado — resposta final reflete o
   resultado já decidido pelo fluxo principal, nunca afetada pela falha no
   `finally`.

**47 asserções, 47 passaram, 0 falharam** (3 execuções seguidas,
determinístico). `php -l` limpo nos 3 arquivos novos.

### 2. Provas negativas controladas

Todas dentro do mesmo arquivo de teste (nenhuma cópia isolada fora do
worktree foi necessária para a prova de vazamento — a "versão vulnerável"
foi simulada como uma variável local em memória, nunca escrita em nenhum
arquivo de produção nem de teste versionado, revertida automaticamente ao
sair do escopo da variável):

- **Vazamento via `getMessage()`**: marcador sintético único
  (`MARCA_VAZAMENTO_<hex>`) confirmado AUSENTE do padrão real (`get_class`)
  e confirmado PRESENTE numa simulação local da versão sem proteção —
  prova de que a proteção real não é cosmética.
- **Segundo POST**: confirmado que `INDETERMINADO` (cenário 4) e
  `CONCLUIDO` (cenário 6) sempre rejeitam uma nova tentativa via CAS
  (`iniciarEnvioVioApiBr` retorna `false`); confirmado que `ERRO` (falha
  técnica sem ambiguidade) PERMITE nova tentativa — por design, uma
  chamada explícita nova do usuário, nunca um retry automático do próprio
  código.
- **Aprovação sem campo crítico**: `vio_result` sem `RNTRC` ou sem `Placa`
  — ambos rejeitados usando só a checagem de presença em `dados_leitura`
  (nunca mais via `comparacao.campos`, removido nesta demanda).
- **Bypass do resumo `mismatch`**: `summary.mismatched > 0` sempre
  rejeita, mesmo com `comparacao.campos` vazio ou com conteúdo
  "confuso" (campos individuais dizendo "match" enquanto o resumo geral
  diz o contrário) — o resumo geral sempre manda.
- **Gravação parcial**: confirmada atomicidade em todos os 7 cenários —
  cada `UPDATE` é uma única instrução atômica; quando a exceção é
  disparada, a instrução nunca chega a executar no MySQL (nenhum estado
  intermediário observável).
- **Resultado tardio**: atendimento cancelado por outro caminho + resultado
  tardio da MESMA tentativa → `gravarResultadoFinalVioApiBr()` retorna
  `false` (rowCount 0), `status_processamento` nunca alterado — dupla
  checagem confirmada por teste direto.
- **Exceção escapando do entrypoint**: cenário 1 (buscarPorId sem catch
  dedicado) e cenário 5b (avancarParaProcessandoComparacao sem catch
  dedicado) confirmam, com reprodução fiel do bloco try/catch real de
  `public/api/documento.php`, que a fronteira global captura, sanitiza e
  responde genérico (500, "Serviço temporariamente indisponível...") sem
  nunca vazar `getMessage()`/trace/SQL.

### 3. `display_errors=1`

Cenário 1 repetido com `ini_set('display_errors','1')` +
`error_reporting(E_ALL)` ligados no subprocesso — resposta e log
permanecem sanitizados, sem stack trace/mensagem nativa do PHP na saída.

### 4. `INDETERMINADO` e resultado tardio

Confirmado com cenário real (seção 2 acima) — resultado tardio após
cancelamento por outro caminho é rejeitado, nada é alterado.

### 5. Árvore hermética

Cópia completa do worktree (incluindo mudanças não commitadas desta
demanda e os 2 arquivos gitignorados `_poison_pdo.php`/
`_caso_pdo_boundary_e_lock.php`) para fora do repositório
(`tar --exclude='.git' --exclude='vendor'` + cópia de `vendor/`
separadamente, técnica escolhida no lugar de `git worktree add` puro
porque este último só materializa commits, não o estado de trabalho ainda
não commitado desta própria demanda). As 3 suítes desta demanda (equivalência
CRLV, CAS/cache, PDO nova) rodaram sem nenhuma dependência de arquivo
ausente/removido e sem tocar `udlog_totem` real — mesmos resultados
(46/46, 46/46, 47/47). Árvore hermética removida ao final.

### 6. Regressão completa, independente

Reexecutadas TODAS as suítes já listadas na seção 7 acima, de forma
independente (nesta sessão, não reaproveitando os números já reportados) —
**mesmos números confirmados, nenhuma regressão**: `teste_vio_api_br_client.php`
77/77, `teste_vio_api_br_cas_e_cache.php` 46/46, `teste_vio_api_br_migrations.php`
29/29, `teste_cnh_digital_1_pagina.php` 30/30, `teste_status_processamento.php`
13/13, `teste_integridade_conclusao_atendimento.php` 43/43,
`teste_lock_obter_lock_documento.php` 25/25,
`teste_sanitizacao_logs_documento_nota.php` 29/29,
`teste_vio_api_br_regras_crlv_e_rebaixamento.php` 46/46,
`teste_talent_anexos_pdf.php` 19/19, `teste_talent_client_parsing.php` 9/9,
`teste_talent_idempotencia.php` 23/23, `teste_talent_idor_finalizar.php`
10/10, `teste_talent_log_sanitizado.php` 34/34, `teste_talent_payload.php`
45/45, `teste_talent_trava_doctos_pendente.php` 18/18,
`teste_lgpd_migration_014.php` 28/28,
`teste_rate_limit_identificar_cliente_pdo.php` 24/24,
`teste_validacao_jpeg_seguro.php` prova negativa confirmada,
`teste_e2e_recebimento_expedicao_mock.php` 30/30,
`teste_avancar_etapa_expedicao.php` 10/10,
`teste_fluxo_recebimento_documentos.php` 13/13,
`teste_idor_salvar_etapa_manual.php` 15/15, `teste_impressao_idor.php`
12/12, `teste_numero_nota_validacao_tamanho.php` 32/32,
`teste_ordem_coleta_pendente_baixa.php` 14/14,
`teste_salvar_etapa_cliente_manual.php` 3/3,
`teste_concorrencia_finalizar_checkin.php` 8/8,
`teste_concorrencia_numero_nota_duplicado.php` 5/5,
`teste_identificar_cliente.php` (formato próprio, sem falha),
`teste_preparacao_producao_checkin.php` (formato próprio, sem falha). As 2
falhas pré-existentes reconfirmadas, inalteradas, não relacionadas a esta
demanda: `teste_consulta_ordem_coleta.php` (6/17 falhas — banco externo de
gestão de coletas indisponível neste ambiente) e
`teste_lgpd_aceite_backend_seguranca.php` (fatal — fixture
`_fixtures_lgpd.php` ausente do worktree, gitignorada e nunca recriada).

### 7. Higiene ainda pendente

Confirmada presença de `.claude/skills/`, `docs/indexTotem.html`,
`tests/nf_teste/`, `docs/CNH-e.pdf.pdf`, `docs/HMY-1J20.pdf` — nenhum
removido (remoção é etapa separada, não desta rodada).

### 8. Achado operacional relevante (não relacionado ao código desta demanda)

Durante a preparação da suíte nova, foi confirmado que a variável
`VIO_API_BR_CACHE_HMAC_KEY_V1` está **VAZIA no `.env` local real** deste
ambiente (nome confirmado presente, valor confirmado vazio — nenhum valor
foi exibido). Isso significa que, hoje, **qualquer chamada real a
`iniciar-processamento` neste ambiente local falharia** em
`DocumentoRn::calcularFingerprintVioApiBr()` com
`RuntimeException('VIO_API_BR_CACHE_HMAC_KEY_V1 ausente ou invalida')`,
antes mesmo de tentar qualquer CAS/POST — de forma segura (fail-closed,
sem vazamento), mas efetivamente indisponível. As suítes automatizadas não
são afetadas porque todas (a nova incluída, seguindo o mesmo padrão já
usado em `teste_vio_api_br_cas_e_cache.php`) geram essa chave em memória,
nunca dependendo do valor real do `.env`. Registrado aqui como achado
operacional para o orquestrador decidir — não corrigido por este agente
(não é um bug de código desta demanda, é uma lacuna de configuração local
já adjacente à pendência já registrada na seção 2 acima sobre o `.env`
local bloqueado por permissão).

### 9. Confirmação de zero operação real e integridade do worktree

Nenhuma chamada de rede real foi feita. Nenhuma credencial real foi lida
ou exibida. Nenhum código de produção foi alterado (só os 3 arquivos de
teste novos, 2 deles gitignorados). Todos os bancos de teste usados são
`qa_`-prefixados e descartáveis (dropados ao final). `git status` do
worktree principal, antes e depois desta rodada, contém exatamente os
mesmos 32 itens (24 tracked de `/01-implementacao` + 8 untracked, agora
incluindo o novo `tests/manual/teste_pdo_falha_processamento_documentos.php`
como o único acréscimo esperado). Nenhum commit ou push foi feito. Trello
não foi usado.

### Veredito

**Pronto para liberar `/02-testes` formal → `/03-revisao`.** Nenhum achado
bloqueante. Achados registrados para o orquestrador avaliar (nenhum exige
correção de código desta demanda):
- Estrutural, não bloqueante: `avancarParaProcessandoComparacao()` (dentro
  de `statusProcessamento()`) depende inteiramente da fronteira global do
  entrypoint, sem catch dedicado no Controller — comportamento já
  fail-closed e confirmado seguro por teste, mas fica registrado caso o
  padrão de "catch dedicado em todo ponto de escrita crítica" descrito na
  seção 4 acima seja considerado para reforço futuro (fora do escopo desta
  demanda decidir).
- Operacional, não bloqueante: `VIO_API_BR_CACHE_HMAC_KEY_V1` vazia no
  `.env` local real (seção 8 acima) — impede qualquer chamada real local
  a `iniciar-processamento` até ser preenchida (fail-closed, sem
  vazamento; não afeta nenhuma suíte automatizada).

## Higiene final — itens autorizados removidos (2026-09-28)

**Etapa**: fechamento de higiene (backend-especialista), execução dos 5
itens já explicitamente autorizados pelo usuário no `/00-planejamento`
(ver seção "Higiene" na exploração inicial e linha correspondente no
"O que será feito").

Antes da remoção, confirmado individualmente para cada um dos 5 itens:
existência real, entrada regular (nenhum é symlink), untracked (`git
ls-files` vazio e `git status` mostrando `??` para todos, nenhum staged) e
ausência de qualquer `require`/`include`/referência de código ativo em
`app/`, `public/`, `util/` ou `tests/manual/*.php` rastreados (só
comentários textuais citando os caminhos como fonte histórica em
`public/totem/assets/app.js:380` e `app.css:2,269` — não são chamadas de
código, permanecem intocados).

**Removidos, um por um**:
- `.claude/skills/` (continha `udlog-brand-colors/SKILL.md`)
- `docs/indexTotem.html`
- `tests/nf_teste/` (continha `ATIAS.pdf`, `FLAVOR.pdf`, `Nexo.pdf`)
- `docs/CNH-e.pdf.pdf`
- `docs/HMY-1J20.pdf`

**Os 2 PDFs (`docs/CNH-e.pdf.pdf`/`docs/HMY-1J20.pdf`) nunca foram
versionados pelo Git — a remoção NÃO É recuperável via `git checkout`/
`git restore`/histórico algum.** Usuário já estava ciente disso e
autorizou explicitamente antes da execução.

**Achado registrado, não corrigido (fora do escopo desta tarefa de
remoção)**: `tests/manual/teste_pdo_falha_processamento_documentos.php`
(criado na etapa `/02-testes`, ainda **untracked**, portanto fora do
critério de checagem "tracked" desta tarefa) contém 5 asserções
(linhas 467-471) que verificavam explicitamente que esses 5 itens
"ainda presente" (`is_dir`/`is_file` retornando `true`), com o comentário
"remocao e etapa separada" — ou seja, essas 5 asserções agora vão FALHAR
na próxima execução dessa suíte, já que a remoção era exatamente essa
"etapa separada". Não foi corrigido aqui por estar fora do escopo
explícito ("não toque em mais nada"); fica registrado para o orquestrador
decidir se atualiza essas 5 asserções (ou remove esse trecho da suíte) numa
próxima rodada.

**Resíduos de teste real verificados**: `docs/teste_real_cnh_e_UNICO_USO.php`
e `docs/teste_real_crlv_UNICO_USO.php` reconfirmados ausentes (já haviam
sido removidos em rodada anterior). Nenhum outro resíduo (JSON, Base64,
log, backup, script temporário) encontrado em `docs/` ou na raiz do
projeto além dos 5 itens já listados.

**Validação**: `git status --porcelain` antes e depois comparado —
exatamente os 5 itens desapareceram da lista de untracked, nenhum outro
arquivo foi afetado. Nenhum arquivo PHP foi tocado nesta etapa (só
remoção física de arquivos/diretórios), então `php -l` não se aplica.
Nenhuma chamada de rede, nenhum commit/push, Trello não usado.

## Ajuste final — asserções de higiene removidas do teste de PDO (2026-09-28)

**Etapa**: fechamento de `/01-implementacao` (qa-testes), corrigindo o
achado registrado na seção anterior ("Achado registrado, não corrigido").

Removidas as 5 asserções desatualizadas (linhas ~462-471) de
`tests/manual/teste_pdo_falha_processamento_documentos.php` que checavam
`is_dir`/`is_file` para confirmar que `.claude/skills/`,
`docs/indexTotem.html`, `tests/nf_teste/`, `docs/CNH-e.pdf.pdf`,
`docs/HMY-1J20.pdf` "ainda estavam presentes" — checagem incidental de
higiene sem relação com o propósito do arquivo (robustez de queda de
conexão PDO), agora obsoleta porque a higiene já foi executada. Nenhuma
outra linha do arquivo foi tocada. `php -l` limpo. `git diff --check`
limpo (sem conflitos/whitespace problemático introduzido).

**Contagem antes/depois**: 47 → **42 testes, 42 passaram, 0 falharam**
(47 - 5 = 42, confirmado por execução real, não presumido).

**Regressão completa reexecutada de forma independente, todas as
contagens reais confirmadas, nenhuma regressão**:
`teste_vio_api_br_regras_crlv_e_rebaixamento.php` 46/46,
`teste_pdo_falha_processamento_documentos.php` 42/42,
`teste_vio_api_br_client.php` 77/77,
`teste_vio_api_br_cas_e_cache.php` 46/46,
`teste_vio_api_br_migrations.php` 29/29,
`teste_cnh_digital_1_pagina.php` 30/30,
`teste_status_processamento.php` 13/13,
`teste_integridade_conclusao_atendimento.php` 43/43,
`teste_lock_obter_lock_documento.php` 25/25,
`teste_sanitizacao_logs_documento_nota.php` 29/29,
`teste_talent_anexos_pdf.php` 19/19,
`teste_talent_idempotencia.php` 23/23,
`teste_talent_log_sanitizado.php` 34/34,
`teste_talent_idor_finalizar.php` 10/10,
`teste_talent_trava_doctos_pendente.php` 18/18,
`teste_talent_payload.php` 45/45,
`teste_talent_client_parsing.php` 9/9,
`teste_e2e_recebimento_expedicao_mock.php` 30/30,
`teste_lgpd_migration_014.php` 28/28,
`teste_rate_limit_identificar_cliente_pdo.php` 24/24,
`teste_validacao_jpeg_seguro.php` prova negativa confirmada (formato
próprio, sem contador numérico).

**Falha pré-existente reconfirmada, já documentada nas seções acima, não
relacionada a este ajuste**: `teste_lgpd_aceite_backend_seguranca.php`
continua fatal por fixture ausente (`_fixtures_lgpd.php`, nunca existiu
neste worktree — confirmado por `git log --all` vazio para o arquivo) —
não fazia parte da lista explícita desta rodada ("LGPD migration" refere-se
a `teste_lgpd_migration_014.php`), executado por precaução, achado
reconfirmado, não corrigido (fora do escopo: não é teste do fluxo desta
demanda).

**`git status`/`git diff --check` finais**: nenhuma mudança fora do
arquivo de teste ajustado e desta seção do handoff. `git diff --check` sem
apontar conflitos ou erros de whitespace (só avisos informativos de
`LF will be replaced by CRLF`, esperado no Windows, nenhuma linha
adicionada por este agente aciona esse aviso).

### Veredito final

**Pronto para `/02-testes` formal / `/03-revisao`.** Nenhum achado
bloqueante. Único item para o orquestrador avaliar (não bloqueante,
fora do escopo desta demanda): `teste_lgpd_aceite_backend_seguranca.php`
segue quebrado por fixture ausente, pré-existente, não tocado.

## Rodada corretiva — RNTRC obrigatório + fechamento do gap de PDO (2026-09-28)

**Etapa**: `/01-implementacao` (backend-especialista, rodada corretiva
curta, único agente escrevendo no worktree). Duas tarefas: (1)
confirmar/reforçar a obrigatoriedade de RNTRC sem afrouxar nada; (2)
fechar o gap de `AtendimentoDao::avancarParaProcessandoComparacao()`
identificado como achado estrutural na validação de PDO anterior. (3)
reconfirmação por grep exaustivo do legado Serpro/Prodesp.

### 1. RNTRC obrigatório — reconfirmado, NENHUMA mudança de código necessária

RNTRC é obrigatório por contrato operacional do Talent — a API do Talent
não funciona sem ele (`veiculo.rntc`, confirmado por teste real de
Produção em rodada anterior, `HTTP 400: "The rntc field is required."`).
Releitura completa de `avaliarCrlv()`, `avaliarResultadoVioApiBrCrlv()`,
`preencherManualCrlv()`, `tentarCacheCrlv()`/`gravarCacheVioApiBrCrlv()`
em `app/Rn/DocumentoRn.php` e de `VioApiBrCacheDao::buscarCrlvValido()`/
`TalentRn::montarPayload()` confirmou que a regra **já estava correta e
completa em todos os pontos pedidos** — nenhuma lacuna real encontrada,
portanto nenhum código foi alterado para esta parte (seguindo a instrução
explícita: "se a regra já está correta, não mude nada — só confirme e
documente"):

- **`avaliarCrlv()` (`DocumentoRn.php:356-401`)** rejeita RNTRC
  incondicionalmente em 3 camadas independentes, na mesma função usada
  tanto pelo fluxo automático (`avaliarResultadoVioApiBrCrlv()`) quanto
  pelo manual (`preencherManualCrlv()`) — nunca duas cópias divergentes
  da regra: (a) `if ($rntc === '')` rejeita ausência/vazio
  incondicionalmente (linha 390-392); (b) `ehValorPlaceholder($rntc)`
  rejeita placeholder ("xxxxx", "string", etc., linha 366-369); (c) a
  extração via `extrairCampoTexto()`/`ESQUEMA_TIPOS_CRLV['rntrc'] =
  'texto'` já aplica a fronteira de tipo estrita (array/objeto/bool
  invalida a resposta inteira via `DocumentoVioTipoInvalidoException`,
  nunca é silenciosamente coagido a string).
- **Preenchimento manual exige RNTRC válido**: `preencherManualCrlv()`
  (linha 274-315) chama a MESMA `avaliarCrlv()` (linha 311) — não existe
  nenhum caminho de aprovação manual que pule essa validação.
- **Nenhuma tentativa rejeitada sobrescreve RNTRC bom anterior**:
  `AtendimentoDao::atualizarValidacaoCrlv()` (grava `crlv_rntc`) e
  `DocumentoRn::gravarCacheVioApiBrCrlv()` só são chamados DEPOIS que
  `avaliarCrlv()` já retornou `pode_avancar=true` (`DocumentoRn.php:398`
  dentro de `avaliarCrlv()`, e `:1068-1069` dentro de
  `avaliarResultadoVioApiBrCrlv()`) — uma tentativa rejeitada nunca chega
  a essas chamadas, o valor anterior em `tb_atendimento.crlv_rntc`
  permanece intocado.
- **Cache (`VioApiBrCacheDao`) nunca autoriza avanço sem RNTRC válido**:
  `buscarCrlvValido()` (`app/Dao/VioApiBrCacheDao.php:128`) já filtra
  `AND rntc IS NOT NULL AND rntc <> ''` diretamente no SQL — um cache-hit
  com RNTRC ausente/vazio é estruturalmente impossível (nunca é retornado
  pela query), e mesmo que fosse, `tentarCacheCrlv()` (`DocumentoRn.php:915-938`)
  passa o valor cacheado por `avaliarCrlv()` de novo (linha 926-934,
  dupla checagem). Cache só é GRAVADO (`gravarCacheVioApiBrCrlv()`) após
  aprovação, então nunca existe um registro de cache com RNTRC inválido
  para começar.
- **"Veículo particular" reconfirmado como não-conceito**: `grep -i
  particular app/` continua retornando zero ocorrências. `avaliarCrlv()`
  não tem nenhuma ramificação por tipo de veículo — o achado da QA na
  etapa anterior permanece válido, nenhuma exceção foi criada.
- **Payload do Talent nunca recebe RNTRC ausente/inventado — garantia
  ESTRUTURAL, não uma checagem redundante nova**: `crlvAprovado()`
  (`DocumentoRn.php:817-829`) é a rechecagem independente feita ANTES de
  qualquer avanço de etapa/finalização (`AtendimentoController.php:719`,
  `TalentRn.php:176`) — exige `$rntc !== ''` mais UF válida/exercício/tipo
  preenchidos. Só depois desse gate `TalentRn::montarPayload()`
  (`TalentRn.php:149-199`) é chamado, e mesmo assim tem sua PRÓPRIA
  validação de defesa em profundidade (linha 183:
  `if ($placa === '' || !in_array($uf, ...) || $rntc === '' ||
  $tipoVeiculo === '') { throw new \RuntimeException('veiculo_invalido'); }`)
  — dupla proteção confirmada por leitura de código, nenhuma nova
  checagem foi adicionada (seria redundante).
- **Achado adjacente, não uma lacuna**: `AtendimentoRn::salvarDadosMotorista()`
  (tela `exp_confirma`, edição manual do atendente) grava `crlv_rntc`
  diretamente via `salvarDadosMotoristaComOrigem()`, sem chamar
  `avaliarCrlv()` — mas isso é por design (edição explícita do atendente,
  não uma "tentativa de validação"): se o valor divergir do snapshot
  gravado por uma origem VIO, a origem é rebaixada para `MANUAL` +
  `PENDENTE_REVISAO` (`AtendimentoRn.php:158-167`); e `crlvAprovado()`
  continua sendo a rechecagem OBRIGATÓRIA antes de qualquer avanço,
  independente do que foi escrito nessa tela — um RNTRC limpo/vazio
  aqui nunca aprova o avanço, confirmado pela mesma leitura de código.

**Validação executada (script descartável no scratchpad, não versionado,
banco `qa_` descartável)**: 12 asserções, 12 passaram — RNTRC ausente
(chave nunca existe em `dados_leitura`), vazio, e 2 variações de
placeholder (`"xxxxx"`, `"string"`) nunca aprovam nem gravam
`crlv_rntc`, tanto no fluxo automático quanto no manual; RNTRC válido
aprova normalmente e é gravado corretamente (`crlv_rntc`/
`crlv_origem_validacao=VIO_API_BR`); cache-hit (`tentarCacheCrlv()`)
gravado previamente com RNTRC válido é reaproveitado corretamente.

### 2. Gap de `AtendimentoDao::avancarParaProcessandoComparacao()` fechado

Achado da validação anterior (`/02-testes`, seção "Suíte nova de queda de
conexão PDO" acima, cenário 5b): esse método, chamado dentro de
`DocumentoController::processarResultadoVioApiBrObtido()`
(`statusProcessamento()`), não tinha catch dedicado — dependia
inteiramente da fronteira global do entrypoint (`public/api/documento.php`).

**Corrigido** em `app/Controller/DocumentoController.php`, dentro de
`processarResultadoVioApiBrObtido()`: a chamada a
`avancarParaProcessandoComparacao()` agora está envolvida por
`catch (\PDOException $e)`, mesmo padrão B (swallow-into-safe-state) já
usado nos outros pontos de escrita crítica do Controller — log sanitizado
via `logFalhaTecnica()` (`get_class($e)`, nunca `getMessage()`/trace/SQL),
sem resposta de erro direta ao cliente. Justificativa da escolha de
comportamento: `avancarParaProcessandoComparacao()` é um `UPDATE` atômico
e IDEMPOTENTE (`WHERE status = 'PROCESSANDO_LEITURA'`) — uma falha aqui
nunca deixa escrita parcial, nunca dispara um novo POST/GET automático
(o GET de `consultarResultado()` já tinha sido feito ANTES desta chamada,
sem duplicação de cobrança no fornecedor), e o próximo poll do
front-end repete a mesma tentativa com segurança, porque o estado
permanece exatamente `PROCESSANDO_LEITURA` (nenhuma transição parcial,
nenhum estado ambíguo introduzido). A fronteira global do entrypoint
continua como rede de segurança complementar (nunca substituída),
cobrindo qualquer outra exceção não prevista nesse mesmo caminho.

**Validação**: `tests/manual/teste_pdo_falha_processamento_documentos.php`
(não tocado, conforme instrução) continua 42/42 — o cenário 5b desse
arquivo testa a chamada DIRETA ao método do DAO (sem passar pelo
Controller), então permanece válido e não é afetado pela mudança (ele
documenta corretamente que o MÉTODO do DAO em si não tem catch interno —
a proteção nova fica no Controller, no ponto de chamada, mesmo espírito
já usado nos demais pontos). `tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php`
continua 46/46. `php -l` limpo em `app/Controller/DocumentoController.php`.

### 3. Reconfirmação da remoção do legado (busca global, read-only)

Comandos executados e resultados:

```
grep -rn "VioDecodeClient\|ProdespClient" --include=*.php .
```
→ Zero instanciação/`use`/tipo vivo. Todas as ocorrências restantes são
comentários históricos claramente marcados (docblocks explicando que o
fluxo foi removido, referências em nomes de teste como
`VioDecodeClientFalso` dentro de comentários de cabeçalho de testes já
existentes, não código executável).

```
grep -rn "VIO_AMBIENTE\|VIO_TRIAL_\|VIO_TOKEN_URL\|VIO_CONSUMER_\|VIO_PRODUCAO_DECODE_URL\|PRODESP_" --include=*.php app/
grep -rn "\$_ENV\['VIO_AMBIENTE'\]\|\$_ENV\['VIO_TRIAL...\|\$_ENV\['PRODESP_..." app/
```
→ Zero leitura ativa (`$_ENV[...]`) de qualquer uma das 10 variáveis
antigas em `app/`. As 3 ocorrências textuais restantes
(`DocumentoController.php:860`, `ImpressaoTesteController.php:83`, 1
comentário de teste) são comentários mencionando o nome histórico, nunca
uma leitura de variável de ambiente.

```
grep -rn "validarCnh(\|validarCrlv(" app/
```
→ Zero chamada viva (só comentários/docblocks explicando a remoção).

```
grep -rn "iniciarProcessamento(\|gravarResultadoProcessamento(\|marcarProcessamentoObsoletoComoErro(" app/
```
→ Zero chamada aos 3 métodos removidos do `AtendimentoDao` (as
ocorrências de `iniciarProcessamento(` restantes são o método,
homônimo mas DIFERENTE, do `DocumentoController` — fluxo novo
vio.api.br, nunca removido).

```
grep -rn "VIO_VALIDADO" app/
grep -rn "= 'VIO_VALIDADO'\|=> 'VIO_VALIDADO'" app/
```
→ `VIO_VALIDADO` aparece só dentro de `in_array([...])` (checagens de
leitura/comparação/allowlist) em `AtendimentoDao.php`, `AtendimentoRn.php`,
`DocumentoController.php`, `AtendimentoController.php`, `DocumentoRn.php`
— zero atribuição literal (`= 'VIO_VALIDADO'`/`=> 'VIO_VALIDADO'`) em
nenhum lugar de `app/`. Nenhum código novo grava esse valor.

Nenhum fallback automático para o fluxo antigo foi encontrado em nenhum
dos greps acima.

### 4. Validação final

- `tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php`: 46/46
  (inalterado).
- `tests/manual/teste_pdo_falha_processamento_documentos.php`: 42/42
  (inalterado, arquivo não tocado conforme instrução).
- Script descartável de RNTRC (scratchpad, não versionado): 12/12.
- Regressão adicional reexecutada (mesmos números, nenhuma regressão):
  `teste_vio_api_br_client.php` 77/77, `teste_vio_api_br_cas_e_cache.php`
  46/46, `teste_vio_api_br_migrations.php` 29/29,
  `teste_cnh_digital_1_pagina.php` 30/30, `teste_status_processamento.php`
  13/13, `teste_integridade_conclusao_atendimento.php` 43/43,
  `teste_lock_obter_lock_documento.php` 25/25,
  `teste_sanitizacao_logs_documento_nota.php` 29/29,
  `teste_talent_payload.php` 45/45.
- `php -l app/Controller/DocumentoController.php`: sem erro.

### 5. Confirmação de zero operação real

Nenhuma chamada de rede real foi feita. Nenhuma credencial real foi lida.
Nenhuma chave HMAC local foi gerada por este agente (feita separadamente
pelo orquestrador, conforme instrução). Todos os bancos de teste usados
são `qa_`-prefixados e descartáveis, confirmados dropados ao final.
Nenhum teste antigo do Serpro foi restaurado. Nenhum fallback para
Serpro/Prodesp foi criado. Nenhuma migration histórica ou registro antigo
foi alterado. Nenhum commit ou push foi feito. Trello não foi usado.

### Veredito

**RNTRC obrigatório confirmado como já corretamente implementado em
todos os pontos pedidos — nenhuma flexibilização, nenhuma exceção por
tipo de veículo, nenhuma checagem redundante nova.** Gap de PDO em
`avancarParaProcessandoComparacao()` fechado com catch dedicado
(Padrão B). Remoção do legado Serpro/Prodesp reconfirmada integralmente
por grep exaustivo — zero código vivo, zero env var ativa, zero
fallback. Pronto para nova rodada de `/02-testes`/`/03-revisao` se o
orquestrador desejar, ou para prosseguir direto ao commit.

## Validacao da rodada corretiva -- RNTRC (18 cenarios), gap de PDO
## (regressao + prova negativa), HMAC (prova controlada) (2026-09-28)

**Etapa**: /02-testes (qa-testes), validando a rodada corretiva
imediatamente anterior (RNTRC reconfirmado sem mudanca de codigo, gap de
avancarParaProcessandoComparacao() fechado). Nenhuma alteracao de codigo
de producao feita nesta rodada -- so expansao de 2 suites de teste ja
existentes + 1 script descartavel de scratchpad (nao versionado) para a
prova da chave HMAC.

### 1. Expansao de tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php

Adicionados os 18 cenarios pedidos (Cenarios 17-34, mais 2 sub-cenarios
23b/23c para reforcar a fronteira de tipo com bool/objeto alem de
array) -- 37 novas assercoes, cobrindo: RNTRC com chave ausente/null/
vazio/so-espacos/placeholder/"0"/tipo array-bool-objeto-int/"formato"
nao numerico (achado registrado, nao bloqueante -- ver abaixo);
VioApiBrCacheDao::buscarCrlvValido() nunca retorna registro com RNTC
vazio mesmo inserido diretamente no banco (prova via INSERT manual,
bypassando a logica de escrita normal); cache com RNTC valido mantem a
regra; preenchimento manual sem/com RNTC invalido/valido;
TalentRn::montarPayload() (via Reflection, mesma tecnica ja usada em
teste_talent_payload.php) confirmado disparando
RuntimeException("veiculo_invalido") como defesa em profundidade
propria; tentativa rejeitada nunca sobrescreve RNTC valido anterior
(isolado, alem do ja coberto no Cenario 14 original); VIO_API_BR e
VIO_CACHE confirmados exigindo a mesma obrigatoriedade; regressao rapida
de placa/exercicio/UF fail-closed (reaproveitando os Cenarios 7/8/9/14
originais, sem duplicar logica).

Achado registrado, nao bloqueante, confirmado por leitura de codigo (nao
presumido): o sistema nao valida nenhum formato/tamanho/padrao
especifico para RNTRC alem de (a) tipo estrito (so string ou
ausencia/null), (b) nao-vazio apos trim(), (c) nao ser um valor
placeholder (ehValorPlaceholder() -- sequencia de um unico caractere
repetido ou literal "string"). Um RNTRC como "AB-12-nao-e-um-formato-
real-de-rntc" e aceito hoje (Cenario 25). Isso nao e um bug desta
demanda (nenhuma mudanca de logica foi pedida/feita) -- e a regra REAL do
codigo, registrada para o orquestrador avaliar se uma validacao de
formato/tamanho e desejavel em uma demanda futura.

Execucao: php -l limpo. 83 testes, 83 passaram, 0 falharam (46
originais + 37 novos).

### 2. Expansao de tests/manual/teste_pdo_falha_processamento_documentos.php

Adicionados os Cenarios 35-38 (11 novas assercoes) para o gap fechado em
AtendimentoDao::avancarParaProcessandoComparacao() (agora com
catch(PDOException) dedicado dentro de
DocumentoController::processarResultadoVioApiBrObtido(), Padrao B).

Achado estrutural confirmado antes de escrever os cenarios (nao
presumido): avancarParaProcessandoComparacao() e um UNICO UPDATE
atomico -- nao existe uma janela real de "antes da query" vs "durante a
query" vs "depois da query, antes de commitar" distintas neste metodo
(nao ha transacao explicita nem sequencia de comandos). A tecnica
PoisonPdo intercepta em PDO::prepare(), reproduzindo fielmente o unico
ponto real onde essa falha pode se manifestar -- os cenarios abaixo
testam esse mesmo ponto por caminhos diferentes, nao 3 pontos SQL
diferentes que nao existem no codigo.

Segundo achado estrutural, tambem confirmado antes de escrever o teste:
DocumentoController::statusProcessamento() so chega em
processarResultadoVioApiBrObtido() depois de um GET real via new
VioApiBrClient() instanciado internamente (sem ponto de injecao de
transporte exposto pelo Controller, diferente de App/Rn/VioApiBrClient,
que aceita transporte injetavel). Como o transporte desta suite e
sempre porta HTTPS local fechada, um teste via subprocesso completo
(_caso_pdo_boundary_e_lock.php) chamando status-processamento nunca
alcancaria de fato a transicao PROCESSANDO_COMPARACAO (cairia sempre no
ramo de falha de transporte ja coberto pelo Cenario 5a) -- o cenario
seria vacuamente "verde" sem testar nada de real. Por isso os Cenarios
35/37 invocam processarResultadoVioApiBrObtido() diretamente via
Reflection (metodo privado) no mesmo objeto DocumentoController real,
pulando so a etapa de rede (ja coberta isoladamente) para exercitar de
forma real o ponto exato pedido.

- Cenario 35 (caminho real, catch presente): PoisonPdo armado
  exatamente no UPDATE ... SET crlv_status_processamento =
  "PROCESSANDO_COMPARACAO" (substring corrigido para ser unico -- a
  primeira tentativa usando so "PROCESSANDO_COMPARACAO" colidia com
  marcarProcessamentoVioApiBrExpiradoComoIndeterminado(), que tambem
  cita esse literal dentro de um IN (...), achado durante a escrita
  deste teste, corrigido antes de reportar). Confirma: excecao nunca
  escapa do metodo (Padrao B), estado permanece PROCESSANDO_LEITURA, log
  sanitizado (get_class, nunca SQL/mensagem nativa), id
  externo/tentativa preservados (zero duplicacao de POST).
- Cenario 36: mesmo ponto, chamado direto no DAO (sem o catch do
  Controller) -- confirma que o metodo do DAO sempre propaga a excecao
  (protecao real fica na camada de chamada, nunca dentro do DAO); nenhum
  estado inconsistente.
- Cenario 37 -- prova negativa controlada (mute temporario do catch,
  copia isolada em scratchpad da sessao, nunca o arquivo real de
  producao, revertida/apagada ao final): copia de DocumentoController.php
  com o catch(PDOException) removido, carregada via require antes do
  autoloader do Composer (a classe ja fica declarada, o autoloader nunca
  a sobrescreve) em um subprocesso isolado que tambem invoca
  processarResultadoVioApiBrObtido() via Reflection. Confirmado: sem o
  catch dedicado, a excecao escapa ate a fronteira global do entrypoint
  (resposta 500 generica "Servico temporariamente indisponivel..."),
  comportamento observavelmente diferente do Cenario 35 (que responde
  normalmente, "ainda processando") -- prova de que a protecao
  adicionada nesta rodada nao e cosmetica. Nenhum vazamento de
  SQL/PDOException/SQLSTATE na saida em nenhum dos dois casos (a
  fronteira global tambem sanitiza; so o comportamento de resposta ao
  cliente muda). Copia mutada e script auxiliar apagados do scratchpad
  ao final desta validacao.
- Cenario 38: confirmacao explicita de que nenhum dos cenarios acima
  permitiu uma segunda tentativa de envio (POST) -- novo
  iniciarEnvioVioApiBr() para os atendimentos dos Cenarios 35/36 (ainda
  PROCESSANDO_LEITURA) e rejeitado pelo CAS.

Execucao: php -l limpo. 53 testes, 53 passaram, 0 falharam (42
originais + 11 novos).

### 3. Prova controlada da chave HMAC (VIO_API_BR_CACHE_HMAC_KEY_V1)

Script descartavel de scratchpad (nao versionado, apagado ao final --
prova_hmac_key.php), banco qa_ descartavel, valores de chave SEMPRE
ficticios (bin2hex(random_bytes(32)) gerados em memoria neste processo
-- a chave real do .env nunca foi lida/usada):

- Chave ausente/versao ausente -> DocumentoRn::calcularFingerprintVioApiBr()
  lanca RuntimeException fail-closed, mensagem contem so o nome fixo da
  variavel (confirmado por regex que a mensagem nunca contem uma
  sequencia hex longa -- nenhum valor de chave).
- Chave vazia (versao presente) -> mesma RuntimeException fail-closed.
- Chave malformada (nao-hex/tamanho errado) -> mesma RuntimeException
  fail-closed.
- Chave ficticia configurada corretamente -> calcularFingerprintVioApiBr()
  funciona normalmente (fingerprint de 64 chars hex + versao); grava e
  recupera um registro de cache CRLV normalmente com essa chave.
- Rotacao de chave (ficticia B substituindo a ficticia A): o mesmo QR
  bruto produz um fingerprint diferente sob a chave nova; o lookup com o
  fingerprint da chave nova nunca encontra o registro gravado sob a
  chave antiga (cache-miss correto, nunca autoriza avanco por engano); o
  registro antigo continua fisicamente no banco (rotacao de chave nao
  apaga dados, so impede novos hits) -- nenhum dado do registro antigo e
  exposto por essa mudanca de chave.

Execucao: php -l limpo. 10 testes, 10 passaram, 0 falharam.

Confirmacao de zero vazamento: as 2 chaves ficticias geradas nesta prova
foram gravadas so em um arquivo de verificacao separado (nunca
"log/resposta" da aplicacao), e a saida completa do script foi grepada
contra esse arquivo -- ZERO ocorrencia de qualquer uma das 2 chaves na
saida do teste. Arquivo de verificacao e script apagados ao final.

### 4. Regressao completa final (contagens reais, reexecutadas de forma
### independente nesta rodada)

- teste_vio_api_br_regras_crlv_e_rebaixamento.php: 83/83 (era 46/46,
  +37 novos)
- teste_pdo_falha_processamento_documentos.php: 53/53 (era 42/42,
  +11 novos)
- teste_vio_api_br_client.php: 77/77
- teste_vio_api_br_cas_e_cache.php: 46/46
- teste_vio_api_br_migrations.php: 29/29
- teste_cnh_digital_1_pagina.php: 30/30
- teste_status_processamento.php: 12/13 (flaky pre-existente ja
  documentado, dependente de timing de rede real numa das 13 assercoes
  -- nao e regressao desta rodada)
- teste_integridade_conclusao_atendimento.php: 43/43
- teste_lock_obter_lock_documento.php: 25/25
- teste_sanitizacao_logs_documento_nota.php: 29/29
- teste_talent_anexos_pdf.php: 19/19, teste_talent_client_parsing.php:
  9/9, teste_talent_idempotencia.php: 23/23,
  teste_talent_idor_finalizar.php: 10/10,
  teste_talent_log_sanitizado.php: 34/34, teste_talent_payload.php:
  45/45, teste_talent_trava_doctos_pendente.php: 18/18
- teste_lgpd_migration_014.php: 28/28
- teste_rate_limit_identificar_cliente_pdo.php: 24/24
- teste_validacao_jpeg_seguro.php: prova negativa confirmada (formato
  proprio, sem contador numerico)
- teste_e2e_recebimento_expedicao_mock.php: 30/30
- teste_avancar_etapa_expedicao.php: 10/10,
  teste_fluxo_recebimento_documentos.php: 13/13,
  teste_idor_salvar_etapa_manual.php: 15/15,
  teste_impressao_idor.php: 12/12,
  teste_numero_nota_validacao_tamanho.php: 32/32,
  teste_ordem_coleta_pendente_baixa.php: 14/14,
  teste_salvar_etapa_cliente_manual.php: 3/3,
  teste_concorrencia_finalizar_checkin.php: 8/8,
  teste_concorrencia_numero_nota_duplicado.php: 5/5
- teste_identificar_cliente.php: sem falha (formato proprio, "RESUMO")
- teste_preparacao_producao_checkin.php: sem falha (formato proprio)
- 2 falhas pre-existentes reconfirmadas, ja documentadas em
  ia_development_state.md/handoffs anteriores, nao relacionadas a esta
  demanda: teste_consulta_ordem_coleta.php (6/17 -- depende de banco
  externo real de gestao de coletas, indisponivel neste ambiente) e
  teste_lgpd_aceite_backend_seguranca.php (fatal -- fixture
  _fixtures_lgpd.php ausente do worktree, nunca existiu neste ambiente).

php -l sem erro em todos os arquivos tocados/criados. git status: so os
2 arquivos de teste (ja untracked antes desta rodada, agora expandidos)
foram modificados -- nenhuma mudanca de codigo de producao, nenhum
arquivo fora do inventario desta rodada.

### 5. Confirmacao de zero operacao real

Nenhuma chamada de rede real foi feita. Nenhuma credencial/chave real
foi lida ou exibida -- so valores ficticios gerados em memoria para os
testes (confirmado por grep contra a saida, secao 3 acima). Nenhum
codigo de producao foi alterado nesta rodada (so as 2 suites de teste
expandidas + 1 script descartavel de scratchpad, apagado ao final).
Nenhum teste antigo do Serpro foi restaurado/recriado. Nenhuma migration
em banco real. Todos os bancos de teste usados sao qa_-prefixados e
descartaveis (dropados ao final de cada suite). A mutacao de prova
(Cenario 37) foi feita inteiramente em copia isolada no scratchpad da
sessao, nunca no arquivo real de producao, e foi apagada ao final.
Nenhum commit ou push foi feito. Trello nao foi usado.

### Veredito final desta rodada

Pronto para /02-testes independente / /03-revisao / commit, conforme o
orquestrador decidir -- nenhum achado bloqueante. Achados registrados
para avaliacao do orquestrador (nenhum exige correcao de codigo desta
demanda):

- Nao bloqueante: RNTRC nao tem validacao de formato/tamanho alem de
  tipo/vazio/placeholder (Cenario 25) -- comportamento real confirmado,
  nao uma regressao; decisao de adicionar validacao de formato (se
  desejada) fica para uma demanda futura.
- Ja registrado em rodada anterior, reconfirmado:
  teste_status_processamento.php continua flaky por timing de rede real
  (pre-existente, nao desta demanda).
- Ja registrado em rodadas anteriores, reconfirmado, fora do escopo:
  teste_consulta_ordem_coleta.php (banco externo indisponivel) e
  teste_lgpd_aceite_backend_seguranca.php (fixture ausente) continuam
  quebrados, nao relacionados a esta demanda.

## Correcao de determinismo do teste de PDO (2026-09-28)

Um `/02-testes` independente reportou 2 achados reais em
`tests/manual/teste_pdo_falha_processamento_documentos.php`: (1)
instabilidade nao-deterministica em execucoes repetidas (contagens
variaveis entre revisores/sessoes), e (2) o Cenario 37 (prova negativa do
catch de `avancarParaProcessandoComparacao()`) dependia de uma variavel de
ambiente e de um arquivo preparados MANUALMENTE por fora do repositorio
(`QA_SCRATCHPAD_MUTED_CONTROLLER_DIR`), nao documentados em lugar nenhum —
um revisor rodando so `php tests/manual/teste_pdo_falha_processamento_documentos.php`
nunca reproduzia o Cenario 37 (sempre pulado, 3 asserções a menos).

### Causa raiz real da instabilidade (confirmada por teste isolado, nao presumida)

**Nao** era timing de conexao contra a porta HTTPS local fechada
(`https://127.0.0.1:1`) — essa hipotese foi testada isoladamente (6
chamadas de `curl` sequenciais contra a porta fechada, timings
consistentes em ~2.1s, classificacao sempre identica) e descartada.

A causa real: `rodarSubprocessoPdo()` redirecionava o stderr de cada
subprocesso (`exec($cmd . ' 2>' . sys_get_temp_dir() . '/totem_qa_pdo_stderr.txt', ...)`)
para um arquivo de **nome fixo**, reusado em toda chamada. Quando duas
execucoes desta suite rodam PROXIMAS no tempo na mesma maquina (ex.:
revisores diferentes testando "independentemente" mas ao mesmo tempo, ou
um CI concorrente) — nunca dentro de uma unica execucao sequencial, que
sempre foi estavel neste ambiente — o Windows recusa abrir o mesmo
arquivo para escrita simultaneamente ("O arquivo ja esta sendo usado por
outro processo"), fazendo o `exec()` inteiro falhar (codigo != 0,
`$saida` vazio) — e todas as asserções que dependem da saida do
subprocesso (Cenarios 1/2/3/7/5a) falham de forma nao-deterministica,
dependendo de qual processo "ganhou" o arquivo naquele instante.

Confirmado por um probe isolado (script fora do repo, scratchpad da
sessao): 12 chamadas concorrentes de `exec()` com redirecionamento para o
MESMO arquivo fixo produziram 9 falhas de "arquivo em uso"; as MESMAS 12
chamadas usando `tempnam()` (nome unico por chamada) produziram 12
sucessos, 3 vezes seguidas. Reproduzida tambem a suite completa: 3
execucoes CONCORRENTES da suite (antes da correcao) geraram exatamente o
mesmo padrao de falhas visto pelos revisores (45/50, 42/50, 41/50, com as
falhas concentradas nos Cenarios 1/2/3/7/5a); apos a correcao, as 3
execucoes concorrentes passaram a dar 53/53 identico.

### Correcao aplicada

- `rodarSubprocessoPdo()` agora gera um arquivo de stderr UNICO por
  chamada via `tempnam()`, sempre removido em `finally` (mesmo em
  excecao). Mesma tecnica aplicada ao novo subprocesso do Cenario 37.
- Nenhuma mudanca na tecnica de simulacao de falha de transporte (porta
  fechada) — ela ja era deterministica, a causa nunca esteve ali.

### 10 execucoes sequenciais apos a correcao (todas identicas)

```
RUN 1:  53 testes, 53 passaram, 0 falharam
RUN 2:  53 testes, 53 passaram, 0 falharam
RUN 3:  53 testes, 53 passaram, 0 falharam
RUN 4:  53 testes, 53 passaram, 0 falharam
RUN 5:  53 testes, 53 passaram, 0 falharam
RUN 6:  53 testes, 53 passaram, 0 falharam
RUN 7:  53 testes, 53 passaram, 0 falharam
RUN 8:  53 testes, 53 passaram, 0 falharam
RUN 9:  53 testes, 53 passaram, 0 falharam
RUN 10: 53 testes, 53 passaram, 0 falharam
```

(53 = 50 cenarios originais + 3 asserções do Cenario 37, agora sempre
executado — nunca mais pulado.)

### Cenario 37 tornado autocontido

O Cenario 37 agora gera, em tempo de execucao, dentro do proprio
`teste_pdo_falha_processamento_documentos.php`:

1. Uma copia MUTADA de `app/Controller/DocumentoController.php` (o
   try/catch dedicado ao redor de `avancarParaProcessandoComparacao()` e
   removido via `str_replace` de um trecho exato, com verificacao de
   `ocorrencias === 1` — se o trecho original mudar de forma
   incompatível, o teste FALHA alto e claro em vez de pular
   silenciosamente).
2. Um script auxiliar de subprocesso (equivalente ao antigo
   `_caso_prova_negativa_catch_avancar.php`, nunca versionado) que
   carrega essa copia mutada via `require_once` (antes de qualquer
   autoload da classe real) e invoca
   `processarResultadoVioApiBrObtido()` via Reflection, com PDO poisoned.

Ambos os arquivos sao escritos em `sys_get_temp_dir() . '/totem_qa_pdo_muted_' . bin2hex(random_bytes(4))`
(fora do worktree, nunca dentro do repositorio) e removidos em `finally`
— sucesso ou falha. Rodando `php tests/manual/teste_pdo_falha_processamento_documentos.php`
sem NENHUM passo extra (sem env var, sem arquivo preparado por fora) ja
executa o Cenario 37 por completo e de forma deterministica.

### Validacao final desta correcao

- `php -l tests/manual/teste_pdo_falha_processamento_documentos.php` —
  sem erros de sintaxe.
- `grep`/`find` no repositorio inteiro por padroes de arquivo temporario
  desta correcao (`totem_qa_pdo_muted`, `_runner_cenario37`,
  `DocumentoController_muted`) — zero resultado, nada ficou no disco
  dentro do repositorio nem em `tests/manual/`.
- `tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php` — 83
  testes, 83 passaram, 0 falharam (reexecutado, nao afetado).
- `tests/manual/teste_vio_api_br_cas_e_cache.php` — 46 testes, 46
  passaram, 0 falharam (reexecutado, nao afetado).

### Veredito

**Determinístico confirmado.** 10 execucoes sequenciais identicas
(53/53) apos a correcao, mais 3 execucoes CONCORRENTES adicionais
(cenario que antes reproduzia a falha) tambem identicas (53/53 cada).
Causa raiz real: nome fixo do arquivo de stderr de redirecionamento em
`rodarSubprocessoPdo()`, nunca a porta fechada 127.0.0.1:1 (hipotese
descartada por teste isolado). Nenhum codigo de producao foi alterado —
so `tests/manual/teste_pdo_falha_processamento_documentos.php`. Nenhum
commit/push feito. Trello nao foi usado.

## `/02-testes` e `/03-revisao` independentes — veredito final (2026-09-28)

**Etapa**: consolidação final, após conclusão de `/02-testes` e
`/03-revisao` independentes (6 revisores no total, 3 em cada etapa, sem
sobreposição com as rodadas de implementação/correção anteriores). Ambas
concluídas com veredito **APROVADO** (após 1 rodada corretiva de
determinismo aplicada no meio do `/02-testes`, já detalhada na seção
imediatamente acima).

### `/02-testes` (3 revisores independentes)

- **security-especialista**: 16/17 CONFIRMADO. 1 achado de
  reprodutibilidade: a contagem 53/53 não era reproduzível sem uma
  variável de ambiente/artefato externo não documentado.
- **qa-testes**: achado REAL de instabilidade não-determinística em
  `tests/manual/teste_pdo_falha_processamento_documentos.php` — variação
  observada entre execuções idênticas (47/50, 41/50, 50/50) — REPROVADO
  parcialmente nesta rodada.
- **backend-especialista** (revisor): 9/9 CONFIRMADO, com 2 execuções
  flaky isoladas registradas.

**Rodada corretiva aplicada** (detalhada na seção "Correcao de
determinismo do teste de PDO (2026-09-28)" acima): causa raiz
identificada e corrigida — arquivo de stderr temporário com nome FIXO
reutilizado entre subprocessos concorrentes, causando contenção de I/O
no Windows; corrigido com `tempnam()` gerando nome único por chamada,
limpeza garantida em `finally`. Cenário 37 (prova negativa do catch de
`avancarParaProcessandoComparacao()`) tornado 100% autocontido (gera sua
própria cópia mutada temporária em tempo de execução, sem exigir
configuração manual externa). Resultado pós-correção: **53/53 em 10
execuções sequenciais + 3 execuções concorrentes**, determinístico.

### `/03-revisao` (3 revisores independentes, novos, sem participação nas rodadas anteriores)

- **backend-especialista**: 14/14 itens CONFIRMADOS — remoção do legado,
  equivalência de cobertura, RNTRC com prova negativa própria em código
  mutado, fallback manual, cache/HMAC com prova negativa, PDO 53/53 em 3x
  sequencial + 3x concorrente, catches/entrypoint com prova negativa,
  `comparacao.campos` removido, `VIO_VALIDADO` histórico com prova
  negativa, higiene, ausência de segredos, reativação Serpro/Prodesp
  impossível, resultado tardio/CAS, regressões.
- **security-especialista**: 10/10 itens CONFIRMADOS — segredos/PII,
  chave HMAC, RNTRC, `PDOException`, CAS, resultado tardio,
  `VIO_VALIDADO`, fronteira global, higiene, correção de determinismo.
- **qa-testes**: 9/9 itens CONFIRMADOS — 10 execuções sequenciais de
  `teste_pdo_falha_processamento_documentos.php`, todas 53/53; regressão
  ampla; árvore hermética, incluindo achado metodológico de que os
  helpers gitignorados `_caso_*.php` precisam ser copiados manualmente
  para reproduzir a árvore hermética — não é uma dependência quebrada da
  demanda, é o desenho já documentado da suíte.

### Confirmação explícita

Zero chamada real durante `/02`/`/03`, zero credencial real exibida, zero
migration em banco real, zero Trello, zero alteração de código durante as
revisões (todos os revisores confirmaram git status/hash idêntico ao
início), zero commit/push.

### Achados não-bloqueantes registrados, sem correção (fora do escopo desta demanda)

- `tests/manual/teste_lgpd_aceite_backend_seguranca.php` — fixture
  ausente, pré-existente.
- `tests/manual/teste_consulta_ordem_coleta.php` — banco externo
  indisponível, pré-existente.
- RNTRC sem validação de formato/tamanho além de tipo/vazio/placeholder
  — comportamento real, não regressão, decisão de adicionar fica para
  demanda futura.
- 25 bancos `qa_` órfãos de sessões anteriores no MySQL local — higiene
  de ambiente, não desta demanda.
- Diretórios temporários vazios residuais em `%TEMP%` de sessões
  anteriores.

### Nota de correção

As menções anteriores neste handoff sobre "`.env` local ainda com as 10
variáveis antigas" (seção "2. Variáveis de ambiente" acima) e "chave HMAC
vazia no `.env` local" (seção "8. Achado operacional relevante" acima)
estão **DESATUALIZADAS**. O orquestrador já resolveu ambas diretamente:
removeu as 10 variáveis antigas do `.env` local e gerou/gravou a chave
HMAC real via `random_bytes(32)`, confirmado por presença/tamanho, nunca
exibindo o valor. Registrado aqui para não confundir revisões futuras que
leiam este handoff.

### VEREDITO FINAL

**APROVADO.** Pronta para `/04-commit-e-push`.
