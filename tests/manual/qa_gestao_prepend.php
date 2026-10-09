<?php

/**
 * auto_prepend_file dos testes da Gestao Totem (demanda gestao-totem, F0/F1):
 * forca, em TODO subprocesso PHP/CGI, o banco QA descartavel
 * `qa_qr_exclusivo_<hex>` (DB_NAME), o STORAGE_PATH temporario e as variaveis
 * GESTAO_* / outras passadas em QA_GESTAO_ENV_JSON (mapa chave => string; chave
 * com valor "" fica ausente para o codigo). Dotenv imutavel nao sobrescreve
 * chaves ja presentes em $_ENV, entao o .env real nunca vence. Fail-closed: sem
 * nome QA valido o processo aborta antes de qualquer conexao (nunca cai em
 * udlog_totem).
 */
$qaNome = getenv('QA_QR_FORCE_DB_NAME');
if ($qaNome === false || preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $qaNome) !== 1) {
    fwrite(STDERR, "QA prepend gestao: QA_QR_FORCE_DB_NAME ausente ou invalido; abortando.\n");
    exit(3);
}
$_ENV['DB_NAME'] = $qaNome;
$_SERVER['DB_NAME'] = $qaNome;
$qaStorage = getenv('QA_QR_FORCE_STORAGE');
if ($qaStorage !== false && $qaStorage !== '') {
    $_ENV['STORAGE_PATH'] = $qaStorage;
    $_SERVER['STORAGE_PATH'] = $qaStorage;
}
$qaJson = getenv('QA_GESTAO_ENV_JSON');
if ($qaJson !== false && $qaJson !== '') {
    $qaMapa = json_decode($qaJson, true);
    if (is_array($qaMapa)) {
        if (array_key_exists('DB_NAME', $qaMapa)) {
            fwrite(STDERR, "QA prepend gestao: QA_GESTAO_ENV_JSON nao pode conter DB_NAME; abortando.\n");
            exit(3);
        }
        foreach ($qaMapa as $qaChave => $qaValor) {
            if (is_string($qaChave) && preg_match('/\A[A-Z0-9_]{1,60}\z/', $qaChave) === 1 && is_string($qaValor)) {
                $_ENV[$qaChave] = $qaValor;
                $_SERVER[$qaChave] = $qaValor;
            }
        }
    }
}
unset($qaNome, $qaStorage, $qaJson, $qaMapa, $qaChave, $qaValor);
