<?php
/**
 * Script de teste manual (PHP CLI), NAO PHPUnit — o projeto nao tem suite
 * de testes automatizados configurada (composer.json so declara
 * vlucas/phpdotenv; sem phpunit.xml, sem convencao de pasta tests/ previa;
 * ver docs/handoffs/2026-09-04-recebimento-leitura-notas.md).
 *
 * Escolha de local/convencao (registrada aqui por nao haver convencao
 * anterior no projeto): tests/manual/, ao lado de um eventual futuro
 * tests/automatizado/ caso o projeto um dia instale PHPUnit (autorizacao
 * explicita necessaria antes disso, nao dada nesta demanda). "manual" aqui
 * significa "executado manualmente via linha de comando pelo
 * desenvolvedor/QA", nao "roteiro em prosa" (esse fica em
 * docs/handoffs/..., secao "Roteiro manual [FISICO]" mais abaixo neste
 * arquivo, em comentario).
 *
 * Cobre a demanda `recebimento-leitura-notas` e, desde 2026-09-08, a troca
 * de fonte de dado de `recebimento-clientes-tabela-local`:
 *   App\Rn\NotaFiscalRn::identificarCliente()
 *   Util\CnpjValidador
 *   Util\RazaoSocialMatcher
 *   App\Dao\ClienteDao (via fake/stub — nunca bate no banco real de producao,
 *   so no banco de teste local configurado em .env; ver ClienteDaoFake)
 *
 * Execucao:
 *   php tests/manual/teste_identificar_cliente.php
 *
 * Requer:
 *   - vendor/autoload.php instalado (composer install)
 *   - .env configurado na raiz do projeto, apontando para um banco MySQL/
 *     MariaDB local acessivel (mesmo schema de sql/schema.sql +
 *     sql/migrations/002_status_ocr_atendimento_nota.sql e
 *     sql/migrations/003_tb_cliente_razao_normalizada.sql aplicadas)
 *   - tabelas tb_totem, tb_atendimento, tb_atendimento_nota existentes
 *
 * O script cria e limpa seus proprios dados (marcados com placa fixa
 * 'TSTQA01' e codigo de totem 'TESTE-QA-IDCLI') — nao deixa lixo no banco,
 * mesmo em caso de falha (limpeza roda sempre, no finally).
 *
 * Desde a troca de fonte de dado (2026-09-08), NotaFiscalRn::identificarCliente
 * consulta App\Dao\ClienteDao (tb_cliente local) em vez da antiga API externa
 * (App\Rn\ClienteApiClient, sem uso em producao desde entao). Este script
 * usa ClienteDaoFake (abaixo) — nunca toca a tb_cliente real, nem faz
 * qualquer chamada de rede.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Rn\NotaFiscalRn;
use App\Controller\NotaController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

// -------------------- infraestrutura de asserts --------------------

$GLOBALS['__total'] = 0;
$GLOBALS['__falhas'] = 0;
$GLOBALS['__nomesFalhados'] = [];

function checar(string $nome, bool $ok, $extra = null): void
{
    $GLOBALS['__total']++;
    $linha = ($ok ? 'OK    ' : 'FALHOU') . ' - ' . $nome;
    if (!$ok) {
        $linha .= ' -- ' . json_encode($extra, JSON_UNESCAPED_UNICODE);
        $GLOBALS['__falhas']++;
        $GLOBALS['__nomesFalhados'][] = $nome;
    }
    echo $linha . "\n";
}

// -------------------- fake/stub de ClienteDao (nunca toca tb_cliente real) --------------------
//
// Shape de $baseClientes: ['id_cliente' => int, 'nome' => string, 'cnpj' => string14]
// (mesmo shape de tb_cliente, sem a coluna razao_social_normalizada — o fake
// computa essa normalizacao sob demanda em listarParaFuzzy(), reutilizando
// via Reflection o metodo privado real Util\RazaoSocialMatcher::normalizar(),
// pra nunca divergir do algoritmo de producao nem duplicar a logica aqui).

class ClienteDaoFake extends ClienteDao
{
    public array $baseClientes = [];
    public bool $falhaTecnica = false;
    public int $chamadasBuscarPorCnpj = 0;
    public int $chamadasListarParaFuzzy = 0;

    public function __construct()
    {
        // nao chama o construtor pai — nunca precisa de PDO real, nunca toca
        // o banco de producao.
    }

    public function buscarPorCnpj(string $cnpj): ?array
    {
        $this->chamadasBuscarPorCnpj++;
        if ($this->falhaTecnica) {
            throw new \RuntimeException('falha tecnica simulada (buscarPorCnpj)');
        }
        foreach ($this->baseClientes as $c) {
            if ($c['cnpj'] === $cnpj) {
                return $c;
            }
        }
        return null;
    }

    public function listarParaFuzzy(): array
    {
        $this->chamadasListarParaFuzzy++;
        if ($this->falhaTecnica) {
            throw new \RuntimeException('falha tecnica simulada (listarParaFuzzy)');
        }
        $listagem = [];
        foreach ($this->baseClientes as $c) {
            $listagem[] = [
                'id_cliente'   => $c['id_cliente'],
                'nome'         => $c['nome'],
                'razao_social' => self::normalizarComoProducao($c['nome']),
                'cnpj'         => $c['cnpj'],
            ];
        }
        return $listagem;
    }

    // Reflection sobre o metodo privado/estatico real (nunca reimplementado
    // a mao aqui) — simula, so para o teste, o pre-calculo que em producao
    // fica gravado em tb_cliente.razao_social_normalizada.
    private static function normalizarComoProducao(string $nome): string
    {
        $metodo = new \ReflectionMethod(\Util\RazaoSocialMatcher::class, 'normalizar');
        $metodo->setAccessible(true);
        return $metodo->invoke(null, $nome);
    }
}

// -------------------- fixtures de banco (criadas e limpas por este script) --------------------
//
// Extraidas para tests/manual/_fixtures_identificar_cliente.php em
// 2026-09-08 (revisao dos achados MEDIOS de try/catch e validacao de
// status/etapa em NotaController::identificarCliente), para reaproveitar as
// mesmas funcoes no subprocesso que exercita o Controller de verdade (ver
// tests/manual/_caso_controller_identificar_cliente.php e a secao "Casos
// 13-16" mais abaixo).
require __DIR__ . '/_fixtures_identificar_cliente.php';

$notaDao = new AtendimentoNotaDao($pdo);

$idsAtendimentoCriados = [];

function novoRn(AtendimentoNotaDao $notaDao, ClienteDaoFake $fakeClienteDao): NotaFiscalRn
{
    return new NotaFiscalRn($notaDao, $fakeClienteDao);
}

try {
    $idTotemTeste = obterOuCriarTotemTeste($pdo, CODIGO_TOTEM_TESTE);
    $idTotemAlheio = obterOuCriarTotemTeste($pdo, CODIGO_TOTEM_ALHEIO);

    // ===================== Caso 1: CNPJ exato (com mascara) identifica na primeira nota =====================
    $idAt1 = criarAtendimentoTeste($pdo, $idTotemTeste);
    $idsAtendimentoCriados[] = $idAt1;
    $idNota1 = criarNotaTeste($pdo, $idAt1, 1);

    $fake1 = new ClienteDaoFake();
    $fake1->baseClientes = [
        ['id_cliente' =>10, 'nome' => 'CROMEX TINTAS LTDA', 'cnpj' => '11222333000181'],
    ];
    $rn1 = novoRn($notaDao,$fake1);

    $r1 = $rn1->identificarCliente($idAt1, $idNota1, null, ['11.222.333/0001-81'], null);
    checar('Caso 1: CNPJ exato (com mascara) identifica na primeira nota', $r1['status'] === 'IDENTIFICADA' && $r1['cliente']['cnpj'] === '11222333000181', $r1);
    checar('Caso 1: ja_identificado_no_atendimento = true', $r1['ja_identificado_no_atendimento'] === true);

    $persistida1 = $pdo->query("SELECT status_ocr, cnpj_emitente, cliente_identificado FROM tb_atendimento_nota WHERE id_nota = $idNota1")->fetch(PDO::FETCH_ASSOC);
    checar('Caso 1: status_ocr persistido = IDENTIFICADA', $persistida1['status_ocr'] === 'IDENTIFICADA', $persistida1);
    checar('Caso 1: cliente_identificado persistido = 1', (int) $persistida1['cliente_identificado'] === 1, $persistida1);

    // ===================== Caso 2: CNPJs da UDLOG excluidos, sem chamar API =====================
    $idAt2 = criarAtendimentoTeste($pdo, $idTotemTeste);
    $idsAtendimentoCriados[] = $idAt2;
    $idNota2 = criarNotaTeste($pdo, $idAt2, 1);

    $fake2 = new ClienteDaoFake();
    $fake2->baseClientes = [['id_cliente' =>1, 'nome' => 'UDLOG LOGISTICA', 'cnpj' => '14706199000182']];
    $rn2 = novoRn($notaDao,$fake2);
    $r2 = $rn2->identificarCliente($idAt2, $idNota2, null, ['14706199000182', '14.706.199/0003-44'], null);
    checar('Caso 2: CNPJs da UDLOG excluidos -> NAO_IDENTIFICADA', $r2['status'] === 'NAO_IDENTIFICADA', $r2);
    checar('Caso 2: nenhuma chamada a API para CNPJ da UDLOG', $fake2->chamadasBuscarPorCnpj === 0, $fake2->chamadasBuscarPorCnpj);

    // ===================== Caso 3: chave_ocr enviada pelo front (compatibilidade) e ignorada por completo =====================
    // Decisao aprovada em 2026-09-04: chave de acesso removida do processo de
    // identificacao (OCR de 44 digitos estruturalmente fragil). O parametro
    // $chaveOcr continua aceito na assinatura por compatibilidade, mas deve
    // ser 100% ignorado -- mesmo com uma chave valida (DV correto, CNPJ do
    // emitente embutido) apontando para um cliente DIFERENTE do candidato
    // solto, quem decide e sempre cnpjs_candidatos.
    $cnpjEmitenteChave = '11222333000181'; // cliente da "chave" (nao deve ser usado)
    $cnpjCandidatoSolto = '22333444000181'; // cliente do candidato solto (DV valido, deve ser usado)
    $chaveValida = montarChaveTeste($cnpjEmitenteChave);
    checar('Caso 3: chave de teste tem 44 digitos (usada so para confirmar que e ignorada)', strlen($chaveValida) === 44, $chaveValida);

    $idAt3 = criarAtendimentoTeste($pdo, $idTotemTeste);
    $idsAtendimentoCriados[] = $idAt3;
    $idNota3 = criarNotaTeste($pdo, $idAt3, 1);
    $fake3 = new ClienteDaoFake();
    $fake3->baseClientes = [
        ['id_cliente' =>10, 'nome' => 'CROMEX TINTAS LTDA', 'cnpj' => $cnpjEmitenteChave],
        ['id_cliente' =>20, 'nome' => 'TRANSPORTES BRASIL SA', 'cnpj' => $cnpjCandidatoSolto],
    ];
    $rn3 = novoRn($notaDao,$fake3);
    // chave valida aponta para um cliente, candidato solto aponta para outro --
    // deve identificar pelo candidato solto, a chave deve ser ignorada por completo.
    $r3 = $rn3->identificarCliente($idAt3, $idNota3, $chaveValida, [$cnpjCandidatoSolto], null);
    checar('Caso 3: chave_ocr enviada e completamente ignorada -- identifica pelo candidato solto, nao pelo CNPJ da chave', $r3['status'] === 'IDENTIFICADA' && $r3['cliente']['cnpj'] === $cnpjCandidatoSolto, $r3);

    // ===================== Caso 4/5: fuzzy CROMIX/CRMEX -> CROMEX identifica =====================
    foreach (['CROMIX', 'CRMEX'] as $candidato) {
        $idAt = criarAtendimentoTeste($pdo, $idTotemTeste);
        $idsAtendimentoCriados[] = $idAt;
        $idNt = criarNotaTeste($pdo, $idAt, 1);
        $fake = new ClienteDaoFake();
        $fake->baseClientes = [
            ['id_cliente' =>10, 'nome' => 'CROMEX TINTAS LTDA', 'cnpj' => '11222333000181'],
            ['id_cliente' =>20, 'nome' => 'TRANSPORTES BRASIL SA', 'cnpj' => '22333444000199'],
        ];
        $rn = novoRn($notaDao,$fake);
        $r = $rn->identificarCliente($idAt, $idNt, null, [], $candidato);
        checar("Caso 4/5: fuzzy '$candidato' -> CROMEX identifica automaticamente", $r['status'] === 'IDENTIFICADA' && $r['cliente']['cnpj'] === '11222333000181', $r);
    }

    // ===================== Caso 6: dois nomes semelhantes -> NAO identifica automaticamente =====================
    $idAt6 = criarAtendimentoTeste($pdo, $idTotemTeste);
    $idsAtendimentoCriados[] = $idAt6;
    $idNt6 = criarNotaTeste($pdo, $idAt6, 1);
    $fake6 = new ClienteDaoFake();
    $fake6->baseClientes = [
        ['id_cliente' =>30, 'nome' => 'TRANSPORTES SILVA LTDA', 'cnpj' => '33444555000110'],
        ['id_cliente' =>31, 'nome' => 'TRANSPORTES SILVEIRA LTDA', 'cnpj' => '44555666000121'],
    ];
    $rn6 = novoRn($notaDao,$fake6);
    $r6 = $rn6->identificarCliente($idAt6, $idNt6, null, [], 'TRANSPORTES SILVA');
    checar('Caso 6: dois nomes semelhantes -> NAO_IDENTIFICADA (sem associacao automatica)', $r6['status'] === 'NAO_IDENTIFICADA', $r6);

    // ===================== Caso 7: passo 0/early-stop nao reprocessa chamada duplicada =====================
    $idAt7 = criarAtendimentoTeste($pdo, $idTotemTeste);
    $idsAtendimentoCriados[] = $idAt7;
    $idNt7a = criarNotaTeste($pdo, $idAt7, 1);
    $idNt7b = criarNotaTeste($pdo, $idAt7, 2);
    $fake7 = new ClienteDaoFake();
    $fake7->baseClientes = [['id_cliente' =>10, 'nome' => 'CROMEX TINTAS LTDA', 'cnpj' => '11222333000181']];
    $rn7 = novoRn($notaDao,$fake7);
    $rn7->identificarCliente($idAt7, $idNt7a, null, ['11222333000181'], null); // identifica na nota 1
    $chamadasAntes7 = $fake7->chamadasBuscarPorCnpj;
    $r7 = $rn7->identificarCliente($idAt7, $idNt7b, null, ['99999999999999'], null); // nota 2, candidato DIFERENTE
    // Decisao de implementacao (documentada no codigo de NotaFiscalRn::identificarCliente,
    // passo 0): o short-circuit faz NO MAXIMO 1 chamada pontual a buscarPorCnpj so
    // para popular o campo "cliente" da resposta -- nunca compara/valida o novo
    // candidato ('99999999999999'), nunca roda chave/fuzzy para a nota 2.
    checar('Caso 7: passo 0 faz no maximo 1 chamada pontual (nao processa novo candidato)', $fake7->chamadasBuscarPorCnpj <= $chamadasAntes7 + 1, ['antes' => $chamadasAntes7, 'depois' => $fake7->chamadasBuscarPorCnpj]);
    checar('Caso 7: passo 0 responde IDENTIFICADA com ja_identificado_no_atendimento=true', $r7['status'] === 'IDENTIFICADA' && $r7['ja_identificado_no_atendimento'] === true, $r7);
    $persistida7b = $pdo->query("SELECT status_ocr FROM tb_atendimento_nota WHERE id_nota = $idNt7b")->fetch(PDO::FETCH_ASSOC);
    checar('Caso 7: nota 2 tambem marcada IDENTIFICADA no banco (consistencia do atendimento)', $persistida7b['status_ocr'] === 'IDENTIFICADA', $persistida7b);

    // ===================== Caso 8: falha tecnica -> ERRO (nao propaga excecao crua) =====================
    $idAt8 = criarAtendimentoTeste($pdo, $idTotemTeste);
    $idsAtendimentoCriados[] = $idAt8;
    $idNt8 = criarNotaTeste($pdo, $idAt8, 1);
    $fake8 = new ClienteDaoFake();
    $fake8->falhaTecnica = true;
    $rn8 = novoRn($notaDao,$fake8);
    $r8 = $rn8->identificarCliente($idAt8, $idNt8, null, ['11222333000181'], null);
    checar('Caso 8: falha tecnica na API -> status ERRO (sem excecao crua)', $r8['status'] === 'ERRO', $r8);

    // ===================== Caso 9: IDOR -- atendimento/nota de outro totem =====================
    $idAt9 = criarAtendimentoTeste($pdo, $idTotemAlheio); // pertence ao totem "alheio"
    $idsAtendimentoCriados[] = $idAt9;
    $idNt9 = criarNotaTeste($pdo, $idAt9, 1);

    $atendimentoDao = new AtendimentoDao($pdo);
    $atendimentoAlheio = $atendimentoDao->buscarPorId($idAt9);
    $pertenceAoAtacante = $atendimentoAlheio && (int) $atendimentoAlheio['id_totem'] === $idTotemTeste;
    // mesma checagem usada por NotaController::buscarAtendimentoDoTotem -- o
    // Controller real usa Resposta::erro(...)+exit(), que nao da pra invocar
    // diretamente em CLI sem encerrar o script; replica-se aqui a MESMA
    // condicao de bloqueio (id_totem do atendimento != id_totem autenticado).
    checar('Caso 9: IDOR -- atendimento de outro totem seria bloqueado (mesma checagem do Controller)', $pertenceAoAtacante === false, ['id_totem_atendimento' => $atendimentoAlheio['id_totem'] ?? null, 'id_totem_atacante' => $idTotemTeste]);

    // ===================== Caso 10: cliente encontrado apenas em nota posterior =====================
    // Notas 1 e 2 sem match (candidato valido mas nao cadastrado na base fake) ->
    // NAO_IDENTIFICADA processando normalmente (chamada real a API, sem
    // early-stop); nota 3 com CNPJ que bate -> IDENTIFICADA.
    $idAt10 = criarAtendimentoTeste($pdo, $idTotemTeste);
    $idsAtendimentoCriados[] = $idAt10;
    $idNt10a = criarNotaTeste($pdo, $idAt10, 1);
    $idNt10b = criarNotaTeste($pdo, $idAt10, 2);
    $idNt10c = criarNotaTeste($pdo, $idAt10, 3);
    $fake10 = new ClienteDaoFake();
    $fake10->baseClientes = [['id_cliente' =>10, 'nome' => 'CROMEX TINTAS LTDA', 'cnpj' => '11222333000181']];
    $rn10 = novoRn($notaDao,$fake10);

    // CNPJ valido (DV correto, calculado pelo mesmo algoritmo de
    // Util\CnpjValidador) mas nao cadastrado na base fake -- gera CHAMADA
    // real a API (nao early-stop), so nao encontra match.
    $r10a = $rn10->identificarCliente($idAt10, $idNt10a, null, ['22333444000181'], null);
    checar('Caso 10: nota 1 sem match -> NAO_IDENTIFICADA (nao early-stop, processou normalmente)', $r10a['status'] === 'NAO_IDENTIFICADA', $r10a);
    checar('Caso 10: nota 1 gerou chamada real a API (nao foi early-stop)', $fake10->chamadasBuscarPorCnpj >= 1, $fake10->chamadasBuscarPorCnpj);

    $chamadasAntes10 = $fake10->chamadasBuscarPorCnpj;
    $r10b = $rn10->identificarCliente($idAt10, $idNt10b, null, ['33444555000181'], null);
    checar('Caso 10: nota 2 tambem sem match -> NAO_IDENTIFICADA (processou normalmente)', $r10b['status'] === 'NAO_IDENTIFICADA', $r10b);
    checar('Caso 10: nota 2 gerou nova chamada real a API (nao early-stop)', $fake10->chamadasBuscarPorCnpj > $chamadasAntes10, ['antes' => $chamadasAntes10, 'depois' => $fake10->chamadasBuscarPorCnpj]);

    $r10c = $rn10->identificarCliente($idAt10, $idNt10c, null, ['11222333000181'], null);
    checar('Caso 10: nota 3 com CNPJ que bate -> IDENTIFICADA (cliente encontrado so em nota posterior)', $r10c['status'] === 'IDENTIFICADA' && $r10c['cliente']['cnpj'] === '11222333000181', $r10c);

    $persistida10a = $pdo->query("SELECT status_ocr FROM tb_atendimento_nota WHERE id_nota = $idNt10a")->fetch(PDO::FETCH_ASSOC);
    $persistida10b = $pdo->query("SELECT status_ocr FROM tb_atendimento_nota WHERE id_nota = $idNt10b")->fetch(PDO::FETCH_ASSOC);
    checar('Caso 10: nota 1 permanece NAO_IDENTIFICADA no banco (nao sobrescrita retroativamente)', $persistida10a['status_ocr'] === 'NAO_IDENTIFICADA', $persistida10a);
    checar('Caso 10: nota 2 permanece NAO_IDENTIFICADA no banco (nao sobrescrita retroativamente)', $persistida10b['status_ocr'] === 'NAO_IDENTIFICADA', $persistida10b);

    // ===================== Caso 11: nenhuma nova consulta "de negocio" apos identificacao =====================
    // Continua o atendimento do Caso 10 (ja identificado na nota 3) simulando
    // um atendimento completo de 5 notas -- notas 4 e 5 chamando o endpoint
    // (como se o front-end tivesse, por bug/corrida, chamado mesmo apos a
    // flag local de "ja identificado"). Nenhuma delas deve rodar
    // chave/CNPJ/fuzzy de verdade (listarTodos nunca chamado; buscarPorCnpj
    // so o "pontual" de popular a resposta, no maximo 1 por chamada).
    $idNt10d = criarNotaTeste($pdo, $idAt10, 4);
    $idNt10e = criarNotaTeste($pdo, $idAt10, 5);
    $chamadasBuscarAntes11 = $fake10->chamadasBuscarPorCnpj;
    $chamadasListarAntes11 = $fake10->chamadasListarParaFuzzy;

    $r11d = $rn10->identificarCliente($idAt10, $idNt10d, null, ['00000000000000'], 'RAZAO SOCIAL TOTALMENTE DIFERENTE');
    $r11e = $rn10->identificarCliente($idAt10, $idNt10e, null, ['00000000000000'], 'OUTRA RAZAO SOCIAL DIFERENTE');

    checar('Caso 11: nota 4 (apos identificacao) responde IDENTIFICADA via early-stop, nao NAO_IDENTIFICADA', $r11d['status'] === 'IDENTIFICADA' && $r11d['ja_identificado_no_atendimento'] === true, $r11d);
    checar('Caso 11: nota 5 (apos identificacao) responde IDENTIFICADA via early-stop', $r11e['status'] === 'IDENTIFICADA' && $r11e['ja_identificado_no_atendimento'] === true, $r11e);
    checar('Caso 11: listarTodos (fuzzy) NUNCA chamado para notas 4/5 pos-identificacao', $fake10->chamadasListarParaFuzzy === $chamadasListarAntes11, $fake10->chamadasListarParaFuzzy);
    checar(
        'Caso 11: buscarPorCnpj cresce no maximo 1 por chamada pos-identificacao (so "pontual" de popular resposta, nunca reprocessa os novos candidatos)',
        $fake10->chamadasBuscarPorCnpj <= $chamadasBuscarAntes11 + 2,
        ['antes' => $chamadasBuscarAntes11, 'depois' => $fake10->chamadasBuscarPorCnpj]
    );

    // ===================== Caso 12: candidatos vazios -> NAO_IDENTIFICADA (sem erro) =====================
    $idAt12 = criarAtendimentoTeste($pdo, $idTotemTeste);
    $idsAtendimentoCriados[] = $idAt12;
    $idNt12 = criarNotaTeste($pdo, $idAt12, 1);
    $fake12 = new ClienteDaoFake();
    $fake12->baseClientes = [['id_cliente' =>10, 'nome' => 'CROMEX TINTAS LTDA', 'cnpj' => '11222333000181']];
    $rn12 = novoRn($notaDao,$fake12);
    $r12 = $rn12->identificarCliente($idAt12, $idNt12, null, [], null);
    checar('Caso 12: OCR sem nenhum candidato (CNPJ/razao social vazios) -> NAO_IDENTIFICADA, sem erro', $r12['status'] === 'NAO_IDENTIFICADA', $r12);
    checar('Caso 12: nenhuma chamada a API quando nao ha candidato algum', $fake12->chamadasBuscarPorCnpj === 0 && $fake12->chamadasListarParaFuzzy === 0, $fake12);

    $idsAtendimentoCriados[] = $idAt10; // ja adicionado acima, mantido por clareza (evitar duplicidade e inofensivo no DELETE)

    // ===================== Casos 13-16: Controller::identificarCliente (subprocesso) =====================
    // Revisao de 2026-09-08 (achados MEDIOS 3/4 da etapa /03-revisao):
    // NotaController::identificarCliente() passou a (a) validar status/etapa
    // do atendimento e (b) envolver a chamada de negocio em try/catch,
    // espelhando processar(). Esses dois comportamentos sao do Controller,
    // nao da Rn -- os casos 1-12 acima nunca invocam o Controller. Como
    // Util\Resposta::erro()/sucesso() chamam exit(), cada cenario roda em um
    // subprocesso PHP isolado (ver _caso_controller_identificar_cliente.php)
    // para nao encerrar esta suite no primeiro caso.
    function rodarCasoController(string $cenario): array
    {
        $script = escapeshellarg(__DIR__ . '/_caso_controller_identificar_cliente.php');
        $comando = PHP_BINARY . ' ' . $script . ' ' . escapeshellarg($cenario) . ' 2>&1';
        exec($comando, $linhasSaida, $codigoSaida);
        $saida = implode("\n", $linhasSaida);
        // error_log() do PHP CLI e a saida JSON de Resposta::erro/sucesso vao
        // ambos para o mesmo stream (2>&1) -- a linha do JSON e sempre a
        // ULTIMA linha nao vazia produzida pelo script filho.
        $ultimaLinha = '';
        foreach (array_reverse($linhasSaida) as $linha) {
            if (trim($linha) !== '') {
                $ultimaLinha = $linha;
                break;
            }
        }
        $json = json_decode($ultimaLinha, true);
        return ['codigo_saida' => $codigoSaida, 'saida_bruta' => $saida, 'json' => $json];
    }

    // Caso 13: status != 'em_andamento' -- Controller rejeita antes de chamar a Rn.
    $c13 = rodarCasoController('status_errado');
    checar(
        'Caso 13: Controller rejeita atendimento com status != em_andamento',
        $c13['json'] !== null && $c13['json']['sucesso'] === false
            && $c13['json']['erro'] === 'Atendimento nao esta em andamento',
        $c13
    );

    // Caso 14: etapa_atual != 'digitalizacao_notas' -- Controller rejeita antes de chamar a Rn.
    $c14 = rodarCasoController('etapa_errada');
    checar(
        'Caso 14: Controller rejeita atendimento fora da etapa digitalizacao_notas',
        $c14['json'] !== null && $c14['json']['sucesso'] === false
            && $c14['json']['erro'] === 'Atendimento nao esta na etapa de digitalizacao de notas',
        $c14
    );

    // Caso 15: excecao nao prevista na chamada de negocio -- Controller
    // responde erro generico (500) e NUNCA vaza a mensagem tecnica da
    // excecao NA RESPOSTA AO TOTEM (a mensagem tecnica DEVE aparecer no log
    // do servidor via error_log -- por isso ela e esperada em saida_bruta,
    // que mistura stdout+stderr do subprocesso; a checagem de "nao vazar" e
    // sempre sobre o campo `erro` do JSON, que e o que o totem realmente recebe).
    $c15 = rodarCasoController('excecao');
    checar(
        'Caso 15: Controller trata excecao nao prevista com mensagem generica na RESPOSTA ao totem',
        $c15['json'] !== null && $c15['json']['sucesso'] === false
            && $c15['json']['erro'] === 'Nao foi possivel identificar o cliente',
        $c15
    );
    checar(
        'Caso 15: mensagem tecnica da excecao nao aparece no campo `erro` da resposta JSON (so no log do servidor)',
        $c15['json'] !== null && stripos((string) ($c15['json']['erro'] ?? ''), 'detalhe tecnico sensivel') === false,
        $c15
    );
    checar(
        'Caso 15: mensagem tecnica da excecao FOI registrada no log tecnico do servidor (error_log, nao perdida)',
        stripos($c15['saida_bruta'], 'detalhe tecnico sensivel') !== false,
        $c15
    );

    // Caso 16: caminho feliz -- validacao nova de status/etapa NAO bloqueia
    // um atendimento legitimo (mesmo estado usado durante toda a digitalizacao
    // de notas 1-5, ver AtendimentoController::atualizarEtapa -- etapa so muda
    // de 'digitalizacao_notas' na confirmacao, depois de todas as notas).
    $c16 = rodarCasoController('ok_feliz');
    checar(
        'Caso 16: Controller aceita normalmente atendimento em em_andamento/digitalizacao_notas (sem regressao)',
        $c16['json'] !== null && $c16['json']['sucesso'] === true
            && in_array($c16['json']['dados']['status'] ?? null, ['NAO_IDENTIFICADA', 'IDENTIFICADA'], true),
        $c16
    );

} finally {
    // -------------------- limpeza (roda sempre, mesmo em falha) --------------------
    $pdo->prepare('DELETE FROM tb_atendimento_nota WHERE id_atendimento IN (SELECT id_atendimento FROM tb_atendimento WHERE placa = :placa)')
        ->execute(['placa' => PLACA_TESTE]);
    $pdo->prepare('DELETE FROM tb_atendimento WHERE placa = :placa')->execute(['placa' => PLACA_TESTE]);
    // Achado 3 do qa-testes (robustez-rate-limit-migrations, 2026-09-18):
    // desde que este script passou a exercitar NotaController::identificarCliente()
    // de verdade via subprocesso (Caso 16, cenario 'ok_feliz'), com
    // RateLimitOcrDao real, uma linha real fica gravada em
    // tb_rate_limit_ocr(id_totem=...) para os totens de teste. Sem esta
    // limpeza, o DELETE FROM tb_totem abaixo falha com PDOException (FK
    // 1451), mesmo com todas as asserções de negócio já tendo passado.
    // Mesmo padrão de limpeza já usado em
    // tests/manual/teste_rate_limit_identificar_cliente_pdo.php.
    $pdo->prepare('DELETE FROM tb_rate_limit_ocr WHERE id_totem IN (SELECT id_totem FROM tb_totem WHERE codigo IN (:c1, :c2))')
        ->execute(['c1' => CODIGO_TOTEM_TESTE, 'c2' => CODIGO_TOTEM_ALHEIO]);
    $pdo->prepare('DELETE FROM tb_totem WHERE codigo IN (:c1, :c2)')->execute(['c1' => CODIGO_TOTEM_TESTE, 'c2' => CODIGO_TOTEM_ALHEIO]);
}

// -------------------- resumo --------------------

echo "\n=== RESUMO ===\n";
echo "{$GLOBALS['__total']} casos verificados, " . ($GLOBALS['__total'] - $GLOBALS['__falhas']) . " OK, {$GLOBALS['__falhas']} falha(s).\n";
if ($GLOBALS['__falhas'] > 0) {
    echo "Falharam:\n";
    foreach ($GLOBALS['__nomesFalhados'] as $nome) {
        echo "  - $nome\n";
    }
}

echo "\n=== Casos NAO cobertos por este script (dependem de navegador real) ===\n";
echo "Ver roteiro manual em docs/handoffs/2026-09-04-recebimento-leitura-notas.md\n";
echo "e na resposta desta execucao de QA: Tesseract.js/Worker real, cancelamento\n";
echo "efetivo de OCR em andamento por timing real, continuidade de captura ate 5\n";
echo "notas na UI (contador/miniaturas/botao), download de por.traineddata.\n";

exit($GLOBALS['__falhas'] > 0 ? 1 : 0);
