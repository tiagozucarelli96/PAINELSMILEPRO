-- 106_agenda_eventos_performance.sql
-- Índices específicos para acelerar a abertura mensal da Agenda Geral.

CREATE INDEX IF NOT EXISTS idx_logistica_eventos_espelho_ativos_data
ON logistica_eventos_espelho (data_evento, hora_inicio, id)
WHERE COALESCE(arquivado, FALSE) = FALSE;

CREATE INDEX IF NOT EXISTS idx_logistica_eventos_espelho_ativos_space_data
ON logistica_eventos_espelho ((TRIM(COALESCE(space_visivel, ''))), data_evento)
WHERE COALESCE(arquivado, FALSE) = FALSE;

CREATE INDEX IF NOT EXISTS idx_eventos_reunioes_me_event_updated
ON eventos_reunioes (me_event_id, updated_at DESC NULLS LAST, id DESC);
