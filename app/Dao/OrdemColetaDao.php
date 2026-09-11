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
}
