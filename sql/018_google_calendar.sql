-- 018_google_calendar.sql
-- Integração Google Calendar - OAuth e Sincronização

-- Tabela de tokens OAuth do Google (configuração global do sistema)
CREATE TABLE IF NOT EXISTS google_calendar_tokens (
    id SERIAL PRIMARY KEY,
    access_token TEXT NOT NULL,
    refresh_token TEXT,
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_at TIMESTAMP,
    scope TEXT,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de configuração do Google Calendar
CREATE TABLE IF NOT EXISTS google_calendar_config (
    id SERIAL PRIMARY KEY,
    google_calendar_id VARCHAR(255) NOT NULL,
    google_calendar_name VARCHAR(255),
    sync_dias_futuro INT DEFAULT 180,
    ultima_sincronizacao TIMESTAMP,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de eventos sincronizados do Google Calendar
CREATE TABLE IF NOT EXISTS google_calendar_eventos (
    id SERIAL PRIMARY KEY,
    google_calendar_id VARCHAR(255) NOT NULL,
    google_event_id VARCHAR(255) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    inicio TIMESTAMP NOT NULL,
    fim TIMESTAMP NOT NULL,
    localizacao VARCHAR(255),
    organizador_email VARCHAR(255),
    status VARCHAR(50) DEFAULT 'confirmed',
    html_link TEXT,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW(),
    UNIQUE(google_calendar_id, google_event_id)
);

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_google_calendar_eventos_calendar ON google_calendar_eventos(google_calendar_id);
CREATE INDEX IF NOT EXISTS idx_google_calendar_eventos_event ON google_calendar_eventos(google_event_id);
CREATE INDEX IF NOT EXISTS idx_google_calendar_eventos_inicio ON google_calendar_eventos(inicio);
CREATE INDEX IF NOT EXISTS idx_google_calendar_eventos_fim ON google_calendar_eventos(fim);

-- Tabela de logs de sincronização
CREATE TABLE IF NOT EXISTS google_calendar_sync_logs (
    id SERIAL PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL CHECK (tipo IN ('importado', 'atualizado', 'erro')),
    total_eventos INT DEFAULT 0,
    detalhes JSONB,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Índice para logs
CREATE INDEX IF NOT EXISTS idx_google_calendar_sync_logs_tipo ON google_calendar_sync_logs(tipo);
CREATE INDEX IF NOT EXISTS idx_google_calendar_sync_logs_criado ON google_calendar_sync_logs(criado_em DESC);
