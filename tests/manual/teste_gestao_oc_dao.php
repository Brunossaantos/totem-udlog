<?php

/**
 * Teste da F4a (Gestao Totem, Ordens de Coleta, 2026-10-08), em processo, contra
 * DOIS bancos QA descartaveis `qa_qr_exclusivo_<hex>` (totem + externo com o
 * schema do dump real e as migrations 001-004; ver qa_gestao_oc_infra.php).
 * Nunca toca em udlog_totem nem no banco externo real; sem rede.
 *
 * Cobre: baixa do totem por CLIENTE+numero (OrdemColetaDao), OrdemColetaGestaoDao
 * (filtros adversariais, whitelists, paginacao/teto, contagens, EXISTS de PDF,
 * ativar/inativar por id com CAS, concorrencia, atendimentos por cliente+numero,
 * erro do banco sem vazamento), auditoria (novas acoes/alvos/detalhe), baixas
 * pendentes (DAO + Rn) e a integracao de tentarMarcarOrdemConcluida com o DAO real.
 *
 * Uso: php tests/manual/teste_gestao_oc_dao.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_oc_infra.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Controller\AtendimentoController;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\AuditoriaDao;
use App\Dao\EmpresaDao;
use App\Dao\OrdemColetaDao;
use App\Dao\OrdemColetaGestaoDao;
use App\Dao\OrdemColetaGestaoException;
use App\Dao\OrdemColetaPendenteBaixaDao;
use App\Dao\TotemDao;
use App\Rn\AtendimentoRn;
use App\Rn\DocumentoRn;
use App\Rn\OrdemColetaBaixaRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentClient;
use App\Rn\TalentRn;

date_default_timezone_set('America/Sao_Paulo');

// ---------------------------------------------------------------------------
// Modo worker (concorrencia real: cada worker e um processo PHP separado)
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--worker') {
    $metodo = (string) ($argv[4] ?? '');
    if (!in_array($metodo, ['inativarPorId', 'ativarPorId'], true)) {
        exit(2);
    }
    $dao = new OrdemColetaGestaoDao(ogAbrir((string) $argv[2]));
    echo $dao->{$metodo}((int) $argv[3]);
    exit(0);
}

$total = 0;
$falhas = 0;
function afirmar(string $d, bool $c): void
{
    global $total, $falhas;
    $total++;
    echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n";
    if (!$c) {
        $falhas++;
    }
}

/** Executa e devolve a excecao lancada (ou null). */
function lancou(callable $f): ?Throwable
{
    try {
        $f();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** PDO que conta prepares e permite um gancho antes de preparar. */
class PdoContador extends PDO
{
    public int $prepares = 0;
    public ?Closure $gancho = null;

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $this->prepares++;
        if ($this->gancho !== null) {
            ($this->gancho)($query);
        }

        return parent::prepare($query, $options);
    }
}

/** Duble do DAO externo para a Rn de baixas. */
class GestaoDaoDuble extends OrdemColetaGestaoDao
{
    public function __construct(private mixed $retorno)
    {
    }

    public function statusPorClienteNumero(string $cnpj, string $numero): array
    {
        if ($this->retorno instanceof Throwable) {
            throw $this->retorno;
        }

        return $this->retorno;
    }
}

$amb = null;
$bancos = [];
$storage = null;
register_shutdown_function(static function () use (&$bancos, &$storage): void {
    // registra a limpeza DEPOIS dos shutdowns ja enfileirados (ex.: LogSistema grava no banco QA)
    register_shutdown_function(static function () use (&$bancos, &$storage): void {
        foreach ($bancos as $b) {
            qaQrDroparBanco($b);
        }
        if ($storage !== null) {
            ocQaLimpar(null, $storage);
        }
    });
});

try {
    $amb = ogCriarAmbiente();
    $bancos = [$amb['banco_totem'], $amb['banco_externo']];
    /** @var PDO $totem */
    $totem = $amb['totem'];
    /** @var PDO $ext */
    $ext = $amb['externo'];
    $bancoExt = $amb['banco_externo'];

    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_oc_storage_' . bin2hex(random_bytes(6));
    mkdir($storage, 0750, true);
    $cfg = qaQrConfiguracao();
    $_ENV['DB_HOST'] = $cfg['host'];
    $_ENV['DB_PORT'] = $cfg['port'];
    $_ENV['DB_USER'] = $cfg['user'];
    $_ENV['DB_PASS'] = $cfg['pass'];
    $_ENV['DB_NAME'] = $amb['banco_totem'];
    $_ENV['GESTAO_COLETAS_DB_NAME'] = $amb['banco_externo'];
    $_ENV['STORAGE_PATH'] = $storage;

    afirmar('ambiente: dois bancos QA distintos com nome qa_qr_exclusivo_<hex>', $amb['banco_totem'] !== $amb['banco_externo']
        && preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $amb['banco_totem']) === 1
        && preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $amb['banco_externo']) === 1);
    afirmar('ambiente: o externo nao tem tb_atendimento e o totem nao tem tb_ordens_coleta (conexoes nao se misturam)',
        (int) $ext->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tb_atendimento'")->fetchColumn() === 0
        && (int) $totem->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tb_ordens_coleta'")->fetchColumn() === 0);

    // =====================================================================
    // A. Baixa do totem por CLIENTE + numero (OrdemColetaDao)
    // =====================================================================
    $cnpjA = '11111111000111';
    $cnpjB = '22222222000122';
    $cnpjC = '33333333000133';
    $cA = ogCliente($ext, $cnpjA, 'ALFA LTDA');
    $cB = ogCliente($ext, $cnpjB, 'BETA SA');
    $cC = ogCliente($ext, $cnpjC, 'GAMA ME');
    $dupA = ogOrdem($ext, $cA, 'DUP-1');
    $dupB = ogOrdem($ext, $cB, 'DUP-1');
    $dupC = ogOrdem($ext, $cC, 'DUP-1');
    $estado = static fn (int $id): array => $ext->query("SELECT status, inativada_em FROM tb_ordens_coleta WHERE id = $id")->fetch();

    $daoOc = new OrdemColetaDao($ext);
    $r = $daoOc->marcarInativaPorClienteNumero($cnpjA, 'DUP-1');
    afirmar('baixa por cliente: inativa SO a OC do cliente A (mesmo numero em B e C nao e tocado)',
        $r === true && $estado($dupA)['status'] === 'INATIVA' && $estado($dupA)['inativada_em'] !== null
        && $estado($dupB)['status'] === 'ATIVA' && $estado($dupC)['status'] === 'ATIVA'
        && $estado($dupB)['inativada_em'] === null && $estado($dupC)['inativada_em'] === null);
    $ia = $estado($dupA)['inativada_em'];
    afirmar('baixa por cliente: idempotente (false) e inativada_em preservada', $daoOc->marcarInativaPorClienteNumero($cnpjA, 'DUP-1') === false && $estado($dupA)['inativada_em'] === $ia);
    afirmar('baixa por cliente: CNPJ mascarado e normalizado para digitos', $daoOc->marcarInativaPorClienteNumero('22.222.222/0001-22', 'DUP-1') === true && $estado($dupB)['status'] === 'INATIVA' && $estado($dupC)['status'] === 'ATIVA');
    afirmar('statusPorClienteNumero: A INATIVA, C ATIVA, inexistente null', $daoOc->statusPorClienteNumero($cnpjA, 'DUP-1') === 'INATIVA' && $daoOc->statusPorClienteNumero($cnpjC, 'DUP-1') === 'ATIVA' && $daoOc->statusPorClienteNumero($cnpjC, 'NAO-EXISTE') === null);
    afirmar('baixa por cliente: CNPJ ausente/so simbolos/numero vazio => false e NADA e tocado', $daoOc->marcarInativaPorClienteNumero('', 'DUP-1') === false
        && $daoOc->marcarInativaPorClienteNumero('  ', 'DUP-1') === false && $daoOc->marcarInativaPorClienteNumero('..-/', 'DUP-1') === false
        && $daoOc->marcarInativaPorClienteNumero($cnpjC, '') === false && $estado($dupC)['status'] === 'ATIVA');
    afirmar('baixa por cliente: CNPJ de cliente inexistente => false e nada tocado', $daoOc->marcarInativaPorClienteNumero('99999999000199', 'DUP-1') === false && $estado($dupC)['status'] === 'ATIVA');
    afirmar('baixa por cliente: SQL injection no cnpj/numero nao altera nada',
        $daoOc->marcarInativaPorClienteNumero("' OR 1=1 --", "DUP-1' OR '1'='1") === false && $daoOc->marcarInativaPorClienteNumero($cnpjC, "DUP-1' OR '1'='1") === false && $estado($dupC)['status'] === 'ATIVA');
    afirmar('statusPorClienteNumero: cnpj/numero vazios => null (sem consulta)', $daoOc->statusPorClienteNumero('', 'DUP-1') === null && $daoOc->statusPorClienteNumero($cnpjA, '') === null);
    // A2: ambiguidade (UNIQUE removida SO neste schema QA) => null (nao conclusivo), nunca a 1a linha
    $ext->exec('ALTER TABLE tb_ordens_coleta DROP INDEX uk_ordem_cliente');
    $ambA1 = ogOrdem($ext, $cA, 'AMB-1', 'INATIVA', null, '2026-01-01 08:00:00');
    $ambA2 = ogOrdem($ext, $cA, 'AMB-1', 'ATIVA');
    afirmar('statusPorClienteNumero (Dao totem): 2 linhas (INATIVA primeiro, ATIVA depois) => null, nao o status da 1a linha', $daoOc->statusPorClienteNumero($cnpjA, 'AMB-1') === null);
    $ext->exec("DELETE FROM tb_ordens_coleta WHERE id = $ambA1");
    afirmar('statusPorClienteNumero (Dao totem): com 1 linha restante volta a devolver o status', $daoOc->statusPorClienteNumero($cnpjA, 'AMB-1') === 'ATIVA');
    $ambA3 = ogOrdem($ext, $cA, 'AMB-1', 'ATIVA');
    $ambA4 = ogOrdem($ext, $cA, 'AMB-1', 'INATIVA', null, '2026-01-01 08:00:00');
    afirmar('statusPorClienteNumero (Dao totem): 3 linhas => null', $daoOc->statusPorClienteNumero($cnpjA, 'AMB-1') === null);
    $daoGestaoA2 = new OrdemColetaGestaoDao($ext);
    afirmar('statusPorClienteNumero (Dao gestao): ambiguo => 2 estados (LIMIT 2), a Rn recusa oc_ambigua', count($daoGestaoA2->statusPorClienteNumero($cnpjA, 'AMB-1')) === 2);
    $ext->exec("DELETE FROM tb_ordens_coleta WHERE numero_ordem_coleta = 'AMB-1'");
    $ext->exec('ALTER TABLE tb_ordens_coleta ADD UNIQUE KEY uk_ordem_cliente (cliente_id, numero_ordem_coleta)');
    $srcOc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Dao/OrdemColetaDao.php');
    $srcCli = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Rn/OrdemColetaClient.php');
    $srcGestao = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Dao/OrdemColetaGestaoDao.php');
    afirmar('metodos por numero removidos (OrdemColetaDao/Client) e nenhum codigo da gestao os usa', !str_contains($srcOc, 'function marcarInativaPorNumero') && !str_contains($srcOc, 'function statusPorNumero') && !preg_match('/function \w*PorNumero/', $srcCli) && !str_contains($srcGestao, 'PorNumero'));
    afirmar('UPDATE da baixa tem WHERE por cliente (subselect de tb_clientes.cnpj) E status ATIVA', (bool) preg_match("/WHERE cliente_id = \(SELECT id FROM tb_clientes WHERE cnpj = :cnpj\)\s+AND numero_ordem_coleta = :numero\s+AND status = [\\\\]?'ATIVA[\\\\]?'/", $srcOc));
    // restaura B para os testes seguintes
    $ext->exec("UPDATE tb_ordens_coleta SET status = 'ATIVA', inativada_em = NULL WHERE id IN ($dupA, $dupB)");

    // =====================================================================
    // B. OrdemColetaGestaoDao: dados
    // =====================================================================
    $agora = time();
    $pagIds = [];
    for ($i = 1; $i <= 60; $i++) {
        $extra = $i === 1 ? ['transportadora_nome' => 'TRANSP X LTDA', 'transportadora_cnpj' => '55555555000155', 'placa_prevista' => 'ABC1D23', 'motorista_nome_previsto' => 'JOAO DA SILVA', 'cnh_prevista' => '12345678901'] : [];
        $pagIds[$i] = ogOrdem($ext, $cA, sprintf('PAG-%04d', $i), 'ATIVA', date('Y-m-d H:i:s', $agora - 30 * 86400 + $i * 60), null, $extra);
    }
    $novaIds = [];
    foreach ([1, 2, 3] as $i) {
        $novaIds[] = ogOrdem($ext, $cA, 'NOVA-' . $i, 'ATIVA', date('Y-m-d H:i:s', $agora - 2 * 86400 - $i * 60));
    }
    $antId = ogOrdem($ext, $cA, 'ANT-1', 'ATIVA', date('Y-m-d H:i:s', $agora - 20 * 86400));
    $quaseId = ogOrdem($ext, $cA, 'QUASE-1', 'ATIVA', date('Y-m-d H:i:s', $agora - 14 * 86400));
    $uvId = ogOrdem($ext, $cB, 'U_V-1');
    $uxId = ogOrdem($ext, $cB, 'UXV-1');
    $abcId = ogOrdem($ext, $cB, 'abc-77');
    $pontoId = ogOrdem($ext, $cB, 'A.B/C-1');
    for ($i = 1; $i <= 5; $i++) {
        ogOrdem($ext, $cA, 'INA-' . $i, 'INATIVA', '2026-02-0' . $i . ' 08:00:00', '2026-03-0' . $i . ' 10:00:00');
    }
    // periodo
    $cD = ogCliente($ext, '44444444000144', 'DELTA PERIODO');
    $p1 = ogOrdem($ext, $cD, 'P-1', 'ATIVA', '2026-03-09 23:59:59');
    $p2 = ogOrdem($ext, $cD, 'P-2', 'ATIVA', '2026-03-10 00:00:00');
    $p3 = ogOrdem($ext, $cD, 'P-3', 'ATIVA', '2026-03-10 23:59:59');
    $p4 = ogOrdem($ext, $cD, 'P-4', 'ATIVA', '2026-03-11 00:00:00');
    $q1 = ogOrdem($ext, $cD, 'Q-1', 'INATIVA', '2026-01-01 08:00:00', '2026-04-05 23:59:59');
    $q2 = ogOrdem($ext, $cD, 'Q-2', 'INATIVA', '2026-01-01 08:00:00', '2026-04-06 00:00:00');
    $z9Id = ogOrdem($ext, $cB, 'Z9');
    ogArquivo($ext, $cnpjA, 'PAG-0001');
    ogArquivo($ext, $cnpjB, 'DUP-1');

    $dao = new OrdemColetaGestaoDao($ext, $totem);
    $oraculoIds = static fn (string $where): array => array_map('intval', $ext->query("SELECT oc.id FROM tb_ordens_coleta oc WHERE $where ORDER BY oc.criado_em DESC, oc.id DESC")->fetchAll(PDO::FETCH_COLUMN));
    $ids = static fn (array $linhas): array => array_map(static fn (array $l): int => $l['id'], $linhas);

    // colunas e ausencia de mascara
    $pri = $dao->listar(['cliente_id' => $cA, 'numero' => 'PAG-0001'], 'ativas', 1);
    $esperadas = ['id', 'numero', 'cliente_id', 'razao_social', 'cnpj', 'cliente_status', 'transportadora_nome', 'transportadora_cnpj', 'placa_prevista', 'motorista_nome_previsto', 'cnh_prevista', 'status', 'criado_em', 'inativada_em', 'tem_pdf', 'mesmo_numero_outros_clientes'];
    $cols = $pri[0] ?? [];
    $k1 = array_keys($cols);
    sort($k1);
    $k2 = $esperadas;
    sort($k2);
    afirmar('listar: 1 linha com EXATAMENTE as colunas da especificacao', count($pri) === 1 && $k1 === $k2);
    afirmar('listar: dados SEM mascara (razao social, cnpj, transportadora, placa, motorista, CNH integrais)',
        $cols['razao_social'] === 'ALFA LTDA' && $cols['cnpj'] === $cnpjA && $cols['transportadora_nome'] === 'TRANSP X LTDA' && $cols['transportadora_cnpj'] === '55555555000155'
        && $cols['placa_prevista'] === 'ABC1D23' && $cols['motorista_nome_previsto'] === 'JOAO DA SILVA' && $cols['cnh_prevista'] === '12345678901'
        && !str_contains(implode('|', array_map('strval', $cols)), '*'));
    afirmar('listar: tipos (id/cliente_id int, tem_pdf bool, mesmo_numero int)', is_int($cols['id']) && is_int($cols['cliente_id']) && is_bool($cols['tem_pdf']) && is_int($cols['mesmo_numero_outros_clientes']));

    // paginacao completa de 'ativas' contra o oraculo SQL
    $oraAtivas = $oraculoIds("oc.status = 'ATIVA'");
    $juntas = [];
    $tamanhos = [];
    for ($p = 1; $p <= 6; $p++) {
        $pg = $dao->listar([], 'ativas', $p);
        $tamanhos[] = count($pg);
        $juntas = array_merge($juntas, $ids($pg));
    }
    afirmar('ativas: paginas de 25 e a ordem (criado_em DESC, id DESC) batem com o oraculo; sem duplicata nem perda', $juntas === $oraAtivas && $tamanhos[0] === 25 && $tamanhos[1] === 25 && $tamanhos[count($tamanhos) - 1] === 0 && count($oraAtivas) > 50);
    $ct = $dao->contar([], 'ativas');
    afirmar('contar(ativas) = oraculo, sem truncar, rotulo formatado', $ct['total'] === count($oraAtivas) && $ct['truncado'] === false && $ct['rotulo'] === number_format(count($oraAtivas), 0, ',', '.'));

    // ativas_15d
    $ora15 = $oraculoIds("oc.status = 'ATIVA' AND oc.criado_em < DATE_SUB(NOW(), INTERVAL 15 DAY)");
    $j15 = [];
    for ($p = 1; $p <= 4; $p++) {
        $j15 = array_merge($j15, $ids($dao->listar([], 'ativas_15d', $p)));
    }
    afirmar('ativas_15d: so ATIVA com criado_em > 15 dias (inclui ANT-1 e PAG, exclui NOVA e QUASE-1 de 14 dias e INATIVAS)', $j15 === $ora15 && in_array($antId, $j15, true) && !in_array($quaseId, $j15, true) && array_intersect($novaIds, $j15) === [] && in_array($pagIds[1], $j15, true));
    afirmar('contar(ativas_15d) = oraculo', $dao->contar([], 'ativas_15d')['total'] === count($ora15));

    // inativas
    $pinat = $dao->listar(['cliente_id' => $cA], 'inativas', 1);
    afirmar('inativas: so INATIVA e, por padrao, inativada_em DESC', count($pinat) === 5 && array_unique(array_column($pinat, 'status')) === ['INATIVA'] && array_column($pinat, 'numero') === ['INA-5', 'INA-4', 'INA-3', 'INA-2', 'INA-1']);
    afirmar('ativas nao lista INATIVA e inativas nao lista ATIVA', array_unique(array_column($dao->listar([], 'ativas', 1), 'status')) === ['ATIVA']);

    // tem_pdf (EXISTS por cnpj + numero) e mesmo numero em outros clientes
    $dups = $dao->listar(['numero' => 'DUP-1', 'ordem' => 'cliente', 'dir' => 'ASC'], 'ativas', 1);
    afirmar('mesmo numero em 3 clientes: 3 linhas, mesmo_numero_outros_clientes = 2 em cada', count($dups) === 3 && array_column($dups, 'mesmo_numero_outros_clientes') === [2, 2, 2]);
    afirmar('tem_pdf: so o cliente B (cnpj+numero) tem PDF; A e C com o mesmo numero NAO', array_column($dups, 'razao_social') === ['ALFA LTDA', 'BETA SA', 'GAMA ME'] && array_column($dups, 'tem_pdf') === [false, true, false]);
    afirmar('tem_pdf em PAG-0001 (A) e mesmo_numero_outros_clientes = 0 em numero unico', $cols['tem_pdf'] === true && $cols['mesmo_numero_outros_clientes'] === 0);
    afirmar('ordenacao por cliente DESC inverte', array_column($dao->listar(['numero' => 'DUP-1', 'ordem' => 'cliente', 'dir' => 'DESC'], 'ativas', 1), 'razao_social') === ['GAMA ME', 'BETA SA', 'ALFA LTDA']);

    // filtros: cliente
    $so = $dao->listar(['cliente_id' => $cB], 'ativas', 1);
    afirmar('filtro cliente_id (int e string) restringe ao cliente', count($so) === 6 && array_unique(array_column($so, 'cliente_id')) === [$cB] && $ids($dao->listar(['cliente_id' => (string) $cB], 'ativas', 1)) === $ids($so));

    // filtros: numero (exato <3, prefixo >=3, nunca %x%)
    afirmar('numero de 1-2 caracteres e EXATO (nao prefixo): Z9 casa, Z nao casa', array_column($dao->listar(['numero' => 'Z9'], 'ativas', 1), 'numero') === ['Z9'] && $dao->listar(['numero' => 'Z'], 'ativas', 1) === [] && $dao->listar(['numero' => 'P-'], 'ativas', 1) === [] && count($dao->listar(['numero' => 'P-1'], 'ativas', 1)) === 1);
    afirmar('numero >= 3 e PREFIXO: PAG-005 casa PAG-0050..0059 (10)', count($dao->listar(['numero' => 'PAG-005'], 'ativas', 1)) === 10 && $dao->contar(['numero' => 'PAG-005'], 'ativas')['total'] === 10);
    afirmar('NUNCA %x%: "AG-0001" nao casa "PAG-0001"; "-0001" nao casa; "NOVA" casa so prefixo', $dao->listar(['numero' => 'AG-0001'], 'ativas', 1) === [] && $dao->listar(['numero' => '-0001'], 'ativas', 1) === [] && count($dao->listar(['numero' => 'NOVA'], 'ativas', 1)) === 3);
    afirmar('curinga "_" do LIKE e escapado: "U_V" casa so U_V-1 (nao UXV-1)', array_column($dao->listar(['numero' => 'U_V'], 'ativas', 1), 'numero') === ['U_V-1']);
    afirmar('numero e comparado sem diferenciar maiusculas (collation do banco): ABC-7 casa abc-77', array_column($dao->listar(['numero' => 'ABC-7'], 'ativas', 1), 'numero') === ['abc-77']);
    afirmar('charset aceita . / - _ : "A.B/C" casa A.B/C-1', array_column($dao->listar(['numero' => 'A.B/C'], 'ativas', 1), 'numero') === ['A.B/C-1']);
    afirmar('escaparLike escapa | % _ \\', OrdemColetaGestaoDao::escaparLike('a_b%c\\d|e') === 'a|_b|%c|\\d||e');
    afirmar('numero com espacos nas pontas = trim', array_column($dao->listar(['numero' => '  PAG-0001  '], 'ativas', 1), 'numero') === ['PAG-0001'] && count($dao->listar(['numero' => "PAG-0001\0"], 'ativas', 1)) === 1);

    // filtros adversariais: nunca excecao, nunca alargam (vazio), nada e alterado
    $antes = (int) $ext->query('SELECT COUNT(*) FROM tb_ordens_coleta')->fetchColumn();
    $adv = [
        'numero' => ['%', '_', '\\', "'", "' OR '1'='1", '" OR ""="', 'ÁBC', 'abc%', '%abc', 'a b c', 'a;b', 'PAG-0001; DROP TABLE tb_ordens_coleta', str_repeat('A', 10240), str_repeat('9', 51), ['x'], ['%'], 'PAG%', 'P_G', '/*x*/', 'a--b', "abc\u{202E}", "a\xC3\x28b", 'ａｂｃ', 'PAG-00 1'],
        'cliente_id' => ['1 OR 1=1', '-1', '0', '1e3', '9999999999999999999999', ['1'], 1.5, true, '0x1', ' 1', '1; DROP TABLE tb_clientes', -5, 0, "1\n"],
        'de' => ['2026-02-30', '2026-13-01', '26-01-01', '2026-1-1', '2026-01-01 00:00:00', '2026-01-01; DROP', ['2026-01-01'], '1969-12-31', '2101-01-01', 'hoje', "2026-01-01\n", 20260101],
        'ate' => ['2026-02-30', 'x', ['2026-01-01'], '2026-01-01 23:59:59'],
    ];
    $okAdv = true;
    $detalheAdv = '';
    foreach ($adv as $campo => $valores) {
        foreach ($valores as $valor) {
            $exc = lancou(static function () use ($dao, $campo, $valor, &$res, &$cnt): void {
                $res = $dao->listar([$campo => $valor], 'ativas', 1);
                $cnt = $dao->contar([$campo => $valor], 'ativas');
            });
            if ($exc !== null || $res !== [] || $cnt['total'] !== 0) {
                // "abc\n" e afins viram validos apos trim: so aceitamos se for exatamente o caso do trim
                $okAdv = false;
                $detalheAdv .= ' [' . $campo . '=' . substr(var_export($valor, true), 0, 30) . ($exc ? ' EXC' : ' n=' . count((array) $res)) . ']';
            }
        }
    }
    afirmar('filtros adversariais (numero/cliente_id/datas): nunca excecao, SEMPRE vazio (nao alargam)' . $detalheAdv, $okAdv);
    afirmar('apos os adversariais: nenhuma linha criada/apagada e as tabelas existem', (int) $ext->query('SELECT COUNT(*) FROM tb_ordens_coleta')->fetchColumn() === $antes && (int) $ext->query('SELECT COUNT(*) FROM tb_clientes')->fetchColumn() === 4);
    afirmar('chaves desconhecidas no filtro sao ignoradas (nao viram SQL)', $ids($dao->listar(['x' => 'DROP', 'status' => 'INATIVA', 'placa' => 'ABC1D23', '1=1' => 1], 'ativas', 1)) === $ids($dao->listar([], 'ativas', 1)));
    afirmar('aba fora da whitelist lanca InvalidArgumentException (nunca vira SQL)', lancou(static fn () => $dao->listar([], 'ativas; DROP', 1)) instanceof InvalidArgumentException && lancou(static fn () => $dao->contar([], 'x')) instanceof InvalidArgumentException);
    $n = OrdemColetaGestaoDao::normalizarFiltros(['numero' => '%', 'cliente_id' => 'x', 'de' => '2026-02-30'], 'ativas');
    afirmar('normalizarFiltros lista os invalidos para o controller mostrar mensagem', $n['invalidos'] === ['cliente_id', 'numero', 'de'] || $n['invalidos'] === ['numero', 'cliente_id', 'de'] || (count($n['invalidos']) === 3));

    // periodo
    $per = static fn (array $f, string $aba = 'ativas'): array => array_column($dao->listar($f + ['cliente_id' => $cD, 'ordem' => 'numero', 'dir' => 'ASC'], $aba, 1), 'numero');
    afirmar('periodo criado_em: de=ate=2026-03-10 inclui 00:00:00 e 23:59:59 e exclui 09/23:59:59 e 11/00:00:00', $per(['de' => '2026-03-10', 'ate' => '2026-03-10']) === ['P-2', 'P-3']);
    afirmar('periodo criado_em: so de / so ate', $per(['de' => '2026-03-10']) === ['P-2', 'P-3', 'P-4'] && $per(['ate' => '2026-03-10']) === ['P-1', 'P-2', 'P-3']);
    afirmar('periodo invertido (de > ate) = vazio', $per(['de' => '2026-03-12', 'ate' => '2026-03-01']) === []);
    afirmar('periodo inativada_em (aba inativas): 2026-04-06 so Q-2; 2026-04-05 so Q-1', $per(['inativada_de' => '2026-04-06', 'inativada_ate' => '2026-04-06'], 'inativas') === ['Q-2'] && $per(['inativada_de' => '2026-04-05', 'inativada_ate' => '2026-04-05'], 'inativas') === ['Q-1']);
    afirmar('periodo inativada_em e IGNORADO fora da aba inativas (nao zera a lista)', $per(['inativada_de' => '2030-01-01'], 'ativas') === ['P-1', 'P-2', 'P-3', 'P-4']);
    afirmar('datas combinadas com numero e cliente (AND)', $per(['de' => '2026-03-10', 'numero' => 'P-3']) === ['P-3']);

    // ordenacao
    $pagCliente = static fn (string $ordem, string $dir, string $aba = 'ativas'): array => array_column($dao->listar(['cliente_id' => $cA, 'numero' => 'PAG', 'ordem' => $ordem, 'dir' => $dir], $aba, 1), 'numero');
    $esperadoNumAsc = array_map(static fn (int $i): string => sprintf('PAG-%04d', $i), range(1, 25));
    afirmar('ordem=numero ASC: PAG-0001..0025 na pagina 1', $pagCliente('numero', 'ASC') === $esperadoNumAsc);
    afirmar('ordem=numero DESC: PAG-0060 primeiro', $pagCliente('numero', 'DESC')[0] === 'PAG-0060');
    afirmar('ordem=criado_em ASC: o mais antigo (PAG-0001) primeiro; DESC: PAG-0060', $pagCliente('criado_em', 'ASC')[0] === 'PAG-0001' && $pagCliente('criado_em', 'DESC')[0] === 'PAG-0060');
    $padrao = $pagCliente('criado_em', 'DESC');
    $injecoes = ['id; DROP TABLE tb_ordens_coleta', 'oc.id', 'criado_em desc', 'criado_em,id', '(SELECT 1)', 'NUMERO', 'inativada_em', '', ['numero']];
    $okOrd = true;
    foreach ($injecoes as $inj) {
        $exc = lancou(static function () use ($pagCliente, $inj, &$out): void {
            $out = array_column($GLOBALS['dao']->listar(['cliente_id' => $GLOBALS['cA'], 'numero' => 'PAG', 'ordem' => $inj], 'ativas', 1), 'numero');
        });
        if ($exc !== null || $out !== $padrao) {
            $okOrd = false;
        }
    }
    afirmar('ordem fora da whitelist (injecao, alias, array, inativada_em em aba ativas) cai no padrao, sem excecao', $okOrd);
    $okDir = true;
    foreach (['ASC; DROP', 'sideways', '1', ['ASC'], '', 'DESC,id'] as $dir) {
        $exc = lancou(static function () use ($dao, $cA, $dir, &$out): void {
            $out = array_column($dao->listar(['cliente_id' => $cA, 'numero' => 'PAG', 'ordem' => 'numero', 'dir' => $dir], 'ativas', 1), 'numero');
        });
        // numero sem dir valido => ASC (padrao de numero)
        if ($exc !== null || $out !== $esperadoNumAsc) {
            $okDir = false;
        }
    }
    afirmar('dir fora da whitelist cai no padrao (ASC para numero), sem excecao; "asc" minusculo e aceito', $okDir && $pagCliente('numero', 'asc') === $esperadoNumAsc);
    afirmar('aba inativas aceita ordem=inativada_em ASC', array_column($dao->listar(['cliente_id' => $cA, 'ordem' => 'inativada_em', 'dir' => 'ASC'], 'inativas', 1), 'numero') === ['INA-1', 'INA-2', 'INA-3', 'INA-4', 'INA-5']);
    afirmar('desempate por id DESC (criado_em igual)', (static function () use ($dao, $ext, $cD): bool {
        $ext->exec("UPDATE tb_ordens_coleta SET criado_em = '2026-03-20 10:00:00' WHERE cliente_id = $cD AND numero_ordem_coleta IN ('P-1','P-2','P-3')");
        $l = $dao->listar(['cliente_id' => $cD, 'de' => '2026-03-20', 'ate' => '2026-03-20'], 'ativas', 1);
        $esperado = array_map('intval', $ext->query("SELECT id FROM tb_ordens_coleta WHERE cliente_id = $cD AND numero_ordem_coleta IN ('P-1','P-2','P-3') ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN));
        $ext->exec("UPDATE tb_ordens_coleta SET criado_em = '2026-03-10 00:00:00' WHERE cliente_id = $cD AND numero_ordem_coleta = 'P-2'");

        return array_column($l, 'id') === $esperado;
    })());
    $ext->exec("UPDATE tb_ordens_coleta SET criado_em = '2026-03-09 23:59:59' WHERE id = $p1");
    $ext->exec("UPDATE tb_ordens_coleta SET criado_em = '2026-03-10 23:59:59' WHERE id = $p3");

    // paginacao: borda e teto
    $pc = static fn (int $p): array => $ids($dao->listar(['cliente_id' => $cA, 'numero' => 'PAG'], 'ativas', $p));
    afirmar('pagina 1/2/3 do cliente A (60 PAG): 25/25/10; pagina 4 vazia', count($pc(1)) === 25 && count($pc(2)) === 25 && count($pc(3)) === 10 && $pc(4) === []);
    afirmar('pagina 0, negativa e enorme sao saneadas (sem excecao, sem OFFSET negativo)', $pc(0) === $pc(1) && $pc(-7) === $pc(1) && $pc(PHP_INT_MIN) === $pc(1) && $pc(PHP_INT_MAX) === [] && $pc(401) === [] && $pc(10 ** 9) === []);
    afirmar('paginaEfetiva: 1..400', OrdemColetaGestaoDao::paginaEfetiva(-1) === 1 && OrdemColetaGestaoDao::paginaEfetiva(0) === 1 && OrdemColetaGestaoDao::paginaEfetiva(7) === 7 && OrdemColetaGestaoDao::paginaEfetiva(PHP_INT_MAX) === 400);

    // teto de contagem (10.000)
    $ext->exec('CREATE TABLE digitos (i TINYINT NOT NULL)');
    $ext->exec('INSERT INTO digitos VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)');
    $cT = ogCliente($ext, '66666666000166', 'TETO GRANDE');
    $cT2 = ogCliente($ext, '77777777000177', 'TETO EXATO');
    $sqlSeq = static fn (int $cli, string $prefixo, int $limite): string => "INSERT INTO tb_ordens_coleta (numero_ordem_coleta, cliente_id, email_recebido_id, status, inativada_em, criado_em)
        SELECT CONCAT('$prefixo', a.i, b.i, c.i, d.i, e.i), $cli, 1, 'INATIVA', NOW(), NOW()
        FROM digitos a, digitos b, digitos c, digitos d, digitos e ORDER BY a.i, b.i, c.i, d.i, e.i LIMIT $limite";
    $ext->exec($sqlSeq($cT, 'TETO-', 10050));
    $ext->exec($sqlSeq($cT2, 'EXAT-', 10000));
    $cg = $dao->contar(['cliente_id' => $cT], 'inativas');
    afirmar('contar com 10.050 linhas: total 10000, truncado, rotulo "mais de 10.000"', $cg['total'] === 10000 && $cg['truncado'] === true && $cg['rotulo'] === 'mais de 10.000');
    $ce = $dao->contar(['cliente_id' => $cT2], 'inativas');
    afirmar('contar com EXATAMENTE 10.000: nao truncado, rotulo "10.000"', $ce['total'] === 10000 && $ce['truncado'] === false && $ce['rotulo'] === '10.000');
    afirmar('contar com prefixo dentro do teto (TETO-000 = 100)', $dao->contar(['cliente_id' => $cT, 'numero' => 'TETO-000'], 'inativas')['total'] === 100);
    $u400 = $pc400 = $dao->listar(['cliente_id' => $cT], 'inativas', 400);
    afirmar('pagina 400 (teto de pagina) devolve 25 linhas e pagina 401+ e saneada para 400', count($pc400) === 25 && $ids($dao->listar(['cliente_id' => $cT], 'inativas', 401)) === $ids($u400) && $ids($dao->listar(['cliente_id' => $cT], 'inativas', PHP_INT_MAX)) === $ids($u400));
    afirmar('contar usa subselect com LIMIT por bind (teto) e nunca COUNT(*) direto da tabela', str_contains($srcGestao, "'SELECT COUNT(*) FROM (SELECT 1' . self::FROM_SQL") && str_contains($srcGestao, 'LIMIT :teto') && !preg_match('/COUNT\(\*\)\s+FROM\s+tb_ordens_coleta\s+oc\s+(INNER|WHERE)/i', $srcGestao) && str_contains($srcGestao, "\$binds['teto'] = [self::TETO_CONTAGEM + 1, PDO::PARAM_INT]"));

    // contagens por aba: no maximo 3 consultas; consulta unica por listar/contar
    $contador = ogAbrir($bancoExt, PdoContador::class);
    $daoC = new OrdemColetaGestaoDao($contador, $totem);
    $contador->prepares = 0;
    $cpa = $daoC->contagensPorAba([]);
    afirmar('contagensPorAba: exatamente 3 consultas, uma por aba, chaves ativas/ativas_15d/inativas', $contador->prepares === 3 && array_keys($cpa) === ['ativas', 'ativas_15d', 'inativas']);
    afirmar('contagensPorAba: valores = oraculo (inativas truncadas pelo teto)', $cpa['ativas']['total'] === count($oraculoIds("oc.status = 'ATIVA'")) && $cpa['ativas_15d']['total'] === count($oraculoIds("oc.status = 'ATIVA' AND oc.criado_em < DATE_SUB(NOW(), INTERVAL 15 DAY)")) && $cpa['inativas']['truncado'] === true);
    $contador->prepares = 0;
    $daoC->listar([], 'ativas', 1);
    $daoC->contar([], 'ativas');
    afirmar('listar = 1 consulta e contar = 1 consulta (sem N+1)', $contador->prepares === 2);
    $contador->prepares = 0;
    $daoC->listar(['numero' => '%'], 'ativas', 1);
    $daoC->contar(['numero' => '%'], 'ativas');
    afirmar('filtro invalido: nenhuma consulta ao banco', $contador->prepares === 0);

    // buscarPorId / listarClientes
    $bid = $dao->buscarPorId($pagIds[1]);
    afirmar('buscarPorId devolve a mesma linha da lista (colunas, sem mascara)', $bid !== null && $bid['numero'] === 'PAG-0001' && $bid['cnh_prevista'] === '12345678901' && $bid['tem_pdf'] === true && array_keys($bid) === array_keys($cols));
    afirmar('buscarPorId: inexistente/0/negativo/gigante => null', $dao->buscarPorId(999999999) === null && $dao->buscarPorId(0) === null && $dao->buscarPorId(-1) === null && $dao->buscarPorId(PHP_INT_MAX) === null && $dao->buscarPorId(PHP_INT_MIN) === null);
    $cl = $dao->listarClientes();
    afirmar('listarClientes: id/razao_social/cnpj ordenados por razao_social', count($cl) === 6 && $cl[0]['razao_social'] === 'ALFA LTDA' && array_keys($cl[0]) === ['id', 'razao_social', 'cnpj'] && $cl[0]['cnpj'] === $cnpjA);

    // S2: cliente por id (consulta direta, sem depender do limite do select)
    $cp = $dao->clientePorId($cl[0]['id']);
    afirmar('S2 clientePorId: devolve id/razao_social/cnpj do cliente; inexistente/0/negativo/gigante => null', $cp === $cl[0] && $dao->clientePorId(999999999) === null && $dao->clientePorId(0) === null && $dao->clientePorId(-1) === null && $dao->clientePorId(PHP_INT_MAX) === null);
    $contador->prepares = 0;
    $daoC->clientePorId($cl[0]['id']);
    afirmar('S2 clientePorId: 1 consulta e por bind (nada concatenado)', $contador->prepares === 1 && str_contains($srcGestao, "'SELECT id, razao_social, cnpj FROM tb_clientes WHERE id = :id'"));

    // S3: ids de varios pares cliente+numero em UMA consulta (sem N+1), identicos aos da consulta individual
    $pares = [[$cnpjA, 'DUP-1'], [$cnpjB, 'DUP-1'], [$cnpjC, 'DUP-1'], ['', 'DUP-1'], [$cnpjA, ''], [$cnpjA, 'NAO-EXISTE-77'], ['11.111.111/0001-11', 'DUP-1'], [$cnpjA, 'dup-1'], ["' OR 1=1 --", "x' OR '1'='1"]];
    $contador->prepares = 0;
    $lote = $daoC->idsPorClienteNumeroLote($pares);
    $prepLote = $contador->prepares;
    $individual = [];
    foreach ($pares as $i => $par) {
        $individual[$i] = $dao->idsPorClienteNumero($par[0], $par[1]);
    }
    afirmar('S3 lote: ' . count($pares) . ' pares = 1 consulta (nunca uma por par) e resultado IDENTICO ao individual (inclui mascara, caixa, vazio e injecao)', $prepLote === 1 && $lote === $individual && $lote[0] === [$dupA] && $lote[1] === [$dupB] && $lote[2] === [$dupC] && $lote[3] === [] && $lote[4] === [] && $lote[5] === [] && $lote[6] === [$dupA] && $lote[8] === []);
    $contador->prepares = 0;
    $lote25 = $daoC->idsPorClienteNumeroLote(array_fill(0, 25, [$cnpjB, 'DUP-1']));
    afirmar('S3 lote: 25 pares (pagina cheia) = 1 consulta; acima de 25 so os 25 primeiros (teto)', $contador->prepares === 1 && count($lote25) === 25 && $lote25[24] === [$dupB] && count($daoC->idsPorClienteNumeroLote(array_fill(0, 40, [$cnpjB, 'DUP-1']))) === OrdemColetaGestaoDao::MAX_PARES_LOTE);
    $contador->prepares = 0;
    afirmar('S3 lote: lista vazia ou so pares vazios => [] / sem consulta ao banco', $daoC->idsPorClienteNumeroLote([]) === [] && $daoC->idsPorClienteNumeroLote([['', 'A'], ['x', '']]) === [0 => [], 1 => []] && $contador->prepares === 0);
    $srcSemComentarios = (string) preg_replace('#/\*.*?\*/#s', '', $srcGestao);
    afirmar('S3 lote: usa so bind (:c<i>/:n<i>) e o indice do par vem de inteiro (int)', str_contains($srcSemComentarios, ":c' . (int) \$i") && str_contains($srcSemComentarios, "\$binds['c' . \$i] = [\$cnpj, PDO::PARAM_STR]") && str_contains($srcSemComentarios, "\$binds['n' . \$i] = [\$numero, PDO::PARAM_STR]"));

    // =====================================================================
    // C. Ativar / inativar por ID (CAS)
    // =====================================================================
    $cE = ogCliente($ext, '88888888000188', 'EPSILON');
    $e1 = ogOrdem($ext, $cE, 'EST-1');
    $e1b = ogOrdem($ext, $ogC = ogCliente($ext, '99999999000199', 'ZETA'), 'EST-1');
    $e2 = ogOrdem($ext, $cE, 'EST-2', 'INATIVA', '2026-01-01 08:00:00', '2026-01-02 08:00:00');
    $e3 = ogOrdem($ext, $cE, 'EST-3', 'ATIVA', '2026-01-01 08:00:00', '2026-01-09 09:09:09'); // ATIVA com inativada_em residual (dado sujo)
    $inativadaEm = static fn (int $id): ?string => $ext->query("SELECT inativada_em FROM tb_ordens_coleta WHERE id = $id")->fetchColumn() ?: null;
    $statusDe = static fn (int $id): string => (string) $ext->query("SELECT status FROM tb_ordens_coleta WHERE id = $id")->fetchColumn();

    afirmar('inativarPorId: ATIVA => efetivado, status INATIVA, inativada_em ~ agora', $dao->inativarPorId($e1) === 'efetivado' && $statusDe($e1) === 'INATIVA' && abs(strtotime((string) $inativadaEm($e1)) - time()) < 30);
    afirmar('inativarPorId NAO toca outra OC com o mesmo numero em outro cliente (WHERE por id)', $statusDe($e1b) === 'ATIVA' && $inativadaEm($e1b) === null);
    $t0 = $inativadaEm($e1);
    afirmar('inativarPorId repetido: ja_no_estado e inativada_em preservada', $dao->inativarPorId($e1) === 'ja_no_estado' && $inativadaEm($e1) === $t0);
    afirmar('inativarPorId em OC INATIVA antiga (inativada_em fixa) NAO a sobrescreve (CAS por status)', $dao->inativarPorId($e2) === 'ja_no_estado' && $inativadaEm($e2) === '2026-01-02 08:00:00');
    afirmar('ativarPorId: INATIVA => efetivado, status ATIVA e inativada_em NULL', $dao->ativarPorId($e1) === 'efetivado' && $statusDe($e1) === 'ATIVA' && $inativadaEm($e1) === null);
    afirmar('ativarPorId repetido: ja_no_estado', $dao->ativarPorId($e1) === 'ja_no_estado' && $statusDe($e1) === 'ATIVA');
    afirmar('ativarPorId em ATIVA com inativada_em residual NAO mexe (CAS por status): ja_no_estado e valor preservado', $dao->ativarPorId($e3) === 'ja_no_estado' && $inativadaEm($e3) === '2026-01-09 09:09:09');
    afirmar('ativarPorId na OC antiga zera inativada_em', $dao->ativarPorId($e2) === 'efetivado' && $inativadaEm($e2) === null && $statusDe($e2) === 'ATIVA');
    afirmar('ciclo inativar->ativar->inativar: cada passo efetivado e inativada_em renovada', $dao->inativarPorId($e2) === 'efetivado' && $inativadaEm($e2) !== null);
    afirmar('ids inexistentes/0/negativos/gigantes => inexistente (sem excecao)', $dao->inativarPorId(999999999) === 'inexistente' && $dao->ativarPorId(999999999) === 'inexistente' && $dao->inativarPorId(0) === 'inexistente' && $dao->ativarPorId(-5) === 'inexistente' && $dao->inativarPorId(PHP_INT_MAX) === 'inexistente' && $dao->ativarPorId(PHP_INT_MIN) === 'inexistente');
    afirmar('so UMA linha muda por chamada (as outras OCs ficam identicas)', (static function () use ($ext, $dao, $e1b): bool {
        $antes = $ext->query('SELECT id, status, inativada_em FROM tb_ordens_coleta ORDER BY id')->fetchAll();
        $dao->inativarPorId($e1b);
        $depois = $ext->query('SELECT id, status, inativada_em FROM tb_ordens_coleta ORDER BY id')->fetchAll();
        $mudou = 0;
        foreach ($antes as $i => $l) {
            if ($l !== $depois[$i]) {
                $mudou++;
            }
        }

        return $mudou === 1;
    })());
    $dao->ativarPorId($e1b);

    // estado_mudou: o estado volta entre o UPDATE (0 linhas) e a releitura
    $ext2 = ogAbrir($bancoExt);
    $espiao = ogAbrir($bancoExt, PdoContador::class);
    $daoE = new OrdemColetaGestaoDao($espiao, $totem);
    $ext->exec("UPDATE tb_ordens_coleta SET status = 'INATIVA', inativada_em = NOW() WHERE id = $e3");
    $espiao->gancho = static function (string $sql) use ($ext2, $e3): void {
        if (str_starts_with(ltrim($sql), 'SELECT status FROM tb_ordens_coleta')) {
            $ext2->exec("UPDATE tb_ordens_coleta SET status = 'ATIVA', inativada_em = NULL WHERE id = $e3");
        }
    };
    afirmar('inativarPorId: UPDATE=0 linhas e a OC voltou a ATIVA antes da releitura => estado_mudou', $daoE->inativarPorId($e3) === 'estado_mudou' && $statusDe($e3) === 'ATIVA');
    $espiao->gancho = null;

    // conexao com FOUND_ROWS: o CAS por status continua correto (linha casada = linha alterada)
    $c = qaQrConfiguracao();
    $extFound = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$bancoExt};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_FOUND_ROWS => true]);
    $daoF = new OrdemColetaGestaoDao($extFound, $totem);
    afirmar('mesmo com MYSQL_ATTR_FOUND_ROWS a idempotencia e o CAS se mantem (ja_no_estado, efetivado)', $daoF->ativarPorId($e1) === 'ja_no_estado' && $daoF->inativarPorId($e1) === 'efetivado' && $daoF->inativarPorId($e1) === 'ja_no_estado');
    $dao->ativarPorId($e1);
    afirmar('ConexaoGestaoColetas e o DAO nao usam FOUND_ROWS (rowCount = linhas alteradas)', !str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/util/ConexaoGestaoColetas.php'), 'FOUND_ROWS') && !str_contains($srcGestao, 'PDO::MYSQL_ATTR_FOUND_ROWS'));

    // concorrencia real: 6 processos inativando a MESMA OC => exatamente 1 efetivado
    $alvo = ogOrdem($ext, $cE, 'CONC-1');
    $procs = [];
    for ($i = 0; $i < 6; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, '--worker', $bancoExt, (string) $alvo, 'inativarPorId'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $resultados = [];
    foreach ($procs as [$p, $pipes]) {
        $resultados[] = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }
    $cont = array_count_values($resultados);
    afirmar('concorrencia: 6 processos inativando a mesma OC => 1 efetivado e 5 ja_no_estado (' . implode(',', $resultados) . ')', ($cont['efetivado'] ?? 0) === 1 && ($cont['ja_no_estado'] ?? 0) === 5);

    // =====================================================================
    // D. Arquivo da OC e atendimentos (banco do TOTEM, so contagem)
    // =====================================================================
    $arq = $dao->arquivoDaOc($dupB);
    afirmar('arquivoDaOc: cnpj+numero (B tem, A e C com o mesmo numero NAO)', $arq !== null && array_keys($arq) === ['id', 'caminho_relativo', 'sha256', 'tamanho'] && $arq['tamanho'] === 600 && strlen($arq['sha256']) === 64
        && str_contains($arq['caminho_relativo'], $cnpjB) && $dao->arquivoDaOc($dupA) === null && $dao->arquivoDaOc($dupC) === null);
    afirmar('arquivoDaOc: id inexistente/0/negativo/gigante => null', $dao->arquivoDaOc(999999999) === null && $dao->arquivoDaOc(0) === null && $dao->arquivoDaOc(-1) === null && $dao->arquivoDaOc(PHP_INT_MAX) === null);

    $totem->exec("INSERT INTO tb_empresa (nome, cnpj) VALUES ('Maua I', '14706199000182')");
    $atDao = new AtendimentoDao($totem);
    $idTotem = talentCriarTotemComEmpresa($totem, 'TESTE_F4A_' . bin2hex(random_bytes(3)), 1);
    $mkAt = static function (string $tipo, ?string $numero, ?string $cnpj, string $status = 'em_andamento') use ($totem, $atDao, $idTotem): int {
        $id = $atDao->criar($idTotem, $tipo, 'T' . substr(bin2hex(random_bytes(4)), 0, 7));
        $totem->prepare('UPDATE tb_atendimento SET ordem_coleta = :n, cliente_cnpj = :c, status = :s WHERE id_atendimento = :id')
            ->execute(['n' => $numero, 'c' => $cnpj, 's' => $status, 'id' => $id]);

        return $id;
    };
    $mkAt('expedicao', 'AT-1', $cnpjA);                       // em andamento A (so digitos)
    $mkAt('expedicao', 'AT-1', '11.111.111/0001-11');         // em andamento A (mascarado)
    $mkAt('expedicao', 'AT-1', $cnpjB);                       // mesmo numero, OUTRO cliente
    $mkAt('recebimento', 'AT-1', $cnpjA);                     // recebimento nao conta
    $mkAt('expedicao', 'AT-1', $cnpjA, 'cancelado');
    $mkAt('expedicao', 'AT-1', $cnpjA, 'concluido');
    $mkAt('expedicao', 'AT-2', $cnpjA, 'concluido');
    $mkAt('expedicao', 'AT-3', null);
    afirmar('atendimentosEmAndamentoDaOc: cliente A + numero AT-1 = 2 (digitos e mascarado), nao conta B, recebimento, cancelado', $dao->atendimentosEmAndamentoDaOc($cnpjA, 'AT-1') === 2 && $dao->atendimentosEmAndamentoDaOc($cnpjB, 'AT-1') === 1);
    afirmar('atendimentosEmAndamentoDaOc: cnpj mascarado no argumento e comparado so por digitos', $dao->atendimentosEmAndamentoDaOc('11.111.111/0001-11', 'AT-1') === 2);
    afirmar('atendimentoConcluidoDaOc: A/AT-1 = 1, A/AT-2 = 1, B/AT-1 = 0, numero inexistente 0', $dao->atendimentoConcluidoDaOc($cnpjA, 'AT-1') === 1 && $dao->atendimentoConcluidoDaOc($cnpjA, 'AT-2') === 1 && $dao->atendimentoConcluidoDaOc($cnpjB, 'AT-1') === 0 && $dao->atendimentoConcluidoDaOc($cnpjA, 'NAO') === 0);
    afirmar('atendimentos: cnpj/numero vazios => 0 (sem consulta) e sem alargar para "so numero"', $dao->atendimentosEmAndamentoDaOc('', 'AT-1') === 0 && $dao->atendimentoConcluidoDaOc('', 'AT-2') === 0 && $dao->atendimentosEmAndamentoDaOc($cnpjA, '') === 0 && $dao->atendimentosEmAndamentoDaOc("' OR 1=1 --", "AT-1' OR '1'='1") === 0);
    afirmar('atendimentos consultam o banco do TOTEM (com o PDO do externo falharia com excecao generica)', lancou(static fn () => (new OrdemColetaGestaoDao($ext, $ext))->atendimentosEmAndamentoDaOc($cnpjA, 'AT-1')) instanceof OrdemColetaGestaoException);
    $colsContagem = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Dao/OrdemColetaGestaoDao.php');
    // S1: comparacao do CNPJ SEM funcao na coluna (pode usar indice); resultado identico com e sem mascara
    $semFuncaoNaColuna = static fn (string $sql): bool => preg_match('/\b[A-Za-z_]+\s*\(\s*(?:[A-Za-z_]+\s*\(\s*)*cliente_cnpj\b/i', $sql) !== 1;
    $totemContador = ogAbrir($amb['banco_totem'], PdoContador::class);
    $sqlCapturado = [];
    $totemContador->gancho = static function (string $q) use (&$sqlCapturado): void {
        $sqlCapturado[] = $q;
    };
    $daoT = new OrdemColetaGestaoDao($ext, $totemContador);
    $daoT->atendimentosEmAndamentoDaOc($cnpjA, 'AT-1');
    $daoT->atendimentoConcluidoDaOc($cnpjA, 'AT-1');
    afirmar('S1: a consulta real de atendimentos (capturada no prepare) NAO usa funcao na coluna cliente_cnpj, comeca por tipo/status/ordem_coleta e compara por IN de 2 formatos',
        count($sqlCapturado) === 2 && $semFuncaoNaColuna($sqlCapturado[0]) && !preg_match('/REPLACE\s*\(/i', $sqlCapturado[0])
        && preg_match('/WHERE\s+tipo\s*=\s*\'expedicao\'\s+AND\s+status\s*=\s*:status\s+AND\s+ordem_coleta\s*=\s*:numero\s+AND\s+cliente_cnpj\s+IN\s*\(/i', $sqlCapturado[0]) === 1);
    afirmar('S1 (mutante): o verificador flagra a versao antiga com REPLACE(...cliente_cnpj...) e aceita a comparacao direta',
        !$semFuncaoNaColuna("SELECT COUNT(*) FROM tb_atendimento WHERE REPLACE(REPLACE(cliente_cnpj, '.', ''), '/', '') = :cnpj")
        && !$semFuncaoNaColuna('SELECT 1 FROM t WHERE TRIM(cliente_cnpj) = :c') && $semFuncaoNaColuna('SELECT 1 FROM t WHERE cliente_cnpj IN (:a, :b)'));
    $sqlExplain = (string) preg_replace('/:(\w+)/', '?', $sqlCapturado[0]);
    $planoExp = $totem->prepare('EXPLAIN ' . $sqlExplain);
    $planoExp->execute(['em_andamento', 'AT-1', $cnpjA, '11.111.111/0001-11']);
    $plano = $planoExp->fetchAll();
    $possiveis = (string) ($plano[0]['possible_keys'] ?? '');
    afirmar('S1: EXPLAIN no banco QA roda e o plano oferece o indice idx_status (WHERE sem funcao na coluna; sem indice composto: pendencia registrada)', count($plano) === 1 && str_contains($possiveis, 'idx_status'));
    $mkAt('expedicao', 'AT-MASK', '11.111.111/0001-11');
    $mkAt('expedicao', 'AT-MASK', $cnpjA);
    $mkAt('expedicao', 'AT-MASK', $cnpjB);
    afirmar('S1: resultado identico com e sem mascara no dado semeado (A digitos + A mascarado = 2) e no argumento (digitos ou mascarado), sem contar outro cliente',
        $dao->atendimentosEmAndamentoDaOc($cnpjA, 'AT-MASK') === 2 && $dao->atendimentosEmAndamentoDaOc('11.111.111/0001-11', 'AT-MASK') === 2 && $dao->atendimentosEmAndamentoDaOc($cnpjB, 'AT-MASK') === 1 && $dao->atendimentosEmAndamentoDaOc($cnpjC, 'AT-MASK') === 0);
    afirmar('S1: CNPJ com menos de 14 digitos so casa igualdade direta (nunca LIKE nem prefixo)', $dao->atendimentosEmAndamentoDaOc('1111111100011', 'AT-MASK') === 0 && $dao->atendimentosEmAndamentoDaOc('111', 'AT-MASK') === 0);
    afirmar('contagem de atendimentos so usa COUNT(*) (nenhuma coluna de dado pessoal de tb_atendimento)', (bool) preg_match('/SELECT COUNT\(\*\) FROM tb_atendimento/', $colsContagem) && !preg_match('/SELECT[^;]*(motorista_cpf|motorista_nome|placa|cnh)[^;]*FROM tb_atendimento/i', $colsContagem));

    // =====================================================================
    // E. Falha do banco: excecao generica, sem vazamento
    // =====================================================================
    $semTabelas = new OrdemColetaGestaoDao($totem, $totem); // o "externo" aqui e o banco do totem: sem tb_ordens_coleta
    $sentinela = 'SENT-NUM-9X7';
    $metodos = [
        'listar' => static fn () => $semTabelas->listar(['numero' => $sentinela], 'ativas', 1),
        'contar' => static fn () => $semTabelas->contar(['numero' => $sentinela], 'ativas'),
        'contagensPorAba' => static fn () => $semTabelas->contagensPorAba(['numero' => $sentinela]),
        'buscarPorId' => static fn () => $semTabelas->buscarPorId(5),
        'listarClientes' => static fn () => $semTabelas->listarClientes(),
        'arquivoDaOc' => static fn () => $semTabelas->arquivoDaOc(5),
        'inativarPorId' => static fn () => $semTabelas->inativarPorId(5),
        'ativarPorId' => static fn () => $semTabelas->ativarPorId(5),
        'statusPorClienteNumero' => static fn () => $semTabelas->statusPorClienteNumero($cnpjA, $sentinela),
    ];
    $vaza = [];
    foreach ($metodos as $nome => $f) {
        $e = lancou($f);
        if (!($e instanceof OrdemColetaGestaoException) || $e->getPrevious() !== null || $e->getMessage() !== 'Falha ao consultar ordens de coleta'
            || str_contains($e->getMessage(), $amb['banco_totem']) || str_contains($e->getMessage(), 'tb_') || str_contains($e->getMessage(), $sentinela) || $e->getCode() !== 0) {
            $vaza[] = $nome;
        }
    }
    afirmar('falha do banco: todos os metodos lancam OrdemColetaGestaoException fixa, sem previous/SQL/banco/valor' . ($vaza ? ' VAZAM:' . implode(',', $vaza) : ''), $vaza === []);
    $semTotem = new OrdemColetaGestaoDao($ext, $ext);
    afirmar('falha do banco do totem (tb_atendimento ausente) tambem e a excecao generica', lancou(static fn () => $semTotem->atendimentoConcluidoDaOc($cnpjA, 'AT-1')) instanceof OrdemColetaGestaoException);
    $_ENV_BKP = $_ENV['GESTAO_COLETAS_DB_NAME'];
    unset($_ENV['GESTAO_COLETAS_DB_NAME']);
    $eConf = lancou(static fn () => (new OrdemColetaGestaoDao())->listar([], 'ativas', 1));
    $_ENV['GESTAO_COLETAS_DB_NAME'] = $_ENV_BKP;
    afirmar('sem configuracao do banco externo: RuntimeException generica da conexao (nunca DSN)', $eConf instanceof RuntimeException && $eConf->getMessage() === 'Nao foi possivel conectar ao banco de ordens de coleta');

    // =====================================================================
    // F. Auditoria: novas acoes/alvos/detalhe
    // =====================================================================
    $aud = new AuditoriaDao($totem);
    afirmar('auditoria: 25 acoes, OC_ATIVAR/OC_INATIVAR/OC_VER_PDF/OC_BAIXA_RESOLVER presentes, alvos ordem_coleta e oc_baixa',
        count(AuditoriaDao::ACOES) === 25 && !array_diff(['OC_ATIVAR', 'OC_INATIVAR', 'OC_VER_PDF', 'OC_BAIXA_RESOLVER'], AuditoriaDao::ACOES)
        && in_array('ordem_coleta', AuditoriaDao::ALVO_TIPOS, true) && in_array('oc_baixa', AuditoriaDao::ALVO_TIPOS, true) && count(AuditoriaDao::ALVO_TIPOS) === 7);
    $chaves = array_keys(AuditoriaDao::DETALHE_CAMPOS);
    $esperadasChaves = ['origem', 'motivo', 'perfil_de', 'perfil_para', 'ativo_para', 'sessoes_revogadas', 'empresa', 'logs_apagados', 'auditoria_apagados', 'lotes', 'status_de', 'status_para', 'motivo_oc', 'motivo_cad'];
    afirmar('DETALHE_CAMPOS: conjunto EXATO de chaves (status_de/status_para/motivo_oc da F4 e motivo_cad da F6) e nenhuma chave de dado pessoal',
        count(array_diff($chaves, $esperadasChaves)) === 0 && count(array_diff($esperadasChaves, $chaves)) === 0 && !preg_grep('/numero|cnpj|nome|placa|cnh|cpf|motorista|cliente/i', $chaves));
    afirmar('status_de/status_para = {ATIVA, INATIVA}; motivo_oc = 11 valores fechados (inclui confirmado_cliente_inativo)',
        AuditoriaDao::DETALHE_CAMPOS['status_de'] === ['ATIVA', 'INATIVA'] && AuditoriaDao::DETALHE_CAMPOS['status_para'] === ['ATIVA', 'INATIVA']
        && AuditoriaDao::DETALHE_CAMPOS['motivo_oc'] === ['ja_no_estado', 'estado_mudou', 'oc_inexistente', 'externo_indisponivel', 'arquivo_ausente', 'confirmado_andamento', 'confirmado_ja_baixada', 'confirmado_cliente_inativo', 'cliente_ausente', 'oc_ambigua', 'oc_ativa']);
    $motivosRn = array_values((new ReflectionClass(OrdemColetaBaixaRn::class))->getConstants());
    $motivosRn = array_values(array_filter($motivosRn, static fn ($v) => is_string($v) && in_array($v, ['cliente_ausente', 'oc_inexistente', 'oc_ambigua', 'oc_ativa', 'externo_indisponivel'], true)));
    $aceitos = true;
    foreach ($motivosRn as $m) {
        try {
            $aceitos = $aceitos && AuditoriaDao::montarDetalhe(['motivo_oc' => $m]) === 'motivo_oc=' . $m;
        } catch (Throwable) {
            $aceitos = false;
        }
    }
    afirmar('A1: os 5 motivos de recusa da OrdemColetaBaixaRn (constantes MOTIVO_*) sao aceitos por montarDetalhe', count($motivosRn) === 5 && $aceitos);
    afirmar('motivo_oc confirmado_cliente_inativo: aceito por montarDetalhe (valor fixo, sem PII); variacoes continuam rejeitadas', AuditoriaDao::montarDetalhe(['status_de' => 'INATIVA', 'status_para' => 'ATIVA', 'motivo_oc' => 'confirmado_cliente_inativo']) === 'status_de=INATIVA;status_para=ATIVA;motivo_oc=confirmado_cliente_inativo' && lancou(static fn () => AuditoriaDao::montarDetalhe(['motivo_oc' => 'confirmado_cliente_inativo '])) !== null && lancou(static fn () => AuditoriaDao::montarDetalhe(['motivo_oc' => 'CONFIRMADO_CLIENTE_INATIVO'])) !== null);
    // cliente_status (JOIN ja existente, sem consulta extra): buscarPorId e listar trazem o status de tb_clientes
    $cStI = ogCliente($ext, '98989898000198', 'STATUS INATIVO SA', 'INATIVO');
    $ocStI = ogOrdem($ext, $cStI, 'ST-INAT-1', 'ATIVA');
    $ocStI2 = ogOrdem($ext, $cStI, 'ST-INAT-2', 'INATIVA', '2026-05-01 08:00:00', '2026-05-02 09:00:00');
    $ocStA = ogOrdem($ext, $cA, 'ST-ATIVO-1', 'ATIVA');
    $lstI = $dao->listar(['cliente_id' => $cStI], 'ativas', 1);
    $lstI2 = $dao->listar(['cliente_id' => $cStI], 'inativas', 1);
    $lstA = $dao->listar(['numero' => 'ST-ATIVO-1'], 'ativas', 1);
    afirmar('cliente_status: buscarPorId e listar (ativas/inativas) trazem INATIVO do cliente inativo e ATIVO do ativo (string, so esses dois valores)',
        ($dao->buscarPorId($ocStI)['cliente_status'] ?? null) === 'INATIVO' && ($dao->buscarPorId($ocStI2)['cliente_status'] ?? null) === 'INATIVO' && ($dao->buscarPorId($ocStA)['cliente_status'] ?? null) === 'ATIVO'
        && array_column($lstI, 'cliente_status') === ['INATIVO'] && array_column($lstI2, 'cliente_status') === ['INATIVO'] && array_column($lstA, 'cliente_status') === ['ATIVO']);
    afirmar('cliente_status: mudar tb_clientes.status para ATIVO reflete na OC; ativarPorId/inativarPorId nao dependem do status do cliente (so a tela avisa)',
        $ext->exec("UPDATE tb_clientes SET status = 'ATIVO' WHERE id = $cStI") === 1 && $dao->buscarPorId($ocStI)['cliente_status'] === 'ATIVO' && $ext->exec("UPDATE tb_clientes SET status = 'INATIVO' WHERE id = $cStI") === 1
        && $dao->ativarPorId($ocStI2) === OrdemColetaGestaoDao::R_EFETIVADO && $dao->buscarPorId($ocStI2)['status'] === 'ATIVA' && $dao->buscarPorId($ocStI2)['cliente_status'] === 'INATIVO');
    $ext->exec("DELETE FROM tb_ordens_coleta WHERE id IN ($ocStI, $ocStI2, $ocStA)");
    $ext->exec("DELETE FROM tb_clientes WHERE id = $cStI");
    afirmar('A1: valor fora do conjunto continua rejeitado por montarDetalhe', lancou(static fn () => AuditoriaDao::montarDetalhe(['motivo_oc' => 'oc_qualquer'])) !== null && lancou(static fn () => AuditoriaDao::montarDetalhe(['motivo_oc' => 'OC_ATIVA'])) !== null);
    $idA1 = $aud->registrar(null, 'OC_INATIVAR', 'ordem_coleta', $dupA, 'OK', ['status_de' => 'ATIVA', 'status_para' => 'INATIVA']);
    $idA2 = $aud->registrar(null, 'OC_ATIVAR', 'ordem_coleta', $dupA, 'OK', ['status_de' => 'INATIVA', 'status_para' => 'ATIVA', 'motivo_oc' => 'confirmado_ja_baixada']);
    $idA3 = $aud->registrar(null, 'OC_VER_PDF', 'ordem_coleta', PHP_INT_MAX, 'SEM_EFEITO', ['motivo_oc' => 'arquivo_ausente']);
    $idA4 = $aud->registrar(null, 'OC_BAIXA_RESOLVER', 'oc_baixa', 3, 'OK');
    $lido = static fn (int $id): array => $totem->query("SELECT acao, alvo_tipo, alvo_id, resultado, detalhe FROM tb_gestao_auditoria WHERE id_auditoria = $id")->fetch();
    afirmar('auditoria: as 4 novas acoes gravam com alvo_tipo/alvo_id e detalhe da allowlist',
        $lido($idA1) == ['acao' => 'OC_INATIVAR', 'alvo_tipo' => 'ordem_coleta', 'alvo_id' => $dupA, 'resultado' => 'OK', 'detalhe' => 'status_de=ATIVA;status_para=INATIVA']
        && $lido($idA2)['detalhe'] === 'status_de=INATIVA;status_para=ATIVA;motivo_oc=confirmado_ja_baixada' && (string) $lido($idA3)['alvo_id'] === (string) PHP_INT_MAX
        && $lido($idA4)['alvo_tipo'] === 'oc_baixa' && $lido($idA4)['detalhe'] === null);
    $abrir = $aud->abrir(null, 'OC_VER_PDF', 'ordem_coleta', $dupB);
    afirmar('auditoria: abrir/fechar OC_VER_PDF com motivo_oc', $aud->fechar($abrir, 'SEM_EFEITO', ['motivo_oc' => 'arquivo_ausente']) === true && $lido($abrir)['resultado'] === 'SEM_EFEITO' && $lido($abrir)['detalhe'] === 'motivo_oc=arquivo_ausente');
    $nAud = (int) $totem->query('SELECT COUNT(*) FROM tb_gestao_auditoria')->fetchColumn();
    $recusas = [
        'numero da OC' => ['numero' => 'OC-12345'], 'numero_oc' => ['numero_oc' => '12345'], 'cnpj' => ['cnpj' => $cnpjA], 'nome' => ['nome' => 'JOAO DA SILVA'], 'placa' => ['placa' => 'ABC1D23'], 'cnh' => ['cnh' => '12345678901'],
        'motivo_oc=numero' => ['motivo_oc' => 'OC-12345'], 'motivo_oc=cnpj' => ['motivo_oc' => $cnpjA], 'motivo_oc=fora' => ['motivo_oc' => 'outro_motivo'], 'status_de=numero' => ['status_de' => 'OC-12345'],
        'status_para minusculo' => ['status_para' => 'inativa'], 'status_para=cnpj' => ['status_para' => $cnpjA], 'status_de vazio' => ['status_de' => ''],
        'chave inteira' => [0 => 'ja_no_estado'],
    ];
    $recusou = [];
    foreach ($recusas as $nome => $det) {
        $e = lancou(static fn () => $aud->registrar(null, 'OC_INATIVAR', 'ordem_coleta', $dupA, 'OK', $det));
        if (!($e instanceof InvalidArgumentException)) {
            $recusou[] = $nome;
        }
    }
    afirmar('auditoria: numero, CNPJ, nome, placa, CNH e valores fora do conjunto fechado sao RECUSADOS' . ($recusou ? ' ACEITOS:' . implode(',', $recusou) : ''), $recusou === []);
    afirmar('auditoria: acao/alvo fora do catalogo recusados e NADA foi gravado nas recusas',
        lancou(static fn () => $aud->registrar(null, 'OC_APAGAR', 'ordem_coleta', 1, 'OK')) instanceof InvalidArgumentException
        && lancou(static fn () => $aud->registrar(null, 'OC_ATIVAR', 'ordem coleta', 1, 'OK')) instanceof InvalidArgumentException
        && lancou(static fn () => $aud->registrar(null, 'OC_ATIVAR', 'oc_baixa ', 1, 'OK')) instanceof InvalidArgumentException
        && (int) $totem->query('SELECT COUNT(*) FROM tb_gestao_auditoria')->fetchColumn() === $nAud);
    $todas = $totem->query("SELECT acao, detalhe FROM tb_gestao_auditoria WHERE acao LIKE 'OC\\_%'")->fetchAll();
    $semPii = true;
    foreach ($todas as $l) {
        $txt = (string) $l['detalhe'];
        if (preg_match('/DUP-1|PAG-|' . $cnpjA . '|ALFA|JOAO|ABC1D23|12345678901/', $txt)) {
            $semPii = false;
        }
    }
    afirmar('auditoria: nenhuma linha OC_* contem numero, CNPJ, nome, placa ou CNH', $semPii && count($todas) >= 5);

    // =====================================================================
    // G. Baixas pendentes (DAO) e resolucao (Rn)
    // =====================================================================
    $baixaDao = new OrdemColetaPendenteBaixaDao($totem);
    $cX = ogCliente($ext, '12121212000112', 'X BAIXAS');
    $cY = ogCliente($ext, '13131313000113', 'Y BAIXAS');
    $oX1 = ogOrdem($ext, $cX, 'BX-1', 'INATIVA', '2026-01-01 08:00:00', '2026-02-01 08:00:00');   // inativa: resolve
    $oX2 = ogOrdem($ext, $cX, 'BX-2', 'ATIVA');                                                      // ativa: recusa
    $oX4 = ogOrdem($ext, $cX, 'BX-4', 'ATIVA');                                                      // ativa em X ...
    $oY4 = ogOrdem($ext, $cY, 'BX-4', 'INATIVA', '2026-01-01 08:00:00', '2026-02-01 08:00:00');      // ... mas INATIVA em Y (mesmo numero)
    $atComPii = $mkAt('expedicao', 'BX-1', '12121212000112');
    $totem->prepare("UPDATE tb_atendimento SET motorista_nome = 'MOTORISTA SECRETO', motorista_cpf = '11144477735', placa = 'PII9X99' WHERE id_atendimento = :id")->execute(['id' => $atComPii]);
    $b1 = $atComPii;
    $b2 = $mkAt('expedicao', 'BX-2', '12121212000112');
    $b3 = $mkAt('expedicao', 'BX-3', '12121212000112');          // OC inexistente
    $b4 = $mkAt('expedicao', 'BX-4', '12121212000112');          // numero INATIVA so em Y: deve recusar (cliente X ativa)
    $b5 = $mkAt('expedicao', 'BX-1', null);                      // cliente ausente
    $b6 = $mkAt('expedicao', 'BX-1', '99999999000199');          // cliente desconhecido
    $ids2 = [];
    foreach ([$b1, $b2, $b3, $b4, $b5, $b6] as $i => $idAt) {
        $baixaDao->registrar($idAt, ['BX-1', 'BX-2', 'BX-3', 'BX-4', 'BX-1', 'BX-1'][$i]);
        $ids2[$i + 1] = (int) $totem->query("SELECT id FROM tb_ordem_coleta_pendente_baixa WHERE id_atendimento = $idAt")->fetchColumn();
    }
    $rn = new OrdemColetaBaixaRn($baixaDao, $dao);
    $res = static fn (int $k): array => $rn->resolver($ids2[$k]);
    afirmar('listar baixas: pendentes = 6, resolvidas = 0, todas = 6', count($baixaDao->listar('pendentes', null, null, 25, 0)) === 6 && $baixaDao->listar('resolvidas', null, null, 25, 0) === [] && $baixaDao->contar('pendentes', null, null) === 6 && $baixaDao->contar('todas', null, null) === 6 && $baixaDao->contar('resolvidas', null, null) === 0);
    $ln = $baixaDao->listar('pendentes', null, null, 25, 0)[0];
    $kcol = array_keys($ln);
    sort($kcol);
    afirmar('listar baixas: so colunas sem dado pessoal (id, id_atendimento, numero, criado_em, resolvido_em, cliente_cnpj, atendimento_criado_em)', $kcol === ['atendimento_criado_em', 'cliente_cnpj', 'criado_em', 'id', 'id_atendimento', 'numero_ordem_coleta', 'resolvido_em']
        && !str_contains(json_encode($baixaDao->listar('todas', null, null, 25, 0)), 'SECRETO') && !str_contains(json_encode($baixaDao->listar('todas', null, null, 25, 0)), '11144477735') && !str_contains(json_encode($baixaDao->listar('todas', null, null, 25, 0)), 'PII9X99'));
    afirmar('buscarPorId baixa: ok, 0/negativo/gigante/inexistente => null', $baixaDao->buscarPorId($ids2[1]) !== null && $baixaDao->buscarPorId($ids2[1])['cliente_cnpj'] === '12121212000112' && $baixaDao->buscarPorId(0) === null && $baixaDao->buscarPorId(-3) === null && $baixaDao->buscarPorId(PHP_INT_MAX) === null && $baixaDao->buscarPorId(999999) === null);

    afirmar('resolver: OC INATIVA (cliente+numero) => resolvida e resolvido_em preenchido', $res(1) === ['resultado' => 'resolvida', 'motivo' => null] && $baixaDao->buscarPorId($ids2[1])['resolvido_em'] !== null);
    $ts = $baixaDao->buscarPorId($ids2[1])['resolvido_em'];
    afirmar('resolver de novo: ja_resolvida e resolvido_em NAO muda (idempotente)', $res(1) === ['resultado' => 'ja_resolvida', 'motivo' => null] && $baixaDao->buscarPorId($ids2[1])['resolvido_em'] === $ts);
    afirmar('resolver: OC ainda ATIVA => RECUSADA (oc_ativa) e nada marcado', $res(2) === ['resultado' => 'recusada', 'motivo' => 'oc_ativa'] && $baixaDao->buscarPorId($ids2[2])['resolvido_em'] === null);
    afirmar('resolver: OC inexistente => recusada (oc_inexistente)', $res(3) === ['resultado' => 'recusada', 'motivo' => 'oc_inexistente'] && $baixaDao->buscarPorId($ids2[3])['resolvido_em'] === null);
    afirmar('resolver por CLIENTE+numero: mesmo numero INATIVA em OUTRO cliente nao libera (oc_ativa)', $res(4) === ['resultado' => 'recusada', 'motivo' => 'oc_ativa'] && $baixaDao->buscarPorId($ids2[4])['resolvido_em'] === null);
    afirmar('resolver: atendimento sem CNPJ (cliente ausente) => recusada (cliente_ausente), nunca por so numero', $res(5) === ['resultado' => 'recusada', 'motivo' => 'cliente_ausente'] && $baixaDao->buscarPorId($ids2[5])['resolvido_em'] === null);
    afirmar('resolver: cliente desconhecido no externo => recusada (oc_inexistente)', $res(6) === ['resultado' => 'recusada', 'motivo' => 'oc_inexistente']);
    // OC reativada -> volta a recusar uma pendencia nova
    $ext->exec("UPDATE tb_ordens_coleta SET status = 'INATIVA', inativada_em = NOW() WHERE id = $oX2");
    afirmar('resolver apos a OC virar INATIVA (baixa manual feita na tela de OCs): resolvida', $res(2) === ['resultado' => 'resolvida', 'motivo' => null]);
    afirmar('resolver: id inexistente/0/negativo/gigante => inexistente', $rn->resolver(999999)['resultado'] === 'inexistente' && $rn->resolver(0)['resultado'] === 'inexistente' && $rn->resolver(-1)['resultado'] === 'inexistente' && $rn->resolver(PHP_INT_MAX)['resultado'] === 'inexistente');
    // ambiguidade / externo fora do ar (duble)
    $bx = $ids2[3];
    $rnAmb = new OrdemColetaBaixaRn($baixaDao, new GestaoDaoDuble(['INATIVA', 'INATIVA']));
    $rnVazio = new OrdemColetaBaixaRn($baixaDao, new GestaoDaoDuble([]));
    $rnFora = new OrdemColetaBaixaRn($baixaDao, new GestaoDaoDuble(new OrdemColetaGestaoException()));
    $rnPdo = new OrdemColetaBaixaRn($baixaDao, new GestaoDaoDuble(new PDOException('SQLSTATE[HY000] host=SEGREDO')));
    $rnIna = new OrdemColetaBaixaRn($baixaDao, new GestaoDaoDuble(['INATIVA']));
    $rnAtivaInativa = new OrdemColetaBaixaRn($baixaDao, new GestaoDaoDuble(['ATIVA', 'INATIVA']));
    afirmar('resolver: ambiguidade (2 OCs) => recusada oc_ambigua; 0 OC => oc_inexistente', $rnAmb->resolver($bx) === ['resultado' => 'recusada', 'motivo' => 'oc_ambigua'] && $rnVazio->resolver($bx) === ['resultado' => 'recusada', 'motivo' => 'oc_inexistente']);
    afirmar('resolver: ATIVA+INATIVA (ambiguo) tambem recusa', $rnAtivaInativa->resolver($bx)['motivo'] === 'oc_ambigua');
    afirmar('resolver: banco externo fora do ar (qualquer excecao) => recusada externo_indisponivel, sem vazar a mensagem', $rnFora->resolver($bx) === ['resultado' => 'recusada', 'motivo' => 'externo_indisponivel'] && $rnPdo->resolver($bx) === ['resultado' => 'recusada', 'motivo' => 'externo_indisponivel'] && $baixaDao->buscarPorId($bx)['resolvido_em'] === null);
    afirmar('resolver: so quando EXATAMENTE 1 OC e INATIVA (duble) => resolvida', $rnIna->resolver($bx)['resultado'] === 'resolvida' && $baixaDao->buscarPorId($bx)['resolvido_em'] !== null);
    $totem->exec("UPDATE tb_ordem_coleta_pendente_baixa SET resolvido_em = '2026-01-01 08:00:00' WHERE id = " . $ids2[1]);
    afirmar('marcarResolvida em pendencia ja resolvida (resolvido_em fixo no passado): false e valor preservado (CAS resolvido_em IS NULL)', $baixaDao->marcarResolvida($ids2[1]) === false && $baixaDao->buscarPorId($ids2[1])['resolvido_em'] === '2026-01-01 08:00:00');
    afirmar('marcarResolvida: CAS (so pendente), segunda chamada false, id invalido false', $baixaDao->marcarResolvida($ids2[4]) === true && $baixaDao->marcarResolvida($ids2[4]) === false && $baixaDao->marcarResolvida(0) === false && $baixaDao->marcarResolvida(-1) === false && $baixaDao->marcarResolvida(999999) === false);
    afirmar('listar baixas por situacao e contagem coerentes apos resolver', $baixaDao->contar('pendentes', null, null) + $baixaDao->contar('resolvidas', null, null) === $baixaDao->contar('todas', null, null) && $baixaDao->contar('resolvidas', null, null) === 4);
    // periodo e limites
    $hoje = date('Y-m-d');
    afirmar('listar baixas: periodo de hoje inclui tudo, periodo antigo exclui tudo', $baixaDao->contar('todas', $hoje, $hoje) === 6 && $baixaDao->contar('todas', '2020-01-01', '2020-12-31') === 0 && count($baixaDao->listar('todas', '2020-01-01', '2020-01-02', 25, 0)) === 0);
    afirmar('listar baixas: limite/offset (2 por pagina, ordem criado_em DESC, id DESC) sem duplicar', (static function () use ($baixaDao): bool {
        $p1 = array_column($baixaDao->listar('todas', null, null, 2, 0), 'id');
        $p2 = array_column($baixaDao->listar('todas', null, null, 2, 2), 'id');
        $p3 = array_column($baixaDao->listar('todas', null, null, 2, 4), 'id');
        $tudo = array_column($baixaDao->listar('todas', null, null, 100, 0), 'id');

        return array_merge($p1, $p2, $p3) === $tudo && count($tudo) === 6;
    })());
    afirmar('listar baixas: limite/offset absurdos sao saneados (sem excecao)', lancou(static fn () => $baixaDao->listar('todas', null, null, PHP_INT_MAX, PHP_INT_MAX)) === null && lancou(static fn () => $baixaDao->listar('todas', null, null, -5, -5)) === null && count($baixaDao->listar('todas', null, null, 0, 0)) === 1);
    afirmar('listar/contar baixas: "mostrar" e datas invalidos lancam InvalidArgumentException (nunca SQL)',
        lancou(static fn () => $baixaDao->listar('todas; DROP', null, null, 25, 0)) instanceof InvalidArgumentException
        && lancou(static fn () => $baixaDao->contar('x', null, null)) instanceof InvalidArgumentException
        && lancou(static fn () => $baixaDao->listar('todas', "2026-01-01' OR '1'='1", null, 25, 0)) instanceof InvalidArgumentException
        && lancou(static fn () => $baixaDao->contar('todas', null, '2026-02-30')) instanceof InvalidArgumentException);
    afirmar('registrar() continua idempotente (UNIQUE id_atendimento)', (static function () use ($baixaDao, $b2, $totem): bool {
        $baixaDao->registrar($b2, 'BX-2');

        return (int) $totem->query("SELECT COUNT(*) FROM tb_ordem_coleta_pendente_baixa WHERE id_atendimento = $b2")->fetchColumn() === 1;
    })());

    // =====================================================================
    // H. Integracao: tentarMarcarOrdemConcluida com o DAO REAL (por cliente + numero)
    // =====================================================================
    $montar = static function (AtendimentoDao $atendimentoDao, PDO $pdo, OrdemColetaClient $client, OrdemColetaPendenteBaixaDao $pend): AtendimentoController {
        return new AtendimentoController(
            new AtendimentoRn($atendimentoDao, new OrdemColetaClient(new OrdemColetaDao())),
            new TalentRn(new TalentClient('', ''), $atendimentoDao, $_ENV['STORAGE_PATH']),
            new AtendimentoNotaDao($pdo),
            new DocumentoRn(new \App\Dao\VioCacheDao($pdo), $atendimentoDao),
            new TotemDao($pdo),
            new EmpresaDao($pdo),
            $client,
            $pend
        );
    };
    $ctl = $montar($atDao, $totem, new OrdemColetaClient(new OrdemColetaDao($ext)), $baixaDao);
    $chamar = static function (int $idAt, string $numero, ?string $cnpj) use ($ctl): ?Throwable {
        $ref = new ReflectionMethod(AtendimentoController::class, 'tentarMarcarOrdemConcluida');
        $ref->setAccessible(true);

        return lancou(static fn () => $cnpj === null ? $ref->invoke($ctl, $idAt, $numero) : $ref->invoke($ctl, $idAt, $numero, $cnpj));
    };
    $pendencia = static fn (int $idAt): bool => (int) $totem->query("SELECT COUNT(*) FROM tb_ordem_coleta_pendente_baixa WHERE id_atendimento = $idAt")->fetchColumn() === 1;
    $cH1 = ogOrdem($ext, $cA, 'HX-1');
    $cH2 = ogOrdem($ext, $cB, 'HX-1');
    $atH1 = $mkAt('expedicao', 'HX-1', $cnpjA);
    afirmar('integracao: baixa do atendimento do cliente A inativa SO a OC de A (a de B, mesmo numero, fica ATIVA); sem pendencia', $chamar($atH1, 'HX-1', $cnpjA) === null && $statusDe($cH1) === 'INATIVA' && $statusDe($cH2) === 'ATIVA' && !$pendencia($atH1));
    $inH = $inativadaEm($cH1);
    afirmar('integracao: repetir (JA_ENVIADO) e idempotente: nao altera inativada_em e nao cria pendencia', $chamar($atH1, 'HX-1', $cnpjA) === null && $inativadaEm($cH1) === $inH && !$pendencia($atH1) && $statusDe($cH2) === 'ATIVA');
    $atH2 = $mkAt('expedicao', 'HX-1', $cnpjB);
    afirmar('integracao: o atendimento do cliente B inativa a OC de B e nao mexe em A', $chamar($atH2, 'HX-1', '22.222.222/0001-22') === null && $statusDe($cH2) === 'INATIVA' && $inativadaEm($cH1) === $inH && !$pendencia($atH2));
    $cH3 = ogOrdem($ext, $cA, 'HX-2');
    $cH4 = ogOrdem($ext, $cB, 'HX-2');
    $atH3 = $mkAt('expedicao', 'HX-2', null);
    afirmar('integracao: CNPJ ausente (default do parametro e null/vazio) => NENHUMA OC e inativada e a pendencia e registrada', $chamar($atH3, 'HX-2', null) === null && $statusDe($cH3) === 'ATIVA' && $statusDe($cH4) === 'ATIVA' && $pendencia($atH3));
    $atH4 = $mkAt('expedicao', 'HX-2', '');
    afirmar('integracao: CNPJ vazio => idem (pendencia, nada inativado)', $chamar($atH4, 'HX-2', '') === null && $statusDe($cH3) === 'ATIVA' && $statusDe($cH4) === 'ATIVA' && $pendencia($atH4));
    $atH5 = $mkAt('expedicao', 'HX-2', '99999999000199');
    afirmar('integracao: cliente inexistente => pendencia e nada inativado', $chamar($atH5, 'HX-2', '99999999000199') === null && $statusDe($cH3) === 'ATIVA' && $statusDe($cH4) === 'ATIVA' && $pendencia($atH5));
    $atH6 = $mkAt('expedicao', 'HX-NAO', $cnpjC);
    afirmar('integracao: numero inexistente no cliente => pendencia', $chamar($atH6, 'HX-NAO', $cnpjC) === null && $pendencia($atH6));
    afirmar('integracao: a pendencia de cliente ausente so e resolvida se a OC do CLIENTE estiver INATIVA (sem cnpj no atendimento: cliente_ausente)', (static function () use ($rn, $totem, $atH3): bool {
        $id = (int) $totem->query("SELECT id FROM tb_ordem_coleta_pendente_baixa WHERE id_atendimento = $atH3")->fetchColumn();

        return $rn->resolver($id) === ['resultado' => 'recusada', 'motivo' => 'cliente_ausente'];
    })());

    // =====================================================================
    // I. Higiene estatica do DAO da gestao
    // =====================================================================
    afirmar('DAO da gestao: LIKE so por prefixo (escaparLike(...) . \'%\'), nunca %x%', str_contains($srcGestao, "self::escaparLike(\$f['numero']) . '%'") && !preg_match("/'%'\s*\.\s*(self::escaparLike|\\\$f\['numero'\])/", $srcGestao));
    afirmar('DAO da gestao: ORDER BY so por constantes (ORDEM_SQL) e LIMIT/OFFSET por bind', str_contains($srcGestao, "self::ORDEM_SQL[\$m['ordem']]") && str_contains($srcGestao, 'LIMIT :limite OFFSET :deslocamento') && !preg_match('/\$filtros\[[^\]]+\]\s*\./', $srcGestao));
    afirmar('DAO da gestao: nenhuma query concatena variavel de entrada (so constantes/binds)', !preg_match('/(prepare|query|exec)\(\s*"[^"]*\$/', $srcGestao));
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ': ' . substr($e->getMessage(), 0, 200) . ')', false);
}

echo "\n=== RESULTADO teste_gestao_oc_dao: {$total} verificacoes, " . ($total - $falhas) . " passaram, {$falhas} falharam ===\n";
exit($falhas > 0 ? 1 : 0);
