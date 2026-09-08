<?php
/**
 * Fixtures compartilhadas entre teste_identificar_cliente.php (exercita
 * App\Rn\NotaFiscalRn diretamente) e _caso_controller_identificar_cliente.php
 * (exercita App\Controller\NotaController::identificarCliente() de verdade,
 * em subprocesso isolado — ver comentario daquele arquivo para o motivo do
 * subprocesso).
 *
 * Extraido para arquivo proprio em 2026-09-08 (revisao dos achados MEDIOS de
 * try/catch e validacao de status/etapa em NotaController::identificarCliente)
 * para nao duplicar a criacao de totem/atendimento/nota de teste entre os
 * dois scripts.
 */

const PLACA_TESTE = 'TSTQA01';
const CODIGO_TOTEM_TESTE = 'TESTE-QA-IDCLI';
const CODIGO_TOTEM_ALHEIO = 'TESTE-QA-IDCLI-ALHEIO';

function obterOuCriarTotemTeste(PDO $pdo, string $codigo): int
{
    $stmt = $pdo->prepare('SELECT id_totem FROM tb_totem WHERE codigo = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $token = str_pad('token-teste-' . $codigo, 64, '0');
    $pdo->prepare('
        INSERT INTO tb_totem (codigo, nome, token_api, ativo)
        VALUES (:codigo, :nome, :token, 1)
    ')->execute(['codigo' => $codigo, 'nome' => "Totem Teste ($codigo)", 'token' => substr($token, 0, 64)]);
    return (int) $pdo->lastInsertId();
}

/**
 * @param string $status status de tb_atendimento (default = caminho feliz,
 *   mesmo estado usado por todos os casos originais deste script)
 * @param string $etapaAtual etapa_atual de tb_atendimento (default = caminho
 *   feliz, mesmo estado usado por todos os casos originais deste script)
 */
function criarAtendimentoTeste(
    PDO $pdo,
    int $idTotem,
    string $status = 'em_andamento',
    string $etapaAtual = 'digitalizacao_notas'
): int {
    $codigoPublico = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    $stmt = $pdo->prepare('
        INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, etapa_atual, placa, pasta_documentos)
        VALUES (:codigo, :idTotem, \'recebimento\', :status, :etapa, :placa, :pasta)
    ');
    $stmt->execute([
        'codigo'  => $codigoPublico,
        'idTotem' => $idTotem,
        'status'  => $status,
        'etapa'   => $etapaAtual,
        'placa'   => PLACA_TESTE,
        'pasta'   => '2026-09-04/TESTE_QA_' . substr(md5($codigoPublico), 0, 8),
    ]);
    return (int) $pdo->lastInsertId();
}

function criarNotaTeste(PDO $pdo, int $idAtendimento, int $ordem): int
{
    $stmt = $pdo->prepare('
        INSERT INTO tb_atendimento_nota (id_atendimento, ordem, arquivo, status_ocr)
        VALUES (:id, :ordem, :arquivo, \'PENDENTE\')
    ');
    $stmt->execute(['id' => $idAtendimento, 'ordem' => $ordem, 'arquivo' => sprintf('nota_%02d.jpg', $ordem)]);
    return (int) $pdo->lastInsertId();
}

// chave de acesso de teste (44 digitos), DV calculado corretamente, com o
// CNPJ do emitente embutido na posicao real da chave (posicoes 7-20).
function montarChaveTeste(string $cnpjEmitente14): string
{
    // uf=35 (SP), aamm=2609, cnpj emitente, modelo=55, serie=001, numero=000000001, tpEmis=1, cNF=00000001
    $base = '35' . '2609' . $cnpjEmitente14 . '55' . '001' . '000000001' . '1' . '00000001';
    $base = str_pad($base, 43, '0');
    $peso = 2;
    $soma = 0;
    for ($i = strlen($base) - 1; $i >= 0; $i--) {
        $soma += (int) $base[$i] * $peso;
        $peso = $peso === 9 ? 2 : $peso + 1;
    }
    $resto = $soma % 11;
    $dv = ($resto === 0 || $resto === 1) ? 0 : 11 - $resto;
    return $base . $dv;
}
