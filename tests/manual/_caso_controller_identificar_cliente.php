<?php
/**
 * Executado em processo PHP separado (subprocesso), disparado por
 * teste_identificar_cliente.php, exclusivamente para os casos que exercitam
 * App\Controller\NotaController::identificarCliente() de verdade.
 *
 * Motivo do subprocesso: Util\Resposta::erro()/sucesso() chamam exit() ao
 * final. Chamar o Controller diretamente dentro do processo principal do
 * script de teste encerraria a suite inteira no primeiro caso (matando
 * tambem os casos de NotaFiscalRn::identificarCliente que ainda faltassem
 * rodar). Isolando em subprocesso, so aquele processo filho termina; o
 * processo pai apenas le a saida (JSON impresso por Resposta::sucesso/erro)
 * e o codigo de saida via proc_open/exec.
 *
 * Uso: php _caso_controller_identificar_cliente.php <cenario>
 * Cenarios:
 *   - status_errado    : atendimento com status != 'em_andamento'
 *   - etapa_errada     : atendimento com etapa_atual != 'digitalizacao_notas'
 *   - excecao          : NotaFiscalRn::identificarCliente lanca excecao nao
 *                         prevista -- confirma que o Controller nao vaza a
 *                         mensagem tecnica da excecao na resposta ao totem
 *   - ok_feliz         : caminho feliz (status/etapa corretos, RN real sem
 *                         nenhum candidato cadastrado) -- confirma que a
 *                         validacao nova nao bloqueia o fluxo legitimo
 *   - dao_ausente      : demanda robustez-rate-limit-migrations (2026-09-18)
 *                         -- ate essa demanda este cenario construia o
 *                         Controller com RateLimitOcrDao=null (fail-open
 *                         antigo: pulava a checagem silenciosamente). Isso
 *                         NAO existe mais -- o construtor agora exige
 *                         RateLimitOcrDao nao-nulo (TypeError na propria
 *                         construcao, ver item 6 da matriz de testes). Este
 *                         cenario foi REDESENHADO para validar o equivalente
 *                         atual: um NotaController montado via Reflection
 *                         (newInstanceWithoutConstructor), com
 *                         notaFiscalRn/atendimentoDao injetados normalmente
 *                         mas a propriedade rateLimitOcrDao deixada
 *                         DELIBERADAMENTE nao inicializada (nao e possivel
 *                         forcar null nela nem via Reflection::setValue --
 *                         PHP recusa com TypeError, confirmado empiricamente
 *                         nesta rodada de testes). O bloco catch(\Error)
 *                         abaixo documentava, ate a rodada curta de
 *                         /01-implementacao de 2026-09-18, o resultado
 *                         REAL observado NAQUELE momento (Error de
 *                         propriedade tipada nao inicializada, NAO
 *                         capturado por nenhum try/catch de NotaController
 *                         -- achado do qa-testes nesta mesma rodada de
 *                         /02-testes). CORRIGIDO NA MESMA DEMANDA (ver
 *                         NotaController::verificarRateLimit(), que hoje
 *                         envolve o acesso a rateLimitOcrDao num
 *                         try/catch(\Throwable) real): o comportamento
 *                         ATUAL confirmado e um HTTP 503 sanitizado via
 *                         Resposta::erro() (que chama exit() internamente)
 *                         -- o bloco catch(\Error) abaixo tornou-se codigo
 *                         morto/inalcancavel apos essa correcao, mantido
 *                         de proposito sem alteracao (nao mexemos em
 *                         instrucao executavel deste script) apenas para
 *                         nao perder o rastro historico do cenario; se o
 *                         catch(\Error) algum dia voltar a ser alcancado,
 *                         isso por si so indicaria uma regressao do
 *                         fail-closed corrigido nesta demanda.
 *
 * Cria seus proprios dados de fixture com a MESMA placa/codigo de totem de
 * teste do script principal (ver _fixtures_identificar_cliente.php) -- a
 * limpeza final continua acontecendo no finally do script principal
 * (teste_identificar_cliente.php roda por ultimo, apos todos os
 * subprocessos terem sido disparados).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/_fixtures_identificar_cliente.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\RateLimitOcrDao;
use App\Rn\NotaFiscalRn;
use App\Controller\NotaController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$cenario = $argv[1] ?? '';

// Dublê que forca uma excecao nao prevista dentro da chamada de negocio, sem
// tocar o banco real de clientes -- so para confirmar que o Controller
// (nao a Rn) trata esse tipo de falha sem vazar detalhe tecnico.
class NotaFiscalRnLancaExcecao extends NotaFiscalRn
{
    public function __construct()
    {
        // nao chama o construtor pai -- nunca precisa de DAOs reais para
        // este cenario.
    }

    // Sobrescrito tambem porque o Controller chama buscarNotaDaOrdem() ANTES
    // de identificarCliente() -- sem sobrescrever, o metodo herdado tentaria
    // usar a propriedade tipada $notaDao, nunca inicializada (construtor
    // deliberadamente vazio acima), e estouraria um erro de "typed property
    // must not be accessed before initialization" antes mesmo de chegar no
    // cenario que este dublê existe para simular.
    public function buscarNotaDaOrdem(int $idAtendimento, int $ordem): ?array
    {
        return ['id_nota' => 1, 'ordem' => $ordem];
    }

    public function identificarCliente(
        int $idAtendimento,
        int $idNota,
        ?string $chaveOcr,
        array $cnpjsCandidatos,
        ?string $razaoSocialCandidata
    ): array {
        throw new \RuntimeException('detalhe tecnico sensivel que jamais pode vazar na resposta ao totem');
    }
}

$idTotem = obterOuCriarTotemTeste($pdo, CODIGO_TOTEM_TESTE);
$atendimentoDao = new AtendimentoDao($pdo);
$notaDaoReal = new AtendimentoNotaDao($pdo);

switch ($cenario) {
    case 'status_errado':
        $idAt = criarAtendimentoTeste($pdo, $idTotem, 'finalizado', 'digitalizacao_notas');
        criarNotaTeste($pdo, $idAt, 1);
        $rn = new NotaFiscalRn($notaDaoReal, new ClienteDao($pdo));
        break;

    case 'etapa_errada':
        $idAt = criarAtendimentoTeste($pdo, $idTotem, 'em_andamento', 'confirmacao');
        criarNotaTeste($pdo, $idAt, 1);
        $rn = new NotaFiscalRn($notaDaoReal, new ClienteDao($pdo));
        break;

    case 'excecao':
        $idAt = criarAtendimentoTeste($pdo, $idTotem, 'em_andamento', 'digitalizacao_notas');
        criarNotaTeste($pdo, $idAt, 1);
        $rn = new NotaFiscalRnLancaExcecao();
        break;

    case 'ok_feliz':
        $idAt = criarAtendimentoTeste($pdo, $idTotem, 'em_andamento', 'digitalizacao_notas');
        criarNotaTeste($pdo, $idAt, 1);
        // ClienteDao real, mas candidato inexistente -- nao identifica
        // ninguem de verdade, so confirma que o caminho feliz (200/sucesso)
        // continua acessivel com a validacao nova de status/etapa.
        $rn = new NotaFiscalRn($notaDaoReal, new ClienteDao($pdo));
        break;

    case 'dao_ausente':
        $idAt = criarAtendimentoTeste($pdo, $idTotem, 'em_andamento', 'digitalizacao_notas');
        criarNotaTeste($pdo, $idAt, 1);

        // Spy: confirma que NotaFiscalRn::identificarCliente() NUNCA e
        // alcancado quando a dependencia de rate limit esta ausente/invalida.
        $rnSpy = new class extends NotaFiscalRn {
            public int $chamadas = 0;
            public function __construct() {}
            public function buscarNotaDaOrdem(int $idAtendimento, int $ordem): ?array
            {
                return ['id_nota' => 1, 'ordem' => $ordem];
            }
            public function identificarCliente(
                int $idAtendimento,
                int $idNota,
                ?string $chaveOcr,
                array $cnpjsCandidatos,
                ?string $razaoSocialCandidata
            ): array {
                $this->chamadas++;
                return ['status' => 'NAO_DEVERIA_TER_SIDO_CHAMADO'];
            }
        };

        // NotaController montado via Reflection, SEM passar pelo construtor
        // normal -- a propriedade rateLimitOcrDao fica deliberadamente NAO
        // inicializada. Nao ha forma de forcar null nela (nem construtor nem
        // Reflection::setValue aceitam null num parametro/propriedade
        // tipada nao-nullable -- confirmado empiricamente: ambos lancam
        // TypeError). Esta e a UNICA forma de reproduzir "dependencia
        // ausente" apos o fail-closed estrutural desta demanda.
        $ref = new \ReflectionClass(NotaController::class);
        $controller = $ref->newInstanceWithoutConstructor();
        $propNota = $ref->getProperty('notaFiscalRn');
        $propNota->setAccessible(true);
        $propNota->setValue($controller, $rnSpy);
        $propAt = $ref->getProperty('atendimentoDao');
        $propAt->setAccessible(true);
        $propAt->setValue($controller, $atendimentoDao);
        // rateLimitOcrDao: NAO setado -- permanece "uninitialized typed property".

        $entrada = [
            'id_atendimento'           => $idAt,
            'ordem'                    => 1,
            'cnpjs_candidatos'         => ['00000000000000'],
            'razao_social_candidata'   => null,
        ];

        try {
            $controller->identificarCliente($entrada, $idTotem);
        } catch (\Error $e) {
            // ATE a rodada curta de /01-implementacao de 2026-09-18, o
            // resultado REAL observado aqui era um Error de propriedade
            // tipada nao inicializada, NAO capturado por nenhum
            // try/catch de NotaController (so capturava excecoes do tipo PDOException).
            // CORRIGIDO NA MESMA DEMANDA: NotaController::verificarRateLimit()
            // hoje envolve esse acesso num try/catch(Throwable) real -- o
            // comportamento ATUAL confirmado e um HTTP 503 sanitizado via
            // Resposta::erro() (que chama exit() internamente), entao este
            // catch (Error) nunca mais e alcancado na pratica. Mantido sem
            // alteracao de comportamento (string de saida abaixo preservada
            // tal como estava) apenas como sentinela historica -- se este
            // catch voltar a ser alcancado no futuro, isso por si so indica
            // uma regressao do fail-closed corrigido nesta demanda.
            echo json_encode([
                'sucesso' => false,
                'cenario_dao_ausente_resultado_real' => 'ERROR_NAO_CAPTURADO',
                'classe_excecao' => get_class($e),
                'rn_spy_chamadas' => $rnSpy->chamadas,
            ]) . "\n";
            echo 'HTTP_CODE:' . (http_response_code() ?: 0) . "\n";
            exit(0);
        }
        exit(0);

    default:
        fwrite(STDERR, "cenario desconhecido: $cenario\n");
        exit(2);
}

$controller = new NotaController($rn, $atendimentoDao, new RateLimitOcrDao($pdo));

// candidato deliberadamente inexistente em qualquer cenario -- nenhum destes
// casos depende de identificar um cliente real de verdade.
$controller->identificarCliente([
    'id_atendimento'           => $idAt,
    'ordem'                    => 1,
    'cnpjs_candidatos'         => ['00000000000000'],
    'razao_social_candidata'   => null,
], $idTotem);
