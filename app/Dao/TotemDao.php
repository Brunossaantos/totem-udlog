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

    /**
     * Usado por App\Controller\AtendimentoController::finalizar() para
     * resolver tb_totem.id_empresa (cnpjArmazem do Talent) a partir do totem
     * ja autenticado por Util\Auth::validarTotem() — nunca de entrada do
     * frontend.
     */
    public function buscarPorId(int $idTotem): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_totem WHERE id_totem = :id AND ativo = 1');
        $stmt->execute(['id' => $idTotem]);
        return $stmt->fetch() ?: null;
    }
}
