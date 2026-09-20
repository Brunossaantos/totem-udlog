<?php

/**
 * Mock server HTTP local (artefato SO de teste), usado exclusivamente por
 * tests/manual/teste_vio_decode_robustez.php via `php -S` (nunca acessado
 * pelo VioDecodeClient em producao, nunca committed como parte do
 * comportamento real do sistema). Roteia por query string `?cenario=` para
 * simular respostas de transporte (tamanho limite, JSON malformado, corpo
 * vazio, qualquer HTTP status com corpo arbitrario) que nao sao cobertas
 * por VioDecodeClientFalso (que so mocka decodificar() inteiro, sem passar
 * pelo cURL/parse real).
 *
 * NUNCA usar contra o Serpro/VIO real — servidor 100% local e sintetico,
 * dados ficticios ('FULANO'/CPF de teste conhecido, nunca dado real).
 *
 * Uso: php -S 127.0.0.1:<porta> tests/manual/mock_vio_server.php
 */

/**
 * Gera um corpo JSON valido com EXATAMENTE $tamanhoAlvo bytes, preenchendo
 * um campo de padding com caracteres ASCII simples ('x', nunca precisam de
 * escape em JSON) — o acrescimo ao tamanho final e sempre 1:1 com o
 * tamanho do padding, permitindo atingir o byte exato sem tentativa/erro.
 */
function corpoComTamanhoExato(int $tamanhoAlvo): string
{
    $envelopeSemPadding = json_encode([
        'data' => [
            'nome' => 'FULANO',
            'cpf' => '11144477735',
            'data_validade' => '2030-01-01',
            'padding' => '',
        ],
    ]);

    $faltam = max(0, $tamanhoAlvo - strlen($envelopeSemPadding));

    return json_encode([
        'data' => [
            'nome' => 'FULANO',
            'cpf' => '11144477735',
            'data_validade' => '2030-01-01',
            'padding' => str_repeat('x', $faltam),
        ],
    ]);
}

$cenario = $_GET['cenario'] ?? '';

header('Content-Type: application/json');

switch ($cenario) {
    case 'tamanho_exato':
        $tamanhoAlvo = (int) ($_GET['bytes'] ?? 0);
        http_response_code(200);
        echo corpoComTamanhoExato($tamanhoAlvo);
        break;

    case 'json_malformado':
        http_response_code(200);
        echo '{isso nao e json valido';
        break;

    case 'vazio':
        http_response_code(200);
        echo '';
        break;

    case 'generico':
        // Simula qualquer HTTP status com um corpo arbitrario (base64 na
        // query string, decodificado aqui) — usado para provar que nenhum
        // conteudo da resposta EXTERNA (mesmo se ela tentasse "vazar" algo
        // por conta propria) chega ao campo sanitizado devolvido ao totem.
        $status = (int) ($_GET['status'] ?? 500);
        $corpoBase64 = (string) ($_GET['corpo_base64'] ?? '');
        $corpo = $corpoBase64 !== '' ? base64_decode($corpoBase64, true) : '';
        http_response_code($status);
        echo $corpo === false ? '' : $corpo;
        break;

    default:
        http_response_code(404);
        echo json_encode(['erro' => 'cenario desconhecido']);
        break;
}
