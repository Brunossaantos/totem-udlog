<?php

/**
 * Servidor local descartavel do harness de layout da Gestao Totem
 * (tests/manual/gestao_layout.js). Cria um banco QA `qa_qr_exclusivo_<hex>`
 * (schema + migrations, mesma infra de qa_gestao_infra.php), semeia usuarios,
 * sobe `php -S 127.0.0.1:<porta>` servindo public/ (paginas REAIS /gestao/*.php,
 * com o prepend QA que forca o banco QA, o storage temporario e o
 * GESTAO_PERMITIR_HTTP=true so deste processo), imprime uma linha JSON "PRONTO"
 * e espera o stdin fechar ou a linha "fim". No fim mata o servidor, remove o
 * banco e o storage. Nunca toca em udlog_totem.
 *
 * Uso (chamado pelo node): php tests/manual/gestao_layout_servidor.php <porta>
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Rn\TotemGestaoRn;

$porta = (int) ($argv[1] ?? 0);
if ($porta < 1024 || $porta > 65000) {
    fwrite(STDERR, "porta invalida\n");
    exit(2);
}

$banco = null;
$storage = null;
$proc = null;
try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Administrador da Silva Albuquerque Neto');
    gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    gtSemear($pdo, 'otavio.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Otavio Usuario');
    gtSemear($pdo, 'davi.pendente', 'usuario', GT_SENHA_BOA, true, true, 'Davi Pendente');
    gtSemear($pdo, 'ines.inativa', 'usuario', GT_SENHA_BOA, false, false, 'Ines Inativa');
    gtSemear($pdo, 'zeca.alvo', 'usuario', GT_SENHA_BOA, false, true, 'Zeca Alvo');
    $idBloq = gtSemear($pdo, 'bloq.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Bruna Bloqueada');
    $pdo->exec('UPDATE tb_gestao_usuario SET bloqueado_ate = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id_usuario = ' . (int) $idBloq);
    gtSemear($pdo, 'xss.um', 'usuario', GT_SENHA_BOA, false, true, '<img src=x onerror=window.__xss=1>');
    gtSemear($pdo, 'xss.dois', 'usuario', GT_SENHA_BOA, false, true, '"><script>window.__xss=2</script>');

    // F2 (totens): empresas e totens semeados (ativo, inativo, legado fora do padrao, com atendimento recente,
    // nome/empresa hostis, URL longa). TOTEM_URL_BASE existe SO no ambiente deste processo (nunca no .env).
    $urlBase = 'https://totem.udlog.online/totem/';
    $idAdmin = (int) $pdo->query("SELECT id_usuario FROM tb_gestao_usuario WHERE login = 'ana.admin'")->fetchColumn();
    $emp = static function (string $nome, string $cnpj, int $ativo = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES (:n, :c, :a)')->execute(['n' => $nome, 'c' => $cnpj, 'a' => $ativo]);

        return (int) $pdo->lastInsertId();
    };
    $eMaua1 = $emp('Maua I', '14706199000182');
    $eMaua2 = $emp('Maua II', '14706199000344');
    $eLonga = $emp('Empresa16Chars00', '55555555000155');
    $eXss = $emp('<img src=x onerror=window.__xss=8>', '66666666000166');
    $emp('Empresa Inativa', '44444444000144', 0);
    $rnTotem = new TotemGestaoRn($pdo, $urlBase);
    $criarTotem = static function (int $empresa, string $nome) use ($rnTotem, $idAdmin): int {
        $r = $rnTotem->criar($idAdmin, $empresa, $nome, '203.0.113.5');
        if (!($r['ok'] ?? false)) {
            throw new RuntimeException('semente de totem falhou: ' . ($r['codigo'] ?? '?'));
        }

        return (int) $r['id'];
    };
    $idGuiche = $criarTotem($eMaua1, 'Guiche 04');
    $idDoca = $criarTotem($eMaua2, 'Doca 02');
    $idInativo = $criarTotem($eMaua1, 'Balcao 09');
    $rnTotem->definirAtivo($idAdmin, $idInativo, false, true, '203.0.113.5');
    $idAtend = $criarTotem($eMaua1, 'Balcao 01');
    $criarTotem($eLonga, 'ABCDEFGHIJKLMNOPQRSTUVWX');
    $pdo->prepare("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, atualizado_em) VALUES (:c, :t, 'expedicao', 'em_andamento', NOW())")->execute(['c' => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), 't' => $idAtend]);
    $legado = static function (string $codigo, string $nome, ?int $empresa) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES (:c, :n, :e, :t, 1)')->execute(['c' => $codigo, 'n' => $nome, 'e' => $empresa, 't' => bin2hex(random_bytes(32))]);

        return (int) $pdo->lastInsertId();
    };
    $legado('RECEPCAO-01', 'RECEPCAO-01', $eMaua1);
    $legado('XSS_LEGADO', '<img src=x onerror=window.__xss=7>', $eXss);

    // F3c (logs): linhas semeadas nas 5 abas (ERRO/AVISO/INFO, mensagem longa, repeticoes, 120 linhas na API para
    // paginacao, texto hostil). Cron e Gestao nunca tem totem. Categorias e mensagens sao as do catalogo.
    $seqLog = 0;
    $log = static function (string $nivel, string $origem, string $cat, string $msg, ?int $idTotem, ?string $detalhe, int $contador, string $quando) use ($pdo, &$seqLog): int {
        $seqLog++;
        $pdo->prepare(
            "INSERT INTO tb_log_sistema (nivel, origem, categoria, mensagem, id_totem, detalhe, dedup_chave, janela, contador, criado_em, ultima_ocorrencia)
             VALUES (:n, :o, :c, :m, :t, :d, :k, NOW(), :ct, $quando, $quando)"
        )->execute(['n' => $nivel, 'o' => $origem, 'c' => $cat, 'm' => $msg, 't' => $idTotem, 'd' => $detalhe, 'k' => sha1('lay' . $seqLog), 'ct' => $contador]);

        return (int) $pdo->lastInsertId();
    };
    $niveisLog = ['ERRO', 'AVISO', 'INFO'];
    $totensLog = [$idGuiche, $idDoca, null, $idAtend];
    $idLogHostil = $log('ERRO', 'API', 'erro_tecnico', '<img src=x onerror=window.__xss=11>"><script>window.__xss=12</script>', null, 'classe=<img src=x onerror=window.__xss=13>;http=500', 3, 'NOW()');
    for ($i = 1; $i <= 120; $i++) {
        $log($niveisLog[$i % 3], 'API', $i % 2 === 0 ? 'erro_tecnico' : 'totem_nao_autorizado', $i % 2 === 0 ? 'Erro técnico inesperado.' : 'Requisição à API com token de totem informado e inválido.', $totensLog[$i % 4], $i % 7 === 0 ? 'classe=PDOException;sqlstate=HY000;http=500' : null, ($i % 5) + 1, "NOW() - INTERVAL $i MINUTE");
    }
    $log('ERRO', 'API', 'banco_coletas_indisponivel', 'Banco de gestão de coletas indisponível.', null, null, 1, 'NOW() - INTERVAL 30 DAY');
    $mensagemLonga = str_repeat('Falha ao consultar a ordem de coleta no banco de gestão de coletas e ao tentar novamente em seguida. ', 2);
    $mensagemLonga = substr($mensagemLonga, 0, 158) . '.';
    $idLongo = $log('ERRO', 'EXPEDICAO', 'oc_consulta_falhou', $mensagemLonga, $idDoca, 'classe=PDOException;http=500;motivo=timeout', 4000000000, 'NOW() - INTERVAL 5 MINUTE');
    $log('AVISO', 'EXPEDICAO', 'oc_baixa_falhou', str_repeat('SemEspacoNenhumNaMensagemMuitoLonga', 4) . 'Fim', $idGuiche, null, 1, 'NOW() - INTERVAL 6 MINUTE');
    $log('INFO', 'EXPEDICAO', 'erro_tecnico', 'Mensagem curta.', null, null, 1, 'NOW() - INTERVAL 7 MINUTE');
    for ($i = 1; $i <= 3; $i++) {
        $log($niveisLog[$i % 3], 'RECEBIMENTO', 'rate_limit_ocr_excedido', 'Limite de leitura de notas excedido pelo totem.', $idGuiche, null, $i, "NOW() - INTERVAL $i HOUR");
    }
    $idCronInfo = $log('INFO', 'CRON', 'cron_resumo', 'Rotina agendada concluída.', null, 'job=limpar_logs_gestao;logs_apagados=3;auditoria_apagados=0;lotes=1', 1, 'NOW() - INTERVAL 2 HOUR');
    $log('ERRO', 'CRON', 'cron_falhou', 'Rotina agendada falhou.', null, 'classe=PDOException;job=limpar_logs_gestao;motivo=erro_banco', 2, 'NOW() - INTERVAL 3 HOUR');
    $log('AVISO', 'CRON', 'cron_falhou', 'Rotina agendada falhou.', null, null, 1, 'NOW() - INTERVAL 4 HOUR');
    $log('ERRO', 'GESTAO', 'gestao_erro_interno', 'Erro interno na Gestão Totem.', null, null, 1, 'NOW() - INTERVAL 1 HOUR');
    $log('AVISO', 'GESTAO', 'auditoria_falhou', 'Falha ao gravar a trilha de auditoria.', null, 'motivo=auditoria_indisponivel', 2, 'NOW() - INTERVAL 2 HOUR');
    $idsLog = ['hostil' => $idLogHostil, 'longo' => $idLongo, 'cron' => $idCronInfo, 'semDetalhe' => (int) $pdo->query("SELECT id_log FROM tb_log_sistema WHERE origem = 'GESTAO' AND detalhe IS NULL LIMIT 1")->fetchColumn()];

    $env = array_merge(gtEnvPadrao(), ['GESTAO_PERMITIR_HTTP' => 'true', 'TOTEM_URL_BASE' => $urlBase]);
    putenv('QA_GESTAO_ENV_JSON=' . json_encode($env));
    $raiz = dirname(__DIR__, 2);
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $porta, '-t', $raiz . '/public', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'log_errors=0'];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/qa_gestao_layout_srv.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/qa_gestao_layout_srv.log', 'w']], $pipes, $raiz);
    if (!is_resource($proc)) {
        throw new RuntimeException('nao subiu o servidor');
    }
    // espera a porta aceitar conexao
    $pronto = false;
    for ($i = 0; $i < 50 && !$pronto; $i++) {
        $s = @fsockopen('127.0.0.1', $porta, $en, $es, 0.2);
        if ($s !== false) {
            fclose($s);
            $pronto = true;
        } else {
            usleep(100000);
        }
    }
    if (!$pronto) {
        throw new RuntimeException('servidor nao respondeu');
    }
    echo json_encode(['pronto' => true, 'porta' => $porta, 'banco' => $banco, 'senha' => GT_SENHA_BOA, 'logs' => $idsLog]) . "\n";
    fflush(STDOUT);
    // bloqueia ate o node mandar "fim" ou fechar o stdin
    while (($linha = fgets(STDIN)) !== false) {
        if (trim($linha) === 'fim') {
            break;
        }
        if (trim($linha) === 'esvaziar-logs') { // estado vazio da tela de logs
            $pdo->exec('DELETE FROM tb_log_sistema');
            echo "LOGS-ESVAZIADOS
";
            fflush(STDOUT);
        }
        if (trim($linha) === 'esvaziar') { // estado vazio da lista de totens (captura/teste)
            $pdo->exec('DELETE FROM tb_atendimento');
            $pdo->exec('DELETE FROM tb_totem');
            echo "ESVAZIADO\n";
            fflush(STDOUT);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    gtDestruirAmbiente($banco, $storage);
    echo "LIMPO\n";
}
