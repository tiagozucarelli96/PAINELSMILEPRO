-- 043_logistica_vendedores.sql
-- Mapeamento de vendedores (ME -> Usuário interno)

CREATE TABLE IF NOT EXISTS logistica_me_vendedores (
    id SERIAL PRIMARY KEY,
    me_vendedor_id INTEGER NOT NULL,
    me_vendedor_nome TEXT NOT NULL,
    usuario_interno_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    status_mapeamento VARCHAR(20) DEFAULT 'PENDENTE',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE (me_vendedor_id)
);

CREATE INDEX IF NOT EXISTS idx_logistica_me_vendedores_nome ON logistica_me_vendedores (LOWER(me_vendedor_nome));
CREATE INDEX IF NOT EXISTS idx_logistica_me_vendedores_usuario ON logistica_me_vendedores (usuario_interno_id);

