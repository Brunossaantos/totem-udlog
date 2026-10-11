# Changelog

Registro das mudanças do totem-udlog, no espírito do [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/), adaptado a este projeto.

**Adaptação:** o projeto não tem tags nem versões numeradas (`git tag` vazio; 90 commits em `main`, de 2026-09-04 a 2026-10-09). Por isso as entradas são organizadas por **marcos**, com a data e o hash reais dos commits, e não por versão. Os marcos foram agrupados a partir do histórico do Git e do `ia_development_state.md`; os nomes dos marcos (M1 a M11) são apenas rótulos de leitura.

Categorias usadas: Adicionado, Alterado, Corrigido, Removido, Documentação.

Os handoffs das demandas ficam em `docs/handoffs/`, pasta ignorada pelo Git; só o handoff `docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md` está versionado e é linkado no marco M6.

## [Não publicado]

Rodada de documentação completa do projeto e a mudança de comportamento do check-in no Talent descrita abaixo (decisão do usuário em 2026-10-11; implementada, revisada e ainda não commitada):

### Adicionado
- Tela `guiche_portaria` em `public/totem/assets/impressao.js` (Expedição e Recebimento): "Não foi possível concluir seu check-in. / Dirija-se ao guichê da portaria.", um único botão "Voltar ao início", sem retry, ajudante nem impressão; a inatividade de 180 s volta ao início sem aviso.
- Testes: `tests/manual/teste_talent_sem_registro.php` (novo; o subprocesso `_caso_finalizar_talent_sem_registro.php` é ignorado pelo `.gitignore` e entra por `git add -f`); `teste_talent_client_parsing.php` e `vio_captura_layout.js` ampliados.
- Pasta `documentacao/` com a documentação do projeto, incluindo [`documentacao/implantacao.md`](documentacao/implantacao.md) (requisitos da hospedagem, `.env`, banco, permissões, crons, atualização, smoke, rollback e divergências), [`documentacao/banco-dados.md`](documentacao/banco-dados.md) e [`documentacao/fluxos/`](documentacao/fluxos/README.md).
- Este `CHANGELOG.md`.

### Alterado
- **Talent 2xx sem `nrRegAcesso` cancela o atendimento e orienta o guichê.** Antes, a resposta 2xx sem número utilizável gravava `ENVIADO`/`concluido` com senha nula. Agora `TalentClient` lança `resposta_ilegivel`, `TalentRn` cancela o atendimento (`ENVIO_INDETERMINADO` + `cancelado`, sem sobrescrever `bloqueado`/`concluido`) e `finalizar` responde 422 `TALENT_SEM_REGISTRO` (`dados.acao=guiche_portaria`, sem senha). As fotos das notas vão à quarentena e, na Expedição, a OC não é baixada. Arquivos de código: `app/Rn/TalentClient.php`, `app/Rn/TalentRn.php`, `app/Dao/AtendimentoDao.php`, `app/Controller/AtendimentoController.php`, `public/totem/assets/app.js`, `public/totem/assets/impressao.js`.
- **`CONCLUIR_EXIGE_NUMERO_NOTA`:** decisão do usuário em 2026-10-11 de ativar em produção (valor no servidor a confirmar); padrão do código e do `.env.example` segue `false`. Documentada a diferença entre ligada (422 `NOTAS_SEM_NUMERO` na digitalização) e desligada (só o `finalizar` recusa número vazio).
- `servico-impressao-local/README.md` atualizado.
- `ia_development_state.md` atualizado (entrada de 2026-10-11, pendências P69 e nova pendência de baixa severidade: a portaria só identifica o caso pelo `tb_log_sistema`).
- `.env.example` revisado contra todas as leituras de variáveis em `app/`, `util/`, `public/`, `cron/`, `tools/` e `servico-impressao-local/`: organizado em OBRIGATÓRIAS e OPCIONAIS, com comentários curtos e valores fictícios (hosts `exemplo.invalid`, `TALENT_CHECKIN_ATIVO=false`, chaves vazias, `GESTAO_HASH_SALT` vazio).
- `README.md` reescrito.
- Rodada corretiva (2026-10-10) da documentação: registrados como confirmados pelo responsável do projeto os 5 crons em execução, PHP web 8.3, `TALENT_CHECKIN_ATIVO=true` em produção, instalação de dependências por `composer install --no-dev --optimize-autoloader` e `display_errors=Off`; removida a pendência de contrato da vio.api.br (só o comportamento comprovado por código e testes é documentado); validação de método HTTP nas APIs do totem e política de retenção de atendimentos, fotos e aceites LGPD passam a decisão de produto "não será tratado"; contagem de telas do front corrigida para 26; dump do banco externo atualizado (somente estrutura, 2026-10-10).

### Removido
- Do `.env.example`, variáveis sem consumidor no código: `DOCUMENTO_QR_HMAC_KEY`, `VIO_API_BR_CACHE_TTL_DIAS`, `VIO_API_BR_CACHE_HMAC_KEY_V1`, `VIO_API_BR_CACHE_HMAC_VERSION`, `IMPRESSORAS_PERMITIDAS` e `IMPRESSAO_TIMEOUT_MS` (as duas últimas pertencem ao `config.json` do serviço de impressão).

## M11. Higienização e encerramento (2026-10-09)

Fim da Gestão Totem; repositório higienizado.

### Alterado
- `6ac26e9` — `sql/schema.sql` completado com a estrutura atual (aceite LGPD, cache vio.api.br, colunas `vio_api`, modo de captura da CNH, `client_uid`); provado equivalente à estrutura de produção.
- `95d11c5` — a infraestrutura de teste passa a aplicar só o `schema.sql`.
- `d783768` — comentários removidos do código-fonte.

### Corrigido
- `9d44513` — o navegador do mini PC deixa de sugerir dados de motoristas anteriores nos campos de digitação do quiosque (`autocomplete="off"`).

### Removido
- `1a69a1c` — migrations (`sql/migrations/` e `sql/migrations_gestao_coletas/`) e o `favicon.ico` não referenciado da gestão; o `sql/schema.sql` é a única fonte da estrutura.
- `1d74db9` — ferramenta de recálculo da razão normalizada (recálculo descartado pelo usuário).

### Documentação
- `4188fee` — estado atualizado com a higienização e a prova do schema.

## M10. Gestão Totem: logs, ordens de coleta, cadastros (2026-10-08 a 2026-10-09)

### Adicionado
- `c4411ce` (2026-10-08) — logger central (`tb_log_sistema`, `LogSistema`) e retenção de 90 dias de logs e auditoria (`cron/limpar-logs-gestao.php`).
- `5b91bd6` (2026-10-08) — tela de logs para administrador (abas, filtros, paginação e detalhe).
- `d1ffda5` (2026-10-08) — instrumentação do `LogSistema` nos pontos de erro, com teto por categoria e throttle de escrita anônima.
- `325fe32` (2026-10-09) — ordens de coleta na gestão (lista, detalhe, ativar/inativar, baixas pendentes e PDF); o totem passa a baixar a OC por cliente e número.
- `51c7ceb` (2026-10-09) — cadastro de clientes e empresas (criar, editar, inativar, excluir) com aviso de ambiguidade do OCR.
- `2903857` (2026-10-09) — normalização de razão social sem acentos, independente de locale.

### Alterado
- `6ff47ff` (2026-10-09) — F4 oculta "Marcar como resolvida" sem OC única, mostra a data de inativação e avisa cliente inativo.

### Documentação
- `f60ebc2` (2026-10-09) — estado: deploy da F4 e verificação do usuário.
- `3be1665` (2026-10-09) — estado: demanda da gestão fechada.

## M9. Gestão Totem: fundação e totens (2026-10-06 a 2026-10-08)

### Adicionado
- `fae1d05` (2026-10-06) — fundação da Gestão Totem (login, perfis, usuários, auditoria), `tools/criar-admin.php` e endurecimento da página do totem.
- `fab53ab` (2026-10-08) — tela de Totens (criar, ativar/desativar, regerar URL) com código `NOME-EMPRESA-HASH16`.
- `93f3751` (2026-10-08) — leão UDLOG na barra lateral recolhida.

### Corrigido
- `9aec6cb` (2026-10-08) — o teclado virtual passa a disparar o evento `input`, liberando o autocomplete de cliente.

### Documentação
- `785ad6f` (2026-10-06), `2c8fa93` e `93baea1` (2026-10-08) — estado atualizado (deploys, diagnóstico da busca de cliente, troca da URL do mini PC).

## M8. Anexo da OC (n8n), remoção da fila do Talent (2026-10-05 a 2026-10-06)

### Adicionado
- `3bc0497` (2026-10-05) — anexo da Ordem de Coleta enviado pelo n8n (`public/api/ordem-coleta-anexo.php`) e anexado ao check-in do Talent; cron `limpar-anexos-ordem-coleta`.

### Removido
- `6a3f353` (2026-10-05) — fila de reenvio ao Talent (`tb_fila_envio`, `FilaEnvioDao`, `cron/reenviar-fila.php`); não há reenvio automático.

### Corrigido
- `ea2c291` (2026-10-06) — impressão: listagem de impressoras via PowerShell JSON e detecção de falta de papel pelo nome base do documento.

## M7. Fluxo QR de CNH/CRLV, confirmação, etiquetas e impressão (2026-10-01 a 2026-10-04)

### Adicionado
- `deb0901`, `cea5c36` (2026-10-01) — fluxo exclusivo por QR para CNH e CRLV (backend e tela de captura).
- `c2dcd0c` (2026-10-04) — RNTRC/Tipo na confirmação, `mensagem_api` do Talent, correção do ajudante e etiqueta em ASCII/48 mm.
- `6933cbc` (2026-10-04) — leitura de QR, ajustes de tela e serviço de impressão local com tarefa de logon.
- `75e4bb1` (2026-10-04) — favicon do totem, título "Totem" e padronização dos botões do modal do número da nota.

### Corrigido
- `0a8c800`, `db7c9b1` (2026-10-01) — `motivo_usuario`, re-escaneio, dados de confirmação, progresso e modal de reprovação.

## M6. Recebimento: OCR/notas e hardening da revisão (2026-09-30 a 2026-10-01)

### Adicionado
- `3a34c9f`, `077675d`, `a21411a` (2026-09-30) — foto da nota em pé, confirmação dos números só ao finalizar a digitalização e OCR de passada única por nota.
- `b37b7fc`, `3c3c18a` (2026-10-01) — conclusão, exclusão, abandono e reconciliação de notas reforçados (quarentena de fotos e crons de abandono e limpeza).

### Corrigido
- `97ae7c9` (2026-09-30) — assets versionados no `index` para evitar cache antigo do navegador.

### Removido
- `2ec9093` (2026-09-30) — integração com o Trello; arquivos de IA e `docs/` deixam de ser versionados.

### Documentação
- `fb13a2f` (2026-10-01) — fecha o hardening da revisão e a identificação do cliente. Handoff: [`docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md`](docs/handoffs/2026-09-30-hardening-revisao-notas-e-cliente.md).

## M5. vio.api.br e CNH digital (2026-09-26 a 2026-09-28)

### Alterado
- `d56c035` (2026-09-26) — a integração direta com o Serpro é substituída pela vio.api.br, com cache seguro.

### Adicionado
- `d5212cc` (2026-09-28) — suporte à CNH digital de uma página.

### Removido
- `9da2516` (2026-09-28) — legado do Serpro; processamento de documentos reforçado.

### Corrigido
- `bd26c91` (2026-09-28) — tamanho dos botões.

### Documentação
- `5f96bfa`, `5a61c34`, `9306b1c` — fechamento das demandas deste marco.

## M4. LGPD (2026-09-25)

### Adicionado
- `88c6a31` — aceite LGPD na tela inicial do totem.

### Corrigido
- `30f70c0`, `4cef4a0` — interface final, termo atualizado (aprovação do DPO: ver `documentacao/regras-negocio.md`, **[PENDENTE DE CONFIRMAÇÃO]**), estilo obsoleto removido e alvo do modal ajustado.

### Alterado
- `453f44b` — testes manuais independentes de helpers órfãos.

### Documentação
- `5ad2a4e` — aprovações registradas e validação física preparada.

## M3. Hardening de integridade, JPEG, rate limit e VIO (2026-09-17 a 2026-09-20)

### Corrigido
- `a4b8e0a` (2026-09-17) — proteção da conclusão do atendimento e sanitização de falhas de banco.
- `03af1df` (2026-09-17) — validação segura de imagens JPEG.
- `1e244cc` (2026-09-19) — rate limit, bootstrap, limpeza e migrations (cron `limpar-rate-limit-ocr`).
- `997139c`, `761c0ff`, `4c8665f` (2026-09-20) — VIO: validação e sanitização sem credenciais, faixa de exercício do CRLV (QR e fluxo manual).

### Documentação
- `735390a`, `7b54de8`, `e56cd05`, `a19f4e1`, `0dcf06c`, `ac7fc1b` — fechamento das demandas deste marco.

## M2. Impressão (serviço local) e Talent (2026-09-14 a 2026-09-16)

### Adicionado
- `44ec896` (2026-09-14) — serviço local de impressão de etiquetas (`servico-impressao-local/`).
- `2369f96` (2026-09-15) — finalização do check-in no Talent com `doctos` e impressão.
- `853857a` (2026-09-16) — CORS/PNA de desenvolvimento e origem de produção do serviço de impressão documentada.

### Alterado
- `b6b5129` (2026-09-15) — fluxos de teste e de produção da impressão separados.

### Corrigido
- `5332a86` (2026-09-15) — validação do conflito HTTP 409 do Talent e encerramento da limpeza.

### Documentação
- `a26dba8` (2026-09-14, integração de workflow com o Trello, removida em `2ec9093`), `0859a49`, `9285147`, `d5aa514`, `a5bb08a`, `fc970a5`, `f7bbbdd`, `93dc551`, `a8a0454`, `6510634`, `effab4b`, `8ddf399`, `e4d53ea`, `1f4d3d5` — fechamento de handoffs e saneamento da lista de pendências.

## M1. Fundação: scanner, clientes, VIO/Talent e ordem de coleta (2026-09-04 a 2026-09-11)

### Adicionado
- `22eeba0` (2026-09-04) — integração com o scanner Netum para notas (Recebimento).
- `b6ab1b0` (2026-09-08) — identificação de clientes pela base local.
- `89b8b5d` (2026-09-09) — integração com a VIO e preparação do check-in no Talent.
- `bdc8d91` (2026-09-11) — consulta real de ordem de coleta por placa (Expedição).

### Documentação
- `ae7f162` (2026-09-04), `a6d668a` (2026-09-11) — fechamento de handoffs.

## Deploys registrados

Não são versões. Servidor de produção, segundo `ia_development_state.md`: `6a3f353` em 2026-10-05; `fab53ab` em 2026-10-08 (Gestão F0 a F2); até `9d44513` em 2026-10-09 (estado) e `4188fee` (checklist de deploy). O ponto final de deploy não está reconciliado entre as duas fontes.
