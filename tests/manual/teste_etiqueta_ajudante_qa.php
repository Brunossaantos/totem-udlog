<?php

/**
 * Teste manual em banco QA descartavel `qa_qr_exclusivo_<hex>` (NUNCA udlog_totem):
 * rota de producao ImpressaoAtendimentoController::gerarEtiqueta com `destinatario`.
 * Subprocessos (_caso_impressao_gerar_etiqueta.php) rodam com o prepend QA. Sem
 * impressao, sem Talent, sem HTTP real.
 *
 * Uso: php tests/manual/teste_etiqueta_ajudante_qa.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/qa_qr_exclusivo_legado.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Dao\AtendimentoDao;

$total = 0; $falhas = 0;
function afirmar(string $d, bool $c): void { global $total, $falhas; $total++; echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n"; if (!$c) $falhas++; }

function caso(int $idTotem, int $idAt, string $dest = '', bool $reimp = false): array
{
    $r = qaLegadoRodar(__DIR__ . '/_caso_impressao_gerar_etiqueta.php', [$idTotem, $idAt, $reimp ? '1' : '0', $dest]);
    $partes = explode("\nHTTP_CODE:", $r['saida']);
    $json = json_decode(trim($partes[0]), true);
    $http = (int) ($partes[1] ?? 0);
    if ($http === 0 && ($json['sucesso'] ?? false) === true) { $http = 200; } // Resposta::sucesso nao define o codigo (CLI => false)
    return ['json' => $json, 'http' => $http, 'bruto' => $r['saida']];
}

$banco = null; $storage = null;
try {
    [$pdo, $banco, $storage] = qaLegadoCriarAmbiente();
    afirmar('banco QA com nome qa_qr_exclusivo_*', preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $banco) === 1);
    afirmar('conectado ao banco QA (nao udlog_totem)', $pdo->query('SELECT DATABASE()')->fetchColumn() === $banco);

    $dao = new AtendimentoDao($pdo);
    $tv = talentCriarTotemComEmpresa($pdo, 'QA_ETQ_VITIMA', null);
    $ti = talentCriarTotemComEmpresa($pdo, 'QA_ETQ_INVASOR', null);

    $novo = function (array $set) use ($pdo, $dao, $tv): int {
        $id = $dao->criar($tv, 'expedicao', 'QAE' . random_int(1000, 9999));
        $dao->atualizarValidacaoCnh($id, 'JOS' . "\u{C9}" . ' A' . "\u{C7}" . 'UCENA QA', '11144477735', '2030-01-01', 'MANUAL', 'PENDENTE_REVISAO');
        $cols = []; $par = ['id' => $id];
        foreach ($set as $k => $v) { $cols[] = "$k = :$k"; $par[$k] = $v; }
        $pdo->prepare('UPDATE tb_atendimento SET ' . implode(', ', $cols) . ' WHERE id_atendimento = :id')->execute($par);
        return $id;
    };
    $base = ['status' => 'concluido', 'etapa_atual' => 'impressao', 'talent_checkin_status' => 'ENVIADO', 'talent_senha' => 'QA-98765'];

    $comAj = $novo($base + ['possui_ajudante' => 1, 'ajudante_nome' => "Jo\u{E3}o P\u{E9}rez \u{1F600}"]);
    $semAj = $novo($base + ['possui_ajudante' => 0]);
    $flagSemNome = $novo($base + ['possui_ajudante' => 1, 'ajudante_nome' => '   ']);
    $nomeSemFlag = $novo($base + ['possui_ajudante' => 0, 'ajudante_nome' => 'Fulano Residual']);
    $emAndamento = $novo(['possui_ajudante' => 1, 'ajudante_nome' => 'Ajudante X']);
    $naoEnviado = $novo(['status' => 'concluido', 'talent_checkin_status' => 'NAO_ENVIADO', 'possui_ajudante' => 1, 'ajudante_nome' => 'Ajudante Y']);
    $semSenha = $novo(['status' => 'concluido', 'talent_checkin_status' => 'ENVIADO', 'talent_senha' => null, 'possui_ajudante' => 1, 'ajudante_nome' => 'Ajudante Z']);

    // ajudante com ajudante: 200
    $r = caso($tv, $comAj, 'ajudante');
    afirmar('ajudante com ajudante: HTTP 200 e sucesso', $r['http'] === 200 && ($r['json']['sucesso'] ?? false) === true);
    $d = $r['json']['dados'] ?? [];
    afirmar('ajudante: destinatario=ajudante', ($d['destinatario'] ?? null) === 'ajudante');
    foreach (['pdf_base64', 'identificador', 'largura_mm', 'comprimento_mm', 'orientacao', 'corte_apos_impressao'] as $k) {
        afirmar("ajudante: campo $k presente", array_key_exists($k, $d));
    }
    $pdf = base64_decode((string) ($d['pdf_base64'] ?? ''), true) ?: '';
    afirmar('ajudante: PDF valido', str_starts_with($pdf, '%PDF-'));
    $tmp = sys_get_temp_dir() . '/qa_etq_' . bin2hex(random_bytes(4)) . '.pdf'; file_put_contents($tmp, $pdf);
    exec('pdftotext -enc UTF-8 ' . escapeshellarg($tmp) . ' - 2>&1', $o); @unlink($tmp);
    $txt = implode("\n", $o);
    afirmar('ajudante: PDF com AJUDANTE, "Joao Perez" (sem acento/emoji), mesmo nrRegAcesso e Gerado em', str_contains($txt, 'AJUDANTE') && str_contains($txt, 'Joao Perez') && str_contains($txt, 'QA-98765') && str_contains($txt, 'Gerado em:'));
    afirmar('ajudante: PDF sem UDLOG, sem CPF e so ASCII', !str_contains($txt, 'UDLOG') && !str_contains($txt, '11144477735') && preg_match('/[^\x09\x0A\x0C\x0D\x20-\x7E]/', $txt) !== 1);
    afirmar('ajudante: nao ha tem_etiqueta_ajudante na resposta do ajudante', !array_key_exists('tem_etiqueta_ajudante', $d));
    $r2 = caso($tv, $comAj, 'ajudante', true);
    afirmar('ajudante: identificador novo a cada chamada', ($r2['json']['dados']['identificador'] ?? '') !== '' && $r2['json']['dados']['identificador'] !== $d['identificador']);

    // motorista com ajudante / padrao / sem ajudante
    $m = caso($tv, $comAj, 'motorista');
    afirmar('motorista: 200, destinatario=motorista, tem_etiqueta_ajudante=true', $m['http'] === 200 && ($m['json']['dados']['destinatario'] ?? '') === 'motorista' && ($m['json']['dados']['tem_etiqueta_ajudante'] ?? null) === true);
    $m = caso($tv, $comAj);
    afirmar('sem parametro = motorista (padrao) com tem_etiqueta_ajudante=true', $m['http'] === 200 && ($m['json']['dados']['destinatario'] ?? '') === 'motorista' && ($m['json']['dados']['tem_etiqueta_ajudante'] ?? null) === true);
    $tmp = sys_get_temp_dir() . '/qa_etq_' . bin2hex(random_bytes(4)) . '.pdf'; file_put_contents($tmp, base64_decode($m['json']['dados']['pdf_base64']));
    $o = []; exec('pdftotext -enc UTF-8 ' . escapeshellarg($tmp) . ' - 2>&1', $o); @unlink($tmp);
    $txtM = implode("\n", $o);
    afirmar('motorista: PDF com UDLOG, JOSE ACUCENA QA sem acento, SEM "Ajudante" nem nome do ajudante e so ASCII', str_contains($txtM, 'UDLOG') && str_contains($txtM, 'JOSE ACUCENA QA') && !stripos($txtM, 'Ajudante') && !str_contains($txtM, 'Joao Perez') && preg_match('/[^\x09\x0A\x0C\x0D\x20-\x7E]/', $txtM) !== 1);
    $m = caso($tv, $semAj);
    afirmar('motorista sem ajudante: tem_etiqueta_ajudante=false', $m['http'] === 200 && ($m['json']['dados']['tem_etiqueta_ajudante'] ?? null) === false);
    $m = caso($tv, $flagSemNome);
    afirmar('motorista com flag e nome vazio: tem_etiqueta_ajudante=false', $m['http'] === 200 && ($m['json']['dados']['tem_etiqueta_ajudante'] ?? null) === false);
    $m = caso($tv, $nomeSemFlag);
    afirmar('motorista com nome mas possui_ajudante=0: tem_etiqueta_ajudante=false', $m['http'] === 200 && ($m['json']['dados']['tem_etiqueta_ajudante'] ?? null) === false);

    // ajudante sem ajudante: recusado sem PDF
    foreach (['sem ajudante' => $semAj, 'flag=1 e nome vazio' => $flagSemNome, 'nome sem flag' => $nomeSemFlag] as $rot => $id) {
        $r = caso($tv, $id, 'ajudante');
        afirmar("ajudante $rot: HTTP 409, sem sucesso e sem pdf_base64", $r['http'] === 409 && !str_contains($r['bruto'], '"sucesso":true') && !str_contains($r['bruto'], 'pdf_base64'));
    }

    // IDOR
    $r = caso($ti, $comAj, 'ajudante');
    afirmar('ajudante: outro totem => 404 generico, sem PDF/senha', $r['http'] === 404 && str_contains($r['bruto'], 'Atendimento nao encontrado') && !str_contains($r['bruto'], 'pdf_base64') && !str_contains($r['bruto'], 'QA-98765'));

    // nao concluido / nao ENVIADO / sem senha
    foreach (['em_andamento' => $emAndamento, 'nao ENVIADO' => $naoEnviado, 'sem talent_senha' => $semSenha] as $rot => $id) {
        $r = caso($tv, $id, 'ajudante');
        afirmar("ajudante $rot: recusado (409), sem PDF", $r['http'] === 409 && !str_contains($r['bruto'], 'pdf_base64'));
    }

    // allowlist
    foreach (['admin', 'AJUDANTE', 'motorista,ajudante'] as $inv) {
        $r = caso($tv, $comAj, $inv);
        afirmar("destinatario invalido '$inv': 422 sem PDF", $r['http'] === 422 && !str_contains($r['bruto'], 'pdf_base64'));
    }
} finally {
    qaLegadoLimparAmbiente($banco, $storage);
}

$restantes = (int) qaQrPdoServidor(qaQrConfiguracao())->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'qa\\_qr\\_exclusivo\\_%'")->fetchColumn();
afirmar('zero bancos qa_qr_exclusivo_% restantes', $restantes === 0);

echo "\n=== RESULTADO: {$total} testes, " . ($total - $falhas) . " passaram, {$falhas} falharam ===\n";
exit($falhas > 0 ? 1 : 0);
