<?php

namespace App\Dao;

class CaminhoJaRegistradoException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('caminho_ja_registrado');
    }
}
