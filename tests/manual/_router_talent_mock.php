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
    // Cenarios de erro com corpo {msg} (teste_talent_mensagem_api_finalizar.php)
    case 'msg_409':
    case 'msg_422':
    case 'msg_400':
        http_response_code((int) substr($cenario, 4));
        echo json_encode(['nrRegAcesso' => null, 'msg' => 'Reg. acesso ajudante. Cracha ja associado a outro ajudante. Acao nao permitida.']);
        break;
    // Recusa so quando o ajudante enviado tem o CPF "ja associado" (52998224725);
    // qualquer outro ajudante (ou nenhum) e aceito (teste_talent_correcao_ajudante.php).
    case 'ajudante_cracha':
        $corpo = json_decode((string) file_get_contents('php://input'), true);
        $cpfAjudante = is_array($corpo) ? ($corpo['ajudantes'][0]['cpf'] ?? null) : null;
        if ($cpfAjudante === '52998224725') {
            http_response_code(409);
            echo json_encode(['nrRegAcesso' => null, 'msg' => 'Reg. acesso ajudante. Cracha ja associado a outro ajudante. Acao nao permitida.']);
        } else {
            http_response_code(200);
            echo json_encode(['nrRegAcesso' => 'AJU' . substr((string) $cpfAjudante, 0, 3), 'msg' => null]);
        }
        break;
    case 'msg_suja_longa':
        http_response_code(409);
        echo json_encode(['msg' => "  Linha1\r\n\t\x00\x07\x1b[31mLinha2   " . str_repeat('A', 400)]);
        break;
    case 'msg_html':
        http_response_code(422);
        echo json_encode(['msg' => '<script>alert(1)</script><b>x</b> & "aspas"']);
        break;
    case 'texto_cru_400':
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Falha\tde validacao sem JSON";
        break;
    case 'corpo_vazio_500':
        http_response_code(500);
        break;
    default:
        http_response_code(404);
        echo json_encode(['erro' => 'cenario de teste desconhecido']);
}
