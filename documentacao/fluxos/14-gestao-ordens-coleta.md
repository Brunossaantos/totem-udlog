# 14 - Gestão: ordens de coleta

Índice: [fluxos](README.md) | Rotas: [gestao-rotas](../apis/gestao-rotas.md) | [regras de negócio](../regras-negocio.md) | [banco de dados](../banco-dados.md) | Consulta e baixa pelo totem: [fluxo 06](06-consulta-e-baixa-ordem-coleta.md)

Telas `ordens.php`, `ordem.php` e as ações `ordem-status.php`, `ordem-baixa.php`, `ordem-pdf.php`. Legenda de confiança: a mesma de [02-expedição](02-expedicao.md).

## Objetivo

Consultar as ordens de coleta (OCs) do banco externo, ativar ou inativar manualmente, resolver as baixas pendentes que o totem não conseguiu concluir e abrir o PDF anexado pelo n8n. `[CÓDIGO: GestaoOrdemController]`

## Perfis

`usuario` e `admin` têm o mesmo acesso a todas as telas desta área. Home do `usuario` após o login: `/gestao/ordens.php`. `[CÓDIGO: public/gestao/ordens.php e demais, perfil mínimo `usuario`]`

Por decisão do usuário (F4) **não há máscara** de dado pessoal (motorista, CNH, placa aparecem em claro), **não há limite por usuário** e não existe "revelar"; isso substituiu a decisão anterior de mascarar. `[AMBIENTE-TERCEIRO: decisão relatada pelo responsável]` `[CÓDIGO: ausência de máscara em GestaoOrdemController]`

## Pré-condições

- Sessão ([fluxo 11](11-gestao-acesso-e-usuarios.md)).
- Banco externo de coletas acessível (OCs em `tb_ordens_coleta`, acesso direto por PDO; a Gestão usa as colunas `status` e `inativada_em`). Indisponível: lista com HTTP 503 e ação com 503, com `LogSistema banco_coletas_indisponivel`. `[CÓDIGO: OrdemColetaGestaoDao, GestaoOrdemController::registrarFalhaExterno]`
- Índices `idx_oc_status_criado` e `idx_oc_status_inativada` no banco externo: a migration externa 004 foi aplicada em produção em 2026-10-09; o repositório não contém mais a migration, e os índices constam do dump local `docs/<CONTA_CPANEL>_db_gestao_coletas.sql` (fora do Git; 2026-10-10). `[AMBIENTE-TERCEIRO]`

## Etapas na tela

### Lista (`ordens.php`, GET)

Quatro abas: **Ativas**, **Ativas há mais de 15 dias** (ativas criadas há mais de 15 dias, da mais antiga para a mais nova; alerta visual de idade), **Inativas** e **Baixas pendentes**. Cada aba mostra sua contagem (as de OCs são limitadas a 10000 e passam a "mais de 10.000"). `[CÓDIGO: GestaoOrdemController::ABAS, OrdemColetaGestaoDao::TETO_CONTAGEM]`

- Filtros das abas de OCs: cliente (lista de clientes), **número** (exato ou prefixo de 3 ou mais caracteres; `[A-Za-z0-9._/-]`, até 50), período de criação (`de`/`ate`) e, na aba Inativas, período de inativação. Ordenação por número, cliente ou data de criação. 25 por página, no máximo 400 páginas. `[CÓDIGO]`
- Sinal "Cliente inativo" na linha quando o cliente da OC está inativo no cadastro do totem. Na aba Inativas a célula de situação mostra "Inativada em" e "PDF será apagado em" (15 dias após a inativação). `[CÓDIGO: decorarOrdens]`
- Aba **Baixas pendentes**: filtro "Mostrar" Pendentes (padrão), Resolvidas ou Todas, mais o período. Cada linha indica se a OC foi `localizada`, `ambígua` ou `não localizada` e só tem link para a OC quando é única. `[CÓDIGO: linhasBaixa]`

### Detalhe (`ordem.php?id=`, GET)

Mostra a OC pelo `id` da tabela externa (não pelo número), a situação, a idade, o aviso permanente de cliente inativo (`#ordem-cliente-inativo`), o estado do PDF (disponível, ausente ou "apagado automaticamente") e as ações Ativar/Inativar. Id inexistente: volta à lista com "Ordem de coleta não encontrada". `[CÓDIGO: GestaoOrdemController::detalhe]`

### Ativar ou inativar (confirmação em dois passos)

1. O botão envia POST com `id_ordem`, `acao`, `status_visto` (situação que o usuário viu) e `retorno`. `[CÓDIGO: alterarStatus]`
2. Se a ação exige confirmação, o servidor redireciona para `ordem.php?id=...&confirmar=<acao>` com o aviso e nada é alterado. O usuário confirma (novo POST com `confirmar=1`). Casos que exigem confirmação:
   - **inativar** com atendimento `em_andamento` do mesmo cliente + número;
   - **ativar** OC que já foi baixada por atendimento concluído;
   - **ativar** OC de cliente **inativo**.
3. Sucesso: volta à lista ou ao detalhe com "Ordem ativada/inativada". `[CÓDIGO]`

### Resolver baixa pendente

Botão "Marcar como resolvida" na aba Baixas, **um clique, sem confirmação** (decisão do usuário). Fica oculto quando a OC não é localizada ou é ambígua; com OC única o botão aparece, mas o servidor recusa se a OC estiver ATIVA. `[CÓDIGO: resolverBaixa]` (a ocultação do botão está na view, descrita na view; não relida linha a linha)

### PDF

Botão abre o PDF por **POST** (`ordem-pdf.php`), como download `oc-<id>.pdf`. Arquivo ausente: volta ao detalhe com a mensagem de que o PDF não está disponível (ele é apagado 15 dias depois da inativação). `[CÓDIGO: pdf]`

## Processamento interno

### Ativar/inativar por `id` com CAS

1. Lê a OC por `id`; recusa se inexistente (`oc_inexistente`), se a situação mudou desde a tela (`status_visto` diferente: `estado_mudou`) ou se já está no estado pedido (`ja_no_estado`); todas viram auditoria `SEM_EFEITO`. `[CÓDIGO]`
2. Calcula a necessidade de confirmação (acima) e, se confirmado, grava o motivo na auditoria: `confirmado_andamento`, `confirmado_ja_baixada` ou `confirmado_cliente_inativo` (com cliente inativo **e** OC já baixada, vale `confirmado_ja_baixada` por precedência). `[CÓDIGO]`
3. **Abre a auditoria `OC_INATIVAR`/`OC_ATIVAR` (PENDENTE) antes do UPDATE**, fora de transação; se não conseguir abrir, a ação é recusada (503) sem alterar. `[CÓDIGO]`
4. Executa o UPDATE **por `id` com compare-and-set na situação**: `... SET status='INATIVA', inativada_em=NOW() WHERE id=:id AND status='ATIVA'` (inativar) e `... SET status='ATIVA', inativada_em=NULL WHERE id=:id AND status='INATIVA'` (ativar). O resultado (efetivado, já no estado, inexistente, estado mudou) fecha a auditoria como `OK` ou `SEM_EFEITO`; erro do banco externo fecha como `ERRO` com `motivo_oc=externo_indisponivel`. `[CÓDIGO: OrdemColetaGestaoDao::inativarPorId/ativarPorId]` `[TESTE: teste_gestao_oc_dao]` `[TESTE: teste_gestao_ordens]`
5. Uma OC ativa volta a ser consultável pelo totem; uma inativa deixa de ser (fluxo [06](06-consulta-e-baixa-ordem-coleta.md)). O totem baixa a OC por **cliente + número** (CNPJ gravado no atendimento), não só pelo número. `[CÓDIGO: F4a]`

### Baixa pendente

A tabela `tb_ordem_coleta_pendente_baixa` (banco do totem) recebe uma linha quando o Talent aceitou o check-in mas a baixa da OC falhou (ou não pôde ser feita por número ambíguo/sem CNPJ). `resolver` (`OrdemColetaBaixaRn`):

1. Busca a baixa; inexistente: `INEXISTENTE`; já resolvida: `JA_RESOLVIDA` (ambos `SEM_EFEITO`).
2. Obtém o CNPJ do cliente (do atendimento) e o número; sem CNPJ ou número: recusa `cliente_ausente`.
3. Consulta os estados das OCs por cliente + número no banco externo: nenhuma (`oc_inexistente`), mais de uma (`oc_ambigua`), uma **ATIVA** (`oc_ativa`), erro de banco (`externo_indisponivel`).
4. Só com **OC única e INATIVA** marca `resolvido_em` com CAS (`resolvido_em IS NULL`). `[CÓDIGO: OrdemColetaBaixaRn::resolver]` `[TESTE: teste_ordem_coleta_pendente_baixa]` (cobre a pendência de baixa; não reexecutado)

Auditoria `OC_BAIXA_RESOLVER` (alvo `oc_baixa`) aberta antes; recusas fecham `SEM_EFEITO` com `motivo_oc`; falha técnica fecha `ERRO`. `[CÓDIGO: resolverBaixa]`

### PDF por POST com auditoria

1. Busca a OC e o registro do arquivo (`tb_ordem_coleta_arquivos`); sem registro: 404.
2. **Abre a auditoria `OC_VER_PDF` antes do primeiro byte**; se falhar, 503 e nada é enviado.
3. Confere que o caminho registrado começa pelo CNPJ da OC (14 dígitos + `/`); senão trata como `prefixo_cnpj_divergente`.
4. Lê o arquivo só por `OrdemColetaArquivoStorage::ler` (limite 5 MiB, confere `%PDF-`, tamanho e sha256 do registro). Falha de integridade: 500 genérico, `LogSistema gestao_erro_interno` com o motivo da lista fixa e auditoria `ERRO`; arquivo ausente: `SEM_EFEITO` e mensagem amigável.
5. Resposta: `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="oc-<id>.pdf"`, `Cache-Control: no-store, private`, `nosniff`, CSP `default-src 'none'; sandbox`, `X-Frame-Options: DENY`. Fecha a auditoria `OK` depois de enviar. O arquivo fica em `STORAGE_PATH/ordens_coleta/`, fora do webroot, e nunca é servido por URL direta. `[CÓDIGO: pdf]`

## Rotas

`GET /gestao/ordens.php`, `GET /gestao/ordem.php`, `POST /gestao/ordem-status.php`, `POST /gestao/ordem-baixa.php`, `POST /gestao/ordem-pdf.php` (todas `usuario`/`admin`; POST com CSRF). Ver [gestao-rotas](../apis/gestao-rotas.md).

## Mudanças de estado

- Banco externo `tb_ordens_coleta`: `status` (ATIVA/INATIVA) e `inativada_em`. A Gestão é uma das duas origens dessa mudança (a outra é a baixa pelo totem). `[CÓDIGO]`
- Banco do totem: `tb_ordem_coleta_pendente_baixa.resolvido_em`; `tb_gestao_auditoria`.
- O PDF não é alterado; o cron `limpar-anexos-ordem-coleta.php` o apaga 15 dias após a inativação. `[CÓDIGO: 2026-10-05]`

## Integrações

Banco externo de coletas (leitura e UPDATE de status); storage local de PDFs; n8n como origem do PDF (fluxo [10](10-anexo-ordem-coleta-n8n.md)). `[AMBIENTE-TERCEIRO]` para o banco real.

## Conclusão

Ativar/inativar: mensagem de sucesso e auditoria `OK`. Resolver baixa: baixa marcada como resolvida. PDF: download iniciado e auditoria `OK`. `[CÓDIGO]`

## Falhas e alternativas

- Banco externo indisponível: lista com 503 e aviso; ação com 503 "Ordens de coleta indisponíveis" (auditoria `ERRO` quando já aberta). `[CÓDIGO]`
- Situação mudou entre a tela e o clique: recusa com "A ordem mudou de situação"; nada é alterado.
- Auditoria não abre: 503 "Não foi possível registrar a ação na trilha de auditoria", sem alterar a OC nem enviar o PDF. `[CÓDIGO: auditoriaIndisponivel]`
- Pedido malformado: 400.

## Auditoria

`OC_ATIVAR`, `OC_INATIVAR`, `OC_VER_PDF`, `OC_BAIXA_RESOLVER` (alvos `ordem_coleta` e `oc_baixa`). `detalhe` só com `status_de`, `status_para` e `motivo_oc` (lista fechada), **sem dado pessoal**. Ver a lista e o detalhe não são auditados; só o PDF e as mudanças. Ver [fluxo 13](13-gestao-logs-auditoria.md). `[CÓDIGO: AuditoriaDao::DETALHE_CAMPOS]`

## Pendências conhecidas

Registradas sem correção:

- **S6**: uma exceção depois do COMMIT do UPDATE externo fecha a auditoria como `ERRO` mesmo que o estado já tenha mudado. `[PENDENTE DE CONFIRMAÇÃO]`
- **OCs sem máscara**: decisão posterior à de mascarar; todos os perfis veem os dados em claro (ver Perfis). `[CÓDIGO]`
- `tb_atendimento` só tem `idx_status`, sem índice composto por OC (sem migration).
- `leitura_falhou` do PDF não tem caso de teste dinâmico.
- Ativar OC de cliente inativo não tem efeito no totem enquanto o cliente continuar inativo (por isso o aviso e a confirmação). `[CÓDIGO: AVISO_CLIENTE_INATIVO]`
- Observações anteriores fora do escopo por decisão do usuário: n8n 406 anônimo e LiteSpeed sem `fastcgi_finish_request`.
- Incidente registrado: um teste legado foi rodado por engano contra o banco de desenvolvimento; sem efeito em produção; guardas de prepend de QA passaram a forçar também `GESTAO_COLETAS_DB_NAME`.
- Itens descartados pelo usuário e inexistentes: F5 (atendimentos do dia, travados, anexos órfãos), painel, status das integrações, saúde dos crons, fotos de notas do Recebimento.
- Testes: `[TESTE: teste_gestao_ordens]`, `[TESTE: teste_gestao_qa_ordens]`, `[TESTE: teste_gestao_oc_dao]`; não reexecutados.
