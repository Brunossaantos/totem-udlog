<?php

namespace App\Dao;

use PDO;

class TotemDao
{
    public function __construct(private PDO $pdo) {}

    public function buscarPorCodigo(string $codigo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_totem WHERE codigo = :codigo AND ativo = 1');
        $stmt->execute(['codigo' => $codigo]);
        return $stmt->fetch() ?: null;
    }
}
