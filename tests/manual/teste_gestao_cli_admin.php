<?php

/**
 * Primeiro admin por CLI (Gestao Totem, F1, 2026-10-06): tools/criar-admin.php e
 * UsuarioGestaoRn::criarAdminInicial. Banco QA descartavel. O prompt interativo
 * sem eco (stty/PowerShell) exige um terminal real e NAO e exercitado aqui: o
 * teste prova as RECUSAS (fora do CLI, sem terminal, senha por argumento), a regra
 * de negocio e a ausencia de rota web de cadastro inicial.
 *
 * Uso: php tests/manual/teste_gestao_cli_admin.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\UsuarioGestaoDao;
use App\Rn\UsuarioGestaoRn;
use Util\AuthGestao;
use Util\GestaoConfig;
use Util\SenhaPolitica;

$raiz = dirname(__DIR__, 2);
$banco = null;
$storage = null;

function rodarCli(string $raiz, array $args, array $envExtra = []): array
{
    $cmd = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', $raiz . '/tools/criar-admin.php'], $args);
    $env = [
        'QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'),
        'QA_QR_FORCE_STORAGE' => (string) getenv('QA_QR_FORCE_STORAGE'),
        'SystemRoot' => (string) getenv('SystemRoot'),
        'QA_GESTAO_ENV_JSON' => json_encode(gtEnvPadrao()),
    ] + $envExtra;
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, $env);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);

    return ['rc' => $rc, 'out' => $out, 'err' => $err];
}

try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $dao = new UsuarioGestaoDao($pdo);
    $rn = new UsuarioGestaoRn($pdo);
    $conta = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_usuario');

    // ---- recusas do script
    $semTty = rodarCli($raiz, ['--login=ana.admin', '--nome=Ana Admin']);
    afirmar('CLI sem terminal interativo (stdin e pipe) => recusa com codigo 2 e nada e criado', $semTty['rc'] === 2 && str_contains($semTty['err'], 'terminal interativo') && $conta() === 0);
    $porArg = rodarCli($raiz, ['--login=ana.admin', '--nome=Ana Admin', '--senha=Senha-Muito-Secreta-1']);
    afirmar('CLI com a senha por ARGUMENTO => recusa (argumento nao reconhecido), nada criado e a senha nao e ecoada', $porArg['rc'] === 1 && !str_contains($porArg['out'] . $porArg['err'], 'Senha-Muito-Secreta-1') && $conta() === 0);
    $porArg2 = rodarCli($raiz, ['Senha-Muito-Secreta-1']);
    afirmar('CLI com argumento posicional (senha) => recusa e nao ecoa', $porArg2['rc'] === 1 && !str_contains($porArg2['out'] . $porArg2['err'], 'Senha-Muito-Secreta-1') && $conta() === 0);
    $porEnv = rodarCli($raiz, ['--login=ana.admin', '--nome=Ana Admin'], ['GESTAO_ADMIN_SENHA' => 'Senha-Muito-Secreta-1', 'SENHA' => 'Senha-Muito-Secreta-1']);
    afirmar('CLI com a senha por VARIAVEL DE AMBIENTE nao a usa (sem terminal => recusa) e nada e criado', $porEnv['rc'] === 2 && $conta() === 0);

    // fora do CLI: php-cgi (SAPI diferente) nao executa nada
    $cgi = gtCgiBinario();
    $proc = proc_open([$cgi, '-q', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', $raiz . '/tools/criar-admin.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, [
        'REDIRECT_STATUS' => '200', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $raiz . '/tools/criar-admin.php', 'QUERY_STRING' => 'login=x.y',
        'QA_QR_FORCE_DB_NAME' => (string) getenv('QA_QR_FORCE_DB_NAME'), 'SystemRoot' => (string) getenv('SystemRoot'),
    ]);
    fclose($pipes[0]);
    $saidaCgi = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    afirmar('fora do CLI (SAPI cgi) o script responde 404 vazio e nao cria nada', preg_match('/^Status:\s*404/mi', $saidaCgi) === 1 && $conta() === 0 && !str_contains($saidaCgi, 'Administrador'));

    // ---- inspecao estatica
    $fonte = (string) file_get_contents($raiz . '/tools/criar-admin.php');
    afirmar('criar-admin: so PHP_SAPI cli, stdin tem de ser terminal, sem getenv/$_ENV/$_SERVER de senha', str_contains($fonte, "PHP_SAPI !== 'cli'") && str_contains($fonte, 'stream_isatty(STDIN)') && !preg_match('/getenv\(|\$_ENV\[|\$_SERVER\[|\$_GET|\$_POST/', $fonte));
    afirmar('criar-admin: nao imprime senha nem hash (so o login criado)', !preg_match('/echo[^;]*\$(senha|hash|confirmacao)|print[^;]*\$(senha|hash)/i', $fonte));
    $rotasQueCriamAdmin = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/public', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php' && preg_match('/criarAdminInicial|criar-admin/i', (string) file_get_contents($f->getPathname())) === 1) {
            $rotasQueCriamAdmin[] = $f->getFilename();
        }
    }
    foreach (['app/Controller', 'app/Views'] as $pasta) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $pasta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/criarAdminInicial/i', (string) file_get_contents($f->getPathname())) === 1) {
                $rotasQueCriamAdmin[] = $f->getFilename();
            }
        }
    }
    afirmar('NENHUMA rota/controller/view web chama o cadastro inicial de admin', $rotasQueCriamAdmin === []);
    $webCadastro = gtChamar(['arquivo' => 'usuario-form.php', 'metodo' => 'POST', 'form' => ['login' => 'primeiro.admin', 'nome' => 'Primeiro Admin', 'perfil' => 'admin']]);
    afirmar('sem admin e sem sessao, o web nao cria o primeiro admin (401)', $webCadastro['status'] === 401 && $conta() === 0);

    // ---- regra de negocio (Rn)
    $r = $rn->criarAdminInicial('bruno.carvalho', 'Bruno Carvalho', 'curta');
    afirmar('Rn: senha fraca (curta) recusada', !$r['ok'] && isset($r['erros']['senha']) && $conta() === 0);
    $r = $rn->criarAdminInicial('bruno.carvalho', 'Bruno Carvalho', 'bruno.carvalho');
    afirmar('Rn: senha igual ao login recusada', !$r['ok'] && isset($r['erros']['senha']));
    $r = $rn->criarAdminInicial('bruno', 'Bruno Carvalho', GT_SENHA_BOA);
    afirmar('Rn: login fora do formato primeiro.segundo recusado', !$r['ok'] && isset($r['erros']['login']));
    $r = $rn->criarAdminInicial('bruno.carvalho', '<b>', GT_SENHA_BOA);
    afirmar('Rn: nome invalido recusado', !$r['ok'] && isset($r['erros']['nome']));
    $r = $rn->criarAdminInicial('Bruno.Carvalho', 'Bruno Carvalho', GT_SENHA_BOA);
    $u = $dao->buscarPorLogin('bruno.carvalho');
    afirmar('Rn: primeiro admin criado (perfil admin, ativo, SEM troca obrigatoria, so o hash, sem criado_por)', $r['ok'] && $u !== null && $u['perfil'] === 'admin' && (int) $u['ativo'] === 1 && (int) $u['deve_trocar_senha'] === 0 && $u['criado_por'] === null && SenhaPolitica::verificar(GT_SENHA_BOA, (string) $u['senha_hash']) && $u['senha_hash'] !== GT_SENHA_BOA);
    $aud = gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria')[0] ?? null;
    afirmar('Rn: auditoria USUARIO_CRIAR OK com origem=cli, sem ator e sem IP', $aud !== null && $aud['acao'] === 'USUARIO_CRIAR' && $aud['resultado'] === 'OK' && $aud['detalhe'] === 'origem=cli;perfil_para=admin' && $aud['id_usuario'] === null && $aud['ip'] === null && (int) $aud['alvo_id'] === (int) $u['id_usuario']);
    afirmar('Rn: nada da senha/hash na auditoria', !str_contains(json_encode($aud) ?: '', GT_SENHA_BOA) && !str_contains(json_encode($aud) ?: '', 'argon'));
    $cfg = GestaoConfig::doAmbiente(gtEnvPadrao());
    $a = new AuthGestao($pdo, $cfg, ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on', 'HTTP_HOST' => 'g.test', 'HTTP_USER_AGENT' => 'x'], []);
    afirmar('o admin criado por CLI consegue entrar e cai direto na tela inicial (sem troca)', ($rl = $a->login('bruno.carvalho', GT_SENHA_BOA))['ok'] === true && $rl['deve_trocar_senha'] === false);
    $r = $rn->criarAdminInicial('outro.admin', 'Outro Admin', GT_SENHA_BOA);
    afirmar('Rn: recusa se ja existe admin ativo (admin_existe) e nada e criado', !$r['ok'] && $r['codigo'] === 'admin_existe' && $dao->buscarPorLogin('outro.admin') === null);
    $r = $rn->criarAdminInicial('outro.admin', 'Outro Admin', GT_SENHA_BOA, true);
    afirmar('Rn: --forcar cria mesmo assim', $r['ok'] && $dao->buscarPorLogin('outro.admin') !== null);
    $r = $rn->criarAdminInicial('outro.admin', 'Outro Admin', GT_SENHA_BOA, true);
    afirmar('Rn: --forcar tambem recusa login duplicado', !$r['ok'] && isset($r['erros']['login']));
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 0 WHERE perfil = 'admin'");
    $r = $rn->criarAdminInicial('recuperacao.admin', 'Recuperacao Admin', GT_SENHA_BOA);
    afirmar('Rn: sem admin ATIVO (so inativos) o primeiro admin pode ser criado sem --forcar', $r['ok']);
    afirmar('Rn: nenhuma linha PENDENTE ficou aberta', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ')', false);
} finally {
    gtDestruirAmbiente($banco, $storage);
}
exit(gtResumo('teste_gestao_cli_admin'));
