# 12 - Gestão: totens

Índice: [fluxos](README.md) | Rotas: [gestao-rotas](../apis/gestao-rotas.md) | [regras de negócio](../regras-negocio.md) | [banco de dados](../banco-dados.md)

Cobre as telas de totens da Gestão (`totens.php`, `totem-form.php`, `totem-url.php`) e o endurecimento da página pública do quiosque (`public/totem/index.php`). Legenda de confiança: a mesma de [02-expedição](02-expedicao.md).

## Objetivo

Cadastrar totens, ativar/desativar e regerar a URL do quiosque sem mexer no banco à mão; e impedir que a URL do quiosque seja adivinhada ou testada por força bruta. `[CÓDIGO]`

## Perfis

Somente `admin` (as três páginas de totem exigem perfil `admin`; `totem-url.php` é só GET). `[CÓDIGO: public/gestao/totens.php, GestaoTotemController]`

## Pré-condições

- Sessão de administrador ([fluxo 11](11-gestao-acesso-e-usuarios.md)).
- `TOTEM_URL_BASE` definida e válida (`http(s)://host[/caminho]/` terminando em `/`, até 300 caracteres). Ausente ou inválida: o controller responde 503 "URL dos totens não configurada" e nada é alterado (inclusive ativar/desativar). A base nunca é derivada do `Host` da requisição. `[CÓDIGO: TotemUrlBase::doAmbiente, GestaoTotemController::__construct]`
- Para criar: ao menos uma empresa ATIVA cujo nome gere um identificador de 1 a 16 caracteres ([fluxo 15](15-gestao-clientes-empresas.md)). `[CÓDIGO]`

## Etapas na tela

1. **Lista (`totens.php`)**: mostra os totens com situação e a URL; ações Ativar/Desativar e Regerar URL. A lista nunca expõe o `codigo` bruto nem o `token_api`: o controller devolve só a `url` montada e a marca `legado` (totem cujo código não segue o padrão novo). `[CÓDIGO: TotemGestaoRn::enriquecer, TotemGestaoDao]`
2. **Criar (`totem-form.php`)**: escolhe a empresa ativa e digita o nome. Erros aparecem por campo (HTTP 422). Sucesso: vai a `totem-url.php?id=` com a mensagem "Totem criado e ativo" e a URL para copiar. `[CÓDIGO: GestaoTotemController::formulario]`
3. **Ver URL (`totem-url.php?id=`)**: mostra a URL do quiosque e o aviso permanente de URL regerada. Abrir a tela não é auditado. `[CÓDIGO]`
4. **Desativar**: se existe atendimento `em_andamento` com `atualizado_em` nos últimos 30 minutos naquele totem, a tela pede confirmação (`confirmar_atendimento=1`); sem a confirmação a ação é recusada com a mensagem "Há atendimento recente neste totem". `[CÓDIGO: TotemGestaoDao::JANELA_ATENDIMENTO_MIN, contarAtendimentosRecentes]`
5. **Regerar URL**: exige digitar o nome do totem (sem diferença de caixa) e envia a `versao_url` vista na lista. Sucesso: vai a `totem-url.php` com a URL nova. `[CÓDIGO]`

## Processamento interno

### Código do totem: `NOME-EMPRESA-HASH16`

- **NOME**: o texto digitado vira ASCII maiúsculo (`TextoEtiqueta::paraAscii`), qualquer sequência fora de A-Z/0-9 vira um traço, traços das pontas são removidos; precisa ter 2 a 24 caracteres. Exemplo da tela: "Guichê 04" vira `GUICHE-04`. `[CÓDIGO: TotemCodigo::nome]`
- **EMPRESA**: nome da empresa em ASCII maiúsculo só com A-Z/0-9 (sem espaço, hífen ou acento; "Mauá I" vira `MAUAI`); 1 a 16 caracteres, senão a criação é recusada. `[CÓDIGO: TotemCodigo::empresa]`
- **HASH16**: 16 caracteres do alfabeto `A-Z2-7` sorteados com `random_int` (CSPRNG), cerca de 80 bits. `[CÓDIGO: TotemCodigo::hash]`
- Padrão aceito (`REGEX_PADRAO`): `NOME-EMPRESA-HASH16`, no máximo 58 caracteres; `tb_totem.codigo` é `VARCHAR(64)`. Totens antigos fora do padrão (ex.: `RECEPCAO-01`) são tratados como `legado`. `[CÓDIGO: TotemCodigo, sql/schema.sql]`
- URL do quiosque = `TOTEM_URL_BASE` + `?totem=` + código (`rawurlencode`). `[CÓDIGO: TotemUrlBase::urlDoCodigo]`

### Criar (`TotemGestaoRn::criar`)

Sob `GET_LOCK` de totens (10 s) e transação: confere que o ator ainda é admin ativo; empresa ativa; slug de 1 a 16; nome único **por empresa** (`uk_totem_empresa_nome`); gera o código e o **token da API novo de 256 bits** (`bin2hex(random_bytes(32))`); até 5 tentativas em caso de colisão do código (chave duplicada), depois recusa com `totem_colisao`; o totem nasce **ativo**; abre a auditoria `TOTEM_CRIAR` (detalhe `empresa=<id>`) dentro da transação e a fecha como `OK` depois do COMMIT. `[CÓDIGO: TotemGestaoRn::criarSobLock]` `[TESTE: teste_gestao_totens]`

### Ativar e desativar

`definirAtivo` atualiza `ativo`; sem mudança responde "Nada foi alterado" sem auditar. Auditoria `TOTEM_ATIVO` (`ativo_para=0|1`). **Efeito imediato**: a página do quiosque filtra `ativo = 1` (404) e a API do totem filtra `token_api` com `ativo = 1` (401 "Totem nao autorizado"), portanto um totem desativado deixa de abrir a página e de usar a API na próxima requisição. `[CÓDIGO: TotemDao::buscarPorCodigo, Auth::validarTotem]`

### Regerar URL e `url_versao`

1. Confere ator admin, totem existente e **`url_versao` igual à versão vista** (controle otimista; senão `url_ja_regerada`).
2. Confere o nome digitado contra o nome do totem (sem caixa; senão `totem_nome_confirmacao`).
3. Troca **só o HASH**: o prefixo `NOME-EMPRESA` do código atual é mantido (mesmo que a empresa tenha sido renomeada depois). Para totem legado fora do padrão, monta o prefixo a partir de nome + empresa; se não for possível, recusa com `totem_legado_invalido`.
4. Grava o código novo com `url_regerada_em = NOW()` e `url_versao = url_versao + 1`; até 5 tentativas em colisão. Auditoria `TOTEM_URL_REGERAR` (sem detalhe). `[CÓDIGO: TotemGestaoRn::regerarUrlSobLock, TotemGestaoDao::atualizarCodigo]`

**O `token_api` não muda** ao regerar a URL (decisão do usuário, risco aceito). A URL antiga deixa de abrir a página (404), mas o token que a página antiga já entregou continua válido na API enquanto o totem estiver ativo; para bloquear de fato um totem é preciso desativá-lo. A própria mensagem da tela informa isso. `[CÓDIGO: GestaoContexto::MENSAGENS['url_regerada']]`

### Hardening da página do quiosque (`public/totem/index.php`)

- Cabeçalhos: `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff`. `[CÓDIGO]`
- **404 uniforme**: código fora do formato `[A-Za-z0-9-]{1,64}`, código inexistente e totem inativo respondem a mesma página "Pagina nao encontrada." com HTTP 404, sem distinguir os casos. `[CÓDIGO]`
- **Rate limit só de falhas, por IP**: cada 404 desses registra uma falha em arquivo (`STORAGE_PATH/totem_pagina_ratelimit`, ou o diretório temporário do sistema se `STORAGE_PATH` for vazio); a partir de **20 falhas em 600 s** o IP recebe HTTP 429 com `Retry-After`, sem consultar o banco. Acessos com código válido não contam. O arquivo é nomeado por HMAC do "balde" do IP com o sal (`GESTAO_HASH_SALT`, ou um sal padrão se ausente/curto). `[CÓDIGO: util/LimiteFalhasIp.php]` `[TESTE: teste_gestao_totem_f0]`
- IP do cliente: `REMOTE_ADDR`; `CF-Connecting-IP` só é aceito se `REMOTE_ADDR` estiver em faixa Cloudflare oficial. `[CÓDIGO: IpCliente::obter]`
- Falha do banco: 503 genérico (não conta como falha). Com totem válido, a página entrega o HTML do quiosque com o `token_api` em `data-totem-token`; HTML, assets e token do quiosque não mudaram. `[CÓDIGO]`

## Rotas

`/gestao/totens.php`, `/gestao/totem-form.php`, `/gestao/totem-url.php` e a página pública `/totem/index.php?totem=`. Ver [gestao-rotas](../apis/gestao-rotas.md) e [totem-api-interna](../apis/totem-api-interna.md).

## Mudanças de estado

`tb_totem`: inserção (`codigo`, `nome`, `id_empresa`, `token_api`, `ativo=1`, `criado_por`); `ativo`; `codigo`, `url_regerada_em`, `url_versao`; `atualizado_em`. O token só é escrito na criação. `[CÓDIGO: TotemGestaoDao]`

## Integrações

Nenhuma externa. O quiosque no mini PC precisa ter a URL nova no `iniciar-totem.bat` e ser reiniciado após regerar; foi feito assim para o RECEPCAO-01 em 2026-10-08. `[AMBIENTE-TERCEIRO: relato do state]`

## Conclusão

Criação: totem ativo e URL exibida para copiar. Regerar: URL nova exibida e a antiga passa a responder 404. Desativar: página e API do totem param de responder. `[CÓDIGO]`

## Falhas e alternativas

- `TOTEM_URL_BASE` ausente/inválida: 503 e nada alterado. `[CÓDIGO]`
- Nome repetido na empresa, empresa inativa, empresa com nome que não gera slug de 1 a 16: erro de validação na tela, auditoria `SEM_EFEITO`.
- Atendimento recente ao desativar: exige confirmação. Versão de URL antiga ou nome digitado errado ao regerar: recusa sem alterar.
- Lock indisponível em 10 s ou erro técnico: `erro_interno`, auditoria `ERRO` com log de texto fixo.
- Recusas viram auditoria `SEM_EFEITO`; falhas técnicas, `ERRO`. `[CÓDIGO]`

## Auditoria

`TOTEM_CRIAR`, `TOTEM_ATIVO`, `TOTEM_URL_REGERAR` (alvo `totem`; `detalhe` só `empresa` ou `ativo_para`, sem nome, código, URL ou token). Ver [fluxo 13](13-gestao-logs-auditoria.md). Ver a lista e a URL não é auditado. `[CÓDIGO: AuditoriaDao::DETALHE_CAMPOS]`

## Pendências conhecidas

Registradas sem correção:

- O token do totem não é rotacionado em nenhuma operação do sistema; quem já abriu a URL antiga mantém acesso à API até a desativação (risco aceito). `[CÓDIGO]`
- O rate limit de falhas é por IP: quiosques atrás do mesmo IP público podem receber 429 se um deles ficar recarregando uma URL antiga (risco registrado no state). `[PENDENTE DE CONFIRMAÇÃO]` (comportamento em produção atrás do Cloudflare)
- O state de 2026-10-06 diz que a URL do RECEPCAO-01 ficaria fixa; a entrada de 2026-10-08 registra que foi regerada e o `.bat` atualizado. Vale a mais recente.
- `no-store` na página do totem: a confirmar no quiosque (state). `[PENDENTE DE CONFIRMAÇÃO]`
- Texto da linha de ajuda da URL (`#totem-url-ajuda`) escrito pelo frontend sem confirmação do usuário (state). `maxlength` 24 pode cortar entrada longa em silêncio (state).
- Regerar mantém o prefixo `NOME-EMPRESA` mesmo se a empresa for renomeada. `[CÓDIGO]`
- Testes: `[TESTE: teste_gestao_totens]`, `[TESTE: teste_gestao_qa_totens]`, `[TESTE: teste_gestao_totem_f0]` (este com falhas conhecidas por dívida de teste: guarda do prepend e fim de linha CRLF; não reexecutado).
