<?php

/**
 * Teste dedicado de /03-revisao (item 2 do escopo em
 * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md, secao
 * "Nova rodada de /01-implementacao (2026-09-10)") — confirma, com um
 * atendimento de teste NOVO 100% valido (posse, tipo, status, etapa,
 * CNH/CRLV aprovados incluindo RNTC/UF/tipo validos, cliente com CNPJ
 * valido, totem com empresa vinculada), que:
 *
 *  1. AtendimentoController::finalizar() SEMPRE responde com o bloqueio
 *     TALENT_DOCTOS_PENDENTE em HTTP 501 (codigo HTTP real, capturado via
 *     register_shutdown_function porque Resposta::erro() chama exit()).
 *  2. talent_checkin_status permanece EXATAMENTE 'NAO_ENVIADO' apos a
 *     chamada (nunca muda).
 *  3. Nenhuma chamada de rede real ao Talent ocorre — provado de duas
 *     formas independentes:
 *     a) TalentRnEspiao (subclasse de TalentRn) lanca excecao se
 *        processarCheckin() for invocado — nunca deve ser chamado;
 *     b) o TalentClient injetado usa TALENT_API_URL/TALENT_API_KEY REAIS do
 *        .env (mesma config de producao, via public/api/atendimento.php),
 *        para provar que a trava bloqueia MESMO com credenciais reais
 *        configuradas — nao e a ausencia de config que impede a chamada.
 *
 * Tudo rodado em subprocesso isolado (_caso_trava_doctos_pendente.php) para
 * capturar o HTTP real via register_shutdown_function sem afetar o processo
 * deste script.
 *
 * Uso: php tests/manual/teste_talent_trava_doctos_pendente.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;

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

$atendimentoDao = new AtendimentoDao($pdo);

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_TRAVA_DOCTOS_' . bin2hex(random_bytes(3)), 1);

$pastas = [];
$idsAtendimento = [];

// Atendimento 100% valido: expedicao
$fixExp = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'TRV1111');
$pastas[] = $fixExp['pasta_completa'];
$idsAtendimento[] = $fixExp['id_atendimento'];

// Atendimento 100% valido: recebimento
$fixRec = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'recebimento', 'TRV2222');
$pastas[] = $fixRec['pasta_completa'];
$idsAtendimento[] = $fixRec['id_atendimento'];

function rodarCasoDedicado(int $idTotem, int $idAtendimento): array
{
    $php = PHP_BINARY;
    $script = __DIR__ . '/_caso_trava_doctos_pendente.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $idTotem . ' ' . $idAtendimento;
    exec($cmd, $saida, $codigo);
    $texto = implode("\n", $saida);
    return ['texto' => $texto, 'codigo' => $codigo];
}

foreach ([['expedicao', $fixExp], ['recebimento', $fixRec]] as [$rotulo, $fix]) {
    $idAtendimento = $fix['id_atendimento'];

    // Pre-condicao: estado exatamente NAO_ENVIADO antes da chamada
    $antes = $atendimentoDao->buscarPorId($idAtendimento);
    afirmar("[$rotulo] pre-condicao: talent_checkin_status = NAO_ENVIADO antes da chamada", $antes['talent_checkin_status'] === 'NAO_ENVIADO');

    $r = rodarCasoDedicado($idTotem, $idAtendimento);

    afirmar("[$rotulo] subprocesso terminou sem excecao fatal (TalentRnEspiao NAO foi acionado)", $r['codigo'] === 0);
    afirmar("[$rotulo] resposta contem o codigo TALENT_DOCTOS_PENDENTE", str_contains($r['texto'], 'TALENT_DOCTOS_PENDENTE'));
    afirmar("[$rotulo] HTTP real capturado via register_shutdown_function e exatamente 501", str_contains($r['texto'], 'HTTP_CODE:501'));
    afirmar("[$rotulo] resposta NAO contem sucesso:true", !str_contains($r['texto'], '"sucesso":true'));
    afirmar("[$rotulo] resposta NAO contem marcador de chamada real ao TalentRn::processarCheckin", !str_contains($r['texto'], 'ESPIAO_PROCESSARCHECKIN_CHAMADO'));

    $depois = $atendimentoDao->buscarPorId($idAtendimento);
    afirmar("[$rotulo] talent_checkin_status permanece EXATAMENTE NAO_ENVIADO depois da chamada", $depois['talent_checkin_status'] === 'NAO_ENVIADO');
    afirmar("[$rotulo] talent_tentativa_id continua NULL (nenhum CAS de idempotencia foi acionado)", $depois['talent_tentativa_id'] === null);
}

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
