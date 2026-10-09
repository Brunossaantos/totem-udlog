<?php

namespace App\Rn;

class DocumentoVioTipoInvalidoException extends \RuntimeException
{
    private string $documento;
    private string $campo;
    private string $tipoRecebido;

    public function __construct(string $documento, string $campo, string $tipoRecebido)
    {
        $this->documento = $documento;
        $this->campo = $campo;
        $this->tipoRecebido = $tipoRecebido;

        parent::__construct("DocumentoRn: campo VIO com tipo invalido documento={$documento} campo={$campo} tipo_recebido={$tipoRecebido}");
    }

    public function documento(): string
    {
        return $this->documento;
    }

    public function campo(): string
    {
        return $this->campo;
    }

    public function tipoRecebido(): string
    {
        return $this->tipoRecebido;
    }
}
