<?php

namespace App\Rn;

/**
 * Adaptador para a API do portal Prodesp (Detran-SP).
 * ATENCAO: viabilidade ainda nao confirmada — depende de convenio/custo por
 * consulta e so cobre documentos emitidos em SP. O QR da CNH digital traz
 * payload assinado (nao eh texto plano legivel) e o QR do CRLV-e costuma
 * ser so uma URL de validacao — nenhum dos dois garante dado estruturado.
 * Por isso este client NUNCA pode travar o atendimento: falha aqui so
 * significa que os campos continuam editaveis manualmente, como ja e o padrao.
 */
class ProdespClient
{
    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = ''
    ) {}

    public function consultarPorQr(string $conteudoQr): ?array
    {
        try {
            // TODO: implementar quando o convenio/contrato estiver confirmado
            return null;
        } catch (\Throwable $e) {
            error_log('Consulta Prodesp falhou: ' . $e->getMessage());
            return null;
        }
    }
}
