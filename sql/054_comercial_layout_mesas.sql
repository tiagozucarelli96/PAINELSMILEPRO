-- Persistencia de organizacao de mesas da tela "Realizar Degustacao"
CREATE TABLE IF NOT EXISTS comercial_degustacao_layout_mesas (
    id BIGSERIAL PRIMARY KEY,
    degustacao_id INTEGER NOT NULL UNIQUE,
    layout_json TEXT NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_comercial_degustacao_layout_mesas_degustacao
ON comercial_degustacao_layout_mesas (degustacao_id);
