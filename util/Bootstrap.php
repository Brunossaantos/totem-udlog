<?php

namespace Util;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class Bootstrap
{
    private const MENSAGEM_SANITIZADA = 'Servico temporariamente indisponivel. Tente novamente em instantes.';

    private const VARIAVEIS_OBRIGATORIAS = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    public static function conectar(string $baseDir): PDO
    {
        self::carregarEnv($baseDir);
        self::validarVariaveisObrigatorias();

        try {
            return Conexao::obter();
        } catch (PDOException $e) {
            throw new RuntimeException(self::MENSAGEM_SANITIZADA, 0, $e);
        }
    }

    private static function carregarEnv(string $baseDir): void
    {
        try {
            $dotenv = Dotenv::createImmutable($baseDir);
            $dotenv->load();
        } catch (Throwable $e) {
            error_log('Util\\Bootstrap::carregarEnv: falha ao carregar .env (ausente ou malformado)');
            throw new RuntimeException(self::MENSAGEM_SANITIZADA, 0, $e);
        }
    }

    private static function validarVariaveisObrigatorias(): void
    {
        foreach (self::VARIAVEIS_OBRIGATORIAS as $variavel) {
            $valor = $_ENV[$variavel] ?? null;
            if (!is_string($valor) || trim($valor) === '') {
                error_log("Util\\Bootstrap::validarVariaveisObrigatorias: variavel obrigatoria ausente ou invalida ({$variavel})");
                throw new RuntimeException(self::MENSAGEM_SANITIZADA);
            }
        }
    }
}
