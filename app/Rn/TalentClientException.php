<?php

namespace App\Rn;

class TalentClientException extends \RuntimeException
{
    public const CATEGORIAS_VALIDAS = [
        'erro_validacao', 'erro_autenticacao', 'nao_encontrado', 'conflito',
        'erro_servidor', 'timeout', 'erro_indeterminado', 'erro_conexao',
        'erro_http', 'resposta_ilegivel',
    ];

    private ?string $mensagemApi = null;

    public function __construct(private string $categoria)
    {
        if (!in_array($categoria, self::CATEGORIAS_VALIDAS, true)) {
            throw new \InvalidArgumentException("Categoria de erro do Talent invalida: {$categoria}");
        }

        parent::__construct($categoria);
    }

    public function categoria(): string
    {
        return $this->categoria;
    }

    public function mensagemApi(): ?string
    {
        return $this->mensagemApi;
    }

    public function comMensagemApi(?string $mensagemApi): static
    {
        $this->mensagemApi = $mensagemApi;
        return $this;
    }

    public function ehTimeout(): bool
    {
        return $this->categoria === 'timeout';
    }

    public function ehIndeterminado(): bool
    {
        return $this->categoria === 'timeout' || $this->categoria === 'erro_indeterminado';
    }

    public function ehConflito(): bool
    {
        return $this->categoria === 'conflito';
    }
}
