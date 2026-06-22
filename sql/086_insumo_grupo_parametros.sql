ALTER TABLE logistica_insumos
    ADD COLUMN IF NOT EXISTS grupo_pessoas_base NUMERIC(12,3),
    ADD COLUMN IF NOT EXISTS grupo_quantidade_base NUMERIC(12,3),
    ADD COLUMN IF NOT EXISTS grupo_unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id);

UPDATE logistica_insumos i
SET grupo_pessoas_base = COALESCE(i.grupo_pessoas_base, t.grupo_pessoas_base),
    grupo_quantidade_base = COALESCE(i.grupo_quantidade_base, t.grupo_quantidade_base),
    grupo_unidade_medida_id = COALESCE(i.grupo_unidade_medida_id, t.grupo_unidade_medida_id),
    updated_at = NOW()
FROM logistica_tipologias_insumo t
WHERE t.id = i.tipologia_insumo_id
  AND COALESCE(t.calculo_por_grupo, FALSE) = TRUE
  AND (
      i.grupo_pessoas_base IS NULL
      OR i.grupo_quantidade_base IS NULL
      OR i.grupo_unidade_medida_id IS NULL
  )
  AND (
      COALESCE(i.grupo_pessoas_base, t.grupo_pessoas_base) IS DISTINCT FROM i.grupo_pessoas_base
      OR COALESCE(i.grupo_quantidade_base, t.grupo_quantidade_base) IS DISTINCT FROM i.grupo_quantidade_base
      OR COALESCE(i.grupo_unidade_medida_id, t.grupo_unidade_medida_id) IS DISTINCT FROM i.grupo_unidade_medida_id
  );
