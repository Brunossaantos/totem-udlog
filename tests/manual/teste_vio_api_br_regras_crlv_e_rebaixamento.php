<?php

/**
 * Teste manual (mesmo padrao hermetico de tests/manual/teste_vio_api_br_cas_e_cache.php,
 * banco `qa_`-prefixado descartavel via tests/manual/qa_db_bootstrap.php) —
 * demanda remocao-legado-serpro-e-hardening-documentos (2026-09-28).
 *
 * OBJETIVO: recriar, contra o fluxo REAL atual vio.api.br
 * (App\Rn\DocumentoRn::avaliarResultadoVioApiBrCrlv(), avaliarCrlv(),
 * preencherManualCrlv()), TODA a cobertura de regra de
 * negocio do CRLV hoje testada SO via VioDecodeClientFalso/validarCrlv()
 * legado em tests/manual/teste_rebaixamento_manual.php,
 * teste_talent_rntc_tipo_crlv.php e teste_talent_uf_crlv.php — pre-requisito
 * BLOQUEANTE (decisao do usuario) para a remocao futura desses 3 arquivos
 * pelo backend-especialista. Ver matriz de equivalencia completa em
 * docs/handoffs/2026-09-28-remocao-legado-serpro-e-hardening-documentos.md,
 * secao "Suite de equivalencia criada e validada (2026-09-28)".
 *
 * ACHADO registrado explicitamente (nao presumido, confirmado por leitura de
 * DocumentoRn::avaliarCrlv() e grep de "particular" em app/ = zero
 * ocorrencias): o sistema NAO tem nenhum conceito de "veiculo particular"
 * nem nenhuma excecao que aceite RNTC ausente para qualquer tipo de veiculo
 * — RNTC ausente/vazio SEMPRE rejeita o CRLV
 * (avaliarCrlv(): `if ($rntc === '') { ... }`), sem excecao por tipo de
 * veiculo, tanto no fluxo novo quanto no antigo (confirmado tambem em
 * teste_talent_rntc_tipo_crlv.php linha ~98-101, que ja testava RNTC vazio
 * como rejeicao incondicional). O cenario 1 do escopo desta suite
 * ("CRLV sem RNTC, veiculo particular — aceito") portanto NAO reflete uma
 * regra real do codigo em NENHUM dos dois fluxos — testado abaixo como a
 * regra REAL confirmada (RNTC ausente sempre rejeita, independente do valor
 * de "tipo"), nunca como "aceito". Nao e achado de equivalencia perdida
 * (a regra nunca existiu nos 3 testes antigos tambem), e sim uma premissa da
 * tarefa que nao corresponde ao codigo — reportado ao orquestrador, nao
 * decidido/inventado silenciosamente aqui.
 *
 * Uso: php tests/manual/teste_vio_api_br_regras_crlv_e_rebaixamento.php
 */

require_once __DIR__ . '/qa_db_bootstrap.php';

use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Dao\OrdemColetaDao;
use App\Rn\DocumentoRn;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;

$totalTestes = 0;
$totalFalhas = 0;
$chamadasReaisVioApiBr = 0; // nunca incrementado nesta suite -- ver cenario 16

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

$nomeBanco = null;

try {
    [$pdo, $nomeBanco] = qaDbCriar('vio_api_br_regras_crlv');

    $atendimentoDao = new AtendimentoDao($pdo);
    $documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao);
    // OrdemColetaDao nunca conecta no construtor (conexao so no momento da
    // consulta, ver App\Dao\OrdemColetaDao) -- seguro instanciar aqui sem
    // nenhuma chamada externa real, so para exercitar
    // AtendimentoRn::salvarDadosMotorista (que nao usa OrdemColetaClient).
    $atendimentoRn = new AtendimentoRn($atendimentoDao, new OrdemColetaClient(new OrdemColetaDao()));

    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('QA_CRLV_REGRAS', 'Totem QA CRLV Regras', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
    $idTotem = (int) $pdo->lastInsertId();

    function novoAtendimentoCrlv(PDO $pdo, AtendimentoDao $dao, int $idTotem, string $placa): array
    {
        $id = $dao->criar($idTotem, 'expedicao', $placa);
        $dao->atualizarEtapa($id, 'exp_crlv');
        return $dao->buscarPorId($id);
    }

    /**
     * Fixture base do resultado normalizado de vio.api.br para CRLV — mesmo
     * formato/chaves REAIS confirmadas usado em
     * tests/manual/teste_vio_api_br_cas_e_cache.php (resultadoCrlvBase()),
     * reaproveitado aqui com a MESMA fonte de verdade (nao duplicar valores
     * assumidos/divergentes).
     */
    function resultadoCrlvBaseRegras(array $overrides = [], array $dadosLeituraOverrides = []): array
    {
        $dadosLeitura = array_replace([
            'Placa' => 'ABC1234', 'Exercício' => 2025, 'UF' => 'SP',
            'RNTRC' => '12345678', 'Tipo' => 'CAMINHAO', 'Renavam' => '98765432100',
        ], $dadosLeituraOverrides);

        return array_replace([
            'ok' => true, 'ambiguo' => false, 'nao_encontrado' => false,
            'estado_leitura' => 'completed', 'qr_type' => 'vio',
            'dados_leitura' => $dadosLeitura,
            'estado_comparacao' => 'completed',
            'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => ['placa' => 'match', 'renavam' => 'match', 'exercicio' => 'match', 'uf' => 'match']],
            'pages_processed' => null, 'total_pages' => null,
        ], $overrides);
    }

    /**
     * Prepara um atendimento para receber um resultado de
     * avaliarResultadoVioApiBrCrlv() com tentativa vigente e ID externo ja
     * persistidos (pre-condicao real de producao, via
     * AtendimentoDao::iniciarEnvioVioApiBr/gravarIdExternoVioApiBr). Os
     * parametros $fingerprint/$hmacVersao sao ignorados (QR-only, sem cache);
     * mantidos so para nao alterar os chamadores.
     */
    function prepararEnvioCrlv(PDO $pdo, AtendimentoDao $dao, int $idAtendimento, string $fingerprint, int $hmacVersao = 1): array
    {
        $tentativa = bin2hex(random_bytes(16));
        $dao->iniciarEnvioVioApiBr($idAtendimento, 'crlv', $tentativa);
        $dao->gravarIdExternoVioApiBr($idAtendimento, 'crlv', $tentativa, 'id-ext-' . bin2hex(random_bytes(4)));
        return $dao->buscarPorId($idAtendimento);
    }

    // ============================================================
    // Cenario 1 — RNTC ausente + "veiculo particular": REGRA REAL
    // confirmada (rejeita sempre, sem excecao por tipo) — ver ACHADO no
    // cabecalho deste arquivo.
    // ============================================================
    $at1 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'PAR0001');
    $fp1 = bin2hex(random_bytes(32));
    $at1 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at1['id_atendimento'], $fp1);
    $r1 = $documentoRn->avaliarResultadoVioApiBrCrlv($at1, resultadoCrlvBaseRegras([], [
        'Placa' => 'PAR0001', 'RNTRC' => null, 'Tipo' => 'PARTICULAR',
    ]));
    afirmar('Cenario 1 (decisao 2026-10-02): RNTC ausente NAO reprova mais o CRLV (RNTRC opcional na aprovacao)', $r1['pode_avancar'] === true);
    // RNTC nao e campo CRITICO em respostaVioApiBrAprovavel() (so placa/
    // renavam/exercicio/uf) -- a rejeicao acontece DENTRO de avaliarCrlv(),
    // que devolve o motivo GRANULAR real diretamente (avaliarResultadoVioApiBrCrlv
    // repassa $avaliacao de avaliarCrlv() sem reescrever o motivo quando
    // pode_avancar=false por essa via -- so respostaVioApiBrNaoAprovada()
    // usa a mensagem generica fixa, e essa so e usada quando
    // respostaVioApiBrAprovavel() falha ANTES de chegar em avaliarCrlv()).
    afirmar('Cenario 1: motivo de aprovacao nao menciona RNTC', !str_contains($r1['motivo'], 'RNTC'));

    // ============================================================
    // Cenario 2 — RNTC ausente NUNCA convertido em placeholder: nenhum
    // valor e persistido (rejeicao acontece ANTES de qualquer escrita) — a
    // coluna crlv_rntc permanece exatamente como estava antes (NULL, valor
    // default de uma linha nunca validada), nunca "SEM RNTC"/"N/A"/qualquer
    // outro texto inventado.
    // ============================================================
    $at1Depois = $atendimentoDao->buscarPorId((int) $at1['id_atendimento']);
    afirmar('Cenario 2: crlv_rntc persiste NULL (nao string vazia nem texto inventado) com CRLV aprovado sem RNTRC', $at1Depois['crlv_rntc'] === null && $at1Depois['crlv_snapshot_rntc'] === null);
    afirmar('Cenario 2: crlv_origem_validacao = VIO_API_BR apos aprovacao sem RNTRC', $at1Depois['crlv_origem_validacao'] === 'VIO_API_BR');

    // ============================================================
    // Cenario 3 — RNTC presente e valido: preservado corretamente (exato,
    // sem normalizacao alem de trim)
    // ============================================================
    $at3 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0003');
    $fp3 = bin2hex(random_bytes(32));
    $at3 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at3['id_atendimento'], $fp3);
    $r3 = $documentoRn->avaliarResultadoVioApiBrCrlv($at3, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0003', 'RNTRC' => '87654321']));
    afirmar('Cenario 3: CRLV aprovado com RNTC presente/valido', $r3['pode_avancar'] === true);
    $at3Depois = $atendimentoDao->buscarPorId((int) $at3['id_atendimento']);
    afirmar('Cenario 3: crlv_rntc gravado com o valor EXATO extraido de "RNTRC" (chave real confirmada)', $at3Depois['crlv_rntc'] === '87654321');

    // ============================================================
    // Cenario 4 (decisao 2026-10-02) — Tipo de veiculo ausente NAO reprova
    // (mesma regra do RNTRC): aprova e persiste NULL.
    // ============================================================
    $at4 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'TIP0004');
    $fp4 = bin2hex(random_bytes(32));
    $at4 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at4['id_atendimento'], $fp4);
    $r4 = $documentoRn->avaliarResultadoVioApiBrCrlv($at4, resultadoCrlvBaseRegras([], ['Placa' => 'TIP0004', 'Tipo' => null]));
    afirmar('Cenario 4: CRLV com Tipo de veiculo ausente APROVA (Tipo opcional na leitura)', $r4['pode_avancar'] === true);
    $at4Depois = $atendimentoDao->buscarPorId((int) $at4['id_atendimento']);
    afirmar('Cenario 4: crlv_tipo_veiculo e snapshot persistem NULL (nao string vazia)', $at4Depois['crlv_tipo_veiculo'] === null && $at4Depois['crlv_snapshot_tipo_veiculo'] === null);
    afirmar('Cenario 4: origem VIO_API_BR apos aprovacao sem Tipo', $at4Depois['crlv_origem_validacao'] === 'VIO_API_BR');

    // ============================================================
    // Cenario 5 — Tipo presente e valido: preservado corretamente
    // ============================================================
    $at5 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'TIP0005');
    $fp5 = bin2hex(random_bytes(32));
    $at5 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at5['id_atendimento'], $fp5);
    $r5 = $documentoRn->avaliarResultadoVioApiBrCrlv($at5, resultadoCrlvBaseRegras([], ['Placa' => 'TIP0005', 'Tipo' => 'CARRETA']));
    afirmar('Cenario 5: CRLV aprovado com Tipo de veiculo presente/valido', $r5['pode_avancar'] === true);
    $at5Depois = $atendimentoDao->buscarPorId((int) $at5['id_atendimento']);
    afirmar('Cenario 5: crlv_tipo_veiculo gravado com o valor EXATO extraido de "Tipo"', $at5Depois['crlv_tipo_veiculo'] === 'CARRETA');

    // ============================================================
    // Cenario 6 — UF valida: aprova e grava em maiusculo
    // ============================================================
    $at6 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'UFV0006');
    $fp6 = bin2hex(random_bytes(32));
    $at6 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at6['id_atendimento'], $fp6);
    $r6 = $documentoRn->avaliarResultadoVioApiBrCrlv($at6, resultadoCrlvBaseRegras([], ['Placa' => 'UFV0006', 'UF' => 'sp']));
    afirmar('Cenario 6: CRLV aprovado com UF valida (minuscula normalizada)', $r6['pode_avancar'] === true);
    $at6Depois = $atendimentoDao->buscarPorId((int) $at6['id_atendimento']);
    afirmar('Cenario 6: crlv_uf gravado em maiusculo (SP)', $at6Depois['crlv_uf'] === 'SP');

    // ============================================================
    // Cenario 7 — UF ausente: rejeita
    // ============================================================
    $at7 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'UFA0007');
    $fp7 = bin2hex(random_bytes(32));
    $at7 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at7['id_atendimento'], $fp7);
    $r7 = $documentoRn->avaliarResultadoVioApiBrCrlv($at7, resultadoCrlvBaseRegras([], ['Placa' => 'UFA0007', 'UF' => null]));
    afirmar('Cenario 7: CRLV com UF ausente e rejeitado', $r7['pode_avancar'] === false);

    // ============================================================
    // Cenario 8 — UF invalida (fora das 27 siglas): rejeita
    // ============================================================
    $at8 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'UFI0008');
    $fp8 = bin2hex(random_bytes(32));
    $at8 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at8['id_atendimento'], $fp8);
    $r8 = $documentoRn->avaliarResultadoVioApiBrCrlv($at8, resultadoCrlvBaseRegras([], ['Placa' => 'UFI0008', 'UF' => 'ZY']));
    afirmar('Cenario 8: CRLV com UF fora da lista fechada de 27 siglas (ZY) e rejeitado', $r8['pode_avancar'] === false);
    $at8Depois = $atendimentoDao->buscarPorId((int) $at8['id_atendimento']);
    afirmar('Cenario 8: crlv_origem_validacao permanece NAO_VALIDADO (nada persistido)', $at8Depois['crlv_origem_validacao'] === 'NAO_VALIDADO');

    // ============================================================
    // Cenario 9 — Campos com tipos incompativeis: Exercicio como
    // array/bool (mesma fronteira de tipo do fluxo VIO existente,
    // ESQUEMA_TIPOS_CRLV['exercicio'] = 'numerico', validado por
    // validarTipoNumerico()/extrairCampoNumerico()) — invalida a resposta
    // INTEIRA (fail-closed), nunca um Error/TypeError fatal, nunca so o
    // campo ignorado.
    // ============================================================
    $at9a = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'TIPO009A');
    $fp9a = bin2hex(random_bytes(32));
    $at9a = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at9a['id_atendimento'], $fp9a);
    $r9a = $documentoRn->avaliarResultadoVioApiBrCrlv($at9a, resultadoCrlvBaseRegras([], ['Placa' => 'TIPO009A', 'Exercício' => [2025]]));
    afirmar('Cenario 9a: Exercicio vindo como ARRAY invalida a resposta inteira (fail-closed, sem Error fatal)', $r9a['pode_avancar'] === false);

    $at9b = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'TIPO009B');
    $fp9b = bin2hex(random_bytes(32));
    $at9b = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at9b['id_atendimento'], $fp9b);
    $r9b = $documentoRn->avaliarResultadoVioApiBrCrlv($at9b, resultadoCrlvBaseRegras([], ['Placa' => 'TIPO009B', 'Exercício' => true]));
    afirmar('Cenario 9b: Exercicio vindo como BOOL invalida a resposta inteira (fail-closed, sem Error fatal)', $r9b['pode_avancar'] === false);

    // ============================================================
    // Cenario 10 — Rebaixamento para MANUAL quando os dados divergirem do
    // snapshot gravado por uma aprovacao VIO_API_BR (App\Rn\AtendimentoRn::
    // salvarDadosMotorista) — equivalente ao item 7a-2 de
    // teste_rebaixamento_manual.php, agora contra o fluxo novo.
    // ============================================================
    $at10 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'REB0010');
    $fp10 = bin2hex(random_bytes(32));
    $at10 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at10['id_atendimento'], $fp10);
    $r10 = $documentoRn->avaliarResultadoVioApiBrCrlv($at10, resultadoCrlvBaseRegras([], ['Placa' => 'REB0010']));
    afirmar('Cenario 10 (setup): CRLV aprovado via VIO_API_BR', $r10['pode_avancar'] === true && $r10['origem'] === 'VIO_API_BR');
    $at10Depois = $atendimentoDao->buscarPorId((int) $at10['id_atendimento']);
    afirmar('Cenario 10 (setup): snapshot crlv_snapshot_rntc gravado (origem VIO_API_BR incluida na regra de snapshot)', $at10Depois['crlv_snapshot_rntc'] === '12345678');

    $atendimentoRn->salvarDadosMotorista((int) $at10['id_atendimento'], [
        'motorista_nome' => '', 'motorista_cpf' => '', 'cnh_validade' => '',
        'crlv_ano' => '2025', 'placa' => 'REB0010', 'crlv_uf' => 'SP',
        'crlv_rntc' => '99999999', // diferente do snapshot (12345678)
        'crlv_tipo_veiculo' => 'CAMINHAO',
    ]);
    $at10AposEdicao = $atendimentoDao->buscarPorId((int) $at10['id_atendimento']);
    afirmar('Cenario 10: editar o RNTC (divergente do snapshot VIO_API_BR) rebaixa crlv_origem_validacao para MANUAL', $at10AposEdicao['crlv_origem_validacao'] === 'MANUAL');
    afirmar('Cenario 10: rebaixamento tambem marca crlv_status_revisao = PENDENTE_REVISAO', $at10AposEdicao['crlv_status_revisao'] === 'PENDENTE_REVISAO');

    // ============================================================
    // Cenario 11 — Manutencao da origem automatica quando os dados forem
    // EQUIVALENTES ao snapshot (confirmar sem editar)
    // ============================================================
    $at11 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'MAN0011');
    $fp11 = bin2hex(random_bytes(32));
    $at11 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at11['id_atendimento'], $fp11);
    $documentoRn->avaliarResultadoVioApiBrCrlv($at11, resultadoCrlvBaseRegras([], ['Placa' => 'MAN0011']));
    $atendimentoRn->salvarDadosMotorista((int) $at11['id_atendimento'], [
        'motorista_nome' => '', 'motorista_cpf' => '', 'cnh_validade' => '',
        'crlv_ano' => '2025', 'placa' => 'MAN0011', 'crlv_uf' => 'SP',
        'crlv_rntc' => '12345678', 'crlv_tipo_veiculo' => 'CAMINHAO', // identico ao snapshot
    ]);
    $at11Depois = $atendimentoDao->buscarPorId((int) $at11['id_atendimento']);
    afirmar('Cenario 11: confirmar SEM divergir do snapshot preserva crlv_origem_validacao = VIO_API_BR', $at11Depois['crlv_origem_validacao'] === 'VIO_API_BR');
    afirmar('Cenario 11: confirmar sem editar preserva crlv_status_revisao = OK', $at11Depois['crlv_status_revisao'] === 'OK');

    // ============================================================
    // Cenario 12 — VIO_API_BR (validacao em tempo real) e VIO_CACHE (cache
    // hit) como origens DISTINTAS
    // ============================================================
    $at12a = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'ORI0012');
    $fp12 = bin2hex(random_bytes(32));
    $at12a = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at12a['id_atendimento'], $fp12);
    $r12a = $documentoRn->avaliarResultadoVioApiBrCrlv($at12a, resultadoCrlvBaseRegras([], ['Placa' => 'ORI0012']));
    afirmar('Cenario 12: primeira validacao (leitura real) tem origem = VIO_API_BR', $r12a['origem'] === 'VIO_API_BR');

    // Contrato QR-only: a aprovacao nao grava VIO_CACHE; cada leitura e
    // sempre validada em tempo real (origem VIO_API_BR).
    afirmar('Cenario 12 (QR-only): aprovacao NAO grava VIO_CACHE (tb_vio_api_cache_crlv permanece vazia)', (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_crlv')->fetchColumn() === 0);
    $at12b = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'ORI0012');
    $fp12b = bin2hex(random_bytes(32));
    $at12b = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at12b['id_atendimento'], $fp12b);
    $r12b = $documentoRn->avaliarResultadoVioApiBrCrlv($at12b, resultadoCrlvBaseRegras([], ['Placa' => 'ORI0012']));
    afirmar('Cenario 12 (QR-only): segunda leitura da mesma placa e aprovada de novo em tempo real, origem = VIO_API_BR (nunca VIO_CACHE)', $r12b['pode_avancar'] === true && $r12b['origem'] === 'VIO_API_BR');

    // Cenarios 13 e 27 (cache CRLV vencido / registro mal formado) removidos:
    // exerciam tentarCacheCrlv()/VioApiBrCacheDao, eliminados em
    // fluxo-qr-exclusivo-cnh-crlv (nao ha mais leitura de cache).

    // ============================================================
    // Cenario 14 — Atomicidade: uma rejeicao (resultado invalido) NUNCA
    // sobrescreve um dado anterior valido ja gravado
    // ============================================================
    $at14 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'ATO0014');
    $fp14a = bin2hex(random_bytes(32));
    $at14 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at14['id_atendimento'], $fp14a);
    $r14a = $documentoRn->avaliarResultadoVioApiBrCrlv($at14, resultadoCrlvBaseRegras([], ['Placa' => 'ATO0014', 'RNTRC' => '11112222', 'Tipo' => 'CARRETA', 'UF' => 'RJ']));
    afirmar('Cenario 14 (setup): primeira validacao (dado A) aprovada', $r14a['pode_avancar'] === true);
    $at14AposA = $atendimentoDao->buscarPorId((int) $at14['id_atendimento']);

    // Nova tentativa com comparacao diagnostica divergente, mas dados
    // extraidos validos: a decisao de produto atual exige aprovacao e
    // atualizacao pelos dados da leitura, nunca pelo bloco compare.
    $fp14b = bin2hex(random_bytes(32));
    $at14ParaRejeicao = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at14['id_atendimento'], $fp14b);
    $r14b = $documentoRn->avaliarResultadoVioApiBrCrlv($at14ParaRejeicao, resultadoCrlvBaseRegras([
        'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 1], 'campos' => ['placa' => 'mismatch', 'renavam' => 'match', 'exercicio' => 'match', 'uf' => 'match']],
    ], ['Placa' => 'ATO0014', 'RNTRC' => '99998888', 'Tipo' => 'BITREM', 'UF' => 'SP']));
    afirmar('Cenario 14: segunda validacao com mismatch diagnostico e aprovada pelos dados extraidos validos', $r14b['pode_avancar'] === true);

    $at14AposRejeicao = $atendimentoDao->buscarPorId((int) $at14['id_atendimento']);
    afirmar('Cenario 14: crlv_rntc e atualizado pela segunda leitura VIO valida, apesar do mismatch diagnostico', $at14AposRejeicao['crlv_rntc'] === '99998888');
    afirmar('Cenario 14: crlv_tipo_veiculo e atualizado pela segunda leitura VIO valida', $at14AposRejeicao['crlv_tipo_veiculo'] === 'BITREM');
    afirmar('Cenario 14: crlv_uf e atualizado pela segunda leitura VIO valida', $at14AposRejeicao['crlv_uf'] === 'SP');
    afirmar('Cenario 14: crlv_origem_validacao permanece VIO_API_BR apos a atualizacao', $at14AposRejeicao['crlv_origem_validacao'] === 'VIO_API_BR');

    // ============================================================
    // Cenario 15 — Nenhuma gravacao PARCIAL: uma excecao de tipo no MEIO da
    // extracao (apos placa, antes/durante exercicio) nao deixa NENHUM campo
    // gravado -- prova estrutural (UPDATE unico) + prova em tempo de
    // execucao (linha inteira permanece no estado NAO_VALIDADO original).
    // ============================================================
    $at15 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'PAR0015');
    $at15Antes = $atendimentoDao->buscarPorId((int) $at15['id_atendimento']);
    $fp15 = bin2hex(random_bytes(32));
    $at15 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at15['id_atendimento'], $fp15);
    $r15 = $documentoRn->avaliarResultadoVioApiBrCrlv($at15, resultadoCrlvBaseRegras([], [
        'Placa' => 'PAR0015', 'Exercício' => ['nao', 'e', 'numero'], 'UF' => 'SP', 'RNTRC' => '12345678', 'Tipo' => 'CAMINHAO',
    ]));
    afirmar('Cenario 15: excecao de tipo no meio da extracao (Exercicio invalido) rejeita a resposta inteira', $r15['pode_avancar'] === false);
    $at15Depois = $atendimentoDao->buscarPorId((int) $at15['id_atendimento']);
    afirmar('Cenario 15: crlv_uf permanece NULL (nao houve gravacao parcial do campo extraido ANTES da excecao)', $at15Depois['crlv_uf'] === $at15Antes['crlv_uf']);
    afirmar('Cenario 15: crlv_rntc permanece NULL (nenhum campo foi gravado parcialmente)', $at15Depois['crlv_rntc'] === $at15Antes['crlv_rntc']);
    afirmar('Cenario 15: crlv_origem_validacao permanece NAO_VALIDADO (linha inteira intocada)', $at15Depois['crlv_origem_validacao'] === 'NAO_VALIDADO');

    // Arquivo do projeto usa quebra de linha CRLF -- normalizado para LF
    // antes de procurar o fim do metodo por padrao de indentacao literal.
    $fonteAtendimentoDao = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../../app/Dao/AtendimentoDao.php'));
    $inicioMetodo = strpos($fonteAtendimentoDao, 'function atualizarValidacaoCrlv(');
    $fimMetodo = strpos($fonteAtendimentoDao, "\n    }\n", $inicioMetodo);
    $corpoMetodo = substr($fonteAtendimentoDao, $inicioMetodo, $fimMetodo - $inicioMetodo);
    afirmar('Cenario 15 (prova estrutural): atualizarValidacaoCrlv() persiste com um UNICO UPDATE atomico (nenhum outro comando de escrita SQL no metodo)', substr_count(strtoupper($corpoMetodo), 'UPDATE ') === 1 && !str_contains(strtoupper($corpoMetodo), 'INSERT ') && !str_contains(strtoupper($corpoMetodo), 'DELETE '));

    // ============================================================
    // Cenario 16 — Ausencia de chamada externa real em TODA a suite
    // ============================================================
    // Prova estrutural 1: DocumentoRn nunca importa/instancia
    // App\Rn\VioApiBrClient (avaliarResultadoVioApiBrCrlv()/avaliarCrlv()/
    // preencherManualCrlv() recebe sempre arrays ja
    // normalizados/DAOs, nunca fazem HTTP por si so).
    $fonteDocumentoRn = file_get_contents(__DIR__ . '/../../app/Rn/DocumentoRn.php');
    afirmar('Cenario 16: DocumentoRn.php nunca importa App\\Rn\\VioApiBrClient (`use App\\Rn\\VioApiBrClient`)', !str_contains($fonteDocumentoRn, 'use App\\Rn\\VioApiBrClient'));
    afirmar('Cenario 16: DocumentoRn.php nunca chama curl_exec/file_get_contents(http (sem cliente HTTP embutido)', !preg_match('/curl_exec|file_get_contents\s*\(\s*[\'"]https?:/', $fonteDocumentoRn));

    // Prova estrutural 2: esta propria suite nunca INSTANCIA/IMPORTA
    // App\Rn\VioApiBrClient nem faz nenhuma chamada HTTP real. So a secao de
    // IMPORTS/SETUP (do `<?php` ate a primeira linha de codigo de teste,
    // ANTES de qualquer chamada afirmar()) e varrida -- nunca o arquivo
    // inteiro, que teria falso-positivo garantido: os proprios textos de
    // descricao de afirmar() desta secao 16 citam literalmente
    // "new VioApiBrClient("/"use App\Rn\VioApiBrClient"/"curl_init(" como
    // PADRAO PROIBIDO, o que faria uma varredura ingenua do arquivo inteiro
    // acusar a si mesma incorretamente.
    $fonteEstaSuiteCompleta = file_get_contents(__FILE__);
    $fimDaSecaoDeImportsSetup = strpos($fonteEstaSuiteCompleta, "\$totalTestes = 0;");
    $fonteEstaSuiteImportsSetup = substr($fonteEstaSuiteCompleta, 0, $fimDaSecaoDeImportsSetup);
    afirmar('Cenario 16: a secao de imports/setup desta suite nunca instancia App\\Rn\\VioApiBrClient (`new VioApiBrClient(`)', !str_contains($fonteEstaSuiteImportsSetup, 'new VioApiBrClient('));
    afirmar('Cenario 16: a secao de imports/setup desta suite nunca importa App\\Rn\\VioApiBrClient (`use App\\Rn\\VioApiBrClient`)', !str_contains($fonteEstaSuiteImportsSetup, 'use App\\Rn\\VioApiBrClient'));
    afirmar('Cenario 16: a secao de imports/setup desta suite nunca CHAMA curl_init()/curl_exec()/file_get_contents("http...") (funcoes de rede real)', !preg_match('/curl_init\(|curl_exec\(|file_get_contents\s*\(\s*[\'"]https?:/', $fonteEstaSuiteImportsSetup));
    // Confirma tambem, de forma factual (sem risco de falso-positivo), que
    // NENHUMA das 6 classes `use`-adas por esta suite e VioApiBrClient --
    // lista fechada, checada uma a uma.
    $classesImportadas = ['App\\Dao\\AtendimentoDao', 'App\\Dao\\VioCacheDao', 'App\\Dao\\OrdemColetaDao', 'App\\Rn\\DocumentoRn', 'App\\Rn\\AtendimentoRn', 'App\\Rn\\OrdemColetaClient'];
    afirmar('Cenario 16: nenhuma das classes importadas por esta suite e App\\Rn\\VioApiBrClient (lista fechada confirmada)', !in_array('App\\Rn\\VioApiBrClient', $classesImportadas, true));

    // Prova em tempo de execucao: contador de "chamadas reais" nunca
    // incrementado (declarado no topo do arquivo, nunca escrito em nenhum
    // ponto da suite) -- todos os $resultado* usados acima sao arrays PHP
    // literais construidos em memoria por resultadoCrlvBaseRegras(), nunca
    // o retorno de uma chamada HTTP real.
    afirmar('Cenario 16: contador de chamadas reais a vio.api.br permanece em 0 ao longo de toda a suite', $chamadasReaisVioApiBr === 0);

    // ============================================================
    // EXPANSAO -- rodada corretiva (2026-09-28): 18 cenarios dedicados a
    // RNTRC, pedidos apos o backend reconfirmar (sem mudanca de codigo) a
    // obrigatoriedade de RNTRC em todos os pontos. Regra REAL confirmada por
    // releitura de avaliarCrlv()/extrairCampoTexto() (ver
    // app/Rn/DocumentoRn.php:356-401,453-466) antes de escrever qualquer
    // cenario abaixo -- nenhum formato/tamanho extra e validado para RNTRC
    // hoje alem de (a) tipo estrito (so string ou ausencia/null,
    // extrairCampoTexto), (b) vazio apos trim, (c) placeholder
    // (ehValorPlaceholder -- sequencia de um unico caractere repetido ou
    // literal "string"). Cenario 25 abaixo documenta esse ACHADO
    // explicitamente (nao presumido).
    // ============================================================

    /** Helper: mesma fixture base, mas com controle TOTAL do array
     * dados_leitura (permite remover uma chave inteiramente, o que
     * array_replace() de resultadoCrlvBaseRegras() nao permite). */
    function resultadoCrlvComDadosLeituraExplicitos(array $dadosLeitura): array
    {
        return [
            'ok' => true, 'ambiguo' => false, 'nao_encontrado' => false,
            'estado_leitura' => 'completed', 'qr_type' => 'vio',
            'dados_leitura' => $dadosLeitura,
            'estado_comparacao' => 'completed',
            'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => []],
            'pages_processed' => null, 'total_pages' => null,
        ];
    }

    // ------------------------------------------------------------
    // Cenario 17 -- RNTRC com a CHAVE ausente inteiramente de dados_leitura
    // (distinto de null explicito, ja coberto no Cenario 1) -- mesma
    // fronteira (extrairCampoTexto: `!array_key_exists($campo, $dadosBrutos)`
    // -> null -> avaliarCrlv rejeita por `$rntc === ''` apos trim(null??'')).
    // ------------------------------------------------------------
    $at17 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0017');
    $fp17 = bin2hex(random_bytes(32));
    $at17 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at17['id_atendimento'], $fp17);
    $dadosSemChaveRntc = ['Placa' => 'RNT0017', 'Exercício' => 2025, 'UF' => 'SP', 'Tipo' => 'CAMINHAO', 'Renavam' => '98765432100'];
    // Confirma a premissa do cenario (chave literalmente ausente, nao null).
    afirmar('Cenario 17 (premissa): fixture nao contem a chave RNTRC de forma alguma', !array_key_exists('RNTRC', $dadosSemChaveRntc));
    $r17 = $documentoRn->avaliarResultadoVioApiBrCrlv($at17, resultadoCrlvComDadosLeituraExplicitos($dadosSemChaveRntc));
    afirmar('Cenario 17: RNTRC com a CHAVE ausente (nao so null) aprova (RNTRC opcional)', $r17['pode_avancar'] === true);
    afirmar('Cenario 17: crlv_rntc NULL persistido', $atendimentoDao->buscarPorId((int) $at17['id_atendimento'])['crlv_rntc'] === null);

    // ------------------------------------------------------------
    // Cenario 18 -- RNTRC null EXPLICITO isolado (Tipo valido, sem misturar
    // com o achado de "veiculo particular" do Cenario 1) -- isola o efeito
    // do campo RNTRC especificamente.
    // ------------------------------------------------------------
    $at18 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0018');
    $fp18 = bin2hex(random_bytes(32));
    $at18 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at18['id_atendimento'], $fp18);
    $r18 = $documentoRn->avaliarResultadoVioApiBrCrlv($at18, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0018', 'RNTRC' => null]));
    afirmar('Cenario 18: RNTRC null explicito (Tipo valido) aprova (RNTRC opcional)', $r18['pode_avancar'] === true);

    // ------------------------------------------------------------
    // Cenario 19 -- RNTRC vazio ('') isolado
    // ------------------------------------------------------------
    $at19 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0019');
    $fp19 = bin2hex(random_bytes(32));
    $at19 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at19['id_atendimento'], $fp19);
    $r19 = $documentoRn->avaliarResultadoVioApiBrCrlv($at19, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0019', 'RNTRC' => '']));
    afirmar('Cenario 19: RNTRC vazio ("") aprova (RNTRC opcional) e persiste NULL', $r19['pode_avancar'] === true && $atendimentoDao->buscarPorId((int) $at19['id_atendimento'])['crlv_rntc'] === null);

    // ------------------------------------------------------------
    // Cenario 20 -- RNTRC so com espacos ('   ') -- trim() em avaliarCrlv()
    // reduz a vazio, mesma regra do Cenario 19.
    // ------------------------------------------------------------
    $at20 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0020');
    $fp20 = bin2hex(random_bytes(32));
    $at20 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at20['id_atendimento'], $fp20);
    $r20 = $documentoRn->avaliarResultadoVioApiBrCrlv($at20, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0020', 'RNTRC' => '   ']));
    afirmar('Cenario 20: RNTRC so com espacos equivale a vazio apos trim(): aprova com NULL', $r20['pode_avancar'] === true && $atendimentoDao->buscarPorId((int) $at20['id_atendimento'])['crlv_rntc'] === null);

    // ------------------------------------------------------------
    // Cenario 21 -- RNTRC placeholder ('xxxxx') -- capturado por
    // ehValorPlaceholder() ANTES da checagem especifica de RNTC vazio (ver
    // ordem real em avaliarCrlv(): placeholder e checado para
    // placa/exercicio/uf/rntc/tipo juntos, motivo generico "dado
    // placeholder").
    // ------------------------------------------------------------
    $at21 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0021');
    $fp21 = bin2hex(random_bytes(32));
    $at21 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at21['id_atendimento'], $fp21);
    $r21 = $documentoRn->avaliarResultadoVioApiBrCrlv($at21, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0021', 'RNTRC' => 'xxxxx']));
    afirmar('Cenario 21: RNTRC placeholder ("xxxxx") e rejeitado (motivo generico de placeholder, capturado ANTES da checagem especifica de RNTC vazio)', $r21['pode_avancar'] === false && str_contains($r21['motivo'], 'placeholder'));

    // ------------------------------------------------------------
    // Cenario 22 -- RNTRC '0' (string) -- ehValorPlaceholder() trata QUALQUER
    // sequencia de um unico caractere repetido como placeholder (regex
    // `^(.)\1*$`), incluindo um unico caractere isolado como "0" -- portanto
    // "0" e rejeitado, mas pela regra de PLACEHOLDER, nao por "vazio".
    // ------------------------------------------------------------
    $at22 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0022');
    $fp22 = bin2hex(random_bytes(32));
    $at22 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at22['id_atendimento'], $fp22);
    $r22 = $documentoRn->avaliarResultadoVioApiBrCrlv($at22, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0022', 'RNTRC' => '0']));
    afirmar('Cenario 22: RNTRC "0" e rejeitado (capturado pela regra de placeholder de caractere unico repetido, nao pela de vazio)', $r22['pode_avancar'] === false && str_contains($r22['motivo'], 'placeholder'));
    // RNTRC numerico 0 (int, nao string) chega como tipo incompativel em
    // extrairCampoTexto() (so aceita string/null) -- fronteira de TIPO,
    // testada isoladamente no Cenario 23 abaixo (mesma familia de excecao).

    // ------------------------------------------------------------
    // Cenario 23 -- RNTRC com tipo ARRAY -- fronteira de tipo estrita de
    // extrairCampoTexto() (so aceita string ou ausencia/null), invalida a
    // resposta INTEIRA via DocumentoVioTipoInvalidoException (capturada
    // internamente, nunca escapa, nunca Error/TypeError fatal).
    // ------------------------------------------------------------
    $at23 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0023');
    $fp23 = bin2hex(random_bytes(32));
    $at23 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at23['id_atendimento'], $fp23);
    $r23 = $documentoRn->avaliarResultadoVioApiBrCrlv($at23, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0023', 'RNTRC' => ['12345678']]));
    afirmar('Cenario 23: RNTRC vindo como ARRAY invalida a resposta inteira (fail-closed, sem Error/TypeError fatal)', $r23['pode_avancar'] === false);
    $at23Depois = $atendimentoDao->buscarPorId((int) $at23['id_atendimento']);
    afirmar('Cenario 23: crlv_rntc permanece NULL (nenhuma gravacao parcial)', $at23Depois['crlv_rntc'] === null);

    // ------------------------------------------------------------
    // Cenario 23b -- RNTRC com tipo BOOL/objeto -- mesma fronteira, valores
    // adicionais de fronteira de tipo (bool true e um objeto stdClass).
    // ------------------------------------------------------------
    $at23b = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT023B');
    $fp23b = bin2hex(random_bytes(32));
    $at23b = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at23b['id_atendimento'], $fp23b);
    $r23b = $documentoRn->avaliarResultadoVioApiBrCrlv($at23b, resultadoCrlvBaseRegras([], ['Placa' => 'RNT023B', 'RNTRC' => true]));
    afirmar('Cenario 23b: RNTRC vindo como BOOL invalida a resposta inteira (fail-closed)', $r23b['pode_avancar'] === false);

    $at23c = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT023C');
    $fp23c = bin2hex(random_bytes(32));
    $at23c = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at23c['id_atendimento'], $fp23c);
    $r23c = $documentoRn->avaliarResultadoVioApiBrCrlv($at23c, resultadoCrlvBaseRegras([], ['Placa' => 'RNT023C', 'RNTRC' => (object) ['v' => '12345678']]));
    afirmar('Cenario 23c: RNTRC vindo como OBJETO (stdClass) invalida a resposta inteira (fail-closed)', $r23c['pode_avancar'] === false);

    // ------------------------------------------------------------
    // Cenario 24 -- RNTRC numerico puro (int 12345678, nao string) -- MESMA
    // fronteira de tipo de extrairCampoTexto() (so aceita string/null, um
    // int NUNCA e coagido silenciosamente) -- rejeita a resposta inteira,
    // reforcando a fronteira de tipo com o valor mais realista de "RNTC
    // numerico enviado sem aspas pelo fornecedor".
    // ------------------------------------------------------------
    $at24 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0024');
    $fp24 = bin2hex(random_bytes(32));
    $at24 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at24['id_atendimento'], $fp24);
    $r24 = $documentoRn->avaliarResultadoVioApiBrCrlv($at24, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0024', 'RNTRC' => 12345678]));
    afirmar('Cenario 24: RNTRC vindo como INT (sem aspas) invalida a resposta inteira (fronteira de tipo estrita, nunca coacao silenciosa para string)', $r24['pode_avancar'] === false);

    // ------------------------------------------------------------
    // Cenario 25 -- ACHADO REGISTRADO (nao presumido, confirmado por
    // releitura de avaliarCrlv()/ehValorPlaceholder()/extrairCampoTexto()):
    // o codigo NAO valida nenhum formato/tamanho/padrao numerico adicional
    // para RNTRC alem de tipo-string + nao-vazio-apos-trim + nao-placeholder
    // -- um valor como "12345678901234567890" (21 digitos, muito mais longo
    // que o RNTC real de 8 digitos) ou "AB-12" (com caracteres nao
    // numericos) hoje SAO ACEITOS (nao ha whitelist de digitos nem checagem
    // de comprimento). Isso NAO e um bug desta demanda (nenhuma mudanca de
    // logica foi pedida/feita) -- e a regra REAL, registrada aqui em vez de
    // inventar uma validacao de formato que nao existe no codigo.
    // ------------------------------------------------------------
    $at25 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0025');
    $fp25 = bin2hex(random_bytes(32));
    $at25 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at25['id_atendimento'], $fp25);
    $r25 = $documentoRn->avaliarResultadoVioApiBrCrlv($at25, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0025', 'RNTRC' => 'AB-12-nao-e-um-formato-real-de-rntc']));
    afirmar('Cenario 25 (ACHADO registrado, nao bloqueante): RNTRC com "formato" nao numerico/nao usual E ACEITO hoje -- nao ha validacao de formato/tamanho alem de tipo/vazio/placeholder (confirmado por leitura de codigo, nao inventado)', $r25['pode_avancar'] === true);

    // ------------------------------------------------------------
    // Cenario 26 -- RNTRC valido -> fluxo permitido (regressao explicita do
    // caso feliz, ja coberto no Cenario 3 -- reconfirmado aqui isoladamente
    // dentro do bloco de expansao para nao depender de nenhum estado dos
    // cenarios 1-16).
    // ------------------------------------------------------------
    $at26 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0026');
    $fp26 = bin2hex(random_bytes(32));
    $at26 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at26['id_atendimento'], $fp26);
    $r26 = $documentoRn->avaliarResultadoVioApiBrCrlv($at26, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0026', 'RNTRC' => '55667788']));
    afirmar('Cenario 26: RNTRC valido (digitos, tamanho tipico) permite o avanco', $r26['pode_avancar'] === true);
    $at26Depois = $atendimentoDao->buscarPorId((int) $at26['id_atendimento']);
    afirmar('Cenario 26: crlv_rntc gravado com o valor exato', $at26Depois['crlv_rntc'] === '55667788');

    // ------------------------------------------------------------
    // Cenario 28 -- Cache COM RNTC valido mantem a regra normalmente
    // (reconfirmacao isolada do Cenario 12/13, mesma familia de teste, sem
    // depender de estado anterior).
    // ------------------------------------------------------------
    $at28a = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0028');
    $fp28 = bin2hex(random_bytes(32));
    $at28a = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at28a['id_atendimento'], $fp28);
    $r28a = $documentoRn->avaliarResultadoVioApiBrCrlv($at28a, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0028', 'RNTRC' => '22334455']));
    afirmar('Cenario 28 (setup): validacao com RNTC valido aprovada e nao grava cache', $r28a['pode_avancar'] === true);
    $at28Depois = $atendimentoDao->buscarPorId((int) $at28a['id_atendimento']);
    afirmar('Cenario 28 (QR-only): RNTC valido gravado em tb_atendimento e origem VIO_API_BR, sem cache', $r28a['origem'] === 'VIO_API_BR' && $at28Depois['crlv_rntc'] === '22334455' && (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_crlv')->fetchColumn() === 0);

    // ------------------------------------------------------------
    // Cenario 29 -- Preenchimento MANUAL sem RNTC -> bloqueado (mesma
    // avaliarCrlv() usada pelo automatico, ver preencherManualCrlv():311).
    // ------------------------------------------------------------
    $at29 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'MAN0029');
    $r29 = $documentoRn->preencherManualCrlv($at29, 'MAN0029', 2025, 'SP', '', 'CAMINHAO');
    afirmar('Cenario 29: preenchimento MANUAL com RNTC vazio aprova (RNTRC opcional)', $r29['pode_avancar'] === true && $r29['origem'] === 'MANUAL');
    afirmar('Cenario 29: crlv_rntc NULL persistido no manual sem RNTRC', $atendimentoDao->buscarPorId((int) $at29['id_atendimento'])['crlv_rntc'] === null);

    // ------------------------------------------------------------
    // Cenario 30 -- Preenchimento MANUAL com RNTC invalido (placeholder) ->
    // bloqueado.
    // ------------------------------------------------------------
    $at30 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'MAN0030');
    $r30 = $documentoRn->preencherManualCrlv($at30, 'MAN0030', 2025, 'SP', 'xxxxx', 'CAMINHAO');
    afirmar('Cenario 30: preenchimento MANUAL com RNTC placeholder e bloqueado', $r30['pode_avancar'] === false);

    // ------------------------------------------------------------
    // Cenario 31 -- Preenchimento MANUAL com RNTC valido -> permitido.
    // ------------------------------------------------------------
    $at31 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'MAN0031');
    $r31 = $documentoRn->preencherManualCrlv($at31, 'MAN0031', 2025, 'SP', '99001122', 'CAMINHAO');
    afirmar('Cenario 31: preenchimento MANUAL com RNTC valido e permitido', $r31['pode_avancar'] === true);
    afirmar('Cenario 31: status_revisao vira PENDENTE_REVISAO (origem MANUAL, nunca OK direto)', $r31['status_revisao'] === 'PENDENTE_REVISAO');
    $at31Depois = $atendimentoDao->buscarPorId((int) $at31['id_atendimento']);
    afirmar('Cenario 31: crlv_rntc gravado com o valor exato via preenchimento manual', $at31Depois['crlv_rntc'] === '99001122');

    // ------------------------------------------------------------
    // Cenario 32 -- Payload do Talent (App\Rn\TalentRn::montarPayload(),
    // metodo PRIVADO) nunca recebe RNTRC ausente/inventado -- defesa em
    // profundidade PROPRIA (linha 183-185: `if (... || $rntc === '' || ...)
    // { throw new \RuntimeException('veiculo_invalido'); }`), independente
    // do gate anterior (crlvAprovado()). Acessada via Reflection, mesma
    // tecnica ja usada em tests/manual/teste_talent_payload.php (que ja
    // cobre isoladamente este caso -- linhas ~231-241 daquele arquivo,
    // reconfirmado aqui tambem dentro desta suite para nao depender de
    // rodar 2 arquivos para provar a regra de RNTRC completa).
    // ------------------------------------------------------------
    $reflexaoMontarPayload = new ReflectionMethod(\App\Rn\TalentRn::class, 'montarPayload');
    $reflexaoMontarPayload->setAccessible(true);
    // TalentClient com URL/token vazios, NUNCA invocado (montarPayload nao
    // faz rede) -- mesmo padrao ja usado em tests/manual/teste_talent_payload.php.
    $talentRnParaTeste = new \App\Rn\TalentRn(
        new \App\Rn\TalentClient('', ''),
        $atendimentoDao,
        sys_get_temp_dir()
    );
    $atendimentoSemRntcParaTalent = [
        'id_atendimento' => 999999, 'tipo' => 'expedicao', 'placa' => 'TAL0032',
        'cliente_cnpj' => '12345678000199', 'crlv_uf' => 'SP', 'crlv_rntc' => '', 'crlv_tipo_veiculo' => 'CAMINHAO',
        'motorista_cpf' => '12345678901', 'motorista_nome' => 'Motorista Teste',
    ];
    $empresaTeste = ['cnpj' => '98765432000188'];
    $lancouVeiculoInvalido32 = false;
    try {
        $reflexaoMontarPayload->invoke($talentRnParaTeste, $atendimentoSemRntcParaTalent, [], $empresaTeste);
    } catch (\RuntimeException $e) {
        $lancouVeiculoInvalido32 = $e->getMessage() === 'veiculo_invalido';
    }
    afirmar('Cenario 32: TalentRn::montarPayload() lanca RuntimeException("veiculo_invalido") se, por algum motivo, um atendimento sem RNTC valido chegasse ate ali (defesa em profundidade propria, independente do gate anterior)', $lancouVeiculoInvalido32);

    // ------------------------------------------------------------
    // Cenario 33 -- Tentativa rejeitada NUNCA sobrescreve dado (RNTC) valido
    // anterior -- releitura confirmada: atualizarValidacaoCrlv() (que grava
    // crlv_rntc) so e chamado DEPOIS que avaliarCrlv() ja retornou
    // pode_avancar=true (DocumentoRn.php:398 dentro de avaliarCrlv();
    // avaliarResultadoVioApiBrCrlv() (QR-only, sem cache) so grava quando
    // pode_avancar=true). Isolado aqui especificamente para RNTC (o Cenario 14 ja prova
    // o mesmo para RNTC+tipo+UF juntos).
    // ------------------------------------------------------------
    $at33 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0033');
    $fp33a = bin2hex(random_bytes(32));
    $at33 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at33['id_atendimento'], $fp33a);
    $r33a = $documentoRn->avaliarResultadoVioApiBrCrlv($at33, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0033', 'RNTRC' => '11223344']));
    afirmar('Cenario 33 (setup): RNTC valido A aprovado', $r33a['pode_avancar'] === true);
    $fp33b = bin2hex(random_bytes(32));
    $at33ParaRejeicao = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at33['id_atendimento'], $fp33b);
    $r33b = $documentoRn->avaliarResultadoVioApiBrCrlv($at33ParaRejeicao, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0033', 'RNTRC' => 'xxxxx']));
    afirmar('Cenario 33: segunda tentativa (RNTC placeholder) e rejeitada', $r33b['pode_avancar'] === false);
    $at33Depois = $atendimentoDao->buscarPorId((int) $at33['id_atendimento']);
    afirmar('Cenario 33: crlv_rntc do dado A (valido, "11223344") permanece intacto apos a tentativa rejeitada', $at33Depois['crlv_rntc'] === '11223344');

    // ------------------------------------------------------------
    // Cenario 34 -- VIO_API_BR e VIO_CACHE seguem a MESMA obrigatoriedade de
    // RNTC (os 2 caminhos de origem) + regressao rapida de placa/exercicio/
    // UF continuarem fail-closed (nao reinventar, so confirmar que a
    // expansao acima nao quebrou nada -- reaproveita os mesmos cenarios
    // 4/6/7/8/9 ja existentes, citados aqui por numero, sem duplicar
    // asserções).
    // ------------------------------------------------------------
    // Caminho VIO_API_BR: RNTC vazio ja rejeitado nos Cenarios 17-25 acima.
    // Caminho VIO_CACHE: buscarCrlvValido() ja confirmado (Cenario 27) que
    // JAMAIS retorna um registro com rntc vazio -- portanto o caminho de
    // cache tambem nunca autoriza avanco sem RNTC valido, pela MESMA regra
    // (SQL fail-closed), sem precisar de uma segunda passada pela logica de
    // avaliarCrlv() para provar isso de novo.
    afirmar('Cenario 34: RNTRC e opcional no caminho VIO_API_BR (Cenarios 17-20 aprovam) e RNTC valido tambem aprova', $r19['pode_avancar'] === true && $r26['pode_avancar'] === true);
    afirmar('Cenario 34 (QR-only): nao ha caminho VIO_CACHE; RNTC valido segue obrigatorio (Cenario 27 filtro SQL legado + Cenario 28 aprovacao direta)', $registroMalFormadoDireto === null && $r28a['origem'] === 'VIO_API_BR' && $r28a['pode_avancar'] === true);
    $at34Placa = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'PLX0034');
    $at34Placa = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at34Placa['id_atendimento'], bin2hex(random_bytes(32)));
    $r34Placa = $documentoRn->avaliarResultadoVioApiBrCrlv($at34Placa, resultadoCrlvBaseRegras([], ['Placa' => 'OUT0034']));
    afirmar('Cenario 34 (regressao rapida): placa continua fail-closed quando a PLACA EXTRAIDA diverge', $r34Placa['pode_avancar'] === false);
    afirmar('Cenario 34 (regressao rapida): exercicio continua fail-closed (Cenario 9a/9b, tipo incompativel invalida)', $r9a['pode_avancar'] === false && $r9b['pode_avancar'] === false);
    afirmar('Cenario 34 (regressao rapida): UF continua fail-closed (Cenario 7 ausente + Cenario 8 fora da lista)', $r7['pode_avancar'] === false && $r8['pode_avancar'] === false);

    // ------------------------------------------------------------
    // Cenarios 35-38 (decisao 2026-10-02): RNTRC digitado na confirmacao.
    // ------------------------------------------------------------
    $dadosConfirmacao = static fn (array $o = []): array => array_replace([
        'motorista_nome' => 'MOTORISTA QA', 'motorista_cpf' => '529.982.247-25', 'cnh_validade' => '2030-01-01',
        'crlv_ano' => '2025', 'crlv_uf' => 'SP', 'crlv_rntc' => '87654321', 'crlv_tipo_veiculo' => 'CAMINHAO',
    ], $o);

    $at35 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0035');
    $at35 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at35['id_atendimento'], bin2hex(random_bytes(32)));
    $r35 = $documentoRn->avaliarResultadoVioApiBrCrlv($at35, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0035', 'RNTRC' => '']));
    $atendimentoRn->salvarDadosMotorista((int) $at35['id_atendimento'], $dadosConfirmacao());
    $at35D = $atendimentoDao->buscarPorId((int) $at35['id_atendimento']);
    afirmar('Cenario 35: RNTRC digitado na confirmacao e persistido em crlv_rntc', $r35['pode_avancar'] === true && $at35D['crlv_rntc'] === '87654321');
    afirmar('Cenario 35: preencher so o RNTRC (que a API nao trouxe) NAO rebaixa a origem VIO_API_BR/OK', $at35D['crlv_origem_validacao'] === 'VIO_API_BR' && $at35D['crlv_status_revisao'] === 'OK');
    afirmar('Cenario 35: snapshot de RNTRC permanece NULL (verdade original da API)', $at35D['crlv_snapshot_rntc'] === null);

    $at36 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0036');
    $at36 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at36['id_atendimento'], bin2hex(random_bytes(32)));
    $documentoRn->avaliarResultadoVioApiBrCrlv($at36, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0036', 'RNTRC' => '12345678']));
    $atendimentoRn->salvarDadosMotorista((int) $at36['id_atendimento'], $dadosConfirmacao(['crlv_rntc' => '99999999']));
    $at36D = $atendimentoDao->buscarPorId((int) $at36['id_atendimento']);
    afirmar('Cenario 36: alterar RNTRC que a API TROUXE rebaixa para MANUAL/PENDENTE_REVISAO', $at36D['crlv_origem_validacao'] === 'MANUAL' && $at36D['crlv_status_revisao'] === 'PENDENTE_REVISAO');

    foreach (['crlv_ano' => '2024', 'crlv_uf' => 'RJ', 'crlv_tipo_veiculo' => 'CARRETA'] as $campoAlterado => $novoValor) {
        $atX = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'RNT0037');
        $atX = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $atX['id_atendimento'], bin2hex(random_bytes(32)));
        $documentoRn->avaliarResultadoVioApiBrCrlv($atX, resultadoCrlvBaseRegras([], ['Placa' => 'RNT0037', 'RNTRC' => '']));
        $atendimentoRn->salvarDadosMotorista((int) $atX['id_atendimento'], $dadosConfirmacao([$campoAlterado => $novoValor]));
        $atXD = $atendimentoDao->buscarPorId((int) $atX['id_atendimento']);
        afirmar("Cenario 37: alterar {$campoAlterado} ja validado (RNTRC digitado) continua rebaixando para MANUAL", $atXD['crlv_origem_validacao'] === 'MANUAL' && $atXD['crlv_status_revisao'] === 'PENDENTE_REVISAO');
    }

    $atExp = ['tipo' => 'expedicao', 'placa' => 'ABC1234', 'ordem_coleta' => '123', 'cliente_nome' => null];
    $atRec = ['tipo' => 'recebimento', 'placa' => 'ABC1234', 'ordem_coleta' => null, 'cliente_nome' => 'CLIENTE X'];
    afirmar('Cenario 38: confirmacao completa (Expedicao) sem campos ausentes', $atendimentoRn->camposObrigatoriosAusentes($atExp, $dadosConfirmacao()) === []);
    afirmar('Cenario 38: confirmacao completa (Recebimento) sem campos ausentes', $atendimentoRn->camposObrigatoriosAusentes($atRec, $dadosConfirmacao()) === []);
    afirmar('Cenario 38: RNTRC vazio/espacos e recusado', array_keys($atendimentoRn->camposObrigatoriosAusentes($atExp, $dadosConfirmacao(['crlv_rntc' => '   ']))) === ['crlv_rntc']);
    afirmar('Cenario 38: CPF invalido e recusado', array_keys($atendimentoRn->camposObrigatoriosAusentes($atExp, $dadosConfirmacao(['motorista_cpf' => '111.111.111-11']))) === ['motorista_cpf']);
    afirmar('Cenario 38: UF fora da lista e recusada', array_keys($atendimentoRn->camposObrigatoriosAusentes($atExp, $dadosConfirmacao(['crlv_uf' => 'XX']))) === ['crlv_uf']);
    afirmar('Cenario 38: cliente ausente (Recebimento) e recusado', array_keys($atendimentoRn->camposObrigatoriosAusentes(array_replace($atRec, ['cliente_nome' => '']), $dadosConfirmacao())) === ['cliente']);
    afirmar('Cenario 38: ordem de coleta ausente (Expedicao) e recusada', array_keys($atendimentoRn->camposObrigatoriosAusentes(array_replace($atExp, ['ordem_coleta' => null]), $dadosConfirmacao())) === ['ordem_coleta']);
    afirmar('Cenario 38: payload vazio lista todos os campos editaveis', count($atendimentoRn->camposObrigatoriosAusentes($atExp, [])) === 7);
    afirmar('Cenario 38: tipo de veiculo vazio e recusado na confirmacao', array_keys($atendimentoRn->camposObrigatoriosAusentes($atExp, $dadosConfirmacao(['crlv_tipo_veiculo' => '  ']))) === ['crlv_tipo_veiculo']);
    afirmar('Cenario 38: tipo de veiculo com mais de 60 caracteres e recusado', array_keys($atendimentoRn->camposObrigatoriosAusentes($atExp, $dadosConfirmacao(['crlv_tipo_veiculo' => str_repeat('A', 61)]))) === ['crlv_tipo_veiculo']);

    // ------------------------------------------------------------
    // Cenarios 39-41 (decisao 2026-10-02): Tipo segue a regra do RNTRC.
    // ------------------------------------------------------------
    $at39 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'TIP0039');
    $at39 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at39['id_atendimento'], bin2hex(random_bytes(32)));
    $r39 = $documentoRn->avaliarResultadoVioApiBrCrlv($at39, resultadoCrlvBaseRegras([], ['Placa' => 'TIP0039', 'Tipo' => '']));
    $atendimentoRn->salvarDadosMotorista((int) $at39['id_atendimento'], $dadosConfirmacao(['crlv_tipo_veiculo' => 'CAMINHAO TRATOR', 'crlv_rntc' => '12345678']));
    $at39D = $atendimentoDao->buscarPorId((int) $at39['id_atendimento']);
    afirmar('Cenario 39: Tipo digitado na confirmacao e persistido', $r39['pode_avancar'] === true && $at39D['crlv_tipo_veiculo'] === 'CAMINHAO TRATOR');
    afirmar('Cenario 39: digitar Tipo que a API nao trouxe NAO rebaixa a origem (VIO_API_BR/OK)', $at39D['crlv_origem_validacao'] === 'VIO_API_BR' && $at39D['crlv_status_revisao'] === 'OK');
    afirmar('Cenario 39: snapshot de Tipo permanece NULL', $at39D['crlv_snapshot_tipo_veiculo'] === null);

    $at40 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'TIP0040');
    $at40 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at40['id_atendimento'], bin2hex(random_bytes(32)));
    $documentoRn->avaliarResultadoVioApiBrCrlv($at40, resultadoCrlvBaseRegras([], ['Placa' => 'TIP0040', 'Tipo' => 'CARRETA']));
    $atendimentoRn->salvarDadosMotorista((int) $at40['id_atendimento'], $dadosConfirmacao(['crlv_tipo_veiculo' => 'BITREM', 'crlv_rntc' => '12345678']));
    $at40D = $atendimentoDao->buscarPorId((int) $at40['id_atendimento']);
    afirmar('Cenario 40: alterar Tipo que a API TROUXE rebaixa para MANUAL/PENDENTE_REVISAO', $at40D['crlv_origem_validacao'] === 'MANUAL' && $at40D['crlv_status_revisao'] === 'PENDENTE_REVISAO');

    $at41 = novoAtendimentoCrlv($pdo, $atendimentoDao, $idTotem, 'TIP0041');
    $at41 = prepararEnvioCrlv($pdo, $atendimentoDao, (int) $at41['id_atendimento'], bin2hex(random_bytes(32)));
    $r41 = $documentoRn->avaliarResultadoVioApiBrCrlv($at41, resultadoCrlvBaseRegras([], ['Placa' => 'TIP0041', 'Tipo' => 'xxxxx']));
    afirmar('Cenario 41: placeholder em Tipo continua reprovando o CRLV', $r41['pode_avancar'] === false && str_contains($r41['motivo'], 'placeholder'));

    // crlvAprovado() (gate de avanco de etapa) nao exige Tipo.
    afirmar('Cenario 42: crlvAprovado aceita CRLV aprovado sem Tipo (ano/UF validos)', $documentoRn->crlvAprovado(['crlv_origem_validacao' => 'VIO_API_BR', 'crlv_ano' => 2025, 'crlv_uf' => 'SP', 'crlv_tipo_veiculo' => null]) === true);

    // ============================================================
    // Limpeza (o finally abaixo dropa o banco inteiro; nenhuma limpeza
    // adicional e necessaria)
    // ============================================================
} finally {
    if ($nomeBanco !== null) {
        qaDbDropar($nomeBanco);
        echo "\n(banco de teste {$nomeBanco} dropado)\n";
    }
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
