<?php

/**
 * Servidor local descartavel do harness de layout da Gestao Totem
 * (tests/manual/gestao_layout.js). Cria um banco QA `qa_qr_exclusivo_<hex>`
 * (schema + migrations, mesma infra de qa_gestao_infra.php), semeia usuarios,
 * sobe `php -S 127.0.0.1:<porta>` servindo public/ (paginas REAIS /gestao/*.php,
 * com o prepend QA que forca o banco QA, o storage temporario e o
 * GESTAO_PERMITIR_HTTP=true so deste processo), imprime uma linha JSON "PRONTO"
 * e espera o stdin fechar ou a linha "fim". No fim mata o servidor, remove o
 * banco e o storage. Nunca toca em udlog_totem.
 *
 * Uso (chamado pelo node): php tests/manual/gestao_layout_servidor.php <porta>
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';
require_once __DIR__ . '/qa_gestao_oc_infra.php';

use App\Rn\TotemGestaoRn;

$porta = (int) ($argv[1] ?? 0);
if ($porta < 1024 || $porta > 65000) {
    fwrite(STDERR, "porta invalida\n");
    exit(2);
}

$banco = null;
$storage = null;
$proc = null;
$amb = null;
try {
    // F4 (ordens): DOIS bancos QA descartaveis (totem + externo de coletas, ver qa_gestao_oc_infra.php); nunca udlog_totem
    // nem o banco externo real. O prepend forca os dois nomes (QA_QR_FORCE_DB_NAME / QA_QR_FORCE_EXT_DB_NAME).
    date_default_timezone_set('America/Sao_Paulo');
    $amb = ogCriarAmbiente();
    $pdo = $amb['totem'];
    $ext = $amb['externo'];
    $banco = $amb['banco_totem'];
    $storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_gestao_' . bin2hex(random_bytes(6));
    mkdir($storage . '/ordens_coleta', 0700, true);
    putenv('QA_QR_FORCE_DB_NAME=' . $banco);
    putenv('QA_QR_FORCE_EXT_DB_NAME=' . $amb['banco_externo']);
    putenv('QA_QR_FORCE_STORAGE=' . $storage);
    gtSemear($pdo, 'ana.admin', 'admin', GT_SENHA_BOA, false, true, 'Ana Admin');
    gtSemear($pdo, 'beto.admin', 'admin', GT_SENHA_BOA, false, true, 'Beto Administrador da Silva Albuquerque Neto');
    gtSemear($pdo, 'carla.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Carla Usuario');
    gtSemear($pdo, 'otavio.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Otavio Usuario');
    gtSemear($pdo, 'davi.pendente', 'usuario', GT_SENHA_BOA, true, true, 'Davi Pendente');
    gtSemear($pdo, 'ines.inativa', 'usuario', GT_SENHA_BOA, false, false, 'Ines Inativa');
    gtSemear($pdo, 'zeca.alvo', 'usuario', GT_SENHA_BOA, false, true, 'Zeca Alvo');
    gtSemear($pdo, 'rita.ordens', 'usuario', GT_SENHA_BOA, false, true, 'Rita Ordens'); // F4: usuario so das telas de ordens
    $idBloq = gtSemear($pdo, 'bloq.usuario', 'usuario', GT_SENHA_BOA, false, true, 'Bruna Bloqueada');
    $pdo->exec('UPDATE tb_gestao_usuario SET bloqueado_ate = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id_usuario = ' . (int) $idBloq);
    gtSemear($pdo, 'xss.um', 'usuario', GT_SENHA_BOA, false, true, '<img src=x onerror=window.__xss=1>');
    gtSemear($pdo, 'xss.dois', 'usuario', GT_SENHA_BOA, false, true, '"><script>window.__xss=2</script>');

    // F2 (totens): empresas e totens semeados (ativo, inativo, legado fora do padrao, com atendimento recente,
    // nome/empresa hostis, URL longa). TOTEM_URL_BASE existe SO no ambiente deste processo (nunca no .env).
    $urlBase = 'https://totem.udlog.online/totem/';
    $idAdmin = (int) $pdo->query("SELECT id_usuario FROM tb_gestao_usuario WHERE login = 'ana.admin'")->fetchColumn();
    $emp = static function (string $nome, string $cnpj, int $ativo = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_empresa (nome, cnpj, ativo) VALUES (:n, :c, :a)')->execute(['n' => $nome, 'c' => $cnpj, 'a' => $ativo]);

        return (int) $pdo->lastInsertId();
    };
    $eMaua1 = $emp('Maua I', '14706199000182');
    $eMaua2 = $emp('Maua II', '14706199000344');
    $eLonga = $emp('Empresa16Chars00', '55555555000155');
    $eXss = $emp('<img src=x onerror=window.__xss=8>', '66666666000166');
    $emp('Empresa Inativa', '44444444000144', 0);
    $rnTotem = new TotemGestaoRn($pdo, $urlBase);
    $criarTotem = static function (int $empresa, string $nome) use ($rnTotem, $idAdmin): int {
        $r = $rnTotem->criar($idAdmin, $empresa, $nome, '203.0.113.5');
        if (!($r['ok'] ?? false)) {
            throw new RuntimeException('semente de totem falhou: ' . ($r['codigo'] ?? '?'));
        }

        return (int) $r['id'];
    };
    $idGuiche = $criarTotem($eMaua1, 'Guiche 04');
    $idDoca = $criarTotem($eMaua2, 'Doca 02');
    $idInativo = $criarTotem($eMaua1, 'Balcao 09');
    $rnTotem->definirAtivo($idAdmin, $idInativo, false, true, '203.0.113.5');
    $idAtend = $criarTotem($eMaua1, 'Balcao 01');
    $criarTotem($eLonga, 'ABCDEFGHIJKLMNOPQRSTUVWX');
    $pdo->prepare("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, atualizado_em) VALUES (:c, :t, 'expedicao', 'em_andamento', NOW())")->execute(['c' => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), 't' => $idAtend]);
    $legado = static function (string $codigo, string $nome, ?int $empresa) use ($pdo): int {
        $pdo->prepare('INSERT INTO tb_totem (codigo, nome, id_empresa, token_api, ativo) VALUES (:c, :n, :e, :t, 1)')->execute(['c' => $codigo, 'n' => $nome, 'e' => $empresa, 't' => bin2hex(random_bytes(32))]);

        return (int) $pdo->lastInsertId();
    };
    $legado('RECEPCAO-01', 'RECEPCAO-01', $eMaua1);
    $legado('XSS_LEGADO', '<img src=x onerror=window.__xss=7>', $eXss);

    // F3c (logs): linhas semeadas nas 5 abas (ERRO/AVISO/INFO, mensagem longa, repeticoes, 120 linhas na API para
    // paginacao, texto hostil). Cron e Gestao nunca tem totem. Categorias e mensagens sao as do catalogo.
    $seqLog = 0;
    $log = static function (string $nivel, string $origem, string $cat, string $msg, ?int $idTotem, ?string $detalhe, int $contador, string $quando) use ($pdo, &$seqLog): int {
        $seqLog++;
        $pdo->prepare(
            "INSERT INTO tb_log_sistema (nivel, origem, categoria, mensagem, id_totem, detalhe, dedup_chave, janela, contador, criado_em, ultima_ocorrencia)
             VALUES (:n, :o, :c, :m, :t, :d, :k, NOW(), :ct, $quando, $quando)"
        )->execute(['n' => $nivel, 'o' => $origem, 'c' => $cat, 'm' => $msg, 't' => $idTotem, 'd' => $detalhe, 'k' => sha1('lay' . $seqLog), 'ct' => $contador]);

        return (int) $pdo->lastInsertId();
    };
    $niveisLog = ['ERRO', 'AVISO', 'INFO'];
    $totensLog = [$idGuiche, $idDoca, null, $idAtend];
    $idLogHostil = $log('ERRO', 'API', 'erro_tecnico', '<img src=x onerror=window.__xss=11>"><script>window.__xss=12</script>', null, 'classe=<img src=x onerror=window.__xss=13>;http=500', 3, 'NOW()');
    for ($i = 1; $i <= 120; $i++) {
        $log($niveisLog[$i % 3], 'API', $i % 2 === 0 ? 'erro_tecnico' : 'totem_nao_autorizado', $i % 2 === 0 ? 'Erro técnico inesperado.' : 'Requisição à API com token de totem informado e inválido.', $totensLog[$i % 4], $i % 7 === 0 ? 'classe=PDOException;sqlstate=HY000;http=500' : null, ($i % 5) + 1, "NOW() - INTERVAL $i MINUTE");
    }
    $log('ERRO', 'API', 'banco_coletas_indisponivel', 'Banco de gestão de coletas indisponível.', null, null, 1, 'NOW() - INTERVAL 30 DAY');
    $mensagemLonga = str_repeat('Falha ao consultar a ordem de coleta no banco de gestão de coletas e ao tentar novamente em seguida. ', 2);
    $mensagemLonga = substr($mensagemLonga, 0, 158) . '.';
    $idLongo = $log('ERRO', 'EXPEDICAO', 'oc_consulta_falhou', $mensagemLonga, $idDoca, 'classe=PDOException;http=500;motivo=timeout', 4000000000, 'NOW() - INTERVAL 5 MINUTE');
    $log('AVISO', 'EXPEDICAO', 'oc_baixa_falhou', str_repeat('SemEspacoNenhumNaMensagemMuitoLonga', 4) . 'Fim', $idGuiche, null, 1, 'NOW() - INTERVAL 6 MINUTE');
    $log('INFO', 'EXPEDICAO', 'erro_tecnico', 'Mensagem curta.', null, null, 1, 'NOW() - INTERVAL 7 MINUTE');
    for ($i = 1; $i <= 3; $i++) {
        $log($niveisLog[$i % 3], 'RECEBIMENTO', 'rate_limit_ocr_excedido', 'Limite de leitura de notas excedido pelo totem.', $idGuiche, null, $i, "NOW() - INTERVAL $i HOUR");
    }
    $idCronInfo = $log('INFO', 'CRON', 'cron_resumo', 'Rotina agendada concluída.', null, 'job=limpar_logs_gestao;logs_apagados=3;auditoria_apagados=0;lotes=1', 1, 'NOW() - INTERVAL 2 HOUR');
    $log('ERRO', 'CRON', 'cron_falhou', 'Rotina agendada falhou.', null, 'classe=PDOException;job=limpar_logs_gestao;motivo=erro_banco', 2, 'NOW() - INTERVAL 3 HOUR');
    $log('AVISO', 'CRON', 'cron_falhou', 'Rotina agendada falhou.', null, null, 1, 'NOW() - INTERVAL 4 HOUR');
    $log('ERRO', 'GESTAO', 'gestao_erro_interno', 'Erro interno na Gestão Totem.', null, null, 1, 'NOW() - INTERVAL 1 HOUR');
    $log('AVISO', 'GESTAO', 'auditoria_falhou', 'Falha ao gravar a trilha de auditoria.', null, 'motivo=auditoria_indisponivel', 2, 'NOW() - INTERVAL 2 HOUR');
    $idsLog = ['hostil' => $idLogHostil, 'longo' => $idLongo, 'cron' => $idCronInfo, 'semDetalhe' => (int) $pdo->query("SELECT id_log FROM tb_log_sistema WHERE origem = 'GESTAO' AND detalhe IS NULL LIMIT 1")->fetchColumn()];

    // F4 (ordens de coleta): semente do banco externo QA. 4 abas: ativas (ativa recente, ativa > 15 dias, mesmo numero em
    // 2 clientes, numero de 50 caracteres, texto hostil, 60 linhas para paginar), inativas (PDF disponivel, apagado, sem PDF)
    // e baixas pendentes/resolvida (OC localizada, nao localizada, ambigua, hostil, numero longo, 30+ linhas para paginar).
    $dias = static fn (int $d): string => date('Y-m-d H:i:s', time() - $d * 86400);
    $horas = static fn (int $h): string => date('Y-m-d H:i:s', time() - $h * 3600);
    $cnpjA = '11111111000111';
    $cnpjB = '22222222000122';
    $cnpjC = '33333333000133';
    $cnpjX = '44444444000144';
    $cnpjL = '77777777000177';
    $cnpjT = '55555555000155';
    $cA = ogCliente($ext, $cnpjA, 'ACME LOGISTICA SA');
    $cB = ogCliente($ext, $cnpjB, 'BETA TRANSPORTES LTDA');
    $cC = ogCliente($ext, $cnpjC, 'GAMA COMERCIO SA');
    $cX = ogCliente($ext, $cnpjX, '<img src=x onerror=window.__xss=31>"><script>window.__xss=32</script>');
    $cL = ogCliente($ext, $cnpjL, 'COMERCIO E DISTRIBUICAO DE PRODUTOS ALIMENTICIOS E BEBIDAS NACIONAIS E IMPORTADOS DO SUL DO BRASIL LTDA ME');
    $numHostil = '<img src=x onerror=window.__xss=33>';
    $numLongo = 'N' . str_repeat('1234567890', 4) . '123456789';
    $pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $gravarPdf = static function (string $cnpj, string $numero) use ($ext, $storage, $pdfBytes): void {
        $rel = $cnpj . '/' . $numero . '_20260101000000.pdf';
        @mkdir($storage . '/ordens_coleta/' . $cnpj, 0700, true);
        file_put_contents($storage . '/ordens_coleta/' . $rel, $pdfBytes);
        $ext->prepare('INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256) VALUES (:c, :n, :p, :t, :h)')
            ->execute(['c' => $cnpj, 'n' => $numero, 'p' => $rel, 't' => strlen($pdfBytes), 'h' => hash('sha256', $pdfBytes)]);
    };
    $oAt = ogOrdem($ext, $cA, 'LAY-1001', 'ATIVA', $dias(3), null, ['placa_prevista' => 'ABC1D23', 'motorista_nome_previsto' => 'JOAO DA SILVA', 'cnh_prevista' => '12345678901', 'transportadora_nome' => 'TRANSPORTADORA RAPIDA LTDA', 'transportadora_cnpj' => $cnpjT]);
    $gravarPdf($cnpjA, 'LAY-1001');
    $oAt15 = ogOrdem($ext, $cA, 'LAY-1002', 'ATIVA', $dias(20), null, ['placa_prevista' => 'XYZ9K88', 'motorista_nome_previsto' => 'MARIA SOUZA', 'cnh_prevista' => '98765432100', 'transportadora_nome' => 'TRANSPORTE LENTO SA', 'transportadora_cnpj' => $cnpjT]);
    $gravarPdf($cnpjA, 'LAY-1002');
    $oAus = ogOrdem($ext, $cB, 'LAY-1003', 'ATIVA', $dias(2));
    for ($i = 1; $i <= 5; $i++) {
        ogOrdem($ext, $cB, 'OLD-' . $i, 'ATIVA', $dias(15 + $i * 5), null, ['transportadora_nome' => 'TRANSPORTE LENTO SA']);
    }
    $oIn = ogOrdem($ext, $cA, 'LAY-2001', 'INATIVA', $dias(10), $dias(3), ['placa_prevista' => 'DEF2G34', 'motorista_nome_previsto' => 'PEDRO LIMA', 'cnh_prevista' => '11122233344']);
    $gravarPdf($cnpjA, 'LAY-2001');
    $oInApag = ogOrdem($ext, $cA, 'LAY-2002', 'INATIVA', $dias(30), $dias(20));
    $oInSem = ogOrdem($ext, $cB, 'LAY-2003', 'INATIVA', $dias(5), $dias(2));
    $oDupA = ogOrdem($ext, $cA, 'LAY-DUP', 'ATIVA', $dias(1));
    $oDupB = ogOrdem($ext, $cB, 'LAY-DUP', 'ATIVA', $dias(2));
    $oConf = ogOrdem($ext, $cA, 'LAY-CONF', 'ATIVA', $dias(2));
    $oConfA = ogOrdem($ext, $cA, 'LAY-CONFA', 'INATIVA', $dias(6), $dias(1));
    // cliente INATIVO (tb_clientes.status): aviso no detalhe, na confirmacao de ativar e na lista (aba Inativas)
    $cnpjI = '88888888000188';
    $cI = ogCliente($ext, $cnpjI, 'DELTA CLIENTE INATIVO SA', 'INATIVO');
    $oCliInat = ogOrdem($ext, $cI, 'LAY-CLIINAT', 'INATIVA', $dias(4), $dias(1));
    $oHostil = ogOrdem($ext, $cX, $numHostil, 'ATIVA', $dias(1), null, ['placa_prevista' => '"><b>X</b>', 'motorista_nome_previsto' => '"><script>window.__xss=34</script>', 'cnh_prevista' => '<i>1</i>', 'transportadora_nome' => '<img src=x onerror=window.__xss=35>', 'transportadora_cnpj' => $cnpjT]);
    $oLongo = ogOrdem($ext, $cL, $numLongo, 'ATIVA', $dias(18), null, ['placa_prevista' => 'ABCDEFGHIJ', 'motorista_nome_previsto' => str_repeat('MOTORISTA', 14), 'cnh_prevista' => '12345678901234567890', 'transportadora_nome' => 'TRANSPORTADORA' . str_repeat('X', 120), 'transportadora_cnpj' => $cnpjT]);
    $gravarPdf($cnpjL, $numLongo);
    $ext->exec('START TRANSACTION');
    for ($i = 1; $i <= 60; $i++) {
        ogOrdem($ext, $cC, sprintf('PG-%04d', $i), 'ATIVA', $horas($i));
    }
    $ext->exec('COMMIT');

    // atendimentos (banco do totem) para a confirmacao e para as baixas (atualizados ha 2 dias: fora da janela de "atendimento recente" da lista de totens)
    $mkAt = static function (?string $numero, ?string $cnpj, string $status) use ($pdo, $idAtend): int {
        $pdo->prepare("INSERT INTO tb_atendimento (codigo_publico, id_totem, tipo, status, ordem_coleta, cliente_cnpj, atualizado_em) VALUES (:c, :t, 'expedicao', :s, :o, :j, NOW() - INTERVAL 2 DAY)")
            ->execute(['c' => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), 't' => $idAtend, 's' => $status, 'o' => $numero, 'j' => $cnpj]);

        return (int) $pdo->lastInsertId();
    };
    $mkAt('LAY-CONF', $cnpjA, 'em_andamento');
    $mkAt('LAY-CONFA', $cnpjA, 'concluido');
    $mkBaixa = static function (?string $cnpj, string $numero, bool $resolvida = false) use ($pdo, $mkAt): int {
        $idAt = $mkAt($numero, $cnpj, 'concluido');
        $pdo->prepare('INSERT INTO tb_ordem_coleta_pendente_baixa (id_atendimento, numero_ordem_coleta) VALUES (:a, :n)')->execute(['a' => $idAt, 'n' => $numero]);
        $idB = (int) $pdo->lastInsertId();
        if ($resolvida) {
            $pdo->prepare('UPDATE tb_ordem_coleta_pendente_baixa SET resolvido_em = NOW() WHERE id = :i')->execute(['i' => $idB]);
        }

        return $idB;
    };
    // ambiguidade real: dois clientes com o MESMO cnpj (indice unico removido so neste banco QA)
    $ext->exec('ALTER TABLE tb_clientes DROP INDEX uk_clientes_cnpj');
    $cnpjD = '66666666000166';
    $cD1 = ogCliente($ext, $cnpjD, 'DUPLICADO 1');
    $cD2 = ogCliente($ext, $cnpjD, 'DUPLICADO 2');
    ogOrdem($ext, $cD1, 'AMB-1', 'INATIVA', $dias(5), $dias(2));
    ogOrdem($ext, $cD2, 'AMB-1', 'INATIVA', $dias(5), $dias(2));
    // as 30 baixas "sem cliente" primeiro (ids menores): a pagina 1 (mais recentes) mostra os 3 estados da OC
    for ($i = 1; $i <= 30; $i++) {
        $mkBaixa(null, 'SEM-' . $i);
    }
    $mkBaixa($cnpjA, 'LAY-2001');
    $mkBaixa($cnpjA, 'NAO-EXISTE-9');
    $mkBaixa($cnpjX, $numHostil);
    $mkBaixa($cnpjL, $numLongo);
    $mkBaixa($cnpjA, 'LAY-2001', true);
    $mkBaixa($cnpjD, 'AMB-1');
    $idsOrdens = ['ativa' => $oAt, 'ativa15' => $oAt15, 'ausente' => $oAus, 'inativa' => $oIn, 'apagado' => $oInApag, 'inativaSemPdf' => $oInSem, 'dupA' => $oDupA, 'dupB' => $oDupB, 'conf' => $oConf, 'confA' => $oConfA, 'cliInativo' => $oCliInat, 'cliInativoConf' => $oCliInat, 'hostil' => $oHostil, 'longo' => $oLongo, 'numLongo' => $numLongo];

    $env = array_merge(gtEnvPadrao(), ['GESTAO_PERMITIR_HTTP' => 'true', 'TOTEM_URL_BASE' => $urlBase]);
    putenv('QA_GESTAO_ENV_JSON=' . json_encode($env));
    $raiz = dirname(__DIR__, 2);
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $porta, '-t', $raiz . '/public', '-d', 'auto_prepend_file=' . __DIR__ . '/qa_gestao_prepend.php', '-d', 'display_errors=0', '-d', 'log_errors=0'];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/qa_gestao_layout_srv.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/qa_gestao_layout_srv.log', 'w']], $pipes, $raiz);
    if (!is_resource($proc)) {
        throw new RuntimeException('nao subiu o servidor');
    }
    // espera a porta aceitar conexao
    $pronto = false;
    for ($i = 0; $i < 50 && !$pronto; $i++) {
        $s = @fsockopen('127.0.0.1', $porta, $en, $es, 0.2);
        if ($s !== false) {
            fclose($s);
            $pronto = true;
        } else {
            usleep(100000);
        }
    }
    if (!$pronto) {
        throw new RuntimeException('servidor nao respondeu');
    }
    echo json_encode(['pronto' => true, 'porta' => $porta, 'banco' => $banco, 'senha' => GT_SENHA_BOA, 'logs' => $idsLog, 'ordens' => $idsOrdens]) . "\n";
    fflush(STDOUT);
    // bloqueia ate o node mandar "fim" ou fechar o stdin
    while (($linha = fgets(STDIN)) !== false) {
        if (trim($linha) === 'fim') {
            break;
        }
        if (trim($linha) === 'esvaziar-logs') { // estado vazio da tela de logs
            $pdo->exec('DELETE FROM tb_log_sistema');
            echo "LOGS-ESVAZIADOS
";
            fflush(STDOUT);
        }
        if (trim($linha) === 'esvaziar-ordens') { // estado vazio da tela de ordens (so os bancos QA)
            $pdo->exec('DELETE FROM tb_ordem_coleta_pendente_baixa');
            $ext->exec('DELETE FROM tb_ordem_coleta_arquivos');
            $ext->exec('DELETE FROM tb_ordens_coleta');
            echo "ORDENS-ESVAZIADAS\n";
            fflush(STDOUT);
        }
        if (trim($linha) === 'esvaziar') { // estado vazio da lista de totens (captura/teste)
            $pdo->exec('DELETE FROM tb_atendimento');
            $pdo->exec('DELETE FROM tb_totem');
            echo "ESVAZIADO\n";
            fflush(STDOUT);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    gtDestruirAmbiente(null, $storage);
    ogLimpar($amb);
    echo "LIMPO\n";
}
