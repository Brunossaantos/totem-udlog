# Handoff — vio-crlv-exercicio-faixa-storage

## Objetivo

Garantir que `exercicio` do CRLV seja validado tambem contra a faixa
EFETIVAMENTE ARMAZENAVEL pela coluna `crlv_ano`, complementando a
demanda anterior `vio-hardening-sem-credenciais` (que corrigiu
overflow de `PHP_INT`, mas deixou registrado como pendencia nao
bloqueante que um valor tecnicamente valido em PHP -- ex.
`PHP_INT_MAX` -- ainda saturaria silenciosamente na coluna
`SMALLINT` do MySQL).

A aplicacao nao pode aceitar um valor que o banco posteriormente
sature, trunque, converta, ou rejeite apenas dependendo do
`sql_mode`.

## Fase 0 -- preflight (2026-09-20)

- `git fetch origin` executado, `HEAD == origin/main`
  (`a19f4e1fa56508efef9e358b58b10c1b31ebb598`), worktree limpo antes
  de iniciar.
- Lido `ia_development_state.md` (secao 5.1 e achados recentes de
  `vio-hardening-sem-credenciais`) e o handoff
  `docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md` por
  completo.
- **Tipo real de `crlv_ano` confirmado**: `sql/schema.sql:85` declara
  `crlv_ano SMALLINT NULL` -- sem `UNSIGNED`. Confirmado
  empiricamente em banco descartavel (schema.sql + todas as
  migrations aplicadas, banco destruido ao final, zero residuo):
  `COLUMN_TYPE = smallint(6)`, `IS_NULLABLE = YES`,
  `COLUMN_DEFAULT = NULL` -- **SMALLINT SIGNED**, faixa `-32768` a
  `32767`. Nenhuma migration altera o tipo desta coluna (confirmado
  por grep em todos os arquivos de `sql/migrations/`). Nenhuma
  divergencia entre migration/schema documentado/banco descartavel.
  (`crlv_snapshot_exercicio`, coluna de snapshot relacionada, e do
  mesmo tipo `smallint(6)` -- fora do escopo desta demanda, que trata
  apenas do campo ativo `crlv_ano` usado por `DocumentoRn::validarCrlv()`.)
- Como anos negativos ja sao rejeitados pela regra de negocio
  existente (`$exercicio > 0` em `avaliarCrlv()`), o limite MINIMO
  funcional desta correcao continua sendo o ja aprovado -- a nova
  faixa tecnica desta demanda so precisa complementar o limite
  MAXIMO (`32767`), sem duplicar a regra de sinal ja existente.

## Fase 1 -- Implementacao (2026-09-20)

Escopo restrito conforme instrucao: `app/Rn/DocumentoRn.php`,
`tests/manual/teste_vio_decode_matriz_tipos_campos.php`, este handoff,
`ia_development_state.md`. Nenhum outro arquivo alterado. Nenhuma
credencial obtida/usada, nenhuma chamada real ao VIO/Serpro/Talent,
nenhum documento/CPF/placa/QR/imagem real, `.env` intocado, zero
commit/push. Trello NAO tocado nesta rodada (outra etapa cuida disso).

### Constante e metodo novos

`app/Rn/DocumentoRn.php`:

```php
private const EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL = 32767;
```

Justificativa (documentada tambem na docstring da constante): origem
EXATA e `sql/schema.sql:85`, `crlv_ano SMALLINT NULL` sem `UNSIGNED` ->
SMALLINT SIGNED, faixa real `-32768..32767`, confirmada empiricamente
no preflight desta demanda (banco descartavel, destruido ao final,
`COLUMN_TYPE = smallint(6)`). So o teto MAXIMO foi codificado -- o piso
tecnico (`-32768`) e muito mais permissivo que a regra de negocio ja
existente e aprovada `$exercicio > 0` (`avaliarCrlv()`), tornando
redundante duplicar o piso (decisao explicita, nao omissao).

Novo metodo privado, chamado logo apos `validarExercicioInteiroExato()`
e ANTES de qualquer cast `(int)` usado para persistencia/envio ao DAO:

```php
private function validarExercicioDentroDaFaixaArmazenavel(string $documento, string $campo, int|string|null $valor): int|string|null
{
    if ($valor === null) {
        return null;
    }

    // Cast seguro so para comparacao -- $valor ja garantido caber em
    // PHP_INT pelo chamador (validarExercicioInteiroExato()); o valor
    // ORIGINAL (int ou string) e devolvido inalterado, nunca o cast.
    $valorParaComparacao = (int) $valor;

    if ($valorParaComparacao > self::EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL) {
        throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_fora_da_faixa_armazenavel_crlv_ano');
    }

    return $valor;
}
```

Chamada adicionada em `validarCrlv()`:

```php
$exercicioBruto = $this->extrairCampoNumerico($dadosBrutos, 'crlv', self::CAMPOS_PERMITIDOS_CRLV[1]);
$exercicioBruto = $this->validarExercicioInteiroExato('crlv', self::CAMPOS_PERMITIDOS_CRLV[1], $exercicioBruto);
$exercicioBruto = $this->validarExercicioDentroDaFaixaArmazenavel('crlv', self::CAMPOS_PERMITIDOS_CRLV[1], $exercicioBruto);
```

Reaproveita a mesma `DocumentoVioTipoInvalidoException`/fluxo
fail-closed ja usado pelas fronteiras de tipo/magnitude (capturada
dentro do mesmo `try/catch` de `validarCrlv()`, retorna
`falhaEstruturaInvalidaCrlv()`) -- sem estrategia paralela de
tratamento de erro. Neste ponto, o valor ja e garantido caber em
`PHP_INT` (por `caberEmPhpInt()`/`validarExercicioInteiroExato()`),
entao o cast `(int)` feito so para fins de COMPARACAO de faixa e seguro
e nao pode ele mesmo estourar/saturar; o valor ORIGINAL (int ou string)
e devolvido inalterado, nunca o cast.

### Ordem de validacao final (cumulativa)

1. tipo permitido (`extrairCampoNumerico()`);
2. formato de inteiro exato (`validarExercicioInteiroExato()`, regex);
3. magnitude compativel com `PHP_INT` (`caberEmPhpInt()`);
4. **NOVO**: faixa armazenavel de `crlv_ano` (`validarExercicioDentroDaFaixaArmazenavel()`, teto `32767`);
5. demais regras ja existentes (`$exercicio > 0` em `avaliarCrlv()`, placeholder, UF, placa, RNTC, tipo).

### Tabela de valores testados

| Valor (int e string, quando aplicavel) | Esperado | Resultado |
|---|---|---|
| `32767` | ACEITO, `crlv_ano` = 32767 exato | OK |
| `32768` | REJEITADO, zero persistencia | OK |
| `PHP_INT_MAX` (`9223372036854775807`) | REJEITADO, zero persistencia | OK |
| `(string) PHP_INT_MAX` | REJEITADO, zero persistencia | OK |
| `2026` (regressao) | ACEITO (comportamento inalterado) | OK |
| `"99999999999999999999"` (regressao) | REJEITADO (magnitude, achado da demanda anterior) | OK |
| `-2026`/`"-2026"` (regressao) | REJEITADO pela regra de FAIXA de negocio ja existente (`> 0`), nao pela fronteira nova | OK |

### Testes novos -- contagens exatas

`tests/manual/teste_vio_decode_matriz_tipos_campos.php`: ampliada de
**590 para 622 asserções, 622/622 passando, 0 falhas** (32 novas: 2
casos ACEITOS x 5 asserções + 4 casos REJEITADOS x 5 asserções, mais
ajuste do valor `918273 -> 12345` em um caso pre-existente que passaria
a ser incorretamente rejeitado pela nova fronteira, e reclassificacao
de `PHP_INT_MAX`/`(string) PHP_INT_MAX` do bloco "MAGNITUDE ACEITA"
para o novo bloco "FAIXA REJEITADA" -- mudanca de comportamento
intencional desta demanda).

### Prova de que `32768`/`PHP_INT_MAX` nao sao mais aprovados nem saturados

Confirmado via fluxo real (`DocumentoRn::validarCrlv()`, mock de
`VioDecodeClient::decodificar()`, banco de dev): com a validacao ativa,
os 4 casos fora da faixa resultam em `pode_avancar=false`,
`crlv_origem_validacao` permanece `NAO_VALIDADO`, `crlv_ano` nunca e
persistido (nem com o valor original nem saturado).

Confirmado tambem via script isolado (descartado ao final, nunca
commitado) que reproduz o comportamento SEM a correcao: com
`validarExercicioDentroDaFaixaArmazenavel()` temporariamente removida,
`exercicio=PHP_INT_MAX` resulta em `pode_avancar=true` e o MySQL satura
silenciosamente `crlv_ano` para `'32767'` -- exatamente o comportamento
que esta correcao elimina.

### Prova negativa obrigatoria (com hash)

1. `md5sum app/Rn/DocumentoRn.php` ANTES da mutacao:
   `13c027f0e7c50fee00530ca09fe95e2c`.
2. Chamada de `validarExercicioDentroDaFaixaArmazenavel()` removida
   temporariamente de `validarCrlv()` (comentario explicativo deixado
   no lugar, sem remover o metodo em si).
3. Suite reexecutada: **602/622 passaram, 20 falharam** -- exatamente
   os 20 asserções esperadas dos 4 casos "FAIXA REJEITADA" (`pode_avancar=false`
   esperado virou `true`; mensagem/origem/persistencia todos
   divergindo do esperado), confirmando deteccao real da regressao.
4. Script isolado adicional (fora da suite, scratchpad, descartado):
   confirmou `pode_avancar=true` e `crlv_ano` persistido como `'32767'`
   para `exercicio=PHP_INT_MAX` sem a validacao -- reproduzindo
   exatamente a saturacao do MySQL que a correcao elimina.
5. Revertido a 100% via copia de backup feita ANTES da mutacao.
6. `md5sum app/Rn/DocumentoRn.php` DEPOIS da reversao:
   `13c027f0e7c50fee00530ca09fe95e2c` -- **identico**.
7. Suite completa reexecutada apos a reversao: **622/622 limpo
   novamente**.

### Regressoes -- contagens exatas (todas batendo, nenhuma regrediu)

| Suite | Resultado |
|---|---|
| Matriz de tipos (`teste_vio_decode_matriz_tipos_campos.php`) | 622/622 (era 590/590) |
| `teste_vio_decode_robustez.php` | 80/80 |
| `teste_vio_decode.php` | 21/21 |
| `teste_rebaixamento_manual.php` | 47/47 |
| `teste_status_processamento.php` | 9/9 |
| `teste_concorrencia_processamento_vio.php` | 16/16 |
| `teste_avancar_etapa_expedicao.php` | 10/10 |
| `teste_fluxo_recebimento_documentos.php` | 11/11 |
| `teste_talent_uf_crlv.php` | 11/11 |
| `teste_talent_rntc_tipo_crlv.php` | 23/23 |
| `teste_validacao_jpeg_seguro.php` | 22/22 controles obrigatorios (formato de saida proprio, "PROVA NEGATIVA CONFIRMADA") |

### Arquivos alterados

- `app/Rn/DocumentoRn.php` (constante `EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL`
  + metodo `validarExercicioDentroDaFaixaArmazenavel()` + 1 chamada em
  `validarCrlv()`).
- `tests/manual/teste_vio_decode_matriz_tipos_campos.php` (novo bloco
  "FAIXA ARMAZENAVEL" com casos aceitos/rejeitados, ajuste do valor
  `918273 -> 12345` em caso pre-existente, sincronizacao de placa nos
  novos casos rejeitados para exercitar a persistencia real).
- Este handoff.
- `ia_development_state.md` (item de backlog marcado
  `~~riscado~~`/resolvido + novo log de mudanca em "7. Log de
  mudancas").

Nao alterados (confirmado): `VioDecodeClient.php`,
`DocumentoController.php`, migrations, schema, frontend, `.env`,
Composer.

### Confirmacao `TrelloClient.php`/`tools/trello-cli.php`

```
TrelloClient: 905278b642cbc32a42952b93ed7108ec959e28b3 (working == HEAD)
trello-cli:   6ba98753aa289c92af49cfcb5e93f6659ca706b0 (working == HEAD)
```

### Validacoes finais

- `php -l` em `app/Rn/DocumentoRn.php` e
  `tests/manual/teste_vio_decode_matriz_tipos_campos.php`: sem erros.
- `git diff --check`: sem problema de formatacao alem do aviso
  pre-existente de CRLF/LF (nao relacionado a este trabalho).
- Inspeccao integral do diff: nenhum numero magico solto (`32767`
  sempre via `self::EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL`, exceto em
  rotulos/comentarios de teste); nenhuma credencial/segredo/dado
  pessoal introduzido.
- Zero commit, zero push nesta etapa (aguardando `/02-testes`/
  `/03-revisao`).

### Avaliacao para liberacao de `/02-testes`

Trabalho concluido dentro do escopo restrito, com evidencia real de
execucao (fluxo real + prova negativa com hash + regressoes exatas).
Nenhum achado pendente conhecido nesta implementacao. **Avaliacao:
pronto para `/02-testes`.**

## Trello

card_id: `6ab0152e2a4dd7c9dc24d685`
URL: https://trello.com/c/zZD57dWd/2139-bruno-sistema-totem-faixa-de-armazenamento-do-exercicio-crlv
Lista: "Sprint Bruno - Fazendo [Semanal]"

Nota: durante a criacao, um cartao duplicado com erro de digitacao
no titulo (sem acento em "exercicio") foi criado por engano
(`6ab014e539fa06fbdd61dc52`) -- o CLI Trello nao suporta
arquivar/excluir cartao, entao NAO foi corrigido automaticamente;
um comentario explicativo foi deixado nesse cartao apontando para o
correto. Acao pendente do usuario: arquivar manualmente esse cartao
duplicado via UI do Trello.

## `/02-testes` independente (2026-09-20)

2 revisores independentes (qa-testes + security-especialista, novas
instancias, sem participacao na implementacao). Ambos APROVADOS.

**qa-testes**: 141 casos de fronteira testados pelo fluxo real (banco
descartavel dedicado), incluindo `-32768`/`32767`/`-32769`/`32768`/
`PHP_INT_MAX` como int e string -- confirmado que SOMENTE valores
representaveis pela coluna E permitidos pelas regras ja existentes
avancam (`32767` avanca; `-32768` nao avanca, mas pela regra de
negocio de sinal, nao pela fronteira nova). Atomicidade confirmada
(CRLV ja aprovado nao e sobrescrito por tentativa com `exercicio`
fora da faixa). Prova negativa em copia isolada: 121/141 (20 falhas
exatas) ao desativar a validacao, reversao confirmada por hash
identico. Todas as 11 regressoes reconfirmadas (622/622 + 10
suites), incluindo investigacao do flake intermitente em
`teste_status_processamento.php` (reproduzido 1x em 15 execucoes,
confirmado nao relacionado a `DocumentoRn.php` -- teste nao referencia
essa classe). Identificado e removido pelo orquestrador um residuo
de banco do proprio preflight desta demanda (`qa_preflight_crlv_ano_043372e8`,
de uma tentativa de script que falhou antes do DROP) -- nao
relacionado aos revisores.

**security-especialista**: revisao critica confirmando ordem exata
de validacao e seguranca do cast interno. **Teste de `sql_mode`
obrigatorio executado com evidencia real**: banco descartavel
dedicado, controle negativo confirmando que o MySQL puro REJEITA em
modo estrito (`ERROR 1264 Out of range`) e SATURA silenciosamente
para `32767` em modo nao estrito; e -- criticamente -- confirmado que
a APLICACAO rejeita `PHP_INT_MAX` de forma IDENTICA em ambos os
modos de SQL (fluxo real com conexoes configuradas com `sql_mode`
diferentes), provando que a protecao nunca depende do modo SQL da
sessao. Escopo confirmado limpo, Trello byte-identico a HEAD.

### Veredito consolidado: APROVADO

Liberado para `/03-revisao` final.

## `/03-revisao` final independente (2026-09-20)

Revisor independente (backend-especialista, nao participou da
implementacao nem da `/02-testes`). Nenhum arquivo alterado (mutacao
de prova negativa propria revertida com hash md5 identico).

Todos os 14 controles obrigatorios confirmados com evidencia real e
execucao propria: tipo real da coluna reconfirmado (`smallint(6)`
signed); limite centralizado em constante unica; ordem de validacao
correta; `32767` aceito e persistido exato; `32768`/`PHP_INT_MAX`
rejeitados (int e string); valores gigantes continuam rejeitados
(regressao); `sql_mode` estrito/nao-estrito sem diferenca funcional
na aplicacao (reproduzido com MySQL puro + fluxo real em 2 sessoes);
atomicidade confirmada (dado anterior preservado); fallback manual
intacto (byte-identico a HEAD); contrato inalterado; migrations/
schema intocados; Trello confirmado byte-identico a HEAD; escopo de
arquivos restrito exatamente aos 4 esperados.

Contagens finais reconfirmadas: 622/622, 80/80, 21/21, 47/47, 9/9,
16/16, 10/10, 11/11, 11/11, 23/23, 22/22 -- todas batendo exatamente.

**Achado NOVO, nao bloqueante, fora do escopo desta demanda**:
`preencherManualCrlv()` (entrada manual pelo operador) NAO passa
pela nova fronteira de faixa nem pela de magnitude -- usa so
`is_numeric() ? (int) : 0`, que tecnicamente permitiria um valor
fora da faixa armazenavel chegar ao DAO por essa via (dependendo so
da restricao do teclado do front-end, nao auditada). Confirmado
byte-identico ao comportamento anterior a esta demanda (nao e
regressao, e gap pre-existente). Registrado para decisao futura,
nao implementado.

### VEREDITO FINAL: APROVADO

Demanda pronta para `/04-commit-e-push`.

## Trello

card_id: `6ab0152e2a4dd7c9dc24d685` -- comentario final sera
adicionado apos confirmacao do push; cartao sera movido para
"Sprint - Feito" somente apos `HEAD == origin/main` confirmado.

## Commit

Hash funcional (codigo/testes): `761c0ffc962996540759038efee7c2f8c4de8772`
Mensagem: `fix(vio): limita exercicio CRLV a faixa de armazenamento`
Autor/Committer: Bruno Santos <brunossaantos@gmail.com>
2 arquivos alterados (`app/Rn/DocumentoRn.php`,
`tests/manual/teste_vio_decode_matriz_tipos_campos.php`).

Confirmado antes do commit: `HEAD` identico a `origin/main`; `php -l`
limpo; `git diff --cached --check` limpo; lista de arquivos exatamente
os 2 autorizados; migrations/schema/`.env`/composer/Trello
confirmados intocados (`git hash-object` byte-identico a `HEAD` para
`TrelloClient.php`/`tools/trello-cli.php`); nenhuma
credencial/atribuicao de IA.
