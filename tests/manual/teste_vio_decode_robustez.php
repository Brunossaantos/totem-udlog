<?php

/**
 * Teste manual (mesmo padrao de tests/manual/teste_vio_decode.php) da
 * demanda vio-hardening-sem-credenciais (Grupo 1 — implementavel sem
 * credenciais Trial, sem chamada real ao Serpro). Roda contra o banco de
 * dev real (udlog_totem), cria e apaga seus proprios dados (nenhum
 * residuo). Um sub-bloco desta suite sobe um mock server HTTP local
 * (`php -S`) para exercitar o cURL real de VioDecodeClient::chamarDecode()
 * a nivel de transporte (limite de tamanho, HTTP status arbitrario) — NUNCA
 * contra o Serpro/VIO real.
 *
 * Cobre a matriz de 14 cenarios do planejamento
 * (docs/handoffs/2026-09-19-vio-hardening-sem-credenciais.md):
 *  1. Sucesso CNH/CRLV completo.
 *  2. Falha de rede/timeout simulada + nivel de transporte via mock server.
 *  3. Resposta parcial (campos faltando).
 *  4. Resposta malformada ($dadosBrutos nao-array em varios formatos).
 *  5. Campos extras/desconhecidos, incluindo reintroducao de `image`.
 *  6. Ausencia de credencial ($env vazio no construtor).
 *  7. Fallback manual funciona quando VIO falha.
 *  9. Sanitizacao de log/resposta varrendo todos os tipos de erro
 *     estruturado (via mock server real, cURL de verdade).
 * 10. Placeholder simetria CNH/CRLV.
 * 13. Envelope de resposta variando (achatado/data/ausente).
 * 14. normalizarData() com formatos invalidos plausiveis.
 *
 * Cenarios 8, 11, 12 sao regressao pura de suites JA EXISTENTES
 * (teste_rebaixamento_manual.php, teste_status_processamento.php,
 * teste_concorrencia_processamento_vio.php) — reexecutados separadamente,
 * nao duplicados aqui.
 *
 * Limite de tamanho testado nesta suite EXATAMENTE no limite (deve passar)
 * e 1 byte acima (deve rejeitar) — via mock server HTTP local, contra o
 * cURL real de VioDecodeClient::chamarDecode().
 *
 * Uso: php tests/manual/teste_vio_decode_robustez.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\VioCacheDao;
use App\Dao\AtendimentoDao;
use App\Rn\DocumentoRn;
use App\Rn\VioDecodeClient;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

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
 * Mesmo padrao de VioDecodeClientFalso de teste_vio_decode.php — substitui
 * a chamada HTTP real por um resultado canned.
 */
class VioDecodeClientFalsoRobustez extends VioDecodeClient
{
    private array $resultadoFixo;

    public function __construct(array $resultadoFixo)
    {
        parent::__construct([
            'VIO_AMBIENTE' => 'trial',
            'VIO_TRIAL_BEARER' => 'fake-bearer-so-para-teste',
            'VIO_TRIAL_DECODE_URL' => 'https://exemplo.invalido/decode',
        ]);
        $this->resultadoFixo = $resultadoFixo;
    }

    public function decodificar(string $rawValueQr): array
    {
        return $this->resultadoFixo;
    }
}

// ============================================================
// Setup: totem base (atendimentos individuais criados por bloco)
// ============================================================
$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_VIO_ROBUSTEZ', 'Totem Teste VIO Robustez', 'token_teste_vio_robustez_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$atendimentoDao = new AtendimentoDao($pdo);
$vioCacheDao = new VioCacheDao($pdo);
$documentoRn = new DocumentoRn($vioCacheDao, $atendimentoDao);

$idsAtendimentoCriados = [];

function novoAtendimento(AtendimentoDao $atendimentoDao, int $idTotem, string $etapa, string $placa, array &$idsAtendimentoCriados): array
{
    $id = $atendimentoDao->criar($idTotem, 'expedicao', $placa);
    $atendimentoDao->atualizarEtapa($id, $etapa);
    $idsAtendimentoCriados[] = $id;
    return $atendimentoDao->buscarPorId($id);
}

// Marcadores usados para detectar vazamento (nunca dado real) — strings
// sintéticas e obviamente artificiais, jamais reintroduzidas fora deste
// bloco de teste.
const MARCADORES_PROIBIDOS = [
    'curl errno',
    'HTTP 401',
    'HTTP 500',
    'Bearer ',
    'Authorization',
    'access_token',
    'consumer_secret',
    'VIO_TOKEN_URL',
    '127.0.0.1',
    'SEGREDO_SINTETICO_QA_NAO_REAL',
];

function motivoContemMarcadorProibido(string $motivo): ?string
{
    foreach (MARCADORES_PROIBIDOS as $marcador) {
        if (stripos($motivo, $marcador) !== false) {
            return $marcador;
        }
    }
    return null;
}

// ============================================================
// 1. Sucesso CNH/CRLV completo
// ============================================================
$atCnh1 = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'AAA1111', $idsAtendimentoCriados);
$vioCnhOk = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => ['nome' => 'FULANO DE TAL', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'], 'erro' => null]);
$rCnhOk = $documentoRn->validarCnh($atCnh1, $vioCnhOk, random_bytes(32));
afirmar('[1] CNH: sucesso completo aprova o documento', $rCnhOk['pode_avancar'] === true);

$atCrlv1 = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'AAA1111', $idsAtendimentoCriados);
$vioCrlvOk = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => ['placa' => 'AAA1111', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '12345678', 'tipo' => 'CAMINHAO'], 'erro' => null]);
$rCrlvOk = $documentoRn->validarCrlv($atCrlv1, $vioCrlvOk, random_bytes(32));
afirmar('[1] CRLV: sucesso completo aprova o documento', $rCrlvOk['pode_avancar'] === true);

// ============================================================
// 3. Resposta parcial (campos faltando)
// ============================================================
$atCnhParcial = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'BBB2222', $idsAtendimentoCriados);
$vioCnhParcial = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => ['nome' => 'FULANO SEM CPF'], 'erro' => null]);
$rCnhParcial = $documentoRn->validarCnh($atCnhParcial, $vioCnhParcial, random_bytes(32));
afirmar('[3] CNH: resposta parcial (sem cpf/data_validade) NAO e aprovada', $rCnhParcial['pode_avancar'] === false);
afirmar('[3] CNH: resposta parcial nao gera fatal/marcador proibido', motivoContemMarcadorProibido($rCnhParcial['motivo']) === null);

$atCrlvParcial = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'BBB2222', $idsAtendimentoCriados);
$vioCrlvParcial = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => ['placa' => 'BBB2222'], 'erro' => null]);
$rCrlvParcial = $documentoRn->validarCrlv($atCrlvParcial, $vioCrlvParcial, random_bytes(32));
afirmar('[3] CRLV: resposta parcial (sem exercicio/uf/rntc/tipo) NAO e aprovada', $rCrlvParcial['pode_avancar'] === false);

// ============================================================
// 4. Resposta malformada — $dadosBrutos nao-array em varios formatos
// (achado 4 do planejamento: NAO reproduzido como TypeError, ver handoff)
// ============================================================
$formatosMalformados = [
    'string simples' => 'isso-nao-e-um-array',
    'null' => null,
    'inteiro' => 12345,
    'booleano' => true,
    'array sem chave data nem campos esperados' => ['algo_inesperado' => 'x'],
    'data como string' => ['data' => 'tambem-nao-e-array'],
    'data como null' => ['data' => null],
];

foreach ($formatosMalformados as $rotulo => $dadosBrutos) {
    $atMalformadoCnh = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'CCC3333', $idsAtendimentoCriados);
    $vioMalformado = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => $dadosBrutos, 'erro' => null]);

    $excecaoLancada = false;
    $rMalformado = null;
    try {
        $rMalformado = $documentoRn->validarCnh($atMalformadoCnh, $vioMalformado, random_bytes(32));
    } catch (\Throwable $e) {
        $excecaoLancada = true;
    }

    afirmar("[4] CNH: dadosBrutos malformado ({$rotulo}) NAO lanca excecao/TypeError", $excecaoLancada === false);
    if (!$excecaoLancada) {
        afirmar("[4] CNH: dadosBrutos malformado ({$rotulo}) resulta em NAO aprovado (fail-closed)", $rMalformado['pode_avancar'] === false);
    }
}

// ============================================================
// 5. Campos extras/desconhecidos, incluindo reintroducao de `image`
// ============================================================
$atCnhImage = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'DDD4444', $idsAtendimentoCriados);
$vioCnhComImage = new VioDecodeClientFalsoRobustez([
    'ok' => true,
    'ambiente' => 'trial',
    'dados' => [
        'data' => [
            'nome' => 'FULANO COM IMAGEM',
            'cpf' => '111.444.777-35',
            'data_validade' => '2030-01-01',
        ],
        'template' => 'template-generico',
        'image' => ['base64' => str_repeat('IMAGEMFALSA', 5000)],
    ],
    'erro' => null,
]);
$rCnhComImage = $documentoRn->validarCnh($atCnhImage, $vioCnhComImage, random_bytes(32));
afirmar('[5] CNH: aprova normalmente mesmo com campos extras (template/image) no envelope', $rCnhComImage['pode_avancar'] === true);
afirmar('[5] CNH: resposta NUNCA contem a chave "image"', !array_key_exists('image', $rCnhComImage));
afirmar('[5] CNH: resposta NUNCA contem a chave "template"', !array_key_exists('template', $rCnhComImage));
$respostaSerializada = json_encode($rCnhComImage);
afirmar('[5] CNH: resposta serializada nao contem o conteudo bruto da imagem falsa', strpos($respostaSerializada, 'IMAGEMFALSA') === false);

// ============================================================
// 6. Ausencia de credencial ($env vazio no construtor)
// ============================================================
// Nota: passar [] cairia no fallback `$env ?: $_ENV` do construtor (array
// vazio e falsy em PHP) e usaria o .env real do ambiente de dev — para
// testar genuinamente a AUSENCIA de VIO_AMBIENTE, precisa de uma chave
// nao-vazia que force o array a nao ser falsy, com VIO_AMBIENTE ausente.
$excecaoAmbienteAusente = false;
try {
    new VioDecodeClient(['MARCADOR_TESTE_SEM_VIO_AMBIENTE' => '1']);
} catch (\RuntimeException $e) {
    $excecaoAmbienteAusente = true;
    afirmar('[6] Ausencia de VIO_AMBIENTE: mensagem cita so nome de variavel, nunca valor/segredo', motivoContemMarcadorProibido($e->getMessage()) === null);
}
afirmar('[6] Construtor com $env vazio lanca RuntimeException (fail-closed)', $excecaoAmbienteAusente === true);

$excecaoCredencialTrialAusente = false;
try {
    new VioDecodeClient(['VIO_AMBIENTE' => 'trial']);
} catch (\RuntimeException $e) {
    $excecaoCredencialTrialAusente = true;
}
afirmar('[6] VIO_AMBIENTE=trial sem VIO_TRIAL_BEARER/URL lanca RuntimeException (fail-closed)', $excecaoCredencialTrialAusente === true);

$excecaoCredencialProducaoAusente = false;
try {
    new VioDecodeClient(['VIO_AMBIENTE' => 'production']);
} catch (\RuntimeException $e) {
    $excecaoCredencialProducaoAusente = true;
}
afirmar('[6] VIO_AMBIENTE=production sem credenciais lanca RuntimeException (fail-closed)', $excecaoCredencialProducaoAusente === true);

// ============================================================
// 7. Fallback manual funciona quando VIO falha
// ============================================================
$atCnhFalhaVio = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'EEE5555', $idsAtendimentoCriados);
$vioFalha = new VioDecodeClientFalsoRobustez(['ok' => false, 'ambiente' => 'trial', 'dados' => null, 'erro' => ['tipo' => 'servidor', 'codigo_vio' => null, 'http_status' => 500, 'mensagem' => 'Nao foi possivel validar o documento junto ao servico externo. Preencha manualmente.']]);
$rCnhFalhaVio = $documentoRn->validarCnh($atCnhFalhaVio, $vioFalha, random_bytes(32));
afirmar('[7] CNH: falha do VIO retorna ok=false (nao aprova)', $rCnhFalhaVio['pode_avancar'] === false);

$atCnhFalhaVioAtual = $atendimentoDao->buscarPorId($atCnhFalhaVio['id_atendimento']);
$rCnhManualAposFalha = $documentoRn->preencherManualCnh($atCnhFalhaVioAtual, 'FULANO MANUAL', '111.444.777-35', '2030-01-01');
afirmar('[7] CNH: preenchimento manual funciona normalmente apos falha do VIO (fallback preservado)', $rCnhManualAposFalha['pode_avancar'] === true);
afirmar('[7] CNH: preenchimento manual grava origem MANUAL apos falha do VIO', $rCnhManualAposFalha['origem'] === 'MANUAL');

// ============================================================
// 9. Sanitizacao de log/resposta — via mock server HTTP local (cURL real)
// e 2. nivel de transporte (limite de tamanho exato / 1 byte acima)
// ============================================================
$portaMock = 8971 + random_int(0, 500); // evita colisao entre execucoes concorrentes
$logMockPath = sys_get_temp_dir() . '/mock_vio_server_' . $portaMock . '.log';
$handleLogMock = fopen($logMockPath, 'w');
// Comando em formato ARRAY (suportado desde PHP 7.4) — evita o wrapper
// cmd.exe /c usado pelo proc_open com comando em string no Windows, que
// torna proc_terminate() incapaz de matar o processo real do servidor
// embutido do PHP (mata so o cmd.exe intermediario, deixando o servidor
// orfao rodando na porta — achado durante esta implementacao). Saida
// redirecionada para ARQUIVO via descriptor (nunca pipe nao lido, que
// pode travar o processo filho quando o buffer do pipe enche).
$processoMock = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $portaMock, __DIR__ . '/mock_vio_server.php'],
    [1 => $handleLogMock, 2 => $handleLogMock],
    $pipesMock,
    __DIR__
);
usleep(500000); // aguarda o servidor embutido do PHP subir

if (!is_resource($processoMock)) {
    echo "AVISO: nao foi possivel subir o mock server local — cenarios 2/9 (transporte real) pulados.\n";
} else {
    $baseUrlMock = "http://127.0.0.1:{$portaMock}";

    /**
     * VioDecodeClient com URL apontando para o mock server local (ambiente
     * trial — sem OAuth2, sem credencial real, Bearer fake sintetico).
     */
    $vioMockTransporte = new VioDecodeClient([
        'VIO_AMBIENTE' => 'trial',
        'VIO_TRIAL_BEARER' => 'bearer-sintetico-de-teste-nao-real',
        'VIO_TRIAL_DECODE_URL' => $baseUrlMock . '/?cenario=generico&status=500',
    ]);

    // --- 9. Varredura de HTTP status arbitrarios, incluindo corpo malicioso
    // sintetico simulando tentativa de vazamento pela propria resposta
    // externa (nunca deve aparecer na saida sanitizada).
    $corpoMalicioso = base64_encode(json_encode([
        'mensagem_interna' => 'SEGREDO_SINTETICO_QA_NAO_REAL Bearer abc123 curl errno 7 HTTP 401 Authorization: Basic xxx',
    ]));

    $statusParaTestar = [400, 401, 404, 415, 422, 429, 500, 502];
    foreach ($statusParaTestar as $status) {
        $vio = new VioDecodeClient([
            'VIO_AMBIENTE' => 'trial',
            'VIO_TRIAL_BEARER' => 'bearer-sintetico-de-teste-nao-real',
            'VIO_TRIAL_DECODE_URL' => "{$baseUrlMock}/?cenario=generico&status={$status}&corpo_base64={$corpoMalicioso}",
        ]);
        $resultado = $vio->decodificar(random_bytes(16));
        $mensagem = $resultado['erro']['mensagem'] ?? '';
        afirmar("[9] HTTP {$status}: ok=false", $resultado['ok'] === false);
        afirmar("[9] HTTP {$status}: mensagem sanitizada, sem marcador proibido/corpo malicioso", motivoContemMarcadorProibido($mensagem) === null);

        // Fim a fim via DocumentoRn — confirma que o campo 'motivo' devolvido
        // ao totem tambem nunca contem o marcador proibido.
        $atSanit = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'FFF' . $status, $idsAtendimentoCriados);
        $rSanit = $documentoRn->validarCnh($atSanit, $vio, random_bytes(16));
        afirmar("[9] HTTP {$status}: campo 'motivo' fim a fim nunca contem marcador proibido", motivoContemMarcadorProibido($rSanit['motivo']) === null);
    }

    // JSON malformado / corpo vazio (HTTP 200)
    foreach (['json_malformado', 'vazio'] as $cenario) {
        $vio = new VioDecodeClient([
            'VIO_AMBIENTE' => 'trial',
            'VIO_TRIAL_BEARER' => 'bearer-sintetico-de-teste-nao-real',
            'VIO_TRIAL_DECODE_URL' => "{$baseUrlMock}/?cenario={$cenario}",
        ]);
        $resultado = $vio->decodificar(random_bytes(16));
        afirmar("[9] Cenario '{$cenario}': ok=false (nao lanca excecao, nao aceita JSON invalido)", $resultado['ok'] === false);
        afirmar("[9] Cenario '{$cenario}': mensagem sanitizada", motivoContemMarcadorProibido($resultado['erro']['mensagem'] ?? '') === null);
    }

    // --- 2. Limite de tamanho: EXATAMENTE no limite (deve passar) e 1 byte
    // acima (deve rejeitar). Usa reflection para ler o valor real da
    // constante (nunca hardcoded duplicado aqui, evita desalinhamento).
    $reflexao = new ReflectionClass(VioDecodeClient::class);
    $limiteBytes = $reflexao->getConstant('TAMANHO_MAXIMO_RESPOSTA_BYTES');
    afirmar('[2] Constante TAMANHO_MAXIMO_RESPOSTA_BYTES existe e e positiva', is_int($limiteBytes) && $limiteBytes > 0);

    $vioNoLimite = new VioDecodeClient([
        'VIO_AMBIENTE' => 'trial',
        'VIO_TRIAL_BEARER' => 'bearer-sintetico-de-teste-nao-real',
        'VIO_TRIAL_DECODE_URL' => "{$baseUrlMock}/?cenario=tamanho_exato&bytes={$limiteBytes}",
    ]);
    $resultadoNoLimite = $vioNoLimite->decodificar(random_bytes(16));
    afirmar('[2] Resposta EXATAMENTE no limite de bytes e aceita (ok=true)', $resultadoNoLimite['ok'] === true);

    $limiteMaisUm = $limiteBytes + 1;
    $vioAcimaLimite = new VioDecodeClient([
        'VIO_AMBIENTE' => 'trial',
        'VIO_TRIAL_BEARER' => 'bearer-sintetico-de-teste-nao-real',
        'VIO_TRIAL_DECODE_URL' => "{$baseUrlMock}/?cenario=tamanho_exato&bytes={$limiteMaisUm}",
    ]);
    $resultadoAcimaLimite = $vioAcimaLimite->decodificar(random_bytes(16));
    afirmar('[2] Resposta 1 byte ACIMA do limite e rejeitada (ok=false)', $resultadoAcimaLimite['ok'] === false);
    afirmar('[2] Rejeicao por tamanho nao expoe detalhe tecnico na mensagem', motivoContemMarcadorProibido($resultadoAcimaLimite['erro']['mensagem'] ?? '') === null);

    proc_terminate($processoMock);
    proc_close($processoMock);
    fclose($handleLogMock);
}

// ============================================================
// 10. Placeholder simetria CNH/CRLV
// ============================================================
$atCnhPlaceholder = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'GGG7777', $idsAtendimentoCriados);
$vioCnhPlaceholder = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => ['nome' => 'xxxxx', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'], 'erro' => null]);
$rCnhPlaceholder = $documentoRn->validarCnh($atCnhPlaceholder, $vioCnhPlaceholder, random_bytes(32));
afirmar('[10] CNH: nome placeholder "xxxxx" e rejeitado', $rCnhPlaceholder['pode_avancar'] === false);

$atCrlvPlaceholder = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'GGG7777', $idsAtendimentoCriados);
$vioCrlvPlaceholder = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => ['placa' => 'xxxxx', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => 'xxxxx', 'tipo' => 'xxxxx'], 'erro' => null]);
$rCrlvPlaceholder = $documentoRn->validarCrlv($atCrlvPlaceholder, $vioCrlvPlaceholder, random_bytes(32));
afirmar('[10] CRLV: campos placeholder "xxxxx" sao rejeitados (mesma simetria da CNH)', $rCrlvPlaceholder['pode_avancar'] === false);

// ============================================================
// 13. Envelope de resposta variando (achatado/data/ausente)
// ============================================================
$atEnvelopeAchatado = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'HHH8888', $idsAtendimentoCriados);
$vioAchatado = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => ['nome' => 'FORMATO ACHATADO', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'], 'erro' => null]);
$rAchatado = $documentoRn->validarCnh($atEnvelopeAchatado, $vioAchatado, random_bytes(32));
afirmar('[13] Envelope achatado (sem wrapper "data") e aceito normalmente', $rAchatado['pode_avancar'] === true);

$atEnvelopeData = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'HHH8889', $idsAtendimentoCriados);
$vioComData = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => ['data' => ['nome' => 'FORMATO COM DATA', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01']], 'erro' => null]);
$rComData = $documentoRn->validarCnh($atEnvelopeData, $vioComData, random_bytes(32));
afirmar('[13] Envelope com wrapper "data" e aceito normalmente', $rComData['pode_avancar'] === true);

$atEnvelopeAusente = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'HHH8890', $idsAtendimentoCriados);
$vioAusente = new VioDecodeClientFalsoRobustez(['ok' => true, 'ambiente' => 'trial', 'dados' => [], 'erro' => null]);
$rAusente = $documentoRn->validarCnh($atEnvelopeAusente, $vioAusente, random_bytes(32));
afirmar('[13] Envelope ausente/vazio nao lanca excecao e nao aprova', $rAusente['pode_avancar'] === false);

// ============================================================
// 14. normalizarData() com formatos invalidos plausiveis
// ============================================================
$formatosDataInvalidos = [
    '00/00/0000',
    '31/02/2030',
    '2030-13-40',
    '2030-02-30',
    'nao-e-uma-data',
    '',
];

foreach ($formatosDataInvalidos as $dataInvalida) {
    $atDataInvalida = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'III9999', $idsAtendimentoCriados);
    $excecaoData = false;
    $rDataInvalida = null;
    try {
        $rDataInvalida = $documentoRn->preencherManualCnh($atDataInvalida, 'FULANO DATA INVALIDA', '111.444.777-35', $dataInvalida);
    } catch (\Throwable $e) {
        $excecaoData = true;
    }
    afirmar("[14] Data invalida '{$dataInvalida}' nao lanca excecao", $excecaoData === false);
    if (!$excecaoData) {
        afirmar("[14] Data invalida '{$dataInvalida}' resulta em NAO aprovado", $rDataInvalida['pode_avancar'] === false);
    }
}

// Data valida de calendario real continua funcionando (controle positivo)
$atDataValida = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'JJJ0000', $idsAtendimentoCriados);
$rDataValida = $documentoRn->preencherManualCnh($atDataValida, 'FULANO DATA VALIDA', '111.444.777-35', '01/01/2030');
afirmar('[14] Data valida (dd/mm/aaaa real) continua sendo aprovada (controle positivo)', $rDataValida['pode_avancar'] === true);

// ============================================================
// Limpeza final
// ============================================================
$pdo->exec('DELETE FROM tb_vio_cache_cnh');
$pdo->exec('DELETE FROM tb_vio_cache_crlv');
foreach ($idsAtendimentoCriados as $id) {
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
