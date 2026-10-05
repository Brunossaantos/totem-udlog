<?php

/**
 * auto_prepend_file dos testes do anexo da Ordem de Coleta (demanda
 * anexo-ordem-coleta-n8n): forca, em TODO subprocesso PHP/CGI, o banco QA
 * descartavel `qa_qr_exclusivo_<hex>` (DB_NAME e GESTAO_COLETAS_DB_NAME), o
 * STORAGE_PATH temporario e a configuracao da API. Dotenv imutavel nao
 * sobrescreve chaves ja presentes em $_ENV. Fail-closed: sem nome QA valido o
 * processo aborta antes de qualquer conexao (nunca cai em udlog_totem nem no
 * banco externo real).
 */
$qaNome = getenv('QA_QR_FORCE_DB_NAME');
if ($qaNome === false || preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $qaNome) !== 1) {
    fwrite(STDERR, "QA prepend OC: QA_QR_FORCE_DB_NAME ausente ou invalido; abortando.\n");
    exit(3);
}
foreach (['DB_NAME', 'GESTAO_COLETAS_DB_NAME'] as $chave) {
    $_ENV[$chave] = $qaNome;
    $_SERVER[$chave] = $qaNome;
}
$qaStorage = getenv('QA_QR_FORCE_STORAGE');
if ($qaStorage !== false && $qaStorage !== '') {
    $_ENV['STORAGE_PATH'] = $qaStorage;
    $_SERVER['STORAGE_PATH'] = $qaStorage;
}
// chave/limites da API: so definidos se o teste os passou (ausente = nao define)
foreach (['QA_OC_API_KEY' => 'ORDEM_COLETA_ANEXO_API_KEY', 'QA_OC_MAX_BYTES' => 'ORDEM_COLETA_ANEXO_MAX_BYTES', 'QA_OC_PERMITIR_HTTP' => 'ORDEM_COLETA_ANEXO_PERMITIR_HTTP'] as $de => $para) {
    $v = getenv($de);
    if ($v !== false) {
        $_ENV[$para] = $v;
        $_SERVER[$para] = $v;
    }
}
// Marcador explicito de "chave vazia": variavel de ambiente VAZIA nao e
// confiavel no CGI do Windows (some ou e ignorada), entao o teste usa
// QA_OC_CHAVE_VAZIA=1 e o prepend fixa a chave como '' (503 fail-closed,
// mesmo caminho de codigo de "ausente"), sobrepondo qualquer valor do .env local.
if (getenv('QA_OC_CHAVE_VAZIA') === '1') {
    $_ENV['ORDEM_COLETA_ANEXO_API_KEY'] = '';
    $_SERVER['ORDEM_COLETA_ANEXO_API_KEY'] = '';
}
unset($qaNome, $qaStorage, $chave, $de, $para, $v);
