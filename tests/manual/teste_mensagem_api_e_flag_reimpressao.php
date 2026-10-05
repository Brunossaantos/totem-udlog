<?php

/**
 * Teste unitario SEM banco, SEM rede: Util\MensagemApi::sanitizar (caracteres
 * invisiveis/de direcao) e ImpressaoAtendimentoController::flagReimpressao.
 *
 * Uso: php tests/manual/teste_mensagem_api_e_flag_reimpressao.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Util\MensagemApi;
use App\Controller\ImpressaoAtendimentoController;

$total = 0;
$falhas = 0;
function afirmar(string $d, bool $c): void
{
    global $total, $falhas;
    $total++;
    echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n";
    if (!$c) $falhas++;
}

function chr8(int $cp): string
{
    return mb_chr($cp, 'UTF-8');
}

// Cada codepoint removido (entre duas palavras -> vira espaco; nas pontas -> some).
$classes = [
    'C0 (0x01)' => [0x01],
    'DEL' => [0x7F],
    'C1' => [0x80, 0x85, 0x9F],
    'soft hyphen U+00AD' => [0x00AD],
    'ALM U+061C' => [0x061C],
    'mongolian vowel separator U+180E' => [0x180E],
    'U+2028/2029' => [0x2028, 0x2029],
    'U+200B-200F' => range(0x200B, 0x200F),
    'bidi U+202A-202E' => range(0x202A, 0x202E),
    'word joiner e invisiveis U+2060-2064' => range(0x2060, 0x2064),
    'bidi isolates U+2066-2069' => range(0x2066, 0x2069),
    'BOM U+FEFF' => [0xFEFF],
];
foreach ($classes as $nome => $cps) {
    $ok = true;
    foreach ($cps as $cp) {
        $c = chr8($cp);
        $ok = $ok && MensagemApi::sanitizar('Ola' . $c . 'mundo') === 'Ola mundo'
            && MensagemApi::sanitizar($c . 'Ola' . $c) === 'Ola'
            && MensagemApi::sanitizar($c . $c) === null;
    }
    afirmar("remove $nome", $ok);
}

afirmar('texto com acentos preservado', MensagemApi::sanitizar('Crachá já associado à ação — número inválido, ÇÃO') === 'Crachá já associado à ação — número inválido, ÇÃO');
afirmar('espacos normalizados', MensagemApi::sanitizar("  a \t\n b   c ") === 'a b c');
afirmar('UTF-8 invalido (latin1) convertido', MensagemApi::sanitizar("A\xE7\xE3o") === 'Ação');
afirmar('limite de 300 caracteres', mb_strlen((string) MensagemApi::sanitizar(str_repeat('ã', 500)), 'UTF-8') === 300);

// Flag de reimpressao (query OU corpo).
$f = [ImpressaoAtendimentoController::class, 'flagReimpressao'];
afirmar('reimpressao: nada -> false', $f([], []) === false);
afirmar('reimpressao: query "1" -> true', $f(['reimpressao' => '1'], []) === true);
afirmar('reimpressao: corpo 1 -> true', $f([], ['reimpressao' => 1]) === true);
afirmar('reimpressao: corpo true -> true', $f([], ['reimpressao' => true]) === true);
afirmar('reimpressao: corpo "1" -> true', $f([], ['reimpressao' => '1']) === true);
afirmar('reimpressao: query 0/"0"/vazio -> false', $f(['reimpressao' => '0'], []) === false && $f(['reimpressao' => ''], []) === false);
afirmar('reimpressao: corpo 0/false/"true"/2 -> false', $f([], ['reimpressao' => 0]) === false && $f([], ['reimpressao' => false]) === false && $f([], ['reimpressao' => 'true']) === false && $f([], ['reimpressao' => 2]) === false);
afirmar('reimpressao: outros campos nao ativam', $f([], ['id_atendimento' => 1, 'destinatario' => 'motorista']) === false);

echo "\n$total testes, $falhas falhas\n";
exit($falhas === 0 ? 0 : 1);
