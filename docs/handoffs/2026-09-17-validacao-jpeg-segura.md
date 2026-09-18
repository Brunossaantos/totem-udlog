# Handoff - validacao-jpeg-segura

Data: 2026-09-17
Etapa: 00-planejamento

## O que foi pedido

Investigar e planejar (sem implementar nada) a correcao da validacao de
imagens JPEG recebidas pelo totem. A validacao atual (unica funcao em
todo o backend: `util/UploadHelper.php::salvarImagemBase64()`) usa
magic bytes + `getimagesizefromstring()`, mas essa combinacao pode
aceitar um JPEG truncado (sem o marcador de fim FFD9), comprometendo a
integridade da imagem como evidencia (nota fiscal, CNH, CRLV).

Escopo pedido: mapear todas as superficies afetadas (CNH, CRLV, nota
fiscal, scanner Netum SD-2000, OCR/integracoes posteriores), comparar
estrategias de validacao sem implementar, definir matriz de testes
sinteticos, e registrar decisoes/riscos. Nenhum codigo alterado, nenhum
dado real usado, nenhuma chamada externa, nenhum commit/push.

## Investigacao - mapeamento confirmado por leitura direta de codigo (explorer)

### Ponto unico de validacao/gravacao de imagem

Todo o backend tem UM SO ponto de validacao de imagem:
`util/UploadHelper.php::salvarImagemBase64()` (linhas 26-77). Fluxo
atual:
1. Exige prefixo `data:image/jpeg;base64,` (so delimitador de string,
   nunca usado como fonte de verdade de tipo).
2. `base64_decode()` em modo estrito.
3. Tamanho maximo `NOTA_IMAGEM_MAX_BYTES` (.env, padrao 5MB) -- UNICO
   limite, usado sem distincao para nota/CNH/CRLV.
4. Magic bytes reais `\xFF\xD8\xFF` (linha 48).
5. `getimagesizefromstring()` (linha 55) -- confirma `IMAGETYPE_JPEG` e
   dimensoes > 0. **Esta funcao so le o cabecalho/segmento SOF do
   JPEG para extrair dimensoes -- nao varre o arquivo ate o marcador
   de fim EOI (FFD9), entao nao detecta truncamento no meio dos dados
   de scan.**
6. Grava em disco com nome definido pelo servidor (nunca vem do
   cliente).

### Chamadores (todos os 4 fluxos pedidos passam por aqui)

- **Nota fiscal**: `app/Controller/NotaController.php:113` (dentro de
  `processar()`).
- **CNH**: `app/Controller/DocumentoController.php:126-127` (frente+
  verso numa chamada, fluxo Expedicao) e `:135` (frente OU verso
  separados, fluxo Recebimento).
- **CRLV**: `app/Controller/DocumentoController.php:143`.
- **Scanner Netum SD-2000**: usa captura PROPRIA no front-end
  (`capturarFotoScannerNota()`, `public/totem/assets/app.js:1756-1789`
  -- resolucao real do video, qualidade JPEG adaptativa 0.92 a 0.4,
  downscale progressivo se exceder `SCANNER_NOTA_LIMITE_BYTES` =
  4.5MB client-side), mas o BACKEND que recebe essa imagem e o MESMO
  `UploadHelper::salvarImagemBase64()` usado por CNH/CRLV/nota -- sem
  validacao backend separada. CNH/CRLV usam captura diferente
  (`capturarFotoBase64()`, `app.js:465-475` -- downscale fixo 900px,
  qualidade fixa 0.7).

Nao existe nenhuma outra validacao de imagem em `app/Rn/DocumentoRn.php`
nem `app/Rn/NotaFiscalRn.php` (confirmado por grep, zero match de
`getimagesizefromstring|magic|imagem|jpeg` nesses 2 arquivos).

### OCR/integracoes posteriores

OCR (`ocr-worker.js`) roda CLIENT-SIDE sobre a imagem em memoria no
navegador, antes do upload -- nao e afetado pela validacao do backend.
Nenhum campo/endpoint do Talent depende do conteudo binario da imagem
-- sem impacto direto la (contrato do Talent para tratamento de imagem
malformada no payload continua nao confirmado, `docs/manual_talent.md`
ainda placeholder nesse aspecto).

### Limites ja existentes

| Checagem | Estado hoje |
|---|---|
| Tamanho maximo | Unico limite `NOTA_IMAGEM_MAX_BYTES` (5MB), sem distincao por tipo de documento |
| MIME declarado | Exigido no prefixo do data URL, mas nunca usado como fonte de verdade (magic bytes reais checados depois) |
| Marcador de fim (EOI) | NAO verificado -- causa raiz confirmada do bug |
| Dimensao maxima (pixels) | NAO existe nenhum limite |
| Dimensao minima | NAO existe (fora do escopo pedido) |
| Resolucao | NAO existe checagem dedicada |

### Ambiente

- Extensao GD confirmada disponivel LOCALMENTE (`php -m` lista `gd`).
  PHP local: 8.0.30. Disponibilidade de GD em producao (Hostgator)
  **NAO CONFIRMADA** -- pendencia ja registrada em
  `docs/deploy-checklist.md` (memory_limit/upload_max_filesize/
  post_max_size tambem nao confirmados em producao).
- Nenhuma lib de imagem alem de GD nativo (`composer.json` so tem
  `vlucas/phpdotenv` e `setasign/fpdf`).
- Front-end usa `canvas.toDataURL('image/jpeg', qualidade)` para gerar
  as imagens (nao sao arquivos de camera originais) -- por padrao isso
  nao deveria gerar JPEG progressivo nem metadados EXIF, mas isso e
  PREMISSA A CONFIRMAR (comportamento de canvas do Chromium kiosk real
  no mini PC, nao documentado oficialmente no projeto), nao certeza.
- Scanner Netum SD-2000: formato real dos bytes produzidos pelo driver
  (JFIF puro vs. container Exif com thumbnail embutido) tambem NAO
  CONFIRMADO -- relevante para calibrar qualquer checagem estrutural
  de marcador.
- Nao existe teste automatizado cobrindo o cenario exato do bug (JPEG
  truncado aceito) -- pendencia de cobertura, sera fechada no plano de
  testes desta demanda.
- Nao ha evidencia arquivada de JPEG real capturado fisicamente do
  Netum/camera do totem disponivel para reteste contra a validacao
  nova -- recomendado nova captura fisica em `/02-testes`, se possivel.

## Causa confirmada do bug

Confirmada tecnicamente por `backend-especialista`: um arquivo JPEG e
uma sequencia de segmentos marcados por `FF xx`. O segmento SOF0
(baseline)/SOF2 (progressivo) contem largura/altura e aparece ANTES do
segmento SOS (Start of Scan) + dados de pixel comprimidos + marcador
final EOI (FFD9). `getimagesizefromstring()` para de ler assim que
encontra o SOF -- nunca avanca ate o EOI nem valida integridade dos
dados de scan. Logo: um arquivo com SOI + segmentos de metadado
validos + inicio de SOS, truncado em QUALQUER ponto dentro dos dados
de scan (antes do EOI), passa pela validacao atual sem erro.

Outros truncamentos que tambem preservam dimensoes validas e passam
despercebidos:
- Truncamento logo apos o primeiro MCU do scan (arquivo "quase vazio"
  de dados reais, mas SOF ja tinha as dimensoes).
- Truncamento no meio de um scan progressivo (SOF2) entre passadas --
  perder scans posteriores ainda preserva as dimensoes do SOF2 inicial.
- Corrupcao de bytes no meio do stream sem alterar o comprimento do
  arquivo (bit-flip) -- nenhuma checagem de marcador detecta isso
  (limitacao inerente a qualquer estrategia que nao decodifique
  pixels, registrada como fora do alcance completo do problema).

Confirmado tambem: a mera presenca de EOI no fim do arquivo NAO e
100% garantia de integridade total dos dados de scan (pode haver
corrupcao de bit no meio e ainda terminar em FFD9 corretamente), mas
a AUSENCIA de EOI e um sinal forte e barato de truncamento, e cobre o
caso relatado nos testes reais que originou esta demanda.

## Comparacao de estrategias (backend-especialista + security-especialista, avaliacao independente)

### (a) Checagem estrutural COMPLETA de segmentos (parser JPEG proprio em PHP)

Percorre todos os marcadores do SOI ao EOI, validando tamanhos
declarados de cada segmento. Eficacia alta contra truncamento, baixo
falso positivo se bem implementada. 100% nativo, custo CPU/memoria
baixo (varredura linear de bytes, nao decodificacao de pixel).
**Rejeitada por ambos os revisores como opcao principal**: reimplementar
um parser JPEG completo em PHP tem superficie de bug desproporcional
ao ganho (o bug relatado e especificamente "arquivo cortado no meio,
sem chegar ao fim", nao corrupcao de segmento de metadado).

### (b) Checagem simples de SOI+EOI (so primeiros/ultimos bytes)

Eficacia media -- resolve o cenario relatado (truncamento acidental,
sem EOI no fim). **Achado do security-especialista**: um EOI simples
procurado "em qualquer lugar do arquivo" e bypassavel -- um atacante
(cenario de dispositivo comprometido com token valido) poderia truncar
o arquivo e ainda assim anexar FFD9 manualmente ao final, ou a
sequencia FFD9 pode ocorrer por acaso dentro do stream de dados de
scan comprimido. Mitigacao proposta pelo backend-especialista: exigir
que o EOI esteja pertO do fim real do arquivo (varrer os ultimos N
bytes, ex. 4-16, nao o arquivo inteiro) -- reduz mas nao elimina
100% o vetor de forjamento deliberado (um EOI literal anexado ao final
de um arquivo truncado ainda passaria). Para o cenario de origem do
bug (truncamento ACIDENTAL de hardware/software/rede, nao um atacante
adversarial ativo neste ponto especifico do pipeline), essa checagem
e considerada suficiente pelo backend-especialista; o
security-especialista pondera que o vetor de forjamento deliberado
so e relevante no cenario ja registrado de "dispositivo comprometido
com token valido do totem" (pendencia de produto mais ampla, nao
exclusiva desta demanda).

### (c) Decodificacao COMPLETA via GD (`imagecreatefromstring()`)

Eficacia mais alta possivel -- forca decodificacao real de todos os
MCUs/blocos DCT; qualquer truncamento real gera falha. Nao e
bypassavel por EOI forjado (o decoder real precisa dos dados
completos). **Risco real confirmado por ambos os revisores**: um JPEG
com dimensoes declaradas artificialmente enormes (ex. 50000x50000)
pode ter arquivo fisico pequeno (poucos MB, dentro do limite de 5MB)
mas, ao decodificar, aloca buffer de pixels proporcional a
largura x altura x canais (~7.5GB de RAM so para o buffer bruto no
exemplo de 50000x50000x3) -- bomba de descompressao classica, vetor
real de DoS em hospedagem compartilhada (Hostgator) com
`memory_limit`/CPU nao totalmente conhecidos e sem isolamento de
processo por container. Mitigacao OBRIGATORIA se esta rota for
escolhida: `getimagesizefromstring()` (leitura leve, ja em uso) SEMPRE
ANTES de `imagecreatefromstring()`, rejeitando por limite de dimensao
antes de decodificar -- nunca decodificar sem checar dimensao primeiro.
CVEs de parsing em libgd: risco pre-existente e aceito implicitamente
assim que qualquer decodificacao de imagem for feita (GD delega ao
libjpeg do sistema, nao implementa parser JPEG proprio) -- nao e
argumento forte contra esta opcao especificamente, e um risco geral de
processar imagem de fonte nao totalmente confiavel, presente
independente da escolha.

### (d) Combinacao: estrutural leve (SOI + EOI proximo do fim) + `getimagesizefromstring()` (ja existe) + limite de dimensao, SEM decodificacao completa

Cobre o bug relatado com custo minimo, sem risco de bomba de
descompressao de decodificacao completa. Nao garante 100% de
integridade de pixel entre os marcadores (mesma limitacao de (a) e
(b)) nem elimina o vetor teorico de EOI forjado deliberadamente.

## DECISAO BLOQUEANTE -- divergencia entre revisores, requer confirmacao do usuario antes de /01-implementacao

Os dois revisores DIVERGEM na recomendacao final entre (c) e (d):

- **backend-especialista recomenda (d)** (estrutural leve, sem GD):
  prioriza seguranca OPERACIONAL em hospedagem compartilhada com
  limites de memoria/CPU nao totalmente conhecidos -- evitar decodificacao
  completa como padrao e a decisao mais segura para nao arriscar
  estourar `memory_limit`/matar processo no Hostgator.
- **security-especialista recomenda (c)** (decodificacao completa via
  GD, com a mitigacao obrigatoria de checar dimensao ANTES de
  decodificar): prioriza a GARANTIA REAL contra truncamento --
  considera que uma checagem estrutural leve tem vetor de bypass
  conhecido (EOI forjado) que a decodificacao completa elimina, e que
  o risco de bomba de descompressao fica neutralizado pela mitigacao
  obrigatoria (checar dimensao antes de decodificar), que AMBOS os
  revisores concordam ser necessaria de qualquer forma (ver secao de
  limite de dimensao abaixo).

**Convergencia real entre os dois**: AMBOS concordam que um limite de
dimensao maxima (independente do limite de bytes do arquivo) e
necessario e deve ser verificado ANTES de qualquer decodificacao, seja
qual for a estrategia escolhida -- essa parte NAO e a decisao
bloqueante, e consenso.

**Recomendacao consolidada do orquestrador para submissao ao
usuario**: comecar pela opcao (d) (estrutural leve + limite de
dimensao), por ser suficiente para o bug efetivamente relatado
(truncamento acidental, nao ataque adversarial neste ponto do
pipeline) e operacionalmente mais segura em hospedagem compartilhada
sem visibilidade total dos limites de producao -- com a opcao (c)
registrada como caminho de reforco futuro caso o usuario prefira a
garantia mais forte e aceite o custo de confirmar
antecipadamente os limites de `memory_limit`/GD em producao antes de
habilitar decodificacao completa. Esta e uma recomendacao, nao uma
decisao arbitraria -- **o usuario precisa confirmar qual das duas
rotas seguir antes de `/01-implementacao`**.

## Tratamento dos requisitos do usuario (validos para (c) ou (d))

- **Rejeita truncamento e conteudo malformado**: sim, objetivo central
  de ambas as opcoes (c)/(d).
- **Nao confia so em MIME/extensao do cliente**: ja e o padrao hoje
  (magic bytes + `getimagesizefromstring`, nunca `Content-Type` do
  request) -- confirmado pelo security-especialista, mantido em
  qualquer opcao.
- **Aceita JPEG do Netum e das cameras do totem**: premissa a
  confirmar (nao certeza) -- `canvas.toDataURL` tipicamente gera
  baseline (SOF0), nao progressivo, mas isso nao esta formalmente
  documentado para o Chromium kiosk real do mini PC. Risco de falso
  positivo considerado BAIXO pelo backend-especialista (o truncamento
  provavelmente ocorre em outro ponto do pipeline -- rede, timeout de
  fetch -- nao na geracao do data URL em si), mas nao certificado sem
  teste fisico.
- **Preserva baseline E progressivo quando validos**: nenhuma das
  opcoes introduz filtro que rejeite progressivo -- `getimagesizefromstring`
  ja aceita SOF0/SOF2 hoje sem distincao, mantido.
- **Evita consumo excessivo de memoria/imagens-bomba**: endereçado
  pelo limite de dimensao maxima obrigatorio ANTES de qualquer
  decodificacao (consenso dos 2 revisores) -- valor sugerido pelo
  backend-especialista: 6000x6000 pixels (bem acima da resolucao real
  do Netum confirmada em demanda anterior, 3264x2448, e da camera do
  totem) -- **sugestao tecnica, nao decisao de produto confirmada**.
- **Produz erro generico sanitizado**: mantem o padrao ja existente
  (`RuntimeException` com mensagem fixa) -- confirmado pelo
  security-especialista que o padrao atual (`NotaController.php`/
  `DocumentoController.php` capturam a excecao e respondem mensagem
  generica, sem logar detalhe) ja e seguido e nao muda.
- **Nao registra Base64/imagem/documento/dado pessoal**: confirmado
  que nenhuma das opcoes propostas introduz log novo desse tipo --
  nenhuma checagem (varredura de bytes, `getimagesizefromstring`,
  `imagecreatefromstring`) grava conteudo binario/base64 em log.
- **Nao exige nova dependencia sem necessidade comprovada**: confirmado
  por ambos os revisores -- GD nativo (ja disponivel localmente, ja em
  uso indiretamente via `getimagesizefromstring`) e suficiente para
  qualquer uma das opcoes (c) ou (d); nenhuma recomenda Imagick/
  Intervention Image. Do ponto de vista de seguranca, GD nativo tem
  MENOR superficie de ataque adicional (sem lib externa para
  acompanhar patches separadamente, sem risco de nao estar habilitada
  no Hostgator como Imagick costuma ter).

## Matriz de limites -- atual vs. proposta

| Checagem | Hoje | Proposta |
|---|---|---|
| Prefixo MIME declarado | Exigido, so delimitador | Mantido |
| Magic bytes iniciais (SOI) | `\xFF\xD8\xFF` | Mantido |
| Marcador de fim (EOI) | NAO verificado | NOVO -- (d) checagem estrutural leve, ou (c) garantido implicitamente pela decodificacao completa |
| Tamanho maximo (bytes) | Unico `NOTA_IMAGEM_MAX_BYTES` (5MB) para todos os tipos | Mantido -- decisao de separar por tipo de documento fica registrada como pendencia a confirmar, nao decidida |
| Dimensao maxima (pixels) | NAO existe | NOVO -- limite obrigatorio (sugestao 6000x6000px, a confirmar), verificado ANTES de qualquer decodificacao |
| Dimensao minima | Nao existe | Fora do escopo pedido, nao incluido |
| Tipo de imagem (IMAGETYPE_JPEG) | Verificado via `getimagesizefromstring` | Mantido |
| Suporte a progressivo | Aceito implicitamente | Continua aceito, sem filtro novo |
| Decodificacao completa de pixels | Nao ocorre | So se a opcao (c) for escolhida |

## Arquivos que provavelmente serao alterados (/01-implementacao)

- `util/UploadHelper.php` -- unico ponto de alteracao de logica:
  (1) checagem de marcador EOI (se opcao (d)) OU chamada a
  `imagecreatefromstring()` apos checar dimensao (se opcao (c));
  (2) checagem de dimensao maxima usando `$info[0]`/`$info[1]` ja
  retornados por `getimagesizefromstring()` (sem chamada adicional).
  Nenhuma mudanca na assinatura do metodo nem no contrato de retorno.
- `.env.example`/`.env` -- SOMENTE se a decisao for introduzir nova
  variavel de ambiente para dimensao maxima (ex.
  `IMAGEM_DIMENSAO_MAXIMA_PX`) em vez de valor fixo no codigo --
  DECISAO A CONFIRMAR, nao decidida nesta etapa (valor fixo no codigo
  ja resolveria o bug; variavel de ambiente so adiciona flexibilidade
  operacional sem redeploy).
- `tests/manual/_caso_nota_processar.php` -- NAO deve precisar mudar:
  o JPEG minimo embutido (`base64_decode('/9j/4AAQ...9k=')`) ja termina
  em `/9k=`, que decodifica para os bytes FFD9 (EOI valido) -- e um
  JPEG 1x1 real e completo, nao um mock sem EOI. Confirmado por
  leitura do proprio base64. Recomendado que `/02-testes` confirme
  isso empiricamente apos a implementacao, nao so por esta leitura
  estatica.
- `tests/manual/_caso_upload_documento.php` -- reaproveita argumento
  de imagem base64 passado externamente, sem imagem embutida propria
  -- nenhuma mudanca estrutural necessaria.
- Nenhuma mudanca esperada em `app/Controller/NotaController.php`,
  `app/Controller/DocumentoController.php`, `app/Rn/DocumentoRn.php`,
  `app/Rn/NotaFiscalRn.php` -- nenhum tem logica de validacao de
  imagem propria; a mudanca fica isolada em `UploadHelper.php`.

Nenhuma alteracao em `sql/schema.sql`/migrations e necessaria.

## Contrato HTTP atual e esperado apos a correcao

- Hoje: `salvarImagemBase64()` lanca `\RuntimeException` com mensagens
  fixas ja existentes (`'Conteudo da imagem nao e um JPEG valido'`,
  `'Imagem excede o tamanho maximo permitido'`, etc.), capturadas por
  `NotaController`/`DocumentoController` e respondidas como erro
  generico HTTP (ja seguem o padrao sanitizado do projeto).
- Apos a correcao: MESMO contrato -- novo(s) caso(s) de rejeicao
  (truncamento, dimensao excessiva) devem reaproveitar o padrao ja
  existente de `\RuntimeException` + mensagem fixa generica (ex.
  reaproveitar `'Conteudo da imagem nao e um JPEG valido'` para
  truncamento, ou criar mensagem fixa nova equivalente para dimensao
  excessiva) -- SEM detalhar ao cliente qual checagem especifica
  falhou. Nao ha mudanca de codigo HTTP esperada (continua o mesmo
  fluxo de erro generico ja usado pelos 2 controllers chamadores).
- Contrato de SUCESSO nao muda: qualquer imagem que ja era aceita HOJE
  e continua sendo aceita (JPEG integro, dentro do limite de bytes e
  do novo limite de dimensao) -- a mudanca so adiciona novos casos de
  REJEICAO, nunca reduz o que ja era valido.

## Risco de falso positivo contra hardware real

- Capturas atuais usam `canvas.toDataURL` -- tipicamente JFIF puro,
  sem EXIF/thumbnail embutido -- baixo risco HOJE.
- **Risco futuro identificado pelo security-especialista**: o driver
  do Netum SD-2000 pode, dependendo de firmware, gerar JPEG com
  container Exif (APP1) contendo thumbnail JPEG embutido ("JPEG dentro
  do JPEG"). Uma checagem estrutural INGENUA (busca simples de
  substring) que nao respeite os tamanhos de segmento declarados
  poderia: (a) encontrar o EOI do thumbnail embutido antes do EOI real
  e rejeitar incorretamente um arquivo valido, ou (b) interpretar
  bytes do thumbnail como marcador invalido. Isso favorece: se a rota
  escolhida for (d), a implementacao precisa respeitar tamanhos de
  segmento declarados (nao busca ingenua); se for (c), o GD/libjpeg ja
  trata isso corretamente (delega ao decoder real).
- Nao ha evidencia arquivada de JPEG real do Netum para testar contra
  a validacao nova antes de producao -- recomendada nova captura
  fisica em `/02-testes`, se possivel dentro do escopo autorizado
  (sem documento real, ver restricoes).

## Compatibilidade PHP 8.0.30 / Hostgator

- `getimagesizefromstring()` -- nativa do PHP core, ja em uso.
- Checagem manual de bytes (opcao (d)) -- manipulacao de string pura,
  100% nativa, zero dependencia de extensao.
- GD/`imagecreatefromstring()` (opcao (c)) -- confirmado disponivel
  LOCALMENTE; NAO confirmado em producao Hostgator (pendencia
  pre-existente, nao resolvida por este plano -- se (c) for escolhida,
  vira bloqueante confirmar antes de `/01-implementacao`).
- `memory_limit`/`upload_max_filesize`/`post_max_size` em producao
  Hostgator -- ja registrados como pendencia nao confirmada em
  `docs/deploy-checklist.md`, relevante especialmente se (c) for
  escolhida.

## Impacto em OCR/Talent/banco/armazenamento

- Contrato de sucesso de `salvarImagemBase64()` nao muda (assinatura,
  tipo de retorno, efeito colateral identicos para qualquer imagem que
  ja era aceita e continua sendo).
- OCR client-side nao afetado (roda sobre canvas em memoria antes do
  upload).
- Talent/banco: nenhum campo/endpoint depende do conteudo binario da
  imagem -- sem impacto.
- Testes existentes (`_caso_nota_processar.php`) nao devem quebrar
  (JPEG sintetico ja tem EOI valido) -- a confirmar empiricamente em
  `/02-testes`.

## Estrategia de rollback

Mudanca isolada em `util/UploadHelper.php`, aditiva (novas checagens
de rejeicao, sem alterar as existentes) -- reverter e remover as
checagens novas (marcador EOI e/ou limite de dimensao), sem migration
de schema envolvida, sem mudanca de contrato de sucesso a desfazer.

## Matriz de testes planejada (fixtures sinteticas, nenhum dado real)

Todos os casos abaixo usam JPEG sintetico gerado localmente (ex. via
GD `imagecreate`+`imagejpeg` para gerar validos, e manipulacao manual
de bytes para os invalidos) -- NUNCA documento, CPF, CNH, CRLV, placa
ou imagem pessoal real.

1. JPEG baseline valido (pequeno, gerado via GD) -- deve ser aceito.
2. JPEG progressivo valido (gerado com flag de progressive, se GD
   suportar, ou fixture sintetica conhecida) -- deve ser aceito.
3. Arquivo sem marcador inicial (SOI removido/corrompido) -- deve ser
   rejeitado (ja rejeitado hoje pelos magic bytes).
4. Arquivo sem marcador final (EOI removido, truncamento no meio do
   scan) -- deve ser rejeitado (este e o bug a corrigir -- hoje passa
   incorretamente).
5. Truncamento no meio de um segmento de metadado (ex. cortar no meio
   do segmento SOF ou APPn, antes mesmo do fim do cabecalho) -- deve
   ser rejeitado.
6. Dimensoes legiveis (SOF completo, dimensoes corretas) mas dados de
   scan incompletos logo apos o SOS -- deve ser rejeitado (variacao
   direta do bug relatado).
7. MIME falso (prefixo `data:image/jpeg;base64,` mas conteudo real e
   outro formato, ex. PNG) -- deve continuar rejeitado (ja rejeitado
   hoje pelos magic bytes).
8. Conteudo nao-JPEG com extensao ou prefixo JPEG (mesmo caso do item
   7, reforcando que extensao/MIME informado nunca e fonte de
   verdade) -- deve continuar rejeitado.
9. Base64 invalido (string corrompida, nao decodificavel) -- deve
   continuar rejeitado (ja tratado hoje, `base64_decode` estrito).
10. Arquivo vazio (base64 decodifica para string vazia) -- deve
    continuar rejeitado (ja tratado hoje).
11. Arquivo acima do limite de tamanho (`NOTA_IMAGEM_MAX_BYTES`) --
    deve continuar rejeitado (ja tratado hoje).
12. Dimensoes excessivas (JPEG valido e integro, mas declarando
    dimensoes acima do novo limite, ex. acima de 6000x6000px, sugestao
    a confirmar) -- deve ser rejeitado pelo NOVO limite de dimensao,
    ANTES de qualquer decodificacao completa (se opcao (c) for
    escolhida) ou apos a checagem estrutural (se opcao (d)).
13. Bytes legitimos antes/depois da estrutura JPEG, se aplicavel (ex.
    JPEG com segmento APP1/Exif com thumbnail embutido, simulando o
    que o Netum poderia produzir) -- deve ser aceito se a imagem
    principal for integra, mesmo com o "JPEG dentro do JPEG" do
    thumbnail (calibracao explicita pedida pelo security-especialista
    para evitar falso positivo).
14. Regressao dos fluxos de CNH, CRLV, nota e Netum: reexecutar os
    testes/fluxos existentes (incluindo `_caso_nota_processar.php`,
    `_caso_upload_documento.php`, e os fluxos completos de Recebimento/
    Expedicao ja cobertos por `teste_fluxo_recebimento_documentos.php`)
    para confirmar zero regressao com JPEGs sinteticos validos ja
    aceitos hoje.

Uso de fixtures sinteticas apenas -- gerar localmente com GD (imagens
minimas 1x1 ou pequenas, cores solidas), nunca reaproveitar imagem de
documento real de nenhum tipo.

## Criterios objetivos de aceite

### Para /01-implementacao
- Rota escolhida ((c) ou (d)) implementada exclusivamente em
  `util/UploadHelper.php`, sem alterar assinatura/contrato de retorno.
- Limite de dimensao maxima implementado e verificado ANTES de
  qualquer decodificacao completa (se (c) for a rota escolhida).
- Nenhuma nova dependencia composer introduzida (GD nativo e
  suficiente).
- Mensagens de erro continuam genericas e fixas, sem detalhar qual
  checagem falhou, sem logar base64/binario/dado pessoal.
- Testes existentes (`_caso_nota_processar.php`, fluxos de Recebimento/
  Expedicao) continuam passando sem alteracao propria.

### Para /02-testes
- Todos os 14 itens da matriz de testes executados com fixtures
  sinteticas isoladas, com resultado esperado confirmado.
- Zero uso de documento/CPF/CNH/CRLV/placa real.
- Regressao completa dos fluxos de CNH, CRLV, nota e Netum sem
  quebra.
- Se possivel, nova captura fisica real do Netum/camera do totem
  testada contra a validacao nova (fora do escopo obrigatorio, mas
  recomendado).

### Para /03-revisao
- Revisao independente (backend + seguranca) confirma que a rota
  escolhida cobre efetivamente o bug relatado (ausencia de EOI) sem
  introduzir falso positivo contra JPEG real do hardware do totem.
- Se a opcao (c) foi escolhida: confirmar que a checagem de dimensao
  SEMPRE ocorre antes da decodificacao (nenhum caminho de codigo
  decodifica sem checar dimensao primeiro).
- Nenhuma exposicao de SQL/stack trace/caminho/credencial/dado pessoal
  em nenhuma mensagem de erro nova.

## O que sera feito (resumo)

Corrigir `util/UploadHelper.php::salvarImagemBase64()` para rejeitar
JPEG truncado (sem EOI) e adicionar limite de dimensao maxima,
seguindo a estrategia que o usuario confirmar entre (c) e (d) --
decisao bloqueante registrada acima.

## O que NAO sera feito

- Nenhuma alteracao em `app/Controller/NotaController.php`,
  `app/Controller/DocumentoController.php`, `app/Rn/DocumentoRn.php`,
  `app/Rn/NotaFiscalRn.php` (nenhum tem logica de validacao de imagem
  propria).
- Nenhuma nova dependencia composer (Imagick, Intervention/Image).
- Nenhuma mudanca em `sql/schema.sql`/migrations.
- Nenhum limite de tamanho separado por tipo de documento (permanece
  fora do escopo, a menos que o usuario decida incluir).
- Nenhuma chamada real ao Talent/VIO, nenhuma impressao, nenhum dado
  real usado.

## Sub-agentes envolvidos

- `explorer` -- mapeamento completo das superficies de validacao de
  imagem em todo o backend/front-end.
- `backend-especialista` -- confirmacao tecnica da causa raiz,
  comparacao de estrategias, plano de arquivos a alterar.
- `security-especialista` -- analise de risco de cada estrategia
  (bypass de EOI forjado, bomba de descompressao, CVEs de libgd,
  superficie de nova dependencia).
- `trello-especialista` -- criacao do cartao de acompanhamento.

## Pendencias conhecidas

1. **BLOQUEANTE**: decisao entre estrategia (c) decodificacao completa
   via GD ou (d) checagem estrutural leve -- divergencia entre
   revisores, requer confirmacao do usuario antes de `/01-implementacao`.
2. Valor exato do limite de dimensao maxima (sugestao tecnica:
   6000x6000px) -- nao confirmado pelo usuario.
3. Decisao sobre introduzir ou nao nova variavel de ambiente para
   dimensao maxima vs. valor fixo no codigo -- nao decidida.
4. Disponibilidade de GD e valores reais de memory_limit/
   upload_max_filesize/post_max_size em producao Hostgator -- nao
   confirmados (pendencia pre-existente, relevante especialmente se
   (c) for escolhida).
5. Formato real dos bytes produzidos pelo driver do Netum SD-2000
   (JFIF puro vs. Exif com thumbnail embutido) -- nao confirmado,
   relevante para calibrar checagem estrutural se (d) for escolhida.
6. Se `canvas.toDataURL()` no Chromium kiosk do mini PC real sempre
   gera baseline (nunca progressivo) -- nao formalmente confirmado,
   tratado como premissa razoavel.
7. Nao ha evidencia arquivada de JPEG real do Netum/camera do totem
   para reteste contra a validacao nova -- recomendada nova captura
   fisica em `/02-testes`, se possivel.
8. Contrato de como o Talent trata (ou nao) imagem malformada no
   payload base64 -- `docs/manual_talent.md` ainda placeholder nesse
   aspecto, nao investigado nesta demanda.

Item 1 e a unica pendencia que BLOQUEIA o inicio de `/01-implementacao`
-- todas as outras podem ser resolvidas/confirmadas durante a
implementacao ou ficam registradas para acompanhamento futuro.


## Decisao confirmada pelo usuario (2026-09-17)

Estrategia HIBRIDA confirmada, resolvendo a divergencia registrada
acima: validacao estrutural leve + limite de bytes existente + limite
de dimensao (5000px por lado, 13.000.000px de area) ANTES da
decodificacao + decodificacao completa via GD (fail-closed, sem
fallback para so-estrutural se GD indisponivel). Constantes nomeadas
no codigo, SEM nova variavel .env. GD obrigatorio, nenhuma
dependencia nova.

## Resultado da implementacao (2026-09-17, /01-implementacao)

### O que foi feito

util/UploadHelper.php::salvarImagemBase64() reescrito seguindo a
ordem obrigatoria das 15 etapas: prefixo data URL + base64 estrito ->
limite de bytes (inalterado) -> SOI (3 bytes FFD8FF) -> EOI (FFD9)
EXATAMENTE nos ultimos 2 bytes do binario (rejeita truncamento E bytes
extras apos o EOI real) -> getimagesizefromstring() para tipo real
(IMAGETYPE_JPEG) e dimensoes -> limite de dimensao por lado (5000px,
constante JPEG_DIMENSAO_MAXIMA_PX) -> limite de area (13.000.000px,
constante JPEG_AREA_MAXIMA_PX, calculado via divisao antes de
multiplicar) -> checagem fail-closed de extension_loaded('gd')/
function_exists('imagecreatefromstring') -> decodificacao completa
via imagecreatefromstring() com set_error_handler() temporario
(so marca flag, nunca loga conteudo do warning) restaurado em
finally -> imagedestroy() explicito -> grava o binario ORIGINAL em
disco (sem recompressao, sem remocao de EXIF).

docs/deploy-checklist.md atualizado com nova secao cobrindo: PHP 8
ativo, GD carregado, JPEG Support em gd_info(),
imagecreatefromstring() disponivel, memory_limit minimo 128M/
recomendado 256M, teste com JPEG sintetico valido, teste com JPEG
truncado, remocao de diagnostico temporario apos validacao (nunca
phpinfo() publico).

Nenhum outro arquivo de producao alterado (NotaController.php,
DocumentoController.php, app/Rn/*, banco, frontend, .env intactos).
php -l sem erro de sintaxe.

### ACHADO CRITICO -- decodificacao GD nao rejeita EOI forjado nesta instalacao

qa-testes executou a matriz completa de 24 casos + prova negativa
obrigatoria com fixtures 100% sinteticas. 22/24 casos PASSARAM, mas
2 casos FALHARAM (11 e 20), revelando que a premissa de que
"decodificacao completa via GD elimina o vetor de EOI forjado"
(registrada na comparacao de estrategias, opcao (c), avaliacao do
security-especialista) NAO se confirma nesta instalacao real de GD
(bundled, PHP 8.0.30, Windows): imagecreatefromstring() decodifica
SILENCIOSAMENTE -- sem retornar false e sem emitir warning -- um
JPEG truncado em QUALQUER ponto do stream de scan (testado de 5% a
99% dos dados), desde que um EOI FFD9 esteja presente no ultimo byte
do arquivo. Ou seja: um JPEG truncado com EOI colado manualmente ao
final e ACEITO pela validacao completa (estrutural + GD), quando
deveria ser rejeitado.

Consequencia real: a UNICA barreira efetiva contra truncamento hoje e
a checagem estrita de posicao do EOI (etapas 4-5) -- que cobre o bug
ORIGINAL relatado (truncamento acidental de hardware/software/rede,
sem qualquer EOI no final). A decodificacao GD completa, que deveria
ser a rede de seguranca adicional especificamente contra um EOI
forjado deliberadamente, NAO pega esse caso nesta build de GD.

Prova negativa (item 3, obrigatoria pelo usuario) NAO CONFIRMADA: "a
simples inclusao artificial de EOI nao contorna a decodificacao
completa" -- CONTRARIADA pelos testes reais. Os itens 1 e 2 da prova
negativa foram confirmados (bug original existe, validacao nova
rejeita a fixture original sem EOI forjado).

Ressalva de ambiente registrada pelo qa-testes: este e o GD "bundled"
do Windows local (estatico), nao necessariamente o libjpeg do sistema
Linux que a Hostgator usa em producao -- o comportamento pode
diferir, mas isso NAO E confirmado, e a pendencia de comportamento de
GD em producao (ja registrada no planejamento) agora tem relevancia
concreta adicional.

### Resultado dos demais 22 casos (todos PASSOU)

Baseline valido, progressivo valido, resolucao real do Netum
(3264x2448), JPEG com thumbnail/APP1 embutido, base64 invalido,
conteudo vazio, sem SOI, sem EOI (bug original -- rejeitado
corretamente pela checagem estrutural), truncamento antes do SOS,
truncamento no meio do scan (sem EOI forjado -- rejeitado
corretamente), PNG/conteudo nao-JPEG com prefixo/MIME JPEG, dimensao
exatamente no limite (5000x2600=13.000.000px, limite confirmado
INCLUSIVO), largura/altura acima de 5000px (rejeitado ANTES da
decodificacao, confirmado por rastreamento de codigo), area acima de
13M com lados <=5000 (rejeitado especificamente pelo limite de area),
arquivo acima do limite de bytes existente, bytes extras apos o EOI
real (rejeitado pela checagem estrita de posicao), GD indisponivel
simulado (fail-closed confirmado, sem fallback estrutural-only),
restauracao do error handler em sucesso e falha (confirmado, handler
global identico antes/depois), preservacao de assinatura/contrato/
gravacao (MD5 do arquivo gravado identico ao binario original, sem
recompressao), regressao de CNH/CRLV/nota (sem regressao,
teste_fluxo_recebimento_documentos.php 11/11).

Consumo maximo de memoria observado: 108.00 MB (nenhum caso
aproximou-se de memory_limit padrao).

### Arquivos de teste criados (tests/manual/, nao sao codigo de producao)

- tests/manual/_fixtures_jpeg_seguro.php (biblioteca de geracao de
  fixtures sinteticas, reutilizavel)
- tests/manual/teste_validacao_jpeg_seguro.php (orquestrador
  principal dos 24 casos + prova negativa, recomendado para
  versionamento)
- tests/manual/_caso_gd_indisponivel_mock.php (subprocesso isolado
  para simular GD indisponivel via override de funcao em namespace de
  teste, sem tocar em codigo de producao)

### Zero residuo e zero operacao real

Todas as linhas de banco de teste e pastas de storage/atendimentos/
removidas ao final. Nenhuma chamada real ao Talent/VIO, nenhuma
impressao, nenhum dado real usado, nenhum commit/push.

### Veredito

PRECISA DE AJUSTE. A implementacao cobre corretamente o bug ORIGINAL
relatado (truncamento acidental sem EOI), mas NAO cumpre o criterio
explicito do usuario de rejeitar JPEG truncado com EOI forjado
artificialmente (caso 11) nem confirma a prova negativa obrigatoria
(item 3). Isso e uma DECISAO ARQUITETURAL a ser tomada pelo usuario
antes de prosseguir, nao um bug simples de corrigir:

- Opcao A: aceitar o risco residual como esta (o bug ORIGINAL --
  truncamento acidental -- esta corrigido; o vetor de EOI forjado
  deliberado exige um agente ja capaz de manipular o payload no
  pipeline, cenario de ameaca mais restrito, ja registrado como
  pendencia mais ampla de "dispositivo comprometido com token
  valido").
- Opcao B: reforcar com parsing estrutural mais completo (percorrer
  segmentos ate o SOS respeitando tamanhos declarados, opcao "(a)"
  originalmente descartada por complexidade/manutencao) para tentar
  detectar truncamento mesmo com EOI forjado -- aumenta a superficie
  de codigo e nao elimina 100% o risco (integridade de PIXEL nunca e
  garantida so por estrutura de CONTAINER).
- Opcao C: investigar se ha configuracao/flag do GD que force
  validacao mais rigorosa dos dados de scan -- nao investigado nesta
  rodada.

Nenhuma opcao foi escolhida pelo orquestrador -- decisao devolvida ao
usuario antes de prosseguir.


## Rodada curta de /01-implementacao -- teste do modo rigoroso gd.jpeg_ignore_warning (2026-09-17)

Decisao confirmada pelo usuario para esta rodada: usar a diretiva
nativa do GD gd.jpeg_ignore_warning, alterada TEMPORARIAMENTE para 0
durante imagecreatefromstring(), sem implementar parser JPEG proprio.

### Implementacao

Reescrito somente o bloco de decodificacao GD (dentro do fail-closed
ja existente de extension_loaded('gd')/function_exists('imagecreatefromstring')),
preservando intactas todas as validacoes estruturais anteriores (SOI,
EOI exato, getimagesizefromstring(), limites de dimensao/area). Fluxo
novo, na ordem exata pedida:

1. Le ini_get('gd.jpeg_ignore_warning') e guarda o valor original. Se
   a diretiva NAO EXISTIR (retorno false), fail-closed sem decodificar.
2. ini_set('gd.jpeg_ignore_warning', '0').
3. Confirma por novo ini_get() que o valor efetivo e '0'. Se
   ini_set() falhou OU o valor confirmado nao for exatamente '0',
   restaura o valor original e aplica fail-closed sem decodificar.
4. Instala error handler temporario (so marca flag, nunca loga texto
   do warning) -- mesmo padrao ja existente.
5. Executa imagecreatefromstring().
6. Rejeita se retorno for false OU warning capturado.
7. Bloco finally cobrindo todo o trecho: libera a imagem GD se criada,
   restaura o error handler anterior, restaura EXATAMENTE o valor
   original de gd.jpeg_ignore_warning lido no passo 1 -- sempre
   executado, sucesso ou falha.

php -l sem erro de sintaxe. Nenhuma outra parte do arquivo tocada.

### Achado do ambiente local (honesto, sem maquiar resultado)

- A diretiva gd.jpeg_ignore_warning EXISTE nesta instalacao (valor
  padrao '1').
- ini_set() para '0' FUNCIONA e e CONFIRMADO por ini_get() --
  mecanismo de leitura/alteracao/confirmacao/restauracao correto e
  testado.
- Restauracao funciona corretamente: antes de qualquer chamada o
  valor e '1'; durante a chamada e '0'; depois de qualquer chamada
  (sucesso ou falha) volta a '1'.
- **PORE M**: mesmo com a diretiva efetivamente em '0',
  imagecreatefromstring() nesta build de GD (bundled, PHP 8.0.30,
  Windows) CONTINUA ACEITANDO SILENCIOSAMENTE um JPEG truncado no
  meio do scan com EOI forjado colado no ultimo byte -- testado com
  cortes de 30%, 50%, 70%, 90% do stream de scan, com e sem a
  diretiva alterada, resultado IDENTICO em ambos os modos: sempre
  aceito, nenhum warning capturado. **A diretiva nao tem efeito
  perceptivel nesta build especifica de libjpeg estatico do Windows.**
- A UNICA barreira real e confirmada contra truncamento (acidental, o
  bug ORIGINAL) continua sendo a checagem estrutural de EOI exato nos
  2 ultimos bytes -- inalterada e 100% funcional.
- Ressalva de ambiente mantida: comportamento em producao (Hostgator,
  provavelmente Linux com libjpeg do sistema via apt) NAO CONFIRMADO
  -- pode se comportar diferente do bundled estatico do Windows local,
  para melhor ou para pior.

### Smoke test (fixtures sinteticas, removidas ao final, nada no repositorio)

- JPEG valido simples: ACEITO (esperado).
- JPEG truncado sem EOI (bug original): continua REJEITADO pela
  checagem estrutural, antes mesmo de chegar ao GD (inalterado).
- JPEG truncado no meio do scan com EOI forjado colado no final:
  ACEITO neste ambiente (nao corrigido pela mudanca de diretiva).
- gd.jpeg_ignore_warning antes/depois de todas as chamadas (sucesso e
  falha): sempre volta ao valor original.
- Nenhum arquivo de teste ficou no repositorio, nenhuma chamada
  externa.

### docs/deploy-checklist.md

Adicionada subsecao "1.Y.1 Diretiva gd.jpeg_ignore_warning (reforco
contra EOI forjado, 2026-09-17)" com os 6 itens de verificacao
pedidos para producao Hostgator, registrando explicitamente que o
comportamento observado localmente foi "diretiva alteravel mas sem
efeito perceptivel contra EOI forjado" e que precisa ser reconfirmado
no ambiente real do Hostgator.

### CRITERIO DE INTERRUPCAO ACIONADO

Conforme instrucao explicita do usuario ("se gd.jpeg_ignore_warning=0
ainda aceitar silenciosamente a fixture truncada com EOI forjado,
interrompa novamente sem implementar parser proprio"): o criterio foi
ACIONADO. Esta rodada foi interrompida SEM implementar parser JPEG
proprio e SEM aceitar o risco residual por conta propria -- evidencia
registrada acima para nova decisao arquitetural do usuario.

A rodada completa dos 24 casos + regressao NAO foi executada pelo
qa-testes nesta etapa, por instrucao explicita do usuario de
interromper assim que o criterio de falha da diretiva fosse
confirmado -- o resultado dos 2 casos criticos (EOI forjado, warning
tratado como falha) ja e conhecido e seria identico ao da rodada
anterior (FALHA), tornando a reexecucao completa redundante ate uma
nova decisao arquitetural ser tomada.

### Opcoes remanescentes para o usuario

1. Aceitar o risco residual documentado -- a barreira estrutural
   (checagem exata de EOI) ja cobre o bug ORIGINAL relatado
   (truncamento acidental, cenario de ameaca mais provavel), o vetor
   de EOI forjado deliberado exige um agente ja capaz de manipular o
   payload no pipeline.
2. Reconfirmar o comportamento real em producao Hostgator antes de
   decidir -- pode se comportar diferente do Windows local (nao
   executar em producao nesta etapa, conforme restricao ja vigente).
3. Avancar para parser estrutural mais completo (opcao B do handoff
   anterior) se quiser fechar tambem o vetor de EOI forjado
   deliberado -- maior complexidade/manutencao, e mesmo assim nao
   garante integridade de pixel, so de container.


## Decisao final do produto (2026-09-17)

O usuario formalmente ACEITOU o risco residual de JPEG truncado com
EOI forjado deliberadamente, classificado como BAIXO/MODERADO.

### Justificativa

- O bug ORIGINAL que motivou esta demanda (truncamento ACIDENTAL de
  hardware/software/rede, sem qualquer EOI no final) esta corrigido e
  validado -- e o cenario de ameaca mais provavel no contexto real do
  totem.
- O vetor de EOI forjado DELIBERADO exige um agente ja capaz de
  manipular o payload no pipeline (cenario de "dispositivo
  comprometido com token valido"), mais restrito e ja registrado como
  pendencia mais ampla do projeto, nao exclusiva desta demanda.
- A tentativa de reforco via `gd.jpeg_ignore_warning=0` foi testada e
  nao teve efeito nesta instalacao de GD -- confirmado empiricamente,
  nao presumido.
- Um parser JPEG estrutural completo (opcao B) foi avaliado e
  descartado: aumenta complexidade/manutencao desproporcionalmente e
  MESMO ASSIM nao garantiria integridade de PIXEL, apenas de
  CONTAINER -- nao eliminaria o risco por completo.

### Alteracao descartada (removida do codigo)

Toda a tentativa relacionada a `gd.jpeg_ignore_warning`:
`ini_get()`/`ini_set()` dessa diretiva, restauracao temporaria, e
fail-closed condicionado a possibilidade de altera-la. Removida de
`util/UploadHelper.php` e de `docs/deploy-checklist.md`. Nenhum
arquivo de teste exclusivo dessa diretiva existia para remover.

### Protecoes que permanecem (inalteradas)

Base64 estrito; limite de 5MB; SOI (`\xFF\xD8\xFF`); EOI (`\xFF\xD9`)
EXATAMENTE no final do binario; rejeicao de bytes apos o EOI; tipo
real `IMAGETYPE_JPEG` via `getimagesizefromstring()`; largura/altura
validas; maximo de 5000px por lado (`JPEG_DIMENSAO_MAXIMA_PX`);
maximo de 13.000.000px de area (`JPEG_AREA_MAXIMA_PX`, calculo via
divisao antes de multiplicar); GD obrigatorio (fail-closed se
ausente); `imagecreatefromstring()` como validacao adicional; warning
do GD tratado como falha; restauracao do error handler em `finally`;
liberacao do objeto GD (`imagedestroy()`); gravacao so apos todas as
verificacoes.

### Resultado final dos testes

**22 controles obrigatorios, 22 passaram, 0 falharam.** Dois
diagnosticos de limitacao conhecida documentados SEPARADAMENTE (nao
contam para aprovacao/reprovacao), ambos com a MESMA causa raiz
(leniencia do GD/libjpeg nesta instalacao especifica -- bundled, PHP
8.0.30, Windows -- diante de dados de scan corrompidos/truncados):
1. JPEG truncado com EOI forjado -- aceito silenciosamente.
2. Warning do GD durante decodificacao -- nao foi possivel reproduzir
   um cenario de bit-flip que produza warning SEM tambem produzir
   retorno `false` nesta build de GD (a logica de tratamento de
   warning em `UploadHelper.php` esta correta por leitura de codigo --
   rejeitaria se o cenario ocorresse -- so nao ha fixture capaz de
   disparar esse gatilho especifico nesta instalacao).

Regressao de CNH, CRLV, nota fiscal e Netum: sem quebra
(`teste_fluxo_recebimento_documentos.php` 11/11,
`teste_e2e_recebimento_expedicao_mock.php` 30/30). Consumo maximo de
memoria observado: 120,00 MB.

### Limites da solucao (registrados explicitamente, sem promessa de mais do que foi entregue)

A validacao implementada PROTEGE contra corrupcao ACIDENTAL
(truncamento sem EOI, o bug original). Ela NAO comprova integridade
visual completa da imagem nem autenticidade documental -- um JPEG
deliberadamente forjado com EOI colado manualmente pode ser aceito
nesta instalacao de GD (risco residual aceito). Comportamento real de
GD/libjpeg em producao Hostgator (provavelmente Linux, libjpeg do
sistema) permanece NAO CONFIRMADO -- pode diferir do bundled estatico
do Windows local, para melhor ou para pior.

### Recomendacao futura (registrada, nao implementada nesta demanda)

Se a legibilidade/autenticidade do documento (nao so a integridade
estrutural do arquivo) precisar de garantia adicional no futuro,
tratar via OCR (ja existente client-side, `ocr-worker.js`), validacao
VIO Decode (ja usada para CNH/CRLV via QR), ou conferencia
operacional humana (ex. balcao da portaria) -- nenhuma dessas
abordagens foi implementada ou expandida nesta demanda.

### Docs atualizados

`docs/deploy-checklist.md`: preserva PHP 8/GD/suporte JPEG/
`imagecreatefromstring()`/`memory_limit`; removida a exigencia de
controlar `gd.jpeg_ignore_warning`; removida a afirmacao de que EOI
forjado sera rejeitado; adicionada observacao explicita sobre o
limite da validacao (protege corrupcao acidental, nao autenticidade).

`tests/manual/teste_validacao_jpeg_seguro.php`: reclassificados os 2
diagnosticos de limitacao conhecida como blocos separados, sem afetar
o contador de controles obrigatorios -- reexecucoes futuras deste
script nao reportarao falha falsa por esses 2 pontos ja aceitos.


## Resultado dos testes -- /02-testes independente (2026-09-17)

Dois revisores independentes (qa-testes + security-especialista, sem
participacao na implementacao) reexecutaram tudo do zero com
ceticismo, sem confiar em nenhum resultado relatado nas rodadas
anteriores.

### Escopo confirmado

`git status`/`git diff --stat` confirmam alteracao pendente somente
em: `util/UploadHelper.php`, `docs/deploy-checklist.md`,
`ia_development_state.md` (modificados), `docs/handoffs/2026-09-17-validacao-jpeg-segura.md`,
`tests/manual/teste_validacao_jpeg_seguro.php` (novos). Nenhum
controller, frontend, schema de banco ou `.env*` alterado.
`tests/manual/_fixtures_jpeg_seguro.php` e
`tests/manual/_caso_gd_indisponivel_mock.php` existem em disco mas
sao cobertos por `.gitignore` pre-existente (`tests/manual/_*.php`) --
observacao registrada (nao bloqueante), confirmando que essa e a
convencao ja usada pelo projeto para scripts descartaveis, nao uma
omissao desta demanda.

### Controles obrigatorios -- 22/22 CONFIRMADOS (por 2 execucoes independentes)

Ambos os revisores reescreveram seus proprios orquestradores de teste
(nao reaproveitaram cegamente o script da implementacao) e confirmaram
os 22 itens: base64 estrito, limite de 5MB, SOI, EOI exato no fim,
bytes apos o EOI, tipo real IMAGETYPE_JPEG, dimensoes positivas,
limite de 5000px por lado (rejeitado ANTES da decodificacao,
confirmado por rastreamento de codigo), limite de 13M pixels de area
(calculo via divisao confirmado), GD obrigatorio/fail-closed
(reproduzido via mock), ordem exata de validacao confirmada por
rastreamento completo do arquivo, warning do GD impede gravacao
(logica confirmada por leitura -- mesma limitacao de reproducao ja
conhecida, nao uma falha nova), error handler sempre restaurado,
`imagedestroy()` chamado, gravacao so apos todas as validacoes, zero
residuo em rejeitados, baseline/progressivo/resolucao real do Netum
aceitos, mensagens de erro fixas/genericas, dimensao exata no limite
aceita (inclusivo).

### Risco residual aceito -- 7/7 confirmacoes CONFIRMADAS

Ambos os revisores reproduziram de forma independente o EOI forjado
aceito silenciosamente (confirmando o risco documentado), e
confirmaram por leitura textual: documentado como limitacao
conhecida; nenhuma promessa exagerada de integridade visual/
autenticidade em nenhum texto do projeto; `gd.jpeg_ignore_warning`
so aparece como registro historico/comentario explicativo, nunca como
codigo ativo ou requisito; nenhum parser JPEG proprio; pendencia
marcada RESOLVIDA COM RISCO RESIDUAL ACEITO; item retirado da lista
ativa da secao 5.1 sem pendencia equivalente criada.

### Memoria -- medicao ISOLADA (metodologia com subprocessos separados)

Fixture gerada em subprocesso dedicado, validacao medida em processo
limpo separado, testando o PIOR CASO legitimo (5000x2600=13.000.000px,
exatamente no limite):
- Memoria inicial do processo: ~2MB.
- Pico total (`memory_get_peak_usage(true)`): **~56,6MB**, IDENTICO
  em 128M e 256M de `memory_limit`.
- Aumento causado pela validacao: consistente com o buffer bruto GD
  (~5000x2600x4 bytes ~49,6MB + overhead).
- 128M: completa sem erro, **margem de ~71MB (56% de folga)**.
- 256M: completa sem erro, margem de ~199MB.
- 2 imagens sequenciais no mesmo processo (simulando CNH frente+verso):
  pico IDENTICO ao de 1 imagem em ambos os limites -- confirma
  liberacao correta de memoria entre chamadas, sem acumulo.
- Estouro forcado (16M e 32M): processo morre DENTRO de
  `imagecreatefromstring()`, ANTES da etapa de gravacao -- **zero
  arquivo parcial gravado** em disco em ambos os casos.

**Avaliacao da margem (aplicando o criterio definido)**: 56,6MB de
pico usa 44% de um limite de 128M, sobrando 56% de folga mesmo no
pior caso legitimo E com 2 imagens sequenciais na mesma execucao (sem
acumulo). Margem real e significativa, nao "passou por pouco".
**Conclusao: a recomendacao atual do `docs/deploy-checklist.md`
("128M minimo / 256M recomendado") PERMANECE VALIDA -- nao e um
achado bloqueante, nenhuma correcao documental necessaria.**

Confirmado que `docs/deploy-checklist.md` (secao "1.Y") lista os
itens de GD/`memory_limit`/JPEG Support como checklist explicito `[ ]`
a confirmar ANTES do deploy -- serve como gate adequado, nao teste em
producao/Hostgator realizado nesta etapa.

### Seguranca (security-especialista, revisor independente)

Sem achado critico ou de atencao nos 7 pontos avaliados: sanitizacao
de logs/respostas (zero base64/warning bruto/credencial/caminho
local), nome de arquivo sempre definido pelo servidor, MIME do
cliente nunca usado como fonte de verdade, `git diff --check` limpo,
compatibilidade PHP 8.0 confirmada (nenhuma funcao exclusiva de 8.1+),
zero nova dependencia (`composer.json` inalterado), documentacao do
risco residual sem exagero e sem reintroducao dissimulada da
pendencia, regressao de seguranca geral (IDOR/posse ja corrigidos em
demandas anteriores) intacta. 1 observacao nao bloqueante: os 2
arquivos de teste `_fixtures_jpeg_seguro.php`/`_caso_gd_indisponivel_mock.php`
nunca serao versionados por regra pre-existente do `.gitignore` --
esperado no projeto, vale ciencia explicita.

### Regressao

`teste_fluxo_recebimento_documentos.php`: 11/11 PASSOU (ambos os
revisores). `teste_e2e_recebimento_expedicao_mock.php`: 30/30 PASSOU
(ambos os revisores). Sem regressao.

### Confirmacoes finais

Zero residuo (todos os scripts/fixtures dos revisores ficaram fora do
repositorio, em scratchpad de sessao, removidos ao final). Zero dado
real usado, zero chamada real ao Talent/VIO, zero impressao, zero
alteracao de banco/registro real, zero acesso a producao/Hostgator,
zero commit/push nesta etapa.

### Veredito consolidado

**APROVADO -- liberado para `/03-revisao`.** A observacao sobre
`.gitignore` (item 6 da seguranca) e nao bloqueante e nao impede o
fechamento desta etapa.


## Resultado da revisao -- /03-revisao (2026-09-17)

Dois revisores independentes (backend-especialista + security-especialista,
sem participacao na implementacao) releram todo o codigo do zero.

### Escopo confirmado

Alteracao pendente somente em: `util/UploadHelper.php`,
`docs/deploy-checklist.md`, `ia_development_state.md` (modificados),
`docs/handoffs/2026-09-17-validacao-jpeg-segura.md`,
`tests/manual/teste_validacao_jpeg_seguro.php` (novos). Nenhum
controller, frontend, schema de banco ou `.env*` alterado.

### Implementacao -- 20/20 pontos SEM ACHADO

Confirmados por ambos os revisores, com evidencia de arquivo+linha:
base64 estrito, limite de 5MB preservado, SOI, EOI exato no fim,
bytes apos o EOI rejeitados, MIME do cliente nunca confiado, tipo
real IMAGETYPE_JPEG, dimensoes positivas, limite de 5000px por lado,
limite de 13.000.000px de area, calculo seguro contra overflow
(divisao antes de multiplicar), dimensoes verificadas ANTES da
decodificacao (ordem exata rastreada linha a linha), GD/
imagecreatefromstring obrigatorios com fail-closed, nenhum caminho de
erro chega a gravacao, error handler sempre restaurado em finally,
imagedestroy sempre liberado, gravacao so apos todas as verificacoes,
mensagens/logs sem dado sensivel, zero dependencia nova,
compatibilidade PHP 8.0 confirmada.

### REPRODUTIBILIDADE -- ACHADO BLOQUEANTE CONFIRMADO

`backend-especialista` executou teste EMPIRICO real (nao presumido)
de reprodutibilidade a partir de clone limpo:
- `git ls-files tests/manual/` confirma que `_fixtures_jpeg_seguro.php`
  e `_caso_gd_indisponivel_mock.php` NAO estao rastreados (ambos
  batem com `.gitignore:37:tests/manual/_*.php`, confirmado via
  `git check-ignore -v`).
- `tests/manual/teste_validacao_jpeg_seguro.php` (script RASTREADO,
  entregue nesta demanda) tem `require_once` OBRIGATORIO e sem guard
  para `_fixtures_jpeg_seguro.php` (linha 28) e usa `exec()` para
  invocar `_caso_gd_indisponivel_mock.php` como subprocesso (caso 21).
- Teste real em diretorio temporario ISOLADO fora do repositorio,
  copiando APENAS os arquivos rastreados relevantes (`util/UploadHelper.php`,
  `tests/manual/teste_validacao_jpeg_seguro.php`) + `vendor/` +
  `.env` sintetico proprio (nunca o `.env` real): `php teste_validacao_jpeg_seguro.php`
  falhou imediatamente com `Fatal error: Uncaught Error: Failed
  opening required '...\_fixtures_jpeg_seguro.php'` na linha 28,
  ANTES de executar qualquer caso de teste.

**Conclusao**: um desenvolvedor que clonar o repositorio limpo e
rodar `composer install` NAO CONSEGUE executar a suite de `/02-testes`
desta demanda de forma alguma -- nem os 22 controles obrigatorios,
nem a prova negativa/diagnosticos de risco residual. A convencao do
`.gitignore` (`tests/manual/_*.php`, pre-existente e usada para
scripts auxiliares OPCIONAIS de outras demandas) NAO justifica isso
neste caso especifico, porque o proprio script ENTREGUE nesta demanda
depende obrigatoriamente dos arquivos ignorados para funcionar.

**Classificacao: BLOQUEANTE**, conforme criterio ja definido pelo
usuario antes desta revisao. Correcao necessaria (uma das duas
opcoes, decisao de implementacao, nao de produto):
(a) versionar `_fixtures_jpeg_seguro.php` e `_caso_gd_indisponivel_mock.php`
(excecao explicita no `.gitignore`, ex. `!tests/manual/_fixtures_jpeg_seguro.php`),
ou (b) inline-ar o conteudo dessas duas dependencias diretamente
dentro do proprio `teste_validacao_jpeg_seguro.php` rastreado,
tornando-o autossuficiente.

### Checklist Hostgator -- SEM ACHADO

`docs/deploy-checklist.md` secao "1.Y" confirma explicitamente todos
os itens exigidos (PHP 8, GD, suporte JPEG em `gd_info()`,
`imagecreatefromstring()`, `memory_limit` 128M/256M, testes
controlados antes da ativacao, nenhum `phpinfo()` publico, remocao de
diagnostico temporario) formulados como checklist a validar "antes do
deploy", sem nenhuma instrucao de testar em producao agora.

### Seguranca e higiene -- APROVADO COM 1 RESSALVA (nao bloqueante)

`php -l` limpo em todos os PHP tocados (rastreados e nao rastreados).
`git diff --check` limpo. Zero segredo/dado pessoal/base64
real/caminho de maquina. Nomes de arquivo confirmados sempre
controlados pelo servidor (allowlist fechada de `$tipo`, `$ordem`
validado como int). Zero alteracao de banco/controller/frontend/
contrato HTTP. Risco residual aceito confirmado consistente entre
codigo e documentacao, sem contradicao, sem promessa exagerada, sem
`gd.jpeg_ignore_warning` ativo, sem parser proprio, historico
preservado, pendencia corretamente retirada da lista ativa 5.1 sem
recriacao dissimulada. Zero dependencia nova.

**Achado de ATENCAO (nao bloqueante)**: resid uo fisico encontrado em
`storage/atendimentos/2026-09-17/TST0A01_142344/nota_01.jpg`
(3264x2448, resolucao identica a citada para o Netum SD-2000 no
handoff) -- nao esta sob controle do Git (`storage/` no `.gitignore`),
mas contradiz a alegacao repetida no handoff de "zero residuo". Nao
e dado pessoal real (placa `TST0A01` e marcador sintetico ja
convencionado do projeto, usado em outras demandas de teste de ordem
de coleta). Precisa ser investigado (confirmar origem exata) e
removido antes do commit; se confirmado como originado desta demanda,
a alegacao de "zero residuo" no handoff deve ser corrigida com
ressalva.

### Veredito consolidado

**PRECISA DE AJUSTE.** Unico ponto que precisa retornar para uma
rodada curta de `/01-implementacao`: tornar
`tests/manual/teste_validacao_jpeg_seguro.php` autossuficiente a
partir de um clone limpo (versionar as 2 dependencias ou inline-ar
seu conteudo). A implementacao de producao em `util/UploadHelper.php`
(todos os 20 pontos), `docs/deploy-checklist.md` e o escopo do diff
estao corretos e NAO precisam de nenhuma alteracao. O residuo fisico
em `storage/` deve ser investigado/limpo na mesma rodada, por
oportunidade, mas nao e o motivo do bloqueio.

## Rodada curta de /01-implementacao -- correcao de reprodutibilidade + limpeza de residuo (2026-09-17)

Restrita aos 2 achados do `/03-revisao` acima (BLOQUEANTE de
reprodutibilidade + ATENCAO de residuo fisico). Nenhuma alteracao em
`util/UploadHelper.php`, nenhuma regra de validacao/limite/risco
residual tocada.

### 1. Reprodutibilidade -- correcao aplicada (renomeacao, opcao (b) adaptada)

Em vez de versionar via excecao no `.gitignore` (opcao (a) do
`/03-revisao`, descartada por restricao explicita de nao tocar
`.gitignore`/nao usar `git add -f`), os 2 arquivos foram RENOMEADOS no
filesystem, removendo o prefixo `_` para que deixem de bater com a
regra `tests/manual/_*.php`:

- `tests/manual/_fixtures_jpeg_seguro.php` -> `tests/manual/fixtures_jpeg_seguro.php`
- `tests/manual/_caso_gd_indisponivel_mock.php` -> `tests/manual/caso_gd_indisponivel_mock.php`

`tests/manual/teste_validacao_jpeg_seguro.php` atualizado: o
`require_once` (linha 28) agora aponta para
`__DIR__ . '/fixtures_jpeg_seguro.php'`; o `exec()` do caso 21 agora
monta o comando com `__DIR__ . '/caso_gd_indisponivel_mock.php'`.
Ambos os caminhos ja eram (e continuam sendo) relativos a `__DIR__`,
independentes do diretorio corrente de onde o script e chamado.
Comentarios internos dos 2 arquivos renomeados (linha de `Uso:`)
tambem atualizados para o novo nome. Confirmado por grep completo do
projeto: zero referencia viva aos nomes antigos
(`_fixtures_jpeg_seguro.php`, `_caso_gd_indisponivel_mock.php`) fora
de trechos historicos deste proprio handoff/`ia_development_state.md`
que narram o achado ORIGINAL do `/03-revisao` (mantidos como registro
historico do que foi encontrado, nao como referencia viva de codigo).

Confirmado `git status`: os 2 novos nomes aparecem como `??`
(untracked, prontos para `git add`). Confirmado
`git check-ignore -v tests/manual/fixtures_jpeg_seguro.php
tests/manual/caso_gd_indisponivel_mock.php`: sem retorno (exit code 1,
nenhuma linha impressa) -- confirmado que NENHUM dos dois bate mais
com `.gitignore:tests/manual/_*.php` nem com nenhuma outra regra.
`.gitignore` NAO foi tocado, `git add -f` NAO foi usado.

O `exec()` do caso 21 monta o comando como
`escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(caminho) . ' ' .
escapeshellarg(caminho_entrada)` -- um comando `php ...` simples, sem
`&&`/pipe/sintaxe especifica de shell, e todos os caminhos ficam entre
aspas via `escapeshellarg()`, portanto continuam funcionando mesmo se
o caminho contiver espacos/parenteses (ex. `...\AppData\Local\Temp\...`
do Windows).

### 2. Prova em arvore limpa

Criada arvore temporaria em scratchpad de sessao (fora do
repositorio), contendo apenas: `app/`, `util/`, `vendor/`, `tests/`,
`composer.json`/`composer.lock` copiados do projeto real, mais um
`storage/atendimentos/` vazio proprio. NENHUMA fixture com prefixo
`_` de rodadas anteriores foi copiada (confirmado por `ls`/`grep` na
arvore: so `fixtures_jpeg_seguro.php`, `caso_gd_indisponivel_mock.php`
e `teste_validacao_jpeg_seguro.php` existem em `tests/manual/`
relacionados a esta demanda).

Para `tests/manual/teste_validacao_jpeg_seguro.php` (o script cujo
achado BLOQUEANTE motivou esta rodada, e que NAO depende de banco de
dados -- `UploadHelper::salvarImagemBase64()` nao toca em PDO), foi
criado um `.env` SINTETICO minimo na arvore, contendo apenas
`STORAGE_PATH` (apontando para a pasta de storage isolada da propria
arvore) e `NOTA_IMAGEM_MAX_BYTES` -- zero segredo/credencial real
presente. Executado `php tests/manual/teste_validacao_jpeg_seguro.php`
de dentro da propria arvore: **22/22 controles obrigatorios PASSOU, 0
FALHOU**, os 2 diagnosticos de limitacao conhecida (EOI forjado e
warning do GD) impressos separadamente sem afetar o contador (igual ao
comportamento ja documentado), prova negativa (itens 1-2) CONFIRMADA.
Consumo de memoria identico ao ja registrado (108,00 MB). Nenhuma
dependencia ausente, nenhum acesso ao worktree original do projeto
durante a execucao -- confirmado pela propria estrutura da arvore (so
continha os arquivos copiados).

**Limitacao honesta desta rodada**: `tests/manual/teste_fluxo_recebimento_documentos.php`
e `tests/manual/teste_e2e_recebimento_expedicao_mock.php` PRECISAM de
acesso real a banco de dados (via `util/Conexao.php`,
`$_ENV['DB_HOST']`/`DB_NAME`/`DB_USER`/`DB_PASS`). A tentativa de
copiar o `.env` real do projeto (que contem essas credenciais, alem de
outros segredos de API nao relacionados: Talent, VIO, Trello) para a
arvore temporaria foi BLOQUEADA automaticamente pelo classificador de
seguranca do ambiente do agente ("Credential Leakage"), antes de
qualquer copia efetiva ocorrer. Por decisao explicita de nao tentar
contornar esse bloqueio por vias alternativas, esses 2 arquivos de
regressao foram executados NO PROPRIO PROJETO REAL (que ja contem os
2 arquivos renomeados desta correcao, com o `.env` de dev local
ja existente ali, sem necessidade de duplicar segredo em lugar
nenhum) -- **nao** numa arvore hermeticamente separada. Resultado:
`teste_fluxo_recebimento_documentos.php` **11/11 PASSOU**;
`teste_e2e_recebimento_expedicao_mock.php` **30/30 PASSOU**. Confirmado
por `find storage/atendimentos -newermt "-5 minutes"` que nenhum
arquivo/pasta novo foi deixado por essa execucao (os testes ja se
autolimpam, como documentado em rodadas anteriores). Isso significa
que a reprodutibilidade "clone limpo total" foi comprovada
empiricamente de forma completa apenas para o script diretamente
relacionado ao achado BLOQUEANTE (`teste_validacao_jpeg_seguro.php`,
que era o unico citado literalmente no achado do `/03-revisao`); para
os 2 scripts de regressao dependentes de banco, a reproducao ficou
restrita ao projeto real (que ja e, em si, o "clone" contendo a
correcao) em vez de uma copia hermetica adicional, por bloqueio de
seguranca do ambiente do agente, nao por limitacao do codigo do
projeto.

Ao final, a arvore temporaria completa foi REMOVIDA
(`rm -rf` do diretorio proprio do scratchpad desta rodada) -- nada
sobrou fora do repositorio real.

### 3. Investigacao e limpeza do residuo fisico

Alvo: `storage/atendimentos/2026-09-17/TST0A01_142344/nota_01.jpg`.

Confirmacoes ANTES da remocao:
1. Caminho absoluto:
   `C:\xampp\htdocs\totem-udlog\storage\atendimentos\2026-09-17\TST0A01_142344\nota_01.jpg`.
2. NAO e symlink -- confirmado via `is_link()` em PHP, retornou `false`.
3. NAO rastreado pelo Git -- `git ls-files -- storage/` nao retorna
   nenhuma linha (toda a pasta `storage/` esta no `.gitignore`,
   confirmado).
4. Placa `TST0A01` e o marcador sintetico ja convencionado do projeto
   para testes (confirmado: ha 92 pastas com o mesmo prefixo
   `TST0A01_*` espalhadas em `storage/atendimentos/` de datas
   anteriores -- 2026-09-04 a 2026-09-16 -- todas de OUTRAS demandas,
   fora do escopo desta limpeza). O arquivo em si e um JPEG baseline
   valido (`JFIF standard 1.01`, `3264x2448`, resolucao IDENTICA a
   citada para o Netum SD-2000 neste handoff, confirmando origem de
   fixture sintetica de teste, nao foto real) -- confirmado via
   `getimagesize`/`file` sem exibir o conteudo visual da imagem em
   nenhum momento; amostragem de pixels via GD (grade de pontos, sem
   renderizar a imagem) mostrou padrao compativel com fixture gerada
   (nao inspecionado visualmente, apenas numericamente).
5. Verificado o registro correspondente em `tb_atendimento`:
   `id_atendimento=2796`, `placa=TST0A01`, `pasta_documentos=2026-09-17/TST0A01_142344`,
   `criado_em=2026-09-17 14:23:44` (bate exatamente com o timestamp do
   nome da pasta). Campos `motorista_nome` e `motorista_cpf` de ambos
   **NULL** -- confirmado, por leitura direta (SELECT, sem nenhuma
   escrita), que NAO ha nenhum dado pessoal real preenchido nessa
   linha. Nenhuma outra tabela consultada alem desta leitura pontual.
6. Origem provavel: uma execucao anterior de teste (rodada de
   `/02-testes` independente ou `/03-revisao` do mesmo dia
   2026-09-17, as 14:23:44 -- horas antes da rodada de implementacao
   original registrada mais acima neste handoff, que ocorreu mais
   tarde no mesmo dia) que exercitou o fluxo real de upload de nota
   (via `NotaController`/`UploadHelper`, nao um teste isolado de
   `UploadHelper` puro) com a resolucao do Netum (3264x2448, igual a
   fixture do caso 3 da matriz), sem que a limpeza automatica de
   `storage/` dessa rodada tenha alcancado esse arquivo/pasta
   especifico -- inferencia razoavel por correlacao de timestamp e
   resolucao, nao certeza absoluta sobre qual rodada exata.

Remocao executada: apenas `nota_01.jpg` removido
(`rm -f` do arquivo exato, sem glob/recursao). Diretorio
`TST0A01_142344` ficou vazio apos a remocao e foi removido
(`rmdir`, diretorio vazio, sem `-r`). Confirmado apos a remocao:
`storage/atendimentos/2026-09-17/` continua existindo, agora vazia
(nao removida, pois nao era o alvo exato). Confirmado por
`find storage/atendimentos -type d -iname "*TST0A01*"` e
`find storage/atendimentos -type f -iname "*jpeg_seguro*"` /
`Glob **/TESTE_JPEG*` que NAO existe nenhum OUTRO residuo desta
demanda especifica (`validacao-jpeg-segura`) em `storage/` -- as
outras 33 pastas `TST0A01_*` encontradas sao de datas anteriores
(2026-09-04 a 2026-09-16), de outras demandas, fora de escopo desta
limpeza, e foram preservadas intactas.

**Correcao da alegacao anterior**: a alegacao repetida de "zero
residuo" nas secoes de `/01-implementacao`/`/02-testes` acima deste
handoff estava INCORRETA para este 1 arquivo especifico (confirmado
empiricamente pelo `/03-revisao`, e agora corrigido). Nao ha mais
duvida sobre a natureza do arquivo (sintetico, sem dado pessoal,
confirmado pelos 6 pontos acima) nem sobre a limpeza (removido e
confirmado ausente). A linha `id_atendimento=2796` em
`tb_atendimento` NAO foi removida do banco nesta rodada -- esta fora
do escopo autorizado (somente leitura no banco foi permitida; a linha
nao contem dado pessoal e nao representa risco de exposicao, apenas
resíduo de metadado de teste, registrado aqui para ciencia).

### Zero operacao real

Zero chamada real ao Talent/VIO, zero impressao, zero uso de
documento/imagem real, zero commit/push, zero escrita no banco real
(somente 1 leitura pontual para o item 5 da investigacao do residuo).
`.gitignore` nao alterado, `git add -f` nao usado, `util/UploadHelper.php`
nao tocado.


## Resultado dos testes -- /02-testes curta e independente (2026-09-17)

`qa-testes` (revisor independente, sem participacao na correcao)
reexecutou do zero, com ceticismo, os 2 pontos corrigidos na rodada
anterior.

### Reprodutibilidade -- CONFIRMADA

Arquivos novos existem, nomes antigos com `_` nao existem mais,
nenhum dos dois novos e ignorado pelo Git (`git check-ignore -v` sem
retorno), ambos aparecem como `??` normais em `git status`. Zero
referencia funcional aos nomes antigos (grep so retorna registro
historico no handoff/estado). `__DIR__` confirmado tanto no
`require_once` quanto no `exec()`.

### Arvore hermetica -- reproduzida de forma independente

Nova arvore isolada montada do zero pelo `qa-testes` (sem depender da
arvore ja removida da rodada anterior), executando o script a partir
de um diretorio corrente DIFERENTE do diretorio do script (teste mais
rigoroso que o da rodada anterior, confirmando de fato a
independencia de `__DIR__` em relacao ao cwd): **22/22 controles
obrigatorios PASSOU, 0 FALHOU**, 2 diagnosticos separados, caso de GD
indisponivel via `exec()` executado corretamente, zero erro de
`require`/`exec`/caminho, zero mencao a arquivo com prefixo `_`
antigo em toda a saida. Consumo de memoria identico ao ja registrado
(108,00 MB). Diretorio temporario removido integralmente ao final.

### Residuo -- confirmado ausente, sem dano colateral

`nota_01.jpg` e o diretorio `TST0A01_142344` confirmados ausentes.
`storage/atendimentos/2026-09-17/` preservada (vazia, nao removida
indevidamente). Nenhum outro residuo desta demanda encontrado. As 34
pastas `TST0A01_*` de outras datas/demandas continuam intactas
(confirmado que nao foram tocadas). As proprias execucoes desta
rodada de teste nao deixaram nada novo em `storage/`.

### Regressao

`teste_fluxo_recebimento_documentos.php`: 11/11 PASSOU.
`teste_e2e_recebimento_expedicao_mock.php`: 30/30 PASSOU.

### Higiene

`php -l` limpo nos 3 scripts JPEG + `UploadHelper.php`. `git diff --check`
limpo. `.gitignore` inalterado (`git diff .gitignore` vazio).
`util/UploadHelper.php` confirmado NAO tocado nesta rodada curta (mtime
anterior as demais mudancas, diff identico ao ja aprovado nos 20
pontos da rodada anterior). Nenhum residuo de log/backup/fixture.
Nenhum segredo/dado pessoal/base64 real/caminho de maquina. Risco
residual confirmado continuar documentado como aceito, pendencia
confirmada fora da lista ativa da secao 5.1.

### Veredito consolidado

**APROVADO -- liberado para `/03-revisao` curta de confirmacao.**


## Resultado da revisao -- /03-revisao curta de confirmacao (2026-09-17)

Dois revisores independentes (backend-especialista + security-especialista,
sem participacao nas correcoes) reproduziram do zero, com ceticismo,
os 4 pontos do escopo desta confirmacao.

### Reprodutibilidade -- CONFIRMADA (nova arvore hermetica propria)

`backend-especialista` montou sua PROPRIA arvore hermetica fora do
repositorio (independente da ja removida em rodadas anteriores),
executando `teste_validacao_jpeg_seguro.php` a partir de diretorio
corrente diferente do diretorio do script: **22/22 controles
obrigatorios PASSOU**, 2 diagnosticos separados, caso de GD
indisponivel executado corretamente, zero erro de caminho/require/exec,
zero acesso ao worktree original. Arvore removida ao final.
`fixtures_jpeg_seguro.php`/`caso_gd_indisponivel_mock.php` confirmados
NAO ignorados, nomes antigos ausentes, zero referencia funcional a
eles em qualquer lugar do codigo.

### Resíduo sintetico -- CONFIRMADO AUSENTE, sem dano colateral

Ambos os revisores confirmaram ausencia de `nota_01.jpg` e do
diretorio `TST0A01_142344`. As 34 pastas de outras
demandas/datas continuam intactas. Handoff confirmado sem alegacao
incorreta remanescente de "zero residuo" (secao "Correcao da
alegacao anterior" ja presente).

### Integridade do diff -- CONFIRMADA

`git status`/`git diff --stat` confirmam alteracao exatamente no
conjunto esperado (2 renomes + referencias + handoff +
`ia_development_state.md`, mais `util/UploadHelper.php` ja aprovado
em rodada anterior). `util/UploadHelper.php` confirmado intocado
nesta rodada curta (mtime anterior as mudancas de teste).
`.gitignore` inalterado, nenhum `git add -f` necessario. Risco
residual confirmado continuar aceito e fora da lista ativa 5.1,
nenhum parser proprio ou `gd.jpeg_ignore_warning` reintroduzidos
(confirmado por grep, unica ocorrencia e comentario historico).
`docs/deploy-checklist.md` confirmado inalterado nesta rodada.

### Testes -- reexecutados por ambos os revisores

JPEG: 22/22. `teste_fluxo_recebimento_documentos.php`: 11/11.
`teste_e2e_recebimento_expedicao_mock.php`: 30/30. 2 diagnosticos
conhecidos separados do contador de aprovacao. Zero regressao.

### Higiene e seguranca -- SEM ACHADO

`php -l` limpo nos 3 scripts JPEG. `git diff --check` limpo. Zero
segredo/dado pessoal/base64 real/caminho de maquina em todos os
arquivos do futuro commit. `composer.json`/`composer.lock`
inalterados -- zero dependencia nova.

### Veredito consolidado

**APROVADO -- liberado para `/04-commit-e-push`.**

## Trello

card_id: 6aac49f6059d69343f93e626

## Proximo passo

Rodar `/04-commit-e-push`.
