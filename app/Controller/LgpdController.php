<?php

namespace App\Controller;

use App\Content\TermoLgpd;
use App\Rn\LgpdRn;
use Util\Resposta;

class LgpdController
{
    public function __construct(private LgpdRn $lgpdRn) {}

    public function aceitar(int $idTotem): void
    {
        $resultado = $this->lgpdRn->emitir($idTotem);

        Resposta::sucesso([
            'token_aceite' => $resultado['token_aceite'],
            'expira_em'    => $resultado['expira_em'],
            'termo'        => [
                'versao' => TermoLgpd::versao(),
                'hash'   => TermoLgpd::hash(),
                'texto'  => TermoLgpd::texto(),
            ],
        ]);
    }
}
