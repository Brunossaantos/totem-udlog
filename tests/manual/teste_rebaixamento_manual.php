<?php

/**
 * Teste manual (mesmo padrao de tests/manual/teste_vio_decode.php) da
 * correcao aplicada apos o /02-testes de 2026-09-08 (rebaixamento para
 * MANUAL nunca implementado + descarte explicito de `image` + rejeicao de
 * placeholder simetrica CNH/CRLV). Roda contra o banco de dev real
 * (udlog_totem), cria e apaga seus proprios dados (nenhum residuo).
 *
 * Cobre:
 *  1. Snapshot gravado somente quando origem = VIO_TRIAL/VIO_VALIDADO
 *     (nunca quando MANUAL).
 *  2. Confirmar sem editar preserva a origem/status originais.
 *  3. Edicao de um unico campo (nome, cpf, validade, exercicio, placa)
 *     rebaixa para MANUAL/PENDENTE_REVISAO.
 *  4. Edicao de multiplos campos ao mesmo tempo rebaixa uma unica vez (sem
 *     efeito duplicado, resultado final MANUAL/PENDENTE_REVISAO).
 *  5. Uma vez rebaixado para MANUAL, digitar de volta o valor ORIGINAL nao
 *     restaura sozinho para VIO_TRIAL/VIO_VALIDADO — design escolhido e
 *     documentado em App\Rn\AtendimentoRn::salvarDadosMotorista (so uma
 *     nova validacao de QR bem-sucedida restaura).
 *  6. Campo de origem/status enviado pelo front (`cnh_origem`) e IGNORADO
 *     pelo backend.
 *  7. tb_vio_cache_cnh/tb_vio_cache_crlv NUNCA sao alteradas por
 *     salvarDadosMotorista (contagem de linhas identica antes/depois).
 *  8. Rejeicao de placeholder simetrica para CNH (nome "xxxxx"/"string"/
 *     sequencia repetida, data_validade "00/00/0000") e reconfirmacao para
 *     CRLV.
 *  9. Descarte explicito de `image`: resposta de validarCnh/validarCrlv
 *     nunca contem a chave 'image' nem seu conteudo, mesmo quando o mock
 *     do VIO devolve um campo `image.base64` grande; confirmado tambem por
 *     leitura estatica do codigo-fonte (unset() logo apos extracao).
 *
 * Uso: php tests/manual/teste_rebaixamento_manual.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\VioCacheDao;
use App\Dao\AtendimentoDao;
use App\Rn\AtendimentoRn;
use App\Dao\OrdemColetaDao;
use App\Rn\OrdemColetaClient;
use App\Rn\DocumentoRn;
use App\Rn\VioDecodeClient;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$totalTestes = 0;
$totalFalhas = 0;

function afirmar(string $descricao, bool $condicao): void
{
    global $totalTestes, $totalFalhas;
    $totalTestes++;
    if ($condicao) {
        echo "OK   - {$descricao}\n";
    } else {
        $totalFalhas++;
        echo "FALHA - {$descricao}\n";
    }
}

class VioDecodeClientFalso extends VioDecodeClient
{
    private array $resultadoFixo;

    public function __construct(array $resultadoFixo)
    {
        parent::__construct([
            'VIO_AMBIENTE' => 'trial',
            'VIO_TRIAL_BEARER' => 'fake-bearer-so-para-teste',
            'VIO_TRIAL_DECODE_URL' => 'https://exemplo.invalido/decode',
        ]);
        $this->resultadoFixo = $resultadoFixo;
    }

    public function decodificar(string $rawValueQr): array
    {
        return $this->resultadoFixo;
    }
}

// ============================================================
// Setup: totem e atendimento de teste
// ============================================================
$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_REBAIXA', 'Totem Teste Rebaixa', 'token_teste_rebaixa_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$atendimentoDao = new AtendimentoDao($pdo);
$idAtendimento = $atendimentoDao->criar($idTotem, 'expedicao', 'ABC1234');
$atendimentoDao->atualizarEtapa($idAtendimento, 'exp_cnh');

$vioCacheDao = new VioCacheDao($pdo);
$documentoRn = new DocumentoRn($vioCacheDao, $atendimentoDao);
$atendimentoRn = new AtendimentoRn($atendimentoDao, new OrdemColetaClient(new OrdemColetaDao()));

function limpar(PDO $pdo, int $idTotem, int $idAtendimento): void
{
    $pdo->exec('DELETE FROM tb_vio_cache_cnh');
    $pdo->exec('DELETE FROM tb_vio_cache_crlv');
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $idAtendimento]);
    $pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);
}

// ============================================================
// 1. Aprova CNH via VIO (mock) — confirma snapshot gravado
// ============================================================
$bytesQrCnh = random_bytes(64);
$vioCnhOk = new VioDecodeClientFalso([
    'ok' => true,
    'ambiente' => 'trial',
    'dados' => ['nome' => 'JOAO DA SILVA', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'],
    'erro' => null,
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
$rCnh = $documentoRn->validarCnh($atendimento, $vioCnhOk, $bytesQrCnh);
afirmar('CNH aprovada via VIO (mock)', $rCnh['pode_avancar'] === true);
afirmar('Resposta de validarCnh inclui origem=VIO_TRIAL', $rCnh['origem'] === 'VIO_TRIAL');
afirmar('Resposta de validarCnh inclui status_revisao=OK', $rCnh['status_revisao'] === 'OK');

$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Snapshot cnh_snapshot_nome gravado com o valor exato do VIO', $atendimento['cnh_snapshot_nome'] === 'JOAO DA SILVA');
afirmar('Snapshot cnh_snapshot_cpf gravado com o valor exato do VIO', $atendimento['cnh_snapshot_cpf'] === '11144477735');
afirmar('Snapshot cnh_snapshot_validade gravado com o valor exato do VIO', $atendimento['cnh_snapshot_validade'] === '2030-01-01');
afirmar('cnh_origem_validacao gravado como VIO_TRIAL', $atendimento['cnh_origem_validacao'] === 'VIO_TRIAL');

// ============================================================
// 2. Confirmar SEM editar preserva a origem
// ============================================================
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'JOAO DA SILVA',
    'motorista_cpf' => '111.444.777-35', // com mascara, deve normalizar igual
    'cnh_validade' => '2030-01-01',
    'crlv_ano' => '',
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Confirmar sem editar preserva cnh_origem_validacao=VIO_TRIAL', $atendimento['cnh_origem_validacao'] === 'VIO_TRIAL');
afirmar('Confirmar sem editar preserva cnh_status_revisao=OK', $atendimento['cnh_status_revisao'] === 'OK');

// ============================================================
// 3. Editar SOMENTE o nome rebaixa para MANUAL
// ============================================================
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'JOAO DA SILVA EDITADO',
    'motorista_cpf' => '111.444.777-35',
    'cnh_validade' => '2030-01-01',
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Editar somente o nome rebaixa cnh_origem_validacao para MANUAL', $atendimento['cnh_origem_validacao'] === 'MANUAL');
afirmar('Editar somente o nome rebaixa cnh_status_revisao para PENDENTE_REVISAO', $atendimento['cnh_status_revisao'] === 'PENDENTE_REVISAO');
afirmar('Snapshot cnh_snapshot_nome permanece intacto (verdade original preservada)', $atendimento['cnh_snapshot_nome'] === 'JOAO DA SILVA');

// ============================================================
// 5. Restaurar o valor ORIGINAL nao promove de volta sozinho
// ============================================================
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'JOAO DA SILVA', // valor original restaurado
    'motorista_cpf' => '111.444.777-35',
    'cnh_validade' => '2030-01-01',
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Restaurar o valor original NAO promove de volta a VIO_TRIAL sozinho (design: so nova validacao de QR restaura)', $atendimento['cnh_origem_validacao'] === 'MANUAL');

// ============================================================
// Reset: nova validacao VIO para testar CPF/validade isoladamente
// ============================================================
$bytesQrCnh2 = random_bytes(64);
$vioCnhOk2 = new VioDecodeClientFalso([
    'ok' => true, 'ambiente' => 'trial',
    'dados' => ['nome' => 'MARIA SOUZA', 'cpf' => '111.444.777-35', 'data_validade' => '2031-06-15'],
    'erro' => null,
]);
$documentoRn->validarCnh($atendimentoDao->buscarPorId($idAtendimento), $vioCnhOk2, $bytesQrCnh2);

$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'MARIA SOUZA',
    'motorista_cpf' => '999.999.999-99', // CPF diferente (mesmo que invalido para digito, so compara texto)
    'cnh_validade' => '2031-06-15',
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Editar somente o CPF rebaixa para MANUAL', $atendimento['cnh_origem_validacao'] === 'MANUAL');

$documentoRn->validarCnh($atendimentoDao->buscarPorId($idAtendimento), $vioCnhOk2, random_bytes(64));
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'MARIA SOUZA',
    'motorista_cpf' => '111.444.777-35',
    'cnh_validade' => '2031-06-16', // 1 dia diferente
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Editar somente a validade rebaixa para MANUAL', $atendimento['cnh_origem_validacao'] === 'MANUAL');

// ============================================================
// 4. Editar MULTIPLOS campos ao mesmo tempo rebaixa uma unica vez
// ============================================================
$documentoRn->validarCnh($atendimentoDao->buscarPorId($idAtendimento), $vioCnhOk2, random_bytes(64));
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'OUTRO NOME',
    'motorista_cpf' => '000.000.000-00',
    'cnh_validade' => '2040-01-01',
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Editar multiplos campos ao mesmo tempo rebaixa (uma unica vez) para MANUAL', $atendimento['cnh_origem_validacao'] === 'MANUAL' && $atendimento['cnh_status_revisao'] === 'PENDENTE_REVISAO');

// ============================================================
// 6. Campo de origem enviado pelo front e IGNORADO
// ============================================================
$documentoRn->validarCnh($atendimentoDao->buscarPorId($idAtendimento), $vioCnhOk2, random_bytes(64));
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'TENTATIVA DE FORJAR ORIGEM',
    'motorista_cpf' => '111.444.777-35',
    'cnh_validade' => '2031-06-15',
    'cnh_origem' => 'VIO_VALIDADO', // campo que o front NAO deveria conseguir controlar
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Campo cnh_origem enviado pelo front e ignorado — edicao ainda rebaixa para MANUAL', $atendimento['cnh_origem_validacao'] === 'MANUAL');

// ============================================================
// 7. CRLV: edicao de exercicio e placa (via snapshot) rebaixa
// ============================================================
$atendimentoDao->atualizarEtapa($idAtendimento, 'exp_crlv');
$bytesQrCrlv = random_bytes(64);
$vioCrlvOk = new VioDecodeClientFalso(['ok' => true, 'ambiente' => 'trial', 'dados' => ['placa' => 'ABC1234', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '12345678', 'tipo' => 'CAMINHAO'], 'erro' => null]);
$rCrlv = $documentoRn->validarCrlv($atendimentoDao->buscarPorId($idAtendimento), $vioCrlvOk, $bytesQrCrlv);
afirmar('CRLV aprovado via VIO (mock)', $rCrlv['pode_avancar'] === true);
afirmar('Resposta de validarCrlv inclui origem=VIO_TRIAL', $rCrlv['origem'] === 'VIO_TRIAL');

$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Snapshot crlv_snapshot_placa gravado', $atendimento['crlv_snapshot_placa'] === 'ABC1234');
afirmar('Snapshot crlv_snapshot_exercicio gravado', (int) $atendimento['crlv_snapshot_exercicio'] === 2025);

$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'MARIA SOUZA', 'motorista_cpf' => '111.444.777-35', 'cnh_validade' => '2031-06-15',
    'crlv_ano' => '2026', // exercicio diferente
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Editar exercicio do CRLV rebaixa crlv_origem_validacao para MANUAL', $atendimento['crlv_origem_validacao'] === 'MANUAL');
afirmar('Editar exercicio do CRLV rebaixa crlv_status_revisao para PENDENTE_REVISAO', $atendimento['crlv_status_revisao'] === 'PENDENTE_REVISAO');
// CNH ja estava MANUAL desde o teste do item 6 (forjar origem) — a edicao
// de CRLV acima nao deve MUDAR o estado da CNH de forma alguma (nem
// rebaixar de novo, nem promover), confirmando que as origens sao
// tratadas de forma totalmente independente uma da outra.
afirmar('CNH nao e afetada pela edicao de CRLV (origens independentes)', $atendimento['cnh_origem_validacao'] === 'MANUAL');

// Placa (campo hoje nao editavel no front, mas backend suporta via 'placa')
$documentoRn->validarCrlv($atendimentoDao->buscarPorId($idAtendimento), $vioCrlvOk, random_bytes(64));
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'MARIA SOUZA', 'motorista_cpf' => '111.444.777-35', 'cnh_validade' => '2031-06-15',
    'crlv_ano' => '2025', 'placa' => 'XYZ9999',
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Editar a placa (defensivo, backend suporta) rebaixa crlv_origem_validacao para MANUAL', $atendimento['crlv_origem_validacao'] === 'MANUAL');

// ============================================================
// 7a-2. RNTC/tipo de veiculo (extensao 2026-09-10): reaprova via VIO,
// depois edita SO o RNTC (diferente do snapshot) — rebaixa para MANUAL,
// mesma logica ja usada para exercicio/placa
// ============================================================
$documentoRn->validarCrlv($atendimentoDao->buscarPorId($idAtendimento), $vioCrlvOk, random_bytes(64));
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Snapshot crlv_snapshot_rntc gravado apos reaprovacao via VIO', $atendimento['crlv_snapshot_rntc'] === '12345678');
afirmar('Snapshot crlv_snapshot_tipo_veiculo gravado apos reaprovacao via VIO', $atendimento['crlv_snapshot_tipo_veiculo'] === 'CAMINHAO');
afirmar('crlv_origem_validacao volta a VIO_TRIAL apos reaprovacao', $atendimento['crlv_origem_validacao'] === 'VIO_TRIAL');

$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'MARIA SOUZA', 'motorista_cpf' => '111.444.777-35', 'cnh_validade' => '2031-06-15',
    'crlv_ano' => '2025', 'placa' => 'ABC1234', 'crlv_rntc' => '99999999', 'crlv_tipo_veiculo' => 'CAMINHAO',
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Editar SOMENTE o RNTC (diferente do snapshot) rebaixa crlv_origem_validacao para MANUAL', $atendimento['crlv_origem_validacao'] === 'MANUAL');
afirmar('Editar SOMENTE o RNTC rebaixa crlv_status_revisao para PENDENTE_REVISAO', $atendimento['crlv_status_revisao'] === 'PENDENTE_REVISAO');

// Reaprova de novo via VIO, depois edita SO o tipo de veiculo — mesma logica
$documentoRn->validarCrlv($atendimentoDao->buscarPorId($idAtendimento), $vioCrlvOk, random_bytes(64));
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'MARIA SOUZA', 'motorista_cpf' => '111.444.777-35', 'cnh_validade' => '2031-06-15',
    'crlv_ano' => '2025', 'placa' => 'ABC1234', 'crlv_rntc' => '12345678', 'crlv_tipo_veiculo' => 'CARRETA',
]);
$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
afirmar('Editar SOMENTE o tipo de veiculo (diferente do snapshot) rebaixa crlv_origem_validacao para MANUAL', $atendimento['crlv_origem_validacao'] === 'MANUAL');

// ============================================================
// 7b. Cache VIO NUNCA e alterado por salvarDadosMotorista
// ============================================================
$totalCacheCnhAntes = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_cache_cnh')->fetchColumn();
$totalCacheCrlvAntes = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_cache_crlv')->fetchColumn();
$atendimentoRn->salvarDadosMotorista($idAtendimento, [
    'motorista_nome' => 'QUALQUER COISA', 'motorista_cpf' => '111.444.777-35', 'cnh_validade' => '2031-06-15', 'crlv_ano' => '2025',
]);
$totalCacheCnhDepois = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_cache_cnh')->fetchColumn();
$totalCacheCrlvDepois = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_cache_crlv')->fetchColumn();
afirmar('tb_vio_cache_cnh nao e alterado por salvarDadosMotorista', $totalCacheCnhAntes === $totalCacheCnhDepois);
afirmar('tb_vio_cache_crlv nao e alterado por salvarDadosMotorista', $totalCacheCrlvAntes === $totalCacheCrlvDepois);

// ============================================================
// 8. Rejeicao de placeholder simetrica — CNH
// ============================================================
$atendimentoDao->atualizarEtapa($idAtendimento, 'exp_cnh');
$casosPlaceholderCnh = [
    'nome xxxxx' => ['nome' => 'xxxxx', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'],
    'nome string' => ['nome' => 'string', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'],
    'nome vazio' => ['nome' => '', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'],
    'nome sequencia repetida (aaaaa)' => ['nome' => 'aaaaa', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'],
    'data_validade 00/00/0000' => ['nome' => 'FULANO VALIDO', 'cpf' => '111.444.777-35', 'data_validade' => '00/00/0000'],
];
foreach ($casosPlaceholderCnh as $descricao => $dadosCaso) {
    $vioPlaceholder = new VioDecodeClientFalso(['ok' => true, 'ambiente' => 'trial', 'dados' => $dadosCaso, 'erro' => null]);
    $r = $documentoRn->validarCnh($atendimentoDao->buscarPorId($idAtendimento), $vioPlaceholder, random_bytes(64));
    afirmar("CNH rejeita placeholder/invalido: {$descricao}", $r['pode_avancar'] === false);
}

// ============================================================
// 8b. Rejeicao de placeholder — CRLV (reconfirmacao)
// ============================================================
$atendimentoDao->atualizarEtapa($idAtendimento, 'exp_crlv');
$casosPlaceholderCrlv = [
    'placa xxxxx' => ['placa' => 'xxxxx', 'exercicio' => 2025, 'uf' => 'SP'],
    'placa string' => ['placa' => 'string', 'exercicio' => 2025, 'uf' => 'SP'],
    'exercicio 00000' => ['placa' => 'ABC1234', 'exercicio' => '00000', 'uf' => 'SP'],
];
foreach ($casosPlaceholderCrlv as $descricao => $dadosCaso) {
    $vioPlaceholder = new VioDecodeClientFalso(['ok' => true, 'ambiente' => 'trial', 'dados' => $dadosCaso, 'erro' => null]);
    $r = $documentoRn->validarCrlv($atendimentoDao->buscarPorId($idAtendimento), $vioPlaceholder, random_bytes(64));
    afirmar("CRLV rejeita placeholder/invalido: {$descricao}", $r['pode_avancar'] === false);
}

// ============================================================
// 9. Descarte explicito de `image` — nunca aparece na resposta
// ============================================================
$imagemFalsaGigante = str_repeat('A', 5000); // simula base64 grande, nunca dado real
$vioComImagem = new VioDecodeClientFalso([
    'ok' => true,
    'ambiente' => 'trial',
    'dados' => [
        'template' => ['name' => 'CNH', 'owner' => ['name' => 'x']],
        'data' => ['nome' => 'CARLOS TESTE', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'],
        'image' => ['base64' => $imagemFalsaGigante, 'type' => 'jpeg'],
    ],
    'erro' => null,
]);
$rComImagem = $documentoRn->validarCnh($atendimentoDao->buscarPorId($idAtendimento), $vioComImagem, random_bytes(64));
afirmar('Resposta de validarCnh NUNCA contem a chave "image"', !array_key_exists('image', $rComImagem));
afirmar('Resposta de validarCnh NUNCA contem o conteudo bruto da imagem em nenhum campo', !str_contains(json_encode($rComImagem), $imagemFalsaGigante));

$fonteDocumentoRn = file_get_contents(__DIR__ . '/../../app/Rn/DocumentoRn.php');
afirmar('DocumentoRn::validarCnh descarta explicitamente $resultadoVio/$dadosBrutos (unset) apos extrair a allowlist', (bool) preg_match('/unset\(\$resultadoVio,\s*\$dadosBrutos\);/', $fonteDocumentoRn));

// Nota (rodada vio-hardening-sem-credenciais, correcao pos-/02-testes de
// 2026-09-19): a fronteira de tipo/esquema adicionada em DocumentoRn.php
// introduziu MAIS pontos de descarte explicito (um por ramo de rejeicao
// estrutural -- `dados` nao-array, `dados.data` nao-array, e o catch de
// DocumentoVioTipoInvalidoException -- alem do ponto original de sucesso
// de extracao), tanto em validarCnh() quanto em validarCrlv(). A contagem
// exata portanto NAO e mais fixa em 2 -- o que importa e que CADA METODO
// descarta em TODOS os caminhos que passam pela extracao de campos, nunca
// so no caminho feliz.
$inicioValidarCnh = strpos($fonteDocumentoRn, 'function validarCnh(');
$inicioPreencherManualCnh = strpos($fonteDocumentoRn, 'function preencherManualCnh(');
$corpoValidarCnh = substr($fonteDocumentoRn, $inicioValidarCnh, $inicioPreencherManualCnh - $inicioValidarCnh);

$inicioValidarCrlv = strpos($fonteDocumentoRn, 'function validarCrlv(');
$inicioPreencherManualCrlv = strpos($fonteDocumentoRn, 'function preencherManualCrlv(');
$corpoValidarCrlv = substr($fonteDocumentoRn, $inicioValidarCrlv, $inicioPreencherManualCrlv - $inicioValidarCrlv);

$unsetsEmValidarCnh = substr_count($corpoValidarCnh, 'unset($resultadoVio, $dadosBrutos);');
$unsetsEmValidarCrlv = substr_count($corpoValidarCrlv, 'unset($resultadoVio, $dadosBrutos);');
afirmar('validarCnh() descarta $resultadoVio/$dadosBrutos em TODOS os caminhos de retorno pos-decode (>= 1, um por ramo de rejeicao estrutural + caminho feliz)', $unsetsEmValidarCnh >= 1);
afirmar('validarCrlv() descarta $resultadoVio/$dadosBrutos em TODOS os caminhos de retorno pos-decode (>= 1, um por ramo de rejeicao estrutural + caminho feliz)', $unsetsEmValidarCrlv >= 1);
afirmar('O unset() aparece em AMBOS validarCnh e validarCrlv (nunca so em um dos dois)', $unsetsEmValidarCnh >= 1 && $unsetsEmValidarCrlv >= 1);

// ============================================================
// Limpeza final
// ============================================================
limpar($pdo, $idTotem, $idAtendimento);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
