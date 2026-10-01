<?php

namespace Util;

/**
 * Acesso controlado as fotos das notas fiscais em STORAGE_PATH (demanda
 * hardening-revisao-notas-e-cliente, 2026-09-30, decisao D3).
 *
 * Seguranca (dado pessoal, LGPD):
 *  - o caminho e SEMPRE derivado no servidor de STORAGE_PATH + pasta_documentos
 *    + arquivo gravados no banco, nunca de campo do request;
 *  - pasta_documentos e arquivo tem o formato validado (AAAA-MM-DD/PLACA_HHMMSS
 *    e nota_01..nota_05.jpg) antes de qualquer acesso a disco;
 *  - a pasta tem de resolver (realpath) exatamente para STORAGE_PATH real +
 *    pasta, o que rejeita symlink e ".." em qualquer componente;
 *  - arquivo que e symlink e rejeitado.
 *
 * Quarentena: a "exclusao" de uma foto e um rename atomico para
 * nota_NN.jpg.<id_nota>.del na MESMA pasta (mesmo filesystem); o arquivo so e
 * apagado de vez depois do COMMIT do banco (remover) ou pelo cron diario
 * (cron/limpar-notas-quarentena.php, retencao de 24 horas, limparQuarentenaExpirada).
 *
 * A classe e injetavel (nao final, metodos publicos) para que os testes
 * simulem falha de rename/remocao sem depender do sistema de arquivos.
 * Nenhum metodo loga caminho, nome de pasta (contem placa) ou numero de nota.
 */
class NotaArquivoStorage
{
    public const RETENCAO_QUARENTENA_SEGUNDOS = 86400;

    // F2 (rodada corretiva 2026-10-01): maximo de .del apagados por execucao
    // do cron, mesmo com mais elegiveis (o restante fica para a proxima).
    public const LIMITE_REMOCOES_POR_EXECUCAO = 500;

    // placa limpa tem de 0 a 10 caracteres alfanumericos (tb_atendimento.placa
    // e VARCHAR(8); "0" cobre uma placa digitada so com simbolos, que
    // UploadHelper::montarPasta limpa para vazio).
    //
    // F3 (rodada corretiva 2026-10-01): TODAS as regexes terminam com o
    // modificador D (PCRE_DOLLAR_ENDONLY). Sem ele, `$` casa tambem ANTES de um
    // newline final, e "nota_01.jpg" + newline (ou uma pasta terminada em newline)
    // passaria como valido. Com D, `$` so casa no fim REAL da string:
    // newline, CR, NUL, separador, traversal, extensao adicional e qualquer
    // sufixo invisivel (bytes extras) sao rejeitados.
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

    /**
     * Raiz real (realpath) de STORAGE_PATH, ou null se ausente/inexistente.
     */
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

    /**
     * Devolve o caminho absoluto do arquivo da nota derivado so de valores
     * do banco, ou null se QUALQUER validacao falhar (formato da pasta, formato
     * do arquivo, pasta inexistente, symlink, fora da raiz, arquivo symlink).
     * O arquivo em si pode nao existir (retorno nao-null).
     */
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

    /**
     * Move a foto para a quarentena (rename atomico). Retorno:
     *  'quarentenado'      -- arquivo movido agora (quem chamou pode restaurar);
     *  'ja_em_quarentena'  -- original ausente e .del ja existe (retry);
     *  'ausente'           -- nem original nem .del.
     * Lanca \RuntimeException (mensagem fixa) se o rename falhar ou houver
     * symlink no caminho.
     */
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
            // rename preserva o mtime da captura: a retencao de 24 horas conta
            // a partir da quarentena, entao o mtime e renovado (melhor esforco).
            @touch($destino);

            return 'quarentenado';
        }

        if (is_file($destino)) {
            return 'ja_em_quarentena';
        }

        return 'ausente';
    }

    /**
     * Desfaz a quarentena (rename de volta). So age se o .del existir e o
     * original nao. Retorna true se o original esta no lugar ao final.
     */
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

    /**
     * Apaga de vez o .del (chamado so depois do COMMIT). Retorna true se o
     * .del nao existe mais ao final; false se a remocao falhou (tolerado
     * pelo chamador: o cron cobre).
     */
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

    /**
     * Apaga so arquivos .del com mais de $retencaoSegundos, em estrutura de
     * pastas FIXA (RAIZ/AAAA-MM-DD/PLACA_HHMMSS/nota_NN.jpg.<id>.del), sem
     * seguir symlink (diretorios ou arquivos), sem tocar em qualquer outro
     * arquivo. Usado por cron/limpar-notas-quarentena.php (sem banco).
     *
     * F2 (rodada corretiva 2026-10-01):
     *  - no maximo $limiteRemocoes arquivos apagados por execucao (parada
     *    imediata ao atingir o limite; `limite_atingido` = true);
     *  - a varredura usa opendir/readdir (UMA entrada por vez, nunca scandir
     *    completo), entao um backlog de milhares de arquivos nao e carregado
     *    em memoria;
     *  - falha ao ABRIR/LER um diretorio (permissao, erro de I/O) conta em
     *    `erros_leitura` (o cron sai com codigo != 0); a raiz inacessivel lanca
     *    \RuntimeException('raiz_indisponivel'). Nunca ha falso sucesso.
     *
     * @return array{removidos:int, mantidos:int, falhas:int, ignorados:int, erros_leitura:int, limite_atingido:bool}
     */
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
                    // diretorio existente mas sem permissao de travessia =
                    // varredura incompleta (nao e "ignorado": e erro de leitura)
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

    /**
     * @param array<string, mixed> $resultado
     * @return bool false se o limite de remocoes foi atingido (parar tudo)
     */
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

    /**
     * @param array<string, mixed> $resultado
     * @return bool false se o limite de remocoes foi atingido
     */
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

    /**
     * Ponto unico de abertura de diretorio da varredura (opendir). Protegido
     * so para que os testes simulem falha de leitura de um diretorio sem
     * depender de permissao do sistema de arquivos.
     *
     * @return resource|false
     */
    protected function abrirDiretorio(string $caminho)
    {
        return @opendir($caminho);
    }

    /**
     * Diretorio que EXISTE (nao e symlink) mas cujo realpath nao resolve
     * (sem permissao de travessia): conta como erro de leitura, nunca como
     * simples "ignorado".
     */
    private function diretorioIlegivel(string $caminho): bool
    {
        return !is_link($caminho) && is_dir($caminho) && realpath($caminho) === false;
    }

    /**
     * Diretorio "de verdade": existe, nao e symlink e o realpath coincide
     * com o proprio caminho (no Windows is_link nao detecta juncao de
     * diretorio; o realpath resolve o desvio e o denuncia).
     */
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
