<?php

namespace App\Controller;

use App\Rn\NotaFiscalRn;
use App\Dao\AtendimentoDao;
use App\Dao\RateLimitOcrDao;
use Util\UploadHelper;
use Util\Resposta;

class NotaController
{
    // Rate limit por totem, so para o endpoint identificar-cliente (ver
    // sql/migrations/004_tb_rate_limit_ocr.sql para o raciocinio completo).
    // Achado do security-especialista na etapa de planejamento: sem o rate
    // limit natural da antiga API externa (~60/min, janela nao confirmada),
    // consultar tb_cliente localmente fica barato demais de abusar como
    // "oraculo" de existencia de CNPJ. Janela fixa de 60s, limite de 30
    // chamadas/totem/janela — dimensionado com folga de ~2x sobre o pior
    // caso realista de uso legitimo (ate 5 notas por atendimento, cada uma
    // gerando 1 chamada "normal" + ate 2 retries de rede com backoff do
    // front-end = ate 15 chamadas em rajada).
    private const RATE_LIMIT_JANELA_SEGUNDOS = 60;
    private const RATE_LIMIT_MAX_CHAMADAS = 30;

    public function __construct(
        private NotaFiscalRn $notaFiscalRn,
        private AtendimentoDao $atendimentoDao,
        private ?RateLimitOcrDao $rateLimitOcrDao = null
    ) {}

    public function processar(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $ordem = (int) ($entrada['ordem'] ?? 0);
        $imagemBase64 = $entrada['imagem'] ?? null;
        $chave = $entrada['chave'] ?? null;

        if (!$idAtendimento || !$ordem || !$imagemBase64) {
            Resposta::erro('Dados incompletos');
        }

        if ($ordem < 1 || $ordem > 5) {
            Resposta::erro('Ordem da nota invalida');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'recebimento') {
            // mensagem generica: nao revela que o atendimento existe mas eh de outro tipo/totem
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }

        if ($atendimento['etapa_atual'] !== 'digitalizacao_notas') {
            Resposta::erro('Atendimento nao esta na etapa de digitalizacao de notas');
        }

        if ($this->notaFiscalRn->contarNotas($idAtendimento) >= 5) {
            Resposta::erro('Limite de 5 notas fiscais ja atingido para esse atendimento');
        }

        if ($this->notaFiscalRn->ordemJaRegistrada($idAtendimento, $ordem)) {
            Resposta::erro('Ja existe uma nota registrada para essa ordem');
        }

        $base64Limpo = null;
        if (is_string($imagemBase64)) {
            $semPrefixo = preg_replace('#^data:image/jpeg;base64,#', '', $imagemBase64);
            $base64Limpo = base64_decode($semPrefixo, true);
        }
        if ($base64Limpo === null || $base64Limpo === false || $base64Limpo === '') {
            Resposta::erro('Imagem invalida');
        }

        $nomeArquivo = sprintf('nota_%02d.jpg', $ordem);

        try {
            $caminhoArquivo = UploadHelper::salvarImagemBase64($imagemBase64, $atendimento['pasta_documentos'], $nomeArquivo);
        } catch (\RuntimeException $e) {
            Resposta::erro('Nao foi possivel salvar a imagem da nota');
        }

        try {
            // identificacao do cliente roda aqui, mas quem decide se pula a tela
            // de confirmacao eh o front-end, chamando /nota.php?acao=status ao finalizar
            $resultado = $this->notaFiscalRn->processarLeitura($idAtendimento, $ordem, $nomeArquivo, $chave);
        } catch (\Throwable $e) {
            // insercao falhou depois do arquivo ja gravado (ex: duplicidade em corrida
            // com a constraint UNIQUE) — nao deixa arquivo orfao sem registro no banco
            @unlink($caminhoArquivo);
            Resposta::erro('Nao foi possivel registrar a nota', 500);
        }

        Resposta::sucesso($resultado);
    }

    /**
     * Endpoint nota.php?acao=identificar-cliente (demanda
     * recebimento-leitura-notas). Recebe candidatos extraidos por OCR
     * client-side (Tesseract.js, fora deste escopo) — entrada tratada como
     * nao confiavel, validada/normalizada dentro de NotaFiscalRn::identificarCliente.
     *
     * Decisao aprovada em 2026-09-04: `chave_ocr` foi removido do processo
     * de identificacao (extracao de 44 digitos via OCR se mostrou
     * estruturalmente fragil em diagnostico real). O campo continua aceito
     * no payload por compatibilidade (o front-end pode mandar `null` ou
     * omitir o campo), mas e completamente IGNORADO aqui — nao validado,
     * nao repassado para logica de negocio.
     */
    public function identificarCliente(array $entrada, int $idTotem): void
    {
        // Rate limit por totem — roda ANTES de qualquer outra validacao de
        // negocio, logo apos Auth::validarTotem() ja ter sido feito por
        // quem despachou para este metodo (nota.php), ja que o limite e por
        // totem autenticado (nao por IP anonimo). Responde 429 + Retry-After
        // e encerra a requisicao (Resposta::erro faz exit()) se excedido.
        $this->verificarRateLimit($idTotem);

        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $ordem = (int) ($entrada['ordem'] ?? 0);
        $cnpjsCandidatos = $entrada['cnpjs_candidatos'] ?? [];
        $razaoSocialCandidata = $entrada['razao_social_candidata'] ?? null;

        if (!$idAtendimento || !$ordem) {
            Resposta::erro('Dados incompletos');
        }

        if ($ordem < 1 || $ordem > 5) {
            Resposta::erro('Ordem da nota invalida');
        }

        if (!is_array($cnpjsCandidatos)) {
            $cnpjsCandidatos = [];
        }
        if ($razaoSocialCandidata !== null && !is_string($razaoSocialCandidata)) {
            $razaoSocialCandidata = null;
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'recebimento') {
            // mensagem generica: nao revela que o atendimento existe mas eh de outro tipo/totem
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }

        if ($atendimento['etapa_atual'] !== 'digitalizacao_notas') {
            Resposta::erro('Atendimento nao esta na etapa de digitalizacao de notas');
        }

        $nota = $this->notaFiscalRn->buscarNotaDaOrdem($idAtendimento, $ordem);
        if ($nota === null) {
            Resposta::erro('Nota nao encontrada para essa ordem', 404);
        }

        try {
            $resultado = $this->notaFiscalRn->identificarCliente(
                $idAtendimento,
                (int) $nota['id_nota'],
                null, // chave_ocr ignorado por completo (decisao 2026-09-04)
                array_values(array_filter($cnpjsCandidatos, 'is_string')),
                $razaoSocialCandidata
            );
        } catch (\Throwable $e) {
            // falha nao prevista (a maioria dos casos de erro tecnico ja e
            // tratada dentro de NotaFiscalRn::identificarCliente, que devolve
            // status ERRO sem lancar excecao) — mesmo padrao de processar():
            // log tecnico completo no servidor, resposta generica ao totem,
            // nunca vaza mensagem/stack trace da excecao.
            error_log('identificar-cliente: falha nao prevista: ' . $e->getMessage());
            Resposta::erro('Nao foi possivel identificar o cliente', 500);
        }

        Resposta::sucesso($resultado);
    }

    public function algumaIdentificada(int $idAtendimento, int $idTotem): void
    {
        $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        $identificada = $this->notaFiscalRn->algumaNotaIdentificouCliente($idAtendimento);
        Resposta::sucesso(['cliente_identificado' => $identificada]);
    }

    /**
     * Rate limit por totem para identificar-cliente (ver constantes da
     * classe). Janela fixa de 60s, incremento atomico via RateLimitOcrDao
     * (INSERT ... ON DUPLICATE KEY UPDATE — sem Redis/APCu, compativel com
     * Hostgator). Se rateLimitOcrDao nao foi injetado (ex: uso futuro fora
     * do endpoint HTTP), a checagem e pulada — nunca quebra o fluxo por
     * ausencia de dependencia opcional.
     */
    private function verificarRateLimit(int $idTotem): void
    {
        if ($this->rateLimitOcrDao === null) {
            return;
        }

        $agora = time();
        $janela = intdiv($agora, self::RATE_LIMIT_JANELA_SEGUNDOS);
        $contador = $this->rateLimitOcrDao->incrementarEContar($idTotem, $janela);

        if ($contador > self::RATE_LIMIT_MAX_CHAMADAS) {
            $segundosRestantes = self::RATE_LIMIT_JANELA_SEGUNDOS - ($agora % self::RATE_LIMIT_JANELA_SEGUNDOS);
            header('Retry-After: ' . $segundosRestantes);
            Resposta::erro('Muitas requisicoes de identificacao de cliente em pouco tempo. Tente novamente em instantes.', 429);
        }
    }

    /**
     * Busca o atendimento e garante que pertence ao totem autenticado.
     * Mensagem de erro generica em qualquer caso de falha (nao existe / eh
     * de outro totem) para nao vazar a existencia de atendimento alheio.
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
