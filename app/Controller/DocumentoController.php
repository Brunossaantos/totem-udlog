<?php

namespace App\Controller;

use PDO;
use Util\UploadHelper;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\RateLimitVioStatusDao;
use App\Rn\DocumentoRn;
use App\Rn\VioDecodeClient;

/**
 * Documentos de CNH/CRLV — Expedicao E Recebimento (demanda
 * expedicao-vio-cnh-crlv, REPLANEJAMENTO de 2026-09-09: VIO Decode passa a
 * valer para os dois tipos de atendimento). Processamento ASSINCRONO do
 * ponto de vista do navegador: upload() salva a foto e libera a etapa
 * seguinte imediatamente; iniciarProcessamento()/statusProcessamento() sao
 * chamados pelo front-end sem bloquear a navegacao (fire-and-forget + poll).
 */
class DocumentoController
{
    public function __construct(
        private AtendimentoDao $atendimentoDao,
        private ?DocumentoRn $documentoRn = null,
        private ?PDO $pdo = null,
        private ?RateLimitVioStatusDao $rateLimitVioStatusDao = null
    ) {}

    /**
     * Timeout de PROCESSANDO obsoleto (backend) — 30s, conforme aprovado
     * (VioDecodeClient tem timeout de 20s + margem). Usado tanto para
     * permitir nova tentativa (iniciarProcessamento) quanto para o endpoint
     * de status marcar ERRO sem chamar o VIO (statusProcessamento).
     */
    private const TIMEOUT_PROCESSAMENTO_SEGUNDOS = 30;

    /**
     * Rate limit proprio do polling de status-processamento: janela de 5s,
     * limite de 20 chamadas/documento/janela — media de 1 chamada a cada
     * 0.25s, folga generosa sobre o polling de 2s especificado no escopo.
     */
    private const RATE_LIMIT_STATUS_JANELA_SEGUNDOS = 5;
    private const RATE_LIMIT_STATUS_MAX_CHAMADAS = 20;

    /**
     * Etapa exigida por (tipo_atendimento, tipo_documento) para UPLOAD — a
     * unica acao que continua exigindo etapa EXATA (uploads seguem
     * estritamente ordenados, sem chamada fire-and-forget concorrente).
     * Expedicao: CNH frente+verso na MESMA chamada/etapa (exp_cnh).
     * Recebimento: CNH frente e verso em chamadas/etapas SEPARADAS
     * (assimetria intencional, conforme especificado).
     */
    private const ETAPAS_UPLOAD = [
        'expedicao' => [
            'cnh' => 'exp_cnh',
            'crlv' => 'exp_crlv',
        ],
        'recebimento' => [
            'cnh_frente' => 'rec_cnh_frente',
            'cnh_verso' => 'rec_cnh_verso',
            'crlv' => 'rec_crlv',
        ],
    ];

    /**
     * Allowlist EXPLICITA e FECHADA de etapas permitidas para
     * iniciar-processamento/status-processamento/preencher-manual, por
     * (tipo_atendimento, tipo_documento) — NUNCA comparacao textual generica
     * de "igual ou posterior". Necessaria porque a validacao de CNH roda em
     * segundo plano enquanto o usuario ja pode ter avancado para a etapa do
     * CRLV (ou para aguarde_documentos) antes do servidor terminar de
     * processar a chamada fire-and-forget disparada anteriormente. NUNCA
     * inclui a etapa de confirmacao/impressao final.
     */
    private const ETAPAS_PERMITIDAS_PROCESSAMENTO = [
        'expedicao' => [
            'cnh' => ['exp_cnh', 'exp_crlv', 'exp_aguarde_documentos'],
            'crlv' => ['exp_crlv', 'exp_aguarde_documentos'],
        ],
        'recebimento' => [
            'cnh' => ['rec_cnh_verso', 'rec_crlv', 'rec_aguarde_documentos'],
            'crlv' => ['rec_crlv', 'rec_aguarde_documentos'],
        ],
    ];

    /**
     * Upload das fotos de CNH/CRLV — Expedicao (cnh: frente+verso numa unica
     * chamada) e Recebimento (cnh_frente/cnh_verso em chamadas separadas,
     * crlv em chamada unica). IDOR corrigido nesta demanda: valida
     * posse/tipo/status/etapa antes de salvar (antes so verificava que o
     * atendimento existia por ID).
     */
    public function upload(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null; // 'cnh' | 'cnh_frente' | 'cnh_verso' | 'crlv'

        if (!$idAtendimento || !in_array($tipo, ['cnh', 'cnh_frente', 'cnh_verso', 'crlv'], true)) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }

        $etapaEsperada = self::ETAPAS_UPLOAD[$atendimento['tipo']][$tipo] ?? null;
        if ($etapaEsperada === null || $atendimento['etapa_atual'] !== $etapaEsperada) {
            Resposta::erro('Atendimento nao esta na etapa esperada para envio desse documento');
        }

        try {
            if ($tipo === 'cnh') {
                $frente = $entrada['imagem_frente'] ?? null;
                $verso = $entrada['imagem_verso'] ?? null;

                if (!$frente || !$verso) {
                    Resposta::erro('Imagens de frente e verso da CNH sao obrigatorias');
                }

                UploadHelper::salvarImagemBase64($frente, $atendimento['pasta_documentos'], 'cnh_frente.jpg');
                UploadHelper::salvarImagemBase64($verso, $atendimento['pasta_documentos'], 'cnh_verso.jpg');
            } elseif ($tipo === 'cnh_frente' || $tipo === 'cnh_verso') {
                $imagem = $entrada['imagem'] ?? null;

                if (!$imagem) {
                    Resposta::erro('Imagem do documento e obrigatoria');
                }

                UploadHelper::salvarImagemBase64($imagem, $atendimento['pasta_documentos'], $tipo . '.jpg');
            } else {
                $imagem = $entrada['imagem'] ?? null;

                if (!$imagem) {
                    Resposta::erro('Imagem do CRLV e obrigatoria');
                }

                UploadHelper::salvarImagemBase64($imagem, $atendimento['pasta_documentos'], 'crlv.jpg');
            }
        } catch (\RuntimeException $e) {
            Resposta::erro('Nao foi possivel salvar a imagem do documento');
        }

        Resposta::sucesso(['ok' => true]);
    }

    /**
     * Inicia (ou consulta idempotentemente, se ja em andamento/concluido) o
     * processamento de um documento contra a VIO Decode. Chamado pelo
     * front-end de forma fire-and-forget (sem await bloqueante) logo apos o
     * upload — a chamada ao VIO continua sincrona DENTRO deste request PHP,
     * o "assincrono" e so do lado do navegador.
     *
     * Concorrencia: transicao para PROCESSANDO e ATOMICA
     * (App\Dao\AtendimentoDao::iniciarProcessamento, via UPDATE...WHERE +
     * rowCount()) com um tentativa_id novo por chamada; a gravacao do
     * resultado final so tem efeito se esse tentativa_id ainda for o vigente
     * (protege contra resposta "zumbi" de uma chamada antiga sobrescrever
     * uma tentativa mais nova). Se outra chamada ja possui o processamento
     * (nao obsoleto) ou o documento ja foi concluido, responde de forma
     * idempotente com o estado atual, SEM reprocessar.
     *
     * Documento ja aprovado (VIO_TRIAL/VIO_VALIDADO/MANUAL) preserva o
     * resultado e nunca reprocessa.
     */
    public function iniciarProcessamento(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null; // 'cnh' | 'crlv'
        $qrBytesBase64 = $entrada['qr_bytes_base64'] ?? null;

        if (!$idAtendimento || !in_array($tipo, ['cnh', 'crlv'], true) || !is_string($qrBytesBase64) || $qrBytesBase64 === '') {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->validarAtendimentoParaProcessamento($idAtendimento, $idTotem, $tipo);

        if ($this->documentoRn === null) {
            Resposta::erro('Nao foi possivel validar o documento agora', 500);
        }

        $jaAprovado = $tipo === 'cnh'
            ? $this->documentoRn->cnhAprovada($atendimento)
            : $this->documentoRn->crlvAprovado($atendimento);

        if ($jaAprovado) {
            Resposta::sucesso($this->respostaStatusAtual($atendimento, $tipo));
            return;
        }

        $bytesQrBrutos = base64_decode($qrBytesBase64, true);
        if ($bytesQrBrutos === false || $bytesQrBrutos === '') {
            Resposta::erro('QR invalido ou ilegivel');
        }

        $tentativaId = bin2hex(random_bytes(16));
        $adquiriu = $this->atendimentoDao->iniciarProcessamento($idAtendimento, $tipo, $tentativaId, self::TIMEOUT_PROCESSAMENTO_SEGUNDOS);

        if (!$adquiriu) {
            // Ja existe uma tentativa em andamento (nao obsoleta) ou o
            // documento ja foi concluido/rejeitado por outra chamada —
            // resposta idempotente, sem reprocessar.
            $atual = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);
            Resposta::sucesso($this->respostaStatusAtual($atual, $tipo));
            return;
        }

        // Lock de curta duracao adicional (defesa em profundidade) — a
        // garantia real contra duplicidade/zumbi e o tentativa_id acima, nao
        // este lock (que e liberado ao fim da conexao mesmo em erro fatal).
        $chaveLock = "vio_validar_{$idAtendimento}_{$tipo}";
        $this->obterLock($chaveLock);

        // Correcao (demanda integridade-conclusao-atendimento, 2026-09-16):
        // Resposta::erro()/exit() NUNCA e chamado dentro deste try/finally —
        // exit() dentro de um try pula o finally em PHP, entao o
        // RELEASE_LOCK explicito abaixo nao aconteceria. A intencao de erro
        // (mensagem + codigo HTTP) e capturada em variaveis locais; a
        // resposta HTTP so e emitida DEPOIS do try/finally ja ter liberado o
        // lock. Mensagens/codigos HTTP e a gravacao de 'ERRO' em cada catch
        // sao EXATAMENTE as mesmas de antes — so mudou onde a resposta e
        // emitida.
        $erroMensagem = null;
        $erroCodigoHttp = null;
        $resultado = null;

        try {
            try {
                $vio = new VioDecodeClient();
            } catch (\Throwable $e) {
                error_log('iniciar-processamento: falha ao inicializar VioDecodeClient: ' . $e->getMessage());
                $this->atendimentoDao->gravarResultadoProcessamento($idAtendimento, $tipo, $tentativaId, 'ERRO');
                $erroMensagem = 'Servico de validacao de documento indisponivel no momento';
                $erroCodigoHttp = 503;
            }

            if ($erroMensagem === null) {
                try {
                    $resultado = $tipo === 'cnh'
                        ? $this->documentoRn->validarCnh($atendimento, $vio, $bytesQrBrutos)
                        : $this->documentoRn->validarCrlv($atendimento, $vio, $bytesQrBrutos);
                } catch (\Throwable $e) {
                    error_log('iniciar-processamento: falha nao prevista: ' . $e->getMessage());
                    $this->atendimentoDao->gravarResultadoProcessamento($idAtendimento, $tipo, $tentativaId, 'ERRO');
                    $erroMensagem = 'Nao foi possivel validar o documento agora';
                    $erroCodigoHttp = 500;
                }
            }

            if ($erroMensagem === null) {
                // status_processamento = "o backend terminou de tentar"
                // (ortogonal a origem/aprovacao): CONCLUIDO sempre que a VIO
                // respondeu de forma definitiva (mesmo se os dados nao
                // passaram nas regras de negocio — nesse caso o front
                // direciona para preenchimento manual, nao para nova
                // tentativa de QR); ERRO so para falha TECNICA
                // (rede/timeout/indisponibilidade/excecao), que permite nova
                // tentativa de processamento.
                $statusFinal = $resultado['ok'] ? 'CONCLUIDO' : 'ERRO';
                $gravou = $this->atendimentoDao->gravarResultadoProcessamento($idAtendimento, $tipo, $tentativaId, $statusFinal);

                if (!$gravou) {
                    // "Tentativa zumbi": uma tentativa mais nova ja
                    // sobrescreveu o tentativa_id antes desta resposta
                    // chegar — descartada silenciosamente do ponto de vista
                    // do banco, so logada para debug tecnico (nunca loga o
                    // QR bruto nem dado pessoal).
                    error_log("iniciar-processamento: resultado descartado (tentativa obsoleta) id_atendimento={$idAtendimento} tipo={$tipo}");
                }
            }
        } finally {
            $this->liberarLock($chaveLock);
        }

        if ($erroMensagem !== null) {
            Resposta::erro($erroMensagem, $erroCodigoHttp);
            return;
        }

        Resposta::sucesso($resultado);
    }

    /**
     * Alias de compatibilidade — nome anterior (ciclo sincrono) do que hoje
     * e iniciarProcessamento() no fluxo assincrono. Mantido para nao quebrar
     * nenhum chamador ja existente que ainda use a acao 'validar-qr'.
     */
    public function validarQr(array $entrada, int $idTotem): void
    {
        $this->iniciarProcessamento($entrada, $idTotem);
    }

    /**
     * Leitura PURA do status persistido — NUNCA chama a VIO Decode. Usado
     * pelo front-end via polling (a cada 2s, conforme escopo) quando nao ha
     * mais uma Promise viva em memoria (ex.: apos reload da pagina) para
     * saber se o processamento fire-and-forget ja terminou.
     *
     * Detecta e marca PROCESSANDO obsoleto (> 30s) como ERRO diretamente
     * aqui (escrita pura no banco, nao e uma chamada externa) — permite que
     * o proprio polling avance o estado sem depender de uma nova chamada de
     * iniciar-processamento.
     */
    public function statusProcessamento(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null; // 'cnh' | 'crlv'

        if (!$idAtendimento || !in_array($tipo, ['cnh', 'crlv'], true)) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->validarAtendimentoParaProcessamento($idAtendimento, $idTotem, $tipo);

        $this->verificarRateLimitStatus($idAtendimento, $tipo);

        $this->atendimentoDao->marcarProcessamentoObsoletoComoErro($idAtendimento, $tipo, self::TIMEOUT_PROCESSAMENTO_SEGUNDOS);

        // Releitura apos a possivel marcacao de timeout acima, para devolver
        // sempre o estado mais atual (nunca o snapshot anterior a checagem).
        $atendimentoAtual = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        Resposta::sucesso($this->respostaStatusAtual($atendimentoAtual, $tipo));
    }

    /**
     * Preenchimento manual (fallback quando a leitura do QR falha, o
     * processamento erra, ou o tempo de espera do front-end se esgota).
     * NUNCA marcado como validado pelo VIO, NUNCA alimenta o cache. Marca
     * status_processamento = CONCLUIDO diretamente (o atendente concluiu o
     * preenchimento agora, sem depender de nenhuma tentativa assincrona).
     */
    public function preencherManual(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null; // 'cnh' | 'crlv'

        if (!$idAtendimento || !in_array($tipo, ['cnh', 'crlv'], true)) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->validarAtendimentoParaProcessamento($idAtendimento, $idTotem, $tipo);

        if ($this->documentoRn === null) {
            Resposta::erro('Nao foi possivel processar o preenchimento manual agora', 500);
        }

        try {
            if ($tipo === 'cnh') {
                $nome = trim((string) ($entrada['nome'] ?? ''));
                $cpf = (string) ($entrada['cpf'] ?? '');
                $validade = (string) ($entrada['validade'] ?? '');

                if ($nome === '' || $cpf === '' || $validade === '') {
                    Resposta::erro('Dados incompletos');
                }

                $resultado = $this->documentoRn->preencherManualCnh($atendimento, $nome, $cpf, $validade);
            } else {
                $placa = (string) ($entrada['placa'] ?? '');
                $exercicio = (string) ($entrada['exercicio'] ?? '');
                $uf = (string) ($entrada['uf'] ?? '');
                $rntc = (string) ($entrada['rntc'] ?? '');
                $tipoVeiculo = (string) ($entrada['tipo_veiculo'] ?? '');

                // Preenchimento manual "so dos campos ausentes" (extensao
                // integracao-talent-portaria-checkin, 2026-09-10): se
                // rntc/tipo_veiculo vierem vazios na requisicao MAS o VIO ja
                // tiver aprovado um valor valido para esses campos
                // especificos (origem VIO_TRIAL/VIO_VALIDADO), reaproveita o
                // valor ja gravado em tb_atendimento em vez de sobrescrever
                // com vazio/rejeitar a requisicao inteira — evita perder um
                // dado bom so porque outro campo (ex.: placa/uf) faltou.
                $origemAtualCrlv = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
                $ehOrigemVioAtual = in_array($origemAtualCrlv, ['VIO_TRIAL', 'VIO_VALIDADO'], true);

                if ($rntc === '' && $ehOrigemVioAtual) {
                    $rntc = trim((string) ($atendimento['crlv_rntc'] ?? ''));
                }
                if ($tipoVeiculo === '' && $ehOrigemVioAtual) {
                    $tipoVeiculo = trim((string) ($atendimento['crlv_tipo_veiculo'] ?? ''));
                }

                if ($placa === '' || $exercicio === '' || $uf === '' || $rntc === '' || $tipoVeiculo === '') {
                    Resposta::erro('Dados incompletos');
                }

                $resultado = $this->documentoRn->preencherManualCrlv($atendimento, $placa, $exercicio, $uf, $rntc, $tipoVeiculo);
            }

            $this->atendimentoDao->marcarProcessamentoConcluido($idAtendimento, $tipo);
        } catch (\Throwable $e) {
            error_log('preencher-manual: falha nao prevista: ' . $e->getMessage());
            Resposta::erro('Nao foi possivel processar o preenchimento manual agora', 500);
        }

        Resposta::sucesso($resultado);
    }

    /**
     * Validacoes comuns a iniciar-processamento/status-processamento/
     * preencher-manual: posse -> tipo (expedicao OU recebimento) -> status
     * em_andamento -> etapa dentro da ALLOWLIST FECHADA por documento (ver
     * ETAPAS_PERMITIDAS_PROCESSAMENTO), nunca comparacao "igual ou
     * posterior" implicita.
     */
    private function validarAtendimentoParaProcessamento(int $idAtendimento, int $idTotem, string $tipo): array
    {
        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }

        $etapasPermitidas = self::ETAPAS_PERMITIDAS_PROCESSAMENTO[$atendimento['tipo']][$tipo] ?? [];
        if (!in_array($atendimento['etapa_atual'], $etapasPermitidas, true)) {
            Resposta::erro('Atendimento nao esta na etapa esperada para esse documento');
        }

        return $atendimento;
    }

    /**
     * Monta a resposta ALLOWLIST (nunca o payload bruto do VIO, nunca campo
     * nao autorizado) com o estado atual persistido de um documento —
     * reaproveitada por iniciarProcessamento() (respostas idempotentes/ja
     * aprovado) e statusProcessamento() (leitura pura).
     */
    private function respostaStatusAtual(array $atendimento, string $tipo): array
    {
        $origem = $atendimento["{$tipo}_origem_validacao"] ?? 'NAO_VALIDADO';
        $statusRevisao = $atendimento["{$tipo}_status_revisao"] ?? 'OK';
        $statusProcessamento = $atendimento["{$tipo}_status_processamento"] ?? 'PENDENTE';
        $aprovado = in_array($origem, ['VIO_TRIAL', 'VIO_VALIDADO', 'MANUAL'], true);

        return [
            'ok' => $aprovado,
            'pode_avancar' => $aprovado,
            'motivo' => $aprovado ? 'Documento ja processado anteriormente' : 'Documento ainda nao aprovado',
            'aviso_trial' => ($_ENV['VIO_AMBIENTE'] ?? '') === 'trial',
            'origem' => $origem,
            'status_revisao' => $statusRevisao,
            'status_processamento' => $statusProcessamento,
            'terminal' => in_array($statusProcessamento, ['CONCLUIDO', 'ERRO'], true),
        ];
    }

    /**
     * Rate limit proprio do polling de status-processamento (ver constantes
     * da classe). Se rateLimitVioStatusDao nao foi injetado (ex.: uso futuro
     * fora do endpoint HTTP), a checagem e pulada — nunca quebra o fluxo por
     * ausencia de dependencia opcional.
     */
    private function verificarRateLimitStatus(int $idAtendimento, string $tipo): void
    {
        if ($this->rateLimitVioStatusDao === null) {
            return;
        }

        $agora = time();
        $janela = intdiv($agora, self::RATE_LIMIT_STATUS_JANELA_SEGUNDOS);
        $contador = $this->rateLimitVioStatusDao->incrementarEContar($idAtendimento, $tipo, $janela);

        if ($contador > self::RATE_LIMIT_STATUS_MAX_CHAMADAS) {
            $segundosRestantes = self::RATE_LIMIT_STATUS_JANELA_SEGUNDOS - ($agora % self::RATE_LIMIT_STATUS_JANELA_SEGUNDOS);
            header('Retry-After: ' . $segundosRestantes);
            Resposta::erro('Muitas consultas de status em pouco tempo. Aguarde.', 429);
        }
    }

    /**
     * Lock por atendimento+tipo via GET_LOCK do MySQL/MariaDB — nao bloqueante
     * (timeout 0): se ja existe uma validacao em andamento para o mesmo
     * atendimento/tipo, falha imediatamente em vez de enfileirar, evitando
     * chamadas duplicadas/abusivas ao VIO Decode durante o processamento.
     * A conexao PDO do proprio request mantem o lock ate liberarLock() ou
     * o fim da conexao (mesmo em erro fatal), sem exigir tabela nova.
     */
    private function obterLock(string $chave): bool
    {
        if ($this->pdo === null) {
            return true; // dependencia opcional nao injetada — nao bloqueia por falta dela
        }

        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:chave, 0)');
        $stmt->execute(['chave' => $chave]);

        return (bool) $stmt->fetchColumn();
    }

    private function liberarLock(string $chave): void
    {
        if ($this->pdo === null) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:chave)');
        $stmt->execute(['chave' => $chave]);
    }

    /**
     * Busca o atendimento e garante que pertence ao totem autenticado.
     * Mensagem de erro generica em qualquer caso de falha, mesmo padrao ja
     * usado em NotaController/AtendimentoController.
     */
    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }
}
