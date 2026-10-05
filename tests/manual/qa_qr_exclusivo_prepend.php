<?php

/**
 * auto_prepend_file das suites portadas ao fluxo QR-only: forca DB_NAME (e,
 * opcionalmente, STORAGE_PATH) de TODO subprocesso PHP para o banco QA
 * descartavel `qa_qr_exclusivo_<hex>` herdado do processo pai. Dotenv
 * imutavel nao sobrescreve chaves ja presentes em $_ENV, entao o DB_NAME do
 * .env nunca e usado. Fail-closed: sem nome QA valido o processo aborta antes
 * de qualquer conexao (nunca cai em udlog_totem).
 */
$qaNome = getenv('QA_QR_FORCE_DB_NAME');
if ($qaNome === false || preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $qaNome) !== 1) {
    fwrite(STDERR, "QA prepend: QA_QR_FORCE_DB_NAME ausente ou invalido; abortando.\n");
    exit(3);
}
$_ENV['DB_NAME'] = $qaNome;
$_SERVER['DB_NAME'] = $qaNome;
$qaStorage = getenv('QA_QR_FORCE_STORAGE');
if ($qaStorage !== false && $qaStorage !== '') {
    $_ENV['STORAGE_PATH'] = $qaStorage;
    $_SERVER['STORAGE_PATH'] = $qaStorage;
}
$qaTalent = getenv('QA_QR_FORCE_TALENT_ATIVO');
if ($qaTalent === 'false') {
    $_ENV['TALENT_CHECKIN_ATIVO'] = 'false';
    $_SERVER['TALENT_CHECKIN_ATIVO'] = 'false';
}
unset($qaNome, $qaStorage, $qaTalent);
