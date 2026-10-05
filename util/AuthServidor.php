<?php

namespace Util;

/**
 * Autenticacao servidor-a-servidor (integracao n8n -> totem), SEPARADA do
 * token do totem (Util\Auth::validarTotem). Demanda anexo-ordem-coleta-n8n
 * (2026-10-05).
 *
 * Regras:
 *  - HTTPS obrigatorio (403 HTTPS_OBRIGATORIO), salvo a flag explicita
 *    ORDEM_COLETA_ANEXO_PERMITIR_HTTP=true (uso local/XAMPP; o projeto nao tem
 *    APP_ENV, entao nao ha deteccao automatica de ambiente de dev);
 *  - chave em ORDEM_COLETA_ANEXO_API_KEY; ausente/vazia = 503 INDISPONIVEL
 *    (fail-closed: sem chave configurada ninguem entra);
 *  - header `Authorization: Bearer <chave>` EXATO, comparado em tempo
 *    constante (hash_equals sobre sha256 de tamanho fixo); ausente = invalido
 *    = 401 NAO_AUTORIZADO generico (sem distinguir causa);
 *  - rate limit de FALHAS de autenticacao por IP (REMOTE_ADDR, nunca
 *    X-Forwarded-For): arquivo com flock em STORAGE_PATH/ordens_coleta_ratelimit/
 *    (um arquivo pequeno por IP, hash do IP no nome). Excedido = 429. Falha ao
 *    ler/gravar o contador NAO bloqueia nem libera nada alem do que a chave ja
 *    decide (a chave continua sendo exigida); so e registrada em log fixo;
 *  - nada do request (header, IP, chave) e logado.
 */
class AuthServidor
{
    public const LIMITE_FALHAS = 10;

    public const JANELA_SEGUNDOS = 900;

    /** @var callable|null */
    private $relogio;

    /**
     * @param string|null $chave chave configurada (null/'' = 503)
     * @param string|null $dirRateLimit pasta do contador (null = sem rate limit)
     */
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

    /**
     * @param array<string,mixed> $server tipicamente $_SERVER
     * @param string|null $authorization valor do header Authorization (ou null)
     * @return array{http:int,codigo:string,retry_after?:int}|null null = autenticado
     */
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
        // contador criado pelo fopen segue o umask: forca 0640 (sem acesso a outros)
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

        // limpeza oportunista (1 em 50 falhas): apaga contadores vencidos
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
