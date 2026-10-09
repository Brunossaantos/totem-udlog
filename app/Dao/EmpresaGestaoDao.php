<?php

namespace App\Dao;

use PDO;

/**
 * Acesso a tb_empresa (banco do TOTEM) para o cadastro da Gestao Totem (F6).
 * Separado de EmpresaDao (leitura do Talent). Nunca altera tb_totem: as contagens de
 * totens vinculados so LEEM. A serializacao com a criacao de totens usa o MESMO lock
 * nomeado de TotemGestaoDao (ver EmpresaGestaoRn), nao um lock proprio.
 */
class EmpresaGestaoDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Todas as empresas (sao poucas), com a contagem de totens ativos e total.
     *
     * @return list<array<string,mixed>>
     */
    public function listar(): array
    {
        return $this->pdo->query(
            'SELECT e.id_empresa, e.nome, e.cnpj, e.ativo, e.criado_em,
                    COALESCE(t.ativos, 0) AS totens_ativos, COALESCE(t.total, 0) AS totens_total
             FROM tb_empresa e
             LEFT JOIN (
                 SELECT id_empresa, SUM(ativo = 1) AS ativos, COUNT(*) AS total FROM tb_totem WHERE id_empresa IS NOT NULL GROUP BY id_empresa
             ) t ON t.id_empresa = e.id_empresa
             ORDER BY e.nome ASC, e.id_empresa ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscarPorId(int $idEmpresa, bool $paraAtualizar = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_empresa, nome, cnpj, ativo, criado_em FROM tb_empresa WHERE id_empresa = :id' . ($paraAtualizar ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $idEmpresa]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Nomes de TODAS as outras empresas (qualquer situacao), para a checagem de slug
     * equivalente. `$exceto` = id ignorado (edicao); null = nenhum.
     *
     * @return list<string>
     */
    public function nomesDeOutras(?int $exceto): array
    {
        $stmt = $this->pdo->prepare('SELECT nome FROM tb_empresa WHERE id_empresa <> :exceto');
        $stmt->bindValue('exceto', $exceto ?? 0, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function existeCnpj(string $cnpj): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM tb_empresa WHERE cnpj = :cnpj LIMIT 1 FOR UPDATE');
        $stmt->execute(['cnpj' => $cnpj]);

        return $stmt->fetchColumn() !== false;
    }

    /** Pode lancar PDOException 1062 (CNPJ repetido): quem chama decide. */
    public function inserir(string $nome, string $cnpj): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES (:nome, :cnpj, 1)');
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('cnpj', $cnpj);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** O CNPJ nunca e alterado. */
    public function atualizarNome(int $idEmpresa, string $nome): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_empresa SET nome = :nome WHERE id_empresa = :id');
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('id', $idEmpresa, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function atualizarAtivo(int $idEmpresa, bool $ativo): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_empresa SET ativo = :ativo WHERE id_empresa = :id');
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('id', $idEmpresa, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Exclusao FISICA (a FK de tb_totem ainda recusa se sobrar totem). @return int linhas apagadas */
    public function excluir(int $idEmpresa): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM tb_empresa WHERE id_empresa = :id');
        $stmt->bindValue('id', $idEmpresa, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** @return array{ativos:int,total:int} totens vinculados (ativos e todos) */
    public function contarTotens(int $idEmpresa): array
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(ativo = 1), 0) AS ativos, COUNT(*) AS total FROM tb_totem WHERE id_empresa = :id');
        $stmt->execute(['id' => $idEmpresa]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['ativos' => 0, 'total' => 0];

        return ['ativos' => (int) $l['ativos'], 'total' => (int) $l['total']];
    }
}
