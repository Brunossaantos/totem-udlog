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
}
