<?php

/**
 * Teste manual real da consulta de ordens de coleta ao banco externo
 * (demanda expedicao-consulta-ordem-coleta-teste, 2026-09-11) — roda contra
 * o banco de dev real (udlog_totem) E o banco externo real configurado em
 * GESTAO_COLETAS_DB_NAME (.env local: udlogo59_db_gestao_coletas, nome real
 * confirmado apos reconciliacao em 2026-09-11). Cria e apaga seus proprios
 * dados em ambos os bancos (nenhum residuo).
 *
 * Cada cenario roda em subprocesso PHP separado (Resposta::erro()/
 * sucesso() chamam exit()).
 *
 * Fixture externa reaproveitada (ja inserida manualmente antes deste
 * teste, ver handoff): OC-TESTE-005 / placa TST0A01 / cliente_id=8 / status
 * ATIVA. NAO e apagada por este script (limpeza documentada para depois de
 * todos os testes desta demanda terminarem).
 *
 * Uso: php tests/manual/teste_consulta_ordem_coleta.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use Util\ConexaoGestaoColetas;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();
$pdoExterno = ConexaoGestaoColetas::obter();

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_ORDEM_COLETA', 'Totem Teste Ordem Coleta', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_ORDEM_COLETA_INVASOR', 'Totem Invasor', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotemInvasor = (int) $pdo->lastInsertId();

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "\n";
    if (!$condicao) $totalFalhas++;
}

function rodar(string $script, array $args): array
{
    $php = PHP_BINARY;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/' . $script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    exec($cmd, $saida, $codigo);
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

// ---------------------------------------------------------------
// Cenario 1: placa com 0 ordens (inventada, sem colisao com fixtures reais)
// ---------------------------------------------------------------
$r1 = rodar('_caso_iniciar_expedicao.php', [(string) $idTotem, 'ZZZ9Z99']);
afirmar('placa sem ordens: bloqueia com mensagem de nenhuma ordem em aberto', str_contains($r1['saida'], 'Nenhuma ordem de coleta em aberto'));

// ---------------------------------------------------------------
// Cenario 2: placa com 1 ordem real (TST0A01 -> OC-TESTE-005, fixture ja inserida)
// ---------------------------------------------------------------
$r2 = rodar('_caso_iniciar_expedicao.php', [(string) $idTotem, 'TST0A01']);
afirmar('placa com 1 ordem: avanca direto para dados_encontrados', str_contains($r2['saida'], '"proxima_tela":"dados_encontrados"'));
afirmar('placa com 1 ordem: numero da ordem retornado e OC-TESTE-005', str_contains($r2['saida'], 'OC-TESTE-005'));
afirmar('placa com 1 ordem: cliente_nome vem do banco (AKRO-PLASTIC)', str_contains($r2['saida'], 'AKRO-PLASTIC'));

$r2b = rodar('_caso_iniciar_expedicao.php', [(string) $idTotemInvasor, 'tst-0a01']);
afirmar('normalizacao no backend: placa minuscula/com hifen encontra a mesma ordem', str_contains($r2b['saida'], 'OC-TESTE-005'));

// ---------------------------------------------------------------
// Cenario 3: placa com multiplas ordens reais (ABC1D23 -> 3 ordens, cliente_id=8)
// ---------------------------------------------------------------
$r3 = rodar('_caso_iniciar_expedicao.php', [(string) $idTotem, 'ABC1D23']);
afirmar('placa com multiplas ordens: retorna tela selecionar_ordem', str_contains($r3['saida'], '"proxima_tela":"selecionar_ordem"'));
preg_match('/"id_atendimento":(\d+)/', $r3['saida'], $m);
$idAtendimentoMultiplas = (int) ($m[1] ?? 0);
$qtdOrdens = substr_count($r3['saida'], '"numero"');
afirmar('placa com multiplas ordens: retorna as 3 ordens esperadas', $qtdOrdens === 3);

// ---------------------------------------------------------------
// Cenario 4: selecionar-ordem legitimo (numero real dentre as retornadas)
// ---------------------------------------------------------------
$ordemLegitima = base64_encode(json_encode(['numero' => 'OC-TESTE-NOVA-003', 'cliente_nome' => 'FORJADO', 'cliente_cnpj' => '00000000000000']));
$r4 = rodar('_caso_selecionar_ordem.php', [(string) $idTotem, (string) $idAtendimentoMultiplas, $ordemLegitima]);
afirmar('selecionar-ordem legitimo: aceito e avanca para dados_encontrados', str_contains($r4['saida'], '"proxima_tela":"dados_encontrados"'));
afirmar('selecionar-ordem legitimo: cliente_nome/cliente_cnpj forjados pelo front SAO IGNORADOS (vem do servidor)', !str_contains($r4['saida'], 'FORJADO') && !str_contains($r4['saida'], '00000000000000'));

$dadosGravados = $pdo->query("SELECT ordem_coleta, cliente_nome, cliente_cnpj FROM tb_atendimento WHERE id_atendimento = {$idAtendimentoMultiplas}")->fetch(PDO::FETCH_ASSOC);
afirmar('selecionar-ordem legitimo: tb_atendimento.ordem_coleta gravado com o numero real', $dadosGravados['ordem_coleta'] === 'OC-TESTE-NOVA-003');
afirmar('selecionar-ordem legitimo: tb_atendimento.cliente_nome NAO e o valor forjado pelo front', $dadosGravados['cliente_nome'] !== 'FORJADO');

// ---------------------------------------------------------------
// Cenario 5: tentativa de selecionar ordem ALHEIA/forjada (numero que nao existe para a placa)
// ---------------------------------------------------------------
$r5a = rodar('_caso_iniciar_expedicao.php', [(string) $idTotem, 'ABC1D23']);
preg_match('/"id_atendimento":(\d+)/', $r5a['saida'], $m5);
$idAtendimentoForjado = (int) ($m5[1] ?? 0);

$ordemForjada = base64_encode(json_encode(['numero' => 'OC-NUNCA-EXISTIU-999', 'cliente_nome' => 'INVASOR', 'cliente_cnpj' => '11111111111111']));
$r5 = rodar('_caso_selecionar_ordem.php', [(string) $idTotem, (string) $idAtendimentoForjado, $ordemForjada]);
afirmar('IDOR: selecionar ordem forjada (numero inexistente para a placa) e bloqueado', str_contains($r5['saida'], 'Ordem de coleta invalida'));

$dadosNaoGravados = $pdo->query("SELECT ordem_coleta FROM tb_atendimento WHERE id_atendimento = {$idAtendimentoForjado}")->fetch(PDO::FETCH_ASSOC);
afirmar('IDOR: nada foi gravado em tb_atendimento apos a tentativa forjada', $dadosNaoGravados['ordem_coleta'] === null);

// ---------------------------------------------------------------
// Cenario 6: IDOR classico — totem invasor tentando selecionar ordem de atendimento alheio
// ---------------------------------------------------------------
$r6a = rodar('_caso_iniciar_expedicao.php', [(string) $idTotem, 'ABC1D23']);
preg_match('/"id_atendimento":(\d+)/', $r6a['saida'], $m6);
$idAtendimentoAlheio = (int) ($m6[1] ?? 0);

$ordemQualquer = base64_encode(json_encode(['numero' => 'OC-TESTE-001']));
$r6 = rodar('_caso_selecionar_ordem.php', [(string) $idTotemInvasor, (string) $idAtendimentoAlheio, $ordemQualquer]);
afirmar('IDOR classico: totem invasor nao consegue selecionar ordem de atendimento alheio', str_contains($r6['saida'], 'Atendimento nao encontrado'));

// ---------------------------------------------------------------
// Cenario 7: cliente INATIVO — nao ha caso real disponivel nos fixtures
// existentes (todos os clientes reais consultados em docs/db_gestao_coletas.sql
// estao com status ATIVO) e nenhuma alteracao de dado real de cliente foi
// autorizada so para forcar esse cenario. Registrado como NAO TESTAVEL.
// ---------------------------------------------------------------
echo "NAO TESTAVEL - cliente INATIVO: nenhum cliente real com status INATIVO disponivel nos fixtures; nao autorizado alterar dado real de cliente so para o teste.\n";

// ---------------------------------------------------------------
// Cenario 8: ordem INATIVA — fixture PROPRIA criada e removida por este
// script (nao e dado de cliente real, reaproveita cliente_id=8/email_recebido_id=1
// ja usados pelos outros fixtures de teste desta mesma demanda).
// ---------------------------------------------------------------
$pdoExterno->exec("INSERT INTO tb_ordens_coleta (numero_ordem_coleta, cliente_id, email_recebido_id, placa_prevista, status) VALUES ('OC-TESTE-006-INATIVA', 8, 1, 'TST0A02', 'INATIVA')");
$r8 = rodar('_caso_iniciar_expedicao.php', [(string) $idTotem, 'TST0A02']);
afirmar('ordem INATIVA: nao aparece na consulta (placa sem ordens em aberto)', str_contains($r8['saida'], 'Nenhuma ordem de coleta em aberto'));
$pdoExterno->exec("DELETE FROM tb_ordens_coleta WHERE numero_ordem_coleta = 'OC-TESTE-006-INATIVA'");

// ---------------------------------------------------------------
// Cenario 9: indisponibilidade do banco externo (host/porta errados SO
// nesta chamada de teste, nunca no .env real)
// ---------------------------------------------------------------
$r9 = rodar('_caso_indisponibilidade_banco_externo.php', [(string) $idTotem, 'TST0A01']);
afirmar('banco externo indisponivel: retorna erro generico (nao trava/nao vaza detalhe)', str_contains($r9['saida'], 'Nao foi possivel consultar as ordens de coleta agora'));
afirmar('banco externo indisponivel: NUNCA vaza host/porta/credencial na resposta', !str_contains($r9['saida'], '127.0.0.1') && !str_contains($r9['saida'], 'DB_PASS') && !str_contains($r9['saida'], _ENV_DB_PASS_VALOR()));

function _ENV_DB_PASS_VALOR(): string
{
    return $_ENV['DB_PASS'] ?? '__nunca__';
}

// Limpeza (banco do totem) — apaga TODOS os atendimentos criados pelos dois
// totens de teste (inclusive os das placas sem ordem, que criam a linha em
// tb_atendimento antes de consultar as ordens e falhar). NUNCA apaga a
// fixture externa OC-TESTE-005 (limpeza documentada separadamente, so
// depois que os testes desta demanda terminarem).
//
// AJUSTE (demanda tela-inicial-lgpd-totem, 2026-09-24, /02-testes):
// _caso_iniciar_expedicao.php passou a emitir um token de aceite LGPD real
// (tb_lgpd_aceite) para $idTotem a cada chamada — precisa ser apagado ANTES
// de tb_totem, senao a FK tb_lgpd_aceite.id_totem -> tb_totem(id_totem)
// bloqueia o DELETE de tb_totem (efeito colateral esperado do novo
// contrato, nao um bug de producao).
$pdo->prepare('DELETE FROM tb_lgpd_aceite WHERE id_totem IN (:t1, :t2)')->execute(['t1' => $idTotem, 't2' => $idTotemInvasor]);
$pdo->prepare('DELETE FROM tb_atendimento WHERE id_totem IN (:t1, :t2)')->execute(['t1' => $idTotem, 't2' => $idTotemInvasor]);
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem IN (:t1, :t2)')->execute(['t1' => $idTotem, 't2' => $idTotemInvasor]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
