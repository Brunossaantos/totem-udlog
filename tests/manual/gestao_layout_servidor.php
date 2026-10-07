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

    $env = array_merge(gtEnvPadrao(), ['GESTAO_PERMITIR_HTTP' => 'true']);
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
    echo json_encode(['pronto' => true, 'porta' => $porta, 'banco' => $banco, 'senha' => GT_SENHA_BOA]) . "\n";
    fflush(STDOUT);
    // bloqueia ate o node mandar "fim" ou fechar o stdin
    while (($linha = fgets(STDIN)) !== false) {
        if (trim($linha) === 'fim') {
            break;
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
