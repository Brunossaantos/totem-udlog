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

    /**
     * Apaga um lote (no maximo $limiteLote linhas) de janelas expiradas de
     * tb_rate_limit_ocr — usado exclusivamente pelo cron
     * cron/limpar-rate-limit-ocr.php (demanda robustez-rate-limit-migrations,
     * 2026-09-18), NUNCA chamado dentro do caminho de uma requisicao HTTP.
     *
     * Corte por 2 criterios combinados (E logico), nunca so por tempo:
     * - atualizado_em mais antigo que o corte de retencao (24h, calculado
     *   pelo chamador e passado como unix timestamp em $corteUnixTime);
     * - janela diferente da janela ATUAL e da janela IMEDIATAMENTE ANTERIOR
     *   (ambas passadas explicitamente pelo chamador) — nunca apaga a janela
     *   em uso, mesmo que o corte de tempo por algum motivo a alcance (ex.
     *   clock skew), reforcando por construcao que o rate limit nunca perde
     *   a linha que ele proprio acabou de escrever/ler.
     *
     * LIMIT 500 por chamada — o chamador (cron) faz o loop de lotes
     * chamando este metodo repetidamente ate ele retornar 0, nunca uma unica
     * chamada sem limite (evita lock/timeout longo numa unica instrucao).
     */
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
