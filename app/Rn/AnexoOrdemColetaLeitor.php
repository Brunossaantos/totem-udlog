<?php

namespace App\Rn;

use App\Dao\OrdemColetaArquivoDao;
use Util\OrdemColetaArquivoStorage;

/**
 * Leitor do PDF da Ordem de Coleta para o anexo do Talent (Expedicao),
 * demanda anexo-ordem-coleta-n8n (2026-10-05). Usado por
 * App\Rn\TalentRn::montarAnexos (5o parametro OPCIONAL do construtor).
 *
 * buscar() NUNCA lanca e NUNCA vira ERRO_REPROCESSAVEL: qualquer falha
 * (banco externo fora do ar, linha sem arquivo, arquivo ausente/corrompido/
 * symlink, sha256 divergente...) devolve base64 = null com um 'motivo' de
 * codigo FIXO (para a unica linha de log agregada do chamador). A ausencia
 * pura e simples de registro (a OC nao tem anexo) devolve motivo = null.
 *
 * A conexao com o banco externo e preguicosa (OrdemColetaArquivoDao so
 * conecta ao consultar), entao instanciar este leitor nao abre conexao.
 */
class AnexoOrdemColetaLeitor
{
    public function __construct(
        private OrdemColetaArquivoDao $dao,
        private OrdemColetaArquivoStorage $storage,
        private int $limiteBytes = OrdemColetaArquivoRn::LIMITE_PADRAO_BYTES
    ) {}

    /** Instancia padrao (STORAGE_PATH e limite do .env, conexao preguicosa). */
    public static function padrao(): self
    {
        return new self(
            new OrdemColetaArquivoDao(),
            new OrdemColetaArquivoStorage(),
            OrdemColetaArquivoRn::limiteDoAmbiente($_ENV['ORDEM_COLETA_ANEXO_MAX_BYTES'] ?? null)
        );
    }

    /** @return array{base64:?string, motivo:?string} */
    public function buscar(string $cnpj, string $numero): array
    {
        if (preg_match('/\A\d{14}\z/D', $cnpj) !== 1 || $numero === '') {
            return ['base64' => null, 'motivo' => 'chave_invalida'];
        }

        try {
            $registro = $this->dao->buscarPorChave($cnpj, $numero);
        } catch (\Throwable $e) {
            return ['base64' => null, 'motivo' => 'banco_indisponivel'];
        }
        if ($registro === null) {
            return ['base64' => null, 'motivo' => null];
        }

        try {
            $leitura = $this->storage->ler($registro['caminho_relativo'], $this->limiteBytes, $registro['sha256']);
        } catch (\Throwable $e) {
            return ['base64' => null, 'motivo' => 'leitura_falhou'];
        }
        if ($leitura['bytes'] === null) {
            return ['base64' => null, 'motivo' => $leitura['motivo'] ?? 'leitura_falhou'];
        }

        return ['base64' => base64_encode($leitura['bytes']), 'motivo' => null];
    }
}
