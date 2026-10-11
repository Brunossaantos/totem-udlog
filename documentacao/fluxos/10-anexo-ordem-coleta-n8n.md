# 10 - Anexo da ordem de coleta (n8n)

Índice: [fluxos](README.md) | API: [n8n-anexo-ordem-coleta](../apis/n8n-anexo-ordem-coleta.md), [talent-checkin](../apis/talent-checkin.md), [banco-gestao-coletas](../apis/banco-gestao-coletas.md) | [regras de negócio](../regras-negocio.md) | [banco de dados](../banco-dados.md)

Legenda de confiança: ver [README](README.md#legenda-de-confiança).

## Objetivo

Receber do n8n o PDF da ordem de coleta (OC), guardá-lo e anexá-lo ao check-in da Expedição no Talent. Este fluxo não tem etapas do motorista. `[CÓDIGO]`

## Atores

n8n `[AMBIENTE-TERCEIRO]`; `public/api/ordem-coleta-anexo.php` (`OrdemColetaAnexoController`, `OrdemColetaArquivoRn`, `AuthServidor`); tabela `tb_ordem_coleta_arquivos` no banco externo; `STORAGE_PATH/ordens_coleta/`; `TalentRn`; cron de limpeza.

## Pré-condições

`ORDEM_COLETA_ANEXO_API_KEY` configurada (sem chave => 503); HTTPS; workflow n8n ativo com URL e credencial configuradas (hoje inativo, P63 `[AMBIENTE-TERCEIRO]`). `[CÓDIGO]`

## Etapas do motorista

Nenhuma. O motorista só se beneficia: o PDF segue no check-in sem ação dele.

## Processamento interno

### Recebimento do PDF (`ordem-coleta-anexo.php`)

- Só POST. HTTPS obrigatório (`requisicaoHttps` aceita `X-Forwarded-Proto`, P57). `Authorization: Bearer <chave>` comparado por `hash_equals` de SHA-256. `[CÓDIGO]`
- Rate limit só de **falhas**: 10 por IP em 900 s, em arquivo (`STORAGE_PATH/ordens_coleta_ratelimit`) => 429. Teto de corpo = limite x 3/2 => 413 (limite de 5 MiB). JSON com profundidade 8. `[CÓDIGO]`
- Regras (`OrdemColetaArquivoRn::receber`): `cnpj_cliente` com 14 dígitos; `numero_ordem_coleta` de 1 a 50 caracteres UTF-8 sem controles; `arquivo_base64` base64 estrito, até 5 MiB, começando com `%PDF-` (422 senão). `[CÓDIGO]`
- Duplicado (mesmo sha256 e tamanho com o arquivo presente) => 200 `duplicado`. Senão grava `STORAGE_PATH/ordens_coleta/<cnpj>/<numero>_<AAAAMMDDHHMMSS>.pdf` com publicação exclusiva (até 3 tentativas de nome) e substitui o registro (UNIQUE cnpj+número: um arquivo por OC; sobrescreve e remove o anterior) => 201 `criado`/`atualizado`. Falha de banco/nome => 503; storage => 500. `[CÓDIGO]`
- Toda recusa ou erro gera `LogSistema n8n_anexo_recusado`/`n8n_anexo_erro`. `[CÓDIGO]`
- Só PDF chega (decisão do usuário). `[CÓDIGO]`

### Uso no check-in

`TalentRn::montarAnexoOrdemColeta` busca por (CNPJ, número) e anexa "Ordem de Coleta" em base64 puro ao payload do Talent ([07](07-checkin-talent.md)). **Qualquer falha segue sem o anexo** (só `error_log`); o check-in não é bloqueado. `[CÓDIGO]`

### Retenção

`cron/limpar-anexos-ordem-coleta.php` (lote 200, 15 dias; agenda 03:55): apaga arquivo e linha quando a OC correspondente está INATIVA com `inativada_em` de 15 dias ou mais, ou, sem OC correspondente, 15 dias após `criado_em` do anexo (órfão). OC ATIVA nunca perde o arquivo. `[CÓDIGO]` `[TESTE: teste_oc_anexo_logica, teste_oc_anexo_http, teste_oc_anexo_lacunas (sai com rc 0 mesmo com falhas, P58), teste_prepend_qa_guarda_externo]`

## Endpoints envolvidos

`POST /api/ordem-coleta-anexo.php` (contrato em [n8n-anexo-ordem-coleta](../apis/n8n-anexo-ordem-coleta.md)). Consumo indireto no `finalizar` ([07](07-checkin-talent.md)); a baixa da OC está em [06](06-consulta-e-baixa-ordem-coleta.md).

## Mudanças de estado

Cria/atualiza linha em `tb_ordem_coleta_arquivos` e o arquivo em `STORAGE_PATH/ordens_coleta/`; a limpeza remove ambos. Não altera o atendimento. `[CÓDIGO]`

## Integrações

n8n (origem) e Talent (destino do anexo) `[AMBIENTE-TERCEIRO]`. A tabela `tb_ordem_coleta_arquivos` consta do dump local `docs/<CONTA_CPANEL>_db_gestao_coletas.sql` (fora do Git; produção, somente estrutura, 2026-10-10). `[PENDENTE DE CONFIRMAÇÃO]`

## Conclusão

PDF armazenado e disponível para o próximo check-in dessa OC.

## Cancelamento

Não aplicável a este endpoint. O cancelamento do atendimento ([09](09-cancelamento-e-abandono.md)) não remove o anexo; só o cron de retenção o faz.

## Falhas e alternativas

- Chave ausente/inválida: 503/401 e rate limit de falhas (429).
- PDF inválido: 422; corpo grande: 413.
- Anexo ausente no check-in: o Talent recebe o payload sem o PDF.

## Pendências

- P56 a P63; P57 (confiança em `X-Forwarded-Proto`); P58 (teste com rc 0 apesar de falhas); P63 (workflow n8n inativo, URL e credencial a configurar).
- O anexo só chega se o n8n enviar antes do `finalizar`; não há reenvio do anexo para um check-in já feito. `[CÓDIGO]`
