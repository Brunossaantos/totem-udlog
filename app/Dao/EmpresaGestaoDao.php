<?php

namespace App\Dao;

use PDO;

class EmpresaGestaoDao
{
    public function __construct(private PDO $pdo)
    {
    }

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

    public function buscarPorId(int $idEmpresa, bool $paraAtualizar = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_empresa, nome, cnpj, ativo, criado_em FROM tb_empresa WHERE id_empresa = :id' . ($paraAtualizar ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $idEmpresa]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

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

    public function inserir(string $nome, string $cnpj): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES (:nome, :cnpj, 1)');
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('cnpj', $cnpj);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

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

    public function excluir(int $idEmpresa): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM tb_empresa WHERE id_empresa = :id');
        $stmt->bindValue('id', $idEmpresa, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function contarTotens(int $idEmpresa): array
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(ativo = 1), 0) AS ativos, COUNT(*) AS total FROM tb_totem WHERE id_empresa = :id');
        $stmt->execute(['id' => $idEmpresa]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['ativos' => 0, 'total' => 0];

        return ['ativos' => (int) $l['ativos'], 'total' => (int) $l['total']];
    }
}
