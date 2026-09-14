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
5. Confirme, via `git rev-parse HEAD` e `git rev-parse origin/main` (ou
   `git status` logo após o push), que `HEAD` local bate exatamente com
   `origin/main` — só com essa confirmação real o passo 6 pode acontecer.
6. Delegue ao `trello-especialista` (usando o `card_id` do handoff):
   comentar no cartão o hash e o resumo do commit — incluindo no próprio
   texto do comentário a data de conclusão (ex: "Concluído em
   2026-09-11 — hash ...") — e mover o cartão para a lista "Sprint -
   Feito". Depois de mover, chamar `marcarConcluida()` (ou o subcomando
   `marcar-concluida` do CLI) com a data real de conclusão (data de hoje,
   ou a data do commit), para que o Trello grave o selo nativo de data
   com check verde diretamente no cartão — não só em texto de comentário
   solto. Isso só acontece DEPOIS da confirmação do passo 5 — nunca
   antes. Se o Trello falhar (rede, credencial, lista não encontrada),
   registre o bloqueio no handoff e mantenha o cartão onde estiver — a
   falha do Trello não desfaz nem repete o commit/push já concluído.
7. Feche o handoff acrescentando "## Commit" com o hash e a mensagem do
   commit (e o resultado da movimentação do cartão do Trello, ou o
   bloqueio, se houver).
8. Confirme que `ia_development_state.md` já está com o "Log de mudanças"
   atualizado (feito na etapa 01) — se não estiver, atualize agora antes
   do commit final.
