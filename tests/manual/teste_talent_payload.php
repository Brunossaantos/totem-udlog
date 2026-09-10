<?php

/**
 * Teste manual (unitario, sem rede real) do payload montado por
 * App\Rn\TalentRn::montarPayload() (metodo privado, acessado via Reflection
 * — mesmo espirito dos demais testes manuais do projeto, que preferem
 * checar o comportamento real do codigo de producao a duplicar logica em
 * um mock). Cobre o item 3 do escopo de
 * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md:
 *
 * - cnpjArmazem vem SO de tb_empresa (via totem), nunca do atendimento;
 * - cnpjDepositante validado com 14 digitos (aceita/rejeita);
 * - tipoEmbDesemb minusculo correto por tipo (embarque/desembarque);
 * - doctos[] AUSENTE do payload (decisao desta rodada, sem mapeamento
 *   confirmado de nrDocto);
 * - campos opcionais sem fonte (reboque/exigePesagem/transportadora/
 *   telefones/nrCNH/categoriaCNH/pernoite/paletes/container/lacre/
 *   delivery/obs) NUNCA aparecem no payload (nem null, nem vazio);
 * - ajudantes[] presente SO quando ha 1 ajudante valido.
 *
 * TalentClient e instanciado com URL/token vazios e NUNCA invocado neste
 * teste (montarPayload nao faz rede) — nenhuma chamada de rede real ao
 * Talent.
 *
 * Uso: php tests/manual/teste_talent_payload.php
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

function chamarMontarPayload(TalentRn $talentRn, array $atendimento, array $notas, array $empresa): array
{
    $reflexao = new ReflectionMethod(TalentRn::class, 'montarPayload');
    $reflexao->setAccessible(true);
    return $reflexao->invoke($talentRn, $atendimento, $notas, $empresa);
}

$atendimentoDao = new AtendimentoDao($pdo);
$empresaDao = new EmpresaDao($pdo);
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);

$empresaMauaI = $empresaDao->buscarPorId(1);
afirmar('Fixture: empresa Maua I (id 1) existe (migration 008 aplicada)', $empresaMauaI !== null && $empresaMauaI['cnpj'] === '14706199000182');

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_PAYLOAD_' . bin2hex(random_bytes(3)));

$pastas = [];

// ============================================================
// Cenario 1: Expedicao, sem ajudante
// ============================================================
$fixExp = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PAY1111');
$pastas[] = $fixExp['pasta_completa'];
$atExp = $atendimentoDao->buscarPorId($fixExp['id_atendimento']);

$payloadExp = chamarMontarPayload($talentRn, $atExp, [], $empresaMauaI);

afirmar('Expedicao: cnpjArmazem vem da empresa do totem (Maua I)', $payloadExp['cnpjArmazem'] === '14706199000182');
afirmar('Expedicao: cnpjDepositante = cliente_cnpj normalizado (14 digitos)', $payloadExp['cnpjDepositante'] === '11222333000181');
afirmar('Expedicao: tipoEmbDesemb = "embarque" (minusculo)', $payloadExp['tipoEmbDesemb'] === 'embarque');
afirmar('Expedicao: veiculo.placa correta', $payloadExp['veiculo']['placa'] === 'PAY1111');
afirmar('Expedicao: veiculo.uf correta', $payloadExp['veiculo']['uf'] === 'SP');
afirmar('Expedicao: veiculo.rntc presente e correto', $payloadExp['veiculo']['rntc'] === '12345678');
afirmar('Expedicao: veiculo.tipo presente e correto', $payloadExp['veiculo']['tipo'] === 'CAMINHAO');
afirmar('Expedicao: motorista.cpf/nome corretos', $payloadExp['motorista']['cpf'] === '11144477735' && $payloadExp['motorista']['nome'] === 'MOTORISTA TESTE');
afirmar("Expedicao: 'doctos' AUSENTE do payload (sem mapeamento confirmado)", !array_key_exists('doctos', $payloadExp));
afirmar("Expedicao: 'ajudantes' AUSENTE (nenhum ajudante informado)", !array_key_exists('ajudantes', $payloadExp));
foreach (['reboque', 'exigePesagem', 'cnpjTransportadora', 'nomeTransportadora', 'temPernoite', 'paletes', 'nrContainer', 'lacreContainer', 'delivery', 'obs'] as $campoOpcional) {
    afirmar("Expedicao: campo opcional sem fonte '{$campoOpcional}' AUSENTE do payload", !array_key_exists($campoOpcional, $payloadExp));
}
afirmar('Expedicao: anexos[] presente com CNH+CRLV (2 itens, sem notas)', count($payloadExp['anexos']) === 2);
afirmar('Expedicao: anexoBase64 e string nao vazia (PDF real gerado)', is_string($payloadExp['anexos'][0]['anexoBase64']) && strlen($payloadExp['anexos'][0]['anexoBase64']) > 100);
afirmar('Expedicao: descricao do 1o anexo e "CNH"', $payloadExp['anexos'][0]['descricao'] === 'CNH');
afirmar('Expedicao: descricao do 2o anexo e "CRLV"', $payloadExp['anexos'][1]['descricao'] === 'CRLV');
// Nenhum campo do payload serializado pode ser null/string vazia (checagem ampla)
$jsonExp = json_encode($payloadExp);
afirmar('Expedicao: JSON serializado nao contem valor "null" (nenhum campo opcional viaja como null)', !str_contains($jsonExp, ':null'));

// ============================================================
// Cenario 2: Recebimento, COM ajudante
// ============================================================
$fixRec = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'PAY2222', '22333444000199', 'RJ');
$pastas[] = $fixRec['pasta_completa'];
$atendimentoDao->salvarAjudante($fixRec['id_atendimento'], 'AJUDANTE TESTE', '22233344456');
$atRec = $atendimentoDao->buscarPorId($fixRec['id_atendimento']);

$payloadRec = chamarMontarPayload($talentRn, $atRec, [], $empresaMauaI);

afirmar('Recebimento: tipoEmbDesemb = "desembarque" (minusculo)', $payloadRec['tipoEmbDesemb'] === 'desembarque');
afirmar('Recebimento: cnpjDepositante correto', $payloadRec['cnpjDepositante'] === '22333444000199');
afirmar('Recebimento: veiculo.uf correta (RJ)', $payloadRec['veiculo']['uf'] === 'RJ');
afirmar("Recebimento: 'ajudantes' presente com exatamente 1 item", array_key_exists('ajudantes', $payloadRec) && count($payloadRec['ajudantes']) === 1);
afirmar('Recebimento: ajudante nome/cpf corretos', $payloadRec['ajudantes'][0]['nome'] === 'AJUDANTE TESTE' && $payloadRec['ajudantes'][0]['cpf'] === '22233344456');
afirmar("Recebimento: 'doctos' AUSENTE do payload", !array_key_exists('doctos', $payloadRec));

// ============================================================
// Cenario 3: cnpjDepositante invalido (nao tem 14 digitos) — deve lancar excecao
// ============================================================
$fixInvalido = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'PAY3333', '123', 'SP');
$pastas[] = $fixInvalido['pasta_completa'];
$atInvalido = $atendimentoDao->buscarPorId($fixInvalido['id_atendimento']);

$lancouExcecaoCnpj = false;
try {
    chamarMontarPayload($talentRn, $atInvalido, [], $empresaMauaI);
} catch (\Throwable $e) {
    $lancouExcecaoCnpj = ($e->getPrevious() ?? $e)->getMessage() === 'cnpj_depositante_invalido'
        || str_contains($e->getMessage(), 'cnpj_depositante_invalido');
}
afirmar('cnpjDepositante com menos de 14 digitos lanca excecao (cnpj_depositante_invalido)', $lancouExcecaoCnpj);

// ============================================================
// Cenario 4: empresa com cnpj invalido (defesa em profundidade) — deve lancar excecao
// ============================================================
$lancouExcecaoArmazem = false;
try {
    chamarMontarPayload($talentRn, $atExp, [], ['cnpj' => '123']);
} catch (\Throwable $e) {
    $lancouExcecaoArmazem = str_contains($e->getMessage(), 'cnpj_armazem_invalido');
}
afirmar('cnpjArmazem invalido (empresa mal configurada) lanca excecao (cnpj_armazem_invalido)', $lancouExcecaoArmazem);

// ============================================================
// Cenario 5: UF invalida no atendimento — deve lancar excecao (veiculo_invalido)
// ============================================================
$atComUfInvalida = $atExp;
$atComUfInvalida['crlv_uf'] = 'XX';
$lancouExcecaoUf = false;
try {
    chamarMontarPayload($talentRn, $atComUfInvalida, [], $empresaMauaI);
} catch (\Throwable $e) {
    $lancouExcecaoUf = str_contains($e->getMessage(), 'veiculo_invalido');
}
afirmar('UF fora da lista fechada de 27 siglas lanca excecao (veiculo_invalido)', $lancouExcecaoUf);

// ============================================================
// Cenario 6: rntc vazio no atendimento (apesar do gate de finalizar()) —
// defesa em profundidade, montarPayload deve lancar excecao mesmo assim
// ============================================================
$atComRntcVazio = $atExp;
$atComRntcVazio['crlv_rntc'] = '';
$lancouExcecaoRntc = false;
try {
    chamarMontarPayload($talentRn, $atComRntcVazio, [], $empresaMauaI);
} catch (\Throwable $e) {
    $lancouExcecaoRntc = str_contains($e->getMessage(), 'veiculo_invalido');
}
afirmar('veiculo.rntc vazio lanca excecao (veiculo_invalido), mesmo com o resto do atendimento OK', $lancouExcecaoRntc);

// ============================================================
// Cenario 7: tipo de veiculo vazio no atendimento — mesma defesa em profundidade
// ============================================================
$atComTipoVazio = $atExp;
$atComTipoVazio['crlv_tipo_veiculo'] = '';
$lancouExcecaoTipo = false;
try {
    chamarMontarPayload($talentRn, $atComTipoVazio, [], $empresaMauaI);
} catch (\Throwable $e) {
    $lancouExcecaoTipo = str_contains($e->getMessage(), 'veiculo_invalido');
}
afirmar('veiculo.tipo vazio lanca excecao (veiculo_invalido), mesmo com o resto do atendimento OK', $lancouExcecaoTipo);

// ============================================================
// Limpeza
// ============================================================
foreach ($pastas as $p) {
    talentLimparPasta($p);
}
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_totem = :id')->execute(['id' => $idTotem]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
