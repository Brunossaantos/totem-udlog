<?php

namespace App\Dao;

use PDO;

class RateLimitOcrDao
{
    public function __construct(private PDO $pdo) {}

    public function incrementarEContar(int $idTotem, int $janela): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_rate_limit_ocr (id_totem, janela, contador)
            VALUES (:id_totem, :janela, 1)
            ON DUPLICATE KEY UPDATE contador = contador + 1
        ');
        $stmt->execute(['id_totem' => $idTotem, 'janela' => $janela]);

        $stmtLeitura = $this->pdo->prepare('
            SELECT contador FROM tb_rate_limit_ocr WHERE id_totem = :id_totem AND janela = :janela
        ');
        $stmtLeitura->execute(['id_totem' => $idTotem, 'janela' => $janela]);

        return (int) $stmtLeitura->fetchColumn();
    }

    public function apagarJanelasExpiradas(
        int $corteUnixTime,
        int $janelaAtual,
        int $janelaAnterior,
        int $limiteLote
    ): int {
        $stmt = $this->pdo->prepare('
            DELETE FROM tb_rate_limit_ocr
            WHERE atualizado_em < FROM_UNIXTIME(:corte)
              AND janela != :janela_atual
              AND janela != :janela_anterior
            LIMIT ' . max(1, $limiteLote) . '
        ');
        $stmt->execute([
            'corte' => $corteUnixTime,
            'janela_atual' => $janelaAtual,
            'janela_anterior' => $janelaAnterior,
        ]);

        return $stmt->rowCount();
    }
}
