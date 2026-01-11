-- Estrutura ficha técnica (componentes com insumo/receita)
ALTER TABLE logistica_receita_componentes
    ADD COLUMN IF NOT EXISTS item_tipo VARCHAR(20),
    ADD COLUMN IF NOT EXISTS item_id INTEGER,
    ADD COLUMN IF NOT EXISTS medida_caseira TEXT,
    ADD COLUMN IF NOT EXISTS unidade_medida VARCHAR(50),
    ADD COLUMN IF NOT EXISTS qtde_bruta NUMERIC(12,4),
    ADD COLUMN IF NOT EXISTS qtde_liquida NUMERIC(12,4),
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();

-- Backfill a partir do modelo antigo
UPDATE logistica_receita_componentes
SET item_tipo = COALESCE(item_tipo, 'insumo'),
    item_id = COALESCE(item_id, insumo_id),
    unidade_medida = COALESCE(unidade_medida, unidade),
    qtde_bruta = COALESCE(qtde_bruta, quantidade_base),
    qtde_liquida = COALESCE(qtde_liquida, quantidade_base),
    updated_at = NOW()
WHERE item_id IS NULL OR item_tipo IS NULL OR unidade_medida IS NULL OR qtde_bruta IS NULL OR qtde_liquida IS NULL;

CREATE INDEX IF NOT EXISTS idx_logistica_receita_componentes_receita ON logistica_receita_componentes (receita_id);
CREATE INDEX IF NOT EXISTS idx_logistica_receita_componentes_item ON logistica_receita_componentes (item_tipo, item_id);
