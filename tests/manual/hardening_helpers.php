<?php

/**
 * Helpers compartilhados pelas suites da demanda
 * hardening-revisao-notas-e-cliente (2026-09-30, fase 2 backend):
 *   - tests/manual/teste_hardening_cliente.php
 *   - tests/manual/teste_hardening_concluir_422.php
 *   - tests/manual/teste_hardening_exclusao.php
 *   - tests/manual/teste_hardening_cron_quarentena.php
 *
 * Regras de seguranca destas suites (NAO negociaveis):
 *   - SO usam banco `qa_`-prefixado DESCARTAVEL criado por
 *     tests/manual/qa_db_bootstrap.php::qaDbCriar() (nunca o banco do .env)
 *     e STORAGE_PATH TEMPORARIO proprio (nunca o do .env);
 *   - os subprocessos recebem DB_NAME/STORAGE_PATH por ambiente (ponte
 *     explicita para $_ENV ANTES do Dotenv imutavel, ver
 *     qaDbTrechoPonteEnvSubprocesso) e verificam SELECT DATABASE() = qa_;
 *   - NUNCA chamam o Talent real (TalentClient aponta para um servidor
 *     `php -S` local com _router_talent_mock.php e contador de requisicoes),
 *     nunca imprimem, nunca chamam a VIO.
 *
 * Este arquivo NAO comeca com "_" de proposito: fica versionado (o
 * .gitignore ignora tests/manual/_*.php), tornando as suites reproduziveis
 * a partir de um clone limpo.
 */

require_once __DIR__ . '/qa_db_bootstrap.php';

$GLOBALS['hd_total'] = 0;
$GLOBALS['hd_falhas'] = 0;
$GLOBALS['hd_temporarios'] = [];

function hdAfirmar(string $descricao, bool $condicao): void
{
    $GLOBALS['hd_total']++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) {
        $GLOBALS['hd_falhas']++;
    }
}

function hdSecao(string $titulo): void
{
    echo "\n=== {$titulo} ===\n";
}

function hdEncerrar(string $nomeSuite): void
{
    echo "\n{$nomeSuite}: {$GLOBALS['hd_total']} verificacoes, {$GLOBALS['hd_falhas']} falha(s)\n";
    exit($GLOBALS['hd_falhas'] > 0 ? 1 : 0);
}

// ============================================================
// Ambiente (banco qa_ + STORAGE_PATH temporario)
// ============================================================

/**
 * @return array{pdo: PDO, banco: string, storage: string, dir_tmp: string}
 */
function hdCriarAmbiente(string $prefixo): array
{
    [$pdo, $banco] = qaDbCriar($prefixo);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $atual = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (strpos((string) $atual, 'qa_') !== 0) {
        throw new \RuntimeException('Ambiente de teste sem banco qa_ -- abortando');
    }

    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totem_hd_' . bin2hex(random_bytes(6));
    mkdir($storage, 0777, true);

    return ['pdo' => $pdo, 'banco' => $banco, 'storage' => $storage, 'dir_tmp' => $storage . '_tmp'];
}

function hdDestruirAmbiente(array $amb): void
{
    hdRemoverDiretorio($amb['storage']);
    hdRemoverDiretorio($amb['dir_tmp']);
    try {
        qaDbDropar($amb['banco']);
    } catch (\Throwable $e) {
        echo "AVISO: falha ao dropar {$amb['banco']}\n";
    }
    foreach ($GLOBALS['hd_temporarios'] as $arquivo) {
        @unlink($arquivo);
    }
}

function hdRemoverDiretorio(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $itens = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($itens as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } else {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($dir);
}

// ============================================================
// Fixtures
// ============================================================

function hdCriarTotem(PDO $pdo, string $codigo): int
{
    $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:c, :n, :t, 1)')
        ->execute(['c' => $codigo, 'n' => 'Totem ' . $codigo, 't' => 'token_' . bin2hex(random_bytes(8))]);

    return (int) $pdo->lastInsertId();
}

/**
 * Atendimento de recebimento com pasta_documentos VALIDA (formato real de
 * UploadHelper::montarPasta) e diretorio criado no STORAGE temporario.
 *
 * @return array{id: int, pasta: string, dir: string}
 */
function hdCriarAtendimento(
    array $amb,
    int $idTotem,
    string $placa = 'HDT1A23',
    string $status = 'em_andamento',
    string $etapa = 'digitalizacao_notas',
    string $tipo = 'recebimento'
): array {
    static $sequencia = 0;
    $sequencia++;
    $pasta = date('Y-m-d') . '/' . $placa . '_' . str_pad((string) ($sequencia % 1000000), 6, '0', STR_PAD_LEFT);

    $pdo = $amb['pdo'];
    $pdo->prepare('
        INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, etapa_atual, placa, pasta_documentos)
        VALUES (:codigo, :totem, :tipo, :status, :etapa, :placa, :pasta)
    ')->execute([
        'codigo' => bin2hex(random_bytes(16)) . substr(bin2hex(random_bytes(2)), 0, 4),
        'totem'  => $idTotem,
        'tipo'   => $tipo,
        'status' => $status,
        'etapa'  => $etapa,
        'placa'  => $placa,
        'pasta'  => $pasta,
    ]);
    $id = (int) $pdo->lastInsertId();

    $dir = $amb['storage'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pasta);
    mkdir($dir, 0777, true);

    return ['id' => $id, 'pasta' => $pasta, 'dir' => $dir];
}

function hdAtendimento(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM tb_atendimento WHERE id_atendimento = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->fetch() ?: [];
}

/** CNPJ valido (14 digitos, DVs corretos) derivado de uma semente. */
function hdCnpj(int $semente): string
{
    $base = str_pad((string) (10000000 + ($semente * 7919) % 89999999), 8, '0', STR_PAD_LEFT) . '0001';
    $calc = static function (string $digitos, array $pesos): int {
        $soma = 0;
        foreach (str_split($digitos) as $i => $d) {
            $soma += (int) $d * $pesos[$i];
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    };
    $dv1 = $calc($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    $dv2 = $calc($base . $dv1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

    return $base . $dv1 . $dv2;
}

function hdCriarCliente(PDO $pdo, string $nome, string $cnpj, int $ativo = 1, ?string $razaoNormalizada = null): int
{
    $pdo->prepare('
        INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo)
        VALUES (:nome, :razao, :cnpj, :ativo)
    ')->execute(['nome' => $nome, 'razao' => $razaoNormalizada ?? strtoupper($nome), 'cnpj' => $cnpj, 'ativo' => $ativo]);

    return (int) $pdo->lastInsertId();
}

/** JPEG valido distinguivel por semente (cor unica) -> bytes crus. */
function hdJpegBytes(int $semente): string
{
    $img = imagecreatetruecolor(48, 32);
    imagefill($img, 0, 0, imagecolorallocate($img, ($semente * 37) % 256, ($semente * 91) % 256, ($semente * 53) % 256));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function hdJpegDataUrl(int $semente): string
{
    return 'data:image/jpeg;base64,' . base64_encode(hdJpegBytes($semente));
}

/**
 * Nota de teste: linha em tb_atendimento_nota + (opcional) arquivo fisico
 * nota_NN.jpg com JPEG distinguivel na pasta do atendimento.
 *
 * @param array{id:int, pasta:string, dir:string} $at
 * @return int id_nota
 */
function hdCriarNota(
    PDO $pdo,
    array $at,
    int $ordem,
    ?string $numero = null,
    string $statusOcr = 'PENDENTE',
    ?string $cnpjEmitente = null,
    bool $comArquivo = true
): int {
    $arquivo = sprintf('nota_%02d.jpg', $ordem);
    $pdo->prepare('
        INSERT INTO tb_atendimento_nota (id_atendimento, ordem, arquivo, numero_nota, numero_nota_origem, cnpj_emitente, cliente_identificado, status_ocr)
        VALUES (:id, :ordem, :arquivo, :numero, :origem, :cnpj, :ident, :status)
    ')->execute([
        'id'      => $at['id'],
        'ordem'   => $ordem,
        'arquivo' => $arquivo,
        'numero'  => $numero,
        'origem'  => $numero !== null ? 'MANUAL' : null,
        'cnpj'    => $cnpjEmitente,
        'ident'   => $statusOcr === 'IDENTIFICADA' ? 1 : 0,
        'status'  => $statusOcr,
    ]);
    $idNota = (int) $pdo->lastInsertId();

    if ($comArquivo) {
        file_put_contents($at['dir'] . DIRECTORY_SEPARATOR . $arquivo, hdJpegBytes($idNota));
    }

    return $idNota;
}

function hdNotas(PDO $pdo, int $idAtendimento): array
{
    $stmt = $pdo->prepare('SELECT * FROM tb_atendimento_nota WHERE id_atendimento = :id ORDER BY ordem');
    $stmt->execute(['id' => $idAtendimento]);

    return $stmt->fetchAll();
}

function hdArquivosDaPasta(array $at): array
{
    $itens = @scandir($at['dir']) ?: [];

    return array_values(array_filter($itens, static fn(string $n): bool => $n !== '.' && $n !== '..'));
}

// ============================================================
// Runner de rota em subprocesso (Resposta faz exit)
// ============================================================

function hdRaizProjeto(): string
{
    return dirname(__DIR__, 2);
}

function hdScriptRunner(): string
{
    static $caminho = null;
    if ($caminho !== null) {
        return $caminho;
    }

    $corpo = <<<'RUNNER'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\FilaEnvioDao;
use App\Dao\OrdemColetaDao;
use App\Dao\RateLimitOcrDao;
use App\Rn\AtendimentoRn;
use App\Rn\NotaFiscalRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Controller\AtendimentoController;
use App\Controller\NotaController;
use Util\NotaArquivoStorage;

%%PONTE%%

$spec = json_decode(file_get_contents($argv[1]), true);

foreach (($spec['env'] ?? []) as $k => $v) {
    $_ENV[$k] = $v;
}

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

// Log do PHP capturado em arquivo (nunca no terminal): as suites conferem
// que nenhum dado sensivel aparece nele.
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $spec['log']);

$pdo = Conexao::obter();
$banco = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (strpos($banco, 'qa_') !== 0) {
    fwrite(STDERR, "RUNNER_ABORTADO: banco sem prefixo qa_\n");
    exit(99);
}

class HdDaoDeleteFalha extends AtendimentoNotaDao
{
    public function excluirPorId(int $idNota, int $idAtendimento): int
    {
        throw new \PDOException('SQLSTATE[HY000]: SENTINELA_DELETE_SECRETA ' . $idNota);
    }
}
class HdDaoDeleteZero extends AtendimentoNotaDao
{
    public function excluirPorId(int $idNota, int $idAtendimento): int
    {
        return 0;
    }
}
class HdDaoInsertFalha extends AtendimentoNotaDao
{
    public function inserir(int $idAtendimento, int $ordem, string $arquivo, ?string $chave, ?string $cnpjEmitente, bool $clienteIdentificado, ?string $clientUid = null): int
    {
        throw new \RuntimeException('SENTINELA_INSERT_SECRETA');
    }
}
class HdAtendimentoDaoCommitFalha extends AtendimentoDao
{
    public function confirmarTransacao(): void
    {
        throw new \PDOException('SQLSTATE[HY000]: SENTINELA_COMMIT_SECRETA');
    }
}
class HdClienteDaoFalha extends ClienteDao
{
    public function buscarPorCnpj(string $cnpj): ?array
    {
        throw new \RuntimeException('SENTINELA_CLIENTE_CNPJ ' . $cnpj);
    }
    public function listarParaFuzzy(): array
    {
        throw new \RuntimeException('SENTINELA_CLIENTE_FUZZY');
    }
}
class HdStorageQuarentenaFalha extends NotaArquivoStorage
{
    public function quarentenar(string $caminho, int $idNota): string
    {
        throw new \RuntimeException('quarentena_falhou');
    }
}
class HdStorageRemoverFalha extends NotaArquivoStorage
{
    public function remover(string $caminho, int $idNota): bool
    {
        return false;
    }
}
// F4: falha REAL de restaurar(): a quarentena acontece de verdade (rename) e,
// em seguida, um DIRETORIO e criado no caminho original, o que faz o rename de
// volta (arquivo -> diretorio existente) falhar no proprio sistema de arquivos.
class HdStorageRestaurarBloqueada extends NotaArquivoStorage
{
    public function quarentenar(string $caminho, int $idNota): string
    {
        $situacao = parent::quarentenar($caminho, $idNota);
        if ($situacao === 'quarentenado') {
            mkdir($caminho);
        }

        return $situacao;
    }
}
// F4: restaurar() falha de verdade (nao devolve a foto); combinavel com
// dao_delete_falha (injecao com varios tokens separados por virgula).
class HdStorageRestaurarFalha extends NotaArquivoStorage
{
    public function restaurar(string $caminho, int $idNota): bool
    {
        return false;
    }
}

// injecao: um token ou VARIOS separados por virgula (ex.: dao_delete_falha,storage_restaurar_falha)
$injecoes = array_filter(explode(',', (string) ($spec['injecao'] ?? '')));
$tem = static fn(string $t): bool => in_array($t, $injecoes, true);

$notaDao = match (true) {
    $tem('dao_delete_falha') => new HdDaoDeleteFalha($pdo),
    $tem('dao_delete_zero')  => new HdDaoDeleteZero($pdo),
    $tem('dao_insert_falha') => new HdDaoInsertFalha($pdo),
    default                  => new AtendimentoNotaDao($pdo),
};
$clienteDao = $tem('cliente_falha') ? new HdClienteDaoFalha($pdo) : new ClienteDao($pdo);
$atendimentoDao = $tem('dao_commit_falha') ? new HdAtendimentoDaoCommitFalha($pdo) : new AtendimentoDao($pdo);
$storage = match (true) {
    $tem('storage_quarentenar_falha') => new HdStorageQuarentenaFalha(),
    $tem('storage_remover_falha')     => new HdStorageRemoverFalha(),
    $tem('storage_restaurar_falha')   => new HdStorageRestaurarFalha(),
    $tem('storage_restaurar_bloqueada') => new HdStorageRestaurarBloqueada(),
    default                           => new NotaArquivoStorage(),
};

$notaRn = new NotaFiscalRn($notaDao, $clienteDao);

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . (http_response_code() ?: 200) . "\n";
});

$idTotem = (int) $spec['totem'];
$entrada = $spec['entrada'] ?? [];

if (str_starts_with($spec['rota'], 'nota.')) {
    $controller = new NotaController($notaRn, $atendimentoDao, new RateLimitOcrDao($pdo), $storage);
    switch ($spec['rota']) {
        case 'nota.processar':   $controller->processar($entrada, $idTotem); break;
        case 'nota.identificar': $controller->identificarCliente($entrada, $idTotem); break;
        case 'nota.definir':     $controller->definirNumero($entrada, $idTotem); break;
        case 'nota.excluir':     $controller->excluir($entrada, $idTotem); break;
        case 'nota.status':      $controller->algumaIdentificada((int) ($entrada['id_atendimento'] ?? 0), $idTotem); break;
        case 'nota.listar':      $controller->listar((int) ($entrada['id_atendimento'] ?? 0), $idTotem); break;
    }
    exit;
}

$atendimentoRn = new AtendimentoRn($atendimentoDao, new OrdemColetaClient(new OrdemColetaDao()));
$talentRn = new TalentRn(
    new TalentClient($spec['talent_url'] ?? '', 'cenario_sucesso_200'),
    new FilaEnvioDao($pdo),
    new AtendimentoDao($pdo),
    $_ENV['STORAGE_PATH']
);
$controller = new AtendimentoController(
    $atendimentoRn, $talentRn, $notaDao,
    null, null, null, null, null, null, null,
    $notaRn, $clienteDao, $storage
);

switch ($spec['rota']) {
    case 'atendimento.concluir': $controller->concluirDigitalizacao($entrada, $idTotem); break;
    case 'atendimento.cancelar': $controller->cancelar($entrada, $idTotem); break;
    case 'atendimento.salvar':   $controller->salvarEtapa($entrada, $idTotem); break;
}
RUNNER;

    $corpo = str_replace('%%PONTE%%', qaDbTrechoPonteEnvSubprocesso(), $corpo);
    $caminho = qaGerarScriptTemporario($corpo, hdRaizProjeto(), 'hd_runner');
    $GLOBALS['hd_temporarios'][] = $caminho;

    return $caminho;
}

/**
 * Inicia a rota em subprocesso (nao bloqueia). $opcoes: injecao (string),
 * env (array extra de $_ENV do subprocesso), talent_url.
 *
 * @return array{proc: resource, pipes: array, log: string, spec: string}
 */
function hdIniciar(array $amb, string $rota, int $idTotem, array $entrada, array $opcoes = []): array
{
    // F1 (rodada corretiva 2026-10-01): concluir-digitalizacao agora so
    // conclui com TODAS as notas em estado terminal de OCR. As fixtures
    // antigas criam notas PENDENTE por padrao e assumem OCR ja concluido, entao,
    // salvo `sem_terminalizar` => true (testes de F1), as notas ainda
    // PENDENTE/PROCESSANDO do atendimento viram NAO_IDENTIFICADA (zero
    // correspondencias, a mesma semantica que o codigo antigo dava a elas)
    // imediatamente antes de a rota de concluir ser iniciada.
    if ($rota === 'atendimento.concluir' && !($opcoes['sem_terminalizar'] ?? false) && isset($entrada['id_atendimento'])) {
        $amb['pdo']->prepare("
            UPDATE tb_atendimento_nota SET status_ocr = 'NAO_IDENTIFICADA', processado_em = NOW()
            WHERE id_atendimento = :id AND status_ocr IN ('PENDENTE', 'PROCESSANDO')
        ")->execute(['id' => (int) $entrada['id_atendimento']]);
    }

    @mkdir($amb['dir_tmp'], 0777, true);
    $sufixo = bin2hex(random_bytes(6));
    $arquivoSpec = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . "spec_{$sufixo}.json";
    $arquivoLog = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . "log_{$sufixo}.txt";

    file_put_contents($arquivoSpec, json_encode([
        'rota'       => $rota,
        'totem'      => $idTotem,
        'entrada'    => $entrada,
        'injecao'    => $opcoes['injecao'] ?? null,
        'env'        => $opcoes['env'] ?? [],
        'log'        => $arquivoLog,
        'talent_url' => $opcoes['talent_url'] ?? null,
    ]));
    file_put_contents($arquivoLog, '');

    $ambiente = array_merge(getenv(), [
        'DB_NAME'      => $amb['banco'],
        'STORAGE_PATH' => $amb['storage'],
    ]);

    $proc = proc_open(
        [PHP_BINARY, hdScriptRunner(), $arquivoSpec],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $ambiente
    );

    return ['proc' => $proc, 'pipes' => $pipes, 'log' => $arquivoLog, 'spec' => $arquivoSpec];
}

/**
 * Aguarda o subprocesso e devolve http, corpo (array|null), stdout/stderr
 * brutos e o log do PHP daquele processo.
 *
 * @return array{http:int, corpo:?array, stdout:string, stderr:string, log:string}
 */
function hdAguardar(array $h): array
{
    $stdout = stream_get_contents($h['pipes'][1]);
    $stderr = stream_get_contents($h['pipes'][2]);
    fclose($h['pipes'][1]);
    fclose($h['pipes'][2]);
    proc_close($h['proc']);

    $http = 0;
    $json = $stdout;
    if (preg_match('/\nHTTP_CODE:(\d+)\s*$/', $stdout, $m)) {
        $http = (int) $m[1];
        $json = substr($stdout, 0, strrpos($stdout, "\nHTTP_CODE:"));
    }

    $log = (string) @file_get_contents($h['log']);
    $GLOBALS['hd_logs_coletados'][] = $log;
    @unlink($h['spec']);

    return [
        'http'   => $http,
        'corpo'  => json_decode(trim($json), true),
        'stdout' => $stdout,
        'stderr' => $stderr,
        'log'    => $log,
    ];
}

function hdRodar(array $amb, string $rota, int $idTotem, array $entrada, array $opcoes = []): array
{
    return hdAguardar(hdIniciar($amb, $rota, $idTotem, $entrada, $opcoes));
}

/**
 * Dispara varias chamadas quase ao mesmo tempo e aguarda todas.
 *
 * @param array<int, array{rota:string, totem:int, entrada:array, opcoes?:array}> $chamadas
 * @return array<int, array>
 */
function hdRodarParalelo(array $amb, array $chamadas): array
{
    $handles = [];
    foreach ($chamadas as $i => $c) {
        $handles[$i] = hdIniciar($amb, $c['rota'], $c['totem'], $c['entrada'], $c['opcoes'] ?? []);
    }
    $resultados = [];
    foreach ($handles as $i => $h) {
        $resultados[$i] = hdAguardar($h);
    }

    return $resultados;
}

/** Logs de TODOS os subprocessos aguardados ate agora (para varrer sentinelas). */
function hdTodosLogs(): string
{
    return implode("\n", $GLOBALS['hd_logs_coletados'] ?? []);
}

/** Concatena os logs de todos os subprocessos ja aguardados. */
function hdLogsDe(array $resultados): string
{
    return implode("\n", array_map(static fn(array $r): string => $r['log'], $resultados));
}

// ============================================================
// Servidor mock do Talent (contador de requisicoes)
// ============================================================

/**
 * Sobe `php -S` local com um router que conta cada requisicao (arquivo) e
 * delega ao _router_talent_mock.php. NUNCA e o Talent real.
 *
 * @return array{proc: resource, url: string, contador: string}|null
 */
function hdIniciarMockTalent(array $amb): ?array
{
    @mkdir($amb['dir_tmp'], 0777, true);
    $contador = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'talent_contador.txt';
    file_put_contents($contador, '');

    $router = $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'router_contador.php';
    file_put_contents(
        $router,
        "<?php\nfile_put_contents(" . var_export($contador, true) . ", \"x\\n\", FILE_APPEND);\nrequire " . var_export(__DIR__ . '/_router_talent_mock.php', true) . ";\n"
    );

    $porta = random_int(20000, 29000);
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$porta}", $router],
        [1 => ['file', $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'mock_out.txt', 'w'], 2 => ['file', $amb['dir_tmp'] . DIRECTORY_SEPARATOR . 'mock_err.txt', 'w']],
        $pipes
    );

    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.2);
        if ($socket) {
            fclose($socket);

            return ['proc' => $proc, 'url' => "http://127.0.0.1:{$porta}", 'contador' => $contador];
        }
        usleep(100000);
    }

    proc_terminate($proc);

    return null;
}

function hdContarRequisicoesMock(array $mock): int
{
    return substr_count((string) @file_get_contents($mock['contador']), "x\n");
}

function hdPararMockTalent(?array $mock): void
{
    if ($mock !== null) {
        proc_terminate($mock['proc']);
        proc_close($mock['proc']);
    }
}

// ============================================================
// Lock externo (segura FOR UPDATE do atendimento em outra conexao)
// ============================================================

/**
 * Abre uma SEGUNDA conexao ao banco qa_ e segura o lock de linha do
 * atendimento (BEGIN + SELECT ... FOR UPDATE) ate hdSoltarLock().
 */
function hdSegurarLock(array $amb, int $idAtendimento): PDO
{
    $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';port=' . ($_ENV['DB_PORT'] ?? '3306') . ';dbname=' . $amb['banco'] . ';charset=utf8mb4';
    $segunda = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $segunda->beginTransaction();
    $segunda->query('SELECT id_atendimento FROM tb_atendimento WHERE id_atendimento = ' . (int) $idAtendimento . ' FOR UPDATE')->fetchAll();

    return $segunda;
}

function hdSoltarLock(PDO $segunda): void
{
    if ($segunda->inTransaction()) {
        $segunda->rollBack();
    }
}

/**
 * Cria um link de diretorio ($link -> $alvo): symlink quando o ambiente
 * permite; no Windows sem privilegio tenta uma juncao (mklink /J, nao exige
 * privilegio). Retorna false se nenhum dos dois foi possivel.
 */
function hdCriarLinkDiretorio(string $link, string $alvo): bool
{
    if (@symlink($alvo, $link)) {
        return true;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $saida = [];
        $codigo = 1;
        @exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($alvo) . ' 2>&1', $saida, $codigo);

        return $codigo === 0 && is_dir($link);
    }

    return false;
}

/** Remove link/juncao de diretorio sem apagar o alvo. */
function hdRemoverLinkDiretorio(string $link): void
{
    if (DIRECTORY_SEPARATOR === '\\') {
        @exec('cmd /c rmdir ' . escapeshellarg($link) . ' 2>&1');
    } else {
        @unlink($link);
    }
}
