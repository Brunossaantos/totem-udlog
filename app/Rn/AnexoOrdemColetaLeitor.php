<?php

namespace App\Rn;

use App\Dao\OrdemColetaArquivoDao;
use Util\OrdemColetaArquivoStorage;

class AnexoOrdemColetaLeitor
{
    public function __construct(
        private OrdemColetaArquivoDao $dao,
        private OrdemColetaArquivoStorage $storage,
        private int $limiteBytes = OrdemColetaArquivoRn::LIMITE_PADRAO_BYTES
    ) {}

    public static function padrao(): self
    {
        return new self(
            new OrdemColetaArquivoDao(),
            new OrdemColetaArquivoStorage(),
            OrdemColetaArquivoRn::limiteDoAmbiente($_ENV['ORDEM_COLETA_ANEXO_MAX_BYTES'] ?? null)
        );
    }

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
