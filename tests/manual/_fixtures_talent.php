<?php

/**
 * Fixtures compartilhadas pelos testes manuais da demanda
 * integracao-talent-portaria-checkin (2026-09-09) — NAO e um teste em si
 * (sem exit/afirmacoes), so funcoes auxiliares reaproveitadas pelos testes
 * de payload/anexos/IDOR/idempotencia do Talent. Mesmo espirito de
 * tests/manual/_fixtures_identificar_cliente.php.
 *
 * Requer que o chamador ja tenha feito require_once do autoload +
 * Dotenv::load() antes de incluir este arquivo.
 */

/**
 * Gera um JPEG minimo porem estruturalmente valido (magic bytes reais,
 * dimensoes informadas) via GD — usado no lugar de arquivos 'fake' (usados
 * em outros testes só para checagem de is_file) porque
 * Util\AnexoPdfHelper::validarJpeg confere a assinatura \xFF\xD8\xFF e
 * getimagesize() precisa conseguir ler as dimensoes reais.
 */
function talentCriarJpegValido(string $caminho, int $largura = 200, int $altura = 120): void
{
    $img = imagecreatetruecolor($largura, $altura);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
    imagejpeg($img, $caminho, 85);
    imagedestroy($img);
}

/**
 * Cria um totem de teste ja vinculado a uma tb_empresa (Maua I por padrao,
 * ja semeada pela migration 008) — usado pelos testes que precisam de
 * cnpjArmazem resolvivel.
 */
function talentCriarTotemComEmpresa(PDO $pdo, string $codigo, ?int $idEmpresa = 1): int
{
    $stmt = $pdo->prepare('
        INSERT INTO tb_totem (codigo, nome, token_api, ativo, id_empresa)
        VALUES (:codigo, :nome, :token, 1, :id_empresa)
    ');
    $stmt->execute([
        'codigo' => $codigo,
        'nome' => 'Totem Teste Talent ' . $codigo,
        'token' => 'token_' . bin2hex(random_bytes(8)),
        'id_empresa' => $idEmpresa,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Monta um atendimento completo (expedicao ou recebimento), com
 * motorista/CNH/CRLV aprovados (origem MANUAL, sem depender do VIO), UF
 * valida, pasta em STORAGE_PATH com cnh_frente.jpg/cnh_verso.jpg/crlv.jpg
 * reais (JPEG valido via GD) — pronto para chegar ate
 * AtendimentoController::finalizar()/TalentRn::montarPayload sem barrar em
 * nenhum gate anterior.
 *
 * @return array{id_atendimento:int, pasta:string, pasta_completa:string}
 */
function talentCriarAtendimentoPronto(
    PDO $pdo,
    App\Dao\AtendimentoDao $atendimentoDao,
    int $idTotem,
    string $tipo,
    string $placa,
    string $clienteCnpj = '11222333000181',
    string $uf = 'SP',
    bool $comCnhFrenteVerso = true,
    string $rntc = '12345678',
    string $tipoVeiculo = 'CAMINHAO',
    ?string $ordemColeta = null
): array {
    $idAtendimento = $atendimentoDao->criar($idTotem, $tipo, $placa);

    $pasta = 'teste_talent_' . bin2hex(random_bytes(4));
    $atendimentoDao->definirPasta($idAtendimento, $pasta);
    $pastaCompleta = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pasta;
    if (!is_dir($pastaCompleta)) {
        mkdir($pastaCompleta, 0750, true);
    }

    if ($comCnhFrenteVerso) {
        talentCriarJpegValido($pastaCompleta . '/cnh_frente.jpg');
        talentCriarJpegValido($pastaCompleta . '/cnh_verso.jpg');
    } else {
        talentCriarJpegValido($pastaCompleta . '/cnh.jpg');
    }
    talentCriarJpegValido($pastaCompleta . '/crlv.jpg');

    $atendimentoDao->salvarCliente($idAtendimento, 'CLIENTE TESTE LTDA', $clienteCnpj);
    $atendimentoDao->atualizarValidacaoCnh($idAtendimento, 'MOTORISTA TESTE', '11144477735', '2030-01-01', 'MANUAL', 'PENDENTE_REVISAO');
    $atendimentoDao->atualizarValidacaoCrlv($idAtendimento, strtoupper($placa), 2025, $uf, $rntc, $tipoVeiculo, 'MANUAL', 'PENDENTE_REVISAO');

    // ordem_coleta (demanda talent-doctos-finalizacao-checkin, 2026-09-14) —
    // gravado diretamente via UPDATE simples (preencherDadosOrdem() muda
    // tambem etapa_atual para 'dados_encontrados', o que seria sobrescrito
    // logo abaixo mesmo, mas evitamos o efeito colateral indevido aqui).
    if ($tipo === 'expedicao' && $ordemColeta !== null) {
        $pdo->prepare('UPDATE tb_atendimento SET ordem_coleta = :oc WHERE id_atendimento = :id')
            ->execute(['oc' => $ordemColeta, 'id' => $idAtendimento]);
    }

    $etapaConfirmacao = $tipo === 'expedicao' ? 'exp_confirmacao' : 'rec_confirmacao';
    $atendimentoDao->atualizarEtapa($idAtendimento, $etapaConfirmacao);

    return ['id_atendimento' => $idAtendimento, 'pasta' => $pasta, 'pasta_completa' => $pastaCompleta];
}

/**
 * Insere uma nota fiscal ja com numero_nota definido (fixture da demanda
 * talent-doctos-finalizacao-checkin, 2026-09-14) — usa
 * App\Dao\AtendimentoNotaDao::inserir + atualizarNumero, mesmo caminho de
 * producao, para os testes de montagem de doctos[]/gate de finalizar().
 */
function talentInserirNotaComNumero(App\Dao\AtendimentoNotaDao $notaDao, int $idAtendimento, int $ordem, string $numeroNota, string $origem = 'MANUAL'): int
{
    $idNota = $notaDao->inserir($idAtendimento, $ordem, "nota_{$ordem}.jpg", null, null, false);
    $notaDao->atualizarNumero($idNota, $numeroNota, $origem);
    return $idNota;
}

function talentLimparPasta(string $pastaCompleta): void
{
    if (!is_dir($pastaCompleta)) {
        return;
    }
    foreach (glob($pastaCompleta . '/*') as $arquivo) {
        @unlink($arquivo);
    }
    @rmdir($pastaCompleta);
}
