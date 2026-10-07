<?php

namespace App\Dao;

use PDO;

/**
 * Sessoes da Gestao Totem em TABELA (tb_gestao_sessao, migration 020).
 * `id_sessao` e o sha256 do token do cookie: o token em si nunca e gravado.
 * Todas as comparacoes de tempo usam a hora do BANCO (NOW()), sem depender do
 * fuso do PHP.
 */
class SessaoGestaoDao
{
    public function __construct(private PDO $pdo)
    {
    }

    public function criar(string $idSessao, int $idUsuario, #[\SensitiveParameter] string $csrfToken, string $uaHash, int $absolutoMin): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_gestao_sessao (id_sessao, id_usuario, csrf_token, ultimo_acesso_em, expira_em, ua_hash)
             VALUES (:id, :usuario, :csrf, NOW(), DATE_ADD(NOW(), INTERVAL :minutos MINUTE), :ua)'
        );
        $stmt->bindValue('id', $idSessao);
        $stmt->bindValue('usuario', $idUsuario, PDO::PARAM_INT);
        $stmt->bindValue('csrf', $csrfToken);
        $stmt->bindValue('minutos', $absolutoMin, PDO::PARAM_INT);
        $stmt->bindValue('ua', $uaHash);
        $stmt->execute();
    }

    /**
     * Busca a sessao + usuario (ativo e perfil lidos do BANCO a cada chamada).
     * Devolve null se nao existe, expirou (teto absoluto) ou passou da
     * inatividade. A decisao sobre `ativo` e `ua_hash` fica com o chamador, que
     * revoga a sessao quando preciso.
     *
     * @return array<string,mixed>|null
     */
    public function buscarValida(string $idSessao, int $idleMin): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id_sessao, s.id_usuario, s.csrf_token, s.ua_hash,
                    u.login, u.nome, u.perfil, u.ativo, u.deve_trocar_senha
               FROM tb_gestao_sessao s
               JOIN tb_gestao_usuario u ON u.id_usuario = s.id_usuario
              WHERE s.id_sessao = :id
                AND s.expira_em > NOW()
                AND TIMESTAMPDIFF(SECOND, s.ultimo_acesso_em, NOW()) < :idle_s'
        );
        $stmt->bindValue('id', $idSessao);
        $stmt->bindValue('idle_s', $idleMin * 60, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Atualiza ultimo_acesso_em no maximo 1x por minuto (condicao no proprio UPDATE). */
    public function tocar(string $idSessao): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tb_gestao_sessao SET ultimo_acesso_em = NOW()
              WHERE id_sessao = :id AND TIMESTAMPDIFF(SECOND, ultimo_acesso_em, NOW()) >= 60'
        );
        $stmt->execute(['id' => $idSessao]);
    }

    public function excluir(string $idSessao): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tb_gestao_sessao WHERE id_sessao = :id');
        $stmt->execute(['id' => $idSessao]);
    }

    /** Revoga as sessoes do usuario (todas, ou todas menos uma). @return int quantas */
    public function revogarDoUsuario(int $idUsuario, ?string $exceto = null): int
    {
        if ($exceto === null) {
            $stmt = $this->pdo->prepare('DELETE FROM tb_gestao_sessao WHERE id_usuario = :usuario');
            $stmt->execute(['usuario' => $idUsuario]);
        } else {
            $stmt = $this->pdo->prepare('DELETE FROM tb_gestao_sessao WHERE id_usuario = :usuario AND id_sessao <> :exceto');
            $stmt->execute(['usuario' => $idUsuario, 'exceto' => $exceto]);
        }

        return $stmt->rowCount();
    }

    /** Limpeza em lote pequeno (chamada oportunista no login). */
    public function apagarExpiradas(int $limite = 200): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM tb_gestao_sessao WHERE expira_em <= NOW() LIMIT ' . max(1, $limite));
        $stmt->execute();

        return $stmt->rowCount();
    }
}
