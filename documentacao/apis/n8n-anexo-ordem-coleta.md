# Anexo da ordem de coleta (n8n -> totem)

Endpoint que recebe o **PDF da ordem de coleta** enviado pelo n8n: `public/api/ordem-coleta-anexo.php`. Só Expedição. Controlador: `app/Controller/OrdemColetaAnexoController.php`; autenticação: `util/AuthServidor.php`; regras: `app/Rn/OrdemColetaArquivoRn.php`; banco: `app/Dao/OrdemColetaArquivoDao.php`. Fluxo funcional: [10 - Anexo da OC (n8n)](../fluxos/10-anexo-ordem-coleta-n8n.md). Convenções: [README.md](README.md). Legenda: **[C]** código, **[T: nome]** teste, **[A]** ambiente/terceiro, **[P]** pendente. O documento do fornecedor/projeto para o n8n é `docs/n8n-anexo-ordem-coleta.md` (INFORMADO PELO FORNECEDOR do lado n8n, escrito pelo próprio projeto).

## Resumo

| Item | Valor |
|---|---|
| URL | `POST https://<dominio-do-totem>/api/ordem-coleta-anexo.php` |
| Método | **somente POST** (única API do projeto que valida o método) |
| Autenticação | `Authorization: Bearer <chave>`; chave de `ORDEM_COLETA_ANEXO_API_KEY`. **Não** usa o token do totem. |
| Corpo | JSON, profundidade máxima 8 |
| Limite do arquivo | 5 MiB (5242880 bytes; `ORDEM_COLETA_ANEXO_MAX_BYTES` altera, aceita só inteiro positivo de até 10 dígitos) |
| Teto do corpo HTTP | 1,5 x o limite do arquivo (7864320 bytes com o padrão) |
| HTTPS | obrigatório (`ORDEM_COLETA_ANEXO_PERMITIR_HTTP=true` desliga; só para ambiente de teste) |

## Pipeline de verificação (ordem real)

Cabeçalhos de toda resposta: `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.

1. Método diferente de POST: **405** `METODO_NAO_PERMITIDO` + `Allow: POST` (**antes** da autenticação).
2. HTTPS: sem `HTTPS` ligado, `X-Forwarded-Proto: https` ou porta 443: **403** `HTTPS_OBRIGATORIO`.
3. Chave não configurada: **503** `INDISPONIVEL`.
4. Rate limit de falhas (abaixo): **429** `MUITAS_TENTATIVAS` + `Retry-After: 900`.
5. Chave: compara `sha256("Bearer " + chave)` com `sha256(<Authorization recebido>)` por `hash_equals`; falha = registra falha do IP e **401** `NAO_AUTORIZADO`.
6. `Content-Length` declarado acima do teto: **413** `ARQUIVO_MUITO_GRANDE`.
7. Corpo lido em blocos de 256 KiB, com teto: excedeu = 413. Corpo vazio = **400** `CORPO_INVALIDO` (ou 413 se havia `Content-Length` maior que zero); não-objeto/JSON inválido = 400 `CORPO_INVALIDO`.
8. Validação dos campos (`OrdemColetaArquivoRn::receber`).
9. Gravação com substituição atômica.

## Corpo

```json
{
  "cnpj_cliente": "11111111000191",
  "numero_ordem_coleta": "OC-0001",
  "arquivo_base64": "JVBERi0xLjQKJ..."
}
```

| Campo | Regra |
|---|---|
| `cnpj_cliente` | string de exatamente 14 dígitos |
| `numero_ordem_coleta` | string UTF-8 com `trim`; sem byte nulo e sem caracteres de controle/formato/separadores (`\p{Cc}`, `\p{Cf}`, `\p{Zl}`, `\p{Zp}`); até 50 caracteres Unicode e 200 bytes |
| `arquivo_base64` | base64 **estrito** (múltiplo de 4, alfabeto padrão, no máximo 2 `=`), **sem** prefixo `data:`; o conteúdo decodificado tem de começar com `%PDF-` |

O totem **não** verifica `%%EOF`, criptografia, JavaScript embutido nem número de páginas: é responsabilidade do n8n (INFORMADO no documento do n8n; o código não as checa). **[C]**

## Respostas

Sucesso:

```json
{ "sucesso": true, "dados": { "status": "criado", "tamanho_bytes": 123456, "sha256": "0123abcd...64hex" } }
```

- **201**: `status` = `criado` (primeira vez) ou `atualizado` (substituiu o anterior).
- **200**: `status` = `duplicado` (mesmo conteúdo, mesmo tamanho, mesmo sha256 e arquivo presente em disco; nada é regravado).

Erro (sempre com `codigo`):

```json
{ "sucesso": false, "erro": "Campo invalido ou ausente.", "codigo": "CAMPO_INVALIDO", "dados": { "campo": "cnpj_cliente" } }
```

| HTTP | `codigo` | Quando | Retentar? (segundo `docs/n8n-anexo-ordem-coleta.md`) |
|---|---|---|---|
| 400 | `CORPO_INVALIDO` | JSON inválido, escalar, vazio | não, corrigir |
| 400 | `CAMPO_INVALIDO` (`dados.campo`: `cnpj_cliente`, `numero_ordem_coleta` ou `arquivo_base64`) | campo ausente ou inválido, base64 inválido | não, corrigir |
| 401 | `NAO_AUTORIZADO` | chave ausente ou errada | alertar |
| 403 | `HTTPS_OBRIGATORIO` | chamada sem HTTPS | corrigir a URL |
| 405 | `METODO_NAO_PERMITIDO` | não é POST | corrigir |
| 413 | `ARQUIVO_MUITO_GRANDE` | acima do limite (corpo ou arquivo decodificado) | não |
| 422 | `FORMATO_NAO_SUPORTADO` | não começa com `%PDF-` | não |
| 429 | `MUITAS_TENTATIVAS` | 10 falhas de autenticação do mesmo IP em 15 min | aguardar |
| 500 | `ERRO_INTERNO` | falha interna/gravação em disco | sim, espera crescente |
| 503 | `INDISPONIVEL` | chave não configurada, banco externo fora ou nome de arquivo indisponível após 3 tentativas | sim, espera crescente |

Erros 4xx e 5xx são registrados no log central (`n8n_anexo_recusado` e `n8n_anexo_erro`, sem texto livre, sem IP, sem token). O contrato do n8n recomenda retentar 1, 5 e 15 min, no máximo 5 vezes, e timeout do node de no mínimo 60 s (lado n8n, **[A]**; o servidor não impõe isso).

## Rate limit de falhas de autenticação

- 10 falhas por IP em janela de 900 s -> 429 + `Retry-After: 900`. **[C]**
- O IP é `REMOTE_ADDR`. Atrás de proxy ele pode ser o do proxy: em produção **[P]** (observação P57). O `X-Forwarded-Proto` é aceito para decidir HTTPS.
- Contador em arquivo `STORAGE_PATH/ordens_coleta_ratelimit/<sha256(ip)[0:40]>.json` (permissão 0640, `flock`); limpeza aleatória de arquivos vencidos (1 a cada ~50 falhas, até 500 itens). Falha ao gravar o contador só gera `error_log` (**não** bloqueia a requisição). **Sem `STORAGE_PATH` não há rate limit.** **[C]**
- Só falhas contam; requisições autenticadas não consomem o limite.

## Idempotência, sobrescrita e concorrência

- Chave natural: `(cnpj_cliente, numero_ordem_coleta)` (UNIQUE `uk_oc_arquivo_cnpj_numero`). **Reenviar a mesma OC sobrescreve** o arquivo anterior (um arquivo por par). Conteúdo idêntico = `duplicado`.
- `OrdemColetaArquivoDao::substituir`: transação com `SELECT ... FOR UPDATE` por (cnpj, número); até 5 tentativas em erro 1062/1213. Colisão do UNIQUE de caminho = `CaminhoJaRegistradoException` e até 3 novos nomes; esgotado = 503 `INDISPONIVEL`.
- Arquivo gravado em `STORAGE_PATH/ordens_coleta/<cnpj>/<numero>_<AAAAMMDDHHMMSS>.pdf` com publicação exclusiva por `link()`; o arquivo anterior é removido **depois do commit**. `storage/` fica fora do webroot (nunca acessível por URL). **[C]**
- Escreve no banco **externo** (`tb_ordem_coleta_arquivos`, só caminho e sha256; ver [banco-gestao-coletas.md](banco-gestao-coletas.md)) e no disco.

## Retenção e uso do arquivo

- O cron `cron/limpar-anexos-ordem-coleta.php` apaga o PDF 15 dias depois de a OC ficar `INATIVA` (`inativada_em`) ou 15 dias após o recebimento se não houver OC correspondente; OC `ATIVA` nunca perde o arquivo (lote de 200). **[C]**
- No check-in, o PDF é anexado como `Ordem de Coleta` ([talent-checkin.md](talent-checkin.md)); a Gestão o entrega por POST autenticado ([gestao-rotas.md](gestao-rotas.md)).
- O vínculo é feito por (CNPJ, número) **exatamente** como estão em `tb_ordens_coleta.numero_ordem_coleta`; qualquer diferença (zero à esquerda, hífen, prefixo) quebra o vínculo e o check-in segue sem anexo.
- Decisão do usuário (2026-10-05): só PDF chega ao totem; Word ou imagem não são convertidos nem enviados. **[A]**

## Fail-closed e indisponibilidade

Chave ausente ou HTTP sem permissão = recusa; banco externo ou disco com falha = 503/500, sem gravar registro parcial (o arquivo novo é descartado quando o registro falha). O n8n é quem retenta; o totem não tem fila.

## Testes

[T: teste_oc_anexo_http (59), teste_oc_anexo_lacunas (59), teste_oc_anexo_logica (183), _caso_oc_anexo_receber], números históricos, não reexecutados aqui. **[A]**
