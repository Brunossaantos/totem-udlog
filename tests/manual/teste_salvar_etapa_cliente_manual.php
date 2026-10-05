<?php

/**
 * Teste manual de regressao: AtendimentoController::salvarEtapa() — case
 * 'cliente' (identificacao MANUAL do cliente no Recebimento, feita pelo
 * atendente) e case 'confirmacao' (gate de campos obrigatorios).
 *
 * Historico:
 * - O case 'cliente' gravava o cliente mas nunca chamava atualizarEtapa(),
 *   travando etapa_atual em 'cliente'; hoje avanca para 'rec_cnh' (mesma
 *   proxima etapa do fluxo automatico via OCR, concluirDigitalizacao()).
 * - 2026-09-09: o case exige etapa_atual = 'cliente' (posse/etapa).
 * - hardening-revisao-notas-e-cliente (2026-09-30): cliente MANUAL validado
 *   contra tb_cliente ATIVA; nome/CNPJ gravados vem sempre de tb_cliente.
 * - PORTADO ao fluxo QR-only (2026-10-02): DocumentoController::upload() foi
 *   removido. A prova "o primeiro documento de rec_cnh e aceito logo apos a
 *   identificacao manual do cliente" agora usa iniciar-processamento com JPEG
 *   sintetico em memoria e VIO FALSO injetado so pela factory
 *   (_caso_documento_qr_vio_falso.php).
 * - Decisao 2026-10-02: RNTRC e Tipo do veiculo sao opcionais na leitura e
 *   OBRIGATORIOS na confirmacao: salvar-etapa 'confirmacao' incompleta devolve
 *   422 CONFIRMACAO_INCOMPLETA (campos/rotulos) sem gravar nem avancar; no
 *   Recebimento o cliente tambem e obrigatorio (campo 'cliente').
 *
 * Cobre:
 * - cliente fora de tb_cliente ativa e rejeitado (etapa/cliente inalterados);
 * - cliente valido avanca para rec_cnh, nome vindo de tb_cliente;
 * - primeiro documento (CNH por QR) aceito em rec_cnh apos a identificacao;
 * - confirmacao com dados completos passa (sucesso, grava, RNTRC/Tipo persistidos);
 * - confirmacao com RNTRC vazio, Tipo vazio ou ambos vazios: 422
 *   CONFIRMACAO_INCOMPLETA listando so os campos ausentes, sem gravar/avancar;
 * - Recebimento sem cliente: 422 CONFIRMACAO_INCOMPLETA com campos=[cliente].
 *
 * BANCO: 100% em banco descartavel `qa_qr_exclusivo_<hex>` (criado e removido
 * no finally; subprocessos apontados via auto_prepend_file), STORAGE_PATH
 * temporario. Nunca toca em udlog_totem.
 *
 * Uso: php tests/manual/teste_salvar_etapa_cliente_manual.php
 */

require_once __DIR__ . '/qa_qr_exclusivo_legado.php';

use App\Dao\AtendimentoDao;

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
    return qaLegadoRodar($script, $args);
}

/** CNH/CRLV por QR-only: iniciar + status com VIO falso (resultado aprovavel). */
function qaDocumentoQrAprovado(int $idTotem, int $idAtendimento, string $tipo, string $placa): array
{
    $ini = rodarSubprocesso(__DIR__ . '/_caso_documento_qr_vio_falso.php', [$idTotem, $idAtendimento, 'iniciar', $tipo]);
    $res = base64_encode(json_encode(qaLegadoResultadoVio($tipo, [], $placa), JSON_THROW_ON_ERROR));
    $sta = rodarSubprocesso(__DIR__ . '/_caso_documento_qr_vio_falso.php', [$idTotem, $idAtendimento, 'status', $tipo, $res]);
    return ['iniciar' => $ini['saida'], 'status' => $sta['saida']];
}

function qaAvancar(int $idTotem, int $idAtendimento): string
{
    return rodarSubprocesso(__DIR__ . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtendimento])['saida'];
}

function qaConfirmacao(int $idTotem, int $idAtendimento, array $dados): string
{
    return rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa_http.php', [$idTotem, $idAtendimento, 'confirmacao', base64_encode(json_encode($dados, JSON_THROW_ON_ERROR))])['saida'];
}

/** Dados completos de confirmacao, coerentes com o resultado VIO falso. */
function qaDadosConfirmacao(array $sobrescrever = []): array
{
    return array_replace([
        'motorista_nome' => 'QA MOTORISTA',
        'motorista_cpf' => '52998224725',
        'cnh_validade' => '2035-12-31',
        'crlv_ano' => 2026,
        'crlv_uf' => 'SP',
        'crlv_rntc' => 'QA-RNTRC',
        'crlv_tipo_veiculo' => 'CAMINHAO',
    ], $sobrescrever);
}

/** Leva um atendimento recebimento (etapa rec_cnh) ate rec_confirmacao pelo caminho QR-only. */
function qaLevarAteConfirmacao(int $idTotem, int $idAtendimento, string $placa): bool
{
    $c = qaDocumentoQrAprovado($idTotem, $idAtendimento, 'cnh', $placa);
    $ok = str_contains($c['status'], '"pode_avancar":true');
    $ok = $ok && str_contains(qaAvancar($idTotem, $idAtendimento), '"etapa":"rec_crlv"');
    $k = qaDocumentoQrAprovado($idTotem, $idAtendimento, 'crlv', $placa);
    $ok = $ok && str_contains($k['status'], '"pode_avancar":true');
    $ok = $ok && str_contains(qaAvancar($idTotem, $idAtendimento), '"etapa":"rec_aguarde_documentos"');
    $ok = $ok && str_contains(qaAvancar($idTotem, $idAtendimento), '"etapa":"rec_confirmacao"');
    return $ok;
}

$banco = null;
$storage = null;
$pdo = null;
try {
    [$pdo, $banco, $storage] = qaLegadoCriarAmbiente();

    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_SALVAR_ETAPA_CLI', 'Totem Teste Salvar Etapa Cliente', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
    $idTotem = (int) $pdo->lastInsertId();

    $atendimentoDao = new AtendimentoDao($pdo);
    $placaCli = 'GGG5A55';
    $idAtendimento = $atendimentoDao->criar($idTotem, 'recebimento', $placaCli);

    // O case 'cliente' exige etapa_atual = 'cliente' (estado deixado por
    // concluirDigitalizacao() quando nenhuma nota identifica o cliente).
    $atendimentoDao->atualizarEtapa($idAtendimento, 'cliente');

    // tb_cliente ATIVA e a unica allowlist (D2); banco QA descartavel.
    $cnpjTesteManual = '11222333000181';
    $pdo->prepare("INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES ('CLIENTE TESTE MANUAL LTDA', 'CLIENTE TESTE MANUAL LTDA', :c, 1)")->execute(['c' => $cnpjTesteManual]);

    // base64: escapeshellarg() no Windows remove aspas duplas (ver _caso_salvar_etapa.php).
    $dadosDesconhecido = base64_encode(json_encode(['nome' => 'CLIENTE INVENTADO', 'cnpj' => '22333444000181']));
    $rRejeitado = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotem, $idAtendimento, 'cliente', $dadosDesconhecido]);
    $aposRejeicao = $atendimentoDao->buscarPorId($idAtendimento);
    afirmar('salvar-etapa cliente com CNPJ fora de tb_cliente ativa e REJEITADO (sucesso false)', str_contains($rRejeitado['saida'], '"sucesso":false'));
    afirmar('Rejeicao do cliente desconhecido nao altera a etapa nem grava cliente', $aposRejeicao['etapa_atual'] === 'cliente' && empty($aposRejeicao['cliente_cnpj']) && empty($aposRejeicao['cliente_nome']));

    // o front envia um NOME divergente: o backend grava o de tb_cliente
    $dadosCliente = base64_encode(json_encode(['nome' => 'NOME ENVIADO PELO FRONT', 'cnpj' => $cnpjTesteManual]));
    $rSalvarEtapa = rodarSubprocesso(__DIR__ . '/_caso_salvar_etapa.php', [$idTotem, $idAtendimento, 'cliente', $dadosCliente]);
    afirmar('salvar-etapa cliente (manual) responde sucesso', str_contains($rSalvarEtapa['saida'], '"sucesso":true'));

    $atendimentoAposCliente = $atendimentoDao->buscarPorId($idAtendimento);
    afirmar('Apos salvar-etapa cliente (manual), etapa_atual avanca para rec_cnh', $atendimentoAposCliente['etapa_atual'] === 'rec_cnh');
    afirmar('Nome e CNPJ gravados vem de tb_cliente (nunca do que o front enviou)', $atendimentoAposCliente['cliente_cnpj'] === $cnpjTesteManual && $atendimentoAposCliente['cliente_nome'] !== 'NOME ENVIADO PELO FRONT' && !empty($atendimentoAposCliente['cliente_nome']));

    // Equivalente QR-only do antigo "primeiro upload de cnh_frente funciona":
    // o primeiro documento (CNH por QR) e aceito em rec_cnh logo apos o cliente.
    $primeira = rodarSubprocesso(__DIR__ . '/_caso_documento_qr_vio_falso.php', [$idTotem, $idAtendimento, 'iniciar', 'cnh']);
    afirmar('Primeiro iniciar-processamento de CNH (QR, etapa rec_cnh) funciona sem erro de etapa apos identificacao manual do cliente', str_contains($primeira['saida'], '"sucesso":true'));

    // ---- Confirmacao: RNTRC e Tipo obrigatorios (decisao 2026-10-02) ----
    // Continua o MESMO atendimento: CNH ja iniciada acima, aprova e segue.
    $resCnh = base64_encode(json_encode(qaLegadoResultadoVio('cnh'), JSON_THROW_ON_ERROR));
    $staCnh = rodarSubprocesso(__DIR__ . '/_caso_documento_qr_vio_falso.php', [$idTotem, $idAtendimento, 'status', 'cnh', $resCnh]);
    $ok = str_contains($staCnh['saida'], '"pode_avancar":true') && str_contains(qaAvancar($idTotem, $idAtendimento), '"etapa":"rec_crlv"');
    $k = qaDocumentoQrAprovado($idTotem, $idAtendimento, 'crlv', $placaCli);
    $ok = $ok && str_contains($k['status'], '"pode_avancar":true')
        && str_contains(qaAvancar($idTotem, $idAtendimento), '"etapa":"rec_aguarde_documentos"')
        && str_contains(qaAvancar($idTotem, $idAtendimento), '"etapa":"rec_confirmacao"');
    afirmar('Setup: CNH e CRLV aprovados por QR (VIO falso) e etapa rec_confirmacao alcancada', $ok);

    $antes = $atendimentoDao->buscarPorId($idAtendimento);
    $nomeAntes = $antes['motorista_nome'];

    // RNTRC vazio -> 422, lista so crlv_rntc, sem gravar nem avancar
    $r = qaConfirmacao($idTotem, $idAtendimento, qaDadosConfirmacao(['crlv_rntc' => '', 'motorista_nome' => 'NOME NAO PODE GRAVAR']));
    $at = $atendimentoDao->buscarPorId($idAtendimento);
    afirmar('confirmacao com RNTRC vazio: 422 CONFIRMACAO_INCOMPLETA com campos=[crlv_rntc] e rotulo RNTRC', str_contains($r, 'HTTP_CODE:422') && str_contains($r, '"sucesso":false') && str_contains($r, 'CONFIRMACAO_INCOMPLETA') && str_contains($r, '"campos":["crlv_rntc"]') && str_contains($r, 'RNTRC'));
    afirmar('confirmacao com RNTRC vazio NAO grava dados nem avanca etapa', $at['motorista_nome'] === $nomeAntes && $at['etapa_atual'] === 'rec_confirmacao');

    // Tipo vazio -> 422, lista so crlv_tipo_veiculo
    $r = qaConfirmacao($idTotem, $idAtendimento, qaDadosConfirmacao(['crlv_tipo_veiculo' => '   ', 'motorista_nome' => 'NOME NAO PODE GRAVAR']));
    $at = $atendimentoDao->buscarPorId($idAtendimento);
    afirmar('confirmacao com Tipo do veiculo vazio: 422 CONFIRMACAO_INCOMPLETA com campos=[crlv_tipo_veiculo]', str_contains($r, 'HTTP_CODE:422') && str_contains($r, 'CONFIRMACAO_INCOMPLETA') && str_contains($r, '"campos":["crlv_tipo_veiculo"]'));
    afirmar('confirmacao com Tipo vazio NAO grava dados nem avanca etapa', $at['motorista_nome'] === $nomeAntes && $at['etapa_atual'] === 'rec_confirmacao');

    // RNTRC e Tipo ausentes (chaves nem enviadas) -> 422 listando os dois
    $dadosSemAmbos = qaDadosConfirmacao();
    unset($dadosSemAmbos['crlv_rntc'], $dadosSemAmbos['crlv_tipo_veiculo']);
    $r = qaConfirmacao($idTotem, $idAtendimento, $dadosSemAmbos);
    afirmar('confirmacao sem RNTRC e sem Tipo: 422 com ambos em campos', str_contains($r, 'HTTP_CODE:422') && str_contains($r, '"crlv_rntc"') && str_contains($r, '"crlv_tipo_veiculo"') && str_contains($r, 'CONFIRMACAO_INCOMPLETA'));

    // Dados completos -> passa
    $r = qaConfirmacao($idTotem, $idAtendimento, qaDadosConfirmacao());
    $at = $atendimentoDao->buscarPorId($idAtendimento);
    afirmar('confirmacao com dados completos passa (sucesso true, sem codigo HTTP 4xx/5xx)', str_contains($r, '"sucesso":true') && !str_contains($r, 'CONFIRMACAO_INCOMPLETA') && !preg_match('/HTTP_CODE:[45]\d\d/', $r));
    afirmar('confirmacao completa grava motorista e mantem RNTRC/Tipo', $at['motorista_nome'] === 'QA MOTORISTA' && $at['crlv_rntc'] === 'QA-RNTRC' && $at['crlv_tipo_veiculo'] === 'CAMINHAO');

    // Recebimento SEM cliente: 422 'cliente' mesmo com RNTRC/Tipo completos
    $idSemCliente = $atendimentoDao->criar($idTotem, 'recebimento', 'HHH6B66');
    $atendimentoDao->atualizarEtapa($idSemCliente, 'rec_cnh');
    afirmar('Setup (sem cliente): CNH e CRLV aprovados por QR e etapa rec_confirmacao alcancada', qaLevarAteConfirmacao($idTotem, $idSemCliente, 'HHH6B66'));
    $semAntes = $atendimentoDao->buscarPorId($idSemCliente);
    afirmar('Setup (sem cliente): atendimento realmente sem cliente_nome', trim((string) ($semAntes['cliente_nome'] ?? '')) === '');
    $r = qaConfirmacao($idTotem, $idSemCliente, qaDadosConfirmacao());
    $semDepois = $atendimentoDao->buscarPorId($idSemCliente);
    afirmar('confirmacao do Recebimento sem cliente: 422 CONFIRMACAO_INCOMPLETA com campos=[cliente]', str_contains($r, 'HTTP_CODE:422') && str_contains($r, 'CONFIRMACAO_INCOMPLETA') && str_contains($r, '"campos":["cliente"]'));
    afirmar('confirmacao sem cliente NAO grava dados nem avanca etapa', $semDepois['motorista_nome'] === $semAntes['motorista_nome'] && $semDepois['etapa_atual'] === 'rec_confirmacao');
} finally {
    // Teardown garantido: banco QA descartavel e storage temporario.
    unset($pdo);
    try {
        qaLegadoLimparAmbiente($banco, $storage);
        echo "Banco QA e storage temporario removidos.\n";
    } catch (Throwable) {
        fwrite(STDERR, "FALHA: banco QA nao removido.\n");
        $totalFalhas++;
    }
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
