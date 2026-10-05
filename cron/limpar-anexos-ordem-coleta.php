<?php
// Rodar via cron do cPanel, SOMENTE via PHP CLI, UMA VEZ POR DIA:
//   php /caminho/absoluto/cron/limpar-anexos-ordem-coleta.php
//
// Demanda anexo-ordem-coleta-n8n (2026-10-05) — retencao dos PDFs de Ordem de
// Coleta em STORAGE_PATH/ordens_coleta/<cnpj>/ e da tabela
// tb_ordem_coleta_arquivos (banco externo de gestao de coletas).
//
// Regra (decisao do usuario): apaga o arquivo + a linha quando
//   (a) existe OC correspondente (por cnpj do cliente + numero) e ela esta
//       INATIVA ha mais de 15 dias (tb_ordens_coleta.inativada_em), ou
//   (b) NAO existe OC correspondente e criado_em do anexo tem mais de 15 dias.
// Uma OC ATIVA NUNCA tem o arquivo apagado.
//
// Para cada linha (lote fixo de 200 por execucao): valida o caminho por
// regex/realpath/sem symlink -> unlink (arquivo ausente = ok) -> DELETE da
// linha. Falha de unlink (ou caminho invalido) MANTEM a linha e a execucao
// sai com codigo 1. O log final e SEMPRE agregado (contagens), sem caminho,
// numero de OC ou cnpj.
//
// Codigo de saida: 0 = sucesso (inclusive nada a apagar); 1 = falha (.env
// malformado, STORAGE_PATH ausente, banco externo indisponivel ou algum
// arquivo/linha nao apagado). Rejeita execucao fora de PHP CLI.

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Dao\OrdemColetaArquivoDao;
use App\Rn\OrdemColetaArquivoRn;
use Util\OrdemColetaArquivoStorage;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

try {
    Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
} catch (\Throwable $e) {
    error_log('limpar-anexos-ordem-coleta: falha ao carregar .env (malformado) -- nenhuma exclusao iniciada');
    exit(1);
}

$storagePath = (string) ($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '');
if ($storagePath === '') {
    error_log('limpar-anexos-ordem-coleta: STORAGE_PATH ausente -- nenhuma exclusao iniciada');
    exit(1);
}

try {
    $rn = new OrdemColetaArquivoRn(new OrdemColetaArquivoDao(), new OrdemColetaArquivoStorage($storagePath));
    $resultado = $rn->limparExpirados(OrdemColetaArquivoRn::LOTE_RETENCAO, OrdemColetaArquivoRn::DIAS_RETENCAO);
} catch (\Throwable $e) {
    // banco externo indisponivel ou falha inesperada: nada foi apagado
    error_log('limpar-anexos-ordem-coleta: falha ao consultar a retencao (' . get_class($e) . ') -- nenhuma exclusao concluida');
    exit(1);
}

error_log(sprintf(
    'limpar-anexos-ordem-coleta: %d elegivel(is), %d arquivo(s) apagado(s), %d ja ausente(s), %d falha(s), lote de %d por execucao',
    $resultado['elegiveis'],
    $resultado['apagados'],
    $resultado['ausentes'],
    $resultado['falhas'],
    OrdemColetaArquivoRn::LOTE_RETENCAO
));

if ($resultado['falhas'] > 0) {
    error_log('limpar-anexos-ordem-coleta: houve falha ao apagar arquivo/linha -- verificar permissoes de STORAGE_PATH/ordens_coleta');
}

exit($resultado['falhas'] > 0 ? 1 : 0);
