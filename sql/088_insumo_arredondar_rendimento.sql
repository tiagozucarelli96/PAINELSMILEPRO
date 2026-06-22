ALTER TABLE logistica_insumos
    ADD COLUMN IF NOT EXISTS arredondar_rendimento_lista BOOLEAN DEFAULT FALSE;

UPDATE logistica_insumos
SET arredondar_rendimento_lista = TRUE,
    updated_at = NOW()
WHERE nome_oficial = 'Uva (Cascata)'
  AND COALESCE(arredondar_rendimento_lista, FALSE) = FALSE;
