# Contrato de comunicação — orquestrador ↔ sub-agentes

Todo despacho do orquestrador para um sub-agente, e toda resposta de um
sub-agente de volta, seguem os formatos fixos abaixo. Isso existe pra que
o orquestrador nunca precise adivinhar o que voltou, e pra que nenhum
sub-agente invente contexto que não recebeu.

---

## 1. Contrato de requisição (orquestrador → sub-agente)

Todo despacho via Agent tool usa este template no corpo do prompt:

```
## Contexto
<resumo objetivo do trecho relevante de ia_development_state.md — só o
que esse sub-agente precisa pra essa tarefa específica, não o arquivo
inteiro>

## Tarefa
<o que deve ser feito, especificamente e sem ambiguidade>

## Restrições
<o que NÃO fazer — ex: "não decidir o contrato da API do Talent, ainda
está pendente"; "não alterar telas fora do escopo desta tarefa">

## Arquivos relevantes
<caminhos específicos — o sub-agente não sai explorando o projeto todo
sem necessidade>

## Formato de resposta esperado
Seguir o contrato de resposta em docs/contratos-comunicacao.md.
```

## 2. Contrato de resposta (sub-agente → orquestrador)

Todo sub-agente devolve o resultado final nesta estrutura:

```
## Resumo
<1-2 frases — o que foi entregue>

## O que foi feito
<lista objetiva de ações — sem floreio>

## Arquivos alterados/criados
<lista de caminhos>

## Pendências / bloqueios
<o que ainda falta, ou o que dependia de uma informação que não estava
disponível (ex: "TalentClient.php continua com URL placeholder — falta
docs/manual_talent.md preenchido"). Nunca inventa uma solução pra
contornar a pendência sem que isso tenha sido pedido.>

## Recomendações
<só preencher se o orquestrador pediu explicitamente uma recomendação.
Caso contrário, deixar "Nenhuma — fora do escopo pedido.">
```

## 3. Regras que valem para todo sub-agente

1. Ler `ia_development_state.md` antes de qualquer ação (o trecho
   relevante já deve vir resumido no "Contexto" da requisição, mas o
   arquivo completo está sempre disponível pra consulta).
2. Nunca inventar contrato de API, campo de banco, ou decisão de produto
   que não esteja em `ia_development_state.md` ou nos `docs/` — se faltar
   informação, reportar como pendência, não supor.
3. Nunca sugerir trabalho fora do que foi pedido na "Tarefa". Se notar algo
   relevante fora do escopo, registrar em "Pendências / bloqueios", não
   implementar por conta própria.
4. Sempre responder no formato do contrato de resposta acima.
