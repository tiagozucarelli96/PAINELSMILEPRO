-- 072_cardapio_quantidade_exata.sql
-- Permite configurar se a quantidade definida por seção deve ser exata ou apenas limite máximo.

ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes
    ADD COLUMN IF NOT EXISTS exigir_quantidade_exata BOOLEAN NOT NULL DEFAULT TRUE;
