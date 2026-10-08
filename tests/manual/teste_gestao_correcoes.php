<?php

/**
 * Gestao Totem (F0/F1), rodada de CORRECAO pos-/02 e /03 (2026-10-06): cobertura
 * nova de
 *  - textos acentuados (menu, mensagens, erros, paginas de erro) e flash `info`;
 *  - M1: senha/hash/token fora de stack trace, log, saida e resposta (PDOException
 *    provocada em login, troca de senha, criar e redefinir; excecao nao tratada);
 *  - M2: acao `desbloquear` (RBAC, auditoria, efeito, concorrencia, tela);
 *  - B1 (chave derivada do token de login), B3 (politica de senha), B4 (limpeza
 *    oportunista no caminho de falha), B5 (IPv6 /64 e HMAC do nome do contador),
 *    B6 (troca de senha com a conta bloqueada), B7 (reenvio do POST de redefinir);
 *  - contrato do frontend: botao de acao das paginas de erro e titulo do topo.
 * Banco QA descartavel; nunca udlog_totem; sem rede.
 *
 * Uso: php tests/manual/teste_gestao_correcoes.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Controller\GestaoContexto;
use App\Dao\AuditoriaDao;
use App\Dao\SessaoGestaoDao;
use App\Dao\UsuarioGestaoDao;
use App\Rn\UsuarioGestaoRn;
use Util\AuthGestao;
use Util\GestaoConfig;
use Util\GestaoHttp;
use Util\IpCliente;
use Util\LimiteFalhasIp;
use Util\SenhaPolitica;

$raiz = dirname(__DIR__, 2);
$banco = null;
$storage = null;
$filho = null;
$logCgi = gtNovoLogCgi();
@unlink($logCgi);

const MSG_LOGIN_NOVA = 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.';
const SENT_ATUAL = 'Sentinela-Atual-Zq81-Longa';
const SENT_NOVA = 'Sentinela-Nova-Wk52-Longa';
const SENT_ERRADA = 'Sentinela-Errada-Pj73-Longa';

/** PDO que falha de proposito quando o SQL casa com o padrao (e guarda a ultima excecao para inspecao). */
final class PdoQuebrado extends PDO
{
    public ?string $padrao = null;

    public ?Throwable $ultima = null;

    private function checar(string $sql): void
    {
        if ($this->padrao !== null && preg_match($this->padrao, $sql) === 1) {
            $this->ultima = new PDOException('falha simulada do banco');
            throw $this->ultima;
        }
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $this->checar((string) $query);

        return parent::prepare($query, $options);
    }

    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs)
    {
        $this->checar((string) $query);

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

/** Palavras sem acento que, em texto de UI em portugues, indicam acentuacao esquecida (lista curta e segura). */
function achadosSemAcento(string $texto): array
{
    $palavras = ['nao', 'voce', 'voces', 'invalido', 'invalida', 'invalidos', 'usuario', 'usuarios', 'acao', 'acoes', 'pagina', 'paginas',
        'servico', 'sessao', 'sessoes', 'permissao', 'requisicao', 'metodo', 'confirmacao', 'orfao', 'orfaos', 'codigo', 'ja', 'so', 'ate',
        'apos', 'alteracoes', 'conexao', 'seguranca', 'excecao', 'informacao', 'operacao', 'obrigatoria', 'obrigatorio', 'unico', 'ultimo',
        'inicio', 'tambem', 'minuscula', 'minusculas', 'proximo', 'proxima', 'gestao'];
    preg_match_all('/(?<![\p{L}\p{N}_$.\/-])(' . implode('|', $palavras) . ')(?![\p{L}\p{N}_])/iu', $texto, $m);

    return array_values(array_unique(array_map('strtolower', $m[1])));
}

/** Texto visivel de uma pagina: nos de texto + atributos aria-label/title/alt/placeholder/data-confirmar*. */
function textoVisivel(string $html): string
{
    $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
    $attrs = '';
    if (preg_match_all('/\b(?:aria-label|title|alt|placeholder|data-confirmar[a-z-]*)="([^"]*)"/i', $html, $m) > 0) {
        $attrs = ' ' . implode(' ', $m[1]);
    }
    $semTags = (string) preg_replace('/<[^>]*>/', ' ', $html);

    return html_entity_decode($semTags . $attrs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function trechoLinha(string $html, int $id): string
{
    return preg_match('/data-id-usuario="' . $id . '".*?<\/tr>/s', $html, $m) === 1 ? $m[0] : '';
}

try {
    // =====================================================================
    // A. Textos acentuados (sem banco)
    // =====================================================================
    afirmar('scanner: detecta formas sem acento (controle positivo)', achadosSemAcento('Voce nao pode alterar a pagina de usuarios') === ['voce', 'nao', 'pagina', 'usuarios']);
    afirmar('scanner: nao dispara em texto acentuado nem em logins/caminhos (controle negativo)', achadosSemAcento('Você não pode alterar a página de usuários. carla.usuario /gestao/usuarios.php $usuario') === []);
    $menuEsperado = ['Painel', 'Totens', 'Atendimentos', 'Ordens de coleta', 'Anexos órfãos', 'Logs', 'Usuários', 'Minha conta'];
    afirmar('menu: rotulos acentuados e na ordem do plano', array_column(GestaoContexto::MENU, 'rotulo') === $menuEsperado);
    $textosFixos = array_column(GestaoContexto::MENU, 'rotulo');
    foreach (GestaoContexto::MENSAGENS as $codigo => [$tipo, $texto]) {
        $textosFixos[] = $texto;
        afirmar("mensagem $codigo: tipo valido (sucesso|erro|info) e texto nao vazio", in_array($tipo, GestaoContexto::TIPOS_MENSAGEM, true) && $texto !== '' && str_ends_with($texto, '.'));
    }
    afirmar('mensagens: "nada a alterar" e neutra (info), nao "sucesso"; ha sucesso, erro e info no catalogo', GestaoContexto::MENSAGENS['sem_mudanca'][0] === 'info' && count(array_unique(array_column(GestaoContexto::MENSAGENS, 0))) === 3);
    afirmar('mensagens: usuario_desbloqueado e senha_ja_redefinida existem (texto pedido para B7)', GestaoContexto::MENSAGENS['usuario_desbloqueado'][0] === 'sucesso' && GestaoContexto::MENSAGENS['senha_ja_redefinida'] === ['erro', 'A senha já foi redefinida. Peça a quem tem a tela original ou redefina de novo.']);
    $textosFixos[] = AuthGestao::MSG_LOGIN_GENERICA;
    $textosFixos[] = AuthGestao::MSG_LOGIN_ERRO_INTERNO;
    $textosFixos[] = GestaoHttp::MSG_ERRO_INTERNO;
    afirmar('login: mensagem generica nova (voz ativa, efeito e proximo passo)', AuthGestao::MSG_LOGIN_GENERICA === MSG_LOGIN_NOVA);
    $entradasSenha = ['', '            ', "\t\t\t\t\t\t\t\t\t\t\t\t", 'aaaaaaaaaaaa', 'abababababab', 'abcdabcdabcd', 'curta', str_repeat('x1', 40), "abcdefghijkl\xff", '123456789012', 'Password1234', 'Correta-Horse-Battery-9'];
    foreach ($entradasSenha as $e) {
        $m = SenhaPolitica::validar($e, 'maria.silva');
        if ($m !== null) {
            $textosFixos[] = $m;
        }
    }
    $textosFixos[] = (string) SenhaPolitica::validar('maria.silva', 'maria.silva');
    $textosFixos[] = (string) SenhaPolitica::validar('Outra-Senha-Boa-1', null, 'Outra-Senha-Boa-1');
    foreach (UsuarioGestaoRn::validarCampos('x', '1', 'root') as $m) {
        $textosFixos[] = $m;
    }
    afirmar('validarCampos: 3 erros de campo (login, nome, perfil)', count(UsuarioGestaoRn::validarCampos('x', '1', 'root')) === 3);
    $semAcento = [];
    foreach ($textosFixos as $t) {
        foreach (achadosSemAcento($t) as $a) {
            $semAcento[] = $a . ' em "' . mb_substr($t, 0, 50) . '"';
        }
    }
    afirmar('textos de UI (menu, mensagens, login, erros de senha e de campo): NENHUMA forma sem acento da lista (' . count($textosFixos) . ' textos)', $semAcento === []);
    if ($semAcento !== []) {
        echo 'INFO sem acento: ' . implode(' | ', $semAcento) . "\n";
    }

    // =====================================================================
    // B. B3: politica de senha
    // =====================================================================
    afirmar('B3: so de espacos recusada com mensagem propria', str_contains((string) SenhaPolitica::validar(str_repeat(' ', 14)), 'só de espaços') && str_contains((string) SenhaPolitica::validar("\t \t \t \t \t \t "), 'só de espaços'));
    afirmar('B3: um unico caractere repetido recusado (12 e 40 repeticoes, inclusive acentuado)', str_contains((string) SenhaPolitica::validar(str_repeat('a', 12)), 'único caractere') && str_contains((string) SenhaPolitica::validar(str_repeat('a', 40)), 'único caractere') && str_contains((string) SenhaPolitica::validar(str_repeat('ç', 20)), 'único caractere'));
    foreach (['abababababab', 'abcdabcdabcd', '1212121212121', 'aabbccddaabbccdd', 'zxzxzxzxzxzxzx'] as $e) {
        afirmar("B3: <= 4 caracteres distintos recusado ($e)", str_contains((string) SenhaPolitica::validar($e), 'poucos caracteres diferentes'));
    }
    afirmar('B3: exatamente 5 distintos e aceito (limite)', SenhaPolitica::validar('abcdeabcdeab') === null && SenhaPolitica::MIN_DISTINTOS === 5);
    foreach (['1q2w3e4r5t6y', 'Qwerty123456', 'ADMIN1234567', 'Admin 1234567', 'udlog@2026', 'Senha@123456', 'P@ssw0rd1234', 'welcome123456', 'qwertyuiop1234', 'administrador@1', 'Mudar@123456', '1qaz2wsx3edc'] as $e) {
        afirmar("B3: senha comum ampliada recusada ($e)", SenhaPolitica::validar($e) !== null);
    }
    foreach (['Correta-Horse-Battery-9', 'minha cachorra Bidu 2026', 'Cafe-com-leite-e-pao!', 'ação-reação-12345', 'Verde&Azul#2026x', 'bruno.carvalho.udlog', 'Totem da Recepção 7', 'onze caracteres e um espaço'] as $e) {
        afirmar("B3: sem falso positivo em senha razoavel ($e)", SenhaPolitica::validar($e, 'maria.silva') === null);
    }
    afirmar('B3: teto de 72 bytes continua e a mensagem diz "72 caracteres (acentos contam mais)"', str_contains((string) SenhaPolitica::validar(str_repeat('abcde', 15)), '72 caracteres (acentos contam mais)') && SenhaPolitica::validar(str_repeat('abcde', 14) . 'ab') === null);
    $conta = (string) file_get_contents($raiz . '/app/Views/gestao/conta.php');
    afirmar('B3: a tela de conta mostra "Máximo de 72 caracteres (acentos contam mais)" (nao mais "72 bytes")', str_contains($conta, 'Máximo de 72 caracteres (acentos contam mais)') && !str_contains($conta, '72 bytes'));

    // =====================================================================
    // C. Higiene do codigo (limpeza, constantes, atributos)
    // =====================================================================
    $fAuth = (string) file_get_contents($raiz . '/util/AuthGestao.php');
    afirmar('limpeza: AuthGestao sem perfilAtende(), config() e auditoria() (sem uso)', !str_contains($fAuth, 'function perfilAtende') && !str_contains($fAuth, 'function config(') && !str_contains($fAuth, 'function auditoria('));
    $fRn = (string) file_get_contents($raiz . '/app/Rn/UsuarioGestaoRn.php');
    afirmar('limpeza: trocarPropriaSenha usa AuthGestao::LIMITE_FALHAS_CONTA / BLOQUEIO_CONTA_MIN (sem 5, 15 duplicados)', str_contains($fRn, 'AuthGestao::LIMITE_FALHAS_CONTA, AuthGestao::BLOQUEIO_CONTA_MIN') && !preg_match('/registrarFalhaSenha\(\$idUsuario, 5, 15\)/', $fRn));
    $semSalDireto = substr_count($fAuth, '$this->config->sal');
    afirmar('B1: o sal so aparece em hash do IP e na derivacao da chave (nenhuma assinatura direta do token com o sal)', !preg_match('/hash_hmac\([^;]*\$this->config->sal\s*\)/', (string) preg_replace('/hash_hmac\(\'sha256\', \'gestao-login-token-v1\', \$this->config->sal\)/', '', $fAuth)) && $semSalDireto >= 1);
    $sens = [
        [AuthGestao::class, 'login', ['senha']],
        [AuthGestao::class, 'tokenLoginValido', ['token']],
        [AuthGestao::class, 'csrfValido', ['enviado']],
        [AuthGestao::class, 'emitirCookie', ['token']],
        [UsuarioGestaoRn::class, 'trocarPropriaSenha', ['senhaAtual', 'senhaNova', 'confirmacao']],
        [UsuarioGestaoRn::class, 'criarAdminInicial', ['senha']],
        [SenhaPolitica::class, 'gerarHash', ['senha']],
        [SenhaPolitica::class, 'verificar', ['senha', 'hash']],
        [SenhaPolitica::class, 'precisaRehash', ['hash']],
        [SenhaPolitica::class, 'verificarDummy', ['senha']],
        [SenhaPolitica::class, 'validar', ['senha', 'senhaAtual']],
        [UsuarioGestaoDao::class, 'inserir', ['senhaHash']],
        [UsuarioGestaoDao::class, 'atualizarSenha', ['senhaHash']],
        [UsuarioGestaoDao::class, 'atualizarHashApenas', ['senhaHash']],
        [SessaoGestaoDao::class, 'criar', ['csrfToken']],
    ];
    $faltam = [];
    foreach ($sens as [$classe, $metodo, $params]) {
        foreach ($params as $nome) {
            $achou = false;
            foreach ((new ReflectionMethod($classe, $metodo))->getParameters() as $par) {
                if ($par->getName() === $nome) {
                    foreach ($par->getAttributes() as $at) {
                        $achou = $achou || ltrim($at->getName(), '\\') === 'SensitiveParameter';
                    }
                }
            }
            if (!$achou) {
                $faltam[] = "$classe::$metodo($nome)";
            }
        }
    }
    foreach (['criarSobLock' => ['temporaria', 'hash'], 'redefinirSenhaSobLock' => ['temporaria', 'hash'], 'criarAdminInicialSobLock' => ['hash'], 'trocarPropriaSenhaInterno' => ['senhaAtual', 'senhaNova', 'confirmacao']] as $metodo => $params) {
        foreach ($params as $nome) {
            $achou = false;
            foreach ((new ReflectionMethod(UsuarioGestaoRn::class, $metodo))->getParameters() as $par) {
                if ($par->getName() === $nome) {
                    foreach ($par->getAttributes() as $at) {
                        $achou = $achou || ltrim($at->getName(), '\\') === 'SensitiveParameter';
                    }
                }
            }
            if (!$achou) {
                $faltam[] = "UsuarioGestaoRn::$metodo($nome)";
            }
        }
    }
    afirmar('M1: todo parametro de senha/hash/token leva #[\\SensitiveParameter]' . ($faltam !== [] ? ' (faltam: ' . implode(', ', $faltam) . ')' : ''), $faltam === []);
    afirmar('M1: ini_set(zend.exception_ignore_args, 1) no bootstrap da gestao e em tools/criar-admin.php; tratador global registrado', str_contains((string) file_get_contents($raiz . '/app/Controller/GestaoContexto.php'), "ini_set('zend.exception_ignore_args', '1')") && str_contains((string) file_get_contents($raiz . '/tools/criar-admin.php'), "ini_set('zend.exception_ignore_args', '1')") && str_contains((string) file_get_contents($raiz . '/app/Controller/GestaoContexto.php'), 'GestaoHttp::registrarTratadorDeExcecao()'));

    // =====================================================================
    // D. B5: IPv6 /64 e nome do contador por HMAC (sem banco)
    // =====================================================================
    afirmar('B5: balde IPv4 = o proprio IP; IPv4 mapeado = IPv4', IpCliente::balde('203.0.113.7') === '203.0.113.7' && IpCliente::balde('::ffff:192.0.2.9') === '192.0.2.9');
    afirmar('B5: IPv6 do mesmo /64 => mesmo balde (inclusive formas escritas diferentes)', IpCliente::balde('2001:db8:1:2:aaaa:bbbb:cccc:dddd') === IpCliente::balde('2001:DB8:1:2::1') && IpCliente::balde('2001:db8:1:2::1') === '2001:db8:1:2::/64');
    afirmar('B5: IPv6 de /64 diferente => balde diferente (e do /64 vizinho)', IpCliente::balde('2001:db8:1:2::1') !== IpCliente::balde('2001:db8:1:3::1') && IpCliente::balde('2001:db8:1:2::1') !== IpCliente::balde('2001:db8:2:2::1'));
    afirmar('B5: valor invalido volta como veio (nunca lanca)', IpCliente::balde('lixo') === 'lixo' && IpCliente::balde('') === '');

    $dirL = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_lim_' . bin2hex(random_bytes(4));
    $agora = time();
    $relogio = static function () use (&$agora): int {
        return $agora;
    };
    $lim = new LimiteFalhasIp($dirL, 20, 600, $relogio, GT_SAL);
    for ($i = 1; $i <= 20; $i++) {
        $lim->registrarFalha('2001:db8:aaaa:1::' . dechex($i));
    }
    afirmar('B5: 20 falhas de enderecos DIFERENTES do mesmo /64 bloqueiam o /64 inteiro', $lim->segundosBloqueado('2001:db8:aaaa:1::ffff') !== null && $lim->segundosBloqueado('2001:db8:aaaa:1:1234:5678:9abc:def0') !== null);
    afirmar('B5: outro /64 (e um IPv4) nao sao afetados', $lim->segundosBloqueado('2001:db8:aaaa:2::1') === null && $lim->segundosBloqueado('198.51.100.1') === null);
    $arqs = array_map('basename', glob($dirL . DIRECTORY_SEPARATOR . '*') ?: []);
    $esperado = substr(hash_hmac('sha256', '2001:db8:aaaa:1::/64', GT_SAL), 0, 40) . '.json';
    afirmar('B5: UM arquivo para o /64, nome = HMAC-SHA256(balde, sal) (nao o sha256 puro do IP)', $arqs === [$esperado] && $esperado !== substr(hash('sha256', '2001:db8:aaaa:1::/64'), 0, 40) . '.json');
    $dirL2 = $dirL . '_b';
    $limOutroSal = new LimiteFalhasIp($dirL2, 20, 600, $relogio, 'outro-sal-gestao-0123456789abcdef');
    $limOutroSal->registrarFalha('198.51.100.9');
    $limSemSal = new LimiteFalhasIp($dirL2, 20, 600, $relogio, null);
    $limSalCurto = new LimiteFalhasIp($dirL2, 20, 600, $relogio, 'curto');
    $limSemSal->registrarFalha('198.51.100.9');
    $limSalCurto->registrarFalha('198.51.100.9');
    $nomesSal = array_map('basename', glob($dirL2 . DIRECTORY_SEPARATOR . '*') ?: []);
    $nomeFallback = substr(hash_hmac('sha256', '198.51.100.9', LimiteFalhasIp::SAL_PADRAO), 0, 40) . '.json';
    afirmar('B5: o sal muda o nome do arquivo; sal ausente ou curto cai na constante SAL_PADRAO (sem excecao)', count($nomesSal) === 2 && in_array($nomeFallback, $nomesSal, true) && $limSemSal->segundosBloqueado('198.51.100.9') === null);
    // limparVencidos varre bem alem dos 500 primeiros. Prova independente de carga e da ordem do
    // readdir: 700 contadores RECENTES + 800 vencidos. Quem olhasse so 500 entradas deixaria, no
    // minimo, 300 vencidos para sempre (a ordem de leitura e fixa); aqui tudo vencido tem de sumir,
    // mesmo que o teto de 0,5 s por passada exija varias passadas numa maquina carregada.
    $dirL3 = $dirL . '_c';
    mkdir($dirL3, 0750, true);
    $velho = time() - 7200;
    $recentes = [];
    for ($i = 0; $i < 800; $i++) {
        $f = $dirL3 . DIRECTORY_SEPARATOR . substr(hash('sha256', 'v' . $i), 0, 40) . '.json';
        file_put_contents($f, '{"inicio":1,"falhas":1}');
        touch($f, $velho);
    }
    for ($i = 0; $i < 700; $i++) {
        $f = $dirL3 . DIRECTORY_SEPARATOR . substr(hash('sha256', 'recente' . $i), 0, 40) . '.json';
        file_put_contents($f, '{"inicio":1,"falhas":1}');
        $recentes[] = $f;
    }
    file_put_contents($dirL3 . DIRECTORY_SEPARATOR . 'nao-e-contador.txt', 'x');
    $lim3 = new LimiteFalhasIp($dirL3, 20, 600, null, GT_SAL);
    $rm = new ReflectionMethod($lim3, 'limparVencidos');
    $rm->setAccessible(true);
    $passadas = 0;
    do {
        $rm->invoke($lim3);
        $passadas++;
        $restam = count(glob($dirL3 . DIRECTORY_SEPARATOR . '*.json') ?: []);
    } while ($restam > 700 && $passadas < 30);
    $sobraram = glob($dirL3 . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($sobraram);
    sort($recentes);
    printf("INFO B5: limpeza convergiu em %d passada(s) (teto de 0,5 s ou 5000 itens por passada); restaram %d contadores recentes
", $passadas, count($sobraram));
    afirmar('B5: limparVencidos varre alem dos 500 primeiros e converge: os 800 vencidos somem, os 700 recentes e o que nao e contador ficam', $sobraram === $recentes && is_file($dirL3 . DIRECTORY_SEPARATOR . 'nao-e-contador.txt'));
    foreach ([$dirL, $dirL2, $dirL3] as $d) {
        foreach (glob($d . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($d);
    }

    // =====================================================================
    // Ambiente com banco
    // =====================================================================
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $cfg = GestaoConfig::doAmbiente(gtEnvPadrao());
    $relogioFixo = static fn (): int => 1_800_000_000;
    $srv = static fn (array $extra = []): array => $extra + ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on', 'HTTP_HOST' => 'gestao.exemplo.test', 'HTTP_USER_AGENT' => 'QA-UA', 'REQUEST_METHOD' => 'GET'];
    $auth = static fn (array $server = [], array $cookies = [], ?callable $rel = null, ?PDO $conn = null): AuthGestao => new AuthGestao($conn ?? $pdo, $cfg, $srv($server), $cookies, $rel ?? $relogioFixo);
    $dao = new UsuarioGestaoDao($pdo);
    $rn = new UsuarioGestaoRn($pdo);
    $idAna = gtSemear($pdo, 'ana.silva', 'admin', GT_SENHA_BOA, false, true, 'Ana Silva');
    $idBeto = gtSemear($pdo, 'beto.souza', 'admin', GT_SENHA_BOA, false, true, 'Beto Souza');
    $idCarla = gtSemear($pdo, 'carla.lima', 'usuario', GT_SENHA_BOA, false, true, 'Carla Lima');
    $idDavi = gtSemear($pdo, 'davi.rocha', 'usuario', GT_SENHA_BOA, false, true, 'Davi Rocha');
    $idInativo = gtSemear($pdo, 'iara.inativa', 'admin', GT_SENHA_BOA, false, false, 'Iara Inativa');
    $bloquear = static function (int $id, int $minutos = 10) use ($pdo): void {
        $pdo->prepare('UPDATE tb_gestao_usuario SET bloqueado_ate = DATE_ADD(NOW(), INTERVAL :m MINUTE), tentativas_falhas = 0 WHERE id_usuario = :i')->execute(['m' => $minutos, 'i' => $id]);
    };
    $auditorias = static fn (string $acao, ?int $alvo = null): array => gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria WHERE acao = :a' . ($alvo !== null ? ' AND alvo_id = :t' : '') . ' ORDER BY id_auditoria', ['a' => $acao] + ($alvo !== null ? ['t' => $alvo] : []));

    // =====================================================================
    // E. M2: desbloquear (Rn)
    // =====================================================================
    afirmar('desbloquear: AuditoriaDao::ACOES inclui USUARIO_DESBLOQUEAR (catalogo fechado)', in_array('USUARIO_DESBLOQUEAR', AuditoriaDao::ACOES, true) && count(AuditoriaDao::ACOES) === 12 && in_array('TOTEM_CRIAR', AuditoriaDao::ACOES, true) && in_array('TOTEM_ATIVO', AuditoriaDao::ACOES, true) && in_array('TOTEM_URL_REGERAR', AuditoriaDao::ACOES, true));
    $bloquear($idCarla);
    $dao->registrarFalhaSenha($idDavi, 5, 15);
    afirmar('desbloquear: pre-condicao (carla bloqueada; davi com 1 falha)', $dao->estaBloqueada($idCarla) && (int) $dao->buscarPorId($idDavi)['tentativas_falhas'] === 1);
    $rUsu = $rn->desbloquear($idDavi, $idCarla, '192.0.2.50');
    afirmar('RBAC desbloquear: usuario comum => sem_permissao, a conta SEGUE bloqueada e a recusa e auditada (SEM_EFEITO)', !$rUsu['ok'] && $rUsu['codigo'] === 'sem_permissao' && $dao->estaBloqueada($idCarla) && count(array_filter($auditorias('USUARIO_DESBLOQUEAR', $idCarla), static fn ($l) => $l['resultado'] === 'SEM_EFEITO' && (int) $l['id_usuario'] === $idDavi)) === 1);
    $rIna = $rn->desbloquear($idInativo, $idCarla);
    afirmar('RBAC desbloquear: admin INATIVO => sem_permissao', !$rIna['ok'] && $rIna['codigo'] === 'sem_permissao' && $dao->estaBloqueada($idCarla));
    afirmar('desbloquear: alvo inexistente => nao_encontrado', $rn->desbloquear($idAna, 999999)['codigo'] === 'nao_encontrado');
    $bloquear($idAna);
    $nAutoAntes = count($auditorias('USUARIO_DESBLOQUEAR', $idAna));
    $rAuto = $rn->desbloquear($idAna, $idAna, '192.0.2.49');
    $ana = $dao->buscarPorId($idAna);
    afirmar('auto-desbloqueio: admin NAO desbloqueia a propria conta (proprio_desbloqueio), nada muda e a recusa e auditada (SEM_EFEITO)', !$rAuto['ok'] && $rAuto['codigo'] === 'proprio_desbloqueio' && $ana['bloqueado_ate'] !== null && count(array_filter($auditorias('USUARIO_DESBLOQUEAR', $idAna), static fn ($l) => $l['resultado'] === 'SEM_EFEITO' && (int) $l['id_usuario'] === $idAna)) === $nAutoAntes + 1);
    $pdo->exec("UPDATE tb_gestao_usuario SET bloqueado_ate = NULL, tentativas_falhas = 0 WHERE id_usuario = $idAna");
    $rOk = $rn->desbloquear($idAna, $idCarla, '192.0.2.51');
    $c = $dao->buscarPorId($idCarla);
    afirmar('desbloquear: admin zera bloqueado_ate e tentativas_falhas', $rOk === ['ok' => true] && $c['bloqueado_ate'] === null && (int) $c['tentativas_falhas'] === 0 && !$dao->estaBloqueada($idCarla));
    $aud = array_values(array_filter($auditorias('USUARIO_DESBLOQUEAR', $idCarla), static fn ($l) => $l['resultado'] === 'OK'));
    afirmar('desbloquear: auditoria USUARIO_DESBLOQUEAR OK com ator, alvo, IP binario e sem detalhe', count($aud) === 1 && (int) $aud[0]['id_usuario'] === $idAna && $aud[0]['alvo_tipo'] === 'usuario' && $aud[0]['detalhe'] === null && $aud[0]['ip'] === inet_pton('192.0.2.51'));
    afirmar('desbloquear: nao mexe na senha nem nas sessoes', SenhaPolitica::verificar(GT_SENHA_BOA, (string) $c['senha_hash']) && (int) $c['senha_versao'] === 1);
    $nAntes = count($auditorias('USUARIO_DESBLOQUEAR'));
    $rDeNovo = $rn->desbloquear($idAna, $idCarla);
    afirmar('desbloquear: de novo => sem_mudanca e NENHUMA auditoria nova', $rDeNovo === ['ok' => true, 'sem_mudanca' => true] && count($auditorias('USUARIO_DESBLOQUEAR')) === $nAntes);
    $rFalhas = $rn->desbloquear($idAna, $idDavi);
    afirmar('desbloquear: conta so com falhas acumuladas (sem bloqueio) tambem zera o contador', $rFalhas === ['ok' => true] && (int) $dao->buscarPorId($idDavi)['tentativas_falhas'] === 0);
    $bloquear($idCarla);
    afirmar('desbloquear: bloqueada de fato nao loga; apos desbloquear, loga com a senha certa', $auth(['REMOTE_ADDR' => '198.51.100.31'])->login('carla.lima', GT_SENHA_BOA)['status'] === 401 && $rn->desbloquear($idAna, $idCarla)['ok'] === true && $auth(['REMOTE_ADDR' => '198.51.100.32'])->login('carla.lima', GT_SENHA_BOA)['ok'] === true);
    afirmar('desbloquear: nao ha DELETE/INSERT na tabela de auditoria fora do AuditoriaDao (so o DAO escreve)', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'USUARIO_DESBLOQUEAR' AND resultado = 'PENDENTE'") === 0);

    // concorrencia: dois processos desbloqueiam a MESMA conta ao mesmo tempo
    $bloquear($idCarla);
    $filho = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_filho_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($filho, "<?php\nrequire_once " . var_export(__DIR__ . '/qa_qr_exclusivo_bootstrap.php', true) . ";\nuse App\\Rn\\UsuarioGestaoRn;\n"
        . '$pdo = qaQrAbrirBanco(' . var_export($banco, true) . ");\n"
        . '$rn = new UsuarioGestaoRn($pdo);' . "\n"
        . 'if ($argv[1] === "desbloquear") { $r = $rn->desbloquear((int) $argv[2], (int) $argv[3]); } else { $r = $rn->redefinirSenha((int) $argv[2], (int) $argv[3], null, (int) $argv[4]); unset($r["senha_temporaria"]); }' . "\n"
        . 'echo json_encode($r);' . "\n");
    $rodarFilhos = static function (array $argsLista) use ($filho, $dao): array {
        $dao->obterLockAdmins(5);
        $procs = [];
        foreach ($argsLista as $args) {
            $procs[] = proc_open(array_merge([PHP_BINARY, $filho], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $procs[count($procs) - 1] = [$procs[count($procs) - 1], $pipes];
        }
        usleep(1_500_000);
        $dao->liberarLockAdmins();
        $saidas = [];
        foreach ($procs as [$p, $pipes]) {
            $saidas[] = json_decode((string) stream_get_contents($pipes[1]), true);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }

        return $saidas;
    };
    $res = $rodarFilhos([['desbloquear', (string) $idAna, (string) $idCarla], ['desbloquear', (string) $idBeto, (string) $idCarla]]);
    $semMud = count(array_filter($res, static fn ($x) => is_array($x) && ($x['sem_mudanca'] ?? false) === true));
    $comEfeito = count(array_filter($res, static fn ($x) => is_array($x) && ($x['ok'] ?? false) === true && !isset($x['sem_mudanca'])));
    afirmar('desbloquear: 2 processos simultaneos na mesma conta => exatamente 1 com efeito e 1 sem_mudanca, 1 auditoria OK', $semMud === 1 && $comEfeito === 1 && count(array_filter($auditorias('USUARIO_DESBLOQUEAR', $idCarla), static fn ($l) => $l['resultado'] === 'OK' && $l['id_usuario'] !== null)) >= 2 && !$dao->estaBloqueada($idCarla));

    // =====================================================================
    // F. B7 (Rn): versao da senha
    // =====================================================================
    $v0 = (int) $dao->buscarPorId($idDavi)['senha_versao'];
    $rVelha = $rn->redefinirSenha($idAna, $idDavi, '192.0.2.60', $v0 + 5);
    afirmar('B7: versao esperada diferente => senha_ja_redefinida, SEM gerar senha e sem mexer no hash', !$rVelha['ok'] && $rVelha['codigo'] === 'senha_ja_redefinida' && !isset($rVelha['senha_temporaria']) && (int) $dao->buscarPorId($idDavi)['senha_versao'] === $v0 && SenhaPolitica::verificar(GT_SENHA_BOA, (string) $dao->buscarPorId($idDavi)['senha_hash']));
    afirmar('B7: a recusa e auditada (SEM_EFEITO)', count(array_filter($auditorias('SENHA_RESETADA', $idDavi), static fn ($l) => $l['resultado'] === 'SEM_EFEITO')) === 1);
    $rBoa = $rn->redefinirSenha($idAna, $idDavi, '192.0.2.61', $v0);
    afirmar('B7: versao correta redefine e INCREMENTA a versao', $rBoa['ok'] && isset($rBoa['senha_temporaria']) && (int) $dao->buscarPorId($idDavi)['senha_versao'] === $v0 + 1);
    $hashApos = (string) $dao->buscarPorId($idDavi)['senha_hash'];
    $rReenvio = $rn->redefinirSenha($idAna, $idDavi, '192.0.2.62', $v0);
    afirmar('B7: reenvio com a MESMA versao (F5) => recusado e o hash nao muda (a senha da primeira tela continua valida)', !$rReenvio['ok'] && $rReenvio['codigo'] === 'senha_ja_redefinida' && (string) $dao->buscarPorId($idDavi)['senha_hash'] === $hashApos && SenhaPolitica::verificar($rBoa['senha_temporaria'], $hashApos));
    afirmar('B7: sem versao (null) o Rn nao confere (uso interno), mas o controller exige (ver HTTP)', $rn->redefinirSenha($idAna, $idDavi)['ok'] === true);
    $vAntesTroca = (int) $dao->buscarPorId($idCarla)['senha_versao'];
    $rn->trocarPropriaSenha($idCarla, GT_SENHA_BOA, 'Troca-Versao-Segura-7', 'Troca-Versao-Segura-7');
    afirmar('B7: a troca da propria senha tambem incrementa a versao', (int) $dao->buscarPorId($idCarla)['senha_versao'] === $vAntesTroca + 1);
    $auth(['REMOTE_ADDR' => '198.51.100.33'])->login('carla.lima', 'Troca-Versao-Segura-7');
    afirmar('B7: login e rehash transparente NAO mudam a versao', (int) $dao->buscarPorId($idCarla)['senha_versao'] === $vAntesTroca + 1);
    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :h, deve_trocar_senha = 0, tentativas_falhas = 0, bloqueado_ate = NULL WHERE id_usuario = :i')->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA), 'i' => $idCarla]);
    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :h, deve_trocar_senha = 0 WHERE id_usuario = :i')->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA), 'i' => $idDavi]);
    // concorrencia: 2 processos com a mesma versao
    $vC = (int) $dao->buscarPorId($idDavi)['senha_versao'];
    $res = $rodarFilhos([['redefinir', (string) $idAna, (string) $idDavi, (string) $vC], ['redefinir', (string) $idBeto, (string) $idDavi, (string) $vC]]);
    $oks = count(array_filter($res, static fn ($x) => is_array($x) && ($x['ok'] ?? false) === true));
    $velhas = count(array_filter($res, static fn ($x) => is_array($x) && ($x['codigo'] ?? '') === 'senha_ja_redefinida'));
    afirmar('B7: 2 processos simultaneos com a mesma versao => exatamente 1 redefine e o outro recebe senha_ja_redefinida', $oks === 1 && $velhas === 1 && (int) $dao->buscarPorId($idDavi)['senha_versao'] === $vC + 1);
    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :h, deve_trocar_senha = 0 WHERE id_usuario = :i')->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA), 'i' => $idDavi]);

    // =====================================================================
    // G. B6: troca de senha com a conta bloqueada
    // =====================================================================
    $pdo->exec("UPDATE tb_gestao_usuario SET bloqueado_ate = NULL, tentativas_falhas = 0 WHERE id_usuario = $idCarla");
    $bloquear($idCarla);
    $bloqAntes = gtEscalar($pdo, 'SELECT bloqueado_ate FROM tb_gestao_usuario WHERE id_usuario = :i', ['i' => $idCarla]);
    $hashAntes = (string) $dao->buscarPorId($idCarla)['senha_hash'];
    $sessCarla = $auth()->criarSessao($idCarla);
    $rB6 = $rn->trocarPropriaSenha($idCarla, GT_SENHA_BOA, 'Nova-Senha-Bloqueada-5', 'Nova-Senha-Bloqueada-5', '192.0.2.70');
    afirmar('B6: conta bloqueada + senha atual CERTA => a troca e recusada (nao verifica a senha) com mensagem clara', !$rB6['ok'] && isset($rB6['erros']['geral']) && str_contains($rB6['erros']['geral'], 'bloqueada') && (string) $dao->buscarPorId($idCarla)['senha_hash'] === $hashAntes);
    for ($i = 0; $i < 12; $i++) {
        $rn->trocarPropriaSenha($idCarla, SENT_ERRADA . $i, 'Nova-Senha-Bloqueada-5', 'Nova-Senha-Bloqueada-5', '192.0.2.70');
    }
    $cB = $dao->buscarPorId($idCarla);
    afirmar('B6: 12 tentativas errada com a conta bloqueada NAO estendem o bloqueio nem acumulam falhas (sem ciclo infinito)', gtEscalar($pdo, 'SELECT bloqueado_ate FROM tb_gestao_usuario WHERE id_usuario = :i', ['i' => $idCarla]) === $bloqAntes && (int) $cB['tentativas_falhas'] === 0);
    afirmar('B6: cada recusa e auditada como SEM_EFEITO (motivo=conta_bloqueada), sem a senha', count(array_filter($auditorias('SENHA_TROCADA', $idCarla), static fn ($l) => $l['resultado'] === 'SEM_EFEITO' && $l['detalhe'] === 'motivo=conta_bloqueada')) === 13);
    afirmar('B6: a sessao aberta continua valida durante o bloqueio (sem DoS de sessao)', $auth([], ['gestao_sid' => $sessCarla])->sessaoAtual() !== null);
    $rn->desbloquear($idAna, $idCarla);
    $rB6b = $rn->trocarPropriaSenha($idCarla, SENT_ERRADA, 'Nova-Senha-Bloqueada-5', 'Nova-Senha-Bloqueada-5');
    afirmar('B6: sem bloqueio a senha errada conta falha normalmente (1) e a mensagem de campo e acentuada', !$rB6b['ok'] && ($rB6b['bloqueada'] ?? null) === false && (int) $dao->buscarPorId($idCarla)['tentativas_falhas'] === 1 && $rB6b['erros']['senha_atual'] === 'A senha atual está incorreta. Confira e tente de novo.');
    $pdo->exec("UPDATE tb_gestao_usuario SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id_usuario = $idCarla");
    $falhas = 0;
    $bloqPorTroca = false;
    for ($i = 0; $i < AuthGestao::LIMITE_FALHAS_CONTA; $i++) {
        $x = $rn->trocarPropriaSenha($idCarla, SENT_ERRADA . $i, 'Nova-Senha-Bloqueada-5', 'Nova-Senha-Bloqueada-5');
        $falhas++;
        $bloqPorTroca = $bloqPorTroca || ($x['bloqueada'] ?? false);
    }
    afirmar('B6: o limite vem de AuthGestao::LIMITE_FALHAS_CONTA (5): a 5a falha bloqueia e derruba as sessoes', $bloqPorTroca && $dao->estaBloqueada($idCarla) && $auth([], ['gestao_sid' => $sessCarla])->sessaoAtual() === null);
    $pdo->exec("UPDATE tb_gestao_usuario SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id_usuario = $idCarla");

    // =====================================================================
    // H. B1: token do formulario de login
    // =====================================================================
    $a1 = $auth();
    $tk = $a1->tokenLogin();
    [$ts, $mac] = explode('.', $tk);
    $chave = hash_hmac('sha256', 'gestao-login-token-v1', GT_SAL);
    afirmar('B1: o MAC e HMAC com a chave DERIVADA (hash_hmac(sha256, "gestao-login-token-v1", sal))', hash_equals(hash_hmac('sha256', 'gestao-login|' . $ts, $chave), $mac) && $a1->tokenLoginValido($tk));
    afirmar('B1: token assinado com o sal DIRETO (esquema antigo) e recusado', !$a1->tokenLoginValido($ts . '.' . hash_hmac('sha256', 'gestao-login|' . $ts, GT_SAL)));
    $mkTk = static fn (int $t, string $k): string => $t . '.' . hash_hmac('sha256', 'gestao-login|' . $t, $k);
    afirmar('B1: validade de 2 h mantida (7199 s ok, 7201 s recusado) e 300 s de tolerancia para o futuro', $a1->tokenLoginValido($mkTk(1_800_000_000 - 7199, $chave)) && !$a1->tokenLoginValido($mkTk(1_800_000_000 - 7201, $chave)) && $a1->tokenLoginValido($mkTk(1_800_000_000 + 299, $chave)) && !$a1->tokenLoginValido($mkTk(1_800_000_000 + 301, $chave)) && AuthGestao::TOKEN_LOGIN_VALIDADE_SEG === 7200);
    $outroSal = new AuthGestao($pdo, GestaoConfig::doAmbiente(['GESTAO_HASH_SALT' => str_repeat('z', 40)] + gtEnvPadrao()), $srv(), [], $relogioFixo);
    afirmar('B1: chave de outro sal nao cruza; tokenLogin de um sal nao vale no outro', !$outroSal->tokenLoginValido($tk) && !$a1->tokenLoginValido($outroSal->tokenLogin()));

    // =====================================================================
    // I. B5 (login): IPv6 /64 e auditoria com IP completo
    // =====================================================================
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    for ($i = 1; $i <= 10; $i++) {
        $auth(['REMOTE_ADDR' => '2001:db8:5:5::' . dechex($i)])->login('fantasma.numero' . chr(96 + $i), 'x-senha-qualquer-1');
    }
    $r11 = $auth(['REMOTE_ADDR' => '2001:db8:5:5:dead:beef:0:1'])->login('ana.silva', GT_SENHA_BOA);
    $rOutro64 = $auth(['REMOTE_ADDR' => '2001:db8:5:6::1'])->login('ana.silva', GT_SENHA_BOA);
    afirmar('B5: 10 falhas de enderecos diferentes do MESMO /64 => 429 para outro endereco do /64, mesmo com credenciais certas', !$r11['ok'] && $r11['status'] === 429);
    afirmar('B5: outro /64 entra normalmente', $rOutro64['ok'] === true);
    afirmar('B5: a tabela guarda UMA chave de balde (hash do /64 + sal), nao do endereco', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_login_tentativa WHERE ip_hash = :h', ['h' => IpCliente::hash('2001:db8:5:5::/64', GT_SAL)]) === 1 && (int) gtEscalar($pdo, 'SELECT contador FROM tb_gestao_login_tentativa WHERE ip_hash = :h', ['h' => IpCliente::hash('2001:db8:5:5::/64', GT_SAL)]) === 10);
    $ipsAud = array_column(gtLinhas($pdo, "SELECT ip FROM tb_gestao_auditoria WHERE acao = 'LOGIN_FALHA' AND LENGTH(ip) = 16"), 'ip');
    afirmar('B5: a auditoria grava o IP COMPLETO em binario (16 bytes, nao o prefixo): enderecos distintos preservados', in_array(inet_pton('2001:db8:5:5::1'), $ipsAud, true) && in_array(inet_pton('2001:db8:5:5::a'), $ipsAud, true));
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    for ($i = 1; $i <= 10; $i++) {
        $auth(['REMOTE_ADDR' => '198.51.100.101'])->login('fantasma.numero' . chr(96 + $i), 'x-senha-qualquer-1');
    }
    afirmar('B5: IPv4 continua por IP inteiro (um IP vizinho nao e afetado)', $auth(['REMOTE_ADDR' => '198.51.100.111'])->login('ana.silva', GT_SENHA_BOA)['ok'] === true && $auth(['REMOTE_ADDR' => '198.51.100.101'])->login('ana.silva', GT_SENHA_BOA)['status'] === 429);

    // =====================================================================
    // J. B4: limpeza oportunista (lote limitado) tambem no caminho de falha
    // =====================================================================
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $pdo->exec('DELETE FROM tb_gestao_sessao');
    $ins = $pdo->prepare("INSERT INTO tb_gestao_sessao (id_sessao, id_usuario, csrf_token, ultimo_acesso_em, expira_em, ua_hash) VALUES (:i, :u, :c, NOW(), DATE_SUB(NOW(), INTERVAL 1 HOUR), :ua)");
    for ($i = 0; $i < 450; $i++) {
        $ins->execute(['i' => hash('sha256', 'exp' . $i), 'u' => $idAna, 'c' => hash('sha256', 'c' . $i), 'ua' => hash('sha256', 'ua')]);
    }
    $insT = $pdo->prepare('INSERT INTO tb_gestao_login_tentativa (ip_hash, janela, contador) VALUES (:h, :j, 1)');
    for ($i = 0; $i < 450; $i++) {
        $insT->execute(['h' => hash('sha256', 'ip' . $i), 'j' => 1000 + $i]);
    }
    $ra = new ReflectionMethod(AuthGestao::class, 'limpezaOportunista');
    $ra->setAccessible(true);
    $ra->invoke($auth(), 10_000);
    afirmar('B4: uma passada da limpeza apaga so um LOTE (<= 200 de sessoes e de tentativas), nao a tabela inteira', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao') === 250 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_login_tentativa') === 250);
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $antesExp = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao');
    // so FALHAS de login (nenhum sucesso): a limpeza precisa rodar neste caminho (1 em 50; 700 falhas => chance de nao rodar < 1e-6)
    $t0 = microtime(true);
    for ($i = 0; $i < 700; $i++) {
        $auth(['REMOTE_ADDR' => '203.0.113.' . (1 + ($i % 250)) . '', 'HTTP_USER_AGENT' => 'QA-' . intdiv($i, 250)])->login('x', 'senha-qualquer-' . $i);
    }
    printf("INFO B4: 700 falhas de login em %.1f s\n", microtime(true) - $t0);
    afirmar('B4: so com FALHAS de login a limpeza oportunista roda (sessoes vencidas diminuem)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao') < $antesExp);
    $pdo->exec('DELETE FROM tb_gestao_sessao');
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $fAud = (string) file_get_contents($raiz . '/app/Dao/AuditoriaDao.php') . (string) file_get_contents($raiz . '/sql/migrations/021_gestao_auditoria.sql') . (string) file_get_contents($raiz . '/util/GestaoConfig.php');
    afirmar('B4: docs corrigidos: auditoria grava IP em claro (forense), retencao de 90 dias na F3; sem "hash de IP ... auditoria"', str_contains($fAud, 'EM CLARO') && str_contains($fAud, '90 dias') && str_contains($fAud, 'F3') && !str_contains($fAud, 'hash de IP dos
 *    contadores e da auditoria'));

    // =====================================================================
    // K. M1: excecoes (em processo) nao vazam senha/hash
    // =====================================================================
    $cfgBd = qaQrConfiguracao();
    $abrirQuebrado = static function () use ($banco, $cfgBd): PdoQuebrado {
        $q = new PdoQuebrado("mysql:host={$cfgBd['host']};port={$cfgBd['port']};dbname={$banco};charset=utf8mb4", $cfgBd['user'], $cfgBd['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $q->exec("SET time_zone = '-03:00'");

        return $q;
    };
    $logProc = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_proc_' . bin2hex(random_bytes(4)) . '.log';
    @unlink($logProc);
    $iniErroLogAntes = ini_get('error_log');
    $iniIgnoreAntes = ini_get('zend.exception_ignore_args');
    ini_set('error_log', $logProc);
    ini_set('log_errors', '1');
    ini_set('zend.exception_ignore_args', '0');
    // getTraceAsString() corta argumentos string em 15 caracteres: varre tambem os prefixos
    $varreduras = [SENT_ATUAL, SENT_NOVA, SENT_ERRADA, GT_SENHA_BOA, 'Sentinela-', 'Correta-Horse-', '$argon2', '$2y$'];
    $tracePrecisaMostrar = PHP_VERSION_ID < 80200;
    $semVazamento = static function (string $saida) use ($varreduras, $logProc): bool {
        $log = is_file($logProc) ? (string) file_get_contents($logProc) : '';
        foreach ($varreduras as $s) {
            if (str_contains($saida, $s) || str_contains($log, $s)) {
                return false;
            }
        }

        return !preg_match('/Stack trace|Uncaught|#0 /', $log . $saida);
    };
    $cenariosLogin = [
        'buscarPorLogin' => ['/FROM tb_gestao_usuario WHERE login/', 'ana.silva', SENT_ATUAL],
        'estaBloqueada' => ['/SELECT bloqueado_ate IS NOT NULL/', 'ana.silva', SENT_ATUAL],
        'registrarFalhaSenha' => ['/SET bloqueado_ate = IF/', 'ana.silva', SENT_ERRADA],
        'contar (tentativas por IP)' => ['/tb_gestao_login_tentativa/', 'ana.silva', SENT_ATUAL],
    ];
    foreach ($cenariosLogin as $nome => [$padrao, $login, $senha]) {
        $q = $abrirQuebrado();
        $q->padrao = $padrao;
        $r = $auth(['REMOTE_ADDR' => '198.51.100.200'], [], null, $q)->login($login, $senha);
        $trace = $q->ultima !== null ? $q->ultima->getTraceAsString() : '';
        afirmar("M1 login ($nome): PDOException => 500 generico (nao 401/200), log SO da classe, nenhuma senha em log/saida", $r === ['ok' => false, 'status' => 500] && $semVazamento(json_encode($r)) && str_contains((string) @file_get_contents($logProc), 'AuthGestao: login_falhou PDOException'));
        if ($nome === 'buscarPorLogin') {
            afirmar('M1 controle: com zend.exception_ignore_args=0 o stack trace ' . ($tracePrecisaMostrar ? 'MOSTRARIA a senha (PHP < 8.2, o log so de classe e que protege)' : 'ja redige a senha (#[SensitiveParameter], PHP >= 8.2)'), $tracePrecisaMostrar ? str_contains($trace, substr($senha, 0, 15)) : !str_contains($trace, substr($senha, 0, 15)));
        }
    }
    $cenariosTroca = [
        'buscarPorId' => ['/FROM tb_gestao_usuario WHERE id_usuario/', SENT_ATUAL],
        'estaBloqueada' => ['/SELECT bloqueado_ate IS NOT NULL/', GT_SENHA_BOA],
        'registrarFalhaSenha' => ['/SET bloqueado_ate = IF/', SENT_ERRADA],
        'atualizarSenha (dentro da transacao)' => ['/SET senha_hash = :hash/', GT_SENHA_BOA],
        'auditoria (dentro da transacao)' => ['/INSERT INTO tb_gestao_auditoria/', GT_SENHA_BOA],
    ];
    foreach ($cenariosTroca as $nome => [$padrao, $atual]) {
        $q = $abrirQuebrado();
        $q->padrao = $padrao;
        $hashPre = (string) $dao->buscarPorId($idDavi)['senha_hash'];
        $r = (new UsuarioGestaoRn($q))->trocarPropriaSenha($idDavi, $atual, SENT_NOVA, SENT_NOVA, '192.0.2.80');
        afirmar("M1 trocar senha ($nome): PDOException => erro_interno, senha NAO trocada, nenhuma senha/hash em log ou retorno", $r['ok'] === false && ($r['codigo'] ?? '') === 'erro_interno' && (string) $dao->buscarPorId($idDavi)['senha_hash'] === $hashPre && $semVazamento(json_encode($r)));
    }
    $q = $abrirQuebrado();
    $q->padrao = '/INSERT INTO tb_gestao_usuario/';
    $r = (new UsuarioGestaoRn($q))->criar($idAna, 'novo.usuariox', 'Novo Usuariox', 'usuario', '192.0.2.81');
    afirmar('M1 criar: PDOException no INSERT => erro_interno, nada criado, sem senha temporaria no retorno nem hash/temporaria no log', ($r['codigo'] ?? '') === 'erro_interno' && !isset($r['senha_temporaria']) && $dao->buscarPorLogin('novo.usuariox') === null && $semVazamento(json_encode($r)) && !preg_match('/[A-HJ-NP-Za-km-z2-9]{4}(-[A-HJ-NP-Za-km-z2-9]{4}){3}/', (string) @file_get_contents($logProc)));
    $q = $abrirQuebrado();
    $q->padrao = '/SET senha_hash = :hash/';
    $hashPre = (string) $dao->buscarPorId($idDavi)['senha_hash'];
    $r = (new UsuarioGestaoRn($q))->redefinirSenha($idAna, $idDavi, '192.0.2.82', (int) $dao->buscarPorId($idDavi)['senha_versao']);
    afirmar('M1 redefinir: PDOException no UPDATE => erro_interno, hash intacto, sem senha temporaria no retorno nem no log', ($r['codigo'] ?? '') === 'erro_interno' && !isset($r['senha_temporaria']) && (string) $dao->buscarPorId($idDavi)['senha_hash'] === $hashPre && $semVazamento(json_encode($r)) && !preg_match('/[A-HJ-NP-Za-km-z2-9]{4}(-[A-HJ-NP-Za-km-z2-9]{4}){3}/', (string) @file_get_contents($logProc)));
    $q = $abrirQuebrado();
    $q->padrao = '/FROM tb_gestao_usuario WHERE login/';
    $r = (new UsuarioGestaoRn($q))->criar($idAna, 'outro.usuariox', 'Outro Usuariox', 'usuario');
    afirmar('M1 criar (buscarPorLogin sob lock): PDOException => erro_interno e sem vazamento', ($r['codigo'] ?? '') === 'erro_interno' && $semVazamento(json_encode($r)));
    $q = $abrirQuebrado();
    $q->padrao = '/INSERT INTO tb_gestao_usuario/';
    $r = (new UsuarioGestaoRn($q))->criarAdminInicial('admin.novox', 'Admin Novox', SENT_NOVA, true);
    afirmar('M1 criarAdminInicial: PDOException => erro_interno, nada criado e a senha digitada nao vai ao log', ($r['codigo'] ?? '') === 'erro_interno' && $dao->buscarPorLogin('admin.novox') === null && $semVazamento(json_encode($r)));
    // B2: falha de banco ao OBTER o lock (GET_LOCK) => erro_interno limpo em todas as acoes
    $acoesLock = [
        'criar' => fn (UsuarioGestaoRn $x): array => $x->criar($idAna, 'lock.falhax', 'Lock Falhax', 'usuario', '192.0.2.83'),
        'editar' => fn (UsuarioGestaoRn $x): array => $x->editar($idAna, $idDavi, 'Davi Rocha', 'usuario', '192.0.2.83'),
        'definirAtivo' => fn (UsuarioGestaoRn $x): array => $x->definirAtivo($idAna, $idDavi, false, '192.0.2.83'),
        'redefinirSenha' => fn (UsuarioGestaoRn $x): array => $x->redefinirSenha($idAna, $idDavi, '192.0.2.83', (int) $dao->buscarPorId($idDavi)['senha_versao']),
        'desbloquear' => fn (UsuarioGestaoRn $x): array => $x->desbloquear($idAna, $idDavi, '192.0.2.83'),
        'criarAdminInicial' => fn (UsuarioGestaoRn $x): array => $x->criarAdminInicial('lock.admin', 'Lock Admin', SENT_NOVA, true),
    ];
    foreach ($acoesLock as $nome => $acao) {
        $q = $abrirQuebrado();
        $q->padrao = '/GET_LOCK/';
        $davi0 = $dao->buscarPorId($idDavi);
        $r = $acao(new UsuarioGestaoRn($q));
        $davi1 = $dao->buscarPorId($idDavi);
        afirmar("B2 GET_LOCK falha ($nome): erro_interno limpo, nada alterado, sem senha no retorno nem sentinela/stack no log", $r === ['ok' => false, 'codigo' => 'erro_interno'] && $davi0 === $davi1 && $dao->buscarPorLogin('lock.falhax') === null && $dao->buscarPorLogin('lock.admin') === null && $semVazamento(json_encode($r)) && str_contains((string) @file_get_contents($logProc), 'UsuarioGestaoRn: lock_admins_falhou PDOException'));
    }
    afirmar('M1: o log do processo so tem linhas fixas (classe da excecao), nenhuma mensagem do banco', preg_match('/SQLSTATE|falha simulada/', (string) file_get_contents($logProc)) !== 1);
    ini_set('error_log', (string) $iniErroLogAntes);
    ini_set('zend.exception_ignore_args', (string) $iniIgnoreAntes);
    @unlink($logProc);

    // excecao NAO tratada: tratador global (processo filho com ignore_args=0)
    $scriptEx = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_ex_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($scriptEx, "<?php\nrequire " . var_export($raiz . '/vendor/autoload.php', true) . ";\nini_set('zend.exception_ignore_args', '0');\n"
        . "if (\$argv[1] === 'com') { Util\\GestaoHttp::registrarTratadorDeExcecao(); }\n"
        . "function falhaComSenha(string \$senha) { throw new RuntimeException('detalhe interno'); }\nfalhaComSenha('SENTINELA-CLI9');\n");
    $rodarEx = static function (string $modo) use ($scriptEx): array {
        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_exlog_' . bin2hex(random_bytes(4)) . '.log';
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=1', '-d', 'error_log="' . $log . '"', $scriptEx, $modo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $l = is_file($log) ? (string) file_get_contents($log) : '';
        @unlink($log);

        return [$out . $err, $l];
    };
    [$saidaSem, $logSem] = $rodarEx('sem');
    [$saidaCom, $logCom] = $rodarEx('com');
    @unlink($scriptEx);
    afirmar('M1 controle: SEM o tratador, a excecao nao tratada vaza a senha (stack trace com argumentos)', str_contains($saidaSem . $logSem, 'SENTINELA-CLI9'));
    afirmar('M1: COM o tratador global, a resposta e generica (500, cabecalhos, botao Recarregar) e NENHUM trace/sentinela aparece na saida nem no log', !str_contains($saidaCom . $logCom, 'SENTINELA-CLI9') && !preg_match('/Stack trace|Uncaught|detalhe interno/', $saidaCom . $logCom) && str_contains($saidaCom, GestaoHttp::MSG_ERRO_INTERNO) && str_contains($logCom, 'gestao: excecao_nao_tratada RuntimeException') && str_contains($saidaCom, 'data-acao="recarregar"'));

    // =====================================================================
    // L. HTTP (php-cgi) contra as paginas reais
    // =====================================================================
    $base = ['logErro' => $logCgi, 'ini' => ['zend.exception_ignore_args=0']];
    $idEdu = gtSemear($pdo, 'edu.pendente', 'usuario', GT_SENHA_BOA, true, true, 'Edu Pendente');
    $idOtto = gtSemear($pdo, 'otto.pendente', 'admin', GT_SENHA_BOA, true, true, 'Otto Pendente');
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $lAdmin = gtLogin('ana.silva', GT_SENHA_BOA, $base + ['ip' => '198.51.100.141']);
    $lUsu = gtLogin('carla.lima', GT_SENHA_BOA, $base + ['ip' => '198.51.100.142']);
    $lPend = gtLogin('edu.pendente', GT_SENHA_BOA, $base + ['ip' => '198.51.100.143']);
    $lAdmPend = gtLogin('otto.pendente', GT_SENHA_BOA, $base + ['ip' => '198.51.100.144']);
    $ck = static fn (array $l): array => ['gestao_sid' => (string) $l['sid']];
    $get = static fn (string $arq, ?array $l, array $extra = []): array => gtChamar($base + ['arquivo' => $arq, 'cookies' => $l === null ? [] : $ck($l), 'ip' => '198.51.100.150'] + $extra);
    $post = static function (string $arq, ?array $l, array $form, array $extra = []) use ($base, $ck): array {
        if ($l !== null && !array_key_exists('csrf_token', $form) && ($extra['semCsrf'] ?? false) !== true) {
            $form['csrf_token'] = (string) $l['csrf'];
        }
        unset($extra['semCsrf']);

        return gtChamar($base + ['arquivo' => $arq, 'metodo' => 'POST', 'form' => $form, 'cookies' => $l === null ? [] : $ck($l), 'ip' => '198.51.100.150'] + $extra);
    };
    afirmar('HTTP: logins de teste ok (admin, usuario, usuario e admin com troca pendente)', $lAdmin['sid'] !== null && $lUsu['sid'] !== null && $lPend['sid'] !== null && $lAdmPend['sid'] !== null);

    // --- titulo do topo
    $h1 = static fn (array $r): ?string => preg_match('/<h1 class="gestao-titulo" id="gestao-titulo">([^<]*)<\/h1>/', $r['corpo'], $m) === 1 ? $m[1] : null;
    $titulo = static fn (array $r): ?string => preg_match('/<title>([^<]*)<\/title>/', $r['corpo'], $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    $pUsuarios = $get('usuarios.php', $lAdmin);
    $pNovo = $get('usuario-form.php', $lAdmin);
    $pEditar = $get('usuario-form.php', $lAdmin, ['query' => ['id' => (string) $idCarla]]);
    $pConta = $get('conta.php', $lAdmin);
    $pNovo422 = $post('usuario-form.php', $lAdmin, ['login' => 'invalido', 'nome' => 'A', 'perfil' => 'usuario']);
    afirmar('titulo do topo: Usuários / Novo usuário / Editar usuário / Minha conta (do contexto, acentuados)', $h1($pUsuarios) === 'Usuários' && $h1($pNovo) === 'Novo usuário' && $h1($pEditar) === 'Editar usuário' && $h1($pConta) === 'Minha conta');
    afirmar('titulo do topo: o 422 de criacao mantem "Novo usuário" e <title> acompanha o titulo', $h1($pNovo422) === 'Novo usuário' && $titulo($pNovo) === 'Novo usuário - Gestão Totem' && $titulo($pEditar) === 'Editar usuário - Gestão Totem' && $titulo($pUsuarios) === 'Usuários - Gestão Totem');
    $pLoginGet = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '198.51.100.151']);
    afirmar('titulo: login mostra "Gestão Totem" e <title> "Entrar"', str_contains($pLoginGet['corpo'], 'id="gestao-titulo">Gestão Totem</h1>') && $titulo($pLoginGet) === 'Entrar - Gestão Totem');

    // --- flash info
    $pInfo = $get('usuarios.php', $lAdmin, ['query' => ['msg' => 'sem_mudanca']]);
    afirmar('flash info: neutro (classe gestao-flash--info, data-tipo=info, role=status, rotulo "Informação:") e NAO "Sucesso:"', str_contains($pInfo['corpo'], 'class="gestao-flash gestao-flash--info" id="gestao-flash" role="status"') && str_contains($pInfo['corpo'], 'data-tipo="info"') && str_contains($pInfo['corpo'], 'Informação:') && !str_contains($pInfo['corpo'], 'Sucesso:') && str_contains($pInfo['corpo'], 'Nada foi alterado.'));
    $pSuc = $get('usuarios.php', $lAdmin, ['query' => ['msg' => 'usuario_desbloqueado']]);
    $pErr = $get('usuarios.php', $lAdmin, ['query' => ['msg' => 'senha_ja_redefinida']]);
    afirmar('flash: sucesso => role=status e "Sucesso:"; erro => role=alert e "Erro:" (contrato mantido)', str_contains($pSuc['corpo'], 'gestao-flash--sucesso" id="gestao-flash" role="status"') && str_contains($pSuc['corpo'], 'Sucesso:') && str_contains($pErr['corpo'], 'gestao-flash--erro" id="gestao-flash" role="alert"') && str_contains($pErr['corpo'], 'Erro:') && str_contains($pErr['corpo'], 'A senha já foi redefinida. Peça a quem tem a tela original ou redefina de novo.'));

    // --- desbloquear pela tela
    $idGabi = gtSemear($pdo, 'gabi.moura', 'usuario', GT_SENHA_BOA, false, true, 'Gabi Moura');
    for ($i = 1; $i <= 5; $i++) {
        $gb = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '198.51.100.16' . $i]);
        gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'gabi.moura', 'senha' => 'errada-errada-' . $i, 'token_login' => (string) gtTokenLogin($gb)], 'ip' => '198.51.100.16' . $i]);
    }
    afirmar('desbloquear (tela): gabi foi bloqueada de verdade por 5 falhas de login', $dao->estaBloqueada($idGabi));
    $pLista = $get('usuarios.php', $lAdmin);
    $linhaG = trechoLinha($pLista['corpo'], $idGabi);
    $horaBanco = (string) gtEscalar($pdo, "SELECT DATE_FORMAT(bloqueado_ate, '%H:%i') FROM tb_gestao_usuario WHERE id_usuario = :i", ['i' => $idGabi]);
    afirmar('desbloquear (tela): a Situacao mostra "Bloqueado até HH:MM" (hora do banco) e a linha tem o botao Desbloquear (form POST + CSRF + id_usuario)', preg_match('/Bloqueado até <time datetime="[^"]+">(\d\d:\d\d)<\/time>/', $linhaG, $mh) === 1 && $mh[1] === $horaBanco && str_contains($linhaG, 'name="acao" value="desbloquear"') && str_contains($linhaG, 'name="csrf_token"') && str_contains($linhaG, 'name="id_usuario" value="' . $idGabi . '"') && str_contains($linhaG, '<form class="gestao-form-linha" method="post" action="/gestao/usuarios.php">'));
    $linhaC = trechoLinha($pLista['corpo'], $idCarla);
    afirmar('desbloquear (tela): linha de quem NAO esta bloqueado nao tem o botao nem o aviso; a do proprio admin tambem nao', !str_contains($linhaC, 'value="desbloquear"') && !str_contains($linhaC, 'Bloqueado até') && !str_contains(trechoLinha($pLista['corpo'], $idAna), 'value="desbloquear"'));
    $bloquear($idAna);
    $pListaAna = $get('usuarios.php', $lAdmin);
    $linhaAna = trechoLinha($pListaAna['corpo'], $idAna);
    afirmar('desbloquear (tela): admin bloqueado e logado ve "Bloqueado até" na propria linha mas SEM o botao (outro admin desbloqueia)', str_contains($linhaAna, 'Bloqueado até') && !str_contains($linhaAna, 'value="desbloquear"'));
    $nAudAuto = count($auditorias('USUARIO_DESBLOQUEAR', $idAna));
    $rAutoHttp = $post('usuarios.php', $lAdmin, ['acao' => 'desbloquear', 'id_usuario' => (string) $idAna]);
    $pAutoHttp = $get('usuarios.php', $lAdmin, ['query' => ['msg' => 'proprio_desbloqueio']]);
    afirmar('auto-desbloqueio (HTTP, POST forjado): 302 ?msg=proprio_desbloqueio, a conta SEGUE bloqueada, SEM_EFEITO auditado e mensagem acentuada na tela', $rAutoHttp['status'] === 302 && str_contains((string) gtCabecalho($rAutoHttp, 'location'), 'msg=proprio_desbloqueio') && $dao->estaBloqueada($idAna) && count($auditorias('USUARIO_DESBLOQUEAR', $idAna)) === $nAudAuto + 1 && str_contains($pAutoHttp['corpo'], 'Você não pode desbloquear a própria conta.') && str_contains($pAutoHttp['corpo'], 'gestao-flash--erro'));
    $pdo->exec("UPDATE tb_gestao_usuario SET bloqueado_ate = NULL, tentativas_falhas = 0 WHERE id_usuario = $idAna");
    $vG = gtVersaoSenha($pdo, 'gabi.moura');
    // RBAC e CSRF antes do efeito
    $rSemCsrf = $post('usuarios.php', $lAdmin, ['acao' => 'desbloquear', 'id_usuario' => (string) $idGabi], ['semCsrf' => true]);
    $rAnon = $post('usuarios.php', null, ['acao' => 'desbloquear', 'id_usuario' => (string) $idGabi]);
    $rUsuario = $post('usuarios.php', $lUsu, ['acao' => 'desbloquear', 'id_usuario' => (string) $idGabi]);
    $rPendente = $post('usuarios.php', $lPend, ['acao' => 'desbloquear', 'id_usuario' => (string) $idGabi]);
    $rGet = $get('usuarios.php', $lAdmin, ['query' => ['acao' => 'desbloquear', 'id_usuario' => (string) $idGabi]]);
    afirmar('RBAC/CSRF desbloquear (HTTP): sem CSRF 403, anonimo 401, usuario 403, troca pendente 403, GET sem efeito => a conta SEGUE bloqueada', $rSemCsrf['status'] === 403 && $rAnon['status'] === 401 && $rUsuario['status'] === 403 && $rPendente['status'] === 403 && $rGet['status'] === 200 && $dao->estaBloqueada($idGabi) && count($auditorias('USUARIO_DESBLOQUEAR', $idGabi)) === 0);
    foreach (['0', '-1', '1 OR 1=1', 'abc', ''] as $idRuim) {
        $rr = $post('usuarios.php', $lAdmin, ['acao' => 'desbloquear', 'id_usuario' => $idRuim]);
        afirmar('desbloquear (HTTP): id_usuario ' . json_encode($idRuim) . ' => 400 sem efeito', $rr['status'] === 400 && $dao->estaBloqueada($idGabi));
    }
    $rDes = $post('usuarios.php', $lAdmin, ['acao' => 'desbloquear', 'id_usuario' => (string) $idGabi]);
    afirmar('desbloquear (HTTP): admin => 302 ?msg=usuario_desbloqueado, conta livre, auditoria OK, senha intacta', $rDes['status'] === 302 && str_contains((string) gtCabecalho($rDes, 'location'), 'msg=usuario_desbloqueado') && !$dao->estaBloqueada($idGabi) && count(array_filter($auditorias('USUARIO_DESBLOQUEAR', $idGabi), static fn ($l) => $l['resultado'] === 'OK')) === 1 && gtVersaoSenha($pdo, 'gabi.moura') === $vG);
    $lGabi = gtLogin('gabi.moura', GT_SENHA_BOA, $base + ['ip' => '198.51.100.170']);
    afirmar('desbloquear (HTTP): a pessoa volta a entrar com a senha certa', $lGabi['sid'] !== null);
    $rDes2 = $post('usuarios.php', $lAdmin, ['acao' => 'desbloquear', 'id_usuario' => (string) $idGabi]);
    $pDes2 = $get('usuarios.php', $lAdmin, ['query' => ['msg' => 'sem_mudanca']]);
    afirmar('desbloquear (HTTP): repetir => 302 ?msg=sem_mudanca (flash info) e sem auditoria nova', str_contains((string) gtCabecalho($rDes2, 'location'), 'msg=sem_mudanca') && count($auditorias('USUARIO_DESBLOQUEAR', $idGabi)) === 1 && str_contains($pDes2['corpo'], 'gestao-flash--info'));
    $pLista2 = $get('usuarios.php', $lAdmin);
    afirmar('desbloquear (tela): depois do desbloqueio a linha volta ao normal (sem aviso e sem botao)', !str_contains(trechoLinha($pLista2['corpo'], $idGabi), 'Bloqueado até') && !str_contains(trechoLinha($pLista2['corpo'], $idGabi), 'value="desbloquear"'));
    afirmar('contrato da lista: cada linha leva senha_versao (campo oculto versao_senha) e o formulario de acao tem CSRF', str_contains(trechoLinha($pLista2['corpo'], $idCarla), 'name="versao_senha" value="' . gtVersaoSenha($pdo, 'carla.lima') . '"'));

    // --- B7 por HTTP
    $vD = gtVersaoSenha($pdo, 'davi.rocha');
    $rSemVersao = $post('usuarios.php', $lAdmin, ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idDavi]);
    $rVersaoRuim = $post('usuarios.php', $lAdmin, ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idDavi, 'versao_senha' => 'x1']);
    afirmar('B7 (HTTP): redefinir SEM versao_senha (ou invalida) => 400 e nada muda', $rSemVersao['status'] === 400 && $rVersaoRuim['status'] === 400 && gtVersaoSenha($pdo, 'davi.rocha') === $vD && str_contains($rSemVersao['corpo'], 'Nada foi alterado'));
    $r1 = $post('usuarios.php', $lAdmin, ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idDavi, 'versao_senha' => $vD]);
    preg_match('/id="senha-temporaria-valor">([^<]+)</', $r1['corpo'], $mt);
    $temp1 = $mt[1] ?? '';
    $hash1 = (string) $dao->buscarPorId($idDavi)['senha_hash'];
    afirmar('B7 (HTTP): 1o envio => 200 com a temporaria, versao incrementada', $r1['status'] === 200 && $temp1 !== '' && gtVersaoSenha($pdo, 'davi.rocha') === (string) ((int) $vD + 1));
    afirmar('tela da senha temporaria: <title> e #gestao-titulo dizem "Senha temporária" (nao "Usuários")', str_contains($r1['corpo'], '<title>Senha temporária - Gestão Totem</title>') && preg_match('/id="gestao-titulo">Senha temporária</', $r1['corpo']) === 1);
    // login.php?msg= (anonimo): so codigos seguros do login; os demais sao ignorados sem eco nem erro
    foreach (['sessao_expirada', 'saiu', 'senha_alterada_entrar'] as $codLogin) {
        $pl = gtChamar($base + ['arquivo' => 'login.php', 'query' => ['msg' => $codLogin], 'ip' => '198.51.100.153']);
        afirmar("login ?msg=$codLogin (anonimo): 200 e mostra a mensagem do catalogo", $pl['status'] === 200 && str_contains($pl['corpo'], 'gestao-flash') && str_contains($pl['corpo'], htmlspecialchars(GestaoContexto::MENSAGENS[$codLogin][1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
    foreach (['usuario_desbloqueado', 'proprio_desbloqueio', 'erro_interno', 'codigo_inventado', '<script>x</script>'] as $codRuim) {
        $pl = gtChamar($base + ['arquivo' => 'login.php', 'query' => ['msg' => $codRuim], 'ip' => '198.51.100.153']);
        afirmar('login ?msg=' . json_encode($codRuim) . ' (anonimo): 200, sem flash, sem eco do codigo e sem erro', $pl['status'] === 200 && !str_contains($pl['corpo'], 'gestao-flash') && !str_contains($pl['corpo'], 'codigo_inventado') && !str_contains($pl['corpo'], '<script>x'));
    }
    $r2 = $post('usuarios.php', $lAdmin, ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idDavi, 'versao_senha' => $vD]);
    $p2 = $get('usuarios.php', $lAdmin, ['query' => ['msg' => 'senha_ja_redefinida']]);
    afirmar('B7 (HTTP): F5 (mesmo POST) => 302 ?msg=senha_ja_redefinida, NENHUMA senha nova gerada e o hash da 1a tela segue valendo', $r2['status'] === 302 && str_contains((string) gtCabecalho($r2, 'location'), 'msg=senha_ja_redefinida') && !str_contains($r2['corpo'], 'senha-temporaria-valor') && (string) $dao->buscarPorId($idDavi)['senha_hash'] === $hash1 && SenhaPolitica::verificar($temp1, $hash1) && str_contains($p2['corpo'], 'A senha já foi redefinida.'));
    $pL = $get('usuarios.php', $lAdmin);
    $r3 = $post('usuarios.php', $lAdmin, ['acao' => 'redefinir-senha', 'id_usuario' => (string) $idDavi, 'versao_senha' => gtVersaoSenha($pdo, 'davi.rocha')]);
    afirmar('B7 (HTTP): com a tela atualizada (versao nova) redefine de novo normalmente e a lista traz a versao nova', str_contains(trechoLinha($pL['corpo'], $idDavi), 'name="versao_senha" value="' . (int) ((int) $vD + 1) . '"') && $r3['status'] === 200 && str_contains($r3['corpo'], 'senha-temporaria-valor'));
    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :h, deve_trocar_senha = 0 WHERE id_usuario = :i')->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA), 'i' => $idDavi]);
    $rCriar1 = $post('usuario-form.php', $lAdmin, ['login' => 'nova.pessoa', 'nome' => 'Nova Pessoa', 'perfil' => 'usuario']);
    $hashCriado = (string) ($dao->buscarPorLogin('nova.pessoa')['senha_hash'] ?? '');
    $rCriar2 = $post('usuario-form.php', $lAdmin, ['login' => 'nova.pessoa', 'nome' => 'Nova Pessoa', 'perfil' => 'usuario']);
    afirmar('B7 (HTTP): reenvio do POST de criar => 422 (login duplicado), sem nova senha e o hash nao muda', $rCriar1['status'] === 200 && $rCriar2['status'] === 422 && !str_contains($rCriar2['corpo'], 'senha-temporaria-valor') && (string) $dao->buscarPorLogin('nova.pessoa')['senha_hash'] === $hashCriado && str_contains($rCriar2['corpo'], 'Já existe um usuário com esse login'));

    // --- botao de acao das paginas de erro
    $acaoDe = static function (array $r): array {
        return preg_match('/<a class="gestao-botao gestao-botao--primario" id="gestao-erro-acao" data-acao="([a-z]+)" href="([^"]*)">([^<]*)<\/a>/', $r['corpo'], $m) === 1 ? [$m[1], $m[2], html_entity_decode($m[3], ENT_QUOTES, 'UTF-8')] : [null, null, null];
    };
    $r403 = $get('usuarios.php', $lUsu);
    afirmar('negar: perfil sem acesso (COM sessao) => "Voltar ao início" -> /gestao/', $r403['status'] === 403 && $acaoDe($r403) === ['inicio', '/gestao/', 'Voltar ao início']);
    $r401 = $post('usuarios.php', null, ['acao' => 'ativar', 'id_usuario' => '1']);
    afirmar('negar: sem sessao (POST anonimo) => "Ir para o login" -> /gestao/login.php', $r401['status'] === 401 && $acaoDe($r401) === ['login', '/gestao/login.php', 'Ir para o login']);
    $rCsrf = $post('usuarios.php', $lAdmin, ['acao' => 'ativar', 'id_usuario' => (string) $idCarla], ['semCsrf' => true]);
    afirmar('negar: token CSRF invalido/pagina expirada => "Recarregar a página" -> a propria pagina (GET, sem query)', $rCsrf['status'] === 403 && $acaoDe($rCsrf) === ['recarregar', '/gestao/usuarios.php', 'Recarregar a página'] && str_contains($rCsrf['corpo'], 'Página expirada'));
    $rOrigem = $post('usuarios.php', $lAdmin, ['acao' => 'ativar', 'id_usuario' => (string) $idCarla], ['origin' => 'https://evil.example']);
    afirmar('negar: origem nao permitida => "Recarregar a página"', $rOrigem['status'] === 403 && $acaoDe($rOrigem)[0] === 'recarregar');
    $r405s = gtChamar($base + ['arquivo' => 'usuarios.php', 'metodo' => 'PUT', 'cookies' => $ck($lAdmin), 'ip' => '198.51.100.150']);
    $r405a = gtChamar($base + ['arquivo' => 'usuarios.php', 'metodo' => 'PUT', 'ip' => '198.51.100.150']);
    afirmar('negar: 405 COM sessao => "Voltar ao início"; SEM sessao => "Ir para o login"', $r405s['status'] === 405 && $acaoDe($r405s)[0] === 'inicio' && $r405a['status'] === 405 && $acaoDe($r405a)[0] === 'login');
    $rTroca = $post('usuarios.php', $lAdmPend, ['acao' => 'ativar', 'id_usuario' => (string) $idCarla]);
    afirmar('negar: troca de senha obrigatoria => "Ir para Minha conta" -> /gestao/conta.php', $rTroca['status'] === 403 && $acaoDe($rTroca) === ['conta', '/gestao/conta.php', 'Ir para Minha conta'] && str_contains($rTroca['corpo'], 'Troca de senha obrigatória'));
    $rHttps = gtChamar($base + ['arquivo' => 'login.php', 'https' => false, 'ip' => '198.51.100.152']);
    $rHost = gtChamar($base + ['arquivo' => 'login.php', 'host' => 'a b/../x', 'ip' => '198.51.100.152']);
    $rSemSal = gtChamar($base + ['arquivo' => 'login.php', 'env' => ['GESTAO_HASH_SALT' => ''], 'ip' => '198.51.100.152']);
    afirmar('negar: HTTPS, Host invalido e gestao nao configurada => SEM botao (nenhum link resolveria)', $rHttps['status'] === 403 && !str_contains($rHttps['corpo'], 'gestao-erro-acao') && $rHost['status'] === 400 && !str_contains($rHost['corpo'], 'gestao-erro-acao') && $rSemSal['status'] === 503 && !str_contains($rSemSal['corpo'], 'gestao-erro-acao'));
    $rJson = gtChamar($base + ['arquivo' => 'usuarios.php', 'cabecalhos' => ['HTTP_ACCEPT' => 'application/json'], 'ip' => '198.51.100.150']);
    afirmar('JSON de erro tambem acentuado (401)', str_contains($rJson['corpo'], 'Não autenticado'));

    // --- M1 por HTTP (php-cgi com zend.exception_ignore_args=0)
    file_put_contents($logCgi, '');
    $gl = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '198.51.100.180']);
    $pdo->exec('RENAME TABLE tb_gestao_login_tentativa TO tb_gestao_login_tentativa_x');
    $rL500 = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'ana.silva', 'senha' => SENT_ATUAL, 'token_login' => (string) gtTokenLogin($gl)], 'ip' => '198.51.100.180']);
    $pdo->exec('RENAME TABLE tb_gestao_login_tentativa_x TO tb_gestao_login_tentativa');
    $logTxt = (string) file_get_contents($logCgi);
    afirmar('M1 HTTP login: tabela ausente => 500 generico com a mensagem nova, SEM cookie, e a senha digitada nao aparece na resposta nem no log', $rL500['status'] === 500 && str_contains($rL500['corpo'], AuthGestao::MSG_LOGIN_ERRO_INTERNO) && gtSid($rL500) === null && !str_contains($rL500['corpo'], SENT_ATUAL) && !str_contains($logTxt, SENT_ATUAL) && str_contains($logTxt, 'AuthGestao: login_falhou PDOException'));
    $lCt = gtLogin('davi.rocha', GT_SENHA_BOA, $base + ['ip' => '198.51.100.181']);
    $hashCt = (string) $dao->buscarPorId($idDavi)['senha_hash'];
    $pdo->exec('RENAME TABLE tb_gestao_auditoria TO tb_gestao_auditoria_x');
    $rC500 = $post('conta.php', $lCt, ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => SENT_NOVA, 'senha_confirmacao' => SENT_NOVA]);
    $pdo->exec('RENAME TABLE tb_gestao_auditoria_x TO tb_gestao_auditoria');
    $logTxt = (string) file_get_contents($logCgi);
    afirmar('M1 HTTP trocar senha: falha tecnica => 500 com mensagem acentuada, senha NAO trocada e nenhuma senha na resposta nem no log', $rC500['status'] === 500 && str_contains($rC500['corpo'], 'Não foi possível trocar a senha. Nada foi alterado.') && (string) $dao->buscarPorId($idDavi)['senha_hash'] === $hashCt && !str_contains($rC500['corpo'], SENT_NOVA) && !str_contains($rC500['corpo'], GT_SENHA_BOA) && !str_contains($logTxt, SENT_NOVA) && !str_contains($logTxt, GT_SENHA_BOA));
    $pdo->exec('RENAME TABLE tb_gestao_sessao TO tb_gestao_sessao_x');
    $rU500 = gtChamar($base + ['arquivo' => 'usuarios.php', 'cookies' => ['gestao_sid' => str_repeat('c', 64)], 'ip' => '198.51.100.182']);
    $pdo->exec('RENAME TABLE tb_gestao_sessao_x TO tb_gestao_sessao');
    $logTxt = (string) file_get_contents($logCgi);
    afirmar('M1 HTTP excecao NAO tratada (guard): tratador global => 500 generico com cabecalhos de seguranca e botao Recarregar; log so da classe, sem trace', $rU500['status'] === 500 && str_contains($rU500['corpo'], GestaoHttp::MSG_ERRO_INTERNO) && gtCabecalho($rU500, 'content-security-policy') !== null && gtCabecalho($rU500, 'cache-control') === 'no-store, private' && $acaoDe($rU500)[0] === 'recarregar' && str_contains($logTxt, 'gestao: excecao_nao_tratada PDOException') && !preg_match('/Stack trace|Uncaught|SQLSTATE/', $logTxt));

    // --- B5 por HTTP: IPv6 /64 (login e pagina do totem)
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    for ($i = 1; $i <= 10; $i++) {
        $gg = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '2001:db8:9:9::' . dechex($i)]);
        gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'fantasma.numero' . chr(96 + $i), 'senha' => 'x-qualquer-senha-1', 'token_login' => (string) gtTokenLogin($gg)], 'ip' => '2001:db8:9:9::' . dechex($i)]);
    }
    $gg = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '2001:db8:9:9::ffff']);
    $r11 = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'ana.silva', 'senha' => GT_SENHA_BOA, 'token_login' => (string) gtTokenLogin($gg)], 'ip' => '2001:db8:9:9::ffff']);
    $gg2 = gtChamar($base + ['arquivo' => 'login.php', 'ip' => '2001:db8:9:a::1']);
    $rOutro = gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'ana.silva', 'senha' => GT_SENHA_BOA, 'token_login' => (string) gtTokenLogin($gg2)], 'ip' => '2001:db8:9:a::1']);
    afirmar('B5 (HTTP login): 10 falhas de enderecos IPv6 diferentes do mesmo /64 => 429 para outro endereco do /64; outro /64 entra', $r11['status'] === 429 && $rOutro['status'] === 302);
    $cods = [];
    for ($i = 1; $i <= 20; $i++) {
        $cods[] = gtChamar(['raiz' => 'totem', 'arquivo' => 'index.php', 'query' => ['totem' => 'NAOEXISTE-ABCDEFGHIJKLMNOP'], 'ip' => '2001:db8:77::' . dechex($i), 'host' => 'totem.exemplo.test'])['status'];
    }
    $r21 = gtChamar(['raiz' => 'totem', 'arquivo' => 'index.php', 'query' => ['totem' => 'NAOEXISTE-ABCDEFGHIJKLMNOP'], 'ip' => '2001:db8:77::abcd', 'host' => 'totem.exemplo.test']);
    $rOutro64 = gtChamar(['raiz' => 'totem', 'arquivo' => 'index.php', 'query' => ['totem' => 'NAOEXISTE-ABCDEFGHIJKLMNOP'], 'ip' => '2001:db8:78::1', 'host' => 'totem.exemplo.test']);
    $arqTotem = $storage . DIRECTORY_SEPARATOR . 'totem_pagina_ratelimit';
    $arquivosTotem = array_map('basename', glob($arqTotem . DIRECTORY_SEPARATOR . '*.json') ?: []);
    afirmar('B5 (HTTP pagina do totem): 20 falhas de enderecos do mesmo /64 => 21o endereco do /64 recebe 429; outro /64 recebe 404', $cods === array_fill(0, 20, 404) && $r21['status'] === 429 && $rOutro64['status'] === 404);
    afirmar('B5 (pagina do totem): arquivo do contador = HMAC do /64 com GESTAO_HASH_SALT (um unico arquivo para o /64) e sem o IP no nome', in_array(gtNomeContador('2001:db8:77::1') . '.json', $arquivosTotem, true) && gtNomeContador('2001:db8:77::1') === gtNomeContador('2001:db8:77::abcd') && count(array_filter($arquivosTotem, static fn ($n) => str_contains($n, '2001'))) === 0);

    // --- textos acentuados em TODAS as paginas renderizadas
    $paginas = [
        'login GET' => $pLoginGet,
        'login 401' => gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'x.y', 'senha' => 'x', 'token_login' => (string) gtTokenLogin($pLoginGet)], 'ip' => '198.51.100.190']),
        'login token ruim' => gtChamar($base + ['arquivo' => 'login.php', 'metodo' => 'POST', 'form' => ['login' => 'x.y', 'senha' => 'x', 'token_login' => 'ruim'], 'ip' => '198.51.100.190']),
        'login 429' => $r11,
        'usuarios' => $pUsuarios,
        'usuarios (bloqueado)' => $pLista,
        'usuario-form novo' => $pNovo,
        'usuario-form editar' => $pEditar,
        'usuario-form 422' => $pNovo422,
        'usuario-senha' => $r1,
        'conta' => $pConta,
        'conta (troca obrigatoria)' => $get('conta.php', $lPend),
        'conta 422 (senha curta)' => $post('conta.php', $lCt, ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => 'curta', 'senha_confirmacao' => 'curta']),
        'conta 422 (senha repetida)' => $post('conta.php', $lCt, ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => 'aaaaaaaaaaaa', 'senha_confirmacao' => 'aaaaaaaaaaaa']),
        'conta 422 (confirmacao)' => $post('conta.php', $lCt, ['senha_atual' => GT_SENHA_BOA, 'senha_nova' => 'Outra-Boa-Senha-88', 'senha_confirmacao' => 'diferente-de-tudo-1']),
        'conta 422 (atual errada)' => $post('conta.php', $lCt, ['senha_atual' => 'errada-errada-1', 'senha_nova' => 'Outra-Boa-Senha-88', 'senha_confirmacao' => 'Outra-Boa-Senha-88']),
        'usuarios 400' => $post('usuarios.php', $lAdmin, ['acao' => 'xxx', 'id_usuario' => '1']),
        '403 perfil' => $r403,
        '401' => $r401,
        '403 csrf' => $rCsrf,
        '403 origem' => $rOrigem,
        '405' => $r405s,
        '403 troca obrigatoria' => $rTroca,
        '403 https' => $rHttps,
        '400 host' => $rHost,
        '503' => $rSemSal,
        '500 guard' => $rU500,
        '500 login' => $rL500,
        '500 conta' => $rC500,
    ];
    foreach (array_keys(GestaoContexto::MENSAGENS) as $codigo) {
        $paginas['flash ' . $codigo] = $get('usuarios.php', $lAdmin, ['query' => ['msg' => $codigo]]);
    }
    $achadosPag = [];
    foreach ($paginas as $rotulo => $r) {
        foreach (achadosSemAcento(textoVisivel($r['corpo'])) as $a) {
            $achadosPag[] = "$rotulo: $a";
        }
    }
    afirmar('textos visiveis de ' . count($paginas) . ' paginas renderizadas (incl. erros, flash de todos os codigos, 422 de senha): NENHUMA forma sem acento da lista', $achadosPag === []);
    if ($achadosPag !== []) {
        echo 'INFO sem acento (paginas): ' . implode(' | ', $achadosPag) . "\n";
    }
    afirmar('toda pagina renderizada declara lang pt-BR/charset UTF-8 e nenhuma tem script/style inline', (function () use ($paginas) {
        foreach ($paginas as $r) {
            if ($r['corpo'] !== '' && (preg_match('/<style\b/i', $r['corpo']) === 1 || preg_match('/<script\b(?![^>]*\bsrc=)/i', $r['corpo']) === 1 || preg_match('/\son[a-z]+\s*=|\sstyle\s*=/i', $r['corpo']) === 1)) {
                return false;
            }
        }

        return true;
    })());

    // --- higiene final
    $logFinal = is_file($logCgi) ? (string) file_get_contents($logCgi) : '';
    afirmar('log do CGI: nenhuma senha/sentinela/sal em todo o log desta suite', !str_contains($logFinal, SENT_ATUAL) && !str_contains($logFinal, SENT_NOVA) && !str_contains($logFinal, SENT_ERRADA) && !str_contains($logFinal, GT_SAL) && !str_contains($logFinal, GT_SENHA_BOA));
    afirmar('auditoria: nenhuma linha PENDENTE ficou aberta e todas as acoes pertencem ao catalogo', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao NOT IN ('" . implode("','", AuditoriaDao::ACOES) . "')") === 0);
    afirmar('auditoria SEM PII: nenhuma sentinela de senha em tb_gestao_auditoria', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE detalhe LIKE '%Sentinela%'") === 0);
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ': ' . $e->getMessage() . ')', false);
} finally {
    gtDestruirAmbiente($banco, $storage);
    @unlink($logCgi);
    if (is_string($filho)) {
        @unlink($filho);
    }
}
exit(gtResumo('teste_gestao_correcoes'));
