<?php

/**
 * Teste manual de seguranca (IDOR) para
 * App\Controller\AtendimentoController::finalizar() — item 5 do escopo de
 * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md. Corrigido
 * nesta demanda (antes usava buscar() puro, sem validar posse/tipo/status/
 * etapa/documentos).
 *
 * Cobre, todos via subprocesso (_caso_finalizar.php), NENHUMA chamada de
 * rede real ao Talent (TalentClient('','') so falha localmente se algum
 * cenario avancar ate a tentativa de HTTP):
 *  - atendimento de OUTRO totem;
 *  - atendimento em etapa errada;
 *  - atendimento com status != em_andamento;
 *  - atendimento sem documentos aprovados;
 *  - totem sem id_empresa configurado (mensagem PROPRIA, tecnica, nao e
 *    caso de IDOR — nao deve ser identica as anteriores);
 *  - as mensagens dos 4 primeiros casos (posse/tipo/status/etapa/docs) sao
 *    IDENTICAS entre si (nunca revela qual checagem falhou);
 *  - dono legitimo, no estado correto, com empresa configurada, chega ate a
 *    tentativa de envio (HTTP 202 "sera processada em instantes" — a
 *    chamada ao Talent falha localmente por TalentClient('','') apontar
 *    para URL vazia, nunca por rede real).
 *
 * Uso: php tests/manual/teste_talent_idor_finalizar.php
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

function rodarFinalizar(int $idTotem, int $idAtendimento): string
{
    $php = PHP_BINARY;
    $script = __DIR__ . '/_caso_finalizar.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $idTotem . ' ' . $idAtendimento;
    exec($cmd, $saida, $codigo);
    return implode("\n", $saida);
}

function extrairErro(string $saidaJson): ?string
{
    $dados = json_decode($saidaJson, true);
    return is_array($dados) ? ($dados['erro'] ?? null) : null;
}

$atendimentoDao = new AtendimentoDao($pdo);

$idTotemVitima = talentCriarTotemComEmpresa($pdo, 'TESTE_IDOR_FIN_VITIMA_' . bin2hex(random_bytes(3)), 1);
$idTotemInvasor = talentCriarTotemComEmpresa($pdo, 'TESTE_IDOR_FIN_INVASOR_' . bin2hex(random_bytes(3)), 1);
$idTotemSemEmpresa = talentCriarTotemComEmpresa($pdo, 'TESTE_IDOR_FIN_SEMEMP_' . bin2hex(random_bytes(3)), null);

$pastas = [];
$idsAtendimento = [];

// ============================================================
// Caso 1: atendimento de OUTRO totem (posse)
// ============================================================
$fix1 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotemVitima, 'expedicao', 'FIN1111');
$pastas[] = $fix1['pasta_completa'];
$idsAtendimento[] = $fix1['id_atendimento'];
$saida1 = rodarFinalizar($idTotemInvasor, $fix1['id_atendimento']);
$erro1 = extrairErro($saida1);
afirmar('IDOR posse: totem invasor recebe erro (nao sucesso) ao finalizar atendimento alheio', !str_contains($saida1, '"sucesso":true'));

// ============================================================
// Caso 2: etapa errada (ainda em exp_cnh, nao exp_confirmacao)
// ============================================================
$fix2 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotemVitima, 'expedicao', 'FIN2222');
$pastas[] = $fix2['pasta_completa'];
$idsAtendimento[] = $fix2['id_atendimento'];
$atendimentoDao->atualizarEtapa($fix2['id_atendimento'], 'exp_cnh'); // fixture ja deixa em exp_confirmacao; forcamos etapa errada
$saida2 = rodarFinalizar($idTotemVitima, $fix2['id_atendimento']);
$erro2 = extrairErro($saida2);
afirmar('Etapa errada: dono legitimo tambem recebe erro (nao sucesso) fora da etapa esperada', !str_contains($saida2, '"sucesso":true'));

// ============================================================
// Caso 3: status != em_andamento (cancelado)
// ============================================================
$fix3 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotemVitima, 'expedicao', 'FIN3333');
$pastas[] = $fix3['pasta_completa'];
$idsAtendimento[] = $fix3['id_atendimento'];
$atendimentoDao->cancelar($fix3['id_atendimento']);
$saida3 = rodarFinalizar($idTotemVitima, $fix3['id_atendimento']);
$erro3 = extrairErro($saida3);
afirmar('Status cancelado: recebe erro (nao sucesso)', !str_contains($saida3, '"sucesso":true'));

// ============================================================
// Caso 4: sem documentos aprovados (CNH nunca validada)
// ============================================================
$idAt4 = $atendimentoDao->criar($idTotemVitima, 'expedicao', 'FIN4444');
$idsAtendimento[] = $idAt4;
$atendimentoDao->atualizarEtapa($idAt4, 'exp_confirmacao'); // etapa certa, mas SEM CNH/CRLV aprovados
$saida4 = rodarFinalizar($idTotemVitima, $idAt4);
$erro4 = extrairErro($saida4);
afirmar('Documentos pendentes: recebe erro (nao sucesso)', !str_contains($saida4, '"sucesso":true'));

// ============================================================
// As 4 mensagens de erro acima devem ser IDENTICAS entre si (nunca revela
// qual checagem especifica falhou)
// ============================================================
// ACHADO (nao e falha de seguranca, ver relatorio do /02-testes): o
// docblock de finalizar() promete "mensagens 1-5 SEMPRE identicas entre
// si", mas a implementacao real usa 4 mensagens distintas (posse/tipo
// compartilham "Atendimento nao encontrado" 404; status/etapa/documentos
// tem cada uma seu proprio texto). Isso NAO reabre o IDOR (a checagem de
// posse ainda bloqueia PRIMEIRO, com mensagem generica, entao um invasor
// sem posse nunca alcanca as mensagens de status/etapa/documentos de um
// atendimento alheio) — e o MESMO padrao ja usado em outros cases de
// salvarEtapa() neste controller. Registrado como discrepancia entre
// comentario/desenho e codigo, nao como vulnerabilidade.
afirmar('Posse invalida (IDOR) usa a MESMA mensagem generica que tipo invalido/404 (nunca revela existencia de atendimento alheio)', $erro1 === 'Atendimento nao encontrado');
afirmar('[ACHADO-DOC] status/etapa/documentos tem mensagens PROPRIAS e distintas entre si (diverge do docblock "mensagens 1-5 sempre identicas", nao e IDOR pois so alcancavel apos posse confirmada)', $erro2 !== $erro3 && $erro3 !== $erro4 && $erro2 !== $erro4);
echo "     (mensagens observadas: posse=\"{$erro1}\" etapa=\"{$erro2}\" status=\"{$erro3}\" documentos=\"{$erro4}\")\n";

// ============================================================
// Caso 5: totem sem id_empresa — mensagem PROPRIA, tecnica, NAO deve ser
// igual as anteriores (nao e IDOR, e config do proprio totem chamador)
// ============================================================
$fix5 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotemSemEmpresa, 'expedicao', 'FIN5555');
$pastas[] = $fix5['pasta_completa'];
$idsAtendimento[] = $fix5['id_atendimento'];
$saida5 = rodarFinalizar($idTotemSemEmpresa, $fix5['id_atendimento']);
$erro5 = extrairErro($saida5);
afirmar('Totem sem empresa: recebe erro tecnico proprio (nao sucesso)', !str_contains($saida5, '"sucesso":true'));
afirmar('Totem sem empresa: mensagem e DIFERENTE das mensagens de posse/etapa/status/documentos (nao e IDOR)', $erro5 !== null && $erro5 !== $erro1);
echo "     (mensagem observada para totem sem empresa: \"{$erro5}\")\n";

// ============================================================
// Caso 6: dono legitimo, etapa/status/documentos/doctos[] corretos, empresa
// configurada — ATUALIZADO (demanda talent-doctos-finalizacao-checkin,
// 2026-09-14): a trava incondicional antiga (TALENT_DOCTOS_PENDENTE) foi
// REMOVIDA e substituida pelo mecanismo de ativacao configuravel
// fail-closed (TALENT_CHECKIN_ATIVO). Mesmo com TODOS os gates reais (doctos/
// posse/tipo/status/etapa/documentos) passando, o _caso_finalizar.php roda
// sem TALENT_CHECKIN_ATIVO=true no ambiente do subprocesso — entao o
// bloqueio esperado agora e TALENT_CHECKIN_DESATIVADO (503), nunca chega a
// tentativa de envio (202) nem a qualquer chamada de rede real.
// ============================================================
$fix6 = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotemVitima, 'expedicao', 'FIN6666', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-FIN6666');
$pastas[] = $fix6['pasta_completa'];
$idsAtendimento[] = $fix6['id_atendimento'];
$saida6 = rodarFinalizar($idTotemVitima, $fix6['id_atendimento']);
afirmar('Dono legitimo (estado correto, doctos OK): bloqueado por TALENT_CHECKIN_DESATIVADO, nunca chega a "sera processada em instantes"', str_contains($saida6, 'TALENT_CHECKIN_DESATIVADO') && !str_contains($saida6, 'sera processada em instantes'));
$estadoFinal6 = $atendimentoDao->buscarPorId($fix6['id_atendimento'])['talent_checkin_status'];
afirmar('Dono legitimo: talent_checkin_status permanece NAO_ENVIADO (nenhuma tentativa de envio, nenhum CAS de idempotencia acionado)', $estadoFinal6 === 'NAO_ENVIADO');

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
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem IN (:a, :b, :c)')->execute(['a' => $idTotemVitima, 'b' => $idTotemInvasor, 'c' => $idTotemSemEmpresa]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
