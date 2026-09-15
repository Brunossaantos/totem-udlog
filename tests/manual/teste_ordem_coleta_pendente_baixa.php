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

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
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
 * Duble de OrdemColetaClient — NUNCA toca no banco externo real
 * (ConexaoGestaoColetas). Comportamento configurado por closure.
 */
class OrdemColetaClientDuble extends OrdemColetaClient
{
    public int $chamadas = 0;
    public int $chamadasStatusAtual = 0;
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

    public function marcarConcluida(string $numero): bool
    {
        $this->chamadas++;
        return ($this->comportamento)($numero);
    }

    public function statusAtual(string $numero): ?string
    {
        $this->chamadasStatusAtual++;
        return ($this->comportamentoStatus)($numero);
    }
}

function chamarTentarMarcarOrdemConcluida(AtendimentoController $controller, int $idAtendimento, string $numero): void
{
    $reflexao = new ReflectionMethod(AtendimentoController::class, 'tentarMarcarOrdemConcluida');
    $reflexao->setAccessible(true);
    $reflexao->invoke($controller, $idAtendimento, $numero);
}

$atendimentoDao = new AtendimentoDao($pdo);
$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_ORDEM_PENDBAIXA_' . bin2hex(random_bytes(3)), 1);
$pastas = [];
$idsAtendimento = [];

function montarController(AtendimentoDao $atendimentoDao, PDO $pdo, OrdemColetaClient $ordemColetaClient): AtendimentoController
{
    $atendimentoRn = new AtendimentoRn($atendimentoDao, new OrdemColetaClient(new OrdemColetaDao()));
    $talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);
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
exit($totalFalhas > 0 ? 1 : 0);
