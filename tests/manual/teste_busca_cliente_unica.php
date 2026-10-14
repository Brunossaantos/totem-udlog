<?php

/**
 * Busca manual de cliente no Recebimento: a rota so devolve UM cliente e apenas
 * quando o texto digitado tem uma unica correspondencia entre os clientes
 * ATIVOS (0 ou mais de 1 => nenhum). Banco `qa_` descartavel, dados ficticios,
 * sem rede. Cobre ClienteDao::buscarUnicoPorTermo e a rota real
 * public/api/cliente.php via php-cgi.
 *
 * Uso: php tests/manual/teste_busca_cliente_unica.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_db_bootstrap.php';

use App\Dao\ClienteDao;

$total = 0;
$falhas = 0;
function afirmar(string $d, bool $c): void
{
    global $total, $falhas;
    $total++;
    echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n";
    if (!$c) {
        $falhas++;
    }
}

$cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
if (!is_file($cgi)) {
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi';
}
if (!is_file($cgi)) {
    fwrite(STDERR, "php-cgi nao encontrado; abortando.\n");
    exit(2);
}
$raiz = dirname(__DIR__, 2);
$rota = $raiz . '/public/api/cliente.php';

$prepend = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'qa_busca_cli_prepend_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($prepend, <<<'PHP'
<?php
$qaNome = getenv('QA_BUSCA_DB');
if ($qaNome === false || preg_match('/\Aqa_[a-z0-9_]+\z/', $qaNome) !== 1) {
    fwrite(STDERR, "prepend: banco QA ausente ou invalido\n");
    exit(3);
}
$_ENV['DB_NAME'] = $qaNome;
$_SERVER['DB_NAME'] = $qaNome;
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $h[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = $v;
            }
        }
        return $h;
    }
}
PHP);

function chamar(string $cgi, string $raiz, string $rota, string $prepend, string $banco, string $query, ?string $token): array
{
    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => 'GET',
        'SCRIPT_FILENAME' => $rota,
        'SCRIPT_NAME' => '/api/cliente.php',
        'QUERY_STRING' => $query,
        'REMOTE_ADDR' => '192.0.2.10',
        'QA_BUSCA_DB' => $banco,
        'SystemRoot' => (string) getenv('SystemRoot'),
    ];
    if ($token !== null) {
        $env['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    $cmd = [$cgi, '-q', '-d', 'auto_prepend_file=' . $prepend, '-d', 'display_errors=0'];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz . '/public/api', $env);
    fclose($pipes[0]);
    $saida = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $status = 200;
    if (preg_match('/^Status:\s*(\d{3})/mi', $saida, $m) === 1) {
        $status = (int) $m[1];
    }
    $partes = preg_split("/\r?\n\r?\n/", $saida, 2);
    $json = json_decode($partes[1] ?? '', true);

    return ['status' => $status, 'corpo' => $partes[1] ?? '', 'json' => is_array($json) ? $json : null];
}

$banco = null;
try {
    [$pdo, $banco] = qaDbCriar('busca_cliente_unica');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $ins = $pdo->prepare('INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:n, :r, :c, :a)');
    foreach ([
        ['Alfa Tintas Ltda', '12345670000101', 1],
        ['Beta Quimica Ltda', '12345671000102', 1],
        ['Cromex Industria Ltda', '12345677000103', 1],
        ['Cromix Comercio Ltda', '99887761000104', 1],
        ['Fantasma Comercio Ltda', '55555555000105', 0],
        ['Agata Impar Ltda', '77777777000106', 1],
        ['Gamma Velho Ltda', '66666660000107', 0],
        ['Gamma Novo Ltda', '66666661000108', 1],
    ] as [$nome, $cnpj, $ativo]) {
        $ins->execute(['n' => $nome, 'r' => strtoupper($nome), 'c' => $cnpj, 'a' => $ativo]);
    }
    $token = 'qa_' . bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 1)')
        ->execute(['c' => 'QA-BUSCA', 'n' => 'Totem QA busca', 't' => $token]);

    $dao = new ClienteDao($pdo);
    $cnpjDe = static fn (?array $c): ?string => $c['cnpj'] ?? null;

    echo "--- DAO ---\n";
    afirmar('1. prefixo de CNPJ ambiguo (1234567) => nenhuma', $dao->buscarUnicoPorTermo('1234567') === null);
    afirmar('2a. prefixo de CNPJ unico (12345677) => Cromex', $cnpjDe($dao->buscarUnicoPorTermo('12345677')) === '12345677000103');
    afirmar('2b. CNPJ com pontuacao (12.345.677/0001-03) => Cromex', $cnpjDe($dao->buscarUnicoPorTermo('12.345.677/0001-03')) === '12345677000103');
    afirmar('2c. CNPJ com espacos e pontuacao parcial (12 345.677/0) => Cromex', $cnpjDe($dao->buscarUnicoPorTermo(' 12 345.677/0 ')) === '12345677000103');
    afirmar('2d. digitos no meio do CNPJ (45677) nao casam: so prefixo => nenhuma', $dao->buscarUnicoPorTermo('45677') === null);
    afirmar('3. nome ambiguo (crom: Cromix e Cromex) => nenhuma', $dao->buscarUnicoPorTermo('crom') === null);
    afirmar('4a. nome unico (crome) => Cromex', $cnpjDe($dao->buscarUnicoPorTermo('crome')) === '12345677000103');
    afirmar('4b. nome unico com acento, caixa e espacos extras => Cromex', $cnpjDe($dao->buscarUnicoPorTermo("  CRÓME  ")) === '12345677000103');
    afirmar('4c. nome do cliente com acento digitado com espacos repetidos (agata   IMPAR)', $cnpjDe($dao->buscarUnicoPorTermo('ÁGATA   impar')) === '77777777000106');
    afirmar('4d. contem no meio do nome (tintas) => Alfa', $cnpjDe($dao->buscarUnicoPorTermo('tintas')) === '12345670000101');
    afirmar('5a. cliente inativo por nome (fantasma) => nenhuma', $dao->buscarUnicoPorTermo('fantasma') === null);
    afirmar('5b. cliente inativo por CNPJ (55555555) => nenhuma', $dao->buscarUnicoPorTermo('55555555') === null);
    afirmar('5c. inexistente por nome e por CNPJ => nenhuma', $dao->buscarUnicoPorTermo('zzzzz') === null && $dao->buscarUnicoPorTermo('99999999') === null);
    afirmar('5d. inativo nao gera ambiguidade (gamma => so o ativo)', $cnpjDe($dao->buscarUnicoPorTermo('gamma')) === '66666661000108');
    afirmar('6. menos de 3 caracteres => nenhuma', $dao->buscarUnicoPorTermo('cr') === null && $dao->buscarUnicoPorTermo('  a ') === null && $dao->buscarUnicoPorTermo('') === null);
    $u = $dao->buscarUnicoPorTermo('crome');
    afirmar('7. DAO devolve somente id_cliente, nome e cnpj', is_array($u) && array_keys($u) === ['id_cliente', 'nome', 'cnpj']);

    echo "--- HTTP ---\n";
    $http = static fn (string $q, ?string $t = null) => chamar($GLOBALS['cgi'], $GLOBALS['raiz'], $GLOBALS['rota'], $GLOBALS['prepend'], $GLOBALS['banco'], $q, $t ?? $GLOBALS['token']);

    $r = $http('acao=buscar&termo=crome');
    $cli = $r['json']['dados']['clientes'] ?? null;
    afirmar('HTTP unico: 200, sucesso e exatamente 1 item', $r['status'] === 200 && ($r['json']['sucesso'] ?? null) === true && is_array($cli) && count($cli) === 1);
    afirmar('HTTP unico: somente nome e cnpj', is_array($cli) && array_keys($cli[0]) === ['nome', 'cnpj'] && $cli[0]['nome'] === 'Cromex Industria Ltda' && $cli[0]['cnpj'] === '12345677000103');
    afirmar('HTTP unico: sem id_cliente/ativo/razao no corpo', !str_contains($r['corpo'], 'id_cliente') && !str_contains($r['corpo'], 'ativo') && !str_contains($r['corpo'], 'razao'));
    $r = $http('acao=buscar&termo=' . rawurlencode('12.345.677/0001-03'));
    afirmar('HTTP CNPJ pontuado => 1 item Cromex', count($r['json']['dados']['clientes'] ?? []) === 1 && $r['json']['dados']['clientes'][0]['cnpj'] === '12345677000103');
    $r = $http('acao=buscar&termo=crom');
    afirmar('HTTP nome ambiguo => clientes []', $r['status'] === 200 && ($r['json']['dados']['clientes'] ?? null) === []);
    $r = $http('acao=buscar&termo=1234567');
    afirmar('HTTP prefixo ambiguo => clientes []', ($r['json']['dados']['clientes'] ?? null) === []);
    $r = $http('acao=buscar&termo=fantasma');
    afirmar('HTTP inativo => clientes []', ($r['json']['dados']['clientes'] ?? null) === []);
    $r = $http('acao=buscar&termo=cr');
    afirmar('HTTP termo < 3 => clientes []', $r['status'] === 200 && ($r['json']['dados']['clientes'] ?? null) === []);
    $r = $http('acao=buscar&termo[]=crome');
    afirmar('HTTP termo array => clientes []', $r['status'] === 200 && ($r['json']['dados']['clientes'] ?? null) === []);
    $r = $http('acao=buscar&termo=crome', 'token-invalido');
    afirmar('HTTP token invalido => 401', $r['status'] === 401 && ($r['json']['sucesso'] ?? null) === false);
    $r = chamar($cgi, $raiz, $rota, $prepend, $banco, 'acao=buscar&termo=crome', null);
    afirmar('HTTP sem token => 401', $r['status'] === 401);
    $r = $http('acao=outra');
    afirmar('HTTP acao invalida => 404', $r['status'] === 404);
} finally {
    @unlink($prepend);
    if ($banco !== null) {
        qaDbDropar($banco);
    }
}

echo "\nTotal: {$total} | Falhas: {$falhas}\n";
exit($falhas === 0 ? 0 : 1);
