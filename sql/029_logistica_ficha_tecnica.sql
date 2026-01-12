-- Ajustes ficha técnica: unidades por linha + pesos + unidade padrão
ALTER TABLE logistica_insumos
    ADD COLUMN IF NOT EXISTS unidade_medida_padrao_id INTEGER;

ALTER TABLE logistica_receitas
    ADD COLUMN IF NOT EXISTS unidade_medida_padrao_id INTEGER;

ALTER TABLE logistica_receita_componentes
    ADD COLUMN IF NOT EXISTS unidade_medida_id INTEGER,
    ADD COLUMN IF NOT EXISTS peso_liquido NUMERIC(12,4),
    ADD COLUMN IF NOT EXISTS fator_correcao NUMERIC(12,4),
    ADD COLUMN IF NOT EXISTS peso_bruto NUMERIC(12,4);

-- Backfill de componentes com base no modelo anterior
UPDATE logistica_receita_componentes
SET peso_liquido = COALESCE(peso_liquido, qtde_liquida, qtde_bruta),
    peso_bruto = COALESCE(peso_bruto, qtde_bruta, qtde_liquida),
    fator_correcao = COALESCE(
        fator_correcao,
        CASE WHEN COALESCE(qtde_liquida, 0) > 0 THEN COALESCE(qtde_bruta, qtde_liquida) / qtde_liquida ELSE 1 END,
        1
    )
WHERE peso_liquido IS NULL OR peso_bruto IS NULL OR fator_correcao IS NULL;

-- Tentar mapear unidade_medida textual para unidade_medida_id
UPDATE logistica_receita_componentes c
SET unidade_medida_id = u.id
FROM logistica_unidades_medida u
WHERE c.unidade_medida_id IS NULL
  AND c.unidade_medida IS NOT NULL
  AND lower(trim(c.unidade_medida)) = lower(trim(u.nome));

CREATE INDEX IF NOT EXISTS idx_logistica_receita_componentes_unidade ON logistica_receita_componentes (unidade_medida_id);
