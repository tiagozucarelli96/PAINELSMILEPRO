-- 093_insumo_rendimento_quantidade_base.sql
-- Permite calcular insumo direto como "X unidades para Y pessoas".

ALTER TABLE IF EXISTS logistica_insumos
    ADD COLUMN IF NOT EXISTS rendimento_quantidade_base NUMERIC(12,3) NOT NULL DEFAULT 1;

UPDATE logistica_insumos
SET rendimento_quantidade_base = 1
WHERE rendimento_quantidade_base IS NULL
   OR rendimento_quantidade_base <= 0;

COMMENT ON COLUMN logistica_insumos.rendimento_quantidade_base
IS 'Quantidade do item consumida para o rendimento_base_pessoas no cálculo da lista de compras.';
