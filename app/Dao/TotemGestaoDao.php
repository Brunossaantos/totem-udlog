<?php

namespace App\Dao;

use PDO;

class TotemGestaoDao
{
    private const LOCK_TOTENS = 'totem_gestao_totens_';

    private const COLUNAS = 't.id_totem, t.codigo, t.nome, t.id_empresa, t.ativo, t.criado_em, t.criado_por,
        t.atualizado_em, t.url_regerada_em, t.url_versao';

    public const JANELA_ATENDIMENTO_MIN = 30;

    public function __construct(private PDO $pdo)
    {
    }

    public function listar(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', e.nome AS empresa_nome, u.login AS criado_por_login, u.nome AS criado_por_nome,
                    COALESCE(a.qtd, 0) AS atendimentos_recentes
             FROM tb_totem t
             LEFT JOIN tb_empresa e ON e.id_empresa = t.id_empresa
             LEFT JOIN tb_gestao_usuario u ON u.id_usuario = t.criado_por
             LEFT JOIN (
                 SELECT id_totem, COUNT(*) AS qtd FROM tb_atendimento
                 WHERE status = \'em_andamento\' AND atualizado_em >= (NOW() - INTERVAL :janela MINUTE)
                 GROUP BY id_totem
             ) a ON a.id_totem = t.id_totem
             ORDER BY t.ativo DESC, e.nome ASC, t.nome ASC, t.id_totem ASC'
        );
        $stmt->bindValue('janela', self::JANELA_ATENDIMENTO_MIN, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarPorId(int $idTotem, bool $paraAtualizar = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', e.nome AS empresa_nome
             FROM tb_totem t LEFT JOIN tb_empresa e ON e.id_empresa = t.id_empresa
             WHERE t.id_totem = :id' . ($paraAtualizar ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $idTotem]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function empresasAtivas(): array
    {
        return $this->pdo->query('SELECT id_empresa, nome FROM tb_empresa WHERE ativo = 1 ORDER BY nome ASC, id_empresa ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarEmpresa(int $idEmpresa, bool $soAtiva): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_empresa, nome, ativo FROM tb_empresa WHERE id_empresa = :id' . ($soAtiva ? ' AND ativo = 1' : ''));
        $stmt->execute(['id' => $idEmpresa]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function existeNomeNaEmpresa(int $idEmpresa, string $nome): bool
    {
        $stmt = $this->pdo->prepare('SELECT id_totem FROM tb_totem WHERE id_empresa = :empresa AND nome = :nome LIMIT 1 FOR UPDATE');
        $stmt->execute(['empresa' => $idEmpresa, 'nome' => $nome]);

        return $stmt->fetchColumn() !== false;
    }

    public function inserir(string $codigo, string $nome, int $idEmpresa, #[\SensitiveParameter] string $tokenApi, int $criadoPor): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo, criado_por)
             VALUES (:codigo, :nome, :empresa, :token, 1, :criado_por)'
        );
        $stmt->bindValue('codigo', $codigo);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('empresa', $idEmpresa, PDO::PARAM_INT);
        $stmt->bindValue('token', $tokenApi);
        $stmt->bindValue('criado_por', $criadoPor, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function atualizarAtivo(int $idTotem, bool $ativo): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_totem SET ativo = :ativo WHERE id_totem = :id');
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('id', $idTotem, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function atualizarCodigo(int $idTotem, string $codigo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tb_totem SET codigo = :codigo, url_regerada_em = NOW(), url_versao = url_versao + 1 WHERE id_totem = :id'
        );
        $stmt->bindValue('codigo', $codigo);
        $stmt->bindValue('id', $idTotem, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function contarAtendimentosRecentes(int $idTotem): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tb_atendimento
             WHERE id_totem = :id AND status = \'em_andamento\' AND atualizado_em >= (NOW() - INTERVAL :janela MINUTE)'
        );
        $stmt->bindValue('id', $idTotem, PDO::PARAM_INT);
        $stmt->bindValue('janela', self::JANELA_ATENDIMENTO_MIN, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function obterLock(int $segundos): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(CONCAT(:nome, MD5(DATABASE())), :segundos)');
        $stmt->bindValue('nome', self::LOCK_TOTENS);
        $stmt->bindValue('segundos', $segundos, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    public function liberarLock(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(CONCAT(:nome, MD5(DATABASE())))');
            $stmt->execute(['nome' => self::LOCK_TOTENS]);
            $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('TotemGestaoDao: release_lock_falhou ' . get_class($e));
        }
    }
}
