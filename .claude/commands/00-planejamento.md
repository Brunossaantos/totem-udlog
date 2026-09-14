---
description: "Fase 0 - planejamento: le o estado real, define o que sera implementado e como, e gera o handoff"
argument-hint: "[descrição da demanda]"
---

Demanda recebida: $ARGUMENTS

Execute o planejamento desta demanda, nesta ordem:

1. Leia `ia_development_state.md` por completo.
2. Se a demanda envolve `OrdemColetaClient`, `TalentClient` ou
   `ProdespClient`, confira `docs/db_gestao_coletas.md` e
   `docs/manual_talent.md` — se o contrato real ainda não estiver
   preenchido, isso é uma pendência que entra no relatório, não um motivo
   pra inventar o contrato.
3. Delegue ao `explorer` para confirmar o estado real do código relevante
   à demanda, se houver qualquer dúvida sobre o que já existe.
4. Delegue aos especialistas necessários (`backend-especialista`,
   `frontend-especialista`, `ui-ux-especialista`, `devops-especialista`,
   `security-especialista` conforme a demanda) pedindo **apenas um plano**
   de como cada um implementaria sua parte — nenhuma implementação real
   ainda nesta etapa.
5. Consolide um relatório de planejamento com:
   - Objetivo da demanda
   - Escopo (o que será tocado)
   - Explicitamente o que NÃO será tocado
   - Sub-agentes envolvidos e o que cada um vai fazer na etapa 01
   - Riscos e pendências identificadas
6. Ao final do relatório, escreva o `chatgpt_handoff` seguindo o template
   abaixo e salve em `docs/handoffs/AAAA-MM-DD-<slug-da-demanda>.md`
   (use a data real de hoje).
7. Delegue ao `trello-especialista`: localizar (por `card_id` de uma
   demanda relacionada anterior, se existir) ou criar o cartão na lista
   "Sprint Bruno - Fazendo [Semanal]" com o título
   `Bruno: sistema totem - <descrição da atividade>`, e comentar nele o
   resumo do planejamento. Registre o `card_id` retornado no campo
   "Trello" do handoff. Se o Trello falhar por qualquer motivo, registre
   o bloqueio no handoff e continue normalmente — falha do Trello nunca
   impede o planejamento de seguir.

## Template do chatgpt_handoff

```
# Handoff — <slug da demanda>

Data: <AAAA-MM-DD>
Etapa: 00-planejamento

## O que foi pedido
<resumo da demanda>

## O que será feito
<resumo do plano consolidado>

## O que NÃO será feito
<escopo explicitamente fora>

## Sub-agentes envolvidos
<lista>

## Pendências conhecidas
<lista, ou "nenhuma">

## Trello
card_id: <id retornado pelo trello-especialista, ou "N/A - Trello indisponível/não configurado">

## Próximo passo
Rodar /01-implementacao para executar este plano.
```

Não implemente nada nesta etapa — só planeje e documente (a criação/
atualização do cartão do Trello não conta como implementação do domínio
do totem, é só registro de acompanhamento).
