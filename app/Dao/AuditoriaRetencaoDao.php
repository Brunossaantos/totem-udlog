<?php

namespace App\Dao;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Retencao (poda) da auditoria da Gestao Totem: 90 dias (decisao do usuario).
 *
 * Este e o UNICO lugar do sistema autorizado a apagar de tb_gestao_auditoria. O
 * AuditoriaDao continua append-only (INSERT e o UPDATE de fechamento, nunca DELETE)
 * e o teste da gestao varre o codigo para garantir que nenhum outro arquivo apaga.
 * Tambem nao e acessivel pela web: so cron/limpar-logs-gestao.php (CLI) usa esta
 * classe; nada em public/, controllers, views ou util/ a referencia.
 *
 * O corte e FIXO: agora menos 90 dias, calculado no PHP, no fuso -03:00 da sessao
 * do projeto. Nunca vem de .env nem de parametro de requisicao. O PISO de 90 dias
 * recusa qualquer corte mais novo (so e possivel apagar o que e MAIS ANTIGO que 90
 * dias). Apaga em lotes (LIMIT) para nao segurar lock por muito tempo.
 */
class AuditoriaRetencaoDao
{
    public const RETENCAO_DIAS = 90;

    public const LOTE_MAXIMO = 500;

    public function __construct(private PDO $pdo)
    {
    }

    /** Corte da retencao: agora menos 90 dias, no fuso da sessao do projeto. */
    public static function corteRetencao(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('-03:00')))->modify('-' . self::RETENCAO_DIAS . ' days');
    }

    /** Linhas que o proximo apagar removeria (usado pelo --dry-run, nao altera nada). */
    public function contarAntigas(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tb_gestao_auditoria WHERE criado_em < :corte');
        $stmt->bindValue('corte', self::corteRetencao()->format('Y-m-d H:i:s'));
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** Apaga um lote de linhas com mais de 90 dias. */
    public function apagarLote(int $limite): int
    {
        return $this->apagarLoteAntesDe(self::corteRetencao(), $limite);
    }

    /**
     * PISO de retencao: lanca InvalidArgumentException para qualquer corte mais
     * novo que agora menos 90 dias e para lote fora de 1..500.
     */
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
