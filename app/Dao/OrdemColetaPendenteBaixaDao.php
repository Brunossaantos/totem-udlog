<?php

namespace App\Dao;

use PDO;

/**
 * Registro de auditoria interno (banco do TOTEM, tabela
 * tb_ordem_coleta_pendente_baixa) para o cenario "Talent aceitou o
 * check-in, mas o UPDATE de status da ordem de coleta para INATIVA (banco
 * externo de gestao de coletas) falhou ou lancou excecao" — demanda
 * talent-doctos-finalizacao-checkin, 2026-09-14. NUNCA persiste payload,
 * corpo bruto, CPF/CNH ou token — so os 2 identificadores + timestamps (ver
 * sql/migrations/012_talent_doctos_finalizacao_checkin.sql).
 *
 * Sem cron de reconciliacao automatica nesta demanda — so o registro de
 * auditoria (decisao do usuario, fora de escopo).
 */
class OrdemColetaPendenteBaixaDao
{
    public function __construct(private PDO $pdo) {}

    /**
     * Idempotente por design: UNIQUE(id_atendimento) impede duplicar a
     * linha se a mesma falha ocorrer de novo (ex: replay do CAS de
     * idempotencia do Talent) — ON DUPLICATE KEY UPDATE e um no-op
     * proposital (nao atualiza criado_em, so evita erro de constraint).
     */
    public function registrar(int $idAtendimento, string $numeroOrdemColeta): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_ordem_coleta_pendente_baixa (id_atendimento, numero_ordem_coleta)
            VALUES (:id_atendimento, :numero)
            ON DUPLICATE KEY UPDATE criado_em = criado_em
        ');
        $stmt->execute([
            'id_atendimento' => $idAtendimento,
            'numero' => $numeroOrdemColeta,
        ]);
    }
}
