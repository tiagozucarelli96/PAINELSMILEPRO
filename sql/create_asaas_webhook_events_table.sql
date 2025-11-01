-- Tabela para armazenar eventos de webhook do Asaas (idempotência)
-- Conforme documentação: https://docs.asaas.com/docs/como-implementar-idempotencia-em-webhooks

CREATE TABLE IF NOT EXISTS asaas_webhook_events (
    id SERIAL PRIMARY KEY,
    asaas_event_id TEXT UNIQUE NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Índice para busca rápida por evento
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_event_id ON asaas_webhook_events(asaas_event_id);
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_event_type ON asaas_webhook_events(event_type);
CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_processed_at ON asaas_webhook_events(processed_at);

-- Comentário na tabela
COMMENT ON TABLE asaas_webhook_events IS 'Armazena eventos de webhook do Asaas para garantir idempotência (processar apenas uma vez)';
COMMENT ON COLUMN asaas_webhook_events.asaas_event_id IS 'ID único do evento retornado pelo Asaas (ex: evt_37260be8159d4472b4458d3de13efc2d)';
COMMENT ON COLUMN asaas_webhook_events.event_type IS 'Tipo do evento (ex: CHECKOUT_PAID, PAYMENT_RECEIVED, etc)';
COMMENT ON COLUMN asaas_webhook_events.payload IS 'Payload completo do webhook em JSON';

