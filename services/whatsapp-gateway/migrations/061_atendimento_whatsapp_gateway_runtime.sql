CREATE TABLE IF NOT EXISTS wa_session_credentials (
    session_key VARCHAR(120) PRIMARY KEY REFERENCES wa_inboxes(session_key) ON DELETE CASCADE,
    provider VARCHAR(40) NOT NULL,
    auth_state JSONB NULL,
    runtime_meta JSONB NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wa_gateway_deliveries (
    id BIGSERIAL PRIMARY KEY,
    session_key VARCHAR(120) NOT NULL REFERENCES wa_inboxes(session_key) ON DELETE CASCADE,
    direction VARCHAR(20) NOT NULL
        CHECK (direction IN ('inbound', 'outbound')),
    external_message_id VARCHAR(190) NULL,
    payload JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_wa_gateway_deliveries_session ON wa_gateway_deliveries(session_key, created_at DESC);
