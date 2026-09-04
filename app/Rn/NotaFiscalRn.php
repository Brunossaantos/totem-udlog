<?php

namespace App\Rn;

use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;

class NotaFiscalRn
{
    public function __construct(
        private AtendimentoNotaDao $notaDao,
        private ClienteDao $clienteDao
    ) {}

    public function processarLeitura(int $idAtendimento, int $ordem, string $arquivo, ?string $chave): array
    {
        $cnpjEmitente = null;
        $clienteIdentificado = false;
        $cliente = null;

        if ($chave && $this->chaveValida($chave)) {
            $decodificada = $this->decodificarChave($chave);
            if ($decodificada['dv_ok']) {
                $cnpjEmitente = $decodificada['cnpj_emitente'];
                $cliente = $this->clienteDao->buscarPorCnpj($cnpjEmitente);
                $clienteIdentificado = $cliente !== null;
            }
        }

        $this->notaDao->inserir($idAtendimento, $ordem, $arquivo, $chave, $cnpjEmitente, $clienteIdentificado);

        return ['cliente_identificado' => $clienteIdentificado, 'cliente' => $cliente];
    }

    public function algumaNotaIdentificouCliente(int $idAtendimento): bool
    {
        return $this->notaDao->algumaIdentificada($idAtendimento);
    }

    public function contarNotas(int $idAtendimento): int
    {
        return $this->notaDao->contarPorAtendimento($idAtendimento);
    }

    public function ordemJaRegistrada(int $idAtendimento, int $ordem): bool
    {
        return $this->notaDao->existeOrdem($idAtendimento, $ordem);
    }

    public function chaveValida(string $chave): bool
    {
        return strlen($chave) === 44 && ctype_digit($chave);
    }

    // decodificacao 100% local, sem custo e sem chamada externa
    public function decodificarChave(string $chave): array
    {
        $aamm = substr($chave, 2, 4);
        $dvInformado = (int) substr($chave, 43, 1);
        $dvCalculado = $this->calcularDV(substr($chave, 0, 43));

        return [
            'uf'            => substr($chave, 0, 2),
            'emissao'       => substr($aamm, 2, 2) . '/20' . substr($aamm, 0, 2),
            'cnpj_emitente' => substr($chave, 6, 14),
            'serie'         => (int) substr($chave, 22, 3),
            'numero_nf'     => (int) substr($chave, 25, 9),
            'dv_ok'         => $dvInformado === $dvCalculado,
        ];
    }

    private function calcularDV(string $chave43): int
    {
        $peso = 2;
        $soma = 0;
        for ($i = strlen($chave43) - 1; $i >= 0; $i--) {
            $soma += (int) $chave43[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $resto = $soma % 11;
        return ($resto === 0 || $resto === 1) ? 0 : 11 - $resto;
    }
}
