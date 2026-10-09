<?php

namespace App\Rn;

use App\Dao\AuditoriaDao;
use App\Dao\UsuarioGestaoDao;
use PDO;
use PDOException;
use Throwable;
use Util\LogSistema;

/**
 * Base dos cadastros da Gestao Totem (clientes e empresas, F6). SO admin (o guard esta
 * em GestaoContexto / nas paginas).
 *
 * Toda mutacao: (1) valida a entrada, (2) toma o lock nomeado, (3) abre a transacao e
 * reconfere que o ator ainda e admin ativo (FOR UPDATE nos admins), (4) trava a linha
 * alvo e confere a regra, (5) abre a auditoria PENDENTE DENTRO da transacao, antes de
 * gravar (na criacao, o id do alvo so existe depois do INSERT, entao ela abre logo apos o
 * INSERT; se nao abrir, nada e gravado), (6) grava, (7) COMMIT e fecha como OK. Como o
 * PENDENTE e parte da transacao, uma falha desfaz o PENDENTE junto com o rollback; em
 * seguida `registrarErro` grava uma linha ERRO (autocommit). Recusas por regra viram
 * SEM_EFEITO. Falha tecnica vira log fixo (SO a classe da excecao) e uma linha ERRO. O `detalhe` da auditoria nunca leva nome, CNPJ ou razao
 * social (so chaves de conjunto fechado do AuditoriaDao).
 */
abstract class CadastroGestaoBase
{
    protected UsuarioGestaoDao $usuarios;

    protected AuditoriaDao $auditoria;

    public function __construct(protected PDO $pdo)
    {
        $this->usuarios = new UsuarioGestaoDao($pdo);
        $this->auditoria = new AuditoriaDao($pdo);
    }

    /** alvo_tipo da auditoria: 'cliente' | 'empresa'. */
    abstract protected function alvoTipo(): string;

    /** Prefixo do error_log (so a classe da excecao e escrita). */
    abstract protected function prefixoLog(): string;

    abstract protected function obterLock(int $segundos): bool;

    abstract protected function liberarLock(): void;

    /**
     * Serializa as mutacoes com um lock NOMEADO do MySQL tomado ANTES da transacao.
     * Falha ou timeout viram `erro_interno` (log so da classe da excecao).
     *
     * @return array<string,mixed>
     */
    protected function comLock(callable $fn): array
    {
        try {
            $obteve = $this->obterLock(10);
        } catch (Throwable $e) {
            error_log($this->prefixoLog() . ': lock_falhou ' . get_class($e));

            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        if (!$obteve) {
            error_log($this->prefixoLog() . ': lock_indisponivel');

            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        try {
            return $fn();
        } finally {
            $this->liberarLock();
        }
    }

    protected function desfazer(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable $e) {
            error_log($this->prefixoLog() . ': rollback_falhou ' . get_class($e));
        }
    }

    protected function ehChaveDuplicada(PDOException $e): bool
    {
        return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
    }

    /** Violacao de chave estrangeira ao apagar o pai (1451). */
    protected function ehFkFilhoExistente(PDOException $e): bool
    {
        return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1451;
    }

    /** O ator ainda e admin ativo? (FOR UPDATE nos admins; chamar DENTRO da transacao.) */
    protected function atorAdmin(int $idAdmin): bool
    {
        return in_array($idAdmin, $this->usuarios->travarAdminsAtivos(), true);
    }

    /** @return array{ok:false,codigo:string} */
    protected function semPermissao(string $acao, int $idAdmin, ?int $idAlvo, ?string $ip): array
    {
        $this->auditarRecusa($acao, $idAdmin, $idAlvo, [], $ip);

        return ['ok' => false, 'codigo' => 'sem_permissao'];
    }

    /** @param array<string,string|int> $detalhe */
    protected function auditarRecusa(string $acao, int $idAdmin, ?int $idAlvo, array $detalhe, ?string $ip): void
    {
        try {
            $this->auditoria->registrar($idAdmin, $acao, $this->alvoTipo(), $idAlvo, 'SEM_EFEITO', $detalhe, $ip);
        } catch (Throwable $e) {
            error_log($this->prefixoLog() . ': auditoria_recusa_falhou ' . get_class($e));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e]);
        }
    }

    protected function registrarErro(string $acao, int $idAtor, ?int $idAlvo, ?string $ip): void
    {
        try {
            $this->auditoria->registrar($idAtor, $acao, $this->alvoTipo(), $idAlvo, 'ERRO', [], $ip);
        } catch (Throwable $e2) {
            error_log($this->prefixoLog() . ': auditoria_erro_falhou ' . get_class($e2));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e2]);
        }
    }

    /** @return array{ok:false,codigo:string} */
    protected function falhaTecnica(string $operacao, Throwable $e, string $acao, int $idAtor, ?int $idAlvo, ?string $ip): array
    {
        error_log($this->prefixoLog() . ': ' . $operacao . '_falhou ' . get_class($e));
        LogSistema::registrar('gestao_erro_interno', ['excecao' => $e]);
        $this->registrarErro($acao, $idAtor, $idAlvo, $ip);

        return ['ok' => false, 'codigo' => 'erro_interno'];
    }

    protected function fecharAuditoria(int $idAuditoria, string $resultado): void
    {
        try {
            $this->auditoria->fechar($idAuditoria, $resultado);
        } catch (Throwable $e) {
            error_log($this->prefixoLog() . ': auditoria_fechar_falhou ' . get_class($e));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e]);
        }
    }
}
