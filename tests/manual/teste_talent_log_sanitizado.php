<?php

/**
 * Teste manual (sem rede real) de sanitizacao de log — item 9 do escopo de
 * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md.
 *
 * Forca varios erros (HTTP 500/401/409/timeout/erro de conexao simulados
 * via TalentClientException, e uma excecao de montagem de payload) e
 * confirma que:
 * - App\Rn\TalentClientException so aceita categorias da allowlist fechada
 *   (nunca string livre vinda de corpo de resposta);
 * - tb_fila_envio.ultimo_erro grava SOMENTE a categoria interna
 *   sanitizada — nunca contem CPF/CNH usados no teste, nem a string
 *   TALENT_API_KEY/base64;
 * - o VALOR configurado em TALENT_API_KEY (.env) nunca aparece em nenhuma
 *   coluna gravada por este fluxo.
 *
 * Uso: php tests/manual/teste_talent_log_sanitizado.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
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

class TalentClientQueLancaErro extends TalentClient
{
    public function __construct(private string $categoria)
    {
        parent::__construct('', '');
    }

    public function checkin(array $payload): array
    {
        throw new TalentClientException($this->categoria);
    }
}

// ============================================================
// 1. TalentClientException: allowlist fechada, nao aceita categoria livre
//    (garante que nenhum corpo bruto de resposta possa virar categoria)
// ============================================================
$lancouCategoriaInvalida = false;
try {
    new TalentClientException('CPF do motorista: 111.444.777-35 invalido segundo o Talent');
} catch (\InvalidArgumentException $e) {
    $lancouCategoriaInvalida = true;
}
afirmar('TalentClientException rejeita categoria fora da allowlist fechada (nunca aceita corpo bruto como categoria)', $lancouCategoriaInvalida);

// ============================================================
// 2. Fluxo real: forcar erro em processarCheckin, registrar na fila,
// conferir que tb_fila_envio.ultimo_erro so tem a categoria sanitizada
// ============================================================
$atendimentoDao = new AtendimentoDao($pdo);
$filaDao = new FilaEnvioDao($pdo);
$empresaDao = new EmpresaDao($pdo);
$empresa = $empresaDao->buscarPorId(1);

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_LOG_' . bin2hex(random_bytes(3)));
$pastas = [];
$idsAtendimento = [];

class TalentClientDeTesteNuncaChamado extends TalentClient
{
    public int $chamadas = 0;

    public function __construct()
    {
        parent::__construct('', '');
    }

    public function checkin(array $payload): array
    {
        $this->chamadas++;
        return ['senha' => null, 'protocolo' => null];
    }
}

$cpfSensivel = '11144477735';
$nomeSensivel = 'MOTORISTA TESTE SANITIZACAO';

$categoriasParaTestar = ['erro_validacao', 'erro_autenticacao', 'nao_encontrado', 'conflito', 'erro_servidor', 'erro_conexao'];

foreach ($categoriasParaTestar as $categoria) {
    $fix = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', strtoupper(substr(md5($categoria), 0, 7)), '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-' . strtoupper(substr(md5($categoria), 0, 7)));
    $idsAtendimento[] = $fix['id_atendimento'];
    $pastas[] = $fix['pasta_completa'];
    // Sobrescreve motorista com CPF/nome sensiveis conhecidos, para procurar
    // literalmente por eles no log depois.
    $atendimentoDao->atualizarValidacaoCnh($fix['id_atendimento'], $nomeSensivel, $cpfSensivel, '2030-01-01', 'MANUAL', 'PENDENTE_REVISAO');

    $cliente = new TalentClientQueLancaErro($categoria);
    $talentRn = new TalentRn($cliente, $filaDao, $atendimentoDao, $_ENV['STORAGE_PATH']);
    $atendimento = $atendimentoDao->buscarPorId($fix['id_atendimento']);
    $resultado = $talentRn->processarCheckin($atendimento, $empresa, []);

    afirmar("Categoria '{$categoria}': processarCheckin devolve exatamente essa categoria (nunca corpo bruto)", $resultado['erro_categoria'] === $categoria);

    $talentRn->registrarFalhaParaReenvio($fix['id_atendimento'], $resultado['erro_categoria']);

    $stmt = $pdo->prepare('SELECT ultimo_erro FROM tb_fila_envio WHERE id_atendimento = :id ORDER BY id_fila DESC LIMIT 1');
    $stmt->execute(['id' => $fix['id_atendimento']]);
    $ultimoErro = $stmt->fetchColumn();

    afirmar("Categoria '{$categoria}': tb_fila_envio.ultimo_erro = categoria sanitizada exata (\"{$ultimoErro}\")", $ultimoErro === $categoria);
    afirmar("Categoria '{$categoria}': ultimo_erro NAO contem o CPF sensivel do teste", !str_contains((string) $ultimoErro, $cpfSensivel));
    afirmar("Categoria '{$categoria}': ultimo_erro NAO contem o nome sensivel do teste", !str_contains((string) $ultimoErro, $nomeSensivel));
    afirmar("Categoria '{$categoria}': ultimo_erro NAO parece base64 longo (nenhum bloco >= 40 chars base64-like)", !preg_match('/[A-Za-z0-9+\/]{40,}={0,2}/', (string) $ultimoErro));
}

// ============================================================
// 3. TALENT_API_KEY real (.env) nunca aparece em nenhuma linha gravada
// ============================================================
$apiKeyReal = $_ENV['TALENT_API_KEY'] ?? '';
if ($apiKeyReal !== '') {
    $stmt = $pdo->prepare('SELECT ultimo_erro FROM tb_fila_envio WHERE id_atendimento IN (' . implode(',', array_fill(0, count($idsAtendimento), '?')) . ')');
    $stmt->execute($idsAtendimento);
    $todosErros = implode('|', $stmt->fetchAll(PDO::FETCH_COLUMN));
    afirmar('TALENT_API_KEY (.env) NUNCA aparece em nenhum ultimo_erro gravado', !str_contains($todosErros, $apiKeyReal));
} else {
    echo "     (TALENT_API_KEY vazio no .env deste ambiente — checagem de vazamento do valor real pulada, nada a comparar)\n";
}

// ============================================================
// 4. erro_montagem_payload (falha ANTES de qualquer HTTP) tambem sanitizado
// ============================================================
$idAtSemDocs = $atendimentoDao->criar($idTotem, 'expedicao', 'NOD0001');
$idsAtendimento[] = $idAtSemDocs;
$atendimentoDao->atualizarEtapa($idAtSemDocs, 'exp_confirmacao');
$atendimentoDao->atualizarValidacaoCnh($idAtSemDocs, $nomeSensivel, $cpfSensivel, '2030-01-01', 'MANUAL', 'PENDENTE_REVISAO');
$atendimentoDao->atualizarValidacaoCrlv($idAtSemDocs, 'NOD0001', 2025, 'SP', '12345678', 'CAMINHAO', 'MANUAL', 'PENDENTE_REVISAO');
$atendimentoDao->definirPasta($idAtSemDocs, 'pasta_sem_arquivos_' . bin2hex(random_bytes(4))); // pasta nunca criada -> anexo_cnh_ausente

$clienteNuncaChamado = new TalentClientDeTesteNuncaChamado();
$talentRnPayload = new TalentRn($clienteNuncaChamado, $filaDao, $atendimentoDao, $_ENV['STORAGE_PATH']);
$atSemDocs = $atendimentoDao->buscarPorId($idAtSemDocs);
$resultadoPayload = $talentRnPayload->processarCheckin($atSemDocs, $empresa, []);
afirmar('Falha na montagem do payload (anexo ausente) -> ERRO_REPROCESSAVEL, categoria erro_montagem_payload', $resultadoPayload['status'] === 'ERRO_REPROCESSAVEL' && $resultadoPayload['erro_categoria'] === 'erro_montagem_payload');
afirmar('Falha de montagem de payload NUNCA chega a chamar o TalentClient (nenhuma tentativa de rede)', $clienteNuncaChamado->chamadas === 0);

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
