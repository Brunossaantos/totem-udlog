-- Migration 003 — adiciona razao_social_normalizada em tb_cliente e faz o
-- seed inicial de 38 clientes (lista fechada fornecida pelo usuario em
-- 2026-09-08, ver docs/handoffs/2026-09-08-recebimento-clientes-tabela-local.md)
--
-- Contexto: demanda de substituicao da API externa de clientes
-- (App\Rn\ClienteApiClient) por consulta local para a identificacao
-- automatica de cliente via OCR nas notas do Recebimento (NotaFiscalRn::
-- identificarCliente). Decisao aprovada explicitamente pelo usuario:
-- reaproveitar a tabela JA EXISTENTE tb_cliente (singular, ja usada hoje
-- por public/api/cliente.php - autocomplete - e por NotaFiscalRn::
-- processarLeitura - identificacao por chave de acesso NF-e), preservando
-- as colunas ja existentes (nome, cnpj VARCHAR(20)) exatamente como estao.
-- Adiciona SOMENTE a coluna razao_social_normalizada, usada pelo fuzzy
-- match (Util\RazaoSocialMatcher::melhorCandidato) para nao precisar
-- normalizar a listagem inteira em tempo real a cada chamada.
--
-- Efeito colateral INTENCIONAL (aprovado pelo usuario, nao e bug): os 38
-- clientes desta lista passam a aparecer tambem no autocomplete manual
-- (cliente.php) e na identificacao antiga por chave de acesso NF-e
-- (processarLeitura/ClienteDao::buscarPorCnpj), pois os tres fluxos leem a
-- MESMA tabela tb_cliente.
--
-- Esta migration NUNCA apaga/altera dado existente. Coluna nova e
-- NULL/opcional; o INSERT do seed usa INSERT IGNORE (idempotente via a
-- UNIQUE ja existente em cnpj) e nao sobrescreve nenhuma linha ja
-- cadastrada manualmente com o mesmo CNPJ.
--
-- Revisao de 2026-09-08 (achados ALTOS da etapa /03-revisao): a versao
-- anterior deste arquivo usava "PASSO 1/3 (checagem manual, so leitura) +
-- PASSO 2/4 (ALTER, so rodar se o passo anterior retornasse 0)" pensada
-- para execucao PASSO A PASSO em phpMyAdmin. Isso quebra quando o arquivo e
-- executado de uma vez so (`mysql <banco> < arquivo.sql`, forma como o
-- cPanel/terminal tambem pode rodar e como o qa-testes reproduziu o bug):
-- se a coluna/indice ja existir (ex: instalacao nova, sql/schema.sql
-- atualizado ja cria a coluna), o ALTER falha com "Duplicate column name" e
-- o cliente `mysql` ABORTA o restante do arquivo, nunca rodando o PASSO 5
-- (seed dos 38 clientes). Reescrito para o padrao de SQL preparado
-- condicional (idempotente de verdade, nao apenas "checagem manual previa")
-- — mesmo raciocinio de sql/migrations/002_status_ocr_atendimento_nota.sql,
-- so que ali a checagem MANUAL ainda era segura porque este arquivo (003)
-- e o unico do projeto com seed de dado que precisa rodar
-- INCONDICIONALMENTE ate o fim, mesmo se o ALTER nao precisar fazer nada.
-- Tambem adicionado "SET NAMES utf8mb4" explicito, ja que sem isso o
-- charset padrao do cliente que executa o script (ex: cp850 no Windows)
-- corrompe os caracteres acentuados dos nomes/razoes sociais dos clientes
-- (mesmo problema de mojibake ja visto e corrigido manualmente antes nesta
-- lista).
--
-- Aplicada MANUALMENTE via phpMyAdmin/cPanel/terminal (sem mecanismo de
-- migration automatica no projeto - hospedagem Hostgator compartilhada).
-- Para instalacoes novas, sql/schema.sql ja cria tb_cliente com a coluna e
-- o seed nao esta embutido no schema (mesmo padrao ja usado pela migration
-- 002 para dado estrutural x dado de seed) - rode este arquivo tambem em
-- banco novo se quiser os 38 clientes ja carregados (o PASSO 1/2 abaixo nao
-- faz nada nesse caso, pois a coluna ja existe, e o PASSO 3 do seed roda
-- normalmente ate o fim).

-- Charset da CONEXAO desta sessao de migration — independente de como o
-- cliente mysql foi invocado (evita mojibake nos nomes/razoes sociais com
-- acento abaixo).
SET NAMES utf8mb4;

-- ============================================================
-- PASSO 1 — coluna razao_social_normalizada (idempotente, nao aborta)
-- ============================================================
-- SQL preparado condicional: se a coluna ja existir, executa "SELECT 1"
-- (no-op) em vez do ALTER — nunca lanca "Duplicate column name", entao o
-- cliente mysql nunca aborta o restante do arquivo por causa deste PASSO,
-- mesmo rodando o arquivo inteiro de uma vez (`mysql <banco> < arquivo.sql`).
SET @ddl_coluna := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_cliente ADD COLUMN razao_social_normalizada VARCHAR(150) NULL AFTER nome',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_cliente'
      AND COLUMN_NAME = 'razao_social_normalizada'
);
PREPARE stmt_coluna FROM @ddl_coluna;
EXECUTE stmt_coluna;
DEALLOCATE PREPARE stmt_coluna;

-- ============================================================
-- PASSO 2 — indice para o fuzzy match (idempotente, nao aborta)
-- ============================================================
-- Mesmo padrao do PASSO 1, agora para o indice.
SET @ddl_indice := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tb_cliente ADD INDEX idx_razao_social_normalizada (razao_social_normalizada)',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tb_cliente'
      AND INDEX_NAME = 'idx_razao_social_normalizada'
);
PREPARE stmt_indice FROM @ddl_indice;
EXECUTE stmt_indice;
DEALLOCATE PREPARE stmt_indice;

-- ============================================================
-- PASSO 3 — seed dos 38 clientes (lista fechada, aprovada pelo usuario)
-- ============================================================
-- razao_social_normalizada pre-computada com o MESMO algoritmo de
-- Util\RazaoSocialMatcher::normalizar() (uppercase, transliteracao ASCII,
-- remocao de pontuacao/sufixos societarios, colapso de espacos) — gerado
-- por script auxiliar reaproveitando a propria classe via Reflection (nao
-- reimplementado a mao), rodado uma vez para gerar este INSERT. CNPJ ja
-- validado (digito verificador modulo 11, Util\CnpjValidador) e sem
-- mascara (14 digitos), conferido: sem duplicidade de CNPJ nem de razao
-- social normalizada entre os 38 registros.
-- INSERT IGNORE: idempotente via a UNIQUE ja existente em cnpj — rodar
-- este passo mais de uma vez nao duplica nem sobrescreve nenhuma linha.
-- Este PASSO roda SEMPRE, independente do PASSO 1/2 terem precisado fazer
-- algo ou nao (nenhum DDL acima pode abortar o script agora).
INSERT IGNORE INTO tb_cliente (nome, razao_social_normalizada, cnpj, ativo) VALUES
    ('AKRO-PLASTIC DO BRASIL INDUSTRIA E COMERCIO DE POLIMEROS DE', 'AKRO PLASTIC DO BRASIL INDUSTRIA E COMERCIO DE POLIMEROS DE', '20200104000238', 1),
    ('ALLIANCE', 'ALLIANCE', '17658250000116', 1),
    ('ALTO TIETE COMERCIO DE PRODUTOS QUIMICOS LTDA', 'ALTO TIETE COMERCIO DE PRODUTOS QUIMICOS', '34690550000100', 1),
    ('ALX BRASIL COMERCIO DE PRODUTOS QUIMICOS EIRELI', 'ALX BRASIL COMERCIO DE PRODUTOS QUIMICOS', '18604543000264', 1),
    ('AMITECCO IMPORTACAO, EXPORTACAO E COMERCIO DE PRODUTOS QUIMI', 'AMITECCO IMPORTACAO EXPORTACAO E COMERCIO DE PRODUTOS QUIMI', '53146300000220', 1),
    ('ATIAS MIHAEL COM DE PRODUTOS QUIMICOS LTDA', 'ATIAS MIHAEL COM DE PRODUTOS QUIMICOS', '60756970000143', 1),
    ('BARENTZ BRASIL DISTRIBUIDORA LTDA (04)', 'BARENTZ BRASIL DISTRIBUIDORA 04', '69170462000404', 1),
    ('BARENTZ BRASIL DISTRIBUIDORA LTDA (49)', 'BARENTZ BRASIL DISTRIBUIDORA 49', '69170462000749', 1),
    ('BLUE CUBE BRASIL COMÉRCIO DE PRODUTOS QUÍMICOS LTDA', 'BLUE CUBE BRASIL COM ERCIO DE PRODUTOS QU IMICOS', '20920259000168', 1),
    ('CARGILL AGRÍCOLA S/A', 'CARGILL AGR ICOLA', '60498706007836', 1),
    ('CARGILL AGRICOLA S/A (62)', 'CARGILL AGRICOLA 62', '60498706010462', 1),
    ('CITROSUCO S/A AGROINDUSTRIA', 'CITROSUCO AGROINDUSTRIA', '33010786000187', 1),
    ('CLARIANT BRASIL LTDA.', 'CLARIANT BRASIL', '31452113002529', 1),
    ('COLORNET COMERCIO EXTERIOR LTDA FILIAL', 'COLORNET COMERCIO EXTERIOR FILIAL', '01382160000377', 1),
    ('CP KELCO BRASIL S/A - MATÃO', 'CP KELCO BRASIL MAT AO', '54105671000731', 1),
    ('CP KELCO BRASIL S/A. - LIMEIRA', 'CP KELCO BRASIL LIMEIRA', '54105671000146', 1),
    ('CROMEX S.A - FILIAL', 'CROMEX FILIAL', '02271463000202', 1),
    ('EFFICAX INDUSTRIA E COMERCIO LTDA', 'EFFICAX INDUSTRIA E COMERCIO', '29182197000109', 1),
    ('ENERGIS 8 AGROQUIMICA LTDA (07)', 'ENERGIS 8 AGROQUIMICA 07', '03805416000507', 1),
    ('FLAVOR TEC-AROMAS DE FRUTAS LTDA', 'FLAVOR TEC AROMAS DE FRUTAS', '00997636000150', 1),
    ('HAIFA QUÍMICA DO BRASIL LTDA', 'HAIFA QU IMICA DO BRASIL', '03347353000150', 1),
    ('HIGHTEC POLYMERS COMERCIO, IMPORTAÇÃO E EXPORTAÇÃO LTDA', 'HIGHTEC POLYMERS COMERCIO IMPORTAC AO E EXPORTAC AO', '17750611000150', 1),
    ('INEOS STYROLUTION DO BRASIL POLIMEROS LTDA', 'INEOS STYROLUTION DO BRASIL POLIMEROS', '12487655000204', 1),
    ('INTERCOM COMÉRCIO DE PRODUTOS QUIMICOS LTDA', 'INTERCOM COM ERCIO DE PRODUTOS QUIMICOS', '60858412000199', 1),
    ('ITOCHU BRASIL S/A', 'ITOCHU BRASIL', '61274155000100', 1),
    ('LOUIS DREYFUS COMPANY SUCOS - MATAO', 'LOUIS DREYFUS COMPANY SUCOS MATAO', '00831373000295', 1),
    ('LOUIS DREYFUS COMPANY SUCOS S/A - BEBEDOURO', 'LOUIS DREYFUS COMPANY SUCOS BEBEDOURO', '00831373003715', 1),
    ('METACHEM INDUSTRIAL E COMERCIAL LTDA (93)', 'METACHEM INDUSTRIAL E COMERCIAL 93', '58656166000493', 1),
    ('MONFIZA COMERCIO E IMPORTADORA LTDA', 'MONFIZA COMERCIO E IMPORTADORA', '22518066000100', 1),
    ('MOURA CHEMICAL LTDA', 'MOURA CHEMICAL', '18678739000112', 1),
    ('NEXIRA BRASIL COMERCIAL LTDA', 'NEXIRA BRASIL COMERCIAL', '62326012000168', 1),
    ('NEXO INTERNATIONAL COMÉCIO DE PRODUTOS QUÍMICOS LTDA', 'NEXO INTERNATIONAL COM ECIO DE PRODUTOS QU IMICOS', '05835040000177', 1),
    ('NICROM QUIMICA LTDA', 'NICROM QUIMICA', '68060193000100', 1),
    ('OMYA DO BRASIL MPORTAÇÃO, EXPORTAÇÃO E COMÉRCIO DE MINERAIS', 'OMYA DO BRASIL MPORTAC AO EXPORTAC AO E COM ERCIO DE MINERAIS', '05969945000482', 1),
    ('TEIJIN ARAMID DO BRASIL', 'TEIJIN ARAMID DO BRASIL', '04139934000160', 1),
    ('TICONA POLYMERS LTDA', 'TICONA POLYMERS', '01808103000226', 1),
    ('UNIPAR INDUPA DO BRASIL S/A', 'UNIPAR INDUPA DO BRASIL', '61460325000494', 1),
    ('WINTERHALTER', 'WINTERHALTER', '12210279000117', 1);
