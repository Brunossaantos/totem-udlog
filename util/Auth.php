<?php

namespace Util;

use PDO;

class Auth
{
    /**
     * Valida o token enviado pelo totem no header Authorization: Bearer <token>.
     * Encerra a requisicao com 401 se invalido. Retorna a linha do totem autenticado.
     */
    public static function validarTotem(PDO $pdo): array
    {
        $headers = getallheaders();
        $cabecalho = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $token = trim(str_replace('Bearer', '', $cabecalho));

        if (empty($token)) {
            Resposta::erro('Token nao informado', 401);
        }

        $stmt = $pdo->prepare('SELECT * FROM tb_totem WHERE token_api = :token AND ativo = 1');
        $stmt->execute(['token' => $token]);
        $totem = $stmt->fetch();

        if (!$totem) {
            Resposta::erro('Totem nao autorizado', 401);
        }

        return $totem;
    }
}
