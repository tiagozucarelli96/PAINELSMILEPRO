-- Estrutura de estoque logística (saldos, contagens, movimentos e transferências)

CREATE TABLE IF NOT EXISTS logistica_estoque_saldos (
    unidade_id INTEGER REFERENCES logistica_unidades(id),
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    quantidade_atual NUMERIC(12,4) NOT NULL DEFAULT 0,
    unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
    updated_at TIMESTAMP DEFAULT NOW(),
    PRIMARY KEY (unidade_id, insumo_id)
);

CREATE TABLE IF NOT EXISTS logistica_estoque_contagens (
    id SERIAL PRIMARY KEY,
    unidade_id INTEGER REFERENCES logistica_unidades(id),
    status VARCHAR(20) DEFAULT 'rascunho',
    iniciada_em TIMESTAMP DEFAULT NOW(),
    finalizada_em TIMESTAMP,
    criado_por INTEGER,
    observacao TEXT
);

CREATE TABLE IF NOT EXISTS logistica_estoque_contagens_itens (
    id SERIAL PRIMARY KEY,
    contagem_id INTEGER REFERENCES logistica_estoque_contagens(id) ON DELETE CASCADE,
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    quantidade_contada NUMERIC(12,4),
    unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
    ordem INTEGER,
    UNIQUE (contagem_id, insumo_id)
);

CREATE TABLE IF NOT EXISTS logistica_estoque_movimentos (
    id SERIAL PRIMARY KEY,
    unidade_id_origem INTEGER REFERENCES logistica_unidades(id),
    unidade_id_destino INTEGER REFERENCES logistica_unidades(id),
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    tipo VARCHAR(30) NOT NULL,
    quantidade NUMERIC(12,4) NOT NULL DEFAULT 0,
    referencia_tipo VARCHAR(30),
    referencia_id INTEGER,
    criado_por INTEGER,
    criado_em TIMESTAMP DEFAULT NOW(),
    observacao TEXT
);

CREATE TABLE IF NOT EXISTS logistica_transferencias (
    id SERIAL PRIMARY KEY,
    unidade_origem_id INTEGER REFERENCES logistica_unidades(id),
    unidade_destino_id INTEGER REFERENCES logistica_unidades(id),
    space_destino VARCHAR(30),
    status VARCHAR(20) DEFAULT 'rascunho',
    criado_por INTEGER,
    criado_em TIMESTAMP DEFAULT NOW(),
    enviado_em TIMESTAMP,
    recebido_em TIMESTAMP,
    observacao TEXT
);

CREATE TABLE IF NOT EXISTS logistica_transferencias_itens (
    id SERIAL PRIMARY KEY,
    transferencia_id INTEGER REFERENCES logistica_transferencias(id) ON DELETE CASCADE,
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    quantidade NUMERIC(12,4) NOT NULL DEFAULT 0,
    unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
    check_carregado BOOLEAN DEFAULT FALSE,
    check_recebido BOOLEAN DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS idx_logistica_estoque_contagens_unidade ON logistica_estoque_contagens (unidade_id, status);
CREATE INDEX IF NOT EXISTS idx_logistica_estoque_movimentos_unidade ON logistica_estoque_movimentos (unidade_id_origem, unidade_id_destino);
CREATE INDEX IF NOT EXISTS idx_logistica_transferencias_status ON logistica_transferencias (status);
