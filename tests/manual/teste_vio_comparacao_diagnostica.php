<?php
/** Matriz QR-only: compare diagnostico, sem cache e sem paginas locais. */
declare(strict_types=1);
require_once __DIR__ . '/qa_qr_exclusivo_bootstrap.php';

use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use App\Rn\DocumentoRn;

$total = $falhas = 0;
function diagAfirmar(string $nome, bool $ok): void { global $total, $falhas; ++$total; if (!$ok) { ++$falhas; } echo ($ok ? 'OK   ' : 'FALHA') . " - {$nome}\n"; }
function diagCnh(array $extra = []): array { return array_replace([
    'ok'=>true, 'ambiguo'=>false, 'nao_encontrado'=>false, 'estado_leitura'=>'completed', 'qr_type'=>'vio',
    'dados_leitura'=>['Nome'=>'MOTORISTA QA','CPF'=>'111.444.777-35','Validade'=>'31/12/2035'],
    'estado_comparacao'=>'completed', 'comparacao'=>['summary'=>['reliable'=>true,'mismatched'=>0],'campos'=>[]],
    'pages_processed'=>null, 'total_pages'=>null,
], $extra); }
function diagCrlv(array $extra = []): array { return array_replace([
    'ok'=>true, 'ambiguo'=>false, 'nao_encontrado'=>false, 'estado_leitura'=>'completed', 'qr_type'=>'vio',
    'dados_leitura'=>['Placa'=>'DIA1234','Exercício'=>2026,'UF'=>'SP','RNTRC'=>'12345678','Tipo'=>'CAMINHAO','Renavam'=>'98765432100'],
    'estado_comparacao'=>'completed', 'comparacao'=>['summary'=>['reliable'=>true,'mismatched'=>0],'campos'=>[]],
    'pages_processed'=>null, 'total_pages'=>null,
], $extra); }

try { [$pdo, $banco] = qaQrCriarBanco(); } catch (RuntimeException $e) { fwrite(STDERR, "BLOQUEADO: configuracao QA explicita indisponivel.\n"); exit(2); }
try {
    $_ENV['DOCUMENTO_DATA_KEY'] = bin2hex(random_bytes(32));
    $dao = new AtendimentoDao($pdo);
    $rn = new DocumentoRn(new VioCacheDao($pdo), $dao);
    $pdo->exec("INSERT INTO tb_totem (codigo,nome,token_api,ativo) VALUES ('QA_DIAG','QA','qa-diag',1)"); $totem=(int)$pdo->lastInsertId();
    $novo = static function(string $doc) use ($dao,$totem): array {
        $id=$dao->criar($totem,'expedicao',$doc === 'crlv' ? 'DIA1234' : 'CNH1234');
        $dao->atualizarEtapa($id,$doc === 'crlv' ? 'exp_crlv' : 'exp_cnh');
        $t=bin2hex(random_bytes(16)); $dao->iniciarEnvioVioApiBr($id,$doc,$t); $dao->gravarIdExternoVioApiBr($id,$doc,$t,'qa-'.bin2hex(random_bytes(4)));
        return $dao->buscarPorId($id);
    };
    foreach (['mismatch'=>['summary'=>['reliable'=>true,'mismatched'=>1]], 'not_found'=>['campos'=>['cpf'=>'not_found']], 'reliable_false'=>['summary'=>['reliable'=>false,'score'=>0]], 'pending'=>['status'=>'pending'], 'failed'=>['status'=>'failed'], 'ausente'=>[]] as $nome=>$compare) {
        $at=$novo('cnh'); diagAfirmar("CNH aprova com compare {$nome}", $rn->avaliarResultadoVioApiBrCnh($at,diagCnh(['comparacao'=>$compare,'estado_comparacao'=>$nome==='ausente'?null:'completed']))['pode_avancar'] === true);
        $at=$novo('crlv'); diagAfirmar("CRLV aprova com compare {$nome}", $rn->avaliarResultadoVioApiBrCrlv($at,diagCrlv(['comparacao'=>$compare,'estado_comparacao'=>$nome==='ausente'?null:'completed']))['pode_avancar'] === true);
    }
    $at=$novo('cnh'); diagAfirmar('CNH QR-only ignora pages legadas', $rn->avaliarResultadoVioApiBrCnh($at,diagCnh(['pages_processed'=>2,'total_pages'=>2]))['pode_avancar'] === true);
    $at=$novo('cnh'); diagAfirmar('CNH CPF invalido bloqueia', $rn->avaliarResultadoVioApiBrCnh($at,diagCnh(['dados_leitura'=>['Nome'=>'QA','CPF'=>'000.000.000-00','Validade'=>'31/12/2035']]))['pode_avancar'] === false);
    $at=$novo('cnh'); diagAfirmar('QR normal bloqueia CNH', $rn->avaliarResultadoVioApiBrCnh($at,diagCnh(['qr_type'=>'normal']))['pode_avancar'] === false);
    $at=$novo('crlv'); diagAfirmar('RNTRC ausente bloqueia CRLV', $rn->avaliarResultadoVioApiBrCrlv($at,diagCrlv(['dados_leitura'=>['Placa'=>'DIA1234','Exercício'=>2026,'UF'=>'SP','RNTRC'=>'','Tipo'=>'CAMINHAO','Renavam'=>'98765432100']]))['pode_avancar'] === false);
    $at=$novo('crlv'); diagAfirmar('Tipo CNH bloqueia CRLV', $rn->avaliarResultadoVioApiBrCrlv($at,diagCnh())['pode_avancar'] === false);
    diagAfirmar('QR-only nao gravou cache CNH/CRLV', (int)$pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_cnh')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM tb_vio_api_cache_crlv')->fetchColumn() === 0);
} finally { qaQrDroparBanco($banco); }
echo "=== RESULTADO: {$total} testes, ".($total-$falhas)." passaram, {$falhas} falharam ===\n";
exit($falhas ? 1 : 0);
