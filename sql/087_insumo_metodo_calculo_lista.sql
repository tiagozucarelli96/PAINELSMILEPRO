ALTER TABLE logistica_insumos
    ADD COLUMN IF NOT EXISTS calculo_lista_metodo VARCHAR(20);

UPDATE logistica_insumos i
SET calculo_lista_metodo = CASE
        WHEN COALESCE(t.calculo_por_grupo, FALSE) = TRUE
             AND COALESCE(i.grupo_pessoas_base, t.grupo_pessoas_base) IS NOT NULL
             AND COALESCE(i.grupo_quantidade_base, t.grupo_quantidade_base) IS NOT NULL
            THEN 'grupo'
        ELSE 'rendimento'
    END,
    updated_at = NOW()
FROM logistica_tipologias_insumo t
WHERE t.id = i.tipologia_insumo_id
  AND i.calculo_lista_metodo IS NULL;

UPDATE logistica_insumos
SET calculo_lista_metodo = 'rendimento',
    updated_at = NOW()
WHERE calculo_lista_metodo IS NULL;

ALTER TABLE logistica_insumos
    ALTER COLUMN calculo_lista_metodo SET DEFAULT 'rendimento';
