<?php

namespace Util;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Fronteira segura de bootstrap dos entrypoints publicos (HTTP em
 * public/api/*.php, public/totem/index.php, e CLI em cron/*.php).
 *
 * Cobre, num unico lugar, TODAS as falhas possiveis antes de qualquer
 * autenticacao/controller/OCR/regra de negocio:
 *   - .env ausente (Dotenv\Exception\InvalidPathException);
 *   - .env malformado (Dotenv\Exception\InvalidFileException/ValidationException);
 *   - variavel obrigatoria ausente ou vazia (DB_HOST/DB_NAME/DB_USER/DB_PASS);
 *   - falha de conexao com o banco (PDOException, ver Util\Conexao).
 *
 * Qualquer uma dessas falhas lanca \RuntimeException com mensagem fixa e
 * sanitizada -- nunca a mensagem original do Dotenv/PDO, nunca stack trace,
 * nunca caminho absoluto do servidor, nunca usuario/host/banco/credencial.
 * Cada chamador decide so o FORMATO da resposta (JSON via Util\Resposta::erro
 * ou HTML via http_response_code+die para public/totem/index.php, ou
 * error_log+exit(1) para cron/*.php) -- a fronteira de seguranca em si fica
 * concentrada aqui, evitando duplicar a mesma logica de risco em 7+ lugares.
 */
class Bootstrap
{
    private const MENSAGEM_SANITIZADA = 'Servico temporariamente indisponivel. Tente novamente em instantes.';

    private const VARIAVEIS_OBRIGATORIAS = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    /**
     * Carrega o .env a partir de $baseDir, valida as variaveis obrigatorias
     * de conexao e retorna a conexao PDO ja aberta. Lanca \RuntimeException
     * (mensagem sanitizada) em qualquer falha nessas 3 etapas.
     */
    public static function conectar(string $baseDir): PDO
    {
        self::carregarEnv($baseDir);
        self::validarVariaveisObrigatorias();

        try {
            return Conexao::obter();
        } catch (PDOException $e) {
            // Conexao::obter() ja loga de forma sanitizada e lanca mensagem
            // generica -- so padroniza o tipo capturado por quem chama.
            throw new RuntimeException(self::MENSAGEM_SANITIZADA, 0, $e);
        }
    }

    private static function carregarEnv(string $baseDir): void
    {
        try {
            $dotenv = Dotenv::createImmutable($baseDir);
            $dotenv->load();
        } catch (Throwable $e) {
            // Dotenv\Exception\InvalidPathException (.env ausente) e
            // InvalidFileException/ValidationException (.env malformado) nao
            // sao PDOException -- captura ampla e proposital aqui, pois esta
            // e a ultima linha de defesa antes de qualquer codigo da
            // aplicacao rodar. getMessage() do Dotenv NUNCA e usado (pode
            // conter caminho absoluto do arquivo .env).
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
