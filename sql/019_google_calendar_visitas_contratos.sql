-- 019_google_calendar_visitas_contratos.sql
-- Adicionar campos para marcar eventos do Google como visita e contrato fechado

-- Adicionar campos na tabela de eventos do Google Calendar
ALTER TABLE google_calendar_eventos 
ADD COLUMN IF NOT EXISTS eh_visita_agendada BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS contrato_fechado BOOLEAN DEFAULT FALSE;

-- Índices para performance nas consultas
CREATE INDEX IF NOT EXISTS idx_google_calendar_eventos_visita ON google_calendar_eventos(eh_visita_agendada) WHERE eh_visita_agendada = TRUE;
CREATE INDEX IF NOT EXISTS idx_google_calendar_eventos_contrato ON google_calendar_eventos(contrato_fechado) WHERE contrato_fechado = TRUE;
