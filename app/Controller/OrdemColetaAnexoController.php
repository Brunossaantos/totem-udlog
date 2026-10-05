<?php

namespace App\Controller;

use App\Rn\OrdemColetaArquivoRn;
use Util\AuthServidor;
use Util\Resposta;

/**
 * POST public/api/ordem-coleta-anexo.php — recebe o PDF da Ordem de Coleta do
 * n8n (demanda anexo-ordem-coleta-n8n, 2026-10-05). Autenticacao propria
 * servidor-a-servidor (Util\AuthServidor), distinta do token do totem.
 *
 * Ordem: metodo (405) -> HTTPS/chave/rate limit/autenticacao -> limite do
 * corpo ANTES de decodificar (Content-Length e leitura com teto) -> JSON ->
 * OrdemColetaArquivoRn. Respostas no formato Util\Resposta com codigo estavel
 * e mensagem fixa; nunca ecoa entrada, caminho ou mensagem de excecao.
 */
class OrdemColetaAnexoController
{
    public function __construct(
        private AuthServidor $auth,
        private OrdemColetaArquivoRn $rn,
        private int $tetoCorpoBytes
    ) {}

    /**
     * @param array<string,mixed> $server tipicamente $_SERVER
     * @param string|null $authorization header Authorization recebido
     * @param callable $abrirCorpo devolve o recurso do corpo (php://input)
     */
    public function processar(array $server, ?string $authorization, callable $abrirCorpo): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            self::responder(405, 'METODO_NAO_PERMITIDO', 'Metodo nao permitido.');
        }

        $falha = $this->auth->verificar($server, $authorization);
        if ($falha !== null) {
            $mensagens = [
                'HTTPS_OBRIGATORIO' => 'HTTPS obrigatorio.',
                'INDISPONIVEL' => 'Servico temporariamente indisponivel.',
                'MUITAS_TENTATIVAS' => 'Muitas tentativas. Tente novamente mais tarde.',
                'NAO_AUTORIZADO' => 'Nao autorizado.',
            ];
            if (isset($falha['retry_after'])) {
                header('Retry-After: ' . (int) $falha['retry_after']);
            }
            self::responder($falha['http'], $falha['codigo'], $mensagens[$falha['codigo']] ?? 'Nao autorizado.');
        }

        $declarado = null;
        $cl = $server['CONTENT_LENGTH'] ?? null;
        if (is_string($cl) && preg_match('/\A\d{1,15}\z/D', $cl) === 1) {
            $declarado = (int) $cl;
        }
        if ($declarado !== null && $declarado > $this->tetoCorpoBytes) {
            self::responder(413, 'ARQUIVO_MUITO_GRANDE', 'Arquivo acima do limite permitido.');
        }

        $corpo = $this->lerCorpoLimitado($abrirCorpo);
        if ($corpo === null) {
            self::responder(413, 'ARQUIVO_MUITO_GRANDE', 'Arquivo acima do limite permitido.');
        }
        if ($corpo === '') {
            // Content-Length > 0 mas nada chegou em php://input: o PHP descarta
            // o corpo quando excede post_max_size (nao e JSON invalido).
            if ($declarado !== null && $declarado > 0) {
                self::responder(413, 'ARQUIVO_MUITO_GRANDE', 'Arquivo acima do limite permitido.');
            }
            self::responder(400, 'CORPO_INVALIDO', 'Corpo da requisicao invalido.');
        }

        $entrada = json_decode($corpo, true, 8);
        unset($corpo);
        if (!is_array($entrada)) {
            // JSON invalido ou escalar (5, "x", true, null)
            self::responder(400, 'CORPO_INVALIDO', 'Corpo da requisicao invalido.');
        }

        try {
            $resultado = $this->rn->receber($entrada);
        } catch (\Throwable $e) {
            error_log('OrdemColetaAnexoController: falha_inesperada ' . get_class($e));
            self::responder(500, 'ERRO_INTERNO', 'Erro interno ao processar o arquivo.');
        }

        if ($resultado['http'] >= 400) {
            self::responder($resultado['http'], $resultado['codigo'], $resultado['mensagem'], $resultado['dados'] ?? []);
        }

        http_response_code($resultado['http']);
        Resposta::sucesso($resultado['dados'] ?? []);
    }

    /**
     * Le php://input em blocos com teto (tetoCorpoBytes). Null = excedeu o teto.
     */
    private function lerCorpoLimitado(callable $abrirCorpo): ?string
    {
        $h = $abrirCorpo();
        if (!is_resource($h)) {
            return '';
        }
        $acumulado = '';
        $total = 0;
        while (!feof($h)) {
            $bloco = fread($h, 262144);
            if ($bloco === false || $bloco === '') {
                break;
            }
            $total += strlen($bloco);
            if ($total > $this->tetoCorpoBytes) {
                fclose($h);

                return null;
            }
            $acumulado .= $bloco;
        }
        fclose($h);

        return $acumulado;
    }

    private static function responder(int $http, string $codigo, string $mensagem, array $dados = []): void
    {
        Resposta::erroComDados($mensagem, $codigo, $dados, $http); // encerra (exit)
    }
}
