# Totem de autoatendimento UDLOG

Projeto novo do zero — fluxos de Expedicao e Recebimento, multi-totem.

## Antes de rodar

1. `composer install`
2. Copiar `.env.example` para `.env` e preencher
3. Rodar `sql/schema.sql` no MariaDB
4. Ajustar `STORAGE_PATH` para um diretorio FORA do public_html (LGPD — fotos de CNH/CPF nao podem ficar acessiveis por URL)
5. Cadastrar ao menos um totem na tabela `tb_totem` com um `codigo` e `token_api`
6. Apontar o Chromium em modo kiosk para `https://SEUDOMINIO/totem/?totem=CODIGO_DO_TOTEM`

## Pendencias reais

Ver a secao 5 de `ia_development_state.md` — essa e a fonte unica de
verdade sobre o que falta, nao esse README.

## Workflow com Claude Code (sub-agentes + orquestrador)

Este projeto ja vem com uma estrutura de desenvolvimento assistido por IA
pronta em `.claude/`:

- `.claude/agents/` — orquestrador + 7 sub-agentes especialistas
  (backend, frontend, ui-ux, devops, security, qa-testes, explorer)
- `.claude/commands/` — os 5 comandos do workflow: `/00-planejamento`,
  `/01-implementacao`, `/02-testes`, `/03-revisao`, `/04-commit-e-push`
- `.claude/settings.json` — faz o `orquestrador` ser o agente principal
  de toda sessao neste projeto automaticamente
- `CLAUDE.md` — instrucoes carregadas automaticamente em toda sessao
- `ia_development_state.md` — estado real do projeto, leitura obrigatoria
  antes de qualquer implementacao (o proprio orquestrador ja faz isso)
- `docs/contratos-comunicacao.md` — formato fixo de como o orquestrador
  fala com os sub-agentes e vice-versa
- `docs/db_gestao_coletas.md` e `docs/manual_talent.md` — cole aqui a
  documentacao real dessas duas APIs assim que tiver acesso a elas

### Como usar

```bash
cd totem-udlog
claude
```

Como `.claude/settings.json` ja define `"agent": "orquestrador"`, a
sessao ja inicia com ele. Basta pedir a demanda normalmente, ou seguir o
workflow explicitamente:

```
/00-planejamento adicionar validacao de placa no formato Mercosul
```

Isso gera um relatorio de plano + um handoff em `docs/handoffs/`. Depois
`/01-implementacao`, `/02-testes`, `/03-revisao` e `/04-commit-e-push`
seguem o mesmo handoff nas etapas seguintes.
