<?php

/**
 * Contrato isolado da demanda fluxo-qr-exclusivo-cnh-crlv.
 *
 * Nao importa .env, nao abre PDO, nao usa storage e nao faz HTTP. Os doubles
 * abaixo fixam o contrato que a implementacao de producao devera cumprir:
 * JPEG QR-only -> uma unica submissao VIO em cache desativado; nenhum anexo
 * CNH/CRLV para Talent; CAS/cancelamento/resultado tardio fail-closed.
 *
 * Uso: php tests/manual/teste_contrato_qr_exclusivo_isolado.php
 */

declare(strict_types=1);

$verificacoes = 0;
$falhas = 0;
function qrAfirmar(string $descricao, bool $condicao): void
{
    global $verificacoes, $falhas;
    $verificacoes++;
    if (!$condicao) {
        $falhas++;
    }
    echo ($condicao ? 'OK   ' : 'FALHA') . " - {$descricao}\n";
}

final class QrOnlyVioMock
{
    /** @var list<array{tipo:string, jpeg:string, comparar:bool}> */
    public array $posts = [];
    /** @var list<array<string, mixed>> */
    public array $resultados;

    /** @param list<array<string, mixed>> $resultados */
    public function __construct(array $resultados)
    {
        $this->resultados = $resultados;
    }

    /** @return array<string, mixed> */
    public function post(string $tipo, string $jpeg, bool $comparar): array
    {
        $this->posts[] = compact('tipo', 'jpeg', 'comparar');
        return array_shift($this->resultados) ?? ['status' => 'failed'];
    }
}

final class QrOnlyFluxoMock
{
    /** @var array<string, int> */
    private array $tentativas = ['cnh' => 0, 'crlv' => 0];
    /** @var array<string, int> */
    private array $tentativaAtiva = ['cnh' => 0, 'crlv' => 0];
    /** @var array<string, bool> */
    private array $cancelado = ['cnh' => false, 'crlv' => false];
    public int $consultasCache = 0;
    public int $gravacoesCache = 0;
    public int $gravacoesStorage = 0;

    public function __construct(private QrOnlyVioMock $vio)
    {
    }

    /** @return array{acao:string, tentativa:int} */
    public function qrAusente(string $tipo): array
    {
        $this->tentativas[$tipo]++;
        return $this->tentativas[$tipo] >= 3
            ? ['acao' => 'manual', 'tentativa' => $this->tentativas[$tipo]]
            : ['acao' => 'tentar_novamente', 'tentativa' => $this->tentativas[$tipo]];
    }

    /** @return array<string, mixed> */
    public function qrValido(string $tipo, string $jpeg): array
    {
        if (substr($jpeg, 0, 2) !== "\xFF\xD8" || substr($jpeg, -2) !== "\xFF\xD9") {
            return ['estado' => 'REJEITADO', 'motivo' => 'jpeg_invalido'];
        }

        // Deliberadamente sem consultar/gravar VIO_CACHE e sem persistir JPEG.
        $this->tentativas[$tipo] = 0;
        $this->cancelado[$tipo] = false;
        $tentativa = ++$this->tentativaAtiva[$tipo];
        $resultado = $this->vio->post($tipo, $jpeg, true); // uma unica chamada

        if (($resultado['ambigua'] ?? false) === true || ($resultado['timeout'] ?? false) === true) {
            return ['estado' => 'INDETERMINADO', 'tentativa' => $tentativa];
        }
        if (($resultado['status'] ?? '') !== 'completed') {
            return ['estado' => 'REJEITADO', 'tentativa' => $tentativa];
        }
        if (($resultado['tipo'] ?? '') !== $tipo || !($resultado['campos_validos'] ?? false)) {
            return ['estado' => 'REJEITADO', 'tentativa' => $tentativa];
        }

        // compare e somente diagnostico: nao participa desta decisao.
        return ['estado' => 'APROVADO', 'tentativa' => $tentativa, 'compare' => $resultado['compare'] ?? null];
    }

    public function cancelar(string $tipo): void
    {
        $this->cancelado[$tipo] = true;
        ++$this->tentativaAtiva[$tipo];
    }

    public function resultadoTardio(string $tipo, int $tentativa): bool
    {
        return !$this->cancelado[$tipo] && $this->tentativaAtiva[$tipo] === $tentativa;
    }

    public function resetarAtendimento(): void
    {
        $this->tentativas = ['cnh' => 0, 'crlv' => 0];
        $this->cancelado = ['cnh' => false, 'crlv' => false];
    }
}

/** @param list<array{descricao:string, anexoBase64:string}> $anexosNotas */
function qrOnlyMontarPayloadTalent(array $doctos, array $anexosNotas): array
{
    return ['doctos' => $doctos, 'anexos' => $anexosNotas];
}

$jpegSintetico = "\xFF\xD8" . str_repeat('QR-ONLY-SINTETICO', 8) . "\xFF\xD9";
$vio = new QrOnlyVioMock([
    ['status' => 'completed', 'tipo' => 'cnh', 'campos_validos' => true, 'compare' => ['status' => 'mismatch']],
    ['status' => 'completed', 'tipo' => 'crlv', 'campos_validos' => true, 'compare' => ['status' => 'pending']],
    ['status' => 'completed', 'tipo' => 'crlv', 'campos_validos' => false],
    ['timeout' => true],
]);
$fluxo = new QrOnlyFluxoMock($vio);

// QR local: nenhuma leitura implica zero backend/VIO; a terceira libera manual.
qrAfirmar('CNH sem QR: primeira falha pede nova captura', $fluxo->qrAusente('cnh') === ['acao' => 'tentar_novamente', 'tentativa' => 1]);
qrAfirmar('CNH sem QR: segunda falha pede nova captura', $fluxo->qrAusente('cnh') === ['acao' => 'tentar_novamente', 'tentativa' => 2]);
qrAfirmar('CNH sem QR: terceira falha abre manual', $fluxo->qrAusente('cnh') === ['acao' => 'manual', 'tentativa' => 3]);
qrAfirmar('Sem QR nao houve POST VIO', count($vio->posts) === 0);
qrAfirmar('CRLV possui contador independente', $fluxo->qrAusente('crlv') === ['acao' => 'tentar_novamente', 'tentativa' => 1]);

// JPEG valido: sempre um POST, nunca cache; comparacao nao bloqueia.
$cnh = $fluxo->qrValido('cnh', $jpegSintetico);
qrAfirmar('CNH valida aprova mesmo com compare mismatch', $cnh['estado'] === 'APROVADO');
qrAfirmar('CNH valida dispara exatamente um POST', count($vio->posts) === 1);
qrAfirmar('POST CNH mantem comparar=true', $vio->posts[0]['comparar'] === true);
qrAfirmar('POST recebe somente bytes JPEG, sem binaryData', array_keys($vio->posts[0]) === ['tipo', 'jpeg', 'comparar']);
qrAfirmar('Fluxo QR-only nunca consulta cache', $fluxo->consultasCache === 0);
qrAfirmar('Fluxo QR-only nunca grava cache', $fluxo->gravacoesCache === 0);
qrAfirmar('Fluxo QR-only nunca grava storage', $fluxo->gravacoesStorage === 0);

$crlv = $fluxo->qrValido('crlv', $jpegSintetico);
qrAfirmar('CRLV valida aprova mesmo com compare pending', $crlv['estado'] === 'APROVADO');
qrAfirmar('CRLV valida adiciona um unico POST proprio', count($vio->posts) === 2);
$invalido = $fluxo->qrValido('crlv', $jpegSintetico);
qrAfirmar('Campos obrigatorios ausentes continuam fail-closed', $invalido['estado'] === 'REJEITADO');
$timeout = $fluxo->qrValido('cnh', $jpegSintetico);
qrAfirmar('Timeout/ambiguidade resulta em INDETERMINADO', $timeout['estado'] === 'INDETERMINADO');
qrAfirmar('Cada JPEG valido gerou somente um POST, sem retry', count($vio->posts) === 4);

$fluxo->cancelar('cnh');
qrAfirmar('Resultado tardio apos cancelamento e descartado por tentativa/CAS', !$fluxo->resultadoTardio('cnh', (int) $cnh['tentativa']));
$fluxo->resetarAtendimento();
qrAfirmar('Troca/cancelamento de atendimento reinicia contador CNH', $fluxo->qrAusente('cnh') === ['acao' => 'tentar_novamente', 'tentativa' => 1]);

$talent = qrOnlyMontarPayloadTalent(
    [['tipo' => 'NOTA_FISCAL', 'nrDocto' => 'NF-SINTETICA-1']],
    [['descricao' => 'NOTA_FISCAL', 'anexoBase64' => 'fixture-sintetica']]
);
qrAfirmar('Talent preserva doctos[]', count($talent['doctos']) === 1 && $talent['doctos'][0]['tipo'] === 'NOTA_FISCAL');
qrAfirmar('Talent preserva anexos de notas', count($talent['anexos']) === 1 && $talent['anexos'][0]['descricao'] === 'NOTA_FISCAL');
$descricoes = array_column($talent['anexos'], 'descricao');
qrAfirmar('Talent nao envia anexo CNH', !in_array('CNH', $descricoes, true));
qrAfirmar('Talent nao envia anexo CRLV', !in_array('CRLV', $descricoes, true));

echo "\nVerificacoes: {$verificacoes}; Falhas: {$falhas}\n";
exit($falhas === 0 ? 0 : 1);
