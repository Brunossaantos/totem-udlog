<?php

namespace App\Rn;

use App\Dao\OrdemColetaDao;

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

    public function marcarConcluida(string $cnpj, string $numero): bool
    {
        return $this->ordemColetaDao->marcarInativaPorClienteNumero($cnpj, $numero);
    }

    public function statusAtual(string $cnpj, string $numero): ?string
    {
        return $this->ordemColetaDao->statusPorClienteNumero($cnpj, $numero);
    }

    public static function normalizarPlaca(string $placa): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa));
    }
}
