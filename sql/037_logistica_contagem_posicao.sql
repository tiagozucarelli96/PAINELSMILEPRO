-- Persistir posição do wizard de contagem
ALTER TABLE logistica_estoque_contagens
    ADD COLUMN IF NOT EXISTS posicao_atual INTEGER DEFAULT 1,
    ADD COLUMN IF NOT EXISTS posicao_atual_em TIMESTAMP;
