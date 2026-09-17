<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;

class AtendimentoRn
{
    public function __construct(
        private AtendimentoDao $atendimentoDao,
        private OrdemColetaClient $ordemColetaClient
    ) {}

    public function iniciar(int $idTotem, string $tipo, string $placa): int
    {
        return $this->atendimentoDao->criar($idTotem, $tipo, $placa);
    }

    /**
     * Filtro morto removido em 2026-09-11 (demanda
     * expedicao-consulta-ordem-coleta-teste): o campo 'status' nunca existiu
     * de fato na fonte real de dados (era presumido pelo placeholder HTTP
     * antigo). O filtro de status/cliente ativo agora acontece na propria
     * consulta SQL (App\Dao\OrdemColetaDao::buscarPorPlacaNormalizada), nao
     * mais em array PHP aqui.
     */
    public function consultarOrdensAbertas(string $placa): array
    {
        return $this->ordemColetaClient->buscarPorPlaca($placa);
    }

    public function selecionarOrdem(int $idAtendimento, array $ordem): void
    {
        $this->atendimentoDao->preencherDadosOrdem($idAtendimento, $ordem);
    }

    public function definirPasta(int $idAtendimento, string $pasta): void
    {
        $this->atendimentoDao->definirPasta($idAtendimento, $pasta);
    }

    public function atualizarEtapa(int $idAtendimento, string $etapa): void
    {
        $this->atendimentoDao->atualizarEtapa($idAtendimento, $etapa);
    }

    /**
     * Grava os dados finais de motorista/CNH/CRLV confirmados na tela
     * exp_confirma da Expedicao. Se o documento (CNH e/ou CRLV) foi
     * validado pela VIO Decode (VIO_TRIAL/VIO_VALIDADO) e o atendente
     * EDITAR qualquer campo desse documento em relacao ao snapshot gravado
     * no momento da validacao (cnh_snapshot_* / crlv_snapshot_*), a origem
     * daquele documento e rebaixada para MANUAL + status_revisao =
     * PENDENTE_REVISAO. Confirmar sem editar (valor identico ao snapshot)
     * preserva a origem/status originais.
     *
     * A decisao e EXCLUSIVAMENTE do backend — qualquer campo de origem/
     * status vindo em $dados (ex.: 'cnh_origem') e IGNORADO, nunca usado
     * para decidir nada aqui.
     *
     * O registro em tb_vio_cache_cnh/tb_vio_cache_crlv NUNCA e alterado por
     * esta rotina (nenhum UPDATE/DELETE nessas tabelas aqui) — o cache
     * continua representando o que a VIO efetivamente retornou para aquele
     * QR; so o dado do ATENDIMENTO e rebaixado.
     *
     * Se o documento ja estava MANUAL (ou NAO_VALIDADO), nao ha snapshot
     * para comparar — mantem a origem/status como estavam (edicao de um
     * campo ja manual continua manual, nunca "promove" a origem sozinha).
     *
     * Design escolhido para reversao: uma vez rebaixado para MANUAL, o
     * atendimento SO volta a VIO_TRIAL/VIO_VALIDADO atraves de uma nova
     * validacao de QR bem-sucedida (App\Rn\DocumentoRn::validarCnh/
     * validarCrlv) — nao ha "auto-restauracao" so por o atendente digitar
     * de volta o valor original na tela de confirmacao, porque o snapshot
     * so e reescrito quando uma validacao VIO nova acontece, e a origem
     * so e recolocada em VIO_TRIAL/VIO_VALIDADO por essa mesma validacao.
     */
    public function salvarDadosMotorista(int $idAtendimento, array $dados): void
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if ($atendimento === null) {
            throw new \RuntimeException('Atendimento nao encontrado');
        }

        $nomeNormalizado = trim((string) ($dados['motorista_nome'] ?? ''));
        $cpfNormalizado = preg_replace('/\D/', '', (string) ($dados['motorista_cpf'] ?? ''));
        $cnhValidadeNormalizada = $this->normalizarDataParaComparacao($dados['cnh_validade'] ?? null);
        $crlvAnoNormalizado = (isset($dados['crlv_ano']) && is_numeric($dados['crlv_ano'])) ? (int) $dados['crlv_ano'] : null;
        // UF do CRLV (demanda integracao-talent-portaria-checkin, 2026-09-09)
        // — nunca texto livre no front (dropdown fechado de 27 UFs), mas
        // normalizado defensivamente aqui tambem.
        $crlvUfNormalizada = strtoupper(trim((string) ($dados['crlv_uf'] ?? '')));
        $crlvUfNormalizada = $crlvUfNormalizada !== '' ? $crlvUfNormalizada : null;
        // RNTC/tipo de veiculo (extensao integracao-talent-portaria-checkin,
        // 2026-09-10) — mesmo tratamento de normalizacao/rebaixamento ja
        // aplicado a crlv_uf.
        $crlvRntcNormalizado = trim((string) ($dados['crlv_rntc'] ?? ''));
        $crlvRntcNormalizado = $crlvRntcNormalizado !== '' ? $crlvRntcNormalizado : null;
        $crlvTipoVeiculoNormalizado = trim((string) ($dados['crlv_tipo_veiculo'] ?? ''));
        $crlvTipoVeiculoNormalizado = $crlvTipoVeiculoNormalizado !== '' ? $crlvTipoVeiculoNormalizado : null;
        // 'placa' nao e editavel hoje na tela exp_confirma (so exibida) —
        // se ausente no payload, usa a placa ja gravada do atendimento, o
        // que nunca acusa divergencia por um campo que o front nem envia.
        $placaNormalizada = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($dados['placa'] ?? $atendimento['placa'] ?? '')));

        $cnhOrigem = $atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO';
        $cnhStatusRevisao = $atendimento['cnh_status_revisao'] ?? 'OK';

        if (in_array($cnhOrigem, ['VIO_TRIAL', 'VIO_VALIDADO'], true)) {
            $snapshotNome = trim((string) ($atendimento['cnh_snapshot_nome'] ?? ''));
            $snapshotCpf = (string) ($atendimento['cnh_snapshot_cpf'] ?? '');
            $snapshotValidade = (string) ($atendimento['cnh_snapshot_validade'] ?? '');

            $cnhDivergiu = $nomeNormalizado !== $snapshotNome
                || $cpfNormalizado !== $snapshotCpf
                || (string) $cnhValidadeNormalizada !== $snapshotValidade;

            if ($cnhDivergiu) {
                $cnhOrigem = 'MANUAL';
                $cnhStatusRevisao = 'PENDENTE_REVISAO';
            }
        }

        $crlvOrigem = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
        $crlvStatusRevisao = $atendimento['crlv_status_revisao'] ?? 'OK';

        if (in_array($crlvOrigem, ['VIO_TRIAL', 'VIO_VALIDADO'], true)) {
            $snapshotPlaca = (string) ($atendimento['crlv_snapshot_placa'] ?? '');
            $snapshotExercicio = $atendimento['crlv_snapshot_exercicio'] !== null ? (int) $atendimento['crlv_snapshot_exercicio'] : null;
            $snapshotUf = $atendimento['crlv_snapshot_uf'] !== null ? (string) $atendimento['crlv_snapshot_uf'] : null;
            $snapshotRntc = $atendimento['crlv_snapshot_rntc'] !== null ? (string) $atendimento['crlv_snapshot_rntc'] : null;
            $snapshotTipoVeiculo = $atendimento['crlv_snapshot_tipo_veiculo'] !== null ? (string) $atendimento['crlv_snapshot_tipo_veiculo'] : null;

            $crlvDivergiu = $placaNormalizada !== $snapshotPlaca
                || $crlvAnoNormalizado !== $snapshotExercicio
                || $crlvUfNormalizada !== $snapshotUf
                || $crlvRntcNormalizado !== $snapshotRntc
                || $crlvTipoVeiculoNormalizado !== $snapshotTipoVeiculo;

            if ($crlvDivergiu) {
                $crlvOrigem = 'MANUAL';
                $crlvStatusRevisao = 'PENDENTE_REVISAO';
            }
        }

        $this->atendimentoDao->salvarDadosMotoristaComOrigem(
            $idAtendimento,
            $nomeNormalizado,
            $cpfNormalizado,
            $cnhValidadeNormalizada,
            $crlvAnoNormalizado,
            $crlvUfNormalizada,
            $crlvRntcNormalizado,
            $crlvTipoVeiculoNormalizado,
            $cnhOrigem,
            $cnhStatusRevisao,
            $crlvOrigem,
            $crlvStatusRevisao
        );
    }

    /**
     * Normaliza uma data recebida da tela exp_confirma para o formato Y-m-d
     * comparavel contra cnh_snapshot_validade (coluna DATE, PDO retorna
     * 'Y-m-d'). Aceita ISO (Y-m-d) e dd/mm/aaaa; qualquer outro formato e
     * mantido como veio (garante que uma divergencia real seja detectada em
     * vez de silenciosamente comparar null contra null).
     */
    private function normalizarDataParaComparacao(mixed $bruta): ?string
    {
        if ($bruta === null) {
            return null;
        }

        $bruta = trim((string) $bruta);
        if ($bruta === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bruta)) {
            return $bruta;
        }

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $bruta, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return $bruta;
    }

    public function salvarCliente(int $idAtendimento, string $nome, ?string $cnpj): void
    {
        $this->atendimentoDao->salvarCliente($idAtendimento, $nome, $cnpj);
    }

    public function salvarAjudante(int $idAtendimento, ?string $nome, ?string $cpf): void
    {
        $this->atendimentoDao->salvarAjudante($idAtendimento, $nome, $cpf);
    }

    /**
     * Repassa o CAS de App\Dao\AtendimentoDao::cancelar() — true se a
     * transicao aconteceu, false se o atendimento ja estava concluido
     * (nenhuma alteracao no banco nesse caso).
     */
    public function cancelar(int $idAtendimento): bool
    {
        return $this->atendimentoDao->cancelar($idAtendimento);
    }

    /**
     * Repassa o CAS de App\Dao\AtendimentoDao::bloquear() — mesma semantica
     * de cancelar() acima.
     */
    public function bloquear(int $idAtendimento): bool
    {
        return $this->atendimentoDao->bloquear($idAtendimento);
    }

    /**
     * Repassa o CAS dedicado de concluirDigitalizacao() — ver
     * App\Dao\AtendimentoDao::concluirDigitalizacaoNotas().
     */
    public function concluirDigitalizacaoNotas(int $idAtendimento, string $novaEtapa): bool
    {
        return $this->atendimentoDao->concluirDigitalizacaoNotas($idAtendimento, $novaEtapa);
    }

    public function buscar(int $idAtendimento): ?array
    {
        return $this->atendimentoDao->buscarPorId($idAtendimento);
    }
}
