# Integração com o Trello — workflow de 5 etapas do totem-udlog

Documentação da integração do totem-udlog com o quadro Trello
"Infraestrutura - Matriz", usada pelo workflow `/00-planejamento` até
`/04-commit-e-push`. Regras completas de idempotência e segurança estão
em `.claude/agents/trello-especialista.md` — este documento cobre
configuração, IDs resolvidos e uso do CLI.

## Configuração

- Quadro: "Infraestrutura - Matriz" — `TRELLO_BOARD_ID=654015c3d29cc34bc1b881f6`
  (não é segredo, valor real já em `.env.example`).
- Lista inicial: "Sprint Bruno - Fazendo [Semanal]"
- Lista final: "Sprint - Feito"
- Título de cartão padrão: `Bruno: sistema totem - <descrição da atividade>`

### Variáveis de ambiente (`.env`)

| Variável | Segredo? | Observação |
|---|---|---|
| `TRELLO_API_KEY` | Sim | Nunca em `.env.example`, nunca logada |
| `TRELLO_API_TOKEN` | Sim | Nunca em `.env.example`, nunca logada |
| `TRELLO_BOARD_ID` | Não | ID do board, valor real em `.env.example` |
| `TRELLO_LISTA_FAZENDO_ID` | Não (é só um ID de lista) | Resolvido via CLI (ver abaixo), vazio no `.example` |
| `TRELLO_LISTA_FEITO_ID` | Não (é só um ID de lista) | Resolvido via CLI (ver abaixo), vazio no `.example` |

### IDs de lista resolvidos (2026-09-11)

Resolvidos ao vivo contra o board real com
`php tools/trello-cli.php buscar-lista "<nome exato>"`, exigindo
correspondência exata e única (nunca aproximada):

- `TRELLO_LISTA_FAZENDO_ID` = `654015f94854311ccf1895b1`
  ("Sprint Bruno - Fazendo [Semanal]")
- `TRELLO_LISTA_FEITO_ID` = `654015fb256ea4b83b4a8816`
  ("Sprint - Feito")

Já gravados no `.env` real do projeto (não no `.env.example`).

## Cliente PHP

`app/Rn/TrelloClient.php` — curl puro (sem dependência nova), mesmo
padrão de `App\Rn\TalentClient`/`App\Rn\VioDecodeClient`. Timeout de 8s
por chamada (`CURLOPT_TIMEOUT`).

Métodos:

- `validarConexao(): bool`
- `buscarListaPorNome(string $nomeExato): array` — exige correspondência
  exata e única; lança `TrelloClientException` (`lista_nao_encontrada` ou
  `lista_ambigua`) caso contrário — nunca escolhe "a mais parecida".
- `criarCartao(string $idLista, string $titulo, ?string $descricao = null): array`
- `listarCartoesDaLista(string $idLista): array` — retorna `[{id, name}, ...]`
  dos cartões abertos (não arquivados) de uma lista; usado para checagem
  de idempotência por título quando ainda não existe `card_id` salvo no
  handoff da demanda.
- `consultarCartao(string $idCartao): array`
- `adicionarComentario(string $idCartao, string $texto): array`
- `moverCartao(string $idCartao, string $idListaDestino): array`
- `marcarConcluida(string $idCartao, ?string $dataIso8601 = null): array` —
  define `due` (data/hora ISO 8601; usa a data/hora atual do servidor em
  UTC se `$dataIso8601` for omitido) e `dueComplete=true` via
  `PUT /1/cards/{id}`. Esse é o mecanismo nativo do Trello para "data de
  conclusão": o cartão passa a exibir um selo de data com check verde
  diretamente nele, visível no board sem precisar abrir o cartão. Usado
  na etapa `/04-commit-e-push`, depois de mover o cartão para "Sprint -
  Feito", para registrar a data real de conclusão de forma visível (não
  só em texto de comentário).

Erros são sempre traduzidos para `App\Rn\TrelloClientException` com
categoria fechada (`erro_autenticacao`, `nao_encontrado`,
`erro_validacao`, `rate_limit`, `erro_servidor`, `erro_http`, `timeout`,
`erro_conexao`, `resposta_ilegivel`, `lista_nao_encontrada`,
`lista_ambigua`) — a mensagem da exceção NUNCA contém a
key/token/URL completa da requisição.

### Autenticação (nota técnica)

A API REST v1 do Trello documenta autenticação via parâmetros `key` e
`token` na própria requisição (query string em GET, corpo
`application/x-www-form-urlencoded` em POST/PUT). `TrelloClient` injeta
esses dois parâmetros **somente dentro de `requisitar()`**, nunca em
nenhum outro ponto do código — a URL/corpo completo com credencial nunca
é logado, impresso, ou incluído em exceção.

## CLI

`tools/trello-cli.php` — subcomandos de linha de comando, PHP 8,
compatível Windows, sem dependência nova:

```
php tools/trello-cli.php validar-conexao
php tools/trello-cli.php buscar-lista "Sprint Bruno - Fazendo [Semanal]"
php tools/trello-cli.php criar-cartao <idLista> "Bruno: sistema totem - <descrição>" ["<descrição longa opcional>"]
php tools/trello-cli.php listar-cartoes <idLista>
php tools/trello-cli.php consultar-cartao <idCartao>
php tools/trello-cli.php comentar <idCartao> "<texto>"
php tools/trello-cli.php mover-cartao <idCartao> <idListaDestino>
php tools/trello-cli.php marcar-concluida <idCartao> [dataIso8601Opcional]
```

Nenhum comando imprime `TRELLO_API_KEY`/`TRELLO_API_TOKEN` em nenhuma
circunstância — falhas saem em `STDERR` como
`ERRO: Trello: <categoria>`.

## Idempotência e segurança (resumo operacional)

- Nunca criar cartão duplicado para a mesma demanda — reutilizar o
  `card_id` já salvo no handoff quando existir.
- Nunca mover cartão que já está na lista de destino.
- Nunca postar o mesmo comentário de etapa duas vezes.
- Falha do Trello (rede, credencial, rate limit, lista/cartão não
  encontrado) nunca pode impedir/reverter commit, push, teste ou qualquer
  etapa real do workflow do totem — apenas é registrada como pendência.
- Credenciais somente em `.env` (nunca em `.env.example`, código, log,
  documentação ou mensagem de exceção).

## Validação real (2026-09-11) — integração ponta a ponta confirmada

O bloqueio inicial (token só com escopo de leitura, chamadas de escrita
retornando HTTP 401) foi resolvido pelo usuário, que gerou um novo
`TRELLO_API_TOKEN` com escopo de leitura E escrita e atualizou o `.env`.
Após a troca, todas as operações foram validadas com sucesso contra o
board real:

- `validar-conexao` — OK.
- `criar-cartao` — cartão de teste `Bruno: Sistema totem - Teste`
  criado com sucesso na lista "Sprint Bruno - Fazendo [Semanal]", já
  usando `pos=top` (ver abaixo).
- `comentar` — comentário adicionado com sucesso.
- `consultar-cartao` — confirma dados do cartão real.
- `mover-cartao` — testado movendo um cartão descartável de "Fazendo"
  para "Feito", `idList` alterado corretamente.
- `marcar-concluida` — testado no mesmo cartão descartável, `due`/
  `dueComplete` confirmados via `consultar-cartao` após a chamada.

### Cartões criados no topo da lista

`criarCartao()` sempre cria o cartão na posição de topo por padrão
(`pos=top`, parâmetro `POST /1/cards`), confirmado comparando o campo
`pos` do cartão novo contra os demais cartões da lista (menor valor =
primeiro). Não é preciso passar nada extra para isso acontecer — é o
comportamento padrão do método/CLI.

### Data de conclusão ao mover para "Feito"

Ao concluir a etapa `/04-commit-e-push`, depois de confirmar
`HEAD == origin/main` e mover o cartão para "Sprint - Feito", o
`trello-especialista` também chama `marcarConcluida()` (ou
`marcar-concluida` via CLI) com a data real de conclusão — isso grava
`due`/`dueComplete=true` no cartão, que passa a exibir um selo de data
com check verde diretamente no board (visível sem abrir o cartão). A
mesma data também é incluída no texto do comentário de fechamento (ex:
"Concluído em 2026-09-11 — hash ..."), para ficar registrada tanto no
selo quanto no histórico de comentários.
