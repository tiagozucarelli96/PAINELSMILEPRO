-- Campos comerciais para pacotes, serviços e produtos na base existente.

ALTER TABLE logistica_pacotes_evento
    ADD COLUMN IF NOT EXISTS categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote',
    ADD COLUMN IF NOT EXISTS valor_venda NUMERIC(12,2) NULL,
    ADD COLUMN IF NOT EXISTS valor_pacote NUMERIC(12,2) NULL,
    ADD COLUMN IF NOT EXISTS pessoas_base INTEGER NULL,
    ADD COLUMN IF NOT EXISTS valor_convidado_adicional NUMERIC(12,2) NULL;

CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_categoria
    ON logistica_pacotes_evento(categoria, deleted_at);

CREATE TABLE IF NOT EXISTS logistica_servico_receitas (
    id BIGSERIAL PRIMARY KEY,
    servico_id BIGINT NOT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE CASCADE,
    receita_id BIGINT NOT NULL REFERENCES logistica_receitas(id) ON DELETE CASCADE,
    ordem INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (servico_id, receita_id)
);

CREATE INDEX IF NOT EXISTS idx_logistica_servico_receitas_servico
    ON logistica_servico_receitas(servico_id, ordem, receita_id);
