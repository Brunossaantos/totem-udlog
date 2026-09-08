<?php

namespace App\Controller;

use App\Rn\AtendimentoRn;
use App\Rn\TalentRn;
use App\Dao\AtendimentoNotaDao;
use Util\Resposta;
use Util\UploadHelper;

class AtendimentoController
{
    public function __construct(
        private AtendimentoRn $atendimentoRn,
        private TalentRn $talentRn,
        private AtendimentoNotaDao $notaDao
    ) {}

    public function iniciar(int $idTotem, array $entrada): void
    {
        $tipo = $entrada['tipo'] ?? null;
        $placa = $entrada['placa'] ?? null;

        if (!in_array($tipo, ['expedicao', 'recebimento'], true) || empty($placa)) {
            Resposta::erro('Tipo ou placa nao informados');
        }

        $idAtendimento = $this->atendimentoRn->iniciar($idTotem, $tipo, $placa);
        $pasta = UploadHelper::montarPasta($placa);
        $this->atendimentoRn->definirPasta($idAtendimento, $pasta);

        if ($tipo === 'expedicao') {
            try {
                $ordens = $this->atendimentoRn->consultarOrdensAbertas($placa);
            } catch (\Throwable $e) {
                Resposta::erro('Nao foi possivel consultar as ordens de coleta agora', 502);
            }

            if (count($ordens) === 0) {
                Resposta::erro('Nenhuma ordem de coleta em aberto para essa placa');
            }
            if (count($ordens) === 1) {
                $this->atendimentoRn->selecionarOrdem($idAtendimento, $ordens[0]);
                Resposta::sucesso(['id_atendimento' => $idAtendimento, 'proxima_tela' => 'dados_encontrados', 'dados' => $ordens[0]]);
            }
            Resposta::sucesso(['id_atendimento' => $idAtendimento, 'proxima_tela' => 'selecionar_ordem', 'ordens' => $ordens]);
        }

        Resposta::sucesso(['id_atendimento' => $idAtendimento, 'proxima_tela' => 'quantidade_notas']);
    }

    public function selecionarOrdem(array $entrada): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $ordem = $entrada['ordem'] ?? null;

        if (!$idAtendimento || !$ordem) {
            Resposta::erro('Dados incompletos');
        }

        $this->atendimentoRn->selecionarOrdem($idAtendimento, $ordem);
        Resposta::sucesso(['proxima_tela' => 'dados_encontrados', 'dados' => $ordem]);
    }

    public function salvarEtapa(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $etapa = $entrada['etapa'] ?? null;
        $dados = $entrada['dados'] ?? [];

        if (!$idAtendimento || !$etapa) {
            Resposta::erro('Dados incompletos');
        }

        switch ($etapa) {
            case 'confirmacao':
                $this->atendimentoRn->salvarDadosMotorista($idAtendimento, $dados);
                break;
            case 'cliente':
                $this->atendimentoRn->salvarCliente($idAtendimento, $dados['nome'] ?? '', $dados['cnpj'] ?? null);
                break;
            case 'ajudante':
                $this->atendimentoRn->salvarAjudante($idAtendimento, $dados['nome'] ?? null, $dados['cpf'] ?? null);
                break;
            case 'digitalizacao_notas':
                // marca a etapa formal da digitalizacao de notas do recebimento;
                // NotaController::processar exige essa etapa antes de aceitar qualquer nota.
                // Validacao de posse/tipo/status/etapa-anterior restrita a este case
                // (nao replicada para 'confirmacao'/'cliente'/'ajudante' — fora de escopo).
                $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

                if ($atendimento['tipo'] !== 'recebimento') {
                    Resposta::erro('Atendimento nao encontrado', 404);
                }
                if ($atendimento['status'] !== 'em_andamento') {
                    Resposta::erro('Atendimento nao esta em andamento');
                }
                if ($atendimento['etapa_atual'] !== 'placa') {
                    Resposta::erro('Atendimento nao esta na etapa esperada para iniciar a digitalizacao');
                }

                $this->atendimentoRn->atualizarEtapa($idAtendimento, 'digitalizacao_notas');
                break;
        }

        Resposta::sucesso(['ok' => true]);
    }

    public function bloquearPorExcessoDeNotas(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'recebimento') {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        $this->atendimentoRn->bloquear($idAtendimento);
        Resposta::sucesso(['ok' => true]);
    }

    /**
     * Conclui a etapa de digitalizacao de notas do Recebimento: valida que
     * ha pelo menos 1 e no maximo 5 notas salvas, decide a proxima tela com
     * base na identificacao (ou nao) do cliente pela leitura da nota fiscal,
     * e so entao atualiza etapa_atual no banco.
     */
    public function concluirDigitalizacao(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);

        if (!$idAtendimento) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'recebimento') {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }
        if ($atendimento['etapa_atual'] !== 'digitalizacao_notas') {
            Resposta::erro('Atendimento nao esta na etapa de digitalizacao de notas');
        }

        $totalNotas = $this->notaDao->contarPorAtendimento($idAtendimento);

        if ($totalNotas < 1) {
            Resposta::erro('Nenhuma nota fiscal foi digitalizada para esse atendimento');
        }
        if ($totalNotas > 5) {
            Resposta::erro('Limite de 5 notas fiscais excedido para esse atendimento');
        }

        // Duas fontes de verdade coexistem e precisam ser unidas (OR logico):
        // - algumaIdentificada(): fluxo ANTIGO de chave de acesso, que ainda
        //   pode gravar cliente_identificado=1/cnpj_emitente e depende de
        //   JOIN com tb_cliente LOCAL (nao removido, continua funcionando).
        // - algumaNotaComStatusIdentificada(): fluxo NOVO de OCR client-side,
        //   baseado em status_ocr = 'IDENTIFICADA' em tb_atendimento_nota, que
        //   NAO depende de tb_cliente local (origem/sincronizacao de
        //   tb_cliente e pendencia de produto separada, nao resolvida aqui).
        // Bug corrigido: antes so o metodo antigo era consultado, entao uma
        // nota identificada via OCR (sem CNPJ correspondente em tb_cliente
        // local) nunca pulava para rec_cnh.
        $identificadoViaOcr = $this->notaDao->algumaNotaComStatusIdentificada($idAtendimento);
        $identificadoViaChaveAntiga = $this->notaDao->algumaIdentificada($idAtendimento);

        if ($identificadoViaOcr || $identificadoViaChaveAntiga) {
            $etapa = 'cnh';
            $proximaTela = 'rec_cnh';
        } else {
            $etapa = 'cliente';
            $proximaTela = 'rec_cliente';
        }

        $this->atendimentoRn->atualizarEtapa($idAtendimento, $etapa);

        Resposta::sucesso(['proxima_tela' => $proximaTela, 'etapa' => $etapa]);
    }

    public function finalizar(array $entrada): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $atendimento = $this->atendimentoRn->buscar($idAtendimento);

        if (!$atendimento) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        $notas = $this->notaDao->listarPorAtendimento($idAtendimento);
        $payload = $this->talentRn->montarPayload($atendimento, $notas);

        try {
            $resultado = $this->talentRn->enviar($payload);
            Resposta::sucesso(['senha' => $resultado['senha'], 'protocolo' => $resultado['protocolo']]);
        } catch (\Throwable $e) {
            $this->talentRn->registrarFalhaParaReenvio($idAtendimento, $e->getMessage());
            // 202: aceito, mas ainda sendo processado — o totem mostra "aguarde" e o cron finaliza depois
            Resposta::erro('Nao foi possivel enviar agora — sua senha sera processada em instantes', 202);
        }
    }

    public function cancelar(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        // sem restricao de tipo/status: cancelar precisa funcionar em qualquer
        // tela/tipo/status do fluxo (Expedicao e Recebimento) — so valida posse
        $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);
        $this->atendimentoRn->cancelar($idAtendimento);
        Resposta::sucesso(['ok' => true]);
    }

    /**
     * Busca o atendimento e garante que pertence ao totem autenticado.
     * Mensagem de erro generica em qualquer caso de falha (nao existe / eh
     * de outro totem) para nao vazar a existencia de atendimento alheio.
     * Mesmo padrao usado em NotaController::buscarAtendimentoDoTotem, adaptado
     * aqui pois AtendimentoController nao tem AtendimentoDao direto no
     * construtor (usa AtendimentoRn::buscar, que ja delega ao Dao).
     */
    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoRn->buscar($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }
}
