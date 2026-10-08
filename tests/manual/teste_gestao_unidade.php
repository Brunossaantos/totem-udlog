<?php

/**
 * Gestao Totem (F0/F1, 2026-10-06), camada de logica em processo (sem HTTP):
 * Util\IpCliente, Util\LimiteFalhasIp, Util\SenhaPolitica, Util\GestaoConfig,
 * App\Dao\AuditoriaDao (allowlist, append-only), App\Rn\UsuarioGestaoRn (ultimo
 * admin, auto-alteracao, senha temporaria, revogacao), Util\AuthGestao (login,
 * mensagem unica, tempo, bloqueio por conta e por IP, sessao, CSRF, origem, HTTPS)
 * e SQL injection. Banco QA descartavel; nunca udlog_totem.
 *
 * Uso: php tests/manual/teste_gestao_unidade.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

use App\Dao\AuditoriaDao;
use App\Dao\SessaoGestaoDao;
use App\Dao\UsuarioGestaoDao;
use App\Rn\UsuarioGestaoRn;
use Util\AuthGestao;
use Util\GestaoConfig;
use Util\IpCliente;
use Util\LimiteFalhasIp;
use Util\SenhaPolitica;

$raiz = dirname(__DIR__, 2);
$banco = null;
$storage = null;

function lancaInvalido(callable $f): bool
{
    try {
        $f();
    } catch (InvalidArgumentException $e) {
        return true;
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function servidor(array $extra = []): array
{
    return $extra + [
        'REMOTE_ADDR' => '192.0.2.1',
        'HTTPS' => 'on',
        'HTTP_HOST' => 'gestao.exemplo.test',
        'HTTP_USER_AGENT' => 'QA-UA',
        'REQUEST_METHOD' => 'GET',
    ];
}

function mediana(array $v): float
{
    sort($v);

    return (float) $v[intdiv(count($v), 2)];
}

try {
    // =====================================================================
    // A. IpCliente
    // =====================================================================
    afirmar('IpCliente: faixas oficiais embutidas (15 IPv4 + 7 IPv6) com data de conferencia 2026-10-06', count(IpCliente::FAIXAS_CLOUDFLARE_V4) === 15 && count(IpCliente::FAIXAS_CLOUDFLARE_V6) === 7 && IpCliente::FAIXAS_CLOUDFLARE_DATA === '2026-10-06');
    foreach (['173.245.48.1', '173.245.63.255', '103.21.244.9', '103.22.200.1', '103.31.4.255', '141.101.64.1', '108.162.192.7', '190.93.240.1', '188.114.96.1', '197.234.240.1', '198.41.128.1', '198.41.255.255', '162.158.0.1', '162.159.255.255', '104.16.0.1', '104.23.255.255', '104.24.0.1', '104.27.255.255', '172.64.0.1', '172.71.255.255', '131.0.72.5', '131.0.75.255'] as $ip) {
        afirmar("IpCliente: $ip pertence ao Cloudflare", IpCliente::pertenceAoCloudflare($ip));
    }
    foreach (['173.245.64.0', '173.245.47.255', '103.21.248.0', '8.8.8.8', '192.0.2.1', '10.0.0.1', '104.15.255.255', '104.28.0.1', '172.72.0.1', '131.0.76.1', '162.160.0.1', '127.0.0.1'] as $ip) {
        afirmar("IpCliente: $ip NAO pertence ao Cloudflare", !IpCliente::pertenceAoCloudflare($ip));
    }
    foreach (['2606:4700::1', '2606:4700:ffff::1', '2400:cb00::5', '2803:f800::1', '2405:b500::1', '2405:8100::1', '2a06:98c0::1', '2a06:98c7:ffff::1', '2c0f:f248::1'] as $ip) {
        afirmar("IpCliente: $ip (IPv6) pertence ao Cloudflare", IpCliente::pertenceAoCloudflare($ip));
    }
    foreach (['2606:4701::1', '2a06:98c8::1', '2001:db8::1', '::1', '2c0f:f249::1'] as $ip) {
        afirmar("IpCliente: $ip (IPv6) NAO pertence ao Cloudflare", !IpCliente::pertenceAoCloudflare($ip));
    }
    afirmar('IpCliente: IPv4 mapeado em IPv6 (::ffff:104.16.0.1) e tratado como IPv4 do Cloudflare', IpCliente::pertenceAoCloudflare('::ffff:104.16.0.1') && IpCliente::normalizar('::ffff:192.0.2.9') === '192.0.2.9');
    foreach (['', 'abc', '999.1.1.1', '1.2.3', '1.2.3.4.5', '::g', str_repeat('1', 50), '1.2.3.4 ', "1.2.3.4\n", '1.2.3.4, 5.6.7.8', '<script>'] as $ruim) {
        afirmar('IpCliente::normalizar rejeita ' . json_encode($ruim), IpCliente::normalizar($ruim) === null);
    }

    // obter(): CF-Connecting-IP so quando REMOTE_ADDR e Cloudflare
    afirmar('obter: REMOTE_ADDR comum sem header => REMOTE_ADDR', IpCliente::obter(['REMOTE_ADDR' => '203.0.113.7']) === '203.0.113.7');
    afirmar('obter: CF-Connecting-IP FORJADO de IP nao-Cloudflare e ignorado', IpCliente::obter(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_CF_CONNECTING_IP' => '198.51.100.99']) === '203.0.113.7');
    afirmar('obter: X-Forwarded-For nunca e usado (IP comum)', IpCliente::obter(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.99']) === '203.0.113.7');
    afirmar('obter: X-Forwarded-For nunca e usado (REMOTE_ADDR Cloudflare sem CF header)', IpCliente::obter(['REMOTE_ADDR' => '172.64.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.99']) === '172.64.0.1');
    afirmar('obter: REMOTE_ADDR Cloudflare + CF-Connecting-IP valido => IP do cliente', IpCliente::obter(['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.99']) === '198.51.100.99');
    afirmar('obter: REMOTE_ADDR Cloudflare + CF-Connecting-IP IPv6 normalizado', IpCliente::obter(['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '2001:DB8:0:0::1']) === '2001:db8::1');
    afirmar('obter: REMOTE_ADDR Cloudflare + CF-Connecting-IP invalido => REMOTE_ADDR', IpCliente::obter(['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => 'lixo']) === '172.64.0.1');
    afirmar('obter: REMOTE_ADDR Cloudflare + CF-Connecting-IP lista "a, b" => REMOTE_ADDR', IpCliente::obter(['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1, 2.2.2.2']) === '172.64.0.1');
    afirmar('obter: REMOTE_ADDR Cloudflare IPv6 + header valido', IpCliente::obter(['REMOTE_ADDR' => '2606:4700::5', 'HTTP_CF_CONNECTING_IP' => '198.51.100.5']) === '198.51.100.5');
    afirmar('obter: REMOTE_ADDR ausente/invalido => 0.0.0.0 (header ignorado)', IpCliente::obter(['HTTP_CF_CONNECTING_IP' => '198.51.100.5']) === '0.0.0.0' && IpCliente::obter(['REMOTE_ADDR' => 'x']) === '0.0.0.0');
    afirmar('hash: determinista, 64 hex, depende do sal e nao contem o IP', IpCliente::hash('1.2.3.4', 's1') === IpCliente::hash('1.2.3.4', 's1') && IpCliente::hash('1.2.3.4', 's1') !== IpCliente::hash('1.2.3.4', 's2') && preg_match('/\A[a-f0-9]{64}\z/', IpCliente::hash('1.2.3.4', 's1')) === 1);
    afirmar('paraBinario: IPv4 = 4 bytes, IPv6 = 16 bytes, invalido = null', strlen((string) IpCliente::paraBinario('192.0.2.1')) === 4 && strlen((string) IpCliente::paraBinario('2001:db8::1')) === 16 && IpCliente::paraBinario('x') === null);

    // =====================================================================
    // B. LimiteFalhasIp (F0)
    // =====================================================================
    $dirLimite = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_lim_' . bin2hex(random_bytes(4));
    $agora = time();
    $limite = new LimiteFalhasIp($dirLimite, 20, 600, static function () use (&$agora): int {
        return $agora;
    });
    for ($i = 1; $i <= 19; $i++) {
        $limite->registrarFalha('203.0.113.50');
    }
    afirmar('LimiteFalhasIp: 19 falhas ainda nao bloqueiam', $limite->segundosBloqueado('203.0.113.50') === null);
    $limite->registrarFalha('203.0.113.50');
    $seg = $limite->segundosBloqueado('203.0.113.50');
    afirmar('LimiteFalhasIp: a 20a falha bloqueia com Retry-After > 0 e <= 600', $seg !== null && $seg >= 1 && $seg <= 600);
    afirmar('LimiteFalhasIp: outro IP nao e afetado', $limite->segundosBloqueado('203.0.113.51') === null);
    $agora += 601;
    afirmar('LimiteFalhasIp: apos a janela de 10 min o bloqueio expira', $limite->segundosBloqueado('203.0.113.50') === null);
    $arquivos = array_map('basename', glob($dirLimite . DIRECTORY_SEPARATOR . '*') ?: []);
    afirmar('LimiteFalhasIp: nome do arquivo e hash (nunca o IP)', $arquivos !== [] && count(array_filter($arquivos, static fn ($n) => str_contains($n, '203.0.113'))) === 0 && preg_match('/\A[a-f0-9]{40}\.json\z/', $arquivos[0]) === 1);
    foreach ($arquivos as $a) {
        @unlink($dirLimite . DIRECTORY_SEPARATOR . $a);
    }
    @rmdir($dirLimite);

    // =====================================================================
    // C. SenhaPolitica e GestaoConfig
    // =====================================================================
    afirmar('Senha: 11 caracteres recusada', SenhaPolitica::validar('abcdefghijk') !== null);
    afirmar('Senha: 12 caracteres aceita (sem regra de composicao)', SenhaPolitica::validar('abcdeabcdeab') === null);
    afirmar('Senha: so minusculas longas aceita', SenhaPolitica::validar('minusculaslongas') === null);
    afirmar('Senha: 72 bytes aceita e 73 bytes recusada', SenhaPolitica::validar(str_repeat('abcde', 14) . 'ab') === null && SenhaPolitica::validar(str_repeat('abcde', 14) . 'abc') !== null);
    afirmar('Senha: teto e em BYTES (40 caracteres de 2 bytes = 80 bytes recusada)', SenhaPolitica::validar(str_repeat('ç', 40)) !== null);
    afirmar('Senha: 12 caracteres acentuados (24 bytes) aceita', SenhaPolitica::validar('çãõéíóúâêôàü!') === null);
    afirmar('Senha: igual ao login recusada (maiusculas/minusculas)', SenhaPolitica::validar('bruno.carvalho', 'Bruno.Carvalho') !== null);
    afirmar('Senha: comum recusada (inclusive com espacos e maiusculas)', SenhaPolitica::validar('Password1234') !== null && SenhaPolitica::validar('123456789012') !== null && SenhaPolitica::validar('Pass word 1234') !== null && SenhaPolitica::validar('UDLOG@123456') !== null);
    afirmar('Senha: igual a atual recusada na troca', SenhaPolitica::validar('Outra-Senha-Boa-1', null, 'Outra-Senha-Boa-1') !== null && SenhaPolitica::validar('Outra-Senha-Boa-2', null, 'Outra-Senha-Boa-1') === null);
    afirmar('Senha: UTF-8 invalido recusado', SenhaPolitica::validar("abcdefghijkl\xff\xfe") !== null);
    $hash = SenhaPolitica::gerarHash('Senha-De-Teste-77');
    afirmar('Hash: argon2id quando disponivel (parametros explicitos)', defined('PASSWORD_ARGON2ID') ? str_starts_with($hash, '$argon2id$') && str_contains($hash, 'm=19456,t=2,p=1') : str_starts_with($hash, '$2y$12$'));
    afirmar('Hash: verificar certo/errado', SenhaPolitica::verificar('Senha-De-Teste-77', $hash) && !SenhaPolitica::verificar('Senha-De-Teste-78', $hash) && !SenhaPolitica::verificar('x', ''));
    afirmar('Hash: nao precisa de rehash quando atual', !SenhaPolitica::precisaRehash($hash));
    afirmar('Hash: bcrypt cost 10 e argon com custo menor precisam de rehash', SenhaPolitica::precisaRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 10])) && (!defined('PASSWORD_ARGON2ID') || SenhaPolitica::precisaRehash(password_hash('x', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]))));
    $temps = [];
    for ($i = 0; $i < 200; $i++) {
        $temps[] = SenhaPolitica::gerarTemporaria();
    }
    afirmar('Senha temporaria: 19 caracteres, 4 grupos, alfabeto sem ambiguidade, todas passam na politica e sao distintas', count(array_unique($temps)) === 200 && count(array_filter($temps, static fn ($t) => preg_match('/\A[A-HJ-NP-Za-km-z2-9]{4}(-[A-HJ-NP-Za-km-z2-9]{4}){3}\z/', $t) === 1 && SenhaPolitica::validar($t) === null)) === 200);

    $cfg = GestaoConfig::doAmbiente(['GESTAO_HASH_SALT' => 'x']);
    afirmar('GestaoConfig: sal curto = invalida; padroes 30 / 720 / HTTP desligado', !$cfg->valida() && $cfg->idleMin === 30 && $cfg->absolutoMin === 720 && $cfg->permitirHttp === false);
    $cfg = GestaoConfig::doAmbiente(['GESTAO_HASH_SALT' => str_repeat('s', 16), 'GESTAO_SESSION_IDLE_MIN' => '15', 'GESTAO_SESSION_ABSOLUTE_MIN' => '60', 'GESTAO_PERMITIR_HTTP' => 'true']);
    afirmar('GestaoConfig: valores do .env aplicados', $cfg->valida() && $cfg->idleMin === 15 && $cfg->absolutoMin === 60 && $cfg->permitirHttp === true);
    $cfg = GestaoConfig::doAmbiente(['GESTAO_HASH_SALT' => str_repeat('s', 16), 'GESTAO_SESSION_IDLE_MIN' => 'abc', 'GESTAO_SESSION_ABSOLUTE_MIN' => '-5', 'GESTAO_PERMITIR_HTTP' => 'TRUE']);
    afirmar('GestaoConfig: invalidos voltam ao padrao e HTTP so com o literal "true"', $cfg->idleMin === 30 && $cfg->absolutoMin === 720 && $cfg->permitirHttp === false);
    afirmar('GestaoConfig: sem GESTAO_PERMITIR_HTTP = desligado', GestaoConfig::doAmbiente(['GESTAO_HASH_SALT' => str_repeat('s', 16)])->permitirHttp === false);

    // =====================================================================
    // Banco QA
    // =====================================================================
    [$pdo, $banco, $storage] = gtCriarAmbiente();
    $cfg = GestaoConfig::doAmbiente(gtEnvPadrao());
    $relogioFixo = static fn (): int => 1_800_000_000;
    $auth = static fn (array $server = [], array $cookies = [], ?callable $rel = null): AuthGestao => new AuthGestao($pdo, $cfg, servidor($server), $cookies, $rel ?? $relogioFixo);
    $dao = new UsuarioGestaoDao($pdo);
    $sessDao = new SessaoGestaoDao($pdo);
    $aud = new AuditoriaDao($pdo);
    $rn = new UsuarioGestaoRn($pdo);

    // =====================================================================
    // D. AuditoriaDao
    // =====================================================================
    $id = $aud->abrir(null, 'USUARIO_CRIAR', 'usuario', 5, ['origem' => 'web', 'perfil_para' => 'admin'], '192.0.2.5');
    $l = gtLinhas($pdo, 'SELECT * FROM tb_gestao_auditoria WHERE id_auditoria = :i', ['i' => $id])[0];
    afirmar('Auditoria: abrir grava PENDENTE com detalhe da allowlist e IP binario de 4 bytes', $l['resultado'] === 'PENDENTE' && $l['detalhe'] === 'origem=web;perfil_para=admin' && strlen((string) $l['ip']) === 4 && $l['acao'] === 'USUARIO_CRIAR');
    afirmar('Auditoria: fechar PENDENTE -> OK', $aud->fechar($id, 'OK') === true && gtEscalar($pdo, 'SELECT resultado FROM tb_gestao_auditoria WHERE id_auditoria = :i', ['i' => $id]) === 'OK');
    afirmar('Auditoria: linha ja fechada NUNCA muda de novo (append-only)', $aud->fechar($id, 'ERRO') === false && gtEscalar($pdo, 'SELECT resultado FROM tb_gestao_auditoria WHERE id_auditoria = :i', ['i' => $id]) === 'OK');
    $aud->registrar(3, 'LOGIN_OK', 'usuario', 3, 'OK', ['origem' => 'web'], '2001:db8::7');
    afirmar('Auditoria: IPv6 em 16 bytes', strlen((string) gtEscalar($pdo, "SELECT ip FROM tb_gestao_auditoria WHERE acao = 'LOGIN_OK'")) === 16);
    $antes = (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria');
    afirmar('Auditoria: acao fora do catalogo recusada', lancaInvalido(fn () => $aud->registrar(1, 'APAGAR_TUDO', null, null, 'OK')));
    afirmar('Auditoria: alvo_tipo fora do catalogo recusado', lancaInvalido(fn () => $aud->registrar(1, 'LOGIN_OK', 'senha', 1, 'OK')));
    afirmar('Auditoria: resultado invalido recusado', lancaInvalido(fn () => $aud->registrar(1, 'LOGIN_OK', null, null, 'PENDENTE')) && lancaInvalido(fn () => $aud->fechar($id, 'QUALQUER')));
    foreach (
        [
            'chave livre' => ['senha' => 'abc'],
            'nome' => ['nome' => 'Fulano de Tal'],
            'cpf' => ['cpf' => '12345678901'],
            'valor livre em chave valida' => ['motivo' => 'senha errada: Abc123'],
            'valor com ponto e virgula' => ['origem' => 'web;senha=x'],
            'perfil invalido' => ['perfil_para' => 'root'],
            'inteiro invalido' => ['sessoes_revogadas' => '12345'],
            'inteiro negativo' => ['sessoes_revogadas' => '-1'],
        ] as $rotulo => $det
    ) {
        afirmar("Auditoria: detalhe fora da allowlist recusado ($rotulo)", lancaInvalido(fn () => $aud->registrar(1, 'LOGIN_FALHA', null, null, 'SEM_EFEITO', $det)));
    }
    afirmar('Auditoria: nada foi gravado pelas chamadas recusadas', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_auditoria') === $antes);
    $fonteAud = (string) file_get_contents($raiz . '/app/Dao/AuditoriaDao.php');
    afirmar('Auditoria: AuditoriaDao tem UM UPDATE (so o fechamento de PENDENTE) e nenhum DELETE', preg_match_all('/\bUPDATE\s+tb_gestao_auditoria\b/i', $fonteAud) === 1 && preg_match('/\bDELETE\s+FROM\b/i', $fonteAud) !== 1 && str_contains($fonteAud, "resultado = 'PENDENTE'"));
    $escritasFora = [];
    foreach (['app', 'util', 'public', 'tools', 'cron'] as $pasta) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $pasta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && !str_ends_with(str_replace('\\', '/', $f->getPathname()), 'app/Dao/AuditoriaDao.php') && !str_ends_with(str_replace('\\', '/', $f->getPathname()), 'app/Dao/AuditoriaRetencaoDao.php')) {
                if (preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+tb_gestao_auditoria/i', (string) file_get_contents($f->getPathname())) === 1) {
                    $escritasFora[] = $f->getFilename();
                }
            }
        }
    }
    afirmar('Auditoria: nenhum outro arquivo da aplicacao escreve em tb_gestao_auditoria (excecao exata: AuditoriaRetencaoDao, so DELETE de retencao, coberto em teste_gestao_retencao)', $escritasFora === []);

    // =====================================================================
    // E. UsuarioGestaoRn
    // =====================================================================
    $idA = gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA);
    $idB = gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA);
    $idU = gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA);

    foreach (['bruno', 'br.u', 'bruno.carvalho.silva', 'bruno_carvalho', 'bruno carvalho', 'brúno.carvalho', 'bruno.carvalho1', 'bruno.', '.carvalho', 'bruno..carvalho', "bruno.carvalho' OR '1'='1", 'bruno.carvalho; DROP TABLE tb_gestao_usuario', str_repeat('a', 31) . '.silva'] as $ruim) {
        $r = $rn->criar($idA, $ruim, 'Nome Valido', 'usuario');
        afirmar('criar: login invalido recusado ' . json_encode($ruim), !$r['ok'] && isset($r['erros']['login']));
    }
    afirmar('criar: LOGIN_REGEX e uma unica constante publica', UsuarioGestaoRn::LOGIN_REGEX === '/\A[a-z]{2,30}\.[a-z]{2,30}\z/D' && substr_count((string) file_get_contents($raiz . '/app/Rn/UsuarioGestaoRn.php'), '[a-z]{2,30}') === 2);
    foreach (['', 'A', '<script>alert(1)</script>', "Nome\x00Nulo", 'Nome; DROP', str_repeat('A', 101), '12345'] as $ruim) {
        $r = $rn->criar($idA, 'novo.usuario', $ruim, 'usuario');
        afirmar('criar: nome invalido recusado ' . json_encode(mb_substr($ruim, 0, 20)), !$r['ok'] && isset($r['erros']['nome']));
    }
    $r = $rn->criar($idA, 'novo.usuario', 'Nome Valido', 'root');
    afirmar('criar: perfil invalido recusado', !$r['ok'] && isset($r['erros']['perfil']));
    $r = $rn->criar($idU, 'novo.usuario', 'Nome Valido', 'usuario');
    afirmar('criar: usuario comum (nao admin) recebe sem_permissao e nada e criado', !$r['ok'] && $r['codigo'] === 'sem_permissao' && $dao->buscarPorLogin('novo.usuario') === null);

    $r = $rn->criar($idA, '  Dani.Souza ', "  D'Angelo   da  Silva-Neto Jr.  ", 'usuario', '192.0.2.77');
    $senhaTemp = (string) ($r['senha_temporaria'] ?? '');
    afirmar('criar: ok, login normalizado para minusculo e senha temporaria devolvida UMA vez', $r['ok'] && $senhaTemp !== '' && preg_match('/\A[A-Za-z2-9-]{19}\z/', $senhaTemp) === 1);
    $novo = $dao->buscarPorLogin('dani.souza');
    afirmar('criar: nome normalizado e gravado literalmente (apostrofo, sem injecao) e deve_trocar_senha=1', $novo !== null && $novo['nome'] === "D'Angelo da Silva-Neto Jr." && (int) $novo['deve_trocar_senha'] === 1 && (int) $novo['ativo'] === 1 && (int) $novo['criado_por'] === $idA);
    afirmar('criar: so o HASH e gravado e ele confere com a senha temporaria', $novo['senha_hash'] !== $senhaTemp && SenhaPolitica::verificar($senhaTemp, (string) $novo['senha_hash']));
    $r2 = $rn->criar($idA, 'DANI.SOUZA', 'Outro Nome', 'usuario');
    afirmar('criar: login duplicado (mesmo com outra caixa) recusado', !$r2['ok'] && isset($r2['erros']['login']));
    $aCriar = gtLinhas($pdo, "SELECT * FROM tb_gestao_auditoria WHERE acao = 'USUARIO_CRIAR' AND alvo_id = :i", ['i' => (int) $novo['id_usuario']]);
    afirmar('criar: auditoria USUARIO_CRIAR fechada como OK, com ator, alvo, IP e detalhe da allowlist', count($aCriar) === 1 && $aCriar[0]['resultado'] === 'OK' && (int) $aCriar[0]['id_usuario'] === $idA && $aCriar[0]['detalhe'] === 'origem=web;perfil_para=usuario' && strlen((string) $aCriar[0]['ip']) === 4);

    // varredura: a senha temporaria e dado pessoal NAO existem em nenhuma tabela da gestao
    $varrer = static function (PDO $p, array $sentinelas): array {
        $achados = [];
        foreach (['tb_gestao_usuario', 'tb_gestao_sessao', 'tb_gestao_login_tentativa', 'tb_gestao_auditoria'] as $t) {
            foreach ($p->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC) as $linha) {
                $texto = json_encode(array_map(static fn ($v) => is_string($v) ? bin2hex($v) . '|' . $v : $v, $linha), JSON_INVALID_UTF8_SUBSTITUTE);
                foreach ($sentinelas as $s) {
                    if ($s !== '' && (str_contains($texto, $s) || str_contains($texto, bin2hex($s)))) {
                        $achados[] = "$t:" . substr($s, 0, 6);
                    }
                }
            }
        }

        return $achados;
    };
    afirmar('senha temporaria nao aparece em nenhuma tabela da gestao (usuario, sessao, tentativas, auditoria)', $varrer($pdo, [$senhaTemp]) === []);

    // --- auto-alteracao
    $r = $rn->editar($idA, $idA, 'Ana Admin Renomeada', 'usuario');
    afirmar('admin NAO altera o proprio perfil (proprio_perfil) e a recusa e auditada como SEM_EFEITO', !$r['ok'] && $r['codigo'] === 'proprio_perfil' && $dao->buscarPorId($idA)['perfil'] === 'admin' && gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'USUARIO_PERFIL' AND resultado = 'SEM_EFEITO' AND id_usuario = :i", ['i' => $idA]) >= 1);
    $r = $rn->editar($idA, $idA, 'Ana Admin Renomeada', 'admin');
    afirmar('admin pode alterar o proprio NOME (perfil igual)', $r['ok'] && $dao->buscarPorId($idA)['nome'] === 'Ana Admin Renomeada');
    $r = $rn->definirAtivo($idA, $idA, false);
    afirmar('admin NAO desativa a si mesmo (proprio_ativo)', !$r['ok'] && $r['codigo'] === 'proprio_ativo' && (int) $dao->buscarPorId($idA)['ativo'] === 1);
    $r = $rn->redefinirSenha($idA, $idA);
    afirmar('admin NAO redefine a propria senha pelo fluxo de admin (propria_senha)', !$r['ok'] && $r['codigo'] === 'propria_senha');
    $r = $rn->definirAtivo($idU, $idB, false);
    afirmar('usuario comum nao ativa/desativa ninguem (sem_permissao)', !$r['ok'] && $r['codigo'] === 'sem_permissao' && (int) $dao->buscarPorId($idB)['ativo'] === 1);
    $r = $rn->editar($idU, $idB, 'X Y', 'usuario');
    afirmar('usuario comum nao edita perfil de ninguem (sem_permissao)', !$r['ok'] && $r['codigo'] === 'sem_permissao' && $dao->buscarPorId($idB)['perfil'] === 'admin');
    $r = $rn->redefinirSenha($idU, $idB);
    afirmar('usuario comum nao redefine senha de ninguem (sem_permissao)', !$r['ok'] && $r['codigo'] === 'sem_permissao');
    $r = $rn->editar($idA, 999999, 'Nome Valido', 'usuario');
    afirmar('alvo inexistente => nao_encontrado', !$r['ok'] && $r['codigo'] === 'nao_encontrado');

    // --- ultimo admin: propriedade e corrida
    $alvosPossiveis = [$idA, $idB, $idU, (int) $novo['id_usuario']];
    $violacoes = 0;
    $autoAlteracoesAceitas = 0;
    mt_srand(20261006);
    for ($i = 0; $i < 300; $i++) {
        $ator = $alvosPossiveis[mt_rand(0, 3)];
        $alvo = $alvosPossiveis[mt_rand(0, 3)];
        $op = mt_rand(0, 3);
        if ($op === 0) {
            $res = $rn->definirAtivo($ator, $alvo, (bool) mt_rand(0, 1));
        } elseif ($op === 1) {
            $res = $rn->editar($ator, $alvo, 'Nome Aleatorio', mt_rand(0, 1) ? 'admin' : 'usuario');
        } elseif ($op === 2) {
            $res = $rn->redefinirSenha($ator, $alvo);
        } else {
            $res = $rn->definirAtivo($ator, $alvo, true);
        }
        if ($ator === $alvo && $res['ok'] && $op === 0 && !isset($res['sem_mudanca'])) {
            $autoAlteracoesAceitas++;
        }
        if ((int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_usuario WHERE perfil = 'admin' AND ativo = 1") < 1) {
            $violacoes++;
            // restaura para a simulacao continuar
            $pdo->exec("UPDATE tb_gestao_usuario SET perfil = 'admin', ativo = 1 WHERE id_usuario = " . (int) $idA);
        }
    }
    afirmar('propriedade (300 operacoes aleatorias de 4 atores): NUNCA fica sem admin ativo', $violacoes === 0);
    afirmar('propriedade: nenhuma auto-desativacao foi aceita', $autoAlteracoesAceitas === 0);
    $pdo->exec("UPDATE tb_gestao_usuario SET perfil = 'admin', ativo = 1 WHERE id_usuario IN ($idA, $idB)");
    $pdo->exec("UPDATE tb_gestao_usuario SET perfil = 'usuario', ativo = 1 WHERE id_usuario = $idU");
    // a simulacao redefine senhas ao acaso: restaura a senha conhecida dos 3 usuarios semeados
    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :h, deve_trocar_senha = 0, tentativas_falhas = 0, bloqueado_ate = NULL WHERE id_usuario IN (:a, :b, :u)')->execute(['h' => SenhaPolitica::gerarHash(GT_SENHA_BOA), 'a' => $idA, 'b' => $idB, 'u' => $idU]);
    $pdo->exec('DELETE FROM tb_gestao_sessao');

    // corrida: B tenta desativar A enquanto A (outra transacao, travando os admins) desativa B
    $scriptFilho = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_filho_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($scriptFilho, "<?php\nrequire_once " . var_export(__DIR__ . '/qa_qr_exclusivo_bootstrap.php', true) . ";\nuse App\\Rn\\UsuarioGestaoRn;\n"
        . '$pdo = qaQrAbrirBanco(' . var_export($banco, true) . ");\n"
        . '$r = (new UsuarioGestaoRn($pdo))->definirAtivo((int) $argv[1], (int) $argv[2], false);' . "\n"
        . 'echo json_encode($r);' . "\n");
    $dao->obterLockAdmins(5);
    $pdo->beginTransaction();
    $dao->travarAdminsAtivos();
    $proc = proc_open([PHP_BINARY, $scriptFilho, (string) $idB, (string) $idA], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(1_200_000); // o filho bloqueia no lock nomeado enquanto esta transacao o mantem
    $pdo->prepare('UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = :i')->execute(['i' => $idB]);
    $pdo->commit();
    $dao->liberarLockAdmins();
    $saidaFilho = (string) stream_get_contents($pipes[1]);
    $errFilho = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($scriptFilho);
    $resFilho = json_decode($saidaFilho, true);
    if (!is_array($resFilho) || ($resFilho['codigo'] ?? null) !== 'sem_permissao') {
        echo 'INFO: filho: ' . substr($saidaFilho, 0, 200) . ' | stderr: ' . substr($errFilho, 0, 300) . "
";
    }
    afirmar('corrida: B (desativado por A no meio) NAO consegue desativar A: sem_permissao e A segue ativo', is_array($resFilho) && ($resFilho['ok'] ?? null) === false && ($resFilho['codigo'] ?? null) === 'sem_permissao' && (int) $dao->buscarPorId($idA)['ativo'] === 1);
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = $idB");

    // --- sessoes revogadas
    $a1 = $auth();
    $tokU1 = $a1->criarSessao($idU);
    $tokU2 = $a1->criarSessao($idU);
    $r = $rn->definirAtivo($idA, $idU, false);
    afirmar('desativar usuario revoga TODAS as sessoes dele', $r['ok'] && ($r['sessoes_revogadas'] ?? 0) === 2 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_usuario = :i', ['i' => $idU]) === 0);
    $aCookie = $auth([], ['gestao_sid' => $tokU1]);
    afirmar('cookie de usuario desativado deixa de valer', $aCookie->sessaoAtual() === null);
    $tAtivo = gtLinhas($pdo, "SELECT detalhe, resultado FROM tb_gestao_auditoria WHERE acao = 'USUARIO_ATIVO' AND alvo_id = :i ORDER BY id_auditoria DESC LIMIT 1", ['i' => $idU])[0];
    afirmar('auditoria USUARIO_ATIVO com ativo_para=0 e sessoes_revogadas=2', $tAtivo['resultado'] === 'OK' && $tAtivo['detalhe'] === 'ativo_para=0;sessoes_revogadas=2');
    $r = $rn->definirAtivo($idA, $idU, true);
    afirmar('reativar funciona e reativar de novo e "sem mudanca"', $r['ok'] && ($rn->definirAtivo($idA, $idU, true)['sem_mudanca'] ?? false) === true);

    $dao->registrarFalhaSenha($idU, 5, 15);
    $tok = $a1->criarSessao($idU);
    $r = $rn->redefinirSenha($idA, $idU, '192.0.2.8');
    $tempReset = (string) ($r['senha_temporaria'] ?? '');
    $u = $dao->buscarPorId($idU);
    afirmar('redefinirSenha: senha temporaria nova, troca obrigatoria, contadores zerados e sessoes revogadas', $r['ok'] && $tempReset !== '' && (int) $u['deve_trocar_senha'] === 1 && (int) $u['tentativas_falhas'] === 0 && $u['bloqueado_ate'] === null && SenhaPolitica::verificar($tempReset, (string) $u['senha_hash']) && !SenhaPolitica::verificar(GT_SENHA_BOA, (string) $u['senha_hash']) && ($r['sessoes_revogadas'] ?? 0) === 1);
    afirmar('redefinirSenha: auditoria SENHA_RESETADA OK sem a senha', gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'SENHA_RESETADA' AND alvo_id = :i AND resultado = 'OK' AND detalhe = 'origem=web;sessoes_revogadas=1'", ['i' => $idU]) === '1' && $varrer($pdo, [$tempReset]) === []);

    // --- trocarPropriaSenha
    $tokC = $a1->criarSessao($idU);
    $tokC2 = $a1->criarSessao($idU);
    $r = $rn->trocarPropriaSenha($idU, 'senha-errada-aqui', 'Nova-Senha-Forte-1', 'Nova-Senha-Forte-1');
    afirmar('trocar senha: senha atual errada => erro e conta falha de senha', !$r['ok'] && isset($r['erros']['senha_atual']) && (int) $dao->buscarPorId($idU)['tentativas_falhas'] === 1);
    $r = $rn->trocarPropriaSenha($idU, $tempReset, 'curta', 'curta');
    afirmar('trocar senha: politica aplicada (curta)', !$r['ok'] && isset($r['erros']['senha_nova']));
    $r = $rn->trocarPropriaSenha($idU, $tempReset, 'Nova-Senha-Forte-1', 'Outra-Coisa-Forte-1');
    afirmar('trocar senha: confirmacao diferente recusada', !$r['ok'] && isset($r['erros']['senha_confirmacao']));
    $r = $rn->trocarPropriaSenha($idU, $tempReset, $tempReset, $tempReset);
    afirmar('trocar senha: nova igual a atual recusada', !$r['ok'] && isset($r['erros']['senha_nova']));
    $r = $rn->trocarPropriaSenha($idU, $tempReset, 'carla.usuario', 'carla.usuario');
    afirmar('trocar senha: nova igual ao login recusada', !$r['ok'] && isset($r['erros']['senha_nova']));
    $r = $rn->trocarPropriaSenha($idU, $tempReset, 'Nova-Senha-Forte-1', 'Nova-Senha-Forte-1', '192.0.2.9');
    $u = $dao->buscarPorId($idU);
    afirmar('trocar senha: ok, deve_trocar=0, hash novo, bloqueio zerado, TODAS as sessoes revogadas', $r['ok'] && (int) $u['deve_trocar_senha'] === 0 && SenhaPolitica::verificar('Nova-Senha-Forte-1', (string) $u['senha_hash']) && (int) $u['tentativas_falhas'] === 0 && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_usuario = :i', ['i' => $idU]) === 0);
    afirmar('trocar senha: auditoria SENHA_TROCADA OK e sem senha em lugar nenhum', gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'SENHA_TROCADA' AND resultado = 'OK'") === '1' && $varrer($pdo, ['Nova-Senha-Forte-1', 'Outra-Coisa-Forte-1', 'senha-errada-aqui', GT_SENHA_BOA]) === []);
    // 5 senhas atuais erradas bloqueiam a conta e revogam sessoes
    $a1->criarSessao($idU);
    $bloqueou = false;
    for ($i = 0; $i < 5; $i++) {
        $rr = $rn->trocarPropriaSenha($idU, 'errada-errada-' . $i, 'Mais-Uma-Senha-Forte-1', 'Mais-Uma-Senha-Forte-1');
        $bloqueou = $bloqueou || ($rr['bloqueada'] ?? false);
    }
    afirmar('trocar senha: 5 senhas atuais erradas bloqueiam a conta e derrubam as sessoes', $bloqueou && $dao->estaBloqueada($idU) && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_usuario = :i', ['i' => $idU]) === 0);
    $pdo->exec("UPDATE tb_gestao_usuario SET bloqueado_ate = NULL, tentativas_falhas = 0 WHERE id_usuario = $idU");

    // =====================================================================
    // F. AuthGestao: login
    // =====================================================================
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $x = $auth(['REMOTE_ADDR' => '198.51.100.20']);
    $rOk = $x->login('Ana.Admin', GT_SENHA_BOA);
    afirmar('login: ok (login normalizado), 200, token de 64 hex, deve_trocar_senha false', $rOk['ok'] && $rOk['status'] === 200 && preg_match('/\A[a-f0-9]{64}\z/', $rOk['token']) === 1 && $rOk['deve_trocar_senha'] === false);
    afirmar('login: so o sha256 do token fica no banco (token em claro NUNCA)', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => hash('sha256', $rOk['token'])]) === 1 && $varrer($pdo, [$rOk['token']]) === []);
    afirmar('login: auditoria LOGIN_OK com ator, alvo, IP e detalhe fixo', gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'LOGIN_OK' AND id_usuario = :i AND detalhe = 'origem=web' AND resultado = 'OK'", ['i' => $idA]) === '1');
    $rNao = $x->login('inexistente.pessoa', GT_SENHA_BOA);
    $rErr = $x->login('ana.admin', 'senha-errada-qualquer');
    afirmar('login: usuario inexistente e senha errada => MESMO resultado (401) sem pistas', $rNao === $rErr && $rNao === ['ok' => false, 'status' => 401]);
    afirmar('login: mensagem generica unica e uma constante', AuthGestao::MSG_LOGIN_GENERICA === 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.');
    $rIna = $auth(['REMOTE_ADDR' => '198.51.100.21'])->login('carla.usuario', 'qualquer-senha-12');
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 0 WHERE id_usuario = $idU");
    $rIna2 = $auth(['REMOTE_ADDR' => '198.51.100.22'])->login('carla.usuario', 'Nova-Senha-Forte-1');
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = $idU");
    afirmar('login: usuario inativo (mesmo com a senha certa) => 401 igual aos demais', $rIna2 === ['ok' => false, 'status' => 401]);
    foreach (['', 'x', 'ana', 'ana.admin ' . str_repeat('a', 200), "ana.admin' OR '1'='1", 'ana.admin\' --', "ana.admin\0", 'ANA.ADMIN; DROP TABLE tb_gestao_usuario', 'ana.admin@x'] as $ruim) {
        $rr = $auth(['REMOTE_ADDR' => '198.51.100.23'])->login($ruim, GT_SENHA_BOA);
        afirmar('login: formato/SQLi invalido ' . json_encode(mb_substr($ruim, 0, 24)) . ' => 401', $rr === ['ok' => false, 'status' => 401]);
    }
    foreach (["' OR '1'='1", "' OR 1=1 -- ", "\" OR \"\"=\"", str_repeat('A', 5000)] as $ruim) {
        $rr = $auth(['REMOTE_ADDR' => '198.51.100.24'])->login('ana.admin', $ruim);
        afirmar('login: senha com payload SQLi/gigante ' . json_encode(mb_substr($ruim, 0, 14)) . ' => 401', $rr === ['ok' => false, 'status' => 401]);
    }
    afirmar('SQLi: as 4 tabelas continuam intactas', (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_usuario') >= 4 && gtEscalar($pdo, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tb\\_gestao\\_%'") === '4');

    // tempo: usuario inexistente x senha errada x conta bloqueada (custo similar)
    $tempo = static function (callable $f, int $n = 7): float {
        $v = [];
        for ($i = 0; $i < $n; $i++) {
            $t = hrtime(true);
            $f($i);
            $v[] = (hrtime(true) - $t) / 1e6;
        }

        return mediana($v);
    };
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $tNaoExiste = $tempo(fn ($i) => $auth(['REMOTE_ADDR' => '198.51.100.30'])->login('naoexiste.pessoa', 'Qualquer-Senha-1'), 7);
    $pdo->exec("UPDATE tb_gestao_usuario SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id_usuario = $idB");
    $tErrada = $tempo(function ($i) use ($auth, $pdo, $idB) {
        $pdo->exec("UPDATE tb_gestao_usuario SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id_usuario = $idB");
        $auth(['REMOTE_ADDR' => '198.51.100.31'])->login('beto.admin', 'Qualquer-Senha-1');
    }, 7);
    $pdo->exec("UPDATE tb_gestao_usuario SET bloqueado_ate = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id_usuario = $idB");
    $tBloq = $tempo(fn ($i) => $auth(['REMOTE_ADDR' => '198.51.100.32'])->login('beto.admin', GT_SENHA_BOA), 7);
    $pdo->exec("UPDATE tb_gestao_usuario SET bloqueado_ate = NULL, tentativas_falhas = 0 WHERE id_usuario = $idB");
    $razao = static fn (float $a, float $b): float => max($a, $b) / max(0.001, min($a, $b));
    printf("INFO: tempos medianos (ms): inexistente %.1f, senha errada %.1f, conta bloqueada %.1f\n", $tNaoExiste, $tErrada, $tBloq);
    afirmar('login: tempo similar (razao < 2,0) entre usuario inexistente e senha errada', $razao($tNaoExiste, $tErrada) < 2.0);
    afirmar('login: tempo similar (razao < 2,0) entre conta bloqueada e usuario inexistente', $razao($tBloq, $tNaoExiste) < 2.0);

    // bloqueio por conta: 5 falhas => 15 min
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $ipC = ['REMOTE_ADDR' => '198.51.100.40'];
    for ($i = 1; $i <= 4; $i++) {
        $auth(['REMOTE_ADDR' => '198.51.100.4' . $i])->login('beto.admin', 'errada-errada-' . $i);
    }
    afirmar('conta: 4 falhas (IPs distintos) ainda nao bloqueiam e a senha certa entra', $auth(['REMOTE_ADDR' => '198.51.100.49'])->login('beto.admin', GT_SENHA_BOA)['ok'] === true);
    afirmar('conta: o login certo zerou o contador de falhas', (int) $dao->buscarPorId($idB)['tentativas_falhas'] === 0);
    for ($i = 1; $i <= 5; $i++) {
        $auth(['REMOTE_ADDR' => '198.51.100.5' . $i])->login('beto.admin', 'errada-errada-' . $i);
    }
    afirmar('conta: a 5a falha bloqueia por ~15 min (bloqueado_ate no futuro)', $dao->estaBloqueada($idB) && (int) gtEscalar($pdo, 'SELECT TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_ate) FROM tb_gestao_usuario WHERE id_usuario = :i', ['i' => $idB]) >= 14);
    $rBloq = $auth(['REMOTE_ADDR' => '198.51.100.60'])->login('beto.admin', GT_SENHA_BOA);
    afirmar('conta: com a conta bloqueada, ate a senha CERTA e recusada com a mesma resposta generica', $rBloq === ['ok' => false, 'status' => 401]);
    $bloqAntes = gtEscalar($pdo, 'SELECT bloqueado_ate FROM tb_gestao_usuario WHERE id_usuario = :i', ['i' => $idB]);
    $auth(['REMOTE_ADDR' => '198.51.100.61'])->login('beto.admin', 'errada-de-novo-1');
    afirmar('conta: tentativas durante o bloqueio NAO estendem o bloqueio', gtEscalar($pdo, 'SELECT bloqueado_ate FROM tb_gestao_usuario WHERE id_usuario = :i', ['i' => $idB]) === $bloqAntes);
    $pdo->exec("UPDATE tb_gestao_usuario SET bloqueado_ate = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id_usuario = $idB");
    afirmar('conta: passados os 15 min o login volta a funcionar', $auth(['REMOTE_ADDR' => '198.51.100.62'])->login('beto.admin', GT_SENHA_BOA)['ok'] === true);
    afirmar('conta: auditoria LOGIN_FALHA registra motivo conta_bloqueada sem login digitado', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'LOGIN_FALHA' AND detalhe = 'motivo=conta_bloqueada'") >= 1 && (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'LOGIN_FALHA' AND (detalhe LIKE '%beto%' OR detalhe LIKE '%errada%')") === 0);

    // limite por IP: 10 falhas / 15 min
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $ipR = '198.51.100.70';
    for ($i = 1; $i <= 10; $i++) {
        $rr = $auth(['REMOTE_ADDR' => $ipR])->login('fantasma.numero' . chr(96 + $i), 'x-senha-qualquer-1');
    }
    $r11 = $auth(['REMOTE_ADDR' => $ipR])->login('ana.admin', GT_SENHA_BOA);
    afirmar('IP: a 11a tentativa (ate com credenciais certas) => 429 com retry_after', !$r11['ok'] && $r11['status'] === 429 && $r11['retry_after'] >= 1 && $r11['retry_after'] <= 900);
    afirmar('IP: outro IP continua entrando normalmente', $auth(['REMOTE_ADDR' => '198.51.100.71'])->login('ana.admin', GT_SENHA_BOA)['ok'] === true);
    afirmar('IP: tabela guarda ip_hash (64 hex, com sal) e nunca o IP', gtLinhas($pdo, 'SELECT ip_hash FROM tb_gestao_login_tentativa WHERE ip_hash = :h', ['h' => IpCliente::hash($ipR, GT_SAL)]) !== [] && $varrer($pdo, [$ipR, '198.51.100.7']) === []);
    afirmar('IP: apenas falhas contam (login ok nao incrementa)', (int) gtEscalar($pdo, 'SELECT contador FROM tb_gestao_login_tentativa WHERE ip_hash = :h', ['h' => IpCliente::hash($ipR, GT_SAL)]) === 10);
    afirmar('IP: auditoria registra motivo ip_limitado uma vez ao atingir o limite', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'LOGIN_FALHA' AND detalhe = 'motivo=ip_limitado'") === 1);
    // CF-Connecting-IP forjado de IP nao Cloudflare nao fura o limite
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    for ($i = 1; $i <= 10; $i++) {
        $auth(['REMOTE_ADDR' => '203.0.113.80', 'HTTP_CF_CONNECTING_IP' => '9.9.9.' . $i])->login('fantasma.numero' . chr(96 + $i), 'x-senha-qualquer-1');
    }
    $rF = $auth(['REMOTE_ADDR' => '203.0.113.80', 'HTTP_CF_CONNECTING_IP' => '9.9.9.200'])->login('ana.admin', GT_SENHA_BOA);
    afirmar('IP: CF-Connecting-IP FORJADO (REMOTE_ADDR comum) NAO evita o limite', !$rF['ok'] && $rF['status'] === 429);
    // atras do Cloudflare de verdade: o IP do header e que conta
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    for ($i = 1; $i <= 10; $i++) {
        $auth(['REMOTE_ADDR' => '172.64.0.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.90'])->login('fantasma.numero' . chr(96 + $i), 'x-senha-qualquer-1');
    }
    $rCf1 = $auth(['REMOTE_ADDR' => '172.64.0.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.90'])->login('ana.admin', GT_SENHA_BOA);
    $rCf2 = $auth(['REMOTE_ADDR' => '172.64.0.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.91'])->login('ana.admin', GT_SENHA_BOA);
    afirmar('IP: atras do Cloudflare o limite e por IP do cliente (um bloqueado, outro livre)', $rCf1['status'] === 429 && $rCf2['ok'] === true);

    // rehash transparente
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $hashAntigo = password_hash('Senha-Hash-Antigo-1', PASSWORD_BCRYPT, ['cost' => 10]);
    $idR = gtSemear($pdo, 'rita.rehash', 'usuario', 'Senha-Hash-Antigo-1');
    $pdo->prepare('UPDATE tb_gestao_usuario SET senha_hash = :h WHERE id_usuario = :i')->execute(['h' => $hashAntigo, 'i' => $idR]);
    $rr = $auth(['REMOTE_ADDR' => '198.51.100.95'])->login('rita.rehash', 'Senha-Hash-Antigo-1');
    $novoHash = (string) gtEscalar($pdo, 'SELECT senha_hash FROM tb_gestao_usuario WHERE id_usuario = :i', ['i' => $idR]);
    afirmar('login: password_needs_rehash regrava o hash no algoritmo atual e a senha continua valida', $rr['ok'] && $novoHash !== $hashAntigo && !SenhaPolitica::precisaRehash($novoHash) && SenhaPolitica::verificar('Senha-Hash-Antigo-1', $novoHash));

    // =====================================================================
    // G. Sessao
    // =====================================================================
    $pdo->exec('DELETE FROM tb_gestao_login_tentativa');
    $l1 = $auth(['REMOTE_ADDR' => '198.51.100.96'])->login('ana.admin', GT_SENHA_BOA);
    $l2 = $auth(['REMOTE_ADDR' => '198.51.100.96'])->login('ana.admin', GT_SENHA_BOA);
    afirmar('sessao: id NOVO a cada login (tokens e ids distintos, ambas validas)', $l1['token'] !== $l2['token'] && $auth([], ['gestao_sid' => $l1['token']])->sessaoAtual() !== null && $auth([], ['gestao_sid' => $l2['token']])->sessaoAtual() !== null);
    $l3 = $auth(['REMOTE_ADDR' => '198.51.100.96'], ['gestao_sid' => $l1['token']])->login('ana.admin', GT_SENHA_BOA);
    afirmar('sessao: login com cookie antigo (fixacao) DESTROI a sessao antiga e emite outra', $l3['token'] !== $l1['token'] && $auth([], ['gestao_sid' => $l1['token']])->sessaoAtual() === null);
    $tok = $l2['token'];
    $s = $auth([], ['gestao_sid' => $tok])->sessaoAtual();
    afirmar('sessao: devolve usuario, perfil e csrf do BANCO', $s !== null && $s['login'] === 'ana.admin' && $s['perfil'] === 'admin' && preg_match('/\A[a-f0-9]{64}\z/', $s['csrf_token']) === 1 && $s['id_usuario'] === $idA);
    $idSess = hash('sha256', $tok);
    foreach (['', 'abc', str_repeat('g', 64), strtoupper($tok), $tok . '0', substr($tok, 0, 63), $idSess] as $ruim) {
        afirmar('sessao: cookie malformado/de outro tipo ignorado ' . json_encode(substr($ruim, 0, 12)), $auth([], ['gestao_sid' => $ruim])->sessaoAtual() === null);
    }
    afirmar('sessao: id_sessao (hash) usado como cookie NAO autentica', $auth([], ['gestao_sid' => $idSess])->sessaoAtual() === null);
    // inatividade
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 29 MINUTE) WHERE id_sessao = :i')->execute(['i' => $idSess]);
    afirmar('sessao: 29 min de inatividade ainda vale', $auth([], ['gestao_sid' => $tok])->sessaoAtual() !== null);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE id_sessao = :i')->execute(['i' => $idSess]);
    afirmar('sessao: 31 min de inatividade EXPIRA (e a linha e removida)', $auth([], ['gestao_sid' => $tok])->sessaoAtual() === null && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $idSess]) === 0);
    // teto absoluto
    $tok2 = $auth()->criarSessao($idA);
    $id2 = hash('sha256', $tok2);
    $pdo->prepare('UPDATE tb_gestao_sessao SET expira_em = DATE_SUB(NOW(), INTERVAL 1 SECOND), ultimo_acesso_em = NOW() WHERE id_sessao = :i')->execute(['i' => $id2]);
    afirmar('sessao: teto absoluto vencido EXPIRA mesmo com atividade recente', $auth([], ['gestao_sid' => $tok2])->sessaoAtual() === null);
    $tok3 = $auth()->criarSessao($idA);
    $exp = gtLinhas($pdo, 'SELECT TIMESTAMPDIFF(MINUTE, criado_em, expira_em) AS m FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => hash('sha256', $tok3)])[0]['m'];
    afirmar('sessao: expira_em = criacao + GESTAO_SESSION_ABSOLUTE_MIN (720 min)', (int) $exp === 720);
    // idle configuravel
    $cfg15 = GestaoConfig::doAmbiente(['GESTAO_HASH_SALT' => GT_SAL, 'GESTAO_SESSION_IDLE_MIN' => '15']);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id_sessao = :i')->execute(['i' => hash('sha256', $tok3)]);
    afirmar('sessao: inatividade vem do .env (15 min expira em 20)', (new AuthGestao($pdo, $cfg15, servidor(), ['gestao_sid' => $tok3], $relogioFixo))->sessaoAtual() === null);
    // atualizacao de ultimo_acesso no maximo 1x por minuto
    $tok4 = $auth()->criarSessao($idA);
    $id4 = hash('sha256', $tok4);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 30 SECOND) WHERE id_sessao = :i')->execute(['i' => $id4]);
    $antesTs = gtEscalar($pdo, 'SELECT ultimo_acesso_em FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $id4]);
    $auth([], ['gestao_sid' => $tok4])->sessaoAtual();
    afirmar('sessao: com menos de 60 s desde o ultimo acesso NAO escreve no banco', gtEscalar($pdo, 'SELECT ultimo_acesso_em FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $id4]) === $antesTs);
    $pdo->prepare('UPDATE tb_gestao_sessao SET ultimo_acesso_em = DATE_SUB(NOW(), INTERVAL 90 SECOND) WHERE id_sessao = :i')->execute(['i' => $id4]);
    $antesTs = gtEscalar($pdo, 'SELECT ultimo_acesso_em FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $id4]);
    $auth([], ['gestao_sid' => $tok4])->sessaoAtual();
    afirmar('sessao: com mais de 60 s o ultimo acesso e atualizado', gtEscalar($pdo, 'SELECT ultimo_acesso_em FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $id4]) !== $antesTs);
    // User-Agent
    afirmar('sessao: User-Agent diferente invalida a sessao (cookie roubado)', $auth(['HTTP_USER_AGENT' => 'Outro-Navegador/9'], ['gestao_sid' => $tok4])->sessaoAtual() === null && (int) gtEscalar($pdo, 'SELECT COUNT(*) FROM tb_gestao_sessao WHERE id_sessao = :i', ['i' => $id4]) === 0);
    // usuario desativado / perfil rebaixado: relido do banco a cada requisicao
    $tok5 = $auth()->criarSessao($idB);
    $pdo->exec("UPDATE tb_gestao_usuario SET perfil = 'usuario' WHERE id_usuario = $idB");
    afirmar('sessao: perfil e relido do BANCO a cada requisicao (rebaixado vira usuario)', $auth([], ['gestao_sid' => $tok5])->sessaoAtual()['perfil'] === 'usuario');
    $pdo->exec("UPDATE tb_gestao_usuario SET perfil = 'admin', ativo = 0 WHERE id_usuario = $idB");
    afirmar('sessao: usuario desativado direto no banco invalida a sessao na proxima requisicao', $auth([], ['gestao_sid' => $tok5])->sessaoAtual() === null);
    $pdo->exec("UPDATE tb_gestao_usuario SET ativo = 1 WHERE id_usuario = $idB");
    // logout
    $tok6 = $auth()->criarSessao($idA);
    $aL = $auth(['REMOTE_ADDR' => '198.51.100.97'], ['gestao_sid' => $tok6]);
    $aL->logout();
    afirmar('logout: destroi a sessao NO SERVIDOR (cookie guardado nao volta a valer) e audita LOGOUT', $auth([], ['gestao_sid' => $tok6])->sessaoAtual() === null && gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao = 'LOGOUT' AND id_usuario = :i AND resultado = 'OK'", ['i' => $idA]) >= '1');
    // revogacao por troca de senha ja coberta (Rn); revogarSessoes com excecao
    $tA = $auth()->criarSessao($idA);
    $tB = $auth()->criarSessao($idA);
    $auth()->revogarSessoes($idA, hash('sha256', $tA));
    afirmar('revogarSessoes: mantem so a sessao indicada', $auth([], ['gestao_sid' => $tA])->sessaoAtual() !== null && $auth([], ['gestao_sid' => $tB])->sessaoAtual() === null);

    // =====================================================================
    // H. CSRF, origem, HTTPS, host
    // =====================================================================
    $sx = $auth([], ['gestao_sid' => $tA]);
    $csrf = $sx->csrfToken();
    afirmar('CSRF: token da sessao (64 hex) confere; vazio/nulo/errado/de outra sessao NAO', $sx->csrfValido($csrf) && !$sx->csrfValido(null) && !$sx->csrfValido('') && !$sx->csrfValido(str_repeat('0', 64)) && !$sx->csrfValido(strtoupper($csrf)) && !$sx->csrfValido($csrf . 'x'));
    $tOutra = $auth()->criarSessao($idA);
    $csrfOutra = $auth([], ['gestao_sid' => $tOutra])->csrfToken();
    afirmar('CSRF: token de OUTRA sessao do mesmo usuario e recusado', $csrfOutra !== $csrf && !$sx->csrfValido($csrfOutra));
    afirmar('CSRF: sem sessao nenhum token vale', !$auth()->csrfValido($csrf));
    afirmar('CSRF: token de login assinado, valido e adulteravel? (adulterado/expirado/futuro/outro sal recusados)', (function () use ($auth, $pdo, $relogioFixo) {
        $a = $auth();
        $t = $a->tokenLogin();
        $ok = $a->tokenLoginValido($t);
        [$ts, $mac] = explode('.', $t);
        $adulterado = !$a->tokenLoginValido($ts . '.' . str_repeat('a', 64)) && !$a->tokenLoginValido(((int) $ts + 1) . '.' . $mac) && !$a->tokenLoginValido(null) && !$a->tokenLoginValido('') && !$a->tokenLoginValido('x.y');
        $depois = new AuthGestao($pdo, GestaoConfig::doAmbiente(gtEnvPadrao()), servidor(), [], static fn (): int => 1_800_000_000 + 7201);
        $expirado = !$depois->tokenLoginValido($t);
        $antes = new AuthGestao($pdo, GestaoConfig::doAmbiente(gtEnvPadrao()), servidor(), [], static fn (): int => 1_800_000_000 - 301);
        $futuro = !$antes->tokenLoginValido($t);
        $outroSal = new AuthGestao($pdo, GestaoConfig::doAmbiente(['GESTAO_HASH_SALT' => str_repeat('z', 40)] + gtEnvPadrao()), servidor(), [], $relogioFixo);
        $naoCruza = !$outroSal->tokenLoginValido($t);

        return $ok && $adulterado && $expirado && $futuro && $naoCruza;
    })());

    $o = static fn (array $s): bool => $auth($s + ['REQUEST_METHOD' => 'POST'])->origemPermitida();
    afirmar('origem: sem Origin e sem Sec-Fetch-Site (token decide) => permitida', $o([]));
    afirmar('origem: Origin igual ao Host (https) => permitida', $o(['HTTP_ORIGIN' => 'https://gestao.exemplo.test']));
    afirmar('origem: Origin de outro site => RECUSADA', !$o(['HTTP_ORIGIN' => 'https://evil.example']));
    afirmar('origem: Origin "null" (sandbox/redirect) => RECUSADA', !$o(['HTTP_ORIGIN' => 'null']));
    afirmar('origem: Origin com esquema http numa requisicao https => RECUSADA', !$o(['HTTP_ORIGIN' => 'http://gestao.exemplo.test']));
    afirmar('origem: Origin com subdominio parecido/porta diferente => RECUSADA', !$o(['HTTP_ORIGIN' => 'https://gestao.exemplo.test.evil.example']) && !$o(['HTTP_ORIGIN' => 'https://gestao.exemplo.test:8443']) && !$o(['HTTP_ORIGIN' => 'https://evil.gestao.exemplo.test']));
    afirmar('origem: Origin com porta padrao 443 explicita equivale ao Host sem porta', $o(['HTTP_ORIGIN' => 'https://gestao.exemplo.test:443']));
    afirmar('origem: Origin com caminho/credencial => RECUSADA', !$o(['HTTP_ORIGIN' => 'https://gestao.exemplo.test/x']) && !$o(['HTTP_ORIGIN' => 'https://u:p@gestao.exemplo.test']));
    afirmar('origem: Sec-Fetch-Site cross-site/same-site => RECUSADA, same-origin/none => permitida', !$o(['HTTP_SEC_FETCH_SITE' => 'cross-site']) && !$o(['HTTP_SEC_FETCH_SITE' => 'same-site']) && $o(['HTTP_SEC_FETCH_SITE' => 'same-origin']) && $o(['HTTP_SEC_FETCH_SITE' => 'none']));
    afirmar('origem: Host invalido => recusada e hostDaRequisicao null', !$o(['HTTP_HOST' => 'a b']) && $auth(['HTTP_HOST' => 'evil/../x'])->hostDaRequisicao() === null && $auth(['HTTP_HOST' => ''])->hostDaRequisicao() === null && $auth(['HTTP_HOST' => "a.test\r\nX: y"])->hostDaRequisicao() === null);
    afirmar('origem: Host com porta e preservado quando nao padrao', $auth(['HTTP_HOST' => 'Dev.Local:8080'])->hostDaRequisicao() === 'dev.local:8080' && $o(['HTTP_HOST' => 'dev.local:8080', 'HTTPS' => 'on', 'HTTP_ORIGIN' => 'https://dev.local:8080']));

    $cfgHttp = GestaoConfig::doAmbiente(['GESTAO_PERMITIR_HTTP' => 'true'] + gtEnvPadrao());
    $mk = static fn (array $s, GestaoConfig $c): AuthGestao => new AuthGestao($pdo, $c, ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_HOST' => 'g.test'] + $s, [], $relogioFixo);
    afirmar('HTTPS: https => aceito', $mk(['HTTPS' => 'on'], $cfg)->transporteAceito());
    afirmar('HTTPS: HTTP puro SEM a flag => recusado (padrao)', !$mk([], $cfg)->transporteAceito() && !$mk(['HTTPS' => 'off'], $cfg)->transporteAceito() && !$mk(['HTTPS' => ''], $cfg)->transporteAceito());
    afirmar('HTTPS: HTTP puro COM GESTAO_PERMITIR_HTTP=true => aceito (so dev)', $mk([], $cfgHttp)->transporteAceito());
    afirmar('HTTPS: X-Forwarded-Proto https e porta 443 contam (igual a AuthServidor::requisicaoHttps)', $mk(['HTTP_X_FORWARDED_PROTO' => 'https'], $cfg)->transporteAceito() && $mk(['SERVER_PORT' => '443'], $cfg)->transporteAceito());
    afirmar('HTTPS: Origin http aceito somente quando a requisicao e http (dev)', (function () use ($mk, $cfgHttp) {
        $a = $mk(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://g.test'], $cfgHttp);

        return $a->origemPermitida();
    })());

    afirmar('metodo: HEAD conta como GET (seguro) e POST nao', $auth(['REQUEST_METHOD' => 'HEAD'])->metodoSeguro() && !$auth(['REQUEST_METHOD' => 'POST'])->metodoSeguro() && !$auth(['REQUEST_METHOD' => 'DELETE'])->metodoSeguro());

    // =====================================================================
    // I. higiene final do banco desta suite
    // =====================================================================
    afirmar('sem senha digitada, hash ou token em log de auditoria', $varrer($pdo, [GT_SENHA_BOA, 'Nova-Senha-Forte-1', $l1['token'], $l2['token'], $tok]) === []);
    afirmar('auditoria: todas as acoes gravadas pertencem ao catalogo fechado', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE acao NOT IN ('" . implode("','", AuditoriaDao::ACOES) . "')") === 0);
    afirmar('auditoria: nenhuma linha PENDENTE ficou aberta', (int) gtEscalar($pdo, "SELECT COUNT(*) FROM tb_gestao_auditoria WHERE resultado = 'PENDENTE'") === 0);
    afirmar('auditoria: detalhes so com chaves da allowlist', (function () use ($pdo) {
        foreach ($pdo->query('SELECT detalhe FROM tb_gestao_auditoria WHERE detalhe IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $d) {
            foreach (explode(';', (string) $d) as $par) {
                [$k] = explode('=', $par, 2);
                if (!array_key_exists($k, AuditoriaDao::DETALHE_CAMPOS)) {
                    return false;
                }
            }
        }

        return true;
    })());
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ')', false);
} finally {
    gtDestruirAmbiente($banco, $storage);
}
exit(gtResumo('teste_gestao_unidade'));
