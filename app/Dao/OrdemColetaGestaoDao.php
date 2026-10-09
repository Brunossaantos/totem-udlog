<?php

namespace App\Dao;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Util\Conexao;
use Util\ConexaoGestaoColetas;

/**
 * Acesso da Gestao Totem (F4) as ordens de coleta do banco EXTERNO
 * `udlogo59_db_gestao_coletas` (tb_ordens_coleta, tb_clientes,
 * tb_ordem_coleta_arquivos) e, SO para contagem sem dado pessoal, a
 * tb_atendimento do banco do TOTEM. Demanda gestao-totem F4a (2026-10-08).
 *
 * Decisoes do usuario: sem mascara de dados (usuario e admin veem tudo), sem
 * limite por usuario, 25 linhas por pagina, busca so por numero (prefixo >= 3
 * caracteres, nunca %x%), cliente e periodo; sem busca por placa/motorista.
 *
 * Regras de seguranca:
 *  - TODO valor vai por bind tipado (nunca concatenado). O UNICO texto SQL
 *    montado em PHP sao constantes deste arquivo (aba, coluna/direcao do
 *    ORDER BY por whitelist, LIMIT/OFFSET inteiros ja saneados e ligados por
 *    bind).
 *  - Filtro invalido NUNCA alarga a consulta: vira resultado vazio.
 *  - Nenhum metodo identifica OC so por numero para alterar estado: ativar e
 *    inativar sao por ID, atomicos (CAS no WHERE status).
 *  - Falha do banco vira OrdemColetaGestaoException (mensagem fixa, sem a
 *    excecao original: ela pode conter host, usuario, SQL e valores).
 *  - rowCount() e "linhas ALTERADAS": a conexao do banco externo
 *    (Util\ConexaoGestaoColetas) NAO usa MYSQL_ATTR_FOUND_ROWS. Mesmo se usasse,
 *    o CAS por status garante que linha casada = linha alterada.
 *
 * Os $pdo sao injetaveis para os testes; em producao a conexao e obtida so
 * no uso (nunca no construtor), como os demais DAOs do banco externo.
 */
class OrdemColetaGestaoDao
{
    public const POR_PAGINA = 25;
    public const TETO_CONTAGEM = 10000;
    /** TETO_CONTAGEM / POR_PAGINA: alem disso o OFFSET nao compensa (filtre mais). */
    public const MAX_PAGINA = 400;
    public const DIAS_ATIVAS_ANTIGAS = 15;
    public const NUMERO_PREFIXO_MIN = 3;
    public const NUMERO_MAX = 50;

    public const ABAS = ['ativas', 'ativas_15d', 'inativas'];

    public const R_EFETIVADO = 'efetivado';
    public const R_JA_NO_ESTADO = 'ja_no_estado';
    public const R_INEXISTENTE = 'inexistente';
    public const R_ESTADO_MUDOU = 'estado_mudou';

    /** aba => condicao fixa (constante, nunca vinda de entrada). */
    private const ABA_SQL = [
        'ativas' => "oc.status = 'ATIVA'",
        'ativas_15d' => "oc.status = 'ATIVA' AND oc.criado_em < DATE_SUB(NOW(), INTERVAL 15 DAY)",
        'inativas' => "oc.status = 'INATIVA'",
    ];

    /** chave publica de ordenacao => coluna (whitelist; so estas strings entram no ORDER BY). */
    private const ORDEM_SQL = [
        'criado_em' => 'oc.criado_em',
        'inativada_em' => 'oc.inativada_em',
        'numero' => 'oc.numero_ordem_coleta',
        'cliente' => 'c.razao_social',
    ];

    private const SELECT_COLUNAS = "
        oc.id,
        oc.numero_ordem_coleta AS numero,
        oc.cliente_id,
        c.razao_social,
        c.cnpj,
        c.status AS cliente_status,
        oc.transportadora_nome,
        oc.transportadora_cnpj,
        oc.placa_prevista,
        oc.motorista_nome_previsto,
        oc.cnh_prevista,
        oc.status,
        oc.criado_em,
        oc.inativada_em,
        EXISTS (
            SELECT 1 FROM tb_ordem_coleta_arquivos a
            WHERE a.cnpj_cliente = c.cnpj AND a.numero_ordem_coleta = oc.numero_ordem_coleta
        ) AS tem_pdf,
        (
            SELECT COUNT(*) FROM tb_ordens_coleta o2
            WHERE o2.numero_ordem_coleta = oc.numero_ordem_coleta AND o2.cliente_id <> oc.cliente_id
        ) AS mesmo_numero_outros_clientes";

    private const FROM_SQL = '
        FROM tb_ordens_coleta oc
        INNER JOIN tb_clientes c ON c.id = oc.cliente_id';

    public function __construct(private ?PDO $pdo = null, private ?PDO $pdoTotem = null)
    {
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * Normaliza e valida os filtros (whitelist de chaves; chave desconhecida e
     * ignorada). Devolve ['validos' => [...], 'invalidos' => [chaves]]. Um
     * valor presente e invalido (array, charset, tamanho, data) vai para
     * 'invalidos' e a consulta devolve VAZIO; ordem/dir invalidos caem no
     * padrao da aba (nao alargam nada).
     *
     * @param array<string,mixed> $filtros
     * @return array{validos: array<string,mixed>, invalidos: list<string>}
     */
    public static function normalizarFiltros(array $filtros, string $aba): array
    {
        if (!in_array($aba, self::ABAS, true)) {
            throw new InvalidArgumentException('aba invalida');
        }

        $validos = [
            'cliente_id' => null, 'numero' => null, 'de' => null, 'ate' => null,
            'inativada_de' => null, 'inativada_ate' => null,
        ];
        $invalidos = [];

        // cliente_id: inteiro positivo
        $v = $filtros['cliente_id'] ?? null;
        if ($v !== null && $v !== '') {
            if (is_int($v) && $v >= 1) {
                $validos['cliente_id'] = $v;
            } elseif (is_string($v) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $v) === 1) {
                $validos['cliente_id'] = (int) $v;
            } else {
                $invalidos[] = 'cliente_id';
            }
        }

        // numero: charset fechado, ate 50; >= 3 caracteres = prefixo, senao exato
        $v = $filtros['numero'] ?? null;
        if ($v !== null && $v !== '') {
            $texto = is_string($v) ? trim($v) : null;
            if ($texto !== null && $texto !== '' && preg_match('/\A[A-Za-z0-9._\/-]{1,' . self::NUMERO_MAX . '}\z/D', $texto) === 1) {
                $validos['numero'] = $texto;
            } elseif ($texto !== '') {
                $invalidos[] = 'numero';
            }
        }

        $chavesData = ['de', 'ate'];
        if ($aba === 'inativas') {
            $chavesData[] = 'inativada_de';
            $chavesData[] = 'inativada_ate';
        }
        foreach ($chavesData as $chave) {
            $v = $filtros[$chave] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $data = self::dataValida($v);
            if ($data === null) {
                $invalidos[] = $chave;
            } else {
                $validos[$chave] = $data;
            }
        }

        // ordenacao: whitelist; qualquer outra coisa = padrao da aba
        $padrao = $aba === 'inativas' ? 'inativada_em' : 'criado_em';
        $ordem = $filtros['ordem'] ?? null;
        if (!is_string($ordem) || !array_key_exists($ordem, self::ORDEM_SQL) || ($ordem === 'inativada_em' && $aba !== 'inativas')) {
            $ordem = $padrao;
        }
        $dir = $filtros['dir'] ?? null;
        $dir = is_string($dir) ? strtoupper($dir) : '';
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            $dir = 'DESC';
            if ($ordem === 'numero' || $ordem === 'cliente') {
                $dir = 'ASC';
            }
        }
        $validos['ordem'] = $ordem;
        $validos['dir'] = $dir;

        return ['validos' => $validos, 'invalidos' => $invalidos];
    }

    /** Escapa curingas do LIKE ('|' e o caractere de escape: vale com ou sem NO_BACKSLASH_ESCAPES). */
    public static function escaparLike(string $texto): string
    {
        return str_replace(['|', '%', '_', '\\'], ['||', '|%', '|_', '|\\'], $texto);
    }

    /** Pagina efetiva: 1..MAX_PAGINA. */
    public static function paginaEfetiva(int $pagina): int
    {
        return max(1, min(self::MAX_PAGINA, $pagina));
    }

    /** 'Y-m-d' real (sem rolagem de calendario) entre 1970 e 2100, ou null. */
    private static function dataValida(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $v) !== 1) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            return null;
        }
        $ano = (int) $d->format('Y');

        return ($ano >= 1970 && $ano <= 2100) ? $v : null;
    }

    /**
     * WHERE + binds. Devolve null se algum filtro e invalido (resultado vazio,
     * sem tocar no banco).
     *
     * @param array<string,mixed> $filtros
     * @return array{where:string, binds:array<string,array{0:mixed,1:int}>, ordem:string, dir:string}|null
     */
    private function montarWhere(array $filtros, string $aba): ?array
    {
        $n = self::normalizarFiltros($filtros, $aba);
        if ($n['invalidos'] !== []) {
            return null;
        }
        $f = $n['validos'];

        $cond = [self::ABA_SQL[$aba]];
        $binds = [];

        if ($f['cliente_id'] !== null) {
            $cond[] = 'oc.cliente_id = :cliente_id';
            $binds['cliente_id'] = [$f['cliente_id'], PDO::PARAM_INT];
        }
        if ($f['numero'] !== null) {
            if (strlen($f['numero']) >= self::NUMERO_PREFIXO_MIN) {
                // prefixo ("x%"), nunca "%x%"
                $cond[] = "oc.numero_ordem_coleta LIKE :numero ESCAPE '|'";
                $binds['numero'] = [self::escaparLike($f['numero']) . '%', PDO::PARAM_STR];
            } else {
                $cond[] = 'oc.numero_ordem_coleta = :numero';
                $binds['numero'] = [$f['numero'], PDO::PARAM_STR];
            }
        }
        if ($f['de'] !== null) {
            $cond[] = 'oc.criado_em >= :criado_de';
            $binds['criado_de'] = [$f['de'] . ' 00:00:00', PDO::PARAM_STR];
        }
        if ($f['ate'] !== null) {
            $cond[] = 'oc.criado_em < :criado_ate';
            $binds['criado_ate'] = [self::diaSeguinte($f['ate']) . ' 00:00:00', PDO::PARAM_STR];
        }
        if ($f['inativada_de'] !== null) {
            $cond[] = 'oc.inativada_em >= :inativada_de';
            $binds['inativada_de'] = [$f['inativada_de'] . ' 00:00:00', PDO::PARAM_STR];
        }
        if ($f['inativada_ate'] !== null) {
            $cond[] = 'oc.inativada_em < :inativada_ate';
            $binds['inativada_ate'] = [self::diaSeguinte($f['inativada_ate']) . ' 00:00:00', PDO::PARAM_STR];
        }

        return ['where' => implode(' AND ', $cond), 'binds' => $binds, 'ordem' => $f['ordem'], 'dir' => $f['dir']];
    }

    private static function diaSeguinte(string $data): string
    {
        return (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d');
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    /**
     * Uma pagina (25 linhas) de ordens da aba, ja filtradas e ordenadas.
     * Colunas SEM mascara (decisao do usuario).
     *
     * @param array<string,mixed> $filtros
     * @return list<array<string,mixed>>
     */
    public function listar(array $filtros, string $aba, int $pagina): array
    {
        $m = $this->montarWhere($filtros, $aba);
        if ($m === null) {
            return [];
        }
        $pagina = self::paginaEfetiva($pagina);

        // coluna e direcao vem de constantes (whitelist), nunca da entrada
        $ordenarPor = self::ORDEM_SQL[$m['ordem']] . ' ' . ($m['dir'] === 'ASC' ? 'ASC' : 'DESC') . ', oc.id DESC';

        $sql = 'SELECT ' . self::SELECT_COLUNAS . self::FROM_SQL
            . ' WHERE ' . $m['where']
            . ' ORDER BY ' . $ordenarPor
            . ' LIMIT :limite OFFSET :deslocamento';

        $binds = $m['binds'];
        $binds['limite'] = [self::POR_PAGINA, PDO::PARAM_INT];
        $binds['deslocamento'] = [($pagina - 1) * self::POR_PAGINA, PDO::PARAM_INT];

        $linhas = $this->executar($this->pdoExterno(), $sql, $binds)->fetchAll();

        return array_map([self::class, 'tipar'], $linhas);
    }

    /**
     * Total da aba com TETO (nunca COUNT(*) irrestrito): conta no maximo
     * TETO_CONTAGEM + 1 linhas.
     *
     * @param array<string,mixed> $filtros
     * @return array{total:int, truncado:bool, rotulo:string}
     */
    public function contar(array $filtros, string $aba): array
    {
        $m = $this->montarWhere($filtros, $aba);
        if ($m === null) {
            return ['total' => 0, 'truncado' => false, 'rotulo' => '0'];
        }

        $binds = $m['binds'];
        $binds['teto'] = [self::TETO_CONTAGEM + 1, PDO::PARAM_INT];
        $n = (int) $this->executar(
            $this->pdoExterno(),
            'SELECT COUNT(*) FROM (SELECT 1' . self::FROM_SQL . ' WHERE ' . $m['where'] . ' LIMIT :teto) t',
            $binds
        )->fetchColumn();

        if ($n > self::TETO_CONTAGEM) {
            return ['total' => self::TETO_CONTAGEM, 'truncado' => true, 'rotulo' => 'mais de ' . number_format(self::TETO_CONTAGEM, 0, ',', '.')];
        }

        return ['total' => $n, 'truncado' => false, 'rotulo' => number_format($n, 0, ',', '.')];
    }

    /**
     * Contagem das 3 abas (3 consultas no maximo), com os mesmos filtros.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,array{total:int, truncado:bool, rotulo:string}>
     */
    public function contagensPorAba(array $filtros): array
    {
        $saida = [];
        foreach (self::ABAS as $aba) {
            $saida[$aba] = $this->contar($filtros, $aba);
        }

        return $saida;
    }

    /** @return array<string,mixed>|null */
    public function buscarPorId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $linha = $this->executar(
            $this->pdoExterno(),
            'SELECT ' . self::SELECT_COLUNAS . self::FROM_SQL . ' WHERE oc.id = :id',
            ['id' => [$id, PDO::PARAM_INT]]
        )->fetch();

        return $linha ? self::tipar($linha) : null;
    }

    public const MAX_CLIENTES_SELECT = 2000;

    /** Maximo de pares cliente+numero por consulta em lote (= linhas por pagina). */
    public const MAX_PARES_LOTE = self::POR_PAGINA;

    /** @return list<array{id:int, razao_social:string, cnpj:string}> */
    public function listarClientes(): array
    {
        $linhas = $this->executar(
            $this->pdoExterno(),
            'SELECT id, razao_social, cnpj FROM tb_clientes ORDER BY razao_social ASC, id ASC LIMIT ' . self::MAX_CLIENTES_SELECT
        )->fetchAll();

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'razao_social' => (string) $l['razao_social'],
            'cnpj' => (string) $l['cnpj'],
        ], $linhas);
    }

    /**
     * Um cliente por id (consulta direta, sem depender do limite do select), ou null.
     *
     * @return array{id:int, razao_social:string, cnpj:string}|null
     */
    public function clientePorId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $l = $this->executar(
            $this->pdoExterno(),
            'SELECT id, razao_social, cnpj FROM tb_clientes WHERE id = :id',
            ['id' => [$id, PDO::PARAM_INT]]
        )->fetch();

        return $l ? ['id' => (int) $l['id'], 'razao_social' => (string) $l['razao_social'], 'cnpj' => (string) $l['cnpj']] : null;
    }

    /**
     * Ids das ordens de VARIOS pares (cliente CNPJ, numero) em UMA consulta (UNION ALL
     * de subconsultas, no maximo 2 ids por par). A chave do resultado e o indice do
     * par recebido. Par com CNPJ ou numero vazios => lista vazia (sem consulta).
     *
     * @param list<array{0:string,1:string}> $pares
     * @return array<int,list<int>>
     */
    public function idsPorClienteNumeroLote(array $pares): array
    {
        $pares = array_slice(array_values($pares), 0, self::MAX_PARES_LOTE);
        $saida = [];
        $partes = [];
        $binds = [];
        foreach ($pares as $i => $par) {
            $saida[$i] = [];
            $cnpj = OrdemColetaDao::normalizarCnpj($par[0]);
            $numero = $par[1];
            if ($cnpj === '' || $numero === '') {
                continue;
            }
            $partes[] = '(SELECT ' . (int) $i . ' AS k, oc.id FROM tb_ordens_coleta oc
                INNER JOIN tb_clientes c ON c.id = oc.cliente_id
                WHERE c.cnpj = :c' . (int) $i . ' AND oc.numero_ordem_coleta = :n' . (int) $i . '
                ORDER BY oc.id ASC LIMIT 2)';
            $binds['c' . $i] = [$cnpj, PDO::PARAM_STR];
            $binds['n' . $i] = [$numero, PDO::PARAM_STR];
        }
        if ($partes === []) {
            return $saida;
        }
        $linhas = $this->executar($this->pdoExterno(), implode(' UNION ALL ', $partes), $binds)->fetchAll();
        foreach ($linhas as $l) {
            $saida[(int) $l['k']][] = (int) $l['id'];
        }
        foreach ($saida as $k => $ids) {
            sort($ids);
            $saida[$k] = $ids;
        }

        return $saida;
    }

    /**
     * Estados das ordens de um CLIENTE (CNPJ) + numero, no maximo 2 linhas
     * (basta para detectar 0 / 1 / ambiguo). Usado na resolucao manual das
     * baixas pendentes.
     *
     * @return list<string> 'ATIVA'|'INATIVA'
     */
    public function statusPorClienteNumero(string $cnpj, string $numero): array
    {
        $cnpj = OrdemColetaDao::normalizarCnpj($cnpj);
        if ($cnpj === '' || $numero === '') {
            return [];
        }
        $linhas = $this->executar(
            $this->pdoExterno(),
            'SELECT oc.status FROM tb_ordens_coleta oc
             INNER JOIN tb_clientes c ON c.id = oc.cliente_id
             WHERE c.cnpj = :cnpj AND oc.numero_ordem_coleta = :numero
             LIMIT 2',
            ['cnpj' => [$cnpj, PDO::PARAM_STR], 'numero' => [$numero, PDO::PARAM_STR]]
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $linhas);
    }

    /**
     * Ids das ordens de um CLIENTE (CNPJ) + numero, no maximo 2 (0 = nao
     * localizada, 1 = unica, 2 = ambigua). Usado so para montar o link "Abrir
     * ordem" das baixas pendentes.
     *
     * @return list<int>
     */
    public function idsPorClienteNumero(string $cnpj, string $numero): array
    {
        $cnpj = OrdemColetaDao::normalizarCnpj($cnpj);
        if ($cnpj === '' || $numero === '') {
            return [];
        }
        $linhas = $this->executar(
            $this->pdoExterno(),
            'SELECT oc.id FROM tb_ordens_coleta oc
             INNER JOIN tb_clientes c ON c.id = oc.cliente_id
             WHERE c.cnpj = :cnpj AND oc.numero_ordem_coleta = :numero
             ORDER BY oc.id ASC
             LIMIT 2',
            ['cnpj' => [$cnpj, PDO::PARAM_STR], 'numero' => [$numero, PDO::PARAM_STR]]
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $linhas);
    }

    /**
     * Registro do PDF da OC (por cnpj do cliente + numero), sem tocar no disco.
     *
     * @return array{id:int, caminho_relativo:string, sha256:string, tamanho:int}|null
     */
    public function arquivoDaOc(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $l = $this->executar(
            $this->pdoExterno(),
            'SELECT a.id, a.caminho_relativo, a.sha256, a.tamanho_bytes
             FROM tb_ordens_coleta oc
             INNER JOIN tb_clientes c ON c.id = oc.cliente_id
             INNER JOIN tb_ordem_coleta_arquivos a
                     ON a.cnpj_cliente = c.cnpj AND a.numero_ordem_coleta = oc.numero_ordem_coleta
             WHERE oc.id = :id
             LIMIT 1',
            ['id' => [$id, PDO::PARAM_INT]]
        )->fetch();

        if (!$l) {
            return null;
        }

        return [
            'id' => (int) $l['id'],
            'caminho_relativo' => (string) $l['caminho_relativo'],
            'sha256' => (string) $l['sha256'],
            'tamanho' => (int) $l['tamanho_bytes'],
        ];
    }

    // ------------------------------------------------------------------
    // Escrita atomica por ID
    // ------------------------------------------------------------------

    /** ATIVA -> INATIVA (inativada_em = agora). Ver alterarEstado(). */
    public function inativarPorId(int $id): string
    {
        return $this->alterarEstado(
            $id,
            'INATIVA',
            "UPDATE tb_ordens_coleta SET status = 'INATIVA', inativada_em = NOW() WHERE id = :id AND status = 'ATIVA'"
        );
    }

    /** INATIVA -> ATIVA (inativada_em volta a NULL). Ver alterarEstado(). */
    public function ativarPorId(int $id): string
    {
        return $this->alterarEstado(
            $id,
            'ATIVA',
            "UPDATE tb_ordens_coleta SET status = 'ATIVA', inativada_em = NULL WHERE id = :id AND status = 'INATIVA'"
        );
    }

    /**
     * rowCount() = 1 => efetivado. 0 => reler por id para distinguir
     * ja_no_estado (alguem ja deixou a ordem no estado pedido), inexistente, ou
     * estado_mudou (corrida: o estado mudou e voltou entre o UPDATE e a leitura).
     */
    private function alterarEstado(int $id, string $estadoPedido, string $sqlUpdate): string
    {
        if ($id < 1) {
            return self::R_INEXISTENTE;
        }
        $pdo = $this->pdoExterno();
        $stmt = $this->executar($pdo, $sqlUpdate, ['id' => [$id, PDO::PARAM_INT]]);
        if ($stmt->rowCount() === 1) {
            return self::R_EFETIVADO;
        }

        $status = $this->executar(
            $pdo,
            'SELECT status FROM tb_ordens_coleta WHERE id = :id',
            ['id' => [$id, PDO::PARAM_INT]]
        )->fetchColumn();

        if ($status === false) {
            return self::R_INEXISTENTE;
        }

        return (string) $status === $estadoPedido ? self::R_JA_NO_ESTADO : self::R_ESTADO_MUDOU;
    }

    // ------------------------------------------------------------------
    // Atendimentos do TOTEM (so contagem, sem dado pessoal)
    // ------------------------------------------------------------------

    /**
     * Atendimentos de Expedicao EM ANDAMENTO apontando para a OC (cnpj do
     * cliente + numero). tb_atendimento.cliente_cnpj (VARCHAR(20)) e gravado
     * verbatim de tb_clientes.cnpj (so digitos) na Expedicao. CNPJ vazio => 0.
     */
    public function atendimentosEmAndamentoDaOc(string $cnpj, string $numero): int
    {
        return $this->contarAtendimentos($cnpj, $numero, 'em_andamento');
    }

    /** Atendimentos de Expedicao CONCLUIDOS da OC (OC "ja baixada" pelo check-in). */
    public function atendimentoConcluidoDaOc(string $cnpj, string $numero): int
    {
        return $this->contarAtendimentos($cnpj, $numero, 'concluido');
    }

    /**
     * Comparacao SEM funcao na coluna (pode usar indice): o CNPJ e normalizado aqui
     * e comparado com os dois formatos conhecidos, so digitos (gravado na Expedicao,
     * a partir de tb_clientes.cnpj) e mascarado 00.000.000/0000-00 (formato do
     * cadastro local do Recebimento). Outras variacoes de mascara nao casam.
     * A consulta comeca por tipo/status/ordem_coleta (idx_status).
     */
    private function contarAtendimentos(string $cnpj, string $numero, string $status): int
    {
        $cnpj = OrdemColetaDao::normalizarCnpj($cnpj);
        if ($cnpj === '' || $numero === '') {
            return 0;
        }
        $mascarado = preg_match('/\A\d{14}\z/D', $cnpj) === 1
            ? substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2)
            : $cnpj;
        try {
            $pdo = $this->pdoTotem ?? Conexao::obter();
        } catch (PDOException) {
            throw new OrdemColetaGestaoException();
        }

        return (int) $this->executar(
            $pdo,
            "SELECT COUNT(*) FROM tb_atendimento
             WHERE tipo = 'expedicao' AND status = :status AND ordem_coleta = :numero
               AND cliente_cnpj IN (:cnpj_digitos, :cnpj_mascarado)",
            [
                'status' => [$status, PDO::PARAM_STR], 'numero' => [$numero, PDO::PARAM_STR],
                'cnpj_digitos' => [$cnpj, PDO::PARAM_STR], 'cnpj_mascarado' => [$mascarado, PDO::PARAM_STR],
            ]
        )->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Infra
    // ------------------------------------------------------------------

    /**
     * PENDENCIA REGISTRADA (revisao F4a): ConexaoGestaoColetas::obter() pode
     * lancar \RuntimeException generica FORA de executar() (que so converte
     * PDOException em OrdemColetaGestaoException). Por isso os chamadores
     * (OrdemColetaBaixaRn e os controllers da gestao) capturam \Throwable, nao
     * so OrdemColetaGestaoException. Comportamento mantido de proposito.
     */
    private function pdoExterno(): PDO
    {
        return $this->pdo ?? ConexaoGestaoColetas::obter();
    }

    /**
     * prepare + bind tipado + execute. Qualquer PDOException vira a excecao
     * generica (sem mensagem do driver, sem SQL, sem valores).
     *
     * @param array<string,array{0:mixed,1:int}> $binds
     */
    private function executar(PDO $pdo, string $sql, array $binds = []): PDOStatement
    {
        try {
            $stmt = $pdo->prepare($sql);
            foreach ($binds as $nome => [$valor, $tipo]) {
                $stmt->bindValue($nome, $valor, $tipo);
            }
            $stmt->execute();

            return $stmt;
        } catch (PDOException) {
            throw new OrdemColetaGestaoException();
        }
    }

    /**
     * @param array<string,mixed> $l
     * @return array<string,mixed>
     */
    private static function tipar(array $l): array
    {
        $l['id'] = (int) $l['id'];
        $l['cliente_id'] = (int) $l['cliente_id'];
        $l['cliente_status'] = (string) ($l['cliente_status'] ?? '');
        $l['tem_pdf'] = ((int) $l['tem_pdf']) === 1;
        $l['mesmo_numero_outros_clientes'] = (int) $l['mesmo_numero_outros_clientes'];

        return $l;
    }
}
