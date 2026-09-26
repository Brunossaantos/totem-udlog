<?php

/**
 * Roteiro de /02-testes da demanda sanitizacao-excecoes-lock-documentos
 * (2026-09-20) — cobre os 5 pontos sanitizados (3 em DocumentoController.php,
 * 2 em NotaController.php): confirma ausencia de marcadores sensiveis
 * sinteticos em resposta HTTP/stdout, stderr e arquivo de log dedicado, e que
 * o log contem SOMENTE o contexto fixo + get_class($e). Inclui prova
 * negativa (reverter 1 ponto para getMessage() cru, confirmar que a suite
 * detecta o vazamento, reverter e confirmar hash MD5 identico).
 *
 * Fixtures 100% sinteticas/descartaveis (banco de desenvolvimento real, sem
 * dado pessoal real — placas/CPF sao fixtures ja usadas por outras suites
 * deste projeto). NUNCA chama VIO/Talent/Serpro/vio.api.br real. Marcador
 * desta rodada: MARCADOR_SANIT_0920_* (ver mocks inlinados abaixo).
 *
 * Pontos 1/2 reescritos na rodada corretiva de migracao-vio-api-br-com-cache
 * (2026-09-26) para refletir o fluxo NOVO (App\Rn\VioApiBrClient) — Ponto 1
 * agora cobre o catch em torno de `new VioApiBrClient()` (era
 * `VioDecodeClient`), Ponto 2 agora cobre o catch em torno de
 * App\Rn\DocumentoRn::calcularFingerprintVioApiBr() (era validarCnh/
 * validarCrlv, metodos que o fluxo novo nunca mais chama).
 *
 * IMPORTANTE (rodada curta de /01-implementacao de 2026-09-25, achado
 * bloqueante de reprodutibilidade de /02-testes de 24/09 e 25/09): este
 * arquivo antes dependia de helpers `tests/manual/_*.php` NUNCA versionados
 * (.gitignore:37) — _mocks_sanitizacao_logs.php (require direto) e 4
 * subprocessos (_caso_iniciar_processamento_vio_indisponivel.php,
 * _caso_iniciar_processamento_marcador.php,
 * _caso_preencher_manual_marcador.php, _caso_identificar_cliente_marcador.php,
 * _caso_definir_numero_marcador.php), tornando este teste IRREPRODUZIVEL a
 * partir de um `git clone` limpo. Decisao do usuario: opcao (b), inlinar.
 * O conteudo dos mocks (constantes/classes) agora e definido como codigo-
 * fonte literal (nowdoc) e ativado via eval() para uso DIRETO neste processo
 * (ex.: todosOsMarcadores()) — mesmo texto e reaproveitado para montar, em
 * tempo de execucao, os scripts temporarios usados como subprocesso (fora de
 * tests/manual/, em sys_get_temp_dir(), removidos ao final via
 * register_shutdown_function) — preserva o padrao de isolamento por
 * subprocesso ja usado antes (env vars/http_response_code isolados por
 * processo), sem depender de nenhum arquivo fixo `_*.php`.
 * _caso_nota_definir_numero.php (usado na regressao de 'nota_nao_encontrada')
 * e _fixtures_talent.php/_fixtures_identificar_cliente.php permanecem como
 * require/subprocesso normais — ja sao VERSIONADOS no git (confirmado via
 * `git ls-files`), nao fazem parte do achado de reprodutibilidade.
 *
 * Uso: php tests/manual/teste_sanitizacao_logs_documento_nota.php
 *
 * IMPORTANTE (rodada corretiva de migracao-vio-api-br-com-cache, 2026-09-26):
 * este arquivo rodava antes contra o banco de DEV compartilhado
 * `udlog_totem`, que NUNCA recebeu as migrations 015/016 desta demanda --
 * batia em "Column not found". Passa a rodar contra um banco
 * `qa_`-prefixado DESCARTAVEL PROPRIO (mesmo helper
 * tests/manual/qa_db_bootstrap.php ja usado pelas 3 suites novas), criado
 * do zero e dropado ao final. Os subprocessos gerados abaixo (proc_open)
 * recebem DB_NAME/demais envs herdados via putenv() do processo pai atraves
 * de qaDbTrechoPonteEnvSubprocesso() (ver qa_db_bootstrap.php).
 */

require_once __DIR__ . '/qa_db_bootstrap.php';
require_once __DIR__ . '/_fixtures_talent.php';
require_once __DIR__ . '/_fixtures_identificar_cliente.php';

use App\Dao\AtendimentoDao;

$raizProjeto = dirname(__DIR__, 2);

$nomeBancoSanit0920 = null;
[$pdo, $nomeBancoSanit0920] = qaDbCriar('sanitizacao_logs_documento_nota');
qaDbCorrigirEnumsMigration015($pdo);
// $_ENV (usado neste processo diretamente, ex.: $pdo/$atendimentoDaoGlobal
// abaixo) + putenv() (herdado pelos subprocessos via proc_open()).
$_ENV['DB_NAME'] = $nomeBancoSanit0920;
putenv('DB_NAME=' . $nomeBancoSanit0920);
register_shutdown_function(function () use (&$nomeBancoSanit0920) {
    if ($nomeBancoSanit0920 !== null) {
        qaDbDropar($nomeBancoSanit0920);
        echo "\n(banco de teste {$nomeBancoSanit0920} dropado)\n";
        $nomeBancoSanit0920 = null;
    }
});

// tb_empresa nao vem semeada em schema.sql (as seeds de
// sql/migrations/008_tb_empresa_totem_vinculo.sql sao aplicadas so no banco
// de dev real) -- fixture sintetica propria deste banco `qa_` descartavel.
$pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Empresa QA Sanitizacao', '00000000000272')");
$idEmpresaQaSanit0920 = (int) $pdo->lastInsertId();

// ============================================================
// Mocks inlinados (conteudo antes em _mocks_sanitizacao_logs.php) — codigo-
// fonte literal, ativado via eval() para uso direto neste processo E
// reaproveitado (mesma string, fonte unica) ao montar os scripts temporarios
// de subprocesso abaixo, sem duplicar o texto.
// ============================================================
$codigoMocksSanitizacao = <<<'MOCKSCODE'
/**
 * Mocks/subclasses SOMENTE para testes (demanda
 * sanitizacao-excecoes-lock-documentos, 2026-09-20) — nunca alteram
 * app/Rn/*.php, apenas SUBCLASSEIAM DocumentoRn/NotaFiscalRn (nenhuma delas
 * e `final`) para forcar, em pontos especificos, uma excecao sintetica cuja
 * getMessage() carrega MARCADORES EXCLUSIVOS (SQL, caminho absoluto, host,
 * usuario/senha, token, CPF, placa, conteudo de documento) — usados para
 * confirmar que os 5 pontos sanitizados de DocumentoController/NotaController
 * NUNCA despejam esses marcadores em log/resposta HTTP, so
 * `get_class($e)` + contexto fixo.
 *
 * Marcador unico desta rodada de testes: prefixo MARCADOR_SANIT_0920_.
 */

const MARCADOR_SQL = "MARCADOR_SANIT_0920_SQL: SELECT senha_hash FROM tb_totem_secreto WHERE 1=1 -- ";
const MARCADOR_CAMINHO = 'MARCADOR_SANIT_0920_PATH: C:\\xampp\\htdocs\\totem-udlog\\storage\\atendimentos\\segredo.txt';
const MARCADOR_HOST = 'MARCADOR_SANIT_0920_HOST: db-interno-udlog.hostgator.local:3306';
const MARCADOR_CREDENCIAL = 'MARCADOR_SANIT_0920_CRED: usuario=root_totem senha=SuperSegred0!2026';
const MARCADOR_TOKEN = 'MARCADOR_SANIT_0920_TOKEN: Bearer eyJhbGciOiJIUzI1NiJ9.MARCADOR.assinatura';
const MARCADOR_CPF = 'MARCADOR_SANIT_0920_CPF: 52998224725';
const MARCADOR_PLACA = 'MARCADOR_SANIT_0920_PLACA: MKR4A11';
const MARCADOR_DOCUMENTO = 'MARCADOR_SANIT_0920_DOC: conteudo bruto da CNH digitalizada em base64 XPTO==';

function mensagemMarcadaCompleta(string $origem): string
{
    return "{$origem} | " . MARCADOR_SQL . ' | ' . MARCADOR_CAMINHO . ' | ' . MARCADOR_HOST . ' | '
        . MARCADOR_CREDENCIAL . ' | ' . MARCADOR_TOKEN . ' | ' . MARCADOR_CPF . ' | ' . MARCADOR_PLACA . ' | '
        . MARCADOR_DOCUMENTO;
}

/**
 * Todos os 8 marcadores individuais + o literal completo — usado pelos
 * scripts de verificacao para varrer resposta HTTP/stdout/stderr/log.
 */
function todosOsMarcadores(): array
{
    return [
        MARCADOR_SQL, MARCADOR_CAMINHO, MARCADOR_HOST, MARCADOR_CREDENCIAL,
        MARCADOR_TOKEN, MARCADOR_CPF, MARCADOR_PLACA, MARCADOR_DOCUMENTO,
    ];
}

// ---------------------------------------------------------------------
// Catch 2 (DocumentoController::iniciarProcessamento, catch em torno de
// App\Rn\DocumentoRn::calcularFingerprintVioApiBr() — reescrito na rodada
// corretiva de migracao-vio-api-br-com-cache, 2026-09-26: o fluxo novo
// NUNCA mais chama validarCnh()/validarCrlv() dentro de iniciarProcessamento
// — esses metodos permanecem intocados so para o fluxo antigo/rollback do
// Serpro, nunca mais exercitados por este caminho) e Catch 3
// (DocumentoController::preencherManual, INALTERADO — preencherManualCnh/
// Crlv continuam sendo os mesmos metodos de sempre) — subclasse de
// DocumentoRn.
// ---------------------------------------------------------------------
final class DocumentoRnMarcadorThrow extends \App\Rn\DocumentoRn
{
    public function cnhAprovada(array $atendimento): bool
    {
        return false;
    }

    public function crlvAprovado(array $atendimento): bool
    {
        return false;
    }

    public function calcularFingerprintVioApiBr(string $bytesQrBrutos): array
    {
        throw new \RuntimeException(mensagemMarcadaCompleta('calcularFingerprintVioApiBr'));
    }

    public function preencherManualCnh(array $atendimento, string $nome, string $cpfBruto, string $dataValidadeBruta): array
    {
        throw new \RuntimeException(mensagemMarcadaCompleta('preencherManualCnh'));
    }

    public function preencherManualCrlv(array $atendimento, string $placaBruta, mixed $exercicioBruto, string $ufBruta, string $rntcBruto, string $tipoBruto): array
    {
        throw new \RuntimeException(mensagemMarcadaCompleta('preencherManualCrlv'));
    }
}

// ---------------------------------------------------------------------
// Catch 4 (NotaController::identificarCliente) e Catch 5
// (NotaController::definirNumero, ramo else) — subclasse de NotaFiscalRn.
// ---------------------------------------------------------------------
final class NotaFiscalRnMarcadorThrow extends \App\Rn\NotaFiscalRn
{
    public function identificarCliente(
        int $idAtendimento,
        int $idNota,
        ?string $chaveOcr,
        array $cnpjsCandidatos,
        ?string $razaoSocialCandidata
    ): array {
        throw new \RuntimeException(mensagemMarcadaCompleta('identificarCliente'));
    }

    public function atualizarNumeroNota(int $idAtendimento, int $ordem, string $numeroBruto, string $origem): array
    {
        // Mensagem deliberadamente DIFERENTE de 'numero_nota_duplicado' e de
        // 'nota_nao_encontrada' -- precisa cair no ramo `else` final de
        // NotaController::definirNumero(), o unico alvo desta sanitizacao.
        throw new \RuntimeException(mensagemMarcadaCompleta('atualizarNumeroNota'));
    }
}
MOCKSCODE;

eval($codigoMocksSanitizacao);

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
    $arquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totem_sanit0920_' . $prefixo . '_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($arquivo, $conteudo);
    return $arquivo;
}

// Corpo antes em _caso_iniciar_processamento_vio_indisponivel.php. Reescrito
// na rodada corretiva de migracao-vio-api-br-com-cache (2026-09-26): forca
// App\Rn\VioApiBrClient (NUNCA mais App\Rn\VioDecodeClient) a falhar no
// construtor. Usa tipo='crlv' fixo (dispensa JPEG estruturalmente valido —
// ver docblock de _caso_iniciar_processamento_vio_indisponivel.php para o
// mesmo raciocinio) com um crlv.jpg de fixture ja presente em disco (criado
// pelo teste pai) e HMAC do fingerprint configurado via putenv() herdado.
$corpoVioIndisponivel = <<<'CORPOVIO'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Dao\VioApiBrCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

foreach (getenv() as $qaChaveHerdada => $qaValorHerdado) {
    if (!array_key_exists($qaChaveHerdada, $_ENV)) {
        $_ENV[$qaChaveHerdada] = $qaValorHerdado;
    }
}

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

// Fail-closed do NOVO cliente: ausencia de VIO_API_BR_BASE_URL/API_KEY forca
// `new VioApiBrClient()` a lancar RuntimeException no construtor — nenhuma
// chamada de rede acontece.
unset($_ENV['VIO_API_BR_BASE_URL'], $_ENV['VIO_API_BR_API_KEY']);
putenv('VIO_API_BR_BASE_URL');
putenv('VIO_API_BR_API_KEY');

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = 'crlv';

$atendimentoDao = new AtendimentoDao($pdo);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao, new VioApiBrCacheDao($pdo));
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

// Corpo antes em _caso_iniciar_processamento_marcador.php. Reescrito na
// rodada corretiva de migracao-vio-api-br-com-cache (2026-09-26): o alvo
// agora e o catch em torno de
// App\Rn\DocumentoRn::calcularFingerprintVioApiBr() (ponto MAIS CEDO do
// fluxo novo que ainda delega a DocumentoRn — nunca mais
// validarCnh()/validarCrlv(), que o fluxo novo nao chama). Nenhuma env de
// VIO e necessaria aqui (a excecao acontece antes de qualquer uso real
// delas).
$corpoMarcadorValidar = <<<'CORPOMARCADOR'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Controller\DocumentoController;

foreach (getenv() as $qaChaveHerdada => $qaValorHerdado) {
    if (!array_key_exists($qaChaveHerdada, $_ENV)) {
        $_ENV[$qaChaveHerdada] = $qaValorHerdado;
    }
}

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = $argv[3] ?? 'cnh';

$atendimentoDao = new AtendimentoDao($pdo);
$documentoRn = new DocumentoRnMarcadorThrow(new VioCacheDao($pdo), $atendimentoDao);
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
CORPOMARCADOR;

// Corpo antes em _caso_preencher_manual_marcador.php.
$corpoPreencherManual = <<<'CORPOPREENCHER'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Controller\DocumentoController;

foreach (getenv() as $qaChaveHerdada => $qaValorHerdado) {
    if (!array_key_exists($qaChaveHerdada, $_ENV)) {
        $_ENV[$qaChaveHerdada] = $qaValorHerdado;
    }
}

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$tipo = $argv[3] ?? 'crlv';

$atendimentoDao = new AtendimentoDao($pdo);
$documentoRn = new DocumentoRnMarcadorThrow(new VioCacheDao($pdo), $atendimentoDao);
$controller = new DocumentoController($atendimentoDao, $documentoRn, $pdo);

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

if ($tipo === 'cnh') {
    $controller->preencherManual([
        'id_atendimento' => $idAtendimento,
        'tipo' => 'cnh',
        'nome' => 'Motorista Teste',
        'cpf' => '52998224725',
        'validade' => '2030-01-01',
    ], $idTotem);
} else {
    $controller->preencherManual([
        'id_atendimento' => $idAtendimento,
        'tipo' => 'crlv',
        'placa' => 'TST1234',
        'exercicio' => '2026',
        'uf' => 'SP',
        'rntc' => '12345678',
        'tipo_veiculo' => 'CAMINHAO',
    ], $idTotem);
}
CORPOPREENCHER;

// Corpo antes em _caso_identificar_cliente_marcador.php.
$corpoIdentificarCliente = <<<'CORPOIDCLIENTE'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\RateLimitOcrDao;
use App\Controller\NotaController;

foreach (getenv() as $qaChaveHerdada => $qaValorHerdado) {
    if (!array_key_exists($qaChaveHerdada, $_ENV)) {
        $_ENV[$qaChaveHerdada] = $qaValorHerdado;
    }
}

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$ordem = (int) ($argv[3] ?? 1);

$atendimentoDao = new AtendimentoDao($pdo);
$notaFiscalRn = new NotaFiscalRnMarcadorThrow(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));
$controller = new NotaController($notaFiscalRn, $atendimentoDao, new RateLimitOcrDao($pdo));

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

$controller->identificarCliente([
    'id_atendimento' => $idAtendimento,
    'ordem' => $ordem,
    'cnpjs_candidatos' => [],
    'razao_social_candidata' => null,
], $idTotem);
CORPOIDCLIENTE;

// Corpo antes em _caso_definir_numero_marcador.php.
$corpoDefinirNumero = <<<'CORPODEFNUM'
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\RateLimitOcrDao;
use App\Controller\NotaController;

foreach (getenv() as $qaChaveHerdada => $qaValorHerdado) {
    if (!array_key_exists($qaChaveHerdada, $_ENV)) {
        $_ENV[$qaChaveHerdada] = $qaValorHerdado;
    }
}

$dotenv = Dotenv::createImmutable(%%RAIZ%%);
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);
$ordem = (int) ($argv[3] ?? 1);

$notaFiscalRn = new NotaFiscalRnMarcadorThrow(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));
$controller = new NotaController($notaFiscalRn, new AtendimentoDao($pdo), new RateLimitOcrDao($pdo));

register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

$controller->definirNumero([
    'id_atendimento' => $idAtendimento,
    'ordem' => $ordem,
    'numero' => '12345',
    'origem' => 'MANUAL',
], $idTotem);
CORPODEFNUM;

$arquivosTemporariosLimpar[] = $scriptVioIndisponivel = gerarScriptTemporario($corpoVioIndisponivel, $raizProjeto, 'vio_indisponivel');
$arquivosTemporariosLimpar[] = $scriptMarcadorValidar = gerarScriptTemporario($codigoMocksSanitizacao . "\n" . $corpoMarcadorValidar, $raizProjeto, 'marcador_validar');
$arquivosTemporariosLimpar[] = $scriptPreencherManual = gerarScriptTemporario($codigoMocksSanitizacao . "\n" . $corpoPreencherManual, $raizProjeto, 'preencher_manual');
$arquivosTemporariosLimpar[] = $scriptIdentificarCliente = gerarScriptTemporario($codigoMocksSanitizacao . "\n" . $corpoIdentificarCliente, $raizProjeto, 'identificar_cliente');
$arquivosTemporariosLimpar[] = $scriptDefinirNumero = gerarScriptTemporario($codigoMocksSanitizacao . "\n" . $corpoDefinirNumero, $raizProjeto, 'definir_numero');

$atendimentoDaoGlobal = new AtendimentoDao($pdo);

/**
 * Cria um atendimento de EXPEDICAO ja posicionado na etapa indicada — usa
 * App\Dao\AtendimentoDao::criar()/atualizarEtapa() (mesmo caminho real de
 * producao), reaproveitando o padrao ja usado por
 * teste_concorrencia_real_iniciar_processamento.php.
 */
function talentCriarAtendimentoExpedicaoEtapa(AtendimentoDao $dao, int $idTotem, string $etapa): int
{
    $idAtendimento = $dao->criar($idTotem, 'expedicao', 'SAN' . bin2hex(random_bytes(2)));
    $dao->atualizarEtapa($idAtendimento, $etapa);
    return $idAtendimento;
}

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

/**
 * Mesma tecnica ja usada em teste_rate_limit_identificar_cliente_pdo.php
 * (dispararComLogDedicado): pipes SEPARADOS para stdout/stderr, error_log
 * direcionado via -d para um arquivo dedicado e isolado por chamada.
 */
function dispararComLogDedicado(array $opcoesPhp, string $script, array $args, string $arquivoLog): array
{
    @unlink($arquivoLog);
    $opcoesPhp = array_merge($opcoesPhp, [
        'log_errors' => '1',
        'error_log' => $arquivoLog,
    ]);
    $php = PHP_BINARY;
    $flags = [];
    foreach ($opcoesPhp as $chave => $valor) {
        $flags[] = '-d';
        $flags[] = "{$chave}={$valor}";
    }
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = array_merge([$php], $flags, [$script], $args);
    $processo = proc_open($cmd, $descritores, $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processo);
    $conteudoLog = file_exists($arquivoLog) ? file_get_contents($arquivoLog) : '';
    @unlink($arquivoLog);
    return ['stdout' => $stdout, 'stderr' => $stderr, 'log' => $conteudoLog];
}

function contemAlgumMarcador(string $conteudo, array $marcadores): ?string
{
    foreach ($marcadores as $m) {
        if (str_contains($conteudo, $m)) {
            return $m;
        }
    }
    return null;
}

/**
 * Roda os 3 fluxos (stdout, stderr, log) de um ponto sanitizado e afirma
 * ausencia TOTAL dos marcadores nos 3, alem de confirmar que o log contem
 * exatamente o contexto fixo esperado + [NomeDaClasse].
 */
function verificarPontoSanitizado(
    string $rotulo,
    string $script,
    array $args,
    string $contextoEsperadoNoLog,
    string $classeExcecaoEsperada,
    array $marcadores
): array {
    $arquivoLog = __DIR__ . '/sanit0920_' . preg_replace('/[^a-z0-9]/i', '_', $rotulo) . '_' . bin2hex(random_bytes(3)) . '.log';
    $r = dispararComLogDedicado(
        ['display_errors' => '1', 'error_reporting' => 'E_ALL', 'html_errors' => '0'],
        $script,
        $args,
        $arquivoLog
    );

    $marcadorEmStdout = contemAlgumMarcador($r['stdout'], $marcadores);
    $marcadorEmStderr = contemAlgumMarcador($r['stderr'], $marcadores);
    $marcadorEmLog = contemAlgumMarcador($r['log'], $marcadores);

    afirmar("{$rotulo}: nenhum marcador sensivel no stdout/resposta HTTP", $marcadorEmStdout === null);
    afirmar("{$rotulo}: nenhum marcador sensivel no stderr", $marcadorEmStderr === null);
    afirmar("{$rotulo}: nenhum marcador sensivel no arquivo de log dedicado", $marcadorEmLog === null);
    afirmar(
        "{$rotulo}: log contem o contexto fixo esperado + [{$classeExcecaoEsperada}] (formato sanitizado)",
        str_contains($r['log'], $contextoEsperadoNoLog) && str_contains($r['log'], "[{$classeExcecaoEsperada}]")
    );

    return $r;
}

$idsAtendimentoLimpar = [];
$idsTotemLimpar = [];

// HMAC do fingerprint do cache vio.api.br — precisa estar presente no
// ambiente dos subprocessos que exercitam o fluxo NOVO de
// iniciarProcessamento() ate o ponto do CAS/montagem (Ponto 1), herdado via
// proc_open() a partir deste processo pai (chave FIXA/sintetica, nunca a
// chave real).
putenv('VIO_API_BR_CACHE_HMAC_VERSION=1');
putenv('VIO_API_BR_CACHE_HMAC_KEY_V1=' . str_repeat('ab', 32));
$storagePathSanit0920 = rtrim($_ENV['STORAGE_PATH'] ?? '', '/');

// ============================================================
// Ponto 1 — DocumentoController::iniciarProcessamento, catch em torno de
// `new VioApiBrClient()` (reescrito na rodada corretiva de
// migracao-vio-api-br-com-cache, 2026-09-26 — NUNCA mais
// App\Rn\VioDecodeClient). Usa tipo='crlv' fixo (ver docblock de
// $corpoVioIndisponivel acima) com um crlv.jpg de fixture real em disco.
// ============================================================
echo "\n=== Ponto 1: DocumentoController::iniciarProcessamento (init VioApiBrClient) ===\n";
$idTotemP1 = talentCriarTotemComEmpresa($pdo, 'SANIT0920_P1_' . bin2hex(random_bytes(3)), $idEmpresaQaSanit0920);
$idsTotemLimpar[] = $idTotemP1;
$idP1 = talentCriarAtendimentoExpedicaoEtapa($atendimentoDaoGlobal, $idTotemP1, 'exp_crlv');
$idsAtendimentoLimpar[] = $idP1;
$pastaP1 = 'sanit0920_p1_' . bin2hex(random_bytes(4));
$atendimentoDaoGlobal->definirPasta($idP1, $pastaP1);
@mkdir("{$storagePathSanit0920}/{$pastaP1}", 0750, true);
file_put_contents("{$storagePathSanit0920}/{$pastaP1}/crlv.jpg", 'FIXTURE_NAO_E_JPEG_REAL_SEM_VALIDACAO_DE_FORMATO');

$mensagemCruaP1 = 'VIO_API_BR_BASE_URL/VIO_API_BR_API_KEY ausentes';
$resP1 = verificarPontoSanitizado(
    'Ponto1-iniciarProcessamento-initVio',
    $scriptVioIndisponivel,
    [(string) $idTotemP1, (string) $idP1, 'crlv'],
    "iniciar-processamento (inicializar VioApiBrClient) id_atendimento={$idP1} tipo=crlv",
    'RuntimeException',
    [$mensagemCruaP1]
);
afirmar('Ponto1: HTTP 503 preservado', str_contains($resP1['stdout'], 'HTTP_CODE:503'));
@unlink("{$storagePathSanit0920}/{$pastaP1}/crlv.jpg");
@rmdir("{$storagePathSanit0920}/{$pastaP1}");

// ============================================================
// Ponto 2 — DocumentoController::iniciarProcessamento, catch em torno de
// App\Rn\DocumentoRn::calcularFingerprintVioApiBr() (DocumentoRnMarcadorThrow
// injetado) — reescrito na rodada corretiva de migracao-vio-api-br-com-cache
// (2026-09-26): o fluxo novo nunca mais chama validarCnh()/validarCrlv().
// ============================================================
echo "\n=== Ponto 2: DocumentoController::iniciarProcessamento (calcularFingerprintVioApiBr) ===\n";
$idTotemP2 = talentCriarTotemComEmpresa($pdo, 'SANIT0920_P2_' . bin2hex(random_bytes(3)), $idEmpresaQaSanit0920);
$idsTotemLimpar[] = $idTotemP2;
$idP2 = talentCriarAtendimentoExpedicaoEtapa($atendimentoDaoGlobal, $idTotemP2, 'exp_cnh');
$idsAtendimentoLimpar[] = $idP2;

$resP2 = verificarPontoSanitizado(
    'Ponto2-iniciarProcessamento-fingerprint',
    $scriptMarcadorValidar,
    [(string) $idTotemP2, (string) $idP2, 'cnh'],
    "iniciar-processamento (fingerprint) id_atendimento={$idP2} tipo=cnh",
    'RuntimeException',
    todosOsMarcadores()
);
afirmar('Ponto2: HTTP 500 preservado', str_contains($resP2['stdout'], 'HTTP_CODE:500'));

// ============================================================
// Ponto 3 — DocumentoController::preencherManual (DocumentoRnMarcadorThrow).
// ============================================================
echo "\n=== Ponto 3: DocumentoController::preencherManual ===\n";
$idTotemP3 = talentCriarTotemComEmpresa($pdo, 'SANIT0920_P3_' . bin2hex(random_bytes(3)), $idEmpresaQaSanit0920);
$idsTotemLimpar[] = $idTotemP3;
$idP3 = talentCriarAtendimentoExpedicaoEtapa($atendimentoDaoGlobal, $idTotemP3, 'exp_cnh');
$idsAtendimentoLimpar[] = $idP3;

$resP3 = verificarPontoSanitizado(
    'Ponto3-preencherManual',
    $scriptPreencherManual,
    [(string) $idTotemP3, (string) $idP3, 'cnh'],
    "preencher-manual id_atendimento={$idP3} tipo=cnh",
    'RuntimeException',
    todosOsMarcadores()
);
afirmar('Ponto3: HTTP 500 preservado', str_contains($resP3['stdout'], 'HTTP_CODE:500'));

// ============================================================
// Ponto 4 — NotaController::identificarCliente (NotaFiscalRnMarcadorThrow).
// ============================================================
echo "\n=== Ponto 4: NotaController::identificarCliente ===\n";
$idTotemP4 = talentCriarTotemComEmpresa($pdo, 'SANIT0920_P4_' . bin2hex(random_bytes(3)), $idEmpresaQaSanit0920);
$idsTotemLimpar[] = $idTotemP4;
$idP4 = criarAtendimentoTeste($pdo, $idTotemP4);
$idsAtendimentoLimpar[] = $idP4;
criarNotaTeste($pdo, $idP4, 1);

$resP4 = verificarPontoSanitizado(
    'Ponto4-identificarCliente',
    $scriptIdentificarCliente,
    [(string) $idTotemP4, (string) $idP4, '1'],
    "identificar-cliente id_atendimento={$idP4}",
    'RuntimeException',
    todosOsMarcadores()
);
afirmar('Ponto4: HTTP 500 preservado', str_contains($resP4['stdout'], 'HTTP_CODE:500'));

// ============================================================
// Ponto 5 — NotaController::definirNumero, ramo else final
// (NotaFiscalRnMarcadorThrow).
// ============================================================
echo "\n=== Ponto 5: NotaController::definirNumero (ramo else) ===\n";
$idTotemP5 = talentCriarTotemComEmpresa($pdo, 'SANIT0920_P5_' . bin2hex(random_bytes(3)), $idEmpresaQaSanit0920);
$idsTotemLimpar[] = $idTotemP5;
$idP5 = criarAtendimentoTeste($pdo, $idTotemP5);
$idsAtendimentoLimpar[] = $idP5;
criarNotaTeste($pdo, $idP5, 1);

$resP5 = verificarPontoSanitizado(
    'Ponto5-definirNumero-else',
    $scriptDefinirNumero,
    [(string) $idTotemP5, (string) $idP5, '1'],
    "definir-numero id_atendimento={$idP5} ordem=1",
    'RuntimeException',
    todosOsMarcadores()
);
afirmar('Ponto5: HTTP 500 preservado', str_contains($resP5['stdout'], 'HTTP_CODE:500'));

// ============================================================
// Prova negativa — reverte temporariamente o Ponto 2 (DocumentoController.php)
// para o formato antigo (getMessage() cru) e confirma que a mesma bateria de
// assercoes DETECTA o vazamento. Reverte em seguida e confirma hash MD5
// identico ao original (arquivo intocado ao final).
// ============================================================
echo "\n=== Prova negativa: reverter Ponto 2 para getMessage() cru ===\n";
$arquivoDocumentoController = __DIR__ . '/../../app/Controller/DocumentoController.php';
$conteudoOriginal = file_get_contents($arquivoDocumentoController);
$md5Original = md5($conteudoOriginal);

// Alvo atualizado na rodada corretiva de migracao-vio-api-br-com-cache
// (2026-09-26) — a linha sanitizada original (catch de validarCnh/
// validarCrlv) nao existe mais no fluxo novo; o equivalente hoje e o catch
// em torno de calcularFingerprintVioApiBr() (mesmo Ponto 2 acima).
$trechoSanitizado = '$this->logFalhaTecnica("iniciar-processamento (fingerprint) id_atendimento={$idAtendimento} tipo={$tipo}", $e);';
$trechoCru = "error_log('iniciar-processamento (fingerprint): falha nao prevista: ' . \$e->getMessage());";

if (!str_contains($conteudoOriginal, $trechoSanitizado)) {
    afirmar('Prova negativa: trecho sanitizado esperado encontrado no arquivo original (pre-condicao)', false);
} else {
    $conteudoComVazamento = str_replace($trechoSanitizado, $trechoCru, $conteudoOriginal);
    file_put_contents($arquivoDocumentoController, $conteudoComVazamento);

    // invalida qualquer opcache/realpath cache de um eventual processo
    // filho -- cada subprocesso php é novo, entao basta a escrita em disco.
    $resNegativo = dispararComLogDedicado(
        ['display_errors' => '1', 'error_reporting' => 'E_ALL', 'html_errors' => '0'],
        $scriptMarcadorValidar,
        [(string) $idTotemP2, (string) $idP2, 'cnh'],
        __DIR__ . '/sanit0920_provanegativa_' . bin2hex(random_bytes(3)) . '.log'
    );
    $detectouVazamentoNoLog = contemAlgumMarcador($resNegativo['log'], todosOsMarcadores()) !== null;
    afirmar(
        'Prova negativa: com o codigo revertido para getMessage() cru, a suite DETECTA marcador vazado no log (confirma que a checagem funciona de verdade)',
        $detectouVazamentoNoLog
    );

    // reverte
    file_put_contents($arquivoDocumentoController, $conteudoOriginal);
    $md5Revertido = md5(file_get_contents($arquivoDocumentoController));
    afirmar('Prova negativa: arquivo revertido com hash MD5 identico ao original', $md5Revertido === $md5Original);
}

// ============================================================
// Confirmacao de preservacao das 2 comparacoes seguras em definirNumero()
// (L293/L297) -- NAO alteradas por esta demanda -- reexecutando o roteiro
// de concorrencia ja existente (numero_nota_duplicado) e criando um caso
// direto para nota_nao_encontrada via ordem sem nota associada.
// ============================================================
echo "\n=== Regressao pontual: comparacoes seguras getMessage() em definirNumero ===\n";
$idTotemP6 = talentCriarTotemComEmpresa($pdo, 'SANIT0920_P6_' . bin2hex(random_bytes(3)), $idEmpresaQaSanit0920);
$idsTotemLimpar[] = $idTotemP6;
$idP6 = criarAtendimentoTeste($pdo, $idTotemP6);
$idsAtendimentoLimpar[] = $idP6;
criarNotaTeste($pdo, $idP6, 1);
// ordem 2 nao tem nota registrada -> buscarNotaDaOrdem() so e chamado dentro
// de identificarCliente(), NAO de definirNumero() -- para acionar
// 'nota_nao_encontrada' de fato, chama definirNumero() com ordem valida (1-5)
// mas sem nota correspondente na tabela.
$saidaNaoEncontrada = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_caso_nota_definir_numero.php')
    . ' ' . escapeshellarg((string) $idTotemP6) . ' ' . escapeshellarg((string) $idP6) . ' 3 12345 MANUAL 2>&1');
afirmar('Regressao: definirNumero() com ordem sem nota ainda responde 404 (nota_nao_encontrada, comparacao preservada)', str_contains($saidaNaoEncontrada, 'HTTP_CODE:404'));

// ============================================================
// Limpeza
// ============================================================
foreach (array_unique($idsAtendimentoLimpar) as $id) {
    $pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
foreach (array_unique($idsTotemLimpar) as $idTotem) {
    $pdo->prepare('DELETE FROM tb_rate_limit_ocr WHERE id_totem = :id')->execute(['id' => $idTotem]);
    $pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);
}

$totalTotemResidual = 0;
foreach (array_unique($idsTotemLimpar) as $idTotem) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tb_totem WHERE id_totem = :id');
    $stmt->execute(['id' => $idTotem]);
    $totalTotemResidual += (int) $stmt->fetchColumn();
}
afirmar('Limpeza: totens de teste (SANIT0920_*) removidos do banco', $totalTotemResidual === 0);

foreach ($arquivosTemporariosLimpar as $arquivo) {
    @unlink($arquivo);
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
