<?php

namespace App\Dao;

use PDO;

class AtendimentoNotaDao
{
    public function __construct(private PDO $pdo) {}

    public function inserir(int $idAtendimento, int $ordem, string $arquivo, ?string $chave, ?string $cnpjEmitente, bool $clienteIdentificado): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_atendimento_nota (id_atendimento, ordem, arquivo, chave_acesso, cnpj_emitente, cliente_identificado)
            VALUES (:id_atendimento, :ordem, :arquivo, :chave, :cnpj, :identificado)
        ');
        $stmt->execute([
            'id_atendimento' => $idAtendimento,
            'ordem'          => $ordem,
            'arquivo'        => $arquivo,
            'chave'          => $chave,
            'cnpj'           => $cnpjEmitente,
            'identificado'   => $clienteIdentificado ? 1 : 0,
        ]);

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

    /**
     * Busca a nota de uma ordem especifica dentro de um atendimento —
     * usado para validar posse (IDOR) antes de aceitar o resultado de
     * identificar-cliente: a nota (id_atendimento + ordem) precisa existir
     * e pertencer ao atendimento do totem autenticado.
     */
    public function buscarPorAtendimentoEOrdem(int $idAtendimento, int $ordem): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM tb_atendimento_nota WHERE id_atendimento = :id AND ordem = :ordem LIMIT 1
        ');
        $stmt->execute(['id' => $idAtendimento, 'ordem' => $ordem]);
        $nota = $stmt->fetch();
        return $nota ?: null;
    }

    /**
     * Novo (demanda recebimento-leitura-notas): checa se o ATENDIMENTO ja
     * tem alguma nota com status_ocr = IDENTIFICADA, baseado inteiramente na
     * nova coluna status_ocr — NAO reaproveita algumaIdentificada() acima,
     * que depende de JOIN com tb_cliente local (fonte diferente, pendencia
     * de produto em aberto). Retorna a nota (id_nota, cnpj_emitente) para o
     * short-circuit do endpoint identificar-cliente, ou null se nenhuma.
     */
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

    /**
     * Persiste o resultado da identificacao de cliente (status_ocr,
     * processado_em, e os campos ja existentes cnpj_emitente/
     * cliente_identificado, reaproveitados sem nova coluna) via PDO
     * prepared statement.
     */
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

    /**
     * Grava o numero da NF-e (ja normalizado pelo chamador —
     * App\Controller\NotaController::definirNumero — nunca confia em
     * normalizacao do front) para uma nota especifica. $origem e sempre
     * 'OCR' ou 'MANUAL'. A UNIQUE KEY uk_atendimento_numero_nota
     * (id_atendimento, numero_nota) e a defesa final contra duplicado
     * dentro do mesmo atendimento — o chamador captura a violacao de
     * constraint (PDOException) e traduz para um erro amigavel, nunca deixa
     * vazar a excecao bruta.
     */
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
}
