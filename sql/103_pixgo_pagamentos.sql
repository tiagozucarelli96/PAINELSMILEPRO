-- Integração PixGo para cobranças de eventos e formaturas.

ALTER TABLE IF EXISTS eventos_financeiro_receitas
    ADD COLUMN IF NOT EXISTS pixgo_payment_id VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS pixgo_payment_url TEXT NULL,
    ADD COLUMN IF NOT EXISTS pixgo_qr_code TEXT NULL,
    ADD COLUMN IF NOT EXISTS pixgo_qr_image_url TEXT NULL,
    ADD COLUMN IF NOT EXISTS pixgo_expires_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS pixgo_idempotency_key VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS pixgo_payload JSONB NULL;

ALTER TABLE IF EXISTS eventos_formatura_financeiro
    ADD COLUMN IF NOT EXISTS pixgo_payment_id VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS pixgo_payment_url TEXT NULL,
    ADD COLUMN IF NOT EXISTS pixgo_qr_code TEXT NULL,
    ADD COLUMN IF NOT EXISTS pixgo_qr_image_url TEXT NULL,
    ADD COLUMN IF NOT EXISTS pixgo_expires_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS pixgo_idempotency_key VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS pixgo_payload JSONB NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_pixgo
    ON eventos_financeiro_receitas(pixgo_payment_id)
    WHERE pixgo_payment_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_formatura_financeiro_pixgo
    ON eventos_formatura_financeiro(pixgo_payment_id)
    WHERE pixgo_payment_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS pixgo_webhook_events (
    id BIGSERIAL PRIMARY KEY,
    payment_id VARCHAR(120) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    signature TEXT NULL,
    payload_raw TEXT NOT NULL,
    signature_valid BOOLEAN NOT NULL DEFAULT FALSE,
    processing_status VARCHAR(30) NOT NULL DEFAULT 'recebido',
    error_message TEXT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processed_at TIMESTAMPTZ NULL,
    UNIQUE(payment_id, event_type)
);

CREATE INDEX IF NOT EXISTS idx_pixgo_webhook_events_received
    ON pixgo_webhook_events(received_at DESC);
