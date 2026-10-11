# Rotas da Gestão (`public/gestao/*.php`)

Painel web da UDLOG (HTML renderizado no servidor, sem API JSON). Cada arquivo de `public/gestao/` é uma rota. Esta página documenta **método, perfil, CSRF, PRG e ações POST**; o comportamento funcional está nos fluxos [11 - acesso e usuários](../fluxos/11-gestao-acesso-e-usuarios.md), [12 - totens](../fluxos/12-gestao-totens.md), [13 - logs e auditoria](../fluxos/13-gestao-logs-auditoria.md), [14 - ordens de coleta](../fluxos/14-gestao-ordens-coleta.md) e [15 - clientes e empresas](../fluxos/15-gestao-clientes-empresas.md). Convenções gerais: [README.md](README.md). Banco: [banco-dados.md](../banco-dados.md). Regras: [regras-negocio.md](../regras-negocio.md). Legenda: **[C]** código, **[T: nome]** teste, **[A]** ambiente, **[P]** pendente.

A Gestão **não usa o token do totem**. Autenticação por sessão em tabela (cookie opaco; só o sha256 vai ao banco), 30 min de inatividade e 12 h absolutas (padrão; `GESTAO_SESSION_ABSOLUTE_MIN` ajusta o teto absoluto), perfis `admin` e `usuario`. **[C]**

## Pipeline comum (`GestaoContexto::iniciar`)

Aplicado a todas as rotas, nesta ordem (`app/Controller/GestaoContexto.php`):

1. Cabeçalhos de segurança e tratador de exceção.
2. Banco do totem indisponível: **503** "Serviço indisponível".
3. `GESTAO_HASH_SALT` ausente ou curto: **503** "A gestão ainda não está configurada".
4. Transporte não HTTPS (`GESTAO_PERMITIR_HTTP` não definido em produção): **403** "HTTPS obrigatório".
5. Host da requisição não reconhecido: **400** "Requisição inválida".
6. Método fora da lista da rota: **405** + cabeçalho `Allow`. Padrão da lista: `GET, POST`.
7. Método **não seguro** (POST) com `Origin` não permitida: **403** "Requisição inválida".
8. Rota pública (`login.php`): para aqui.
9. Sem sessão: redireciona ao login (método seguro e navegação normal); **401** "Sessão expirada" (POST/JSON). Pedido JSON (`Accept: application/json` ou `X-Requested-With: XMLHttpRequest`) recebe 401/403 em JSON `{"sucesso":false,"erro":"..."}`.
10. Perfil insuficiente: **403** "Acesso negado". `usuario` = aceita `admin` e `usuario`; `admin` = só `admin`.
11. Troca de senha pendente: redireciona a `/gestao/conta.php` (GET) ou **403** (POST), exceto em `index.php` e `conta.php`.
12. **CSRF** em todo método não seguro: token da sessão no cabeçalho `X-CSRF-Token` **ou** no campo `csrf_token`; inválido = **403** "Página expirada".

**PRG (Post/Redirect/Get)**: as ações POST terminam em `GestaoHttp::redirecionar('...?msg=<codigo>')`; o texto vem de `GestaoContexto::MENSAGENS` (tipos `sucesso`, `erro`, `info`); código desconhecido vira `erro_interno`. As exceções (sem PRG) são `login.php` com erro, `usuarios.php` com redefinição de senha bem-sucedida e `usuario-form.php` ao criar (mostram a senha temporária na própria resposta), respostas 422 de formulários, e `ordem-pdf.php` (binário). **[C]**

## Tabela de rotas

| Rota | Métodos | Perfil | CSRF | Resumo |
|---|---|---|---|---|
| `index.php` | GET | usuario (`trocaPendenteOk`) | n/a | redireciona: conta (troca pendente), usuários (admin) ou ordens (usuario) |
| `login.php` | GET, POST | pública | **não usa o CSRF de sessão** (veja abaixo) | formulário e login |
| `logout.php` | POST | usuario | sim | encerra a sessão |
| `conta.php` | GET, POST | usuario (`trocaPendenteOk`) | sim | troca da própria senha |
| `usuarios.php` | GET, POST | admin | sim | lista e ações sobre usuários |
| `usuario-form.php` | GET, POST | admin | sim | criar e editar usuário |
| `totens.php` | GET, POST | admin | sim | lista e ações sobre totens |
| `totem-form.php` | GET, POST | admin | sim | criar totem |
| `totem-url.php` | GET | admin | n/a | exibe a URL do quiosque |
| `empresas.php` | GET | admin | n/a | lista de empresas |
| `empresa-form.php` | GET, POST | admin | sim | criar/editar empresa |
| `empresa-acao.php` | POST | admin | sim | ativar, inativar, excluir empresa |
| `clientes.php` | GET | admin | n/a | lista de clientes (`situacao`, `q`, `pagina`) |
| `cliente-form.php` | GET, POST | admin | sim | criar/editar cliente |
| `cliente-acao.php` | POST | admin | sim | ativar, inativar, excluir cliente |
| `ordens.php` | GET | usuario | n/a | 4 abas, filtros, 25 por página |
| `ordem.php` | GET | usuario | n/a | detalhe (`?id=`) |
| `ordem-status.php` | POST | usuario | sim | ativar/inativar OC |
| `ordem-baixa.php` | POST | usuario | sim | resolver baixa pendente |
| `ordem-pdf.php` | POST | usuario | sim | baixar o PDF da OC |
| `logs.php` | GET | admin | n/a | 5 abas de logs |
| `log.php` | GET | admin | n/a | detalhe (`?id=`) |

`usuarios.php`, `usuario-form.php`, `totens.php`, `totem-form.php`, `empresa-form.php`, `cliente-form.php`, `conta.php` e `login.php` aceitam GET e POST. **[C]**

## Ações POST

Todas com CSRF e `Origin` válida. Sucesso redireciona com `?msg=<codigo>`. Alvo inexistente/alterado: mensagem de erro (`*_nao_encontrado`, `*_estado_mudou` etc.), nada é alterado.

### Login e conta

- **`login.php` POST**: campos `login` (formato `primeiro.segundo`), `senha`, `token_login`. O `token_login` é **sem estado** (reutilizável por até 2 h; mitigado por `Origin` e limite por IP). Respostas (renderizam o formulário, sem PRG): **400** token de login expirado; **401** credencial inválida (mensagem genérica); **429** + `Retry-After` (limite de 10 falhas por IP em 900 s e bloqueio de conta de 15 min após 5 falhas); **500**. Sucesso = redireciona. **[C]**
- **`logout.php`**: sem campos além do CSRF. Redireciona ao login (com ou sem sessão).
- **`conta.php` POST**: `senha_atual`, `senha_nova`, `senha_confirmacao`. Sucesso: nova sessão e redirecionamento; 422 renderiza o formulário; conta bloqueada ou sem permissão: limpa o cookie e vai ao login. Senhas novas encerram as outras sessões (`senha_trocada`).

### Usuários (admin)

- **`usuarios.php` POST**: `acao` = `ativar` | `desativar` | `desbloquear` | `redefinir-senha`; `id_usuario`; `versao_senha` (obrigatório em `redefinir-senha`, controle otimista). `redefinir-senha` bem-sucedida **renderiza a senha temporária** na resposta (sem PRG). Regras com mensagem própria: `ultimo_admin`, `proprio_perfil`, `proprio_ativo`, `proprio_desbloqueio`, `senha_ja_redefinida`.
- **`usuario-form.php` POST**: `nome`, `perfil`, `login`, `id_usuario` (na edição, por query `id`). Criar renderiza a senha temporária; editar faz PRG; 422 renderiza.

### Totens (admin)

- **`totens.php` POST**: `acao` = `ativar` | `desativar` | `regerar-url`; `id_totem`. `desativar` com atendimento recente exige `confirmar_atendimento=1` (mensagem `totem_atendimento_em_andamento`). `regerar-url` exige `versao_url` (otimista) e `nome_confirmacao` (digitar o nome do totem; `totem_nome_confirmacao`); o token de acesso do totem **não muda**.
- **`totem-form.php` POST**: `id_empresa`, `nome`. Sucesso redireciona a `totem-url.php`; 422 renderiza. O código da URL é `NOME-EMPRESA-HASH16`.
- **`totem-url.php` GET**: sem `TOTEM_URL_BASE` válida responde **503**; a URL nunca é derivada do `Host`.

### Empresas e clientes (admin)

- **`empresa-acao.php`** e **`cliente-acao.php`**: `acao` = `ativar` | `inativar` | `excluir`; `id_empresa` / `id_cliente`; `confirmar` para a **confirmação em dois passos** (primeiro POST mostra o aviso, segundo confirma). Excluir empresa só sem totens (`empresa_com_totens`). Excluir cliente é físico. Ativar/criar/renomear cliente que torne o OCR ambíguo exige confirmação em dois passos (registrada na auditoria como `motivo_cad=confirmado_ambiguidade_ocr`, valor fechado que não é campo do formulário).
- **`empresa-form.php`** (`nome`, `cnpj`) e **`cliente-form.php`** (`nome`, `cnpj`, `ativo`, `confirmar`): CNPJ imutável após criar (cliente); CNPJ da UDLOG bloqueado como cliente; nome precisa gerar slug `[A-Z0-9]` de 1 a 16 e único entre empresas. 422 renderiza.
- Cliente da Gestão = `tb_cliente` do totem. O banco externo **não** é escrito por essas telas. **[C]**

### Ordens de coleta (usuario e admin)

- **`ordem-status.php` POST**: `id_ordem`, `acao` = `ativar` | `inativar`, `status_visto` = `ATIVA` | `INATIVA` (CAS pelo estado que o usuário viu; divergência = `oc_estado_mudou`), `confirmar=1` (dois passos quando há atendimento em andamento, a OC já foi baixada ou o cliente está inativo) e `origem` (para onde voltar). O `motivo_oc` **não é campo do formulário**: é o detalhe fechado da auditoria (por exemplo `confirmado_andamento`, `confirmado_ja_baixada`, `confirmado_cliente_inativo`, `ja_no_estado`, `estado_mudou`, `oc_inexistente`, `externo_indisponivel`). A **auditoria é aberta antes do UPDATE**. Sem efeito (`oc_sem_efeito_ja_ativa`/`..._inativa`) não é erro.
- **`ordem-baixa.php` POST**: `id_baixa`. Resolve só com OC **única** e **INATIVA** (recusas: `oc_baixa_recusada_cliente_ausente`, `..._oc_inexistente`, `..._oc_ambigua`, `..._oc_ativa`, `..._externo_indisponivel`). Um clique, sem confirmação (decisão do usuário).
- **`ordem-pdf.php` POST**: `id_ordem`. Entrega `attachment; filename="oc-<id>.pdf"`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`. O caminho do arquivo só vem de `OrdemColetaArquivoStorage::ler` (prefixo do CNPJ conferido). Auditoria **aberta antes do primeiro byte**. Sem PDF: PRG com `oc_pdf_indisponivel`. Sem PRG no caso de sucesso (resposta binária).
- Todas as escritas no banco externo estão descritas em [banco-gestao-coletas.md](banco-gestao-coletas.md).

### Consultas (GET)

- `ordens.php`: abas Ativas, Ativas há mais de 15 dias, Inativas e Baixas pendentes; filtros por cliente, número (exato ou prefixo de 3 ou mais caracteres) e período; 25 por página.
- `ordem.php?id=<n>`, `log.php?id=<n>`: detalhe. `logs.php`: query `aba` (API, Recebimento, Expedição, Cron, Gestão), `nivel`, `categoria`, `totem`, `pagina` e período, todos por whitelist; sem busca de texto livre. Abrir a tela não é auditado.

## Auditoria

Escritas na Gestão (usuários, totens, empresas, clientes, OCs) registram auditoria append-only sem dados pessoais (`AuditoriaDao`; o único DELETE é a retenção de 90 dias, `AuditoriaRetencaoDao`). **[C]** (25 ações). Ver [13](../fluxos/13-gestao-logs-auditoria.md).

## Rate limits e fail-closed

| Mecanismo | Valor |
|---|---|
| Falhas de login por conta | 5, bloqueio de 15 min |
| Falhas de login por IP | 10 em 900 s (429 + `Retry-After`) |
| CSRF ausente/inválido | 403 "Página expirada" |
| Configuração ausente (`GESTAO_HASH_SALT`, `TOTEM_URL_BASE`) | 503 |
| Sessão inválida | redireciona ao login ou 401 |

Os limites de login por conta e por IP não são atômicos entre processos (pendência B2). **[A]**

## Testes

[T: teste_gestao_http, teste_gestao_unidade, teste_gestao_qa_independente, teste_gestao_qa_correcoes, teste_gestao_logs, teste_gestao_qa_logs_tela, teste_gestao_oc_dao, teste_gestao_cli_admin]. Contagens históricas; não reexecutados aqui. `teste_gestao_totem_f0` e `teste_gestao_logs` têm falhas conhecidas por fim de linha (CRLF) e guarda de prepend, conhecidas. **[A]**
