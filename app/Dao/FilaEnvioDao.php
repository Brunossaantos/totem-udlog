<?php

namespace App\Dao;

use PDO;

/**
 * Fila de reenvio ao Talent — backoff exponencial ja existente preservado.
 * `ultimo_erro` guarda SOMENTE a categoria interna sanitizada (ex:
 * "timeout", "erro_http", "erro_montagem_payload"), NUNCA corpo bruto de
 * requisicao/resposta, CPF/CNH, ou qualquer dado sensivel (demanda
 * integracao-talent-portaria-checkin, 2026-09-09 — corrige achado CRITICO
 * de seguranca da rodada de planejamento anterior).
 */
class FilaEnvioDao
{
    public function __construct(private PDO $pdo) {}

    /**
     * $categoriaErro e sempre uma categoria interna fechada (ver
     * App\Rn\TalentClientException) — nunca mensagem de excecao bruta.
     */
    public function registrarFalha(int $idAtendimento, string $categoriaErro): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_fila_envio (id_atendimento, tentativas, ultimo_erro, status, proxima_tentativa_em)
            VALUES (:id, 1, :erro, "pendente", DATE_ADD(NOW(), INTERVAL 5 MINUTE))
        ');
        $stmt->execute(['id' => $idAtendimento, 'erro' => $categoriaErro]);
    }

    /**
     * Item 11 do escopo: o cron so pode tentar reenviar atendimentos com
     * talent_checkin_status = 'ERRO_REPROCESSAVEL' — NUNCA
     * 'ENVIO_INDETERMINADO'/'ENVIANDO'/'ENVIADO'/'NAO_ENVIADO'. O JOIN abaixo
     * garante isso mesmo que o estado do atendimento tenha mudado por outro
     * caminho depois que a fila foi enfileirada (ex.: virou
     * ENVIO_INDETERMINADO num retry anterior, ou ja foi conciliado
     * manualmente) — nesse caso o item simplesmente nao aparece mais aqui.
     */
    public function buscarPendentes(): array
    {
        $stmt = $this->pdo->query("
            SELECT f.* FROM tb_fila_envio f
            INNER JOIN tb_atendimento a ON a.id_atendimento = f.id_atendimento
            WHERE f.status = 'pendente' AND f.proxima_tentativa_em <= NOW()
              AND a.talent_checkin_status = 'ERRO_REPROCESSAVEL'
            ORDER BY f.criado_em ASC
            LIMIT 20
        ");
        return $stmt->fetchAll();
    }

    public function marcarSucesso(int $idFila): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_fila_envio SET status = "sucesso" WHERE id_fila = :id');
        $stmt->execute(['id' => $idFila]);
    }

    // backoff exponencial: 2, 4, 8... minutos ate a 9a tentativa, depois marca como falha definitiva
    public function registrarNovaTentativa(int $idFila, string $categoriaErro): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_fila_envio
            SET tentativas = tentativas + 1,
                ultimo_erro = :erro,
                status = IF(tentativas >= 9, "falhou_definitivo", "pendente"),
                proxima_tentativa_em = DATE_ADD(NOW(), INTERVAL POW(2, tentativas) MINUTE)
            WHERE id_fila = :id
        ');
        $stmt->execute(['erro' => $categoriaErro, 'id' => $idFila]);
    }

    /**
     * Interrompe definitivamente o reenvio automatico de um item da fila —
     * usado quando um retry resulta em ENVIO_INDETERMINADO (nunca deve ser
     * tentado de novo automaticamente, exige conferencia manual no painel do
     * Talent antes de qualquer novo envio). Reaproveita o status
     * "falhou_definitivo" ja existente no ENUM (mesmo efeito pratico: sai da
     * fila de reenvio automatico).
     */
    public function interromperPorIndeterminado(int $idFila): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_fila_envio
            SET status = "falhou_definitivo", ultimo_erro = "envio_indeterminado_requer_conferencia_manual"
            WHERE id_fila = :id
        ');
        $stmt->execute(['id' => $idFila]);
    }
}
