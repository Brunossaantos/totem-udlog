# Banco externo de gestão de coletas (ordens de coleta)

**Não existe API HTTP de ordens de coleta no projeto.** O totem e a Gestão acessam o banco externo `<CONTA_CPANEL>_db_gestao_coletas` **direto por PDO**. Este documento descreve esse acesso como "integração". Estrutura das tabelas: [banco-dados.md](../banco-dados.md#5-banco-externo-conta_cpanel_db_gestao_coletas). Fluxos: [06 - Consulta e baixa de OC](../fluxos/06-consulta-e-baixa-ordem-coleta.md), [14 - Gestão: ordens de coleta](../fluxos/14-gestao-ordens-coleta.md). Convenções: [README.md](README.md). Legenda: **[C]** código, **[T: nome]** teste, **[A]** ambiente/terceiro, **[P]** pendente.

## O que é e o que não é

| Item | Situação |
|---|---|
| Acesso por PDO ao banco externo (`Util\ConexaoGestaoColetas`) | **ativo** **[C]** |
| API HTTP de ordens de coleta (`/iaUdlog/api/v1/ordens-coleta`) | **não usada pelo totem**; o contrato dela não está documentado no projeto **[P]** |
| `App\Rn\ClienteApiClient` (URL fixa de uma API de clientes, cache de 60 s em arquivo) | **código morto**: nenhum `new ClienteApiClient` em `app/`, `public/`, `cron/`, `util/`; só aparece em `.gitignore` e em comentário de teste **[C]** |
| Cadastro de clientes usado em produção | `tb_cliente` **local** (`ClienteDao`), não o banco externo **[C]** |

Documentação do fornecedor em `docs/` (INFORMADO PELO FORNECEDOR, mas **desatualizada em relação ao código**): `docs/db_gestao_coletas.md` ainda trata "buscar OC por placa" como placeholder e descreve a API HTTP de clientes como contrato "confirmado 2026-09-04", e a estrutura do banco é a do dump local `docs/<CONTA_CPANEL>_db_gestao_coletas.sql` (fora do Git; produção, somente estrutura, extraído em 2026-10-10; 13 tabelas). **[P]** quanto à API de clientes e às ordens por placa. Segundo o usuário (registrado em `docs/n8n-anexo-ordem-coleta.md`, 2026-10-05), a iaUdlog grava no **mesmo** banco que o totem lê. **[A]**

## Conexão

- `Util\ConexaoGestaoColetas::obter()` abre uma conexão PDO **singleton** com `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS` (as **mesmas credenciais** do banco do totem) e `GESTAO_COLETAS_DB_NAME`. DSN `utf8mb4`; `ERRMODE_EXCEPTION`; `FETCH_ASSOC`; `PDO::ATTR_TIMEOUT = 4` s; `SET time_zone = '-03:00'`. **[C]**
- A conexão só é aberta quando um código precisa dela (não no bootstrap).
- Falha de configuração ou de conexão: `error_log` fixo, `LogSistema 'banco_coletas_indisponivel'` e `RuntimeException('Nao foi possivel conectar ao banco de ordens de coleta')`. **[C]**
- Prepared statements em todas as consultas. As permissões do usuário MySQL sobre o banco externo em produção (leitura/escrita/DELETE) não estão registradas: **[P]**.

## O que o totem lê e escreve

### Banco externo (3 tabelas)

| Tabela | Operação | Quem | Detalhe |
|---|---|---|---|
| `tb_ordens_coleta` + `tb_clientes` | **lê** | `OrdemColetaDao::buscarPorPlacaNormalizada` (via `OrdemColetaClient::buscarPorPlaca`) | `placa_prevista = :placa AND oc.status = 'ATIVA' AND c.status = 'ATIVO'`, `ORDER BY criado_em DESC`; devolve `numero`, `cliente_nome`, `cliente_cnpj`, `data` |
| `tb_ordens_coleta` + `tb_clientes` | lê | `statusPorClienteNumero` (`LIMIT 2`; devolve o status só se houver **exatamente 1** linha; ambíguo/ausente = `null`) | usado para confirmar o estado da OC |
| `tb_ordens_coleta` | **escreve** (UPDATE) | `marcarInativaPorClienteNumero` (baixa pelo totem) | `SET status='INATIVA', inativada_em=NOW() WHERE cliente_id=(SELECT id FROM tb_clientes WHERE cnpj=:cnpj) AND numero_ordem_coleta=:numero AND status='ATIVA'` (CAS pelo status; sem CNPJ não baixa) |
| `tb_ordens_coleta` | escreve (UPDATE) | Gestão: `inativarPorId`, `ativarPorId` | por `id`, com CAS no status; ao ativar `inativada_em = NULL`. Ver [gestao-rotas.md](gestao-rotas.md) |
| `tb_ordem_coleta_arquivos` | lê, **escreve** (INSERT/UPDATE) e **apaga** (DELETE) | `OrdemColetaArquivoDao` | recebe o PDF do n8n ([n8n-anexo-ordem-coleta.md](n8n-anexo-ordem-coleta.md)); `listarExpirados` junta com `tb_ordens_coleta`/`tb_clientes`; `excluirPorIdECaminho` roda no cron `limpar-anexos-ordem-coleta` |
| `tb_clientes` | lê (Gestão: lista de clientes e filtro) | `OrdemColetaGestaoDao` | `SELECT id, razao_social, cnpj ...` |

O totem **não escreve** em `tb_clientes` externo, `tb_motoristas`, `tb_emails_recebidos` nem outras tabelas do dump, e **não há INSERT nem DELETE** em `tb_ordens_coleta` no código (as OCs são criadas pelo sistema externo). **[C]**

### Banco do totem (o que se grava por causa das OCs)

`tb_atendimento` (OC selecionada, `cliente_cnpj`) e `tb_ordem_coleta_pendente_baixa` (baixa que falhou). `OrdemColetaPendenteBaixaDao` usa o **banco do totem**, não o externo. Ver [banco-dados.md](../banco-dados.md).

## Fluxo de uso

1. **Consulta por placa** (`atendimento.php?acao=iniciar`, só Expedição): `AtendimentoRn::consultarOrdensAbertas` -> `OrdemColetaClient::buscarPorPlaca` (placa normalizada: só `A-Z0-9`, maiúscula; vazia = lista vazia). Resultado: 0 OC = 400 "Nenhuma ordem de coleta em aberto para essa placa"; 1 OC = seleciona sozinha; várias = tela de escolha. **[C]**
2. **Revalidação** (`selecionar-ordem`): a OC escolhida é conferida de novo contra a placa. **[C]**
3. **Baixa** (`finalizar`, Expedição, depois de `ENVIADO`/`JA_ENVIADO` no Talent): `tentarMarcarOrdemConcluida` usa CNPJ+número. Sucesso = `rowCount() > 0`. Se falhar, consulta `statusAtual`; sem CNPJ não tenta; em qualquer falha grava pendência em `tb_ordem_coleta_pendente_baixa` e `LogSistema 'oc_baixa_falhou'`, e **nunca falha o check-in**. **[C]** [T: teste_ordem_coleta_pendente_baixa, teste_consulta_ordem_coleta]
4. **Anexo no check-in**: `AnexoOrdemColetaLeitor::buscar(cnpj, numero)` lê `tb_ordem_coleta_arquivos` e o arquivo em disco (confere limite e sha256); motivos de omissão registrados incluem `chave_invalida`, `banco_indisponivel`, `leitura_falhou`. Nunca falha o check-in. **[C]**

## Timeout, retry, idempotência

| Item | Valor |
|---|---|
| Timeout de conexão/consulta | `PDO::ATTR_TIMEOUT` = 4 s (atributo do driver; o efeito exato em consultas longas depende do driver) |
| Retry | **nenhum** no totem |
| Polling | não se aplica |
| Idempotência da baixa | CAS pelo status (`AND status='ATIVA'`): repetir a baixa não faz nada; o código trata "já inativa" por `statusAtual` |
| Duplicidade na Gestão | `ativarPorId`/`inativarPorId` com CAS e `status_visto` ([gestao-rotas.md](gestao-rotas.md)) |

## Indisponibilidade e fail-closed

| Momento | O que acontece |
|---|---|
| Consulta por placa (`iniciar`) | falha = **502** `Nao foi possivel consultar as ordens de coleta agora`, log `oc_consulta_falhou`; **o atendimento e o aceite LGPD já estão gravados** (a consulta roda depois do commit) |
| `selecionar-ordem` | exceção **não é capturada** no controlador; o que o cliente recebe é **[P]** |
| Baixa no `finalizar` | falha vira pendência + log; check-in segue 200 |
| Leitura do anexo | segue sem anexo |
| Gestão (`ordens.php` e ações) | mensagem `oc_banco_indisponivel` ("Nao foi possivel consultar as ordens de coleta agora...") ou `oc_baixa_recusada_externo_indisponivel`; nada é alterado |
| Recebimento do PDF do n8n (`ordem-coleta-anexo.php`) | 503 `INDISPONIVEL`; o n8n deve retentar |
| Configuração ausente | `RuntimeException` e log `banco_coletas_indisponivel` |

Fail-closed: sem OC ativa para a placa (ou sem banco), a Expedição não segue.

## Estrutura e migrations

A estrutura do banco externo **não vem de migration** no repositório: as migrations externas 001-004 foram removidas (higienização de 2026-10-09). Colunas e índices usados pelo código (`status`, `inativada_em`, `idx_oc_status_*`) e a tabela `tb_ordem_coleta_arquivos` constam do dump local (fora do Git) e têm cópia embutida na infra de testes. Para mudar o banco externo no futuro, criar uma nova migration. **[C]** Ver [banco-dados.md, seção 5](../banco-dados.md#5-banco-externo-conta_cpanel_db_gestao_coletas).

## Testes

[T: teste_consulta_ordem_coleta (12/17 por fixture, P24), teste_ordem_coleta_pendente_baixa, teste_gestao_oc_dao, _caso_selecionar_ordem, _caso_iniciar_expedicao]. Usam bancos `qa_` descartáveis; nunca devem apontar ao banco real (incidente registrado no state em 2026-10-09, sem efeito em produção). Não reexecutados aqui. **[A]**
