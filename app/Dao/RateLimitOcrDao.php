<?php

namespace App\Dao;

use PDO;

/**
 * Controle de taxa (rate limit) por totem para o endpoint
 * nota.php?acao=identificar-cliente (ver sql/migrations/004_tb_rate_limit_ocr.sql
 * para o raciocinio completo). Janela fixa de 60s, incremento atomico via
 * INSERT ... ON DUPLICATE KEY UPDATE contra a PK composta (id_totem, janela)
 * — o proprio InnoDB serializa requisicoes concorrentes do mesmo totem na
 * mesma janela, sem necessidade de transacao/SELECT ... FOR UPDATE
 * explicito. Sem dependencia de Redis/APCu (nao confirmados no Hostgator).
 */
class RateLimitOcrDao
{
    public function __construct(private PDO $pdo) {}

    /**
     * Registra mais uma chamada do totem na janela informada e retorna o
     * contador ja atualizado (incluindo esta chamada). Operacao atomica:
     * uma unica instrucao SQL faz o incremento (ou a criacao da linha, se
     * for a primeira chamada do totem nessa janela).
     */
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
}
