<?php

namespace App\Rn;

/**
 * Cliente da API contratada `vio.api.br` — SUBSTITUI o USO direto do Serpro
 * (App\Rn\VioDecodeClient, mantido fisicamente intocado no repositorio so
 * para rollback manual/controlado por configuracao, NUNCA instanciado
 * automaticamente como fallback).
 *
 * Duas responsabilidades SEPARADAS, cada uma com no maximo 1 chamada HTTP
 * por invocacao — nenhum dos dois metodos faz polling/loop/sleep interno
 * (a Hostgator compartilhada nao permite processo persistente; o polling
 * real acontece do lado de fora, 1 GET por chamada de
 * App\Controller\DocumentoController::statusProcessamento):
 *
 * - enviarParaLeitura(): exatamente 1 POST /api/qrcode/read. Nunca repete o
 *   POST automaticamente, mesmo em timeout — quem decide se uma nova
 *   tentativa e permitida e a maquina de estados do Controller/AtendimentoDao
 *   (CAS por tentativa_id), nunca este cliente.
 * - consultarResultado(): exatamente 1 GET /api/qrcode/result/{id}. Nunca
 *   faz POST.
 *
 * Fail-closed no construtor: VIO_API_BR_BASE_URL/VIO_API_BR_API_KEY
 * ausentes/vazias, ou base URL nao-HTTPS, lancam RuntimeException imediata
 * — nunca tenta chamar a API sem credencial/transporte seguro configurado.
 *
 * Cliente HTTP INJETAVEL (segundo parametro do construtor, $transporteHttp)
 * — permite mock completo em teste, sem rede real, mesmo espirito de
 * App\Rn\VioDecodeClient ja usado no projeto (que e substituido inteiro por
 * uma classe fake em teste; aqui a granularidade e menor, no nivel do
 * transporte HTTP, porque este cliente tem 2 metodos publicos distintos que
 * precisam ser exercitados independentemente).
 *
 * NUNCA usa `compare_lines=true`. NUNCA loga corpo de resposta, chave de API,
 * QR bruto, Base64/imagem ou dado pessoal — so uma categoria tecnica fixa
 * (mesmo padrao de App\Rn\VioDecodeClient::logFalhaTecnica()).
 *
 * PENDENCIA registrada (contrato da vio.api.br tratado como PLACEHOLDER
 * fornecido pelo usuario nesta demanda, nao confirmado oficialmente pelo
 * fornecedor — ver docs/handoffs/2026-09-25-migracao-vio-api-br-com-cache.md,
 * secao "Perguntas ao fornecedor"): o formato exato de "saldo insuficiente"
 * dentro de `error_message` (leitura `status:"failed"` sem HTTP de erro) nao
 * e documentado — este cliente NAO tenta reconhecer essa categoria
 * especifica por string matching (evitaria inventar um contrato nao
 * confirmado); ela e tratada pelo mesmo caminho generico de "leitura/
 * comparacao failed", que ja resulta em CONCLUIDO + fallback manual com
 * mensagem sanitizada — o efeito pratico para o totem e o mesmo, so a
 * categorizacao interna de log nao diferencia "saldo insuficiente" de outra
 * falha definitiva do fornecedor.
 */
class VioApiBrClient
{
    /**
     * Timeouts SEPARADOS (conexao vs. total), ambos como constantes de
     * classe — mesmo padrao ja usado no projeto para valores tecnicos deste
     * tipo (ex.: App\Rn\VioDecodeClient::TIMEOUT_SEGUNDOS), nao expostos via
     * .env porque a lista de variaveis novas desta demanda foi fechada
     * explicitamente pelo usuario (VIO_API_BR_BASE_URL/API_KEY/
     * CACHE_TTL_DIAS/CACHE_HMAC_KEY_V1/CACHE_HMAC_VERSION) — alterar aqui
     * exige nova rodada de codigo, nao uma variavel de ambiente nova.
     */
    private const TIMEOUT_CONEXAO_SEGUNDOS = 5;
    private const TIMEOUT_TOTAL_SEGUNDOS = 20;

    /**
     * Teto de tamanho da resposta HTTP, aplicado ANTES do json_decode —
     * mesma defesa em profundidade e mesma ordem de grandeza ja usada e
     * documentada em App\Rn\VioDecodeClient::TAMANHO_MAXIMO_RESPOSTA_BYTES.
     */
    private const TAMANHO_MAXIMO_RESPOSTA_BYTES = 10 * 1024 * 1024;

    private const MENSAGEM_ERRO_GENERICA = 'Nao foi possivel validar o documento junto ao servico externo. Preencha manualmente.';

    private const ESTADOS_LEITURA_VALIDOS = ['processing', 'completed', 'failed'];
    private const ESTADOS_COMPARACAO_VALIDOS = ['pending', 'processing', 'completed', 'failed', 'expired'];

    private string $baseUrl;
    private string $apiKey;

    /** @var callable */
    private $transporteHttp;

    /**
     * @param callable|null $transporteHttp Assinatura:
     *   function(string $metodo, string $url, ?array $corpoJson, int $timeoutConexao, int $timeoutTotal): array{
     *     erro: ?array{tipo:string}, http_status: ?int, corpo: ?array
     *   }
     *   Quando null, usa a implementacao real via cURL (executarHttpReal()).
     *   Injetavel para permitir mock completo sem rede em teste.
     */
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
            // Fail-closed: nunca permite HTTP puro nem URL malformada —
            // certificado/HTTPS sempre validado (CURLOPT_SSL_VERIFYPEER/
            // VERIFYHOST nunca desabilitados em executarHttpReal()).
            throw new \RuntimeException('VIO_API_BR_BASE_URL precisa ser HTTPS');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->transporteHttp = $transporteHttp ?? [$this, 'executarHttpReal'];
    }

    /**
     * Exatamente 1 POST /api/qrcode/read. $imagemBinaria e o conteudo
     * binario JA PRONTO para envio (PDF de 2 paginas para CNH, JPEG unico
     * para CRLV) — nunca o QR isolado (o QR bruto so e usado, em memoria,
     * para calcular o fingerprint do cache, nunca enviado ao fornecedor
     * isoladamente nem persistido).
     *
     * @return array{ok:bool, id_externo:?string, ambiguo:bool, erro:?array{mensagem:string}}
     *   ambiguo=true significa que NAO se sabe se o fornecedor recebeu/
     *   processou a chamada (ex.: timeout apos a conexao ja estabelecida,
     *   ou HTTP 5xx) — o chamador NUNCA deve tentar um novo POST
     *   automaticamente nesse caso (risco de cobranca duplicada), e deve
     *   transicionar o documento para INDETERMINADO, nunca ERRO.
     */
    public function enviarParaLeitura(string $imagemBinaria): array
    {
        // Nome de arquivo ALEATORIO — nunca nome/CPF/placa/qualquer dado do
        // atendimento.
        $fileName = bin2hex(random_bytes(8)) . '.bin';

        $corpo = [
            'image' => base64_encode($imagemBinaria),
            'file_name' => $fileName,
            'comparar' => true, // NUNCA compare_lines=true
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
                // Contrato minimo nao atendido -- nunca confiamos em um id
                // ausente; tratado como ambiguo (nao sabemos se o fornecedor
                // considerou a chamada valida do lado dele).
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

        // 5xx/desconhecido: nao sabemos se o fornecedor chegou a processar
        // a chamada do lado dele antes de falhar -- tratado como ambiguo,
        // por seguranca (nunca retry automatico, nunca assume que nao foi
        // cobrado).
        $this->logFalhaTecnica('envio_http_desconhecido_ou_5xx');
        return ['ok' => false, 'id_externo' => null, 'ambiguo' => true, 'erro' => ['mensagem' => self::MENSAGEM_ERRO_GENERICA]];
    }

    /**
     * Exatamente 1 GET /api/qrcode/result/{id}. Nunca faz POST. Valida o
     * FORMATO do id ANTES de montar a URL (defesa contra injecao/
     * malformacao). Nunca persiste a resposta integra (nem aqui, nem em
     * log) -- so devolve os campos normalizados que App\Rn\DocumentoRn
     * precisa.
     *
     * @return array{
     *   ok:bool, ambiguo:bool, nao_encontrado:bool,
     *   estado_leitura:?string, qr_type:?string, dados_leitura:?array,
     *   estado_comparacao:?string,
     *   comparacao:array{summary:?array{reliable:?bool,mismatched:?int}, campos:array<string,string>},
     *   pages_processed:?int, total_pages:?int
     * }
     *   ambiguo=true (resposta fora do contrato minimo esperado) e
     *   nao_encontrado=true (HTTP 404) SEMPRE devem levar o chamador a
     *   INDETERMINADO, nunca a um novo POST. Qualquer outro `ok=false` (erro
     *   de transporte/HTTP 400/401/403/422/desconhecido) e uma falha
     *   TECNICA de LEITURA (GET nunca gera cobranca nem duplicidade no
     *   fornecedor) -- pode ser classificada como ERRO pelo chamador,
     *   permitindo nova consulta.
     */
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

    /**
     * Valida o contrato MINIMO esperado antes de confiar em qualquer campo
     * (achado do handoff: nunca confiar em resposta sem validar
     * estrutura/tipo primeiro). Qualquer campo fora do formato esperado e
     * simplesmente descartado (vira null), nunca causa erro fatal -- so a
     * ausencia/formato invalido do campo `status` (estado de LEITURA) e
     * suficiente para marcar a resposta inteira como ambigua.
     */
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

    /**
     * Implementacao real do transporte HTTP via cURL — HTTPS/certificado
     * SEMPRE validados (CURLOPT_SSL_VERIFYPEER/VERIFYHOST nunca
     * desabilitados), teto de bytes aplicado via CURLOPT_WRITEFUNCTION
     * (mesmo padrao de App\Rn\VioDecodeClient::chamarDecode), timeout de
     * CONEXAO e TOTAL separados.
     */
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
                // Nunca chegou a conectar -- seguro classificar como falha
                // de rede SEM ambiguidade (o fornecedor nunca recebeu nada).
                return ['erro' => ['tipo' => 'rede_sem_conexao'], 'http_status' => null, 'corpo' => null];
            }

            // Qualquer outro erro de transporte (incluindo timeout) APOS a
            // conexao ja ter sido estabelecida ($tempoConexao > 0) e
            // AMBIGUO -- nao sabemos se a requisicao chegou a ser recebida/
            // processada do lado do fornecedor.
            $tipo = $tempoConexao > 0 ? 'timeout_ambiguo' : 'rede_sem_conexao';
            return ['erro' => ['tipo' => $tipo], 'http_status' => null, 'corpo' => null];
        }

        $corpo = json_decode($corpoAcumulado, true);

        return ['erro' => null, 'http_status' => $codigoHttp, 'corpo' => is_array($corpo) ? $corpo : null];
    }

    /**
     * Log minimalista/categorizado -- mesmo padrao de App\Rn\VioDecodeClient
     * ::logFalhaTecnica(). NUNCA recebe credencial, QR bruto, Base64/imagem,
     * CPF/CNH/placa/nome, id externo ou conteudo bruto da resposta.
     */
    private function logFalhaTecnica(string $categoria): void
    {
        error_log('VioApiBrClient: falha tecnica categoria=' . $categoria);
    }
}
