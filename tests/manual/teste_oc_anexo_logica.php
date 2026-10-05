<?php

/**
 * Teste (demanda anexo-ordem-coleta-n8n, 2026-10-05) da LOGICA do anexo da
 * Ordem de Coleta, em processo, contra banco QA descartavel
 * `qa_qr_exclusivo_<hex>` (que tambem faz o papel do banco externo de gestao
 * de coletas, com as migrations REAIS 001/002/003) e storage temporario.
 * Nunca toca em udlog_totem nem no banco externo real; sem rede, sem Talent
 * real, sem impressao.
 *
 * Cobre: Storage (nome/pasta/traversal/symlink/leitura/remocao), Rn::receber
 * (limites, base64, PDF, sobrescrita, duplicado, falha de transacao),
 * AuthServidor (HTTPS, chave, 401/503/429), retencao (regras a/b) e cron,
 * marcarInativaPorNumero, leitor + TalentRn (payload so Expedicao, falhas
 * seguem sem anexo, nunca ERRO_REPROCESSAVEL), concorrencia basica e prova
 * negativa de vazamento nos logs.
 *
 * Uso: php tests/manual/teste_oc_anexo_logica.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_oc_anexo_infra.php';
require_once __DIR__ . '/_fixtures_talent.php';

use App\Dao\AtendimentoDao;
use App\Dao\CaminhoJaRegistradoException;
use App\Dao\FilaEnvioDao;
use App\Dao\OrdemColetaArquivoDao;
use App\Dao\OrdemColetaDao;
use App\Rn\AnexoOrdemColetaLeitor;
use App\Rn\OrdemColetaArquivoRn;
use App\Rn\TalentClient;
use App\Rn\TalentRn;
use Util\AuthServidor;
use Util\OrdemColetaArquivoStorage;

$total = 0;
$falhas = 0;
function afirmar(string $d, bool $c): void
{
    global $total, $falhas;
    $total++;
    echo ($c ? 'OK   - ' : 'FALHA - ') . $d . "\n";
    if (!$c) {
        $falhas++;
    }
}

class DaoIndisponivel extends OrdemColetaArquivoDao
{
    protected function pdo(): PDO
    {
        throw new RuntimeException('Nao foi possivel conectar ao banco de ordens de coleta');
    }
}

class DaoFalhaNaTransacao extends OrdemColetaArquivoDao
{
    public function substituir(string $cnpj, string $numero, string $caminhoRelativo, int $tamanho, string $sha256): array
    {
        throw new PDOException('falha simulada SEGREDO-NUMERO-' . $numero);
    }
}

class TalentClientCaptura extends TalentClient
{
    public array $ultimo = [];
    public int $chamadas = 0;

    public function __construct()
    {
        parent::__construct('', '');
    }

    public function checkin(array $payload): array
    {
        $this->ultimo = $payload;
        $this->chamadas++;

        return ['senha' => 'S-QA', 'protocolo' => 'P-QA'];
    }
}

class LeitorEspiao extends AnexoOrdemColetaLeitor
{
    public int $chamadas = 0;

    public function buscar(string $cnpj, string $numero): array
    {
        $this->chamadas++;

        return parent::buscar($cnpj, $numero);
    }
}

/** Esvazia ordens_coleta/ (arquivos, inclusive ocultos, e subpastas); mantem a raiz. */
function limparRaiz(string $raiz): void
{
    foreach (new DirectoryIterator($raiz) as $e) {
        if ($e->isDot()) {
            continue;
        }
        if ($e->isDir() && !$e->isLink()) {
            foreach (new DirectoryIterator($e->getPathname()) as $f) {
                if (!$f->isDot()) {
                    @unlink($f->getPathname());
                }
            }
            @rmdir($e->getPathname());
        } else {
            @unlink($e->getPathname());
        }
    }
}

function rnPdf(string $marca = 'x', int $tamanho = 800): string
{
    return ocQaPdf($tamanho, $marca);
}

function receber(OrdemColetaArquivoRn $rn, string $cnpj, mixed $numero, string $bytes, array $extra = []): array
{
    return $rn->receber(array_merge(['cnpj_cliente' => $cnpj, 'numero_ordem_coleta' => $numero, 'arquivo_base64' => base64_encode($bytes)], $extra));
}

$banco = null;
$storage = null;
$logArquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_oc_log_' . bin2hex(random_bytes(4)) . '.log';
$logAnterior = ini_set('error_log', $logArquivo);

try {
    [$pdo, $banco, $storage] = ocQaCriarAmbiente();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    afirmar('ambiente QA e banco QA (nunca udlog_totem)', preg_match('/\Aqa_qr_exclusivo_[a-f0-9]{8}\z/', $banco) === 1 && $banco !== 'udlog_totem');
    afirmar('migrations 001/002/003 aplicadas (colunas status, inativada_em e tabela de arquivos)', (int) $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='tb_ordens_coleta' AND COLUMN_NAME IN ('status','inativada_em')) OR TABLE_NAME='tb_ordem_coleta_arquivos')")->fetchColumn() >= 10);
    // migrations idempotentes: reaplicar nao falha nem altera nada
    $mig = dirname(__DIR__, 2) . '/sql/migrations_gestao_coletas/';
    $semErro = true;
    try {
        ocQaAplicarMigration($pdo, $mig . '002_tb_ordem_coleta_arquivos.sql');
        ocQaAplicarMigration($pdo, $mig . '003_inativada_em_ordem_coleta.sql');
    } catch (Throwable $e) {
        $semErro = false;
    }
    afirmar('migrations 002/003 idempotentes (reaplicar nao falha)', $semErro);

    $dao = new OrdemColetaArquivoDao($pdo);
    $relogioFixo = new DateTimeImmutable('2026-10-05 10:00:00', new DateTimeZone('America/Sao_Paulo'));
    $st = new OrdemColetaArquivoStorage($storage, static fn () => $relogioFixo);
    $rn = new OrdemColetaArquivoRn($dao, new OrdemColetaArquivoStorage($storage), 5242880);
    $raizOc = $storage . DIRECTORY_SEPARATOR . 'ordens_coleta';
    $cnpjA = '11222333000181';
    $cnpjB = '22333444000199';

    // ======================= STORAGE =======================
    $rel = $st->gravar($cnpjA, '1234', rnPdf('a'));
    afirmar('storage: nome <numero>_<AAAAMMDDHHMMSS>.pdf em <cnpj>/ (fuso America/Sao_Paulo fixado)', $rel === $cnpjA . '/1234_20261005100000.pdf');
    afirmar('storage: arquivo gravado com o conteudo exato', is_file($raizOc . '/' . $cnpjA . '/1234_20261005100000.pdf') && file_get_contents($raizOc . '/' . $cnpjA . '/1234_20261005100000.pdf') === rnPdf('a'));
    if (DIRECTORY_SEPARATOR === '/') {
        afirmar('storage: pasta 0750 e arquivo 0640', (fileperms($raizOc . '/' . $cnpjA) & 0777) === 0750 && (fileperms($raizOc . '/' . $rel) & 0777) === 0640);
    }
    afirmar('storage: nenhum .tmp residual', count(array_filter(ocQaArquivos($raizOc), static fn ($a) => str_contains($a, '.tmp_'))) === 0);

    $rel2 = $st->gravar($cnpjA, '1234', rnPdf('b'));
    afirmar('storage: mesmo segundo + mesmo numero NAO sobrescreve (nome desloca 1 s)', $rel2 !== $rel && $rel2 === $cnpjA . '/1234_20261005100001.pdf' && file_get_contents($raizOc . '/' . $rel) === rnPdf('a') && file_get_contents($raizOc . '/' . $rel2) === rnPdf('b'));

    $hostis = ['../../etc/passwd', '..\\..\\windows\\x', 'A/B\\C', "linha\nnova", 'OC-Ação-9', '....', '.', str_repeat('Z', 50), 'a b;c|d&e$f', '日本語'];
    $antes = ocQaArquivos($storage);
    $todosValidos = true;
    $dentroDaRaiz = true;
    $raizReal = realpath($raizOc);
    foreach ($hostis as $h) {
        try {
            $r = $st->gravar($cnpjB, $h, rnPdf('h'));
        } catch (Throwable $e) {
            $todosValidos = false;
            continue;
        }
        $todosValidos = $todosValidos && OrdemColetaArquivoStorage::caminhoRelativoValido($r);
        $abs = $st->caminhoAbsoluto($r);
        $dentroDaRaiz = $dentroDaRaiz && $abs !== null && is_file($abs) && str_starts_with(realpath($abs), $raizReal . DIRECTORY_SEPARATOR . $cnpjB . DIRECTORY_SEPARATOR);
        usleep(1);
    }
    afirmar('storage: numeros hostis (../, \\, \\n, acentos, CJK) geram nome so [A-Za-z0-9._-] valido', $todosValidos);
    afirmar('storage: numeros hostis gravam SEMPRE dentro de ordens_coleta/<cnpj>/ (sem traversal)', $dentroDaRaiz);
    $depois = ocQaArquivos($storage);
    $fora = array_filter(array_diff($depois, $antes), static fn ($a) => !str_starts_with($a, 'ordens_coleta/' . $cnpjB . '/'));
    afirmar('storage: nenhum arquivo criado fora da pasta do cliente', count($fora) === 0);

    foreach (['', 'abc', '../x', $cnpjA . '/../' . $cnpjB . '/a_20260101000000.pdf', $cnpjA . '/a_20260101000000.pdf' . "\n", $cnpjA . "/a\0_20260101000000.pdf", $cnpjA . '/a_20260101000000.PDF', '1234567890123/a_20260101000000.pdf', '/' . $cnpjA . '/a_20260101000000.pdf', $cnpjA . '\\a_20260101000000.pdf', $cnpjA . '/a/b_20260101000000.pdf', $cnpjA . '/' . str_repeat('a', 61) . '_20260101000000.pdf', 'C:/' . $cnpjA . '/a_20260101000000.pdf', null, 5] as $ruim) {
        $ok = !OrdemColetaArquivoStorage::caminhoRelativoValido($ruim) && $st->caminhoAbsoluto($ruim) === null && $st->remover(is_string($ruim) ? $ruim : '') === 'invalido';
        afirmar('storage: caminho hostil rejeitado/nao removido: ' . json_encode(is_string($ruim) ? substr($ruim, 0, 24) : $ruim), $ok);
    }

    // leitura segura
    $relOk = $st->gravar($cnpjB, 'LER-1', rnPdf('ler'));
    $shaOk = hash('sha256', rnPdf('ler'));
    $l = $st->ler($relOk, 5242880, $shaOk);
    afirmar('storage.ler: arquivo integro devolve bytes', $l['bytes'] === rnPdf('ler') && $l['motivo'] === null);
    afirmar('storage.ler: sha256 divergente => motivo sha256_divergente', $st->ler($relOk, 5242880, str_repeat('0', 64))['motivo'] === 'sha256_divergente');
    afirmar('storage.ler: sha256 malformado rejeitado', $st->ler($relOk, 5242880, 'xyz')['motivo'] === 'sha256_divergente');
    afirmar('storage.ler: tamanho acima do limite => tamanho_invalido', $st->ler($relOk, 10, $shaOk)['motivo'] === 'tamanho_invalido');
    afirmar('storage.ler: arquivo ausente => arquivo_ausente', $st->ler($cnpjB . '/nao-existe_20260101000000.pdf', 5242880, $shaOk)['motivo'] === 'arquivo_ausente');
    afirmar('storage.ler: caminho invalido => caminho_invalido', $st->ler('../x', 5242880, $shaOk)['motivo'] === 'caminho_invalido');
    file_put_contents($raizOc . '/' . $relOk, 'NAO E PDF ' . str_repeat('x', 100));
    afirmar('storage.ler: conteudo sem %PDF- => nao_e_pdf', $st->ler($relOk, 5242880, $shaOk)['motivo'] === 'nao_e_pdf');
    file_put_contents($raizOc . '/' . $relOk, rnPdf('CORROMPIDO'));
    afirmar('storage.ler: conteudo alterado => sha256_divergente', $st->ler($relOk, 5242880, $shaOk)['motivo'] === 'sha256_divergente');

    // symlink (pode exigir privilegio no Windows: skip explicito se nao criar)
    $alvoFora = $storage . DIRECTORY_SEPARATOR . 'fora_da_raiz';
    mkdir($alvoFora, 0750);
    file_put_contents($alvoFora . '/segredo_20260101000000.pdf', rnPdf('segredo'));
    $linkDir = $raizOc . DIRECTORY_SEPARATOR . '99999999999999';
    $symOk = @symlink($alvoFora, $linkDir);
    $tipoLink = 'symlink';
    if (!$symOk && DIRECTORY_SEPARATOR === '\\') {
        // Windows sem privilegio de symlink: juncao de diretorio (mklink /J), que is_link() NAO detecta
        @exec('cmd /c mklink /J ' . escapeshellarg($linkDir) . ' ' . escapeshellarg($alvoFora) . ' 2>&1', $saidaLink, $codLink);
        $symOk = ($codLink ?? 1) === 0 && is_dir($linkDir);
        $tipoLink = 'juncao';
    }
    if ($symOk) {
        afirmar("storage: pasta de cliente que e $tipoLink e rejeitada (ler/remover)", $st->caminhoAbsoluto('99999999999999/segredo_20260101000000.pdf') === null && $st->remover('99999999999999/segredo_20260101000000.pdf') === 'invalido' && is_file($alvoFora . '/segredo_20260101000000.pdf'));
        $threw = false;
        try {
            $st->gravar('99999999999999', 'X', rnPdf('x'));
        } catch (Throwable $e) {
            $threw = true;
        }
        afirmar("storage.gravar: pasta $tipoLink => excecao e nada escrito fora", $threw && ocQaArquivos($alvoFora) === ['segredo_20260101000000.pdf']);
        @unlink($linkDir);
        @rmdir($linkDir);
    } else {
        echo "SKIP - symlink de diretorio indisponivel neste ambiente (sem privilegio)\n";
    }
    $linkArq = $raizOc . DIRECTORY_SEPARATOR . $cnpjB . DIRECTORY_SEPARATOR . 'link_20260101000000.pdf';
    if (@symlink($alvoFora . '/segredo_20260101000000.pdf', $linkArq)) {
        afirmar('storage: arquivo symlink rejeitado (ler/remover) e alvo preservado', $st->ler($cnpjB . '/link_20260101000000.pdf', 5242880, hash('sha256', rnPdf('segredo')))['bytes'] === null && $st->remover($cnpjB . '/link_20260101000000.pdf') === 'invalido' && is_file($alvoFora . '/segredo_20260101000000.pdf'));
        @unlink($linkArq);
    } else {
        echo "SKIP - symlink de arquivo indisponivel neste ambiente\n";
    }

    afirmar('storage.remover: removido, depois ausente', $st->remover($rel) === 'removido' && $st->remover($rel) === 'ausente');
    $stSemRaiz = new OrdemColetaArquivoStorage($storage . DIRECTORY_SEPARATOR . 'nao_existe');
    $threw = false;
    try {
        $stSemRaiz->gravar($cnpjA, '1', rnPdf());
    } catch (Throwable $e) {
        $threw = $e->getMessage() === 'storage_indisponivel';
    }
    afirmar('storage.gravar: STORAGE_PATH inexistente => storage_indisponivel', $threw);

    // limpa a area para os proximos blocos
    limparRaiz($raizOc);

    // ======================= Rn::receber =======================
    $r = receber($rn, $cnpjA, '  OC-1  ', rnPdf('v1'));
    $linha = $pdo->query("SELECT * FROM tb_ordem_coleta_arquivos WHERE cnpj_cliente='$cnpjA'")->fetch();
    afirmar('receber: primeiro envio => 201 criado, numero normalizado (trim) no banco', $r['http'] === 201 && $r['dados']['status'] === 'criado' && $linha['numero_ordem_coleta'] === 'OC-1');
    afirmar('receber: banco guarda so caminho relativo, tamanho e sha256 (sem PDF)', $linha['caminho_relativo'] === $cnpjA . '/OC-1_' . substr($linha['caminho_relativo'], -18, 14) . '.pdf' && (int) $linha['tamanho_bytes'] === strlen(rnPdf('v1')) && $linha['sha256'] === hash('sha256', rnPdf('v1')));
    afirmar('receber: arquivo existe no disco', is_file($raizOc . '/' . $linha['caminho_relativo']));
    $mtime1 = filemtime($raizOc . '/' . $linha['caminho_relativo']);

    $r = receber($rn, $cnpjA, 'OC-1', rnPdf('v1'));
    afirmar('receber: mesmo conteudo => 200 duplicado, sem regravar (1 linha, 1 arquivo, mesmo caminho)', $r['http'] === 200 && $r['dados']['status'] === 'duplicado' && (int) $pdo->query('SELECT COUNT(*) FROM tb_ordem_coleta_arquivos')->fetchColumn() === 1 && count(ocQaArquivos($raizOc)) === 1 && filemtime($raizOc . '/' . $linha['caminho_relativo']) === $mtime1 && $pdo->query("SELECT caminho_relativo FROM tb_ordem_coleta_arquivos")->fetchColumn() === $linha['caminho_relativo']);

    sleep(1); // garante timestamp de nome diferente
    $r = receber($rn, $cnpjA, 'oc-1', rnPdf('v2')); // ci: mesma chave logica (collation unicode_ci)
    $linha2 = $pdo->query("SELECT * FROM tb_ordem_coleta_arquivos")->fetch();
    afirmar('receber: conteudo diferente => 201 atualizado (sobrescreve; UMA linha)', $r['http'] === 201 && $r['dados']['status'] === 'atualizado' && (int) $pdo->query('SELECT COUNT(*) FROM tb_ordem_coleta_arquivos')->fetchColumn() === 1);
    afirmar('receber: arquivo ANTIGO apagado depois do commit, so o novo permanece', !is_file($raizOc . '/' . $linha['caminho_relativo']) && is_file($raizOc . '/' . $linha2['caminho_relativo']) && count(ocQaArquivos($raizOc)) === 1 && $linha2['caminho_relativo'] !== $linha['caminho_relativo'] && $linha2['sha256'] === hash('sha256', rnPdf('v2')));

    // arquivo sumiu do disco + reenvio identico => repara (regrava), nao fica "duplicado" falso
    unlink($raizOc . '/' . $linha2['caminho_relativo']);
    sleep(1);
    $r = receber($rn, $cnpjA, 'OC-1', rnPdf('v2'));
    $linha3 = $pdo->query("SELECT * FROM tb_ordem_coleta_arquivos")->fetch();
    afirmar('receber: reenvio identico com arquivo ausente no disco => regrava (201 atualizado)', $r['http'] === 201 && is_file($raizOc . '/' . $linha3['caminho_relativo']) && count(ocQaArquivos($raizOc)) === 1);

    // falha de transacao: preserva o antigo e remove o novo
    $rnFalha = new OrdemColetaArquivoRn(new DaoFalhaNaTransacao($pdo), new OrdemColetaArquivoStorage($storage), 5242880);
    $antesArqs = ocQaArquivos($raizOc);
    $r = receber($rnFalha, $cnpjA, 'OC-1', rnPdf('v3-falha'));
    $linha4 = $pdo->query("SELECT * FROM tb_ordem_coleta_arquivos")->fetch();
    afirmar('receber: falha da transacao => 503 INDISPONIVEL', $r['http'] === 503 && $r['codigo'] === 'INDISPONIVEL');
    afirmar('receber: falha da transacao preserva registro e arquivo ANTIGOS e remove o NOVO', $linha4 === $linha3 && ocQaArquivos($raizOc) === $antesArqs);
    // falha REAL do banco dentro da transacao (UNIQUE do caminho ja usado por outra chave => 1062 repetido) => rollback
    $pdo->prepare("INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256) VALUES ('99999999999999', 'OUTRA', :p, 1, :s)")->execute(['p' => $cnpjA . '/colide_20260101000000.pdf', 's' => str_repeat('c', 64)]);
    $threw = false;
    try {
        $dao->substituir($cnpjA, 'OC-1', $cnpjA . '/colide_20260101000000.pdf', 10, str_repeat('a', 64));
    } catch (CaminhoJaRegistradoException $e) {
        // 1062 em uk_oc_arquivo_caminho = colisao de NOME (distinta da corrida da chave cnpj+numero)
        $threw = $e->getMessage() === 'caminho_ja_registrado';
    }
    $linha5 = $pdo->query("SELECT * FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='OC-1' AND cnpj_cliente='$cnpjA'")->fetch();
    $pdo->exec("DELETE FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='OUTRA'");
    afirmar('dao.substituir: 1062 em uk caminho_relativo => CaminhoJaRegistradoException com ROLLBACK (linha inalterada, sem transacao aberta)', $threw && $linha5 === $linha3 && !$pdo->inTransaction());
    // falha ao criar (sem registro anterior): nada fica
    $r = receber($rnFalha, $cnpjB, 'NOVO-1', rnPdf('nova'));
    afirmar('receber: falha na criacao nao deixa arquivo orfao nem linha', $r['http'] === 503 && ocQaArquivos($raizOc) === $antesArqs && (int) $pdo->query("SELECT COUNT(*) FROM tb_ordem_coleta_arquivos WHERE cnpj_cliente='$cnpjB'")->fetchColumn() === 0);

    // banco indisponivel
    $rnOff = new OrdemColetaArquivoRn(new DaoIndisponivel(), new OrdemColetaArquivoStorage($storage), 5242880);
    $r = receber($rnOff, $cnpjA, 'OFF-1', rnPdf('off'));
    afirmar('receber: banco externo indisponivel => 503 INDISPONIVEL e nada gravado em disco', $r['http'] === 503 && $r['codigo'] === 'INDISPONIVEL' && ocQaArquivos($raizOc) === $antesArqs);

    // limites
    $limite = 5242880;
    $r = receber($rn, $cnpjA, 'LIM-EXATO', ocQaPdf($limite, 'lim'));
    afirmar('limite: PDF de EXATAMENTE 5 MiB aceito (201)', $r['http'] === 201);
    $r = receber($rn, $cnpjA, 'LIM-MAIS1', ocQaPdf($limite + 1, 'lim'));
    afirmar('limite: 5 MiB + 1 byte => 413 ARQUIVO_MUITO_GRANDE', $r['http'] === 413 && $r['codigo'] === 'ARQUIVO_MUITO_GRANDE');
    afirmar('limite: nada gravado para o PDF recusado', (int) $pdo->query("SELECT COUNT(*) FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='LIM-MAIS1'")->fetchColumn() === 0);
    $r = receber($rn, $cnpjA, 'LIM-GRANDE', ocQaPdf($limite * 2, 'lim'));
    afirmar('limite: 10 MiB => 413 (recusado antes de decodificar)', $r['http'] === 413);
    $rnPequeno = new OrdemColetaArquivoRn($dao, new OrdemColetaArquivoStorage($storage), 1000);
    afirmar('limite configuravel (ORDEM_COLETA_ANEXO_MAX_BYTES): 1000 aceita, 1001 recusa', receber($rnPequeno, $cnpjA, 'CFG-1', ocQaPdf(1000))['http'] === 201 && receber($rnPequeno, $cnpjA, 'CFG-2', ocQaPdf(1001))['http'] === 413);
    afirmar('limiteDoAmbiente: valida inteiro positivo, senao padrao 5242880', OrdemColetaArquivoRn::limiteDoAmbiente('1000') === 1000 && OrdemColetaArquivoRn::limiteDoAmbiente(null) === 5242880 && OrdemColetaArquivoRn::limiteDoAmbiente('abc') === 5242880 && OrdemColetaArquivoRn::limiteDoAmbiente('0') === 5242880 && OrdemColetaArquivoRn::limiteDoAmbiente('-5') === 5242880);
    afirmar('tetoCorpo = 1,5 x limite (7,5 MiB para 5 MiB)', OrdemColetaArquivoRn::tetoCorpo(5242880) === 7864320);

    // base64
    $b64 = base64_encode(rnPdf('b64'));
    $base = ['cnpj_cliente' => $cnpjA, 'numero_ordem_coleta' => 'B64-1'];
    $invalidos = [
        'prefixo data:' => 'data:application/pdf;base64,' . $b64,
        'com espaco' => substr($b64, 0, 8) . ' ' . substr($b64, 8),
        'com quebra de linha' => chunk_split($b64, 76, "\n"),
        'urlsafe (-_)' => strtr($b64, '+/', '-_') . 'A-_=',
        'tamanho nao multiplo de 4' => $b64 . 'A',
        'padding em excesso' => $b64 . '===',
        'padding no meio' => substr($b64, 0, 8) . '==' . substr($b64, 8),
        'caractere invalido' => substr($b64, 0, 8) . '!' . substr($b64, 9),
        'so padding' => '====',
    ];
    foreach ($invalidos as $nome => $valor) {
        $r = $rn->receber($base + ['arquivo_base64' => $valor]);
        afirmar("base64 invalido ($nome) => 400 CAMPO_INVALIDO", $r['http'] === 400 && $r['codigo'] === 'CAMPO_INVALIDO');
    }
    $r = $rn->receber($base + ['arquivo_base64' => '']);
    afirmar('base64 vazio => 400', $r['http'] === 400);
    $r = $rn->receber($base + ['arquivo_base64' => ['x']]);
    afirmar('arquivo_base64 nao-string => 400', $r['http'] === 400);
    $r = receber($rn, $cnpjA, 'NAO-PDF', "GIF89a" . str_repeat('x', 100));
    afirmar('conteudo que nao comeca com %PDF- => 422 FORMATO_NAO_SUPORTADO', $r['http'] === 422 && $r['codigo'] === 'FORMATO_NAO_SUPORTADO');
    $r = receber($rn, $cnpjA, 'NAO-PDF2', " %PDF-1.4\n" . str_repeat('x', 100));
    afirmar('%PDF- precedido de byte extra => 422', $r['http'] === 422);
    $r = receber($rn, $cnpjA, 'VAZIO', '');
    afirmar('arquivo decodificado vazio => 400', $r['http'] === 400);
    afirmar('nada gravado para os envios recusados', (int) $pdo->query("SELECT COUNT(*) FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta IN ('B64-1','NAO-PDF','NAO-PDF2','VAZIO')")->fetchColumn() === 0);

    // campos
    foreach (['13 digitos' => '1122233300018', '15 digitos' => '112223330001811', 'com mascara' => '11.222.333/0001-81', 'letras' => '1122233300018A', 'vazio' => '', 'espaco' => ' 11222333000181', 'newline final' => "11222333000181\n", 'int' => 11222333000181, 'null' => null, 'array' => ['x']] as $nome => $cnpjRuim) {
        $r = $rn->receber(['cnpj_cliente' => $cnpjRuim, 'numero_ordem_coleta' => 'C-1', 'arquivo_base64' => $b64]);
        afirmar("cnpj_cliente invalido ($nome) => 400 CAMPO_INVALIDO", $r['http'] === 400 && $r['codigo'] === 'CAMPO_INVALIDO');
    }
    $r = receber($rn, '00000000000000', 'CNPJ-SEM-DV', rnPdf('dv'));
    afirmar('cnpj so exige 14 digitos (DV nao exigido, como no resto do projeto)', $r['http'] === 201);
    foreach (['vazio' => '', 'so espacos' => '   ', 'NUL no meio' => "A\0B", 'NUL na ponta' => "A\0", 'newline interno' => "A\nB", 'tab interno' => "A\tB", 'controle 0x1F' => "A\x1FB", 'DEL' => "A\x7FB", 'UTF-8 invalido' => "A\xC3(B", '51 caracteres' => str_repeat('9', 51), 'zero width' => "A\u{200B}B", 'int' => 1234, 'null' => null, 'array' => ['x']] as $nome => $numRuim) {
        $r = $rn->receber(['cnpj_cliente' => $cnpjA, 'numero_ordem_coleta' => $numRuim, 'arquivo_base64' => $b64]);
        afirmar("numero_ordem_coleta invalido ($nome) => 400 CAMPO_INVALIDO", $r['http'] === 400 && $r['codigo'] === 'CAMPO_INVALIDO');
    }
    $r = receber($rn, $cnpjA, str_repeat('9', 50), rnPdf('n50'));
    afirmar('numero com 50 caracteres aceito', $r['http'] === 201);
    $r = receber($rn, $cnpjA, str_repeat('á', 50), rnPdf('n50u'));
    $gravado = $pdo->query("SELECT caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='" . str_repeat('á', 50) . "'")->fetchColumn();
    afirmar('numero com 50 caracteres acentuados aceito; nome do arquivo so ASCII seguro', $r['http'] === 201 && OrdemColetaArquivoStorage::caminhoRelativoValido($gravado));
    afirmar('receber: entrada escalar/invalida (5, "x", null) vira [] => 400 CAMPO_INVALIDO', $rn->receber(5)['http'] === 400 && $rn->receber('x')['http'] === 400 && $rn->receber(null)['http'] === 400 && $rn->receber([])['http'] === 400);
    $hostil = receber($rn, $cnpjA, '../../../../etc/cron.d/x', rnPdf('trav'));
    $gravadoH = $pdo->query("SELECT caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='../../../../etc/cron.d/x'")->fetchColumn();
    afirmar('numero com ../ aceito como TEXTO (numero real so no banco); arquivo dentro da pasta do cliente, nome valido', $hostil['http'] === 201 && is_string($gravadoH) && OrdemColetaArquivoStorage::caminhoRelativoValido($gravadoH) && is_file($raizOc . '/' . $gravadoH));
    afirmar('mesmo numero em CNPJs diferentes = registros distintos', receber($rn, $cnpjB, 'OC-1', rnPdf('b1'))['dados']['status'] === 'criado');
    $jsonErros = json_encode([receber($rn, $cnpjA, 'ECO-SEGREDO-123', "GIF89a" . str_repeat('x', 50)), $rn->receber(['cnpj_cliente' => 'ECO-SEGREDO-CNPJ', 'numero_ordem_coleta' => 'x', 'arquivo_base64' => 'y'])]);
    afirmar('respostas de erro nao ecoam entrada (numero/cnpj)', !str_contains($jsonErros, 'ECO-SEGREDO'));

    // ======================= PUBLICACAO EXCLUSIVA / COLISAO DE NOME (ajuste pos-/02) =======================
    limparRaiz($raizOc);
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');
    $stFixo = new OrdemColetaArquivoStorage($storage, static fn () => $relogioFixo);
    $nomeBase = $cnpjA . '/EXC-1_20261005100000.pdf';
    // arquivo PRE-EXISTENTE no nome do segundo atual: nunca e sobrescrito
    mkdir($raizOc . '/' . $cnpjA, 0750, true);
    file_put_contents($raizOc . '/' . $nomeBase, 'DONO-ORIGINAL');
    $rel = $stFixo->gravar($cnpjA, 'EXC-1', rnPdf('novo'));
    afirmar('exclusivo: nome ocupado nao e sobrescrito (avanca +1 s) e o dono original fica intacto', $rel === $cnpjA . '/EXC-1_20261005100001.pdf' && file_get_contents($raizOc . '/' . $nomeBase) === 'DONO-ORIGINAL' && file_get_contents($raizOc . '/' . $rel) === rnPdf('novo'));
    afirmar('exclusivo: nenhum .tmp_ residual', count(array_filter(ocQaArquivos($raizOc), static fn ($a) => str_contains($a, '.tmp_'))) === 0);
    // numeros DISTINTOS com a MESMA parte ("OC 1" e "OC-1" => "OC-1") no mesmo segundo
    $r1 = $stFixo->gravar($cnpjA, 'OC 1', rnPdf('d1'));
    $r2 = $stFixo->gravar($cnpjA, 'OC-1', rnPdf('d2'));
    $r3 = $stFixo->gravar($cnpjA, 'OC:1', rnPdf('d3'));
    afirmar('exclusivo: numeros distintos de mesma parte no mesmo segundo => 3 nomes distintos, 3 conteudos preservados', count(array_unique([$r1, $r2, $r3])) === 3 && file_get_contents($raizOc . '/' . $r1) === rnPdf('d1') && file_get_contents($raizOc . '/' . $r2) === rnPdf('d2') && file_get_contents($raizOc . '/' . $r3) === rnPdf('d3'));
    afirmar('exclusivo: formato <parte>_<AAAAMMDDHHMMSS>.pdf mantido', preg_match('#^' . $cnpjA . '/OC-1_\d{14}\.pdf$#D', $r1) === 1 && preg_match('#^' . $cnpjA . '/OC-1_\d{14}\.pdf$#D', $r2) === 1);
    // reservado pelo banco: pula o nome
    $reserva = [$cnpjA . '/RES-1_20261005100000.pdf' => true];
    $relR = $stFixo->gravar($cnpjA, 'RES-1', rnPdf('res'), static fn (string $p): bool => isset($reserva[$p]));
    afirmar('exclusivo: nome reservado no banco (arquivo ausente) e pulado', $relR === $cnpjA . '/RES-1_20261005100001.pdf');
    // esgotou as tentativas: falha limpa, sem tmp e sem tocar nos existentes
    $semNome = new OrdemColetaArquivoStorage($storage, static fn () => $relogioFixo);
    $threw = null;
    try {
        $semNome->gravar($cnpjA, 'LOTADO', rnPdf('l'), static fn (string $p): bool => true);
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    afirmar('exclusivo: sem nome livre => excecao nome_indisponivel e nenhum .tmp_ residual', $threw === 'nome_indisponivel' && count(array_filter(ocQaArquivos($raizOc), static fn ($a) => str_contains($a, '.tmp_'))) === 0);
    // FS sem hard link (link() falha): fallback 'xb' tambem exclusivo
    $stSemLink = new class($storage, static fn () => $relogioFixo) extends OrdemColetaArquivoStorage {
        protected function criarLinkExclusivo(string $tmp, string $final): bool
        {
            return false;
        }
    };
    file_put_contents($raizOc . '/' . $cnpjA . '/FB-1_20261005100000.pdf', 'DONO-FB');
    $relF = $stSemLink->gravar($cnpjA, 'FB-1', rnPdf('fb'));
    afirmar('exclusivo (fallback sem link): nao sobrescreve existente, grava no proximo segundo, sem .tmp_', $relF === $cnpjA . '/FB-1_20261005100001.pdf' && file_get_contents($raizOc . '/' . $cnpjA . '/FB-1_20261005100000.pdf') === 'DONO-FB' && file_get_contents($raizOc . '/' . $relF) === rnPdf('fb') && count(array_filter(ocQaArquivos($raizOc), static fn ($a) => str_contains($a, '.tmp_'))) === 0);
    limparRaiz($raizOc);
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');

    // Rn: 1062 em uk caminho_relativo (outra linha ja registra o nome, arquivo ausente) => outro nome, NADA apagado
    $stIgnoraReserva = new class($storage, static fn () => $relogioFixo) extends OrdemColetaArquivoStorage {
        public function gravar(string $cnpj, string $numero, string $bytes, ?callable $caminhoReservado = null): string
        {
            return parent::gravar($cnpj, $numero, $bytes, null);
        }
    };
    $pColide = $cnpjA . '/COL-1_20261005100000.pdf';
    $pdo->prepare("INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256) VALUES ('99999999999999', 'OUTRA-LINHA', :p, 1, :s)")->execute(['p' => $pColide, 's' => str_repeat('c', 64)]);
    $rnCol = new OrdemColetaArquivoRn(new OrdemColetaArquivoDao($pdo), $stIgnoraReserva, 5242880);
    $r = receber($rnCol, $cnpjA, 'COL-1', rnPdf('col'));
    $lnk = $pdo->query("SELECT caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='COL-1'")->fetchColumn();
    $outra = $pdo->query("SELECT caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='OUTRA-LINHA'")->fetchColumn();
    afirmar('rn: colisao de NOME no banco (1062 caminho) => 201 com outro nome; a outra linha continua com o nome dela', $r['http'] === 201 && $lnk === $cnpjA . '/COL-1_20261005100001.pdf' && $outra === $pColide && is_file($raizOc . '/' . $lnk));
    afirmar('rn: colisao de nome NUNCA apaga arquivo existente (o do nome colidido permanece em disco)', is_file($raizOc . '/' . $pColide));
    limparRaiz($raizOc);
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');

    // Rn: falha da transacao so remove o arquivo novo se NENHUMA linha o referencia
    $daoReferenciado = new class($pdo) extends OrdemColetaArquivoDao {
        public function substituir(string $cnpj, string $numero, string $caminhoRelativo, int $tamanho, string $sha256): array
        {
            throw new PDOException('falha simulada');
        }

        private int $consultas = 0;

        // 1a consulta (reserva do nome, antes de publicar) = livre; depois = referenciado
        public function caminhoRegistrado(string $caminhoRelativo): bool
        {
            return ++$this->consultas > 1;
        }
    };
    $rnRef = new OrdemColetaArquivoRn($daoReferenciado, new OrdemColetaArquivoStorage($storage), 5242880);
    $r = receber($rnRef, $cnpjA, 'REF-1', rnPdf('ref'));
    afirmar('rn: falha da transacao com o caminho REFERENCIADO por alguma linha => arquivo mantido (nao apaga o que e de outro registro)', $r['http'] === 503 && count(ocQaArquivos($raizOc)) === 1);
    limparRaiz($raizOc);
    $daoVerifFalha = new class($pdo) extends OrdemColetaArquivoDao {
        public function substituir(string $cnpj, string $numero, string $caminhoRelativo, int $tamanho, string $sha256): array
        {
            throw new PDOException('falha simulada');
        }

        public function caminhoRegistrado(string $caminhoRelativo): bool
        {
            throw new RuntimeException('verificacao indisponivel');
        }
    };
    $r = receber(new OrdemColetaArquivoRn($daoVerifFalha, new OrdemColetaArquivoStorage($storage), 5242880), $cnpjA, 'REF-2', rnPdf('ref2'));
    afirmar('rn: sem conseguir confirmar a referencia (banco falhou) => em duvida MANTEM o arquivo, 503', $r['http'] === 503 && count(ocQaArquivos($raizOc)) === 1);
    limparRaiz($raizOc);
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');
    $dist = [];
    foreach (['OC 1', 'OC-1', 'OC/1'] as $nOc) {
        $dist[] = receber($rn, $cnpjA, $nOc, rnPdf('dist' . $nOc))['http'];
    }
    $arqsDist = ocQaArquivos($raizOc . '/' . $cnpjA);
    $okDist = count($arqsDist) === 3 && (int) $pdo->query('SELECT COUNT(*) FROM tb_ordem_coleta_arquivos')->fetchColumn() === 3;
    foreach ($pdo->query('SELECT caminho_relativo, sha256 FROM tb_ordem_coleta_arquivos')->fetchAll() as $l) {
        $okDist = $okDist && hash_file('sha256', $raizOc . '/' . $l['caminho_relativo']) === $l['sha256'];
    }
    afirmar('rn: 3 numeros distintos de mesma parte em sequencia (mesmo segundo) => 3 x 201, 3 arquivos, sha256 de cada um igual ao da sua linha', $dist === [201, 201, 201] && $okDist);
    limparRaiz($raizOc);
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');

    // ======================= AuthServidor =======================
    $dirRl = $storage . DIRECTORY_SEPARATOR . 'ordens_coleta_ratelimit';
    $agora = time(); // relogio do teste ancorado no real: a limpeza oportunista usa filemtime real
    $relogio = static function () use (&$agora) {
        return $agora;
    };
    $srv = ['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.7'];
    $auth = new AuthServidor('chave-forte-QA', false, $dirRl, 10, 900, $relogio);
    afirmar('auth: chave correta => autenticado (null)', $auth->verificar($srv, 'Bearer chave-forte-QA') === null);
    foreach (['ausente' => null, 'vazia' => '', 'errada' => 'Bearer outra-chave', 'sem esquema' => 'chave-forte-QA', 'bearer minusculo' => 'bearer chave-forte-QA', 'duas espacos' => 'Bearer  chave-forte-QA', 'espaco no fim' => 'Bearer chave-forte-QA ', 'Basic' => 'Basic chave-forte-QA', 'prefixo da chave' => 'Bearer chave-forte', 'chave + extra' => 'Bearer chave-forte-QAx'] as $nome => $hdr) {
        $a = new AuthServidor('chave-forte-QA', false, null, 10, 900, $relogio);
        $res = $a->verificar($srv, $hdr);
        afirmar("auth: header $nome => 401 NAO_AUTORIZADO generico", $res !== null && $res['http'] === 401 && $res['codigo'] === 'NAO_AUTORIZADO');
    }
    foreach ([['vazia', ''], ['ausente', null]] as [$nome, $chaveCfg]) {
        $a = new AuthServidor($chaveCfg, false, null);
        $res = $a->verificar($srv, 'Bearer ');
        afirmar("auth: chave $nome no .env => 503 INDISPONIVEL (fail-closed)", $res !== null && $res['http'] === 503 && $res['codigo'] === 'INDISPONIVEL');
        $res = $a->verificar($srv, 'Bearer chave-forte-QA');
        afirmar("auth: chave $nome nunca autentica (503 mesmo com header 'correto')", $res !== null && $res['http'] === 503);
    }
    $res = $auth->verificar(['REMOTE_ADDR' => '203.0.113.7'], 'Bearer chave-forte-QA');
    afirmar('auth: HTTP (sem HTTPS) => 403 HTTPS_OBRIGATORIO mesmo com chave correta', $res !== null && $res['http'] === 403 && $res['codigo'] === 'HTTPS_OBRIGATORIO');
    afirmar('auth: HTTPS=off => 403', $auth->verificar(['HTTPS' => 'off'], 'Bearer chave-forte-QA')['http'] === 403);
    afirmar('auth: X-Forwarded-Proto https aceito', $auth->verificar(['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '203.0.113.7'], 'Bearer chave-forte-QA') === null);
    afirmar('auth: porta 443 aceita', $auth->verificar(['SERVER_PORT' => '443', 'REMOTE_ADDR' => '203.0.113.7'], 'Bearer chave-forte-QA') === null);
    $authHttp = new AuthServidor('chave-forte-QA', true, null);
    afirmar('auth: flag explicita permitirHttp libera HTTP (dev local)', $authHttp->verificar(['REMOTE_ADDR' => '127.0.0.1'], 'Bearer chave-forte-QA') === null);

    // rate limit por IP
    $rl = new AuthServidor('chave-forte-QA', false, $dirRl, 3, 900, $relogio);
    $srvA = ['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.1'];
    $srvB = ['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.2'];
    $c = [];
    for ($i = 0; $i < 3; $i++) {
        $c[] = $rl->verificar($srvA, 'Bearer errada')['http'];
    }
    afirmar('rate limit: 3 falhas = 401 cada', $c === [401, 401, 401]);
    $res = $rl->verificar($srvA, 'Bearer errada');
    afirmar('rate limit: 4a tentativa => 429 MUITAS_TENTATIVAS com retry_after', $res['http'] === 429 && $res['codigo'] === 'MUITAS_TENTATIVAS' && $res['retry_after'] === 900);
    afirmar('rate limit: IP bloqueado nem com chave correta passa (429)', $rl->verificar($srvA, 'Bearer chave-forte-QA')['http'] === 429);
    afirmar('rate limit: outro IP nao e afetado', $rl->verificar($srvB, 'Bearer chave-forte-QA') === null);
    $agora += 901;
    afirmar('rate limit: janela expirada libera o IP', $rl->verificar($srvA, 'Bearer chave-forte-QA') === null);
    $arquivosRl = glob($dirRl . DIRECTORY_SEPARATOR . '*.json') ?: [];
    afirmar('rate limit: contador em arquivo com hash do IP (IP nao aparece no nome nem no conteudo)', count($arquivosRl) >= 1 && !str_contains(implode(' ', $arquivosRl), '198.51.100') && !str_contains((string) file_get_contents($arquivosRl[0]), '198.51.100'));
    // contador indisponivel (pasta impossivel de criar) nao derruba nem libera a chave
    $semPasta = new AuthServidor('chave-forte-QA', false, $storage . DIRECTORY_SEPARATOR . 'ordens_coleta' . DIRECTORY_SEPARATOR . 'arquivo-comum' . DIRECTORY_SEPARATOR . 'x', 3, 900, $relogio);
    file_put_contents($storage . DIRECTORY_SEPARATOR . 'ordens_coleta' . DIRECTORY_SEPARATOR . 'arquivo-comum', 'x');
    afirmar('rate limit: pasta do contador indisponivel => ainda 401 (sem excecao) e chave correta autentica', $semPasta->verificar($srvA, 'Bearer errada')['http'] === 401 && $semPasta->verificar($srvA, 'Bearer chave-forte-QA') === null);
    unlink($storage . DIRECTORY_SEPARATOR . 'ordens_coleta' . DIRECTORY_SEPARATOR . 'arquivo-comum');

    // ======================= marcarInativaPorNumero =======================
    $cid = ocQaCliente($pdo, '33444555000100');
    $oAtiva = ocQaOrdem($pdo, $cid, 'BAIXA-1');
    $oJaInativa = ocQaOrdem($pdo, $cid, 'BAIXA-2', 'INATIVA', '2026-01-01 08:00:00');
    $daoOc = new OrdemColetaDao();
    $r1 = $daoOc->marcarInativaPorNumero('BAIXA-1');
    $s1 = $pdo->query("SELECT status, inativada_em FROM tb_ordens_coleta WHERE id=$oAtiva")->fetch();
    afirmar('marcarInativaPorNumero: ATIVA => true, status INATIVA e inativada_em preenchida (agora)', $r1 === true && $s1['status'] === 'INATIVA' && $s1['inativada_em'] !== null && abs(strtotime((string) $s1['inativada_em']) - time()) < 600 + 3 * 3600);
    $r2 = $daoOc->marcarInativaPorNumero('BAIXA-1');
    $s2 = $pdo->query("SELECT inativada_em FROM tb_ordens_coleta WHERE id=$oAtiva")->fetchColumn();
    afirmar('marcarInativaPorNumero: idempotente (false) e nao altera inativada_em', $r2 === false && $s2 === $s1['inativada_em']);
    $r3 = $daoOc->marcarInativaPorNumero('BAIXA-2');
    afirmar('marcarInativaPorNumero: ja INATIVA => false e inativada_em original preservada', $r3 === false && $pdo->query("SELECT inativada_em FROM tb_ordens_coleta WHERE id=$oJaInativa")->fetchColumn() === '2026-01-01 08:00:00');
    afirmar('marcarInativaPorNumero: numero inexistente => false', $daoOc->marcarInativaPorNumero('NAO-EXISTE') === false);
    afirmar('statusPorNumero continua funcionando', $daoOc->statusPorNumero('BAIXA-1') === 'INATIVA' && $daoOc->statusPorNumero('NAO-EXISTE') === null);
    // backfill da migration 003 (re-executada): INATIVA sem inativada_em recebe atualizado_em; ATIVA fica NULL
    $oSem = ocQaOrdem($pdo, $cid, 'BACKFILL-1', 'INATIVA', null);
    $oAt = ocQaOrdem($pdo, $cid, 'BACKFILL-2', 'ATIVA', null);
    $pdo->exec("UPDATE tb_ordens_coleta SET atualizado_em='2026-02-02 10:00:00' WHERE id IN ($oSem, $oAt)");
    ocQaAplicarMigration($pdo, $mig . '003_inativada_em_ordem_coleta.sql');
    $bf = $pdo->query("SELECT id, inativada_em, atualizado_em FROM tb_ordens_coleta WHERE id IN ($oSem, $oAt) ORDER BY id")->fetchAll();
    afirmar('migration 003: backfill INATIVA => inativada_em = atualizado_em (atualizado_em preservado); ATIVA segue NULL', $bf[0]['inativada_em'] === '2026-02-02 10:00:00' && $bf[0]['atualizado_em'] === '2026-02-02 10:00:00' && $bf[1]['inativada_em'] === null);

    // ======================= RETENCAO =======================
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');
    $pdo->exec('DELETE FROM tb_ordens_coleta');
    $pdo->exec('DELETE FROM tb_clientes');
    limparRaiz($raizOc);
    $cli = ocQaCliente($pdo, $cnpjA);
    $cliDup = ocQaCliente($pdo, $cnpjA); // cliente DUPLICADO (dado sujo): ATIVA em um deles protege
    $cenarios = [
        // chave => [oc criada?, status, inativada_em (SQL), criado_em do anexo (SQL), apagar?]
        'INATIVA_16d' => ['INATIVA', 'NOW() - INTERVAL 16 DAY', 'NOW() - INTERVAL 1 DAY', true],
        'INATIVA_14d' => ['INATIVA', 'NOW() - INTERVAL 14 DAY', 'NOW() - INTERVAL 40 DAY', false],
        'INATIVA_SEM_DATA' => ['INATIVA', null, 'NOW() - INTERVAL 40 DAY', false],
        'ATIVA_ANTIGA' => ['ATIVA', null, 'NOW() - INTERVAL 400 DAY', false],
        'ATIVA_REATIVADA' => ['ATIVA', 'NOW() - INTERVAL 40 DAY', 'NOW() - INTERVAL 400 DAY', false], // reativada com inativada_em antigo: status ATIVA protege
        'ORFAO_16d' => [null, null, 'NOW() - INTERVAL 16 DAY', true],
        'ORFAO_14d' => [null, null, 'NOW() - INTERVAL 14 DAY', false],
        'ORFAO_ARQUIVO_AUSENTE_16d' => [null, null, 'NOW() - INTERVAL 16 DAY', true],
    ];
    $ids = [];
    foreach ($cenarios as $chave => [$statusOc, $inatSql, $criadoSql, $apagar]) {
        $relC = $st->gravar($cnpjA, $chave, rnPdf($chave));
        usleep(1000);
        $relReal = $relC;
        $pdo->prepare('INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256, criado_em) VALUES (:c,:n,:p,10,:s,' . $criadoSql . ')')
            ->execute(['c' => $cnpjA, 'n' => $chave, 'p' => $relReal, 's' => str_repeat('a', 64)]);
        $ids[$chave] = ['rel' => $relReal, 'apagar' => $apagar];
        if ($statusOc !== null) {
            $pdo->exec('INSERT INTO tb_ordens_coleta (numero_ordem_coleta, cliente_id, status, inativada_em) VALUES (' . $pdo->quote($chave) . ", $cli, '$statusOc', " . ($inatSql ?? 'NULL') . ')');
        }
    }
    unlink($raizOc . '/' . $ids['ORFAO_ARQUIVO_AUSENTE_16d']['rel']); // linha sem arquivo = ausente (ok)
    // cliente duplicado: uma OC INATIVA antiga em um cliente e ATIVA no outro => mantem
    $st2 = new OrdemColetaArquivoStorage($storage, static fn () => $relogioFixo->modify('+30 seconds'));
    $relDup = $st2->gravar($cnpjA, 'DUP_ATIVA_E_INATIVA', rnPdf('dup'));
    $pdo->prepare('INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256, criado_em) VALUES (:c,:n,:p,10,:s, NOW() - INTERVAL 90 DAY)')
        ->execute(['c' => $cnpjA, 'n' => 'DUP_ATIVA_E_INATIVA', 'p' => $relDup, 's' => str_repeat('a', 64)]);
    $pdo->exec("INSERT INTO tb_ordens_coleta (numero_ordem_coleta, cliente_id, status, inativada_em) VALUES ('DUP_ATIVA_E_INATIVA', $cli, 'INATIVA', NOW() - INTERVAL 60 DAY)");
    $pdo->exec("INSERT INTO tb_ordens_coleta (numero_ordem_coleta, cliente_id, status) VALUES ('DUP_ATIVA_E_INATIVA', $cliDup, 'ATIVA')");

    $dao2 = new OrdemColetaArquivoDao($pdo);
    $elegiveis = array_column($dao2->listarExpirados(200, 15), 'id');
    $esperados = [];
    foreach ($ids as $chave => $info) {
        if ($info['apagar']) {
            $esperados[] = (int) $pdo->query('SELECT id FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta=' . $pdo->quote($chave))->fetchColumn();
        }
    }
    sort($esperados);
    sort($elegiveis);
    afirmar('retencao: elegiveis = INATIVA>15d + orfao>15d (e so eles)', $elegiveis === $esperados && count($esperados) === 3);
    afirmar('retencao: lote respeita o limite', count($dao2->listarExpirados(2, 15)) === 2);

    $rnRet = new OrdemColetaArquivoRn($dao2, new OrdemColetaArquivoStorage($storage));
    // linha hostil: caminho que escapa da raiz + arquivo fora da raiz que NAO pode ser apagado
    file_put_contents($storage . DIRECTORY_SEPARATOR . 'intocavel_20260101000000.pdf', rnPdf('intocavel'));
    $pdo->prepare('INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256, criado_em) VALUES (:c,:n,:p,10,:s, NOW() - INTERVAL 90 DAY)')
        ->execute(['c' => '55666777000188', 'n' => 'HOSTIL', 'p' => '../intocavel_20260101000000.pdf', 's' => str_repeat('a', 64)]);
    $res = $rnRet->limparExpirados(200, 15);
    afirmar('retencao: 3 apagados/ausentes e 1 falha (caminho hostil)', $res['elegiveis'] === 4 && $res['apagados'] === 2 && $res['ausentes'] === 1 && $res['falhas'] === 1);
    afirmar('retencao: caminho hostil NAO apaga arquivo fora da raiz e MANTEM a linha', is_file($storage . DIRECTORY_SEPARATOR . 'intocavel_20260101000000.pdf') && (int) $pdo->query("SELECT COUNT(*) FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='HOSTIL'")->fetchColumn() === 1);
    $restantes = $pdo->query('SELECT numero_ordem_coleta FROM tb_ordem_coleta_arquivos ORDER BY numero_ordem_coleta')->fetchAll(PDO::FETCH_COLUMN);
    afirmar('retencao: restam ATIVA, INATIVA<=15d, INATIVA sem data, orfao<=15d, dup ATIVA+INATIVA e o hostil', $restantes === ['ATIVA_ANTIGA', 'ATIVA_REATIVADA', 'DUP_ATIVA_E_INATIVA', 'HOSTIL', 'INATIVA_14d', 'INATIVA_SEM_DATA', 'ORFAO_14d']);
    afirmar('retencao: arquivos das linhas apagadas removidos; os das mantidas permanecem', !is_file($raizOc . '/' . $ids['INATIVA_16d']['rel']) && !is_file($raizOc . '/' . $ids['ORFAO_16d']['rel']) && is_file($raizOc . '/' . $ids['ATIVA_ANTIGA']['rel']) && is_file($raizOc . '/' . $ids['INATIVA_14d']['rel']) && is_file($raizOc . '/' . $ids['ORFAO_14d']['rel']) && is_file($raizOc . '/' . $relDup));
    $res2 = $rnRet->limparExpirados(200, 15);
    afirmar('retencao: segunda execucao idempotente (so a linha hostil segue como falha)', $res2['elegiveis'] === 1 && $res2['falhas'] === 1 && $res2['apagados'] === 0);
    // sobrescrita concorrente: linha com outro caminho NAO e apagada pelo cron
    $linhaTroca = $pdo->query("SELECT id, caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='ORFAO_14d'")->fetch();
    afirmar('dao.excluirPorIdECaminho: caminho diferente (linha ja sobrescrita) => nao apaga', $dao2->excluirPorIdECaminho((int) $linhaTroca['id'], $cnpjA . '/outro_20260101000000.pdf') === false && (int) $pdo->query("SELECT COUNT(*) FROM tb_ordem_coleta_arquivos WHERE id=" . (int) $linhaTroca['id'])->fetchColumn() === 1);
    // falha de unlink mantem a linha
    $stFalha = new class($storage) extends OrdemColetaArquivoStorage {
        public function remover(string $relativo): string
        {
            return 'falha';
        }
    };
    $pdo->prepare("UPDATE tb_ordem_coleta_arquivos SET criado_em = NOW() - INTERVAL 30 DAY WHERE numero_ordem_coleta='ORFAO_14d'")->execute();
    $resF = (new OrdemColetaArquivoRn($dao2, $stFalha))->limparExpirados(200, 15);
    afirmar('retencao: falha de unlink MANTEM a linha e conta falha', $resF['apagados'] === 0 && $resF['falhas'] >= 1 && (int) $pdo->query("SELECT COUNT(*) FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='ORFAO_14d'")->fetchColumn() === 1);
    $resBanco = null;
    try {
        (new OrdemColetaArquivoRn(new DaoIndisponivel(), $st))->limparExpirados();
    } catch (Throwable $e) {
        $resBanco = get_class($e);
    }
    afirmar('retencao: banco externo indisponivel lanca (cron sai com 1, nada apagado)', $resBanco === 'RuntimeException');

    // cron real (subprocesso CLI, prepend QA)
    $cron = dirname(__DIR__, 2) . '/cron/limpar-anexos-ordem-coleta.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/qa_oc_anexo_prepend.php') . ' ' . escapeshellarg($cron);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigoCron = proc_close($proc);
    afirmar('cron: executa no QA, saida 1 (ha linha hostil/falha), log agregado so com contagens', $codigoCron === 1 && str_contains($stderr, 'limpar-anexos-ordem-coleta: ') && preg_match('/\d+ elegivel/', $stderr) === 1 && !str_contains($stderr, 'intocavel') && !str_contains($stderr, $cnpjA) && $stdout === '');
    $pdo->exec("DELETE FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='HOSTIL'");
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    afirmar('cron: sem linhas problematicas => saida 0', proc_close($proc) === 0);

    // ======================= LEITOR + TalentRn =======================
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');
    limparRaiz($raizOc);
    $stReal = new OrdemColetaArquivoStorage($storage);
    $leitor = new LeitorEspiao($dao, $stReal, 5242880);
    $clientCap = new TalentClientCaptura();
    $atDao = new AtendimentoDao($pdo);
    $talent = new TalentRn($clientCap, new FilaEnvioDao($pdo), $atDao, $storage, $leitor);
    $idTotem = talentCriarTotemComEmpresa($pdo, 'QA-OC-' . bin2hex(random_bytes(3)), null);
    $empresa = ['cnpj' => '14706199000182'];
    $cnpjCli = '11222333000181';

    $novoAtendimento = static function (string $tipo, string $placa, ?string $oc) use ($pdo, $atDao, $idTotem, $cnpjCli): array {
        $f = talentCriarAtendimentoPronto($pdo, $atDao, $idTotem, $tipo, $placa, $cnpjCli, 'SP', true, '12345678', 'CAMINHAO', $oc);

        return $atDao->buscarPorId($f['id_atendimento']);
    };
    $rnGrava = new OrdemColetaArquivoRn($dao, $stReal, 5242880);

    // payload COM anexo
    receber($rnGrava, $cnpjCli, 'OC-TALENT-1', rnPdf('talent', 3000));
    $a = $novoAtendimento('expedicao', 'QAA1111', 'OC-TALENT-1');
    $res = $talent->processarCheckin($a, $empresa, []);
    $anexos = $clientCap->ultimo['anexos'] ?? [];
    $ultimo = end($anexos);
    afirmar('talent: Expedicao com anexo => ENVIADO e anexo "Ordem de Coleta" em base64 puro', $res['status'] === 'ENVIADO' && is_array($ultimo) && $ultimo['descricao'] === 'Ordem de Coleta' && base64_decode($ultimo['anexoBase64'], true) === rnPdf('talent', 3000) && !str_contains($ultimo['anexoBase64'], 'data:'));
    afirmar('talent: anexos so tem as chaves anexoBase64/descricao e doctos[] ORDEM_COLETA inalterado', array_keys($ultimo) === ['anexoBase64', 'descricao'] && $clientCap->ultimo['doctos'] === [['tipo' => 'ORDEM_COLETA', 'nrDocto' => 'OC-TALENT-1']] && !array_key_exists('anexosGZip', $clientCap->ultimo));
    $gz = $talent->montarAnexosGzip($a, []);
    afirmar('talent: montarAnexosGzip herda o anexo da OC', count($gz) === 1 && isset($gz[0]['nome'], $gz[0]['valueBase64']) && gzdecode((string) base64_decode($gz[0]['valueBase64'], true)) === rnPdf('talent', 3000));

    // cliente_cnpj com mascara no atendimento (so digitos sao usados) e numero em caixa diferente (collation ci)
    $a2 = $novoAtendimento('expedicao', 'QAA1112', 'oc-talent-1');
    $pdo->prepare("UPDATE tb_atendimento SET cliente_cnpj='11.222.333/0001-81' WHERE id_atendimento=:i")->execute(['i' => $a2['id_atendimento']]);
    $a2 = $atDao->buscarPorId((int) $a2['id_atendimento']);
    $res = $talent->processarCheckin($a2, $empresa, []);
    afirmar('talent: cnpj mascarado e numero em outra caixa casam o anexo (digitos + collation)', $res['status'] === 'ENVIADO' && count($clientCap->ultimo['anexos']) === 1);

    // SEM anexo registrado: caso normal, sem log
    $logAntes = (string) @file_get_contents($logArquivo);
    $a3 = $novoAtendimento('expedicao', 'QAA1113', 'OC-SEM-ANEXO');
    $res = $talent->processarCheckin($a3, $empresa, []);
    $logDepois = (string) file_get_contents($logArquivo);
    afirmar('talent: sem anexo registrado => ENVIADO, anexos vazio e SEM log', $res['status'] === 'ENVIADO' && $clientCap->ultimo['anexos'] === [] && substr($logDepois, strlen($logAntes)) === '');

    // falhas => segue sem anexo, UMA linha de log, nunca ERRO_REPROCESSAVEL
    $falhasCasos = [];
    // arquivo ausente
    receber($rnGrava, $cnpjCli, 'OC-FALHA-AUSENTE', rnPdf('f1'));
    $falhasCasos['arquivo_ausente'] = ['OC-FALHA-AUSENTE', static function () use ($pdo, $raizOc) {
        $p = $pdo->query("SELECT caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='OC-FALHA-AUSENTE'")->fetchColumn();
        unlink($raizOc . '/' . $p);
    }];
    receber($rnGrava, $cnpjCli, 'OC-FALHA-CORROMPIDO', rnPdf('f2'));
    $falhasCasos['sha256_divergente'] = ['OC-FALHA-CORROMPIDO', static function () use ($pdo, $raizOc) {
        $p = $pdo->query("SELECT caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='OC-FALHA-CORROMPIDO'")->fetchColumn();
        file_put_contents($raizOc . '/' . $p, rnPdf('adulterado'));
    }];
    receber($rnGrava, $cnpjCli, 'OC-FALHA-NAOPDF', rnPdf('f3'));
    $falhasCasos['nao_e_pdf'] = ['OC-FALHA-NAOPDF', static function () use ($pdo, $raizOc) {
        $p = $pdo->query("SELECT caminho_relativo FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='OC-FALHA-NAOPDF'")->fetchColumn();
        file_put_contents($raizOc . '/' . $p, 'lixo');
    }];
    $pdo->prepare("INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256) VALUES (:c, 'OC-FALHA-CAMINHO', '../fora_20260101000000.pdf', 10, :s)")->execute(['c' => $cnpjCli, 's' => str_repeat('b', 64)]);
    $falhasCasos['caminho_invalido'] = ['OC-FALHA-CAMINHO', static function () {
    }];
    foreach ($falhasCasos as $motivo => [$oc, $quebrar]) {
        $quebrar();
        $logAntes = (string) @file_get_contents($logArquivo);
        $at = $novoAtendimento('expedicao', 'QAF' . random_int(1000, 9999), $oc);
        $res = $talent->processarCheckin($at, $empresa, []);
        $novoLog = substr((string) file_get_contents($logArquivo), strlen($logAntes));
        $linhasLog = array_values(array_filter(explode("\n", trim($novoLog))));
        afirmar("talent: falha ($motivo) => ENVIADO sem anexo (nunca ERRO_REPROCESSAVEL)", $res['status'] === 'ENVIADO' && $clientCap->ultimo['anexos'] === [] && $res['erro_categoria'] === null);
        afirmar("talent: falha ($motivo) => UMA linha de log com so id_atendimento e motivo fixo", count($linhasLog) === 1 && preg_match('/TalentRn: anexo_ordem_coleta_omitido id_atendimento=' . (int) $at['id_atendimento'] . ' motivo=' . $motivo . '$/', $linhasLog[0]) === 1 && !str_contains($novoLog, $oc) && !str_contains($novoLog, $cnpjCli));
        $fila = (int) $pdo->query('SELECT COUNT(*) FROM tb_fila_envio WHERE id_atendimento=' . (int) $at['id_atendimento'])->fetchColumn();
        afirmar("talent: falha ($motivo) nao entra na fila de reenvio", $fila === 0);
    }
    // banco externo fora do ar
    $leitorOff = new AnexoOrdemColetaLeitor(new DaoIndisponivel(), $stReal, 5242880);
    $talentOff = new TalentRn($clientCap, new FilaEnvioDao($pdo), $atDao, $storage, $leitorOff);
    $logAntes = (string) file_get_contents($logArquivo);
    $at = $novoAtendimento('expedicao', 'QAOFF11', 'OC-BANCO-OFF');
    $res = $talentOff->processarCheckin($at, $empresa, []);
    $novoLog = substr((string) file_get_contents($logArquivo), strlen($logAntes));
    afirmar('talent: banco externo indisponivel => ENVIADO sem anexo, 1 linha de log (banco_indisponivel), sem getMessage', $res['status'] === 'ENVIADO' && $clientCap->ultimo['anexos'] === [] && substr_count($novoLog, "\n") === 1 && str_contains($novoLog, 'motivo=banco_indisponivel') && !str_contains($novoLog, 'conectar'));
    // leitor que lanca / devolve lixo
    $leitorBoom = new class($dao, $stReal) extends AnexoOrdemColetaLeitor {
        public function buscar(string $cnpj, string $numero): array
        {
            throw new PDOException('SQLSTATE[HY000] SEGREDO-' . $numero);
        }
    };
    $talentBoom = new TalentRn($clientCap, new FilaEnvioDao($pdo), $atDao, $storage, $leitorBoom);
    $logAntes = (string) file_get_contents($logArquivo);
    $at = $novoAtendimento('expedicao', 'QABOOM1', 'OC-BOOM');
    $res = $talentBoom->processarCheckin($at, $empresa, []);
    $novoLog = substr((string) file_get_contents($logArquivo), strlen($logAntes));
    afirmar('talent: leitor que lanca excecao => segue sem anexo, log so com get_class/codigo fixo', $res['status'] === 'ENVIADO' && $clientCap->ultimo['anexos'] === [] && str_contains($novoLog, 'motivo=leitor_excecao') && !str_contains($novoLog, 'SEGREDO') && !str_contains($novoLog, 'SQLSTATE'));

    // Recebimento inalterado: leitor nunca consultado; payload sem anexo de OC
    $chamadasAntes = $leitor->chamadas;
    $rec = $novoAtendimento('recebimento', 'QAREC11', null);
    $notaDao = new App\Dao\AtendimentoNotaDao($pdo);
    talentInserirNotaComNumero($notaDao, (int) $rec['id_atendimento'], 1, '789');
    $pdo->prepare("UPDATE tb_atendimento SET ordem_coleta='OC-TALENT-1' WHERE id_atendimento=:i")->execute(['i' => $rec['id_atendimento']]);
    $rec = $atDao->buscarPorId((int) $rec['id_atendimento']);
    $notas = $notaDao->listarPorAtendimento((int) $rec['id_atendimento']);
    $res = $talent->processarCheckin($rec, $empresa, $notas);
    $descricoes = array_column($clientCap->ultimo['anexos'], 'descricao');
    afirmar('talent: Recebimento NAO consulta o leitor e nao leva anexo de OC (mesmo com ordem_coleta preenchida)', $res['status'] === 'ENVIADO' && $leitor->chamadas === $chamadasAntes && !in_array('Ordem de Coleta', $descricoes, true) && $clientCap->ultimo['doctos'][0]['tipo'] === 'NOTA_FISCAL');
    // sem leitor injetado (padrao): comportamento anterior
    $talentSem = new TalentRn($clientCap, new FilaEnvioDao($pdo), $atDao, $storage);
    $at = $novoAtendimento('expedicao', 'QASEM11', 'OC-TALENT-1');
    $talentSem->processarCheckin($at, $empresa, []);
    afirmar('talent: sem leitor (5o parametro omitido) => nenhum anexo de OC (comportamento anterior)', $clientCap->ultimo['anexos'] === []);
    // padrao(): nao abre conexao no construtor (DAO sem PDO; so cria objetos)
    $padrao = AnexoOrdemColetaLeitor::padrao();
    afirmar('AnexoOrdemColetaLeitor::padrao() instancia sem conectar', $padrao instanceof AnexoOrdemColetaLeitor);
    afirmar('leitor: chave invalida (cnpj curto/numero vazio) => motivo chave_invalida sem tocar no banco', (new AnexoOrdemColetaLeitor(new DaoIndisponivel(), $stReal))->buscar('123', 'x')['motivo'] === 'chave_invalida');

    // ======================= CONCORRENCIA =======================
    $pdo->exec('DELETE FROM tb_ordem_coleta_arquivos');
    limparRaiz($raizOc);
    $caso = __DIR__ . '/_caso_oc_anexo_receber.php';
    $procs = [];
    for ($i = 0; $i < 6; $i++) {
        $c = escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/qa_oc_anexo_prepend.php') . ' ' . escapeshellarg($caso) . ' ' . escapeshellarg($cnpjA) . ' ' . escapeshellarg('CONC-1') . ' ' . escapeshellarg('marca' . $i);
        $procs[$i] = proc_open($c, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, null);
        $procs[$i] = [$procs[$i], $pp];
    }
    $saidas = [];
    foreach ($procs as [$p, $pp]) {
        $saidas[] = json_decode((string) stream_get_contents($pp[1]), true);
        stream_get_contents($pp[2]);
        fclose($pp[1]);
        fclose($pp[2]);
        proc_close($p);
    }
    $httpCodes = array_map(static fn ($s) => $s['http'] ?? null, $saidas);
    $linhasConc = $pdo->query("SELECT * FROM tb_ordem_coleta_arquivos WHERE numero_ordem_coleta='CONC-1'")->fetchAll();
    $arqsConc = ocQaArquivos($raizOc . '/' . $cnpjA);
    afirmar('concorrencia: 6 envios simultaneos da mesma chave => todos 201/200, exatamente 1 linha e 1 arquivo (sem orfaos)', count($linhasConc) === 1 && count($arqsConc) === 1 && $cnpjA . '/' . $arqsConc[0] === $linhasConc[0]['caminho_relativo'] && count(array_filter($httpCodes, static fn ($h) => $h === 201 || $h === 200)) === 6);
    $lido = $stReal->ler($linhasConc[0]['caminho_relativo'], 5242880, $linhasConc[0]['sha256']);
    afirmar('concorrencia: o arquivo final bate com o sha256 da linha (consistente)', $lido['bytes'] !== null);

    // ======================= LOGS =======================
    $logFinal = (string) file_get_contents($logArquivo);
    $sentinelas = ['SEGREDO', 'OC-TALENT-1', 'OC-FALHA', 'OC-BANCO-OFF', 'ECO-SEGREDO', $cnpjCli, 'Nao foi possivel conectar', 'SQLSTATE', 'qa_oc_storage_', '%PDF', 'JVBER'];
    $vazou = [];
    foreach ($sentinelas as $s) {
        if (str_contains($logFinal, $s)) {
            $vazou[] = $s === $cnpjCli ? 'cnpj' : substr($s, 0, 12);
        }
    }
    afirmar('logs: nenhum numero de OC, cnpj, caminho, base64, PDF ou getMessage nos logs (' . ($vazou ? implode(',', $vazou) : 'limpo') . ')', $vazou === []);
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada: ' . get_class($e) . ' @' . basename($e->getFile()) . ':' . $e->getLine() . ' ' . substr($e->getMessage(), 0, 160), false);
} finally {
    if ($logAnterior !== false) {
        ini_set('error_log', (string) $logAnterior);
    }
    @unlink($logArquivo);
    ocQaLimpar($banco, $storage);
}

echo "\nTotal: $total, falhas: $falhas\n";
exit($falhas === 0 ? 0 : 1);
