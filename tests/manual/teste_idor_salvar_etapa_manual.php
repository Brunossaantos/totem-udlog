<?php

/**
 * Teste manual de seguranca (IDOR) para AtendimentoController::salvarEtapa(),
 * cases 'confirmacao', 'cliente' e 'ajudante' — achado CRITICO do
 * security-especialista em 2026-09-09: nenhum dos 3 validava posse do
 * atendimento (id_totem), tipo, status ou etapa antes de gravar dados,
 * permitindo que um totem A enviasse o id_atendimento de um totem B e
 * sobrescrevesse motorista_nome/motorista_cpf (confirmacao),
 * cliente_nome/cliente_cnpj + forcasse a transicao de etapa (cliente), ou
 * ajudante_nome/ajudante_cpf (ajudante) de um atendimento alheio.
 *
 * Corrigido replicando exatamente o padrao ja usado pelo case
 * 'digitalizacao_notas' (buscarAtendimentoDoTotem + validacao de
 * tipo/status/etapa esperada, mensagem generica).
 *
 * Cobre, para os 3 cases:
 *  - totem invasor (id_totem diferente do dono) recebe resposta de erro
 *    generica (nunca "sucesso":true) e NAO consegue gravar/alterar dado
 *    algum do atendimento da vitima;
 *  - o dono legitimo do atendimento, na etapa correta, continua conseguindo
 *    salvar normalmente (sem regressao).
 *
 * Uso: php tests/manual/teste_idor_salvar_etapa_manual.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

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

function rodarSubprocesso(string $script, array $args): array
{
    $php = PHP_BINARY;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string) $a);
    }
    exec($cmd, $saida, $codigo);
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

$atendimentoDao = new AtendimentoDao($pdo);

// ============================================================
// Setup: totem VITIMA (dono do atendimento) e totem INVASOR
// ============================================================
$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_IDOR_VITIMA', 'Totem Teste IDOR Vitima', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotemVitima = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_IDOR_INVASOR', 'Totem Teste IDOR Invasor', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotemInvasor = (int) $pdo->lastInsertId();

function limpar(PDO $pdo, array $idsAtendimento, array $idsTotem): void
{
    foreach ($idsAtendimento as $id) {
        $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
    }
    foreach ($idsTotem as $id) {
        $pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $id]);
    }
}

$idsAtendimento = [];

// ============================================================
// Caso 1: 'confirmacao' — expedicao, etapa exp_confirmacao
// ============================================================
$idAtConfirmacao = $atendimentoDao->criar($idTotemVitima, 'expedicao', 'IDR1111');
$idsAtendimento[] = $idAtConfirmacao;
$atendimentoDao->atualizarEtapa($idAtConfirmacao, 'exp_confirmacao');

// base64: escapeshellarg() no Windows remove aspas duplas de argumentos,
// corrompendo JSON literal na linha de comando — ver comentario em
// _caso_salvar_etapa.php.
$dadosConfirmacao = base64_encode(json_encode([
    'motorista_nome' => 'INVASOR TENTOU SOBRESCREVER',
    'motorista_cpf' => '99999999999',
]));
$rInvasorConfirmacao = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemInvasor, $idAtConfirmacao, 'confirmacao', $dadosConfirmacao]);
afirmar(
    "IDOR 'confirmacao': totem invasor NAO recebe sucesso ao usar id_atendimento alheio",
    !str_contains($rInvasorConfirmacao['saida'], '"sucesso":true')
);
$atendimentoAposInvasorConfirmacao = $atendimentoDao->buscarPorId($idAtConfirmacao);
afirmar(
    "IDOR 'confirmacao': motorista_nome do atendimento da vitima NAO foi alterado pelo invasor",
    $atendimentoAposInvasorConfirmacao['motorista_nome'] !== 'INVASOR TENTOU SOBRESCREVER'
);

// Decisao 2026-10-02: confirmacao incompleta (sem RNTRC etc.) e recusada com
// 422/CONFIRMACAO_INCOMPLETA, sem gravar nada (nem avancar etapa).
$pdo->prepare("UPDATE tb_atendimento SET ordem_coleta = '123' WHERE id_atendimento = ?")->execute([$idAtConfirmacao]);
$dadosIncompleta = base64_encode(json_encode([
    'motorista_nome' => 'MOTORISTA LEGITIMO',
    'motorista_cpf' => '11144477735',
]));
$rIncompleta = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemVitima, $idAtConfirmacao, 'confirmacao', $dadosIncompleta]);
afirmar("'confirmacao' incompleta responde erro CONFIRMACAO_INCOMPLETA com mensagem fixa e lista de campos", str_contains($rIncompleta['saida'], '"sucesso":false') && str_contains($rIncompleta['saida'], 'CONFIRMACAO_INCOMPLETA') && str_contains($rIncompleta['saida'], 'Nao e possivel continuar: preencha ') && str_contains($rIncompleta['saida'], 'RNTRC') && str_contains($rIncompleta['saida'], '"crlv_rntc"'));
afirmar("'confirmacao' incompleta tambem lista o tipo de veiculo ausente", str_contains($rIncompleta['saida'], '"crlv_tipo_veiculo"'));
$atIncompleta = $atendimentoDao->buscarPorId($idAtConfirmacao);
afirmar("'confirmacao' incompleta NAO grava dados nem avanca etapa", $atIncompleta['motorista_nome'] !== 'MOTORISTA LEGITIMO' && $atIncompleta['etapa_atual'] === 'exp_confirmacao');

// Decisao 2026-10-02: Tipo do veiculo ausente (demais campos completos) -> 422
// listando so crlv_tipo_veiculo, sem gravar nem avancar.
$dadosSemTipo = base64_encode(json_encode([
    'motorista_nome' => 'MOTORISTA LEGITIMO', 'motorista_cpf' => '11144477735', 'cnh_validade' => '2030-01-01',
    'crlv_ano' => '2025', 'crlv_uf' => 'SP', 'crlv_rntc' => '87654321', 'crlv_tipo_veiculo' => '',
]));
$rSemTipo = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemVitima, $idAtConfirmacao, 'confirmacao', $dadosSemTipo]);
afirmar("'confirmacao' sem tipo de veiculo e recusada (CONFIRMACAO_INCOMPLETA, campos=[crlv_tipo_veiculo])", str_contains($rSemTipo['saida'], '"sucesso":false') && str_contains($rSemTipo['saida'], 'CONFIRMACAO_INCOMPLETA') && str_contains($rSemTipo['saida'], '"crlv_tipo_veiculo"') && !str_contains($rSemTipo['saida'], '"crlv_rntc"'));
$atSemTipo = $atendimentoDao->buscarPorId($idAtConfirmacao);
afirmar("'confirmacao' sem tipo NAO grava dados nem avanca etapa", $atSemTipo['motorista_nome'] !== 'MOTORISTA LEGITIMO' && $atSemTipo['etapa_atual'] === 'exp_confirmacao');

// dono legitimo continua funcionando normalmente (sem regressao)
$dadosConfirmacaoDono = base64_encode(json_encode([
    'motorista_nome' => 'MOTORISTA LEGITIMO',
    'motorista_cpf' => '11144477735',
    'cnh_validade' => '2030-01-01',
    'crlv_ano' => '2025',
    'crlv_uf' => 'SP',
    'crlv_rntc' => '87654321',
    'crlv_tipo_veiculo' => 'CAMINHAO',
]));
$rDonoConfirmacao = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemVitima, $idAtConfirmacao, 'confirmacao', $dadosConfirmacaoDono]);
afirmar("'confirmacao' (dono legitimo, etapa correta) continua respondendo sucesso", str_contains($rDonoConfirmacao['saida'], '"sucesso":true'));
$atendimentoAposDonoConfirmacao = $atendimentoDao->buscarPorId($idAtConfirmacao);
afirmar("'confirmacao' (dono legitimo) grava motorista_nome corretamente", $atendimentoAposDonoConfirmacao['motorista_nome'] === 'MOTORISTA LEGITIMO');
afirmar("'confirmacao' (dono legitimo) persiste crlv_rntc digitado", $atendimentoAposDonoConfirmacao['crlv_rntc'] === '87654321');

// Gate final do finalizar (decisao 2026-10-02): tipo vazio no atendimento
// (documentos aprovados, etapa de confirmacao) -> 422 com campos=[crlv_tipo_veiculo].
$pdo->prepare("UPDATE tb_atendimento SET cnh_origem_validacao = 'MANUAL', crlv_origem_validacao = 'MANUAL', crlv_tipo_veiculo = NULL, etapa_atual = 'exp_confirmacao' WHERE id_atendimento = ?")->execute([$idAtConfirmacao]);
$rFinalSemTipo = rodarSubprocesso(__DIR__ . '/_caso_finalizar.php', [$idTotemVitima, $idAtConfirmacao]);
afirmar("finalizar com tipo de veiculo vazio responde CONFIRMACAO_INCOMPLETA com campos=[crlv_tipo_veiculo]", str_contains($rFinalSemTipo['saida'], 'CONFIRMACAO_INCOMPLETA') && str_contains($rFinalSemTipo['saida'], '"campos":["crlv_tipo_veiculo"]'));

// ============================================================
// Caso 2: 'cliente' — recebimento, etapa 'cliente'
// ============================================================
$idAtCliente = $atendimentoDao->criar($idTotemVitima, 'recebimento', 'IDR2222');
$idsAtendimento[] = $idAtCliente;
$atendimentoDao->atualizarEtapa($idAtCliente, 'cliente');

$dadosCliente = base64_encode(json_encode(['nome' => 'CLIENTE FORJADO PELO INVASOR', 'cnpj' => '00000000000000']));
$rInvasorCliente = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemInvasor, $idAtCliente, 'cliente', $dadosCliente]);
afirmar(
    "IDOR 'cliente': totem invasor NAO recebe sucesso ao usar id_atendimento alheio",
    !str_contains($rInvasorCliente['saida'], '"sucesso":true')
);
$atendimentoAposInvasorCliente = $atendimentoDao->buscarPorId($idAtCliente);
afirmar(
    "IDOR 'cliente': cliente_nome do atendimento da vitima NAO foi alterado pelo invasor",
    $atendimentoAposInvasorCliente['cliente_nome'] !== 'CLIENTE FORJADO PELO INVASOR'
);
afirmar(
    "IDOR 'cliente': etapa_atual do atendimento da vitima NAO foi forcada para rec_cnh pelo invasor",
    $atendimentoAposInvasorCliente['etapa_atual'] === 'cliente'
);

// ATUALIZADO (hardening-revisao-notas-e-cliente, 2026-09-30): o cliente MANUAL
// e validado contra tb_cliente ATIVA (D2); linha temporaria, removida na limpeza.
$idClienteTmp = null;
$existeClienteTmp = $pdo->prepare('SELECT id_cliente FROM tb_cliente WHERE cnpj = :c');
$existeClienteTmp->execute(['c' => '11222333000181']);
if ($existeClienteTmp->fetchColumn() === false) {
    $pdo->prepare("INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:n, :n, '11222333000181', 1)")->execute(['n' => 'CLIENTE LEGITIMO LTDA']);
    $idClienteTmp = (int) $pdo->lastInsertId();
}

// dono legitimo continua funcionando normalmente (sem regressao)
$dadosClienteDono = base64_encode(json_encode(['nome' => 'CLIENTE LEGITIMO LTDA', 'cnpj' => '11222333000181']));
$rDonoCliente = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemVitima, $idAtCliente, 'cliente', $dadosClienteDono]);
afirmar("'cliente' (dono legitimo, etapa correta) continua respondendo sucesso", str_contains($rDonoCliente['saida'], '"sucesso":true'));
$atendimentoAposDonoCliente = $atendimentoDao->buscarPorId($idAtCliente);
afirmar("'cliente' (dono legitimo) grava cliente_nome corretamente", $atendimentoAposDonoCliente['cliente_nome'] === 'CLIENTE LEGITIMO LTDA');
afirmar("'cliente' (dono legitimo) avanca etapa_atual para rec_cnh (etapa unica desde a rodada corretiva de 2026-09-26)", $atendimentoAposDonoCliente['etapa_atual'] === 'rec_cnh');

// ============================================================
// Caso 3: 'ajudante' — recebimento, etapa rec_confirmacao
// ============================================================
$idAtAjudante = $atendimentoDao->criar($idTotemVitima, 'recebimento', 'IDR3333');
$idsAtendimento[] = $idAtAjudante;
$atendimentoDao->atualizarEtapa($idAtAjudante, 'rec_confirmacao');

$dadosAjudante = base64_encode(json_encode(['nome' => 'AJUDANTE FORJADO PELO INVASOR', 'cpf' => '52998224725']));
$rInvasorAjudante = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemInvasor, $idAtAjudante, 'ajudante', $dadosAjudante]);
afirmar(
    "IDOR 'ajudante': totem invasor NAO recebe sucesso ao usar id_atendimento alheio",
    !str_contains($rInvasorAjudante['saida'], '"sucesso":true')
);
$atendimentoAposInvasorAjudante = $atendimentoDao->buscarPorId($idAtAjudante);
afirmar(
    "IDOR 'ajudante': ajudante_nome do atendimento da vitima NAO foi alterado pelo invasor",
    $atendimentoAposInvasorAjudante['ajudante_nome'] !== 'AJUDANTE FORJADO PELO INVASOR'
);

// dono legitimo continua funcionando normalmente (sem regressao)
$dadosAjudanteDono = base64_encode(json_encode(['nome' => 'AJUDANTE LEGITIMO', 'cpf' => '39053344705']));
$rDonoAjudante = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemVitima, $idAtAjudante, 'ajudante', $dadosAjudanteDono]);
afirmar("'ajudante' (dono legitimo, etapa correta) continua respondendo sucesso", str_contains($rDonoAjudante['saida'], '"sucesso":true'));
$atendimentoAposDonoAjudante = $atendimentoDao->buscarPorId($idAtAjudante);
afirmar("'ajudante' (dono legitimo) grava ajudante_nome corretamente", $atendimentoAposDonoAjudante['ajudante_nome'] === 'AJUDANTE LEGITIMO');

// ============================================================
// Caso 4: invasor tambem nao passa nem informando id_atendimento
// inexistente (sanity check da mensagem generica reaproveitada)
// ============================================================
$rInvasorInexistente = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotemInvasor, 9999999, 'confirmacao', base64_encode(json_encode(['motorista_nome' => 'X']))]);
afirmar(
    "IDOR 'confirmacao': id_atendimento inexistente tambem responde erro (nao sucesso)",
    !str_contains($rInvasorInexistente['saida'], '"sucesso":true')
);

// ============================================================
// Limpeza final
// ============================================================
limpar($pdo, $idsAtendimento, [$idTotemVitima, $idTotemInvasor]);
if ($idClienteTmp !== null) {
    $pdo->prepare('DELETE FROM tb_cliente WHERE id_cliente = :id')->execute(['id' => $idClienteTmp]);
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
