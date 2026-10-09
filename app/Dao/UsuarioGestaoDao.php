<?php

namespace App\Dao;

use PDO;

class UsuarioGestaoDao
{
    private const LOCK_ADMINS = 'totem_gestao_admins_';

    private const COLUNAS = 'id_usuario, login, nome, perfil, senha_hash, ativo, deve_trocar_senha, tentativas_falhas,
        bloqueado_ate, ultimo_login_em, senha_alterada_em, senha_versao, criado_em, atualizado_em, criado_por';

    public function __construct(private PDO $pdo)
    {
    }

    public function buscarPorLogin(string $login): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM tb_gestao_usuario WHERE login = :login');
        $stmt->execute(['login' => $login]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function buscarPorId(int $idUsuario, bool $paraAtualizar = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM tb_gestao_usuario WHERE id_usuario = :id' . ($paraAtualizar ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $idUsuario]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listar(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id_usuario, login, nome, perfil, ativo, deve_trocar_senha, senha_versao,
                    IF(bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW(), bloqueado_ate, NULL) AS bloqueado_ate,
                    ultimo_login_em, criado_em
             FROM tb_gestao_usuario ORDER BY nome ASC, id_usuario ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function inserir(string $login, string $nome, string $perfil, #[\SensitiveParameter] string $senhaHash, bool $deveTrocarSenha, ?int $criadoPor): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_gestao_usuario (login, nome, perfil, senha_hash, ativo, deve_trocar_senha, senha_alterada_em, criado_por)
             VALUES (:login, :nome, :perfil, :senha_hash, 1, :deve_trocar, NOW(), :criado_por)'
        );
        $stmt->bindValue('login', $login);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('perfil', $perfil);
        $stmt->bindValue('senha_hash', $senhaHash);
        $stmt->bindValue('deve_trocar', $deveTrocarSenha ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('criado_por', $criadoPor, $criadoPor === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function atualizarNomePerfil(int $idUsuario, string $nome, string $perfil): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_gestao_usuario SET nome = :nome, perfil = :perfil WHERE id_usuario = :id');
        $stmt->execute(['nome' => $nome, 'perfil' => $perfil, 'id' => $idUsuario]);
    }

    public function atualizarAtivo(int $idUsuario, bool $ativo): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_gestao_usuario SET ativo = :ativo WHERE id_usuario = :id');
        $stmt->execute(['ativo' => $ativo ? 1 : 0, 'id' => $idUsuario]);
    }

    public function atualizarSenha(int $idUsuario, #[\SensitiveParameter] string $senhaHash, bool $deveTrocarSenha): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tb_gestao_usuario
                SET senha_hash = :hash, deve_trocar_senha = :deve_trocar, senha_alterada_em = NOW(),
                    senha_versao = senha_versao + 1, tentativas_falhas = 0, bloqueado_ate = NULL
              WHERE id_usuario = :id'
        );
        $stmt->bindValue('hash', $senhaHash);
        $stmt->bindValue('deve_trocar', $deveTrocarSenha ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('id', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function atualizarHashApenas(int $idUsuario, #[\SensitiveParameter] string $senhaHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :hash WHERE id_usuario = :id');
        $stmt->execute(['hash' => $senhaHash, 'id' => $idUsuario]);
    }

    public function desbloquear(int $idUsuario): void
    {
        $stmt = $this->pdo->prepare('UPDATE tb_gestao_usuario SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id_usuario = :id');
        $stmt->execute(['id' => $idUsuario]);
    }

    public function registrarLoginOk(int $idUsuario): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tb_gestao_usuario SET tentativas_falhas = 0, bloqueado_ate = NULL, ultimo_login_em = NOW() WHERE id_usuario = :id'
        );
        $stmt->execute(['id' => $idUsuario]);
    }

    public function registrarFalhaSenha(int $idUsuario, int $limite, int $bloqueioMinutos): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tb_gestao_usuario
                SET bloqueado_ate = IF(tentativas_falhas + 1 >= :limite_a, DATE_ADD(NOW(), INTERVAL :minutos MINUTE), bloqueado_ate),
                    tentativas_falhas = IF(tentativas_falhas + 1 >= :limite_b, 0, tentativas_falhas + 1)
              WHERE id_usuario = :id AND (bloqueado_ate IS NULL OR bloqueado_ate <= NOW())'
        );
        $stmt->bindValue('limite_a', $limite, PDO::PARAM_INT);
        $stmt->bindValue('limite_b', $limite, PDO::PARAM_INT);
        $stmt->bindValue('minutos', $bloqueioMinutos, PDO::PARAM_INT);
        $stmt->bindValue('id', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();

        return $this->estaBloqueada($idUsuario);
    }

    public function estaBloqueada(int $idUsuario): bool
    {
        $stmt = $this->pdo->prepare('SELECT bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW() FROM tb_gestao_usuario WHERE id_usuario = :id');
        $stmt->execute(['id' => $idUsuario]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function travarAdminsAtivos(): array
    {
        $stmt = $this->pdo->query("SELECT id_usuario FROM tb_gestao_usuario WHERE perfil = 'admin' AND ativo = 1 ORDER BY id_usuario FOR UPDATE");

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function obterLockAdmins(int $segundos): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(CONCAT(:nome, MD5(DATABASE())), :segundos)');
        $stmt->bindValue('nome', self::LOCK_ADMINS);
        $stmt->bindValue('segundos', $segundos, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    public function liberarLockAdmins(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(CONCAT(:nome, MD5(DATABASE())))');
            $stmt->execute(['nome' => self::LOCK_ADMINS]);
            $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('UsuarioGestaoDao: release_lock_falhou ' . get_class($e));
        }
    }

    public function existeAdminAtivo(): bool
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM tb_gestao_usuario WHERE perfil = 'admin' AND ativo = 1")->fetchColumn() > 0;
    }
}
