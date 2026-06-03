-- Campos comerciais para pacotes, serviços e produtos na base existente.

ALTER TABLE logistica_pacotes_evento
    ADD COLUMN IF NOT EXISTS categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote',
    ADD COLUMN IF NOT EXISTS valor_venda NUMERIC(12,2) NULL,
    ADD COLUMN IF NOT EXISTS valor_pacote NUMERIC(12,2) NULL,
    ADD COLUMN IF NOT EXISTS pessoas_base INTEGER NULL,
    ADD COLUMN IF NOT EXISTS valor_convidado_adicional NUMERIC(12,2) NULL;

CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_categoria
    ON logistica_pacotes_evento(categoria, deleted_at);
