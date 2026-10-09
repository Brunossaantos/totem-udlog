<?php

namespace App\Rn;

use App\Dao\OrdemColetaDao;
use App\Dao\OrdemColetaGestaoDao;
use App\Dao\OrdemColetaPendenteBaixaDao;

class OrdemColetaBaixaRn
{
    public const RESOLVIDA = 'resolvida';
    public const JA_RESOLVIDA = 'ja_resolvida';
    public const INEXISTENTE = 'inexistente';
    public const RECUSADA = 'recusada';

    public const MOTIVO_CLIENTE_AUSENTE = 'cliente_ausente';
    public const MOTIVO_OC_INEXISTENTE = 'oc_inexistente';
    public const MOTIVO_OC_AMBIGUA = 'oc_ambigua';
    public const MOTIVO_OC_ATIVA = 'oc_ativa';
    public const MOTIVO_EXTERNO_INDISPONIVEL = 'externo_indisponivel';

    public function __construct(
        private OrdemColetaPendenteBaixaDao $baixas,
        private OrdemColetaGestaoDao $ordens
    ) {
    }

    public function resolver(int $idBaixa): array
    {
        $baixa = $this->baixas->buscarPorId($idBaixa);
        if ($baixa === null) {
            return ['resultado' => self::INEXISTENTE, 'motivo' => null];
        }
        if ($baixa['resolvido_em'] !== null) {
            return ['resultado' => self::JA_RESOLVIDA, 'motivo' => null];
        }

        $cnpj = OrdemColetaDao::normalizarCnpj((string) ($baixa['cliente_cnpj'] ?? ''));
        $numero = (string) $baixa['numero_ordem_coleta'];
        if ($cnpj === '' || $numero === '') {
            return ['resultado' => self::RECUSADA, 'motivo' => self::MOTIVO_CLIENTE_AUSENTE];
        }

        try {
            $estados = $this->ordens->statusPorClienteNumero($cnpj, $numero);
        } catch (\Throwable $e) {
            return ['resultado' => self::RECUSADA, 'motivo' => self::MOTIVO_EXTERNO_INDISPONIVEL];
        }

        if (count($estados) === 0) {
            return ['resultado' => self::RECUSADA, 'motivo' => self::MOTIVO_OC_INEXISTENTE];
        }
        if (count($estados) > 1) {
            return ['resultado' => self::RECUSADA, 'motivo' => self::MOTIVO_OC_AMBIGUA];
        }
        if ($estados[0] !== 'INATIVA') {
            return ['resultado' => self::RECUSADA, 'motivo' => self::MOTIVO_OC_ATIVA];
        }

        if ($this->baixas->marcarResolvida($idBaixa)) {
            return ['resultado' => self::RESOLVIDA, 'motivo' => null];
        }

        return ['resultado' => self::JA_RESOLVIDA, 'motivo' => null];
    }
}
