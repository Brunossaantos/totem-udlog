<?php

namespace Util;

class NotaArquivoStorage
{
    public const RETENCAO_QUARENTENA_SEGUNDOS = 86400;

    public const LIMITE_REMOCOES_POR_EXECUCAO = 500;

    private const REGEX_PASTA = '#^\d{4}-\d{2}-\d{2}/[A-Z0-9]{0,10}_\d{6}$#D';
    private const REGEX_ARQUIVO = '#^nota_0[1-5]\.jpg$#D';
    private const REGEX_DIR_DATA = '#^\d{4}-\d{2}-\d{2}$#D';
    private const REGEX_DIR_ATENDIMENTO = '#^[A-Z0-9]{0,10}_\d{6}$#D';
    private const REGEX_QUARENTENA = '#^nota_0[1-5]\.jpg\.\d{1,20}\.del$#D';

    public function __construct(private ?string $raiz = null) {}

    public static function pastaValida(mixed $pasta): bool
    {
        return is_string($pasta) && preg_match(self::REGEX_PASTA, $pasta) === 1;
    }

    public static function arquivoValido(mixed $arquivo): bool
    {
        return is_string($arquivo) && preg_match(self::REGEX_ARQUIVO, $arquivo) === 1;
    }

    public function raizReal(): ?string
    {
        $bruta = $this->raiz ?? (string) ($_ENV['STORAGE_PATH'] ?? '');
        $bruta = rtrim($bruta, '/\\');
        if ($bruta === '') {
            return null;
        }

        $real = realpath($bruta);

        return $real === false ? null : $real;
    }

    public function caminhoDoArquivo(mixed $pasta, mixed $arquivo): ?string
    {
        if (!self::pastaValida($pasta) || !self::arquivoValido($arquivo)) {
            return null;
        }

        $raiz = $this->raizReal();
        if ($raiz === null) {
            return null;
        }

        $esperado = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pasta);
        $real = realpath($esperado);
        if ($real === false || !is_dir($real) || is_link($esperado)) {
            return null;
        }
        if (!$this->mesmoCaminho($real, $esperado)) {
            return null;
        }
        if (!str_starts_with($real, $raiz . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $caminho = $real . DIRECTORY_SEPARATOR . $arquivo;
        if (is_link($caminho)) {
            return null;
        }

        return $caminho;
    }

    public function caminhoQuarentena(string $caminho, int $idNota): string
    {
        return $caminho . '.' . $idNota . '.del';
    }

    public function existe(string $caminho): bool
    {
        return is_file($caminho) && !is_link($caminho);
    }

    public function quarentenar(string $caminho, int $idNota): string
    {
        $destino = $this->caminhoQuarentena($caminho, $idNota);

        if (is_link($caminho) || is_link($destino)) {
            throw new \RuntimeException('quarentena_symlink');
        }

        if (is_file($caminho)) {
            if (!@rename($caminho, $destino)) {
                throw new \RuntimeException('quarentena_falhou');
            }
            @touch($destino);

            return 'quarentenado';
        }

        if (is_file($destino)) {
            return 'ja_em_quarentena';
        }

        return 'ausente';
    }

    public function restaurar(string $caminho, int $idNota): bool
    {
        $destino = $this->caminhoQuarentena($caminho, $idNota);

        if (is_file($caminho)) {
            return true;
        }
        if (!is_file($destino) || is_link($destino)) {
            return false;
        }

        return @rename($destino, $caminho);
    }

    public function remover(string $caminho, int $idNota): bool
    {
        $destino = $this->caminhoQuarentena($caminho, $idNota);

        if (is_link($destino)) {
            return false;
        }
        if (!is_file($destino)) {
            return true;
        }

        return @unlink($destino);
    }

    public function limparQuarentenaExpirada(
        int $agora,
        int $retencaoSegundos = self::RETENCAO_QUARENTENA_SEGUNDOS,
        int $limiteRemocoes = self::LIMITE_REMOCOES_POR_EXECUCAO
    ): array {
        $resultado = [
            'removidos' => 0, 'mantidos' => 0, 'falhas' => 0, 'ignorados' => 0,
            'erros_leitura' => 0, 'limite_atingido' => false,
        ];

        $raiz = $this->raizReal();
        if ($raiz === null) {
            throw new \RuntimeException('raiz_indisponivel');
        }

        $corte = $agora - $retencaoSegundos;

        $gestorRaiz = $this->abrirDiretorio($raiz);
        if ($gestorRaiz === false) {
            throw new \RuntimeException('raiz_indisponivel');
        }

        try {
            while (($dirData = readdir($gestorRaiz)) !== false) {
                if ($dirData === '.' || $dirData === '..') {
                    continue;
                }
                $caminhoData = $raiz . DIRECTORY_SEPARATOR . $dirData;
                if (preg_match(self::REGEX_DIR_DATA, $dirData) !== 1) {
                    $resultado['ignorados']++;
                    continue;
                }
                if (!$this->diretorioReal($caminhoData)) {
                    $resultado[$this->diretorioIlegivel($caminhoData) ? 'erros_leitura' : 'ignorados']++;
                    continue;
                }

                if (!$this->varrerDiretorioDeData($caminhoData, $corte, $limiteRemocoes, $resultado)) {
                    break;
                }
            }
        } finally {
            closedir($gestorRaiz);
        }

        return $resultado;
    }

    private function varrerDiretorioDeData(string $caminhoData, int $corte, int $limiteRemocoes, array &$resultado): bool
    {
        $gestorData = $this->abrirDiretorio($caminhoData);
        if ($gestorData === false) {
            $resultado['erros_leitura']++;

            return true;
        }

        try {
            while (($dirAtend = readdir($gestorData)) !== false) {
                if ($dirAtend === '.' || $dirAtend === '..') {
                    continue;
                }
                $caminhoAtend = $caminhoData . DIRECTORY_SEPARATOR . $dirAtend;
                if (preg_match(self::REGEX_DIR_ATENDIMENTO, $dirAtend) !== 1) {
                    $resultado['ignorados']++;
                    continue;
                }
                if (!$this->diretorioReal($caminhoAtend)) {
                    $resultado[$this->diretorioIlegivel($caminhoAtend) ? 'erros_leitura' : 'ignorados']++;
                    continue;
                }

                if (!$this->varrerDiretorioDeAtendimento($caminhoAtend, $corte, $limiteRemocoes, $resultado)) {
                    return false;
                }
            }
        } finally {
            closedir($gestorData);
        }

        return true;
    }

    private function varrerDiretorioDeAtendimento(string $caminhoAtend, int $corte, int $limiteRemocoes, array &$resultado): bool
    {
        $gestor = $this->abrirDiretorio($caminhoAtend);
        if ($gestor === false) {
            $resultado['erros_leitura']++;

            return true;
        }

        try {
            while (($nome = readdir($gestor)) !== false) {
                if (preg_match(self::REGEX_QUARENTENA, $nome) !== 1) {
                    continue;
                }

                $arquivo = $caminhoAtend . DIRECTORY_SEPARATOR . $nome;
                if (is_link($arquivo) || !is_file($arquivo)) {
                    $resultado['ignorados']++;
                    continue;
                }

                $mtime = @filemtime($arquivo);
                if ($mtime === false || $mtime >= $corte) {
                    $resultado['mantidos']++;
                    continue;
                }

                if (@unlink($arquivo)) {
                    $resultado['removidos']++;
                    if ($resultado['removidos'] >= $limiteRemocoes) {
                        $resultado['limite_atingido'] = true;

                        return false;
                    }
                } else {
                    $resultado['falhas']++;
                }
            }
        } finally {
            closedir($gestor);
        }

        return true;
    }

    protected function abrirDiretorio(string $caminho)
    {
        return @opendir($caminho);
    }

    private function diretorioIlegivel(string $caminho): bool
    {
        return !is_link($caminho) && is_dir($caminho) && realpath($caminho) === false;
    }

    private function diretorioReal(string $caminho): bool
    {
        if (is_link($caminho) || !is_dir($caminho)) {
            return false;
        }
        $real = realpath($caminho);

        return $real !== false && $this->mesmoCaminho($real, $caminho);
    }

    private function mesmoCaminho(string $a, string $b): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
    }
}
