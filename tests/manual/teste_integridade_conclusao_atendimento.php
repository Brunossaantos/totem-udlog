<?php

/**
 * Roteiro de /02-testes da demanda integridade-conclusao-atendimento
 * (2026-09-16) — valida as 4 correcoes implementadas em /01-implementacao:
 *
 * 1. cancelar()/bloquear-excesso-notas passam a ser CAS (HTTP 409 + nenhuma
 *    alteracao no banco quando status ja e 'concluido'; comportamento
 *    inalterado para os demais estados de origem).
 * 2. concluirDigitalizacao() ganha CAS dedicado contra corrida real entre 2
 *    requisicoes quase simultaneas.
 * 3. DocumentoController::iniciarProcessamento() nunca chama
 *    Resposta::erro()/exit() dentro do escopo protegido pelo lock
 *    (GET_LOCK/RELEASE_LOCK) — RELEASE_LOCK sempre explicito antes da
 *    resposta de erro.
 * 4. NotaController ganha try/catch(\PDOException) cobrindo os trechos
 *    antes desprotegidos — resposta sanitizada, nunca vaza SQL/stack trace.
 *
 * Fixtures 100% isoladas e descartaveis (id_totem/id_atendimento sinteticos
 * dedicados a este arquivo, nunca dados reais) — limpeza por DELETE
 * explicito ao final. NUNCA chama o Talent real (TALENT_CHECKIN_ATIVO
 * ausente neste ambiente, TalentClient('','') quando instanciado), NUNCA
 * imprime de verdade, NUNCA altera app/util (so scripts em tests/manual/).
 *
 * Uso: php tests/manual/teste_integridade_conclusao_atendimento.php
 *
 * IMPORTANTE (rodada corretiva de migracao-vio-api-br-com-cache, 2026-09-26):
 * este arquivo rodava antes contra o banco de DEV compartilhado
 * `udlog_totem`, que NUNCA recebeu as migrations 015/016 desta demanda --
 * batia em "Column not found". Passa a rodar contra um banco
 * `qa_`-prefixado DESCARTAVEL PROPRIO (mesmo helper
 * tests/manual/qa_db_bootstrap.php ja usado pelas 3 suites novas desta
 * demanda), criado do zero e dropado ao final. Tambem elimina, nesta mesma
 * rodada, a dependencia de 6 subprocessos `tests/manual/_caso_*.php` NUNCA
 * versionados (.gitignore:37 -- confirmado via `git ls-files`), que tornavam
 * este arquivo IRREPRODUZIVEL a partir de um `git clone` limpo: os corpos
 * desses subprocessos (antes em _caso_cancelar.php, _caso_bloquear_excesso.php,
 * _caso_concluir_digitalizacao_cas_direto.php,
 * _caso_iniciar_processamento_vio_indisponivel.php,
 * _caso_iniciar_processamento_falha_durante_validacao.php e
 * _caso_nota_pdo_falha.php) agora sao inlinados como nowdoc e materializados
 * em tempo de execucao via qaGerarScriptTemporario() (mesmo padrao ja usado
 * por tests/manual/teste_lock_obter_lock_documento.php e
 * tests/manual/teste_sanitizacao_logs_documento_nota.php), fora de
 * tests/manual/, removidos ao final. _caso_concluir_digitalizacao.php
 * (usado no Item 6) e _fixtures_talent.php PERMANECEM como estao -- ambos ja
 * sao versionados (`git ls-files` confirma).
 */

require_once __DIR__ . '/qa_db_bootstrap.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function dispararParalelo(string $script, array $args): array
{
    $php = PHP_BINARY;
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $processos = [];
    foreach ($args as $chave => $argv) {
        $cmd = array_merge([$php, $script], $argv);
        $processos[$chave] = proc_open($cmd, $descritores, $pipes);
        $processos[$chave . '_pipes'] = $pipes;
    }

    $saidas = [];
    foreach ($args as $chave => $argv) {
        $pipes = $processos[$chave . '_pipes'];
        $saidas[$chave] = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($processos[$chave]);
    }

    return $saidas;
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

// ============================================================
// Corpos de subprocesso antes em _caso_cancelar.php, _caso_bloquear_excesso.php,
// _caso_concluir_digitalizacao_cas_direto.php,
// _caso_iniciar_processamento_vio_indisponivel.php,
// _caso_iniciar_processamento_falha_durante_validacao.php e
// _caso_nota_pdo_falha.php (nenhum versionado -- ver docblock deste
// arquivo) -- inlinados aqui como nowdoc, materializados em arquivo
// temporario por qaGerarScriptTemporario() (tests/manual/qa_db_bootstrap.php),
// removidos ao final via register_shutdown_function.
// ============================================================
$raizProjeto = dirname(__DIR__, 2);
$arquivosTemporariosLimpar = [];
register_shutdown_function(function () use (&$arquivosTemporariosLimpar) {
    foreach ($arquivosTemporariosLimpar as $arquivo) {
        @unlink($arquivo);
    }
});

$pontEnvSubprocesso = qaDbTrechoPonteEnvSubprocesso();

$corpoCancelar = <<<PHP
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Dao\OrdemColetaDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Controller\AtendimentoController;

{$pontEnvSubprocesso}

\$dotenv = Dotenv::createImmutable(%%RAIZ%%);
\$dotenv->load();

\$pdo = Conexao::obter();

\$idTotem = (int) (\$argv[1] ?? 0);
\$idAtendimento = (int) (\$argv[2] ?? 0);

\$atendimentoRn = new AtendimentoRn(new AtendimentoDao(\$pdo), new OrdemColetaClient(new OrdemColetaDao()));
\$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao(\$pdo), new AtendimentoDao(\$pdo), \$_ENV['STORAGE_PATH']);
\$controller = new AtendimentoController(\$atendimentoRn, \$talentRn, new AtendimentoNotaDao(\$pdo));

register_shutdown_function(function () {
    echo "\\nHTTP_CODE:" . http_response_code() . "\\n";
});

\$controller->cancelar(['id_atendimento' => \$idAtendimento], \$idTotem);
PHP;

$corpoBloquearExcesso = <<<PHP
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Dao\OrdemColetaDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Controller\AtendimentoController;

{$pontEnvSubprocesso}

\$dotenv = Dotenv::createImmutable(%%RAIZ%%);
\$dotenv->load();

\$pdo = Conexao::obter();

\$idTotem = (int) (\$argv[1] ?? 0);
\$idAtendimento = (int) (\$argv[2] ?? 0);

\$atendimentoRn = new AtendimentoRn(new AtendimentoDao(\$pdo), new OrdemColetaClient(new OrdemColetaDao()));
\$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao(\$pdo), new AtendimentoDao(\$pdo), \$_ENV['STORAGE_PATH']);
\$controller = new AtendimentoController(\$atendimentoRn, \$talentRn, new AtendimentoNotaDao(\$pdo));

register_shutdown_function(function () {
    echo "\\nHTTP_CODE:" . http_response_code() . "\\n";
});

\$controller->bloquearPorExcessoDeNotas(['id_atendimento' => \$idAtendimento], \$idTotem);
PHP;

$corpoConcluirDigitalizacaoCasDireto = <<<PHP
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\OrdemColetaDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;

{$pontEnvSubprocesso}

\$dotenv = Dotenv::createImmutable(%%RAIZ%%);
\$dotenv->load();

\$pdo = Conexao::obter();

\$idAtendimento = (int) (\$argv[1] ?? 0);
\$novaEtapa = \$argv[2] ?? 'cliente';

\$atendimentoRn = new AtendimentoRn(new AtendimentoDao(\$pdo), new OrdemColetaClient(new OrdemColetaDao()));

\$resultado = \$atendimentoRn->concluirDigitalizacaoNotas(\$idAtendimento, \$novaEtapa);

echo 'CAS:' . (\$resultado ? 'TRUE' : 'FALSE') . "\\n";
PHP;

$corpoVioIndisponivelItg = <<<PHP
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioApiBrCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

{$pontEnvSubprocesso}

\$dotenv = Dotenv::createImmutable(%%RAIZ%%);
\$dotenv->load();

// Fail-closed do NOVO cliente: ausencia de VIO_API_BR_BASE_URL/API_KEY forca
// `new VioApiBrClient()` a lancar RuntimeException no construtor -- nenhuma
// chamada de rede acontece.
unset(\$_ENV['VIO_API_BR_BASE_URL'], \$_ENV['VIO_API_BR_API_KEY']);
putenv('VIO_API_BR_BASE_URL');
putenv('VIO_API_BR_API_KEY');

// HMAC do fingerprint do cache -- chave FIXA/sintetica, nunca a real.
\$_ENV['VIO_API_BR_CACHE_HMAC_VERSION'] = '1';
\$_ENV['VIO_API_BR_CACHE_HMAC_KEY_V1'] = str_repeat('ab', 32);

\$pdo = Conexao::obter();

\$idTotem = (int) (\$argv[1] ?? 0);
\$idAtendimento = (int) (\$argv[2] ?? 0);
\$tipo = 'crlv';

\$atendimentoDao = new AtendimentoDao(\$pdo);
\$documentoRn = new DocumentoRn(new \App\Dao\VioCacheDao(\$pdo), \$atendimentoDao, new VioApiBrCacheDao(\$pdo));
\$controller = new DocumentoController(\$atendimentoDao, \$documentoRn, \$pdo);

\$bytesGarbage = random_bytes(40);

register_shutdown_function(function () {
    echo "\\nHTTP_CODE:" . http_response_code() . "\\n";
});

\$controller->iniciarProcessamento([
    'id_atendimento' => \$idAtendimento,
    'tipo' => \$tipo,
    'qr_bytes_base64' => base64_encode(\$bytesGarbage),
], \$idTotem);
PHP;

$corpoFalhaDuranteValidacaoItg = <<<PHP
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioApiBrCacheDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;

{$pontEnvSubprocesso}

\$dotenv = Dotenv::createImmutable(%%RAIZ%%);
\$dotenv->load();

// Cliente CONSTROI com sucesso (URL valida/HTTPS, apenas inalcancavel) -- a
// falha sob teste aqui e exclusivamente a do ENVIO (POST).
\$_ENV['VIO_API_BR_BASE_URL'] = 'https://127.0.0.1:1';
\$_ENV['VIO_API_BR_API_KEY'] = 'chave-teste-nao-real';
putenv('VIO_API_BR_BASE_URL=https://127.0.0.1:1');
putenv('VIO_API_BR_API_KEY=chave-teste-nao-real');

\$_ENV['VIO_API_BR_CACHE_HMAC_VERSION'] = '1';
\$_ENV['VIO_API_BR_CACHE_HMAC_KEY_V1'] = str_repeat('ab', 32);

\$pdo = Conexao::obter();

\$idTotem = (int) (\$argv[1] ?? 0);
\$idAtendimento = (int) (\$argv[2] ?? 0);
\$tipo = 'crlv';

\$atendimentoDao = new AtendimentoDao(\$pdo);
\$documentoRn = new DocumentoRn(new \App\Dao\VioCacheDao(\$pdo), \$atendimentoDao, new VioApiBrCacheDao(\$pdo));
\$controller = new DocumentoController(\$atendimentoDao, \$documentoRn, \$pdo);

\$bytesGarbage = random_bytes(40);

register_shutdown_function(function () {
    echo "\\nHTTP_CODE:" . http_response_code() . "\\n";
});

\$controller->iniciarProcessamento([
    'id_atendimento' => \$idAtendimento,
    'tipo' => \$tipo,
    'qr_bytes_base64' => base64_encode(\$bytesGarbage),
], \$idTotem);
PHP;

$corpoNotaPdoFalha = <<<PHP
use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\RateLimitOcrDao;
use App\Rn\NotaFiscalRn;
use App\Controller\NotaController;

{$pontEnvSubprocesso}

\$dotenv = Dotenv::createImmutable(%%RAIZ%%);
\$dotenv->load();

\$pdo = Conexao::obter();

\$metodo = \$argv[1] ?? 'algumaIdentificada';
\$idTotem = (int) (\$argv[2] ?? 0);
\$idAtendimento = (int) (\$argv[3] ?? 0);
\$ordem = (int) (\$argv[4] ?? 1);

// Conexao PDO isolada e descartavel, derrubada de proposito ANTES de
// qualquer query real feita atraves dela.
\$host = \$_ENV['DB_HOST'];
\$nome = \$_ENV['DB_NAME'];
\$porta = \$_ENV['DB_PORT'] ?? '3306';
\$dsn = "mysql:host={\$host};port={\$porta};dbname={\$nome};charset=utf8mb4";
\$pdoQuebrado = new PDO(\$dsn, \$_ENV['DB_USER'], \$_ENV['DB_PASS'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
\$idConexaoQuebrada = (int) \$pdoQuebrado->query('SELECT CONNECTION_ID()')->fetchColumn();
\$pdo->exec('KILL ' . \$idConexaoQuebrada);

\$atendimentoDaoQuebrado = new AtendimentoDao(\$pdoQuebrado);
\$notaFiscalRn = new NotaFiscalRn(new AtendimentoNotaDao(\$pdo), new ClienteDao(\$pdo));
\$controller = new NotaController(\$notaFiscalRn, \$atendimentoDaoQuebrado, new RateLimitOcrDao(\$pdo));

register_shutdown_function(function () {
    echo "\\nHTTP_CODE:" . http_response_code() . "\\n";
});

if (\$metodo === 'processar') {
    \$imagemBase64 = 'data:image/jpeg;base64,' . base64_encode(
        base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=')
    );
    \$controller->processar([
        'id_atendimento' => \$idAtendimento,
        'ordem' => \$ordem,
        'imagem' => \$imagemBase64,
    ], \$idTotem);
} else {
    \$controller->algumaIdentificada(\$idAtendimento, \$idTotem);
}
PHP;

$arquivosTemporariosLimpar[] = $scriptCancelar = qaGerarScriptTemporario($corpoCancelar, $raizProjeto, 'itg_cancelar');
$arquivosTemporariosLimpar[] = $scriptBloquearExcesso = qaGerarScriptTemporario($corpoBloquearExcesso, $raizProjeto, 'itg_bloquear');
$arquivosTemporariosLimpar[] = $scriptConcluirCasDireto = qaGerarScriptTemporario($corpoConcluirDigitalizacaoCasDireto, $raizProjeto, 'itg_cas_direto');
$arquivosTemporariosLimpar[] = $scriptVioIndisponivelItg = qaGerarScriptTemporario($corpoVioIndisponivelItg, $raizProjeto, 'itg_vio_indisponivel');
$arquivosTemporariosLimpar[] = $scriptFalhaDuranteValidacaoItg = qaGerarScriptTemporario($corpoFalhaDuranteValidacaoItg, $raizProjeto, 'itg_falha_validacao');
$arquivosTemporariosLimpar[] = $scriptNotaPdoFalha = qaGerarScriptTemporario($corpoNotaPdoFalha, $raizProjeto, 'itg_nota_pdo_falha');

$nomeBanco = null;

try {
    [$pdo, $nomeBanco] = qaDbCriar('integridade_conclusao_atendimento');
    qaDbCorrigirEnumsMigration015($pdo);
    putenv('DB_NAME=' . $nomeBanco);

    // tb_empresa nao vem semeada em schema.sql (as seeds de
    // sql/migrations/008_tb_empresa_totem_vinculo.sql sao aplicadas so no
    // banco de dev real, nunca reaplicadas aqui) -- fixture sintetica
    // propria deste banco `qa_` descartavel.
    $pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Empresa QA Integridade', '00000000000191')");
    $idEmpresaQa = (int) $pdo->lastInsertId();

$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_INTEGRIDADE_' . bin2hex(random_bytes(3)), $idEmpresaQa);
$idsAtendimentoLimpar = [];

echo "\n=== Item 1: cancelar atendimento em_andamento -> HTTP 200, status=cancelado ===\n";
$id1 = $atendimentoDao->criar($idTotem, 'expedicao', 'ITG0001');
$idsAtendimentoLimpar[] = $id1;
$saida1 = dispararSequencial($scriptCancelar, [(string) $idTotem, (string) $id1]);
echo $saida1 . "\n";
$estado1 = $atendimentoDao->buscarPorId($id1);
// Nota: Resposta::sucesso() nunca chama http_response_code() explicitamente
// (so Resposta::erro() chama) — em CLI SAPI isso faz http_response_code()
// retornar false/vazio no shutdown mesmo em sucesso real (200 e o padrao
// implicito do protocolo HTTP quando nenhum outro codigo e setado). Por
// isso a evidencia de sucesso usada aqui e o marcador "sucesso":true do
// corpo JSON (mesmo padrao ja usado pelos demais testes deste projeto,
// ex. teste_concorrencia_finalizar_checkin.php), nao a ausencia/presenca
// de HTTP_CODE.
afirmar('Item 1: HTTP 200 (sucesso":true, sem HTTP_CODE de erro)', str_contains($saida1, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida1));
afirmar('Item 1: status=cancelado no banco', $estado1['status'] === 'cancelado');

echo "\n=== Item 2: cancelar atendimento concluido -> HTTP 409, status permanece concluido ===\n";
$id2 = $atendimentoDao->criar($idTotem, 'expedicao', 'ITG0002');
$idsAtendimentoLimpar[] = $id2;
$pdo->prepare("UPDATE tb_atendimento SET status = 'concluido' WHERE id_atendimento = :id")->execute(['id' => $id2]);
$antesLinha2 = $atendimentoDao->buscarPorId($id2);
$saida2 = dispararSequencial($scriptCancelar, [(string) $idTotem, (string) $id2]);
echo $saida2 . "\n";
$depoisLinha2 = $atendimentoDao->buscarPorId($id2);
afirmar('Item 2: HTTP 409', str_contains($saida2, 'HTTP_CODE:409'));
afirmar('Item 2: status permanece concluido (confirmado por SELECT direto)', $depoisLinha2['status'] === 'concluido');
afirmar('Item 2: nenhuma coluna da linha foi alterada (comparacao completa antes/depois)', $antesLinha2 == $depoisLinha2);

echo "\n=== Item 3: bloquear excesso em atendimento em_andamento (recebimento) -> HTTP 200, status=bloqueado, etapa_atual=balcao_portaria ===\n";
$id3 = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG0003');
$idsAtendimentoLimpar[] = $id3;
$saida3 = dispararSequencial($scriptBloquearExcesso, [(string) $idTotem, (string) $id3]);
echo $saida3 . "\n";
$estado3 = $atendimentoDao->buscarPorId($id3);
afirmar('Item 3: HTTP 200 ("sucesso":true, sem HTTP_CODE de erro)', str_contains($saida3, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida3));
afirmar('Item 3: status=bloqueado no banco', $estado3['status'] === 'bloqueado');
afirmar('Item 3: etapa_atual=balcao_portaria no banco', $estado3['etapa_atual'] === 'balcao_portaria');

echo "\n=== Item 4: bloquear excesso em atendimento concluido (recebimento) -> HTTP 409, nenhuma alteracao no banco ===\n";
$id4 = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG0004');
$idsAtendimentoLimpar[] = $id4;
$pdo->prepare("UPDATE tb_atendimento SET status = 'concluido' WHERE id_atendimento = :id")->execute(['id' => $id4]);
$antesLinha4 = $atendimentoDao->buscarPorId($id4);
$saida4 = dispararSequencial($scriptBloquearExcesso, [(string) $idTotem, (string) $id4]);
echo $saida4 . "\n";
$depoisLinha4 = $atendimentoDao->buscarPorId($id4);
afirmar('Item 4: HTTP 409', str_contains($saida4, 'HTTP_CODE:409'));
afirmar('Item 4: nenhuma alteracao no banco (comparacao completa antes/depois)', $antesLinha4 == $depoisLinha4);

echo "\n=== Item 5: cancelado/bloqueado como ORIGEM preservam comportamento atual (sem regressao) ===\n";
// 5a: origem cancelado -> cancelar() -> 200, reafirma cancelado
$id5a = $atendimentoDao->criar($idTotem, 'expedicao', 'ITG005A');
$idsAtendimentoLimpar[] = $id5a;
$pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_atendimento = :id")->execute(['id' => $id5a]);
$saida5a = dispararSequencial($scriptCancelar, [(string) $idTotem, (string) $id5a]);
$estado5a = $atendimentoDao->buscarPorId($id5a);
afirmar('Item 5a: origem cancelado, cancelar() -> HTTP 200 (sem regressao)', str_contains($saida5a, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida5a));
afirmar('Item 5a: status permanece cancelado', $estado5a['status'] === 'cancelado');

// 5b: origem cancelado -> bloquear-excesso() -> 200 (mesma logica de idempotencia/sem restricao nova)
$id5b = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG005B');
$idsAtendimentoLimpar[] = $id5b;
$pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_atendimento = :id")->execute(['id' => $id5b]);
$saida5b = dispararSequencial($scriptBloquearExcesso, [(string) $idTotem, (string) $id5b]);
$estado5b = $atendimentoDao->buscarPorId($id5b);
afirmar('Item 5b: origem cancelado, bloquear-excesso() -> HTTP 200 (sem regressao)', str_contains($saida5b, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida5b));
afirmar('Item 5b: status vira bloqueado/balcao_portaria (comportamento atual mantido)', $estado5b['status'] === 'bloqueado' && $estado5b['etapa_atual'] === 'balcao_portaria');

// 5c: origem bloqueado -> cancelar() -> 200, status vira cancelado
$id5c = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG005C');
$idsAtendimentoLimpar[] = $id5c;
$pdo->prepare("UPDATE tb_atendimento SET status = 'bloqueado', etapa_atual = 'balcao_portaria' WHERE id_atendimento = :id")->execute(['id' => $id5c]);
$saida5c = dispararSequencial($scriptCancelar, [(string) $idTotem, (string) $id5c]);
$estado5c = $atendimentoDao->buscarPorId($id5c);
afirmar('Item 5c: origem bloqueado, cancelar() -> HTTP 200 (sem regressao)', str_contains($saida5c, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida5c));
afirmar('Item 5c: status vira cancelado', $estado5c['status'] === 'cancelado');

// 5d: origem bloqueado -> bloquear-excesso() -> 200, reafirma bloqueado/balcao_portaria
$id5d = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG005D');
$idsAtendimentoLimpar[] = $id5d;
$pdo->prepare("UPDATE tb_atendimento SET status = 'bloqueado', etapa_atual = 'balcao_portaria' WHERE id_atendimento = :id")->execute(['id' => $id5d]);
$saida5d = dispararSequencial($scriptBloquearExcesso, [(string) $idTotem, (string) $id5d]);
$estado5d = $atendimentoDao->buscarPorId($id5d);
afirmar('Item 5d: origem bloqueado, bloquear-excesso() -> HTTP 200 (sem regressao)', str_contains($saida5d, '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saida5d));
afirmar('Item 5d: reafirma bloqueado/balcao_portaria', $estado5d['status'] === 'bloqueado' && $estado5d['etapa_atual'] === 'balcao_portaria');

echo "\n=== Itens 6/7/8: concluirDigitalizacao() sob concorrencia REAL (2 subprocessos quase simultaneos) ===\n";

// 6/8: caminho REAL via Controller (fixture com 1 nota, sem identificacao ->
// etapa-alvo = 'cliente')
$id6 = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG0006');
$idsAtendimentoLimpar[] = $id6;
$atendimentoDao->atualizarEtapa($id6, 'digitalizacao_notas');
$notaDao->inserir($id6, 1, 'nota_01.jpg', null, null, false);
$totalNotasAntes6 = $notaDao->contarPorAtendimento($id6);

$saidasConcluir = dispararParalelo(__DIR__ . '/_caso_concluir_digitalizacao.php', [
    'a' => [(string) $idTotem, (string) $id6],
    'b' => [(string) $idTotem, (string) $id6],
]);
echo "--- saida A ---\n{$saidasConcluir['a']}\n--- saida B ---\n{$saidasConcluir['b']}\n";

$totalNotasDepois6 = $notaDao->contarPorAtendimento($id6);
$estado6 = $atendimentoDao->buscarPorId($id6);

afirmar('Item 6: os 2 processos concorrentes recebem HTTP 200 (um venceu o CAS, o outro foi idempotente)', str_contains($saidasConcluir['a'], '"sucesso":true') && str_contains($saidasConcluir['b'], '"sucesso":true') && !preg_match('/HTTP_CODE:[1-9]/', $saidasConcluir['a']) && !preg_match('/HTTP_CODE:[1-9]/', $saidasConcluir['b']));
afirmar('Item 6: os 2 processos recebem a MESMA etapa/proxima_tela (idempotencia real, nao so ausencia de erro)', str_contains($saidasConcluir['a'], '"etapa":"cliente"') && str_contains($saidasConcluir['b'], '"etapa":"cliente"'));
afirmar('Item 6: etapa_atual no banco avancou EXATAMENTE para a etapa-alvo (cliente), nunca ficou presa em digitalizacao_notas', $estado6['etapa_atual'] === 'cliente');
afirmar('Item 8: contagem de notas identica antes/depois (nenhum efeito colateral duplicado)', $totalNotasAntes6 === $totalNotasDepois6);

// 7: confirmacao de baixo nivel do CAS (rowCount() real, nao so resposta
// HTTP) — fixture separada, mesmo estado inicial, chamando
// AtendimentoRn::concluirDigitalizacaoNotas() diretamente em 2 processos
// concorrentes visando a MESMA etapa-alvo.
$id7 = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG0007');
$idsAtendimentoLimpar[] = $id7;
$atendimentoDao->atualizarEtapa($id7, 'digitalizacao_notas');

$saidasCas = dispararParalelo($scriptConcluirCasDireto, [
    'a' => [(string) $id7, 'cliente'],
    'b' => [(string) $id7, 'cliente'],
]);
echo "--- CAS direto A ---\n{$saidasCas['a']}\n--- CAS direto B ---\n{$saidasCas['b']}\n";

$aCasTrue = str_contains($saidasCas['a'], 'CAS:TRUE');
$bCasTrue = str_contains($saidasCas['b'], 'CAS:TRUE');
afirmar('Item 7: EXATAMENTE um dos dois processos teve o CAS efetivo (rowCount()>0 real, nao so resposta HTTP)', $aCasTrue xor $bCasTrue);

$estado7 = $atendimentoDao->buscarPorId($id7);
afirmar('Item 7: etapa_atual final e a etapa-alvo (uma unica transicao efetiva)', $estado7['etapa_atual'] === 'cliente');

echo "\n=== Item 9: erro na inicializacao do client de validacao e erro durante a validacao (DocumentoController, fluxo NOVO vio.api.br) ===\n";
// Reescrito na rodada corretiva de migracao-vio-api-br-com-cache
// (2026-09-26): o fluxo real de iniciarProcessamento() hoje e via
// App\Rn\VioApiBrClient, nunca mais App\Rn\VioDecodeClient. Usa tipo='crlv'
// de proposito (ver docblock dos 2 subprocessos abaixo) -- exige um arquivo
// crlv.jpg REAL em disco (a montagem da imagem acontece ANTES de
// instanciar/chamar o cliente), criado aqui pelo teste pai e removido na
// limpeza final.
$storagePath9 = rtrim($_ENV['STORAGE_PATH'] ?? '', '/');

$id9a = $atendimentoDao->criar($idTotem, 'expedicao', 'ITG009A');
$idsAtendimentoLimpar[] = $id9a;
$atendimentoDao->atualizarEtapa($id9a, 'exp_crlv');
$pasta9a = 'teste_integridade_' . bin2hex(random_bytes(4));
$atendimentoDao->definirPasta($id9a, $pasta9a);
@mkdir("{$storagePath9}/{$pasta9a}", 0750, true);
file_put_contents("{$storagePath9}/{$pasta9a}/crlv.jpg", 'FIXTURE_NAO_E_JPEG_REAL_SEM_VALIDACAO_DE_FORMATO');

$saida9a = dispararSequencial($scriptVioIndisponivelItg, [(string) $idTotem, (string) $id9a, 'crlv']);
echo "--- Item 9a (falha na inicializacao do client VioApiBrClient) ---\n{$saida9a}\n";
afirmar('Item 9a: HTTP 503 (mesma mensagem/codigo ja existente antes desta correcao)', str_contains($saida9a, 'HTTP_CODE:503'));
afirmar('Item 9a: mensagem "Servico de validacao de documento indisponivel no momento"', str_contains($saida9a, 'Servico de validacao de documento indisponivel no momento'));
$estado9a = $atendimentoDao->buscarPorId($id9a);
afirmar('Item 9a: crlv_status_processamento gravado como ERRO (falha sem ambiguidade, permite nova tentativa)', $estado9a['crlv_status_processamento'] === 'ERRO');

$id9b = $atendimentoDao->criar($idTotem, 'expedicao', 'ITG009B');
$idsAtendimentoLimpar[] = $id9b;
$atendimentoDao->atualizarEtapa($id9b, 'exp_crlv');
$pasta9b = 'teste_integridade_' . bin2hex(random_bytes(4));
$atendimentoDao->definirPasta($id9b, $pasta9b);
@mkdir("{$storagePath9}/{$pasta9b}", 0750, true);
file_put_contents("{$storagePath9}/{$pasta9b}/crlv.jpg", 'FIXTURE_NAO_E_JPEG_REAL_SEM_VALIDACAO_DE_FORMATO');

$saida9b = dispararSequencial($scriptFalhaDuranteValidacaoItg, [(string) $idTotem, (string) $id9b, 'crlv']);
echo "--- Item 9b (falha durante o envio -- conexao recusada, sem ambiguidade) ---\n{$saida9b}\n";
afirmar('Item 9b: HTTP 502 (ramo de falha de envio ao fornecedor, ver DocumentoController::iniciarProcessamento)', str_contains($saida9b, 'HTTP_CODE:502'));
afirmar('Item 9b: mensagem "Nao foi possivel validar o documento agora"', str_contains($saida9b, 'Nao foi possivel validar o documento agora'));
$estado9b = $atendimentoDao->buscarPorId($id9b);
afirmar('Item 9b: crlv_status_processamento gravado como ERRO (falha de conexao SEM ambiguidade, nunca INDETERMINADO)', $estado9b['crlv_status_processamento'] === 'ERRO');

// Limpeza das fixtures fisicas de imagem criadas para o Item 9.
@unlink("{$storagePath9}/{$pasta9a}/crlv.jpg");
@rmdir("{$storagePath9}/{$pasta9a}");
@unlink("{$storagePath9}/{$pasta9b}/crlv.jpg");
@rmdir("{$storagePath9}/{$pasta9b}");

echo "\n=== Item 10: GET_LOCK e liberado explicitamente apos erro (IS_USED_LOCK) ===\n";
// Chave do lock adicional atualizada para o prefixo do fluxo NOVO
// (App\Controller\DocumentoController::iniciarProcessamento —
// "vio_api_br_validar_{id}_{tipo}", nunca mais "vio_validar_{id}_{tipo}" do
// fluxo antigo).
$chaveLock9a = "vio_api_br_validar_{$id9a}_crlv";
$chaveLock9b = "vio_api_br_validar_{$id9b}_crlv";
$stmtLock = $pdo->prepare('SELECT IS_USED_LOCK(:chave)');
$stmtLock->execute(['chave' => $chaveLock9a]);
$usoLock9a = $stmtLock->fetchColumn();
$stmtLock->execute(['chave' => $chaveLock9b]);
$usoLock9b = $stmtLock->fetchColumn();
afirmar('Item 10: lock do cenario 9a (erro ANTES/na inicializacao) esta liberado (IS_USED_LOCK retorna NULL)', $usoLock9a === null || $usoLock9a === false);
afirmar('Item 10: lock do cenario 9b (erro DURANTE a validacao) esta liberado (IS_USED_LOCK retorna NULL)', $usoLock9b === null || $usoLock9b === false);

echo "\n=== Item 11: PDOException em NotaController retorna resposta sanitizada (HTTP 500, sem SQL/stack trace/host/credencial) ===\n";
$id11a = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG011A');
$idsAtendimentoLimpar[] = $id11a;
$atendimentoDao->atualizarEtapa($id11a, 'digitalizacao_notas');
$saida11a = dispararSequencial($scriptNotaPdoFalha, ['algumaIdentificada', (string) $idTotem, (string) $id11a]);
echo "--- Item 11a (algumaIdentificada) ---\n{$saida11a}\n";
afirmar('Item 11a: HTTP 500', str_contains($saida11a, 'HTTP_CODE:500'));
afirmar('Item 11a: mensagem generica fixa', str_contains($saida11a, 'Nao foi possivel consultar a identificacao do cliente') || str_contains($saida11a, 'Não foi possível consultar a identificação do cliente'));
afirmar('Item 11a: nunca vaza SQL/consulta', !preg_match('/SELECT|INSERT|UPDATE|FROM tb_/i', $saida11a));
afirmar('Item 11a: nunca vaza caminho de servidor/stack trace', !str_contains($saida11a, __DIR__) && !str_contains($saida11a, '.php on line') && !str_contains($saida11a, 'Stack trace'));
afirmar('Item 11a: nunca vaza credencial (usuario/senha do banco)', !str_contains($saida11a, (string) ($_ENV['DB_USER'] ?? "\0")) && !str_contains($saida11a, (string) ($_ENV['DB_PASS'] ?? "\0")));

$id11b = $atendimentoDao->criar($idTotem, 'recebimento', 'ITG011B');
$idsAtendimentoLimpar[] = $id11b;
$atendimentoDao->atualizarEtapa($id11b, 'digitalizacao_notas');
$saida11b = dispararSequencial($scriptNotaPdoFalha, ['processar', (string) $idTotem, (string) $id11b, '1']);
echo "--- Item 11b (processar) ---\n{$saida11b}\n";
afirmar('Item 11b: HTTP 500', str_contains($saida11b, 'HTTP_CODE:500'));
afirmar('Item 11b: mensagem generica fixa', str_contains($saida11b, 'Nao foi possivel registrar a nota') || str_contains($saida11b, 'Não foi possível registrar a nota'));
afirmar('Item 11b: nunca vaza SQL/consulta', !preg_match('/SELECT|INSERT|UPDATE|FROM tb_/i', $saida11b));
afirmar('Item 11b: nunca vaza caminho de servidor/stack trace', !str_contains($saida11b, __DIR__) && !str_contains($saida11b, '.php on line') && !str_contains($saida11b, 'Stack trace'));

// ============================================================
// Limpeza — remove TODOS os residuos criados por este arquivo
// ============================================================
foreach (array_unique($idsAtendimentoLimpar) as $id) {
    $pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

$totalAtendimentosResiduais = (int) $pdo->query('SELECT COUNT(*) FROM tb_atendimento WHERE placa LIKE \'ITG%\'')->fetchColumn();
afirmar('Limpeza: nenhum atendimento residual com placa de teste (ITG%) permanece no banco', $totalAtendimentosResiduais === 0);

$stmtTotemResidual = $pdo->prepare('SELECT COUNT(*) FROM tb_totem WHERE id_totem = :id');
$stmtTotemResidual->execute(['id' => $idTotem]);
afirmar('Limpeza: totem de teste removido do banco', (int) $stmtTotemResidual->fetchColumn() === 0);
} finally {
    putenv('DB_NAME');
    if ($nomeBanco !== null) {
        qaDbDropar($nomeBanco);
        echo "\n(banco de teste {$nomeBanco} dropado)\n";
    }
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
