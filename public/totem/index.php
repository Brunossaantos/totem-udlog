<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\TotemDao;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$codigoTotem = $_GET['totem'] ?? '';
$totem = (new TotemDao(Conexao::obter()))->buscarPorCodigo($codigoTotem);

if (!$totem) {
    http_response_code(404);
    die('Totem nao configurado. Verifique o parametro ?totem= na URL (ex: ?totem=RECEPCAO-01).');
}
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
<script src="assets/tesseract/tesseract.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
