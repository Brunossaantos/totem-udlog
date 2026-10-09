<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Bootstrap;
use Util\IpCliente;
use Util\LimiteFalhasIp;
use App\Dao\TotemDao;
use App\Content\TermoLgpd;

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$responder404 = static function (?LimiteFalhasIp $limite, string $ip): void {
    if ($limite !== null) {
        $limite->registrarFalha($ip);
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    die('Pagina nao encontrada.');
};

try {
    Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
} catch (\Throwable $e) {
}
$storageBase = rtrim((string) ($_ENV['STORAGE_PATH'] ?? ''), '/\\');
$dirLimite = ($storageBase !== '' ? $storageBase : sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit';
$salLimite = $_ENV['GESTAO_HASH_SALT'] ?? null;
$limiteFalhas = new LimiteFalhasIp(
    $dirLimite,
    LimiteFalhasIp::LIMITE_FALHAS,
    LimiteFalhasIp::JANELA_SEGUNDOS,
    null,
    is_string($salLimite) ? $salLimite : null
);
$ipCliente = IpCliente::obter($_SERVER);

$segundos = $limiteFalhas->segundosBloqueado($ipCliente);
if ($segundos !== null) {
    http_response_code(429);
    header('Retry-After: ' . $segundos);
    header('Content-Type: text/html; charset=UTF-8');
    die('Muitas tentativas. Tente novamente mais tarde.');
}

$codigoTotem = $_GET['totem'] ?? '';
if (!is_string($codigoTotem) || preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $codigoTotem) !== 1) {
    $responder404($limiteFalhas, $ipCliente);
}

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    http_response_code(503);
    die('Servico temporariamente indisponivel. Tente novamente em instantes.');
}

try {
    $totem = (new TotemDao($pdo))->buscarPorCodigo($codigoTotem);
} catch (\Throwable $e) {
    error_log('totem/index: falha_consulta_totem ' . get_class($e));
    http_response_code(503);
    die('Servico temporariamente indisponivel. Tente novamente em instantes.');
}

if (!$totem) {
    $responder404($limiteFalhas, $ipCliente);
}

$lgpdTermoDados = [
    'versao' => TermoLgpd::versao(),
    'hash'   => TermoLgpd::hash(),
    'texto'  => TermoLgpd::texto(),
];
$lgpdTermoJson = json_encode(
    $lgpdTermoDados,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
);

$assetsVersoes = [
    'qrWorker'  => (int) @filemtime(__DIR__ . '/assets/qr-worker.js'),
    'tesseract' => (int) @filemtime(__DIR__ . '/assets/vendor/tesseract-5.1.1/tesseract.min.js'),
];
$assetsVersoesJson = json_encode($assetsVersoes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Totem</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=<?= (int) @filemtime(__DIR__ . '/assets/favicon.svg') ?>">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png?v=<?= (int) @filemtime(__DIR__ . '/assets/favicon-32.png') ?>">
<link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png?v=<?= (int) @filemtime(__DIR__ . '/assets/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body data-totem-token="<?= htmlspecialchars($totem['token_api'], ENT_QUOTES, 'UTF-8') ?>" data-totem-nome="<?= htmlspecialchars($totem['nome'], ENT_QUOTES, 'UTF-8') ?>">
<div id="app"></div>
<script type="application/json" id="lgpd-termo-dados"><?= $lgpdTermoJson ?></script>
<script type="application/json" id="assets-versoes"><?= $assetsVersoesJson ?></script>
<script src="assets/vendor/tesseract-5.1.1/tesseract.min.js?v=<?= (int) $assetsVersoes['tesseract'] ?>"></script>
<script src="assets/app.js?v=<?= (int) @filemtime(__DIR__ . '/assets/app.js') ?>"></script>
<script src="assets/impressao.js?v=<?= (int) @filemtime(__DIR__ . '/assets/impressao.js') ?>"></script>
<script src="assets/diagnostico-impressao.js?v=<?= (int) @filemtime(__DIR__ . '/assets/diagnostico-impressao.js') ?>"></script>
</body>
</html>
