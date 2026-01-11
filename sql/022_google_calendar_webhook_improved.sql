-- 022_google_calendar_webhook_improved.sql
-- Melhorias no sistema de webhook do Google Calendar

-- Adicionar colunas para gerenciar webhook e sincronização
ALTER TABLE google_calendar_config
ADD COLUMN IF NOT EXISTS webhook_channel_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS webhook_resource_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS webhook_expiration BIGINT, -- Timestamp em milissegundos
ADD COLUMN IF NOT EXISTS precisa_sincronizar BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS ultima_sincronizacao_at TIMESTAMP,
ADD COLUMN IF NOT EXISTS ultima_sincronizacao_resumo JSONB;

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_google_calendar_config_precisa_sync ON google_calendar_config(precisa_sincronizar) WHERE precisa_sincronizar = TRUE;
CREATE INDEX IF NOT EXISTS idx_google_calendar_config_webhook_expiration ON google_calendar_config(webhook_expiration) WHERE webhook_expiration IS NOT NULL;

-- Comentários
COMMENT ON COLUMN google_calendar_config.webhook_channel_id IS 'ID do canal do webhook (X-Goog-Channel-Id)';
COMMENT ON COLUMN google_calendar_config.webhook_resource_id IS 'ID do recurso do webhook (X-Goog-Resource-Id)';
COMMENT ON COLUMN google_calendar_config.webhook_expiration IS 'Timestamp de expiração do webhook em milissegundos';
COMMENT ON COLUMN google_calendar_config.precisa_sincronizar IS 'Flag indicando que o calendário precisa ser sincronizado';
COMMENT ON COLUMN google_calendar_config.ultima_sincronizacao_at IS 'Timestamp da última sincronização realizada';
COMMENT ON COLUMN google_calendar_config.ultima_sincronizacao_resumo IS 'Resumo da última sincronização (importados, atualizados, pulados)';
