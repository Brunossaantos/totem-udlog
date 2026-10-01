<?php

namespace Util;

class Resposta
{
    public static function sucesso(array $dados = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => true, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function erro(string $mensagem, int $codigoHttp = 400): void
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'erro' => $mensagem], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Erro com codigo estavel e dados estruturados (demanda
     * hardening-revisao-notas-e-cliente, 2026-09-30) -- ADITIVO: `erro()`
     * continua identico. Corpo: {sucesso:false, erro:<mensagem>,
     * codigo:<codigo>, dados:<dados>}. Quem chama e responsavel por so
     * passar dados sanitizados (inteiros, flags), nunca dado pessoal.
     */
    public static function erroComDados(string $mensagem, string $codigo, array $dados, int $codigoHttp = 422): void
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['sucesso' => false, 'erro' => $mensagem, 'codigo' => $codigo, 'dados' => $dados],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}
