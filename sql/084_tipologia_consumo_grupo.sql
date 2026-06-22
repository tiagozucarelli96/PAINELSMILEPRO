ALTER TABLE logistica_tipologias_insumo
    ADD COLUMN IF NOT EXISTS calculo_por_grupo BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS grupo_pessoas_base NUMERIC(12,3),
    ADD COLUMN IF NOT EXISTS grupo_quantidade_base NUMERIC(12,3),
    ADD COLUMN IF NOT EXISTS grupo_unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
    ADD COLUMN IF NOT EXISTS grupo_distribuir_igual BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS grupo_arredondar_inteiro BOOLEAN DEFAULT TRUE;

UPDATE logistica_tipologias_insumo t
SET calculo_por_grupo = TRUE,
    grupo_pessoas_base = COALESCE(grupo_pessoas_base, 100),
    grupo_quantidade_base = COALESCE(grupo_quantidade_base, 1500),
    grupo_unidade_medida_id = COALESCE(
        grupo_unidade_medida_id,
        (SELECT id FROM logistica_unidades_medida WHERE LOWER(nome) = 'un' LIMIT 1)
    ),
    grupo_distribuir_igual = TRUE,
    grupo_arredondar_inteiro = TRUE,
    updated_at = NOW()
WHERE LOWER(TRIM(nome)) IN (LOWER('Salgados'), LOWER('Salgados Assados'));
