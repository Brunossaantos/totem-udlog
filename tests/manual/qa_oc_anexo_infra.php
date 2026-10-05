<?php

/**
 * Infra dos testes do anexo da Ordem de Coleta (demanda anexo-ordem-coleta-n8n,
 * 2026-10-05): banco QA descartavel `qa_qr_exclusivo_<hex>` (mesma infra de
 * qa_qr_exclusivo_bootstrap.php) que tambem faz o papel do banco EXTERNO de
 * gestao de coletas: tb_clientes + tb_ordens_coleta minimas (sem FKs de e-mail/
 * motorista) + as migrations REAIS 001, 002 e 003. Storage temporario.
 * NUNCA toca em udlog_totem nem no banco externo real; sem rede.
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_qr_exclusivo_bootstrap.php';

/** Aplica um arquivo SQL ignorando linhas de comentario (migrations externas). */
function ocQaAplicarMigration(PDO $pdo, string $arquivo): void
{
    $sql = file_get_contents($arquivo);
    if ($sql === false) {
        throw new RuntimeException('Migration ausente');
    }
    $linhas = array_filter(explode("\n", $sql), static fn ($l) => preg_match('/^\s*--/', $l) !== 1);
    foreach (preg_split('/;\s*\R/', implode("\n", $linhas)) as $comando) {
        $comando = rtrim(trim($comando), ';');
        if ($comando === '') {
            continue;
        }
        $stmt = $pdo->query($comando);
        if ($stmt !== false) {
            $stmt->closeCursor();
        }
    }
}

/** @return array{0: PDO, 1: string, 2: string} [pdo QA, banco, storage temporario] */
function ocQaCriarAmbiente(): array
{
    [$pdo, $banco] = qaQrCriarBanco();
    qaQrValidarNomeBanco($banco);

    $pdo->exec('CREATE TABLE tb_clientes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        razao_social VARCHAR(200) NOT NULL,
        cnpj VARCHAR(14) NOT NULL,
        status ENUM(\'ATIVO\',\'INATIVO\') NOT NULL DEFAULT \'ATIVO\'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE tb_ordens_coleta (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        numero_ordem_coleta VARCHAR(50) NOT NULL,
        cliente_id BIGINT UNSIGNED NOT NULL,
        placa_prevista VARCHAR(10) DEFAULT NULL,
        cnh_prevista VARCHAR(20) DEFAULT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ordem_cliente (cliente_id, numero_ordem_coleta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $raiz = dirname(__DIR__, 2) . '/sql/migrations_gestao_coletas/';
    foreach (['001_status_ordem_coleta.sql', '002_tb_ordem_coleta_arquivos.sql', '003_inativada_em_ordem_coleta.sql'] as $m) {
        ocQaAplicarMigration($pdo, $raiz . $m);
    }

    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_oc_storage_' . bin2hex(random_bytes(6));
    if (!mkdir($storage, 0750, true) && !is_dir($storage)) {
        throw new RuntimeException('Nao criou storage temporario QA');
    }

    // processo pai: a conexao do banco externo (ConexaoGestaoColetas) aponta
    // SOMENTE para o banco QA; credenciais vem da mesma configuracao do QA.
    $cfg = qaQrConfiguracao();
    $_ENV['DB_HOST'] = $cfg['host'];
    $_ENV['DB_PORT'] = $cfg['port'];
    $_ENV['DB_USER'] = $cfg['user'];
    $_ENV['DB_PASS'] = $cfg['pass'];
    $_ENV['DB_NAME'] = $banco;
    $_ENV['GESTAO_COLETAS_DB_NAME'] = $banco;
    $_ENV['STORAGE_PATH'] = $storage;

    // subprocessos (CLI/CGI)
    putenv('QA_QR_FORCE_DB_NAME=' . $banco);
    putenv('QA_QR_FORCE_STORAGE=' . $storage);
    putenv('QA_QR_DB_HOST=' . $cfg['host']);
    putenv('QA_QR_DB_PORT=' . $cfg['port']);
    putenv('QA_QR_DB_USER=' . $cfg['user']);
    putenv('QA_QR_DB_PASS=' . $cfg['pass']);

    return [$pdo, $banco, $storage];
}

function ocQaLimpar(?string $banco, ?string $storage): void
{
    if ($storage !== null && is_dir($storage) && str_contains($storage, 'qa_oc_storage_')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isLink() || $f->isFile()) {
                @unlink($f->getPathname());
                if ($f->isLink() && is_dir($f->getPathname())) {
                    @rmdir($f->getPathname());
                }
            } else {
                @rmdir($f->getPathname());
            }
        }
        @rmdir($storage);
    }
    if ($banco !== null) {
        qaQrDroparBanco($banco);
    }
}

/** PDF minimo valido o bastante para o servidor (comeca com %PDF-). */
function ocQaPdf(int $tamanho = 600, string $marca = ''): string
{
    $base = "%PDF-1.4\n% QA {$marca}\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    if ($tamanho > strlen($base)) {
        $base .= str_repeat('A', $tamanho - strlen($base));
    }

    return $base;
}

function ocQaCliente(PDO $pdo, string $cnpj, string $status = 'ATIVO'): int
{
    $pdo->prepare('INSERT INTO tb_clientes (razao_social, cnpj, status) VALUES (:r, :c, :s)')
        ->execute(['r' => 'CLIENTE QA ' . $cnpj, 'c' => $cnpj, 's' => $status]);

    return (int) $pdo->lastInsertId();
}

function ocQaOrdem(PDO $pdo, int $clienteId, string $numero, string $status = 'ATIVA', ?string $inativadaEm = null): int
{
    $pdo->prepare('INSERT INTO tb_ordens_coleta (numero_ordem_coleta, cliente_id, status, inativada_em) VALUES (:n, :c, :s, :i)')
        ->execute(['n' => $numero, 'c' => $clienteId, 's' => $status, 'i' => $inativadaEm]);

    return (int) $pdo->lastInsertId();
}

/** Lista recursivamente os arquivos regulares (relativos) sob um diretorio. */
function ocQaArquivos(string $dir): array
{
    $saida = [];
    if (!is_dir($dir)) {
        return $saida;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $saida[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        }
    }
    sort($saida);

    return $saida;
}
