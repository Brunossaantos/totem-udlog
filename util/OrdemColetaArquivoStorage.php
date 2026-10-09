<?php

namespace Util;

class OrdemColetaArquivoStorage
{
    public const SUBPASTA = 'ordens_coleta';

    public const FUSO = 'America/Sao_Paulo';

    private const REGEX_CAMINHO = '#^(\d{14})/([A-Za-z0-9._-]{1,60})_(\d{14})\.pdf$#D';

    private const MAX_TENTATIVAS_NOME = 30;

    private $relogio;

    public function __construct(private ?string $raizStorage = null, ?callable $relogio = null)
    {
        $this->relogio = $relogio;
    }

    public static function caminhoRelativoValido(mixed $caminho): bool
    {
        return is_string($caminho) && strlen($caminho) <= 255 && preg_match(self::REGEX_CAMINHO, $caminho) === 1;
    }

    public static function parteDoNumero(string $numero): string
    {
        $parte = @preg_replace('/[^A-Za-z0-9._-]/u', '-', $numero);
        if (!is_string($parte) || $parte === '') {
            $parte = @preg_replace('/[^A-Za-z0-9._-]/', '-', $numero);
        }
        $parte = (string) preg_replace('/\A\.+/', '-', (string) $parte);
        $parte = substr($parte, 0, 60);

        return $parte === '' ? '-' : $parte;
    }

    public function storageReal(): ?string
    {
        $bruta = $this->raizStorage ?? (string) ($_ENV['STORAGE_PATH'] ?? '');
        $bruta = rtrim($bruta, '/\\');
        if ($bruta === '') {
            return null;
        }
        $real = realpath($bruta);

        return $real === false ? null : $real;
    }

    public function raizReal(): ?string
    {
        $storage = $this->storageReal();
        if ($storage === null) {
            return null;
        }
        $esperado = $storage . DIRECTORY_SEPARATOR . self::SUBPASTA;
        if (is_link($esperado) || !is_dir($esperado)) {
            return null;
        }
        $real = realpath($esperado);
        if ($real === false || !$this->mesmoCaminho($real, $esperado)) {
            return null;
        }

        return $real;
    }

    public function gravar(string $cnpj, string $numero, string $bytes, ?callable $caminhoReservado = null): string
    {
        if (preg_match('/\A\d{14}\z/D', $cnpj) !== 1) {
            throw new \RuntimeException('cnpj_invalido');
        }
        $storage = $this->storageReal();
        if ($storage === null) {
            throw new \RuntimeException('storage_indisponivel');
        }

        $raiz = $storage . DIRECTORY_SEPARATOR . self::SUBPASTA;
        if (!is_dir($raiz) && !@mkdir($raiz, 0750) && !is_dir($raiz)) {
            throw new \RuntimeException('raiz_nao_criada');
        }
        $dir = $raiz . DIRECTORY_SEPARATOR . $cnpj;
        if (!is_dir($dir) && !@mkdir($dir, 0750) && !is_dir($dir)) {
            throw new \RuntimeException('pasta_nao_criada');
        }
        if (is_link($raiz) || is_link($dir)) {
            throw new \RuntimeException('symlink_rejeitado');
        }
        $raizReal = realpath($raiz);
        $dirReal = realpath($dir);
        if ($raizReal === false || $dirReal === false
            || !$this->mesmoCaminho($raizReal, $raiz)
            || !$this->mesmoCaminho($dirReal, $dir)) {
            throw new \RuntimeException('pasta_fora_da_raiz');
        }

        $tmp = $dirReal . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(8));
        $this->escreverExclusivo($tmp, $bytes);

        $parte = self::parteDoNumero($numero);
        $base = $this->agora();
        try {
            for ($i = 0; $i < self::MAX_TENTATIVAS_NOME; $i++) {
                $carimbo = $base->modify('+' . $i . ' seconds')->format('YmdHis');
                $relativo = $cnpj . '/' . $parte . '_' . $carimbo . '.pdf';
                if (!self::caminhoRelativoValido($relativo)) {
                    throw new \RuntimeException('nome_invalido');
                }
                $final = $dirReal . DIRECTORY_SEPARATOR . $parte . '_' . $carimbo . '.pdf';
                if (is_link($final) || file_exists($final)) {
                    continue;
                }
                if ($caminhoReservado !== null && $caminhoReservado($relativo) === true) {
                    continue;
                }
                if ($this->criarLinkExclusivo($tmp, $final)) {
                    return $relativo;
                }
                clearstatcache(true, $final);
                if (is_link($final) || file_exists($final)) {
                    continue;
                }
                try {
                    $this->escreverExclusivo($final, $bytes);

                    return $relativo;
                } catch (\RuntimeException $e) {
                    clearstatcache(true, $final);
                    if (is_link($final) || file_exists($final)) {
                        continue;
                    }
                    throw $e;
                }
            }
            throw new \RuntimeException('nome_indisponivel');
        } finally {
            @unlink($tmp);
        }
    }

    protected function criarLinkExclusivo(string $tmp, string $final): bool
    {
        return @link($tmp, $final);
    }

    private function escreverExclusivo(string $caminho, string $bytes): void
    {
        $h = @fopen($caminho, 'xb');
        if ($h === false) {
            throw new \RuntimeException('tmp_nao_criado');
        }
        $total = strlen($bytes);
        try {
            $escrito = 0;
            while ($escrito < $total) {
                $n = @fwrite($h, substr($bytes, $escrito, 1048576));
                if ($n === false || $n === 0) {
                    throw new \RuntimeException('escrita_falhou');
                }
                $escrito += $n;
            }
            @fflush($h);
        } catch (\Throwable $e) {
            @fclose($h);
            @unlink($caminho);
            throw $e;
        }
        @fclose($h);
        @chmod($caminho, 0640);
        clearstatcache(true, $caminho);
        if (@filesize($caminho) !== $total) {
            @unlink($caminho);
            throw new \RuntimeException('tamanho_gravado_divergente');
        }
    }

    public function caminhoAbsoluto(mixed $relativo): ?string
    {
        if (!self::caminhoRelativoValido($relativo)) {
            return null;
        }
        $raiz = $this->raizReal();
        if ($raiz === null) {
            return null;
        }
        [$cnpj, $arquivo] = explode('/', $relativo, 2);
        $dir = $raiz . DIRECTORY_SEPARATOR . $cnpj;
        if (is_link($dir) || !is_dir($dir)) {
            return null;
        }
        $dirReal = realpath($dir);
        if ($dirReal === false || !$this->mesmoCaminho($dirReal, $dir)) {
            return null;
        }
        if (!str_starts_with($dirReal, $raiz . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $caminho = $dirReal . DIRECTORY_SEPARATOR . $arquivo;
        if (is_link($caminho)) {
            return null;
        }

        return $caminho;
    }

    public function existeComTamanho(string $relativo, int $tamanho): bool
    {
        $caminho = $this->caminhoAbsoluto($relativo);
        if ($caminho === null || !is_file($caminho)) {
            return false;
        }
        clearstatcache(true, $caminho);

        return @filesize($caminho) === $tamanho;
    }

    public function ler(string $relativo, int $maximo, string $sha256Esperado): array
    {
        $caminho = $this->caminhoAbsoluto($relativo);
        if ($caminho === null) {
            return ['bytes' => null, 'motivo' => 'caminho_invalido'];
        }
        if (!is_file($caminho)) {
            return ['bytes' => null, 'motivo' => 'arquivo_ausente'];
        }
        clearstatcache(true, $caminho);
        $tamanho = @filesize($caminho);
        if ($tamanho === false || $tamanho <= 0 || $tamanho > $maximo) {
            return ['bytes' => null, 'motivo' => 'tamanho_invalido'];
        }
        $h = @fopen($caminho, 'rb');
        if ($h === false) {
            return ['bytes' => null, 'motivo' => 'leitura_falhou'];
        }
        $bytes = @stream_get_contents($h, $maximo + 1);
        @fclose($h);
        if (!is_string($bytes) || strlen($bytes) !== $tamanho) {
            return ['bytes' => null, 'motivo' => 'leitura_falhou'];
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            return ['bytes' => null, 'motivo' => 'nao_e_pdf'];
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256Esperado) !== 1
            || !hash_equals($sha256Esperado, hash('sha256', $bytes))) {
            return ['bytes' => null, 'motivo' => 'sha256_divergente'];
        }

        return ['bytes' => $bytes, 'motivo' => null];
    }

    public function remover(string $relativo): string
    {
        $caminho = $this->caminhoAbsoluto($relativo);
        if ($caminho === null) {
            return 'invalido';
        }
        if (is_link($caminho)) {
            return 'invalido';
        }
        if (!file_exists($caminho)) {
            return 'ausente';
        }
        if (!is_file($caminho)) {
            return 'invalido';
        }

        return @unlink($caminho) ? 'removido' : 'falha';
    }

    private function agora(): \DateTimeImmutable
    {
        if ($this->relogio !== null) {
            $v = ($this->relogio)();
            if ($v instanceof \DateTimeImmutable) {
                return $v->setTimezone(new \DateTimeZone(self::FUSO));
            }
        }

        return new \DateTimeImmutable('now', new \DateTimeZone(self::FUSO));
    }

    private function mesmoCaminho(string $a, string $b): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
    }
}
