<?php

/**
 * Helper de teste (prefixo `_`, mesmo padrao de tests/manual/_caso_*.php) —
 * cria um banco `qa_`-prefixado DESCARTAVEL, carrega sql/schema.sql +
 * migrations/014..016 (schema.sql ja consolida 001-013, ver seu cabecalho) e
 * devolve um PDO conectado a esse banco. Usado exclusivamente pela bateria
 * de testes da demanda migracao-vio-api-br-com-cache (2026-09-25) — NUNCA
 * toca em udlog_totem nem em qualquer banco de outra pessoa.
 *
 * Uso:
 *   [$pdo, $nomeBanco] = qaDbCriar('qa_vio_api_br_cache');
 *   try { ... } finally { qaDbDropar($nomeBanco); }
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

if (!function_exists('qaDbConexaoAdmin')) {
    function qaDbConexaoAdmin(): PDO
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->load();

        $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';port=' . ($_ENV['DB_PORT'] ?? '3306') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);

        return $pdo;
    }
}

if (!function_exists('qaDbAplicarArquivoSql')) {
    function qaDbAplicarArquivoSql(PDO $pdo, string $caminho): void
    {
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false) {
            throw new \RuntimeException("Nao foi possivel ler {$caminho}");
        }

        // Cada arquivo deste projeto termina toda instrucao com ";" seguido
        // de nova linha (confirmado por grep antes de escrever este helper)
        // -- nenhuma string interna com ";" embutido. Split simples e seguro.
        $comandos = preg_split('/;\s*\n/', $conteudo);

        foreach ($comandos as $comando) {
            $comando = trim($comando);
            if ($comando === '') {
                continue;
            }
            // query() (nao exec()) -- algumas migrations executam SQL dinamico
            // via PREPARE/EXECUTE que pode devolver um resultset (ex.: quando
            // o SQL montado e o no-op "SELECT 1"); em modo buffered (setado na
            // conexao), query() consome e fecha o cursor imediatamente,
            // evitando o erro "unbuffered queries are active" na instrucao
            // seguinte (DEALLOCATE PREPARE).
            $stmt = $pdo->query($comando);
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
        }
    }
}

if (!function_exists('qaDbCriar')) {
    /**
     * @return array{0: PDO, 1: string} [pdo conectado ao banco qa_ novo, nome do banco]
     */
    function qaDbCriar(string $prefixoDescritivo): array
    {
        $admin = qaDbConexaoAdmin();

        $nomeBanco = 'qa_' . preg_replace('/[^a-z0-9_]/', '', strtolower($prefixoDescritivo)) . '_' . bin2hex(random_bytes(4));
        if (strlen($nomeBanco) > 64) {
            $nomeBanco = substr($nomeBanco, 0, 64);
        }

        // Nome sempre `qa_`-prefixado -- nunca aceita nome de banco vindo de
        // fora sem esse prefixo (defesa contra uso indevido deste helper).
        if (strpos($nomeBanco, 'qa_') !== 0) {
            throw new \RuntimeException('Nome de banco de teste precisa comecar com qa_');
        }

        $admin->exec("CREATE DATABASE `{$nomeBanco}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $admin->exec("USE `{$nomeBanco}`");

        $atual = $admin->query('SELECT DATABASE()')->fetchColumn();
        if ($atual !== $nomeBanco) {
            throw new \RuntimeException("Falha ao trocar para o banco de teste {$nomeBanco} (SELECT DATABASE() retornou {$atual})");
        }

        $raiz = __DIR__ . '/../../';
        qaDbAplicarArquivoSql($admin, $raiz . 'sql/schema.sql');
        qaDbAplicarArquivoSql($admin, $raiz . 'sql/migrations/014_tb_lgpd_aceite.sql');
        qaDbAplicarArquivoSql($admin, $raiz . 'sql/migrations/015_vio_api_br_estados_e_id_externo.sql');
        qaDbAplicarArquivoSql($admin, $raiz . 'sql/migrations/016_vio_api_br_cache.sql');

        return [$admin, $nomeBanco];
    }
}

if (!function_exists('qaDbDropar')) {
    function qaDbDropar(string $nomeBanco): void
    {
        if (strpos($nomeBanco, 'qa_') !== 0) {
            // Nunca dropa nada que nao comece com qa_ -- defesa em profundidade
            // mesmo dentro de um helper exclusivo de teste.
            throw new \RuntimeException('Recusando DROP DATABASE em banco sem prefixo qa_: ' . $nomeBanco);
        }

        $admin = qaDbConexaoAdmin();
        $admin->exec("DROP DATABASE IF EXISTS `{$nomeBanco}`");
    }
}

if (!function_exists('qaDbCorrigirEnumsMigration015')) {
    /**
     * HISTORICO (nao mais necessaria — mantida como no-op documentado):
     * um achado registrado em 2026-09-26 apontava que
     * sql/migrations/015_vio_api_br_estados_e_id_externo.sql tinha a
     * condicao dos 4 blocos "ALTER TABLE ... MODIFY COLUMN ... ENUM(...)"
     * invertida, o que teria feito a migration nunca acrescentar de fato
     * ENVIANDO/PROCESSANDO_LEITURA/PROCESSANDO_COMPARACAO/INDETERMINADO nem
     * VIO_CACHE/VIO_API_BR aos ENUMs quando aplicada num banco novo. O bug
     * real foi corrigido no proprio arquivo .sql na mesma rodada, e a
     * correcao foi reconfirmada por 2 execucoes independentes na
     * `/03-revisao` seguinte, aplicando a migration 015 num banco `qa_`
     * novo/descartavel SEM nenhum workaround e validando que os ENUMs saem
     * corretos (INDETERMINADO/VIO_CACHE/VIO_API_BR presentes) e que
     * reaplicar a migration depois e um no-op seguro (idempotente).
     *
     * Esta funcao ficou como no-op (nao executa mais nenhum ALTER TABLE)
     * apenas para nao quebrar nenhuma chamada explicita remanescente —
     * ver tambem o cabecalho de
     * tests/manual/teste_vio_api_br_cas_e_cache.php, que documenta a mesma
     * historia com mais detalhe.
     */
    function qaDbCorrigirEnumsMigration015(PDO $pdo): void
    {
        // Intencionalmente vazio — migration 015 ja aplica os ENUMs
        // corretos sozinha, sem necessidade de correcao manual.
    }
}

if (!function_exists('qaGerarScriptTemporario')) {
    /**
     * Materializa um corpo de script PHP (nowdoc, com o placeholder %%RAIZ%%
     * substituido pela raiz do projeto em forma de literal PHP seguro via
     * var_export()) num arquivo temporario, fora de tests/manual/. Retorna o
     * caminho absoluto do arquivo gerado. Mesmo padrao ja usado por
     * tests/manual/teste_lock_obter_lock_documento.php e
     * tests/manual/teste_sanitizacao_logs_documento_nota.php (funcao local
     * gerarScriptTemporario() nesses arquivos) -- extraida aqui como helper
     * compartilhado para as demais suites que tambem precisam gerar
     * subprocessos sem depender de arquivos tests/manual/_*.php gitignorados.
     */
    function qaGerarScriptTemporario(string $corpo, string $raizProjeto, string $prefixo): string
    {
        $raizPhp = var_export($raizProjeto, true);
        $conteudo = "<?php\n"
            . "require_once {$raizPhp} . '/vendor/autoload.php';\n\n"
            . str_replace('%%RAIZ%%', $raizPhp, $corpo);
        $arquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totem_qa_' . $prefixo . '_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($arquivo, $conteudo);
        return $arquivo;
    }
}

if (!function_exists('qaDbTrechoPonteEnvSubprocesso')) {
    /**
     * Trecho de codigo PHP (string, para ser interpolado no corpo de
     * subprocessos gerados/existentes) que faz a ponte de variaveis de
     * ambiente herdadas via putenv() do processo pai para $_ENV, ANTES de
     * Dotenv::createImmutable()->load() rodar no subprocesso.
     *
     * Confirmado por teste isolado (nao versionado, rodada corretiva de
     * 2026-09-26): quando o processo pai so usa putenv() (sem tambem setar
     * $_ENV, que nao se propaga entre processos), o subprocesso consegue ler
     * o valor via getenv() normalmente, mas Dotenv::createImmutable()->load()
     * em modo imutavel NUNCA promove sozinho um valor presente so via
     * getenv()/ambiente do SO para dentro de $_ENV -- ele so pula a chave
     * (por já considerá-la "definida"), deixando $_ENV[$chave] UNSET. Como
     * Util\Conexao e App\Rn\VioApiBrClient leem $_ENV diretamente (nunca
     * getenv()), sem esta ponte explicita o subprocesso conectaria sempre no
     * banco real do .env (nunca no banco `qa_` descartavel do processo pai).
     */
    function qaDbTrechoPonteEnvSubprocesso(): string
    {
        return <<<'PONTE'
foreach (getenv() as $qaChaveHerdada => $qaValorHerdado) {
    if (!array_key_exists($qaChaveHerdada, $_ENV)) {
        $_ENV[$qaChaveHerdada] = $qaValorHerdado;
    }
}
PONTE;
    }
}
