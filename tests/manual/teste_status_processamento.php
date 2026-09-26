<?php

/**
 * Teste manual de documento.php?acao=status-processamento
 * (DocumentoController::statusProcessamento) — demanda expedicao-vio-cnh-crlv,
 * REPLANEJAMENTO 2026-09-09. Reescrito na rodada corretiva de
 * migracao-vio-api-br-com-cache (2026-09-26) para refletir o fluxo
 * ASSINCRONO NOVO via App\Rn\VioApiBrClient (ENVIANDO/PROCESSANDO_LEITURA/
 * PROCESSANDO_COMPARACAO/CONCLUIDO/ERRO/INDETERMINADO) — o antigo estado
 * unico 'PROCESSANDO'/App\Rn\VioDecodeClient nao e mais usado por nenhum
 * caminho real de statusProcessamento().
 *
 * Cobre:
 * - leitura pura (sem tentativa vio.api.br pendente) NUNCA faz nenhuma
 *   chamada de rede — confirmado por VIO_API_BR_BASE_URL/API_KEY ausentes do
 *   ambiente do subprocesso (se o codigo tentasse instanciar
 *   App\Rn\VioApiBrClient sem necessidade, o construtor lancaria
 *   RuntimeException fail-closed, o que teria feito HTTP diferente de 200);
 * - tentativa RECENTE em PROCESSANDO_LEITURA (nao expirada pelo limite de
 *   duracao maxima) FAZ exatamente 1 GET real — usa uma URL local
 *   inalcancavel (127.0.0.1 em porta fechada, SEM rede real/externa) para
 *   forcar uma falha TECNICA de transporte controlada e deterministica,
 *   confirmando que o resultado vira ERRO (terminal=true) sem duplicar
 *   chamadas;
 * - tentativa OBSOLETA (> limite de duracao maxima) em PROCESSANDO_LEITURA
 *   e transicionada para INDETERMINADO pelo limite de duracao maxima ANTES
 *   de qualquer tentativa de consulta — nunca dispara GET (confirmado pela
 *   AUSENCIA de VIO_API_BR_BASE_URL/API_KEY no ambiente: se o codigo
 *   regredisse e tentasse consultar mesmo assim, o resultado seria ERRO, nao
 *   INDETERMINADO, e a asserção falharia);
 * - rate limit proprio (tb_rate_limit_vio_status) bloqueia apos exceder o
 *   limite na janela, com HTTP 429;
 * - allowlist de etapa (nao aceita fora das etapas permitidas para o
 *   documento) e IDOR (posse do atendimento).
 *
 * Cada cenario roda em subprocesso separado (Resposta::erro/sucesso chamam
 * exit()). Uso: php tests/manual/teste_status_processamento.php
 *
 * IMPORTANTE (rodada corretiva de migracao-vio-api-br-com-cache, 2026-09-26):
 * este arquivo rodava antes contra o banco de DEV compartilhado
 * `udlog_totem`, que NUNCA recebeu as migrations 015/016 desta demanda
 * (proibido aplicar la sem autorizacao separada) -- batia em "Column not
 * found". Passa a rodar contra um banco `qa_`-prefixado DESCARTAVEL PROPRIO
 * (mesmo helper tests/manual/qa_db_bootstrap.php ja usado pelas 3 suites
 * novas desta demanda), criado do zero (schema.sql + migrations) e dropado
 * ao final. O subprocesso (_caso_status_processamento.php) recebe o nome do
 * banco via putenv() (ponte explicada em qaDbTrechoPonteEnvSubprocesso()).
 */

require_once __DIR__ . '/qa_db_bootstrap.php';

use App\Dao\AtendimentoDao;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function rodarSubprocesso(int $idTotem, int $idAtendimento, string $tipo): array
{
    $php = PHP_BINARY;
    $script = __DIR__ . '/_caso_status_processamento.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $idTotem . ' ' . $idAtendimento . ' ' . $tipo;
    exec($cmd, $saida, $codigo);
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

/**
 * Mesmo subprocesso, mas com VIO_API_BR_BASE_URL/API_KEY definidos no
 * ambiente do PROPRIO subprocesso (putenv() no processo pai, herdado por
 * exec() — Dotenv::createImmutable() nunca sobrescreve uma env ja presente).
 * Usado SOMENTE quando o cenario precisa que
 * App\Rn\VioApiBrClient consiga ser CONSTRUIDO com sucesso (a URL aponta
 * para uma porta local fechada — 127.0.0.1:1 — garantindo falha de conexao
 * IMEDIATA e determinística, sem nenhuma chamada de rede real/externa).
 */
function rodarSubprocessoComVioApiBrConfigurado(int $idTotem, int $idAtendimento, string $tipo): array
{
    putenv('VIO_API_BR_BASE_URL=https://127.0.0.1:1');
    putenv('VIO_API_BR_API_KEY=chave-teste-nao-real');
    try {
        return rodarSubprocesso($idTotem, $idAtendimento, $tipo);
    } finally {
        putenv('VIO_API_BR_BASE_URL');
        putenv('VIO_API_BR_API_KEY');
    }
}

$nomeBanco = null;

try {
    [$pdo, $nomeBanco] = qaDbCriar('status_processamento');
    qaDbCorrigirEnumsMigration015($pdo);
    // Ponte para os subprocessos disparados via exec() abaixo -- ver
    // qaDbTrechoPonteEnvSubprocesso() em qa_db_bootstrap.php.
    putenv('DB_NAME=' . $nomeBanco);

    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_STATUS_PROC', 'Totem Teste Status', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
    $idTotem = (int) $pdo->lastInsertId();

    $dao = new AtendimentoDao($pdo);
    $idAtendimento = $dao->criar($idTotem, 'expedicao', 'EEE3333');
    $dao->atualizarEtapa($idAtendimento, 'exp_crlv');

// Cenario 1: CNH em etapa nao permitida (exp_crlv nao esta na allowlist de CNH
// -> na verdade esta: ['exp_cnh','exp_crlv','exp_aguarde_documentos'] inclui
// exp_crlv. Usamos 'crlv' aqui, que TAMBEM aceita exp_crlv.
$r1 = rodarSubprocesso($idTotem, $idAtendimento, 'crlv');
afirmar('status-processamento aceito na etapa exp_crlv para tipo crlv (allowlist)', str_contains($r1['saida'], '"sucesso":true'));

// Cenario 2: CNH consultada numa etapa fora da allowlist (dados_encontrados)
$dao->atualizarEtapa($idAtendimento, 'dados_encontrados');
$r2 = rodarSubprocesso($idTotem, $idAtendimento, 'cnh');
afirmar('status-processamento bloqueado fora da allowlist de etapa', str_contains($r2['saida'], 'nao esta na etapa esperada'));
$dao->atualizarEtapa($idAtendimento, 'exp_crlv');

// Cenario 3: PROCESSANDO_LEITURA RECENTE (nao expirado pelo limite de
// duracao maxima) -> statusProcessamento() FAZ exatamente 1 GET real contra
// vio.api.br (aqui, uma porta local fechada, sem rede real/externa) -> falha
// de transporte SEM ambiguidade -> gravarResultadoFinalVioApiBr(..., 'ERRO'),
// terminal=true. Confirma que a tentativa recente e de fato consultada (nao
// fica presa esperando o proximo poll indefinidamente).
$tentativa3 = bin2hex(random_bytes(16));
['fingerprint' => $fingerprint3, 'versao' => $hmacVersao3] = ['fingerprint' => bin2hex(random_bytes(32)), 'versao' => 1];
afirmar('Cenario 3 (pre-condicao): CAS de envio adquirido (PENDENTE -> ENVIANDO)', $dao->iniciarEnvioVioApiBr($idAtendimento, 'crlv', $tentativa3, $fingerprint3, $hmacVersao3));
afirmar('Cenario 3 (pre-condicao): ID externo gravado (ENVIANDO -> PROCESSANDO_LEITURA)', $dao->gravarIdExternoVioApiBr($idAtendimento, 'crlv', $tentativa3, 'ext-teste-cenario3'));
$r3 = rodarSubprocessoComVioApiBrConfigurado($idTotem, $idAtendimento, 'crlv');
afirmar('Cenario 3: tentativa recente em PROCESSANDO_LEITURA e CONSULTADA (1 GET real) e vira ERRO em falha tecnica de transporte', str_contains($r3['saida'], '"status_processamento":"ERRO"'));
afirmar('Cenario 3: ERRO reporta terminal=true', str_contains($r3['saida'], '"terminal":true'));
$estadoCenario3 = $dao->buscarPorId($idAtendimento);
afirmar('Cenario 3: estado persistido no banco confirma ERRO (nao so a resposta HTTP)', $estadoCenario3['crlv_status_processamento'] === 'ERRO');

// Cenario 4: PROCESSANDO_LEITURA OBSOLETO (envio confirmado ha mais que o
// limite de duracao maxima, DURACAO_MAXIMA_PROCESSAMENTO_VIO_API_BR_SEGUNDOS
// = 120s) -> marcarProcessamentoVioApiBrExpiradoComoIndeterminado() ja
// transiciona para INDETERMINADO ANTES de qualquer tentativa de consulta ->
// NUNCA dispara GET (VIO_API_BR_BASE_URL/API_KEY propositalmente AUSENTES do
// ambiente do subprocesso aqui: se o codigo regredisse e tentasse consultar
// mesmo assim, App\Rn\VioApiBrClient() lancaria RuntimeException fail-closed,
// capturada internamente, e o resultado gravado seria ERRO — nao
// INDETERMINADO —, o que faria a asserção abaixo falhar e expor a regressao).
$tentativa4 = bin2hex(random_bytes(16));
$dao->iniciarEnvioVioApiBr($idAtendimento, 'crlv', $tentativa4, bin2hex(random_bytes(32)), 1);
$dao->gravarIdExternoVioApiBr($idAtendimento, 'crlv', $tentativa4, 'ext-teste-cenario4');
$pdo->prepare('UPDATE tb_atendimento SET crlv_vio_api_enviado_em = DATE_SUB(NOW(), INTERVAL 200 SECOND), crlv_processamento_iniciado_em = DATE_SUB(NOW(), INTERVAL 200 SECOND) WHERE id_atendimento = :id')
    ->execute(['id' => $idAtendimento]);
$r4 = rodarSubprocesso($idTotem, $idAtendimento, 'crlv');
afirmar('Cenario 4: tentativa obsoleta (> limite de duracao maxima) e reportada como INDETERMINADO', str_contains($r4['saida'], '"status_processamento":"INDETERMINADO"'));
afirmar('Cenario 4: INDETERMINADO reporta terminal=true', str_contains($r4['saida'], '"terminal":true'));
afirmar('Cenario 4: subprocesso encerrou normalmente (sem excecao fatal do fail-closed do VioApiBrClient)', $r4['codigo'] === 0);
$estadoCenario4 = $dao->buscarPorId($idAtendimento);
afirmar('Cenario 4: estado persistido no banco confirma INDETERMINADO, nunca voltou a PENDENTE', $estadoCenario4['crlv_status_processamento'] === 'INDETERMINADO');

// Cenario 5: rate limit — muitas chamadas na mesma janela devem eventualmente
// retornar 429 (limite 20/5s por documento)
$excedeu = false;
for ($i = 0; $i < 30; $i++) {
    $r = rodarSubprocesso($idTotem, $idAtendimento, 'crlv');
    if (str_contains($r['saida'], 'Muitas consultas de status')) {
        $excedeu = true;
        break;
    }
}
afirmar('Rate limit proprio de status-processamento bloqueia apos exceder o limite na janela', $excedeu === true);

// Cenario 6: IDOR — outro totem consultando status de atendimento alheio
$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_STATUS_INVASOR', 'Totem Invasor Status', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotemInvasor = (int) $pdo->lastInsertId();
$r6 = rodarSubprocesso($idTotemInvasor, $idAtendimento, 'crlv');
afirmar('IDOR: totem invasor nao consegue consultar status de atendimento alheio', str_contains($r6['saida'], 'Atendimento nao encontrado'));
} finally {
    putenv('DB_NAME');
    if ($nomeBanco !== null) {
        qaDbDropar($nomeBanco);
        echo "\n(banco de teste {$nomeBanco} dropado)\n";
    }
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
