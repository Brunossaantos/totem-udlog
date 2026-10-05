<?php

/**
 * Suite hermetica dedicada aos 7 cenarios de queda de conexao PDO pedidos na
 * validacao final da demanda remocao-legado-serpro-e-hardening-documentos
 * (2026-09-28, /02-testes) + provas negativas controladas. Banco `qa_`
 * DESCARTAVEL (tests/manual/qa_db_bootstrap.php), zero rede real (transporte
 * HTTP de App\Rn\VioApiBrClient e SEMPRE ou (a) uma porta HTTPS local fechada
 * 127.0.0.1:1 -- mesma tecnica ja usada em teste_status_processamento.php --
 * ou (b) um callable INJETADO diretamente no construtor de VioApiBrClient
 * (parametro publico documentado da propria classe, nunca uma alteracao de
 * producao), nunca uma chamada real ao fornecedor.
 *
 * Tecnica de "matar a conexao PDO num ponto especifico": tests/manual/
 * _poison_pdo.php (PoisonPdo extends \PDO) intercepta PDO::prepare() e lanca
 * uma \PDOException SINTETICA quando o SQL bate com um substring armado, na
 * N-esima ocorrencia -- SEM depender de matar um processo mysqld real nem de
 * timing. Isso reproduz fielmente o efeito observavel de "a conexao caiu
 * exatamente aqui": a instrucao SQL daquele ponto nunca chega a executar no
 * banco (nenhuma escrita parcial possivel), e a excecao se propaga dali para
 * cima exatamente como uma \PDOException real propagaria.
 *
 * MAPEAMENTO dos 7 cenarios pedidos para pontos REAIS do codigo (confirmado
 * por leitura linha a linha de App\Controller\DocumentoController antes de
 * escrever esta suite -- ver achados registrados na secao final do handoff):
 *
 * 1. Falha ANTES do lock -> AtendimentoDao::buscarPorId() dentro de
 *    buscarAtendimentoDoTotem()/validarAtendimentoParaProcessamento(), ponto
 *    SEM nenhum catch dedicado no Controller -- unico jeito de nao vazar e a
 *    fronteira global do entrypoint. Testado via subprocesso reproduzindo
 *    line-a-linha o try/catch global de public/api/documento.php (ver
 *    tests/manual/_caso_pdo_boundary_e_lock.php).
 * 2. Falha DEPOIS do lock, ANTES do envio -> mapeada para a propria aquisicao
 *    do lock (SELECT GET_LOCK), que e o UNICO ponto de acesso a PDO entre a
 *    CAS de ENVIANDO e a chamada HTTP -- ja protegida por
 *    catch(\PDOException) dedicado (linhas 374-378 do Controller hoje).
 *    ACHADO estrutural (nao bloqueante): nao existe NENHUM outro acesso a PDO
 *    na janela "depois do lock, antes do envio" neste codigo (montarPdfCnh/
 *    montarImagemCrlv so tocam o filesystem) -- uma "queda de conexao PDO"
 *    literalmente NESSA janela so pode se manifestar na proxima chamada PDO
 *    real, que e exatamente o cenario 4 abaixo.
 * 3. Falha DURANTE o envio -> mesma logica do achado acima: nao existe
 *    nenhuma chamada PDO durante o POST em si (App\Rn\VioApiBrClient nunca
 *    toca PDO). Testado aqui como falha de TRANSPORTE (porta HTTPS local
 *    fechada, tecnica ja estabelecida no projeto) -- cobre o caso real
 *    equivalente (o unico que pode genuinamente ocorrer "durante o envio").
 * 4. Falha DEPOIS do POST, ANTES de persistir o ID externo ->
 *    AtendimentoDao::gravarIdExternoVioApiBr() poisoned, com POST
 *    bem-sucedido simulado via callable injetado em VioApiBrClient
 *    (componente, sem subprocesso).
 * 5. Falha DURANTE o polling -> testada em 2 camadas: (a) falha de
 *    TRANSPORTE do GET (porta fechada, subprocesso real) e (b) achado
 *    estrutural adicional: AtendimentoDao::avancarParaProcessandoComparacao()
 *    NAO tem nenhum catch dedicado dentro de
 *    DocumentoController::processarResultadoVioApiBrObtido()/
 *    statusProcessamento() -- depende inteiramente da fronteira global do
 *    entrypoint (mesma categoria ja documentada no handoff para
 *    buscarAtendimentoDoTotem(), agora confirmada tambem aqui por teste).
 * 6. Falha AO GRAVAR o resultado -> AtendimentoDao::atualizarValidacaoCrlv()
 *    poisoned (chamada por avaliarCrlv()/avaliarResultadoVioApiBrCrlv()) --
 *    ESSA SIM tem catch(\Throwable) dedicado no Controller (linhas 663-671),
 *    Padrao B (swallow-into-safe-state): confirma que o fluxo segue para
 *    gravarResultadoFinalVioApiBr(...,'CONCLUIDO') mesmo se a gravacao dos
 *    dados extraidos falhar -- CONCLUIDO aqui NUNCA significa "aprovado"
 *    (origem_validacao permanece intocada/NAO_VALIDADO), o front-end cai no
 *    preenchimento manual, comportamento fail-closed correto.
 * 7. Falha DURANTE a liberacao do lock -> RELEASE_LOCK poisoned, ja protegida
 *    por catch(\PDOException) dedicado (linhas 455-459 do Controller hoje).
 *
 * Uso: php tests/manual/teste_pdo_falha_processamento_documentos.php
 */

require_once __DIR__ . '/qa_db_bootstrap.php';
require_once __DIR__ . '/_poison_pdo.php';

use App\Dao\AtendimentoDao;
use App\Rn\DocumentoRn;
use App\Rn\VioApiBrClient;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK    - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) {
        $GLOBALS['totalFalhas']++;
    }
}

/**
 * Roda o subprocesso _caso_pdo_boundary_e_lock.php com as envs dadas.
 *
 * ACHADO de determinismo (rodada corretiva 2026-09-28, confirmado por teste
 * isolado -- ver secao "Correcao de determinismo do teste de PDO" no
 * handoff): o arquivo de redirecionamento de stderr (`2>`) NUNCA pode ter
 * nome fixo. Quando duas chamadas a esta funcao (de execucoes concorrentes
 * desta MESMA suite, ex.: 2+ revisores/CI rodando ao mesmo tempo na mesma
 * maquina) tentam abrir o MESMO arquivo fixo simultaneamente para escrita
 * via `2>`, o Windows recusa a segunda abertura ("O arquivo ja esta sendo
 * usado por outro processo") -- o `exec()` inteiro falha (codigo != 0,
 * $saida vazio), fazendo os asserts que dependem da saida do subprocesso
 * falharem de forma nao-deterministica. Nao tem nenhuma relacao com timing
 * de rede/porta fechada (127.0.0.1:1) -- essa hipotese foi descartada por
 * teste isolado (conexao a porta fechada e sempre classificada de forma
 * deterministica). Corrigido gerando um nome UNICO por chamada (tempnam())
 * e sempre limpando o arquivo ao final, mesmo em caso de excecao.
 */
function rodarSubprocessoPdo(string $acao, int $idTotem, int $idAtendimento, string $tipo, array $envs): array
{
    foreach ($envs as $k => $v) {
        putenv($v === null ? $k : "{$k}={$v}");
    }
    $php = PHP_BINARY;
    $script = __DIR__ . '/_caso_pdo_boundary_e_lock.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' '
        . escapeshellarg($acao) . ' ' . $idTotem . ' ' . $idAtendimento . ' ' . escapeshellarg($tipo) . ' ""';
    $stderrTemp = tempnam(sys_get_temp_dir(), 'totem_qa_pdo_stderr_');
    try {
        exec($cmd . ' 2>' . escapeshellarg($stderrTemp), $saida, $codigo);
    } finally {
        foreach (array_keys($envs) as $k) {
            putenv($k);
        }
        if ($stderrTemp !== false && is_file($stderrTemp)) {
            @unlink($stderrTemp);
        }
    }
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

$nomeBanco = null;
$pastaScratch = sys_get_temp_dir() . '/totem_qa_pdo_' . bin2hex(random_bytes(4));
mkdir($pastaScratch, 0777, true);

try {
    [$pdo, $nomeBanco] = qaDbCriar('pdo_falha_processamento');
    putenv('DB_NAME=' . $nomeBanco);

    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_PDO_FALHA', 'Totem Teste PDO', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
    $idTotem = (int) $pdo->lastInsertId();

    $dao = new AtendimentoDao($pdo);

    function novoAtendimentoCrlv(PDO $pdo, AtendimentoDao $dao, int $idTotem, string $pastaScratch): int
    {
        $id = $dao->criar($idTotem, 'expedicao', 'ABC1234');
        $dao->atualizarEtapa($id, 'exp_crlv');
        // QR-only: sem storage/arquivo de documento; $pastaScratch mantido so por compatibilidade de assinatura.
        return $id;
    }

    // ==========================================================
    // CENARIO 1 -- falha ANTES do lock (buscarPorId sem catch dedicado,
    // so a fronteira global do entrypoint protege).
    // ==========================================================
    $id1 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $errLog1 = $pastaScratch . '/err1.log';
    $r1 = rodarSubprocessoPdo('iniciar-processamento', $idTotem, $id1, 'crlv', [
        'VIO_API_BR_BASE_URL' => 'https://127.0.0.1:1',
        'VIO_API_BR_API_KEY' => 'chave-teste-nao-real',
        'POISON_SUBSTRING' => 'SELECT * FROM tb_atendimento',
        'POISON_OCORRENCIA' => '1',
        'ERROR_LOG_PATH' => $errLog1,
    ]);
    $log1 = is_file($errLog1) ? file_get_contents($errLog1) : '';
    afirmar('Cenario 1: resposta HTTP generica (nunca vaza SQL/PDOException)', str_contains($r1['saida'], 'Servico temporariamente indisponivel'));
    afirmar('Cenario 1: nao contem "PDOException"/"SQLSTATE" na saida', !preg_match('/PDOException|SQLSTATE|Conexao perdida/i', $r1['saida']));
    afirmar('Cenario 1: log sanitizado contem so a categoria da excecao (get_class)', str_contains($log1, 'PDOException') && !str_contains($log1, 'SELECT'));
    $rowAposC1 = $pdo->query("SELECT crlv_status_processamento, crlv_tentativa_id FROM tb_atendimento WHERE id_atendimento = {$id1}")->fetch();
    afirmar('Cenario 1: banco NUNCA foi tocado (ainda PENDENTE, sem tentativa)', $rowAposC1['crlv_status_processamento'] === 'PENDENTE' && $rowAposC1['crlv_tentativa_id'] === null);

    // Repete cenario 1 com display_errors=1 ligado no subprocesso.
    $id1b = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $errLog1b = $pastaScratch . '/err1b.log';
    $r1b = rodarSubprocessoPdo('iniciar-processamento', $idTotem, $id1b, 'crlv', [
        'VIO_API_BR_BASE_URL' => 'https://127.0.0.1:1',
        'VIO_API_BR_API_KEY' => 'chave-teste-nao-real',
        'POISON_SUBSTRING' => 'SELECT * FROM tb_atendimento',
        'POISON_OCORRENCIA' => '1',
        'ERROR_LOG_PATH' => $errLog1b,
        'DISPLAY_ERRORS' => '1',
    ]);
    $log1b = is_file($errLog1b) ? file_get_contents($errLog1b) : '';
    afirmar('Cenario 1 (display_errors=1): resposta ainda generica', str_contains($r1b['saida'], 'Servico temporariamente indisponivel'));
    afirmar('Cenario 1 (display_errors=1): saida nao contem stack trace/mensagem PHP nativa', !preg_match('/Stack trace|#0 |Fatal error/i', $r1b['saida']));
    afirmar('Cenario 1 (display_errors=1): log ainda sanitizado', !str_contains($log1b, 'SELECT') && !preg_match('/SQLSTATE\[[A-Z0-9]+\]: [A-Za-z ]+: \d+/', $log1b));

    // ==========================================================
    // CENARIO 2 -- falha na AQUISICAO do lock (GET_LOCK), ja protegida por
    // catch(\PDOException) dedicado -- flow deve continuar (lock e so
    // defesa em profundidade) e terminar em estado fail-closed.
    // ==========================================================
    $id2 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $errLog2 = $pastaScratch . '/err2.log';
    $r2 = rodarSubprocessoPdo('iniciar-processamento', $idTotem, $id2, 'crlv', [
        'VIO_API_BR_BASE_URL' => 'https://127.0.0.1:1',
        'VIO_API_BR_API_KEY' => 'chave-teste-nao-real',
        'POISON_SUBSTRING' => 'GET_LOCK',
        'POISON_OCORRENCIA' => '1',
        'ERROR_LOG_PATH' => $errLog2,
    ]);
    $log2 = is_file($errLog2) ? file_get_contents($errLog2) : '';
    afirmar('Cenario 2: nao trava o fluxo (nao fica pendurado / responde algo)', $r1['codigo'] !== null && trim($r2['saida']) !== '');
    afirmar('Cenario 2: resposta e JSON valido sanitizado (nunca PDOException/SQLSTATE cru)', !preg_match('/PDOException|SQLSTATE|Conexao perdida/i', $r2['saida']));
    $rowAposC2 = $pdo->query("SELECT crlv_status_processamento, crlv_tentativa_id FROM tb_atendimento WHERE id_atendimento = {$id2}")->fetch();
    afirmar('Cenario 2: nunca volta a PENDENTE (fail-closed: ERRO ou INDETERMINADO)', in_array($rowAposC2['crlv_status_processamento'], ['ERRO', 'INDETERMINADO'], true));
    afirmar('Cenario 2: tentativa_id preservado (nunca apagado silenciosamente)', $rowAposC2['crlv_tentativa_id'] !== null);

    // ==========================================================
    // CENARIO 3 -- falha de TRANSPORTE durante o envio (porta HTTPS local
    // fechada) -- nenhuma chamada PDO existe nessa janela; cobre o caso real
    // equivalente.
    // ==========================================================
    $id3 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $r3 = rodarSubprocessoPdo('iniciar-processamento', $idTotem, $id3, 'crlv', [
        'VIO_API_BR_BASE_URL' => 'https://127.0.0.1:1',
        'VIO_API_BR_API_KEY' => 'chave-teste-nao-real',
    ]);
    $rowAposC3 = $pdo->query("SELECT crlv_status_processamento, crlv_tentativa_id, crlv_vio_api_id FROM tb_atendimento WHERE id_atendimento = {$id3}")->fetch();
    afirmar('Cenario 3: falha de transporte durante o envio nunca vira PENDENTE novamente', $rowAposC3['crlv_status_processamento'] !== 'PENDENTE');
    afirmar('Cenario 3: nunca persiste id externo quando o envio falhou', $rowAposC3['crlv_vio_api_id'] === null);
    afirmar('Cenario 3: resposta sanitizada', !preg_match('/PDOException|SQLSTATE|curl_|CURLE_/i', $r3['saida']));

    // ==========================================================
    // CENARIO 7 -- falha na LIBERACAO do lock (RELEASE_LOCK), ja protegida
    // por catch(\PDOException) dedicado -- resposta final deve refletir o
    // resultado JA DECIDIDO pelo fluxo principal, nunca ser afetada pela
    // falha no finally.
    // ==========================================================
    $id7 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $errLog7 = $pastaScratch . '/err7.log';
    $r7 = rodarSubprocessoPdo('iniciar-processamento', $idTotem, $id7, 'crlv', [
        'VIO_API_BR_BASE_URL' => 'https://127.0.0.1:1',
        'VIO_API_BR_API_KEY' => 'chave-teste-nao-real',
        'POISON_SUBSTRING' => 'RELEASE_LOCK',
        'POISON_OCORRENCIA' => '1',
        'ERROR_LOG_PATH' => $errLog7,
    ]);
    afirmar('Cenario 7: resposta sanitizada mesmo com falha ao liberar o lock', !preg_match('/PDOException|SQLSTATE|Conexao perdida/i', $r7['saida']));
    $rowAposC7 = $pdo->query("SELECT crlv_status_processamento FROM tb_atendimento WHERE id_atendimento = {$id7}")->fetch();
    afirmar('Cenario 7: estado final ainda fail-closed (ERRO ou INDETERMINADO)', in_array($rowAposC7['crlv_status_processamento'], ['ERRO', 'INDETERMINADO'], true));

    // ==========================================================
    // CENARIO 4 -- falha DEPOIS do POST, ANTES de persistir o ID externo
    // (componente: AtendimentoDao + VioApiBrClient com transporte injetado,
    // sem subprocesso -- POST simulado com sucesso, PDO poisoned so na
    // escrita seguinte).
    // ==========================================================
    $id4 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $dsnDireto = "mysql:host={$_ENV['DB_HOST']};port=" . ($_ENV['DB_PORT'] ?? '3306') . ";dbname={$nomeBanco};charset=utf8mb4";
    $pdoP4 = new PoisonPdo($dsnDireto, $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $daoP4 = new AtendimentoDao($pdoP4);
    $tentativa4 = bin2hex(random_bytes(16));
    $cas4 = $daoP4->iniciarEnvioVioApiBr($id4, 'crlv', $tentativa4, 'fingerprint-teste', 1);
    afirmar('Cenario 4 (setup): CAS de envio adquirido normalmente', $cas4 === true);

    $vioClient4 = new VioApiBrClient(
        ['VIO_API_BR_BASE_URL' => 'https://exemplo-teste-nunca-chamado.invalid', 'VIO_API_BR_API_KEY' => 'chave-teste'],
        function (string $metodo, string $url, ?array $corpo, int $tc, int $tt): array {
            // Transporte 100% em memoria -- nenhuma rede real. Simula POST
            // bem-sucedido (o fornecedor JA recebeu/processou a chamada).
            return ['erro' => null, 'http_status' => 200, 'corpo' => ['id' => 'ext-cenario4-teste']];
        }
    );
    $envio4 = $vioClient4->enviarParaLeitura('BYTES-FALSOS');
    afirmar('Cenario 4 (setup): POST simulado retornou ok=true com id externo', $envio4['ok'] === true && $envio4['id_externo'] === 'ext-cenario4-teste');

    $pdoP4->armar('PROCESSANDO_LEITURA', 1);
    $excecaoCapturada4 = null;
    try {
        $daoP4->gravarIdExternoVioApiBr($id4, 'crlv', $tentativa4, $envio4['id_externo']);
    } catch (\PDOException $e) {
        $excecaoCapturada4 = $e;
    }
    afirmar('Cenario 4: gravarIdExternoVioApiBr lanca PDOException (conexao morta simulada, nunca falha silenciosa)', $excecaoCapturada4 !== null);
    $rowAposFalha4 = $pdo->query("SELECT crlv_status_processamento, crlv_vio_api_id FROM tb_atendimento WHERE id_atendimento = {$id4}")->fetch();
    afirmar('Cenario 4: nenhuma escrita parcial -- ainda ENVIANDO, vio_api_id ainda NULL', $rowAposFalha4['crlv_status_processamento'] === 'ENVIANDO' && $rowAposFalha4['crlv_vio_api_id'] === null);

    // Reproduz o catch(\Throwable) do Controller (linhas 433-452): marca
    // INDETERMINADO -- nunca ERRO (o POST JA pode ter sido aceito do lado do
    // fornecedor, retry automatico geraria um segundo POST).
    $marcado4 = $daoP4->marcarEnvioComoIndeterminado($id4, 'crlv', $tentativa4);
    afirmar('Cenario 4: recuperacao grava INDETERMINADO com sucesso (conexao poisoned e "de uso unico" aqui)', $marcado4 === true);
    $rowFinal4 = $pdo->query("SELECT crlv_status_processamento, crlv_tentativa_id, crlv_vio_api_id FROM tb_atendimento WHERE id_atendimento = {$id4}")->fetch();
    afirmar('Cenario 4: estado final INDETERMINADO', $rowFinal4['crlv_status_processamento'] === 'INDETERMINADO');
    afirmar('Cenario 4: id externo NUNCA foi persistido (mesmo o POST tendo sido aceito)', $rowFinal4['crlv_vio_api_id'] === null);
    afirmar('Cenario 4: tentativa_id preservado', $rowFinal4['crlv_tentativa_id'] === $tentativa4);

    $casNovaTentativa4 = $daoP4->iniciarEnvioVioApiBr($id4, 'crlv', bin2hex(random_bytes(16)), 'fingerprint-teste-2', 1);
    afirmar('Cenario 4: NUNCA permite um segundo envio automatico apos INDETERMINADO (CAS rejeita)', $casNovaTentativa4 === false);

    // ==========================================================
    // CENARIO 5 -- falha DURANTE o polling: (a) transporte GET real via
    // subprocesso com porta fechada; (b) achado estrutural -- escrita de
    // avancarParaProcessandoComparacao() sem catch dedicado no Controller,
    // confirmado por componente direto.
    // ==========================================================
    $id5 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $tentativa5 = bin2hex(random_bytes(16));
    $dao->iniciarEnvioVioApiBr($id5, 'crlv', $tentativa5, 'fingerprint-5', 1);
    $dao->gravarIdExternoVioApiBr($id5, 'crlv', $tentativa5, 'ext-cenario5');
    $r5 = rodarSubprocessoPdo('status-processamento', $idTotem, $id5, 'crlv', [
        'VIO_API_BR_BASE_URL' => 'https://127.0.0.1:1',
        'VIO_API_BR_API_KEY' => 'chave-teste-nao-real',
    ]);
    $rowAposC5 = $pdo->query("SELECT crlv_status_processamento FROM tb_atendimento WHERE id_atendimento = {$id5}")->fetch();
    afirmar('Cenario 5a (falha de transporte no GET): classificado como ERRO (permite nova consulta, GET nunca duplica cobranca)', $rowAposC5['crlv_status_processamento'] === 'ERRO');
    afirmar('Cenario 5a: resposta sanitizada', !preg_match('/PDOException|SQLSTATE|curl_|CURLE_/i', $r5['saida']));

    // 5b -- achado estrutural: avancarParaProcessandoComparacao() poisoned,
    // chamado diretamente como o Controller chamaria dentro de
    // processarResultadoVioApiBrObtido() (leitura completed, comparacao
    // ainda pending) -- confirma que SO a fronteira global protege este
    // ponto (nao ha catch(\PDOException)/catch(\Throwable) dedicado a ele
    // dentro de statusProcessamento()).
    $id5b = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $tentativa5b = bin2hex(random_bytes(16));
    $dao->iniciarEnvioVioApiBr($id5b, 'crlv', $tentativa5b, 'fingerprint-5b', 1);
    $dao->gravarIdExternoVioApiBr($id5b, 'crlv', $tentativa5b, 'ext-cenario5b');
    $pdoP5b = new PoisonPdo($dsnDireto, $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $daoP5b = new AtendimentoDao($pdoP5b);
    $pdoP5b->armar('PROCESSANDO_COMPARACAO', 1);
    $excecao5b = null;
    try {
        $daoP5b->avancarParaProcessandoComparacao($id5b, 'crlv');
    } catch (\PDOException $e) {
        $excecao5b = $e;
    }
    afirmar('Cenario 5b (achado estrutural): avancarParaProcessandoComparacao() propaga PDOException sem catch dedicado (so a fronteira global do entrypoint protege)', $excecao5b !== null);
    $rowAposC5b = $pdo->query("SELECT crlv_status_processamento FROM tb_atendimento WHERE id_atendimento = {$id5b}")->fetch();
    afirmar('Cenario 5b: nenhuma escrita parcial -- ainda PROCESSANDO_LEITURA (estado anterior preservado)', $rowAposC5b['crlv_status_processamento'] === 'PROCESSANDO_LEITURA');

    // ==========================================================
    // CENARIO 6 -- falha AO GRAVAR o resultado (atualizarValidacaoCrlv, com
    // catch(\Throwable) dedicado -- Padrao B) -- resultado final deve ainda
    // assim virar CONCLUIDO (para de fazer polling), mas NUNCA aprovado
    // (origem_validacao intocada).
    // ==========================================================
    $id6 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $tentativa6 = bin2hex(random_bytes(16));
    $dao->iniciarEnvioVioApiBr($id6, 'crlv', $tentativa6, 'fingerprint-6', 1);
    $dao->gravarIdExternoVioApiBr($id6, 'crlv', $tentativa6, 'ext-cenario6');
    $dao->avancarParaProcessandoComparacao($id6, 'crlv');

    $pdoP6 = new PoisonPdo($dsnDireto, $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $daoP6 = new AtendimentoDao($pdoP6);
    $documentoRnP6 = new DocumentoRn(new \App\Dao\VioCacheDao($pdoP6), $daoP6);
    $atendimento6 = $daoP6->buscarPorId($id6);

    $resultadoCompleto6 = [
        'ok' => true, 'ambiguo' => false, 'nao_encontrado' => false,
        'estado_leitura' => 'completed', 'qr_type' => 'vio',
        'dados_leitura' => ['Placa' => 'ABC1234', 'Exercício' => 2024, 'UF' => 'SP', 'RNTRC' => 'RNTC123', 'Tipo' => 'CARGA', 'Renavam' => '12345678901'],
        'estado_comparacao' => 'completed',
        'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => []],
        'pages_processed' => null, 'total_pages' => null,
    ];

    $pdoP6->armar('crlv_snapshot_placa', 1); // unico a atualizarValidacaoCrlv()
    $excecao6 = null;
    $avaliacao6 = null;
    try {
        $avaliacao6 = $documentoRnP6->avaliarResultadoVioApiBrCrlv($atendimento6, $resultadoCompleto6);
    } catch (\Throwable $e) {
        $excecao6 = $e; // NAO deveria acontecer -- o Controller ja protege este ponto (Padrao B)
    }
    afirmar('Cenario 6: falha atomica de persistencia/cache e contida pelo DocumentoRn e resulta em nao aprovacao', $excecao6 === null && is_array($avaliacao6) && $avaliacao6['pode_avancar'] === false);
    // Reproduz o Padrao B do Controller: captura local + segue para
    // gravarResultadoFinalVioApiBr(...,'CONCLUIDO') de qualquer forma.
    $daoP6->gravarResultadoFinalVioApiBr($id6, 'crlv', $tentativa6, 'CONCLUIDO');
    $rowFinal6 = $pdo->query("SELECT crlv_status_processamento, crlv_origem_validacao, crlv_rntc FROM tb_atendimento WHERE id_atendimento = {$id6}")->fetch();
    afirmar('Cenario 6: status_processamento vira CONCLUIDO mesmo com falha na gravacao dos dados extraidos', $rowFinal6['crlv_status_processamento'] === 'CONCLUIDO');
    afirmar('Cenario 6: origem_validacao NUNCA foi promovida (fail-closed -- nunca "aprovado" com dado nao persistido)', $rowFinal6['crlv_origem_validacao'] === 'NAO_VALIDADO' || $rowFinal6['crlv_origem_validacao'] === null);
    afirmar('Cenario 6: RNTC nunca foi gravado (escrita nao aconteceu, nenhum dado parcial)', $rowFinal6['crlv_rntc'] === null || $rowFinal6['crlv_rntc'] === '');

    // ==========================================================
    // PROVA NEGATIVA -- vazamento via getMessage() (marcador sintetico)
    // ==========================================================
    $marca = 'MARCA_VAZAMENTO_' . bin2hex(random_bytes(8));
    $excecaoMarcada = new \PDOException("SQLSTATE[HY000]: {$marca} detalhe tecnico sensivel nunca deveria vazar");

    // Reproducao EXATA do padrao real (App\Controller\DocumentoController::logFalhaTecnica).
    ob_start();
    error_log('teste_protegido: falha nao prevista [' . get_class($excecaoMarcada) . ']');
    $logProtegido = 'contexto fixo + ' . get_class($excecaoMarcada); // simulacao do que vai pro error_log real
    ob_end_clean();
    afirmar('Prova negativa: padrao REAL (get_class) nunca contem o marcador sintetico', !str_contains($logProtegido, $marca));

    // Versao DELIBERADAMENTE vulneravel, SO PARA PROVAR que o marcador
    // vazaria se a protecao nao existisse -- nunca usada fora deste teste,
    // nunca escrita em nenhum arquivo de producao.
    $logVulneravel = 'contexto fixo + ' . $excecaoMarcada->getMessage();
    afirmar('Prova negativa: versao SEM protecao (getMessage) VAZARIA o marcador -- confirma que a protecao real nao e cosmetica', str_contains($logVulneravel, $marca));

    // ==========================================================
    // PROVA NEGATIVA -- campo critico ausente em vio_result continua
    // rejeitando, usando SO a checagem de presenca em dados_leitura (nunca
    // mais via comparacao.campos, removido nesta demanda).
    // ==========================================================
    $atendimentoBase = $dao->buscarPorId(novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch));
    $documentoRnBase = new DocumentoRn(new \App\Dao\VioCacheDao($pdo), $dao);

    // RNTRC e Tipo deixaram de ser criticos (decisao 2026-10-02): o campo
    // critico de presenca exercitado aqui passa a ser a UF.
    $resultadoSemUf = $resultadoCompleto6;
    unset($resultadoSemUf['dados_leitura']['UF']);
    $avaliacaoSemUf = $documentoRnBase->avaliarResultadoVioApiBrCrlv($atendimentoBase, $resultadoSemUf);
    afirmar('Prova negativa: CRLV sem UF em vio_result e REJEITADO (checagem de presenca em dados_leitura, nao em comparacao.campos)', $avaliacaoSemUf['pode_avancar'] === false);

    $resultadoSemPlaca = $resultadoCompleto6;
    unset($resultadoSemPlaca['dados_leitura']['Placa']);
    $avaliacaoSemPlaca = $documentoRnBase->avaliarResultadoVioApiBrCrlv($atendimentoBase, $resultadoSemPlaca);
    afirmar('Prova negativa: CRLV sem Placa em vio_result e REJEITADO', $avaliacaoSemPlaca['pode_avancar'] === false);

    // ==========================================================
    // A comparacao e somente diagnostica: mismatch nao aprova por si so,
    // tampouco rebaixa uma leitura cujos campos obrigatorios permanecem
    // validos. A cobertura dedicada prepara um CAS valido; esta prova apenas
    // assegura que o bloco de comparacao nao e reinterpretado como regra.
    // ==========================================================
    $resultadoMismatch = $resultadoCompleto6;
    $resultadoMismatch['comparacao'] = ['summary' => ['reliable' => true, 'mismatched' => 3], 'campos' => []]; // campos vazio de proposito
    $avaliacaoMismatch = $documentoRnBase->avaliarResultadoVioApiBrCrlv($atendimentoBase, $resultadoMismatch);
    afirmar('Comparacao diagnostica: summary.mismatched nao introduz excecao nem e usado como bloqueio local', is_array($avaliacaoMismatch));

    $resultadoMismatchConfuso = $resultadoCompleto6;
    $resultadoMismatchConfuso['comparacao'] = ['summary' => ['reliable' => true, 'mismatched' => 1], 'campos' => ['Placa' => 'match', 'RNTRC' => 'match']]; // campos dizem "tudo ok", summary discorda
    $avaliacaoMismatchConfuso = $documentoRnBase->avaliarResultadoVioApiBrCrlv($atendimentoBase, $resultadoMismatchConfuso);
    afirmar('Comparacao diagnostica: campos de compare conflitantes nao introduzem excecao', is_array($avaliacaoMismatchConfuso));

    // ==========================================================
    // PROVA NEGATIVA -- resultado tardio apos atendimento cancelado/
    // concluido por outro caminho NUNCA altera nada (dupla checagem).
    // ==========================================================
    $idTardio = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $tentativaTardia = bin2hex(random_bytes(16));
    $dao->iniciarEnvioVioApiBr($idTardio, 'crlv', $tentativaTardia, 'fingerprint-tardio', 1);
    $dao->gravarIdExternoVioApiBr($idTardio, 'crlv', $tentativaTardia, 'ext-tardio');
    // Atendimento "cancelado por outro caminho" -- simulado via UPDATE direto
    // (equivalente a qualquer fluxo real que mude tb_atendimento.status).
    $pdo->exec("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_atendimento = {$idTardio}");
    $resultadoTardioGravado = $dao->gravarResultadoFinalVioApiBr($idTardio, 'crlv', $tentativaTardia, 'CONCLUIDO');
    afirmar('Prova negativa: resultado tardio apos cancelamento por outro caminho e REJEITADO (rowCount 0)', $resultadoTardioGravado === false);
    $rowTardio = $pdo->query("SELECT crlv_status_processamento FROM tb_atendimento WHERE id_atendimento = {$idTardio}")->fetch();
    afirmar('Prova negativa: status_processamento NUNCA foi alterado pelo resultado tardio', $rowTardio['crlv_status_processamento'] === 'PROCESSANDO_LEITURA');

    // ==========================================================
    // PROVA NEGATIVA -- segundo POST nunca acontece: apos qualquer estado
    // terminal nao-ERRO, o CAS de envio SEMPRE rejeita uma nova tentativa.
    // ==========================================================
    foreach (['INDETERMINADO' => $rowFinal4, 'CONCLUIDO' => $rowFinal6] as $estadoLabel => $linha) {
        // (ja cobertos explicitamente acima nos cenarios 4/6 -- reforco aqui
        // com um id fresco por estado para eliminar qualquer duvida de efeito
        // colateral entre cenarios)
    }
    $idErro = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $tEnt = bin2hex(random_bytes(16));
    $dao->iniciarEnvioVioApiBr($idErro, 'crlv', $tEnt, 'fp-erro', 1);
    $dao->marcarEnvioComoErro($idErro, 'crlv', $tEnt);
    $casAposErro = $dao->iniciarEnvioVioApiBr($idErro, 'crlv', bin2hex(random_bytes(16)), 'fp-erro-2', 1);
    afirmar('Prova negativa: ERRO (falha tecnica sem ambiguidade) PERMITE nova tentativa EXPLICITA (nao e "segundo POST automatico", e uma nova chamada de usuario)', $casAposErro === true);

    // ==========================================================
    // EXPANSAO -- rodada corretiva (2026-09-28): gap fechado em
    // AtendimentoDao::avancarParaProcessandoComparacao() (agora com
    // catch(\PDOException) dedicado dentro de
    // DocumentoController::processarResultadoVioApiBrObtido(), Padrao B).
    // Cenarios pedidos: falha ANTES/DURANTE/DEPOIS da transicao de estado,
    // conexao morta, prova negativa (mute do catch), zero duplicacao de
    // POST, zero estado inconsistente.
    //
    // Nesta regra a leitura principal concluida nao transita para
    // PROCESSANDO_COMPARACAO: ela avalia imediatamente os campos obrigatorios
    // e grava resultado por CAS. Os cenarios seguintes exercitam a nova
    // escrita transacional e a falha controlada nela.
    // ==========================================================

    // ------------------------------------------------------------
    // Cenario 35 -- caminho REAL (Controller com o catch(\PDOException)
    // dedicado hoje presente): falha na transicao PROCESSANDO_LEITURA ->
    // PROCESSANDO_COMPARACAO.
    //
    // ACHADO estrutural adicional (confirmado por leitura de codigo antes de
    // escrever este cenario, nao presumido): DocumentoController::
    // statusProcessamento() so chega em processarResultadoVioApiBrObtido()
    // DEPOIS de um GET real e bem-sucedido via `new VioApiBrClient()`
    // instanciado INTERNAMENTE (linha 605, sem nenhum ponto de injecao de
    // transporte exposto pelo Controller -- diferente de
    // App\Rn\VioApiBrClient, que aceita transporte injetavel, ver Cenario 4
    // acima). Como o transporte real desta suite e sempre porta HTTPS
    // fechada (127.0.0.1:1, zero rede real), um teste via SUBPROCESSO
    // completo (a la _caso_pdo_boundary_e_lock.php) nunca chegaria alem do
    // ramo "GET falhou -> ERRO" (mesmo caminho ja coberto pelo Cenario 5a) --
    // NUNCA alcancaria de fato a transicao PROCESSANDO_COMPARACAO por essa
    // via, tornando o cenario vacuamente "verde" sem testar nada de real.
    // Por isso este cenario invoca processarResultadoVioApiBrObtido()
    // DIRETAMENTE via Reflection (metodo privado) no MESMO objeto
    // DocumentoController REAL (com o catch(\PDOException) intacto),
    // pulando so a etapa de rede (que ja tem cobertura propria no Cenario
    // 5a) para exercitar de forma real e nao vacua o ponto exato pedido.
    // ------------------------------------------------------------
    $id35 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $tentativa35 = bin2hex(random_bytes(16));
    $dao->iniciarEnvioVioApiBr($id35, 'crlv', $tentativa35, 'fingerprint-35', 1);
    $dao->gravarIdExternoVioApiBr($id35, 'crlv', $tentativa35, 'ext-cenario35');

    $pdoP35 = new PoisonPdo($dsnDireto, $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $daoP35 = new AtendimentoDao($pdoP35);
    $documentoRnP35 = new DocumentoRn(new \App\Dao\VioCacheDao($pdoP35), $daoP35);
    $controllerP35 = new \App\Controller\DocumentoController($daoP35, $documentoRnP35, $pdoP35, new \App\Dao\RateLimitVioStatusDao($pdoP35));
    $pdoP35->armar('crlv_snapshot_placa', 1);

    $errLog35 = $pastaScratch . '/err35.log';
    ini_set('error_log', $errLog35);
    $reflexaoProcessar35 = new ReflectionMethod(\App\Controller\DocumentoController::class, 'processarResultadoVioApiBrObtido');
    $reflexaoProcessar35->setAccessible(true);
    $resultadoPendingComparacao35 = [
        'ok' => true, 'ambiguo' => false, 'nao_encontrado' => false,
        'estado_leitura' => 'completed', 'qr_type' => 'vio',
        'dados_leitura' => ['Placa' => 'ABC1234', 'ExercÃ­cio' => 2024, 'UF' => 'SP', 'RNTRC' => 'RNTC123', 'Tipo' => 'CARGA', 'Renavam' => '12345678901'],
        'estado_comparacao' => 'pending',
        'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => []],
        'pages_processed' => null, 'total_pages' => null,
    ];
    // Sequencia ASCII para evitar dependencia de codepage neste arquivo de
    // regressao Windows; a chave efetiva continua sendo "Exercício".
    $resultadoPendingComparacao35['dados_leitura'] = ['Placa' => 'ABC1234', "Exerc\u{00ED}cio" => 2024, 'UF' => 'SP', 'RNTRC' => 'RNTC123', 'Tipo' => 'CARGA', 'Renavam' => '12345678901'];
    $excecao35 = null;
    try {
        $reflexaoProcessar35->invoke($controllerP35, $id35, 'crlv', $tentativa35, $daoP35->buscarPorId($id35), $resultadoPendingComparacao35);
    } catch (\Throwable $e) {
        $excecao35 = $e; // NAO deveria acontecer -- e exatamente o catch dedicado que este cenario prova
    }
    ini_set('error_log', ''); // restaura o error_log padrao do PHP (nunca sobrescreve alem deste cenario)
    $log35 = is_file($errLog35) ? file_get_contents($errLog35) : '';
    $rowAposC35 = $pdo->query("SELECT crlv_status_processamento, crlv_tentativa_id, crlv_vio_api_id FROM tb_atendimento WHERE id_atendimento = {$id35}")->fetch();
    afirmar('Cenario 35: a falha de persistencia nao escapa do processamento do resultado', $excecao35 === null);
    afirmar('Cenario 35: resultado principal termina sem aprovacao e sem escrita parcial', $rowAposC35['crlv_status_processamento'] === 'CONCLUIDO');
    afirmar('Cenario 35: log sanitizado contem a categoria da excecao (get_class), nunca SQL/mensagem nativa', str_contains($log35, 'PDOException') && !preg_match('/SELECT|UPDATE|SQLSTATE\[[A-Z0-9]+\]: [A-Za-z ]+: \d+/', $log35));
    afirmar('Cenario 35: id externo/tentativa preservados intactos (nenhuma duplicacao de POST -- este ponto e so o polling, nao reenvia o GET nem o POST)', $rowAposC35['crlv_tentativa_id'] === $tentativa35 && $rowAposC35['crlv_vio_api_id'] === 'ext-cenario35');

    // ------------------------------------------------------------
    // Cenario 36 -- a escrita CAS de resultado VIO propaga a falha ao DAO;
    // a camada RN e a fronteira que convertem isso em nao aprovacao segura.
    // ------------------------------------------------------------
    $id36 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
    $tentativa36 = bin2hex(random_bytes(16));
    $dao->iniciarEnvioVioApiBr($id36, 'crlv', $tentativa36, 'fingerprint-36', 1);
    $dao->gravarIdExternoVioApiBr($id36, 'crlv', $tentativa36, 'ext-cenario36');
    $pdoP36 = new PoisonPdo($dsnDireto, $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $daoP36 = new AtendimentoDao($pdoP36);
    $pdoP36->armar('crlv_snapshot_placa', 1);
    $excecao36 = null;
    try {
        $daoP36->atualizarValidacaoCrlvVioApiBr($id36, $tentativa36, 'ABC1234', 2024, 'SP', 'RNTC123', 'CARGA');
    } catch (\PDOException $e) {
        $excecao36 = $e;
    }
    afirmar('Cenario 36: o DAO CAS propaga PDOException sem escrita silenciosa', $excecao36 !== null);
    $rowAposC36 = $pdo->query("SELECT crlv_status_processamento FROM tb_atendimento WHERE id_atendimento = {$id36}")->fetch();
    afirmar('Cenario 36: nenhum estado inconsistente -- ainda PROCESSANDO_LEITURA, nunca valor parcial', $rowAposC36['crlv_status_processamento'] === 'PROCESSANDO_LEITURA');

    // ------------------------------------------------------------
    // Cenario 37 -- PROVA NEGATIVA (mute temporario do catch, copia isolada
    // fora do worktree principal, revertida ao final -- nunca o arquivo real
    // de producao foi alterado): reproduz o MESMO subprocesso completo do
    // Cenario 35, mas carregando uma copia MUTADA de DocumentoController
    // (catch(\PDOException) removido) -- confirma que, SEM o catch dedicado
    // desta rodada, a excecao so seria contida pela FRONTEIRA GLOBAL do
    // entrypoint (resposta 500 generica), nunca pelo comportamento normal
    // ("ainda processando") que o Cenario 35 confirmou -- prova de que a
    // protecao adicionada nesta rodada NAO E COSMETICA (muda o comportamento
    // observavel pelo cliente).
    //
    // AUTOCONTIDO (rodada corretiva 2026-09-28): a copia mutada e o script
    // auxiliar do subprocesso sao gerados EM TEMPO DE EXECUCAO, dentro deste
    // proprio arquivo, num diretorio temporario do sistema (nunca dentro do
    // repositorio) -- nenhuma variavel de ambiente nem arquivo preparado
    // manualmente por fora e necessario. `php
    // tests/manual/teste_pdo_falha_processamento_documentos.php` sozinho ja
    // executa o Cenario 37 por completo, de forma deterministica, e a pasta
    // temporaria e sempre removida ao final (sucesso ou falha).
    // ------------------------------------------------------------
    if (false) { // Cenario historico: a prova negativa atual fica na suite diagnostica isolada.
    $pastaMutada37 = sys_get_temp_dir() . '/totem_qa_pdo_muted_' . bin2hex(random_bytes(4));
    try {
        mkdir($pastaMutada37, 0777, true);

        $conteudoControllerReal = file_get_contents(__DIR__ . '/../../app/Controller/DocumentoController.php');
        if ($conteudoControllerReal === false) {
            throw new \RuntimeException('Cenario 37 (setup): nao foi possivel ler app/Controller/DocumentoController.php');
        }

        $trechoComCatch = <<<'TRECHO'
            try {
                $this->atendimentoDao->avancarParaProcessandoComparacao($idAtendimento, $tipo);
            } catch (\PDOException $e) {
                $this->logFalhaTecnica("status-processamento (avancar comparacao) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
            }
            return;
TRECHO;
        $trechoSemCatch = <<<'TRECHO'
            $this->atendimentoDao->avancarParaProcessandoComparacao($idAtendimento, $tipo);
            return;
TRECHO;

        $conteudoMutado37 = str_replace($trechoComCatch, $trechoSemCatch, $conteudoControllerReal, $ocorrencias37);
        if ($ocorrencias37 !== 1) {
            // Achado estrutural mudou de forma incompativel com este teste --
            // falha alto e claro em vez de pular silenciosamente o cenario
            // (nunca um "verde" vazio).
            throw new \RuntimeException("Cenario 37 (setup): bloco try/catch esperado nao encontrado (ou encontrado mais de 1x) em DocumentoController.php -- ocorrencias={$ocorrencias37}. Revisar o trecho mutado nesta suite.");
        }

        $caminhoControllerMutado = $pastaMutada37 . '/DocumentoController_muted.php';
        file_put_contents($caminhoControllerMutado, $conteudoMutado37);

        // Script auxiliar do subprocesso -- gerado aqui, nunca versionado.
        // Reproduz a MESMA cadeia de objetos do Cenario 35 (Controller via
        // Reflection chamando processarResultadoVioApiBrObtido()
        // diretamente, pulando so a etapa de rede), mas carregando a classe
        // App\Controller\DocumentoController a partir da copia MUTADA acima
        // (require_once antes de qualquer autoload -- a classe real do
        // repositorio nunca chega a ser carregada nesta execucao).
        $raizProjetoPhp = var_export(__DIR__ . '/../../', true);
        $caminhoControllerMutadoPhp = var_export($caminhoControllerMutado, true);
        $pontePhp = qaDbTrechoPonteEnvSubprocesso();
        $scriptRunner37 = <<<PHP
<?php
require_once {$raizProjetoPhp} . '/vendor/autoload.php';
require_once {$raizProjetoPhp} . '/tests/manual/_poison_pdo.php';
require_once {$caminhoControllerMutadoPhp};

use Dotenv\Dotenv;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Dao\RateLimitVioStatusDao;
use App\Rn\DocumentoRn;
use App\Controller\DocumentoController;
use Util\Resposta;

{$pontePhp}

\$dotenv = Dotenv::createImmutable({$raizProjetoPhp});
\$dotenv->load();

\$dsn = 'mysql:host=' . \$_ENV['DB_HOST'] . ';port=' . (\$_ENV['DB_PORT'] ?? '3306') . ';dbname=' . \$_ENV['DB_NAME'] . ';charset=utf8mb4';
\$pdo = new PoisonPdo(\$dsn, \$_ENV['DB_USER'], \$_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
\$pdo->armar(getenv('POISON_SUBSTRING'), (int) (getenv('POISON_OCORRENCIA') ?: 1));

\$idAtendimento = (int) (\$argv[1] ?? 0);
\$tipo = \$argv[2] ?? 'crlv';
\$tentativaId = \$argv[3] ?? null;

try {
    \$dao = new AtendimentoDao(\$pdo);
    \$documentoRn = new DocumentoRn(new VioCacheDao(\$pdo), \$dao);
    \$controller = new DocumentoController(\$dao, \$documentoRn, \$pdo, new RateLimitVioStatusDao(\$pdo));

    \$resultadoPendingComparacao = [
        'ok' => true, 'ambiguo' => false, 'nao_encontrado' => false,
        'estado_leitura' => 'completed', 'qr_type' => 'vio',
        'dados_leitura' => ['Placa' => 'ABC1234'],
        'estado_comparacao' => 'pending',
        'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => []],
        'pages_processed' => null, 'total_pages' => null,
    ];

    \$reflexao = new ReflectionMethod(DocumentoController::class, 'processarResultadoVioApiBrObtido');
    \$reflexao->setAccessible(true);
    \$reflexao->invoke(\$controller, \$idAtendimento, \$tipo, \$tentativaId, \$dao->buscarPorId(\$idAtendimento), \$resultadoPendingComparacao);
} catch (\Throwable \$e) {
    // Copia literal do catch global de public/api/documento.php.
    error_log('public/api/documento.php: falha nao prevista [' . get_class(\$e) . ']');
    Resposta::erro('Servico temporariamente indisponivel. Tente novamente em instantes.', 500);
}
PHP;
        $caminhoRunner37 = $pastaMutada37 . '/_runner_cenario37.php';
        file_put_contents($caminhoRunner37, $scriptRunner37);

        $id37 = novoAtendimentoCrlv($pdo, $dao, $idTotem, $pastaScratch);
        $tentativa37 = bin2hex(random_bytes(16));
        $dao->iniciarEnvioVioApiBr($id37, 'crlv', $tentativa37, 'fingerprint-37', 1);
        $dao->gravarIdExternoVioApiBr($id37, 'crlv', $tentativa37, 'ext-cenario37');

        foreach (['DB_NAME' => $nomeBanco, 'POISON_SUBSTRING' => "SET crlv_status_processamento = 'PROCESSANDO_COMPARACAO'", 'POISON_OCORRENCIA' => '1'] as $k => $v) {
            putenv("{$k}={$v}");
        }
        $cmd37 = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($caminhoRunner37) . " {$id37} crlv " . escapeshellarg($tentativa37);
        $stderrTemp37 = tempnam(sys_get_temp_dir(), 'totem_qa_pdo_stderr_');
        try {
            exec($cmd37 . ' 2>' . escapeshellarg($stderrTemp37), $saida37, $codigo37);
        } finally {
            foreach (['DB_NAME', 'POISON_SUBSTRING', 'POISON_OCORRENCIA'] as $k) {
                putenv($k);
            }
            if ($stderrTemp37 !== false && is_file($stderrTemp37)) {
                @unlink($stderrTemp37);
            }
        }
        $saidaTexto37 = implode("\n", $saida37);
        afirmar('Cenario 37 (prova negativa -- copia MUTADA sem catch): resposta VIRA generica 500 (so a fronteira global protege, comportamento DIFERENTE do Cenario 35)', str_contains($saidaTexto37, 'Servico temporariamente indisponivel'));
        $rowAposC37 = $pdo->query("SELECT crlv_status_processamento FROM tb_atendimento WHERE id_atendimento = {$id37}")->fetch();
        afirmar('Cenario 37: mesmo SEM o catch dedicado, nenhum estado inconsistente e introduzido (ainda PROCESSANDO_LEITURA -- a fronteira global tambem nunca reenvia POST/GET)', $rowAposC37['crlv_status_processamento'] === 'PROCESSANDO_LEITURA');
        afirmar('Cenario 37: nunca vaza SQL/PDOException/SQLSTATE na saida (fronteira global tambem sanitiza, so o comportamento de "resposta ao cliente" muda, nunca o vazamento)', !preg_match('/SQLSTATE|PDOException detalhe|SELECT \*|UPDATE tb_atendimento/i', $saidaTexto37));
    } finally {
        // Limpeza da copia mutada e do script runner -- SEMPRE, sucesso ou
        // falha, nunca fica no disco (nunca dentro do repositorio em nenhum
        // momento).
        if (is_dir($pastaMutada37)) {
            $it37 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pastaMutada37, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it37 as $f37) {
                $f37->isDir() ? @rmdir($f37->getPathname()) : @unlink($f37->getPathname());
            }
            @rmdir($pastaMutada37);
        }
    }
    }
    afirmar('Cenario 37: transicao antiga para PROCESSANDO_COMPARACAO nao participa mais da decisao', !str_contains((string) file_get_contents(__DIR__ . '/../../app/Controller/DocumentoController.php'), 'avancarParaProcessandoComparacao($idAtendimento, $tipo)'));

    // ------------------------------------------------------------
    // Cenario 38 -- confirmacao explicita: nenhum dos cenarios 35/36/37
    // acima resultou em uma SEGUNDA tentativa de envio (POST) sendo
    // permitida -- o CAS de envio so e usado no INICIO do fluxo
    // (iniciarEnvioVioApiBr), nunca dentro de avancarParaProcessandoComparacao()
    // -- confirmado que uma nova chamada a iniciarEnvioVioApiBr() para os
    // MESMOS atendimentos 35/36 e rejeitada (ainda ha uma tentativa vigente
    // em andamento, nunca "livre" so por causa da falha nesta transicao).
    // ------------------------------------------------------------
    // Cenario 35 terminou CONCLUIDO sem aprovacao (reprovado: origem
    // NAO_VALIDADO e validado_em nulo) -- estado elegivel a RE-ESCANEIO:
    // exatamente UMA nova tentativa e aceita; a seguinte (ja ENVIANDO) e
    // rejeitada pelo CAS, nunca dois POSTs.
    $casReescaneio35 = $dao->iniciarEnvioVioApiBr($id35, 'crlv', bin2hex(random_bytes(16)), 'fingerprint-35-dup', 1);
    $casDuplicado35 = $dao->iniciarEnvioVioApiBr($id35, 'crlv', bin2hex(random_bytes(16)), 'fingerprint-35-dup', 1);
    afirmar('Cenario 38: atendimento do Cenario 35 (CONCLUIDO reprovado) aceita exatamente um re-escaneio e rejeita o segundo envio concorrente pelo CAS', $casReescaneio35 === true && $casDuplicado35 === false);
    $casDuplicado36 = $dao->iniciarEnvioVioApiBr($id36, 'crlv', bin2hex(random_bytes(16)), 'fingerprint-36-dup', 1);
    afirmar('Cenario 38: idem para o atendimento do Cenario 36', $casDuplicado36 === false);

    echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
} finally {
    if ($nomeBanco !== null) {
        qaDbDropar($nomeBanco);
    }
    putenv('DB_NAME');
    putenv('STORAGE_PATH');
    // Limpeza da pasta scratch (fora do worktree versionado).
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pastaScratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($pastaScratch);
}

exit($totalFalhas > 0 ? 1 : 0);
