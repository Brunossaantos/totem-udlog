<?php

/**
 * Teste manual (SEM Talent real, SEM rede externa) — Talent responde 2xx SEM
 * nrRegAcesso utilizavel: NAO e sucesso. O atendimento vira 'cancelado',
 * talent_checkin_status fica ENVIO_INDETERMINADO (sem reenvio), nenhuma senha
 * e gravada, a ordem de coleta (expedicao) NAO e baixada e o finalizar responde
 * HTTP 422 codigo TALENT_SEM_REGISTRO. Banco QA descartavel qa_qr_exclusivo_<hex>,
 * servidor mock local e TalentClient REAL via subprocesso.
 *
 * Uso: php tests/manual/teste_talent_sem_registro.php
 */

require_once __DIR__ . '/qa_qr_exclusivo_legado.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

const MSG_GUICHE = 'Nao foi possivel concluir seu check-in. Dirija-se ao guiche da portaria.';

$banco = null; $storage = null; $pdo = null; $processo = null; $logs = []; $contagem = null;
try {
    [$pdo, $banco, $storage] = qaLegadoCriarAmbiente();
    $pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $dir = __DIR__;
    $atendimentoDao = new AtendimentoDao($pdo);
    $notaDao = new AtendimentoNotaDao($pdo);
    $idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_SEMREG_' . bin2hex(random_bytes(3)), 1);

    $contagem = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_semreg_posts_' . bin2hex(random_bytes(6)) . '.log';
    file_put_contents($contagem, '');
    putenv('TALENT_MOCK_CONTAGEM=' . $contagem);

    $porta = 20371 + random_int(0, 400);
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

    $novoLog = function () use (&$logs): string {
        $p = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_semreg_log_' . bin2hex(random_bytes(6)) . '.log';
        file_put_contents($p, '');
        $logs[] = $p;
        return $p;
    };
    $postsDoMock = fn (): int => count(array_filter(explode("\n", (string) file_get_contents($contagem)), fn ($l) => $l !== ''));
    $novoRecebimento = function (string $placa) use ($pdo, $atendimentoDao, $notaDao, $idTotem): array {
        $fix = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', $placa);
        $pastaValida = date('Y-m-d') . '/SR_' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $completa = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pastaValida;
        mkdir($completa, 0750, true);
        foreach (glob($fix['pasta_completa'] . '/*') as $f) { rename($f, $completa . '/' . basename($f)); }
        @rmdir($fix['pasta_completa']);
        $atendimentoDao->definirPasta($fix['id_atendimento'], $pastaValida);
        $idNota = $notaDao->inserir($fix['id_atendimento'], 1, 'nota_01.jpg', null, null, false);
        $notaDao->atualizarNumero($idNota, '12345', 'MANUAL');
        talentCriarJpegValido($completa . '/nota_01.jpg');
        $fix['pasta_completa'] = $completa;
        return $fix;
    };
    $novaExpedicao = function (string $placa) use ($pdo, $atendimentoDao, $idTotem): array {
        return talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', $placa, '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-SEMREG-1');
    };
    $finalizar = function (int $id, string $cenario, string $log, string $sabotagem = '') use ($dir, $idTotem, $baseUrl): array {
        $r = qaLegadoRodar($dir . '/_caso_finalizar_talent_sem_registro.php', [$idTotem, $id, $baseUrl, $cenario, $log, $sabotagem]);
        $partes = explode("\nHTTP_CODE:", $r['saida']);
        $bruto = str_replace(["OC_BAIXA_CHAMADA\n", "OC_STATUS_CHAMADO\n"], '', $partes[0]);
        return [
            'corpo' => json_decode(trim($bruto), true),
            'bruto' => $partes[0],
            'http' => (int) trim($partes[1] ?? '0'),
            'oc' => substr_count($partes[0], 'OC_BAIXA_CHAMADA') + substr_count($partes[0], 'OC_STATUS_CHAMADO'),
        ];
    };
    $estado = function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT status, etapa_atual, talent_checkin_status, talent_senha, talent_protocolo, talent_enviado_em, ordem_coleta FROM tb_atendimento WHERE id_atendimento = :id');
        $s->execute(['id' => $id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    };
    $linhasLog = function (int $id) use ($pdo): array {
        $s = $pdo->prepare("SELECT * FROM tb_log_sistema WHERE categoria = 'talent_indeterminado' AND id_atendimento = :id");
        $s->execute(['id' => $id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $idNotaDe = fn (int $id): int => (int) $pdo->query('SELECT id_nota FROM tb_atendimento_nota WHERE id_atendimento = ' . (int) $id)->fetchColumn();
    $pendencias = fn (int $id): int => (int) $pdo->query('SELECT COUNT(*) FROM tb_ordem_coleta_pendente_baixa WHERE id_atendimento = ' . (int) $id)->fetchColumn();

    $cenarios = [
        'campo_ausente_200' => 'sem nrRegAcesso',
        'nr_vazio_200' => 'nrRegAcesso vazio',
        'nr_espacos_200' => 'nrRegAcesso so espacos',
        'nr_numero_200' => 'nrRegAcesso numerico',
        'nr_array_200' => 'nrRegAcesso array',
        'corpo_ilegivel_200' => 'corpo nao-JSON',
        'json_lista_200' => 'corpo JSON lista',
        'corpo_vazio_204' => 'corpo vazio 204',
        'nr_aninhado_200' => 'nrRegAcesso aninhado em dados',
    ];

    foreach ($cenarios as $cenario => $rotulo) {
        $fix = $novoRecebimento('SR' . substr(md5($cenario), 0, 5));
        $id = $fix['id_atendimento'];
        $log = $novoLog();
        $antes = $postsDoMock();
        $r = $finalizar($id, $cenario, $log);
        $c = $r['corpo'];
        afirmar("[recebimento/{$rotulo}] HTTP 422, sucesso:false, codigo TALENT_SEM_REGISTRO, mensagem fixa", $r['http'] === 422 && ($c['sucesso'] ?? null) === false && ($c['codigo'] ?? null) === 'TALENT_SEM_REGISTRO' && ($c['erro'] ?? null) === MSG_GUICHE);
        afirmar("[recebimento/{$rotulo}] dados = acao guiche_portaria, tentar_novamente=false; sem mensagem_api/senha", ($c['dados'] ?? null) === ['acao' => 'guiche_portaria', 'tentar_novamente' => false] && !str_contains($r['bruto'], 'mensagem_api') && !str_contains($r['bruto'], 'senha'));
        $e = $estado($id);
        afirmar("[recebimento/{$rotulo}] atendimento cancelado, ENVIO_INDETERMINADO, sem senha/protocolo/enviado_em", $e['status'] === 'cancelado' && $e['talent_checkin_status'] === 'ENVIO_INDETERMINADO' && $e['talent_senha'] === null && $e['talent_protocolo'] === null && $e['talent_enviado_em'] === null && $e['etapa_atual'] !== 'impressao');
        afirmar("[recebimento/{$rotulo}] exatamente 1 POST ao Talent", $postsDoMock() === $antes + 1);
        $l = $linhasLog($id);
        afirmar("[recebimento/{$rotulo}] 1 LogSistema talent_indeterminado ERRO RECEBIMENTO com categoria_erro=resposta_ilegivel", count($l) === 1 && $l[0]['nivel'] === 'ERRO' && $l[0]['origem'] === 'RECEBIMENTO' && (int) $l[0]['id_totem'] === $idTotem && $l[0]['detalhe'] === 'categoria_erro=resposta_ilegivel');
        $textoLog = (string) file_get_contents($log);
        afirmar("[recebimento/{$rotulo}] error_log sanitizado (id_atendimento + categoria), sem corpo/placa/CPF/CNPJ/Bearer", str_contains($textoLog, "checkin_talent_sem_registro_cancelado id_atendimento={$id} categoria=resposta_ilegivel") && !preg_match('/\d{11}/', $textoLog) && !str_contains($textoLog, 'Bearer') && !str_contains($textoLog, 'cenario_') && !str_contains($textoLog, '12345') && !str_contains($textoLog, 'SR'));
        $dadosLog = json_encode(array_map(fn ($x) => [$x['mensagem'], $x['detalhe']], $l));
        afirmar("[recebimento/{$rotulo}] LogSistema sem PII", !preg_match('/\d{11}/', (string) $dadosLog) && !str_contains((string) $dadosLog, 'MOTORISTA') && !str_contains((string) $dadosLog, 'cenario_'));
        afirmar("[recebimento/{$rotulo}] foto da nota segue a quarentena do cancelar (saiu da pasta)", !is_file($fix['pasta_completa'] . '/nota_01.jpg') && is_file($fix['pasta_completa'] . '/nota_01.jpg.' . $idNotaDe($id) . '.del'));

        $log2 = $novoLog();
        $r2 = $finalizar($id, 'sucesso_200', $log2);
        afirmar("[recebimento/{$rotulo}] segunda chamada: HTTP 400 'nao esta em andamento', SEM novo POST ao Talent", $r2['http'] === 400 && ($r2['corpo']['sucesso'] ?? null) === false && !isset($r2['corpo']['codigo']) && $postsDoMock() === $antes + 1);
        $e2 = $estado($id);
        afirmar("[recebimento/{$rotulo}] segunda chamada nao altera o estado (cancelado, ENVIO_INDETERMINADO, sem senha)", $e2['status'] === 'cancelado' && $e2['talent_checkin_status'] === 'ENVIO_INDETERMINADO' && $e2['talent_senha'] === null);
    }

    $fix = $novaExpedicao('SREXP01');
    $id = $fix['id_atendimento'];
    $log = $novoLog();
    $antes = $postsDoMock();
    $r = $finalizar($id, 'nr_vazio_200', $log);
    $e = $estado($id);
    afirmar('[expedicao] HTTP 422 TALENT_SEM_REGISTRO', $r['http'] === 422 && ($r['corpo']['codigo'] ?? null) === 'TALENT_SEM_REGISTRO' && ($r['corpo']['erro'] ?? null) === MSG_GUICHE);
    afirmar('[expedicao] atendimento cancelado, ENVIO_INDETERMINADO, sem senha, ordem_coleta preservada no atendimento', $e['status'] === 'cancelado' && $e['talent_checkin_status'] === 'ENVIO_INDETERMINADO' && $e['talent_senha'] === null && $e['ordem_coleta'] === 'OC-SEMREG-1');
    afirmar('[expedicao] ordem de coleta NAO baixada: nenhuma chamada de baixa/status ao cliente de OC, nenhuma pendencia de baixa, nenhum log oc_baixa_falhou', $r['oc'] === 0 && $pendencias($id) === 0 && (int) $pdo->query("SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'oc_baixa_falhou'")->fetchColumn() === 0);
    $l = $linhasLog($id);
    afirmar('[expedicao] LogSistema talent_indeterminado origem EXPEDICAO com categoria_erro=resposta_ilegivel', count($l) === 1 && $l[0]['origem'] === 'EXPEDICAO' && $l[0]['detalhe'] === 'categoria_erro=resposta_ilegivel');
    $r2 = $finalizar($id, 'sucesso_200', $novoLog());
    afirmar('[expedicao] segunda chamada: sem novo POST, sem baixa de OC, HTTP 400', $r2['http'] === 400 && $postsDoMock() === $antes + 1 && $r2['oc'] === 0);

    $fix = $novoRecebimento('SROKK01');
    $id = $fix['id_atendimento'];
    $antes = $postsDoMock();
    $r = $finalizar($id, 'sucesso_200', $novoLog());
    $e = $estado($id);
    afirmar('[regressao] 2xx COM nrRegAcesso: HTTP 200, senha ABC123, concluido/ENVIADO', $r['http'] === 200 && ($r['corpo']['dados']['senha'] ?? null) === 'ABC123' && $e['status'] === 'concluido' && $e['talent_checkin_status'] === 'ENVIADO' && $e['talent_senha'] === 'ABC123');
    $r2 = $finalizar($id, 'nr_vazio_200', $novoLog());
    afirmar('[regressao] concluido: segunda chamada em atendimento concluido nao chama o Talent e nao o cancela', $postsDoMock() === $antes + 1 && $estado($id)['status'] === 'concluido' && $estado($id)['talent_senha'] === 'ABC123');

    foreach (['nr_zero_200' => '0', 'nr_zeros_esquerda_200' => '00123', 'nr_espaco_meio_200' => 'AB 12', 'nr_espacos_bordas_200' => '  ABC  '] as $cenarioAceito => $valorAceito) {
        $fix = $novoRecebimento('SRACE' . substr(md5($cenarioAceito), 0, 3));
        $id = $fix['id_atendimento'];
        $r = $finalizar($id, $cenarioAceito, $novoLog());
        $e = $estado($id);
        afirmar("[aceito/{$cenarioAceito}] nrRegAcesso utilizavel: HTTP 200, senha identica ao valor, concluido/ENVIADO", $r['http'] === 200 && ($r['corpo']['dados']['senha'] ?? null) === $valorAceito && $e['status'] === 'concluido' && $e['talent_checkin_status'] === 'ENVIADO' && $e['talent_senha'] === $valorAceito);
    }

    foreach (['indeterminado_por_terceiro' => 'terceiro marcou ENVIO_INDETERMINADO', 'bloqueado_e_indeterminado' => 'terceiro bloqueou e marcou ENVIO_INDETERMINADO', 'outra_tentativa' => 'outra tentativa assumiu o envio'] as $sabotagem => $rotulo) {
        foreach (['recebimento', 'expedicao'] as $tipoCas) {
            $placaCas = 'CAS' . substr(md5($sabotagem . $tipoCas), 0, 4);
            $fix = $tipoCas === 'recebimento' ? $novoRecebimento($placaCas) : $novaExpedicao($placaCas);
            $id = $fix['id_atendimento'];
            $log = $novoLog();
            $antes = $postsDoMock();
            $statusEsperado = $sabotagem === 'bloqueado_e_indeterminado' ? 'bloqueado' : 'em_andamento';
            $statusTalentEsperado = $sabotagem === 'outra_tentativa' ? 'ENVIANDO' : 'ENVIO_INDETERMINADO';
            $r = $finalizar($id, 'nr_vazio_200', $log, $sabotagem);
            $c = $r['corpo'];
            $e = $estado($id);
            $rot = "[CAS perde/{$sabotagem}/{$tipoCas}]";
            afirmar("{$rot} {$rotulo}: mesmo HTTP 422 TALENT_SEM_REGISTRO, mensagem fixa e dados do guiche", $r['http'] === 422 && ($c['sucesso'] ?? null) === false && ($c['codigo'] ?? null) === 'TALENT_SEM_REGISTRO' && ($c['erro'] ?? null) === MSG_GUICHE && ($c['dados'] ?? null) === ['acao' => 'guiche_portaria', 'tentar_novamente' => false]);
            afirmar("{$rot} estado nao sobrescrito: status={$statusEsperado}, talent={$statusTalentEsperado}, sem senha/protocolo/enviado_em", $e['status'] === $statusEsperado && $e['talent_checkin_status'] === $statusTalentEsperado && $e['talent_senha'] === null && $e['talent_protocolo'] === null && $e['talent_enviado_em'] === null);
            afirmar("{$rot} exatamente 1 POST ao Talent e nenhuma baixa/consulta de OC, nenhuma pendencia de baixa", $postsDoMock() === $antes + 1 && $r['oc'] === 0 && $pendencias($id) === 0);
            if ($tipoCas === 'recebimento') {
                afirmar("{$rot} fotos NAO quarentenadas (nota_01.jpg no lugar, sem .del)", is_file($fix['pasta_completa'] . '/nota_01.jpg') && count(glob($fix['pasta_completa'] . '/*.del')) === 0);
            }
            $l = $linhasLog($id);
            afirmar("{$rot} 1 LogSistema talent_indeterminado com categoria_erro=resposta_ilegivel", count($l) === 1 && $l[0]['nivel'] === 'ERRO' && $l[0]['detalhe'] === 'categoria_erro=resposta_ilegivel');
            $textoLog = (string) file_get_contents($log);
            $dadosLog = json_encode(array_map(fn ($x) => [$x['mensagem'], $x['detalhe']], $l));
            if ($sabotagem === 'indeterminado_por_terceiro') {
                $r2 = $finalizar($id, 'sucesso_200', $novoLog());
                afirmar("{$rot} segunda chamada: sem novo POST ao Talent, sem baixa de OC, sem senha", $postsDoMock() === $antes + 1 && $r2['oc'] === 0 && $estado($id)['talent_senha'] === null);
            }
            afirmar("{$rot} logs sem PII e sem registrar cancelamento que nao ocorreu", !str_contains($textoLog, 'sem_registro_cancelado') && !preg_match('/\d{11}/', $textoLog . $dadosLog) && !str_contains($textoLog, 'Bearer') && !str_contains($textoLog . $dadosLog, 'cenario_') && !str_contains($textoLog . $dadosLog, $placaCas));
        }
    }

    $fix = $novaExpedicao('SREXP02');
    $id = $fix['id_atendimento'];
    $r = $finalizar($id, 'sucesso_201_sem_msg', $novoLog());
    afirmar('[regressao] expedicao com nrRegAcesso: 200 e baixa de OC tentada', $r['http'] === 200 && ($r['corpo']['dados']['senha'] ?? null) === 'XYZ789' && $r['oc'] >= 1);

    $fix = $novoRecebimento('SR409X1');
    $id = $fix['id_atendimento'];
    $r = $finalizar($id, 'msg_409', $novoLog());
    $e = $estado($id);
    afirmar('[regressao] 409 do Talent segue 202 reprocessavel, em_andamento, ERRO_REPROCESSAVEL, sem codigo TALENT_SEM_REGISTRO', $r['http'] === 202 && !isset($r['corpo']['codigo']) && $e['status'] === 'em_andamento' && $e['talent_checkin_status'] === 'ERRO_REPROCESSAVEL');

    $fix = $novoRecebimento('SR500X1');
    $id = $fix['id_atendimento'];
    $r = $finalizar($id, 'erro_servidor_500', $novoLog());
    afirmar('[regressao] 500 do Talent segue 202 reprocessavel', $r['http'] === 202 && $estado($id)['talent_checkin_status'] === 'ERRO_REPROCESSAVEL' && $estado($id)['status'] === 'em_andamento');

    $fix = $novoRecebimento('SRCNCL1');
    $id = $fix['id_atendimento'];
    $pdo->prepare("UPDATE tb_atendimento SET status = 'concluido' WHERE id_atendimento = :id")->execute(['id' => $id]);
    $tentativa = 'tentativa_teste_cas';
    $pdo->prepare("UPDATE tb_atendimento SET talent_checkin_status = 'ENVIANDO', talent_tentativa_id = :t WHERE id_atendimento = :id")->execute(['t' => $tentativa, 'id' => $id]);
    $afetou = $atendimentoDao->cancelarPorRespostaSemRegistroTalent($id, $tentativa);
    afirmar('[CAS] concluido e terminal: cancelarPorRespostaSemRegistroTalent nao altera atendimento concluido', $afetou === false && $estado($id)['status'] === 'concluido' && $estado($id)['talent_checkin_status'] === 'ENVIANDO');
    $pdo->prepare("UPDATE tb_atendimento SET status = 'bloqueado' WHERE id_atendimento = :id")->execute(['id' => $id]);
    $atendimentoDao->cancelarPorRespostaSemRegistroTalent($id, 'outra_tentativa');
    afirmar('[CAS] tentativa diferente (zumbi) nao altera nada', $estado($id)['talent_checkin_status'] === 'ENVIANDO' && $estado($id)['status'] === 'bloqueado');
    $atendimentoDao->cancelarPorRespostaSemRegistroTalent($id, $tentativa);
    afirmar('[CAS] estado nao-em_andamento (bloqueado) nao e sobrescrito para cancelado', $estado($id)['status'] === 'bloqueado' && $estado($id)['talent_checkin_status'] === 'ENVIO_INDETERMINADO');
} finally {
    if (is_resource($processo)) { proc_terminate($processo); proc_close($processo); }
    foreach ($logs as $l) { @unlink($l); }
    if ($contagem !== null) { @unlink($contagem); }
    putenv('TALENT_MOCK_CONTAGEM');
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
