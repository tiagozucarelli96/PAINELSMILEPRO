-- 098_insumo_metodo_calculo_por_categoria.sql
-- Separa o método de cálculo da lista por categoria de evento.

ALTER TABLE IF EXISTS logistica_insumos
    ADD COLUMN IF NOT EXISTS calculo_lista_metodo_adulto VARCHAR(20),
    ADD COLUMN IF NOT EXISTS calculo_lista_metodo_infantil VARCHAR(20);

UPDATE logistica_insumos
SET calculo_lista_metodo_adulto = COALESCE(NULLIF(calculo_lista_metodo_adulto, ''), calculo_lista_metodo, 'rendimento'),
    calculo_lista_metodo_infantil = COALESCE(NULLIF(calculo_lista_metodo_infantil, ''), calculo_lista_metodo, 'rendimento')
WHERE calculo_lista_metodo_adulto IS NULL
   OR calculo_lista_metodo_adulto NOT IN ('rendimento', 'grupo')
   OR calculo_lista_metodo_infantil IS NULL
   OR calculo_lista_metodo_infantil NOT IN ('rendimento', 'grupo');

COMMENT ON COLUMN logistica_insumos.calculo_lista_metodo_adulto
IS 'Método de cálculo na lista para casamento, 15 anos, formatura e demais eventos não infantis.';

COMMENT ON COLUMN logistica_insumos.calculo_lista_metodo_infantil
IS 'Método de cálculo na lista para eventos infantis.';
