<?php

namespace App\Dao;

use PDO;

class FilaEnvioDao
{
    public function __construct(private PDO $pdo) {}

    public function registrarFalha(int $idAtendimento, string $erro): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_fila_envio (id_atendimento, tentativas, ultimo_erro, status, proxima_tentativa_em)
            VALUES (:id, 1, :erro, "pendente", DATE_ADD(NOW(), INTERVAL 5 MINUTE))
        ');
        $stmt->execute(['id' => $idAtendimento, 'erro' => $erro]);
    }

    public function buscarPendentes(): array
    {
        $stmt = $this->pdo->query('
            SELECT * FROM tb_fila_envio
            WHERE status = "pendente" AND proxima_tentativa_em <= NOW()
            ORDER BY criado_em ASC
            LIMIT 20
        ');
        return $stmt->fetchAll();
    }

    public function marcarSucesso(int $idFila): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_fila_envio SET status = "sucesso" WHERE id_fila = :id');
        $stmt->execute(['id' => $idFila]);
    }

    // backoff exponencial: 2, 4, 8... minutos ate a 9a tentativa, depois marca como falha definitiva
    public function registrarNovaTentativa(int $idFila, string $erro): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_fila_envio
            SET tentativas = tentativas + 1,
                ultimo_erro = :erro,
                status = IF(tentativas >= 9, "falhou_definitivo", "pendente"),
                proxima_tentativa_em = DATE_ADD(NOW(), INTERVAL POW(2, tentativas) MINUTE)
            WHERE id_fila = :id
        ');
        $stmt->execute(['erro' => $erro, 'id' => $idFila]);
    }
}
