<?php

/**
 * Migrations 020 e 021 (Gestao Totem, F1, 2026-10-06): idempotencia (aplicar 2x),
 * estrutura, equivalencia com sql/schema.sql e higiene do arquivo (sem CHECK,
 * sem coluna gerada, sem ADD COLUMN IF NOT EXISTS, sem ponto e virgula em
 * comentario). Banco QA descartavel; nunca udlog_totem.
 *
 * Uso: php tests/manual/teste_gestao_migrations.php
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_gestao_infra.php';

$raiz = dirname(__DIR__, 2);
$bancoVazio = null;
$bancoCompleto = null;
try {
    // ---- higiene dos arquivos
    foreach (['020_gestao_usuario_sessao.sql', '021_gestao_auditoria.sql'] as $arq) {
        $sql = (string) file_get_contents($raiz . '/sql/migrations/' . $arq);
        $semComentarios = preg_replace('/^--.*$/m', '', $sql);
        afirmar("$arq: sem CHECK", stripos($semComentarios, 'CHECK (') === false && stripos($semComentarios, 'CHECK(') === false);
        afirmar("$arq: sem coluna gerada", stripos($semComentarios, 'GENERATED') === false && stripos($semComentarios, ' AS (') === false);
        afirmar("$arq: sem ADD COLUMN IF NOT EXISTS", stripos($semComentarios, 'ADD COLUMN IF NOT EXISTS') === false);
        $comentarioComPontoEVirgula = 0;
        foreach (preg_split('/\R/', $sql) as $linha) {
            if (str_starts_with($linha, '--') && str_contains($linha, ';')) {
                $comentarioComPontoEVirgula++;
            }
        }
        afirmar("$arq: nenhum comentario com ponto e virgula (loader dos QA)", $comentarioComPontoEVirgula === 0);
        afirmar("$arq: cabecalho com Problema, Solucao, Idempotencia e REVERSAO", str_contains($sql, 'Problema') && str_contains($sql, 'Solucao') && str_contains($sql, 'Idempotencia') && str_contains($sql, 'REVERSAO'));
        afirmar("$arq: so CREATE TABLE IF NOT EXISTS (nada destrutivo)", preg_match('/\b(DROP\s+(TABLE|COLUMN|INDEX)|TRUNCATE|DELETE\s+FROM|ALTER\s+TABLE)\b|^\s*UPDATE\s/im', $semComentarios) !== 1);
    }

    // ---- aplicar duas vezes num banco VAZIO (sem schema.sql)
    $config = qaQrConfiguracao();
    $admin = qaQrPdoServidor($config);
    $bancoVazio = qaQrNomeBanco();
    qaQrValidarNomeBanco($bancoVazio);
    $admin->exec("CREATE DATABASE `{$bancoVazio}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("USE `{$bancoVazio}`");
    $m20 = $raiz . '/sql/migrations/020_gestao_usuario_sessao.sql';
    $m21 = $raiz . '/sql/migrations/021_gestao_auditoria.sql';

    foreach ([1, 2] as $rodada) {
        $erro = null;
        try {
            qaQrAplicarSql($admin, $m20);
            qaQrAplicarSql($admin, $m21);
        } catch (Throwable $e) {
            $erro = get_class($e);
        }
        afirmar("rodada $rodada: 020 e 021 aplicam sem erro", $erro === null);
        if ($rodada === 1) {
            $admin->exec("INSERT INTO tb_gestao_usuario (login, nome, perfil, senha_hash) VALUES ('ana.silva', 'Ana', 'admin', 'x')");
            $admin->exec("INSERT INTO tb_gestao_auditoria (acao, resultado) VALUES ('LOGIN_OK', 'OK')");
        }
    }
    afirmar('reaplicar nao apaga nem duplica dados (1 usuario, 1 auditoria)', (int) $admin->query('SELECT COUNT(*) FROM tb_gestao_usuario')->fetchColumn() === 1 && (int) $admin->query('SELECT COUNT(*) FROM tb_gestao_auditoria')->fetchColumn() === 1);

    $tabelas = $admin->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    afirmar('so as 4 tabelas esperadas existem', $tabelas === ['tb_gestao_auditoria', 'tb_gestao_login_tentativa', 'tb_gestao_sessao', 'tb_gestao_usuario']);

    $col = static function (PDO $p, string $t, string $c): ?array {
        $s = $p->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $s->execute(['t' => $t, 'c' => $c]);

        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $c = $col($admin, 'tb_gestao_usuario', 'perfil');
    afirmar("tb_gestao_usuario.perfil ENUM('admin','usuario')", $c !== null && $c['COLUMN_TYPE'] === "enum('admin','usuario')");
    $c = $col($admin, 'tb_gestao_usuario', 'login');
    afirmar('tb_gestao_usuario.login VARCHAR(60) com indice UNIQUE', $c !== null && $c['COLUMN_TYPE'] === 'varchar(60)' && $c['COLUMN_KEY'] === 'UNI');
    $c = $col($admin, 'tb_gestao_usuario', 'criado_por');
    afirmar('tb_gestao_usuario.criado_por NULL', $c !== null && $c['IS_NULLABLE'] === 'YES');
    foreach (['senha_hash' => 'varchar(255)', 'nome' => 'varchar(100)', 'ativo' => 'tinyint(1)', 'deve_trocar_senha' => 'tinyint(1)'] as $nome => $tipo) {
        $c = $col($admin, 'tb_gestao_usuario', $nome);
        afirmar("tb_gestao_usuario.$nome $tipo", $c !== null && $c['COLUMN_TYPE'] === $tipo);
    }
    foreach (['tentativas_falhas', 'bloqueado_ate', 'ultimo_login_em', 'senha_alterada_em', 'criado_em', 'atualizado_em'] as $nome) {
        afirmar("tb_gestao_usuario.$nome existe", $col($admin, 'tb_gestao_usuario', $nome) !== null);
    }
    $c = $col($admin, 'tb_gestao_usuario', 'senha_versao');
    afirmar('tb_gestao_usuario.senha_versao INT UNSIGNED NOT NULL DEFAULT 1 (B7)', $c !== null && $c['COLUMN_TYPE'] === 'int(10) unsigned' && $c['IS_NULLABLE'] === 'NO' && (string) $c['COLUMN_DEFAULT'] === '1');
    $c = $col($admin, 'tb_gestao_sessao', 'id_sessao');
    afirmar('tb_gestao_sessao.id_sessao CHAR(64) PRIMARY KEY', $c !== null && $c['COLUMN_TYPE'] === 'char(64)' && $c['COLUMN_KEY'] === 'PRI');
    $c = $col($admin, 'tb_gestao_sessao', 'csrf_token');
    afirmar('tb_gestao_sessao.csrf_token CHAR(64)', $c !== null && $c['COLUMN_TYPE'] === 'char(64)');
    foreach (['id_usuario', 'criado_em', 'ultimo_acesso_em', 'expira_em', 'ua_hash'] as $nome) {
        afirmar("tb_gestao_sessao.$nome existe", $col($admin, 'tb_gestao_sessao', $nome) !== null);
    }
    $pk = $admin->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_gestao_login_tentativa' AND CONSTRAINT_NAME = 'PRIMARY'")->fetchColumn();
    afirmar('tb_gestao_login_tentativa PK (ip_hash, janela)', $pk === 'ip_hash,janela');
    $fk = $admin->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_gestao_sessao' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->fetchColumn();
    afirmar('tb_gestao_sessao tem FK para o usuario', (int) $fk === 1);
    $c = $col($admin, 'tb_gestao_auditoria', 'resultado');
    afirmar("tb_gestao_auditoria.resultado ENUM('PENDENTE','OK','SEM_EFEITO','ERRO')", $c !== null && $c['COLUMN_TYPE'] === "enum('PENDENTE','OK','SEM_EFEITO','ERRO')");
    $c = $col($admin, 'tb_gestao_auditoria', 'ip');
    afirmar('tb_gestao_auditoria.ip VARBINARY(16)', $c !== null && $c['COLUMN_TYPE'] === 'varbinary(16)');
    $c = $col($admin, 'tb_gestao_auditoria', 'detalhe');
    afirmar('tb_gestao_auditoria.detalhe VARCHAR(255)', $c !== null && $c['COLUMN_TYPE'] === 'varchar(255)');
    $idx = $admin->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_gestao_auditoria' AND INDEX_NAME <> 'PRIMARY'")->fetchColumn();
    afirmar('tb_gestao_auditoria tem 4 indices alem da PK', (int) $idx === 4);
    $fkAud = $admin->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_gestao_auditoria' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->fetchColumn();
    afirmar('tb_gestao_auditoria sem FK (trilha independente)', (int) $fkAud === 0);

    // ---- equivalencia com schema.sql (banco completo do bootstrap)
    [$pdoCompleto, $bancoCompleto] = qaQrCriarBanco();
    $dump = static function (PDO $p): array {
        $s = $p->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tb\\_gestao\\_%' ORDER BY TABLE_NAME, ORDINAL_POSITION");

        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $pdoCompleto->exec("USE `{$bancoCompleto}`");
    afirmar('banco do bootstrap (schema.sql + 020 + 021) tem a mesma estrutura das migrations isoladas', $dump($pdoCompleto) === $dump($admin));
    qaQrAplicarSql($pdoCompleto, $m20);
    qaQrAplicarSql($pdoCompleto, $m21);
    afirmar('020/021 sobre schema.sql (tabelas ja existentes) e no-op', $dump($pdoCompleto) === $dump($admin));
} catch (Throwable $e) {
    afirmar('execucao sem excecao inesperada (' . get_class($e) . ')', false);
} finally {
    foreach ([$bancoVazio, $bancoCompleto] as $b) {
        if ($b !== null) {
            qaQrDroparBanco($b);
        }
    }
}
exit(gtResumo('teste_gestao_migrations'));
