UPDATE logistica_insumos
SET tipologia_insumo_id = (
        SELECT id
        FROM logistica_tipologias_insumo
        WHERE nome = 'Sabor de bolo'
        LIMIT 1
    ),
    updated_at = NOW()
WHERE nome_oficial = 'Massa Chocolate'
  AND tipologia_insumo_id IS NULL
  AND EXISTS (
      SELECT 1
      FROM logistica_tipologias_insumo
      WHERE nome = 'Sabor de bolo'
  );
