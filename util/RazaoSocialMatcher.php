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
     * @return array{identificado: bool, cliente: ?array, score1: float, score2: float}
     */
    public static function melhorCandidato(string $razaoSocialCandidata, array $listagem): array
    {
        $candidataNormalizada = self::normalizar($razaoSocialCandidata);

        if ($candidataNormalizada === '' || empty($listagem)) {
            return ['identificado' => false, 'cliente' => null, 'score1' => 0.0, 'score2' => 0.0];
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
            return ['identificado' => false, 'cliente' => null, 'score1' => 0.0, 'score2' => 0.0];
        }

        usort($ranking, static fn(array $a, array $b) => $b['score'] <=> $a['score']);

        $score1 = $ranking[0]['score'];
        $score2 = count($ranking) > 1 ? $ranking[1]['score'] : 0.0;

        $identificado = $score1 >= self::LIMIAR_MINIMO && ($score1 - $score2) >= self::MARGEM_MINIMA;

        return [
            'identificado' => $identificado,
            'cliente'      => $identificado ? $ranking[0]['item'] : null,
            'score1'       => $score1,
            'score2'       => $score2,
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

    private static function normalizar(string $razaoSocial): string
    {
        $normalizada = strtoupper($razaoSocial);
        $transliterada = @iconv('UTF-8', 'ASCII//TRANSLIT', $normalizada);
        if ($transliterada !== false && $transliterada !== null) {
            $normalizada = $transliterada;
        }

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
