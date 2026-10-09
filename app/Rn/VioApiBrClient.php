<?php

namespace App\Rn;

use Util\LogSistema;

class VioApiBrClient
{
    private const TIMEOUT_CONEXAO_SEGUNDOS = 5;
    private const TIMEOUT_TOTAL_SEGUNDOS = 20;

    private const TAMANHO_MAXIMO_RESPOSTA_BYTES = 10 * 1024 * 1024;

    private const MENSAGEM_ERRO_GENERICA = 'Nao foi possivel validar o documento junto ao servico externo. Preencha manualmente.';

    private const ESTADOS_LEITURA_VALIDOS = ['processing', 'completed', 'failed'];
    private const ESTADOS_COMPARACAO_VALIDOS = ['pending', 'processing', 'completed', 'failed', 'expired'];

    private string $baseUrl;
    private string $apiKey;

    private $transporteHttp;

    public function __construct(array $env = [], ?callable $transporteHttp = null)
    {
        $env = $env ?: $_ENV;

        $baseUrl = $env['VIO_API_BR_BASE_URL'] ?? '';
        $apiKey = $env['VIO_API_BR_API_KEY'] ?? '';

        if ($baseUrl === '' || $apiKey === '') {
            throw new \RuntimeException('VIO_API_BR_BASE_URL/VIO_API_BR_API_KEY ausentes');
        }

        $esquema = parse_url($baseUrl, PHP_URL_SCHEME);
        if ($esquema !== 'https') {
            throw new \RuntimeException('VIO_API_BR_BASE_URL precisa ser HTTPS');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->transporteHttp = $transporteHttp ?? [$this, 'executarHttpReal'];
    }

    public function enviarParaLeitura(string $imagemBinaria): array
    {
        $fileName = bin2hex(random_bytes(8)) . '.bin';

        $corpo = [
            'image' => base64_encode($imagemBinaria),
            'file_name' => $fileName,
            'comparar' => true,
        ];

        $resposta = ($this->transporteHttp)(
            'POST',
            $this->baseUrl . '/api/qrcode/read',
            $corpo,
            self::TIMEOUT_CONEXAO_SEGUNDOS,
            self::TIMEOUT_TOTAL_SEGUNDOS
        );

        if ($resposta['erro'] !== null) {
            $this->logFalhaTecnica('envio_' . $resposta['erro']['tipo']);
            $ambiguo = $resposta['erro']['tipo'] === 'timeout_ambiguo';

            return ['ok' => false, 'id_externo' => null, 'ambiguo' => $ambiguo, 'erro' => ['mensagem' => self::MENSAGEM_ERRO_GENERICA]];
        }

        $http = $resposta['http_status'];
        $corpoResp = $resposta['corpo'];

        if (in_array($http, [200, 201, 202], true)) {
            $id = is_array($corpoResp) ? ($corpoResp['id'] ?? null) : null;

            if (!is_string($id) || $id === '') {
                $this->logFalhaTecnica('envio_resposta_sem_id');
                return ['ok' => false, 'id_externo' => null, 'ambiguo' => true, 'erro' => ['mensagem' => self::MENSAGEM_ERRO_GENERICA]];
            }

            return ['ok' => true, 'id_externo' => $id, 'ambiguo' => false, 'erro' => null];
        }

        if ($http === 401 || $http === 403) {
            $this->logFalhaTecnica("envio_autenticacao_{$http}");
            return ['ok' => false, 'id_externo' => null, 'ambiguo' => false, 'erro' => ['mensagem' => self::MENSAGEM_ERRO_GENERICA]];
        }

        if ($http === 400 || $http === 422) {
            $this->logFalhaTecnica("envio_validacao_{$http}");
            return ['ok' => false, 'id_externo' => null, 'ambiguo' => false, 'erro' => ['mensagem' => self::MENSAGEM_ERRO_GENERICA]];
        }

        $this->logFalhaTecnica('envio_http_desconhecido_ou_5xx');
        return ['ok' => false, 'id_externo' => null, 'ambiguo' => true, 'erro' => ['mensagem' => self::MENSAGEM_ERRO_GENERICA]];
    }

    public function consultarResultado(string $idExterno): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $idExterno)) {
            $this->logFalhaTecnica('consulta_id_formato_invalido');
            return $this->resultadoVazio(ambiguo: true, naoEncontrado: false);
        }

        $resposta = ($this->transporteHttp)(
            'GET',
            $this->baseUrl . '/api/qrcode/result/' . rawurlencode($idExterno),
            null,
            self::TIMEOUT_CONEXAO_SEGUNDOS,
            self::TIMEOUT_TOTAL_SEGUNDOS
        );

        if ($resposta['erro'] !== null) {
            $this->logFalhaTecnica('consulta_' . $resposta['erro']['tipo']);
            return $this->resultadoVazio(ambiguo: false, naoEncontrado: false);
        }

        $http = $resposta['http_status'];

        if ($http === 200) {
            return $this->normalizarResultado($resposta['corpo']);
        }

        if ($http === 404) {
            return $this->resultadoVazio(ambiguo: false, naoEncontrado: true);
        }

        if (in_array($http, [400, 401, 403, 422], true)) {
            $this->logFalhaTecnica("consulta_http_{$http}");
            return $this->resultadoVazio(ambiguo: false, naoEncontrado: false);
        }

        $this->logFalhaTecnica('consulta_http_desconhecido_ou_5xx');
        return $this->resultadoVazio(ambiguo: false, naoEncontrado: false);
    }

    private function normalizarResultado(?array $corpo): array
    {
        if (!is_array($corpo)) {
            return $this->resultadoVazio(ambiguo: true, naoEncontrado: false);
        }

        $estadoLeitura = $corpo['status'] ?? null;
        if (!is_string($estadoLeitura) || !in_array($estadoLeitura, self::ESTADOS_LEITURA_VALIDOS, true)) {
            return $this->resultadoVazio(ambiguo: true, naoEncontrado: false);
        }

        $qrType = $corpo['qr_type'] ?? null;
        $qrType = is_string($qrType) ? $qrType : null;

        $dadosLeitura = $corpo['vio_result'] ?? null;
        $dadosLeitura = is_array($dadosLeitura) ? $dadosLeitura : null;

        $estadoComparacao = null;
        $summary = null;
        $campos = [];

        $compareBruto = $corpo['compare'] ?? null;
        if (is_array($compareBruto)) {
            $estadoComparacaoBruto = $compareBruto['status'] ?? null;
            if (is_string($estadoComparacaoBruto) && in_array($estadoComparacaoBruto, self::ESTADOS_COMPARACAO_VALIDOS, true)) {
                $estadoComparacao = $estadoComparacaoBruto;
            }

            $resultCompare = $compareBruto['result'] ?? null;
            if (is_array($resultCompare)) {
                $summaryBruto = $resultCompare['summary'] ?? null;
                if (is_array($summaryBruto)) {
                    $summary = [
                        'reliable' => is_bool($summaryBruto['reliable'] ?? null) ? $summaryBruto['reliable'] : null,
                        'mismatched' => is_int($summaryBruto['mismatched'] ?? null) ? $summaryBruto['mismatched'] : null,
                    ];
                }

                $camposBrutos = $resultCompare['fields'] ?? null;
                if (is_array($camposBrutos)) {
                    foreach ($camposBrutos as $nomeCampo => $infoCampo) {
                        if (!is_string($nomeCampo)) {
                            continue;
                        }
                        $estadoCampo = is_array($infoCampo) ? ($infoCampo['status'] ?? null) : $infoCampo;
                        if (is_string($estadoCampo)) {
                            $campos[$nomeCampo] = $estadoCampo;
                        }
                    }
                }
            }
        }

        $paginasProcessadas = $corpo['pages_processed'] ?? null;
        $totalPaginas = $corpo['total_pages'] ?? null;

        return [
            'ok' => true,
            'ambiguo' => false,
            'nao_encontrado' => false,
            'estado_leitura' => $estadoLeitura,
            'qr_type' => $qrType,
            'dados_leitura' => $dadosLeitura,
            'estado_comparacao' => $estadoComparacao,
            'comparacao' => ['summary' => $summary, 'campos' => $campos],
            'pages_processed' => is_int($paginasProcessadas) ? $paginasProcessadas : null,
            'total_pages' => is_int($totalPaginas) ? $totalPaginas : null,
        ];
    }

    private function resultadoVazio(bool $ambiguo, bool $naoEncontrado): array
    {
        return [
            'ok' => false,
            'ambiguo' => $ambiguo,
            'nao_encontrado' => $naoEncontrado,
            'estado_leitura' => null,
            'qr_type' => null,
            'dados_leitura' => null,
            'estado_comparacao' => null,
            'comparacao' => ['summary' => null, 'campos' => []],
            'pages_processed' => null,
            'total_pages' => null,
        ];
    }

    private function executarHttpReal(string $metodo, string $url, ?array $corpoJson, int $timeoutConexao, int $timeoutTotal): array
    {
        $corpoAcumulado = '';
        $tamanhoAcumulado = 0;
        $excedeuLimite = false;

        $headers = ['Accept: application/json', 'X-API-Key: ' . $this->apiKey];

        $ch = curl_init($url);

        if ($metodo === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpoJson ?? []));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutConexao);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutTotal);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($chRecurso, string $pedaco) use (&$corpoAcumulado, &$tamanhoAcumulado, &$excedeuLimite): int {
            $tamanhoAcumulado += strlen($pedaco);

            if ($tamanhoAcumulado > self::TAMANHO_MAXIMO_RESPOSTA_BYTES) {
                $excedeuLimite = true;
                return 0;
            }

            $corpoAcumulado .= $pedaco;

            return strlen($pedaco);
        });

        curl_exec($ch);
        $erroCurl = curl_errno($ch);
        $codigoHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tempoConexao = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        curl_close($ch);

        if ($excedeuLimite) {
            return ['erro' => ['tipo' => 'resposta_excessiva'], 'http_status' => null, 'corpo' => null];
        }

        if ($erroCurl !== 0) {
            $semConexao = in_array($erroCurl, [CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true);
            if ($semConexao) {
                return ['erro' => ['tipo' => 'rede_sem_conexao'], 'http_status' => null, 'corpo' => null];
            }

            $tipo = $tempoConexao > 0 ? 'timeout_ambiguo' : 'rede_sem_conexao';
            return ['erro' => ['tipo' => $tipo], 'http_status' => null, 'corpo' => null];
        }

        $corpo = json_decode($corpoAcumulado, true);

        return ['erro' => null, 'http_status' => $codigoHttp, 'corpo' => is_array($corpo) ? $corpo : null];
    }

    private function logFalhaTecnica(string $categoria): void
    {
        error_log('VioApiBrClient: falha tecnica categoria=' . $categoria);

        $http = preg_match('/_(\d{3})\z/D', $categoria, $m) === 1 ? (int) $m[1] : null;
        $ctx = ['motivo' => self::motivoDoLogCentral($categoria, $http)];
        if ($http !== null) {
            $ctx['http'] = $http;
        }
        LogSistema::registrar('vio_falha_integracao', $ctx);
    }

    private static function motivoDoLogCentral(string $categoria, ?int $http): string
    {
        if (str_contains($categoria, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($categoria, 'rede_sem_conexao') || str_contains($categoria, '5xx') || ($http !== null && $http >= 500)) {
            return 'indisponivel';
        }
        if ($http === 401 || $http === 403) {
            return 'nao_autorizado';
        }
        if ($http === 400 || $http === 422 || str_contains($categoria, 'formato_invalido') || str_contains($categoria, 'sem_id')) {
            return 'dados_invalidos';
        }

        return 'falha_inesperada';
    }
}
