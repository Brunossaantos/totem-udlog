<?php

/** Regressao QR-only de CNH e CRLV; nome historico preservado. */
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;

$total = 0;
$falhas = 0;
function qrAfirmarRn(string $descricao, bool $condicao): void
{
    global $total, $falhas;
    ++$total;
    if (!$condicao) {
        ++$falhas;
    }
    echo ($condicao ? 'OK   ' : 'FALHA') . " - {$descricao}\n";
}

final class QrOnlyAtendimentoDaoFake extends AtendimentoDao
{
    public int $cnhGravacoes = 0;
    public int $crlvGravacoes = 0;
    public function __construct() {}
    public function executarEmTransacao(callable $operacao): mixed { return $operacao(); }
    public function atualizarValidacaoCnhVioApiBr(int $id, string $tentativaId, string $nome, string $cpf, string $dataValidade): bool { ++$this->cnhGravacoes; return $tentativaId === 'tentativa-cnh'; }
    public function atualizarValidacaoCrlvVioApiBr(int $id, string $tentativaId, string $placa, int $exercicio, string $uf, string $rntc, string $tipoVeiculo): bool { ++$this->crlvGravacoes; return $tentativaId === 'tentativa-crlv'; }
}

$dao = new QrOnlyAtendimentoDaoFake();
$cacheIgnorado = (new ReflectionClass(VioCacheDao::class))->newInstanceWithoutConstructor();
$rn = new DocumentoRn($cacheIgnorado, $dao, null);
$atendimentoCnh = ['id_atendimento' => 101, 'placa' => 'ABC1D23', 'cnh_tentativa_id' => 'tentativa-cnh', 'cnh_origem_validacao' => 'NAO_VALIDADO', 'cnh_status_revisao' => 'OK'];
$atendimentoCrlv = ['id_atendimento' => 102, 'placa' => 'ABC1D23', 'crlv_tentativa_id' => 'tentativa-crlv', 'crlv_origem_validacao' => 'NAO_VALIDADO', 'crlv_status_revisao' => 'OK'];
$cnhDados = ['Nome' => 'NOME SINTETICO', 'CPF' => '529.982.247-25', 'Validade' => '31/12/2035'];
$crlvDados = ['Placa' => 'ABC1D23', 'Renavam' => '12345678901', 'Exercício' => 2030, 'UF' => 'SP', 'RNTRC' => '12345678', 'Tipo' => 'CAMINHAO'];
function qrResultado(array $dados, array $compare = []): array { return ['estado_leitura' => 'completed', 'qr_type' => 'vio', 'dados_leitura' => $dados, 'comparacao' => $compare]; }

foreach ([
    ['mismatch', ['status' => 'completed', 'summary' => ['reliable' => true, 'mismatched' => 1]]],
    ['not_found', ['status' => 'completed', 'summary' => ['reliable' => false, 'mismatched' => 0], 'campos' => ['nome' => 'not_found']]],
    ['pending', ['status' => 'pending', 'summary' => ['reliable' => false, 'mismatched' => 0]]],
    ['ausente', []],
] as [$nome, $compare]) {
    $resultado = $rn->avaliarResultadoVioApiBrCnh($atendimentoCnh, qrResultado($cnhDados, $compare));
    qrAfirmarRn("CNH valida aprova com compare {$nome} diagnostico", $resultado['pode_avancar'] === true);
}
qrAfirmarRn('CNH valida persistiu somente via CAS falso vigente', $dao->cnhGravacoes === 4);
$crlv = $rn->avaliarResultadoVioApiBrCrlv($atendimentoCrlv, qrResultado($crlvDados, ['status' => 'failed']));
qrAfirmarRn('CRLV valida aprova mesmo com compare failed', $crlv['pode_avancar'] === true);
qrAfirmarRn('CRLV valida persiste somente via CAS falso vigente', $dao->crlvGravacoes === 1);

$cnhSemCampo = $cnhDados; unset($cnhSemCampo['CPF']);
qrAfirmarRn('CNH com campo obrigatorio ausente permanece fail-closed', $rn->avaliarResultadoVioApiBrCnh($atendimentoCnh, qrResultado($cnhSemCampo))['pode_avancar'] === false);
$crlvSemCampo = $crlvDados; unset($crlvSemCampo['RNTRC']);
qrAfirmarRn('CRLV com RNTRC ausente aprova (decisao 2026-10-02)', $rn->avaliarResultadoVioApiBrCrlv($atendimentoCrlv, qrResultado($crlvSemCampo))['pode_avancar'] === true);
$crlvSemTipo = $crlvDados; unset($crlvSemTipo['Tipo']);
qrAfirmarRn('CRLV sem Tipo aprova (decisao 2026-10-02)', $rn->avaliarResultadoVioApiBrCrlv($atendimentoCrlv, qrResultado($crlvSemTipo))['pode_avancar'] === true);
$crlvSemExercicio = $crlvDados; unset($crlvSemExercicio['Exercício']);
qrAfirmarRn('CRLV com Exercicio ausente permanece fail-closed', $rn->avaliarResultadoVioApiBrCrlv($atendimentoCrlv, qrResultado($crlvSemExercicio))['pode_avancar'] === false);
$crlvTipoPlaceholder = $crlvDados; $crlvTipoPlaceholder['Tipo'] = 'xxxxx';
qrAfirmarRn('CRLV com placeholder em Tipo reprova', $rn->avaliarResultadoVioApiBrCrlv($atendimentoCrlv, qrResultado($crlvTipoPlaceholder))['pode_avancar'] === false);
qrAfirmarRn('CNH rejeita tipo QR normal', $rn->avaliarResultadoVioApiBrCnh($atendimentoCnh, array_replace(qrResultado($cnhDados), ['qr_type' => 'normal']))['pode_avancar'] === false);
qrAfirmarRn('CRLV rejeita leitura principal failed', $rn->avaliarResultadoVioApiBrCrlv($atendimentoCrlv, array_replace(qrResultado($crlvDados), ['estado_leitura' => 'failed']))['pode_avancar'] === false);
qrAfirmarRn('CNH nao aceita campos de CRLV como resultado CNH', $rn->avaliarResultadoVioApiBrCnh($atendimentoCnh, qrResultado($crlvDados))['pode_avancar'] === false);
qrAfirmarRn('CRLV nao aceita campos de CNH como resultado CRLV', $rn->avaliarResultadoVioApiBrCrlv($atendimentoCrlv, qrResultado($cnhDados))['pode_avancar'] === false);

$fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Rn/DocumentoRn.php');
$inicioAvaliadores = strpos($fonte, 'public function avaliarResultadoVioApiBrCnh');
$fimAvaliadores = strpos($fonte, 'private function respostaVioApiBrAprovavel', $inicioAvaliadores);
$avaliadores = substr($fonte, $inicioAvaliadores, $fimAvaliadores - $inicioAvaliadores);
qrAfirmarRn('avaliadores QR-only nao gravam VIO_CACHE', $fimAvaliadores !== false && !str_contains($avaliadores, 'gravarCacheVioApiBr'));
qrAfirmarRn('DocumentoRn nao contem mais codigo de cache/fingerprint', !str_contains($fonte, 'VioApiBrCacheDao') && !str_contains($fonte, 'tentarCache') && !str_contains($fonte, 'calcularFingerprintVioApiBr') && !str_contains($fonte, 'gravarCacheVioApiBr'));

echo "\nVerificacoes: {$total}; Falhas: {$falhas}\n";
exit($falhas === 0 ? 0 : 1);
