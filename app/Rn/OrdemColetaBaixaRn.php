<?php

namespace App\Rn;

use App\Dao\OrdemColetaDao;
use App\Dao\OrdemColetaGestaoDao;
use App\Dao\OrdemColetaPendenteBaixaDao;

/**
 * Resolucao MANUAL de baixas pendentes de ordem de coleta (Gestao Totem F4a,
 * 2026-10-08). Uma baixa fica pendente quando o Talent aceitou o check-in mas a
 * baixa (INATIVA) da OC no banco externo falhou, ou quando o atendimento nao
 * tinha o CNPJ do cliente (numero ambiguo: nao se inativa so por numero).
 *
 * Regra do usuario: resolver e SO manual e SO se a OC estiver realmente
 * INATIVA no banco externo, identificada por CLIENTE (CNPJ do atendimento) +
 * numero. Ambiguidade (0 ou mais de 1 OC), cliente ausente, OC ainda ATIVA ou
 * banco externo fora do ar => RECUSA com motivo; nada e marcado.
 *
 * Esta classe nao inativa/ativa a OC (isso e da tela de OCs); so decide e
 * marca a pendencia como resolvida. Limite conhecido: entre a checagem do
 * estado e o UPDATE a OC pode ser reativada por outro usuario (a pendencia
 * fica resolvida sobre uma OC ATIVA); a tela de OCs mostra o estado real.
 */
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

    /**
     * @return array{resultado:string, motivo:?string}
     */
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

        // CAS perdeu: alguem resolveu entre a leitura e o UPDATE
        return ['resultado' => self::JA_RESOLVIDA, 'motivo' => null];
    }
}
