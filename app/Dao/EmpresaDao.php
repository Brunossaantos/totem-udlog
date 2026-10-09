<?php

namespace App\Dao;

use PDO;

class EmpresaDao
{
    public function __construct(private PDO $pdo) {}

    public function buscarPorId(int $idEmpresa): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_empresa WHERE id_empresa = :id AND ativo = 1');
        $stmt->execute(['id' => $idEmpresa]);
        return $stmt->fetch() ?: null;
    }
}
