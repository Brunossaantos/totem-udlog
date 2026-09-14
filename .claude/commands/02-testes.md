---
description: "Fase 2 - testes: valida a implementacao feita na etapa anterior"
argument-hint: "[opcional: caminho do handoff, se nao for o mais recente]"
---

Argumento (se houver): $ARGUMENTS

1. Leia o handoff mais recente em `docs/handoffs/` (ou o passado em
   $ARGUMENTS) para saber exatamente o que foi implementado na etapa 01.
2. Delegue ao `qa-testes` a validação do que foi implementado — roteiro
   manual e/ou execução de testes automatizados, conforme o que existir
   no projeto.
3. Se a implementação tocou autenticação, banco de dados, upload de
   arquivo ou rota pública, delegue também ao `security-especialista`
   para revisão de segurança.
4. Colete os achados. Se houver falha ou problema de segurança, **não
   corrija aqui** — reporte claramente no handoff e sinalize que a demanda
   precisa voltar para `/01-implementacao` antes de seguir.
5. Acrescente ao handoff uma seção "## Resultado dos testes" com o que foi
   validado e o que falhou, se algo falhou.
6. Delegue ao `trello-especialista` (usando o `card_id` do handoff):
   comentar no cartão o resultado dos testes e as falhas encontradas, se
   houver. Falha do Trello não bloqueia nem reverte o resultado real dos
   testes — só registre o bloqueio no handoff e continue.

Não avance para `/03-revisao` se houver falha crítica pendente.
