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

    public function consultarOrdensAbertas(string $placa): array
    {
        $ordens = $this->ordemColetaClient->buscarPorPlaca($placa);

        // so considera ordens que ainda nao foram concluidas
        return array_values(array_filter($ordens, fn($o) => ($o['status'] ?? '') !== 'concluido'));
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

    public function salvarDadosMotorista(int $idAtendimento, array $dados): void
    {
        $this->atendimentoDao->salvarDadosMotorista($idAtendimento, $dados);
    }

    public function salvarCliente(int $idAtendimento, string $nome, ?string $cnpj): void
    {
        $this->atendimentoDao->salvarCliente($idAtendimento, $nome, $cnpj);
    }

    public function salvarAjudante(int $idAtendimento, ?string $nome, ?string $cpf): void
    {
        $this->atendimentoDao->salvarAjudante($idAtendimento, $nome, $cpf);
    }

    public function cancelar(int $idAtendimento): void
    {
        $this->atendimentoDao->cancelar($idAtendimento);
    }

    public function bloquear(int $idAtendimento): void
    {
        $this->atendimentoDao->bloquear($idAtendimento);
    }

    public function buscar(int $idAtendimento): ?array
    {
        return $this->atendimentoDao->buscarPorId($idAtendimento);
    }
}
