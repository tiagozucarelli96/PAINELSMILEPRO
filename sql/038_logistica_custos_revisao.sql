-- Logs de custo e revisão mensal
CREATE TABLE IF NOT EXISTS logistica_custos_log (
    id SERIAL PRIMARY KEY,
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    usuario_id INTEGER,
    custo_anterior NUMERIC(12,4),
    custo_novo NUMERIC(12,4),
    criado_em TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_logistica_custos_log_insumo ON logistica_custos_log (insumo_id);
CREATE INDEX IF NOT EXISTS idx_logistica_custos_log_data ON logistica_custos_log (criado_em DESC);

CREATE TABLE IF NOT EXISTS logistica_revisao_custos (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER UNIQUE,
    posicao_atual INTEGER DEFAULT 1,
    posicao_atual_em TIMESTAMP,
    iniciado_em TIMESTAMP DEFAULT NOW(),
    finalizado_em TIMESTAMP
);
