<?php
// CLI de manutencao da integracao com o Trello (quadro "Infraestrutura -
// Matriz"), usado pelo workflow de 5 etapas do totem-udlog e por manutencao
// manual do sub-agente trello-especialista. NUNCA imprime
// TRELLO_API_KEY/TRELLO_API_TOKEN em nenhuma saida — ver
// docs/trello-integracao.md.
//
// Uso: php tools/trello-cli.php <comando> [args...]
// Comandos:
//   validar-conexao
//   buscar-lista "<nome exato>"
//   criar-cartao <idLista> "<titulo>" ["<descricao>"] ["<posicao>"]
//     posicao: 'top' (padrao — cartao sempre criado no topo da lista),
//     'bottom', ou um numero positivo aceito pelo parametro `pos` da API
//     do Trello. Omitir para usar o padrao (top).
//   listar-cartoes <idLista>
//     Lista cartoes abertos (id + titulo) de uma lista, usado para checagem
//     de idempotencia por titulo quando ainda nao existe card_id salvo.
//   consultar-cartao <idCartao>
//   comentar <idCartao> "<texto>"
//   mover-cartao <idCartao> <idListaDestino>
//   marcar-concluida <idCartao> [dataIso8601Opcional]
//     Define a data de conclusao nativa do Trello (`due` + `dueComplete`),
//     que aparece como selo com check verde diretamente no cartao. Se a
//     data for omitida, usa a data/hora atual do servidor (UTC).
//
// Compativel com Windows (sem posix_*, sem shebang exigido).

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Rn\TrelloClient;
use App\Rn\TrelloClientException;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

function trelloCliErro(string $mensagem): void
{
    fwrite(STDERR, "ERRO: {$mensagem}\n");
    exit(1);
}

$comando = $argv[1] ?? null;
if ($comando === null) {
    trelloCliErro('comando ausente. Uso: php tools/trello-cli.php <comando> [args...]');
}

$client = new TrelloClient(
    $_ENV['TRELLO_API_KEY'] ?? '',
    $_ENV['TRELLO_API_TOKEN'] ?? '',
    $_ENV['TRELLO_BOARD_ID'] ?? ''
);

try {
    switch ($comando) {
        case 'validar-conexao':
            $ok = $client->validarConexao();
            echo $ok ? "OK: conexao valida com o board.\n" : "FALHA: nao foi possivel autenticar/acessar o board.\n";
            exit($ok ? 0 : 1);

        case 'buscar-lista':
            $nome = $argv[2] ?? trelloCliErro('uso: buscar-lista "<nome exato>"');
            $lista = $client->buscarListaPorNome($nome);
            echo json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;

        case 'criar-cartao':
            $idLista = $argv[2] ?? trelloCliErro('uso: criar-cartao <idLista> "<titulo>" ["<descricao>"] ["<posicao>"]');
            $titulo = $argv[3] ?? trelloCliErro('titulo ausente');
            $descricao = $argv[4] ?? null;
            $posicao = $argv[5] ?? 'top';
            $cartao = $client->criarCartao($idLista, $titulo, $descricao, $posicao);
            echo json_encode(['id' => $cartao['id'] ?? null, 'name' => $cartao['name'] ?? null, 'url' => $cartao['url'] ?? null, 'pos' => $cartao['pos'] ?? null], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;

        case 'listar-cartoes':
            $idLista = $argv[2] ?? trelloCliErro('uso: listar-cartoes <idLista>');
            $cartoes = $client->listarCartoesDaLista($idLista);
            echo json_encode($cartoes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;

        case 'consultar-cartao':
            $idCartao = $argv[2] ?? trelloCliErro('uso: consultar-cartao <idCartao>');
            $cartao = $client->consultarCartao($idCartao);
            echo json_encode($cartao, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;

        case 'comentar':
            $idCartao = $argv[2] ?? trelloCliErro('uso: comentar <idCartao> "<texto>"');
            $texto = $argv[3] ?? trelloCliErro('texto ausente');
            $resultado = $client->adicionarComentario($idCartao, $texto);
            echo json_encode(['id' => $resultado['id'] ?? null], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;

        case 'mover-cartao':
            $idCartao = $argv[2] ?? trelloCliErro('uso: mover-cartao <idCartao> <idListaDestino>');
            $idListaDestino = $argv[3] ?? trelloCliErro('idListaDestino ausente');
            $cartao = $client->moverCartao($idCartao, $idListaDestino);
            echo json_encode(['id' => $cartao['id'] ?? null, 'idList' => $cartao['idList'] ?? null], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;

        case 'marcar-concluida':
            $idCartao = $argv[2] ?? trelloCliErro('uso: marcar-concluida <idCartao> [dataIso8601Opcional]');
            $dataIso8601 = $argv[3] ?? null;
            $cartao = $client->marcarConcluida($idCartao, $dataIso8601);
            echo json_encode(['id' => $cartao['id'] ?? null, 'due' => $cartao['due'] ?? null, 'dueComplete' => $cartao['dueComplete'] ?? null], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;

        default:
            trelloCliErro("comando desconhecido: {$comando}");
    }
} catch (TrelloClientException $e) {
    // Mensagem sempre categorizada/sanitizada — nunca contem credencial.
    trelloCliErro('Trello: ' . $e->getMessage());
} catch (\Throwable $e) {
    trelloCliErro('falha inesperada na integracao com o Trello.');
}
