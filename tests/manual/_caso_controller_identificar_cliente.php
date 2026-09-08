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

    default:
        fwrite(STDERR, "cenario desconhecido: $cenario\n");
        exit(2);
}

$controller = new NotaController($rn, $atendimentoDao, null);

// candidato deliberadamente inexistente em qualquer cenario -- nenhum destes
// casos depende de identificar um cliente real de verdade.
$controller->identificarCliente([
    'id_atendimento'           => $idAt,
    'ordem'                    => 1,
    'cnpjs_candidatos'         => ['00000000000000'],
    'razao_social_candidata'   => null,
], $idTotem);
