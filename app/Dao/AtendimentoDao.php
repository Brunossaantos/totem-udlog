<?php

namespace App\Dao;

use PDO;

class AtendimentoDao
{
    public function __construct(private PDO $pdo) {}

    /**
     * $idAceiteLgpd (demanda tela-inicial-lgpd-totem, 2026-09-24): vincula
     * o atendimento ao aceite do termo LGPD que o originou (ver
     * sql/migrations/014_tb_lgpd_aceite.sql). Parametro OPCIONAL/NULLABLE
     * ao final para preservar 100% dos chamadores existentes (testes
     * manuais e demais fluxos que ainda chamam criar() com so 3
     * argumentos) — quando omitido, grava NULL (atendimento sem aceite
     * associado, mesmo comportamento de antes desta demanda).
     */
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

    // salvarDadosMotorista(int, array) removido nesta demanda — substituido
    // por salvarDadosMotoristaComOrigem() abaixo, que tambem decide o
    // rebaixamento de cnh_origem_validacao/crlv_origem_validacao (ver
    // App\Rn\AtendimentoRn::salvarDadosMotorista). Continua atendendo tanto
    // Recebimento (origem sempre NAO_VALIDADO, nunca rebaixa, comportamento
    // identico ao anterior) quanto Expedicao.

    /**
     * Persiste o resultado aprovado da validacao de CNH (VIO_TRIAL/
     * VIO_VALIDADO/VIO_API_BR/MANUAL) — chamado somente quando
     * App\Rn\DocumentoRn ja confirmou que a CNH passou em todas as regras
     * de aprovacao.
     *
     * Grava tambem o SNAPSHOT (cnh_snapshot_*) do valor exato quando a
     * origem e VIO_TRIAL/VIO_VALIDADO/VIO_API_BR (rodada corretiva de
     * 2026-09-26: VIO_API_BR incluida aqui — sem isso, uma validacao real
     * pela vio.api.br deixaria de preservar a "verdade VIO original", usada
     * depois por App\Rn\AtendimentoRn::salvarDadosMotorista para decidir o
     * rebaixamento para MANUAL na tela exp_confirma). Quando a origem e
     * MANUAL, o snapshot e gravado como NULL (nao ha "verdade VIO" a
     * preservar neste momento).
     */
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

    /**
     * Persiste o resultado aprovado da validacao de CRLV. A placa NAO e
     * regravada em tb_atendimento.placa aqui (ja existe e e a fonte de
     * comparacao, nunca sobrescrita pelo CRLV lido) — mas E gravada no
     * SNAPSHOT (crlv_snapshot_placa) quando a origem e VIO_TRIAL/
     * VIO_VALIDADO/VIO_API_BR, mesmo raciocinio de atualizarValidacaoCnh()
     * acima (VIO_API_BR incluida na rodada corretiva de 2026-09-26).
     */
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
            'crlv_rntc' => $rntc,
            'crlv_tipo_veiculo' => $tipoVeiculo,
            'origem' => $origem,
            'status_revisao' => $statusRevisao,
            'snapshot_placa' => $ehOrigemVio ? $placa : null,
            'snapshot_exercicio' => $ehOrigemVio ? $exercicio : null,
            'snapshot_uf' => $ehOrigemVio ? $uf : null,
            'snapshot_rntc' => $ehOrigemVio ? $rntc : null,
            'snapshot_tipo_veiculo' => $ehOrigemVio ? $tipoVeiculo : null,
            'id' => $id,
        ]);
    }

    /**
     * Persiste a EDICAO MANUAL dos dados de motorista/CNH/CRLV feita na tela
     * exp_confirma, com a origem/status_revisao JA DECIDIDOS pelo backend
     * (App\Rn\AtendimentoRn::salvarDadosMotorista compara contra o snapshot
     * e decide se rebaixa para MANUAL). NUNCA toca nas colunas de snapshot
     * (cnh_snapshot_* / crlv_snapshot_*) nem em cnh_validado_em/
     * crlv_validado_em — o snapshot e a "verdade original" da VIO e deve
     * sobreviver intacta a qualquer edicao manual posterior; validado_em
     * marca o momento da validacao original, nao da edicao.
     */
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

    /**
     * Mapa FECHADO de colunas por documento (cnh|crlv) para as operacoes de
     * status_processamento abaixo — nomes de coluna nunca vem de entrada do
     * usuario, so deste array fixo (interpolados na SQL com seguranca).
     */
    private const COLUNAS_STATUS_PROCESSAMENTO = [
        'cnh' => ['status' => 'cnh_status_processamento', 'iniciado_em' => 'cnh_processamento_iniciado_em', 'tentativa' => 'cnh_tentativa_id'],
        'crlv' => ['status' => 'crlv_status_processamento', 'iniciado_em' => 'crlv_processamento_iniciado_em', 'tentativa' => 'crlv_tentativa_id'],
    ];

    private function colunasStatusProcessamento(string $documento): array
    {
        return self::COLUNAS_STATUS_PROCESSAMENTO[$documento]
            ?? throw new \InvalidArgumentException("Documento invalido para status_processamento: {$documento}");
    }

    /**
     * Transicao ATOMICA para PROCESSANDO: so tem efeito se o status atual for
     * PENDENTE/ERRO, OU se estiver PROCESSANDO ha mais de $timeoutSegundos
     * (tentativa obsoleta/zumbi, tratada como se fosse ERRO). Grava um novo
     * tentativa_id junto — a escrita do resultado final (ver
     * gravarResultadoProcessamento) so tem efeito se esse mesmo tentativa_id
     * ainda estiver gravado no momento da escrita (controle de versao
     * otimista, impede que uma resposta antiga sobrescreva uma tentativa mais
     * nova). Retorna true se ESTA chamada adquiriu o direito de processar.
     */
    public function iniciarProcessamento(int $id, string $documento, string $tentativaId, int $timeoutSegundos): bool
    {
        $c = $this->colunasStatusProcessamento($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'PROCESSANDO', {$c['tentativa']} = :tentativa, {$c['iniciado_em']} = NOW()
            WHERE id_atendimento = :id
              AND (
                    {$c['status']} IN ('PENDENTE', 'ERRO')
                    OR ({$c['status']} = 'PROCESSANDO' AND {$c['iniciado_em']} < DATE_SUB(NOW(), INTERVAL :timeout SECOND))
                  )
        ");
        $stmt->execute(['tentativa' => $tentativaId, 'id' => $id, 'timeout' => $timeoutSegundos]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Grava o resultado final (CONCLUIDO ou ERRO) de UMA tentativa especifica
     * — so tem efeito se :tentativa ainda for o tentativa_id gravado no
     * banco. Se uma tentativa mais nova ja sobrescreveu o tentativa_id
     * (ex.: esta chamada e uma resposta "zumbi" atrasada), o UPDATE afeta 0
     * linhas e o resultado e descartado silenciosamente do ponto de vista do
     * banco (o chamador pode logar para debug, nunca reprocessar).
     */
    public function gravarResultadoProcessamento(int $id, string $documento, string $tentativaId, string $statusFinal): bool
    {
        $c = $this->colunasStatusProcessamento($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = :status
            WHERE id_atendimento = :id AND {$c['tentativa']} = :tentativa
        ");
        $stmt->execute(['status' => $statusFinal, 'id' => $id, 'tentativa' => $tentativaId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Marca status_processamento = CONCLUIDO diretamente, sem checagem de
     * tentativa_id — usado so pelo preenchimento MANUAL (App\Rn\DocumentoRn::
     * preencherManualCnh/Crlv), que e uma escrita sincrona e direta do
     * atendente, sem concorrencia de tentativas assincronas a proteger.
     */
    public function marcarProcessamentoConcluido(int $id, string $documento): void
    {
        $c = $this->colunasStatusProcessamento($documento);

        $stmt = $this->pdo->prepare("UPDATE tb_atendimento SET {$c['status']} = 'CONCLUIDO' WHERE id_atendimento = :id");
        $stmt->execute(['id' => $id]);
    }

    /**
     * Deteccao de PROCESSANDO obsoleto (mais de $timeoutSegundos sem
     * resolver) — usada pelo endpoint de status (leitura barata, NUNCA chama
     * o VIO) para marcar ERRO e permitir nova tentativa/fallback manual.
     * Atomica e idempotente: se ja nao estiver mais PROCESSANDO (outra
     * chamada ja tratou), nao tem efeito.
     */
    public function marcarProcessamentoObsoletoComoErro(int $id, string $documento, int $timeoutSegundos): bool
    {
        $c = $this->colunasStatusProcessamento($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'ERRO'
            WHERE id_atendimento = :id
              AND {$c['status']} = 'PROCESSANDO'
              AND {$c['iniciado_em']} < DATE_SUB(NOW(), INTERVAL :timeout SECOND)
        ");
        $stmt->execute(['id' => $id, 'timeout' => $timeoutSegundos]);

        return $stmt->rowCount() > 0;
    }

    // ============================================================
    // Fluxo assincrono vio.api.br (demanda migracao-vio-api-br-com-cache,
    // 2026-09-25) — metodos NOVOS e ADITIVOS, nunca reaproveitam/alteram os
    // metodos acima (iniciarProcessamento/gravarResultadoProcessamento/
    // marcarProcessamentoObsoletoComoErro), que permanecem intocados para
    // suportar rollback manual do fluxo antigo via App\Rn\VioDecodeClient.
    //
    // Conceitos distintos: *_tentativa_id (CAS local, ja existente) decide
    // exclusividade de tentativa; *_vio_api_id (novo) e o ID EXTERNO opaco
    // da vio.api.br, persistido so DEPOIS que o POST responde com sucesso.
    // ============================================================

    /**
     * Mapa FECHADO de colunas por documento (cnh|crlv) para o fluxo
     * vio.api.br — mesmo espirito de colunasStatusProcessamento() acima,
     * nomes de coluna nunca vem de entrada do usuario.
     */
    private const COLUNAS_VIO_API_BR = [
        'cnh' => [
            'status' => 'cnh_status_processamento',
            'tentativa' => 'cnh_tentativa_id',
            'iniciado_em' => 'cnh_processamento_iniciado_em',
            'vio_api_id' => 'cnh_vio_api_id',
            'enviado_em' => 'cnh_vio_api_enviado_em',
            'fingerprint' => 'cnh_vio_api_fingerprint',
            'fingerprint_versao' => 'cnh_vio_api_fingerprint_versao',
        ],
        'crlv' => [
            'status' => 'crlv_status_processamento',
            'tentativa' => 'crlv_tentativa_id',
            'iniciado_em' => 'crlv_processamento_iniciado_em',
            'vio_api_id' => 'crlv_vio_api_id',
            'enviado_em' => 'crlv_vio_api_enviado_em',
            'fingerprint' => 'crlv_vio_api_fingerprint',
            'fingerprint_versao' => 'crlv_vio_api_fingerprint_versao',
        ],
    ];

    private function colunasVioApiBr(string $documento): array
    {
        return self::COLUNAS_VIO_API_BR[$documento]
            ?? throw new \InvalidArgumentException("Documento invalido para fluxo vio.api.br: {$documento}");
    }

    /**
     * CAS: adquire o direito de ENVIAR (POST /api/qrcode/read) — so tem
     * efeito se o status atual for PENDENTE ou ERRO (nunca reabre
     * ENVIANDO/PROCESSANDO_LEITURA/PROCESSANDO_COMPARACAO/INDETERMINADO
     * automaticamente; ver marcarProcessamentoVioApiBrExpiradoComoIndeterminado
     * para o unico caminho de saida desses estados). So o vencedor deste CAS
     * pode chamar App\Rn\VioApiBrClient::enviarParaLeitura() — concorrencia
     * nunca gera 2 POSTs. Grava tambem o fingerprint/versao do HMAC do
     * cache (calculados a partir do QR, nunca o QR em si), necessarios mais
     * tarde para o cache-write assincrono quando a comparacao responder
     * (o QR bruto nunca sobrevive alem do calculo do fingerprint).
     */
    public function iniciarEnvioVioApiBr(int $id, string $documento, string $tentativaId, string $fingerprint, int $hmacVersao): bool
    {
        $c = $this->colunasVioApiBr($documento);

        $stmt = $this->pdo->prepare("
            UPDATE tb_atendimento
            SET {$c['status']} = 'ENVIANDO', {$c['tentativa']} = :tentativa, {$c['iniciado_em']} = NOW(),
                {$c['vio_api_id']} = NULL, {$c['enviado_em']} = NULL,
                {$c['fingerprint']} = :fingerprint, {$c['fingerprint_versao']} = :fp_versao
            WHERE id_atendimento = :id AND {$c['status']} IN ('PENDENTE', 'ERRO')
        ");
        $stmt->execute(['tentativa' => $tentativaId, 'fingerprint' => $fingerprint, 'fp_versao' => $hmacVersao, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Persiste o ID EXTERNO imediatamente apos o POST responder com
     * sucesso, ANTES de qualquer polling, e avanca para PROCESSANDO_LEITURA
     * — so tem efeito se :tentativa ainda for a vigente E o status ainda for
     * ENVIANDO (protege contra tentativa obsoleta/zumbi).
     */
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

    /**
     * Falha TECNICA no envio, SEM ambiguidade (nunca chegou a persistir ID
     * externo, nunca ha risco de cobranca duplicada) — permite nova
     * tentativa explicita (CAS acima aceita ERRO). So tem efeito se ainda
     * estiver ENVIANDO com a mesma tentativa.
     */
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

    /**
     * Falha AMBIGUA no envio (nao se sabe se o fornecedor recebeu/processou
     * a chamada) — NUNCA permite nova tentativa automatica (CAS acima nao
     * aceita INDETERMINADO). Fallback manual continua sempre disponivel via
     * preencher-manual, independente deste estado.
     */
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

    /**
     * Leitura completed, comparacao ainda pending/processing — avanca de
     * PROCESSANDO_LEITURA para PROCESSANDO_COMPARACAO. Idempotente: se ja
     * nao estiver mais em PROCESSANDO_LEITURA, nao tem efeito.
     */
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

    /**
     * Grava o resultado FINAL (CONCLUIDO/ERRO/INDETERMINADO) de uma consulta
     * de status — DUPLA checagem no WHERE (achado do security-especialista
     * no handoff): :tentativa ainda vigente (protege contra resultado
     * tardio de uma tentativa ja obsoleta/substituida) E o atendimento ainda
     * `em_andamento` (protege contra um resultado tardio alterar um
     * atendimento ja cancelado/concluido/substituido por outro caminho).
     */
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

    /**
     * Limite de DURACAO maxima de processamento apos o envio ja ter sido
     * confirmado — cobre tanto ENVIANDO preso (ex.: crash do processo PHP
     * entre o POST e a persistencia do ID externo, usando
     * *_processamento_iniciado_em) quanto PROCESSANDO_LEITURA/
     * PROCESSANDO_COMPARACAO presos ha tempo demais sem resolucao (usando
     * *_vio_api_enviado_em). NUNCA volta a PENDENTE — so avanca para
     * INDETERMINADO, nunca dispara novo POST automatico. Atomica e
     * idempotente (sem efeito se ja tiver saido desses estados).
     */
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

    /**
     * Reconciliacao de kiosk (Tarefa 3, rodada corretiva de 2026-09-26):
     * quando um NOVO atendimento e criado para o MESMO totem (novo aceite
     * LGPD apos reinicio excepcional/abandono do totem), qualquer
     * atendimento ANTERIOR do mesmo id_totem que ainda esteja 'em_andamento'
     * com uma tentativa vio.api.br ATIVA (ENVIANDO/PROCESSANDO_LEITURA/
     * PROCESSANDO_COMPARACAO) nunca mais sera consultado pelo front-end — o
     * estado do totem sempre reinicia na tela LGPD, nunca retoma um
     * id_atendimento antigo (design ja existente, nao alterado aqui). Sem
     * esta reconciliacao essas colunas ficariam PRESAS PARA SEMPRE nesses
     * estados: o unico mecanismo que as move para fora deles
     * (marcarProcessamentoVioApiBrExpiradoComoIndeterminado()) so roda
     * quando o front chama status-processamento, o que nunca mais acontece
     * para um atendimento abandonado.
     *
     * Transiciona DIRETAMENTE para INDETERMINADO (nunca PENDENTE — nunca
     * reabre a possibilidade de um novo POST automatico para uma tentativa
     * antiga). NENHUMA chamada de rede acontece aqui, so UPDATE local — por
     * construcao NUNCA gera um segundo POST/tentativa externa. Escopo
     * restrito por id_totem + status = 'em_andamento' + id_atendimento
     * DIFERENTE do recem-criado: nunca toca no atendimento novo, nunca toca
     * em atendimento ja cancelado/concluido (mesma dupla checagem de escopo
     * ja usada por gravarResultadoFinalVioApiBr()). NUNCA altera o status
     * geral do atendimento antigo (fora de escopo desta demanda — limpeza
     * mais ampla de residuo de atendimento fica para o qa-testes, com
     * protocolo proprio).
     */
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

    /**
     * Transicao ATOMICA para ENVIANDO — so tem efeito se o estado atual for
     * NAO_ENVIADO ou ERRO_REPROCESSAVEL (mesmo padrao de CAS via UPDATE...
     * WHERE + rowCount() ja usado em iniciarProcessamento()). DIFERENTE do
     * padrao VIO: ENVIO_INDETERMINADO NUNCA e elegivel para retomar o lock
     * automaticamente (nem um ENVIANDO "velho" com timeout) — exige
     * intervencao manual, nunca reenvio automatico, ate confirmacao do
     * significado real de um envio indeterminado (ver
     * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md).
     * Retorna true se ESTA chamada adquiriu o direito de enviar.
     */
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

    /**
     * Grava o resultado final de UMA tentativa especifica de envio ao
     * Talent — so tem efeito se :tentativa ainda for o tentativa_id gravado
     * no banco (mesmo controle de versao otimista de
     * gravarResultadoProcessamento()), protegendo contra uma resposta
     * atrasada de uma tentativa antiga sobrescrever uma tentativa mais nova.
     *
     * Quando $statusFinal = 'ENVIADO', tambem atualiza status/etapa_atual
     * do atendimento para concluido/impressao — mesmo efeito final que o
     * antigo AtendimentoDao::finalizar() (substituido por este metodo), para
     * nao quebrar a tela de impressao. $senha/$protocolo so sao gravados
     * quando o status final e 'ENVIADO' (NUNCA persistir corpo bruto de
     * resposta do Talent — categorizacao de erro fica em
     * App\Dao\FilaEnvioDao::ultimo_erro, sempre sanitizada).
     */
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

    /**
     * Rede de seguranca para requisicao PHP morta no meio do envio (fatal
     * error, worker matado) sem nunca chamar gravarResultadoEnvioTalent —
     * marca ENVIO_INDETERMINADO apos $timeoutSegundos em ENVIANDO. NAO torna
     * o atendimento elegivel a retry automatico (ENVIO_INDETERMINADO
     * continua fora do IN(...) de iniciarEnvioTalent) — por design, exige
     * intervencao manual/consulta ao painel do Talent antes de qualquer novo
     * envio.
     */
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

    /**
     * CAS (demanda integridade-conclusao-atendimento, 2026-09-16): status=
     * 'concluido' e terminal e imutavel do lado do motorista — a clausula
     * AND status != 'concluido' impede que este UPDATE reabra um atendimento
     * ja concluido. Retorna true se a transicao efetivamente aconteceu, OU
     * se o atendimento ja estava no estado-alvo ('cancelado', reafirmacao
     * idempotente); false so quando o atendimento estava, de fato,
     * 'concluido' — unico caso em que o Controller responde HTTP 409.
     *
     * Correcao (mesma demanda, bug encontrado pelo qa-testes em
     * 2026-09-17): sem PDO::MYSQL_ATTR_FOUND_ROWS (nao habilitado
     * globalmente — fora de escopo avaliar o impacto na conexao inteira),
     * rowCount()==0 e AMBIGUO: tanto "0 linhas casadas pelo WHERE" (o
     * atendimento existe mas esta 'concluido' — bloqueio real) quanto "1
     * linha casada mas nenhuma coluna mudou porque status ja era
     * 'cancelado'" (MySQL/PDO reportam rowCount()==0 nesse caso tambem, por
     * padrao) produzem o mesmo rowCount()==0, e antes disso as duas
     * situacoes eram tratadas identicamente como bloqueio — respondendo 409
     * mesmo quando o atendimento nao estava 'concluido'. Agora, quando o
     * UPDATE nao afeta nenhuma linha, um SELECT dedicado confirma o status
     * real antes de decidir: 'concluido' => bloqueio de verdade (false);
     * 'cancelado' (o proprio estado-alvo) => reafirmacao idempotente,
     * sucesso (true); qualquer outro residual (ex.: linha some entre o
     * UPDATE e o SELECT) => decisao conservadora de tratar como sucesso, ja
     * que nao ha evidencia de que o atendimento esteja 'concluido'.
     */
    public function cancelar(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET status = "cancelado" WHERE id_atendimento = :id AND status != "concluido"');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return $this->statusAtual($id) !== 'concluido';
    }

    /**
     * Mesmo CAS de cancelar() acima — status='concluido' tambem bloqueia
     * bloquear-excesso-notas. Mesma correcao de ambiguidade de rowCount()==0
     * descrita em cancelar(): estado-alvo aqui e 'bloqueado'.
     */
    public function bloquear(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET status = "bloqueado", etapa_atual = "balcao_portaria" WHERE id_atendimento = :id AND status != "concluido"');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return $this->statusAtual($id) !== 'concluido';
    }

    /**
     * Leitura dedicada de status usada exclusivamente por cancelar()/
     * bloquear() para desambiguar rowCount()==0 (ver comentario acima).
     */
    private function statusAtual(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM tb_atendimento WHERE id_atendimento = :id');
        $stmt->execute(['id' => $id]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    /**
     * CAS dedicado para concluirDigitalizacao() (demanda
     * integridade-conclusao-atendimento, 2026-09-16) — evita corrida entre
     * duas requisicoes quase simultaneas do mesmo atendimento: so tem efeito
     * se o atendimento ainda estiver em_andamento/digitalizacao_notas no
     * momento exato do UPDATE. AtendimentoDao::atualizarEtapa() generico NAO
     * e alterado (outros chamadores ja fazem sua propria checagem em PHP
     * antes de escrever) — este metodo e exclusivo desse caminho.
     */
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
