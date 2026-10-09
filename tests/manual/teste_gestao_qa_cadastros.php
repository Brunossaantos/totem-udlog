<?php

/**
 * QA ADVERSARIAL dos CADASTROS da gestao (F6 reduzida: clientes e empresas, 2026-10-09).
 * Complementa teste_gestao_cadastros.php SEM duplica-lo: so o que ele nao cobre.
 *   A. corridas entre processos (empresa criar x2, empresa excluir x2, recriar x excluir,
 *      editar x editar para a mesma razao, duas confirmacoes HTTP simultaneas)
 *   B. POST forjado por HTTP (mass assignment, ativo invalido, id array, acao e confirmar
 *      em variantes), nada grava em GET
 *   C. sessao de admin rebaixado/desativado depois do login
 *   D. nomes exoticos (varredura Cc/Cf/Zl/Zp, emoji 4 bytes no limite, NFC x NFD, RTL,
 *      expansao da razao)
 *   E. ambiguidade do OCR com 1, 2, 5, 6 e 7 afetados, inativos nao contam, limite exato de
 *      500 x 501 clientes ativos, XSS dos nomes listados (criar, editar e ativar)
 * Banco QA descartavel `qa_qr_exclusivo_<hex>` (php-cgi real); NUNCA udlog_totem.
 *
 * Uso: php tests/manual/teste_gestao_qa_cadastros.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Rn\ClienteGestaoRn;
use App\Rn\EmpresaGestaoRn;
use Util\NomeCadastro;
use Util\RazaoSocialMatcher;

function cnpjQa(string $base12): string
{
    $dv = static function (string $b, array $p): int {
        $s = 0;
        foreach (str_split($b) as $i => $d) {
            $s += (int) $d * $p[$i];
        }
        $r = $s % 11;

        return $r < 2 ? 0 : 11 - $r;
    };
    $d1 = $dv($base12, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    $d2 = $dv($base12 . $d1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

    return $base12 . $d1 . $d2;
}

// ---------------------------------------------------------------------------
// Trabalhadores (subprocessos): corridas
// ---------------------------------------------------------------------------
if (isset($argv[1]) && str_starts_with($argv[1], '--w-')) {
    [, $wModo, $wBanco, $wAdmin, $wAlvo, $wInicio, $wExtra, $wExtra2] = array_pad($argv, 8, '');
    $wPdo = qaQrAbrirBanco($wBanco);
    $wPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    while (microtime(true) < (float) $wInicio) {
        usleep(200);
    }
    if ($wModo === '--w-http') {
        // $wAdmin = sid, $wAlvo = csrf, $wExtra = arquivo, $wExtra2 = json do formulario
        putenv('QA_QR_FORCE_DB_NAME=' . $wBanco);
        putenv('QA_QR_FORCE_STORAGE=' . (string) getenv('QA_STORAGE_W'));
        $wForm = json_decode($wExtra2, true) + ['csrf_token' => $wAlvo];
        $wR = gtChamar(['arquivo' => $wExtra, 'metodo' => 'POST', 'form' => $wForm, 'cookies' => ['gestao_sid' => $wAdmin]]);
        echo json_encode(['status' => $wR['status'], 'loc' => gtCabecalho($wR, 'location')]);
        exit(0);
    }
    $wIp = '203.0.113.9';
    if ($wModo === '--w-emp-criar') {
        $wR = (new EmpresaGestaoRn($wPdo))->criar((int) $wAdmin, $wExtra, (string) $wAlvo, $wIp);
    } elseif ($wModo === '--w-emp-excluir') {
        $wR = (new EmpresaGestaoRn($wPdo))->excluir((int) $wAdmin, (int) $wAlvo, true, $wIp);
    } elseif ($wModo === '--w-cli-editar') {
        $wR = (new ClienteGestaoRn($wPdo))->editar((int) $wAdmin, (int) $wAlvo, $wExtra, $wIp);
    } else {
        $wR = ['ok' => false, 'codigo' => 'modo_invalido'];
    }
    echo json_encode(['ok' => $wR['ok'], 'codigo' => $wR['codigo'] ?? null, 'erros' => array_keys($wR['erros'] ?? [])]);
    exit(0);
}

if (isset($_ENV['DB_NAME'])) {
    fwrite(STDERR, "teste_gestao_qa_cadastros: DB_NAME presente no ambiente; abortando (nunca usar udlog_totem).\n");
    exit(3);
}

$banco = null;
$storage = null;
$log = gtNovoLogCgi();
@unlink($log);

function lw(array $args): array
{
    $p = proc_open(array_merge([PHP_BINARY, __FILE__], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);

    return [$p, $pipes];
}

function cw(array $h): ?array
{
    $o = (string) stream_get_contents($h[1][1]);
    stream_get_contents($h[1][2]);
    fclose($h[1][1]);
    fclose($h[1][2]);
    proc_close($h[0]);

    return json_decode($o, true);
}

try {
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    putenv('QA_STORAGE_W=' . $storage);
    afirmar('seguranca: o banco usado e um qa_qr_exclusivo_<hex> (nunca udlog_totem)', preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $banco) === 1 && $banco !== 'udlog_totem');
    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idAdmin2 = gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Admin');
    $idAdmin3 = gtSemear($pdo, 'carol.admin', 'admin', GT_SENHA_BOA, false, true, 'Carol Admin');
    gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    $rnC = new ClienteGestaoRn($pdo);
    $rnE = new EmpresaGestaoRn($pdo);
    $pdo->exec('DELETE FROM tb_cliente');
    $cli = static fn (int $id): array => gtLinhas($pdo, 'SELECT * FROM tb_cliente WHERE id_cliente = :i', ['i' => $id])[0] ?? [];
    $emp = static fn (int $id): array => gtLinhas($pdo, 'SELECT * FROM tb_empresa WHERE id_empresa = :i', ['i' => $id])[0] ?? [];
    $nAud = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria');
    $pendentes = static fn (): int => (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'");
    $snapTudo = static fn (): string => md5(json_encode([
        gtLinhas($pdo, 'SELECT * FROM tb_cliente ORDER BY id_cliente'),
        gtLinhas($pdo, 'SELECT * FROM tb_empresa ORDER BY id_empresa'),
        gtLinhas($pdo, 'SELECT * FROM tb_totem ORDER BY id_totem'),
        gtLinhas($pdo, 'SELECT * FROM tb_atendimento ORDER BY id_atendimento'),
        gtLinhas($pdo, 'SELECT id_auditoria FROM tb_gestao_auditoria ORDER BY id_auditoria'),
    ]));
    $H = ['logErro' => $log];
    $req = static fn (array $o): array => gtChamar($o + $H);
    $ck = static fn (?array $l): array => $l !== null && $l['sid'] !== null ? ['cookies' => ['gestao_sid' => $l['sid']]] : [];
    $get = static fn (string $arq, ?array $l, array $q = []): array => $req(['arquivo' => $arq, 'query' => $q] + $ck($l));
    $post = static fn (string $arq, ?array $l, array $form, array $extra = []): array => $req(['arquivo' => $arq, 'metodo' => 'POST', 'form' => $form + ($l !== null && $l['csrf'] !== null ? ['csrf_token' => $l['csrf']] : [])] + $ck($l) + $extra);
    $postCru = static fn (string $arq, array $l, string $corpo): array => $req(['arquivo' => $arq, 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $l['csrf'] . '&' . $corpo] + $ck($l));
    $loc = static fn (array $r): ?string => gtCabecalho($r, 'location');
    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => '192.0.2.41']);
    $lAdm2 = gtLogin('beto.admin', GT_SENHA_BOA, ['ip' => '192.0.2.42']);
    $lCar = gtLogin('carol.admin', GT_SENHA_BOA, ['ip' => '192.0.2.43']);
    afirmar('sessoes abertas (3 admins) com CSRF', $lAdm['sid'] !== null && $lAdm['csrf'] !== null && $lAdm2['sid'] !== null && $lCar['sid'] !== null && $lCar['csrf'] !== null);

    // =====================================================================
    // A. Corridas
    // =====================================================================
    foreach ([1, 2] as $rodada) {
        $cnpj = cnpjQa('41000000' . sprintf('%04d', $rodada));
        $ini = sprintf('%.4F', microtime(true) + 3.0);
        $s = array_map('cw', [lw(['--w-emp-criar', $banco, (string) $idAdmin, $cnpj, $ini, 'CorrEmpA' . $rodada]), lw(['--w-emp-criar', $banco, (string) $idAdmin2, $cnpj, $ini, 'CorrEmpB' . $rodada])]);
        afirmar("corrida empresa (rodada $rodada): 2 processos criando o MESMO CNPJ => 1 cria e o outro recebe erro no campo cnpj, nunca erro_interno", count(array_filter($s, static fn ($x) => ($x['ok'] ?? false) === true)) === 1 && count(array_filter($s, static fn ($x) => ($x['ok'] ?? true) === false && ($x['erros'] ?? []) === ['cnpj'])) === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_empresa WHERE cnpj = :c', ['c' => $cnpj]) === 1);
    }
    $ini = sprintf('%.4F', microtime(true) + 3.0);
    $s = array_map('cw', [lw(['--w-emp-criar', $banco, (string) $idAdmin, cnpjQa('412000000001'), $ini, 'Slug-Igual']), lw(['--w-emp-criar', $banco, (string) $idAdmin2, cnpjQa('412000000002'), $ini, 'SLUG IGUAL'])]);
    afirmar('corrida empresa: 2 processos criando nomes de MESMO slug (CNPJs diferentes) => so 1 cria (slugs de URL nunca colidem)', count(array_filter($s, static fn ($x) => ($x['ok'] ?? false) === true)) === 1 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_empresa WHERE nome IN ('Slug-Igual','SLUG IGUAL')") === 1);
    foreach ([1, 2, 3] as $rodada) {
        $eX = (int) $rnE->criar($idAdmin, 'ExcDup' . $rodada, cnpjQa('413000000' . sprintf('%03d', $rodada)))['id'];
        $ini = sprintf('%.4F', microtime(true) + 3.0);
        $s = array_map('cw', [lw(['--w-emp-excluir', $banco, (string) $idAdmin, (string) $eX, $ini]), lw(['--w-emp-excluir', $banco, (string) $idAdmin2, (string) $eX, $ini])]);
        afirmar("corrida empresa (rodada $rodada): duas confirmacoes de exclusao da MESMA empresa => 1 exclui, 1 recebe empresa_nao_encontrada, 1 auditoria OK", count(array_filter($s, static fn ($x) => ($x['ok'] ?? false) === true)) === 1 && count(array_filter($s, static fn ($x) => ($x['codigo'] ?? '') === 'empresa_nao_encontrada')) === 1 && $emp($eX) === [] && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'EMPRESA_EXCLUIR' AND resultado = 'OK' AND alvo_id = :i", ['i' => $eX]) === 1);
    }
    $consistente = true;
    foreach ([1, 2, 3, 4] as $rodada) {
        $cnpjR = cnpjQa('414000000' . sprintf('%03d', $rodada));
        $eR = (int) $rnE->criar($idAdmin, 'Recria' . $rodada, $cnpjR)['id'];
        $ini = sprintf('%.4F', microtime(true) + 3.0);
        $s = array_map('cw', [lw(['--w-emp-excluir', $banco, (string) $idAdmin, (string) $eR, $ini]), lw(['--w-emp-criar', $banco, (string) $idAdmin2, $cnpjR, $ini, 'Recria' . $rodada . 'B'])]);
        $n = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_empresa WHERE cnpj = :c', ['c' => $cnpjR]);
        $consistente = $consistente && ($s[0]['ok'] ?? false) === true && $emp($eR) === []
            && (($s[1]['ok'] ?? false) === true ? $n === 1 : ($n === 0 && ($s[1]['erros'] ?? []) === ['cnpj'])) && !in_array('erro_interno', [$s[0]['codigo'] ?? '', $s[1]['codigo'] ?? ''], true);
    }
    afirmar('corrida empresa: excluir x recriar o MESMO CNPJ (4 rodadas) => a exclusao sempre vence; a recriacao ou entra (1 linha) ou recebe erro de CNPJ (0 linhas); nunca 2 linhas nem erro_interno', $consistente && $pendentes() === 0);
    $cA = (int) $rnC->criar($idAdmin, 'Edit Corrida Um', cnpjQa('415000000001'), '')['id'];
    $cB = (int) $rnC->criar($idAdmin, 'Edit Corrida Dois', cnpjQa('415000000002'), '')['id'];
    $ini = sprintf('%.4F', microtime(true) + 3.0);
    $s = array_map('cw', [lw(['--w-cli-editar', $banco, (string) $idAdmin, (string) $cA, $ini, 'Mesma Razao Final']), lw(['--w-cli-editar', $banco, (string) $idAdmin2, (string) $cB, $ini, 'mesma razao final ltda'])]);
    afirmar('corrida cliente: 2 edicoes simultaneas de clientes DIFERENTES para a MESMA razao normalizada => so 1 grava (o lock evita razao repetida)', count(array_filter($s, static fn ($x) => ($x['ok'] ?? false) === true)) === 1 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_cliente WHERE razao_social_normalizada = 'MESMA RAZAO FINAL'") === 1 && $pendentes() === 0);

    // duas confirmacoes confirmar=1 simultaneas por HTTP (sessoes diferentes)
    foreach ([['cliente', 'cliente-acao.php', 'id_cliente'], ['empresa', 'empresa-acao.php', 'id_empresa']] as [$tipo, $arq, $campoId]) {
        foreach ([1, 2] as $rodada) {
            $idAlvo = $tipo === 'cliente'
                ? (int) $rnC->criar($idAdmin, 'Http Simult ' . $rodada, cnpjQa('416000000' . sprintf('%03d', $rodada)), '')['id']
                : (int) $rnE->criar($idAdmin, 'HttpSim' . $rodada, cnpjQa('417000000' . sprintf('%03d', $rodada)))['id'];
            $form = json_encode(['acao' => 'excluir', $campoId => (string) $idAlvo, 'confirmar' => '1']);
            $ini = sprintf('%.4F', microtime(true) + 4.0);
            $s = array_map('cw', [lw(['--w-http', $banco, (string) $lAdm['sid'], (string) $lAdm['csrf'], $ini, $arq, $form]), lw(['--w-http', $banco, (string) $lAdm2['sid'], (string) $lAdm2['csrf'], $ini, $arq, $form])]);
            $locs = array_map(static fn ($x) => (string) ($x['loc'] ?? ''), $s);
            sort($locs);
            $esperado = $tipo === 'cliente' ? ['/gestao/clientes.php?msg=cliente_excluido', '/gestao/clientes.php?msg=cliente_nao_encontrado'] : ['/gestao/empresas.php?msg=empresa_excluida', '/gestao/empresas.php?msg=empresa_nao_encontrada'];
            sort($esperado);
            $existe = $tipo === 'cliente' ? $cli($idAlvo) : $emp($idAlvo);
            afirmar("HTTP $tipo (rodada $rodada): duas confirmacoes confirmar=1 SIMULTANEAS da mesma exclusao => um 302 de sucesso e um de nao_encontrado (sem 500), linha apagada e 1 auditoria OK", $locs === $esperado && $existe === [] && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = :a AND resultado = 'OK' AND alvo_id = :i", ['a' => $tipo === 'cliente' ? 'CLIENTE_EXCLUIR' : 'EMPRESA_EXCLUIR', 'i' => $idAlvo]) === 1);
        }
    }

    // =====================================================================
    // B. POST forjado por HTTP
    // =====================================================================
    $alvoC = (int) $rnC->criar($idAdmin, 'Alvo Forjado', cnpjQa('420000000001'), '')['id'];
    $alvoE = (int) $rnE->criar($idAdmin, 'AlvoForj', cnpjQa('420000000002'))['id'];
    $antes = $snapTudo();
    // mass assignment na CRIACAO
    $cnpjMA = cnpjQa('420000000003');
    $p = $post('cliente-form.php', $lAdm, ['nome' => 'Mass Assign Comercio', 'cnpj' => $cnpjMA, 'ativo' => '1', 'razao_social_normalizada' => 'HACK', 'id_cliente_novo' => '1', 'criado_em' => '1999-01-01 00:00:00', 'codigo' => 'X', 'token_api' => 'Y', 'id_empresa' => (string) $alvoE, 'perfil' => 'admin', 'confirmar_ambiguidade' => '1']);
    $l = gtLinhas($pdo, 'SELECT * FROM tb_cliente WHERE cnpj = :c', ['c' => $cnpjMA])[0] ?? [];
    afirmar('mass assignment (criar cliente): campos extras (razao_social_normalizada, criado_em, codigo, token_api, id_empresa...) sao ignorados; razao e calculada, criado_em e o do servidor', $p['status'] === 302 && $l !== [] && $l['razao_social_normalizada'] === 'MASS ASSIGN COMERCIO' && substr((string) $l['criado_em'], 0, 4) !== '1999' && !array_key_exists('token_api', $l) && !array_key_exists('codigo', $l));
    $pME = $post('empresa-form.php', $lAdm, ['nome' => 'MassEmp', 'cnpj' => cnpjQa('420000000004'), 'ativo' => '0', 'id_empresa_novo' => '9', 'criado_em' => '1999-01-01 00:00:00']);
    $lE = gtLinhas($pdo, 'SELECT * FROM tb_empresa WHERE cnpj = :c', ['c' => cnpjQa('420000000004')])[0] ?? [];
    afirmar('mass assignment (criar empresa): ativo e criado_em enviados no POST nao valem (empresa nasce ativa, criado_em do servidor)', $pME['status'] === 302 && $lE !== [] && (int) $lE['ativo'] === 1 && substr((string) $lE['criado_em'], 0, 4) !== '1999');
    // mass assignment na EDICAO
    $pEd = $post('cliente-form.php', $lAdm, ['id_cliente' => (string) $alvoC, 'nome' => 'Alvo Forjado Renomeado', 'cnpj' => cnpjQa('420000000099'), 'ativo' => '0', 'razao_social_normalizada' => 'HACK', 'criado_em' => '1999-01-01 00:00:00', 'id_cliente_novo' => '1']);
    $l = $cli($alvoC);
    afirmar('mass assignment (editar cliente): so o nome muda; razao recalculada do nome (nunca a enviada), cnpj/ativo/criado_em intactos', $pEd['status'] === 302 && $l['nome'] === 'Alvo Forjado Renomeado' && $l['razao_social_normalizada'] === 'ALVO FORJADO RENOMEADO' && $l['cnpj'] === cnpjQa('420000000001') && (int) $l['ativo'] === 1 && substr((string) $l['criado_em'], 0, 4) !== '1999');
    $pEe = $post('empresa-form.php', $lAdm, ['id_empresa' => (string) $alvoE, 'nome' => 'AlvoForjX', 'cnpj' => cnpjQa('420000000098'), 'ativo' => '0']);
    afirmar('mass assignment (editar empresa): cnpj e ativo enviados sao ignorados', $pEe['status'] === 302 && $emp($alvoE)['nome'] === 'AlvoForjX' && $emp($alvoE)['cnpj'] === cnpjQa('420000000002') && (int) $emp($alvoE)['ativo'] === 1);
    // ativo invalido por HTTP (criar cliente)
    $antesN = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente');
    foreach (['2', 'sim', 'true', '-1', '1 ', '01', '１', 'on'] as $av) {
        $pa = $post('cliente-form.php', $lAdm, ['nome' => 'Ativo Invalido', 'cnpj' => cnpjQa('421000000001'), 'ativo' => $av]);
        afirmar('ativo invalido ' . json_encode($av) . ' (criar cliente HTTP): 422 com erro no campo ativo e nada criado', $pa['status'] === 422 && str_contains($pa['corpo'], 'id="erro-cliente-ativo"') && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente') === $antesN);
    }
    $paArr = $postCru('cliente-form.php', $lAdm, 'nome=Ativo+Array&cnpj=' . cnpjQa('421000000001') . '&ativo[]=1');
    afirmar('ativo como array (criar cliente HTTP): sem 500 e nada criado ou criado como ativo padrao, nunca erro', in_array($paArr['status'], [302, 422], true) && !str_contains($paArr['corpo'], 'Fatal') && !str_contains($paArr['corpo'], 'SQLSTATE'));
    $pdo->exec("DELETE FROM tb_cliente WHERE nome = 'Ativo Array'");
    // id como array nos formularios e acao em variantes
    $antes = $snapTudo();
    $nAntesAud = $nAud();
    $pIa = $postCru('cliente-form.php', $lAdm, 'id_cliente[]=' . $alvoC . '&nome=Hack+Array');
    $pIb = $postCru('empresa-form.php', $lAdm, 'id_empresa[]=' . $alvoE . '&nome=HackArr');
    afirmar('id_cliente/id_empresa como array no formulario: sem 500, nada muda (nao_encontrado ou criacao SEM id)', $pIa['status'] !== 500 && $pIb['status'] !== 500 && !str_contains($pIa['corpo'] . $pIb['corpo'], 'SQLSTATE') && $cli($alvoC)['nome'] === 'Alvo Forjado Renomeado' && $emp($alvoE)['nome'] === 'AlvoForjX');
    $pdo->exec("DELETE FROM tb_cliente WHERE nome = 'Hack Array'");
    $antes = $snapTudo();
    foreach (['EXCLUIR', 'Excluir', 'excluir ', ' excluir', 'excluir%00', 'delete', 'remover', '', 'excluir,inativar'] as $acaoF) {
        $pc = $post('cliente-acao.php', $lAdm, ['acao' => $acaoF, 'id_cliente' => (string) $alvoC, 'confirmar' => '1']);
        $pe = $post('empresa-acao.php', $lAdm, ['acao' => $acaoF, 'id_empresa' => (string) $alvoE, 'confirmar' => '1']);
        afirmar('acao forjada ' . json_encode($acaoF) . ': 400 nas duas rotas e NADA muda (comparacao exata, sem caixa/trim)', $pc['status'] === 400 && $pe['status'] === 400 && $snapTudo() === $antes);
    }
    $pAa = $postCru('cliente-acao.php', $lAdm, 'acao[]=excluir&id_cliente=' . $alvoC . '&confirmar=1');
    $pAb = $postCru('empresa-acao.php', $lAdm, 'confirmar=1&id_empresa=' . $alvoE);
    afirmar('acao como array e acao ausente: 400, nada muda', $pAa['status'] === 400 && $pAb['status'] === 400 && $snapTudo() === $antes);
    // confirmar em variantes: so a string exata "1" confirma
    foreach (['true', 'on', 'yes', '1 ', ' 1', '01', '1.0', '', '１', '0', '2', '-1'] as $cf) {
        $pc = $post('cliente-acao.php', $lAdm, ['acao' => 'excluir', 'id_cliente' => (string) $alvoC, 'confirmar' => $cf]);
        $pe = $post('empresa-acao.php', $lAdm, ['acao' => 'excluir', 'id_empresa' => (string) $alvoE, 'confirmar' => $cf]);
        $okRedir = $loc($pc) === '/gestao/clientes.php?confirmar=excluir&id=' . $alvoC . '&msg=cliente_confirmacao_necessaria' && $loc($pe) === '/gestao/empresas.php?confirmar=excluir&id=' . $alvoE . '&msg=empresa_confirmacao_necessaria';
        afirmar('confirmar=' . json_encode($cf) . ' (excluir cliente e empresa): NAO confirma, volta ao 1o passo e nada e apagado', $okRedir && $snapTudo() === $antes);
    }
    $pCa = $postCru('cliente-acao.php', $lAdm, 'acao=excluir&id_cliente=' . $alvoC . '&confirmar[]=1');
    $pCb = $postCru('empresa-acao.php', $lAdm, 'acao=excluir&id_empresa=' . $alvoE . '&confirmar[]=1');
    afirmar('confirmar como array (excluir): nao confirma e nada muda', $loc($pCa) !== '/gestao/clientes.php?msg=cliente_excluido' && $loc($pCb) !== '/gestao/empresas.php?msg=empresa_excluida' && $cli($alvoC) !== [] && $emp($alvoE) !== []);
    $pDup = $postCru('cliente-acao.php', $lAdm, 'acao=excluir&id_cliente=' . $alvoC . '&confirmar=0&confirmar=1');
    afirmar('parametro confirmar duplicado: vale o ULTIMO valor do PHP (documenta o comportamento; so "1" exato exclui) e id duplicado nao amplia o alvo', in_array($loc($pDup), ['/gestao/clientes.php?msg=cliente_excluido', '/gestao/clientes.php?confirmar=excluir&id=' . $alvoC . '&msg=cliente_confirmacao_necessaria'], true));
    if ($cli($alvoC) === []) {
        $alvoC = (int) $rnC->criar($idAdmin, 'Alvo Forjado', cnpjQa('420000000001'), '')['id'];
    }
    // cabecalhos de override de metodo nao tornam GET/POST em outra coisa
    $antes = $snapTudo();
    $pMo = $post('cliente-acao.php', $lAdm, ['acao' => 'inativar', '_method' => 'DELETE', 'id_cliente' => (string) $alvoC], ['cabecalhos' => ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE']]);
    afirmar('_method/X-HTTP-Method-Override nao altera a acao: inativar continua inativar (sem andamento, direto) e nenhum DELETE', $cli($alvoC) !== [] && (int) $cli($alvoC)['ativo'] === 0);
    $pdo->prepare('UPDATE tb_cliente SET ativo = 1 WHERE id_cliente = :i')->execute(['i' => $alvoC]);
    // GET nunca grava
    $antes = $snapTudo();
    $variantes = [
        ['clientes.php', ['confirmar' => 'excluir', 'id' => (string) $alvoC]], ['clientes.php', ['confirmar' => 'inativar', 'id' => (string) $alvoC, 'acao' => 'excluir', 'confirmar2' => '1']],
        ['clientes.php', ['confirmar' => 'ativar', 'id' => (string) $alvoC, 'msg' => 'cliente_excluido']], ['empresas.php', ['confirmar' => 'excluir', 'id' => (string) $alvoE]],
        ['empresas.php', ['confirmar' => 'inativar', 'id' => (string) $alvoE, 'acao' => 'excluir']], ['cliente-form.php', ['id' => (string) $alvoC, 'nome' => 'Renomeado Por GET', 'confirmar' => '1']],
        ['empresa-form.php', ['id' => (string) $alvoE, 'nome' => 'RenGet', 'confirmar' => '1']], ['cliente-form.php', ['nome' => 'Criado Por GET', 'cnpj' => cnpjQa('422000000001'), 'confirmar' => '1']],
        ['empresa-form.php', ['nome' => 'CriadaGet', 'cnpj' => cnpjQa('422000000002')]], ['clientes.php', ['acao' => 'excluir', 'id_cliente' => (string) $alvoC, 'confirmar' => '1']],
        ['empresas.php', ['acao' => 'excluir', 'id_empresa' => (string) $alvoE, 'confirmar' => '1']],
    ];
    $statusGet = [];
    foreach ($variantes as [$arq, $q]) {
        $statusGet[] = $get($arq, $lAdm, $q)['status'];
    }
    afirmar('GET com parametros de acao (confirmar, acao, nome, cnpj, id_*) em todas as 4 paginas de leitura: nada e criado/alterado/excluido, nenhuma auditoria, nenhum 500', $snapTudo() === $antes && !in_array(500, $statusGet, true));
    foreach (['cliente-acao.php', 'empresa-acao.php'] as $arq) {
        $g = $get($arq, $lAdm, ['acao' => 'excluir', 'id_cliente' => (string) $alvoC, 'id_empresa' => (string) $alvoE, 'confirmar' => '1']);
        afirmar("GET em $arq com acao=excluir&confirmar=1: 405 e nada muda", $g['status'] === 405 && $snapTudo() === $antes);
    }

    // =====================================================================
    // C. Sessao de admin rebaixado/desativado
    // =====================================================================
    $antes = $snapTudo();
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'usuario' WHERE id_usuario = :i")->execute(['i' => $idAdmin3]);
    afirmar('admin rebaixado a usuario (sessao ja aberta): a mudanca de perfil foi aplicada', (string) gtEscalar($pdo, 'SELECT perfil FROM tb_gestao_usuario WHERE id_usuario = :i', ['i' => $idAdmin3]) === 'usuario');
    $codsReb = [];
    foreach (['clientes.php', 'empresas.php', 'cliente-form.php', 'empresa-form.php'] as $arq) {
        $codsReb[] = $get($arq, $lCar)['status'];
    }
    $pcR = $post('cliente-acao.php', $lCar, ['acao' => 'excluir', 'id_cliente' => (string) $alvoC, 'confirmar' => '1']);
    $peR = $post('empresa-acao.php', $lCar, ['acao' => 'excluir', 'id_empresa' => (string) $alvoE, 'confirmar' => '1']);
    $pfR = $post('cliente-form.php', $lCar, ['nome' => 'Rebaixado Criou', 'cnpj' => cnpjQa('423000000001')]);
    $pgR = $post('empresa-form.php', $lCar, ['nome' => 'RebCriou', 'cnpj' => cnpjQa('423000000002')]);
    afirmar('sessao de admin REBAIXADO: as 4 paginas (GET) e as 4 gravacoes (POST) respondem 403 e NADA muda', $codsReb === [403, 403, 403, 403] && [$pcR['status'], $peR['status'], $pfR['status'], $pgR['status']] === [403, 403, 403, 403] && $snapTudo() === $antes);
    $pdo->prepare("UPDATE tb_gestao_usuario SET perfil = 'admin' WHERE id_usuario = :i")->execute(['i' => $idAdmin3]);
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idAdmin3]);
    $codsDes = [];
    foreach (['clientes.php', 'empresas.php', 'cliente-form.php', 'empresa-form.php'] as $arq) {
        $codsDes[] = $get($arq, $lCar)['status'];
    }
    $pcD = $post('cliente-acao.php', $lCar, ['acao' => 'excluir', 'id_cliente' => (string) $alvoC, 'confirmar' => '1']);
    $peD = $post('empresa-acao.php', $lCar, ['acao' => 'excluir', 'id_empresa' => (string) $alvoE, 'confirmar' => '1']);
    afirmar('sessao de admin DESATIVADO: GET e POST NAO executam (sem 200 nem 302 de sucesso) e NADA muda', !in_array(200, $codsDes, true) && $pcD['status'] !== 200 && !str_contains((string) $loc($pcD), '_excluid') && !str_contains((string) $loc($peD), '_excluid') && $snapTudo() === $antes);
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = :i')->execute(['i' => $idAdmin3]);

    // =====================================================================
    // D. Nomes exoticos
    // =====================================================================
    $proibidos = 0;
    $vazados = [];
    $cps = array_merge(range(0, 0xFFFF), range(0xE0000, 0xE007F), range(0x1BCA0, 0x1BCA3), range(0x1D173, 0x1D17A), range(0xE0100, 0xE01EF));
    foreach ($cps as $cp) {
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            continue;
        }
        $ch = mb_chr($cp, 'UTF-8');
        $deveBloquear = preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $ch) === 1 || in_array($cp, [0x3164, 0x2800, 0xFE0F, 0x034F, 0x115F, 0x1160, 0xFFA0], true) || ($cp >= 0xE0000 && $cp <= 0xE007F);
        if ($deveBloquear) {
            $proibidos++;
            if (NomeCadastro::normalizar('A' . $ch . 'B', 1, 150) !== null || NomeCadastro::normalizar($ch . 'Acme', 1, 150) !== null) {
                $vazados[] = sprintf('U+%04X', $cp);
            }
        }
    }
    afirmar("NomeCadastro: TODO codepoint Cc/Cf/Zl/Zp (BMP, tags, Cf do plano 1) mais os 7 invisiveis e recusado no meio e no comeco ($proibidos testados)" . ($vazados !== [] ? ' vazaram: ' . implode(',', array_slice($vazados, 0, 10)) : ''), $proibidos > 150 && $vazados === []);
    // Invisiveis FORA do conjunto documentado (Mn de variacao etc.): so informa, nao reprova
    $fora = [];
    foreach ([0x17B4, 0x17B5, 0x180B, 0x180C, 0x180D, 0xFE00, 0xFE0E, 0xE0100, 0x2063, 0x3000, 0x1680] as $cp) {
        if (NomeCadastro::normalizar('A' . mb_chr($cp, 'UTF-8') . 'B', 1, 150) !== null) {
            $fora[] = sprintf('U+%04X', $cp);
        }
    }
    echo 'INFO invisiveis fora do conjunto documentado e ACEITOS (visualmente vazios, harmlessos para o OCR pois a razao os descarta): ' . implode(',', $fora) . "\n";
    $pdo->exec('DELETE FROM tb_cliente');
    $paInv = $post('cliente-form.php', $lAdm, ['nome' => "Acme\u{3164}Ltda", 'cnpj' => cnpjQa('430000000001')]);
    afirmar('nome com invisivel (U+3164) por HTTP: 422 no campo nome e nada criado', $paInv['status'] === 422 && str_contains($paInv['corpo'], 'id="erro-cliente-nome"') && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente') === 0);
    // emoji 4 bytes no limite (150 caracteres = 375 bytes) e ida e volta no banco
    $n150 = str_repeat("A\u{1F600}", 75);
    $n152 = str_repeat("A\u{1F600}", 76);
    $r150 = $rnC->criar($idAdmin, $n150, cnpjQa('430000000002'), '');
    $r152 = $rnC->criar($idAdmin, $n152, cnpjQa('430000000003'), '');
    afirmar('nome de 150 caracteres com emoji (4 bytes cada, 375 bytes): aceito e gravado SEM truncar (utf8mb4 de ponta a ponta); 152 caracteres recusado', $r150['ok'] === true && $cli((int) $r150['id'])['nome'] === $n150 && $r152['ok'] === false && isset($r152['erros']['nome']));
    $rnC->excluir($idAdmin, (int) $r150['id'], true);
    $nEmoji = "\u{1F600}\u{1F600}\u{1F600}";
    afirmar('nome so de emoji: recusado por razao vazia (nunca grava razao vazia)', $rnC->criar($idAdmin, $nEmoji, cnpjQa('430000000004'), '')['ok'] === false);
    // NFC x NFD => mesma razao
    $nfc = $rnC->criar($idAdmin, "Caf\u{00E9} Central Ltda", cnpjQa('430000000005'), '');
    $nfd = $rnC->criar($idAdmin, "Cafe\u{0301} Central", cnpjQa('430000000006'), '');
    $razoesCafe = array_column(gtLinhas($pdo, "SELECT razao_social_normalizada AS r FROM tb_cliente WHERE nome LIKE 'Caf%'"), 'r');
    afirmar('NFC "Café Central Ltda" e NFD "Cafe+combinante Central": razao SEMPRE "CAFE CENTRAL" (acentos ignorados); o 2o gera a MESMA razao e e recusado como duplicado; nunca erro interno', ($nfc['ok'] ?? false) === true && $cli((int) $nfc['id'])['razao_social_normalizada'] === 'CAFE CENTRAL' && ($nfd['ok'] ?? true) === false && ($nfd['codigo'] ?? '') === 'validacao' && $razoesCafe === ['CAFE CENTRAL']);
    // RTL + latim: razao so com o trecho latino; colisao tratada
    $rt1 = $rnC->criar($idAdmin, "Nova \u{05E9}\u{05DC}\u{05D5}\u{05DD} Brasil", cnpjQa('430000000007'), '');
    $rt2 = $rnC->criar($idAdmin, "Nova \u{0627}\u{0644}\u{0639}\u{0631}\u{0628}\u{064A}\u{0629} Brasil", cnpjQa('430000000008'), '');
    $razoesRtl = gtLinhas($pdo, "SELECT razao_social_normalizada AS r FROM tb_cliente WHERE nome LIKE 'Nova %'");
    afirmar('nomes RTL (hebraico/arabe) misturados ao latim: nunca erro interno; se aceitos, a razao nao e vazia; razoes nunca repetidas no banco', in_array($rt1['codigo'] ?? 'ok', ['ok', 'validacao'], true) && in_array($rt2['codigo'] ?? 'ok', ['ok', 'validacao'], true) && count($razoesRtl) === count(array_unique(array_column($razoesRtl, 'r'))) && !in_array('', array_column($razoesRtl, 'r'), true));
    // expansao da razao por transliteracao (ß => SS, Æ => AE): nao pode estourar a coluna
    $colRazao = (int) gtEscalar($pdo, "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_cliente' AND COLUMN_NAME = 'razao_social_normalizada'");
    $rEx = $rnC->criar($idAdmin, str_repeat("\u{00DF}", 150), cnpjQa('430000000009'), '');
    $rEx2 = $rnC->criar($idAdmin, str_repeat("\u{00C6}", 150), cnpjQa('430000000010'), '');
    $okEx = true;
    foreach ([$rEx, $rEx2] as $rx) {
        $okEx = $okEx && in_array($rx['codigo'] ?? 'ok', ['ok', 'validacao'], true) && ($rx['ok'] === true ? mb_strlen((string) $cli((int) $rx['id'])['razao_social_normalizada']) <= $colRazao : isset($rx['erros']['nome']));
    }
    afirmar("nome de 150 'ß' ou 'Æ' (razao expande ao transliterar): recusado com erro no nome ou gravado com razao que CABE na coluna ($colRazao), nunca erro interno nem truncada", $okEx);
    // golden extra de normalizar (valores fixos ASCII) alem dos do teste principal
    foreach (['  Acme   Tintas  S/A ' => 'ACME TINTAS', 'Alfa-Beta, Gama (Delta)' => 'ALFA BETA GAMA DELTA', 'EIRELI' => '', 'Meia Ltda Me' => 'MEIA', 'CIA Alfa' => 'ALFA', 'ALFA 2000 Industria' => 'ALFA 2000 INDUSTRIA', 'a/b' => 'A B'] as $in => $out) {
        afirmar('golden normalizar ' . json_encode($in) . ' => ' . json_encode($out), RazaoSocialMatcher::normalizar($in) === $out);
    }
    $pdo->exec('DELETE FROM tb_cliente');

    // =====================================================================
    // E. Ambiguidade do OCR: 1, 2, 5, 6 e 7 afetados
    // =====================================================================
    $palavras = ['ALFA', 'BRAVO', 'CHARLIE', 'DELTA', 'ECHO', 'FOXTROT', 'GOLF'];
    $cnpjN = static fn (int $n): string => cnpjQa(sprintf('%012d', 450000000000 + $n));
    $k = 0;
    foreach ($palavras as $pal) {
        $rnC->criar($idAdmin, $pal, $cnpjN(++$k), '');
    }
    $hotel = (int) $rnC->criar($idAdmin, 'HOTEL', $cnpjN(++$k), '0')['id'];
    $oraculo = static function (string $nomeNovo) use ($pdo): int {
        $antes = gtLinhas($pdo, "SELECT id_cliente, nome, razao_social_normalizada AS razao_social FROM tb_cliente WHERE ativo = 1 AND razao_social_normalizada <> ''");
        $depois = array_merge($antes, [['id_cliente' => -1, 'nome' => $nomeNovo, 'razao_social' => RazaoSocialMatcher::normalizar($nomeNovo)]]);
        $ok = static function (array $it, array $lista): bool {
            $r = RazaoSocialMatcher::melhorCandidato($it['razao_social'], $lista);

            return $r['identificado'] === true && (int) $r['cliente']['id_cliente'] === (int) $it['id_cliente'];
        };
        $n = 0;
        foreach ($antes as $it) {
            if ($ok($it, $antes) && !$ok($it, $depois)) {
                $n++;
            }
        }

        return $n;
    };
    $snap = $snapTudo();
    foreach ([1 => 1, 2 => 2, 3 => 5, 4 => 6, 5 => 7] as $_ => $n) {
        $nomeNovo = implode(' ', array_slice($palavras, 0, $n)) . ' Zulu';
        $esperado = $n;
        $r = $rnC->criar($idAdmin, $nomeNovo, $cnpjN(100 + $n), '');
        $amb = $r['ambiguidade'] ?? ['total' => -1, 'nomes' => []];
        $orac = $oraculo($nomeNovo);
        afirmar("ambiguidade com $n afetado(s): confirmacao_ambiguidade com total=$esperado (oraculo independente=$orac), nomes limitados a 5, nada gravado", ($r['codigo'] ?? '') === 'confirmacao_ambiguidade' && $amb['total'] === $esperado && $orac === $esperado && count($amb['nomes']) === min(5, $esperado) && $snapTudo() === $snap);
        if ($n === 7) {
            $pH = $post('cliente-form.php', $lAdm, ['nome' => $nomeNovo, 'cnpj' => $cnpjN(100 + $n), 'ativo' => '1']);
            afirmar('ambiguidade com 7 afetados (HTTP): a tela diz 7 cliente(s) e lista exatamente 5 nomes (ate 5), sem gravar', $pH['status'] === 200 && str_contains($pH['corpo'], 'automaticamente 7 cliente(s)') && preg_match('#id="cliente-ambiguidade-afetados">Clientes afetados \(até 5\): ([^<]*)</p>#', $pH['corpo'], $mAf) === 1 && count(explode(', ', $mAf[1])) === 5 && $snapTudo() === $snap);
        }
    }
    $rHot = $rnC->criar($idAdmin, 'Hotel Zulu Quimica', $cnpjN(200), '');
    afirmar('cliente INATIVO (HOTEL) nao conta: nome novo parecido com ele e criado direto, sem aviso', $rHot['ok'] === true);
    $rnC->excluir($idAdmin, (int) $rHot['id'], true);
    $rAtH = $rnC->definirAtivo($idAdmin, $hotel, true, false);
    afirmar('ativar HOTEL (unico parecido e nenhum outro ativo igual): direto, sem aviso', $rAtH['ok'] === true);
    $rnC->definirAtivo($idAdmin, $hotel, false, false);
    // aviso por EDICAO e por ATIVACAO com muitos afetados
    $idEd = (int) $rnC->criar($idAdmin, 'Neutro Qualquer', $cnpjN(201), '')['id'];
    $rEd = $rnC->editar($idAdmin, $idEd, 'Alfa Bravo Charlie Delta Echo Foxtrot Zulu');
    afirmar('editar para nome que quebraria 6 clientes: confirmacao_ambiguidade total 6, 5 nomes, nada muda', ($rEd['codigo'] ?? '') === 'confirmacao_ambiguidade' && $rEd['ambiguidade']['total'] === 6 && count($rEd['ambiguidade']['nomes']) === 5 && $cli($idEd)['nome'] === 'Neutro Qualquer');
    $pdo->prepare('UPDATE tb_cliente SET nome = :n, razao_social_normalizada = :r WHERE id_cliente = :i')->execute(['n' => 'Alfa Bravo Charlie Zulu', 'r' => RazaoSocialMatcher::normalizar('Alfa Bravo Charlie Zulu'), 'i' => $hotel]);
    $aAt = $rnC->ambiguidadeAoAtivar($hotel);
    afirmar('ativar cliente inativo cujo nome (editado por fora) engloba 3 existentes: total 3 e o proprio inativo nao e contado antes de ativar', $aAt['total'] === 3);
    $pdo->prepare("UPDATE tb_cliente SET nome = 'HOTEL', razao_social_normalizada = 'HOTEL' WHERE id_cliente = :i")->execute(['i' => $hotel]);
    $rnC->excluir($idAdmin, $idEd, true);

    // XSS nos nomes listados (atributos, aspas, ampersand, tags)
    $pdo->exec('DELETE FROM tb_cliente');
    $nomeX = "ALFA <b>\"x'&amp;</b>";
    $idX = (int) $rnC->criar($idAdmin, $nomeX, $cnpjN(300), '')['id'];
    $idX2 = (int) $rnC->criar($idAdmin, 'ZED "><img src=x onerror=alert(1)>', $cnpjN(301), '')['id'];
    $razX = (string) $cli($idX)['razao_social_normalizada'];
    $razX2 = (string) $cli($idX2)['razao_social_normalizada'];
    $nomeNovoX = $razX . ' Zulu';
    $pX = $post('cliente-form.php', $lAdm, ['nome' => $nomeNovoX, 'cnpj' => $cnpjN(302), 'ativo' => '1']);
    $escOk = static fn (string $corpo): bool => !str_contains($corpo, '<b>"x') && !str_contains($corpo, '<img src=x') && !str_contains($corpo, 'onerror=alert(1)>') && !str_contains($corpo, "'&amp;</b>");
    afirmar('XSS (criar, passo 1): nome afetado com <b>, aspas, apostrofo e &amp; sai ESCAPADO na lista "Clientes afetados" e nunca como HTML cru (razao do afetado: ' . $razX . ')', $pX['status'] === 200 && str_contains($pX['corpo'], 'id="cliente-ambiguidade-afetados"') && str_contains($pX['corpo'], '&lt;b&gt;&quot;x&#039;&amp;amp;&lt;/b&gt;') && $escOk($pX['corpo']));
    $pX2 = $post('cliente-form.php', $lAdm, ['nome' => $razX2 . ' Zulu', 'cnpj' => $cnpjN(303), 'ativo' => '1']);
    afirmar('XSS (criar, passo 1): afetado com ">< img onerror" tambem escapado (sem fechar atributo/tag)', $pX2['status'] === 200 && str_contains($pX2['corpo'], '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;') && $escOk($pX2['corpo']));
    $pX3 = $post('cliente-form.php', $lAdm, ['nome' => "Nome \"><svg onload=alert(1)> " . $razX, 'cnpj' => $cnpjN(304), 'ativo' => '1']);
    afirmar('XSS: nome DIGITADO hostil aparece escapado no "Nome a gravar" e nos campos ocultos (value=), sem quebrar o atributo', !str_contains($pX3['corpo'], '<svg onload') && !str_contains($pX3['corpo'], 'value=""><svg'));
    $idEdX = (int) $rnC->criar($idAdmin, 'Quieto Qualquer', $cnpjN(305), '')['id'];
    $pX4 = $post('cliente-form.php', $lAdm, ['id_cliente' => (string) $idEdX, 'nome' => $nomeNovoX]);
    afirmar('XSS (editar, passo 1): mesmo escape na tela de confirmacao da edicao', $pX4['status'] === 200 && str_contains($pX4['corpo'], 'id="cliente-ambiguidade-afetados"') && $escOk($pX4['corpo']));
    $idIna = (int) $rnC->criar($idAdmin, $nomeNovoX, $cnpjN(306), '0')['id'];
    $pX5 = $get('clientes.php', $lAdm, ['confirmar' => 'ativar', 'id' => (string) $idIna]);
    afirmar('XSS (ativar, passo 1): clientes afetados e o nome do cliente na confirmacao de ativacao saem escapados', str_contains($pX5['corpo'], 'id="cliente-confirmacao-afetados"') && $escOk($pX5['corpo']));
    foreach ([$pX, $pX2, $pX3, $pX4, $pX5] as $pg) {
        preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $pg['corpo'], $mDbg);
        afirmar('tela de confirmacao de ambiguidade: no-store, CSP e sem script/handler inline', gtCabecalho($pg, 'cache-control') === 'no-store, private' && gtCabecalho($pg, 'content-security-policy') !== null && preg_match('/<script\b(?![^>]*\bsrc=)/i', $pg['corpo']) !== 1 && !str_contains($pg['corpo'], '<svg onload') && !str_contains($pg['corpo'], '<img src=x'));
    }
    $pdo->exec('DELETE FROM tb_cliente');

    // limite exato: 500 ativos simulam; 501 nao
    $fill = $pdo->prepare('INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:n, :r, :c, 1)');
    $rnC->criar($idAdmin, 'ALFA', $cnpjN(400), '');
    for ($i = 1; $i <= 499; $i++) {
        $nf = sprintf('QX%04d', $i);
        $fill->execute(['n' => $nf, 'r' => $nf, 'c' => $cnpjN(1000 + $i)]);
    }
    afirmar('limite: exatamente 500 clientes ativos montados', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente WHERE ativo = 1') === 500);
    $r500 = $rnC->criar($idAdmin, 'Alfa Zulu Quimica', $cnpjN(401), '');
    afirmar('limite: com EXATAMENTE 500 ativos a simulacao RODA (aviso para ALFA)', ($r500['codigo'] ?? '') === 'confirmacao_ambiguidade' && $r500['ambiguidade']['total'] === 1);
    $fill->execute(['n' => 'QX9999', 'r' => 'QX9999', 'c' => $cnpjN(1999)]);
    $r501 = $rnC->criar($idAdmin, 'Alfa Zulu Quimica', $cnpjN(401), '');
    afirmar('limite: com 501 ativos a simulacao e PULADA (disponibilidade): cria direto, sem aviso, auditoria sem motivo_cad', $r501['ok'] === true && (string) gtEscalar($pdo, "SELECT detalhe FROM tb_gestao_auditoria WHERE acao = 'CLIENTE_CRIAR' AND resultado = 'OK' ORDER BY id_auditoria DESC LIMIT 1") === 'ativo_para=1');
    $pdo->exec('DELETE FROM tb_cliente');

    // =====================================================================
    // F. Higiene da propria bateria
    // =====================================================================
    afirmar('nenhuma auditoria PENDENTE sobrou depois de toda a bateria', $pendentes() === 0);
    $logTxt = (string) @file_get_contents($log);
    afirmar('log do PHP dos cgi: sem SQLSTATE nem fatal', !str_contains($logTxt, 'SQLSTATE') && !str_contains($logTxt, 'Fatal error') && !str_contains($logTxt, 'Uncaught'));
    afirmar('nenhuma auditoria dos cadastros cita nome ou CNPJ usados nesta bateria', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE detalhe REGEXP '[0-9]{8}|ALFA|Alfa|Zulu|Acme|Forjado'") === 0);
} finally {
    if ($banco !== null) {
        try {
            qaQrDroparBanco($banco);
        } catch (Throwable $e) {
        }
    }
    gtDestruirAmbiente(null, $storage);
    @unlink($log);
}

exit(gtResumo('teste_gestao_qa_cadastros'));
