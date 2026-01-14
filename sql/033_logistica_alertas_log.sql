-- Log de alertas operacionais da Logística
CREATE TABLE IF NOT EXISTS logistica_alertas_log (
    id SERIAL PRIMARY KEY,
    tipo VARCHAR(60) NOT NULL,
    unidade_id INTEGER REFERENCES logistica_unidades(id),
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    referencia_tipo VARCHAR(40),
    referencia_id INTEGER,
    mensagem TEXT,
    criado_em TIMESTAMP DEFAULT NOW(),
    criado_por INTEGER
);

CREATE INDEX IF NOT EXISTS idx_logistica_alertas_log_tipo_data ON logistica_alertas_log (tipo, criado_em DESC);
