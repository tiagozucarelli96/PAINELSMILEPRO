ALTER TABLE eventos_galeria
    ADD COLUMN IF NOT EXISTS thumb_storage_key VARCHAR(500),
    ADD COLUMN IF NOT EXISTS thumb_public_url TEXT;

CREATE INDEX IF NOT EXISTS idx_eventos_galeria_uploaded_at
ON eventos_galeria(uploaded_at DESC)
WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_eventos_galeria_categoria_uploaded_at
ON eventos_galeria(categoria, uploaded_at DESC)
WHERE deleted_at IS NULL;
