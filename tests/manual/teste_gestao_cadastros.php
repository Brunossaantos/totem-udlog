<?php

/**
 * Gestao Totem, F6 REDUZIDA (CADASTROS, 2026-10-09): clientes (tb_cliente) e empresas
 * (tb_empresa) do banco do TOTEM. Regras de negocio (Rn em processo), auditoria (PENDENTE
 * antes de gravar, SEM_EFEITO, sem PII), corridas (varios processos), efeito em tempo real
 * no OCR/autocomplete (ClienteDao) e no Talent (EmpresaDao), e as paginas REAIS por php-cgi
 * (RBAC, metodos, CSRF/Origin, ids e filtros hostis, paginacao, confirmacao em dois passos,
 * XSS, contrato de ids, sem inline). Banco QA descartavel `qa_qr_exclusivo_<hex>`; NUNCA
 * udlog_totem nem o banco externo de coletas.
 *
 * Uso: php tests/manual/teste_gestao_cadastros.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\AuditoriaDao;
use App\Dao\ClienteDao;
use App\Dao\EmpresaDao;
use App\Rn\ClienteGestaoRn;
use App\Rn\EmpresaGestaoRn;
use App\Rn\TotemGestaoRn;
use Util\CnpjValidador;
use Util\NomeCadastro;
use Util\RazaoSocialMatcher;

const BASE_CAD = 'http://localhost:8080/totem/';

/** CNPJ valido (14 digitos) a partir de 12 digitos de base. */
function cnpjValido(string $base12): string
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

function mascarar(string $c): string
{
    return substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/' . substr($c, 8, 4) . '-' . substr($c, 12, 2);
}

// ---------------------------------------------------------------------------
// Trabalhadores (subprocessos da propria bateria): corridas
// ---------------------------------------------------------------------------
if (isset($argv[1]) && str_starts_with($argv[1], '--w-')) {
    [, $wModo, $wBanco, $wAdmin, $wAlvo, $wInicio, $wExtra] = array_pad($argv, 7, '');
    $wPdo = qaQrAbrirBanco($wBanco);
    $wPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    while (microtime(true) < (float) $wInicio) {
        usleep(200);
    }
    $wIp = '203.0.113.9';
    if ($wModo === '--w-cli-excluir') {
        $wR = (new ClienteGestaoRn($wPdo))->excluir((int) $wAdmin, (int) $wAlvo, true, $wIp);
    } elseif ($wModo === '--w-cli-editar') {
        $wR = (new ClienteGestaoRn($wPdo))->editar((int) $wAdmin, (int) $wAlvo, $wExtra, $wIp);
    } elseif ($wModo === '--w-cli-criar') {
        $wR = (new ClienteGestaoRn($wPdo))->criar((int) $wAdmin, $wExtra, (string) $wAlvo, '', $wIp);
    } elseif ($wModo === '--w-emp-excluir') {
        $wR = (new EmpresaGestaoRn($wPdo))->excluir((int) $wAdmin, (int) $wAlvo, true, $wIp);
    } elseif ($wModo === '--w-emp-inativar') {
        $wR = (new EmpresaGestaoRn($wPdo))->definirAtivo((int) $wAdmin, (int) $wAlvo, false, true, $wIp);
    } elseif ($wModo === '--w-totem-criar') {
        $wR = (new TotemGestaoRn($wPdo, BASE_CAD))->criar((int) $wAdmin, (int) $wAlvo, $wExtra, $wIp);
    } else {
        $wR = ['ok' => false, 'codigo' => 'modo_invalido'];
    }
    echo json_encode(['ok' => $wR['ok'], 'codigo' => $wR['codigo'] ?? null, 'erros' => array_keys($wR['erros'] ?? [])]);
    exit(0);
}

if (isset($_ENV['DB_NAME'])) {
    fwrite(STDERR, "teste_gestao_cadastros: DB_NAME presente no ambiente; abortando (nunca usar udlog_totem).\n");
    exit(3);
}

$banco = null;
$storage = null;
$bancos = [];
$log = gtNovoLogCgi();
@unlink($log);
$raiz = dirname(__DIR__, 2);

function codigoSemComentarios(string $arquivo): string
{
    $saida = '';
    foreach (token_get_all((string) file_get_contents($arquivo)) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                continue;
            }
            $saida .= $t[1];
        } else {
            $saida .= $t;
        }
    }

    return $saida;
}

function loc(array $r): ?string
{
    return gtCabecalho($r, 'location');
}

function ultima(array $l): array
{
    return $l === [] ? [] : $l[array_key_last($l)];
}

function lancarW(array $args): array
{
    $p = proc_open(array_merge([PHP_BINARY, __FILE__], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);

    return [$p, $pipes];
}

function colherW(array $h): ?array
{
    $o = (string) stream_get_contents($h[1][1]);
    stream_get_contents($h[1][2]);
    fclose($h[1][1]);
    fclose($h[1][2]);
    proc_close($h[0]);

    return json_decode($o, true);
}

/** Texto visivel (sem tags) de um HTML. */
function textoVisivel(string $html): string
{
    return html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? $html), ENT_QUOTES, 'UTF-8');
}

try {
    // =====================================================================
    // A. RazaoSocialMatcher::normalizar publico; IDENTICO ao algoritmo antigo para ASCII e SEM
    //    acentos para o resto (decisao do usuario 2026-10-09: sempre normalizar sem acento)
    // =====================================================================
    $metodoNorm = new ReflectionMethod(RazaoSocialMatcher::class, 'normalizar');
    afirmar('normalizar: agora e public static', $metodoNorm->isPublic() && $metodoNorm->isStatic());
    // Referencia: copia VERBATIM da implementacao anterior (strtoupper + iconv TRANSLIT), usada SO para
    // entradas ASCII (a nova nao depende de iconv/locale; a antiga perdia letras acentuadas minusculas).
    $normalizarAntigo = static function (string $razaoSocial): string {
        $sufixos = ['LTDA ME', 'LTDA', 'S A', 'S/A', 'SA', 'EIRELI', 'ME', 'EPP', 'MEI', 'CIA'];
        $normalizada = strtoupper($razaoSocial);
        $transliterada = @iconv('UTF-8', 'ASCII//TRANSLIT', $normalizada);
        if ($transliterada !== false && $transliterada !== null) {
            $normalizada = $transliterada;
        }
        $normalizada = preg_replace('/[^A-Z0-9 ]/', ' ', $normalizada);
        foreach ($sufixos as $sufixo) {
            $normalizada = preg_replace('/\b' . preg_quote($sufixo, '/') . '\b/', ' ', $normalizada);
        }
        $normalizada = preg_replace('/\s+/', ' ', $normalizada);

        return trim($normalizada);
    };
    $golden = [
        'CROMEX TINTAS LTDA' => 'CROMEX TINTAS',
        'Acme Comercio LTDA ME' => 'ACME COMERCIO',
        'Beta S/A' => 'BETA',
        'LTDA' => '',
        '***' => '',
        '  multi   espaco  ' => 'MULTI ESPACO',
        'Cia Brasileira MEI' => 'BRASILEIRA',
        "O'Brien & Sons" => 'O BRIEN SONS',
        "Tab\tNew\nLine" => 'TAB NEW LINE',
        'x' => 'X',
        'A.B.C. Industria e Comercio EPP' => 'A B C INDUSTRIA E COMERCIO',
        '' => '',
    ];
    foreach ($golden as $entrada => $esperado) {
        afirmar('normalizar golden ' . json_encode($entrada, JSON_UNESCAPED_UNICODE) . ' => ' . json_encode($esperado), RazaoSocialMatcher::normalizar((string) $entrada) === $esperado);
    }
    $equivalente = true;
    foreach (array_merge(array_keys($golden), ['ACME & CIA LTDA', 'a/b S/A', 'Alfa-Beta, Gama (Delta) EIRELI - ME', "A\0B", 'ZZZ 123 epp']) as $entrada) {
        $equivalente = $equivalente && RazaoSocialMatcher::normalizar((string) $entrada) === $normalizarAntigo((string) $entrada);
    }
    afirmar('normalizar novo == implementacao antiga (copia verbatim) em 17 entradas ASCII', $equivalente);
    // Acentos: sempre removidos, em qualquer caixa (antes "Cafe" minusculo perdia a letra)
    foreach ([
        'Café Ação Ltda' => 'CAFE ACAO', 'CAFÉ AÇÃO' => 'CAFE ACAO', 'Açúcar & Álcool S/A' => 'ACUCAR ALCOOL',
        'Ünïcödé Comércio EIRELI - ME' => 'UNICODE COMERCIO', 'Maçã e Pêra S.A.' => 'MACA E PERA', 'ÀÉÎÕÜ' => 'AEIOU',
        'Café São João LTDA ME' => 'CAFE SAO JOAO', "Cafe\u{0301} Central" => 'CAFE CENTRAL', 'ß æ œ' => 'SS AE OE', 'Ørsted Łódź Đakovo' => 'ORSTED LODZ DAKOVO',
    ] as $entrada => $esperado) {
        afirmar('normalizar sem acento ' . json_encode($entrada, JSON_UNESCAPED_UNICODE) . ' => ' . json_encode($esperado), RazaoSocialMatcher::normalizar((string) $entrada) === $esperado);
    }
    foreach (['日本語', 'ЖУК', '😀'] as $entrada) {
        afirmar('normalizar: alfabeto sem mapa continua virando espaco ' . json_encode($entrada, JSON_UNESCAPED_UNICODE) . ' => ""', RazaoSocialMatcher::normalizar($entrada) === '');
    }
    afirmar('normalizar: idempotente sobre texto ja normalizado (o OCR reaplica)', RazaoSocialMatcher::normalizar(RazaoSocialMatcher::normalizar('Acme Comercio LTDA')) === 'ACME COMERCIO');
    $fonteMatcher = codigoSemComentarios($raiz . '/util/RazaoSocialMatcher.php');
    afirmar('RazaoSocialMatcher: mesma lista de SUFIXOS; sem setlocale e SEM iconv (deterministico, independe de locale); usa mb_strtoupper', !str_contains($fonteMatcher, 'setlocale') && !str_contains($fonteMatcher, 'iconv') && str_contains($fonteMatcher, 'mb_strtoupper') && str_contains($fonteMatcher, "'LTDA ME', 'LTDA', 'S A', 'S/A', 'SA', 'EIRELI', 'ME', 'EPP', 'MEI', 'CIA'"));

    // =====================================================================
    // B. NomeCadastro (sem banco)
    // =====================================================================
    afirmar('nome: trim e colapso de espacos (inclui NBSP)', NomeCadastro::normalizar("  Acme \u{00A0} Ltda  ", 2, 150) === 'Acme Ltda');
    afirmar('nome: 2 e 150 caracteres passam; 1 e 151 nao (conta caracteres, nao bytes)', NomeCadastro::normalizar('ab', 2, 150) === 'ab' && NomeCadastro::normalizar(str_repeat('é', 150), 2, 150) !== null && NomeCadastro::normalizar('a', 2, 150) === null && NomeCadastro::normalizar(str_repeat('é', 151), 2, 150) === null);
    foreach ([["A\0B", 'NUL'], ["A\nB", 'quebra de linha'], ["A\tB", 'tab'], ["A\x07B", 'BEL'], ["A\x7FB", 'DEL'], ["AB\u{202E}CD", 'RTL override U+202E'], ["A\u{200B}B", 'zero-width U+200B'], ["A\u{2028}B", 'separador de linha U+2028'], ["\xFF\xFEabc", 'UTF-8 invalido'], [str_repeat('x', 1200), 'entrada gigante']] as [$entrada, $rot]) {
        afirmar("nome recusado ($rot)", NomeCadastro::normalizar($entrada, 2, 150) === null);
    }
    // O1: invisiveis/visualmente vazios e excesso de marcas combinantes
    foreach ([["A\u{3164}B", 'U+3164 hangul filler'], ["A\u{2800}B", 'U+2800 braille em branco'], ["A\u{FE0F}B", 'U+FE0F seletor de variacao'], ["A\u{034F}B", 'U+034F combining grapheme joiner'], ["A\u{115F}B", 'U+115F hangul choseong filler'], ["A\u{1160}B", 'U+1160 hangul jungseong filler'], ["A\u{FFA0}B", 'U+FFA0 hangul filler meia largura'], ["A\u{E0041}B", 'tag char U+E0041'], ["A\u{E0001}B", 'tag char U+E0001'], ["A\u{E0000}B", 'U+E0000 (inicio da faixa de tags)'], ["A\u{E007F}B", 'tag cancel U+E007F'], ["\u{3164}", 'so U+3164'], ["Cafe\u{0301}\u{0302}\u{0303}\u{0304}", '4 marcas Mn seguidas'], ["Z\u{0300}\u{0301}\u{0302}\u{0303}\u{0304}\u{0305}\u{0306}", 'zalgo (7 marcas)']] as [$entrada, $rot]) {
        afirmar("nome recusado ($rot)", NomeCadastro::normalizar($entrada, 1, 150) === null);
    }
    foreach ([["Cafe\u{0301}\u{0302}\u{0303}", '3 marcas Mn seguidas (limite)'], ["a\u{0301}b\u{0301}c\u{0301}d\u{0301}e\u{0301}", 'varias marcas, no maximo 1 seguida'], ['Café São João Ltda', 'acentos normais'], ['Ørsted Åland & Cia.', 'letras nao ASCII'], ['شركة النور', 'arabe sem formatacao']] as [$entrada, $rot]) {
        afirmar("nome aceito ($rot)", NomeCadastro::normalizar($entrada, 1, 150) === $entrada);
    }
    $docNome = (string) file_get_contents($raiz . '/util/NomeCadastro.php');
    afirmar('NomeCadastro: docblock descreve exatamente o bloqueio (categorias e os 7 codepoints, faixa de tags e limite de 3 marcas)', str_contains($docNome, 'Cc') && str_contains($docNome, 'Cf') && str_contains($docNome, 'Zl') && str_contains($docNome, 'Zp') && array_reduce(['U+3164', 'U+2800', 'U+FE0F', 'U+034F', 'U+115F', 'U+1160', 'U+FFA0', 'U+E0000..U+E007F', 'mais de 3 marcas combinantes'], static fn ($c, $t) => $c && str_contains($docNome, $t), true));
    $docAud = (string) preg_replace('/\s*\n\s*\*?\s*/', ' ', (string) file_get_contents($raiz . '/app/Dao/AuditoriaDao.php'));
    $docBase = (string) file_get_contents($raiz . '/app/Rn/CadastroGestaoBase.php');
    afirmar('O4: docblock de AuditoriaDao::abrir e da base de cadastros descrevem o uso real (PENDENTE dentro da transacao, desfeito no rollback, ERRO gravado depois); frase antiga removida', !str_contains($docAud, 'fora de qualquer transacao do chamador') && str_contains($docAud, 'DENTRO da transacao') && str_contains($docAud, 'rollback desfaz o PENDENTE') && str_contains($docBase, 'DENTRO da transacao') && str_contains($docBase, '`registrarErro` grava uma linha ERRO'));
    afirmar('textoBusca: valido volta limpo; controle, UTF-8 invalido e longo => vazio', NomeCadastro::textoBusca('  ac  me ') === 'ac me' && NomeCadastro::textoBusca("a\0b") === '' && NomeCadastro::textoBusca("\xFFabc") === '' && NomeCadastro::textoBusca(str_repeat('a', 61)) === '');
    afirmar('cnpjValido (gerador do teste) gera CNPJs aceitos pelo CnpjValidador', CnpjValidador::normalizarEValidar(cnpjValido('112223330001')) === cnpjValido('112223330001') && cnpjValido('112223330001') === '11222333000181');

    // =====================================================================
    // C. Ambiente QA e fixtures
    // =====================================================================
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $bancos[] = $banco;
    $idAdmin = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    $idAdmin2 = gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Admin');
    $idUsu = gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    $cnpjUdlog = CnpjValidador::CNPJ_UDLOG_1;
    $emp = static function (string $nome, string $cnpj, int $ativo = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES (:n, :c, :a)')->execute(['n' => $nome, 'c' => $cnpj, 'a' => $ativo]);

        return (int) $pdo->lastInsertId();
    };
    $totemDireto = static function (string $codigo, string $nome, ?int $empresa, int $ativo = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES (:c, :n, :e, :t, :a)')->execute(['c' => $codigo, 'n' => $nome, 'e' => $empresa, 't' => bin2hex(random_bytes(32)), 'a' => $ativo]);

        return (int) $pdo->lastInsertId();
    };
    $uuid = static fn (): string => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    $atend = static function (int $idTotem, string $status, ?string $cnpj) use ($pdo, $uuid): int {
        $pdo->prepare("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, cliente_cnpj) VALUES (:c, :t, 'recebimento', :s, :cnpj)")->execute(['c' => $uuid(), 't' => $idTotem, 's' => $status, 'cnpj' => $cnpj]);

        return (int) $pdo->lastInsertId();
    };
    $nota = static function (int $idAtend, int $ordem, ?string $cnpjEmitente) use ($pdo): void {
        $pdo->prepare("INSERT INTO tb_atendimento_nota (id_atendimento, ordem, arquivo, cnpj_emitente) VALUES (:a, :o, 'x.jpg', :c)")->execute(['a' => $idAtend, 'o' => $ordem, 'c' => $cnpjEmitente]);
    };
    $linhasCli = static fn (int $id): array => gtLinhas($pdo, 'SELECT * FROM tb_cliente WHERE id_cliente = :i', ['i' => $id])[0] ?? [];
    $linhasEmp = static fn (int $id): array => gtLinhas($pdo, 'SELECT * FROM tb_empresa WHERE id_empresa = :i', ['i' => $id])[0] ?? [];
    $audit = static fn (string $acao): array => gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria WHERE acao = :a ORDER BY id_auditoria', ['a' => $acao]);
    $nAudit = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria');
    $snapAtend = static fn (): string => md5(json_encode([gtLinhas($pdo, 'SELECT * FROM tb_atendimento ORDER BY id_atendimento'), gtLinhas($pdo, 'SELECT * FROM tb_atendimento_nota ORDER BY id_nota')]));
    $snapTotens = static fn (): string => md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_totem ORDER BY id_totem')));
    $nCli = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente');
    $nEmp = static fn (): int => (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_empresa');
    $rnC = new ClienteGestaoRn($pdo);
    $rnE = new EmpresaGestaoRn($pdo);
    $cliDao = new ClienteDao($pdo);
    $empDao = new EmpresaDao($pdo);

    $nCliInicial = $nCli();
    afirmar('schema: tb_cliente com razao_social_normalizada e UNIQUE em cnpj; tb_empresa com UNIQUE em cnpj', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tb_cliente','tb_empresa') AND INDEX_NAME = 'cnpj' AND NON_UNIQUE = 0") === 2);

    // ---------------------------------------------------------------- clientes: criar
    $cnpjA = cnpjValido('112223330001');
    $r = $rnC->criar($idAdmin, '  Acme   Comercio  LTDA ', mascarar($cnpjA), '', '203.0.113.5');
    $idA = (int) ($r['id'] ?? 0);
    $a = $linhasCli($idA);
    afirmar('cliente criar: ok, nome com espacos colapsados, CNPJ gravado SO com digitos (veio mascarado), ativo por padrao', $r['ok'] === true && $idA > 0 && $a['nome'] === 'Acme Comercio LTDA' && $a['cnpj'] === $cnpjA && (int) $a['ativo'] === 1);
    afirmar('cliente criar: razao_social_normalizada = RazaoSocialMatcher::normalizar(nome)', $a['razao_social_normalizada'] === RazaoSocialMatcher::normalizar('Acme Comercio LTDA') && $a['razao_social_normalizada'] === 'ACME COMERCIO');
    $audC = $audit('CLIENTE_CRIAR');
    afirmar('cliente criar: auditoria OK, alvo cliente/id, detalhe SO ativo_para=1, IP gravado, sem nome nem CNPJ', count($audC) === 1 && $audC[0]['resultado'] === 'OK' && $audC[0]['alvo_tipo'] === 'cliente' && (int) $audC[0]['alvo_id'] === $idA && $audC[0]['detalhe'] === 'ativo_para=1' && (int) $audC[0]['id_usuario'] === $idAdmin && $audC[0]['ip'] !== null);
    $rI = $rnC->criar($idAdmin, 'Beta Inativa SA', cnpjValido('223334440001'), '0');
    afirmar('cliente criar com ativo=0: grava inativo; auditoria ativo_para=0', $rI['ok'] === true && (int) $linhasCli((int) $rI['id'])['ativo'] === 0 && $audit('CLIENTE_CRIAR')[1]['detalhe'] === 'ativo_para=0');

    // validacao de CNPJ
    $antes = $nCli();
    $antesAud = $nAudit();
    foreach ([['', 'vazio'], ['123', 'curto'], [cnpjValido('112223330001') === '11222333000181' ? '11222333000182' : '1', 'digito verificador errado'], ['00000000000000', 'zeros'], ['11111111111111', 'repetido'], ['abc', 'letras'], ['11222333000181abc', 'letras coladas'], ["11222333000181\0", 'NUL'], [str_repeat('1', 40), 'gigante'], ['１１２２２３３３０００１８１', 'digitos largos (fullwidth)']] as [$cnpjRuim, $rot]) {
        $x = $rnC->criar($idAdmin, 'Cliente Valido SA', $cnpjRuim, '');
        afirmar("cliente criar recusa CNPJ ($rot): validacao no campo cnpj e nada criado", $x['ok'] === false && ($x['codigo'] ?? '') === 'validacao' && isset($x['erros']['cnpj']) && $nCli() === $antes);
    }
    afirmar('cliente criar: validacao de formato nao gera auditoria (so recusas de regra)', $nAudit() === $antesAud);
    foreach ([$cnpjUdlog, mascarar($cnpjUdlog), CnpjValidador::CNPJ_UDLOG_2, mascarar(CnpjValidador::CNPJ_UDLOG_2)] as $u) {
        $x = $rnC->criar($idAdmin, 'Falsa Udlog SA', $u, '');
        afirmar("cliente criar BLOQUEIA o CNPJ da UDLOG ($u)", $x['ok'] === false && isset($x['erros']['cnpj']) && str_contains($x['erros']['cnpj'], 'UDLOG') && $nCli() === $antes);
    }
    // validacao de nome
    foreach ([["A\0B", 'NUL'], ["Acme\nLtda", 'quebra de linha'], ["Acme\x07", 'controle'], [str_repeat('A', 151), '151 caracteres'], ['A', '1 caractere'], ['***', 'so simbolos'], ['😀😀', 'so emoji'], ['LTDA', 'so sufixo societario'], ['ME', 'so ME'], ["Acme\u{202E}Ltda", 'RTL override'], ['   ', 'so espacos'], ['', 'vazio'], ["\xFF\xFEAcme", 'UTF-8 invalido']] as [$nomeRuim, $rot]) {
        $x = $rnC->criar($idAdmin, $nomeRuim, cnpjValido('334445550001'), '');
        afirmar("cliente criar recusa nome ($rot): validacao no campo nome e nada criado", $x['ok'] === false && isset($x['erros']['nome']) && $nCli() === $antes);
    }
    $x = $rnC->criar($idAdmin, '***', cnpjValido('334445550001'), '');
    afirmar('cliente criar: razao normalizada VAZIA => mensagem clara (letra ou numero)', str_contains((string) ($x['erros']['nome'] ?? ''), 'pelo menos uma letra ou um número'));
    $x150 = $rnC->criar($idAdmin, str_repeat('N', 150), cnpjValido('334445550001'), '');
    afirmar('cliente criar: nome de exatamente 150 caracteres e aceito', $x150['ok'] === true);
    $rnC->excluir($idAdmin, (int) $x150['id'], true);
    $xa = $rnC->criar($idAdmin, 'Acme Teste', cnpjValido('334445550001'), 'x');
    afirmar('cliente criar: ativo fora do conjunto => erro no campo ativo', $xa['ok'] === false && isset($xa['erros']['ativo']));
    $nome_arabe = $rnC->criar($idAdmin, 'شركة النور', cnpjValido('334445550001'), '');
    afirmar('cliente criar: nome em arabe (RTL) nunca da erro 500: ou recusa (razao vazia) ou grava com razao nao vazia', $nome_arabe['ok'] === false ? isset($nome_arabe['erros']['nome']) : (string) $linhasCli((int) $nome_arabe['id'])['razao_social_normalizada'] !== '');
    if ($nome_arabe['ok']) {
        $rnC->excluir($idAdmin, (int) $nome_arabe['id'], true);
    }

    // duplicidade
    $antesAud = $nAudit();
    $d1 = $rnC->criar($idAdmin, 'Outro Nome Qualquer SA', $cnpjA, '');
    afirmar('cliente criar: CNPJ duplicado (mascarado ou nao) => erro no campo cnpj SEM revelar o nome do cliente existente', $d1['ok'] === false && isset($d1['erros']['cnpj']) && !str_contains($d1['erros']['cnpj'], 'Acme') && $nCli() === $antes);
    $d2 = $rnC->criar($idAdmin, 'ACME COMERCIO', cnpjValido('445556660001'), '');
    afirmar('cliente criar: razao normalizada IGUAL a de outro cliente (Acme Comercio LTDA x ACME COMERCIO) => recusa no nome, mensagem generica sem o nome do outro', $d2['ok'] === false && isset($d2['erros']['nome']) && !str_contains($d2['erros']['nome'], 'Acme') && !str_contains($d2['erros']['nome'], 'COMERCIO'));
    $d3 = $rnC->criar($idAdmin, 'acme   comercio ltda me', cnpjValido('445556660001'), '');
    afirmar('cliente criar: igualdade apos normalizar (caixa, espacos, sufixos) e recusada tambem contra cliente INATIVO', $d3['ok'] === false && $rnC->criar($idAdmin, 'beta inativa', cnpjValido('445556660001'), '')['ok'] === false);
    $audDup = array_values(array_filter($audit('CLIENTE_CRIAR'), static fn ($l) => $l['resultado'] === 'SEM_EFEITO'));
    afirmar('cliente criar duplicado: 4 auditorias SEM_EFEITO com motivo_cad=duplicado, sem alvo e sem nome/CNPJ', count($audDup) === 4 && array_reduce($audDup, static fn ($c, $l) => $c && $l['detalhe'] === 'motivo_cad=duplicado' && $l['alvo_id'] === null, true) && $nAudit() === $antesAud + 4);

    // ---------------------------------------------------------------- clientes: editar
    $rE1 = $rnC->editar($idAdmin, $idA, 'Acme Industria e Comercio SA', '203.0.113.5');
    $a2 = $linhasCli($idA);
    afirmar('cliente editar: nome novo e razao RECALCULADA (normalizar do nome novo); CNPJ e situacao inalterados', $rE1['ok'] === true && $a2['nome'] === 'Acme Industria e Comercio SA' && $a2['razao_social_normalizada'] === RazaoSocialMatcher::normalizar('Acme Industria e Comercio SA') && $a2['razao_social_normalizada'] !== $a['razao_social_normalizada'] && $a2['cnpj'] === $cnpjA && (int) $a2['ativo'] === 1);
    $audE = $audit('CLIENTE_EDITAR');
    afirmar('cliente editar: auditoria OK, alvo cliente/id, SEM detalhe (nada de nome)', count($audE) === 1 && $audE[0]['resultado'] === 'OK' && $audE[0]['detalhe'] === null && (int) $audE[0]['alvo_id'] === $idA);
    $rE2 = $rnC->editar($idAdmin, $idA, 'Acme Industria e Comercio SA');
    afirmar('cliente editar com o mesmo nome: sem_mudanca (auditoria SEM_EFEITO ja_no_estado)', $rE2['ok'] === true && ($rE2['sem_mudanca'] ?? false) === true && $audit('CLIENTE_EDITAR')[1]['detalhe'] === 'motivo_cad=ja_no_estado' && $audit('CLIENTE_EDITAR')[1]['resultado'] === 'SEM_EFEITO');
    $pdo->prepare('UPDATE tb_cliente SET razao_social_normalizada = NULL WHERE id_cliente = :i')->execute(['i' => $idA]);
    $rE3 = $rnC->editar($idAdmin, $idA, 'Acme Industria e Comercio SA');
    afirmar('cliente editar: mesmo nome com razao gravada defasada (NULL) recalcula e grava (nao e "sem mudanca")', $rE3['ok'] === true && !($rE3['sem_mudanca'] ?? false) && $linhasCli($idA)['razao_social_normalizada'] === 'ACME INDUSTRIA E COMERCIO');
    $idB = (int) $rI['id'];
    $rE4 = $rnC->editar($idAdmin, $idB, 'acme industria e comercio');
    afirmar('cliente editar: razao igual a de OUTRO cliente => recusa no nome, nada muda', $rE4['ok'] === false && isset($rE4['erros']['nome']) && $linhasCli($idB)['nome'] === 'Beta Inativa SA');
    foreach ([['***', 'so simbolos'], ['LTDA', 'so sufixo'], [str_repeat('A', 151), '151'], ["A\0", 'NUL'], ['A', '1 char']] as [$nomeRuim, $rot]) {
        $x = $rnC->editar($idAdmin, $idA, $nomeRuim);
        afirmar("cliente editar recusa nome ($rot) e nada muda", $x['ok'] === false && isset($x['erros']['nome']) && $linhasCli($idA)['nome'] === 'Acme Industria e Comercio SA');
    }
    afirmar('cliente editar inexistente => cliente_nao_encontrado (SEM_EFEITO nao_encontrado, alvo NULL)', $rnC->editar($idAdmin, 987654, 'Qualquer Nome')['codigo'] === 'cliente_nao_encontrado' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'CLIENTE_EDITAR' AND detalhe = 'motivo_cad=nao_encontrado' AND alvo_id IS NULL") === 1);

    // ---------------------------------------------------------------- OCR / autocomplete em tempo real
    $cNovo = cnpjValido('556667770001');
    $rN = $rnC->criar($idAdmin, 'Zeta Quimica Brasileira', $cNovo, '');
    $idZ = (int) $rN['id'];
    $achado = $cliDao->buscarPorCnpj($cNovo);
    afirmar('OCR: cliente recem-criado passa a casar por CNPJ (ClienteDao::buscarPorCnpj)', $achado !== null && (int) $achado['id_cliente'] === $idZ);
    afirmar('autocomplete: cliente recem-criado casa por nome (buscarPorTermo) e por parte do CNPJ', count(array_filter($cliDao->buscarPorTermo('Zeta Qui'), static fn ($l) => (int) $l['id_cliente'] === $idZ)) === 1 && count(array_filter($cliDao->buscarPorTermo(substr($cNovo, 0, 8)), static fn ($l) => (int) $l['id_cliente'] === $idZ)) === 1);
    afirmar('OCR fuzzy: listarParaFuzzy traz a razao normalizada do novo cliente', count(array_filter($cliDao->listarParaFuzzy(), static fn ($l) => (int) $l['id_cliente'] === $idZ && $l['razao_social'] === 'ZETA QUIMICA BRASILEIRA')) === 1);
    $fuzzy = RazaoSocialMatcher::melhorCandidato('ZETA QUIMICA BRASILEIRA LTDA', $cliDao->listarParaFuzzy());
    afirmar('OCR fuzzy: o candidato do OCR identifica o cliente cadastrado pela tela (e nao e ambiguo)', $fuzzy['identificado'] === true && (int) $fuzzy['cliente']['id_cliente'] === $idZ && $fuzzy['ambiguo'] === false);
    $rnC->editar($idAdmin, $idZ, 'Zeta Quimica do Sul');
    afirmar('renomear vale na hora: o nome antigo deixa de casar e o novo casa', $cliDao->buscarPorTermo('Zeta Quimica Brasileira') === [] && count($cliDao->buscarPorTermo('Zeta Quimica do Sul')) === 1);
    $rnC->definirAtivo($idAdmin, $idZ, false, false);
    afirmar('inativar vale na hora: deixa de casar por CNPJ, por nome e no fuzzy', $cliDao->buscarPorCnpj($cNovo) === null && $cliDao->buscarPorTermo('Zeta') === [] && count(array_filter($cliDao->listarParaFuzzy(), static fn ($l) => (int) $l['id_cliente'] === $idZ)) === 0);
    $rnC->definirAtivo($idAdmin, $idZ, true, false);
    afirmar('ativar vale na hora: volta a casar', $cliDao->buscarPorCnpj($cNovo) !== null);
    $rnC->excluir($idAdmin, $idZ, true);
    afirmar('excluir vale na hora: linha apagada, nao casa mais', $linhasCli($idZ) === [] && $cliDao->buscarPorCnpj($cNovo) === null && $cliDao->buscarPorTermo('Zeta') === []);
    $rRe = $rnC->criar($idAdmin, 'Zeta Quimica do Sul', $cNovo, '');
    afirmar('apos excluir, o mesmo CNPJ e o mesmo nome podem ser cadastrados de novo', $rRe['ok'] === true);
    $idZ = (int) $rRe['id'];

    // ---------------------------------------------------------------- atendimento em andamento
    $eFix = $emp('Fixture SA', cnpjValido('990000000001'));
    $tFix = $totemDireto('FIXTURE-TOTEM-' . bin2hex(random_bytes(3)), 'FIXTURE', $eFix);
    $cAnd = cnpjValido('667778880001');
    $idAnd = (int) $rnC->criar($idAdmin, 'Gama Andamento', $cAnd, '')['id'];
    $cLim = cnpjValido('778889990001');
    $idLim = (int) $rnC->criar($idAdmin, 'Delta Limpo', $cLim, '')['id'];
    $atend($tFix, 'concluido', $cAnd);
    $atend($tFix, 'cancelado', $cAnd);
    $atend($tFix, 'bloqueado', $cAnd);
    $atend($tFix, 'em_andamento', cnpjValido('880001110001'));
    $atendNota = $atend($tFix, 'em_andamento', null);
    $nota($atendNota, 1, cnpjValido('880001110001'));
    $snap0 = $snapAtend();
    afirmar('andamento: concluido/cancelado/bloqueado e andamento de OUTRO cliente nao contam', $rnC->andamentoDoCliente($cAnd) === 0);
    $rd = $rnC->definirAtivo($idAdmin, $idAnd, false, false);
    afirmar('inativar sem atendimento em andamento: direto, sem confirmacao', $rd['ok'] === true && (int) $linhasCli($idAnd)['ativo'] === 0);
    $rnC->definirAtivo($idAdmin, $idAnd, true, false);
    $atAnd = $atend($tFix, 'em_andamento', $cAnd);
    $atMasc = $atend($tFix, 'em_andamento', mascarar($cAnd));
    $atNotaCli = $atend($tFix, 'em_andamento', null);
    $nota($atNotaCli, 1, $cAnd);
    $nota($atNotaCli, 2, cnpjValido('880001110001'));
    $snap1 = $snapAtend();
    afirmar('andamento: conta cliente_cnpj em digitos, cliente_cnpj mascarado e nota.cnpj_emitente (3 atendimentos), sem duplicar o atendimento com 2 notas', $rnC->andamentoDoCliente($cAnd) === 3);
    $antesA = $nAudit();
    $rc1 = $rnC->definirAtivo($idAdmin, $idAnd, false, false, '203.0.113.5');
    afirmar('inativar COM atendimento em andamento e SEM confirmar: confirmacao_necessaria (3) e NADA muda, sem auditoria', $rc1['ok'] === false && $rc1['codigo'] === 'confirmacao_necessaria' && $rc1['andamento'] === 3 && (int) $linhasCli($idAnd)['ativo'] === 1 && $nAudit() === $antesA);
    $rc2 = $rnC->definirAtivo($idAdmin, $idAnd, false, true, '203.0.113.5');
    $audAt = $audit('CLIENTE_ATIVO');
    afirmar('inativar com confirmacao: executa; auditoria OK com ativo_para=0 e motivo_cad=andamento_confirmado', $rc2['ok'] === true && (int) $linhasCli($idAnd)['ativo'] === 0 && end($audAt)['detalhe'] === 'ativo_para=0;motivo_cad=andamento_confirmado' && end($audAt)['resultado'] === 'OK');
    $rc3 = $rnC->definirAtivo($idAdmin, $idAnd, true, false);
    afirmar('ativar nunca pede confirmacao (mesmo com atendimento em andamento)', $rc3['ok'] === true && (int) $linhasCli($idAnd)['ativo'] === 1);
    $rc4 = $rnC->definirAtivo($idAdmin, $idAnd, true, false);
    afirmar('ativar quem ja esta ativo: sem_mudanca + SEM_EFEITO ja_no_estado', $rc4['ok'] === true && ($rc4['sem_mudanca'] ?? false) === true && ultima($audit('CLIENTE_ATIVO'))['detalhe'] === 'ativo_para=1;motivo_cad=ja_no_estado');
    afirmar('inativar/ativar nao alterou tb_atendimento nem tb_atendimento_nota (historico guarda copia em texto)', $snapAtend() === $snap1);
    // excluir: SEMPRE em dois passos
    $antesA = $nAudit();
    $rx1 = $rnC->excluir($idAdmin, $idLim, false);
    afirmar('excluir SEM confirmar (mesmo sem atendimento): confirmacao_necessaria (0), nada muda, sem auditoria', $rx1['ok'] === false && $rx1['codigo'] === 'confirmacao_necessaria' && $rx1['andamento'] === 0 && $linhasCli($idLim) !== [] && $nAudit() === $antesA);
    $rx2 = $rnC->excluir($idAdmin, $idAnd, false);
    afirmar('excluir COM atendimento em andamento e sem confirmar: confirmacao_necessaria com a contagem (3)', $rx2['codigo'] === 'confirmacao_necessaria' && $rx2['andamento'] === 3 && $linhasCli($idAnd) !== []);
    $rx3 = $rnC->excluir($idAdmin, $idAnd, true, '203.0.113.5');
    $audX = $audit('CLIENTE_EXCLUIR');
    afirmar('excluir com confirmacao: linha APAGADA; auditoria OK com motivo_cad=andamento_confirmado, alvo cliente/id', $rx3['ok'] === true && $linhasCli($idAnd) === [] && (array_values(array_filter($audX, static fn ($l) => (int) $l['alvo_id'] === $idAnd))[0]['detalhe'] ?? '') === 'motivo_cad=andamento_confirmado' && (array_values(array_filter($audX, static fn ($l) => (int) $l['alvo_id'] === $idAnd))[0]['resultado'] ?? '') === 'OK');
    afirmar('excluir nao alterou tb_atendimento nem tb_atendimento_nota (continuam com o CNPJ em texto)', $snapAtend() === $snap1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_atendimento WHERE cliente_cnpj = :c', ['c' => $cAnd]) === 4);
    $rx4 = $rnC->excluir($idAdmin, $idAnd, true);
    afirmar('excluir de novo (ja excluido): idempotente => cliente_nao_encontrado, sem erro; auditoria SEM_EFEITO nao_encontrado', $rx4['ok'] === false && $rx4['codigo'] === 'cliente_nao_encontrado' && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'CLIENTE_EXCLUIR' AND resultado = 'SEM_EFEITO' AND detalhe = 'motivo_cad=nao_encontrado' AND alvo_id IS NULL") === 1);
    $rx5 = $rnC->excluir($idAdmin, $idLim, true);
    afirmar('excluir sem atendimento: auditoria OK SEM detalhe', $rx5['ok'] === true && ultima($audit('CLIENTE_EXCLUIR'))['detalhe'] === null);
    // RBAC no Rn
    $snapCli = md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_cliente ORDER BY id_cliente')));
    foreach ([[$idUsu, 'usuario'], [999999, 'ator inexistente']] as [$ator, $rot]) {
        $res = [
            $rnC->criar($ator, 'Intruso Comercio', cnpjValido('990001110001'), ''),
            $rnC->editar($ator, $idZ, 'Intruso Editado'),
            $rnC->definirAtivo($ator, $idZ, false, true),
            $rnC->excluir($ator, $idZ, true),
        ];
        afirmar("Rn cliente: ator $rot => sem_permissao em criar/editar/ativo/excluir e nada muda", array_reduce($res, static fn ($c, $x) => $c && $x['ok'] === false && $x['codigo'] === 'sem_permissao', true) && md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_cliente ORDER BY id_cliente'))) === $snapCli);
    }
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idAdmin2]);
    afirmar('Rn cliente: admin DESATIVADO (sessao antiga) => sem_permissao', $rnC->excluir($idAdmin2, $idZ, true)['codigo'] === 'sem_permissao' && $linhasCli($idZ) !== []);
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = :i')->execute(['i' => $idAdmin2]);

    // ---------------------------------------------------------------- A1: ambiguidade do OCR (confirmacao em dois passos)
    $ambCnpj = static fn (int $n): string => cnpjValido(sprintf('%012d', 880000000000 + $n));
    $snapTodosCli = static fn (): string => md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_cliente ORDER BY id_cliente')));
    $detalheAmb = 'motivo_cad=confirmado_ambiguidade_ocr';
    $rAl = $rnC->criar($idAdmin, 'ALLIANCE', $ambCnpj(1), '');
    $idAl = (int) ($rAl['id'] ?? 0);
    afirmar('ambiguidade: cliente sem conflito (ALLIANCE) e criado direto, sem confirmacao e sem motivo_cad', $rAl['ok'] === true && $idAl > 0 && ultima($audit('CLIENTE_CRIAR'))['detalhe'] === 'ativo_para=1');
    $snapA1 = $snapTodosCli();
    $audA1 = $nAudit();
    $rAq = $rnC->criar($idAdmin, 'Alliance Quimica Ltda', $ambCnpj(2), '');
    afirmar('ambiguidade: "ALLIANCE" + "Alliance Quimica Ltda" => confirmacao_ambiguidade (total 1, nomes [ALLIANCE])', $rAq['ok'] === false && ($rAq['codigo'] ?? '') === 'confirmacao_ambiguidade' && $rAq['ambiguidade'] === ['total' => 1, 'nomes' => ['ALLIANCE']]);
    afirmar('ambiguidade 1o passo: NADA muda (nenhum cliente criado) e nenhuma auditoria gravada', $snapTodosCli() === $snapA1 && $nAudit() === $audA1);
    $rAqX = $rnC->criar($idAdmin, 'Alliance Quimica Ltda', $ambCnpj(2), '', null, false);
    afirmar('ambiguidade: confirmar=false explicito continua pedindo confirmacao', ($rAqX['codigo'] ?? '') === 'confirmacao_ambiguidade' && $snapTodosCli() === $snapA1);
    $rAq2 = $rnC->criar($idAdmin, 'Alliance Quimica Ltda', $ambCnpj(2), '', '203.0.113.5', true);
    $idAq = (int) ($rAq2['id'] ?? 0);
    $audAq = ultima($audit('CLIENTE_CRIAR'));
    afirmar('ambiguidade 2o passo (confirmar=1): cria; auditoria OK com ativo_para=1;motivo_cad=confirmado_ambiguidade_ocr, sem nome/CNPJ', $rAq2['ok'] === true && $idAq > 0 && $audAq['resultado'] === 'OK' && $audAq['detalhe'] === 'ativo_para=1;' . $detalheAmb && (int) $audAq['alvo_id'] === $idAq);
    $fzAl = RazaoSocialMatcher::melhorCandidato('ALLIANCE', $cliDao->listarParaFuzzy());
    afirmar('ambiguidade: o aviso era real (o OCR agora acha ALLIANCE ambiguo)', $fzAl['ambiguo'] === true && $fzAl['identificado'] === false);
    $rZb = $rnC->criar($idAdmin, 'Zorba Embalagens', $ambCnpj(3), '');
    $idZb = (int) ($rZb['id'] ?? 0);
    afirmar('ambiguidade: nome sem conflito (Zorba Embalagens) cria direto, sem confirmacao e sem motivo_cad', $rZb['ok'] === true && ultima($audit('CLIENTE_CRIAR'))['detalhe'] === 'ativo_para=1');
    // cliente criado INATIVO nao afeta o OCR: nao pede confirmacao
    $rSul = $rnC->criar($idAdmin, 'Alliance Quimica Sul', $ambCnpj(4), '0');
    $idSul = (int) ($rSul['id'] ?? 0);
    afirmar('ambiguidade: criar INATIVO com nome parecido NAO pede confirmacao (inativo nao entra no OCR)', $rSul['ok'] === true && ultima($audit('CLIENTE_CRIAR'))['detalhe'] === 'ativo_para=0');
    $snapA2 = $snapTodosCli();
    $audA2 = $nAudit();
    $rAt = $rnC->definirAtivo($idAdmin, $idSul, true, false);
    afirmar('ambiguidade: ATIVAR cliente inativo que causaria ambiguidade => confirmacao_ambiguidade (total 2: o Ltda e o proprio), nada muda', ($rAt['codigo'] ?? '') === 'confirmacao_ambiguidade' && $rAt['ambiguidade'] === ['total' => 2, 'nomes' => ['Alliance Quimica Ltda', 'Alliance Quimica Sul']] && $snapTodosCli() === $snapA2 && $nAudit() === $audA2);
    afirmar('ambiguidade: ambiguidadeAoAtivar (tela do 1o passo) devolve a mesma contagem; cliente ja ativo/ausente = 0', $rnC->ambiguidadeAoAtivar($idSul) === ['total' => 2, 'nomes' => ['Alliance Quimica Ltda', 'Alliance Quimica Sul']] && $rnC->ambiguidadeAoAtivar($idAl)['total'] === 0 && $rnC->ambiguidadeAoAtivar(987654)['total'] === 0);
    $rAt2 = $rnC->definirAtivo($idAdmin, $idSul, true, true, '203.0.113.5');
    afirmar('ambiguidade: ativar com confirmacao executa; auditoria ativo_para=1;motivo_cad=confirmado_ambiguidade_ocr', $rAt2['ok'] === true && (int) $linhasCli($idSul)['ativo'] === 1 && ultima($audit('CLIENTE_ATIVO'))['detalhe'] === 'ativo_para=1;' . $detalheAmb);
    $rnC->excluir($idAdmin, $idSul, true);
    $rnC->excluir($idAdmin, $idAq, true);
    // editar (nome)
    $snapA3 = $snapTodosCli();
    $audA3 = $nAudit();
    $rEa = $rnC->editar($idAdmin, $idZb, 'Alliance Embalagens');
    afirmar('ambiguidade: EDITAR o nome de cliente ativo para um parecido (Zorba => Alliance Embalagens) => confirmacao_ambiguidade (total 1, [ALLIANCE]); nada muda', ($rEa['codigo'] ?? '') === 'confirmacao_ambiguidade' && $rEa['ambiguidade'] === ['total' => 1, 'nomes' => ['ALLIANCE']] && $snapTodosCli() === $snapA3 && $nAudit() === $audA3);
    $rEa2 = $rnC->editar($idAdmin, $idZb, 'Alliance Embalagens', '203.0.113.5', true);
    afirmar('ambiguidade: editar com confirmacao grava e audita CLIENTE_EDITAR com motivo_cad=confirmado_ambiguidade_ocr (sem nome)', $rEa2['ok'] === true && $linhasCli($idZb)['nome'] === 'Alliance Embalagens' && ultima($audit('CLIENTE_EDITAR'))['detalhe'] === $detalheAmb && ultima($audit('CLIENTE_EDITAR'))['resultado'] === 'OK');
    $rEb = $rnC->editar($idAdmin, $idZb, 'Zorba Plasticos');
    afirmar('ambiguidade: editar para nome SEM conflito (e que desfaz a ambiguidade) => direto, sem motivo_cad', $rEb['ok'] === true && ultima($audit('CLIENTE_EDITAR'))['detalhe'] === null);
    $rnC->definirAtivo($idAdmin, $idZb, false, false);
    $rEc = $rnC->editar($idAdmin, $idZb, 'Alliance Quimica');
    afirmar('ambiguidade: editar o nome de cliente INATIVO nao pede confirmacao (nao entra no OCR)', $rEc['ok'] === true && $linhasCli($idZb)['nome'] === 'Alliance Quimica' && ultima($audit('CLIENTE_EDITAR'))['detalhe'] === null);
    $rAt3 = $rnC->definirAtivo($idAdmin, $idZb, true, false);
    afirmar('ambiguidade: depois, ATIVAR esse cliente pede confirmacao (total 1) e nada muda', ($rAt3['codigo'] ?? '') === 'confirmacao_ambiguidade' && $rAt3['ambiguidade']['total'] === 1 && (int) $linhasCli($idZb)['ativo'] === 0);
    $rAt4 = $rnC->definirAtivo($idAdmin, $idZb, true, true);
    afirmar('ambiguidade: ativar confirmado grava ativo=1 com o motivo novo', $rAt4['ok'] === true && (int) $linhasCli($idZb)['ativo'] === 1 && ultima($audit('CLIENTE_ATIVO'))['detalhe'] === 'ativo_para=1;' . $detalheAmb);
    // ativar sem conflito: direto
    $rnC->definirAtivo($idAdmin, $idZb, false, false);
    $rnC->editar($idAdmin, $idZb, 'Zorba Plasticos');
    $rAt5 = $rnC->definirAtivo($idAdmin, $idZb, true, false);
    afirmar('ambiguidade: ativar cliente SEM conflito => direto, sem confirmacao e sem motivo_cad', $rAt5['ok'] === true && ultima($audit('CLIENTE_ATIVO'))['detalhe'] === 'ativo_para=1');
    // a auditoria nunca leva nome nem CNPJ
    afirmar('ambiguidade: nenhuma auditoria cita nome/CNPJ dos clientes do teste', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE detalhe LIKE '%ALLIANCE%' OR detalhe LIKE '%Alliance%' OR detalhe LIKE '%880000%'") === 0);
    // falha segura: mais de 500 clientes ativos => simulacao pulada, cria sem aviso
    $fillSql = $pdo->prepare('INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:n, :r, :c, 1)');
    $ativosAgora = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_cliente WHERE ativo = 1 AND razao_social_normalizada <> ''");
    for ($i = 1; $i <= 501 - $ativosAgora + 1; $i++) {
        $nf = sprintf('QX%04d', $i);
        $fillSql->execute(['n' => $nf, 'r' => $nf, 'c' => $ambCnpj(1000 + $i)]);
    }
    $rGrande = $rnC->criar($idAdmin, 'Alliance Quimica Ltda', $ambCnpj(5), '');
    afirmar('ambiguidade: mais de 500 clientes ATIVOS => simulacao pulada (disponibilidade): cria SEM aviso e sem motivo_cad', $rGrande['ok'] === true && ultima($audit('CLIENTE_CRIAR'))['detalhe'] === 'ativo_para=1');
    $pdo->exec("DELETE FROM tb_cliente WHERE razao_social_normalizada LIKE 'QX%'");
    $rnC->excluir($idAdmin, (int) $rGrande['id'], true);
    $rnC->excluir($idAdmin, $idZb, true);
    $rnC->excluir($idAdmin, $idAl, true);
    afirmar('ambiguidade: fixtures removidos (sem residuos)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_cliente WHERE cnpj LIKE '88000000%'") === 0 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_cliente WHERE razao_social_normalizada LIKE 'QX%'") === 0);

    // ---------------------------------------------------------------- empresas
    $eNova =$rnE->criar($idAdmin, '  Maua   III ', mascarar(cnpjValido('121212120001')), '203.0.113.5');
    $idE = (int) ($eNova['id'] ?? 0);
    $e = $linhasEmp($idE);
    afirmar('empresa criar: ok, nome colapsado, CNPJ so digitos, ativa; auditoria EMPRESA_CRIAR OK alvo empresa/id sem detalhe', $eNova['ok'] === true && $e['nome'] === 'Maua III' && $e['cnpj'] === cnpjValido('121212120001') && (int) $e['ativo'] === 1 && $audit('EMPRESA_CRIAR')[0]['alvo_tipo'] === 'empresa' && (int) $audit('EMPRESA_CRIAR')[0]['alvo_id'] === $idE && $audit('EMPRESA_CRIAR')[0]['detalhe'] === null && $audit('EMPRESA_CRIAR')[0]['resultado'] === 'OK');
    $eUdlog = $rnE->criar($idAdmin, 'Maua I', $cnpjUdlog);
    afirmar('empresa criar: ACEITA o CNPJ da UDLOG (caso normal das empresas)', $eUdlog['ok'] === true && $linhasEmp((int) $eUdlog['id'])['cnpj'] === $cnpjUdlog);
    $eUdlog2 = $rnE->criar($idAdmin, 'Maua II', mascarar(CnpjValidador::CNPJ_UDLOG_2));
    afirmar('empresa criar: segundo CNPJ da UDLOG (filial) tambem aceito', $eUdlog2['ok'] === true);
    $antesE = $nEmp();
    $antesAud = $nAudit();
    foreach ([['***', 'so simbolos'], ['😀😀', 'so emoji'], ['A', '1 caractere'], ['ABCDEFGHIJKLMNOPQ', '17 letras (slug 17)'], ['Empresa Muito Longa Demais Ltda', 'slug longo'], ['Maua-+-+', 'nome valido mas ...'], ["A\0B", 'NUL'], ["A\nB", 'quebra de linha'], [str_repeat('A', 101), '101 caracteres'], ["Maua\u{202E}I", 'RTL override'], ['', 'vazio'], ['çãé', 'so acentos que viram apenas letras (c a e = 3)']] as [$nomeRuim, $rot]) {
        $x = $rnE->criar($idAdmin, $nomeRuim, cnpjValido('343434340001'));
        $esperaRecusa = !in_array($rot, ['nome valido mas ...', 'so acentos que viram apenas letras (c a e = 3)'], true);
        if ($esperaRecusa) {
            afirmar("empresa criar recusa nome ($rot): validacao no campo nome e nada criado", $x['ok'] === false && isset($x['erros']['nome']) && $nEmp() === $antesE);
        } else {
            afirmar("empresa criar aceita nome ($rot) cujo slug e valido", $x['ok'] === true);
            $rnE->excluir($idAdmin, (int) $x['id'], true);
        }
    }
    $x = $rnE->criar($idAdmin, '***', cnpjValido('343434340001'));
    afirmar('empresa criar: slug invalido => mensagem clara (1 a 16 letras ou numeros sem espacos nem acentos)', str_contains($x['erros']['nome'], '1 a 16 letras ou números') && str_contains($x['erros']['nome'], 'Ajuste o nome'));
    $x16 = $rnE->criar($idAdmin, 'Empresa16Chars00', cnpjValido('343434340001'));
    afirmar('empresa criar: slug de exatamente 16 caracteres aceito', $x16['ok'] === true);
    $rnE->excluir($idAdmin, (int) $x16['id'], true);
    foreach ([['', 'vazio'], ['123', 'curto'], ['11222333000182', 'DV errado'], ['abc', 'letras']] as [$cnpjRuim, $rot]) {
        $x = $rnE->criar($idAdmin, 'Empresa Teste', $cnpjRuim);
        afirmar("empresa criar recusa CNPJ ($rot)", $x['ok'] === false && isset($x['erros']['cnpj']) && $nEmp() === $antesE);
    }
    $xd = $rnE->criar($idAdmin, 'Outra Empresa', $cnpjUdlog);
    afirmar('empresa criar: CNPJ duplicado => erro no campo cnpj; auditoria SEM_EFEITO duplicado', $xd['ok'] === false && isset($xd['erros']['cnpj']) && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'EMPRESA_CRIAR' AND resultado = 'SEM_EFEITO' AND detalhe = 'motivo_cad=duplicado'") === 1);

    // editar
    $t1 = $totemDireto('TOTEM-A-MAUAIII-AAAAAAAAAAAAAAAA', 'TOTEM-A', $idE);
    $t2 = $totemDireto('TOTEM-B-MAUAIII-BBBBBBBBBBBBBBBB', 'TOTEM-B', $idE, 0);
    $snapT = $snapTotens();
    $re1 = $rnE->editar($idAdmin, $idE, 'Mauá Três', '203.0.113.5');
    afirmar('empresa editar: renomeia (CNPJ e situacao inalterados); URLs/codigos dos totens existentes NAO mudam', $re1['ok'] === true && $linhasEmp($idE)['nome'] === 'Mauá Três' && $linhasEmp($idE)['cnpj'] === cnpjValido('121212120001') && (int) $linhasEmp($idE)['ativo'] === 1 && $snapTotens() === $snapT);
    afirmar('empresa editar: auditoria EMPRESA_EDITAR OK sem detalhe', $audit('EMPRESA_EDITAR')[0]['resultado'] === 'OK' && $audit('EMPRESA_EDITAR')[0]['detalhe'] === null);
    foreach ([['***', 'slug vazio'], ['Empresa Muito Longa Demais Ltda', 'slug longo'], ['A', '1 char'], ["A\0", 'NUL']] as [$nomeRuim, $rot]) {
        $x = $rnE->editar($idAdmin, $idE, $nomeRuim);
        afirmar("empresa editar recusa nome ($rot) e nada muda", $x['ok'] === false && isset($x['erros']['nome']) && $linhasEmp($idE)['nome'] === 'Mauá Três');
    }
    $re2 = $rnE->editar($idAdmin, $idE, 'Mauá Três');
    afirmar('empresa editar com o mesmo nome: sem_mudanca (SEM_EFEITO ja_no_estado)', ($re2['sem_mudanca'] ?? false) === true && $audit('EMPRESA_EDITAR')[1]['detalhe'] === 'motivo_cad=ja_no_estado');
    afirmar('empresa editar inexistente => empresa_nao_encontrada', $rnE->editar($idAdmin, 987654, 'Qualquer')['codigo'] === 'empresa_nao_encontrada');
    $lista = array_values(array_filter($rnE->listar(), static fn ($l) => (int) $l['id_empresa'] === $idE))[0];
    afirmar('empresa listar: conta totens ativos (1) e total (2)', (int) $lista['totens_ativos'] === 1 && (int) $lista['totens_total'] === 2);
    afirmar('empresa obter: totens_ativos e totens_total', $rnE->obter($idE)['totens_ativos'] === 1 && $rnE->obter($idE)['totens_total'] === 2 && $rnE->obter(987654) === null);

    // inativar / ativar e o efeito no Talent
    afirmar('Talent: EmpresaDao::buscarPorId enxerga a empresa ativa', $empDao->buscarPorId($idE) !== null);
    $antesA = $nAudit();
    $ri1 = $rnE->definirAtivo($idAdmin, $idE, false, false);
    afirmar('empresa inativar com 1 totem ATIVO e sem confirmar: confirmacao_necessaria (1) e NADA muda, sem auditoria, totens intactos', $ri1['ok'] === false && $ri1['codigo'] === 'confirmacao_necessaria' && $ri1['totens_ativos'] === 1 && (int) $linhasEmp($idE)['ativo'] === 1 && $nAudit() === $antesA && $snapTotens() === $snapT);
    $ri2 = $rnE->definirAtivo($idAdmin, $idE, false, true, '203.0.113.5');
    $audEA = $audit('EMPRESA_ATIVO');
    afirmar('empresa inativar com confirmacao: executa; auditoria OK ativo_para=0;motivo_cad=totens_ativos_confirmado; totens intactos', $ri2['ok'] === true && (int) $linhasEmp($idE)['ativo'] === 0 && $audEA[0]['detalhe'] === 'ativo_para=0;motivo_cad=totens_ativos_confirmado' && $snapTotens() === $snapT);
    afirmar('Talent: empresa inativa deixa de ser encontrada (EmpresaDao::buscarPorId = null) na hora', $empDao->buscarPorId($idE) === null);
    $ri3 = $rnE->definirAtivo($idAdmin, $idE, false, true);
    afirmar('inativar de novo: sem_mudanca (SEM_EFEITO ja_no_estado)', ($ri3['sem_mudanca'] ?? false) === true && ultima($audit('EMPRESA_ATIVO'))['detalhe'] === 'ativo_para=0;motivo_cad=ja_no_estado');
    $ra1 = $rnE->definirAtivo($idAdmin, $idE, true, false);
    afirmar('empresa ativar: sem confirmacao; volta para o Talent; auditoria ativo_para=1', $ra1['ok'] === true && $empDao->buscarPorId($idE) !== null && ultima($audit('EMPRESA_ATIVO'))['detalhe'] === 'ativo_para=1');
    $pdo->prepare('UPDATE tb_totem SET ativo = 0 WHERE id_totem = :i')->execute(['i' => $t1]);
    $snapT2 = $snapTotens();
    $ri4 = $rnE->definirAtivo($idAdmin, $idE, false, false);
    afirmar('empresa inativar com totens so INATIVOS (N=0): direto, sem confirmacao, sem motivo_cad', $ri4['ok'] === true && ultima($audit('EMPRESA_ATIVO'))['detalhe'] === 'ativo_para=0' && $snapTotens() === $snapT2);
    $rnE->definirAtivo($idAdmin, $idE, true, false);

    // excluir
    $antesA = $nAudit();
    $rx = $rnE->excluir($idAdmin, $idE, true, '203.0.113.5');
    afirmar('empresa excluir COM totens (ativo ou inativo) vinculados: recusa empresa_com_totens, NADA muda, totens intactos', $rx['ok'] === false && $rx['codigo'] === 'empresa_com_totens' && $linhasEmp($idE) !== [] && $snapTotens() === $snapT2);
    afirmar('empresa excluir com totem: auditoria SEM_EFEITO motivo_cad=totens_vinculados', ultima($audit('EMPRESA_EXCLUIR'))['resultado'] === 'SEM_EFEITO' && ultima($audit('EMPRESA_EXCLUIR'))['detalhe'] === 'motivo_cad=totens_vinculados');
    // a regra e da APLICACAO, nao so da FK: remove a FK (so neste banco QA) e repete
    $fk = (string) gtEscalar($pdo, "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_totem' AND COLUMN_NAME = 'id_empresa' AND REFERENCED_TABLE_NAME = 'tb_empresa' LIMIT 1");
    afirmar('schema: tb_totem.id_empresa tem FK para tb_empresa', $fk !== '');
    $pdo->exec('ALTER TABLE tb_totem DROP FOREIGN KEY `' . $fk . '`');
    $rxSemFk = $rnE->excluir($idAdmin, $idE, true);
    afirmar('empresa excluir com totem: recusa mesmo SEM a FK no banco (regra da aplicacao)', $rxSemFk['ok'] === false && $rxSemFk['codigo'] === 'empresa_com_totens' && $linhasEmp($idE) !== []);
    $pdo->exec('ALTER TABLE tb_totem ADD CONSTRAINT `' . $fk . '` FOREIGN KEY (id_empresa) REFERENCES tb_empresa(id_empresa)');
    $eVazia = (int) $rnE->criar($idAdmin, 'Vazia SA', cnpjValido('565656560001'))['id'];
    $antesA = $nAudit();
    $rv1 = $rnE->excluir($idAdmin, $eVazia, false);
    afirmar('empresa excluir sem totem e SEM confirmar: confirmacao_necessaria, nada muda, sem auditoria', $rv1['ok'] === false && $rv1['codigo'] === 'confirmacao_necessaria' && $linhasEmp($eVazia) !== [] && $nAudit() === $antesA);
    $rv2 = $rnE->excluir($idAdmin, $eVazia, true, '203.0.113.5');
    afirmar('empresa excluir sem totem e com confirmacao: linha apagada; auditoria OK sem detalhe', $rv2['ok'] === true && $linhasEmp($eVazia) === [] && ultima($audit('EMPRESA_EXCLUIR'))['resultado'] === 'OK' && ultima($audit('EMPRESA_EXCLUIR'))['detalhe'] === null && (int) ultima($audit('EMPRESA_EXCLUIR'))['alvo_id'] === $eVazia);
    $rv3 = $rnE->excluir($idAdmin, $eVazia, true);
    afirmar('empresa excluir de novo: idempotente => empresa_nao_encontrada', $rv3['ok'] === false && $rv3['codigo'] === 'empresa_nao_encontrada');

    // O2: slug equivalente ("Maua I" x "MAUA-I" => MAUAI) e recusado em OUTRA empresa
    $msgSlug = 'Já existe uma empresa com um nome equivalente. Use um nome diferente.';
    $antesE = $nEmp();
    $antesAud = $nAudit();
    $nSemEfeitoDup = static fn (string $acao): int => (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = '" . $acao . "' AND resultado = 'SEM_EFEITO' AND detalhe = 'motivo_cad=duplicado'");
    $dupAntes = $nSemEfeitoDup('EMPRESA_CRIAR');
    foreach (['MAUA-I', 'maua i', 'Mauá I', 'M.A.U.A  I', ' Maua_I '] as $nomeSlug) {
        $xs = $rnE->criar($idAdmin, $nomeSlug, cnpjValido('787878780001'));
        afirmar('empresa criar com slug de outra ("' . $nomeSlug . '" => MAUAI): recusa no campo nome, mensagem fixa, sem revelar o outro nome, nada criado', $xs['ok'] === false && ($xs['erros']['nome'] ?? '') === $msgSlug && !str_contains($xs['erros']['nome'], 'Maua') && $nEmp() === $antesE);
    }
    afirmar('empresa criar slug duplicado: auditoria SEM_EFEITO motivo_cad=duplicado, sem alvo (5 recusas)', $nSemEfeitoDup('EMPRESA_CRIAR') === $dupAntes + 5 && $nAudit() === $antesAud + 5);
    $eIna = $emp('Zeta Inativa', cnpjValido('898989890001'), 0);
    $xi = $rnE->criar($idAdmin, 'ZETA-INATIVA', cnpjValido('787878780001'));
    afirmar('empresa criar: slug igual ao de empresa INATIVA tambem e recusado', $xi['ok'] === false && ($xi['erros']['nome'] ?? '') === $msgSlug && $nEmp() === $antesE + 1);
    $rnE->excluir($idAdmin, $eIna, true);
    $xc = $rnE->criar($idAdmin, 'MAUA-IV', cnpjValido('787878780001'));
    afirmar('empresa criar: nome com slug DIFERENTE (MAUAIV) continua aceito', $xc['ok'] === true);
    $idSlug = (int) $xc['id'];
    $edAntes = $nSemEfeitoDup('EMPRESA_EDITAR');
    $xr1 = $rnE->editar($idAdmin, $idSlug, 'Maua-I');
    afirmar('empresa editar para slug de OUTRA (Maua-I => MAUAI): recusa no campo nome, mensagem fixa, nome inalterado, auditoria SEM_EFEITO duplicado com o alvo', $xr1['ok'] === false && ($xr1['erros']['nome'] ?? '') === $msgSlug && $linhasEmp($idSlug)['nome'] === 'MAUA-IV' && $nSemEfeitoDup('EMPRESA_EDITAR') === $edAntes + 1 && (int) ultima($audit('EMPRESA_EDITAR'))['alvo_id'] === $idSlug);
    $xr2 = $rnE->editar($idAdmin, $idSlug, 'Maua IV');
    afirmar('empresa editar mantendo o PROPRIO slug (MAUA-IV => Maua IV, mesmo MAUAIV): permitido, e a mudanca de nome e gravada (nao e sem_mudanca)', $xr2['ok'] === true && !($xr2['sem_mudanca'] ?? false) && $linhasEmp($idSlug)['nome'] === 'Maua IV' && ultima($audit('EMPRESA_EDITAR'))['resultado'] === 'OK');
    $rnE->excluir($idAdmin, $idSlug, true);
    $rTot = (new TotemGestaoRn($pdo, BASE_CAD))->criar($idAdmin, $eVazia, 'Novo Totem');
    afirmar('criar totem numa empresa excluida: recusa de validacao (empresa), nada criado', $rTot['ok'] === false && isset($rTot['erros']['empresa']));
    $snapEmp = md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_empresa ORDER BY id_empresa')));
    foreach ([[$idUsu, 'usuario'], [999999, 'ator inexistente']] as [$ator, $rot]) {
        $res = [$rnE->criar($ator, 'Intrusa', cnpjValido('787878780001')), $rnE->editar($ator, $idE, 'Intrusa'), $rnE->definirAtivo($ator, $idE, false, true), $rnE->excluir($ator, $idE, true)];
        afirmar("Rn empresa: ator $rot => sem_permissao e nada muda", array_reduce($res, static fn ($c, $x) => $c && $x['ok'] === false && $x['codigo'] === 'sem_permissao', true) && md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_empresa ORDER BY id_empresa'))) === $snapEmp);
    }

    // =====================================================================
    // D. Auditoria: abre ANTES de gravar; falha ao abrir = nada e executado
    // =====================================================================
    $pdo->exec('CREATE TABLE qa_ordem (id INT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(20), evento VARCHAR(10), pendentes INT)');
    foreach (['tb_cliente' => 'CLIENTE', 'tb_empresa' => 'EMPRESA'] as $tab => $pref) {
        foreach (['UPDATE', 'DELETE'] as $ev) {
            $pdo->exec("CREATE TRIGGER qa_ord_{$tab}_{$ev} BEFORE {$ev} ON {$tab} FOR EACH ROW INSERT INTO qa_ordem (tabela, evento, pendentes) VALUES ('{$tab}', '{$ev}', (SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE' AND acao LIKE '{$pref}\\_%'))");
        }
    }
    $cO = (int) $rnC->criar($idAdmin, 'Ordem Auditoria Um', cnpjValido('909090900001'), '')['id'];
    $eO = (int) $rnE->criar($idAdmin, 'OrdAud', cnpjValido('919191910001'))['id'];
    $rnC->editar($idAdmin, $cO, 'Ordem Auditoria Dois');
    $rnC->definirAtivo($idAdmin, $cO, false, false);
    $rnC->excluir($idAdmin, $cO, true);
    $rnE->editar($idAdmin, $eO, 'OrdAud2');
    $rnE->definirAtivo($idAdmin, $eO, false, false);
    $rnE->excluir($idAdmin, $eO, true);
    $ordens = gtLinhas($pdo, 'SELECT tabela, evento, pendentes FROM qa_ordem ORDER BY id');
    afirmar('ordem: cada UPDATE/DELETE de cliente e empresa aconteceu com a auditoria PENDENTE JA aberta (editar, ativo, excluir x cliente/empresa = 6 gravacoes)', count($ordens) === 6 && array_reduce($ordens, static fn ($c, $o) => $c && (int) $o['pendentes'] === 1, true));
    $pdo->exec('DROP TRIGGER qa_ord_tb_cliente_UPDATE');
    $pdo->exec('DROP TRIGGER qa_ord_tb_cliente_DELETE');
    $pdo->exec('DROP TRIGGER qa_ord_tb_empresa_UPDATE');
    $pdo->exec('DROP TRIGGER qa_ord_tb_empresa_DELETE');
    // falha ao abrir: trigger que recusa o INSERT da auditoria de uma acao
    $cF = (int) $rnC->criar($idAdmin, 'Falha Auditoria', cnpjValido('929292920001'), '')['id'];
    $eF = (int) $rnE->criar($idAdmin, 'FalhaAud', cnpjValido('939393930001'))['id'];
    $snapCliF = md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_cliente ORDER BY id_cliente')));
    $snapEmpF = md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_empresa ORDER BY id_empresa')));
    $logFalha = gtNovoLogCgi();
    ini_set('log_errors', '1');
    ini_set('error_log', $logFalha);
    foreach (['CLIENTE_CRIAR', 'CLIENTE_EDITAR', 'CLIENTE_ATIVO', 'CLIENTE_EXCLUIR', 'EMPRESA_CRIAR', 'EMPRESA_EDITAR', 'EMPRESA_ATIVO', 'EMPRESA_EXCLUIR'] as $acaoBloq) {
        $pdo->exec("CREATE TRIGGER qa_bloq BEFORE INSERT ON tb_gestao_auditoria FOR EACH ROW IF NEW.acao = '{$acaoBloq}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa bloqueio'; END IF");
        $res = match ($acaoBloq) {
            'CLIENTE_CRIAR' => $rnC->criar($idAdmin, 'Nao Deve Existir', cnpjValido('949494940001'), ''),
            'CLIENTE_EDITAR' => $rnC->editar($idAdmin, $cF, 'Nome Que Nao Deve Valer'),
            'CLIENTE_ATIVO' => $rnC->definirAtivo($idAdmin, $cF, false, true),
            'CLIENTE_EXCLUIR' => $rnC->excluir($idAdmin, $cF, true),
            'EMPRESA_CRIAR' => $rnE->criar($idAdmin, 'NaoDeve', cnpjValido('959595950001')),
            'EMPRESA_EDITAR' => $rnE->editar($idAdmin, $eF, 'NaoValer'),
            'EMPRESA_ATIVO' => $rnE->definirAtivo($idAdmin, $eF, false, true),
            'EMPRESA_EXCLUIR' => $rnE->excluir($idAdmin, $eF, true),
        };
        $dados = md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_cliente ORDER BY id_cliente'))) === $snapCliF && md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_empresa ORDER BY id_empresa'))) === $snapEmpF;
        afirmar("falha ao abrir a auditoria ($acaoBloq): erro_interno e NADA foi executado (dados identicos, sem linha PENDENTE)", $res['ok'] === false && $res['codigo'] === 'erro_interno' && $dados && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
        $pdo->exec('DROP TRIGGER qa_bloq');
    }
    $txtLog = (string) @file_get_contents($logFalha);
    afirmar('falha tecnica: log fixo so com a CLASSE da excecao (nunca getMessage, nome, CNPJ ou SQL)', str_contains($txtLog, 'ClienteGestaoRn: excluir_falhou PDOException') && str_contains($txtLog, 'EmpresaGestaoRn: criar_falhou PDOException') && !str_contains($txtLog, 'qa bloqueio') && !str_contains($txtLog, 'Falha Auditoria') && !str_contains($txtLog, 'SQLSTATE'));
    ini_restore('error_log');
    @unlink($logFalha);
    afirmar('lock de clientes e liberado depois de cada operacao (nenhum lock residual na conexao do Rn)', (int) gtEscalar($pdo, "SELECT IS_USED_LOCK(CONCAT('totem_gestao_clientes_', MD5(DATABASE()))) IS NULL") === 1 && (int) gtEscalar($pdo, "SELECT IS_USED_LOCK(CONCAT('totem_gestao_totens_', MD5(DATABASE()))) IS NULL") === 1);

    // =====================================================================
    // E. Corridas (varios processos)
    // =====================================================================
    foreach ([1, 2, 3] as $rodada) {
        $cc = (int) $rnC->criar($idAdmin, 'Corrida Excluir ' . $rodada, cnpjValido('96969696' . sprintf('%04d', $rodada)), '')['id'];
        $inicio = sprintf('%.4F', microtime(true) + 3.0);
        $hs = [lancarW(['--w-cli-excluir', $banco, (string) $idAdmin, (string) $cc, $inicio]), lancarW(['--w-cli-excluir', $banco, (string) $idAdmin2, (string) $cc, $inicio])];
        $saidas = array_map('colherW', $hs);
        $oks = count(array_filter($saidas, static fn ($s) => ($s['ok'] ?? false) === true));
        $nao = count(array_filter($saidas, static fn ($s) => ($s['codigo'] ?? '') === 'cliente_nao_encontrado'));
        afirmar("corrida (rodada $rodada): 2 admins excluindo o MESMO cliente => 1 exclui e 1 recebe cliente_nao_encontrado (sem erro)", $oks === 1 && $nao === 1 && $linhasCli($cc) === [] && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'CLIENTE_EXCLUIR' AND resultado = 'OK' AND alvo_id = :i", ['i' => $cc]) === 1);
    }
    foreach ([1, 2] as $rodada) {
        $ce = (int) $rnC->criar($idAdmin, 'Corrida Editar ' . $rodada, cnpjValido('97979797' . sprintf('%04d', $rodada)), '')['id'];
        $inicio = sprintf('%.4F', microtime(true) + 3.0);
        $hs = [lancarW(['--w-cli-editar', $banco, (string) $idAdmin, (string) $ce, $inicio, 'Renomeado Na Corrida ' . $rodada]), lancarW(['--w-cli-excluir', $banco, (string) $idAdmin2, (string) $ce, $inicio])];
        $saidas = array_map('colherW', $hs);
        $edicaoOk = ($saidas[0]['ok'] ?? false) === true;
        $edicaoNao = ($saidas[0]['codigo'] ?? '') === 'cliente_nao_encontrado';
        afirmar("corrida (rodada $rodada): editar x excluir => cliente apagado; a edicao ou aplicou antes ou recebeu nao_encontrado; nenhum erro_interno; nenhuma auditoria PENDENTE", ($saidas[1]['ok'] ?? false) === true && ($edicaoOk || $edicaoNao) && $linhasCli($ce) === [] && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    }
    $cnpjCorrida = cnpjValido('981111110001');
    $inicio = sprintf('%.4F', microtime(true) + 3.0);
    $saidas = array_map('colherW', [lancarW(['--w-cli-criar', $banco, (string) $idAdmin, $cnpjCorrida, $inicio, 'Corrida Criar Alfa']), lancarW(['--w-cli-criar', $banco, (string) $idAdmin2, $cnpjCorrida, $inicio, 'Corrida Criar Beta'])]);
    afirmar('corrida: 2 processos criando o MESMO CNPJ => exatamente 1 cria e o outro recebe erro no campo cnpj (1062 tratado, sem erro_interno)', count(array_filter($saidas, static fn ($s) => ($s['ok'] ?? false) === true)) === 1 && count(array_filter($saidas, static fn ($s) => ($s['ok'] ?? true) === false && ($s['erros'] ?? []) === ['cnpj'])) === 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente WHERE cnpj = :c', ['c' => $cnpjCorrida]) === 1);
    $inicio = sprintf('%.4F', microtime(true) + 3.0);
    $saidas = array_map('colherW', [lancarW(['--w-cli-criar', $banco, (string) $idAdmin, cnpjValido('982222220001'), $inicio, 'Corrida Razao Igual']), lancarW(['--w-cli-criar', $banco, (string) $idAdmin2, cnpjValido('983333330001'), $inicio, 'corrida razao igual ltda'])]);
    afirmar('corrida: 2 processos criando razoes normalizadas IGUAIS (CNPJs diferentes) => so 1 cria (o lock evita duas razoes iguais)', count(array_filter($saidas, static fn ($s) => ($s['ok'] ?? false) === true)) === 1 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_cliente WHERE razao_social_normalizada = 'CORRIDA RAZAO IGUAL'") === 1);
    // criar totem x excluir empresa
    $resultados = [];
    foreach ([1, 2, 3, 4, 5] as $rodada) {
        $eC = (int) $rnE->criar($idAdmin, 'Corr' . $rodada, cnpjValido('99999999' . sprintf('%04d', $rodada)))['id'];
        $inicio = sprintf('%.4F', microtime(true) + 3.0);
        $hs = [lancarW(['--w-totem-criar', $banco, (string) $idAdmin, (string) $eC, $inicio, 'Totem Corrida']), lancarW(['--w-emp-excluir', $banco, (string) $idAdmin2, (string) $eC, $inicio])];
        $saidas = array_map('colherW', $hs);
        $totemCriado = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_totem WHERE id_empresa = :e', ['e' => $eC]) === 1;
        $empresaExiste = $linhasEmp($eC) !== [];
        $cenarioA = $totemCriado && $empresaExiste && ($saidas[0]['ok'] ?? false) === true && ($saidas[1]['codigo'] ?? '') === 'empresa_com_totens';
        $cenarioB = !$totemCriado && !$empresaExiste && ($saidas[1]['ok'] ?? false) === true && ($saidas[0]['ok'] ?? true) === false && ($saidas[0]['erros'] ?? []) === ['empresa'];
        $resultados[] = $cenarioA || $cenarioB;
    }
    afirmar('corrida: criar totem x excluir empresa (5 rodadas) => SEMPRE consistente: (totem criado e empresa mantida) OU (empresa excluida e totem recusado); nunca empresa excluida com totem, nunca erro_interno', !in_array(false, $resultados, true));
    $eI = (int) $rnE->criar($idAdmin, 'CorrInat', cnpjValido('990000000002'))['id'];
    $totemDireto('TOTEM-CORR-INAT-AAAAAAAAAAAAAAAA', 'TOTEM-CORR', $eI);
    $inicio = sprintf('%.4F', microtime(true) + 3.0);
    $saidas = array_map('colherW', [lancarW(['--w-emp-inativar', $banco, (string) $idAdmin, (string) $eI, $inicio]), lancarW(['--w-emp-inativar', $banco, (string) $idAdmin2, (string) $eI, $inicio])]);
    afirmar('corrida: 2 admins inativando a MESMA empresa (com confirmacao) => ambos ok, 1 com efeito (OK) e 1 sem_mudanca (SEM_EFEITO)', ($saidas[0]['ok'] ?? false) === true && ($saidas[1]['ok'] ?? false) === true && (int) $linhasEmp($eI)['ativo'] === 0 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'EMPRESA_ATIVO' AND alvo_id = :i AND resultado = 'OK'", ['i' => $eI]) === 1 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'EMPRESA_ATIVO' AND alvo_id = :i AND resultado = 'SEM_EFEITO'", ['i' => $eI]) === 1);

    // auditoria global: sem PII, so catalogo e allowlist
    $detalhesOk = true;
    foreach (gtLinhas($pdo, 'SELECT acao, detalhe, alvo_tipo, resultado FROM tb_gestao_auditoria WHERE acao LIKE \'CLIENTE\\_%\' OR acao LIKE \'EMPRESA\\_%\'') as $l) {
        $detalhesOk = $detalhesOk && in_array($l['alvo_tipo'], ['cliente', 'empresa'], true) && ($l['detalhe'] === null || preg_match('/\A(ativo_para=[01])?(;?motivo_cad=(andamento_confirmado|totens_ativos_confirmado|totens_vinculados|ja_no_estado|duplicado|nao_encontrado|confirmado_ambiguidade_ocr))?\z/D', (string) $l['detalhe']) === 1);
    }
    afirmar('auditoria dos cadastros: detalhe SO com ativo_para/motivo_cad do conjunto fechado (nada de nome, CNPJ, razao ou ids), alvo cliente|empresa', $detalhesOk);
    $semPii = (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE detalhe REGEXP '[0-9]{8}|Acme|Zeta|Maua|Delta|Gama|Beta|LTDA'") === 0;
    afirmar('auditoria: nenhum detalhe contem digitos longos (CNPJ), nomes de cliente/empresa nem razao', $semPii);
    afirmar('auditoria: nenhuma linha PENDENTE ficou aberta e todas as acoes pertencem ao catalogo fechado', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao NOT IN ('" . implode("','", AuditoriaDao::ACOES) . "')") === 0);
    afirmar('catalogo: 8 acoes novas, alvos cliente/empresa e motivo_cad fechado; chave/valor fora da allowlist recusados', !array_diff(['CLIENTE_CRIAR', 'CLIENTE_EDITAR', 'CLIENTE_ATIVO', 'CLIENTE_EXCLUIR', 'EMPRESA_CRIAR', 'EMPRESA_EDITAR', 'EMPRESA_ATIVO', 'EMPRESA_EXCLUIR'], AuditoriaDao::ACOES) && in_array('cliente', AuditoriaDao::ALVO_TIPOS, true) && in_array('empresa', AuditoriaDao::ALVO_TIPOS, true) && AuditoriaDao::DETALHE_CAMPOS['motivo_cad'] === ['andamento_confirmado', 'totens_ativos_confirmado', 'totens_vinculados', 'ja_no_estado', 'duplicado', 'nao_encontrado', 'confirmado_ambiguidade_ocr'] && (static function (): bool {
        foreach ([['nome' => 'Acme'], ['cnpj' => '11222333000181'], ['motivo_cad' => 'Acme Ltda'], ['razao' => 'X']] as $d) {
            try {
                AuditoriaDao::montarDetalhe($d);

                return false;
            } catch (InvalidArgumentException $e) {
            }
        }

        return true;
    })());

    // =====================================================================
    // F. Paginas REAIS por php-cgi
    // =====================================================================
    $H = ['logErro' => $log];
    $req = static fn (array $o): array => gtChamar($o + $H);
    $ck = static fn (?array $l): array => $l !== null && $l['sid'] !== null ? ['cookies' => ['gestao_sid' => $l['sid']]] : [];
    $get = static fn (string $arq, ?array $l, array $q = [], array $extra = []): array => $req(['arquivo' => $arq, 'query' => $q] + $ck($l) + $extra);
    $post = static fn (string $arq, ?array $l, array $form, array $extra = [], bool $comCsrf = true): array => $req(['arquivo' => $arq, 'metodo' => 'POST', 'form' => $form + ($comCsrf && $l !== null && $l['csrf'] !== null ? ['csrf_token' => $l['csrf']] : [])] + $ck($l) + $extra);
    $lAdm = gtLogin('ana.admin', GT_SENHA_BOA, ['ip' => '192.0.2.21']);
    $lAdm2 = gtLogin('beto.admin', GT_SENHA_BOA, ['ip' => '192.0.2.22']);
    $lUsu = gtLogin('carla.usuario', GT_SENHA_BOA, ['ip' => '192.0.2.23']);
    afirmar('sessoes de teste abertas (admin, admin2, usuario) com CSRF', $lAdm['sid'] !== null && $lAdm['csrf'] !== null && $lAdm2['sid'] !== null && $lUsu['sid'] !== null && $lUsu['csrf'] !== null);

    // dados para as paginas: limpa os clientes do teste e semeia um conjunto controlado
    $pdo->exec('DELETE FROM tb_cliente');
    $cliSql = $pdo->prepare('INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:n, :r, :c, :a)');
    for ($i = 1; $i <= 60; $i++) {
        $nomeP = sprintf('Pag %03d Comercio', $i);
        $cliSql->execute(['n' => $nomeP, 'r' => RazaoSocialMatcher::normalizar($nomeP), 'c' => cnpjValido(sprintf('%012d', 500000 + $i)), 'a' => $i % 5 === 0 ? 0 : 1]);
    }
    $cXss = cnpjValido('600000000001');
    $idXss = (int) $rnC->criar($idAdmin, '<script>alert(1)</script> "A\'&', $cXss, '')['id'];
    $idPct = (int) $rnC->criar($idAdmin, '100%_Real Ltda', cnpjValido('600000000002'), '')['id'];
    $idMil = (int) $rnC->criar($idAdmin, '1000 Mil Comercio', cnpjValido('600000000003'), '')['id'];
    $idBar = (int) $rnC->criar($idAdmin, 'A|B Barra', cnpjValido('600000000004'), '')['id'];
    $totalCli = $nCli();
    $codTotemFix = (string) gtEscalar($pdo, 'SELECT codigo FROM tb_totem WHERE id_totem = :i', ['i' => $tFix]);
    $tokTotemFix = (string) gtEscalar($pdo, 'SELECT token_api FROM tb_totem WHERE id_totem = :i', ['i' => $tFix]);

    // --- RBAC / metodos
    $rotasGet = [['clientes.php', []], ['cliente-form.php', []], ['cliente-form.php', ['id' => (string) $idXss]], ['empresas.php', []], ['empresa-form.php', []], ['empresa-form.php', ['id' => (string) $idE]]];
    foreach ($rotasGet as [$arq, $q]) {
        $rot = $arq . ($q !== [] ? '?id' : '');
        $ra = $get($arq, null, $q);
        afirmar("RBAC $rot: anonimo => redireciona ao login, sem conteudo", in_array($ra['status'], [302, 401], true) && !str_contains($ra['corpo'], 'Comercio') && !str_contains($ra['corpo'], 'Maua'));
        $ru = $get($arq, $lUsu, $q);
        afirmar("RBAC $rot: perfil usuario => 403 sem conteudo", $ru['status'] === 403 && !str_contains($ru['corpo'], 'Comercio') && !str_contains($ru['corpo'], 'Maua'));
        $rad = $get($arq, $lAdm, $q);
        afirmar("RBAC $rot: admin => 200 com no-store, X-Frame-Options e CSP", $rad['status'] === 200 && gtCabecalho($rad, 'cache-control') === 'no-store, private' && gtCabecalho($rad, 'x-frame-options') === 'DENY' && gtCabecalho($rad, 'content-security-policy') !== null);
    }
    foreach (['cliente-acao.php', 'empresa-acao.php'] as $arq) {
        afirmar("metodo: GET em $arq => 405 (so POST)", $get($arq, $lAdm)['status'] === 405 && $get($arq, null)['status'] === 405);
    }
    foreach (['clientes.php', 'empresas.php'] as $arq) {
        afirmar("metodo: POST em $arq => 405 (so GET)", $post($arq, $lAdm, ['x' => '1'])['status'] === 405);
    }
    $snapC = static fn (): string => md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_cliente ORDER BY id_cliente')));
    $snapE = static fn (): string => md5(json_encode(gtLinhas($pdo, 'SELECT * FROM tb_empresa ORDER BY id_empresa')));
    $antesSnap = $snapC() . $snapE();
    $antesAud = $nAudit();
    $acoesPost = [
        ['cliente-acao.php', ['acao' => 'excluir', 'id_cliente' => (string) $idPct, 'confirmar' => '1']],
        ['cliente-acao.php', ['acao' => 'inativar', 'id_cliente' => (string) $idPct]],
        ['cliente-form.php', ['nome' => 'Invasor Comercio', 'cnpj' => cnpjValido('700000000001')]],
        ['cliente-form.php', ['id_cliente' => (string) $idPct, 'nome' => 'Invasor Editado']],
        ['empresa-acao.php', ['acao' => 'excluir', 'id_empresa' => (string) $idE, 'confirmar' => '1']],
        ['empresa-acao.php', ['acao' => 'inativar', 'id_empresa' => (string) $idE, 'confirmar' => '1']],
        ['empresa-form.php', ['nome' => 'Invasora', 'cnpj' => cnpjValido('700000000002')]],
        ['empresa-form.php', ['id_empresa' => (string) $idE, 'nome' => 'Invasora Editada']],
    ];
    foreach ($acoesPost as [$arq, $form]) {
        $rot = $arq . ' ' . ($form['acao'] ?? (isset($form['id_cliente']) || isset($form['id_empresa']) ? 'editar' : 'criar'));
        afirmar("RBAC POST $rot: anonimo bloqueado", in_array($post($arq, null, $form, [], false)['status'], [302, 401, 403], true));
        afirmar("RBAC POST $rot: perfil usuario => 403", $post($arq, $lUsu, $form)['status'] === 403);
        $p0 = $post($arq, $lAdm, $form, [], false);
        afirmar("CSRF POST $rot: sem token => 403 'Pagina expirada'", $p0['status'] === 403 && str_contains($p0['corpo'], 'Página expirada'));
        afirmar("CSRF POST $rot: token errado => 403", $post($arq, $lAdm, $form + ['csrf_token' => str_repeat('a', 64)])['status'] === 403);
        afirmar("CSRF POST $rot: token de OUTRA sessao => 403", $post($arq, $lAdm, $form + ['csrf_token' => (string) $lAdm2['csrf']])['status'] === 403);
        afirmar("CSRF POST $rot: Origin de outro site => 403", $post($arq, $lAdm, $form, ['origin' => 'https://evil.example.test'])['status'] === 403);
        afirmar("CSRF POST $rot: Sec-Fetch-Site cross-site sem Origin => 403", $post($arq, $lAdm, $form, ['origin' => null, 'cabecalhos' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']])['status'] === 403);
        afirmar("metodo PUT em $arq => 405", $req(['arquivo' => $arq, 'metodo' => 'PUT', 'form' => $form] + $ck($lAdm))['status'] === 405);
    }
    afirmar('RBAC/CSRF: NENHUMA dessas tentativas alterou clientes ou empresas e nenhuma gerou auditoria', $snapC() . $snapE() === $antesSnap && $nAudit() === $antesAud);

    // --- ids hostis
    $hostis = ['999999', '0', '-1', '1 OR 1=1', "1' OR '1'='1", '1e3', ' 1', '01', '1.5', '99999999999', 'abc', '', '1;DROP TABLE tb_cliente'];
    $okHostis = true;
    foreach ($hostis as $idH) {
        foreach (['inativar', 'ativar', 'excluir'] as $acao) {
            $ph = $post('cliente-acao.php', $lAdm, ['acao' => $acao, 'id_cliente' => $idH, 'confirmar' => '1']);
            $okHostis = $okHostis && $ph['status'] === 302 && preg_match('#\A/gestao/clientes\.php\?msg=cliente_nao_encontrado\z#', (string) loc($ph)) === 1 && !str_contains($ph['corpo'], 'SQLSTATE');
            $pe = $post('empresa-acao.php', $lAdm, ['acao' => $acao, 'id_empresa' => $idH, 'confirmar' => '1']);
            $okHostis = $okHostis && $pe['status'] === 302 && preg_match('#\A/gestao/empresas\.php\?msg=empresa_nao_encontrada\z#', (string) loc($pe)) === 1;
        }
        foreach ([['cliente-form.php', 'cliente_nao_encontrado', '/gestao/clientes.php'], ['empresa-form.php', 'empresa_nao_encontrada', '/gestao/empresas.php']] as [$arq, $msg, $dest]) {
            if ($idH === '') {
                continue;
            }
            $gh = $get($arq, $lAdm, ['id' => $idH]);
            $okHostis = $okHostis && $gh['status'] === 302 && loc($gh) === $dest . '?msg=' . $msg;
        }
    }
    afirmar('IDOR: id hostil (inexistente, SQL, negativo, cientifico, vazio, com espaco) em todas as acoes e nos formularios => nao_encontrado, sem erro SQL', $okHostis);
    $pArr = $req(['arquivo' => 'cliente-acao.php', 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $lAdm['csrf'] . '&acao=excluir&confirmar=1&id_cliente[]=' . $idPct] + $ck($lAdm));
    afirmar('entrada: id_cliente como array => cliente_nao_encontrado, nada muda', $pArr['status'] === 302 && loc($pArr) === '/gestao/clientes.php?msg=cliente_nao_encontrado' && $linhasCli($idPct) !== []);
    $pAc = $post('cliente-acao.php', $lAdm, ['acao' => 'apagar-tudo', 'id_cliente' => (string) $idPct]);
    $pAc2 = $post('empresa-acao.php', $lAdm, ['acao' => 'apagar-tudo', 'id_empresa' => (string) $idE]);
    afirmar('entrada: acao desconhecida => 400 (mostra a lista com erro), nada muda', $pAc['status'] === 400 && $pAc2['status'] === 400 && str_contains($pAc['corpo'], 'id="tabela-clientes"') && $linhasCli($idPct) !== []);
    $pArr2 = $req(['arquivo' => 'cliente-form.php', 'metodo' => 'POST', 'corpo' => 'csrf_token=' . $lAdm['csrf'] . '&nome[]=x&cnpj[]=y'] + $ck($lAdm));
    afirmar('entrada: nome/cnpj como array => 422 de validacao (campos vazios), sem erro 500', $pArr2['status'] === 422 && str_contains($pArr2['corpo'], 'role="alert"'));
    afirmar('IDOR: nada mudou apos os pedidos hostis', $snapC() . $snapE() === $antesSnap);

    // --- lista de clientes: contrato de ids, ordenacao, paginacao
    $pL = $get('clientes.php', $lAdm);
    $corpoL = $pL['corpo'];
    afirmar('clientes: ids de contrato (aviso, filtros role=search, situacao, busca, aplicar, limpar, novo, contador, tabela com caption e th scope)', str_contains($corpoL, 'id="clientes-aviso"') && str_contains($corpoL, 'id="clientes-filtros"') && str_contains($corpoL, 'role="search"') && str_contains($corpoL, 'id="filtro-situacao"') && str_contains($corpoL, 'id="filtro-busca"') && str_contains($corpoL, 'id="btn-aplicar-filtros"') && str_contains($corpoL, 'id="btn-limpar-filtros"') && str_contains($corpoL, 'id="btn-novo-cliente"') && str_contains($corpoL, 'id="clientes-contador"') && str_contains($corpoL, 'id="tabela-clientes"') && str_contains($corpoL, '<caption') && substr_count($corpoL, '<th scope="col">') === 5);
    afirmar('clientes: filtros por GET (method=get) e situacao com as 3 opcoes (todos padrao)', str_contains($corpoL, 'method="get" action="/gestao/clientes.php"') && str_contains($corpoL, '<option value="todos" selected>') && str_contains($corpoL, 'value="ativos"') && str_contains($corpoL, 'value="inativos"'));
    preg_match_all('/<tr class="gestao-tabela__linha[^"]*" data-id-cliente="(\d+)" data-ativo="([01])"/', $corpoL, $mLin);
    afirmar('clientes: 25 linhas na pagina 1, cada uma com data-id-cliente e data-ativo 0|1', count($mLin[1]) === 25);
    $idsPag1 = array_map('intval', $mLin[1]);
    $nomesOrd = static function (string $html): array {
        preg_match_all('/<td class="col-nome">(.*?)<\/td>/s', $html, $m);

        return array_map(static fn ($x) => html_entity_decode($x, ENT_QUOTES, 'UTF-8'), $m[1]);
    };
    $n1 = $nomesOrd($corpoL);
    $ordenado = $n1;
    usort($ordenado, static fn ($a, $b) => strcasecmp($a, $b));
    afirmar('clientes: ordenacao fixa por nome ASC', $n1 === $ordenado && $n1[0] !== '');
    afirmar('clientes: colunas .col-nome .col-cnpj .col-situacao .col-criado .col-acoes por linha', substr_count($corpoL, 'class="col-nome"') === 25 && substr_count($corpoL, 'class="col-cnpj"') === 25 && substr_count($corpoL, 'class="col-situacao"') === 25 && substr_count($corpoL, 'class="col-criado"') === 25 && substr_count($corpoL, 'class="col-acoes"') === 25);
    afirmar('clientes: CNPJ exibido formatado 00.000.000/0000-00 e nunca so digitos na celula', preg_match('#<td class="col-cnpj">\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}</td>#', $corpoL) === 1 && preg_match('#<td class="col-cnpj">\d{14}</td>#', $corpoL) !== 1);
    afirmar('clientes: Situacao com texto Ativo/Inativo (e icone) e contador "N clientes (mostrando 1 a 25)"', str_contains($corpoL, '<span>Ativo</span>') && str_contains($corpoL, '<span>Inativo</span>') === (preg_match('/data-ativo="0"/', $corpoL) === 1) && str_contains($corpoL, $totalCli . ' clientes (mostrando 1 a 25)'));
    $pag = ['paginacao ids' => str_contains($corpoL, 'id="clientes-paginacao"') && str_contains($corpoL, 'id="pag-anterior"') && str_contains($corpoL, 'id="pag-posicao"') && str_contains($corpoL, 'id="pag-proxima"')];
    afirmar('clientes: paginacao com ids; pagina 1 sem Anterior ativo, com Proxima; posicao "Pagina 1 de ' . (int) ceil($totalCli / 25) . '"', $pag['paginacao ids'] && str_contains($corpoL, 'id="pag-anterior" aria-disabled="true"') && str_contains($corpoL, 'rel="next"') && str_contains($corpoL, 'Página 1 de ' . (int) ceil($totalCli / 25)));
    $p2 = $get('clientes.php', $lAdm, ['pagina' => '2']);
    $p3 = $get('clientes.php', $lAdm, ['pagina' => '3']);
    preg_match_all('/data-id-cliente="(\d+)"/', $p2['corpo'], $m2);
    preg_match_all('/data-id-cliente="(\d+)"/', $p3['corpo'], $m3);
    afirmar('paginacao: pagina 2 traz 25 linhas distintas da 1; pagina 3 traz o resto (' . ($totalCli - 50) . ')', count($m2[1]) === 25 && !array_intersect($idsPag1, array_map('intval', $m2[1])) && count($m3[1]) === $totalCli - 50 && str_contains($p3['corpo'], 'id="pag-proxima" aria-disabled="true"'));
    foreach (['9999', '99999999999', '0', '-3', 'abc', '1e3', "2'"] as $pgH) {
        $px = $get('clientes.php', $lAdm, ['pagina' => $pgH]);
        $okTeto = $px['status'] === 200 && !str_contains($px['corpo'], 'SQLSTATE') && preg_match_all('/data-id-cliente="/', $px['corpo']) >= 1;
        afirmar("paginacao hostil pagina=$pgH: 200, limitada ao total de paginas ou volta a 1 (sem pagina vazia nem erro)", $okTeto);
    }
    afirmar('paginacao: pagina alem do fim e limitada a ultima (pagina=9999 mostra a ultima)', str_contains($get('clientes.php', $lAdm, ['pagina' => '9999'])['corpo'], 'Página 3 de 3'));
    $pQ = $get('clientes.php', $lAdm, ['pagina' => ['2']]);
    afirmar('filtro: pagina como array e ignorada (pagina 1)', $pQ['status'] === 200 && str_contains($pQ['corpo'], 'Página 1 de'));

    // --- filtros
    $contar = static fn (string $html): int => preg_match_all('/data-id-cliente="/', $html);
    $pI = $get('clientes.php', $lAdm, ['situacao' => 'inativos']);
    preg_match_all('/data-ativo="([01])"/', $pI['corpo'], $mI);
    $totalInativos = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente WHERE ativo = 0');
    afirmar('filtro situacao=inativos: so linhas inativas e contador correto', $mI[1] !== [] && !in_array('1', $mI[1], true) && str_contains($pI['corpo'], $totalInativos . ' clientes') && str_contains($pI['corpo'], '<option value="inativos" selected>'));
    $pA = $get('clientes.php', $lAdm, ['situacao' => 'ativos']);
    preg_match_all('/data-ativo="([01])"/', $pA['corpo'], $mA);
    afirmar('filtro situacao=ativos: so linhas ativas', $mA[1] !== [] && !in_array('0', $mA[1], true));
    foreach (['hostil', 'TODOS', '', "ativos'", ' ativos'] as $sitH) {
        $ps = $get('clientes.php', $lAdm, ['situacao' => $sitH]);
        afirmar('filtro situacao hostil ' . json_encode($sitH) . ' => cai em todos (padrao), sem erro', $ps['status'] === 200 && str_contains($ps['corpo'], '<option value="todos" selected>') && str_contains($ps['corpo'], $totalCli . ' clientes'));
    }
    $pSa = $get('clientes.php', $lAdm, ['situacao' => ['ativos']]);
    afirmar('filtro situacao como array => padrao todos', $pSa['status'] === 200 && str_contains($pSa['corpo'], $totalCli . ' clientes'));
    $pPre = $get('clientes.php', $lAdm, ['q' => 'Pag 01']);
    afirmar('busca por PREFIXO do nome (Pag 01 => Pag 010..019 = 10 linhas)', $contar($pPre['corpo']) === 10 && str_contains($pPre['corpo'], '10 clientes'));
    $pMeio = $get('clientes.php', $lAdm, ['q' => 'Comercio']);
    afirmar('busca e por PREFIXO (nao "contem"): "Comercio" nao casa "Pag 001 Comercio"', $contar($pMeio['corpo']) === 0 && str_contains($pMeio['corpo'], 'id="clientes-vazio"') && str_contains($pMeio['corpo'], 'Nenhum cliente encontrado com estes filtros.'));
    $pCnpj = $get('clientes.php', $lAdm, ['q' => '500007']);
    afirmar('busca por parte do CNPJ (so digitos, >=3): "500007" acha o cliente cujo CNPJ contem esses digitos', $contar($pCnpj['corpo']) >= 1 && str_contains($pCnpj['corpo'], 'Pag 007 Comercio'));
    $pCnpjM = $get('clientes.php', $lAdm, ['q' => '00.000.000']);
    afirmar('busca com mascara: os digitos sao extraidos (sem erro)', $pCnpjM['status'] === 200);
    foreach ([['%%%', 'tres percentuais'], ['___', 'tres underscores'], ['|||', 'tres barras'], ["' OR 1=1 -- ", 'SQL'], ['\\\\\\', 'barras invertidas']] as [$qH, $rot]) {
        $px = $get('clientes.php', $lAdm, ['q' => $qH]);
        afirmar("busca adversarial ($rot): 200, sem erro SQL e sem trazer a lista toda", $px['status'] === 200 && !str_contains($px['corpo'], 'SQLSTATE') && $contar($px['corpo']) < min(25, $totalCli));
    }
    foreach (['%', 'a%', '_'] as $qCoringa) {
        $px = $get('clientes.php', $lAdm, ['q' => $qCoringa]);
        afirmar('busca curta com coringa ' . json_encode($qCoringa) . ' (<3 caracteres): ignorada com aviso, nunca vira "tudo casa" silencioso', $px['status'] === 200 && str_contains($px['corpo'], 'id="erro-filtro-busca"'));
    }
    $pPct = $get('clientes.php', $lAdm, ['q' => '100%']);
    afirmar('busca: "100%" tem o % como LITERAL (acha "100%_Real Ltda" e nao "1000 Mil Comercio")', str_contains($pPct['corpo'], '100%_Real Ltda') && !str_contains($pPct['corpo'], '1000 Mil Comercio') && $contar($pPct['corpo']) === 1);
    $pUnd = $get('clientes.php', $lAdm, ['q' => '100%_']);
    afirmar('busca: "100%_" (% e _ literais) acha so o literal', $contar($pUnd['corpo']) === 1 && str_contains($pUnd['corpo'], '100%_Real Ltda'));
    $pMil = $get('clientes.php', $lAdm, ['q' => '1000']);
    afirmar('busca: "1000" acha "1000 Mil Comercio" (prefixo) e nao "100%_Real"', str_contains($pMil['corpo'], '1000 Mil Comercio') && !str_contains($pMil['corpo'], '100%_Real'));
    $pBar = $get('clientes.php', $lAdm, ['q' => 'A|B']);
    afirmar('busca: "A|B" (barra do ESCAPE) e literal: acha "A|B Barra"', $contar($pBar['corpo']) === 1 && str_contains($pBar['corpo'], 'A|B Barra'));
    foreach (['ab', 'a', '  b  '] as $qc) {
        $px = $get('clientes.php', $lAdm, ['q' => $qc]);
        afirmar('busca curta ' . json_encode($qc) . ' (<3 caracteres): ignorada com aviso (erro-filtro-busca) e lista completa', $px['status'] === 200 && str_contains($px['corpo'], 'id="erro-filtro-busca"') && str_contains($px['corpo'], 'Digite pelo menos 3 caracteres') && str_contains($px['corpo'], $totalCli . ' clientes'));
    }
    foreach ([["Pag\x00 01", 'NUL'], ["Pag\n01", 'quebra'], ["\xFF\xFEPag", 'UTF-8 invalido'], [str_repeat('P', 200), 'gigante']] as [$qb, $rot]) {
        $px = $get('clientes.php', $lAdm, ['q' => $qb]);
        afirmar("busca com $rot: 200, sem erro, sem eco do valor hostil e lista completa", $px['status'] === 200 && !str_contains($px['corpo'], 'SQLSTATE') && !str_contains($px['corpo'], "\0") && str_contains($px['corpo'], 'id="tabela-clientes"'));
    }
    $pQa = $get('clientes.php', $lAdm, ['q' => ['Pag']]);
    afirmar('busca como array: ignorada, 200', $pQa['status'] === 200 && str_contains($pQa['corpo'], $totalCli . ' clientes'));
    $pCombo = $get('clientes.php', $lAdm, ['q' => 'Pag 0', 'situacao' => 'inativos', 'pagina' => '1']);
    afirmar('filtros combinados (situacao + q): so inativos que comecam com "Pag 0"', $contar($pCombo['corpo']) >= 1 && !str_contains($pCombo['corpo'], 'data-ativo="1"') && str_contains($pCombo['corpo'], 'value="Pag 0"'));
    $pLink = $get('clientes.php', $lAdm, ['q' => 'Pag', 'situacao' => 'ativos']);
    preg_match('/id="pag-proxima" href="([^"]+)"/', $pLink['corpo'], $mLink);
    afirmar('paginacao preserva os filtros nos links (situacao e q) e escapa o &', isset($mLink[1]) && str_contains($mLink[1], 'situacao=ativos') && str_contains($mLink[1], 'q=Pag') && str_contains($mLink[1], 'pagina=2') && str_contains($mLink[1], '&amp;'));
    $pLimpar = $get('clientes.php', $lAdm, ['q' => 'Zzzz inexistente']);
    afirmar('lista vazia por filtro: #clientes-vazio, sem tabela e sem paginacao', str_contains($pLimpar['corpo'], 'id="clientes-vazio"') && !str_contains($pLimpar['corpo'], 'id="tabela-clientes"') && !str_contains($pLimpar['corpo'], 'id="clientes-paginacao"'));

    // --- botoes por linha (contrato)
    $linhaCli = static function (string $html, int $id): string {
        return preg_match('/<tr class="gestao-tabela__linha[^"]*" data-id-cliente="' . $id . '".*?<\/tr>/s', $html, $m) === 1 ? $m[0] : '';
    };
    $pAll = $get('clientes.php', $lAdm, ['q' => 'Pag 001']);
    $idP1 = (int) gtEscalar($pdo, "SELECT id_cliente FROM tb_cliente WHERE nome = 'Pag 001 Comercio'");
    $idP5 = (int) gtEscalar($pdo, "SELECT id_cliente FROM tb_cliente WHERE nome = 'Pag 005 Comercio'");
    $l1 = $linhaCli($pAll['corpo'], $idP1);
    afirmar('linha de cliente ATIVO: Editar (link), Inativar (form com csrf_token, acao e id_cliente) e Excluir por ULTIMO; todos com aria-label descritivo', str_contains($l1, 'id="btn-cliente-editar-' . $idP1 . '"') && str_contains($l1, 'id="form-cliente-inativar-' . $idP1 . '"') && str_contains($l1, 'id="btn-cliente-inativar-' . $idP1 . '"') && str_contains($l1, 'id="form-cliente-excluir-' . $idP1 . '"') && str_contains($l1, 'id="btn-cliente-excluir-' . $idP1 . '"') && !str_contains($l1, 'btn-cliente-ativar-') && strpos($l1, 'btn-cliente-excluir-') > strpos($l1, 'btn-cliente-inativar-') && strpos($l1, 'btn-cliente-inativar-') > strpos($l1, 'btn-cliente-editar-') && str_contains($l1, 'aria-label="Editar o cliente Pag 001 Comercio"') && str_contains($l1, 'aria-label="Inativar o cliente Pag 001 Comercio"') && str_contains($l1, 'aria-label="Excluir o cliente Pag 001 Comercio"') && str_contains($l1, 'name="csrf_token"') && str_contains($l1, 'name="acao" value="inativar"') && str_contains($l1, 'name="id_cliente" value="' . $idP1 . '"') && str_contains($l1, 'data-ativo="1"') && str_contains($l1, 'gestao-acoes-grupo--destrutivo'));
    $pAll5 = $get('clientes.php', $lAdm, ['q' => 'Pag 005']);
    $l5 = $linhaCli($pAll5['corpo'], $idP5);
    afirmar('linha de cliente INATIVO: Ativar no lugar de Inativar (form-cliente-ativar-<id>, btn-cliente-ativar-<id>), data-ativo=0', str_contains($l5, 'id="form-cliente-ativar-' . $idP5 . '"') && str_contains($l5, 'id="btn-cliente-ativar-' . $idP5 . '"') && !str_contains($l5, 'btn-cliente-inativar-') && str_contains($l5, 'name="acao" value="ativar"') && str_contains($l5, 'data-ativo="0"') && str_contains($l5, 'aria-label="Ativar o cliente Pag 005 Comercio"'));
    afirmar('clientes: TODO formulario POST da lista tem o campo csrf_token (' . substr_count($corpoL, 'method="post"') . ' formularios)', substr_count($corpoL, 'method="post"') > 25 && substr_count($corpoL, 'method="post"') === substr_count($corpoL, 'name="csrf_token"'));

    // --- criar cliente por HTTP + PRG
    $nAntes = $nCli();
    $cHttp = cnpjValido('710000000001');
    $pC = $post('cliente-form.php', $lAdm, ['nome' => 'Http Criado Comercio', 'cnpj' => mascarar($cHttp), 'ativo' => '1'], ['ip' => '192.0.2.31']);
    afirmar('criar cliente (HTTP): 302 PRG para clientes.php?msg=cliente_criado, corpo vazio, CNPJ mascarado gravado SO com digitos', $pC['status'] === 302 && loc($pC) === '/gestao/clientes.php?msg=cliente_criado' && $pC['corpo'] === '' && $nCli() === $nAntes + 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente WHERE cnpj = :c', ['c' => $cHttp]) === 1);
    $idHttp = (int) gtEscalar($pdo, 'SELECT id_cliente FROM tb_cliente WHERE cnpj = :c', ['c' => $cHttp]);
    $pFl = $get('clientes.php', $lAdm, ['msg' => 'cliente_criado', 'q' => 'Http Criado']);
    afirmar('criar cliente (HTTP): flash de sucesso acentuado e o cliente aparece na lista', str_contains($pFl['corpo'], 'Cliente cadastrado. O OCR e o autocomplete já o reconhecem.') && str_contains($pFl['corpo'], 'Http Criado Comercio') && str_contains($pFl['corpo'], '71.000.000/0001-'));
    $pCr = $post('cliente-form.php', $lAdm, ['nome' => 'Http Criado Comercio', 'cnpj' => $cHttp]);
    afirmar('criar cliente (HTTP): reenvio (F5) => 422 com erro de duplicidade no campo CNPJ, sem expor o nome, sem segunda linha', $pCr['status'] === 422 && str_contains($pCr['corpo'], 'id="erro-cliente-cnpj"') && str_contains($pCr['corpo'], 'Já existe um cliente cadastrado com este CNPJ') && $nCli() === $nAntes + 1);
    $pUd = $post('cliente-form.php', $lAdm, ['nome' => 'Quase Udlog', 'cnpj' => mascarar($cnpjUdlog)]);
    afirmar('criar cliente (HTTP): CNPJ da UDLOG => 422 no campo cnpj', $pUd['status'] === 422 && str_contains($pUd['corpo'], 'id="erro-cliente-cnpj"') && str_contains($pUd['corpo'], 'UDLOG') && $nCli() === $nAntes + 1);
    foreach ([['nome' => '***', 'cnpj' => cnpjValido('710000000002')], ['nome' => "A\0B", 'cnpj' => cnpjValido('710000000002')], ['nome' => str_repeat('N', 151), 'cnpj' => cnpjValido('710000000002')], ['nome' => 'Valido Comercio', 'cnpj' => '123'], ['nome' => '😀😀', 'cnpj' => cnpjValido('710000000002')], ['nome' => "Rtl\u{202E}Nome", 'cnpj' => cnpjValido('710000000002')]] as $f) {
        $pv = $post('cliente-form.php', $lAdm, $f);
        afirmar('criar cliente (HTTP) invalido ' . json_encode(array_map(static fn ($v) => mb_substr($v, 0, 12), $f), JSON_UNESCAPED_UNICODE) . ' => 422, erro no campo e nada criado', $pv['status'] === 422 && str_contains($pv['corpo'], 'role="alert"') && $nCli() === $nAntes + 1);
    }
    $pBlank = $get('cliente-form.php', $lAdm);
    afirmar('formulario novo: ids de contrato (form-cliente, cliente-nome, cliente-cnpj editavel, cliente-ativo, btn-salvar-cliente) e <title> Novo cliente', str_contains($pBlank['corpo'], 'id="form-cliente"') && str_contains($pBlank['corpo'], 'id="cliente-nome"') && str_contains($pBlank['corpo'], 'id="cliente-cnpj" name="cnpj"') && !str_contains($pBlank['corpo'], 'id="cliente-cnpj" type="text" value="" readonly') && str_contains($pBlank['corpo'], 'id="cliente-ativo"') && str_contains($pBlank['corpo'], 'id="btn-salvar-cliente"') && str_contains($pBlank['corpo'], '<title>Novo cliente - Gestão Totem</title>') && str_contains($pBlank['corpo'], 'name="csrf_token"') && !str_contains($pBlank['corpo'], 'name="id_cliente"'));
    $pXX = $post('cliente-form.php', $lAdm, ['nome' => '***', 'cnpj' => '12']);
    afirmar('formulario 422: erro-cliente-nome e erro-cliente-cnpj com role=alert e aria-describedby/aria-invalid nos campos; valores preservados', str_contains($pXX['corpo'], 'id="erro-cliente-nome"') && str_contains($pXX['corpo'], 'id="erro-cliente-cnpj"') && str_contains($pXX['corpo'], 'aria-invalid="true"') && str_contains($pXX['corpo'], 'value="***"') && str_contains($pXX['corpo'], 'value="12"'));

    // --- editar cliente por HTTP: CNPJ imutavel
    $pEd = $get('cliente-form.php', $lAdm, ['id' => (string) $idHttp]);
    afirmar('formulario de edicao: id_cliente oculto, cliente-cnpj SOMENTE LEITURA e formatado, sem name (nao e enviado), sem seletor de situacao, <title> Editar cliente', str_contains($pEd['corpo'], 'name="id_cliente" value="' . $idHttp . '"') && str_contains($pEd['corpo'], 'id="cliente-cnpj" type="text" value="71.000.000/0001-') && preg_match('/id="cliente-cnpj"[^>]*\sreadonly/', $pEd['corpo']) === 1 && preg_match('/id="cliente-cnpj"[^>]*\sname=/', $pEd['corpo']) !== 1 && !str_contains($pEd['corpo'], 'id="cliente-ativo"') && str_contains($pEd['corpo'], '<title>Editar cliente - Gestão Totem</title>') && str_contains($pEd['corpo'], 'value="Http Criado Comercio"'));
    $outroCnpj = cnpjValido('720000000001');
    $pE1 = $post('cliente-form.php', $lAdm, ['id_cliente' => (string) $idHttp, 'nome' => 'Http Renomeado Comercio', 'cnpj' => $outroCnpj, 'ativo' => '0']);
    $linhaH = $linhasCli($idHttp);
    afirmar('editar cliente (HTTP): renomeia e recalcula a razao; cnpj e ativo ENVIADOS NO POST sao ignorados (CNPJ imutavel, situacao so por acao)', $pE1['status'] === 302 && loc($pE1) === '/gestao/clientes.php?msg=cliente_editado' && $linhaH['nome'] === 'Http Renomeado Comercio' && $linhaH['cnpj'] === $cHttp && (int) $linhaH['ativo'] === 1 && $linhaH['razao_social_normalizada'] === RazaoSocialMatcher::normalizar('Http Renomeado Comercio'));
    $pE2 = $post('cliente-form.php', $lAdm, ['id_cliente' => (string) $idHttp, 'nome' => 'Http Renomeado Comercio']);
    afirmar('editar cliente com o mesmo nome (HTTP): 302 cliente_sem_mudanca', loc($pE2) === '/gestao/clientes.php?msg=cliente_sem_mudanca');
    $pE3 = $post('cliente-form.php', $lAdm, ['id_cliente' => (string) $idHttp, 'nome' => '***']);
    afirmar('editar cliente (HTTP) com nome invalido: 422, CNPJ continua somente leitura e nada muda', $pE3['status'] === 422 && str_contains($pE3['corpo'], 'id="erro-cliente-nome"') && preg_match('/id="cliente-cnpj"[^>]*\sreadonly/', $pE3['corpo']) === 1 && $linhasCli($idHttp)['nome'] === 'Http Renomeado Comercio');
    $pE4 = $post('cliente-form.php', $lAdm, ['id_cliente' => '987654', 'nome' => 'Fantasma Comercio']);
    afirmar('editar cliente inexistente (HTTP): 302 cliente_nao_encontrado', loc($pE4) === '/gestao/clientes.php?msg=cliente_nao_encontrado');

    // --- acoes de cliente por HTTP: confirmacao em dois passos
    $idDel = (int) $rnC->criar($idAdmin, 'Http Para Excluir', cnpjValido('730000000001'), '')['id'];
    $pX1 = $post('cliente-acao.php', $lAdm, ['acao' => 'excluir', 'id_cliente' => (string) $idDel]);
    afirmar('excluir (HTTP) 1o passo: 302 para clientes.php?confirmar=excluir&id=N&msg=cliente_confirmacao_necessaria e NADA foi apagado', $pX1['status'] === 302 && loc($pX1) === '/gestao/clientes.php?confirmar=excluir&id=' . $idDel . '&msg=cliente_confirmacao_necessaria' && $linhasCli($idDel) !== []);
    $pXc = $get('clientes.php', $lAdm, ['confirmar' => 'excluir', 'id' => (string) $idDel, 'msg' => 'cliente_confirmacao_necessaria']);
    $posCancelar = strpos($pXc['corpo'], 'id="cliente-confirmacao-cancelar"');
    $posConfirmar = strpos($pXc['corpo'], 'id="btn-cliente-confirmar"');
    afirmar('excluir 1o passo: bloco #cliente-confirmacao[data-acao=excluir] com o aviso EXATO, form com confirmar=1, Cancelar ANTES do botao destrutivo', str_contains($pXc['corpo'], 'id="cliente-confirmacao"') && str_contains($pXc['corpo'], 'data-acao="excluir"') && str_contains($pXc['corpo'], 'Esta ação exclui o cliente de forma definitiva. O OCR e o autocomplete deixam de reconhecê-lo imediatamente. Para voltar a cadastrá-lo será preciso criar de novo.') && str_contains($pXc['corpo'], 'name="confirmar" value="1"') && $posCancelar !== false && $posConfirmar !== false && $posCancelar < $posConfirmar && str_contains($pXc['corpo'], 'Http Para Excluir') && str_contains($pXc['corpo'], 'Esta ação precisa de confirmação'));
    $pXn = $post('cliente-acao.php', $lAdm, ['acao' => 'excluir', 'id_cliente' => (string) $idDel, 'confirmar' => '0']);
    afirmar('excluir: confirmar diferente de "1" ("0") nao confirma', loc($pXn) === '/gestao/clientes.php?confirmar=excluir&id=' . $idDel . '&msg=cliente_confirmacao_necessaria' && $linhasCli($idDel) !== []);
    $pX2 = $post('cliente-acao.php', $lAdm, ['acao' => 'excluir', 'id_cliente' => (string) $idDel, 'confirmar' => '1']);
    afirmar('excluir (HTTP) 2o passo: 302 cliente_excluido e a linha foi APAGADA', loc($pX2) === '/gestao/clientes.php?msg=cliente_excluido' && $linhasCli($idDel) === []);
    $pX3 = $post('cliente-acao.php', $lAdm, ['acao' => 'excluir', 'id_cliente' => (string) $idDel, 'confirmar' => '1']);
    afirmar('excluir de novo (HTTP, dois admins/duplo clique): 302 cliente_nao_encontrado (sem erro 500)', $pX3['status'] === 302 && loc($pX3) === '/gestao/clientes.php?msg=cliente_nao_encontrado');
    $pXcGone = $get('clientes.php', $lAdm, ['confirmar' => 'excluir', 'id' => (string) $idDel]);
    afirmar('confirmar= para cliente que nao existe mais: sem bloco de confirmacao', !str_contains($pXcGone['corpo'], 'id="cliente-confirmacao"') && $pXcGone['status'] === 200);
    foreach (['apagar', 'ativar', "excluir'", ['excluir']] as $cH) {
        $pH = $get('clientes.php', $lAdm, ['confirmar' => $cH, 'id' => (string) $idHttp]);
        afirmar('confirmar= hostil ' . json_encode($cH) . ': ignorado (sem bloco), 200', $pH['status'] === 200 && !str_contains($pH['corpo'], 'id="cliente-confirmacao"'));
    }
    $idAnd2 = (int) $rnC->criar($idAdmin, 'Http Com Andamento', $cAnd, '')['id'];
    $atend($tFix, 'em_andamento', $cAnd);
    $atend($tFix, 'em_andamento', mascarar($cAnd));
    $nAndH = $rnC->andamentoDoCliente($cAnd);
    $snapAtendH = $snapAtend();
    $pI1 = $post('cliente-acao.php', $lAdm, ['acao' => 'inativar', 'id_cliente' => (string) $idAnd2]);
    afirmar('inativar com atendimento em andamento (HTTP) 1o passo: 302 para a confirmacao e o cliente continua ativo', loc($pI1) === '/gestao/clientes.php?confirmar=inativar&id=' . $idAnd2 . '&msg=cliente_confirmacao_necessaria' && (int) $linhasCli($idAnd2)['ativo'] === 1);
    $pIc = $get('clientes.php', $lAdm, ['confirmar' => 'inativar', 'id' => (string) $idAnd2]);
    afirmar('inativar 1o passo: aviso "Ha N atendimento(s) em andamento que usam este cliente." e data-acao=inativar', str_contains($pIc['corpo'], 'data-acao="inativar"') && str_contains($pIc['corpo'], 'Há ' . $nAndH . ' atendimento(s) em andamento que usam este cliente.') && str_contains($pIc['corpo'], 'name="confirmar" value="1"'));
    $pIx = $get('clientes.php', $lAdm, ['confirmar' => 'excluir', 'id' => (string) $idAnd2]);
    afirmar('excluir 1o passo com andamento: o aviso traz a contagem E o texto de exclusao definitiva', str_contains($pIx['corpo'], 'Há ' . $nAndH . ' atendimento(s) em andamento que usam este cliente. Esta ação exclui o cliente de forma definitiva.'));
    $pI2 = $post('cliente-acao.php', $lAdm, ['acao' => 'inativar', 'id_cliente' => (string) $idAnd2, 'confirmar' => '1']);
    afirmar('inativar (HTTP) 2o passo: 302 cliente_inativado, ativo=0; auditoria com motivo_cad=andamento_confirmado; atendimentos intactos', loc($pI2) === '/gestao/clientes.php?msg=cliente_inativado' && (int) $linhasCli($idAnd2)['ativo'] === 0 && ultima($audit('CLIENTE_ATIVO'))['detalhe'] === 'ativo_para=0;motivo_cad=andamento_confirmado' && $snapAtend() === $snapAtendH);
    $pIcAgora = $get('clientes.php', $lAdm, ['confirmar' => 'inativar', 'id' => (string) $idAnd2]);
    afirmar('confirmar=inativar de cliente ja inativo: sem bloco', !str_contains($pIcAgora['corpo'], 'id="cliente-confirmacao"'));
    $pA1 = $post('cliente-acao.php', $lAdm, ['acao' => 'ativar', 'id_cliente' => (string) $idAnd2]);
    afirmar('ativar (HTTP): direto, 302 cliente_ativado', loc($pA1) === '/gestao/clientes.php?msg=cliente_ativado' && (int) $linhasCli($idAnd2)['ativo'] === 1);
    $pA2 = $post('cliente-acao.php', $lAdm, ['acao' => 'ativar', 'id_cliente' => (string) $idAnd2]);
    afirmar('ativar quem ja esta ativo (HTTP): 302 cliente_sem_mudanca', loc($pA2) === '/gestao/clientes.php?msg=cliente_sem_mudanca');
    $idSem = (int) $rnC->criar($idAdmin, 'Http Sem Andamento', cnpjValido('730000000002'), '', null, true)['id'];
    $pI3 = $post('cliente-acao.php', $lAdm, ['acao' => 'inativar', 'id_cliente' => (string) $idSem]);
    afirmar('inativar sem atendimento (HTTP): direto (302 cliente_inativado), sem passo de confirmacao', loc($pI3) === '/gestao/clientes.php?msg=cliente_inativado' && (int) $linhasCli($idSem)['ativo'] === 0);
    $pI4 = $post('cliente-acao.php', $lAdm, ['acao' => 'inativar', 'id_cliente' => (string) $idSem]);
    afirmar('inativar de novo (HTTP): 302 cliente_sem_mudanca', loc($pI4) === '/gestao/clientes.php?msg=cliente_sem_mudanca');

    // --- A1 por HTTP: ambiguidade do OCR (dois passos), A2 (texto de ajuda), O3 (id so do corpo em POST)
    $textoAmb = static fn (int $n): string => 'Este nome é parecido com o de outros clientes e pode fazer o OCR das notas não identificar automaticamente ' . $n . ' cliente(s) (cairá no preenchimento manual). Confirme para continuar.';
    $esc = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $idAlH = (int) $rnC->criar($idAdmin, 'ALLIANCE', cnpjValido('740000000001'), '')['id'];
    $idOmH = (int) $rnC->criar($idAdmin, 'Omega <b>Tintas</b>', cnpjValido('740000000002'), '')['id'];
    $snapHa = $snapC();
    $audHa = $nAudit();
    $pAm1 = $post('cliente-form.php', $lAdm, ['nome' => 'Alliance Quimica Ltda', 'cnpj' => cnpjValido('740000000003'), 'ativo' => '1']);
    afirmar('criar (HTTP) 1o passo: 200 (sem gravar) com o aviso EXATO (N=1) no flash e em #cliente-ambiguidade-texto, nome do cliente afetado so na tela de confirmacao', $pAm1['status'] === 200 && str_contains($pAm1['corpo'], 'id="cliente-ambiguidade"') && str_contains($pAm1['corpo'], 'id="cliente-ambiguidade-texto">' . $esc($textoAmb(1)) . '</p>') && str_contains($pAm1['corpo'], 'id="gestao-flash"') && str_contains($pAm1['corpo'], $esc($textoAmb(1))) && str_contains($pAm1['corpo'], 'id="cliente-ambiguidade-afetados">Clientes afetados (até 5): ALLIANCE</p>') && $snapC() === $snapHa && $nAudit() === $audHa);
    $flashAm = preg_match('#id="gestao-flash".*?</div>#s', $pAm1['corpo'], $mFl) === 1 ? $mFl[0] : '';
    afirmar('criar (HTTP) 1o passo: o FLASH tem so a mensagem fixa (sem nome de cliente)', str_contains($flashAm, $esc($textoAmb(1))) && !str_contains($flashAm, 'ALLIANCE</'));
    afirmar('criar (HTTP) 1o passo: form de confirmacao com csrf, nome/cnpj/ativo ocultos, confirmar=1 e botoes Confirmar, Voltar e Cancelar; sem o formulario editavel', str_contains($pAm1['corpo'], 'id="form-cliente-confirmar-ambiguidade"') && str_contains($pAm1['corpo'], 'name="confirmar" value="1"') && str_contains($pAm1['corpo'], 'name="csrf_token"') && str_contains($pAm1['corpo'], '<input type="hidden" name="nome" value="Alliance Quimica Ltda">') && str_contains($pAm1['corpo'], 'name="cnpj" value="' . cnpjValido('740000000003') . '"') && str_contains($pAm1['corpo'], 'id="btn-confirmar-ambiguidade"') && str_contains($pAm1['corpo'], 'id="btn-voltar-ambiguidade"') && str_contains($pAm1['corpo'], 'id="btn-cancelar-ambiguidade"') && !str_contains($pAm1['corpo'], 'id="form-cliente"') && !str_contains($pAm1['corpo'], 'id="btn-salvar-cliente"'));
    $pAm0 = $post('cliente-form.php', $lAdm, ['nome' => 'Alliance Quimica Ltda', 'cnpj' => cnpjValido('740000000003'), 'ativo' => '1', 'confirmar' => '0']);
    afirmar('criar (HTTP): confirmar diferente de "1" ("0") continua no 1o passo e nada e criado', $pAm0['status'] === 200 && str_contains($pAm0['corpo'], 'id="cliente-ambiguidade"') && $snapC() === $snapHa);
    $pAm2 = $post('cliente-form.php', $lAdm, ['nome' => 'Alliance Quimica Ltda', 'cnpj' => cnpjValido('740000000003'), 'ativo' => '1', 'confirmar' => '1']);
    afirmar('criar (HTTP) 2o passo: 302 cliente_criado, cliente gravado e auditoria com motivo_cad=confirmado_ambiguidade_ocr', $pAm2['status'] === 302 && loc($pAm2) === '/gestao/clientes.php?msg=cliente_criado' && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_cliente WHERE cnpj = :c', ['c' => cnpjValido('740000000003')]) === 1 && ultima($audit('CLIENTE_CRIAR'))['detalhe'] === 'ativo_para=1;motivo_cad=confirmado_ambiguidade_ocr');
    $idAqH = (int) gtEscalar($pdo, 'SELECT id_cliente FROM tb_cliente WHERE cnpj = :c', ['c' => cnpjValido('740000000003')]);
    $pAmX = $post('cliente-form.php', $lAdm, ['nome' => 'Omega B Tintas B Sul', 'cnpj' => cnpjValido('740000000004'), 'ativo' => '1']);
    afirmar('criar (HTTP) 1o passo: nomes dos afetados ESCAPADOS na tela (Omega &lt;b&gt;Tintas&lt;/b&gt;), nunca HTML cru', $pAmX['status'] === 200 && str_contains($pAmX['corpo'], 'Omega &lt;b&gt;Tintas&lt;/b&gt;') && !str_contains($pAmX['corpo'], 'Omega <b>Tintas</b>'));
    $pSemConf = $post('cliente-form.php', $lAdm, ['nome' => 'Zorba Logistica', 'cnpj' => cnpjValido('740000000005'), 'ativo' => '1']);
    afirmar('criar (HTTP): nome sem conflito => 302 cliente_criado direto (sem aviso)', $pSemConf['status'] === 302 && loc($pSemConf) === '/gestao/clientes.php?msg=cliente_criado');
    // editar por HTTP
    $idZbH = (int) gtEscalar($pdo, 'SELECT id_cliente FROM tb_cliente WHERE cnpj = :c', ['c' => cnpjValido('740000000005')]);
    $pEm1 = $post('cliente-form.php', $lAdm, ['id_cliente' => (string) $idZbH, 'nome' => 'Omega B Tintas B Norte']);
    afirmar('editar (HTTP) 1o passo: 200 com o aviso (N), id_cliente oculto e nada gravado', $pEm1['status'] === 200 && str_contains($pEm1['corpo'], 'id="cliente-ambiguidade"') && str_contains($pEm1['corpo'], 'name="id_cliente" value="' . $idZbH . '"') && str_contains($pEm1['corpo'], 'id="btn-voltar-ambiguidade" href="/gestao/cliente-form.php?id=' . $idZbH . '"') && $linhasCli($idZbH)['nome'] === 'Zorba Logistica');
    $pEm2 = $post('cliente-form.php', $lAdm, ['id_cliente' => (string) $idZbH, 'nome' => 'Omega B Tintas B Norte', 'confirmar' => '1']);
    afirmar('editar (HTTP) 2o passo: 302 cliente_editado, nome gravado, auditoria com o motivo novo', $pEm2['status'] === 302 && loc($pEm2) === '/gestao/clientes.php?msg=cliente_editado' && $linhasCli($idZbH)['nome'] === 'Omega B Tintas B Norte' && ultima($audit('CLIENTE_EDITAR'))['detalhe'] === 'motivo_cad=confirmado_ambiguidade_ocr');
    // ativar por HTTP
    $idSulH = (int) $rnC->criar($idAdmin, 'Alliance Quimica Sul', cnpjValido('740000000006'), '0')['id'];
    $pAt1 = $post('cliente-acao.php', $lAdm, ['acao' => 'ativar', 'id_cliente' => (string) $idSulH]);
    afirmar('ativar (HTTP) 1o passo: 302 para clientes.php?confirmar=ativar&id=N&msg=cliente_confirmacao_necessaria e o cliente continua inativo', $pAt1['status'] === 302 && loc($pAt1) === '/gestao/clientes.php?confirmar=ativar&id=' . $idSulH . '&msg=cliente_confirmacao_necessaria' && (int) $linhasCli($idSulH)['ativo'] === 0);
    $pAtc = $get('clientes.php', $lAdm, ['confirmar' => 'ativar', 'id' => (string) $idSulH, 'msg' => 'cliente_confirmacao_necessaria']);
    $nAtivar = $rnC->ambiguidadeAoAtivar($idSulH)['total'];
    afirmar('ativar 1o passo: bloco #cliente-confirmacao[data-acao=ativar] com o aviso EXATO (N), clientes afetados, form confirmar=1 e botao "Ativar mesmo assim"', $nAtivar >= 1 && str_contains($pAtc['corpo'], 'id="cliente-confirmacao"') && str_contains($pAtc['corpo'], 'data-acao="ativar"') && str_contains($pAtc['corpo'], 'id="cliente-confirmacao-texto">' . $esc($textoAmb($nAtivar)) . '</p>') && str_contains($pAtc['corpo'], 'id="cliente-confirmacao-afetados"') && str_contains($pAtc['corpo'], 'name="confirmar" value="1"') && str_contains($pAtc['corpo'], 'Ativar mesmo assim'));
    $pAtn = $post('cliente-acao.php', $lAdm, ['acao' => 'ativar', 'id_cliente' => (string) $idSulH, 'confirmar' => '0']);
    afirmar('ativar: confirmar "0" nao confirma (volta ao 1o passo)', loc($pAtn) === '/gestao/clientes.php?confirmar=ativar&id=' . $idSulH . '&msg=cliente_confirmacao_necessaria' && (int) $linhasCli($idSulH)['ativo'] === 0);
    $pAt2 = $post('cliente-acao.php', $lAdm, ['acao' => 'ativar', 'id_cliente' => (string) $idSulH, 'confirmar' => '1']);
    afirmar('ativar (HTTP) 2o passo: 302 cliente_ativado, ativo=1, auditoria com o motivo novo', loc($pAt2) === '/gestao/clientes.php?msg=cliente_ativado' && (int) $linhasCli($idSulH)['ativo'] === 1 && ultima($audit('CLIENTE_ATIVO'))['detalhe'] === 'ativo_para=1;motivo_cad=confirmado_ambiguidade_ocr');
    $pAtAgora = $get('clientes.php', $lAdm, ['confirmar' => 'ativar', 'id' => (string) $idSulH]);
    afirmar('confirmar=ativar de cliente ja ativo: sem bloco', !str_contains($pAtAgora['corpo'], 'id="cliente-confirmacao"') && $pAtAgora['status'] === 200);
    // A2: texto de ajuda
    $pAj = $get('cliente-form.php', $lAdm);
    $ajuda = preg_match('#id="cliente-nome-ajuda">(.*?)</span>#s', $pAj['corpo'], $mAj) === 1 ? $mAj[1] : '';
    afirmar('A2: ajuda do nome = texto atual (acentos ignorados na normalizacao; "O OCR compara" antigo removido)', $ajuda === 'O nome é normalizado (maiúsculas, sem acentos, sem pontuação e sem termos como LTDA e S/A) para o reconhecimento automático das notas; acentos são ignorados, então Café e Cafe são o mesmo nome. Prefira escrever o nome como aparece na nota fiscal.' && !str_contains($pAj['corpo'], 'O OCR compara'));
    // O3: em POST vale so o id do corpo
    $nomeAlH = (string) $linhasCli($idAlH)['nome'];
    $nAntesO3 = $nCli();
    $pO3 = $post('cliente-form.php', $lAdm, ['nome' => 'Cliente Query Id', 'cnpj' => cnpjValido('740000000007'), 'ativo' => '1'], ['query' => ['id' => (string) $idAlH]]);
    afirmar('O3 cliente: POST de CRIACAO com ?id= de cliente existente continua sendo criacao (302 cliente_criado, +1 cliente) e o existente nao muda', $pO3['status'] === 302 && loc($pO3) === '/gestao/clientes.php?msg=cliente_criado' && $nCli() === $nAntesO3 + 1 && $linhasCli($idAlH)['nome'] === $nomeAlH);
    $pO3b = $post('cliente-form.php', $lAdm, ['nome' => 'Nome Via Corpo', 'id_cliente' => '987654'], ['query' => ['id' => (string) $idAlH]]);
    afirmar('O3 cliente: POST com id_cliente inexistente no CORPO e ?id= valido => nao_encontrado (o corpo manda), existente intacto', loc($pO3b) === '/gestao/clientes.php?msg=cliente_nao_encontrado' && $linhasCli($idAlH)['nome'] === $nomeAlH);
    $idQ = (int) gtEscalar($pdo, 'SELECT id_cliente FROM tb_cliente WHERE cnpj = :c', ['c' => cnpjValido('740000000007')]);
    $pO3c = $post('cliente-form.php', $lAdm, ['id_cliente' => (string) $idQ, 'nome' => 'Cliente Query Renomeado'], ['query' => ['id' => (string) $idAlH]]);
    afirmar('O3 cliente: POST de edicao usa o id do CORPO, nao o da URL', loc($pO3c) === '/gestao/clientes.php?msg=cliente_editado' && $linhasCli($idQ)['nome'] === 'Cliente Query Renomeado' && $linhasCli($idAlH)['nome'] === $nomeAlH);
    $pO3g = $get('cliente-form.php', $lAdm, ['id' => (string) $idAlH]);
    afirmar('O3 cliente: GET ?id= continua abrindo a edicao', $pO3g['status'] === 200 && str_contains($pO3g['corpo'], 'name="id_cliente" value="' . $idAlH . '"'));
    $pO3e = $post('empresa-form.php', $lAdm, ['nome' => 'Empresa Query Id', 'cnpj' => cnpjValido('740000000008')], ['query' => ['id' => (string) $idE]]);
    $nomeEmpE = (string) $linhasEmp($idE)['nome'];
    afirmar('O3 empresa: POST de CRIACAO com ?id= de empresa existente continua sendo criacao (302 empresa_criada) e a existente nao muda', $pO3e['status'] === 302 && loc($pO3e) === '/gestao/empresas.php?msg=empresa_criada' && $linhasEmp($idE)['nome'] === $nomeEmpE && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_empresa WHERE cnpj = :c', ['c' => cnpjValido('740000000008')]) === 1);
    $pO3f = $post('empresa-form.php', $lAdm, ['nome' => 'Nome Via Corpo', 'id_empresa' => '987654'], ['query' => ['id' => (string) $idE]]);
    afirmar('O3 empresa: id_empresa inexistente no CORPO + ?id= valido => nao_encontrada, existente intacta', loc($pO3f) === '/gestao/empresas.php?msg=empresa_nao_encontrada' && $linhasEmp($idE)['nome'] === $nomeEmpE);
    $idEQ = (int) gtEscalar($pdo, 'SELECT id_empresa FROM tb_empresa WHERE cnpj = :c', ['c' => cnpjValido('740000000008')]);
    $pO3h = $post('empresa-form.php', $lAdm, ['id_empresa' => (string) $idEQ, 'nome' => 'Empresa Query Ren'], ['query' => ['id' => (string) $idE]]);
    afirmar('O3 empresa: POST de edicao usa o id do CORPO, nao o da URL', loc($pO3h) === '/gestao/empresas.php?msg=empresa_editada' && $linhasEmp($idEQ)['nome'] === 'Empresa Query Ren' && $linhasEmp($idE)['nome'] === $nomeEmpE);
    $pO3i = $get('empresa-form.php', $lAdm, ['id' => (string) $idE]);
    afirmar('O3 empresa: GET ?id= continua abrindo a edicao', $pO3i['status'] === 200 && str_contains($pO3i['corpo'], 'name="id_empresa" value="' . $idE . '"'));
    // O2 por HTTP: slug duplicado volta 422 com a mensagem fixa
    $pSl = $post('empresa-form.php', $lAdm, ['nome' => 'MAUA-I', 'cnpj' => cnpjValido('740000000009')]);
    afirmar('O2 (HTTP): criar empresa com slug de outra => 422 com a mensagem fixa no campo nome, sem revelar o outro nome, nada criado', $pSl['status'] === 422 && str_contains($pSl['corpo'], 'id="erro-empresa-nome"') && str_contains($pSl['corpo'], $esc('Já existe uma empresa com um nome equivalente. Use um nome diferente.')) && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_empresa WHERE cnpj = :c', ['c' => cnpjValido('740000000009')]) === 0);
    foreach ([$idAlH, $idOmH, $idAqH, $idZbH, $idSulH, $idQ] as $idLimpo) {
        $rnC->excluir($idAdmin, $idLimpo, true);
    }
    $rnE->excluir($idAdmin, $idEQ, true);

    // --- flash: todas as mensagens novas do catalogo
    foreach (['cliente_criado', 'cliente_editado', 'cliente_ativado', 'cliente_inativado', 'cliente_excluido', 'cliente_sem_mudanca', 'cliente_nao_encontrado', 'cliente_confirmacao_necessaria', 'empresa_criada', 'empresa_editada', 'empresa_ativada', 'empresa_inativada', 'empresa_excluida', 'empresa_sem_mudanca', 'empresa_nao_encontrada', 'empresa_confirmacao_necessaria', 'empresa_com_totens'] as $cod) {
        $existe = isset(App\Controller\GestaoContexto::MENSAGENS[$cod]) && in_array(App\Controller\GestaoContexto::MENSAGENS[$cod][0], ['sucesso', 'erro', 'info'], true);
        $pf = $get(str_starts_with($cod, 'cliente') ? 'clientes.php' : 'empresas.php', $lAdm, ['msg' => $cod]);
        afirmar("flash $cod: no catalogo e renderizado com o texto fixo", $existe && str_contains($pf['corpo'], 'id="gestao-flash"') && str_contains($pf['corpo'], htmlspecialchars(App\Controller\GestaoContexto::MENSAGENS[$cod][1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
    }
    $pFh = $get('clientes.php', $lAdm, ['msg' => '<script>alert(1)</script>']);
    afirmar('flash: ?msg hostil e ignorado (sem flash, sem eco)', !str_contains($pFh['corpo'], 'id="gestao-flash"') && !str_contains($pFh['corpo'], '<script>alert(1)'));
    afirmar('catalogo: mensagens novas acentuadas, sem texto do usuario nem tags', (static function (): bool {
        foreach (['cliente_criado', 'cliente_editado', 'cliente_ativado', 'cliente_inativado', 'cliente_excluido', 'cliente_sem_mudanca', 'cliente_nao_encontrado', 'cliente_confirmacao_necessaria', 'empresa_criada', 'empresa_editada', 'empresa_ativada', 'empresa_inativada', 'empresa_excluida', 'empresa_sem_mudanca', 'empresa_nao_encontrada', 'empresa_confirmacao_necessaria', 'empresa_com_totens'] as $c) {
            $t = App\Controller\GestaoContexto::MENSAGENS[$c][1] ?? '';
            if ($t === '' || $t !== strip_tags($t) || preg_match('/\b(voce|nao|pagina|acao|excluido|invalido|confirmacao|situacao|codigo)\b/i', $t) === 1) {
                return false;
            }
        }

        return true;
    })());

    // --- XSS
    $pXl = $get('clientes.php', $lAdm, ['q' => '<scr']);
    $pXs = $get('clientes.php', $lAdm, ['q' => '<script>alert(1)</script>']);
    afirmar('XSS: cliente com <script> e aspas no nome sai ESCAPADO na lista (celula e aria-label), sem tag crua', str_contains($pXs['corpo'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($pXs['corpo'], '<script>alert(1)') && str_contains($pXs['corpo'], '&quot;A&#039;&amp;') && !str_contains($pXs['corpo'], 'aria-label="Editar o cliente <script') && str_contains($pXs['corpo'], 'value="&lt;script&gt;alert(1)&lt;/script&gt;'));
    $pXe = $get('cliente-form.php', $lAdm, ['id' => (string) $idXss]);
    afirmar('XSS: formulario de edicao escapa o nome no value=', str_contains($pXe['corpo'], 'value="&lt;script&gt;alert(1)&lt;/script&gt; &quot;A&#039;&amp;"') && !str_contains($pXe['corpo'], '<script>alert(1)'));
    $pXc2 = $get('clientes.php', $lAdm, ['confirmar' => 'excluir', 'id' => (string) $idXss]);
    afirmar('XSS: bloco de confirmacao escapa o nome (texto e aria-label do botao)', str_contains($pXc2['corpo'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($pXc2['corpo'], '<script>alert(1)') && str_contains($pXc2['corpo'], 'aria-label="Excluir definitivamente: &lt;script&gt;'));
    $pXpost = $post('cliente-form.php', $lAdm, ['nome' => '"><img src=x onerror=alert(2)>', 'cnpj' => '"><script>alert(3)</script>']);
    afirmar('XSS: valores hostis devolvidos num 422 saem escapados', $pXpost['status'] === 422 && !str_contains($pXpost['corpo'], '<img src=x') && !str_contains($pXpost['corpo'], '<script>alert(3)') && str_contains($pXpost['corpo'], '&lt;img src=x onerror=alert(2)&gt;'));

    // --- empresas por HTTP
    $pEL = $get('empresas.php', $lAdm);
    $corpoEL = $pEL['corpo'];
    afirmar('empresas: ids de contrato (aviso, nova, tabela com caption e th scope, colunas, linhas data-id-empresa/data-ativo)', str_contains($corpoEL, 'id="empresas-aviso"') && str_contains($corpoEL, 'id="btn-nova-empresa"') && str_contains($corpoEL, 'id="tabela-empresas"') && str_contains($corpoEL, '<caption') && substr_count($corpoEL, '<th scope="col">') === 6 && preg_match('/<tr class="gestao-tabela__linha[^"]*" data-id-empresa="\d+" data-ativo="[01]"/', $corpoEL) === 1 && str_contains($corpoEL, 'class="col-nome"') && str_contains($corpoEL, 'class="col-cnpj"') && str_contains($corpoEL, 'class="col-situacao"') && str_contains($corpoEL, 'class="col-totens"') && str_contains($corpoEL, 'class="col-criada"') && str_contains($corpoEL, 'class="col-acoes"'));
    afirmar('empresas: CNPJ formatado; coluna de totens mostra "N ativo(s) de M"; sem codigo nem token de totem na pagina', str_contains($corpoEL, '14.706.199/0001-82') && str_contains($corpoEL, '1 ativo(s) de 1') && !str_contains($corpoEL, $codTotemFix) && !str_contains($corpoEL, $tokTotemFix) && !str_contains($corpoEL, 'TOTEM-A-MAUAIII') && !str_contains($corpoEL, 'token_api'));
    $linhaEmp = static fn (string $html, int $id): string => preg_match('/<tr class="gestao-tabela__linha[^"]*" data-id-empresa="' . $id . '".*?<\/tr>/s', $html, $m) === 1 ? $m[0] : '';
    $lE = $linhaEmp($corpoEL, $idE);
    afirmar('linha de empresa ATIVA: Editar, Inativar (form-empresa-inativar-<id>) e Excluir por ULTIMO, com aria-label e csrf', str_contains($lE, 'id="btn-empresa-editar-' . $idE . '"') && str_contains($lE, 'id="form-empresa-inativar-' . $idE . '"') && str_contains($lE, 'id="btn-empresa-inativar-' . $idE . '"') && str_contains($lE, 'id="form-empresa-excluir-' . $idE . '"') && str_contains($lE, 'id="btn-empresa-excluir-' . $idE . '"') && strpos($lE, 'btn-empresa-excluir-') > strpos($lE, 'btn-empresa-inativar-') && str_contains($lE, 'aria-label="Inativar a empresa Mauá Três"') && str_contains($lE, 'name="csrf_token"') && str_contains($lE, 'name="id_empresa" value="' . $idE . '"') && str_contains($lE, 'gestao-acoes-grupo--destrutivo'));
    // inativar empresa: 2 passos com N totens
    // so TOTEM-B esta inativo e TOTEM-A tambem (desativado antes): reativa 1 para ter N=1
    $pdo->prepare('UPDATE tb_totem SET ativo = 1 WHERE id_totem = :i')->execute(['i' => $t1]);
    $pEi1 = $post('empresa-acao.php', $lAdm, ['acao' => 'inativar', 'id_empresa' => (string) $idE]);
    $pEic = $get('empresas.php', $lAdm, ['confirmar' => 'inativar', 'id' => (string) $idE]);
    $posC = strpos($pEic['corpo'], 'id="empresa-confirmacao-cancelar"');
    $posB = strpos($pEic['corpo'], 'id="btn-empresa-confirmar"');
    afirmar('inativar empresa 1o passo: #empresa-confirmacao[data-acao=inativar] com "1 totem(ns) ativo(s) vao deixar de concluir o check-in no Talent enquanto a empresa estiver inativa.", Cancelar antes do destrutivo', loc($pEi1) === '/gestao/empresas.php?confirmar=inativar&id=' . $idE . '&msg=empresa_confirmacao_necessaria' && str_contains($pEic['corpo'], 'id="empresa-confirmacao"') && str_contains($pEic['corpo'], 'data-acao="inativar"') && str_contains($pEic['corpo'], '1 totem(ns) ativo(s) vão deixar de concluir o check-in no Talent enquanto a empresa estiver inativa.') && $posC !== false && $posB !== false && $posC < $posB && str_contains($pEic['corpo'], 'name="confirmar" value="1"'));
    $snapTH = $snapTotens();
    $pEi2 = $post('empresa-acao.php', $lAdm, ['acao' => 'inativar', 'id_empresa' => (string) $idE, 'confirmar' => '1']);
    afirmar('inativar empresa 2o passo: 302 empresa_inativada; ativo=0; totens intactos; EmpresaDao (Talent) nao a enxerga mais', loc($pEi2) === '/gestao/empresas.php?msg=empresa_inativada' && (int) $linhasEmp($idE)['ativo'] === 0 && $snapTotens() === $snapTH && $empDao->buscarPorId($idE) === null);
    $linhaEI = $linhaEmp($get('empresas.php', $lAdm)['corpo'], $idE);
    afirmar('linha de empresa INATIVA: Ativar no lugar de Inativar; data-ativo=0; texto Inativa', str_contains($linhaEI, 'id="btn-empresa-ativar-' . $idE . '"') && str_contains($linhaEI, 'id="form-empresa-ativar-' . $idE . '"') && !str_contains($linhaEI, 'btn-empresa-inativar-') && str_contains($linhaEI, 'data-ativo="0"') && str_contains($linhaEI, '<span>Inativa</span>'));
    $pEa = $post('empresa-acao.php', $lAdm, ['acao' => 'ativar', 'id_empresa' => (string) $idE]);
    afirmar('ativar empresa (HTTP): direto, 302 empresa_ativada', loc($pEa) === '/gestao/empresas.php?msg=empresa_ativada' && (int) $linhasEmp($idE)['ativo'] === 1);
    $eSem = (int) $rnE->criar($idAdmin, 'SemTotem', cnpjValido('740000000001'))['id'];
    $pEi3 = $post('empresa-acao.php', $lAdm, ['acao' => 'inativar', 'id_empresa' => (string) $eSem]);
    afirmar('inativar empresa SEM totem ativo (HTTP): direto (302 empresa_inativada), sem confirmacao', loc($pEi3) === '/gestao/empresas.php?msg=empresa_inativada');
    // excluir empresa
    $pEx0 = $post('empresa-acao.php', $lAdm, ['acao' => 'excluir', 'id_empresa' => (string) $idE, 'confirmar' => '1']);
    afirmar('excluir empresa COM totens (HTTP, mesmo confirmando): 302 empresa_com_totens ("Remova ou mova os totens...") e nada muda', loc($pEx0) === '/gestao/empresas.php?msg=empresa_com_totens' && $linhasEmp($idE) !== [] && str_contains(App\Controller\GestaoContexto::MENSAGENS['empresa_com_totens'][1], 'Remova ou mova os totens desta empresa antes de excluir.'));
    $pEx1 = $post('empresa-acao.php', $lAdm, ['acao' => 'excluir', 'id_empresa' => (string) $eSem]);
    afirmar('excluir empresa sem totem (HTTP) 1o passo: 302 para a confirmacao; empresa existe', loc($pEx1) === '/gestao/empresas.php?confirmar=excluir&id=' . $eSem . '&msg=empresa_confirmacao_necessaria' && $linhasEmp($eSem) !== []);
    $pExc = $get('empresas.php', $lAdm, ['confirmar' => 'excluir', 'id' => (string) $eSem]);
    afirmar('excluir empresa 1o passo: bloco com aviso de exclusao definitiva e data-acao=excluir', str_contains($pExc['corpo'], 'data-acao="excluir"') && str_contains($pExc['corpo'], 'Esta ação exclui a empresa de forma definitiva.'));
    $pExcT = $get('empresas.php', $lAdm, ['confirmar' => 'excluir', 'id' => (string) $idE]);
    afirmar('confirmar=excluir de empresa COM totens: sem bloco (nao se pede confirmacao de algo recusado)', !str_contains($pExcT['corpo'], 'id="empresa-confirmacao"'));
    $pEx2 = $post('empresa-acao.php', $lAdm, ['acao' => 'excluir', 'id_empresa' => (string) $eSem, 'confirmar' => '1']);
    $pEx3 = $post('empresa-acao.php', $lAdm, ['acao' => 'excluir', 'id_empresa' => (string) $eSem, 'confirmar' => '1']);
    afirmar('excluir empresa 2o passo: 302 empresa_excluida; repetir => empresa_nao_encontrada (idempotente, sem 500)', loc($pEx2) === '/gestao/empresas.php?msg=empresa_excluida' && $linhasEmp($eSem) === [] && loc($pEx3) === '/gestao/empresas.php?msg=empresa_nao_encontrada');
    // criar/editar empresa
    $pEB = $get('empresa-form.php', $lAdm);
    afirmar('formulario de nova empresa: ids de contrato; sem aviso de URLs (so na edicao); cnpj editavel', str_contains($pEB['corpo'], 'id="form-empresa"') && str_contains($pEB['corpo'], 'id="empresa-nome"') && str_contains($pEB['corpo'], 'id="empresa-cnpj" name="cnpj"') && str_contains($pEB['corpo'], 'id="btn-salvar-empresa"') && !str_contains($pEB['corpo'], 'id="empresa-aviso-urls"') && str_contains($pEB['corpo'], '<title>Nova empresa - Gestão Totem</title>'));
    $pEE = $get('empresa-form.php', $lAdm, ['id' => (string) $idE]);
    afirmar('formulario de edicao de empresa: aviso PERMANENTE (#empresa-aviso-urls) com o texto exato, CNPJ somente leitura sem name, resumo de totens', str_contains($pEE['corpo'], 'id="empresa-aviso-urls"') && str_contains($pEE['corpo'], 'As URLs dos totens já criados continuam com o nome anterior da empresa até serem regeradas.') && preg_match('/id="empresa-cnpj"[^>]*\sreadonly/', $pEE['corpo']) === 1 && preg_match('/id="empresa-cnpj"[^>]*\sname=/', $pEE['corpo']) !== 1 && str_contains($pEE['corpo'], 'Totens desta empresa: 2 no total, 1 ativo(s).') && str_contains($pEE['corpo'], '<title>Editar empresa - Gestão Totem</title>'));
    $nEmpAntes = $nEmp();
    $pEC = $post('empresa-form.php', $lAdm, ['nome' => 'Http Empresa', 'cnpj' => mascarar(cnpjValido('750000000001'))]);
    afirmar('criar empresa (HTTP): 302 empresa_criada; CNPJ so digitos', loc($pEC) === '/gestao/empresas.php?msg=empresa_criada' && $nEmp() === $nEmpAntes + 1 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_empresa WHERE cnpj = :c', ['c' => cnpjValido('750000000001')]) === 1);
    $pECd = $post('empresa-form.php', $lAdm, ['nome' => 'Http Empresa 2', 'cnpj' => cnpjValido('750000000001')]);
    afirmar('criar empresa (HTTP): CNPJ duplicado => 422 no campo CNPJ', $pECd['status'] === 422 && str_contains($pECd['corpo'], 'id="erro-empresa-cnpj"') && $nEmp() === $nEmpAntes + 1);
    foreach ([['nome' => '***', 'cnpj' => cnpjValido('750000000002')], ['nome' => 'Nome Muito Longo Demais Para Slug', 'cnpj' => cnpjValido('750000000002')], ['nome' => '😀', 'cnpj' => cnpjValido('750000000002')], ['nome' => "A\0B", 'cnpj' => cnpjValido('750000000002')], ['nome' => 'Valida', 'cnpj' => '12']] as $f) {
        $pv = $post('empresa-form.php', $lAdm, $f);
        afirmar('criar empresa (HTTP) invalida ' . json_encode(array_map(static fn ($v) => mb_substr($v, 0, 14), $f), JSON_UNESCAPED_UNICODE) . ' => 422 com erro no campo e nada criado', $pv['status'] === 422 && (str_contains($pv['corpo'], 'id="erro-empresa-nome"') || str_contains($pv['corpo'], 'id="erro-empresa-cnpj"')) && $nEmp() === $nEmpAntes + 1);
    }
    $pEs = $post('empresa-form.php', $lAdm, ['nome' => '***', 'cnpj' => cnpjValido('750000000002')]);
    afirmar('criar empresa (HTTP): mensagem do slug invalido e clara', str_contains($pEs['corpo'], '1 a 16 letras ou números') && str_contains($pEs['corpo'], 'Ajuste o nome'));
    $pEd1 = $post('empresa-form.php', $lAdm, ['id_empresa' => (string) $idE, 'nome' => 'Mauá Quatro', 'cnpj' => cnpjValido('750000000009')]);
    afirmar('editar empresa (HTTP): renomeia; o CNPJ enviado no POST e IGNORADO (imutavel); codigos dos totens nao mudam', loc($pEd1) === '/gestao/empresas.php?msg=empresa_editada' && $linhasEmp($idE)['nome'] === 'Mauá Quatro' && $linhasEmp($idE)['cnpj'] === cnpjValido('121212120001') && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_totem WHERE codigo IN ('TOTEM-A-MAUAIII-AAAAAAAAAAAAAAAA','TOTEM-B-MAUAIII-BBBBBBBBBBBBBBBB')") === 2);
    $pEd2 = $post('empresa-form.php', $lAdm, ['id_empresa' => (string) $idE, 'nome' => '***']);
    afirmar('editar empresa (HTTP) com slug invalido: 422, aviso permanente continua visivel e nada muda', $pEd2['status'] === 422 && str_contains($pEd2['corpo'], 'id="erro-empresa-nome"') && str_contains($pEd2['corpo'], 'id="empresa-aviso-urls"') && $linhasEmp($idE)['nome'] === 'Mauá Quatro');
    $pEd3 = $post('empresa-form.php', $lAdm, ['id_empresa' => '987654', 'nome' => 'Fantasma']);
    afirmar('editar empresa inexistente (HTTP): 302 empresa_nao_encontrada', loc($pEd3) === '/gestao/empresas.php?msg=empresa_nao_encontrada');
    // XSS empresa
    $eHostil = $rnE->criar($idAdmin, '<i>"A\'&', cnpjValido('760000000001'));
    $pEX = $get('empresas.php', $lAdm);
    afirmar('XSS: empresa com <i>"A\'& (se aceita pela regra do slug) sai ESCAPADA na lista; a regra do slug nunca deixa passar sem escape', ($eHostil['ok'] === false) || (str_contains($pEX['corpo'], '&lt;i&gt;&quot;A&#039;&amp;') && !str_contains($pEX['corpo'], '<i>"A')));
    $pEXe = $get('empresa-form.php', $lAdm, ['id' => (string) ($eHostil['id'] ?? $idE)]);
    afirmar('XSS: formulario de edicao de empresa escapa o nome', !str_contains($pEXe['corpo'], '<i>"A') && !str_contains($pEXe['corpo'], 'onerror='));

    // --- menu, sem inline, acentuacao, no-store
    $pMenu = $get('clientes.php', $lAdm);
    afirmar('menu do admin tem Clientes e Empresas (links, item atual marcado)', str_contains($pMenu['corpo'], 'href="/gestao/clientes.php"') && str_contains($pMenu['corpo'], 'href="/gestao/empresas.php"') && preg_match('#<a class="gestao-menu__link" href="/gestao/clientes\.php"[^>]*aria-current="page"#', $pMenu['corpo']) === 1 && str_contains($pMenu['corpo'], 'i-clientes') && str_contains($get('empresas.php', $lAdm)['corpo'], 'i-empresas'));
    $pOrdUsu = $get('ordens.php', $lUsu);
    afirmar('menu do usuario comum NAO tem Clientes nem Empresas', !str_contains($pOrdUsu['corpo'], '/gestao/clientes.php') && !str_contains($pOrdUsu['corpo'], '/gestao/empresas.php'));
    $sprite = (string) file_get_contents($raiz . '/app/Views/gestao/_helpers.php');
    afirmar('sprite: icones clientes, empresas e excluir, nome [a-z-], traco 2px currentColor', str_contains($sprite, "'clientes' =>") && str_contains($sprite, "'empresas' =>") && str_contains($sprite, "'excluir' =>") && str_contains($sprite, 'stroke="currentColor" stroke-width="2"'));
    $paginas = ['clientes' => $get('clientes.php', $lAdm), 'clientes-confirm' => $pXc, 'cliente-form' => $pBlank, 'cliente-form-edit' => $pEd, 'empresas' => $pEL, 'empresas-confirm' => $pEic, 'empresa-form' => $pEB, 'empresa-form-edit' => $pEE, 'clientes-422' => $pXX];
    foreach ($paginas as $nomePag => $rp) {
        $c = $rp['corpo'];
        afirmar("pagina $nomePag: sem script/style inline nem on*= nem javascript:", preg_match('/<script\b(?![^>]*\bsrc=)/i', $c) !== 1 && preg_match('/<style\b|\sstyle\s*=|\son[a-z]+\s*=|javascript:/i', $c) !== 1);
        afirmar("pagina $nomePag: no-store, CSP e noindex", gtCabecalho($rp, 'cache-control') === 'no-store, private' && gtCabecalho($rp, 'content-security-policy') !== null && str_contains($c, 'noindex'));
        $txt = textoVisivel($c);
        afirmar("pagina $nomePag: texto visivel acentuado (sem formas comuns sem acento)", preg_match('/\b(voce|pagina|acao|excluido|invalido|obrigatorio|confirmacao|situacao|codigo|tambem|nao ha)\b/i', $txt) !== 1);
    }
    $todos = implode("\n", array_map(static fn ($p) => $p['corpo'], $paginas));
    afirmar('nenhuma pagina nova mostra codigo, token ou URL de totem nem hash de sessao', !str_contains($todos, $codTotemFix) && !str_contains($todos, $tokTotemFix) && !str_contains($todos, '?totem=') && !str_contains($todos, 'token_api'));
    $logTxt = (string) @file_get_contents($log);
    afirmar('log do PHP das paginas: sem SQLSTATE, sem nomes/CNPJ de clientes e sem getMessage', !str_contains($logTxt, 'SQLSTATE') && !str_contains($logTxt, 'Acme') && !str_contains($logTxt, $cHttp) && !str_contains($logTxt, 'Fatal error'));

    // =====================================================================
    // G. Varredura de fonte
    // =====================================================================
    $arqs = ['app/Rn/CadastroGestaoBase.php', 'app/Rn/ClienteGestaoRn.php', 'app/Rn/EmpresaGestaoRn.php', 'app/Dao/ClienteGestaoDao.php', 'app/Dao/EmpresaGestaoDao.php', 'app/Controller/GestaoClienteController.php', 'app/Controller/GestaoEmpresaController.php', 'util/NomeCadastro.php'];
    $fonte = '';
    foreach ($arqs as $a) {
        $fonte .= codigoSemComentarios($raiz . '/' . $a) . "\n";
    }
    afirmar('fonte: nenhum getMessage/getTraceAsString/var_dump/print_r nos arquivos novos', preg_match('/getMessage|getTrace|var_dump|print_r|var_export/', $fonte) !== 1);
    $daos = codigoSemComentarios($raiz . '/app/Dao/ClienteGestaoDao.php') . codigoSemComentarios($raiz . '/app/Dao/EmpresaGestaoDao.php');
    afirmar('fonte: DAOs usam prepared statements (nenhum valor concatenado: sem $_GET/$_POST, sem "." com variavel dentro de SQL alem de WHERE/FOR UPDATE montados de constantes)', !str_contains($daos, '$_GET') && !str_contains($daos, '$_POST') && !str_contains($daos, '->exec(') && substr_count($daos, 'prepare(') >= 14);
    afirmar('fonte: UPDATE de tb_cliente/tb_empresa NUNCA altera o cnpj', preg_match('/UPDATE\s+tb_(cliente|empresa)\s+SET[^\'"]*\bcnpj\b/i', $daos) !== 1);
    afirmar('fonte: so os 2 DAOs novos apagam (DELETE), e so de tb_cliente/tb_empresa; nenhuma escrita em tb_atendimento, tb_atendimento_nota ou tb_totem nos arquivos novos', preg_match_all('/DELETE\s+FROM\s+(\w+)/i', $daos, $mDel) === 2 && $mDel[1] === ['tb_cliente', 'tb_empresa'] && preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(tb_atendimento|tb_atendimento_nota|tb_totem)\b/i', $fonte) !== 1);
    afirmar('fonte: nenhum acesso ao banco externo de coletas pelos arquivos novos', !str_contains($fonte, 'ConexaoGestaoColetas') && !str_contains($fonte, 'GESTAO_COLETAS'));
    afirmar('fonte: Rn usa RazaoSocialMatcher::normalizar, CnpjValidador::ehUdlog e TotemCodigo::empresa; ESCAPE do LIKE no DAO', str_contains($fonte, 'RazaoSocialMatcher::normalizar') && str_contains($fonte, 'CnpjValidador::ehUdlog') && str_contains($fonte, 'TotemCodigo::empresa') && str_contains($daos, "ESCAPE \'|\'"));
    afirmar('fonte: so admin (as 6 paginas exigem perfil admin) e metodos restritos', (static function () use ($raiz): bool {
        foreach (['clientes' => "['GET']", 'cliente-form' => "['GET', 'POST']", 'cliente-acao' => "['POST']", 'empresas' => "['GET']", 'empresa-form' => "['GET', 'POST']", 'empresa-acao' => "['POST']"] as $arq => $metodos) {
            $c = (string) file_get_contents($raiz . '/public/gestao/' . $arq . '.php');
            if (!str_contains($c, "'admin', ['metodos' => " . $metodos . ']')) {
                return false;
            }
        }

        return true;
    })());
    afirmar('fonte: views usam h() em toda saida de dado (sem echo cru de variavel que nao seja inteiro/helper)', (static function () use ($raiz): bool {
        foreach (['clientes', 'cliente-form', 'empresas', 'empresa-form'] as $v) {
            $c = codigoSemComentarios($raiz . '/app/Views/gestao/' . $v . '.php');
            if (preg_match('/<\?=\s*\$(?!ativo\b|idC\b|idE\b|nAtivos\b|nTotal\b)[a-zA-Z_]+\s*\?>/', $c) === 1) {
                return false;
            }
        }

        return true;
    })());
    afirmar('fonte: bootstrap QA aplica o schema atual (tb_cliente e tb_empresa existem no banco QA)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tb_cliente','tb_empresa')") === 2);
    $pdo->exec('DROP TABLE IF EXISTS qa_ordem');
} finally {
    foreach ($bancos as $b) {
        try {
            qaQrDroparBanco($b);
        } catch (Throwable $e) {
        }
    }
    gtDestruirAmbiente(null, $storage);
    @unlink($log);
}

exit(gtResumo('teste_gestao_cadastros'));
