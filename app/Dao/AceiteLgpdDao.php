<?php

namespace App\Dao;

use PDO;

class AceiteLgpdDao
{
    public function __construct(private PDO $pdo) {}

    public function criar(string $tokenHash, int $idTotem, string $versaoTermo, string $hashTermo, string $expiraEm): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_lgpd_aceite (token_hash, id_totem, versao_termo, hash_termo, expira_em, status)
            VALUES (:token_hash, :id_totem, :versao_termo, :hash_termo, :expira_em, "PENDENTE_USO")
        ');
        $stmt->execute([
            'token_hash'   => $tokenHash,
            'id_totem'     => $idTotem,
            'versao_termo' => $versaoTermo,
            'hash_termo'   => $hashTermo,
            'expira_em'    => $expiraEm,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function consumirPorHash(string $tokenHash, int $idTotem, string $versaoTermoAtual, string $hashTermoAtual): ?int
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_lgpd_aceite
            SET status = "USADO", usado_em = NOW()
            WHERE token_hash = :token_hash
              AND id_totem = :id_totem
              AND status = "PENDENTE_USO"
              AND expira_em > NOW()
              AND versao_termo = :versao_termo
              AND hash_termo = :hash_termo
        ');
        $stmt->execute([
            'token_hash'   => $tokenHash,
            'id_totem'     => $idTotem,
            'versao_termo' => $versaoTermoAtual,
            'hash_termo'   => $hashTermoAtual,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        $stmtId = $this->pdo->prepare('SELECT id_aceite FROM tb_lgpd_aceite WHERE token_hash = :token_hash');
        $stmtId->execute(['token_hash' => $tokenHash]);
        $idAceite = $stmtId->fetchColumn();

        return $idAceite === false ? null : (int) $idAceite;
    }
}
