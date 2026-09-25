<?php

namespace App\Dao;

use PDO;

/**
 * Acesso a tb_lgpd_aceite (registro de aceite do Aviso de Privacidade/LGPD
 * exibido na tela inicial do Totem — demanda tela-inicial-lgpd-totem, ver
 * sql/migrations/014_tb_lgpd_aceite.sql).
 *
 * ATENCAO DE SEGURANCA: nenhum metodo desta classe recebe ou grava o token
 * BRUTO em nenhuma coluna — sempre token_hash (SHA-256 hex), calculado pelo
 * chamador (App\Rn\LgpdRn). O token bruto so existe em memoria/trânsito,
 * nunca no banco.
 */
class AceiteLgpdDao
{
    public function __construct(private PDO $pdo) {}

    /**
     * Emite um novo aceite PENDENTE_USO — chamado por
     * App\Controller\LgpdController::aceitar(). Nenhum dado pessoal do
     * motorista e recebido/gravado aqui.
     */
    public function criar(string $tokenHash, int $idTotem, string $versaoTermo, string $hashTermo, string $expiraEm): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_lgpd_aceite (token_hash, id_totem, versao_termo, hash_termo, expira_em, status)
            VALUES (:token_hash, :id_totem, :versao_termo, :hash_termo, :expira_em, "PENDENTE_USO")
        ');
        $stmt->execute([
            'token_hash'   => $tokenHash,
            'id_totem'     => $idTotem,
            'versao_termo' => $versaoTermo,
            'hash_termo'   => $hashTermo,
            'expira_em'    => $expiraEm,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Consumo ATOMICO do token via CAS (UPDATE...WHERE + rowCount()) —
     * mesmo padrao ja usado em TODO App\Dao\AtendimentoDao.php (cancelar(),
     * bloquear(), concluirDigitalizacaoNotas(), etc). So marca USADO se
     * TODAS as condicoes abaixo forem verdadeiras no momento exato do
     * UPDATE (UM UNICO statement atomico — nenhuma leitura/checagem
     * separada antes, para nao abrir nenhuma janela de corrida/TOCTOU):
     *   - token_hash bate com o hash calculado pelo chamador;
     *   - id_totem bate com o totem autenticado NA REQUISICAO ATUAL (nunca
     *     aceita token de outro totem, mesmo que tecnicamente valido);
     *   - status ainda e PENDENTE_USO (uso unico — nunca reconsumido);
     *   - expira_em ainda nao passou;
     *   - versao_termo/hash_termo gravados na EMISSAO ainda batem com o
     *     termo ATUAL (App\Content\TermoLgpd::versao()/hash(), calculados
     *     pelo chamador) — se o termo mudou depois da emissao do token, o
     *     aceite e tratado como invalido, mesma resposta generica de
     *     qualquer outra falha.
     *
     * Retorna o id_aceite consumido em caso de sucesso, ou null se
     * rowCount() === 0 (por QUALQUER motivo — ausente, expirado, ja usado,
     * de outro totem, versao/hash do termo divergente; o chamador NUNCA
     * diferencia esses motivos na resposta ao totem, so em log interno).
     */
    public function consumirPorHash(string $tokenHash, int $idTotem, string $versaoTermoAtual, string $hashTermoAtual): ?int
    {
        $stmt = $this->pdo->prepare('
            UPDATE tb_lgpd_aceite
            SET status = "USADO", usado_em = NOW()
            WHERE token_hash = :token_hash
              AND id_totem = :id_totem
              AND status = "PENDENTE_USO"
              AND expira_em > NOW()
              AND versao_termo = :versao_termo
              AND hash_termo = :hash_termo
        ');
        $stmt->execute([
            'token_hash'   => $tokenHash,
            'id_totem'     => $idTotem,
            'versao_termo' => $versaoTermoAtual,
            'hash_termo'   => $hashTermoAtual,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        $stmtId = $this->pdo->prepare('SELECT id_aceite FROM tb_lgpd_aceite WHERE token_hash = :token_hash');
        $stmtId->execute(['token_hash' => $tokenHash]);
        $idAceite = $stmtId->fetchColumn();

        return $idAceite === false ? null : (int) $idAceite;
    }
}
