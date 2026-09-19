<?php
// Rodar via cron do cPanel, SOMENTE via PHP CLI:
//   php /caminho/absoluto/cron/limpar-rate-limit-ocr.php
//
// Demanda robustez-rate-limit-migrations (2026-09-18) — job de manutencao
// que apaga janelas expiradas de tb_rate_limit_ocr (controle de taxa por
// totem do endpoint nota.php?acao=identificar-cliente, ver
// app/Dao/RateLimitOcrDao.php e sql/migrations/004_tb_rate_limit_ocr.sql).
// Decisao confirmada pelo usuario: limpeza SOMENTE via cron/CLI — nunca
// oportunista dentro de uma requisicao HTTP, nunca exposta como endpoint
// publico (nao existe rota em public/api/ para este script; a checagem
// PHP_SAPI abaixo e uma defesa adicional, nao o unico motivo de nao ser
// publico).
//
// Retencao: 24 horas, MAS nunca apaga a janela atual nem a imediatamente
// anterior (janela = 60s, mesma formula de intdiv(time(), 60) usada em
// App\Controller\NotaController::verificarRateLimit()) — garante por
// construcao que o rate limit em uso nunca perde a linha que acabou de
// escrever/ler, independente do corte de tempo.
//
// Lotes de no maximo 500 linhas por DELETE (RateLimitOcrDao::
// apagarJanelasExpiradas), teto de 50 lotes por execucao (ate 25.000 linhas
// por rodada) — protege contra execucao muito longa mesmo se o cron ficar
// muito tempo sem rodar. Sem transacao multi-lote, sem LOCK TABLES — cada
// DELETE e uma operacao curta e independente; se o processo for
// interrompido no meio, a proxima execucao simplesmente recomeca (nenhum
// checkpoint adicional necessario).
//
// Log final SEMPRE agregado (contagem total apagada, numero de lotes,
// corte usado) — NUNCA conteudo de linha, NUNCA id_totem individual.

require_once __DIR__ . '/../vendor/autoload.php';

use Util\Bootstrap;
use App\Dao\RateLimitOcrDao;

// Defesa adicional: rejeita execucao fora de PHP CLI. Nao ha rota HTTP
// registrada para este arquivo em public/api/, mas esta checagem evita que
// o script produza qualquer efeito util se, por engano de configuracao do
// servidor, for exposto via SAPI HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

const RATE_LIMIT_JANELA_SEGUNDOS = 60;
const RETENCAO_SEGUNDOS = 24 * 60 * 60; // 24 horas
const LIMITE_LOTE = 500;
const MAX_LOTES = 50;

/**
 * Loga falha de banco de forma minima e segura — mesmo padrao de
 * App\Controller\NotaController::logFalhaBancoPdo(): nunca inclui
 * getMessage()/getTraceAsString()/getFile()/getLine() da excecao (podem
 * conter SQL, valores de parametro ou dado pessoal). SQLSTATE so entra no
 * log se bater estritamente no formato esperado.
 */
function logFalhaBancoPdoCron(string $contexto, \PDOException $e): void
{
    $sqlstate = (string) $e->getCode();
    $sqlstateValidado = preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? $sqlstate : null;

    error_log(
        $contexto . ': falha de banco (PDOException)'
        . ($sqlstateValidado !== null ? " [SQLSTATE={$sqlstateValidado}]" : '')
    );
}

try {
    // Util\Bootstrap::conectar() cobre, numa unica fronteira segura, .env
    // ausente/malformado, variavel obrigatoria de banco ausente/invalida e
    // falha de conexao (ver util/Bootstrap.php e util/Conexao.php) -- se
    // qualquer uma dessas falhar, nenhum DELETE e sequer preparado.
    $pdo = Bootstrap::conectar(__DIR__ . '/../');
    $dao = new RateLimitOcrDao($pdo);

    $agora = time();
    $corteUnixTime = $agora - RETENCAO_SEGUNDOS;
    $janelaAtual = intdiv($agora, RATE_LIMIT_JANELA_SEGUNDOS);
    $janelaAnterior = $janelaAtual - 1;

    $totalApagado = 0;
    $lotes = 0;

    while (true) {
        $apagados = $dao->apagarJanelasExpiradas($corteUnixTime, $janelaAtual, $janelaAnterior, LIMITE_LOTE);
        if ($apagados === 0) {
            break;
        }
        $totalApagado += $apagados;
        $lotes++;
        if ($lotes >= MAX_LOTES) {
            break;
        }
    }

    error_log(sprintf(
        'limpar-rate-limit-ocr: %d linha(s) apagada(s) em %d lote(s), corte=%d',
        $totalApagado,
        $lotes,
        $corteUnixTime
    ));

    exit(0);
} catch (\PDOException $e) {
    logFalhaBancoPdoCron('limpar-rate-limit-ocr', $e);
    exit(1);
} catch (\Throwable $e) {
    // Cobre falha de Util\Bootstrap::conectar() (.env ausente/malformado,
    // variavel obrigatoria ausente/invalida) -- mensagem ja sanitizada pelo
    // proprio Bootstrap, nunca getMessage()/getTraceAsString() bruto. Neste
    // ponto NENHUM DELETE foi sequer preparado (falha ocorre antes de
    // qualquer chamada a RateLimitOcrDao).
    error_log('limpar-rate-limit-ocr: falha de bootstrap (.env/conexao) -- nenhuma exclusao iniciada');
    exit(1);
}
