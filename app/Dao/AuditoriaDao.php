<?php

namespace App\Dao;

use InvalidArgumentException;
use PDO;
use Util\IpCliente;

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
        'TOTEM_CRIAR',
        'TOTEM_ATIVO',
        'TOTEM_URL_REGERAR',
        'RETENCAO_EXECUTAR',
        'OC_ATIVAR',
        'OC_INATIVAR',
        'OC_VER_PDF',
        'OC_BAIXA_RESOLVER',
        'CLIENTE_CRIAR',
        'CLIENTE_EDITAR',
        'CLIENTE_ATIVO',
        'CLIENTE_EXCLUIR',
        'EMPRESA_CRIAR',
        'EMPRESA_EDITAR',
        'EMPRESA_ATIVO',
        'EMPRESA_EXCLUIR',
    ];

    public const ALVO_TIPOS = ['usuario', 'totem', 'sistema', 'ordem_coleta', 'oc_baixa', 'cliente', 'empresa'];

    public const RESULTADOS_FINAIS = ['OK', 'SEM_EFEITO', 'ERRO'];

    public const DETALHE_CAMPOS = [
        'origem' => ['web', 'cli', 'cron'],
        'motivo' => ['credenciais', 'conta_bloqueada', 'ip_limitado', 'conta_inativa'],
        'perfil_de' => ['admin', 'usuario'],
        'perfil_para' => ['admin', 'usuario'],
        'ativo_para' => ['0', '1'],
        'sessoes_revogadas' => 'int',
        'empresa' => 'id',
        'logs_apagados' => 'int_grande',
        'auditoria_apagados' => 'int_grande',
        'lotes' => 'int_grande',
        'status_de' => ['ATIVA', 'INATIVA'],
        'status_para' => ['ATIVA', 'INATIVA'],
        'motivo_oc' => ['ja_no_estado', 'estado_mudou', 'oc_inexistente', 'externo_indisponivel', 'arquivo_ausente', 'confirmado_andamento', 'confirmado_ja_baixada', 'confirmado_cliente_inativo', 'cliente_ausente', 'oc_ambigua', 'oc_ativa'],
        'motivo_cad' => ['andamento_confirmado', 'totens_ativos_confirmado', 'totens_vinculados', 'ja_no_estado', 'duplicado', 'nao_encontrado', 'confirmado_ambiguidade_ocr'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Abre uma linha PENDENTE e devolve o id para o fechamento. Usa a MESMA conexao do
     * chamador: se ha transacao aberta, o INSERT participa dela (commit e rollback valem
     * tambem para o PENDENTE). Uso real: (a) nas Rn de usuario, totem e cadastros (F6) a
     * abertura ocorre DENTRO da transacao da mutacao; se a gravacao falha, o rollback
     * desfaz o PENDENTE junto, e quem chama grava depois uma linha ERRO com `registrar`
     * (ex.: CadastroGestaoBase::registrarErro); `fechar('OK')` so roda depois do COMMIT;
     * (b) nas acoes de OC (GestaoOrdemController) a abertura ocorre FORA de transacao
     * (autocommit), antes do UPDATE externo, e por isso sobrevive a uma falha posterior.
     *
     * @param array<string,string|int> $detalhe
     */
    public function abrir(?int $idUsuario, string $acao, ?string $alvoTipo, ?int $alvoId, array $detalhe = [], ?string $ip = null): int
    {
        return $this->inserir($idUsuario, $acao, $alvoTipo, $alvoId, $detalhe, $ip, 'PENDENTE');
    }

    public function registrar(?int $idUsuario, string $acao, ?string $alvoTipo, ?int $alvoId, string $resultado, array $detalhe = [], ?string $ip = null): int
    {
        if (!in_array($resultado, self::RESULTADOS_FINAIS, true)) {
            throw new InvalidArgumentException('resultado de auditoria invalido');
        }

        return $this->inserir($idUsuario, $acao, $alvoTipo, $alvoId, $detalhe, $ip, $resultado);
    }

    public function fechar(int $idAuditoria, string $resultado, array $detalhe = []): bool
    {
        if (!in_array($resultado, self::RESULTADOS_FINAIS, true)) {
            throw new InvalidArgumentException('resultado de auditoria invalido');
        }
        $texto = self::montarDetalhe($detalhe);
        $stmt = $this->pdo->prepare(
            "UPDATE tb_gestao_auditoria SET resultado = :resultado, detalhe = COALESCE(:detalhe, detalhe) WHERE id_auditoria = :id AND resultado = 'PENDENTE'"
        );
        $stmt->bindValue('resultado', $resultado);
        $stmt->bindValue('detalhe', $texto, $texto === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('id', $idAuditoria, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public static function montarDetalhe(array $detalhe): ?string
    {
        $partes = [];
        foreach ($detalhe as $chave => $valor) {
            if (!is_string($chave) || !array_key_exists($chave, self::DETALHE_CAMPOS)) {
                throw new InvalidArgumentException('chave de detalhe fora da allowlist');
            }
            $regra = self::DETALHE_CAMPOS[$chave];
            $texto = (string) $valor;
            if ($regra === 'id') {
                if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $texto) !== 1) {
                    throw new InvalidArgumentException('valor de detalhe fora da allowlist');
                }
            } elseif ($regra === 'int_grande') {
                if (preg_match('/\A[0-9]{1,9}\z/D', $texto) !== 1) {
                    throw new InvalidArgumentException('valor de detalhe fora da allowlist');
                }
            } elseif ($regra === 'int') {
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
