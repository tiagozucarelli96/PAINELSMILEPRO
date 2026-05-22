-- 073_cardapio_selecionar_todos_itens.sql
-- Permite marcar uma seção como fixa, selecionando todos os itens disponíveis no cardápio do cliente.

ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes
    ADD COLUMN IF NOT EXISTS selecionar_todos_itens BOOLEAN NOT NULL DEFAULT FALSE;
