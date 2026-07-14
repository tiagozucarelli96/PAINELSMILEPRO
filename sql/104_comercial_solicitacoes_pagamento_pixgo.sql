-- Links comerciais de pagamento PixGo.
-- O link é criado primeiro; a cobrança PixGo só é criada quando o cliente clica em "Gerar Pix".

CREATE TABLE IF NOT EXISTS comercial_pagamento_solicitacoes (
    id BIGSERIAL PRIMARY KEY,
    token VARCHAR(96) NOT NULL UNIQUE,
    evento_id BIGINT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE SET NULL,
    evento_nome TEXT NULL,
    descricao TEXT NOT NULL,
    valor_original NUMERIC(12,2) NOT NULL,
    vencimento DATE NOT NULL,
    multa_percent NUMERIC(6,3) NOT NULL DEFAULT 2.000,
    juros_mensal_percent NUMERIC(6,3) NOT NULL DEFAULT 1.000,
    pagador_nome VARCHAR(160) NOT NULL,
    pagador_documento VARCHAR(20) NOT NULL,
    pagador_email VARCHAR(255) NULL,
    pagador_telefone VARCHAR(40) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'solicitado',
    pixgo_payment_id VARCHAR(120) NULL,
    pixgo_qr_code TEXT NULL,
    pixgo_qr_image_url TEXT NULL,
    pixgo_expires_at TIMESTAMPTZ NULL,
    pixgo_idempotency_key VARCHAR(120) NULL,
    pixgo_payload JSONB NULL,
    ultimo_erro TEXT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    pago_em TIMESTAMPTZ NULL
);

CREATE TABLE IF NOT EXISTS comercial_pagamento_pixgo_tentativas (
    id BIGSERIAL PRIMARY KEY,
    solicitacao_id BIGINT NOT NULL REFERENCES comercial_pagamento_solicitacoes(id) ON DELETE CASCADE,
    payment_id VARCHAR(120) NOT NULL UNIQUE,
    idempotency_key VARCHAR(120) NOT NULL,
    valor_cobrado NUMERIC(12,2) NOT NULL,
    valor_original NUMERIC(12,2) NOT NULL,
    multa_valor NUMERIC(12,2) NOT NULL DEFAULT 0,
    juros_valor NUMERIC(12,2) NOT NULL DEFAULT 0,
    dias_atraso INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'pendente',
    qr_code TEXT NULL,
    qr_image_url TEXT NULL,
    expires_at TIMESTAMPTZ NULL,
    payload JSONB NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_comercial_pagamento_solicitacoes_evento
    ON comercial_pagamento_solicitacoes(evento_id);

CREATE INDEX IF NOT EXISTS idx_comercial_pagamento_solicitacoes_status
    ON comercial_pagamento_solicitacoes(status, created_at DESC);

CREATE UNIQUE INDEX IF NOT EXISTS idx_comercial_pagamento_solicitacoes_pixgo
    ON comercial_pagamento_solicitacoes(pixgo_payment_id)
    WHERE pixgo_payment_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_comercial_pagamento_pixgo_tentativas_solicitacao
    ON comercial_pagamento_pixgo_tentativas(solicitacao_id, created_at DESC);
