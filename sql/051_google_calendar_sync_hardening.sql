-- 051_google_calendar_sync_hardening.sql
-- Correções de schema para sincronização automática do Google Calendar.

ALTER TABLE google_calendar_config
ADD COLUMN IF NOT EXISTS webhook_channel_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS webhook_resource_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS webhook_expiration TIMESTAMP,
ADD COLUMN IF NOT EXISTS precisa_sincronizar BOOLEAN DEFAULT FALSE;

UPDATE google_calendar_config
SET precisa_sincronizar = FALSE
WHERE precisa_sincronizar IS NULL;

CREATE INDEX IF NOT EXISTS idx_google_calendar_config_webhook ON google_calendar_config(webhook_resource_id);
CREATE INDEX IF NOT EXISTS idx_google_calendar_config_sync ON google_calendar_config(ativo, precisa_sincronizar);
