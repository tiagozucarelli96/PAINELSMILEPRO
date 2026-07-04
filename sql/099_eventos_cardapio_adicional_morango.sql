-- 099_eventos_cardapio_adicional_morango.sql
-- Marca administrativa para adicional de morango no bolo do cardápio.

ALTER TABLE IF EXISTS eventos_cardapio_respostas
    ADD COLUMN IF NOT EXISTS adicional_morango_bolo BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS adicional_morango_updated_by INTEGER NULL,
    ADD COLUMN IF NOT EXISTS adicional_morango_updated_at TIMESTAMP NULL;

