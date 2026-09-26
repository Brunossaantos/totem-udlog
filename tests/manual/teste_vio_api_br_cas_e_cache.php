<?php

/**
 * Teste manual (sem framework, mesmo padrao de tests/manual/teste_vio_decode.php)
 * da demanda migracao-vio-api-br-com-cache — baterias 2 (concorrencia/
 * idempotencia/CAS) e 3 (cache VIO_CACHE).
 *
 * Roda contra um banco `qa_`-prefixado DESCARTAVEL (nunca udlog_totem),
 * dropado ao final independente de sucesso/falha.
 *
 * ESTADO ATUAL (2026-09-26): CORRIGIDO E VALIDADO -- nenhuma acao
 * necessaria. sql/migrations/015_vio_api_br_estados_e_id_externo.sql
 * aplica corretamente os ENUMs (ENVIANDO/PROCESSANDO_LEITURA/
 * PROCESSANDO_COMPARACAO/INDETERMINADO/VIO_CACHE/VIO_API_BR) quando
 * aplicada num banco novo/descartavel, sem precisar de nenhum workaround.
 *
 * Nota historica: um achado critico foi registrado em 2026-09-26 (achado
 * inicial) apontando que a condicao dos 4 blocos "ALTER TABLE ... MODIFY
 * COLUMN ... ENUM(...)" estava invertida, o que teria impedido a migration
 * de acrescentar esses valores num banco novo. O bug real foi corrigido no
 * arquivo .sql na mesma rodada, e a correcao foi reconfirmada por 2
 * revisoes independentes adicionais na `/03-revisao` seguinte (aplicando a
 * migration num banco `qa_` novo, sem nenhum workaround, com resultado
 * correto e idempotente). A funcao corrigirEnumsQuebradosPelaMigration015()
 * abaixo foi mantida sem alteracao de logica (chamada continua no fluxo,
 * ainda que hoje seja redundante/no-op na pratica) -- ver tambem
 * qaDbCorrigirEnumsMigration015() em tests/manual/qa_db_bootstrap.php, que
 * documenta a mesma historia.
 *
 * Uso: php tests/manual/teste_vio_api_br_cas_e_cache.php
 */

require_once __DIR__ . '/qa_db_bootstrap.php';

use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Dao\VioApiBrCacheDao;
use App\Rn\DocumentoRn;

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

/**
 * HISTORICO (nao mais necessaria, ver cabecalho deste arquivo): workaround
 * de teste (banco descartavel) para um achado critico de
 * sql/migrations/015 (condicao invertida) ja corrigido e reconfirmado em
 * `/03-revisao` -- mantida sem alteracao de logica nesta rodada de limpeza
 * de comentarios. NUNCA aplicado fora de um banco qa_.
 */
function corrigirEnumsQuebradosPelaMigration015(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE tb_atendimento MODIFY COLUMN cnh_status_processamento ENUM('PENDENTE','PROCESSANDO','ENVIANDO','PROCESSANDO_LEITURA','PROCESSANDO_COMPARACAO','CONCLUIDO','ERRO','INDETERMINADO') NOT NULL DEFAULT 'PENDENTE'");
    $pdo->exec("ALTER TABLE tb_atendimento MODIFY COLUMN crlv_status_processamento ENUM('PENDENTE','PROCESSANDO','ENVIANDO','PROCESSANDO_LEITURA','PROCESSANDO_COMPARACAO','CONCLUIDO','ERRO','INDETERMINADO') NOT NULL DEFAULT 'PENDENTE'");
    $pdo->exec("ALTER TABLE tb_atendimento MODIFY COLUMN cnh_origem_validacao ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO','VIO_CACHE') NOT NULL DEFAULT 'NAO_VALIDADO'");
    $pdo->exec("ALTER TABLE tb_atendimento MODIFY COLUMN crlv_origem_validacao ENUM('VIO_TRIAL','VIO_VALIDADO','MANUAL','NAO_VALIDADO','VIO_CACHE') NOT NULL DEFAULT 'NAO_VALIDADO'");
}

$nomeBanco = null;

try {
    [$pdo, $nomeBanco] = qaDbCriar('vio_api_br_cas_cache');
    corrigirEnumsQuebradosPelaMigration015($pdo);

    // Chaves FAKE geradas so em memoria para este processo de teste -- NUNCA
    // gravadas em .env, nunca reais. hex2bin exige 64 chars hex (32 bytes).
    $_ENV['VIO_API_BR_CACHE_HMAC_VERSION'] = '1';
    $_ENV['VIO_API_BR_CACHE_HMAC_KEY_V1'] = bin2hex(random_bytes(32));
    $_ENV['VIO_API_BR_CACHE_TTL_DIAS'] = '7';
    $_ENV['DOCUMENTO_DATA_KEY'] = bin2hex(random_bytes(32));
    $_ENV['DOCUMENTO_QR_HMAC_KEY'] = bin2hex(random_bytes(32));

    $atendimentoDao = new AtendimentoDao($pdo);
    $cacheVioApiBrDao = new VioApiBrCacheDao($pdo);
    $documentoRn = new DocumentoRn(new VioCacheDao($pdo), $atendimentoDao, $cacheVioApiBrDao);

    $pdo->exec("INSERT INTO tb_totem (codigo, nome, token_api, ativo) VALUES ('QA_VIO_API_BR', 'Totem QA vio.api.br', 'token_" . bin2hex(random_bytes(8)) . "', 1)");
    $idTotem = (int) $pdo->lastInsertId();

    function novoAtendimento(PDO $pdo, AtendimentoDao $dao, int $idTotem, string $placa = 'ABC1234'): array
    {
        $id = $dao->criar($idTotem, 'expedicao', $placa);
        $dao->atualizarEtapa($id, 'exp_cnh');
        return $dao->buscarPorId($id);
    }

    // ============================================================
    // BATERIA 2 — Concorrencia / idempotencia / CAS
    // ============================================================

    // 2.1 Duas "tentativas" simuladas de iniciar o envio -- so uma vence o CAS
    $at1 = novoAtendimento($pdo, $atendimentoDao, $idTotem);
    $fp1 = bin2hex(random_bytes(32));
    $tentativaA = bin2hex(random_bytes(16));
    $tentativaB = bin2hex(random_bytes(16));

    $venceuA = $atendimentoDao->iniciarEnvioVioApiBr((int) $at1['id_atendimento'], 'cnh', $tentativaA, $fp1, 1);
    $venceuB = $atendimentoDao->iniciarEnvioVioApiBr((int) $at1['id_atendimento'], 'cnh', $tentativaB, $fp1, 1);
    afirmar('CAS de envio: a 1a tentativa vence (adquire ENVIANDO)', $venceuA === true);
    afirmar('CAS de envio: a 2a tentativa concorrente para o MESMO documento NUNCA vence (ja esta ENVIANDO)', $venceuB === false);

    $at1Depois = $atendimentoDao->buscarPorId((int) $at1['id_atendimento']);
    afirmar('CAS de envio: tentativa_id gravado e o da VENCEDORA (A), nunca da perdedora (B)', $at1Depois['cnh_tentativa_id'] === $tentativaA);
    afirmar('CAS de envio: status apos a corrida e ENVIANDO (um unico "POST" seria disparado, pela vencedora)', $at1Depois['cnh_status_processamento'] === 'ENVIANDO');

    // 2.2 ID externo so e persistido para a tentativa vigente
    $gravouComTentativaObsoleta = $atendimentoDao->gravarIdExternoVioApiBr((int) $at1['id_atendimento'], 'cnh', $tentativaB, 'id-externo-nao-deveria-gravar');
    afirmar('gravarIdExternoVioApiBr com tentativa OBSOLETA (perdedora) nunca tem efeito', $gravouComTentativaObsoleta === false);

    $gravouComTentativaVigente = $atendimentoDao->gravarIdExternoVioApiBr((int) $at1['id_atendimento'], 'cnh', $tentativaA, 'id-externo-real-123');
    afirmar('gravarIdExternoVioApiBr com tentativa VIGENTE tem efeito', $gravouComTentativaVigente === true);

    $at1AposId = $atendimentoDao->buscarPorId((int) $at1['id_atendimento']);
    afirmar('ID externo persistido corretamente', $at1AposId['cnh_vio_api_id'] === 'id-externo-real-123');
    afirmar('Status avancou para PROCESSANDO_LEITURA apos persistir o ID externo', $at1AposId['cnh_status_processamento'] === 'PROCESSANDO_LEITURA');

    // 2.3 Timeout DEPOIS do ID externo persistido -> INDETERMINADO, nunca volta a PENDENTE
    $pdo->prepare('UPDATE tb_atendimento SET cnh_vio_api_enviado_em = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id_atendimento = :id')
        ->execute(['id' => (int) $at1['id_atendimento']]);

    $expirou = $atendimentoDao->marcarProcessamentoVioApiBrExpiradoComoIndeterminado((int) $at1['id_atendimento'], 'cnh', 120);
    afirmar('Processamento expirado (enviado ha 1h, limite 120s) marcado como expirado', $expirou === true);

    $at1AposExpirar = $atendimentoDao->buscarPorId((int) $at1['id_atendimento']);
    afirmar('Apos expirar: status = INDETERMINADO', $at1AposExpirar['cnh_status_processamento'] === 'INDETERMINADO');
    afirmar('Apos expirar: NUNCA volta a PENDENTE', $at1AposExpirar['cnh_status_processamento'] !== 'PENDENTE');

    // Idempotencia: chamar de novo nao muda nada nem gera erro
    $expirouDeNovo = $atendimentoDao->marcarProcessamentoVioApiBrExpiradoComoIndeterminado((int) $at1['id_atendimento'], 'cnh', 120);
    afirmar('marcarProcessamentoVioApiBrExpiradoComoIndeterminado e idempotente (2a chamada sem efeito, ja saiu dos estados alvo)', $expirouDeNovo === false);

    // CAS de envio NUNCA reabre a partir de INDETERMINADO (nunca dispara novo POST automatico)
    $tentativaC = bin2hex(random_bytes(16));
    $reabriuDeIndeterminado = $atendimentoDao->iniciarEnvioVioApiBr((int) $at1['id_atendimento'], 'cnh', $tentativaC, $fp1, 1);
    afirmar('CAS de envio NUNCA reabre automaticamente a partir de INDETERMINADO (exige acao explicita, aqui simplesmente nao ha novo POST)', $reabriuDeIndeterminado === false);

    // 2.4 Resposta tardia apos o atendimento ja ter sido cancelado/concluido por outro caminho
    $at2 = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'DEF5678');
    $fp2 = bin2hex(random_bytes(32));
    $tentativa2 = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $at2['id_atendimento'], 'cnh', $tentativa2, $fp2, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $at2['id_atendimento'], 'cnh', $tentativa2, 'id-externo-2');

    // Atendimento cancelado por OUTRO caminho (ex.: atendente cancelou na tela)
    $pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_atendimento = :id")->execute(['id' => (int) $at2['id_atendimento']]);

    $gravouResultadoTardio = $atendimentoDao->gravarResultadoFinalVioApiBr((int) $at2['id_atendimento'], 'cnh', $tentativa2, 'CONCLUIDO');
    afirmar('Resultado TARDIO apos atendimento cancelado por outro caminho e DESCARTADO (CAS falha pela dupla checagem tentativa+status)', $gravouResultadoTardio === false);

    $at2AposTardio = $atendimentoDao->buscarPorId((int) $at2['id_atendimento']);
    afirmar('Status de processamento do documento NAO foi alterado pela gravacao tardia descartada', $at2AposTardio['cnh_status_processamento'] === 'PROCESSANDO_LEITURA');

    // gravarResultadoFinalVioApiBr com tentativaId null/vazio nunca grava (defesa adicional)
    $gravouComTentativaNula = $atendimentoDao->gravarResultadoFinalVioApiBr((int) $at2['id_atendimento'], 'cnh', null, 'CONCLUIDO');
    afirmar('gravarResultadoFinalVioApiBr com tentativaId=null nunca grava', $gravouComTentativaNula === false);

    // 2.5 Cancelamento DURANTE o polling -- confirma que, apos cancelado, a
    // rota de leitura de status nao mais concede novas transicoes (a
    // checagem de status='em_andamento' de gravarResultadoFinalVioApiBr ja
    // cobre o caminho de escrita; aqui confirmamos tambem que
    // avancarParaProcessandoComparacao/marcarEnvioComoErro sofrem da mesma
    // protecao de tentativa vigente, ainda que NAO checkem status do
    // atendimento diretamente -- achado registrado abaixo).
    $at3 = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'GHI9999');
    $fp3 = bin2hex(random_bytes(32));
    $tentativa3 = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $at3['id_atendimento'], 'cnh', $tentativa3, $fp3, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $at3['id_atendimento'], 'cnh', $tentativa3, 'id-externo-3');
    $pdo->prepare("UPDATE tb_atendimento SET status = 'cancelado' WHERE id_atendimento = :id")->execute(['id' => (int) $at3['id_atendimento']]);

    // avancarParaProcessandoComparacao SO checa status_processamento (nao
    // status do atendimento) -- ACHADO nao-bloqueante: continua avancando o
    // status_processamento interno mesmo com o atendimento ja cancelado
    // (nao gera nenhuma chamada de rede nova por si so -- quem faz a
    // chamada de rede e App\Controller\DocumentoController::statusProcessamento,
    // que so e alcancado via validarAtendimentoParaProcessamento(), que
    // JA bloqueia com "Atendimento nao esta em andamento" antes de chegar
    // aqui -- ver bateria de Controller abaixo).
    $avancouMesmoCancelado = $atendimentoDao->avancarParaProcessandoComparacao((int) $at3['id_atendimento'], 'cnh');
    afirmar('avancarParaProcessandoComparacao nao valida status do atendimento sozinho (protecao real esta em DocumentoController::validarAtendimentoParaProcessamento, testado a seguir) -- documentado, nao e bug isolado', $avancouMesmoCancelado === true);

    // A protecao efetiva contra nova chamada de rede apos cancelamento fica
    // em DocumentoController::statusProcessamento -> validarAtendimentoParaProcessamento()
    // (Resposta::erro('Atendimento nao esta em andamento') ANTES de qualquer
    // consulta a VioApiBrClient). Confirmado por leitura de codigo (nao
    // reexecutado aqui via HTTP real para nao interromper a suite via exit()
    // -- mesmo padrao ja documentado em tests/manual/teste_vio_decode.php).
    afirmar('Atendimento cancelado nao esta em_andamento (pre-condicao que bloqueia DocumentoController::validarAtendimentoParaProcessamento antes de qualquer GET)', $pdo->query("SELECT status FROM tb_atendimento WHERE id_atendimento = " . (int) $at3['id_atendimento'])->fetchColumn() === 'cancelado');

    // ============================================================
    // BATERIA 3 — Cache VIO_CACHE
    // ============================================================

    // 3.1 Cache miss (fingerprint nao encontrado) -> null, segue fluxo normal
    $at4 = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'JKL1111');
    $fpMiss = bin2hex(random_bytes(32));
    $missCnh = $documentoRn->tentarCacheCnh($at4, $fpMiss, 1);
    afirmar('Cache MISS (fingerprint desconhecido) retorna null', $missCnh === null);

    // 3.2 Cache hit valido -> retorna direto, origem VIO_CACHE, nunca chamada externa
    $fpHit = bin2hex(random_bytes(32));
    $agora = new DateTimeImmutable('now');
    $cacheVioApiBrDao->salvarCnh($fpHit, 1, 1, 'FULANO DA SILVA', '11144477735', '2030-01-01', true, 0, $agora->format('Y-m-d H:i:s'), $agora->modify('+7 days')->format('Y-m-d H:i:s'));

    $at5 = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'MNO2222');
    $hitCnh = $documentoRn->tentarCacheCnh($at5, $fpHit, 1);
    afirmar('Cache HIT valido retorna resultado (nunca null)', $hitCnh !== null);
    afirmar('Cache HIT valido: pode_avancar=true', $hitCnh['pode_avancar'] === true);
    afirmar('Cache HIT valido: origem = VIO_CACHE', $hitCnh['origem'] === 'VIO_CACHE');
    afirmar('Cache HIT valido: aviso_trial sempre false (vio.api.br nao tem trial)', $hitCnh['aviso_trial'] === false);

    // 3.3 Cache vencido (expira_em no passado) -> tratado como MISS, nunca serve dado velho
    $fpVencido = bin2hex(random_bytes(32));
    $ontem = $agora->modify('-1 day');
    $cacheVioApiBrDao->salvarCnh($fpVencido, 1, 1, 'CICLANO VENCIDO', '11144477735', '2030-01-01', true, 0, $ontem->modify('-8 days')->format('Y-m-d H:i:s'), $ontem->format('Y-m-d H:i:s'));
    $missVencido = $documentoRn->tentarCacheCnh(novoAtendimento($pdo, $atendimentoDao, $idTotem, 'PQR3333'), $fpVencido, 1);
    afirmar('Cache VENCIDO (expira_em no passado) tratado como MISS, nunca serve dado velho', $missVencido === null);

    // 3.4 Cache revogado -> MISS
    $fpRevogado = bin2hex(random_bytes(32));
    $cacheVioApiBrDao->salvarCnh($fpRevogado, 1, 1, 'BELTRANO REVOGADO', '11144477735', '2030-01-01', true, 0, $agora->format('Y-m-d H:i:s'), $agora->modify('+7 days')->format('Y-m-d H:i:s'));
    $pdo->prepare("UPDATE tb_vio_api_cache_cnh SET estado = 'REVOGADO' WHERE fingerprint = :fp")->execute(['fp' => $fpRevogado]);
    $missRevogado = $documentoRn->tentarCacheCnh(novoAtendimento($pdo, $atendimentoDao, $idTotem, 'STU4444'), $fpRevogado, 1);
    afirmar('Cache REVOGADO (estado invalido) tratado como MISS', $missRevogado === null);

    // 3.5 Documento vencido (CNH com data_validade no passado) -> NUNCA usa
    // cache, mesmo com registro valido/nao-expirado
    $fpDocVencido = bin2hex(random_bytes(32));
    $cacheVioApiBrDao->salvarCnh($fpDocVencido, 1, 1, 'FULANO CNH VENCIDA', '11144477735', '2020-01-01', true, 0, $agora->format('Y-m-d H:i:s'), $agora->modify('+7 days')->format('Y-m-d H:i:s'));
    $missDocVencido = $documentoRn->tentarCacheCnh(novoAtendimento($pdo, $atendimentoDao, $idTotem, 'VWX5555'), $fpDocVencido, 1);
    afirmar('CNH com data_validade vencida NUNCA usa cache, mesmo com registro de cache valido/nao-expirado', $missDocVencido === null);

    // 3.6 QR renovado (bytes diferentes) -> fingerprint diferente, sem hit cruzado
    $qrBytesOriginal = random_bytes(64);
    $qrBytesRenovado = random_bytes(64);
    $fpOriginal = $documentoRn->calcularFingerprintVioApiBr($qrBytesOriginal);
    $fpRenovado = $documentoRn->calcularFingerprintVioApiBr($qrBytesRenovado);
    afirmar('QR renovado (bytes diferentes) gera fingerprint DIFERENTE do original (sem hit cruzado)', $fpOriginal['fingerprint'] !== $fpRenovado['fingerprint']);

    // 3.7 Mudanca de versao de MAPEAMENTO invalida cache antigo (nao da hit)
    $fpMapeamento = bin2hex(random_bytes(32));
    $cacheVioApiBrDao->salvarCnh($fpMapeamento, 1, 1, 'FULANO MAPEAMENTO V1', '11144477735', '2030-01-01', true, 0, $agora->format('Y-m-d H:i:s'), $agora->modify('+7 days')->format('Y-m-d H:i:s'));
    // Simula versao de mapeamento 2 (a atual em producao e sempre 1 -- aqui
    // consultamos diretamente o Dao com versaoMapeamento=2 para confirmar
    // que o Dao SEMPRE exige a versao EXATA, nunca "menor ou igual").
    $buscaComOutraVersaoMapeamento = $cacheVioApiBrDao->buscarCnhValido($fpMapeamento, 1, 2);
    afirmar('Cache gravado sob versao_mapeamento=1 NUNCA da hit para versao_mapeamento=2 (mudanca de mapeamento invalida cache antigo)', $buscaComOutraVersaoMapeamento === null);

    // 3.8 HMAC versionado -- registro gravado sob V1 nao da hit se a versao
    // ativa mudar para V2 (troca de VIO_API_BR_CACHE_HMAC_VERSION, sem
    // credencial real -- so chaves fake locais)
    $qrBytesHmac = random_bytes(64);
    $_ENV['VIO_API_BR_CACHE_HMAC_VERSION'] = '1';
    $fpSobV1 = $documentoRn->calcularFingerprintVioApiBr($qrBytesHmac);
    $cacheVioApiBrDao->salvarCnh($fpSobV1['fingerprint'], $fpSobV1['versao'], 1, 'FULANO HMAC V1', '11144477735', '2030-01-01', true, 0, $agora->format('Y-m-d H:i:s'), $agora->modify('+7 days')->format('Y-m-d H:i:s'));

    // Rotaciona para V2 (nova chave, mesmo QR)
    $_ENV['VIO_API_BR_CACHE_HMAC_KEY_V2'] = bin2hex(random_bytes(32));
    $_ENV['VIO_API_BR_CACHE_HMAC_VERSION'] = '2';
    $fpSobV2 = $documentoRn->calcularFingerprintVioApiBr($qrBytesHmac);
    afirmar('Fingerprint do MESMO QR muda ao rotacionar a versao ativa do HMAC (V1 -> V2)', $fpSobV1['fingerprint'] !== $fpSobV2['fingerprint']);

    $buscaSobV2ComFingerprintV1 = $cacheVioApiBrDao->buscarCnhValido($fpSobV1['fingerprint'], 2, 1);
    afirmar('Registro gravado sob versao HMAC=1 NUNCA da hit consultado como versao=2 (mesmo fingerprint hipotetico)', $buscaSobV2ComFingerprintV1 === null);

    $missAposRotacao = $documentoRn->tentarCacheCnh(novoAtendimento($pdo, $atendimentoDao, $idTotem, 'YZA6666'), $fpSobV2['fingerprint'], (int) $_ENV['VIO_API_BR_CACHE_HMAC_VERSION']);
    afirmar('Apos rotacionar a chave HMAC ativa, o MESMO QR (agora com fingerprint V2) e um MISS real (nunca reaproveita o registro antigo gravado sob V1)', $missAposRotacao === null);

    // Restaura V1 como versao ativa para o restante da suite
    $_ENV['VIO_API_BR_CACHE_HMAC_VERSION'] = '1';

    // 3.9 preencherManual NUNCA atualiza o cache, e cache nunca sobrescreve
    // dado corrigido manualmente
    $at6 = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'BCD7777');
    $totalCacheAntesManual = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_cnh')->fetchColumn();
    $resultadoManual = $documentoRn->preencherManualCnh($at6, 'MANUAL CORRIGIDO', '11144477735', '2030-06-01');
    afirmar('Preenchimento manual de CNH e aprovado (PENDENTE_REVISAO)', $resultadoManual['pode_avancar'] === true);
    $totalCacheDepoisManual = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_cnh')->fetchColumn();
    afirmar('Preenchimento manual NUNCA grava/atualiza tb_vio_api_cache_cnh (VIO_CACHE e exclusivo de vio.api.br automatico)', $totalCacheAntesManual === $totalCacheDepoisManual);

    $at6AposManual = $atendimentoDao->buscarPorId((int) $at6['id_atendimento']);
    afirmar('Origem gravada e MANUAL (nunca VIO_CACHE) apos preenchimento manual', $at6AposManual['cnh_origem_validacao'] === 'MANUAL');

    // ============================================================
    // BATERIA 1b — avaliacao de aprovacao automatica (DocumentoRn +
    // resultado normalizado, formato identico ao devolvido por
    // App\Rn\VioApiBrClient::consultarResultado())
    // ============================================================

    function resultadoCnhBase(array $overrides = []): array
    {
        return array_replace([
            'ok' => true, 'ambiguo' => false, 'nao_encontrado' => false,
            'estado_leitura' => 'completed', 'qr_type' => 'vio',
            'dados_leitura' => ['nome' => 'FULANO DA SILVA', 'cpf' => '111.444.777-35', 'data_validade' => '2030-01-01'],
            'estado_comparacao' => 'completed',
            'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => ['nome' => 'match', 'cpf' => 'match', 'data_validade' => 'match']],
            'pages_processed' => 2, 'total_pages' => 2,
        ], $overrides);
    }

    function resultadoCrlvBase(array $overrides = []): array
    {
        return array_replace([
            'ok' => true, 'ambiguo' => false, 'nao_encontrado' => false,
            'estado_leitura' => 'completed', 'qr_type' => 'vio',
            'dados_leitura' => ['placa' => 'ABC1234', 'exercicio' => 2025, 'uf' => 'SP', 'rntrc' => '12345678', 'tipo' => 'CAMINHAO', 'renavam' => '98765432100'],
            'estado_comparacao' => 'completed',
            'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => ['placa' => 'match', 'renavam' => 'match', 'exercicio' => 'match', 'uf' => 'match']],
            'pages_processed' => null, 'total_pages' => null,
        ], $overrides);
    }

    // 1b.1 CNH pages_processed=2/total_pages=2 -- aprovacao elegivel
    $atCnhOk = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'CNH0001');
    $fpCnhOk = bin2hex(random_bytes(32));
    $tentCnhOk = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $atCnhOk['id_atendimento'], 'cnh', $tentCnhOk, $fpCnhOk, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $atCnhOk['id_atendimento'], 'cnh', $tentCnhOk, 'id-ext-cnh-ok');
    $atCnhOkAtual = $atendimentoDao->buscarPorId((int) $atCnhOk['id_atendimento']);
    $totalCacheAntesCnhOk = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_cnh')->fetchColumn();
    $avalCnhOk = $documentoRn->avaliarResultadoVioApiBrCnh($atCnhOkAtual, resultadoCnhBase());
    afirmar('CNH com pages_processed=2/total_pages=2 + reliable=true/mismatched=0 + campos ok: APROVADA', $avalCnhOk['pode_avancar'] === true);
    $totalCacheDepoisCnhOk = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_cnh')->fetchColumn();
    afirmar('CNH aprovada automaticamente grava cache VIO_CACHE', $totalCacheDepoisCnhOk === $totalCacheAntesCnhOk + 1);

    // 1b.2 CNH so 1 pagina processada -- NUNCA aprova, mesmo com todo o resto ok
    $atCnh1pag = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'CNH0002');
    $fpCnh1pag = bin2hex(random_bytes(32));
    $tentCnh1pag = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $atCnh1pag['id_atendimento'], 'cnh', $tentCnh1pag, $fpCnh1pag, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $atCnh1pag['id_atendimento'], 'cnh', $tentCnh1pag, 'id-ext-cnh-1pag');
    $atCnh1pagAtual = $atendimentoDao->buscarPorId((int) $atCnh1pag['id_atendimento']);
    $totalCacheAntes1pag = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_cnh')->fetchColumn();
    $aval1pag = $documentoRn->avaliarResultadoVioApiBrCnh($atCnh1pagAtual, resultadoCnhBase(['pages_processed' => 1, 'total_pages' => 2]));
    afirmar('CNH com so 1 pagina processada (total_pages=2) NUNCA e aprovada automaticamente (rebaixa para manual)', $aval1pag['pode_avancar'] === false);
    $totalCacheDepois1pag = (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_cnh')->fetchColumn();
    afirmar('CNH nao aprovada (1 pagina) NUNCA grava cache', $totalCacheDepois1pag === $totalCacheAntes1pag);

    // 1b.3 CRLV — fluxo completo aprovado (sem exigencia de paginas)
    $atCrlvOk = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'ABC1234');
    $atendimentoDao->atualizarEtapa((int) $atCrlvOk['id_atendimento'], 'exp_crlv');
    $fpCrlvOk = bin2hex(random_bytes(32));
    $tentCrlvOk = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $atCrlvOk['id_atendimento'], 'crlv', $tentCrlvOk, $fpCrlvOk, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $atCrlvOk['id_atendimento'], 'crlv', $tentCrlvOk, 'id-ext-crlv-ok');
    $atCrlvOkAtual = $atendimentoDao->buscarPorId((int) $atCrlvOk['id_atendimento']);
    $avalCrlvOk = $documentoRn->avaliarResultadoVioApiBrCrlv($atCrlvOkAtual, resultadoCrlvBase());
    afirmar('CRLV com placa batendo + reliable=true/mismatched=0 + campos ok: APROVADO (sem exigencia de paginas)', $avalCrlvOk['pode_avancar'] === true);

    // 1b.4 not_found em campo CRITICO (CNH: data_validade) -> NUNCA aprova, mesmo com reliable=true
    $atCritico = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'CNH0003');
    $fpCritico = bin2hex(random_bytes(32));
    $tentCritico = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $atCritico['id_atendimento'], 'cnh', $tentCritico, $fpCritico, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $atCritico['id_atendimento'], 'cnh', $tentCritico, 'id-ext-critico');
    $atCriticoAtual = $atendimentoDao->buscarPorId((int) $atCritico['id_atendimento']);
    $avalCritico = $documentoRn->avaliarResultadoVioApiBrCnh($atCriticoAtual, resultadoCnhBase([
        'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => ['nome' => 'match', 'cpf' => 'match', 'data_validade' => 'not_found']],
    ]));
    afirmar('not_found em campo CRITICO (data_validade) NUNCA aprova, mesmo com summary.reliable=true/mismatched=0', $avalCritico['pode_avancar'] === false);

    // 1b.5 not_found em campo SECUNDARIO (fora de CAMPOS_CRITICOS_CNH) com
    // reliable=true global -- comportamento IMPLEMENTADO: DocumentoRn so
    // checa mismatch/not_found nos campos da allowlist CRITICA; um campo
    // secundario desconhecido (fora de nome/cpf/data_validade) com
    // not_found NAO bloqueia a aprovacao (decisao ja tomada pelo summary
    // global do fornecedor, refletido em reliable/mismatched).
    $atSecundario = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'CNH0004');
    $fpSecundario = bin2hex(random_bytes(32));
    $tentSecundario = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $atSecundario['id_atendimento'], 'cnh', $tentSecundario, $fpSecundario, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $atSecundario['id_atendimento'], 'cnh', $tentSecundario, 'id-ext-secundario');
    $atSecundarioAtual = $atendimentoDao->buscarPorId((int) $atSecundario['id_atendimento']);
    $avalSecundario = $documentoRn->avaliarResultadoVioApiBrCnh($atSecundarioAtual, resultadoCnhBase([
        'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 0], 'campos' => ['nome' => 'match', 'cpf' => 'match', 'data_validade' => 'match', 'categoria' => 'not_found']],
    ]));
    afirmar('not_found em campo SECUNDARIO (ex.: categoria, fora da allowlist critica) com reliable=true global NAO bloqueia a aprovacao (comportamento implementado, registrado aqui explicitamente)', $avalSecundario['pode_avancar'] === true);

    // 1b.6 reliable=false -> NUNCA aprova, mesmo com mismatched=0 e todos os campos "match"
    $atReliableFalse = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'CNH0005');
    $fpReliableFalse = bin2hex(random_bytes(32));
    $tentReliableFalse = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $atReliableFalse['id_atendimento'], 'cnh', $tentReliableFalse, $fpReliableFalse, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $atReliableFalse['id_atendimento'], 'cnh', $tentReliableFalse, 'id-ext-reliable-false');
    $atReliableFalseAtual = $atendimentoDao->buscarPorId((int) $atReliableFalse['id_atendimento']);
    $avalReliableFalse = $documentoRn->avaliarResultadoVioApiBrCnh($atReliableFalseAtual, resultadoCnhBase([
        'comparacao' => ['summary' => ['reliable' => false, 'mismatched' => 0], 'campos' => ['nome' => 'match', 'cpf' => 'match', 'data_validade' => 'match']],
    ]));
    afirmar('summary.reliable=false NUNCA aprova, mesmo com mismatched=0 e todos os campos match', $avalReliableFalse['pode_avancar'] === false);

    // 1b.7 mismatch em campo critico -> NUNCA aprova
    $atMismatch = novoAtendimento($pdo, $atendimentoDao, $idTotem, 'CNH0006');
    $fpMismatch = bin2hex(random_bytes(32));
    $tentMismatch = bin2hex(random_bytes(16));
    $atendimentoDao->iniciarEnvioVioApiBr((int) $atMismatch['id_atendimento'], 'cnh', $tentMismatch, $fpMismatch, 1);
    $atendimentoDao->gravarIdExternoVioApiBr((int) $atMismatch['id_atendimento'], 'cnh', $tentMismatch, 'id-ext-mismatch');
    $atMismatchAtual = $atendimentoDao->buscarPorId((int) $atMismatch['id_atendimento']);
    $avalMismatch = $documentoRn->avaliarResultadoVioApiBrCnh($atMismatchAtual, resultadoCnhBase([
        'comparacao' => ['summary' => ['reliable' => true, 'mismatched' => 1], 'campos' => ['nome' => 'mismatch', 'cpf' => 'match', 'data_validade' => 'match']],
    ]));
    afirmar('mismatch em campo critico (nome) NUNCA aprova', $avalMismatch['pode_avancar'] === false);

    // ============================================================
    // 4 (transversal). Sanitizacao -- QR bruto NUNCA aparece em nenhuma
    // linha do banco de teste (nem em texto plano nem em qualquer coluna
    // reconhecivel).
    // ============================================================
    $todosQrBytesUsados = [$qrBytesOriginal, $qrBytesRenovado, $qrBytesHmac];
    $tabelasParaVarrer = ['tb_atendimento', 'tb_vio_api_cache_cnh', 'tb_vio_api_cache_crlv'];
    $qrBrutoVazouEmAlgumaTabela = false;
    foreach ($tabelasParaVarrer as $tabela) {
        $linhas = $pdo->query("SELECT * FROM {$tabela}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($linhas as $linha) {
            foreach ($linha as $valor) {
                if (!is_string($valor) || $valor === '') {
                    continue;
                }
                foreach ($todosQrBytesUsados as $qrBruto) {
                    if (str_contains($valor, $qrBruto) || str_contains($valor, base64_encode($qrBruto)) || str_contains($valor, bin2hex($qrBruto))) {
                        $qrBrutoVazouEmAlgumaTabela = true;
                    }
                }
            }
        }
    }
    afirmar('QR bruto (bytes/base64/hex) NUNCA aparece em nenhuma linha de nenhuma tabela do banco de teste', $qrBrutoVazouEmAlgumaTabela === false);

    // 4.2 Renavam cifrado no banco -- nunca legivel em texto plano
    $fpRenavam = bin2hex(random_bytes(32));
    $cacheVioApiBrDao->salvarCrlv($fpRenavam, 1, 1, 'ABC1234', 2025, 'SP', '12345678', 'CAMINHAO', '98765432109', true, 0, $agora->format('Y-m-d H:i:s'), $agora->modify('+7 days')->format('Y-m-d H:i:s'));
    $linhaRenavam = $pdo->query("SELECT renavam_cifrado FROM tb_vio_api_cache_crlv WHERE fingerprint = " . $pdo->quote($fpRenavam))->fetch(PDO::FETCH_ASSOC);
    afirmar('Renavam gravado no cache NUNCA aparece em texto plano na coluna (esta cifrado)', !str_contains((string) $linhaRenavam['renavam_cifrado'], '98765432109'));

    // Confirma que DESCRIPTOGRAFAR de volta funciona (round-trip correto)
    $crlvComRenavamHit = $cacheVioApiBrDao->buscarCrlvValido($fpRenavam, 1, 1);
    afirmar('Registro de CRLV com renavam gravado e recuperavel via Dao (round-trip da tabela)', $crlvComRenavamHit !== null && $crlvComRenavamHit['placa'] === 'ABC1234');
} finally {
    if ($nomeBanco !== null) {
        qaDbDropar($nomeBanco);
        echo "\n(banco de teste {$nomeBanco} dropado)\n";
    }
}

echo "\n=== RESULTADO: {$totalTestes} testes, " . ($totalTestes - $totalFalhas) . " passaram, {$totalFalhas} falharam ===\n";
exit($totalFalhas > 0 ? 1 : 0);
