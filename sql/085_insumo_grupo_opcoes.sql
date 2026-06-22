ALTER TABLE logistica_insumos
    ADD COLUMN IF NOT EXISTS grupo_arredondar_inteiro BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS grupo_aplicar_margem BOOLEAN DEFAULT TRUE;

UPDATE logistica_insumos i
SET grupo_arredondar_inteiro = COALESCE(t.grupo_arredondar_inteiro, TRUE),
    grupo_aplicar_margem = COALESCE(t.grupo_aplicar_margem, TRUE),
    updated_at = NOW()
FROM logistica_tipologias_insumo t
WHERE t.id = i.tipologia_insumo_id
  AND COALESCE(t.calculo_por_grupo, FALSE) = TRUE;
