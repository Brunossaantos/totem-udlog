<?php

namespace App\Dao;

class OrdemColetaGestaoException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Falha ao consultar ordens de coleta');
    }
}
