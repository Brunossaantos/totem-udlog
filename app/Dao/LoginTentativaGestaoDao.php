<?php

namespace App\Dao;

use PDO;

class LoginTentativaGestaoDao
{
    public function __construct(private PDO $pdo)
    {
    }

    public function incrementar(string $ipHash, int $janela): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_gestao_login_tentativa (ip_hash, janela, contador) VALUES (:ip, :janela, 1)
             ON DUPLICATE KEY UPDATE contador = LEAST(contador + 1, 1000)'
        );
        $stmt->execute(['ip' => $ipHash, 'janela' => $janela]);
    }

    public function contar(string $ipHash, int $janela): int
    {
        $stmt = $this->pdo->prepare('SELECT contador FROM tb_gestao_login_tentativa WHERE ip_hash = :ip AND janela = :janela');
        $stmt->execute(['ip' => $ipHash, 'janela' => $janela]);

        return (int) $stmt->fetchColumn();
    }

    public function apagarAntigas(int $janelaMinima, int $limite = 200): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM tb_gestao_login_tentativa WHERE janela < :minima LIMIT ' . max(1, $limite));
        $stmt->execute(['minima' => $janelaMinima]);

        return $stmt->rowCount();
    }
}
