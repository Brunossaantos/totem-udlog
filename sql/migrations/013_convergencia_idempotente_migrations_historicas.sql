-- Migration 013 — convergencia idempotente dos efeitos de 001/002 + novo
-- indice de limpeza de tb_rate_limit_ocr
--
-- Demanda: robustez-rate-limit-migrations (2026-09-18). Decisao do usuario
-- (confirmada apos handoff docs/handoffs/2026-09-18-robustez-rate-limit-migrations.md,
-- que registrava divergencia entre backend-especialista e security-especialista):
-- opcao (b) — criar uma migration NOVA e idempotente, em vez de editar
-- diretamente 001/002.
--
-- ============================================================
-- LEIA ANTES DE APLICAR — papel desta migration em relacao a 001/002
-- ============================================================
-- (a) sql/migrations/001_uk_atendimento_nota_ordem.sql e
--     sql/migrations/002_status_ocr_atendimento_nota.sql CONTINUAM sendo o
--     bootstrap historico original destes objetos de schema — preservadas
--     SEM NENHUMA ALTERACAO por esta migration ou por esta demanda. Nada
--     aqui reescreve, corrige ou substitui o conteudo desses 2 arquivos.
-- (b) 001 e 002 NAO devem ser reaplicadas em um banco ja inicializado —
--     essa orientacao ja existia nos proprios arquivos e continua valendo
--     integralmente (001 pode abortar com "Duplicate key name" numa
--     reexecucao; 002 pode abortar com "Duplicate column name" e deixar o
--     indice idx_status_ocr faltando silenciosamente numa reexecucao
--     parcial). Esta migration 013 NAO torna 001/002 seguras para
--     reexecucao — continue seguindo a orientacao original de nao
--     reaplica-las.
-- (c) Instalacoes JA EXISTENTES (banco que ja rodou 001/002 alguma vez, com
--     sucesso total, parcial, ou state incerto) devem convergir para o
--     estado final esperado rodando ESTA migration 013 — ela e idempotente
--     de verdade (padrao PREPARE/EXECUTE condicional contra
--     INFORMATION_SCHEMA, o mesmo ja usado e validado em
--     sql/migrations/003_tb_cliente_razao_normalizada.sql) e cada objeto
--     (indice/coluna) e verificado e aplicado de forma INDEPENDENTE dos
--     demais — um bloco faltando ou ja existente nunca aborta os outros.
-- (d) Uma instalacao NOVA (banco vazio) roda a sequencia completa uma unica
--     vez, na ordem normal: sql/schema.sql, depois todas as migrations em
--     ordem numerica (001, 002, 003, ..., 013). sql/schema.sql ja cria
--     tb_atendimento_nota com uk_atendimento_ordem, status_ocr,
--     processado_em e idx_status_ocr desde o inicio — nesse caso, todos os
--     blocos condicionais desta migration 013 fazem SELECT 1 (no-op) e o
--     unico efeito real e o indice NOVO idx_rate_limit_ocr_limpeza.
-- (e) IMPORTANTE — esta migration NAO afirma, e NAO deve ser interpretada
--     como afirmando, que 001/002 "se tornaram idempotentes". Elas
--     continuam EXATAMENTE como estavam, com as mesmas limitacoes
--     originais (PASSO incondicional que pode abortar o arquivo numa
--     reexecucao). A mitigacao de robustez/idempotencia desta demanda e
--     atribuida EXCLUSIVAMENTE a este arquivo (013) e ao procedimento de
--     deploy documentado em docs/deploy-checklist.md — nao a uma mudanca de
--     comportamento de 001/002 em si.
--
-- ============================================================
-- O que esta migration faz
-- ============================================================
-- 1. Converge com seguranca os objetos finais esperados de 001 (indice
--    UNIQUE uk_atendimento_ordem sobre tb_atendimento_nota(id_atendimento,
--    ordem)) e de 002 (colunas status_ocr/processado_em em
--    tb_atendimento_nota + indice idx_status_ocr), cada um checado e
--    aplicado de forma INDEPENDENTE.
-- 2. Adiciona o indice NOVO idx_rate_limit_ocr_limpeza em
--    tb_rate_limit_ocr(atualizado_em, janela) — necessario para o DELETE em
--    lote do cron cron/limpar-rate-limit-ocr.php
--    (RateLimitOcrDao::apagarJanelasExpiradas) localizar linhas expiradas
--    sem full table scan; a PK composta (id_totem, janela) de
--    tb_rate_limit_ocr nao serve sozinha para esse filtro. Ver
--    sql/migrations/004_tb_rate_limit_ocr.sql (onde essa pendencia ja estava
--    registrada nos comentarios finais).
--
--    Coluna lider do indice: atualizado_em (filtro realmente seletivo da
--    query real de apagarJanelasExpiradas() — `WHERE atualizado_em <
--    FROM_UNIXTIME(:corte) AND janela != :a AND janela != :b`); janela
--    mantida como segunda coluna para Index Condition Pushdown do filtro de
--    desigualdade, sem acessar a linha completa via PK. Confirmado por
--    EXPLAIN real em rodada anterior desta demanda (ver handoff) que um
--    indice liderado por `janela` sozinho NUNCA e escolhido pelo otimizador
--    (seletividade ~0% do filtro `!=` contra milhares de janelas distintas).
--
-- ============================================================
-- REESCRITA SEM STORED PROCEDURE (rodada curta de /01-implementacao apos
-- /02-testes independente ter encontrado que a versao anterior deste
-- arquivo exigia os privilegios CREATE ROUTINE e ALTER ROUTINE — nao
-- confirmados como disponiveis no usuario de banco do Hostgator real, e
-- fora do conjunto ja usado pelas migrations anteriores do projeto)
-- ============================================================
-- Esta versao usa SOMENTE: INFORMATION_SCHEMA, variaveis de sessao
-- (SET @var := ...), PREPARE/EXECUTE/DEALLOCATE PREPARE de SQL dinamico, e
-- ALTER TABLE — mesmo padrao basico ja usado em 001/002/003. NENHUMA
-- CREATE/DROP/CALL PROCEDURE, NENHUMA rotina/funcao/trigger/evento,
-- NENHUM privilegio de rotina exigido.
--
-- (1) Checagem de TIPO divergente (PASSO 0, status_ocr/processado_em) —
--     sem stored procedure nao ha bloco IF/SIGNAL disponivel fora de uma
--     rotina armazenada em SQL puro. Solucao adotada: quando o tipo/
--     nulabilidade da coluna ja existente diverge do esperado, o SQL
--     dinamico gerado e um SELECT contra uma tabela que deliberadamente NAO
--     EXISTE (nome autoexplicativo, prefixado com o numero da migration,
--     84-87 caracteres, excedendo o limite de 64 caracteres para nomes de
--     tabela do MySQL/MariaDB) — o proprio MySQL/MariaDB rejeita o
--     identificador como sintaticamente invalido ANTES de tentar localizar
--     a tabela, com o erro nativo "ERROR 1103 (42000): Incorrect table
--     name '...'" (ER_WRONG_TABLE_NAME). CORRECAO (confirmado
--     empiricamente em /03-revisao de 2026-09-19): uma versao anterior
--     deste comentario afirmava erroneamente que o erro seria "Table ...
--     doesn't exist" (ER_NO_SUCH_TABLE, ERROR 1146) — na pratica isso
--     NUNCA ocorre aqui, pois o parser rejeita o identificador invalido
--     antes de sequer tentar localizar a tabela. O efeito pratico e
--     identico nos dois casos: abortando o script inteiro de forma
--     deterministica, sem exigir SIGNAL nem privilegio de rotina. A
--     mensagem nativa resultante so contem o nome da tabela inventada (sem
--     credencial/dado pessoal). Decisao documentada: essa checagem fica
--     tecnicamente EQUIVALENTE a antes (mesmo criterio de tipo/nulabilidade,
--     mesmo efeito de abortar o script), so troca o MECANISMO de aborto
--     (erro nativo de tabela inexistente em vez de SIGNAL customizado).
-- (2) Colisao de NOME esperado com colunas diferentes (PASSOS 1, 4 e 5) —
--     a versao anterior usava uma stored procedure auxiliar
--     (_migracao_013_abortar_se_colisao) para detectar e abortar
--     explicitamente esse cenario ANTES de tentar o ALTER TABLE. Esta
--     versao remove essa deteccao antecipada e delega ao proprio
--     `ALTER TABLE ... ADD INDEX <nome_esperado> (...)` nativo: se o nome
--     esperado ja estiver ocupado por outro indice com colunas/ordem
--     diferentes, o ALTER TABLE falha nativamente com "Duplicate key name",
--     abortando o script — exatamente a estrategia pedida ("primeiro
--     procure indice estruturalmente equivalente; se nao existir, tente
--     criar o indice com o nome esperado; se o nome estiver ocupado por
--     estrutura incorreta, deixar a criacao falhar de maneira
--     deterministica"). O comportamento final observado pelo operador do
--     deploy e o mesmo (script aborta, nenhum objeto incorreto e criado,
--     nenhum dado alterado/apagado) — so a origem da mensagem de erro muda
--     (nativa do MySQL/MariaDB em vez de customizada via SIGNAL).
--
-- ============================================================
-- Reconhecimento de indice EQUIVALENTE, nao so de nome identico
-- ============================================================
-- Para os 3 indices convergidos por esta migration (uk_atendimento_ordem,
-- idx_status_ocr, idx_rate_limit_ocr_limpeza), a checagem de existencia NAO
-- usa so INDEX_NAME = '...' (diferente de 001/002/003) — em vez disso,
-- agrupa INFORMATION_SCHEMA.STATISTICS por INDEX_NAME e verifica se ALGUM
-- indice da tabela cobre EXATAMENTE o mesmo conjunto/ordem de colunas
-- esperado (nem a mais, nem a menos), independente do nome que esse indice
-- tenha recebido. Isso evita criar um indice DUPLICADO funcionalmente
-- equivalente com nome diferente, caso uma instalacao real tenha aplicado
-- o mesmo indice manualmente com outro nome. Logica exata (ver os blocos
-- SET @indice_..._existe abaixo): GROUP BY INDEX_NAME HAVING contagem de
-- colunas da tabela == numero de colunas esperado E cada coluna esperada
-- aparece na posicao (SEQ_IN_INDEX) correta.
--
-- Se NENHUM indice equivalente existir, a migration tenta criar o indice
-- com o NOME ESPERADO diretamente via ALTER TABLE — se esse nome ja
-- estiver ocupado por um indice com colunas erradas, o proprio ALTER TABLE
-- falha nativamente (ver secao "REESCRITA SEM STORED PROCEDURE" acima).
--
-- ============================================================
-- Garantias gerais
-- ============================================================
-- Nenhum DROP, TRUNCATE ou renomeacao destrutiva em nenhum ponto deste
-- arquivo. Todos os blocos sao ADD COLUMN / ADD INDEX / ADD UNIQUE KEY
-- condicionais, ou a checagem de tipo (que so pode ABORTAR o script via
-- erro nativo de tabela inexistente, nunca alterar/apagar dado).
-- Compativel com MySQL 5.x+/toda a linha MariaDB (mesmo padrao ja validado
-- em 003); privilegios necessarios: SELECT sobre INFORMATION_SCHEMA (leitura
-- padrao, sempre disponivel) e ALTER/CREATE/INDEX no schema do totem — os
-- MESMOS ja exigidos por 001/002/003, NENHUM privilegio de rotina
-- (CREATE ROUTINE/ALTER ROUTINE/EXECUTE) e necessario nesta versao.
--
-- ============================================================
-- Rollback manual (nenhum automatico — mesmo padrao ja usado no projeto)
-- ============================================================
-- ATENCAO: so execute os comandos abaixo se tiver certeza de que o objeto
-- foi de fato criado por ESTA migration (e nao ja existia antes, vindo de
-- 001/002/sql/schema.sql) — confirme antes via SHOW INDEX FROM/SHOW COLUMNS
-- FROM (ver docs/deploy-checklist.md, secao desta demanda).
--   ALTER TABLE tb_rate_limit_ocr DROP INDEX idx_rate_limit_ocr_limpeza;
--   ALTER TABLE tb_atendimento_nota DROP INDEX idx_status_ocr;
--   ALTER TABLE tb_atendimento_nota DROP COLUMN processado_em;
--   ALTER TABLE tb_atendimento_nota DROP COLUMN status_ocr;
--   ALTER TABLE tb_atendimento_nota DROP INDEX uk_atendimento_ordem;
--
-- Aplicada MANUALMENTE via phpMyAdmin/cPanel/terminal — sem executor de
-- migration automatico no projeto (Hostgator, hospedagem compartilhada).
-- Backup do banco OBRIGATORIO antes de aplicar (ver docs/deploy-checklist.md).

SET NAMES utf8mb4;

-- ============================================================
-- PASSO 0 — checagem de TIPO divergente para status_ocr/processado_em
-- (aborta o script inteiro via erro nativo de "tabela inexistente" se
-- encontrar divergencia; nao altera nem apaga nenhum dado; ver secao
-- "REESCRITA SEM STORED PROCEDURE" no topo do arquivo)
-- ============================================================
SET @tipo_status_ocr_diverge := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento_nota'
      AND COLUMN_NAME = 'status_ocr'
      AND (
            COLUMN_TYPE <> "enum('PENDENTE','PROCESSANDO','IDENTIFICADA','NAO_IDENTIFICADA','ERRO')"
            OR IS_NULLABLE <> 'NO'
      )
);

SET @sql_checagem_status_ocr := (
    SELECT IF(
        @tipo_status_ocr_diverge = 1,
        'SELECT 1 FROM `migracao_013_abortar__status_ocr_tipo_ou_nulabilidade_divergente_corrija_manualmente`',
        'SELECT 1'
    )
);
PREPARE stmt_checagem_status_ocr FROM @sql_checagem_status_ocr;
EXECUTE stmt_checagem_status_ocr;
DEALLOCATE PREPARE stmt_checagem_status_ocr;

SET @tipo_processado_em_diverge := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento_nota'
      AND COLUMN_NAME = 'processado_em'
      AND (
            COLUMN_TYPE <> 'datetime'
            OR IS_NULLABLE <> 'YES'
      )
);

SET @sql_checagem_processado_em := (
    SELECT IF(
        @tipo_processado_em_diverge = 1,
        'SELECT 1 FROM `migracao_013_abortar__processado_em_tipo_ou_nulabilidade_divergente_corrija_manualmente`',
        'SELECT 1'
    )
);
PREPARE stmt_checagem_processado_em FROM @sql_checagem_processado_em;
EXECUTE stmt_checagem_processado_em;
DEALLOCATE PREPARE stmt_checagem_processado_em;

-- ============================================================
-- PASSO 1 — indice UNIQUE equivalente a uk_atendimento_ordem (efeito de 001)
-- ============================================================
-- "Equivalente" = algum indice de tb_atendimento_nota com EXATAMENTE 2
-- colunas, na ordem (1) id_atendimento (2) ordem — independente do nome.
SET @indice_uk_atendimento_ordem_existe := (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tb_atendimento_nota'
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 2
           AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'id_atendimento' THEN 1 ELSE 0 END) = 1
           AND SUM(CASE WHEN SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'ordem' THEN 1 ELSE 0 END) = 1
    ) indices_equivalentes
);

-- Se nao houver indice equivalente, tenta criar com o NOME ESPERADO
-- diretamente — se esse nome ja estiver ocupado por um indice com colunas
-- erradas, o proprio ALTER TABLE falha nativamente com "Duplicate key
-- name" (ver secao "REESCRITA SEM STORED PROCEDURE" no topo do arquivo).
SET @ddl_uk_atendimento_ordem := (
    SELECT IF(
        @indice_uk_atendimento_ordem_existe = 0,
        'ALTER TABLE tb_atendimento_nota ADD UNIQUE KEY uk_atendimento_ordem (id_atendimento, ordem)',
        'SELECT 1'
    )
);
PREPARE stmt_uk_atendimento_ordem FROM @ddl_uk_atendimento_ordem;
EXECUTE stmt_uk_atendimento_ordem;
DEALLOCATE PREPARE stmt_uk_atendimento_ordem;

-- ============================================================
-- PASSO 2 — coluna status_ocr (efeito de 002, aplicada de forma
-- independente da coluna processado_em e do indice idx_status_ocr)
-- ============================================================
SET @coluna_status_ocr_existe := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento_nota'
      AND COLUMN_NAME = 'status_ocr'
);

SET @ddl_status_ocr := (
    SELECT IF(
        @coluna_status_ocr_existe = 0,
        "ALTER TABLE tb_atendimento_nota ADD COLUMN status_ocr ENUM('PENDENTE','PROCESSANDO','IDENTIFICADA','NAO_IDENTIFICADA','ERRO') NOT NULL DEFAULT 'PENDENTE' AFTER cliente_identificado",
        'SELECT 1'
    )
);
PREPARE stmt_status_ocr FROM @ddl_status_ocr;
EXECUTE stmt_status_ocr;
DEALLOCATE PREPARE stmt_status_ocr;

-- ============================================================
-- PASSO 3 — coluna processado_em (efeito de 002, independente do PASSO 2)
-- ============================================================
SET @coluna_processado_em_existe := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_atendimento_nota'
      AND COLUMN_NAME = 'processado_em'
);

-- AFTER status_ocr so faz sentido se a coluna ja existir neste ponto —
-- como o PASSO 2 acima ja garante isso (ou ja existia antes), esta
-- referencia e sempre valida quando este ALTER precisa rodar.
SET @ddl_processado_em := (
    SELECT IF(
        @coluna_processado_em_existe = 0,
        'ALTER TABLE tb_atendimento_nota ADD COLUMN processado_em DATETIME NULL AFTER status_ocr',
        'SELECT 1'
    )
);
PREPARE stmt_processado_em FROM @ddl_processado_em;
EXECUTE stmt_processado_em;
DEALLOCATE PREPARE stmt_processado_em;

-- ============================================================
-- PASSO 4 — indice equivalente a idx_status_ocr (efeito de 002, independente
-- das colunas acima)
-- ============================================================
-- "Equivalente" = algum indice de tb_atendimento_nota com EXATAMENTE 1
-- coluna: status_ocr — independente do nome. So verificado se a coluna
-- status_ocr existir de fato (senao o indice nao pode ser criado de
-- qualquer forma — situacao que so ocorreria se o PASSO 2 tivesse sido
-- pulado por engano, o que nao acontece neste arquivo).
SET @indice_status_ocr_existe := (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tb_atendimento_nota'
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1
           AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'status_ocr' THEN 1 ELSE 0 END) = 1
    ) indices_equivalentes
);

-- Se nao houver indice equivalente, tenta criar com o NOME ESPERADO
-- diretamente — mesma estrategia do PASSO 1 (colisao de nome com colunas
-- erradas falha nativamente via "Duplicate key name").
SET @ddl_idx_status_ocr := (
    SELECT IF(
        @indice_status_ocr_existe = 0,
        'ALTER TABLE tb_atendimento_nota ADD INDEX idx_status_ocr (status_ocr)',
        'SELECT 1'
    )
);
PREPARE stmt_idx_status_ocr FROM @ddl_idx_status_ocr;
EXECUTE stmt_idx_status_ocr;
DEALLOCATE PREPARE stmt_idx_status_ocr;

-- ============================================================
-- PASSO 5 — indice NOVO idx_rate_limit_ocr_limpeza em
-- tb_rate_limit_ocr(atualizado_em, janela) (necessario para o DELETE em
-- lote do cron de limpeza — ver cron/limpar-rate-limit-ocr.php e
-- app/Dao/RateLimitOcrDao.php::apagarJanelasExpiradas)
-- ============================================================
-- "Equivalente" = algum indice de tb_rate_limit_ocr com EXATAMENTE 2
-- colunas, na ordem (1) atualizado_em (2) janela — independente do nome.
SET @indice_rate_limit_limpeza_existe := (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tb_rate_limit_ocr'
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 2
           AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'atualizado_em' THEN 1 ELSE 0 END) = 1
           AND SUM(CASE WHEN SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'janela' THEN 1 ELSE 0 END) = 1
    ) indices_equivalentes
);

-- Se nao houver indice equivalente, tenta criar com o NOME ESPERADO
-- diretamente — mesma estrategia dos PASSOS 1 e 4 (colisao de nome com
-- colunas erradas falha nativamente via "Duplicate key name"), mantendo o
-- indice EXATAMENTE como estava (atualizado_em, janela) — nenhuma mudanca
-- nesta rodada alem da remocao da stored procedure auxiliar.
SET @ddl_idx_rate_limit_limpeza := (
    SELECT IF(
        @indice_rate_limit_limpeza_existe = 0,
        'ALTER TABLE tb_rate_limit_ocr ADD INDEX idx_rate_limit_ocr_limpeza (atualizado_em, janela)',
        'SELECT 1'
    )
);
PREPARE stmt_idx_rate_limit_limpeza FROM @ddl_idx_rate_limit_limpeza;
EXECUTE stmt_idx_rate_limit_limpeza;
DEALLOCATE PREPARE stmt_idx_rate_limit_limpeza;
