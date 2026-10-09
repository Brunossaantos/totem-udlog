<?php

namespace Util;

class RazaoSocialMatcher
{
    public const LIMIAR_MINIMO = 80.0;
    public const MARGEM_MINIMA = 15.0;

    private const SUFIXOS_SOCIETARIOS = [
        'LTDA ME', 'LTDA', 'S A', 'S/A', 'SA', 'EIRELI', 'ME', 'EPP', 'MEI', 'CIA',
    ];

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

    private const MAPA_ASCII = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE',
        'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'TH', 'ß' => 'SS', 'ẞ' => 'SS',
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

    public static function normalizar(string $razaoSocial): string
    {
        $normalizada = mb_convert_encoding($razaoSocial, 'UTF-8', 'UTF-8');
        $normalizada = mb_strtoupper($normalizada, 'UTF-8');
        $semMarcas = preg_replace('/\p{Mn}+/u', '', $normalizada);
        if (is_string($semMarcas)) {
            $normalizada = $semMarcas;
        }
        $normalizada = strtr($normalizada, self::MAPA_ASCII);

        $normalizada = preg_replace('/[^A-Z0-9 ]/', ' ', $normalizada);

        foreach (self::SUFIXOS_SOCIETARIOS as $sufixo) {
            $normalizada = preg_replace('/\b' . preg_quote($sufixo, '/') . '\b/', ' ', $normalizada);
        }

        $normalizada = preg_replace('/\s+/', ' ', $normalizada);

        return trim($normalizada);
    }
}
