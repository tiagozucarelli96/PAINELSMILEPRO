-- Migração: Tabelas de Comercial
-- Data: 2025-10-25 00:36:35


        CREATE TABLE IF NOT EXISTS comercial_inscricoes (
            id SERIAL PRIMARY KEY,
            degustacao_id INTEGER,
            nome VARCHAR(200) NOT NULL,
            email VARCHAR(200) NOT NULL,
            telefone VARCHAR(20),
            cpf VARCHAR(14),
            status VARCHAR(20) DEFAULT 'pendente',
            pagamento_status VARCHAR(20),
            pagamento_id VARCHAR(100),
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS comercial_campos_padrao (
            id SERIAL PRIMARY KEY,
            campos_json JSONB NOT NULL,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS comercial_email_config (
            id SERIAL PRIMARY KEY,
            host VARCHAR(200) NOT NULL,
            port INTEGER DEFAULT 587,
            username VARCHAR(200),
            password VARCHAR(500),
            encryption VARCHAR(20) DEFAULT 'tls',
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

-- Índices
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_degustacao_id ON comercial_inscricoes(degustacao_id);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_email ON comercial_inscricoes(email);
