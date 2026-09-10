<?php

/**
 * Teste manual (sem rede real) da maquina de estados de idempotencia de 5
 * estados do envio ao Talent — itens 6, 7 e 8 do escopo de
 * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md.
 *
 * Cobre:
 * - item 6: CONCORRENCIA REAL (2 processos via proc_open, nao sequencial)
 *   disputando App\Dao\AtendimentoDao::iniciarEnvioTalent para o MESMO
 *   atendimento — so um vence;
 * - item 7: timeout do TalentClient vira ENVIO_INDETERMINADO (via
 *   TalentClient de teste, SEM rede real); resposta atrasada de uma
 *   tentativa antiga (tentativa_id != atual) NAO sobrescreve o resultado
 *   de uma tentativa mais nova (gravarResultadoEnvioTalent retorna false);
 * - item 8: ENVIO_INDETERMINADO NUNCA e elegivel a retry automatico
 *   (iniciarEnvioTalent so aceita NAO_ENVIADO/ERRO_REPROCESSAVEL), e
 *   FilaEnvioDao::buscarPendentes nunca traz um item ENVIO_INDETERMINADO;
 * - bonus: App\Rn\TalentRn::processarCheckin fim a fim com TalentClient
 *   mockado (sucesso -> ENVIADO grava senha/protocolo + status=concluido/
 *   etapa=impressao; timeout -> ENVIO_INDETERMINADO; erro_servidor ->
 *   ERRO_REPROCESSAVEL; chamada repetida apos ENVIADO -> JA_ENVIADO SEM
 *   chamar o Talent de novo).
 *
 * Uso: php tests/manual/teste_talent_idempotencia.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Dao\EmpresaDao;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\TalentClientException;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

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
 * Cliente de teste do Talent — NUNCA faz rede real. Devolve um resultado
 * canned ou lanca uma TalentClientException canned, conforme configurado,
 * e conta quantas vezes checkin() foi chamado (usado para confirmar que um
 * atendimento JA_ENVIADO nunca dispara uma nova chamada).
 */
class TalentClientDeTeste extends TalentClient
{
    public int $chamadas = 0;
    private $comportamento;

    public function __construct(callable $comportamento)
    {
        parent::__construct('', '');
        $this->comportamento = $comportamento;
    }

    public function checkin(array $payload): array
    {
        $this->chamadas++;
        return ($this->comportamento)($payload);
    }
}

$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);
$empresaDao = new EmpresaDao($pdo);
$empresa = $empresaDao->buscarPorId(1);

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_IDEMP_' . bin2hex(random_bytes(3)));
$pastas = [];
$idsAtendimento = [];

function afirmarLimpo(array &$idsAtendimento, array &$pastas, PDO $pdo, array $fix): void
{
    $idsAtendimento[] = $fix['id_atendimento'];
    $pastas[] = $fix['pasta_completa'];
}

// ============================================================
// Item 6: concorrencia REAL — 2 processos disputando iniciarEnvioTalent
// ============================================================
$fixConc = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'CNC1111');
afirmarLimpo($idsAtendimento, $pastas, $pdo, $fixConc);

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

$scriptConc = __DIR__ . '/_caso_iniciar_envio_talent.php';
$saidasConc = dispararParalelo($scriptConc, [
    'a' => [(string) $fixConc['id_atendimento'], 'tentativa_a_' . bin2hex(random_bytes(4))],
    'b' => [(string) $fixConc['id_atendimento'], 'tentativa_b_' . bin2hex(random_bytes(4))],
]);
$adquiriuA = str_contains($saidasConc['a'], 'ADQUIRIU');
$adquiriuB = str_contains($saidasConc['b'], 'ADQUIRIU');
afirmar('Concorrencia REAL (2 processos): exatamente um dos dois adquire o CAS de envio', $adquiriuA xor $adquiriuB);
$statusPosConc = $atendimentoDao->buscarPorId($fixConc['id_atendimento'])['talent_checkin_status'];
afirmar('Concorrencia REAL: estado final e ENVIANDO (o vencedor, nenhum dos dois foi negado silenciosamente sem UM adquirir)', $statusPosConc === 'ENVIANDO');

// ============================================================
// Item 8: ENVIO_INDETERMINADO NUNCA e elegivel a retry automatico
// ============================================================
$pdo->prepare("UPDATE tb_atendimento SET talent_checkin_status = 'ENVIO_INDETERMINADO' WHERE id_atendimento = :id")
    ->execute(['id' => $fixConc['id_atendimento']]);
$tentativaPosIndeterminado = bin2hex(random_bytes(8));
$reabriuIndeterminado = $atendimentoDao->iniciarEnvioTalent($fixConc['id_atendimento'], $tentativaPosIndeterminado);
afirmar('ENVIO_INDETERMINADO NUNCA e reaberto por iniciarEnvioTalent (retry automatico bloqueado por design)', $reabriuIndeterminado === false);

// buscarPendentes nunca traz ENVIO_INDETERMINADO mesmo se houver linha em tb_fila_envio
$stmtFila = $pdo->prepare("
    INSERT INTO tb_fila_envio (id_atendimento, tentativas, ultimo_erro, status, proxima_tentativa_em)
    VALUES (:id, 1, 'timeout', 'pendente', DATE_SUB(NOW(), INTERVAL 1 MINUTE))
");
$stmtFila->execute(['id' => $fixConc['id_atendimento']]);
$filaDao = new FilaEnvioDao($pdo);
$pendentes = $filaDao->buscarPendentes();
$apareceIndeterminadoNaFila = false;
foreach ($pendentes as $item) {
    if ((int) $item['id_atendimento'] === $fixConc['id_atendimento']) {
        $apareceIndeterminadoNaFila = true;
    }
}
afirmar('FilaEnvioDao::buscarPendentes NUNCA retorna atendimento com talent_checkin_status = ENVIO_INDETERMINADO', !$apareceIndeterminadoNaFila);

// ============================================================
// Item 7 (parte 1): timeout do TalentClient vira ENVIO_INDETERMINADO via
// TalentRn::processarCheckin (SEM rede real)
// ============================================================
$fixTimeout = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'TIM1111');
afirmarLimpo($idsAtendimento, $pastas, $pdo, $fixTimeout);

$clienteTimeout = new TalentClientDeTeste(function () {
    throw new TalentClientException('timeout');
});
$talentRnTimeout = new TalentRn($clienteTimeout, new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);
$atTimeout = $atendimentoDao->buscarPorId($fixTimeout['id_atendimento']);
$resultadoTimeout = $talentRnTimeout->processarCheckin($atTimeout, $empresa, []);
afirmar('Timeout do TalentClient -> processarCheckin retorna status ENVIO_INDETERMINADO', $resultadoTimeout['status'] === 'ENVIO_INDETERMINADO');
$statusBdTimeout = $atendimentoDao->buscarPorId($fixTimeout['id_atendimento'])['talent_checkin_status'];
afirmar('Timeout: talent_checkin_status gravado como ENVIO_INDETERMINADO no banco', $statusBdTimeout === 'ENVIO_INDETERMINADO');
$reabriuAposTimeout = $atendimentoDao->iniciarEnvioTalent($fixTimeout['id_atendimento'], bin2hex(random_bytes(8)));
afirmar('Apos timeout, NENHUMA nova tentativa automatica e aceita (ENVIO_INDETERMINADO permanece travado)', $reabriuAposTimeout === false);

// ============================================================
// Item 7 (parte 1b): categoria 'erro_indeterminado' (correcao de seguranca
// pos-revisao — erros de curl que podem ter ocorrido apos a requisicao ja
// ter saido do totem, ex.: CURLE_RECV_ERROR/GOT_NOTHING) tambem vira
// ENVIO_INDETERMINADO, igual a 'timeout' (nunca ERRO_REPROCESSAVEL)
// ============================================================
$fixIndeterminado = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'IND1111');
afirmarLimpo($idsAtendimento, $pastas, $pdo, $fixIndeterminado);

$clienteIndeterminado = new TalentClientDeTeste(function () {
    throw new TalentClientException('erro_indeterminado');
});
$talentRnIndeterminado = new TalentRn($clienteIndeterminado, new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);
$atIndeterminado = $atendimentoDao->buscarPorId($fixIndeterminado['id_atendimento']);
$resultadoIndeterminado = $talentRnIndeterminado->processarCheckin($atIndeterminado, $empresa, []);
afirmar("Categoria 'erro_indeterminado' -> processarCheckin retorna status ENVIO_INDETERMINADO (nunca ERRO_REPROCESSAVEL)", $resultadoIndeterminado['status'] === 'ENVIO_INDETERMINADO');
$statusBdIndeterminado = $atendimentoDao->buscarPorId($fixIndeterminado['id_atendimento'])['talent_checkin_status'];
afirmar("Categoria 'erro_indeterminado': talent_checkin_status gravado como ENVIO_INDETERMINADO no banco", $statusBdIndeterminado === 'ENVIO_INDETERMINADO');
$reabriuAposIndeterminado = $atendimentoDao->iniciarEnvioTalent($fixIndeterminado['id_atendimento'], bin2hex(random_bytes(8)));
afirmar("Apos 'erro_indeterminado', NENHUMA nova tentativa automatica e aceita (retry automatico bloqueado)", $reabriuAposIndeterminado === false);

// ============================================================
// Item 7 (parte 2): resposta ATRASADA de uma tentativa ANTIGA nao
// sobrescreve o resultado de uma tentativa MAIS NOVA
// ============================================================
$fixZumbi = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'ZUM1111');
afirmarLimpo($idsAtendimento, $pastas, $pdo, $fixZumbi);

$tentativaAntiga = bin2hex(random_bytes(8));
$atendimentoDao->iniciarEnvioTalent($fixZumbi['id_atendimento'], $tentativaAntiga); // 1a tentativa fica ENVIANDO com tentativaAntiga
// simula timeout dessa 1a tentativa ficando obsoleta (>60s) e sendo destravada
$pdo->prepare("UPDATE tb_atendimento SET talent_status_iniciado_em = DATE_SUB(NOW(), INTERVAL 61 SECOND) WHERE id_atendimento = :id")
    ->execute(['id' => $fixZumbi['id_atendimento']]);
$atendimentoDao->marcarEnvioTalentObsoletoComoIndeterminado($fixZumbi['id_atendimento'], 60);
$statusAposObsoleto = $atendimentoDao->buscarPorId($fixZumbi['id_atendimento'])['talent_checkin_status'];
afirmar('ENVIANDO obsoleto (> timeout) e marcado ENVIO_INDETERMINADO pela rede de seguranca', $statusAposObsoleto === 'ENVIO_INDETERMINADO');

// Resposta "zumbi" da tentativa ANTIGA chega DEPOIS — tenta gravar sucesso,
// mas o tentativa_id no banco ja nao e mais o dela (foi so marcado
// ENVIO_INDETERMINADO, tentativa_id permanece o mesmo neste caso — o teste
// relevante aqui e uma tentativa REALMENTE substituida por uma mais nova):
$tentativaNova = bin2hex(random_bytes(8));
// Reabre manualmente para simular uma nova tentativa distinta (fora do fluxo
// automatico, que nao reabriria ENVIO_INDETERMINADO) so para testar
// isoladamente a protecao de tentativa_id em gravarResultadoEnvioTalent:
$pdo->prepare("UPDATE tb_atendimento SET talent_checkin_status = 'ENVIANDO', talent_tentativa_id = :t WHERE id_atendimento = :id")
    ->execute(['t' => $tentativaNova, 'id' => $fixZumbi['id_atendimento']]);

$gravouZumbi = $atendimentoDao->gravarResultadoEnvioTalent($fixZumbi['id_atendimento'], $tentativaAntiga, 'ENVIADO', 'SENHA_ZUMBI', 'PROTO_ZUMBI');
afirmar('Resposta atrasada de tentativa ANTIGA (tentativa_id diferente do atual) NAO tem efeito (retorna false)', $gravouZumbi === false);
$atendimentoAposZumbi = $atendimentoDao->buscarPorId($fixZumbi['id_atendimento']);
afirmar('Estado NAO foi sobrescrito pela resposta zumbi (continua ENVIANDO da tentativa nova)', $atendimentoAposZumbi['talent_checkin_status'] === 'ENVIANDO');
afirmar('talent_senha NAO foi preenchido pela resposta zumbi', $atendimentoAposZumbi['talent_senha'] === null);

$gravouNova = $atendimentoDao->gravarResultadoEnvioTalent($fixZumbi['id_atendimento'], $tentativaNova, 'ENVIADO', 'SENHA_REAL', 'PROTO_REAL');
afirmar('Resposta da tentativa VIGENTE grava normalmente', $gravouNova === true);
$atendimentoAposNova = $atendimentoDao->buscarPorId($fixZumbi['id_atendimento']);
afirmar('talent_senha final e o da tentativa vigente (nao da zumbi)', $atendimentoAposNova['talent_senha'] === 'SENHA_REAL');
afirmar('status/etapa do atendimento avancam para concluido/impressao apos ENVIADO', $atendimentoAposNova['status'] === 'concluido' && $atendimentoAposNova['etapa_atual'] === 'impressao');

// ============================================================
// Bonus: fim a fim de processarCheckin — sucesso, erro reprocessavel, e
// idempotencia (JA_ENVIADO nunca chama o Talent de novo)
// ============================================================
$fixSucesso = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'SUC1111');
afirmarLimpo($idsAtendimento, $pastas, $pdo, $fixSucesso);

$clienteSucesso = new TalentClientDeTeste(function () {
    return ['senha' => 'ABC123', 'protocolo' => 'PROTO-XYZ'];
});
$talentRnSucesso = new TalentRn($clienteSucesso, new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);
$atSucesso = $atendimentoDao->buscarPorId($fixSucesso['id_atendimento']);
$r1 = $talentRnSucesso->processarCheckin($atSucesso, $empresa, []);
afirmar('processarCheckin (sucesso): status ENVIADO', $r1['status'] === 'ENVIADO');
afirmar('processarCheckin (sucesso): senha/protocolo retornados vem literalmente da allowlist do TalentClient', $r1['senha'] === 'ABC123' && $r1['protocolo'] === 'PROTO-XYZ');

$atSucesso2 = $atendimentoDao->buscarPorId($fixSucesso['id_atendimento']);
$r2 = $talentRnSucesso->processarCheckin($atSucesso2, $empresa, []);
afirmar('processarCheckin (chamada repetida apos ENVIADO): status JA_ENVIADO', $r2['status'] === 'JA_ENVIADO');
afirmar('processarCheckin (JA_ENVIADO): NAO chama o Talent de novo (idempotencia real, so 1 chamada total)', $clienteSucesso->chamadas === 1);

$fixErro = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'ERR1111');
afirmarLimpo($idsAtendimento, $pastas, $pdo, $fixErro);
$clienteErro = new TalentClientDeTeste(function () {
    throw new TalentClientException('erro_servidor');
});
$talentRnErro = new TalentRn($clienteErro, new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);
$atErro = $atendimentoDao->buscarPorId($fixErro['id_atendimento']);
$rErro = $talentRnErro->processarCheckin($atErro, $empresa, []);
afirmar('processarCheckin (HTTP 500 simulado): status ERRO_REPROCESSAVEL (nunca ENVIO_INDETERMINADO)', $rErro['status'] === 'ERRO_REPROCESSAVEL');
$reabriuErro = $atendimentoDao->iniciarEnvioTalent($fixErro['id_atendimento'], bin2hex(random_bytes(8)));
afirmar('ERRO_REPROCESSAVEL PODE ser reaberto (elegivel a retry automatico, ao contrario de ENVIO_INDETERMINADO)', $reabriuErro === true);

// ============================================================
// Limpeza
// ============================================================
foreach ($pastas as $p) {
    talentLimparPasta($p);
}
foreach ($idsAtendimento as $id) {
    $pdo->prepare('DELETE FROM tb_fila_envio WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
