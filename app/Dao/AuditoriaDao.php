<?php

namespace App\Dao;

use InvalidArgumentException;
use PDO;
use Util\IpCliente;

/**
 * Auditoria APPEND-ONLY da Gestao Totem (tb_gestao_auditoria, migration 021).
 *
 * Contrato de privacidade: NUNCA grava senha, token, hash, CPF, nome, placa nem
 * URL de totem. Por isso:
 *  - `acao` pertence a um catalogo fechado (ACOES), `alvo_tipo` tambem;
 *  - `detalhe` e montado SO a partir da allowlist DETALHE_CAMPOS (chave fixa +
 *    valor de um conjunto fechado ou inteiro limitado). Chave ou valor fora da
 *    allowlist lancam InvalidArgumentException (erro de programacao) e nada e
 *    gravado: texto livre nunca chega a coluna.
 *
 * IP: a coluna `ip` guarda o IP COMPLETO em binario, em CLARO (nao e hash nem
 * truncado), de proposito: a trilha serve a analise forense. A contrapartida e
 * a retencao curta de 90 dias (decisao do usuario), a ser implementada na fase
 * F3 junto do cron de logs; ate la a tabela nao e podada. (O hash de IP com sal
 * existe so nos contadores de login, tb_gestao_login_tentativa.)
 *
 * Esta classe so faz INSERT e UM UPDATE: o fechamento do `resultado` de uma
 * linha PENDENTE (abrir antes da acao, fechar depois). Nao existe DELETE nem
 * outro UPDATE (o teste da gestao varre este arquivo para garantir).
 */
class AuditoriaDao
{
    public const ACOES = [
        'LOGIN_OK',
        'LOGIN_FALHA',
        'LOGOUT',
        'SENHA_TROCADA',
        'SENHA_RESETADA',
        'USUARIO_CRIAR',
        'USUARIO_PERFIL',
        'USUARIO_ATIVO',
        'USUARIO_DESBLOQUEAR',
    ];

    public const ALVO_TIPOS = ['usuario'];

    public const RESULTADOS_FINAIS = ['OK', 'SEM_EFEITO', 'ERRO'];

    /**
     * Allowlist de `detalhe`: chave => lista fechada de valores permitidos, ou
     * 'int' (inteiro 0..9999).
     */
    public const DETALHE_CAMPOS = [
        'origem' => ['web', 'cli'],
        'motivo' => ['credenciais', 'conta_bloqueada', 'ip_limitado', 'conta_inativa'],
        'perfil_de' => ['admin', 'usuario'],
        'perfil_para' => ['admin', 'usuario'],
        'ativo_para' => ['0', '1'],
        'sessoes_revogadas' => 'int',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Abre uma linha PENDENTE (autocommit, fora de qualquer transacao do chamador
     * para sobreviver a um rollback) e devolve o id para o fechamento.
     *
     * @param array<string,string|int> $detalhe
     */
    public function abrir(?int $idUsuario, string $acao, ?string $alvoTipo, ?int $alvoId, array $detalhe = [], ?string $ip = null): int
    {
        return $this->inserir($idUsuario, $acao, $alvoTipo, $alvoId, $detalhe, $ip, 'PENDENTE');
    }

    /**
     * Registra um evento ja concluido (LOGIN_OK, LOGIN_FALHA, LOGOUT).
     *
     * @param array<string,string|int> $detalhe
     */
    public function registrar(?int $idUsuario, string $acao, ?string $alvoTipo, ?int $alvoId, string $resultado, array $detalhe = [], ?string $ip = null): int
    {
        if (!in_array($resultado, self::RESULTADOS_FINAIS, true)) {
            throw new InvalidArgumentException('resultado de auditoria invalido');
        }

        return $this->inserir($idUsuario, $acao, $alvoTipo, $alvoId, $detalhe, $ip, $resultado);
    }

    /** Fecha uma linha PENDENTE. Unico UPDATE desta classe: nunca altera linha ja fechada. */
    public function fechar(int $idAuditoria, string $resultado): bool
    {
        if (!in_array($resultado, self::RESULTADOS_FINAIS, true)) {
            throw new InvalidArgumentException('resultado de auditoria invalido');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE tb_gestao_auditoria SET resultado = :resultado WHERE id_auditoria = :id AND resultado = 'PENDENTE'"
        );
        $stmt->execute(['resultado' => $resultado, 'id' => $idAuditoria]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Valida e serializa o detalhe pela allowlist.
     *
     * @param array<string,string|int> $detalhe
     */
    public static function montarDetalhe(array $detalhe): ?string
    {
        $partes = [];
        foreach ($detalhe as $chave => $valor) {
            if (!is_string($chave) || !array_key_exists($chave, self::DETALHE_CAMPOS)) {
                throw new InvalidArgumentException('chave de detalhe fora da allowlist');
            }
            $regra = self::DETALHE_CAMPOS[$chave];
            $texto = (string) $valor;
            if ($regra === 'int') {
                if (preg_match('/\A[0-9]{1,4}\z/D', $texto) !== 1) {
                    throw new InvalidArgumentException('valor de detalhe fora da allowlist');
                }
            } elseif (!in_array($texto, $regra, true)) {
                throw new InvalidArgumentException('valor de detalhe fora da allowlist');
            }
            $partes[] = $chave . '=' . $texto;
        }

        return $partes === [] ? null : implode(';', $partes);
    }

    /** @param array<string,string|int> $detalhe */
    private function inserir(?int $idUsuario, string $acao, ?string $alvoTipo, ?int $alvoId, array $detalhe, ?string $ip, string $resultado): int
    {
        if (!in_array($acao, self::ACOES, true)) {
            throw new InvalidArgumentException('acao de auditoria fora do catalogo');
        }
        if ($alvoTipo !== null && !in_array($alvoTipo, self::ALVO_TIPOS, true)) {
            throw new InvalidArgumentException('alvo_tipo de auditoria fora do catalogo');
        }
        $texto = self::montarDetalhe($detalhe);
        $binario = $ip !== null ? IpCliente::paraBinario($ip) : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_gestao_auditoria (id_usuario, acao, alvo_tipo, alvo_id, resultado, detalhe, ip)
             VALUES (:id_usuario, :acao, :alvo_tipo, :alvo_id, :resultado, :detalhe, :ip)'
        );
        $stmt->bindValue('id_usuario', $idUsuario, $idUsuario === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('acao', $acao);
        $stmt->bindValue('alvo_tipo', $alvoTipo, $alvoTipo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('alvo_id', $alvoId, $alvoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('resultado', $resultado);
        $stmt->bindValue('detalhe', $texto, $texto === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('ip', $binario, $binario === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }
}
