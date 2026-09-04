---
description: "Fase 4 - commit e push: finaliza a demanda e registra a mudanca"
argument-hint: "[opcional: caminho do handoff, se nao for o mais recente]"
allowed-tools: Bash(git add:*), Bash(git commit:*), Bash(git push:*), Bash(git status:*), Bash(git diff:*)
---

Argumento (se houver): $ARGUMENTS

1. Leia o handoff mais recente em `docs/handoffs/` (ou o passado em
   $ARGUMENTS) para confirmar que a etapa 03 concluiu como "aprovado".
   Se não concluiu, pare e informe que a demanda ainda não está pronta
   para commit.
2. Rode `git status` e `git diff` para conferir exatamente o que vai
   entrar no commit — nada além do que está descrito no handoff.
3. Faça o commit com mensagem no padrão Conventional Commits, resumindo
   objetivamente o que foi feito (referencie o slug do handoff).
4. Push para o repositório remoto.
5. Feche o handoff acrescentando "## Commit" com o hash e a mensagem do
   commit.
6. Confirme que `ia_development_state.md` já está com o "Log de mudanças"
   atualizado (feito na etapa 01) — se não estiver, atualize agora antes
   do commit final.
