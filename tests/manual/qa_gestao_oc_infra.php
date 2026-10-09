<?php

/**
 * Infra dos testes da F4a (Gestao Totem, Ordens de Coleta, 2026-10-08): DOIS
 * bancos QA descartaveis `qa_qr_exclusivo_<hex>`:
 *   - "totem": schema completo do banco do totem (tb_atendimento,
 *     tb_ordem_coleta_pendente_baixa, tb_gestao_auditoria...);
 *   - "externo": schema do banco de gestao de coletas fiel ao dump real
 *     (docs/udlogo59_db_gestao_coletas.sql: tb_clientes + tb_ordens_coleta com
 *     os indices reais e a FK para o cliente; as FKs de e-mail/motorista ficam
 *     de fora por falta das tabelas) + as migrations REAIS 001, 002 e 003 e,
 *     opcionalmente, a 004.
 * Os dois sao bancos DIFERENTES de proposito: um DAO que usar a conexao errada
 * falha em vez de passar por acaso. NUNCA toca em udlog_totem nem no banco
 * externo real; sem rede. As conexoes imitam as de producao (exceptions,
 * FETCH_ASSOC, time_zone -03:00, SEM MYSQL_ATTR_FOUND_ROWS).
 */
declare(strict_types=1);

require_once __DIR__ . '/qa_oc_anexo_infra.php';

function ogAbrir(string $banco, string $classe = PDO::class): PDO
{
    qaQrValidarNomeBanco($banco);
    $c = qaQrConfiguracao();
    $pdo = new $classe("mysql:host={$c['host']};port={$c['port']};dbname={$banco};charset=utf8mb4", $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $banco) {
        throw new RuntimeException('Sentinela do banco QA falhou');
    }

    return $pdo;
}

/**
 * @return array{totem: PDO, banco_totem: string, externo: PDO, banco_externo: string}
 */
function ogCriarAmbiente(bool $aplicar004 = true): array
{
    [$tmp, $bancoTotem] = qaQrCriarBanco();
    unset($tmp);
    $bancoExterno = null;
    try {
        $servidor = qaQrPdoServidor(qaQrConfiguracao());
        $bancoExterno = qaQrNomeBanco();
        qaQrValidarNomeBanco($bancoExterno);
        $servidor->exec("CREATE DATABASE `{$bancoExterno}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $ext = ogAbrir($bancoExterno);

        $ext->exec('CREATE TABLE tb_clientes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            razao_social VARCHAR(200) NOT NULL,
            cnpj VARCHAR(14) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            status ENUM(\'ATIVO\',\'INATIVO\') NOT NULL DEFAULT \'ATIVO\',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_clientes_cnpj (cnpj),
            KEY idx_clientes_razao_social (razao_social),
            KEY idx_clientes_email (email),
            KEY idx_clientes_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $ext->exec('CREATE TABLE tb_ordens_coleta (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            numero_ordem_coleta VARCHAR(50) NOT NULL,
            cliente_id BIGINT UNSIGNED NOT NULL,
            transportadora_nome VARCHAR(150) DEFAULT NULL,
            transportadora_cnpj VARCHAR(14) DEFAULT NULL,
            motorista_id BIGINT UNSIGNED DEFAULT NULL,
            email_recebido_id BIGINT UNSIGNED NOT NULL,
            placa_prevista VARCHAR(10) DEFAULT NULL,
            motorista_nome_previsto VARCHAR(150) DEFAULT NULL,
            cnh_prevista VARCHAR(20) DEFAULT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_ordem_cliente (cliente_id, numero_ordem_coleta),
            KEY idx_ordens_numero_ordem (numero_ordem_coleta),
            KEY idx_ordens_cliente_id (cliente_id),
            KEY idx_ordens_motorista_id (motorista_id),
            KEY idx_ordens_email_recebido_id (email_recebido_id),
            KEY idx_ordens_placa_prevista (placa_prevista),
            KEY idx_ordens_transportadora (transportadora_nome),
            CONSTRAINT fk_ordens_cliente FOREIGN KEY (cliente_id) REFERENCES tb_clientes (id) ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $raiz = dirname(__DIR__, 2) . '/sql/migrations_gestao_coletas/';
        $migrations = ['001_status_ordem_coleta.sql', '002_tb_ordem_coleta_arquivos.sql', '003_inativada_em_ordem_coleta.sql'];
        if ($aplicar004) {
            $migrations[] = '004_indices_gestao_oc.sql';
        }
        foreach ($migrations as $m) {
            ocQaAplicarMigration($ext, $raiz . $m);
        }

        return ['totem' => ogAbrir($bancoTotem), 'banco_totem' => $bancoTotem, 'externo' => $ext, 'banco_externo' => $bancoExterno];
    } catch (Throwable $e) {
        try {
            qaQrDroparBanco($bancoTotem);
            if ($bancoExterno !== null) {
                qaQrDroparBanco($bancoExterno);
            }
        } catch (Throwable) {
        }
        throw $e;
    }
}

function ogLimpar(?array $amb): void
{
    if ($amb === null) {
        return;
    }
    foreach (['banco_totem', 'banco_externo'] as $k) {
        if (!empty($amb[$k])) {
            qaQrDroparBanco($amb[$k]);
        }
    }
}

function ogCliente(PDO $ext, string $cnpj, string $razao = '', string $status = 'ATIVO'): int
{
    $ext->prepare('INSERT INTO tb_clientes (razao_social, cnpj, status) VALUES (:r, :c, :s)')
        ->execute(['r' => $razao !== '' ? $razao : 'CLIENTE ' . $cnpj, 'c' => $cnpj, 's' => $status]);

    return (int) $ext->lastInsertId();
}

/** @param array<string,mixed> $extra colunas opcionais (placa_prevista, criado_em, ...) */
function ogOrdem(PDO $ext, int $clienteId, string $numero, string $status = 'ATIVA', ?string $criadoEm = null, ?string $inativadaEm = null, array $extra = []): int
{
    $cols = ['numero_ordem_coleta' => $numero, 'cliente_id' => $clienteId, 'email_recebido_id' => 1, 'status' => $status, 'inativada_em' => $inativadaEm];
    if ($criadoEm !== null) {
        $cols['criado_em'] = $criadoEm;
    }
    $cols = array_merge($cols, $extra);
    $nomes = implode(',', array_keys($cols));
    $params = ':' . implode(',:', array_keys($cols));
    $ext->prepare("INSERT INTO tb_ordens_coleta ($nomes) VALUES ($params)")->execute($cols);

    return (int) $ext->lastInsertId();
}

function ogArquivo(PDO $ext, string $cnpj, string $numero, string $caminho = ''): int
{
    $caminho = $caminho !== '' ? $caminho : $cnpj . '/' . $numero . '_20260101000000.pdf';
    $ext->prepare('INSERT INTO tb_ordem_coleta_arquivos (cnpj_cliente, numero_ordem_coleta, caminho_relativo, tamanho_bytes, sha256) VALUES (:c, :n, :p, 600, :h)')
        ->execute(['c' => $cnpj, 'n' => $numero, 'p' => $caminho, 'h' => hash('sha256', $caminho)]);

    return (int) $ext->lastInsertId();
}
