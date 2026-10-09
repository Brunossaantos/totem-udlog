<?php

namespace App\Dao;

use PDO;

class AtendimentoDao
{
    public function __construct(private PDO $pdo) {}

    public function criar(int $idTotem, string $tipo, string $placa, ?int $idAceiteLgpd = null): int
    {
        $codigo = $this->gerarUuid();

        $stmt = $this->pdo->prepare('
            INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, etapa_atual, status, placa, id_aceite_lgpd)
            VALUES (:codigo, :id_totem, :tipo, :etapa, :status, :placa, :id_aceite_lgpd)
        ');
        $stmt->execute([
            'codigo'         => $codigo,
            'id_totem'       => $idTotem,
            'tipo'           => $tipo,
            'etapa'          => 'placa',
            'status'         => 'em_andamento',
            'placa'          => strtoupper($placa),
            'id_aceite_lgpd' => $idAceiteLgpd,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_atendimento WHERE id_atendimento = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
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


    private const SQL_INATIVIDADE_SEGUNDOS = '
        TIMESTAMPDIFF(SECOND,
            GREATEST(
                a.atualizado_em,
                COALESCE(
                    (SELECT MAX(GREATEST(n.criado_em, COALESCE(n.processado_em, n.criado_em)))
                     FROM tb_atendimento_nota n WHERE n.id_atendimento = a.id_atendimento),
                    a.atualizado_em
                )
            ),
            NOW())';

    public function listarCandidatosAbandono(int $inatividadeSegundos, int $limite): array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.id_atendimento
            FROM tb_atendimento a
            WHERE a.status = 'em_andamento'
              AND a.talent_checkin_status IN ('NAO_ENVIADO', 'ERRO_REPROCESSAVEL')
              AND " . self::SQL_INATIVIDADE_SEGUNDOS . " >= :inatividade
            ORDER BY a.atualizado_em ASC, a.id_atendimento ASC
            LIMIT :limite
        ");
        $stmt->bindValue(':inatividade', $inatividadeSegundos, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function buscarParaAbandonoParaUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.id_atendimento, a.status, a.talent_checkin_status, a.pasta_documentos,
                   " . self::SQL_INATIVIDADE_SEGUNDOS . " AS inatividade_segundos
            FROM tb_atendimento a
            WHERE a.id_atendimento = :id
            FOR UPDATE
        ");
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function marcarAbandonado(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_atendimento = :id AND status = 'em_andamento'");
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function buscarPorIdParaUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_atendimento WHERE id_atendimento = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function gravarClienteAutomaticoSeVazio(int $id, string $nome, string $cnpj): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento SET cliente_nome = :nome, cliente_cnpj = :cnpj
            WHERE id_atendimento = :id AND (cliente_cnpj IS NULL OR TRIM(cliente_cnpj) = '')
        ");
        $stmt->execute(['nome' => $nome, 'cnpj' => $cnpj, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function atualizarEtapa(int $id, string $etapa): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET etapa_atual = :etapa WHERE id_atendimento = :id');
        $stmt->execute(['etapa' => $etapa, 'id' => $id]);
    }

    public function preencherDadosOrdem(int $id, array $ordem): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET ordem_coleta = :oc, cliente_nome = :cliente, cliente_cnpj = :cnpj, etapa_atual = "dados_encontrados"
            WHERE id_atendimento = :id
        ');
        $stmt->execute([
            'oc'      => $ordem['numero'],
            'cliente' => $ordem['cliente_nome'],
            'cnpj'    => $ordem['cliente_cnpj'],
            'id'      => $id,
        ]);
    }

    public function definirPasta(int $id, string $pastaRelativa): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET pasta_documentos = :pasta WHERE id_atendimento = :id');
        $stmt->execute(['pasta' => $pastaRelativa, 'id' => $id]);
    }


    public function atualizarValidacaoCnh(int $id, string $nome, string $cpf, string $dataValidade, string $origem, string $statusRevisao): void
    {
        $ehOrigemVio = in_array($origem, ['VIO_TRIAL', 'VIO_VALIDADO', 'VIO_API_BR'], true);

        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET motorista_nome = :nome, motorista_cpf = :cpf, cnh_validade = :cnh_validade,
                cnh_origem_validacao = :origem, cnh_status_revisao = :status_revisao, cnh_validado_em = NOW(),
                cnh_snapshot_nome = :snapshot_nome, cnh_snapshot_cpf = :snapshot_cpf,
                cnh_snapshot_validade = :snapshot_validade
            WHERE id_atendimento = :id
        ');
        $stmt->execute([
            'nome' => $nome,
            'cpf' => $cpf,
            'cnh_validade' => $dataValidade,
            'origem' => $origem,
            'status_revisao' => $statusRevisao,
            'snapshot_nome' => $ehOrigemVio ? $nome : null,
            'snapshot_cpf' => $ehOrigemVio ? $cpf : null,
            'snapshot_validade' => $ehOrigemVio ? $dataValidade : null,
            'id' => $id,
        ]);
    }

    public function atualizarValidacaoCnhVioApiBr(int $id, string $tentativaId, string $nome, string $cpf, string $dataValidade): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET motorista_nome = :nome, motorista_cpf = :cpf, cnh_validade = :cnh_validade,
                cnh_origem_validacao = 'VIO_API_BR', cnh_status_revisao = 'OK', cnh_validado_em = NOW(),
                cnh_snapshot_nome = :snapshot_nome, cnh_snapshot_cpf = :snapshot_cpf,
                cnh_snapshot_validade = :snapshot_validade
            WHERE id_atendimento = :id AND status = 'em_andamento'
              AND cnh_tentativa_id = :tentativa
              AND cnh_status_processamento IN ('PROCESSANDO_LEITURA', 'PROCESSANDO_COMPARACAO')
        ");
        $stmt->execute([
            'nome' => $nome, 'cpf' => $cpf, 'cnh_validade' => $dataValidade,
            'snapshot_nome' => $nome, 'snapshot_cpf' => $cpf, 'snapshot_validade' => $dataValidade,
            'id' => $id, 'tentativa' => $tentativaId,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $vigente = $this->pdo->prepare("SELECT 1 FROM tb_atendimento WHERE id_atendimento = :id AND status = 'em_andamento' AND cnh_tentativa_id = :tentativa AND cnh_status_processamento IN ('PROCESSANDO_LEITURA', 'PROCESSANDO_COMPARACAO')");
        $vigente->execute(['id' => $id, 'tentativa' => $tentativaId]);
        return $vigente->fetchColumn() !== false;
    }

    public function atualizarValidacaoCrlv(int $id, string $placa, int $exercicio, string $uf, string $rntc, string $tipoVeiculo, string $origem, string $statusRevisao): void
    {
        $ehOrigemVio = in_array($origem, ['VIO_TRIAL', 'VIO_VALIDADO', 'VIO_API_BR'], true);

        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET crlv_ano = :crlv_ano, crlv_uf = :crlv_uf, crlv_rntc = :crlv_rntc,
                crlv_tipo_veiculo = :crlv_tipo_veiculo, crlv_origem_validacao = :origem,
                crlv_status_revisao = :status_revisao, crlv_validado_em = NOW(),
                crlv_snapshot_placa = :snapshot_placa, crlv_snapshot_exercicio = :snapshot_exercicio,
                crlv_snapshot_uf = :snapshot_uf, crlv_snapshot_rntc = :snapshot_rntc,
                crlv_snapshot_tipo_veiculo = :snapshot_tipo_veiculo
            WHERE id_atendimento = :id
        ');
        $stmt->execute([
            'crlv_ano' => $exercicio,
            'crlv_uf' => $uf,
            'crlv_rntc' => $rntc !== '' ? $rntc : null,
            'crlv_tipo_veiculo' => $tipoVeiculo !== '' ? $tipoVeiculo : null,
            'origem' => $origem,
            'status_revisao' => $statusRevisao,
            'snapshot_placa' => $ehOrigemVio ? $placa : null,
            'snapshot_exercicio' => $ehOrigemVio ? $exercicio : null,
            'snapshot_uf' => $ehOrigemVio ? $uf : null,
            'snapshot_rntc' => ($ehOrigemVio && $rntc !== '') ? $rntc : null,
            'snapshot_tipo_veiculo' => ($ehOrigemVio && $tipoVeiculo !== '') ? $tipoVeiculo : null,
            'id' => $id,
        ]);
    }

    public function atualizarValidacaoCrlvVioApiBr(int $id, string $tentativaId, string $placa, int $exercicio, string $uf, string $rntc, string $tipoVeiculo): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET crlv_ano = :crlv_ano, crlv_uf = :crlv_uf, crlv_rntc = :crlv_rntc,
                crlv_tipo_veiculo = :crlv_tipo_veiculo, crlv_origem_validacao = 'VIO_API_BR',
                crlv_status_revisao = 'OK', crlv_validado_em = NOW(),
                crlv_snapshot_placa = :snapshot_placa, crlv_snapshot_exercicio = :snapshot_exercicio,
                crlv_snapshot_uf = :snapshot_uf, crlv_snapshot_rntc = :snapshot_rntc,
                crlv_snapshot_tipo_veiculo = :snapshot_tipo_veiculo
            WHERE id_atendimento = :id AND status = 'em_andamento'
              AND crlv_tentativa_id = :tentativa
              AND crlv_status_processamento IN ('PROCESSANDO_LEITURA', 'PROCESSANDO_COMPARACAO')
        ");
        $stmt->execute([
            'crlv_ano' => $exercicio, 'crlv_uf' => $uf, 'crlv_rntc' => $rntc !== '' ? $rntc : null,
            'crlv_tipo_veiculo' => $tipoVeiculo !== '' ? $tipoVeiculo : null, 'snapshot_placa' => $placa,
            'snapshot_exercicio' => $exercicio, 'snapshot_uf' => $uf,
            'snapshot_rntc' => $rntc !== '' ? $rntc : null, 'snapshot_tipo_veiculo' => $tipoVeiculo !== '' ? $tipoVeiculo : null,
            'id' => $id, 'tentativa' => $tentativaId,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $vigente = $this->pdo->prepare("SELECT 1 FROM tb_atendimento WHERE id_atendimento = :id AND status = 'em_andamento' AND crlv_tentativa_id = :tentativa AND crlv_status_processamento IN ('PROCESSANDO_LEITURA', 'PROCESSANDO_COMPARACAO')");
        $vigente->execute(['id' => $id, 'tentativa' => $tentativaId]);
        return $vigente->fetchColumn() !== false;
    }

    public function executarEmTransacao(callable $operacao): mixed
    {
        $propria = !$this->pdo->inTransaction();
        if ($propria) {
            $this->pdo->beginTransaction();
        }

        try {
            $resultado = $operacao();
            if ($propria) {
                $this->pdo->commit();
            }
            return $resultado;
        } catch (\Throwable $e) {
            if ($propria && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function salvarDadosMotoristaComOrigem(
        int $id,
        string $nome,
        string $cpf,
        ?string $cnhValidade,
        ?int $crlvAno,
        ?string $crlvUf,
        ?string $crlvRntc,
        ?string $crlvTipoVeiculo,
        string $cnhOrigem,
        string $cnhStatusRevisao,
        string $crlvOrigem,
        string $crlvStatusRevisao
    ): void {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET motorista_nome = :nome, motorista_cpf = :cpf, cnh_validade = :cnh_validade,
                crlv_ano = :crlv_ano, crlv_uf = :crlv_uf, crlv_rntc = :crlv_rntc,
                crlv_tipo_veiculo = :crlv_tipo_veiculo,
                cnh_origem_validacao = :cnh_origem, cnh_status_revisao = :cnh_status_revisao,
                crlv_origem_validacao = :crlv_origem, crlv_status_revisao = :crlv_status_revisao
            WHERE id_atendimento = :id
        ');
        $stmt->execute([
            'nome' => $nome,
            'cpf' => $cpf,
            'cnh_validade' => $cnhValidade,
            'crlv_ano' => $crlvAno,
            'crlv_uf' => $crlvUf,
            'crlv_rntc' => $crlvRntc,
            'crlv_tipo_veiculo' => $crlvTipoVeiculo,
            'cnh_origem' => $cnhOrigem,
            'cnh_status_revisao' => $cnhStatusRevisao,
            'crlv_origem' => $crlvOrigem,
            'crlv_status_revisao' => $crlvStatusRevisao,
            'id' => $id,
        ]);
    }

    private const COLUNAS_STATUS_PROCESSAMENTO = [
        'cnh' => ['status' => 'cnh_status_processamento', 'iniciado_em' => 'cnh_processamento_iniciado_em', 'tentativa' => 'cnh_tentativa_id'],
        'crlv' => ['status' => 'crlv_status_processamento', 'iniciado_em' => 'crlv_processamento_iniciado_em', 'tentativa' => 'crlv_tentativa_id'],
    ];

    private function colunasStatusProcessamento(string $documento): array
    {
        return self::COLUNAS_STATUS_PROCESSAMENTO[$documento]
            ?? throw new \InvalidArgumentException("Documento invalido para status_processamento: {$documento}");
    }

    public function marcarProcessamentoConcluido(int $id, string $documento): void
    {
        $c = $this->colunasStatusProcessamento($documento);

        $stmt = $this->pdo->prepare("UPDATE tb_atendimento SET {$c['status']} = 'CONCLUIDO' WHERE id_atendimento = :id");
        $stmt->execute(['id' => $id]);
    }


    private const COLUNAS_VIO_API_BR = [
        'cnh' => [
            'status' => 'cnh_status_processamento',
            'tentativa' => 'cnh_tentativa_id',
            'iniciado_em' => 'cnh_processamento_iniciado_em',
            'vio_api_id' => 'cnh_vio_api_id',
            'enviado_em' => 'cnh_vio_api_enviado_em',
            'fingerprint' => 'cnh_vio_api_fingerprint',
            'fingerprint_versao' => 'cnh_vio_api_fingerprint_versao',
            'origem' => 'cnh_origem_validacao',
            'validado_em' => 'cnh_validado_em',
        ],
        'crlv' => [
            'status' => 'crlv_status_processamento',
            'tentativa' => 'crlv_tentativa_id',
            'iniciado_em' => 'crlv_processamento_iniciado_em',
            'vio_api_id' => 'crlv_vio_api_id',
            'enviado_em' => 'crlv_vio_api_enviado_em',
            'fingerprint' => 'crlv_vio_api_fingerprint',
            'fingerprint_versao' => 'crlv_vio_api_fingerprint_versao',
            'origem' => 'crlv_origem_validacao',
            'validado_em' => 'crlv_validado_em',
        ],
    ];

    private function colunasVioApiBr(string $documento): array
    {
        return self::COLUNAS_VIO_API_BR[$documento]
            ?? throw new \InvalidArgumentException("Documento invalido para fluxo vio.api.br: {$documento}");
    }

    public function iniciarEnvioVioApiBr(int $id, string $documento, string $tentativaId): bool
    {
        $c = $this->colunasVioApiBr($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'ENVIANDO', {$c['tentativa']} = :tentativa, {$c['iniciado_em']} = NOW(),
                {$c['vio_api_id']} = NULL, {$c['enviado_em']} = NULL,
                {$c['fingerprint']} = NULL, {$c['fingerprint_versao']} = NULL
            WHERE id_atendimento = :id
              AND (
                    {$c['status']} IN ('PENDENTE', 'ERRO')
                    OR ({$c['status']} = 'CONCLUIDO' AND {$c['origem']} = 'NAO_VALIDADO' AND {$c['validado_em']} IS NULL)
                  )
        ");
        $stmt->execute(['tentativa' => $tentativaId, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function gravarIdExternoVioApiBr(int $id, string $documento, string $tentativaId, string $idExterno): bool
    {
        $c = $this->colunasVioApiBr($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'PROCESSANDO_LEITURA', {$c['vio_api_id']} = :vio_api_id, {$c['enviado_em']} = NOW()
            WHERE id_atendimento = :id AND {$c['tentativa']} = :tentativa AND {$c['status']} = 'ENVIANDO'
        ");
        $stmt->execute(['vio_api_id' => $idExterno, 'id' => $id, 'tentativa' => $tentativaId]);

        return $stmt->rowCount() > 0;
    }

    public function marcarEnvioComoErro(int $id, string $documento, string $tentativaId): bool
    {
        $c = $this->colunasVioApiBr($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'ERRO'
            WHERE id_atendimento = :id AND {$c['tentativa']} = :tentativa AND {$c['status']} = 'ENVIANDO'
        ");
        $stmt->execute(['id' => $id, 'tentativa' => $tentativaId]);

        return $stmt->rowCount() > 0;
    }

    public function marcarEnvioComoIndeterminado(int $id, string $documento, string $tentativaId): bool
    {
        $c = $this->colunasVioApiBr($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'INDETERMINADO'
            WHERE id_atendimento = :id AND {$c['tentativa']} = :tentativa AND {$c['status']} = 'ENVIANDO'
        ");
        $stmt->execute(['id' => $id, 'tentativa' => $tentativaId]);

        return $stmt->rowCount() > 0;
    }

    public function avancarParaProcessandoComparacao(int $id, string $documento): bool
    {
        $c = $this->colunasVioApiBr($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'PROCESSANDO_COMPARACAO'
            WHERE id_atendimento = :id AND {$c['status']} = 'PROCESSANDO_LEITURA'
        ");
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function gravarResultadoFinalVioApiBr(int $id, string $documento, ?string $tentativaId, string $statusFinal): bool
    {
        if ($tentativaId === null || $tentativaId === '') {
            return false;
        }

        $c = $this->colunasVioApiBr($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = :status_final
            WHERE id_atendimento = :id AND {$c['tentativa']} = :tentativa AND status = 'em_andamento'
        ");
        $stmt->execute(['status_final' => $statusFinal, 'id' => $id, 'tentativa' => $tentativaId]);

        return $stmt->rowCount() > 0;
    }

    public function marcarProcessamentoVioApiBrExpiradoComoIndeterminado(int $id, string $documento, int $duracaoMaximaSegundos): bool
    {
        $c = $this->colunasVioApiBr($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'INDETERMINADO'
            WHERE id_atendimento = :id
              AND {$c['status']} IN ('ENVIANDO', 'PROCESSANDO_LEITURA', 'PROCESSANDO_COMPARACAO')
              AND (
                    {$c['iniciado_em']} < DATE_SUB(NOW(), INTERVAL :duracao1 SECOND)
                    OR ({$c['enviado_em']} IS NOT NULL AND {$c['enviado_em']} < DATE_SUB(NOW(), INTERVAL :duracao2 SECOND))
                  )
        ");
        $stmt->execute(['id' => $id, 'duracao1' => $duracaoMaximaSegundos, 'duracao2' => $duracaoMaximaSegundos]);

        return $stmt->rowCount() > 0;
    }

    public function reconciliarProcessamentoVioApiBrAbandonado(int $idTotem, int $idAtendimentoAtual): void
    {
        foreach (['cnh', 'crlv'] as $documento) {
            $c = $this->colunasVioApiBr($documento);

            $stmt = $this->pdo->prepare("
                UPDATE tb_atendimento
                SET {$c['status']} = 'INDETERMINADO'
                WHERE id_totem = :id_totem AND id_atendimento != :id_atual
                  AND status = 'em_andamento'
                  AND {$c['status']} IN ('ENVIANDO', 'PROCESSANDO_LEITURA', 'PROCESSANDO_COMPARACAO')
            ");
            $stmt->execute(['id_totem' => $idTotem, 'id_atual' => $idAtendimentoAtual]);
        }
    }

    public function salvarCliente(int $id, string $nome, ?string $cnpj): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET cliente_nome = :nome, cliente_cnpj = :cnpj WHERE id_atendimento = :id');
        $stmt->execute(['nome' => $nome, 'cnpj' => $cnpj, 'id' => $id]);
    }

    public function salvarAjudante(int $id, ?string $nome, ?string $cpf): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET possui_ajudante = :possui, ajudante_nome = :nome, ajudante_cpf = :cpf
            WHERE id_atendimento = :id
        ');
        $stmt->execute([
            'possui' => $nome ? 1 : 0,
            'nome'   => $nome,
            'cpf'    => $cpf,
            'id'     => $id,
        ]);
    }

    public function salvarAjudanteSeEditavel(int $id, ?string $nome, ?string $cpf): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT status, talent_checkin_status FROM tb_atendimento WHERE id_atendimento = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $linha = $stmt->fetch();
            if (
                !$linha
                || $linha['status'] !== 'em_andamento'
                || !in_array($linha['talent_checkin_status'], ['NAO_ENVIADO', 'ERRO_REPROCESSAVEL'], true)
            ) {
                $this->pdo->rollBack();
                return false;
            }
            $this->salvarAjudante($id, $nome, $cpf);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function iniciarEnvioTalent(int $id, string $tentativaId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET talent_checkin_status = 'ENVIANDO', talent_tentativa_id = :tentativa, talent_status_iniciado_em = NOW()
            WHERE id_atendimento = :id AND talent_checkin_status IN ('NAO_ENVIADO', 'ERRO_REPROCESSAVEL')
        ");
        $stmt->execute(['tentativa' => $tentativaId, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function gravarResultadoEnvioTalent(int $id, string $tentativaId, string $statusFinal, ?string $senha, ?string $protocolo): bool
    {
        if ($statusFinal === 'ENVIADO') {
            $stmt = $this->pdo->prepare("
                UPDATE tb_atendimento
                SET talent_checkin_status = :status, status = 'concluido', etapa_atual = 'impressao',
                    talent_enviado_em = NOW(), talent_senha = :senha, talent_protocolo = :protocolo
                WHERE id_atendimento = :id AND talent_tentativa_id = :tentativa
            ");
            $stmt->execute([
                'status' => $statusFinal,
                'senha' => $senha,
                'protocolo' => $protocolo,
                'id' => $id,
                'tentativa' => $tentativaId,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE tb_atendimento
                SET talent_checkin_status = :status
                WHERE id_atendimento = :id AND talent_tentativa_id = :tentativa
            ");
            $stmt->execute(['status' => $statusFinal, 'id' => $id, 'tentativa' => $tentativaId]);
        }

        return $stmt->rowCount() > 0;
    }

    public function marcarEnvioTalentObsoletoComoIndeterminado(int $id, int $timeoutSegundos): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET talent_checkin_status = 'ENVIO_INDETERMINADO'
            WHERE id_atendimento = :id
              AND talent_checkin_status = 'ENVIANDO'
              AND talent_status_iniciado_em < DATE_SUB(NOW(), INTERVAL :timeout SECOND)
        ");
        $stmt->execute(['id' => $id, 'timeout' => $timeoutSegundos]);

        return $stmt->rowCount() > 0;
    }

    public function cancelar(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET status = "cancelado" WHERE id_atendimento = :id AND status != "concluido"');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return $this->statusAtual($id) !== 'concluido';
    }

    public function bloquear(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET status = "bloqueado", etapa_atual = "balcao_portaria" WHERE id_atendimento = :id AND status != "concluido"');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return $this->statusAtual($id) !== 'concluido';
    }

    private function statusAtual(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM tb_atendimento WHERE id_atendimento = :id');
        $stmt->execute(['id' => $id]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    public function concluirDigitalizacaoNotas(int $id, string $novaEtapa): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento SET etapa_atual = :nova_etapa
            WHERE id_atendimento = :id AND status = "em_andamento" AND etapa_atual = "digitalizacao_notas"
        ');
        $stmt->execute(['nova_etapa' => $novaEtapa, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function gerarUuid(): string
    {
        $dados = random_bytes(16);
        $dados[6] = chr(ord($dados[6]) & 0x0f | 0x40);
        $dados[8] = chr(ord($dados[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($dados), 4));
    }
}
