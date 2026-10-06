<?php

/**
 * Teste manual (SEM Talent real, SEM VIO/rede externa) — `finalizar` devolve
 * `dados.mensagem_api` (mensagem da Talent sanitizada) nas falhas HTTP do
 * check-in. Roda em banco descartavel `qa_qr_exclusivo_<hex>` (nunca
 * udlog_totem), com servidor HTTP mock local (_router_talent_mock.php) e o
 * TalentClient REAL via subprocesso (_caso_finalizar_talent_mock.php).
 *
 * Uso: php tests/manual/teste_talent_mensagem_api_finalizar.php
 */

require_once __DIR__ . '/qa_qr_exclusivo_legado.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use Util\MensagemApi;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

const MSG_TALENT = 'Reg. acesso ajudante. Cracha ja associado a outro ajudante. Acao nao permitida.';

$banco = null; $storage = null; $pdo = null; $processo = null; $logArquivo = null;
try {
    // --- unitario: sanitizador ---
    afirmar('MensagemApi: msg string do JSON', MensagemApi::extrairDoCorpo('{"nrRegAcesso":null,"msg":"  ola\tmundo  "}') === 'ola mundo');
    afirmar('MensagemApi: sem msg -> texto cru truncado/sanitizado', MensagemApi::extrairDoCorpo("a\x00b\n c") === 'a b c');
    afirmar('MensagemApi: vazio/so controles -> null', MensagemApi::extrairDoCorpo('') === null && MensagemApi::sanitizar("\x00\x01 \n") === null);
    afirmar('MensagemApi: UTF-8 invalido (latin1) nao quebra', MensagemApi::sanitizar("A\xE7\xE3o") === 'Ação');
    afirmar('MensagemApi: limite de 300 caracteres multibyte', mb_strlen((string) MensagemApi::sanitizar(str_repeat('ã', 500)), 'UTF-8') === 300);

    [$pdo, $banco, $storage] = qaLegadoCriarAmbiente();
    $pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $dir = __DIR__;
    $atendimentoDao = new AtendimentoDao($pdo);
    $notaDao = new AtendimentoNotaDao($pdo);
    $idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_MSGAPI_' . bin2hex(random_bytes(3)), 1);

    $logArquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_msgapi_log_' . bin2hex(random_bytes(6)) . '.log';
    file_put_contents($logArquivo, '');

    // --- servidor mock ---
    $porta = 19571 + random_int(0, 400);
    $host = '127.0.0.1';
    $processo = proc_open([PHP_BINARY, '-S', "{$host}:{$porta}", $dir . '/_router_talent_mock.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $pronto = false;
    for ($i = 0; $processo !== false && $i < 50; $i++) {
        $fp = @fsockopen($host, $porta, $e1, $e2, 0.1);
        if ($fp !== false) { fclose($fp); $pronto = true; break; }
        usleep(100000);
    }
    if (!$pronto) { throw new RuntimeException('servidor mock nao subiu'); }
    $baseUrl = "http://{$host}:{$porta}";

    $novoAtendimento = function (string $placa) use ($pdo, $atendimentoDao, $notaDao, $idTotem): int {
        $fix = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', $placa);
        talentInserirNotaComNumero($notaDao, $fix['id_atendimento'], 1, '12345');
        return $fix['id_atendimento'];
    };
    $finalizar = function (int $id, string $url, string $cenario) use ($dir, $idTotem, $logArquivo): array {
        $r = qaLegadoRodar($dir . '/_caso_finalizar_talent_mock.php', [$idTotem, $id, $url, $cenario, $logArquivo]);
        $partes = explode("\nHTTP_CODE:", $r['saida']);
        return ['corpo' => json_decode(trim($partes[0]), true), 'bruto' => $partes[0], 'http' => (int) trim($partes[1] ?? '0')];
    };
    $estado = function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT talent_checkin_status, talent_senha FROM tb_atendimento WHERE id_atendimento = :id');
        $s->execute(['id' => $id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    };
    // Categorias registradas na UNICA linha de log sanitizada por falha
    // (sem fila de reenvio; migration 019) para um id_atendimento.
    $categoriasLogadas = function (int $id) use ($logArquivo): array {
        preg_match_all('/checkin_talent_erro_reprocessavel id_atendimento=' . $id . ' categoria=([a-z_]+)\r?$/m', (string) file_get_contents($logArquivo), $m);
        return $m[1];
    };
    // Textos fixos do 202 por categoria (sem promessa de reprocessamento)
    $textoPadrao = 'Nao foi possivel concluir o check-in. Chame o atendimento.';
    $textoFora = 'O sistema Talent esta fora do ar ou nao respondeu, e o check-in nao foi concluido. Chame o atendimento.';
    $textoServidor = 'O sistema Talent informou um erro interno e o check-in nao foi concluido. Chame o atendimento.';
    $fraseAntiga = 'sera processada em instantes';

    // --- 409 / 422 / 400 com msg ---
    foreach (['msg_409' => 'conflito', 'msg_422' => 'erro_http', 'msg_400' => 'erro_validacao'] as $cenario => $categoria) {
        $id = $novoAtendimento('MSG' . substr(md5($cenario), 0, 4));
        $r = $finalizar($id, $baseUrl, $cenario);
        $c = $r['corpo'];
        afirmar("[{$cenario}] HTTP 202 mantido", $r['http'] === 202);
        afirmar("[{$cenario}] sucesso:false e texto fixo da categoria, sem a frase antiga", ($c['sucesso'] ?? null) === false && ($c['erro'] ?? null) === $textoPadrao && !str_contains($r['bruto'], $fraseAntiga));
        afirmar("[{$cenario}] dados.mensagem_api = mensagem da Talent", ($c['dados']['mensagem_api'] ?? null) === MSG_TALENT);
        afirmar("[{$cenario}] sem chave 'codigo' (inalterado: nao existia nessa falha)", !array_key_exists('codigo', $c));
        $e = $estado($id);
        afirmar("[{$cenario}] status/CAS inalterado: ERRO_REPROCESSAVEL, sem senha", $e['talent_checkin_status'] === 'ERRO_REPROCESSAVEL' && $e['talent_senha'] === null);
        $r2 = $finalizar($id, $baseUrl, $cenario);
        afirmar("[{$cenario}] retentativa continua permitida (novo 202 com mensagem)", $r2['http'] === 202 && ($r2['corpo']['dados']['mensagem_api'] ?? null) === MSG_TALENT);
        afirmar("[{$cenario}] exatamente UMA linha de log por chamada de finalizar (2 chamadas), so com a categoria ('{$categoria}')", $categoriasLogadas($id) === [$categoria, $categoria]);
    }

    // --- sujeira + >300 ---
    $id = $novoAtendimento('SUJ0001');
    $r = $finalizar($id, $baseUrl, 'msg_suja_longa');
    $m = $r['corpo']['dados']['mensagem_api'] ?? null;
    afirmar('[suja_longa] HTTP 202 e mensagem presente', $r['http'] === 202 && is_string($m));
    afirmar('[suja_longa] sem caracteres de controle', is_string($m) && preg_match('/[\x00-\x1F\x7F]/', $m) === 0);
    afirmar('[suja_longa] espacos normalizados, inicio correto', is_string($m) && str_starts_with($m, 'Linha1 ') && !str_contains($m, '  ') && str_contains($m, 'Linha2 AAA'));
    afirmar('[suja_longa] truncada em exatamente 300 caracteres', is_string($m) && mb_strlen($m, 'UTF-8') === 300);

    // --- HTML devolvido como texto ---
    $id = $novoAtendimento('HTM0001');
    $r = $finalizar($id, $baseUrl, 'msg_html');
    afirmar('[html] devolvido como texto cru (front escapa)', ($r['corpo']['dados']['mensagem_api'] ?? null) === '<script>alert(1)</script><b>x</b> & "aspas"');

    // --- texto cru (sem JSON) ---
    $id = $novoAtendimento('TXT0001');
    $r = $finalizar($id, $baseUrl, 'texto_cru_400');
    afirmar('[texto_cru] corpo nao-JSON vira texto cru sanitizado', ($r['corpo']['dados']['mensagem_api'] ?? null) === 'Falha de validacao sem JSON');

    // --- corpo vazio ---
    $id = $novoAtendimento('VAZ0001');
    $r = $finalizar($id, $baseUrl, 'corpo_vazio_500');
    afirmar('[corpo_vazio] 202 sem dados.mensagem_api', $r['http'] === 202 && !isset($r['corpo']['dados']) && ($r['corpo']['erro'] ?? null) === $textoServidor && !str_contains($r['bruto'], $fraseAntiga) && $categoriasLogadas($id) === ['erro_servidor']);

    // --- erro de rede (porta fechada) ---
    $id = $novoAtendimento('NET0001');
    $r = $finalizar($id, "http://{$host}:" . ($porta + 450), 'msg_409');
    afirmar('[rede] 202 sem mensagem_api/dados (nada inventado), texto de API fora do ar e sem a frase antiga', $r['http'] === 202 && !isset($r['corpo']['dados']) && !str_contains($r['bruto'], 'mensagem_api') && ($r['corpo']['erro'] ?? null) === $textoFora && !str_contains($r['bruto'], $fraseAntiga));
    afirmar('[rede] status ERRO_REPROCESSAVEL, log com categoria erro_conexao', $estado($id)['talent_checkin_status'] === 'ERRO_REPROCESSAVEL' && $categoriasLogadas($id) === ['erro_conexao']);

    // --- sucesso inalterado ---
    $id = $novoAtendimento('OKK0001');
    $r = $finalizar($id, $baseUrl, 'sucesso_200');
    afirmar('[sucesso] 200 com senha, sem mensagem_api', $r['http'] === 200 && ($r['corpo']['sucesso'] ?? null) === true && ($r['corpo']['dados']['senha'] ?? null) === 'ABC123' && !str_contains($r['bruto'], 'mensagem_api'));
    afirmar('[sucesso] status ENVIADO', $estado($id)['talent_checkin_status'] === 'ENVIADO');

    // --- logs sem a mensagem ---
    $log = (string) file_get_contents($logArquivo);
    afirmar('[logs] error_log capturado sem a mensagem da Talent nem "mensagem_api"', !str_contains($log, 'Cracha') && !str_contains($log, 'ajudante') && !str_contains($log, 'mensagem_api') && !str_contains($log, 'script') && !str_contains($log, 'Linha1'));
    afirmar('[logs] tabela tb_fila_envio inexistente no banco QA (migration 019)', (int) $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_fila_envio'")->fetchColumn() === 0);
} finally {
    if (is_resource($processo)) { proc_terminate($processo); proc_close($processo); }
    if ($logArquivo !== null) { @unlink($logArquivo); }
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
