<?php

/**
 * Teste manual da demanda remocao-fila-reenvio-talent (2026-10-05): a fila de
 * reenvio ao Talent (tb_fila_envio, FilaEnvioDao, cron/reenviar-fila.php) foi
 * removida e a tabela nao existe mais.
 *
 * Parte 1 (estatica, sem banco): nenhuma referencia remanescente em app/ cron/
 * public/ util/, arquivos removidos, schema.sql sem a tabela, assinatura nova do
 * construtor de TalentRn, textos fixos por categoria do 202.
 *
 * Parte 2 (banco QA descartavel qa_qr_exclusivo_<hex>, nunca udlog_totem): tabela
 * inexistente,
 * finalizar() com Talent falso/mock = 202 com os textos esperados, sem a frase
 * antiga, UMA linha de log sanitizada, nova tentativa sem excecao de banco,
 * processarCheckin com ENVIO_INDETERMINADO sem chamar o client.
 *
 * Uso: php tests/manual/teste_sem_fila_envio.php
 */

require_once __DIR__ . '/qa_qr_exclusivo_legado.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Rn\TalentClient;
use App\Rn\TalentClientException;
use App\Rn\TalentRn;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

$raiz = dirname(__DIR__, 2);
const FRASE_ANTIGA = 'sera processada em instantes';
const TEXTO_PADRAO = 'Nao foi possivel concluir o check-in. Chame o atendimento.';
const TEXTO_FORA = 'O sistema Talent esta fora do ar ou nao respondeu, e o check-in nao foi concluido. Chame o atendimento.';
const TEXTO_SERVIDOR = 'O sistema Talent informou um erro interno e o check-in nao foi concluido. Chame o atendimento.';
const MSG_TALENT = 'Reg. acesso ajudante. Cracha ja associado a outro ajudante. Acao nao permitida.';

// ============================================================
// Parte 1: estatica
// ============================================================
$proibido = '/FilaEnvioDao|filaDao|tb_fila_envio|registrarFalhaParaReenvio|reenviar-fila/';
$achados = [];
foreach (['app', 'cron', 'public', 'util'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !preg_match('/\.(php|js|html|json|sql|md)$/i', $f->getFilename())) continue;
        if (preg_match($proibido, (string) file_get_contents($f->getPathname()))) $achados[] = substr($f->getPathname(), strlen($raiz) + 1);
    }
}
afirmar('nenhuma referencia a fila de reenvio em app/ cron/ public/ util/' . ($achados ? ' (' . implode(', ', $achados) . ')' : ''), $achados === []);
afirmar('app/Dao/FilaEnvioDao.php removido', !file_exists($raiz . '/app/Dao/FilaEnvioDao.php'));
afirmar('cron/reenviar-fila.php removido', !file_exists($raiz . '/cron/reenviar-fila.php'));
$schema = (string) file_get_contents($raiz . '/sql/schema.sql');
afirmar('sql/schema.sql nao cria mais tb_fila_envio', !preg_match('/CREATE\s+TABLE\s+`?tb_fila_envio/i', $schema));
afirmar('classe FilaEnvioDao nao e carregavel', !class_exists('App\\Dao\\FilaEnvioDao'));
afirmar('TalentRn::registrarFalhaParaReenvio nao existe', !method_exists(TalentRn::class, 'registrarFalhaParaReenvio'));
$params = array_map(fn ($p) => $p->getName(), (new ReflectionMethod(TalentRn::class, '__construct'))->getParameters());
afirmar('construtor de TalentRn: talentClient, atendimentoDao, caminhoBase, leitorAnexoOrdemColeta', $params === ['talentClient', 'atendimentoDao', 'caminhoBase', 'leitorAnexoOrdemColeta']);

$mTexto = new ReflectionMethod(\App\Controller\AtendimentoController::class, 'textoFalhaCheckin');
$mTexto->setAccessible(true);
$esperado = ['timeout' => TEXTO_FORA, 'erro_indeterminado' => TEXTO_FORA, 'erro_conexao' => TEXTO_FORA, 'erro_servidor' => TEXTO_SERVIDOR,
    'erro_validacao' => TEXTO_PADRAO, 'conflito' => TEXTO_PADRAO, 'erro_http' => TEXTO_PADRAO, 'erro_montagem_payload' => TEXTO_PADRAO, 'erro_desconhecido' => TEXTO_PADRAO];
$okTextos = true; $okSemPromessa = true;
foreach ($esperado as $cat => $txt) {
    $r = $mTexto->invoke(null, $cat);
    $okTextos = $okTextos && $r === $txt;
    $okSemPromessa = $okSemPromessa && !str_contains($r, FRASE_ANTIGA) && !str_contains(mb_strtolower($r), 'instantes') && str_ends_with($r, 'Chame o atendimento.');
}
afirmar('textos fixos por categoria do 202 (allowlist)', $okTextos);
afirmar('textos do 202 sem promessa de reprocessamento e sempre orientando chamar o atendimento', $okSemPromessa);
afirmar('TalentClientException::CATEGORIAS_VALIDAS cobre as categorias do mapa de textos (exceto internas)', count(array_diff(array_keys($esperado), TalentClientException::CATEGORIAS_VALIDAS, ['erro_montagem_payload', 'erro_desconhecido'])) === 0);

// ============================================================
// Parte 2: banco QA descartavel
// ============================================================
$banco = null; $storage = null; $pdo = null; $processo = null; $logs = [];
try {
    [$pdo, $banco, $storage] = qaLegadoCriarAmbiente();
    $dir = __DIR__;
    $tabelaExiste = fn () => (int) $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_fila_envio'")->fetchColumn() === 1;
    $pdo->exec('USE `' . $banco . '`');

    afirmar('banco QA novo (schema.sql): tb_fila_envio inexistente', !$tabelaExiste());

    $pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $atendimentoDao = new AtendimentoDao($pdo);
    $notaDao = new AtendimentoNotaDao($pdo);
    $idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_SEMFILA_' . bin2hex(random_bytes(3)), 1);

    $novoAtendimento = function (string $placa) use ($pdo, $atendimentoDao, $notaDao, $idTotem): int {
        $fix = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', $placa);
        talentInserirNotaComNumero($notaDao, $fix['id_atendimento'], 1, '12345');
        return $fix['id_atendimento'];
    };
    $estado = function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT talent_checkin_status, talent_senha, status FROM tb_atendimento WHERE id_atendimento = :id');
        $s->execute(['id' => $id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    };
    $novoLog = function () use (&$logs): string {
        $p = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_semfila_log_' . bin2hex(random_bytes(6)) . '.log';
        file_put_contents($p, '');
        $logs[] = $p;
        return $p;
    };
    $partir = function (string $saida): array {
        $p = explode("\nHTTP_CODE:", $saida);
        return ['corpo' => json_decode(trim(str_replace("CLIENT_CHAMADO\n", '', $p[0])), true), 'bruto' => $p[0], 'http' => (int) trim($p[1] ?? '0'), 'chamado' => substr_count($p[0], 'CLIENT_CHAMADO')];
    };
    $falso = function (int $id, string $categoria, string $log, string $msg = '') use ($dir, $idTotem, $partir): array {
        $r = qaLegadoRodar($dir . '/_caso_finalizar_talent_falso.php', [$idTotem, $id, $categoria, $log, $msg]);
        return $partir($r['saida']);
    };
    $linhasLog = fn (string $arq): array => array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($arq))), fn ($l) => $l !== ''));
    $umaLinhaSanitizada = function (string $arq, int $id, string $categoria) use ($linhasLog): bool {
        $l = $linhasLog($arq);
        return count($l) === 1
            && preg_match('/\[AtendimentoController\] checkin_talent_erro_reprocessavel id_atendimento=' . $id . ' categoria=' . $categoria . '$/', $l[0]) === 1
            && !str_contains($l[0], MSG_TALENT) && !str_contains($l[0], 'Bearer') && !preg_match('/\d{11}/', $l[0]);
    };

    // --- Talent falso: erro_conexao, erro_servidor, erro_validacao com mensagem_api ---
    $casos = [
        ['erro_conexao', '', TEXTO_FORA, false],
        ['erro_servidor', '', TEXTO_SERVIDOR, false],
        ['erro_validacao', MSG_TALENT, TEXTO_PADRAO, true],
        ['conflito', MSG_TALENT, TEXTO_PADRAO, true],
    ];
    foreach ($casos as $i => [$cat, $msg, $texto, $comMsg]) {
        $id = $novoAtendimento('SFL' . $i . '001');
        $log = $novoLog();
        $r = $falso($id, $cat, $log, $msg);
        $c = $r['corpo'];
        afirmar("[falso:{$cat}] HTTP 202, sucesso:false e texto fixo da categoria", $r['http'] === 202 && ($c['sucesso'] ?? null) === false && ($c['erro'] ?? null) === $texto);
        afirmar("[falso:{$cat}] sem a frase antiga e sem promessa de reprocessamento", !str_contains($r['bruto'], FRASE_ANTIGA) && !str_contains(mb_strtolower($r['bruto']), 'instantes'));
        afirmar("[falso:{$cat}] dados.mensagem_api " . ($comMsg ? 'e o retorno real da Talent' : 'ausente (nada inventado)'), $comMsg ? (($c['dados']['mensagem_api'] ?? null) === $msg) : (!isset($c['dados']) && !str_contains($r['bruto'], 'mensagem_api')));
        $e = $estado($id);
        afirmar("[falso:{$cat}] ERRO_REPROCESSAVEL preservado (sem senha)", $e['talent_checkin_status'] === 'ERRO_REPROCESSAVEL' && $e['talent_senha'] === null && $e['status'] === 'em_andamento');
        afirmar("[falso:{$cat}] exatamente UMA linha de log sanitizada (id_atendimento + categoria)", $umaLinhaSanitizada($log, $id, $cat));

        // nova tentativa: sem excecao de banco, novo 202, nova linha de log
        $log2 = $novoLog();
        $r2 = $falso($id, $cat, $log2, $msg);
        afirmar("[falso:{$cat}] nova tentativa de finalizar(): 202 sem excecao/erro de banco", $r2['http'] === 202 && ($r2['corpo']['sucesso'] ?? null) === false && !str_contains($r2['bruto'], 'SQLSTATE') && !str_contains($r2['bruto'], 'Fatal') && $r2['chamado'] === 1);
        afirmar("[falso:{$cat}] nova tentativa: continua ERRO_REPROCESSAVEL e UMA linha de log", $estado($id)['talent_checkin_status'] === 'ERRO_REPROCESSAVEL' && $umaLinhaSanitizada($log2, $id, $cat));
    }

    // --- sucesso na nova tentativa apos erro (retry manual do totem) ---
    $id = $novoAtendimento('SFLOK001');
    $falso($id, 'erro_conexao', $novoLog());
    $logOk = $novoLog();
    $rOk = $falso($id, 'sucesso', $logOk);
    afirmar('nova tentativa apos ERRO_REPROCESSAVEL conclui (200, ENVIADO) sem fila', $rOk['http'] === 200 && ($rOk['corpo']['dados']['senha'] ?? null) === 'FAL123' && $estado($id)['talent_checkin_status'] === 'ENVIADO' && $linhasLog($logOk) === []);

    // --- timeout: ENVIO_INDETERMINADO (nao e 202), sem linha de erro reprocessavel ---
    $id = $novoAtendimento('SFLTMO01');
    $logT = $novoLog();
    $rT = $falso($id, 'timeout', $logT);
    afirmar('[falso:timeout] vira ENVIO_INDETERMINADO (HTTP 500 "procure um atendente", nunca a frase antiga) e nao gera linha de erro reprocessavel',
        $rT['http'] === 500 && str_contains($rT['bruto'], 'procure um atendente') && !str_contains($rT['bruto'], FRASE_ANTIGA)
        && $estado($id)['talent_checkin_status'] === 'ENVIO_INDETERMINADO' && $linhasLog($logT) === []);
    $logT2 = $novoLog();
    $rT2 = $falso($id, 'sucesso', $logT2);
    afirmar('ENVIO_INDETERMINADO: novo finalizar NAO chama o Talent e responde "procure um atendente"', $rT2['http'] === 500 && $rT2['chamado'] === 0 && str_contains($rT2['bruto'], 'procure um atendente') && $estado($id)['talent_checkin_status'] === 'ENVIO_INDETERMINADO');

    // processarCheckin direto com ENVIO_INDETERMINADO: INDETERMINADO_PENDENTE_MANUAL, sem chamar o client
    $espiao = new class extends TalentClient {
        public int $chamadas = 0;
        public function __construct() { parent::__construct('', ''); }
        public function checkin(array $payload): array { $this->chamadas++; return ['senha' => 'X', 'protocolo' => 'Y']; }
    };
    $rnDireto = new TalentRn($espiao, $atendimentoDao, $storage);
    $res = $rnDireto->processarCheckin($atendimentoDao->buscarPorId($id), ['cnpj' => '14706199000182'], []);
    afirmar('processarCheckin com ENVIO_INDETERMINADO devolve INDETERMINADO_PENDENTE_MANUAL sem chamar o client', $res['status'] === 'INDETERMINADO_PENDENTE_MANUAL' && $espiao->chamadas === 0);

    // --- HTTP real contra servidor mock local: 400 com mensagem e 5xx sem corpo ---
    $porta = 19971 + random_int(0, 400);
    $host = '127.0.0.1';
    $processo = proc_open([PHP_BINARY, '-S', "{$host}:{$porta}", $dir . '/_router_talent_mock.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $pronto = false;
    for ($i = 0; $processo !== false && $i < 50; $i++) {
        $fp = @fsockopen($host, $porta, $e1, $e2, 0.1);
        if ($fp !== false) { fclose($fp); $pronto = true; break; }
        usleep(100000);
    }
    if (!$pronto) { throw new RuntimeException('servidor mock nao subiu'); }
    $http = function (int $id, string $cenario, string $log) use ($dir, $idTotem, $partir, $host, $porta): array {
        $r = qaLegadoRodar($dir . '/_caso_finalizar_talent_mock.php', [$idTotem, $id, "http://{$host}:{$porta}", $cenario, $log]);
        return $partir($r['saida']);
    };
    $id = $novoAtendimento('SFLH4001');
    $log = $novoLog();
    $r = $http($id, 'msg_400', $log);
    afirmar('[http:400 com msg] 202, texto padrao, dados.mensagem_api = retorno real da Talent, sem frase antiga', $r['http'] === 202 && ($r['corpo']['erro'] ?? null) === TEXTO_PADRAO && ($r['corpo']['dados']['mensagem_api'] ?? null) === MSG_TALENT && !str_contains($r['bruto'], FRASE_ANTIGA));
    afirmar('[http:400 com msg] UMA linha de log com so id_atendimento e categoria erro_validacao (sem a mensagem)', $umaLinhaSanitizada($log, $id, 'erro_validacao'));
    $id = $novoAtendimento('SFLH5001');
    $log = $novoLog();
    $r = $http($id, 'corpo_vazio_500', $log);
    afirmar('[http:5xx sem corpo] 202, texto de erro interno do Talent, sem dados e sem frase antiga', $r['http'] === 202 && ($r['corpo']['erro'] ?? null) === TEXTO_SERVIDOR && !isset($r['corpo']['dados']) && !str_contains($r['bruto'], FRASE_ANTIGA));
    afirmar('[http:5xx sem corpo] UMA linha de log, categoria erro_servidor', $umaLinhaSanitizada($log, $id, 'erro_servidor'));
    $id = $novoAtendimento('SFLH0001');
    $log = $novoLog();
    $r = qaLegadoRodar($dir . '/_caso_finalizar_talent_mock.php', [$idTotem, $id, "http://{$host}:" . ($porta + 450), 'msg_409', $log]);
    $r = $partir($r['saida']);
    afirmar('[http:erro de conexao] 202, texto de Talent fora do ar, sem dados', $r['http'] === 202 && ($r['corpo']['erro'] ?? null) === TEXTO_FORA && !isset($r['corpo']['dados']) && $umaLinhaSanitizada($log, $id, 'erro_conexao'));

    afirmar('no fim: tb_fila_envio continua inexistente no banco QA', !$tabelaExiste());
} finally {
    if (is_resource($processo)) { proc_terminate($processo); proc_close($processo); }
    foreach ($logs as $l) { @unlink($l); }
    unset($pdo);
    try {
        qaLegadoLimparAmbiente($banco, $storage);
        echo "Banco QA e storage temporario removidos.\n";
    } catch (Throwable) {
        fwrite(STDERR, "FALHA: banco QA nao removido.\n");
        $totalFalhas++;
    }
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
