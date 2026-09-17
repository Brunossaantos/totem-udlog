<?php

/**
 * Roteiro da rodada CURTA de /02-testes (2026-09-17) da demanda
 * integridade-conclusao-atendimento — valida o fechamento do Achado 1 da
 * rodada anterior: PDOException agora tratada em torno de
 * verificarRateLimit()/RateLimitOcrDao::incrementarEContar() dentro de
 * NotaController::identificarCliente().
 *
 * Fixtures 100% isoladas e descartaveis, marcador exclusivo desta rodada
 * (RTL0917 — distinto de QA0217/ITG usados em rodadas anteriores). Limpeza
 * por DELETE explicito ao final, incluindo tb_rate_limit_ocr. NUNCA chama
 * Talent real, NUNCA imprime de verdade, NUNCA altera app/util.
 *
 * Uso: php tests/manual/teste_rate_limit_identificar_cliente_pdo.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';
require_once __DIR__ . '/_fixtures_identificar_cliente.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function dispararSequencial(string $script, array $args): string
{
    $php = PHP_BINARY;
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php, $script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    return $saida;
}

function dispararComOpcoes(array $opcoesPhp, string $script, array $args): string
{
    $php = PHP_BINARY;
    $flags = [];
    foreach ($opcoesPhp as $chave => $valor) {
        $flags[] = '-d';
        $flags[] = "{$chave}={$valor}";
    }
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php], $flags, [$script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    return $saida;
}

/**
 * Correcao do falso positivo do Item 5 (ver handoff desta rodada): dispara o
 * subprocesso com stdout e stderr capturados em PIPES SEPARADOS (nunca
 * concatenados num unico buffer) e com error_log/log_errors configurados via
 * -d para um ARQUIVO DEDICADO e isolado, permitindo inspecionar tambem o
 * conteudo desse arquivo apos a execucao.
 *
 * Retorna ['stdout' => ..., 'stderr' => ..., 'log' => conteudo do arquivo de
 * log dedicado (string vazia se o arquivo nao chegou a ser criado)].
 *
 * Confirmado empiricamente neste ambiente (php CLI on Windows/XAMPP, sem
 * override de -d): error_log() SEM -d error_log=... cai em STDERR, porque o
 * caminho default do php.ini (C:\xampp\php\logs\php_error_log) aponta para
 * um diretorio que NAO EXISTE neste ambiente — o PHP falha silenciosamente
 * ao escrever no arquivo e usa STDERR como fallback. Com -d error_log=<path>
 * explicito (o que esta funcao sempre faz), a escrita vai integralmente para
 * o arquivo indicado e stdout/stderr do processo ficam limpos.
 */
function dispararComLogDedicado(array $opcoesPhp, string $script, array $args, string $arquivoLog): array
{
    @unlink($arquivoLog);
    $opcoesPhp = array_merge($opcoesPhp, [
        'log_errors' => '1',
        'error_log' => $arquivoLog,
    ]);
    $php = PHP_BINARY;
    $flags = [];
    foreach ($opcoesPhp as $chave => $valor) {
        $flags[] = '-d';
        $flags[] = "{$chave}={$valor}";
    }
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php], $flags, [$script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    $conteudoLog = file_exists($arquivoLog) ? file_get_contents($arquivoLog) : '';
    @unlink($arquivoLog);
    return ['stdout' => $stdout, 'stderr' => $stderr, 'log' => $conteudoLog];
}

/**
 * Conjunto unico de marcadores sensiveis (SQL, caminho local, stack trace,
 * credencial, host do banco, CPF sintetico padrao do projeto, placa
 * sintetica desta suite) verificado da mesma forma em qualquer um dos 4
 * fluxos (resposta HTTP/stdout, stderr, arquivo de log) — nunca aceitavel em
 * NENHUM deles. Deliberadamente NAO inclui o literal 'PDOException' nem o
 * padrao de SQLSTATE, que sao avaliados a parte (permitidos apenas no
 * destino de log tecnico, nunca na resposta ao totem).
 */
function contemMarcadorSensivelComum(string $conteudo): bool
{
    if (preg_match('/SELECT|INSERT INTO|UPDATE tb_|FROM tb_/i', $conteudo)) {
        return true;
    }
    if (str_contains($conteudo, __DIR__) || str_contains($conteudo, '.php on line') || str_contains($conteudo, 'Stack trace')) {
        return true;
    }
    if (str_contains($conteudo, (string) ($_ENV['DB_USER'] ?? "\0")) || str_contains($conteudo, (string) ($_ENV['DB_PASS'] ?? "\0"))) {
        return true;
    }
    if (str_contains($conteudo, (string) ($_ENV['DB_HOST'] ?? "\0"))) {
        return true;
    }
    if (str_contains($conteudo, '11144477735')) { // CPF sintetico padrao do projeto (ver _fixtures_talent.php)
        return true;
    }
    if (str_contains($conteudo, PLACA_TESTE)) { // placa sintetica desta suite (ver _fixtures_identificar_cliente.php)
        return true;
    }
    return false;
}

$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);
$script = __DIR__ . '/_caso_identificar_cliente_rate_limit.php';

$idsAtendimentoLimpar = [];
$idsTotemLimpar = [];

function contarLinhasRateLimit(PDO $pdo, int $idTotem): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tb_rate_limit_ocr WHERE id_totem = :id');
    $stmt->execute(['id' => $idTotem]);
    return (int) $stmt->fetchColumn();
}

function contadorRateLimit(PDO $pdo, int $idTotem, int $janela): ?int
{
    $stmt = $pdo->prepare('SELECT contador FROM tb_rate_limit_ocr WHERE id_totem = :id AND janela = :janela');
    $stmt->execute(['id' => $idTotem, 'janela' => $janela]);
    $valor = $stmt->fetchColumn();
    return $valor === false ? null : (int) $valor;
}

// ============================================================
// Item 1 — rate limit funcionando normalmente (fluxo sem excecao):
// identificarCliente() se comporta exatamente como antes da correcao.
// ============================================================
echo "\n=== Item 1: fluxo normal (sem excecao) ===\n";
$idTotem1 = talentCriarTotemComEmpresa($pdo, 'RTL0917_T1_' . bin2hex(random_bytes(3)), 1);
$idsTotemLimpar[] = $idTotem1;
$id1 = criarAtendimentoTeste($pdo, $idTotem1);
$idsAtendimentoLimpar[] = $id1;
$nota1 = criarNotaTeste($pdo, $id1, 1);

$antesRl1 = contarLinhasRateLimit($pdo, $idTotem1);
$saida1 = dispararSequencial($script, ['normal', (string) $idTotem1, (string) $id1, '1']);
echo $saida1 . "\n";
$depoisRl1 = contarLinhasRateLimit($pdo, $idTotem1);
$notaStatus1 = $pdo->prepare('SELECT status_ocr FROM tb_atendimento_nota WHERE id_nota = :id');
$notaStatus1->execute(['id' => $nota1]);
$statusOcr1 = $notaStatus1->fetchColumn();

afirmar('Item 1: HTTP 200 (sucesso":true, sem HTTP_CODE de erro)', str_contains($saida1, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida1));
afirmar('Item 1: linha de rate limit criada (contador incrementado normalmente)', $depoisRl1 === $antesRl1 + 1);
afirmar('Item 1: identificacao de cliente rodou de fato (status_ocr saiu de PENDENTE)', $statusOcr1 !== 'PENDENTE' && $statusOcr1 !== false);

// ============================================================
// Item 2 — limite ainda nao atingido: requisicao prossegue normalmente,
// chega a chamar a logica de OCR/identificacao da Rn (2a chamada, mesmo
// totem, ainda bem abaixo do limite de 30).
// ============================================================
echo "\n=== Item 2: limite ainda nao atingido (2a chamada, mesmo totem) ===\n";
$id2 = criarAtendimentoTeste($pdo, $idTotem1);
$idsAtendimentoLimpar[] = $id2;
$nota2 = criarNotaTeste($pdo, $id2, 1);

$saida2 = dispararSequencial($script, ['normal', (string) $idTotem1, (string) $id2, '1']);
echo $saida2 . "\n";
$notaStatus2 = $pdo->prepare('SELECT status_ocr FROM tb_atendimento_nota WHERE id_nota = :id');
$notaStatus2->execute(['id' => $nota2]);
$statusOcr2 = $notaStatus2->fetchColumn();

afirmar('Item 2: HTTP 200 (limite nao atingido, requisicao prossegue)', str_contains($saida2, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida2));
afirmar('Item 2: chegou a chamar a logica de identificacao (status_ocr saiu de PENDENTE)', $statusOcr2 !== 'PENDENTE' && $statusOcr2 !== false);

// ============================================================
// Item 3 — limite atingido: resposta HTTP 429 + Retry-After preservada
// exatamente como antes desta correcao (totem dedicado, janela atual
// pre-semeada com contador=30, a 31a chamada estoura o limite).
// ============================================================
echo "\n=== Item 3: limite atingido (HTTP 429 + Retry-After) ===\n";
$idTotem3 = talentCriarTotemComEmpresa($pdo, 'RTL0917_T3_' . bin2hex(random_bytes(3)), 1);
$idsTotemLimpar[] = $idTotem3;
$id3 = criarAtendimentoTeste($pdo, $idTotem3);
$idsAtendimentoLimpar[] = $id3;
criarNotaTeste($pdo, $id3, 1);

$janelaAtual3 = intdiv(time(), 60);
$pdo->prepare('INSERT INTO tb_rate_limit_ocr (id_totem, janela, contador) VALUES (:id, :janela, 30)')
    ->execute(['id' => $idTotem3, 'janela' => $janelaAtual3]);

$saida3 = dispararSequencial($script, ['normal', (string) $idTotem3, (string) $id3, '1']);
echo $saida3 . "\n";

afirmar('Item 3: HTTP 429', str_contains($saida3, 'HTTP_CODE:429'));
// NOTA: headers_list()/header() nao tem efeito observavel no SAPI CLI do
// PHP (confirmado empiricamente: header() + headers_list() em shutdown
// retorna array vazio mesmo com header() chamado antes) — limitacao do
// AMBIENTE de teste (mesma limitacao se aplicaria a qualquer teste deste
// projeto que quisesse observar o header Retry-After via CLI direto, nao
// introduzida por esta correcao). Verificacao equivalente feita por
// leitura estatica do codigo-fonte, nao alterado por esta rodada:
// NotaController.php chama header('Retry-After: ' . $segundosRestantes)
// imediatamente antes de Resposta::erro(..., 429) dentro de
// verificarRateLimit() — mesma linha/logica de antes desta correcao (o
// try/catch novo so envolve a CHAMADA a verificarRateLimit(), nao o corpo
// do metodo).
$codigoFonteController = file_get_contents(__DIR__ . '/../../app/Controller/NotaController.php');
afirmar('Item 3: codigo-fonte ainda emite header Retry-After antes do 429 (verificacao estatica, header() nao observavel em CLI)', (bool) preg_match("/header\\('Retry-After: '.*?\\);\\s*\\n\\s*Resposta::erro\\([^,]+,\\s*429\\)/s", $codigoFonteController));
$contadorPos3 = contadorRateLimit($pdo, $idTotem3, $janelaAtual3);
afirmar('Item 3: contador incrementado para 31 (a chamada que estourou foi contabilizada, comportamento ja existente)', $contadorPos3 === 31);

// ============================================================
// Item 4 — PDOException real dentro de incrementarEContar() (conexao
// derrubada via KILL CONNECTION_ID()) -> HTTP 500 com mensagem generica.
// ============================================================
echo "\n=== Item 4: PDOException real no rate limit -> HTTP 500 generico ===\n";
$idTotem4 = talentCriarTotemComEmpresa($pdo, 'RTL0917_T4_' . bin2hex(random_bytes(3)), 1);
$idsTotemLimpar[] = $idTotem4;
$id4 = criarAtendimentoTeste($pdo, $idTotem4);
$idsAtendimentoLimpar[] = $id4;
$nota4 = criarNotaTeste($pdo, $id4, 1);

$antesRl4 = contarLinhasRateLimit($pdo, $idTotem4);
$saida4 = dispararSequencial($script, ['pdo_falha', (string) $idTotem4, (string) $id4, '1']);
echo $saida4 . "\n";
$depoisRl4 = contarLinhasRateLimit($pdo, $idTotem4);
$notaStatus4 = $pdo->prepare('SELECT status_ocr FROM tb_atendimento_nota WHERE id_nota = :id');
$notaStatus4->execute(['id' => $nota4]);
$statusOcr4 = $notaStatus4->fetchColumn();

afirmar('Item 4: HTTP 500', str_contains($saida4, 'HTTP_CODE:500'));
afirmar('Item 4: mensagem generica fixa ("Nao foi possivel identificar o cliente")', str_contains($saida4, 'Nao foi possivel identificar o cliente') || str_contains($saida4, 'Não foi possível identificar o cliente'));

// ============================================================
// Item 5 — resposta sanitizada mesmo com display_errors=1 (SQL/stack
// trace/caminho de servidor/credencial ausentes). CORRIGIDO nesta rodada: os
// 4 fluxos (resposta HTTP/stdout, stderr, arquivo de log dedicado) sao
// avaliados SEPARADAMENTE — a versao anterior misturava stdout+stderr num
// unico buffer e checava ausencia do literal 'PDOException' nesse buffer
// combinado, gerando falso positivo (o log de servidor inclui esse rotulo de
// proposito, via NotaController::logFalhaBancoPdo(); e legitimo la, nunca na
// resposta ao totem). Ver comentario de dispararComLogDedicado() para o
// destino real de error_log() confirmado empiricamente neste ambiente.
// ============================================================
echo "\n=== Item 5: resposta sanitizada com display_errors=1 (fluxos separados) ===\n";
$idTotem5 = talentCriarTotemComEmpresa($pdo, 'RTL0917_T5_' . bin2hex(random_bytes(3)), 1);
$idsTotemLimpar[] = $idTotem5;
$id5 = criarAtendimentoTeste($pdo, $idTotem5);
$idsAtendimentoLimpar[] = $id5;
criarNotaTeste($pdo, $id5, 1);

// Nao usa sys_get_temp_dir(): neste ambiente ele resolve para um caminho com
// nome curto do Windows (ex. C:\Users\BRUNO~1.CAR\...) e o '~' quebra o
// parser de argumentos '-d' do PHP CLI via proc_open (confirmado
// empiricamente — o comando falha com "syntax error, unexpected '~'").
// Usa um arquivo temporario dentro de tests/manual/ (sem tilde), removido ao
// final desta secao.
$arquivoLog5 = __DIR__ . '/rtl0917_item5_' . bin2hex(random_bytes(4)) . '.log';
$resultado5 = dispararComLogDedicado(
    ['display_errors' => '1', 'error_reporting' => 'E_ALL', 'html_errors' => '0'],
    $script,
    ['pdo_falha', (string) $idTotem5, (string) $id5, '1'],
    $arquivoLog5
);
$stdout5 = $resultado5['stdout'];
$stderr5 = $resultado5['stderr'];
$log5 = $resultado5['log'];
echo "--- stdout ---\n{$stdout5}\n--- stderr ---\n{$stderr5}\n--- arquivo de log dedicado ---\n{$log5}\n";

afirmar('Item 5: HTTP 500 mesmo com display_errors=1 (resposta HTTP/stdout)', str_contains($stdout5, 'HTTP_CODE:500'));

afirmar(
    'Item 5: resposta HTTP/stdout nunca vaza SQL/caminho/stack trace/credencial/host, e NUNCA contem o literal PDOException (fluxo avaliado isoladamente, sem misturar com stderr/log)',
    !contemMarcadorSensivelComum($stdout5) && !str_contains($stdout5, 'PDOException')
);

afirmar(
    'Item 5: stderr do processo esta limpo — mesmos marcadores proibidos do stdout, incluindo o literal PDOException (destino real de error_log() confirmado como arquivo dedicado, nao stderr, neste cenario)',
    !contemMarcadorSensivelComum($stderr5) && !str_contains($stderr5, 'PDOException') && trim($stderr5) === ''
);

afirmar(
    'Item 5: arquivo de log dedicado contem SOMENTE o esperado — contexto fixo "identificarCliente (rate limit)", o rotulo (PDOException) e um SQLSTATE sanitizado de 5 caracteres alfanumericos — nunca SQL/caminho/stack trace/credencial/host/CPF/placa',
    str_contains($log5, 'identificarCliente (rate limit): falha de banco (PDOException)')
    && (bool) preg_match('/\[SQLSTATE=[A-Z0-9]{5}\]/', $log5)
    && !contemMarcadorSensivelComum($log5)
);

afirmar(
    'Item 5: nenhum dos 5 marcadores sinteticos sensiveis (SQL, caminho local, CPF, placa, credencial) aparece em NENHUM dos 3 fluxos observaveis (stdout/stderr/log)',
    !contemMarcadorSensivelComum($stdout5) && !contemMarcadorSensivelComum($stderr5) && !contemMarcadorSensivelComum($log5)
);

// ============================================================
// Item 6 — OCR/identificacao de cliente NAO e iniciado apos a falha do
// rate limit: nota4/nota5 permanecem com status_ocr=PENDENTE (prova
// direta de que NotaFiscalRn::identificarCliente() nunca rodou — ela
// SEMPRE grava um status final via atualizarResultadoOcr, exceto no
// short-circuit de "ja identificado", inaplicavel aqui pois a nota eh
// nova).
// ============================================================
echo "\n=== Item 6: OCR nao iniciado apos falha do rate limit ===\n";
afirmar('Item 6: nota do cenario do Item 4 permanece com status_ocr=PENDENTE (RN de identificacao nunca rodou)', $statusOcr4 === 'PENDENTE');

$nota5status = $pdo->prepare('SELECT status_ocr FROM tb_atendimento_nota WHERE id_atendimento = :id');
$nota5status->execute(['id' => $id5]);
$statusOcr5 = $nota5status->fetchColumn();
afirmar('Item 6: nota do cenario do Item 5 permanece com status_ocr=PENDENTE (RN de identificacao nunca rodou)', $statusOcr5 === 'PENDENTE');

// ============================================================
// Item 7 — nenhuma dupla contabilizacao do rate limit: a conexao
// derrubada falha na PRIMEIRA query (INSERT ... ON DUPLICATE KEY UPDATE),
// entao nenhuma linha chega a ser gravada para os totens 4/5 — confirma
// que nao houve incremento (nem simples nem duplicado) nesses cenarios de
// falha, e que o catch nao tenta uma segunda chamada a incrementarEContar().
// ============================================================
echo "\n=== Item 7: sem dupla contabilizacao do rate limit em falha ===\n";
afirmar('Item 7: nenhuma linha de rate limit gravada para o totem do Item 4 (INSERT falhou antes de commitar)', $depoisRl4 === $antesRl4 && $depoisRl4 === 0);
$linhasRl5 = contarLinhasRateLimit($pdo, $idTotem5);
afirmar('Item 7: nenhuma linha de rate limit gravada para o totem do Item 5 (INSERT falhou antes de commitar)', $linhasRl5 === 0);

// ============================================================
// Item 8 — regressao: reexecuta os metodos ja protegidos do
// NotaController (processar, definirNumero, algumaIdentificada) via os
// scripts ja existentes desta demanda.
// ============================================================
echo "\n=== Item 8: regressao dos metodos ja protegidos do NotaController ===\n";
$idTotem8 = talentCriarTotemComEmpresa($pdo, 'RTL0917_T8_' . bin2hex(random_bytes(3)), 1);
$idsTotemLimpar[] = $idTotem8;

$id8a = criarAtendimentoTeste($pdo, $idTotem8);
$idsAtendimentoLimpar[] = $id8a;
$saida8a = dispararSequencial(__DIR__ . '/_caso_nota_pdo_falha.php', ['algumaIdentificada', (string) $idTotem8, (string) $id8a]);
echo "--- algumaIdentificada (PDO falha) ---\n{$saida8a}\n";
afirmar('Item 8a: algumaIdentificada() ainda responde HTTP 500 generico em PDOException (sem regressao)', str_contains($saida8a, 'HTTP_CODE:500') && str_contains($saida8a, 'Nao foi possivel consultar a identificacao do cliente'));

$id8b = criarAtendimentoTeste($pdo, $idTotem8);
$idsAtendimentoLimpar[] = $id8b;
$saida8b = dispararSequencial(__DIR__ . '/_caso_nota_pdo_falha.php', ['processar', (string) $idTotem8, (string) $id8b, '1']);
echo "--- processar (PDO falha) ---\n{$saida8b}\n";
afirmar('Item 8b: processar() ainda responde HTTP 500 generico em PDOException (sem regressao)', str_contains($saida8b, 'HTTP_CODE:500') && str_contains($saida8b, 'Nao foi possivel registrar a nota'));

$id8c = criarAtendimentoTeste($pdo, $idTotem8);
$idsAtendimentoLimpar[] = $id8c;
criarNotaTeste($pdo, $id8c, 1);
$saida8c = dispararSequencial(__DIR__ . '/_caso_nota_definir_numero.php', [(string) $idTotem8, (string) $id8c, '1', '12345', 'MANUAL']);
echo "--- definirNumero (caminho normal) ---\n{$saida8c}\n";
afirmar('Item 8c: definirNumero() sem regressao (script pre-existente reexecutado)', !preg_match('/HTTP_CODE:5\d\d/', $saida8c));

// ============================================================
// Limpeza — remove TODOS os residuos criados por este arquivo
// ============================================================
foreach (array_unique($idsAtendimentoLimpar) as $id) {
    $pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
foreach (array_unique($idsTotemLimpar) as $idTotem) {
    $pdo->prepare('DELETE FROM tb_rate_limit_ocr WHERE id_totem = :id')->execute(['id' => $idTotem]);
    $pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);
}

$totalAtendimentosResiduais = (int) $pdo->query("SELECT COUNT(*) FROM tb_atendimento WHERE placa = 'TSTQA01'")->fetchColumn();
$totalTotemResidual = 0;
foreach (array_unique($idsTotemLimpar) as $idTotem) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tb_totem WHERE id_totem = :id');
    $stmt->execute(['id' => $idTotem]);
    $totalTotemResidual += (int) $stmt->fetchColumn();
}
$totalRateLimitResidual = 0;
foreach (array_unique($idsTotemLimpar) as $idTotem) {
    $totalRateLimitResidual += contarLinhasRateLimit($pdo, $idTotem);
}

afirmar('Limpeza: totens de teste (RTL0917_*) removidos do banco', $totalTotemResidual === 0);
afirmar('Limpeza: nenhuma linha residual em tb_rate_limit_ocr para os totens desta rodada', $totalRateLimitResidual === 0);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
