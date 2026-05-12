-- 061_marketing_arquivos.sql
-- Biblioteca interna de imagens e videos para o modulo Marketing

CREATE TABLE IF NOT EXISTS marketing_arquivos (
    id BIGSERIAL PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    storage_key VARCHAR(500) NOT NULL,
    public_url TEXT NOT NULL,
    descricao TEXT NULL,
    media_kind VARCHAR(20) NOT NULL CHECK (media_kind IN ('imagem', 'video')),
    uploaded_by_user_id INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP NULL,
    deleted_by_user_id INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_marketing_arquivos_ativos
    ON marketing_arquivos (deleted_at, uploaded_at DESC);

CREATE INDEX IF NOT EXISTS idx_marketing_arquivos_tipo
    ON marketing_arquivos (media_kind, deleted_at, uploaded_at DESC);
