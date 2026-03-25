-- 057_eventos_cardapio_pacotes_secoes.sql
-- Pacotes, seções e escolhas de cardápio do cliente

CREATE TABLE IF NOT EXISTS logistica_cardapio_secoes (
    id BIGSERIAL PRIMARY KEY,
    nome VARCHAR(180) NOT NULL,
    descricao TEXT NULL,
    ordem INTEGER NOT NULL DEFAULT 0,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    created_by_user_id INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP NULL,
    deleted_by_user_id INTEGER NULL
);

CREATE INDEX IF NOT EXISTS idx_logistica_cardapio_secoes_nome
    ON logistica_cardapio_secoes (LOWER(nome));

CREATE TABLE IF NOT EXISTS logistica_pacotes_evento_secoes (
    id BIGSERIAL PRIMARY KEY,
    pacote_evento_id BIGINT NOT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE CASCADE,
    secao_cardapio_id BIGINT NOT NULL REFERENCES logistica_cardapio_secoes(id) ON DELETE CASCADE,
    quantidade_maxima INTEGER NOT NULL DEFAULT 1,
    ordem INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_logistica_pacotes_evento_secoes
    ON logistica_pacotes_evento_secoes (pacote_evento_id, secao_cardapio_id);

CREATE TABLE IF NOT EXISTS logistica_cardapio_item_pacotes (
    id BIGSERIAL PRIMARY KEY,
    item_tipo VARCHAR(20) NOT NULL,
    item_id BIGINT NOT NULL,
    pacote_evento_id BIGINT NOT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_logistica_cardapio_item_pacotes
    ON logistica_cardapio_item_pacotes (item_tipo, item_id, pacote_evento_id);

CREATE TABLE IF NOT EXISTS logistica_cardapio_item_secoes (
    id BIGSERIAL PRIMARY KEY,
    item_tipo VARCHAR(20) NOT NULL,
    item_id BIGINT NOT NULL,
    secao_cardapio_id BIGINT NOT NULL REFERENCES logistica_cardapio_secoes(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_logistica_cardapio_item_secoes
    ON logistica_cardapio_item_secoes (item_tipo, item_id, secao_cardapio_id);

ALTER TABLE IF EXISTS eventos_cliente_portais
    ADD COLUMN IF NOT EXISTS visivel_cardapio BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE IF EXISTS eventos_cliente_portais
    ADD COLUMN IF NOT EXISTS editavel_cardapio BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS eventos_cardapio_respostas (
    id BIGSERIAL PRIMARY KEY,
    meeting_id BIGINT NOT NULL UNIQUE REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    portal_id BIGINT NULL REFERENCES eventos_cliente_portais(id) ON DELETE SET NULL,
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS eventos_cardapio_resposta_itens (
    id BIGSERIAL PRIMARY KEY,
    resposta_id BIGINT NOT NULL REFERENCES eventos_cardapio_respostas(id) ON DELETE CASCADE,
    secao_cardapio_id BIGINT NOT NULL REFERENCES logistica_cardapio_secoes(id) ON DELETE CASCADE,
    item_tipo VARCHAR(20) NOT NULL,
    item_id BIGINT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_eventos_cardapio_resposta_itens
    ON eventos_cardapio_resposta_itens (resposta_id, secao_cardapio_id, item_tipo, item_id);
