# 15 - Gestão: clientes e empresas

Índice: [fluxos](README.md) | Rotas: [gestao-rotas](../apis/gestao-rotas.md) | [regras de negócio](../regras-negocio.md) | [banco de dados](../banco-dados.md)

CRUD de clientes (`tb_cliente`) e empresas (`tb_empresa`) do banco do totem. Telas `clientes.php`, `cliente-form.php`, `cliente-acao.php`, `empresas.php`, `empresa-form.php`, `empresa-acao.php`. Legenda de confiança: a mesma de [02-expedição](02-expedicao.md).

## Objetivo

Manter o cadastro que o totem usa (a) para reconhecer o cliente pela nota fiscal no Recebimento (OCR e autocomplete) e (b) para identificar a empresa/armazém ao enviar o check-in ao Talent e para montar a URL dos totens. `[CÓDIGO]`

Os clientes desta tela são os de **`tb_cliente` do totem**; o banco externo de gestão de coletas **não** é escrito por estas telas (decisão do usuário). `[CÓDIGO: ClienteGestaoDao]`

## Perfis

Somente `admin`, em todas as seis páginas. As páginas `*-acao.php` aceitam apenas POST; `clientes.php` e `empresas.php`, apenas GET; os formulários, GET e POST. `[CÓDIGO: public/gestao/*.php]`

## Pré-condições

- Sessão de administrador ([fluxo 11](11-gestao-acesso-e-usuarios.md)); toda mutação reconfere, sob lock, que o ator ainda é admin ativo. `[CÓDIGO: CadastroGestaoBase::atorAdmin]`
- Para criar totem é preciso empresa ativa ([fluxo 12](12-gestao-totens.md)).

## Etapas na tela

### Clientes

1. **Lista (`clientes.php`)**: filtro de situação (todos/ativos/inativos) e busca por nome (mínimo 3 caracteres; menos que isso é ignorado com aviso), paginada. Ações por linha: Editar, Ativar/Inativar, Excluir. `[CÓDIGO: ClienteGestaoRn::filtrosValidos, GestaoClienteController::pagina]`
2. **Novo (`cliente-form.php`)**: nome (2 a 150 caracteres), CNPJ (com ou sem pontuação) e situação inicial (ativo por padrão). Erros por campo (HTTP 422).
3. **Editar**: só o **nome** é editável; o CNPJ aparece somente leitura. `[CÓDIGO: ClienteGestaoRn::editar recebe só o nome]`
4. **Ativar, Inativar, Excluir**: botões na lista; quando há aviso a confirmar, a lista reabre em `?confirmar=<acao>&id=` com o texto do aviso e o botão "Ativar mesmo assim", "Inativar mesmo assim" ou "Excluir definitivamente" (**confirmação em dois passos**).

### Empresas

1. **Lista (`empresas.php`)**: empresas com contagem de totens ativos e totais e as ações Editar, Ativar/Inativar, Excluir.
2. **Nova (`empresa-form.php`)**: nome (2 a 100) e CNPJ. **Editar**: só o nome; o CNPJ é somente leitura. `[CÓDIGO: EmpresaGestaoRn::editar]`
3. Inativar empresa com totens ativos e Excluir pedem confirmação em dois passos. `[CÓDIGO: GestaoEmpresaController::acao]`

## Processamento interno

### Estrutura comum (`CadastroGestaoBase`)

Toda mutação: valida a entrada, toma um `GET_LOCK` nomeado (10 s), abre transação, reconfere o ator admin (`FOR UPDATE` nos admins), trava a linha alvo, confere a regra, **abre a auditoria `PENDENTE` dentro da transação**, grava, faz COMMIT e fecha como `OK`. Recusas viram `SEM_EFEITO`; falha técnica vira log de texto fixo (só a classe da exceção), `LogSistema gestao_erro_interno` e uma linha de auditoria `ERRO`. O lock de clientes é o do `ClienteGestaoDao`; o de empresas é o mesmo lock de totens (`TotemGestaoDao`), para que a contagem de totens de uma empresa seja relida sob o mesmo lock que cria totens. `[CÓDIGO: CadastroGestaoBase, EmpresaGestaoRn::obterLock]`

### Clientes (`ClienteGestaoRn`)

- **Nome**: `NomeCadastro::normalizar` (UTF-8, 2 a 150 caracteres, sem caracteres de controle, invisíveis nem marcas de direção). Rejeita nome que, normalizado, fique vazio (por exemplo só símbolos ou só "LTDA/ME/SA") ou com mais de 150 caracteres. `[CÓDIGO]`
- **Normalização da razão social**: ao criar e ao mudar o nome, `razao_social_normalizada` é recalculada com `RazaoSocialMatcher::normalizar`: maiúsculas, remoção de acentos (determinística, sem depender de locale ou `iconv`), qualquer caractere fora de `A-Z0-9` e espaço vira espaço, remoção de sufixos societários, espaços colapsados. Exemplo: "Café Ação Ltda" e "CAFÉ AÇÃO" viram `CAFE ACAO`. Recusa nome cuja razão normalizada seja igual à de **outro** cliente (a comparação considera todos os clientes, ativos e inativos). `[CÓDIGO: RazaoSocialMatcher::normalizar, ClienteGestaoDao::existeRazaoNormalizada]`
- **CNPJ**: 14 dígitos com dígito verificador válido; único entre clientes; **imutável** depois de criado (a edição só recebe o nome). O CNPJ da **UDLOG** (as duas filiais constantes em `CnpjValidador`) é **bloqueado** como cliente: "Este CNPJ é da UDLOG e não pode ser cadastrado como cliente". `[CÓDIGO: CnpjValidador::ehUdlog]`
- **Ativar/inativar e excluir são ações separadas.** Excluir é **físico**. Inativar com atendimento `em_andamento` daquele CNPJ exige confirmação (`andamento_confirmado`); excluir **sempre** exige confirmação (com aviso extra se há atendimento em andamento). `[CÓDIGO: definirAtivoSobLock, excluirSobLock]`
- **Simulação de ambiguidade do OCR**: criar ativo, renomear cliente ativo (se a razão normalizada mudou) ou ativar cliente inativo roda `ambiguidadeOcr`. Ela reaplica `RazaoSocialMatcher::melhorCandidato` à lista de clientes ativos antes e depois da mudança e conta os clientes que **deixariam de ser identificados sem ambiguidade** (ou o próprio cliente que nasce ambíguo). Se a contagem for maior que zero, a operação exige confirmação em dois passos: para criar/editar o formulário reabre com o aviso (até 5 nomes afetados) e um botão de confirmar; para ativar a lista reabre em `?confirmar=ativar`. Confirmada, a auditoria leva `motivo_cad=confirmado_ambiguidade_ocr`. A simulação é **pulada** quando há mais de 500 clientes ativos (`SIMULACAO_MAX_CLIENTES`), e uma falha na simulação é ignorada (log fixo) e tratada como "sem ambiguidade". `[CÓDIGO: ClienteGestaoRn::ambiguidadeOcr]` `[TESTE: teste_gestao_cadastros]`
- **Efeito no totem**: `ClienteDao` (busca por termo, por CNPJ e fuzzy por razão normalizada) só enxerga clientes com `ativo = 1`; a mudança vale na hora. `[CÓDIGO: ClienteDao]`

### Empresas (`EmpresaGestaoRn`)

- **Nome**: 2 a 100 caracteres (`NomeCadastro`) e **precisa gerar um identificador (slug) de 1 a 16 caracteres `A-Z0-9`** (`TotemCodigo::empresa`: sem espaços, acentos ou símbolos), e esse slug precisa ser **único entre as empresas** (comparado contra o slug de todas as outras). `[CÓDIGO: EmpresaGestaoRn::validarNome, slugEmUsoPorOutra]`
- **CNPJ**: válido e único; imutável depois de criado; **não** passa pelo bloqueio da UDLOG (esse bloqueio é só de cliente). `[CÓDIGO]`
- **Renomear é permitido**; as URLs dos totens já criados **mantêm o nome antigo** até a URL ser regerada ([fluxo 12](12-gestao-totens.md)). `[CÓDIGO: mensagem empresa_editada]`
- **Inativar** com totens **ativos** pede confirmação (`totens_ativos_confirmado`). Efeito: o `finalizar` de um totem cuja empresa está inativa responde 500 "Totem sem empresa/armazem configurado para envio ao Talent" (o `EmpresaDao` só devolve empresa ativa). `[CÓDIGO: AtendimentoController::finalizar, EmpresaDao::buscarPorId]`
- **Excluir** só **sem nenhum totem vinculado** (ativo ou inativo): a contagem é relida sob o lock e uma violação de chave estrangeira também é tratada como `totens_vinculados`; depois exige confirmação. `[CÓDIGO: EmpresaGestaoRn::excluirSobLock]`

## Rotas

`GET /gestao/clientes.php`, `GET|POST /gestao/cliente-form.php`, `POST /gestao/cliente-acao.php`, `GET /gestao/empresas.php`, `GET|POST /gestao/empresa-form.php`, `POST /gestao/empresa-acao.php` (todas `admin`; POST com CSRF). Ver [gestao-rotas](../apis/gestao-rotas.md).

## Mudanças de estado

- `tb_cliente`: INSERT; `nome` e `razao_social_normalizada`; `ativo`; DELETE físico. (`tb_cliente` não tem coluna de data de atualização.) `[CÓDIGO: ClienteGestaoDao]`
- `tb_empresa`: INSERT; `nome`; `ativo`; DELETE.
- `tb_gestao_auditoria`: uma linha por operação. `[CÓDIGO]`

## Integrações

Nenhuma externa. O cadastro alimenta o OCR/autocomplete do Recebimento ([fluxo 03](03-recebimento.md)) e a identificação de empresa/armazém do envio ao Talent. O recálculo em lote da razão normalizada dos clientes antigos foi **descartado** pelo usuário. `[CÓDIGO]` `[AMBIENTE-TERCEIRO: decisão relatada pelo responsável]`

## Conclusão

Operação concluída com COMMIT e auditoria `OK`; a tela volta à lista com a mensagem de sucesso. As ações POST voltam à lista **sem** os filtros e a página anteriores. `[CÓDIGO: GestaoClienteController::voltar]`

## Falhas e alternativas

- Validação (nome, CNPJ, situação): formulário reaberto com erro por campo (422), sem gravar.
- Duplicidade (CNPJ ou nome equivalente, também por corrida na chave única): recusa com mensagem e auditoria `SEM_EFEITO` (`motivo_cad=duplicado`).
- Registro não encontrado (ex.: excluído em outra sessão): volta à lista com aviso e auditoria `SEM_EFEITO` (`nao_encontrado`).
- Sem mudança (já no estado ou nome igual): mensagem informativa, `SEM_EFEITO` (`ja_no_estado`).
- Lock indisponível em 10 s ou erro técnico: `erro_interno`, auditoria `ERRO`. `[CÓDIGO]`

## Auditoria

`CLIENTE_CRIAR`, `CLIENTE_EDITAR`, `CLIENTE_ATIVO`, `CLIENTE_EXCLUIR`, `EMPRESA_CRIAR`, `EMPRESA_EDITAR`, `EMPRESA_ATIVO`, `EMPRESA_EXCLUIR` (alvos `cliente` e `empresa`). `detalhe` só com `ativo_para` e `motivo_cad` (`andamento_confirmado`, `totens_ativos_confirmado`, `totens_vinculados`, `ja_no_estado`, `duplicado`, `nao_encontrado`, `confirmado_ambiguidade_ocr`); **nunca** nome, CNPJ ou razão social. Ver [fluxo 13](13-gestao-logs-auditoria.md). `[CÓDIGO: AuditoriaDao::DETALHE_CAMPOS]`

## Pendências conhecidas

Registradas sem correção:

- **P40**: os 8 clientes do seed com razão acentuada (mojibake de origem não explicada) não foram recalculados; o recálculo da razão normalizada em produção (ids 11, 33 e 35 divergentes no último dry-run) foi descartado pelo usuário. Clientes novos já gravam a razão com a normalização atual. `[AMBIENTE-TERCEIRO: relato do responsável]`
- Locale/`iconv` do PHP de produção: a normalização atual não depende deles; foram confirmados `mbstring`/`iconv`/`intl` e PHP 8.3 em produção. Determinismo em versões 8.1 a 8.3 não foi provado (só 8.0.30 no dev); letras fora do mapa fixo (ex.: vietnamita pré-composto) viram espaço. `[PENDENTE DE CONFIRMAÇÃO]`
- Ações POST voltam à lista sem filtros e página; "Voltar e editar o nome" abre o formulário em branco.
- `NomeCadastro` aceita alguns invisíveis raros (U+17B4/5, U+180B-D, U+FE00/E, U+E0100), inofensivos.
- Custo da simulação de ambiguidade: cerca de 0,46 s com 200 clientes ativos e 2,9 s com 500 (medido); acima de 500 a simulação é pulada.
- `ClienteDao::buscarPorTermo` usa `LIKE` sem escape de curingas (anterior à F6).
- Não há F5 (atendimentos do dia, travados, anexos órfãos), painel, status das integrações, saúde dos crons nem fotos de notas: descartados pelo usuário. A tela de clientes não toca o banco externo (`tb_clientes` do banco de coletas).
- Testes: `[TESTE: teste_gestao_cadastros]`, `[TESTE: teste_gestao_qa_cadastros]`; não reexecutados.
