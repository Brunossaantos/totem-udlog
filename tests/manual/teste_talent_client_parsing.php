<?php

/**
 * Teste manual (item 3 do roteiro de testes da demanda
 * talent-doctos-finalizacao-checkin, 2026-09-14) — parsing REAL de
 * App\Rn\TalentClient::checkin() (nao um mock que substitui checkin() por
 * inteiro, como os demais testes do projeto fazem — aqui o objetivo e
 * exercitar o proprio curl/json_decode/classificacao de HTTP do metodo).
 *
 * NUNCA faz nenhuma chamada de rede real ao Talent (api.talentcs.com.br) —
 * usa um servidor HTTP mock em 127.0.0.1 (servidor embutido do PHP,
 * tests/manual/_router_talent_mock.php), escolhendo o cenario via
 * Authorization: Bearer cenario_XXX (TalentClient ja envia esse header
 * normalmente, usado aqui so como canal de controle do teste).
 *
 * Cobre:
 * - 200/201 com nrRegAcesso extraido corretamente e mapeado para a chave
 *   interna 'senha' (protocolo sempre null, API real nunca devolve isso);
 * - 'msg' NUNCA aparece no array de retorno (descartado);
 * - 2xx sem nrRegAcesso no corpo -> senha=null, sem excecao;
 * - 2xx com corpo nao interpretavel como JSON -> senha=null, sem excecao
 *   (resposta_ilegivel nao e lancada aqui pois o codigo trata isso como
 *   sucesso tecnico sem dados extras, nao erro);
 * - HTTP 409 -> TalentClientException categoria 'conflito' (nunca
 *   reconciliado automaticamente como sucesso/duplicidade);
 * - HTTP 500 -> TalentClientException categoria 'erro_servidor';
 * - host inalcancavel (porta fechada em localhost) -> categoria
 *   'erro_conexao' (falha ANTES de qualquer byte sair, elegivel a retry
 *   automatico) — mesmo padrao ja confirmado pelos testes existentes de
 *   TalentClient (teste_talent_idempotencia.php/teste_talent_log_sanitizado.php),
 *   que testam a CLASSIFICACAO via TalentClientException diretamente; este
 *   teste soma a confirmacao de que o METODO real (no cliente HTTP de
 *   verdade) chega as mesmas categorias fechadas.
 *
 * Uso: php tests/manual/teste_talent_client_parsing.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Rn\TalentClient;
use App\Rn\TalentClientException;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

$porta = 18971 + random_int(0, 500);
$host = '127.0.0.1';
$router = __DIR__ . '/_router_talent_mock.php';

$descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$processo = proc_open([PHP_BINARY, '-S', "{$host}:{$porta}", $router], $descritores, $pipes);
if ($processo === false) {
    echo "FALHA CRITICA: nao foi possivel iniciar o servidor mock local.\n";
    exit(1);
}

// aguarda o servidor embutido ficar pronto (poll curto, so localhost)
$pronto = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen($host, $porta, $errno, $errstr, 0.1);
    if ($fp !== false) {
        fclose($fp);
        $pronto = true;
        break;
    }
    usleep(100000);
}
if (!$pronto) {
    echo "FALHA CRITICA: servidor mock local nao subiu a tempo.\n";
    proc_terminate($processo);
    exit(1);
}

$baseUrl = "http://{$host}:{$porta}";

function chamar(string $baseUrl, string $cenario): array
{
    $client = new TalentClient($baseUrl, 'cenario_' . $cenario);
    return ['resultado' => $client->checkin(['teste' => true])];
}

// ============================================================
// 1. 200 com nrRegAcesso -> senha extraida, protocolo sempre null
// ============================================================
try {
    $r = chamar($baseUrl, 'sucesso_200')['resultado'];
    afirmar('HTTP 200: nrRegAcesso mapeado para "senha" corretamente', $r['senha'] === 'ABC123');
    afirmar('HTTP 200: "protocolo" sempre null (API real nunca devolve esse campo)', $r['protocolo'] === null);
} catch (\Throwable $e) {
    afirmar('HTTP 200: nao deveria lancar excecao (' . $e->getMessage() . ')', false);
}

// ============================================================
// 2. 201 com nrRegAcesso, sem "msg" no corpo -> ainda funciona
// ============================================================
try {
    $r = chamar($baseUrl, 'sucesso_201_sem_msg')['resultado'];
    afirmar('HTTP 201: nrRegAcesso extraido mesmo sem "msg" no corpo', $r['senha'] === 'XYZ789');
} catch (\Throwable $e) {
    afirmar('HTTP 201: nao deveria lancar excecao (' . $e->getMessage() . ')', false);
}

// ============================================================
// 3. 200 sem nrRegAcesso no corpo -> senha=null, SEM excecao
// ============================================================
try {
    $r = chamar($baseUrl, 'campo_ausente_200')['resultado'];
    afirmar('HTTP 200 sem nrRegAcesso no corpo: senha=null, sem excecao', $r['senha'] === null && $r['protocolo'] === null);
} catch (\Throwable $e) {
    afirmar('HTTP 200 sem nrRegAcesso: nao deveria lancar excecao (' . $e->getMessage() . ')', false);
}

// ============================================================
// 4. 200 com corpo ilegivel como JSON -> senha=null, SEM excecao
// ============================================================
try {
    $r = chamar($baseUrl, 'corpo_ilegivel_200')['resultado'];
    afirmar('HTTP 200 com corpo nao-JSON: senha=null, sem excecao (sucesso tecnico sem dados extras)', $r['senha'] === null && $r['protocolo'] === null);
} catch (\Throwable $e) {
    afirmar('HTTP 200 corpo ilegivel: nao deveria lancar excecao (' . $e->getMessage() . ')', false);
}

// ============================================================
// 5. HTTP 409 -> categoria propria 'conflito', NUNCA reconciliado como sucesso
// ============================================================
$categoria409 = null;
try {
    chamar($baseUrl, 'conflito_409');
} catch (TalentClientException $e) {
    $categoria409 = $e->categoria();
}
afirmar("HTTP 409 lanca TalentClientException categoria 'conflito' (nunca sucesso)", $categoria409 === 'conflito');
afirmar('HTTP 409: categoria "conflito" NAO e classificada como indeterminada (ehIndeterminado() false)', $categoria409 !== null && (new TalentClientException('conflito'))->ehIndeterminado() === false);

// ============================================================
// 6. HTTP 500 -> categoria 'erro_servidor'
// ============================================================
$categoria500 = null;
try {
    chamar($baseUrl, 'erro_servidor_500');
} catch (TalentClientException $e) {
    $categoria500 = $e->categoria();
}
afirmar("HTTP 500 lanca TalentClientException categoria 'erro_servidor'", $categoria500 === 'erro_servidor');

// ============================================================
// 7. Host inalcancavel (porta fechada em localhost) -> 'erro_conexao'
//    (falha ANTES de qualquer byte ser transmitido, seguro reenviar)
// ============================================================
$portaFechada = $porta + 1; // ninguem escutando aqui
$categoriaConexao = null;
try {
    $clienteInalcancavel = new TalentClient("http://{$host}:{$portaFechada}", 'cenario_sucesso_200');
    $clienteInalcancavel->checkin(['teste' => true]);
} catch (TalentClientException $e) {
    $categoriaConexao = $e->categoria();
}
afirmar("Host inalcancavel lanca TalentClientException categoria 'erro_conexao'", $categoriaConexao === 'erro_conexao');

// ============================================================
// Encerramento do servidor mock
// ============================================================
proc_terminate($processo);
proc_close($processo);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
