# 13 - Gestão: logs do sistema e auditoria

Índice: [fluxos](README.md) | Rotas: [gestao-rotas](../apis/gestao-rotas.md) | [regras de negócio](../regras-negocio.md) | [banco de dados](../banco-dados.md)

Dois mecanismos distintos, ambos no banco do totem: o **log central** (`tb_log_sistema`, falhas técnicas e de integração, lido na tela Logs) e a **auditoria** (`tb_gestao_auditoria`, quem fez o quê na Gestão, append-only). Legenda de confiança: a mesma de [02-expedição](02-expedicao.md).

## Objetivo

Dar ao administrador uma visão das falhas do sistema (integrações, banco, crons, Gestão) sem expor dado pessoal, e manter a trilha das ações administrativas; ambos com retenção de 90 dias. `[CÓDIGO]`

## Perfis

- Tela de logs (`logs.php`, `log.php`): somente `admin`, somente GET. `[CÓDIGO: public/gestao/logs.php, log.php]`
- Auditoria: não existe tela. A trilha é gravada pelas operações da Gestão e só pode ser consultada diretamente no banco. `[CÓDIGO: ausência de página em public/gestao]`

## Pré-condições

- Sessão de administrador ([fluxo 11](11-gestao-acesso-e-usuarios.md)).
- Tabelas `tb_log_sistema` e `tb_gestao_auditoria` ([banco-dados](../banco-dados.md)).
- Para as recusas anônimas serem logadas, `STORAGE_PATH` precisa ser gravável (o throttle de escrita usa `STORAGE_PATH/log_throttle`; sem isso essas linhas não são gravadas e a resposta HTTP não muda). `[CÓDIGO: LogSistema::throttlePermiteEscrita]`

## Etapas na tela (logs)

1. **Lista (`/gestao/logs.php`)**: cinco abas por link, sem aba "Todas": **API**, **Recebimento**, **Expedição**, **Cron** e **Gestão** (aba padrão: API). Cada aba mostra a contagem do período. "API" significa falhas de integrações e infraestrutura (Talent, VIO, n8n, banco, impressão) que não pertencem a um atendimento. `[CÓDIGO: GestaoLogController::ABAS]`
2. **Filtros** (GET, por lista permitida; sem busca de texto livre): nível (Erro/Aviso/Info); período por atalhos Hoje, Últimos 7 dias, Últimos 90 dias ou datas `de`/`ate` (`AAAA-MM-DD`, não futuras, não anteriores a 90 dias; data inválida é ignorada com aviso); totem (nome + empresa, ou "Sem totem"; só nas abas API, Recebimento e Expedição); categoria (as do catálogo para a aba). `[CÓDIGO: GestaoLogController::lerFiltros]`
3. **Paginação**: 50 por página; página além do fim redireciona para a última. `[CÓDIGO: POR_PAGINA]`
4. **Detalhe (`/gestao/log.php?id=`)**: mensagem fixa do catálogo, nível, aba, totem e os pares técnicos permitidos (`chave=valor`); aviso de retenção de 90 dias; botão Voltar mantém os filtros. Registro apagado pela retenção: volta à lista com a mensagem "Registro não encontrado". `[CÓDIGO: GestaoLogController::detalhe]`
5. A tela não exporta nem "revela" nada, e abrir a tela não é auditado. `[CÓDIGO]`

## Processamento interno

### Gravação: `Util\LogSistema::registrar($categoria, $contexto)`

- **Nunca lança exceção**: qualquer falha cai num `error_log` de texto fixo. Reentrância bloqueada. `[CÓDIGO]`
- **Catálogo fechado** (`Util\LogCatalogo::CATEGORIAS`, 22 categorias): categoria desconhecida é descartada. Cada categoria define origem, nível, **mensagem fixa**, janela de deduplicação e as chaves de contexto permitidas. As categorias: `totem_nao_autorizado`, `oc_consulta_falhou`, `oc_baixa_falhou`, `banco_coletas_indisponivel`, `n8n_anexo_recusado`, `n8n_anexo_erro`, `talent_erro_reprocessavel`, `talent_indeterminado`, `vio_falha_integracao`, `vio_erro_interno`, `erro_banco_pdo`, `erro_tecnico`, `rate_limit_ocr_excedido`, `etiqueta_pdf_falhou`, `etiqueta_config_invalida`, `impressao_config_falhou`, `cron_resumo`, `cron_falhou`, `gestao_erro_interno`, `auditoria_falhou` e as internas `log_parametro_invalido` e `log_suprimido`. Origem `por_tipo` significa Recebimento ou Expedição conforme o tipo do atendimento. `[CÓDIGO: LogCatalogo]`
- **Contexto por lista permitida**: só chaves e valores de domínio fechado (motivos, categorias de erro do Talent, documento `cnh`/`crlv`, jobs, contagens inteiras, `id_atendimento`, `id_totem`, `tipo`, `http`, classe da exceção). Texto livre é rejeitado. `[CÓDIGO: LogCatalogo::DOMINIO]`
- **Deduplicação**: linha única por (`dedup_chave`, `janela`); repetição incrementa `contador` e `ultima_ocorrencia` (UNIQUE `uk_log_sistema_dedup`). `[CÓDIGO: schema.sql, LogSistemaDao::registrarOuIncrementar]`
- **Escrita**: enfileirada e gravada no encerramento da requisição por conexão dedicada, fora da transação do chamador; os crons chamam `LogSistema::descarregar()` explicitamente. `[CÓDIGO: LogSistema, cron/limpar-logs-gestao.php]`
- **Throttle de escrita** (1 por categoria a cada 60 s, marcador em arquivo) para `totem_nao_autorizado` e `n8n_anexo_recusado`: o contador dessas categorias é subestimado por desenho; falha do throttle descarta a linha. `[CÓDIGO]`
- **Tetos**: 10 por requisição; 500 por dia por categoria (exceto `cron_*` e `log_suprimido`); 2000 por dia no total; 100000 linhas na tabela. Ao suprimir, registra `log_suprimido` com o motivo (`teto_categoria`, `teto_diario`, `teto_total`). `[CÓDIGO: LogSistema constantes]` `[TESTE: teste_log_sistema]`
- **Instrumentação**: ligada em 53 pontos do código (token de totem inválido, consulta/baixa de OC, banco de coletas, anexo do n8n, Talent, VIO, erros de banco/técnicos, limite de OCR, etiqueta/impressão, os crons e a Gestão). Os `error_log` já existentes foram mantidos (registro duplo). `[TESTE: teste_gestao_instrumentacao]` (cobertura; não reexecutado)

### O que NÃO é gravado no log central

- Por desenho: nunca `getMessage()` de exceção, dado pessoal, token, IP ou caminho de arquivo; só a classe da exceção e códigos de domínio fechado; a mensagem é a do catálogo. `[CÓDIGO]`
- Por decisão do usuário (não instrumentados, só `error_log` quando existe): banco do totem fora do ar, serviço de impressão do mini PC, `vio_reprovado`, login (vai para a auditoria), erros 4xx do usuário e anexo omitido no `TalentRn`. `[AMBIENTE-TERCEIRO: decisão relatada pelo responsável]`
- 401 só é logado quando o token foi **informado** e é inválido; token vazio não gera log. `[CÓDIGO: Auth::validarTotem]`

### Auditoria (`AuditoriaDao`)

- **Append-only**: `AuditoriaDao` só tem `abrir`/`registrar` (INSERT) e `fechar` (UPDATE restrito a `resultado = 'PENDENTE'`); não tem DELETE. O único DELETE é `AuditoriaRetencaoDao`, usado pelo cron. `[CÓDIGO]`
- **Ciclo**: a linha nasce `PENDENTE` **antes** da mutação e é fechada como `OK`, `SEM_EFEITO` (recusa de negócio) ou `ERRO` (falha técnica). Nas Rn de usuário, totem e cadastros a abertura ocorre dentro da transação (rollback desfaz o PENDENTE; quem chama grava depois a linha `ERRO`); nas ações de OC a abertura ocorre fora de transação, antes do UPDATE no banco externo. `[CÓDIGO: comentário e uso em AuditoriaDao::abrir]`
- **Colunas**: `id_usuario` (ator, nulo em CLI/cron), `acao`, `alvo_tipo`, `alvo_id`, `resultado`, `detalhe`, `ip`, `criado_em`. `[CÓDIGO: schema.sql]`
- **Ações (25)**: `LOGIN_OK`, `LOGIN_FALHA`, `LOGOUT`, `SENHA_TROCADA`, `SENHA_RESETADA`, `USUARIO_CRIAR`, `USUARIO_PERFIL`, `USUARIO_ATIVO`, `USUARIO_DESBLOQUEAR`, `TOTEM_CRIAR`, `TOTEM_ATIVO`, `TOTEM_URL_REGERAR`, `RETENCAO_EXECUTAR`, `OC_ATIVAR`, `OC_INATIVAR`, `OC_VER_PDF`, `OC_BAIXA_RESOLVER`, `CLIENTE_CRIAR`, `CLIENTE_EDITAR`, `CLIENTE_ATIVO`, `CLIENTE_EXCLUIR`, `EMPRESA_CRIAR`, `EMPRESA_EDITAR`, `EMPRESA_ATIVO`, `EMPRESA_EXCLUIR`. Ação fora do catálogo lança exceção. `[CÓDIGO: AuditoriaDao::ACOES]`
- **Alvos**: `usuario`, `totem`, `sistema`, `ordem_coleta`, `oc_baixa`, `cliente`, `empresa`. `[CÓDIGO: ALVO_TIPOS]`
- **`detalhe` por lista permitida** (`DETALHE_CAMPOS`): `origem` (web/cli/cron), `motivo`, `perfil_de`, `perfil_para`, `ativo_para`, `sessoes_revogadas`, `empresa` (id), `logs_apagados`, `auditoria_apagados`, `lotes`, `status_de`, `status_para`, `motivo_oc`, `motivo_cad`; formato `chave=valor;...`. Nunca nome, CNPJ, login, URL, token ou texto livre. Chave ou valor fora da lista lança exceção. `[CÓDIGO: AuditoriaDao::montarDetalhe]`
- **IP do ator**: gravado em `ip` (`VARBINARY(16)`) quando a Gestão informa; nulo em CLI e cron. Ver Pendências. `[CÓDIGO: AuditoriaDao::inserir]`
- Falha ao gravar a trilha de uma operação já concluída vira `LogSistema auditoria_falhou`, sem desfazer a operação. `[CÓDIGO: Rn::fecharAuditoria]`

### Retenção de 90 dias: `cron/limpar-logs-gestao.php`

- Só roda em CLI (outro SAPI: HTTP 403 e saída 1); aceita apenas `--dry-run` (outro argumento: saída 2). `[CÓDIGO]`
- Corte fixo de 90 dias (fuso -03:00) tanto em `LogSistemaDao` quanto em `AuditoriaRetencaoDao`, com piso: o DAO recusa corte mais novo que 90 dias. `[CÓDIGO]`
- `--dry-run` apenas conta as linhas elegíveis e escreve no `error_log`; nada é apagado nem auditado. `[CÓDIGO]`
- Execução real: `GET_LOCK` não bloqueante (`totem_logs_<sha1 do DB_NAME>`; se outra execução está ativa, sai sem fazer nada); **abre a trilha `RETENCAO_EXECUTAR`** (origem `cron`, sem ator) **antes** de apagar e aborta sem apagar se não conseguir abri-la; poda `tb_log_sistema` e `tb_gestao_auditoria` de forma independente, em lotes de 500, até 50 lotes por tabela e 240 s no total; fecha a trilha com `logs_apagados`, `auditoria_apagados` e `lotes` reais; registra `cron_resumo` (sucesso) ou `cron_falhou` (falha). A própria trilha `RETENCAO_EXECUTAR` também é podada após 90 dias. `[CÓDIGO]` `[TESTE: teste_gestao_retencao]`
- Sessões de login expiradas e tentativas antigas **não** passam por este cron: são apagadas de forma oportunista pelo login ([fluxo 11](11-gestao-acesso-e-usuarios.md)). `[CÓDIGO]`

## Rotas

`/gestao/logs.php` e `/gestao/log.php` (GET, `admin`). Não há rota de auditoria. Ver [gestao-rotas](../apis/gestao-rotas.md).

## Mudanças de estado

`tb_log_sistema` (INSERT/UPDATE do contador por quem registra; DELETE só pelo cron) e `tb_gestao_auditoria` (INSERT, UPDATE de `resultado`/`detalhe` na linha PENDENTE, DELETE só pelo cron). A tela de logs não altera nada. `[CÓDIGO]`

## Integrações

Nenhuma. O cron usa só o banco do totem. `[CÓDIGO]`

## Conclusão

Tela: lista ou detalhe renderizados. Retenção: saída 0 com a trilha fechada `OK`, linha `cron_resumo` no log e resumo no `error_log` do cron. `[CÓDIGO]`

## Falhas e alternativas

- Falha ao carregar a lista ou o detalhe: página com HTTP 500 e aviso, `error_log` de texto fixo (`logs_carga_falhou`, `log_detalhe_falhou`). `[CÓDIGO: GestaoLogController]`
- Banco indisponível para gravar log: fallback `error_log` fixo, no máximo uma vez por minuto por categoria. `[CÓDIGO]`
- Cron: bootstrap/conexão falha (saída 1, nada apagado); trilha não abriu (saída 1, nada apagado); erro numa tabela não impede a outra (`falhou`, saída 1). `[CÓDIGO]`

## Auditoria

Este fluxo é a própria trilha. A ação deste fluxo é `RETENCAO_EXECUTAR` (alvo `sistema`). Ler logs e abrir detalhe não são auditados. `[CÓDIGO]`

## Pendências conhecidas

Registradas sem correção:

- **IP do ator na auditoria**: `tb_gestao_auditoria.ip` guarda o IP do ator em binário (não hash). Documentos anteriores falam em auditoria "sem PII", mas só o `detalhe` é livre de dado pessoal. `[CÓDIGO]`
- **Agendamento do cron em produção**: a linha `10 4 * * *` está no crontab e executando corretamente (confirmado pelo responsável do projeto em 2026-10-10). `[AMBIENTE-TERCEIRO]`
- Não há segundo usuário MySQL só para o DELETE da auditoria; a imutabilidade é por código, não por permissão de banco (não confirmado na Hostgator). `[PENDENTE DE CONFIRMAÇÃO]`
- O teto diário global de 2000 pode suprimir logs legítimos se um token válido gerar muitos ids (risco registrado).
- Byte NUL fora do contrato do `LogSistema` passa cru ao HTML (`h()` não remove NUL; não explotável).
- Mensagem completa só no detalhe (sem `title` na lista); aviso de sessão expirada não aparece ao redirecionar ao login.
- `STORAGE_PATH` gravável no cPanel e SAPI/FPM da Hostgator: não confirmados. `[AMBIENTE-TERCEIRO]`
- Testes: `[TESTE: teste_gestao_logs]` (uma falha só por fim de linha CRLF no Windows), `[TESTE: teste_gestao_qa_logs]`, `[TESTE: teste_gestao_qa_logs_tela]`, `[TESTE: teste_gestao_retencao]`, `[TESTE: teste_log_sistema]`, `[TESTE: teste_gestao_instrumentacao]`; não reexecutados.
- Itens descartados pelo usuário e inexistentes: painel, status das integrações, saúde dos crons (tela que mostraria a execução dos crons), fotos de notas do Recebimento, F5.
