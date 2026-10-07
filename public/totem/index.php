<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Bootstrap;
use Util\IpCliente;
use Util\LimiteFalhasIp;
use App\Dao\TotemDao;
use App\Content\TermoLgpd;

// Endurecimento da pagina do totem (demanda gestao-totem, F0, 2026-10-06).
// Cabecalhos em TODA resposta desta pagina (200, 404, 429, 503). Sem CSP de
// proposito: a pagina carrega app.js/tesseract por <script src> do proprio
// dominio e usa so blocos <script type="application/json"> (dados, nao
// executam), mas o front do totem nao foi auditado para CSP e esta rodada nao
// pode arriscar o quiosque.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

/**
 * Resposta UNICA de "nao encontrado": mesmo status, mesmo corpo e mesmos
 * cabecalhos para codigo malformado, inexistente e inativo (nunca cita o
 * formato esperado nem o codigo do quiosque). Cada chamada conta UMA falha
 * para o IP.
 */
$responder404 = static function (?LimiteFalhasIp $limite, string $ip): void {
    if ($limite !== null) {
        $limite->registrarFalha($ip);
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    die('Pagina nao encontrada.');
};

// .env so para ler STORAGE_PATH (pasta do contador); falha aqui NAO e fatal: o
// Bootstrap abaixo continua sendo quem responde 503 por .env ausente/malformado.
try {
    Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
} catch (\Throwable $e) {
}
$storageBase = rtrim((string) ($_ENV['STORAGE_PATH'] ?? ''), '/\\');
$dirLimite = ($storageBase !== '' ? $storageBase : sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit';
// Sal do nome do arquivo do contador: GESTAO_HASH_SALT se houver (a classe cai
// numa constante fixa se faltar ou for curta; nunca quebra o quiosque).
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

// Formato fixo ANTES de qualquer consulta ao banco (a collation atual ignora
// maiusculas/minusculas, entao RECEPCAO-01 e recepcao-01 chegam ao mesmo totem).
$codigoTotem = $_GET['totem'] ?? '';
if (!is_string($codigoTotem) || preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $codigoTotem) !== 1) {
    $responder404($limiteFalhas, $ipCliente);
}

// Bootstrap isolado: Util\Bootstrap::conectar() cobre .env ausente/malformado,
// variavel obrigatoria de banco ausente/invalida e falha de conexao (ver
// util/Bootstrap.php e util/Conexao.php) -- capturado aqui para nunca vazar
// fatal error cru (stack trace + caminho do servidor) ao navegador do totem.
// Esta rota serve HTML, nao JSON -- resposta segue o mesmo estilo ja usado
// abaixo para "totem nao configurado" (http_response_code + die).
// Falha de infraestrutura (503) NAO conta como falha do IP.
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

// Fonte UNICA do texto/versao/hash do termo LGPD (App\Content\TermoLgpd) —
// renderizada no servidor e injetada abaixo via <script type="application/
// json">, para o front-end (assets/app.js) consumir sem NUNCA duplicar o
// texto em JavaScript (demanda tela-inicial-lgpd-totem, 2026-09-24).
// json_encode() com JSON_HEX_TAG/JSON_HEX_AMP/JSON_HEX_QUOT/JSON_HEX_APOS
// escapa corretamente para uso seguro dentro de uma tag <script> (evita
// que o texto do termo, que contem HTML interno como <h3>/<p>/<ul>, quebre
// o parsing do HTML ao redor, inclusive contra sequencias como "</script>").
$lgpdTermoDados = [
    'versao' => TermoLgpd::versao(),
    'hash'   => TermoLgpd::hash(),
    'texto'  => TermoLgpd::texto(),
];
$lgpdTermoJson = json_encode(
    $lgpdTermoDados,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
);

// Versoes (filemtime) dos assets que o JavaScript nao consegue versionar sozinho:
// o qr-worker.js e criado por app.js via new Worker() e o Tesseract e
// carregado a partir de assets/vendor/tesseract-5.1.1/. Mesmo padrao do
// bloco lgpd-termo-dados (somente inteiros, sem dado de usuario).
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
