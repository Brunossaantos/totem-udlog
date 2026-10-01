<?php

/**
 * Router do php -S do teste front_r4b_real.js: expoe SOMENTE /api/nota.php e
 * /api/atendimento.php do projeto real. DB_NAME/STORAGE_PATH vem do ambiente do
 * processo (banco qa_r4fb_* e storage temporario); a ponte para $_ENV e a mesma
 * das demais suites (Dotenv imutavel nunca promove sozinho o ambiente do SO).
 * Falha fechada se o banco nao for qa_r4fb_*.
 */

require_once __DIR__ . '/qa_db_bootstrap.php';

eval(qaDbTrechoPonteEnvSubprocesso());

if (strpos((string) getenv('DB_NAME'), 'qa_r4fb_') !== 0 || ($_ENV['DB_NAME'] ?? '') !== getenv('DB_NAME')) {
    http_response_code(500);
    echo 'banco de teste invalido';
    return true;
}

$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (preg_match('#^/api/(nota|atendimento)\.php$#', (string) $caminho, $m)) {
    require __DIR__ . '/../../public/api/' . $m[1] . '.php';
    return true;
}
http_response_code(404);
echo 'nf';
return true;
