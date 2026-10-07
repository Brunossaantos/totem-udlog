<?php

namespace Util;

/**
 * Rate limit de FALHAS por IP (demanda gestao-totem, F0, 2026-10-06), usado pela
 * pagina do totem (public/totem/index.php). So conta pedidos que FALHAM: o
 * pedido valido do quiosque nunca incrementa o contador.
 *
 * Mesmo desenho de Util\AuthServidor: um arquivo pequeno por IP numa pasta fora
 * do webroot, com flock para atomicidade, janela que comeca na primeira falha.
 * O nome do arquivo e HMAC-SHA256 do "balde" do IP (IPv4 inteiro; IPv6 = prefixo
 * /64, ver IpCliente::balde) com um sal: nunca o IP em claro e nao reproduzivel
 * de fora. Sem banco: um pedido malformado nao precisa nem abrir conexao. Falha
 * ao ler/gravar o contador NAO bloqueia nem libera nada (so um log fixo, sem IP
 * nem caminho): quem decide e a validacao do codigo, o limite e uma defesa
 * adicional.
 *
 * Sal: `GESTAO_HASH_SALT` quando configurado (>= 16 caracteres); senao a
 * constante fixa SAL_PADRAO (o contador e efemero, entao a constante so serve
 * para o nome do arquivo nao ser o hash puro do IP). A variavel ausente NUNCA
 * quebra o quiosque.
 *
 * O IP deve vir de Util\IpCliente::obter().
 */
class LimiteFalhasIp
{
    public const LIMITE_FALHAS = 20;

    public const JANELA_SEGUNDOS = 600;

    /** Sal de reserva (publico de proposito): usado quando GESTAO_HASH_SALT falta ou e curta. */
    public const SAL_PADRAO = 'totem-udlog/limite-falhas-ip/v1';

    /** Teto de itens e de tempo da limpeza oportunista. */
    private const LIMPEZA_MAX_ITENS = 5000;

    private const LIMPEZA_MAX_SEGUNDOS = 0.5;

    /** @var callable|null */
    private $relogio;

    private string $sal;

    public function __construct(
        private string $dir,
        private int $limiteFalhas = self::LIMITE_FALHAS,
        private int $janelaSegundos = self::JANELA_SEGUNDOS,
        ?callable $relogio = null,
        ?string $sal = null
    ) {
        $this->relogio = $relogio;
        $this->sal = ($sal !== null && strlen($sal) >= GestaoConfig::SAL_MIN_CARACTERES) ? $sal : self::SAL_PADRAO;
    }

    /**
     * @return int|null null = nao bloqueado, int = segundos ate poder tentar de novo (>= 1)
     */
    public function segundosBloqueado(string $ip): ?int
    {
        $arquivo = $this->arquivoDoIp($ip);
        if (!is_file($arquivo)) {
            return null;
        }
        $h = @fopen($arquivo, 'rb');
        if ($h === false) {
            error_log('LimiteFalhasIp: leitura_falhou');

            return null;
        }
        $estado = null;
        if (@flock($h, LOCK_SH)) {
            $estado = json_decode((string) stream_get_contents($h), true);
            @flock($h, LOCK_UN);
        }
        @fclose($h);
        if (!is_array($estado)) {
            return null;
        }
        $inicio = (int) ($estado['inicio'] ?? 0);
        $falhas = (int) ($estado['falhas'] ?? 0);
        $decorrido = $this->agora() - $inicio;
        if ($falhas < $this->limiteFalhas || $decorrido > $this->janelaSegundos) {
            return null;
        }

        return max(1, $this->janelaSegundos - $decorrido);
    }

    public function registrarFalha(string $ip): void
    {
        if (!$this->prepararPasta()) {
            error_log('LimiteFalhasIp: pasta_indisponivel');

            return;
        }
        $arquivo = $this->arquivoDoIp($ip);
        $h = @fopen($arquivo, 'c+b');
        if ($h === false) {
            error_log('LimiteFalhasIp: gravacao_falhou');

            return;
        }
        @chmod($arquivo, 0640);
        try {
            if (!@flock($h, LOCK_EX)) {
                error_log('LimiteFalhasIp: lock_falhou');

                return;
            }
            $estado = json_decode((string) stream_get_contents($h), true);
            $agora = $this->agora();
            if (!is_array($estado) || ($agora - (int) ($estado['inicio'] ?? 0)) > $this->janelaSegundos) {
                $estado = ['inicio' => $agora, 'falhas' => 0];
            }
            $estado['falhas'] = min((int) $estado['falhas'] + 1, 1000000);
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

    private function agora(): int
    {
        return $this->relogio !== null ? (int) ($this->relogio)() : time();
    }

    private function arquivoDoIp(string $ip): string
    {
        return rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR
            . substr(hash_hmac('sha256', IpCliente::balde($ip), $this->sal), 0, 40) . '.json';
    }

    private function prepararPasta(): bool
    {
        $dir = rtrim($this->dir, '/\\');
        if ($dir === '') {
            return false;
        }

        return is_dir($dir) || @mkdir($dir, 0750, true) || is_dir($dir);
    }

    private function limparVencidos(): void
    {
        $dir = rtrim($this->dir, '/\\');
        $gestor = @opendir($dir);
        if ($gestor === false) {
            return;
        }
        $corte = $this->agora() - $this->janelaSegundos;
        $lidos = 0;
        // varre ate o teto de itens OU de tempo (nunca so os primeiros 500)
        $limiteTempo = microtime(true) + self::LIMPEZA_MAX_SEGUNDOS;
        while (($nome = readdir($gestor)) !== false && $lidos < self::LIMPEZA_MAX_ITENS && microtime(true) < $limiteTempo) {
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
