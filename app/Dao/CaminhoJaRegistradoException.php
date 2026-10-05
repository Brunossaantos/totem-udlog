<?php

namespace App\Dao;

/**
 * 1062 em uk_oc_arquivo_caminho (tb_ordem_coleta_arquivos): o caminho relativo
 * ja esta registrado em OUTRA linha. Colisao de NOME, nao de chave
 * (cnpj, numero): o chamador gera outro nome e NUNCA apaga o arquivo existente.
 * Sem mensagem (nada do banco/entrada vai para log).
 */
class CaminhoJaRegistradoException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('caminho_ja_registrado');
    }
}
