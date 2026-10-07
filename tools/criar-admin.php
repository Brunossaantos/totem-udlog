<?php
/**
 * Cria o PRIMEIRO administrador da Gestao Totem (demanda gestao-totem, F1).
 * Nao existe rota web de cadastro inicial: este e o unico caminho.
 *
 * Somente linha de comando, em terminal interativo:
 *   php tools/criar-admin.php [--login=primeiro.segundo] [--nome="Nome Completo"] [--forcar]
 *
 * A SENHA e pedida por prompt SEM eco (duas vezes). Nunca por argumento, nunca
 * por variavel de ambiente nem pelo .env. Sem terminal interativo o script
 * recusa (nao le a senha de pipe/arquivo). O script nunca imprime a senha nem o
 * hash. Recusa se ja existir admin ativo, salvo --forcar. A criacao e auditada
 * (USUARIO_CRIAR, origem=cli).
 *
 * Saida: 0 = criado; 1 = recusado/invalido; 2 = ambiente (sem terminal, banco).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// M1: stack trace nunca leva argumentos (a senha digitada passa por este processo) e
// qualquer excecao nao tratada vira UMA linha so com a classe, sem mensagem nem trace.
ini_set('zend.exception_ignore_args', '1');
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'Falha inesperada (' . get_class($e) . '). Nada foi criado.' . PHP_EOL);
    exit(2);
});

require_once __DIR__ . '/../vendor/autoload.php';

use App\Rn\UsuarioGestaoRn;
use Util\Bootstrap;
use Util\SenhaPolitica;

/** Imprime em STDERR e encerra. */
function falhar(string $mensagem, int $codigo): void
{
    fwrite(STDERR, $mensagem . PHP_EOL);
    exit($codigo);
}

/** Le uma linha (com eco) do terminal. */
function perguntar(string $rotulo): string
{
    fwrite(STDOUT, $rotulo);
    $linha = fgets(STDIN);

    return $linha === false ? '' : rtrim($linha, "\r\n");
}

/**
 * Le a senha SEM eco. Linux/macOS: stty -echo (restaurado sempre). Windows:
 * Read-Host -AsSecureString no PowerShell com o console herdado.
 */
function perguntarSenha(string $rotulo): string
{
    fwrite(STDOUT, $rotulo);
    if (DIRECTORY_SEPARATOR === '\\') {
        $comando = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
            . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); '
            . 'try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)) } '
            . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }"';
        $senha = shell_exec($comando);
        fwrite(STDOUT, PHP_EOL);

        return is_string($senha) ? rtrim($senha, "\r\n") : '';
    }
    $anterior = trim((string) shell_exec('stty -g 2>/dev/null'));
    if ($anterior === '') {
        falhar('Nao foi possivel desativar o eco do terminal. Abortando.', 2);
    }
    register_shutdown_function(static function () use ($anterior): void {
        shell_exec('stty ' . escapeshellarg($anterior) . ' 2>/dev/null');
    });
    shell_exec('stty -echo 2>/dev/null');
    $linha = fgets(STDIN);
    shell_exec('stty ' . escapeshellarg($anterior) . ' 2>/dev/null');
    fwrite(STDOUT, PHP_EOL);

    return $linha === false ? '' : rtrim($linha, "\r\n");
}

$login = null;
$nome = null;
$forcar = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--forcar') {
        $forcar = true;
    } elseif (str_starts_with($arg, '--login=')) {
        $login = substr($arg, 8);
    } elseif (str_starts_with($arg, '--nome=')) {
        $nome = substr($arg, 7);
    } else {
        // qualquer outro argumento (incluindo tentativa de passar a senha) e recusado
        falhar('Argumento nao reconhecido. Uso: php tools/criar-admin.php [--login=primeiro.segundo] [--nome="Nome"] [--forcar]', 1);
    }
}

if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    falhar('Este comando exige um terminal interativo (a senha e pedida sem eco e nunca vem de pipe, argumento ou .env).', 2);
}

try {
    $pdo = Bootstrap::conectar(dirname(__DIR__) . '/');
} catch (Throwable $e) {
    falhar('Nao foi possivel conectar ao banco (confira o .env).', 2);
}

$login ??= perguntar('Login (primeiro.segundo): ');
$nome ??= perguntar('Nome completo: ');
$senha = perguntarSenha('Senha (minimo ' . SenhaPolitica::MIN_CARACTERES . ' caracteres, sem eco): ');
$confirmacao = perguntarSenha('Repita a senha: ');
if (!hash_equals($senha, $confirmacao)) {
    falhar('As senhas nao conferem. Nada foi criado.', 1);
}

$r = (new UsuarioGestaoRn($pdo))->criarAdminInicial($login, $nome, $senha, $forcar);
unset($senha, $confirmacao);

if ($r['ok']) {
    echo 'Administrador criado: ' . UsuarioGestaoRn::normalizarLogin($login) . PHP_EOL;
    echo 'Acesse /gestao/login.php.' . PHP_EOL;
    exit(0);
}
if (($r['codigo'] ?? '') === 'admin_existe') {
    falhar('Ja existe um administrador ativo. Use --forcar somente se for realmente necessario.', 1);
}
if (($r['codigo'] ?? '') === 'validacao') {
    foreach (($r['erros'] ?? []) as $campo => $mensagem) {
        fwrite(STDERR, '- ' . $campo . ': ' . $mensagem . PHP_EOL);
    }
    falhar('Nada foi criado.', 1);
}
falhar('Falha ao criar o administrador. Nada foi criado.', 2);
