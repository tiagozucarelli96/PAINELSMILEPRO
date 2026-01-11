-- Unidades de medida para catálogo logístico
CREATE TABLE IF NOT EXISTS logistica_unidades_medida (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE,
    ordem INTEGER DEFAULT 0,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_logistica_unidades_medida_nome ON logistica_unidades_medida (LOWER(nome));
