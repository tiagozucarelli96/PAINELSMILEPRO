-- Adicionar chave_storage para fotos de insumos e receitas
ALTER TABLE logistica_insumos
    ADD COLUMN IF NOT EXISTS foto_chave_storage TEXT;

ALTER TABLE logistica_receitas
    ADD COLUMN IF NOT EXISTS foto_chave_storage TEXT;
