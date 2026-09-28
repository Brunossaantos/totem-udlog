<?php

/**
 * Teste manual (sem rede real, banco `qa_` descartavel) — suporte a CNH
 * DIGITAL (documento eletronico oficial do app do Detran/Senatran, PDF de 1
 * pagina com QR Code), demanda `suporte-cnh-digital` (2026-09-27) sobre
 * `migracao-vio-api-br-com-cache`. Ver docs/handoffs/
 * 2026-09-25-migracao-vio-api-br-com-cache.md, secao "Suporte a CNH digital
 * (1 pagina) — 2026-09-27".
 *
 * Cobre exatamente os 5 itens exigidos pelo escopo desta demanda:
 * (a) cnh_modo_captura precisa ser definido explicitamente como 'DIGITAL'
 *     ANTES da captura (App\Controller\DocumentoController::definirModoCnh(),
 *     acao nova `definir-modo-cnh`; App\Dao\AtendimentoDao::definirModoCnh())
 *     — valor fora do enum 'FISICA'|'DIGITAL' e rejeitado fail-closed, sem
 *     gravar nada; posse/etapa validadas (mesma allowlist das demais acoes).
 * (b) modo DIGITAL exige so a frente — upload() aceita imagem_frente sozinha
 *     (sem imagem_verso); FISICA/NULL (fail-safe) continua exigindo os 2
 *     lados na mesma chamada, comportamento IDENTICO ao de antes desta
 *     mudanca. arquivosCnhCompletosParaModo() (renomeada de
 *     arquivosCnhAmbosLadosPresentes()) testada via Reflection.
 * (c) PDF de 1 pagina gerado corretamente para o modo DIGITAL (montarPdfCnh(),
 *     via Reflection) — FISICA continua gerando 2 paginas (regressao).
 * (d) Aprovacao automatica em modo DIGITAL exige pages_processed=1/
 *     total_pages=1 (App\Rn\DocumentoRn::avaliarResultadoVioApiBrCnh()) —
 *     NUNCA aprova com 2 nem com qualquer outro valor; modo FISICA/NULL
 *     continua exigindo 2/2 (regressao pontual, cobertura completa fica em
 *     tests/manual/teste_vio_api_br_cas_e_cache.php).
 * (e) cnh_modo_captura=NULL (nunca definido) + so a frente presente -> o
 *     gate ANTES do CAS de envio (DocumentoController::iniciarProcessamento())
 *     continua bloqueando com HTTP 409, esperando o verso — fail-safe
 *     preservado, comportamento IDENTICO ao de antes desta mudanca.
 *
 * Roda contra um banco `qa_`-prefixado DESCARTAVEL (nunca udlog_totem),
 * dropado ao final independente de sucesso/falha. Zero chamada de rede real
 * (VioApiBrClient nunca e instanciado nesta suite — todos os cenarios param
 * ANTES dele, no gate de arquivos, ou chamam DocumentoRn diretamente com um
 * resultado ja normalizado/sintetico).
 *
 * Uso: php tests/manual/teste_cnh_digital_1_pagina.php
 */

require_once __DIR__ . '/qa_db_bootstrap.php';

use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Dao\VioApiBrCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

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
 * Cria um JPEG minimo VALIDO em disco (mesma tecnica ja usada por
 * tests/manual/_fixtures_talent.php::talentCriarJpegValido() — inlinada
 * aqui para manter este arquivo autocontido, sem depender de outro fixture
 * nao relacionado a esta demanda).
 */
function cnhDigitalCriarJpegValido(string $caminho): void
{
    $img = imagecreatetruecolor(200, 120);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
    imagejpeg($img, $caminho, 85);
    imagedestroy($img);
}

$nomeBanco = null;
$arquivosTemporariosLimpar = [];
$pastasStorageLimpar = [];

register_shutdown_function(function () use (&$arquivosTemporariosLimpar) {
    foreach ($arquivosTemporariosLimpar as $arquivo) {
        @unlink($arquivo);
    }
});

try {
    [$pdo, $nomeBanco] = qaDbCriar('cnh_digital_1_pagina');

    // Chaves FAKE geradas so em memoria para este processo de teste -- NUNCA
    // gravadas em .env, nunca reais (mesmo padrao ja usado por
    // teste_vio_api_br_cas_e_cache.php).
    $_ENV['VIO_API_BR_CACHE_HMAC_VERSION'] = '1';
    $_ENV['VIO_API_BR_CACHE_HMAC_KEY_V1'] = bin2hex(random_bytes(32));
    $_ENV['VIO_API_BR_CACHE_TTL_DIAS'] = '7';
    $_ENV['DOCUMENTO_DATA_KEY'] = bin2hex(random_bytes(32));
    $_ENV['DOCUMENTO_QR_HMAC_KEY'] = bin2hex(random_bytes(32));
    // Herdado pelos subprocessos via putenv() + qaDbTrechoPonteEnvSubprocesso().
    putenv('VIO_API_BR_CACHE_HMAC_VERSION=' . $_ENV['VIO_API_BR_CACHE_HMAC_VERSION']);
    putenv('VIO_API_BR_CACHE_HMAC_KEY_V1=' . $_ENV['VIO_API_BR_CACHE_HMAC_KEY_V1']);
    putenv('VIO_API_BR_CACHE_TTL_DIAS=' . $_ENV['VIO_API_BR_CACHE_TTL_DIAS']);
    putenv('DOCUMENTO_DATA_KEY=' . $_ENV['DOCUMENTO_DATA_KEY']);
    putenv('DOCUMENTO_QR_HMAC_KEY=' . $_ENV['DOCUMENTO_QR_HMAC_KEY']);

    $_ENV['DB_NAME'] = $nomeBanco;
    putenv('DB_NAME=' . $nomeBanco);

    $atendimentoDao = new AtendimentoDao($pdo);
    $cacheVioApiBrDao = new VioApiBrCacheDao($pdo);
    $documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao, $cacheVioApiBrDao);
    $controller = new DocumentoController($atendimentoDao, $documentoRn, $pdo);

    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('QA_CNH_DIGITAL', 'Totem QA CNH Digital', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
    $idTotem = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('QA_CNH_DIGITAL_INVASOR', 'Totem QA Invasor', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
    $idTotemInvasor = (int) $pdo->lastInsertId();

    function novoAtendimentoExp(AtendimentoDao $dao, int $idTotem, string $placa): array
    {
        $id = $dao->criar($idTotem, 'expedicao', $placa);
        $dao->atualizarEtapa($id, 'exp_cnh');
        return $dao->buscarPorId($id);
    }

    $storagePath = rtrim($_ENV['STORAGE_PATH'] ?? '', '/');
    $raizProjeto = dirname(__DIR__, 2);

    $refArquivos = new ReflectionMethod(DocumentoController::class, 'arquivosCnhCompletosParaModo');
    $refArquivos->setAccessible(true);
    $refPdf = new ReflectionMethod(DocumentoController::class, 'montarPdfCnh');
    $refPdf->setAccessible(true);

    // Subprocesso generico (dispatcher unico, reaproveitado por todos os
    // cenarios que precisam do comportamento HTTP real de Resposta::sucesso/
    // erro -- que chama exit(), por isso nunca invocado diretamente neste
    // processo). Mesmo padrao (bridge de env + Conexao::obter() contra o
    // banco qa_) ja usado por tests/manual/teste_lock_obter_lock_documento.php.
    $corpoDispatcher = <<<'CORPO'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Dao\VioApiBrCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

foreach (getenv() as $qaChaveHerdada => $qaValorHerdado) {
    if (!array_key_exists($qaChaveHerdada, $_ENV)) {
        $_ENV[$qaChaveHerdada] = $qaValorHerdado;
    }
}

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

$pdo = Conexao::obter();
$atendimentoDao = new AtendimentoDao($pdo);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao, new VioApiBrCacheDao($pdo));
$controller = new DocumentoController($atendimentoDao, $documentoRn, $pdo);

$acao = $argv[1] ?? '';
$idTotem = (int) ($argv[2] ?? 0);
$entrada = json_decode($argv[3] ?? '{}', true) ?? [];

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

switch ($acao) {
    case 'definir-modo-cnh':
        $controller->definirModoCnh($entrada, $idTotem);
        break;
    case 'upload':
        $controller->upload($entrada, $idTotem);
        break;
    case 'iniciar-processamento':
        $controller->iniciarProcessamento($entrada, $idTotem);
        break;
    default:
        echo json_encode(['erro' => 'acao invalida no subprocesso de teste']);
        exit(2);
}
CORPO;

    $arquivosTemporariosLimpar[] = $scriptDispatcher = qaGerarScriptTemporario($corpoDispatcher, $raizProjeto, 'cnh_digital_dispatcher');

    function dispararSubprocesso(string $script, string $acao, int $idTotem, array $entrada): string
    {
        $php = PHP_BINARY;
        $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmd = [$php, $script, $acao, (string) $idTotem, json_encode($entrada)];
        $processo = proc_open($cmd, $descritores, $pipes);
        $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($processo);
        return $saida;
    }

    // ============================================================
    // (a) definirModoCnh — definicao explicita do modo ANTES da captura
    // ============================================================

    $atA = novoAtendimentoExp($atendimentoDao, $idTotem, 'DIG0001');
    $idAtA = (int) $atA['id_atendimento'];

    $rInvalido = dispararSubprocesso($scriptDispatcher, 'definir-modo-cnh', $idTotem, ['id_atendimento' => $idAtA, 'modo' => 'PDF_INVALIDO']);
    afirmar('(a) definirModoCnh rejeita valor fora do enum FISICA|DIGITAL (fail-closed)', str_contains($rInvalido, 'HTTP_CODE:400'));

    $atAApos = $atendimentoDao->buscarPorId($idAtA);
    afirmar('(a) valor fora do enum NUNCA e gravado (cnh_modo_captura continua NULL)', $atAApos['cnh_modo_captura'] === null);

    $rDigital = dispararSubprocesso($scriptDispatcher, 'definir-modo-cnh', $idTotem, ['id_atendimento' => $idAtA, 'modo' => 'DIGITAL']);
    // Nota: http_response_code() sem argumento previo devolve false (nunca
    // "200") quando nenhum codigo foi setado explicitamente -- Resposta::
    // sucesso() nunca chama http_response_code(). Sucesso e confirmado so
    // pelo payload ('"sucesso":true'); "HTTP_CODE:" (vazio) e o esperado
    // aqui, nunca "HTTP_CODE:4xx"/"5xx".
    afirmar('(a) definirModoCnh aceita DIGITAL na etapa correta (exp_cnh)', str_contains($rDigital, '"sucesso":true') && str_contains($rDigital, '"cnh_modo_captura":"DIGITAL"') && !preg_match('/HTTP_CODE:[45]\d\d/', $rDigital));

    $atADepoisDigital = $atendimentoDao->buscarPorId($idAtA);
    afirmar('(a) cnh_modo_captura gravado como DIGITAL', $atADepoisDigital['cnh_modo_captura'] === 'DIGITAL');

    // IDOR: outro totem nao consegue definir o modo de um atendimento alheio
    $rIdor = dispararSubprocesso($scriptDispatcher, 'definir-modo-cnh', $idTotemInvasor, ['id_atendimento' => $idAtA, 'modo' => 'FISICA']);
    afirmar('(a) totem invasor nao consegue definir o modo de atendimento alheio (404)', str_contains($rIdor, 'HTTP_CODE:404'));
    $atAAposIdor = $atendimentoDao->buscarPorId($idAtA);
    afirmar('(a) tentativa do invasor nao alterou cnh_modo_captura (continua DIGITAL)', $atAAposIdor['cnh_modo_captura'] === 'DIGITAL');

    // etapa errada -- atendimento ainda na etapa 'placa' (recem criado, sem
    // atualizarEtapa) nao pode definir o modo
    $atEtapaErrada = $atendimentoDao->buscarPorId($atendimentoDao->criar($idTotem, 'expedicao', 'DIG0002'));
    $rEtapaErrada = dispararSubprocesso($scriptDispatcher, 'definir-modo-cnh', $idTotem, ['id_atendimento' => (int) $atEtapaErrada['id_atendimento'], 'modo' => 'DIGITAL']);
    afirmar('(a) definirModoCnh bloqueado fora da etapa exp_cnh', str_contains($rEtapaErrada, 'HTTP_CODE:400'));

    // ============================================================
    // (b) upload() — modo DIGITAL exige so a frente; FISICA/NULL exige os 2
    // ============================================================

    $pastaA = 'teste_cnh_digital_' . bin2hex(random_bytes(4));
    $pastasStorageLimpar[] = $pastaA;
    $atendimentoDao->definirPasta($idAtA, $pastaA);
    @mkdir("{$storagePath}/{$pastaA}", 0750, true);

    $jpegBase64 = 'data:image/jpeg;base64,' . base64_encode(
        base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=')
    );

    // Modo DIGITAL: upload so com imagem_frente (sem imagem_verso) e aceito.
    $rUploadDigital = dispararSubprocesso($scriptDispatcher, 'upload', $idTotem, [
        'id_atendimento' => $idAtA,
        'tipo' => 'cnh',
        'imagem_frente' => $jpegBase64,
    ]);
    afirmar('(b) upload em modo DIGITAL aceita so imagem_frente (sem imagem_verso)', str_contains($rUploadDigital, '"sucesso":true'));
    afirmar('(b) cnh_frente.jpg gravado em disco', is_file("{$storagePath}/{$pastaA}/cnh_frente.jpg"));
    afirmar('(b) cnh_verso.jpg NUNCA gravado em modo DIGITAL (nao foi enviado, nao existe)', !is_file("{$storagePath}/{$pastaA}/cnh_verso.jpg"));

    // Reflection: arquivosCnhCompletosParaModo() considera completo so com a frente
    afirmar('(b) arquivosCnhCompletosParaModo(): DIGITAL com so a frente presente = completo (true)', $refArquivos->invoke($controller, $atendimentoDao->buscarPorId($idAtA)) === true);

    // Novo atendimento em modo FISICA (explicito) -- upload so com a frente
    // continua INSUFICIENTE (mesmo comportamento de antes desta mudanca).
    $atB = novoAtendimentoExp($atendimentoDao, $idTotem, 'DIG0003');
    $idAtB = (int) $atB['id_atendimento'];
    dispararSubprocesso($scriptDispatcher, 'definir-modo-cnh', $idTotem, ['id_atendimento' => $idAtB, 'modo' => 'FISICA']);
    $pastaB = 'teste_cnh_digital_' . bin2hex(random_bytes(4));
    $pastasStorageLimpar[] = $pastaB;
    $atendimentoDao->definirPasta($idAtB, $pastaB);
    @mkdir("{$storagePath}/{$pastaB}", 0750, true);

    $rUploadFisicaSoFrente = dispararSubprocesso($scriptDispatcher, 'upload', $idTotem, [
        'id_atendimento' => $idAtB,
        'tipo' => 'cnh',
        'imagem_frente' => $jpegBase64,
    ]);
    afirmar('(b) upload em modo FISICA (explicito) rejeita frente sozinha, sem verso (obrigatorias)', str_contains($rUploadFisicaSoFrente, '"sucesso":false') && str_contains($rUploadFisicaSoFrente, 'HTTP_CODE:400'));
    afirmar('(b) nenhum arquivo gravado quando o upload FISICA e rejeitado por incompletude', !is_file("{$storagePath}/{$pastaB}/cnh_frente.jpg"));

    $rUploadFisicaCompleto = dispararSubprocesso($scriptDispatcher, 'upload', $idTotem, [
        'id_atendimento' => $idAtB,
        'tipo' => 'cnh',
        'imagem_frente' => $jpegBase64,
        'imagem_verso' => $jpegBase64,
    ]);
    afirmar('(b) upload em modo FISICA com frente+verso e aceito (regressao, comportamento identico ao de antes)', str_contains($rUploadFisicaCompleto, '"sucesso":true'));
    afirmar('(b) arquivosCnhCompletosParaModo(): FISICA com os 2 lados presentes = completo (true)', $refArquivos->invoke($controller, $atendimentoDao->buscarPorId($idAtB)) === true);

    // ============================================================
    // (c) montarPdfCnh() — 1 pagina para DIGITAL, 2 paginas para FISICA
    // (regressao)
    // ============================================================

    $pdfDigital = $refPdf->invoke($controller, $atendimentoDao->buscarPorId($idAtA));
    afirmar('(c) PDF gerado em modo DIGITAL comeca com assinatura %PDF-', str_starts_with($pdfDigital, '%PDF-'));
    afirmar('(c) PDF gerado em modo DIGITAL tem EXATAMENTE 1 pagina', preg_match_all('/\/Type\s*\/Page[^s]/', $pdfDigital) === 1);

    $pdfFisica = $refPdf->invoke($controller, $atendimentoDao->buscarPorId($idAtB));
    afirmar('(c) PDF gerado em modo FISICA continua com EXATAMENTE 2 paginas (regressao)', preg_match_all('/\/Type\s*\/Page[^s]/', $pdfFisica) === 2);

    // ============================================================
    // (d) avaliarResultadoVioApiBrCnh() — CORRIGIDO 2026-09-28 (correcao de
    // contrato real, ver docs/handoffs/2026-09-25-migracao-vio-api-br-com-
    // cache.md): a exigencia de pages_processed/total_pages foi REMOVIDA do
    // criterio de aprovacao da CNH (a API real nunca devolve esses campos —
    // vieram NULL no teste real). O item (d) original desta suite testava
    // exatamente essa exigencia (dinamica por cnh_modo_captura); agora
    // testa o OPOSTO — confirma que pages_processed/total_pages, presentes
    // ou NULL, consistentes ou nao, NUNCA bloqueiam mais a aprovacao, seja
    // qual for o cnh_modo_captura. cnh_modo_captura continua controlando
    // SOMENTE arquivosCnhCompletosParaModo()/montarPdfCnh() (itens b/c
    // acima, intocados). Nomes de campo de dados_leitura tambem corrigidos
    // para as chaves REAIS confirmadas ('Nome'/'CPF'/'Validade').
    // ============================================================

    function resultadoCnhBase(array $overrides = []): array
    {
        return array_replace([
            'ok' => true, 'ambiguo' => false, 'nao_encontrado' => false,
            'estado_leitura' => 'completed', 'qr_type' => 'vio',
            'dados_leitura' => ['Nome' => 'FULANO DIGITAL DE TAL', 'CPF' => '111.444.777-35', 'Validade' => '31/12/2030'],
            'estado_comparacao' => 'completed',
            'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => ['nome' => 'match', 'cpf' => 'match', 'data_validade' => 'match']],
            'pages_processed' => null, 'total_pages' => null,
        ], $overrides);
    }

    // d.1 — DIGITAL com pages_processed/total_pages NULL (comportamento REAL
    // confirmado do fornecedor): APROVADA
    $atDigOk = $atendimentoDao->buscarPorId($idAtA); // ja em modo DIGITAL
    $avalDigOk = $documentoRn->avaliarResultadoVioApiBrCnh($atDigOk, resultadoCnhBase());
    afirmar('(d) CNH DIGITAL com pages_processed/total_pages NULL (real) + reliable=true/mismatched=0: APROVADA', $avalDigOk['pode_avancar'] === true);

    // d.2 — DIGITAL com pages_processed=2/total_pages=2 (como se fosse
    // fisica): tambem APROVADA agora -- contagem de paginas nao e mais
    // avaliada de jeito nenhum, independente do valor.
    $avalDigCom2 = $documentoRn->avaliarResultadoVioApiBrCnh($atDigOk, resultadoCnhBase(['pages_processed' => 2, 'total_pages' => 2]));
    afirmar('(d) CNH DIGITAL com pages_processed=2/total_pages=2 tambem e aprovada (exigencia de paginas removida, nao discrimina mais por modo)', $avalDigCom2['pode_avancar'] === true);

    // d.3 — DIGITAL com total_pages=2 mas pages_processed=1 (valor
    // inconsistente/parcial): tambem APROVADA
    $avalDigParcial = $documentoRn->avaliarResultadoVioApiBrCnh($atDigOk, resultadoCnhBase(['pages_processed' => 1, 'total_pages' => 2]));
    afirmar('(d) CNH DIGITAL com contagem de paginas inconsistente tambem e aprovada (nao bloqueia mais)', $avalDigParcial['pode_avancar'] === true);

    // d.4 — FISICA (explicito) com pages_processed=1/total_pages=1: tambem
    // APROVADA agora (regressao pontual da mudanca -- antes exigia 2/2)
    $atFisOk = $atendimentoDao->buscarPorId($idAtB); // ja em modo FISICA
    $avalFisCom1 = $documentoRn->avaliarResultadoVioApiBrCnh($atFisOk, resultadoCnhBase(['pages_processed' => 1, 'total_pages' => 1]));
    afirmar('(d) CNH FISICA com pages_processed=1/total_pages=1 tambem e aprovada (exigencia de paginas removida tambem para FISICA)', $avalFisCom1['pode_avancar'] === true);

    // d.5 — NULL (nunca definido, fail-safe=FISICA para efeito de
    // arquivosCnhCompletosParaModo/montarPdfCnh) com pages_processed/
    // total_pages NULL ou preenchidos: sempre APROVADA (mesmo criterio,
    // cnh_modo_captura NUNCA mais influencia a aprovacao)
    $atNulo = novoAtendimentoExp($atendimentoDao, $idTotem, 'DIG0004');
    afirmar('(d) pre-condicao: cnh_modo_captura NULL (nao definido)', $atNulo['cnh_modo_captura'] === null);
    $avalNuloComNull = $documentoRn->avaliarResultadoVioApiBrCnh($atNulo, resultadoCnhBase());
    afirmar('(d) cnh_modo_captura NULL com pages_processed/total_pages NULL (real): APROVADA', $avalNuloComNull['pode_avancar'] === true);
    $avalNuloCom2 = $documentoRn->avaliarResultadoVioApiBrCnh($atNulo, resultadoCnhBase(['pages_processed' => 2, 'total_pages' => 2]));
    afirmar('(d) cnh_modo_captura NULL com pages_processed=2/total_pages=2: APROVADA (contagem de paginas irrelevante para a aprovacao)', $avalNuloCom2['pode_avancar'] === true);

    // d.6 — CNH vencida (Validade no formato real DD/MM/AAAA, no passado) e
    // corretamente REJEITADA -- confirma que normalizarData()/dataVencida()
    // ja tratam DD/MM/AAAA corretamente (nenhuma mudanca de codigo foi
    // necessaria para o formato de data em si, so para o NOME da chave).
    $avalVencida = $documentoRn->avaliarResultadoVioApiBrCnh($atNulo, resultadoCnhBase(['dados_leitura' => ['Nome' => 'FULANO VENCIDO DE TAL', 'CPF' => '111.444.777-35', 'Validade' => '01/01/2020']]));
    afirmar('(d) CNH com Validade (DD/MM/AAAA real) vencida NUNCA e aprovada automaticamente, mesmo com reliable=true/mismatched=0', $avalVencida['pode_avancar'] === false);

    // ============================================================
    // (e) cnh_modo_captura=NULL + so a frente presente -> gate ainda
    // bloqueia esperando o verso (fail-safe preservado)
    //
    // Atendimento NOVO e proprio deste cenario (nunca $atNulo do item (d) --
    // aquele ja foi aprovado de verdade pela chamada d.5 acima, que persiste
    // a aprovacao em tb_atendimento; reaproveita-lo aqui faria
    // iniciarProcessamento() responder pelo caminho idempotente "ja
    // aprovado", nunca chegando ao gate sob teste).
    // ============================================================

    $atNuloE = novoAtendimentoExp($atendimentoDao, $idTotem, 'DIG0005');
    afirmar('(e) pre-condicao: novo atendimento com cnh_modo_captura NULL (nao definido)', $atNuloE['cnh_modo_captura'] === null);

    $pastaNulo = 'teste_cnh_digital_' . bin2hex(random_bytes(4));
    $pastasStorageLimpar[] = $pastaNulo;
    $atendimentoDao->definirPasta((int) $atNuloE['id_atendimento'], $pastaNulo);
    @mkdir("{$storagePath}/{$pastaNulo}", 0750, true);
    cnhDigitalCriarJpegValido("{$storagePath}/{$pastaNulo}/cnh_frente.jpg");
    // cnh_verso.jpg NUNCA criado neste cenario -- exatamente o caso "so a
    // frente presente" exigido pelo item (e).

    $atNuloComFrente = $atendimentoDao->buscarPorId((int) $atNuloE['id_atendimento']);
    afirmar('(e) arquivosCnhCompletosParaModo(): NULL (fail-safe=FISICA) com so a frente presente = INCOMPLETO (false)', $refArquivos->invoke($controller, $atNuloComFrente) === false);

    $rIniciarProcessamentoBloqueado = dispararSubprocesso($scriptDispatcher, 'iniciar-processamento', $idTotem, [
        'id_atendimento' => (int) $atNuloE['id_atendimento'],
        'tipo' => 'cnh',
        'qr_bytes_base64' => base64_encode(random_bytes(40)),
    ]);
    afirmar('(e) iniciarProcessamento() com cnh_modo_captura=NULL e so a frente presente: BLOQUEADO (HTTP 409, gate antes do CAS)', str_contains($rIniciarProcessamentoBloqueado, 'HTTP_CODE:409') && str_contains($rIniciarProcessamentoBloqueado, 'Aguardando captura de frente e verso'));

    $atNuloAposBloqueio = $atendimentoDao->buscarPorId((int) $atNuloE['id_atendimento']);
    afirmar('(e) status_processamento da CNH permanece PENDENTE apos o bloqueio (nunca consumiu uma tentativa/CAS)', $atNuloAposBloqueio['cnh_status_processamento'] === 'PENDENTE');
} finally {
    foreach ($pastasStorageLimpar as $pastaLimpar) {
        $caminhoPasta = rtrim($_ENV['STORAGE_PATH'] ?? '', '/') . '/' . $pastaLimpar;
        if (is_dir($caminhoPasta)) {
            foreach (glob($caminhoPasta . '/*') as $arquivo) {
                @unlink($arquivo);
            }
            @rmdir($caminhoPasta);
        }
    }

    if ($nomeBanco !== null) {
        qaDbDropar($nomeBanco);
        echo "\n(banco de teste {$nomeBanco} dropado)\n";
    }
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
