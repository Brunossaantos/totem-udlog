<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\Bootstrap;
use App\Dao\TotemDao;
use App\Content\TermoLgpd;

// Bootstrap isolado: Util\Bootstrap::conectar() cobre .env ausente/malformado,
// variavel obrigatoria de banco ausente/invalida e falha de conexao (ver
// util/Bootstrap.php e util/Conexao.php) -- capturado aqui para nunca vazar
// fatal error cru (stack trace + caminho do servidor) ao navegador do totem.
// Esta rota serve HTML, nao JSON -- resposta segue o mesmo estilo ja usado
// abaixo para "totem nao configurado" (http_response_code + die).
try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../../');
} catch (\Throwable $e) {
    http_response_code(503);
    die('Servico temporariamente indisponivel. Tente novamente em instantes.');
}

$codigoTotem = $_GET['totem'] ?? '';
$totem = (new TotemDao($pdo))->buscarPorCodigo($codigoTotem);

if (!$totem) {
    http_response_code(404);
    die('Totem nao configurado. Verifique o parametro ?totem= na URL (ex: ?totem=RECEPCAO-01).');
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>UDLOG — Totem de autoatendimento</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body data-totem-token="<?= htmlspecialchars($totem['token_api'], ENT_QUOTES, 'UTF-8') ?>" data-totem-nome="<?= htmlspecialchars($totem['nome'], ENT_QUOTES, 'UTF-8') ?>">
<div id="app"></div>
<script type="application/json" id="lgpd-termo-dados"><?= $lgpdTermoJson ?></script>
<script src="assets/tesseract/tesseract.min.js"></script>
<script src="assets/app.js"></script>
<script src="assets/impressao.js"></script>
<script src="assets/diagnostico-impressao.js"></script>
</body>
</html>
