<?php
// Rodar via cron do cPanel, SOMENTE via PHP CLI, UMA VEZ POR DIA:
//   php /caminho/absoluto/cron/limpar-notas-quarentena.php
//
// Demanda hardening-revisao-notas-e-cliente (2026-09-30, decisao D3) — apaga
// de vez as fotos de notas fiscais que foram para a QUARENTENA (rename para
// nota_NN.jpg.<id_nota>.del) quando o motorista excluiu uma nota, cancelou o
// atendimento ou abandonou o totem (a inatividade cancela pelo mesmo
// endpoint). Retencao: 24 horas (mtime do .del, renovado no momento da
// quarentena por Util\NotaArquivoStorage::quarentenar).
//
// Escopo DELIBERADAMENTE estreito (dado pessoal, LGPD):
//  - so arquivos cujo nome bate em nota_0[1-5].jpg.<id>.del;
//  - so na estrutura fixa STORAGE_PATH/AAAA-MM-DD/PLACA_HHMMSS/ (2 niveis);
//  - NUNCA segue symlink (pasta ou arquivo) e nunca toca em outro arquivo
//    (nem nota_NN.jpg, nem CNH/CRLV, nem .del mais novo que 24 h);
//  - NAO usa banco de dados.
// Mesmo padrao de cron/limpar-rate-limit-ocr.php: rejeita execucao fora de
// PHP CLI (nao existe rota em public/api/ para este script) e o log final e
// SEMPRE agregado (contagens), sem caminho, pasta (contem placa), nome de
// arquivo ou id de nota.
//
// Rodada corretiva F2 (2026-10-01):
//  - LIMITE de 500 arquivos apagados por execucao, mesmo com mais elegiveis
//    (NotaArquivoStorage::LIMITE_REMOCOES_POR_EXECUCAO); o restante fica para
//    a execucao seguinte. Atingir o limite NAO e falha (saida 0);
//  - varredura por opendir/readdir (uma entrada por vez), sem carregar
//    milhares de nomes em memoria;
//  - falha ao abrir/ler um diretorio = log agregado fixo e saida 1 (nunca um
//    falso "0 apagados" com saida 0).
//
// Codigo de saida: 0 = sucesso (mesmo sem nada a apagar, ou com limite
// atingido) ; 1 = falha (STORAGE_PATH ausente/inacessivel, diretorio
// ilegivel, ou algum unlink falhou).

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\NotaArquivoStorage;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

try {
    // safeLoad: .env ausente nao e erro aqui (a variavel pode vir do
    // ambiente do cron); quem decide e a checagem de STORAGE_PATH abaixo.
    // Nao ha conexao com banco neste script.
    Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
} catch (\Throwable $e) {
    error_log('limpar-notas-quarentena: falha ao carregar .env (malformado) -- nenhuma exclusao iniciada');
    exit(1);
}

$storagePath = (string) ($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '');

try {
    $storage = new NotaArquivoStorage($storagePath !== '' ? $storagePath : null);
    $resultado = $storage->limparQuarentenaExpirada(time());
} catch (\Throwable $e) {
    // raiz ausente/inexistente ou falha inesperada: nada foi apagado
    error_log('limpar-notas-quarentena: STORAGE_PATH ausente ou inacessivel -- nenhuma exclusao iniciada');
    exit(1);
}

error_log(sprintf(
    'limpar-notas-quarentena: %d arquivo(s) .del apagado(s), %d mantido(s) (menos de 24 h), %d falha(s), %d entrada(s) ignorada(s), %d diretorio(s) ilegivel(is), limite de %d por execucao %s',
    $resultado['removidos'],
    $resultado['mantidos'],
    $resultado['falhas'],
    $resultado['ignorados'],
    $resultado['erros_leitura'],
    NotaArquivoStorage::LIMITE_REMOCOES_POR_EXECUCAO,
    $resultado['limite_atingido'] ? 'ATINGIDO (restante na proxima execucao)' : 'nao atingido'
));

if ($resultado['erros_leitura'] > 0) {
    error_log('limpar-notas-quarentena: varredura INCOMPLETA (diretorio ilegivel) -- verificar permissoes de STORAGE_PATH');
}

exit(($resultado['falhas'] > 0 || $resultado['erros_leitura'] > 0) ? 1 : 0);
