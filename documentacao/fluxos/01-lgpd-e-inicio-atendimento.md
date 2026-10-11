# 01 - LGPD e início do atendimento

Índice: [fluxos](README.md) | API: [totem-api-interna](../apis/totem-api-interna.md) | [regras de negócio](../regras-negocio.md) | [banco de dados](../banco-dados.md)

## Objetivo

Impedir que um atendimento seja iniciado sem ciência do Aviso de Privacidade e registrar o aceite vinculado ao atendimento criado. `[CÓDIGO]`

## Atores

Motorista; quiosque (front `app.js`); backend PHP (`LgpdController`, `LgpdRn`, `AtendimentoController`).

## Pré-condições

- Página do totem aberta com código válido e totem ativo (404 uniforme para inválido, inexistente ou inativo; rate limit só de falhas por IP). `[CÓDIGO]` (`public/totem/index.php`)
- Tela `lgpd` é o estado inicial; checkbox desmarcado e botão "Iniciar" desabilitado. `[CÓDIGO]`

## Etapas do motorista

1. Marca "Li e estou ciente do Aviso de Privacidade" (pode abrir "Ver termo completo", texto embutido na página).
2. Toca "Iniciar": o front chama `lgpd.php?acao=aceitar` e vai à tela `home`.
3. Em `home`, escolhe Expedição ou Recebimento e digita a placa; o front chama `atendimento.php?acao=iniciar` enviando o `token_aceite`. `[CÓDIGO]`

## Processamento interno

- `aceitar`: `LgpdRn::emitir` gera token de 32 bytes aleatórios (64 hex), grava **só o SHA-256** em `tb_lgpd_aceite` com `versao_termo`, `hash_termo`, `expira_em` (+10 min) e status `PENDENTE_USO`; devolve `token_aceite`, `expira_em` e o termo. `[CÓDIGO]`
- `iniciar`: valida `tipo` (expedicao ou recebimento), `placa` não vazia e formato do token (64 hex); em **uma transação** consome o aceite (`UPDATE ... SET status=USADO WHERE token_hash AND id_totem AND status=PENDENTE_USO AND expira_em>NOW() AND versao_termo AND hash_termo`, decisão por `rowCount`) e cria o atendimento com `id_aceite_lgpd`. `[CÓDIGO]`
- O aceite é de uso único, preso ao totem e à versão/hash exatos do termo vigente (versão `2026-09-25-v2`). `[CÓDIGO]`
- O atendimento nasce `em_andamento`, etapa `placa`, placa em maiúsculas; `pasta_documentos` = `AAAA-MM-DD/PLACA_HHMMSS` (fuso America/Sao_Paulo). `[CÓDIGO]`
- Ao iniciar, processamentos de documento pendentes de **outros** atendimentos `em_andamento` do mesmo totem são marcados `INDETERMINADO` (reconciliação de abandono). `[CÓDIGO]`
- A placa não tem validação de formato no backend (só não vazia e `strtoupper`) nem no front. `[CÓDIGO]`

## Endpoints envolvidos

`lgpd.php?acao=aceitar` e `atendimento.php?acao=iniciar` (detalhes em [totem-api-interna](../apis/totem-api-interna.md)). Autenticação: Bearer do totem.

## Mudanças de estado

| Antes | Depois |
|---|---|
| sem registro | `tb_lgpd_aceite` `PENDENTE_USO` |
| `PENDENTE_USO` | `USADO` (`usado_em`) no `iniciar` |
| sem registro | `tb_atendimento` `em_andamento`, etapa `placa` |

## Integrações

Nenhuma no aceite. Em Expedição, o `iniciar` consulta o banco externo de OCs depois do commit (fluxos [02](02-expedicao.md) e [06](06-consulta-e-baixa-ordem-coleta.md)).

## Conclusão

O atendimento criado segue para o fluxo de [Expedição](02-expedicao.md) ou [Recebimento](03-recebimento.md).

## Cancelamento

Na tela `lgpd` e `home` não há botão de cancelar nem aviso de inatividade. Depois do `iniciar`, valem as regras do fluxo [09](09-cancelamento-e-abandono.md). `[CÓDIGO]`

## Falhas e alternativas

- Token inválido, expirado ou já usado: 409 "Aceite de privacidade invalido ou expirado"; o front volta à tela `lgpd` para novo aceite. `[CÓDIGO]`
- Erro de rede em "Iniciar": o botão é reabilitado e aparece aviso. `[CÓDIGO]`
- Falha de banco no consumo: 500 genérico, log sem dado pessoal. `[CÓDIGO]`
- Em Expedição, o token e o atendimento já foram gravados antes da consulta de OCs; com 0 OCs (400) ou falha da consulta (502) sobra um atendimento `em_andamento` em `placa` (sai por cancelar ou abandono de 24 h). `[CÓDIGO]`
- `lgpd.php` não captura exceção de banco do `aceitar` (resposta ao cliente `[PENDENTE DE CONFIRMAÇÃO]`).

## Pendências conhecidas

- **Retenção** (decisão de produto em 2026-10-10: não será tratado): nenhuma rotina remove `tb_lgpd_aceite` (nem tokens expirados). O state registra "retenção acompanha o atendimento, sem prazo próprio nem rotina de exclusão". `[CÓDIGO]`
- Aprovação do DPO ao texto: só registrada no state, sem evidência no repositório. `[PENDENTE DE CONFIRMAÇÃO]`
- `[TESTE: teste_lgpd_aceite_backend_seguranca]` depende de `tests/manual/_fixtures_lgpd.php`, ausente no disco (ignorado pelo git); `[PENDENTE DE CONFIRMAÇÃO]` se roda hoje.
- Placa sem validação de formato.
