-- Migration 019 -- Remocao da fila de reenvio ao Talent (demanda
-- remocao-fila-reenvio-talent, 2026-10-05).
--
-- Motivo: a fila (tb_fila_envio, App\Dao\FilaEnvioDao, cron/reenviar-fila.php)
-- foi removida por decisao do usuario. O reenvio tardio criaria check-in sem
-- etiqueta para motorista que ja saiu, recusas de negocio falham igual em
-- reenvios e o payload enfileirado ficava desatualizado apos a correcao do
-- ajudante. A fila nunca rodou em producao (nenhum cron agendado) e nada mais
-- referencia a tabela. O estado ERRO_REPROCESSAVEL continua valendo: so uma
-- nova chamada de finalizar() do totem tenta de novo.
--
-- Idempotente: DROP TABLE IF EXISTS e um no-op em banco novo (sql/schema.sql
-- ja nao cria mais a tabela) e em banco ja convergido. Compativel com MariaDB
-- 10.4 e MySQL 5.7.
--
-- ATENCAO (producao): aplicar SOMENTE depois do deploy do codigo sem a fila
-- (o codigo antigo faria INSERT na tabela e daria erro 500) e depois de
-- backup/dump da tabela.
--
-- REVERSAO (manual, so se necessario): o conteudo da tabela e perdido, so a
-- estrutura pode ser recriada. O CREATE TABLE original esta no historico git
-- de sql/schema.sql (bloco "Fila de reenvio quando a API do Talent falha",
-- por exemplo em git show 3bc0497:sql/schema.sql) e o conteudo, no dump feito
-- antes do DROP. Restaurar a tabela ANTES de reverter o commit do codigo.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS tb_fila_envio;
