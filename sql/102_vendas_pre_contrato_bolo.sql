-- Escolha antecipada do bolo no formulário infantil.
-- Os IDs apontam para os mesmos insumos usados pelo cardápio da Área do Cliente.

ALTER TABLE IF EXISTS vendas_pre_contratos
    ADD COLUMN IF NOT EXISTS bolo_massa_item_id BIGINT NULL REFERENCES logistica_insumos(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS bolo_recheio_item_id BIGINT NULL REFERENCES logistica_insumos(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_bolo_massa
    ON vendas_pre_contratos (bolo_massa_item_id);

CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_bolo_recheio
    ON vendas_pre_contratos (bolo_recheio_item_id);
