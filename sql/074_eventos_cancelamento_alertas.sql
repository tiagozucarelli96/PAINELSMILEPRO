-- Alertas de cancelamento de eventos ME para gerentes de eventos por unidade.

CREATE TABLE IF NOT EXISTS eventos_cancelamento_alertas (
    id BIGSERIAL PRIMARY KEY,
    me_event_id BIGINT NOT NULL,
    usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    nome_evento TEXT NOT NULL,
    data_evento DATE NULL,
    local_evento TEXT NULL,
    space_visivel TEXT NULL,
    mensagem TEXT NOT NULL,
    email_enviado_em TIMESTAMP NULL,
    popup_visto_em TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (me_event_id, usuario_id)
);

CREATE INDEX IF NOT EXISTS idx_eventos_cancelamento_alertas_usuario_popup
    ON eventos_cancelamento_alertas(usuario_id, popup_visto_em, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_eventos_cancelamento_alertas_evento
    ON eventos_cancelamento_alertas(me_event_id);
