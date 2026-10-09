<?php

namespace Util;

/**
 * Validacao/normalizacao do NOME digitado nos cadastros da gestao (clientes e
 * empresas, F6). Funcao pura, sem I/O.
 *
 * normalizar() recusa (retorna null): bytes que nao sejam UTF-8 valido; entrada acima
 * de 1000 bytes; qualquer caractere das categorias Unicode Cc (controle: NUL, tab,
 * quebra de linha, DEL), Cf (formatacao invisivel: direcao de texto como U+202E,
 * zero-width, tag chars), Zl e Zp (separadores de linha/paragrafo); os caracteres
 * U+3164, U+2800, U+FE0F, U+034F, U+115F, U+1160 e U+FFA0 e a faixa de tag chars
 * U+E0000..U+E007F (invisiveis ou visualmente vazios fora das categorias acima); e
 * mais de 3 marcas combinantes (Mn) seguidas. NAO bloqueia outras categorias (espacos
 * Zs, por exemplo, apenas sao colapsados). Depois: espacos nas pontas removidos e
 * sequencias de espacos colapsadas num so; o tamanho (em caracteres, nao bytes) e
 * conferido DEPOIS da normalizacao.
 * textoBusca() aplica so a checagem de Cc, Cf, Zl e Zp.
 */
final class NomeCadastro
{
    private const ENTRADA_MAX_BYTES = 1000;

    private const PROIBIDOS = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\x{3164}\x{2800}\x{FE0F}\x{034F}\x{115F}\x{1160}\x{FFA0}\x{E0000}-\x{E007F}]/u';

    public static function normalizar(string $bruto, int $min, int $max): ?string
    {
        if (strlen($bruto) > self::ENTRADA_MAX_BYTES || preg_match('//u', $bruto) !== 1) {
            return null;
        }
        if (preg_match(self::PROIBIDOS, $bruto) === 1 || preg_match('/\p{Mn}{4,}/u', $bruto) === 1) {
            return null;
        }
        $colapsado = preg_replace('/\s+/u', ' ', trim($bruto));
        if (!is_string($colapsado)) {
            return null;
        }
        $nome = trim($colapsado);
        $tam = mb_strlen($nome, 'UTF-8');
        if ($tam < $min || $tam > $max) {
            return null;
        }

        return $nome;
    }

    public static function textoBusca(string $bruto, int $max = 60): string
    {
        if (strlen($bruto) > $max * 4 || preg_match('//u', $bruto) !== 1 || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $bruto) === 1) {
            return '';
        }
        $colapsado = preg_replace('/\s+/u', ' ', trim($bruto));
        if (!is_string($colapsado)) {
            return '';
        }
        $texto = trim($colapsado);

        return mb_strlen($texto, 'UTF-8') > $max ? '' : $texto;
    }
}
