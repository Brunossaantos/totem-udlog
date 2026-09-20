<?php

/**
 * Rodada curta de correcao pos-`/02-testes` independente da demanda
 * `vio-hardening-sem-credenciais` (2026-09-19) -- cobre os 2 achados
 * (BLOQUEANTE + MODERADO) encontrados por 2 revisores convergentes
 * (backend-especialista/security-especialista) sobre `DocumentoRn.php`:
 * cast `(string)` sem `is_string()` previo permitindo que um array
 * aninhado virasse a string literal "Array" e fosse aprovado/persistido
 * como dado valido, e `Error`/`TypeError` reais escapando de
 * `DocumentoRn` para campos internos com tipo estruturalmente invalido
 * (stdClass/array aninhado).
 *
 * Roda contra o banco de dev real (udlog_totem, mesmo padrao ja aceito
 * pelas 3 rodadas anteriores de `/02-testes` desta demanda para
 * `teste_vio_decode_robustez.php`) -- cria e apaga seus proprios dados,
 * nenhum residuo. Usa `error_log` redirecionado para um arquivo temporario
 * isolado (nunca o log real do servidor) para confirmar que nenhum
 * warning/stack trace/dado do payload vaza, e que o log categorizado
 * (documento/campo/tipo PHP, NUNCA o valor) funciona como projetado.
 *
 * `display_errors`/`error_reporting` forcados a maximo explicitamente
 * (E_ALL) para garantir que nenhum warning de "Array to string
 * conversion" escapa mesmo nessas condicoes.
 *
 * Marcadores sinteticos MARCADOR_QA02_* usados como prova negativa --
 * NUNCA devem aparecer na resposta HTTP simulada (array de retorno),
 * no log, no stdout/stderr, ou em qualquer coluna persistida no banco.
 *
 * Uso: php tests/manual/teste_vio_decode_matriz_tipos_campos.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\VioCacheDao;
use App\Dao\AtendimentoDao;
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

class VioDecodeClientFalsoMatriz extends VioDecodeClient
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
// Redirecionamento de error_log para arquivo isolado desta suite (nunca o
// log real do servidor) -- restaurado ao final.
// ============================================================
$logOriginalDestino = ini_get('error_log');
$logOriginalUsaErrorLog = ini_get('log_errors');
$logIsoladoPath = sys_get_temp_dir() . '/teste_vio_matriz_tipos_' . bin2hex(random_bytes(4)) . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $logIsoladoPath);

// ============================================================
// Marcadores sinteticos -- NUNCA reais, nunca reutilizados fora deste
// arquivo de teste.
// ============================================================
const MARCADOR_ARRAY = 'MARCADOR_QA02_ARRAY_e7f3a9';
const MARCADOR_STDCLASS = 'MARCADOR_QA02_STDCLASS_b21c40';

function contemMarcador(string $texto): bool
{
    return stripos($texto, 'MARCADOR_QA02') !== false;
}

$pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('TESTE_VIO_MATRIZ_TIPOS', 'Totem Teste VIO Matriz Tipos', 'token_teste_vio_matriz_" . bin2hex(random_bytes(8)) . "', 1)");
$idTotem = (int) $pdo->lastInsertId();

$atendimentoDao = new AtendimentoDao($pdo);
$vioCacheDao = new VioCacheDao($pdo);
$documentoRn = new DocumentoRn($vioCacheDao, $atendimentoDao);

$idsAtendimentoCriados = [];

function novoAtendimento(AtendimentoDao $atendimentoDao, int $idTotem, string $etapa, string $placa, array &$idsAtendimentoCriados): array
{
    $id = $atendimentoDao->criar($idTotem, 'expedicao', $placa);
    $atendimentoDao->atualizarEtapa($id, $etapa);
    $idsAtendimentoCriados[] = $id;
    return $atendimentoDao->buscarPorId($id);
}

/**
 * Executa uma chamada validarCnh()/validarCrlv() capturando qualquer saida
 * inesperada em stdout (ob_*) -- warning acidental de "Array to string
 * conversion" apareceria aqui se a correcao nao estivesse funcionando.
 */
function executarCapturandoStdout(callable $fn): array
{
    ob_start();
    $excecao = null;
    $resultado = null;
    try {
        $resultado = $fn();
    } catch (\Throwable $e) {
        $excecao = $e;
    }
    $stdoutCapturado = ob_get_clean();

    return [$resultado, $excecao, $stdoutCapturado];
}

$dadosCnhValidoBase = ['nome' => 'FULANO VALIDO MATRIZ', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'];
$dadosCrlvValidoBase = ['placa' => 'MTZ1111', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '12345678', 'tipo' => 'CAMINHAO'];

// ============================================================
// Matriz de tipos estruturalmente incompativeis -- devem ser REJEITADOS
// (resposta VIO inteira invalidada, sem excecao, sem persistencia parcial,
// sem marcar origem VIO_TRIAL/VIO_VALIDADO).
// ============================================================
$valoresEstruturalmenteInvalidos = [
    'array vazio' => [],
    'array aninhado com marcador' => [MARCADOR_ARRAY => 'valor_' . MARCADOR_ARRAY],
    'stdClass' => (object) [MARCADOR_STDCLASS => 'valor_' . MARCADOR_STDCLASS],
    'inteiro' => 424242,
    'float' => 42.42,
    'booleano true' => true,
    'booleano false' => false,
];

// ============================================================
// Valores de CONTEUDO (tipo correto: string ou ausencia) -- NAO devem
// disparar a fronteira de tipo; continuam seguindo as regras de CONTEUDO
// ja existentes (regressao).
// ============================================================
$valoresConteudoValido = [
    'string vazia' => '',
    'null' => null,
];

// ---------- CNH: nome, cpf, data_validade ----------
foreach (['nome', 'cpf', 'data_validade'] as $campo) {
    foreach ($valoresEstruturalmenteInvalidos as $rotulo => $valor) {
        $dados = $dadosCnhValidoBase;
        $dados[$campo] = $valor;

        $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'MTZ' . substr(md5($campo . $rotulo), 0, 4), $idsAtendimentoCriados);
        $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);

        [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCnh($at, $vio, random_bytes(16)));

        afirmar("[CNH campo={$campo}] tipo '{$rotulo}': NAO lanca excecao/TypeError/Error", $excecao === null);
        afirmar("[CNH campo={$campo}] tipo '{$rotulo}': stdout vazio (sem warning de conversao vazando)", $stdout === '');

        if ($excecao === null) {
            afirmar("[CNH campo={$campo}] tipo '{$rotulo}': pode_avancar=false", $resultado['pode_avancar'] === false);
            afirmar("[CNH campo={$campo}] tipo '{$rotulo}': motivo e a mensagem generica de estrutura invalida", $resultado['motivo'] === 'Falha ao validar CNH: dados retornados em formato invalido');
            afirmar("[CNH campo={$campo}] tipo '{$rotulo}': origem permanece NAO_VALIDADO (nunca VIO_TRIAL/VIO_VALIDADO)", $resultado['origem'] === 'NAO_VALIDADO');
            afirmar("[CNH campo={$campo}] tipo '{$rotulo}': resposta serializada NUNCA contem marcador sintetico", !contemMarcador(json_encode($resultado)));

            if ($campo === 'nome') {
                afirmar("[CNH campo=nome] tipo '{$rotulo}': resultado nunca e a string literal 'Array'", ($resultado['motivo'] ?? '') !== 'Array');
            }

            $atendimentoRecarregado = $atendimentoDao->buscarPorId($at['id_atendimento']);
            afirmar("[CNH campo={$campo}] tipo '{$rotulo}': motorista_nome NAO persistido como 'Array'/marcador", ($atendimentoRecarregado['motorista_nome'] ?? '') !== 'Array' && !contemMarcador((string) ($atendimentoRecarregado['motorista_nome'] ?? '')));
            afirmar("[CNH campo={$campo}] tipo '{$rotulo}': cnh_origem_validacao permanece NAO_VALIDADO no banco (sem persistencia parcial)", ($atendimentoRecarregado['cnh_origem_validacao'] ?? 'NAO_VALIDADO') === 'NAO_VALIDADO');
        }
    }

    // Conteudo valido (tipo correto: string/null) -- regressao, NAO deve
    // acionar a mensagem de estrutura invalida.
    foreach ($valoresConteudoValido as $rotulo => $valor) {
        $dados = $dadosCnhValidoBase;
        $dados[$campo] = $valor;

        $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'MTC' . substr(md5($campo . $rotulo), 0, 4), $idsAtendimentoCriados);
        $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);

        [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCnh($at, $vio, random_bytes(16)));

        afirmar("[CNH campo={$campo}] conteudo '{$rotulo}': NAO lanca excecao (regressao)", $excecao === null);
        if ($excecao === null) {
            afirmar("[CNH campo={$campo}] conteudo '{$rotulo}': NAO usa a mensagem de estrutura invalida (e rejeicao de CONTEUDO normal)", $resultado['motivo'] !== 'Falha ao validar CNH: dados retornados em formato invalido');
            afirmar("[CNH campo={$campo}] conteudo '{$rotulo}': pode_avancar=false (campo obrigatorio ausente)", $resultado['pode_avancar'] === false);
        }
    }
}

// Regressao explicita de placeholder (CNH) -- ainda deve ser rejeitado
// pela regra de CONTEUDO, nao pela fronteira de tipo.
$atPlaceholderCnh = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'MTZPLC1', $idsAtendimentoCriados);
$vioPlaceholderCnh = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => array_merge($dadosCnhValidoBase, ['nome' => 'xxxxx']), 'erro' => null]);
$rPlaceholderCnh = $documentoRn->validarCnh($atPlaceholderCnh, $vioPlaceholderCnh, random_bytes(16));
afirmar('[CNH regressao] nome placeholder "xxxxx" continua rejeitado (regra de conteudo, nao de tipo)', $rPlaceholderCnh['pode_avancar'] === false && $rPlaceholderCnh['motivo'] !== 'Falha ao validar CNH: dados retornados em formato invalido');

// Controle positivo: caminho feliz de CNH continua 100% funcional.
$atValidoCnh = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'MTZOK01', $idsAtendimentoCriados);
$vioValidoCnh = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dadosCnhValidoBase, 'erro' => null]);
$rValidoCnh = $documentoRn->validarCnh($atValidoCnh, $vioValidoCnh, random_bytes(16));
afirmar('[CNH controle positivo] resposta 100% valida continua sendo aprovada sem alteracao de comportamento', $rValidoCnh['pode_avancar'] === true && $rValidoCnh['origem'] === 'VIO_TRIAL');

// ---------- CRLV: placa, uf, rntrc (extraido como rntc), tipo ----------
foreach (['placa', 'uf', 'rntrc', 'tipo'] as $campo) {
    foreach ($valoresEstruturalmenteInvalidos as $rotulo => $valor) {
        $dados = $dadosCrlvValidoBase;
        $dados[$campo] = $valor;

        $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'MTZ' . substr(md5('crlv' . $campo . $rotulo), 0, 4), $idsAtendimentoCriados);
        $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);

        [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCrlv($at, $vio, random_bytes(16)));

        afirmar("[CRLV campo={$campo}] tipo '{$rotulo}': NAO lanca excecao/TypeError/Error", $excecao === null);
        afirmar("[CRLV campo={$campo}] tipo '{$rotulo}': stdout vazio (sem warning de conversao vazando)", $stdout === '');

        if ($excecao === null) {
            afirmar("[CRLV campo={$campo}] tipo '{$rotulo}': pode_avancar=false", $resultado['pode_avancar'] === false);
            afirmar("[CRLV campo={$campo}] tipo '{$rotulo}': motivo e a mensagem generica de estrutura invalida", $resultado['motivo'] === 'Falha ao validar CRLV: dados retornados em formato invalido');
            afirmar("[CRLV campo={$campo}] tipo '{$rotulo}': origem permanece NAO_VALIDADO", $resultado['origem'] === 'NAO_VALIDADO');
            afirmar("[CRLV campo={$campo}] tipo '{$rotulo}': resposta serializada NUNCA contem marcador sintetico", !contemMarcador(json_encode($resultado)));

            $atendimentoRecarregado = $atendimentoDao->buscarPorId($at['id_atendimento']);
            afirmar("[CRLV campo={$campo}] tipo '{$rotulo}': crlv_origem_validacao permanece NAO_VALIDADO no banco (sem persistencia parcial)", ($atendimentoRecarregado['crlv_origem_validacao'] ?? 'NAO_VALIDADO') === 'NAO_VALIDADO');
            afirmar("[CRLV campo={$campo}] tipo '{$rotulo}': nenhuma coluna crlv_* persistida contem marcador sintetico", !contemMarcador(json_encode($atendimentoRecarregado)));
        }
    }
}

// exercicio: aceita string/int/float (numerico), rejeita array/stdClass/bool.
$valoresExercicioInvalidos = [
    'array vazio' => [],
    'array aninhado com marcador' => [MARCADOR_ARRAY => 'valor_' . MARCADOR_ARRAY],
    'stdClass' => (object) [MARCADOR_STDCLASS => 'valor_' . MARCADOR_STDCLASS],
    'booleano true' => true,
    'booleano false' => false,
];
foreach ($valoresExercicioInvalidos as $rotulo => $valor) {
    $dados = $dadosCrlvValidoBase;
    $dados['exercicio'] = $valor;

    $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'MTZ' . substr(md5('exercicio' . $rotulo), 0, 4), $idsAtendimentoCriados);
    $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);

    [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCrlv($at, $vio, random_bytes(16)));

    afirmar("[CRLV campo=exercicio] tipo '{$rotulo}': NAO lanca excecao/TypeError/Error", $excecao === null);
    afirmar("[CRLV campo=exercicio] tipo '{$rotulo}': stdout vazio", $stdout === '');
    if ($excecao === null) {
        afirmar("[CRLV campo=exercicio] tipo '{$rotulo}': pode_avancar=false", $resultado['pode_avancar'] === false);
        afirmar("[CRLV campo=exercicio] tipo '{$rotulo}': motivo e a mensagem generica de estrutura invalida", $resultado['motivo'] === 'Falha ao validar CRLV: dados retornados em formato invalido');
    }
}

// ============================================================
// exercicio: fronteira de CONTEUDO (nao so tipo) -- achado BLOQUEANTE do
// /03-revisao independente de 2026-09-20 (backend-especialista,
// reproduzido pelo fluxo real em banco descartavel): `exercicio=2026.9`
// (float) e `exercicio="2026.9"` (string) eram aprovados normalmente e
// persistidos em crlv_ano como 2026 -- a parte fracionaria era descartada
// silenciosamente pelo cast (int) ja existente, sem NENHUMA sinalizacao.
// Cobre tambem a decisao de rejeitar "2026.0"/2026.0 (float ou string
// matematicamente inteira): investigado docs/manual_vio_decode.md
// (linhas 126-130) -- o unico teste real observado de `exercicio` (Trial,
// crlv-demo.bin) retornou o placeholder literal string "xxxxx", nunca um
// numero. SEM EVIDENCIA de que a VIO envie `exercicio` como float
// (inteiro ou fracionario), o esquema estrito rejeita qualquer float
// aqui, mesmo "2026.0"/2026.0 -- ver validarExercicioInteiroExato() em
// DocumentoRn.php.
// ============================================================

// ACEITOS -- inteiro exato (int nativo ou string de digitos puros),
// dentro da faixa ja existente ($exercicio > 0) -- continuam sendo
// aprovados normalmente, sem nenhuma alteracao de comportamento.
$valoresExercicioAceitos = [
    'int valido' => 2025,
    'string numerica valida' => '2025',
    'string numerica com zero a esquerda' => '02025',
    // Nao usa "999999"/"111111" etc -- sequencia de digito unico repetido e
    // tratada como placeholder por ehValorPlaceholder(), rejeitaria pela
    // regra de CONTEUDO existente, nao testaria o que se pretende aqui.
    'int valido grande, sem overflow (faixa existente permanece sem teto superior)' => 918273,
];
foreach ($valoresExercicioAceitos as $rotulo => $valorExercicio) {
    $dados = $dadosCrlvValidoBase;
    $dados['exercicio'] = $valorExercicio;
    $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'MTZOK', $idsAtendimentoCriados);
    // placa do atendimento deve bater com a placa do CRLV para aprovar de fato
    $atendimentoDao->atualizarEtapa($at['id_atendimento'], 'exp_crlv');
    $pdo->prepare('UPDATE tb_atendimento SET placa = :placa WHERE id_atendimento = :id')->execute(['placa' => $dadosCrlvValidoBase['placa'], 'id' => $at['id_atendimento']]);
    $at = $atendimentoDao->buscarPorId($at['id_atendimento']);
    $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);
    $r = $documentoRn->validarCrlv($at, $vio, random_bytes(16));
    afirmar("[CRLV exercicio ACEITO] '{$rotulo}': continua sendo aprovado normalmente (pode_avancar=true, sem regressao)", $r['pode_avancar'] === true);
    afirmar("[CRLV exercicio ACEITO] '{$rotulo}': origem=VIO_TRIAL", $r['origem'] === 'VIO_TRIAL');
}

// REJEITADOS -- tipo numerico correto, mas conteudo NAO e um inteiro
// exato (fracionario, notacao cientifica, "2026.0" sem evidencia,
// overflow de int para float, espaco em branco/sufixo). Devem seguir o
// MESMO caminho de falha estrutural ja usado para tipo incompativel --
// resposta VIO inteira invalidada, NUNCA truncada/aprovada.
$valoresExercicioConteudoInvalido = [
    'float fracionario 2026.9' => 2026.9,
    'string fracionaria "2026.9"' => '2026.9',
    'float fracionario 2026.1' => 2026.1,
    'string fracionaria "2026.0"' => '2026.0',
    'float matematicamente inteiro 2026.0 (sem evidencia de formato real)' => 2026.0,
    'notacao cientifica float 2.026e3' => 2.026e3,
    'notacao cientifica string "2.026e3"' => '2.026e3',
    'string vazia' => '',
    'string com espaco a frente " 2026"' => ' 2026',
    'string com espaco atras "2026 "' => '2026 ',
    'string com sufixo nao numerico "2026 anos"' => '2026 anos',
    'overflow de int para float (PHP_INT_MAX + 1)' => 9223372036854775808.0,
];
foreach ($valoresExercicioConteudoInvalido as $rotulo => $valorExercicio) {
    $dados = $dadosCrlvValidoBase;
    $dados['exercicio'] = $valorExercicio;
    $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'MTZ' . substr(md5('exercicioconteudo' . $rotulo), 0, 4), $idsAtendimentoCriados);
    $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);

    [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCrlv($at, $vio, random_bytes(16)));

    afirmar("[CRLV exercicio REJEITADO] '{$rotulo}': NAO lanca excecao/TypeError/Error", $excecao === null);
    afirmar("[CRLV exercicio REJEITADO] '{$rotulo}': stdout vazio (sem warning vazando)", $stdout === '');
    if ($excecao === null) {
        afirmar("[CRLV exercicio REJEITADO] '{$rotulo}': pode_avancar=false", $resultado['pode_avancar'] === false);
        afirmar("[CRLV exercicio REJEITADO] '{$rotulo}': motivo e a mensagem generica de estrutura invalida (nao trunca, nao aprova)", $resultado['motivo'] === 'Falha ao validar CRLV: dados retornados em formato invalido');
        afirmar("[CRLV exercicio REJEITADO] '{$rotulo}': origem permanece NAO_VALIDADO", $resultado['origem'] === 'NAO_VALIDADO');

        $atendimentoRecarregado = $atendimentoDao->buscarPorId($at['id_atendimento']);
        afirmar("[CRLV exercicio REJEITADO] '{$rotulo}': crlv_origem_validacao permanece NAO_VALIDADO no banco (sem persistencia parcial)", ($atendimentoRecarregado['crlv_origem_validacao'] ?? 'NAO_VALIDADO') === 'NAO_VALIDADO');
        afirmar("[CRLV exercicio REJEITADO] '{$rotulo}': crlv_ano NUNCA persistido com o valor truncado 2026", $atendimentoRecarregado['crlv_ano'] === null || (int) $atendimentoRecarregado['crlv_ano'] !== 2026);
    }
}

// REGRESSAO -- valor negativo (int ou string) continua sendo rejeitado
// pela regra de FAIXA JA EXISTENTE em avaliarCrlv() ($exercicio > 0),
// NAO pela fronteira nova de exatidao -- negativo e um inteiro EXATO
// (sem perda de precisao), so fora da faixa permitida. Confirma que a
// fronteira nova nao usurpa/duplica a regra de faixa (instrucao
// explicita de nao altera-la).
$valoresExercicioNegativoRegressao = [
    'int negativo' => -2026,
    'string negativa' => '-2026',
];
foreach ($valoresExercicioNegativoRegressao as $rotulo => $valorExercicio) {
    $dados = $dadosCrlvValidoBase;
    $dados['exercicio'] = $valorExercicio;
    $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'MTZ' . substr(md5('exercicioneg' . $rotulo), 0, 4), $idsAtendimentoCriados);
    $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);

    [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCrlv($at, $vio, random_bytes(16)));

    afirmar("[CRLV exercicio negativo] '{$rotulo}': NAO lanca excecao (regressao)", $excecao === null);
    afirmar("[CRLV exercicio negativo] '{$rotulo}': stdout vazio", $stdout === '');
    if ($excecao === null) {
        afirmar("[CRLV exercicio negativo] '{$rotulo}': pode_avancar=false", $resultado['pode_avancar'] === false);
        afirmar("[CRLV exercicio negativo] '{$rotulo}': usa a mensagem de CONTEUDO/faixa ja existente, NAO a de estrutura invalida (fronteira nova nao intercepta sinal)", $resultado['motivo'] !== 'Falha ao validar CRLV: dados retornados em formato invalido');
        afirmar("[CRLV exercicio negativo] '{$rotulo}': origem permanece NAO_VALIDADO", $resultado['origem'] === 'NAO_VALIDADO');
    }
}

// ============================================================
// exercicio: MAGNITUDE/OVERFLOW -- achado BLOQUEANTE do /03-revisao curta
// independente de 2026-09-20 (security-especialista, reproduzido pelo
// fluxo real em banco descartavel): a regex /^-?\d+$/ de
// validarExercicioInteiroExato() validava so o FORMATO lexical (sequencia
// de digitos, sinal opcional), nunca se o valor cabe em PHP_INT -- uma
// string de digitos maior que PHP_INT_MAX passava incolume, chegava
// intacta ao cast (int) ja existente, que SATURAVA SILENCIOSAMENTE para
// PHP_INT_MAX (> 0, aprovado pela regra de faixa), e saturava de novo no
// MySQL (coluna SMALLINT) para 32767 -- resultado real confirmado antes
// desta correcao: pode_avancar=true, origem=VIO_TRIAL,
// crlv_ano='32767'. Ver caberEmPhpInt()/validarExercicioInteiroExato()
// em DocumentoRn.php -- comparacao por COMPRIMENTO/LEXICOGRAFICA em
// nivel de STRING, nunca convertendo a string gigante para numero.
// ============================================================

$phpIntMaxStr = (string) PHP_INT_MAX;          // "9223372036854775807"
$phpIntMaxMais1Str = '9223372036854775808';    // PHP_INT_MAX + 1, construido como STRING LITERAL (nunca aritmetica -- aritmetica ja viraria float antes de chegar aqui)
$phpIntMinMenos1Str = '-9223372036854775809';  // PHP_INT_MIN - 1, idem

// ACEITOS pela fronteira de MAGNITUDE (cabem em PHP_INT) -- o resultado
// final (pode_avancar) e decidido pela regra de FAIXA ja existente
// ($exercicio > 0), que NAO tem teto superior de negocio documentado
// para "ano" (investigado: nenhum contrato/regra de negocio confirma um
// teto plausivel alem de > 0) -- portanto PHP_INT_MAX "cabe" tecnicamente
// e e aprovado, por decisao explicita de NAO inventar um teto de negocio
// sem justificativa.
$valoresExercicioMagnitudeAceita = [
    'PHP_INT_MAX (int nativo)' => PHP_INT_MAX,
    'string igual a (string) PHP_INT_MAX' => $phpIntMaxStr,
];
foreach ($valoresExercicioMagnitudeAceita as $rotulo => $valorExercicio) {
    $dados = $dadosCrlvValidoBase;
    $dados['exercicio'] = $valorExercicio;
    $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'MTZ' . substr(md5('exercmag' . $rotulo), 0, 4), $idsAtendimentoCriados);
    $atendimentoDao->atualizarEtapa($at['id_atendimento'], 'exp_crlv');
    $pdo->prepare('UPDATE tb_atendimento SET placa = :placa WHERE id_atendimento = :id')->execute(['placa' => $dadosCrlvValidoBase['placa'], 'id' => $at['id_atendimento']]);
    $at = $atendimentoDao->buscarPorId($at['id_atendimento']);
    $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);

    [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCrlv($at, $vio, random_bytes(16)));

    afirmar("[CRLV exercicio MAGNITUDE ACEITA] '{$rotulo}': NAO lanca excecao/TypeError/Error", $excecao === null);
    if ($excecao === null) {
        afirmar("[CRLV exercicio MAGNITUDE ACEITA] '{$rotulo}': aceito pela fronteira de magnitude -- pode_avancar=true (sem teto de negocio documentado para 'ano' alem de > 0)", $resultado['pode_avancar'] === true);
        afirmar("[CRLV exercicio MAGNITUDE ACEITA] '{$rotulo}': origem=VIO_TRIAL", $resultado['origem'] === 'VIO_TRIAL');
    }

    // OBSERVACAO nao bloqueante, FORA do escopo desta correcao (nao e
    // achado desta rodada, nem foi pedido corrigir schema/DB): a coluna
    // crlv_ano e SMALLINT (sql/schema.sql:85). Um valor que CABE em
    // PHP_INT (aprovado corretamente por esta fronteira de magnitude, que
    // so valida capacidade de PHP_INT, nunca capacidade de coluna de
    // banco) pode ainda ser silenciosamente saturado pelo MySQL na
    // gravacao, se o `sql_mode` do servidor nao incluir
    // STRICT_TRANS_TABLES (confirmado: dev DB deste ambiente usa
    // "NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION", sem modo
    // estrito). Nao corrigido aqui -- alterar schema/DB esta fora do
    // escopo explicito desta demanda, e nao ha teto de negocio
    // documentado para "ano" que justifique um valor de corte arbitrario.
    // Registrado como observacao para decisao futura do usuario.
    $atendimentoRecarregadoMag = $atendimentoDao->buscarPorId($at['id_atendimento']);
    echo "INFO  - [CRLV exercicio MAGNITUDE ACEITA] '{$rotulo}': crlv_ano persistido = " . var_export($atendimentoRecarregadoMag['crlv_ano'] ?? null, true) . " (observacao nao bloqueante, fora do escopo -- teto de coluna SMALLINT do banco, nao teto de negocio de 'ano')\n";
}

// REJEITADOS pela fronteira de MAGNITUDE -- formato lexical de digitos
// puros OK, mas o valor NAO cabe em PHP_INT_MIN..PHP_INT_MAX -- devem ser
// interceptados EM NIVEL DE STRING, antes de qualquer cast/conversao
// numerica, nunca saturar/truncar silenciosamente.
$valoresExercicioMagnitudeRejeitada = [
    'string PHP_INT_MAX + 1 (construida como string literal)' => $phpIntMaxMais1Str,
    'string PHP_INT_MIN - 1 (construida como string literal)' => $phpIntMinMenos1Str,
    'string gigante "99999999999999999999" (caso original do achado)' => '99999999999999999999',
    'sequencia de 200 digitos' => str_repeat('7', 200),
];
foreach ($valoresExercicioMagnitudeRejeitada as $rotulo => $valorExercicio) {
    $dados = $dadosCrlvValidoBase;
    $dados['exercicio'] = $valorExercicio;
    $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'MTZ' . substr(md5('exercmagrej' . $rotulo), 0, 4), $idsAtendimentoCriados);
    $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dados, 'erro' => null]);

    [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCrlv($at, $vio, random_bytes(16)));

    afirmar("[CRLV exercicio MAGNITUDE REJEITADA] '{$rotulo}': NAO lanca excecao/TypeError/Error", $excecao === null);
    afirmar("[CRLV exercicio MAGNITUDE REJEITADA] '{$rotulo}': stdout vazio (sem warning vazando)", $stdout === '');
    if ($excecao === null) {
        afirmar("[CRLV exercicio MAGNITUDE REJEITADA] '{$rotulo}': pode_avancar=false", $resultado['pode_avancar'] === false);
        afirmar("[CRLV exercicio MAGNITUDE REJEITADA] '{$rotulo}': motivo e a mensagem generica de estrutura invalida (nao satura, nao aprova)", $resultado['motivo'] === 'Falha ao validar CRLV: dados retornados em formato invalido');
        afirmar("[CRLV exercicio MAGNITUDE REJEITADA] '{$rotulo}': origem permanece NAO_VALIDADO", $resultado['origem'] === 'NAO_VALIDADO');

        $atendimentoRecarregado = $atendimentoDao->buscarPorId($at['id_atendimento']);
        afirmar("[CRLV exercicio MAGNITUDE REJEITADA] '{$rotulo}': crlv_origem_validacao permanece NAO_VALIDADO (sem persistencia parcial)", ($atendimentoRecarregado['crlv_origem_validacao'] ?? 'NAO_VALIDADO') === 'NAO_VALIDADO');
        afirmar("[CRLV exercicio MAGNITUDE REJEITADA] '{$rotulo}': crlv_ano NUNCA persistido (nem saturado para 32767/PHP_INT_MAX)", $atendimentoRecarregado['crlv_ano'] === null);
    }
}

// Regressao explicita de placeholder (CRLV).
$atPlaceholderCrlv = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', 'MTZPLC2', $idsAtendimentoCriados);
$vioPlaceholderCrlv = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => array_merge($dadosCrlvValidoBase, ['placa' => 'xxxxx']), 'erro' => null]);
$rPlaceholderCrlv = $documentoRn->validarCrlv($atPlaceholderCrlv, $vioPlaceholderCrlv, random_bytes(16));
afirmar('[CRLV regressao] placa placeholder "xxxxx" continua rejeitada (regra de conteudo, nao de tipo)', $rPlaceholderCrlv['pode_avancar'] === false && $rPlaceholderCrlv['motivo'] !== 'Falha ao validar CRLV: dados retornados em formato invalido');

// Controle positivo: caminho feliz de CRLV continua 100% funcional.
$atValidoCrlv = novoAtendimento($atendimentoDao, $idTotem, 'exp_crlv', $dadosCrlvValidoBase['placa'], $idsAtendimentoCriados);
$vioValidoCrlv = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dadosCrlvValidoBase, 'erro' => null]);
$rValidoCrlv = $documentoRn->validarCrlv($atValidoCrlv, $vioValidoCrlv, random_bytes(16));
afirmar('[CRLV controle positivo] resposta 100% valida continua sendo aprovada sem alteracao de comportamento', $rValidoCrlv['pode_avancar'] === true && $rValidoCrlv['origem'] === 'VIO_TRIAL');

// ============================================================
// 'dados' inteiro como stdClass ou array com 'data' nao-array -- moderado
// (achado 2): garante que a fronteira de is_array() protege ANTES de
// qualquer acesso a chave.
// ============================================================
$casosEnvelopeInvalido = [
    'dados como stdClass' => (object) ['nome' => 'x'],
    "dados['data'] como stdClass" => ['data' => (object) ['nome' => 'x']],
    "dados['data'] como inteiro" => ['data' => 12345],
];
foreach ($casosEnvelopeInvalido as $rotulo => $dadosBrutos) {
    $at = novoAtendimento($atendimentoDao, $idTotem, 'exp_cnh', 'MTZ' . substr(md5('envelope' . $rotulo), 0, 4), $idsAtendimentoCriados);
    $vio = new VioDecodeClientFalsoMatriz(['ok' => true, 'ambiente' => 'trial', 'dados' => $dadosBrutos, 'erro' => null]);

    [$resultado, $excecao, $stdout] = executarCapturandoStdout(fn () => $documentoRn->validarCnh($at, $vio, random_bytes(16)));

    afirmar("[Envelope invalido] '{$rotulo}': NAO lanca excecao/Error (achado MODERADO corrigido)", $excecao === null);
    afirmar("[Envelope invalido] '{$rotulo}': stdout vazio", $stdout === '');
    if ($excecao === null) {
        afirmar("[Envelope invalido] '{$rotulo}': pode_avancar=false", $resultado['pode_avancar'] === false);
    }
}

// ============================================================
// Confirmacao: nenhum marcador sintetico vazou para o log isolado desta
// suite, e o log categorizado (documento/campo/tipo, NUNCA o valor)
// funcionou como projetado.
// ============================================================
$conteudoLog = is_file($logIsoladoPath) ? file_get_contents($logIsoladoPath) : '';
afirmar('[Log] arquivo de log isolado desta suite foi criado (categorizacao esta ativa)', $conteudoLog !== '');
afirmar('[Log] log NUNCA contem os marcadores sinteticos (nem chave nem valor)', !contemMarcador($conteudoLog));
afirmar('[Log] log NUNCA contem a palavra "Array to string" (nenhum warning de cast cru vazou)', stripos($conteudoLog, 'Array to string') === false);
afirmar("[Log] log contem pelo menos uma entrada categorizada (tipo_recebido=array ou tipo_recebido=stdClass)", stripos($conteudoLog, 'tipo_recebido=array') !== false || stripos($conteudoLog, 'tipo_recebido=stdClass') !== false);

// Restaura configuracao de log original.
ini_set('log_errors', $logOriginalUsaErrorLog !== false ? $logOriginalUsaErrorLog : '1');
ini_set('error_log', $logOriginalDestino !== false ? $logOriginalDestino : '');
@unlink($logIsoladoPath);

// ============================================================
// Limpeza final
// ============================================================
$pdo->exec('DELETE FROM tb_vio_cache_cnh');
$pdo->exec('DELETE FROM tb_vio_cache_crlv');
foreach ($idsAtendimentoCriados as $id) {
    $pdo->prepare('DELETE FROM tb_atendimento WHERE id_atendimento = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM tb_totem WHERE id_totem = :id')->execute(['id' => $idTotem]);

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
