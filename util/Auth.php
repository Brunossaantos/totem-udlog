<?php

namespace Util;

use PDO;

class Auth
{
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
            LogSistema::registrar('totem_nao_autorizado');
            Resposta::erro('Totem nao autorizado', 401);
        }

        return $totem;
    }
}
