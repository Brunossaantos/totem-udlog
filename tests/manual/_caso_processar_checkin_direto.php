<?php

/**
 * Subprocesso auxiliar (demanda talent-doctos-finalizacao-checkin,
 * 2026-09-14) — chama App\Rn\TalentRn::processarCheckin() diretamente com um
 * TalentClient MOCK (NUNCA rede real), usado para exercitar o CAS de
 * idempotencia (talent_checkin_status, 5 estados) sob CONCORRENCIA REAL (2
 * processos, ver teste_concorrencia_finalizar_checkin.php) com o payload NOVO
 * (doctos[] real montado a partir das notas/ordem_coleta ja persistidas) —
 * bypassa deliberadamente o gate TALENT_CHECKIN_ATIVO do controller (que so
 * seria testavel em ambiente com a flag ligada) para confirmar que o CAS
 * de baixo nivel, reaproveitado sem alteracao, continua correto com o novo
 * payload.
 *
 * Uso: php _caso_processar_checkin_direto.php <id_atendimento>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Dao\FilaEnvioDao;
use App\Dao\EmpresaDao;
use App\Dao\TotemDao;
use App\Rn\TalentRn;
use App\Rn\TalentClient;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$pdo = Conexao::obter();

$idAtendimento = (int) ($argv[1] ?? 0);

/**
 * Cliente mock — NUNCA rede real, devolve sucesso canned apos uma pequena
 * pausa aleatoria (aumenta a chance real de sobreposicao entre os 2
 * processos disputando o mesmo atendimento).
 */
class TalentClientMockConcorrencia extends TalentClient
{
    public function __construct()
    {
        parent::__construct('', '');
    }

    public function checkin(array $payload): array
    {
        usleep(random_int(10000, 60000));
        return ['senha' => 'SENHA_CONC_MOCK', 'protocolo' => 'PROTO_CONC_MOCK'];
    }
}

$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);
$empresaDao = new EmpresaDao($pdo);
$totemDao = new TotemDao($pdo);

$atendimento = $atendimentoDao->buscarPorId($idAtendimento);
$totem = $totemDao->buscarPorId((int) $atendimento['id_totem']);
$empresa = $empresaDao->buscarPorId((int) $totem['id_empresa']);
$notas = $notaDao->listarPorAtendimento($idAtendimento);

$talentRn = new TalentRn(new TalentClientMockConcorrencia(), new FilaEnvioDao($pdo), $atendimentoDao, $_ENV['STORAGE_PATH']);
$resultado = $talentRn->processarCheckin($atendimento, $empresa, $notas);

echo json_encode($resultado) . "\n";
