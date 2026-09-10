<?php

/**
 * Subprocesso auxiliar de tests/manual/teste_talent_trava_doctos_pendente.php
 * — chama AtendimentoController::finalizar() diretamente, mas usando
 * TalentClient com TALENT_API_URL/TALENT_API_KEY REAIS do .env (mesma
 * configuracao usada por public/api/atendimento.php em producao) e um
 * TalentRnEspiao que lanca excecao caso processarCheckin() seja invocado —
 * prova que a trava TALENT_DOCTOS_PENDENTE bloqueia ANTES de qualquer
 * tentativa de uso do TalentClient real, mesmo com credenciais reais
 * configuradas (nao e so ausencia de config que impede a chamada).
 *
 * Uso: php _caso_trava_doctos_pendente.php <id_totem> <id_atendimento>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\VioCacheDao;
use App\Dao\FilaEnvioDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Rn\AtendimentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\TalentRn;
use App\Rn\TalentClient;
use App\Rn\DocumentoRn;
use App\Controller\AtendimentoController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idTotem = (int) ($argv[1] ?? 0);
$idAtendimento = (int) ($argv[2] ?? 0);

/**
 * Espiao: se este metodo for chamado, imprime um marcador exclusivo e lanca
 * excecao antes de qualquer possibilidade de montar payload/chamar o
 * TalentClient real — o teste-pai verifica ausencia desse marcador.
 */
class TalentRnEspiao extends TalentRn
{
    public function processarCheckin(array $atendimento, array $empresa, array $notas): array
    {
        echo "ESPIAO_PROCESSARCHECKIN_CHAMADO\n";
        throw new \RuntimeException('TalentRnEspiao: processarCheckin() NUNCA deveria ser chamado enquanto TALENT_DOCTOS_PENDENTE estiver ativo');
    }
}

// TalentClient com URL/token REAIS do .env (mesma config de producao) —
// propositalmente, para provar que a trava bloqueia mesmo com credenciais
// reais validas, nao por falta de configuracao.
$talentClient = new TalentClient($_ENV['TALENT_API_URL'] ?? '', $_ENV['TALENT_API_KEY'] ?? '');

$atendimentoRn = new AtendimentoRn(new AtendimentoDao($pdo), new OrdemColetaClient('', ''));
$talentRnEspiao = new TalentRnEspiao($talentClient, new FilaEnvioDao($pdo), new AtendimentoDao($pdo), $_ENV['STORAGE_PATH']);
$documentoRn = new DocumentoRn(new VioCacheDao($pdo), new AtendimentoDao($pdo));

$controller = new AtendimentoController(
    $atendimentoRn,
    $talentRnEspiao,
    new AtendimentoNotaDao($pdo),
    $documentoRn,
    new TotemDao($pdo),
    new EmpresaDao($pdo)
);

// Resposta::erro()/sucesso() chamam exit() apos http_response_code() —
// register_shutdown_function roda ANTES do processo realmente terminar,
// permitindo capturar o codigo HTTP real que teria sido enviado.
register_shutdown_function(function () {
    echo "\nHTTP_CODE:" . http_response_code() . "\n";
});

$controller->finalizar(['id_atendimento' => $idAtendimento], $idTotem);
