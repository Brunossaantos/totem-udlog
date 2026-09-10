<?php

namespace App\Dao;

use PDO;
use Util\CriptografiaHelper;

/**
 * Cache local de CNH/CRLV ja validados pela VIO Decode (Serpro). Chaveado
 * por identificador_qr (hex do HMAC-SHA256 do QR bruto) + ambiente
 * (trial|production) — UNIQUE(identificador_qr, ambiente) nunca cruza
 * cache entre ambientes. Concorrencia tratada via
 * INSERT ... ON DUPLICATE KEY UPDATE contra essa constraint (mesmo espirito
 * de App\Dao\RateLimitOcrDao).
 *
 * nome/cpf de CNH sao criptografados em repouso (Util\CriptografiaHelper,
 * AES-256-GCM) — decisao de seguranca por serem dado pessoal sensivel de
 * titular identificavel. Placa/exercicio de CRLV NAO sao criptografados
 * (dado do veiculo, nao de pessoa fisica, mesmo padrao ja usado hoje em
 * tb_atendimento.placa em texto plano).
 */
class VioCacheDao
{
    public function __construct(private PDO $pdo) {}

    /**
     * Retorna o registro de cache de CNH ainda valido (valido_ate > NOW()),
     * com nome/cpf ja descriptografados, ou null se nao houver cache
     * valido para esse identificador+ambiente.
     */
    public function buscarCnhValido(string $identificadorQr, string $ambiente): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM tb_vio_cache_cnh
            WHERE identificador_qr = :qr AND ambiente = :ambiente AND valido_ate > NOW()
        ');
        $stmt->execute(['qr' => $identificadorQr, 'ambiente' => $ambiente]);
        $linha = $stmt->fetch();

        if (!$linha) {
            return null;
        }

        return [
            'nome' => CriptografiaHelper::descriptografar($linha['nome_cifrado']),
            'cpf' => CriptografiaHelper::descriptografar($linha['cpf_cifrado']),
            'data_validade' => $linha['data_validade'],
            'origem' => $linha['origem'],
            'data_validacao' => $linha['data_validacao'],
            'valido_ate' => $linha['valido_ate'],
        ];
    }

    public function salvarCnh(
        string $identificadorQr,
        string $ambiente,
        string $nome,
        string $cpf,
        string $dataValidade,
        string $origem,
        string $dataValidacaoIso,
        string $validoAteIso
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_vio_cache_cnh
                (identificador_qr, ambiente, nome_cifrado, cpf_cifrado, data_validade, origem, data_validacao, valido_ate)
            VALUES
                (:qr, :ambiente, :nome, :cpf, :data_validade, :origem, :data_validacao, :valido_ate)
            ON DUPLICATE KEY UPDATE
                nome_cifrado = VALUES(nome_cifrado),
                cpf_cifrado = VALUES(cpf_cifrado),
                data_validade = VALUES(data_validade),
                origem = VALUES(origem),
                data_validacao = VALUES(data_validacao),
                valido_ate = VALUES(valido_ate)
        ');
        $stmt->execute([
            'qr' => $identificadorQr,
            'ambiente' => $ambiente,
            'nome' => CriptografiaHelper::criptografar($nome),
            'cpf' => CriptografiaHelper::criptografar($cpf),
            'data_validade' => $dataValidade,
            'origem' => $origem,
            'data_validacao' => $dataValidacaoIso,
            'valido_ate' => $validoAteIso,
        ]);
    }

    /**
     * `AND uf IS NOT NULL` (demanda integracao-talent-portaria-checkin,
     * 2026-09-09): cache gravado ANTES da coluna `uf` existir (ou sem UF
     * estruturalmente valida no momento da gravacao) e tratado como
     * INCOMPLETO — nunca reaproveitado como cache-hit, forca nova consulta
     * ao VIO ou preenchimento manual.
     *
     * `AND rntc IS NOT NULL AND rntc != '' AND tipo_veiculo IS NOT NULL AND
     * tipo_veiculo != ''` (extensao de 2026-09-10, mesma logica): cache
     * gravado antes de RNTC/tipo de veiculo existirem/serem preenchidos e
     * tambem tratado como INCOMPLETO — nunca reaproveitado como cache-hit.
     */
    public function buscarCrlvValido(string $identificadorQr, string $ambiente): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM tb_vio_cache_crlv
            WHERE identificador_qr = :qr AND ambiente = :ambiente AND valido_ate > NOW()
              AND uf IS NOT NULL
              AND rntc IS NOT NULL AND rntc != ''
              AND tipo_veiculo IS NOT NULL AND tipo_veiculo != ''
        ");
        $stmt->execute(['qr' => $identificadorQr, 'ambiente' => $ambiente]);
        $linha = $stmt->fetch();

        return $linha ?: null;
    }

    public function salvarCrlv(
        string $identificadorQr,
        string $ambiente,
        string $placa,
        int $exercicio,
        string $uf,
        string $rntc,
        string $tipoVeiculo,
        string $origem,
        string $dataValidacaoIso,
        string $validoAteIso
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO tb_vio_cache_crlv
                (identificador_qr, ambiente, placa, exercicio, uf, rntc, tipo_veiculo, origem, data_validacao, valido_ate)
            VALUES
                (:qr, :ambiente, :placa, :exercicio, :uf, :rntc, :tipo_veiculo, :origem, :data_validacao, :valido_ate)
            ON DUPLICATE KEY UPDATE
                placa = VALUES(placa),
                exercicio = VALUES(exercicio),
                uf = VALUES(uf),
                rntc = VALUES(rntc),
                tipo_veiculo = VALUES(tipo_veiculo),
                origem = VALUES(origem),
                data_validacao = VALUES(data_validacao),
                valido_ate = VALUES(valido_ate)
        ');
        $stmt->execute([
            'qr' => $identificadorQr,
            'ambiente' => $ambiente,
            'placa' => $placa,
            'exercicio' => $exercicio,
            'uf' => $uf,
            'rntc' => $rntc,
            'tipo_veiculo' => $tipoVeiculo,
            'origem' => $origem,
            'data_validacao' => $dataValidacaoIso,
            'valido_ate' => $validoAteIso,
        ]);
    }
}
