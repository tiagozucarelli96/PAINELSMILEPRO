-- Remoção completa da estrutura de insumo base
-- Executar somente após remover o uso no código

BEGIN;

ALTER TABLE IF EXISTS logistica_insumos
    DROP CONSTRAINT IF EXISTS fk_logistica_insumos_insumo_base_id;

DROP INDEX IF EXISTS idx_logistica_insumos_insumo_base_id;

ALTER TABLE IF EXISTS logistica_insumos
    DROP COLUMN IF EXISTS insumo_base_id;

DROP TABLE IF EXISTS logistica_insumos_base;

COMMIT;

