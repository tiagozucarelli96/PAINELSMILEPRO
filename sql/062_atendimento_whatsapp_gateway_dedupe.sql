CREATE UNIQUE INDEX IF NOT EXISTS uq_wa_messages_conversation_external_message
ON wa_messages (conversation_id, external_message_id)
WHERE external_message_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_wa_gateway_deliveries_session_direction_external
ON wa_gateway_deliveries (session_key, direction, external_message_id)
WHERE external_message_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_wa_messages_external_message
ON wa_messages (external_message_id)
WHERE external_message_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_wa_gateway_deliveries_session_external
ON wa_gateway_deliveries (session_key, external_message_id)
WHERE external_message_id IS NOT NULL;
