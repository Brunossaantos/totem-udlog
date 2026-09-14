# totem-udlog — instruções do projeto para o Claude Code

## O que é

Totem de autoatendimento físico da UDLOG (Expedição e Recebimento). Stack:
PHP 8 MVC + MariaDB + front-end vanilla JS, hospedado na Hostgator
(hospedagem compartilhada). Detalhes completos do estado atual estão em
`ia_development_state.md` — leia esse arquivo, não repita aqui.

## Regras inegociáveis

1. **Leia `ia_development_state.md` antes de planejar ou implementar
   qualquer coisa.** É a fonte única de verdade do estado real do projeto.
2. **Nunca invente.** Contrato de API não confirmado, campo de banco não
   existente, decisão de produto não tomada — tudo isso fica registrado
   como pendência em `ia_development_state.md`, nunca é suposto.
3. **Nunca sugira nada que não foi pedido.** Se notar algo relevante fora
   do escopo da demanda atual, registre a observação, não implemente por
   conta própria.
4. **Identidade visual** segue o padrão de https://udlog.com.br/. A
   paleta exata ainda está pendente de confirmação — ver seção 4 de
   `ia_development_state.md`.
5. **Segurança não é opcional**: toda query usa PDO com prepared
   statements (nunca concatenar SQL), toda rota da API exige o token do
   totem (`Util\Auth`), `storage/` fica sempre fora do `public_html` (nunca
   acessível direto por URL), toda entrada de usuário é validada.

## Sub-agentes disponíveis

| Sub-agente | Quando usar |
|---|---|
| `explorer` | Mapear o estado real do código/banco antes de decidir algo |
| `backend-especialista` | PHP, MySQL, endpoints, integrações externas |
| `frontend-especialista` | HTML/CSS/JS do totem, telas, câmera, teclado |
| `ui-ux-especialista` | Layout, identidade visual, usabilidade touchscreen |
| `devops-especialista` | Ambiente Hostgator, cron, `.env`, deploy |
| `security-especialista` | Revisão de autenticação, SQL injection, exposição de pasta |
| `qa-testes` | Roteiro e execução de testes |
| `trello-especialista` | Integração do workflow com o Trello (quadro Infraestrutura - Matriz) |

Delegação e resposta seguem sempre o contrato em
`docs/contratos-comunicacao.md`.

## Workflow de 5 etapas

Cada demanda de implementação passa pelos comandos, nesta ordem:

1. `/00-planejamento` — relatório do que e como será implementado + gera o handoff
2. `/01-implementacao` — implementação real, dentro do escopo definido em 00
3. `/02-testes` — validação do que foi implementado
4. `/03-revisao` — revisão final (código, segurança, UX)
5. `/04-commit-e-push` — commit, push, atualização de `ia_development_state.md`

## Documentação de APIs externas

`docs/db_gestao_coletas.md` e `docs/manual_talent.md` recebem a
documentação oficial das APIs externas conforme ela for confirmada.
Enquanto um desses arquivos não estiver preenchido, o contrato daquela
integração é tratado como placeholder — nenhum sub-agente assume que está
confirmado.
