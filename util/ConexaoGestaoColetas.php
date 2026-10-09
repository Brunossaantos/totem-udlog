<?php

namespace Util;

use PDO;
use PDOException;

class ConexaoGestaoColetas
{
    private static ?PDO $instancia = null;

    private const TIMEOUT_SEGUNDOS = 4;

    public static function obter(): PDO
    {
        if (self::$instancia === null) {
            $host = $_ENV['DB_HOST'] ?? '';
            $nome = $_ENV['GESTAO_COLETAS_DB_NAME'] ?? '';
            $porta = $_ENV['DB_PORT'] ?? '3306';

            if ($host === '' || $nome === '') {
                error_log('ConexaoGestaoColetas: DB_HOST ou GESTAO_COLETAS_DB_NAME ausente no .env');
                LogSistema::registrar('banco_coletas_indisponivel');
                throw new \RuntimeException('Nao foi possivel conectar ao banco de ordens de coleta');
            }

            $dsn = "mysql:host={$host};port={$porta};dbname={$nome};charset=utf8mb4";

            try {
                self::$instancia = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => self::TIMEOUT_SEGUNDOS,
                ]);
                self::$instancia->exec("SET time_zone = '-03:00'");
            } catch (PDOException $e) {
                error_log('ConexaoGestaoColetas: falha na conexao com o banco de ordens de coleta');
                LogSistema::registrar('banco_coletas_indisponivel', ['excecao' => $e]);
                throw new \RuntimeException('Nao foi possivel conectar ao banco de ordens de coleta');
            }
        }

        return self::$instancia;
    }
}
