<?php

/**
 * Teste E2E (mock, SEM chamada real ao Talent/VIO, SEM impressao fisica) —
 * demanda talent-doctos-finalizacao-checkin (2026-09-14), item "E2E" do
 * roteiro de /02-testes. Todo o percurso roda via subprocessos que chamam os
 * Controllers reais diretamente (Resposta::sucesso()/erro() chamam exit(),
 * entao cada chamada roda isolada em processo proprio).
 *
 * PORTADO ao fluxo QR-only de CNH/CRLV (2026-10-02): DocumentoController::
 * upload() foi removido; CNH/CRLV agora entram por iniciar-processamento
 * (JPEG sintetico do QR em memoria) + status-processamento com VIO FALSO
 * injetado so pela factory do construtor (_caso_documento_qr_vio_falso.php).
 * Nao ha mais cnh_frente/cnh_verso/crlv em disco nem gate de upload: o gate
 * que bloqueia a confirmacao e 'ambos_aprovados' (CNH E CRLV aprovados),
 * coberto por assercao de bloqueio com so a CNH aprovada. Cada confirmacao
 * agora exige RNTRC e Tipo do veiculo (CONFIRMACAO_INCOMPLETA/422 se vazios).
 *
 * BANCO: roda 100% em banco descartavel `qa_qr_exclusivo_<hex>` (criado e
 * removido no finally; subprocessos apontados via auto_prepend_file), com
 * STORAGE_PATH temporario. Nunca toca em udlog_totem.
 *
 * Fluxo Recebimento: fixture direta do atendimento -> 3 notas (mock upload)
 * -> numeros (incl. 1 DUPLICADO rejeitado) -> concluirDigitalizacao() ->
 * cliente manual -> CNH/CRLV por QR (VIO falso) -> confirmacao -> finalizar()
 * com 503 TALENT_CHECKIN_DESATIVADO limpo, todos os gates ja passados.
 *
 * Fluxo Expedicao: atendimento com ordem_coleta (fixture direta; selecao real
 * depende do banco externo de coletas) -> CNH/CRLV por QR -> confirmacao ->
 * finalizar() com o mesmo bloqueio limpo.
 *
 * Zero chamada de rede ao Talent: _caso_trava_doctos_pendente.php usa um
 * TalentRnEspiao que imprime ESPIAO_PROCESSARCHECKIN_CHAMADO se
 * processarCheckin() for invocado; a ausencia do marcador prova que o
 * bloqueio ocorre antes (gate TALENT_CHECKIN_ATIVO).
 *
 * Uso: php tests/manual/teste_e2e_recebimento_expedicao_mock.php
 */

require_once __DIR__ . '/qa_qr_exclusivo_legado.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    echo ($condicao ? 'OK   - ' : 'FALHA - ') . $descricao . "
";
    if (!$condicao) $totalFalhas++;
}

function rodar(string $script, array $args): array
{
    return qaLegadoRodar($script, $args);
}

/** CNH/CRLV por QR-only: iniciar + status (VIO falso, resultado aprovavel). */
function qaDocumentoQr(string $dir, int $idTotem, int $idAtendimento, string $tipo, string $placa = 'ABC1234'): array
{
    $ini = rodar($dir . '/_caso_documento_qr_vio_falso.php', [$idTotem, $idAtendimento, 'iniciar', $tipo]);
    $res = base64_encode(json_encode(qaLegadoResultadoVio($tipo, [], $placa), JSON_THROW_ON_ERROR));
    $sta = rodar($dir . '/_caso_documento_qr_vio_falso.php', [$idTotem, $idAtendimento, 'status', $tipo, $res]);
    return ['iniciar' => $ini['saida'], 'status' => $sta['saida']];
}

$banco = null;
$storage = null;
$pdo = null;
try {
[$pdo, $banco, $storage] = qaLegadoCriarAmbiente();
// Seed da empresa Maua I (migration 008 nao esta no schema QA base): totem do teste usa id_empresa=1.
$pdo->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");

$dir = __DIR__;
$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_E2E_' . bin2hex(random_bytes(3)), 1);
$pastas = [];
$idsAtendimento = [];

// ============================================================
// FLUXO RECEBIMENTO COMPLETO
// ============================================================
$idAtRec = $atendimentoDao->criar($idTotem, 'recebimento', 'REC1A23');
$idsAtendimento[] = $idAtRec;
$pastaRec = 'teste_e2e_rec_' . bin2hex(random_bytes(4));
$atendimentoDao->definirPasta($idAtRec, $pastaRec);
$pastaRecCompleta = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pastaRec;
if (!is_dir($pastaRecCompleta)) {
    mkdir($pastaRecCompleta, 0750, true);
}
$pastas[] = $pastaRecCompleta;

// --- salvar-etapa: placa -> digitalizacao_notas ---
$r1 = rodar($dir . '/_caso_salvar_etapa.php', [$idTotem, $idAtRec, 'digitalizacao_notas', base64_encode('{}')]);
afirmar('[Recebimento] placa -> digitalizacao_notas', str_contains($r1['saida'], '"sucesso":true'));

// --- digitalizar 3 notas (mock upload) ---
foreach ([1, 2, 3] as $ordem) {
    $rNota = rodar($dir . '/_caso_nota_processar.php', [$idTotem, $idAtRec, $ordem]);
    afirmar("[Recebimento] nota ordem={$ordem} digitalizada (mock upload)", str_contains($rNota['saida'], '"sucesso":true'));
}

// --- definir numero: ordem 1 = '111', ordem 2 tenta duplicar '111'
//     (REJEITADO), ordem 2 grava '222', ordem 3 grava '333' ---
$rNum1 = rodar($dir . '/_caso_nota_definir_numero.php', [$idTotem, $idAtRec, 1, '111', 'MANUAL']);
afirmar('[Recebimento] numero da nota 1 gravado (111)', str_contains($rNum1['saida'], '"sucesso":true'));

$rNum2Dup = rodar($dir . '/_caso_nota_definir_numero.php', [$idTotem, $idAtRec, 2, '111', 'MANUAL']);
afirmar('[Recebimento] tentativa de duplicar numero 111 na nota 2 e REJEITADA (NUMERO_NOTA_DUPLICADO, HTTP 409)', str_contains($rNum2Dup['saida'], 'NUMERO_NOTA_DUPLICADO') && str_contains($rNum2Dup['saida'], 'HTTP_CODE:409'));

$rNum2 = rodar($dir . '/_caso_nota_definir_numero.php', [$idTotem, $idAtRec, 2, '222', 'MANUAL']);
afirmar('[Recebimento] numero da nota 2 gravado com valor correto (222) apos rejeicao do duplicado', str_contains($rNum2['saida'], '"sucesso":true'));

$rNum3 = rodar($dir . '/_caso_nota_definir_numero.php', [$idTotem, $idAtRec, 3, '333', 'MANUAL']);
afirmar('[Recebimento] numero da nota 3 gravado (333)', str_contains($rNum3['saida'], '"sucesso":true'));

// F1 (rodada corretiva 2026-10-01): concluir-digitalizacao so conclui com todas
// as notas em estado terminal de OCR. O front real chama identificar-cliente por
// nota; aqui o resultado (OCR sem nenhum cliente identificado) e gravado direto
// pelo DAO, como o endpoint faria.
foreach ([1, 2, 3] as $ordemOcr) {
    $notaOcr = $notaDao->buscarPorAtendimentoEOrdem($idAtRec, $ordemOcr);
    $notaDao->gravarResultadoOcrUnico((int) $notaOcr['id_nota'], $idAtRec, 'NAO_IDENTIFICADA', null);
}

// --- concluir digitalizacao (sem cliente identificado via OCR -> etapa 'cliente') ---
$rConcluir = rodar($dir . '/_caso_concluir_digitalizacao.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] concluirDigitalizacao aceita (1-5 notas, todas com numero)', str_contains($rConcluir['saida'], '"sucesso":true'));
afirmar("[Recebimento] proxima etapa e 'cliente' (nenhuma nota identificou automaticamente)", str_contains($rConcluir['saida'], '"etapa":"cliente"'));

// --- identificacao manual do cliente (etapa 'cliente' -> 'rec_cnh', unica
//     etapa desde a rodada corretiva de migracao-vio-api-br-com-cache de
//     2026-09-26 -- antes 'rec_cnh_frente') ---
// ATUALIZADO (hardening-revisao-notas-e-cliente, 2026-09-30): o cliente MANUAL
// e validado contra tb_cliente ATIVA (D2); linha temporaria, removida na limpeza.
$idClienteTmp = null;
$existeClienteTmp = $pdo->prepare('SELECT id_cliente FROM tb_cliente WHERE cnpj = :c');
$existeClienteTmp->execute(['c' => '11222333000181']);
if ($existeClienteTmp->fetchColumn() === false) {
    $pdo->prepare("INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:n, :n, '11222333000181', 1)")->execute(['n' => 'CLIENTE E2E LTDA']);
    $idClienteTmp = (int) $pdo->lastInsertId();
}
$dadosCliente = base64_encode(json_encode(['nome' => 'CLIENTE E2E LTDA', 'cnpj' => '11222333000181']));
$rCliente = rodar($dir . '/_caso_salvar_etapa.php', [$idTotem, $idAtRec, 'cliente', $dadosCliente]);
afirmar('[Recebimento] cliente identificado manualmente, avanca para rec_cnh', str_contains($rCliente['saida'], '"sucesso":true'));

// --- CNH/CRLV por QR-only (VIO falso via factory; JPEG sintetico em memoria) ---
$cnhRec = qaDocumentoQr($dir, $idTotem, $idAtRec, 'cnh');
afirmar('[Recebimento] CNH: iniciar-processamento QR responde sucesso', str_contains($cnhRec['iniciar'], '"sucesso":true'));
afirmar('[Recebimento] CNH: status-processamento aprova (pode_avancar=true)', str_contains($cnhRec['status'], '"pode_avancar":true'));
$atCnh = $atendimentoDao->buscarPorId($idAtRec);
afirmar('[Recebimento] CNH aprovada via VIO falso (origem VIO_API_BR, CPF persistido)', ($atCnh['cnh_origem_validacao'] ?? '') === 'VIO_API_BR' && ($atCnh['motorista_cpf'] ?? '') === '52998224725');
afirmar('[Recebimento] sem arquivos de CNH/CRLV no storage temporario (QR-only)', !is_dir($pastaRecCompleta) || array_filter(glob($pastaRecCompleta . '/*') ?: [], fn($f) => preg_match('/(cnh|crlv)/i', basename($f))) === []);

$rAvanca2 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] rec_cnh -> rec_crlv', str_contains($rAvanca2['saida'], '"etapa":"rec_crlv"'));
$rAvanca3 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] rec_crlv -> rec_aguarde_documentos (gate de upload liberado no QR-only)', str_contains($rAvanca3['saida'], '"etapa":"rec_aguarde_documentos"'));

// gate 'ambos_aprovados': com so a CNH aprovada, rec_aguarde_documentos NAO avanca
$rAvancaBloqueado = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] rec_aguarde_documentos -> rec_confirmacao BLOQUEADO com CRLV nao aprovado (gate ambos_aprovados)', str_contains($rAvancaBloqueado['saida'], 'documentos pendentes') && ($atendimentoDao->buscarPorId($idAtRec)['etapa_atual'] ?? '') === 'rec_aguarde_documentos');

$crlvRec = qaDocumentoQr($dir, $idTotem, $idAtRec, 'crlv', 'REC1A23');
afirmar('[Recebimento] CRLV: iniciar + status aprovam (pode_avancar=true)', str_contains($crlvRec['iniciar'], '"sucesso":true') && str_contains($crlvRec['status'], '"pode_avancar":true'));
$atCrlv = $atendimentoDao->buscarPorId($idAtRec);
afirmar('[Recebimento] CRLV aprovado via VIO falso (origem VIO_API_BR, RNTRC/Tipo persistidos)', ($atCrlv['crlv_origem_validacao'] ?? '') === 'VIO_API_BR' && ($atCrlv['crlv_rntc'] ?? '') === 'QA-RNTRC' && ($atCrlv['crlv_tipo_veiculo'] ?? '') === 'CAMINHAO');

$rAvanca4 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] rec_aguarde_documentos -> rec_confirmacao (ambos aprovados)', str_contains($rAvanca4['saida'], '"etapa":"rec_confirmacao"'));
afirmar('[Recebimento] avanco devolve dados_confirmacao com RNTRC e Tipo', str_contains($rAvanca4['saida'], '"dados_confirmacao"') && str_contains($rAvanca4['saida'], 'QA-RNTRC') && str_contains($rAvanca4['saida'], 'CAMINHAO'));

// --- confirmar dados do motorista (RNTRC e Tipo obrigatorios na confirmacao) ---
$dadosConfirmacao = base64_encode(json_encode([
    'motorista_nome' => 'QA MOTORISTA',
    'motorista_cpf' => '52998224725',
    'cnh_validade' => '2035-12-31',
    'crlv_ano' => 2026,
    'crlv_uf' => 'SP',
    'crlv_rntc' => 'QA-RNTRC',
    'crlv_tipo_veiculo' => 'CAMINHAO',
]));
$rConfirma = rodar($dir . '/_caso_salvar_etapa.php', [$idTotem, $idAtRec, 'confirmacao', $dadosConfirmacao]);
afirmar('[Recebimento] confirmacao dos dados do motorista aceita', str_contains($rConfirma['saida'], '"sucesso":true'));

// --- finalizar(): TODOS os gates (doctos/posse/tipo/status/etapa/documentos)
//     ja passaram — o UNICO bloqueio restante deve ser TALENT_CHECKIN_DESATIVADO ---
$rFinalizarRec = rodar($dir . '/_caso_trava_doctos_pendente.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] finalizar(): bloqueio LIMPO por TALENT_CHECKIN_DESATIVADO (503) — todos os demais gates ja passaram', str_contains($rFinalizarRec['saida'], 'TALENT_CHECKIN_DESATIVADO') && str_contains($rFinalizarRec['saida'], 'HTTP_CODE:503'));
afirmar('[Recebimento] finalizar(): NENHUMA chamada de rede real ao Talent (TalentRnEspiao nao foi acionado)', !str_contains($rFinalizarRec['saida'], 'ESPIAO_PROCESSARCHECKIN_CHAMADO'));
afirmar('[Recebimento] finalizar(): NAO e mais bloqueado por falta de doctos/NOTAS_SEM_NUMERO (bug antigo corrigido)', !str_contains($rFinalizarRec['saida'], 'NOTAS_SEM_NUMERO'));

// ============================================================
// FLUXO EXPEDICAO COMPLETO
// ============================================================
// Selecao real de ordem depende do banco externo de gestao de coletas (fora
// de escopo): ordem_coleta e cliente gravados via fixture direta; CNH/CRLV
// seguem o MESMO caminho QR-only (VIO falso) do Recebimento.
$idAtExp = $atendimentoDao->criar($idTotem, 'expedicao', 'EXP1A23');
$idsAtendimento[] = $idAtExp;
$pdo->prepare('UPDATE tb_atendimento SET ordem_coleta = :oc WHERE id_atendimento = :id')->execute(['oc' => 'OC-E2EEXP01', 'id' => $idAtExp]);
$atendimentoDao->salvarCliente($idAtExp, 'CLIENTE E2E LTDA', '11222333000181');
$atendimentoDao->atualizarEtapa($idAtExp, 'exp_cnh');

$cnhExp = qaDocumentoQr($dir, $idTotem, $idAtExp, 'cnh');
afirmar('[Expedicao] CNH aprovada por QR (VIO falso)', str_contains($cnhExp['status'], '"pode_avancar":true') && ($atendimentoDao->buscarPorId($idAtExp)['cnh_origem_validacao'] ?? '') === 'VIO_API_BR');
$rExp1 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtExp]);
afirmar('[Expedicao] exp_cnh -> exp_crlv', str_contains($rExp1['saida'], '"etapa":"exp_crlv"'));
$crlvExp = qaDocumentoQr($dir, $idTotem, $idAtExp, 'crlv', 'EXP1A23');
afirmar('[Expedicao] CRLV aprovado por QR (VIO falso)', str_contains($crlvExp['status'], '"pode_avancar":true') && ($atendimentoDao->buscarPorId($idAtExp)['crlv_origem_validacao'] ?? '') === 'VIO_API_BR');
$rExp2 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtExp]);
afirmar('[Expedicao] exp_crlv -> exp_aguarde_documentos', str_contains($rExp2['saida'], '"etapa":"exp_aguarde_documentos"'));
$rExp3 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtExp]);
afirmar('[Expedicao] exp_aguarde_documentos -> exp_confirmacao (dados_confirmacao presente)', str_contains($rExp3['saida'], '"etapa":"exp_confirmacao"') && str_contains($rExp3['saida'], '"dados_confirmacao"'));
$dadosConfExp = base64_encode(json_encode(['motorista_nome' => 'QA MOTORISTA', 'motorista_cpf' => '52998224725', 'cnh_validade' => '2035-12-31', 'crlv_ano' => 2026, 'crlv_uf' => 'SP', 'crlv_rntc' => 'QA-RNTRC', 'crlv_tipo_veiculo' => 'CAMINHAO']));
$rConfExp = rodar($dir . '/_caso_salvar_etapa.php', [$idTotem, $idAtExp, 'confirmacao', $dadosConfExp]);
afirmar('[Expedicao] confirmacao dos dados aceita', str_contains($rConfExp['saida'], '"sucesso":true'));

$rFinalizarExp = rodar($dir . '/_caso_trava_doctos_pendente.php', [$idTotem, $idAtExp]);
afirmar('[Expedicao] finalizar(): bloqueio LIMPO por TALENT_CHECKIN_DESATIVADO (503) — gates de doctos/ordem_coleta ja passaram', str_contains($rFinalizarExp['saida'], 'TALENT_CHECKIN_DESATIVADO') && str_contains($rFinalizarExp['saida'], 'HTTP_CODE:503'));
afirmar('[Expedicao] finalizar(): NENHUMA chamada de rede real ao Talent (TalentRnEspiao nao foi acionado)', !str_contains($rFinalizarExp['saida'], 'ESPIAO_PROCESSARCHECKIN_CHAMADO'));

// ============================================================
// Endpoint de impressao real: atendimento SEM sucesso de Talent nunca gera PDF
// ============================================================
$rImpressaoSemSucesso = rodar($dir . '/_caso_impressao_gerar_etiqueta.php', [$idTotem, $idAtRec, '0']);
afirmar('[Impressao] atendimento sem talent_checkin_status=ENVIADO: erro explicito, PDF NUNCA gerado', !str_contains($rImpressaoSemSucesso['saida'], '"sucesso":true') && str_contains($rImpressaoSemSucesso['saida'], 'HTTP_CODE:409'));

// ============================================================
// Fixture direta (NUNCA via chamada real ao Talent) simulando sucesso de
// check-in ja persistido, para confirmar a geracao real do PDF + reimpressao
// ============================================================
$pdo->prepare("
    UPDATE tb_atendimento
    SET status = 'concluido', etapa_atual = 'impressao', talent_checkin_status = 'ENVIADO',
        talent_senha = 'SENHA-E2E-999', talent_enviado_em = NOW()
    WHERE id_atendimento = :id
")->execute(['id' => $idAtRec]);

$rImpressao1 = rodar($dir . '/_caso_impressao_gerar_etiqueta.php', [$idTotem, $idAtRec, '0']);
afirmar('[Impressao] apos fixture de sucesso: PDF gerado (pdf_base64 presente)', str_contains($rImpressao1['saida'], 'pdf_base64'));
$dadosImp1 = json_decode(trim(explode("\nHTTP_CODE:", $rImpressao1['saida'])[0]), true);
$identificadorImp1 = $dadosImp1['dados']['identificador'] ?? null;
afirmar('[Impressao] identificador presente na 1a geracao', !empty($identificadorImp1));

$rImpressao2 = rodar($dir . '/_caso_impressao_gerar_etiqueta.php', [$idTotem, $idAtRec, '1']);
$dadosImp2 = json_decode(trim(explode("\nHTTP_CODE:", $rImpressao2['saida'])[0]), true);
$identificadorImp2 = $dadosImp2['dados']['identificador'] ?? null;
afirmar('[Impressao] reimpressao (reimpressao=1) gera identificador DIFERENTE da 1a geracao', !empty($identificadorImp2) && $identificadorImp2 !== $identificadorImp1);

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
