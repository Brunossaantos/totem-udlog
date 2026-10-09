<?php

namespace App\Dao;

use InvalidArgumentException;
use PDO;

class OrdemColetaPendenteBaixaDao
{
    public function __construct(private PDO $pdo) {}

    public function registrar(int $idAtendimento, string $numeroOrdemColeta): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_ordem_coleta_pendente_baixa (id_atendimento, numero_ordem_coleta)
            VALUES (:id_atendimento, :numero)
            ON DUPLICATE KEY UPDATE criado_em = criado_em
        ');
        $stmt->execute([
            'id_atendimento' => $idAtendimento,
            'numero' => $numeroOrdemColeta,
        ]);
    }


    public const MOSTRAR = ['pendentes', 'resolvidas', 'todas'];
    public const LIMITE_MAX = 100;

    private const SELECT_BAIXA = '
        SELECT b.id, b.id_atendimento, b.numero_ordem_coleta, b.criado_em, b.resolvido_em,
               a.cliente_cnpj, a.criado_em AS atendimento_criado_em
        FROM tb_ordem_coleta_pendente_baixa b
        INNER JOIN tb_atendimento a ON a.id_atendimento = b.id_atendimento';

    public function listar(string $mostrar, ?string $de, ?string $ate, int $limite, int $offset): array
    {
        [$where, $binds] = $this->filtrar($mostrar, $de, $ate);
        $limite = max(1, min(self::LIMITE_MAX, $limite));
        $offset = max(0, min(100000, $offset));

        $stmt = $this->pdo->prepare(
            self::SELECT_BAIXA . $where . ' ORDER BY b.criado_em DESC, b.id DESC LIMIT :limite OFFSET :deslocamento'
        );
        foreach ($binds as $nome => $valor) {
            $stmt->bindValue($nome, $valor, PDO::PARAM_STR);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue('deslocamento', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([self::class, 'tipar'], $stmt->fetchAll());
    }

    public function contar(string $mostrar, ?string $de, ?string $ate): int
    {
        [$where, $binds] = $this->filtrar($mostrar, $de, $ate);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tb_ordem_coleta_pendente_baixa b INNER JOIN tb_atendimento a ON a.id_atendimento = b.id_atendimento' . $where
        );
        foreach ($binds as $nome => $valor) {
            $stmt->bindValue($nome, $valor, PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function buscarPorId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(self::SELECT_BAIXA . ' WHERE b.id = :id');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $linha = $stmt->fetch();

        return $linha ? self::tipar($linha) : null;
    }

    public function marcarResolvida(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE tb_ordem_coleta_pendente_baixa SET resolvido_em = NOW() WHERE id = :id AND resolvido_em IS NULL'
        );
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    private function filtrar(string $mostrar, ?string $de, ?string $ate): array
    {
        if (!in_array($mostrar, self::MOSTRAR, true)) {
            throw new InvalidArgumentException('filtro "mostrar" invalido');
        }
        $cond = [];
        $binds = [];
        if ($mostrar === 'pendentes') {
            $cond[] = 'b.resolvido_em IS NULL';
        } elseif ($mostrar === 'resolvidas') {
            $cond[] = 'b.resolvido_em IS NOT NULL';
        }
        if ($de !== null && $de !== '') {
            $cond[] = 'b.criado_em >= :de';
            $binds['de'] = self::dataValida($de) . ' 00:00:00';
        }
        if ($ate !== null && $ate !== '') {
            $cond[] = 'b.criado_em < :ate';
            $binds['ate'] = (new \DateTimeImmutable(self::dataValida($ate)))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }

        return [$cond === [] ? '' : ' WHERE ' . implode(' AND ', $cond), $binds];
    }

    private static function dataValida(string $v): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $v) !== 1 || $d === false || $d->format('Y-m-d') !== $v) {
            throw new InvalidArgumentException('data invalida');
        }

        return $v;
    }

    private static function tipar(array $l): array
    {
        $l['id'] = (int) $l['id'];
        $l['id_atendimento'] = (int) $l['id_atendimento'];

        return $l;
    }
}
