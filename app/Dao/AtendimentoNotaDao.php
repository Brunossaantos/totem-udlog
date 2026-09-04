<?php

namespace App\Dao;

use PDO;

class AtendimentoNotaDao
{
    public function __construct(private PDO $pdo) {}

    public function inserir(int $idAtendimento, int $ordem, string $arquivo, ?string $chave, ?string $cnpjEmitente, bool $clienteIdentificado): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_atendimento_nota (id_atendimento, ordem, arquivo, chave_acesso, cnpj_emitente, cliente_identificado)
            VALUES (:id_atendimento, :ordem, :arquivo, :chave, :cnpj, :identificado)
        ');
        $stmt->execute([
            'id_atendimento' => $idAtendimento,
            'ordem'          => $ordem,
            'arquivo'        => $arquivo,
            'chave'          => $chave,
            'cnpj'           => $cnpjEmitente,
            'identificado'   => $clienteIdentificado ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function contarPorAtendimento(int $idAtendimento): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tb_atendimento_nota WHERE id_atendimento = :id');
        $stmt->execute(['id' => $idAtendimento]);
        return (int) $stmt->fetchColumn();
    }

    public function existeOrdem(int $idAtendimento, int $ordem): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM tb_atendimento_nota WHERE id_atendimento = :id AND ordem = :ordem LIMIT 1
        ');
        $stmt->execute(['id' => $idAtendimento, 'ordem' => $ordem]);
        return (bool) $stmt->fetchColumn();
    }

    public function listarPorAtendimento(int $idAtendimento): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_atendimento_nota WHERE id_atendimento = :id ORDER BY ordem');
        $stmt->execute(['id' => $idAtendimento]);
        return $stmt->fetchAll();
    }

    public function algumaIdentificada(int $idAtendimento): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT nome, cnpj FROM tb_cliente c
            INNER JOIN tb_atendimento_nota n ON n.cnpj_emitente = c.cnpj
            WHERE n.id_atendimento = :id AND n.cliente_identificado = 1
            LIMIT 1
        ');
        $stmt->execute(['id' => $idAtendimento]);
        return (bool) $stmt->fetch();
    }
}
