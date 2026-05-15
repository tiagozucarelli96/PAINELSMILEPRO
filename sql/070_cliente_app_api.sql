-- 070_cliente_app_api.sql
-- Estrutura base para a API do app do cliente

CREATE TABLE IF NOT EXISTS cliente_app_sessoes (
    id BIGSERIAL PRIMARY KEY,
    meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    cpf_hash VARCHAR(64) NOT NULL,
    access_token_hash VARCHAR(64) NOT NULL UNIQUE,
    device_name VARCHAR(120),
    platform VARCHAR(40),
    app_version VARCHAR(40),
    ip VARCHAR(64),
    user_agent TEXT,
    last_seen_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cliente_app_sessoes_meeting_id
    ON cliente_app_sessoes(meeting_id);

CREATE INDEX IF NOT EXISTS idx_cliente_app_sessoes_expires_at
    ON cliente_app_sessoes(expires_at);

CREATE TABLE IF NOT EXISTS cliente_app_login_tentativas (
    id BIGSERIAL PRIMARY KEY,
    cpf_digitado VARCHAR(11) NOT NULL DEFAULT '',
    data_evento_digitada DATE NULL,
    local_digitado VARCHAR(120) NOT NULL DEFAULT '',
    meeting_id_encontrado BIGINT NULL REFERENCES eventos_reunioes(id) ON DELETE SET NULL,
    sucesso BOOLEAN NOT NULL DEFAULT FALSE,
    motivo VARCHAR(80) NOT NULL DEFAULT '',
    ip VARCHAR(64),
    user_agent TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cliente_app_login_tentativas_cpf_created_at
    ON cliente_app_login_tentativas(cpf_digitado, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_cliente_app_login_tentativas_ip_created_at
    ON cliente_app_login_tentativas(ip, created_at DESC);
