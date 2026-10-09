<?php

namespace Util;

use PDO;
use PDOException;

class Conexao
{
    private static ?PDO $instancia = null;

    public static function obter(): PDO
    {
        if (self::$instancia === null) {
            $dsn = self::dsn();

            try {
                self::$instancia = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$instancia->exec("SET time_zone = '-03:00'");
            } catch (PDOException $e) {
                error_log('Conexao::obter: falha ao conectar ao banco de dados');
                throw new PDOException('Nao foi possivel conectar ao banco de dados');
            }
        }

        return self::$instancia;
    }

    public static function criarDedicada(): PDO
    {
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $variavel) {
            if (!isset($_ENV[$variavel]) || !is_string($_ENV[$variavel])) {
                throw new PDOException('Nao foi possivel conectar ao banco de dados');
            }
        }

        try {
            $pdo = @new PDO(self::dsn(), $_ENV['DB_USER'], $_ENV['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-03:00', SESSION innodb_lock_wait_timeout = 2",
            ]);
        } catch (\Throwable $e) {
            throw new PDOException('Nao foi possivel conectar ao banco de dados');
        }

        return $pdo;
    }

    private static function dsn(): string
    {
        $host = $_ENV['DB_HOST'];
        $nome = $_ENV['DB_NAME'];
        $porta = $_ENV['DB_PORT'] ?? '3306';

        return "mysql:host={$host};port={$porta};dbname={$nome};charset=utf8mb4";
    }
}
