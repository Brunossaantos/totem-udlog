<?php

/** Banco descartavel exclusivo da bateria QR-only; nunca usa DB_NAME. */
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

/** @return array{host:string,port:string,user:string,pass:string} */
function qaQrConfiguracao(): array
{
    $nomes = ['host' => 'QA_QR_DB_HOST', 'port' => 'QA_QR_DB_PORT', 'user' => 'QA_QR_DB_USER', 'pass' => 'QA_QR_DB_PASS'];
    $config = []; $presentes = 0;
    foreach ($nomes as $chave => $nome) {
        $valor = getenv($nome);
        if ($valor !== false) { ++$presentes; $config[$chave] = $valor; }
    }
    if ($presentes !== 0 && $presentes !== count($nomes)) throw new RuntimeException('Configuracao QA parcial');
    if ($presentes === count($nomes) && $config['host'] !== '' && $config['port'] !== '' && $config['user'] !== '') return $config;

    // Credenciais locais sao usadas somente para abrir a conexao administrativa
    // e criar um banco QA com nome validado. DB_NAME nunca e lido nem usado.
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->load();
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS'] as $nome) {
        if (!array_key_exists($nome, $_ENV)) throw new RuntimeException('Credencial local QA indisponivel');
    }
    return [
        'host' => (string) $_ENV['DB_HOST'],
        'port' => (string) ($_ENV['DB_PORT'] ?? '3306'),
        'user' => (string) $_ENV['DB_USER'],
        'pass' => (string) $_ENV['DB_PASS'],
    ];
}

/** @param array{host:string,port:string,user:string,pass:string} $config */
function qaQrPdoServidor(array $config): PDO
{
    return new PDO("mysql:host={$config['host']};port={$config['port']};charset=utf8mb4", $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
}

function qaQrNomeBanco(): string { return 'qa_qr_exclusivo_' . bin2hex(random_bytes(4)); }
function qaQrValidarNomeBanco(string $nome): void { if (preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $nome) !== 1) throw new RuntimeException('Nome de banco QA invalido'); }

function qaQrAplicarSql(PDO $pdo, string $caminho): void
{
    $sql = file_get_contents($caminho);
    if ($sql === false) throw new RuntimeException('Arquivo SQL QA ausente');
    foreach (preg_split('/;\s*\R/', $sql) as $comando) {
        $comando = trim($comando);
        if ($comando === '') continue;
        $stmt = $pdo->query($comando);
        if ($stmt !== false) $stmt->closeCursor();
    }
}

/** @return array{0: PDO, 1: string} */
function qaQrCriarBanco(): array
{
    $pdo = qaQrPdoServidor(qaQrConfiguracao());
    $nome = qaQrNomeBanco(); qaQrValidarNomeBanco($nome);
    $pdo->exec("CREATE DATABASE `{$nome}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$nome}`");
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $nome) throw new RuntimeException('Sentinela do banco QA falhou');
    $raiz = dirname(__DIR__, 2);
    foreach (['sql/schema.sql', 'sql/migrations/014_tb_lgpd_aceite.sql', 'sql/migrations/015_vio_api_br_estados_e_id_externo.sql', 'sql/migrations/016_vio_api_br_cache.sql', 'sql/migrations/017_cnh_modo_captura.sql', 'sql/migrations/018_nota_client_uid.sql', 'sql/migrations/019_drop_tb_fila_envio.sql', 'sql/migrations/020_gestao_usuario_sessao.sql', 'sql/migrations/021_gestao_auditoria.sql'] as $arquivo) qaQrAplicarSql($pdo, $raiz . '/' . $arquivo);
    return [$pdo, $nome];
}

function qaQrAbrirBanco(string $nome): PDO
{
    qaQrValidarNomeBanco($nome); $c = qaQrConfiguracao();
    $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$nome};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $nome) throw new RuntimeException('Sentinela do banco QA falhou');
    return $pdo;
}

function qaQrDroparBanco(string $nome): void { qaQrValidarNomeBanco($nome); qaQrPdoServidor(qaQrConfiguracao())->exec("DROP DATABASE IF EXISTS `{$nome}`"); }
