-- 044_vendas_kanban_status_arquivo.sql
-- Adiciona flags de conclusão e arquivamento para cards do Kanban de Vendas

ALTER TABLE vendas_kanban_cards
    ADD COLUMN IF NOT EXISTS concluido BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS concluido_em TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS concluido_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS arquivado BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS arquivado_em TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS arquivado_por INT REFERENCES usuarios(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_vendas_kanban_cards_arquivado
    ON vendas_kanban_cards(arquivado);

CREATE INDEX IF NOT EXISTS idx_vendas_kanban_cards_concluido
    ON vendas_kanban_cards(concluido);
