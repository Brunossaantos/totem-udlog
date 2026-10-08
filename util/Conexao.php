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

    /**
     * Conexao PROPRIA (nunca a compartilhada de obter()), criada a cada chamada e
     * so quando alguem pede (lazy). Usada pelo log central do sistema para
     * gravar FORA da transacao do chamador, com timeout curto de conexao e sem
     * retry. O erro de conexao NUNCA carrega getMessage() do driver (pode ter
     * host/usuario/porta): a excecao relancada tem mensagem fixa e sem a anterior.
     */
    public static function criarDedicada(): PDO
    {
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $variavel) {
            if (!isset($_ENV[$variavel]) || !is_string($_ENV[$variavel])) {
                throw new PDOException('Nao foi possivel conectar ao banco de dados');
            }
        }

        try {
            // "@": o driver pode emitir E_WARNING com host/porta (ex.: getaddrinfo)
            // alem de lancar a excecao; o aviso nao deve ir para o log do PHP.
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
