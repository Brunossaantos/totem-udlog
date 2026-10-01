<?php

/**
 * Suite do ABANDONO (rodada corretiva /01, 2026-10-01) da demanda
 * hardening-revisao-notas-e-cliente: atendimento em_andamento sem atividade
 * por mais de 24 h passa ao estado terminal JA EXISTENTE 'cancelado' e as
 * fotos das notas vao para a quarentena (.del), onde ficam mais 24 h antes da
 * exclusao fisica (cron/limpar-notas-quarentena.php).
 *
 * Cobre: criterio de "sem atividade" (colunas existentes) e fronteira de 24 h;
 * ativo/recente nunca tocado; estados nao candidatos; envio ao Talent em
 * curso/aceito nunca abandonado; limite de 500 por execucao; consistencia
 * (rename -> UPDATE -> COMMIT com compensacao) com falhas reais/controladas;
 * revalidacao sob lock; concorrencia real com processar; retencao +24 h pelo
 * cron de limpeza; fail-closed (STORAGE_PATH/banco ausentes); logs agregados;
 * somente CLI.
 *
 * Banco `qa_` DESCARTAVEL (qa_r4be_) + STORAGE_PATH temporario. O cron roda de
 * verdade em subprocesso CLI contra o banco QA (DB_NAME/STORAGE_PATH por
 * ambiente). NUNCA toca banco/storage reais. Sem Talent/VIO/impressao.
 *
 * Uso: php tests/manual/teste_hardening_r4_abandono.php
 */

require_once __DIR__ . '/hardening_helpers.php';

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Rn\AbandonoAtendimentoRn;
use Util\NotaArquivoStorage;

$amb = hdCriarAmbiente('r4be_abandono');
$pdo = $amb['pdo'];
// mesmo fuso de Util\Conexao (NOW() e os envelhecimentos desta sessao)
$pdo->exec("SET time_zone = '-03:00'");

$logInProcess = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'inprocess.log';
@mkdir($amb['dir_tmp'], 0777, true);
file_put_contents($logInProcess, '');
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $logInProcess);

class R4DaoCandidatosForcados extends AtendimentoDao
{
    /** @param int[] $ids */
    public function __construct(\PDO $pdo, private array $ids)
    {
        parent::__construct($pdo);
    }

    public function listarCandidatosAbandono(int $inatividadeSegundos, int $limite): array
    {
        return $this->ids;
    }
}
class R4StorageFalhaNaSegunda extends NotaArquivoStorage
{
    private int $chamadas = 0;

    public function quarentenar(string $caminho, int $idNota): string
    {
        $this->chamadas++;
        if ($this->chamadas === 2) {
            throw new \RuntimeException('quarentena_falhou');
        }

        return parent::quarentenar($caminho, $idNota);
    }
}
class R4StorageRestauraFalha extends NotaArquivoStorage
{
    public function restaurar(string $caminho, int $idNota): bool
    {
        return false;
    }
}

function r4aEnvelhecer(PDO $pdo, int $idAtendimento, int $minutos): void
{
    $m = (int) $minutos;
    $pdo->prepare("UPDATE tb_atendimento SET atualizado_em = NOW() - INTERVAL {$m} MINUTE WHERE id_atendimento = :id")->execute(['id' => $idAtendimento]);
    $pdo->prepare("UPDATE tb_atendimento_nota SET criado_em = NOW() - INTERVAL {$m} MINUTE, processado_em = NULL WHERE id_atendimento = :id")->execute(['id' => $idAtendimento]);
}

function r4aStatus(PDO $pdo, int $id): string
{
    return (string) hdAtendimento($pdo, $id)['status'];
}

/** Inicia o cron de abandono CLI de verdade. @return array{proc:resource, pipes:array, log:string} */
function r4aIniciarCron(array $amb, array $envExtra = []): array
{
    $log = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'abandono_' . bin2hex(random_bytes(4)) . '.log';
    file_put_contents($log, '');
    $env = array_merge(getenv(), ['DB_NAME' => $amb['banco'], 'STORAGE_PATH' => $amb['storage']], $envExtra);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log="' . $log . '"', hdRaizProjeto() . '/cron/abandonar-atendimentos.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        hdRaizProjeto(),
        $env
    );

    return ['proc' => $proc, 'pipes' => $pipes, 'log' => $log];
}

/** @return array{exit:int, log:string, stdout:string} */
function r4aAguardarCron(array $h): array
{
    $stdout = stream_get_contents($h['pipes'][1]) . stream_get_contents($h['pipes'][2]);
    $codigo = proc_close($h['proc']);
    $log = (string) file_get_contents($h['log']);
    @unlink($h['log']);
    $GLOBALS['hd_logs_coletados'][] = $log;

    return ['exit' => $codigo, 'log' => $log, 'stdout' => $stdout];
}

function r4aCron(array $amb, array $envExtra = []): array
{
    return r4aAguardarCron(r4aIniciarCron($amb, $envExtra));
}

function r4aLimparQuarentenaCron(array $amb): array
{
    $log = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'limpar_' . bin2hex(random_bytes(4)) . '.log';
    file_put_contents($log, '');
    $env = array_merge(getenv(), ['STORAGE_PATH' => $amb['storage']]);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log="' . $log . '"', hdRaizProjeto() . '/cron/limpar-notas-quarentena.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        hdRaizProjeto(),
        $env
    );
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $codigo = proc_close($proc);
    $txt = (string) file_get_contents($log);
    @unlink($log);

    return ['exit' => $codigo, 'log' => $txt, 'stdout' => $out];
}

try {
    $idTotem = hdCriarTotem($pdo, 'R4-ABN');
    $storage = new NotaArquivoStorage($amb['storage']);
    $novoRn = static fn(?AtendimentoDao $dao = null, ?NotaArquivoStorage $st = null) => new AbandonoAtendimentoRn(
        $dao ?? new AtendimentoDao($GLOBALS['pdo_r4']),
        new AtendimentoNotaDao($GLOBALS['pdo_r4']),
        $st ?? $GLOBALS['storage_r4']
    );
    $GLOBALS['pdo_r4'] = $pdo;
    $GLOBALS['storage_r4'] = $storage;

    // ---------------------------------------------------------------
    hdSecao('A1 abandono real (cron CLI): cancelado + fotos em quarentena, nada apagado');
    $at = hdCriarAtendimento($amb, $idTotem, 'ABN1A23');
    $n1 = hdCriarNota($pdo, $at, 1, '1001');
    $n2 = hdCriarNota($pdo, $at, 3, '1003');
    file_put_contents($at['dir'] . '/cnh_frente.jpg', 'documento CNH');
    $bytes1 = file_get_contents($at['dir'] . '/nota_01.jpg');
    touch($at['dir'] . '/nota_01.jpg', time() - 5 * 86400);
    r4aEnvelhecer($pdo, $at['id'], 25 * 60);
    $r = r4aCron($amb);
    $arqs = hdArquivosDaPasta($at);
    hdAfirmar('A1: saida 0', $r['exit'] === 0);
    hdAfirmar('A1: status = cancelado (estado terminal ja existente; NAO bloqueado nem concluido)', r4aStatus($pdo, $at['id']) === 'cancelado');
    hdAfirmar('A1: fotos das notas viraram nota_NN.jpg.<id_nota>.del (nao apagadas)', in_array("nota_01.jpg.{$n1}.del", $arqs, true) && in_array("nota_03.jpg.{$n2}.del", $arqs, true) && !in_array('nota_01.jpg', $arqs, true) && !in_array('nota_03.jpg', $arqs, true));
    hdAfirmar('A1: conteudo da foto preservado na quarentena', file_get_contents($at['dir'] . "/nota_01.jpg.{$n1}.del") === $bytes1);
    hdAfirmar('A1: mtime do .del renovado (as +24 h contam da quarentena, nao da captura de 5 dias atras)', abs(filemtime($at['dir'] . "/nota_01.jpg.{$n1}.del") - time()) < 120);
    hdAfirmar('A1: linhas das notas continuam no banco', count(hdNotas($pdo, $at['id'])) === 2);
    hdAfirmar('A1: CNH e demais documentos NAO sao tocados', file_get_contents($at['dir'] . '/cnh_frente.jpg') === 'documento CNH');
    hdAfirmar('A1: log agregado (1 candidato, 1 abandonado, 2 fotos em quarentena, limite nao atingido)', str_contains($r['log'], '1 candidato(s), 1 abandonado(s), 0 ignorado(s)') && str_contains($r['log'], '2 foto(s) em quarentena') && str_contains($r['log'], 'nao atingido'));
    hdAfirmar('A1: log SEM placa, pasta, caminho, nome de arquivo ou id', !preg_match('/ABN1A23|nota_0|\.jpg|\.del|\d{4}-\d{2}-\d{2}\/|' . preg_quote($amb['storage'], '/') . '/', $r['log']));
    $r = r4aCron($amb);
    hdAfirmar('A1: idempotente: 2a execucao nao tem candidatos e sai 0', $r['exit'] === 0 && str_contains($r['log'], '0 candidato(s), 0 abandonado(s)'));

    hdSecao('A1b abandono em etapa avancada e atendimento de expedicao (sem notas)');
    $atEt = hdCriarAtendimento($amb, $idTotem, 'ABN2A23', 'em_andamento', 'rec_cnh');
    $e1 = hdCriarNota($pdo, $atEt, 1, '2001', 'IDENTIFICADA', null);
    r4aEnvelhecer($pdo, $atEt['id'], 30 * 60);
    $atExp = hdCriarAtendimento($amb, $idTotem, 'ABN3A23', 'em_andamento', 'exp_cnh', 'expedicao');
    r4aEnvelhecer($pdo, $atExp['id'], 40 * 60);
    $r = r4aCron($amb);
    hdAfirmar('A1b: etapa rec_cnh abandonada (cancelado) com foto em quarentena', r4aStatus($pdo, $atEt['id']) === 'cancelado' && in_array("nota_01.jpg.{$e1}.del", hdArquivosDaPasta($atEt), true));
    hdAfirmar('A1b: expedicao abandonada (cancelado) sem erro e sem notas', r4aStatus($pdo, $atExp['id']) === 'cancelado' && $r['exit'] === 0);

    // ---------------------------------------------------------------
    hdSecao('A2 atividade: fronteira de 24 h e atendimento ativo/recente intocado');
    $atR = hdCriarAtendimento($amb, $idTotem, 'ABN4A23');
    hdCriarNota($pdo, $atR, 1, '3001');
    r4aEnvelhecer($pdo, $atR['id'], 23 * 60 + 50);
    $atL = hdCriarAtendimento($amb, $idTotem, 'ABN5A23');
    hdCriarNota($pdo, $atL, 1, '3101');
    r4aEnvelhecer($pdo, $atL['id'], 24 * 60 + 10);
    $atN = hdCriarAtendimento($amb, $idTotem, 'ABN6A23'); // recem criado
    hdCriarNota($pdo, $atN, 1, '3201');
    $r = r4aCron($amb);
    hdAfirmar('A2: 23 h 50 min (recente) NAO e abandonado e as fotos ficam no lugar', r4aStatus($pdo, $atR['id']) === 'em_andamento' && hdArquivosDaPasta($atR) === ['nota_01.jpg']);
    hdAfirmar('A2: 24 h 10 min E abandonado', r4aStatus($pdo, $atL['id']) === 'cancelado' && count(array_filter(hdArquivosDaPasta($atL), static fn($x) => str_ends_with($x, '.del'))) === 1);
    hdAfirmar('A2: atendimento recem criado intocado', r4aStatus($pdo, $atN['id']) === 'em_andamento' && hdArquivosDaPasta($atN) === ['nota_01.jpg']);
    hdAfirmar('A2: exatamente 1 abandonado nesta execucao', str_contains($r['log'], '1 candidato(s), 1 abandonado(s)'));

    hdSecao('A3 atividade em colunas de nota conta (criado_em/processado_em): atendimento NAO e abandonado');
    $atA = hdCriarAtendimento($amb, $idTotem, 'ABN7A23');
    hdCriarNota($pdo, $atA, 1, '4001', 'IDENTIFICADA');
    hdCriarNota($pdo, $atA, 2, '4002', 'PENDENTE');
    r4aEnvelhecer($pdo, $atA['id'], 30 * 60);
    // atividade recente SO na nota: processado_em = agora (resultado de OCR chegou)
    $pdo->prepare("UPDATE tb_atendimento_nota SET processado_em = NOW() - INTERVAL 60 MINUTE WHERE id_atendimento = :id AND ordem = 1")->execute(['id' => $atA['id']]);
    $atB = hdCriarAtendimento($amb, $idTotem, 'ABN8A23');
    hdCriarNota($pdo, $atB, 1, '4101');
    r4aEnvelhecer($pdo, $atB['id'], 30 * 60);
    // atividade recente SO em criado_em de uma nota nova
    $pdo->prepare("UPDATE tb_atendimento_nota SET criado_em = NOW() - INTERVAL 120 MINUTE WHERE id_atendimento = :id")->execute(['id' => $atB['id']]);
    $atC = hdCriarAtendimento($amb, $idTotem, 'ABN9A23');
    hdCriarNota($pdo, $atC, 1, '4201');
    r4aEnvelhecer($pdo, $atC['id'], 30 * 60); // tudo antigo: abandonado (controle)
    $r = r4aCron($amb);
    hdAfirmar('A3: processado_em recente (1 h) impede o abandono', r4aStatus($pdo, $atA['id']) === 'em_andamento' && count(hdArquivosDaPasta($atA)) === 2 && !preg_grep('/\.del$/', hdArquivosDaPasta($atA)));
    hdAfirmar('A3: criado_em de nota recente (2 h) impede o abandono', r4aStatus($pdo, $atB['id']) === 'em_andamento' && hdArquivosDaPasta($atB) === ['nota_01.jpg']);
    hdAfirmar('A3: controle: tudo antigo = abandonado', r4aStatus($pdo, $atC['id']) === 'cancelado');

    // ---------------------------------------------------------------
    hdSecao('A4 estados que NAO sao candidatos (concluido, cancelado, bloqueado) ficam como estao');
    $naoCand = [];
    foreach (['concluido' => 'impressao', 'cancelado' => 'digitalizacao_notas', 'bloqueado' => 'balcao_portaria'] as $st => $etapa) {
        $a = hdCriarAtendimento($amb, $idTotem, 'ABS' . strtoupper(substr($st, 0, 1)) . '1A23', $st, $etapa);
        hdCriarNota($pdo, $a, 1, '5001');
        r4aEnvelhecer($pdo, $a['id'], 72 * 60);
        $naoCand[$st] = $a;
    }
    $r = r4aCron($amb);
    foreach ($naoCand as $st => $a) {
        hdAfirmar("A4: {$st} antigo intocado (status e fotos)", r4aStatus($pdo, $a['id']) === $st && hdArquivosDaPasta($a) === ['nota_01.jpg']);
    }
    hdAfirmar('A4: nenhum candidato nesta execucao', str_contains($r['log'], '0 candidato(s)'));

    hdSecao('A5 envio ao Talent em curso/aceito NUNCA e abandonado; NAO_ENVIADO e ERRO_REPROCESSAVEL sao');
    $porTalent = [];
    foreach (['ENVIANDO', 'ENVIADO', 'ENVIO_INDETERMINADO', 'NAO_ENVIADO', 'ERRO_REPROCESSAVEL'] as $i => $ts) {
        $a = hdCriarAtendimento($amb, $idTotem, 'ABT' . $i . 'A23');
        hdCriarNota($pdo, $a, 1, '6001');
        $pdo->prepare('UPDATE tb_atendimento SET talent_checkin_status = :s WHERE id_atendimento = :id')->execute(['s' => $ts, 'id' => $a['id']]);
        r4aEnvelhecer($pdo, $a['id'], 48 * 60);
        $porTalent[$ts] = $a;
    }
    $r = r4aCron($amb);
    foreach (['ENVIANDO', 'ENVIADO', 'ENVIO_INDETERMINADO'] as $ts) {
        hdAfirmar("A5: talent_checkin_status {$ts} intocado (em_andamento, foto no lugar)", r4aStatus($pdo, $porTalent[$ts]['id']) === 'em_andamento' && hdArquivosDaPasta($porTalent[$ts]) === ['nota_01.jpg']);
    }
    foreach (['NAO_ENVIADO', 'ERRO_REPROCESSAVEL'] as $ts) {
        hdAfirmar("A5: talent_checkin_status {$ts} abandonado", r4aStatus($pdo, $porTalent[$ts]['id']) === 'cancelado');
    }

    // ---------------------------------------------------------------
    hdSecao('A6 limite de 500 por execucao (520 atendimentos abandonaveis)');
    $pdo->exec("DELETE FROM tb_atendimento_nota");
    $pdo->exec("UPDATE tb_atendimento SET status = 'concluido' WHERE status = 'em_andamento'"); // zera candidatos antigos
    $ins = $pdo->prepare("
        INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, etapa_atual, placa)
        VALUES (:codigo, :totem, 'expedicao', 'em_andamento', 'placa', 'LIM0001')
    ");
    for ($i = 0; $i < 520; $i++) {
        $ins->execute(['codigo' => bin2hex(random_bytes(18)), 'totem' => $idTotem]);
    }
    $pdo->exec("UPDATE tb_atendimento SET atualizado_em = NOW() - INTERVAL 3 DAY WHERE placa = 'LIM0001'");
    $r1 = r4aCron($amb);
    $abandonados1 = (int) $pdo->query("SELECT COUNT(*) FROM tb_atendimento WHERE placa = 'LIM0001' AND status = 'cancelado'")->fetchColumn();
    hdAfirmar('A6: 1a execucao abandona EXATAMENTE 500 dos 520', $abandonados1 === 500 && $r1['exit'] === 0);
    hdAfirmar('A6: log informa o limite ATINGIDO', str_contains($r1['log'], '500 candidato(s), 500 abandonado(s)') && str_contains($r1['log'], 'ATINGIDO'));
    $r2 = r4aCron($amb);
    $abandonados2 = (int) $pdo->query("SELECT COUNT(*) FROM tb_atendimento WHERE placa = 'LIM0001' AND status = 'cancelado'")->fetchColumn();
    hdAfirmar('A6: 2a execucao abandona os 20 restantes sem atingir o limite', $abandonados2 === 520 && str_contains($r2['log'], '20 candidato(s), 20 abandonado(s)') && str_contains($r2['log'], 'nao atingido'));
    $pdo->exec("UPDATE tb_atendimento SET status = 'concluido' WHERE placa = 'LIM0001'");

    // ---------------------------------------------------------------
    hdSecao('A7 consistencia: falhas antes do COMMIT devolvem as fotos e mantem em_andamento (in-process)');
    $criarComDuasNotas = static function (string $placa) use ($amb, $idTotem, $pdo) {
        $a = hdCriarAtendimento($amb, $idTotem, $placa);
        $x1 = hdCriarNota($pdo, $a, 1, '7001');
        $x2 = hdCriarNota($pdo, $a, 2, '7002');
        r4aEnvelhecer($pdo, $a['id'], 26 * 60);

        return [$a, $x1, $x2];
    };
    $intacto = static fn(array $a): bool => hdArquivosDaPasta($a) === ['nota_01.jpg', 'nota_02.jpg'];

    [$a1] = $criarComDuasNotas('ABF1A23');
    $res = $novoRn(null, new R4StorageFalhaNaSegunda($amb['storage']))->executar();
    hdAfirmar('A7a: falha do rename da 2a foto: 1a foto DEVOLVIDA, em_andamento, falhas = 1, abandonados = 0', $res['falhas'] === 1 && $res['abandonados'] === 0 && r4aStatus($pdo, $a1['id']) === 'em_andamento' && $intacto($a1));

    [$a2] = $criarComDuasNotas('ABF2A23');
    // o atendimento a1 ainda e candidato: usa so os ids de a2
    $res = $novoRn(new R4DaoCandidatosForcados($pdo, [$a2['id']]) )->executar();
    hdAfirmar('A7b: controle sem falha: abandona a2 (a1 segue pendente por causa da falha anterior)', $res['abandonados'] === 1 && r4aStatus($pdo, $a2['id']) === 'cancelado');
    // desfaz a2 para reutilizar? nao: novo atendimento por caso

    [$a3] = $criarComDuasNotas('ABF3A23');
    $daoCommit = new class($pdo, [$a3['id']]) extends R4DaoCandidatosForcados {
        public function confirmarTransacao(): void
        {
            throw new \PDOException('SQLSTATE[HY000]: SENTINELA_COMMIT_ABANDONO_SECRETA');
        }
    };
    $res = $novoRn($daoCommit)->executar();
    hdAfirmar('A7c: COMMIT falha: fotos de volta, em_andamento, falhas = 1', $res['falhas'] === 1 && r4aStatus($pdo, $a3['id']) === 'em_andamento' && $intacto($a3));

    [$a4] = $criarComDuasNotas('ABF4A23');
    $daoCas = new class($pdo, [$a4['id']]) extends R4DaoCandidatosForcados {
        public function marcarAbandonado(int $id): bool
        {
            return false;
        }
    };
    $res = $novoRn($daoCas)->executar();
    hdAfirmar('A7d: CAS perdido (UPDATE 0 linhas): fotos de volta, em_andamento, falhas = 1', $res['falhas'] === 1 && r4aStatus($pdo, $a4['id']) === 'em_andamento' && $intacto($a4));

    [$a5] = $criarComDuasNotas('ABF5A23');
    $daoUpd = new class($pdo, [$a5['id']]) extends R4DaoCandidatosForcados {
        public function marcarAbandonado(int $id): bool
        {
            throw new \PDOException('SQLSTATE[HY000]: SENTINELA_UPDATE_ABANDONO_SECRETA');
        }
    };
    $res = $novoRn($daoUpd)->executar();
    hdAfirmar('A7e: UPDATE lanca excecao (rename ja feito): fotos de volta, em_andamento, falhas = 1', $res['falhas'] === 1 && r4aStatus($pdo, $a5['id']) === 'em_andamento' && $intacto($a5));

    [$a6, $f1, $f2] = $criarComDuasNotas('ABF6A23');
    $res = $novoRn(new class($pdo, [$a6['id']]) extends R4DaoCandidatosForcados {
        public function marcarAbandonado(int $id): bool
        {
            return false;
        }
    }, new R4StorageRestauraFalha($amb['storage']))->executar();
    hdAfirmar('A7f: falha do UPDATE E do restaurar: em_andamento (ROLLBACK), fotos em quarentena (recuperaveis)', $res['falhas'] === 1 && r4aStatus($pdo, $a6['id']) === 'em_andamento' && in_array("nota_01.jpg.{$f1}.del", hdArquivosDaPasta($a6), true) && in_array("nota_02.jpg.{$f2}.del", hdArquivosDaPasta($a6), true));
    $res = $novoRn(new R4DaoCandidatosForcados($pdo, [$a6['id']]))->executar();
    hdAfirmar('A7f: a proxima execucao conclui: abandona e reconhece as fotos ja em quarentena', $res['abandonados'] === 1 && r4aStatus($pdo, $a6['id']) === 'cancelado' && count(array_filter(hdArquivosDaPasta($a6), static fn($x) => str_ends_with($x, '.del'))) === 2);

    // isola os atendimentos deixados em_andamento pelas falhas acima das execucoes seguintes
    $pdo->prepare("UPDATE tb_atendimento SET status = 'concluido' WHERE id_atendimento IN (:a, :b, :c, :d)")
        ->execute(['a' => $a1['id'], 'b' => $a3['id'], 'c' => $a4['id'], 'd' => $a5['id']]);

    $logIn = (string) file_get_contents($logInProcess);
    hdAfirmar('A7: logs das falhas sem excecao bruta (sentinelas), caminho, placa, pasta ou nome de arquivo', !preg_match('/SENTINELA_|SQLSTATE|Stack trace|ABF\dA23|nota_0|\.jpg|\d{4}-\d{2}-\d{2}\/|' . preg_quote($amb['storage'], '/') . '/', $logIn));
    hdAfirmar('A7: falha do restaurar registrada com contexto fixo e so ids', str_contains($logIn, 'abandono: FALHA ao restaurar a foto da quarentena apos rollback') && str_contains($logIn, 'abandono: falha ao abandonar atendimento, mantido em_andamento [RuntimeException]'));

    // ---------------------------------------------------------------
    hdSecao('A8 revalidacao sob lock: candidato que voltou a ficar ativo/terminal nao e abandonado');
    $ativo = hdCriarAtendimento($amb, $idTotem, 'ABR1A23');
    hdCriarNota($pdo, $ativo, 1, '8001');                          // recente
    $concl = hdCriarAtendimento($amb, $idTotem, 'ABR2A23', 'concluido', 'impressao');
    hdCriarNota($pdo, $concl, 1, '8101');
    r4aEnvelhecer($pdo, $concl['id'], 3000);
    $enviando = hdCriarAtendimento($amb, $idTotem, 'ABR3A23');
    hdCriarNota($pdo, $enviando, 1, '8201');
    $pdo->prepare("UPDATE tb_atendimento SET talent_checkin_status = 'ENVIANDO' WHERE id_atendimento = :id")->execute(['id' => $enviando['id']]);
    r4aEnvelhecer($pdo, $enviando['id'], 3000);
    $res = $novoRn(new R4DaoCandidatosForcados($pdo, [$ativo['id'], $concl['id'], $enviando['id'], 987654321]))->executar();
    hdAfirmar('A8: 4 candidatos listados, 0 abandonados, 4 ignorados na revalidacao (inclusive id inexistente)', $res['candidatos'] === 4 && $res['abandonados'] === 0 && $res['ignorados'] === 4 && $res['falhas'] === 0);
    hdAfirmar('A8: nenhum status mudou e nenhuma foto foi movida', r4aStatus($pdo, $ativo['id']) === 'em_andamento' && r4aStatus($pdo, $concl['id']) === 'concluido' && r4aStatus($pdo, $enviando['id']) === 'em_andamento' && hdArquivosDaPasta($ativo) === ['nota_01.jpg'] && hdArquivosDaPasta($concl) === ['nota_01.jpg'] && hdArquivosDaPasta($enviando) === ['nota_01.jpg']);

    // ---------------------------------------------------------------
    hdSecao('A9 concorrencia real: processar (atividade) x cron de abandono');
    $violacoes = 0;
    $desfechos = ['abandonado' => 0, 'ativo' => 0];
    for ($i = 0; $i < 6; $i++) {
        $ac = hdCriarAtendimento($amb, $idTotem, 'ABC' . $i . 'A23');
        $c1 = hdCriarNota($pdo, $ac, 1, '90' . $i . '1');
        $c2 = hdCriarNota($pdo, $ac, 2, '90' . $i . '2');
        r4aEnvelhecer($pdo, $ac['id'], 25 * 60);
        $hRunner = hdIniciar($amb, 'nota.processar', $idTotem, ['id_atendimento' => $ac['id'], 'imagem' => hdJpegDataUrl(700 + $i), 'uid' => 'conc_aband_' . $i . '_x1']);
        $hCron = r4aIniciarCron($amb);
        $rp = hdAguardar($hRunner);
        $rc = r4aAguardarCron($hCron);
        $arqs = hdArquivosDaPasta($ac);
        $dels = array_values(array_filter($arqs, static fn($x) => str_ends_with($x, '.del')));
        $status = r4aStatus($pdo, $ac['id']);
        if ($status === 'cancelado') {
            $desfechos['abandonado']++;
            // abandonou primeiro: o processar foi barrado e as 2 fotos antigas estao em quarentena, nenhuma nova
            if ($rp['http'] !== 400 || count($dels) !== 2 || count($arqs) !== 2 || count(hdNotas($pdo, $ac['id'])) !== 2) {
                $violacoes++;
            }
        } else {
            $desfechos['ativo']++;
            // processar venceu: atendimento ativo, NENHUMA foto movida, 3 notas
            if ($status !== 'em_andamento' || $rp['http'] !== 200 || $dels !== [] || count($arqs) !== 3 || count(hdNotas($pdo, $ac['id'])) !== 3) {
                $violacoes++;
            }
        }
        if ($rc['exit'] !== 0) {
            $violacoes++;
        }
    }
    hdAfirmar('A9: 6 rodadas paralelas sem violacao (cancelado => processar barrado e fotos em quarentena; ativo => nada movido)', $violacoes === 0);
    echo '  (desfechos: ' . json_encode($desfechos) . ")\n";

    // ---------------------------------------------------------------
    hdSecao('A10 pasta/arquivo fora do formato (legado): abandona sem tocar disco e sinaliza (saida 1)');
    $atLeg = hdCriarAtendimento($amb, $idTotem, 'ABL1A23');
    hdCriarNota($pdo, $atLeg, 1, '9901');
    $pdo->prepare('UPDATE tb_atendimento SET pasta_documentos = NULL WHERE id_atendimento = :id')->execute(['id' => $atLeg['id']]);
    r4aEnvelhecer($pdo, $atLeg['id'], 30 * 60);
    $r = r4aCron($amb);
    hdAfirmar('A10: abandonado (cancelado) e foto intocada (sem caminho valido nao ha acesso a disco)', r4aStatus($pdo, $atLeg['id']) === 'cancelado' && hdArquivosDaPasta($atLeg) === ['nota_01.jpg']);
    hdAfirmar('A10: saida 1 e log informa 1 foto sem caminho valido (anomalia visivel, nao sucesso silencioso)', $r['exit'] === 1 && str_contains($r['log'], '1 foto(s) sem caminho valido'));

    // ---------------------------------------------------------------
    hdSecao('A11 retencao: .del novo fica 24 h; so depois o cron de limpeza apaga');
    $atRet = hdCriarAtendimento($amb, $idTotem, 'ABK1A23');
    $k1 = hdCriarNota($pdo, $atRet, 1, '9801');
    r4aEnvelhecer($pdo, $atRet['id'], 26 * 60);
    r4aCron($amb);
    $del = $atRet['dir'] . "/nota_01.jpg.{$k1}.del";
    hdAfirmar('A11: .del criado pelo abandono', is_file($del));
    $l = r4aLimparQuarentenaCron($amb);
    hdAfirmar('A11: limpeza logo apos o abandono NAO apaga o .del (retencao de mais 24 h)', is_file($del) && $l['exit'] === 0);
    touch($del, time() - 23 * 3600);
    $l = r4aLimparQuarentenaCron($amb);
    hdAfirmar('A11: com 23 h de quarentena ainda fica', is_file($del));
    touch($del, time() - 25 * 3600);
    $l = r4aLimparQuarentenaCron($amb);
    hdAfirmar('A11: com 25 h de quarentena a limpeza apaga o .del', !is_file($del) && $l['exit'] === 0);
    hdAfirmar('A11: a linha da nota continua (so a foto e removida)', count(hdNotas($pdo, $atRet['id'])) === 1);

    // ---------------------------------------------------------------
    hdSecao('A12 fail-closed: STORAGE_PATH invalido e banco indisponivel');
    $atFc = hdCriarAtendimento($amb, $idTotem, 'ABM1A23');
    hdCriarNota($pdo, $atFc, 1, '9701');
    r4aEnvelhecer($pdo, $atFc['id'], 30 * 60);
    $r = r4aCron($amb, ['STORAGE_PATH' => $amb['storage'] . '_inexistente_r4']);
    hdAfirmar('A12: STORAGE_PATH inexistente = saida 1 e NENHUM atendimento alterado', $r['exit'] === 1 && r4aStatus($pdo, $atFc['id']) === 'em_andamento' && hdArquivosDaPasta($atFc) === ['nota_01.jpg']);
    hdAfirmar('A12: log fixo, sem caminho', str_contains($r['log'], 'STORAGE_PATH ausente ou inacessivel') && !str_contains($r['log'], '_inexistente_r4'));
    $r = r4aCron($amb, ['DB_NAME' => 'qa_r4be_banco_que_nao_existe']);
    hdAfirmar('A12: banco indisponivel = saida 1, log fixo, sem host/usuario/banco', $r['exit'] === 1 && !str_contains($r['log'], 'qa_r4be_banco') && !preg_match('/Access denied|Unknown database|SQLSTATE\[/', $r['log'] . $r['stdout']));
    hdAfirmar('A12: atendimento continua intacto', r4aStatus($pdo, $atFc['id']) === 'em_andamento');
    $r = r4aCron($amb);
    hdAfirmar('A12: com o ambiente correto o mesmo atendimento e abandonado', $r['exit'] === 0 && r4aStatus($pdo, $atFc['id']) === 'cancelado');

    hdSecao('A13 somente CLI e sem rota publica');
    $fonteCron = (string) file_get_contents(__DIR__ . '/../../cron/abandonar-atendimentos.php');
    hdAfirmar('A13: rejeita execucao fora do PHP CLI (403 + exit 1)', str_contains($fonteCron, "PHP_SAPI !== 'cli'") && str_contains($fonteCron, 'http_response_code(403)'));
    hdAfirmar('A13: nao existe rota em public/ para o script', !file_exists(__DIR__ . '/../../public/api/abandonar-atendimentos.php'));
    hdAfirmar('A13: constantes da politica: 24 h e 500 por execucao', AbandonoAtendimentoRn::INATIVIDADE_SEGUNDOS === 86400 && AbandonoAtendimentoRn::LIMITE_POR_EXECUCAO === 500);

    // ---------------------------------------------------------------
    hdSecao('A14 logs de todos os subprocessos agregados e sem dado pessoal');
    $todos = hdTodosLogs();
    hdAfirmar('A14: logs coletados', strlen($todos) > 0);
    hdAfirmar('A14: nenhum log de cron contem placa, pasta, nome de arquivo, caminho, sentinela', !preg_match('/AB[A-Z0-9]{4,6}|nota_0|\.jpg|\.del|SENTINELA_|' . preg_quote($amb['storage'], '/') . '/', $todos));
    hdAfirmar('A14: nenhum warning/notice/fatal nos logs do PHP', !preg_match('/Warning|Notice|Deprecated|Fatal|Stack trace/i', $todos));
} finally {
    hdDestruirAmbiente($amb);
}

hdEncerrar('teste_hardening_r4_abandono');
