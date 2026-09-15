<?php

/**
 * Router do servidor HTTP mock usado por
 * tests/manual/teste_talent_client_parsing.php (php -S 127.0.0.1:PORTA
 * _router_talent_mock.php) — NUNCA e a URL real do Talent
 * (api.talentcs.com.br), so localhost. O cenario desejado e escolhido pelo
 * valor do header Authorization (Bearer cenario_XXX), que
 * App\Rn\TalentClient::checkin() ja envia normalmente (usado aqui so como
 * canal de controle do teste, nunca em producao).
 */

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$cenario = str_starts_with($auth, 'Bearer cenario_') ? substr($auth, strlen('Bearer cenario_')) : '';

header('Content-Type: application/json; charset=utf-8');

switch ($cenario) {
    case 'sucesso_200':
        http_response_code(200);
        echo json_encode(['nrRegAcesso' => 'ABC123', 'msg' => null]);
        break;
    case 'sucesso_201_sem_msg':
        http_response_code(201);
        echo json_encode(['nrRegAcesso' => 'XYZ789']);
        break;
    case 'conflito_409':
        http_response_code(409);
        echo json_encode(['nrRegAcesso' => null, 'msg' => 'violacao de regra de negocio']);
        break;
    case 'erro_servidor_500':
        http_response_code(500);
        echo json_encode(['erro' => 'falha interna simulada']);
        break;
    case 'corpo_ilegivel_200':
        http_response_code(200);
        echo 'isto nao e JSON valido {{{';
        break;
    case 'campo_ausente_200':
        http_response_code(200);
        echo json_encode(['msg' => 'sucesso sem nrRegAcesso']);
        break;
    default:
        http_response_code(404);
        echo json_encode(['erro' => 'cenario de teste desconhecido']);
}
