<?php

namespace App\Dao;

use PDO;
use PDOException;
use Util\ConexaoGestaoColetas;

/**
 * Acesso a tb_ordem_coleta_arquivos (banco EXTERNO de gestao de coletas,
 * migration sql/migrations_gestao_coletas/002). Guarda so o caminho relativo
 * do PDF da Ordem de Coleta (nunca o PDF). Um unico registro por
 * (cnpj_cliente, numero_ordem_coleta). Demanda anexo-ordem-coleta-n8n
 * (2026-10-05).
 *
 * A conexao e obtida so no momento do uso (nunca no construtor), como
 * App\Dao\OrdemColetaDao: um banco externo fora do ar nao pode quebrar
 * quem apenas instancia o DAO. $pdo e injetavel para os testes.
 */
class OrdemColetaArquivoDao
{
    private const MAX_TENTATIVAS_TRANSACAO = 5;

    /** Nome da UNIQUE do caminho (migration 002); distingue 1062 de nome x 1062 de chave. */
    private const UK_CAMINHO = 'uk_oc_arquivo_caminho';

    public function __construct(private ?PDO $pdo = null) {}

    protected function pdo(): PDO
    {
        return $this->pdo ?? ConexaoGestaoColetas::obter();
    }

    /** @return array{id:int,caminho_relativo:string,tamanho_bytes:int,sha256:string}|null */
    public function buscarPorChave(string $cnpj, string $numero): ?array
    {
        $stmt = $this->pdo()->prepare('
            SELECT id, caminho_relativo, tamanho_bytes, sha256
            FROM tb_ordem_coleta_arquivos
            WHERE cnpj_cliente = :cnpj AND numero_ordem_coleta = :numero
        ');
        $stmt->execute(['cnpj' => $cnpj, 'numero' => $numero]);
        $linha = $stmt->fetch();
        if (!$linha) {
            return null;
        }

        return [
            'id' => (int) $linha['id'],
            'caminho_relativo' => (string) $linha['caminho_relativo'],
            'tamanho_bytes' => (int) $linha['tamanho_bytes'],
            'sha256' => (string) $linha['sha256'],
        ];
    }

    /** True se alguma linha ja aponta para o caminho relativo. */
    public function caminhoRegistrado(string $caminhoRelativo): bool
    {
        $stmt = $this->pdo()->prepare('
            SELECT 1 FROM tb_ordem_coleta_arquivos WHERE caminho_relativo = :caminho LIMIT 1
        ');
        $stmt->execute(['caminho' => $caminhoRelativo]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Grava (insere ou atualiza) o registro da chave (cnpj, numero) numa
     * transacao: le o caminho antigo com FOR UPDATE, grava o novo e faz COMMIT.
     * Devolve ['anterior' => caminho antigo ou null] SO depois do COMMIT; o
     * chamador apaga o arquivo antigo. Em qualquer excecao faz ROLLBACK e
     * relanca (o chamador apaga o arquivo novo).
     *
     * 1062 em uk_oc_arquivo_caminho (nome ja registrado em outra linha) lanca
     * CaminhoJaRegistradoException (sem repetir: o nome e que precisa mudar).
     * Corrida de dois INSERTs da mesma chave (cnpj, numero): o segundo recebe
     * 1062 na chave, a transacao e refeita (agora com a linha existente, lida com FOR UPDATE),
     * entao o caminho antigo devolvido e sempre o realmente substituido.
     * criado_em e renovado na sobrescrita: marca a criacao do ARQUIVO atual
     * (base da retencao de orfaos).
     *
     * @return array{anterior:?string}
     */
    public function substituir(string $cnpj, string $numero, string $caminhoRelativo, int $tamanho, string $sha256): array
    {
        $pdo = $this->pdo();
        $ultima = null;

        for ($tentativa = 0; $tentativa < self::MAX_TENTATIVAS_TRANSACAO; $tentativa++) {
            try {
                $pdo->beginTransaction();

                $sel = $pdo->prepare('
                    SELECT id, caminho_relativo FROM tb_ordem_coleta_arquivos
                    WHERE cnpj_cliente = :cnpj AND numero_ordem_coleta = :numero
                    FOR UPDATE
                ');
                $sel->execute(['cnpj' => $cnpj, 'numero' => $numero]);
                $atual = $sel->fetch();

                if ($atual) {
                    $upd = $pdo->prepare('
                        UPDATE tb_ordem_coleta_arquivos
                        SET caminho_relativo = :caminho, tamanho_bytes = :tamanho, sha256 = :sha,
                            criado_em = NOW()
                        WHERE id = :id
                    ');
                    $upd->execute([
                        'caminho' => $caminhoRelativo,
                        'tamanho' => $tamanho,
                        'sha' => $sha256,
                        'id' => (int) $atual['id'],
                    ]);
                    $anterior = (string) $atual['caminho_relativo'];
                } else {
                    $ins = $pdo->prepare('
                        INSERT INTO tb_ordem_coleta_arquivos
                            (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256, criado_em)
                        VALUES (:cnpj, :numero, :caminho, :tamanho, :sha, NOW())
                    ');
                    $ins->execute([
                        'cnpj' => $cnpj,
                        'numero' => $numero,
                        'caminho' => $caminhoRelativo,
                        'tamanho' => $tamanho,
                        'sha' => $sha256,
                    ]);
                    $anterior = null;
                }

                $pdo->commit();

                return ['anterior' => $anterior];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $ultima = $e;
                $codigoDriver = $e->errorInfo[1] ?? null;
                if ($codigoDriver === 1062 && str_contains((string) ($e->errorInfo[2] ?? ''), self::UK_CAMINHO)) {
                    throw new CaminhoJaRegistradoException();
                }
                // 1062 na chave (cnpj, numero) e 1213 (deadlock): refaz a transacao
                if ($codigoDriver !== 1062 && $codigoDriver !== 1213) {
                    throw $e;
                }
                usleep(random_int(2000, 20000));
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        throw $ultima;
    }

    /**
     * Linhas elegiveis a exclusao pela retencao (cron diario), no maximo
     * $limite. Regra (decisao do usuario, 2026-10-05):
     *  (a) existe OC correspondente (tb_ordens_coleta + tb_clientes por
     *      cnpj + numero) e TODAS as correspondentes estao INATIVA ha mais de
     *      $dias dias (inativada_em); qualquer correspondente ATIVA, ou
     *      INATIVA sem inativada_em, ou INATIVA recente, mantem o arquivo;
     *  (b) nao existe OC correspondente e criado_em tem mais de $dias dias.
     * Usa EXISTS (nunca JOIN que multiplique linhas) para que uma OC ATIVA
     * nunca perca o arquivo mesmo com dados duplicados no banco externo.
     *
     * @return list<array{id:int,caminho_relativo:string}>
     */
    public function listarExpirados(int $limite, int $dias = 15): array
    {
        // Premissa (M1, verificada no banco externo de dev em 2026-10-05):
        // tb_clientes.cnpj e VARCHAR(14) com UNIQUE (uk_clientes_cnpj) e nenhum
        // valor com caractere nao numerico; uma mascara (18 chars) nem caberia
        // na coluna. O anexo guarda cnpj_cliente so com 14 digitos, entao a
        // igualdade direta c.cnpj = a.cnpj_cliente usa o indice. Rever se a
        // coluna mudar de formato.
        $correspondentes = '
            SELECT 1 FROM tb_ordens_coleta oc
            INNER JOIN tb_clientes c ON c.id = oc.cliente_id
            WHERE c.cnpj = a.cnpj_cliente AND oc.numero_ordem_coleta = a.numero_ordem_coleta';

        $stmt = $this->pdo()->prepare("
            SELECT a.id, a.caminho_relativo
            FROM tb_ordem_coleta_arquivos a
            WHERE (
                EXISTS ({$correspondentes})
                AND NOT EXISTS ({$correspondentes}
                    AND (oc.status <> 'INATIVA' OR oc.inativada_em IS NULL
                         OR oc.inativada_em >= DATE_SUB(NOW(), INTERVAL :dias_a DAY)))
            ) OR (
                NOT EXISTS ({$correspondentes})
                AND a.criado_em < DATE_SUB(NOW(), INTERVAL :dias_b DAY)
            )
            ORDER BY a.id
            LIMIT :limite
        ");
        $stmt->bindValue('dias_a', $dias, PDO::PARAM_INT);
        $stmt->bindValue('dias_b', $dias, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $saida = [];
        foreach ($stmt->fetchAll() as $linha) {
            $saida[] = ['id' => (int) $linha['id'], 'caminho_relativo' => (string) $linha['caminho_relativo']];
        }

        return $saida;
    }

    /**
     * Apaga a linha SOMENTE se ainda aponta para o mesmo caminho (uma
     * sobrescrita concorrente troca o caminho e a linha nova e preservada).
     */
    public function excluirPorIdECaminho(int $id, string $caminhoRelativo): bool
    {
        $stmt = $this->pdo()->prepare('
            DELETE FROM tb_ordem_coleta_arquivos WHERE id = :id AND caminho_relativo = :caminho
        ');
        $stmt->execute(['id' => $id, 'caminho' => $caminhoRelativo]);

        return $stmt->rowCount() > 0;
    }
}
