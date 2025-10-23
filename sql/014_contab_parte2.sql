-- 014_contab_parte2.sql
-- PARTE 2: Criar tabelas auxiliares da Contabilidade

-- Tabela de anexos contábeis
CREATE TABLE IF NOT EXISTS contab_anexos (
    id SERIAL PRIMARY KEY,
    documento_id INT REFERENCES contab_documentos(id) ON DELETE CASCADE,
    parcela_id INT REFERENCES contab_parcelas(id) ON DELETE CASCADE,
    tipo_anexo VARCHAR(50) NOT NULL,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100) NOT NULL,
    tamanho_bytes BIGINT NOT NULL,
    autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de tokens do portal contábil
CREATE TABLE IF NOT EXISTS contab_tokens (
    id SERIAL PRIMARY KEY,
    token VARCHAR(64) UNIQUE NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    ultimo_uso TIMESTAMP
);

-- Tabela de rate limiting do portal
CREATE TABLE IF NOT EXISTS contab_rate_limit (
    id SERIAL PRIMARY KEY,
    ip_origem INET NOT NULL,
    token_contab VARCHAR(64),
    uploads_na_hora INT NOT NULL DEFAULT 1,
    janela_inicio TIMESTAMP NOT NULL DEFAULT NOW(),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);
