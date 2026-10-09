<?php

namespace Util;

/**
 * Fuzzy match de razao social contra a listagem completa de clientes da API
 * externa (App\Rn\ClienteApiClient::listarTodos), usado quando nao houve
 * match de CNPJ exato/chave de acesso.
 *
 * Criterio de confianca (formalizado no handoff de recebimento-leitura-notas,
 * refinamento de 2026-09-04): so identifica automaticamente se o score do 1o
 * colocado for >= LIMIAR_MINIMO E a diferenca entre o 1o e o 2o colocado for
 * >= MARGEM_MINIMA (2o colocado tratado como score 0 se so existir 1
 * candidato na listagem). Qualquer outro caso -> sem identificacao
 * automatica (NAO_IDENTIFICADA), nunca escolhe entre ambiguos.
 */
class RazaoSocialMatcher
{
    // Valores iniciais, a ajustar empiricamente (nao travados como definitivos):
    // LIMIAR_MINIMO calibrado para os exemplos reais informados pelo usuario
    // (CROMIX->CROMEX ~83.33%, CRMEX->CROMEX ~90.91% via similar_text() apos
    // normalizacao — calculado e confirmado nesta implementacao) permanecendo
    // acima de 80 para manter alguma margem de seguranca contra falsos
    // positivos aleatorios.
    public const LIMIAR_MINIMO = 80.0;
    public const MARGEM_MINIMA = 15.0;

    // Lista nao-exaustiva de sufixos societarios comuns removidos antes da
    // comparacao (reduz ruido que nao ajuda a diferenciar empresas).
    private const SUFIXOS_SOCIETARIOS = [
        'LTDA ME', 'LTDA', 'S A', 'S/A', 'SA', 'EIRELI', 'ME', 'EPP', 'MEI', 'CIA',
    ];

    /**
     * @param array<int, array{id:mixed, razao_social:string, cnpj:string}> $listagem
     * @return array{identificado: bool, cliente: ?array, score1: float, score2: float, ambiguo: bool}
     *
     * `ambiguo` (ADITIVO, demanda hardening-revisao-notas-e-cliente,
     * 2026-09-30): true quando o 1o colocado atingiu LIMIAR_MINIMO mas a
     * margem para o 2o ficou abaixo de MARGEM_MINIMA (empate ou quase
     * empate entre clientes) -- o chamador trata como anomalia, nunca
     * escolhe automaticamente. `identificado` continua com a mesma semantica.
     */
    public static function melhorCandidato(string $razaoSocialCandidata, array $listagem): array
    {
        $candidataNormalizada = self::normalizar($razaoSocialCandidata);

        if ($candidataNormalizada === '' || empty($listagem)) {
            return ['identificado' => false, 'cliente' => null, 'score1' => 0.0, 'score2' => 0.0, 'ambiguo' => false];
        }

        $ranking = [];
        foreach ($listagem as $item) {
            $razaoItem = self::normalizar((string) ($item['razao_social'] ?? ''));
            if ($razaoItem === '') {
                continue;
            }
            $ranking[] = ['item' => $item, 'score' => self::melhorScoreContraItem($candidataNormalizada, $razaoItem)];
        }

        if (empty($ranking)) {
            return ['identificado' => false, 'cliente' => null, 'score1' => 0.0, 'score2' => 0.0, 'ambiguo' => false];
        }

        usort($ranking, static fn(array $a, array $b) => $b['score'] <=> $a['score']);

        $score1 = $ranking[0]['score'];
        $score2 = count($ranking) > 1 ? $ranking[1]['score'] : 0.0;

        $identificado = $score1 >= self::LIMIAR_MINIMO && ($score1 - $score2) >= self::MARGEM_MINIMA;
        $ambiguo = $score1 >= self::LIMIAR_MINIMO && !$identificado;

        return [
            'identificado' => $identificado,
            'cliente'      => $identificado ? $ranking[0]['item'] : null,
            'score1'       => $score1,
            'score2'       => $score2,
            'ambiguo'      => $ambiguo,
        ];
    }

    /**
     * A razao social real da API costuma ter mais de uma palavra (ex: "CROMEX
     * TINTAS LTDA"), enquanto o candidato extraido por OCR muitas vezes eh
     * so o nome mais distintivo/curto (ex: "CROMIX"). Comparar a string
     * inteira do candidato contra a string inteira do item penaliza
     * demais por diferenca de tamanho (formula de similar_text() eh
     * 2*sim/(len1+len2)). Em vez disso, compara o candidato contra a
     * string inteira do item E contra toda janela contigua de palavras do
     * item (ex: so "CROMEX", so "TINTAS"), usando o MELHOR score entre
     * todas as comparacoes — assim um candidato curto e distintivo ainda
     * casa bem com a palavra correspondente dentro de um nome maior.
     */
    private static function melhorScoreContraItem(string $candidataNormalizada, string $itemNormalizado): float
    {
        similar_text($candidataNormalizada, $itemNormalizado, $melhorScore);
        $melhorScore = (float) $melhorScore;

        $palavrasItem = explode(' ', $itemNormalizado);
        $totalPalavras = count($palavrasItem);

        for ($tamanhoJanela = 1; $tamanhoJanela < $totalPalavras; $tamanhoJanela++) {
            for ($inicio = 0; $inicio + $tamanhoJanela <= $totalPalavras; $inicio++) {
                $janela = implode(' ', array_slice($palavrasItem, $inicio, $tamanhoJanela));
                similar_text($candidataNormalizada, $janela, $percentual);
                if ($percentual > $melhorScore) {
                    $melhorScore = (float) $percentual;
                }
            }
        }

        return $melhorScore;
    }

    /**
     * Mapa FIXO de letras latinas acentuadas/especiais MAIUSCULAS para ASCII
     * (Latin-1 Supplement e Latin Extended-A completos). Substitui o
     * iconv('ASCII//TRANSLIT'), que dependia da libc/locale: em glibc traduzia
     * so o que estava em maiuscula (strtoupper do PHP 8 e ASCII-only, entao
     * "Cafe com acento" em minuscula virava "caf" + espaco) e no libiconv do
     * Windows devolvia lixo ("'E" para E agudo). O mapa so recebe texto que
     * ja passou por mb_strtoupper, logo so as maiusculas precisam constar.
     */
    private const MAPA_ASCII = [
        // Latin-1 Supplement
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE',
        'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'TH', 'ß' => 'SS', 'ẞ' => 'SS',
        // Latin Extended-A
        'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A', 'Ć' => 'C', 'Ĉ' => 'C', 'Ċ' => 'C', 'Č' => 'C',
        'Ď' => 'D', 'Đ' => 'D', 'Ē' => 'E', 'Ĕ' => 'E', 'Ė' => 'E', 'Ę' => 'E', 'Ě' => 'E',
        'Ĝ' => 'G', 'Ğ' => 'G', 'Ġ' => 'G', 'Ģ' => 'G', 'Ĥ' => 'H', 'Ħ' => 'H',
        'Ĩ' => 'I', 'Ī' => 'I', 'Ĭ' => 'I', 'Į' => 'I', 'İ' => 'I', 'Ĳ' => 'IJ', 'Ĵ' => 'J', 'Ķ' => 'K', 'ĸ' => 'K',
        'Ĺ' => 'L', 'Ļ' => 'L', 'Ľ' => 'L', 'Ŀ' => 'L', 'Ł' => 'L',
        'Ń' => 'N', 'Ņ' => 'N', 'Ň' => 'N', 'Ŋ' => 'NG',
        'Ō' => 'O', 'Ŏ' => 'O', 'Ő' => 'O', 'Œ' => 'OE',
        'Ŕ' => 'R', 'Ŗ' => 'R', 'Ř' => 'R', 'Ś' => 'S', 'Ŝ' => 'S', 'Ş' => 'S', 'Š' => 'S',
        'Ţ' => 'T', 'Ť' => 'T', 'Ŧ' => 'T',
        'Ũ' => 'U', 'Ū' => 'U', 'Ŭ' => 'U', 'Ů' => 'U', 'Ű' => 'U', 'Ų' => 'U',
        'Ŵ' => 'W', 'Ŷ' => 'Y', 'Ÿ' => 'Y', 'Ź' => 'Z', 'Ż' => 'Z', 'Ž' => 'Z',
    ];

    /**
     * Publica desde a F6 (cadastro de clientes da gestao: a razao normalizada
     * gravada em tb_cliente tem de sair DESTE algoritmo, o mesmo que o OCR aplica
     * ao comparar).
     *
     * DECISAO DO USUARIO (2026-10-09): "sempre normalizar os textos para nao ter
     * acentos". A etapa antiga strtoupper + iconv TRANSLIT dependia de
     * locale/libc e perdia letras acentuadas minusculas ("Cafe Acao" com
     * acentos virava "CAF A O"). Agora e deterministica e sem locale/iconv:
     *  1. UTF-8: entrada invalida tem os bytes/sequencias invalidos trocados por
     *     '?' (mb_convert_encoding) e viram espaco adiante, como os bytes >= 0x80
     *     viravam antes; letras validas da mesma string sao preservadas;
     *  2. mb_strtoupper (Unicode, independe de locale; ß vira SS);
     *  3. marcas combinantes (\p{Mn}, cobre NFD) removidas;
     *  4. mapa fixo MAPA_ASCII (Latin-1 e Latin Extended-A);
     *  5. restante IDENTICO ao algoritmo anterior (simbolos -> espaco, sufixos
     *     societarios, colapso de espacos, trim).
     * Para ASCII o resultado e identico ao algoritmo antigo. Alfabetos sem mapa
     * (cirilico, CJK, arabe, emoji, latino estendido adicional) continuam
     * virando espaco. Os valores ja gravados em tb_cliente.razao_social_normalizada
     * antes desta mudanca podem divergir: ver tools/recalcular-razao-normalizada.php.
     */
    public static function normalizar(string $razaoSocial): string
    {
        $normalizada = mb_convert_encoding($razaoSocial, 'UTF-8', 'UTF-8');
        $normalizada = mb_strtoupper($normalizada, 'UTF-8');
        $semMarcas = preg_replace('/\p{Mn}+/u', '', $normalizada);
        if (is_string($semMarcas)) {
            $normalizada = $semMarcas;
        }
        $normalizada = strtr($normalizada, self::MAPA_ASCII);

        // remove pontuacao/simbolos, mantem letras/numeros/espacos
        $normalizada = preg_replace('/[^A-Z0-9 ]/', ' ', $normalizada);

        // remove sufixos societarios (como palavras isoladas, em qualquer posicao)
        foreach (self::SUFIXOS_SOCIETARIOS as $sufixo) {
            $normalizada = preg_replace('/\b' . preg_quote($sufixo, '/') . '\b/', ' ', $normalizada);
        }

        // colapsa espacos multiplos
        $normalizada = preg_replace('/\s+/', ' ', $normalizada);

        return trim($normalizada);
    }
}
