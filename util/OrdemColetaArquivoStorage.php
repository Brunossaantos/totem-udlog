<?php

namespace Util;

/**
 * Acesso controlado aos PDFs de Ordem de Coleta em
 * STORAGE_PATH/ordens_coleta/<cnpj 14 digitos>/ (demanda
 * anexo-ordem-coleta-n8n, 2026-10-05). Modelado em Util\NotaArquivoStorage
 * (que NAO e alterada).
 *
 * Seguranca (a pasta pode conter dado pessoal/comercial):
 *  - o nome final do arquivo e gerado SO no servidor:
 *    <parte do numero>_<AAAAMMDDHHMMSS>.pdf, com a parte do numero restrita a
 *    [A-Za-z0-9._-] (qualquer outro caractere vira '-'; o numero real fica so
 *    no banco). Nenhum valor do request vira separador de caminho;
 *  - todo caminho relativo (vindo do banco ou gerado aqui) e validado por
 *    regex com modificador D antes de qualquer acesso a disco;
 *  - o diretorio do cliente tem de resolver (realpath) exatamente para
 *    raiz + cnpj (rejeita symlink e ".."); arquivo symlink e rejeitado;
 *  - gravacao atomica e EXCLUSIVA: arquivo temporario ('xb') no MESMO
 *    diretorio publicado com link() (falha se o destino existe) + unlink do
 *    tmp; colisao de nome => proximo segundo (ate 30 tentativas); nunca
 *    sobrescreve; pasta 0750, arquivo 0640;
 *  - nenhum metodo loga caminho, numero da OC ou cnpj.
 *
 * Injetavel (nao final, metodos publicos) para os testes simularem falhas.
 */
class OrdemColetaArquivoStorage
{
    public const SUBPASTA = 'ordens_coleta';

    public const FUSO = 'America/Sao_Paulo';

    // Com D: `$` so casa no fim REAL da string (sem newline final).
    private const REGEX_CAMINHO = '#^(\d{14})/([A-Za-z0-9._-]{1,60})_(\d{14})\.pdf$#D';

    private const MAX_TENTATIVAS_NOME = 30;

    /** @var callable|null retorna \DateTimeImmutable (testes) */
    private $relogio;

    public function __construct(private ?string $raizStorage = null, ?callable $relogio = null)
    {
        $this->relogio = $relogio;
    }

    public static function caminhoRelativoValido(mixed $caminho): bool
    {
        return is_string($caminho) && strlen($caminho) <= 255 && preg_match(self::REGEX_CAMINHO, $caminho) === 1;
    }

    /**
     * Parte do nome derivada do numero da OC: so [A-Za-z0-9._-], resto vira
     * '-' (por caractere UTF-8), no maximo 60 caracteres, nunca vazia.
     */
    public static function parteDoNumero(string $numero): string
    {
        $parte = @preg_replace('/[^A-Za-z0-9._-]/u', '-', $numero);
        if (!is_string($parte) || $parte === '') {
            $parte = @preg_replace('/[^A-Za-z0-9._-]/', '-', $numero);
        }
        // nunca comeca com '.' (evita arquivo oculto e nomes como "." ou "..")
        $parte = (string) preg_replace('/\A\.+/', '-', (string) $parte);
        $parte = substr($parte, 0, 60);

        return $parte === '' ? '-' : $parte;
    }

    /** Raiz real de STORAGE_PATH (sem a subpasta), ou null. */
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

    /** Raiz real de ordens_coleta (precisa existir), ou null. */
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

    /**
     * Grava o PDF e devolve o caminho relativo (<cnpj>/<nome>.pdf).
     * Lanca \RuntimeException com codigo fixo em qualquer falha (o arquivo
     * temporario e removido). Nunca sobrescreve nem apaga arquivo existente.
     *
     * @param callable|null $caminhoReservado fn(string $relativo): bool; true =
     *        nome ja registrado no banco (pula para o proximo segundo)
     */
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

        // 1) conteudo completo em arquivo temporario exclusivo (nome aleatorio)
        $tmp = $dirReal . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(8));
        $this->escreverExclusivo($tmp, $bytes);

        // 2) publicacao do nome final de forma EXCLUSIVA e atomica: link() falha
        // se o destino existe (nunca sobrescreve, sem janela checagem->uso).
        // Colisao (mesmo segundo/mesma parte do numero, ou nome ja registrado
        // no banco) => proximo segundo, ate MAX_TENTATIVAS_NOME.
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
                    continue; // perdeu a corrida por este nome
                }
                // sistema de arquivos sem hard link: criacao exclusiva direta
                // ('xb' tambem falha se existir). O leitor valida %PDF- e sha256,
                // entao um arquivo parcial so geraria "sem anexo".
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

    /**
     * Cria $final como hard link de $tmp. Atomico e exclusivo: retorna false se
     * $final ja existe. Metodo separado para os testes simularem FS sem link.
     */
    protected function criarLinkExclusivo(string $tmp, string $final): bool
    {
        return @link($tmp, $final);
    }

    /**
     * Cria $caminho de forma exclusiva ('xb'), grava tudo, 0640 e confere o
     * tamanho. Em qualquer falha apaga o que ELA criou e lanca. Nunca abre um
     * arquivo existente.
     */
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

    /**
     * Caminho absoluto do arquivo derivado so de valor do banco, ou null se
     * QUALQUER validacao falhar (regex, raiz, pasta symlink/fora da raiz,
     * arquivo symlink). O arquivo em si pode nao existir.
     */
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

    /** True se o arquivo existe (regular, nao symlink) e tem o tamanho dado. */
    public function existeComTamanho(string $relativo, int $tamanho): bool
    {
        $caminho = $this->caminhoAbsoluto($relativo);
        if ($caminho === null || !is_file($caminho)) {
            return false;
        }
        clearstatcache(true, $caminho);

        return @filesize($caminho) === $tamanho;
    }

    /**
     * Leitura segura para o check-in. Retorna ['bytes' => string, 'motivo' =>
     * null] ou ['bytes' => null, 'motivo' => <codigo fixo>]. Nunca lanca.
     * Valida: regex/realpath/symlink, is_file, tamanho <= $maximo, assinatura
     * %PDF- e sha256 igual ao do banco.
     *
     * @return array{bytes:?string, motivo:?string}
     */
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

    /**
     * Apaga o arquivo (ausente = ok). Retorno: 'removido' | 'ausente' |
     * 'invalido' (caminho nao passou na validacao: nada e tocado) | 'falha'.
     */
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
