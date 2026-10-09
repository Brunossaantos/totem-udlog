<?php

/**
 * Teste manual (item 6 do roteiro de testes da demanda
 * talent-doctos-finalizacao-checkin, 2026-09-14) — cenario "Talent aceitou
 * o check-in (sucesso mock), mas o UPDATE de status da ordem de coleta para
 * INATIVA falhou (mock)": confirma que
 * App\Controller\AtendimentoController::tentarMarcarOrdemConcluida()
 * (acessado via Reflection, mesmo espirito dos demais testes manuais do
 * projeto):
 *
 * - NUNCA propaga excecao (resposta de sucesso ao motorista, ja decidida
 *   pelo chamador, precisa ser preservada de qualquer forma);
 * - grava um registro em tb_ordem_coleta_pendente_baixa quando
 *   OrdemColetaClient::marcarConcluida() retorna false OU lanca excecao;
 * - o registro e IDEMPOTENTE — rodar a mesma falha 2x para o MESMO
 *   id_atendimento nao duplica linha (UNIQUE KEY uk_ordem_coleta_pendente_
 *   baixa_atendimento, migration 012);
 * - quando marcarConcluida() retorna true (sucesso), NENHUM registro e
 *   criado;
 * - (correcao de 2026-09-15) quando marcarConcluida() retorna false MAS a
 *   ordem JA ESTA INATIVA (reprocessamento idempotente do branch JA_ENVIADO
 *   apos uma chamada anterior bem-sucedida), NENHUM registro de pendencia e
 *   criado, o fluxo trata como sucesso (nao propaga erro) e NENHUMA segunda
 *   tentativa de UPDATE ocorre.
 *
 * NUNCA toca no banco externo real de gestao de coletas (usa um DUBLE de
 * OrdemColetaClient, sem herdar de OrdemColetaDao/ConexaoGestaoColetas) —
 * nenhuma alteracao de status de ordem real ocorre neste teste.
 *
 * Uso: php tests/manual/teste_ordem_coleta_pendente_baixa.php
 */

require_once __DIR__ . '/qa_qr_exclusivo_legado.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Dao\OrdemColetaPendenteBaixaDao;
use App\Rn\AtendimentoRn;
use App\Dao\OrdemColetaDao;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\DocumentoRn;
use App\Controller\AtendimentoController;

// F4a: roda SO em banco QA descartavel (qa_qr_exclusivo_<hex>); nunca em udlog_totem.
[$pdoAdmQa, $bancoQa, $storageQa] = qaLegadoCriarAmbiente();
$_ENV['DB_NAME'] = $bancoQa; // LogSistema/Conexao do processo pai tambem apontam para o banco QA
register_shutdown_function(static function () use ($bancoQa, $storageQa): void {
    qaLegadoLimparAmbiente($bancoQa, $storageQa); // idempotente: nunca deixa banco QA residual, mesmo em fatal
});
$pdo = Conexao::obter();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $bancoQa) {
    fwrite(STDERR, "banco nao e o QA\n");
    exit(3);
}
$pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");

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
 * Duble de OrdemColetaClient — NUNCA toca no banco externo real
 * (ConexaoGestaoColetas). Comportamento configurado por closure.
 */
class OrdemColetaClientDuble extends OrdemColetaClient
{
    public int $chamadas = 0;
    public int $chamadasStatusAtual = 0;
    public ?string $ultimoCnpj = null;
    public ?string $ultimoNumero = null;
    public ?string $ultimoCnpjStatus = null;
    private $comportamento;
    private $comportamentoStatus;

    /**
     * $comportamentoStatus e usado somente quando $comportamento (marcarConcluida)
     * retorna false — simula App\Rn\OrdemColetaClient::statusAtual(), chamado
     * pelo controller para distinguir "ja estava INATIVA" (idempotente) de
     * falha real. Default 'ATIVA' simula o cenario de falha real (ordem ainda
     * ativa, UPDATE genuinamente nao conseguiu mudar).
     */
    public function __construct(callable $comportamento, ?callable $comportamentoStatus = null)
    {
        parent::__construct(new OrdemColetaDao());
        $this->comportamento = $comportamento;
        $this->comportamentoStatus = $comportamentoStatus ?? fn() => 'ATIVA';
    }

    public function marcarConcluida(string $cnpj, string $numero): bool
    {
        $this->chamadas++;
        $this->ultimoCnpj = $cnpj;
        $this->ultimoNumero = $numero;
        return ($this->comportamento)($numero);
    }

    public function statusAtual(string $cnpj, string $numero): ?string
    {
        $this->chamadasStatusAtual++;
        $this->ultimoCnpjStatus = $cnpj;
        return ($this->comportamentoStatus)($numero);
    }
}

function chamarTentarMarcarOrdemConcluida(AtendimentoController $controller, int $idAtendimento, string $numero, string $cnpj = "11222333000181"): void
{
    $reflexao = new ReflectionMethod(AtendimentoController::class, 'tentarMarcarOrdemConcluida');
    $reflexao->setAccessible(true);
    $reflexao->invoke($controller, $idAtendimento, $numero, $cnpj);
}

$atendimentoDao = new AtendimentoDao($pdo);
$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_ORDEM_PENDBAIXA_' . bin2hex(random_bytes(3)), 1);
$pastas = [];
$idsAtendimento = [];

function montarController(AtendimentoDao $atendimentoDao, PDO $pdo, OrdemColetaClient $ordemColetaClient): AtendimentoController
{
    $atendimentoRn = new AtendimentoRn($atendimentoDao, new OrdemColetaClient(new OrdemColetaDao()));
    $talentRn = new TalentRn(new TalentClient('', ''), $atendimentoDao, $_ENV['STORAGE_PATH']);
    $documentoRn = new DocumentoRn(new \App\Dao\VioCacheDao($pdo), $atendimentoDao);

    return new AtendimentoController(
        $atendimentoRn,
        $talentRn,
        new AtendimentoNotaDao($pdo),
        $documentoRn,
        new TotemDao($pdo),
        new EmpresaDao($pdo),
        $ordemColetaClient,
        new OrdemColetaPendenteBaixaDao($pdo)
    );
}

// ============================================================
// Cenario 1: marcarConcluida() retorna FALSE -> registra pendencia
// ============================================================
$fix1 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PEN1111', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-PEN1111');
$pastas[] = $fix1['pasta_completa'];
$idsAtendimento[] = $fix1['id_atendimento'];

$clienteRetornaFalse = new OrdemColetaClientDuble(fn() => false);
$controller1 = montarController($atendimentoDao, $pdo, $clienteRetornaFalse);
chamarTentarMarcarOrdemConcluida($controller1, $fix1['id_atendimento'], 'OC-PEN1111');

$stmt = $pdo->prepare('SELECT * FROM tb_ordem_coleta_pendente_baixa WHERE id_atendimento = :id');
$stmt->execute(['id' => $fix1['id_atendimento']]);
$registro1 = $stmt->fetch();
afirmar('marcarConcluida()=false (ordem ainda ATIVA, falha real): registro criado em tb_ordem_coleta_pendente_baixa', $registro1 !== false);
afirmar('marcarConcluida()=false: statusAtual() consultado para distinguir falha real de ja-INATIVA', $clienteRetornaFalse->chamadasStatusAtual >= 1);
afirmar('Registro contem APENAS id_atendimento/numero_ordem_coleta/timestamps (nunca payload/dado pessoal)', $registro1 !== false && array_keys($registro1) == array_keys($registro1) && $registro1['numero_ordem_coleta'] === 'OC-PEN1111');
afirmar('resolvido_em comeca NULL (pendente de reconciliacao manual)', $registro1['resolvido_em'] === null);

// idempotencia: rodar de novo o MESMO cenario para o MESMO id_atendimento
chamarTentarMarcarOrdemConcluida($controller1, $fix1['id_atendimento'], 'OC-PEN1111');
$stmt->execute(['id' => $fix1['id_atendimento']]);
$totalLinhas1 = count($stmt->fetchAll());
afirmar('Idempotente: rodar a mesma falha 2x NAO duplica linha (UNIQUE id_atendimento)', $totalLinhas1 === 1);

// ============================================================
// Cenario 2: marcarConcluida() lanca EXCECAO -> tambem registra pendencia
// (nunca propaga a excecao para o chamador)
// ============================================================
$fix2 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PEN2222', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-PEN2222');
$pastas[] = $fix2['pasta_completa'];
$idsAtendimento[] = $fix2['id_atendimento'];

$clienteLancaExcecao = new OrdemColetaClientDuble(function () {
    throw new \RuntimeException('Nao foi possivel conectar ao banco de ordens de coleta');
});
$controller2 = montarController($atendimentoDao, $pdo, $clienteLancaExcecao);

$lancouExcecao = false;
try {
    chamarTentarMarcarOrdemConcluida($controller2, $fix2['id_atendimento'], 'OC-PEN2222');
} catch (\Throwable $e) {
    $lancouExcecao = true;
}
afirmar('marcarConcluida() lancando excecao NUNCA propaga para o chamador (resposta de sucesso preservada)', $lancouExcecao === false);

$stmt->execute(['id' => $fix2['id_atendimento']]);
$registro2 = $stmt->fetch();
afirmar('Excecao no marcarConcluida(): registro TAMBEM criado em tb_ordem_coleta_pendente_baixa', $registro2 !== false);

// ============================================================
// Cenario 3: marcarConcluida() retorna TRUE (sucesso real) -> NENHUM
// registro criado
// ============================================================
$fix3 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PEN3333', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-PEN3333');
$pastas[] = $fix3['pasta_completa'];
$idsAtendimento[] = $fix3['id_atendimento'];

$clienteSucesso = new OrdemColetaClientDuble(fn() => true);
$controller3 = montarController($atendimentoDao, $pdo, $clienteSucesso);
chamarTentarMarcarOrdemConcluida($controller3, $fix3['id_atendimento'], 'OC-PEN3333');

$stmt->execute(['id' => $fix3['id_atendimento']]);
afirmar('marcarConcluida()=true: NENHUM registro criado em tb_ordem_coleta_pendente_baixa', $stmt->fetch() === false);
afirmar('marcarConcluida() foi chamado exatamente 1 vez no cenario de sucesso', $clienteSucesso->chamadas === 1);
afirmar('marcarConcluida()=true: statusAtual() NUNCA consultado (nao precisa distinguir nada)', $clienteSucesso->chamadasStatusAtual === 0);

// ============================================================
// Cenario 4: marcarConcluida() retorna FALSE mas a ordem JA ESTA INATIVA
// (reprocessamento idempotente do branch JA_ENVIADO apos uma chamada
// anterior bem-sucedida) -> SUCESSO idempotente, NENHUM registro de
// pendencia, NENHUMA segunda tentativa de UPDATE
// ============================================================
$fix4 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PEN4444', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-PEN4444');
$pastas[] = $fix4['pasta_completa'];
$idsAtendimento[] = $fix4['id_atendimento'];

$clienteJaInativa = new OrdemColetaClientDuble(fn() => false, fn() => 'INATIVA');
$controller4 = montarController($atendimentoDao, $pdo, $clienteJaInativa);
chamarTentarMarcarOrdemConcluida($controller4, $fix4['id_atendimento'], 'OC-PEN4444');

$stmt->execute(['id' => $fix4['id_atendimento']]);
afirmar('JA_ENVIADO com ordem ja INATIVA: NENHUM registro criado em tb_ordem_coleta_pendente_baixa', $stmt->fetch() === false);
afirmar('JA_ENVIADO com ordem ja INATIVA: marcarConcluida() chamado exatamente 1 vez (sem segunda tentativa de UPDATE)', $clienteJaInativa->chamadas === 1);
afirmar('JA_ENVIADO com ordem ja INATIVA: statusAtual() consultado exatamente 1 vez para distinguir do caso de falha real', $clienteJaInativa->chamadasStatusAtual === 1);

$lancouExcecaoCenario4 = false;
try {
    chamarTentarMarcarOrdemConcluida($controller4, $fix4['id_atendimento'], 'OC-PEN4444');
} catch (\Throwable $e) {
    $lancouExcecaoCenario4 = true;
}
afirmar('JA_ENVIADO com ordem ja INATIVA: NENHUMA excecao propagada (tratado como sucesso, nao erro)', $lancouExcecaoCenario4 === false);

// ============================================================
// Cenario 5 (F4a): CNPJ do cliente AUSENTE -> NAO inativa por numero ambiguo:
// marcarConcluida()/statusAtual() NUNCA chamados, pendencia registrada.
// ============================================================
foreach (['', '   ', '..-/'] as $i => $cnpjAusente) {
    $fix5 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PEN5' . $i . '55', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-PEN5' . $i);
    $pastas[] = $fix5['pasta_completa'];
    $idsAtendimento[] = $fix5['id_atendimento'];

    $clienteSemCnpj = new OrdemColetaClientDuble(fn() => true);
    $controller5 = montarController($atendimentoDao, $pdo, $clienteSemCnpj);
    $lancou5 = false;
    try {
        chamarTentarMarcarOrdemConcluida($controller5, $fix5['id_atendimento'], 'OC-PEN5' . $i, $cnpjAusente);
    } catch (\Throwable $e) {
        $lancou5 = true;
    }
    $stmt->execute(['id' => $fix5['id_atendimento']]);
    $registro5 = $stmt->fetch();
    afirmar("CNPJ ausente (caso " . $i . "): nenhuma excecao propagada", $lancou5 === false);
    afirmar("CNPJ ausente (caso " . $i . "): marcarConcluida() NUNCA chamado (nao inativa por numero ambiguo)", $clienteSemCnpj->chamadas === 0);
    afirmar("CNPJ ausente (caso " . $i . "): statusAtual() NUNCA chamado", $clienteSemCnpj->chamadasStatusAtual === 0);
    afirmar("CNPJ ausente (caso " . $i . "): pendencia de baixa registrada", $registro5 !== false && $registro5['resolvido_em'] === null);
}

// ============================================================
// Cenario 6 (F4a): o CNPJ chega ao client SO com digitos (normalizado no
// controller) e junto do numero da ordem escolhida.
// ============================================================
$fix6 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PEN6666', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-PEN6666');
$pastas[] = $fix6['pasta_completa'];
$idsAtendimento[] = $fix6['id_atendimento'];
$cliente6 = new OrdemColetaClientDuble(fn() => false, fn() => 'ATIVA');
chamarTentarMarcarOrdemConcluida(montarController($atendimentoDao, $pdo, $cliente6), $fix6['id_atendimento'], 'OC-PEN6666', '11.222.333/0001-81');
afirmar('CNPJ mascarado: marcarConcluida() recebe so digitos e o numero da OC', $cliente6->ultimoCnpj === '11222333000181' && $cliente6->ultimoNumero === 'OC-PEN6666');
afirmar('CNPJ mascarado: statusAtual() tambem recebe so digitos', $cliente6->ultimoCnpjStatus === '11222333000181');

// ============================================================
// Cenario 7 (F4a): finalizar() ponta a ponta le cliente_cnpj do atendimento
// persistido (nao do front) e o repassa ao client.
// ============================================================
$stmtSrc = file_get_contents(__DIR__ . '/../../app/Controller/AtendimentoController.php');
afirmar("finalizar(): chama tentarMarcarOrdemConcluida com atendimento['cliente_cnpj'] (nunca so o numero)", str_contains($stmtSrc, "(string) (\$atendimento['cliente_cnpj'] ?? '')"));
afirmar('Metodos antigos por numero removidos do DAO/Client (so cliente+numero)', !str_contains(file_get_contents(__DIR__ . '/../../app/Dao/OrdemColetaDao.php'), 'function marcarInativaPorNumero') && !str_contains(file_get_contents(__DIR__ . '/../../app/Dao/OrdemColetaDao.php'), 'function statusPorNumero'));

// ============================================================
// Cenario 8 (F4a, apontado pelo QA): finalizar() REAL (subprocesso, Talent falso,
// sem rede) de uma Expedicao com cliente_cnpj vazio / so sem digitos.
// (a) NAO_ENVIADO: o TalentRn real exige CNPJ do depositante com 14 digitos, entao o
//     payload nao monta (202 erro_montagem_payload): nada e baixado, nenhuma pendencia,
//     nenhuma chamada ao externo. (b) JA_ENVIADO (check-in ja aceito antes): resposta de
//     SUCESSO ao motorista, UMA linha de pendencia, NENHUMA chamada ao banco externo
//     (duble que falha se chamado) e LogSistema oc_baixa_falhou motivo=baixa_pendente.
// ============================================================
foreach (['' => 'vazio', '..-/ ' => 'so sem digitos'] as $cnpjSemDigitos => $rotulo) {
    $cnpjSemDigitos = (string) $cnpjSemDigitos;
    $fix8 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PEN8' . strlen($cnpjSemDigitos) . '88', $cnpjSemDigitos, 'SP', true, '12345678', 'CAMINHAO', 'OC-PEN8' . strlen($cnpjSemDigitos));
    $pastas[] = $fix8['pasta_completa'];
    $idsAtendimento[] = $fix8['id_atendimento'];
    $id8 = $fix8['id_atendimento'];
    $pdo->prepare('UPDATE tb_atendimento SET cliente_cnpj = :c WHERE id_atendimento = :id')->execute(['c' => $cnpjSemDigitos, 'id' => $id8]);
    $rodar8 = static function () use ($idTotem, $id8): array {
        $log8 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_pendbaixa_log_' . bin2hex(random_bytes(6)) . '.log';
        file_put_contents($log8, '');
        $r = qaLegadoRodar(__DIR__ . '/_caso_finalizar_baixa_sem_cnpj.php', [$idTotem, $id8, $log8]);
        @unlink($log8);
        $partes = explode("
HTTP_CODE:", $r['saida']);

        return ['bruto' => $partes[0], 'http' => (int) trim($partes[1] ?? '0'), 'corpo' => json_decode(trim(str_replace("TALENT_CHAMADO
", '', $partes[0])), true)];
    };
    $stLog = $pdo->prepare("SELECT origem, nivel, detalhe FROM tb_log_sistema WHERE categoria = 'oc_baixa_falhou' AND id_atendimento = :id");

    // (a) NAO_ENVIADO
    $ra = $rodar8();
    $stmt->execute(['id' => $id8]);
    $stLog->execute(['id' => $id8]);
    afirmar("finalizar() NAO_ENVIADO com CNPJ {$rotulo}: payload nao monta (202), Talent NAO chamado, nenhuma pendencia, nenhum log de baixa, externo intacto",
        $ra['http'] === 202 && !str_contains($ra['bruto'], 'TALENT_CHAMADO') && $stmt->fetchAll() === [] && $stLog->fetchAll() === [] && !str_contains($ra['bruto'], 'EXTERNO_CHAMADO'));

    // (b) JA_ENVIADO
    $pdo->prepare("UPDATE tb_atendimento SET talent_checkin_status = 'ENVIADO', talent_senha = 'JA123', talent_protocolo = 'PROTO-JA' WHERE id_atendimento = :id")->execute(['id' => $id8]);
    $rb = $rodar8();
    $stmt->execute(['id' => $id8]);
    $linhas8 = $stmt->fetchAll();
    $stLog->execute(['id' => $id8]);
    $logs8 = $stLog->fetchAll();
    afirmar("finalizar() JA_ENVIADO com CNPJ {$rotulo}: resposta de SUCESSO ao motorista (HTTP 200, senha/protocolo) sem chamar o Talent", $rb['http'] === 200 && is_array($rb['corpo']) && ($rb['corpo']['sucesso'] ?? null) === true && ($rb['corpo']['dados']['senha'] ?? null) === 'JA123' && !str_contains($rb['bruto'], 'TALENT_CHAMADO'));
    afirmar("finalizar() JA_ENVIADO com CNPJ {$rotulo}: UMA linha em tb_ordem_coleta_pendente_baixa (resolvido_em NULL)", count($linhas8) === 1 && $linhas8[0]['resolvido_em'] === null && $linhas8[0]['numero_ordem_coleta'] === 'OC-PEN8' . strlen($cnpjSemDigitos));
    afirmar("finalizar() JA_ENVIADO com CNPJ {$rotulo}: NENHUMA chamada ao banco externo (duble que falha se chamado)", !str_contains($rb['bruto'], 'EXTERNO_CHAMADO'));
    afirmar("finalizar() JA_ENVIADO com CNPJ {$rotulo}: LogSistema oc_baixa_falhou motivo=baixa_pendente (EXPEDICAO/ERRO)", count($logs8) === 1 && $logs8[0]['detalhe'] === 'motivo=baixa_pendente' && $logs8[0]['origem'] === 'EXPEDICAO' && $logs8[0]['nivel'] === 'ERRO');
}

// ============================================================
// Limpeza
// ============================================================
foreach ($pastas as $p) {
    talentLimparPasta($p);
}
foreach ($idsAtendimento as $id) {
    $pdo->prepare('DELETE FROM tb_ordem_coleta_pendente_baixa WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
qaLegadoLimparAmbiente($bancoQa, $storageQa);
exit($totalFalhas > 0 ? 1 : 0);
