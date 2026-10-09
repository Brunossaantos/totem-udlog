<?php

namespace App\Dao;

use PDO;
use Util\ConexaoGestaoColetas;

class OrdemColetaDao
{
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

    public static function normalizarCnpj(?string $cnpj): string
    {
        return preg_replace('/\D/', '', (string) $cnpj) ?? '';
    }

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

        if (count($linhas) !== 1) {
            return null;
        }

        return (string) $linhas[0];
    }
}
