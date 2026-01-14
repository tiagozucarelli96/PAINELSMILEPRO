-- Adiciona nome do evento ao espelho
ALTER TABLE logistica_eventos_espelho
    ADD COLUMN IF NOT EXISTS nome_evento TEXT;

CREATE INDEX IF NOT EXISTS idx_logistica_eventos_nome_evento
    ON logistica_eventos_espelho (LOWER(nome_evento));
