<?php

namespace Util;

use PDO;
use PDOException;

/**
 * Conexao PDO dedicada e SEPARADA para o banco externo de gestao de coletas
 * (`udlogo59_db_gestao_coletas`), usado para consultar `tb_ordens_coleta`/
 * `tb_clientes` diretamente (demanda expedicao-consulta-ordem-coleta-teste,
 * 2026-09-11 — nunca existiu API REST real documentada para essa consulta,
 * uso direto ao banco autorizado explicitamente pelo usuario).
 *
 * NUNCA reaproveita Util\Conexao (conexao do banco do totem) — instancia
 * PDO propria, mesmo reaproveitando DB_HOST/DB_PORT/DB_USER/DB_PASS do
 * .env (mesmo servidor/credencial), so o nome do banco muda
 * (GESTAO_COLETAS_DB_NAME). Timeout de conexao curto e explicito
 * (PDO::ATTR_TIMEOUT), diferente da conexao do totem, para nao travar o
 * totem inteiro se o banco externo ficar indisponivel.
 *
 * Erros de conexao NUNCA vazam DSN/host/credencial para o chamador — so um
 * log tecnico via error_log() e uma excecao generica.
 */
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
                error_log('ConexaoGestaoColetas: falha na conexao: ' . $e->getMessage());
                throw new \RuntimeException('Nao foi possivel conectar ao banco de ordens de coleta');
            }
        }

        return self::$instancia;
    }
}
