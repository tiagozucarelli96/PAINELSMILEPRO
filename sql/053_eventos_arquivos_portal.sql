-- 053_eventos_arquivos_portal.sql
-- Módulo de arquivos no portal do cliente (campos solicitados + uploads)

ALTER TABLE IF EXISTS eventos_cliente_portais
    ADD COLUMN IF NOT EXISTS visivel_arquivos BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS editavel_arquivos BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS eventos_arquivos_campos (
    id BIGSERIAL PRIMARY KEY,
    meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    titulo VARCHAR(180) NOT NULL,
    descricao TEXT NULL,
    obrigatorio_cliente BOOLEAN NOT NULL DEFAULT FALSE,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_user_id INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_campos_meeting
    ON eventos_arquivos_campos(meeting_id, ativo);

CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_campos_sort
    ON eventos_arquivos_campos(meeting_id, sort_order, id);

CREATE TABLE IF NOT EXISTS eventos_arquivos_itens (
    id BIGSERIAL PRIMARY KEY,
    meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    campo_id BIGINT NULL REFERENCES eventos_arquivos_campos(id) ON DELETE SET NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    storage_key VARCHAR(500) NOT NULL,
    public_url TEXT NULL,
    descricao TEXT NULL,
    visivel_cliente BOOLEAN NOT NULL DEFAULT FALSE,
    uploaded_by_type VARCHAR(20) NOT NULL DEFAULT 'interno',
    uploaded_by_user_id INTEGER NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP NULL,
    deleted_by_user_id INTEGER NULL
);

CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_itens_meeting
    ON eventos_arquivos_itens(meeting_id, deleted_at);

CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_itens_campo
    ON eventos_arquivos_itens(campo_id, deleted_at);

CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_itens_visivel_cliente
    ON eventos_arquivos_itens(meeting_id, visivel_cliente, deleted_at);
