ALTER TABLE IF EXISTS logistica_pacotes_evento
    ADD COLUMN IF NOT EXISTS tipo_evento_real VARCHAR(60) NULL,
    ADD COLUMN IF NOT EXISTS modelo_preco VARCHAR(30) NOT NULL DEFAULT 'simples';

CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_tipo_real
    ON logistica_pacotes_evento(tipo_evento_real, deleted_at);

CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_modelo_preco
    ON logistica_pacotes_evento(modelo_preco, deleted_at);

CREATE TABLE IF NOT EXISTS logistica_pacote_preco_variacoes (
    id BIGSERIAL PRIMARY KEY,
    pacote_evento_id BIGINT NOT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE CASCADE,
    nome VARCHAR(120) NOT NULL,
    codigo VARCHAR(80) NULL,
    dias_semana VARCHAR(30) NULL,
    inclui_feriado BOOLEAN NOT NULL DEFAULT FALSE,
    inclui_vespera_feriado BOOLEAN NOT NULL DEFAULT FALSE,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    ordem INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_logistica_pacote_preco_variacoes_pacote
    ON logistica_pacote_preco_variacoes(pacote_evento_id, ativo, ordem);

CREATE TABLE IF NOT EXISTS logistica_pacote_preco_faixas (
    id BIGSERIAL PRIMARY KEY,
    variacao_id BIGINT NOT NULL REFERENCES logistica_pacote_preco_variacoes(id) ON DELETE CASCADE,
    pessoas INTEGER NOT NULL,
    valor NUMERIC(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (variacao_id, pessoas)
);

CREATE INDEX IF NOT EXISTS idx_logistica_pacote_preco_faixas_variacao
    ON logistica_pacote_preco_faixas(variacao_id, pessoas);
