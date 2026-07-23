-- Controle anti-duplicidade dos lembretes enviados no dia da degustação.
CREATE TABLE IF NOT EXISTS comercial_degustacao_notificacao_envios (
    id BIGSERIAL PRIMARY KEY,
    degustacao_id BIGINT NOT NULL,
    inscricao_id BIGINT NOT NULL,
    telefone VARCHAR(40),
    status VARCHAR(20) NOT NULL DEFAULT 'processando',
    tentativas INT NOT NULL DEFAULT 1,
    erro TEXT,
    reservado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    enviado_em TIMESTAMPTZ,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_comercial_deg_notif_envio
ON comercial_degustacao_notificacao_envios (degustacao_id, inscricao_id);

CREATE INDEX IF NOT EXISTS idx_comercial_deg_notif_status
ON comercial_degustacao_notificacao_envios (status, atualizado_em);
