<?php

namespace Util;

class AuthServidor
{
    public const LIMITE_FALHAS = 10;

    public const JANELA_SEGUNDOS = 900;

    private $relogio;

    public function __construct(
        private ?string $chave,
        private bool $permitirHttp = false,
        private ?string $dirRateLimit = null,
        private int $limiteFalhas = self::LIMITE_FALHAS,
        private int $janelaSegundos = self::JANELA_SEGUNDOS,
        ?callable $relogio = null
    ) {
        $this->relogio = $relogio;
    }

    public function verificar(array $server, ?string $authorization): ?array
    {
        if (!$this->permitirHttp && !self::requisicaoHttps($server)) {
            return ['http' => 403, 'codigo' => 'HTTPS_OBRIGATORIO'];
        }

        if ($this->chave === null || $this->chave === '') {
            return ['http' => 503, 'codigo' => 'INDISPONIVEL'];
        }

        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($this->bloqueado($ip)) {
            return ['http' => 429, 'codigo' => 'MUITAS_TENTATIVAS', 'retry_after' => $this->janelaSegundos];
        }

        $esperado = hash('sha256', 'Bearer ' . $this->chave, true);
        $recebido = hash('sha256', (string) $authorization, true);
        if (!hash_equals($esperado, $recebido)) {
            $this->registrarFalha($ip);

            return ['http' => 401, 'codigo' => 'NAO_AUTORIZADO'];
        }

        return null;
    }

    public static function requisicaoHttps(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return (string) ($server['SERVER_PORT'] ?? '') === '443';
    }

    private function agora(): int
    {
        return $this->relogio !== null ? (int) ($this->relogio)() : time();
    }

    private function arquivoDoIp(string $ip): ?string
    {
        if ($this->dirRateLimit === null || $this->dirRateLimit === '') {
            return null;
        }

        return rtrim($this->dirRateLimit, '/\\') . DIRECTORY_SEPARATOR . substr(hash('sha256', $ip), 0, 40) . '.json';
    }

    private function prepararPasta(): bool
    {
        $dir = rtrim((string) $this->dirRateLimit, '/\\');
        if ($dir === '') {
            return false;
        }

        return is_dir($dir) || @mkdir($dir, 0750) || is_dir($dir);
    }

    private function bloqueado(string $ip): bool
    {
        $arquivo = $this->arquivoDoIp($ip);
        if ($arquivo === null || !is_file($arquivo)) {
            return false;
        }
        $h = @fopen($arquivo, 'rb');
        if ($h === false) {
            error_log('AuthServidor: rate_limit_leitura_falhou');

            return false;
        }
        $estado = null;
        if (@flock($h, LOCK_SH)) {
            $estado = json_decode((string) stream_get_contents($h), true);
            @flock($h, LOCK_UN);
        }
        @fclose($h);
        if (!is_array($estado)) {
            return false;
        }
        $inicio = (int) ($estado['inicio'] ?? 0);
        $falhas = (int) ($estado['falhas'] ?? 0);

        return ($this->agora() - $inicio) <= $this->janelaSegundos && $falhas >= $this->limiteFalhas;
    }

    private function registrarFalha(string $ip): void
    {
        $arquivo = $this->arquivoDoIp($ip);
        if ($arquivo === null) {
            return;
        }
        if (!$this->prepararPasta()) {
            error_log('AuthServidor: rate_limit_pasta_indisponivel');

            return;
        }
        $h = @fopen($arquivo, 'c+b');
        if ($h === false) {
            error_log('AuthServidor: rate_limit_gravacao_falhou');

            return;
        }
        @chmod($arquivo, 0640);
        try {
            if (!@flock($h, LOCK_EX)) {
                error_log('AuthServidor: rate_limit_lock_falhou');

                return;
            }
            $estado = json_decode((string) stream_get_contents($h), true);
            $agora = $this->agora();
            if (!is_array($estado) || ($agora - (int) ($estado['inicio'] ?? 0)) > $this->janelaSegundos) {
                $estado = ['inicio' => $agora, 'falhas' => 0];
            }
            $estado['falhas'] = (int) $estado['falhas'] + 1;
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($estado));
            fflush($h);
            @flock($h, LOCK_UN);
        } finally {
            @fclose($h);
        }

        if (random_int(1, 50) === 1) {
            $this->limparVencidos();
        }
    }

    private function limparVencidos(): void
    {
        $dir = rtrim((string) $this->dirRateLimit, '/\\');
        $gestor = @opendir($dir);
        if ($gestor === false) {
            return;
        }
        $corte = $this->agora() - $this->janelaSegundos;
        $lidos = 0;
        while (($nome = readdir($gestor)) !== false && $lidos < 500) {
            $lidos++;
            if (preg_match('/\A[a-f0-9]{40}\.json\z/D', $nome) !== 1) {
                continue;
            }
            $caminho = $dir . DIRECTORY_SEPARATOR . $nome;
            $mtime = @filemtime($caminho);
            if (is_file($caminho) && !is_link($caminho) && $mtime !== false && $mtime < $corte) {
                @unlink($caminho);
            }
        }
        closedir($gestor);
    }
}
