-- 095_agenda_google_reverse_sync.sql
-- Vinculo entre eventos internos da Agenda e eventos criados no Google Calendar.

ALTER TABLE agenda_eventos
    ADD COLUMN IF NOT EXISTS google_calendar_id VARCHAR(255),
    ADD COLUMN IF NOT EXISTS google_event_id VARCHAR(255),
    ADD COLUMN IF NOT EXISTS google_sync_status VARCHAR(20),
    ADD COLUMN IF NOT EXISTS google_sync_error TEXT,
    ADD COLUMN IF NOT EXISTS google_synced_at TIMESTAMP;

CREATE UNIQUE INDEX IF NOT EXISTS idx_agenda_eventos_google_event
    ON agenda_eventos(google_calendar_id, google_event_id)
    WHERE google_event_id IS NOT NULL;
