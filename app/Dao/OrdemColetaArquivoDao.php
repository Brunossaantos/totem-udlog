<?php

namespace App\Dao;

use PDO;
use PDOException;
use Util\ConexaoGestaoColetas;

class OrdemColetaArquivoDao
{
    private const MAX_TENTATIVAS_TRANSACAO = 5;

    private const UK_CAMINHO = 'uk_oc_arquivo_caminho';

    public function __construct(private ?PDO $pdo = null) {}

    protected function pdo(): PDO
    {
        return $this->pdo ?? ConexaoGestaoColetas::obter();
    }

    public function buscarPorChave(string $cnpj, string $numero): ?array
    {
        $stmt = $this->pdo()->prepare('
            SELECT id, caminho_relativo, tamanho_bytes, sha256
            FROM tb_ordem_coleta_arquivos
            WHERE cnpj_cliente = :cnpj AND numero_ordem_coleta = :numero
        ');
        $stmt->execute(['cnpj' => $cnpj, 'numero' => $numero]);
        $linha = $stmt->fetch();
        if (!$linha) {
            return null;
        }

        return [
            'id' => (int) $linha['id'],
            'caminho_relativo' => (string) $linha['caminho_relativo'],
            'tamanho_bytes' => (int) $linha['tamanho_bytes'],
            'sha256' => (string) $linha['sha256'],
        ];
    }

    public function caminhoRegistrado(string $caminhoRelativo): bool
    {
        $stmt = $this->pdo()->prepare('
            SELECT 1 FROM tb_ordem_coleta_arquivos WHERE caminho_relativo = :caminho LIMIT 1
        ');
        $stmt->execute(['caminho' => $caminhoRelativo]);

        return $stmt->fetchColumn() !== false;
    }

    public function substituir(string $cnpj, string $numero, string $caminhoRelativo, int $tamanho, string $sha256): array
    {
        $pdo = $this->pdo();
        $ultima = null;

        for ($tentativa = 0; $tentativa < self::MAX_TENTATIVAS_TRANSACAO; $tentativa++) {
            try {
                $pdo->beginTransaction();

                $sel = $pdo->prepare('
                    SELECT id, caminho_relativo FROM tb_ordem_coleta_arquivos
                    WHERE cnpj_cliente = :cnpj AND numero_ordem_coleta = :numero
                    FOR UPDATE
                ');
                $sel->execute(['cnpj' => $cnpj, 'numero' => $numero]);
                $atual = $sel->fetch();

                if ($atual) {
                    $upd = $pdo->prepare('
                        UPDATE tb_ordem_coleta_arquivos
                        SET caminho_relativo = :caminho, tamanho_bytes = :tamanho, sha256 = :sha,
                            criado_em = NOW()
                        WHERE id = :id
                    ');
                    $upd->execute([
                        'caminho' => $caminhoRelativo,
                        'tamanho' => $tamanho,
                        'sha' => $sha256,
                        'id' => (int) $atual['id'],
                    ]);
                    $anterior = (string) $atual['caminho_relativo'];
                } else {
                    $ins = $pdo->prepare('
                        INSERT INTO tb_ordem_coleta_arquivos
                            (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256, criado_em)
                        VALUES (:cnpj, :numero, :caminho, :tamanho, :sha, NOW())
                    ');
                    $ins->execute([
                        'cnpj' => $cnpj,
                        'numero' => $numero,
                        'caminho' => $caminhoRelativo,
                        'tamanho' => $tamanho,
                        'sha' => $sha256,
                    ]);
                    $anterior = null;
                }

                $pdo->commit();

                return ['anterior' => $anterior];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $ultima = $e;
                $codigoDriver = $e->errorInfo[1] ?? null;
                if ($codigoDriver === 1062 && str_contains((string) ($e->errorInfo[2] ?? ''), self::UK_CAMINHO)) {
                    throw new CaminhoJaRegistradoException();
                }
                if ($codigoDriver !== 1062 && $codigoDriver !== 1213) {
                    throw $e;
                }
                usleep(random_int(2000, 20000));
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        throw $ultima;
    }

    public function listarExpirados(int $limite, int $dias = 15): array
    {
        $correspondentes = '
            SELECT 1 FROM tb_ordens_coleta oc
            INNER JOIN tb_clientes c ON c.id = oc.cliente_id
            WHERE c.cnpj = a.cnpj_cliente AND oc.numero_ordem_coleta = a.numero_ordem_coleta';

        $stmt = $this->pdo()->prepare("
            SELECT a.id, a.caminho_relativo
            FROM tb_ordem_coleta_arquivos a
            WHERE (
                EXISTS ({$correspondentes})
                AND NOT EXISTS ({$correspondentes}
                    AND (oc.status <> 'INATIVA' OR oc.inativada_em IS NULL
                         OR oc.inativada_em >= DATE_SUB(NOW(), INTERVAL :dias_a DAY)))
            ) OR (
                NOT EXISTS ({$correspondentes})
                AND a.criado_em < DATE_SUB(NOW(), INTERVAL :dias_b DAY)
            )
            ORDER BY a.id
            LIMIT :limite
        ");
        $stmt->bindValue('dias_a', $dias, PDO::PARAM_INT);
        $stmt->bindValue('dias_b', $dias, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $saida = [];
        foreach ($stmt->fetchAll() as $linha) {
            $saida[] = ['id' => (int) $linha['id'], 'caminho_relativo' => (string) $linha['caminho_relativo']];
        }

        return $saida;
    }

    public function excluirPorIdECaminho(int $id, string $caminhoRelativo): bool
    {
        $stmt = $this->pdo()->prepare('
            DELETE FROM tb_ordem_coleta_arquivos WHERE id = :id AND caminho_relativo = :caminho
        ');
        $stmt->execute(['id' => $id, 'caminho' => $caminhoRelativo]);

        return $stmt->rowCount() > 0;
    }
}
