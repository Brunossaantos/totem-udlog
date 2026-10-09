<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

ini_set('zend.exception_ignore_args', '1');

require_once __DIR__ . '/../vendor/autoload.php';

use App\Dao\ClienteGestaoDao;
use App\Rn\ClienteGestaoRn;
use Util\Bootstrap;
use Util\LogSistema;
use Util\RazaoSocialMatcher;

const JOB_RECALCULO = 'recalcular_razao';

function saida(string $linha): void
{
    fwrite(STDOUT, $linha . PHP_EOL);
}

function listaIds(array $ids): string
{
    return $ids === [] ? '-' : implode(',', $ids);
}

function analisar(array $linhas): array
{
    $r = ['total' => count($linhas), 'iguais' => 0, 'diferentes' => [], 'vazias_novas' => [], 'vazias_mantidas' => 0, 'longas' => [], 'duplicadas' => []];
    $ativosPorRazao = [];
    foreach ($linhas as $l) {
        $id = (int) $l['id_cliente'];
        $antiga = $l['razao_social_normalizada'] === null ? null : (string) $l['razao_social_normalizada'];
        $nova = RazaoSocialMatcher::normalizar((string) $l['nome']);
        if ($antiga === $nova) {
            $r['iguais']++;
            if ($nova === '') {
                $r['vazias_mantidas']++;
            }
        } else {
            $r['diferentes'][] = ['id' => $id, 'antiga' => $antiga, 'nova' => $nova];
            if ($nova === '') {
                $r['vazias_novas'][] = $id;
            }
        }
        if (strlen($nova) > ClienteGestaoRn::RAZAO_MAX) {
            $r['longas'][] = $id;
        }
        if ((int) $l['ativo'] === 1 && $nova !== '') {
            $ativosPorRazao[$nova][] = $id;
        }
    }
    foreach ($ativosPorRazao as $razao => $ids) {
        if (count($ids) > 1) {
            $r['duplicadas'][(string) $razao] = $ids;
        }
    }

    return $r;
}

function imprimirResumo(array $a, bool $aplicar): void
{
    $ids = array_column($a['diferentes'], 'id');
    $idsDuplicados = [];
    foreach ($a['duplicadas'] as $grupo) {
        $idsDuplicados = array_merge($idsDuplicados, $grupo);
    }
    saida('modo: ' . ($aplicar ? 'APLICAR' : 'DRY-RUN (nada sera gravado)'));
    saida('total: ' . $a['total']);
    saida('iguais: ' . $a['iguais']);
    saida('diferentes: ' . count($a['diferentes']));
    saida('vazias_que_ficariam_vazias: ' . ($a['vazias_mantidas'] + count($a['vazias_novas'])) . ' (das quais ' . count($a['vazias_novas']) . ' mudam de valor)');
    saida('acima_da_coluna: ' . count($a['longas']));
    saida('grupos_duplicados_entre_ativos: ' . count($a['duplicadas']));
    saida('ids_diferentes: ' . listaIds($ids));
    saida('ids_vazios_novos: ' . listaIds($a['vazias_novas']));
    saida('ids_acima_da_coluna: ' . listaIds($a['longas']));
    foreach (array_values($a['duplicadas']) as $i => $grupo) {
        saida('duplicidade_' . ($i + 1) . '_ids: ' . listaIds($grupo));
    }
    $bloqueios = count($a['vazias_novas']) + count($a['longas']) + count($a['duplicadas']);
    if (!$aplicar) {
        saida('a aplicacao seria ' . ($bloqueios === 0 ? 'PERMITIDA' : 'RECUSADA') . ' (use --aplicar para gravar)');
    }
}

function lerClientes(PDO $pdo, bool $travar): array
{
    $sql = 'SELECT id_cliente, nome, razao_social_normalizada, ativo FROM tb_cliente ORDER BY id_cliente' . ($travar ? ' FOR UPDATE' : '');

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function executar(bool $aplicar): int
{
    try {
        $pdo = Bootstrap::conectar(__DIR__ . '/../');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Falha ao conectar (.env/banco). Nada foi alterado.' . PHP_EOL);

        return 2;
    }

    if (!$aplicar) {
        try {
            imprimirResumo(analisar(lerClientes($pdo, false)), false);

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Falha no dry-run (' . get_class($e) . '). Nada foi alterado.' . PHP_EOL);

            return 1;
        }
    }

    $dao = new ClienteGestaoDao($pdo);
    $lock = false;
    $emTransacao = false;
    try {
        $lock = $dao->obterLock(10);
        if (!$lock) {
            fwrite(STDERR, 'Outra operacao de cadastro de clientes em andamento (lock). Nada foi alterado.' . PHP_EOL);

            return 1;
        }

        $pdo->beginTransaction();
        $emTransacao = true;
        $analise = analisar(lerClientes($pdo, true));
        imprimirResumo($analise, true);

        if ($analise['vazias_novas'] !== [] || $analise['longas'] !== [] || $analise['duplicadas'] !== []) {
            $pdo->rollBack();
            $emTransacao = false;
            saida('RECUSADO: nenhuma alteracao foi gravada (valor novo vazio, acima da coluna ou duplicado entre ativos).');
            error_log('recalcular-razao-normalizada: recusado total=' . $analise['total'] . ' diferentes=' . count($analise['diferentes'])
                . ' vazias=' . count($analise['vazias_novas']) . ' longas=' . count($analise['longas']) . ' duplicadas=' . count($analise['duplicadas']));
            LogSistema::registrar('cron_falhou', ['job' => JOB_RECALCULO, 'motivo' => 'dados_invalidos', 'itens' => count($analise['diferentes']), 'falhas' => count($analise['vazias_novas']) + count($analise['longas']) + count($analise['duplicadas'])]);

            return 1;
        }

        $upd = $pdo->prepare('UPDATE tb_cliente SET razao_social_normalizada = :r WHERE id_cliente = :id AND (razao_social_normalizada <=> :antiga)');
        foreach ($analise['diferentes'] as $d) {
            $upd->execute(['r' => $d['nova'], 'id' => $d['id'], 'antiga' => $d['antiga']]);
            if ($upd->rowCount() !== 1) {
                $pdo->rollBack();
                $emTransacao = false;
                saida('ABORTADO: o cliente id_cliente=' . $d['id'] . ' mudou durante a execucao. Nenhuma alteracao foi gravada.');
                error_log('recalcular-razao-normalizada: abortado (linha mudou) diferentes=' . count($analise['diferentes']));
                LogSistema::registrar('cron_falhou', ['job' => JOB_RECALCULO, 'motivo' => 'dados_invalidos', 'itens' => count($analise['diferentes'])]);

                return 1;
            }
        }
        $pdo->commit();
        $emTransacao = false;

        $n = count($analise['diferentes']);
        saida('APLICADO: ' . $n . ' razao(oes) normalizada(s) atualizada(s).');
        error_log('recalcular-razao-normalizada: aplicado total=' . $analise['total'] . ' atualizadas=' . $n);
        LogSistema::registrar('cron_resumo', ['job' => JOB_RECALCULO, 'itens' => $n]);

        return 0;
    } catch (\Throwable $e) {
        if ($emTransacao) {
            try {
                $pdo->rollBack();
            } catch (\Throwable $e2) {
            }
        }
        fwrite(STDERR, 'Falha inesperada (' . get_class($e) . '). Nenhuma alteracao foi gravada.' . PHP_EOL);
        error_log('recalcular-razao-normalizada: falha ' . get_class($e));
        LogSistema::registrar('cron_falhou', ['job' => JOB_RECALCULO, 'motivo' => 'falha_inesperada', 'excecao' => $e]);

        return 1;
    } finally {
        if ($lock) {
            $dao->liberarLock();
        }
    }
}

$aplicar = false;
$dry = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--aplicar') {
        $aplicar = true;
    } elseif ($arg === '--dry-run') {
        $dry = true;
    } else {
        fwrite(STDERR, 'Argumento desconhecido. Uso: php tools/recalcular-razao-normalizada.php [--aplicar]' . PHP_EOL);
        exit(2);
    }
}
if ($aplicar && $dry) {
    fwrite(STDERR, '--aplicar e --dry-run sao excludentes.' . PHP_EOL);
    exit(2);
}

$codigo = executar($aplicar);
LogSistema::descarregar();
exit($codigo);
