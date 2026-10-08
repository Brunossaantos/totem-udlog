<?php

namespace App\Dao;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Util\LogCatalogo;

/**
 * Acesso a tb_log_sistema (migration 023).
 *
 * Escrita: usada SO por Util\LogSistema (com a conexao dedicada dele), que valida
 * tudo pelo catalogo antes de chegar aqui. Fica neste DAO (e nao dentro do
 * LogSistema) para o SQL da tabela ter um unico dono e para os testes poderem
 * exercitar o UPSERT direto. Todo valor entra por prepared statement.
 *
 * Leitura (para a tela de logs da F3c): listagem paginada com whitelist de
 * filtros e de ordenacao (nenhum nome de coluna vem do chamador), contagem por
 * aba e categorias da aba. LIMIT/OFFSET sempre PARAM_INT.
 *
 * Retencao: o UNICO DELETE desta tabela esta em apagarAntigosLoteAntesDe(): corte
 * calculado no PHP (agora menos 90 dias, fuso -03:00 da sessao do projeto) com PISO
 * de 90 dias (recusa corte mais novo). Nunca vem de .env nem de parametro de
 * requisicao. So o cron cron/limpar-logs-gestao.php chama.
 */
class LogSistemaDao
{
    public const RETENCAO_DIAS = 90;

    public const LOTE_MAXIMO = 500;

    public const ORDENS = ['ultima_ocorrencia', 'criado_em', 'contador', 'nivel', 'categoria', 'id_log'];

    public const FILTROS = ['origem', 'nivel', 'categoria', 'id_totem', 'id_atendimento', 'de', 'ate'];

    public const POR_PAGINA_MAXIMO = 200;

    /**
     * Quantas consultas COUNT(*) de teto (diario/total) este processo executou.
     * So observabilidade (os testes provam que evento ja agrupado nao conta).
     */
    public static int $consultasDeTeto = 0;

    public function __construct(private PDO $pdo)
    {
    }

    // ------------------------------------------------------------------
    // Escrita (Util\LogSistema)
    // ------------------------------------------------------------------

    /** tipo ('expedicao'|'recebimento') do atendimento, por PK, ou null. */
    public function tipoDoAtendimento(int $idAtendimento): ?string
    {
        $stmt = $this->pdo->prepare('SELECT tipo FROM tb_atendimento WHERE id_atendimento = :id');
        $stmt->bindValue('id', $idAtendimento, PDO::PARAM_INT);
        $stmt->execute();
        $tipo = $stmt->fetchColumn();

        return is_string($tipo) ? $tipo : null;
    }

    /** Linhas criadas hoje (dia da sessao, -03:00). */
    public function contarCriadosHoje(): int
    {
        self::$consultasDeTeto++;

        return (int) $this->pdo->query('SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em >= CURDATE()')->fetchColumn();
    }

    public function contarTotal(): int
    {
        self::$consultasDeTeto++;

        return (int) $this->pdo->query('SELECT COUNT(*) FROM tb_log_sistema')->fetchColumn();
    }

    /**
     * UPSERT atomico (uma unica instrucao): cria a linha da janela ou soma
     * `$ocorrencias` ao contador, com teto de 4294967295.
     *
     * @param array{nivel:string,origem:string,categoria:string,mensagem:string,id_atendimento:?int,id_totem:?int,detalhe:?string,dedup_chave:string,janela:int} $e janela = unix timestamp do inicio do balde
     */
    public function registrarOuIncrementar(array $e, int $ocorrencias): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_log_sistema
                (nivel, origem, categoria, mensagem, id_atendimento, id_totem, detalhe, dedup_chave, janela, contador)
             VALUES
                (:nivel, :origem, :categoria, :mensagem, :id_atendimento, :id_totem, :detalhe, :dedup_chave, FROM_UNIXTIME(:janela), :contador_novo)
             ON DUPLICATE KEY UPDATE
                contador = LEAST(contador + :contador_soma, 4294967295),
                ultima_ocorrencia = NOW()'
        );
        $stmt->bindValue('nivel', $e['nivel']);
        $stmt->bindValue('origem', $e['origem']);
        $stmt->bindValue('categoria', $e['categoria']);
        $stmt->bindValue('mensagem', $e['mensagem']);
        $stmt->bindValue('id_atendimento', $e['id_atendimento'], $e['id_atendimento'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('id_totem', $e['id_totem'], $e['id_totem'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('detalhe', $e['detalhe'], $e['detalhe'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('dedup_chave', $e['dedup_chave']);
        $stmt->bindValue('janela', $e['janela'], PDO::PARAM_INT);
        $stmt->bindValue('contador_novo', $ocorrencias, PDO::PARAM_INT);
        $stmt->bindValue('contador_soma', $ocorrencias, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Soma ocorrencias SO se a linha da janela ja existe (nunca cria linha nova;
     * sem COUNT). E o PRIMEIRO passo de cada descarga: evento ja agrupado nao
     * paga a contagem dos tetos. Devolve se uma linha foi atualizada.
     */
    public function incrementarSeExistir(string $dedupChave, int $janela, int $ocorrencias): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tb_log_sistema
                SET contador = LEAST(contador + :soma, 4294967295), ultima_ocorrencia = NOW()
              WHERE dedup_chave = :dedup_chave AND janela = FROM_UNIXTIME(:janela)'
        );
        $stmt->bindValue('soma', $ocorrencias, PDO::PARAM_INT);
        $stmt->bindValue('dedup_chave', $dedupChave);
        $stmt->bindValue('janela', $janela, PDO::PARAM_INT);
        $stmt->execute();

        // So rowCount: NAO reconfirmar com SELECT (corrida: a linha pode nascer entre o
        // UPDATE e o SELECT e o incremento se perderia). contador + n sempre muda a linha,
        // exceto saturada em 4294967295 no mesmo segundo: ai devolve false e o chamador
        // cai no UPSERT, que e inofensivo (LEAST).
        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------
    // Leitura (tela de logs, F3c)
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $filtros chaves de FILTROS (origem, nivel, categoria, id_totem, id_atendimento, de, ate = AAAA-MM-DD)
     * @return array{itens:list<array<string,mixed>>,total:int,pagina:int,por_pagina:int}
     */
    public function listar(array $filtros, int $pagina = 1, int $porPagina = 50, string $ordem = 'ultima_ocorrencia', string $direcao = 'DESC'): array
    {
        if (!in_array($ordem, self::ORDENS, true)) {
            throw new InvalidArgumentException('ordenacao fora da whitelist');
        }
        $direcao = strtoupper($direcao);
        if ($direcao !== 'ASC' && $direcao !== 'DESC') {
            throw new InvalidArgumentException('direcao fora da whitelist');
        }
        $pagina = max(1, $pagina);
        $porPagina = min(self::POR_PAGINA_MAXIMO, max(1, $porPagina));

        [$where, $params] = $this->montarFiltros($filtros);

        $stmtTotal = $this->pdo->prepare('SELECT COUNT(*) FROM tb_log_sistema' . $where);
        $this->vincular($stmtTotal, $params);
        $stmtTotal->execute();
        $total = (int) $stmtTotal->fetchColumn();

        // $ordem e $direcao ja validados contra whitelist fixa (nunca vem cru)
        $stmt = $this->pdo->prepare(
            'SELECT id_log, nivel, origem, categoria, mensagem, id_atendimento, id_totem, detalhe, contador, criado_em, ultima_ocorrencia
               FROM tb_log_sistema' . $where . '
              ORDER BY ' . $ordem . ' ' . $direcao . ', id_log DESC
              LIMIT :limite OFFSET :deslocamento'
        );
        $this->vincular($stmt, $params);
        $stmt->bindValue('limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue('deslocamento', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        return ['itens' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    /**
     * Total de linhas por aba (origem), com os mesmos filtros exceto a propria aba.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,int> origem => total (todas as origens presentes, 0 quando vazio)
     */
    public function contarPorAba(array $filtros = []): array
    {
        unset($filtros['origem']);
        [$where, $params] = $this->montarFiltros($filtros);
        $stmt = $this->pdo->prepare('SELECT origem, COUNT(*) AS total FROM tb_log_sistema' . $where . ' GROUP BY origem');
        $this->vincular($stmt, $params);
        $stmt->execute();
        $abas = array_fill_keys(LogCatalogo::ORIGENS, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $abas[(string) $linha['origem']] = (int) $linha['total'];
        }

        return $abas;
    }

    /** @return list<string> categorias que existem na aba (para o filtro da tela) */
    public function categoriasDaAba(string $origem): array
    {
        if (!in_array($origem, LogCatalogo::ORIGENS, true)) {
            throw new InvalidArgumentException('aba fora da whitelist');
        }
        $stmt = $this->pdo->prepare('SELECT DISTINCT categoria FROM tb_log_sistema WHERE origem = :origem ORDER BY categoria');
        $stmt->bindValue('origem', $origem);
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,mixed> $filtros
     * @return array{0:string,1:array<string,array{0:mixed,1:int}>}
     */
    private function montarFiltros(array $filtros): array
    {
        $condicoes = [];
        $params = [];
        foreach ($filtros as $chave => $valor) {
            if (!is_string($chave) || !in_array($chave, self::FILTROS, true)) {
                throw new InvalidArgumentException('filtro fora da whitelist');
            }
            if ($valor === null || $valor === '') {
                continue;
            }
            switch ($chave) {
                case 'origem':
                    $this->exigirEnum($valor, LogCatalogo::ORIGENS);
                    $condicoes[] = 'origem = :f_origem';
                    $params['f_origem'] = [$valor, PDO::PARAM_STR];
                    break;
                case 'nivel':
                    $this->exigirEnum($valor, LogCatalogo::NIVEIS);
                    $condicoes[] = 'nivel = :f_nivel';
                    $params['f_nivel'] = [$valor, PDO::PARAM_STR];
                    break;
                case 'categoria':
                    $this->exigirEnum($valor, array_keys(LogCatalogo::CATEGORIAS));
                    $condicoes[] = 'categoria = :f_categoria';
                    $params['f_categoria'] = [$valor, PDO::PARAM_STR];
                    break;
                case 'id_totem':
                case 'id_atendimento':
                    if (!is_int($valor) || $valor < 1) {
                        throw new InvalidArgumentException('filtro de id invalido');
                    }
                    $condicoes[] = $chave . ' = :f_' . $chave;
                    $params['f_' . $chave] = [$valor, PDO::PARAM_INT];
                    break;
                case 'de':
                    $condicoes[] = 'ultima_ocorrencia >= :f_de';
                    $params['f_de'] = [$this->dia($valor)->format('Y-m-d 00:00:00'), PDO::PARAM_STR];
                    break;
                case 'ate':
                    $condicoes[] = 'ultima_ocorrencia < :f_ate';
                    $params['f_ate'] = [$this->dia($valor)->modify('+1 day')->format('Y-m-d 00:00:00'), PDO::PARAM_STR];
                    break;
            }
        }

        return [$condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes), $params];
    }

    /** @param list<string> $permitidos */
    private function exigirEnum(mixed $valor, array $permitidos): void
    {
        if (!is_string($valor) || !in_array($valor, $permitidos, true)) {
            throw new InvalidArgumentException('valor de filtro fora da whitelist');
        }
    }

    private function dia(mixed $valor): DateTimeImmutable
    {
        if (!is_string($valor) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $valor) !== 1) {
            throw new InvalidArgumentException('data de filtro invalida');
        }
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw new InvalidArgumentException('data de filtro invalida');
        }

        return $data;
    }

    /** @param array<string,array{0:mixed,1:int}> $params */
    private function vincular(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $nome => [$valor, $tipo]) {
            $stmt->bindValue($nome, $valor, $tipo);
        }
    }

    // ------------------------------------------------------------------
    // Retencao (cron/limpar-logs-gestao.php)
    // ------------------------------------------------------------------

    /** Corte da retencao: agora menos 90 dias, no fuso da sessao do projeto. */
    public static function corteRetencao(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('-03:00')))->modify('-' . self::RETENCAO_DIAS . ' days');
    }

    public function contarAntigos(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tb_log_sistema WHERE criado_em < :corte');
        $stmt->bindValue('corte', self::corteRetencao()->format('Y-m-d H:i:s'));
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** Apaga um lote de logs com mais de 90 dias. */
    public function apagarAntigosLote(int $limite): int
    {
        return $this->apagarAntigosLoteAntesDe(self::corteRetencao(), $limite);
    }

    /**
     * PISO de retencao: recusa qualquer corte mais novo que agora menos 90 dias.
     * Unico DELETE de tb_log_sistema do sistema.
     */
    public function apagarAntigosLoteAntesDe(DateTimeInterface $corte, int $limite): int
    {
        if ($limite < 1 || $limite > self::LOTE_MAXIMO) {
            throw new InvalidArgumentException('tamanho de lote invalido');
        }
        if ($corte->getTimestamp() > self::corteRetencao()->getTimestamp()) {
            throw new InvalidArgumentException('corte mais novo que o piso de retencao');
        }
        $corteTexto = (new DateTimeImmutable('@' . $corte->getTimestamp()))
            ->setTimezone(new DateTimeZone('-03:00'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('DELETE FROM tb_log_sistema WHERE criado_em < :corte ORDER BY id_log LIMIT :limite');
        $stmt->bindValue('corte', $corteTexto);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
