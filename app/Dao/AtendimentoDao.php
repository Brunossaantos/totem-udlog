<?php

namespace App\Dao;

use PDO;

class AtendimentoDao
{
    public function __construct(private PDO $pdo) {}

    public function criar(int $idTotem, string $tipo, string $placa): int
    {
        $codigo = $this->gerarUuid();

        $stmt = $this->pdo->prepare('
            INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, etapa_atual, status, placa)
            VALUES (:codigo, :id_totem, :tipo, :etapa, :status, :placa)
        ');
        $stmt->execute([
            'codigo'   => $codigo,
            'id_totem' => $idTotem,
            'tipo'     => $tipo,
            'etapa'    => 'placa',
            'status'   => 'em_andamento',
            'placa'    => strtoupper($placa),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_atendimento WHERE id_atendimento = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function atualizarEtapa(int $id, string $etapa): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET etapa_atual = :etapa WHERE id_atendimento = :id');
        $stmt->execute(['etapa' => $etapa, 'id' => $id]);
    }

    public function preencherDadosOrdem(int $id, array $ordem): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET ordem_coleta = :oc, cliente_nome = :cliente, cliente_cnpj = :cnpj, etapa_atual = "dados_encontrados"
            WHERE id_atendimento = :id
        ');
        $stmt->execute([
            'oc'      => $ordem['numero'],
            'cliente' => $ordem['cliente_nome'],
            'cnpj'    => $ordem['cliente_cnpj'],
            'id'      => $id,
        ]);
    }

    public function definirPasta(int $id, string $pastaRelativa): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET pasta_documentos = :pasta WHERE id_atendimento = :id');
        $stmt->execute(['pasta' => $pastaRelativa, 'id' => $id]);
    }

    public function salvarDadosMotorista(int $id, array $dados): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET motorista_nome = :nome, motorista_cpf = :cpf, cnh_validade = :cnh_validade, crlv_ano = :crlv_ano
            WHERE id_atendimento = :id
        ');
        $stmt->execute([
            'nome'         => $dados['motorista_nome'] ?? null,
            'cpf'          => $dados['motorista_cpf'] ?? null,
            'cnh_validade' => $dados['cnh_validade'] ?? null,
            'crlv_ano'     => $dados['crlv_ano'] ?? null,
            'id'           => $id,
        ]);
    }

    public function salvarCliente(int $id, string $nome, ?string $cnpj): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET cliente_nome = :nome, cliente_cnpj = :cnpj WHERE id_atendimento = :id');
        $stmt->execute(['nome' => $nome, 'cnpj' => $cnpj, 'id' => $id]);
    }

    public function salvarAjudante(int $id, ?string $nome, ?string $cpf): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET possui_ajudante = :possui, ajudante_nome = :nome, ajudante_cpf = :cpf
            WHERE id_atendimento = :id
        ');
        $stmt->execute([
            'possui' => $nome ? 1 : 0,
            'nome'   => $nome,
            'cpf'    => $cpf,
            'id'     => $id,
        ]);
    }

    public function finalizar(int $id, string $senha, ?string $protocolo): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_atendimento
            SET status = "concluido", etapa_atual = "impressao",
                talent_enviado_em = NOW(), talent_senha = :senha, talent_protocolo = :protocolo
            WHERE id_atendimento = :id
        ');
        $stmt->execute(['senha' => $senha, 'protocolo' => $protocolo, 'id' => $id]);
    }

    public function cancelar(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET status = "cancelado" WHERE id_atendimento = :id');
        $stmt->execute(['id' => $id]);
    }

    public function bloquear(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_atendimento SET status = "bloqueado", etapa_atual = "balcao_portaria" WHERE id_atendimento = :id');
        $stmt->execute(['id' => $id]);
    }

    private function gerarUuid(): string
    {
        $dados = random_bytes(16);
        $dados[6] = chr(ord($dados[6]) & 0x0f | 0x40);
        $dados[8] = chr(ord($dados[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($dados), 4));
    }
}
