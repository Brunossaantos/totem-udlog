<?php

namespace App\Dao;

use PDO;

class ClienteDao
{
    public function __construct(private PDO $pdo) {}

    public function buscarPorCnpj(string $cnpj): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_cliente WHERE cnpj = :cnpj AND ativo = 1');
        $stmt->execute(['cnpj' => $cnpj]);
        return $stmt->fetch() ?: null;
    }

    // autocomplete: busca a partir de 3 letras, por nome ou CNPJ
    public function buscarPorTermo(string $termo): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM tb_cliente
            WHERE ativo = 1 AND (nome LIKE :termo OR cnpj LIKE :termo)
            ORDER BY nome LIMIT 8
        ');
        $stmt->execute(['termo' => "%{$termo}%"]);
        return $stmt->fetchAll();
    }
}
