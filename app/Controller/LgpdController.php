<?php

namespace App\Controller;

use App\Content\TermoLgpd;
use App\Rn\LgpdRn;
use Util\Resposta;

/**
 * Endpoint do fluxo de aceite do Aviso de Privacidade/LGPD — demanda
 * tela-inicial-lgpd-totem. Ver public/api/lgpd.php.
 */
class LgpdController
{
    public function __construct(private LgpdRn $lgpdRn) {}

    /**
     * Emite um token de aceite de uso unico para o totem autenticado
     * (Util\Auth::validarTotem ja rodou antes, no entrypoint). Nenhum
     * dado pessoal e recebido nem gravado aqui — so o totem.
     *
     * Tambem retorna o texto/versao/hash ATUAIS do termo (App\Content\
     * TermoLgpd) na mesma resposta — decisao registrada no handoff desta
     * implementacao: como public/totem/index.php ja faz render
     * server-side hoje, ele pode preferir incluir TermoLgpd diretamente e
     * injetar via <script type="application/json"> no HTML, sem depender
     * desta chamada; esta acao devolve os mesmos 3 campos so para cobrir
     * o caso de o front precisar buscá-los via fetch (ex.: re-render sem
     * reload de pagina). Nunca hardcoded em JS — sempre a partir desta
     * classe/endpoint.
     */
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
