<?php

/**
 * Infra compartilhada das suites legadas portadas ao fluxo QR-only: cria o
 * banco QA descartavel, aponta subprocessos (_caso_*.php) para ele via
 * auto_prepend_file e oferece um runner. Nunca usa DB_NAME do .env.
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_qr_exclusivo_bootstrap.php';

/** @return array{0: PDO, 1: string, 2: string} [pdo QA, nome do banco, diretorio temporario de storage] */
function qaLegadoCriarAmbiente(): array
{
    [$pdo, $banco] = qaQrCriarBanco();
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_legado_storage_' . bin2hex(random_bytes(6));
    if (!mkdir($storage, 0750, true) && !is_dir($storage)) {
        throw new RuntimeException('Nao criou storage temporario QA');
    }
    putenv('QA_QR_FORCE_DB_NAME=' . $banco);
    putenv('QA_QR_FORCE_STORAGE=' . $storage);
    putenv('QA_QR_FORCE_TALENT_ATIVO=false'); // suites mock: finalizar() sempre bloqueia em TALENT_CHECKIN_DESATIVADO, independente do .env local
    $_ENV['STORAGE_PATH'] = $storage; // processo pai (fixtures/DAOs)
    return [$pdo, $banco, $storage];
}

function qaLegadoLimparAmbiente(?string $banco, ?string $storage): void
{
    if ($storage !== null && is_dir($storage) && str_contains($storage, 'qa_legado_storage_')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($storage);
    }
    if ($banco !== null) {
        qaQrDroparBanco($banco);
    }
}

/** Executa um script PHP com o prepend QA (herdado: DB_NAME QA forcado). */
function qaLegadoRodar(string $script, array $args): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/qa_qr_exclusivo_prepend.php') . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string) $a);
    }
    exec($cmd, $saida, $codigo); // stderr (error_log) nao entra na saida analisada
    return ['saida' => implode("\n", $saida), 'codigo' => $codigo];
}

function qaLegadoJpegDataUrl(): string
{
    $im = imagecreatetruecolor(8, 8);
    ob_start();
    imagejpeg($im, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);
    return 'data:image/jpeg;base64,' . base64_encode($bytes);
}

/** Resultado VIO falso completo/aprovavel (mesmo formato de qaResultado da integracao). */
function qaLegadoResultadoVio(string $tipo, array $campos = [], string $placa = 'ABC1234'): array
{
    $dados = $tipo === 'cnh'
        ? ['Nome' => 'QA MOTORISTA', 'CPF' => '52998224725', 'Validade' => '2035-12-31']
        : ['Placa' => $placa, 'Exercício' => 2026, 'UF' => 'SP', 'RNTRC' => 'QA-RNTRC', 'Tipo' => 'CAMINHAO', 'Renavam' => 'QA-RENAVAM'];
    return ['ok' => true, 'estado_leitura' => 'completed', 'qr_type' => 'vio', 'dados_leitura' => array_replace($dados, $campos), 'comparacao' => ['status' => 'pending', 'summary' => ['reliable' => false, 'mismatched' => 99]]];
}
