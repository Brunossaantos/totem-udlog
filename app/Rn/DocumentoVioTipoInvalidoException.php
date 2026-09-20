<?php

namespace App\Rn;

/**
 * Lancada quando um campo da resposta VIO Decode (dentro da allowlist de
 * DocumentoRn) chega com um tipo estruturalmente incompativel -- array,
 * objeto/stdClass, bool, int ou float onde um texto (ou ausencia/null) era
 * esperado -- em vez de string ou ausencia (null).
 *
 * Existe para impedir o "fail-open por cast": sem esta checagem, um valor
 * nao-string sofrendo cast implicito/explicito para string (`(string) $x`)
 * silenciosamente vira `"Array"` (so emite E_WARNING, nunca excecao) e
 * seguiria por todas as validacoes de conteudo como se fosse um dado real
 * e valido.
 *
 * Tambem reaproveitada (mesmo caminho de fail-closed, sem estrategia
 * paralela de tratamento de erro) por validarExercicioInteiroExato() em
 * DocumentoRn para o mesmo "fail-open por cast" quando o TIPO ja e
 * compativel (int/float/string) mas o CONTEUDO nao representa um inteiro
 * exato (ex.: `2026.9` truncado silenciosamente para `2026` pelo cast
 * `(int)` -- achado BLOQUEANTE do /03-revisao de 2026-09-20). Nesse caso
 * o terceiro parametro nao e `get_debug_type()`, e um marcador fixo
 * descrevendo o motivo da rejeicao (ex.: "numero_nao_inteiro_exato") --
 * nunca o valor recebido.
 *
 * A mensagem NUNCA carrega o valor recebido (nem parcial) -- so o nome do
 * documento, o nome do campo (ambos fixos, vindos da allowlist do proprio
 * codigo) e um marcador fixo do motivo da rejeicao (tipo PHP via
 * `get_debug_type()`, ex.: "array", "stdClass", "bool"; ou um marcador de
 * conteudo invalido, ex.: "numero_nao_inteiro_exato"). Sempre capturada
 * inteiramente dentro de DocumentoRn (nunca deveria escapar ate
 * DocumentoController).
 */
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
