-- Insumo base: consolidação de marcas/códigos sob um mesmo item base

CREATE TABLE IF NOT EXISTS logistica_insumos_base (
    id SERIAL PRIMARY KEY,
    nome_base VARCHAR(200) NOT NULL,
    chave_nome VARCHAR(220) NOT NULL UNIQUE,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE logistica_insumos
    ADD COLUMN IF NOT EXISTS insumo_base_id INTEGER;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_logistica_insumos_insumo_base_id'
    ) THEN
        ALTER TABLE logistica_insumos
            ADD CONSTRAINT fk_logistica_insumos_insumo_base_id
            FOREIGN KEY (insumo_base_id)
            REFERENCES logistica_insumos_base(id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_logistica_insumos_insumo_base_id
    ON logistica_insumos (insumo_base_id);

INSERT INTO logistica_insumos_base (nome_base, chave_nome)
SELECT DISTINCT
    TRIM(i.nome_oficial) AS nome_base,
    LOWER(REGEXP_REPLACE(TRIM(i.nome_oficial), '\s+', ' ', 'g')) AS chave_nome
FROM logistica_insumos i
WHERE COALESCE(TRIM(i.nome_oficial), '') <> ''
ON CONFLICT (chave_nome) DO NOTHING;

UPDATE logistica_insumos i
SET insumo_base_id = b.id
FROM logistica_insumos_base b
WHERE i.insumo_base_id IS NULL
  AND b.chave_nome = LOWER(REGEXP_REPLACE(TRIM(i.nome_oficial), '\s+', ' ', 'g'));

