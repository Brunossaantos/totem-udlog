<?php

/**
 * Subprocesso ISOLADO para o caso 21 da matriz de testes de
 * validacao-jpeg-segura: simula GD indisponivel (extension_loaded('gd') e
 * function_exists('imagecreatefromstring') retornando false) SEM alterar
 * nenhum arquivo de producao.
 *
 * Tecnica: define funcoes com o MESMO NOME dentro do namespace `Util` (a
 * mesma namespace de UploadHelper.php) ANTES de carregar a classe real.
 * Chamadas nao qualificadas a `extension_loaded(...)` dentro de um arquivo
 * que declara `namespace Util;` sao resolvidas em tempo de execucao,
 * primeiro tentando `Util\extension_loaded`, so cain para a global se a
 * versao namespaced nao existir -- e exatamente o mecanismo usado aqui.
 *
 * Roda em processo PHP CLI proprio (nao afeta o restante da suite): o
 * orquestrador principal chama este arquivo via `exec()`/subprocesso.
 *
 * Uso: php caso_gd_indisponivel_mock.php <caminho_arquivo_com_jpeg_valido_base64>
 * (o arquivo de entrada contem o data URL completo, para nao passar
 * conteudo binario/base64 via linha de comando/echo)
 */

namespace Util {
    function extension_loaded(string $nome): bool
    {
        if ($nome === 'gd') {
            return false;
        }
        return \extension_loaded($nome);
    }

    function function_exists(string $nome): bool
    {
        if ($nome === 'imagecreatefromstring') {
            return false;
        }
        return \function_exists($nome);
    }
}

namespace {
    require_once __DIR__ . '/../../util/UploadHelper.php';

    $arquivoEntrada = $argv[1] ?? '';
    if ($arquivoEntrada === '' || !is_readable($arquivoEntrada)) {
        echo "ERRO:entrada_invalida\n";
        exit(2);
    }

    $dataUrl = file_get_contents($arquivoEntrada);

    $_ENV['STORAGE_PATH'] = sys_get_temp_dir() . '/teste_jpeg_seguro_gd_mock_' . bin2hex(random_bytes(4));

    try {
        \Util\UploadHelper::salvarImagemBase64($dataUrl, 'pasta', 'arquivo.jpg');
        echo "RESULTADO:ACEITO\n";
        exit(0);
    } catch (\Throwable $e) {
        // Mensagem ja e generica/fixa por contrato do proprio UploadHelper --
        // seguro reportar (nao contem base64/binario).
        echo "RESULTADO:REJEITADO:" . $e->getMessage() . "\n";
        exit(1);
    } finally {
        $pasta = rtrim($_ENV['STORAGE_PATH'], '/') . '/pasta';
        if (is_dir($pasta)) {
            foreach (glob($pasta . '/*') as $arquivo) {
                unlink($arquivo);
            }
            rmdir($pasta);
        }
        if (is_dir($_ENV['STORAGE_PATH'])) {
            rmdir($_ENV['STORAGE_PATH']);
        }
    }
}
