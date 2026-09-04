---
description: "Fase 3 - revisao: revisao final de codigo, seguranca e UX contra o que foi planejado"
argument-hint: "[opcional: caminho do handoff, se nao for o mais recente]"
---

Argumento (se houver): $ARGUMENTS

1. Leia o handoff mais recente em `docs/handoffs/` (ou o passado em
   $ARGUMENTS), incluindo o plano original da etapa 00 e o resultado dos
   testes da etapa 02.
2. Confirme que o que foi implementado corresponde ao que foi planejado
   — nem menos, nem mais do que foi pedido.
3. Delegue revisão cruzada:
   - `security-especialista` — se ainda não revisou nesta demanda
   - `ui-ux-especialista` — se a demanda tocou front-end, confirma
     conformidade com a identidade visual e usabilidade touchscreen
4. Liste qualquer ajuste necessário antes do commit. Se houver ajuste,
   sinalize que a demanda volta para `/01-implementacao` para o ajuste
   pontual — não corrija diretamente nesta etapa.
5. Acrescente ao handoff uma seção "## Resultado da revisão" com a
   conclusão (aprovado / precisa de ajuste, e qual).

Só siga para `/04-commit-e-push` se o resultado for "aprovado".
