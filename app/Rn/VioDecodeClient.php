<?php

namespace App\Rn;

/**
 * Cliente da API VIO DECODE (Serpro) — validacao de CNH/CRLV via raw value
 * bruto do QR Code. Contrato confirmado em docs/manual_vio_decode.md.
 *
 * Fail-closed no bootstrap (construtor): se VIO_AMBIENTE ausente/invalido,
 * ou faltar a credencial exigida pelo ambiente escolhido, lanca
 * RuntimeException imediatamente — nunca tenta chamar a API sem credencial.
 *
 * decodificar() NUNCA propaga excecao para fora — qualquer falha (rede,
 * timeout, HTTP 4xx/5xx, JSON invalido) e capturada e devolvida como
 * resultado estruturado (['ok' => false, 'erro' => [...]]), para o
 * Controller decidir o proximo passo (retry manual, fallback).
 *
 * Cache do token OAuth2 de Producao: arquivo em storage/cache/ (fora do
 * webroot, mesmo diretorio ja usado pelo projeto para outros caches
 * efemeros), preferido a uma tabela nova porque o dado e puramente
 * transitorio (expira em minutos/horas) e nao precisa de indice/consulta
 * relacional — evita uma migration so para um valor de cache de curta
 * duracao. Nao usado no Trial (Bearer publico fixo, sem OAuth2).
 *
 * Formato do corpo confirmado em 2026-09-08 via teste real com os arquivos
 * de demonstracao oficiais do Serpro (qrcode-trial.bin, crlv-demo.bin): o
 * corpo da requisicao POST e os BYTES BINARIOS PUROS do QR, sem nenhum
 * envelope (nao e JSON, nao e base64, nao e hex, nao e multipart/CURLFile).
 * O envio anterior como JSON {"raw_value": "..."} retornava HTTP 415
 * (Unsupported Media Type) — corrigido nesta rodada. `decodificar()` recebe
 * a string binaria PURA (os mesmos bytes ja decodificados de base64 em
 * App\Controller\DocumentoController::validarQr, sem re-serializacao no
 * meio) e a usa tal qual em CURLOPT_POSTFIELDS.
 *
 * PENDENCIA (nao resolvida por este teste): o QR de demonstracao do Trial
 * (`qrcode-trial.bin`) e um cracha generico, nao uma CNH real — o Trial
 * comprova que o TRANSPORTE HTTP funciona, nao que os dados retornados
 * equivalem aos de uma CNH real. O `crlv-demo.bin` do Trial retorna
 * placa/exercicio como valor placeholder ("xxxxx"), tratado como resultado
 * tecnico "sem dado real utilizavel" em App\Rn\DocumentoRn (nunca aprovado
 * automaticamente so por isso).
 */
class VioDecodeClient
{
    private const TIMEOUT_SEGUNDOS = 20;

    /**
     * Mensagem funcional, fixa e sanitizada devolvida ao totem para
     * QUALQUER falha tecnica de transporte/autenticacao com a VIO Decode —
     * NUNCA inclui status HTTP, curl errno, mensagem bruta do cURL/OAuth2,
     * URL/host, ou qualquer outro detalhe interno (demanda
     * vio-hardening-sem-credenciais, achado 1 do planejamento
     * 2026-09-19). O detalhe tecnico completo, quando necessario para
     * depuracao, so existe em log de servidor via logFalhaTecnica(), de
     * forma minimalista/categorizada (nunca a mensagem bruta da excecao).
     */
    private const MENSAGEM_ERRO_GENERICA = 'Nao foi possivel validar o documento junto ao servico externo. Preencha manualmente.';

    /**
     * Teto de tamanho da resposta HTTP da VIO Decode, aplicado ANTES do
     * json_decode (achado 2 do planejamento 2026-09-19). Escolhido como
     * defesa em profundidade generica (grupo 1, sem credencial Trial para
     * medir o tamanho real de uma resposta de Producao com o campo
     * `image` preenchido — pendencia de grupo 2, ver handoff).
     *
     * Justificativa do valor (10 MB): os arquivos oficiais de demonstracao
     * do Serpro usados no teste real de 2026-09-08
     * (docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md) sao minusculos
     * como REQUISICAO (qrcode-trial.bin = 1041 bytes, crlv-demo.bin = 232
     * bytes) e a RESPOSTA do Trial nesse teste nao contem foto real (dado
     * de demonstracao, sem template.image relevante) — nenhum tamanho real
     * de resposta COM foto (`image` em Base64, CNH/CRLV de Producao) foi
     * observado ate hoje. Uma foto de documento em Base64 pode facilmente
     * passar de 1-2 MB (JPEG de alta resolucao + inflacao ~33% do Base64).
     * 10 MB da margem generosa (5-10x acima do pior caso plausivel de uma
     * unica foto de documento) sem deixar de ser um teto finito que
     * protege contra uma resposta anormalmente grande (bug do servidor VIO
     * ou MITM em rede comprometida) ser integralmente carregada em memoria
     * antes do parse. Nao e uma confirmacao de tamanho real (grupo 2) — e
     * uma defesa em profundidade independente do tamanho real.
     */
    private const TAMANHO_MAXIMO_RESPOSTA_BYTES = 10 * 1024 * 1024;

    private string $ambiente;
    private string $urlDecode;
    private ?string $bearerTrial = null;
    private ?string $tokenUrl = null;
    private ?string $consumerKey = null;
    private ?string $consumerSecret = null;
    private string $caminhoCacheToken;

    public function __construct(array $env = [])
    {
        $env = $env ?: $_ENV;

        $this->ambiente = $env['VIO_AMBIENTE'] ?? '';

        if (!in_array($this->ambiente, ['trial', 'production'], true)) {
            throw new \RuntimeException('VIO_AMBIENTE ausente ou invalido (esperado "trial" ou "production")');
        }

        if ($this->ambiente === 'trial') {
            $this->bearerTrial = $env['VIO_TRIAL_BEARER'] ?? '';
            $this->urlDecode = $env['VIO_TRIAL_DECODE_URL'] ?? '';

            if ($this->bearerTrial === '' || $this->urlDecode === '') {
                throw new \RuntimeException('VIO_TRIAL_BEARER/VIO_TRIAL_DECODE_URL ausentes para VIO_AMBIENTE=trial');
            }
        } else {
            $this->tokenUrl = $env['VIO_TOKEN_URL'] ?? '';
            $this->consumerKey = $env['VIO_CONSUMER_KEY'] ?? '';
            $this->consumerSecret = $env['VIO_CONSUMER_SECRET'] ?? '';
            $this->urlDecode = $env['VIO_PRODUCAO_DECODE_URL'] ?? '';

            if ($this->tokenUrl === '' || $this->consumerKey === '' || $this->consumerSecret === '' || $this->urlDecode === '') {
                throw new \RuntimeException('VIO_TOKEN_URL/VIO_CONSUMER_KEY/VIO_CONSUMER_SECRET/VIO_PRODUCAO_DECODE_URL ausentes para VIO_AMBIENTE=production');
            }
        }

        $storagePath = rtrim($env['STORAGE_PATH'] ?? sys_get_temp_dir(), '/');
        $this->caminhoCacheToken = $storagePath . '/cache/vio_token_production.json';
    }

    public function ambiente(): string
    {
        return $this->ambiente;
    }

    /**
     * Decodifica o QR — $bytesQrBrutos deve ser a STRING BINARIA PURA dos
     * bytes do QR (ja decodificada de base64 pelo chamador, nunca
     * re-serializada). Enviada tal qual no corpo HTTP, sem JSON/base64/hex/
     * multipart. Nunca lanca excecao.
     *
     * @return array{ok:bool, ambiente:string, dados:?array, erro:?array}
     */
    public function decodificar(string $bytesQrBrutos): array
    {
        try {
            $token = $this->ambiente === 'trial' ? $this->bearerTrial : $this->obterAccessTokenProducao();
        } catch (\Throwable $e) {
            // Nunca loga $e->getMessage() bruta aqui — a excecao de
            // obterAccessTokenProducao() pode conter HTTP status/curl errno
            // da chamada OAuth2 (detalhe tecnico de transporte, nao
            // credencial), mas mesmo assim so um marcador categorizado fixo
            // e logado (mesmo padrao de NotaController::logFalhaBancoPdo).
            $this->logFalhaTecnica('autenticacao');
            return $this->erroEstruturado('autenticacao', null, null, self::MENSAGEM_ERRO_GENERICA);
        }

        $tentativas = 0;
        $maxTentativas = 2; // 1 tentativa + 1 retry, so para falha de rede/timeout

        while (true) {
            $tentativas++;
            $resultado = $this->chamarDecode($this->urlDecode, $token, $bytesQrBrutos);

            if ($resultado['tipo'] !== 'rede_timeout' || $tentativas >= $maxTentativas) {
                break;
            }
        }

        if ($resultado['tipo'] === 'sucesso') {
            return [
                'ok' => true,
                'ambiente' => $this->ambiente,
                'dados' => $resultado['dados'],
                'erro' => null,
            ];
        }

        return $this->erroEstruturado(
            $resultado['tipo'],
            $resultado['codigo_vio'] ?? null,
            $resultado['http_status'] ?? null,
            $resultado['mensagem'] ?? 'Falha desconhecida'
        );
    }

    /**
     * Monta e executa a chamada HTTP de decode. $bytesQrBrutos e enviado
     * EXATAMENTE como recebido, como corpo binario puro — PROIBIDO envolver
     * em JSON, base64, hex, multipart/CURLFile ou http_build_query aqui.
     * Confirmado por teste real em 2026-09-08 (arquivos oficiais de
     * demonstracao do Serpro): qualquer envelope em volta dos bytes causa
     * HTTP 415 (Unsupported Media Type).
     */
    private function chamarDecode(string $url, string $bearer, string $bytesQrBrutos): array
    {
        // CURLOPT_RETURNTRANSFER NAO e usado — o corpo e acumulado
        // manualmente via CURLOPT_WRITEFUNCTION, que aborta a transferencia
        // (retornando um tamanho diferente do recebido, o que o libcurl
        // trata como CURLE_WRITE_ERROR) assim que o acumulado ultrapassa
        // TAMANHO_MAXIMO_RESPOSTA_BYTES — o corpo excedente NUNCA chega a
        // ser integralmente carregado em memoria nem passa pelo
        // json_decode (achado 2 do planejamento vio-hardening-sem-credenciais,
        // 2026-09-19).
        $corpoAcumulado = '';
        $tamanhoAcumulado = 0;
        $excedeuLimite = false;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bytesQrBrutos); // string binaria pura, NUNCA array
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/octet-stream',
            'Authorization: Bearer ' . $bearer,
            'Content-Length: ' . strlen($bytesQrBrutos),
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SEGUNDOS);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($chRecurso, string $pedaco) use (&$corpoAcumulado, &$tamanhoAcumulado, &$excedeuLimite): int {
            $tamanhoAcumulado += strlen($pedaco);

            if ($tamanhoAcumulado > self::TAMANHO_MAXIMO_RESPOSTA_BYTES) {
                $excedeuLimite = true;
                return 0; // tamanho != strlen($pedaco) => libcurl aborta com CURLE_WRITE_ERROR
            }

            $corpoAcumulado .= $pedaco;

            return strlen($pedaco);
        });

        curl_exec($ch);
        $erroCurl = curl_errno($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($excedeuLimite) {
            // Nunca loga o conteudo/tamanho exato — so um marcador
            // categorizado fixo, sem bytes da resposta.
            $this->logFalhaTecnica('resposta_excedeu_limite_tamanho');
            return ['tipo' => 'resposta_excessiva', 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
        }

        if ($erroCurl !== 0) {
            $ehTimeout = in_array($erroCurl, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true);
            $this->logFalhaTecnica($ehTimeout ? 'rede_timeout' : 'rede');
            return ['tipo' => $ehTimeout ? 'rede_timeout' : 'rede', 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
        }

        // json_decode ja espera UTF-8 por padrao; a resposta da VIO Decode e
        // JSON declarado, sem indicio de outro charset — sem transformacao
        // adicional necessaria aqui.
        $corpo = json_decode($corpoAcumulado, true);

        if ($codigoHttp === 200) {
            if (!is_array($corpo)) {
                $this->logFalhaTecnica('resposta_200_sem_json_valido');
                return ['tipo' => 'servidor', 'http_status' => $codigoHttp, 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
            }
            return ['tipo' => 'sucesso', 'dados' => $corpo];
        }

        if ($codigoHttp === 400) {
            return ['tipo' => 'validacao', 'http_status' => $codigoHttp, 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
        }

        if ($codigoHttp === 415) {
            // Nao deveria mais ocorrer apos a correcao de 2026-09-08 (era o
            // bug do envelope JSON). Se voltar a acontecer por regressao
            // futura, e um erro TECNICO de formato de transporte — nunca
            // deve ser tratado como 422 (documento invalido), para nao
            // confundir "corpo mal formado pelo nosso codigo" com "QR
            // ilegivel/invalido". Detalhe tecnico so em log categorizado,
            // nunca no campo devolvido ao totem.
            $this->logFalhaTecnica('formato_corpo_invalido_415');
            return ['tipo' => 'formato_corpo_invalido', 'http_status' => $codigoHttp, 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
        }

        if ($codigoHttp === 422) {
            $codigoVio = is_array($corpo) ? ($corpo['codigo'] ?? $corpo['code'] ?? null) : null;
            return ['tipo' => 'qr_invalido', 'http_status' => $codigoHttp, 'codigo_vio' => $codigoVio, 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
        }

        if ($codigoHttp === 401) {
            $this->logFalhaTecnica('autenticacao_401');
            return ['tipo' => 'autenticacao', 'http_status' => $codigoHttp, 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
        }

        if ($codigoHttp === 429) {
            return ['tipo' => 'rate_limit', 'http_status' => $codigoHttp, 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
        }

        if ($codigoHttp >= 500) {
            $this->logFalhaTecnica('servidor_5xx');
            return ['tipo' => 'servidor', 'http_status' => $codigoHttp, 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
        }

        $this->logFalhaTecnica('resposta_http_desconhecida');
        return ['tipo' => 'desconhecido', 'http_status' => $codigoHttp, 'mensagem' => self::MENSAGEM_ERRO_GENERICA];
    }

    private function erroEstruturado(string $tipo, ?string $codigoVio, ?int $httpStatus, string $mensagem): array
    {
        return [
            'ok' => false,
            'ambiente' => $this->ambiente,
            'dados' => null,
            'erro' => [
                'tipo' => $tipo,
                'codigo_vio' => $codigoVio,
                'http_status' => $httpStatus,
                'mensagem' => $mensagem,
            ],
        ];
    }

    /**
     * Log minimalista/categorizado de falha tecnica — mesmo padrao de
     * NotaController::logFalhaBancoPdo() (nunca a mensagem bruta de uma
     * excecao/resposta externa, so um marcador de categoria fixo,
     * validado contra uma allowlist implicita de chamadas literais neste
     * arquivo). NUNCA recebe credencial, token, QR bruto, Base64/imagem,
     * CPF/CNH/placa/nome, ou conteudo bruto da resposta externa.
     */
    private function logFalhaTecnica(string $categoria): void
    {
        error_log('VioDecodeClient: falha tecnica categoria=' . $categoria);
    }

    /**
     * Obtem o access_token de Producao via OAuth2 client_credentials (HTTP
     * Basic Auth, confirmado por teste real contra VIO_TOKEN_URL). Cacheia
     * em arquivo ate perto da expiracao (margem de 30s), evitando obter um
     * token novo a cada chamada de decode.
     */
    private function obterAccessTokenProducao(): string
    {
        $cache = $this->lerCacheToken();
        if ($cache !== null && $cache['expira_em'] > time()) {
            return $cache['access_token'];
        }

        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode("{$this->consumerKey}:{$this->consumerSecret}"),
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
        ]);
        $resposta = curl_exec($ch);
        $erroCurl = curl_errno($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($erroCurl !== 0 || $codigoHttp !== 200) {
            throw new \RuntimeException("Falha ao obter token OAuth2 (HTTP {$codigoHttp}, curl errno {$erroCurl})");
        }

        $corpo = json_decode((string) $resposta, true);
        $accessToken = $corpo['access_token'] ?? null;
        $expiraEmSegundos = (int) ($corpo['expires_in'] ?? 300);

        if (!is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException('Resposta do token OAuth2 sem access_token');
        }

        $this->gravarCacheToken($accessToken, time() + max(30, $expiraEmSegundos - 30));

        return $accessToken;
    }

    private function lerCacheToken(): ?array
    {
        if (!is_file($this->caminhoCacheToken)) {
            return null;
        }

        $conteudo = @file_get_contents($this->caminhoCacheToken);
        $dados = $conteudo !== false ? json_decode($conteudo, true) : null;

        if (!is_array($dados) || !isset($dados['access_token'], $dados['expira_em'])) {
            return null;
        }

        return $dados;
    }

    private function gravarCacheToken(string $accessToken, int $expiraEm): void
    {
        $pasta = dirname($this->caminhoCacheToken);
        if (!is_dir($pasta)) {
            @mkdir($pasta, 0750, true);
        }

        @file_put_contents(
            $this->caminhoCacheToken,
            json_encode(['access_token' => $accessToken, 'expira_em' => $expiraEm]),
            LOCK_EX
        );
    }
}
