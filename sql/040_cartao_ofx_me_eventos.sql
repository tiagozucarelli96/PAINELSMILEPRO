-- 040_cartao_ofx_me_eventos.sql
-- Modulo Cartao -> OFX (ME Eventos)

CREATE TABLE IF NOT EXISTS cartao_ofx_cartoes (
    id BIGSERIAL PRIMARY KEY,
    nome_cartao VARCHAR(120) NOT NULL,
    dia_vencimento INT NOT NULL CHECK (dia_vencimento BETWEEN 1 AND 31),
    status BOOLEAN NOT NULL DEFAULT TRUE,
    apelido VARCHAR(60),
    cor VARCHAR(30),
    final VARCHAR(10),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS cartao_ofx_compra_base (
    id BIGSERIAL PRIMARY KEY,
    cartao_id BIGINT NOT NULL REFERENCES cartao_ofx_cartoes(id),
    competencia_base VARCHAR(7) NOT NULL,
    descricao_normalizada TEXT NOT NULL,
    valor_total NUMERIC(12,2) NOT NULL,
    indicador_parcela VARCHAR(10),
    hash_base CHAR(64) NOT NULL UNIQUE,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS cartao_ofx_parcelas (
    id BIGSERIAL PRIMARY KEY,
    compra_base_id BIGINT NOT NULL REFERENCES cartao_ofx_compra_base(id),
    numero_parcela INT NOT NULL,
    total_parcelas INT NOT NULL,
    data_vencimento DATE NOT NULL,
    valor_parcela NUMERIC(12,2) NOT NULL,
    hash_parcela CHAR(64) NOT NULL UNIQUE,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS cartao_ofx_geracoes (
    id BIGSERIAL PRIMARY KEY,
    cartao_id BIGINT NOT NULL REFERENCES cartao_ofx_cartoes(id),
    competencia VARCHAR(7) NOT NULL,
    gerado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    usuario_id BIGINT,
    quantidade_transacoes INT NOT NULL DEFAULT 0,
    arquivo_url TEXT,
    arquivo_key TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'gerado',
    excluido_em TIMESTAMP,
    excluido_por BIGINT,
    transacoes_json JSONB
);

CREATE TABLE IF NOT EXISTS cartao_ofx_ocr_usage (
    mes VARCHAR(7) PRIMARY KEY,
    paginas_processadas INT NOT NULL DEFAULT 0,
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cartao_ofx_compra_cartao ON cartao_ofx_compra_base(cartao_id);
CREATE INDEX IF NOT EXISTS idx_cartao_ofx_parcelas_compra ON cartao_ofx_parcelas(compra_base_id);
CREATE INDEX IF NOT EXISTS idx_cartao_ofx_geracoes_cartao ON cartao_ofx_geracoes(cartao_id);
CREATE INDEX IF NOT EXISTS idx_cartao_ofx_geracoes_competencia ON cartao_ofx_geracoes(competencia);
