<?php

/** Contrato QR-only de CAS e ausencia consciente de cache/HMAC. */
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Controller\DocumentoController;
use App\Dao\AtendimentoDao;
use App\Rn\DocumentoRn;

$total = 0;
$falhas = 0;
function casAfirmar(string $descricao, bool $condicao): void
{
    global $total, $falhas;
    ++$total;
    if (!$condicao) {
        ++$falhas;
    }
    echo ($condicao ? 'OK   ' : 'FALHA') . " - {$descricao}\n";
}

$raiz = dirname(__DIR__, 2);
$controllerFonte = (string) file_get_contents($raiz . '/app/Controller/DocumentoController.php');
$daoFonte = (string) file_get_contents($raiz . '/app/Dao/AtendimentoDao.php');
$apiFonte = (string) file_get_contents($raiz . '/public/api/documento.php');
preg_match('/public function iniciarProcessamento\b.*?(?=\n    public function statusProcessamento\b)/s', $controllerFonte, $inicio);
$inicioQrOnly = $inicio[0] ?? '';

casAfirmar('iniciar-processamento recebe somente imagem_qr_base64', str_contains($inicioQrOnly, "['imagem_qr_base64']"));
casAfirmar('QR-only nao recebe qr_bytes_base64 ou binaryData', !str_contains($inicioQrOnly, 'qr_bytes_base64') && !str_contains($inicioQrOnly, 'binaryData'));
casAfirmar('QR-only valida JPEG somente em memoria', str_contains($inicioQrOnly, 'decodificarJpegBase64Seguro') && !str_contains($inicioQrOnly, 'salvarImagemBase64'));
casAfirmar('QR-only nao consulta VIO_CACHE', !str_contains($inicioQrOnly, 'tentarCache') && !str_contains($inicioQrOnly, 'VioApiBrCacheDao'));
casAfirmar('QR-only nao calcula fingerprint/HMAC', !str_contains($inicioQrOnly, 'calcularFingerprint') && !str_contains($inicioQrOnly, 'DOCUMENTO_QR_HMAC_KEY'));
casAfirmar('QR-only adquire CAS antes do POST', str_contains($inicioQrOnly, 'iniciarEnvioVioApiBr($idAtendimento, $tipo, $tentativaId)'));
casAfirmar('perdedor do CAS retorna sem segundo POST', str_contains($inicioQrOnly, 'if (!$adquiriu)') && !str_contains(substr($inicioQrOnly, strpos($inicioQrOnly, 'if (!$adquiriu)'), 500), 'enviarParaLeitura'));
casAfirmar('envio QR-only tem uma unica chamada de POST no controller', substr_count($inicioQrOnly, '$vio->enviarParaLeitura(') === 1);
casAfirmar('falha ambigua segue para INDETERMINADO', str_contains($inicioQrOnly, 'marcarEnvioComoIndeterminado'));
casAfirmar('controller nao grava cache apos o POST', !str_contains($inicioQrOnly, 'gravarCacheVioApiBr'));

casAfirmar('CAS do DAO aceita apenas PENDENTE ou ERRO', str_contains($daoFonte, "IN ('PENDENTE', 'ERRO')"));
casAfirmar('CAS limpa colunas historicas de fingerprint sem reutiliza-las', str_contains($daoFonte, "fingerprint']} = NULL") && str_contains($daoFonte, "fingerprint_versao']} = NULL"));
casAfirmar('resultado final exige tentativa vigente e atendimento em andamento', str_contains($daoFonte, 'AND {$c[\'tentativa\']} = :tentativa AND status = \'em_andamento\''));
casAfirmar('timeout do DAO termina em INDETERMINADO, sem reabrir PENDENTE', str_contains($daoFonte, 'SET {$c[\'status\']} = \'INDETERMINADO\''));
casAfirmar('rotas legadas nao encaminham a controller nem gravam dados', !str_contains($apiFonte, "->upload(") && !str_contains($apiFonte, "->definirModoCnh(") && !str_contains($apiFonte, "->validarQr("));
casAfirmar('acao desconhecida retorna resposta sanitizada 404', str_contains($apiFonte, "Resposta::erro('Acao invalida', 404)"));

$daoSemPdo = (new ReflectionClass(AtendimentoDao::class))->newInstanceWithoutConstructor();
$vioFalso = new class {
    public function enviarParaLeitura(string $jpeg): array { return ['ok' => false]; }
    public function consultarResultado(string $id): array { return ['ok' => false]; }
};
$controller = new DocumentoController($daoSemPdo, null, null, null, static fn() => $vioFalso);
$factory = new ReflectionMethod(DocumentoController::class, 'criarVioClient');
$factory->setAccessible(true);
casAfirmar('factory VIO de teste e composta no servidor, sem request', $factory->invoke($controller) === $vioFalso);

$rn = (new ReflectionClass(DocumentoRn::class))->newInstanceWithoutConstructor();
$aprovavel = new ReflectionMethod(DocumentoRn::class, 'respostaVioApiBrAprovavel');
$aprovavel->setAccessible(true);
$resultadoBase = ['estado_leitura' => 'completed', 'qr_type' => 'vio', 'dados_leitura' => ['campo' => 'sintetico']];
foreach (['pending', 'processing', 'failed', 'expired', null] as $compare) {
    $resultado = $resultadoBase;
    if ($compare !== null) {
        $resultado['comparacao'] = ['status' => $compare, 'summary' => ['reliable' => false, 'mismatched' => 99]];
    }
    casAfirmar('compare ' . ($compare ?? 'ausente') . ' e somente diagnostico', $aprovavel->invoke($rn, $resultado, null) === true);
}
casAfirmar('leitura principal failed continua bloqueante', $aprovavel->invoke($rn, array_replace($resultadoBase, ['estado_leitura' => 'failed']), null) === false);
casAfirmar('QR nao-VIO continua bloqueante', $aprovavel->invoke($rn, array_replace($resultadoBase, ['qr_type' => 'normal']), null) === false);
casAfirmar('dados estruturados ausentes continuam bloqueantes', $aprovavel->invoke($rn, array_replace($resultadoBase, ['dados_leitura' => null]), null) === false);

echo "\nVerificacoes: {$total}; Falhas: {$falhas}\n";
exit($falhas === 0 ? 0 : 1);
