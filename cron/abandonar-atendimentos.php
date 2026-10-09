<?php
// Rodar via cron do cPanel, SOMENTE via PHP CLI, UMA VEZ POR DIA:
//   php /caminho/absoluto/cron/abandonar-atendimentos.php
//
// Rodada corretiva da demanda hardening-revisao-notas-e-cliente (2026-10-01):
// politica de ABANDONO/RETENCAO de fotos de notas.
//
//  - Atendimento em_andamento SEM ATIVIDADE ha mais de 24 horas e abandonado:
//    vai ao estado terminal ja existente 'cancelado' (mesmo estado do cancelar
//    e da inatividade do front) e as fotos das notas vao para a quarentena
//    (nota_NN.jpg.<id_nota>.del). Depois ficam mais 24 horas e o cron
//    cron/limpar-notas-quarentena.php as apaga de vez (o mtime do .del e
//    renovado no momento da quarentena).
//  - "Sem atividade" (colunas existentes, nao ha coluna dedicada): o MAIOR
//    entre tb_atendimento.atualizado_em e, entre as notas, criado_em e
//    processado_em. Nunca candidato: atendimento ativo/recente, concluido,
//    cancelado, bloqueado, ou com envio ao Talent em curso/aceito
//    (talent_checkin_status ENVIANDO/ENVIADO/ENVIO_INDETERMINADO).
//  - Cada atendimento: BEGIN, lock da linha, REVALIDACAO sob lock, rename das
//    fotos, UPDATE (CAS em_andamento -> cancelado), COMMIT; falha antes do
//    COMMIT devolve as fotos e faz ROLLBACK (continua em_andamento; a proxima
//    execucao tenta de novo). Ver App\Rn\AbandonoAtendimentoRn.
//  - LIMITE de 500 atendimentos por execucao (o restante fica para a proxima).
//
// Este script PRECISA de banco (diferente de limpar-notas-quarentena.php, que
// nao usa): conexao por Util\Bootstrap (so PDO com prepared statements). Sem
// STORAGE_PATH valido nada e alterado (fail-closed). Mesmo padrao dos demais
// crons: rejeita execucao fora de PHP CLI (nao existe rota em public/api/
// para este script) e o log final e SEMPRE agregado (contagens), sem placa,
// pasta, caminho, nome de arquivo ou dado de nota.
//
// Ordem sugerida no cPanel: este script primeiro e limpar-notas-quarentena.php
// depois (qualquer ordem e segura: os .del novos sempre esperam 24 horas).
//
// Codigo de saida: 0 = sucesso (mesmo sem candidatos, ou com limite atingido);
// 1 = falha (.env/banco/STORAGE_PATH indisponivel, atendimento que falhou ao
// abandonar, ou foto sem caminho valido).

require_once __DIR__ . '/../vendor/autoload.php';

use Util\Bootstrap;
use Util\LogSistema;
use Util\NotaArquivoStorage;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Rn\AbandonoAtendimentoRn;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

try {
    $pdo = Bootstrap::conectar(__DIR__ . '/../');

    $storagePath = (string) ($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '');
    $storage = new NotaArquivoStorage($storagePath !== '' ? $storagePath : null);
    if ($storage->raizReal() === null) {
        error_log('abandonar-atendimentos: STORAGE_PATH ausente ou inacessivel -- nenhum atendimento alterado');
        LogSistema::registrar('cron_falhou', ['job' => 'abandonar_atendimentos', 'motivo' => 'config_ausente']);
        LogSistema::descarregar();
        exit(1);
    }

    $rn = new AbandonoAtendimentoRn(new AtendimentoDao($pdo), new AtendimentoNotaDao($pdo), $storage);
    $resultado = $rn->executar();
} catch (\PDOException $e) {
    $sqlstate = (string) $e->getCode();
    error_log('abandonar-atendimentos: falha de banco (PDOException)'
        . (preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? " [SQLSTATE={$sqlstate}]" : ''));
    LogSistema::registrar('cron_falhou', ['job' => 'abandonar_atendimentos', 'excecao' => $e, 'motivo' => 'erro_banco']);
    LogSistema::descarregar();
    exit(1);
} catch (\Throwable $e) {
    error_log('abandonar-atendimentos: falha de bootstrap ou execucao -- verificar .env, banco e STORAGE_PATH');
    LogSistema::registrar('cron_falhou', ['job' => 'abandonar_atendimentos', 'excecao' => $e, 'motivo' => 'falha_inesperada']);
    LogSistema::descarregar();
    exit(1);
}

error_log(sprintf(
    'abandonar-atendimentos: %d candidato(s), %d abandonado(s), %d ignorado(s) (voltaram a ficar ativos), %d falha(s), %d foto(s) em quarentena, %d foto(s) sem caminho valido, limite de %d por execucao %s',
    $resultado['candidatos'],
    $resultado['abandonados'],
    $resultado['ignorados'],
    $resultado['falhas'],
    $resultado['fotos_quarentenadas'],
    $resultado['fotos_sem_caminho_valido'],
    AbandonoAtendimentoRn::LIMITE_POR_EXECUCAO,
    $resultado['limite_atingido'] ? 'ATINGIDO (restante na proxima execucao)' : 'nao atingido'
));

if ($resultado['falhas'] > 0 || $resultado['fotos_sem_caminho_valido'] > 0) {
    LogSistema::registrar('cron_falhou', [
        'job' => 'abandonar_atendimentos',
        'motivo' => $resultado['falhas'] > 0 ? 'falha_inesperada' : 'dados_invalidos',
        'itens' => $resultado['abandonados'],
        'falhas' => $resultado['falhas'],
    ]);
} else {
    LogSistema::registrar('cron_resumo', ['job' => 'abandonar_atendimentos', 'itens' => $resultado['abandonados'], 'falhas' => 0]);
}
LogSistema::descarregar();

exit(($resultado['falhas'] > 0 || $resultado['fotos_sem_caminho_valido'] > 0) ? 1 : 0);
