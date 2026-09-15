<?php

namespace App\Dao;

use Util\ConexaoGestaoColetas;

/**
 * Consulta direta ao banco externo `udlogo59_db_gestao_coletas`
 * (`tb_ordens_coleta` INNER JOIN `tb_clientes`), demanda
 * expedicao-consulta-ordem-coleta-teste (2026-09-11). Schema real
 * confirmado verbatim em docs/db_gestao_coletas.sql.
 *
 * Filtra `tb_clientes.status = 'ATIVO'` E `tb_ordens_coleta.status = 'ATIVA'`
 * (decisao do usuario) — a placa recebida aqui ja deve vir normalizada
 * (mesma regra sempre aplicada no backend, nunca confiando em normalizacao
 * do front).
 *
 * A conexao com o banco externo (Util\ConexaoGestaoColetas) e obtida so no
 * momento da consulta (nunca no construtor) — isso evita que acoes do
 * fluxo de Expedicao/Recebimento que NAO precisam consultar ordem de coleta
 * (ex: cancelar, salvar-etapa, finalizar) fiquem refeitas de uma conexao
 * eager a um banco externo que pode estar indisponivel.
 */
class OrdemColetaDao
{
    public function buscarPorPlacaNormalizada(string $placaNormalizada): array
    {
        $pdo = ConexaoGestaoColetas::obter();

        $stmt = $pdo->prepare('
            SELECT
                oc.numero_ordem_coleta AS numero,
                c.razao_social AS cliente_nome,
                c.cnpj AS cliente_cnpj,
                oc.criado_em AS data
            FROM tb_ordens_coleta oc
            INNER JOIN tb_clientes c ON c.id = oc.cliente_id
            WHERE oc.placa_prevista = :placa
              AND oc.status = \'ATIVA\'
              AND c.status = \'ATIVO\'
            ORDER BY oc.criado_em DESC
        ');
        $stmt->execute(['placa' => $placaNormalizada]);

        return $stmt->fetchAll();
    }

    /**
     * Marca uma ordem de coleta como INATIVA apos check-in confirmado no
     * Talent (demanda talent-doctos-finalizacao-checkin, 2026-09-14) — so
     * tem efeito se a ordem ainda estiver ATIVA (UPDATE condicional,
     * idempotente: reexecutar contra uma ordem ja INATIVA nao altera nada e
     * retorna false, o que o chamador trata como "sem efeito", nunca como
     * erro fatal isolado). Conexao aberta so no momento desta chamada, nunca
     * no bootstrap.
     */
    public function marcarInativaPorNumero(string $numero): bool
    {
        $pdo = ConexaoGestaoColetas::obter();

        $stmt = $pdo->prepare('
            UPDATE tb_ordens_coleta
            SET status = \'INATIVA\'
            WHERE numero_ordem_coleta = :numero AND status = \'ATIVA\'
        ');
        $stmt->execute(['numero' => $numero]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Consulta o status atual (ATIVA/INATIVA) de uma ordem de coleta pelo
     * numero, sem alterar nada — usada por
     * App\Controller\AtendimentoController::tentarMarcarOrdemConcluida()
     * para distinguir, quando marcarInativaPorNumero() retorna false, se foi
     * porque a ordem JA ESTAVA INATIVA (idempotente, nada a fazer, nao e
     * falha) de uma falha real (ordem ainda ATIVA e o UPDATE nao conseguiu
     * mudar, ou ordem inexistente). Retorna null se a ordem nao existir.
     */
    public function statusPorNumero(string $numero): ?string
    {
        $pdo = ConexaoGestaoColetas::obter();

        $stmt = $pdo->prepare('
            SELECT status FROM tb_ordens_coleta WHERE numero_ordem_coleta = :numero
        ');
        $stmt->execute(['numero' => $numero]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : $status;
    }
}
