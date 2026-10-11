# 11 - Gestão: acesso e usuários

Índice: [fluxos](README.md) | Rotas: [gestao-rotas](../apis/gestao-rotas.md) | [regras de negócio](../regras-negocio.md) | [banco de dados](../banco-dados.md)

A "Gestão Totem" é a área administrativa server-rendered (`public/gestao/`, `app/Views/gestao/`, `app/Controller/Gestao*`, `util/AuthGestao.php`, `util/GestaoContexto`). Este fluxo cobre login, sessão, CSRF, perfis, usuários, "Minha conta", troca de senha e o primeiro administrador. Legenda de confiança: a mesma de [02-expedição](02-expedicao.md).

## Objetivo

Autenticar quem opera a Gestão, aplicar o perfil (`admin` ou `usuario`) no servidor em toda página e permitir que o administrador gerencie as contas. `[CÓDIGO]`

## Perfis

| Perfil | O que acessa |
|---|---|
| Público (sem sessão) | somente `login.php` |
| `usuario` | Ordens de coleta (`ordens.php`, `ordem.php`, `ordem-status.php`, `ordem-baixa.php`, `ordem-pdf.php`), Minha conta (`conta.php`), `logout.php` |
| `admin` | tudo o que o `usuario` acessa, mais Usuários, Totens, Clientes, Empresas e Logs |

Página inicial após o login: `admin` vai a `/gestao/usuarios.php`; `usuario` vai a `/gestao/ordens.php`; conta com troca de senha pendente vai a `/gestao/conta.php`. `[CÓDIGO: GestaoContexto::paginaInicial, GestaoContexto::iniciar]`

O menu tem itens Painel, Atendimentos e Anexos órfãos com `disponivel=false` (não aparecem e as páginas não existem; itens descartados, ver Pendências). `[CÓDIGO: GestaoContexto::MENU]`

## Pré-condições

- Migrações aplicadas e tabelas `tb_gestao_usuario`, `tb_gestao_sessao`, `tb_gestao_login_tentativa`, `tb_gestao_auditoria` existentes (ver [banco-dados](../banco-dados.md)). `[CÓDIGO: sql/schema.sql]`
- `GESTAO_HASH_SALT` com 16 ou mais caracteres; sem isso toda página responde 503 "A gestão ainda não está configurada". `[CÓDIGO: GestaoConfig::valida]`
- HTTPS obrigatório (403 "HTTPS obrigatório") salvo `GESTAO_PERMITIR_HTTP=true`. `[CÓDIGO]`
- Pelo menos um administrador, criado por `tools/criar-admin.php` (abaixo).

## Etapas na tela

### Login (`/gestao/login.php`)

1. O usuário abre a página (GET). Se já tem sessão válida, é redirecionado ao destino do perfil. `[CÓDIGO: GestaoLoginController::login]`
2. Digita login no formato `primeiro.segundo` e a senha e envia (POST). O login é normalizado (minúsculas, sem espaços nas pontas) e deve casar `[a-z]{2,30}\.[a-z]{2,30}`. `[CÓDIGO: UsuarioGestaoRn::LOGIN_REGEX]`
3. Erro: mensagem única "Login ou senha incorretos..." para qualquer falha de credencial (não revela se o login existe, está inativo ou bloqueado). Limite por IP: mensagem com minutos de espera e cabeçalho `Retry-After` (HTTP 429). Formulário vencido: HTTP 400 "A página de login ficou aberta por tempo demais". `[CÓDIGO]`
4. Sucesso: cookie de sessão emitido e redirecionamento (home do perfil ou `conta.php` se a troca de senha é obrigatória).

### Troca de senha e "Minha conta" (`/gestao/conta.php`)

1. Obrigatória quando `deve_trocar_senha=1` (conta criada pelo admin ou senha redefinida): qualquer outra página redireciona (GET) ou responde 403 (POST) até a troca. `[CÓDIGO: GestaoContexto::iniciar]`
2. O usuário informa senha atual, senha nova e confirmação. Erros aparecem por campo (HTTP 422).
3. Sucesso: nova sessão emitida; se a troca era obrigatória vai à home do perfil, senão volta a `conta.php` com a mensagem "Senha alterada". `[CÓDIGO: GestaoContaController::tratar]`

### Usuários (`/gestao/usuarios.php`, `/gestao/usuario-form.php`; só `admin`)

- Lista de usuários com as ações Ativar/Desativar, Desbloquear e Redefinir senha, e o formulário de criar/editar (nome e perfil; o login não é editável). `[CÓDIGO: GestaoUsuarioController]`
- Criar ou redefinir senha mostra a **senha temporária uma única vez** (tela `usuario-senha`), no formato `XXXX-XXXX-XXXX-XXXX`. `[CÓDIGO: SenhaPolitica::gerarTemporaria]`
- A conta logada vê a própria linha marcada (`eh_proprio`), e as ações sobre si mesma são recusadas pelo servidor (abaixo).

## Processamento interno

### Pipeline de toda requisição

`GestaoContexto::iniciar` executa, nesta ordem: cabeçalhos de segurança (`Cache-Control: no-store`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, noindex, CSP restritiva); tratador global de exceção (500 genérico e `LogSistema gestao_erro_interno`); conexão ao banco (falha: 503); configuração válida (503); HTTPS; host válido (400); método permitido para a página (405); para método diferente de GET, `Origin`/`Sec-Fetch-Site` coerentes com o host (403); sessão e perfil mínimo (401/403 ou redirecionamento ao login em GET); troca de senha pendente; e **CSRF**. `[CÓDIGO: GestaoContexto::iniciar, util/GestaoHttp.php]`

### CSRF

Todo método diferente de GET exige o token CSRF da sessão, enviado em `X-CSRF-Token` ou no campo `csrf_token` do POST, comparado com `hash_equals`. O token é gerado por sessão (256 bits) e fica em `tb_gestao_sessao.csrf_token`. Falha: HTTP 403 "Página expirada", nada é alterado. `[CÓDIGO: AuthGestao::csrfValido]`

### RBAC

Dois perfis (`UsuarioGestaoRn::PERFIS`). Cada página declara o perfil mínimo e `AuthGestao::exigirPerfil` confere no servidor: pedido que aceita JSON recebe 401/403 em JSON; HTML recebe página de erro. Esconder item de menu não substitui a checagem. `[CÓDIGO: AuthGestao::exigirPerfil]`

### Sessão em tabela

- Cookie `gestao_sid`: valor aleatório de 256 bits (64 hex), `HttpOnly`, `SameSite=Strict`, caminho `/gestao`, `Secure` salvo `GESTAO_PERMITIR_HTTP`. No banco fica apenas o SHA-256 do valor (`tb_gestao_sessao.id_sessao`). `[CÓDIGO: AuthGestao::criarSessao, opcoesCookie]`
- Validade: **30 minutos de inatividade** (`GESTAO_SESSION_IDLE_MIN`, padrão 30, faixa 1 a 1440) e **12 horas absolutas** (`GESTAO_SESSION_ABSOLUTE_MIN`, padrão 720, faixa 1 a 10080). A sessão só vale com `expira_em > NOW()`, ociosidade menor que o limite, usuário ativo e `ua_hash` (SHA-256 do User-Agent) igual ao da criação; caso contrário a linha é apagada. `ultimo_acesso_em` é renovado no máximo uma vez por 60 s. `[CÓDIGO: GestaoConfig, SessaoGestaoDao::buscarValida/tocar]`
- Login bem-sucedido apaga a sessão anterior do cookie e cria uma nova. Logout apaga a linha, limpa o cookie e grava `LOGOUT`. `[CÓDIGO: AuthGestao::loginInterno, logout]`
- Sessões expiradas são apagadas de forma oportunista (1 em cada 50 logins, até 200 linhas), sem cron. `[CÓDIGO: AuthGestao::limpezaOportunista]`

### Senha

- Política: no mínimo 12 caracteres, no máximo 72 bytes, pelo menos 5 caracteres distintos, não pode ser só espaços, igual ao login, igual à senha atual nem constar numa lista de senhas comuns. `[CÓDIGO: SenhaPolitica::validar]`
- Hash Argon2id (m=19456, t=2, p=1) com reserva em bcrypt custo 12 quando o PHP não tem Argon2id; rehash automático no login se os parâmetros mudarem. `[CÓDIGO: SenhaPolitica]` Argon2id disponível em produção: relato do responsável em 2026-10-08. `[AMBIENTE-TERCEIRO]`
- Login e conta inexistente/inativa/bloqueada executam uma verificação de hash "dummy" para igualar o tempo de resposta. `[CÓDIGO: SenhaPolitica::verificarDummy]`

### Bloqueio de login

- **Por conta:** 5 senhas erradas seguidas bloqueiam a conta por 15 minutos (`LIMITE_FALHAS_CONTA=5`, `BLOQUEIO_CONTA_MIN=15`); o contador zera ao bloquear e no login correto. Conta bloqueada não é verificada: responde a mesma mensagem genérica. `[CÓDIGO: UsuarioGestaoDao::registrarFalhaSenha]`
- **Por IP:** 10 falhas por janela de 900 s (`LIMITE_FALHAS_IP`, `JANELA_IP_SEGUNDOS`), contadas em `tb_gestao_login_tentativa` por hash do "balde" do IP com o sal; ao atingir o limite o login responde 429. O IP vem de `IpCliente::obter` (usa `CF-Connecting-IP` somente se `REMOTE_ADDR` estiver em faixa Cloudflare oficial). `[CÓDIGO: AuthGestao::loginInterno, util/IpCliente.php]`
- Toda falha grava `LOGIN_FALHA` (resultado `SEM_EFEITO`, motivo `credenciais`, `conta_inativa`, `conta_bloqueada` ou `ip_limitado`). `[CÓDIGO]`
- Quem desbloqueia: o admin (ação Desbloquear). `criar-admin.php --forcar` não desbloqueia nem recria a conta: apenas cria outro admin com login novo (login existente é recusado). O bloqueio é, por desenho, um DoS recuperável (registrado). `[CÓDIGO]` `[AMBIENTE-TERCEIRO: decisão relatada pelo responsável]`

### Operações de usuário (só `admin`)

Todas por POST + CSRF, sob `GET_LOCK` de administradores (10 s) e transação, travando os admins ativos (`FOR UPDATE`) e reconferindo que o **ator ainda é admin ativo**. `[CÓDIGO: UsuarioGestaoRn::comLockAdmins]`

| Ação | Regras | Efeito colateral |
|---|---|---|
| Criar | login `primeiro.segundo` único; nome 2 a 100 (letras, espaço, ponto, apóstrofo, hífen); perfil da lista | senha temporária, `deve_trocar_senha=1` |
| Editar (nome/perfil) | recusa mudar o **próprio** perfil e remover o **último** admin ativo | só mudança de perfil é auditada |
| Ativar/desativar | recusa a própria conta e desativar o último admin ativo | desativar apaga as sessões do alvo |
| Redefinir senha | exige `versao_senha` (otimista); recusa a própria conta | nova senha temporária, `deve_trocar_senha=1`, sessões do alvo apagadas |
| Desbloquear | recusa a própria conta; sem efeito se não há bloqueio | zera `tentativas_falhas` e `bloqueado_ate` |

Usuários não são excluídos, só inativados. `[CÓDIGO: UsuarioGestaoRn]`

### Troca da própria senha

Exige a senha atual: se errada, conta como falha da conta (pode bloquear e então apaga todas as sessões). Com a senha nova válida e confirmada, em transação: grava o hash com `deve_trocar_senha=0`, apaga **todas** as sessões do usuário e abre a auditoria `SENHA_TROCADA`; o controller emite uma sessão nova. `[CÓDIGO: UsuarioGestaoRn::trocarPropriaSenhaInterno, GestaoContaController]`

### Primeiro administrador: `tools/criar-admin.php`

CLI exclusivo (fora do CLI responde 404), exige terminal interativo (a senha é pedida sem eco e nunca vem de argumento, pipe nem `.env`). Pergunta login, nome, senha e confirmação (ou `--login=`/`--nome=`). Recusa se já existe admin ativo, salvo `--forcar`. Cria com `deve_trocar_senha=0`, perfil `admin`, auditoria `USUARIO_CRIAR` com origem `cli` e sem IP. Usa o `.env` da máquina onde roda. `[CÓDIGO]` `[TESTE: teste_gestao_cli_admin]`

## Rotas

Páginas envolvidas: `login.php`, `logout.php`, `index.php`, `conta.php`, `usuarios.php`, `usuario-form.php`. Detalhes de método e perfil em [gestao-rotas](../apis/gestao-rotas.md).

## Mudanças de estado

- `tb_gestao_usuario`: `ativo`, `perfil`, `senha_hash`, `deve_trocar_senha`, `senha_versao`, `tentativas_falhas`, `bloqueado_ate`, `ultimo_login_em`.
- `tb_gestao_sessao`: linha criada no login/troca de senha; apagada no logout, na revogação, na invalidação ou pela limpeza.
- `tb_gestao_login_tentativa`: contador por (hash do IP, janela).
- `tb_gestao_auditoria`: uma linha por ação (ver Auditoria). `[CÓDIGO: schema.sql]`

## Integrações

Nenhuma externa. Só o banco do totem; `LogSistema` registra `gestao_erro_interno` e `auditoria_falhou` em falha técnica. `[CÓDIGO]`

## Conclusão

Login concluído quando o cookie é emitido e a página inicial do perfil abre. Operação de usuário concluída quando o COMMIT ocorre e a auditoria é fechada como `OK`; o admin volta à lista com a mensagem de sucesso (`?msg=`). `[CÓDIGO]`

## Falhas e alternativas

- Banco indisponível: 503 em todas as páginas; no login, falha interna: "Não foi possível entrar agora. Nada foi alterado." `[CÓDIGO]`
- Recusas de negócio (próprio perfil, último admin, versão de senha antiga, ator sem permissão) voltam à lista com mensagem e viram auditoria `SEM_EFEITO`; falha técnica vira `ERRO` com log de texto fixo (só a classe da exceção). `[CÓDIGO]`
- Lock de administradores indisponível em 10 s: `erro_interno`, nada é alterado. `[CÓDIGO]`

## Auditoria

Ações deste fluxo: `LOGIN_OK`, `LOGIN_FALHA`, `LOGOUT`, `SENHA_TROCADA`, `SENHA_RESETADA`, `USUARIO_CRIAR`, `USUARIO_PERFIL`, `USUARIO_ATIVO`, `USUARIO_DESBLOQUEAR`. Detalhes sobre a tabela e o que é gravado: [fluxo 13](13-gestao-logs-auditoria.md). Editar só o nome não gera linha de auditoria. `[CÓDIGO: UsuarioGestaoRn::editarSobLock]`

## Pendências conhecidas

Registradas sem correção:

- **IP do ator gravado**: `tb_gestao_auditoria.ip` guarda o IP do ator em binário (`VARBINARY(16)`), não hash; o que é livre de dado pessoal é o `detalhe`. Documentos anteriores descrevem a auditoria como "sem PII". `[CÓDIGO: AuditoriaDao::inserir]`
- **Senha temporária sem expiração**: vale até ser trocada; a única proteção é `deve_trocar_senha`. `[CÓDIGO]`
- **Token do formulário de login sem estado**: HMAC de timestamp com o sal, reutilizável por até 7200 s; mitigado por CSRF/`Origin` e pelo limite por IP. `[CÓDIGO: AuthGestao::tokenLoginValido]`
- **Limites de login não atômicos entre processos (B2)**: a contagem do IP (`contar`) e o incremento (`incrementar`) são operações separadas; processos simultâneos podem ultrapassar o limite. O bloqueio por conta usa um único `UPDATE`. `[CÓDIGO]` (registro B2)
- Mensagens de login `sessao_expirada` e `saiu` existem mas o aviso ao redirecionar para o login ainda não é exibido. `[CÓDIGO: MENSAGENS_LOGIN]`
- `AuthServidor::requisicaoHttps` confia em `X-Forwarded-Proto` (P57). `[PENDENTE DE CONFIRMAÇÃO]`
- Cookie `Secure`, Argon2id e `REMOTE_ADDR` por trás do Cloudflare só se verificam em produção. `[AMBIENTE-TERCEIRO]`
- 2FA, recuperação de senha por e-mail e bloqueio por IP além do login: não planejados.
- `criar-admin.php` imprime erros acentuados que o terminal Windows pode exibir mal.
- Itens descartados pelo usuário em 2026-10-09 e que não existem: F5 (atendimentos do dia, travados, anexos órfãos), painel, status das integrações, saúde dos crons, fotos de notas do Recebimento.
- Testes que cobrem este fluxo: `[TESTE: teste_gestao_unidade]`, `[TESTE: teste_gestao_http]`, `[TESTE: teste_gestao_correcoes]`, `[TESTE: teste_gestao_qa_independente]`, `[TESTE: teste_gestao_cli_admin]`; `teste_gestao_totem_f0` tem falhas conhecidas por dívida de teste (não é deste fluxo). Não reexecutados.
