<?php

/**
 * Integracao descartavel QR-only: controller e DAO reais, VIO/Talent falsos.
 * O processo principal cria/descarta apenas qa_qr_exclusivo_<hex>; workers
 * existem porque Resposta termina o request com exit.
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_qr_exclusivo_bootstrap.php';

use App\Controller\DocumentoController;
use App\Dao\AtendimentoDao;
use App\Dao\FilaEnvioDao;
use App\Dao\RateLimitVioStatusDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;
use App\Rn\TalentClient;
use App\Rn\TalentRn;

// Sentinelas de prova negativa de logs (valores ficticios, sem dados reais).
// PII "sucesso": persiste legitimamente em tb_atendimento quando o documento e
// validado, mas nunca em log, resposta HTTP ou demais tabelas.
// PII "falha": so aparece em cenarios de falha/rejeicao; nao pode ir a lugar nenhum.
const QA_S_API_KEY = 'QA-SENTINELA-APIKEY-9f3c1e7a';
const QA_S_OK = ['cpf' => '52998224725', 'cpf_fmt' => '529.982.247-25', 'nome' => 'QA MOTORISTA', 'placa' => 'ABC1234', 'renavam' => 'QA-RENAVAM', 'rntrc' => 'QA-RNTRC'];
const QA_S_FALHA = ['cpf' => '11144477735', 'cpf_fmt' => '111.444.777-35', 'nome' => 'QA SENTINELA FALHA', 'placa' => 'QAF9Z99', 'renavam' => 'QA-RENAVAM-FALHA', 'rntrc' => 'QA-RNTRC-FALHA'];
const QA_S_QR_INVALIDO = 'QRINVALIDOSENTINELA!!';
const QA_S_QR_NAO_JPEG = 'QA-NAO-JPEG-SENTINELA-QR';
putenv('VIO_API_BR_API_KEY=' . QA_S_API_KEY);

final class QaQrVioFalso
{
    public function __construct(private PDO $pdo, private array $cenario) {}
    private function lancar(): never
    {
        throw new RuntimeException('VIO falso: ' . implode(' ', QA_S_OK) . ' ' . implode(' ', QA_S_FALHA) . ' ' . QA_S_API_KEY . ' ' . (string) ($this->cenario['imagem_qr_base64'] ?? ''));
    }
    public function enviarParaLeitura(string $jpeg): array
    {
        $this->pdo->exec('UPDATE qa_qr_auditoria SET posts = posts + 1 WHERE id = 1');
        if (!empty($this->cenario['lanca_envio'])) $this->lancar();
        return ($this->cenario['envio'] ?? 'ok') === 'ambiguo'
            ? ['ok' => false, 'ambiguo' => true]
            : ['ok' => true, 'id_externo' => 'qa-vio-' . ($this->cenario['tipo'] ?? 'x')];
    }
    public function consultarResultado(string $id): array
    {
        $this->pdo->exec('UPDATE qa_qr_auditoria SET gets = gets + 1 WHERE id = 1');
        if (!empty($this->cenario['lanca_consulta'])) $this->lancar();
        return $this->cenario['resultado'] ?? ['ok' => false, 'ambiguo' => true];
    }
}

final class QaQrTalentFalso extends TalentClient
{
    public array $payloads = [];
    public function checkin(array $payload): array { $this->payloads[] = $payload; return ['senha' => 'QA', 'protocolo' => null]; }
}

function qaQrWorker(string $banco, string $json): void
{
    $entrada = json_decode(base64_decode($json, true) ?: '', true);
    if (!is_array($entrada)) { fwrite(STDERR, "worker invalido\n"); exit(2); }
    $pdo = qaQrAbrirBanco($banco);
    $dao = new AtendimentoDao($pdo);
    $rn = new DocumentoRn(new VioCacheDao($pdo), $dao);
    // Factory de composicao: nenhum valor do request participa da injecao.
    $controller = new DocumentoController($dao, $rn, $pdo, new RateLimitVioStatusDao($pdo), fn() => new QaQrVioFalso($pdo, $entrada));
    if (($entrada['acao'] ?? '') === 'iniciar') $controller->iniciarProcessamento($entrada, (int) $entrada['id_totem']);
    if (($entrada['acao'] ?? '') === 'status') $controller->statusProcessamento($entrada, (int) $entrada['id_totem']);
    fwrite(STDERR, "acao invalida\n"); exit(2);
}

if (($argv[1] ?? '') === '--worker') qaQrWorker((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));

$total = 0; $falhas = 0;
function qaAfirmar(string $texto, bool $ok): void { global $total, $falhas; ++$total; if (!$ok) ++$falhas; echo ($ok ? 'OK   ' : 'FALHA') . " - {$texto}\n"; }
function qaJpeg(): string { $im = imagecreatetruecolor(8, 8); ob_start(); imagejpeg($im, null, 85); $bytes = (string) ob_get_clean(); imagedestroy($im); return 'data:image/jpeg;base64,' . base64_encode($bytes); }
$qaLogArquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_qr_exclusivo_log_' . bin2hex(random_bytes(6)) . '.log';
ini_set('log_errors', '1'); ini_set('error_log', $qaLogArquivo); // processo principal (TalentRn) tambem
$qaSaidas = []; // stdout (resposta HTTP do controller) e stderr de todos os workers
function qaChamarWorker(string $banco, array $dados): array
{
    global $qaLogArquivo, $qaSaidas;
    // error_log do worker redirecionado para arquivo temporario do QA.
    $cmd = [PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log="' . $qaLogArquivo . '"', __FILE__, '--worker', $banco, base64_encode(json_encode($dados, JSON_THROW_ON_ERROR))];
    $pipes = []; $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($p)) throw new RuntimeException('Nao iniciou worker QA');
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p); $body = json_decode($out, true);
    $qaSaidas[] = $out; $qaSaidas[] = $err;
    return ['code' => $code, 'body' => is_array($body) ? $body : null, 'stderr' => $err];
}
function qaNovo(AtendimentoDao $dao, int $totem, string $etapa, string $placa = 'ABC1234'): int { $id = $dao->criar($totem, 'expedicao', $placa); $dao->atualizarEtapa($id, $etapa); return $id; }
function qaResultado(string $tipo, array $campos = []): array
{
    $dados = $tipo === 'cnh'
        ? ['Nome' => 'QA MOTORISTA', 'CPF' => '52998224725', 'Validade' => '2035-12-31']
        : ['Placa' => 'ABC1234', 'Exercício' => 2026, 'UF' => 'SP', 'RNTRC' => 'QA-RNTRC', 'Tipo' => 'CAMINHAO', 'Renavam' => 'QA-RENAVAM'];
    return ['ok' => true, 'estado_leitura' => 'completed', 'qr_type' => 'vio', 'dados_leitura' => array_replace($dados, $campos), 'comparacao' => ['status' => 'pending', 'summary' => ['reliable' => false, 'mismatched' => 99]]];
}
function qaContador(PDO $pdo, string $campo): int { return (int) $pdo->query("SELECT {$campo} FROM qa_qr_auditoria WHERE id=1")->fetchColumn(); }

$banco = null;
try {
    [$pdo, $banco] = qaQrCriarBanco();
    $pdo->exec('CREATE TABLE qa_qr_auditoria (id TINYINT PRIMARY KEY, posts INT NOT NULL DEFAULT 0, gets INT NOT NULL DEFAULT 0)');
    $pdo->exec('INSERT INTO qa_qr_auditoria (id) VALUES (1)');
    $pdo->exec("INSERT INTO tb_totem (codigo,nome,token_api,ativo) VALUES ('QAQR1','QA',REPEAT('a',64),1),('QAQR2','QA2',REPEAT('b',64),1)");
    $totem = (int) $pdo->query("SELECT id_totem FROM tb_totem WHERE codigo='QAQR1'")->fetchColumn();
    $outroTotem = (int) $pdo->query("SELECT id_totem FROM tb_totem WHERE codigo='QAQR2'")->fetchColumn();
    $dao = new AtendimentoDao($pdo); $jpeg = qaJpeg();

    $cnh = qaNovo($dao, $totem, 'exp_cnh');
    $r = qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$cnh,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    qaAfirmar('CNH QR valido inicia no controller real', $r['code'] === 0 && ($r['body']['sucesso'] ?? false));
    qaAfirmar('QR valido faz exatamente um POST VIO falso', qaContador($pdo, 'posts') === 1);
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$cnh,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    qaAfirmar('CAS impede retry e segundo POST', qaContador($pdo, 'posts') === 1);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$cnh,'id_totem'=>$totem,'tipo'=>'cnh','resultado'=>qaResultado('cnh')]);
    $at = $dao->buscarPorId($cnh);
    qaAfirmar('compare pending/reliable=false sao apenas diagnosticos', $at['cnh_origem_validacao'] === 'VIO_API_BR');
    qaAfirmar('polling faz um GET sem segundo POST', qaContador($pdo, 'gets') === 1 && qaContador($pdo, 'posts') === 1);

    $crlv = qaNovo($dao, $totem, 'exp_crlv');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$crlv,'id_totem'=>$totem,'tipo'=>'crlv','imagem_qr_base64'=>$jpeg]);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$crlv,'id_totem'=>$totem,'tipo'=>'crlv','resultado'=>qaResultado('crlv')]);
    qaAfirmar('CRLV valida somente resultado CRLV', ($dao->buscarPorId($crlv)['crlv_origem_validacao'] ?? '') === 'VIO_API_BR');

    $trocado = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$trocado,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$trocado,'id_totem'=>$totem,'tipo'=>'cnh','resultado'=>qaResultado('crlv')]);
    qaAfirmar('tipo documental trocado e fail-closed', ($dao->buscarPorId($trocado)['cnh_origem_validacao'] ?? '') === 'NAO_VALIDADO');

    $incompleto = qaNovo($dao, $totem, 'exp_crlv');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$incompleto,'id_totem'=>$totem,'tipo'=>'crlv','imagem_qr_base64'=>$jpeg]);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$incompleto,'id_totem'=>$totem,'tipo'=>'crlv','resultado'=>qaResultado('crlv', ['UF'=>''])]);
    qaAfirmar('campo obrigatorio ausente (UF) e fail-closed', ($dao->buscarPorId($incompleto)['crlv_origem_validacao'] ?? '') === 'NAO_VALIDADO');

    $semTipo = qaNovo($dao, $totem, 'exp_crlv');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$semTipo,'id_totem'=>$totem,'tipo'=>'crlv','imagem_qr_base64'=>$jpeg]);
    $r = qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$semTipo,'id_totem'=>$totem,'tipo'=>'crlv','resultado'=>qaResultado('crlv', ['Tipo'=>''])]);
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    $rowSemTipo = $dao->buscarPorId($semTipo);
    qaAfirmar('Tipo ausente (decisao 2026-10-02): CRLV aprova, sem motivo_usuario, tipo e snapshot NULL', ($d['motivo_usuario'] ?? null) === null && ($rowSemTipo['crlv_origem_validacao'] ?? '') === 'VIO_API_BR' && $rowSemTipo['crlv_tipo_veiculo'] === null && $rowSemTipo['crlv_snapshot_tipo_veiculo'] === null);

    $tipoPh = qaNovo($dao, $totem, 'exp_crlv');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$tipoPh,'id_totem'=>$totem,'tipo'=>'crlv','imagem_qr_base64'=>$jpeg]);
    $r = qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$tipoPh,'id_totem'=>$totem,'tipo'=>'crlv','resultado'=>qaResultado('crlv', ['Tipo'=>'xxxxx'])]);
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    qaAfirmar('placeholder em Tipo reprova (dados_invalidos) e nao valida', ($d['motivo_usuario'] ?? null) === 'dados_invalidos' && ($dao->buscarPorId($tipoPh)['crlv_origem_validacao'] ?? '') === 'NAO_VALIDADO');

    $idor = qaNovo($dao, $totem, 'exp_cnh'); $antes = qaContador($pdo, 'posts');
    $r = qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$idor,'id_totem'=>$outroTotem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    qaAfirmar('IDOR nao chama VIO e resposta e sanitizada', ($r['body']['sucesso'] ?? true) === false && ($r['body']['erro'] ?? '') === 'Atendimento nao encontrado' && qaContador($pdo, 'posts') === $antes);

    $invalido = qaNovo($dao, $totem, 'exp_cnh'); $antes = qaContador($pdo, 'posts');
    $r = qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$invalido,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>'data:image/jpeg;base64,invalido']);
    qaAfirmar('JPEG/Base64 invalido da zero POST', ($r['body']['sucesso'] ?? true) === false && qaContador($pdo, 'posts') === $antes);

    $ambiguo = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$ambiguo,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg,'envio'=>'ambiguo']);
    qaAfirmar('ambiguidade vira INDETERMINADO sem retry', ($dao->buscarPorId($ambiguo)['cnh_status_processamento'] ?? '') === 'INDETERMINADO');

    $tardio = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$tardio,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    $pdo->prepare("UPDATE tb_atendimento SET status='cancelado' WHERE id_atendimento=:id")->execute(['id'=>$tardio]);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$tardio,'id_totem'=>$totem,'tipo'=>'cnh','resultado'=>qaResultado('cnh')]);
    qaAfirmar('cancelamento descarta resultado tardio', ($dao->buscarPorId($tardio)['cnh_origem_validacao'] ?? '') === 'NAO_VALIDADO');

    $timeout = qaNovo($dao, $totem, 'exp_cnh'); $tentativa = bin2hex(random_bytes(16));
    $dao->iniciarEnvioVioApiBr($timeout, 'cnh', $tentativa); $dao->gravarIdExternoVioApiBr($timeout, 'cnh', $tentativa, 'qa-timeout');
    $pdo->prepare('UPDATE tb_atendimento SET cnh_vio_api_enviado_em=DATE_SUB(NOW(), INTERVAL 121 SECOND) WHERE id_atendimento=:id')->execute(['id'=>$timeout]);
    $getsAntes = qaContador($pdo, 'gets'); qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$timeout,'id_totem'=>$totem,'tipo'=>'cnh']);
    qaAfirmar('timeout vira INDETERMINADO sem GET ou retry', ($dao->buscarPorId($timeout)['cnh_status_processamento'] ?? '') === 'INDETERMINADO' && qaContador($pdo, 'gets') === $getsAntes);

    // Cenarios de falha adicionais para a prova negativa de logs (sentinelas de PII "falha").
    $f = QA_S_FALHA;
    $resFalha = qaResultado('cnh', ['Nome' => $f['nome'], 'CPF' => $f['cpf']]);
    $lancaEnvio = qaNovo($dao, $totem, 'exp_cnh');
    $r = qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$lancaEnvio,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg,'lanca_envio'=>true]);
    qaAfirmar('VIO falso que lanca excecao no POST nao quebra o contrato de resposta', is_array($r['body']) && ($r['body']['sucesso'] ?? true) === false);
    $lancaConsulta = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$lancaConsulta,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    $r = qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$lancaConsulta,'id_totem'=>$totem,'tipo'=>'cnh','lanca_consulta'=>true,'imagem_qr_base64'=>$jpeg]);
    qaAfirmar('VIO falso que lanca excecao no GET fecha em ERRO sem aprovar', ($dao->buscarPorId($lancaConsulta)['cnh_origem_validacao'] ?? '') === 'NAO_VALIDADO' && is_array($r['body']));
    $erroVio = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$erroVio,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$erroVio,'id_totem'=>$totem,'tipo'=>'cnh','resultado'=>['ok'=>false,'erro'=>implode(' ', $f) . ' ' . QA_S_API_KEY,'dados_leitura'=>$resFalha['dados_leitura']]]);
    qaAfirmar('resposta VIO com erro nao aprova o documento', ($dao->buscarPorId($erroVio)['cnh_origem_validacao'] ?? '') === 'NAO_VALIDADO');
    $respInvalida = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$respInvalida,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$respInvalida,'id_totem'=>$totem,'tipo'=>'cnh','resultado'=>['ok'=>true,'estado_leitura'=>'estado_invalido_' . $f['nome'],'qr_type'=>'vio','dados_leitura'=>$resFalha['dados_leitura']]]);
    qaAfirmar('resposta VIO invalida nao aprova o documento', ($dao->buscarPorId($respInvalida)['cnh_origem_validacao'] ?? '') === 'NAO_VALIDADO');
    $trocadoFalha = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$trocadoFalha,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>$jpeg]);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$trocadoFalha,'id_totem'=>$totem,'tipo'=>'cnh','resultado'=>qaResultado('crlv', ['Placa'=>$f['placa'],'Renavam'=>$f['renavam'],'RNTRC'=>$f['rntrc']])]);
    qaAfirmar('tipo trocado com dados de falha nao aprova o documento', ($dao->buscarPorId($trocadoFalha)['cnh_origem_validacao'] ?? '') === 'NAO_VALIDADO');
    $placaDiv = qaNovo($dao, $totem, 'exp_crlv');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$placaDiv,'id_totem'=>$totem,'tipo'=>'crlv','imagem_qr_base64'=>$jpeg]);
    qaChamarWorker($banco, ['acao'=>'status','id_atendimento'=>$placaDiv,'id_totem'=>$totem,'tipo'=>'crlv','resultado'=>qaResultado('crlv', ['Placa'=>$f['placa'],'Renavam'=>$f['renavam'],'RNTRC'=>$f['rntrc']])]);
    qaAfirmar('CRLV com placa divergente nao aprova o documento', ($dao->buscarPorId($placaDiv)['crlv_origem_validacao'] ?? '') === 'NAO_VALIDADO');
    $invQr1 = qaNovo($dao, $totem, 'exp_cnh'); $invQr2 = qaNovo($dao, $totem, 'exp_crlv'); $antes = qaContador($pdo, 'posts');
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$invQr1,'id_totem'=>$totem,'tipo'=>'cnh','imagem_qr_base64'=>'data:image/jpeg;base64,' . QA_S_QR_INVALIDO]);
    qaChamarWorker($banco, ['acao'=>'iniciar','id_atendimento'=>$invQr2,'id_totem'=>$totem,'tipo'=>'crlv','imagem_qr_base64'=>'data:image/jpeg;base64,' . base64_encode(QA_S_QR_NAO_JPEG)]);
    qaAfirmar('Base64 invalido e bytes nao-JPEG nao geram POST', qaContador($pdo, 'posts') === $antes);

    // ---- motivo_usuario e re-escaneio de documento reprovado ----
    $ent = static fn(string $acao, int $id, string $tipo, array $extra = []) => ['acao'=>$acao,'id_atendimento'=>$id,'id_totem'=>$GLOBALS['totem'],'tipo'=>$tipo,'imagem_qr_base64'=>$GLOBALS['jpeg']] + $extra;
    $reprov = qaNovo($dao, $totem, 'exp_crlv');
    qaChamarWorker($banco, $ent('iniciar', $reprov, 'crlv'));
    $r = qaChamarWorker($banco, $ent('status', $reprov, 'crlv', ['resultado'=>qaResultado('crlv', ['Placa'=>$f['placa'],'Renavam'=>$f['renavam']])]));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    qaAfirmar('(a) placa divergente: terminal com motivo_usuario=placa_divergente e pode_avancar=false', ($d['terminal'] ?? false) === true && ($d['pode_avancar'] ?? true) === false && ($d['motivo_usuario'] ?? null) === 'placa_divergente');
    $corpo = json_encode($r['body']);
    qaAfirmar('(a) resposta sem placa lida/valor do documento/motivo_codigo', !str_contains($corpo, $f['placa']) && !str_contains($corpo, $f['renavam']) && !str_contains($corpo, 'motivo_codigo') && !str_contains($corpo, 'Placa do CRLV'));
    $tentativaAntiga = (string) $dao->buscarPorId($reprov)['crlv_tentativa_id'];
    $r = qaChamarWorker($banco, $ent('status', $reprov, 'crlv'));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    qaAfirmar('motivo_usuario vem apenas da chamada que efetivou o terminal (poll seguinte: null, sem GET)', array_key_exists('motivo_usuario', $d) && $d['motivo_usuario'] === null);

    $rntrcSem = qaNovo($dao, $totem, 'exp_crlv');
    qaChamarWorker($banco, $ent('iniciar', $rntrcSem, 'crlv'));
    $r = qaChamarWorker($banco, $ent('status', $rntrcSem, 'crlv', ['resultado'=>qaResultado('crlv', ['RNTRC'=>''])]));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    $rowSem = $dao->buscarPorId($rntrcSem);
    qaAfirmar('(b) RNTRC ausente (decisao 2026-10-02): CRLV aprova, sem motivo_usuario', ($d['motivo_usuario'] ?? null) === null && ($rowSem['crlv_origem_validacao'] ?? '') === 'VIO_API_BR' && $rowSem['crlv_rntc'] === null);
    $venc = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, $ent('iniciar', $venc, 'cnh'));
    $r = qaChamarWorker($banco, $ent('status', $venc, 'cnh', ['resultado'=>qaResultado('cnh', ['Validade'=>'2001-01-01'])]));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    qaAfirmar('CNH vencida: motivo_usuario=cnh_vencida', ($d['motivo_usuario'] ?? null) === 'cnh_vencida');
    $ileg = qaNovo($dao, $totem, 'exp_cnh');
    qaChamarWorker($banco, $ent('iniciar', $ileg, 'cnh'));
    $r = qaChamarWorker($banco, $ent('status', $ileg, 'cnh', ['resultado'=>['ok'=>true,'estado_leitura'=>'failed','qr_type'=>'vio','dados_leitura'=>[]]]));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    qaAfirmar('leitura failed: motivo_usuario=documento_ilegivel', ($d['motivo_usuario'] ?? null) === 'documento_ilegivel');
    $dadosInv = qaNovo($dao, $totem, 'exp_crlv');
    qaChamarWorker($banco, $ent('iniciar', $dadosInv, 'crlv'));
    $r = qaChamarWorker($banco, $ent('status', $dadosInv, 'crlv', ['resultado'=>qaResultado('crlv', ['UF'=>'XX'])]));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    qaAfirmar('UF invalida: motivo_usuario=dados_invalidos', ($d['motivo_usuario'] ?? null) === 'dados_invalidos');
    $r = qaChamarWorker($banco, $ent('status', $cnh, 'cnh'));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    qaAfirmar('documento aprovado: motivo_usuario=null', array_key_exists('motivo_usuario', $d) && $d['motivo_usuario'] === null && ($d['pode_avancar'] ?? false) === true);

    // (c) re-escaneio: duas chamadas CONCORRENTES sobre o reprovado -> exatamente 1 POST adicional.
    $antes = qaContador($pdo, 'posts');
    $cmds = []; $procs = []; $pipesTodos = [];
    foreach ([1, 2] as $i) {
        $cmd = [PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log="' . $qaLogArquivo . '"', __FILE__, '--worker', $banco, base64_encode(json_encode($ent('iniciar', $reprov, 'crlv'), JSON_THROW_ON_ERROR))];
        $pp = []; $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, null, ['bypass_shell' => true]); $pipesTodos[$i] = $pp;
    }
    foreach ($procs as $i => $p) { $qaSaidas[] = stream_get_contents($pipesTodos[$i][1]); $qaSaidas[] = stream_get_contents($pipesTodos[$i][2]); fclose($pipesTodos[$i][1]); fclose($pipesTodos[$i][2]); proc_close($p); }
    $at = $dao->buscarPorId($reprov);
    qaAfirmar('(c) re-escaneio concorrente faz exatamente 1 POST adicional', qaContador($pdo, 'posts') === $antes + 1);
    qaAfirmar('(c) re-escaneio gera tentativa_id nova e estado em processamento', (string) $at['crlv_tentativa_id'] !== $tentativaAntiga && in_array($at['crlv_status_processamento'], ['PROCESSANDO_LEITURA', 'ENVIANDO'], true));
    qaAfirmar('(c) resultado tardio da tentativa anterior e descartado', $dao->gravarResultadoFinalVioApiBr($reprov, 'crlv', $tentativaAntiga, 'CONCLUIDO') === false && $dao->buscarPorId($reprov)['crlv_status_processamento'] === 'PROCESSANDO_LEITURA');
    // (d) iniciar duplicado durante processamento nao faz 2o POST.
    $antes = qaContador($pdo, 'posts');
    qaChamarWorker($banco, $ent('iniciar', $reprov, 'crlv'));
    qaChamarWorker($banco, $ent('iniciar', $reprov, 'crlv'));
    qaAfirmar('(d) iniciar durante PROCESSANDO_LEITURA nao faz 2o POST', qaContador($pdo, 'posts') === $antes);
    // Estados ENVIANDO/INDETERMINADO tambem nunca reabrem.
    foreach (['ENVIANDO', 'INDETERMINADO'] as $estado) {
        $pdo->prepare('UPDATE tb_atendimento SET crlv_status_processamento=:s WHERE id_atendimento=:id')->execute(['s'=>$estado,'id'=>$reprov]);
        qaChamarWorker($banco, $ent('iniciar', $reprov, 'crlv'));
        qaAfirmar("(d) iniciar em {$estado} nao faz POST", qaContador($pdo, 'posts') === $antes);
    }
    $pdo->prepare("UPDATE tb_atendimento SET crlv_status_processamento='PROCESSANDO_LEITURA' WHERE id_atendimento=:id")->execute(['id'=>$reprov]);
    // Com dados corretos, o re-escaneio aprova.
    $r = qaChamarWorker($banco, $ent('status', $reprov, 'crlv', ['resultado'=>qaResultado('crlv')]));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    $at = $dao->buscarPorId($reprov);
    qaAfirmar('(c) re-escaneio com dados corretos aprova (VIO_API_BR, pode_avancar, motivo_usuario=null)', $at['crlv_origem_validacao'] === 'VIO_API_BR' && ($d['pode_avancar'] ?? false) === true && array_key_exists('motivo_usuario', $d) && $d['motivo_usuario'] === null);
    // (e) aprovado: novo iniciar e idempotente, sem POST.
    $antes = qaContador($pdo, 'posts'); $tentAprov = (string) $at['crlv_tentativa_id'];
    $r = qaChamarWorker($banco, $ent('iniciar', $reprov, 'crlv'));
    $d = $r['body']['dados'] ?? $r['body'] ?? [];
    qaAfirmar('(e) iniciar apos aprovado e idempotente: sem POST, sem trocar tentativa, pode_avancar', qaContador($pdo, 'posts') === $antes && (string) $dao->buscarPorId($reprov)['crlv_tentativa_id'] === $tentAprov && ($d['pode_avancar'] ?? false) === true);
    // Por documento: CAS direto nao reabre aprovado nem outro documento.
    qaAfirmar('CAS nao reabre documento aprovado', $dao->iniciarEnvioVioApiBr($cnh, 'cnh', bin2hex(random_bytes(16))) === false);

    $talentId = qaNovo($dao, $totem, 'exp_confirmacao');
    $pdo->prepare("UPDATE tb_atendimento SET ordem_coleta='QA-OC',cliente_cnpj='11222333000181',motorista_nome='QA MOTORISTA',motorista_cpf='52998224725',crlv_uf='SP',crlv_rntc='QA',crlv_tipo_veiculo='CAMINHAO' WHERE id_atendimento=:id")->execute(['id'=>$talentId]);
    $talentClient = new QaQrTalentFalso();
    (new TalentRn($talentClient, new FilaEnvioDao($pdo), $dao, 'qa-nao-existe'))->processarCheckin($dao->buscarPorId($talentId), ['cnpj'=>'11222333000181'], []);
    $payload = $talentClient->payloads[0] ?? [];
    qaAfirmar('Talent falso preserva doctos[] e remove anexos CNH/CRLV', isset($payload['doctos'], $payload['anexos']) && count($payload['anexos']) === 0);
    qaAfirmar('zero cache, PDF, temporario e storage QR-only', (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_cnh')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_crlv')->fetchColumn() === 0 && !is_dir(dirname(__DIR__, 2) . '/storage/qa_qr_exclusivo'));
    // ---- Prova negativa de logs: log PHP capturado, respostas HTTP e tabelas do banco QA ----
    $b64 = substr($jpeg, strlen('data:image/jpeg;base64,'));
    $sentQr = ['qr_data_url' => $jpeg, 'qr_base64' => $b64, 'qr_base64_trecho' => substr($b64, 40, 80), 'qr_invalido' => QA_S_QR_INVALIDO, 'qr_nao_jpeg' => QA_S_QR_NAO_JPEG, 'qr_nao_jpeg_b64' => base64_encode(QA_S_QR_NAO_JPEG), 'api_key' => QA_S_API_KEY];
    $rotulos = ['cpf' => 'CPF', 'cpf_fmt' => 'CPF', 'nome' => 'nome', 'placa' => 'placa', 'renavam' => 'Renavam', 'rntrc' => 'RNTRC'];
    $sentOk = []; $sentFalha = [];
    foreach (QA_S_OK as $k => $v) $sentOk["ok_{$k}"] = $v;
    foreach (QA_S_FALHA as $k => $v) $sentFalha["falha_{$k}"] = $v;
    $achar = static function (string $texto, array $sents): array { $achados = []; foreach ($sents as $nome => $valor) if ($valor !== '' && stripos($texto, $valor) !== false) $achados[] = $nome; return $achados; };
    $log = is_file($qaLogArquivo) ? (string) file_get_contents($qaLogArquivo) : '';
    $http = implode("\n", $qaSaidas);
    qaAfirmar('controle positivo: error_log capturado em arquivo temporario com falha tecnica registrada', $log !== '' && str_contains($log, 'RuntimeException'));
    // Observabilidade de reprovacao VIO: codigo de allowlist no log, sem valores.
    $linhaDiv = preg_match('/\[DocumentoController\] vio_reprovado id=' . $placaDiv . ' tipo=crlv estado_leitura=completed qr_type=vio motivo=placa_divergente( chaves_vio_result=.*)?$/m', $log, $mDiv) === 1;
    $linhaRntrc = preg_match('/\[DocumentoController\] vio_reprovado id=' . $incompleto . ' tipo=crlv estado_leitura=completed qr_type=vio motivo=uf_invalida( chaves_vio_result=.*)?$/m', $log, $mRn) === 1;
    qaAfirmar('log vio_reprovado traz motivo=placa_divergente para CRLV com placa divergente', $linhaDiv);
    qaAfirmar('log vio_reprovado traz motivo=uf_invalida para CRLV sem UF', $linhaRntrc);
    qaAfirmar('log vio_reprovado lista apenas NOMES de chaves (Placa, UF, RNTRC), sem valores', $linhaDiv && str_contains($mDiv[1] ?? '', 'chaves_vio_result=Placa,Exerc') && str_contains($mDiv[1], 'RNTRC'));
    qaAfirmar('motivo_codigo nao vaza na resposta HTTP do controller', !str_contains($http ?? implode("
", $qaSaidas), 'motivo_codigo'));
    foreach ([['log PHP', $log], ['respostas HTTP/stderr', $http]] as [$alvo, $texto]) {
        $v = $achar($texto, $sentQr + $sentOk + $sentFalha);
        qaAfirmar("{$alvo}: nenhuma sentinela de QR/Base64/API key/CPF/placa/Renavam/RNTRC/nome" . ($v ? ' [VAZOU: ' . implode(',', $v) . ']' : ''), $v === []);
    }
    $vazDb = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabela) {
        if (!preg_match('/\A[a-z0-9_]+\z/', (string) $tabela)) continue;
        $linhas = $pdo->query("SELECT * FROM `{$tabela}`")->fetchAll(PDO::FETCH_NUM);
        $texto = implode("\n", array_map(static fn(array $l) => implode("\x1f", array_map(static fn($c) => (string) $c, $l)), $linhas));
        // PII de sucesso persiste legitimamente so em tb_atendimento (documento validado).
        $sents = $sentQr + $sentFalha + ($tabela === 'tb_atendimento' ? [] : $sentOk);
        foreach ($achar($texto, $sents) as $s) $vazDb[] = "{$tabela}:{$s}";
    }
    qaAfirmar('tabelas do banco QA: nenhuma sentinela de QR/Base64/API key/PII de falha (PII de sucesso so em tb_atendimento)' . ($vazDb ? ' [VAZOU: ' . implode(',', $vazDb) . ']' : ''), $vazDb === []);

    $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controller/DocumentoController.php');
    qaAfirmar('request nao possui mecanismo de injetar mock', !str_contains($fonte, "['vioFactory']") && !str_contains($fonte, "['mock_vio']"));
} catch (Throwable $e) {
    fwrite(STDERR, 'BLOQUEADO: integracao QA nao pode executar com seguranca [' . get_class($e) . ':' . (int) $e->getCode() . "].\n"); ++$falhas;
} finally {
    if (is_file($qaLogArquivo)) @unlink($qaLogArquivo);
    if ($banco !== null) {
        try { qaQrDroparBanco($banco); echo "Banco QA removido.\n"; } catch (Throwable) { fwrite(STDERR, "FALHA: banco QA nao removido.\n"); ++$falhas; }
    }
}
echo "Verificacoes: {$total}; Falhas: {$falhas}\n";
exit($falhas === 0 ? 0 : 1);
