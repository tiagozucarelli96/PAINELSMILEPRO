-- 043_vendas_kanban_interacoes.sql
-- Interações do Kanban de Vendas: observações + suporte completo para notificações de menção.

CREATE TABLE IF NOT EXISTS vendas_kanban_observacoes (
    id SERIAL PRIMARY KEY,
    card_id INT NOT NULL REFERENCES vendas_kanban_cards(id) ON DELETE CASCADE,
    autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    observacao TEXT NOT NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_vendas_kanban_observacoes_card
    ON vendas_kanban_observacoes(card_id, criado_em DESC);

CREATE INDEX IF NOT EXISTS idx_vendas_kanban_observacoes_autor
    ON vendas_kanban_observacoes(autor_id);

ALTER TABLE demandas_notificacoes
    ADD COLUMN IF NOT EXISTS tipo VARCHAR(50);

ALTER TABLE demandas_notificacoes
    ADD COLUMN IF NOT EXISTS referencia_id INT;

ALTER TABLE demandas_notificacoes
    ADD COLUMN IF NOT EXISTS mensagem TEXT;

ALTER TABLE demandas_notificacoes
    ADD COLUMN IF NOT EXISTS lida BOOLEAN DEFAULT FALSE;

ALTER TABLE demandas_notificacoes
    ADD COLUMN IF NOT EXISTS criada_em TIMESTAMPTZ DEFAULT NOW();

ALTER TABLE demandas_notificacoes
    ADD COLUMN IF NOT EXISTS titulo VARCHAR(180);

ALTER TABLE demandas_notificacoes
    ADD COLUMN IF NOT EXISTS url_destino TEXT;

CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_usuario
    ON demandas_notificacoes(usuario_id, lida);
