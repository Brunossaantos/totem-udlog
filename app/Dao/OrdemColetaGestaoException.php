<?php

namespace App\Dao;

/**
 * Falha ao acessar o banco externo de ordens de coleta (ou o do totem) pelos
 * DAOs da Gestao. Mensagem FIXA e SEM excecao anterior encadeada: a
 * PDOException original pode carregar host/usuario/porta/SQL com valores, e
 * nada disso deve chegar a log, tela ou auditoria (F4a, 2026-10-08).
 */
class OrdemColetaGestaoException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Falha ao consultar ordens de coleta');
    }
}
