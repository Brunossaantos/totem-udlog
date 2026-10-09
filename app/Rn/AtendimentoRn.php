<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;

class AtendimentoRn
{
    public function __construct(
        private AtendimentoDao $atendimentoDao,
        private OrdemColetaClient $ordemColetaClient
    ) {}

    public function iniciar(int $idTotem, string $tipo, string $placa, ?int $idAceiteLgpd = null): int
    {
        return $this->atendimentoDao->criar($idTotem, $tipo, $placa, $idAceiteLgpd);
    }

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

    public function reconciliarProcessamentoAbandonado(int $idTotem, int $idAtendimentoAtual): void
    {
        $this->atendimentoDao->reconciliarProcessamentoVioApiBrAbandonado($idTotem, $idAtendimentoAtual);
    }

    public function atualizarEtapa(int $idAtendimento, string $etapa): void
    {
        $this->atendimentoDao->atualizarEtapa($idAtendimento, $etapa);
    }

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
        $crlvUfNormalizada = strtoupper(trim((string) ($dados['crlv_uf'] ?? '')));
        $crlvUfNormalizada = $crlvUfNormalizada !== '' ? $crlvUfNormalizada : null;
        $crlvRntcNormalizado = trim((string) ($dados['crlv_rntc'] ?? ''));
        $crlvRntcNormalizado = $crlvRntcNormalizado !== '' ? $crlvRntcNormalizado : null;
        $crlvTipoVeiculoNormalizado = trim((string) ($dados['crlv_tipo_veiculo'] ?? ''));
        $crlvTipoVeiculoNormalizado = $crlvTipoVeiculoNormalizado !== '' ? $crlvTipoVeiculoNormalizado : null;
        $placaNormalizada = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($dados['placa'] ?? $atendimento['placa'] ?? '')));

        $cnhOrigem = $atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO';
        $cnhStatusRevisao = $atendimento['cnh_status_revisao'] ?? 'OK';

        if (in_array($cnhOrigem, ['VIO_TRIAL', 'VIO_VALIDADO', 'VIO_API_BR'], true)) {
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

        if (in_array($crlvOrigem, ['VIO_TRIAL', 'VIO_VALIDADO', 'VIO_API_BR'], true)) {
            $snapshotPlaca = (string) ($atendimento['crlv_snapshot_placa'] ?? '');
            $snapshotExercicio = $atendimento['crlv_snapshot_exercicio'] !== null ? (int) $atendimento['crlv_snapshot_exercicio'] : null;
            $snapshotUf = $atendimento['crlv_snapshot_uf'] !== null ? (string) $atendimento['crlv_snapshot_uf'] : null;
            $snapshotRntc = $atendimento['crlv_snapshot_rntc'] !== null ? (string) $atendimento['crlv_snapshot_rntc'] : null;
            $snapshotTipoVeiculo = $atendimento['crlv_snapshot_tipo_veiculo'] !== null ? (string) $atendimento['crlv_snapshot_tipo_veiculo'] : null;

            $snapshotRntcVazio = $snapshotRntc === null || trim($snapshotRntc) === '';
            $rntcDivergiu = !$snapshotRntcVazio && $crlvRntcNormalizado !== $snapshotRntc;

            $snapshotTipoVazio = $snapshotTipoVeiculo === null || trim($snapshotTipoVeiculo) === '';
            $tipoDivergiu = !$snapshotTipoVazio && $crlvTipoVeiculoNormalizado !== $snapshotTipoVeiculo;

            $crlvDivergiu = $placaNormalizada !== $snapshotPlaca
                || $crlvAnoNormalizado !== $snapshotExercicio
                || $crlvUfNormalizada !== $snapshotUf
                || $rntcDivergiu
                || $tipoDivergiu;

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

    public function camposObrigatoriosAusentes(array $atendimento, array $dados): array
    {
        $ausentes = [];
        $texto = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';

        $placa = preg_replace('/[^A-Za-z0-9]/', '', (string) ($atendimento['placa'] ?? ''));
        if ($placa === '') {
            $ausentes['placa'] = 'placa';
        }
        if ($texto($dados['motorista_nome'] ?? null) === '') {
            $ausentes['motorista_nome'] = 'nome do motorista';
        }
        if (\Util\CpfValidador::normalizarEValidar(is_scalar($dados['motorista_cpf'] ?? null) ? (string) $dados['motorista_cpf'] : null) === null) {
            $ausentes['motorista_cpf'] = 'CPF';
        }
        $validadeNorm = $this->normalizarDataParaComparacao(is_scalar($dados['cnh_validade'] ?? null) ? $dados['cnh_validade'] : null);
        if ($validadeNorm === null
            || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $validadeNorm, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $ausentes['cnh_validade'] = 'validade da CNH';
        }
        $ano = $texto($dados['crlv_ano'] ?? null);
        if (!ctype_digit($ano) || (int) $ano <= 0) {
            $ausentes['crlv_ano'] = 'ano do CRLV';
        }
        if (!in_array(strtoupper($texto($dados['crlv_uf'] ?? null)), DocumentoRn::UFS_VALIDAS, true)) {
            $ausentes['crlv_uf'] = 'UF do CRLV';
        }
        $rntc = $texto($dados['crlv_rntc'] ?? null);
        if ($rntc === '' || strlen($rntc) > 20) {
            $ausentes['crlv_rntc'] = 'RNTRC';
        }
        $tipo = $texto($dados['crlv_tipo_veiculo'] ?? null);
        if ($tipo === '' || strlen($tipo) > 60) {
            $ausentes['crlv_tipo_veiculo'] = 'tipo de veiculo';
        }

        if (($atendimento['tipo'] ?? '') === 'expedicao') {
            if (trim((string) ($atendimento['ordem_coleta'] ?? '')) === '') {
                $ausentes['ordem_coleta'] = 'ordem de coleta';
            }
        } elseif (trim((string) ($atendimento['cliente_nome'] ?? '')) === '') {
            $ausentes['cliente'] = 'cliente';
        }

        return $ausentes;
    }

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

    public function normalizarAjudante(mixed $nome, mixed $cpf): ?array
    {
        if (($nome !== null && !is_string($nome)) || ($cpf !== null && !is_string($cpf))) {
            return null;
        }
        $nomeLimpo = $nome === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $nome));
        $cpfBruto = $cpf === null ? '' : trim($cpf);

        if ($nomeLimpo === '' && $cpfBruto === '') {
            return ['nome' => null, 'cpf' => null];
        }
        if ($nomeLimpo === '' || mb_strlen($nomeLimpo, 'UTF-8') > 150 || preg_match('/[\x00-\x1F\x7F]/', $nomeLimpo) === 1) {
            return null;
        }
        $cpfDigitos = (string) preg_replace('/\D/', '', $cpfBruto);
        if (strlen($cpfDigitos) !== 11) {
            return null;
        }

        return ['nome' => $nomeLimpo, 'cpf' => $cpfDigitos];
    }

    public function salvarAjudante(int $idAtendimento, ?string $nome, ?string $cpf): bool
    {
        return $this->atendimentoDao->salvarAjudanteSeEditavel($idAtendimento, $nome, $cpf);
    }

    public function cancelar(int $idAtendimento): bool
    {
        return $this->atendimentoDao->cancelar($idAtendimento);
    }

    public function bloquear(int $idAtendimento): bool
    {
        return $this->atendimentoDao->bloquear($idAtendimento);
    }

    public function concluirDigitalizacaoNotas(int $idAtendimento, string $novaEtapa): bool
    {
        return $this->atendimentoDao->concluirDigitalizacaoNotas($idAtendimento, $novaEtapa);
    }

    public function buscar(int $idAtendimento): ?array
    {
        return $this->atendimentoDao->buscarPorId($idAtendimento);
    }

    public function iniciarTransacao(int $timeoutLockSegundos = 5): void
    {
        $this->atendimentoDao->iniciarTransacao($timeoutLockSegundos);
    }

    public function confirmarTransacao(): void
    {
        $this->atendimentoDao->confirmarTransacao();
    }

    public function desfazerTransacao(): void
    {
        $this->atendimentoDao->desfazerTransacao();
    }

    public function buscarParaUpdate(int $idAtendimento): ?array
    {
        return $this->atendimentoDao->buscarPorIdParaUpdate($idAtendimento);
    }

    public function gravarClienteAutomaticoSeVazio(int $idAtendimento, string $nome, string $cnpj): bool
    {
        return $this->atendimentoDao->gravarClienteAutomaticoSeVazio($idAtendimento, $nome, $cnpj);
    }
}
