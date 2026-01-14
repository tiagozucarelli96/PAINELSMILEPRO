-- Status e separação das listas + vínculo com transferências
ALTER TABLE logistica_listas
    ADD COLUMN IF NOT EXISTS status VARCHAR(40) DEFAULT 'gerada',
    ADD COLUMN IF NOT EXISTS separado_garden BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS separado_em TIMESTAMP,
    ADD COLUMN IF NOT EXISTS separado_por INTEGER;

ALTER TABLE logistica_transferencias
    ADD COLUMN IF NOT EXISTS lista_id INTEGER REFERENCES logistica_listas(id);

CREATE INDEX IF NOT EXISTS idx_logistica_listas_status ON logistica_listas (status);
CREATE INDEX IF NOT EXISTS idx_logistica_transferencias_lista ON logistica_transferencias (lista_id);
