-- Cadastro comercial importado da ME Eventos e vínculo com a Agenda Geral.

CREATE TABLE IF NOT EXISTS comercial_cadastro_clientes (
    id BIGSERIAL PRIMARY KEY,
    tipo_pessoa VARCHAR(2) NOT NULL DEFAULT 'PF',
    nome_completo VARCHAR(180) NOT NULL,
    email VARCHAR(180) NOT NULL DEFAULT '',
    telefone_whatsapp VARCHAR(40) NOT NULL DEFAULT '',
    documento_tipo VARCHAR(8) NOT NULL DEFAULT 'CPF',
    documento_numero VARCHAR(20) NOT NULL DEFAULT '',
    rg VARCHAR(30) NULL,
    cep VARCHAR(12) NULL,
    endereco_logradouro VARCHAR(180) NULL,
    endereco_numero VARCHAR(30) NULL,
    endereco_complemento VARCHAR(120) NULL,
    endereco_bairro VARCHAR(120) NULL,
    endereco_cidade VARCHAR(120) NULL,
    endereco_estado VARCHAR(2) NULL,
    origem_cliente VARCHAR(60) NULL,
    responsavel_usuario_id INTEGER NULL,
    tipo_interesse VARCHAR(40) NULL,
    data_desejada DATE NULL,
    unidade_interesse VARCHAR(120) NULL,
    observacoes TEXT NULL,
    me_cliente_id BIGINT NULL,
    ultimo_me_event_id BIGINT NULL,
    origem_importacao VARCHAR(40) NULL,
    imported_at TIMESTAMP NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

ALTER TABLE comercial_cadastro_clientes
    ADD COLUMN IF NOT EXISTS me_cliente_id BIGINT NULL,
    ADD COLUMN IF NOT EXISTS ultimo_me_event_id BIGINT NULL,
    ADD COLUMN IF NOT EXISTS origem_importacao VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS imported_at TIMESTAMP NULL;

CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_me_cliente
    ON comercial_cadastro_clientes(me_cliente_id);

CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_me_event
    ON comercial_cadastro_clientes(ultimo_me_event_id);

CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_documento
    ON comercial_cadastro_clientes(documento_numero);

ALTER TABLE logistica_eventos_espelho
    ADD COLUMN IF NOT EXISTS cliente_cadastro_id BIGINT NULL;

CREATE INDEX IF NOT EXISTS idx_logistica_eventos_cliente_cadastro
    ON logistica_eventos_espelho(cliente_cadastro_id);
