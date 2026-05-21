-- Central de Notificações dos eventos

ALTER TABLE usuarios
ADD COLUMN IF NOT EXISTS perm_notificacoes_eventos BOOLEAN DEFAULT FALSE;

UPDATE usuarios
SET perm_notificacoes_eventos = TRUE
WHERE COALESCE(perm_superadmin, FALSE) = TRUE;

CREATE TABLE IF NOT EXISTS eventos_notificacoes_central (
    id BIGSERIAL PRIMARY KEY,
    meeting_id BIGINT NOT NULL,
    tipo VARCHAR(80) NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    mensagem TEXT NULL,
    target_url TEXT NOT NULL,
    reference_key VARCHAR(220) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_eventos_notificacoes_central_ref
    ON eventos_notificacoes_central(reference_key);

CREATE INDEX IF NOT EXISTS idx_eventos_notificacoes_central_created
    ON eventos_notificacoes_central(created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_eventos_notificacoes_central_meeting
    ON eventos_notificacoes_central(meeting_id, created_at DESC);

CREATE TABLE IF NOT EXISTS eventos_notificacoes_central_ignorados (
    notificacao_id BIGINT NOT NULL REFERENCES eventos_notificacoes_central(id) ON DELETE CASCADE,
    usuario_id BIGINT NOT NULL,
    ignored_at TIMESTAMP NOT NULL DEFAULT NOW(),
    PRIMARY KEY (notificacao_id, usuario_id)
);

CREATE INDEX IF NOT EXISTS idx_eventos_notificacoes_central_ign_user
    ON eventos_notificacoes_central_ignorados(usuario_id, ignored_at DESC);

