<?php

namespace App\Dao;

use PDO;
use Util\CriptografiaHelper;

/**
 * Cache local seguro (VIO_CACHE) EXCLUSIVO da integracao `vio.api.br` —
 * tabelas NOVAS e SEPARADAS de tb_vio_cache_cnh/tb_vio_cache_crlv (cache do
 * fluxo antigo Serpro, migration 005), nunca misturadas sob o mesmo
 * fingerprint. Ver sql/migrations/016_vio_api_br_cache.sql.
 *
 * fingerprint = hex do HMAC-SHA256(QR bruto, VIO_API_BR_CACHE_HMAC_KEY_V{n})
 * — calculado por App\Rn\DocumentoRn::calcularFingerprintVioApiBr(), nunca
 * aqui (este Dao nunca recebe o QR bruto). nome/cpf/renavam cifrados em
 * repouso via Util\CriptografiaHelper (AES-256-GCM, MESMA chave/mecanismo do
 * cache antigo — DOCUMENTO_DATA_KEY, nenhuma criptografia propria).
 *
 * Concorrencia tratada via INSERT ... ON DUPLICATE KEY UPDATE contra a
 * UNIQUE KEY (fingerprint, hmac_versao, fornecedor, versao_mapeamento) —
 * mesmo espirito de App\Dao\VioCacheDao/RateLimitOcrDao.
 */
class VioApiBrCacheDao
{
    public function __construct(private PDO $pdo) {}

    /**
     * Retorna o registro de cache de CNH ainda VALIDO (estado='VALIDO' E
     * expira_em > NOW()), com nome/cpf ja descriptografados, ou null se nao
     * houver cache valido para essa combinacao (fingerprint, versao de
     * HMAC, versao de mapeamento). Uma versao de mapeamento diferente da
     * gravada NUNCA e considerada um hit (mudanca de versao invalida o
     * cache anterior daquela versao).
     */
    public function buscarCnhValido(string $fingerprint, int $hmacVersao, int $versaoMapeamento): ?array
    {
        // AND origem_original = 'VIO_API_BR' — defesa em profundidade
        // explicita (rodada corretiva de 2026-09-26): hoje e sempre
        // verdadeiro por construcao (fornecedor e origem_original desta
        // tabela sao ENUMs de VALOR UNICO, sempre 'VIO_API_BR'), mas o
        // registro fica explicito no SQL para nunca depender apenas do
        // schema caso o ENUM seja ampliado no futuro — nome/CPF/RNTC/tipo
        // de veiculo de cache SO podem ser reaproveitados de um registro
        // cuja origem original foi de fato uma validacao vio.api.br, nunca
        // de um cache "generico"/origem ambigua.
        $stmt = $this->pdo->prepare("
            SELECT * FROM tb_vio_api_cache_cnh
            WHERE fingerprint = :fp AND hmac_versao = :hv AND fornecedor = 'VIO_API_BR'
              AND origem_original = 'VIO_API_BR'
              AND versao_mapeamento = :vm AND estado = 'VALIDO' AND expira_em > NOW()
        ");
        $stmt->execute(['fp' => $fingerprint, 'hv' => $hmacVersao, 'vm' => $versaoMapeamento]);
        $linha = $stmt->fetch();

        if (!$linha) {
            return null;
        }

        return [
            'nome' => CriptografiaHelper::descriptografar($linha['nome_cifrado']),
            'cpf' => CriptografiaHelper::descriptografar($linha['cpf_cifrado']),
            'data_validade' => $linha['data_validade'],
        ];
    }

    public function salvarCnh(
        string $fingerprint,
        int $hmacVersao,
        int $versaoMapeamento,
        string $nome,
        string $cpf,
        string $dataValidade,
        bool $comparacaoReliable,
        int $comparacaoMismatched,
        string $validadoEmIso,
        string $expiraEmIso
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO tb_vio_api_cache_cnh
                (fingerprint, hmac_versao, fornecedor, versao_mapeamento, nome_cifrado, cpf_cifrado, data_validade,
                 resumo_comparacao_reliable, resumo_comparacao_mismatched, estado, origem_original, validado_em, expira_em)
            VALUES
                (:fp, :hv, 'VIO_API_BR', :vm, :nome, :cpf, :data_validade,
                 :reliable, :mismatched, 'VALIDO', 'VIO_API_BR', :validado_em, :expira_em)
            ON DUPLICATE KEY UPDATE
                nome_cifrado = VALUES(nome_cifrado),
                cpf_cifrado = VALUES(cpf_cifrado),
                data_validade = VALUES(data_validade),
                resumo_comparacao_reliable = VALUES(resumo_comparacao_reliable),
                resumo_comparacao_mismatched = VALUES(resumo_comparacao_mismatched),
                estado = 'VALIDO',
                validado_em = VALUES(validado_em),
                expira_em = VALUES(expira_em)
        ");
        $stmt->execute([
            'fp' => $fingerprint,
            'hv' => $hmacVersao,
            'vm' => $versaoMapeamento,
            'nome' => CriptografiaHelper::criptografar($nome),
            'cpf' => CriptografiaHelper::criptografar($cpf),
            'data_validade' => $dataValidade,
            'reliable' => $comparacaoReliable ? 1 : 0,
            'mismatched' => $comparacaoMismatched,
            'validado_em' => $validadoEmIso,
            'expira_em' => $expiraEmIso,
        ]);
    }

    /**
     * `AND uf/rntc/tipo_veiculo` nao vazios/nao nulos — mesma logica ja
     * aprovada em App\Dao\VioCacheDao::buscarCrlvValido() (cache incompleto
     * nunca e reaproveitado como hit).
     */
    public function buscarCrlvValido(string $fingerprint, int $hmacVersao, int $versaoMapeamento): ?array
    {
        // AND origem_original = 'VIO_API_BR' — mesma defesa em profundidade
        // explicita descrita em buscarCnhValido() acima (rodada corretiva de
        // 2026-09-26): RNTC/tipo de veiculo do cache SO podem ser
        // reaproveitados de um registro cuja origem original foi de fato uma
        // validacao vio.api.br.
        $stmt = $this->pdo->prepare("
            SELECT * FROM tb_vio_api_cache_crlv
            WHERE fingerprint = :fp AND hmac_versao = :hv AND fornecedor = 'VIO_API_BR'
              AND origem_original = 'VIO_API_BR'
              AND versao_mapeamento = :vm AND estado = 'VALIDO' AND expira_em > NOW()
              AND uf IS NOT NULL AND uf <> ''
              AND rntc IS NOT NULL AND rntc <> ''
              AND tipo_veiculo IS NOT NULL AND tipo_veiculo <> ''
        ");
        $stmt->execute(['fp' => $fingerprint, 'hv' => $hmacVersao, 'vm' => $versaoMapeamento]);

        return $stmt->fetch() ?: null;
    }

    public function salvarCrlv(
        string $fingerprint,
        int $hmacVersao,
        int $versaoMapeamento,
        string $placa,
        int $exercicio,
        string $uf,
        string $rntc,
        string $tipoVeiculo,
        ?string $renavam,
        bool $comparacaoReliable,
        int $comparacaoMismatched,
        string $validadoEmIso,
        string $expiraEmIso
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO tb_vio_api_cache_crlv
                (fingerprint, hmac_versao, fornecedor, versao_mapeamento, placa, exercicio, uf, rntc, tipo_veiculo,
                 renavam_cifrado, resumo_comparacao_reliable, resumo_comparacao_mismatched, estado, origem_original,
                 validado_em, expira_em)
            VALUES
                (:fp, :hv, 'VIO_API_BR', :vm, :placa, :exercicio, :uf, :rntc, :tipo_veiculo,
                 :renavam, :reliable, :mismatched, 'VALIDO', 'VIO_API_BR',
                 :validado_em, :expira_em)
            ON DUPLICATE KEY UPDATE
                placa = VALUES(placa),
                exercicio = VALUES(exercicio),
                uf = VALUES(uf),
                rntc = VALUES(rntc),
                tipo_veiculo = VALUES(tipo_veiculo),
                renavam_cifrado = VALUES(renavam_cifrado),
                resumo_comparacao_reliable = VALUES(resumo_comparacao_reliable),
                resumo_comparacao_mismatched = VALUES(resumo_comparacao_mismatched),
                estado = 'VALIDO',
                validado_em = VALUES(validado_em),
                expira_em = VALUES(expira_em)
        ");
        $stmt->execute([
            'fp' => $fingerprint,
            'hv' => $hmacVersao,
            'vm' => $versaoMapeamento,
            'placa' => $placa,
            'exercicio' => $exercicio,
            'uf' => $uf,
            'rntc' => $rntc,
            'tipo_veiculo' => $tipoVeiculo,
            'renavam' => $renavam !== null ? CriptografiaHelper::criptografar($renavam) : null,
            'reliable' => $comparacaoReliable ? 1 : 0,
            'mismatched' => $comparacaoMismatched,
            'validado_em' => $validadoEmIso,
            'expira_em' => $expiraEmIso,
        ]);
    }
}
