-- Tabelas para listas de compras (logistica)
CREATE TABLE IF NOT EXISTS logistica_listas (
    id SERIAL PRIMARY KEY,
    unidade_interna_id INTEGER REFERENCES logistica_unidades(id),
    space_visivel VARCHAR(20),
    criado_por INTEGER,
    criado_em TIMESTAMP DEFAULT NOW(),
    excluida BOOLEAN DEFAULT FALSE,
    excluida_em TIMESTAMP,
    excluida_por INTEGER
);

CREATE TABLE IF NOT EXISTS logistica_lista_eventos (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER REFERENCES logistica_listas(id) ON DELETE CASCADE,
    evento_id INTEGER REFERENCES logistica_eventos_espelho(id),
    me_event_id INTEGER,
    nome_evento TEXT,
    data_evento DATE,
    hora_inicio TIME,
    convidados INTEGER,
    localevento TEXT,
    space_visivel VARCHAR(20),
    unidade_interna_id INTEGER REFERENCES logistica_unidades(id)
);

CREATE TABLE IF NOT EXISTS logistica_lista_itens (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER REFERENCES logistica_listas(id) ON DELETE CASCADE,
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    tipologia_insumo_id INTEGER REFERENCES logistica_tipologias_insumo(id),
    unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
    quantidade_total_bruto NUMERIC(12,4) NOT NULL DEFAULT 0,
    observacao TEXT
);

CREATE INDEX IF NOT EXISTS idx_logistica_listas_unidade ON logistica_listas (unidade_interna_id);
CREATE INDEX IF NOT EXISTS idx_logistica_listas_criado_em ON logistica_listas (criado_em);
CREATE INDEX IF NOT EXISTS idx_logistica_lista_eventos_lista ON logistica_lista_eventos (lista_id);
CREATE INDEX IF NOT EXISTS idx_logistica_lista_itens_lista ON logistica_lista_itens (lista_id);
