<?php

namespace App\Dao;

use PDO;

/**
 * Acesso a tb_cliente (banco do TOTEM) para o cadastro da Gestao Totem (F6).
 * Separado de ClienteDao (leitura do OCR e do autocomplete): este faz CRUD.
 * Tudo com prepared statements; filtros de lista com `%`, `_` e `|` escapados
 * (ESCAPE '|'). Nunca escreve em tb_atendimento nem em tb_atendimento_nota.
 */
class ClienteGestaoDao
{
    /** Prefixo do lock nomeado (o nome real inclui MD5(DATABASE()): um lock por banco). */
    private const LOCK_CLIENTES = 'totem_gestao_clientes_';

    public const POR_PAGINA = 25;

    public const SITUACOES = ['ativos', 'inativos', 'todos'];

    public function __construct(private PDO $pdo)
    {
    }

    /** Escapa o texto para LIKE com ESCAPE '|'. */
    public static function escaparLike(string $texto): string
    {
        return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $texto);
    }

    /**
     * @param array{situacao:string,q:string} $filtros situacao na whitelist; q ja validado ('' = sem busca)
     * @return array{0:string,1:array<string,string>} [where, binds]
     */
    private function filtro(array $filtros): array
    {
        $partes = [];
        $binds = [];
        if ($filtros['situacao'] === 'ativos') {
            $partes[] = 'ativo = 1';
        } elseif ($filtros['situacao'] === 'inativos') {
            $partes[] = 'ativo = 0';
        }
        $q = $filtros['q'];
        if ($q !== '') {
            $busca = ['nome LIKE :prefixo ESCAPE \'|\''];
            $binds['prefixo'] = self::escaparLike($q) . '%';
            $digitos = (string) preg_replace('/\D/', '', $q);
            if (strlen($digitos) >= 3) {
                $busca[] = 'cnpj LIKE :parte ESCAPE \'|\'';
                $binds['parte'] = '%' . self::escaparLike($digitos) . '%';
            }
            $partes[] = '(' . implode(' OR ', $busca) . ')';
        }

        return [$partes === [] ? '' : ' WHERE ' . implode(' AND ', $partes), $binds];
    }

    /** @param array{situacao:string,q:string} $filtros */
    public function contar(array $filtros): int
    {
        [$where, $binds] = $this->filtro($filtros);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tb_cliente' . $where);
        $stmt->execute($binds);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ordenacao fixa: nome ASC (desempate pelo id).
     *
     * @param array{situacao:string,q:string} $filtros
     * @return list<array<string,mixed>>
     */
    public function listar(array $filtros, int $pagina): array
    {
        [$where, $binds] = $this->filtro($filtros);
        $stmt = $this->pdo->prepare(
            'SELECT id_cliente, nome, cnpj, ativo, criado_em FROM tb_cliente' . $where . ' ORDER BY nome ASC, id_cliente ASC LIMIT :limite OFFSET :deslocamento'
        );
        foreach ($binds as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }
        $stmt->bindValue('limite', self::POR_PAGINA, PDO::PARAM_INT);
        $stmt->bindValue('deslocamento', max(0, ($pagina - 1)) * self::POR_PAGINA, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscarPorId(int $idCliente, bool $paraAtualizar = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_cliente, nome, razao_social_normalizada, cnpj, ativo, criado_em FROM tb_cliente WHERE id_cliente = :id' . ($paraAtualizar ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $idCliente]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Clientes ATIVOS com razao normalizada nao vazia, no formato de ClienteDao::listarParaFuzzy
     * (mais `nome`, so para a tela de confirmacao de ambiguidade). `$limite` corta a leitura:
     * quem chama pede (maximo + 1) e pula a simulacao se vier acima do maximo.
     *
     * @return list<array{id_cliente:int,nome:string,razao_social:string}>
     */
    public function listarAtivosParaSimulacao(int $limite): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_cliente, nome, razao_social_normalizada AS razao_social FROM tb_cliente
             WHERE ativo = 1 AND razao_social_normalizada IS NOT NULL AND razao_social_normalizada <> ''
             ORDER BY id_cliente ASC LIMIT :limite"
        );
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function existeCnpj(string $cnpj): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM tb_cliente WHERE cnpj = :cnpj LIMIT 1 FOR UPDATE');
        $stmt->execute(['cnpj' => $cnpj]);

        return $stmt->fetchColumn() !== false;
    }

    /** Outro cliente (qualquer situacao) com a MESMA razao normalizada? `$exceto` = id ignorado (edicao). */
    public function existeRazaoNormalizada(string $razao, ?int $exceto): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM tb_cliente WHERE razao_social_normalizada = :razao AND id_cliente <> :exceto LIMIT 1 FOR UPDATE');
        $stmt->bindValue('razao', $razao);
        $stmt->bindValue('exceto', $exceto ?? 0, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** Pode lancar PDOException 1062 (CNPJ repetido): quem chama decide. */
    public function inserir(string $nome, string $razaoNormalizada, string $cnpj, bool $ativo): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES (:nome, :razao, :cnpj, :ativo)');
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('razao', $razaoNormalizada);
        $stmt->bindValue('cnpj', $cnpj);
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** O CNPJ nunca e alterado. */
    public function atualizarNome(int $idCliente, string $nome, string $razaoNormalizada): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_cliente SET nome = :nome, razao_social_normalizada = :razao WHERE id_cliente = :id');
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('razao', $razaoNormalizada);
        $stmt->bindValue('id', $idCliente, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function atualizarAtivo(int $idCliente, bool $ativo): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_cliente SET ativo = :ativo WHERE id_cliente = :id');
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('id', $idCliente, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Exclusao FISICA. @return int linhas apagadas (0 = ja nao existia) */
    public function excluir(int $idCliente): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM tb_cliente WHERE id_cliente = :id');
        $stmt->bindValue('id', $idCliente, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Atendimentos EM ANDAMENTO que dependem do cliente: `cliente_cnpj` igual ao CNPJ
     * OU alguma nota do atendimento com `cnpj_emitente` igual ao CNPJ. Os dois formatos
     * conhecidos (so digitos e mascarado) sao comparados sem funcao na coluna. So conta
     * (nenhum dado pessoal sai daqui).
     */
    public function contarAtendimentosEmAndamento(string $cnpj): int
    {
        if (preg_match('/\A\d{14}\z/D', $cnpj) !== 1) {
            return 0;
        }
        $mascarado = substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM tb_atendimento a
             WHERE a.status = 'em_andamento'
               AND (a.cliente_cnpj IN (:c1, :m1)
                    OR EXISTS (SELECT 1 FROM tb_atendimento_nota n WHERE n.id_atendimento = a.id_atendimento AND n.cnpj_emitente IN (:c2, :m2)))"
        );
        $stmt->execute(['c1' => $cnpj, 'm1' => $mascarado, 'c2' => $cnpj, 'm2' => $mascarado]);

        return (int) $stmt->fetchColumn();
    }

    /** Lock nomeado (por banco) que serializa as mutacoes de clientes. @return bool true se obteve */
    public function obterLock(int $segundos): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(CONCAT(:nome, MD5(DATABASE())), :segundos)');
        $stmt->bindValue('nome', self::LOCK_CLIENTES);
        $stmt->bindValue('segundos', $segundos, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    public function liberarLock(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(CONCAT(:nome, MD5(DATABASE())))');
            $stmt->execute(['nome' => self::LOCK_CLIENTES]);
            $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('ClienteGestaoDao: release_lock_falhou ' . get_class($e));
        }
    }
}
