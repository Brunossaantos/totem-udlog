<?php

/**
 * Gestao Totem, F4b/F4c/F4d (TELAS DE ORDENS DE COLETA, 2026-10-08): paginas REAIS
 * `ordens.php`, `ordem.php`, `ordem-status.php`, `ordem-baixa.php` e `ordem-pdf.php` por
 * php-cgi, contra DOIS bancos QA descartaveis `qa_qr_exclusivo_<hex>` (totem + externo,
 * ver qa_gestao_oc_infra.php) e STORAGE temporario. NUNCA udlog_totem nem o banco
 * externo real; sem rede.
 *
 * Cobre: RBAC, metodos, CSRF/Origin, ids e filtros hostis, abas e contagens, ordenacao,
 * paginacao/teto, XSS, ativar/inativar (CAS, status_visto, confirmacao em dois passos,
 * auditoria PENDENTE ANTES do UPDATE, fechamento OK/SEM_EFEITO/ERRO, sem PII), banco
 * externo fora do ar, baixas pendentes, PDF (cabecalhos, integridade, auditoria antes
 * do primeiro byte) e varredura de fonte.
 *
 * Uso: php tests/manual/teste_gestao_ordens.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';
require_once __DIR__ . '/qa_gestao_oc_infra.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Controller\GestaoContexto;
use App\Dao\AtendimentoDao;
use App\Dao\OrdemColetaGestaoDao;
use App\Dao\OrdemColetaPendenteBaixaDao;

date_default_timezone_set('America/Sao_Paulo');

$amb = null;
$storage = null;
$log = gtNovoLogCgi();
@unlink($log);
$raiz = dirname(__DIR__, 2);

function semInlineOrdens(string $html): bool
{
    if (preg_match('/<style\b/i', $html) === 1 || preg_match('/\sstyle\s*=/i', $html) === 1 || preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html) === 1) {
        return false;
    }
    if (preg_match_all('/<script\b([^>]*)>/i', $html, $m) > 0) {
        foreach ($m[1] as $a) {
            if (preg_match('/\bsrc="\/gestao\/assets\/gestao\.js\?v=\d+"/', $a) !== 1) {
                return false;
            }
        }
    }

    return true;
}

/** @return list<int> data-id-ordem das linhas, na ordem */
function idsOrdens(string $corpo): array
{
    preg_match_all('/<tr class="gestao-tabela__linha" data-id-ordem="(\d+)" data-status="(?:ativa|inativa)" data-idade="(?:atencao|normal)"/', $corpo, $m);

    return array_map('intval', $m[1]);
}

/** @return list<int> data-id-baixa */
function idsBaixas(string $corpo): array
{
    preg_match_all('/<tr class="gestao-tabela__linha" data-id-baixa="(\d+)" data-resolvida="[01]"/', $corpo, $m);

    return array_map('intval', $m[1]);
}

function locOrdens(array $r): string
{
    return (string) gtCabecalho($r, 'location');
}

/** @return array<string,mixed> query decodificada de um Location */
function queryDe(string $loc): array
{
    parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);

    return $q;
}

/** contagem (texto) do link da aba */
function contagemAba(string $corpo, string $idAba): ?string
{
    if (preg_match('/id="' . preg_quote($idAba, '/') . '"[^>]*>.*?<span class="gestao-aba__contagem" aria-hidden="true">([^<]*)<\/span>/s', $corpo, $m) === 1) {
        return $m[1];
    }

    return null;
}

try {
    $amb = ogCriarAmbiente();
    $pdo = $amb['totem'];
    $ext = $amb['externo'];
    $bancoExt = $amb['banco_externo'];
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_' . bin2hex(random_bytes(6));
    mkdir($storage, 0700, true);
    putenv('QA_QR_FORCE_DB_NAME=' . $amb['banco_totem']);
    putenv('QA_QR_FORCE_STORAGE=' . $storage);
    mkdir($storage . '/ordens_coleta', 0750, true);

    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idUsu = gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    $idUsuPend = gtSemear($pdo, 'paulo.pendente', 'usuario', GT_SENHA_BOA, true, true, 'Paulo Pendente');

    $agoraStr = static fn (int $segundosAtras): string => date('Y-m-d H:i:s', time() - $segundosAtras);
    $dias = static fn (int $d): string => $agoraStr($d * 86400);

    // ------------------------------------------------------------------
    // Semeadura
    // ------------------------------------------------------------------
    $cnpjA = '11111111000111';
    $cnpjB = '22222222000122';
    $cnpjC = '33333333000133';
    $cnpjX = '44444444000144';
    $cnpjT = '55555555000155';
    $razaoA = 'ACME LOGISTICA SA';
    $razaoX = '<b>"X"&\' Hostil</b>';
    $cA = ogCliente($ext, $cnpjA, $razaoA);
    $cB = ogCliente($ext, $cnpjB, 'BETA TRANSPORTES LTDA');
    $cC = ogCliente($ext, $cnpjC, 'GAMA COMERCIO SA');
    $cX = ogCliente($ext, $cnpjX, $razaoX);
    $numHostil = '<script>alert(7)</script>OC';

    $oA1 = ogOrdem($ext, $cA, 'OC-1001', 'ATIVA', $dias(3), null, ['placa_prevista' => 'ABC1D23', 'motorista_nome_previsto' => 'JOAO DA SILVA', 'cnh_prevista' => '12345678901', 'transportadora_nome' => 'TRANSP RAPIDA', 'transportadora_cnpj' => $cnpjT]);
    $oA2 = ogOrdem($ext, $cA, 'OC-1002', 'ATIVA', $dias(20));
    $oB3 = ogOrdem($ext, $cB, 'OC-1003', 'ATIVA', $dias(5));
    $oB1 = ogOrdem($ext, $cB, 'OC-1001', 'ATIVA', $dias(1));
    $oAnd = ogOrdem($ext, $cA, 'OC-AND', 'ATIVA', $dias(2));
    $oX = ogOrdem($ext, $cX, $numHostil, 'ATIVA', $dias(1), null, ['transportadora_nome' => '<img src=x onerror=alert(8)>']);
    $oI1 = ogOrdem($ext, $cA, 'OC-2001', 'INATIVA', $dias(10), $dias(3));
    $oI2 = ogOrdem($ext, $cA, 'OC-2002', 'INATIVA', $dias(30), $dias(20));
    $oBx = ogOrdem($ext, $cA, 'OC-BX', 'INATIVA', $dias(8), $dias(1));
    $oIDentro = ogOrdem($ext, $cA, 'OC-INSEMPDF', 'INATIVA', $dias(6), $dias(2));
    $oSem = ogOrdem($ext, $cB, 'OC-SEMPDF', 'ATIVA', $dias(2));
    $oConf = ogOrdem($ext, $cA, 'OC-CONF', 'ATIVA', $dias(2));
    $oTrig = ogOrdem($ext, $cA, 'OC-TRIG', 'ATIVA', $dias(2));
    $oFail = ogOrdem($ext, $cA, 'OC-FAIL', 'ATIVA', $dias(2));
    $oAud = ogOrdem($ext, $cA, 'OC-AUD', 'ATIVA', $dias(2));
    $oDown = ogOrdem($ext, $cA, 'OC-DOWN', 'ATIVA', $dias(2));
    $oVis = ogOrdem($ext, $cA, 'OC-VIS', 'ATIVA', $dias(2));
    $ext->exec('START TRANSACTION');
    for ($i = 1; $i <= 60; $i++) {
        ogOrdem($ext, $cC, sprintf('PG-%04d', $i), 'ATIVA', $agoraStr($i * 3600));
    }
    $ext->exec('COMMIT');

    // arquivos de PDF
    $pdfBytes = "%PDF-1.4\n" . "1 0 obj\r\n<< /Type /Catalog >>\nendobj\n" . random_bytes(300) . "\r\n\n%%EOF\n";
    $gravarPdf = static function (string $cnpj, string $numero, string $bytes, ?string $sha = null, ?string $relativo = null) use ($ext, $storage): string {
        $rel = $relativo ?? ($cnpj . '/' . $numero . '_20260101000000.pdf');
        if ($relativo === null) {
            @mkdir($storage . '/ordens_coleta/' . $cnpj, 0750, true);
            file_put_contents($storage . '/ordens_coleta/' . $rel, $bytes);
        }
        $ext->prepare('INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256) VALUES (:c, :n, :p, :t, :h)')
            ->execute(['c' => $cnpj, 'n' => $numero, 'p' => $rel, 't' => strlen($bytes), 'h' => $sha ?? hash('sha256', $bytes)]);

        return $rel;
    };
    $gravarPdf($cnpjA, 'OC-1001', $pdfBytes);
    $gravarPdf($cnpjA, 'OC-2001', $pdfBytes);
    $gravarPdf($cnpjB, 'OC-1001', $pdfBytes);
    $oPAus = ogOrdem($ext, $cA, 'P-AUS', 'ATIVA', $dias(2));
    $gravarPdf($cnpjA, 'P-AUS', $pdfBytes, null, $cnpjA . '/P-AUS_20260101000000.pdf');
    $oPNao = ogOrdem($ext, $cA, 'P-NAOPDF', 'ATIVA', $dias(2));
    $gravarPdf($cnpjA, 'P-NAOPDF', 'GIF89a nao e pdf');
    $oPSha = ogOrdem($ext, $cA, 'P-SHA', 'ATIVA', $dias(2));
    $gravarPdf($cnpjA, 'P-SHA', $pdfBytes, str_repeat('a', 64));
    $oPBig = ogOrdem($ext, $cA, 'P-BIG', 'ATIVA', $dias(2));
    $gravarPdf($cnpjA, 'P-BIG', '%PDF-' . str_repeat('x', 5 * 1024 * 1024));
    $oPTrav = ogOrdem($ext, $cA, 'P-TRAV', 'ATIVA', $dias(2));
    $gravarPdf($cnpjA, 'P-TRAV', $pdfBytes, null, '../../fora/segredo_20260101000000.pdf');
    $oPTrav2 = ogOrdem($ext, $cA, 'P-TRAV2', 'ATIVA', $dias(2));
    $gravarPdf($cnpjA, 'P-TRAV2', $pdfBytes, null, $cnpjA . '/../' . $cnpjB . '/OC-1001_20260101000000.pdf');
    $oPSym = ogOrdem($ext, $cA, 'P-SYM', 'ATIVA', $dias(2));
    $simbolico = $storage . '/ordens_coleta/' . $cnpjA . '/P-SYM_20260101000000.pdf';
    file_put_contents($storage . '/alvo_fora.pdf', $pdfBytes);
    $symOk = @symlink($storage . '/alvo_fora.pdf', $simbolico);
    $gravarPdf($cnpjA, 'P-SYM', $pdfBytes, null, $cnpjA . '/P-SYM_20260101000000.pdf');
    // S4: registro adulterado: cnpj_cliente do cliente A, mas caminho (e sha256 VALIDO) do PDF do cliente B
    $oPPref = ogOrdem($ext, $cA, 'P-PREF', 'ATIVA', $dias(2));
    file_put_contents($storage . '/ordens_coleta/' . $cnpjB . '/P-PREF_20260101000000.pdf', $pdfBytes);
    $gravarPdf($cnpjA, 'P-PREF', $pdfBytes, null, $cnpjB . '/P-PREF_20260101000000.pdf');
    // PDF de OC INATIVA ha mais de 15 dias nao tem arquivo (apagado pelo cron): OC-2002 sem registro

    // totem + atendimentos (banco do totem)
    $pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_F4B_' . bin2hex(random_bytes(3)), 1);
    $codigoTotem = (string) gtEscalar($pdo, 'SELECT codigo FROM tb_totem WHERE id_totem = :i', ['i' => $idTotem]);
    $tokenTotem = (string) gtEscalar($pdo, 'SELECT token_api FROM tb_totem WHERE id_totem = :i', ['i' => $idTotem]);
    $atDao = new AtendimentoDao($pdo);
    $mkAt = static function (string $tipo, ?string $numero, ?string $cnpj, string $status = 'em_andamento') use ($pdo, $atDao, $idTotem): int {
        $id = $atDao->criar($idTotem, $tipo, 'T' . substr(bin2hex(random_bytes(4)), 0, 7));
        $pdo->prepare('UPDATE tb_atendimento SET ordem_coleta = :n, cliente_cnpj = :c, status = :s WHERE id_atendimento = :id')
            ->execute(['n' => $numero, 'c' => $cnpj, 's' => $status, 'id' => $id]);

        return $id;
    };
    $mkAt('expedicao', 'OC-AND', $cnpjA);
    $mkAt('expedicao', 'OC-AND', $cnpjA);
    $mkAt('expedicao', 'OC-BX', $cnpjA, 'concluido');
    $mkAt('expedicao', 'OC-CONF', $cnpjA);

    // baixas pendentes
    $baixaDao = new OrdemColetaPendenteBaixaDao($pdo);
    $mkBaixa = static function (?string $cnpj, string $numero, bool $resolvida = false) use ($pdo, $mkAt, $baixaDao): array {
        $idAt = $mkAt('expedicao', $numero, $cnpj, 'concluido');
        $baixaDao->registrar($idAt, $numero);
        $idB = (int) gtEscalar($pdo, 'SELECT id FROM tb_ordem_coleta_pendente_baixa WHERE id_atendimento = :a', ['a' => $idAt]);
        if ($resolvida) {
            $pdo->prepare('UPDATE tb_ordem_coleta_pendente_baixa SET resolvido_em = NOW() WHERE id = :i')->execute(['i' => $idB]);
        }

        return [$idB, $idAt];
    };
    [$bResolve] = $mkBaixa($cnpjA, 'OC-2001');
    [$bAtiva] = $mkBaixa($cnpjA, 'OC-1002');
    [$bSemCnpj] = $mkBaixa(null, 'OC-SEM-CLIENTE');
    [$bNaoExiste] = $mkBaixa($cnpjA, 'NAO-EXISTE-9');
    [$bHostil] = $mkBaixa($cnpjX, $numHostil);
    [$bFeita, $atFeita] = $mkBaixa($cnpjA, 'OC-BX', true);
    $pdo->prepare("UPDATE tb_ordem_coleta_pendente_baixa SET criado_em = NOW() - INTERVAL 40 DAY WHERE id = :i")->execute(['i' => $bFeita]);
    [$bFalha] = $mkBaixa($cnpjA, 'OC-2002');
    [$bAud] = $mkBaixa($cnpjA, 'OC-2001');   // mesmo numero em outro atendimento
    // ambiguidade real: dois clientes com o MESMO cnpj (indice unico removido so neste banco QA)
    $ext->exec('ALTER TABLE tb_clientes DROP INDEX uk_clientes_cnpj');
    $cnpjD = '66666666000166';
    $cD1 = ogCliente($ext, $cnpjD, 'DUPLICADO 1');
    $cD2 = ogCliente($ext, $cnpjD, 'DUPLICADO 2');
    ogOrdem($ext, $cD1, 'AMB-1', 'INATIVA', $dias(5), $dias(2));
    ogOrdem($ext, $cD2, 'AMB-1', 'INATIVA', $dias(5), $dias(2));
    [$bAmb] = $mkBaixa($cnpjD, 'AMB-1');
    for ($i = 1; $i <= 28; $i++) {
        $mkBaixa(null, 'SEM-' . $i);
    }
    $totalPendentes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_ordem_coleta_pendente_baixa WHERE resolvido_em IS NULL');

    $segredos = [$cnpjA, $cnpjB, $cnpjX, $razaoA, 'OC-1001', 'OC-2001', 'JOAO DA SILVA', '12345678901', 'ABC1D23', 'TRANSP RAPIDA', $codigoTotem, $tokenTotem, $storage];

    $semComentarios = static function (string $arquivo): string {
        $saida = '';
        foreach (token_get_all((string) file_get_contents($arquivo)) as $t) {
            if (is_array($t)) {
                if ($t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT) {
                    $saida .= $t[1];
                }
            } else {
                $saida .= $t;
            }
        }

        return $saida;
    };
    $dao = new OrdemColetaGestaoDao($ext, $pdo);
    $snapExt = static fn (): string => md5(json_encode(gtLinhas($ext, 'SELECT id, status, inativada_em FROM tb_ordens_coleta ORDER BY id')));
    $stOc = static fn (int $id): array => gtLinhas($ext, 'SELECT status, inativada_em FROM tb_ordens_coleta WHERE id = :i', ['i' => $id])[0] ?? ['status' => null, 'inativada_em' => null];
    $audit = static fn (string $acao): array => gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria WHERE acao = :a ORDER BY id_auditoria', ['a' => $acao]);
    $nAudit = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao IN (\'OC_ATIVAR\',\'OC_INATIVAR\',\'OC_VER_PDF\',\'OC_BAIXA_RESOLVER\')');
    $renomear = static function (string $de, string $para) use ($pdo): void {
        $pdo->exec("RENAME TABLE `{$de}` TO `{$para}`");
    };

    // ------------------------------------------------------------------
    // Infra de requisicoes
    // ------------------------------------------------------------------
    // o prepend (fail-closed) so aceita o banco externo QA por QA_QR_FORCE_EXT_DB_NAME no ambiente do CGI
    $H = ['logErro' => $log, 'cabecalhos' => ['QA_QR_FORCE_EXT_DB_NAME' => $bancoExt]];
    $todas = [];
    $req = static function (array $o) use (&$todas, $H): array {
        $resp = gtChamar(array_merge($H, $o, ['cabecalhos' => ($o['cabecalhos'] ?? []) + $H['cabecalhos']]));
        $todas[] = $resp;

        return $resp;
    };
    $ck = static fn (?array $l): array => $l !== null && $l['sid'] !== null ? ['cookies' => ['gestao_sid' => $l['sid']]] : [];
    $get = static fn (string $arq, ?array $l, array $q = [], array $extra = []): array => $req(array_merge(['arquivo' => $arq, 'query' => $q], $ck($l), $extra));
    $post = static fn (string $arq, ?array $l, array $form, array $extra = []): array => $req(array_merge(['arquivo' => $arq, 'metodo' => 'POST', 'form' => $form + ($l !== null ? ['csrf_token' => (string) $l['csrf']] : [])], $ck($l), $extra));
    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => '192.0.2.61']);
    $lUsu = gtLogin('carla.usuario', GT_SENHA_BOA, ['ip' => '192.0.2.62']);
    $lPend = gtLogin('paulo.pendente', GT_SENHA_BOA, ['ip' => '192.0.2.63']);
    afirmar('sessoes de teste abertas (admin, usuario e usuario com troca pendente) com CSRF', $lAdm['sid'] !== null && $lAdm['csrf'] !== null && $lUsu['sid'] !== null && $lUsu['csrf'] !== null && $lPend['sid'] !== null);
    afirmar('login do usuario leva a /gestao/ordens.php (home) e o do admin segue em usuarios.php', locOrdens(gtLogin('carla.usuario', GT_SENHA_BOA, ['ip' => '192.0.2.64'])['resp']) === '/gestao/ordens.php' && locOrdens(gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => '192.0.2.65'])['resp']) === '/gestao/usuarios.php');

    // ------------------------------------------------------------------
    // A. RBAC e metodos
    // ------------------------------------------------------------------
    foreach (['ordens.php', 'ordem.php'] as $arq) {
        $q = $arq === 'ordem.php' ? ['id' => (string) $oA1] : [];
        $ra = $get($arq, null, $q);
        afirmar("RBAC $arq: anonimo => login/401 e sem conteudo de ordem", in_array($ra['status'], [302, 401], true) && !str_contains($ra['corpo'], 'OC-1001') && !str_contains($ra['corpo'], 'ordens-tabela'));
        afirmar("RBAC $arq: usuario => 200", $get($arq, $lUsu, $q)['status'] === 200);
        afirmar("RBAC $arq: admin => 200", $get($arq, $lAdm, $q)['status'] === 200);
        $rp = $get($arq, $lPend, $q);
        afirmar("troca de senha pendente bloqueia $arq (302 conta.php)", $rp['status'] === 302 && locOrdens($rp) === '/gestao/conta.php');
        $rm = $post($arq, $lUsu, ['x' => '1']);
        afirmar("metodo POST em $arq (GET-only) => 405 com Allow: GET", $rm['status'] === 405 && str_contains((string) gtCabecalho($rm, 'allow'), 'GET'));
    }
    foreach (['ordem-status.php', 'ordem-baixa.php', 'ordem-pdf.php'] as $arq) {
        $rg = $get($arq, $lUsu);
        afirmar("metodo GET em $arq (POST-only) => 405 com Allow: POST, sem nenhum efeito", $rg['status'] === 405 && str_contains((string) gtCabecalho($rg, 'allow'), 'POST') && !str_starts_with(substr($rg['corpo'], 0, 5), '%PDF'));
        $rg2 = $get($arq, $lUsu, ['id_ordem' => (string) $oA1, 'acao' => 'inativar', 'id_baixa' => (string) $bResolve, 'status_visto' => 'ATIVA']);
        afirmar("GET com parametros de escrita em $arq => 405 e nada alterado", $rg2['status'] === 405 && $stOc($oA1)['status'] === 'ATIVA' && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_ordem_coleta_pendente_baixa WHERE id = :i AND resolvido_em IS NOT NULL', ['i' => $bResolve]) === 0);
        $ran = $post($arq, null, ['id_ordem' => (string) $oA1, 'id_baixa' => (string) $bResolve, 'acao' => 'inativar', 'status_visto' => 'ATIVA']);
        afirmar("anonimo POST $arq => 401/403/302 e nada alterado", in_array($ran['status'], [302, 401, 403], true) && $stOc($oA1)['status'] === 'ATIVA');
        $rsc = $post($arq, $lUsu, ['id_ordem' => (string) $oA1, 'id_baixa' => (string) $bResolve, 'acao' => 'inativar', 'status_visto' => 'ATIVA', 'csrf_token' => 'x'], []);
        afirmar("CSRF invalido em $arq => 403 e nada alterado", $rsc['status'] === 403 && $stOc($oA1)['status'] === 'ATIVA');
        $rsemcsrf = $req(['arquivo' => $arq, 'metodo' => 'POST', 'form' => ['id_ordem' => (string) $oA1, 'id_baixa' => (string) $bResolve, 'acao' => 'inativar', 'status_visto' => 'ATIVA']] + $ck($lUsu));
        afirmar("sem CSRF em $arq => 403 e nada alterado", $rsemcsrf['status'] === 403 && $stOc($oA1)['status'] === 'ATIVA');
        $rorig = $post($arq, $lUsu, ['id_ordem' => (string) $oA1, 'id_baixa' => (string) $bResolve, 'acao' => 'inativar', 'status_visto' => 'ATIVA'], ['origin' => 'https://evil.example']);
        afirmar("Origin externa em $arq => 403 e nada alterado", $rorig['status'] === 403 && $stOc($oA1)['status'] === 'ATIVA');
        $rcsrfOutro = $post($arq, $lUsu, ['id_ordem' => (string) $oA1, 'id_baixa' => (string) $bResolve, 'acao' => 'inativar', 'status_visto' => 'ATIVA', 'csrf_token' => (string) $lAdm['csrf']]);
        afirmar("token CSRF de OUTRA sessao em $arq => 403", $rcsrfOutro['status'] === 403 && $stOc($oA1)['status'] === 'ATIVA');
        $rpp = $post($arq, $lPend, ['id_ordem' => (string) $oA1, 'acao' => 'inativar', 'status_visto' => 'ATIVA', 'id_baixa' => (string) $bResolve]);
        afirmar("troca de senha pendente bloqueia POST em $arq (403) e nada alterado", $rpp['status'] === 403 && $stOc($oA1)['status'] === 'ATIVA');
    }
    afirmar('nenhum dos testes de metodo/CSRF/RBAC gravou auditoria de OC', $nAudit() === 0);

    // ------------------------------------------------------------------
    // B. Lista: abas, contagens, filtros, ordenacao, paginacao, XSS
    // ------------------------------------------------------------------
    $snapAntesLeitura = $snapExt();
    $p1 = $get('ordens.php', $lUsu);
    if (getenv('QA_DEBUG') && $p1['status'] !== 200) {
        echo substr($p1['cru'], 0, 1500) . "
--LOG--
" . (string) @file_get_contents($log) . "
";
    }
    $c = $p1['corpo'];
    $nAtivas = (int) gtEscalar($ext, "SELECT COUNT(*) FROM tb_ordens_coleta WHERE status = 'ATIVA'");
    $nAtivas15 = (int) gtEscalar($ext, "SELECT COUNT(*) FROM tb_ordens_coleta WHERE status = 'ATIVA' AND criado_em < NOW() - INTERVAL 15 DAY");
    $nInativas = (int) gtEscalar($ext, "SELECT COUNT(*) FROM tb_ordens_coleta WHERE status = 'INATIVA'");
    afirmar('lista: 200, aba padrao = ativas (aria-current so nela), 4 abas em nav com aria-label e sem roles de tablist', $p1['status'] === 200 && substr_count($c, 'aria-current="page"') === 2 /* menu + aba */ && preg_match('/id="aba-ativas"[^>]*aria-current="page"/', $c) === 1 && str_contains($c, '<nav class="gestao-abas" id="ordens-abas" aria-label="Categorias de ordens">') && !str_contains($c, 'role="tab') && !str_contains($c, 'role="tablist"'));
    afirmar('lista: ids das abas (aba-ativas, aba-ativas-15d, aba-inativas, aba-baixas), painel, descricao e formulario de busca', str_contains($c, 'id="aba-ativas-15d"') && str_contains($c, 'id="aba-inativas"') && str_contains($c, 'id="aba-baixas"') && str_contains($c, 'id="ordens-painel"') && str_contains($c, 'id="ordens-descricao"') && str_contains($c, 'id="ordens-filtros" method="get"') && str_contains($c, 'role="search"') && str_contains($c, 'id="ordens-aba-campo" value="ativas"'));
    afirmar("contagens por aba pelo banco: ativas=$nAtivas, ativas_15d=$nAtivas15, inativas=$nInativas, baixas pendentes=$totalPendentes (aria-hidden + texto para leitor de tela)", contagemAba($c, 'aba-ativas') === (string) $nAtivas && contagemAba($c, 'aba-ativas-15d') === (string) $nAtivas15 && contagemAba($c, 'aba-inativas') === (string) $nInativas && contagemAba($c, 'aba-baixas') === (string) $totalPendentes && str_contains($c, '<span class="gestao-sr"> (' . $nAtivas . ' registros)</span>'));
    afirmar('lista: aviso permanente de retencao do PDF (15 dias) e sem erro de carga', str_contains($c, 'id="ordens-aviso-retencao"') && str_contains($c, 'Depois que uma ordem é inativada, o PDF dela é apagado em 15 dias.') && !str_contains($c, 'ordens-erro-carga'));
    $linhas1 = idsOrdens($c);
    afirmar('lista: 25 por pagina, ordem padrao criada em DESC (mais recente primeiro)', count($linhas1) === 25 && $linhas1 === array_map('intval', array_column(gtLinhas($ext, "SELECT id FROM tb_ordens_coleta WHERE status = 'ATIVA' ORDER BY criado_em DESC, id DESC LIMIT 25"), 'id')));
    afirmar('lista: contador "Exibindo 1 a 25 de ' . $nAtivas . ' ordens." e paginacao Pagina 1 de ' . (int) ceil($nAtivas / 25), str_contains($c, 'id="ordens-contador" role="status">Exibindo 1 a 25 de ' . $nAtivas . ' ordens.<') && str_contains($c, '<span class="gestao-paginacao__posicao" id="pag-posicao">Página 1 de ' . (int) ceil($nAtivas / 25) . '</span>') && str_contains($c, 'id="pag-anterior" aria-disabled="true"') && str_contains($c, 'id="pag-proxima" href="'));
    afirmar('lista: colunas e classes do contrato (col-numero, col-cliente, col-transportadora, col-situacao, col-criada, col-pdf, col-acoes), caption e th scope', preg_match('/<caption[^>]*>.+<\/caption>/', $c) === 1 && substr_count($c, '<th scope="col"') === 7 && str_contains($c, 'class="col-transportadora"') && str_contains($c, 'class="col-pdf"') && str_contains($c, 'class="col-acoes"') && str_contains($c, 'table class="gestao-tabela gestao-tabela--ordens" id="ordens-tabela"'));
    $pTudo = $get('ordens.php', $lUsu, ['numero' => 'OC-1001']);
    afirmar('filtro numero exato/prefixo: OC-1001 devolve as DUAS OCs de clientes diferentes (e nenhuma outra)', idsOrdens($pTudo['corpo']) === [$oB1, $oA1] || idsOrdens($pTudo['corpo']) === [$oA1, $oB1]);
    $linhaA1 = (string) preg_replace('/\A.*?(<tr class="gestao-tabela__linha" data-id-ordem="' . $oA1 . '".*?<\/tr>).*\z/s', '$1', $pTudo['corpo']);
    afirmar('linha da OC-1001 (cliente A): ATIVA, idade normal, "Há 3 dias", PDF Disponivel, razao social + CNPJ formatado, transportadora, sinal de mesmo numero em outros clientes', str_contains($linhaA1, 'data-status="ativa" data-idade="normal"') && str_contains($linhaA1, 'Há 3 dias') && str_contains($linhaA1, 'Disponível') && str_contains($linhaA1, 'ACME LOGISTICA SA') && str_contains($linhaA1, '11.111.111/0001-11') && str_contains($linhaA1, 'TRANSP RAPIDA') && str_contains($linhaA1, '55.555.555/0001-55') && str_contains($linhaA1, 'Mesmo número em outros clientes') && str_contains($linhaA1, 'data-outros-clientes="1"'));
    $pOne = $get('ordens.php', $lUsu, ['numero' => 'OC-1003']);
    afirmar('OC sem outro cliente com o mesmo numero NAO tem o sinal; PDF "Não disponível" quando nao ha anexo', !str_contains($pOne['corpo'], 'Mesmo número em outros clientes') && str_contains($pOne['corpo'], 'Não disponível') && idsOrdens($pOne['corpo']) === [$oB3]);
    $pPrefixo = $get('ordens.php', $lUsu, ['numero' => 'OC-10']);
    afirmar('prefixo com 3+ caracteres encontra pelo inicio (OC-10 => 1001, 1002, 1003) e "C-100" (meio) nao encontra', count(idsOrdens($pPrefixo['corpo'])) === 4 && idsOrdens($get('ordens.php', $lUsu, ['numero' => 'C-100'])['corpo']) === []);
    $pCoringa = $get('ordens.php', $lUsu, ['numero' => 'OC-%']);
    afirmar('curinga "%" no numero e ignorado (mensagem no campo) e nao vira LIKE aberto', $pCoringa['status'] === 200 && str_contains($pCoringa['corpo'], 'id="erro-numero" role="alert"') && count(idsOrdens($pCoringa['corpo'])) === 25);

    $p15 = $get('ordens.php', $lUsu, ['aba' => 'ativas_15d']);
    afirmar('aba "Ativas há mais de 15 dias": so a OC de 20 dias, idade em atencao ("Há 20 dias"), mais antiga primeiro por padrao', idsOrdens($p15['corpo']) === [$oA2] && str_contains($p15['corpo'], 'data-idade="atencao"') && str_contains($p15['corpo'], 'Há 20 dias') && preg_match('/id="aba-ativas-15d"[^>]*aria-current="page"/', $p15['corpo']) === 1);
    $pIn = $get('ordens.php', $lUsu, ['aba' => 'inativas']);
    afirmar('aba Inativas: so INATIVAS, "Inativada em dd/mm/aaaa", sem "Há N dias", idade normal, botao Ativar (nao Inativar)', count(idsOrdens($pIn['corpo'])) === $nInativas && str_contains($pIn['corpo'], 'Inativada em ' . date('d/m/Y', time() - 3 * 86400)) && !str_contains($pIn['corpo'], 'Há ') && str_contains($pIn['corpo'], 'id="btn-ordem-ativar-' . $oI1 . '"') && !str_contains($pIn['corpo'], 'btn-ordem-inativar-') && !str_contains($pIn['corpo'], 'data-idade="atencao"'));
    afirmar('aba Inativas: filtros de periodo da inativacao (inativada-de/ate) existem so aqui', str_contains($pIn['corpo'], 'id="filtro-inativada-de"') && str_contains($pIn['corpo'], 'id="filtro-inativada-ate"') && !str_contains($c, 'filtro-inativada-de') && !str_contains($p15['corpo'], 'filtro-inativada-ate'));
    $pInPer = $get('ordens.php', $lUsu, ['aba' => 'inativas', 'inativada_de' => date('Y-m-d', time() - 5 * 86400), 'inativada_ate' => date('Y-m-d', time() - 2 * 86400)]);
    $esperaInPer = array_map('intval', array_column(gtLinhas($ext, "SELECT id FROM tb_ordens_coleta WHERE status = 'INATIVA' AND inativada_em >= :d AND inativada_em < :a ORDER BY criado_em DESC, id DESC", ['d' => date('Y-m-d', time() - 5 * 86400) . ' 00:00:00', 'a' => date('Y-m-d', time() - 1 * 86400) . ' 00:00:00']), 'id'));
    afirmar('periodo da inativacao filtra (inativadas entre ha 5 e ha 2 dias, inclusive; OC-BX de ha 1 dia fica de fora)', idsOrdens($pInPer['corpo']) === $esperaInPer && in_array($oI1, $esperaInPer, true) && !in_array($oBx, $esperaInPer, true) && !in_array($oI2, $esperaInPer, true));
    $pCriada = $get('ordens.php', $lUsu, ['aba' => 'inativas', 'de' => date('Y-m-d', time() - 12 * 86400), 'ate' => date('Y-m-d', time() - 9 * 86400)]);
    afirmar('periodo da criacao filtra (OC-2001 criada ha 10 dias e OC-BX ha 8 => so OC-2001)', idsOrdens($pCriada['corpo']) === [$oI1]);
    afirmar('contagem das abas respeita SO o periodo (nao cliente/numero): com numero=OC-2001 as abas mantem os totais', contagemAba($get('ordens.php', $lUsu, ['numero' => 'OC-2001'])['corpo'], 'aba-ativas') === (string) $nAtivas);
    $nAtivasPer = (int) gtEscalar($ext, "SELECT COUNT(*) FROM tb_ordens_coleta WHERE status = 'ATIVA' AND criado_em >= :d", ['d' => date('Y-m-d', time() - 2 * 86400) . ' 00:00:00']);
    afirmar('contagem das abas muda com o periodo (de = ha 2 dias)', contagemAba($get('ordens.php', $lUsu, ['de' => date('Y-m-d', time() - 2 * 86400)])['corpo'], 'aba-ativas') === (string) $nAtivasPer);
    $pCli = $get('ordens.php', $lUsu, ['cliente' => (string) $cB]);
    afirmar('filtro de cliente (select): so OCs do cliente B, opcao marcada, rotulo "razao (CNPJ)"', idsOrdens($pCli['corpo']) === array_map('intval', array_column(gtLinhas($ext, "SELECT id FROM tb_ordens_coleta WHERE status='ATIVA' AND cliente_id = :c ORDER BY criado_em DESC, id DESC", ['c' => $cB]), 'id')) && str_contains($pCli['corpo'], '<option value="' . $cB . '" selected>BETA TRANSPORTES LTDA (22.222.222/0001-22)</option>'));
    foreach (['99999999' => 'inexistente', '1 OR 1=1' => 'forma invalida', '0' => 'zero', "$cB
" => 'newline'] as $cliHostil => $rotCli) {
        $pCliH = $get('ordens.php', $lUsu, ['cliente' => (string) $cliHostil]);
        afirmar("filtro cliente $rotCli: NAO alarga (lista vazia), mensagem no campo (role=alert, aria-invalid) e select em Todos", $pCliH['status'] === 200 && idsOrdens($pCliH['corpo']) === [] && str_contains($pCliH['corpo'], 'id="erro-cliente"') && str_contains($pCliH['corpo'], 'O cliente é inválido ou não existe mais.') && str_contains($pCliH['corpo'], 'aria-invalid="true" aria-describedby="erro-cliente"') && str_contains($pCliH['corpo'], 'id="ordens-vazio"') && str_contains($pCliH['corpo'], 'Nenhuma ordem encontrada com estes filtros.') && !str_contains($pCliH['corpo'], 'id="ordens-tabela"'));
    }
    afirmar('filtro cliente invalido: os links de aba e Limpar nao carregam o cliente hostil', !str_contains(html_entity_decode($get('ordens.php', $lUsu, ['cliente' => '99999999'])['corpo']), 'cliente=99999999'));

    // ordenacao
    $pNum = $get('ordens.php', $lUsu, ['ordem' => 'numero', 'dir' => 'asc', 'cliente' => (string) $cB]);
    $esperaNum = array_map('intval', array_column(gtLinhas($ext, "SELECT id FROM tb_ordens_coleta WHERE status='ATIVA' AND cliente_id = :c ORDER BY numero_ordem_coleta ASC, id DESC", ['c' => $cB]), 'id'));
    afirmar('ordenar por numero ASC: ordem do banco, aria-sort="ascending" no cabecalho Numero e "none" nos outros, link de cabecalho alterna para DESC', idsOrdens($pNum['corpo']) === $esperaNum && preg_match('/class="col-numero" aria-sort="ascending"><a class="gestao-ordenar" href="[^"]*dir=desc/', $pNum['corpo']) === 1 && str_contains($pNum['corpo'], 'class="col-cliente" aria-sort="none"') && str_contains($pNum['corpo'], 'class="col-criada" aria-sort="none"'));
    $pCliOrd = $get('ordens.php', $lUsu, ['ordem' => 'cliente', 'dir' => 'desc']);
    afirmar('ordenar por cliente DESC: aria-sort descending', preg_match('/class="col-cliente" aria-sort="descending"/', $pCliOrd['corpo']) === 1 && $pCliOrd['status'] === 200);
    afirmar('ordenar por criada em: default DESC no cabecalho (aria-sort descending na coluna criada)', preg_match('/class="col-criada" aria-sort="descending"/', $c) === 1);
    afirmar('aba ativas_15d: cabecalho "Criada em" ja em ascending por padrao', preg_match('/class="col-criada" aria-sort="ascending"/', $p15['corpo']) === 1);
    foreach (['numero;DROP TABLE tb_ordens_coleta', 'inativada_em', 'id', 'oc.id DESC', 'NUMERO', '1', 'razao_social'] as $ord) {
        $pO = $get('ordens.php', $lUsu, ['ordem' => $ord]);
        afirmar('ordem hostil ' . json_encode($ord) . ' => ordenacao padrao (sem erro de SQL)', $pO['status'] === 200 && idsOrdens($pO['corpo']) === $linhas1 && !str_contains($pO['corpo'], 'SQLSTATE'));
    }
    foreach (['sideways', 'ASC; DROP', 'desc ', '0'] as $dir) {
        $pD = $get('ordens.php', $lUsu, ['ordem' => 'numero', 'dir' => $dir]);
        afirmar('dir hostil ' . json_encode($dir) . ' => direcao padrao da coluna (ASC), sem erro', $pD['status'] === 200 && !str_contains($pD['corpo'], 'SQLSTATE') && preg_match('/class="col-numero" aria-sort="ascending"/', $pD['corpo']) === 1);
    }

    // filtros adversariais
    $hostis = ["'; DROP TABLE tb_ordens_coleta;--", '<script>alert(1)</script>', str_repeat('A', 120), "OC\0X", "OC-1\nSET", '..\..\etc', 'OC 1001', '1 OR 1=1', "OC-1001' OR '1'='1"];
    $antesExt = $snapExt();
    foreach ($hostis as $hn) {
        $pH = $get('ordens.php', $lUsu, ['numero' => $hn]);
        afirmar('numero hostil ' . json_encode(substr($hn, 0, 24)) . ' => 200, ignorado com mensagem no campo, lista completa, sem SQL/XSS refletido', $pH['status'] === 200 && str_contains($pH['corpo'], 'id="erro-numero"') && idsOrdens($pH['corpo']) === $linhas1 && !str_contains($pH['corpo'], 'SQLSTATE') && !str_contains($pH['corpo'], '<script>alert(1)') && !str_contains($pH['corpo'], 'DROP TABLE'));
    }
    foreach (['2026-02-30', '2026-13-01', 'ontem', '0000-00-00', '2026-1-1', '1969-12-31', '2101-01-01', "2026-01-01\0", '2026-01-01 00:00:00', '<script>'] as $dt) {
        $pDt = $get('ordens.php', $lUsu, ['de' => $dt, 'ate' => $dt]);
        afirmar('data invalida ' . json_encode($dt) . ' => ignorada com mensagem nos campos e lista completa', $pDt['status'] === 200 && str_contains($pDt['corpo'], 'id="erro-criada-de" role="alert"') && idsOrdens($pDt['corpo']) === $linhas1 && !str_contains($pDt['corpo'], 'SQLSTATE') && !str_contains($pDt['corpo'], '<script>'));
    }
    $pInv = $get('ordens.php', $lUsu, ['de' => '2026-05-10', 'ate' => '2026-05-01']);
    afirmar('periodo invertido (de > ate) => ignorado inteiro com mensagem em "ate"', str_contains($pInv['corpo'], 'A data final não pode ser anterior à inicial') && idsOrdens($pInv['corpo']) === $linhas1);
    foreach (['aba' => ['inativas'], 'numero' => ['OC-1003'], 'cliente' => ['1'], 'de' => ['2026-01-01'], 'ordem' => ['numero'], 'dir' => ['asc'], 'pagina' => ['2'], 'mostrar' => ['todas']] as $k => $v) {
        $pArr = $get('ordens.php', $lUsu, [$k => $v]);
        afirmar("parametro em forma de array ({$k}[]) => ignorado (padrao), sem erro", $pArr['status'] === 200 && idsOrdens($pArr['corpo']) === $linhas1);
    }
    foreach (['zzz', "ativas' OR '1'='1", 'ATIVAS', 'todas', '', '0'] as $ab) {
        $pAb = $get('ordens.php', $lUsu, ['aba' => $ab]);
        afirmar('aba invalida ' . json_encode($ab) . ' => aba padrao (ativas)', $pAb['status'] === 200 && preg_match('/id="aba-ativas"[^>]*aria-current="page"/', $pAb['corpo']) === 1 && idsOrdens($pAb['corpo']) === $linhas1);
    }
    afirmar('filtros hostis nao alteraram nenhuma OC', $snapExt() === $antesExt);

    // paginacao
    $paginas = (int) ceil($nAtivas / 25);
    $pP2 = $get('ordens.php', $lUsu, ['pagina' => '2']);
    $pUlt = $get('ordens.php', $lUsu, ['pagina' => (string) $paginas]);
    $ultimoEsperado = $nAtivas - ($paginas - 1) * 25;
    afirmar('pagina 2: 25 linhas distintas da pagina 1, contador "26 a 50", links Anterior e Proxima', count(idsOrdens($pP2['corpo'])) === 25 && array_intersect($linhas1, idsOrdens($pP2['corpo'])) === [] && str_contains($pP2['corpo'], 'Exibindo 26 a 50 de ' . $nAtivas . ' ordens.') && str_contains($pP2['corpo'], 'id="pag-anterior" href="') && str_contains($pP2['corpo'], 'rel="prev"') && str_contains($pP2['corpo'], 'rel="next"'));
    afirmar("ultima pagina ($paginas): $ultimoEsperado linhas, Proxima desabilitada", count(idsOrdens($pUlt['corpo'])) === $ultimoEsperado && str_contains($pUlt['corpo'], 'id="pag-proxima" aria-disabled="true"') && str_contains($pUlt['corpo'], 'de ' . $nAtivas . ' ordens.'));
    $linkProx = preg_match('/id="pag-proxima" href="([^"]*)"/', $pP2['corpo'], $m) === 1 ? html_entity_decode($m[1]) : '';
    afirmar('link da paginacao so tem filtros validados em ordem fixa (aba, pagina) e vai a pagina 3', $linkProx === '/gestao/ordens.php?aba=ativas&pagina=3');
    foreach (['999999', '401', (string) ($paginas + 1), '9999999999'] as $pg) {
        $rPg = $get('ordens.php', $lUsu, ['pagina' => $pg]);
        afirmar("pagina alem do fim ($pg) => 302 para a ultima pagina ($paginas)", $rPg['status'] === 302 && queryDe(locOrdens($rPg))['pagina'] === (string) $paginas && str_starts_with(locOrdens($rPg), '/gestao/ordens.php?'));
    }
    foreach (['0', '-1', 'abc', '1.5', '1e3', ' 2', '99999999999999999999'] as $pg) {
        $rPg = $get('ordens.php', $lUsu, ['pagina' => $pg]);
        afirmar("pagina invalida ($pg) => pagina 1", $rPg['status'] === 200 && idsOrdens($rPg['corpo']) === $linhas1);
    }
    $rVazioPg = $get('ordens.php', $lUsu, ['aba' => 'inativas', 'numero' => 'NAO-EXISTE', 'pagina' => '9']);
    afirmar('sem resultados e pagina > 1 => redireciona para a pagina 1 (sem loop)', $rVazioPg['status'] === 302 && !isset(queryDe(locOrdens($rVazioPg))['pagina']));
    $pVazio = $get('ordens.php', $lUsu, ['aba' => 'inativas', 'numero' => 'NAO-EXISTE']);
    afirmar('estado vazio com filtro: contador "Nenhuma ordem.", #ordens-vazio com link "Limpar filtros", sem tabela nem paginacao', str_contains($pVazio['corpo'], 'id="ordens-contador" role="status">Nenhuma ordem.<') && str_contains($pVazio['corpo'], 'id="ordens-vazio"') && str_contains($pVazio['corpo'], 'id="ordens-vazio-limpar"') && !str_contains($pVazio['corpo'], 'id="ordens-tabela"') && !str_contains($pVazio['corpo'], 'id="ordens-paginacao"'));
    $pVazio2 = $get('ordens.php', $lUsu, ['aba' => 'ativas_15d', 'cliente' => (string) $cB]);
    afirmar('estado vazio sem busca util: "Nenhuma ordem encontrada com estes filtros." e link Limpar', str_contains($pVazio2['corpo'], 'Nenhuma ordem encontrada com estes filtros.') && idsOrdens($pVazio2['corpo']) === []);

    // links: so filtros validados
    $pLinks = $get('ordens.php', $lUsu, ['aba' => 'inativas', 'numero' => 'OC-2', 'de' => '2020-01-01', 'cliente' => (string) $cA, 'ordem' => 'numero', 'dir' => 'desc', 'zzz' => '<script>x</script>', 'msg' => 'oc_inativada']);
    $hrefs = [];
    preg_match_all('/href="([^"]*)"/', $pLinks['corpo'], $mh);
    foreach ($mh[1] as $hr) {
        $hrefs[] = html_entity_decode($hr);
    }
    afirmar('links de aba/ordenacao/abrir/limpar nao propagam parametros desconhecidos nem msg nem texto hostil', !array_filter($hrefs, static fn ($hr) => str_contains($hr, 'zzz') || str_contains($hr, '<script') || str_contains($hr, 'msg=')));
    $hrefAba = array_values(array_filter($hrefs, static fn ($hr) => str_starts_with($hr, '/gestao/ordens.php?aba=ativas')));
    afirmar('link de outra aba mantem so o periodo e zera cliente/numero/ordenacao (de=2020-01-01 fica)', $hrefAba !== [] && str_contains($hrefAba[0], 'de=2020-01-01') && !str_contains($hrefAba[0], 'numero=') && !str_contains($hrefAba[0], 'cliente='));
    afirmar('formulario de filtros: numero e cliente preenchidos (valores escapados), aba oculta correta', str_contains($pLinks['corpo'], 'name="numero" type="text" maxlength="50" autocomplete="off" value="OC-2"') && str_contains($pLinks['corpo'], '<option value="' . $cA . '" selected>'));
    $pLimpar = preg_match('/id="btn-limpar-filtros" href="([^"]*)"/', $pLinks['corpo'], $m) === 1 ? html_entity_decode($m[1]) : '';
    afirmar('"Limpar filtros" volta a lista da aba sem filtro nenhum', $pLimpar === '/gestao/ordens.php?aba=inativas');

    // mensagens (flash)
    $pFl = $get('ordens.php', $lUsu, ['msg' => 'oc_inativada']);
    afirmar('flash por codigo fixo: oc_inativada => "Ordem inativada." (sucesso); texto livre e codigo inexistente nao aparecem', str_contains($pFl['corpo'], 'data-tipo="sucesso"') && str_contains($pFl['corpo'], 'Ordem inativada. Ela não pode mais') && !str_contains($get('ordens.php', $lUsu, ['msg' => 'Ordem inativada'])['corpo'], 'id="gestao-flash"') && !str_contains($get('ordens.php', $lUsu, ['msg' => 'inexistente'])['corpo'], 'id="gestao-flash"'));
    afirmar('mensagem oc_estado_mudou usa o texto do UX (erro) e oc_banco_indisponivel o texto fixo', str_contains($get('ordens.php', $lUsu, ['msg' => 'oc_estado_mudou'])['corpo'], 'A ordem mudou de situação desde que você abriu a tela. Confira e tente de novo.') && str_contains($get('ordens.php', $lUsu, ['msg' => 'oc_banco_indisponivel'])['corpo'], 'Não foi possível consultar as ordens de coleta agora. Tente novamente em instantes.'));
    foreach (array_keys(GestaoContexto::MENSAGENS) as $codigoMsg) {
        if (str_starts_with($codigoMsg, 'oc_')) {
            afirmar("mensagem $codigoMsg: tipo valido, texto pt-BR acentuado sem mojibake e sem dado do usuario", in_array(GestaoContexto::MENSAGENS[$codigoMsg][0], ['sucesso', 'erro', 'info'], true) && strlen(GestaoContexto::MENSAGENS[$codigoMsg][1]) > 10 && !preg_match('/Ã|â€|\?\?/', GestaoContexto::MENSAGENS[$codigoMsg][1]));
        }
    }

    // XSS
    $pXss = $get('ordens.php', $lUsu, ['cliente' => (string) $cX]);
    afirmar('XSS: numero da OC e razao social hostis saem escapados (sem tag crua, sem handler), botao com aria-label escapado', idsOrdens($pXss['corpo']) === [$oX] && !str_contains($pXss['corpo'], '<script>alert(7)') && str_contains($pXss['corpo'], '&lt;script&gt;alert(7)&lt;/script&gt;OC') && !str_contains($pXss['corpo'], '<b>"X"') && str_contains($pXss['corpo'], '&lt;b&gt;&quot;X&quot;&amp;&#039; Hostil&lt;/b&gt;') && !str_contains($pXss['corpo'], '<img src=x') && str_contains($pXss['corpo'], 'aria-label="Inativar a ordem &lt;script&gt;alert(7)&lt;/script&gt;OC do cliente &lt;b&gt;&quot;X&quot;&amp;&#039; Hostil&lt;/b&gt;"') && str_contains($pXss['corpo'], 'aria-label="Abrir a ordem &lt;script&gt;alert(7)&lt;/script&gt;OC do cliente '));
    $pXssFiltro = $get('ordens.php', $lUsu, ['numero' => '"><img src=x onerror=alert(9)>']);
    afirmar('XSS: filtro hostil no numero nao e refletido no HTML (nem no value, nem no retorno)', !str_contains($pXssFiltro['corpo'], 'onerror=alert(9)') && !str_contains($pXssFiltro['corpo'], '"><img'));
    $pDXss = $get('ordem.php', $lUsu, ['id' => (string) $oX]);
    afirmar('XSS no detalhe: numero, razao social e transportadora hostis escapados', $pDXss['status'] === 200 && !str_contains($pDXss['corpo'], '<script>alert(7)') && !str_contains($pDXss['corpo'], '<img src=x onerror') && str_contains($pDXss['corpo'], '&lt;img src=x onerror=alert(8)&gt;'));

    // sem dados vazados / sem inline / acentuacao
    $respostasLeitura = [$p1, $p15, $pIn, $pXss, $pDXss, $pP2];
    afirmar('nenhum campo de totem (codigo/token_api) nas telas de OC (so campos da OC)', array_reduce($respostasLeitura, static fn ($ok, $r) => $ok && !str_contains($r['corpo'], $codigoTotem) && !str_contains($r['corpo'], $tokenTotem) && !str_contains($r['corpo'], 'token_api') && !preg_match('/name="codigo"|"codigo"/', $r['corpo']), true));
    afirmar('sem script/style/on* inline nas telas', array_reduce($respostasLeitura, static fn ($ok, $r) => $ok && semInlineOrdens($r['corpo']), true));
    afirmar('cabecalhos: no-store, X-Frame-Options, nosniff e CSP da gestao', str_contains((string) gtCabecalho($p1, 'cache-control'), 'no-store') && gtCabecalho($p1, 'x-frame-options') === 'DENY' && gtCabecalho($p1, 'x-content-type-options') === 'nosniff' && str_contains((string) gtCabecalho($p1, 'content-security-policy'), "script-src 'self'"));
    afirmar('acentuacao correta (sem mojibake) em portugues', str_contains($c, 'Ordens de coleta') && str_contains($c, 'Número') && str_contains($c, 'Situação') && str_contains($c, 'Ações') && str_contains($c, 'Ativas há mais de 15 dias') && !preg_match('/Ã|â€|�/', $c));
    afirmar('menu: usuario e admin veem "Ordens de coleta"; usuario nao ve Usuarios/Logs/Totens; item atual marcado', str_contains($c, 'href="/gestao/ordens.php"') && str_contains($c, 'gestao-menu__item--atual') && !str_contains($c, '/gestao/usuarios.php') && !str_contains($c, '/gestao/logs.php') && str_contains($get('ordens.php', $lAdm)['corpo'], 'href="/gestao/ordens.php"') && !str_contains($c, '/gestao/anexos.php'));
    afirmar('abrir a lista nao alterou nenhuma OC nem gravou auditoria', $snapExt() === $snapAntesLeitura && $nAudit() === 0);
    $ids5 = idsOrdens($get('ordens.php', $lUsu)['corpo']);
    afirmar('lista deterministica (mesma consulta duas vezes = mesma ordem)', $ids5 === $linhas1);

    // ------------------------------------------------------------------
    // C. Detalhe
    // ------------------------------------------------------------------
    $pD = $get('ordem.php', $lUsu, ['id' => (string) $oA1]);
    $d = $pD['corpo'];
    afirmar('detalhe: 200, contrato de ids (ordem-detalhe[data-id-ordem][data-status], ordem-numero, situacao, idade, criada, inativada, cliente, transportadora, motorista, acao-form, pdf)', $pD['status'] === 200 && str_contains($d, 'id="ordem-detalhe" data-id-ordem="' . $oA1 . '" data-status="ativa"') && str_contains($d, 'id="ordem-numero">OC-1001<') && str_contains($d, 'id="ordem-situacao"') && str_contains($d, 'id="ordem-idade" data-idade="normal">Há 3 dias<') && str_contains($d, 'id="ordem-criada"') && str_contains($d, 'id="ordem-inativada"') && str_contains($d, 'id="ordem-dados"') && str_contains($d, 'id="ordem-cliente"') && str_contains($d, 'id="ordem-transportadora"') && str_contains($d, 'id="ordem-motorista"') && str_contains($d, 'id="ordem-acao-form"'));
    afirmar('detalhe: dados EM CLARO (sem mascara): cliente, CNPJ formatado, transportadora, motorista, CNH e placa', str_contains($d, 'ACME LOGISTICA SA') && str_contains($d, '11.111.111/0001-11') && str_contains($d, 'TRANSP RAPIDA') && str_contains($d, 'JOAO DA SILVA') && str_contains($d, '12345678901') && str_contains($d, 'ABC1D23'));
    afirmar('detalhe: ATIVA tem botao Inativar com aria-label, formulario POST em ordem-status com CSRF, id_ordem, status_visto=ATIVA, origem=detalhe e retorno', str_contains($d, 'id="btn-ordem-inativar"') && str_contains($d, 'aria-label="Inativar a ordem OC-1001"') && str_contains($d, 'action="/gestao/ordem-status.php"') && str_contains($d, 'name="csrf_token"') && str_contains($d, 'name="id_ordem" value="' . $oA1 . '"') && str_contains($d, 'name="status_visto" value="ATIVA"') && str_contains($d, 'name="origem" value="detalhe"') && str_contains($d, 'name="retorno"') && !str_contains($d, 'btn-ordem-ativar'));
    afirmar('detalhe: PDF disponivel => botao "Baixar PDF" (com " (abre em uma nova aba)" so para leitor de tela) em form POST ordem-pdf com target=_blank, rel=noopener, CSRF e id_ordem; data-pdf=disponivel; aviso de retencao', str_contains($d, 'id="ordem-pdf" data-pdf="disponivel"') && str_contains($d, 'method="post" action="/gestao/ordem-pdf.php" target="_blank" rel="noopener"') && str_contains($d, 'id="btn-ordem-pdf"') && str_contains($d, '<span>Baixar PDF</span><span class="gestao-sr"> (abre em uma nova aba)</span>') && !str_contains($d, 'Ver PDF') && str_contains($d, 'id="ordem-pdf-aviso"') && str_contains($d, 'Depois que a ordem for inativada, o PDF dela é apagado em 15 dias.') && str_contains($d, '#i-externo'));
    afirmar('detalhe: sinal de mesmo numero em outros clientes (OC-1001)', str_contains($d, 'id="ordem-outros-clientes"'));
    $pDI = $get('ordem.php', $lUsu, ['id' => (string) $oI1]);
    $dataApagada = date('d/m/Y', time() - 3 * 86400 + 15 * 86400);
    afirmar('detalhe INATIVA com PDF: botao Ativar, data de apagamento prevista (inativacao + 15 dias), Idade "Inativada em"', str_contains($pDI['corpo'], 'id="btn-ordem-ativar"') && !str_contains($pDI['corpo'], 'btn-ordem-inativar') && str_contains($pDI['corpo'], 'data-status="inativa"') && str_contains($pDI['corpo'], 'Este PDF será apagado em ' . $dataApagada) && str_contains($pDI['corpo'], 'id="ordem-pdf" data-pdf="disponivel"'));
    $pDAp = $get('ordem.php', $lUsu, ['id' => (string) $oI2]);
    afirmar('detalhe INATIVA ha mais de 15 dias sem anexo => data-pdf=apagado, sem botao de PDF, texto unico "apagado automaticamente" e SEM aviso redundante', str_contains($pDAp['corpo'], 'data-pdf="apagado"') && !str_contains($pDAp['corpo'], 'btn-ordem-pdf') && str_contains($pDAp['corpo'], 'O PDF foi apagado automaticamente, 15 dias depois da inativação.') && !str_contains($pDAp['corpo'], 'id="ordem-pdf-aviso"'));
    $pDInDentro = $get('ordem.php', $lUsu, ['id' => (string) $oIDentro]);
    afirmar('detalhe INATIVA ha menos de 15 dias SEM anexo => data-pdf=ausente com o texto "Esta ordem não tem PDF anexado."', str_contains($pDInDentro['corpo'], 'data-pdf="ausente"') && str_contains($pDInDentro['corpo'], 'Esta ordem não tem PDF anexado.') && !str_contains($pDInDentro['corpo'], 'PDF não disponível'));
    $pDAus = $get('ordem.php', $lUsu, ['id' => (string) $oSem]);
    afirmar('detalhe ATIVA sem anexo => data-pdf=ausente, texto "Esta ordem não tem PDF anexado.", sem botao', str_contains($pDAus['corpo'], 'data-pdf="ausente"') && str_contains($pDAus['corpo'], 'Esta ordem não tem PDF anexado.') && !str_contains($pDAus['corpo'], 'btn-ordem-pdf'));
    $pDIsolada = $get('ordem.php', $lAdm, ['id' => (string) $oSem]);
    afirmar('detalhe: admin tambem ve tudo e pode agir (mesmo botao)', $pDIsolada['status'] === 200 && str_contains($pDIsolada['corpo'], 'id="btn-ordem-inativar"'));
    $pDV = $get('ordem.php', $lUsu, ['id' => (string) $oA1, 'aba' => 'inativas', 'numero' => 'OC-2', 'zzz' => '<script>', 'pagina' => '3', 'ordem' => 'numero', 'dir' => 'desc']);
    $voltar = preg_match('/id="ordem-voltar" href="([^"]*)"/', $pDV['corpo'], $m) === 1 ? html_entity_decode($m[1]) : '';
    afirmar('detalhe: "Voltar para a lista" volta com SO os filtros validados (aba, numero, ordenacao, pagina), sem zzz', $voltar === '/gestao/ordens.php?aba=inativas&numero=OC-2&ordem=numero&dir=desc&pagina=3');
    foreach (['abc', '0', '-1', '1 OR 1=1', str_repeat('9', 30), '01', '9999999999999999999', '1.5', ' 1', "1\n", '%00', '1;DROP TABLE tb_ordens_coleta', '999999'] as $idh) {
        $rIh = $get('ordem.php', $lUsu, ['id' => $idh, 'aba' => 'inativas']);
        afirmar('detalhe com id hostil/inexistente ' . json_encode(substr($idh, 0, 22)) . ' => 302 para a lista com msg=oc_nao_encontrada (e o filtro valido)', $rIh['status'] === 302 && queryDe(locOrdens($rIh))['msg'] === 'oc_nao_encontrada' && queryDe(locOrdens($rIh))['aba'] === 'inativas' && str_starts_with(locOrdens($rIh), '/gestao/ordens.php?'));
    }
    $rIhArr = $get('ordem.php', $lUsu, ['id' => [(string) $oA1]]);
    $rIhSem = $get('ordem.php', $lUsu);
    afirmar('detalhe com id em array ou ausente => 302 oc_nao_encontrada', $rIhArr['status'] === 302 && $rIhSem['status'] === 302 && queryDe(locOrdens($rIhSem))['msg'] === 'oc_nao_encontrada');
    $pFlD = $get('ordem.php', $lUsu, ['id' => (string) $oA1, 'msg' => 'oc_pdf_indisponivel']);
    afirmar('detalhe mostra a mensagem de PDF indisponivel (retencao)', str_contains($pFlD['corpo'], 'O PDF desta ordem não está disponível. Ele é apagado 15 dias depois que a ordem é inativada.') && !str_contains($pFlD['corpo'], 'não está mais disponível'));
    afirmar('detalhe: sem confirmar na query nao mostra #ordem-confirmacao (parametro confirmar sozinho nao altera nada)', !str_contains($pD['corpo'], 'ordem-confirmacao') && $stOc($oA1)['status'] === 'ATIVA');

    // ------------------------------------------------------------------
    // D. Ativar / inativar
    // ------------------------------------------------------------------
    $form = static fn (int $id, string $acao, string $visto, array $mais = []): array => ['id_ordem' => (string) $id, 'acao' => $acao, 'status_visto' => $visto] + $mais;

    // pedidos invalidos
    $antes = $snapExt();
    foreach ([['id_ordem' => 'abc', 'acao' => 'inativar', 'status_visto' => 'ATIVA'], ['id_ordem' => '0', 'acao' => 'inativar', 'status_visto' => 'ATIVA'], ['id_ordem' => (string) $oA1, 'acao' => 'apagar', 'status_visto' => 'ATIVA'], ['id_ordem' => (string) $oA1, 'acao' => 'inativar', 'status_visto' => 'QUALQUER'], ['id_ordem' => (string) $oA1, 'acao' => 'inativar', 'status_visto' => 'ativa'], ['id_ordem' => (string) $oA1, 'status_visto' => 'ATIVA'], ['id_ordem' => (string) $oA1, 'acao' => 'inativar'], ['id_ordem' => '1 OR 1=1', 'acao' => 'inativar', 'status_visto' => 'ATIVA'], ['id_ordem' => str_repeat('9', 30), 'acao' => 'inativar', 'status_visto' => 'ATIVA'], ['numero' => 'OC-1001', 'acao' => 'inativar', 'status_visto' => 'ATIVA'], ['id_ordem' => ['1'], 'acao' => 'inativar', 'status_visto' => 'ATIVA']] as $i => $fh) {
        $r = $post('ordem-status.php', $lUsu, $fh);
        afirmar("ordem-status com pedido invalido #$i => 400 e nada alterado nem auditado", $r['status'] === 400 && $snapExt() === $antes && $nAudit() === 0);
    }
    afirmar('inativar/ativar NAO aceita "numero" nem CNPJ: so id da OC (fonte do controller)', !preg_match('/post\(\'(numero|cnpj|numero_ordem_coleta)\'\)/', $semComentarios($raiz . '/app/Controller/GestaoOrdemController.php')));

    // OC inexistente
    $rIn = $post('ordem-status.php', $lUsu, $form(999999, 'inativar', 'ATIVA', ['retorno' => 'aba=inativas&pagina=2']));
    afirmar('id inexistente => 302 para a lista com msg=oc_nao_encontrada (mantem o retorno validado) e auditoria SEM_EFEITO oc_inexistente', $rIn['status'] === 302 && queryDe(locOrdens($rIn))['msg'] === 'oc_nao_encontrada' && queryDe(locOrdens($rIn))['aba'] === 'inativas' && ($audit('OC_INATIVAR')[0]['resultado'] ?? '') === 'SEM_EFEITO' && str_contains((string) ($audit('OC_INATIVAR')[0]['detalhe'] ?? ''), 'motivo_oc=oc_inexistente'));

    // status_visto desatualizado
    $nAuditAntes = $nAudit();
    $rVisto = $post('ordem-status.php', $lUsu, $form($oA2, 'inativar', 'INATIVA'));
    afirmar('status_visto desatualizado (tela dizia INATIVA, e ATIVA) => oc_estado_mudou, NADA alterado, auditoria SEM_EFEITO estado_mudou', $rVisto['status'] === 302 && queryDe(locOrdens($rVisto))['msg'] === 'oc_estado_mudou' && $stOc($oA2)['status'] === 'ATIVA' && $nAudit() === $nAuditAntes + 1 && ($audit('OC_INATIVAR')[1]['resultado'] ?? '') === 'SEM_EFEITO' && str_contains((string) ($audit('OC_INATIVAR')[1]['detalhe'] ?? ''), 'motivo_oc=estado_mudou'));

    $ultima = static function (string $acao) use ($audit): array {
        $l = $audit($acao);

        return $l === [] ? [] : $l[count($l) - 1];
    };
    $detalheDe = static fn (array $linha): string => (string) ($linha['detalhe'] ?? '');

    // ---- inativar por id (mesmo numero em dois clientes) e reativar
    $rOk = $post('ordem-status.php', $lUsu, $form($oA1, 'inativar', 'ATIVA', ['retorno' => 'aba=ativas&cliente=' . $cA]));
    $stA1 = $stOc($oA1);
    $aI = $ultima('OC_INATIVAR');
    afirmar('inativar por id: 302 para a lista com o retorno validado e msg=oc_inativada; OC INATIVA com inativada_em', $rOk['status'] === 302 && locOrdens($rOk) === '/gestao/ordens.php?aba=ativas&cliente=' . $cA . '&msg=oc_inativada' && $stA1['status'] === 'INATIVA' && $stA1['inativada_em'] !== null);
    afirmar('MESMO numero em dois clientes: so a OC do id mudou (a OC-1001 do cliente B segue ATIVA)', $stOc($oB1)['status'] === 'ATIVA' && $stOc($oA1)['status'] === 'INATIVA');
    afirmar('auditoria OC_INATIVAR: OK, alvo ordem_coleta + id, usuario da sessao, IP, detalhe so com status (sem numero/CNPJ/nome)', ($aI['resultado'] ?? '') === 'OK' && $aI['alvo_tipo'] === 'ordem_coleta' && (int) $aI['alvo_id'] === $oA1 && (int) $aI['id_usuario'] === $idUsu && $detalheDe($aI) === 'status_de=ATIVA;status_para=INATIVA' && $aI['ip'] !== null);
    $rRe = $post('ordem-status.php', $lAdm, $form($oA1, 'ativar', 'INATIVA'));
    $stRe = $stOc($oA1);
    $aA = $ultima('OC_ATIVAR');
    afirmar('reativar (admin): msg=oc_ativada, status ATIVA e inativada_em volta a NULL; auditoria OC_ATIVAR OK com status_de=INATIVA', $rRe['status'] === 302 && queryDe(locOrdens($rRe))['msg'] === 'oc_ativada' && $stRe['status'] === 'ATIVA' && $stRe['inativada_em'] === null && ($aA['resultado'] ?? '') === 'OK' && $detalheDe($aA) === 'status_de=INATIVA;status_para=ATIVA' && (int) $aA['id_usuario'] === $idAdmin);
    $rDet = $post('ordem-status.php', $lUsu, $form($oVis, 'inativar', 'ATIVA', ['origem' => 'detalhe', 'retorno' => 'aba=ativas&pagina=2']));
    afirmar('inativar a partir do detalhe: PRG volta ao detalhe (id + filtros validados + msg)', $rDet['status'] === 302 && locOrdens($rDet) === '/gestao/ordem.php?id=' . $oVis . '&aba=ativas&pagina=2&msg=oc_inativada' && $stOc($oVis)['status'] === 'INATIVA');
    $pPosDet = $get('ordem.php', $lUsu, ['id' => (string) $oVis, 'msg' => 'oc_inativada']);
    afirmar('detalhe apos inativar: situacao INATIVA, botao Ativar, flash de sucesso', str_contains($pPosDet['corpo'], 'data-status="inativa"') && str_contains($pPosDet['corpo'], 'id="btn-ordem-ativar"') && str_contains($pPosDet['corpo'], 'data-tipo="sucesso"'));

    // ---- ja no estado e estado mudou
    $rJa = $post('ordem-status.php', $lUsu, $form($oI2, 'inativar', 'INATIVA'));
    afirmar('inativar OC ja INATIVA (tela atual) => oc_sem_efeito_ja_inativa e auditoria SEM_EFEITO ja_no_estado; nada alterado', $rJa['status'] === 302 && queryDe(locOrdens($rJa))['msg'] === 'oc_sem_efeito_ja_inativa' && ($ultima('OC_INATIVAR')['resultado'] ?? '') === 'SEM_EFEITO' && str_contains($detalheDe($ultima('OC_INATIVAR')), 'motivo_oc=ja_no_estado') && $stOc($oI2)['status'] === 'INATIVA');
    $rJa2 = $post('ordem-status.php', $lUsu, $form($oSem, 'ativar', 'ATIVA'));
    afirmar('ativar OC ja ATIVA (tela atual) => oc_sem_efeito_ja_ativa', $rJa2['status'] === 302 && queryDe(locOrdens($rJa2))['msg'] === 'oc_sem_efeito_ja_ativa' && $stOc($oSem)['status'] === 'ATIVA');
    $inativadaAntes = $stOc($oI2)['inativada_em'];
    $rEm = $post('ordem-status.php', $lUsu, $form($oI2, 'ativar', 'ATIVA'));
    afirmar('ativar com status_visto ATIVA numa OC que esta INATIVA => oc_estado_mudou e NADA alterado (inativada_em intacta)', $rEm['status'] === 302 && queryDe(locOrdens($rEm))['msg'] === 'oc_estado_mudou' && $stOc($oI2)['status'] === 'INATIVA' && $stOc($oI2)['inativada_em'] === $inativadaAntes);
    $rEm2 = $post('ordem-status.php', $lUsu, $form($oA2, 'inativar', 'INATIVA', ['origem' => 'detalhe']));
    afirmar('estado mudou a partir do detalhe volta ao detalhe com a mensagem', queryDe(locOrdens($rEm2))['msg'] === 'oc_estado_mudou' && str_starts_with(locOrdens($rEm2), '/gestao/ordem.php?id=' . $oA2));

    // ---- confirmacao em dois passos
    $antesConf = $nAudit();
    $r1 = $post('ordem-status.php', $lUsu, $form($oAnd, 'inativar', 'ATIVA', ['retorno' => 'aba=ativas&numero=OC-AND']));
    $q1 = queryDe(locOrdens($r1));
    afirmar('inativar com atendimentos EM ANDAMENTO (2) sem confirmar => 302 ao detalhe com confirmar=inativar e msg=oc_confirmacao_necessaria, NADA alterado e sem auditoria', $r1['status'] === 302 && str_starts_with(locOrdens($r1), '/gestao/ordem.php?') && $q1['id'] === (string) $oAnd && $q1['confirmar'] === 'inativar' && $q1['msg'] === 'oc_confirmacao_necessaria' && $q1['numero'] === 'OC-AND' && $stOc($oAnd)['status'] === 'ATIVA' && $nAudit() === $antesConf);
    $pConf = $get('ordem.php', $lUsu, ['id' => (string) $oAnd, 'confirmar' => 'inativar', 'msg' => 'oc_confirmacao_necessaria', 'aba' => 'ativas', 'numero' => 'OC-AND']);
    afirmar('detalhe com aviso: #ordem-confirmacao role=alert com "Há 2 atendimentos em andamento com esta ordem. Confirme para inativar mesmo assim.", flash neutro, SEM alterar nada', $pConf['status'] === 200 && str_contains($pConf['corpo'], 'id="ordem-confirmacao" role="alert" data-acao="inativar"') && str_contains($pConf['corpo'], 'Há 2 atendimentos em andamento com esta ordem. Confirme para inativar mesmo assim.') && str_contains($pConf['corpo'], 'data-tipo="info"') && $stOc($oAnd)['status'] === 'ATIVA');
    afirmar('detalhe com aviso: formulario do segundo passo (confirmar=1, origem=detalhe, mesmo id e status_visto), botao "Inativar mesmo assim" e Cancelar', str_contains($pConf['corpo'], 'name="confirmar" value="1"') && str_contains($pConf['corpo'], 'name="acao" value="inativar"') && str_contains($pConf['corpo'], 'name="status_visto" value="ATIVA"') && str_contains($pConf['corpo'], 'Inativar mesmo assim') && str_contains($pConf['corpo'], 'id="ordem-confirmacao-cancelar"') && substr_count($pConf['corpo'], 'id="btn-ordem-inativar"') === 1);
    afirmar('confirmar=inativar sem atendimento, em OC ja inativa, com acao oposta ou valor hostil NAO mostra o formulario de confirmacao', !str_contains($get('ordem.php', $lUsu, ['id' => (string) $oSem, 'confirmar' => 'inativar'])['corpo'], 'ordem-confirmacao') && !str_contains($get('ordem.php', $lUsu, ['id' => (string) $oAnd, 'confirmar' => 'ativar'])['corpo'], 'ordem-confirmacao') && !str_contains($get('ordem.php', $lUsu, ['id' => (string) $oAnd, 'confirmar' => '<script>'])['corpo'], 'ordem-confirmacao') && !str_contains($get('ordem.php', $lUsu, ['id' => (string) $oAnd, 'confirmar' => ['inativar']])['corpo'], 'ordem-confirmacao'));
    foreach (['0', 'true', '2', '', 'sim', ' 1', '1 '] as $cv) {
        $rcv = $post('ordem-status.php', $lUsu, $form($oConf, 'inativar', 'ATIVA', ['confirmar' => $cv]));
        afirmar('confirmar=' . json_encode($cv) . ' (diferente de "1") nao confirma: volta ao aviso e NADA muda', queryDe(locOrdens($rcv))['msg'] === 'oc_confirmacao_necessaria' && $stOc($oConf)['status'] === 'ATIVA');
    }
    $nAuditAntesC = $nAudit();
    $r2 = $post('ordem-status.php', $lUsu, $form($oAnd, 'inativar', 'ATIVA', ['confirmar' => '1', 'origem' => 'detalhe', 'retorno' => 'aba=ativas&numero=OC-AND']));
    $aC = $ultima('OC_INATIVAR');
    afirmar('segundo passo (confirmar=1): inativa, volta ao detalhe com msg=oc_inativada; auditoria OK com motivo_oc=confirmado_andamento', $r2['status'] === 302 && queryDe(locOrdens($r2))['msg'] === 'oc_inativada' && $stOc($oAnd)['status'] === 'INATIVA' && ($aC['resultado'] ?? '') === 'OK' && $detalheDe($aC) === 'status_de=ATIVA;status_para=INATIVA;motivo_oc=confirmado_andamento' && $nAudit() === $nAuditAntesC + 1);
    $rBx1 = $post('ordem-status.php', $lUsu, $form($oBx, 'ativar', 'INATIVA'));
    $qBx = queryDe(locOrdens($rBx1));
    $pBx = $get('ordem.php', $lUsu, ['id' => (string) $oBx, 'confirmar' => 'ativar', 'msg' => 'oc_confirmacao_necessaria']);
    afirmar('reativar OC ja BAIXADA (check-in concluido) sem confirmar => aviso, NADA muda; texto "Esta ordem já foi usada em um check-in concluído. Confirme para ativar mesmo assim."', ($qBx['confirmar'] ?? '') === 'ativar' && $qBx['msg'] === 'oc_confirmacao_necessaria' && $stOc($oBx)['status'] === 'INATIVA' && str_contains($pBx['corpo'], 'Esta ordem já foi usada em um check-in concluído. Confirme para ativar mesmo assim.') && str_contains($pBx['corpo'], 'Ativar mesmo assim'));
    $rBx2 = $post('ordem-status.php', $lUsu, $form($oBx, 'ativar', 'INATIVA', ['confirmar' => '1']));
    $aB = $ultima('OC_ATIVAR');
    afirmar('reativar confirmado: ATIVA, inativada_em NULL, auditoria OK com motivo_oc=confirmado_ja_baixada', queryDe(locOrdens($rBx2))['msg'] === 'oc_ativada' && $stOc($oBx)['status'] === 'ATIVA' && $stOc($oBx)['inativada_em'] === null && $detalheDe($aB) === 'status_de=INATIVA;status_para=ATIVA;motivo_oc=confirmado_ja_baixada');
    $rSemConf = $post('ordem-status.php', $lUsu, $form($oSem, 'inativar', 'ATIVA', ['confirmar' => '1']));
    afirmar('confirmar=1 sem nada a confirmar nao deixa marca de confirmacao na auditoria', queryDe(locOrdens($rSemConf))['msg'] === 'oc_inativada' && $detalheDe($ultima('OC_INATIVAR')) === 'status_de=ATIVA;status_para=INATIVA');
    $post('ordem-status.php', $lUsu, $form($oSem, 'ativar', 'INATIVA'));

    // ---- ajuste 4: cliente INATIVO (tb_clientes.status): aviso, confirmacao em dois passos e motivo_oc proprio
    $cnpjI = '88888888000188';
    $cI = ogCliente($ext, $cnpjI, 'DELTA CLIENTE INATIVO SA', 'INATIVO');
    $inativadaCli = $dias(2);
    $oCI = ogOrdem($ext, $cI, 'OC-CLIINAT', 'INATIVA', $dias(6), $inativadaCli);
    $gravarPdf($cnpjI, 'OC-CLIINAT', $pdfBytes);
    $oCISem = ogOrdem($ext, $cI, 'OC-CLIINAT-SEMPDF', 'INATIVA', $dias(6), $dias(3));
    $oCIBx = ogOrdem($ext, $cI, 'OC-CLIINAT-BX', 'INATIVA', $dias(6), $dias(3));
    $oCIAt = ogOrdem($ext, $cI, 'OC-CLIINAT-ATIVA', 'ATIVA', $dias(1));
    $oAtivoTmp = ogOrdem($ext, $cA, 'OC-ATIVO-TMP', 'INATIVA', $dias(6), $dias(2));
    $mkAt('expedicao', 'OC-CLIINAT-BX', $cnpjI, 'concluido');
    $aviso = 'Este cliente está inativo. O totem não vai mostrar esta ordem enquanto o cliente continuar inativo.';
    $dCli = $dao->buscarPorId($oCI);
    afirmar('DAO: buscarPorId traz cliente_status (INATIVO / ATIVO) junto com a OC', ($dCli['cliente_status'] ?? '') === 'INATIVO' && ($dao->buscarPorId($oA2)['cliente_status'] ?? '') === 'ATIVO');
    $pCiDet = $get('ordem.php', $lUsu, ['id' => (string) $oCI]);
    afirmar('detalhe de OC de cliente INATIVO: aviso permanente #ordem-cliente-inativo (gestao-estado--aviso, icone alerta, texto exato) sem role=alert e SEM #ordem-confirmacao', $pCiDet['status'] === 200 && str_contains($pCiDet['corpo'], 'id="ordem-cliente-inativo"') && str_contains($pCiDet['corpo'], $aviso) && preg_match('/<div class="gestao-estado gestao-estado--aviso" id="ordem-cliente-inativo"[^>]*><svg.*?#i-alerta/s', $pCiDet['corpo']) === 1 && !str_contains($pCiDet['corpo'], 'id="ordem-cliente-inativo" role=') && !str_contains($pCiDet['corpo'], 'id="ordem-confirmacao"'));
    $pCiAtiva = $get('ordem.php', $lUsu, ['id' => (string) $oCIAt]);
    afirmar('detalhe de OC ATIVA de cliente INATIVO: o aviso permanente tambem aparece (cliente inativo vale para qualquer situacao)', str_contains($pCiAtiva['corpo'], 'id="ordem-cliente-inativo"') && str_contains($pCiAtiva['corpo'], 'id="btn-ordem-inativar"'));
    afirmar('detalhe de OC de cliente ATIVO: sem aviso de cliente inativo', !str_contains($get('ordem.php', $lUsu, ['id' => (string) $oA2])['corpo'], 'ordem-cliente-inativo') && !str_contains($get('ordem.php', $lUsu, ['id' => (string) $oI2])['corpo'], 'ordem-cliente-inativo'));
    // lista: sinal "Cliente inativo" so nas OCs do cliente inativo + data de inativacao e "PDF sera apagado" na celula de situacao
    $pCiLista = $get('ordens.php', $lUsu, ['aba' => 'inativas', 'cliente' => (string) $cI]);
    $lnOrdem = static function (string $corpo, int $id): string {
        return (string) preg_replace('/\A.*?(<tr class="gestao-tabela__linha" data-id-ordem="' . $id . '".*?<\/tr>).*\z/s', '$1', $corpo);
    };
    $lCi = $lnOrdem($pCiLista['corpo'], $oCI);
    $lCiSem = $lnOrdem($pCiLista['corpo'], $oCISem);
    $apagarEm = (new DateTimeImmutable($inativadaCli, new DateTimeZone('-03:00')))->modify('+15 days')->format('d/m/Y');
    afirmar('lista (Inativas, cliente INATIVO): sinal "Cliente inativo" (icone alerta + texto + texto para leitor de tela) na coluna Cliente', $pCiLista['status'] === 200 && str_contains($lCi, 'class="gestao-sinal gestao-sinal--cliente-inativo" data-cliente-inativo="1"') && str_contains($lCi, '<span aria-hidden="true">Cliente inativo</span>') && str_contains($lCi, 'Cliente inativo: o totem não mostra esta ordem') && preg_match('/<td class="col-cliente">.*gestao-sinal--cliente-inativo.*?<\/td>/s', $lCi) === 1);
    afirmar('lista (Inativas): celula de situacao com "Inativada em dd/mm/aaaa hh:mm" (<time>) em linha propria e, com PDF, "PDF será apagado em ' . $apagarEm . '" (inativada_em + 15 dias); sem PDF nao ha a linha', preg_match('/<td class="col-situacao">.*?Inativa<\/span><\/span> <span class="gestao-celula-sec gestao-situacao__inativada">Inativada em <time datetime="[^"]+">\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}<\/time><\/span> <span class="gestao-celula-sec gestao-situacao__pdf-apagado">PDF será apagado em ' . preg_quote($apagarEm, '/') . '<\/span><\/td>/s', $lCi) === 1 && str_contains($lCiSem, 'gestao-situacao__inativada') && !str_contains($lCiSem, 'PDF será apagado') && str_contains($lCi, '<td class="col-pdf">Disponível</td>'));
    $pAtivasLista = $get('ordens.php', $lUsu, ['aba' => 'ativas', 'cliente' => (string) $cI]);
    afirmar('lista (Ativas): OC ATIVA do cliente inativo tem o sinal e NAO tem data de inativacao nem "PDF será apagado"', str_contains($pAtivasLista['corpo'], 'gestao-sinal--cliente-inativo') && !str_contains($pAtivasLista['corpo'], 'gestao-situacao__inativada') && !str_contains($pAtivasLista['corpo'], 'PDF será apagado'));
    $pAtivoLista = $get('ordens.php', $lUsu, ['aba' => 'inativas', 'cliente' => (string) $cA]);
    afirmar('lista (Inativas, cliente ATIVO): SEM sinal de cliente inativo, mas com a data de inativacao na situacao', !str_contains($pAtivoLista['corpo'], 'gestao-sinal--cliente-inativo') && !str_contains($pAtivoLista['corpo'], 'Cliente inativo') && str_contains($pAtivoLista['corpo'], 'gestao-situacao__inativada'));
    afirmar('lista: larguras das colunas inalteradas (1000px) e ids do contrato preservados (th.col-situacao 100px; sem coluna nova)', substr_count($pCiLista['corpo'], '<th scope="col"') === 7 && str_contains($pCiLista['corpo'], '<th scope="col" class="col-situacao">Situação</th>') && preg_match('/\.gestao-tabela--ordens th\.col-situacao \{ width: 100px; \}/', (string) file_get_contents($raiz . '/public/gestao/assets/gestao.css')) === 1);
    // ativar sem confirmar: aviso, NADA muda, sem auditoria
    $nAuditCi = $nAudit();
    $snapCi = $snapExt();
    $rCi1 = $post('ordem-status.php', $lUsu, $form($oCI, 'ativar', 'INATIVA', ['retorno' => 'aba=inativas']));
    $qCi1 = queryDe(locOrdens($rCi1));
    afirmar('ativar OC de cliente INATIVO sem confirmar=1 => 302 ao detalhe com confirmar=ativar e msg=oc_confirmacao_necessaria; NADA alterado e SEM auditoria', $rCi1['status'] === 302 && str_starts_with(locOrdens($rCi1), '/gestao/ordem.php?') && $qCi1['id'] === (string) $oCI && ($qCi1['confirmar'] ?? '') === 'ativar' && $qCi1['msg'] === 'oc_confirmacao_necessaria' && $stOc($oCI)['status'] === 'INATIVA' && $stOc($oCI)['inativada_em'] === $inativadaCli && $nAudit() === $nAuditCi && $snapExt() === $snapCi);
    foreach (['0', 'true', '', 'sim'] as $cv) {
        $rcv = $post('ordem-status.php', $lUsu, $form($oCI, 'ativar', 'INATIVA', ['confirmar' => $cv]));
        afirmar('cliente INATIVO: confirmar=' . json_encode($cv) . ' nao confirma (volta ao aviso) e nada muda', queryDe(locOrdens($rcv))['msg'] === 'oc_confirmacao_necessaria' && $stOc($oCI)['status'] === 'INATIVA' && $nAudit() === $nAuditCi);
    }
    $pCiConf = $get('ordem.php', $lUsu, ['id' => (string) $oCI, 'confirmar' => 'ativar', 'msg' => 'oc_confirmacao_necessaria']);
    afirmar('detalhe com confirmar=ativar (cliente INATIVO): #ordem-confirmacao role=alert com o aviso + "Confirme para ativar mesmo assim.", botao "Ativar mesmo assim", Cancelar, confirmar=1 no formulario; o aviso permanente tambem aparece', $pCiConf['status'] === 200 && str_contains($pCiConf['corpo'], 'id="ordem-confirmacao" role="alert" data-acao="ativar"') && str_contains($pCiConf['corpo'], $aviso . ' Confirme para ativar mesmo assim.') && str_contains($pCiConf['corpo'], 'Ativar mesmo assim') && str_contains($pCiConf['corpo'], 'id="ordem-confirmacao-cancelar"') && str_contains($pCiConf['corpo'], 'name="confirmar" value="1"') && str_contains($pCiConf['corpo'], 'id="ordem-cliente-inativo"') && $stOc($oCI)['status'] === 'INATIVA');
    afirmar('confirmar=inativar em OC de cliente inativo NAO exige nada extra (inativar nunca pede o aviso de cliente inativo): sem #ordem-confirmacao', !str_contains($get('ordem.php', $lUsu, ['id' => (string) $oCIAt, 'confirmar' => 'inativar'])['corpo'], 'id="ordem-confirmacao"'));
    $rCi2 = $post('ordem-status.php', $lUsu, $form($oCI, 'ativar', 'INATIVA', ['confirmar' => '1', 'origem' => 'detalhe']));
    $aCi = $ultima('OC_ATIVAR');
    afirmar('segundo passo (confirmar=1): ativa (AVISO, nao bloqueio), inativada_em NULL, volta ao detalhe com msg=oc_ativada; auditoria OK com motivo_oc=confirmado_cliente_inativo (valor fixo, sem PII)', $rCi2['status'] === 302 && queryDe(locOrdens($rCi2))['msg'] === 'oc_ativada' && $stOc($oCI)['status'] === 'ATIVA' && $stOc($oCI)['inativada_em'] === null && ($aCi['resultado'] ?? '') === 'OK' && $detalheDe($aCi) === 'status_de=INATIVA;status_para=ATIVA;motivo_oc=confirmado_cliente_inativo' && (int) $aCi['alvo_id'] === $oCI && $nAudit() === $nAuditCi + 1 && !str_contains($detalheDe($aCi), 'OC-CLIINAT') && !str_contains($detalheDe($aCi), $cnpjI));
    // cliente inativo E OC ja baixada: um unico motivo; "ja baixada" tem precedencia
    $rCiBx0 = $post('ordem-status.php', $lUsu, $form($oCIBx, 'ativar', 'INATIVA'));
    $pCiBx = $get('ordem.php', $lUsu, ['id' => (string) $oCIBx, 'confirmar' => 'ativar']);
    afirmar('cliente INATIVO + OC ja baixada: um unico aviso com os dois textos ("Este cliente está inativo ... Esta ordem já foi usada em um check-in concluído. Confirme para ativar mesmo assim.")', queryDe(locOrdens($rCiBx0))['msg'] === 'oc_confirmacao_necessaria' && $stOc($oCIBx)['status'] === 'INATIVA' && str_contains($pCiBx['corpo'], $aviso . ' Esta ordem já foi usada em um check-in concluído. Confirme para ativar mesmo assim.'));
    $post('ordem-status.php', $lUsu, $form($oCIBx, 'ativar', 'INATIVA', ['confirmar' => '1']));
    afirmar('cliente INATIVO + OC ja baixada confirmada: ativa e o motivo_oc e confirmado_ja_baixada (precedencia)', $stOc($oCIBx)['status'] === 'ATIVA' && $detalheDe($ultima('OC_ATIVAR')) === 'status_de=INATIVA;status_para=ATIVA;motivo_oc=confirmado_ja_baixada');
    // cliente ATIVO: comportamento inalterado (ativa direto, sem confirmacao e sem motivo_oc)
    $nAuditAtivo = $nAudit();
    $rAt = $post('ordem-status.php', $lUsu, $form($oAtivoTmp, 'ativar', 'INATIVA'));
    afirmar('cliente ATIVO sem check-in concluido: ativar segue direto (oc_ativada), sem confirmacao e auditoria SEM motivo_oc', queryDe(locOrdens($rAt))['msg'] === 'oc_ativada' && $stOc($oAtivoTmp)['status'] === 'ATIVA' && $detalheDe($ultima('OC_ATIVAR')) === 'status_de=INATIVA;status_para=ATIVA' && $nAudit() === $nAuditAtivo + 1);
    // inativar OC de cliente inativo: sem confirmacao extra
    $rIni = $post('ordem-status.php', $lUsu, $form($oCIAt, 'inativar', 'ATIVA'));
    afirmar('inativar OC de cliente INATIVO segue direto (o aviso so vale ao ATIVAR) e a auditoria nao leva motivo_oc', queryDe(locOrdens($rIni))['msg'] === 'oc_inativada' && $stOc($oCIAt)['status'] === 'INATIVA' && $detalheDe($ultima('OC_INATIVAR')) === 'status_de=ATIVA;status_para=INATIVA');
    // limpeza: estas OCs/cliente existem so neste bloco (as contagens das abas seguem as da semeadura)
    $ext->prepare('DELETE FROM tb_ordens_coleta WHERE id IN (:a, :b, :c, :d, :e)')->execute(['a' => $oCI, 'b' => $oCISem, 'c' => $oCIBx, 'd' => $oCIAt, 'e' => $oAtivoTmp]);
    $ext->prepare('DELETE FROM tb_ordem_coleta_arquivos WHERE cnpj_cliente = :c')->execute(['c' => $cnpjI]);
    $ext->prepare('DELETE FROM tb_clientes WHERE id = :i')->execute(['i' => $cI]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE cliente_cnpj = :c')->execute(['c' => $cnpjI]);

    // ---- retorno hostil nunca vira URL
    foreach (['http://evil.example/x', '//evil.example', '/gestao/../etc', 'aba=inativas&numero=<script>alert(1)</script>&zzz=1', "aba=inativas\r\nLocation: http://evil.example", 'aba=baixas&mostrar=todas&pagina=3&msg=oc_inativada', str_repeat('a=1&', 400), '%0d%0aSet-Cookie:x=1', 'javascript:alert(1)'] as $rh) {
        $rr = $post('ordem-status.php', $lUsu, $form($oSem, 'ativar', 'ATIVA', ['retorno' => $rh]));
        $loc = locOrdens($rr);
        afirmar('retorno hostil ' . json_encode(substr($rh, 0, 30)) . ' => redirect so para /gestao/ordens.php com filtros validados', $rr['status'] === 302 && str_starts_with($loc, '/gestao/ordens.php?aba=') && !str_contains($loc, 'evil') && !str_contains($loc, '<') && !str_contains($loc, "\r") && !str_contains($loc, 'zzz') && !str_contains($loc, 'Set-Cookie') && gtCabecalho($rr, 'set-cookie') === null);
    }
    $rRetBaixas = $post('ordem-status.php', $lUsu, $form($oSem, 'ativar', 'ATIVA', ['retorno' => 'aba=baixas&mostrar=todas&pagina=3']));
    afirmar('retorno valido de outra aba e preservado (aba=baixas&mostrar=todas&pagina=3)', str_starts_with(locOrdens($rRetBaixas), '/gestao/ordens.php?aba=baixas&mostrar=todas&pagina=3&msg='));

    // ---- AUDITORIA PENDENTE ANTES DO UPDATE (sonda no banco externo)
    $ext->exec('CREATE TABLE probe_aud (id INT AUTO_INCREMENT PRIMARY KEY, pendentes INT NOT NULL, status_novo VARCHAR(10) NOT NULL, oc_id BIGINT NOT NULL)');
    $ext->exec('CREATE TABLE probe_upd (id INT AUTO_INCREMENT PRIMARY KEY)');
    $triggerOk = true;
    try {
        $ext->exec("CREATE TRIGGER trg_probe_aud BEFORE UPDATE ON tb_ordens_coleta FOR EACH ROW INSERT INTO probe_aud (pendentes, status_novo, oc_id) SELECT COUNT(*), NEW.status, OLD.id FROM `{$amb['banco_totem']}`.tb_gestao_auditoria WHERE resultado = 'PENDENTE' AND acao IN ('OC_INATIVAR','OC_ATIVAR') AND alvo_id = OLD.id");
    } catch (Throwable $e) {
        $triggerOk = false;
    }
    afirmar('sonda: trigger BEFORE UPDATE no banco externo criado (le a auditoria do totem)', $triggerOk);
    $rTrig = $post('ordem-status.php', $lUsu, $form($oTrig, 'inativar', 'ATIVA'));
    $sonda = gtLinhas($ext, 'SELECT pendentes, status_novo, oc_id FROM probe_aud');
    afirmar('ORDEM: no instante do UPDATE ja existia 1 linha de auditoria PENDENTE da acao (aberta ANTES), e depois foi fechada como OK', count($sonda) === 1 && (int) $sonda[0]['pendentes'] === 1 && $sonda[0]['status_novo'] === 'INATIVA' && (int) $sonda[0]['oc_id'] === $oTrig && ($ultima('OC_INATIVAR')['resultado'] ?? '') === 'OK' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    $ext->exec('DROP TRIGGER trg_probe_aud');
    $rTrig2 = $post('ordem-status.php', $lUsu, $form($oTrig, 'ativar', 'INATIVA'));
    afirmar('(reativar volta ao estado original)', queryDe(locOrdens($rTrig2))['msg'] === 'oc_ativada');

    // ---- UPDATE falha no externo (ERRO) sem vazar detalhe
    $ext->exec("CREATE TRIGGER trg_falha BEFORE UPDATE ON tb_ordens_coleta FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falha simulada SEGREDO-SQL'");
    @unlink($log);
    $rErr = $post('ordem-status.php', $lUsu, $form($oFail, 'inativar', 'ATIVA'));
    $aE = $ultima('OC_INATIVAR');
    $logErr = (string) @file_get_contents($log);
    $ext->exec('DROP TRIGGER trg_falha');
    afirmar('UPDATE externo falha => 503 com mensagem fixa, sem SQLSTATE/segredo/host na resposta, OC intacta', $rErr['status'] === 503 && str_contains($rErr['corpo'], 'Não foi possível consultar as ordens de coleta agora. Tente novamente em instantes.') && !preg_match('/SQLSTATE|SEGREDO-SQL|falha simulada|PDOException|Stack trace/i', $rErr['cru']) && $stOc($oFail)['status'] === 'ATIVA');
    afirmar('UPDATE externo falha => auditoria fechada como ERRO com motivo_oc=externo_indisponivel (nunca PENDENTE)', ($aE['resultado'] ?? '') === 'ERRO' && $detalheDe($aE) === 'status_de=ATIVA;status_para=INATIVA;motivo_oc=externo_indisponivel' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    afirmar('UPDATE externo falha => log do PHP so com a classe da excecao (sem mensagem, SQL, numero, CNPJ) e LogSistema banco_coletas_indisponivel (so a classe)', str_contains($logErr, 'gestao: oc_externo_falhou App\Dao\OrdemColetaGestaoException') && !preg_match('/SEGREDO-SQL|SQLSTATE|falha simulada|UPDATE tb_ordens|OC-FAIL/', $logErr) && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'banco_coletas_indisponivel' AND detalhe = 'classe=App\\\\Dao\\\\OrdemColetaGestaoException'") >= 1);

    // ---- AUDITORIA INDISPONIVEL: nada e executado
    $ext->exec('CREATE TRIGGER trg_cont_upd BEFORE UPDATE ON tb_ordens_coleta FOR EACH ROW INSERT INTO probe_upd VALUES (NULL)');
    $renomear('tb_gestao_auditoria', 'tb_gestao_auditoria_qa_off');
    try {
        $rAud = $post('ordem-status.php', $lUsu, $form($oAud, 'inativar', 'ATIVA'));
        $rAudC = $post('ordem-status.php', $lUsu, $form($oAnd, 'ativar', 'INATIVA', ['confirmar' => '1']));
    } finally {
        $renomear('tb_gestao_auditoria_qa_off', 'tb_gestao_auditoria');
    }
    afirmar('auditoria nao abre => 503 fixo, NENHUM UPDATE foi emitido (sonda zerada) e a OC segue ATIVA', $rAud['status'] === 503 && str_contains($rAud['corpo'], 'trilha de auditoria') && (int) gtEscalar($ext, 'SELECT COUNT(*) FROM probe_upd') === 0 && $stOc($oAud)['status'] === 'ATIVA' && !preg_match('/SQLSTATE|tb_gestao_auditoria|PDOException/i', $rAud['cru']));
    afirmar('auditoria nao abre, tambem no 2o passo confirmado => 503 e OC intacta', $rAudC['status'] === 503 && $stOc($oAnd)['status'] === 'INATIVA' && (int) gtEscalar($ext, 'SELECT COUNT(*) FROM probe_upd') === 0);
    afirmar('auditoria nao abre: LogSistema auditoria_falhou registrado e error_log so com a classe', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'auditoria_falhou'") >= 1 && str_contains((string) @file_get_contents($log), 'gestao: oc_auditoria_abrir_falhou PDOException') && !preg_match('/Base table|tb_gestao_auditoria_qa_off|doesn\'t exist/i', (string) @file_get_contents($log)));
    $ext->exec('DROP TRIGGER trg_cont_upd');
    $rAudOk = $post('ordem-status.php', $lUsu, $form($oAud, 'inativar', 'ATIVA'));
    afirmar('com a auditoria de volta a acao funciona normalmente', queryDe(locOrdens($rAudOk))['msg'] === 'oc_inativada' && $stOc($oAud)['status'] === 'INATIVA');

    // ------------------------------------------------------------------
    // E. Baixas pendentes (F4c)
    // ------------------------------------------------------------------
    $pB = $get('ordens.php', $lUsu, ['aba' => 'baixas']);
    $cb = $pB['corpo'];
    $esperaBaixas = static fn (string $where, int $limite, int $offset = 0, array $p = []): array => array_map('intval', array_column(gtLinhas($pdo, "SELECT b.id FROM tb_ordem_coleta_pendente_baixa b INNER JOIN tb_atendimento a ON a.id_atendimento = b.id_atendimento $where ORDER BY b.criado_em DESC, b.id DESC LIMIT $limite OFFSET $offset", $p), 'id'));
    afirmar('baixas: 200, aba atual, filtro Mostrar (padrao Pendentes), sem filtros de cliente/numero, tabela baixas-tabela com 25 linhas ordenadas por criada em DESC', $pB['status'] === 200 && preg_match('/id="aba-baixas"[^>]*aria-current="page"/', $cb) === 1 && str_contains($cb, 'id="filtro-baixas-mostrar"') && str_contains($cb, '<option value="pendentes" selected>') && !str_contains($cb, 'id="filtro-cliente"') && !str_contains($cb, 'id="filtro-numero"') && str_contains($cb, 'id="baixas-tabela"') && idsBaixas($cb) === $esperaBaixas('WHERE b.resolvido_em IS NULL', 25));
    afirmar('baixas: colunas do contrato (col-atendimento, col-numero-oc, col-baixa-criada, col-baixa-situacao, col-acoes), contador "Exibindo 1 a 25 de ' . $totalPendentes . ' baixas." e paginacao', str_contains($cb, 'class="col-atendimento"') && str_contains($cb, 'class="col-numero-oc"') && str_contains($cb, 'class="col-baixa-criada"') && str_contains($cb, 'class="col-baixa-situacao"') && str_contains($cb, 'Exibindo 1 a 25 de ' . $totalPendentes . ' baixas.') && str_contains($cb, 'id="pag-proxima" href="') && str_contains($cb, 'Página 1 de ' . (int) ceil($totalPendentes / 25)) && !str_contains($cb, 'id="ordens-aviso-retencao"') && str_contains($cb, '>Número da ordem</th>') && !str_contains($cb, 'Número da OC') && str_contains($c, 'id="ordens-aviso-retencao"'));
    $pB2 = $get('ordens.php', $lUsu, ['aba' => 'baixas', 'pagina' => '2']);
    afirmar('baixas: pagina 2 tem o restante sem repetir as linhas da 1', idsBaixas($pB2['corpo']) === $esperaBaixas('WHERE b.resolvido_em IS NULL', 25, 25) && array_intersect(idsBaixas($cb), idsBaixas($pB2['corpo'])) === [] && str_contains($pB2['corpo'], 'Exibindo 26 a ' . $totalPendentes . ' de ' . $totalPendentes . ' baixas.'));
    $rBP = $get('ordens.php', $lUsu, ['aba' => 'baixas', 'pagina' => '99']);
    afirmar('baixas: pagina alem do fim => 302 para a ultima (' . (int) ceil($totalPendentes / 25) . ')', $rBP['status'] === 302 && queryDe(locOrdens($rBP))['pagina'] === (string) (int) ceil($totalPendentes / 25) && queryDe(locOrdens($rBP))['aba'] === 'baixas');
    $linhaBaixa = static function (string $corpo, int $id): string {
        return (string) preg_replace('/\A.*?(<tr class="gestao-tabela__linha" data-id-baixa="' . $id . '".*?<\/tr>).*\z/s', '$1', $corpo);
    };
    $todasPendentes = '';
    foreach ([$cb, $pB2['corpo']] as $corpoB) {
        $todasPendentes .= $corpoB;
    }
    $lResolve = $linhaBaixa($todasPendentes, $bResolve);
    $atResolve = (int) gtEscalar($pdo, 'SELECT id_atendimento FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $bResolve]);
    afirmar('baixa com OC localizada: "#atendimento", numero, "Pendente", link "Abrir ordem" para ordem.php?id=<id da OC> (so cliente+numero) e botao Marcar como resolvida (form POST com CSRF/id_baixa/retorno)', str_contains($lResolve, 'data-resolvida="0"') && str_contains($lResolve, '#' . $atResolve) && str_contains($lResolve, 'OC-2001') && str_contains($lResolve, 'Pendente') && str_contains($lResolve, 'id="btn-baixa-abrir-' . $bResolve . '" href="/gestao/ordem.php?id=' . $oI1 . '&amp;aba=baixas') && str_contains($lResolve, 'id="form-baixa-resolver-' . $bResolve . '" method="post" action="/gestao/ordem-baixa.php"') && str_contains($lResolve, 'name="csrf_token"') && str_contains($lResolve, 'name="id_baixa" value="' . $bResolve . '"') && str_contains($lResolve, 'name="retorno" value="aba=baixas') && str_contains($lResolve, 'id="btn-baixa-resolver-' . $bResolve . '"'));
    afirmar('baixa sem cliente (cnpj nulo) e baixa com OC inexistente: "Ordem não localizada" (com icone de alerta) e SEM link para abrir', str_contains($linhaBaixa($todasPendentes, $bSemCnpj), '<span>Ordem não localizada</span>') && str_contains($linhaBaixa($todasPendentes, $bSemCnpj), '#i-alerta') && !str_contains($linhaBaixa($todasPendentes, $bSemCnpj), 'btn-baixa-abrir-') && str_contains($linhaBaixa($todasPendentes, $bNaoExiste), 'Ordem não localizada') && !str_contains($linhaBaixa($todasPendentes, $bNaoExiste), 'btn-baixa-abrir-') && !str_contains($todasPendentes, 'OC não localizada'));
    afirmar('baixa ambigua (2 OCs com o mesmo cliente+numero): "Mais de uma ordem com este número" e SEM link', str_contains($linhaBaixa($todasPendentes, $bAmb), 'Mais de uma ordem com este número') && !str_contains($linhaBaixa($todasPendentes, $bAmb), 'btn-baixa-abrir-') && !str_contains($todasPendentes, 'OC ambígua'));
    afirmar('baixa com numero hostil: escapado e linka a OC certa', !str_contains($todasPendentes, '<script>alert(7)') && str_contains($linhaBaixa($todasPendentes, $bHostil), '&lt;script&gt;alert(7)&lt;/script&gt;OC') && str_contains($linhaBaixa($todasPendentes, $bHostil), 'ordem.php?id=' . $oX));
    afirmar('ajuste 1: baixa pendente com OC NAO localizada (sem cliente / OC inexistente) ou AMBIGUA NAO renderiza o formulario nem o botao "Marcar como resolvida"', !str_contains($linhaBaixa($todasPendentes, $bSemCnpj), 'form-baixa-resolver-') && !str_contains($linhaBaixa($todasPendentes, $bSemCnpj), 'btn-baixa-resolver-') && !str_contains($linhaBaixa($todasPendentes, $bNaoExiste), 'form-baixa-resolver-') && !str_contains($linhaBaixa($todasPendentes, $bNaoExiste), 'btn-baixa-resolver-') && !str_contains($linhaBaixa($todasPendentes, $bAmb), 'form-baixa-resolver-') && !str_contains($linhaBaixa($todasPendentes, $bAmb), 'btn-baixa-resolver-') && !str_contains($linhaBaixa($todasPendentes, $bAmb), 'Marcar como resolvida') && str_contains($linhaBaixa($todasPendentes, $bAmb), 'id="baixa-oc-estado-' . $bAmb . '"') && str_contains($linhaBaixa($todasPendentes, $bSemCnpj), 'id="baixa-oc-estado-' . $bSemCnpj . '"'));
    afirmar('ajuste 1: OC unica mantem o botao (INATIVA e tambem ATIVA: o servidor recusa com oc_baixa_recusada_oc_ativa); nenhuma linha sem "Abrir" tem formulario de resolver', str_contains($linhaBaixa($todasPendentes, $bAtiva), 'id="form-baixa-resolver-' . $bAtiva . '"') && str_contains($linhaBaixa($todasPendentes, $bHostil), 'id="btn-baixa-resolver-' . $bHostil . '"') && substr_count($todasPendentes, 'id="form-baixa-resolver-') === substr_count($todasPendentes, 'id="btn-baixa-abrir-'));
    afirmar('ajuste 2: resolver baixa continua SEM confirmacao (um clique): nenhum data-confirmar no formulario/botao e o servidor resolve direto sem confirmar', !str_contains($todasPendentes, 'data-confirmar') && !str_contains($lResolve, 'confirmar'));
    $pBRes = $get('ordens.php', $lUsu, ['aba' => 'baixas', 'mostrar' => 'resolvidas']);
    afirmar('Mostrar=resolvidas: so a baixa resolvida, "Resolvida em dd/mm/aaaa hh:mm", data-resolvida=1 e SEM botao de resolver', idsBaixas($pBRes['corpo']) === [$bFeita] && str_contains($pBRes['corpo'], 'data-resolvida="1"') && str_contains($pBRes['corpo'], 'Resolvida em <time') && !str_contains($pBRes['corpo'], 'btn-baixa-resolver-') && str_contains($pBRes['corpo'], '<option value="resolvidas" selected>') && str_contains($pBRes['corpo'], 'Exibindo 1 a 1 de 1 baixa.'));
    $pBTodas = $get('ordens.php', $lUsu, ['aba' => 'baixas', 'mostrar' => 'todas']);
    afirmar('Mostrar=todas: pendentes + resolvidas (total ' . ($totalPendentes + 1) . ')', str_contains($pBTodas['corpo'], 'de ' . ($totalPendentes + 1) . ' baixas.'));
    foreach (['qualquer', 'TODAS', "todas' OR '1'='1", '0'] as $mv) {
        afirmar('mostrar invalido ' . json_encode($mv) . ' => pendentes', idsBaixas($get('ordens.php', $lUsu, ['aba' => 'baixas', 'mostrar' => $mv])['corpo']) === idsBaixas($cb));
    }
    $pBPer = $get('ordens.php', $lUsu, ['aba' => 'baixas', 'mostrar' => 'todas', 'de' => date('Y-m-d', time() - 10 * 86400), 'ate' => date('Y-m-d')]);
    afirmar('periodo (de/ate sobre a criacao da baixa): a baixa de 40 dias fica de fora; sem erro', !in_array($bFeita, idsBaixas($pBPer['corpo']), true) && str_contains($pBPer['corpo'], 'id="filtro-criada-de"') && !str_contains($pBPer['corpo'], 'SQLSTATE'));
    $pBVazio = $get('ordens.php', $lUsu, ['aba' => 'baixas', 'de' => '2001-01-01', 'ate' => '2001-01-02']);
    afirmar('baixas sem resultado: #baixas-vazio e "Nenhuma baixa."', str_contains($pBVazio['corpo'], 'id="baixas-vazio"') && str_contains($pBVazio['corpo'], 'Nenhuma baixa.') && !str_contains($pBVazio['corpo'], 'id="baixas-tabela"'));
    foreach (['2026-02-30', '<script>', 'x'] as $dt) {
        $pbd = $get('ordens.php', $lUsu, ['aba' => 'baixas', 'de' => $dt]);
        afirmar('baixas: data invalida ' . json_encode($dt) . ' ignorada com mensagem no campo', $pbd['status'] === 200 && str_contains($pbd['corpo'], 'id="erro-criada-de"') && idsBaixas($pbd['corpo']) === idsBaixas($cb));
    }

    // resolver
    $resolvidoAntes = gtEscalar($pdo, 'SELECT resolvido_em FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $bResolve]);
    $rR1 = $post('ordem-baixa.php', $lUsu, ['id_baixa' => (string) $bResolve, 'retorno' => 'aba=baixas&mostrar=pendentes&pagina=2']);
    $aR = $ultima('OC_BAIXA_RESOLVER');
    afirmar('resolver (OC INATIVA): 302 para a aba Baixas (retorno validado) com msg=oc_baixa_resolvida; resolvido_em gravado', $rR1['status'] === 302 && locOrdens($rR1) === '/gestao/ordens.php?aba=baixas&pagina=2&msg=oc_baixa_resolvida' && gtEscalar($pdo, 'SELECT resolvido_em FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $bResolve]) !== null && $resolvidoAntes === null);
    afirmar('auditoria OC_BAIXA_RESOLVER: OK, alvo oc_baixa + id da baixa, sem detalhe (sem numero/CNPJ)', ($aR['resultado'] ?? '') === 'OK' && $aR['alvo_tipo'] === 'oc_baixa' && (int) $aR['alvo_id'] === $bResolve && $detalheDe($aR) === '' && (int) $aR['id_usuario'] === $idUsu);
    $quando = gtEscalar($pdo, 'SELECT resolvido_em FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $bResolve]);
    $rR2 = $post('ordem-baixa.php', $lAdm, ['id_baixa' => (string) $bResolve]);
    afirmar('idempotencia: resolver de novo => msg=oc_baixa_ja_resolvida, resolvido_em intacto, auditoria SEM_EFEITO ja_no_estado', queryDe(locOrdens($rR2))['msg'] === 'oc_baixa_ja_resolvida' && gtEscalar($pdo, 'SELECT resolvido_em FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $bResolve]) === $quando && ($ultima('OC_BAIXA_RESOLVER')['resultado'] ?? '') === 'SEM_EFEITO' && $detalheDe($ultima('OC_BAIXA_RESOLVER')) === 'motivo_oc=ja_no_estado');
    $recusas = [
        [$bAtiva, 'oc_ativa', 'OC ainda ATIVA'],
        [$bSemCnpj, 'cliente_ausente', 'atendimento sem cliente'],
        [$bNaoExiste, 'oc_inexistente', 'OC inexistente'],
        [$bAmb, 'oc_ambigua', 'OC ambigua (2 OCs)'],
        [$bHostil, 'oc_ativa', 'OC ativa com numero hostil'],
    ];
    foreach ($recusas as [$idb, $motivo, $rotulo]) {
        $rr = $post('ordem-baixa.php', $lUsu, ['id_baixa' => (string) $idb]);
        $au = $ultima('OC_BAIXA_RESOLVER');
        afirmar("recusa ($rotulo): msg=oc_baixa_recusada_$motivo, baixa continua pendente, auditoria SEM_EFEITO com motivo_oc=$motivo", queryDe(locOrdens($rr))['msg'] === 'oc_baixa_recusada_' . $motivo && gtEscalar($pdo, 'SELECT resolvido_em FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $idb]) === null && ($au['resultado'] ?? '') === 'SEM_EFEITO' && $detalheDe($au) === 'motivo_oc=' . $motivo && (int) $au['alvo_id'] === $idb);
    }
    afirmar('resolver NAO altera nenhuma OC (so marca a pendencia)', $stOc($oA2)['status'] === 'ATIVA' && $stOc($oX)['status'] === 'ATIVA');
    $rR404 = $post('ordem-baixa.php', $lUsu, ['id_baixa' => '9999999']);
    afirmar('baixa inexistente => msg=oc_baixa_nao_encontrada e auditoria SEM_EFEITO oc_inexistente', queryDe(locOrdens($rR404))['msg'] === 'oc_baixa_nao_encontrada' && $detalheDe($ultima('OC_BAIXA_RESOLVER')) === 'motivo_oc=oc_inexistente');
    $antesB = $nAudit();
    foreach (['abc', '0', '-1', '1 OR 1=1', str_repeat('9', 30), '', ' 5', "5\n"] as $ib) {
        $rh = $post('ordem-baixa.php', $lUsu, ['id_baixa' => $ib]);
        afirmar('id_baixa hostil ' . json_encode(substr($ib, 0, 20)) . ' => 400 sem auditoria e sem efeito', $rh['status'] === 400 && $nAudit() === $antesB);
    }
    $rhArr = $post('ordem-baixa.php', $lUsu, ['id_baixa' => ['1']]);
    afirmar('id_baixa em array => 400', $rhArr['status'] === 400);
    $renomear('tb_gestao_auditoria', 'tb_gestao_auditoria_qa_off');
    try {
        $rBAud = $post('ordem-baixa.php', $lUsu, ['id_baixa' => (string) $bAud]);
    } finally {
        $renomear('tb_gestao_auditoria_qa_off', 'tb_gestao_auditoria');
    }
    afirmar('auditoria nao abre => 503 fixo e a baixa NAO e marcada', $rBAud['status'] === 503 && gtEscalar($pdo, 'SELECT resolvido_em FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $bAud]) === null && !preg_match('/SQLSTATE|PDOException/', $rBAud['cru']));
    $rBMut = $post('ordem-baixa.php', $lUsu, ['id_baixa' => (string) $bAud, 'retorno' => 'http://evil.example/']);
    afirmar('com a OC-2001 INATIVA a segunda baixa do mesmo numero (outro atendimento) resolve; retorno hostil ignorado', queryDe(locOrdens($rBMut))['msg'] === 'oc_baixa_resolvida' && str_starts_with(locOrdens($rBMut), '/gestao/ordens.php?aba=baixas') && !str_contains(locOrdens($rBMut), 'evil'));
    $post('ordem-status.php', $lUsu, $form($oA2, 'inativar', 'ATIVA'));
    $rBAtivaAgora = $post('ordem-baixa.php', $lUsu, ['id_baixa' => (string) $bAtiva]);
    afirmar('apos INATIVAR a OC-1002, a baixa dela passa a poder ser resolvida (so manual e so com a OC inativa)', queryDe(locOrdens($rBAtivaAgora))['msg'] === 'oc_baixa_resolvida' && gtEscalar($pdo, 'SELECT resolvido_em FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $bAtiva]) !== null);

    // ------------------------------------------------------------------
    // F. PDF (F4d)
    // ------------------------------------------------------------------
    $nAuditPdf = $nAudit();
    $rPdf = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oA1]);
    $cabs = $rPdf['cabecalhos'];
    afirmar('PDF: 200, corpo IDENTICO ao arquivo (mesmos bytes, incl. CR/LF/binario), Content-Length = bytes enviados', $rPdf['status'] === 200 && $rPdf['corpo'] === $pdfBytes && gtCabecalho($rPdf, 'content-length') === (string) strlen($pdfBytes));
    afirmar('PDF: cabecalhos exatos (Content-Type, nosniff, no-store+private, Pragma, attachment com id da OC, CSP restritiva, X-Frame-Options, Referrer-Policy, Accept-Ranges)', gtCabecalho($rPdf, 'content-type') === 'application/pdf' && gtCabecalho($rPdf, 'x-content-type-options') === 'nosniff' && gtCabecalho($rPdf, 'cache-control') === 'no-store, private' && gtCabecalho($rPdf, 'pragma') === 'no-cache' && gtCabecalho($rPdf, 'content-disposition') === 'attachment; filename="oc-' . $oA1 . '.pdf"' && gtCabecalho($rPdf, 'content-security-policy') === "default-src 'none'; sandbox; frame-ancestors 'none'" && gtCabecalho($rPdf, 'x-frame-options') === 'DENY' && gtCabecalho($rPdf, 'referrer-policy') === 'no-referrer' && gtCabecalho($rPdf, 'accept-ranges') === 'none');
    afirmar('PDF: cada cabecalho de seguranca aparece UMA vez (o da gestao foi SUBSTITUIDO, nao duplicado)', count($cabs['content-security-policy']) === 1 && count($cabs['cache-control']) === 1 && count($cabs['referrer-policy']) === 1 && count($cabs['x-content-type-options']) === 1 && count($cabs['content-type']) === 1);
    afirmar('PDF: nome do arquivo so tem o id numerico da OC (nunca numero, CNPJ ou cliente)', !str_contains((string) gtCabecalho($rPdf, 'content-disposition'), 'OC-1001') && !str_contains((string) gtCabecalho($rPdf, 'content-disposition'), $cnpjA));
    $aP = $ultima('OC_VER_PDF');
    afirmar('auditoria OC_VER_PDF: OK, alvo ordem_coleta + id, usuario da sessao, sem detalhe', ($aP['resultado'] ?? '') === 'OK' && $aP['alvo_tipo'] === 'ordem_coleta' && (int) $aP['alvo_id'] === $oA1 && (int) $aP['id_usuario'] === $idUsu && $detalheDe($aP) === '' && $nAudit() === $nAuditPdf + 1);
    $rPdfAdm = $post('ordem-pdf.php', $lAdm, ['id_ordem' => (string) $oI1]);
    afirmar('admin abre o PDF de OC INATIVA ainda retida (OC-2001) e a auditoria registra o admin', $rPdfAdm['status'] === 200 && $rPdfAdm['corpo'] === $pdfBytes && (int) $ultima('OC_VER_PDF')['id_usuario'] === $idAdmin && (int) $ultima('OC_VER_PDF')['alvo_id'] === $oI1);
    afirmar('PDF de OC com o MESMO numero em outro cliente: cada id abre o seu (B tem o proprio PDF)', $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oB1])['corpo'] === $pdfBytes);

    // 404 uniforme
    $nAuditAntes404 = $nAudit();
    $r404a = $post('ordem-pdf.php', $lUsu, ['id_ordem' => '999999']);
    $r404b = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oSem]);
    $r404c = $post('ordem-pdf.php', $lUsu, ['id_ordem' => 'abc']);
    $r404d = $post('ordem-pdf.php', $lUsu, ['id_ordem' => '0']);
    $r404e = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oI2]);
    afirmar('id inexistente, OC SEM anexo, OC INATIVA sem anexo, id invalido: MESMA resposta 404 (mesmo corpo), sem auditoria e sem bytes de PDF', $r404a['status'] === 404 && $r404b['status'] === 404 && $r404c['status'] === 404 && $r404d['status'] === 404 && $r404e['status'] === 404 && $r404a['corpo'] === $r404b['corpo'] && $r404a['corpo'] === $r404c['corpo'] && $r404a['corpo'] === $r404d['corpo'] && $r404a['corpo'] === $r404e['corpo'] && $nAudit() === $nAuditAntes404 && !str_contains($r404a['cru'], '%PDF'));
    foreach (['1 OR 1=1', str_repeat('9', 30), '-1', ' 1', '01'] as $ih) {
        afirmar('id_ordem hostil ' . json_encode(substr($ih, 0, 20)) . ' no PDF => a mesma 404', $post('ordem-pdf.php', $lUsu, ['id_ordem' => $ih])['corpo'] === $r404a['corpo']);
    }
    $rPdfSemId = $post('ordem-pdf.php', $lUsu, []);
    $rPdfArr = $post('ordem-pdf.php', $lUsu, ['id_ordem' => [(string) $oA1]]);
    $rPdfCaminho = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oSem, 'caminho' => $cnpjA . '/OC-1001_20260101000000.pdf', 'caminho_relativo' => $cnpjA . '/OC-1001_20260101000000.pdf', 'arquivo' => 'OC-1001_20260101000000.pdf', 'numero' => 'OC-1001']);
    $rPdfCaminho2 = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oPAus, 'caminho' => $cnpjA . '/OC-1001_20260101000000.pdf', 'caminho_relativo' => $cnpjA . '/OC-1001_20260101000000.pdf', 'arquivo' => 'OC-1001_20260101000000.pdf', 'numero' => 'OC-1001']);
    afirmar('caminho/arquivo/numero vindos do request sao IGNORADOS mesmo numa OC com registro: o arquivo ausente do banco continua ausente (nunca serve o PDF de outra OC)', $rPdfCaminho2['status'] === 302 && !str_contains($rPdfCaminho2['cru'], '%PDF') && queryDe(locOrdens($rPdfCaminho2))['msg'] === 'oc_pdf_indisponivel');
    afirmar('sem id, id em array ou caminho/numero vindos do request (ignorados) => 404 uniforme, nunca serve outro arquivo', $rPdfSemId['corpo'] === $r404a['corpo'] && $rPdfArr['corpo'] === $r404a['corpo'] && $rPdfCaminho['status'] === 404 && $rPdfCaminho['corpo'] === $r404a['corpo'] && !str_contains($rPdfCaminho['cru'], '%PDF'));

    // arquivo ausente (retencao)
    $rAus = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oPAus]);
    $aAus = $ultima('OC_VER_PDF');
    afirmar('registro sem arquivo (apagado): 302 ao detalhe com msg=oc_pdf_indisponivel (mensagem de retencao), nenhum byte de PDF', $rAus['status'] === 302 && locOrdens($rAus) === '/gestao/ordem.php?id=' . $oPAus . '&msg=oc_pdf_indisponivel' && !str_contains($rAus['cru'], '%PDF') && gtCabecalho($rAus, 'content-type') !== 'application/pdf');
    afirmar('arquivo ausente: a auditoria foi ABERTA ANTES de abrir o arquivo e fechada SEM_EFEITO motivo_oc=arquivo_ausente', ($aAus['resultado'] ?? '') === 'SEM_EFEITO' && (int) $aAus['alvo_id'] === $oPAus && $detalheDe($aAus) === 'motivo_oc=arquivo_ausente');

    // integridade: resposta uniforme
    @unlink($log);
    $integr = [
        'nao e PDF (conteudo GIF com sha correto)' => $oPNao,
        'sha256 divergente' => $oPSha,
        'maior que 5 MiB' => $oPBig,
        'caminho com ../ adulterado no banco' => $oPTrav,
        'caminho que entra em pasta de outro cliente' => $oPTrav2,
        'S4: prefixo de CNPJ do caminho e de OUTRO cliente (sha256 valido do PDF dele)' => $oPPref,
    ];
    if ($symOk) {
        $integr['arquivo e symlink (alvo PDF valido fora da pasta)'] = $oPSym;
    } else {
        echo "AVISO - symlink indisponivel neste ambiente (Windows sem privilegio): caso do symlink coberto por teste_oc_anexo_logica\n";
    }
    $corposIntegr = [];
    foreach ($integr as $rotulo => $idOcI) {
        $antesPdfAud = count($audit('OC_VER_PDF'));
        $ri = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $idOcI]);
        $corposIntegr[] = $ri['corpo'];
        $ai = $ultima('OC_VER_PDF');
        afirmar("integridade ($rotulo): 500 com mensagem fixa, nenhum byte do arquivo, auditoria ERRO", $ri['status'] === 500 && str_contains($ri['corpo'], 'Não foi possível abrir o PDF desta ordem agora. Tente novamente em instantes.') && !str_contains($ri['cru'], '%PDF') && !str_contains($ri['corpo'], 'GIF89a') && gtCabecalho($ri, 'content-type') !== 'application/pdf' && count($audit('OC_VER_PDF')) === $antesPdfAud + 1 && ($ai['resultado'] ?? '') === 'ERRO' && (int) $ai['alvo_id'] === $idOcI);
    }
    afirmar('integridade: TODAS as falhas devolvem a MESMA resposta (nao revela qual verificacao falhou)', count(array_unique($corposIntegr)) === 1);
    $logIntegr = (string) @file_get_contents($log);
    $todosLogsBanco = json_encode(gtLinhas($pdo, 'SELECT categoria, mensagem, detalhe FROM tb_log_sistema')) . json_encode(gtLinhas($pdo, 'SELECT acao, alvo_tipo, alvo_id, resultado, detalhe FROM tb_gestao_auditoria'));
    $vazou = array_values(array_filter(array_merge($segredos, ['../../fora', 'segredo_2026', 'ordens_coleta', 'P-TRAV', 'P-SHA', 'P-NAOPDF', 'P-BIG', '_20260101000000.pdf']), static fn ($sx) => str_contains($logIntegr, $sx) || str_contains($todosLogsBanco, $sx) || str_contains(implode('|', $corposIntegr), $sx)));
    afirmar('NADA de caminho, CNPJ, numero de OC, nome de arquivo, dado pessoal ou STORAGE em log do PHP, tb_log_sistema, auditoria ou resposta de erro' . ($vazou !== [] ? ' VAZOU: ' . json_encode(array_map(static fn ($x) => substr($x, 0, 12), $vazou)) : ''), $vazou === []);
    $motivosLog = array_column(gtLinhas($pdo, "SELECT detalhe FROM tb_log_sistema WHERE categoria = 'gestao_erro_interno'"), 'detalhe');
    afirmar('integridade: error_log fixo (oc_pdf_integridade) e LogSistema gestao_erro_interno (so http/motivo)', str_contains($logIntegr, 'gestao: oc_pdf_integridade') && $motivosLog !== [] && count(array_filter($motivosLog, static fn ($d) => preg_match('/\Ahttp=500;motivo=[a-z_0-9]+\z/', (string) $d) !== 1)) === 0);
    foreach (['nao_e_pdf' => 'P-NAOPDF', 'sha256_divergente' => 'P-SHA', 'tamanho_invalido' => 'P-BIG', 'caminho_invalido' => 'P-SYM', 'prefixo_cnpj_divergente' => 'P-PREF e P-TRAV'] as $motLog => $origemLog) {
        if ($motLog === 'caminho_invalido' && !$symOk) {
            continue;
        }
        afirmar("S5: LogSistema gestao_erro_interno registra o motivo fixo $motLog (caso $origemLog), sem caminho/CNPJ/numero", in_array('http=500;motivo=' . $motLog, $motivosLog, true));
    }
    afirmar('S5: nenhuma falha de integridade cai mais no motivo generico falha_inesperada e o catalogo e FECHADO (6 motivos do PDF no enum de motivo)', !in_array('http=500;motivo=falha_inesperada', $motivosLog, true) && array_diff(\App\Controller\GestaoOrdemController::MOTIVOS_INTEGRIDADE_PDF, \Util\LogCatalogo::MOTIVOS) === [] && \Util\LogCatalogo::DOMINIO['motivo'][0] === 'enum' && !in_array('qualquer_coisa', \Util\LogCatalogo::MOTIVOS, true) && count(\App\Controller\GestaoOrdemController::MOTIVOS_INTEGRIDADE_PDF) === 6);

    // auditoria indisponivel => nenhum byte
    $renomear('tb_gestao_auditoria', 'tb_gestao_auditoria_qa_off');
    try {
        $rPdfAudOff = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oA1]);
    } finally {
        $renomear('tb_gestao_auditoria_qa_off', 'tb_gestao_auditoria');
    }
    afirmar('auditoria nao abre => 503 e NENHUM byte do PDF (sem %PDF, sem Content-Type pdf, sem attachment)', $rPdfAudOff['status'] === 503 && !str_contains($rPdfAudOff['cru'], '%PDF') && gtCabecalho($rPdfAudOff, 'content-type') !== 'application/pdf' && gtCabecalho($rPdfAudOff, 'content-disposition') === null && !str_contains($rPdfAudOff['corpo'], substr($pdfBytes, 10, 40)));
    afirmar('o PDF da raiz do storage nunca e acessivel por URL: STORAGE fora de public/ e nenhuma pasta ordens_coleta no webroot', !str_starts_with(str_replace('\\', '/', $storage), str_replace('\\', '/', $raiz . '/public')) && !is_dir($raiz . '/public/ordens_coleta') && !is_dir($raiz . '/public/gestao/ordens_coleta'));

    // ------------------------------------------------------------------
    // G. Banco externo FORA DO AR
    // ------------------------------------------------------------------
    $fora = ['cabecalhos' => ['QA_QR_FORCE_EXT_DB_NAME' => 'qa_qr_exclusivo_00000000']];
    @unlink($log);
    $snapStExt = $snapExt();
    $nAuditFora = $nAudit();
    $rF1 = $get('ordens.php', $lUsu, [], $fora);
    $rF2 = $get('ordem.php', $lUsu, ['id' => (string) $oA2], $fora);
    $rF3 = $post('ordem-status.php', $lUsu, $form($oA2, 'ativar', 'INATIVA'), $fora);
    $rF4 = $post('ordem-pdf.php', $lUsu, ['id_ordem' => (string) $oA1], $fora);
    $rF5 = $get('ordens.php', $lUsu, ['aba' => 'baixas'], $fora);
    $rF6 = $post('ordem-baixa.php', $lUsu, ['id_baixa' => (string) $bFalha], $fora);
    $logFora = (string) @file_get_contents($log);
    $cfgHost = qaQrConfiguracao()['host'];
    $vazFora = static fn (string $t): bool => (bool) preg_match('/Unknown database|SQLSTATE|qa_qr_exclusivo|Access denied|PDOException|Stack trace|getaddrinfo|mysql:/i', $t) || ($cfgHost !== 'localhost' && strlen($cfgHost) > 3 && str_contains($t, $cfgHost));
    afirmar('externo fora (lista): 503, #ordens-erro-carga role=alert com a mensagem fixa, sem tabela, SEM host/SQL/nome de banco', $rF1['status'] === 503 && str_contains($rF1['corpo'], 'id="ordens-erro-carga" role="alert"') && str_contains($rF1['corpo'], 'Não foi possível consultar as ordens de coleta agora. Tente novamente em instantes.') && !str_contains($rF1['corpo'], 'id="ordens-tabela"') && !$vazFora($rF1['cru']));
    afirmar('externo fora (detalhe): 503 com o erro fixo e link Voltar; aba Baixas tambem 503', $rF2['status'] === 503 && str_contains($rF2['corpo'], 'id="ordens-erro-carga"') && str_contains($rF2['corpo'], 'id="ordem-voltar"') && !$vazFora($rF2['cru']) && $rF5['status'] === 503 && str_contains($rF5['corpo'], 'ordens-erro-carga') && !$vazFora($rF5['cru']));
    afirmar('externo fora (ativar): 503 fixo, nada alterado, auditoria registrada como ERRO motivo_oc=externo_indisponivel (sem PENDENTE)', $rF3['status'] === 503 && str_contains($rF3['corpo'], 'Não foi possível consultar as ordens de coleta agora.') && !$vazFora($rF3['cru']) && $snapExt() === $snapStExt && ($ultima('OC_ATIVAR')['resultado'] ?? '') === 'ERRO' && $detalheDe($ultima('OC_ATIVAR')) === 'status_de=INATIVA;status_para=ATIVA;motivo_oc=externo_indisponivel' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    afirmar('externo fora (PDF): 503 fixo sem nenhum byte; sem auditoria de visualizacao (nada foi visto)', $rF4['status'] === 503 && !str_contains($rF4['cru'], '%PDF') && !$vazFora($rF4['cru']) && $nAudit() === $nAuditFora + 2);
    afirmar('externo fora (resolver baixa): recusada com msg=oc_baixa_recusada_externo_indisponivel, baixa pendente, auditoria ERRO motivo_oc=externo_indisponivel', queryDe(locOrdens($rF6))['msg'] === 'oc_baixa_recusada_externo_indisponivel' && gtEscalar($pdo, 'SELECT resolvido_em FROM tb_ordem_coleta_pendente_baixa WHERE id = :i', ['i' => $bFalha]) === null && ($ultima('OC_BAIXA_RESOLVER')['resultado'] ?? '') === 'ERRO' && $detalheDe($ultima('OC_BAIXA_RESOLVER')) === 'motivo_oc=externo_indisponivel');
    $logsFora = json_encode(gtLinhas($pdo, 'SELECT detalhe, mensagem FROM tb_log_sistema'));
    afirmar('externo fora: log do PHP sem host/SQL/nome do banco', !$vazFora($logFora));
    afirmar('externo fora: LogSistema banco_coletas_indisponivel registrado, so classe e SQLSTATE (sem mensagem, host ou nome de banco)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_log_sistema WHERE categoria = 'banco_coletas_indisponivel'") >= 1 && !preg_match('/Unknown database|qa_qr_exclusivo|Access denied|mysql:/i', $logsFora));
    afirmar('externo fora: o resto da gestao segue de pe (Minha conta 200)', $get('conta.php', $lUsu, [], $fora)['status'] === 200);

    // ------------------------------------------------------------------
    // H. Varredura de fonte e sentinelas finais
    // ------------------------------------------------------------------
    $fonteCtrl = $semComentarios($raiz . '/app/Controller/GestaoOrdemController.php');
    $fonteViews = (string) file_get_contents($raiz . '/app/Views/gestao/ordens.php') . (string) file_get_contents($raiz . '/app/Views/gestao/ordem.php');
    afirmar('fonte: controller sem SQL (SELECT/UPDATE/INSERT/DELETE/LIKE), sem Mascara/revelar, sem rate limit por usuario, sem acesso a disco (fopen/readfile/file_get_contents/STORAGE)', !preg_match('/\b(SELECT|UPDATE|INSERT|DELETE)\b|\bLIKE\b/', $fonteCtrl) && !preg_match('/Mascara|revelar|RateLimit|LimiteFalhas/i', $fonteCtrl) && !preg_match('/\b(fopen|readfile|file_get_contents|fpassthru|file_put_contents|unlink|STORAGE_PATH)\b/', $fonteCtrl));
    afirmar('fonte: o caminho do PDF so passa por OrdemColetaArquivoStorage::ler (sem concatenar caminho_relativo, sem "ordens_coleta/" no controller)', substr_count($fonteCtrl, 'caminho_relativo') === 1 && str_contains($fonteCtrl, "->ler(\$caminhoRegistro, self::PDF_MAX_BYTES, (string) \$registro['sha256'])") && str_contains($fonteCtrl, 'strncmp($caminhoRegistro, $cnpjOc, 14)') && !str_contains($fonteCtrl, 'ordens_coleta/') && !str_contains($fonteCtrl, "\$_POST['caminho") && !preg_match('/\$_(GET|POST|REQUEST)\[\'(caminho|arquivo|path)/', $fonteCtrl));
    afirmar('S3: a aba Baixas usa UMA consulta em lote (idsPorClienteNumeroLote) e nunca idsPorClienteNumero por linha; contagens nao sao repetidas', str_contains($fonteCtrl, 'idsPorClienteNumeroLote(') && !preg_match('/->idsPorClienteNumero\(/', $fonteCtrl) && str_contains($fonteCtrl, '? $totalBaixasPendentes') && str_contains($fonteCtrl, '$contagens[$f[\'aba\']]'));
    afirmar('S2: o cliente do filtro e validado por consulta direta (clientePorId), nunca pela lista limitada', str_contains($fonteCtrl, '$this->ordens->clientePorId((int) $clienteBruto)') && !str_contains($fonteCtrl, 'foreach ($clientes as $c)'));
    afirmar('fonte: ativar/inativar so por id (inativarPorId/ativarPorId) e nunca por numero; atendimentos por cliente+numero da OC lida do banco', str_contains($fonteCtrl, 'inativarPorId($id)') && str_contains($fonteCtrl, 'ativarPorId($id)') && !preg_match('/PorNumero/', $fonteCtrl));
    afirmar('fonte: paginas finas com metodos certos (ordens/ordem so GET; status/baixa/pdf so POST) e perfil usuario', substr_count((string) file_get_contents($raiz . '/public/gestao/ordens.php') . (string) file_get_contents($raiz . '/public/gestao/ordem.php'), "'usuario', ['metodos' => ['GET']]") === 2 && substr_count((string) file_get_contents($raiz . '/public/gestao/ordem-status.php') . (string) file_get_contents($raiz . '/public/gestao/ordem-baixa.php') . (string) file_get_contents($raiz . '/public/gestao/ordem-pdf.php'), "'usuario', ['metodos' => ['POST']]") === 3);
    afirmar('fonte: menu Ordens de coleta disponivel (perfil usuario) e home do usuario em /gestao/ordens.php (admin em usuarios.php)', in_array(['id' => 'ordens', 'rotulo' => 'Ordens de coleta', 'href' => '/gestao/ordens.php', 'perfil' => 'usuario', 'disponivel' => true], GestaoContexto::MENU, true) && GestaoContexto::paginaInicial('usuario') === '/gestao/ordens.php' && GestaoContexto::paginaInicial('admin') === '/gestao/usuarios.php' && in_array(['id' => 'anexos', 'rotulo' => 'Anexos órfãos', 'href' => '/gestao/anexos.php', 'perfil' => 'admin', 'disponivel' => false], GestaoContexto::MENU, true));
    $semH = [];
    preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $fonteViews, $mexp);
    foreach ($mexp[1] as $ex) {
        if (!preg_match('/\A(h\(|\(int\)|gestaoIcone\(|gestaoData\(|gestaoCsrfInput\(|\$idOrdem\b|\$idBaixa\b|\$classe\b|\$ehBaixas|\$a\[\'ativa\'\]|\$b\[\'resolvida\'\]|\$o\[\'tem_pdf\'\]|\$o\[\'idade_atencao\'\]|\$o\[\'situacao_slug\'\] === |\$ativa\b|\$confirmacao|\$idadeAtencao|\$total === |\$temFiltros|\$filtros\[\'[a-z_]+\'\] === |isset\(\$errosFiltro|\$b\[\'oc_estado\'\] === |\$b\[\'resolvida\'\])/', $ex)) {
            $semH[] = substr($ex, 0, 50);
        }
    }
    afirmar('fonte: toda saida de dado nas views passa por h()/(int)/helpers' . ($semH !== [] ? ' - SEM h(): ' . json_encode($semH) : ''), $semH === []);
    afirmar('fonte: views sem inline script/style/on*', !preg_match('/<script|<style|\sstyle=|\son[a-z]+=/i', $fonteViews));
    afirmar('fonte: so rawurlencode/http_build_query nos links e nenhum link montado com texto cru do usuario', !preg_match('/href="[^"]*<\?=\s*\$(_GET|_POST|filtros)/', $fonteViews) && str_contains($fonteCtrl, 'http_build_query($q'));

    $todosBanco = json_encode(gtLinhas($pdo, 'SELECT detalhe FROM tb_gestao_auditoria')) . json_encode(gtLinhas($pdo, 'SELECT categoria, mensagem, detalhe FROM tb_log_sistema'));
    $vazouGeral = array_values(array_filter($segredos, static fn ($sx) => str_contains($todosBanco, $sx)));
    afirmar('SENTINELAS: nenhum dado pessoal (razao, CNPJ, numero de OC, motorista, placa, CNH, codigo/token do totem, caminho) em tb_gestao_auditoria.detalhe nem tb_log_sistema' . ($vazouGeral !== [] ? ' VAZOU: ' . json_encode($vazouGeral) : ''), $vazouGeral === []);
    $locs = array_map(static fn ($r) => (string) gtCabecalho($r, 'location'), $todas);
    afirmar('nenhum Location de nenhuma resposta carrega CNPJ ou razao social', array_reduce($locs, static fn ($ok, $l) => $ok && !str_contains($l, $cnpjA) && !str_contains($l, $cnpjB) && !str_contains($l, 'ACME') && !str_contains($l, 'evil'), true));
    afirmar('auditoria: nenhuma linha ficou PENDENTE; so acoes OC_* do catalogo; IP gravado nas escritas', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao LIKE 'OC\\_%' AND ip IS NULL AND id_usuario IS NOT NULL") === 0);
    afirmar('nenhum 500 inesperado nem erro/trace de PHP nas respostas (so os 500 da integridade do PDF, com a mensagem fixa)', array_reduce($todas, static fn ($ok, $r) => $ok && ($r['status'] !== 500 || str_contains($r['corpo'], 'Não foi possível abrir o PDF desta ordem agora')) && !preg_match('/(Fatal error|Uncaught|Warning:|Notice:|Deprecated:|Stack trace|SQLSTATE)/', $r['cru']), true));

    // ------------------------------------------------------------------
    // I. Teto de contagem (mais de 10.000) e de pagina (400)
    // ------------------------------------------------------------------
    $digitos = '(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
    $ext->exec("INSERT INTO tb_ordens_coleta (numero_ordem_coleta, cliente_id, email_recebido_id, status, criado_em)
        SELECT CONCAT('BIG-', t.n), " . (int) $cC . ", 1, 'ATIVA', NOW() - INTERVAL 1 HOUR
        FROM (SELECT a.n + 10 * b.n + 100 * c.n + 1000 * d.n + 10000 * e.n AS n FROM $digitos a, $digitos b, $digitos c, $digitos d, $digitos e) t
        WHERE t.n < 10001");
    $pTeto = $get('ordens.php', $lUsu);
    afirmar('teto: aba mostra "mais de 10.000" e o contador "de mais de 10.000 ordens."', contagemAba($pTeto['corpo'], 'aba-ativas') === 'mais de 10.000' && str_contains($pTeto['corpo'], 'Exibindo 1 a 25 de mais de 10.000 ordens.') && str_contains($pTeto['corpo'], 'Página 1 de 400'));
    $rTeto = $get('ordens.php', $lUsu, ['pagina' => '401']);
    afirmar('teto de pagina: pagina 401 => 302 para a 400; a 400 abre com 25 linhas e Proxima desabilitada', $rTeto['status'] === 302 && queryDe(locOrdens($rTeto))['pagina'] === '400' && count(idsOrdens($get('ordens.php', $lUsu, ['pagina' => '400'])['corpo'])) === 25 && str_contains($get('ordens.php', $lUsu, ['pagina' => '400'])['corpo'], 'id="pag-proxima" aria-disabled="true"'));

    // ------------------------------------------------------------------
    // J. S2: mais de 2000 clientes (o select mostra 2000; o filtro NAO depende desse limite)
    // ------------------------------------------------------------------
    $ext->exec('START TRANSACTION');
    $stCli = $ext->prepare('INSERT INTO tb_clientes (razao_social, cnpj) VALUES (:r, :c)');
    for ($i = 1; $i <= 2100; $i++) {
        $stCli->execute(['r' => sprintf('ZZZ CLIENTE %04d', $i), 'c' => sprintf('9%013d', $i)]);
    }
    $ext->exec('COMMIT');
    $cUltimo = (int) gtEscalar($ext, "SELECT id FROM tb_clientes WHERE razao_social = 'ZZZ CLIENTE 2100'");
    $cForaDoLimite = (int) gtEscalar($ext, "SELECT id FROM tb_clientes ORDER BY razao_social ASC, id ASC LIMIT 1 OFFSET 2000");
    $oFora = ogOrdem($ext, $cUltimo, 'OC-FORA-2000', 'ATIVA', $dias(1));
    $pSelect = $get('ordens.php', $lUsu);
    $nOpcoes = substr_count($pSelect['corpo'], '<option value="');
    afirmar('S2: com 2100+ clientes o select renderiza so 2000 clientes (+ "Todos os clientes")', $pSelect['status'] === 200 && $nOpcoes === 2001 && !str_contains($pSelect['corpo'], 'ZZZ CLIENTE 2100'));
    $pFora = $get('ordens.php', $lUsu, ['cliente' => (string) $cUltimo]);
    afirmar('S2: cliente FORA dos 2000 primeiros do select continua filtrando (so a OC dele; nunca alarga a lista) e aparece selecionado no select', $pFora['status'] === 200 && idsOrdens($pFora['corpo']) === [$oFora] && str_contains($pFora['corpo'], '<option value="' . $cUltimo . '" selected>ZZZ CLIENTE 2100 (') && !str_contains($pFora['corpo'], 'id="erro-cliente"') && substr_count($pFora['corpo'], '<option value="') === 2002);
    $pForaOutro = $get('ordens.php', $lUsu, ['cliente' => (string) $cForaDoLimite]);
    afirmar('S2: cliente fora do limite SEM ordens: lista vazia com "Nenhuma ordem" (sem erro de cliente) e paginas seguintes tambem nao alargam', $pForaOutro['status'] === 200 && idsOrdens($pForaOutro['corpo']) === [] && !str_contains($pForaOutro['corpo'], 'id="erro-cliente"') && idsOrdens($get('ordens.php', $lUsu, ['cliente' => (string) $cUltimo, 'aba' => 'inativas'])['corpo']) === []);
    $pForaInexistente = $get('ordens.php', $lUsu, ['cliente' => (string) ($cUltimo + 100000)]);
    afirmar('S2: cliente inexistente mesmo com 2100+ clientes: vazio + mensagem no campo (nao alarga)', idsOrdens($pForaInexistente['corpo']) === [] && str_contains($pForaInexistente['corpo'], 'id="erro-cliente"'));
} catch (Throwable $e) {
    echo 'EXCECAO FATAL: ' . get_class($e) . "\n";
    if (getenv('QA_DEBUG')) {
        echo $e->getMessage() . ' @' . $e->getLine() . "\n";
    }
    $GLOBALS['gtFalhas']++;
} finally {
    ogLimpar($amb);
    gtDestruirAmbiente(null, $storage);
    @unlink($log);
}

exit(gtResumo('teste_gestao_ordens'));
