-- 021_google_calendar_webhook.sql
-- Adicionar campos para webhook do Google Calendar

ALTER TABLE google_calendar_config 
ADD COLUMN IF NOT EXISTS webhook_resource_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS webhook_expiration TIMESTAMP;

CREATE INDEX IF NOT EXISTS idx_google_calendar_config_webhook ON google_calendar_config(webhook_resource_id);
