<?php

namespace App\Rn;

class TalentClient
{
    private const TIMEOUT_SEGUNDOS = 30;

    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = ''
    ) {}

    public function checkin(array $payload): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . '/Portaria/Checkin');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                "Authorization: Bearer {$this->apiKey}",
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
        ]);

        $resposta = curl_exec($ch);
        $erroCurl = curl_errno($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($erroCurl !== 0) {
            $ehTimeoutCurl = $erroCurl === CURLE_OPERATION_TIMEDOUT;
            $ehOutroIndeterminadoCurl = in_array($erroCurl, [
                CURLE_RECV_ERROR,
                CURLE_GOT_NOTHING,
                CURLE_PARTIAL_FILE,
                CURLE_SSL_CONNECT_ERROR,
                CURLE_SEND_ERROR,
            ], true);

            $categoria = match (true) {
                $ehTimeoutCurl => 'timeout',
                $ehOutroIndeterminadoCurl => 'erro_indeterminado',
                default => 'erro_conexao',
            };
            throw new TalentClientException($categoria);
        }

        if ($resposta === false) {
            throw new TalentClientException('erro_conexao');
        }

        if ($codigoHttp >= 200 && $codigoHttp < 300) {
            $dados = json_decode($resposta, true);
            unset($resposta);

            if (!is_array($dados)) {
                return ['senha' => null, 'protocolo' => null];
            }

            unset($dados['msg']);

            return [
                'senha' => is_string($dados['nrRegAcesso'] ?? null) ? $dados['nrRegAcesso'] : null,
                'protocolo' => null,
            ];
        }

        $mensagemApi = is_string($resposta) ? \Util\MensagemApi::extrairDoCorpo($resposta) : null;
        unset($resposta);

        throw (new TalentClientException(match ($codigoHttp) {
            400 => 'erro_validacao',
            401 => 'erro_autenticacao',
            404 => 'nao_encontrado',
            409 => 'conflito',
            500 => 'erro_servidor',
            default => 'erro_http',
        }))->comMensagemApi($mensagemApi);
    }
}
