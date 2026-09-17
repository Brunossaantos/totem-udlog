<?php

namespace App\Controller;

use App\Rn\AtendimentoRn;
use App\Rn\TalentRn;
use App\Rn\DocumentoRn;
use App\Rn\OrdemColetaClient;
use App\Dao\AtendimentoNotaDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Dao\OrdemColetaPendenteBaixaDao;
use Util\Resposta;
use Util\UploadHelper;

class AtendimentoController
{
    public function __construct(
        private AtendimentoRn $atendimentoRn,
        private TalentRn $talentRn,
        private AtendimentoNotaDao $notaDao,
        private ?DocumentoRn $documentoRn = null,
        private ?TotemDao $totemDao = null,
        private ?EmpresaDao $empresaDao = null,
        private ?OrdemColetaClient $ordemColetaClient = null,
        private ?OrdemColetaPendenteBaixaDao $ordemColetaPendenteBaixaDao = null
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

    /**
     * Corrigido IDOR (achado do security-especialista, registrado em
     * ia_development_state.md; corrigido nesta demanda
     * expedicao-consulta-ordem-coleta-teste, 2026-09-11): antes aceitava "no
     * escuro" o objeto 'ordem' inteiro enviado pelo front-end, sem nenhuma
     * validacao de posse/tipo/status/etapa nem confirmacao de que a ordem
     * pertencia de fato ao resultado consultado para aquele atendimento —
     * um totem podia gravar cliente_nome/cliente_cnpj/ordem_coleta
     * arbitrarios em tb_atendimento.
     *
     * Correcao: valida posse do atendimento pelo totem autenticado (mesmo
     * padrao ja usado em outras acoes deste controller), confere tipo/
     * status/etapa, e RECONSULTA as ordens reais para a placa do atendimento
     * — so aceita a selecao se o 'numero' enviado pelo front bater com uma
     * das ordens realmente retornadas. Os dados gravados em tb_atendimento
     * vem SEMPRE do resultado reconsultado no servidor, nunca do que o front
     * enviou (cliente_nome/cliente_cnpj do front sao ignorados).
     */
    public function selecionarOrdem(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $ordemRecebida = $entrada['ordem'] ?? null;

        if (!$idAtendimento || !is_array($ordemRecebida) || empty($ordemRecebida['numero'])) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'expedicao') {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }
        // 'placa' e a etapa inicial (definida por AtendimentoDao::criar) e
        // permanece assim ate a ordem ser efetivamente selecionada — quando
        // ha so 1 ordem, AtendimentoController::iniciar() ja seleciona
        // sozinho e avanca para 'dados_encontrados', entao esta acao so faz
        // sentido enquanto o atendimento ainda estiver em 'placa'.
        if ($atendimento['etapa_atual'] !== 'placa') {
            Resposta::erro('Atendimento nao esta na etapa esperada para selecionar a ordem');
        }

        $ordensReais = $this->atendimentoRn->consultarOrdensAbertas($atendimento['placa']);
        $numeroRecebido = (string) $ordemRecebida['numero'];

        $ordemReal = null;
        foreach ($ordensReais as $o) {
            if ((string) ($o['numero'] ?? '') === $numeroRecebido) {
                $ordemReal = $o;
                break;
            }
        }

        if ($ordemReal === null) {
            Resposta::erro('Ordem de coleta invalida para essa placa');
        }

        $this->atendimentoRn->selecionarOrdem($idAtendimento, $ordemReal);
        Resposta::sucesso(['proxima_tela' => 'dados_encontrados', 'dados' => $ordemReal]);
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
                // Corrigido IDOR critico (achado do security-especialista,
                // 2026-09-09): antes nao validava posse/tipo/status/etapa,
                // permitindo que um totem sobrescrevesse motorista_nome/
                // motorista_cpf de atendimento alheio. Espelha exatamente o
                // padrao ja usado no case 'digitalizacao_notas' abaixo.
                // A tela de confirmacao (exp_confirma/rec_confirma) e a
                // ULTIMA etapa persistida em tb_atendimento antes do ajudante
                // — nem 'confirmacao' nem 'ajudante' chamam atualizarEtapa(),
                // entao a etapa_atual esperada aqui e a mesma dos dois casos:
                // 'exp_confirmacao'/'rec_confirmacao' (gravada por
                // avancarEtapaDocumentos() no gate final).
                $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

                if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
                    Resposta::erro('Atendimento nao encontrado', 404);
                }
                if ($atendimento['status'] !== 'em_andamento') {
                    Resposta::erro('Atendimento nao esta em andamento');
                }
                $etapaEsperadaConfirmacao = $atendimento['tipo'] === 'expedicao' ? 'exp_confirmacao' : 'rec_confirmacao';
                if ($atendimento['etapa_atual'] !== $etapaEsperadaConfirmacao) {
                    Resposta::erro('Atendimento nao esta na etapa esperada para confirmar os dados');
                }

                $this->atendimentoRn->salvarDadosMotorista($idAtendimento, $dados);
                break;
            case 'cliente':
                // Identificacao MANUAL do cliente no Recebimento (atendente confirma
                // nome/cnpj). Deve avancar etapa_atual para 'rec_cnh_frente', a mesma
                // proxima etapa usada pelo fluxo AUTOMATICO via OCR em
                // concluirDigitalizacao() (expedicao-vio-cnh-crlv, 2026-09-09) —
                // mantendo as duas fontes de identificacao de cliente consistentes
                // entre si. Sem isso, etapa_atual ficava presa em 'cliente' e o
                // primeiro upload de rec_cnh_frente falhava na checagem de etapa em
                // DocumentoController::upload().
                //
                // Corrigido IDOR critico (achado do security-especialista,
                // 2026-09-09): antes nao validava posse/tipo/status/etapa,
                // permitindo sobrescrever cliente_nome/cliente_cnpj de
                // atendimento alheio e forcar a transicao para
                // 'rec_cnh_frente'. So existe na etapa 'cliente' (gravada por
                // concluirDigitalizacao() quando nenhuma nota identifica o
                // cliente automaticamente), e so no Recebimento.
                $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

                if ($atendimento['tipo'] !== 'recebimento') {
                    Resposta::erro('Atendimento nao encontrado', 404);
                }
                if ($atendimento['status'] !== 'em_andamento') {
                    Resposta::erro('Atendimento nao esta em andamento');
                }
                if ($atendimento['etapa_atual'] !== 'cliente') {
                    Resposta::erro('Atendimento nao esta na etapa esperada para identificar o cliente');
                }

                $this->atendimentoRn->salvarCliente($idAtendimento, $dados['nome'] ?? '', $dados['cnpj'] ?? null);
                $this->atendimentoRn->atualizarEtapa($idAtendimento, 'rec_cnh_frente');
                break;
            case 'ajudante':
                // Corrigido IDOR critico (achado do security-especialista,
                // 2026-09-09): antes nao validava posse/tipo/status/etapa,
                // permitindo sobrescrever ajudante_nome/ajudante_cpf de
                // atendimento alheio. Mesma etapa esperada de 'confirmacao'
                // (ver comentario acima) — nenhum dos dois persiste
                // transicao de etapa_atual.
                $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

                if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
                    Resposta::erro('Atendimento nao encontrado', 404);
                }
                if ($atendimento['status'] !== 'em_andamento') {
                    Resposta::erro('Atendimento nao esta em andamento');
                }
                $etapaEsperadaAjudante = $atendimento['tipo'] === 'expedicao' ? 'exp_confirmacao' : 'rec_confirmacao';
                if ($atendimento['etapa_atual'] !== $etapaEsperadaAjudante) {
                    Resposta::erro('Atendimento nao esta na etapa esperada para informar o ajudante');
                }

                $this->atendimentoRn->salvarAjudante($idAtendimento, $dados['nome'] ?? null, $dados['cpf'] ?? null);
                break;
            case 'digitalizacao_notas':
                // marca a etapa formal da digitalizacao de notas do recebimento;
                // NotaController::processar exige essa etapa antes de aceitar qualquer nota.
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

        // CAS no banco (App\Dao\AtendimentoDao::bloquear) — status='concluido'
        // e terminal e imutavel (demanda integridade-conclusao-atendimento,
        // 2026-09-16). false so acontece se o atendimento ja estava
        // concluido no momento exato do UPDATE; nenhuma alteracao no banco
        // nesse caso.
        if (!$this->atendimentoRn->bloquear($idAtendimento)) {
            Resposta::erro('Atendimento ja concluido, nao pode mais ser bloqueado', 409);
            return;
        }

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
            // Correcao (mesma demanda, bug encontrado pelo qa-testes em
            // 2026-09-17): antes esta checagem de precondicao rodava ANTES
            // de qualquer tentativa de CAS/releitura, entao a requisicao
            // perdedora de uma corrida (que so chega aqui DEPOIS que a
            // vencedora ja terminou completamente, etapa_atual ja avancada)
            // caia direto neste erro 400 generico, violando a exigencia do
            // handoff de que so ha conflito real se o estado nao corresponde
            // nem a origem nem ao destino. Antes de falhar, confere se
            // etapa_atual ja e 'rec_cnh_frente'/'cliente' (destino possivel
            // desta chamada) ou uma etapa legitima posterior do fluxo de
            // Recebimento — reaproveitando a MESMA lista/logica usada
            // abaixo para o caso "CAS perdeu a corrida" — e, se for,
            // responde sucesso idempotente sem repetir nenhum efeito
            // colateral. So permanece erro de precondicao quando a etapa
            // atual nao e nem a origem, nem nenhum destino/etapa posterior
            // plausivel (situacao genuinamente anomala).
            $etapaDestinoPossivel = $this->notaDao->algumaNotaComStatusIdentificada($idAtendimento) || $this->notaDao->algumaIdentificada($idAtendimento)
                ? 'rec_cnh_frente'
                : 'cliente';

            if ($this->etapaEhAlvoOuPosterior((string) $atendimento['etapa_atual'], $etapaDestinoPossivel)) {
                $proximaTelaIdempotente = $etapaDestinoPossivel === 'rec_cnh_frente' ? 'rec_cnh_frente' : 'rec_cliente';
                Resposta::sucesso(['proxima_tela' => $proximaTelaIdempotente, 'etapa' => $etapaDestinoPossivel]);
                return;
            }

            Resposta::erro('Atendimento nao esta na etapa de digitalizacao de notas');
        }

        $totalNotas = $this->notaDao->contarPorAtendimento($idAtendimento);

        if ($totalNotas < 1) {
            Resposta::erro('Nenhuma nota fiscal foi digitalizada para esse atendimento');
        }
        if ($totalNotas > 5) {
            Resposta::erro('Limite de 5 notas fiscais excedido para esse atendimento');
        }

        // ATENCAO: a etapa/tela quando um cliente e identificado mudou de
        // 'cnh'/'rec_cnh' para 'rec_cnh_frente' nesta demanda
        // (expedicao-vio-cnh-crlv, REPLANEJAMENTO 2026-09-09) — Recebimento
        // agora tambem valida CNH/CRLV via VIO Decode, com a mesma maquina de
        // estados assincrona da Expedicao (rec_cnh_frente -> rec_cnh_verso ->
        // rec_crlv -> rec_aguarde_documentos -> rec_confirmacao), ver
        // avancarEtapaDocumentos() abaixo.
        //
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
            $etapa = 'rec_cnh_frente';
            $proximaTela = 'rec_cnh_frente';
        } else {
            $etapa = 'cliente';
            $proximaTela = 'rec_cliente';
        }

        // CAS dedicado (demanda integridade-conclusao-atendimento,
        // 2026-09-16) contra a corrida entre 2 requisicoes quase
        // simultaneas de concluirDigitalizacao() do mesmo atendimento: so
        // grava se etapa_atual ainda for 'digitalizacao_notas' e status
        // ainda for 'em_andamento' no momento exato do UPDATE.
        $venceuCas = $this->atendimentoRn->concluirDigitalizacaoNotas($idAtendimento, $etapa);

        if (!$venceuCas) {
            // CAS perdeu — outra requisicao concorrente ja avancou esta
            // etapa antes desta chamada. Rele o estado atual: se ja e a
            // mesma etapa-alvo que esta chamada calculou (ou uma etapa
            // legitima posterior do fluxo de Recebimento), responde sucesso
            // idempotente com os MESMOS dados que a chamada vencedora
            // devolveria, sem repetir nenhum efeito colateral (nenhuma nova
            // escrita, nenhuma contagem de notas de novo). Qualquer outro
            // estado e anomalo — 409.
            $atual = $this->atendimentoRn->buscar($idAtendimento);

            if ($atual !== null && $atual['status'] === 'em_andamento' && $this->etapaEhAlvoOuPosterior((string) $atual['etapa_atual'], $etapa)) {
                Resposta::sucesso(['proxima_tela' => $proximaTela, 'etapa' => $etapa]);
                return;
            }

            Resposta::erro('Nao foi possivel concluir a digitalizacao agora — tente novamente', 409);
            return;
        }

        Resposta::sucesso(['proxima_tela' => $proximaTela, 'etapa' => $etapa]);
    }

    /**
     * Ordem legitima das etapas do Recebimento apos digitalizacao_notas —
     * usada exclusivamente para decidir se a segunda requisicao concorrente
     * de concluirDigitalizacao() (que perdeu o CAS) pode responder sucesso
     * idempotente: a etapa atual do banco precisa ser a etapa-alvo desta
     * chamada, ou qualquer etapa posterior legitima ja alcancada por quem
     * venceu a corrida (ambos os ramos, com ou sem identificacao automatica
     * de cliente, convergem para 'rec_cnh_frente' e seguem a mesma
     * sequencia dai em diante).
     */
    private const SEQUENCIA_POS_DIGITALIZACAO_RECEBIMENTO = [
        'cliente', 'rec_cnh_frente', 'rec_cnh_verso', 'rec_crlv', 'rec_aguarde_documentos', 'rec_confirmacao', 'impressao',
    ];

    private function etapaEhAlvoOuPosterior(string $etapaAtual, string $etapaAlvo): bool
    {
        $indiceAtual = array_search($etapaAtual, self::SEQUENCIA_POS_DIGITALIZACAO_RECEBIMENTO, true);
        $indiceAlvo = array_search($etapaAlvo, self::SEQUENCIA_POS_DIGITALIZACAO_RECEBIMENTO, true);

        if ($indiceAtual === false || $indiceAlvo === false) {
            return false;
        }

        return $indiceAtual >= $indiceAlvo;
    }

    /**
     * Sequencia REAL e autorizada pelo backend para CNH/CRLV da Expedicao
     * (demanda expedicao-vio-cnh-crlv; REPLANEJAMENTO 2026-09-09 tornou o
     * processamento assincrono do lado do navegador):
     *   dados_encontrados -> exp_cnh -> exp_crlv -> exp_aguarde_documentos
     *   -> exp_confirmacao -> impressao.
     * exp_cnh -> exp_crlv e exp_crlv -> exp_aguarde_documentos exigem so que
     * a FOTO tenha sido enviada (upload feito) — NAO que a validacao VIO ja
     * tenha terminado (ela roda em segundo plano no front-end). A aprovacao
     * efetiva (VIO_TRIAL/VIO_VALIDADO/MANUAL) so e OBRIGATORIA no gate final
     * exp_aguarde_documentos -> exp_confirmacao e revalidada de novo em
     * exp_confirmacao -> impressao (nunca confia so na etapa ja alcancada).
     */
    private const SEQUENCIA_EXPEDICAO = [
        'dados_encontrados'        => ['proxima_etapa' => 'exp_cnh', 'proxima_tela' => 'exp_cnh', 'gate' => null],
        'exp_cnh'                  => ['proxima_etapa' => 'exp_crlv', 'proxima_tela' => 'exp_crlv', 'gate' => 'upload_cnh'],
        'exp_crlv'                 => ['proxima_etapa' => 'exp_aguarde_documentos', 'proxima_tela' => 'exp_aguarde_documentos', 'gate' => 'upload_crlv'],
        'exp_aguarde_documentos'   => ['proxima_etapa' => 'exp_confirmacao', 'proxima_tela' => 'exp_confirma', 'gate' => 'ambos_aprovados'],
        'exp_confirmacao'          => ['proxima_etapa' => 'impressao', 'proxima_tela' => 'impressao', 'gate' => 'ambos_aprovados'],
    ];

    /**
     * Mesma logica para Recebimento (mesma demanda, escopo expandido no
     * REPLANEJAMENTO 2026-09-09): rec_cnh_frente -> rec_cnh_verso ->
     * rec_crlv -> rec_aguarde_documentos -> rec_confirmacao. Diferenca
     * intencional em relacao a Expedicao: CNH frente/verso sao etapas
     * SEPARADAS (2 uploads), nao uma unica etapa exp_cnh — especificado
     * explicitamente, nao e divergencia a corrigir.
     */
    private const SEQUENCIA_RECEBIMENTO_DOCUMENTOS = [
        'rec_cnh_frente'         => ['proxima_etapa' => 'rec_cnh_verso', 'proxima_tela' => 'rec_cnh_verso', 'gate' => 'upload_cnh_frente'],
        'rec_cnh_verso'          => ['proxima_etapa' => 'rec_crlv', 'proxima_tela' => 'rec_crlv', 'gate' => 'upload_cnh_verso'],
        'rec_crlv'               => ['proxima_etapa' => 'rec_aguarde_documentos', 'proxima_tela' => 'rec_aguarde_documentos', 'gate' => 'upload_crlv'],
        'rec_aguarde_documentos' => ['proxima_etapa' => 'rec_confirmacao', 'proxima_tela' => 'rec_confirma', 'gate' => 'ambos_aprovados'],
    ];

    /**
     * Maquina de estados GENERALIZADA (Expedicao E Recebimento) para as
     * etapas de CNH/CRLV — substitui a versao anterior restrita a Expedicao,
     * reaproveitando a MESMA logica de gate (App\Rn\DocumentoRn::
     * cnhAprovada/crlvAprovado, ja agnostico de tipo) sem duplicar regra de
     * negocio entre os dois fluxos. O front-end NUNCA decide sozinho mudar
     * de tela — toda transicao passa por aqui, com posse/tipo/status/regra
     * de negocio revalidados no backend a cada chamada.
     */
    public function avancarEtapaDocumentos(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);

        if (!$idAtendimento) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }
        if ($this->documentoRn === null) {
            // dependencia opcional nao injetada (uso fora do endpoint HTTP) —
            // nao ha como validar CNH/CRLV com seguranca, falha fechada.
            Resposta::erro('Nao foi possivel avancar a etapa agora', 500);
        }

        $sequencia = $atendimento['tipo'] === 'expedicao'
            ? self::SEQUENCIA_EXPEDICAO
            : self::SEQUENCIA_RECEBIMENTO_DOCUMENTOS;

        $transicao = $sequencia[$atendimento['etapa_atual']] ?? null;
        if ($transicao === null) {
            Resposta::erro('Atendimento nao esta em uma etapa valida para avancar');
        }

        if (!$this->gateDeTransicaoLiberado($atendimento, $transicao['gate'])) {
            Resposta::erro('Nao foi possivel avancar a etapa agora — documentos pendentes');
        }

        $this->atendimentoRn->atualizarEtapa($idAtendimento, $transicao['proxima_etapa']);
        Resposta::sucesso(['proxima_tela' => $transicao['proxima_tela'], 'etapa' => $transicao['proxima_etapa']]);
    }

    /**
     * Alias de compatibilidade — nome/rota anterior (ciclo sincrono,
     * restrito a Expedicao) do que hoje e avancarEtapaDocumentos(), ja
     * generalizado para os dois tipos de atendimento.
     */
    public function avancarEtapaExpedicao(array $entrada, int $idTotem): void
    {
        $this->avancarEtapaDocumentos($entrada, $idTotem);
    }

    /**
     * Avalia o "gate" de uma transicao: null = sem restricao; upload_* =
     * confere que o ARQUIVO da foto foi de fato salvo em disco (nao exige
     * que a validacao VIO ja tenha terminado — e o que viabiliza liberar a
     * etapa seguinte imediatamente, com a validacao rodando em segundo
     * plano); ambos_aprovados = CNH E CRLV precisam estar em estado terminal
     * ACEITAVEL (VIO_TRIAL/VIO_VALIDADO/MANUAL com dados validos — mesma
     * checagem ja usada no gate final de impressao, sem nenhuma
     * flexibilizacao).
     */
    private function gateDeTransicaoLiberado(array $atendimento, ?string $gate): bool
    {
        return match ($gate) {
            null => true,
            'upload_cnh' => $this->arquivoDoDocumentoExiste($atendimento, 'cnh_frente.jpg')
                && $this->arquivoDoDocumentoExiste($atendimento, 'cnh_verso.jpg'),
            'upload_cnh_frente' => $this->arquivoDoDocumentoExiste($atendimento, 'cnh_frente.jpg'),
            'upload_cnh_verso' => $this->arquivoDoDocumentoExiste($atendimento, 'cnh_verso.jpg'),
            'upload_crlv' => $this->arquivoDoDocumentoExiste($atendimento, 'crlv.jpg'),
            'ambos_aprovados' => $this->documentoRn->cnhAprovada($atendimento) && $this->documentoRn->crlvAprovado($atendimento),
            default => false,
        };
    }

    /**
     * Confere no sistema de arquivos (STORAGE_PATH + pasta_documentos, fora
     * do webroot publico) se a foto do documento ja foi salva por
     * DocumentoController::upload() — usado so para o gate de "upload
     * feito", nunca para decidir aprovacao (isso e sempre
     * App\Rn\DocumentoRn::cnhAprovada/crlvAprovado, calculado a partir de
     * tb_atendimento).
     */
    private function arquivoDoDocumentoExiste(array $atendimento, string $nomeArquivo): bool
    {
        $pasta = $atendimento['pasta_documentos'] ?? null;
        $storagePath = rtrim($_ENV['STORAGE_PATH'] ?? '', '/');

        if (!$pasta || $storagePath === '') {
            return false;
        }

        return is_file($storagePath . '/' . $pasta . '/' . $nomeArquivo);
    }

    /**
     * Finalizacao do atendimento — envio do check-in ao Talent
     * (Portaria/Checkin). Corrigido IDOR CRITICO nesta demanda
     * (integracao-talent-portaria-checkin, 2026-09-09): antes usava
     * atendimentoRn->buscar() puro, sem validar posse/tipo/status/etapa —
     * qualquer totem autenticado podia disparar o check-in (com CPF/CNH/
     * anexos) de um atendimento de OUTRO totem.
     *
     * Sequencia de validacao (correcao de comentario apos revisao do
     * security-especialista e qa-testes — a checagem 1 usa mensagem
     * generica de proposito para nunca revelar posse/existencia a outro
     * totem; as checagens 2-5 ja operam sobre um atendimento CONFIRMADO
     * como do totem autenticado, entao podem ter mensagens distintas entre
     * si sem constituir vazamento de informacao entre totens — mesmo
     * padrao ja usado nos demais `case` de salvarEtapa() neste controller):
     *   1. posse (buscarAtendimentoDoTotem) — mensagem generica
     *      'Atendimento nao encontrado', NUNCA revela se o id existe ou
     *      pertence a outro totem (cobre tanto "nao existe" quanto
     *      "existe mas e de outro totem")
     *   2. tipo expedicao|recebimento — tambem 'Atendimento nao encontrado'
     *      (coincide com a mensagem da checagem 1, mas por motivo diferente:
     *      aqui e so validacao de dado, nao ocultacao de IDOR)
     *   3. status em_andamento — 'Atendimento nao esta em andamento'
     *   4. etapa_atual = etapa de confirmacao esperada (exp_confirmacao/
     *      rec_confirmacao) — 'Atendimento nao esta na etapa esperada para
     *      finalizar'
     *   5. documentos obrigatorios aprovados (cnhAprovada && crlvAprovado)
     *      — 'Documentos obrigatorios pendentes/invalidos'
     *   6. cnpjArmazem resolvivel (totem tem id_empresa valido) — mensagem
     *      PROPRIA aqui, nao e um caso de IDOR (nao revela nada sobre outro
     *      atendimento, so sobre a configuracao do PROPRIO totem)
     *   7. idempotencia (CAS de talent_checkin_status) — ULTIMA checagem,
     *      imediatamente antes de ler qualquer anexo do disco/montar payload
     */
    public function finalizar(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }
        $etapaEsperadaFinalizar = $atendimento['tipo'] === 'expedicao' ? 'exp_confirmacao' : 'rec_confirmacao';
        if ($atendimento['etapa_atual'] !== $etapaEsperadaFinalizar) {
            Resposta::erro('Atendimento nao esta na etapa esperada para finalizar');
        }
        if ($this->documentoRn === null || !$this->documentoRn->cnhAprovada($atendimento) || !$this->documentoRn->crlvAprovado($atendimento)) {
            Resposta::erro('Documentos obrigatorios pendentes/invalidos');
        }

        if ($this->totemDao === null || $this->empresaDao === null) {
            Resposta::erro('Nao foi possivel processar o check-in agora', 500);
        }

        $totemAtual = $this->totemDao->buscarPorId($idTotem);
        $idEmpresa = $totemAtual['id_empresa'] ?? null;
        $empresa = $idEmpresa !== null ? $this->empresaDao->buscarPorId((int) $idEmpresa) : null;

        if ($empresa === null) {
            // Nunca assume empresa default — totem sem vinculo configurado
            // falha explicitamente (decisao de produto, ver
            // docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md).
            Resposta::erro('Totem sem empresa/armazem configurado para envio ao Talent', 500);
        }

        $notas = $this->notaDao->listarPorAtendimento($idAtendimento);

        // GATES REAIS de doctos[] (demanda talent-doctos-finalizacao-checkin,
        // 2026-09-14) — substituem a trava incondicional TALENT_DOCTOS_PENDENTE
        // anterior. Rodam ANTES do CAS de idempotencia, exercitando 100% da
        // validacao mesmo quando TALENT_CHECKIN_ATIVO estiver desligado (ver
        // abaixo) — defesa em profundidade, App\Rn\TalentRn::montarPayload()
        // tambem valida isso internamente.
        if ($atendimento['tipo'] === 'recebimento') {
            foreach ($notas as $nota) {
                if (trim((string) ($nota['numero_nota'] ?? '')) === '') {
                    Resposta::erro('Existem notas fiscais sem numero definido (NOTAS_SEM_NUMERO)', 422);
                    return;
                }
            }
        } else {
            // expedicao — defesa em profundidade, ordem_coleta ja deveria
            // estar garantido a montante (selecionar-ordem)
            if (trim((string) ($atendimento['ordem_coleta'] ?? '')) === '') {
                Resposta::erro('Atendimento sem ordem de coleta selecionada', 422);
                return;
            }
        }

        // Mecanismo de "ativacao configuravel fail-closed" — enquanto
        // TALENT_CHECKIN_ATIVO nao for LITERALMENTE 'true' no .env, nenhuma
        // chamada de rede real ao Talent ocorre daqui pra frente, mas TODOS
        // os gates acima (doctos/posse/tipo/status/etapa/documentos) ja
        // foram exercitados normalmente. Fail-closed: ausente/qualquer outro
        // valor = tratado como desativado. Permite "ligar" no futuro so
        // mudando esta variavel de ambiente, sem alterar codigo. Ver
        // docs/handoffs/2026-09-14-talent-doctos-finalizacao-checkin.md.
        $talentCheckinAtivo = ($_ENV['TALENT_CHECKIN_ATIVO'] ?? '') === 'true';
        if (!$talentCheckinAtivo) {
            Resposta::erro('Envio ao Talent temporariamente desativado (TALENT_CHECKIN_DESATIVADO)', 503);
            return;
        }

        // CAS de idempotencia (5 estados) — ULTIMO portao antes de ler
        // anexos do disco/montar payload. App\Rn\TalentRn::processarCheckin
        // orquestra a maquina de estados inteira (marcar obsoleto -> checar
        // estado atual -> CAS -> montar payload/anexos -> chamar o Talent ->
        // gravar resultado).
        $resultado = $this->talentRn->processarCheckin($atendimento, $empresa, $notas);

        switch ($resultado['status']) {
            case 'ENVIADO':
            case 'JA_ENVIADO':
                // Atualizacao de ordem para INATIVA — so Expedicao, so DEPOIS
                // da confirmacao de sucesso, nunca antes, nunca para
                // Recebimento. Falha aqui NUNCA bloqueia a resposta de
                // sucesso ja garantida ao motorista (a senha/nrRegAcesso ja
                // e valida independente disso) — registrada como pendencia
                // de auditoria (tb_ordem_coleta_pendente_baixa), idempotente,
                // sem cron de reconciliacao automatica nesta demanda.
                if ($atendimento['tipo'] === 'expedicao') {
                    $this->tentarMarcarOrdemConcluida($idAtendimento, (string) $atendimento['ordem_coleta']);
                }
                Resposta::sucesso(['senha' => $resultado['senha'], 'protocolo' => $resultado['protocolo']]);
                return;
            case 'EM_ANDAMENTO':
                Resposta::erro('Seu check-in ja esta sendo processado, aguarde', 409);
                return;
            case 'INDETERMINADO_PENDENTE_MANUAL':
                Resposta::erro('Nao foi possivel confirmar seu check-in — procure um atendente', 500);
                return;
            case 'ERRO_REPROCESSAVEL':
                $this->talentRn->registrarFalhaParaReenvio($idAtendimento, $resultado['erro_categoria'] ?? 'erro_desconhecido');
                // 202: aceito, mas ainda sendo processado — o totem mostra "aguarde" e o cron finaliza depois
                Resposta::erro('Nao foi possivel enviar agora — sua senha sera processada em instantes', 202);
                return;
            case 'ENVIO_INDETERMINADO':
                // Nao enfileirado para retry automatico (por design) — exige
                // conferencia manual no painel do Talent.
                Resposta::erro('Nao foi possivel confirmar seu check-in — procure um atendente', 500);
                return;
            default:
                Resposta::erro('Nao foi possivel processar o check-in agora', 500);
        }
    }

    public function cancelar(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        // sem restricao de tipo/status em PHP: cancelar precisa funcionar em
        // qualquer tela/tipo/status do fluxo (Expedicao e Recebimento) — so
        // valida posse aqui. A UNICA restricao (status='concluido' e
        // terminal/imutavel, demanda integridade-conclusao-atendimento,
        // 2026-09-16) e aplicada diretamente no CAS do UPDATE
        // (App\Dao\AtendimentoDao::cancelar), nunca checada em PHP antes —
        // checar em PHP aqui abriria uma janela de corrida (TOCTOU) entre
        // este SELECT de posse e o UPDATE.
        $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (!$this->atendimentoRn->cancelar($idAtendimento)) {
            Resposta::erro('Atendimento ja concluido, nao pode mais ser cancelado', 409);
            return;
        }

        Resposta::sucesso(['ok' => true]);
    }

    /**
     * Tenta marcar a ordem de coleta como INATIVA (banco externo de gestao
     * de coletas) apos check-in confirmado no Talent — SEMPRE em try/catch,
     * SEMPRE sem propagar excecao: qualquer falha real (retorno false por
     * ordem ainda ATIVA/inexistente, OU excecao) e registrada em
     * tb_ordem_coleta_pendente_baixa (auditoria idempotente, so os 2
     * identificadores + timestamps, NUNCA payload/dado pessoal/credencial),
     * log estruturado sanitizado so com esses 2 IDs. Nunca bloqueia/altera a
     * resposta de sucesso ja decidida pelo chamador.
     *
     * Correcao de auditoria (2026-09-15): o branch JA_ENVIADO do Talent pode
     * chegar aqui com a ordem ja INATIVA de uma chamada anterior
     * bem-sucedida (reprocessamento idempotente do mesmo check-in) —
     * marcarConcluida() retorna false nesse caso (UPDATE condicional
     * WHERE status='ATIVA' afeta 0 linhas), mas isso NAO e uma falha: a
     * ordem ja esta no estado final correto. Por isso, quando marcarConcluida()
     * retorna false, consulta-se o status atual (statusAtual()) para
     * distinguir "ja estava INATIVA" (idempotente, sucesso, sem registro de
     * pendencia, sem segunda tentativa de UPDATE) de uma falha real (ordem
     * ainda ATIVA, inexistente, ou status desconhecido por excecao na
     * propria consulta de status — tratado como falha real por seguranca).
     */
    private function tentarMarcarOrdemConcluida(int $idAtendimento, string $numeroOrdemColeta): void
    {
        if ($this->ordemColetaClient === null || $this->ordemColetaPendenteBaixaDao === null) {
            error_log("finalizar: OrdemColetaClient/OrdemColetaPendenteBaixaDao nao injetados — pendencia de baixa nao registrada para id_atendimento={$idAtendimento}");
            return;
        }

        try {
            $ok = $this->ordemColetaClient->marcarConcluida($numeroOrdemColeta);
        } catch (\Throwable $e) {
            $ok = false;
        }

        if ($ok === true) {
            return;
        }

        try {
            $statusAtual = $this->ordemColetaClient->statusAtual($numeroOrdemColeta);
        } catch (\Throwable $e) {
            $statusAtual = null;
        }

        if ($statusAtual === 'INATIVA') {
            error_log("finalizar: ordem de coleta ja estava INATIVA (idempotente, nada a fazer) para id_atendimento={$idAtendimento}");
            return;
        }

        try {
            $this->ordemColetaPendenteBaixaDao->registrar($idAtendimento, $numeroOrdemColeta);
            error_log("finalizar: baixa da ordem de coleta pendente, registrada para reconciliacao manual (id_atendimento={$idAtendimento})");
        } catch (\Throwable $e) {
            error_log("finalizar: falha ao registrar pendencia de baixa de ordem de coleta (id_atendimento={$idAtendimento})");
        }
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
