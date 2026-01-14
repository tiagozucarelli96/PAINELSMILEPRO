-- Itens por evento e controle de baixas automáticas
CREATE TABLE IF NOT EXISTS logistica_lista_evento_itens (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER REFERENCES logistica_listas(id) ON DELETE CASCADE,
    evento_id INTEGER REFERENCES logistica_eventos_espelho(id),
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
    quantidade_total_bruto NUMERIC(12,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_logistica_lista_evento_itens_evento ON logistica_lista_evento_itens (evento_id);
CREATE INDEX IF NOT EXISTS idx_logistica_lista_evento_itens_lista ON logistica_lista_evento_itens (lista_id);

CREATE TABLE IF NOT EXISTS logistica_evento_baixas (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER REFERENCES logistica_listas(id),
    evento_id INTEGER REFERENCES logistica_eventos_espelho(id),
    unidade_id INTEGER REFERENCES logistica_unidades(id),
    baixado_em TIMESTAMP DEFAULT NOW(),
    baixado_por INTEGER
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_logistica_evento_baixas_unq ON logistica_evento_baixas (lista_id, evento_id);

ALTER TABLE logistica_listas
    ADD COLUMN IF NOT EXISTS baixada_em TIMESTAMP,
    ADD COLUMN IF NOT EXISTS baixada_por INTEGER;
