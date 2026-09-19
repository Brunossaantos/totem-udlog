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
            $host = $_ENV['DB_HOST'];
            $nome = $_ENV['DB_NAME'];
            $porta = $_ENV['DB_PORT'] ?? '3306';

            $dsn = "mysql:host={$host};port={$porta};dbname={$nome};charset=utf8mb4";

            try {
                self::$instancia = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                // evita divergencia de timezone entre PHP e MariaDB (mesmo padrao do UDFlow)
                self::$instancia->exec("SET time_zone = '-03:00'");
            } catch (PDOException $e) {
                // Log fixo e sanitizado -- nunca usar $e->getMessage() aqui: o driver PDO
                // pode incluir host/usuario/porta/SQLSTATE do MySQL na mensagem bruta da
                // excecao de conexao (diferente de falha de query, que ja e sanitizada em
                // todo o resto do projeto). Sem getMessage(), sem trace, sem file/line.
                error_log('Conexao::obter: falha ao conectar ao banco de dados');
                throw new PDOException('Nao foi possivel conectar ao banco de dados');
            }
        }

        return self::$instancia;
    }
}
