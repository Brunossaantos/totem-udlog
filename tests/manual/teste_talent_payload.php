<?php

/**
 * Teste manual (unitario, sem rede real) do payload montado por
 * App\Rn\TalentRn::montarPayload() (metodo privado, acessado via Reflection
 * — mesmo espirito dos demais testes manuais do projeto, que preferem
 * checar o comportamento real do codigo de producao a duplicar logica em
 * um mock).
 *
 * ATUALIZADO em 2026-09-14 (demanda talent-doctos-finalizacao-checkin) —
 * doctos[] agora e implementado de verdade e tipoEmbDesemb foi corrigido
 * para capitalizado (Swagger oficial), substituindo as asserções antigas
 * (doctos ausente / minusculo). Cobre:
 *
 * - cnpjArmazem vem SO de tb_empresa (via totem), nunca do atendimento;
 * - cnpjDepositante validado com 14 digitos (aceita/rejeita);
 * - tipoEmbDesemb CAPITALIZADO correto por tipo (Embarque/Desembarque);
 * - doctos[] presente: Expedicao = 1 entrada ORDEM_COLETA; Recebimento = 1
 *   entrada NOTA_FISCAL por nota (numero_nota preenchido);
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
use App\Dao\AtendimentoNotaDao;
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
$notaDao = new AtendimentoNotaDao($pdo);
$empresaDao = new EmpresaDao($pdo);
$talentRn = new TalentRn(new TalentClient('', ''), new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);

$empresaMauaI = $empresaDao->buscarPorId(1);
afirmar('Fixture: empresa Maua I (id 1) existe (migration 008 aplicada)', $empresaMauaI !== null && $empresaMauaI['cnpj'] === '14706199000182');

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_PAYLOAD_' . bin2hex(random_bytes(3)));

$pastas = [];

// ============================================================
// Cenario 1: Expedicao, sem ajudante
// ============================================================
$fixExp = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'PAY1111', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-PAY1111');
$pastas[] = $fixExp['pasta_completa'];
$atExp = $atendimentoDao->buscarPorId($fixExp['id_atendimento']);

$payloadExp = chamarMontarPayload($talentRn, $atExp, [], $empresaMauaI);

afirmar('Expedicao: cnpjArmazem vem da empresa do totem (Maua I)', $payloadExp['cnpjArmazem'] === '14706199000182');
afirmar('Expedicao: cnpjDepositante = cliente_cnpj normalizado (14 digitos)', $payloadExp['cnpjDepositante'] === '11222333000181');
afirmar('Expedicao: tipoEmbDesemb = "Embarque" (capitalizado, Swagger oficial)', $payloadExp['tipoEmbDesemb'] === 'Embarque');
afirmar('Expedicao: veiculo.placa correta', $payloadExp['veiculo']['placa'] === 'PAY1111');
afirmar('Expedicao: veiculo.uf correta', $payloadExp['veiculo']['uf'] === 'SP');
afirmar('Expedicao: veiculo.rntc presente e correto', $payloadExp['veiculo']['rntc'] === '12345678');
afirmar('Expedicao: veiculo.tipo presente e correto', $payloadExp['veiculo']['tipo'] === 'CAMINHAO');
afirmar('Expedicao: motorista.cpf/nome corretos', $payloadExp['motorista']['cpf'] === '11144477735' && $payloadExp['motorista']['nome'] === 'MOTORISTA TESTE');
afirmar("Expedicao: 'doctos' presente com exatamente 1 entrada ORDEM_COLETA", array_key_exists('doctos', $payloadExp) && count($payloadExp['doctos']) === 1 && $payloadExp['doctos'][0]['tipo'] === 'ORDEM_COLETA');
afirmar('Expedicao: doctos[0].nrDocto = ordem_coleta do atendimento', $payloadExp['doctos'][0]['nrDocto'] === 'OC-PAY1111');
afirmar("Expedicao: 'ajudantes' AUSENTE (nenhum ajudante informado)", !array_key_exists('ajudantes', $payloadExp));
foreach (['reboque', 'exigePesagem', 'cnpjTransportadora', 'nomeTransportadora', 'temPernoite', 'paletes', 'nrContainer', 'lacreContainer', 'delivery', 'obs'] as $campoOpcional) {
    afirmar("Expedicao: campo opcional sem fonte '{$campoOpcional}' AUSENTE do payload", !array_key_exists($campoOpcional, $payloadExp));
}
afirmar("Expedicao: 'anexos' presente com CNH+CRLV (2 itens, sem notas) — formato ATIVO (nao anexosGZip)", count($payloadExp['anexos']) === 2 && !array_key_exists('anexosGZip', $payloadExp));
afirmar('Expedicao: anexoBase64 e string nao vazia (PDF real gerado)', is_string($payloadExp['anexos'][0]['anexoBase64']) && strlen($payloadExp['anexos'][0]['anexoBase64']) > 100);
afirmar('Expedicao: descricao do 1o anexo e "CNH"', $payloadExp['anexos'][0]['descricao'] === 'CNH');
afirmar('Expedicao: descricao do 2o anexo e "CRLV"', $payloadExp['anexos'][1]['descricao'] === 'CRLV');
// Nenhum campo do payload serializado pode ser null/string vazia (checagem ampla)
$jsonExp = json_encode($payloadExp);
afirmar('Expedicao: JSON serializado nao contem valor "null" (nenhum campo opcional viaja como null)', !str_contains($jsonExp, ':null'));

// ============================================================
// Cenario 1b: Expedicao SEM ordem_coleta — doctos[] deve lancar excecao
// (defesa em profundidade, mesmo que o gate de finalizar() ja devesse ter
// barrado isso antes)
// ============================================================
$atExpSemOrdem = $atExp;
$atExpSemOrdem['ordem_coleta'] = null;
$lancouExcecaoSemOrdem = false;
try {
    chamarMontarPayload($talentRn, $atExpSemOrdem, [], $empresaMauaI);
} catch (\Throwable $e) {
    $lancouExcecaoSemOrdem = str_contains($e->getMessage(), 'doctos_ordem_coleta_ausente');
}
afirmar('Expedicao sem ordem_coleta lanca excecao (doctos_ordem_coleta_ausente) — doctos[] nunca vazio', $lancouExcecaoSemOrdem);

// ============================================================
// Cenario 2: Recebimento, COM ajudante
// ============================================================
$fixRec = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'PAY2222', '22333444000199', 'RJ');
$pastas[] = $fixRec['pasta_completa'];
$atendimentoDao->salvarAjudante($fixRec['id_atendimento'], 'AJUDANTE TESTE', '22233344456');

// 3 notas, uma delas com zero a esquerda no numero digitado (normalizado
// pelo backend em AtendimentoNotaDao::atualizarNumero/NotaFiscalRn::
// atualizarNumeroNota antes de chegar aqui — aqui simulamos o valor JA
// normalizado, que e o unico que chega ate montarPayload).
talentInserirNotaComNumero($notaDao, $fixRec['id_atendimento'], 1, '789'); // veio de "00789" digitado, ja normalizado
talentInserirNotaComNumero($notaDao, $fixRec['id_atendimento'], 2, '1001');
talentInserirNotaComNumero($notaDao, $fixRec['id_atendimento'], 3, '55');

$atRec = $atendimentoDao->buscarPorId($fixRec['id_atendimento']);
$notasRec = $notaDao->listarPorAtendimento($fixRec['id_atendimento']);

$payloadRec = chamarMontarPayload($talentRn, $atRec, $notasRec, $empresaMauaI);

afirmar('Recebimento: tipoEmbDesemb = "Desembarque" (capitalizado, Swagger oficial)', $payloadRec['tipoEmbDesemb'] === 'Desembarque');
afirmar('Recebimento: cnpjDepositante correto', $payloadRec['cnpjDepositante'] === '22333444000199');
afirmar('Recebimento: veiculo.uf correta (RJ)', $payloadRec['veiculo']['uf'] === 'RJ');
afirmar("Recebimento: 'ajudantes' presente com exatamente 1 item", array_key_exists('ajudantes', $payloadRec) && count($payloadRec['ajudantes']) === 1);
afirmar('Recebimento: ajudante nome/cpf corretos', $payloadRec['ajudantes'][0]['nome'] === 'AJUDANTE TESTE' && $payloadRec['ajudantes'][0]['cpf'] === '22233344456');
afirmar("Recebimento: 'doctos' presente com exatamente 3 entradas NOTA_FISCAL (1 por nota)", array_key_exists('doctos', $payloadRec) && count($payloadRec['doctos']) === 3);
afirmar('Recebimento: todas as entradas de doctos sao tipo NOTA_FISCAL', array_reduce($payloadRec['doctos'], fn($ok, $d) => $ok && $d['tipo'] === 'NOTA_FISCAL', true));
afirmar('Recebimento: nrDocto[0] = numero ja normalizado (zero a esquerda ja removido a montante)', $payloadRec['doctos'][0]['nrDocto'] === '789');
afirmar('Recebimento: nrDocto[1]/nrDocto[2] corretos, ordem preservada', $payloadRec['doctos'][1]['nrDocto'] === '1001' && $payloadRec['doctos'][2]['nrDocto'] === '55');

// ============================================================
// Cenario 2b: Recebimento com UMA nota sem numero_nota — doctos[] deve
// lancar excecao (defesa em profundidade — o gate de finalizar() ja
// deveria ter barrado isso como NOTAS_SEM_NUMERO antes de chegar aqui)
// ============================================================
$fixRecSemNumero = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'PAY2233', '22333444000199', 'RJ');
$pastas[] = $fixRecSemNumero['pasta_completa'];
talentInserirNotaComNumero($notaDao, $fixRecSemNumero['id_atendimento'], 1, '111');
$notaDao->inserir($fixRecSemNumero['id_atendimento'], 2, 'nota_02.jpg', null, null, false); // SEM numero_nota
$atRecSemNumero = $atendimentoDao->buscarPorId($fixRecSemNumero['id_atendimento']);
$notasRecSemNumero = $notaDao->listarPorAtendimento($fixRecSemNumero['id_atendimento']);
$lancouExcecaoNotaSemNumero = false;
try {
    chamarMontarPayload($talentRn, $atRecSemNumero, $notasRecSemNumero, $empresaMauaI);
} catch (\Throwable $e) {
    $lancouExcecaoNotaSemNumero = str_contains($e->getMessage(), 'doctos_nota_sem_numero');
}
afirmar('Recebimento com 1 nota sem numero_nota lanca excecao (doctos_nota_sem_numero) — doctos[] nunca incompleto', $lancouExcecaoNotaSemNumero);

// ============================================================
// Cenario 2c: Recebimento sem NENHUMA nota — doctos[] deve lancar excecao
// (nunca doctos: [] vazio)
// ============================================================
$fixRecSemNotas = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'PAY2244', '22333444000199', 'RJ');
$pastas[] = $fixRecSemNotas['pasta_completa'];
$atRecSemNotas = $atendimentoDao->buscarPorId($fixRecSemNotas['id_atendimento']);
$lancouExcecaoSemNotas = false;
try {
    chamarMontarPayload($talentRn, $atRecSemNotas, [], $empresaMauaI);
} catch (\Throwable $e) {
    $lancouExcecaoSemNotas = str_contains($e->getMessage(), 'doctos_notas_ausentes');
}
afirmar('Recebimento sem nenhuma nota lanca excecao (doctos_notas_ausentes) — nunca envia doctos:[] vazio', $lancouExcecaoSemNotas);

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
// Cenario 8: numero_nota duplicado no MESMO atendimento e bloqueado pela
// UNIQUE KEY uk_atendimento_numero_nota (migration 012)
// ============================================================
$fixDup = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'PAY9999', '22333444000199', 'RJ');
$pastas[] = $fixDup['pasta_completa'];
talentInserirNotaComNumero($notaDao, $fixDup['id_atendimento'], 1, '999');
$idNota2Dup = $notaDao->inserir($fixDup['id_atendimento'], 2, 'nota_02.jpg', null, null, false);
$lancouViolacaoDuplicado = false;
try {
    $notaDao->atualizarNumero($idNota2Dup, '999', 'MANUAL'); // mesmo numero da nota 1, mesmo atendimento
} catch (\PDOException $e) {
    $lancouViolacaoDuplicado = $e->getCode() === '23000';
}
afirmar('numero_nota duplicado no mesmo atendimento e bloqueado pela UNIQUE KEY (violacao 23000)', $lancouViolacaoDuplicado);

// ============================================================
// Limpeza
// ============================================================
foreach ($pastas as $p) {
    talentLimparPasta($p);
}
$idsTotem = $pdo->prepare('SELECT id_atendimento FROM tb_atendimento WHERE id_totem = :id');
$idsTotem->execute(['id' => $idTotem]);
foreach ($idsTotem->fetchAll(PDO::FETCH_COLUMN) as $idAt) {
    $pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $idAt]);
}
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_totem = :id')->execute(['id' => $idTotem]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
