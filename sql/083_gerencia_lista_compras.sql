ALTER TABLE logistica_insumos
    ADD COLUMN IF NOT EXISTS rendimento_base_pessoas INTEGER NOT NULL DEFAULT 100;

UPDATE logistica_insumos
SET rendimento_base_pessoas = 100;

COMMENT ON COLUMN logistica_insumos.rendimento_base_pessoas IS 'Rendimento base em pessoas para cálculo de insumo direto em lista de compras';

ALTER TABLE logistica_insumos
    ALTER COLUMN rendimento_base_pessoas SET DEFAULT 100;

ALTER TABLE logistica_receitas
    ADD COLUMN IF NOT EXISTS rendimento_base_pessoas INTEGER DEFAULT 100;

ALTER TABLE logistica_receitas
    ALTER COLUMN rendimento_base_pessoas SET DEFAULT 100;

UPDATE logistica_receitas
SET rendimento_base_pessoas = 100;

CREATE TABLE IF NOT EXISTS logistica_lista_evento_itens (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER REFERENCES logistica_listas(id) ON DELETE CASCADE,
    evento_id INTEGER REFERENCES logistica_eventos_espelho(id),
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
    quantidade_total_bruto NUMERIC(12,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_logistica_lista_evento_itens_evento
    ON logistica_lista_evento_itens (evento_id);

CREATE INDEX IF NOT EXISTS idx_logistica_lista_evento_itens_lista
    ON logistica_lista_evento_itens (lista_id);

ALTER TABLE logistica_listas
    ADD COLUMN IF NOT EXISTS status VARCHAR(40) DEFAULT 'gerada';
