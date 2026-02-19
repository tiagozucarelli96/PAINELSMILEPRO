-- 052_eventos_lista_convidados.sql
-- Lista de convidados no portal do cliente + check-in interno

ALTER TABLE IF EXISTS eventos_cliente_portais
    ADD COLUMN IF NOT EXISTS visivel_convidados BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS editavel_convidados BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS eventos_convidados_config (
    id BIGSERIAL PRIMARY KEY,
    meeting_id BIGINT NOT NULL UNIQUE,
    tipo_evento VARCHAR(24) NOT NULL DEFAULT 'infantil',
    updated_by_type VARCHAR(20) NOT NULL DEFAULT 'interno',
    updated_by_user_id INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_convidados_config_meeting
    ON eventos_convidados_config(meeting_id);

CREATE TABLE IF NOT EXISTS eventos_convidados (
    id BIGSERIAL PRIMARY KEY,
    meeting_id BIGINT NOT NULL,
    nome VARCHAR(180) NOT NULL,
    faixa_etaria VARCHAR(40) NULL,
    numero_mesa VARCHAR(20) NULL,
    checkin_at TIMESTAMP NULL,
    checkin_by_user_id INTEGER NULL,
    created_by_type VARCHAR(20) NOT NULL DEFAULT 'cliente',
    created_by_user_id INTEGER NULL,
    updated_by_user_id INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_eventos_convidados_meeting
    ON eventos_convidados(meeting_id, deleted_at);

CREATE INDEX IF NOT EXISTS idx_eventos_convidados_mesa
    ON eventos_convidados(meeting_id, numero_mesa);

CREATE INDEX IF NOT EXISTS idx_eventos_convidados_nome
    ON eventos_convidados(meeting_id, lower(nome));
