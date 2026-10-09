<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_gestao_instrumentacao.php (demanda
 * gestao-totem, F3b, 2026-10-08): dispara UM ponto real instrumentado com o
 * LogSistema por execucao (Resposta::erro()/sucesso() chamam exit(), por isso
 * cada cenario roda isolado). Sempre sob o prepend QA (banco `qa_qr_exclusivo_*`
 * forcado em DB_NAME); a escrita do log central acontece no shutdown, na conexao
 * dedicada, exatamente como em producao. error_log vai para o arquivo informado.
 *
 * Uso: php _caso_instrumentacao.php <cenario> <arquivo_log> [json_args]
 * Nunca usa rede real (Talent/VIO/n8n/impressora): tudo por dublê local.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/qa_qr_exclusivo_legado.php';

use App\Controller\AtendimentoController;
use App\Controller\DocumentoController;
use App\Controller\ImpressaoAtendimentoController;
use App\Controller\NotaController;
use App\Controller\OrdemColetaAnexoController;
use App\Dao\AceiteLgpdDao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\EmpresaDao;
use App\Dao\OrdemColetaArquivoDao;
use App\Dao\OrdemColetaDao;
use App\Dao\OrdemColetaPendenteBaixaDao;
use App\Dao\RateLimitOcrDao;
use App\Dao\TotemDao;
use App\Dao\VioCacheDao;
use App\Rn\AbandonoAtendimentoRn;
use App\Rn\AtendimentoRn;
use App\Rn\DocumentoRn;
use App\Rn\LgpdRn;
use App\Rn\NotaFiscalRn;
use App\Rn\OrdemColetaArquivoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentClient;
use App\Rn\TalentRn;
use App\Rn\TotemGestaoRn;
use App\Rn\VioApiBrClient;
use Util\AuthGestao;
use Util\AuthServidor;
use Util\Conexao;
use Util\ConexaoGestaoColetas;
use Util\GestaoHttp;
use Util\LogSistema;
use Util\NotaArquivoStorage;
use Util\OrdemColetaArquivoStorage;

\Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
ini_set('log_errors', '1');
ini_set('error_log', (string) ($argv[2] ?? ''));

$cenario = (string) ($argv[1] ?? '');
$A = json_decode((string) ($argv[3] ?? '{}'), true);
$A = is_array($A) ? $A : [];
foreach (($A['env'] ?? []) as $k => $v) {
    $_ENV[(string) $k] = (string) $v;
}

register_shutdown_function(static function (): void {
    echo "\nHTTP_CODE:" . (http_response_code() ?: 200) . "\n";
});

/** Excecao de banco com SQLSTATE textual (o PDO real preenche code como string). */
class CasoPdoExc extends PDOException
{
    public function __construct(string $mensagem, string $codigo)
    {
        parent::__construct($mensagem);
        $this->code = $codigo;
    }
}

function casoPdo(): PDO
{
    return Conexao::obter();
}

function casoControllerAtendimento(array $extras = []): AtendimentoController
{
    $pdo = casoPdo();

    return new AtendimentoController(
        $extras['atendimentoRn'] ?? new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient(new OrdemColetaDao())),
        $extras['talentRn'] ?? new TalentRn(new TalentClient('', ''), new AtendimentoDao($pdo), (string) $_ENV['STORAGE_PATH']),
        new AtendimentoNotaDao($pdo),
        new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo)),
        new TotemDao($pdo),
        new EmpresaDao($pdo),
        $extras['ordemColetaClient'] ?? null,
        $extras['pendenciaDao'] ?? null,
        $extras['lgpdRn'] ?? null,
        $extras['pdo'] ?? null
    );
}

switch ($cenario) {
    case 'iniciar_502':
        // banco externo de coletas inexistente: consultarOrdensAbertas lanca => 502
        $pdo = casoPdo();
        $lgpd = new LgpdRn(new AceiteLgpdDao($pdo));
        $token = $lgpd->emitir((int) $A['id_totem'])['token_aceite'];
        $_ENV['GESTAO_COLETAS_DB_NAME'] = 'inexistente_qa_zzz';
        $c = casoControllerAtendimento(['lgpdRn' => $lgpd, 'pdo' => $pdo]);
        $c->iniciar((int) $A['id_totem'], ['tipo' => 'expedicao', 'placa' => (string) $A['placa'], 'token_aceite' => $token]);
        break;

    case 'coletas_sem_env':
        $_ENV['GESTAO_COLETAS_DB_NAME'] = '';
        try {
            ConexaoGestaoColetas::obter();
        } catch (Throwable $e) {
            echo 'EXC:' . get_class($e);
        }
        break;

    case 'coletas_vaza':
        // usuario sentinela invalido no MESMO servidor: o getMessage() real do driver cita
        // usuario e host ("Access denied for user 'SENT'@'host'")
        $_ENV['DB_USER'] = (string) $A['usuario'];
        $_ENV['DB_PASS'] = 'SENTINELA_SENHA_ZZ';
        $_ENV['GESTAO_COLETAS_DB_NAME'] = 'qualquer_banco_qa';
        try {
            ConexaoGestaoColetas::obter();
        } catch (Throwable $e) {
            echo 'EXC:' . get_class($e) . ':' . $e->getMessage();
        }
        break;

    case 'finalizar':
        // TalentRn dublê: devolve o resultado informado; OrdemColetaClient dublê: baixa sempre falha
        $_ENV['TALENT_CHECKIN_ATIVO'] = 'true';
        $pdo = casoPdo();
        $ret = $A['ret'];
        $talentRn = new class ($ret) extends TalentRn {
            public function __construct(private array $retorno)
            {
            }

            public function processarCheckin(array $atendimento, array $empresa, array $notas): array
            {
                return $this->retorno;
            }
        };
        $ocClient = null;
        $pendencia = null;
        if (($A['deps'] ?? 'sem') !== 'sem') {
            $ocClient = new class () extends OrdemColetaClient {
                public function __construct()
                {
                }

                public function marcarConcluida(string $cnpj, string $numero): bool
                {
                    return false;
                }

                public function statusAtual(string $cnpj, string $numero): ?string
                {
                    return 'ATIVA';
                }
            };
            if ($A['deps'] === 'pendencia_ok') {
                $pendencia = new OrdemColetaPendenteBaixaDao($pdo);
            } else {
                $pendencia = new class ($pdo) extends OrdemColetaPendenteBaixaDao {
                    public function registrar(int $idAtendimento, string $numeroOrdemColeta): void
                    {
                        throw new CasoPdoExc('SENT_MSG_PENDENCIA_CPF_11144477735', '42S02');
                    }
                };
            }
        }
        $c = casoControllerAtendimento(['talentRn' => $talentRn, 'ordemColetaClient' => $ocClient, 'pendenciaDao' => $pendencia]);
        $c->finalizar(['id_atendimento' => (int) $A['id_atendimento']], (int) $A['id_totem']);
        break;

    case 'etiqueta':
        $c = new ImpressaoAtendimentoController(new AtendimentoDao(casoPdo()));
        $c->gerarEtiqueta(['id_atendimento' => (int) $A['id_atendimento']], (int) $A['id_totem'], false);
        break;

    case 'etiqueta_pdf_falha':
        // FPDF dublê que lanca (declarado ANTES do autoload do FPDF real, que e lazy via classmap)
        eval('class FPDF { public function __construct(...$a) { throw new RuntimeException("SENT_FPDF_FALHA_cpf_11144477735"); } }');
        $c = new ImpressaoAtendimentoController(new AtendimentoDao(casoPdo()));
        $c->gerarEtiqueta(['id_atendimento' => (int) $A['id_atendimento']], (int) $A['id_totem'], false);
        break;

    case 'impressao_config':
        $c = new ImpressaoAtendimentoController(new AtendimentoDao(casoPdo()));
        $c->configuracaoServicoLocal();
        break;

    case 'copias_log':
        // as quatro copias privadas (hook dentro do corpo): excecao com mensagem sentinela
        $pdo = casoPdo();
        $pdoExc = new CasoPdoExc('SENT_MSG_PDO_SQL_SELECT_cpf_11144477735', '42S02');
        $rtExc = new RuntimeException('SENT_MSG_RT_CNPJ_11222333000181');
        $nota = new NotaController(new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo)), new AtendimentoDao($pdo), new RateLimitOcrDao($pdo));
        $m = new ReflectionMethod($nota, 'logFalhaBancoPdo');
        $m->setAccessible(true);
        $m->invoke($nota, 'ctx_nota_pdo', $pdoExc);
        $m = new ReflectionMethod($nota, 'logFalhaTecnica');
        $m->setAccessible(true);
        $m->invoke($nota, 'ctx_nota_tec', $rtExc);
        $doc = new DocumentoController(new AtendimentoDao($pdo));
        $m = new ReflectionMethod($doc, 'logFalhaTecnica');
        $m->setAccessible(true);
        $m->invoke($doc, 'ctx_doc_tec', new LogicException('SENT_MSG_LOGIC_PLACA_ABC1D23'));
        $rn = new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo));
        $m = new ReflectionMethod($rn, 'logFalhaTecnica');
        $m->setAccessible(true);
        $m->invoke($rn, 'ctx_rn_tec', new DomainException('SENT_MSG_DOM_TOKEN_ZZZ'), 5, 7);
        break;

    case 'nota_rate':
        $pdo = casoPdo();
        $modo = (string) $A['modo'];
        $dao = new class ($pdo, $modo) extends RateLimitOcrDao {
            public function __construct(PDO $pdo, private string $modo)
            {
                parent::__construct($pdo);
            }

            public function incrementarEContar(int $idTotem, int $janela): int
            {
                if ($this->modo === 'excecao') {
                    throw new RuntimeException('SENT_MSG_RATE_DEP');
                }

                return 31;
            }
        };
        $nota = new NotaController(new NotaFiscalRn(new AtendimentoNotaDao($pdo), new ClienteDao($pdo)), new AtendimentoDao($pdo), $dao);
        $nota->identificarCliente(['id_atendimento' => 1, 'id_nota' => 1], (int) $A['id_totem']);
        break;

    case 'vio_client':
        $mk = static fn (array $resp): VioApiBrClient => new VioApiBrClient(
            ['VIO_API_BR_BASE_URL' => 'https://vio.exemplo.test', 'VIO_API_BR_API_KEY' => 'SENT_CHAVE_VIO_ZZ'],
            static fn (): array => $resp
        );
        $mk(['erro' => ['tipo' => 'timeout_ambiguo'], 'http_status' => null, 'corpo' => null])->enviarParaLeitura('x');
        $mk(['erro' => null, 'http_status' => 401, 'corpo' => null])->enviarParaLeitura('x');
        $mk(['erro' => null, 'http_status' => 503, 'corpo' => null])->enviarParaLeitura('x');
        $mk(['erro' => ['tipo' => 'rede_sem_conexao'], 'http_status' => null, 'corpo' => null])->consultarResultado('abc123');
        $mk(['erro' => null, 'http_status' => 422, 'corpo' => null])->consultarResultado('abc123');
        echo 'FIM';
        break;

    case 'doc_vio':
        $pdo = casoPdo();
        $rn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo));
        $id = (int) $A['id_atendimento'];
        $r1 = $rn->avaliarResultadoVioApiBrCnh(['id_atendimento' => $id], qaLegadoResultadoVio('cnh', ['Nome' => ['SENT_NOME_ARRAY_CPF_11144477735']]));
        $r2 = $rn->avaliarResultadoVioApiBrCrlv(['id_atendimento' => $id], qaLegadoResultadoVio('crlv', ['Placa' => ['SENT_PLACA_ARRAY']]));
        $r3 = $rn->preencherManualCrlv(['id_atendimento' => $id], 'ABC1D23', ['SENT_EXERCICIO'], 'SP', 'x', 'CAMINHAO');
        echo json_encode([$r1['pode_avancar'], $r2['pode_avancar'], $r3['pode_avancar']]);
        break;

    case 'anexo':
        $chave = 'CHAVE_QA_' . bin2hex(random_bytes(8));
        $tipo = (string) $A['tipo'];
        $server = ['REQUEST_METHOD' => 'POST', 'HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.77'];
        $auth = 'Bearer ' . $chave;
        $cfg = $chave;
        $corpo = json_encode(['cnpj_cliente' => '11222333000181', 'numero_ordem_coleta' => 'OC-SENT-123', 'arquivo_base64' => base64_encode('nao e pdf SENT')]);
        if ($tipo === 'metodo') {
            $server['REQUEST_METHOD'] = 'GET';
        } elseif ($tipo === 'nao_autorizado') {
            $auth = 'Bearer errada';
        } elseif ($tipo === 'https') {
            unset($server['HTTPS']);
        } elseif ($tipo === 'indisponivel') {
            $cfg = '';
        } elseif ($tipo === 'corpo_invalido') {
            $corpo = 'isto nao e json SENT';
        } elseif ($tipo === 'grande') {
            $server['CONTENT_LENGTH'] = '999999999';
        }
        $server += ['CONTENT_LENGTH' => (string) strlen($corpo)];
        $rn = new OrdemColetaArquivoRn(new OrdemColetaArquivoDao(), new OrdemColetaArquivoStorage((string) $_ENV['STORAGE_PATH']), 5242880);
        if ($tipo === 'rn_excecao') {
            $rn = new class () extends OrdemColetaArquivoRn {
                public function __construct()
                {
                }

                public function receber(mixed $entrada): array
                {
                    throw new RuntimeException('SENT_MSG_RN_EXC_OC-SENT-123');
                }
            };
        }
        $ctrl = new OrdemColetaAnexoController(new AuthServidor($cfg, false, null), $rn, OrdemColetaArquivoRn::tetoCorpo(5242880));
        $ctrl->processar($server, $auth, static function () use ($corpo) {
            $h = fopen('php://memory', 'w+b');
            fwrite($h, $corpo);
            rewind($h);

            return $h;
        });
        break;

    case 'abandono_falha':
        // CAS perdido dentro da transacao do abandono: rollback do atendimento + log que SOBREVIVE (conexao propria)
        $pdo = casoPdo();
        $dao = new class ($pdo) extends AtendimentoDao {
            public function marcarAbandonado(int $id): bool
            {
                return false;
            }
        };
        $rn = new AbandonoAtendimentoRn($dao, new AtendimentoNotaDao($pdo), new NotaArquivoStorage((string) $_ENV['STORAGE_PATH']));
        $res = $rn->executar();
        echo json_encode(['falhas' => $res['falhas'], 'abandonados' => $res['abandonados']]);
        break;

    case 'gestao_handler':
        GestaoHttp::registrarTratadorDeExcecao();
        throw new RuntimeException('SENT_SEGREDO_GESTAO_senha_hunter2');

    case 'auditoria_falhou':
        $pdo = casoPdo();
        // AuthGestao: auditarSemFalhar (callable lanca)
        $ag = (new ReflectionClass(AuthGestao::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($ag, 'auditarSemFalhar');
        $m->setAccessible(true);
        $m->invoke($ag, static function (): void {
            throw new CasoPdoExc('SENT_MSG_AUD_login_bruno.carvalho', '42S02');
        });
        // TotemGestaoRn::fecharAuditoria com a tabela de auditoria ausente (falha real do banco)
        $pdo->exec('RENAME TABLE tb_gestao_auditoria TO tb_gestao_auditoria_x');
        try {
            $rn = new TotemGestaoRn($pdo, 'https://totem.exemplo.test/totem/');
            $m = new ReflectionMethod($rn, 'fecharAuditoria');
            $m->setAccessible(true);
            $m->invoke($rn, 1, 'OK');
        } finally {
            $pdo->exec('RENAME TABLE tb_gestao_auditoria_x TO tb_gestao_auditoria');
        }
        echo 'FIM';
        break;

    default:
        fwrite(STDERR, "cenario desconhecido\n");
        exit(2);
}
