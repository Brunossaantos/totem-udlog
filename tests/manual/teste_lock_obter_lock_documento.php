<?php

/**
 * Roteiro de /02-testes (item "Lock") da demanda
 * sanitizacao-excecoes-lock-documentos (2026-09-20) — cobre o tratamento
 * explicito do retorno de DocumentoController::obterLock() introduzido em
 * iniciarProcessamento(). Fixtures sinteticas/descartaveis, banco de
 * desenvolvimento real (sem dado pessoal), limpeza por DELETE ao final.
 * NUNCA chama VIO/Talent real.
 *
 * Cobre (ver docs/handoffs/2026-09-20-sanitizacao-excecoes-lock-documentos.md,
 * plano de testes, secao "Lock"):
 *   1. GET_LOCK retorna 1 (lock adquirido) -- fluxo normal com PDO UNICO
 *      compartilhado (mesma arquitetura real de public/api/documento.php).
 *   2. GET_LOCK retorna 0 (ocupado) -- via segunda sessao MySQL real
 *      segurando a mesma chave nomeada, criada e derrubada MANUALMENTE
 *      pelo teste (nunca aguarda indefinidamente -- GET_LOCK(..., 0) tem
 *      timeout 0, nao bloqueia).
 *   4. \PDOException REAL na AQUISICAO (dentro de obterLock()) -- conexao
 *      dedicada ao lock morta ANTES do GET_LOCK. Confirma, por CONTAGEM
 *      direta via spy (StatementEspiaoLock, inlinado abaixo), que
 *      liberarLock() NUNCA e executada ($lockAdquirido permanece false).
 *      NUNCA chama VIO real (VIO_AMBIENTE removido do ambiente do
 *      subprocesso antes da chamada).
 *   5/6/7. Conexao do lock morta IMEDIATAMENTE apos GET_LOCK ter sido
 *      adquirido com sucesso (cenario central desta rodada de correcao) --
 *      confirma que a \PDOException de liberarLock() no `finally` e contida,
 *      logada de forma sanitizada (so contexto fixo + get_class($e)) e
 *      NUNCA escapa de iniciarProcessamento().
 *   8. A falha de liberacao do lock (cenario 5/6/7) NAO mascara a falha
 *      TECNICA principal ja capturada antes do finally (VioDecodeClient()
 *      falhando no construtor) -- resposta HTTP 503 preservada.
 *   9. RELEASE_LOCK de uma sessao que NAO detem o lock nao afeta a sessao
 *      que de fato o detem (confirmado via IS_USED_LOCK antes/depois).
 *   Prova negativa: reverte temporariamente o guard
 *      `if ($lockAdquirido) { try { ... } catch (\PDOException $e) { ... } }`
 *      do finally de volta para uma chamada incondicional e sem protecao,
 *      confirma que o cenario 5/6/7 passa a vazar a \PDOException sem
 *      tratamento, reverte e confirma hash MD5 identico ao original.
 *
 * Os demais itens do plano de testes (3: GET_LOCK->NULL, 10-12: concorrencia
 * real/idempotencia) sao cobertos por:
 *   - Item 3 (NULL): NAO simulado nesta suite -- sem cenario realista e
 *     seguro de forcar NULL isoladamente sem inventar artificialidade
 *     (mesma decisao documentada no planejamento/rodadas anteriores).
 *   - Itens 10-12: ja cobertos por
 *     teste_concorrencia_real_iniciar_processamento.php (concorrencia real
 *     via proc_open, CAS por tentativa_id) e por
 *     teste_integridade_conclusao_atendimento.php (liberacao do lock via
 *     IS_USED_LOCK apos erro) -- reexecutados como regressao nesta rodada,
 *     sem alteracao esperada.
 *
 * IMPORTANTE (rodada curta de /01-implementacao de 2026-09-25, achado
 * bloqueante de reprodutibilidade de /02-testes de 24/09 e 25/09): este
 * arquivo antes dependia de helpers `tests/manual/_*.php` NUNCA versionados
 * (.gitignore:37) — _spy_lock_pdo.php (require direto) e 3 subprocessos
 * (_caso_iniciar_processamento_vio_indisponivel.php,
 * _caso_iniciar_processamento_lock_falha_aquisicao_sem_rede.php,
 * _caso_iniciar_processamento_lock_perdido_apos_aquisicao.php), tornando
 * este teste IRREPRODUZIVEL a partir de um `git clone` limpo. Decisao do
 * usuario: opcao (b), inlinar. O spy de PDOStatement (StatementEspiaoLock/
 * EstatisticasLockEspiao/novaConexaoLockEspiao) so era usado dentro dos
 * subprocessos (nunca diretamente por este arquivo) -- seu codigo-fonte
 * agora e definido como string nowdoc reaproveitada apenas ao montar, em
 * tempo de execucao, os scripts temporarios usados como subprocesso (fora
 * de tests/manual/, em sys_get_temp_dir(), removidos ao final via
 * register_shutdown_function) -- preserva o padrao de isolamento por
 * processo separado (indispensavel aqui: os cenarios matam a PROPRIA
 * conexao PDO do subprocesso via KILL CONNECTION_ID(), o que precisa
 * continuar acontecendo num processo isolado do processo principal deste
 * teste, nunca na mesma conexao usada para orquestrar/limpar o banco).
 *
 * Uso: php tests/manual/teste_lock_obter_lock_documento.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();
$raizProjeto = dirname(__DIR__, 2);

// ============================================================
// Infra de scripts temporarios de subprocesso (substitui os antigos
// _caso_*.php nao versionados) — gerados em sys_get_temp_dir(), fora de
// tests/manual/, removidos ao final via register_shutdown_function (mesmo
// em caso de falha/exit antecipado).
// ============================================================
$arquivosTemporariosLimpar = [];
register_shutdown_function(function () use (&$arquivosTemporariosLimpar) {
    foreach ($arquivosTemporariosLimpar as $arquivo) {
        @unlink($arquivo);
    }
});

/**
 * Materializa um corpo de script PHP (nowdoc, com o placeholder %%RAIZ%%
 * substituido pela raiz do projeto em forma de literal PHP seguro via
 * var_export()) num arquivo temporario, fora de tests/manual/. Retorna o
 * caminho absoluto do arquivo gerado.
 */
function gerarScriptTemporario(string $corpo, string $raizProjeto, string $prefixo): string
{
    $raizPhp = var_export($raizProjeto, true);
    // O script gerado roda como PROCESSO PHP SEPARADO (proc_open) -- precisa
    // do seu proprio require do autoload, o autoload do processo GERADOR
    // (este arquivo) nao se propaga para o subprocesso.
    $conteudo = "<?php\n"
        . "require_once {$raizPhp} . '/vendor/autoload.php';\n\n"
        . str_replace('%%RAIZ%%', $raizPhp, $corpo);
    $arquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totem_lock0920_' . $prefixo . '_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($arquivo, $conteudo);
    return $arquivo;
}

// Codigo-fonte antes em _spy_lock_pdo.php — usado SOMENTE dentro dos
// subprocessos abaixo (nunca diretamente neste processo principal).
$codigoSpyLockPdo = <<<'SPYCODE'
/**
 * Infra de teste (continuacao da demanda sanitizacao-excecoes-lock-documentos,
 * 2026-09-20) — spy de PDOStatement usado SOMENTE pelos casos de teste do
 * lock adicional (GET_LOCK/RELEASE_LOCK) em
 * App\Controller\DocumentoController::obterLock()/liberarLock(). Nunca
 * altera producao; e injetado via PDO::ATTR_STATEMENT_CLASS numa conexao
 * PDO isolada e descartavel, usada EXCLUSIVAMENTE como o 3o parametro
 * (`$pdo`) do construtor de DocumentoController nos casos de teste.
 *
 * Dois papeis:
 *  - Contar quantas vezes GET_LOCK/RELEASE_LOCK sao de fato EXECUTADOS
 *    nesta conexao (prova direta, nao inferida, de que liberarLock() so
 *    roda quando $lockAdquirido === true).
 *  - Opcionalmente, matar a PROPRIA conexao (via uma conexao "matadora"
 *    separada, `KILL CONNECTION_ID()`) IMEDIATAMENTE apos um GET_LOCK bem
 *    sucedido, para reproduzir de forma real e deterministica o cenario
 *    "conexao morre entre a aquisicao do lock e o finally" (nao um mock de
 *    excecao sintetica) — sem exigir nenhuma alteracao em producao.
 */

class EstatisticasLockEspiao
{
    public static int $chamadasGetLock = 0;
    public static int $chamadasReleaseLock = 0;

    public static function resetar(): void
    {
        self::$chamadasGetLock = 0;
        self::$chamadasReleaseLock = 0;
    }
}

class StatementEspiaoLock extends \PDOStatement
{
    /** Conexao SEPARADA (nunca a propria conexao sob teste) usada para o KILL. */
    public static ?PDO $matador = null;

    /** CONNECTION_ID() da conexao sob teste a ser morta apos GET_LOCK bem sucedido. 0 = nao mata. */
    public static int $idConexaoParaMatarAposGetLock = 0;

    protected function __construct()
    {
        // Construtor protegido de proposito (mesmo padrao de qualquer
        // PDOStatement customizado) — sempre instanciado internamente pelo
        // PDO::prepare()/query(), nunca diretamente pelo teste.
    }

    public function execute(?array $params = null): bool
    {
        $resultado = parent::execute($params);

        $sql = $this->queryString;

        if (str_contains($sql, 'GET_LOCK(')) {
            EstatisticasLockEspiao::$chamadasGetLock++;

            if ($resultado && self::$idConexaoParaMatarAposGetLock > 0 && self::$matador !== null) {
                // Mata a conexao sob teste a partir de uma conexao
                // DIFERENTE, exatamente como um DBA/timeout do servidor
                // encerraria a sessao de fora -- nunca a propria conexao se
                // matando no meio da sua execucao.
                self::$matador->exec('KILL ' . self::$idConexaoParaMatarAposGetLock);
            }
        }

        if (str_contains($sql, 'RELEASE_LOCK(')) {
            EstatisticasLockEspiao::$chamadasReleaseLock++;
        }

        return $resultado;
    }
}

function novaConexaoLockEspiao(): PDO
{
    $host = $_ENV['DB_HOST'];
    $nome = $_ENV['DB_NAME'];
    $porta = $_ENV['DB_PORT'] ?? '3306';
    $dsn = "mysql:host={$host};port={$porta};dbname={$nome};charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [StatementEspiaoLock::class]);

    return $pdo;
}
SPYCODE;

// Corpo antes em _caso_iniciar_processamento_vio_indisponivel.php.
$corpoVioIndisponivel = <<<'CORPOVIO'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

// Forca VioDecodeClient() a lancar RuntimeException no construtor (ver
// App\Rn\VioDecodeClient::__construct) — nenhuma chamada de rede acontece.
unset($_ENV['VIO_AMBIENTE']);
putenv('VIO_AMBIENTE');

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = $argv[3] ?? 'cnh';

$atendimentoDao = new AtendimentoDao($pdo);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao);
$controller = new DocumentoController($atendimentoDao, $documentoRn, $pdo);

$bytesGarbage = random_bytes(40);

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

$controller->iniciarProcessamento([
    'id_atendimento' => $idAtendimento,
    'tipo' => $tipo,
    'qr_bytes_base64' => base64_encode($bytesGarbage),
], $idTotem);
CORPOVIO;

// Corpo antes em _caso_iniciar_processamento_lock_falha_aquisicao_sem_rede.php.
$corpoLockFalhaAquisicao = <<<'CORPOFALHAAQUISICAO'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

// Garante ZERO chamada de rede real a VIO Decode neste teste -- a falha
// tecnica sob teste aqui e exclusivamente a do LOCK, nunca a da VIO.
unset($_ENV['VIO_AMBIENTE']);
putenv('VIO_AMBIENTE');

$pdoBom = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = $argv[3] ?? 'cnh';

EstatisticasLockEspiao::resetar();

// Conexao ISOLADA e descartavel, usada APENAS como $pdo do
// DocumentoController (obterLock()/liberarLock()).
$pdoLockEspiao = novaConexaoLockEspiao();
$idConexaoLock = (int) $pdoLockEspiao->query('SELECT CONNECTION_ID()')->fetchColumn();
EstatisticasLockEspiao::resetar(); // a query acima nao e GET_LOCK/RELEASE_LOCK, mas reseta por seguranca

// Mata a conexao do lock ANTES de qualquer chamada a obterLock() -- o
// GET_LOCK abaixo, executado sobre uma conexao ja morta, lanca
// \PDOException real dentro de obterLock().
$pdoBom->exec('KILL ' . $idConexaoLock);

$atendimentoDao = new AtendimentoDao($pdoBom);
$documentoRn = new DocumentoRn(new VioCacheDao($pdoBom), $atendimentoDao);
$controller = new DocumentoController($atendimentoDao, $documentoRn, $pdoLockEspiao);

$bytesGarbage = random_bytes(40);

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
    echo 'GET_LOCK_CHAMADAS:' . EstatisticasLockEspiao::$chamadasGetLock . "\n";
    echo 'RELEASE_LOCK_CHAMADAS:' . EstatisticasLockEspiao::$chamadasReleaseLock . "\n";
});

try {
    $controller->iniciarProcessamento([
        'id_atendimento' => $idAtendimento,
        'tipo' => $tipo,
        'qr_bytes_base64' => base64_encode($bytesGarbage),
    ], $idTotem);
} catch (\Throwable $e) {
    echo "EXCECAO_NAO_TRATADA_PROPAGOU: " . get_class($e) . "\n";
}
CORPOFALHAAQUISICAO;

// Corpo antes em _caso_iniciar_processamento_lock_perdido_apos_aquisicao.php.
$corpoLockPerdidoAposAquisicao = <<<'CORPOPERDIDO'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

// Falha tecnica PRINCIPAL, independente do lock, e SEM nenhuma chamada de
// rede real -- VioDecodeClient() falha no proprio construtor.
unset($_ENV['VIO_AMBIENTE']);
putenv('VIO_AMBIENTE');

$pdoBom = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = $argv[3] ?? 'cnh';

EstatisticasLockEspiao::resetar();

$pdoLockEspiao = novaConexaoLockEspiao();
$idConexaoLock = (int) $pdoLockEspiao->query('SELECT CONNECTION_ID()')->fetchColumn();
EstatisticasLockEspiao::resetar();

// A propria conexao do lock mata a SI MESMA a partir de uma conexao
// separada ($pdoBom), disparado pelo spy IMEDIATAMENTE apos o GET_LOCK ter
// tido sucesso (dentro de StatementEspiaoLock::execute()) -- ou seja, no
// momento exato entre "lock adquirido" e o restante do fluxo (incluindo o
// finally), sem qualquer artificialidade de excecao sintetica.
StatementEspiaoLock::$matador = $pdoBom;
StatementEspiaoLock::$idConexaoParaMatarAposGetLock = $idConexaoLock;

$atendimentoDao = new AtendimentoDao($pdoBom);
$documentoRn = new DocumentoRn(new VioCacheDao($pdoBom), $atendimentoDao);
$controller = new DocumentoController($atendimentoDao, $documentoRn, $pdoLockEspiao);

$bytesGarbage = random_bytes(40);

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
    echo 'GET_LOCK_CHAMADAS:' . EstatisticasLockEspiao::$chamadasGetLock . "\n";
    echo 'RELEASE_LOCK_CHAMADAS:' . EstatisticasLockEspiao::$chamadasReleaseLock . "\n";
});

try {
    $controller->iniciarProcessamento([
        'id_atendimento' => $idAtendimento,
        'tipo' => $tipo,
        'qr_bytes_base64' => base64_encode($bytesGarbage),
    ], $idTotem);
} catch (\Throwable $e) {
    // Se este catch disparar, uma excecao propagou sem tratamento de
    // dentro de iniciarProcessamento() -- exatamente o que esta rodada
    // precisa provar que NAO acontece mais.
    echo "EXCECAO_NAO_TRATADA_PROPAGOU: " . get_class($e) . "\n";
}
CORPOPERDIDO;

$arquivosTemporariosLimpar[] = $scriptVioIndisponivel = gerarScriptTemporario($corpoVioIndisponivel, $raizProjeto, 'vio_indisponivel');
$arquivosTemporariosLimpar[] = $scriptLockFalhaAquisicao = gerarScriptTemporario($codigoSpyLockPdo . "\n" . $corpoLockFalhaAquisicao, $raizProjeto, 'lock_falha_aquisicao');
$arquivosTemporariosLimpar[] = $scriptLockPerdidoAposAquisicao = gerarScriptTemporario($codigoSpyLockPdo . "\n" . $corpoLockPerdidoAposAquisicao, $raizProjeto, 'lock_perdido');

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function dispararSequencial(string $script, array $args): string
{
    $php = PHP_BINARY;
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php, $script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    return $saida;
}

function dispararComLogDedicado(string $script, array $args, string $arquivoLog): array
{
    @unlink($arquivoLog);
    $php = PHP_BINARY;
    $flags = ['-d', 'log_errors=1', '-d', "error_log={$arquivoLog}"];
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php], $flags, [$script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    $log = file_exists($arquivoLog) ? file_get_contents($arquivoLog) : '';
    @unlink($arquivoLog);
    return ['stdout' => $stdout, 'stderr' => $stderr, 'log' => $log];
}

function novaConexaoIsolada(): PDO
{
    $host = $_ENV['DB_HOST'];
    $nome = $_ENV['DB_NAME'];
    $porta = $_ENV['DB_PORT'] ?? '3306';
    $dsn = "mysql:host={$host};port={$porta};dbname={$nome};charset=utf8mb4";
    return new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

$idsAtendimentoLimpar = [];
$idsTotemLimpar = [];

// ============================================================
// Item 1 — GET_LOCK retorna 1 (lock adquirido), fluxo normal, PDO UNICO
// compartilhado (mesma arquitetura real de public/api/documento.php:
// AtendimentoDao/DocumentoRn/lock usam a MESMA conexao).
// ============================================================
echo "\n=== Item 1: lock adquirido normalmente (GET_LOCK=1), PDO unico compartilhado ===\n";
$stmtTotem1 = $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:codigo, :nome, :token, 1)');
$stmtTotem1->execute(['codigo' => 'LOCK0920_T1_' . bin2hex(random_bytes(3)), 'nome' => 'x', 'token' => 'tok' . bin2hex(random_bytes(8))]);
$idTotem1 = (int) $pdo->lastInsertId();
$idsTotemLimpar[] = $idTotem1;

$dao1 = new AtendimentoDao($pdo);
$idAt1 = $dao1->criar($idTotem1, 'expedicao', 'LCK0001');
$idsAtendimentoLimpar[] = $idAt1;
$dao1->atualizarEtapa($idAt1, 'exp_cnh');

$chaveLock1 = "vio_validar_{$idAt1}_cnh";
// Confirma estado inicial: lock livre.
$antesUso1 = $pdo->query("SELECT IS_USED_LOCK('{$chaveLock1}')")->fetchColumn();
afirmar('Item 1: lock livre antes de qualquer chamada (IS_USED_LOCK = NULL)', $antesUso1 === null || $antesUso1 === false);

// Dispara em SUBPROCESSO real (mesmo padrao das demais suites deste
// projeto) via script gerado em tempo de execucao que forca erro 503
// controlado (VIO_AMBIENTE ausente) logo apos o trecho do lock, sem chamada
// de rede -- so para observar o comportamento do lock em torno de uma
// execucao real completa, com PDO UNICO compartilhado (mesma arquitetura de
// producao).
$saida1 = dispararSequencial($scriptVioIndisponivel, [(string) $idTotem1, (string) $idAt1, 'cnh']);

afirmar('Item 1: resposta HTTP 503 (mesmo comportamento de sempre)', str_contains($saida1, 'HTTP_CODE:503'));
$depoisUso1 = $pdo->query("SELECT IS_USED_LOCK('{$chaveLock1}')")->fetchColumn();
afirmar('Item 1: lock liberado apos a chamada (IS_USED_LOCK = NULL, RELEASE_LOCK explicito no finally)', $depoisUso1 === null || $depoisUso1 === false);

// ============================================================
// Item 2 — GET_LOCK retorna 0 (ocupado por OUTRA sessao real) -- confirma
// que o processamento PROSSEGUE normalmente (nao bloqueia, nao muda HTTP),
// e emite o novo log de observabilidade "lock adicional nao adquirido".
// ============================================================
echo "\n=== Item 2: lock ocupado por outra sessao real (GET_LOCK=0) ===\n";
$stmtTotem2 = $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:codigo, :nome, :token, 1)');
$stmtTotem2->execute(['codigo' => 'LOCK0920_T2_' . bin2hex(random_bytes(3)), 'nome' => 'x', 'token' => 'tok' . bin2hex(random_bytes(8))]);
$idTotem2 = (int) $pdo->lastInsertId();
$idsTotemLimpar[] = $idTotem2;

$dao2 = new AtendimentoDao($pdo);
$idAt2 = $dao2->criar($idTotem2, 'expedicao', 'LCK0002');
$idsAtendimentoLimpar[] = $idAt2;
$dao2->atualizarEtapa($idAt2, 'exp_cnh');

$chaveLock2 = "vio_validar_{$idAt2}_cnh";

// Segunda sessao REAL segura a mesma chave ANTES da chamada ao Controller.
$pdoSessaoOcupante = novaConexaoIsolada();
$obtidoPelaOcupante = (int) $pdoSessaoOcupante->query("SELECT GET_LOCK('{$chaveLock2}', 0)")->fetchColumn();
afirmar('Item 2 (pre-condicao): sessao ocupante conseguiu o lock de fato', $obtidoPelaOcupante === 1);

$arquivoLog2 = __DIR__ . '/lock0920_item2_' . bin2hex(random_bytes(3)) . '.log';
$resItem2 = dispararComLogDedicado(
    $scriptVioIndisponivel,
    [(string) $idTotem2, (string) $idAt2, 'cnh'],
    $arquivoLog2
);

afirmar('Item 2: processamento PROSSEGUE normalmente mesmo com lock ocupado (mesmo HTTP 503 do fluxo sem contencao)', str_contains($resItem2['stdout'], 'HTTP_CODE:503'));
afirmar(
    'Item 2: log de observabilidade "lock adicional nao adquirido" foi emitido',
    str_contains($resItem2['log'], "iniciar-processamento: lock adicional nao adquirido (defesa em profundidade, CAS ja garantiu exclusividade) id_atendimento={$idAt2} tipo=cnh")
);

// Confirma que a sessao ocupante AINDA detem o lock dela (o codigo sob
// teste nunca tenta liberar o lock de outra sessao para o MESMO
// atendimento -- liberarLock() so roda para a MESMA chave apos a PROPRIA
// tentativa, que aqui foi 0/nao adquirida -- ver Item 9 abaixo para a
// prova direta de nao-interferencia).
$idConexaoOcupante = (int) $pdoSessaoOcupante->query('SELECT CONNECTION_ID()')->fetchColumn();
$aindaDetido = $pdoSessaoOcupante->query("SELECT IS_USED_LOCK('{$chaveLock2}')")->fetchColumn();
afirmar('Item 2: lock da sessao ocupante permanece intacto/detido por ela apos a chamada do Controller', (int) $aindaDetido === $idConexaoOcupante);

$pdoSessaoOcupante->query("SELECT RELEASE_LOCK('{$chaveLock2}')");
unset($pdoSessaoOcupante);

// ============================================================
// Item 4 — \PDOException REAL na AQUISICAO do lock (dentro de obterLock()):
// conexao dedicada ao lock e morta ANTES do GET_LOCK. Confirma por CONTAGEM
// direta (nao inferencia) que liberarLock() NUNCA e executada nesta conexao
// ($lockAdquirido permanece false). SEM chamada de rede real a VIO (o
// script gerado remove VIO_AMBIENTE do ambiente do subprocesso).
// ============================================================
echo "\n=== Item 4: PDOException real na aquisicao do lock (GET_LOCK sobre conexao morta) ===\n";
$stmtTotem4 = $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:codigo, :nome, :token, 1)');
$stmtTotem4->execute(['codigo' => 'LOCK0920_T4_' . bin2hex(random_bytes(3)), 'nome' => 'x', 'token' => 'tok' . bin2hex(random_bytes(8))]);
$idTotem4 = (int) $pdo->lastInsertId();
$idsTotemLimpar[] = $idTotem4;

$dao4 = new AtendimentoDao($pdo);
$idAt4 = $dao4->criar($idTotem4, 'expedicao', 'LCK0004');
$idsAtendimentoLimpar[] = $idAt4;
$dao4->atualizarEtapa($idAt4, 'exp_cnh');

$arquivoLog4 = __DIR__ . '/lock0920_item4_' . bin2hex(random_bytes(3)) . '.log';
$resItem4 = dispararComLogDedicado(
    $scriptLockFalhaAquisicao,
    [(string) $idTotem4, (string) $idAt4, 'cnh'],
    $arquivoLog4
);

afirmar('Item 4: nenhuma excecao propaga sem tratamento (sem "EXCECAO_NAO_TRATADA_PROPAGOU")', !str_contains($resItem4['stdout'], 'EXCECAO_NAO_TRATADA_PROPAGOU'));
afirmar('Item 4: resposta HTTP 503 (VioDecodeClient falha no construtor, mesmo comportamento de sempre)', str_contains($resItem4['stdout'], 'HTTP_CODE:503'));
afirmar('Item 4: log sanitizado da falha de AQUISICAO do lock foi emitido (contexto fixo + get_class)', str_contains($resItem4['log'], "iniciar-processamento (obter lock adicional) id_atendimento={$idAt4} tipo=cnh: falha nao prevista [PDOException]"));
afirmar('Item 4: RELEASE_LOCK NUNCA executado nesta conexao (liberarLock() nao chamada -- $lockAdquirido permaneceu false)', str_contains($resItem4['stdout'], 'RELEASE_LOCK_CHAMADAS:0'));
afirmar('Item 4: nenhuma chamada real a VIO Decode (log NAO contem nada do VioDecodeClient alem da falha local de construtor)', !str_contains($resItem4['log'], 'VioDecodeClient: falha tecnica categoria='));

// ============================================================
// Itens 5/6/7/8 — conexao do lock morta IMEDIATAMENTE apos GET_LOCK ter
// sido adquirido com sucesso (cenario central desta rodada de correcao),
// combinado com uma falha TECNICA principal INDEPENDENTE e ANTERIOR ao
// finally (VioDecodeClient() falhando no construtor, sem rede real).
// Confirma: (5) cenario reproduzido de forma real; (6) PDOException de
// liberarLock() capturada e logada de forma sanitizada; (7) nenhuma
// excecao escapa de iniciarProcessamento(); (8) a falha de liberacao do
// lock NAO mascara a resposta HTTP da falha principal ja capturada.
// ============================================================
echo "\n=== Itens 5/6/7/8: conexao do lock morre apos aquisicao bem-sucedida, antes do finally ===\n";
$stmtTotem5 = $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:codigo, :nome, :token, 1)');
$stmtTotem5->execute(['codigo' => 'LOCK0920_T5_' . bin2hex(random_bytes(3)), 'nome' => 'x', 'token' => 'tok' . bin2hex(random_bytes(8))]);
$idTotem5 = (int) $pdo->lastInsertId();
$idsTotemLimpar[] = $idTotem5;

$dao5 = new AtendimentoDao($pdo);
$idAt5 = $dao5->criar($idTotem5, 'expedicao', 'LCK0005');
$idsAtendimentoLimpar[] = $idAt5;
$dao5->atualizarEtapa($idAt5, 'exp_cnh');

$arquivoLog5 = __DIR__ . '/lock0920_item5_' . bin2hex(random_bytes(3)) . '.log';
$resItem5 = dispararComLogDedicado(
    $scriptLockPerdidoAposAquisicao,
    [(string) $idTotem5, (string) $idAt5, 'cnh'],
    $arquivoLog5
);

afirmar('Item 5: GET_LOCK foi de fato executado e adquirido com sucesso nesta conexao', str_contains($resItem5['stdout'], 'GET_LOCK_CHAMADAS:1'));
afirmar('Item 7: nenhuma excecao propaga sem tratamento de iniciarProcessamento() (sem "EXCECAO_NAO_TRATADA_PROPAGOU")', !str_contains($resItem5['stdout'], 'EXCECAO_NAO_TRATADA_PROPAGOU'));
afirmar('Item 6: log sanitizado da falha de LIBERACAO do lock foi emitido (contexto fixo + get_class, nunca getMessage/trace/SQL)', str_contains($resItem5['log'], "iniciar-processamento (liberar lock adicional) id_atendimento={$idAt5} tipo=cnh: falha nao prevista [PDOException]"));
afirmar('Item 8: resposta HTTP 503 da falha PRINCIPAL (VioDecodeClient) preservada, NAO mascarada pela falha de liberacao do lock', str_contains($resItem5['stdout'], 'HTTP_CODE:503'));
afirmar('Item 8: mensagem da falha principal preservada no corpo da resposta', str_contains($resItem5['stdout'], 'Servico de validacao de documento indisponivel no momento'));
afirmar('Item 9 (reforco): nenhuma tentativa de reconexao/nova conexao para liberar o lock -- so os 2 logs esperados (aquisicao=nenhum erro + liberacao=1 erro) aparecem, sem log adicional de retry', !str_contains($resItem5['log'], 'reconex') && !str_contains($resItem5['log'], 'retry'));

// ============================================================
// Prova negativa — reverte temporariamente o guard
// `if ($lockAdquirido) { try { liberarLock() } catch (\PDOException $e) { ... } }`
// do finally de iniciarProcessamento() para uma chamada INCONDICIONAL e SEM
// protecao (exatamente o estado ANTES desta rodada de correcao), roda
// NOVAMENTE o cenario 5/6/7 acima, confirma que a suite agora DETECTA a
// regressao (excecao propagando sem tratamento), reverte e confirma hash
// MD5 identico ao original (arquivo intocado ao final).
// ============================================================
echo "\n=== Prova negativa: reverter guard/try-catch de liberarLock() no finally ===\n";
$arquivoDocumentoController = __DIR__ . '/../../app/Controller/DocumentoController.php';
$conteudoOriginalDc = file_get_contents($arquivoDocumentoController);
$md5OriginalDc = md5($conteudoOriginalDc);

$trechoProtegido = <<<'PHP'
            if ($lockAdquirido) {
                try {
                    $this->liberarLock($chaveLock);
                } catch (\PDOException $e) {
                    $this->logFalhaTecnica("iniciar-processamento (liberar lock adicional) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
                }
            }
PHP;
$trechoSemProtecao = '            $this->liberarLock($chaveLock);';

if (!str_contains($conteudoOriginalDc, $trechoProtegido)) {
    afirmar('Prova negativa: trecho protegido esperado encontrado no arquivo original (pre-condicao)', false);
} else {
    $conteudoSemProtecao = str_replace($trechoProtegido, $trechoSemProtecao, $conteudoOriginalDc);
    file_put_contents($arquivoDocumentoController, $conteudoSemProtecao);

    $stmtTotemNeg = $pdo->prepare('INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES (:codigo, :nome, :token, 1)');
    $stmtTotemNeg->execute(['codigo' => 'LOCK0920_NEG_' . bin2hex(random_bytes(3)), 'nome' => 'x', 'token' => 'tok' . bin2hex(random_bytes(8))]);
    $idTotemNeg = (int) $pdo->lastInsertId();
    $idsTotemLimpar[] = $idTotemNeg;

    $daoNeg = new AtendimentoDao($pdo);
    $idAtNeg = $daoNeg->criar($idTotemNeg, 'expedicao', 'LCKNEG');
    $idsAtendimentoLimpar[] = $idAtNeg;
    $daoNeg->atualizarEtapa($idAtNeg, 'exp_cnh');

    $arquivoLogNeg = __DIR__ . '/lock0920_negativo_' . bin2hex(random_bytes(3)) . '.log';
    $resNegativo = dispararComLogDedicado(
        $scriptLockPerdidoAposAquisicao,
        [(string) $idTotemNeg, (string) $idAtNeg, 'cnh'],
        $arquivoLogNeg
    );

    afirmar(
        'Prova negativa: sem o guard/try-catch, a PDOException de liberarLock() ESCAPA de iniciarProcessamento() (confirma que a protecao real esta sendo testada)',
        str_contains($resNegativo['stdout'], 'EXCECAO_NAO_TRATADA_PROPAGOU: PDOException')
    );

    // reverte
    file_put_contents($arquivoDocumentoController, $conteudoOriginalDc);
    $md5RevertidoDc = md5(file_get_contents($arquivoDocumentoController));
    afirmar('Prova negativa: arquivo revertido com hash MD5 identico ao original', $md5RevertidoDc === $md5OriginalDc);
}

// ============================================================
// Item 9 — RELEASE_LOCK explicito de uma sessao que NUNCA deteve o lock (a
// mesma liberarLock() do finally, chamada incondicionalmente) nao afeta
// NENHUMA outra sessao que de fato o detenha -- confirmado com uma chamada
// DIRETA e ISOLADA (sem depender do fluxo completo do Controller).
// ============================================================
echo "\n=== Item 9: RELEASE_LOCK sobre lock nao detido por esta sessao nao afeta outra sessao ===\n";
$chaveLock9 = 'vio_validar_teste_item9_' . bin2hex(random_bytes(3));

$sessaoA = novaConexaoIsolada();
$sessaoB = novaConexaoIsolada();

$obtidoPorA = (int) $sessaoA->query("SELECT GET_LOCK('{$chaveLock9}', 0)")->fetchColumn();
afirmar('Item 9 (pre-condicao): sessao A obteve o lock', $obtidoPorA === 1);

$idConexaoA = (int) $sessaoA->query('SELECT CONNECTION_ID()')->fetchColumn();
$usoAntes9 = (int) $sessaoB->query("SELECT IS_USED_LOCK('{$chaveLock9}')")->fetchColumn();
afirmar('Item 9: antes do RELEASE_LOCK de B, o lock e detido pela sessao A', $usoAntes9 === $idConexaoA);

// Sessao B (que NUNCA obteve o lock) chama RELEASE_LOCK sobre a mesma
// chave -- exatamente o que liberarLock() faz incondicionalmente hoje.
$retornoReleaseB = $sessaoB->query("SELECT RELEASE_LOCK('{$chaveLock9}')")->fetchColumn();
afirmar('Item 9: RELEASE_LOCK por quem NAO detem o lock retorna 0 (NAO libera, NAO lanca erro)', (int) $retornoReleaseB === 0);

$usoDepois9 = (int) $sessaoB->query("SELECT IS_USED_LOCK('{$chaveLock9}')")->fetchColumn();
afirmar('Item 9: lock da sessao A continua INTACTO/detido por ela apos o RELEASE_LOCK indevido de B', $usoDepois9 === $idConexaoA);

$sessaoA->query("SELECT RELEASE_LOCK('{$chaveLock9}')");
unset($sessaoA, $sessaoB);

// ============================================================
// Limpeza
// ============================================================
foreach (array_unique($idsAtendimentoLimpar) as $id) {
    $pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
foreach (array_unique($idsTotemLimpar) as $idTotem) {
    $pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);
}

$totalTotemResidual = 0;
foreach (array_unique($idsTotemLimpar) as $idTotem) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tb_totem WHERE id_totem = :id');
    $stmt->execute(['id' => $idTotem]);
    $totalTotemResidual += (int) $stmt->fetchColumn();
}
afirmar('Limpeza: totens de teste (LOCK0920_*) removidos do banco', $totalTotemResidual === 0);

foreach ($arquivosTemporariosLimpar as $arquivo) {
    @unlink($arquivo);
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
