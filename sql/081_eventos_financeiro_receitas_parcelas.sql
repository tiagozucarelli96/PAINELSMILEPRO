ALTER TABLE IF EXISTS eventos_financeiro_receitas
    ADD COLUMN IF NOT EXISTS carteira VARCHAR(20) NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS modo_pagamento VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS unidade VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS parcelamento_grupo VARCHAR(80) NULL;

CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_unidade
    ON eventos_financeiro_receitas(unidade, status);

CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_grupo
    ON eventos_financeiro_receitas(parcelamento_grupo);
