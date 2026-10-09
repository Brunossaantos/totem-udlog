<?php

namespace App\Controller;

use PDO;
use Util\LogSistema;
use Util\UploadHelper;
use Util\Resposta;
use App\Dao\AtendimentoDao;
use App\Dao\RateLimitVioStatusDao;
use App\Rn\DocumentoRn;
use App\Rn\VioApiBrClient;


class DocumentoController
{
    private $vioFactory;

    public function __construct(
        private AtendimentoDao $atendimentoDao,
        private ?DocumentoRn $documentoRn = null,
        private ?PDO $pdo = null,
        private ?RateLimitVioStatusDao $rateLimitVioStatusDao = null,
        ?callable $vioFactory = null
    ) {
        $this->vioFactory = $vioFactory;
    }

    private function criarVioClient(): object
    {
        $cliente = $this->vioFactory !== null
            ? ($this->vioFactory)()
            : new VioApiBrClient();

        if (!is_object($cliente)
            || !method_exists($cliente, 'enviarParaLeitura')
            || !method_exists($cliente, 'consultarResultado')) {
            throw new \LogicException('Factory VIO invalida');
        }

        return $cliente;
    }

    private const DURACAO_MAXIMA_PROCESSAMENTO_VIO_API_BR_SEGUNDOS = 120;

    private const RATE_LIMIT_STATUS_JANELA_SEGUNDOS = 5;
    private const RATE_LIMIT_STATUS_MAX_CHAMADAS = 20;

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

    public function iniciarProcessamento(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null;
        $imagemQrBase64 = $entrada['imagem_qr_base64'] ?? null;

        if (!$idAtendimento || !in_array($tipo, ['cnh', 'crlv'], true) || !is_string($imagemQrBase64) || $imagemQrBase64 === '') {
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

        try {
            $imagemBinaria = UploadHelper::decodificarJpegBase64Seguro($imagemQrBase64);
        } catch (\Throwable $e) {
            $this->logFalhaTecnica("iniciar-processamento (jpeg qr) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
            Resposta::erro('Imagem do QR invalida');
            return;
        }

        $tentativaId = bin2hex(random_bytes(16));
        $adquiriu = $this->atendimentoDao->iniciarEnvioVioApiBr($idAtendimento, $tipo, $tentativaId);

        if (!$adquiriu) {
            $atual = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);
            Resposta::sucesso($this->respostaStatusAtual($atual, $tipo));
            return;
        }

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

        $erroMensagem = null;
        $erroCodigoHttp = null;

        try {
            if ($erroMensagem === null) {
                try {
                    $vio = $this->criarVioClient();
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

    public function statusProcessamento(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null;

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

        $motivoUsuario = null;

        if ($precisaConsultar) {
            try {
                $vio = $this->criarVioClient();
                $resultado = $vio->consultarResultado($vioApiId);
            } catch (\Throwable $e) {
                $this->logFalhaTecnica("status-processamento (consultar) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
                $resultado = null;
            }

            if ($resultado === null) {
                $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaAtual, 'ERRO');
            } elseif (!$resultado['ok']) {
                $statusFinal = (!empty($resultado['ambiguo']) || !empty($resultado['nao_encontrado'])) ? 'INDETERMINADO' : 'ERRO';
                $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaAtual, $statusFinal);
            } else {
                $motivoInterno = $this->processarResultadoVioApiBrObtido($idAtendimento, $tipo, $tentativaAtual, $atendimentoAtual, $resultado);
                $motivoUsuario = self::motivoUsuarioDeCodigoInterno($motivoInterno);
            }

            $atendimentoAtual = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);
        }

        $resposta = $this->respostaStatusAtual($atendimentoAtual, $tipo);
        $resposta['motivo_usuario'] = null;
        if (!empty($resposta['terminal']) && empty($resposta['pode_avancar'])
            && ($atendimentoAtual["{$tipo}_status_processamento"] ?? null) === 'CONCLUIDO') {
            $resposta['motivo_usuario'] = $motivoUsuario;
        }
        Resposta::sucesso($resposta);
    }

    private function processarResultadoVioApiBrObtido(int $idAtendimento, string $tipo, ?string $tentativaId, array $atendimento, array $resultado): ?string
    {
        if ($resultado['estado_leitura'] === 'processing') {
            return null;
        }

        if ($resultado['estado_leitura'] === 'failed') {
            $this->logVioReprovado($idAtendimento, $tipo, $resultado, 'leitura_failed');
            $gravou = $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaId, 'CONCLUIDO');
            return $gravou ? 'leitura_failed' : null;
        }

        $motivoReprovacao = null;
        if ($this->documentoRn !== null) {
            try {
                $avaliacao = $tipo === 'cnh'
                    ? $this->documentoRn->avaliarResultadoVioApiBrCnh($atendimento, $resultado)
                    : $this->documentoRn->avaliarResultadoVioApiBrCrlv($atendimento, $resultado);
                if (($avaliacao['pode_avancar'] ?? null) !== true) {
                    $motivoReprovacao = $avaliacao['motivo_codigo'] ?? 'motivo_indisponivel';
                    $this->logVioReprovado($idAtendimento, $tipo, $resultado, $motivoReprovacao);
                }
            } catch (\Throwable $e) {
                $motivoReprovacao = 'excecao_avaliacao';
                $this->logVioReprovado($idAtendimento, $tipo, $resultado, 'excecao_avaliacao', false);
                $this->logFalhaTecnica("status-processamento (avaliar resultado) id_atendimento={$idAtendimento} tipo={$tipo}", $e);
            }
        }

        $gravou = $this->atendimentoDao->gravarResultadoFinalVioApiBr($idAtendimento, $tipo, $tentativaId, 'CONCLUIDO');

        return $gravou ? $motivoReprovacao : null;
    }

    private static function motivoUsuarioDeCodigoInterno(?string $codigo): ?string
    {
        return match ($codigo) {
            'placa_divergente' => 'placa_divergente',
            'cnh_vencida' => 'cnh_vencida',
            'leitura_failed', 'vio_result_ausente', 'qr_type_inesperado', 'paginas_divergentes', 'excecao_avaliacao' => 'documento_ilegivel',
            'placeholder', 'uf_invalida', 'exercicio_invalido', 'tipo_invalido_campo', 'campos_cnh_invalidos' => 'dados_invalidos',
            default => null,
        };
    }

    private function logVioReprovado(int $idAtendimento, string $tipo, array $resultado, string $motivoCodigo, bool $comChaves = true): void
    {
        $estado = $resultado['estado_leitura'] ?? null;
        $estado = in_array($estado, ['completed', 'processing', 'failed'], true) ? $estado : ($estado === null ? 'ausente' : 'outro');
        $qrType = $resultado['qr_type'] ?? null;
        $qrType = $qrType === 'vio' ? 'vio' : ($qrType === null ? 'ausente' : 'outro');
        $tipoLog = $tipo === 'cnh' ? 'cnh' : 'crlv';
        if (!preg_match('/\A[a-z_]{1,40}\z/', $motivoCodigo)) {
            $motivoCodigo = 'motivo_indisponivel';
        }

        $linha = "[DocumentoController] vio_reprovado id={$idAtendimento} tipo={$tipoLog} estado_leitura={$estado} qr_type={$qrType} motivo={$motivoCodigo}";

        if ($comChaves && is_array($resultado['dados_leitura'] ?? null)) {
            $chaves = [];
            foreach (array_slice(array_keys($resultado['dados_leitura']), 0, 20) as $chave) {
                $limpa = preg_replace('/[^A-Za-z0-9_ çÇãÃéÉíóúâêôà.\-]/u', '', (string) $chave);
                $chaves[] = mb_substr($limpa ?? '', 0, 40);
            }
            $linha .= ' chaves_vio_result=' . implode(',', $chaves);
        }

        error_log($linha);
    }

    public function preencherManual(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null;

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
                $exercicioBruto = $entrada['exercicio'] ?? null;
                $uf = (string) ($entrada['uf'] ?? '');
                $rntc = (string) ($entrada['rntc'] ?? '');
                $tipoVeiculo = (string) ($entrada['tipo_veiculo'] ?? '');

                $origemAtualCrlv = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
                $ehOrigemVioAtual = in_array($origemAtualCrlv, ['VIO_TRIAL', 'VIO_VALIDADO', 'VIO_CACHE', 'VIO_API_BR'], true);

                if ($rntc === '' && $ehOrigemVioAtual) {
                    $rntc = trim((string) ($atendimento['crlv_rntc'] ?? ''));
                }
                if ($tipoVeiculo === '' && $ehOrigemVioAtual) {
                    $tipoVeiculo = trim((string) ($atendimento['crlv_tipo_veiculo'] ?? ''));
                }

                $exercicioAusente = $exercicioBruto === null
                    || (is_string($exercicioBruto) && trim($exercicioBruto) === '');

                if ($placa === '' || $exercicioAusente || $uf === '') {
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

    private function respostaStatusAtual(array $atendimento, string $tipo): array
    {
        $origem = $atendimento["{$tipo}_origem_validacao"] ?? 'NAO_VALIDADO';
        $statusRevisao = $atendimento["{$tipo}_status_revisao"] ?? 'OK';
        $statusProcessamento = $atendimento["{$tipo}_status_processamento"] ?? 'PENDENTE';
        $aprovado = in_array($origem, ['VIO_TRIAL', 'VIO_VALIDADO', 'MANUAL', 'VIO_CACHE', 'VIO_API_BR'], true);

        return [
            'ok' => $aprovado,
            'pode_avancar' => $aprovado,
            'motivo' => $aprovado ? 'Documento ja processado anteriormente' : 'Documento ainda nao aprovado',
            'aviso_trial' => false,
            'origem' => $origem,
            'status_revisao' => $statusRevisao,
            'status_processamento' => $statusProcessamento,
            'terminal' => in_array($statusProcessamento, ['CONCLUIDO', 'ERRO', 'INDETERMINADO'], true),
        ];
    }

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

    private function logFalhaTecnica(string $contexto, \Throwable $e): void
    {
        error_log($contexto . ': falha nao prevista [' . get_class($e) . ']');
        LogSistema::registrar('erro_tecnico', ['excecao' => $e]);
    }

    private function obterLock(string $chave): bool
    {
        if ($this->pdo === null) {
            return true;
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

    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }
}
