<?php

namespace App\Rn;

use App\Dao\OrdemColetaDao;

/**
 * Consulta de ordens de coleta por placa. Ate 2026-09-11 era um adaptador
 * HTTP placeholder (nenhuma API REST real chegou a existir/ser documentada
 * — ver docs/db_gestao_coletas.md). Demanda
 * expedicao-consulta-ordem-coleta-teste substituiu por acesso direto ao
 * banco externo `udlogo59_db_gestao_coletas` (mesmo servidor/credencial do
 * totem, autorizado explicitamente pelo usuario), via App\Dao\OrdemColetaDao
 * (Util\ConexaoGestaoColetas — conexao PDO propria, nunca compartilhada com
 * o banco do totem).
 *
 * Nome da classe/metodo publico preservados (buscarPorPlaca) para nao
 * quebrar App\Rn\AtendimentoRn nem o restante do fluxo de Expedicao.
 *
 * Mapeamento de campos (fonte real, sem coluna de "veiculo"):
 *   numero        <- tb_ordens_coleta.numero_ordem_coleta
 *   cliente_nome  <- tb_clientes.razao_social
 *   cliente_cnpj  <- tb_clientes.cnpj
 *   data          <- tb_ordens_coleta.criado_em (data de cadastro da ordem,
 *                    nao data prevista de coleta — decisao do usuario,
 *                    2026-09-11)
 *   veiculo       <- omitido nesta rodada (sem fonte real confirmada)
 */
class OrdemColetaClient
{
    public function __construct(private OrdemColetaDao $ordemColetaDao) {}

    public function buscarPorPlaca(string $placa): array
    {
        $placaNormalizada = self::normalizarPlaca($placa);

        if ($placaNormalizada === '') {
            return [];
        }

        return $this->ordemColetaDao->buscarPorPlacaNormalizada($placaNormalizada);
    }

    /**
     * Wrapper fino sobre OrdemColetaDao::marcarInativaPorNumero() — usado
     * por App\Controller\AtendimentoController::finalizar() apos check-in
     * confirmado no Talent (ENVIADO/JA_ENVIADO), somente para Expedicao.
     * Qualquer excecao de conexao/banco (ConexaoGestaoColetas) se propaga —
     * o chamador e responsavel por capturar e tratar como "pendente de
     * baixa manual" (nunca bloqueia a resposta de sucesso ja dada ao
     * motorista).
     */
    public function marcarConcluida(string $numero): bool
    {
        return $this->ordemColetaDao->marcarInativaPorNumero($numero);
    }

    /**
     * Wrapper fino sobre OrdemColetaDao::statusPorNumero() — usado por
     * App\Controller\AtendimentoController::tentarMarcarOrdemConcluida()
     * SOMENTE quando marcarConcluida() retornou false, para distinguir
     * "ordem ja estava INATIVA" (idempotente, nao e falha) de uma falha real.
     * Qualquer excecao se propaga, mesmo padrao de marcarConcluida() — o
     * chamador trata como status desconhecido (assume falha real por
     * seguranca, registra pendencia de reconciliacao manual).
     */
    public function statusAtual(string $numero): ?string
    {
        return $this->ordemColetaDao->statusPorNumero($numero);
    }

    /**
     * Normalizacao EXCLUSIVAMENTE no backend — maiusculas, remove espaco/
     * hifen/qualquer caractere que nao seja alfanumerico. Nunca confia em
     * normalizacao feita pelo front-end.
     */
    public static function normalizarPlaca(string $placa): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa));
    }
}
