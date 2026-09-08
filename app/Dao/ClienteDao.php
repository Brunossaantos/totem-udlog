<?php

namespace App\Dao;

use PDO;

class ClienteDao
{
    public function __construct(private PDO $pdo) {}

    public function buscarPorCnpj(string $cnpj): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_cliente WHERE cnpj = :cnpj AND ativo = 1');
        $stmt->execute(['cnpj' => $cnpj]);
        return $stmt->fetch() ?: null;
    }

    // autocomplete: busca a partir de 3 letras, por nome ou CNPJ
    public function buscarPorTermo(string $termo): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM tb_cliente
            WHERE ativo = 1 AND (nome LIKE :termo OR cnpj LIKE :termo)
            ORDER BY nome LIMIT 8
        ');
        $stmt->execute(['termo' => "%{$termo}%"]);
        return $stmt->fetchAll();
    }

    /**
     * Listagem local para o fuzzy match de razao social do fluxo de
     * identificacao automatica de cliente via OCR
     * (App\Rn\NotaFiscalRn::identificarCliente, ver
     * sql/migrations/003_tb_cliente_razao_normalizada.sql). Antes desta
     * migracao, essa listagem vinha da API externa de clientes
     * (App\Rn\ClienteApiClient::listarTodos) e era normalizada em tempo real
     * a cada chamada por Util\RazaoSocialMatcher::normalizar(). Agora a
     * coluna "razao_social" do retorno ja vem com
     * tb_cliente.razao_social_normalizada (pre-computada no banco pelo
     * mesmo algoritmo) — Util\RazaoSocialMatcher::melhorCandidato() ainda
     * chama normalizar() sobre ela internamente (classe intocada por
     * decisao do orquestrador), mas normalizar() e idempotente sobre texto
     * ja normalizado (uppercase/ASCII/sem pontuacao ja aplicados), entao
     * essa segunda passada e essencialmente um no-op — evita reprocessar a
     * transliteracao/regex sobre a razao social original (com acento e
     * pontuacao) a cada uma das ate 5 chamadas de um mesmo atendimento.
     * "nome" (razao social original, para exibicao) tambem vem no array —
     * o chamador remonta a resposta final com o nome de exibicao correto
     * apos a identificacao (ver App\Rn\NotaFiscalRn::mapClienteLocal).
     *
     * @return array<int, array{id_cliente:int, nome:string, razao_social:string, cnpj:string}>
     */
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
