<?php

/**
 * Teste manual (sem rede real) do RNTC/tipo de veiculo do CRLV — extensao
 * 2026-09-10 de docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md
 * ("Terceira tentativa — CAUSA RAIZ ENCONTRADA"), que confirmou
 * veiculo.rntc/veiculo.tipo como obrigatorios pelo Talent via teste real
 * de Producao (HTTP 400).
 *
 * Cobre (itens do roteiro de /02-testes desta extensao):
 * - item 2: extracao de rntrc/tipo da resposta VIO (chave de origem `rntrc`,
 *   destino `rntc`) aprova o CRLV quando tudo mais tambem esta OK;
 * - item 3: rntc/tipo vazios ou placeholder ("xxxxx"/"string") NAO aprovam,
 *   com motivo especifico;
 * - item 4: cache completo (com rntc/tipo) e reaproveitado (cache-hit);
 *   cache "antigo" (sem rntc/tipo, simulando registro pre-migration 010)
 *   NUNCA e retornado por VioCacheDao::buscarCrlvValido;
 * - item 5: preenchimento manual reaproveita rntc/tipo ja aprovados pelo
 *   VIO quando vierem vazios na requisicao (so outro campo, ex. UF, sendo
 *   re-enviado) — e rejeita como incompleto quando NAO ha nenhum valor
 *   previo valido e rntc/tipo vierem vazios (via DocumentoController,
 *   exercitando a logica real de "so dos campos ausentes").
 *
 * Uso: php tests/manual/teste_talent_rntc_tipo_crlv.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;
use App\Rn\VioDecodeClient;

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

class VioDecodeClientFalsoRntcTipo extends VioDecodeClient
{
    public function __construct(private array $resultadoFixo)
    {
        parent::__construct([
            'VIO_AMBIENTE' => 'trial',
            'VIO_TRIAL_BEARER' => 'fake-bearer-teste',
            'VIO_TRIAL_DECODE_URL' => 'https://exemplo.invalido/decode',
        ]);
    }

    public function decodificar(string $rawValueQr): array
    {
        return $this->resultadoFixo;
    }
}

$atendimentoDao = new AtendimentoDao($pdo);
$cacheDao = new VioCacheDao($pdo);
$documentoRn = new DocumentoRn($cacheDao, $atendimentoDao);

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_RNTC_" . bin2hex(random_bytes(3)) . "', 'Totem Teste RNTC', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$idsAtendimento = [];

// ============================================================
// Item 2: extracao de rntrc/tipo da resposta VIO aprova o CRLV
// ============================================================
$idAt1 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT1111');
$idsAtendimento[] = $idAt1;
$at1 = $atendimentoDao->buscarPorId($idAt1);
$vioOk = new VioDecodeClientFalsoRntcTipo(['ok' => true, 'dados' => ['data' => ['placa' => 'RNT1111', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '87654321', 'tipo' => 'CAMINHAO TRUCADO']]]);
$r1 = $documentoRn->validarCrlv($at1, $vioOk, random_bytes(16));
afirmar('CRLV aprovado com rntrc/tipo preenchidos vindos da VIO', $r1['pode_avancar'] === true);
$at1Depois = $atendimentoDao->buscarPorId($idAt1);
afirmar('crlv_rntc gravado com o valor extraido de "rntrc" da resposta VIO', $at1Depois['crlv_rntc'] === '87654321');
afirmar('crlv_tipo_veiculo gravado com o valor extraido de "tipo" da resposta VIO', $at1Depois['crlv_tipo_veiculo'] === 'CAMINHAO TRUCADO');
afirmar('crlvAprovado() confirma aprovacao a partir do banco', $documentoRn->crlvAprovado($at1Depois) === true);

// ============================================================
// Item 3: rntc/tipo vazios NAO aprovam, com motivo especifico
// ============================================================
$idAt2 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT2222');
$idsAtendimento[] = $idAt2;
$at2 = $atendimentoDao->buscarPorId($idAt2);
$vioSemRntc = new VioDecodeClientFalsoRntcTipo(['ok' => true, 'dados' => ['data' => ['placa' => 'RNT2222', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '', 'tipo' => 'CAMINHAO']]]);
$r2 = $documentoRn->validarCrlv($at2, $vioSemRntc, random_bytes(16));
afirmar('CRLV com rntc vazio: pode_avancar = false', $r2['pode_avancar'] === false);
afirmar('Motivo da rejeicao (rntc vazio) menciona RNTC', str_contains($r2['motivo'], 'RNTC'));

$idAt3 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT3333');
$idsAtendimento[] = $idAt3;
$at3 = $atendimentoDao->buscarPorId($idAt3);
$vioSemTipo = new VioDecodeClientFalsoRntcTipo(['ok' => true, 'dados' => ['data' => ['placa' => 'RNT3333', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '87654321', 'tipo' => '']]]);
$r3 = $documentoRn->validarCrlv($at3, $vioSemTipo, random_bytes(16));
afirmar('CRLV com tipo de veiculo vazio: pode_avancar = false', $r3['pode_avancar'] === false);
afirmar('Motivo da rejeicao (tipo vazio) menciona "Tipo de veiculo"', str_contains($r3['motivo'], 'Tipo de veiculo'));

// ============================================================
// Item 3 (placeholder): rntc/tipo com valor placeholder ("xxxxx"/"string")
// NAO aprovam
// ============================================================
$idAt4 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT4444');
$idsAtendimento[] = $idAt4;
$at4 = $atendimentoDao->buscarPorId($idAt4);
$vioRntcPlaceholder = new VioDecodeClientFalsoRntcTipo(['ok' => true, 'dados' => ['data' => ['placa' => 'RNT4444', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => 'xxxxx', 'tipo' => 'CAMINHAO']]]);
$r4 = $documentoRn->validarCrlv($at4, $vioRntcPlaceholder, random_bytes(16));
afirmar('CRLV com rntc placeholder ("xxxxx"): pode_avancar = false', $r4['pode_avancar'] === false);
afirmar('Motivo da rejeicao (rntc placeholder) menciona placeholder', str_contains($r4['motivo'], 'placeholder'));

$idAt5 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT5555');
$idsAtendimento[] = $idAt5;
$at5 = $atendimentoDao->buscarPorId($idAt5);
$vioTipoPlaceholder = new VioDecodeClientFalsoRntcTipo(['ok' => true, 'dados' => ['data' => ['placa' => 'RNT5555', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '87654321', 'tipo' => 'string']]]);
$r5 = $documentoRn->validarCrlv($at5, $vioTipoPlaceholder, random_bytes(16));
afirmar('CRLV com tipo placeholder ("string"): pode_avancar = false', $r5['pode_avancar'] === false);
afirmar('Motivo da rejeicao (tipo placeholder) menciona placeholder', str_contains($r5['motivo'], 'placeholder'));

// ============================================================
// Item 4: cache completo (com rntc/tipo) e reaproveitado (cache-hit real,
// via QR identico)
// ============================================================
$idAt6 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT1111'); // mesma placa do item 2, QR compartilhado
$idsAtendimento[] = $idAt6;
$at6 = $atendimentoDao->buscarPorId($idAt6);
$bytesQrCompartilhado = random_bytes(16);
$vioCacheavel = new VioDecodeClientFalsoRntcTipo(['ok' => true, 'dados' => ['data' => ['placa' => 'RNT1111', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '11112222', 'tipo' => 'CARRETA']]]);
$documentoRn->validarCrlv($at6, $vioCacheavel, $bytesQrCompartilhado); // popula o cache

$vioNuncaChamar = new class extends VioDecodeClient {
    public function __construct() { parent::__construct(['VIO_AMBIENTE' => 'trial', 'VIO_TRIAL_BEARER' => 'x', 'VIO_TRIAL_DECODE_URL' => 'https://exemplo.invalido']); }
    public function decodificar(string $rawValueQr): array { throw new \RuntimeException('nao deveria chamar o VIO — deveria ser cache hit'); }
};
$idAt7 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT1111');
$idsAtendimento[] = $idAt7;
$at7 = $atendimentoDao->buscarPorId($idAt7);
$r7 = $documentoRn->validarCrlv($at7, $vioNuncaChamar, $bytesQrCompartilhado);
afirmar('Cache COMPLETO (com rntc/tipo) e reaproveitado (cache-hit, VIO nunca chamado de novo)', $r7['pode_avancar'] === true);
$at7Depois = $atendimentoDao->buscarPorId($idAt7);
afirmar('Cache-hit grava rntc corretamente a partir do cache', $at7Depois['crlv_rntc'] === '11112222');
afirmar('Cache-hit grava tipo_veiculo corretamente a partir do cache', $at7Depois['crlv_tipo_veiculo'] === 'CARRETA');

// ============================================================
// Item 4: cache "antigo" sem rntc/tipo (simulando registro pre-migration
// 010) NUNCA e retornado como cache-hit valido
// ============================================================
$identificadorQrAntigo = hash_hmac('sha256', 'qr-legado-sem-rntc-tipo', hex2bin($_ENV['DOCUMENTO_QR_HMAC_KEY']));
$pdo->prepare("
    INSERT INTO tb_vio_cache_crlv (identificador_qr, ambiente, placa, exercicio, uf, rntc, tipo_veiculo, origem, data_validacao, valido_ate)
    VALUES (:qr, 'trial', 'LEG9999', 2024, 'SP', NULL, NULL, 'VIO_TRIAL', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
")->execute(['qr' => $identificadorQrAntigo]);
$cacheAntigo = $cacheDao->buscarCrlvValido($identificadorQrAntigo, 'trial');
afirmar('Cache legado (rntc/tipo_veiculo = NULL, simulando registro pre-migration 010) NUNCA e retornado como cache-hit valido', $cacheAntigo === null);
$pdo->prepare('DELETE FROM tb_vio_cache_crlv WHERE identificador_qr = :qr')->execute(['qr' => $identificadorQrAntigo]);

// Variante: uf presente mas rntc vazio (nao NULL) — tambem incompleto
$identificadorQrParcial = hash_hmac('sha256', 'qr-legado-rntc-vazio', hex2bin($_ENV['DOCUMENTO_QR_HMAC_KEY']));
$pdo->prepare("
    INSERT INTO tb_vio_cache_crlv (identificador_qr, ambiente, placa, exercicio, uf, rntc, tipo_veiculo, origem, data_validacao, valido_ate)
    VALUES (:qr, 'trial', 'LEG8888', 2024, 'SP', '', '', 'VIO_TRIAL', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
")->execute(['qr' => $identificadorQrParcial]);
$cacheParcial = $cacheDao->buscarCrlvValido($identificadorQrParcial, 'trial');
afirmar('Cache com rntc/tipo_veiculo = string vazia (nao NULL) tambem NUNCA e retornado como cache-hit valido', $cacheParcial === null);
$pdo->prepare('DELETE FROM tb_vio_cache_crlv WHERE identificador_qr = :qr')->execute(['qr' => $identificadorQrParcial]);

// ============================================================
// Item 5: preenchimento manual reaproveita rntc/tipo ja aprovados pelo VIO
// quando vierem vazios na requisicao (via DocumentoController::preencherManual)
// ============================================================
$idAt8 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT8888');
$idsAtendimento[] = $idAt8;
$atendimentoDao->atualizarEtapa($idAt8, 'exp_aguarde_documentos');
$at8 = $atendimentoDao->buscarPorId($idAt8);
$vioAprovaOitavo = new VioDecodeClientFalsoRntcTipo(['ok' => true, 'dados' => ['data' => ['placa' => 'RNT8888', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '55556666', 'tipo' => 'BITREM']]]);
$documentoRn->validarCrlv($at8, $vioAprovaOitavo, random_bytes(16));

// Agora o atendente reenvia o preenchimento manual so para "corrigir" a UF,
// deixando rntc/tipo_veiculo vazios na requisicao (front nao reenvia campos
// que ja foram aprovados pelo VIO). Executado via subprocesso real
// (Util\Resposta::sucesso() chama exit(), destrutivo se chamado in-process
// no meio desta suite — mesmo cuidado ja documentado em teste_vio_decode.php).
$php = PHP_BINARY;
$script = __DIR__ . '/_caso_preencher_manual.php';
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
foreach ([$idTotem, $idAt8, 'crlv', 'RNT8888', '2025', 'RJ', '', ''] as $a) {
    $cmd .= ' ' . escapeshellarg((string) $a);
}
exec($cmd, $saidaLinhas8, $codigoSaida8);
$at8Depois = $atendimentoDao->buscarPorId($idAt8);
afirmar('Preenchimento manual (so UF, rntc/tipo vazios) reaproveita rntc ja aprovado pelo VIO', $at8Depois['crlv_rntc'] === '55556666');
afirmar('Preenchimento manual (so UF, rntc/tipo vazios) reaproveita tipo_veiculo ja aprovado pelo VIO', $at8Depois['crlv_tipo_veiculo'] === 'BITREM');
afirmar('Preenchimento manual (so UF) grava a UF corrigida', $at8Depois['crlv_uf'] === 'RJ');
afirmar('Preenchimento manual (so UF) continua aprovado (pode_avancar equivalente a crlvAprovado)', $documentoRn->crlvAprovado($at8Depois) === true);

// ============================================================
// Item 5 (caso oposto): preenchimento manual SEM nenhum valor previo
// valido (NAO_VALIDADO) e rntc/tipo vazios na requisicao — deve ser
// rejeitado como dado incompleto ("Dados incompletos"), simulado via
// subprocesso real (Resposta::erro() encerra a requisicao com exit())
// ============================================================
$idAt9 = $atendimentoDao->criar($idTotem, 'expedicao', 'RNT9999');
$idsAtendimento[] = $idAt9;
$atendimentoDao->atualizarEtapa($idAt9, 'exp_aguarde_documentos');

$php = PHP_BINARY;
$script = __DIR__ . '/_caso_preencher_manual.php';
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
foreach ([$idTotem, $idAt9, 'crlv', 'RNT9999', '2025', 'SP', '', ''] as $a) {
    $cmd .= ' ' . escapeshellarg((string) $a);
}
exec($cmd, $saidaLinhas, $codigoSaida);
$saida = implode("\n", $saidaLinhas);
afirmar('Preenchimento manual SEM valor previo valido e rntc/tipo vazios: rejeitado como "Dados incompletos"', str_contains($saida, 'Dados incompletos') && !str_contains($saida, '"sucesso":true'));
$at9Depois = $atendimentoDao->buscarPorId($idAt9);
afirmar('Atendimento sem valor previo valido: crlv_origem_validacao permanece NAO_VALIDADO (nada foi persistido)', ($at9Depois['crlv_origem_validacao'] ?? 'NAO_VALIDADO') === 'NAO_VALIDADO');

// ============================================================
// Limpeza
// ============================================================
foreach ($idsAtendimento as $id) {
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare("DELETE FROM tb_vio_cache_crlv WHERE placa LIKE 'RNT%'")->execute();
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
