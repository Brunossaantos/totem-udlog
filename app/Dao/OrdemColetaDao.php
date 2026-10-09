<?php

namespace App\Dao;

use PDO;
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
    /** $pdo e injetavel so para os testes; em producao a conexao e obtida sob demanda. */
    public function __construct(private ?PDO $pdo = null) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? ConexaoGestaoColetas::obter();
    }

    public function buscarPorPlacaNormalizada(string $placaNormalizada): array
    {
        $stmt = $this->pdo()->prepare('
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
     * CNPJ so com digitos. O atendimento de Expedicao guarda o CNPJ verbatim de
     * tb_clientes.cnpj (VARCHAR(14), so digitos; ver
     * AtendimentoDao::preencherDadosOrdem); normalizar aqui evita que uma
     * mascara ou espaco faca a comparacao falhar em silencio. '' = ausente.
     */
    public static function normalizarCnpj(?string $cnpj): string
    {
        return preg_replace('/\D/', '', (string) $cnpj) ?? '';
    }

    /**
     * Marca UMA ordem de coleta como INATIVA, identificada por CLIENTE (CNPJ) +
     * numero, apos check-in confirmado no Talent. O mesmo numero pode existir
     * em clientes diferentes (UNIQUE so em (cliente_id, numero_ordem_coleta)),
     * por isso NUNCA se inativa so por numero (F4a, 2026-10-08; substitui o
     * antigo marcarInativaPorNumero, removido). So tem efeito se a ordem ainda
     * estiver ATIVA (UPDATE condicional, idempotente: reexecutar contra uma
     * ordem ja INATIVA nao altera nada e retorna false). CNPJ ou numero vazios
     * (apos normalizar) => false SEM tocar no banco. rowCount() e "linhas
     * alteradas" (a conexao nao usa MYSQL_ATTR_FOUND_ROWS).
     */
    public function marcarInativaPorClienteNumero(string $cnpj, string $numero): bool
    {
        $cnpj = self::normalizarCnpj($cnpj);
        if ($cnpj === '' || $numero === '') {
            return false;
        }

        $stmt = $this->pdo()->prepare('
            UPDATE tb_ordens_coleta
            SET status = \'INATIVA\', inativada_em = NOW()
            WHERE cliente_id = (SELECT id FROM tb_clientes WHERE cnpj = :cnpj)
              AND numero_ordem_coleta = :numero
              AND status = \'ATIVA\'
        ');
        $stmt->bindValue('cnpj', $cnpj, PDO::PARAM_STR);
        $stmt->bindValue('numero', $numero, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Status atual (ATIVA/INATIVA) da ordem identificada por CLIENTE (CNPJ) +
     * numero, sem alterar nada — usado por
     * App\Controller\AtendimentoController::tentarMarcarOrdemConcluida() para
     * distinguir, quando marcarInativaPorClienteNumero() retorna false, se a
     * ordem JA ESTAVA INATIVA (idempotente, nao e falha) de uma falha real.
     * Retorna null se a ordem (ou o cliente) nao existir, se houver mais de
     * uma linha (ambiguo: nao conclusivo) ou se CNPJ/numero forem vazios.
     */
    public function statusPorClienteNumero(string $cnpj, string $numero): ?string
    {
        $cnpj = self::normalizarCnpj($cnpj);
        if ($cnpj === '' || $numero === '') {
            return null;
        }

        $stmt = $this->pdo()->prepare('
            SELECT oc.status
            FROM tb_ordens_coleta oc
            INNER JOIN tb_clientes c ON c.id = oc.cliente_id
            WHERE c.cnpj = :cnpj AND oc.numero_ordem_coleta = :numero
            LIMIT 2
        ');
        $stmt->bindValue('cnpj', $cnpj, PDO::PARAM_STR);
        $stmt->bindValue('numero', $numero, PDO::PARAM_STR);
        $stmt->execute();
        $linhas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Ambiguidade (mais de uma linha, so possivel sem a UNIQUE
        // uk_ordem_cliente): estado INDEFINIDO => null ("nao conclusivo"; o
        // chamador registra a pendencia). Nunca o status da primeira linha.
        if (count($linhas) !== 1) {
            return null;
        }

        return (string) $linhas[0];
    }
}
