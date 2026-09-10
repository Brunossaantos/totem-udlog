<?php
// Rodar via cron do cPanel a cada minuto: php /caminho/cron/reenviar-fila.php
//
// Reescrito na demanda integracao-talent-portaria-checkin (2026-09-09): so
// reenvia atendimentos com talent_checkin_status = 'ERRO_REPROCESSAVEL'
// (FilaEnvioDao::buscarPendentes ja filtra isso via JOIN) — NUNCA
// 'ENVIO_INDETERMINADO'/'ENVIANDO'/'ENVIADO'. Reaproveita a MESMA maquina de
// estados/CAS de App\Rn\TalentRn::processarCheckin usada por
// AtendimentoController::finalizar(), para nunca ter um caminho de reenvio
// que ignore o estado atual.

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\FilaEnvioDao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Rn\TalentRn;
use App\Rn\TalentClient;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$pdo = Conexao::obter();
$filaDao = new FilaEnvioDao($pdo);
$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);
$totemDao = new TotemDao($pdo);
$empresaDao = new EmpresaDao($pdo);

$talentClient = new TalentClient($_ENV['TALENT_API_URL'] ?? '', $_ENV['TALENT_API_KEY'] ?? '');
$talentRn = new TalentRn($talentClient, $filaDao, $atendimentoDao, $_ENV['STORAGE_PATH']);

foreach ($filaDao->buscarPendentes() as $item) {
    $idAtendimento = (int) $item['id_atendimento'];
    $idFila = (int) $item['id_fila'];

    $atendimento = $atendimentoDao->buscarPorId($idAtendimento);
    if (!$atendimento) {
        continue;
    }

    $totem = $totemDao->buscarPorId((int) $atendimento['id_totem']);
    $idEmpresa = $totem['id_empresa'] ?? null;
    $empresa = $idEmpresa !== null ? $empresaDao->buscarPorId((int) $idEmpresa) : null;

    if ($empresa === null) {
        // Totem sem empresa configurada — nao ha como montar cnpjArmazem,
        // categoria interna sanitizada, nunca corpo bruto.
        $filaDao->registrarNovaTentativa($idFila, 'cnpj_armazem_indisponivel');
        continue;
    }

    $notas = $notaDao->listarPorAtendimento($idAtendimento);

    $resultado = $talentRn->processarCheckin($atendimento, $empresa, $notas);

    switch ($resultado['status']) {
        case 'ENVIADO':
        case 'JA_ENVIADO':
            $filaDao->marcarSucesso($idFila);
            break;
        case 'ENVIO_INDETERMINADO':
            // NUNCA reenviado automaticamente — exige conferencia manual no
            // painel do Talent antes de qualquer novo envio.
            $filaDao->interromperPorIndeterminado($idFila);
            break;
        case 'ERRO_REPROCESSAVEL':
            $filaDao->registrarNovaTentativa($idFila, $resultado['erro_categoria'] ?? 'erro_desconhecido');
            break;
        case 'EM_ANDAMENTO':
        case 'INDETERMINADO_PENDENTE_MANUAL':
        default:
            // Estado mudou por outro caminho entre a leitura da fila e esta
            // chamada (ex.: outra requisicao concorrente) — nao mexe na
            // fila, a proxima execucao do cron reavalia o estado atual.
            break;
    }
}
