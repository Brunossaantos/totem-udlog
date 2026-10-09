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
// A3 (F4a): o banco EXTERNO de coletas tambem e forcado para um banco QA (nunca
// o .env real). QA_QR_FORCE_EXT_DB_NAME opcional; deve seguir o padrao QA, senao
// aborta (exit 3). Sem ele, usa o proprio banco QA (tabelas externas ausentes =
// falha limpa, jamais o banco externo real).
$qaExt = getenv('QA_QR_FORCE_EXT_DB_NAME');
if ($qaExt === false || $qaExt === '') {
    $qaExt = $qaNome;
}
if (preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $qaExt) !== 1) {
    fwrite(STDERR, "QA prepend: QA_QR_FORCE_EXT_DB_NAME invalido (nao e nome QA); abortando.
");
    exit(3);
}
$_ENV['GESTAO_COLETAS_DB_NAME'] = $qaExt;
$_SERVER['GESTAO_COLETAS_DB_NAME'] = $qaExt;
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
unset($qaNome, $qaExt, $qaStorage, $qaTalent);
