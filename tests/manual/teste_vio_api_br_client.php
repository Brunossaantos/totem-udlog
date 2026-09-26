<?php

/**
 * Teste manual (sem framework, mesmo padrao de tests/manual/teste_vio_decode_robustez.php)
 * da demanda migracao-vio-api-br-com-cache — bateria 1 (mock HTTP local em
 * MEMORIA, zero rede real, zero credencial real). App\Rn\VioApiBrClient e
 * injetavel no nivel do transporte HTTP (2o parametro do construtor), entao
 * este teste NUNCA sobe um servidor HTTP real (nem `php -S`) — o mock e um
 * `callable` PHP simples que registra as chamadas recebidas e devolve
 * respostas canned.
 *
 * Nao depende de banco de dados (VioApiBrClient e standalone).
 *
 * Uso: php tests/manual/teste_vio_api_br_client.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Rn\VioApiBrClient;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    if ($condicao) {
        echo "OK   - {$descricao}\n";
    } else {
        $totalFalhas++;
        echo "FALHA - {$descricao}\n";
    }
}

/**
 * Fabrica um transporte mock em memoria: $respostas e uma fila (array) de
 * respostas no formato esperado por VioApiBrClient — cada chamada consome a
 * proxima da fila. $chamadas (passado por referencia) registra
 * [metodo, url, corpoJson] de cada invocacao, para asserções de "no maximo
 * 1 chamada" / "nunca chamou".
 */
function mockTransporte(array $respostas, &$chamadas): callable
{
    $chamadas = [];
    $indice = 0;
    return function (string $metodo, string $url, ?array $corpoJson, int $timeoutConexao, int $timeoutTotal) use ($respostas, &$chamadas, &$indice): array {
        $chamadas[] = ['metodo' => $metodo, 'url' => $url, 'corpo' => $corpoJson];
        if (!array_key_exists($indice, $respostas)) {
            throw new \RuntimeException('mockTransporte: fila de respostas esgotada (chamada inesperada #' . ($indice + 1) . ')');
        }
        return $respostas[$indice++];
    };
}

function envAmostra(): array
{
    return ['VIO_API_BR_BASE_URL' => 'https://vio.api.br', 'VIO_API_BR_API_KEY' => 'chave-de-teste-fake-nunca-real'];
}

// ============================================================
// 0. Fail-closed no construtor
// ============================================================
$falhouSemBaseUrl = false;
try {
    new VioApiBrClient(['VIO_API_BR_BASE_URL' => '', 'VIO_API_BR_API_KEY' => 'x']);
} catch (\RuntimeException $e) {
    $falhouSemBaseUrl = true;
}
afirmar('Construtor falha (RuntimeException) sem VIO_API_BR_BASE_URL', $falhouSemBaseUrl);

$falhouSemApiKey = false;
try {
    new VioApiBrClient(['VIO_API_BR_BASE_URL' => 'https://vio.api.br', 'VIO_API_BR_API_KEY' => '']);
} catch (\RuntimeException $e) {
    $falhouSemApiKey = true;
}
afirmar('Construtor falha (RuntimeException) sem VIO_API_BR_API_KEY', $falhouSemApiKey);

$falhouHttpPuro = false;
try {
    new VioApiBrClient(['VIO_API_BR_BASE_URL' => 'http://vio.api.br', 'VIO_API_BR_API_KEY' => 'x']);
} catch (\RuntimeException $e) {
    $falhouHttpPuro = true;
}
afirmar('Construtor falha (RuntimeException) com base URL nao-HTTPS', $falhouHttpPuro);

// ============================================================
// 1. Envio aceito (POST 200, retorna id + status:processing)
// ============================================================
$chamadas = [];
$transporte = mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => ['id' => 'abc123', 'status' => 'processing']],
], $chamadas);
$vio = new VioApiBrClient(envAmostra(), $transporte);
$r = $vio->enviarParaLeitura('bytes-fake-do-pdf-ou-jpeg');
afirmar('Envio aceito: ok=true', $r['ok'] === true);
afirmar('Envio aceito: id_externo devolvido corretamente', $r['id_externo'] === 'abc123');
afirmar('Envio aceito: ambiguo=false', $r['ambiguo'] === false);
afirmar('Envio aceito: exatamente 1 chamada HTTP disparada (nunca mais de 1 POST por tentativa)', count($chamadas) === 1);
afirmar('Envio aceito: metodo usado foi POST', $chamadas[0]['metodo'] === 'POST');
afirmar('Envio aceito: URL correta', $chamadas[0]['url'] === 'https://vio.api.br/api/qrcode/read');
afirmar('Envio aceito: corpo nunca usa compare_lines=true (usa comparar=true)', ($chamadas[0]['corpo']['comparar'] ?? null) === true && !array_key_exists('compare_lines', $chamadas[0]['corpo']));
afirmar('Envio aceito: imagem enviada em base64 (nunca binario cru no JSON)', $chamadas[0]['corpo']['image'] === base64_encode('bytes-fake-do-pdf-ou-jpeg'));

// ============================================================
// 2. Envio 201/202 tambem aceitos
// ============================================================
foreach ([201, 202] as $statusAceito) {
    $chamadas2 = [];
    $vio2 = new VioApiBrClient(envAmostra(), mockTransporte([
        ['erro' => null, 'http_status' => $statusAceito, 'corpo' => ['id' => 'id-' . $statusAceito, 'status' => 'processing']],
    ], $chamadas2));
    $r2 = $vio2->enviarParaLeitura('x');
    afirmar("Envio aceito com HTTP {$statusAceito}", $r2['ok'] === true && $r2['id_externo'] === 'id-' . $statusAceito);
}

// ============================================================
// 3. Envio: resposta 200 sem campo id -> ambiguo=true (nunca confia em id ausente)
// ============================================================
$vio3 = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => ['status' => 'processing']],
], $chamadas3));
$r3 = $vio3->enviarParaLeitura('x');
afirmar('Envio 200 sem id: ok=false', $r3['ok'] === false);
afirmar('Envio 200 sem id: ambiguo=true (nunca sabemos se o fornecedor considerou valido)', $r3['ambiguo'] === true);
afirmar('Envio 200 sem id: mensagem generica (nunca crua)', $r3['erro']['mensagem'] === 'Nao foi possivel validar o documento junto ao servico externo. Preencha manualmente.');

// ============================================================
// 4. Envio: HTTP 401/403 -> falha SEM ambiguidade (credencial invalida/sem permissao)
// ============================================================
foreach ([401, 403] as $statusAuth) {
    $vioAuth = new VioApiBrClient(envAmostra(), mockTransporte([
        ['erro' => null, 'http_status' => $statusAuth, 'corpo' => null],
    ], $chamadasAuth));
    $rAuth = $vioAuth->enviarParaLeitura('x');
    afirmar("Envio HTTP {$statusAuth}: ok=false", $rAuth['ok'] === false);
    afirmar("Envio HTTP {$statusAuth}: ambiguo=false (falha tecnica sem ambiguidade)", $rAuth['ambiguo'] === false);
}

// ============================================================
// 5. Envio: HTTP 400/422 -> falha SEM ambiguidade (validacao)
// ============================================================
foreach ([400, 422] as $statusValidacao) {
    $vioVal = new VioApiBrClient(envAmostra(), mockTransporte([
        ['erro' => null, 'http_status' => $statusValidacao, 'corpo' => null],
    ], $chamadasVal));
    $rVal = $vioVal->enviarParaLeitura('x');
    afirmar("Envio HTTP {$statusValidacao}: ok=false, ambiguo=false", $rVal['ok'] === false && $rVal['ambiguo'] === false);
}

// ============================================================
// 6. Envio: HTTP 5xx/desconhecido -> AMBIGUO (nao sabemos se foi processado, nunca retry automatico)
// ============================================================
foreach ([500, 503, 418] as $statusDesconhecido) {
    $vio5xx = new VioApiBrClient(envAmostra(), mockTransporte([
        ['erro' => null, 'http_status' => $statusDesconhecido, 'corpo' => null],
    ], $chamadas5xx));
    $r5xx = $vio5xx->enviarParaLeitura('x');
    afirmar("Envio HTTP {$statusDesconhecido}: ok=false, ambiguo=true", $r5xx['ok'] === false && $r5xx['ambiguo'] === true);
}

// ============================================================
// 7. Envio: timeout APOS conexao estabelecida ('timeout_ambiguo') -> ambiguo=true
// ============================================================
$vioTimeoutAmbiguo = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => ['tipo' => 'timeout_ambiguo'], 'http_status' => null, 'corpo' => null],
], $chamadasTimeoutAmb));
$rTimeoutAmb = $vioTimeoutAmbiguo->enviarParaLeitura('x');
afirmar('Envio timeout apos conexao (ambiguo): ok=false', $rTimeoutAmb['ok'] === false);
afirmar('Envio timeout apos conexao (ambiguo): ambiguo=true (nunca retry automatico)', $rTimeoutAmb['ambiguo'] === true);

// ============================================================
// 8. Envio: conexao recusada / sem conexao ('rede_sem_conexao') -> ambiguo=false (seguro para nova tentativa)
// ============================================================
$vioSemConexao = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => ['tipo' => 'rede_sem_conexao'], 'http_status' => null, 'corpo' => null],
], $chamadasSemConexao));
$rSemConexao = $vioSemConexao->enviarParaLeitura('x');
afirmar('Envio sem conexao (nunca chegou a conectar): ok=false', $rSemConexao['ok'] === false);
afirmar('Envio sem conexao: ambiguo=false (fornecedor nunca recebeu nada, seguro reenviar)', $rSemConexao['ambiguo'] === false);

// ============================================================
// 9. Envio: resposta excessivamente grande (teto de bytes) -> tratado como erro de transporte
// ============================================================
$vioExcessivo = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => ['tipo' => 'resposta_excessiva'], 'http_status' => null, 'corpo' => null],
], $chamadasExcessivo));
$rExcessivo = $vioExcessivo->enviarParaLeitura('x');
afirmar('Envio com resposta excessiva: ok=false (abortada antes de decodificar)', $rExcessivo['ok'] === false);
afirmar('Envio com resposta excessiva: exatamente 1 chamada (nunca tenta ler o corpo inteiro)', count($chamadasExcessivo) === 1);

// ============================================================
// 10. Consulta: ID externo com formato invalido -> rejeitado ANTES de montar
// a URL, NUNCA chama a rede.
// ============================================================
foreach (['id com espaco', 'id;drop table', "id\ncom\nquebra", str_repeat('a', 200), ''] as $idInvalido) {
    $chamadasIdInvalido = [];
    $vioIdInvalido = new VioApiBrClient(envAmostra(), mockTransporte([], $chamadasIdInvalido));
    $rIdInvalido = $vioIdInvalido->consultarResultado($idInvalido);
    afirmar('Consulta com id invalido (' . substr(json_encode($idInvalido), 0, 20) . '...): ambiguo=true', $rIdInvalido['ambiguo'] === true);
    afirmar('Consulta com id invalido: ZERO chamadas de rede (validado antes de montar URL)', count($chamadasIdInvalido) === 0);
}

// ============================================================
// 11. Consulta: leitura processing (ainda sem comparacao)
// ============================================================
$vioProcessing = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => ['status' => 'processing']],
], $chamadasProcessing));
$rProcessing = $vioProcessing->consultarResultado('id-valido-123');
afirmar('Consulta leitura processing: ok=true', $rProcessing['ok'] === true);
afirmar('Consulta leitura processing: estado_leitura=processing', $rProcessing['estado_leitura'] === 'processing');
afirmar('Consulta com id valido: exatamente 1 GET disparado', count($chamadasProcessing) === 1 && $chamadasProcessing[0]['metodo'] === 'GET');
afirmar('Consulta usa a URL correta com o id', $chamadasProcessing[0]['url'] === 'https://vio.api.br/api/qrcode/result/id-valido-123');

// ============================================================
// 12. Consulta: leitura completed, comparacao pending/processing -> devolve estado_comparacao correspondente
// ============================================================
foreach (['pending', 'processing'] as $estadoComparacao) {
    $vioComp = new VioApiBrClient(envAmostra(), mockTransporte([
        ['erro' => null, 'http_status' => 200, 'corpo' => [
            'status' => 'completed',
            'qr_type' => 'vio',
            'vio_result' => ['nome' => 'FULANO'],
            'compare' => ['status' => $estadoComparacao],
        ]],
    ], $chamadasComp));
    $rComp = $vioComp->consultarResultado('id-valido-123');
    afirmar("Consulta leitura completed + comparacao {$estadoComparacao}: estado_comparacao correto", $rComp['estado_comparacao'] === $estadoComparacao);
}

// ============================================================
// 13. Consulta: comparacao completed, CNH com pages_processed=2/total_pages=2
// (elegivel para aprovacao) vs so 1 pagina processada (nunca deve ser
// elegivel, mesmo com reliable=true/mismatched=0)
// ============================================================
function corpoComparacaoCompleta(array $overrides = []): array
{
    return array_replace([
        'status' => 'completed',
        'qr_type' => 'vio',
        'vio_result' => ['nome' => 'FULANO DA SILVA', 'cpf' => '11144477735', 'data_validade' => '2030-01-01'],
        'compare' => [
            'status' => 'completed',
            'result' => [
                'summary' => ['reliable' => true, 'mismatched' => 0],
                'fields' => ['nome' => 'match', 'cpf' => 'match', 'data_validade' => 'match'],
            ],
        ],
        'pages_processed' => 2,
        'total_pages' => 2,
    ], $overrides);
}

$vio2paginas = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => corpoComparacaoCompleta()],
], $chamadas2pag));
$r2pag = $vio2paginas->consultarResultado('id-cnh-2paginas');
afirmar('CNH com pages_processed=2/total_pages=2: campos normalizados corretamente', $r2pag['pages_processed'] === 2 && $r2pag['total_pages'] === 2);
afirmar('CNH com pages_processed=2/total_pages=2: summary reliable=true/mismatched=0 normalizados', $r2pag['comparacao']['summary']['reliable'] === true && $r2pag['comparacao']['summary']['mismatched'] === 0);

$vio1pagina = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => corpoComparacaoCompleta(['pages_processed' => 1, 'total_pages' => 2])],
], $chamadas1pag));
$r1pag = $vio1pagina->consultarResultado('id-cnh-1pagina');
afirmar('CNH com so 1 pagina processada: cliente normaliza pages_processed=1 corretamente (decisao de aprovacao fica a cargo de DocumentoRn, testada em outra suite)', $r1pag['pages_processed'] === 1 && $r1pag['total_pages'] === 2);

// ============================================================
// 14. Consulta: CRLV — fluxo completo aprovado (sem exigencia de paginas)
// ============================================================
$corpoCrlv = [
    'status' => 'completed',
    'qr_type' => 'vio',
    'vio_result' => ['placa' => 'ABC1234', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '12345678', 'tipo' => 'CAMINHAO', 'renavam' => '12345678901'],
    'compare' => [
        'status' => 'completed',
        'result' => [
            'summary' => ['reliable' => true, 'mismatched' => 0],
            'fields' => ['placa' => 'match', 'renavam' => 'match', 'exercicio' => 'match', 'uf' => 'match'],
        ],
    ],
];
$vioCrlv = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => $corpoCrlv],
], $chamadasCrlv));
$rCrlv = $vioCrlv->consultarResultado('id-crlv-ok');
afirmar('CRLV completo: estado_leitura/estado_comparacao completed', $rCrlv['estado_leitura'] === 'completed' && $rCrlv['estado_comparacao'] === 'completed');
afirmar('CRLV completo: dados_leitura contem os campos esperados', $rCrlv['dados_leitura']['placa'] === 'ABC1234' && $rCrlv['dados_leitura']['renavam'] === '12345678901');

// ============================================================
// 15. Consulta: summary.reliable=true + mismatched=0 -> normalizado ok;
// mismatch/not_found em campo -> normalizado no array `campos`
// ============================================================
$vioMismatch = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => corpoComparacaoCompleta([
        'compare' => ['status' => 'completed', 'result' => ['summary' => ['reliable' => true, 'mismatched' => 1], 'fields' => ['nome' => 'mismatch', 'cpf' => 'match', 'data_validade' => 'match']]],
    ])],
], $chamadasMismatch));
$rMismatch = $vioMismatch->consultarResultado('id-mismatch');
afirmar('Campo com mismatch normalizado corretamente em comparacao.campos', $rMismatch['comparacao']['campos']['nome'] === 'mismatch');

$vioNotFound = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => corpoComparacaoCompleta([
        'compare' => ['status' => 'completed', 'result' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'fields' => ['nome' => 'match', 'cpf' => 'match', 'data_validade' => 'not_found']]],
    ])],
], $chamadasNotFound));
$rNotFound = $vioNotFound->consultarResultado('id-not-found-critico');
afirmar('Campo critico com not_found normalizado corretamente (decisao de reprovar fica em DocumentoRn)', $rNotFound['comparacao']['campos']['data_validade'] === 'not_found');

$vioReliableFalse = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => corpoComparacaoCompleta([
        'compare' => ['status' => 'completed', 'result' => ['summary' => ['reliable' => false, 'mismatched' => 0], 'fields' => ['nome' => 'match', 'cpf' => 'match', 'data_validade' => 'match']]],
    ])],
], $chamadasReliableFalse));
$rReliableFalse = $vioReliableFalse->consultarResultado('id-reliable-false');
afirmar('summary.reliable=false normalizado corretamente', $rReliableFalse['comparacao']['summary']['reliable'] === false);

// ============================================================
// 16. Saldo insuficiente: status:"failed" SEM HTTP de erro -> normalizado
// como leitura failed (definitiva, nao ambigua) — a categorizacao especifica
// de "saldo insuficiente" e uma PENDENCIA registrada (nao reconhecida por
// string matching, ver comentario de classe de VioApiBrClient), mas a
// mensagem exposta ao totem e SEMPRE generica em qualquer caminho.
// ============================================================
$vioSaldoInsuficiente = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => ['status' => 'failed', 'error_message' => 'saldo insuficiente na conta xyz123']],
], $chamadasSaldo));
$rSaldo = $vioSaldoInsuficiente->consultarResultado('id-saldo');
afirmar('Saldo insuficiente (status failed sem HTTP erro): normalizado como leitura failed', $rSaldo['ok'] === true && $rSaldo['estado_leitura'] === 'failed');
afirmar('Saldo insuficiente: error_message BRUTO nunca aparece em nenhum campo do array normalizado', !str_contains(json_encode($rSaldo), 'saldo insuficiente'));

// ============================================================
// 17. Consulta: HTTP 401/403/404/422 (e 400)
// ============================================================
$vio404 = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 404, 'corpo' => null],
], $chamadas404));
$r404 = $vio404->consultarResultado('id-inexistente');
afirmar('Consulta HTTP 404: nao_encontrado=true', $r404['nao_encontrado'] === true);
afirmar('Consulta HTTP 404: ambiguo=false, ok=false', $r404['ambiguo'] === false && $r404['ok'] === false);

foreach ([400, 401, 403, 422] as $statusErroConsulta) {
    $vioErroConsulta = new VioApiBrClient(envAmostra(), mockTransporte([
        ['erro' => null, 'http_status' => $statusErroConsulta, 'corpo' => null],
    ], $chamadasErroConsulta));
    $rErroConsulta = $vioErroConsulta->consultarResultado('id-erro-consulta');
    afirmar("Consulta HTTP {$statusErroConsulta}: ok=false, ambiguo=false, nao_encontrado=false (falha tecnica de leitura, GET nunca cobra)", $rErroConsulta['ok'] === false && $rErroConsulta['ambiguo'] === false && $rErroConsulta['nao_encontrado'] === false);
}

$vio5xxConsulta = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 500, 'corpo' => null],
], $chamadas5xxConsulta));
$r5xxConsulta = $vio5xxConsulta->consultarResultado('id-5xx');
afirmar('Consulta HTTP 500: ok=false, ambiguo=false (GET nunca gera cobranca, seguro classificar como ERRO)', $r5xxConsulta['ok'] === false && $r5xxConsulta['ambiguo'] === false);

// ============================================================
// 18. Consulta: JSON invalido / corpo malformado (corpo=null apos json_decode falhar)
// ============================================================
$vioJsonInvalido = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => null],
], $chamadasJsonInvalido));
$rJsonInvalido = $vioJsonInvalido->consultarResultado('id-json-invalido');
afirmar('Consulta com JSON invalido/corpo malformado: ambiguo=true (contrato minimo nao atendido)', $rJsonInvalido['ambiguo'] === true);

// campo `status` ausente/tipo errado -> tambem ambiguo=true
$vioStatusAusente = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => ['qr_type' => 'vio']],
], $chamadasStatusAusente));
$rStatusAusente = $vioStatusAusente->consultarResultado('id-status-ausente');
afirmar('Consulta sem campo status (leitura): ambiguo=true', $rStatusAusente['ambiguo'] === true);

$vioStatusTipoErrado = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => ['status' => 12345]],
], $chamadasStatusTipoErrado));
$rStatusTipoErrado = $vioStatusTipoErrado->consultarResultado('id-status-tipo-errado');
afirmar('Consulta com status de tipo errado (int em vez de string): ambiguo=true', $rStatusTipoErrado['ambiguo'] === true);

$vioStatusInvalido = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => ['status' => 'um_valor_nao_documentado']],
], $chamadasStatusInvalido));
$rStatusInvalido = $vioStatusInvalido->consultarResultado('id-status-invalido');
afirmar('Consulta com status fora da allowlist conhecida: ambiguo=true (nunca confia em valor desconhecido)', $rStatusInvalido['ambiguo'] === true);

// ============================================================
// 19. Consulta: resposta excessivamente grande (teto de bytes) — abortada
// ANTES do json_decode
// ============================================================
$vioConsultaExcessiva = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => ['tipo' => 'resposta_excessiva'], 'http_status' => null, 'corpo' => null],
], $chamadasConsultaExcessiva));
$rConsultaExcessiva = $vioConsultaExcessiva->consultarResultado('id-excessivo');
afirmar('Consulta com resposta excessiva: ok=false (nunca tenta decodificar)', $rConsultaExcessiva['ok'] === false);
afirmar('Consulta com resposta excessiva: exatamente 1 GET disparado (nunca loop de retry interno)', count($chamadasConsultaExcessiva) === 1);

// ============================================================
// 20. Consulta: timeout de conexao simulado / conexao recusada
// ============================================================
$vioTimeoutConsulta = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => ['tipo' => 'timeout_ambiguo'], 'http_status' => null, 'corpo' => null],
], $chamadasTimeoutConsulta));
$rTimeoutConsulta = $vioTimeoutConsulta->consultarResultado('id-timeout');
afirmar('Consulta com timeout (apos conexao): ok=false, ambiguo=false (GET nunca gera cobranca -- so ENVIO e ambiguo, nunca consulta)', $rTimeoutConsulta['ok'] === false && $rTimeoutConsulta['ambiguo'] === false);

$vioConexaoRecusadaConsulta = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => ['tipo' => 'rede_sem_conexao'], 'http_status' => null, 'corpo' => null],
], $chamadasConexaoRecusadaConsulta));
$rConexaoRecusadaConsulta = $vioConexaoRecusadaConsulta->consultarResultado('id-conexao-recusada');
afirmar('Consulta com conexao recusada: ok=false, ambiguo=false', $rConexaoRecusadaConsulta['ok'] === false && $rConexaoRecusadaConsulta['ambiguo'] === false);

// ============================================================
// 21. Campos extras/desconhecidos na resposta -- ignorados com seguranca
// (nunca causam erro fatal, nunca sao repassados adiante)
// ============================================================
$vioCamposExtras = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => array_merge(corpoComparacaoCompleta(), [
        'campo_desconhecido_do_fornecedor' => ['qualquer' => 'coisa'],
        'outro_campo_nao_documentado' => 12345,
    ])],
], $chamadasCamposExtras));
$rCamposExtras = $vioCamposExtras->consultarResultado('id-campos-extras');
afirmar('Campos extras/desconhecidos nao causam erro (resposta processada normalmente)', $rCamposExtras['ok'] === true);
afirmar('Campos extras/desconhecidos NUNCA aparecem na resposta normalizada', !array_key_exists('campo_desconhecido_do_fornecedor', $rCamposExtras) && !str_contains(json_encode($rCamposExtras), 'campo_desconhecido_do_fornecedor'));

// ============================================================
// 22. Tentativa de incluir image/image_url/Base64 na resposta -- DESCARTADO,
// nunca persistido/retornado
// ============================================================
$base64Fake = base64_encode(random_bytes(256));
$vioComImagem = new VioApiBrClient(envAmostra(), mockTransporte([
    ['erro' => null, 'http_status' => 200, 'corpo' => array_merge(corpoComparacaoCompleta(), [
        'image' => $base64Fake,
        'image_url' => 'https://vio.api.br/imagens/deveria-nunca-vazar.jpg',
        'vio_result' => array_merge(corpoComparacaoCompleta()['vio_result'], ['foto_base64' => $base64Fake]),
    ])],
], $chamadasComImagem));
$rComImagem = $vioComImagem->consultarResultado('id-com-imagem');
$jsonResultado = json_encode($rComImagem);
afirmar('Campo `image` (Base64) da resposta NUNCA aparece na saida normalizada', !str_contains($jsonResultado, $base64Fake));
afirmar('Campo `image_url` NUNCA aparece na saida normalizada', !str_contains($jsonResultado, 'image_url') && !str_contains($jsonResultado, 'deveria-nunca-vazar'));
// vio_result e passado adiante em dados_leitura (uso interno de DocumentoRn,
// que so extrai campos da ALLOWLIST fechada ESQUEMA_TIPOS_CNH/CRLV) -- o
// `foto_base64` so seria descartado na fronteira de App\Rn\DocumentoRn, nao
// aqui em VioApiBrClient (responsabilidades diferentes). Confirmado por
// leitura de codigo: extrairCampoTexto/extrairCampoNumerico usam allowlist
// fechada, nunca "repassam tudo".
afirmar('dados_leitura preserva vio_result para DocumentoRn decidir (fronteira de allowlist fica em DocumentoRn, nao aqui)', is_array($rComImagem['dados_leitura']));

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
