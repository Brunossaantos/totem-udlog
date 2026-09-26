<?php

/**
 * Teste E2E (mock, SEM chamada real ao Talent, SEM impressao fisica) —
 * demanda talent-doctos-finalizacao-checkin (2026-09-14), item "E2E" do
 * roteiro de /02-testes. Todo o percurso roda via subprocessos que chamam os
 * Controllers reais diretamente (mesmo padrao ja usado por
 * teste_fluxo_recebimento_documentos.php/teste_talent_idor_finalizar.php
 * deste projeto — Resposta::sucesso()/erro() chamam exit(), entao cada
 * chamada roda isolada em processo proprio).
 *
 * Fluxo Recebimento completo:
 *   iniciar (via fixture direta, mesmo padrao ja usado por
 *   talentCriarAtendimentoPronto para nao depender de API externa fora de
 *   escopo) -> digitalizar 3 notas (mock upload) -> definir numero de cada
 *   uma (incluindo 1 tentativa DUPLICADA rejeitada) -> concluirDigitalizacao()
 *   -> identificacao manual do cliente -> upload+aprovacao manual de CNH/CRLV
 *   -> avancar ate rec_confirmacao -> confirmar dados do motorista ->
 *   finalizar() -> confirma 503 TALENT_CHECKIN_DESATIVADO limpo, com TODOS os
 *   gates (doctos/posse/tipo/status/etapa/documentos) ja tendo passado (i.e.
 *   o UNICO bloqueio restante e a ativacao, nao mais falta de doctos).
 *
 * Fluxo Expedicao completo:
 *   atendimento com ordem_coleta ja selecionada (fixture direta — selecao
 *   real depende do banco externo de gestao de coletas, fora de escopo desta
 *   demanda) -> aprovar CNH/CRLV -> avancar ate exp_confirmacao -> finalizar()
 *   -> mesma confirmacao de bloqueio limpo.
 *
 * Zero chamada de rede real ao Talent: cada chamada de finalizar() usa
 * _caso_trava_doctos_pendente.php, que instancia TalentClient com
 * TALENT_API_URL/TALENT_API_KEY REAIS do .env + um TalentRnEspiao que lanca
 * excecao se processarCheckin() for sequer invocado — a auséncia do marcador
 * ESPIAO_PROCESSARCHECKIN_CHAMADO na saida PROVA estruturalmente que nenhuma
 * tentativa de montar payload/chamar o Talent ocorreu (o bloqueio acontece
 * ANTES disso, no gate TALENT_CHECKIN_ATIVO).
 *
 * Uso: php tests/manual/teste_e2e_recebimento_expedicao_mock.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;

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

function rodar(string $script, array $args): array
{
    $php = PHP_BINARY;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string) $a);
    }
    exec($cmd, $saida, $codigo);
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

$dir = __DIR__;
$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);

$idTotem = talentCriarTotemComEmpresa($pdo, 'TESTE_E2E_' . bin2hex(random_bytes(3)), 1);
$pastas = [];
$idsAtendimento = [];

// ============================================================
// FLUXO RECEBIMENTO COMPLETO
// ============================================================
$idAtRec = $atendimentoDao->criar($idTotem, 'recebimento', 'E2EREC01');
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

// --- concluir digitalizacao (sem cliente identificado via OCR -> etapa 'cliente') ---
$rConcluir = rodar($dir . '/_caso_concluir_digitalizacao.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] concluirDigitalizacao aceita (1-5 notas, todas com numero)', str_contains($rConcluir['saida'], '"sucesso":true'));
afirmar("[Recebimento] proxima etapa e 'cliente' (nenhuma nota identificou automaticamente)", str_contains($rConcluir['saida'], '"etapa":"cliente"'));

// --- identificacao manual do cliente (etapa 'cliente' -> 'rec_cnh', unica
//     etapa desde a rodada corretiva de migracao-vio-api-br-com-cache de
//     2026-09-26 -- antes 'rec_cnh_frente') ---
$dadosCliente = base64_encode(json_encode(['nome' => 'CLIENTE E2E LTDA', 'cnpj' => '11222333000181']));
$rCliente = rodar($dir . '/_caso_salvar_etapa.php', [$idTotem, $idAtRec, 'cliente', $dadosCliente]);
afirmar('[Recebimento] cliente identificado manualmente, avanca para rec_cnh', str_contains($rCliente['saida'], '"sucesso":true'));

// --- upload CNH frente/verso + CRLV (mock) ---
$imagemJpegBase64 = 'data:image/jpeg;base64,' . base64_encode(
    base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=')
);
$rUploadCnhF = rodar($dir . '/_caso_upload_documento.php', [$idTotem, $idAtRec, 'cnh_frente', $imagemJpegBase64]);
afirmar('[Recebimento] upload CNH frente (mock)', str_contains($rUploadCnhF['saida'], '"sucesso":true'));
$rAvancaBloqueado = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] rec_cnh -> rec_crlv BLOQUEADO so com a frente (gate upload_cnh exige os 2 arquivos, etapa unica desde 2026-09-26)', str_contains($rAvancaBloqueado['saida'], 'documentos pendentes'));

$rUploadCnhV = rodar($dir . '/_caso_upload_documento.php', [$idTotem, $idAtRec, 'cnh_verso', $imagemJpegBase64]);
afirmar('[Recebimento] upload CNH verso (mock)', str_contains($rUploadCnhV['saida'], '"sucesso":true'));
$rAvanca2 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] rec_cnh -> rec_crlv (apos os 2 lados salvos)', str_contains($rAvanca2['saida'], '"etapa":"rec_crlv"'));

$rUploadCrlv = rodar($dir . '/_caso_upload_documento.php', [$idTotem, $idAtRec, 'crlv', $imagemJpegBase64]);
afirmar('[Recebimento] upload CRLV (mock)', str_contains($rUploadCrlv['saida'], '"sucesso":true'));
$rAvanca3 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] rec_crlv -> rec_aguarde_documentos', str_contains($rAvanca3['saida'], '"etapa":"rec_aguarde_documentos"'));

// --- aprovacao MANUAL de CNH/CRLV (mock/fixture, sem VIO real) ---
$rManualCnh = rodar($dir . '/_caso_preencher_manual.php', [$idTotem, $idAtRec, 'cnh', 'MOTORISTA E2E', '11144477735', '2031-01-01']);
afirmar('[Recebimento] CNH aprovada manualmente', str_contains($rManualCnh['saida'], '"origem":"MANUAL"'));
$rManualCrlv = rodar($dir . '/_caso_preencher_manual.php', [$idTotem, $idAtRec, 'crlv', 'E2EREC01', '2025', 'SP', '12345678', 'CAMINHAO']);
afirmar('[Recebimento] CRLV aprovado manualmente', str_contains($rManualCrlv['saida'], '"origem":"MANUAL"'));

$rAvanca4 = rodar($dir . '/_caso_avancar_etapa_generico.php', [$idTotem, $idAtRec]);
afirmar('[Recebimento] rec_aguarde_documentos -> rec_confirmacao (ambos aprovados)', str_contains($rAvanca4['saida'], '"etapa":"rec_confirmacao"'));

// --- confirmar dados do motorista ---
$dadosConfirmacao = base64_encode(json_encode([
    'motorista_nome' => 'MOTORISTA E2E',
    'motorista_cpf' => '11144477735',
    'cnh_validade' => '2031-01-01',
    'crlv_ano' => 2025,
    'crlv_uf' => 'SP',
    'crlv_rntc' => '12345678',
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
// de escopo desta demanda) — ordem_coleta gravada via fixture direta, mesmo
// padrao ja usado por talentCriarAtendimentoPronto/outros testes deste
// projeto.
$fixExp = talentCriarAtendimentoPronto($pdo, $atendimentoDao, $idTotem, 'expedicao', 'E2EEXP01', '11222333000181', 'SP', true, '12345678', 'CAMINHAO', 'OC-E2EEXP01');
$pastas[] = $fixExp['pasta_completa'];
$idsAtendimento[] = $fixExp['id_atendimento'];
// talentCriarAtendimentoPronto ja deixa em exp_confirmacao com CNH/CRLV
// aprovados (MANUAL) — reproduzimos aqui so a etapa final (finalizar), ja
// que a sequencia de avanco de etapas (exp_cnh -> exp_crlv ->
// exp_aguarde_documentos -> exp_confirmacao) e a MESMA maquina de estados ja
// coberta pelo fluxo de Recebimento acima e por
// teste_avancar_etapa_expedicao.php (fora do escopo desta demanda especifica
// de doctos/finalizacao).

$rFinalizarExp = rodar($dir . '/_caso_trava_doctos_pendente.php', [$idTotem, $fixExp['id_atendimento']]);
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

// ============================================================
// Limpeza
// ============================================================
foreach ($pastas as $p) {
    talentLimparPasta($p);
}
foreach ($idsAtendimento as $id) {
    $pdo->prepare('DELETE FROM tb_fila_envio WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
