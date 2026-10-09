<?php

/**
 * Gestao Totem F4 (QA ADVERSARIAL INDEPENDENTE, 2026-10-09): complementa
 * teste_gestao_ordens.php (nao duplica) com php-cgi REAL, banco do totem + banco externo
 * QA descartaveis `qa_qr_exclusivo_<hex>` (qa_gestao_oc_infra.php) e STORAGE temporario.
 * NUNCA udlog_totem nem o banco externo real.
 *
 * Cobre: sessoes admin/usuario/anonimo/rebaixado/inativado; POST sem Origin/CSRF;
 * PDF (arquivo vazio, exatamente 5 MiB e 5 MiB + 1, caminho absoluto/NUL/barra invertida,
 * Content-Length = corpo, auditoria PENDENTE durante a leitura); CAS com DOIS PROCESSOS
 * concorrentes na mesma OC (trigger de espera) e mesmo numero em dois clientes;
 * XSS em motorista/CNH/placa/CNPJ da transportadora; paginacao 24/25/26/50/51;
 * nada grava em GET; sem codigo/token_api.
 *
 * Uso: php tests/manual/teste_gestao_qa_ordens.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';
require_once __DIR__ . '/qa_gestao_oc_infra.php';
require_once __DIR__ . '/_fixtures_talent.php';

date_default_timezone_set('America/Sao_Paulo');

// ----------------------------------------------------------------------
// Modo worker: uma requisicao php-cgi disparada em hora marcada (concorrencia real)
// ----------------------------------------------------------------------
if (($argv[1] ?? '') === '--worker') {
    $o = json_decode((string) base64_decode((string) $argv[2]), true, 16, JSON_THROW_ON_ERROR);
    $inicio = (float) $o['inicio'];
    unset($o['inicio']);
    while (microtime(true) < $inicio) {
        usleep(500);
    }
    $r = gtChamar($o);
    echo json_encode(['status' => $r['status'], 'loc' => gtCabecalho($r, 'location')]);
    exit(0);
}

$amb = null;
$storage = null;
$log = gtNovoLogCgi();
@unlink($log);

function gqIds(string $corpo): array
{
    preg_match_all('/<tr class="gestao-tabela__linha" data-id-ordem="(\d+)"/', $corpo, $m);

    return array_map('intval', $m[1]);
}

function gqLoc(array $r): string
{
    return (string) gtCabecalho($r, 'location');
}

try {
    $amb = ogCriarAmbiente();
    $pdo = $amb['totem'];
    $ext = $amb['externo'];
    $bancoExt = $amb['banco_externo'];
    if (!preg_match('/^qa_qr_exclusivo_[a-f0-9]{8}$/', $amb['banco_totem']) || !preg_match('/^qa_qr_exclusivo_[a-f0-9]{8}$/', $bancoExt)) {
        throw new RuntimeException('banco nao e QA');
    }
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_' . bin2hex(random_bytes(6));
    mkdir($storage . '/ordens_coleta', 0750, true);
    putenv('QA_QR_FORCE_DB_NAME=' . $amb['banco_totem']);
    putenv('QA_QR_FORCE_STORAGE=' . $storage);
    putenv('QA_QR_FORCE_EXT_DB_NAME=' . $bancoExt);

    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idUsu = gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    $idReb = gtSemear($pdo, 'rita.rebaixada', 'admin', GT_SENHA_BOA, false, true, 'Rita Rebaixada');
    $idIna = gtSemear($pdo, 'ivo.inativado', 'usuario', GT_SENHA_BOA, false, true, 'Ivo Inativado');

    $H = ['logErro' => $log, 'cabecalhos' => ['QA_QR_FORCE_EXT_DB_NAME' => $bancoExt]];
    $req = static fn (array $o): array => gtChamar(array_merge($H, $o, ['cabecalhos' => ($o['cabecalhos'] ?? []) + $H['cabecalhos']]));
    $ck = static fn (?array $l): array => $l !== null && $l['sid'] !== null ? ['cookies' => ['gestao_sid' => $l['sid']]] : [];
    $get = static fn (string $arq, ?array $l, array $q = [], array $x = []): array => $req(array_merge(['arquivo' => $arq, 'query' => $q], $ck($l), $x));
    $post = static fn (string $arq, ?array $l, array $form, array $x = []): array => $req(array_merge(['arquivo' => $arq, 'metodo' => 'POST', 'form' => $form + ($l !== null ? ['csrf_token' => (string) $l['csrf']] : [])], $ck($l), $x));

    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => '192.0.2.71']);
    $lUsu = gtLogin('carla.usuario', GT_SENHA_BOA, ['ip' => '192.0.2.72']);
    $lReb = gtLogin('rita.rebaixada', GT_SENHA_BOA, ['ip' => '192.0.2.73']);
    $lIna = gtLogin('ivo.inativado', GT_SENHA_BOA, ['ip' => '192.0.2.74']);
    afirmar('sessoes abertas (admin, usuario, futura rebaixada, futuro inativado) com CSRF', $lAdm['csrf'] !== null && $lUsu['csrf'] !== null && $lReb['csrf'] !== null && $lIna['csrf'] !== null);

    $ago = static fn (int $d): string => date('Y-m-d H:i:s', time() - $d * 86400);
    $cnpjA = '71111111000111';
    $cnpjB = '72222222000122';
    $cA = ogCliente($ext, $cnpjA, 'ALFA QA SA');
    $cB = ogCliente($ext, $cnpjB, 'BETA QA SA');
    $stOc = static fn (int $id): array => gtLinhas($ext, 'SELECT status, inativada_em FROM tb_ordens_coleta WHERE id = :i', ['i' => $id])[0];
    $nAudit = static fn (): int => (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao LIKE 'OC\\_%'");

    // ------------------------------------------------------------------
    // 1. Sessoes: anonimo / rebaixado / inativado / sem Origin
    // ------------------------------------------------------------------
    $oS1 = ogOrdem($ext, $cA, 'S-1', 'ATIVA', $ago(2));
    $oS2 = ogOrdem($ext, $cA, 'S-2', 'ATIVA', $ago(2));
    $f = static fn (int $id): array => ['id_ordem' => (string) $id, 'acao' => 'inativar', 'status_visto' => 'ATIVA'];
    $a0 = $nAudit();
    $rAn = $post('ordem-status.php', null, $f($oS1));
    $rAnP = $post('ordem-pdf.php', null, ['id_ordem' => (string) $oS1]);
    afirmar('anonimo: POST ordem-status e ordem-pdf nao executam nada (sem 2xx de PDF, OC intacta, sem auditoria)', in_array($rAn['status'], [302, 401, 403], true) && in_array($rAnP['status'], [302, 401, 403], true) && !str_contains($rAnP['cru'], '%PDF') && $stOc($oS1)['status'] === 'ATIVA' && $nAudit() === $a0);
    $rSemOrigin = $post('ordem-status.php', $lUsu, $f($oS1), ['origin' => null, 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']]);
    afirmar('POST sem Origin mas Sec-Fetch-Site=cross-site (CSRF valido) => 403 e OC intacta', $rSemOrigin['status'] === 403 && $stOc($oS1)['status'] === 'ATIVA');
    $rSemTudo = $req(['arquivo' => 'ordem-status.php', 'metodo' => 'POST', 'origin' => null, 'form' => $f($oS1)] + $ck($lUsu));
    afirmar('POST sem Origin e SEM CSRF => 403 e OC intacta', $rSemTudo['status'] === 403 && $stOc($oS1)['status'] === 'ATIVA');
    $rSemOriginPdf = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oS1], ['origin' => null, 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']]);
    afirmar('POST ordem-pdf cross-site => 403 sem byte de PDF', $rSemOriginPdf['status'] === 403 && !str_contains($rSemOriginPdf['cru'], '%PDF'));
    echo "  INFO - desenho F1 (AuthGestao::origemPermitida): sem Origin e sem Sec-Fetch-Site, o token CSRF decide (nao testado como defeito)
";

    // rebaixada: admin -> usuario com a sessao viva
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'usuario' WHERE id_usuario = :i")->execute(['i' => $idReb]);
    $rRebLogs = $get('logs.php', $lReb);
    $rRebUsu = $get('usuarios.php', $lReb);
    $rRebOrd = $get('ordens.php', $lReb);
    afirmar('rebaixado (admin->usuario com sessao viva): logs.php e usuarios.php deixam de abrir (nao 200) e ordens.php segue abrindo', $rRebLogs['status'] !== 200 && $rRebUsu['status'] !== 200 && $rRebOrd['status'] === 200);
    $rRebAct = $post('ordem-status.php', $lReb, $f($oS2));
    afirmar('rebaixado: pode inativar OC como usuario (decisao D: usuario e admin ativam/inativam) e a auditoria registra o id dele', $stOc($oS2)['status'] === 'INATIVA' && (int) gtEscalar($pdo, "SELECT id_usuario FROM tb_gestao_auditoria WHERE acao='OC_INATIVAR' AND alvo_id = :i", ['i' => $oS2]) === $idReb);
    // inativado
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idIna]);
    $a1 = $nAudit();
    $rInaG = $get('ordens.php', $lIna);
    $rInaP = $post('ordem-status.php', $lIna, $f($oS1));
    $rInaPdf = $post('ordem-pdf.php', $lIna, ['id_ordem' => (string) $oS1]);
    afirmar('usuario INATIVADO com sessao viva: lista nao abre, POST de status e PDF nao executam, OC intacta, sem auditoria', $rInaG['status'] !== 200 && !in_array($rInaP['status'], [200], true) && $stOc($oS1)['status'] === 'ATIVA' && !str_contains($rInaPdf['cru'], '%PDF') && $nAudit() === $a1);

    // ------------------------------------------------------------------
    // 2. PDF adicional
    // ------------------------------------------------------------------
    $pdfOk = "%PDF-1.4\n" . random_bytes(200) . "\n%%EOF\n";
    $gravar = static function (string $cnpj, string $numero, string $bytes, ?string $rel = null, ?string $sha = null, bool $arquivo = true) use ($ext, $storage): void {
        $rel ??= $cnpj . '/' . $numero . '_20260101000000.pdf';
        if ($arquivo) {
            @mkdir($storage . '/ordens_coleta/' . $cnpj, 0750, true);
            file_put_contents($storage . '/ordens_coleta/' . $cnpj . '/' . $numero . '_20260101000000.pdf', $bytes);
        }
        $ext->prepare('INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256) VALUES (:c, :n, :p, :t, :h)')
            ->execute(['c' => $cnpj, 'n' => $numero, 'p' => $rel, 't' => strlen($bytes), 'h' => $sha ?? hash('sha256', $bytes)]);
    };
    $caso = [];
    $caso['vazio'] = ogOrdem($ext, $cA, 'PV-VAZIO', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-VAZIO', '');
    $caso['absoluto'] = ogOrdem($ext, $cA, 'PV-ABS', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-ABS', $pdfOk);
    $ext->prepare('UPDATE tb_ordem_coleta_arquivos SET caminho_relativo = :p WHERE numero_ordem_coleta = :n')->execute(['p' => str_replace('\\', '/', $storage) . '/ordens_coleta/' . $cnpjA . '/PV-ABS_20260101000000.pdf', 'n' => 'PV-ABS']);
    $caso['nul'] = ogOrdem($ext, $cA, 'PV-NUL', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-NUL', $pdfOk, $cnpjA . "/PV-NUL_20260101000000.pdf\0.txt", hash('sha256', $pdfOk), true);
    $caso['barra'] = ogOrdem($ext, $cA, 'PV-BS', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-BS', $pdfOk, $cnpjA . '\\PV-BS_20260101000000.pdf', hash('sha256', $pdfOk), true);
    $caso['up'] = ogOrdem($ext, $cA, 'PV-UP', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-UP', $pdfOk, '../' . $cnpjA . '/PV-UP_20260101000000.pdf');
    $caso['dir'] = ogOrdem($ext, $cA, 'PV-DIR', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-DIR', $pdfOk, $cnpjA, hash('sha256', $pdfOk), false);
    $caso['shaMaiusc'] = ogOrdem($ext, $cA, 'PV-SHAUP', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-SHAUP', $pdfOk, null, strtoupper(hash('sha256', $pdfOk)));
    $corpos = [];
    foreach ($caso as $rot => $idc) {
        $nA = $nAudit();
        $r = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $idc]);
        $corpos[$rot] = $r['corpo'];
        $ult = gtLinhas($pdo, "SELECT resultado FROM tb_gestao_auditoria WHERE acao='OC_VER_PDF' AND alvo_id = :i ORDER BY id_auditoria DESC LIMIT 1", ['i' => $idc]);
        afirmar("PDF registro adulterado/invalido [$rot]: nenhum byte de PDF, sem Content-Type pdf, auditoria nao fica PENDENTE nem OK", !str_contains($r['cru'], '%PDF') && gtCabecalho($r, 'content-type') !== 'application/pdf' && $r['status'] !== 200 && ($ult[0]['resultado'] ?? '') !== 'OK' && ($ult[0]['resultado'] ?? '') !== 'PENDENTE' && $nAudit() === $nA + 1);
    }
    $semAusente = array_diff_key($corpos, ['dir' => 1]);
    afirmar('PDF invalido: mesma resposta 500 uniforme para vazio/absoluto/NUL/barra invertida/../ /sha maiusculo (nao revela o motivo)', count(array_unique(array_map('md5', $semAusente))) === 1);

    // limite exato de 5 MiB
    $exato = '%PDF-' . str_repeat('y', 5 * 1024 * 1024 - 5);
    $oExato = ogOrdem($ext, $cA, 'PV-5M', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-5M', $exato);
    $rEx = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oExato]);
    afirmar('PDF de exatamente 5 MiB: 200, corpo identico e Content-Length = bytes do corpo', $rEx['status'] === 200 && strlen($rEx['corpo']) === 5 * 1024 * 1024 && $rEx['corpo'] === $exato && gtCabecalho($rEx, 'content-length') === (string) strlen($exato));
    $mais = $exato . 'z';
    $oMais = ogOrdem($ext, $cA, 'PV-5M1', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-5M1', $mais);
    $rMais = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oMais]);
    afirmar('PDF de 5 MiB + 1 byte: recusado sem nenhum byte', $rMais['status'] === 500 && !str_contains($rMais['cru'], '%PDF'));
    $oPeq = ogOrdem($ext, $cA, 'PV-OK', 'ATIVA', $ago(1));
    $gravar($cnpjA, 'PV-OK', $pdfOk);
    $rPeq = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oPeq]);
    afirmar('PDF pequeno com bytes CR/LF/NUL: Content-Length = corpo e corpo identico', $rPeq['status'] === 200 && $rPeq['corpo'] === $pdfOk && gtCabecalho($rPeq, 'content-length') === (string) strlen($pdfOk));
    // OC com caminho valido apontando para PDF de OUTRO cliente: o que o servidor faz (informativo)
    $oCruz = ogOrdem($ext, $cB, 'PV-CRUZ', 'ATIVA', $ago(1));
    file_put_contents($storage . '/ordens_coleta/' . $cnpjA . '/PV-ALVO_20260101000000.pdf', $pdfOk);
    $gravar($cnpjB, 'PV-CRUZ', 'x', $cnpjA . '/PV-ALVO_20260101000000.pdf', hash('sha256', $pdfOk), false);
    $rCruz = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oCruz]);
    echo '  INFO - registro do cliente B apontando (por adulteracao do banco externo) para PDF do cliente A com sha do conteudo: HTTP ' . $rCruz['status'] . (str_contains($rCruz['cru'], '%PDF') ? ' (SERVIDO)' : ' (nao servido)') . "\n";

    // auditoria PENDENTE durante a escrita da resposta: gatilho de sonda em UPDATE da auditoria
    $pdo->exec('CREATE TABLE probe_fecha (id INT AUTO_INCREMENT PRIMARY KEY, antes VARCHAR(20) NOT NULL, depois VARCHAR(20) NOT NULL, acao VARCHAR(40) NOT NULL)');
    $pdo->exec("CREATE TRIGGER trg_probe_fecha BEFORE UPDATE ON tb_gestao_auditoria FOR EACH ROW INSERT INTO probe_fecha (antes, depois, acao) VALUES (OLD.resultado, NEW.resultado, OLD.acao)");
    $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oPeq]);
    $sonda = gtLinhas($pdo, "SELECT antes, depois FROM probe_fecha WHERE acao = 'OC_VER_PDF'");
    afirmar('PDF servido: a linha de auditoria estava PENDENTE e so foi fechada (PENDENTE->OK) depois', count($sonda) === 1 && $sonda[0]['antes'] === 'PENDENTE' && $sonda[0]['depois'] === 'OK');
    $pdo->exec('DROP TRIGGER trg_probe_fecha');
    $pdo->exec('DROP TABLE probe_fecha');

    // ------------------------------------------------------------------
    // 3. CAS com dois processos concorrentes
    // ------------------------------------------------------------------
    $par = static function (array $reqs) use ($H): array {
        $inicio = microtime(true) + 2.0;
        $procs = [];
        foreach ($reqs as $i => $o) {
            $o = array_merge($H, $o, ['cabecalhos' => ($o['cabecalhos'] ?? []) + $H['cabecalhos'], 'inicio' => $inicio]);
            $procs[$i] = proc_open([PHP_BINARY, __FILE__, '--worker', base64_encode(json_encode($o))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $procs[$i] = [$procs[$i], $pipes];
        }
        $out = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $out[$i] = json_decode((string) stream_get_contents($pipes[1]), true);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }

        return $out;
    };
    $ext->exec("CREATE TRIGGER trg_espera BEFORE UPDATE ON tb_ordens_coleta FOR EACH ROW BEGIN IF OLD.numero_ordem_coleta LIKE 'RACE-%' THEN DO SLEEP(0.7); END IF; END");
    $okTodas = true;
    $detalhesRound = [];
    for ($rodada = 1; $rodada <= 3; $rodada++) {
        $oR = ogOrdem($ext, $cA, 'RACE-' . $rodada, 'ATIVA', $ago(2));
        $oRb = ogOrdem($ext, $cB, 'RACE-' . $rodada, 'ATIVA', $ago(2)); // mesmo numero em outro cliente
        $rs = $par([
            ['arquivo' => 'ordem-status.php', 'metodo' => 'POST', 'form' => $f($oR) + ['csrf_token' => (string) $lUsu['csrf']], 'cookies' => ['gestao_sid' => $lUsu['sid']], 'ip' => '192.0.2.72'],
            ['arquivo' => 'ordem-status.php', 'metodo' => 'POST', 'form' => $f($oR) + ['csrf_token' => (string) $lAdm['csrf']], 'cookies' => ['gestao_sid' => $lAdm['sid']], 'ip' => '192.0.2.71'],
        ]);
        $aud = gtLinhas($pdo, "SELECT resultado FROM tb_gestao_auditoria WHERE acao='OC_INATIVAR' AND alvo_id = :i ORDER BY id_auditoria", ['i' => $oR]);
        $res = array_column($aud, 'resultado');
        sort($res);
        $msgs = array_map(static fn ($x) => (string) (parse_url((string) ($x['loc'] ?? ''), PHP_URL_QUERY) ?? ''), $rs);
        $ok = $res === ['OK', 'SEM_EFEITO'] && $stOc($oR)['status'] === 'INATIVA' && $stOc($oR)['inativada_em'] !== null && $stOc($oRb)['status'] === 'ATIVA' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0 && count(array_filter($msgs, static fn ($q) => str_contains($q, 'msg=oc_inativada'))) === 1;
        $detalhesRound[] = json_encode($res) . ' ' . implode(' | ', $msgs);
        $okTodas = $okTodas && $ok;
    }
    afirmar('CAS 2 processos concorrentes inativando a MESMA OC (3 rodadas, UPDATE retido 0,7 s): exatamente 1 OK + 1 SEM_EFEITO, 1 flash oc_inativada, outra OC de mesmo numero em outro cliente intacta, nenhuma auditoria PENDENTE [' . implode(' // ', $detalhesRound) . ']', $okTodas);
    // ativar concorrente
    $oRA = ogOrdem($ext, $cA, 'RACE-A', 'INATIVA', $ago(9), $ago(3));
    $fa = ['id_ordem' => (string) $oRA, 'acao' => 'ativar', 'status_visto' => 'INATIVA'];
    $rs2 = $par([
        ['arquivo' => 'ordem-status.php', 'metodo' => 'POST', 'form' => $fa + ['csrf_token' => (string) $lUsu['csrf']], 'cookies' => ['gestao_sid' => $lUsu['sid']], 'ip' => '192.0.2.72'],
        ['arquivo' => 'ordem-status.php', 'metodo' => 'POST', 'form' => $fa + ['csrf_token' => (string) $lAdm['csrf']], 'cookies' => ['gestao_sid' => $lAdm['sid']], 'ip' => '192.0.2.71'],
    ]);
    $res2 = array_column(gtLinhas($pdo, "SELECT resultado FROM tb_gestao_auditoria WHERE acao='OC_ATIVAR' AND alvo_id = :i", ['i' => $oRA]), 'resultado');
    sort($res2);
    afirmar('CAS 2 processos concorrentes ATIVANDO a mesma OC: 1 OK + 1 SEM_EFEITO, status ATIVA e inativada_em NULL', $res2 === ['OK', 'SEM_EFEITO'] && $stOc($oRA)['status'] === 'ATIVA' && $stOc($oRA)['inativada_em'] === null);
    // inativar x ativar com a tela ja desatualizada (visto distinto) na mesma OC
    $oRM = ogOrdem($ext, $cA, 'RACE-M', 'ATIVA', $ago(2));
    $rs3 = $par([
        ['arquivo' => 'ordem-status.php', 'metodo' => 'POST', 'form' => $f($oRM) + ['csrf_token' => (string) $lUsu['csrf']], 'cookies' => ['gestao_sid' => $lUsu['sid']], 'ip' => '192.0.2.72'],
        ['arquivo' => 'ordem-status.php', 'metodo' => 'POST', 'form' => ['id_ordem' => (string) $oRM, 'acao' => 'ativar', 'status_visto' => 'INATIVA', 'csrf_token' => (string) $lAdm['csrf']], 'cookies' => ['gestao_sid' => $lAdm['sid']], 'ip' => '192.0.2.71'],
    ]);
    $linhasM = gtLinhas($pdo, "SELECT acao, resultado FROM tb_gestao_auditoria WHERE alvo_id = :i AND acao IN ('OC_INATIVAR','OC_ATIVAR')", ['i' => $oRM]);
    $oks = array_filter($linhasM, static fn ($l) => $l['resultado'] === 'OK');
    afirmar('inativar x ativar simultaneos com tela antiga: nenhuma auditoria PENDENTE, no maximo 1 efetivado e o estado final bate com a trilha', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado='PENDENTE'") === 0 && count($oks) === 1 && $stOc($oRM)['status'] === 'INATIVA');
    $ext->exec('DROP TRIGGER trg_espera');

    // ------------------------------------------------------------------
    // 4. XSS em motorista, CNH, placa e CNPJ da transportadora
    // ------------------------------------------------------------------
    $oXss = ogOrdem($ext, $cA, 'XSS-1', 'ATIVA', $ago(1), null, ['motorista_nome_previsto' => '<svg onload=alert(1)>', 'cnh_prevista' => '"><script>x</script>', 'placa_prevista' => "<i>'&", 'transportadora_nome' => '<iframe src=//e>', 'transportadora_cnpj' => '<b>x</b>']);
    $dX = $get('ordem.php', $lUsu, ['id' => (string) $oXss]);
    $lX = $get('ordens.php', $lUsu, ['numero' => 'XSS-1']);
    $limpo = static function (string $c): bool {
        foreach (['<svg onload', '<script>x', '<i>\'', '<iframe', '<b>x</b>', '"><script'] as $p) {
            if (str_contains($c, $p)) {
                return false;
            }
        }

        return true;
    };
    afirmar('XSS no detalhe: motorista, CNH, placa, transportadora e CNPJ da transportadora hostis saem escapados (nenhuma tag crua)', $dX['status'] === 200 && $limpo($dX['corpo']) && str_contains($dX['corpo'], '&lt;svg onload=alert(1)&gt;'));
    afirmar('XSS na lista: transportadora/CNPJ hostis escapados', $lX['status'] === 200 && $limpo($lX['corpo']));
    $oXq = ogOrdem($ext, $cA, 'XSS-2', 'ATIVA', $ago(1));
    afirmar('XSS em retorno/msg/confirmar de ordem.php: parametros refletidos nao viram HTML', $limpo($get('ordem.php', $lUsu, ['id' => (string) $oXss, 'confirmar' => '<script>x</script>', 'msg' => '<svg onload=alert(1)>', 'aba' => '<script>x</script>', 'numero' => '<iframe src=//e>'])['corpo']));

    // ------------------------------------------------------------------
    // 5. Paginacao 24 / 25 / 26 / 50 / 51 (cliente dedicado por tamanho)
    // ------------------------------------------------------------------
    foreach ([24 => 1, 25 => 1, 26 => 2, 50 => 2, 51 => 3] as $n => $paginas) {
        $c = ogCliente($ext, '8' . str_pad((string) $n, 13, '0', STR_PAD_LEFT), 'PAG ' . $n);
        $ext->exec('START TRANSACTION');
        for ($i = 1; $i <= $n; $i++) {
            ogOrdem($ext, $c, sprintf('PG%d-%03d', $n, $i), 'ATIVA', date('Y-m-d H:i:s', time() - $i * 60));
        }
        $ext->exec('COMMIT');
        $total = 0;
        $vistos = [];
        $ok = true;
        for ($p = 1; $p <= $paginas; $p++) {
            $r = $get('ordens.php', $lUsu, ['cliente' => (string) $c, 'pagina' => (string) $p]);
            $ids = gqIds($r['corpo']);
            $esperado = min(25, $n - ($p - 1) * 25);
            $ultima = $p === $paginas;
            $ok = $ok && $r['status'] === 200 && count($ids) === $esperado && array_intersect($vistos, $ids) === []
                && str_contains($r['corpo'], "Página {$p} de {$paginas}")
                && ($ultima ? str_contains($r['corpo'], 'id="pag-proxima" aria-disabled="true"') : str_contains($r['corpo'], 'id="pag-proxima" href="'));
            $vistos = array_merge($vistos, $ids);
            $total += count($ids);
        }
        $alem = $get('ordens.php', $lUsu, ['cliente' => (string) $c, 'pagina' => (string) ($paginas + 1)]);
        afirmar("paginacao com $n ordens: $paginas pagina(s), 25 por pagina, sem repeticao, soma = $n, Proxima so enquanto houver; pagina " . ($paginas + 1) . ' => 302 para a ultima', $ok && $total === $n && $alem['status'] === 302);
    }

    // ------------------------------------------------------------------
    // 6. Nada grava em GET; sem codigo/token_api
    // ------------------------------------------------------------------
    $pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_QAO_' . bin2hex(random_bytes(3)), 1);
    $codigoTotem = (string) gtEscalar($pdo, 'SELECT codigo FROM tb_totem WHERE id_totem = :i', ['i' => $idTotem]);
    $tokenTotem = (string) gtEscalar($pdo, 'SELECT token_api FROM tb_totem WHERE id_totem = :i', ['i' => $idTotem]);
    $snap = static fn (): string => md5(serialize([
        gtLinhas($ext, 'SELECT id, status, inativada_em, atualizado_em FROM tb_ordens_coleta ORDER BY id'),
        gtLinhas($ext, 'SELECT * FROM tb_ordem_coleta_arquivos ORDER BY id'),
        gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria ORDER BY id_auditoria'),
        gtLinhas($pdo, 'SELECT * FROM tb_ordem_coleta_pendente_baixa ORDER BY id'),
    ]));
    $antes = $snap();
    $todos = [];
    foreach (['ordens.php' => [], 'ordem.php' => ['id' => (string) $oXss]] as $arq => $q) {
        foreach ([$lUsu, $lAdm] as $l) {
            foreach ([[], ['aba' => 'inativas'], ['aba' => 'baixas'], ['aba' => 'ativas_15d'], ['acao' => 'inativar', 'status_visto' => 'ATIVA', 'id_ordem' => (string) $oXss, 'confirmar' => '1'], ['id_baixa' => '1', 'id_ordem' => (string) $oXss]] as $extra) {
                $todos[] = $get($arq, $l, $q + $extra);
            }
        }
    }
    foreach (['ordem-status.php', 'ordem-baixa.php', 'ordem-pdf.php'] as $arq) {
        $todos[] = $get($arq, $lUsu, ['id_ordem' => (string) $oXss, 'acao' => 'inativar', 'status_visto' => 'ATIVA', 'id_baixa' => '1']);
        $r405 = end($todos);
        afirmar("GET em $arq (escrita) => 405, nada gravado", $r405['status'] === 405);
    }
    afirmar('nada grava em GET (ordens, OC anexos, auditoria, baixas identicos antes/depois de ' . count($todos) . ' GETs, incl. GET com parametros de escrita)', $snap() === $antes);
    $vaza = array_filter($todos, static fn ($r) => str_contains($r['cru'], $codigoTotem) || str_contains($r['cru'], $tokenTotem) || str_contains($r['cru'], 'token_api'));
    afirmar('nenhuma resposta das telas/acoes de OC contem codigo, token_api do totem ou a palavra token_api', $vaza === []);
    $rd = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oPeq]);
    afirmar('resposta do PDF nao carrega CNPJ, numero da OC, codigo/token do totem nos cabecalhos', !str_contains(strtolower(implode('|', array_map(static fn ($v) => implode(',', $v), $rd['cabecalhos']))), strtolower($cnpjA)) && !str_contains(implode('|', array_map(static fn ($v) => implode(',', $v), $rd['cabecalhos'])), 'PV-OK') && !str_contains($rd['cru'], substr($tokenTotem, 0, 12)));
    afirmar('nenhuma auditoria ficou PENDENTE ao final', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
} catch (Throwable $e) {
    echo 'EXCECAO FATAL: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $GLOBALS['gtFalhas']++;
} finally {
    ogLimpar($amb);
    gtDestruirAmbiente(null, $storage);
    @unlink($log);
}

exit(gtResumo('teste_gestao_qa_ordens'));
