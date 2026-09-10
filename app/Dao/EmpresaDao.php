<?php

namespace App\Dao;

use PDO;

/**
 * Empresa/armazem do Talent (Portaria/Checkin) — tb_empresa, vinculada ao
 * totem via tb_totem.id_empresa (demanda integracao-talent-portaria-checkin,
 * 2026-09-09). cnpjArmazem do payload do Talent vem EXCLUSIVAMENTE daqui,
 * nunca do frontend.
 */
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
