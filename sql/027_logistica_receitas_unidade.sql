-- Unidade de medida da receita
ALTER TABLE logistica_receitas
    ADD COLUMN IF NOT EXISTS unidade_medida VARCHAR(50);
