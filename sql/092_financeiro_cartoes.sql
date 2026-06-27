-- 092_financeiro_cartoes.sql
-- Cartoes de credito, faturas, lancamentos e aprendizado de categoria.

CREATE TABLE IF NOT EXISTS financeiro_cartoes (
    id BIGSERIAL PRIMARY KEY,
    nome VARCHAR(160) NOT NULL,
    dia_vencimento INTEGER NOT NULL DEFAULT 1,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS financeiro_cartao_faturas (
    id BIGSERIAL PRIMARY KEY,
    cartao_id BIGINT NOT NULL REFERENCES financeiro_cartoes(id) ON DELETE CASCADE,
    competencia DATE NOT NULL,
    vencimento DATE NOT NULL,
    total NUMERIC(12,2) NOT NULL DEFAULT 0,
    despesa_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (cartao_id, competencia)
);

CREATE TABLE IF NOT EXISTS financeiro_cartao_lancamentos (
    id BIGSERIAL PRIMARY KEY,
    fatura_id BIGINT NOT NULL REFERENCES financeiro_cartao_faturas(id) ON DELETE CASCADE,
    cartao_id BIGINT NOT NULL REFERENCES financeiro_cartoes(id) ON DELETE CASCADE,
    data_compra DATE NOT NULL,
    descricao TEXT NOT NULL,
    descricao_normalizada VARCHAR(220) NOT NULL,
    valor NUMERIC(12,2) NOT NULL DEFAULT 0,
    parcela_numero INTEGER NOT NULL DEFAULT 1,
    parcelas_total INTEGER NOT NULL DEFAULT 1,
    categoria VARCHAR(120) NULL,
    hash_parcela VARCHAR(80) NOT NULL,
    origem_texto TEXT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (cartao_id, hash_parcela)
);

CREATE TABLE IF NOT EXISTS financeiro_cartao_descricoes (
    id BIGSERIAL PRIMARY KEY,
    descricao_normalizada VARCHAR(220) NOT NULL UNIQUE,
    descricao_exemplo TEXT NOT NULL,
    categoria VARCHAR(120) NULL,
    uso_count INTEGER NOT NULL DEFAULT 0,
    ultimo_uso_em TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_financeiro_cartao_faturas_cartao
    ON financeiro_cartao_faturas(cartao_id, vencimento);

CREATE INDEX IF NOT EXISTS idx_financeiro_cartao_lancamentos_fatura
    ON financeiro_cartao_lancamentos(fatura_id);

CREATE INDEX IF NOT EXISTS idx_financeiro_cartao_descricoes_norm
    ON financeiro_cartao_descricoes(descricao_normalizada);
