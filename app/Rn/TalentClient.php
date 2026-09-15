<?php

namespace App\Rn;

/**
 * Adaptador para a API oficial do Talent (Portaria/Checkin), conforme
 * contrato confirmado em docs/manual_talent.md — substitui o contrato
 * placeholder anterior (/atendimentos, nunca existiu no manual real).
 *
 * Endpoint: POST {TALENT_API_URL rtrim('/')}/Portaria/Checkin — TALENT_API_URL
 * e a BASE URL (ex: https://api.talentcs.com.br), conforme "Base URL" da
 * secao "Padroes tecnicos gerais" do manual, que documenta separadamente a
 * base URL e o path de cada endpoint (/Portaria/Checkin, /Inbound,
 * /Outbound, etc.) — mesma convencao ja usada no restante do projeto
 * (TALENT_API_URL como base, path fixo apendado no client).
 *
 * Autenticacao: header Authorization: Bearer <token>, conforme manual
 * ("Padroes tecnicos gerais").
 *
 * Timeout: 30s — valor explicito escolhido nesta implementacao (o manual nao
 * documenta timeout recomendado para este endpoint; escolha empirica,
 * folgada o suficiente para nao cortar uma resposta legitima, curta o
 * suficiente para nao travar a tela de impressao do totem por tempo
 * excessivo).
 *
 * NUNCA loga/persiste o corpo bruto da requisicao ou da resposta, nem o
 * valor de TALENT_API_KEY — todo erro e traduzido para uma categoria interna
 * fechada (TalentClientException::categoria()) antes de sair deste metodo.
 */
class TalentClient
{
    private const TIMEOUT_SEGUNDOS = 30;

    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = ''
    ) {}

    /**
     * @param array $payload payload ja montado por App\Rn\TalentRn::montarPayload
     * @return array{senha: ?string, protocolo: ?string} formato de resposta
     *         real CONFIRMADO via Swagger oficial em 2026-09-14
     *         (TPortariaCheckinRet = {nrRegAcesso: string|null, msg:
     *         string|null}) — substitui o allowlist anterior
     *         (senha/protocolo), que nunca existiram na API real.
     *         'nrRegAcesso' e mapeado para a chave interna 'senha' (mantida
     *         por compatibilidade com App\Dao\AtendimentoDao::gravarResultadoEnvioTalent
     *         e o restante do fluxo, que ja consomem esse nome — decisao
     *         documentada aqui em vez de renomear a coluna/assinatura em
     *         cascata). 'protocolo' NUNCA e preenchido pela API real (o
     *         campo tb_atendimento.talent_protocolo fica permanentemente
     *         NULL apos esta correcao — achado registrado em
     *         ia_development_state.md para limpeza futura de schema).
     *         'msg' e SOMENTE usado transitoriamente aqui dentro (nunca
     *         logado/persistido/retornado) — hoje sem uso de decisao
     *         interna, mas o parsing ja o descarta explicitamente por
     *         clareza.
     * @throws TalentClientException categorizada (nunca com corpo bruto)
     */
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
            // Correcao de seguranca (revisao security-especialista, ver
            // docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md,
            // secao "Idempotencia — 5 estados"): a classificacao de erro de
            // curl precisa distinguir entre "a requisicao NUNCA saiu do
            // totem" (seguro reenviar automaticamente — erro_conexao,
            // ERRO_REPROCESSAVEL) e "nao se sabe se o Talent recebeu/
            // processou a requisicao" (NUNCA pode ser reenviado automatico —
            // deve virar ENVIO_INDETERMINADO).
            //
            // CURLE_COULDNT_RESOLVE_HOST / CURLE_COULDNT_CONNECT ocorrem
            // ANTES de qualquer byte ser transmitido ao servidor — nenhum
            // dado chegou ao Talent, entao e seguro tratar como
            // erro_conexao/reprocessavel.
            //
            // Qualquer erro que possa ocorrer DEPOIS que a requisicao (ou
            // parte dela) ja foi enviada ao servidor — timeout aguardando
            // resposta, conexao resetada/recebendo dados (RECV_ERROR),
            // servidor fechou a conexao sem responder (GOT_NOTHING),
            // resposta cortada no meio (PARTIAL_FILE), ou falha de TLS que
            // pode ocorrer apos o handshake (SSL_CONNECT_ERROR) — e tratado
            // como indeterminado por precaucao, mesmo que na pratica alguns
            // desses ocorram antes do envio completo: o custo de reenviar
            // indevidamente um checkin ja processado (duplicidade) e maior
            // que o custo de exigir intervencao manual num caso que na
            // verdade era seguro reenviar.
            $ehTimeoutCurl = $erroCurl === CURLE_OPERATION_TIMEDOUT;
            $ehOutroIndeterminadoCurl = in_array($erroCurl, [
                CURLE_RECV_ERROR,
                CURLE_GOT_NOTHING,
                CURLE_PARTIAL_FILE,
                CURLE_SSL_CONNECT_ERROR,
                CURLE_SEND_ERROR,
            ], true);

            // Excecao de rede/DNS/timeout — NUNCA inclui curl_error($ch) na
            // mensagem (pode ecoar a URL com o token em alguns casos de
            // erro de biblioteca) nem qualquer outro detalhe bruto.
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
            unset($resposta); // corpo bruto nunca sobrevive alem deste ponto

            if (!is_array($dados)) {
                // 2xx mas corpo nao interpretavel como JSON — nao e erro
                // fatal do ponto de vista do check-in (o Talent respondeu
                // sucesso), mas nao ha dado extra para extrair.
                return ['senha' => null, 'protocolo' => null];
            }

            // 'msg' (nullable, TPortariaCheckinRet) e descartado logo apos a
            // leitura — NUNCA logado nem persistido (corpo de texto livre
            // vindo do Talent, potencialmente com dado sensivel de negocio).
            unset($dados['msg']);

            return [
                'senha' => is_string($dados['nrRegAcesso'] ?? null) ? $dados['nrRegAcesso'] : null,
                'protocolo' => null,
            ];
        }

        unset($resposta); // corpo bruto de erro NUNCA persistido/logado

        throw new TalentClientException(match ($codigoHttp) {
            400 => 'erro_validacao',
            401 => 'erro_autenticacao',
            404 => 'nao_encontrado',
            // 409 tratado como categoria propria por precaucao — NAO
            // reconciliado automaticamente como duplicidade nesta versao
            // (ver TalentClientException::ehConflito() e TODO em TalentRn).
            409 => 'conflito',
            500 => 'erro_servidor',
            default => 'erro_http',
        });
    }
}
