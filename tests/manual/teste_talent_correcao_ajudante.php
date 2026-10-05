<?php

/**
 * Teste manual (SEM Talent real, SEM rede externa) — correcao do ajudante apos
 * a Talent recusar o check-in ("Cracha ja associado a outro ajudante").
 * Banco descartavel `qa_qr_exclusivo_<hex>` (nunca udlog_totem), mock local
 * (_router_talent_mock.php, cenario ajudante_cracha: recusa so o CPF
 * 52998224725), TalentClient REAL via subprocesso.
 *
 * Uso: php tests/manual/teste_talent_correcao_ajudante.php
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

const CPF_RECUSADO = '52998224725';
const CPF_NOVO = '39053344705';
const MSG_TALENT = 'Reg. acesso ajudante. Cracha ja associado a outro ajudante. Acao nao permitida.';

$banco = null; $storage = null; $pdo = null; $processo = null;
try {
    [$pdo, $banco, $storage] = qaLegadoCriarAmbiente();
    $pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $dir = __DIR__;
    $atendimentoDao = new AtendimentoDao($pdo);
    $notaDao = new AtendimentoNotaDao($pdo);
    $idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_AJUD_' . bin2hex(random_bytes(3)), 1);
    $idTotemInvasor = talentCriarTotemComEmpresa($pdo, 'TESTE_AJUD_INV_' . bin2hex(random_bytes(3)), 1);

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
    $baseUrl = "http://{$host}:{$porta}";

    $novo = function (string $tipo, string $placa) use ($pdo, $atendimentoDao, $notaDao, $idTotem): int {
        $fix = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, $tipo, $placa, ordemColeta: $tipo === 'expedicao' ? 'OC-1' : null);
        if ($tipo === 'recebimento') {
            talentInserirNotaComNumero($notaDao, $fix['id_atendimento'], 1, '12345');
        }
        return $fix['id_atendimento'];
    };
    $partir = fn (string $saida): array => (function () use ($saida) {
        $p = explode("\nHTTP_CODE:", $saida);
        return ['corpo' => json_decode(trim($p[0]), true), 'http' => (int) trim($p[1] ?? '0')];
    })();
    $finalizar = function (int $id, ?int $totem = null) use ($dir, $idTotem, $baseUrl, $partir): array {
        $r = qaLegadoRodar($dir . '/_caso_finalizar_talent_mock.php', [$totem ?? $idTotem, $id, $baseUrl, 'ajudante_cracha', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_ajud_log.log']);
        return $partir($r['saida']);
    };
    $ajudante = function (int $id, mixed $nome, mixed $cpf, ?int $totem = null) use ($dir, $idTotem, $partir): array {
        $dados = base64_encode(json_encode(['nome' => $nome, 'cpf' => $cpf]));
        $r = qaLegadoRodar($dir . '/_caso_salvar_etapa.php', [$totem ?? $idTotem, $id, 'ajudante', $dados]);
        return $partir($r['saida']);
    };
    $linha = function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT status, etapa_atual, talent_checkin_status, talent_senha, possui_ajudante, ajudante_nome, ajudante_cpf FROM tb_atendimento WHERE id_atendimento = :id');
        $s->execute(['id' => $id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    };
    $setStatus = function (int $id, string $st) use ($pdo): void {
        $pdo->prepare('UPDATE tb_atendimento SET talent_checkin_status = :s WHERE id_atendimento = :id')->execute(['s' => $st, 'id' => $id]);
    };
    $filaCount = function (int $id) use ($pdo): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM tb_fila_envio WHERE id_atendimento = :id');
        $s->execute(['id' => $id]);
        return (int) $s->fetchColumn();
    };

    foreach (['recebimento', 'expedicao'] as $tipo) {
        // 1) cadastro inicial + recusa da Talent com a mensagem do ajudante
        $id = $novo($tipo, 'AJU' . substr($tipo, 0, 1) . '0001');
        $r = $ajudante($id, 'AJUDANTE ANTIGO', '529.982.247-25');
        afirmar("[{$tipo}] cadastro inicial aceito (200) e CPF gravado so com digitos", $r['http'] === 200 && ($r['corpo']['sucesso'] ?? null) === true && $linha($id)['ajudante_cpf'] === CPF_RECUSADO);
        $r = $finalizar($id);
        afirmar("[{$tipo}] finalizar recusado: 202 + mensagem_api do ajudante", $r['http'] === 202 && ($r['corpo']['dados']['mensagem_api'] ?? null) === MSG_TALENT);
        $l = $linha($id);
        afirmar("[{$tipo}] estado: em_andamento, ERRO_REPROCESSAVEL, etapa_atual inalterada", $l['status'] === 'em_andamento' && $l['talent_checkin_status'] === 'ERRO_REPROCESSAVEL' && str_ends_with($l['etapa_atual'], '_confirmacao'));
        $filaAntes = $filaCount($id);

        // 2) entradas invalidas recusadas (422), nada gravado (CPF: so exige 11 digitos)
        foreach ([['NOVO AJUDANTE', '1234567890'], ['NOVO AJUDANTE', '123456789012'], ['NOVO AJUDANTE', '123.456.789-0'], ['NOVO AJUDANTE', '123'], ['NOVO AJUDANTE', ''], ['NOVO AJUDANTE', 'abcdefghijk'], ['NOVO AJUDANTE', '1234567890a'], ['', CPF_NOVO], [['x'], CPF_NOVO], ['NOVO', ['1']], ['NOVO', 12345678909], [str_repeat('A', 151), CPF_NOVO]] as $i => [$n, $c]) {
            $r = $ajudante($id, $n, $c);
            afirmar("[{$tipo}] entrada invalida #{$i} recusada 422 sem gravar", $r['http'] === 422 && ($r['corpo']['sucesso'] ?? null) === false && $linha($id)['ajudante_cpf'] === CPF_RECUSADO);
        }
        $r = $ajudante($id, 'NOVO AJUDANTE', '123');
        afirmar("[{$tipo}] mensagem 422 do ajudante atualizada", ($r['corpo']['erro'] ?? '') === 'Dados do ajudante invalidos: informe o nome completo e o CPF com 11 digitos');

        // 2b) CPF com DV invalido mas 11 digitos e ACEITO (sem pontuacao e com pontuacao, normalizado)
        $r = $ajudante($id, 'DV INVALIDO', '11111111111');
        afirmar("[{$tipo}] CPF 11 digitos com DV invalido aceito (200) e gravado", $r['http'] === 200 && $linha($id)['ajudante_cpf'] === '11111111111');
        $r = $ajudante($id, 'COM PONTOS', '123.456.789-09');
        afirmar("[{$tipo}] CPF com pontuacao aceito e gravado so com 11 digitos", $r['http'] === 200 && $linha($id)['ajudante_cpf'] === '12345678909');
        $r = $ajudante($id, 'DV INVALIDO 2', '12345678900');
        afirmar("[{$tipo}] CPF 12345678900 (DV invalido) aceito", $r['http'] === 200 && $linha($id)['ajudante_cpf'] === '12345678900');
        $ajudante($id, 'AJUDANTE ANTIGO', '529.982.247-25'); // restaura o estado base do cenario

        // 3) correcao aceita em ERRO_REPROCESSAVEL
        $r = $ajudante($id, '  NOVO   AJUDANTE ', CPF_NOVO);
        $l = $linha($id);
        afirmar("[{$tipo}] correcao aceita em ERRO_REPROCESSAVEL (200), nome normalizado e CPF novo", $r['http'] === 200 && $l['ajudante_nome'] === 'NOVO AJUDANTE' && $l['ajudante_cpf'] === CPF_NOVO && (int) $l['possui_ajudante'] === 1);
        afirmar("[{$tipo}] correcao nao muda status do check-in nem etapa e nao enfileira reenvio", $l['talent_checkin_status'] === 'ERRO_REPROCESSAVEL' && $l['status'] === 'em_andamento' && $filaCount($id) === $filaAntes);

        // 4) IDOR: outro totem nao consegue
        $r = $ajudante($id, 'FORJADO', '52998224725', $idTotemInvasor);
        afirmar("[{$tipo}] IDOR: outro totem recebe erro generico e nada muda", ($r['corpo']['sucesso'] ?? null) === false && ($r['corpo']['erro'] ?? '') === 'Atendimento nao encontrado' && $linha($id)['ajudante_cpf'] === CPF_NOVO);

        // 5) novo finalizar envia o ajudante NOVO e conclui
        $r = $finalizar($id);
        $l = $linha($id);
        afirmar("[{$tipo}] novo finalizar com ajudante novo: 200 e senha do ajudante novo (payload atualizado)", $r['http'] === 200 && ($r['corpo']['dados']['senha'] ?? null) === 'AJU390');
        afirmar("[{$tipo}] concluido: ENVIADO/concluido", $l['talent_checkin_status'] === 'ENVIADO' && $l['status'] === 'concluido');

        // 6) concluido e imutavel
        $r = $ajudante($id, 'OUTRO', '52998224725');
        afirmar("[{$tipo}] concluido: ajudante recusado 409 e inalterado", $r['http'] === 409 && $linha($id)['ajudante_cpf'] === CPF_NOVO);
        $r = $finalizar($id);
        afirmar("[{$tipo}] finalizar apos concluido nao reenvia (nao e sucesso novo)", ($r['corpo']['sucesso'] ?? null) === false && $linha($id)['talent_checkin_status'] === 'ENVIADO');
    }

    // 7) estados em que a correcao e recusada (409, nada gravado)
    foreach (['ENVIANDO', 'ENVIADO', 'ENVIO_INDETERMINADO'] as $st) {
        $id = $novo('recebimento', 'EST' . substr($st, 0, 4));
        $ajudante($id, 'AJUDANTE BASE', CPF_NOVO);
        $setStatus($id, $st);
        $r = $ajudante($id, 'ALTERADO', CPF_RECUSADO);
        $l = $linha($id);
        afirmar("[{$st}] ajudante recusado 409 com mensagem fixa, nada gravado", $r['http'] === 409 && ($r['corpo']['erro'] ?? '') === 'Nao e possivel alterar o ajudante: o check-in ja esta em processamento ou foi enviado' && $l['ajudante_nome'] === 'AJUDANTE BASE' && $l['talent_checkin_status'] === $st);
    }
    // NAO_ENVIADO aceito; "sem ajudante" limpa
    $id = $novo('recebimento', 'NAOENV1');
    $ajudante($id, 'AJUDANTE BASE', CPF_NOVO);
    $r = $ajudante($id, null, null);
    $l = $linha($id);
    afirmar('[NAO_ENVIADO] "sem ajudante" (nome/cpf nulos) aceito e limpa os dados', $r['http'] === 200 && (int) $l['possui_ajudante'] === 0 && $l['ajudante_nome'] === null && $l['ajudante_cpf'] === null);
    // cancelado/outra etapa continuam recusados
    $pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_atendimento = :id")->execute(['id' => $id]);
    $r = $ajudante($id, 'X AJ', CPF_NOVO);
    afirmar('[cancelado] ajudante recusado (400)', $r['http'] === 400 && ($r['corpo']['erro'] ?? '') === 'Atendimento nao esta em andamento');
    $id2 = $novo('recebimento', 'ETAPA01');
    $atendimentoDao->atualizarEtapa($id2, 'rec_cnh');
    $r = $ajudante($id2, 'X AJ', CPF_NOVO);
    afirmar('[etapa] fora de *_confirmacao continua recusado (400)', $r['http'] === 400 && str_contains((string) ($r['corpo']['erro'] ?? ''), 'etapa esperada'));
} finally {
    if (is_resource($processo)) { proc_terminate($processo); proc_close($processo); }
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_ajud_log.log');
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
