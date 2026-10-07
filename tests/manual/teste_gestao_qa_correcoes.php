<?php

/**
 * QA INDEPENDENTE da rodada de CORRECAO da Gestao Totem (F0+F1, 2026-10-06).
 * Testes ADVERSARIAIS focados no DELTA (M1, M2/desbloquear, B1, B3, B5, B6, B7,
 * acentuacao, flash, botoes das paginas de erro, F0), por php-cgi contra as
 * paginas REAIS, em banco QA descartavel (qa_gestao_infra.php). Escritos pelo QA,
 * nao pelos implementadores.
 *
 * Uso: php tests/manual/teste_gestao_qa_correcoes.php
 *
 * Nunca toca em udlog_totem; sem rede; sem Talent/VIO/n8n.
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Controller\GestaoContexto;
use Util\AuthGestao;
use Util\GestaoConfig;
use Util\GestaoHttp;
use Util\IpCliente;
use Util\LimiteFalhasIp;
use Util\SenhaPolitica;

// ---------------------------------------------------------------------------
// Modo worker (concorrencia real: cada worker e um processo PHP separado)
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode((string) base64_decode((string) $argv[2]), true);
    while (microtime(true) < (float) $job['t0']) {
        // espera ativa ate o instante comum de largada
    }
    $r = gtChamar($job['o']);
    echo base64_encode((string) json_encode(['status' => $r['status'], 'cab' => $r['cabecalhos'], 'corpo' => substr($r['corpo'], 0, 60000)]));
    exit(0);
}

const Q_SENT_LOGIN = 'Sentinela-Login-Qk47-Longa';
const Q_SENT_ERRADA = 'Sentinela-Errada-Pj73-Longa';
const Q_SENT_NOVA = 'Sentinela-Nova-Wk52-Longa';
const Q_TEMP_RE = '/\b[A-HJ-NP-Za-km-z2-9]{4}(?:-[A-HJ-NP-Za-km-z2-9]{4}){3}\b/';

$banco = null;
$storage = null;
$log = gtNovoLogCgi();
@unlink($log);
$PAGINAS = [];   // toda resposta da gestao, para a varredura de acentuacao

/** Dispara N requisicoes em processos separados com largada comum. @return list<array<string,mixed>> */
function qSimultaneas(array $jobs, float $atrasoSeg = 1.2): array
{
    $t0 = microtime(true) + $atrasoSeg;
    $hs = [];
    foreach ($jobs as $o) {
        $cmd = [PHP_BINARY, __FILE__, '--worker', base64_encode((string) json_encode(['t0' => $t0, 'o' => $o]))];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        $hs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($hs as [$p, $pipes]) {
        $txt = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
        $d = json_decode((string) base64_decode($txt), true);
        $out[] = is_array($d) ? $d : ['status' => 0, 'cab' => [], 'corpo' => ''];
    }

    return $out;
}

/** Formas sem acento que, em texto de UI em portugues, indicam acentuacao esquecida. */
function qSemAcento(string $texto): array
{
    $palavras = ['nao', 'voce', 'voces', 'invalido', 'invalida', 'invalidos', 'usuario', 'usuarios', 'acao', 'acoes', 'pagina', 'paginas',
        'servico', 'sessao', 'sessoes', 'permissao', 'requisicao', 'metodo', 'confirmacao', 'orfao', 'orfaos', 'codigo', 'ja', 'so', 'ate',
        'apos', 'alteracoes', 'conexao', 'seguranca', 'excecao', 'informacao', 'operacao', 'obrigatoria', 'obrigatorio', 'unico', 'ultimo',
        'inicio', 'tambem', 'minuscula', 'minusculas', 'proximo', 'proxima', 'gestao', 'senha ok'];
    $ach = [];
    if (preg_match_all('/(?<![\p{L}\p{N}_$.\/-])(' . implode('|', array_map(static fn ($p) => str_replace(' ', '\s+', $p), $palavras)) . ')(?![\p{L}\p{N}_-])/iu', $texto, $m) > 0) {
        $ach = array_values(array_unique(array_map('strtolower', $m[1])));
    }

    return $ach;
}

/** Texto visivel de uma pagina: nos de texto + atributos aria-label/title/alt/placeholder/data-confirmar*. */
function qTextoVisivel(string $html): string
{
    $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
    $attrs = '';
    if (preg_match_all('/\b(?:aria-label|title|alt|placeholder|data-confirmar[a-z-]*)="([^"]*)"/i', $html, $m) > 0) {
        $attrs = ' ' . implode(' ', $m[1]);
    }
    $semTags = (string) preg_replace('/<[^>]*>/', ' ', $html);

    return html_entity_decode($semTags . $attrs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function qLinha(string $html, int $id): string
{
    return preg_match('/data-id-usuario="' . $id . '".*?<\/tr>/s', $html, $m) === 1 ? $m[0] : '';
}

/** @return array{acao:?string,href:?string} botao #gestao-erro-acao de uma pagina de erro */
function qBotaoErro(array $r): array
{
    if (preg_match('/id="gestao-erro-acao" data-acao="([a-z]+)" href="([^"]*)"/', $r['corpo'], $m) === 1) {
        return ['acao' => $m[1], 'href' => html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }

    return ['acao' => null, 'href' => null];
}

function qTemporaria(array $r): ?string
{
    return preg_match('/id="senha-temporaria-valor">([^<]+)</', $r['corpo'], $m) === 1 ? $m[1] : null;
}

try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $ipn = 0;
    $ip = static function () use (&$ipn): string {
        $ipn++;

        return '198.18.' . intdiv($ipn, 250) . '.' . (($ipn % 250) + 1);
    };
    /** chama a gestao e guarda o corpo para a varredura de acentuacao */
    $R = static function (array $o) use (&$PAGINAS, $log): array {
        $r = gtChamar($o + ['logErro' => $log]);
        $PAGINAS[] = ['arq' => (string) ($o['arquivo'] ?? '') . ' ' . (string) ($o['metodo'] ?? 'GET'), 'status' => $r['status'], 'corpo' => $r['corpo'], 'json' => str_contains((string) gtCabecalho($r, 'content-type'), 'json')];

        return $r;
    };
    $lerLog = static fn (): string => is_file($log) ? (string) file_get_contents($log) : '';
    $zerarLog = static function () use ($log): void {
        @unlink($log);
    };
    $usuarioDe = static fn (string $login): array => gtLinhas($pdo, 'SELECT * FROM tb_gestao_usuario WHERE login = :l', ['l' => $login])[0] ?? [];
    $auditorias = static fn (string $acao, ?int $alvo = null): array => gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria WHERE acao = :a' . ($alvo !== null ? ' AND alvo_id = :t' : '') . ' ORDER BY id_auditoria', ['a' => $acao] + ($alvo !== null ? ['t' => $alvo] : []));
    $idDe = static fn (string $login): int => (int) gtEscalar($pdo, 'SELECT id_usuario FROM tb_gestao_usuario WHERE login = :l', ['l' => $login]);

    $login = static function (string $user, string $senha, ?string $ipx = null) use ($R, $ip): array {
        $ipx ??= $ip();
        $g = $R(['arquivo' => 'login.php', 'ip' => $ipx]);
        $r = $R(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => $user, 'senha' => $senha, 'token_login' => (string) gtTokenLogin($g)], 'ip' => $ipx]);
        $sid = gtSid($r);
        $csrf = null;
        if ($sid !== null) {
            $csrf = gtCsrf($R(['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => $sid], 'ip' => $ipx]));
        }

        return ['sid' => $sid, 'csrf' => $csrf, 'resp' => $r, 'ip' => $ipx];
    };
    $falhar = static function (string $user, ?string $ipx = null, string $senha = 'senha-errada-qa-123') use ($R, $ip): array {
        $ipx ??= $ip();
        $g = $R(['arquivo' => 'login.php', 'ip' => $ipx]);

        return $R(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => $user, 'senha' => $senha, 'token_login' => (string) gtTokenLogin($g)], 'ip' => $ipx]);
    };
    $postar = static fn (array $sess, string $arq, array $form, array $x = []): array => $R(['arquivo' => $arq, 'metodo' => 'POST', 'form' => $form + ['csrf_token' => (string) $sess['csrf']], 'cookies' => ['gestao_sid' => (string) $sess['sid']], 'ip' => $sess['ip']] + $x);
    $obter = static fn (array $sess, string $arq, array $q = [], array $x = []): array => $R(['arquivo' => $arq, 'query' => $q, 'cookies' => ['gestao_sid' => (string) $sess['sid']], 'ip' => $sess['ip']] + $x);
    $versaoDe = static function (array $sess, int $id) use ($obter): ?int {
        $p = $obter($sess, 'usuarios.php');

        return preg_match('/name="versao_senha" value="(\d+)"/', qLinha($p['corpo'], $id), $m) === 1 ? (int) $m[1] : null;
    };

    // usuarios
    $idAna = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idBeto = gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Admin');
    $idCarla = gtSemear($pdo, 'carla.silva', 'usuario', GT_SENHA_BOA, false, true, 'Carla Silva');
    $idDavi = gtSemear($pdo, 'davi.rocha', 'usuario', GT_SENHA_BOA, false, true, 'Davi Rocha');
    $idEdu = gtSemear($pdo, 'edu.lima', 'usuario', GT_SENHA_BOA, false, true, 'Edu Lima');
    $idMari = gtSemear($pdo, 'mari.sentinela', 'usuario', Q_SENT_LOGIN, false, true, 'Mari Sentinela');

    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 1)')->execute(['c' => 'QUIOSQUE-QA-01', 'n' => 'Totem QA', 't' => bin2hex(random_bytes(32))]);
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 0)')->execute(['c' => 'QUIOSQUE-OFF-01', 'n' => 'Totem Off', 't' => bin2hex(random_bytes(32))]);

    // =====================================================================
    // (a) M1: senha/hash fora de log, saida e resposta sob excecoes REAIS
    // =====================================================================
    // controle positivo: sem as defesas do app, o PHP vaza a sentinela (prova que as chaves de ini sao operantes)
    $ctl = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_ctl_' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($ctl, '<?php function entrar($senha) { throw new RuntimeException("falha"); } entrar("' . Q_SENT_LOGIN . '");');
    $rodarCtl = static function (string $ignorarArgs) use ($ctl, $log): string {
        @unlink($log);
        $p = proc_open([gtCgiBinario(), '-q', '-d', 'zend.exception_ignore_args=' . $ignorarArgs, '-d', 'display_errors=1', '-d', 'html_errors=0', '-d', 'log_errors=1', '-d', 'error_log="' . $log . '"', $ctl], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SystemRoot' => (string) getenv('SystemRoot')]);
        $o = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);

        return $o . (is_file($log) ? (string) file_get_contents($log) : '');
    };
    afirmar('M1 controle positivo: excecao nao tratada SEM defesa e com exception_ignore_args=0 VAZA a senha no stack trace (o PHP trunca em 15 caracteres; a busca usa o prefixo) - a prova abaixo e discriminante', str_contains($rodarCtl('0'), substr(Q_SENT_LOGIN, 0, 12)));
    afirmar('M1 controle negativo: com exception_ignore_args=1 o mesmo script nao vaza', !str_contains($rodarCtl('1'), substr(Q_SENT_LOGIN, 0, 12)));
    @unlink($ctl);

    $vaza = static function (string $texto, array $sentinelas): array {
        $ruins = [];
        foreach ($sentinelas as $s) {
            // o PHP trunca argumentos de stack trace em 15 caracteres: a busca vale para o PREFIXO tambem
            if (str_contains($texto, $s) || str_contains($texto, substr($s, 0, 12))) {
                $ruins[] = 'sentinela:' . substr($s, 0, 12);
            }
        }
        foreach (['$argon2', '$2y$', 'Stack trace', '#0 ', 'SQLSTATE', 'C:\\xampp', 'C:/xampp', '.php(', 'getMessage'] as $p) {
            if (str_contains($texto, $p)) {
                $ruins[] = $p;
            }
        }
        if (preg_match(Q_TEMP_RE, $texto) === 1) {
            $ruins[] = 'senha_temporaria';
        }

        return $ruins;
    };
    $sentinelas = [Q_SENT_LOGIN, Q_SENT_ERRADA, Q_SENT_NOVA];
    $ini0 = ['zend.exception_ignore_args=0', 'display_errors=1', 'html_errors=0'];
    $renomear = static function (string $de, string $para) use ($pdo): void {
        $pdo->exec("RENAME TABLE `$de` TO `$para`");
    };
    $cenario = static function (string $nome, callable $quebrar, callable $restaurar, callable $req, int $statusEsperado, ?string $textoEsperado, ?string $logEsperado) use ($zerarLog, $lerLog, $vaza, $sentinelas): void {
        $zerarLog();
        $quebrar();
        try {
            $r = $req();
        } finally {
            $restaurar();
        }
        $l = $lerLog();
        $resp = $r['cru'];
        afirmar("M1 [$nome]: status $statusEsperado" . ($textoEsperado !== null ? " e texto \"$textoEsperado\"" : ''), $r['status'] === $statusEsperado && ($textoEsperado === null || str_contains($r['corpo'], $textoEsperado) || str_contains((string) gtCabecalho($r, 'location'), $textoEsperado)));
        afirmar("M1 [$nome]: ZERO vazamento na RESPOSTA (sentinela, hash, stack trace, SQLSTATE, caminho, senha temporaria)", $vaza($resp, $sentinelas) === []);
        afirmar("M1 [$nome]: ZERO vazamento no LOG" . ($logEsperado !== null ? " e o log tem a linha fixa \"$logEsperado\" (a excecao aconteceu)" : ''), $vaza($l, $sentinelas) === [] && ($logEsperado === null || str_contains($l, $logEsperado)));
    };

    // S1/S2/S3: login com tabelas quebradas
    $form = static function (string $u, string $s, string $ipx) use ($R): array {
        $g = $R(['arquivo' => 'login.php', 'ip' => $ipx]);

        return ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => $u, 'senha' => $s, 'token_login' => (string) gtTokenLogin($g)], 'ip' => $ipx, 'ini' => ['zend.exception_ignore_args=0', 'display_errors=1', 'html_errors=0']];
    };
    $cenario('login valido, tabela de sessao ausente', fn () => $renomear('tb_gestao_sessao', 'tb_gestao_sessao_qa'), fn () => $renomear('tb_gestao_sessao_qa', 'tb_gestao_sessao'), fn () => $R($form('mari.sentinela', Q_SENT_LOGIN, $ip())), 500, 'Não foi possível entrar agora', 'login_falhou PDOException');
    afirmar('M1: o login que falhou depois da senha certa NAO deixou sessao nem cookie (nada foi alterado)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_usuario = :i', ['i' => $idMari]) === 0);
    $cenario('login com senha errada, tabela de usuario ausente', fn () => $renomear('tb_gestao_usuario', 'tb_gestao_usuario_qa'), fn () => $renomear('tb_gestao_usuario_qa', 'tb_gestao_usuario'), fn () => $R($form('mari.sentinela', Q_SENT_ERRADA, $ip())), 500, 'Não foi possível entrar agora', 'login_falhou PDOException');
    $cenario('login valido, tabela de tentativas ausente', fn () => $renomear('tb_gestao_login_tentativa', 'tb_gestao_login_tentativa_qa'), fn () => $renomear('tb_gestao_login_tentativa_qa', 'tb_gestao_login_tentativa'), fn () => $R($form('mari.sentinela', Q_SENT_LOGIN, $ip())), 500, 'Não foi possível entrar agora', 'login_falhou PDOException');
    $cenario('login valido, auditoria ausente (login deve continuar)', fn () => $renomear('tb_gestao_auditoria', 'tb_gestao_auditoria_qa'), fn () => $renomear('tb_gestao_auditoria_qa', 'tb_gestao_auditoria'), fn () => $R($form('mari.sentinela', Q_SENT_LOGIN, $ip())), 302, '/gestao/', 'auditoria_falhou PDOException');
    $pdo->prepare('DELETE FROM tb_gestao_sessao WHERE id_usuario = :i')->execute(['i' => $idMari]);

    // S5: troca de senha com UPDATE que falha (trigger), 3 variantes
    $sMari = $login('mari.sentinela', Q_SENT_LOGIN);
    afirmar('M1 pre-condicao: mari logou (sessao e csrf)', $sMari['sid'] !== null && $sMari['csrf'] !== null);
    $hashMari = $usuarioDe('mari.sentinela')['senha_hash'];
    $trigUpd = static fn () => $pdo->exec("CREATE TRIGGER qa_trg_upd BEFORE UPDATE ON tb_gestao_usuario FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa falha simulada'");
    $dropUpd = static fn () => $pdo->exec('DROP TRIGGER IF EXISTS qa_trg_upd');
    $cenario('troca de senha: atualizarSenha falha (senha atual certa)', $trigUpd, $dropUpd, fn () => $postar($sMari, 'conta.php', ['senha_atual' => Q_SENT_LOGIN, 'senha_nova' => Q_SENT_NOVA, 'senha_confirmacao' => Q_SENT_NOVA], ['ini' => $ini0]), 500, 'Não foi possível trocar a senha', 'trocarPropriaSenha_falhou PDOException');
    afirmar('M1: troca que falhou NAO alterou o hash e a sessao continua', $usuarioDe('mari.sentinela')['senha_hash'] === $hashMari && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_usuario = :i', ['i' => $idMari]) === 1);
    $cenario('troca de senha: senha atual ERRADA e UPDATE de falha quebrado', $trigUpd, $dropUpd, fn () => $postar($sMari, 'conta.php', ['senha_atual' => Q_SENT_ERRADA, 'senha_nova' => Q_SENT_NOVA, 'senha_confirmacao' => Q_SENT_NOVA], ['ini' => $ini0]), 500, 'Não foi possível trocar a senha', 'trocarPropriaSenha_falhou PDOException');
    $cenario('troca de senha: auditoria ausente (abrir falha na transacao)', fn () => $renomear('tb_gestao_auditoria', 'tb_gestao_auditoria_qa'), fn () => $renomear('tb_gestao_auditoria_qa', 'tb_gestao_auditoria'), fn () => $postar($sMari, 'conta.php', ['senha_atual' => Q_SENT_LOGIN, 'senha_nova' => Q_SENT_NOVA, 'senha_confirmacao' => Q_SENT_NOVA], ['ini' => $ini0]), 500, 'Não foi possível trocar a senha', 'trocarPropriaSenha_falhou PDOException');
    afirmar('M1: apos as falhas a senha antiga continua valendo e a nova NAO (rollback real)', SenhaPolitica::verificar(Q_SENT_LOGIN, $usuarioDe('mari.sentinela')['senha_hash']) && !SenhaPolitica::verificar(Q_SENT_NOVA, $usuarioDe('mari.sentinela')['senha_hash']));

    // S6/S7: criar e redefinir (senha temporaria e hash nos frames)
    $sAna = $login('ana.admin', GT_SENHA_BOA);
    $trigIns = static fn () => $pdo->exec("CREATE TRIGGER qa_trg_ins BEFORE INSERT ON tb_gestao_usuario FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa falha simulada'");
    $dropIns = static fn () => $pdo->exec('DROP TRIGGER IF EXISTS qa_trg_ins');
    $cenario('criar usuario: INSERT falha (senha temporaria e hash nos frames)', $trigIns, $dropIns, fn () => $postar($sAna, 'usuario-form.php', ['login' => 'novo.pessoa', 'nome' => 'Novo Pessoa', 'perfil' => 'usuario'], ['ini' => $ini0]), 302, 'msg=erro_interno', 'criar_falhou PDOException');
    afirmar('M1: criar que falhou NAO deixou usuario', $usuarioDe('novo.pessoa') === []);
    $verMari = $versaoDe($sAna, $idMari);
    $cenario('redefinir senha: UPDATE falha', $trigUpd, $dropUpd, fn () => $postar($sAna, 'usuarios.php', ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idMari, 'versao_senha' => (string) $verMari], ['ini' => $ini0]), 302, 'msg=erro_interno', 'redefinirSenha_falhou PDOException');
    afirmar('M1: redefinir que falhou manteve o hash e a senha_versao', $usuarioDe('mari.sentinela')['senha_hash'] === $hashMari && $versaoDe($sAna, $idMari) === $verMari);
    $cenario('redefinir senha: auditoria ausente', fn () => $renomear('tb_gestao_auditoria', 'tb_gestao_auditoria_qa'), fn () => $renomear('tb_gestao_auditoria_qa', 'tb_gestao_auditoria'), fn () => $postar($sAna, 'usuarios.php', ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idMari, 'versao_senha' => (string) $verMari], ['ini' => $ini0]), 302, 'msg=erro_interno', 'redefinirSenha_falhou PDOException');
    // controle positivo do padrao de senha temporaria: o caso de SUCESSO mostra a temporaria uma vez
    $zerarLog();
    $okCriar = $postar($sAna, 'usuario-form.php', ['login' => 'novo.pessoa', 'nome' => 'Novo Pessoa', 'perfil' => 'usuario']);
    $tempCriada = qTemporaria($okCriar);
    afirmar('M1 controle positivo: criar OK mostra a senha temporaria no corpo (o padrao Q_TEMP_RE a reconhece) e ela NAO esta no log', $okCriar['status'] === 200 && $tempCriada !== null && preg_match(Q_TEMP_RE, $okCriar['corpo']) === 1 && !str_contains($lerLog(), (string) $tempCriada));
    $logCompleto = $lerLog();
    afirmar('M1: a senha temporaria e o seu hash nao estao em nenhuma linha de auditoria nem em colunas de texto do banco', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria WHERE detalhe LIKE :a OR detalhe LIKE :b', ['a' => '%' . $tempCriada . '%', 'b' => '%argon2%']) === 0);

    // S8: conexao com o banco falha (503) com a senha digitada
    $antesDb = (string) getenv('QA_QR_FORCE_DB_NAME');
    putenv('QA_QR_FORCE_DB_NAME=qa_qr_exclusivo_00000000');
    $zerarLog();
    $r503 = gtChamar(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'mari.sentinela', 'senha' => Q_SENT_LOGIN, 'token_login' => '1800000000.' . str_repeat('a', 64)], 'ip' => $ip(), 'logErro' => $log, 'ini' => $ini0]);
    putenv('QA_QR_FORCE_DB_NAME=' . $antesDb);
    afirmar('M1 [conexao com o banco falha]: 503 generico, sem sentinela/trace/caminho/SQLSTATE na resposta e no log', $r503['status'] === 503 && $vaza($r503['cru'] . $lerLog(), $sentinelas) === []);

    // S9: excecao NAO tratada (usuario ausente na sessao) -> tratador global
    $cenario('excecao nao tratada na sessao (tabela de usuario ausente) em POST de conta', fn () => $renomear('tb_gestao_usuario', 'tb_gestao_usuario_qa'), fn () => $renomear('tb_gestao_usuario_qa', 'tb_gestao_usuario'), fn () => $postar($sAna, 'conta.php', ['senha_atual' => Q_SENT_LOGIN, 'senha_nova' => Q_SENT_NOVA, 'senha_confirmacao' => Q_SENT_NOVA], ['ini' => $ini0]), 500, 'Não foi possível concluir', 'excecao_nao_tratada PDOException');
    $r9 = $R(['arquivo' => 'conta.php', 'cookies' => ['gestao_sid' => (string) $sAna['sid']], 'ip' => $sAna['ip']]);
    afirmar('M1: apos restaurar as tabelas a sessao e a pagina voltam ao normal', $r9['status'] === 200);
    afirmar('M1: nenhuma coluna de auditoria carrega sentinela de senha', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE detalhe LIKE '%Sentinela%'") === 0);

    // =====================================================================
    // (b) M2: Desbloquear (HTTP)
    // =====================================================================
    $bloquearPorLogin = static function (string $user) use ($falhar, $usuarioDe): void {
        for ($i = 0; $i < 5; $i++) {
            $falhar($user);
        }
    };
    $sAdmA = $login('ana.admin', GT_SENHA_BOA);
    $sAdmB = $login('beto.admin', GT_SENHA_BOA);
    $sUsuC = $login('carla.silva', GT_SENHA_BOA);
    $bloquearPorLogin('davi.rocha');
    $davi = $usuarioDe('davi.rocha');
    afirmar('DoS: 5 falhas de login bloqueiam a conta (bloqueado_ate no futuro)', $davi['bloqueado_ate'] !== null && (int) gtEscalar($pdo, 'SELECT bloqueado_ate > NOW() FROM tb_gestao_usuario WHERE id_usuario = :i', ['i' => $idDavi]) === 1);
    $rBloq = $login('davi.rocha', GT_SENHA_BOA);
    afirmar('DoS: com a conta bloqueada nem a senha CERTA entra (401, sem cookie)', $rBloq['resp']['status'] === 401 && $rBloq['sid'] === null);
    $pg = $obter($sAdmA, 'usuarios.php');
    $linhaDavi = qLinha($pg['corpo'], $idDavi);
    afirmar('tela: a linha do bloqueado mostra "Bloqueado até" e o botao Desbloquear (acao=desbloquear)', str_contains($linhaDavi, 'Bloqueado até') && str_contains($linhaDavi, 'value="desbloquear"'));
    afirmar('tela: linhas SEM bloqueio (carla, edu) nao mostram o botao', !str_contains(qLinha($pg['corpo'], $idCarla), 'value="desbloquear"') && !str_contains(qLinha($pg['corpo'], $idEdu), 'value="desbloquear"'));
    // bloqueia tambem a propria ana (por SQL) e confere que a linha PROPRIA nunca oferece o botao
    $pdo->prepare('UPDATE tb_gestao_usuario SET bloqueado_ate = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id_usuario = :i')->execute(['i' => $idAna]);
    $pgAna = $obter($sAdmA, 'usuarios.php');
    $pgBeto = $obter($sAdmB, 'usuarios.php');
    afirmar('tela: o admin bloqueado NAO ve Desbloquear na PROPRIA linha (so a dica de Minha conta); o outro admin ve na linha dela', !str_contains(qLinha($pgAna['corpo'], $idAna), 'value="desbloquear"') && str_contains(qLinha($pgAna['corpo'], $idAna), 'Minha conta') && str_contains(qLinha($pgBeto['corpo'], $idAna), 'value="desbloquear"'));
    $totalAntes = count($auditorias('USUARIO_DESBLOQUEAR'));
    $forma = ['acao' => 'desbloquear', 'id_usuario' => (string) $idDavi];
    // RBAC: usuario comum
    $rU = $postar($sUsuC, 'usuarios.php', $forma);
    afirmar('RBAC: perfil usuario => 403, conta segue bloqueada, nenhuma auditoria nova', $rU['status'] === 403 && $usuarioDe('davi.rocha')['bloqueado_ate'] !== null && count($auditorias('USUARIO_DESBLOQUEAR')) === $totalAntes);
    // RBAC: anonimo
    $rAn = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $forma + ['csrf_token' => (string) $sAdmA['csrf']], 'ip' => $ip()]);
    $btnAn = qBotaoErro($rAn);
    afirmar('RBAC: anonimo (sem cookie, mesmo com um CSRF de admin) => 401, botao "login", nada muda', $rAn['status'] === 401 && $btnAn['acao'] === 'login' && $usuarioDe('davi.rocha')['bloqueado_ate'] !== null);
    $rAnJ = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $forma, 'ip' => $ip(), 'cabecalhos' => ['HTTP_ACCEPT' => 'application/json']]);
    afirmar('RBAC: anonimo pedindo JSON => 401 JSON (nao pagina)', $rAnJ['status'] === 401 && is_array(json_decode($rAnJ['corpo'], true)) && (json_decode($rAnJ['corpo'], true)['sucesso'] ?? null) === false);
    $rUJ = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $forma + ['csrf_token' => (string) $sUsuC['csrf']], 'cookies' => ['gestao_sid' => (string) $sUsuC['sid']], 'ip' => $sUsuC['ip'], 'cabecalhos' => ['HTTP_ACCEPT' => 'application/json']]);
    afirmar('RBAC: usuario pedindo JSON => 403 JSON', $rUJ['status'] === 403 && (json_decode($rUJ['corpo'], true)['sucesso'] ?? null) === false);
    // CSRF
    $rC1 = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $forma, 'cookies' => ['gestao_sid' => (string) $sAdmB['sid']], 'ip' => $sAdmB['ip']]);
    $rC2 = $postar(['sid' => $sAdmB['sid'], 'csrf' => str_repeat('0', 64), 'ip' => $sAdmB['ip']], 'usuarios.php', $forma);
    $rC3 = $postar(['sid' => $sAdmB['sid'], 'csrf' => $sUsuC['csrf'], 'ip' => $sAdmB['ip']], 'usuarios.php', $forma);
    $rC4 = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $forma + ['csrf_token' => (string) $sAdmB['csrf']], 'cookies' => ['gestao_sid' => (string) $sAdmB['sid']], 'ip' => $sAdmB['ip'], 'origin' => 'https://evil.exemplo.test']);
    $rC5 = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $forma + ['csrf_token' => (string) $sAdmB['csrf']], 'cookies' => ['gestao_sid' => (string) $sAdmB['sid']], 'ip' => $sAdmB['ip'], 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'origin' => null]);
    afirmar('CSRF: sem token, token errado, token de OUTRA sessao, Origin estranho, Sec-Fetch-Site cross-site => 403 e a conta segue bloqueada', [$rC1['status'], $rC2['status'], $rC3['status'], $rC4['status'], $rC5['status']] === [403, 403, 403, 403, 403] && $usuarioDe('davi.rocha')['bloqueado_ate'] !== null && count($auditorias('USUARIO_DESBLOQUEAR')) === $totalAntes);
    $rM = $R(['arquivo' => 'usuarios.php', 'metodo' => 'GET', 'query' => ['acao' => 'desbloquear', 'id_usuario' => (string) $idDavi], 'cookies' => ['gestao_sid' => (string) $sAdmB['sid']], 'ip' => $sAdmB['ip']]);
    afirmar('GET com acao=desbloquear na query NAO executa nada (so POST muda estado)', $rM['status'] === 200 && $usuarioDe('davi.rocha')['bloqueado_ate'] !== null);
    // entradas invalidas
    $invalidas = ['', 'abc', '0', '-1', '1.5', '1 OR 1=1', '99999999999', "{$idDavi}'", "{$idDavi}\0"];
    $status400 = [];
    foreach ($invalidas as $iv) {
        $status400[] = $postar($sAdmB, 'usuarios.php', ['acao' => 'desbloquear', 'id_usuario' => $iv])['status'];
    }
    $rArr = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'body' => null, 'corpo' => 'acao=desbloquear&id_usuario[]=' . $idDavi . '&csrf_token=' . $sAdmB['csrf'], 'cookies' => ['gestao_sid' => (string) $sAdmB['sid']], 'ip' => $sAdmB['ip']]);
    afirmar('entradas invalidas de id_usuario (vazio, texto, 0, negativo, decimal, SQL, enorme, apostrofo, NUL, array) => 400 sem efeito', $status400 === array_fill(0, count($invalidas), 400) && $rArr['status'] === 400 && $usuarioDe('davi.rocha')['bloqueado_ate'] !== null);
    $rInex = $postar($sAdmB, 'usuarios.php', ['acao' => 'desbloquear', 'id_usuario' => '999999']);
    afirmar('id inexistente => redireciona com msg=nao_encontrado', $rInex['status'] === 302 && str_contains((string) gtCabecalho($rInex, 'location'), 'msg=nao_encontrado'));
    $rAcaoRuim = $postar($sAdmB, 'usuarios.php', ['acao' => 'Desbloquear', 'id_usuario' => (string) $idDavi]);
    afirmar('acao com caixa diferente ("Desbloquear") nao e aceita (400)', $rAcaoRuim['status'] === 400);
    // efeito
    $antesSessDavi = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_usuario = :i', ['i' => $idDavi]);
    $hashDaviAntes = $usuarioDe('davi.rocha')['senha_hash'];
    $rOk = $postar($sAdmB, 'usuarios.php', $forma);
    $d2 = $usuarioDe('davi.rocha');
    afirmar('desbloquear OK: 302 com msg=usuario_desbloqueado, bloqueado_ate NULL, tentativas 0, hash intacto', $rOk['status'] === 302 && str_contains((string) gtCabecalho($rOk, 'location'), 'msg=usuario_desbloqueado') && $d2['bloqueado_ate'] === null && (int) $d2['tentativas_falhas'] === 0 && $d2['senha_hash'] === $hashDaviAntes);
    $aud = array_values(array_filter($auditorias('USUARIO_DESBLOQUEAR', $idDavi), static fn ($l) => $l['resultado'] === 'OK'));
    afirmar('auditoria USUARIO_DESBLOQUEAR: 1 linha OK, ator = beto, alvo = davi, IP do ator e sem detalhe de senha', count($aud) === 1 && (int) $aud[0]['id_usuario'] === $idBeto && $aud[0]['alvo_tipo'] === 'usuario' && $aud[0]['ip'] === inet_pton((string) $sAdmB['ip']) && ($aud[0]['detalhe'] === null || !str_contains((string) $aud[0]['detalhe'], 'senha')));
    $rVolta = $login('davi.rocha', GT_SENHA_BOA);
    afirmar('DoS fechado: depois do Desbloquear o login com a senha certa volta a funcionar (cookie emitido)', $rVolta['resp']['status'] === 302 && $rVolta['sid'] !== null);
    $rAgain = $postar($sAdmB, 'usuarios.php', $forma);
    afirmar('desbloquear de conta ja livre => msg=sem_mudanca e NENHUMA auditoria nova', str_contains((string) gtCabecalho($rAgain, 'location'), 'msg=sem_mudanca') && count(array_filter($auditorias('USUARIO_DESBLOQUEAR', $idDavi), static fn ($l) => $l['resultado'] === 'OK')) === 1);
    // admin bloqueado por PUBLICO + desbloqueio entre admins; auto-desbloqueio por POST forjado (observacao)
    $rProp = $postar($sAdmA, 'usuarios.php', ['acao' => 'desbloquear', 'id_usuario' => (string) $idAna]);
    $anaDepois = $usuarioDe('ana.admin');
    afirmar('auto-desbloqueio por POST forjado: recusado no servidor (302 msg=proprio_desbloqueio), a conta SEGUE bloqueada', $rProp['status'] === 302 && str_contains((string) gtCabecalho($rProp, 'location'), 'msg=proprio_desbloqueio') && $anaDepois['bloqueado_ate'] !== null);
    $pdo->prepare('UPDATE tb_gestao_usuario SET bloqueado_ate = NULL, tentativas_falhas = 0 WHERE id_usuario = :i')->execute(['i' => $idAna]);
    // desbloquear tambem zera so tentativas (sem bloqueio) -> auditado e login ok
    $pdo->prepare('UPDATE tb_gestao_usuario SET tentativas_falhas = 3 WHERE id_usuario = :i')->execute(['i' => $idEdu]);
    $rT = $postar($sAdmB, 'usuarios.php', ['acao' => 'desbloquear', 'id_usuario' => (string) $idEdu]);
    afirmar('desbloquear zera tambem tentativas_falhas > 0 sem bloqueio (e audita)', str_contains((string) gtCabecalho($rT, 'location'), 'msg=usuario_desbloqueado') && (int) $usuarioDe('edu.lima')['tentativas_falhas'] === 0 && count($auditorias('USUARIO_DESBLOQUEAR', $idEdu)) === 1);
    // admin INATIVO com cookie antigo
    $sInat = $login('beto.admin', GT_SENHA_BOA);
    $bloquearPorLogin('edu.lima');
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idBeto]);
    $rInat = $postar($sInat, 'usuarios.php', ['acao' => 'desbloquear', 'id_usuario' => (string) $idEdu]);
    afirmar('admin DESATIVADO com cookie antigo: sessao revogada (401), a conta alvo continua bloqueada', in_array($rInat['status'], [401, 403], true) && $usuarioDe('edu.lima')['bloqueado_ate'] !== null);
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = :i')->execute(['i' => $idBeto]);
    $sAdmB = $login('beto.admin', GT_SENHA_BOA);
    // concorrencia: 4 workers (2 admins x 2) desbloqueiam o MESMO alvo ao mesmo tempo
    $totAntes = count($auditorias('USUARIO_DESBLOQUEAR', $idEdu));
    $jobBase = static fn (array $s): array => ['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => ['acao' => 'desbloquear', 'id_usuario' => (string) $idEdu, 'csrf_token' => (string) $s['csrf']], 'cookies' => ['gestao_sid' => (string) $s['sid']], 'ip' => $s['ip']];
    $conc = qSimultaneas([$jobBase($sAdmA), $jobBase($sAdmB), $jobBase($sAdmA), $jobBase($sAdmB)]);
    $locs = array_map(static fn ($c) => (string) ($c['cab']['location'][0] ?? ''), $conc);
    $okN = count(array_filter($locs, static fn ($l) => str_contains($l, 'msg=usuario_desbloqueado')));
    $semN = count(array_filter($locs, static fn ($l) => str_contains($l, 'msg=sem_mudanca')));
    $erroN = count(array_filter($locs, static fn ($l) => str_contains($l, 'msg=erro_interno')));
    $novas = array_slice($auditorias('USUARIO_DESBLOQUEAR', $idEdu), $totAntes);
    afirmar("concorrencia: 4 desbloqueios simultaneos => exatamente 1 efetivo + 3 sem_mudanca, 0 erro_interno (efetivo=$okN sem=$semN erro=$erroN)", $okN === 1 && $semN === 3 && $erroN === 0 && count($novas) === 1 && $novas[0]['resultado'] === 'OK' && $usuarioDe('edu.lima')['bloqueado_ate'] === null);

    // =====================================================================
    // (c) B6: conta bloqueada nao verifica a senha atual na troca
    // =====================================================================
    $sDavi = $login('davi.rocha', GT_SENHA_BOA);
    $bloquearPorLogin('davi.rocha');
    $antes = $usuarioDe('davi.rocha');
    $rb1 = $postar($sDavi, 'conta.php', ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => 'Outra-Senha-Forte-77', 'senha_confirmacao' => 'Outra-Senha-Forte-77']);
    $m1 = qTextoVisivel($rb1['corpo']);
    $dep1 = $usuarioDe('davi.rocha');
    $rb2 = $postar($sDavi, 'conta.php', ['senha_atual' => 'senha-errada-qa-999', 'senha_nova' => 'Outra-Senha-Forte-77', 'senha_confirmacao' => 'Outra-Senha-Forte-77']);
    $m2 = qTextoVisivel($rb2['corpo']);
    $dep2 = $usuarioDe('davi.rocha');
    afirmar('B6: bloqueada + senha atual CERTA => 422, mensagem de bloqueio, hash NAO mudou, sessao mantida', $rb1['status'] === 422 && str_contains($m1, 'bloqueada') && $dep1['senha_hash'] === $antes['senha_hash'] && $obter($sDavi, 'conta.php')['status'] === 200);
    afirmar('B6: bloqueada + senha atual ERRADA => a MESMA mensagem (a senha nem foi verificada: sem oraculo)', $rb2['status'] === 422 && str_contains($m2, 'bloqueada') && !str_contains($m2, 'senha atual está incorreta'));
    afirmar('B6: nenhuma das duas tentativas contou falha nova nem estendeu o bloqueio (tentativas e bloqueado_ate identicos)', $dep1['bloqueado_ate'] === $antes['bloqueado_ate'] && $dep2['bloqueado_ate'] === $antes['bloqueado_ate'] && $dep2['tentativas_falhas'] === $antes['tentativas_falhas']);
    $audB6 = array_filter($auditorias('SENHA_TROCADA', $idDavi), static fn ($l) => $l['resultado'] === 'SEM_EFEITO' && str_contains((string) $l['detalhe'], 'conta_bloqueada'));
    afirmar('B6: as 2 tentativas viraram auditoria SENHA_TROCADA SEM_EFEITO (motivo conta_bloqueada)', count($audB6) === 2);
    $postar($sAdmB, 'usuarios.php', ['acao' => 'desbloquear', 'id_usuario' => (string) $idDavi]);
    $rb3 = $postar($sDavi, 'conta.php', ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => 'Outra-Senha-Forte-77', 'senha_confirmacao' => 'Outra-Senha-Forte-77']);
    afirmar('B6 controle: depois do Desbloquear a mesma troca funciona (302, sessao nova, senha nova vale)', $rb3['status'] === 302 && gtSid($rb3) !== null && SenhaPolitica::verificar('Outra-Senha-Forte-77', $usuarioDe('davi.rocha')['senha_hash']));
    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :h WHERE id_usuario = :i')->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA), 'i' => $idDavi]);

    // =====================================================================
    // (d) B7: reenvio / adulteracao de versao_senha no redefinir
    // =====================================================================
    $sAdmA = $login('ana.admin', GT_SENHA_BOA);
    $verEdu = $versaoDe($sAdmA, $idEdu);
    $verCarla = $versaoDe($sAdmA, $idCarla);
    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_versao = 7 WHERE id_usuario = :i')->execute(['i' => $idCarla]);
    $hashEdu0 = $usuarioDe('edu.lima')['senha_hash'];
    $rv = [];
    $casos = ['ausente' => null, 'texto' => 'abc', 'zero' => '0', 'negativa' => '-1', 'zero a esquerda' => '0' . $verEdu, 'com espaco' => ' ' . $verEdu, 'enorme' => '99999999999', 'adulterada (alta)' => '999999', 'de OUTRO usuario (carla=7)' => '7'];
    foreach ($casos as $nome => $v) {
        $f = ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idEdu] + ($v !== null ? ['versao_senha' => $v] : []);
        $r = $postar($sAdmA, 'usuarios.php', $f);
        $rv[$nome] = $r;
    }
    $todosRecusados = true;
    foreach ($rv as $nome => $r) {
        $loc = (string) gtCabecalho($r, 'location');
        $ok = ($r['status'] === 400) || ($r['status'] === 302 && str_contains($loc, 'msg=senha_ja_redefinida'));
        $todosRecusados = $todosRecusados && $ok && qTemporaria($r) === null;
    }
    afirmar('B7: versao ausente/texto/0/negativa/com zero a esquerda/com espaco/enorme/adulterada/de outro usuario => recusada (400 ou senha_ja_redefinida) SEM senha temporaria', $todosRecusados);
    afirmar('B7: nenhuma recusa mexeu no hash do alvo', $usuarioDe('edu.lima')['senha_hash'] === $hashEdu0 && (int) $usuarioDe('edu.lima')['senha_versao'] === $verEdu);
    $rArrV = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'corpo' => 'acao=redefinir-senha&id_usuario=' . $idEdu . '&versao_senha[]=' . $verEdu . '&csrf_token=' . $sAdmA['csrf'], 'cookies' => ['gestao_sid' => (string) $sAdmA['sid']], 'ip' => $sAdmA['ip']]);
    afirmar('B7: versao_senha[] (array) => 400', $rArrV['status'] === 400 && qTemporaria($rArrV) === null);
    $r1 = $postar($sAdmA, 'usuarios.php', ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idEdu, 'versao_senha' => (string) $verEdu]);
    $temp1 = qTemporaria($r1);
    $hashEdu1 = $usuarioDe('edu.lima')['senha_hash'];
    afirmar('B7: o POST legitimo gera UMA senha temporaria, troca o hash e incrementa a senha_versao', $r1['status'] === 200 && $temp1 !== null && $hashEdu1 !== $hashEdu0 && (int) $usuarioDe('edu.lima')['senha_versao'] === $verEdu + 1 && SenhaPolitica::verificar($temp1, $hashEdu1));
    $r2 = $postar($sAdmA, 'usuarios.php', ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idEdu, 'versao_senha' => (string) $verEdu]);
    $r3 = $postar($sAdmA, 'usuarios.php', ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idEdu, 'versao_senha' => (string) $verEdu]);
    afirmar('B7: F5/reenvio do MESMO POST (2x) => msg=senha_ja_redefinida, NENHUMA senha nova, hash e versao iguais, a 1a temporaria continua valendo', str_contains((string) gtCabecalho($r2, 'location'), 'msg=senha_ja_redefinida') && str_contains((string) gtCabecalho($r3, 'location'), 'msg=senha_ja_redefinida') && qTemporaria($r2) === null && qTemporaria($r3) === null && $usuarioDe('edu.lima')['senha_hash'] === $hashEdu1 && SenhaPolitica::verificar($temp1, $usuarioDe('edu.lima')['senha_hash']));
    $nOk = count(array_filter($auditorias('SENHA_RESETADA', $idEdu), static fn ($l) => $l['resultado'] === 'OK'));
    $nSe = count(array_filter($auditorias('SENHA_RESETADA', $idEdu), static fn ($l) => $l['resultado'] === 'SEM_EFEITO'));
    afirmar("B7: auditoria SENHA_RESETADA: 1 OK e as recusas como SEM_EFEITO (ok=$nOk sem_efeito=$nSe)", $nOk === 1 && $nSe === 4);
    $rEduLogin = $login('edu.lima', (string) $temp1);
    afirmar('B7: a senha temporaria entregue funciona no login e exige troca (redireciona a conta.php)', $rEduLogin['sid'] !== null && str_contains((string) gtCabecalho($rEduLogin['resp'], 'location'), '/gestao/conta.php'));
    // concorrencia: 3 reenvios simultaneos com a mesma versao => 1 so efetivo
    $verCarla2 = $versaoDe($sAdmA, $idCarla);
    $jobR = ['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idCarla, 'versao_senha' => (string) $verCarla2, 'csrf_token' => (string) $sAdmA['csrf']], 'cookies' => ['gestao_sid' => (string) $sAdmA['sid']], 'ip' => $sAdmA['ip']];
    $cr = qSimultaneas([$jobR, $jobR, $jobR]);
    $com200 = count(array_filter($cr, static fn ($c) => $c['status'] === 200 && preg_match(Q_TEMP_RE, $c['corpo']) === 1));
    $comJa = count(array_filter($cr, static fn ($c) => str_contains((string) ($c['cab']['location'][0] ?? ''), 'msg=senha_ja_redefinida')));
    afirmar("B7 concorrencia: 3 envios simultaneos da mesma versao => 1 senha temporaria so ($com200) e 2 recusas ($comJa)", $com200 === 1 && $comJa === 2);

    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :h, deve_trocar_senha = 0 WHERE id_usuario = :i')->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA), 'i' => $idCarla]);

    // =====================================================================
    // (e) B5: IPv6 /64, IPv4 inteiro, nome do contador = HMAC, sem sal
    // =====================================================================
    $statusLogin = static function (string $ipx) use ($falhar): int {
        return $falhar('zz.naoexiste', $ipx)['status'];
    };
    $v6 = static fn (int $n): string => '2001:db8:abcd:1::' . dechex($n);
    $s = [];
    for ($n = 1; $n <= 10; $n++) {
        $s[] = $statusLogin($v6($n));
    }
    afirmar('B5 HTTP: 10 falhas de 10 enderecos DIFERENTES do mesmo /64 => 401 em todas', $s === array_fill(0, 10, 401));
    afirmar('B5 HTTP: o 11o endereco do MESMO /64 (nunca visto) => 429 (balde /64 compartilhado)', $statusLogin('2001:db8:abcd:1:ffff:eeee:dddd:cccc') === 429);
    afirmar('B5 HTTP: um /64 DIFERENTE (vizinho e outro prefixo) nao e afetado (401)', $statusLogin('2001:db8:abcd:2::1') === 401 && $statusLogin('2001:db8:abce:1::1') === 401);
    $s4 = [];
    for ($n = 1; $n <= 10; $n++) {
        $s4[] = $statusLogin('203.0.113.7');
    }
    afirmar('B5 HTTP: IPv4 e INTEIRO: 10 falhas de 203.0.113.7 => o 11o do mesmo IP e 429, o vizinho 203.0.113.8 continua 401', $s4 === array_fill(0, 10, 401) && $statusLogin('203.0.113.7') === 429 && $statusLogin('203.0.113.8') === 401);
    afirmar('B5 HTTP: IPv4 mapeado em IPv6 (::ffff:203.0.113.7) cai no MESMO balde do IPv4 (429)', $statusLogin('::ffff:203.0.113.7') === 429);
    $dirContador = $storage . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit';
    $T = static fn (array $q, string $ipx, array $x = []): array => gtChamar(['raiz' => 'totem', 'arquivo' => 'index.php', 'query' => $q, 'ip' => $ipx, 'logErro' => $GLOBALS['log']] + $x);
    $ipA = '2001:db8:beef:1::1';
    $ipB = '2001:db8:beef:1:aa:bb:cc:dd';
    $ipC = '2001:db8:beef:2::1';
    $ipV4 = '198.19.77.5';
    foreach ([$ipA, $ipB, $ipC, $ipV4] as $x) {
        $T(['totem' => 'NAOEXISTE-99'], $x);
    }
    $arquivos = array_map('basename', glob($dirContador . DIRECTORY_SEPARATOR . '*.json') ?: []);
    $nomeA = gtNomeContador($ipA) . '.json';
    afirmar('B5 totem: 2 enderecos do mesmo /64 compartilham UM arquivo (HMAC do balde); /64 diferente e IPv4 tem o seu', gtNomeContador($ipA) === gtNomeContador($ipB) && gtNomeContador($ipA) !== gtNomeContador($ipC) && in_array($nomeA, $arquivos, true) && in_array(gtNomeContador($ipC) . '.json', $arquivos, true) && in_array(gtNomeContador($ipV4) . '.json', $arquivos, true) && count($arquivos) === 3);
    $estadoA = json_decode((string) file_get_contents($dirContador . DIRECTORY_SEPARATOR . $nomeA), true);
    afirmar('B5 totem: o arquivo do /64 contou as 2 falhas dos 2 enderecos', ($estadoA['falhas'] ?? 0) === 2);
    $ingenuos = [];
    foreach ([$ipA, $ipB, $ipC, $ipV4, IpCliente::balde($ipA), IpCliente::balde($ipC)] as $x) {
        foreach ([hash('sha256', $x), md5($x), sha1($x), hash('sha256', IpCliente::balde($x))] as $h) {
            $ingenuos[] = substr($h, 0, 40) . '.json';
        }
    }
    afirmar('B5 totem: NENHUM arquivo tem nome = sha256/md5/sha1 simples do IP ou do balde (nome = HMAC com sal)', array_intersect($arquivos, $ingenuos) === []);
    afirmar('B5 totem: o nome tambem depende do sal (HMAC com outro sal nao reproduz o nome)', gtNomeContador($ipA, 'outro-sal-gestao-0123456789abcdef') !== gtNomeContador($ipA));
    // sem sal: o quiosque nao quebra
    $sem = ['env' => ['GESTAO_HASH_SALT' => '']];
    $curto = ['env' => ['GESTAO_HASH_SALT' => 'curto']];
    $semOk = $T(['totem' => 'QUIOSQUE-QA-01'], '198.19.78.1', ['host' => 'totem.exemplo.test'] + $sem);
    $curtoOk = $T(['totem' => 'QUIOSQUE-QA-01'], '198.19.78.2', ['host' => 'totem.exemplo.test'] + $curto);
    $sem404 = $T(['totem' => 'NAOEXISTE-99'], '198.19.78.3', ['host' => 'totem.exemplo.test'] + $sem);
    $arq2 = array_map('basename', glob($dirContador . DIRECTORY_SEPARATOR . '*.json') ?: []);
    afirmar('B5: SEM GESTAO_HASH_SALT (vazio ou curto) o quiosque valido continua 200 (nunca 5xx) e o invalido 404', $semOk['status'] === 200 && $curtoOk['status'] === 200 && $sem404['status'] === 404 && $semOk['corpo'] !== '' && str_contains($semOk['corpo'], 'lgpd-termo-dados'));
    afirmar('B5: sem sal o contador usa o sal de reserva (HMAC com SAL_PADRAO), nunca o IP em claro', in_array(gtNomeContador('198.19.78.3', LimiteFalhasIp::SAL_PADRAO) . '.json', $arq2, true) && !in_array(substr(hash('sha256', '198.19.78.3'), 0, 40) . '.json', $arq2, true));
    // CF-Connecting-IP sem validar a faixa
    $remotoFora = '198.51.100.9';
    $codigos = [];
    for ($n = 1; $n <= 21; $n++) {
        $codigos[] = $T(['totem' => 'NAOEXISTE-' . $n], $remotoFora, ['cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '203.0.113.' . (100 + $n), 'HTTP_X_FORWARDED_FOR' => '192.0.2.' . $n]])['status'];
    }
    afirmar('F0/CF: REMOTE_ADDR fora do Cloudflare + CF-Connecting-IP/X-Forwarded-For forjados e DIFERENTES a cada pedido => 20x404 e o 21o e 429 (cabecalho ignorado)', array_slice($codigos, 0, 20) === array_fill(0, 20, 404) && $codigos[20] === 429);
    $cf = '173.245.48.5';
    $T(['totem' => 'NAOEXISTE-1'], $cf, ['cabecalhos' => ['HTTP_CF_CONNECTING_IP' => '203.0.113.50']]);
    $T(['totem' => 'NAOEXISTE-1'], $cf, ['cabecalhos' => ['HTTP_CF_CONNECTING_IP' => 'lixo<script>']]);
    $arq3 = array_map('basename', glob($dirContador . DIRECTORY_SEPARATOR . '*.json') ?: []);
    afirmar('F0/CF: remoto DENTRO da faixa Cloudflare usa o CF-Connecting-IP valido; invalido cai no remoto', in_array(gtNomeContador('203.0.113.50') . '.json', $arq3, true) && in_array(gtNomeContador($cf) . '.json', $arq3, true));
    afirmar('F0/CF: IpCliente::obter: IPv6 do Cloudflare aceita; IPv4 logo apos a faixa (173.245.64.1) e IPv6 vizinho NAO', IpCliente::obter(['REMOTE_ADDR' => '2606:4700::1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9']) === '203.0.113.9' && IpCliente::obter(['REMOTE_ADDR' => '173.245.64.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9']) === '173.245.64.1' && IpCliente::obter(['REMOTE_ADDR' => '2606:4701::1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9']) === '2606:4701::1' && IpCliente::obter(['REMOTE_ADDR' => '173.245.63.255', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9']) === '203.0.113.9' && IpCliente::obter(['REMOTE_ADDR' => '173.245.47.255', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9']) === '173.245.47.255');

    // =====================================================================
    // (f) B3: senhas triviais (unitario + HTTP)
    // =====================================================================
    $triviais = ['so espacos (12)' => str_repeat(' ', 12), 'so espacos (40)' => str_repeat(' ', 40), 'tabs' => str_repeat("\t", 14), 'repetida' => str_repeat('a', 14), 'repetida acentuada' => str_repeat('é', 20), '2 distintos' => 'abababababab', '4 distintos' => 'abcdabcdabcd', '4 distintos longa' => '1234123412341234', 'comum numerica' => '123456789012', 'comum caixa' => 'PASSWORD1234', 'comum com espacos' => 'pass word 1234', 'comum udlog' => 'UDLOG@2026', 'curta' => 'Abc!9xyz', '73 bytes' => str_repeat('abcde', 14) . 'abc', 'igual ao login' => 'carla.silva', 'UTF-8 invalido' => "abcdefghijkl\xff"];
    $recusadas = [];
    foreach ($triviais as $nome => $senha) {
        if (SenhaPolitica::validar($senha, 'carla.silva') === null) {
            $recusadas[] = $nome;
        }
    }
    afirmar('B3 unitario: todas as senhas triviais sao recusadas (nenhuma aceita: ' . implode(',', $recusadas) . ')', $recusadas === []);
    afirmar('B3 unitario: senhas boas aceitas (frase, simbolos, acentos, 5 distintos)', SenhaPolitica::validar(GT_SENHA_BOA, 'x.y') === null && SenhaPolitica::validar('Maçã verde, 2026 ótima', 'x.y') === null && SenhaPolitica::validar('abcdeabcdeab', 'x.y') === null);
    afirmar('B3 unitario: nova igual a atual recusada', SenhaPolitica::validar(GT_SENHA_BOA, 'x.y', GT_SENHA_BOA) !== null);
    $sCarla = $login('carla.silva', GT_SENHA_BOA);
    $hashCarla0 = $usuarioDe('carla.silva')['senha_hash'];
    $falhasHttp = [];
    foreach (['espacos' => str_repeat(' ', 14), 'repetida' => str_repeat('z', 14), 'poucos distintos' => 'abababababab', 'comum' => '123456789012', 'igual a atual' => GT_SENHA_BOA] as $nome => $nova) {
        $r = $postar($sCarla, 'conta.php', ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => $nova, 'senha_confirmacao' => $nova]);
        if ($r['status'] !== 422 || $usuarioDe('carla.silva')['senha_hash'] !== $hashCarla0 || !str_contains($r['corpo'], 'gestao-campo--erro')) {
            $falhasHttp[] = $nome . '=' . $r['status'];
        }
    }
    afirmar('B3 HTTP: espacos, repetida, poucos distintos, comum e igual a atual => 422 com erro no campo, hash intacto, sessao mantida' . ($falhasHttp !== [] ? ' (' . implode(',', $falhasHttp) . ')' : ''), $falhasHttp === [] && $obter($sCarla, 'conta.php')['status'] === 200);
    $rConf = $postar($sCarla, 'conta.php', ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => 'Senha-Boa-Nova-55', 'senha_confirmacao' => 'Senha-Boa-Nova-56']);
    afirmar('B3 HTTP: confirmacao diferente => 422 no campo de confirmacao e hash intacto', $rConf['status'] === 422 && str_contains(qTextoVisivel($rConf['corpo']), 'confirmação não confere') && $usuarioDe('carla.silva')['senha_hash'] === $hashCarla0);
    $rBoa = $postar($sCarla, 'conta.php', ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => 'Senha-Boa-Nova-55', 'senha_confirmacao' => 'Senha-Boa-Nova-55']);
    afirmar('B3 HTTP: senha boa aceita (302 ?msg=senha_trocada), sessoes antigas morrem, sessao nova vale, a nova senha loga', $rBoa['status'] === 302 && str_contains((string) gtCabecalho($rBoa, 'location'), 'msg=senha_trocada') && gtSid($rBoa) !== null && $obter($sCarla, 'conta.php')['status'] === 302 && $login('carla.silva', 'Senha-Boa-Nova-55')['sid'] !== null);
    $sSemAtual = $login('carla.silva', 'Senha-Boa-Nova-55');
    $rSemAtual = $postar($sSemAtual, 'conta.php', ['senha_nova' => 'Outra-Senha-Boa-88', 'senha_confirmacao' => 'Outra-Senha-Boa-88']);
    afirmar('B3 HTTP: troca SEM senha_atual => 422 (conta como falha de senha atual) e hash intacto', $rSemAtual['status'] === 422 && SenhaPolitica::verificar('Senha-Boa-Nova-55', $usuarioDe('carla.silva')['senha_hash']));

    // =====================================================================
    // (g) B1: token de login com chave derivada + (CSRF por ==)
    // =====================================================================
    $cfg = GestaoConfig::doAmbiente(gtEnvPadrao());
    $T0 = 1_800_000_000;
    $agora = $T0;
    $mk = static fn (callable $rel): AuthGestao => new AuthGestao($pdo, $cfg, ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on', 'HTTP_HOST' => 'g.exemplo.test', 'REQUEST_METHOD' => 'GET'], [], $rel);
    $emissor = $mk(static fn (): int => $T0);
    $tok = $emissor->tokenLogin();
    $valida = static fn (int $tAgora, string $t): bool => $mk(static fn (): int => $tAgora)->tokenLoginValido($t);
    afirmar('B1: token recem-emitido vale; vale ate 2 h (7200 s) e expira em 7201 s', $valida($T0, $tok) && $valida($T0 + 7200, $tok) && !$valida($T0 + 7201, $tok) && !$valida($T0 + 86400, $tok));
    afirmar('B1: token do FUTURO (relogio atrasado > 300 s) recusado; ate 300 s de folga aceita', !$valida($T0 - 301, $tok) && $valida($T0 - 299, $tok));
    [$ts, $mac] = explode('.', $tok);
    $ultimo = $mac[-1] === 'a' ? 'b' : 'a';
    $adulterados = [
        'ultimo hex trocado' => $ts . '.' . substr($mac, 0, -1) . $ultimo,
        'timestamp alterado (mais novo)' => ((string) ((int) $ts + 1)) . '.' . $mac,
        'timestamp alterado (renovado)' => ((string) ($T0 + 7000)) . '.' . $mac,
        'mac em MAIUSCULAS' => $ts . '.' . strtoupper($mac),
        'mac truncado' => $ts . '.' . substr($mac, 0, 63),
        'mac com 65' => $ts . '.' . $mac . '0',
        'sem ponto' => $ts . $mac,
        'quebra de linha no fim' => $tok . "\n",
        'espaco no inicio' => ' ' . $tok,
        'vazio' => '',
        'NUL' => $tok . "\0",
        'dois pontos' => $ts . '..' . $mac,
    ];
    $aceitos = [];
    foreach ($adulterados as $nome => $t) {
        if ($valida($T0 + 10, $t)) {
            $aceitos[] = $nome;
        }
    }
    afirmar('B1: tokens adulterados/malformados recusados (aceitos indevidamente: ' . ($aceitos === [] ? 'nenhum' : implode(',', $aceitos)) . ')', $aceitos === []);
    $comSalDireto = $ts . '.' . hash_hmac('sha256', 'gestao-login|' . $ts, GT_SAL);
    $comChaveDerivada = $ts . '.' . hash_hmac('sha256', 'gestao-login|' . $ts, hash_hmac('sha256', 'gestao-login-token-v1', GT_SAL));
    $comOutroSal = $ts . '.' . hash_hmac('sha256', 'gestao-login|' . $ts, hash_hmac('sha256', 'gestao-login-token-v1', 'outro-sal-gestao-0123456789abcdef'));
    $comRotuloV2 = $ts . '.' . hash_hmac('sha256', 'gestao-login|' . $ts, hash_hmac('sha256', 'gestao-login-token-v2', GT_SAL));
    afirmar('B1: assinatura direta com o SAL e recusada; com a chave derivada (controle) aceita; outro sal e outro rotulo de versao recusados', !$valida($T0 + 5, $comSalDireto) && $valida($T0 + 5, $comChaveDerivada) && !$valida($T0 + 5, $comOutroSal) && !$valida($T0 + 5, $comRotuloV2));
    $falhasAntes = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'LOGIN_FALHA'");
    $ipTk = $ip();
    $tokenReal = (string) gtTokenLogin($R(['arquivo' => 'login.php', 'ip' => $ipTk]));
    [$tsR, $macR] = explode('.', $tokenReal);
    $rTk = [];
    foreach ([$tsR . '.' . substr($macR, 0, -1) . ($macR[-1] === 'a' ? 'b' : 'a'), ((string) ((int) $tsR - 8000)) . '.' . $macR, '', 'lixo'] as $t) {
        $rTk[] = $R(['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'carla.silva', 'senha' => 'Senha-Boa-Nova-55', 'token_login' => $t], 'ip' => $ipTk]);
    }
    afirmar('B1 HTTP: login com token adulterado/expirado/vazio/lixo => 400, sem cookie e sem contar tentativa nem auditoria de login', array_column($rTk, 'status') === [400, 400, 400, 400] && count(array_filter($rTk, static fn ($r) => gtSid($r) !== null)) === 0 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'LOGIN_FALHA'") === $falhasAntes);
    // CSRF: hash_equals e nao ==  ("0e..." == "0e..." e verdadeiro em PHP)
    $sNum = $login('beto.admin', GT_SENHA_BOA);
    $csrfNum = '0e' . str_repeat('1', 62);
    $pdo->prepare('UPDATE tb_gestao_sessao SET csrf_token = :c WHERE id_sessao = :h')->execute(['c' => $csrfNum, 'h' => hash('sha256', (string) $sNum['sid'])]);
    $formNum = ['acao' => 'desbloquear', 'id_usuario' => (string) $idDavi];
    $rN1 = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $formNum + ['csrf_token' => '0e' . str_repeat('2', 62)], 'cookies' => ['gestao_sid' => (string) $sNum['sid']], 'ip' => $sNum['ip']]);
    $rN2 = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $formNum, 'cookies' => ['gestao_sid' => (string) $sNum['sid']], 'ip' => $sNum['ip'], 'cabecalhos' => ['HTTP_X_CSRF_TOKEN' => '0e' . str_repeat('3', 62)]]);
    $rN3 = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $formNum + ['csrf_token' => '0'], 'cookies' => ['gestao_sid' => (string) $sNum['sid']], 'ip' => $sNum['ip']]);
    $rN4 = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => $formNum + ['csrf_token' => $csrfNum], 'cookies' => ['gestao_sid' => (string) $sNum['sid']], 'ip' => $sNum['ip']]);
    afirmar('CSRF: token numerico-equivalente ("0e22..." vs "0e11...", header e campo, e "0") => 403 (hash_equals, nao ==); o token exato (controle) passa', [$rN1['status'], $rN2['status'], $rN3['status']] === [403, 403, 403] && $rN4['status'] === 302);

    // =====================================================================
    // (L) perfil/ativo lidos do BANCO a cada requisicao
    // =====================================================================
    $sAnaL = $login('ana.admin', GT_SENHA_BOA);
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'usuario' WHERE id_usuario = :i")->execute(['i' => $idAna]);
    $rL1 = $obter($sAnaL, 'usuarios.php');
    $rL2 = $postar($sAnaL, 'usuarios.php', ['acao' => 'desbloquear', 'id_usuario' => (string) $idEdu]);
    afirmar('perfil do BANCO: admin rebaixado no banco perde o acesso na MESMA sessao (GET 403 e POST 403)', $rL1['status'] === 403 && $rL2['status'] === 403);
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'admin' WHERE id_usuario = :i")->execute(['i' => $idAna]);
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'admin' WHERE id_usuario = :i")->execute(['i' => $idCarla]);
    $sCarlaL = $login('carla.silva', 'Senha-Boa-Nova-55');
    $rL3 = $obter($sCarlaL, 'usuarios.php');
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'usuario' WHERE id_usuario = :i")->execute(['i' => $idCarla]);
    $rL4 = $obter($sCarlaL, 'usuarios.php');
    afirmar('perfil do BANCO: usuario promovido no banco ganha acesso na MESMA sessao (200) e o rebaixamento volta a 403', $rL3['status'] === 200 && $rL4['status'] === 403);
    $sDesat = $login('ana.admin', GT_SENHA_BOA);
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idAna]);
    $rL5 = $obter($sDesat, 'usuarios.php');
    afirmar('ativo do BANCO: usuario desativado no banco tem a sessao negada na hora (302 login) e a sessao e removida', $rL5['status'] === 302 && str_contains((string) gtCabecalho($rL5, 'location'), '/gestao/login.php') && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :h', ['h' => hash('sha256', (string) $sDesat['sid'])]) === 0);
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = :i')->execute(['i' => $idAna]);

    // =====================================================================
    // (i) flash: so codigos fixos, sem eco de texto livre
    // =====================================================================
    $sFlash = $login('ana.admin', GT_SENHA_BOA);
    $tiposOk = true;
    foreach (GestaoContexto::MENSAGENS as $codigo => [$tipo, $texto]) {
        $p = $obter($sFlash, 'usuarios.php', ['msg' => $codigo]);
        $okTipo = preg_match('/id="gestao-flash" role="(alert|status)"[^>]*data-tipo="(sucesso|erro|info)"/', $p['corpo'], $m) === 1 && $m[2] === $tipo && str_contains($p['corpo'], 'gestao-flash--' . $tipo) && str_contains(html_entity_decode($p['corpo'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $texto) && ($m[1] === 'alert') === ($tipo === 'erro');
        $tiposOk = $tiposOk && $okTipo && $p['status'] === 200;
    }
    afirmar('flash: cada codigo de MENSAGENS aparece com o tipo certo (sucesso/erro/info), o texto do catalogo e role alert so para erro', $tiposOk);
    $livres = ['<script>alert(1)</script>', 'Texto livre injetado', 'usuario_editado<b>x</b>', 'usuario_editado ', "usuario_editado\0", 'USUARIO_EDITADO', '__proto__', 'constructor', '0', '../../etc/passwd', 'javascript:alert(1)', str_repeat('A', 5000), '%00', "x\r\nSet-Cookie: a=b"];
    $eco = [];
    foreach ($livres as $lv) {
        $p = $obter($sFlash, 'usuarios.php', ['msg' => $lv]);
        $corpoSemEsc = html_entity_decode($p['corpo'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($p['status'] !== 200 || str_contains($p['corpo'], 'id="gestao-flash"') || (strlen($lv) > 3 && str_contains($corpoSemEsc, $lv)) || str_contains((string) $p['cru'], 'Set-Cookie: a=b')) {
            $eco[] = substr($lv, 0, 20);
        }
    }
    afirmar('flash: ?msg= livre/hostil (script, texto, caixa diferente, espaco, NUL, proto, traversal, 5000 chars, CRLF) => NENHUM flash e NENHUM eco do texto' . ($eco !== [] ? ' (falhou: ' . implode('|', $eco) . ')' : ''), $eco === []);
    $pArr = $R(['arquivo' => 'usuarios.php', 'query' => ['msg' => ['usuario_editado']], 'cookies' => ['gestao_sid' => (string) $sFlash['sid']], 'ip' => $sFlash['ip']]);
    afirmar('flash: ?msg[]= (array) => sem flash e sem erro 5xx', $pArr['status'] === 200 && !str_contains($pArr['corpo'], 'id="gestao-flash"'));
    $pConta = $obter($sFlash, 'conta.php', ['msg' => 'senha_trocada']);
    $pConta2 = $obter($sFlash, 'conta.php', ['msg' => 'Senha alterada com sucesso']);
    afirmar('flash: conta.php?msg=senha_trocada mostra sucesso; o texto livre equivalente NAO', str_contains($pConta['corpo'], 'data-tipo="sucesso"') && !str_contains($pConta2['corpo'], 'id="gestao-flash"'));
    $pLogin = $R(['arquivo' => 'login.php', 'query' => ['msg' => 'usuario_editado'], 'ip' => $ip()]);
    $pLoginLivre = $R(['arquivo' => 'login.php', 'query' => ['msg' => 'Texto livre injetado <b>x</b>'], 'ip' => $ip()]);
    afirmar('flash: o login.php nunca ecoa texto livre de ?msg=', !str_contains($pLoginLivre['corpo'], 'Texto livre') && !str_contains($pLoginLivre['corpo'], 'id="gestao-flash"'));
    echo 'INFO: login.php?msg=usuario_editado (codigo FIXO do catalogo) ' . (str_contains($pLogin['corpo'], 'id="gestao-flash"') ? 'MOSTRA o flash do catalogo para quem nao esta logado (texto fixo, sem dado do usuario)' : 'nao mostra flash') . "
";

    // =====================================================================
    // (j) botoes das paginas de erro, por status/contexto
    // =====================================================================
    $sAdmJ = $login('ana.admin', GT_SENHA_BOA);
    $sUsuJ = $login('carla.silva', 'Senha-Boa-Nova-55');
    $idFlor = gtSemear($pdo, 'flor.admin', 'admin', GT_SENHA_BOA, true, true, 'Flor Admin');
    $sFlor = $login('flor.admin', GT_SENHA_BOA);
    $casosJ = [];
    $casosJ['401 POST sem sessao => login'] = [$R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => ['acao' => 'ativar'], 'ip' => $ip()]), 401, 'login', '/gestao/login.php'];
    $casosJ['403 perfil usuario => inicio'] = [$obter($sUsuJ, 'usuarios.php'), 403, 'inicio', '/gestao/'];
    $casosJ['403 CSRF => recarregar (sem query)'] = [$R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'query' => ['msg' => 'x'], 'form' => ['acao' => 'ativar', 'csrf_token' => 'errado'], 'cookies' => ['gestao_sid' => (string) $sAdmJ['sid']], 'ip' => $sAdmJ['ip']]), 403, 'recarregar', '/gestao/usuarios.php'];
    $casosJ['403 origem estranha => recarregar'] = [$R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => ['acao' => 'ativar', 'csrf_token' => (string) $sAdmJ['csrf']], 'cookies' => ['gestao_sid' => (string) $sAdmJ['sid']], 'ip' => $sAdmJ['ip'], 'origin' => 'https://evil.exemplo.test']), 403, 'recarregar', '/gestao/usuarios.php'];
    $casosJ['403 troca obrigatoria (POST) => conta'] = [$postar($sFlor, 'usuarios.php', ['acao' => 'ativar', 'id_usuario' => '1']), 403, 'conta', '/gestao/conta.php'];
    $casosJ['405 PUT com sessao => inicio'] = [$R(['arquivo' => 'usuarios.php', 'metodo' => 'PUT', 'cookies' => ['gestao_sid' => (string) $sAdmJ['sid']], 'ip' => $sAdmJ['ip']]), 405, 'inicio', '/gestao/'];
    $casosJ['405 PUT sem sessao => login'] = [$R(['arquivo' => 'usuarios.php', 'metodo' => 'PUT', 'ip' => $ip()]), 405, 'login', '/gestao/login.php'];
    $casosJ['403 HTTP (sem HTTPS) => sem botao'] = [$R(['arquivo' => 'login.php', 'https' => false, 'ip' => $ip()]), 403, null, null];
    $casosJ['400 host invalido => sem botao'] = [$R(['arquivo' => 'login.php', 'host' => 'host invalido!', 'ip' => $ip()]), 400, null, null];
    $casosJ['503 sem sal => sem botao'] = [$R(['arquivo' => 'login.php', 'env' => ['GESTAO_HASH_SALT' => ''], 'ip' => $ip()]), 503, null, null];
    putenv('QA_QR_FORCE_DB_NAME=qa_qr_exclusivo_00000000');
    $casosJ['503 banco fora => recarregar'] = [gtChamar(['arquivo' => 'usuarios.php', 'ip' => $ip(), 'logErro' => $log]), 503, 'recarregar', '/gestao/usuarios.php'];
    putenv('QA_QR_FORCE_DB_NAME=' . $antesDb);
    $renomear('tb_gestao_usuario', 'tb_gestao_usuario_qa');
    $casosJ['500 excecao nao tratada => recarregar'] = [$obter($sAdmJ, 'usuarios.php'), 500, 'recarregar', '/gestao/usuarios.php'];
    $renomear('tb_gestao_usuario_qa', 'tb_gestao_usuario');
    foreach ($casosJ as $nome => [$resp, $st, $acaoEsp, $hrefEsp]) {
        $b = qBotaoErro($resp);
        afirmar("botao de erro [$nome]: status $st, data-acao=" . ($acaoEsp ?? '(nenhum)') . ($hrefEsp !== null ? ", href=$hrefEsp" : ''), $resp['status'] === $st && $b['acao'] === $acaoEsp && $b['href'] === $hrefEsp && ($acaoEsp !== null || !str_contains($resp['corpo'], 'gestao-erro-acao')));
    }
    $uris = ['/gestao/usuarios.php?x=1&y=<script>' => '/gestao/usuarios.php', '/gestao/../etc/passwd' => '/gestao/', '/gestao/"onmouseover="x' => '/gestao/', '/outra/coisa.php' => '/gestao/', '/gestao/a b.php' => '/gestao/'];
    $okUri = true;
    foreach ($uris as $uri => $esp) {
        $rr = $R(['arquivo' => 'usuarios.php', 'metodo' => 'POST', 'form' => ['acao' => 'ativar', 'csrf_token' => 'errado'], 'cookies' => ['gestao_sid' => (string) $sAdmJ['sid']], 'ip' => $sAdmJ['ip'], 'cabecalhos' => ['REQUEST_URI' => $uri]]);
        $bb = qBotaoErro($rr);
        $okUri = $okUri && $rr['status'] === 403 && $bb['href'] === $esp && !str_contains($rr['corpo'], '<script>') && !str_contains($rr['corpo'], 'onmouseover="x');
    }
    afirmar('botao "recarregar": o href e SEMPRE um caminho /gestao/... sem query; REQUEST_URI hostil (script, traversal, aspas, outra raiz, espaco) cai em /gestao/ sem XSS', $okUri);
    afirmar('botoes de erro: todos os data-acao emitidos pertencem ao conjunto fechado {login, inicio, recarregar, conta}', array_diff(array_filter(array_map(static fn ($c) => qBotaoErro($c[0])['acao'], $casosJ)), ['login', 'inicio', 'recarregar', 'conta']) === []);

    // =====================================================================
    // (k) F0 inalterado
    // =====================================================================
    $hostil = ['' => '', 'espacos' => ' QUIOSQUE-QA-01 ', 'curinga' => '%', 'traversal' => '../../etc/passwd', '65 chars' => str_repeat('A', 65), 'inexistente' => 'NAOEXISTE-01', 'inativo' => 'QUIOSQUE-OFF-01', 'unicode' => "QUIOSQUE-QA-0\u{0031}\u{0301}"];
    $sig = [];
    $ipsF0 = 0;
    foreach ($hostil as $nome => $cod) {
        $rr = $T(['totem' => $cod], '198.19.90.' . (++$ipsF0), ['host' => 'totem.exemplo.test']);
        $cabN = $rr['cabecalhos'];
        unset($cabN['date'], $cabN['retry-after']);
        $sig[$nome] = [$rr['status'], md5($rr['corpo']), json_encode($cabN)];
    }
    afirmar('F0: 404 UNIFORME (mesmo status, corpo e cabecalhos) para vazio, espacos, curinga, traversal, 65 chars, inexistente, inativo e unicode', count(array_unique(array_map('json_encode', $sig))) === 1 && array_values($sig)[0][0] === 404);
    $rr404 = $T(['totem' => 'NAOEXISTE-01'], '198.19.91.1');
    afirmar('F0: o 404 nao cita o codigo nem o formato esperado e traz os 4 cabecalhos de seguranca', !str_contains($rr404['corpo'], 'NAOEXISTE') && !str_contains($rr404['corpo'], 'RECEPCAO') && !str_contains(strtolower($rr404['corpo']), 'parametro') && gtCabecalho($rr404, 'referrer-policy') === 'no-referrer' && gtCabecalho($rr404, 'cache-control') === 'no-store' && gtCabecalho($rr404, 'x-content-type-options') === 'nosniff' && str_contains((string) gtCabecalho($rr404, 'x-robots-tag'), 'noindex'));
    $ipV = '198.19.92.1';
    $stV = [];
    for ($n = 0; $n < 30; $n++) {
        $stV[] = $T(['totem' => 'QUIOSQUE-QA-01'], $ipV, ['host' => 'totem.exemplo.test'])['status'];
    }
    afirmar('F0: 30 pedidos VALIDOS do mesmo IP => 200 todos (so falhas contam) e nenhum contador criado', $stV === array_fill(0, 30, 200) && !is_file($dirContador . DIRECTORY_SEPARATOR . gtNomeContador($ipV) . '.json'));
    $ipM = '198.19.93.1';
    $stM = [];
    $esperadoM = [];
    for ($n = 0; $n < 25; $n++) {
        $stM[] = $T(['totem' => ($n % 2 === 0 ? 'NAOEXISTE-' . $n : 'QUIOSQUE-QA-01')], $ipM)['status'];
        $esperadoM[] = $n % 2 === 0 ? 404 : 200;
    }
    afirmar('F0: mistura 13 invalidos + 12 validos do mesmo IP (abaixo do limite de 20 falhas) => invalidos 404 e validos 200, nenhum 429 (so as falhas contam)', $stM === $esperadoM);
    $ipN = '198.19.94.1';
    $st20 = [];
    for ($n = 0; $n < 20; $n++) {
        $st20[] = $T(['totem' => 'NAOEXISTE-' . $n], $ipN)['status'];
    }
    $st21 = $T(['totem' => 'NAOEXISTE-x'], $ipN);
    $stValidoBloq = $T(['totem' => 'QUIOSQUE-QA-01'], $ipN);
    $stOutroIp = $T(['totem' => 'QUIOSQUE-QA-01'], '198.19.94.2');
    afirmar('F0: 20 invalidos => 404 x20; o 21o => 429 com Retry-After; IP vizinho segue 200', $st20 === array_fill(0, 20, 404) && $st21['status'] === 429 && (int) gtCabecalho($st21, 'retry-after') >= 1 && $stOutroIp['status'] === 200 && $stValidoBloq['status'] === 429);
    putenv('QA_QR_FORCE_DB_NAME=qa_qr_exclusivo_00000000');
    $st503 = [];
    for ($n = 0; $n < 25; $n++) {
        $st503[] = $T(['totem' => 'QUIOSQUE-QA-01'], '198.19.95.1')['status'];
    }
    putenv('QA_QR_FORCE_DB_NAME=' . $antesDb);
    afirmar('F0: banco fora => 503 (nunca 429) e nao conta como falha do IP', $st503 === array_fill(0, 25, 503) && !is_file($dirContador . DIRECTORY_SEPARATOR . gtNomeContador('198.19.95.1') . '.json'));

    // =====================================================================
    // (h) acentuacao: varredura de TODAS as respostas e das constantes de UI
    // =====================================================================
    afirmar('acentuacao: scanner detecta formas sem acento (controle positivo: voce, nao, pagina, usuarios, sessao, invalido, acao, senha ok)', qSemAcento('Voce nao pode abrir a pagina de usuarios: sessao com acao invalido. Senha ok') === ['voce', 'nao', 'pagina', 'usuarios', 'sessao', 'acao', 'invalido', 'senha ok']);
    afirmar('acentuacao: scanner nao dispara em texto acentuado, logins, caminhos e classes (controle negativo)', qSemAcento('Você não pode abrir a página de usuários. carla.usuario /gestao/usuarios.php $usuario gestao-botao usuario_editado') === []);
    $constantes = array_column(GestaoContexto::MENU, 'rotulo');
    foreach (GestaoContexto::MENSAGENS as [$t, $tx]) {
        $constantes[] = $tx;
    }
    array_push($constantes, AuthGestao::MSG_LOGIN_GENERICA, AuthGestao::MSG_LOGIN_ERRO_INTERNO, GestaoHttp::MSG_ERRO_INTERNO);
    foreach (['', str_repeat(' ', 14), str_repeat('a', 14), 'abababababab', '123456789012', 'curta', str_repeat('x1', 40), "abcdefghijkl\xff", 'carla.silva'] as $e) {
        $m = SenhaPolitica::validar($e, 'carla.silva');
        if ($m !== null) {
            $constantes[] = $m;
        }
    }
    $constantes[] = (string) SenhaPolitica::validar(GT_SENHA_BOA, null, GT_SENHA_BOA);
    foreach (App\Rn\UsuarioGestaoRn::validarCampos('x', '1', 'root') as $m) {
        $constantes[] = $m;
    }
    $problemas = [];
    foreach ($constantes as $t) {
        foreach (qSemAcento($t) as $a) {
            $problemas[] = $a . ' em "' . mb_substr($t, 0, 40) . '"';
        }
    }
    afirmar('acentuacao: constantes de UI (menu, mensagens, login, senha, campos, erro interno) sem forma sem acento (' . count($constantes) . ' textos)' . ($problemas !== [] ? ': ' . implode(' | ', $problemas) : ''), $problemas === []);
    $raiz = dirname(__DIR__, 2);
    $fontes = array_merge(glob($raiz . '/app/Views/gestao/*.php') ?: [], [$raiz . '/util/GestaoHttp.php', $raiz . '/util/AuthGestao.php', $raiz . '/app/Controller/GestaoContexto.php', $raiz . '/app/Controller/GestaoUsuarioController.php', $raiz . '/app/Controller/GestaoContaController.php', $raiz . '/app/Controller/GestaoLoginController.php', $raiz . '/app/Rn/UsuarioGestaoRn.php', $raiz . '/util/SenhaPolitica.php']);
    $probFontes = [];
    $totalTrechos = 0;
    foreach ($fontes as $f) {
        $trechos = [];
        foreach (token_get_all((string) file_get_contents($f)) as $tk) {
            if (!is_array($tk)) {
                continue;
            }
            if ($tk[0] === T_INLINE_HTML) {
                // fragmentos de HTML cortados pelo PHP embutido: tira atributos que nao sao texto de UI (class, name, value, id, href...)
                $frag = (string) preg_replace('/(?<![\w-])(?!aria-label|title|alt|placeholder|data-confirmar)[\w:-]+="[^"]*"/', ' ', $tk[1]);
                $frag = (string) preg_replace(['/(?<![\w-])(?!aria-label|title|alt|placeholder|data-confirmar)[\w:-]+="[^"]*\z/', '/\A[^<>"]*"[^<>]*>/'], ' ', $frag);
                $trechos[] = qTextoVisivel($frag);
            } elseif ($tk[0] === T_CONSTANT_ENCAPSED_STRING) {
                $lit = substr($tk[1], 1, -1);
                if (!str_contains($lit, '<') && str_contains($lit, ' ') && strlen($lit) >= 8 && !preg_match('/\A(SELECT |INSERT |UPDATE |DELETE |AuthGestao: |UsuarioGestaoRn: |gestao: |LimiteFalhasIp: |Content-|Cache-|X-|Referrer|Retry|Location|Allow|default-src|Format)/', $lit) && !str_contains($lit, '<symbol') && !str_contains($lit, 'FROM ')) {
                    $trechos[] = $lit . ' ';
                }
                if (preg_match('/<[a-z]/', $lit) === 1) {
                    $trechos[] = qTextoVisivel($lit);
                }
            }
        }
        $totalTrechos += count($trechos);
        foreach (qSemAcento(implode("
", $trechos)) as $a) {
            $probFontes[] = basename($f) . ': ' . $a;
        }
    }
    afirmar('acentuacao: texto de UI nas views e literais de frase dos controllers/Rn/Util sem forma sem acento (' . $totalTrechos . ' trechos)' . ($probFontes !== [] ? ': ' . implode(' | ', $probFontes) : ''), $probFontes === [] && $totalTrechos > 100);
    $js = (string) file_get_contents($raiz . '/public/gestao/assets/gestao.js');
    preg_match_all('/([\'"`])((?:(?!\1)[^\\\\\n]|\\\\.){8,})\1/u', $js, $mj);
    $probJs = [];
    foreach ($mj[2] as $lit) {
        if (preg_match('/\p{L}{2,} \p{L}{2,}/u', $lit) === 1) {
            foreach (qSemAcento($lit) as $a) {
                $probJs[] = $a . ' em "' . mb_substr($lit, 0, 40) . '"';
            }
        }
    }
    afirmar('acentuacao: textos de UI no gestao.js sem forma sem acento' . ($probJs !== [] ? ' (' . implode(' | ', $probJs) . ')' : ''), $probJs === []);
    $achadosPag = [];
    $nPag = 0;
    foreach ($PAGINAS as $p) {
        $nPag++;
        $txt = $p['json'] ? (string) json_encode(json_decode($p['corpo'], true), JSON_UNESCAPED_UNICODE) : qTextoVisivel($p['corpo']);
        foreach (qSemAcento($txt) as $a) {
            $achadosPag[$a . ' | ' . $p['arq'] . ' ' . $p['status']] = true;
        }
    }
    afirmar("acentuacao: varredura de $nPag respostas HTML/JSON da gestao (todas as telas e paginas de erro exercitadas) sem forma sem acento" . ($achadosPag !== [] ? ' (' . implode('; ', array_keys($achadosPag)) . ')' : ''), $achadosPag === [] && $nPag > 100);
    $comJson = count(array_filter($PAGINAS, static fn ($p) => $p['json']));
    afirmar("acentuacao: a varredura incluiu respostas JSON ($comJson) e paginas de erro (status 4xx/5xx)", $comJson >= 2 && count(array_filter($PAGINAS, static fn ($p) => $p['status'] >= 400)) >= 20);
} catch (Throwable $e) {
    afirmar('excecao nao esperada no teste: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getLine(), false);
} finally {
    if (isset($pdo)) {
        foreach (['qa_trg_upd', 'qa_trg_ins'] as $t) {
            try {
                $pdo->exec("DROP TRIGGER IF EXISTS $t");
            } catch (Throwable $e) {
            }
        }
    }
    gtDestruirAmbiente($banco, $storage);
    @unlink($log);
}

exit(gtResumo('teste_gestao_qa_correcoes'));
