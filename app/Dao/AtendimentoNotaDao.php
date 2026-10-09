<?php

namespace App\Dao;

use PDO;

class AtendimentoNotaDao
{
    public function __construct(private PDO $pdo) {}

    public function inserir(int $idAtendimento, int $ordem, string $arquivo, ?string $chave, ?string $cnpjEmitente, bool $clienteIdentificado, ?string $clientUid = null): int
    {
        $parametros = [
            'id_atendimento' => $idAtendimento,
            'ordem'          => $ordem,
            'arquivo'        => $arquivo,
            'chave'          => $chave,
            'cnpj'           => $cnpjEmitente,
            'identificado'   => $clienteIdentificado ? 1 : 0,
        ];

        if ($clientUid === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO tb_atendimento_nota (id_atendimento, ordem, arquivo, chave_acesso, cnpj_emitente, cliente_identificado)
                VALUES (:id_atendimento, :ordem, :arquivo, :chave, :cnpj, :identificado)
            ');
        } else {
            $stmt = $this->pdo->prepare('
                INSERT INTO tb_atendimento_nota (id_atendimento, ordem, client_uid, arquivo, chave_acesso, cnpj_emitente, cliente_identificado)
                VALUES (:id_atendimento, :ordem, :client_uid, :arquivo, :chave, :cnpj, :identificado)
            ');
            $parametros['client_uid'] = $clientUid;
        }
        $stmt->execute($parametros);

        return (int) $this->pdo->lastInsertId();
    }

    public function contarPorAtendimento(int $idAtendimento): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tb_atendimento_nota WHERE id_atendimento = :id');
        $stmt->execute(['id' => $idAtendimento]);
        return (int) $stmt->fetchColumn();
    }

    public function existeOrdem(int $idAtendimento, int $ordem): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM tb_atendimento_nota WHERE id_atendimento = :id AND ordem = :ordem LIMIT 1
        ');
        $stmt->execute(['id' => $idAtendimento, 'ordem' => $ordem]);
        return (bool) $stmt->fetchColumn();
    }

    public function listarPorAtendimento(int $idAtendimento): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_atendimento_nota WHERE id_atendimento = :id ORDER BY ordem');
        $stmt->execute(['id' => $idAtendimento]);
        return $stmt->fetchAll();
    }

    public function algumaIdentificada(int $idAtendimento): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT nome, cnpj FROM tb_cliente c
            INNER JOIN tb_atendimento_nota n ON n.cnpj_emitente = c.cnpj
            WHERE n.id_atendimento = :id AND n.cliente_identificado = 1
            LIMIT 1
        ');
        $stmt->execute(['id' => $idAtendimento]);
        return (bool) $stmt->fetch();
    }

    public function buscarPorAtendimentoEOrdem(int $idAtendimento, int $ordem): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM tb_atendimento_nota WHERE id_atendimento = :id AND ordem = :ordem LIMIT 1
        ');
        $stmt->execute(['id' => $idAtendimento, 'ordem' => $ordem]);
        $nota = $stmt->fetch();
        return $nota ?: null;
    }

    public function algumaNotaComStatusIdentificada(int $idAtendimento): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id_nota, cnpj_emitente FROM tb_atendimento_nota
            WHERE id_atendimento = :id AND status_ocr = "IDENTIFICADA"
            LIMIT 1
        ');
        $stmt->execute(['id' => $idAtendimento]);
        $nota = $stmt->fetch();
        return $nota ?: null;
    }

    public function atualizarResultadoOcr(int $idNota, string $statusOcr, ?string $cnpjEmitente, bool $clienteIdentificado): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento_nota
            SET status_ocr = :status, processado_em = NOW(), cnpj_emitente = :cnpj, cliente_identificado = :identificado
            WHERE id_nota = :id_nota
        ');
        $stmt->execute([
            'status'       => $statusOcr,
            'cnpj'         => $cnpjEmitente,
            'identificado' => $clienteIdentificado ? 1 : 0,
            'id_nota'      => $idNota,
        ]);
    }

    public function atualizarNumero(int $idNota, string $numeroNormalizado, string $origem): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento_nota
            SET numero_nota = :numero, numero_nota_origem = :origem
            WHERE id_nota = :id_nota
        ');
        $stmt->execute([
            'numero' => $numeroNormalizado,
            'origem' => $origem,
            'id_nota' => $idNota,
        ]);

        return $stmt->rowCount() > 0;
    }


    public function iniciarTransacao(int $timeoutLockSegundos = 5): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . max(1, $timeoutLockSegundos));
    }

    public function confirmarTransacao(): void
    {
        $this->pdo->commit();
    }

    public function desfazerTransacao(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function emTransacao(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function buscarPorIdNota(int $idAtendimento, int $idNota, bool $travar = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tb_atendimento_nota WHERE id_nota = :id_nota AND id_atendimento = :id_atendimento LIMIT 1'
            . ($travar ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id_nota' => $idNota, 'id_atendimento' => $idAtendimento]);
        $nota = $stmt->fetch();
        return $nota ?: null;
    }

    public function buscarPorAtendimentoEOrdemParaUpdate(int $idAtendimento, int $ordem): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tb_atendimento_nota WHERE id_atendimento = :id AND ordem = :ordem LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $idAtendimento, 'ordem' => $ordem]);
        $nota = $stmt->fetch();
        return $nota ?: null;
    }

    public function listarAtivasParaUpdate(int $idAtendimento): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id_nota, ordem, numero_nota, status_ocr, cnpj_emitente,
                   TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS idade_segundos
            FROM tb_atendimento_nota WHERE id_atendimento = :id ORDER BY ordem FOR UPDATE
        ');
        $stmt->execute(['id' => $idAtendimento]);
        return $stmt->fetchAll();
    }

    public function primeiraOrdemLivre(int $idAtendimento): ?int
    {
        $stmt = $this->pdo->prepare('SELECT ordem FROM tb_atendimento_nota WHERE id_atendimento = :id');
        $stmt->execute(['id' => $idAtendimento]);
        $ocupadas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        for ($ordem = 1; $ordem <= 5; $ordem++) {
            if (!in_array($ordem, $ocupadas, true)) {
                return $ordem;
            }
        }

        return null;
    }

    public function gravarResultadoOcrUnico(int $idNota, int $idAtendimento, string $statusOcr, ?string $cnpjEmitente): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento_nota
            SET status_ocr = :status, processado_em = NOW(), cnpj_emitente = :cnpj, cliente_identificado = :identificado
            WHERE id_nota = :id_nota AND id_atendimento = :id_atendimento
              AND status_ocr IN ('PENDENTE', 'PROCESSANDO')
        ");
        $stmt->execute([
            'status'         => $statusOcr,
            'cnpj'           => $cnpjEmitente,
            'identificado'   => $statusOcr === 'IDENTIFICADA' ? 1 : 0,
            'id_nota'        => $idNota,
            'id_atendimento' => $idAtendimento,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function clientesDistintosIdentificados(int $idAtendimento): array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.id_cliente, c.nome, c.cnpj
            FROM tb_atendimento_nota n
            INNER JOIN tb_cliente c ON c.cnpj = n.cnpj_emitente AND c.ativo = 1
            WHERE n.id_atendimento = :id AND n.status_ocr = 'IDENTIFICADA'
            GROUP BY c.id_cliente, c.nome, c.cnpj
            ORDER BY c.id_cliente
        ");
        $stmt->execute(['id' => $idAtendimento]);
        return $stmt->fetchAll();
    }

    public function contarComStatusOcr(int $idAtendimento, string $statusOcr): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tb_atendimento_nota WHERE id_atendimento = :id AND status_ocr = :status');
        $stmt->execute(['id' => $idAtendimento, 'status' => $statusOcr]);
        return (int) $stmt->fetchColumn();
    }

    public function excluirPorId(int $idNota, int $idAtendimento): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_nota = :id_nota AND id_atendimento = :id_atendimento');
        $stmt->execute(['id_nota' => $idNota, 'id_atendimento' => $idAtendimento]);
        return $stmt->rowCount();
    }


    public function contarEmProcessamento(int $idAtendimento): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM tb_atendimento_nota
            WHERE id_atendimento = :id AND status_ocr IN ('PENDENTE', 'PROCESSANDO')
        ");
        $stmt->execute(['id' => $idAtendimento]);
        return (int) $stmt->fetchColumn();
    }

    public function buscarPorClientUid(int $idAtendimento, string $clientUid, bool $travar = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_nota, ordem, client_uid FROM tb_atendimento_nota'
            . ' WHERE id_atendimento = :id_atendimento AND client_uid = :client_uid LIMIT 1'
            . ($travar ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id_atendimento' => $idAtendimento, 'client_uid' => $clientUid]);
        $nota = $stmt->fetch();
        return $nota ?: null;
    }

    public function listarParaReconciliacao(int $idAtendimento): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id_nota, ordem, client_uid, numero_nota
            FROM tb_atendimento_nota WHERE id_atendimento = :id ORDER BY ordem
        ');
        $stmt->execute(['id' => $idAtendimento]);
        return $stmt->fetchAll();
    }
}
