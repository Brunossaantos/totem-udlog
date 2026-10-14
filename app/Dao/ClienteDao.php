<?php

namespace App\Dao;

use PDO;
use Util\RazaoSocialMatcher;

class ClienteDao
{
    private const LIMITE_BUSCA_UNICA = 20000;

    public function __construct(private PDO $pdo) {}

    public function buscarPorCnpj(string $cnpj): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_cliente WHERE cnpj = :cnpj AND ativo = 1');
        $stmt->execute(['cnpj' => $cnpj]);
        return $stmt->fetch() ?: null;
    }

    public function buscarUnicoPorTermo(string $termo): ?array
    {
        $termoNormalizado = RazaoSocialMatcher::normalizarTextoBusca($termo);
        if (mb_strlen($termoNormalizado, 'UTF-8') < 3) {
            return null;
        }

        $digitos = preg_replace('/[.\-\/\s]/', '', $termoNormalizado);
        $porCnpj = is_string($digitos) && preg_match('/\A\d{3,}\z/', $digitos) === 1;

        $stmt = $this->pdo->prepare('
            SELECT id_cliente, nome, cnpj
            FROM tb_cliente
            WHERE ativo = 1
            ORDER BY id_cliente
            LIMIT ' . self::LIMITE_BUSCA_UNICA
        );
        $stmt->execute();

        $encontrados = [];
        foreach ($stmt->fetchAll() as $linha) {
            if ($porCnpj) {
                $cnpjLinha = (string) preg_replace('/\D/', '', (string) $linha['cnpj']);
                $casou = str_starts_with($cnpjLinha, $digitos);
            } else {
                $nomeLinha = RazaoSocialMatcher::normalizarTextoBusca((string) $linha['nome']);
                $casou = mb_strpos($nomeLinha, $termoNormalizado, 0, 'UTF-8') !== false;
            }
            if ($casou) {
                $encontrados[(int) $linha['id_cliente']] = $linha;
                if (count($encontrados) > 1) {
                    return null;
                }
            }
        }

        return count($encontrados) === 1 ? reset($encontrados) : null;
    }

    public function listarParaFuzzy(): array
    {
        $stmt = $this->pdo->query('
            SELECT id_cliente, nome, razao_social_normalizada AS razao_social, cnpj
            FROM tb_cliente
            WHERE ativo = 1 AND razao_social_normalizada IS NOT NULL AND razao_social_normalizada <> \'\'
        ');
        return $stmt->fetchAll();
    }
}
