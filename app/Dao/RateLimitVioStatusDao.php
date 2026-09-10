<?php

namespace App\Dao;

use PDO;

/**
 * Controle de taxa (rate limit) PROPRIO para o polling de
 * documento.php?acao=status-processamento (ver sql/migrations/007_status_processamento_assincrono_vio.sql
 * para o raciocinio completo). Chave por (id_atendimento, tipo_documento,
 * janela) — diferente de App\Dao\RateLimitOcrDao (chave por id_totem), porque
 * o polling e por documento/atendimento, nao por totem. Mesmo mecanismo
 * atomico (INSERT ... ON DUPLICATE KEY UPDATE contra a PK composta), sem
 * dependencia de Redis/APCu.
 */
class RateLimitVioStatusDao
{
    public function __construct(private PDO $pdo) {}

    public function incrementarEContar(int $idAtendimento, string $tipoDocumento, int $janela): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_rate_limit_vio_status (id_atendimento, tipo_documento, janela, contador)
            VALUES (:id_atendimento, :tipo, :janela, 1)
            ON DUPLICATE KEY UPDATE contador = contador + 1
        ');
        $stmt->execute(['id_atendimento' => $idAtendimento, 'tipo' => $tipoDocumento, 'janela' => $janela]);

        $leitura = $this->pdo->prepare('
            SELECT contador FROM tb_rate_limit_vio_status
            WHERE id_atendimento = :id_atendimento AND tipo_documento = :tipo AND janela = :janela
        ');
        $leitura->execute(['id_atendimento' => $idAtendimento, 'tipo' => $tipoDocumento, 'janela' => $janela]);

        return (int) $leitura->fetchColumn();
    }
}
