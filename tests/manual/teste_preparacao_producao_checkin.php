<?php

/**
 * Script de PREPARACAO (nao dispara chamada real ao Talent) para o teste
 * controlado em Producao da demanda integracao-talent-portaria-checkin.
 *
 * Objetivo: usar o totem REAL id_totem=1 (RECEPCAO-01, ja vinculado a Maua I
 * pela migration 008) e um atendimento de RECEBIMENTO criado especificamente
 * para este teste, para:
 *  - montar o payload real (App\Rn\TalentRn::montarPayload via Reflection);
 *  - gerar os PDFs reais de anexo (CNH, CRLV, Nota Fiscal 01);
 *  - confirmar que iniciarEnvioTalent() adquire o lock (CAS) corretamente;
 *  - reverter o lock ao final (volta para NAO_ENVIADO) sem jamais chamar
 *    TalentClient::checkin().
 *
 * NUNCA importa/usa App\Rn\TalentClient::checkin() nem qualquer coisa que
 * abra socket/cURL para a URL real do Talent. TalentClient e instanciado
 * apenas para satisfazer o construtor de TalentRn (mesmo padrao dos demais
 * testes manuais desta demanda).
 *
 * Uso: php tests/manual/teste_preparacao_producao_checkin.php
 *
 * O atendimento de teste criado NAO e apagado ao final (dado local de dev,
 * fica registrado no relatorio para exclusao posterior sob pedido).
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_fixtures_talent.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\EmpresaDao;
use App\Dao\FilaEnvioDao;
use App\Rn\TalentRn;
use App\Rn\TalentClient;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

function chamarMontarPayload(TalentRn $talentRn, array $atendimento, array $notas, array $empresa): array
{
    $reflexao = new ReflectionMethod(TalentRn::class, 'montarPayload');
    $reflexao->setAccessible(true);
    return $reflexao->invoke($talentRn, $atendimento, $notas, $empresa);
}

function mascararPlaca(string $placa): string
{
    $placa = strtoupper($placa);
    $len = strlen($placa);
    if ($len <= 2) {
        return str_repeat('*', $len);
    }
    return str_repeat('*', $len - 2) . substr($placa, -2);
}

$IDTOTEM_REAL = 1;

$totemDao = new \App\Dao\TotemDao($pdo);
$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);
$empresaDao = new EmpresaDao($pdo);
// TalentClient instanciado so para satisfazer o construtor de TalentRn --
// NUNCA usado (checkin() jamais e chamado neste script).
$talentRn = new TalentRn(new TalentClient($_ENV['TALENT_API_URL'] ?? '', $_ENV['TALENT_API_KEY'] ?? ''), new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);

echo "=== 1. Confirmacao do totem real (id_totem={$IDTOTEM_REAL}) ===\n";
$totem = $pdo->prepare('SELECT id_totem, codigo, nome, id_empresa, ativo FROM tb_totem WHERE id_totem = :id');
$totem->execute(['id' => $IDTOTEM_REAL]);
$totemRow = $totem->fetch();
if ($totemRow === false) {
    echo "FALHA: id_totem={$IDTOTEM_REAL} nao existe no banco de dev local ({$_ENV['DB_NAME']}). Abortando.\n";
    exit(1);
}
echo "OK: id_totem={$totemRow['id_totem']} codigo={$totemRow['codigo']} id_empresa={$totemRow['id_empresa']} ativo={$totemRow['ativo']}\n";

if ($totemRow['id_empresa'] === null) {
    echo "FALHA: totem sem id_empresa vinculado. Abortando.\n";
    exit(1);
}
$empresa = $empresaDao->buscarPorId((int) $totemRow['id_empresa']);
if ($empresa === null) {
    echo "FALHA: empresa vinculada nao encontrada. Abortando.\n";
    exit(1);
}
echo "OK: empresa vinculada = {$empresa['nome']} / cnpj={$empresa['cnpj']}\n\n";

echo "=== 2. Criacao do atendimento de teste (Recebimento) ===\n";
$placaTeste = 'TLT9001';
$idAtendimento = $atendimentoDao->criar($IDTOTEM_REAL, 'recebimento', $placaTeste);
echo "Criado id_atendimento={$idAtendimento} (INSERT tb_atendimento via AtendimentoDao::criar, id_totem={$IDTOTEM_REAL}, tipo=recebimento, placa={$placaTeste})\n";

$pastaRelativa = 'teste_prep_producao_' . bin2hex(random_bytes(4));
$atendimentoDao->definirPasta($idAtendimento, $pastaRelativa);
$pastaCompleta = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pastaRelativa;
mkdir($pastaCompleta, 0750, true);

talentCriarJpegValido($pastaCompleta . '/cnh_frente.jpg');
talentCriarJpegValido($pastaCompleta . '/cnh_verso.jpg');
talentCriarJpegValido($pastaCompleta . '/crlv.jpg');
talentCriarJpegValido($pastaCompleta . '/nota_01.jpg');

$clienteCnpjTeste = '11222333000181';
$atendimentoDao->salvarCliente($idAtendimento, 'CLIENTE TESTE PREP PRODUCAO LTDA', $clienteCnpjTeste);
$atendimentoDao->atualizarValidacaoCnh($idAtendimento, 'MOTORISTA TESTE PREP', '11144477735', '2030-01-01', 'MANUAL', 'PENDENTE_REVISAO');
$atendimentoDao->atualizarValidacaoCrlv($idAtendimento, $placaTeste, 2025, 'SP', '12345678', 'CAMINHAO', 'MANUAL', 'PENDENTE_REVISAO');
$atendimentoDao->atualizarEtapa($idAtendimento, 'rec_confirmacao');
$idNota = $notaDao->inserir($idAtendimento, 1, 'nota_01.jpg', '35' . str_repeat('0', 42), $clienteCnpjTeste, true);
// numero_nota (demanda talent-doctos-finalizacao-checkin, 2026-09-14) --
// obrigatorio para doctos[] (tipo NOTA_FISCAL) nao lancar excecao ao montar
// o payload no passo 4 abaixo.
$notaDao->atualizarNumero($idNota, '123456', 'MANUAL');

echo "Documentos gravados: CNH (MANUAL, aprovada), CRLV (MANUAL, aprovado, UF=SP), 1 nota fiscal (id_nota={$idNota})\n";
echo "Arquivos criados em disco: {$pastaCompleta}/cnh_frente.jpg, cnh_verso.jpg, crlv.jpg, nota_01.jpg\n\n";

$atendimento = $atendimentoDao->buscarPorId($idAtendimento);

echo "=== 3. Validacao de aprovacao de documentos (DocumentoRn) ===\n";
$documentoRn = new \App\Rn\DocumentoRn(new \App\Dao\VioCacheDao($pdo), $atendimentoDao);
$cnhOk = $documentoRn->cnhAprovada($atendimento);
$crlvOk = $documentoRn->crlvAprovado($atendimento);
echo 'cnhAprovada(): ' . ($cnhOk ? 'true' : 'false') . "\n";
echo 'crlvAprovado(): ' . ($crlvOk ? 'true' : 'false') . "\n\n";
if (!$cnhOk || !$crlvOk) {
    echo "FALHA: documentos nao aprovados, abortando antes de montar payload.\n";
    exit(1);
}

echo "=== 4. Montagem do payload + PDFs (sem chamar TalentClient::checkin) ===\n";
$notas = $notaDao->listarPorAtendimento($idAtendimento);
$payload = chamarMontarPayload($talentRn, $atendimento, $notas, $empresa);

echo "Payload montado com sucesso. Chaves de topo: " . implode(', ', array_keys($payload)) . "\n";
echo 'tipoEmbDesemb: ' . $payload['tipoEmbDesemb'] . "\n";
echo 'cnpjArmazem: ' . $payload['cnpjArmazem'] . " (deve == cnpj da empresa vinculada ao totem)\n";
echo 'cnpjDepositante: ' . $payload['cnpjDepositante'] . "\n";
echo 'veiculo.placa (mascarada): ' . mascararPlaca($payload['veiculo']['placa']) . '  uf: ' . $payload['veiculo']['uf'] . "\n";
echo 'Qtde de anexos: ' . count($payload['anexos']) . "\n";
foreach ($payload['anexos'] as $i => $anexo) {
    $bytesPdf = base64_decode($anexo['anexoBase64'], true);
    $assinaturaOk = $bytesPdf !== false && str_starts_with($bytesPdf, '%PDF-');
    echo "  Anexo #{$i}: descricao=\"{$anexo['descricao']}\" tamanho_base64=" . strlen($anexo['anexoBase64']) . " bytes_pdf=" . ($bytesPdf === false ? 'N/A' : strlen($bytesPdf)) . " assinatura_%PDF-=" . ($assinaturaOk ? 'sim' : 'nao') . "\n";
}
echo "\n";

echo "=== 5. Teste do lock de idempotencia (iniciarEnvioTalent) ===\n";
echo 'talent_checkin_status ANTES: ' . $atendimento['talent_checkin_status'] . "\n";
$tentativaTeste = bin2hex(random_bytes(16));
$adquiriu = $atendimentoDao->iniciarEnvioTalent($idAtendimento, $tentativaTeste);
echo 'iniciarEnvioTalent() retornou: ' . ($adquiriu ? 'true (lock adquirido)' : 'false (NAO adquiriu)') . "\n";

$atualizado = $atendimentoDao->buscarPorId($idAtendimento);
echo 'talent_checkin_status APOS lock: ' . $atualizado['talent_checkin_status'] . "\n";

// Reverte explicitamente o lock -- nenhum envio real ocorreu, entao volta
// para NAO_ENVIADO (nao ENVIADO/ERRO_REPROCESSAVEL/ENVIO_INDETERMINADO, que
// implicariam uma tentativa real de envio que nao aconteceu).
$reverte = $pdo->prepare("UPDATE tb_atendimento SET talent_checkin_status = 'NAO_ENVIADO', talent_tentativa_id = NULL, talent_status_iniciado_em = NULL WHERE id_atendimento = :id");
$reverte->execute(['id' => $idAtendimento]);
$final = $atendimentoDao->buscarPorId($idAtendimento);
echo 'talent_checkin_status REVERTIDO para: ' . $final['talent_checkin_status'] . " (estado final, atendimento NAO travado)\n\n";

echo "=== 6. Limpeza de arquivos em disco (mantendo o registro no banco para exclusao posterior sob pedido) ===\n";
talentLimparPasta($pastaCompleta);
echo "Pasta {$pastaCompleta} removida (arquivos JPEG de teste apagados). Registro em tb_atendimento/tb_atendimento_nota PERMANECE (id_atendimento={$idAtendimento}), aguardando pedido de exclusao.\n\n";

echo "=== RESUMO SEGURO (sem dado sensivel) ===\n";
echo "id_atendimento: {$idAtendimento}\n";
echo "fluxo: recebimento\n";
echo 'placa mascarada: ' . mascararPlaca($placaTeste) . "\n";
echo "empresa do totem: {$empresa['nome']} / cnpj {$empresa['cnpj']}\n";
echo "anexos: CNH (2 paginas: frente+verso), CRLV (1 pagina), Nota Fiscal 01 (1 pagina) -- 3 PDFs no total\n";
echo "talent_checkin_status final: {$final['talent_checkin_status']}\n";
echo "lock adquirido com sucesso: " . ($adquiriu ? 'sim' : 'nao') . "\n";
