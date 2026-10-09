<?php

namespace Util;

/**
 * Cabecalhos e respostas de erro da Gestao Totem (demanda gestao-totem, F1).
 * Os metodos que "encerram" a requisicao (redirecionar, negar, json) chamam
 * exit. PHP 8.0 nao tem o tipo `never`, entao o retorno e void.
 */
final class GestaoHttp
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'";

    /**
     * Acao (botao) da pagina de erro de `negar()`:
     *  - ACAO_LOGIN: "Ir para o login" (sem sessao);
     *  - ACAO_INICIO: "Voltar ao início" (com sessao; /gestao/ leva a pagina inicial do perfil);
     *  - ACAO_RECARREGAR: "Recarregar a página" (token invalido, pagina expirada, erro
     *    temporario; e um link GET para o mesmo caminho, sem query);
     *  - ACAO_CONTA: "Ir para Minha conta" (troca de senha obrigatoria);
     *  - ACAO_NENHUMA: sem botao (HTTPS/Host invalidos: nenhum link resolveria).
     */
    public const ACAO_LOGIN = 'login';

    public const ACAO_INICIO = 'inicio';

    public const ACAO_RECARREGAR = 'recarregar';

    public const ACAO_CONTA = 'conta';

    public const ACAO_NENHUMA = 'nenhuma';

    /** Texto padrao de erro interno (excecao nao tratada, falha inesperada). */
    public const MSG_ERRO_INTERNO = 'Não foi possível concluir. Nada foi alterado. Tente novamente em instantes.';

    /** Cabecalhos de seguranca em TODA resposta da gestao (200, 302, 4xx, 5xx). */
    public static function cabecalhosSeguranca(): void
    {
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Content-Security-Policy: ' . self::CSP);
    }

    /** Redireciona (302) para um caminho do proprio site (sempre iniciado por /gestao/). */
    public static function redirecionar(string $caminho, int $status = 302): void
    {
        if (!str_starts_with($caminho, '/gestao/') || str_contains($caminho, "\r") || str_contains($caminho, "\n")) {
            $caminho = '/gestao/login.php';
        }
        http_response_code($status);
        header('Location: ' . $caminho);
        exit;
    }

    /**
     * Tratador global da gestao: excecao nao capturada vira resposta generica 500
     * com os cabecalhos de seguranca e UM log com SO a classe da excecao (nunca
     * mensagem, arquivo, linha nem trace: o stack trace pode carregar senha).
     */
    public static function registrarTratadorDeExcecao(): void
    {
        set_exception_handler(static function (\Throwable $e): void {
            error_log('gestao: excecao_nao_tratada ' . get_class($e));
            LogSistema::registrar('gestao_erro_interno', ['excecao' => $e, 'http' => 500, 'motivo' => 'falha_inesperada']);
            self::erroInterno();
        });
    }

    /** Resposta 500 generica (usada pelo tratador global e por falhas inesperadas). */
    public static function erroInterno(): void
    {
        if (headers_sent()) {
            echo '<p>' . htmlspecialchars(self::MSG_ERRO_INTERNO, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            exit;
        }
        self::cabecalhosSeguranca();
        self::negar(500, 'Erro interno', self::MSG_ERRO_INTERNO, [], self::ACAO_RECARREGAR);
    }

    /** Caminho atual (sem query) so se for da propria gestao; senao a raiz da gestao. */
    private static function caminhoAtual(): string
    {
        $caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($caminho) || preg_match('#\A/gestao/[A-Za-z0-9._/-]*\z#D', $caminho) !== 1 || str_contains($caminho, '..')) {
            return '/gestao/';
        }

        return $caminho;
    }

    /**
     * Pagina de erro minima (sem script nem estilo inline, compativel com a CSP).
     * `$acao` escolhe o botao (ver ACAO_*).
     */
    public static function negar(int $status, string $titulo, string $mensagem, array $cabecalhosExtras = [], string $acao = self::ACAO_LOGIN): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        foreach ($cabecalhosExtras as $linha) {
            header($linha);
        }
        $t = htmlspecialchars($titulo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $m = htmlspecialchars($mensagem, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $css = '/gestao/assets/gestao.css?v=' . (int) @filemtime(__DIR__ . '/../public/gestao/assets/gestao.css');
        $logo = '/gestao/assets/udlog.png?v=' . (int) @filemtime(__DIR__ . '/../public/gestao/assets/udlog.png');
        $botoes = [
            self::ACAO_LOGIN => ['/gestao/login.php', 'Ir para o login'],
            self::ACAO_INICIO => ['/gestao/', 'Voltar ao início'],
            self::ACAO_RECARREGAR => [self::caminhoAtual(), 'Recarregar a página'],
            self::ACAO_CONTA => ['/gestao/conta.php', 'Ir para Minha conta'],
        ];
        $botao = '';
        if (isset($botoes[$acao])) {
            $botao = '<a class="gestao-botao gestao-botao--primario" id="gestao-erro-acao" data-acao="' . $acao . '" href="'
                . htmlspecialchars($botoes[$acao][0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $botoes[$acao][1] . '</a>';
        }
        $icone = '/gestao/assets/favicon.svg?v=' . (int) @filemtime(__DIR__ . '/../public/gestao/assets/favicon.svg');
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . $t . ' - Gestão Totem</title>'
            . '<link rel="icon" type="image/svg+xml" href="' . $icone . '">'
            . '<link rel="stylesheet" href="' . $css . '"></head><body class="gestao gestao--publica gestao--erro">'
            . '<main class="gestao-erro" id="conteudo">'
            . '<img class="gestao-erro__logo" src="' . $logo . '" width="160" height="51" alt="UDLOG">'
            . '<h1 class="gestao-erro__titulo">' . $t . '</h1><p class="gestao-erro__texto">' . $m . '</p>'
            . $botao
            . '</main></body></html>';
        exit;
    }

    /** @param array<string,mixed> $corpo */
    public static function json(int $status, array $corpo): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
