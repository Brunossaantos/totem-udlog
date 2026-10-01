<?php

namespace App\Dao;

use PDO;

class AtendimentoNotaDao
{
    public function __construct(private PDO $pdo) {}

    /**
     * $clientUid (rodada corretiva F7, 2026-10-01) e OPCIONAL: so quando
     * informado a coluna client_uid (migration 018) entra no INSERT, entao o
     * caminho sem uid (front antigo) continua identico ao anterior e nao
     * depende da migration. A UNIQUE (id_atendimento, client_uid) e a defesa
     * final contra duas notas com o mesmo uid no mesmo atendimento.
     */
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

    // ============================================================
    // demanda hardening-revisao-notas-e-cliente (2026-09-30) -- lock e
    // transacao, id_nota, avaliacao de cliente derivada, exclusao. Todos os
    // metodos novos usam prepared statements. Metodos antigos acima
    // permanecem inalterados (contrato aditivo).
    // ============================================================

    /** Transacao curta sobre a MESMA conexao dos demais metodos deste DAO. */
    public function iniciarTransacao(int $timeoutLockSegundos = 5): void
    {
        $this->pdo->beginTransaction();
        // valor inteiro fixo (nunca entrada de usuario); SET nao aceita
        // placeholder. Timeout curto: espera de lock longa travaria o totem.
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

    /**
     * Busca a nota por id_nota DENTRO do atendimento (nunca so por id_nota:
     * id_nota de outro atendimento nao pode ser alcancado). $travar = true
     * usa SELECT ... FOR UPDATE (so valido dentro de transacao).
     */
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

    /**
     * Notas ATIVAS (existentes) do atendimento, travadas (FOR UPDATE) --
     * unica leitura autorizada para decidir conclusao/cliente sob lock.
     * Nao carrega chave_acesso nem arquivo (minimo necessario).
     *
     * @return array<int, array{id_nota:mixed, ordem:mixed, numero_nota:mixed, status_ocr:mixed, cnpj_emitente:mixed, idade_segundos:mixed}>
     */
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

    /**
     * Primeira ordem livre de 1 a 5 (null se as 5 estiverem ocupadas).
     * Chamar sob lock do atendimento.
     */
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

    /**
     * Escrita UNICA do resultado de identificacao de UMA nota: so grava se a
     * nota ainda e do atendimento e continua PENDENTE/PROCESSANDO. Retorna
     * false (0 linhas) quando o resultado ja foi gravado -- o chamador rele
     * e devolve o valor gravado (idempotente, nunca sobrescreve).
     */
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

    /**
     * Clientes DISTINTOS (por id_cliente, nunca pela string do CNPJ)
     * identificados pelas notas ativas do atendimento, sempre contra
     * tb_cliente ATIVA (ativo = 1, unica allowlist oficial -- decisao D2).
     * Nota IDENTIFICADA cujo cliente foi desativado/removido nao conta.
     *
     * @return array<int, array{id_cliente:mixed, nome:mixed, cnpj:mixed}>
     */
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

    /**
     * Exclusao fisica da linha (sem exclusao logica: os UNIQUE por ordem e
     * por numero_nota precisam ser liberados para reutilizacao). Retorna o
     * numero de linhas removidas (o chamador exige exatamente 1).
     */
    public function excluirPorId(int $idNota, int $idAtendimento): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_nota = :id_nota AND id_atendimento = :id_atendimento');
        $stmt->execute(['id_nota' => $idNota, 'id_atendimento' => $idAtendimento]);
        return $stmt->rowCount();
    }

    // ============================================================
    // rodada corretiva (2026-10-01): F1 (notas em processamento) e F7
    // (uid idempotente + reconciliacao)
    // ============================================================

    /**
     * Notas do atendimento ainda sem resultado terminal de OCR (PENDENTE ou
     * PROCESSANDO) -- F1. Total (inclui as ja expiradas).
     */
    public function contarEmProcessamento(int $idAtendimento): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM tb_atendimento_nota
            WHERE id_atendimento = :id AND status_ocr IN ('PENDENTE', 'PROCESSANDO')
        ");
        $stmt->execute(['id' => $idAtendimento]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Nota do atendimento pelo uid gerado pelo front (F7). SEMPRE escopada ao
     * atendimento: o mesmo uid em outro atendimento nunca e alcancado.
     */
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

    /**
     * Reconciliacao (somente leitura): todas as notas ativas do atendimento
     * com o minimo necessario (id_nota, ordem, uid, numero_nota). Nunca
     * arquivo, chave, CNPJ ou status interno.
     *
     * @return array<int, array{id_nota:mixed, ordem:mixed, client_uid:mixed, numero_nota:mixed}>
     */
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
