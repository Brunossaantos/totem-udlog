---
description: "Fase 1 - implementacao: executa o plano aprovado com os sub-agentes especialistas"
argument-hint: "[opcional: caminho do handoff, se nao for o mais recente]"
---

Argumento (se houver): $ARGUMENTS

1. Leia `ia_development_state.md`.
2. Localize o handoff mais recente em `docs/handoffs/` (ou o caminho
   passado em $ARGUMENTS, se houver) e leia o plano da etapa 00.
3. Delegue a implementação real aos especialistas definidos no plano,
   estritamente dentro do escopo descrito ali — não amplie, não corrija
   "de passagem" algo que não estava no plano.
4. Depois que cada especialista responder (no formato do contrato em
   `docs/contratos-comunicacao.md`), colete os arquivos alterados/criados
   e as pendências reportadas.
5. Atualize `ia_development_state.md`:
   - Adicione uma linha na seção "Log de mudanças" com a data e um resumo
     objetivo do que foi implementado
   - Se alguma pendência da seção 5 foi resolvida, remova-a ou marque como
     resolvida
   - Se surgiu pendência nova, adicione na seção 5
6. Atualize o handoff da etapa 00 (mesmo arquivo) acrescentando uma seção
   "## Resultado da implementação" com o que foi feito e as pendências
   restantes.

Não pule para testes ou revisão — isso é `/02-testes` e `/03-revisao`.
