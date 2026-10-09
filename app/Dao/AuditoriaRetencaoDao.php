<?php

namespace App\Dao;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

class AuditoriaRetencaoDao
{
    public const RETENCAO_DIAS = 90;

    public const LOTE_MAXIMO = 500;

    public function __construct(private PDO $pdo)
    {
    }

    public static function corteRetencao(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('-03:00')))->modify('-' . self::RETENCAO_DIAS . ' days');
    }

    public function contarAntigas(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tb_gestao_auditoria WHERE criado_em < :corte');
        $stmt->bindValue('corte', self::corteRetencao()->format('Y-m-d H:i:s'));
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function apagarLote(int $limite): int
    {
        return $this->apagarLoteAntesDe(self::corteRetencao(), $limite);
    }

    public function apagarLoteAntesDe(DateTimeInterface $corte, int $limite): int
    {
        if ($limite < 1 || $limite > self::LOTE_MAXIMO) {
            throw new InvalidArgumentException('tamanho de lote invalido');
        }
        if ($corte->getTimestamp() > self::corteRetencao()->getTimestamp()) {
            throw new InvalidArgumentException('corte mais novo que o piso de retencao');
        }
        $corteTexto = (new DateTimeImmutable('@' . $corte->getTimestamp()))
            ->setTimezone(new DateTimeZone('-03:00'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('DELETE FROM tb_gestao_auditoria WHERE criado_em < :corte ORDER BY id_auditoria LIMIT :limite');
        $stmt->bindValue('corte', $corteTexto);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
