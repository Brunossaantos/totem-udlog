<?php

namespace App\Controller;

use PDO;
use Util\UploadHelper;
use Util\Resposta;
use Util\AnexoPdfHelper;
use App\Dao\AtendimentoDao;
use App\Dao\RateLimitVioStatusDao;
use App\Rn\DocumentoRn;
use App\Rn\VioApiBrClient;

// App\Rn\VioDecodeClient (Serpro/VIO Decode direto) NAO e mais importado nem
// instanciado por este Controller (demanda migracao-vio-api-br-com-cache,
// 2026-09-25) — o arquivo permanece fisicamente intocado no repositorio so
// para rollback manual/controlado por configuracao (nunca automatico). Ver
// docs/handoffs/2026-09-25-migracao-vio-api-br-com-cache.md.

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
     * Timeout de PROCESSANDO obsoleto do fluxo ANTIGO (backend) — 30s,
     * mantido intocado, usado so pelos metodos legados de
     * App\Dao\AtendimentoDao (rollback manual do fluxo Serpro), nunca mais
     * referenciado pelo fluxo novo abaixo.
     */
    private const TIMEOUT_PROCESSAMENTO_SEGUNDOS = 30;

    /**
     * Limite de DURACAO MAXIMA de processamento assincrono via vio.api.br,
     * a partir do momento em que o envio foi confirmado (ou do inicio da
     * tentativa, em caso de crash antes disso) — apos esse tempo sem
     * resolucao, o documento vira INDETERMINADO (nunca dispara novo POST
     * automatico; fallback manual continua sempre disponivel). Constante de
     * classe (nao .env) por decisao explicita desta implementacao — a lista
     * de variaveis de ambiente novas desta demanda foi fechada pelo usuario
     * (VIO_API_BR_BASE_URL/API_KEY/CACHE_TTL_DIAS/CACHE_HMAC_KEY_V1/
     * CACHE_HMAC_VERSION); alterar este limite exige nova rodada de codigo,
     * mesmo padrao ja usado para TIMEOUT_PROCESSAMENTO_SEGUNDOS acima. Este
     * mesmo valor tambem limita, na pratica, a QUANTIDADE de GETs de
     * polling que o front-end pode gerar para um mesmo documento (cada
     * chamada de statusProcessamento faz no maximo 1 GET, e o front faz
     * polling a cada ~2-3s — ~120s cobre dezenas de tentativas antes de
     * cair em INDETERMINADO).
     */
    private const DURACAO_MAXIMA_PROCESSAMENTO_VIO_API_BR_SEGUNDOS = 120;

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
     * Recebimento (unificado na rodada corretiva de 2026-09-26 — a
     * assimetria anterior foi uma decisao de uma demanda ANTERIOR, revogada
     * explicitamente pelo usuario nesta rodada): CNH frente e verso
     * continuam em CHAMADAS de upload separadas (2 fotos capturadas em
     * momentos distintos pela camera do totem), mas agora na MESMA etapa
     * unica 'rec_cnh' (mesmo padrao ja usado por 'exp_cnh' na Expedicao) —
     * o segundo upload (verso) nao exige mais uma transicao de etapa entre
     * um e outro.
     */
    private const ETAPAS_UPLOAD = [
        'expedicao' => [
            'cnh' => 'exp_cnh',
            'crlv' => 'exp_crlv',
        ],
        'recebimento' => [
            'cnh_frente' => 'rec_cnh',
            'cnh_verso' => 'rec_cnh',
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
            'cnh' => ['rec_cnh', 'rec_crlv', 'rec_aguarde_documentos'],
            'crlv' => ['rec_crlv', 'rec_aguarde_documentos'],
        ],
    ];

    /**
     * Upload das fotos de CNH/CRLV — Expedicao (cnh: frente+verso numa unica
     * chamada) e Recebimento (cnh_frente/cnh_verso em chamadas SEPARADAS,
     * mas ambas dentro da MESMA etapa 'rec_cnh' desde a rodada corretiva de
     * 2026-09-26 — ver ETAPAS_UPLOAD; crlv continua em chamada unica). IDOR
     * corrigido nesta demanda: valida posse/tipo/status/etapa antes de
     * salvar (antes so verificava que o atendimento existia por ID).
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
     * Inicia (ou consulta idempotentemente, se ja em andamento/concluido) a
     * validacao de um documento via vio.api.br. Chamado pelo front-end de
     * forma fire-and-forget logo apos o upload.
     *
     * Fluxo (demanda migracao-vio-api-br-com-cache, 2026-09-25):
     * 1. Documento ja aprovado -> resposta idempotente, nunca reprocessa.
     * 2. Cache VIO_CACHE (fingerprint do QR) -> hit evita QUALQUER chamada
     *    externa, grava direto como CONCLUIDO.
     * 3. Cache miss -> CAS (App\Dao\AtendimentoDao::iniciarEnvioVioApiBr) —
     *    so quem ganha o direito de ENVIAR monta a imagem (PDF de 2 paginas
     *    da CNH via FPDF/Util\AnexoPdfHelper, ou o JPEG unico do CRLV) e
     *    chama App\Rn\VioApiBrClient::enviarParaLeitura() EXATAMENTE 1 vez.
     *    O ID EXTERNO retornado e persistido IMEDIATAMENTE, ANTES de
     *    qualquer polling — o polling real acontece depois, em
     *    statusProcessamento(), nunca aqui.
     *
     * Concorrencia: perdedor do CAS (ou documento ja em qualquer estado nao
     * PENDENTE/ERRO) recebe resposta idempotente com o estado atual, sem
     * reprocessar — nunca gera um segundo POST.
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

        // Fingerprint calculado UMA vez, aqui — o QR bruto so existe em
        // memoria durante este calculo, nunca persistido (nem aqui, nem em
        // App\Rn\DocumentoRn/App\Dao\VioApiBrCacheDao). Fail-closed: env de
        // HMAC ausente/invalida propaga como erro tecnico generico.
        try {
            ['fingerprint' => $fingerprint, 'versao' => $hmacVersao] = $this->documentoRn->calcularFingerprintVioApiBr($bytesQrBrutos);
        } catch (\Throwable $e) {
            $this->logFalhaTecnica("iniciar-processamento (fingerprint) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
            Resposta::erro('Nao foi possivel validar o documento agora', 500);
            return;
        }

        // Cache VIO_CACHE — nunca gera chamada externa em caso de hit.
        try {
            $resultadoCache = $tipo === 'cnh'
                ? $this->documentoRn->tentarCacheCnh($atendimento, $fingerprint, $hmacVersao)
                : $this->documentoRn->tentarCacheCrlv($atendimento, $fingerprint, $hmacVersao);
        } catch (\Throwable $e) {
            $this->logFalhaTecnica("iniciar-processamento (cache) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
            $resultadoCache = null;
        }

        if ($resultadoCache !== null) {
            // Escrita direta (mesmo metodo ja usado pelo preenchimento
            // manual) — cache-hit nunca passa pelo CAS de tentativa, nao ha
            // concorrencia de ENVIO a proteger aqui.
            $this->atendimentoDao->marcarProcessamentoConcluido($idAtendimento, $tipo);
            Resposta::sucesso($resultadoCache);
            return;
        }

        // Unificacao Recebimento/Expedicao (rodada corretiva de 2026-09-26):
        // a CNH so pode ser enviada para validacao externa quando AMBOS os
        // lados (frente/verso) ja estao gravados em disco -- Expedicao ja
        // garante isso por upload() exigir os 2 lados na MESMA chamada
        // (tipo 'cnh'); o Recebimento faz 2 uploads SEPARADOS
        // (cnh_frente/cnh_verso), agora dentro da MESMA etapa 'rec_cnh'
        // (ver ETAPAS_UPLOAD/AtendimentoController::
        // SEQUENCIA_RECEBIMENTO_DOCUMENTOS), entao a maquina de etapas
        // sozinha NAO garante, por si so, que o verso ja foi de fato salvo
        // em disco no momento exato desta chamada (o front-end pode chamar
        // iniciar-processamento assim que a FRENTE e salva, antes do verso
        // sequer ser capturado, ja que nao ha mais transicao de etapa
        // obrigatoria entre um upload e outro). Checagem explicita aqui, ANTES do CAS
        // de envio (App\Dao\AtendimentoDao::iniciarEnvioVioApiBr) -- nunca
        // consome uma tentativa/gera ENVIANDO->ERRO por um problema que nao e
        // tecnico, so "documento ainda incompleto". Sem esta checagem o
        // fluxo ja seria SEGURO mesmo assim (montarPdfCnh()/
        // Util\AnexoPdfHelper::gerarPdfDeImagens() ja rejeita e nunca chega a
        // fazer nenhum POST se um dos 2 arquivos faltar), mas desperdicaria
        // um ciclo de CAS/ERRO por uma condicao inteiramente previsivel.
        if ($tipo === 'cnh' && !$this->arquivosCnhAmbosLadosPresentes($atendimento)) {
            Resposta::erro('Aguardando captura de frente e verso da CNH antes de iniciar a validacao', 409);
            return;
        }

        // CAS: so quem ganha o direito de ENVIAR chama a vio.api.br. Grava
        // tambem o fingerprint/versao (necessarios mais tarde, de forma
        // assincrona, para o cache-write quando a comparacao responder).
        $tentativaId = bin2hex(random_bytes(16));
        $adquiriu = $this->atendimentoDao->iniciarEnvioVioApiBr($idAtendimento, $tipo, $tentativaId, $fingerprint, $hmacVersao);

        if (!$adquiriu) {
            // Ja existe um envio/processamento em andamento OU o documento
            // ja esta em um estado terminal (CONCLUIDO/ERRO tratado por
            // outra chamada nesse meio-tempo/INDETERMINADO) — resposta
            // idempotente, NUNCA um segundo POST.
            $atual = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);
            Resposta::sucesso($this->respostaStatusAtual($atual, $tipo));
            return;
        }

        // Lock de curta duracao adicional (defesa em profundidade) — mesmo
        // papel/limitacoes ja documentados no fluxo antigo: a garantia real
        // contra duplicidade e o CAS acima, nunca este lock.
        $chaveLock = "vio_api_br_validar_{$idAtendimento}_{$tipo}";
        $lockAdquirido = false;
        try {
            $lockAdquirido = $this->obterLock($chaveLock);
        } catch (\PDOException $e) {
            $this->logFalhaTecnica("iniciar-processamento (obter lock adicional) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
        }
        if (!$lockAdquirido) {
            error_log("iniciar-processamento: lock adicional nao adquirido (defesa em profundidade, CAS ja garantiu exclusividade) id_atendimento={$idAtendimento} tipo={$tipo}");
        }

        // Resposta::erro()/exit() NUNCA e chamado dentro deste try/finally —
        // mesma correcao ja aplicada ao fluxo antigo (exit() dentro de um
        // try pula o finally em PHP).
        $erroMensagem = null;
        $erroCodigoHttp = null;

        try {
            try {
                $imagemBinaria = $tipo === 'cnh'
                    ? $this->montarPdfCnh($atendimento)
                    : $this->montarImagemCrlv($atendimento);
            } catch (\Throwable $e) {
                $this->logFalhaTecnica("iniciar-processamento (montar imagem) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
                $this->atendimentoDao->marcarEnvioComoErro($idAtendimento, $tipo, $tentativaId);
                $erroMensagem = 'Nao foi possivel preparar o documento para validacao';
                $erroCodigoHttp = 500;
            }

            if ($erroMensagem === null) {
                try {
                    $vio = new VioApiBrClient();
                } catch (\Throwable $e) {
                    $this->logFalhaTecnica("iniciar-processamento (inicializar VioApiBrClient) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
                    $this->atendimentoDao->marcarEnvioComoErro($idAtendimento, $tipo, $tentativaId);
                    $erroMensagem = 'Servico de validacao de documento indisponivel no momento';
                    $erroCodigoHttp = 503;
                }
            }

            if ($erroMensagem === null) {
                $envio = $vio->enviarParaLeitura($imagemBinaria);
                unset($imagemBinaria);

                if (!$envio['ok']) {
                    if (!empty($envio['ambiguo'])) {
                        // Ambiguidade apos o envio -- NUNCA volta a PENDENTE,
                        // NUNCA permite novo POST automatico.
                        $this->atendimentoDao->marcarEnvioComoIndeterminado($idAtendimento, $tipo, $tentativaId);
                    } else {
                        $this->atendimentoDao->marcarEnvioComoErro($idAtendimento, $tipo, $tentativaId);
                    }
                    $erroMensagem = 'Nao foi possivel validar o documento agora';
                    $erroCodigoHttp = 502;
                } else {
                    $gravou = $this->atendimentoDao->gravarIdExternoVioApiBr($idAtendimento, $tipo, $tentativaId, $envio['id_externo']);
                    if (!$gravou) {
                        error_log("iniciar-processamento: id externo descartado (tentativa obsoleta) id_atendimento={$idAtendimento} tipo={$tipo}");
                    }
                }
            }
        } catch (\Throwable $e) {
            // Excecao NAO PREVISTA em qualquer ponto do envio (antes, durante
            // ou depois de enviarParaLeitura()/gravarIdExternoVioApiBr()) —
            // mesmo padrao de log sanitizado ja usado em statusProcessamento()
            // (achado da /03-revisao independente de 2026-09-26, security-
            // especialista: este bloco tinha so try/finally, sem catch).
            // Como NAO e possivel saber, neste ponto, se o POST real ja
            // chegou a ser recebido pelo fornecedor (a excecao pode ter
            // ocorrido antes do envio, durante o transporte HTTP, ou depois
            // do envio ja ter sido aceito mas antes de persistir o ID
            // externo), o unico estado seguro e INDETERMINADO — nunca ERRO
            // (que permitiria uma nova tentativa automatica/manual gerando
            // um segundo POST sobre uma primeira chamada de resultado
            // desconhecido) e nunca PENDENTE. Mesma mensagem/codigo HTTP
            // generico ja usado para o outro caminho ambiguo (linha acima,
            // $envio['ambiguo']), nunca detalhe tecnico exposto.
            $this->logFalhaTecnica("iniciar-processamento (enviar/gravar) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
            $this->atendimentoDao->marcarEnvioComoIndeterminado($idAtendimento, $tipo, $tentativaId);
            $erroMensagem = 'Nao foi possivel validar o documento agora';
            $erroCodigoHttp = 502;
        } finally {
            if ($lockAdquirido) {
                try {
                    $this->liberarLock($chaveLock);
                } catch (\PDOException $e) {
                    $this->logFalhaTecnica("iniciar-processamento (liberar lock adicional) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
                }
            }
        }

        if ($erroMensagem !== null) {
            Resposta::erro($erroMensagem, $erroCodigoHttp);
            return;
        }

        $atual = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);
        Resposta::sucesso($this->respostaStatusAtual($atual, $tipo));
    }

    /**
     * Monta o PDF de 2 paginas (frente+verso) da CNH, no BACKEND, via FPDF —
     * reaproveita INTEGRALMENTE Util\AnexoPdfHelper::gerarPdfDeImagens(), ja
     * aprovado/em uso real pelo Talent (App\Rn\TalentRn), sem nenhuma
     * biblioteca nova. Esse helper ja garante: nome de arquivo temporario
     * ALEATORIO, geracao FORA do webroot publico (storage/tmp), validacao de
     * contagem EXATA de paginas (2), e limpeza GARANTIDA do arquivo
     * temporario em `finally` (sucesso OU falha) — cobrindo os pontos 5/6/7
     * do escopo desta demanda sem duplicar logica ja aprovada. As imagens
     * JPEG originais (cnh_frente.jpg/cnh_verso.jpg) NUNCA sao modificadas.
     */
    private function montarPdfCnh(array $atendimento): string
    {
        $pasta = rtrim($_ENV['STORAGE_PATH'] ?? '', '/') . '/' . $atendimento['pasta_documentos'];

        return AnexoPdfHelper::gerarPdfDeImagens([
            $pasta . '/cnh_frente.jpg',
            $pasta . '/cnh_verso.jpg',
        ]);
    }

    /**
     * Checagem explicita de que os 2 lados da CNH ja existem em disco —
     * usada SOMENTE como um gate ANTES do CAS de envio (ver comentario em
     * iniciarProcessamento()), nunca como a garantia primaria (essa continua
     * sendo Util\AnexoPdfHelper::validarJpeg()/gerarPdfDeImagens(), que ja
     * rejeita com seguranca se um arquivo faltar).
     */
    private function arquivosCnhAmbosLadosPresentes(array $atendimento): bool
    {
        $pasta = rtrim($_ENV['STORAGE_PATH'] ?? '', '/') . '/' . $atendimento['pasta_documentos'];

        return is_file($pasta . '/cnh_frente.jpg') && is_file($pasta . '/cnh_verso.jpg');
    }

    /**
     * Le os bytes da imagem JPEG unica do CRLV ja salva em disco pelo
     * endpoint de upload() — CRLV usa `comparar:true` com uma unica imagem,
     * nunca PDF.
     */
    private function montarImagemCrlv(array $atendimento): string
    {
        $caminho = rtrim($_ENV['STORAGE_PATH'] ?? '', '/') . '/' . $atendimento['pasta_documentos'] . '/crlv.jpg';

        if (!is_file($caminho)) {
            throw new \RuntimeException('Imagem do CRLV nao encontrada');
        }

        $binario = file_get_contents($caminho);
        if ($binario === false) {
            throw new \RuntimeException('Falha ao ler imagem do CRLV');
        }

        return $binario;
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
     * Polling do front-end (a cada 2-3s) — faz NO MAXIMO 1 GET real contra a
     * vio.api.br POR CHAMADA (nunca loop/sleep interno, nunca mais que 1),
     * so quando ha um ID externo pendente de resolucao
     * (PROCESSANDO_LEITURA/PROCESSANDO_COMPARACAO). Fora disso (PENDENTE/
     * ENVIANDO ainda sem id/estado terminal), e leitura pura do banco —
     * nenhuma chamada externa.
     *
     * Antes de decidir, aplica o limite de DURACAO MAXIMA de processamento
     * (App\Dao\AtendimentoDao::marcarProcessamentoVioApiBrExpiradoComoIndeterminado)
     * — nunca volta a PENDENTE, so avanca para INDETERMINADO.
     */
    public function statusProcessamento(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null; // 'cnh' | 'crlv'

        if (!$idAtendimento || !in_array($tipo, ['cnh', 'crlv'], true)) {
            Resposta::erro('Dados incompletos');
        }

        $this->validarAtendimentoParaProcessamento($idAtendimento, $idTotem, $tipo);

        $this->verificarRateLimitStatus($idAtendimento, $tipo);

        $this->atendimentoDao->marcarProcessamentoVioApiBrExpiradoComoIndeterminado(
            $idAtendimento,
            $tipo,
            self::DURACAO_MAXIMA_PROCESSAMENTO_VIO_API_BR_SEGUNDOS
        );

        $atendimentoAtual = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);
        $statusAtual = $atendimentoAtual["{$tipo}_status_processamento"] ?? 'PENDENTE';
        $vioApiId = $atendimentoAtual["{$tipo}_vio_api_id"] ?? null;
        $tentativaAtual = $atendimentoAtual["{$tipo}_tentativa_id"] ?? null;

        $precisaConsultar = in_array($statusAtual, ['PROCESSANDO_LEITURA', 'PROCESSANDO_COMPARACAO'], true)
            && is_string($vioApiId) && $vioApiId !== ''
            && $this->documentoRn !== null;

        if ($precisaConsultar) {
            try {
                $vio = new VioApiBrClient();
                $resultado = $vio->consultarResultado($vioApiId);
            } catch (\Throwable $e) {
                $this->logFalhaTecnica("status-processamento (consultar) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
                $resultado = null;
            }

            if ($resultado === null) {
                // Falha tecnica ao inicializar/consultar -- GET nunca gera
                // cobranca/duplicidade no fornecedor, seguro classificar
                // como ERRO (permite nova consulta na proxima chamada).
                $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaAtual, 'ERRO');
            } elseif (!$resultado['ok']) {
                $statusFinal = (!empty($resultado['ambiguo']) || !empty($resultado['nao_encontrado'])) ? 'INDETERMINADO' : 'ERRO';
                $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaAtual, $statusFinal);
            } else {
                $this->processarResultadoVioApiBrObtido($idAtendimento, $tipo, $tentativaAtual, $atendimentoAtual, $resultado);
            }

            $atendimentoAtual = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);
        }

        Resposta::sucesso($this->respostaStatusAtual($atendimentoAtual, $tipo));
    }

    /**
     * Interpreta um resultado JA validado/normalizado (ok=true) de
     * App\Rn\VioApiBrClient::consultarResultado() e decide a proxima
     * transicao de estado — nunca decide APROVACAO de negocio aqui (isso
     * continua exclusivo de App\Rn\DocumentoRn::avaliarResultadoVioApiBrCnh/
     * Crlv), so o status_processamento.
     */
    private function processarResultadoVioApiBrObtido(int $idAtendimento, string $tipo, ?string $tentativaId, array $atendimento, array $resultado): void
    {
        if ($resultado['estado_leitura'] === 'processing') {
            return; // continua PROCESSANDO_LEITURA, nada a gravar ainda
        }

        if ($resultado['estado_leitura'] === 'failed') {
            // Resposta DEFINITIVA do fornecedor (nao aprovado) -- CONCLUIDO,
            // nunca ERRO/INDETERMINADO (nao e falha tecnica nem ambiguidade).
            $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaId, 'CONCLUIDO');
            return;
        }

        // estado_leitura === 'completed' daqui em diante.
        if (in_array($resultado['estado_comparacao'], [null, 'pending', 'processing'], true)) {
            $this->atendimentoDao->avancarParaProcessandoComparacao($idAtendimento, $tipo);
            return;
        }

        if (in_array($resultado['estado_comparacao'], ['failed', 'expired'], true)) {
            $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaId, 'CONCLUIDO');
            return;
        }

        // estado_comparacao === 'completed' -- avalia aprovacao automatica
        // (e, se aprovado, atualiza o cache VIO_CACHE) antes de concluir.
        if ($this->documentoRn !== null) {
            try {
                $tipo === 'cnh'
                    ? $this->documentoRn->avaliarResultadoVioApiBrCnh($atendimento, $resultado)
                    : $this->documentoRn->avaliarResultadoVioApiBrCrlv($atendimento, $resultado);
            } catch (\Throwable $e) {
                $this->logFalhaTecnica("status-processamento (avaliar resultado) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
            }
        }

        $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaId, 'CONCLUIDO');
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
                // NAO faz cast prematuro de exercicio para string aqui --
                // demanda vio-crlv-manual-exercicio-validacao (2026-09-20):
                // um valor array/objeto no JSON ja sofreria (string) NESTE
                // ponto, virando a string "Array" (aprovada como dado
                // valido, so warning) ou lancando Error fatal (objeto sem
                // __toString()), ANTES mesmo de chegar a fronteira central
                // de tipo/formato/magnitude/faixa em DocumentoRn. O valor
                // bruto (mixed) e repassado como veio do JSON; quem decide
                // o que fazer com cada tipo e
                // DocumentoRn::validarExercicioCompleto() (mesma fronteira
                // reutilizada pelo fluxo automatico via VIO Decode).
                $exercicioBruto = $entrada['exercicio'] ?? null;
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
                // VIO_CACHE/VIO_API_BR incluidos nesta allowlist (demanda
                // migracao-vio-api-br-com-cache, 2026-09-25; VIO_API_BR
                // acrescentado na rodada corretiva de 2026-09-26 quando a
                // origem da validacao real via vio.api.br deixou de ser
                // gravada como VIO_VALIDADO): VIO_CACHE so chega a ter essa
                // origem gravada quando App\Rn\DocumentoRn::avaliarCrlv() ja
                // aprovou o cache-hit (rntc/tipo_veiculo obrigatoriamente
                // preenchidos nesse caminho — App\Dao\VioApiBrCacheDao::
                // buscarCrlvValido ja garante isso), e VIO_API_BR so e
                // gravada apos aprovacao automatica completa (mesma regra) —
                // mesma garantia de confiabilidade que VIO_TRIAL/VIO_VALIDADO
                // ja tinham.
                $origemAtualCrlv = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
                $ehOrigemVioAtual = in_array($origemAtualCrlv, ['VIO_TRIAL', 'VIO_VALIDADO', 'VIO_CACHE', 'VIO_API_BR'], true);

                if ($rntc === '' && $ehOrigemVioAtual) {
                    $rntc = trim((string) ($atendimento['crlv_rntc'] ?? ''));
                }
                if ($tipoVeiculo === '' && $ehOrigemVioAtual) {
                    $tipoVeiculo = trim((string) ($atendimento['crlv_tipo_veiculo'] ?? ''));
                }

                // "Ausente" para exercicio cobre so null/string vazia (ou
                // so espacos) -- os MESMOS casos ja tratados como "Dados
                // incompletos" para os demais campos deste endpoint. Nao
                // decide nada sobre tipo/formato/magnitude/faixa aqui --
                // isso e responsabilidade exclusiva da fronteira central em
                // DocumentoRn (validarExercicioCompleto()), nunca duplicada
                // neste Controller. Um array/objeto/bool/numero fora da
                // faixa NAO e "ausente" -- segue adiante e e rejeitado de
                // forma controlada (pode_avancar=false) pela fronteira
                // central, nunca aqui.
                $exercicioAusente = $exercicioBruto === null
                    || (is_string($exercicioBruto) && trim($exercicioBruto) === '');

                if ($placa === '' || $exercicioAusente || $uf === '' || $rntc === '' || $tipoVeiculo === '') {
                    Resposta::erro('Dados incompletos');
                }

                $resultado = $this->documentoRn->preencherManualCrlv($atendimento, $placa, $exercicioBruto, $uf, $rntc, $tipoVeiculo);
            }

            $this->atendimentoDao->marcarProcessamentoConcluido($idAtendimento, $tipo);
        } catch (\Throwable $e) {
            $this->logFalhaTecnica("preencher-manual id_atendimento={$idAtendimento} tipo={$tipo}", $e);
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
        // VIO_CACHE/VIO_API_BR incluidos nesta allowlist (demanda
        // migracao-vio-api-br-com-cache, 2026-09-25; VIO_API_BR
        // acrescentado na rodada corretiva de 2026-09-26 — validacao real
        // concluida pela vio.api.br deixou de ser gravada como VIO_VALIDADO,
        // precisa da sua propria entrada aqui) — documento aprovado via
        // cache-hit OU validacao real vio.api.br precisa ser tratado como
        // aprovado igual a VIO_TRIAL/VIO_VALIDADO/MANUAL, nunca preso como
        // "ainda nao aprovado". VIO_VALIDADO permanece na lista so por
        // compatibilidade historica (fluxo antigo Serpro), nunca mais
        // gravado por codigo novo.
        $aprovado = in_array($origem, ['VIO_TRIAL', 'VIO_VALIDADO', 'MANUAL', 'VIO_CACHE', 'VIO_API_BR'], true);

        return [
            'ok' => $aprovado,
            'pode_avancar' => $aprovado,
            'motivo' => $aprovado ? 'Documento ja processado anteriormente' : 'Documento ainda nao aprovado',
            // Sempre false: a vio.api.br nao tem conceito de ambiente
            // trial/producao (isso era exclusivo do fluxo antigo Serpro,
            // VIO_AMBIENTE) — nunca mais chamado automaticamente por este
            // Controller. Campo mantido na resposta so por compatibilidade
            // com o contrato ja consumido pelo front-end.
            'aviso_trial' => false,
            'origem' => $origem,
            'status_revisao' => $statusRevisao,
            'status_processamento' => $statusProcessamento,
            'terminal' => in_array($statusProcessamento, ['CONCLUIDO', 'ERRO', 'INDETERMINADO'], true),
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
    /**
     * Loga falha tecnica generica de forma minima e segura: nunca inclui
     * getMessage(), getTraceAsString(), getFile() ou getLine() da
     * excecao (podem conter SQL, payload, dado pessoal ou detalhe de
     * integracao externa). Registra so o contexto operacional fixo
     * (acao) + a classe concreta da excecao, suficiente para diferenciar
     * rapidamente o tipo de falha em debug futuro sem vazar conteudo.
     */
    private function logFalhaTecnica(string $contexto, \Throwable $e): void
    {
        error_log($contexto . ': falha nao prevista [' . get_class($e) . ']');
    }

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
