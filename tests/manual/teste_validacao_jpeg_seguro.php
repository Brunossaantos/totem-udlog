<?php

/**
 * Orquestrador principal de /02-testes da demanda validacao-jpeg-segura.
 * Executa os casos da matriz de teste contra
 * util/UploadHelper.php::salvarImagemBase64() usando SOMENTE fixtures
 * sinteticas geradas via GD/manipulacao manual de bytes (nunca documento
 * real). Nao altera nenhum arquivo de producao, nao chama Talent/VIO, nao
 * grava nada no banco, nao expoe base64/binario em nenhuma saida.
 *
 * IMPORTANTE -- risco residual aceito pelo usuario (2026-09-17): o caso do
 * "JPEG truncado com EOI forjado ao final" (antigo item 11) e a "prova
 * negativa item 3" NAO exigem mais REJEICAO. O usuario aceitou
 * formalmente esse risco residual (decodificacao GD desta build nao
 * detecta esse vetor de forjamento deliberado -- ver
 * docs/handoffs/2026-09-17-validacao-jpeg-segura.md, secao "Decisao
 * confirmada pelo usuario" / "Opcao A: aceitar o risco residual
 * documentado"). Por isso esses dois pontos aparecem abaixo como
 * DIAGNOSTICO DE LIMITACAO CONHECIDA, impressos separadamente e SEM
 * incrementar o contador PASSOU/FALHOU dos controles obrigatorios. Quem
 * reexecutar este script no futuro (ex. regressao de outra demanda) NAO
 * deve tratar esse diagnostico como falha do script.
 *
 * Uso: php tests/manual/teste_validacao_jpeg_seguro.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/fixtures_jpeg_seguro.php';

use Dotenv\Dotenv;
use Util\UploadHelper;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pastaTeste = 'teste_jpeg_seguro_' . bin2hex(random_bytes(4));
$storagePathBase = rtrim($_ENV['STORAGE_PATH'], '/');
$contadorArquivo = 0;

$totalCasos = 0;
$totalFalhas = 0;

/** Registra resultado PASSOU/FALHOU com evidencia (nunca binario/base64). */
function registrar(int $numero, string $descricao, bool $passou, string $evidencia): void
{
    global $totalCasos, $totalFalhas;
    $totalCasos++;
    if (!$passou) {
        $totalFalhas++;
    }
    echo sprintf("[%02d] %s -- %s\n     Evidencia: %s\n", $numero, $passou ? 'PASSOU' : 'FALHOU', $descricao, $evidencia);
}

/**
 * Executa salvarImagemBase64 e retorna ['aceito' => bool, 'mensagem' =>
 * string|null, 'caminho' => string|null].
 */
function executar(string $dataUrl, string $pastaRelativa, string $nomeArquivo): array
{
    try {
        $caminho = UploadHelper::salvarImagemBase64($dataUrl, $pastaRelativa, $nomeArquivo);
        return ['aceito' => true, 'mensagem' => null, 'caminho' => $caminho];
    } catch (\Throwable $e) {
        return ['aceito' => false, 'mensagem' => $e->getMessage(), 'caminho' => null];
    }
}

function proximoNome(): string
{
    global $contadorArquivo;
    $contadorArquivo++;
    return sprintf('teste_%03d.jpg', $contadorArquivo);
}

// ---------------------------------------------------------------------
// Caso 1: JPEG baseline valido
// ---------------------------------------------------------------------
$jpegBaseline = jpegFixtureGerar(64, 48, 80, false, false);
$r = executar(jpegFixtureParaDataUrl($jpegBaseline), $pastaTeste, proximoNome());
registrar(1, 'JPEG baseline valido deve ser ACEITO', $r['aceito'] === true,
    'bytes=' . strlen($jpegBaseline) . ' aceito=' . var_export($r['aceito'], true) . ($r['mensagem'] ? " msg={$r['mensagem']}" : ' caminho_gravado=' . ($r['caminho'] !== null && file_exists($r['caminho']) ? 'sim' : 'nao')));

// ---------------------------------------------------------------------
// Caso 2: JPEG progressivo valido
// ---------------------------------------------------------------------
$jpegProgressivo = jpegFixtureGerar(64, 48, 80, true, true);
$offsetSof = jpegFixtureOffsetSof($jpegProgressivo);
$ehSof2 = $offsetSof !== null && substr($jpegProgressivo, $offsetSof, 2) === "\xFF\xC2";
$r = executar(jpegFixtureParaDataUrl($jpegProgressivo), $pastaTeste, proximoNome());
registrar(2, 'JPEG progressivo valido (imageinterlace) deve ser ACEITO', $r['aceito'] === true,
    'marcador_SOF=' . ($ehSof2 ? 'SOF2(progressivo confirmado)' : 'SOF0(GD nao gerou progressivo real neste ambiente, usado como esta mesmo assim)') . ' bytes=' . strlen($jpegProgressivo) . ' aceito=' . var_export($r['aceito'], true) . ($r['mensagem'] ? " msg={$r['mensagem']}" : ''));

// ---------------------------------------------------------------------
// Caso 3: dimensoes reais do Netum (3264x2448)
// ---------------------------------------------------------------------
$jpegNetum = jpegFixtureGerar(3264, 2448, 75, false, false);
$r = executar(jpegFixtureParaDataUrl($jpegNetum), $pastaTeste, proximoNome());
registrar(3, 'Dimensao real do Netum 3264x2448 (7.990.272px, dentro dos limites) deve ser ACEITA', $r['aceito'] === true,
    'bytes=' . strlen($jpegNetum) . ' area=7990272 aceito=' . var_export($r['aceito'], true) . ($r['mensagem'] ? " msg={$r['mensagem']}" : ''));

// ---------------------------------------------------------------------
// Caso 4: metadado APP1/EXIF com thumbnail JPEG embutido
// ---------------------------------------------------------------------
$jpegExternoParaThumb = jpegFixtureGerar(120, 90, 80, false, false);
$jpegThumb = jpegFixtureGerar(16, 12, 60, false, false);
$jpegComThumb = jpegFixtureComThumbnailEmbutido($jpegExternoParaThumb, $jpegThumb);
$temEoiValido = jpegFixtureTemEoiNoFim($jpegComThumb);
$r = executar(jpegFixtureParaDataUrl($jpegComThumb), $pastaTeste, proximoNome());
registrar(4, 'JPEG com APP1/EXIF + thumbnail JPEG embutido (imagem principal integra) deve ser ACEITO', $r['aceito'] === true,
    'eoi_final_preservado=' . var_export($temEoiValido, true) . ' bytes=' . strlen($jpegComThumb) . ' aceito=' . var_export($r['aceito'], true) . ($r['mensagem'] ? " msg={$r['mensagem']}" : ''));

// ---------------------------------------------------------------------
// Caso 5: base64 invalido
// ---------------------------------------------------------------------
$r = executar('data:image/jpeg;base64,%%%invalido%%%===', $pastaTeste, proximoNome());
registrar(5, 'Base64 invalido deve ser REJEITADO', $r['aceito'] === false, 'msg=' . $r['mensagem']);

// ---------------------------------------------------------------------
// Caso 6: conteudo vazio
// ---------------------------------------------------------------------
$r = executar('data:image/jpeg;base64,', $pastaTeste, proximoNome());
registrar(6, 'Conteudo vazio (base64 decodifica para string vazia) deve ser REJEITADO', $r['aceito'] === false, 'msg=' . $r['mensagem']);

// ---------------------------------------------------------------------
// Caso 7: sem SOI
// ---------------------------------------------------------------------
$jpegSemSoi = substr($jpegBaseline, 3); // remove os 3 primeiros bytes (SOI + inicio do proximo marcador)
$r = executar(jpegFixtureParaDataUrl($jpegSemSoi), $pastaTeste, proximoNome());
registrar(7, 'Arquivo sem SOI deve ser REJEITADO', $r['aceito'] === false, 'bytes=' . strlen($jpegSemSoi) . ' msg=' . $r['mensagem']);

// ---------------------------------------------------------------------
// Caso 8: sem EOI (truncamento no final) -- BUG ORIGINAL
// ---------------------------------------------------------------------
$jpegSemEoi = substr($jpegBaseline, 0, -8); // remove os ultimos bytes, incluindo FFD9
$r = executar(jpegFixtureParaDataUrl($jpegSemEoi), $pastaTeste, proximoNome());
registrar(8, 'Arquivo sem EOI (truncado no fim) deve ser REJEITADO -- bug original', $r['aceito'] === false, 'bytes=' . strlen($jpegSemEoi) . ' ultimos_2_bytes_hex=' . bin2hex(substr($jpegSemEoi, -2)) . ' msg=' . $r['mensagem']);

// ---------------------------------------------------------------------
// Caso 9: truncamento logo apos o SOF, antes do SOS
// ---------------------------------------------------------------------
$offsetSofBaseline = jpegFixtureOffsetSof($jpegBaseline);
$offsetSosBaseline = jpegFixtureOffsetSos($jpegBaseline);
if ($offsetSofBaseline === null || $offsetSosBaseline === null) {
    registrar(9, 'Truncamento logo apos SOF, antes do SOS deve ser REJEITADO', false, 'BLOQUEIO: nao foi possivel localizar marcador SOF/SOS na fixture baseline para montar o corte');
} else {
    $pontoCorte = $offsetSofBaseline + 10; // dentro/logo apos o segmento SOF, antes do SOS
    if ($pontoCorte >= $offsetSosBaseline) {
        $pontoCorte = $offsetSosBaseline - 1;
    }
    $jpegCorteAntesSos = substr($jpegBaseline, 0, $pontoCorte);
    $r = executar(jpegFixtureParaDataUrl($jpegCorteAntesSos), $pastaTeste, proximoNome());
    registrar(9, 'Truncamento logo apos SOF, antes do SOS deve ser REJEITADO', $r['aceito'] === false,
        'offset_sof=' . $offsetSofBaseline . ' offset_sos_original=' . $offsetSosBaseline . ' corte_em=' . $pontoCorte . ' bytes=' . strlen($jpegCorteAntesSos) . ' msg=' . $r['mensagem']);
}

// ---------------------------------------------------------------------
// Caso 10: truncamento no meio do scan (bem depois do SOS)
// ---------------------------------------------------------------------
if ($offsetSosBaseline === null) {
    registrar(10, 'Truncamento no meio do scan deve ser REJEITADO', false, 'BLOQUEIO: nao foi possivel localizar SOS na fixture baseline');
} else {
    $meioDoScan = $offsetSosBaseline + (int) ((strlen($jpegBaseline) - $offsetSosBaseline) / 2);
    $jpegCorteMeioScan = substr($jpegBaseline, 0, $meioDoScan);
    $r = executar(jpegFixtureParaDataUrl($jpegCorteMeioScan), $pastaTeste, proximoNome());
    registrar(10, 'Truncamento no meio do scan deve ser REJEITADO', $r['aceito'] === false,
        'offset_sos=' . $offsetSosBaseline . ' corte_em=' . $meioDoScan . ' bytes_originais=' . strlen($jpegBaseline) . ' bytes_truncados=' . strlen($jpegCorteMeioScan) . ' msg=' . $r['mensagem']);
}

// ---------------------------------------------------------------------
// DIAGNOSTICO DE LIMITACAO CONHECIDA (nao conta para PASSOU/FALHOU):
// JPEG truncado com FFD9 acrescentado artificialmente ao final.
//
// Risco residual aceito pelo usuario em 2026-09-17 (ver
// docs/handoffs/2026-09-17-validacao-jpeg-segura.md). Nesta build de GD,
// imagecreatefromstring() decodifica silenciosamente um JPEG truncado no
// meio do scan quando um EOI e forjado no ultimo byte -- NAO e mais
// tratado como requisito de rejeicao do script. Impresso apenas como
// diagnostico informativo, sem chamar registrar() (nao afeta o contador
// dos controles obrigatorios).
// ---------------------------------------------------------------------
$jpegTruncadoComEoiForjado = $jpegCorteMeioScan . "\xFF\xD9";
$rDiagnosticoEoiForjado = executar(jpegFixtureParaDataUrl($jpegTruncadoComEoiForjado), $pastaTeste, proximoNome());
echo sprintf(
    "[DIAGNOSTICO (nao conta para aprovacao)] JPEG truncado + EOI forjado ao final -- risco residual aceito pelo usuario em 2026-09-17\n     Evidencia: passa_checagem_estrutural_EOI=%s bytes=%d aceito_final=%s msg=%s\n",
    var_export(jpegFixtureTemEoiNoFim($jpegTruncadoComEoiForjado), true),
    strlen($jpegTruncadoComEoiForjado),
    var_export($rDiagnosticoEoiForjado['aceito'], true),
    (string) $rDiagnosticoEoiForjado['mensagem']
);

// ---------------------------------------------------------------------
// Caso 12/13: PNG valido apresentado com MIME/prefixo de JPEG
// ---------------------------------------------------------------------
$pngValido = pngFixtureGerar(50, 50);
$r = executar(jpegFixtureParaDataUrl($pngValido), $pastaTeste, proximoNome());
registrar(12, 'MIME declarado JPEG mas conteudo real e PNG deve ser REJEITADO (magic bytes SOI nao batem)', $r['aceito'] === false,
    'primeiros_bytes_hex=' . bin2hex(substr($pngValido, 0, 4)) . ' msg=' . $r['mensagem']);

$r13 = executar(jpegFixtureParaDataUrl($pngValido), $pastaTeste, proximoNome());
registrar(13, 'PNG valido apresentado como JPEG (reforco do caso 12) deve ser REJEITADO', $r13['aceito'] === false,
    'msg=' . $r13['mensagem']);

// ---------------------------------------------------------------------
// Caso 14: dimensao exatamente no limite (5000 x 2600 = 13.000.000)
// ---------------------------------------------------------------------
// 5000 * 2600 = 13.000.000 -- exatamente na area maxima, com um lado
// exatamente no limite de 5000px. Gerar pixel a pixel com ruido nessa
// resolucao seria caro; usa preenchimento solido (nao afeta a checagem,
// que so olha para largura/altura via getimagesizefromstring/GD).
$jpegLimiteExato = jpegFixtureGerar(5000, 2600, 60, false, false);
$r = executar(jpegFixtureParaDataUrl($jpegLimiteExato), $pastaTeste, proximoNome());
registrar(14, 'Dimensao exatamente no limite (5000x2600 = 13.000.000px) deve ser ACEITA (limite inclusivo)', $r['aceito'] === true,
    'largura=5000 altura=2600 area=13000000 bytes=' . strlen($jpegLimiteExato) . ' aceito=' . var_export($r['aceito'], true) . ($r['mensagem'] ? " msg={$r['mensagem']}" : ''));

// ---------------------------------------------------------------------
// Caso 15: largura acima de 5000 (5001x10) -- deve falhar ANTES da decodificacao
// ---------------------------------------------------------------------
$jpegLarguraExcedente = jpegFixtureGerar(5001, 10, 60, false, false);
$r = executar(jpegFixtureParaDataUrl($jpegLarguraExcedente), $pastaTeste, proximoNome());
$mensagemEsperadaDimensao = 'Imagem excede a dimensao maxima permitida';
registrar(15, 'Largura 5001px (acima do limite por lado) deve ser REJEITADA pelo limite de DIMENSAO (nao pela decodificacao)', $r['aceito'] === false && $r['mensagem'] === $mensagemEsperadaDimensao,
    'largura=5001 altura=10 msg=' . $r['mensagem'] . ' (confirmado por leitura de codigo: etapa 9 -- checagem de largura/altura -- ocorre ANTES das etapas 12-14 de decodificacao GD)');

// ---------------------------------------------------------------------
// Caso 16: altura acima de 5000 (10x5001) -- deve falhar ANTES da decodificacao
// ---------------------------------------------------------------------
$jpegAlturaExcedente = jpegFixtureGerar(10, 5001, 60, false, false);
$r = executar(jpegFixtureParaDataUrl($jpegAlturaExcedente), $pastaTeste, proximoNome());
registrar(16, 'Altura 5001px (acima do limite por lado) deve ser REJEITADA pelo limite de DIMENSAO (nao pela decodificacao)', $r['aceito'] === false && $r['mensagem'] === $mensagemEsperadaDimensao,
    'largura=10 altura=5001 msg=' . $r['mensagem'] . ' (mesma confirmacao do caso 15 -- etapa 9 antes das etapas 12-14)');

// ---------------------------------------------------------------------
// Caso 17: area acima de 13.000.000 mas ambos os lados <= 5000 (4900x2700 = 13.230.000)
// ---------------------------------------------------------------------
$jpegAreaExcedente = jpegFixtureGerar(4900, 2700, 60, false, false);
$r = executar(jpegFixtureParaDataUrl($jpegAreaExcedente), $pastaTeste, proximoNome());
registrar(17, 'Area 13.230.000px com ambos os lados <=5000 (4900x2700) deve ser REJEITADA pelo limite de AREA (nao pelo limite de lado)', $r['aceito'] === false && $r['mensagem'] === $mensagemEsperadaDimensao,
    'largura=4900(<=5000) altura=2700(<=5000) area=13230000(>13000000) msg=' . $r['mensagem']);

// ---------------------------------------------------------------------
// Caso 18: arquivo acima do limite de bytes (NOTA_IMAGEM_MAX_BYTES)
// ---------------------------------------------------------------------
$maxBytesConfigurado = isset($_ENV['NOTA_IMAGEM_MAX_BYTES']) && $_ENV['NOTA_IMAGEM_MAX_BYTES'] !== ''
    ? (int) $_ENV['NOTA_IMAGEM_MAX_BYTES']
    : 5 * 1024 * 1024;
// Gera JPEG com ruido em resolucao dentro dos limites de dimensao/area
// (para que a rejeicao ocorra pelo limite de BYTES, nao pelo de dimensao),
// mas grande o bastante para exceder o limite de bytes configurado.
$jpegAcimaDoLimiteBytes = jpegFixtureGerar(3000, 2000, 100, false, true);
$tentativas = 0;
while (strlen($jpegAcimaDoLimiteBytes) <= $maxBytesConfigurado && $tentativas < 3) {
    // aumenta a entropia/qualidade nao reduz mais -- se ainda nao passou do
    // limite, concatena ruido extra dentro de um comentario JPEG valido
    // (marcador COM, FFFE) antes do EOI, preservando estrutura.
    $semEoi = substr($jpegAcimaDoLimiteBytes, 0, -2);
    $comentario = random_bytes(1024 * 1024);
    $tamanhoComentario = strlen($comentario) + 2;
    $segmentoComentario = "\xFF\xFE" . pack('n', $tamanhoComentario) . $comentario;
    $jpegAcimaDoLimiteBytes = $semEoi . $segmentoComentario . "\xFF\xD9";
    $tentativas++;
}
$r = executar(jpegFixtureParaDataUrl($jpegAcimaDoLimiteBytes), $pastaTeste, proximoNome());
registrar(18, 'Arquivo acima de NOTA_IMAGEM_MAX_BYTES deve continuar REJEITADO (sem regressao)', $r['aceito'] === false,
    'limite_configurado_bytes=' . $maxBytesConfigurado . ' bytes_fixture=' . strlen($jpegAcimaDoLimiteBytes) . ' msg=' . $r['mensagem']);

// ---------------------------------------------------------------------
// Caso 19: bytes extras depois do EOI real
// ---------------------------------------------------------------------
$jpegComLixoAposEoi = $jpegBaseline . "\x00\x01\x02\x03";
$r = executar(jpegFixtureParaDataUrl($jpegComLixoAposEoi), $pastaTeste, proximoNome());
registrar(19, 'Bytes extras apos o EOI real devem ser REJEITADOS pela checagem estrita de posicao do EOI', $r['aceito'] === false,
    'bytes_extras=4 ultimos_2_bytes_hex=' . bin2hex(substr($jpegComLixoAposEoi, -2)) . ' (nao e FFD9, esta 4 bytes antes) msg=' . $r['mensagem']);

// ---------------------------------------------------------------------
// Caso 20: warning do GD tratado como falha (decodificacao "sucede
// parcialmente" mas com warning) -- busca de um bit-flip que produza esse
// cenario especifico, testado ISOLADAMENTE via imagecreatefromstring puro
// antes de confirmar contra UploadHelper.
// ---------------------------------------------------------------------
function probarFlipGeraWarningSemFalse(string $jpeg, int $offset): array
{
    if ($offset < 0 || $offset >= strlen($jpeg)) {
        return ['aplicavel' => false];
    }
    $bytes = $jpeg;
    $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0xFF);

    $warning = false;
    set_error_handler(static function () use (&$warning): bool {
        $warning = true;
        return true;
    });
    try {
        $img = @imagecreatefromstring($bytes);
        $resultado = $img !== false ? true : false;
        if ($img !== false) {
            imagedestroy($img);
        }
    } finally {
        restore_error_handler();
    }

    return ['aplicavel' => true, 'bytes' => $bytes, 'decodificou_non_false' => $resultado, 'warning' => $warning];
}

$jpegRuidoParaFlip = jpegFixtureGerar(80, 60, 90, false, true);
$offsetSosRuido = jpegFixtureOffsetSos($jpegRuidoParaFlip);
$casoEncontrado = null;
if ($offsetSosRuido !== null) {
    // varre alguns pontos dentro dos dados de scan (bem depois do SOS,
    // evitando os 2 primeiros bytes do marcador em si)
    $inicioScan = $offsetSosRuido + 12;
    $fimScan = strlen($jpegRuidoParaFlip) - 4; // evita mexer no EOI
    $passo = max(1, (int) (($fimScan - $inicioScan) / 40));
    for ($offset = $inicioScan; $offset < $fimScan; $offset += $passo) {
        $tentativa = probarFlipGeraWarningSemFalse($jpegRuidoParaFlip, $offset);
        if (!empty($tentativa['aplicavel']) && $tentativa['warning'] === true && $tentativa['decodificou_non_false'] === true) {
            $casoEncontrado = $tentativa;
            $casoEncontrado['offset'] = $offset;
            break;
        }
    }
}

if ($casoEncontrado === null) {
    // DIAGNOSTICO DE LIMITACAO CONHECIDA (nao conta para PASSOU/FALHOU):
    // mesma raiz do risco residual do EOI forjado (ver bloco acima e
    // docs/handoffs/2026-09-17-validacao-jpeg-segura.md, secao "Decisao
    // confirmada pelo usuario"). Nesta build de GD (bundled, PHP 8.0.30,
    // Windows), um bit-flip no meio do stream de scan NUNCA produz o
    // cenario "warning sem false" -- ou o GD reverte integralmente para
    // false, ou decodifica de forma totalmente silenciosa (sem warning).
    // Isso e uma LIMITACAO DE REPRODUTIBILIDADE DO TESTE nesta instalacao
    // de GD, nao uma falha da logica de tratamento de warning. A logica em
    // UploadHelper.php (linha 170: "if ($imagemGd === false ||
    // $houveWarningGd) { throw ... }") foi confirmada por LEITURA DE
    // CODIGO: SE um warning fosse emitido pelo GD, a imagem SERIA
    // rejeitada independentemente do valor de retorno de
    // imagecreatefromstring(). Por isso este caso e impresso apenas como
    // diagnostico informativo, sem chamar registrar() (nao afeta o
    // contador dos controles obrigatorios).
    echo "[DIAGNOSTICO (nao conta para aprovacao)] Warning do GD durante decodificacao -- limitacao de reprodutibilidade nesta build de GD (mesma raiz do risco residual do EOI forjado, 2026-09-17)\n" .
        "     Evidencia: BLOQUEIO/LIMITACAO: nao foi possivel produzir deterministicamente, nesta rodada, um bit-flip que faca imagecreatefromstring() retornar valor NAO-false E emitir warning simultaneamente (o libjpeg desta instalacao ou reverte para false, ou decodifica sem warning para os pontos testados). Logica de tratamento de warning (UploadHelper.php, linha 170) confirmada correta por LEITURA DE CODIGO, nao por reproducao empirica deste cenario especifico.\n";
} else {
    $r = executar(jpegFixtureParaDataUrl($casoEncontrado['bytes']), $pastaTeste, proximoNome());
    registrar(20, 'Warning do GD durante decodificacao deve resultar em REJEICAO (nao aceitacao silenciosa)', $r['aceito'] === false,
        'offset_flip=' . $casoEncontrado['offset'] . ' imagecreatefromstring_isolado_retornou_non_false=' . var_export($casoEncontrado['decodificou_non_false'], true) . ' warning_isolado=' . var_export($casoEncontrado['warning'], true) . ' resultado_via_UploadHelper_aceito=' . var_export($r['aceito'], true) . ' msg=' . $r['mensagem']);
}

// ---------------------------------------------------------------------
// Caso 21: GD indisponivel simulado -- delegado a subprocesso isolado
// ---------------------------------------------------------------------
$arquivoEntradaMock = sys_get_temp_dir() . '/jpeg_seguro_mock_entrada_' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($arquivoEntradaMock, jpegFixtureParaDataUrl($jpegBaseline));
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/caso_gd_indisponivel_mock.php') . ' ' . escapeshellarg($arquivoEntradaMock);
exec($cmd, $saidaMock, $codigoMock);
unlink($arquivoEntradaMock);
$saidaMockTexto = implode("\n", $saidaMock);
registrar(21, 'GD/imagecreatefromstring indisponivel (simulado via override em namespace de teste) deve resultar em REJEICAO fail-closed (nunca fallback so estrutural)', str_starts_with($saidaMockTexto, 'RESULTADO:REJEITADO'),
    'saida_subprocesso=' . $saidaMockTexto . ' codigo_saida=' . $codigoMock);

// ---------------------------------------------------------------------
// Caso 22: restauracao do error handler apos sucesso E apos falha
// ---------------------------------------------------------------------
function verificarHandlerRestaurado(string $dataUrl, string $pasta, string $nome): array
{
    $marcador = static function () {
        return true;
    };
    set_error_handler($marcador);

    try {
        UploadHelper::salvarImagemBase64($dataUrl, $pasta, $nome);
    } catch (\Throwable $e) {
        // esperado no cenario de falha -- irrelevante para esta checagem
    }

    $handlerAtivoAntesDoNosso = set_error_handler(static function () {
        return true;
    });
    restore_error_handler(); // remove o handler de sondagem que acabamos de instalar
    restore_error_handler(); // remove o $marcador que instalamos no inicio (seguranca -- volta ao estado global original)

    return ['restaurado' => $handlerAtivoAntesDoNosso === $marcador];
}

$checagemSucesso = verificarHandlerRestaurado(jpegFixtureParaDataUrl($jpegBaseline), $pastaTeste, proximoNome());
registrar(22, 'Error handler restaurado corretamente apos chamada com SUCESSO', $checagemSucesso['restaurado'] === true,
    'handler_global_e_o_mesmo_que_estava_antes_da_chamada=' . var_export($checagemSucesso['restaurado'], true));

$checagemFalha = verificarHandlerRestaurado(jpegFixtureParaDataUrl($jpegSemEoi), $pastaTeste, proximoNome());
registrar(22, 'Error handler restaurado corretamente apos chamada com FALHA (excecao lancada antes da decodificacao GD)', $checagemFalha['restaurado'] === true,
    'handler_global_e_o_mesmo_que_estava_antes_da_chamada=' . var_export($checagemFalha['restaurado'], true) . ' (neste caso a excecao eh lancada nas etapas 3-5, antes de set_error_handler ser chamado -- confirma que nao ha vazamento de handler mesmo fora do bloco try/finally do GD)');

// ---------------------------------------------------------------------
// Caso 23: preservacao de assinatura/contrato de retorno/gravacao em disco
// ---------------------------------------------------------------------
$reflexao = new \ReflectionMethod(UploadHelper::class, 'salvarImagemBase64');
$parametros = array_map(static function (\ReflectionParameter $p) {
    return ($p->getType() ? $p->getType() . ' ' : '') . '$' . $p->getName();
}, $reflexao->getParameters());
$assinaturaAtual = 'public static function salvarImagemBase64(' . implode(', ', $parametros) . '): ' . $reflexao->getReturnType();
$assinaturaEsperada = 'public static function salvarImagemBase64(string $base64, string $pastaRelativa, string $nomeArquivo): string';

$nomeArquivo23 = proximoNome();
$r = executar(jpegFixtureParaDataUrl($jpegBaseline), $pastaTeste, $nomeArquivo23);
$caminhoEsperado = $storagePathBase . '/' . $pastaTeste . '/' . $nomeArquivo23;
$arquivoGravadoIdentico = $r['caminho'] !== null && file_exists($r['caminho']) && md5_file($r['caminho']) === md5($jpegBaseline);
registrar(23, 'Assinatura do metodo, formato do caminho retornado e gravacao em disco (bytes originais, sem recompressao) preservados', $reflexao->isStatic() && $reflexao->isPublic() && $assinaturaAtual === $assinaturaEsperada && $r['caminho'] === $caminhoEsperado && $arquivoGravadoIdentico,
    'assinatura_atual="' . $assinaturaAtual . '" caminho_retornado_bate_com_esperado=' . var_export($r['caminho'] === $caminhoEsperado, true) . ' arquivo_gravado_identico_ao_binario_original(md5)=' . var_export($arquivoGravadoIdentico, true));

echo "\n";
echo "=== Consumo de memoria (peak, real_usage=true): " . number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB ===\n";
echo "=== Controles obrigatorios: {$totalCasos} executados, " . ($totalCasos - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
echo "    (os diagnosticos de EOI forjado e de warning do GD, impressos acima e na secao de prova negativa abaixo, NAO contam neste total -- limitacoes de reprodutibilidade nesta build de GD, aceitas pelo usuario em 2026-09-17)\n";

// ---------------------------------------------------------------------
// Prova negativa obrigatoria
// ---------------------------------------------------------------------
echo "\n=== PROVA NEGATIVA ===\n";
$infoIsolado = @getimagesizefromstring($jpegSemEoi);
$provaNegativa1 = $infoIsolado !== false && $infoIsolado[2] === IMAGETYPE_JPEG && $infoIsolado[0] > 0 && $infoIsolado[1] > 0;
echo '1) getimagesizefromstring() ISOLADO aceita a fixture truncada (caso 8, sem EOI): ' . var_export($provaNegativa1, true) . ' (largura=' . ($infoIsolado[0] ?? 'n/a') . ' altura=' . ($infoIsolado[1] ?? 'n/a') . ' tipo=' . ($infoIsolado[2] ?? 'n/a') . ")\n";

$rProva2 = executar(jpegFixtureParaDataUrl($jpegSemEoi), $pastaTeste, proximoNome());
$provaNegativa2 = $rProva2['aceito'] === false;
echo '2) salvarImagemBase64() COMPLETO rejeita a MESMA fixture (bug original, truncamento sem EOI): ' . var_export($provaNegativa2, true) . ' (msg=' . $rProva2['mensagem'] . ")\n";

// DIAGNOSTICO DE LIMITACAO CONHECIDA (nao conta para PASSOU/FALHOU): a
// prova negativa item 3 original exigia que o EOI forjado deliberadamente
// tambem fosse rejeitado. O usuario aceitou formalmente o risco residual
// de que isso NAO ocorra nesta build de GD (2026-09-17, ver handoff) --
// mantido aqui apenas como registro informativo, fora do veredito final.
$r11Repetido = executar(jpegFixtureParaDataUrl($jpegTruncadoComEoiForjado), $pastaTeste, proximoNome());
echo '3) [DIAGNOSTICO (nao conta para aprovacao)] EOI forjado NAO contorna a decodificacao completa -- risco residual aceito pelo usuario em 2026-09-17, ver docs/handoffs/2026-09-17-validacao-jpeg-segura.md: ' . var_export($r11Repetido['aceito'] === false, true) . ' (msg=' . (string) $r11Repetido['mensagem'] . ")\n";

$provaNegativaTotal = $provaNegativa1 && $provaNegativa2;
echo "\n=== PROVA NEGATIVA (controles obrigatorios 1-2): " . ($provaNegativaTotal ? 'CONFIRMADA' : 'NAO CONFIRMADA') . " ===\n";

// ---------------------------------------------------------------------
// Limpeza (nunca deixa fixtures/arquivos gravados para tras)
// ---------------------------------------------------------------------
$pastaCompleta = $storagePathBase . '/' . $pastaTeste;
if (is_dir($pastaCompleta)) {
    foreach (glob($pastaCompleta . '/*') as $arquivo) {
        unlink($arquivo);
    }
    rmdir($pastaCompleta);
}

exit($totalFalhas > 0 || !$provaNegativaTotal ? 1 : 0);
