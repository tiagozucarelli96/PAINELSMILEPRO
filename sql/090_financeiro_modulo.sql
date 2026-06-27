-- 090_financeiro_modulo.sql
-- Modulo financeiro geral: despesas manuais e importadas via OFX.

CREATE TABLE IF NOT EXISTS financeiro_despesas (
    id BIGSERIAL PRIMARY KEY,
    data_movimento DATE NOT NULL,
    descricao TEXT NOT NULL,
    valor NUMERIC(12,2) NOT NULL DEFAULT 0,
    banco VARCHAR(120) NULL,
    conta VARCHAR(120) NULL,
    categoria VARCHAR(120) NULL,
    centro_custo VARCHAR(120) NULL,
    destinatario VARCHAR(180) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pendente',
    origem VARCHAR(30) NOT NULL DEFAULT 'manual',
    ofx_fitid VARCHAR(180) NULL,
    ofx_payload JSONB NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_data
    ON financeiro_despesas(data_movimento);

CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_status
    ON financeiro_despesas(status);

CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_ofx_fitid
    ON financeiro_despesas(banco, conta, ofx_fitid)
    WHERE ofx_fitid IS NOT NULL;

ALTER TABLE IF EXISTS financeiro_despesas
    ADD COLUMN IF NOT EXISTS destinatario VARCHAR(180) NULL;

CREATE TABLE IF NOT EXISTS financeiro_ofx_descricoes (
    id BIGSERIAL PRIMARY KEY,
    descricao_banco TEXT NOT NULL,
    descricao_normalizada VARCHAR(220) NOT NULL,
    destinatario VARCHAR(180) NULL,
    categoria VARCHAR(120) NULL,
    centro_custo VARCHAR(120) NULL,
    evento_id BIGINT NULL,
    banco VARCHAR(120) NULL,
    conta VARCHAR(120) NULL,
    tipo_movimento VARCHAR(30) NULL,
    uso_count INTEGER NOT NULL DEFAULT 0,
    ultimo_uso_em TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_financeiro_ofx_descricoes_norm
    ON financeiro_ofx_descricoes(descricao_normalizada);

CREATE UNIQUE INDEX IF NOT EXISTS idx_financeiro_ofx_descricoes_unique
    ON financeiro_ofx_descricoes(descricao_normalizada, COALESCE(banco, ''), COALESCE(conta, ''));
