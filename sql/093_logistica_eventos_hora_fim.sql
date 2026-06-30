-- 093_logistica_eventos_hora_fim.sql
-- Persiste o horário de término vindo da ME Eventos na Agenda Geral.

ALTER TABLE IF EXISTS logistica_eventos_espelho
    ADD COLUMN IF NOT EXISTS hora_fim TIME;

CREATE INDEX IF NOT EXISTS idx_logistica_eventos_hora_fim
    ON logistica_eventos_espelho (hora_fim);
