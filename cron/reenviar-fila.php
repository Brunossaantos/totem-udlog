<?php
// Rodar via cron do cPanel a cada minuto: php /caminho/cron/reenviar-fila.php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Util\Conexao;
use App\Dao\FilaEnvioDao;
use App\Dao\AtendimentoDao;
use App\Dao\AtendimentoNotaDao;
use App\Rn\TalentRn;
use App\Rn\TalentClient;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$pdo = Conexao::obter();
$filaDao = new FilaEnvioDao($pdo);
$atendimentoDao = new AtendimentoDao($pdo);
$notaDao = new AtendimentoNotaDao($pdo);

$talentClient = new TalentClient($_ENV['TALENT_API_URL'] ?? '', $_ENV['TALENT_API_KEY'] ?? '');
$talentRn = new TalentRn($talentClient, $filaDao, $_ENV['STORAGE_PATH']);

foreach ($filaDao->buscarPendentes() as $item) {
    $atendimento = $atendimentoDao->buscarPorId((int) $item['id_atendimento']);
    if (!$atendimento) {
        continue;
    }

    $notas = $notaDao->listarPorAtendimento((int) $item['id_atendimento']);
    $payload = $talentRn->montarPayload($atendimento, $notas);

    try {
        $resultado = $talentRn->enviar($payload);
        $atendimentoDao->finalizar((int) $item['id_atendimento'], $resultado['senha'], $resultado['protocolo']);
        $filaDao->marcarSucesso((int) $item['id_fila']);
    } catch (\Throwable $e) {
        $filaDao->registrarNovaTentativa((int) $item['id_fila'], $e->getMessage());
    }
}
